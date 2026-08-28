<?php
// Portal-Anfragen (Produkt / Rohstoff / Dienstleistung) – interne Eingangsliste
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

// Status setzen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'status') {
    $aid = (int)($_POST['id'] ?? 0);
    $st  = in_array($_POST['status'] ?? '', ['neu','in_bearbeitung','beantwortet','abgelehnt'], true) ? $_POST['status'] : 'neu';
    if ($aid) q("UPDATE portal_anfrage SET status=? WHERE id=?", [$st, $aid]);
    header('Location: ?p=portal_anfragen&ok=1'); exit;
}

$TYP = ['produkt'=>'Produkt', 'rohstoff'=>'Rohstoff', 'dienstleistung'=>'Dienstleistung'];
$filterTyp = $_GET['typ'] ?? 'alle';

$where = $filterTyp !== 'alle' && isset($TYP[$filterTyp]) ? "WHERE pa.typ=" . "'" . $filterTyp . "'" : '';
$rows = all("SELECT pa.*, k.firma, p.name AS produkt_name
             FROM portal_anfrage pa
             LEFT JOIN kunden k ON k.id=pa.kunde_id
             LEFT JOIN produkt p ON p.id=pa.produkt_id
             $where ORDER BY (pa.status='neu') DESC, pa.angelegt DESC");
$VTYPEN = ['glas'=>'Glas', 'pet'=>'PET-Dose', 'pla'=>'PLA-Becher', 'beutel'=>'Standbodenbeutel', 'stick'=>'Stick', 'blister'=>'Blister'];

$stBadge = fn($s) => match ($s) { 'neu'=>bx_badge('neu','info'),'in_bearbeitung'=>bx_badge('in Bearbeitung','warn'),'beantwortet'=>bx_badge('Angebot abgegeben','ok'),'abgelehnt'=>bx_badge('abgelehnt','err'),default=>bx_badge($s) };

render_header('portal_anfragen', 'Portal-Anfragen');
bx_head('Portal-Anfragen', count($rows) . ' Anfragen', '');
if (isset($_GET['ok'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Aktualisiert.</div>';
?>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="portal_anfragen">
  <select name="typ" onchange="this.form.submit()">
    <option value="alle" <?= $filterTyp==='alle'?'selected':'' ?>>Alle Typen</option>
    <?php foreach ($TYP as $key=>$lbl): ?><option value="<?= $key ?>" <?= $filterTyp===$key?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?>
  </select>
</form>
<div class="bx-tablewrap"><table class="bx-table">
  <thead><tr><th>Nr.</th><th>Kunde</th><th>Typ</th><th>Anfrage</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="6" class="muted">Keine Portal-Anfragen.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r):
      if ($r['typ'] === 'produkt') {
          $groesse = $r['fuellmenge_g'] ? rtrim(rtrim(number_format((float)$r['fuellmenge_g'],1,',','.'),'0'),',') . ' g/Pkg'
                   : ($r['stueck'] ? (int)$r['stueck'] . ' Stk/Pkg' : '');
          $txt = ($r['produkt_name'] ?: '–')
               . ($groesse ? ' · ' . $groesse : '')
               . ($r['verpackung_typ'] ? ' · ' . ($VTYPEN[$r['verpackung_typ']] ?? $r['verpackung_typ']) : '')
               . ($r['menge'] ? ' · ' . (int)$r['menge'] . ' Pkg' : '');
      } else {
          $txt = ($r['betreff'] ?: '–')
               . ($r['wunsch_menge'] ? ' · ' . rtrim(rtrim(number_format((float)$r['wunsch_menge'],3,',','.'),'0'),',') . ' ' . ($r['wunsch_einheit'] ?: '') : '');
      }
  ?>
    <tr style="cursor:pointer" onclick="location.href='?p=portal_anfrage&id=<?= (int)$r['id'] ?>'">
      <td><a href="?p=portal_anfrage&id=<?= (int)$r['id'] ?>"><?= h($r['nummer']) ?></a></td>
      <td><?= h($r['firma'] ?: '–') ?></td>
      <td><?= h($TYP[$r['typ']] ?? $r['typ']) ?></td>
      <td><?= h($txt) ?><?php if ($r['notiz']): ?><div class="muted" style="font-size:12px"><?= h($r['notiz']) ?></div><?php endif; ?></td>
      <td><?= $stBadge($r['status']) ?></td>
      <td style="text-align:right"><a class="btn btn-ghost btn-sm" href="?p=portal_anfrage&id=<?= (int)$r['id'] ?>">öffnen</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php render_footer(); ?>
