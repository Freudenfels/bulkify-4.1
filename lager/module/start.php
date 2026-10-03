<?php
// Lager-Startseite: Überblick auf einen Blick. Kennzahlen (Lager 1/2, Quarantäne, MHD, erwartete
// Lieferungen, Blinker-Batterie), Schnellzugriffe und zwei Listen (MHD-kritisch, letzte Bewegungen).
// Alle Dashboard-Zahlen kommen über die Naht erp.php; Lager-eigenes (Bewegungen/Batterie) direkt.
$k = erp_lager_kennzahlen();

$erwartet = erp_erwartete_lieferungen();
$heute = date('Y-m-d');
$erw_zahl = count($erwartet);
$erw_ueber = 0;
foreach ($erwartet as $e) { $eta = (string)($e['eta_geplant'] ?? ''); if ($eta !== '' && $eta < $heute) $erw_ueber++; }

$batt = function_exists('leiste_batterie_zahl') ? (int) leiste_batterie_zahl() : 0;
$mhd  = erp_mhd_kritisch(90, 10);
$bew  = lg_bewegungen(10);

kopf('Übersicht', 'uebersicht');
seitenkopf('Übersicht', 'Lager auf einen Blick.');
flash_zeigen();
?>
<style>
  .lgd-akt{display:flex;flex-wrap:wrap;gap:var(--sp-3);margin-bottom:var(--sp-5)}
  .lgd-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:var(--sp-4);margin-bottom:var(--sp-6)}
  .lgd-card{display:block;border:1px solid var(--line);border-radius:var(--r-sm);padding:var(--sp-4);background:var(--panel);color:var(--text)}
  .lgd-card:hover{text-decoration:none;border-color:var(--gruen)}
  .lgd-card .lab{color:var(--muted);font-size:var(--fs-sm)}
  .lgd-card .num{font-size:var(--fs-xl);font-weight:600;margin-top:2px;line-height:1.1}
  .lgd-card .sub{color:var(--muted);font-size:var(--fs-sm);margin-top:2px}
  .lgd-card.warn{border-color:#eed9b6;background:#fbf1e0}
  .lgd-card.err{border-color:#eec7c2;background:#fbeae8}
  .lgd-card.warn .num{color:#8a5a12} .lgd-card.err .num{color:#8f231b}
  :root[data-theme="dark"] .lgd-card.warn{background:#2a2110} :root[data-theme="dark"] .lgd-card.err{background:#2a1512}
  .lgd-cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:var(--sp-5)}
</style>

<div class="lgd-akt">
  <a class="btn btn-primary" href="?p=we">Einbuchen</a>
  <a class="btn btn-ghost" href="?p=finden">Finden</a>
  <a class="btn btn-ghost" href="?p=ausgang">Warenausgang</a>
  <a class="btn btn-ghost" href="?p=erwartet">Erwartete Lieferungen</a>
</div>

<div class="lgd-kpi">
  <a class="lgd-card" href="?p=bestand">
    <div class="lab">Lager 1 · eigener Bestand</div>
    <div class="num"><?= (int)$k['l1_chargen'] ?></div>
    <div class="sub">Chargen · <?= (int)$k['l1_artikel'] ?> Artikel</div>
  </a>
  <a class="lgd-card" href="?p=l2_bestand">
    <div class="lab">Lager 2 · Kundenware</div>
    <div class="num"><?= (int)$k['l2_chargen'] ?></div>
    <div class="sub">Chargen · <?= (int)$k['l2_kunden'] ?> Kunden</div>
  </a>
  <a class="lgd-card<?= $k['quarantaene'] > 0 ? ' warn' : '' ?>" href="?p=bestand">
    <div class="lab">In Quarantäne</div>
    <div class="num"><?= (int)$k['quarantaene'] ?></div>
    <div class="sub">warten auf Freigabe</div>
  </a>
  <a class="lgd-card<?= $k['mhd_bald'] > 0 ? ' warn' : '' ?>" href="#mhd">
    <div class="lab">MHD bald (90 Tage)</div>
    <div class="num"><?= (int)$k['mhd_bald'] ?></div>
    <div class="sub">bald ablaufend</div>
  </a>
  <a class="lgd-card<?= $k['mhd_ablauf'] > 0 ? ' err' : '' ?>" href="#mhd">
    <div class="lab">MHD abgelaufen</div>
    <div class="num"><?= (int)$k['mhd_ablauf'] ?></div>
    <div class="sub">bitte prüfen</div>
  </a>
  <a class="lgd-card" href="?p=erwartet">
    <div class="lab">Erwartete Lieferungen</div>
    <div class="num"><?= (int)$erw_zahl ?></div>
    <div class="sub">unterwegs</div>
  </a>
  <a class="lgd-card<?= $erw_ueber > 0 ? ' warn' : '' ?>" href="?p=erwartet">
    <div class="lab">Überfällig</div>
    <div class="num"><?= (int)$erw_ueber ?></div>
    <div class="sub">über Termin</div>
  </a>
  <?php if ($batt > 0): ?>
  <a class="lgd-card warn" href="?p=batterie">
    <div class="lab">Blinker-Batterie</div>
    <div class="num"><?= (int)$batt ?></div>
    <div class="sub">brauchen neue Batterie</div>
  </a>
  <?php endif; ?>
</div>

<div class="lgd-cols">
  <div class="bx-panel" id="mhd">
    <div class="bx-head" style="margin:0 0 var(--sp-3)"><div><h2 style="margin:0">MHD im Blick</h2>
      <p class="bx-sub" style="margin:4px 0 0">Abgelaufen zuerst, dann die nächsten 90 Tage.</p></div></div>
    <?php if (!$mhd): ?>
      <p class="muted" style="margin:0">Nichts läuft in nächster Zeit ab. </p>
    <?php else: ?>
    <div class="bx-tablewrap"><table class="bx-table" style="table-layout:fixed;width:100%">
      <colgroup><col><col style="width:110px"><col style="width:120px"></colgroup>
      <thead><tr><th>Artikel</th><th>Bestand</th><th class="bx-num">MHD</th></tr></thead>
      <tbody>
      <?php foreach ($mhd as $c): $ab = (string)$c['mhd'] < $heute; ?>
        <tr onclick="location.href='?p=charge&id=<?= (int)$c['id'] ?>'" style="cursor:pointer">
          <td><a class="lg-namelink" href="?p=charge&id=<?= (int)$c['id'] ?>" onclick="event.stopPropagation()"><?= h((string)$c['item_name']) ?></a>
            <?php if (!empty($c['kunde'])): ?><span class="badge" style="margin-left:4px"><?= h((string)$c['kunde']) ?></span><?php endif; ?></td>
          <td><?= h(menge_txt($c['menge_verfuegbar'])) ?> <?= h((string)$c['einheit']) ?></td>
          <td class="bx-num"><span class="badge <?= $ab ? 'badge-err' : 'badge-warn' ?>"><?= h(date('d.m.Y', strtotime((string)$c['mhd']))) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <div class="bx-panel">
    <div class="bx-head" style="margin:0 0 var(--sp-3)"><div><h2 style="margin:0">Letzte Bewegungen</h2></div>
      <div class="bx-row"><a class="btn btn-ghost btn-sm" href="?p=bewegungen">Alle</a></div></div>
    <?php if (!$bew): ?>
      <p class="muted" style="margin:0">Noch keine Bewegungen.</p>
    <?php else: ?>
    <div class="bx-tablewrap"><table class="bx-table" style="table-layout:fixed;width:100%">
      <colgroup><col style="width:92px"><col style="width:96px"><col><col style="width:96px"></colgroup>
      <thead><tr><th>Zeit</th><th>Richtung</th><th>Artikel</th><th class="bx-num">Menge</th></tr></thead>
      <tbody>
      <?php foreach ($bew as $b): ?>
        <tr>
          <td class="muted"><?= h(fmt_zeit((string)$b['angelegt'], 'd.m. H:i')) ?></td>
          <td><?= $b['typ'] === 'ein' ? '<span class="badge badge-ok">Eingang</span>' : '<span class="badge badge-warn">Ausgang</span>' ?></td>
          <td><?php if (!empty($b['charge_id'])): ?><a class="lg-namelink" href="?p=charge&id=<?= (int)$b['charge_id'] ?>"><?= h((string)$b['item_name']) ?></a><?php else: ?><?= h((string)$b['item_name']) ?><?php endif; ?></td>
          <td class="bx-num"><?= h(menge_txt($b['menge'])) ?> <?= h((string)$b['einheit']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>
<?php
fuss();
