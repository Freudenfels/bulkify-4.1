<?php
// Einkauf: jede Bestellung einzeln (Paket 13.11.2026, Teil 6). Regel Nico: nie eine Bestellung mit mehreren Positionen,
// Lieferanten oder Arten – damit jede CoA, jede Lieferung und jedes fehlende Papier genau einer Bestellung gehört.
// Hier: Aufteilen einer Bestellung je Position (ohne etwas zu verlieren). Braucht core/schema.php.

// Bestellung je Position aufteilen: die erste Position bleibt in der Bestellung, jede weitere bekommt eine eigene BE-Nummer.
// Die Positionen werden VERSCHOBEN (nicht kopiert) – so bleiben Liefermengen, stornierte Positionen und alle Verknüpfungen
// (Chargen, bestellung_charge, Reservierungen, Dokumente je Position, Lieferanten-Aufkleber) unverändert gültig.
// Kopfdaten (Lieferant, Status, Daten, Versand) werden übernommen; es wird KEIN Lieferant gesetzt und KEINE Nachricht verschickt.
// Rückgabe ['ok'=>bool, 'msg'=>…, 'neu'=>[['nummer'=>…, 'id'=>…, 'position'=>…], …]].
function bestellung_aufteilen_je_position(int $bestellung_id, string $vermerk, string $wer = ''): array {
    $b = one("SELECT * FROM bestellung WHERE id=?", [$bestellung_id]);
    if (!$b) return ['ok' => false, 'msg' => 'Bestellung nicht gefunden.', 'neu' => []];
    $pos = all("SELECT * FROM bestellung_position WHERE bestellung_id=? ORDER BY sort, id", [$bestellung_id]);
    if (count($pos) < 2) return ['ok' => false, 'msg' => $b['nummer'] . ' hat nur eine Position – nichts aufzuteilen.', 'neu' => []];
    $kopf = $b;
    foreach (['id', 'nummer', 'angelegt', 'aktualisiert', 'v3_id'] as $weg) unset($kopf[$weg]);
    $neu = [];
    db()->beginTransaction();
    try {
        foreach (array_slice($pos, 1) as $p) {
            $k = $kopf;
            $k['nummer'] = naechste_nummer('BE');
            $k['notiz'] = trim((string)($b['notiz'] ?? '') . "\n[" . gmdate('d.m.Y') . '] Aufgeteilt aus ' . $b['nummer'] . ' – ' . $vermerk);
            $sp = array_keys($k);
            q("INSERT INTO bestellung (" . implode(',', array_map(fn($c) => "`$c`", $sp)) . ", angelegt) VALUES (" . implode(',', array_fill(0, count($sp), '?')) . ", ?)",
              array_merge(array_values($k), [(string)($b['angelegt'] ?? gmdate('Y-m-d H:i:s'))]));
            $nid = (int) insert_id();
            q("UPDATE bestellung_position SET bestellung_id=? WHERE id=?", [$nid, (int)$p['id']]);
            try { q("UPDATE lief_aufkleber SET bestellung_id=? WHERE bestellung_position_id=?", [$nid, (int)$p['id']]); } catch (Throwable $e) {}
            q("UPDATE dokument SET objekt_id=? WHERE objekt_typ='bestellung' AND objekt_id=? AND bestellung_position_id=?", [$nid, $bestellung_id, (int)$p['id']]);
            bestellung_einzeln_status($nid, $b);
            $was = trim((string)($p['bezeichnung'] ?? '')) ?: (string) scalar("SELECT name FROM item WHERE id=?", [(int)$p['item_id']]);
            log_aktivitaet('bestellung', $nid, 'system', 'Aufgeteilt aus ' . $b['nummer'] . ' (Position: ' . $was . ') – ' . $vermerk . ($wer !== '' ? ' (' . $wer . ')' : ''), 'status');
            $neu[] = ['nummer' => $k['nummer'], 'id' => $nid, 'position' => $was];
        }
        bestellung_einzeln_status($bestellung_id, $b);
        log_aktivitaet('bestellung', $bestellung_id, 'system', 'Aufgeteilt in Einzelbestellungen je Position: ' . implode(', ', array_column($neu, 'nummer')) . ' – ' . $vermerk . ($wer !== '' ? ' (' . $wer . ')' : ''), 'status');
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        return ['ok' => false, 'msg' => 'Aufteilen fehlgeschlagen: ' . $e->getMessage(), 'neu' => []];
    }
    if (function_exists('bedarf_bump')) { try { bedarf_bump(); } catch (Throwable $e) {} }
    return ['ok' => true, 'msg' => $b['nummer'] . ' aufgeteilt: ' . implode(', ', array_column($neu, 'nummer')), 'neu' => $neu];
}

