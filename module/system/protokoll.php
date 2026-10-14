<?php
// Admin-Protokoll: zentrale Liste aller Aktivitaeten (wer hat wann was gemacht/geaendert). Liest die
// bestehende aktivitaet-Tabelle, die quer im System ueber log_aktivitaet() gefuellt wird (Statuswechsel,
// Angebote, Bestellungen, Nachrichten, PIB-Downloads …). Route: ?p=protokoll  (nur Admin).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

if (!has_role('admin')) {
    render_header('dashboard', 'Protokoll');
    bx_head('Protokoll', '', bx_btn('Zurück', '?p=dashboard', 'ghost'));
    echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:16px">Das Protokoll ist nur für Administratoren.</div>';
    render_footer();
    return;
}

$q   = trim((string)($_GET['q'] ?? ''));
$typ = trim((string)($_GET['objekt'] ?? ''));   // Filter auf objekt_typ
$akt = in_array($_GET['akteur'] ?? '', ['team','kunde','lieferant','system'], true) ? (string)$_GET['akteur'] : '';

// Objekt-Typen fuer Filter + Verlinkung (nur die, die es wirklich als Seite gibt).
$OBJ = ['kunde'=>'Kunde','lieferant'=>'Lieferant','auftrag'=>'Auftrag','angebot'=>'Angebot','rezeptur'=>'Rezeptur',
        'produkt'=>'Produkt','item'=>'Rohstoff/Artikel','bestellung'=>'Bestellung','portal_anfrage'=>'Anfrage','beleg'=>'Beleg'];
$LINK = ['kunde'=>'kunde','lieferant'=>'lieferant','auftrag'=>'auftrag','angebot'=>'angebot','rezeptur'=>'rezeptur_detail',
         'produkt'=>'produkt','item'=>'rohstoff','bestellung'=>'bestellung','portal_anfrage'=>'portal_anfrage'];

$where = '1=1'; $args = [];
if ($typ !== '') { $where .= ' AND objekt_typ=?'; $args[] = $typ; }
if ($akt !== '') { $where .= ' AND akteur=?';     $args[] = $akt; }
if ($q !== '') {
    $like = '%' . str_replace(['\\','%','_'], ['','',''], $q) . '%';
    $where .= ' AND (text LIKE ? OR benutzer LIKE ? OR typ LIKE ?)';
    array_push($args, $like, $like, $like);
}
$rows = all("SELECT * FROM aktivitaet WHERE $where ORDER BY erstellt DESC, id DESC LIMIT 500", $args);
$gesamt = (int) scalar("SELECT COUNT(*) FROM aktivitaet");

$akteurLbl = fn($r) => $r['akteur'] === 'team' ? (trim((string)($r['benutzer'] ?? '')) !== '' ? (string)$r['benutzer'] : 'Team')
    : ($r['akteur'] === 'kunde' ? 'Kunde' : ($r['akteur'] === 'lieferant' ? 'Lieferant' : 'System'));

render_header('protokoll', 'Protokoll');
bx_head('Protokoll', 'Wer hat wann was gemacht – zentrale Aktivitätenliste (' . number_format($gesamt, 0, ',', '.') . ' Einträge)');
?>
<div class="bx-listbar">
  <form method="get" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;width:100%">
    <input type="hidden" name="p" value="protokoll">
    <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Text, Person, Aktionstyp …" style="min-width:220px">
    <select name="objekt"><option value="">Alle Bereiche</option><?php foreach ($OBJ as $k=>$l): ?><option value="<?= h($k) ?>" <?= $typ===$k?'selected':'' ?>><?= h($l) ?></option><?php endforeach; ?></select>
    <select name="akteur"><option value="">Alle</option><?php foreach (['team'=>'Team','kunde'=>'Kunde','lieferant'=>'Lieferant','system'=>'System'] as $k=>$l): ?><option value="<?= $k ?>" <?= $akt===$k?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select>
    <button class="btn btn-ghost btn-sm" type="submit">Filtern</button>
    <?php if ($q!=='' || $typ!=='' || $akt!==''): ?><a class="btn btn-ghost btn-sm" href="?p=protokoll">zurücksetzen</a><?php endif; ?>
  </form>
</div>

<div class="bx-panel">
<?php if (!$rows): ?>
  <div class="muted">Keine Einträge in dieser Ansicht.</div>
<?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Zeitpunkt</th><th>Wer</th><th>Aktion</th><th>Bereich</th><th>Objekt</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $route = $LINK[$r['objekt_typ']] ?? '';
        $objLbl = ($OBJ[$r['objekt_typ']] ?? $r['objekt_typ']) . ' #' . (int)$r['objekt_id']; ?>
      <tr>
        <td style="white-space:nowrap"><?= h(fmt_zeit($r['erstellt'], 'd.m.Y H:i')) ?></td>
        <td style="white-space:nowrap"><?= h($akteurLbl($r)) ?></td>
        <td style="max-width:440px;overflow-wrap:anywhere"><?= h((string)$r['text']) ?><?= $r['typ'] ? ' <span class="muted" style="font-size:11px">· ' . h((string)$r['typ']) . '</span>' : '' ?></td>
        <td><?= h($OBJ[$r['objekt_typ']] ?? $r['objekt_typ']) ?></td>
        <td style="white-space:nowrap"><?php if ($route && (int)$r['objekt_id'] > 0): ?><a href="?p=<?= h($route) ?>&id=<?= (int)$r['objekt_id'] ?>"><?= h($objLbl) ?></a><?php else: ?><?= h($objLbl) ?><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if (count($rows) >= 500): ?><p class="muted" style="font-size:12px;margin-top:8px">Nur die neuesten 500 – mit Suche/Filter eingrenzen.</p><?php endif; ?>
<?php endif; ?>
</div>
<?php render_footer();
