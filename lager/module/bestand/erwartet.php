<?php
// Erwartete Lieferungen ("Waren, auf die wir warten"): beim Lieferanten bestellt, noch nicht angekommen.
// Als TABELLE (eine Zeile je Position), mit Reitern nach Kategorie. Die Lieferungs-Infos (Lieferant,
// Bestellnr., Termin, Sendung) werden auf jede Zeile uebernommen; "Einbuchen" fuellt den Wareneingang vor.
$lieferungen = erp_erwartete_lieferungen();

kopf('Erwartete Lieferungen', 'erwartet');
seitenkopf('Erwartete Lieferungen', 'Waren, auf die wir warten – beim Lieferanten bestellt, noch nicht angekommen.');
flash_zeigen();

if (!$lieferungen) {
    hinweis('Aktuell sind keine Lieferungen unterwegs. Sobald im Dashboard eine Bestellung als „bestellt" markiert ist, erscheint sie hier.', 'ok');
    fuss();
    return;
}

// --- Positionen flach ziehen; Lieferungs-Infos auf jede Zeile uebernehmen ---
$heute = date('Y-m-d');
$kats  = erp_kategorien();              // rohstoff/verpackung/verbrauch/fertig/verkaufsfertig
$zeilen = [];
foreach ($lieferungen as $l) {
    $eta  = (string)($l['eta_geplant'] ?? '');
    $kopf = [
        'lieferant'      => (string)($l['lieferant'] ?? ''),
        'lieferant_id'   => (int)($l['lieferant_id'] ?? 0),
        'nummer'         => (string)($l['nummer'] ?? ''),
        'bestelldatum'   => (string)($l['bestelldatum'] ?? ''),
        'eta'            => $eta,
        'ueberfaellig'   => $eta !== '' && $eta < $heute,
        'tracking'       => (string)($l['tracking'] ?? ''),
        'versandanbieter'=> (string)($l['versandanbieter'] ?? ''),
    ];
    $pos = $l['positionen'] ?? [];
    if (!$pos) $pos = [[]];   // Lieferung ohne Positionen: trotzdem als eine Zeile zeigen (freies Einbuchen)
    foreach ($pos as $p) {
        $katKey = (string)($p['warenart'] ?? ($p['kategorie'] ?? ''));
        $tabKey = isset($kats[$katKey]) ? $katKey : 'rest';
        $zeilen[] = $kopf + [
            'item_id'       => (int)($p['item_id'] ?? 0),
            'name'          => (string)($p['name'] ?? ''),
            'menge'         => (float)($p['menge'] ?? 0),
            'einheit'       => (string)($p['einheit'] ?? ''),
            'kapselgroesse' => (string)($p['kapselgroesse'] ?? ''),
            'kategorie'     => $katKey,
            'tabkey'        => $tabKey,
            'hat_pos'       => !empty($p),
        ];
    }
}

// Reiter + Zaehlung
$zaehl = ['' => count($zeilen), 'rest' => 0];
foreach ($kats as $k => $_) $zaehl[$k] = 0;
$hatRest = false;
foreach ($zeilen as $z) { $zaehl[$z['tabkey']] = ($zaehl[$z['tabkey']] ?? 0) + 1; if ($z['tabkey'] === 'rest') $hatRest = true; }
$reiter = ['' => 'Alle'];
foreach ($kats as $k => $label) if (($zaehl[$k] ?? 0) > 0) $reiter[$k] = $label;
if ($hatRest) $reiter['rest'] = 'Sonstiges';
?>
<div class="lg-reiter" id="erwReiter">
  <?php foreach ($reiter as $k => $label): ?>
    <a href="#" data-kat="<?= h($k) ?>" class="<?= $k === '' ? 'an' : '' ?>"><?= h($label) ?> <span class="lg-reiter-z"><?= (int)($zaehl[$k] ?? 0) ?></span></a>
  <?php endforeach; ?>
</div>

<div class="bx-listbar">
  <input type="search" id="erwSuche" class="bx-search" placeholder="Suchen: Lieferant, Bestellnr., Artikel …" autofocus>
</div>
<div id="erwLeer" class="bx-panel muted" hidden>Nichts gefunden.</div>

