<?php
// Öffentlicher JSON-Export des Novel-Food-Katalogs für die (statische) Website.
// Route: ?p=novelfood_export (steht in $PUBLIC -> kein Login). Immer FRISCH aus novelfood_katalog.
// Struktur = exakt das Website-Schema aus novelfood_export_schreiben() (core/novelfood_sync.php):
//   {stand, anzahl, quelle, sprachen, eintraege:[{name,code,pub,teil,status,status_code,
//     trivial,syn,beschreibung,beschreibung_de,erstellt,geaendert}]}
// Die Daten sind ohnehin öffentlich (EU-Katalog) – kein Secret nötig, wie rohstoffe_public / ki_job.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');   // 1 h – die Website zieht ohnehin nur monatlich

$eintraege = [];
if (function_exists('table_exists') && table_exists('novelfood_katalog')) {
    foreach (all("SELECT * FROM novelfood_katalog ORDER BY name") as $d) {
        $eintraege[] = [
            'name' => (string)($d['name'] ?? ''),            'code' => (string)($d['code'] ?? ''),
            'pub' => (string)($d['pub'] ?? ''),              'teil' => (string)($d['teil'] ?? ''),
            'status' => (string)($d['status'] ?? ''),        'status_code' => (string)($d['status_code'] ?? ''),
            'trivial' => (string)($d['trivial'] ?? ''),      'syn' => (string)($d['syn'] ?? ''),
            'beschreibung' => (string)($d['beschreibung'] ?? ''), 'beschreibung_de' => (string)($d['beschreibung_de'] ?? ''),
            'erstellt' => (string)($d['erstellt'] ?? ''),    'geaendert' => (string)($d['geaendert'] ?? ''),
        ];
    }
}
$stand = substr((string) meta_get('novelfood_last_run', ''), 0, 10) ?: gmdate('Y-m-d');
echo json_encode([
    'stand'     => $stand,
    'anzahl'    => count($eintraege),
    'quelle'    => 'EU Novel Food Katalog (Kommission)',
    'sprachen'  => 'de+en',
    'eintraege' => $eintraege,
], JSON_UNESCAPED_UNICODE);
exit;
