<?php
// Anfragen – EINE Eingangsliste für alle Anfragearten (Soll-Handbuch Abschnitt 3, Schritt 2 vom 08.11.2026):
// Rezepturanfragen (rezeptur_anfrage) + Portal-Anfragen Produkt / Rohstoff / Dienstleistung (portal_anfrage).
// Spalte „Art", Filter nach Art, Ansicht-Tabs (Offen/Beantwortet/Abgelehnt/Alle), Suche, Markierung „neu".
// Die Detailseiten bleiben getrennt (?p=anfrage bzw. ?p=portal_anfrage). Rechte unverändert: Portal-Anfragen erscheinen
// nur, wenn der Nutzer ?p=portal_anfragen sehen darf. Die alte Seite ?p=portal_anfragen bleibt erreichbar.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/rohstoff_unterlagen.php';

seed_anfrage_if_empty();

$DFORM = function_exists('anfrage_formen') ? anfrage_formen()
    : ['kapsel'=>'Kapsel','tablette'=>'Tablette','softgel'=>'Softgel','stick'=>'Stick','gummi'=>'Fruchtgummi','gel'=>'Gel','pulver'=>'Pulver','granulat'=>'Granulat','fluessig'=>'Flüssig','oel'=>'Öl','extrakt'=>'Extrakt'];
$VTYPEN = ['glas'=>'Glas', 'pet'=>'PET-Dose', 'pla'=>'PLA-Becher', 'beutel'=>'Standbodenbeutel', 'stick'=>'Stick', 'blister'=>'Blister'];
$ARTEN = ['alle'=>'Alle Arten', 'rezeptur'=>'Rezeptur', 'produkt'=>'Produkt', 'rohstoff'=>'Rohstoff', 'dienstleistung'=>'Dienstleistung'];
$ANSICHT = ['offen'=>'Offen', 'beantwortet'=>'Beantwortet', 'abgelehnt'=>'Abgelehnt', 'alle'=>'Alle'];

$q       = trim((string)($_GET['q'] ?? ''));
$sort    = $_GET['sort'] ?? 'angelegt';
$dir     = $_GET['dir']  ?? 'desc';
$ansicht = isset($ANSICHT[$_GET['ansicht'] ?? '']) ? $_GET['ansicht'] : 'offen';
$art     = isset($ARTEN[$_GET['art'] ?? '']) ? $_GET['art'] : 'alle';
// Alte Links ?p=portal_anfragen&typ=… / Menü-Bookmarks mit typ= weiter verstehen.
if ($art === 'alle' && isset($ARTEN[$_GET['typ'] ?? '']) && ($_GET['typ'] ?? '') !== 'alle') $art = (string)$_GET['typ'];

$zeilen = [];

