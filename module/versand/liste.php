<?php
// Versand – versandbereite Aufträge versenden (Fertigware ausbuchen + Lieferschein)
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$hinweis = ''; $fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'versenden') {
    $res = auftrag_versenden((int)($_POST['auftrag_id'] ?? 0));
    header('Location: ?p=versand&' . ($res['ok'] ? 'ok=1' : 'fehler=' . urlencode($res['msg']))); exit;
}

$q    = trim($_GET['q'] ?? '');
$rows = all("SELECT a.*, k.firma AS kunde_firma, p.name AS produkt_name,
             (SELECT COALESCE(SUM(c.menge_verfuegbar),0) FROM item i JOIN charge c ON c.item_id=i.id
              WHERE i.produkt_id=a.produkt_id AND i.kategorie='verkaufsfertig' AND c.status='frei') AS fertig_frei,
             (SELECT nummer FROM beleg b WHERE b.auftrag_id=a.id AND b.typ='lieferschein' LIMIT 1) AS lieferschein_nr
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
if (isset($_GET['ok'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Auftrag versendet – Fertigware ausgebucht, Lieferschein erstellt.</div>';
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
  ?>
    <tr>
      <td><?= h($r['nummer']) ?></td>
      <td><?= $r['kunde_firma'] ? h($r['kunde_firma']) : '<span class="muted">–</span>' ?></td>
      <td><?= $r['produkt_name'] ? h($r['produkt_name']) : '<span class="muted">–</span>' ?></td>
      <td class="bx-num"><?= (int)$r['menge'] ?></td>
      <td class="bx-num"><?= (int)$r['fertig_frei'] ?></td>
      <td><?= $r['lieferschein_nr'] ? h($r['lieferschein_nr']) : '<span class="muted">–</span>' ?></td>
      <td><?= $versendet ? bx_badge('versendet','ok') : bx_badge('versandbereit','info') ?></td>
      <td style="text-align:right">
        <?php if (!$versendet): ?>
          <?php if ($genug): ?>
            <form method="post" style="display:inline"><input type="hidden" name="aktion" value="versenden"><input type="hidden" name="auftrag_id" value="<?= (int)$r['id'] ?>"><button class="btn btn-primary btn-sm" type="submit">Versenden</button></form>
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
