<?php
// Produktionsaufträge – nur AKTIVE (Vorbereitung + offen + laufend). Abgeschlossene stehen im Archiv (?p=archiv).
// Reiter trennen nach Produzierbarkeit: Bereit / Laufend / Gesperrt (+ Alle). Dazu Textsuche und
// Spaltensortierung (Auftragseingang alt→neu als Standard, Status). Nur lesend über erp.php.

// --- Daten + Einteilung in Reiter-Eimer ---------------------------------------------------------
$alle = erp_produktionsauftraege('');   // vorbereitung + offen + laufend

// Produktionsbereitschaft je Auftrag bestimmen und in einen Eimer einsortieren.
//   bereit   = produzierbar, noch nicht gestartet
//   laufend  = läuft bereits (mind. ein Schritt erledigt)
//   gesperrt = nicht startbar: in Vorbereitung (nicht freigegeben) ODER wartet auf Material
$eimer = ['bereit' => [], 'laufend' => [], 'gesperrt' => []];
foreach ($alle as $i => $pa) {
    $f = (int)$pa['schritte_fertig'];
    $b = erp_pa_bereitschaft((int)$pa['id'], (string)$pa['status'], $f)['status'];
    $k = $b === 'laeuft' ? 'laufend' : ($b === 'bereit' ? 'bereit' : 'gesperrt');
    $alle[$i]['_bereit'] = $b;   // $alle direkt annotieren (foreach läuft per Wert)
    $alle[$i]['_eimer']  = $k;
    $eimer[$k][] = $alle[$i];
}

$tabs = [
    'alle'     => 'Alle',
    'bereit'   => 'Bereit',
    'laufend'  => 'Laufend',
    'gesperrt' => 'Gesperrt',
];
$tab = (string)($_GET['tab'] ?? 'alle');
if (!isset($tabs[$tab])) $tab = 'alle';
$zahl = ['alle' => count($alle), 'bereit' => count($eimer['bereit']), 'laufend' => count($eimer['laufend']), 'gesperrt' => count($eimer['gesperrt'])];

$pas = $tab === 'alle' ? $alle : $eimer[$tab];

$eingangVon = fn($pa) => (string)($pa['auftrag_eingang'] ?? ($pa['angelegt'] ?? ''));
// Standard-Sortierung: „in Produktion" (laufend) und „produzierbar" (bereit) IMMER OBEN, Gesperrtes
// (Vorbereitung / wartet auf Material) unten. Innerhalb der Gruppe nach Auftragseingang alt → neu.
// Clientseitig über die Spaltenköpfe umsortierbar.
$prioRang = fn($pa) => ['laufend' => 0, 'bereit' => 1, 'gesperrt' => 2][$pa['_eimer'] ?? 'gesperrt'] ?? 2;
usort($pas, function ($a, $b) use ($eingangVon, $prioRang) {
    $pr = $prioRang($a) - $prioRang($b);
    if ($pr !== 0) return $pr;
    $ea = $eingangVon($a) ?: '9999'; $eb = $eingangVon($b) ?: '9999';
    return strcmp($ea, $eb);
});

// Status-Rang für die Status-Sortierung (Workflow-Reihenfolge).
$statusRang = fn(string $s) => ['vorbereitung' => 0, 'offen' => 1, 'laufend' => 2][$s] ?? 3;

kopf('Produktionsaufträge', 'liste');
seitenkopf('Produktionsaufträge', count($alle) . ' aktive ' . (count($alle) === 1 ? 'Auftrag' : 'Aufträge'),
    '<a class="btn btn-ghost btn-sm" href="?p=archiv">Archiv</a>');
