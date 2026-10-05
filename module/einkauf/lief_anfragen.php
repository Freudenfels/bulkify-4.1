<?php
// Lieferanten-Anfragen & Preise – zentrale Übersicht ALLER an Lieferanten gestellten Anfragen (RFQ)
// inkl. des vom Lieferanten abgegebenen Preises. Route: ?p=lief_anfragen
// Hintergrund: Der Preis einer Fremdfertigungs-Anfrage (auch zu einer noch nicht freigegebenen Rezeptur)
// steht in lieferant_angebot. Bisher nur im Rezeptur-Detail bzw. Lieferant-Detail sichtbar – hier an EINER
// Stelle, mit Preis und Link zur Rezeptur/zum Lieferanten.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$q = trim((string)($_GET['q'] ?? ''));
$f = in_array($_GET['f'] ?? 'alle', ['alle', 'offen', 'beantwortet'], true) ? $_GET['f'] : 'alle';

$ARTLBL = ['rohstoff'=>'Rohstoff','fertigprodukt'=>'Fremdfertigung','verpackung'=>'Verpackung','verbrauch'=>'Verbrauch','sonstiges'=>'Sonstiges'];

$where = '1=1'; $args = [];
if ($f === 'offen')        $where .= " AND ag.id IS NULL";             // noch kein Preis abgegeben
elseif ($f === 'beantwortet') $where .= " AND ag.id IS NOT NULL";      // Preis liegt vor
if ($q !== '') {
    $like = '%' . str_replace('\\', '', $q) . '%';
    $where .= " AND (l.firma LIKE ? OR la.nummer LIKE ? OR la.betreff LIKE ? OR i.name LIKE ? OR rz.name LIKE ?)";
    array_push($args, $like, $like, $like, $like, $like);
}

