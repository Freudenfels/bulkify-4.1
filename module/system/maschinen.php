<?php
// Maschinenfuhrpark – Verwaltung im Dashboard (Spec 9.3). Maschinen + Räume, jeweils mit Typ, QR-Code
// (MA-<id>), Reinigungsintervall. Arbeitet auf den (geteilten) Tabellen pr_maschine/pr_raum – die Produktion
// liest sie weiter (QR-Scan je Step, Reinigung bleibt im Werk). Bearbeiten passiert ab jetzt NUR hier.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string)($_POST['aktion'] ?? '');
    $rid = fn($f) => ($_POST[$f] ?? '') !== '' ? (int)$_POST[$f] : null;
    if ($a === 'maschine_neu') {
        maschine_neu((string)($_POST['name'] ?? ''), $rid('raum_id'), (string)($_POST['intervall'] ?? ''),
                     (string)($_POST['notiz'] ?? ''), (string)($_POST['typ'] ?? ''), (string)($_POST['qr'] ?? ''));
        header('Location: ?p=maschinen&ok=1'); exit;
    }
    if ($a === 'maschine_edit') {
        maschine_setzen((int)($_POST['id'] ?? 0), (string)($_POST['name'] ?? ''), $rid('raum_id'),
                        (string)($_POST['intervall'] ?? ''), (string)($_POST['notiz'] ?? ''),
                        (string)($_POST['typ'] ?? ''), (string)($_POST['qr'] ?? ''));
        header('Location: ?p=maschinen&ok=1'); exit;
    }
    if ($a === 'maschine_del') { maschine_loeschen((int)($_POST['id'] ?? 0)); header('Location: ?p=maschinen&ok=1'); exit; }
    if ($a === 'raum_neu')     { raum_neu((string)($_POST['name'] ?? ''), (string)($_POST['intervall'] ?? ''), (string)($_POST['notiz'] ?? '')); header('Location: ?p=maschinen&tab=raeume&ok=1'); exit; }
    if ($a === 'raum_del')     { raum_loeschen((int)($_POST['id'] ?? 0)); header('Location: ?p=maschinen&tab=raeume&ok=1'); exit; }
    header('Location: ?p=maschinen'); exit;
}

$tab       = ($_GET['tab'] ?? '') === 'raeume' ? 'raeume' : 'maschinen';
$maschinen = maschine_liste(true);
$raeume    = raum_liste(true);
$TYPEN     = maschinen_typen();
$INTERV    = maschine_intervalle();

render_header('maschinen', 'Maschinenfuhrpark');
bx_head('Maschinenfuhrpark', count($maschinen) . ' Maschinen · ' . count($raeume) . ' Räume',
    bx_hint('Maschinen + Räume mit Typ, QR-Code und Reinigungsintervall. Die Produktion scannt den QR je Schritt; die Reinigung/Freigabe läuft im Werk.'));
