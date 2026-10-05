<?php
// Warenausgang / Versand – Liste aller geplanten und versendeten Sendungen. "Neue Sendung" legt
// einen Entwurf an und springt in die Detailplanung (?p=versand_detail).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'neu') {
    $id = lg_versand_anlegen(['empf_land' => 'DE']);
    weiter('?p=versand_detail&id=' . (int)$id);
}

$filter = (string)($_GET['status'] ?? '');
if (!in_array($filter, ['', 'geplant', 'versendet', 'storniert'], true)) $filter = '';
$zeilen = lg_versand_liste($filter, 200);

kopf('Warenausgang', 'versand');
seitenkopf('Warenausgang', 'Sendungen planen, Lieferscheine und Versand-Etiketten drucken.',
    '<form method="post" style="display:inline"><input type="hidden" name="aktion" value="neu"><button class="btn btn-primary" type="submit">+ Neue Sendung</button></form> '
    . '<a class="btn btn-ghost" href="?p=ausgang">Schnell abbuchen</a>');
flash_zeigen();

$statusBadge = function (string $s): string {
    $m = ['geplant' => ['Geplant', 'warn'], 'versendet' => ['Versendet', 'ok'], 'storniert' => ['Storniert', 'muted']];
    [$t, $k] = $m[$s] ?? [$s, 'muted'];
    return '<span class="bx-badge bx-badge-' . $k . '">' . h($t) . '</span>';
};
?>
<div class="lg-reiter" style="margin-bottom:var(--sp-4)">
  <a href="?p=versand" class="<?= $filter === '' ? 'an' : '' ?>">Alle</a>
  <a href="?p=versand&status=geplant" class="<?= $filter === 'geplant' ? 'an' : '' ?>">Geplant</a>
  <a href="?p=versand&status=versendet" class="<?= $filter === 'versendet' ? 'an' : '' ?>">Versendet</a>
  <a href="?p=versand&status=storniert" class="<?= $filter === 'storniert' ? 'an' : '' ?>">Storniert</a>
</div>

<?php if (!$zeilen): ?>
  <div class="bx-panel muted">Noch keine Sendungen. Oben „+ Neue Sendung" anlegen.</div>
<?php else: ?>
<div class="bx-tablewrap">
  <table class="bx-table lg-karten">
    <thead><tr><th>Nummer</th><th>Empfänger</th><th>Versandart</th><th>Status</th><th>Sendungsnr.</th><th>Angelegt</th></tr></thead>
    <tbody>
    <?php foreach ($zeilen as $v):
      $empf = trim((string)$v['empf_firma']) !== '' ? (string)$v['empf_firma'] : (trim((string)$v['empf_ort']) !== '' ? (string)$v['empf_ort'] : '–'); ?>
      <tr onclick="location.href='?p=versand_detail&id=<?= (int)$v['id'] ?>'" style="cursor:pointer">
        <td data-label=""><a href="?p=versand_detail&id=<?= (int)$v['id'] ?>" class="lg-namelink lg-code" onclick="event.stopPropagation()"><?= h((string)$v['nummer']) ?></a></td>
        <td data-label="Empfänger"><?= h($empf) ?><?= trim((string)$v['empf_land']) !== '' && strtoupper((string)$v['empf_land']) !== 'DE' ? ' <span class="muted">· ' . h(strtoupper((string)$v['empf_land'])) . '</span>' : '' ?></td>
        <td data-label="Versandart"><?= (string)$v['typ'] === 'palette' ? 'Palette / Fracht' : 'Paket' ?></td>
        <td data-label="Status"><?= $statusBadge((string)$v['status']) ?></td>
        <td data-label="Sendungsnr." class="lg-code"><?= h((string)($v['tracking'] ?? '')) ?: '<span class="muted">–</span>' ?></td>
        <td data-label="Angelegt" class="muted"><?= h(fmt_zeit((string)$v['angelegt'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php fuss();
