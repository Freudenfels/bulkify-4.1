<?php
// Rezeptur-Liste – mit Rohstoff-/Dokumenten-Status je Rezeptur (wie v3: „N · frei X/Y" + „M Dokumente fehlen").
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

seed_rezeptur_if_empty();

$DFORM = ['kapsel'=>'Kapsel','tablette'=>'Tablette','softgel'=>'Softgel','stick'=>'Stick','pulver'=>'Pulver','fluessig'=>'Flüssig'];
$q    = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'aktualisiert';
$dir  = $_GET['dir']  ?? 'desc';

// Nur „echte" Rezepturen: Hausrezepturen (ohne Kunde) sowie vom Kunden ANGENOMMENE (eingefroren).
// Kundenvorschläge (Entwurf/Vorschlag/abgelehnt) sind noch keine Rezeptur → werden an der Anfrage geführt.
// Je Rezeptur zusätzlich: Anzahl Rohstoffe, davon mit freiem Lagerbestand, und wie viele noch kein
// Spec/CoA-Dokument haben (Rohstoff-Doku = dokument objekt_typ='item', typ spec|coa|analyse).
$rows = all("SELECT r.*, k.firma AS kunde_firma,
             (SELECT COUNT(*) FROM rezeptur_zutat z WHERE z.rezeptur_id=r.id) AS zutat_anzahl,
             (SELECT COUNT(DISTINCT z.item_id) FROM rezeptur_zutat z WHERE z.rezeptur_id=r.id AND z.item_id IS NOT NULL) AS roh_anzahl,
             (SELECT COUNT(DISTINCT z.item_id) FROM rezeptur_zutat z
                WHERE z.rezeptur_id=r.id AND z.item_id IS NOT NULL
                  AND EXISTS (SELECT 1 FROM charge c WHERE c.item_id=z.item_id AND c.status='frei' AND c.menge_verfuegbar>0)) AS roh_frei,
             (SELECT COUNT(DISTINCT z.item_id) FROM rezeptur_zutat z
                WHERE z.rezeptur_id=r.id AND z.item_id IS NOT NULL
                  AND NOT EXISTS (SELECT 1 FROM dokument d WHERE d.objekt_typ='item' AND d.objekt_id=z.item_id AND d.typ IN ('spec','coa','analyse'))) AS dok_fehlen
             FROM rezeptur r LEFT JOIN kunden k ON k.id=r.kunde_id
             WHERE r.kunde_id IS NULL OR r.status IN ('eingefroren','freigegeben')");
// Suche ist LIVE (clientseitig, siehe Script unten) – es werden immer alle Zeilen gerendert und beim
// Tippen sofort gefiltert. $q dient nur zum Vorbefüllen (z. B. per Deep-Link).
$rows = bx_sort_rows($rows, $sort, $dir);

$statusBadge = function($r) {
    return match ($r['status']) {
        'entwurf'     => bx_badge('Entwurf'),
        'vorschlag'   => bx_badge('Vorschlag','info'),
        'freigegeben' => bx_badge('freigegeben','ok'),
        'eingefroren' => bx_badge('eingefroren','warn'),
        'abgelehnt'   => bx_badge('abgelehnt','err'),
        default       => bx_badge(status_text($r['status'])),
    };
};

// Rohstoffe-Zelle: Anzahl + „frei X/Y" + Hinweiszeile (fehlende Dokumente / leeres Rezept / nicht zugeordnet).
$rohCell = function($r) {
    $zut = (int)$r['zutat_anzahl']; $n = (int)$r['roh_anzahl']; $frei = (int)$r['roh_frei']; $dok = (int)$r['dok_fehlen'];
    if ($zut === 0) {
        return '<span class="muted">0 · frei 0/0</span>'
             . '<div style="color:var(--err);font-size:12px;margin-top:2px">Rezept leer – Rohstoffe hinzufügen</div>';
    }
    if ($n === 0) {   // Zutaten da, aber keiner einem Lager-Rohstoff zugeordnet
        return '<strong>' . $zut . '</strong> Zutat' . ($zut === 1 ? '' : 'en')
             . '<div style="color:var(--warn);font-size:12px;margin-top:2px">kein Rohstoff zugeordnet</div>';
    }
    $freiCol = $frei >= $n ? 'var(--gruen)' : ($frei > 0 ? 'var(--warn)' : 'var(--err)');
    $out = '<strong>' . $n . '</strong> · <span style="color:' . $freiCol . '">frei ' . $frei . '/' . $n . '</span>';
    if ($zut > $n) $out .= ' <span class="muted" style="font-size:12px">(+' . ($zut - $n) . ' ohne Rohstoff)</span>';
    $out .= $dok > 0
        ? '<div style="color:var(--err);font-size:12px;margin-top:2px">' . $dok . ' Dokument' . ($dok === 1 ? ' fehlt' : 'e fehlen') . ' – hochladen</div>'
        : '<div style="color:var(--gruen);font-size:12px;margin-top:2px">Dokumente vollständig</div>';
    return $out;
};

// Status-Zelle: Status-Badge + Hinweis-Chips (leer / nicht zugeordnet / CoA/Spec fehlt).
$statusCell = function($r) use ($statusBadge) {
    $chips = [$statusBadge($r)];
    if ((int)$r['zutat_anzahl'] === 0)      $chips[] = bx_badge('leer','err');
    elseif ((int)$r['roh_anzahl'] === 0)    $chips[] = bx_badge('nicht zugeordnet','warn');
    elseif ((int)$r['dok_fehlen'] > 0)      $chips[] = bx_badge('CoA/Spec fehlt','warn');
    return '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">' . implode(' ', $chips) . '</div>';
};

$cols = [
    'nummer'           => ['label' => 'Nr.', 'sort' => true],
    'name'             => ['label' => 'Rezeptur', 'sort' => true, 'render' => fn($r)=> '<strong>' . h($r['name']) . '</strong>'],
    'darreichungsform' => ['label' => 'Form', 'sort' => true, 'render' => fn($r)=> h($DFORM[$r['darreichungsform']] ?? $r['darreichungsform'])],
    'kunde_firma'      => ['label' => 'Kunde', 'sort' => true, 'render' => fn($r)=> $r['kunde_firma'] ? h($r['kunde_firma']) : '<span class="muted">–</span>'],
    'roh_anzahl'       => ['label' => 'Rohstoffe', 'sort' => true, 'num' => true, 'render' => $rohCell],
    'status'           => ['label' => 'Status', 'sort' => true, 'render' => $statusCell],
];

render_header('rezeptur', 'Rezepturen');
bx_head('Rezepturen', count($rows) . ' Einträge', bx_btn('Neue Rezeptur', '?p=rezeptur_detail&id=neu', 'primary'));
?>
<form class="bx-listbar" method="get" onsubmit="return false">
  <input type="hidden" name="p" value="rezeptur">
  <input class="bx-search" type="text" id="rezSuche" name="q" value="<?= h($q) ?>" placeholder="Suchen: Nummer, Name, Kunde …" autocomplete="off" autofocus>
  <span class="muted" id="rezCount" style="font-size:13px;white-space:nowrap"></span>
  <button class="btn btn-ghost btn-sm" type="button" id="rezReset" hidden>zurücksetzen</button>
</form>
<?php
bx_table($cols, array_values($rows), [
    'baseUrl' => '?p=rezeptur',
    'sort'    => $sort,
    'dir'     => $dir,
    'rowUrl'  => fn($r) => '?p=rezeptur_detail&id=' . $r['id'],
    'empty'   => 'Keine Rezepturen gefunden.',
]);
?>
<script>
(function(){
  var box=document.getElementById('rezSuche'); if(!box) return;
  var tbody=document.querySelector('.bx-table tbody'); if(!tbody) return;
  var rows=Array.prototype.slice.call(tbody.querySelectorAll('tr')),
      cnt=document.getElementById('rezCount'), reset=document.getElementById('rezReset'), total=rows.length;
  // "Keine Treffer"-Zeile (nur sichtbar, wenn live nichts passt)
  var leer=document.createElement('tr'); leer.hidden=true;
  leer.innerHTML='<td colspan="<?= count($cols) ?>" class="muted">Keine Rezepturen gefunden.</td>';
  tbody.appendChild(leer);
  function norm(s){ return (s||'').toLowerCase(); }
  function filter(){
    var q=norm(box.value.trim()), sichtbar=0;
    rows.forEach(function(tr){ var m = !q || norm(tr.textContent).indexOf(q)>=0; tr.hidden=!m; if(m) sichtbar++; });
    leer.hidden = sichtbar>0;
    cnt.textContent = q ? (sichtbar + ' von ' + total) : (total + ' Einträge');
    if(reset) reset.hidden = q==='';
  }
  box.addEventListener('input', filter);
  if(reset) reset.addEventListener('click', function(){ box.value=''; filter(); box.focus(); });
  filter();
  // Cursor ans Ende setzen (bei vorbefülltem Feld)
  var v=box.value; box.value=''; box.value=v;
})();
</script>
<?php
render_footer();
