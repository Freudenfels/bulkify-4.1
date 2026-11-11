<?php
// JSON für das Zählen (11.11.2026). Blind: keine Antwort enthält eine Soll-Menge.
// a=scan (code, bereich) · a=start (key) · a=zaehlen (bereich, obj, id, art, menge|brutto,tara,kapsel_mg) · a=klaerfall (multipart mit foto)
// a=scans (bereich) · a=fertig (bereich) · a=artikel · a=nachzaehlen · a=kf_etikett (PDF zum Anzeigen, falls die Brücke nicht läuft)
$inv = inv_get((int)($_REQUEST['id'] ?? 0));
$a = (string)($_REQUEST['a'] ?? '');
if (!$inv || !inv_darf_zaehlen($inv)) json_antwort(['ok' => false, 'msg' => 'Diese Inventur ist nicht (mehr) zum Zählen offen.'], 403);

if ($a === 'kf_etikett') {
    $z = one("SELECT id FROM lg_inventur_zaehlung WHERE id=? AND inventur_id=? AND obj='k'", [(int)($_GET['z'] ?? 0), (int)$inv['id']]);
    require_once __DIR__ . '/../../core/inventur_etikett.php';
    $pdf = $z ? inv_klaerfall_etikett_pdf((int)$z['id']) : null;
    if (!$pdf) { http_response_code(404); exit('Etikett nicht gefunden.'); }
    header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="inventur-etikett.pdf"'); echo $pdf; exit;
}
if ($a === 'artikel') json_antwort(['ok' => true, 'artikel' => erp_inv_artikel(), 'tara' => array_map(fn($t) => ['id' => (int)$t['id'], 'name' => (string)$t['name'], 'kg' => (float)$t['tara_kg']], inv_tara_liste())]);
if ($a === 'nachzaehlen') json_antwort(['ok' => true, 'liste' => inv_nachzaehl_aufgaben($inv)]);
if ($a === 'start') json_antwort(inv_bereich_starten($inv, (string)($_POST['key'] ?? '')));
if ($a === 'scans') json_antwort(['ok' => true, 'scans' => inv_bereich_scans((int)($_REQUEST['bereich'] ?? 0))]);

if ((string)$inv['status'] !== 'zaehlen' && !in_array($a, ['zaehlen', 'scan'], true)) json_antwort(['ok' => false, 'msg' => 'Die Zählung ist beendet.']);
if ($a === 'scan') {
    $r = inv_scan($inv, (string)($_POST['code'] ?? ''));
    if (in_array($r['typ'], ['platz', 'kiste'], true)) $r += ['start' => inv_bereich_starten($inv, $r['key'])];
    json_antwort(['ok' => true] + $r);
}
if ($a === 'zaehlen') json_antwort(inv_zaehlen($inv, (int)($_POST['bereich'] ?? 0), (string)($_POST['obj'] ?? '') === 'g' ? 'g' : 'c', (int)($_POST['oid'] ?? 0), $_POST));
if ($a === 'klaerfall') json_antwort(inv_klaerfall($inv, (int)($_POST['bereich'] ?? 0), $_POST, $_FILES['foto'] ?? []));
if ($a === 'fertig') json_antwort(inv_bereich_fertig($inv, (int)($_POST['bereich'] ?? 0)));
json_antwort(['ok' => false, 'msg' => 'Unbekannte Aktion.'], 400);
