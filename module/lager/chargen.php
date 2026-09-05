<?php
// Chargenverfolgung – Übersicht aller Chargen + Rückverfolgung (Rohstoff ↔ Produkt) über produktion_verbrauch.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$chId = (int)($_GET['id'] ?? 0);

$chBadge = fn($s) => match ($s) {
    'frei'        => bx_badge('frei','ok'),
    'quarantaene' => bx_badge('Quarantäne','warn'),
    'leer'        => bx_badge('leer'),
    default       => bx_badge($s),
};
$katLbl = ['rohstoff'=>'Rohstoff','verpackung'=>'Verpackung','verkaufsfertig'=>'Fertigware'];
$num = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');

// ---------- Detail ----------
if ($chId) {
    $c = one("SELECT c.*, i.name AS item_name, i.kategorie AS kategorie, l.firma AS lieferant_firma,
                     a.nummer AS auftrag_nr, ak.firma AS auftrag_kunde
              FROM charge c LEFT JOIN item i ON i.id=c.item_id LEFT JOIN lieferanten l ON l.id=c.lieferant_id
              LEFT JOIN auftrag a ON a.id=c.auftrag_id LEFT JOIN kunden ak ON ak.id=a.kunde_id
              WHERE c.id=?", [$chId]);
    if (!$c) { render_header('chargen','Charge'); bx_head('Charge nicht gefunden','', bx_btn('Zurück','?p=chargen','ghost')); render_footer(); exit; }

    // Wohin ging diese Charge? (nur Rohstoff/Verpackung sinnvoll) – aus produktion_verbrauch
    $verwendet = all("SELECT v.menge, v.einheit, pa.id AS pa_id, pa.nummer AS pa_nr, pa.status AS pa_status,
                             p.name AS produkt_name, pa.kunde_id, k.firma AS kunde_firma
                      FROM produktion_verbrauch v
                      JOIN produktionsauftrag pa ON pa.id=v.pa_id
                      LEFT JOIN produkt p ON p.id=pa.produkt_id
                      LEFT JOIN kunden k ON k.id=pa.kunde_id
                      WHERE v.charge_id=? ORDER BY v.id", [$chId]);

    // Woraus besteht diese (Fertigwaren-)Charge? Über pa_id; Fallback für alte Chargen: charge_nr = PR-Nummer.
    $bestandteile = [];
    $pa = null;
    if (!empty($c['pa_id'])) $pa = one("SELECT id, nummer FROM produktionsauftrag WHERE id=?", [(int)$c['pa_id']]);
    if (!$pa) $pa = one("SELECT id, nummer FROM produktionsauftrag WHERE nummer=?", [$c['charge_nr']]);
    if ($pa) {
        $bestandteile = all("SELECT v.menge, v.einheit, i.name AS item_name, c2.id AS charge_id, c2.charge_nr, c2.mhd
                             FROM produktion_verbrauch v
                             LEFT JOIN item i ON i.id=v.item_id
                             LEFT JOIN charge c2 ON c2.id=v.charge_id
                             WHERE v.pa_id=? ORDER BY v.id", [(int)$pa['id']]);
    }

    render_header('chargen', 'Charge ' . ($c['charge_nr'] ?: $chId));
    bx_head('Charge ' . h($c['charge_nr'] ?: ('#' . $chId)), h(($katLbl[$c['kategorie']] ?? $c['kategorie']) . ' · ' . $c['item_name']),
            bx_btn('Zurück zur Übersicht', '?p=chargen', 'ghost'));

    echo '<div class="bx-cards">';
    echo '<div class="bx-card"><div class="k">Status</div><div class="v">' . $chBadge($c['status']) . '</div></div>';
    echo '<div class="bx-card"><div class="k">Menge (verfügbar)</div><div class="v">' . $num($c['menge_verfuegbar']) . ' ' . h($c['einheit']) . '</div></div>';
    echo '<div class="bx-card"><div class="k">Ursprung</div><div class="v">' . $num($c['menge']) . ' ' . h($c['einheit']) . '</div></div>';
    echo '<div class="bx-card"><div class="k">MHD</div><div class="v">' . ($c['mhd'] ? h(date('d.m.Y', strtotime($c['mhd']))) : '–') . '</div></div>';
    echo '</div>';
    ?>
    <div class="bx-panel">
      <h2>Herkunft</h2>
      <div class="bx-grid">
        <div><div class="k muted">Artikel</div><div><?= h($c['item_name']) ?></div></div>
        <div><div class="k muted">Lieferant</div><div><?= $c['lieferant_firma'] ? h($c['lieferant_firma']) : '<span class="muted">–</span>' ?></div></div>
        <div><div class="k muted">Wareneingang</div><div><?= $c['wareneingang'] ? h(fmt_zeit($c['wareneingang'], 'd.m.Y')) : '<span class="muted">–</span>' ?></div></div>
        <div><div class="k muted">Für Auftrag</div><div><?php if ($c['auftrag_id']): ?><a href="?p=auftrag&id=<?= (int)$c['auftrag_id'] ?>"><?= h($c['auftrag_nr']) ?></a><?= $c['auftrag_kunde'] ? ' · ' . h($c['auftrag_kunde']) : '' ?><?php else: ?><span class="muted">Lager / allgemein</span><?php endif; ?></div></div>
      </div>
      <?php if ($c['notiz']): ?><div style="margin-top:10px"><div class="k muted">Notiz</div><div><?= h($c['notiz']) ?></div></div><?php endif; ?>
    </div>

    <?php if ($bestandteile): ?>
    <div class="bx-panel">
      <h2>Zusammensetzung (eingesetzte Chargen)</h2>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Rohstoff</th><th>Charge</th><th class="bx-num">Menge</th><th>MHD</th></tr></thead>
        <tbody>
          <?php foreach ($bestandteile as $b): ?>
            <tr>
              <td><?= h($b['item_name']) ?></td>
              <td><?php if ($b['charge_id']): ?><a href="?p=chargen&id=<?= (int)$b['charge_id'] ?>"><?= h($b['charge_nr']) ?></a><?php else: ?><span class="muted">–</span><?php endif; ?></td>
              <td class="bx-num"><?= $num($b['menge']) ?> <?= h($b['einheit']) ?></td>
              <td><?= $b['mhd'] ? h(date('d.m.Y', strtotime($b['mhd']))) : '<span class="muted">–</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php endif; ?>

    <div class="bx-panel">
      <h2>Verwendet in Produktionen</h2>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Produktionsauftrag</th><th>Produkt</th><th>Kunde</th><th class="bx-num">Eingesetzt</th></tr></thead>
        <tbody>
          <?php if (!$verwendet): ?><tr><td colspan="4" class="muted">Diese Charge wurde bisher in keiner Produktion eingesetzt.</td></tr><?php endif; ?>
          <?php foreach ($verwendet as $v): ?>
            <tr>
              <td><a href="?p=produktionsauftrag&id=<?= (int)$v['pa_id'] ?>"><?= h($v['pa_nr'] ?: ('#' . $v['pa_id'])) ?></a></td>
              <td><?= $v['produkt_name'] ? h($v['produkt_name']) : '<span class="muted">–</span>' ?></td>
              <td><?= kunde_link($v['kunde_id'] ?? null, $v['kunde_firma']) ?></td>
              <td class="bx-num"><?= $num($v['menge']) ?> <?= h($v['einheit']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php
    render_footer(); exit;
}

// ---------- Liste ----------
$q = trim($_GET['q'] ?? '');
$where = ''; $params = [];
if ($q !== '') { $where = "WHERE (c.charge_nr LIKE ? OR i.name LIKE ?)"; $like = '%' . str_replace('%', '', $q) . '%'; $params = [$like, $like]; }
$rows = all("SELECT c.*, i.name AS item_name, i.kategorie AS kategorie, l.firma AS lieferant_firma
             FROM charge c LEFT JOIN item i ON i.id=c.item_id LEFT JOIN lieferanten l ON l.id=c.lieferant_id
             $where ORDER BY c.angelegt DESC", $params);

render_header('chargen', 'Chargen');
bx_head('Chargenverfolgung', 'Alle Chargen mit Rückverfolgung Rohstoff ↔ Produkt.');
?>
<form method="get" class="bx-row" style="gap:8px;margin-bottom:16px">
  <input type="hidden" name="p" value="chargen">
  <input type="text" name="q" value="<?= h($q) ?>" placeholder="Charge-Nr. oder Artikel suchen" style="max-width:340px">
  <button class="btn btn-primary" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost" href="?p=chargen">Zurücksetzen</a><?php endif; ?>
</form>
<div class="bx-panel">
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Charge</th><th>Artikel</th><th>Art</th><th class="bx-num">Verfügbar</th><th>MHD</th><th>Lieferant</th><th>Status</th></tr></thead>
    <tbody>
      <?php if (!$rows): ?><tr><td colspan="7" class="muted">Keine Chargen gefunden.</td></tr><?php endif; ?>
      <?php foreach ($rows as $c): ?>
        <tr style="cursor:pointer" onclick="location.href='?p=chargen&id=<?= (int)$c['id'] ?>'">
          <td><?= h($c['charge_nr'] ?: ('#' . $c['id'])) ?></td>
          <td><?= $c['item_name'] ? h($c['item_name']) : '<span class="muted">–</span>' ?></td>
          <td><?= h($katLbl[$c['kategorie']] ?? $c['kategorie']) ?></td>
          <td class="bx-num"><?= $num($c['menge_verfuegbar']) ?> <?= h($c['einheit']) ?></td>
          <td><?= $c['mhd'] ? h(date('d.m.Y', strtotime($c['mhd']))) : '<span class="muted">–</span>' ?></td>
          <td><?= $c['lieferant_firma'] ? h($c['lieferant_firma']) : '<span class="muted">–</span>' ?></td>
          <td><?= $chBadge($c['status']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php render_footer(); ?>
