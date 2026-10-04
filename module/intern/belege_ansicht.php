<?php
// Rechnungen – NUR-ANSICHT im Dashboard. Route: ?p=rechnungen_ansicht
// Die Buchhaltung ist ein eigenes, abgeschottetes Programm (/buchhaltung/). Das Dashboard soll aber
// schnell einen Blick auf die Rechnungen werfen koennen, OHNE sich dort anzumelden. Da beide auf
// dieselbe Tabelle `beleg` (gleiche DB) zugreifen, lesen wir hier einfach mit – rein lesend, keine
// Bearbeitung/Zahlung/Export (das bleibt in der Buchhaltung).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$q = trim((string)($_GET['q'] ?? ''));
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
$rows = all("SELECT b.id, b.nummer, b.typ, b.datum, b.netto, b.brutto, b.status, k.firma
             FROM beleg b LEFT JOIN kunden k ON k.id = b.kunde_id
             WHERE $where ORDER BY b.datum DESC, b.id DESC LIMIT 500", $args);

$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
$dat = fn($d) => $d ? date('d.m.Y', strtotime((string)$d)) : '–';

render_header('rechnungen_ansicht', 'Rechnungen (Ansicht)');
bx_head('Rechnungen', 'Nur-Ansicht – Bearbeiten, Zahlungen und Export laufen in der Buchhaltung',
        bx_btn('Zur Buchhaltung', 'buchhaltung/', 'ghost'));
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
          <td><?= bx_badge($statusLbl[$st] ?? $st, $statusKind[$st] ?? '') ?></td>
          <td style="white-space:nowrap"><a class="btn btn-ghost btn-sm" href="buchhaltung/?p=rechnung&id=<?= (int)$b['id'] ?>" title="In der Buchhaltung öffnen (eigener Login)">öffnen</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if (count($rows) >= 500): ?><p class="muted" style="font-size:12px;margin-top:8px">Nur die neuesten 500 – mit der Suche eingrenzen.</p><?php endif; ?>
  <?php endif; ?>
</div>
<?php render_footer();
