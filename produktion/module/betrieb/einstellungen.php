<?php
// Einstellungen: Maschinen und Räume pflegen (je mit Reinigungsintervall). Daraus werden die
// Reinigungspläne generiert (?p=reinigung). Eigene pr_-Tabellen (pr_raum, pr_maschine).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    if ($aktion === 'raum_neu' && trim((string)($_POST['name'] ?? '')) !== '') {
        pr_raum_neu((string)$_POST['name'], (string)($_POST['intervall'] ?? ''), (string)($_POST['notiz'] ?? ''));
        flash('Raum angelegt.');
    } elseif ($aktion === 'raum_del') {
        pr_raum_loeschen((int)($_POST['id'] ?? 0)); flash('Raum entfernt.', 'warn');
    } elseif ($aktion === 'maschine_neu' && trim((string)($_POST['name'] ?? '')) !== '') {
        pr_maschine_neu((string)$_POST['name'], (int)($_POST['raum_id'] ?? 0) ?: null, (string)($_POST['intervall'] ?? ''), (string)($_POST['notiz'] ?? ''),
                        (string)($_POST['typ'] ?? ''), (string)($_POST['qr_code'] ?? ''));
        flash('Maschine angelegt.');
    } elseif ($aktion === 'maschine_typ' && (int)($_POST['id'] ?? 0) > 0) {
        pr_maschine_setzen((int)$_POST['id'], (string)($_POST['typ'] ?? ''), (string)($_POST['qr_code'] ?? ''));
        flash('Maschine aktualisiert.');
    } elseif ($aktion === 'maschine_del') {
        pr_maschine_loeschen((int)($_POST['id'] ?? 0)); flash('Maschine entfernt.', 'warn');
    }
    weiter('?p=einstellungen&tab=' . (preg_replace('/[^a-z]/', '', (string)($_POST['tab'] ?? 'maschinen'))));
}
$tab = (($_GET['tab'] ?? '') === 'raeume') ? 'raeume' : 'maschinen';
$raeume    = pr_raum_alle();
$maschinen = pr_maschine_alle();
$intervalle = pr_intervalle();
$typen      = pr_maschinen_typen();
$typSelect  = function (string $name, string $aktiv = '') use ($typen) {
    $o = '<select name="' . $name . '"><option value="">— kein Typ —</option>';
    foreach ($typen as $code => $label) $o .= '<option value="' . h($code) . '"' . ($aktiv === $code ? ' selected' : '') . '>' . h($label) . '</option>';
    return $o . '</select>';
};

kopf('Einstellungen', 'einstellungen');
seitenkopf('Einstellungen', 'Maschinen & Räume (Basis der Reinigungspläne)');

$intervallSelect = function (string $name) use ($intervalle) {
    $o = '<select name="' . $name . '"><option value="">— kein Reinigungsintervall —</option>';
    foreach ($intervalle as $code => $def) $o .= '<option value="' . h($code) . '">' . h($def[0]) . '</option>';
    return $o . '</select>';
};
?>
<div class="settabs">
  <a href="?p=einstellungen&tab=maschinen" class="<?= $tab === 'maschinen' ? 'on' : '' ?>">Maschinen</a>
  <a href="?p=einstellungen&tab=raeume" class="<?= $tab === 'raeume' ? 'on' : '' ?>">Räume</a>
</div>

<?php if ($tab === 'maschinen'): ?>
<div class="bx-panel">
  <h2 style="margin-top:0">Neue Maschine</h2>
  <form method="post" class="bx-row" style="gap:12px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="aktion" value="maschine_neu"><input type="hidden" name="tab" value="maschinen">
    <div class="bx-field" style="margin:0;min-width:200px;flex:1"><label>Name</label><input type="text" name="name" required placeholder="z. B. Kapselmaschine KM-3"></div>
    <div class="bx-field" style="margin:0;max-width:260px"><label>Typ</label><?= $typSelect('typ') ?></div>
    <div class="bx-field" style="margin:0;max-width:200px"><label>QR-Code</label><input type="text" name="qr_code" placeholder="leer = automatisch (MA-…)"></div>
    <div class="bx-field" style="margin:0;max-width:200px"><label>Raum</label>
      <select name="raum_id"><option value="">— kein Raum —</option><?php foreach ($raeume as $r): ?><option value="<?= (int)$r['id'] ?>"><?= h((string)$r['name']) ?></option><?php endforeach; ?></select></div>
    <div class="bx-field" style="margin:0;max-width:220px"><label>Reinigung</label><?= $intervallSelect('intervall') ?></div>
    <div class="bx-field" style="margin:0;min-width:180px;flex:1"><label>Hinweis</label><input type="text" name="notiz" placeholder="optional"></div>
    <button type="submit" class="btn btn-primary">Anlegen</button>
  </form>
  <p class="muted" style="font-size:12px;margin:12px 0 0">Der Typ koppelt die Maschine an den Produktionsschritt – beim Mischen wird ein Mischer gescannt, beim Verkapseln eine Kapselmaschine usw. Ohne QR-Code wird automatisch einer vergeben.</p>
