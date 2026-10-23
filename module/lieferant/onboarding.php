<?php
// Pflicht-Onboarding für NEUE Lieferanten (Erstlogin): mehrstufiger Assistent, der die Stammdaten + Anschrift
// einsammelt, bevor das Portal nutzbar ist. Gated über index.php (lieferanten.onboarding_fertig). Mehrsprachig
// (de/en/zh) über die Portalsprache, Rahmen via lp_head()/lp_foot() (bewusst OHNE Menü-Shell – noch nicht frei).
require_once BX_ROOT . '/module/lieferant/portal_layout.php';

// Nur echte Lieferanten durchlaufen den Assistenten; Admin-Vorschau einfach ins Portal.
if (!function_exists('ist_echter_lieferant') || !ist_echter_lieferant()) { header('Location: ?p=lieferant_portal'); exit; }

$lid = aktueller_lieferant_id();
$lf  = aktueller_lieferant() ?: [];
$lang = lp_sprache();

// Onboarding-eigene Texte (de/en/zh). Gemeinsame Labels kommen aus lp_t().
$T = [
    'titel'    => ['de'=>'Willkommen bei bulkify','en'=>'Welcome to bulkify','zh'=>'欢迎来到 bulkify'],
    'sub'      => ['de'=>'Bitte vervollständigen Sie einmalig Ihre Lieferantendaten. Danach steht Ihnen das Portal offen.','en'=>'Please complete your supplier details once. After that the portal is fully available to you.','zh'=>'请一次性完善您的供应商资料，之后即可使用整个门户。'],
    'schritt'  => ['de'=>'Schritt','en'=>'Step','zh'=>'步骤'],
    'von'      => ['de'=>'von','en'=>'of','zh'=>'/'],
    's1'       => ['de'=>'Firma & Kontakt','en'=>'Company & contact','zh'=>'公司与联系人'],
    's2'       => ['de'=>'Anschrift','en'=>'Address','zh'=>'地址'],
    'firma'    => ['de'=>'Firma','en'=>'Company','zh'=>'公司名称'],
    'telefon'  => ['de'=>'Telefon','en'=>'Phone','zh'=>'电话'],
    'webseite' => ['de'=>'Webseite (optional)','en'=>'Website (optional)','zh'=>'网站（可选）'],
    'strasse'  => ['de'=>'Straße','en'=>'Street','zh'=>'街道'],
    'nr'       => ['de'=>'Nr.','en'=>'No.','zh'=>'门牌号'],
    'plz'      => ['de'=>'PLZ','en'=>'Postal code','zh'=>'邮编'],
    'ort'      => ['de'=>'Ort','en'=>'City','zh'=>'城市'],
    'land'     => ['de'=>'Land (2 Buchstaben)','en'=>'Country (2 letters)','zh'=>'国家（两位字母）'],
    'ust'      => ['de'=>'USt-IdNr. / Steuernummer (optional)','en'=>'VAT / tax ID (optional)','zh'=>'税号（可选）'],
    'weiter'   => ['de'=>'Weiter','en'=>'Next','zh'=>'下一步'],
    'zurueck'  => ['de'=>'Zurück','en'=>'Back','zh'=>'上一步'],
    'fertig'   => ['de'=>'Speichern & Portal öffnen','en'=>'Save & open portal','zh'=>'保存并进入门户'],
    'err_name' => ['de'=>'Bitte einen Ansprechpartner angeben.','en'=>'Please enter a contact person.','zh'=>'请填写联系人。'],
    'err_adr'  => ['de'=>'Bitte Straße, PLZ und Ort angeben.','en'=>'Please enter street, postal code and city.','zh'=>'请填写街道、邮编和城市。'],
];
$t = function (string $k) use ($T, $lang): string { return $T[$k][$lang] ?? $T[$k]['en'] ?? $k; };

$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'onboarding_save') {
    $g = fn($k) => trim((string)($_POST[$k] ?? ''));
    if ($g('ansprechpartner') === '')                               $fehler = $t('err_name');
    elseif ($g('strasse') === '' || $g('plz') === '' || $g('ort') === '') $fehler = $t('err_adr');
    if ($fehler === '') {
        q("UPDATE lieferanten SET ansprechpartner=?, email=?, telefon=?, strasse=?, hausnummer=?, plz=?, ort=?, land=?, ust_id=?, webseite=?, onboarding_fertig=1 WHERE id=?",
          [$g('ansprechpartner'), $g('email') ?: null, $g('telefon') ?: null, $g('strasse'), $g('hausnummer') ?: null,
           $g('plz'), $g('ort'), (strtoupper($g('land')) ?: 'DE'), $g('ust_id') ?: null, $g('webseite') ?: null, $lid]);
        header('Location: ?p=lieferant_portal&onboarded=1'); exit;
    }
    // bei Fehler: Eingaben zurückspielen
    foreach (['ansprechpartner','email','telefon','strasse','hausnummer','plz','ort','land','ust_id','webseite'] as $f) $lf[$f] = $_POST[$f] ?? ($lf[$f] ?? '');
}
$v = fn($k) => h((string)($lf[$k] ?? ''));

