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

// Chunk-Groesse (Rezepturen je Datei) – kleine Dateien = kurze Requests (beta-TLS reisst bei langen ab).
$chunk = 12;
foreach ($argv as $a) if (preg_match('/^--chunk=(\d+)$/', $a, $m)) $chunk = max(1, (int)$m[1]);

// Nur kundeneigene, v3-verknüpfte Rezepturen, deren Kunde selbst eine v3_id hat (sonst nicht zuordenbar).
$rez = all("SELECT r.*, k.v3_id AS kunde_v3 FROM rezeptur r JOIN kunden k ON k.id=r.kunde_id
            WHERE r.v3_id IS NOT NULL AND k.v3_id IS NOT NULL ORDER BY r.id");

// Je Rezeptur einen Statement-Block bauen (bleibt zusammen in einer Datei).
$bloecke = []; $nRez = 0; $nZut = 0;
foreach ($rez as $r) {
    $v3rid = (int)$r['v3_id']; $kv3 = (int)$r['kunde_v3'];
    $kid   = "(SELECT id FROM kunden WHERE v3_id=$kv3 LIMIT 1)";
    $rezId = "(SELECT id FROM (SELECT id FROM rezeptur WHERE v3_id=$v3rid LIMIT 1) t1)";
    $b = [];
    $b[] = "-- Rezeptur v3#$v3rid (Kunde v3#$kv3): " . str_replace(["\r","\n"], ' ', (string)$r['name']);
    $b[] = "INSERT INTO rezeptur (nummer,name,kunde_id,darreichungsform,kapselgroesse_id,exklusiv,status,freigabe_name,freigabe_am,notiz,v3_id) "
         . "SELECT " . $q($r['nummer']) . "," . $q($r['name']) . ",$kid," . $q($r['darreichungsform']) . "," . $numN($r['kapselgroesse_id']) . ","
         . (int)$r['exklusiv'] . "," . $q($r['status']) . "," . $q($r['freigabe_name']) . "," . $q($r['freigabe_am']) . "," . $q($r['notiz']) . ",$v3rid "
         . "FROM DUAL WHERE $kid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM rezeptur WHERE v3_id=$v3rid) t0);";
    $b[] = "UPDATE rezeptur SET kunde_id=$kid, exklusiv=" . (int)$r['exklusiv'] . ", status=" . $q($r['status'])
         . ", name=" . $q($r['name']) . ", darreichungsform=" . $q($r['darreichungsform'])
         . " WHERE v3_id=$v3rid AND $kid IS NOT NULL;";
    $b[] = "DELETE FROM rezeptur_zutat WHERE rezeptur_id IN (SELECT id FROM (SELECT id FROM rezeptur WHERE v3_id=$v3rid) t2);";
    foreach (all("SELECT bezeichnung, menge_mg, sort FROM rezeptur_zutat WHERE rezeptur_id=? ORDER BY sort, id", [(int)$r['id']]) as $z) {
        $bez = $q($z['bezeichnung']);
        $b[] = "INSERT INTO rezeptur_zutat (rezeptur_id,item_id,bezeichnung,menge_mg,sort) "
             . "SELECT $rezId,(SELECT id FROM item WHERE kategorie='rohstoff' AND name=$bez LIMIT 1),$bez," . $numN($z['menge_mg']) . "," . (int)$z['sort'] . " "
             . "FROM DUAL WHERE $rezId IS NOT NULL;";
        $nZut++;
    }
    $bloecke[] = implode("\n", $b);
    $nRez++;
}

if (!is_dir(BX_ROOT . '/data')) @mkdir(BX_ROOT . '/data', 0775, true);
$stamp = gmdate('Ymd_Hi');
$teile = array_chunk($bloecke, $chunk);
$anzTeile = count($teile);
$dateien = [];
foreach ($teile as $i => $grp) {
    $kopf = "-- bulkify Kunden-Rezepturen – Teil " . ($i + 1) . " / $anzTeile (idempotent, per v3_id). Erzeugt $stamp UTC.\n"
          . "-- Ueber ?p=db_import hochladen. Ergaenzt NUR Rezepturen/Zutaten.\n\n";
    $sql = $kopf . implode("\n\n", $grp) . "\n";
    $datei = BX_ROOT . '/data/kundenrezepturen_' . $stamp . '_teil' . ($i + 1) . 'von' . $anzTeile . '.sql';
    file_put_contents($datei, $sql);
    $dateien[] = $datei;
    echo "Teil " . ($i + 1) . "/$anzTeile: " . basename($datei) . " (" . count($grp) . " Rezepturen, " . strlen($sql) . " Bytes)\n";
}
echo "Gesamt: $nRez Rezepturen, $nZut Zutaten in $anzTeile Dateien (chunk=$chunk).\n";

if (in_array('--apply', $argv, true)) {
    require_once BX_ROOT . '/core/db_import.php';
    $okAll = 0; $stAll = 0; $errAll = 0;
    foreach ($dateien as $d) { $res = db_import_sql($pdo, file_get_contents($d)); $okAll += $res['ok']; $stAll += $res['stmts']; $errAll += count($res['fehler']); }
    echo "Selbsttest lokal (alle Teile): $okAll/$stAll Anweisungen ok, Fehler: $errAll\n";
}