// --- Rezepturanfragen ---------------------------------------------------------------------------------------------
// „Beantwortet" = verknüpfte Rezeptur liegt WIRKLICH als Vorschlag beim Kunden (vorschlag/freigegeben/eingefroren),
// außer der Kunde hat „überarbeiten" verlangt. Abgelehnt = Archiv. Alles andere ist offen (wir müssen ran).
$rezRaus = ['vorschlag','freigegeben','eingefroren'];
foreach (all("SELECT a.*, k.firma AS kunde_firma, r.nummer AS rezeptur_nr, r.status AS rezeptur_status
              FROM rezeptur_anfrage a LEFT JOIN kunden k ON k.id=a.kunde_id LEFT JOIN rezeptur r ON r.id=a.rezeptur_id") as $r) {
    $as = (string)($r['status'] ?? ''); $rs = (string)($r['rezeptur_status'] ?? '');
    if ($as === 'abgelehnt')                                              { $b = 'abgelehnt';   $badge = bx_badge('abgelehnt','err'); }
    elseif ($as === 'ueberarbeiten')                                      { $b = 'offen';       $badge = bx_badge('Vorschlag abgelehnt – überarbeiten','err'); }
    elseif (in_array($rs, $rezRaus, true))                                { $b = 'beantwortet'; $badge = $rs === 'vorschlag' ? bx_badge('Vorschlag gesendet','ok') : bx_badge('angenommen','ok'); }
    elseif ($rs === 'entwurf')                                            { $b = 'offen';       $badge = bx_badge('Entwurf – nicht gesendet','warn'); }
    elseif ($as === 'in_bearbeitung')                                     { $b = 'offen';       $badge = bx_badge('in Bearbeitung','warn'); }
    else                                                                  { $b = 'offen';       $badge = bx_badge('offen – kein Vorschlag','info'); }
    $form = (string)($DFORM[$r['darreichungsform'] ?? ''] ?? ($r['darreichungsform'] ?? ''));
    $zeilen[] = [
        'art' => 'rezeptur', 'id' => (int)$r['id'], 'nummer' => (string)$r['nummer'],
        'kunde_id' => $r['kunde_id'], 'kunde_firma' => (string)($r['kunde_firma'] ?? ''),
        'text' => trim(((string)($r['produktname'] ?? '') ?: '–') . ($form !== '' ? ' · ' . $form : '') . (!empty($r['rezeptur_nr']) ? ' · Rezeptur ' . $r['rezeptur_nr'] : '')),
        'bucket' => $b, 'badge' => $badge, 'neu' => $as === 'neu' && $b === 'offen', 'oben' => $as === 'ueberarbeiten',
        'status' => $as, 'angelegt' => (string)($r['angelegt'] ?? ''), 'url' => '?p=anfrage&id=' . (int)$r['id'],
        'rez_id' => (int)($r['rezeptur_id'] ?? 0),
    ];
}

// --- Portal-Anfragen (Produkt / Rohstoff / Dienstleistung) -------------------------------------------------------
// „Beantwortet" = sichtbares, preislich freigegebenes Angebot beim Kunden (wie im Kundenportal und im Menü-Zähler).
if (!function_exists('route_erlaubt') || route_erlaubt('portal_anfragen')) {
    $ANG = "EXISTS(SELECT 1 FROM angebot a WHERE a.anfrage_id=pa.id AND a.kunde_id=pa.kunde_id AND COALESCE(a.kunde_ausgeblendet,0)=0 AND a.status<>'offen' AND a.preise_kunde=1)";
    foreach (all("SELECT pa.*, k.firma, p.name AS produkt_name, rz.name AS rezeptur_name,
                         COALESCE(NULLIF(posp.kundenname,''), posp.name) AS pos_produkt_name, $ANG AS hat_angebot
                  FROM portal_anfrage pa
                  LEFT JOIN kunden k ON k.id=pa.kunde_id
                  LEFT JOIN produkt p ON p.id=pa.produkt_id
                  LEFT JOIN rezeptur rz ON rz.id=pa.rezeptur_id
                  LEFT JOIN (SELECT anfrage_id, MIN(produkt_id) AS produkt_id FROM portal_anfrage_pos
                             WHERE produkt_id IS NOT NULL GROUP BY anfrage_id) fp ON fp.anfrage_id=pa.id
                  LEFT JOIN produkt posp ON posp.id=fp.produkt_id") as $r) {
        $s = (string)($r['status'] ?? '');
        if ($s === 'abgelehnt')            { $b = 'abgelehnt';   $badge = bx_badge('abgelehnt','err'); }
        elseif (!empty($r['hat_angebot'])) { $b = 'beantwortet'; $badge = bx_badge('Angebot abgegeben','ok'); }
        elseif ($s === 'beantwortet')      { $b = 'offen';       $badge = bx_badge('Angebot zurückgezogen – offen','warn'); }
        elseif ($s === 'in_bearbeitung')   { $b = 'offen';       $badge = bx_badge('in Bearbeitung','warn'); }
        else                               { $b = 'offen';       $badge = bx_badge('offen – kein Angebot','info'); }
        if ($r['typ'] === 'produkt') {
            $groesse = $r['fuellmenge_g'] ? rtrim(rtrim(number_format((float)$r['fuellmenge_g'],1,',','.'),'0'),',') . ' g/Pkg'
                     : ($r['stueck'] ? (int)$r['stueck'] . ' Stk/Pkg' : '');
            $txt = ($r['produkt_name'] ?: ($r['rezeptur_name'] ?: '–'))
                 . ($groesse ? ' · ' . $groesse : '')
                 . ($r['verpackung_typ'] ? ' · ' . ($VTYPEN[$r['verpackung_typ']] ?? $r['verpackung_typ']) : '')
                 . ($r['menge'] ? ' · ' . number_format((int)$r['menge'], 0, ',', '.') . ' Pkg' : '')
                 . (!empty($r['umkarton']) ? ' · Umkarton' : '');
        } else {
            $dlProd = $r['produkt_name'] ?: ($r['pos_produkt_name'] ?: '');
            $txt = ($r['betreff'] ?: '–') . ($dlProd ? ' · ' . $dlProd : '')
                 . ($r['wunsch_menge'] ? ' · ' . rtrim(rtrim(number_format((float)$r['wunsch_menge'],3,',','.'),'0'),',') . ' ' . ($r['wunsch_einheit'] ?: '') : '');
        }
        $zeilen[] = [
            'art' => (string)$r['typ'], 'id' => (int)$r['id'], 'nummer' => (string)$r['nummer'],
            'kunde_id' => $r['kunde_id'], 'kunde_firma' => (string)($r['firma'] ?? ''),
            'text' => $txt, 'bucket' => $b, 'badge' => $badge, 'neu' => $s === 'neu' && $b === 'offen', 'oben' => false,
            'status' => $s, 'angelegt' => (string)($r['angelegt'] ?? ''), 'url' => '?p=portal_anfrage&id=' . (int)$r['id'],
            'rohstoff_id' => (int)($r['rohstoff_id'] ?? 0), 'rez_id' => (int)($r['rezeptur_id'] ?? 0),
        ];
    }
}

// --- Filter + Zähler ---------------------------------------------------------------------------------------------
$cntAnsicht = ['offen'=>0,'beantwortet'=>0,'abgelehnt'=>0,'alle'=>0];   // innerhalb der gewählten Art
$cntArt = array_fill_keys(array_keys($ARTEN), 0);                       // innerhalb der gewählten Ansicht
foreach ($zeilen as $z) {
    if ($art === 'alle' || $z['art'] === $art) { $cntAnsicht[$z['bucket']]++; $cntAnsicht['alle']++; }
    if ($ansicht === 'alle' || $z['bucket'] === $ansicht) { $cntArt[$z['art']] = ($cntArt[$z['art']] ?? 0) + 1; $cntArt['alle']++; }
}
$needle = mb_strtolower($q);
$rows = array_values(array_filter($zeilen, function ($z) use ($art, $ansicht, $q, $needle) {
    if ($art !== 'alle' && $z['art'] !== $art) return false;
    if ($q !== '') {   // Suche läuft über alle Status (die Ansicht zählt dann nicht)
        foreach (['nummer','kunde_firma','text'] as $f) if (mb_strpos(mb_strtolower((string)$z[$f]), $needle) !== false) return true;
        return false;
    }
    return $ansicht === 'alle' || $z['bucket'] === $ansicht;
}));
foreach ($rows as &$z) $z['art_label'] = $ARTEN[$z['art']] ?? $z['art'];
unset($z);
// Nico 10.11.2026: fehlende Lieferanten-Unterlagen (Spec/CoA) der Rohstoffe je Anfrage – für alle angezeigten, nicht abgelehnten Zeilen.
$unRez = []; $unPortal = [];
foreach ($rows as $z) {
    if ($z['bucket'] === 'abgelehnt') continue;
    if ($z['art'] === 'rezeptur') $unRez[$z['id']] = true;
    elseif (in_array($z['art'], ['rohstoff', 'produkt'], true)) $unPortal[$z['id']] = ['rohstoff_id' => $z['rohstoff_id'] ?? 0, 'rezeptur_id' => $z['rez_id'] ?? 0];
}
$unterlagen = ($unRez || $unPortal) ? anfragen_unterlagen_fehlen($unRez, $unPortal) : ['rezeptur' => [], 'portal' => []];
foreach ($rows as &$z) {
    $u = $z['art'] === 'rezeptur' ? ($unterlagen['rezeptur'][$z['id']] ?? null) : ($unterlagen['portal'][$z['id']] ?? null);
    $z['unterlagen_fehlen'] = $u ? (int)$u['fehlen'] : -1;
    $z['unterlagen_angefragt'] = $u ? (int)$u['angefragt'] : 0;
}
unset($z);
$rows = bx_sort_rows($rows, $sort, $dir);
// „Überarbeiten" (Kunde hat Vorschlag abgelehnt) und neue Anfragen zuoberst – da muss das Team zuerst ran.
usort($rows, fn($a, $b) => [$b['oben'], $b['neu']] <=> [$a['oben'], $a['neu']]);

$lnk = function (array $over) use ($art, $ansicht, $q): string {
    $p = array_merge(['p' => 'anfragen', 'art' => $art, 'ansicht' => $ansicht, 'q' => $q], $over);
    return '?' . http_build_query(array_filter($p, fn($v, $k) => $k === 'p' || ($v !== '' && $v !== 'alle' && !($k === 'ansicht' && $v === 'offen')), ARRAY_FILTER_USE_BOTH));
};

$cols = [
    'nummer'      => ['label'=>'Nummer', 'sort'=>true, 'render'=>fn($r)=> h($r['nummer']) . ($r['neu'] ? ' ' . bx_badge('neu','info') : '')],
    'art_label'   => ['label'=>'Art', 'sort'=>true],
    'kunde_firma' => ['label'=>'Kunde', 'sort'=>true, 'render'=>fn($r)=> kunde_link($r['kunde_id'], $r['kunde_firma'])],
    'text'        => ['label'=>'Anfrage', 'sort'=>true, 'render'=>fn($r)=> h($r['text'])],
    'status'      => ['label'=>'Status', 'sort'=>true, 'render'=>fn($r)=> $r['badge']],
    'unterlagen_fehlen' => ['label'=>'Unterlagen', 'sort'=>true, 'render'=>fn($r)=> $r['unterlagen_fehlen'] < 0 ? '<span class="muted">–</span>'
        : ($r['unterlagen_fehlen'] === 0 ? '<span class="muted">vollständig</span>'
        : bx_badge($r['unterlagen_fehlen'] . ' ohne Spec/CoA', 'warn') . ($r['unterlagen_angefragt'] ? ' <span class="muted" style="font-size:11px">' . (int)$r['unterlagen_angefragt'] . ' angefragt</span>' : ''))],
    'angelegt'    => ['label'=>'Angefragt', 'sort'=>true, 'render'=>fn($r)=> $r['angelegt'] ? h(fmt_zeit($r['angelegt'], 'd.m.Y H:i')) : '<span class="muted">–</span>'],
];

render_header('anfragen', 'Anfragen');
bx_head('Anfragen', count($rows) . ($q !== '' ? ' Treffer' : ' ' . ($ANSICHT[$ansicht] ?? '')), bx_btn('Neue Rezepturanfrage', '?p=anfrage&id=neu', 'primary'));
?>
<?php if (isset($_GET['geloescht'])): ?><div class="bx-panel badge-ok" style="padding:10px 14px;margin-bottom:12px">Rezepturanfrage gelöscht.</div><?php endif; ?>
<div class="settabs" style="margin:0 0 8px">
  <?php foreach ($ARTEN as $k => $lbl): ?>
    <a href="<?= h($lnk(['art' => $k])) ?>" class="<?= $art === $k ? 'on' : '' ?>"><?= h($lbl) ?><?= $cntArt[$k] ? ' (' . $cntArt[$k] . ')' : '' ?></a>
  <?php endforeach; ?>
</div>
<div class="settabs" style="margin:0 0 12px">
  <?php foreach ($ANSICHT as $k => $lbl): ?>
    <a href="<?= h($lnk(['ansicht' => $k, 'q' => ''])) ?>" class="<?= ($q === '' && $ansicht === $k) ? 'on' : '' ?>"><?= h($lbl) ?><?= $cntAnsicht[$k] ? ' (' . $cntAnsicht[$k] . ')' : '' ?></a>
  <?php endforeach; ?>
</div>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="anfragen">
  <input type="hidden" name="art" value="<?= h($art) ?>">
  <input type="hidden" name="ansicht" value="<?= h($ansicht) ?>">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Nummer, Kunde, Produkt, Rezeptur …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="<?= h($lnk(['q' => ''])) ?>">zurücksetzen</a><?php endif; ?>
</form>
<?php
bx_table($cols, $rows, [
    'baseUrl' => $lnk([]),
    'sort'    => $sort,
    'dir'     => $dir,
    'rowUrl'  => fn($r) => $r['url'],
    'empty'   => $q !== '' ? 'Keine Treffer für „' . h($q) . '".' : ('Keine Anfragen in der Ansicht „' . h($ANSICHT[$ansicht] ?? '') . '".'),
]);
render_footer();
