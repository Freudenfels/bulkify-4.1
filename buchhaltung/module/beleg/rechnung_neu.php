<?php
// Rechnung aus einem Auftrag – VORSCHAU + Eingaben (Rechnungsdatum, Leistungsdatum, Zahlungsziel,
// USt, Text) VOR dem Erstellen. Erst „Rechnung erstellen" legt den Beleg an (rechnung_aus_auftrag()).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$aid = (int)($_GET['auftrag'] ?? $_POST['auftrag'] ?? 0);
$a = $aid ? one("SELECT a.*, k.firma AS kunde_firma, k.land AS kunde_land, k.zahlungsziel_tage AS kunde_ziel,
                        COALESCE(NULLIF(p.kundenname,''), p.name, a.produkt_bezeichnung) AS produkt_name
                 FROM auftrag a LEFT JOIN kunden k ON k.id=a.kunde_id LEFT JOIN produkt p ON p.id=a.produkt_id
                 WHERE a.id=?", [$aid]) : null;

if (!$a) { render_header('rechnungen','Rechnung erstellen'); bx_head('Auftrag nicht gefunden','', bx_btn('Zurück','?p=auftraege','ghost')); render_footer(); exit; }

// Schon eine (nicht stornierte) Rechnung? Dann direkt dorthin.
$vorhanden = (int) scalar("SELECT id FROM beleg WHERE auftrag_id=? AND typ='rechnung' AND status<>'storniert' ORDER BY id LIMIT 1", [$aid]);
if ($vorhanden) { header('Location: ?p=rechnung&id=' . $vorhanden); exit; }

// Netto aus dem Auftrag.
$menge = (int)($a['menge'] ?? 0);
$vk    = (float)($a['vk_stueck'] ?? 0);
$netto = round((float)($a['gesamt_netto'] ?? 0), 2);
if ($netto <= 0) $netto = round($menge * $vk, 2);

// Erstellen (POST).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'erstellen') {
    if ($netto <= 0) { header('Location: ?p=rechnung_neu&auftrag=' . $aid . '&kinpreis=1'); exit; }
    $akteur = (function_exists('current_user') && ($u = current_user())) ? (string)($u['name'] ?? 'team') : 'team';
    $bid = rechnung_aus_auftrag($aid, [
        'datum'            => trim((string)($_POST['datum'] ?? '')),
        'leistung_datum'   => trim((string)($_POST['leistung_datum'] ?? '')),
        'zahlungsziel_tage'=> ($_POST['zahlungsziel_tage'] ?? '') !== '' ? (int)$_POST['zahlungsziel_tage'] : '',
        'ust_prozent'      => ($_POST['ust_prozent'] ?? '') !== '' ? (float) str_replace(',', '.', (string)$_POST['ust_prozent']) : '',
        'text'             => (string)($_POST['text'] ?? ''),
        'freigeben'        => !empty($_POST['freigeben']),
        'ersteller'        => $akteur,
    ]);
    if ($bid) { header('Location: ?p=rechnung&id=' . $bid . '&erstellt=1'); exit; }
    header('Location: ?p=rechnung_neu&auftrag=' . $aid . '&kinpreis=1'); exit;
}

// USt-Vorgabe (automatisch): Kleinunternehmer/EU-Ausland 0 %, sonst Inland.
$land = (string)($a['kunde_land'] ?? 'DE') ?: 'DE';
$ustInland = (float) meta_get('ust_inland', 19);
$ustStd = (meta_get('kleinunternehmer', '0') === '1' || $land !== 'DE') ? 0.0 : $ustInland;
// Zahlungsziel-Vorgabe: Kunde, sonst 14 Tage.
$zielStd = (int)($a['kunde_ziel'] ?? 0); if ($zielStd <= 0) $zielStd = 14;
$heute = date('Y-m-d');

$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
render_header('rechnungen', 'Rechnung erstellen');
bx_head('Rechnung erstellen', 'aus Auftrag ' . h($a['nummer']) . ($a['kunde_firma'] ? ' · ' . h($a['kunde_firma']) : ''),
        bx_btn('Zurück zum Auftrag', '?p=auftrag&id=' . $aid, 'ghost'));
