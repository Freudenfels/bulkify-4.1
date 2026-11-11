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
$FILTER = ['anfragen' => 'Aktuelle Anfragen', 'offen' => 'Etwas fehlt', 'dok' => 'Dokument fehlt', 'preis' => 'Preis fehlt', 'nrv' => 'NRV nachtragen', 'ok' => 'Vollständig', 'alle' => 'Alle'];
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
// Stand je Rohstoff (Preis, Dokument, Wirkstoffe, Lieferanten-Anfragen) für eine Liste von IDs.
$statusFuer = function (array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $in = implode(',', $ids);
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
        $out[(int)$it['id']] = $it + ['preis' => $preis, 'dok' => $dok, 'nrv' => (bool)$nrvPflicht, 'fehlt' => $fehlt];
    }
    return $out ?? [];
};
if ($vorg) foreach ($statusFuer(array_keys($vorg)) as $iid => $st) $zeilen[] = $st + ['vorgaenge' => array_values($vorg[$iid])];

// --- Reiter „Aktuelle Anfragen" (Nico 11.11.2026): die offenen Anfragen (gleiche Regel wie die Zahl am Menü „Anfragen"),
// je Anfrage ihre Rohstoffe mit Stand und Knopf zum Hochladen von Preis/CoA/Spec. Älteste zuerst (wartet am längsten).
$anfOffen = [];
$TYPLBL = ['produkt' => 'Produkt-Anfrage', 'rohstoff' => 'Rohstoff-Anfrage', 'dienstleistung' => 'Dienstleistung'];
foreach (all("SELECT ra.id, ra.nummer, ra.darreichungsform, ra.produktname, ra.angelegt, ra.status, ra.rezeptur_id, k.firma
              FROM rezeptur_anfrage ra LEFT JOIN rezeptur r ON r.id=ra.rezeptur_id LEFT JOIN kunden k ON k.id=ra.kunde_id
              WHERE ra.status<>'abgelehnt' AND NOT (ra.status<>'ueberarbeiten' AND r.status IN ('vorschlag','freigegeben','eingefroren'))") as $r) {
    $roh = [];
    foreach (all("SELECT item_id, bezeichnung FROM rezeptur_anfrage_wunsch WHERE anfrage_id=? ORDER BY sort, id", [(int)$r['id']]) as $w)
        $roh[] = ['item_id' => (int)$w['item_id'], 'bez' => (string)$w['bezeichnung']];
    if (!empty($r['rezeptur_id'])) foreach (all("SELECT item_id, bezeichnung FROM rezeptur_zutat WHERE rezeptur_id=? ORDER BY sort, id", [(int)$r['rezeptur_id']]) as $z)
        $roh[] = ['item_id' => (int)$z['item_id'], 'bez' => (string)$z['bezeichnung']];
    $anfOffen[] = ['nummer' => (string)$r['nummer'], 'href' => '?p=anfrage&id=' . (int)$r['id'], 'art' => 'Rezeptur-Anfrage · ' . ($DFORM[$r['darreichungsform']] ?? (string)$r['darreichungsform']),
                   'titel' => (string)($r['produktname'] ?? ''), 'kunde' => (string)($r['firma'] ?? ''), 'datum' => (string)$r['angelegt'], 'roh' => $roh];
}
foreach (all("SELECT pa.id, pa.nummer, pa.typ, pa.betreff, pa.angelegt, pa.rohstoff_id, pa.rezeptur_id, pa.produkt_id, pa.wunsch_menge, pa.wunsch_einheit, k.firma
              FROM portal_anfrage pa LEFT JOIN kunden k ON k.id=pa.kunde_id
              WHERE pa.status<>'abgelehnt'
                AND NOT EXISTS (SELECT 1 FROM angebot a WHERE a.anfrage_id=pa.id AND a.kunde_id=pa.kunde_id
                                AND COALESCE(a.kunde_ausgeblendet,0)=0 AND a.status<>'offen' AND a.preise_kunde=1)") as $r) {
    $roh = [];
    if ($r['typ'] === 'rohstoff') {
        $roh[] = ['item_id' => (int)($r['rohstoff_id'] ?? 0), 'bez' => (string)$r['betreff']];
        foreach (all("SELECT item_id FROM portal_anfrage_alternative WHERE anfrage_id=? AND status='vorgeschlagen'", [(int)$r['id']]) as $al)
            $roh[] = ['item_id' => (int)$al['item_id'], 'bez' => 'Alternative'];
    } else {
        $rzId = (int)($r['rezeptur_id'] ?? 0) ?: ((int)($r['produkt_id'] ?? 0) ? (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [(int)$r['produkt_id']]) : 0);
        if ($rzId) foreach (all("SELECT item_id, bezeichnung FROM rezeptur_zutat WHERE rezeptur_id=? ORDER BY sort, id", [$rzId]) as $z)
            $roh[] = ['item_id' => (int)$z['item_id'], 'bez' => (string)$z['bezeichnung']];
    }
    $menge = $r['wunsch_menge'] ? ' · ' . rtrim(rtrim(number_format((float)$r['wunsch_menge'], 3, ',', '.'), '0'), ',') . ' ' . (string)$r['wunsch_einheit'] : '';
    $anfOffen[] = ['nummer' => (string)$r['nummer'], 'href' => '?p=portal_anfrage&id=' . (int)$r['id'], 'art' => ($TYPLBL[$r['typ']] ?? (string)$r['typ']),
                   'titel' => (string)($r['betreff'] ?? '') . $menge, 'kunde' => (string)($r['firma'] ?? ''), 'datum' => (string)$r['angelegt'], 'roh' => $roh];
}
usort($anfOffen, fn($a, $b) => strcmp($a['datum'], $b['datum']));
$anfStatus = $statusFuer(array_merge(...array_map(fn($a) => array_column($a['roh'], 'item_id'), $anfOffen ?: [['roh' => []]])));
// Je Anfrage: wie viele Rohstoffe haben eine Lücke (inkl. „noch nicht zugeordnet")?
foreach ($anfOffen as &$ao) {
    $seen = []; $liste = [];
    foreach ($ao['roh'] as $rr) {
        $k = $rr['item_id'] > 0 ? 'i' . $rr['item_id'] : 'b' . mb_strtolower($rr['bez']);
        if (isset($seen[$k])) continue; $seen[$k] = 1;
        if ($rr['item_id'] > 0 && !isset($anfStatus[$rr['item_id']])) continue;   // z. B. Leerkapsel/Premix: hier nicht relevant
        $liste[] = $rr;
    }
    $ao['roh'] = $liste;
    $ao['luecken'] = count(array_filter($liste, fn($rr) => $rr['item_id'] <= 0 || $anfStatus[$rr['item_id']]['fehlt'] > 0));
}
unset($ao);
if ($q !== '' && $filter === 'anfragen') {
    $nq = mb_strtolower($q);
    $anfOffen = array_values(array_filter($anfOffen, function ($a) use ($nq, $anfStatus) {
        $hay = mb_strtolower($a['nummer'] . ' ' . $a['art'] . ' ' . $a['titel'] . ' ' . $a['kunde'] . ' ' . implode(' ', array_map(fn($r) => $r['bez'] . ' ' . ($anfStatus[$r['item_id']]['name'] ?? ''), $a['roh'])));
        return mb_strpos($hay, $nq) !== false;
    }));
}
$cnt = ['anfragen' => count($anfOffen), 'offen' => 0, 'dok' => 0, 'preis' => 0, 'nrv' => 0, 'ok' => 0, 'alle' => count($zeilen)];
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
<?php if ($filter === 'anfragen'): ?>
<p class="muted" style="margin:0 0 10px;font-size:13px">Offene Anfragen (wie die Zahl am Menü „Anfragen“), älteste zuerst. Je Anfrage die Rohstoffe mit Stand. „Preis / Unterlage“ öffnet das Fenster zum Hochladen von Preis, CoA und Spezifikation (KI liest aus).</p>
<?php if (!$anfOffen): ?><div class="bx-panel"><div class="muted">Keine offenen Anfragen.</div></div><?php endif; ?>
<?php foreach ($anfOffen as $ao): ?>
<div class="bx-panel" style="padding:12px 14px;margin-bottom:12px<?= $ao['luecken'] ? ';border-left:3px solid var(--warn)' : ';border-left:3px solid var(--gruen)' ?>">
  <div class="bx-row" style="justify-content:space-between;align-items:baseline;gap:10px;flex-wrap:wrap">
    <div><a href="<?= h($ao['href']) ?>" target="_blank" rel="noopener"><?= h($ao['nummer']) ?></a> <span class="muted">· <?= h($ao['art']) ?><?= $ao['kunde'] !== '' ? ' · ' . h($ao['kunde']) : '' ?></span>
      <?php if ($ao['titel'] !== ''): ?><div style="font-size:13px"><?= h(mb_substr($ao['titel'], 0, 90)) ?></div><?php endif; ?></div>
    <div class="muted" style="font-size:12px;white-space:nowrap">eingegangen <?= h(fmt_zeit($ao['datum'], 'd.m.Y')) ?> · <?= $ao['roh'] ? ($ao['luecken'] ? bx_badge($ao['luecken'] . ' mit Lücke', 'warn') : bx_badge('Unterlagen vollständig', 'ok')) : bx_badge('keine Rohstoffe', '') ?></div>
  </div>
  <?php if ($ao['roh']): ?>
  <div class="bx-tablewrap" style="margin-top:8px"><table class="bx-table" style="font-size:13px">
    <thead><tr><th>Rohstoff</th><th>Preis</th><th>Dokument</th><th>Wirkstoff / NRV</th><th>Beim Lieferanten</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($ao['roh'] as $rr): $st = $rr['item_id'] > 0 ? ($anfStatus[$rr['item_id']] ?? null) : null; ?>
      <tr>
        <?php if (!$st): ?>
          <td style="white-space:normal"><?= h($rr['bez'] !== '' ? $rr['bez'] : '–') ?></td>
          <td colspan="4"><?= bx_badge('noch keinem Rohstoff zugeordnet', 'warn') ?> <span class="muted" style="font-size:11px">in der Anfrage zuordnen</span></td>
          <td style="text-align:right"><a class="btn btn-ghost btn-sm" href="<?= h($ao['href']) ?>" target="_blank" rel="noopener">Zuordnen</a></td>
        <?php else: ?>
          <td style="white-space:normal;min-width:180px"><a href="?p=rohstoff&id=<?= (int)$st['id'] ?>" target="_blank" rel="noopener"><?= h((string)$st['name']) ?></a><?= $st['artikelnummer'] ? ' <span class="muted" style="font-size:11px">' . h((string)$st['artikelnummer']) . '</span>' : '' ?></td>
          <td><?= $st['preis'] ? bx_badge('vorhanden', 'ok') : bx_badge('fehlt', 'err') ?></td>
          <td><?= $st['dok'] ? bx_badge('vorhanden', 'ok') : bx_badge('fehlt', 'err') ?></td>
          <td style="white-space:normal;max-width:220px"><?= (string)($st['wirkstoffe'] ?? '') !== '' ? '<span class="muted">' . h(preg_replace('/(\d)\.(\d)/', '$1,$2', (string)$st['wirkstoffe'])) . '</span>' : ($st['nrv'] ? bx_badge('NRV nachtragen', 'warn') : '<span class="muted">–</span>') ?></td>
          <td style="white-space:normal"><?= (int)$st['la_n'] > 0 ? ((int)$st['la_antw'] > 0 ? bx_badge('beantwortet', 'ok') : bx_badge('angefragt', 'info')) . ' <span class="muted" style="font-size:11px">' . (int)$st['la_n'] . '×</span>' : '<span class="muted">noch nicht</span>' ?></td>
          <td style="text-align:right;white-space:nowrap"><?= rohstoff_erfassen_knopf((int)$st['id'], (string)$st['name']) ?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php else: ?>
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
<?php endif; /* Ansicht */ ?>
<?php anfrage_modal(lieferanten_fuer_anfrage('rohstoff'), '?p=rohstoff_unterlagen&f=' . rawurlencode($filter)); ?>
<?= rohstoff_erfassen_dialog($lnk([])) ?>
<?php render_footer(); ?>
