<?php
// Mahnwesen (Debitoren): 3-stufig mit Gebühren + optionalen Verzugszinsen, als Mahnlauf über die offenen
// Posten. Eigene Tabellen (mahnlauf, mahnung). Mahnstufe/letzte_mahnung stehen additiv am Beleg.
// Nur Debitoren (beleg typ=rechnung). Zahlungen laufen über zahlung_erfassen (finanz.php) – unberührt.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';        // naechste_nummer, meta_get/meta_set, beleg_status_log_add, zahlung_summe, log_aktivitaet
require_once __DIR__ . '/buchhaltung.php';   // bh_op_rechnungen()

function mahn_init(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS mahnlauf (
        id INT AUTO_INCREMENT PRIMARY KEY,
        datum DATE NULL,
        anzahl INT NOT NULL DEFAULT 0,
        summe DECIMAL(14,2) NOT NULL DEFAULT 0,          -- Summe offener Betrag der gemahnten Posten
        gebuehr_summe DECIMAL(14,2) NOT NULL DEFAULT 0,  -- Summe Mahngebühren
        akteur VARCHAR(80) NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS mahnung (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nummer VARCHAR(20) NULL,
        lauf_id INT NULL,
        beleg_id INT NOT NULL,
        kunde_id INT NULL,
        stufe TINYINT NOT NULL DEFAULT 1,                -- 1=Zahlungserinnerung, 2=1. Mahnung, 3=2. Mahnung
        datum DATE NULL,
        offen DECIMAL(14,2) NOT NULL DEFAULT 0,          -- offener Rechnungsbetrag zum Mahnzeitpunkt
        gebuehr DECIMAL(14,2) NOT NULL DEFAULT 0,
        zins DECIMAL(14,2) NOT NULL DEFAULT 0,
        summe DECIMAL(14,2) NOT NULL DEFAULT 0,          -- offen + gebuehr + zins
        faellig_neu DATE NULL,                           -- neue Zahlungsfrist
        kunde_sichtbar TINYINT(1) NOT NULL DEFAULT 0,
        akteur VARCHAR(80) NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_beleg (beleg_id), KEY idx_lauf (lauf_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// Konfiguration aus app_meta mit sinnvollen Defaults. frist = Tage überfällig, ab denen die Stufe greift.
function mahn_config(): array {
    $f = fn($k, $d) => (float) str_replace(',', '.', (string) meta_get($k, (string)$d));
    $i = fn($k, $d) => (int) meta_get($k, (string)$d);
    $t = fn($k, $d) => (string) meta_get($k, $d);
    return [
        'aktiv'       => (string) meta_get('mahn_aktiv', '1') === '1',
        'zins_prozent'=> $f('mahn_zins', 0),          // Verzugszinsen p. a. (0 = aus)
        'neue_frist'  => $i('mahn_neue_frist', 7),     // neue Zahlungsfrist (Tage) ab Mahndatum
        'stufen' => [
            1 => ['label' => 'Zahlungserinnerung', 'frist' => $i('mahn_frist_1', 7),  'gebuehr' => $f('mahn_gebuehr_1', 0),
                  'text' => $t('mahn_text_1', 'Sicher haben Sie es nur übersehen: Die folgende Rechnung ist noch offen. Wir bitten Sie, den offenen Betrag bis zum unten genannten Datum auszugleichen.')],
            2 => ['label' => '1. Mahnung',          'frist' => $i('mahn_frist_2', 14), 'gebuehr' => $f('mahn_gebuehr_2', 5),
                  'text' => $t('mahn_text_2', 'Trotz unserer Erinnerung ist die folgende Rechnung weiterhin offen. Wir fordern Sie auf, den offenen Betrag inklusive Mahngebühr bis zum unten genannten Datum zu begleichen.')],
            3 => ['label' => '2. Mahnung',          'frist' => $i('mahn_frist_3', 28), 'gebuehr' => $f('mahn_gebuehr_3', 10),
                  'text' => $t('mahn_text_3', 'Letzte Mahnung: Die folgende Rechnung ist trotz mehrfacher Aufforderung nicht ausgeglichen. Bitte zahlen Sie den Gesamtbetrag umgehend bis zum unten genannten Datum. Danach behalten wir uns weitere Schritte vor.')],
        ],
    ];
}
function mahn_stufe_label(int $stufe): string { $c = mahn_config(); return $c['stufen'][$stufe]['label'] ?? ('Stufe ' . $stufe); }

// Nächste fällige Mahnstufe für einen offenen, überfälligen OP (0 = noch nichts fällig / fertig gemahnt).
function mahn_naechste_stufe(array $op): int {
    $cur = (int)($op['mahnstufe'] ?? 0);
    if ($cur >= 3) return 0;
    $tue = (int)($op['tage_ueberfaellig'] ?? 0);
    $ziel = $cur + 1;
    $cfg = mahn_config();
    return ($tue >= (int)$cfg['stufen'][$ziel]['frist']) ? $ziel : 0;
}

// Gebühr + Zins + Summe für einen OP bei einer Stufe. offen aus dem OP, zins p. a. anteilig auf Tage überfällig.
function mahn_betrag(array $op, int $stufe): array {
    $cfg = mahn_config();
    $offen = round((float)($op['rest'] ?? 0), 2);
    $gebuehr = round((float)($cfg['stufen'][$stufe]['gebuehr'] ?? 0), 2);
    $zins = 0.0;
    if ($cfg['zins_prozent'] > 0) {
        $tue = max(0, (int)($op['tage_ueberfaellig'] ?? 0));
        $zins = round($offen * $cfg['zins_prozent'] / 100 * $tue / 365, 2);
    }
    return ['offen' => $offen, 'gebuehr' => $gebuehr, 'zins' => $zins, 'summe' => round($offen + $gebuehr + $zins, 2)];
}

// Mahnkandidaten: alle überfälligen OP, bei denen eine nächste Stufe fällig ist. Mit Vorschlag + Beträgen.
function mahn_kandidaten(): array {
    $out = [];
    foreach (bh_op_rechnungen('ueberfaellig') as $op) {
        $st = mahn_naechste_stufe($op);
        if (!$st) continue;
        $out[] = $op + ['vorschlag' => $st] + mahn_betrag($op, $st);
    }
    return $out;
}

// Eine Mahnung erzeugen (bucht keine Zahlung!). Bumpt beleg.mahnstufe + letzte_mahnung. Gibt mahnung-ID.
function mahn_erzeugen(int $beleg_id, int $stufe, array $opt = []): ?int {
    mahn_init();
    $b = one("SELECT * FROM beleg WHERE id=? AND typ='rechnung'", [$beleg_id]);
    if (!$b || ($b['status'] ?? '') === 'storniert') return null;
    $stufe = max(1, min(3, $stufe));
    $offen = round((float)$b['brutto'] - zahlung_summe($beleg_id), 2);
    if ($offen <= 0.005) return null;   // nichts mehr offen
    $cfg = mahn_config();
    $tue = ($b['faellig'] && strtotime((string)$b['faellig']) < strtotime(date('Y-m-d')))
         ? (int) round((strtotime(date('Y-m-d')) - strtotime((string)$b['faellig'])) / 86400) : 0;
    $gebuehr = array_key_exists('gebuehr', $opt) ? round((float)$opt['gebuehr'], 2) : round((float)($cfg['stufen'][$stufe]['gebuehr'] ?? 0), 2);
    $zins = 0.0;
    if ($cfg['zins_prozent'] > 0) $zins = round($offen * $cfg['zins_prozent'] / 100 * $tue / 365, 2);
    $datum = $opt['datum'] ?? date('Y-m-d');
    $faelligNeu = date('Y-m-d', strtotime($datum . ' +' . (int)$cfg['neue_frist'] . ' days'));
    $summe = round($offen + $gebuehr + $zins, 2);
    $sicht = !empty($opt['freigeben']) ? 1 : 0;
    q("INSERT INTO mahnung (nummer,lauf_id,beleg_id,kunde_id,stufe,datum,offen,gebuehr,zins,summe,faellig_neu,kunde_sichtbar,akteur)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
      [naechste_nummer('MA'), $opt['lauf_id'] ?? null, $beleg_id, $b['kunde_id'] ?: null, $stufe, $datum,
       $offen, $gebuehr, $zins, $summe, $faelligNeu, $sicht, trim((string)($opt['akteur'] ?? 'team')) ?: 'team']);
    $mid = (int) insert_id();
    q("UPDATE beleg SET mahnstufe=?, letzte_mahnung=? WHERE id=?", [$stufe, $datum, $beleg_id]);
    $lbl = mahn_stufe_label($stufe);
    if (function_exists('beleg_status_log_add'))
        beleg_status_log_add($beleg_id, (string)($b['status'] ?? 'offen'), $lbl . ' erstellt (offen ' . number_format($offen,2,',','.') . ' €' . ($gebuehr>0?', Gebühr '.number_format($gebuehr,2,',','.').' €':'') . ')', trim((string)($opt['akteur'] ?? 'team')) ?: 'team');
    if (!empty($b['kunde_id'])) log_aktivitaet('kunde', (int)$b['kunde_id'], 'team', $lbl . ' zu Rechnung ' . $b['nummer'] . ' erstellt.', 'mahnung', 'beleg', $beleg_id);
    return $mid;
}

// Mahnlauf: mehrere Posten in einem Rutsch mahnen. $posten = [['beleg_id'=>int,'stufe'=>int], …].
// Gibt ['lauf_id'=>int,'anzahl'=>int,'summe'=>float,'gebuehr'=>float].
function mahn_lauf(array $posten, array $opt = []): array {
    mahn_init();
    $datum = $opt['datum'] ?? date('Y-m-d');
    $akteur = trim((string)($opt['akteur'] ?? 'team')) ?: 'team';
    q("INSERT INTO mahnlauf (datum, anzahl, summe, gebuehr_summe, akteur) VALUES (?,?,?,?,?)", [$datum, 0, 0, 0, $akteur]);
    $laufId = (int) insert_id();
    $anz = 0; $summe = 0.0; $geb = 0.0;
    foreach ($posten as $pt) {
        $bid = (int)($pt['beleg_id'] ?? 0);
        $stufe = (int)($pt['stufe'] ?? 0);
        if (!$bid || !$stufe) continue;
        $mid = mahn_erzeugen($bid, $stufe, ['lauf_id' => $laufId, 'datum' => $datum, 'akteur' => $akteur, 'freigeben' => !empty($opt['freigeben'])]);
        if ($mid) {
            $m = one("SELECT offen, gebuehr FROM mahnung WHERE id=?", [$mid]);
            $anz++; $summe += (float)$m['offen']; $geb += (float)$m['gebuehr'];
        }
    }
    q("UPDATE mahnlauf SET anzahl=?, summe=?, gebuehr_summe=? WHERE id=?", [$anz, round($summe,2), round($geb,2), $laufId]);
    if ($anz === 0) q("DELETE FROM mahnlauf WHERE id=? AND anzahl=0", [$laufId]);   // leeren Lauf nicht stehen lassen
    return ['lauf_id' => $laufId, 'anzahl' => $anz, 'summe' => round($summe,2), 'gebuehr' => round($geb,2)];
}

// --- Lesen -----------------------------------------------------------------
function mahn_laeufe(int $limit = 50): array {
    mahn_init();
    return all("SELECT * FROM mahnlauf ORDER BY id DESC LIMIT " . max(1, $limit));
}
function mahn_lauf_get(int $id): ?array { mahn_init(); return one("SELECT * FROM mahnlauf WHERE id=?", [$id]); }
function mahn_eintraege(int $lauf_id): array {
    mahn_init();
    return all("SELECT m.*, b.nummer AS beleg_nr, k.firma AS kunde_firma
                  FROM mahnung m LEFT JOIN beleg b ON b.id=m.beleg_id LEFT JOIN kunden k ON k.id=m.kunde_id
                 WHERE m.lauf_id=? ORDER BY k.firma, m.id", [$lauf_id]);
}
function mahn_get(int $id): ?array {
    mahn_init();
    return one("SELECT m.*, b.nummer AS beleg_nr, b.datum AS beleg_datum, b.faellig AS beleg_faellig
                  FROM mahnung m LEFT JOIN beleg b ON b.id=m.beleg_id WHERE m.id=?", [$id]);
}
function mahn_fuer_beleg(int $beleg_id): array {
    mahn_init();
    return all("SELECT * FROM mahnung WHERE beleg_id=? ORDER BY id DESC", [$beleg_id]);
}
