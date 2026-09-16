<?php
// Nährstoff (NRV-Referenz) anlegen & bearbeiten
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$KATN = ['vitamin'=>'Vitamin','mineral'=>'Mineralstoff','sonstige'=>'Sonstige'];
$id  = $_GET['id'] ?? 'neu';
$neu = ($id === 'neu' || !is_numeric($id));

$fehler = '';
// Health-Claim-Aktionen (nur fuer bestehende Naehrstoffe) – eigene Wege, damit das Stammdaten-Formular unberuehrt bleibt.
if (!$neu && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['aktion'] ?? '', ['claim_add','claim_del','claim_import'], true)) {
    $nid = (int)$id;
    $nname = (string) scalar("SELECT name FROM naehrstoff WHERE id=?", [$nid]);
    if (($_POST['aktion'] ?? '') === 'claim_add' && trim($_POST['claim'] ?? '') !== '') {
        $sort = (int) scalar("SELECT COALESCE(MAX(sort),-1)+1 FROM health_claim WHERE naehrstoff_id=?", [$nid]);
        q("INSERT INTO health_claim (naehrstoff_id,stoff,claim,bedingung,quelle,sort) VALUES (?,?,?,?,?,?)",
          [$nid, $nname, trim($_POST['claim']), trim($_POST['bedingung'] ?? '') ?: null, trim($_POST['quelle'] ?? '') ?: 'EU 432/2012', $sort]);
    } elseif (($_POST['aktion'] ?? '') === 'claim_del') {
        q("DELETE FROM health_claim WHERE id=? AND naehrstoff_id=?", [(int)($_POST['claim_id'] ?? 0), $nid]);
    } elseif (($_POST['aktion'] ?? '') === 'claim_import') {
        $sort = (int) scalar("SELECT COALESCE(MAX(sort),-1)+1 FROM health_claim WHERE naehrstoff_id=?", [$nid]);
        foreach (preg_split('/\r?\n/', (string)($_POST['bulk'] ?? '')) as $line) {
            $line = trim($line); if ($line === '') continue;
            $teile = array_map('trim', explode('|', $line));   // "Claim" oder "Claim | Bedingung"
            if ($teile[0] === '') continue;
            q("INSERT INTO health_claim (naehrstoff_id,stoff,claim,bedingung,quelle,sort) VALUES (?,?,?,?, 'EU 432/2012', ?)",
              [$nid, $nname, $teile[0], ($teile[1] ?? '') !== '' ? $teile[1] : null, $sort++]);
        }
    }
    header('Location: ?p=naehrstoff&id=' . $nid . '&claimok=1'); exit;
}
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

<?php if (!$neu): seed_health_claims_if_empty(); $claims = health_claims_naehrstoff((int)$id); ?>
<div class="bx-panel">
  <h2 style="margin-top:0">Health Claims <span class="muted" style="font-weight:400;font-size:13px">zugelassene Angaben (EU-VO 432/2012) – erscheinen im PIB der Produkte mit diesem Nährstoff</span></h2>
  <?php if (isset($_GET['claimok'])): ?><div class="bx-panel badge-ok" style="padding:8px 12px;margin-bottom:10px">Gespeichert.</div><?php endif; ?>
  <?php if ($claims): ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Claim</th><th>Bedingung</th><th>Quelle</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($claims as $c): ?>
      <tr>
        <td><?= h($c['claim']) ?></td>
        <td class="muted" style="font-size:12px"><?= h((string)($c['bedingung'] ?? '')) ?></td>
        <td class="muted" style="font-size:12px"><?= h((string)($c['quelle'] ?? '')) ?></td>
        <td style="text-align:right"><form method="post" style="margin:0" onsubmit="return confirm('Diesen Claim löschen?');"><input type="hidden" name="aktion" value="claim_del"><input type="hidden" name="claim_id" value="<?= (int)$c['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">×</button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?><p class="muted" style="margin-top:0">Noch keine Claims für diesen Nährstoff hinterlegt.</p><?php endif; ?>

  <form method="post" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:12px">
    <input type="hidden" name="aktion" value="claim_add">
    <div class="bx-field" style="margin:0;flex:1 1 360px"><label>Neuer Claim (wortgleich zur EU-Liste)</label><input type="text" name="claim" required placeholder="z. B. Vitamin C trägt zu einer normalen Funktion des Immunsystems bei."></div>
    <div class="bx-field" style="margin:0;max-width:240px"><label>Bedingung (optional)</label><input type="text" name="bedingung" placeholder="signifikante Menge (15 % NRV)"></div>
    <button class="btn btn-primary" type="submit">Hinzufügen</button>
  </form>

  <details style="margin-top:12px">
    <summary class="btn btn-ghost btn-sm" style="list-style:none;display:inline-block">Mehrere importieren</summary>
    <form method="post" style="margin-top:10px">
      <input type="hidden" name="aktion" value="claim_import">
      <div class="bx-field"><label>Je Zeile ein Claim <?= bx_hint('Optional mit Bedingung: „Claim | Bedingung". Wortlaut wie in der offiziellen EU-Liste einfügen.') ?></label>
        <textarea name="bulk" rows="6" placeholder="Vitamin C trägt zu einer normalen Funktion des Immunsystems bei. | signifikante Menge (15 % NRV)"></textarea></div>
      <button class="btn btn-primary" type="submit">Importieren</button>
    </form>
  </details>
</div>
<?php endif; ?>
<?php render_footer(); ?>
