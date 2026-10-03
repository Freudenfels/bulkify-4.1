<?php
// Dashboard / Startseite des Produktions-Programms. Zeigt auf einen Blick, was offen, in Planung
// und in Produktion ist, plus Kennzahlen (u. a. Ø Produktionszeit). Nur lesend über die Naht.
$offenAlle = erp_produktionsauftraege('offen');     // status offen
$laufend   = erp_produktionsauftraege('laufend');   // status laufend
// Offene aufteilen: ohne Termin = „zu planen", mit geplant_am = „in Planung".
$zuPlanen = array_values(array_filter($offenAlle, fn($p) => empty($p['geplant_am'])));
$inPlanung = array_values(array_filter($offenAlle, fn($p) => !empty($p['geplant_am'])));

$erledigtN = erp_pa_count('erledigt');
$prodzeit  = erp_produktionszeit_schnitt();
$durchlauf = erp_durchlaufzeit_schnitt();

kopf('Dashboard', 'dash');
seitenkopf('Dashboard', 'Produktion auf einen Blick');

// KPI-Kachel
$kpi = function (string $label, string $wert, string $sub = '') {
    echo '<div class="bx-card" style="margin:0">'
       . '<div class="k muted">' . h($label) . '</div>'
       . '<div style="font-size:26px;line-height:1.2;margin-top:4px">' . $wert . '</div>'
       . ($sub !== '' ? '<div class="muted" style="font-size:12px;margin-top:2px">' . h($sub) . '</div>' : '')
       . '</div>';
};
?>
<div class="bx-cards" style="margin-bottom:20px">
  <?php
  $kpi('Offen (zu planen)', (string)count($zuPlanen));
  $kpi('In Planung', (string)count($inPlanung));
  $kpi('Laufend', (string)count($laufend));
  $kpi('Abgeschlossen', (string)$erledigtN);
  $kpi('Ø Produktionszeit', h(dauer_txt($prodzeit['sekunden'])), $prodzeit['n'] > 0 ? 'aus ' . $prodzeit['n'] . ' Aufträgen' : 'noch keine Messwerte');
  $kpi('Ø Durchlaufzeit', h(dauer_txt($durchlauf['sekunden'])), 'Auftragseingang → fertig');
  ?>
</div>

<?php
// Wiederverwendbare Liste (kurz) mit „alle anzeigen"-Link.
$liste = function (string $titel, array $rows, string $alleTab, string $art) {
    echo '<div class="bx-panel" style="margin-bottom:16px">';
    echo '<div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">';
    echo '<h2 style="margin:0">' . h($titel) . ' <span class="muted" style="font-size:14px">' . count($rows) . '</span></h2>';
    if ($rows) echo '<a class="btn btn-ghost btn-sm" href="?p=liste&tab=' . h($alleTab) . '">alle anzeigen</a>';
    echo '</div>';
    if (!$rows) { echo '<div class="muted" style="margin-top:10px">Nichts.</div></div>'; return; }
    echo '<div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table"><thead><tr>'
       . '<th>Nr.</th><th>Produkt</th><th>Kunde</th>'
       . ($art === 'planung' ? '<th>Geplant am</th>' : '')
       . ($art === 'laufend' ? '<th>Fortschritt</th>' : '<th>Produzierbar?</th>')
       . '<th></th></tr></thead><tbody>';
    foreach (array_slice($rows, 0, 8) as $pa) {
        $g = (int)$pa['schritte_gesamt']; $f = (int)$pa['schritte_fertig'];
        echo '<tr>';
        echo '<td><strong>' . h((string)$pa['nummer']) . '</strong></td>';
        echo '<td>' . h((string)($pa['produkt_name'] ?: '–')) . '</td>';
        echo '<td>' . h((string)($pa['kunde'] ?: '–')) . '</td>';
        if ($art === 'planung') {
            echo '<td>' . ($pa['geplant_am'] ? h(date('d.m.Y', strtotime((string)$pa['geplant_am']))) : '–') . '</td>';
        }
        if ($art === 'laufend') {
            echo '<td>' . ($g > 0 ? 'Schritt ' . $f . '/' . $g : '–') . '</td>';
        } else {
            $ber = erp_pa_bereitschaft((int)$pa['id'], (string)$pa['status'], $f);
            echo '<td>' . bereit_badge($ber['status']) . '</td>';
        }
        $ziel = $art === 'laufend' ? 'run' : 'pa';
        echo '<td class="bx-num"><a class="btn btn-ghost btn-sm" href="?p=' . $ziel . '&id=' . (int)$pa['id'] . '">öffnen</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div></div>';
};

$liste('Laufende Produktionen', $laufend, 'laufend', 'laufend');
$liste('In Planung', $inPlanung, 'alle', 'planung');
$liste('Offen · zu planen', $zuPlanen, 'alle', 'offen');
?>
<?php fuss();
