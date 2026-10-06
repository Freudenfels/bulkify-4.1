<?php
// Rechnungen – NUR-ANSICHT im Dashboard. Route: ?p=rechnungen_ansicht
// Die Buchhaltung ist ein eigenes, abgeschottetes Programm (/buchhaltung/). Das Dashboard soll aber
// schnell einen Blick auf die Rechnungen werfen koennen, OHNE sich dort anzumelden. Da beide auf
// dieselbe Tabelle `beleg` (gleiche DB) zugreifen, lesen wir hier einfach mit – rein lesend, keine
// Bearbeitung/Zahlung/Export (das bleibt in der Buchhaltung).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$q = trim((string)($_GET['q'] ?? ''));

// Rechnung ZURÜCKZIEHEN / WIEDER SICHTBAR machen: schaltet nur die Kundensichtbarkeit (beleg.kunde_sichtbar)
// – die Rechnung verschwindet sofort aus dem Kundenportal, bleibt aber intern/in der Buchhaltung erhalten.
// Für fehlerhafte Rechnungen, die der Kunde nicht sehen soll. Nur Admin/Finance/Sales.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'sichtbar_setzen') {
    if (!(has_role('admin') || has_role('finance') || has_role('sales'))) { header('Location: ?p=rechnungen_ansicht&fehler=1'); exit; }
    $bid = (int)($_POST['beleg_id'] ?? 0);
    $sicht = (($_POST['sichtbar'] ?? '') === '1') ? 1 : 0;
    if ($bid) {
        q("UPDATE beleg SET kunde_sichtbar=? WHERE id=? AND typ IN ('rechnung','gutschrift')", [$sicht, $bid]);
        $wer = (function_exists('current_user') && ($cu = current_user())) ? (string)($cu['name'] ?? '') : '';
        $kid = (int) scalar("SELECT kunde_id FROM beleg WHERE id=?", [$bid]);
        $nr  = (string) scalar("SELECT nummer FROM beleg WHERE id=?", [$bid]);
        if ($kid && function_exists('log_aktivitaet'))
            log_aktivitaet('kunde', $kid, 'team', 'Rechnung ' . $nr . ($sicht ? ' wieder für den Kunden sichtbar gemacht' : ' zurückgezogen (für den Kunden ausgeblendet)') . ($wer !== '' ? ' – ' . $wer : '') . '.', 'beleg', 'beleg', $bid);
    }
    header('Location: ?p=rechnungen_ansicht' . ($q !== '' ? '&q=' . urlencode($q) : '') . '&done=' . ($sicht ? 'frei' : 'zur')); exit;
}

$typLbl    = ['rechnung' => 'Rechnung', 'gutschrift' => 'Gutschrift', 'lieferschein' => 'Lieferschein'];
$statusLbl = ['offen' => 'offen', 'teilbezahlt' => 'teilbezahlt', 'bezahlt' => 'bezahlt', 'storniert' => 'storniert'];
$statusKind= ['offen' => '', 'teilbezahlt' => '', 'bezahlt' => 'ok', 'storniert' => 'warn'];

