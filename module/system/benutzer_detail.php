<?php
// Benutzer anlegen & bearbeiten (nur Admin). Rollen als Mehrfachauswahl.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/auth.php';
require_once BX_ROOT . '/core/mail.php';   // mail_signatur_render / _vorlage für die E-Mail-Signatur

$ROLLEN = rollen_liste();
$id  = $_GET['id'] ?? 'neu';
$neu = ($id === 'neu' || !is_numeric($id));

$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['name'] ?? '');
    $email = trim(mb_strtolower($_POST['email'] ?? ''));
    $aktiv = isset($_POST['aktiv']) ? 1 : 0;
    $rollenArr = array_values(array_intersect(array_keys($ROLLEN), $_POST['rollen'] ?? []));
    // Rollen, die es hier gar nicht zum Ankreuzen gibt (z. B. 'lieferant' fuer einen Portalzugang),
    // bleiben erhalten. Sonst wuerde ein Speichern hier den Portalzugang stillschweigend kaputtmachen.
    if (!$neu) {
        $alt = array_filter(array_map('trim', explode(',', (string) scalar("SELECT rollen FROM benutzer WHERE id=?", [(int)$id]))));
        $fremd = array_diff($alt, array_keys($ROLLEN));
        $rollenArr = array_values(array_unique(array_merge($rollenArr, $fremd)));
    }
    $rollenCsv = implode(',', $rollenArr);
    $pass  = (string)($_POST['pass'] ?? '');
    $editId = $neu ? 0 : (int)$id;
    $pin    = preg_replace('/\D/', '', (string)($_POST['pin'] ?? ''));   // Tablet-PIN: nur Ziffern
    $pinDel = !empty($_POST['pin_loeschen']);
    $pinFehler = ($pin !== '' && (strlen($pin) < 4 || strlen($pin) > 8)) ? 'Die Produktions-PIN muss 4–8 Ziffern haben.' : '';
    // E-Mail-Signatur: Telefon + Position (für die Vorlage) und die (optionale) eigene Signatur-HTML.
    $telefon  = trim((string)($_POST['telefon'] ?? ''));
    $position = trim((string)($_POST['position'] ?? ''));
    $signatur = trim((string)($_POST['mail_signatur'] ?? ''));

    if ($name === '' || $email === '') {
        $fehler = 'Name und E-Mail sind Pflicht.';
    } elseif ((int) scalar("SELECT COUNT(*) FROM benutzer WHERE email=? AND id<>?", [$email, $editId]) > 0) {
        $fehler = 'Diese E-Mail wird bereits verwendet.';
    } elseif ($neu && $pass === '') {
        $fehler = 'Für einen neuen Benutzer ist ein Passwort nötig.';
    } elseif ($pinFehler !== '') {
        $fehler = $pinFehler;
    } else {
        // Lockout-Schutz: mindestens ein aktiver Admin muss bleiben
        $wirdAdmin = in_array('admin', $rollenArr, true) && $aktiv === 1;
        $andereAdmins = (int) scalar("SELECT COUNT(*) FROM benutzer WHERE aktiv=1 AND FIND_IN_SET('admin',rollen) AND id<>?", [$editId]);
        if (!$wirdAdmin && $andereAdmins === 0) {
            $fehler = 'Es muss mindestens ein aktiver Admin bleiben – Änderung abgelehnt.';
        } elseif ($neu) {
            q("INSERT INTO benutzer (name,email,pass_hash,rollen,aktiv) VALUES (?,?,?,?,?)",
              [$name, $email, password_hash($pass, PASSWORD_DEFAULT), $rollenCsv, $aktiv]);
            $nid = insert_id();
            if ($pin !== '') q("UPDATE benutzer SET pin_hash=? WHERE id=?", [password_hash($pin, PASSWORD_DEFAULT), $nid]);
            // Signatur: eigene übernehmen, sonst automatisch aus der Vorlage mit den Benutzerdaten rendern (neue Mitarbeiter).
            $sig = $signatur !== '' ? $signatur : mail_signatur_render(['name'=>$name, 'position'=>$position, 'email'=>$email, 'telefon'=>$telefon]);
            q("UPDATE benutzer SET telefon=?, position=?, mail_signatur=? WHERE id=?", [$telefon ?: null, $position ?: null, $sig, $nid]);
            header('Location: ?p=benutzer&ok=1'); exit;
        } else {
            q("UPDATE benutzer SET name=?, email=?, rollen=?, aktiv=?, telefon=?, position=?, mail_signatur=? WHERE id=?",
              [$name, $email, $rollenCsv, $aktiv, $telefon ?: null, $position ?: null, $signatur ?: null, $editId]);
            if ($pass !== '') q("UPDATE benutzer SET pass_hash=? WHERE id=?", [password_hash($pass, PASSWORD_DEFAULT), $editId]);
            if ($pinDel)         q("UPDATE benutzer SET pin_hash=NULL WHERE id=?", [$editId]);
            elseif ($pin !== '') q("UPDATE benutzer SET pin_hash=? WHERE id=?", [password_hash($pin, PASSWORD_DEFAULT), $editId]);
            header('Location: ?p=benutzer_detail&id=' . $editId . '&ok=1'); exit;
        }
    }
}