<div class="bx-tablewrap"><table class="bx-table lg-karten" id="erwTab">
  <thead><tr>
    <th>Artikel</th><th>Kategorie</th><th>Lieferant</th><th>Erwartet</th><th>Sendung</th><th>Menge</th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($zeilen as $z):
    $suchteile = array_filter([$z['lieferant'], $z['nummer'], $z['tracking'], $z['name'], $z['einheit'], $z['kapselgroesse']]);
    $such = mb_strtolower(implode(' ', $suchteile));
    $katLabel = $z['tabkey'] === 'rest' ? ($z['kategorie'] !== '' ? ucfirst($z['kategorie']) : 'Sonstiges') : $kats[$z['tabkey']];
    $buchUrl = '?p=eingang'
      . '&item=' . (int)$z['item_id']
      . '&menge=' . rawurlencode(menge_txt($z['menge']))
      . ($z['lieferant_id'] ? '&lieferant=' . (int)$z['lieferant_id'] : '')
      . ($z['nummer'] !== '' ? '&charge=' . rawurlencode($z['nummer']) : '');
  ?>
    <tr data-kat="<?= h($z['tabkey']) ?>" data-such="<?= h($such) ?>">
      <td data-label="Artikel"><?= $z['name'] !== '' ? h($z['name']) : '<span class="muted">Artikel beim Einbuchen wählen</span>' ?><?php
          if ($z['kapselgroesse'] !== ''): ?> <span class="badge" style="margin-left:4px"><?= h($z['kapselgroesse']) ?></span><?php endif; ?></td>
      <td data-label="Kategorie" class="muted"><?= h($katLabel) ?></td>
      <td data-label="Lieferant">
        <?= $z['lieferant'] !== '' ? h($z['lieferant']) : '<span class="muted">Ohne Lieferant</span>' ?>
        <?php if ($z['nummer'] !== ''): ?><br><span class="muted" style="font-size:12px"><?= h($z['nummer']) ?></span><?php endif; ?>
      </td>
      <td data-label="Erwartet">
        <?php if ($z['eta'] !== ''): ?><?= h(date('d.m.Y', strtotime($z['eta']))) ?>
          <?php if ($z['ueberfaellig']): ?><span class="badge badge-warn" style="margin-left:4px">überfällig</span><?php endif; ?>
        <?php else: ?><span class="muted">offen</span><?php endif; ?>
        <?php if ($z['bestelldatum'] !== ''): ?><br><span class="muted" style="font-size:12px">best. <?= h(date('d.m.Y', strtotime($z['bestelldatum']))) ?></span><?php endif; ?>
      </td>
      <td data-label="Sendung">
        <?php if ($z['tracking'] !== ''): ?><span class="lg-code"><?= h($z['tracking']) ?></span>
          <?php if ($z['versandanbieter'] !== ''): ?><br><span class="muted" style="font-size:12px"><?= h($z['versandanbieter']) ?></span><?php endif; ?>
        <?php else: ?><span class="muted">–</span><?php endif; ?>
      </td>
      <td data-label="Menge"><?= h(menge_txt($z['menge'])) ?> <?= h($z['einheit']) ?></td>
      <td data-label="" style="text-align:right">
        <?php if ($z['hat_pos']): ?>
          <a class="btn btn-primary btn-sm" href="<?= h($buchUrl) ?>">Einbuchen</a>
        <?php else: ?>
          <a class="btn btn-ghost btn-sm" href="?p=we<?= $z['lieferant_id'] ? '&lieferant=' . (int)$z['lieferant_id'] : '' ?><?= $z['nummer'] !== '' ? '&charge=' . rawurlencode($z['nummer']) : '' ?>">Freies Einbuchen</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>

<script>
(function(){
  var reiter = document.getElementById('erwReiter'), q = document.getElementById('erwSuche');
  var zeilen = Array.prototype.slice.call(document.querySelectorAll('#erwTab tbody tr'));
  var leer = document.getElementById('erwLeer'), tab = document.getElementById('erwTab');
  var kat = '';
  function filter(){
    var words = (q && q.value ? q.value : '').trim().toLowerCase().split(/\s+/).filter(Boolean), treffer = 0;
    zeilen.forEach(function(tr){
      var okKat = !kat || tr.getAttribute('data-kat') === kat;
      var hay = tr.getAttribute('data-such') || '';
      var okQ = words.every(function(w){ return hay.indexOf(w) >= 0; });
      var hit = okKat && okQ;
      tr.style.display = hit ? '' : 'none'; if (hit) treffer++;
    });
    if (leer) leer.hidden = treffer > 0;
    if (tab) tab.style.display = treffer > 0 ? '' : 'none';
  }
  if (reiter) reiter.addEventListener('click', function(e){
    var a = e.target.closest('a[data-kat]'); if (!a) return; e.preventDefault();
    kat = a.getAttribute('data-kat');
    Array.prototype.forEach.call(reiter.querySelectorAll('a'), function(x){ x.classList.toggle('an', x === a); });
    filter();
  });
  if (q) q.addEventListener('input', filter);
  filter();
})();
</script>
<?php
fuss();
