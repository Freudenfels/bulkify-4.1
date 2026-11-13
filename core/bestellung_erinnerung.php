<?php
// Bestell-Erinnerungen an Portal-Lieferanten (täglicher Cron) – Regeln nach Fable-Prüfung 6, P1/P2 (13.11.2026):
// - eine Mail je Lieferant und Tag mit allen fälligen Bestellungen (nicht je Bestellung)
// - nur Bestellungen mit mindestens einer Position mit offenem Rest (nicht „Rest storniert“, nicht komplett geliefert)
// - höchstens 3 Erinnerungen je Bestellung, danach einmal eine Aufgabe ans Team „Lieferant reagiert nicht: BE-…“
// - gesperrte Lieferanten bekommen nichts
// - erinnert_am / erinnert_anzahl nur bei erfolgreichem Versand
// Siehe bestellung_erinnerung.md.

const BER_MAX = 3;

function ber_schema(): void {
    static $ok = false; if ($ok) return; $ok = true;
    try {
        ensure_column('bestellung', 'erinnert_anzahl', "INT NOT NULL DEFAULT 0");
        ensure_column('bestellung', 'erinnert_aufgabe_am', "DATETIME NULL");   // UTC, Aufgabe „Lieferant reagiert nicht“ angelegt
    } catch (\Throwable $e) {}
}