</div>
<?php if (!$maschinen): ?><div class="bx-panel"><div class="muted">Noch keine Maschinen.</div></div><?php else: ?>
<div class="bx-tablewrap"><table class="bx-table">
  <thead><tr><th>Maschine</th><th>Typ</th><th>QR-Code</th><th>Raum</th><th>Reinigung</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($maschinen as $m): ?>
    <tr>
      <td><strong><?= h((string)$m['name']) ?></strong><?php if (!empty($m['notiz'])): ?><br><span class="muted" style="font-size:12px"><?= h((string)$m['notiz']) ?></span><?php endif; ?></td>
      <td>
        <form method="post" class="bx-row" style="margin:0;gap:6px;align-items:center;flex-wrap:wrap">
          <input type="hidden" name="aktion" value="maschine_typ"><input type="hidden" name="tab" value="maschinen"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
          <?= $typSelect('typ', (string)($m['typ'] ?? '')) ?>
          <input type="text" name="qr_code" value="<?= h((string)($m['qr_code'] ?? '')) ?>" style="max-width:120px" placeholder="QR">
          <button class="btn btn-ghost btn-sm" type="submit">Speichern</button>
        </form>
      </td>
      <td><?= h((string)($m['qr_code'] ?: '–')) ?></td>
      <td><?= h((string)($m['raum_name'] ?: '–')) ?></td>
      <td><?= h(pr_intervall_label((string)($m['reinigung_intervall'] ?? ''))) ?></td>
      <td class="bx-num"><form method="post" style="margin:0" onsubmit="return confirm('Maschine entfernen?');"><input type="hidden" name="aktion" value="maschine_del"><input type="hidden" name="tab" value="maschinen"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Entfernen</button></form></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<?php else: ?>
<div class="bx-panel">
  <h2 style="margin-top:0">Neuer Raum</h2>
  <form method="post" class="bx-row" style="gap:12px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="aktion" value="raum_neu"><input type="hidden" name="tab" value="raeume">
    <div class="bx-field" style="margin:0;min-width:200px;flex:1"><label>Name</label><input type="text" name="name" required placeholder="z. B. Produktion A"></div>
    <div class="bx-field" style="margin:0;max-width:220px"><label>Reinigung</label><?= $intervallSelect('intervall') ?></div>
    <div class="bx-field" style="margin:0;min-width:180px;flex:1"><label>Hinweis</label><input type="text" name="notiz" placeholder="optional"></div>
    <button type="submit" class="btn btn-primary">Anlegen</button>
  </form>
</div>
<?php if (!$raeume): ?><div class="bx-panel"><div class="muted">Noch keine Räume.</div></div><?php else: ?>
<div class="bx-tablewrap"><table class="bx-table">
  <thead><tr><th>Raum</th><th>Reinigung</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($raeume as $r): ?>
    <tr>
      <td><strong><?= h((string)$r['name']) ?></strong><?php if (!empty($r['notiz'])): ?><br><span class="muted" style="font-size:12px"><?= h((string)$r['notiz']) ?></span><?php endif; ?></td>
      <td><?= h(pr_intervall_label((string)($r['reinigung_intervall'] ?? ''))) ?></td>
      <td class="bx-num"><form method="post" style="margin:0" onsubmit="return confirm('Raum entfernen?');"><input type="hidden" name="aktion" value="raum_del"><input type="hidden" name="tab" value="raeume"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Entfernen</button></form></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php endif; ?>
<?php fuss();
