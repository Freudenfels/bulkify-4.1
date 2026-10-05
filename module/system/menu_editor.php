<?php
// Menü-Editor (global, nur Admin): Gruppen/Einträge per Drag & Drop sortieren, Überschriften umbenennen,
// eigene Gruppen anlegen, Einträge ausblenden. Gespeichert als JSON in app_meta 'menu_layout'; bx_nav()
// legt es über die Standard-Navigation. „Zurücksetzen" entfernt die Anpassung wieder.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

if (!has_role('admin')) { header('Location: ?p=dashboard'); exit; }

$reg = bx_nav_registry();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    if ($aktion === 'reset') { meta_set('menu_layout', ''); header('Location: ?p=menu_editor&ok=reset'); exit; }
    if ($aktion === 'save') {
        $d = json_decode((string)($_POST['layout'] ?? ''), true);
        $clean = ['groups' => [], 'hidden' => []];
        $gesehen = [];
        if (is_array($d) && !empty($d['groups']) && is_array($d['groups'])) {
            foreach ($d['groups'] as $g) {
                $label = trim((string)($g['label'] ?? '')); if ($label === '') continue;
                $keys = [];
                foreach ((array)($g['keys'] ?? []) as $k) { $k = (string)$k; if (isset($reg[$k]) && !isset($gesehen[$k])) { $keys[] = $k; $gesehen[$k] = 1; } }
                $clean['groups'][] = ['label' => mb_substr($label, 0, 40), 'keys' => $keys];
            }
            foreach ((array)($d['hidden'] ?? []) as $k) { $k = (string)$k; if (isset($reg[$k]) && !isset($gesehen[$k])) { $clean['hidden'][] = $k; $gesehen[$k] = 1; } }
        }
        meta_set('menu_layout', $clean['groups'] ? json_encode($clean, JSON_UNESCAPED_UNICODE) : '');
        header('Location: ?p=menu_editor&ok=1'); exit;
    }
}

// Arbeitsmodell für die Anzeige aufbauen (Standard oder gespeichertes Layout) – alle Punkte kommen sicher vor.
$layout = menu_layout_get();
$hiddenKeys = [];
if ($layout) foreach ((array)($layout['hidden'] ?? []) as $k) if (isset($reg[(string)$k])) $hiddenKeys[(string)$k] = 1;

$groups = []; $used = [];
if ($layout) {
    foreach ($layout['groups'] as $g) {
        $label = trim((string)($g['label'] ?? '')); if ($label === '') continue;
        $keys = [];
        foreach ((array)($g['keys'] ?? []) as $k) { $k = (string)$k; if (isset($reg[$k]) && !isset($hiddenKeys[$k]) && !isset($used[$k])) { $keys[] = $k; $used[$k] = 1; } }
        $groups[] = ['label' => $label, 'keys' => $keys];
    }
} else {
    foreach (bx_nav_default() as $grp => $items) { $keys = array_keys($items); foreach ($keys as $k) $used[$k] = 1; $groups[] = ['label' => (string)$grp, 'keys' => array_map('strval', $keys)]; }
}
// Nicht einsortierte Punkte an ihre Standardgruppe (oder „Weitere") hängen.
foreach ($reg as $k => $r) {
    if (isset($used[$k]) || isset($hiddenKeys[$k])) continue;
    $gi = null; foreach ($groups as $i => $g) if ($g['label'] === $r['group']) { $gi = $i; break; }
    if ($gi === null) { $groups[] = ['label' => 'Weitere', 'keys' => []]; $gi = count($groups) - 1; }
    $groups[$gi]['keys'][] = $k; $used[$k] = 1;
}

$lbl = fn($k) => (string)($reg[$k]['label'] ?? $k);

render_header('menu_editor', 'Menü');
bx_head('Menü anpassen', 'Per Drag & Drop sortieren, umbenennen, ausblenden – gilt für alle.',
        '<button type="button" class="btn btn-primary" onclick="menuSave()">Speichern</button> '
      . '<form method="post" style="display:inline" onsubmit="return confirm(\'Menü auf Standard zurücksetzen?\');"><input type="hidden" name="aktion" value="reset"><button class="btn btn-ghost" type="submit">Zurücksetzen</button></form>');
