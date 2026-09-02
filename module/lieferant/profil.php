<?php
// Lieferantenportal – eigene Daten pflegen. Route: ?p=lieferant_profil
// Der Lieferant pflegt Kontakt UND Firmendaten selbst; er weiß am besten, wie seine
// Firma heißt und wo sie sitzt. Konditionen und Preise bleiben bei uns.
require_once BX_ROOT . '/module/lieferant/portal_layout.php';
if (!ist_lieferant()) { header('Location: ?p=lieferant_login'); exit; }

$lid = aktueller_lieferant_id();
$fehler = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $t = fn(string $k, int $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    $spr = in_array((string)($_POST['sprache'] ?? ''), ['de', 'en'], true) ? $_POST['sprache'] : 'de';
    $firma = $t('firma', 190);
    if ($firma === '') {
        $fehler = lp_t('firma') . ' – ' . (lp_sprache() === 'de' ? 'Pflichtfeld.' : 'required.');
    } else {
        q("UPDATE lieferanten SET firma=?, ansprechpartner=?, email=?, telefon=?, wechat=?, whatsapp=?,
                  strasse=?, hausnummer=?, plz=?, ort=?, land=?, webseite=?, ust_id=?, sprache=? WHERE id=?",
          [$firma, $t('ansprechpartner', 190), $t('email', 190), $t('telefon', 60), $t('wechat', 80), $t('whatsapp', 40),
           $t('strasse', 190), $t('hausnummer', 20), $t('plz', 20), $t('ort', 120), mb_strtoupper($t('land', 5)),
           $t('webseite', 190), $t('ust_id', 40), $spr, $lid]);
        $_SESSION['lp_lang'] = $spr;

        // Logo: nur Bilder, höchstens 2 MB. Das alte wird ersetzt, nicht angesammelt.
        if (!empty($_FILES['logo']['name']) && ($_FILES['logo']['error'] ?? 1) === UPLOAD_ERR_OK) {
            $groesse = (int)($_FILES['logo']['size'] ?? 0);
            $info = @getimagesize($_FILES['logo']['tmp_name']);
            $erlaubt = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];
            if (!$info || !isset($erlaubt[$info[2]])) {
                $fehler = lp_sprache() === 'de' ? 'Nur PNG, JPG oder WebP.' : 'Only PNG, JPG or WebP.';
            } elseif ($groesse > 2 * 1024 * 1024) {
                $fehler = lp_sprache() === 'de' ? 'Die Datei ist größer als 2 MB.' : 'The file is larger than 2 MB.';
            } else {
                if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
                $alt = (string) scalar("SELECT logo FROM lieferanten WHERE id=?", [$lid]);
                $fn = 'lieferant_' . $lid . '_' . bin2hex(random_bytes(5)) . '.' . $erlaubt[$info[2]];
                if (move_uploaded_file($_FILES['logo']['tmp_name'], BX_UPLOADS . '/' . $fn)) {
                    q("UPDATE lieferanten SET logo=? WHERE id=?", [$fn, $lid]);
                    if ($alt !== '') @unlink(BX_UPLOADS . '/' . basename($alt));
                }
            }
        }
        if ($fehler === '') { header('Location: ?p=lieferant_profil&ok=1'); exit; }
    }
}
$lf = aktueller_lieferant();
$v  = fn(string $k) => h((string)($lf[$k] ?? ''));

