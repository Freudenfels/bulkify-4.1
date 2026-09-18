<?php
// Rezeptur-Liste
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

seed_rezeptur_if_empty();

$DFORM = ['kapsel'=>'Kapsel','tablette'=>'Tablette','softgel'=>'Softgel','stick'=>'Stick','pulver'=>'Pulver','fluessig'=>'Flüssig'];
$q    = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'aktualisiert';
$dir  = $_GET['dir']  ?? 'desc';

// Nur „echte" Rezepturen: Hausrezepturen (ohne Kunde) sowie vom Kunden ANGENOMMENE (eingefroren).
// Kundenvorschläge (Entwurf/Vorschlag/abgelehnt) sind noch keine Rezeptur → werden an der Anfrage geführt.
$rows = all("SELECT r.*, k.firma AS kunde_firma,
             (SELECT COUNT(*) FROM rezeptur_zutat z WHERE z.rezeptur_id=r.id) AS zutat_anzahl
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

$cols = [
    'nummer'           => ['label' => 'Nummer', 'sort' => true],
    'name'             => ['label' => 'Name', 'sort' => true],
    'kunde_firma'      => ['label' => 'Kunde', 'sort' => true, 'render' => fn($r)=> $r['kunde_firma'] ? h($r['kunde_firma']) : '<span class="muted">–</span>'],
    'darreichungsform' => ['label' => 'Form', 'sort' => true, 'render' => fn($r)=> h($DFORM[$r['darreichungsform']] ?? $r['darreichungsform'])],
    'zutat_anzahl'     => ['label' => 'Zutaten', 'sort' => true, 'num' => true],
    'status'           => ['label' => 'Status', 'sort' => true, 'render' => $statusBadge],
    'angelegt'         => ['label' => 'Angelegt', 'sort' => true, 'render' => fn($r)=> $r['angelegt'] ? h(fmt_zeit($r['angelegt'], 'd.m.Y H:i')) : '<span class="muted">–</span>'],
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
