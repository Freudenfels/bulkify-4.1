<?php
// Schlanke, read-only Rezeptur-Ansicht fuer das Popup (iframe) – NUR Kopf, Zutaten und Inhaltsstoffe,
// ohne Dashboard-Menue/Editierformular. Route: ?p=rezeptur_popup&id=<rezeptur_id>.
// Inhaltsstoffe werden serverseitig berechnet (gleiche Logik wie die Live-Deklaration im Rezeptur-Detail).
require_once BX_ROOT . '/core/schema.php';

$rid = (int)($_GET['id'] ?? 0);
$rez = $rid ? one("SELECT r.*, kg.name AS kapselgroesse FROM rezeptur r LEFT JOIN kapselgroesse kg ON kg.id=r.kapselgroesse_id WHERE r.id=?", [$rid]) : null;

$dfLabel = ['kapsel'=>'Kapsel','tablette'=>'Tablette','pulver'=>'Pulver','granulat'=>'Granulat','fluessig'=>'Flüssig','softgel'=>'Softgel','stick'=>'Stick','gummi'=>'Fruchtgummi','gel'=>'Gel'];
$nf = fn($x, $d) => number_format((float)$x, $d, ',', '.');
$betrag = function($mg, $einheit, $anzeige, $ie_mg) use ($nf) {
    $lbl = ($anzeige !== null && $anzeige !== '') ? $anzeige : $einheit;
    $s = ($einheit === 'µg') ? $nf($mg * 1000, 1) . ' ' . $lbl : $nf($mg, 1) . ' ' . $lbl;
    if ($ie_mg && (float)$ie_mg > 0) $s .= ' (' . $nf($mg / (float)$ie_mg, 0) . ' I.E.)';
    return $s;
};
$nrvP = function($mg, $nrv, $einheit) use ($nf) {
    if ($nrv === null || $nrv === '') return '';
    $nrvMg = ($einheit === 'µg') ? (float)$nrv / 1000 : (float)$nrv;
    return $nrvMg > 0 ? $nf($mg / $nrvMg * 100, 0) . ' %' : '';
};

