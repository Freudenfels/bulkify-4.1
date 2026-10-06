<?php
// DL-Rechnungen (Liste) – eigener Nummernkreis DR-. Verwaltung (bezahlt/mahnen) in der Buchhaltung.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/dienstleistung.php';

// Rechnung ZURÜCKZIEHEN / WIEDER SICHTBAR (nur Kundensichtbarkeit beleg.kunde_sichtbar) – für fehlerhafte
// DL-Rechnungen, die der Kunde nicht sehen soll. Intern/Buchhaltung bleibt erhalten. Nur Admin/Finance/Sales.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'sichtbar_setzen') {
    if (!(has_role('admin') || has_role('finance') || has_role('sales'))) { header('Location: ?p=dl_rechnungen&fehler=1'); exit; }
    $bid = (int)($_POST['beleg_id'] ?? 0);
    $sicht = (($_POST['sichtbar'] ?? '') === '1') ? 1 : 0;
    if ($bid) {
        q("UPDATE beleg SET kunde_sichtbar=? WHERE id=? AND kategorie='dienstleistung' AND typ='rechnung'", [$sicht, $bid]);
        $wer = (function_exists('current_user') && ($cu = current_user())) ? (string)($cu['name'] ?? '') : '';
        $kid = (int) scalar("SELECT kunde_id FROM beleg WHERE id=?", [$bid]);
        $nr  = (string) scalar("SELECT nummer FROM beleg WHERE id=?", [$bid]);
        if ($kid && function_exists('log_aktivitaet'))
            log_aktivitaet('kunde', $kid, 'team', 'DL-Rechnung ' . $nr . ($sicht ? ' wieder für den Kunden sichtbar gemacht' : ' zurückgezogen (für den Kunden ausgeblendet)') . ($wer !== '' ? ' – ' . $wer : '') . '.', 'beleg', 'beleg', $bid);
    }
    header('Location: ?p=dl_rechnungen&done=' . ($sicht ? 'frei' : 'zur')); exit;
}
$darfZiehen = has_role('admin') || has_role('finance') || has_role('sales');

$rows = dl_rechnungen_alle();
$statusBadge = fn($s) => match ($s) {
    'offen'=>bx_badge('offen','info'), 'bezahlt'=>bx_badge('bezahlt','ok'),
    'storniert'=>bx_badge('storniert','err'), default=>bx_badge(status_text($s)) };

render_header('dienstleistungen', 'DL-Rechnungen');
bx_head('Dienstleistungs-Rechnungen', count($rows) . ' Rechnungen', bx_hint('Eigener Nummernkreis DR-… – getrennt von den Produkt-Rechnungen (RE-…). Bezahlung/Mahnung in der Buchhaltung.'));
dl_subtabs('dl_rechnungen');
if (isset($_GET['done'])) echo '<div class="bx-panel badge-ok" style="padding:10px 14px">' . ($_GET['done'] === 'zur' ? 'Rechnung zurückgezogen – der Kunde sieht sie nicht mehr.' : 'Rechnung wieder für den Kunden sichtbar.') . '</div>';
if (isset($_GET['fehler'])) echo '<div class="bx-panel badge-err" style="padding:10px 14px">Keine Berechtigung.</div>';
?>
<div class="bx-panel">
  <?php if (!$rows): ?><div class="muted">Noch keine Dienstleistungs-Rechnungen. Entstehen aus einem DL-Auftrag.</div>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Nummer</th><th>Datum</th><th>Kunde</th><th>aus Auftrag</th><th class="bx-num">Netto</th><th class="bx-num">Brutto</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="/buchhaltung/?p=rechnung&id=<?= (int)$r['id'] ?>"><strong><?= h($r['nummer'] ?: '–') ?></strong></a></td>
        <td class="muted"><?= $r['datum'] ? h(date('d.m.Y', strtotime((string)$r['datum']))) : '–' ?></td>
        <td><?= kunde_link($r['kunde_id'] ?? null, $r['kunde_firma']) ?></td>
        <td class="muted"><?= h($r['auftrag_nummer'] ?: '–') ?></td>
        <td class="bx-num"><?= number_format((float)$r['netto'],2,',','.') ?> €</td>
        <td class="bx-num"><?= number_format((float)$r['brutto'],2,',','.') ?> €</td>
        <td><?= $statusBadge($r['status']) ?><?php if ((int)($r['kunde_sichtbar'] ?? 1) === 0): ?> <?= bx_badge('zurückgezogen','err') ?><?php endif; ?></td>
        <td class="bx-num" style="white-space:nowrap">
          <?php if ($darfZiehen): $sichtbar = (int)($r['kunde_sichtbar'] ?? 1) === 1; ?>
            <form method="post" style="display:inline" onsubmit="return confirm('<?= $sichtbar ? 'Diese Rechnung zurückziehen? Der Kunde sieht sie dann nicht mehr.' : 'Diese Rechnung wieder für den Kunden sichtbar machen?' ?>');">
              <input type="hidden" name="aktion" value="sichtbar_setzen"><input type="hidden" name="beleg_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="sichtbar" value="<?= $sichtbar ? '0' : '1' ?>">
              <button class="btn btn-ghost btn-sm" type="submit"><?= $sichtbar ? 'Zurückziehen' : 'Wieder sichtbar' ?></button>
            </form>
          <?php endif; ?>
          <?= pdf_btn('/buchhaltung/?p=rechnung_pdf&id=' . (int)$r['id'], 'PDF', false, 'Rechnung als PDF') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php render_footer();