if (isset($_GET['ok'])) echo '<div class="bx-panel badge-ok" style="padding:10px 14px">' . ($_GET['ok'] === 'reset' ? 'Menü auf Standard zurückgesetzt.' : 'Menü gespeichert.') . '</div>';
?>
<form method="post" id="menuForm"><input type="hidden" name="aktion" value="save"><input type="hidden" name="layout" id="menuLayout"></form>

<div class="bx-panel bx-keepinfo" style="padding:10px 14px;margin-bottom:12px">
  Einträge mit der Maus zwischen den Gruppen ziehen. Gruppen am Griff <strong>⠿</strong> verschieben.
  Überschrift anklicken und umbenennen. Zum Ausblenden einen Eintrag in „Ausgeblendet" ziehen. Danach <strong>Speichern</strong>.
</div>

<div id="groups" style="display:flex;flex-direction:column;gap:12px">
  <?php foreach ($groups as $g): ?>
    <div class="bx-panel menu-group" style="padding:12px 14px">
      <div class="bx-row" style="align-items:center;gap:10px;margin-bottom:8px">
        <span class="grip" draggable="true" title="Gruppe verschieben" style="cursor:grab;font-size:18px;color:var(--muted)">⠿</span>
        <input type="text" class="grp-label" value="<?= h($g['label']) ?>" style="font-weight:600;font-size:15px;border:1px solid transparent;background:transparent;padding:4px 6px;border-radius:6px;min-width:200px" onfocus="this.style.borderColor='var(--line)'" onblur="this.style.borderColor='transparent'">
        <button type="button" class="btn btn-ghost btn-sm grp-del" title="Leere Gruppe entfernen" onclick="grpDel(this)" style="margin-left:auto">Gruppe entfernen</button>
      </div>
      <ul class="items" style="list-style:none;margin:0;padding:6px;min-height:40px;border:1px dashed var(--line);border-radius:8px;display:flex;flex-direction:column;gap:4px">
        <?php foreach ($g['keys'] as $k): ?>
          <li draggable="true" data-key="<?= h($k) ?>" style="padding:7px 10px;border:1px solid var(--line);border-radius:7px;background:var(--panel-2,rgba(0,0,0,.03));cursor:grab;display:flex;justify-content:space-between;align-items:center;gap:8px">
            <span><?= h($lbl($k)) ?></span><span class="muted" style="font-size:11px"><?= h($k) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endforeach; ?>
</div>

<div class="bx-row" style="margin:12px 0;gap:10px">
  <button type="button" class="btn btn-ghost" onclick="grpAdd()">+ Gruppe</button>
</div>

<div class="bx-panel" style="padding:12px 14px;border-style:dashed">
  <div style="font-weight:600;margin-bottom:8px">Ausgeblendet <span class="muted" style="font-weight:400;font-size:12px">(bleiben per Direktlink erreichbar, erscheinen aber nicht im Menü)</span></div>
  <ul class="items hidden-bucket" id="hidden" style="list-style:none;margin:0;padding:6px;min-height:40px;border:1px dashed var(--line);border-radius:8px;display:flex;flex-wrap:wrap;gap:4px">
    <?php foreach (array_keys($hiddenKeys) as $k): ?>
      <li draggable="true" data-key="<?= h($k) ?>" style="padding:7px 10px;border:1px solid var(--line);border-radius:7px;background:var(--panel-2,rgba(0,0,0,.03));cursor:grab;display:flex;gap:8px;align-items:center">
        <span><?= h($lbl($k)) ?></span><span class="muted" style="font-size:11px"><?= h($k) ?></span>
      </li>
    <?php endforeach; ?>
  </ul>
</div>