$u = $neu ? ['aktiv' => 1, 'rollen' => ''] : one("SELECT * FROM benutzer WHERE id=?", [(int)$id]);
if (!$u) { $neu = true; $u = ['aktiv' => 1, 'rollen' => '']; }
$meine = array_filter(array_map('trim', explode(',', (string)($u['rollen'] ?? ''))));
$v = fn($k) => h((string)($u[$k] ?? ''));
$sigEff = !$neu ? mail_signatur_fuer((int)$id) : '';   // effektive Signatur (eigene oder aus Vorlage) für die Vorschau

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
    <div class="bx-field"><label>Produktions-PIN (Tablet) <?= bx_hint('4–8 Ziffern für die Mitarbeiter-Produktions-App am Tablet (?p=werk). ' . ($neu ? '' : 'Leer lassen = unverändert.')) ?></label>
      <input type="text" inputmode="numeric" pattern="[0-9]*" name="pin" autocomplete="off" placeholder="<?= !$neu && !empty($u['pin_hash']) ? '•••• (gesetzt)' : 'z. B. 2468' ?>">
      <?php if (!$neu && !empty($u['pin_hash'])): ?><label style="display:flex;gap:6px;align-items:center;font-size:12px;margin-top:6px"><input type="checkbox" name="pin_loeschen" value="1"> PIN löschen (kein Tablet-Login)</label><?php endif; ?>
    </div>
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

  <div class="bx-panel">
    <div style="font-weight:600;margin-bottom:8px">E-Mail-Signatur <?= bx_hint('Erscheint unter den E-Mails, die das Dashboard im Namen dieses Mitarbeiters verschickt, und dient als Vorlage fürs eigene Mailprogramm. Leer lassen = automatisch aus der zentralen Vorlage mit den Angaben hier. Vorlage ändern unter Einstellungen → E-Mail.') ?></div>
    <div class="bx-grid">
      <div class="bx-field"><label>Position / Funktion</label><input type="text" name="position" value="<?= $v('position') ?>" placeholder="z. B. Produktentwicklung"></div>
      <div class="bx-field"><label>Telefon</label><input type="text" name="telefon" value="<?= $v('telefon') ?>" placeholder="z. B. +49 1520 2822870"></div>
    </div>
    <div class="bx-field" style="margin-top:8px"><label>Eigene Signatur (HTML) – optional <?= bx_hint('Leer = automatisch aus der Vorlage. Eigenes HTML überschreibt die Vorlage für diesen Mitarbeiter.') ?></label>
      <textarea name="mail_signatur" rows="6" style="font-family:monospace;font-size:12px" placeholder="Leer lassen = automatisch aus der zentralen Vorlage"><?= h((string)($u['mail_signatur'] ?? '')) ?></textarea>
    </div>
    <?php if (!$neu && $sigEff !== ''): ?>
      <div style="margin-top:10px"><div class="muted" style="font-size:12px;margin-bottom:4px">Vorschau:</div>
        <div style="border:1px solid var(--line);border-radius:8px;padding:12px;background:#fff"><?= $sigEff ?></div></div>
    <?php else: ?>
      <div class="muted" style="font-size:12px;margin-top:8px">Die Signatur wird beim Anlegen automatisch aus der Vorlage erzeugt (mit Name, Position, Telefon und E-Mail).</div>
    <?php endif; ?>
  </div>

  <div class="bx-row" style="margin-top:var(--sp-4)">
    <button class="btn btn-primary" type="submit"><?= $neu ? 'Benutzer anlegen' : 'Speichern' ?></button>
    <a class="btn btn-ghost" href="?p=benutzer">Abbrechen</a>
  </div>
</form>
<?php render_footer(); ?>
