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
