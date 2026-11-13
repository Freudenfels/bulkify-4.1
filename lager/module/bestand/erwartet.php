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
    // Ein Termin am Bestelltag oder davor ist ein Eingabefehler (BE-2924: bestellt und „erwartet" am 28.10.) – nicht als
    // „überfällig" zeigen, sondern wie „offen" (Termin an der Bestellung im Dashboard korrigierbar).
    $bdat = (string)($l['bestelldatum'] ?? '');
    if ($eta !== '' && $bdat !== '' && $eta <= $bdat) $eta = '';
    $kopf = [
        'lieferant'      => (string)($l['lieferant'] ?? ''),
        'lieferant_id'   => (int)($l['lieferant_id'] ?? 0),
        'bestellung_id'  => (int)($l['id'] ?? 0),
        'nummer'         => (string)($l['nummer'] ?? ''),
        'bestelldatum'   => (string)($l['bestelldatum'] ?? ''),
        'eta'            => $eta,
        'ueberfaellig'   => $eta !== '' && $eta < $heute,
        'tracking'       => (string)($l['tracking'] ?? ''),
        'versandanbieter'=> (string)($l['versandanbieter'] ?? ''),
        'sendung'        => (string)($l['sendung'] ?? ''),
        'pakete'         => (array)($l['pakete'] ?? []),
        'unbestaetigt'   => (string)($l['status'] ?? '') === 'gesendet',   // Portal-Bestellung, Lieferant hat noch nicht bestätigt
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
            'fuer_auftrag'  => (string)($p['fuer_auftrag'] ?? ''),
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

<?php // 13.11.2026 (Paket Teil 6.8): „Nächste Tage“ – je Lieferung Lieferant, Sendungsart, Anzahl, Gewicht (Platz und Stapler einplanen)
$naechste = [];
$bis = date('Y-m-d', strtotime('+7 days'));
foreach ($lieferungen as $l) { $e = (string)($l['eta_geplant'] ?? ''); if ($e !== '' && $e <= $bis) $naechste[] = $l; }
usort($naechste, fn($a, $b) => strcmp((string)$a['eta_geplant'], (string)$b['eta_geplant']));
if ($naechste): ?>
<div class="bx-panel" style="margin-bottom:14px"><h2 style="margin-top:0">Nächste Tage</h2>
  <table class="bx-table"><thead><tr><th>Datum</th><th>Lieferant</th><th>Bestellung</th><th>Sendung</th><th>Pakete / Tracking</th></tr></thead><tbody>
  <?php foreach ($naechste as $l): $e = (string)$l['eta_geplant']; ?>
    <tr><td data-label="Datum"><?= h(date('d.m.', strtotime($e))) ?><?= $e < date('Y-m-d') ? ' <span class="badge badge-warn">überfällig</span>' : ($e === date('Y-m-d') ? ' <b style="font-weight:600">heute</b>' : '') ?></td>
      <td data-label="Lieferant"><?= $l['lieferant'] ? h((string)$l['lieferant']) : '<span class="muted">ohne Lieferant</span>' ?></td>
      <td data-label="Bestellung"><?= h((string)$l['nummer']) ?></td>
      <td data-label="Sendung"><?= ($l['sendung'] ?? '') !== '' ? h((string)$l['sendung']) : '<span class="muted">noch nicht angegeben</span>' ?></td>
      <td data-label="Pakete"><?= $l['pakete'] ? count($l['pakete']) . ' · ' . h(implode(', ', array_slice($l['pakete'], 0, 3))) : ((string)($l['tracking'] ?? '') !== '' ? h((string)$l['tracking']) : '<span class="muted">–</span>') ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
</div>
<?php endif; ?>
<div class="bx-tablewrap"><table class="bx-table lg-karten" id="erwTab">
  <thead><tr>
    <th>Artikel</th><th>Kategorie</th><th>Lieferant</th><th>Erwartet</th><th>Sendung</th><th>Menge</th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($zeilen as $z):
    $suchteile = array_filter([$z['lieferant'], $z['nummer'], $z['tracking'], $z['name'], $z['einheit'], $z['kapselgroesse']]);
    $such = mb_strtolower(implode(' ', $suchteile));
    $katLabel = $z['tabkey'] === 'rest' ? ($z['kategorie'] !== '' ? ucfirst($z['kategorie']) : 'Sonstiges') : $kats[$z['tabkey']];
    // „Einbuchen" führt in den vollwertigen Wareneingang (?p=we) mit vorgewählter Lieferung: dort wird die Bestellung
    // beim Buchen geschlossen (S-06). Die alte Schnell-Maske ?p=eingang kennt keine Bestellung.
    $buchUrl = '?p=we&lieferung=' . (int)$z['bestellung_id'];
  ?>
    <tr data-kat="<?= h($z['tabkey']) ?>" data-such="<?= h($such) ?>">
      <td data-label="Artikel"><?= $z['name'] !== '' ? ($z['hat_pos'] ? '<a href="' . h($buchUrl) . '" style="font-weight:600">' . h($z['name']) . '</a>' : h($z['name'])) : '<span class="muted">Artikel beim Einbuchen wählen</span>' ?><?php
          if ($z['kapselgroesse'] !== ''): ?> <span class="badge" style="margin-left:4px"><?= h($z['kapselgroesse']) ?></span><?php endif;
          if (($z['fuer_auftrag'] ?? '') !== ''): ?><div class="muted" style="font-size:12px;margin-top:2px">für <?= h($z['fuer_auftrag']) ?></div><?php endif; ?></td>
      <td data-label="Kategorie" class="muted"><?= h($katLabel) ?></td>
      <td data-label="Lieferant">
        <?= $z['lieferant'] !== '' ? h($z['lieferant']) : '<span class="muted">Ohne Lieferant</span>' ?>
        <?php if ($z['nummer'] !== ''): ?><br><span class="muted" style="font-size:12px"><?= h($z['nummer']) ?><?= !empty($z['unbestaetigt']) ? ' · noch nicht bestätigt' : '' ?></span><?php endif; ?>
      </td>
      <td data-label="Erwartet">
        <?php if ($z['eta'] !== ''): ?><?= h(date('d.m.Y', strtotime($z['eta']))) ?>
          <?php if ($z['ueberfaellig']): ?><span class="badge badge-warn" style="margin-left:4px">überfällig</span><?php endif; ?>
        <?php else: ?><span class="muted">offen</span><?php endif; ?>
        <?php if ($z['bestelldatum'] !== ''): ?><br><span class="muted" style="font-size:12px">best. <?= h(date('d.m.Y', strtotime($z['bestelldatum']))) ?></span><?php endif; ?>
      </td>
      <td data-label="Sendung">
        <?php if ($z['tracking'] !== ''): ?><span class="lg-code"><?= h($z['tracking']) ?></span><?= count($z['pakete']) > 1 ? ' <span class="muted" style="font-size:12px">+' . (count($z['pakete']) - 1) . ' Pakete</span>' : '' ?>
          <?php if ($z['versandanbieter'] !== ''): ?><br><span class="muted" style="font-size:12px"><?= h($z['versandanbieter']) ?></span><?php endif; ?>
        <?php elseif ($z['sendung'] === ''): ?><span class="muted">–</span><?php endif; ?>
        <?php if ($z['sendung'] !== ''): ?><div style="font-size:12px;margin-top:2px"><?= h($z['sendung']) ?></div><?php endif; ?>
      </td>
      <td data-label="Menge"><?= h(menge_txt($z['menge'])) ?> <?= h($z['einheit']) ?></td>
      <td data-label="" style="text-align:right">
        <?php if ($z['hat_pos']): ?>
          <a class="btn btn-primary btn-sm" href="<?= h($buchUrl) ?>">Einbuchen</a>
        <?php else: ?>
          <a class="btn btn-ghost btn-sm" href="?p=we&amp;weg=regulaer">Ohne Bestellung einbuchen</a>
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
