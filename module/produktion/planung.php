<?php
// Produktionsplanung – Termin (geplant_am) je Produktionsauftrag im Sammel-Setzen vergeben.
// Vertrag: schreibt NUR produktionsauftrag.geplant_am (DATE); die Produktion liest es (Spalte „Wann dran",
// Kalender, Sortierung). Leeres Feld = Termin entfernen. Rolle: Planung (admin/production).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

// Sammel-Speichern: je Zeile ein Datum (leer = Termin entfernen).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'plan_bulk') {
    $map = (array)($_POST['geplant'] ?? []);
    $n = 0;
    foreach ($map as $pid => $d) {
        $pid = (int)$pid; if ($pid <= 0) continue;
        $d = trim((string)$d);
        $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;   // ungültig/leer -> Termin entfernen
        q("UPDATE produktionsauftrag SET geplant_am=? WHERE id=?", [$d, $pid]);
        $n++;
    }
    header('Location: ?p=produktion_planung&gespeichert=' . $n); exit;
}

// Offene/laufende Produktionsaufträge. Sortierung: ohne Termin zuerst, dann nach Datum, dann Prio.
$rows = all("SELECT pa.id, pa.nummer, pa.menge, pa.prio, pa.geplant_am, pa.art_festgelegt_am, pa.produktionsart,
                   k.firma AS kunde_firma,
                   COALESCE(NULLIF(a.produkt_bezeichnung,''), NULLIF(p.name,''), CONCAT(rz.name, ' · Bulk')) AS produkt_name
            FROM produktionsauftrag pa
            LEFT JOIN kunden k    ON k.id=pa.kunde_id
            LEFT JOIN produkt p   ON p.id=pa.produkt_id
            LEFT JOIN rezeptur rz ON rz.id=pa.rezeptur_id
            LEFT JOIN auftrag a   ON a.id=pa.auftrag_id
            WHERE pa.status IN ('offen','laufend')
            ORDER BY (pa.geplant_am IS NULL) DESC, pa.geplant_am ASC, COALESCE(pa.prio,2), pa.id");

$prioDot = function($p) {
    $p = (int)($p ?: 2);
    $f = $p === 1 ? '#d64545' : ($p === 3 ? '#9aa0a6' : '#2b6cd4');
    $t = $p === 1 ? 'Hoch' : ($p === 3 ? 'Niedrig' : 'Normal');
    return '<span title="Priorität: ' . $t . '" style="display:inline-block;width:11px;height:11px;border-radius:50%;background:' . $f . '"></span>';
};
$heute = date('Y-m-d');

render_header('produktion_planung', 'Produktionsplanung');
bx_head('Produktionsplanung', count($rows) . ' offene/laufende Aufträge – Termin je Auftrag setzen (erscheint sofort im Werk: „Wann dran", Kalender).',
        bx_btn('Zum Kalender', '?p=kalender', 'ghost') . ' ' . bx_btn('Zu den Produktionsaufträgen', '?p=produktion', 'ghost'));
if (isset($_GET['gespeichert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Termine gespeichert (' . (int)$_GET['gespeichert'] . ' Aufträge geprüft).</div>';
?>
<?php if (!$rows): ?>
  <div class="bx-panel"><div class="muted">Aktuell keine offenen oder laufenden Produktionsaufträge.</div></div>
<?php else: ?>
<form method="post">
  <input type="hidden" name="aktion" value="plan_bulk">
  <div class="bx-panel" style="padding:0;overflow:hidden">
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Prio</th><th>Nummer</th><th>Kunde</th><th>Produkt</th><th>Eigen/Fremd</th><th class="bx-num">Menge</th><th style="width:170px">Geplant am</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $ueberfaellig = !empty($r['geplant_am']) && (string)$r['geplant_am'] < $heute; ?>
        <tr>
          <td><?= $prioDot($r['prio']) ?></td>
          <td><a href="?p=produktionsauftrag&id=<?= (int)$r['id'] ?>"><?= h($r['nummer'] ?: ('#' . (int)$r['id'])) ?></a></td>
          <td><?= $r['kunde_firma'] ? h(firma_kurz($r['kunde_firma'])) : '<span class="muted">–</span>' ?></td>
          <td><?= $r['produkt_name'] ? h($r['produkt_name']) : '<span class="muted">–</span>' ?></td>
          <td><?= empty($r['art_festgelegt_am'])
                    ? '<span title="Eigen/Fremd noch nicht festgelegt – erst danach geht der Auftrag in die Produktion">' . bx_badge('festlegen', 'err') . '</span>'
                    : (($r['produktionsart'] ?? 'fremd') === 'eigen' ? bx_badge('Eigen', 'ok') : bx_badge('Fremd', 'info')) ?></td>
          <td class="bx-num"><?= (int)$r['menge'] ?></td>
          <td>
            <input type="date" name="geplant[<?= (int)$r['id'] ?>]" value="<?= h((string)($r['geplant_am'] ?? '')) ?>"
                   style="<?= $ueberfaellig ? 'border-color:#d64545;' : '' ?>">
            <?php if ($ueberfaellig): ?><div class="muted" style="font-size:11px;color:#8f231b">überfällig</div><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="bx-row" style="margin-top:12px;align-items:center;gap:12px">
    <button class="btn btn-primary" type="submit">Termine speichern</button>
    <span class="muted" style="font-size:12px">Leeres Datum = Termin entfernen. „Ohne Termin" steht oben.</span>
  </div>
</form>
<?php endif; ?>
<?php render_footer(); ?>