// Status einer (aufgeteilten) Bestellung aus ihren gespeicherten Positionen – ohne Liefermengen neu zu rechnen:
// alles geliefert bzw. Rest storniert -> geliefert (Ankunftsdatum der ursprünglichen Bestellung), sonst offen weiter wie der Kopf.
function bestellung_einzeln_status(int $bid, array $orig): void {
    $ps = all("SELECT menge, COALESCE(geliefert_menge,0) AS gel, rest_storniert_am FROM bestellung_position WHERE bestellung_id=?", [$bid]);
    if (!$ps || in_array((string)$orig['status'], ['storniert', 'offen'], true)) return;
    $alleZu = true; $etwas = false;
    foreach ($ps as $p) {
        if ((float)$p['gel'] > 0) $etwas = true;
        if (empty($p['rest_storniert_am']) && (float)$p['gel'] + 1e-9 < (float)$p['menge'] * 0.98) $alleZu = false;
    }
    // Nur stornierte Positionen ohne jede Lieferung -> die Einzelbestellung ist storniert (Vermerk bleibt in der Position).
    if (!$etwas && !array_filter($ps, fn($p) => empty($p['rest_storniert_am']))) { q("UPDATE bestellung SET status='storniert' WHERE id=?", [$bid]); return; }
    if ($alleZu) q("UPDATE bestellung SET status='geliefert', angekommen_am=COALESCE(angekommen_am, ?, teilgeliefert_am, CURDATE()) WHERE id=? AND status IN ('gesendet','bestellt','geliefert')", [$orig['angekommen_am'] ?? null, $bid]);
    else q("UPDATE bestellung SET status=IF(status='geliefert','bestellt',status), angekommen_am=NULL, teilgeliefert_am=" . ($etwas ? "COALESCE(teilgeliefert_am, ?)" : "NULL") . " WHERE id=?",
           $etwas ? [$orig['teilgeliefert_am'] ?? $orig['angekommen_am'] ?? gmdate('Y-m-d'), $bid] : [$bid]);
}

