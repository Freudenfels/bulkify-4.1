<?php
// Benutzer anlegen & bearbeiten (nur Admin). Rollen als Mehrfachauswahl.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/auth.php';

$ROLLEN = rollen_liste();
$id  = $_GET['id'] ?? 'neu';
$neu = ($id === 'neu' || !is_numeric($id));

$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['name'] ?? '');
    $email = trim(mb_strtolower($_POST['email'] ?? ''));
    $aktiv = isset($_POST['aktiv']) ? 1 : 0;
    $rollenArr = array_values(array_intersect(array_keys($ROLLEN), $_POST['rollen'] ?? []));
    $rollenCsv = implode(',', $rollenArr);
    $pass  = (string)($_POST['pass'] ?? '');
    $editId = $neu ? 0 : (int)$id;

    if ($name === '' || $email === '') {
        $fehler = 'Name und E-Mail sind Pflicht.';
    } elseif ((int) scalar("SELECT COUNT(*) FROM benutzer WHERE email=? AND id<>?", [$email, $editId]) > 0) {
        $fehler = 'Diese E-Mail wird bereits verwendet.';
    } elseif ($neu && $pass === '') {
        $fehler = 'Für einen neuen Benutzer ist ein Passwort nötig.';
    } else {
        // Lockout-Schutz: mindestens ein aktiver Admin muss bleiben
        $wirdAdmin = in_array('admin', $rollenArr, true) && $aktiv === 1;
        $andereAdmins = (int) scalar("SELECT COUNT(*) FROM benutzer WHERE aktiv=1 AND FIND_IN_SET('admin',rollen) AND id<>?", [$editId]);
        if (!$wirdAdmin && $andereAdmins === 0) {
            $fehler = 'Es muss mindestens ein aktiver Admin bleiben – Änderung abgelehnt.';
        } elseif ($neu) {
            q("INSERT INTO benutzer (name,email,pass_hash,rollen,aktiv) VALUES (?,?,?,?,?)",
              [$name, $email, password_hash($pass, PASSWORD_DEFAULT), $rollenCsv, $aktiv]);
            header('Location: ?p=benutzer&ok=1'); exit;
        } else {
            q("UPDATE benutzer SET name=?, email=?, rollen=?, aktiv=? WHERE id=?", [$name, $email, $rollenCsv, $aktiv, $editId]);
            if ($pass !== '') q("UPDATE benutzer SET pass_hash=? WHERE id=?", [password_hash($pass, PASSWORD_DEFAULT), $editId]);
            header('Location: ?p=benutzer_detail&id=' . $editId . '&ok=1'); exit;
        }
    }
}

$u = $neu ? ['aktiv' => 1, 'rollen' => ''] : one("SELECT * FROM benutzer WHERE id=?", [(int)$id]);
if (!$u) { $neu = true; $u = ['aktiv' => 1, 'rollen' => '']; }
$meine = array_filter(array_map('trim', explode(',', (string)($u['rollen'] ?? ''))));
$v = fn($k) => h((string)($u[$k] ?? ''));

render_header('benutzer', $neu ? 'Neuer Benutzer' : $u['name']);
bx_head($neu ? 'Neuer Benutzer' : $v('name'),
        $neu ? 'Mitarbeiter anlegen' : $v('email'),
        bx_btn('Zurück zur Liste', '?p=benutzer', 'ghost'));
if (isset($_GET['ok'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b">' . h($fehler) . '</div>';
?>
<form method="post" class="bx-form">
  <div class="bx-panel"><div class="bx-grid">
    <div class="bx-field"><label>Name</label><input type="text" name="name" value="<?= $v('name') ?>" required></div>
    <div class="bx-field"><label>E-Mail (Login)</label><input type="email" name="email" value="<?= $v('email') ?>" required></div>
    <div class="bx-field"><label>Passwort <?= bx_hint($neu ? 'für den ersten Login' : 'leer lassen = unverändert') ?></label><input type="password" name="pass" <?= $neu ? 'required' : '' ?> placeholder="<?= $neu ? '' : '••••••• (unverändert)' ?>"></div>
    <div class="bx-field"><label>Konto aktiv</label>
      <div class="bx-check" style="padding-top:8px">
        <input type="checkbox" name="aktiv" id="f_aktiv" value="1" <?= (int)($u['aktiv'] ?? 1) === 1 ? 'checked' : '' ?>>
        <label for="f_aktiv" style="margin:0">Benutzer darf sich anmelden</label>
      </div>
    </div>
  </div></div>

  <div class="bx-panel">
    <div style="font-weight:600;margin-bottom:8px">Rollen <?= bx_hint('Mehrfachauswahl – jemand kann z. B. Finance und Einkauf sein. Admin sieht und darf alles.') ?></div>
    <div class="bx-grid">
      <?php foreach ($ROLLEN as $key => $lbl): ?>
        <div class="bx-check">
          <input type="checkbox" name="rollen[]" id="r_<?= $key ?>" value="<?= $key ?>" <?= in_array($key, $meine, true) ? 'checked' : '' ?>>
          <label for="r_<?= $key ?>" style="margin:0"><?= h($lbl) ?></label>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="bx-row" style="margin-top:var(--sp-4)">
    <button class="btn btn-primary" type="submit"><?= $neu ? 'Benutzer anlegen' : 'Speichern' ?></button>
    <a class="btn btn-ghost" href="?p=benutzer">Abbrechen</a>
  </div>
</form>
<?php render_footer(); ?>
