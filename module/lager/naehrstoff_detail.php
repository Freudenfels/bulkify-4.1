<?php
// Nährstoff (NRV-Referenz) anlegen & bearbeiten
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$KATN = ['vitamin'=>'Vitamin','mineral'=>'Mineralstoff','sonstige'=>'Sonstige'];
$id  = $_GET['id'] ?? 'neu';
$neu = ($id === 'neu' || !is_numeric($id));

$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = fn($k) => trim($_POST[$k] ?? '');
    if ($f('name') === '') {
        $fehler = 'Name ist ein Pflichtfeld.';
    } else {
        $nrv = $f('nrv_wert') === '' ? null : $f('nrv_wert');
        $ist = isset($_POST['ist_nrv']) ? 1 : 0;
        if ($neu) {
            q("INSERT INTO naehrstoff (name,kategorie,nrv_wert,einheit,ist_nrv) VALUES (?,?,?,?,?)",
              [$f('name'),$f('kategorie'),$nrv,$f('einheit'),$ist]);
        } else {
            q("UPDATE naehrstoff SET name=?,kategorie=?,nrv_wert=?,einheit=?,ist_nrv=? WHERE id=?",
              [$f('name'),$f('kategorie'),$nrv,$f('einheit'),$ist,(int)$id]);
        }
        header('Location: ?p=naehrstoffe'); exit;
    }
}

$n = $neu ? ['kategorie'=>'sonstige','einheit'=>'mg','ist_nrv'=>0]
          : one("SELECT * FROM naehrstoff WHERE id=?", [(int)$id]);
if (!$n) { $neu = true; $n = ['kategorie'=>'sonstige','einheit'=>'mg','ist_nrv'=>0]; }
$v = fn($k) => h((string)($n[$k] ?? ''));

render_header('naehrstoffe', $neu ? 'Neuer Nährstoff' : $n['name']);
bx_head($neu ? 'Neuer Nährstoff' : $v('name'), 'NRV-Referenz', bx_btn('Zurück zur Liste', '?p=naehrstoffe', 'ghost'));
if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b">' . h($fehler) . '</div>';
?>
<form method="post" class="bx-form">
  <div class="bx-panel"><div class="bx-grid">
    <div class="bx-field"><label>Name</label><input type="text" name="name" value="<?= $v('name') ?>" required></div>
    <div class="bx-field"><label>Kategorie</label>
      <select name="kategorie">
        <?php foreach ($KATN as $k=>$lbl): ?><option value="<?= $k ?>" <?= ($n['kategorie']??'')===$k?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="bx-field"><label>NRV / Tag <?= bx_hint('Nährstoffbezugswert je Tag. Leer lassen, wenn es keine NRV gibt (z. B. Curcumin)') ?></label><input type="number" step="0.0001" name="nrv_wert" value="<?= $v('nrv_wert') ?>"></div>
    <div class="bx-field"><label>Einheit</label>
      <select name="einheit">
        <?php foreach (['mg'=>'mg','µg'=>'µg'] as $k=>$lbl): ?><option value="<?= $k ?>" <?= ($n['einheit']??'')===$k?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="bx-field"><label>Offizieller NRV-Nährstoff</label>
      <div class="bx-check" style="padding-top:8px">
        <input type="checkbox" name="ist_nrv" id="f_ist" value="1" <?= (int)($n['ist_nrv']??0)===1?'checked':'' ?>>
        <label for="f_ist" style="margin:0">hat eine gesetzliche NRV</label>
      </div>
    </div>
  </div></div>
  <div class="bx-row" style="margin-top:var(--sp-4)">
    <button class="btn btn-primary" type="submit"><?= $neu ? 'Anlegen' : 'Speichern' ?></button>
    <a class="btn btn-ghost" href="?p=naehrstoffe">Abbrechen</a>
  </div>
</form>
<?php render_footer(); ?>
