<?php
// Öffentliche Lieferanten-Bewerbung (Selbst-Registrierung). Route: ?p=lieferant_bewerbung (öffentlich).
// Der Lieferant trägt seine Daten selbst ein (mehrsprachig DE/EN/ZH) und legt ein Passwort fest. Daraus
// entsteht ein GESPERRTER Lieferant + INAKTIVER Login – Zugang erst nach Freigabe durch das Team.
require_once BX_ROOT . '/module/lieferant/portal_layout.php';

// Sprache: Besucherwahl, sonst Standard Englisch (internationale Lieferanten).
if (empty($_SESSION['lp_lang']) && ($_GET['lang'] ?? '') === '') $_SESSION['lp_lang'] = 'en';

$KATS = ['rohstoff','verpackung','verbrauch','maschine','labor','fertigprodukt'];
$fehler = ''; $ok = false; $v = fn($f) => h((string)($_POST[$f] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $r = lieferant_bewerbung_anlegen([
        'firma'          => $_POST['firma'] ?? '',
        'ansprechpartner'=> $_POST['ansprechpartner'] ?? '',
        'email'          => $_POST['email'] ?? '',
        'telefon'        => $_POST['telefon'] ?? '',
        'webseite'       => $_POST['webseite'] ?? '',
        'land'           => $_POST['land'] ?? '',
        'sprache'        => $_SESSION['lp_lang'] ?? 'en',
        'waehrung'       => $_POST['waehrung'] ?? 'USD',
        'kategorien'     => (array)($_POST['kategorien'] ?? []),
        'nachricht'      => $_POST['nachricht'] ?? '',
        'passwort'       => $_POST['passwort'] ?? '',
    ]);
    $ok = !empty($r['ok']);
    $fehler = $r['fehler'] ?? '';
}

lp_head('bulkify – ' . lp_t('bew_titel'));
?>
<div class="bx-shell"><aside class="bx-side">
  <div class="bx-brand"><img src="assets/bulkify-logo-white.png" alt="bulkify" class="bx-logo"><span class="bx-ver"><?= h(lp_t('portal')) ?></span></div>
</aside>
<main class="bx-main">
  <div class="bx-row" style="justify-content:space-between;align-items:center;max-width:620px">
    <h1 style="margin:0 0 4px"><?= h(lp_t('bew_titel')) ?></h1>
    <?= lp_sprachwahl() ?>
  </div>

<?php if ($ok): ?>
  <div class="bx-panel" style="max-width:620px;border-color:var(--gruen)">
    <h2 style="margin-top:0"><?= h(lp_t('bew_danke_titel')) ?></h2>
    <p class="muted"><?= h(lp_t('bew_danke_text')) ?></p>
    <a class="btn btn-primary" href="?p=lieferant_login"><?= h(lp_t('bew_zum_login')) ?></a>
  </div>
<?php else: ?>
  <p class="bx-sub" style="max-width:620px"><?= h(lp_t('bew_sub')) ?></p>
  <?php if ($fehler): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px;max-width:620px"><?= h($fehler) ?></div><?php endif; ?>
  <div class="bx-panel" style="max-width:620px">
    <form method="post">
      <div class="bx-grid">
        <div class="bx-field"><label><?= h(lp_t('bew_firma')) ?> *</label><input type="text" name="firma" required value="<?= $v('firma') ?>"></div>
        <div class="bx-field"><label><?= h(lp_t('ansprechpartner')) ?></label><input type="text" name="ansprechpartner" value="<?= $v('ansprechpartner') ?>" autocomplete="name"></div>
        <div class="bx-field"><label><?= h(lp_t('email')) ?> *</label><input type="email" name="email" required value="<?= $v('email') ?>" autocomplete="email"></div>
        <div class="bx-field"><label><?= h(lp_t('telefon')) ?></label><input type="text" name="telefon" value="<?= $v('telefon') ?>"></div>
        <div class="bx-field"><label><?= h(lp_t('bew_land')) ?></label><input type="text" name="land" maxlength="2" placeholder="CN" value="<?= $v('land') ?>" style="max-width:120px"></div>
        <div class="bx-field"><label><?= h(lp_t('bew_webseite')) ?></label><input type="text" name="webseite" value="<?= $v('webseite') ?>"></div>
        <div class="bx-field"><label><?= h(lp_t('bew_waehrung')) ?></label>
          <select name="waehrung"><?php foreach (['USD','EUR','CNY'] as $w): ?><option value="<?= $w ?>"<?= ($_POST['waehrung'] ?? 'USD') === $w ? ' selected' : '' ?>><?= $w ?></option><?php endforeach; ?></select>
        </div>
      </div>

      <div class="bx-field" style="margin-top:6px"><label><?= h(lp_t('bew_kategorien')) ?></label>
        <div class="bx-row" style="flex-wrap:wrap;gap:12px;margin-top:4px">
          <?php $sel = (array)($_POST['kategorien'] ?? []); foreach ($KATS as $kk): ?>
            <label style="display:inline-flex;align-items:center;gap:6px;font-weight:400">
              <input type="checkbox" name="kategorien[]" value="<?= $kk ?>"<?= in_array($kk, $sel, true) ? ' checked' : '' ?>> <?= h(lp_t('kat_' . $kk)) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="bx-field"><label><?= h(lp_t('bew_nachricht')) ?></label><textarea name="nachricht" rows="4"><?= $v('nachricht') ?></textarea></div>
      <div class="bx-field"><label><?= h(lp_t('bew_pw_neu')) ?> *</label><input type="password" name="passwort" required minlength="8" autocomplete="new-password"></div>

      <button class="btn btn-primary" type="submit" style="margin-top:6px"><?= h(lp_t('bew_absenden')) ?></button>
    </form>
  </div>
  <div style="max-width:620px;margin-top:10px"><a class="btn btn-ghost btn-sm" href="?p=lieferant_login"><?= h(lp_t('bew_zum_login')) ?></a></div>
<?php endif; ?>
</main></div>
<?php lp_foot();