// ---- Versand und Sendung an der Bestellung (Teil 6.4) -------------------------------------------------------------------
function bestellung_sendung_schema(): void {
    static $ok = false; if ($ok) return; $ok = true;
    try {
        ensure_column('bestellung', 'sendung_art', "VARCHAR(20) NULL");       // paket | palette | container | sonstiges
        ensure_column('bestellung', 'sendung_anzahl', "INT NULL");            // z. B. 2 (Paletten) / 5 (Pakete)
        ensure_column('bestellung', 'palettenart', "VARCHAR(20) NULL");       // euro | einweg | tausch
        ensure_column('bestellung', 'kisten_anzahl', "INT NULL");
        ensure_column('bestellung', 'gewicht_kg', "DECIMAL(12,2) NULL");
        ensure_column('bestellung', 'masse', "VARCHAR(120) NULL");
        ensure_column('bestellung', 'stellplaetze', "INT NULL");
    } catch (Throwable $e) {}
}
function bestellung_sendung_arten(): array { return ['paket' => 'Paket', 'palette' => 'Palette', 'container' => 'Container', 'sonstiges' => 'Sonstiges']; }
function bestellung_palettenarten(): array { return ['euro' => 'Euro-Palette', 'einweg' => 'Einweg-Palette', 'tausch' => 'Tausch-/Wechselpalette']; }
// Kurztext „2 × Palette (Euro-Palette), 12 Kartons, 840 kg, 2 Stellplätze“
function bestellung_sendung_text(int $bid): string {
    bestellung_sendung_schema();
    $b = one("SELECT sendung_art, sendung_anzahl, palettenart, kisten_anzahl, gewicht_kg, masse, stellplaetze FROM bestellung WHERE id=?", [$bid]) ?: [];
    $t = [];
    if (!empty($b['sendung_art'])) $t[] = ((int)$b['sendung_anzahl'] > 0 ? (int)$b['sendung_anzahl'] . ' × ' : '') . (bestellung_sendung_arten()[$b['sendung_art']] ?? $b['sendung_art'])
        . (!empty($b['palettenart']) ? ' (' . (bestellung_palettenarten()[$b['palettenart']] ?? $b['palettenart']) . ')' : '');
    if ((int)($b['kisten_anzahl'] ?? 0) > 0) $t[] = (int)$b['kisten_anzahl'] . ' Kartons';
    if ((float)($b['gewicht_kg'] ?? 0) > 0) $t[] = rtrim(rtrim(number_format((float)$b['gewicht_kg'], 2, ',', '.'), '0'), ',') . ' kg';
    if ((int)($b['stellplaetze'] ?? 0) > 0) $t[] = (int)$b['stellplaetze'] . ' Stellplätze';
    if (!empty($b['masse'])) $t[] = (string)$b['masse'];
    return implode(', ', $t);
}
// Tracking-Nummern: eine je Zeile (oder durch Komma/Semikolon getrennt) -> lieferung_paket; die erste auch in bestellung.tracking.
// Entfernte Nummern ohne Wareneingang werden gelöscht. Rückgabe: Anzahl Nummern.
function bestellung_tracking_setzen(int $bid, string $text, string $dienst = ''): int {
    $nrs = array_values(array_unique(array_filter(array_map(fn($x) => preg_replace('/\s+/', '', $x), preg_split('/[\r\n,;]+/', $text)))));
    foreach ($nrs as $t) {
        $t = mb_substr($t, 0, 120);
        $da = one("SELECT id, bestellung_id FROM lieferung_paket WHERE tracking=?", [$t]);
        if (!$da) q("INSERT INTO lieferung_paket (bestellung_id, tracking, spediteur, angelegt) VALUES (?,?,?,?)", [$bid, $t, $dienst !== '' ? $dienst : null, gmdate('Y-m-d H:i:s')]);
        elseif ((int)$da['bestellung_id'] === $bid && $dienst !== '') q("UPDATE lieferung_paket SET spediteur=COALESCE(spediteur, ?) WHERE id=?", [$dienst, (int)$da['id']]);
    }
    $alle = array_column(all("SELECT tracking FROM lieferung_paket WHERE bestellung_id=?", [$bid]), 'tracking');
    foreach (array_diff($alle, $nrs) as $weg) q("DELETE FROM lieferung_paket WHERE bestellung_id=? AND tracking=? AND COALESCE(angekommen,0)=0", [$bid, $weg]);
    q("UPDATE bestellung SET tracking=? WHERE id=?", [$nrs[0] ?? null, $bid]);
    return count($nrs);
}
function bestellung_tracking_liste(int $bid): array {
    $l = all("SELECT tracking, spediteur, angekommen FROM lieferung_paket WHERE bestellung_id=? ORDER BY id", [$bid]);
    $t = (string) scalar("SELECT tracking FROM bestellung WHERE id=?", [$bid]);
    if ($t !== '' && !in_array($t, array_column($l, 'tracking'), true)) array_unshift($l, ['tracking' => $t, 'spediteur' => null, 'angekommen' => 0]);
    return $l;
}
// Was fehlt an einer offenen Bestellung? (für „Offen je Lieferant“) – Bestätigung, CoA, Tracking, Lieferung.
function bestellung_fehlt(array $b): array {
    $f = [];
    $st = (string)($b['status'] ?? '');
    if ($st === 'storniert') return [];
    if ($st === 'offen') return ['noch nicht bestellt'];
    if ($st === 'gesendet' && empty($b['bestaetigt'])) $f[] = 'Bestätigung';
    $bid = (int)($b['id'] ?? $b['bestellung_id'] ?? 0);
    $istRoh = (int) scalar("SELECT COUNT(*) FROM bestellung_position bp JOIN item i ON i.id=bp.item_id WHERE bp.bestellung_id=? AND i.kategorie IN ('rohstoff','fertig')", [$bid])
            + (int) scalar("SELECT COUNT(*) FROM bestellung_position WHERE bestellung_id=? AND item_id IS NULL", [$bid]);
    if ($istRoh > 0) {
        $coa = (int) scalar("SELECT COUNT(*) FROM dokument WHERE objekt_typ='bestellung' AND objekt_id=? AND typ IN ('coa','analyse')", [$bid])
             + (int) scalar("SELECT COUNT(*) FROM dokument d JOIN bestellung_position bp ON bp.id=d.bestellung_position_id WHERE bp.bestellung_id=? AND d.typ IN ('coa','analyse')", [$bid]);
        if ($coa === 0) $f[] = 'CoA';
    }
    if (empty($b['angekommen_am'])) {
        if (trim((string)($b['tracking'] ?? '')) === '' && (int) scalar("SELECT COUNT(*) FROM lieferung_paket WHERE bestellung_id=?", [$bid]) === 0) $f[] = 'Tracking';
        $f[] = 'Lieferung';
    }
    return $f;
}
