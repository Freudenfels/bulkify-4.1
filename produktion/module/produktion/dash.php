<?php
// Dashboard / Startseite des Produktions-Programms. Zeigt auf einen Blick, was offen, in Planung
// und in Produktion ist, plus Kennzahlen (u. a. Ø Produktionszeit). Nur lesend über die Naht.
// Zusätzlich (nur Admin): Blinker-Test – eine Chargennummer direkt im Lager leuchten lassen,
// um die Pick-to-Light-Kette end-to-end zu prüfen.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['aktion'] ?? '') === 'blinktest' && pr_ist_admin()) {
    $eingabe = trim((string)($_POST['charge_nr'] ?? ''));
    $aktion  = ($_POST['aus'] ?? '') === '1' ? 'aus' : 'an';
    // Blinker-Code (z. B. C2EC08 oder Barcode C2EC08XD) direkt? Sonst als Chargennummer behandeln.
    $norm = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $eingabe));
    if (strlen($norm) === 8 && str_ends_with($norm, 'XD')) $norm = substr($norm, 0, 6);
    $istCode = (bool) preg_match('/^[0-9A-F]{6}$/', $norm);
    if ($eingabe === '') {
        flash('Bitte eine Chargennummer oder einen Blinker-Code eingeben.', 'warn');
    } elseif ($istCode) {
        $r = pr_lager_blink_leiste($norm, $aktion);
        flash(($r['ok'] ? 'Blinker-Test ok: ' : 'Blinker-Test: ') . ($r['meldung'] ?: ($r['ok'] ? 'ausgelöst.' : 'nicht ausgelöst.')), $r['ok'] ? 'ok' : 'warn');
    } elseif (($cid = erp_charge_id_per_nr($eingabe))) {
        $r = pr_lager_blink($cid, $aktion);
        flash(($r['ok'] ? 'Blinker-Test ok: ' : 'Blinker-Test: ') . ($r['meldung'] ?: ($r['ok'] ? 'ausgelöst.' : 'nicht ausgelöst.')), $r['ok'] ? 'ok' : 'warn');
    } else {
        flash('„' . $eingabe . '" ist weder eine bekannte Charge noch ein Blinker-Code.', 'warn');
    }
    weiter('?p=dash');
}

$offenAlle = erp_produktionsauftraege('offen');     // status offen
$laufend   = erp_produktionsauftraege('laufend');   // status laufend
// Offene aufteilen: ohne Termin = „zu planen", mit geplant_am = „in Planung".
$zuPlanen = array_values(array_filter($offenAlle, fn($p) => empty($p['geplant_am'])));
$inPlanung = array_values(array_filter($offenAlle, fn($p) => !empty($p['geplant_am'])));

$vorbereitung = erp_produktionsauftraege('vorbereitung');   // warten auf Admin-Freigabe
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
  $kpi('In Vorbereitung', (string)count($vorbereitung), 'warten auf Freigabe');
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
       . '</tr></thead><tbody>';
    foreach (array_slice($rows, 0, 8) as $pa) {
        $g = (int)$pa['schritte_gesamt']; $f = (int)$pa['schritte_fertig'];
        $ziel = $art === 'laufend' ? 'run' : 'pa';
        echo '<tr onclick="location.href=\'?p=' . $ziel . '&id=' . (int)$pa['id'] . '\'" style="cursor:pointer">';
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
        echo '</tr>';
    }
    echo '</tbody></table></div></div>';
};

$liste('In Vorbereitung · warten auf Freigabe', $vorbereitung, 'alle', 'offen');
$liste('Laufende Produktionen', $laufend, 'laufend', 'laufend');
$liste('In Planung', $inPlanung, 'alle', 'planung');
$liste('Offen · zu planen', $zuPlanen, 'alle', 'offen');

if (pr_ist_admin()): ?>
<div class="bx-panel" style="margin-bottom:16px">
  <h2 style="margin-top:0">Blinker-Test (Lager)</h2>
  <p class="muted" style="margin-top:0">Prüft die Pick-to-Light-Kette: Chargennummer <strong>oder</strong> Blinker-Code eingeben (Barcode mit „XD" geht auch) – der Blinker im Lager leuchtet kurz grün. Nur zum Testen.</p>
  <form method="post" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="aktion" value="blinktest">
    <div class="bx-field" style="margin:0;max-width:300px"><label>Chargennummer oder Blinker-Code</label>
      <input type="text" name="charge_nr" required placeholder="z. B. MBG-2609A oder C2EC08XD"></div>
    <button type="submit" class="btn btn-primary">Blinken</button>
    <button type="submit" name="aus" value="1" class="btn btn-ghost">Aus</button>
  </form>
</div>
<?php endif; ?>
<?php fuss();
