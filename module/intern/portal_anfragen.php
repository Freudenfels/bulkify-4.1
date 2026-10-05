<?php
// Portal-Anfragen (Produkt / Rohstoff / Dienstleistung) – interne Eingangsliste
// mit Suche, Status-Filter (Archiv) und Sortierung, damit die Liste handhabbar bleibt.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

// Status setzen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'status') {
    $aid = (int)($_POST['id'] ?? 0);
    $st  = in_array($_POST['status'] ?? '', ['neu','in_bearbeitung','beantwortet','abgelehnt'], true) ? $_POST['status'] : 'neu';
    if ($aid) q("UPDATE portal_anfrage SET status=? WHERE id=?", [$st, $aid]);
    header('Location: ?p=portal_anfragen&ok=1'); exit;
}

$TYP = ['produkt'=>'Produkt', 'rohstoff'=>'Rohstoff', 'dienstleistung'=>'Dienstleistung'];
$filterTyp = $_GET['typ'] ?? 'alle';
// Status-Ansicht (=Archiv-Logik): offen = neu+in_bearbeitung (das Arbeitsfach),
// beantwortet / abgelehnt getrennt, alle = alles. Standard: offen (kurze Liste).
$ANSICHT = ['offen'=>'Offen', 'beantwortet'=>'Beantwortet', 'abgelehnt'=>'Abgelehnt', 'alle'=>'Alle'];
$ansicht = isset($ANSICHT[$_GET['ansicht'] ?? '']) ? $_GET['ansicht'] : 'offen';
$suche   = trim((string)($_GET['q'] ?? ''));
$SORT = ['neu'=>'Neueste zuerst', 'alt'=>'Älteste zuerst', 'kunde'=>'Kunde (A–Z)', 'status'=>'Status'];
$sort = isset($SORT[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'neu';

$w = []; $args = [];
if ($filterTyp !== 'alle' && isset($TYP[$filterTyp])) { $w[] = "pa.typ=?"; $args[] = $filterTyp; }
// Bei aktiver Suche über ALLE Status suchen (die Ansicht-Tabs zählen dann nicht), sonst nach Ansicht filtern.
if ($suche !== '') {
    // LIKE mit ESCAPE '=' (Backslash bringt MySQL live zum Absturz – siehe Projektregel).
    $like = '%' . str_replace(['=','%','_'], ['==','=%','=_'], $suche) . '%';
    $w[] = "(pa.nummer LIKE ? ESCAPE '=' OR k.firma LIKE ? ESCAPE '=' OR p.name LIKE ? ESCAPE '=' OR p.synonyme LIKE ? ESCAPE '=' OR rz.name LIKE ? ESCAPE '=' OR rz.synonyme LIKE ? ESCAPE '=' OR pa.betreff LIKE ? ESCAPE '=' OR pa.notiz LIKE ? ESCAPE '=')";
    array_push($args, $like, $like, $like, $like, $like, $like, $like, $like);
} elseif ($ansicht === 'offen') {
    $w[] = "pa.status IN ('neu','in_bearbeitung')";
} elseif ($ansicht === 'beantwortet') {
    $w[] = "pa.status='beantwortet'";
} elseif ($ansicht === 'abgelehnt') {
    $w[] = "pa.status='abgelehnt'";
}
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
$order = match ($sort) {
    'alt'    => 'pa.angelegt ASC',
    'kunde'  => 'k.firma ASC, pa.angelegt DESC',
    'status' => "FIELD(pa.status,'neu','in_bearbeitung','beantwortet','abgelehnt'), pa.angelegt DESC",
    default  => "(pa.status='neu') DESC, pa.angelegt DESC",
};
$rows = all("SELECT pa.*, k.firma, p.name AS produkt_name, rz.name AS rezeptur_name
             FROM portal_anfrage pa
             LEFT JOIN kunden k ON k.id=pa.kunde_id
             LEFT JOIN produkt p ON p.id=pa.produkt_id
             LEFT JOIN rezeptur rz ON rz.id=pa.rezeptur_id
             $where ORDER BY $order", $args);
// Zähler je Ansicht (für die Tabs) – unabhängig von der aktuellen Auswahl, aber im Typ-Filter.
$cntW = ''; $cntArgs = [];
if ($filterTyp !== 'alle' && isset($TYP[$filterTyp])) { $cntW = 'WHERE typ=?'; $cntArgs[] = $filterTyp; }
$cnt = ['offen'=>0,'beantwortet'=>0,'abgelehnt'=>0,'alle'=>0];
foreach (all("SELECT status, COUNT(*) n FROM portal_anfrage $cntW GROUP BY status", $cntArgs) as $c) {
    $cnt['alle'] += (int)$c['n'];
    if (in_array($c['status'], ['neu','in_bearbeitung'], true)) $cnt['offen'] += (int)$c['n'];
    elseif ($c['status'] === 'beantwortet') $cnt['beantwortet'] += (int)$c['n'];
    elseif ($c['status'] === 'abgelehnt') $cnt['abgelehnt'] += (int)$c['n'];
}
$VTYPEN = ['glas'=>'Glas', 'pet'=>'PET-Dose', 'pla'=>'PLA-Becher', 'beutel'=>'Standbodenbeutel', 'stick'=>'Stick', 'blister'=>'Blister'];
$stBadge = fn($s) => match ($s) { 'neu'=>bx_badge('neu','info'),'in_bearbeitung'=>bx_badge('in Bearbeitung','warn'),'beantwortet'=>bx_badge('Angebot abgegeben','ok'),'abgelehnt'=>bx_badge('abgelehnt','err'),default=>bx_badge($s) };
// Hilfsfunktion: Link mit erhaltenen Parametern (Typ/Ansicht/Sortierung/Suche) bauen.
$lnk = function(array $over) use ($filterTyp, $ansicht, $sort, $suche) {
    $p = array_merge(['p'=>'portal_anfragen', 'typ'=>$filterTyp, 'ansicht'=>$ansicht, 'sort'=>$sort, 'q'=>$suche], $over);
    return '?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== 'alle' || $v === $p['p']));
};

render_header('portal_anfragen', 'Portal-Anfragen');
bx_head('Portal-Anfragen', count($rows) . ($suche !== '' ? ' Treffer' : ' Anfragen'), '');
if (isset($_GET['ok'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Aktualisiert.</div>';
?>
<form class="bx-listbar" method="get" style="gap:10px;flex-wrap:wrap;align-items:center">
  <input type="hidden" name="p" value="portal_anfragen">
  <input type="hidden" name="ansicht" value="<?= h($ansicht) ?>">
  <input type="hidden" name="sort" value="<?= h($sort) ?>">
  <input type="search" name="q" value="<?= h($suche) ?>" placeholder="Suche: Nummer, Kunde, Produkt, Rezeptur …" style="min-width:280px;flex:1">
  <select name="typ" onchange="this.form.submit()">
    <option value="alle" <?= $filterTyp==='alle'?'selected':'' ?>>Alle Typen</option>
    <?php foreach ($TYP as $key=>$lbl): ?><option value="<?= $key ?>" <?= $filterTyp===$key?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?>
  </select>
  <button class="btn btn-primary btn-sm" type="submit">Suchen</button>
  <?php if ($suche !== ''): ?><a class="btn btn-ghost btn-sm" href="<?= h($lnk(['q'=>''])) ?>">Suche löschen</a><?php endif; ?>
</form>

<div class="bx-row" style="gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 12px">
  <?php foreach ($ANSICHT as $k=>$lbl): $aktiv = ($suche==='' && $ansicht===$k); ?>
    <a class="btn <?= $aktiv ? 'btn-primary' : 'btn-ghost' ?> btn-sm" href="<?= h($lnk(['ansicht'=>$k, 'q'=>''])) ?>"><?= $lbl ?><?php if (isset($cnt[$k])): ?> <span class="muted">(<?= $cnt[$k] ?>)</span><?php endif; ?></a>
  <?php endforeach; ?>
  <span style="flex:1"></span>
  <label class="muted" style="font-size:13px">Sortierung:
    <select onchange="location.href=this.value" style="width:auto">
      <?php foreach ($SORT as $k=>$lbl): ?><option value="<?= h($lnk(['sort'=>$k])) ?>" <?= $sort===$k?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?>
    </select>
  </label>
</div>

<div class="bx-tablewrap"><table class="bx-table">
  <thead><tr><th>Nr.</th><th>Kunde</th><th>Typ</th><th>Anfrage</th><th>Status</th><th>Angefragt</th><th></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="7" class="muted"><?= $suche !== '' ? 'Keine Treffer für „' . h($suche) . '".' : 'Keine Portal-Anfragen in dieser Ansicht.' ?></td></tr><?php endif; ?>
  <?php foreach ($rows as $r):
      if ($r['typ'] === 'produkt') {
          $groesse = $r['fuellmenge_g'] ? rtrim(rtrim(number_format((float)$r['fuellmenge_g'],1,',','.'),'0'),',') . ' g/Pkg'
                   : ($r['stueck'] ? (int)$r['stueck'] . ' Stk/Pkg' : '');
          $txt = ($r['produkt_name'] ?: ($r['rezeptur_name'] ?: '–'))
               . ($groesse ? ' · ' . $groesse : '')
               . ($r['verpackung_typ'] ? ' · ' . ($VTYPEN[$r['verpackung_typ']] ?? $r['verpackung_typ']) : '')
               . ($r['menge'] ? ' · ' . (int)$r['menge'] . ' Pkg' : '');
      } else {
          $txt = ($r['betreff'] ?: '–')
               . ($r['wunsch_menge'] ? ' · ' . rtrim(rtrim(number_format((float)$r['wunsch_menge'],3,',','.'),'0'),',') . ' ' . ($r['wunsch_einheit'] ?: '') : '');
      }
  ?>
    <tr style="cursor:pointer" onclick="location.href='?p=portal_anfrage&id=<?= (int)$r['id'] ?>'">
      <td><a href="?p=portal_anfrage&id=<?= (int)$r['id'] ?>"><?= h($r['nummer']) ?></a></td>
      <td><?= h($r['firma'] ?: '–') ?></td>
      <td><?= h($TYP[$r['typ']] ?? $r['typ']) ?></td>
      <td><?= h($txt) ?><?php if ($r['notiz']): ?><div class="muted" style="font-size:12px"><?= h($r['notiz']) ?></div><?php endif; ?></td>
      <td><?= $stBadge($r['status']) ?></td>
      <td style="white-space:nowrap"><?= $r['angelegt'] ? h(fmt_zeit($r['angelegt'], 'd.m.Y H:i')) : '<span class="muted">–</span>' ?></td>
      <td style="text-align:right"><a class="btn btn-ghost btn-sm" href="?p=portal_anfrage&id=<?= (int)$r['id'] ?>">öffnen</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php render_footer(); ?>