$where = "b.typ IN ('rechnung','gutschrift')";
$args  = [];
if ($q !== '') {
    $like = '%' . str_replace('\\', '', $q) . '%';
    $where .= " AND (b.nummer LIKE ? OR k.firma LIKE ?)";
    $args[] = $like; $args[] = $like;
}
$rows = all("SELECT b.id, b.nummer, b.typ, b.datum, b.netto, b.brutto, b.status, b.kunde_sichtbar, k.firma
             FROM beleg b LEFT JOIN kunden k ON k.id = b.kunde_id
             WHERE $where ORDER BY b.datum DESC, b.id DESC LIMIT 500", $args);
$darfZiehen = has_role('admin') || has_role('finance') || has_role('sales');

$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
$dat = fn($d) => $d ? date('d.m.Y', strtotime((string)$d)) : '–';

render_header('rechnungen_ansicht', 'Rechnungen (Ansicht)');
bx_head('Rechnungen', 'Nur-Ansicht – Bearbeiten, Zahlungen und Export laufen in der Buchhaltung',
        bx_btn('Zur Buchhaltung', 'buchhaltung/', 'ghost'));
if (isset($_GET['done'])) echo '<div class="bx-panel badge-ok" style="padding:10px 14px">' . ($_GET['done'] === 'zur' ? 'Rechnung zurückgezogen – der Kunde sieht sie nicht mehr.' : 'Rechnung wieder für den Kunden sichtbar.') . '</div>';
if (isset($_GET['fehler'])) echo '<div class="bx-panel badge-err" style="padding:10px 14px">Keine Berechtigung.</div>';
?>
<div class="bx-panel" style="padding:10px 14px;color:var(--muted)">
  Schneller Überblick aus dem Dashboard. Für Erstellen/Zahlungen/GoBD-Export bitte die
  <a href="buchhaltung/">Buchhaltung</a> öffnen.
</div>

<form method="get" class="bx-listbar">
  <input type="hidden" name="p" value="rechnungen_ansicht">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Nummer oder Kunde …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=rechnungen_ansicht">zurücksetzen</a><?php endif; ?>
</form>

<div class="bx-panel">
  <?php if (!$rows): ?>
    <div class="muted"><?= $q !== '' ? 'Keine Treffer.' : 'Noch keine Rechnungen.' ?></div>
  <?php else: ?>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr>
        <th>Nummer</th><th>Datum</th><th>Kunde</th><th>Typ</th>
        <th class="bx-num">Netto</th><th class="bx-num">Brutto</th><th>Status</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $b): $st = (string)$b['status']; ?>
        <tr>
          <td><?= h((string)($b['nummer'] ?: '–')) ?></td>
          <td style="white-space:nowrap"><?= $dat($b['datum']) ?></td>
          <td style="max-width:260px;overflow-wrap:anywhere"><?= h((string)($b['firma'] ?: '–')) ?></td>
          <td><?= h($typLbl[(string)$b['typ']] ?? (string)$b['typ']) ?></td>
          <td class="bx-num"><?= $eur($b['netto']) ?></td>
          <td class="bx-num"><?= $eur($b['brutto']) ?></td>
          <td><?= bx_badge($statusLbl[$st] ?? $st, $statusKind[$st] ?? '') ?><?php if ((int)($b['kunde_sichtbar'] ?? 1) === 0): ?> <?= bx_badge('zurückgezogen', 'err') ?><?php endif; ?></td>
          <td style="white-space:nowrap">
            <?php if ($darfZiehen): $sichtbar = (int)($b['kunde_sichtbar'] ?? 1) === 1; ?>
              <form method="post" style="display:inline" onsubmit="return confirm('<?= $sichtbar ? 'Diese Rechnung zurückziehen? Der Kunde sieht sie dann nicht mehr.' : 'Diese Rechnung wieder für den Kunden sichtbar machen?' ?>');">
                <input type="hidden" name="aktion" value="sichtbar_setzen"><input type="hidden" name="beleg_id" value="<?= (int)$b['id'] ?>">
                <input type="hidden" name="sichtbar" value="<?= $sichtbar ? '0' : '1' ?>">
                <button class="btn btn-ghost btn-sm" type="submit"><?= $sichtbar ? 'Zurückziehen' : 'Wieder sichtbar' ?></button>
              </form>
            <?php endif; ?>
            <a class="btn btn-ghost btn-sm" href="buchhaltung/?p=rechnung&id=<?= (int)$b['id'] ?>" title="In der Buchhaltung öffnen (eigener Login)">öffnen</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if (count($rows) >= 500): ?><p class="muted" style="font-size:12px;margin-top:8px">Nur die neuesten 500 – mit der Suche eingrenzen.</p><?php endif; ?>
  <?php endif; ?>
</div>
<?php render_footer();