?>
<style>
  .bx-sort { cursor:pointer; user-select:none; white-space:nowrap }
  .bx-sort .arr { opacity:.35; font-size:11px; margin-left:4px }
  .bx-sort.on .arr { opacity:1 }
  .pa-tools { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:14px }
  .pa-tools .such { flex:1; min-width:220px; max-width:340px }
  .pa-tools .such input { width:100% }
  .pa-leer td { color:var(--muted,#888) }
</style>

<div class="settabs">
  <?php foreach ($tabs as $key => $label): ?>
    <a href="?p=liste&tab=<?= h($key) ?>" class="<?= $tab === $key ? 'on' : '' ?>"><?= h($label) ?> <span class="muted">(<?= (int)$zahl[$key] ?>)</span></a>
  <?php endforeach; ?>
</div>

<div class="pa-tools">
  <div class="bx-field such" style="margin:0">
    <label for="pa-suche">Suche</label>
    <input type="search" id="pa-suche" placeholder="Nummer, Produkt, Kunde oder Auftrag …" autocomplete="off">
  </div>
  <div class="muted" style="align-self:flex-end;padding-bottom:9px"><span id="pa-count"><?= count($pas) ?></span> angezeigt</div>
</div>

<?php if (!$pas): ?>
  <div class="bx-panel"><div class="muted">Keine Aufträge in diesem Reiter. Abgeschlossene stehen im <a href="?p=archiv">Archiv</a>.</div></div>
<?php else: ?>
<div class="bx-tablewrap"><table class="bx-table" id="pa-tabelle">
  <thead><tr>
    <th>Nr.</th><th>Produkt</th><th>Kunde</th><th class="bx-num">Menge</th>
    <th class="bx-sort" data-sort="geplant">Wann dran<span class="arr"></span></th>
    <th class="bx-sort" data-sort="eingang">Auftragseingang<span class="arr"></span></th>
    <th class="bx-sort on" data-sort="prio" data-dir="1">Produzierbar?<span class="arr">▲</span></th><th>Fortschritt</th>
    <th class="bx-sort" data-sort="statusrank">Status<span class="arr"></span></th>
  </tr></thead>
  <tbody id="pa-rows">
  <?php foreach ($pas as $pa):
      $g = (int)$pa['schritte_gesamt']; $f = (int)$pa['schritte_fertig'];
      $proz = $g > 0 ? round($f * 100 / $g) : 0;
      $ber = $pa['_bereit'];
      $eingang = $eingangVon($pa);
      $gesperrt = ($pa['_eimer'] ?? '') === 'gesperrt';
      $suche = mb_strtolower(trim(($pa['nummer'] ?? '') . ' ' . ($pa['auftrag_nr'] ?? '') . ' ' . ($pa['produkt_name'] ?? '') . ' ' . ($pa['form'] ?? '') . ' ' . ($pa['kunde'] ?? ''))); ?>
    <tr onclick="location.href='?p=pa&id=<?= (int)$pa['id'] ?>'"
        style="cursor:pointer<?= $gesperrt ? ';opacity:.6' : '' ?>"
        title="<?= $gesperrt ? 'Gesperrt – noch nicht produzierbar' : '' ?>"
        data-eingang="<?= h($eingang ?: '9999') ?>"
        data-geplant="<?= h((string)($pa['geplant_am'] ?? '') ?: '9999') ?>"
        data-statusrank="<?= (int)$statusRang((string)$pa['status']) ?>"
        data-prio="<?= (int)$prioRang($pa) ?>"
        data-search="<?= h($suche) ?>">
      <td><strong><?= h((string)$pa['nummer']) ?></strong><?php if (!empty($pa['auftrag_nr'])): ?><br><span class="muted" style="font-size:12px"><?= h((string)$pa['auftrag_nr']) ?></span><?php endif; ?></td>
      <td><?= h((string)($pa['produkt_name'] ?: '–')) ?><?php if (!empty($pa['form'])): ?> <span class="muted" style="font-size:12px">· <?= h((string)$pa['form']) ?></span><?php endif; ?></td>
      <td><?= h((string)($pa['kunde'] ?: '–')) ?></td>
      <td class="bx-num"><?= menge_txt($pa['menge']) ?></td>
      <td><?= !empty($pa['geplant_am']) ? h(date('d.m.Y', strtotime((string)$pa['geplant_am']))) : '<span class="muted">—</span>' ?></td>
      <td class="muted"><?= $eingang ? h(fmt_zeit($eingang, 'd.m.Y')) : '–' ?></td>
      <td><?= bereit_badge($ber) ?></td>
      <td style="min-width:120px"><?= $g > 0 ? ('Schritt ' . $f . '/' . $g . ' · ' . $proz . '%') : '<span class="muted">–</span>' ?></td>
      <td><?= pa_badge((string)$pa['status']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<script>
(function () {
  var such  = document.getElementById('pa-suche');
  var tbody = document.getElementById('pa-rows');
  if (!tbody) return;
  var countEl = document.getElementById('pa-count');

  function alleZeilen() { return Array.prototype.slice.call(tbody.querySelectorAll('tr')); }

  // --- Suche (clientseitig, sofort) ---
  function sucheAnwenden() {
    var t = (such.value || '').trim().toLowerCase();
    var n = 0;
    alleZeilen().forEach(function (r) {
      var treffer = !t || (r.dataset.search || '').indexOf(t) !== -1;
      r.style.display = treffer ? '' : 'none';
      if (treffer) n++;
    });
    if (countEl) countEl.textContent = n;
  }
  if (such) such.addEventListener('input', sucheAnwenden);

  // --- Spaltensortierung ---
  function sortiere(th) {
    var key = th.dataset.sort;
    var dir = th.dataset.dir === '1' ? -1 : 1;   // Klick kippt die Richtung
    document.querySelectorAll('#pa-tabelle th.bx-sort').forEach(function (h) {
      h.classList.remove('on'); h.removeAttribute('data-dir');
      var a = h.querySelector('.arr'); if (a) a.textContent = '';
    });
    th.classList.add('on'); th.dataset.dir = dir === 1 ? '1' : '-1';
    var arr = th.querySelector('.arr'); if (arr) arr.textContent = dir === 1 ? '▲' : '▼';

    var zeilen = alleZeilen();
    zeilen.sort(function (a, b) {
      var va = a.dataset[key] || '', vb = b.dataset[key] || '';
      if (key === 'statusrank' || key === 'prio') return (parseInt(va, 10) - parseInt(vb, 10)) * dir;
      return va < vb ? -1 * dir : (va > vb ? 1 * dir : 0);
    });
    zeilen.forEach(function (r) { tbody.appendChild(r); });
  }
  document.querySelectorAll('#pa-tabelle th.bx-sort').forEach(function (th) {
    th.addEventListener('click', function () { sortiere(th); });
  });
})();
</script>
<?php fuss();
