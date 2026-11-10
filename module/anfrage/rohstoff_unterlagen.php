<?php
// Rohstoff-Unterlagen – große Übersicht (Nico 10.11.2026): alle Rohstoffe, die in laufenden Anfragen stecken (Rezeptur-Anfrage,
// Rezeptur im Entwurf/Vorschlag – Kapsel, Stick, Pulver …, Rohstoff-Anfrage, Produkt-Anfrage) mit dem, was fehlt:
// Preis, Dokument (Spezifikation/CoA/Analyse des Lieferanten) und Wirkstoffdaten für die NRV. Häkchen + Sammel-Anfrage beim
// Lieferanten (Preis je kg + CoA/Spezifikation). Route ?p=rohstoff_unterlagen, Menü „Anfragen“ → „Rohstoff-Unterlagen“.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/anfrage_ui.php';
require_once BX_ROOT . '/core/rohstoff_unterlagen.php';   // Pop-up „Preis & Unterlagen“

$DFORM = function_exists('anfrage_formen') ? anfrage_formen() : [];
$filter = (string)($_GET['f'] ?? 'offen');
$FILTER = ['offen' => 'Etwas fehlt', 'dok' => 'Dokument fehlt', 'preis' => 'Preis fehlt', 'nrv' => 'NRV nachtragen', 'ok' => 'Vollständig', 'alle' => 'Alle'];
if (!isset($FILTER[$filter])) $filter = 'offen';
$q = trim((string)($_GET['q'] ?? ''));

