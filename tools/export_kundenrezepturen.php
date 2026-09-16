<?php
// Erzeugt ein gezieltes .sql, das NUR die Kunden-eigenen Rezepturen (+ Zutaten) nachliefert –
// idempotent und verknüpft über v3_id (nicht über lokale IDs, die auf beta anders sind).
// Ändert nichts anderes (keine Rohstoffe/Produkte/Aufträge). Zum Hochladen über ?p=db_import.
//
// Aufruf:  php tools/export_kundenrezepturen.php            (schreibt data/kundenrezepturen_<stamp>.sql)
//          php tools/export_kundenrezepturen.php --apply    (führt es zusätzlich lokal aus = Selbsttest)
if (!defined('BX_ROOT')) define('BX_ROOT', dirname(__DIR__));
require_once BX_ROOT . '/core/config.php';
require_once BX_ROOT . '/core/schema.php';

$pdo  = db();
$q    = fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v);
$numN = fn($v) => ($v === null || $v === '') ? 'NULL' : (string)(0 + $v);   // Zahl oder NULL

$L = [];
$L[] = "-- bulkify: gezielte Nachlieferung der Kunden-Rezepturen (idempotent, Verknüpfung über v3_id).";
$L[] = "-- Erzeugt " . gmdate('Y-m-d H:i') . " UTC. Aendert NUR rezeptur + rezeptur_zutat der v3-Kunden-Rezepturen.";
$L[] = "";

// Nur kundeneigene, v3-verknüpfte Rezepturen, deren Kunde selbst eine v3_id hat (sonst nicht zuordenbar).
$rez = all("SELECT r.*, k.v3_id AS kunde_v3 FROM rezeptur r JOIN kunden k ON k.id=r.kunde_id
            WHERE r.v3_id IS NOT NULL AND k.v3_id IS NOT NULL ORDER BY r.id");
$nRez = 0; $nZut = 0;
foreach ($rez as $r) {
    $v3rid = (int)$r['v3_id']; $kv3 = (int)$r['kunde_v3'];
    $kid   = "(SELECT id FROM kunden WHERE v3_id=$kv3 LIMIT 1)";           // Kunden-ID AUF beta (per v3_id)
    $rezId = "(SELECT id FROM (SELECT id FROM rezeptur WHERE v3_id=$v3rid LIMIT 1) t1)";  // Rezeptur-ID auf beta

    // 1) Fehlende Rezeptur anlegen – nur wenn der Kunde auf beta existiert und die v3_id noch fehlt.
    //    Derived-Table-Wrap bei NOT EXISTS wegen MySQL-Fehler 1093 (gleiche Zieltabelle).
    $L[] = "INSERT INTO rezeptur (nummer,name,kunde_id,darreichungsform,kapselgroesse_id,exklusiv,status,freigabe_name,freigabe_am,notiz,v3_id) "
         . "SELECT " . $q($r['nummer']) . "," . $q($r['name']) . ",$kid," . $q($r['darreichungsform']) . "," . $numN($r['kapselgroesse_id']) . ","
         . (int)$r['exklusiv'] . "," . $q($r['status']) . "," . $q($r['freigabe_name']) . "," . $q($r['freigabe_am']) . "," . $q($r['notiz']) . ",$v3rid "
         . "FROM DUAL WHERE $kid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM rezeptur WHERE v3_id=$v3rid) t0);";

    // 2) Vorhandene Rezeptur korrekt verknüpfen/aktualisieren (falls auf beta ohne kunde_id importiert).
    $L[] = "UPDATE rezeptur SET kunde_id=$kid, exklusiv=" . (int)$r['exklusiv'] . ", status=" . $q($r['status'])
         . ", name=" . $q($r['name']) . ", darreichungsform=" . $q($r['darreichungsform'])
         . " WHERE v3_id=$v3rid AND $kid IS NOT NULL;";

    // 3) Zutaten idempotent neu aufbauen: erst löschen, dann einfügen (item_id auf beta per Name auflösen).
    $L[] = "DELETE FROM rezeptur_zutat WHERE rezeptur_id IN (SELECT id FROM (SELECT id FROM rezeptur WHERE v3_id=$v3rid) t2);";
    foreach (all("SELECT bezeichnung, menge_mg, sort FROM rezeptur_zutat WHERE rezeptur_id=? ORDER BY sort, id", [(int)$r['id']]) as $z) {
        $bez = $q($z['bezeichnung']);
        $L[] = "INSERT INTO rezeptur_zutat (rezeptur_id,item_id,bezeichnung,menge_mg,sort) "
             . "SELECT $rezId,(SELECT id FROM item WHERE kategorie='rohstoff' AND name=$bez LIMIT 1),$bez," . $numN($z['menge_mg']) . "," . (int)$z['sort'] . " "
             . "FROM DUAL WHERE $rezId IS NOT NULL;";
        $nZut++;
    }
    $nRez++;
}
$L[] = "";
$L[] = "-- Fertig: $nRez Kunden-Rezepturen, $nZut Zutaten.";
$sql = implode("\n", $L) . "\n";

$datei = BX_ROOT . '/data/kundenrezepturen_' . gmdate('Ymd_Hi') . '.sql';
if (!is_dir(dirname($datei))) @mkdir(dirname($datei), 0775, true);
file_put_contents($datei, $sql);
echo "Geschrieben: $datei\n$nRez Rezepturen, $nZut Zutaten, " . strlen($sql) . " Bytes\n";

if (in_array('--apply', $argv, true)) {
    require_once BX_ROOT . '/core/db_import.php';
    $res = db_import_sql($pdo, $sql);
    echo "Selbsttest lokal: {$res['ok']}/{$res['stmts']} Anweisungen ok, Fehler: " . count($res['fehler']) . "\n";
    foreach (array_slice($res['fehler'], 0, 5) as $f) echo "  FEHLER: $f\n";
}
