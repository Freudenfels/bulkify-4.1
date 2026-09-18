<?php
// Rezeptur-Preise (Fremdfertigung) – Gesamtuebersicht aller Lieferanten-Angebote je Rezeptur.
// Entspricht der v3-Seite rezept_preise.php, aber mit Verlinkung zur Rezeptur UND zum Produkt in v4.
// Quelle: rezeptur_lief_angebot (u. a. aus dem v3-Import, Stufe 4).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$q       = trim((string)($_GET['q'] ?? ''));
$nurP    = ($_GET['preis'] ?? '') === '1';   // nur Zeilen mit echtem Preis

$where = []; $args = [];
if ($q !== '')  { $where[] = "(r.name LIKE ? OR l.firma LIKE ?)"; $args[] = '%'.$q.'%'; $args[] = '%'.$q.'%'; }
if ($nurP)      { $where[] = "la.preis IS NOT NULL AND la.preis > 0"; }
$wsql = $where ? 'WHERE '.implode(' AND ', $where) : '';

$rows = all("SELECT la.*, r.name AS rez_name, r.darreichungsform AS df, r.kunde_id, l.firma
             FROM rezeptur_lief_angebot la
             LEFT JOIN rezeptur r   ON r.id = la.rezeptur_id
             LEFT JOIN lieferanten l ON l.id = la.lieferant_id
             $wsql
             ORDER BY r.name IS NULL, r.name, (la.preis IS NULL OR la.preis = 0), la.preis
             LIMIT 2000", $args);

$gesamt   = (int) scalar("SELECT COUNT(*) FROM rezeptur_lief_angebot");
$mitPreis = (int) scalar("SELECT COUNT(*) FROM rezeptur_lief_angebot WHERE preis IS NOT NULL AND preis > 0");

// Verknuepftes Produkt je Rezeptur (produkt.rezeptur_id) – fuer den Klick ins v4-Produkt.
$prodMap = [];
foreach (all("SELECT id, name, rezeptur_id FROM produkt WHERE rezeptur_id IS NOT NULL") as $p)
    $prodMap[(int)$p['rezeptur_id']][] = $p;

$dfLabel = ['kapsel'=>'Kapsel','tablette'=>'Tablette','pulver'=>'Pulver','granulat'=>'Granulat','fluessig'=>'Flüssig','softgel'=>'Softgel'];

render_header('rezept_preise', 'Rezeptur-Preise');
bx_head('Rezeptur-Preise (Fremdfertigung)', $gesamt . ' Lieferanten-Angebote · ' . $mitPreis . ' mit Preis – aus v3 übernommen, Rezeptur und Produkt verlinkt');
?>
<form method="get" class="bx-row" style="gap:8px;margin-bottom:14px;align-items:center;flex-wrap:wrap">
  <input type="hidden" name="p" value="rezept_preise">
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="Rezeptur oder Lieferant suchen…" style="max-width:340px">
  <button class="btn btn-primary" type="submit">Suchen</button>
  <label class="bx-row muted" style="gap:6px;align-items:center;margin-left:6px;font-size:13px">
    <input type="checkbox" name="preis" value="1" onchange="this.form.submit()"<?= $nurP ? ' checked' : '' ?>> nur mit Preis
  </label>
  <?php if ($q !== '' || $nurP): ?><a class="btn btn-ghost" href="?p=rezept_preise">Zurücksetzen</a><?php endif; ?>
</form>
<div class="bx-panel">
  <?php if (!$rows): ?>
    <div class="muted"><?= ($q !== '' || $nurP) ? 'Keine Treffer.' : 'Keine Lieferanten-Angebote vorhanden.' ?></div>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr>
      <th>Rezeptur</th><th>Form</th><th>Produkt</th><th>Lieferant</th>
      <th class="bx-num">Preis</th><th>Einheit</th><th class="bx-num">Menge</th><th>Status</th>
    </tr></thead>
    <tbody>
      <?php foreach ($rows as $r): $rid = (int)$r['rezeptur_id']; $prods = $prodMap[$rid] ?? []; ?>
        <tr>
          <td><?php if ($rid): ?><a class="kundenlink" href="?p=rezeptur_detail&id=<?= $rid ?>"><?= h((string)($r['rez_name'] ?? '–')) ?></a><?php else: ?><span class="muted">–</span><?php endif; ?></td>
          <td class="muted"><?= h($dfLabel[(string)$r['df']] ?? (string)($r['df'] ?? '')) ?></td>
          <td>
            <?php if ($prods): $p0 = $prods[0]; ?>
              <a class="kundenlink" href="?p=produkt&id=<?= (int)$p0['id'] ?>"><?= h((string)$p0['name']) ?></a><?php if (count($prods) > 1): ?> <span class="muted" style="font-size:11px">+<?= count($prods)-1 ?></span><?php endif; ?>
            <?php else: ?><span class="muted">–</span><?php endif; ?>
          </td>
          <td><?= $r['firma'] ? h((string)$r['firma']) : '<span class="muted">–</span>' ?></td>
          <td class="bx-num"><?= $r['preis'] !== null && (float)$r['preis'] > 0 ? '<strong>' . number_format((float)$r['preis'], 4, ',', '.') . ' &euro;</strong>' : '<span class="muted">–</span>' ?></td>
          <td><?= $r['einheit'] ? h((string)$r['einheit']) : '<span class="muted">–</span>' ?></td>
          <td class="bx-num"><?= $r['menge'] !== null && (float)$r['menge'] > 0 ? rtrim(rtrim(number_format((float)$r['menge'], 3, ',', '.'), '0'), ',') : '<span class="muted">–</span>' ?></td>
          <td><?= ($r['status'] ?? '') === 'angenommen' ? bx_badge('angenommen', 'ok') : bx_badge($r['status'] ?: 'offen', 'info') ?><?= !empty($r['angenommen_am']) ? ' <span class="muted" style="font-size:11px">' . h(date('d.m.Y', strtotime((string)$r['angenommen_am']))) . '</span>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if (count($rows) >= 2000): ?><p class="muted" style="font-size:12px;margin-top:8px">Nur die ersten 2.000 Treffer – bitte die Suche eingrenzen.</p><?php endif; ?>
  <?php endif; ?>
  <p class="muted" style="font-size:12px;margin-top:8px">Lieferanten-Angebote für die Fremdfertigung je Rezeptur (v3-Übernahme). Klick auf die Rezeptur öffnet das Rezeptur-Detail, Klick auf das Produkt das zugehörige v4-Produkt. Details/Neuerfassung je Rezeptur im Panel „Lieferanten-Angebote (Fremdfertigung)" auf der Rezeptur-Detailseite.</p>
</div>
<?php render_footer(); ?>
