<?php
// Lokales Testdaten-Werkzeug: das Lager mit Testbeständen füllen, damit man Produktion / Bestand / Einkauf
// OFFLINE durchspielen kann (im Flugzeug). NUR lokal nutzbar (ist_lokal()); die Seite dazu ist
// module/system/testdaten.php. Idempotent – erkennbar am Marker in charge.notiz ('TESTDATEN …').
require_once __DIR__ . '/../core/schema.php';

// Füllt Rohstoffe (die in Rezepturen vorkommen) + alle Verpackungs-Items mit einer grossen, freien Charge,
// sofern sie noch keinen Bestand und keine Testcharge haben. Rückgabe: ['neu'=>int, 'schon'=>int].
function testdaten_lager_fuellen(): array {
    $ids = [];
    foreach (all("SELECT DISTINCT item_id FROM rezeptur_zutat WHERE item_id IS NOT NULL") as $r) $ids[(int)$r['item_id']] = 1;
    foreach (all("SELECT id FROM item WHERE kategorie='verpackung'") as $r) $ids[(int)$r['id']] = 1;
    $neu = 0; $schon = 0;
    foreach (array_keys($ids) as $iid) {
        if ($iid <= 0) continue;
        $hat = (int) scalar("SELECT COUNT(*) FROM charge WHERE item_id=? AND (menge_verfuegbar>0 OR notiz LIKE 'TESTDATEN%')", [$iid]);
        if ($hat) { $schon++; continue; }
        $einheit = (string) scalar("SELECT einheit FROM item WHERE id=?", [$iid]) ?: 'Stück';
        q("INSERT INTO charge (charge_nr,item_id,menge,menge_verfuegbar,einheit,wareneingang,status,notiz,angelegt)
           VALUES (?,?,?,?,?,CURDATE(),'frei',?,?)",
          ['TEST-' . $iid, $iid, 1000000, 1000000, $einheit, 'TESTDATEN (lokal) – jederzeit löschbar', gmdate('Y-m-d H:i:s')]);
        $neu++;
    }
    if (function_exists('meta_set')) meta_set('bedarf_version', (string)((int) meta_get('bedarf_version', 0) + 1));   // Verfügbarkeit neu rechnen
    return ['neu' => $neu, 'schon' => $schon];
}

// Entfernt die (unangetasteten) Test-Chargen wieder. Bereits im Spiel verbrauchte Testchargen bleiben, damit
// keine Rückverfolgung ins Leere zeigt. Rückgabe: Anzahl entfernter Chargen.
function testdaten_lager_zuruecksetzen(): int {
    $n = (int) scalar("SELECT COUNT(*) FROM charge WHERE notiz LIKE 'TESTDATEN%' AND menge_verfuegbar=menge");
    q("DELETE FROM charge WHERE notiz LIKE 'TESTDATEN%' AND menge_verfuegbar=menge");
    if (function_exists('meta_set')) meta_set('bedarf_version', (string)((int) meta_get('bedarf_version', 0) + 1));
    return $n;
}

// Kurz-Statistik fürs UI.
function testdaten_lager_stat(): array {
    return [
        'test'   => (int) scalar("SELECT COUNT(*) FROM charge WHERE notiz LIKE 'TESTDATEN%'"),
        'frei'   => (int) scalar("SELECT COUNT(*) FROM charge WHERE menge_verfuegbar>0"),
        'roh'    => (int) scalar("SELECT COUNT(DISTINCT item_id) FROM rezeptur_zutat WHERE item_id IS NOT NULL"),
        'verp'   => (int) scalar("SELECT COUNT(*) FROM item WHERE kategorie='verpackung'"),
    ];
}