lp_head('bulkify – ' . $t('titel'));
?>
<div style="max-width:560px;margin:5vh auto;padding:0 16px">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
    <img src="assets/bulkify-logo-dark.png" alt="bulkify" style="height:34px">
    <?= lp_sprachwahl() ?>
  </div>
  <div class="bx-panel">
    <h1 style="margin:0 0 4px;font-size:22px"><?= h($t('titel')) ?></h1>
    <p class="bx-sub" style="margin-top:0"><?= h($t('sub')) ?></p>
    <?php if ($fehler !== ''): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:10px 14px;margin-bottom:12px"><?= h($fehler) ?></div><?php endif; ?>
    <div class="muted" style="font-size:12px;margin-bottom:10px"><?= h($t('schritt')) ?> <span id="obStepNo">1</span> <?= h($t('von')) ?> 2 · <span id="obStepName"><?= h($t('s1')) ?></span></div>

    <form method="post" id="obForm" novalidate>
      <input type="hidden" name="aktion" value="onboarding_save">

      <!-- Schritt 1: Firma & Kontakt -->
      <div class="ob-step" data-step="1">
        <div class="bx-field"><label><?= h($t('firma')) ?></label><input type="text" value="<?= $v('firma') ?>" disabled></div>
        <div class="bx-field"><label><?= h(lp_t('ansprechpartner')) ?> *</label><input type="text" name="ansprechpartner" required value="<?= $v('ansprechpartner') ?>"></div>
        <div class="bx-field"><label><?= h(lp_t('email')) ?></label><input type="email" name="email" value="<?= $v('email') ?>" autocomplete="email"></div>
        <div class="bx-field"><label><?= h($t('telefon')) ?></label><input type="text" name="telefon" value="<?= $v('telefon') ?>"></div>
        <div class="bx-field"><label><?= h($t('webseite')) ?></label><input type="text" name="webseite" value="<?= $v('webseite') ?>" placeholder="https://"></div>
      </div>

      <!-- Schritt 2: Anschrift -->
      <div class="ob-step" data-step="2" hidden>
        <div class="bx-row" style="gap:10px">
          <div class="bx-field" style="flex:3"><label><?= h($t('strasse')) ?> *</label><input type="text" name="strasse" required value="<?= $v('strasse') ?>"></div>
          <div class="bx-field" style="flex:1"><label><?= h($t('nr')) ?></label><input type="text" name="hausnummer" value="<?= $v('hausnummer') ?>"></div>
        </div>
        <div class="bx-row" style="gap:10px">
          <div class="bx-field" style="flex:1"><label><?= h($t('plz')) ?> *</label><input type="text" name="plz" required value="<?= $v('plz') ?>"></div>
          <div class="bx-field" style="flex:2"><label><?= h($t('ort')) ?> *</label><input type="text" name="ort" required value="<?= $v('ort') ?>"></div>
          <div class="bx-field" style="flex:1"><label><?= h($t('land')) ?></label><input type="text" name="land" maxlength="2" value="<?= $v('land') ?: 'DE' ?>"></div>
        </div>
        <div class="bx-field"><label><?= h($t('ust')) ?></label><input type="text" name="ust_id" value="<?= $v('ust_id') ?>"></div>
      </div>

      <div class="bx-row" style="justify-content:space-between;gap:10px;margin-top:14px">
        <button type="button" class="btn btn-ghost" id="obBack" hidden><?= h($t('zurueck')) ?></button>
        <div style="flex:1"></div>
        <button type="button" class="btn btn-primary" id="obNext"><?= h($t('weiter')) ?></button>
        <button type="submit" class="btn btn-primary" id="obDone" hidden><?= h($t('fertig')) ?></button>
      </div>
    </form>
  </div>
</div>
<script>
(function(){
  var form=document.getElementById('obForm'), steps=form.querySelectorAll('.ob-step');
  var back=document.getElementById('obBack'), next=document.getElementById('obNext'), done=document.getElementById('obDone');
  var noEl=document.getElementById('obStepNo'), nameEl=document.getElementById('obStepName');
  var names=[<?= json_encode($t('s1'), JSON_UNESCAPED_UNICODE) ?>,<?= json_encode($t('s2'), JSON_UNESCAPED_UNICODE) ?>];
  var cur=1, total=steps.length;
  function show(n){
    cur=n;
    steps.forEach(function(s){ s.hidden = (+s.dataset.step!==n); });
    back.hidden = n===1; next.hidden = n===total; done.hidden = n!==total;
    noEl.textContent=n; nameEl.textContent=names[n-1]||'';
    var f=form.querySelector('.ob-step[data-step="'+n+'"] input:not([disabled])'); if(f) try{f.focus();}catch(e){}
  }
  function stepValid(n){
    var ins=form.querySelectorAll('.ob-step[data-step="'+n+'"] input:not([disabled])');
    for(var i=0;i<ins.length;i++){ if(!ins[i].checkValidity()){ ins[i].reportValidity(); return false; } }
    return true;
  }
  next.addEventListener('click',function(){ if(stepValid(cur)) show(Math.min(cur+1,total)); });
  back.addEventListener('click',function(){ show(Math.max(cur-1,1)); });
  form.addEventListener('submit',function(e){ if(!stepValid(total)){ e.preventDefault(); } });
  show(1);
})();
</script>
<?php lp_foot();
