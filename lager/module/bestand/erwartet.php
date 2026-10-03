<?php
// Erwartete Lieferungen ("Waren, auf die wir warten"): beim Lieferanten bestellt, noch nicht angekommen.
// Zeigt Termin + Sendungsnummer + Positionen; je Position "Einbuchen" -> Wareneingang vorbefuellt.
$lieferungen = erp_erwartete_lieferungen();

kopf('Erwartete Lieferungen', 'erwartet');
seitenkopf('Erwartete Lieferungen', 'Waren, auf die wir warten – beim Lieferanten bestellt, noch nicht angekommen.',
    '<a class="btn btn-ghost" href="?p=we">Freies Einbuchen</a>');
flash_zeigen();

if (!$lieferungen) {
    hinweis('Aktuell sind keine Lieferungen unterwegs. Sobald im Dashboard eine Bestellung als „bestellt" markiert ist, erscheint sie hier.', 'ok');
    fuss();
    return;
}

?>
<div class="bx-listbar">
  <input type="search" id="erwSuche" class="bx-search" placeholder="Suchen: Lieferant, Bestellnr., Artikel …" autofocus>
</div>
<div id="erwLeer" class="bx-panel muted" hidden>Nichts gefunden.</div>
<?php
$heute = date('Y-m-d');
foreach ($lieferungen as $l):
    $eta = (string)($l['eta_geplant'] ?? '');
    $ueberfaellig = $eta !== '' && $eta < $heute;
    $suchteile = [(string)($l['lieferant'] ?? ''), (string)($l['nummer'] ?? ''), (string)($l['tracking'] ?? '')];
    foreach (($l['positionen'] ?? []) as $sp) $suchteile[] = (string)($sp['name'] ?? '');
    $such = mb_strtolower(implode(' ', array_filter($suchteile)));
?>
<div class="bx-panel erw-karte" data-such="<?= h($such) ?>">
  <div class="bx-head" style="margin:0 0 10px">
    <div>
      <h2 style="margin:0"><?= h((string)($l['lieferant'] ?: 'Ohne Lieferant')) ?>
        <?php if (!empty($l['nummer'])): ?><span class="muted" style="font-weight:normal">· <?= h((string)$l['nummer']) ?></span><?php endif; ?>
      </h2>
      <p class="bx-sub" style="margin:4px 0 0">
        <?php if (!empty($l['bestelldatum'])): ?>bestellt am <?= h(date('d.m.Y', strtotime((string)$l['bestelldatum']))) ?><?php endif; ?>
        <?php if ($eta !== ''): ?> · erwartet <strong><?= h(date('d.m.Y', strtotime($eta))) ?></strong>
          <?php if ($ueberfaellig): ?><span class="badge badge-warn" style="margin-left:6px">überfällig</span><?php endif; ?>
        <?php endif; ?>
      </p>
    </div>
    <?php if (!empty($l['tracking'])): ?>
      <div class="bx-row" style="align-items:center;gap:8px">
        <span class="muted">Sendung<?= !empty($l['versandanbieter']) ? ' (' . h((string)$l['versandanbieter']) . ')' : '' ?>:</span>
        <span class="lg-code"><?= h((string)$l['tracking']) ?></span>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($l['positionen'])): ?>
  <div class="bx-tablewrap"><table class="bx-table" style="table-layout:fixed;width:100%">
    <colgroup><col><col style="width:200px"><col style="width:130px"></colgroup>
    <thead><tr><th>Artikel</th><th class="bx-num">Erwartete Menge</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($l['positionen'] as $p):
        $name = (string)($p['name'] ?? '');
        $menge = (float)($p['menge'] ?? 0);
        $einh = (string)($p['einheit'] ?? '');
        $kg = (string)($p['kapselgroesse'] ?? '');
        $buchUrl = '?p=eingang'
          . '&item=' . (int)($p['item_id'] ?? 0)
          . '&menge=' . rawurlencode(menge_txt($menge))
          . ($l['lieferant_id'] ? '&lieferant=' . (int)$l['lieferant_id'] : '')
          . (!empty($l['nummer']) ? '&charge=' . rawurlencode((string)$l['nummer']) : '');
      ?>
      <tr>
        <td><?= $name !== '' ? h($name) : '<span class="muted">Artikel beim Einbuchen wählen</span>' ?><?php
            if ($kg !== ''): ?> <span class="badge" style="margin-left:4px"><?= h($kg) ?></span><?php endif; ?></td>
        <td class="bx-num"><?= h(menge_txt($menge)) ?> <?= h($einh) ?></td>
        <td style="text-align:right">
          <a class="btn btn-primary btn-sm" href="<?= h($buchUrl) ?>">Einbuchen</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?>
    <p class="muted" style="margin:0">Keine Positionen hinterlegt. <a href="?p=we">Freies Einbuchen</a>.</p>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<script>
(function(){
  var q = document.getElementById('erwSuche'); if (!q) return;
  var karten = document.querySelectorAll('.erw-karte'), leer = document.getElementById('erwLeer');
  function filter(){
    var words = q.value.trim().toLowerCase().split(/\s+/).filter(Boolean), treffer = 0;
    karten.forEach(function(k){
      var hay = k.getAttribute('data-such') || '';
      var hit = words.every(function(w){ return hay.indexOf(w) >= 0; });
      k.style.display = hit ? '' : 'none'; if (hit) treffer++;
    });
    if (leer) leer.hidden = treffer > 0;
  }
  q.addEventListener('input', filter);
})();
</script>
<?php
fuss();