// Positionen mit offenem Rest (Menge > geliefert, nicht „Rest storniert“).
function ber_offene_positionen(int $bid): array {
    return all("SELECT bp.id, bp.menge, COALESCE(bp.geliefert_menge,0) AS geliefert, bp.einheit, COALESCE(NULLIF(bp.bezeichnung,''), i.name) AS name
                FROM bestellung_position bp LEFT JOIN item i ON i.id=bp.item_id
                WHERE bp.bestellung_id=? AND bp.menge>0 AND bp.rest_storniert_am IS NULL AND COALESCE(bp.geliefert_menge,0) < bp.menge - 0.0001
                ORDER BY bp.sort, bp.id", [$bid]);
}

// Fällige Bestellungen je Lieferant: [lieferant_id => [bestellung, …]]. Ohne Seiteneffekt (auch für eine Vorschau nutzbar).
function ber_faellig(int $tage = 3, int $minAbstandStd = 20): array {
    ber_schema();
    $cand = all("SELECT b.* FROM bestellung b JOIN lieferanten l ON l.id=b.lieferant_id
                 WHERE COALESCE(b.bestaetigt,0)=0 AND b.status NOT IN ('geliefert','storniert','offen') AND b.angekommen_am IS NULL
                   AND COALESCE(l.gesperrt,0)=0 AND TRIM(COALESCE(l.email,'')) <> ''
                 ORDER BY b.lieferant_id, b.id");
    $out = [];
    foreach ($cand as $b) {
        if (!bestellung_ueberfaellig($b, $tage)) continue;
        if ((int)($b['erinnert_anzahl'] ?? 0) >= BER_MAX) continue;
        if (!empty($b['erinnert_am']) && (time() - strtotime((string)$b['erinnert_am'] . ' UTC')) < $minAbstandStd * 3600) continue;
        if (!ber_offene_positionen((int)$b['id'])) continue;
        $out[(int)$b['lieferant_id']][] = $b;
    }
    return $out;
}

// Erfolgreiche Erinnerung an einer Bestellung vermerken (Zähler + Zeit + Verlauf).
function ber_vermerken(array $b, bool $auto): void {
    ber_schema();
    q("UPDATE bestellung SET erinnert_am=UTC_TIMESTAMP(), erinnert_anzahl=COALESCE(erinnert_anzahl,0)+1 WHERE id=?", [(int)$b['id']]);
    if (!empty($b['lieferant_id']) && function_exists('log_aktivitaet'))
        log_aktivitaet('lieferant', (int)$b['lieferant_id'], 'team', 'Erinnerung an Bestellung ' . $b['nummer'] . ($auto ? ' (automatisch)' : '') . ' gesendet.', 'bestellung', 'bestellung', (int)$b['id']);
}

// Nach 3 Erinnerungen ohne Bestätigung: einmal eine Aufgabe ans Team.
function ber_aufgaben_nach_max(): int {
    ber_schema();
    $n = 0;
    foreach (all("SELECT b.id, b.nummer, l.firma FROM bestellung b LEFT JOIN lieferanten l ON l.id=b.lieferant_id
                  WHERE COALESCE(b.bestaetigt,0)=0 AND b.status NOT IN ('geliefert','storniert','offen') AND b.angekommen_am IS NULL
                    AND COALESCE(b.erinnert_anzahl,0) >= ? AND b.erinnert_aufgabe_am IS NULL", [BER_MAX]) as $b) {
        if (function_exists('aufgabe_neu')) {
            aufgabe_neu('Lieferant reagiert nicht: ' . $b['nummer'],
                ($b['firma'] ?: 'Der Lieferant') . ' hat die Bestellung ' . $b['nummer'] . ' nach ' . BER_MAX . ' Erinnerungen nicht bestätigt. Bitte telefonisch nachfassen (dann „Telefonisch bestätigt“) oder die Bestellung stornieren.',
                1, null, null, null, 'bestellung', (int)$b['id'], '?p=bestellung&id=' . (int)$b['id']);
        }
        q("UPDATE bestellung SET erinnert_aufgabe_am=UTC_TIMESTAMP() WHERE id=?", [(int)$b['id']]);
        $n++;
    }
    return $n;
}

// Täglicher Lauf. Rückgabe ['gesendet' (Mails), 'bestellungen', 'geprueft', 'aufgaben', 'fehler' => []].
function ber_cron(int $tage = 3): array {
    if (!function_exists('mail_senden')) require_once __DIR__ . '/mail.php';
    if (!function_exists('beleg_firma')) require_once __DIR__ . '/pdf_beleg.php';
    $faellig = ber_faellig($tage);
    $mails = 0; $nb = 0; $geprueft = 0; $fehler = [];
    foreach ($faellig as $lid => $liste) {
        $geprueft += count($liste);
        try {
            $f = mail_lieferant_erinnerung_sammel((int)$lid, $liste);
        } catch (\Throwable $e) { $f = $e->getMessage(); }
        if ($f !== '') { $fehler[] = 'Lieferant ' . $lid . ': ' . $f; continue; }   // erinnert_am bleibt leer -> morgen erneut
        $mails++;
        foreach ($liste as $b) { ber_vermerken($b, true); $nb++; }
    }
    $auf = 0;
    try { $auf = ber_aufgaben_nach_max(); } catch (\Throwable $e) { $fehler[] = 'Aufgaben: ' . $e->getMessage(); }
    return ['gesendet' => $mails, 'bestellungen' => $nb, 'geprueft' => $geprueft, 'aufgaben' => $auf, 'fehler' => $fehler];
}

// Sperre gegen Doppellauf (app_meta cron_sperre = UTC-Zeitstempel; nach 15 Minuten verfällt sie). true = Sperre bekommen.
function cron_sperre_holen(int $minuten = 15): bool {
    $jetzt = gmdate('Y-m-d H:i:s');
    $alt = gmdate('Y-m-d H:i:s', time() - $minuten * 60);
    try {
        q("INSERT IGNORE INTO app_meta (k, v) VALUES ('cron_sperre', '')");
        $st = db()->prepare("UPDATE app_meta SET v=? WHERE k='cron_sperre' AND (v='' OR v < ?)");
        $st->execute([$jetzt, $alt]);
        return $st->rowCount() === 1;
    } catch (\Throwable $e) { return false; }
}
function cron_sperre_freigeben(): void {
    try { q("UPDATE app_meta SET v='' WHERE k='cron_sperre'"); } catch (\Throwable $e) {}
}
