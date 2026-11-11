<?php
// Inventur – Startseite (11.11.2026): laufende und abgeschlossene Inventuren. Nur Admin und Lager (Route-Gate in index.php).
$liste = inv_liste();
$admin = lg_ist_admin();
kopf('Inventur', 'inventur');
seitenkopf('Inventur', 'Eigener Modus, getrennt vom Tagesgeschäft: blind zählen, danach Soll und Ist vergleichen, Nico entscheidet jede Korrektur.',
    ($admin ? '<a class="btn btn-ghost" href="?p=plaetze">Lagerplätze</a> <a class="btn btn-ghost" href="?p=tara">Gebindetypen</a> <a class="btn btn-primary" href="?p=inventur_neu">Neue Inventur</a>' : '<a class="btn btn-ghost" href="?p=plaetze">Lagerplätze</a>'));
flash_zeigen();
if (function_exists('inv_anleitung_html')) echo inv_anleitung_html((bool)array_filter($liste, fn($i) => $i['status'] === 'abgeschlossen'));   // zugeklappt, sobald es eine abgeschlossene Inventur gibt
$offen = array_filter($liste, fn($i) => $i['status'] !== 'abgeschlossen');
$zu    = array_filter($liste, fn($i) => $i['status'] === 'abgeschlossen');
$tab = function (array $rows, string $titel, string $leer) {
    echo '<div class="bx-panel"><h2>' . h($titel) . '</h2>';
    if (!$rows) { echo '<p class="muted">' . h($leer) . '</p></div>'; return; }
    echo '<div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Inventur</th><th>Stichtag</th><th>Umfang</th><th>Status</th><th>Fortschritt</th></tr></thead><tbody>';
    foreach ($rows as $i) {
        $f = inv_fortschritt($i);
        echo '<tr><td><a class="lg-namelink" href="?p=inventur_lauf&id=' . (int)$i['id'] . '">' . h((string)$i['name']) . '</a></td>'
           . '<td>' . h(date('d.m.Y', strtotime((string)$i['stichtag']))) . '</td>'
           . '<td style="white-space:normal">' . h(inv_umfang_text($i)) . '</td>'
           . '<td>' . h(inv_status_label((string)$i['status'])) . '</td>'
           . '<td>Plätze ' . $f['fertig'] . ' von ' . $f['gesamt'] . ' · ' . $f['gescannt'] . ' gescannt'
           . ($f['klaerfaelle'] ? ' · ' . $f['klaerfaelle'] . ' Klärfälle' : '') . '</td></tr>';
    }
    echo '</tbody></table></div></div>';
};
$tab(array_values($offen), 'Laufende Inventuren', $admin ? 'Keine laufende Inventur. „Neue Inventur" legt eine an.' : 'Keine laufende Inventur.');
$tab(array_values($zu), 'Abgeschlossen', 'Noch keine abgeschlossene Inventur.');
fuss();
