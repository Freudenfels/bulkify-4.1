<?php
// EK-Preise (Import): eingelesene EK-Preislisten aus CSV – Rohstoff/Bulk-EK je kg und interne
// Fertigprodukt-Kapselpreise, je mit Lieferant. Durchsuchbar; Zuordnung zu v4-Rohstoffen/Produkten
// (item_id/produkt_id) erfolgt nachgelagert (manuell/KI). Fertigprodukt-Preise sind INTERN
// (Zukauf – Kunde sieht das nie).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$typ = ($_GET['typ'] ?? 'rohstoff') === 'fertigprodukt' ? 'fertigprodukt' : 'rohstoff';
$q   = trim((string)($_GET['q'] ?? ''));
$nurOffen = isset($_GET['offen']);

$where = "WHERE typ=?"; $args = [$typ];
if ($q !== '') { $where .= " AND (name LIKE ? OR lieferant LIKE ? OR formulierung LIKE ?)"; array_push($args, "%$q%", "%$q%", "%$q%"); }
if ($nurOffen) $where .= " AND status='offen' AND item_id IS NULL AND produkt_id IS NULL";

$rows = all("SELECT e.*, i.name AS item_name, p.name AS produkt_name
             FROM ek_import e LEFT JOIN item i ON i.id=e.item_id LEFT JOIN produkt p ON p.id=e.produkt_id
             $where ORDER BY e.name LIMIT 1500", $args);
$anzRoh    = (int) scalar("SELECT COUNT(*) FROM ek_import WHERE typ='rohstoff'");
$anzFertig = (int) scalar("SELECT COUNT(*) FROM ek_import WHERE typ='fertigprodukt'");
$anzZugeordnet = (int) scalar("SELECT COUNT(*) FROM ek_import WHERE typ=? AND (item_id IS NOT NULL OR produkt_id IS NOT NULL)", [$typ]);

$preisFmt = function ($e) {
    $p = (float)$e['preis'];
    $eh = $e['einheit'] === 'kg' ? '/kg' : ($e['einheit'] === 'kapsel' ? '/Kapsel' : '/' . $e['einheit']);
    return number_format($p, $p < 1 ? 4 : 2, ',', '.') . ' €' . $eh;
};
$zuordnung = function ($e) {
    if ($e['item_name'])    return '<a class="kundenlink" href="?p=rohstoff&id=' . (int)$e['item_id'] . '">' . h($e['item_name']) . '</a>';
    if ($e['produkt_name']) return '<a class="kundenlink" href="?p=produkt&id=' . (int)$e['produkt_id'] . '">' . h($e['produkt_name']) . '</a>';
    return '<span class="muted">– offen –</span>';
};

render_header('ek_import', 'EK-Preise (Import)');
bx_head('EK-Preise (Import)', 'aus CSV eingelesene Einkaufspreise mit Lieferant', bx_btn('Zurück', '?p=lief_preisliste', 'ghost'));
?>
<form method="get" class="bx-listbar">
  <input type="hidden" name="p" value="ek_import">
  <select name="typ" onchange="this.form.submit()">
    <option value="rohstoff" <?= $typ==='rohstoff'?'selected':'' ?>>Rohstoffe / Bulk (<?= $anzRoh ?>)</option>
    <option value="fertigprodukt" <?= $typ==='fertigprodukt'?'selected':'' ?>>Fertigprodukte – intern (<?= $anzFertig ?>)</option>
  </select>
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Name, Lieferant, Formulierung …">
  <label class="bx-check" style="display:inline-flex;align-items:center;gap:6px;margin:0 4px">
    <input type="checkbox" name="offen" value="1" onchange="this.form.submit()" <?= $nurOffen?'checked':'' ?>> nur nicht zugeordnete
  </label>
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q!==''||$nurOffen): ?><a class="btn btn-ghost btn-sm" href="?p=ek_import&typ=<?= $typ ?>">zurücksetzen</a><?php endif; ?>
</form>

<div class="muted" style="margin:6px 2px 12px;font-size:13px">
  <?= count($rows) ?> angezeigt · <?= $anzZugeordnet ?> von <?= $typ==='rohstoff'?$anzRoh:$anzFertig ?> bereits einem <?= $typ==='rohstoff'?'Rohstoff':'Produkt' ?> zugeordnet.
  <?php if ($typ==='fertigprodukt'): ?><br>Interne Zukauf-Preise – erscheinen nie in der Kundensicht.<?php endif; ?>
</div>

<div class="bx-panel">
<?php if (!$rows): ?>
  <div class="muted"><?= $q!==''||$nurOffen ? 'Keine Treffer.' : 'Noch nichts importiert – tools/ek_import.php ausführen.' ?></div>
<?php elseif ($typ==='rohstoff'): ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Name (CSV)</th><th>Lieferant</th><th class="bx-num">EK</th><th>Zuordnung → Rohstoff</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $e): ?>
      <tr>
        <td><?= h($e['name']) ?></td>
        <td><?= $e['lieferant'] ? h($e['lieferant']) : '<span class="muted">–</span>' ?></td>
        <td class="bx-num"><?= $preisFmt($e) ?></td>
        <td><?= $zuordnung($e) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Produkt (CSV)</th><th>Größe</th><th>Formulierung</th><th class="bx-num">Kapsel-EK</th><th class="bx-num">Menge</th><th>Lieferant</th><th>Zuordnung → Produkt</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $e): $f = trim((string)$e['formulierung']); ?>
      <tr>
        <td><?= h($e['name']) ?></td>
        <td class="muted"><?= $e['groesse'] ? h($e['groesse']) : '–' ?></td>
        <td class="muted" style="font-size:12px;max-width:320px"><?= $f !== '' ? h(mb_strlen($f) > 90 ? mb_substr($f,0,90).'…' : $f) : '–' ?></td>
        <td class="bx-num"><?= $preisFmt($e) ?></td>
        <td class="bx-num"><?= $e['menge'] !== null ? number_format((float)$e['menge'],0,',','.') : '<span class="muted">–</span>' ?></td>
        <td><?= $e['lieferant'] ? h($e['lieferant']) : '<span class="muted">–</span>' ?></td>
        <td><?= $zuordnung($e) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
  <?php if (count($rows) >= 1500): ?><p class="muted" style="font-size:12px;margin-top:8px">Nur die ersten 1.500 – Suche eingrenzen.</p><?php endif; ?>
</div>

<div class="bx-panel muted" style="font-size:13px;line-height:1.6">
  <strong style="font-weight:600">Nächster Schritt – Zuordnung:</strong> Diese Rohnamen den v4-Rohstoffen/Produkten zuordnen (dann füllt sich „Preis ab" und der Lieferanten-Marker automatisch).
  Nur ein kleiner Teil trägt den Originalnamen, daher kommt die <em>KI-Zuordnung</em> (läuft auf beta): die KI schlägt je Zeile den passenden Rohstoff vor, bestätigt wird von Hand.
</div>
<?php
render_footer();
