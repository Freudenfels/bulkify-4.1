<?php
// Versand – versandbereite Aufträge versenden (Fertigware ausbuchen + Lieferschein)
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$hinweis = ''; $fehler = '';
// Das Lager entscheidet je Auftrag: an den Kunden senden ODER an Lager 2 (Fremdlager) übergeben.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'versenden') {
    $res = auftrag_versenden((int)($_POST['auftrag_id'] ?? 0));
    header('Location: ?p=versand&' . ($res['ok'] ? 'ok=1' : 'fehler=' . urlencode($res['msg']))); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'an_lager2') {
    $res = auftrag_ins_fremdlager((int)($_POST['auftrag_id'] ?? 0));
    header('Location: ?p=versand&' . ($res['ok'] ? 'l2=1' : 'fehler=' . urlencode($res['msg']))); exit;
}

$q    = trim($_GET['q'] ?? '');
$rows = all("SELECT a.*, k.firma AS kunde_firma, p.name AS produkt_name,
             (SELECT COALESCE(SUM(c.menge_verfuegbar),0) FROM item i JOIN charge c ON c.item_id=i.id
              WHERE i.produkt_id=a.produkt_id AND i.kategorie='verkaufsfertig' AND c.status='frei') AS fertig_frei,
             (SELECT nummer FROM beleg b WHERE b.auftrag_id=a.id AND b.typ='lieferschein' LIMIT 1) AS lieferschein_nr,
             COALESCE(k.nutzt_fulfillment,0) AS fulfillment
             FROM auftrag a LEFT JOIN kunden k ON k.id=a.kunde_id LEFT JOIN produkt p ON p.id=a.produkt_id
             WHERE a.status IN ('erledigt','versendet')
             ORDER BY a.status='versendet', a.angelegt DESC");
if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($rows, function($r) use ($needle) {
        foreach (['nummer','kunde_firma','produkt_name'] as $f) if (mb_strpos(mb_strtolower((string)$r[$f]), $needle) !== false) return true;
        return false;
    });
}

render_header('versand', 'Versand');
bx_head('Versand', count($rows) . ' Aufträge');
if (isset($_GET['ok'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">An den Kunden versendet – Fertigware ausgebucht, Lieferschein erstellt.</div>';
if (isset($_GET['l2'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">An Lager 2 (Fremdlager) übergeben – der Bestand bleibt dort, bis der Endkunde bestellt.</div>';
if (isset($_GET['fehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b">' . h($_GET['fehler']) . '</div>';
?>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="versand">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Nummer, Kunde, Produkt …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
</form>
<div class="bx-tablewrap"><table class="bx-table">
  <thead><tr><th>Auftrag</th><th>Kunde</th><th>Produkt</th><th class="bx-num">Menge</th><th class="bx-num">Fertig im Lager</th><th>Lieferschein</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="8" class="muted">Keine versandbereiten Aufträge. Ein Auftrag wird versandbereit, wenn die Produktion abgeschlossen ist.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r):
      $versendet = $r['status'] === 'versendet';
      $genug = (float)$r['fertig_frei'] + 0.0001 >= (float)$r['menge'];
      $ff = (int)$r['fulfillment'] === 1;   // Fulfillment: die Ware bleibt bei uns im Fremdlager
  ?>
    <tr>
      <td><?= h($r['nummer']) ?></td>
      <td><?= kunde_link($r['kunde_id'] ?? null, $r['kunde_firma']) ?></td>
      <td><?= $r['produkt_name'] ? h($r['produkt_name']) : '<span class="muted">–</span>' ?></td>
      <td class="bx-num"><?= (int)$r['menge'] ?></td>
      <td class="bx-num"><?= (int)$r['fertig_frei'] ?></td>
      <td><?= $r['lieferschein_nr'] ? h($r['lieferschein_nr']) : '<span class="muted">–</span>' ?></td>
      <td><?= $versendet ? bx_badge('abgeschlossen','ok') : bx_badge('versandbereit','info') ?></td>
      <td style="text-align:right">
        <?php if (!$versendet): ?>
          <?php if ($genug): ?>
            <div class="bx-row" style="gap:6px;justify-content:flex-end;flex-wrap:wrap">
              <form method="post" style="margin:0"><input type="hidden" name="aktion" value="versenden"><input type="hidden" name="auftrag_id" value="<?= (int)$r['id'] ?>"><button class="btn btn-primary btn-sm" type="submit" title="An den Kunden ausliefern (Lieferschein, Fertigware ausbuchen)">An Kunden senden</button></form>
              <?php if ($ff): ?>
              <form method="post" style="margin:0"><input type="hidden" name="aktion" value="an_lager2"><input type="hidden" name="auftrag_id" value="<?= (int)$r['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit" title="An das Fremdlager (Lager 2) übergeben – Bestand bleibt bis zur Endkundenbestellung">An Lager 2 übergeben</button></form>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <span class="badge badge-warn">zu wenig Fertigware</span>
          <?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php render_footer(); ?>
