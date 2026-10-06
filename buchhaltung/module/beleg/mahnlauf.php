<?php
// Mahnlauf: überfällige offene Posten sammeln, Stufe vorschlagen, auswählen und in einem Lauf mahnen.
// Zeigt außerdem die bisherigen Mahnläufe. Mit ?lauf=<id> die Detailansicht eines Laufs. Route: mahnlauf.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/mahnung.php';
mahn_init();

$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
$u = function_exists('current_user') ? current_user() : null;
$akteur = $u['name'] ?? 'team';

// Mahnlauf starten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'lauf_starten') {
    $posten = [];
    foreach (($_POST['sel'] ?? []) as $bid) {
        $bid = (int)$bid;
        $stufe = (int)($_POST['stufe'][$bid] ?? 0);
        if ($bid && $stufe) $posten[] = ['beleg_id' => $bid, 'stufe' => $stufe];
    }
    $res = mahn_lauf($posten, ['akteur' => $akteur, 'freigeben' => !empty($_POST['freigeben'])]);
    if ($res['anzahl'] > 0) { header('Location: ?p=mahnlauf&lauf=' . $res['lauf_id'] . '&neu=1'); exit; }
    header('Location: ?p=mahnlauf&leer=1'); exit;
}

$laufId = (int)($_GET['lauf'] ?? 0);

