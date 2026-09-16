<?php
// Import der ZUGELASSENEN EU-Health-Claims aus dem Register-CSV in die Tabelle health_claim.
// Nur Status "Authorised". Verknuepft die Substanz mit unseren Naehrstoffen (EN->DE-Aliasse + Teilstring),
// damit die Claims automatisch im PIB der Produkte mit diesem Naehrstoff erscheinen.
//
// Aufruf:  php tools/import_health_claims.php "<csv>"            (Testlauf, schreibt nichts)
//          php tools/import_health_claims.php "<csv>" --reset --write   (leert die Tabelle und importiert)
//          php tools/import_health_claims.php "<csv>" --write           (idempotent ergaenzen per entry_id)
require_once dirname(__DIR__) . '/core/config.php';
require_once BX_ROOT . '/core/schema.php';
init_schema();   // sicherstellen, dass health_claim + entry_id existieren
q("ALTER TABLE health_claim MODIFY bedingung TEXT NULL");           // Bedingungstexte sind lang (EU-Register)
q("ALTER TABLE health_claim MODIFY entry_id VARCHAR(120) NULL");    // manche Entry-Ids sind laenger

$csv = null; $reset = false; $write = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--reset') $reset = true;
    elseif ($a === '--write') $write = true;
    elseif ($csv === null) $csv = $a;
}
if (!$csv || !is_file($csv)) { fwrite(STDERR, "CSV nicht gefunden: " . (string)$csv . "\n"); exit(1); }

// EN -> DE fuer die Verknuepfung mit unseren Naehrstoffen (nur wo die Namen sprachlich abweichen).
$alias = [
    'iron' => 'Eisen', 'zinc' => 'Zink', 'folate' => 'Folsäure', 'folic acid' => 'Folsäure',
    'copper' => 'Kupfer', 'iodine' => 'Jod', 'selenium' => 'Selen', 'potassium' => 'Kalium',
    'sodium' => 'Natrium', 'phosphorus' => 'Phosphor', 'manganese' => 'Mangan', 'chromium' => 'Chrom',
    'molybdenum' => 'Molybdän', 'calcium' => 'Calcium', 'magnesium' => 'Magnesium', 'biotin' => 'Biotin',
    'niacin' => 'Niacin', 'thiamine' => 'Thiamin', 'riboflavin' => 'Riboflavin', 'pantothenic acid' => 'Pantothensäure',
];
$nById = [];
foreach (all("SELECT id, name FROM naehrstoff") as $n) $nById[mb_strtolower(trim((string)$n['name']))] = (int)$n['id'];
$findNaehr = function (string $sub) use ($nById, $alias): ?int {
    $s = mb_strtolower(trim($sub));
    if ($s === '') return null;
    if (isset($nById[$s])) return $nById[$s];
    if (isset($alias[$s], $nById[mb_strtolower($alias[$s])])) return $nById[mb_strtolower($alias[$s])];
    foreach ($nById as $nm => $id) if ($nm !== '' && mb_strlen($nm) >= 3 && mb_strpos($s, $nm) !== false) return $id;
    foreach ($alias as $en => $de) if (mb_strpos($s, $en) !== false && isset($nById[mb_strtolower($de)])) return $nById[mb_strtolower($de)];
    return null;
};

$fh = fopen($csv, 'r');
$head = fgetcsv($fh);   // Kopfzeile ueberspringen
if ($reset && $write) { q("DELETE FROM health_claim"); echo "Tabelle geleert (--reset).\n"; }

$n = 0; $auth = 0; $linked = 0; $written = 0; $skip = 0;
while (($r = fgetcsv($fh)) !== false) {
    $n++;
    if (strcasecmp(trim((string)($r[7] ?? '')), 'Authorised') !== 0) continue;
    $sub   = trim((string)($r[1] ?? ''));
    $claim = trim(preg_replace('/\s+/', ' ', (string)($r[2] ?? '')));
    $cond  = trim(preg_replace('/\s+/', ' ', (string)($r[3] ?? '')));
    $reg   = trim((string)($r[6] ?? ''));
    $eid   = trim((string)($r[8] ?? ''));
    if ($claim === '') continue;
    $auth++;
    $nid = $findNaehr($sub);
    if ($nid) $linked++;
    if ($write) {
        if ($eid !== '' && (int) scalar("SELECT COUNT(*) FROM health_claim WHERE entry_id=?", [$eid]) > 0) { $skip++; continue; }
        $sortv = (int) scalar("SELECT COALESCE(MAX(sort),-1)+1 FROM health_claim WHERE " . ($nid ? "naehrstoff_id=" . (int)$nid : "naehrstoff_id IS NULL"));
        q("INSERT INTO health_claim (naehrstoff_id,stoff,claim,bedingung,quelle,entry_id,aktiv,sort) VALUES (?,?,?,?,?,?,1,?)",
          [$nid ?: null, mb_substr($sub, 0, 120), $claim, $cond ?: null, mb_substr($reg ?: 'EU 432/2012', 0, 80), $eid !== '' ? mb_substr($eid, 0, 120) : null, $sortv]);
        $written++;
    }
}
echo "Zeilen=$n · authorised=$auth · davon verknuepft=$linked" . ($write ? " · geschrieben=$written · uebersprungen=$skip" : " · (Testlauf, nichts geschrieben)") . "\n";
