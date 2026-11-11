<?php
// Inventur der Lager-App (Nico 11.11.2026): eigener Modus, getrennt vom Tagesgeschäft. Dustin zählt blind, das System
// vergleicht mit dem Buchbestand ZUM SCAN-ZEITPUNKT, Nico entscheidet jede Korrektur. Siehe inventur.md.
// Diese Datei: Tabellen, Lagerplätze, Gebindetypen (Tara), Inventur-Lauf anlegen, Bereiche, Fortschritt.
// Zählen / Abgleich / Buchen / Protokoll liegen in eigenen Dateien (unten eingebunden). Dashboard-Zugriffe nur über
// erp_inventur.php (Naht).
require_once __DIR__ . '/erp_inventur.php';
foreach (['inventur_zaehlen', 'inventur_abgleich', 'erp_inventur_buchen', 'inventur_buchen', 'inventur_protokoll'] as $__f)
    if (is_file(__DIR__ . '/' . $__f . '.php')) require_once __DIR__ . '/' . $__f . '.php';
unset($__f);

function inv_schema(): void {
    // Lagerplätze (Lager 1/2): lg_platz gab es schon (Fulfillment, leer). Jetzt mit aktiv/lager; Code „LP-00012" als Barcode.
    lg_spalte('lg_platz', 'aktiv', 'TINYINT NOT NULL DEFAULT 1');
    lg_spalte('lg_platz', 'lager', "VARCHAR(10) NOT NULL DEFAULT 'lager1'");
    // Was liegt wo: je Gebinde (gebinde_id) bzw. je Charge ohne Gebinde (gebinde_id=0). Lager-eigen, Dashboard bleibt unberührt.
    q("CREATE TABLE IF NOT EXISTS lg_platz_inhalt (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        platz_id   INT NOT NULL,
        charge_id  INT NOT NULL,
        gebinde_id INT NOT NULL DEFAULT 0,
        seit       DATETIME NOT NULL,
        von        INT NULL,
        UNIQUE KEY obj (charge_id, gebinde_id),
        KEY platz (platz_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Gebindetypen mit Tara (Leergewicht) fürs Wiegen in der Inventur.
    q("CREATE TABLE IF NOT EXISTS lg_tara (
        id      INT AUTO_INCREMENT PRIMARY KEY,
        name    VARCHAR(120) NOT NULL,
        tara_kg DECIMAL(10,3) NOT NULL DEFAULT 0,
        sort    INT NOT NULL DEFAULT 0,
        aktiv   TINYINT NOT NULL DEFAULT 1
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Ein Inventur-Lauf.
    q("CREATE TABLE IF NOT EXISTS lg_inventur (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        name              VARCHAR(190) NOT NULL,
        stichtag          DATE NOT NULL,
        lager             VARCHAR(2) NOT NULL DEFAULT '1',      -- '1' eigener Bestand, '2' Fremdlager
        kunde_id          INT NULL,                              -- nur Lager 2 (NULL = alle Kunden)
        warenarten        VARCHAR(190) NOT NULL DEFAULT '',      -- Komma-Liste, leer = alle
        ganz              TINYINT NOT NULL DEFAULT 1,            -- 1 = ganzes Lager (Plätze entstehen beim Scannen)
        zaehler           VARCHAR(255) NOT NULL DEFAULT '',      -- Benutzer-IDs, Komma-Liste
        nachzaehl_prozent DECIMAL(6,2) NULL,                     -- zweite Person ab Abweichung > x %
        tol_gewogen       DECIMAL(6,2) NOT NULL DEFAULT 1,       -- Toleranz gewogene Ware in %
        tol_stk           DECIMAL(6,2) NOT NULL DEFAULT 0,       -- Toleranz Stück-Ware in %
        klein_eur         DECIMAL(10,2) NOT NULL DEFAULT 20,     -- Sammel-Knopf: kleine Differenzen unter x €
        klein_prozent     DECIMAL(6,2) NOT NULL DEFAULT 2,       -- … und unter y %
        status            VARCHAR(16) NOT NULL DEFAULT 'zaehlen', -- zaehlen | auswerten | abgeschlossen
        notiz             TEXT NULL,
        angelegt          DATETIME NOT NULL,
        angelegt_von      INT NULL,
        zaehlung_ende     DATETIME NULL,
        abgeschlossen_am  DATETIME NULL,
        abgeschlossen_von INT NULL,
        protokoll_pdf     VARCHAR(190) NULL,
        protokoll_csv     VARCHAR(190) NULL,
        KEY st (status)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // „abgeschlossen" hat 13 Zeichen – erste Fassung (Teil 1) hatte VARCHAR(12).
    try { q("ALTER TABLE lg_inventur MODIFY status VARCHAR(16) NOT NULL DEFAULT 'zaehlen'"); } catch (\Throwable $e) {}
    // Zählbereiche (Platz oder Kiste). sperre = Schlüssel, solange die Inventur offen ist -> ein Bereich nie in zwei offenen Inventuren.
    q("CREATE TABLE IF NOT EXISTS lg_inventur_bereich (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        inventur_id INT NOT NULL,
        schluessel  VARCHAR(20) NOT NULL,                        -- p:<platz_id> | k:<kiste_id>
        name        VARCHAR(120) NOT NULL,
        status      VARCHAR(10) NOT NULL DEFAULT 'offen',        -- offen | laeuft | fertig
        sperre      VARCHAR(20) NULL,
        gestartet_am DATETIME NULL, gestartet_von INT NULL,
        fertig_am    DATETIME NULL, fertig_von    INT NULL,
        UNIQUE KEY inv_bereich (inventur_id, schluessel),
        UNIQUE KEY sperre (sperre)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Jeder Scan. soll/soll_ort = Buchbestand und gebuchter Ort ZUM SCAN-ZEITPUNKT (eingefroren), der Zähler sieht sie nie.
    q("CREATE TABLE IF NOT EXISTS lg_inventur_zaehlung (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        inventur_id  INT NOT NULL,
        bereich_id   INT NOT NULL,
        obj          CHAR(1) NOT NULL,                           -- g Gebinde | c Charge | k Klärfall (ohne Etikett)
        gebinde_id   INT NOT NULL DEFAULT 0,
        charge_id    INT NOT NULL DEFAULT 0,
        item_id      INT NOT NULL DEFAULT 0,
        art          VARCHAR(12) NOT NULL,                       -- stk | voll | wiegen | kapsel | leer
        ist          DECIMAL(14,3) NOT NULL DEFAULT 0,           -- in der Einheit des Artikels
        einheit      VARCHAR(20) NULL,
        brutto_kg    DECIMAL(12,3) NULL, tara_kg DECIMAL(12,3) NULL, kapsel_mg DECIMAL(10,2) NULL,
        soll         DECIMAL(14,3) NULL, soll_einheit VARCHAR(20) NULL, soll_ort VARCHAR(20) NULL,
        durchgang    TINYINT NOT NULL DEFAULT 1,                 -- 2 = Nachzählung durch zweite Person
        ersetzt      TINYINT NOT NULL DEFAULT 0,                 -- 1 = später neu gezählt (zählt nicht mehr)
        zaehler_id   INT NULL,
        zeit         DATETIME NOT NULL,
        kf_charge_nr VARCHAR(80) NULL, kf_mhd DATE NULL, kf_foto VARCHAR(190) NULL, kf_inventar_nr VARCHAR(8) NULL, kf_notiz VARCHAR(255) NULL,
        kf_charge_id INT NULL,                                   -- Klärfall gebucht -> neue Charge
        KEY inv (inventur_id, bereich_id),
        KEY obj (inventur_id, obj, gebinde_id, charge_id),
        KEY kf (kf_inventar_nr)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Abweichungen und Nicos Entscheidungen.
    q("CREATE TABLE IF NOT EXISTS lg_inventur_diff (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        inventur_id   INT NOT NULL,
        bereich_id    INT NULL,
        zaehlung_id   INT NULL,
        obj           CHAR(1) NOT NULL,
        gebinde_id    INT NOT NULL DEFAULT 0,
        charge_id     INT NOT NULL DEFAULT 0,
        item_id       INT NOT NULL DEFAULT 0,
        art           VARCHAR(16) NOT NULL,     -- passt | menge | nicht_gefunden | ohne_buchung | falscher_platz | einheit | leer
        soll          DECIMAL(14,3) NULL, soll_einheit VARCHAR(20) NULL,
        ist           DECIMAL(14,3) NULL, einheit VARCHAR(20) NULL,
        diff          DECIMAL(14,3) NULL,
        diff_prozent  DECIMAL(10,2) NULL,
        wert_eur      DECIMAL(12,2) NULL,
        soll_ort      VARCHAR(20) NULL, ist_ort VARCHAR(20) NULL,
        status        VARCHAR(16) NOT NULL DEFAULT 'offen',   -- passt | offen | nachzaehlen | gebucht | offen_gelassen
        ursache       VARCHAR(24) NULL,
        notiz         VARCHAR(255) NULL,
        ergebnis      VARCHAR(255) NULL,                      -- was gebucht wurde
        erster_zaehler INT NULL,
        entschieden_von INT NULL, entschieden_am DATETIME NULL,
        KEY inv (inventur_id, status),
        KEY obj (inventur_id, obj, gebinde_id, charge_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Korrekturbuchungen stehen als Bewegung mit Bezug zur Inventur und Ursache in lg_bewegung (typ 'inventur').
    lg_spalte('lg_bewegung', 'inventur_id', 'INT NULL');
    lg_spalte('lg_bewegung', 'ursache', 'VARCHAR(24) NULL');
    if ((int) scalar("SELECT COUNT(*) FROM lg_tara") === 0)
        foreach ([['Sack klein (PE)', 0.05], ['Sack groß (PE)', 0.15], ['Karton', 0.40], ['Eimer 10 l', 0.60], ['Eimer 25 l', 1.10], ['Fass 50 kg', 4.00], ['ohne (netto gewogen)', 0]] as $k => [$n, $t])
            q("INSERT INTO lg_tara (name, tara_kg, sort) VALUES (?,?,?)", [$n, $t, $k + 1]);
}

// ---- Lagerplätze -------------------------------------------------------------------------------------------------
function inv_platz_code(int $id): string { return 'LP-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT); }
function inv_platz_name(array $p): string {
    if (trim((string)($p['bezeichnung'] ?? '')) !== '') return trim((string)$p['bezeichnung']);
    return (string)$p['bereich'] . '-' . str_pad((string)(int)$p['regal'], 2, '0', STR_PAD_LEFT) . '-' . (int)$p['ebene'] . ((int)$p['fach'] > 1 || !empty($p['_mit_fach']) ? '-' . (int)$p['fach'] : '');
}
function inv_platz(int $id): ?array { return $id > 0 ? one("SELECT * FROM lg_platz WHERE id=?", [$id]) : null; }
function inv_plaetze(string $lager = ''): array {
    return all("SELECT * FROM lg_platz WHERE aktiv=1" . ($lager !== '' ? " AND lager=?" : "") . " ORDER BY bereich, regal, ebene, fach", $lager !== '' ? [$lager] : []);
}
// Raster anlegen: Bereich A, Regal 1 bis 4, je 5 Ebenen, je 1 Fach -> A-01-1 … A-04-5. Vorhandene bleiben. Rückgabe: Anzahl neu.
function inv_platz_raster(string $bereich, int $regal_von, int $regal_bis, int $ebenen, int $faecher, string $lager = 'lager1'): int {
    $bereich = mb_substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $bereich)), 0, 10) ?: 'A';
    $n = 0;
    for ($r = max(1, $regal_von); $r <= max($regal_von, $regal_bis) && $r <= 999; $r++)
        for ($e = 1; $e <= max(1, min(50, $ebenen)); $e++)
            for ($f = 1; $f <= max(1, min(50, $faecher)); $f++)
                $n += q("INSERT IGNORE INTO lg_platz (bereich, regal, ebene, fach, lager, aktiv, angelegt) VALUES (?,?,?,?,?,1,?)",
                        [$bereich, $r, $e, $f, $lager === 'lager2' ? 'lager2' : 'lager1', jetzt_utc()])->rowCount();
    return $n;
}
// Gescannten Platz-Code auflösen (LP-00012, auch ohne Bindestrich/führende Nullen, oder als URL ...platz=12).
function inv_platz_per_code(string $code): ?array {
    $q = trim($code);
    if (preg_match('/^LP-?0*(\d+)$/i', $q, $m) || preg_match('/[?&]platz=(\d+)/i', $q, $m)) {
        $p = inv_platz((int)$m[1]);
        return $p && (int)$p['aktiv'] === 1 ? $p : null;
    }
    return null;
}
// Gebuchter Ort eines Zählobjekts: Platz (je Gebinde, sonst je Charge), sonst Kiste. ['key'=>'p:3'|'k:2'|'', 'name'=>…].
function inv_ort_von(int $charge_id, int $gebinde_id = 0): array {
    $r = one("SELECT pi.platz_id, p.* FROM lg_platz_inhalt pi JOIN lg_platz p ON p.id=pi.platz_id
              WHERE pi.charge_id=? AND pi.gebinde_id IN (?,0) ORDER BY (pi.gebinde_id=?) DESC LIMIT 1", [$charge_id, $gebinde_id, $gebinde_id]);
    if ($r) return ['key' => 'p:' . (int)$r['platz_id'], 'name' => inv_platz_name($r)];
    $k = function_exists('kiste_fuer_charge') ? kiste_fuer_charge($charge_id) : null;
    if ($k) return ['key' => 'k:' . (int)($k['kiste_id'] ?? $k['id'] ?? 0), 'name' => 'Kiste ' . (string)($k['kiste_name'] ?? $k['name'] ?? '')];
    return ['key' => '', 'name' => ''];
}
function inv_ort_name(string $key): string {
    if (preg_match('/^p:(\d+)$/', $key, $m)) { $p = inv_platz((int)$m[1]); return $p ? inv_platz_name($p) : 'Platz #' . $m[1]; }
    if (preg_match('/^k:(\d+)$/', $key, $m)) { $k = one("SELECT name FROM lg_kiste WHERE id=?", [(int)$m[1]]); return 'Kiste ' . (string)($k['name'] ?? '#' . $m[1]); }
    return '';
}
// Platz setzen (Umlagerung). Liegt das Objekt in einer Kiste, bleibt die Kiste (Kiste steht dann auf dem Platz). Protokoll in lg_charge_log.
function inv_platz_setzen(int $charge_id, int $gebinde_id, int $platz_id, string $grund = ''): void {
    $alt = inv_ort_von($charge_id, $gebinde_id);
    q("INSERT INTO lg_platz_inhalt (platz_id, charge_id, gebinde_id, seit, von) VALUES (?,?,?,?,?)
       ON DUPLICATE KEY UPDATE platz_id=VALUES(platz_id), seit=VALUES(seit), von=VALUES(von)", [$platz_id, $charge_id, $gebinde_id, jetzt_utc(), lg_uid() ?: null]);
    $neu = inv_ort_name('p:' . $platz_id);
    if ($alt['name'] !== $neu && function_exists('lg_charge_log_add'))
        lg_charge_log_add($charge_id, 'platz', ($gebinde_id ? 'Gebinde #' . $gebinde_id . ': ' : '') . ($alt['name'] ?: '–'), $neu . ($grund !== '' ? ' (' . $grund . ')' : ''));
}

// ---- Gebindetypen (Tara) ---------------------------------------------------------------------------------------
function inv_tara_liste(bool $nur_aktiv = true): array {
    return all("SELECT * FROM lg_tara" . ($nur_aktiv ? " WHERE aktiv=1" : "") . " ORDER BY sort, name");
}

// ---- Inventur-Lauf ---------------------------------------------------------------------------------------------
function inv_get(int $id): ?array { return $id > 0 ? one("SELECT * FROM lg_inventur WHERE id=?", [$id]) : null; }
function inv_liste(): array { return all("SELECT * FROM lg_inventur ORDER BY (status='abgeschlossen'), id DESC LIMIT 200"); }
function inv_status_label(string $s): string {
    return ['zaehlen' => 'Zählung läuft', 'auswerten' => 'Auswertung', 'abgeschlossen' => 'abgeschlossen'][$s] ?? $s;
}
function inv_umfang_text(array $inv): string {
    $t = (string)$inv['lager'] === '2' ? 'Lager 2' . ((int)$inv['kunde_id'] ? ' · ' . erp_inv_kunde_name((int)$inv['kunde_id']) : ' · alle Kunden') : 'Lager 1';
    $t .= (int)$inv['ganz'] ? ' · ganzes Lager' : ' · ' . (int) scalar("SELECT COUNT(*) FROM lg_inventur_bereich WHERE inventur_id=?", [(int)$inv['id']]) . ' Plätze/Kisten';
    $wa = array_filter(explode(',', (string)$inv['warenarten']));
    if ($wa) { $l = erp_inv_warenarten(); $t .= ' · ' . implode(', ', array_map(fn($a) => $l[$a] ?? $a, $wa)); }
    return $t;
}
// Inventur anlegen. $d: name, stichtag, lager, kunde_id, warenarten[], bereiche[] ('p:12','k:3'; leer = ganzes Lager), zaehler[], nachzaehl_prozent, tol_*.
// Rückgabe ['ok', 'id'|'fehler'].
function inv_anlegen(array $d): array {
    $name = trim((string)($d['name'] ?? ''));
    if ($name === '') return ['ok' => false, 'fehler' => 'Bitte einen Namen angeben.'];
    $lager = (string)($d['lager'] ?? '1') === '2' ? '2' : '1';
    $kunde = $lager === '2' ? max(0, (int)($d['kunde_id'] ?? 0)) : 0;
    $bereiche = array_values(array_unique(array_filter((array)($d['bereiche'] ?? []), fn($b) => preg_match('/^[pk]:\d+$/', (string)$b))));
    $ganz = $bereiche ? 0 : 1;
    // Ganzes Lager: es darf keine andere offene Inventur dasselbe Lager ganz zählen.
    if ($ganz && (int) scalar("SELECT COUNT(*) FROM lg_inventur WHERE status<>'abgeschlossen' AND ganz=1 AND lager=? AND (lager='1' OR COALESCE(kunde_id,0) IN (0,?) OR ?=0)", [$lager, $kunde, $kunde]) > 0)
        return ['ok' => false, 'fehler' => 'Für dieses Lager läuft schon eine Inventur über das ganze Lager. Erst abschließen oder Plätze einzeln wählen.'];
    foreach ($bereiche as $b) {
        $x = one("SELECT i.name FROM lg_inventur_bereich b JOIN lg_inventur i ON i.id=b.inventur_id WHERE b.sperre=?", [$b]);
        if ($x) return ['ok' => false, 'fehler' => inv_ort_name($b) . ' ist schon in der offenen Inventur „' . $x['name'] . '".'];
    }
    $wa = implode(',', array_intersect((array)($d['warenarten'] ?? []), array_keys(erp_inv_warenarten())));
    $z  = implode(',', array_values(array_filter(array_map('intval', (array)($d['zaehler'] ?? [])))));
    $num = fn($k, $std) => isset($d[$k]) && trim((string)$d[$k]) !== '' ? max(0, erp_menge_parse((string)$d[$k])) : $std;
    $stichtag = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['stichtag'] ?? '')) ? (string)$d['stichtag'] : gmdate('Y-m-d');
    q("INSERT INTO lg_inventur (name, stichtag, lager, kunde_id, warenarten, ganz, zaehler, nachzaehl_prozent, tol_gewogen, tol_stk, klein_eur, klein_prozent, status, notiz, angelegt, angelegt_von)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'zaehlen',?,?,?)",
      [mb_substr($name, 0, 190), $stichtag, $lager, $kunde ?: null, $wa, $ganz, $z,
       isset($d['nachzaehl_prozent']) && trim((string)$d['nachzaehl_prozent']) !== '' ? erp_menge_parse((string)$d['nachzaehl_prozent']) : null,
       $num('tol_gewogen', 1), $num('tol_stk', 0), $num('klein_eur', 20), $num('klein_prozent', 2),
       trim((string)($d['notiz'] ?? '')) ?: null, jetzt_utc(), lg_uid() ?: null]);
    $id = insert_id();
    foreach ($bereiche as $b) inv_bereich_anlegen($id, $b);
    return ['ok' => true, 'id' => $id];
}
// Bereich in einer Inventur anlegen (bzw. vorhandenen liefern). Rückgabe Zeile oder ['fehler'=>…] wenn der Bereich in einer
// anderen offenen Inventur steckt.
function inv_bereich_anlegen(int $inv_id, string $key): array {
    $b = one("SELECT * FROM lg_inventur_bereich WHERE inventur_id=? AND schluessel=?", [$inv_id, $key]);
    if ($b) return $b;
    $x = one("SELECT i.name FROM lg_inventur_bereich b JOIN lg_inventur i ON i.id=b.inventur_id WHERE b.sperre=? AND b.inventur_id<>?", [$key, $inv_id]);
    if ($x) return ['fehler' => inv_ort_name($key) . ' gehört gerade zur Inventur „' . $x['name'] . '".'];
    try {
        q("INSERT INTO lg_inventur_bereich (inventur_id, schluessel, name, status, sperre) VALUES (?,?,?,'offen',?)", [$inv_id, $key, mb_substr(inv_ort_name($key) ?: $key, 0, 120), $key]);
    } catch (\Throwable $e) { return ['fehler' => inv_ort_name($key) . ' ist gerade in einer anderen Inventur.']; }
    return one("SELECT * FROM lg_inventur_bereich WHERE id=?", [insert_id()]);
}
function inv_bereiche(int $inv_id): array {
    return all("SELECT * FROM lg_inventur_bereich WHERE inventur_id=? ORDER BY (status='fertig'), name", [$inv_id]);
}
function inv_bereich(int $id): ?array { return $id > 0 ? one("SELECT * FROM lg_inventur_bereich WHERE id=?", [$id]) : null; }
// Darf dieser Nutzer in dieser Inventur zählen? Admin immer; sonst eingetragene Zähler (keine Liste = alle Lager-Leute).
function inv_darf_zaehlen(array $inv): bool {
    if ((string)$inv['status'] !== 'zaehlen' && (string)$inv['status'] !== 'auswerten') return false;
    if (lg_ist_admin()) return true;
    $z = array_filter(array_map('intval', explode(',', (string)$inv['zaehler'])));
    return !$z || in_array(lg_uid(), $z, true);
}
// Fortschritt: Plätze gezählt x von y, gescannte Objekte, offene Klärfälle, offene Nachzählungen.
function inv_fortschritt(array $inv): array {
    $id = (int)$inv['id'];
    $fertig = (int) scalar("SELECT COUNT(*) FROM lg_inventur_bereich WHERE inventur_id=? AND status='fertig'", [$id]);
    $angelegt = (int) scalar("SELECT COUNT(*) FROM lg_inventur_bereich WHERE inventur_id=?", [$id]);
    $gesamt = $angelegt;
    if ((int)$inv['ganz']) {   // ganzes Lager: alle aktiven Plätze dieses Lagers (dazu Kisten, sobald sie gezählt werden)
        $pl = (int) scalar("SELECT COUNT(*) FROM lg_platz WHERE aktiv=1 AND lager=?", [(string)$inv['lager'] === '2' ? 'lager2' : 'lager1']);
        $gesamt = max($angelegt, $pl + (int) scalar("SELECT COUNT(*) FROM lg_inventur_bereich WHERE inventur_id=? AND schluessel LIKE 'k:%'", [$id]));
    }
    return [
        'fertig' => $fertig, 'gesamt' => $gesamt,
        'gescannt' => (int) scalar("SELECT COUNT(*) FROM lg_inventur_zaehlung WHERE inventur_id=? AND ersetzt=0", [$id]),
        'klaerfaelle' => (int) scalar("SELECT COUNT(*) FROM lg_inventur_zaehlung WHERE inventur_id=? AND obj='k' AND ersetzt=0 AND kf_charge_id IS NULL", [$id]),
        'nachzaehlen' => (int) scalar("SELECT COUNT(*) FROM lg_inventur_diff WHERE inventur_id=? AND status='nachzaehlen'", [$id]),
        'offen' => (int) scalar("SELECT COUNT(*) FROM lg_inventur_diff WHERE inventur_id=? AND status IN ('offen','nachzaehlen')", [$id]),
    ];
}
function inv_mz($v): string { return rtrim(rtrim(number_format((float)$v, 3, ',', '.'), '0'), ','); }
