<?php
// Einkauf → Preise: ALLE Einkaufspreise an einem Ort, schlanker Tabellen-Stil + Klick-Popup.
// Reiter: Fremdfertigung (Kapsel/Rezeptur) · Rohstoff · Verpackung · Fertigprodukt/Zukauf.
// Löst die früheren Einzelseiten rezept_preise, lieferant_preise und lief_preisliste ab (die leiten hierher).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$TABS = ['fremd' => 'Fremdfertigung (Kapsel)', 'rohstoff' => 'Rohstoff', 'verpackung' => 'Verpackung', 'zukauf' => 'Fertigprodukt/Zukauf'];
$tab = $_GET['tab'] ?? 'fremd';
if (!isset($TABS[$tab])) $tab = 'fremd';
$q   = trim((string)($_GET['q'] ?? ''));

$dfLabel = ['kapsel'=>'Kapsel','tablette'=>'Tablette','pulver'=>'Pulver','granulat'=>'Granulat','fluessig'=>'Flüssig','softgel'=>'Softgel'];
$preisFmt = fn($p, $dec = 4) => rtrim(rtrim(number_format((float)$p, $dec, ',', '.'), '0'), ',');

// ---------- Daten je Reiter ----------
$rows = []; $extraRows = [];
if ($tab === 'fremd') {
    // Fremdfertigung je Rezeptur: v3-Übernahme (rezeptur_lief_angebot) + aktuelle v4-Angebote (inkl. Staffeln).
    $where = []; $args = [];
    if ($q !== '') { $where[] = "(r.name LIKE ? OR r.synonyme LIKE ? OR l.firma LIKE ?)"; $args[] = '%'.$q.'%'; $args[] = '%'.$q.'%'; $args[] = '%'.$q.'%'; }
    $wsql = $where ? 'WHERE '.implode(' AND ', $where) : '';
    $rows = all("SELECT la.rezeptur_id, r.nummer AS rez_nr, r.name AS rez_name, r.darreichungsform AS df,
                        kg.name AS kapselgroesse, l.firma, la.preis, la.einheit, la.menge
                 FROM rezeptur_lief_angebot la
                 LEFT JOIN rezeptur r   ON r.id = la.rezeptur_id
                 LEFT JOIN kapselgroesse kg ON kg.id = r.kapselgroesse_id
                 LEFT JOIN lieferanten l ON l.id = la.lieferant_id
                 $wsql ORDER BY r.name IS NULL, r.name, la.menge LIMIT 2000", $args);
    $neuRows = all("SELECT la.rezeptur_id, r.nummer AS rez_nr, r.name AS rez_name, r.darreichungsform AS df, kg.name AS kapselgroesse,
                           l.firma, ag.id AS ag_id, ag.preis, ag.einheit, la.menge
                    FROM lieferant_anfrage la
                    JOIN lieferant_angebot ag ON ag.anfrage_id = la.id
                    LEFT JOIN rezeptur r    ON r.id = la.rezeptur_id
                    LEFT JOIN kapselgroesse kg ON kg.id = r.kapselgroesse_id
                    LEFT JOIN lieferanten l ON l.id = la.lieferant_id
                    WHERE la.rezeptur_id IS NOT NULL AND la.art='fertigprodukt'");
    $staffeln = [];
    if ($neuRows) {
        $agIds = array_values(array_unique(array_map(fn($x) => (int)$x['ag_id'], $neuRows)));
        $in = implode(',', array_fill(0, count($agIds), '?'));
        foreach (all("SELECT angebot_id, menge_ab, preis FROM lieferant_angebot_staffel WHERE angebot_id IN ($in) ORDER BY menge_ab", $agIds) as $s)
            $staffeln[(int)$s['angebot_id']][] = $s;
    }
    foreach ($neuRows as $nr) {
        $mk = fn($preis, $menge) => ['rezeptur_id'=>$nr['rezeptur_id'],'rez_nr'=>$nr['rez_nr'],'rez_name'=>$nr['rez_name'],
            'df'=>$nr['df'],'kapselgroesse'=>$nr['kapselgroesse'],'firma'=>$nr['firma'],'preis'=>$preis,'einheit'=>$nr['einheit'],'menge'=>$menge];
        $kand = !empty($staffeln[(int)$nr['ag_id']]) ? array_map(fn($s) => $mk($s['preis'], $s['menge_ab']), $staffeln[(int)$nr['ag_id']]) : [$mk($nr['preis'], $nr['menge'])];
        foreach ($kand as $row) {
            if ($q !== '' && mb_stripos((string)$row['rez_name'], $q) === false && mb_stripos((string)$row['firma'], $q) === false) continue;
            $rows[] = $row;
        }
    }
    usort($rows, fn($a,$b) => [(string)($a['rez_name']??''),(float)($a['menge']??0),(float)($a['preis']??0)] <=> [(string)($b['rez_name']??''),(float)($b['menge']??0),(float)($b['preis']??0)]);
} elseif ($tab === 'rohstoff') {
    $rows = all("SELECT lp.*, i.name AS artikel, i.artikelnummer AS artikelnr, i.einheit AS art_einheit, COALESCE(l.firma, lp.lieferant_name, '') AS lieferant
                 FROM lieferant_preis lp LEFT JOIN item i ON i.id=lp.item_id LEFT JOIN lieferanten l ON l.id=lp.lieferant_id
                 ORDER BY artikel, lp.menge_ab");
    if ($q !== '') $rows = array_values(array_filter($rows, fn($r) => mb_stripos((string)$r['artikel'], $q) !== false || mb_stripos((string)$r['lieferant'], $q) !== false));
    // EK-Preisliste (flache v3-Referenz) eingeblendet.
    $extraRows = all("SELECT rohstoff_name, COALESCE(l.firma, pl.lieferant, '') AS lieferant, pl.eur_kg, pl.einheit, pl.stand
                      FROM lieferant_preisliste pl LEFT JOIN lieferanten l ON l.id=pl.lieferant_id ORDER BY rohstoff_name LIMIT 2000");
    if ($q !== '') $extraRows = array_values(array_filter($extraRows, fn($r) => mb_stripos((string)$r['rohstoff_name'], $q) !== false || mb_stripos((string)$r['lieferant'], $q) !== false));
} elseif ($tab === 'verpackung') {
    $rows = all("SELECT ps.item_id, i.name AS artikel, i.verpackungsart, ps.menge_ab, ps.ek_preis, COALESCE(l.firma,'') AS lieferant
                 FROM pack_ek_staffel ps JOIN item i ON i.id=ps.item_id LEFT JOIN lieferanten l ON l.id=ps.lieferant_id
                 ORDER BY artikel, ps.menge_ab");
    if ($q !== '') $rows = array_values(array_filter($rows, fn($r) => mb_stripos((string)$r['artikel'], $q) !== false || mb_stripos((string)$r['lieferant'], $q) !== false));
} else { // zukauf
    $rows = all("SELECT plp.*, p.name AS artikel, plp.einheit AS art_einheit, plp.groesse, COALESCE(l.firma, plp.lieferant_name, '') AS lieferant
                 FROM produkt_lieferant_preis plp LEFT JOIN produkt p ON p.id=plp.produkt_id LEFT JOIN lieferanten l ON l.id=plp.lieferant_id
                 ORDER BY artikel, plp.menge_ab");
    if ($q !== '') $rows = array_values(array_filter($rows, fn($r) => mb_stripos((string)$r['artikel'], $q) !== false || mb_stripos((string)$r['lieferant'], $q) !== false));
}

// Reiter-Zähler (ohne Suche).
$cnt = [
    'fremd'      => (int) scalar("SELECT COUNT(*) FROM rezeptur_lief_angebot"),
    'rohstoff'   => (int) scalar("SELECT COUNT(*) FROM lieferant_preis"),
    'verpackung' => (int) scalar("SELECT COUNT(*) FROM pack_ek_staffel"),
    'zukauf'     => (int) scalar("SELECT COUNT(*) FROM produkt_lieferant_preis"),
];

render_header('einkauf_preise', 'Preise');
bx_head('Einkaufspreise', 'Alle Einkaufspreise an einem Ort – Fremdfertigung, Rohstoff, Verpackung und Zukauf.');
?>
<div class="settabs">
  <?php foreach ($TABS as $k => $lbl): ?>
    <a href="?p=einkauf_preise&tab=<?= $k ?>" class="<?= $tab===$k?'on':'' ?>"><?= h($lbl) ?><?= !empty($cnt[$k]) ? ' (' . $cnt[$k] . ')' : '' ?></a>
  <?php endforeach; ?>
</div>
<form method="get" class="bx-row" style="gap:8px;margin:12px 0 14px;align-items:center;flex-wrap:wrap">
  <input type="hidden" name="p" value="einkauf_preise"><input type="hidden" name="tab" value="<?= h($tab) ?>">
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="Suchen: Artikel/Rezeptur oder Lieferant …" style="max-width:360px">
  <button class="btn btn-primary" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost" href="?p=einkauf_preise&tab=<?= h($tab) ?>">Zurücksetzen</a><?php endif; ?>
</form>

<div class="bx-panel" style="padding:0;overflow:hidden">
<?php if ($tab === 'fremd'): ?>
  <?php if (!$rows): ?><div style="padding:16px" class="muted">Keine Fremdfertigungs-Preise.</div><?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Rezepturnr.</th><th>Rezeptur-Name</th><th>Form</th><th>Kapselgröße</th><th>Lieferant</th><th class="bx-num">Preis</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $rid=(int)$r['rezeptur_id']; $ek=($r['preis']!==null && (float)$r['preis']>0)?(float)$r['preis']:null; ?>
      <tr>
        <td class="muted"><?= h((string)($r['rez_nr'] ?? '')) ?: '–' ?></td>
        <td><?php if ($rid): ?><a class="kundenlink bx-rezpop" href="?p=rezeptur_popup&id=<?= $rid ?>"><?= h((string)($r['rez_name'] ?? '–')) ?></a><?php else: ?><span class="muted">–</span><?php endif; ?></td>
        <td class="muted"><?= h($dfLabel[(string)$r['df']] ?? (string)($r['df'] ?? '')) ?></td>
        <td><?= !empty($r['kapselgroesse']) ? h((string)$r['kapselgroesse']) : '<span class="muted">–</span>' ?></td>
        <td><?= $r['firma'] ? h((string)$r['firma']) : '<span class="muted">–</span>' ?></td>
        <td class="bx-num"><?= $ek!==null ? $preisFmt($ek) . ' &euro;' : '<span class="muted">–</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>

<?php elseif ($tab === 'rohstoff'): ?>
  <?php if (!$rows && !$extraRows): ?><div style="padding:16px" class="muted">Keine Rohstoff-Preise.</div><?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Artikelnr.</th><th>Rohstoff</th><th>Lieferant</th><th class="bx-num">ab Menge</th><th class="bx-num">Preis</th><th>Stand</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="muted"><?= !empty($r['artikelnr']) ? h((string)$r['artikelnr']) : '–' ?></td>
        <td><?php if (!empty($r['item_id'])): ?><a class="kundenlink bx-rezpop" href="?p=rohstoff&id=<?= (int)$r['item_id'] ?>&tab=ek"><?= h($r['artikel'] ?: '–') ?></a><?php else: ?><?= h($r['artikel'] ?: '–') ?><?php endif; ?></td>
        <td><?= $r['lieferant'] !== '' ? h($r['lieferant']) : '<span class="muted">–</span>' ?></td>
        <td class="bx-num"><?= (float)$r['menge_ab'] > 0 ? $preisFmt($r['menge_ab'], 3) . ($r['art_einheit'] ? ' ' . h($r['art_einheit']) : '') : '<span class="muted">–</span>' ?></td>
        <td class="bx-num"><?= $r['preis']!==null ? $preisFmt($r['preis']) . ' ' . h($r['waehrung'] ?: 'EUR') . ($r['art_einheit'] ? ' / ' . h($r['art_einheit']) : '') : '<span class="muted">–</span>' ?></td>
        <td class="muted"><?= !empty($r['stand']) ? h(date('d.m.Y', strtotime((string)$r['stand']))) : '–' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($extraRows): ?>
      <tr><td colspan="6" style="background:var(--line-2,#f3f3f3);font-weight:600;font-size:12px">EK-Preisliste (Referenz aus v3)</td></tr>
      <?php foreach ($extraRows as $r): ?>
      <tr>
        <td class="muted">–</td>
        <td><?= h((string)$r['rohstoff_name']) ?></td>
        <td><?= $r['lieferant'] !== '' ? h((string)$r['lieferant']) : '<span class="muted">–</span>' ?></td>
        <td class="bx-num"><span class="muted">–</span></td>
        <td class="bx-num"><?= $r['eur_kg']!==null ? $preisFmt($r['eur_kg']) . ' &euro; / ' . h($r['einheit'] ?: 'kg') : '<span class="muted">–</span>' ?></td>
        <td class="muted"><?= !empty($r['stand']) ? h(date('d.m.Y', strtotime((string)$r['stand']))) : '–' ?></td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table></div>
  <?php endif; ?>

<?php elseif ($tab === 'verpackung'): ?>
  <?php if (!$rows): ?><div style="padding:16px" class="muted">Keine Verpackungs-Preise (EK-Staffeln am Behälter).</div><?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Artikel</th><th>Typ</th><th>Lieferant</th><th class="bx-num">ab Menge</th><th class="bx-num">EK je Gebinde</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><a class="kundenlink bx-rezpop" href="?p=rohstoff&id=<?= (int)$r['item_id'] ?>"><?= h($r['artikel'] ?: '–') ?></a></td>
        <td class="muted"><?= $r['verpackungsart'] ? h((string)$r['verpackungsart']) : '–' ?></td>
        <td><?= $r['lieferant'] !== '' ? h((string)$r['lieferant']) : '<span class="muted">–</span>' ?></td>
        <td class="bx-num"><?= (int)$r['menge_ab'] > 0 ? number_format((int)$r['menge_ab'], 0, ',', '.') : '<span class="muted">–</span>' ?></td>
        <td class="bx-num"><?= $preisFmt($r['ek_preis']) ?> &euro;</td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>

<?php else: /* zukauf */ ?>
  <?php if (!$rows): ?><div style="padding:16px" class="muted">Keine Fertigprodukt-Zukaufpreise.</div><?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Fertigprodukt</th><th>Größe</th><th>Lieferant</th><th class="bx-num">ab Menge</th><th class="bx-num">Preis</th><th>Stand</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?php if (!empty($r['produkt_id'])): ?><a class="kundenlink bx-rezpop" href="?p=produkt&id=<?= (int)$r['produkt_id'] ?>"><?= h($r['artikel'] ?: '–') ?></a><?php else: ?><?= h($r['artikel'] ?: '–') ?><?php endif; ?></td>
        <td class="muted"><?= $r['groesse'] ? h((string)$r['groesse']) : '–' ?></td>
        <td><?= $r['lieferant'] !== '' ? h((string)$r['lieferant']) : '<span class="muted">–</span>' ?></td>
        <td class="bx-num"><?= (float)$r['menge_ab'] > 0 ? $preisFmt($r['menge_ab'], 3) . ($r['art_einheit'] ? ' ' . h($r['art_einheit']) : '') : '<span class="muted">–</span>' ?></td>
        <td class="bx-num"><?= $r['preis']!==null ? $preisFmt($r['preis']) . ' ' . h($r['waehrung'] ?: 'EUR') . ($r['art_einheit'] ? ' / ' . h($r['art_einheit']) : '') : '<span class="muted">–</span>' ?></td>
        <td class="muted"><?= !empty($r['stand']) ? h(date('d.m.Y', strtotime((string)$r['stand']))) : '–' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
<?php endif; ?>
</div>

<!-- Popup: Klick auf Rezeptur/Artikel zeigt das Detail im Overlay (Größe passt sich dem Inhalt an). -->
<div id="rezPop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:4vh 2vw" onclick="if(event.target===this)rezPopClose()">
  <div style="position:relative;width:min(880px,96vw);max-height:92vh;border-radius:12px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 12px 44px rgba(0,0,0,.35);background:var(--panel,#fff)">
    <div style="display:flex;justify-content:flex-end;align-items:center;padding:8px 10px;border-bottom:1px solid var(--line,#e6e6e6);background:var(--panel,#fff)">
      <button class="btn btn-ghost btn-sm" type="button" onclick="rezPopClose()">Schließen</button>
    </div>
    <iframe id="rezPopFrame" src="" title="Detail" style="display:block;width:100%;border:0;background:transparent;height:300px"></iframe>
  </div>
</div>
<script>
var rezFr = document.getElementById('rezPopFrame');
rezFr.addEventListener('load', function(){
  try {
    var d = rezFr.contentDocument || rezFr.contentWindow.document;
    var max = Math.floor(window.innerHeight * 0.92) - 52;   // Platz für die Kopfzeile
    rezFr.style.height = Math.min((d.body.scrollHeight || 400) + 4, max) + 'px';
  } catch(e) { rezFr.style.height = '70vh'; }
});
function rezPopClose(){ var p=document.getElementById('rezPop'); p.style.display='none'; rezFr.src=''; }
document.addEventListener('click',function(e){
  var a=e.target.closest('.bx-rezpop'); if(!a) return;
  e.preventDefault();
  rezFr.src = a.getAttribute('href');
  document.getElementById('rezPop').style.display='flex';
});
document.addEventListener('keydown',function(e){ if(e.key==='Escape') rezPopClose(); });
</script>
<?php render_footer(); ?>