<script>
(function(){
  var draggedItem = null, draggedGroup = null;

  function afterItem(ul, y){
    var els = [].slice.call(ul.querySelectorAll('li:not(.dragging)'));
    return els.reduce(function(closest, el){
      var box = el.getBoundingClientRect(); var off = y - box.top - box.height/2;
      return (off < 0 && off > closest.off) ? {off:off, el:el} : closest;
    }, {off:-Infinity, el:null}).el;
  }
  function afterGroup(cont, y){
    var els = [].slice.call(cont.querySelectorAll('.menu-group:not(.dragging)'));
    return els.reduce(function(closest, el){
      var box = el.getBoundingClientRect(); var off = y - box.top - box.height/2;
      return (off < 0 && off > closest.off) ? {off:off, el:el} : closest;
    }, {off:-Infinity, el:null}).el;
  }

  // Items
  document.addEventListener('dragstart', function(e){
    var li = e.target.closest('li[data-key]');
    if (li){ draggedItem = li; li.classList.add('dragging'); li.style.opacity='.5'; e.stopPropagation(); return; }
    var grip = e.target.closest('.grip');
    if (grip){ draggedGroup = grip.closest('.menu-group'); draggedGroup.classList.add('dragging'); draggedGroup.style.opacity='.6'; }
  });
  document.addEventListener('dragend', function(){
    if (draggedItem){ draggedItem.classList.remove('dragging'); draggedItem.style.opacity=''; draggedItem=null; }
    if (draggedGroup){ draggedGroup.classList.remove('dragging'); draggedGroup.style.opacity=''; draggedGroup=null; }
  });
  document.addEventListener('dragover', function(e){
    if (draggedItem){
      var ul = e.target.closest('ul.items'); if (!ul) return;
      e.preventDefault();
      var ref = afterItem(ul, e.clientY);
      if (ref == null) ul.appendChild(draggedItem); else ul.insertBefore(draggedItem, ref);
    } else if (draggedGroup){
      var cont = document.getElementById('groups'); if (!e.target.closest('#groups')) return;
      e.preventDefault();
      var ref = afterGroup(cont, e.clientY);
      if (ref == null) cont.appendChild(draggedGroup); else cont.insertBefore(draggedGroup, ref);
    }
  });

  window.grpAdd = function(){
    var d = document.createElement('div'); d.className='bx-panel menu-group'; d.style.padding='12px 14px';
    d.innerHTML = '<div class="bx-row" style="align-items:center;gap:10px;margin-bottom:8px">'
      + '<span class="grip" draggable="true" title="Gruppe verschieben" style="cursor:grab;font-size:18px;color:var(--muted)">⠿</span>'
      + '<input type="text" class="grp-label" value="Neue Gruppe" style="font-weight:600;font-size:15px;border:1px solid var(--line);background:transparent;padding:4px 6px;border-radius:6px;min-width:200px">'
      + '<button type="button" class="btn btn-ghost btn-sm grp-del" onclick="grpDel(this)" style="margin-left:auto">Gruppe entfernen</button></div>'
      + '<ul class="items" style="list-style:none;margin:0;padding:6px;min-height:40px;border:1px dashed var(--line);border-radius:8px;display:flex;flex-direction:column;gap:4px"></ul>';
    document.getElementById('groups').appendChild(d);
  };
  window.grpDel = function(btn){
    var card = btn.closest('.menu-group');
    if (card.querySelectorAll('li[data-key]').length){ alert('Bitte zuerst alle Einträge aus dieser Gruppe ziehen.'); return; }
    card.remove();
  };
  window.menuSave = function(){
    var groups = [].map.call(document.querySelectorAll('#groups .menu-group'), function(card){
      return { label: (card.querySelector('.grp-label').value||'').trim(),
               keys: [].map.call(card.querySelectorAll('ul.items > li[data-key]'), function(li){ return li.getAttribute('data-key'); }) };
    }).filter(function(g){ return g.label !== ''; });
    var hidden = [].map.call(document.querySelectorAll('#hidden > li[data-key]'), function(li){ return li.getAttribute('data-key'); });
    document.getElementById('menuLayout').value = JSON.stringify({groups:groups, hidden:hidden});
    document.getElementById('menuForm').submit();
  };
})();
</script>
<?php render_footer(); ?>