if (isset($_GET['kinpreis'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Am Auftrag ist kein Preis hinterlegt – es kann keine Rechnung erstellt werden.</div>';

if ($netto <= 0) {
    echo '<div class="bx-panel"><p class="muted" style="margin:0">Dieser Auftrag hat keinen Preis (Netto bzw. Menge × Stückpreis = 0). Bitte zuerst den Preis am Auftrag/Angebot pflegen.</p></div>';
    render_footer(); exit;
}
?>
<form method="post">
<input type="hidden" name="aktion" value="erstellen">
<input type="hidden" name="auftrag" value="<?= $aid ?>">
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;align-items:start">

  <!-- Eingaben -->
  <div class="bx-panel" style="margin:0">
    <h2 style="margin-top:0">Angaben</h2>
    <div class="bx-grid">
      <div class="bx-field"><label>Rechnungsdatum</label><input type="date" name="datum" id="f_datum" value="<?= h($heute) ?>"></div>
      <div class="bx-field"><label>Leistungsdatum <?= bx_hint('Liefer-/Leistungsdatum; leer = keines') ?></label><input type="date" name="leistung_datum" id="f_leist" value="<?= h($heute) ?>"></div>
      <div class="bx-field"><label>Zahlungsziel (Tage)</label><input type="number" name="zahlungsziel_tage" id="f_ziel" min="0" step="1" value="<?= (int)$zielStd ?>"></div>
      <div class="bx-field"><label>USt-Satz (%)</label><input type="text" inputmode="decimal" name="ust_prozent" id="f_ust" value="<?= rtrim(rtrim(number_format($ustStd,2,',',''),'0'),',') ?>">
        <div class="muted" style="font-size:12px;margin-top:4px"><?= $ustStd == 0.0 ? 'Standard 0 % (Kleinunternehmer/EU-Ausland)' : 'Standard ' . rtrim(rtrim(number_format($ustStd,2,',','.'),'0'),',') . ' %' ?></div></div>
    </div>
    <div class="bx-field"><label>Rechnungstext / Hinweis (optional)</label><textarea name="text" id="f_text" rows="2" placeholder="z. B. Zahlbar ohne Abzug innerhalb des Zahlungsziels. Vielen Dank."></textarea></div>
    <div class="bx-check" style="margin-top:4px"><input type="checkbox" name="freigeben" id="f_frei" value="1"><label for="f_frei" style="margin:0">Dem Kunden direkt freigeben (sofort im Portal sichtbar)</label></div>
    <div class="muted" style="font-size:12px;margin-top:4px">Ohne Haken wird die Rechnung erstellt, ist aber für den Kunden noch nicht sichtbar – du kannst sie später auf der Rechnung freigeben und auch wieder zurückziehen.</div>
  </div>

  <!-- Vorschau -->
  <div class="bx-panel" style="margin:0">
    <h2 style="margin-top:0">Vorschau</h2>
    <table class="bx-table" style="margin-bottom:12px"><tbody>
      <tr><td class="muted" style="width:150px">Kunde</td><td><?= h($a['kunde_firma'] ?: '–') ?></td></tr>
      <tr><td class="muted">Auftrag</td><td><?= h($a['nummer']) ?></td></tr>
      <tr><td class="muted">Rechnungsdatum</td><td id="v_datum"><?= h(date('d.m.Y', strtotime($heute))) ?></td></tr>
      <tr><td class="muted">Fällig bis</td><td id="v_faellig">–</td></tr>
    </tbody></table>

    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Bezeichnung</th><th class="bx-num">Menge</th><th class="bx-num">Einzel</th><th class="bx-num">Gesamt</th></tr></thead>
      <tbody>
        <tr>
          <td><?= h($a['produkt_name'] ?: ('Leistung laut Auftrag ' . $a['nummer'])) ?></td>
          <td class="bx-num"><?= $menge > 0 ? number_format($menge, 0, ',', '.') : '1' ?></td>
          <td class="bx-num"><?= $menge > 0 ? $eur($vk) : $eur($netto) ?></td>
          <td class="bx-num"><?= $eur($netto) ?></td>
        </tr>
      </tbody>
    </table></div>

    <table class="bx-table" style="margin-top:12px"><tbody>
      <tr><td class="muted" style="width:150px">Netto</td><td class="bx-num" data-netto="<?= h(number_format($netto,2,'.','')) ?>"><?= $eur($netto) ?></td></tr>
      <tr><td class="muted">USt (<span id="v_ustp">–</span> %)</td><td class="bx-num" id="v_ust">–</td></tr>
      <tr><td class="muted"><strong>Brutto</strong></td><td class="bx-num" id="v_brutto"><strong>–</strong></td></tr>
    </tbody></table>
    <p class="muted" style="font-size:12px;margin:10px 0 0">Beträge kommen aus dem Auftrag. Nach dem Erstellen kannst du auf der Rechnung Zahlungen erfassen oder stornieren.</p>
  </div>
</div>

<div class="bx-row" style="gap:10px;margin-top:16px">
  <button class="btn btn-primary" type="submit">Rechnung jetzt erstellen</button>
  <a class="btn btn-ghost" href="?p=auftrag&id=<?= $aid ?>">Abbrechen</a>
</div>
</form>

<script>
(function(){
  var netto = parseFloat(document.querySelector('[data-netto]').getAttribute('data-netto')) || 0;
  var fUst = document.getElementById('f_ust'), fZiel = document.getElementById('f_ziel'), fDatum = document.getElementById('f_datum');
  var vUstp = document.getElementById('v_ustp'), vUst = document.getElementById('v_ust'), vBrutto = document.getElementById('v_brutto');
  var vDatum = document.getElementById('v_datum'), vFaellig = document.getElementById('v_faellig');
  function eur(x){ return x.toLocaleString('de-DE',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' €'; }
  function deDate(d){ if(!d) return '–'; var p=d.split('-'); return p.length===3 ? p[2]+'.'+p[1]+'.'+p[0] : '–'; }
  function recalc(){
    var up = parseFloat((fUst.value||'0').replace(',','.')) || 0;
    var ustR = Math.round(netto*up/100*100)/100;   // USt, auf Cent gerundet
    vUstp.textContent = (up % 1 === 0) ? up.toString() : up.toString().replace('.',',');
    vUst.textContent = eur(ustR);
    vBrutto.innerHTML = '<strong>' + eur(netto + ustR) + '</strong>';
    vDatum.textContent = deDate(fDatum.value);
    var tage = parseInt(fZiel.value||'',10);
    if(fDatum.value && !isNaN(tage)){ var d=new Date(fDatum.value+'T00:00:00'); d.setDate(d.getDate()+tage);
      vFaellig.textContent = deDate(d.toISOString().slice(0,10)) + ' (' + tage + ' Tage)'; }
    else vFaellig.textContent = '–';
  }
  [fUst,fZiel,fDatum].forEach(function(el){ el.addEventListener('input', recalc); el.addEventListener('change', recalc); });
  recalc();
})();
</script>
<?php render_footer();