$zutaten = []; $wmap = []; $gesamt = 0.0;
if ($rez) {
    $zutaten = all("SELECT z.menge_mg, z.item_id, COALESCE(NULLIF(z.bezeichnung,''), i.name) AS name, i.artikelnummer
                    FROM rezeptur_zutat z LEFT JOIN item i ON i.id=z.item_id WHERE z.rezeptur_id=? ORDER BY z.sort, z.id", [$rid]);
    foreach ($zutaten as $z) $gesamt += (float)$z['menge_mg'];
    $ids = array_values(array_unique(array_filter(array_map(fn($z) => (int)$z['item_id'], $zutaten))));
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        foreach (all("SELECT iw.item_id, n.name, n.nrv_wert, n.einheit, n.ie_mg, n.einheit_anzeige,
                             COALESCE(iw.gehalt_wert, iw.gehalt_prozent) AS gehalt_wert, COALESCE(iw.gehalt_einheit,'prozent') AS gehalt_einheit
                      FROM item_wirkstoff iw JOIN naehrstoff n ON n.id=iw.naehrstoff_id WHERE iw.item_id IN ($in)", $ids) as $w) {
            $wmap[(int)$w['item_id']][] = ['name'=>$w['name'], 'nrv'=>$w['nrv_wert'], 'einheit'=>$w['einheit'],
                'anzeige'=>$w['einheit_anzeige'], 'ie_mg'=>$w['ie_mg'] !== null ? (float)$w['ie_mg'] : null,
                'basePerMg'=>wirkstoff_mg_je_mg($w['gehalt_wert'], $w['gehalt_einheit'], $w['ie_mg'])];
        }
    }
}
// Inhaltsstoff-Zeilen (wie auf dem Etikett) + Nährstoff-Summe aufbauen.
$etikett = []; $nutr = []; $order = [];
foreach ($zutaten as $z) {
    $mg = (float)$z['menge_mg']; if ($mg <= 0) continue;
    $etikett[] = ['art'=>'zutat', 'name'=>(string)$z['name'], 'mg'=>$mg];
    foreach ($wmap[(int)$z['item_id']] ?? [] as $w) {
        if (!$w['basePerMg']) continue;
        $mgN = $mg * (float)$w['basePerMg'];
        $etikett[] = ['art'=>'davon', 'name'=>$w['name'], 'betrag'=>$betrag($mgN, $w['einheit'], $w['anzeige'], $w['ie_mg']), 'nrv'=>$nrvP($mgN, $w['nrv'], $w['einheit'])];
        if (!isset($nutr[$w['name']])) { $nutr[$w['name']] = ['mg'=>0.0, 'nrv'=>$w['nrv'], 'einheit'=>$w['einheit'], 'anzeige'=>$w['anzeige'], 'ie_mg'=>$w['ie_mg']]; $order[] = $w['name']; }
        $nutr[$w['name']]['mg'] += $mgN;
    }
}
?><!doctype html>
<html lang="de"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($rez['name'] ?? 'Rezeptur') ?></title>
<script>try{var t=localStorage.getItem('bx-theme');if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
<style>
  :root{ --bg:#fff; --fg:#1a1a1a; --line:#e6e6e6; --muted:#6b7280; --gruen:#1D9E75; --card:#fff; }
  :root[data-theme="dark"]{ --bg:#10210f; --fg:#e8ece9; --line:#2a3a2a; --muted:#9fb0a7; --gruen:#5fd3a3; --card:#17301a; }
  @media (prefers-color-scheme: dark){ :root:not([data-theme="light"]){ --bg:#10210f; --fg:#e8ece9; --line:#2a3a2a; --muted:#9fb0a7; --gruen:#5fd3a3; --card:#17301a; } }
  *{ box-sizing:border-box }
  body{ margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; color:var(--fg); background:var(--bg); padding:18px 20px 24px }
  h1{ font-size:20px; margin:0 0 2px } h2{ font-size:15px; margin:20px 0 8px }
  a{ color:var(--gruen) }
  .muted{ color:var(--muted) } .sub{ color:var(--muted); font-size:13px }
  .top{ display:flex; justify-content:space-between; align-items:flex-start; gap:12px; margin-bottom:12px }
  .btn{ display:inline-block; padding:7px 12px; border:1px solid var(--line); border-radius:8px; color:var(--fg); text-decoration:none; font-size:13px; white-space:nowrap }
  .btn:hover{ border-color:var(--gruen); color:var(--gruen) }
  .cards{ display:flex; gap:10px; flex-wrap:wrap; margin:8px 0 4px }
  .card{ border:1px solid var(--line); border-radius:10px; padding:10px 14px; min-width:120px; background:var(--card) }
  .card .k{ color:var(--muted); font-size:12px } .card .v{ font-size:16px; font-weight:600; margin-top:2px }
  table{ width:100%; border-collapse:collapse; font-size:14px }
  th,td{ text-align:left; padding:8px 10px; border-bottom:1px solid var(--line); vertical-align:top }
  th{ color:var(--muted); font-weight:500; font-size:12px }
  td.num,th.num{ text-align:right; white-space:nowrap }
  .davon td:first-child{ padding-left:30px; color:var(--muted) }
</style></head><body>
<?php if (!$rez): ?>
  <h1>Rezeptur nicht gefunden</h1>
<?php else: ?>
  <div class="top">
    <div>
      <h1><?= h($rez['name']) ?></h1>
      <div class="sub"><?= h((string)($rez['nummer'] ?? '')) ?><?= !empty($rez['darreichungsform']) ? ' · ' . h($dfLabel[(string)$rez['darreichungsform']] ?? (string)$rez['darreichungsform']) : '' ?></div>
    </div>
    <a class="btn" href="?p=rezeptur_detail&id=<?= $rid ?>" target="_top">Zur Rezeptur bearbeiten →</a>
  </div>
  <div class="cards">
    <div class="card"><div class="k">Gesamtgewicht</div><div class="v"><?= $gesamt > 0 ? $nf($gesamt, 0) . ' mg' : '–' ?></div></div>
    <div class="card"><div class="k">Kapselgröße</div><div class="v" style="color:var(--gruen)"><?= !empty($rez['kapselgroesse']) ? h((string)$rez['kapselgroesse']) : '–' ?></div></div>
    <div class="card"><div class="k">Zutaten</div><div class="v"><?= count($zutaten) ?></div></div>
  </div>

  <h2>Zutaten</h2>
  <table>
    <thead><tr><th>Rohstoff</th><th class="num">Menge (mg)</th></tr></thead>
    <tbody>
      <?php if (!$zutaten): ?><tr><td colspan="2" class="muted">Keine Zutaten hinterlegt.</td></tr><?php endif; ?>
      <?php foreach ($zutaten as $z): ?>
        <tr><td><?= h((string)$z['name']) ?><?php if (!empty($z['artikelnummer'])): ?> <span class="muted" style="font-size:12px">· <?= h((string)$z['artikelnummer']) ?></span><?php endif; ?></td>
            <td class="num"><?= $nf((float)$z['menge_mg'], 0) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <h2>Inhaltsstoffe (wie auf dem Etikett)</h2>
  <table>
    <thead><tr><th>Inhaltsstoff</th><th class="num">Menge je Einheit</th><th class="num">% NRV*</th></tr></thead>
    <tbody>
      <?php if (!$etikett): ?><tr><td colspan="3" class="muted">Keine Wirkstoffdaten an den Rohstoffen hinterlegt.</td></tr><?php endif; ?>
      <?php foreach ($etikett as $e): ?>
        <?php if ($e['art'] === 'zutat'): ?>
          <tr><td><strong><?= h($e['name']) ?></strong></td><td class="num"><?= $nf($e['mg'], 0) ?> mg</td><td></td></tr>
        <?php else: ?>
          <tr class="davon"><td>– davon <?= h($e['name']) ?></td><td class="num"><?= h($e['betrag']) ?></td><td class="num"><?= $e['nrv'] !== '' ? h($e['nrv']) : '<span class="muted">–</span>' ?></td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="muted" style="margin-top:6px;font-size:12px">* NRV = Prozent des Nährstoffbezugswerts pro Tag.</div>
<?php endif; ?>
</body></html>
