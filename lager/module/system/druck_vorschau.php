<?php
// Druckvorschau – nur in der Testumgebung (Paket 12.11.2026). Dort druckt die Brücke nie; jeder Druckauftrag
// (Lager-App, Werk-App, Dashboard über api_druck) bleibt in lg_druckjob und lässt sich hier als PDF ansehen.
//   ?p=druck_vorschau        Liste der letzten 50 Druckaufträge
//   ?p=druck_vorschau&id=N   PDF dieses Auftrags (inline)
require_once __DIR__ . '/../../core/druckjob.php';
require_once __DIR__ . '/../../core/etikett_pdf.php';
require_once __DIR__ . '/../../core/lieferschein_pdf.php';
require_once __DIR__ . '/../../core/erp.php';
foreach (['inventur_etikett.php'] as $__f) if (is_file(__DIR__ . '/../../core/' . $__f)) require_once __DIR__ . '/../../core/' . $__f;

if (!function_exists('bx_testumgebung') || !bx_testumgebung()) {
    kopf('Druckvorschau'); seitenkopf('Druckvorschau', 'Testumgebung');
    echo '<div class="karte"><p>Die Druckvorschau gibt es nur in der Testumgebung. Hier wird über die Brücke gedruckt.</p></div>';
    fuss();
    return;
}

$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
    $j = one("SELECT id, ids, format, typ FROM lg_druckjob WHERE id=?", [$id]);
    $r = $j ? lg_druckjob_pdf($j) : ['pdf' => null, 'fehlt' => 'Druckauftrag nicht gefunden'];
    if ($r['pdf'] === null || $r['pdf'] === '') {
        kopf('Druckvorschau'); seitenkopf('Druckvorschau', 'Testumgebung');
        echo '<div class="karte"><p>Keine Vorschau: ' . h((string)$r['fehlt']) . '</p><p><a href="?p=druck_vorschau">Zur Liste</a></p></div>';
        fuss();
        return;
    }
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="druckauftrag-' . $id . '.pdf"');
    echo $r['pdf'];
    exit;
}

kopf('Druckvorschau'); seitenkopf('Druckvorschau', 'Testumgebung');
echo '<div class="karte"><p>Testumgebung: Druckaufträge werden nicht an die Drucker in Barßel geschickt. Hier stehen sie als PDF.</p>';
$jobs = all("SELECT id, typ, format, ids, angelegt FROM lg_druckjob ORDER BY id DESC LIMIT 50");
if (!$jobs) echo '<p>Noch keine Druckaufträge.</p>';
else {
    echo '<table class="tabelle"><tr><th>Nr.</th><th>Art</th><th>Format</th><th>Bezug</th><th>angelegt</th><th></th></tr>';
    foreach ($jobs as $j)
        echo '<tr><td>' . (int)$j['id'] . '</td><td>' . h((string)$j['typ']) . '</td><td>' . h((string)$j['format']) . '</td><td>' . h((string)$j['ids'])
           . '</td><td>' . h(function_exists('fmt_zeit') ? fmt_zeit((string)$j['angelegt']) : (string)$j['angelegt']) . '</td><td><a href="?p=druck_vorschau&id=' . (int)$j['id'] . '" target="_blank">PDF ansehen</a></td></tr>';
    echo '</table>';
}
echo '</div>';
fuss();