$rows = all("SELECT la.id, la.nummer, la.art, la.betreff, la.menge, la.einheit, la.status, la.angelegt,
                    la.item_id, la.rezeptur_id, l.firma,
                    ag.preis AS ang_preis, ag.einheit AS ang_einheit, ag.waehrung AS ang_waehrung,
                    ag.mindestmenge AS ang_min, ag.lieferzeit_tage AS ang_lz,
                    i.name AS item_name, i.artikelnummer AS item_nr,
                    rz.name AS rez_name, rz.nummer AS rez_nummer
             FROM lieferant_anfrage la
             LEFT JOIN lieferanten l ON l.id = la.lieferant_id
             LEFT JOIN lieferant_angebot ag ON ag.anfrage_id = la.id
             LEFT JOIN item i ON i.id = la.item_id
             LEFT JOIN rezeptur rz ON rz.id = la.rezeptur_id
             WHERE $where ORDER BY la.angelegt DESC LIMIT 500", $args);

$cAlle = (int) scalar("SELECT COUNT(*) FROM lieferant_anfrage");
$cOffen = (int) scalar("SELECT COUNT(*) FROM lieferant_anfrage la LEFT JOIN lieferant_angebot ag ON ag.anfrage_id=la.id WHERE ag.id IS NULL");
$cBeant = $cAlle - $cOffen;

$eur = fn($x, $w) => rtrim(rtrim(number_format((float)$x, 4, ',', '.'), '0'), ',') . ' ' . h($w ?: 'EUR');
$menge = fn($m, $e) => $m !== null && $m !== '' ? rtrim(rtrim(number_format((float)$m, 3, ',', '.'), '0'), ',') . ($e ? ' ' . h($e) : '') : '–';
$dat = fn($d) => $d ? fmt_zeit($d, 'd.m.Y') : '–';

render_header('lief_anfragen', 'Lieferanten-Anfragen & Preise');
bx_head('Lieferanten-Anfragen & Preise', 'Alle an Lieferanten gestellten Anfragen – mit dem abgegebenen Preis, inkl. Fremdfertigung zu (auch nicht freigegebenen) Rezepturen');
?>
<div class="bx-listbar">
  <?php $tab = function($key,$lbl) use ($f,$q) { $on=$f===$key?' class="on"':''; $href='?p=lief_anfragen&f='.$key.($q!==''?'&q='.urlencode($q):''); return '<a'.$on.' href="'.$href.'" style="margin-right:12px">'.$lbl.'</a>'; }; ?>
  <div><?= $tab('alle','Alle ('.$cAlle.')') ?><?= $tab('offen','Preis ausstehend ('.$cOffen.')') ?><?= $tab('beantwortet','Preis da ('.$cBeant.')') ?></div>
  <form method="get" style="margin-left:auto;display:flex;gap:6px">
    <input type="hidden" name="p" value="lief_anfragen"><input type="hidden" name="f" value="<?= h($f) ?>">
    <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Lieferant, Rezeptur, Nr. …">
    <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
    <?php if ($q!==''): ?><a class="btn btn-ghost btn-sm" href="?p=lief_anfragen&f=<?= h($f) ?>">zurücksetzen</a><?php endif; ?>
  </form>
</div>

<div class="bx-panel">
<?php if (!$rows): ?>
  <div class="muted">Keine Anfragen in dieser Ansicht.</div>
<?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Nr.</th><th>Lieferant</th><th>Was</th><th class="bx-num">Menge</th><th class="bx-num">Angebotspreis</th><th>Status</th><th>Datum</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        // "Was" + Link (Rezeptur-Fremdfertigung / Rohstoff / Freitext)
        if ((int)$r['rezeptur_id'] > 0) {
            $wasLink = '?p=rezeptur&id=' . (int)$r['rezeptur_id'];
            $wasText = 'Fremdfertigung: ' . ($r['rez_name'] ?: ('Rezeptur ' . $r['rez_nummer']));
        } elseif ((int)$r['item_id'] > 0) {
            $wasLink = '?p=rohstoff&id=' . (int)$r['item_id'];
            $wasText = ($r['item_nr'] ? $r['item_nr'] . ' · ' : '') . ($r['item_name'] ?: 'Rohstoff');
        } else {
            $wasLink = null;
            $wasText = $r['betreff'] ?: '–';
        }
        $artBadge = $r['art'] ? '<span class="muted" style="font-size:11px">' . h($ARTLBL[$r['art']] ?? $r['art']) . '</span>' : '';
        $hatPreis = ($r['ang_preis'] !== null && $r['ang_preis'] !== '' && (float)$r['ang_preis'] > 0);
    ?>
      <tr>
        <td style="white-space:nowrap"><?= h((string)($r['nummer'] ?: ('#'.$r['id']))) ?></td>
        <td style="max-width:200px;overflow-wrap:anywhere"><?= h((string)($r['firma'] ?: '–')) ?></td>
        <td style="max-width:320px;overflow-wrap:anywhere">
          <?php if ($wasLink): ?><a href="<?= $wasLink ?>"><?= h($wasText) ?></a><?php else: ?><?= h($wasText) ?><?php endif; ?>
          <?php if ($artBadge): ?><div><?= $artBadge ?></div><?php endif; ?>
        </td>
        <td class="bx-num"><?= $menge($r['menge'], $r['einheit']) ?></td>
        <td class="bx-num">
          <?php if ($hatPreis): ?>
            <strong style="font-weight:600"><?= $eur($r['ang_preis'], $r['ang_waehrung']) ?></strong><?= $r['ang_einheit'] ? ' <span class="muted">/ ' . h($r['ang_einheit']) . '</span>' : '' ?>
            <?php if ($r['ang_min'] || $r['ang_lz']): ?><div class="muted" style="font-size:11px"><?= $r['ang_min'] ? 'ab ' . $menge($r['ang_min'], $r['ang_einheit']) : '' ?><?= ($r['ang_min'] && $r['ang_lz']) ? ' · ' : '' ?><?= $r['ang_lz'] ? (int)$r['ang_lz'] . ' Tage' : '' ?></div><?php endif; ?>
          <?php else: ?>
            <span class="muted">ausstehend</span>
          <?php endif; ?>
        </td>
        <td><?= $hatPreis ? bx_badge('Preis da','ok') : bx_badge('offen') ?></td>
        <td style="white-space:nowrap"><?= $dat($r['angelegt']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if (count($rows) >= 500): ?><p class="muted" style="font-size:12px;margin-top:8px">Nur die neuesten 500 – mit der Suche eingrenzen.</p><?php endif; ?>
<?php endif; ?>
</div>
<?php render_footer();