if (isset($_GET['ok'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';

$intervalSelect = function(string $name, string $cur) use ($INTERV) {
    $o = '<select name="' . $name . '" style="width:auto"><option value="">– kein Intervall –</option>';
    foreach ($INTERV as $k => $l) $o .= '<option value="' . h($k) . '"' . ($cur === $k ? ' selected' : '') . '>' . h($l) . '</option>';
    return $o . '</select>';
};
$typSelect = function(string $cur) use ($TYPEN) {
    $o = '<select name="typ" style="width:auto"><option value="">– Typ wählen –</option>';
    foreach ($TYPEN as $k => $l) $o .= '<option value="' . h($k) . '"' . ($cur === $k ? ' selected' : '') . '>' . h($l) . '</option>';
    return $o . '</select>';
};
$raumSelect = function(?int $cur) use ($raeume) {
    $o = '<select name="raum_id" style="width:auto"><option value="">– kein Raum –</option>';
    foreach ($raeume as $r) $o .= '<option value="' . (int)$r['id'] . '"' . ($cur === (int)$r['id'] ? ' selected' : '') . '>' . h((string)$r['name']) . '</option>';
    return $o . '</select>';
};
?>
<div class="bx-row" style="gap:8px;margin-bottom:14px">
  <a class="btn <?= $tab === 'maschinen' ? 'btn-primary' : 'btn-ghost' ?> btn-sm" href="?p=maschinen">Maschinen</a>
  <a class="btn <?= $tab === 'raeume' ? 'btn-primary' : 'btn-ghost' ?> btn-sm" href="?p=maschinen&tab=raeume">Räume</a>
</div>

<?php if ($tab === 'maschinen'): ?>
<div class="bx-panel">
  <?php if (!$maschinen): ?><div class="muted">Noch keine Maschinen. Unten anlegen.</div>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Name</th><th>Typ</th><th>QR-Code</th><th>Raum</th><th>Reinigung</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($maschinen as $m): ?>
      <tr>
        <td><?= h((string)$m['name']) ?></td>
        <td><?= h(maschinentyp_label((string)($m['typ'] ?? ''))) ?></td>
        <td><?= $m['qr_code'] ? '<code>' . h((string)$m['qr_code']) . '</code>' : '<span class="muted">–</span>' ?></td>
        <td><?= h((string)($m['raum_name'] ?? '')) ?: '<span class="muted">–</span>' ?></td>
        <td><?= h(maschine_intervall_label((string)($m['reinigung_intervall'] ?? ''))) ?></td>
        <td style="text-align:right;white-space:nowrap">
          <details style="display:inline-block">
            <summary class="btn btn-ghost btn-sm" style="list-style:none;cursor:pointer">bearbeiten</summary>
            <form method="post" class="bx-row" style="gap:8px;align-items:flex-end;flex-wrap:wrap;margin-top:8px;justify-content:flex-end">
              <input type="hidden" name="aktion" value="maschine_edit"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
              <div class="bx-field" style="margin:0"><label>Name</label><input type="text" name="name" value="<?= h((string)$m['name']) ?>" required></div>
              <div class="bx-field" style="margin:0"><label>Typ</label><?= $typSelect((string)($m['typ'] ?? '')) ?></div>
              <div class="bx-field" style="margin:0"><label>Raum</label><?= $raumSelect(isset($m['raum_id']) ? (int)$m['raum_id'] : null) ?></div>
              <div class="bx-field" style="margin:0"><label>Reinigung</label><?= $intervalSelect('intervall', (string)($m['reinigung_intervall'] ?? '')) ?></div>
              <div class="bx-field" style="margin:0;max-width:150px"><label>QR-Code</label><input type="text" name="qr" value="<?= h((string)($m['qr_code'] ?? '')) ?>" placeholder="MA-<?= (int)$m['id'] ?>"></div>
              <button class="btn btn-primary btn-sm" type="submit">Speichern</button>
            </form>
          </details>
          <form method="post" style="display:inline" onsubmit="return confirm('Maschine „<?= h((string)$m['name']) ?>“ entfernen?');">
            <input type="hidden" name="aktion" value="maschine_del"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
            <button class="btn btn-ghost btn-sm" type="submit" style="color:#8f231b">entfernen</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0">Neue Maschine</h2>
  <form method="post" class="bx-row" style="gap:12px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="aktion" value="maschine_neu">
    <div class="bx-field" style="margin:0;min-width:200px"><label>Name</label><input type="text" name="name" required placeholder="z. B. Kapselmaschine 1"></div>
    <div class="bx-field" style="margin:0"><label>Typ <?= bx_hint('Legt fest, für welchen Produktionsschritt die Maschine vorgeschlagen wird.') ?></label><?= $typSelect('') ?></div>
    <div class="bx-field" style="margin:0"><label>Raum</label><?= $raumSelect(null) ?></div>
    <div class="bx-field" style="margin:0"><label>Reinigungsintervall</label><?= $intervalSelect('intervall', '') ?></div>
    <div class="bx-field" style="margin:0;max-width:160px"><label>QR-Code <?= bx_hint('Leer = automatisch MA-<Nr>.') ?></label><input type="text" name="qr" placeholder="automatisch"></div>
    <button class="btn btn-primary" type="submit">Maschine anlegen</button>
  </form>
  <p class="muted" style="font-size:12px;margin-top:8px">Die Produktion scannt diesen QR-Code (oder tippt den Namen) je Produktionsschritt. Reinigung &amp; harte Sperre bei „nicht sauber" laufen weiter im Werk.</p>
</div>

<?php else: ?>
<div class="bx-panel">
  <?php if (!$raeume): ?><div class="muted">Noch keine Räume. Unten anlegen.</div>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Name</th><th>Reinigung</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($raeume as $r): ?>
      <tr>
        <td><?= h((string)$r['name']) ?></td>
        <td><?= h(maschine_intervall_label((string)($r['reinigung_intervall'] ?? ''))) ?></td>
        <td style="text-align:right">
          <form method="post" style="display:inline" onsubmit="return confirm('Raum „<?= h((string)$r['name']) ?>“ entfernen?');">
            <input type="hidden" name="aktion" value="raum_del"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-ghost btn-sm" type="submit" style="color:#8f231b">entfernen</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0">Neuer Raum</h2>
  <form method="post" class="bx-row" style="gap:12px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="aktion" value="raum_neu">
    <div class="bx-field" style="margin:0;min-width:200px"><label>Name</label><input type="text" name="name" required placeholder="z. B. Reinraum 1"></div>
    <div class="bx-field" style="margin:0"><label>Reinigungsintervall</label><?= $intervalSelect('intervall', '') ?></div>
    <button class="btn btn-primary" type="submit">Raum anlegen</button>
  </form>
</div>
<?php endif; ?>
<?php render_footer();