// --- Vorgänge je Rohstoff sammeln ------------------------------------------------------------------------------
$vorg = [];   // item_id => [ ['label','href','kunde','art'] ... ]
$add = function (int $iid, string $key, array $v) use (&$vorg) { if ($iid > 0) $vorg[$iid][$key] = $v; };
// a) Rezeptur-Anfragen (Wunschzeilen mit Zuordnung), solange nicht abgelehnt und noch nicht angenommen
foreach (all("SELECT w.item_id, a.id, a.nummer, a.darreichungsform, a.produktname, k.firma
              FROM rezeptur_anfrage_wunsch w JOIN rezeptur_anfrage a ON a.id=w.anfrage_id
              LEFT JOIN rezeptur r ON r.id=a.rezeptur_id LEFT JOIN kunden k ON k.id=a.kunde_id
              WHERE w.item_id IS NOT NULL AND a.status NOT IN ('abgelehnt') AND (r.id IS NULL OR r.status IN ('entwurf','vorschlag'))") as $r)
    $add((int)$r['item_id'], 'ra' . $r['id'], ['label' => $r['nummer'] . ' · ' . ($DFORM[$r['darreichungsform']] ?? $r['darreichungsform']), 'titel' => (string)($r['produktname'] ?? ''),
                                               'href' => '?p=anfrage&id=' . (int)$r['id'], 'kunde' => (string)($r['firma'] ?? ''), 'art' => 'Rezeptur-Anfrage']);
// b) Rezepturen im Entwurf/Vorschlag (Kapsel, Stick, Pulver …)
foreach (all("SELECT z.item_id, r.id, r.nummer, r.name, r.darreichungsform, r.status, k.firma
              FROM rezeptur_zutat z JOIN rezeptur r ON r.id=z.rezeptur_id LEFT JOIN kunden k ON k.id=r.kunde_id
              WHERE z.item_id IS NOT NULL AND r.status IN ('entwurf','vorschlag')") as $r)
    $add((int)$r['item_id'], 'rz' . $r['id'], ['label' => $r['nummer'] . ' · ' . ($DFORM[$r['darreichungsform']] ?? $r['darreichungsform']), 'titel' => (string)$r['name'] . ($r['status'] === 'vorschlag' ? ' (Vorschlag)' : ' (Entwurf)'),
                                               'href' => '?p=rezeptur_detail&id=' . (int)$r['id'], 'kunde' => (string)($r['firma'] ?? ''), 'art' => 'Rezeptur']);
// c) Rohstoff-Anfragen und d) Produkt-Anfragen aus dem Portal (nicht abgelehnt)
foreach (all("SELECT pa.id, pa.nummer, pa.typ, pa.rohstoff_id, pa.rezeptur_id, pa.betreff, k.firma FROM portal_anfrage pa LEFT JOIN kunden k ON k.id=pa.kunde_id
              WHERE pa.status NOT IN ('abgelehnt') AND pa.typ IN ('rohstoff','produkt')") as $r) {
    $v = ['label' => $r['nummer'] . ' · ' . ($r['typ'] === 'rohstoff' ? 'Rohstoff' : 'Produkt'), 'titel' => (string)($r['betreff'] ?? ''),
          'href' => '?p=portal_anfrage&id=' . (int)$r['id'], 'kunde' => (string)($r['firma'] ?? ''), 'art' => $r['typ'] === 'rohstoff' ? 'Rohstoff-Anfrage' : 'Produkt-Anfrage'];
    if (!empty($r['rohstoff_id'])) $add((int)$r['rohstoff_id'], 'pa' . $r['id'], $v);
    if (!empty($r['rezeptur_id'])) foreach (all("SELECT item_id FROM rezeptur_zutat WHERE rezeptur_id=? AND item_id IS NOT NULL", [(int)$r['rezeptur_id']]) as $z) $add((int)$z['item_id'], 'pa' . $r['id'], $v);
}

// e) vorgeschlagene Alternativen zu Rohstoff-Anfragen (Nico 10.11.2026) – brauchen genauso Preis und Unterlagen
foreach (all("SELECT a.item_id, pa.id, pa.nummer, pa.betreff, k.firma FROM portal_anfrage_alternative a JOIN portal_anfrage pa ON pa.id=a.anfrage_id
              LEFT JOIN kunden k ON k.id=pa.kunde_id WHERE a.status='vorgeschlagen' AND pa.status NOT IN ('abgelehnt')") as $r)
    $add((int)$r['item_id'], 'alt' . $r['id'], ['label' => $r['nummer'] . ' · Alternative', 'titel' => 'statt ' . (string)($r['betreff'] ?? ''),
                                               'href' => '?p=portal_anfrage&id=' . (int)$r['id'] . '#alternative', 'kunde' => (string)($r['firma'] ?? ''), 'art' => 'Alternative']);

// --- Stand je Rohstoff: Preis, Dokument, Wirkstoff/NRV, Lieferanten-Anfragen ----------------------------------------
$zeilen = [];
if ($vorg) {
    $in = implode(',', array_map('intval', array_keys($vorg)));
    $items = all("SELECT i.id, i.name, i.artikelnummer, i.ek_preis, i.einheit,
                         EXISTS(SELECT 1 FROM lieferant_preis lp WHERE lp.item_id=i.id AND lp.preis>0) AS hat_lp,
                         EXISTS(SELECT 1 FROM dokument d WHERE d.objekt_typ='item' AND d.objekt_id=i.id AND d.typ IN ('spec','coa','beides','analyse')) AS hat_dok,
                         (SELECT GROUP_CONCAT(CONCAT(n.name, ' ', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(COALESCE(iw.gehalt_wert, iw.gehalt_prozent) AS CHAR))),
                                 IF(COALESCE(iw.gehalt_einheit,'prozent')='prozent',' %',CONCAT(' ',iw.gehalt_einheit))) SEPARATOR ', ')
                            FROM item_wirkstoff iw JOIN naehrstoff n ON n.id=iw.naehrstoff_id WHERE iw.item_id=i.id) AS wirkstoffe,
                         (SELECT COUNT(*) FROM lieferant_anfrage la WHERE la.item_id=i.id AND la.status NOT IN ('zurueckgezogen')) AS la_n,
                         (SELECT COUNT(*) FROM lieferant_anfrage la JOIN lieferant_angebot ag ON ag.anfrage_id=la.id WHERE la.item_id=i.id) AS la_antw,
                         (SELECT DATE(MAX(la.angelegt)) FROM lieferant_anfrage la WHERE la.item_id=i.id) AS la_letzte
                  FROM item i WHERE i.id IN ($in) AND i.kategorie='rohstoff' AND COALESCE(i.form,'') NOT IN ('kapselhuelle','premix')");
    $nrvMuster = '/(citrat|bisglycinat|glycinat|gluconat|oxid|carbonat|chlorid|sulfat|picolinat|malat|orotat|lactat|aspartat|taurat|threonat|phosphat|fumarat|ascorbin|tocopher|retin|cholecalciferol|cobalamin|folsäure|folat|biotin|niacin|riboflavin|thiamin|pyridox|pantothen|(^|[^a-zäöü])(kalium|natrium|zink|zinc|magnesium|calcium|kalzium|eisen|selen|jod|chrom|kupfer|mangan|molybdän|vitamin))/iu';
    foreach ($items as $it) {
        $preis = !empty($it['hat_lp']) || (float)$it['ek_preis'] > 0;
        $dok = !empty($it['hat_dok']);
        $nrvPflicht = (string)($it['wirkstoffe'] ?? '') === '' && preg_match($nrvMuster, mb_strtolower((string)$it['name']));
        $fehlt = (!$preis ? 1 : 0) + (!$dok ? 1 : 0) + ($nrvPflicht ? 1 : 0);
        $zeilen[] = $it + ['preis' => $preis, 'dok' => $dok, 'nrv' => $nrvPflicht, 'fehlt' => $fehlt, 'vorgaenge' => array_values($vorg[(int)$it['id']])];
    }
}
$cnt = ['offen' => 0, 'dok' => 0, 'preis' => 0, 'nrv' => 0, 'ok' => 0, 'alle' => count($zeilen)];
foreach ($zeilen as $z) {
    if ($z['fehlt']) $cnt['offen']++; else $cnt['ok']++;
    if (!$z['dok']) $cnt['dok']++;
    if (!$z['preis']) $cnt['preis']++;
    if ($z['nrv']) $cnt['nrv']++;
}
$needle = mb_strtolower($q);
$rows = array_values(array_filter($zeilen, function ($z) use ($filter, $q, $needle) {
    if ($q !== '') {
        $hay = mb_strtolower($z['name'] . ' ' . $z['artikelnummer'] . ' ' . implode(' ', array_map(fn($v) => $v['label'] . ' ' . $v['titel'] . ' ' . $v['kunde'], $z['vorgaenge'])));
        if (mb_strpos($hay, $needle) === false) return false;
    }
    return match ($filter) { 'offen' => $z['fehlt'] > 0, 'dok' => !$z['dok'], 'preis' => !$z['preis'], 'nrv' => $z['nrv'], 'ok' => $z['fehlt'] === 0, default => true };
}));
usort($rows, fn($a, $b) => [$b['fehlt'], count($b['vorgaenge']), $a['name']] <=> [$a['fehlt'], count($a['vorgaenge']), $b['name']]);

render_header('rohstoff_unterlagen', 'Rohstoff-Unterlagen');
bx_head('Rohstoff-Unterlagen', count($rows) . ' Rohstoff(e) aus laufenden Anfragen und Rezepturen – was fehlt: Preis, Dokument (Spezifikation/CoA) oder Wirkstoffdaten für die NRV.', bx_btn('Zu den Anfragen', '?p=anfragen', 'ghost'));
if (isset($_GET['angefragt'])) echo '<div class="bx-panel badge-ok" style="padding:10px 14px">' . (int)$_GET['angefragt'] . ' Preisanfrage(n) verschickt' . (isset($_GET['gemailt']) && (int)$_GET['gemailt'] > 0 ? ', davon ' . (int)$_GET['gemailt'] . ' per E-Mail' : '') . '. Spezifikation/CoA kommen mit der Antwort.</div>';
$lnk = fn(array $o) => '?' . http_build_query(array_merge(['p' => 'rohstoff_unterlagen', 'f' => $filter, 'q' => $q], $o));
echo rohstoff_erfassen_meldung();
?>
<div class="settabs" style="margin:0 0 10px">
  <?php foreach ($FILTER as $k => $lbl): ?><a href="<?= h($lnk(['f' => $k])) ?>" class="<?= $filter === $k ? 'on' : '' ?>"><?= h($lbl) ?><?= $cnt[$k] ? ' (' . $cnt[$k] . ')' : '' ?></a><?php endforeach; ?>
</div>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="rohstoff_unterlagen"><input type="hidden" name="f" value="<?= h($filter) ?>">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Rohstoff, Anfrage, Rezeptur, Kunde …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="<?= h($lnk(['q' => ''])) ?>">zurücksetzen</a><?php endif; ?>
</form>
<div class="bx-panel" style="padding:0;overflow:hidden">
  <div class="bx-tablewrap"><table class="bx-table" style="font-size:13px">
    <thead><tr>
      <th style="width:28px"><input type="checkbox" title="alle" onclick="document.querySelectorAll('.ruWahl').forEach(function(c){c.checked=this.checked;}.bind(this))"></th>
      <th>Rohstoff</th><th>Wo angefragt</th><th>Preis</th><th>Dokument</th><th>Wirkstoff / NRV</th><th>Beim Lieferanten</th><th></th>
    </tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="8" class="muted" style="padding:14px">Nichts in dieser Ansicht.</td></tr><?php endif; ?>
    <?php foreach ($rows as $z): ?>
      <tr>
        <td><input type="checkbox" class="ruWahl" value="<?= (int)$z['id'] ?>"></td>
        <td style="white-space:normal;min-width:180px"><a href="?p=rohstoff&id=<?= (int)$z['id'] ?>" target="_blank" rel="noopener"><?= h((string)$z['name']) ?></a><?= $z['artikelnummer'] ? ' <span class="muted" style="font-size:11px">' . h((string)$z['artikelnummer']) . '</span>' : '' ?></td>
        <td style="white-space:normal;min-width:220px"><?php foreach (array_slice($z['vorgaenge'], 0, 4) as $v): ?>
          <div><a href="<?= h($v['href']) ?>" target="_blank" rel="noopener"><?= h($v['label']) ?></a><span class="muted"><?= $v['titel'] !== '' ? ' · ' . h(mb_substr($v['titel'], 0, 40)) : '' ?><?= $v['kunde'] !== '' ? ' · ' . h($v['kunde']) : '' ?></span></div>
        <?php endforeach; ?><?= count($z['vorgaenge']) > 4 ? '<div class="muted">+' . (count($z['vorgaenge']) - 4) . ' weitere</div>' : '' ?></td>
        <td><?= $z['preis'] ? bx_badge('vorhanden', 'ok') : bx_badge('fehlt', 'err') ?></td>
        <td><?= $z['dok'] ? bx_badge('vorhanden', 'ok') : bx_badge('fehlt', 'err') ?></td>
        <td style="white-space:normal;max-width:220px"><?= (string)($z['wirkstoffe'] ?? '') !== '' ? '<span class="muted">' . h(preg_replace('/(\d)\.(\d)/', '$1,$2', (string)$z['wirkstoffe'])) . '</span>'
              : ($z['nrv'] ? bx_badge('NRV nachtragen', 'warn') . '<div class="muted" style="font-size:11px">Wirkstoffgehalt am Rohstoff eintragen</div>' : '<span class="muted">–</span>') ?></td>
        <td style="white-space:normal"><?= (int)$z['la_n'] > 0
              ? ((int)$z['la_antw'] > 0 ? bx_badge('beantwortet', 'ok') : bx_badge('angefragt', 'info')) . ' <span class="muted" style="font-size:11px">' . (int)$z['la_n'] . '× · ' . h(date('d.m.Y', strtotime((string)$z['la_letzte']))) . '</span>'
              : '<span class="muted">noch nicht</span>' ?></td>
        <td style="text-align:right"><?= rohstoff_erfassen_knopf((int)$z['id'], (string)$z['name']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<div class="bx-row" style="gap:10px;margin-top:10px;align-items:center;flex-wrap:wrap">
  <button type="button" class="btn btn-primary" onclick="(function(){var ids=[].map.call(document.querySelectorAll('.ruWahl:checked'),function(c){return parseInt(c.value,10);});
    if(!ids.length){alert('Bitte mindestens einen Rohstoff anhaken.');return;}
    bxAnfrageBulkOeffnen(ids, ids.length+' Rohstoff(e) – bei welchen Lieferanten anfragen? (Preis je kg, CoA/Spezifikation)'); if(typeof bxAnfrageModus==='function') bxAnfrageModus('preis');})()">Ausgewählte beim Lieferanten anfragen</button>
  <span class="muted" style="font-size:12px">Preis je kg + Spezifikation/CoA (bei der Preisabgabe Pflicht). „NRV nachtragen“ = am Rohstoff unter Wirkstoffe den Gehalt eintragen (z. B. Kalium 38 %), sonst fehlen elementare Werte und % NRV in Rezeptur, PIB und Kundenportal.</span>
</div>
<?php anfrage_modal(lieferanten_fuer_anfrage('rohstoff'), '?p=rohstoff_unterlagen&f=' . rawurlencode($filter)); ?>
<?= rohstoff_erfassen_dialog($lnk([])) ?>
<?php render_footer(); ?>