lp_head('bulkify – ' . lp_t('profil'));
lp_shell_start('lieferant_profil');
if (isset($_GET['ok'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h(lp_t('gespeichert')) . '</div>';
if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h($fehler) . '</div>';
?>
<div class="bx-row" style="justify-content:space-between;align-items:center">
  <h1 style="margin:0 0 4px"><?= h(lp_t('profil')) ?></h1>
  <?= lp_sprachwahl() ?>
</div>
<p class="bx-sub"><?= h(lp_t('nur_wir')) ?></p>

<form method="post" enctype="multipart/form-data" style="max-width:760px">
  <div class="bx-panel">
    <h2 style="margin-top:0"><?= h(lp_t('firmendaten')) ?></h2>
    <div class="bx-grid">
      <div class="bx-field" style="grid-column:1/-1"><label><?= h(lp_t('firma')) ?></label>
        <input type="text" name="firma" required value="<?= $v('firma') ?>"></div>
      <div class="bx-field" style="grid-column:1/-1"><label><?= h(lp_t('strasse')) ?></label>
        <div class="bx-row" style="gap:10px">
          <input type="text" name="strasse" value="<?= $v('strasse') ?>" style="flex:1">
          <input type="text" name="hausnummer" value="<?= $v('hausnummer') ?>" style="width:110px" placeholder="Nr.">
        </div></div>
      <div class="bx-field"><label><?= h(lp_t('plz')) ?></label><input type="text" name="plz" value="<?= $v('plz') ?>"></div>
      <div class="bx-field"><label><?= h(lp_t('ort')) ?></label><input type="text" name="ort" value="<?= $v('ort') ?>"></div>
      <div class="bx-field"><label><?= h(lp_t('land')) ?> <span class="muted">(DE, CN, NL …)</span></label>
        <input type="text" name="land" maxlength="5" value="<?= $v('land') ?>"></div>
      <div class="bx-field"><label><?= h(lp_t('ustid')) ?></label><input type="text" name="ust_id" value="<?= $v('ust_id') ?>"></div>
      <div class="bx-field" style="grid-column:1/-1"><label><?= h(lp_t('webseite')) ?></label>
        <input type="text" name="webseite" value="<?= $v('webseite') ?>" placeholder="https://…"></div>
    </div>
  </div>

  <div class="bx-panel">
    <h2 style="margin-top:0"><?= h(lp_t('kontakt')) ?></h2>
    <div class="bx-grid">
      <div class="bx-field"><label><?= h(lp_t('ansprechpartner')) ?></label>
        <input type="text" name="ansprechpartner" value="<?= $v('ansprechpartner') ?>"></div>
      <div class="bx-field"><label><?= h(lp_t('email')) ?></label>
        <input type="email" name="email" value="<?= $v('email') ?>"></div>
      <div class="bx-field"><label><?= h(lp_t('telefon')) ?></label>
        <input type="text" name="telefon" value="<?= $v('telefon') ?>"></div>
      <div class="bx-field"><label><?= h(lp_t('wechat')) ?></label>
        <input type="text" name="wechat" value="<?= $v('wechat') ?>"></div>
      <div class="bx-field"><label><?= h(lp_t('whatsapp')) ?></label>
        <input type="text" name="whatsapp" value="<?= $v('whatsapp') ?>" placeholder="+86 …"></div>
      <div class="bx-field"><label><?= h(lp_t('sprache')) ?></label>
        <select name="sprache">
          <option value="de"<?= strtolower((string)$lf['sprache']) === 'de' ? ' selected' : '' ?>>Deutsch</option>
          <option value="en"<?= strtolower((string)$lf['sprache']) !== 'de' ? ' selected' : '' ?>>English</option>
        </select></div>
    </div>
  </div>

  <div class="bx-panel">
    <h2 style="margin-top:0"><?= h(lp_t('logo')) ?></h2>
    <?php if (!empty($lf['logo'])): ?>
      <div style="margin-bottom:10px"><img src="?p=lieferant_logo" alt="" style="max-height:70px;max-width:240px"></div>
    <?php endif; ?>
    <div class="bx-field" style="margin:0"><input type="file" name="logo" accept="image/png,image/jpeg,image/webp">
      <div class="muted" style="font-size:12px;margin-top:4px"><?= h(lp_t('logo_hinweis')) ?></div></div>
  </div>

  <button class="btn btn-primary" type="submit"><?= h(lp_t('speichern')) ?></button>
</form>
<?php lp_shell_ende(); lp_foot();