// ---------- Detailansicht eines Laufs ----------
if ($laufId) {
    $lauf = mahn_lauf_get($laufId);
    if (!$lauf) { render_header('mahnlauf','Mahnlauf'); bx_head('Mahnlauf nicht gefunden','', bx_btn('Zurück','?p=mahnlauf','ghost')); render_footer(); exit; }
    $eintraege = mahn_eintraege($laufId);
    render_header('mahnlauf', 'Mahnlauf vom ' . ($lauf['datum'] ? date('d.m.Y', strtotime((string)$lauf['datum'])) : ''));
    bx_head('Mahnlauf vom ' . ($lauf['datum'] ? date('d.m.Y', strtotime((string)$lauf['datum'])) : ''),
            (int)$lauf['anzahl'] . ' Mahnung(en) · offen ' . $eur($lauf['summe']) . ' · Gebühren ' . $eur($lauf['gebuehr_summe']),
            bx_btn('Zurück zum Mahnlauf', '?p=mahnlauf', 'ghost'));
    if (isset($_GET['neu'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Mahnlauf erstellt. Öffne die einzelnen Mahnungen zum Drucken/Versenden.</div>';
    ?>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Mahnung</th><th>Kunde</th><th>Rechnung</th><th>Stufe</th><th class="bx-num">Offen</th><th class="bx-num">Gebühr</th><th class="bx-num">Summe</th><th>Neue Frist</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($eintraege as $m): ?>
        <tr>
          <td><strong><?= h((string)$m['nummer']) ?></strong></td>
          <td><?= kunde_link($m['kunde_id'] ?? null, $m['kunde_firma'] ?: '–') ?></td>
          <td><a href="?p=rechnung&id=<?= (int)$m['beleg_id'] ?>"><?= h((string)$m['beleg_nr']) ?></a></td>
          <td><?= h(mahn_stufe_label((int)$m['stufe'])) ?></td>
          <td class="bx-num"><?= $eur($m['offen']) ?></td>
          <td class="bx-num"><?= $eur($m['gebuehr']) ?></td>
          <td class="bx-num"><strong><?= $eur($m['summe']) ?></strong></td>
          <td><?= $m['faellig_neu'] ? h(date('d.m.Y', strtotime((string)$m['faellig_neu']))) : '' ?></td>
          <td><a class="btn btn-ghost btn-sm" href="?p=mahnung&id=<?= (int)$m['id'] ?>" target="_blank">Mahnbrief</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php
    render_footer(); exit;
}

// ---------- Übersicht: Kandidaten + bisherige Läufe ----------
$cfg = mahn_config();
$kandidaten = mahn_kandidaten();
$laeufe = mahn_laeufe();
$sumOffen = 0.0; $sumGeb = 0.0;
foreach ($kandidaten as $k) { $sumOffen += (float)$k['offen']; $sumGeb += (float)$k['gebuehr']; }

render_header('mahnlauf', 'Mahnlauf');
bx_head('Mahnlauf', count($kandidaten) . ' fällige Mahnung(en) vorgeschlagen · offen ' . $eur($sumOffen) . ' · Gebühren ' . $eur($sumGeb),
        bx_btn('Offene Posten', '?p=op_debitoren&filter=ueberfaellig', 'ghost') . ' ' . bx_btn('Einstellungen Mahnwesen', '?p=einstellungen&tab=mahnwesen', 'ghost'));
if (isset($_GET['leer'])) echo '<div class="bx-panel" style="padding:12px 16px;border-color:#e6c4c0">Kein Posten ausgewählt – es wurde nichts gemahnt.</div>';
if (!$cfg['aktiv']) echo '<div class="bx-panel" style="padding:12px 16px;border-color:#e6c4c0">Hinweis: Mahnwesen ist in den Einstellungen deaktiviert – Vorschläge werden trotzdem angezeigt.</div>';
?>
<div class="bx-panel">
  <h2>Fällige Mahnungen</h2>
  <?php if (!$kandidaten): ?>
    <p class="muted" style="margin:0">Aktuell nichts fällig. Stufen greifen ab <?= (int)$cfg['stufen'][1]['frist'] ?> / <?= (int)$cfg['stufen'][2]['frist'] ?> / <?= (int)$cfg['stufen'][3]['frist'] ?> Tagen überfällig (<a href="?p=einstellungen&tab=mahnwesen">anpassen</a>).</p>
  <?php else: ?>
  <form method="post">
    <input type="hidden" name="aktion" value="lauf_starten">
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr>
        <th><input type="checkbox" id="allsel" checked onclick="document.querySelectorAll('.selbox').forEach(c=>c.checked=this.checked)"></th>
        <th>Rechnung</th><th>Kunde</th><th class="bx-num">Tage überf.</th><th>Bisher</th><th>Stufe (Lauf)</th>
        <th class="bx-num">Offen</th><th class="bx-num">Gebühr</th><?php if ($cfg['zins_prozent']>0): ?><th class="bx-num">Zins</th><?php endif; ?><th class="bx-num">Summe</th>
      </tr></thead>
      <tbody>
      <?php foreach ($kandidaten as $k): $bid=(int)$k['id']; ?>
        <tr>
          <td><input type="checkbox" class="selbox" name="sel[]" value="<?= $bid ?>" checked></td>
          <td><a href="?p=rechnung&id=<?= $bid ?>"><strong><?= h((string)$k['nummer']) ?></strong></a><?= $k['faellig'] ? '<div class="muted" style="font-size:12px">fällig '.h(date('d.m.Y',strtotime((string)$k['faellig']))).'</div>' : '' ?></td>
          <td><?= kunde_link($k['kunde_id'] ?? null, $k['kunde_firma'] ?: '–') ?></td>
          <td class="bx-num" style="color:var(--err)"><?= (int)$k['tage_ueberfaellig'] ?></td>
          <td><?= (int)$k['mahnstufe']>0 ? h(mahn_stufe_label((int)$k['mahnstufe'])) : '<span class="muted">–</span>' ?></td>
          <td>
            <select name="stufe[<?= $bid ?>]">
              <?php foreach ([1,2,3] as $s): ?><option value="<?= $s ?>" <?= (int)$k['vorschlag']===$s?'selected':'' ?>><?= h(mahn_stufe_label($s)) ?></option><?php endforeach; ?>
            </select>
          </td>
          <td class="bx-num"><?= $eur($k['offen']) ?></td>
          <td class="bx-num"><?= $eur($k['gebuehr']) ?></td>
          <?php if ($cfg['zins_prozent']>0): ?><td class="bx-num"><?= $eur($k['zins']) ?></td><?php endif; ?>
          <td class="bx-num"><strong><?= $eur($k['summe']) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <label class="bx-check" style="display:flex;align-items:center;gap:8px;margin:12px 0 0"><input type="checkbox" name="freigeben" value="1"> Mahnungen direkt für den Kunden im Portal freigeben</label>
    <div class="bx-row" style="margin-top:12px">
      <button class="btn btn-primary" type="submit" onclick="return confirm('Mahnlauf für die ausgewählten Posten starten? Die Mahnstufe der Rechnungen wird hochgesetzt.');">Mahnlauf starten</button>
    </div>
    <p class="muted" style="margin:8px 0 0;font-size:12px">Die Mahngebühr wird nur auf der Mahnung ausgewiesen – der Rechnungsbetrag selbst bleibt unverändert.</p>
  </form>
  <?php endif; ?>
</div>

<div class="bx-panel">
  <h2>Bisherige Mahnläufe</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Datum</th><th class="bx-num">Mahnungen</th><th class="bx-num">Offen</th><th class="bx-num">Gebühren</th><th>Von</th></tr></thead>
    <tbody>
      <?php if (!$laeufe): ?><tr><td colspan="5" class="muted">Noch keine Mahnläufe.</td></tr><?php endif; ?>
      <?php foreach ($laeufe as $l): ?>
        <tr style="cursor:pointer" onclick="location.href='?p=mahnlauf&lauf=<?= (int)$l['id'] ?>'">
          <td><?= $l['datum'] ? h(date('d.m.Y', strtotime((string)$l['datum']))) : '' ?></td>
          <td class="bx-num"><?= (int)$l['anzahl'] ?></td>
          <td class="bx-num"><?= $eur($l['summe']) ?></td>
          <td class="bx-num"><?= $eur($l['gebuehr_summe']) ?></td>
          <td><?= h((string)($l['akteur'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php
render_footer();
