<?php
// Import des EU-Novel-Food-Katalogs (JSON) in novelfood_katalog. Idempotent über code (bzw. name).
// Aufruf:  php tools/novelfood_import.php "PFAD/novelfood.json"
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/schema.php';
init_schema();

$datei = $argv[1] ?? '';
if (!is_file($datei)) { fwrite(STDERR, "Datei nicht gefunden: $datei\n"); exit(1); }
$roh = json_decode(file_get_contents($datei), true);
$eintraege = $roh['eintraege'] ?? (is_array($roh) ? $roh : null);
if (!is_array($eintraege)) { fwrite(STDERR, "Kein 'eintraege'-Array in der JSON.\n"); exit(1); }

$clean = function (?string $s): ?string {
    if ($s === null) return null;
    $s = str_replace('&nbsp;', ' ', $s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = trim(preg_replace('/\s+/', ' ', strip_tags($s)));
    return $s === '' ? null : $s;
};

$neu = $upd = 0;
foreach ($eintraege as $e) {
    $code = $clean($e['code'] ?? '') ?: null;
    $name = $clean($e['name'] ?? '');
    if ($name === null) continue;
    $daten = [
        'code' => $code, 'name' => mb_substr($name, 0, 255),
        'trivial' => $clean($e['trivial'] ?? '') !== null ? mb_substr($clean($e['trivial']), 0, 500) : null,
        'syn' => $clean($e['syn'] ?? '') !== null ? mb_substr($clean($e['syn']), 0, 500) : null,
        'status' => $clean($e['status'] ?? '') !== null ? mb_substr($clean($e['status']), 0, 120) : null,
        'status_code' => mb_substr((string)($e['status_code'] ?? ''), 0, 50) ?: null,
        'teil' => $clean($e['teil'] ?? '') !== null ? mb_substr($clean($e['teil']), 0, 120) : null,
        'beschreibung_de' => $clean($e['beschreibung_de'] ?? '') ?: $clean($e['beschreibung'] ?? ''),
    ];
    $ex = $code ? one("SELECT id FROM novelfood_katalog WHERE code=?", [$code]) : one("SELECT id FROM novelfood_katalog WHERE code IS NULL AND name=?", [$daten['name']]);
    if ($ex) {
        q("UPDATE novelfood_katalog SET name=?,trivial=?,syn=?,status=?,status_code=?,teil=?,beschreibung_de=? WHERE id=?",
          [$daten['name'], $daten['trivial'], $daten['syn'], $daten['status'], $daten['status_code'], $daten['teil'], $daten['beschreibung_de'], (int)$ex['id']]);
        $upd++;
    } else {
        q("INSERT INTO novelfood_katalog (code,name,trivial,syn,status,status_code,teil,beschreibung_de) VALUES (?,?,?,?,?,?,?,?)",
          [$daten['code'], $daten['name'], $daten['trivial'], $daten['syn'], $daten['status'], $daten['status_code'], $daten['teil'], $daten['beschreibung_de']]);
        $neu++;
    }
}
echo "Novel-Food-Katalog: $neu neu, $upd aktualisiert (Stand: " . ($roh['stand'] ?? '?') . ").\n";
$g = (int) scalar("SELECT COUNT(*) FROM novelfood_katalog");
echo "Gesamt in der DB: $g\n";
