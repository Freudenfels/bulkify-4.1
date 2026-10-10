<?php
// Mitarbeiter-Vollbild-App (Kiosk/Tablet) – rein schrittweise Produktion. Eigener PIN-Login
// (benutzer.pin_hash, vom Admin im Dashboard gesetzt). Kein Team-Login nötig. Nutzt dieselben
// Schritt-/Material-Funktionen wie ?p=run, aber großflächig/touch und ohne Sidebar/Abkürzungen.
// Mehrsprachig (DE/EN/UK) – der Mitarbeiter wählt die Sprache selbst (siehe core/werk_i18n.php).

require_once __DIR__ . '/../../core/werk_i18n.php';

// Sprache umschalten (GET-Link ?setlang=) – setzen und ohne Parameter zurück.
$id = (int)($_GET['id'] ?? 0);
if (isset($_GET['setlang'])) { werk_lang_setzen((string)$_GET['setlang']); weiter('?p=werk' . ($id > 0 ? '&id=' . $id : '')); }

$lang = werk_lang();
$T    = werk_texte($lang);

$werkUid  = (int)($_SESSION['werk_uid'] ?? 0);
$werkName = '';
if ($werkUid) { $wu = erp_benutzer($werkUid); if ($wu) { $werkName = (string)$wu['name']; } else { $werkUid = 0; unset($_SESSION['werk_uid']); } }

// ---- POST (Login / Logout / Schritt erledigen) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    if ($aktion === 'werk_login') {
        $u = erp_benutzer_per_pin((string)($_POST['pin'] ?? ''));
        if ($u) { $_SESSION['werk_uid'] = (int)$u['id']; weiter('?p=werk'); }
        $_SESSION['werk_fehler'] = $T['pin_wrong']; weiter('?p=werk');
    }
    if ($aktion === 'werk_logout') { unset($_SESSION['werk_uid']); weiter('?p=werk'); }
    if ($werkUid && $aktion === 'werk_erledigt') {
        $sid = (int)($_POST['schritt_id'] ?? 0);
        $paId = (int)($_POST['pa_id'] ?? 0);
        // Scan-Pflicht: jede Pflicht-Charge dieses Schritts muss bestätigt (gescannt) sein, bevor abgeschlossen wird.
        $station = '';
        foreach (erp_pa_schritte($paId) as $s) if ((int)$s['id'] === $sid) { $station = (string)$s['station']; break; }
        if ($station !== '') {
            $need = [];
            foreach ((erp_schritt_material($paId, $station)['zeilen'] ?? []) as $z)
                if (($z['pflicht'] ?? true) && !empty($z['charge_id'])) $need[(int)$z['charge_id']] = true;
            if ($need) {
                $have = array_flip(array_filter(array_map('intval', explode(',', (string)($_POST['scanned'] ?? '')))));
                $offen = 0; foreach (array_keys($need) as $cid) if (!isset($have[$cid])) $offen++;
                if ($offen > 0) {
                    $_SESSION['werk_flash'] = sprintf($T['fl_scan_first'], $offen);
                    $_SESSION['werk_flash_ok'] = false;
                    weiter('?p=werk&id=' . $paId);
                }
            }
        }
        $r = erp_schritt_abschliessen($sid, $werkName ?: 'Mitarbeiter');
        // Mischen: je Mischbehälter eine Gebinde-Untercharge anlegen (ein Etikett je Behälter, FEFO beim Abfüllen).
        if (!empty($r['ok']) && ($r['station'] ?? '') === 'Mischen') {
            $cap = (float) str_replace(',', '.', (string)($_POST['cap'] ?? ''));
            if ($cap > 0 && function_exists('erp_mischer_unterchargen_anlegen')) {
                $wn = $werkName ?: 'Mitarbeiter';
                if (function_exists('pr_daten_setzen')) pr_daten_setzen($paId, 'kg_pro_gebinde', rtrim(rtrim(number_format($cap, 3, '.', ''), '0'), '.'), $wn);
                if (function_exists('erp_prod_charge_fuer_station')) erp_prod_charge_fuer_station($paId, 'Mischen', 0, null, $wn);
                erp_mischer_unterchargen_anlegen($paId, $cap, $wn);
            }
        }
        $_SESSION['werk_flash']    = $r['ok'] ? (($r['fertig'] ?? false) ? $T['fl_finished'] : $T['fl_step_done']) : ($r['msg'] ?: $T['fl_step_fail']);
        $_SESSION['werk_flash_ok'] = !empty($r['ok']);
        weiter('?p=werk&id=' . $paId . (!empty($r['fertig']) ? '&fertig=1' : ''));
    }
    if ($werkUid && $aktion === 'werk_rueckgabe') {
        $paId = (int)($_POST['pa_id'] ?? 0);
        $g = [];
        foreach ((array)($_POST['rest'] ?? []) as $cid => $val) {
            $cid = (int)$cid; if ($cid <= 0) continue;
            $g[$cid] = (float) str_replace(',', '.', (string)$val);
        }
        $n = erp_rohstoff_rueckgabe_speichern($paId, $g, $werkName ?: 'Mitarbeiter');
        $_SESSION['werk_flash'] = $T['fl_return_saved'] . ($n > 0 ? ' (' . $n . ')' : '') . '.';
        $_SESSION['werk_flash_ok'] = true;
        weiter('?p=werk&id=' . $paId);
    }
    if ($werkUid && $aktion === 'werk_probe') {
        $paId = (int)($_POST['pa_id'] ?? 0);
        erp_rohstoff_probe_ziehen($paId, (int)($_POST['charge_id'] ?? 0), (int)($_POST['item_id'] ?? 0), $werkName ?: 'Mitarbeiter');
        $_SESSION['werk_flash'] = $T['fl_sample_ok']; $_SESSION['werk_flash_ok'] = true;
        weiter('?p=werk&id=' . $paId);
    }
    if ($werkUid && $aktion === 'werk_blink') {
        // Pick-to-Light: den Blinker der FEFO-Charge im Lager leuchten lassen (gleiche Kette wie ?p=run).
        $cid   = (int)($_POST['charge_id'] ?? 0);
        $modus = ((string)($_POST['modus'] ?? 'an') === 'aus') ? 'aus' : 'an';
        $r = (function_exists('pr_lager_blink') && $cid > 0)
           ? pr_lager_blink($cid, $modus)
           : ['ok' => false, 'meldung' => 'Keine Charge für den Blinker.'];
        if (!empty($_POST['js'])) {   // AJAX vom Tablet: kein Reload, nur kurze Rückmeldung am Button
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => !empty($r['ok']), 'meldung' => (string)($r['meldung'] ?? '')]);
            exit;
        }
        $_SESSION['werk_flash'] = !empty($r['ok']) ? ($modus === 'aus' ? $T['fl_blink_off'] : $T['fl_blink_on']) : $T['fl_blink_fail'];
        $_SESSION['werk_flash_ok'] = !empty($r['ok']);
        weiter('?p=werk&id=' . (int)($_POST['pa_id'] ?? 0));
    }
    weiter('?p=werk');
}

$flash   = $_SESSION['werk_flash'] ?? ''; $flashOk = !empty($_SESSION['werk_flash_ok']);
$fehler  = $_SESSION['werk_fehler'] ?? '';
unset($_SESSION['werk_flash'], $_SESSION['werk_flash_ok'], $_SESSION['werk_fehler']);

// Pick-to-Light: großer Touch-Button, der den Blinker der FEFO-Charge im Lager leuchten lässt (AJAX, ohne Reload).
$blinkBtn = function($cid, string $extra = '') use ($T) {
    $cid = (int)$cid;
    if ($cid <= 0) return '<span class="muted" style="font-size:14px">' . h($T['no_blinker']) . '</span>';
    return '<button type="button" class="werk-blink btn btn-ghost" data-cid="' . $cid . '"'
         . ' style="min-height:48px;padding:10px 18px;font-size:16px;' . $extra . '">' . h($T['show_location']) . '</button>';
};

// ---- Ausgabe: eigenes Vollbild-Dokument (keine Sidebar) ----
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="<?= h($lang) ?>"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="theme-color" content="#10210f">
<link rel="manifest" href="/produktion/werk.webmanifest">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Produktion">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
<link rel="icon" href="/assets/app-icon-192.png" type="image/png">
<title>Produktion · Werk</title>
<style>
  :root{--bg:#0f1a12;--panel:#17241a;--panel2:#1e2f22;--line:#2c4232;--text:#e9f1ea;--muted:#9fb6a6;--gruen:#1D9E75;--lime:#C0F24E;--warn:#e0a53a;--err:#e4584e}
  *{box-sizing:border-box}
  html,body{margin:0;height:100%}
  body{background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;font-size:18px;-webkit-text-size-adjust:100%}
  .wrap{max-width:1100px;margin:0 auto;padding:18px 18px 60px}
  .topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 4px;margin-bottom:16px;position:sticky;top:0;z-index:30;background:var(--bg);border-bottom:1px solid var(--line);flex-wrap:wrap}
  .brand{font-weight:800;font-size:22px;letter-spacing:.3px}
  .brand b{color:var(--lime)}
  .who{color:var(--muted);font-size:15px}
  .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:none;border-radius:14px;padding:16px 22px;font-size:19px;font-weight:700;cursor:pointer;text-decoration:none;min-height:60px}
  .btn-primary{background:var(--gruen);color:#06130d}
  .btn-lime{background:var(--lime);color:#15230a}
  .btn-ghost{background:var(--panel2);color:var(--text);border:1px solid var(--line)}
  .btn-lg{font-size:26px;padding:22px 40px;min-height:78px;width:100%}
  .btn[disabled]{opacity:.45;cursor:not-allowed}
  .panel{background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:18px 20px;margin-bottom:16px}
  .muted{color:var(--muted)}
  .flash{border-radius:14px;padding:14px 18px;margin-bottom:16px;font-weight:600}
  .flash.ok{background:rgba(29,158,117,.18);border:1px solid var(--gruen)}
  .flash.err{background:rgba(228,88,78,.16);border:1px solid var(--err)}
  /* Sprach-Umschalter */
  .langsw{display:inline-flex;gap:4px;background:var(--panel2);border:1px solid var(--line);border-radius:12px;padding:3px}
  .langbtn{min-height:40px;display:inline-flex;align-items:center;padding:6px 14px;border-radius:9px;color:var(--muted);text-decoration:none;font-size:15px;font-weight:700}
  .langbtn.on{background:var(--gruen);color:#06130d}
  /* Login */
  .login{max-width:420px;margin:4vh auto 0;text-align:center}
  .pindisp{font-size:42px;letter-spacing:14px;min-height:56px;margin:14px 0 18px;font-weight:800}
  .keys{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
  .key{background:var(--panel2);border:1px solid var(--line);color:var(--text);border-radius:16px;font-size:30px;font-weight:700;padding:20px 0;cursor:pointer;user-select:none}
  .key:active{background:var(--gruen);color:#06130d}
  /* Kacheln */
  .cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px}
  .card{display:block;background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:18px 20px;text-decoration:none;color:var(--text)}
  .card:active{border-color:var(--gruen)}
  .card .nr{font-size:22px;font-weight:800}
  .card .prod{font-size:18px;margin:4px 0}
  .card .meta{color:var(--muted);font-size:15px}
  .badge{display:inline-block;border-radius:999px;padding:4px 12px;font-size:14px;font-weight:700;margin-top:10px}
  .b-lauf{background:rgba(192,242,78,.18);color:var(--lime);border:1px solid var(--lime)}
  .b-bereit{background:rgba(29,158,117,.18);color:#7fe3c2;border:1px solid var(--gruen)}
  .prog{height:10px;border-radius:6px;background:var(--panel2);overflow:hidden;margin-top:12px}
  .prog>div{height:100%;background:var(--gruen)}
  /* Material-Tabelle */
  table.mat{width:100%;border-collapse:collapse;margin-top:6px}
  table.mat th,table.mat td{text-align:left;padding:12px 10px;border-bottom:1px solid var(--line);font-size:18px}
  table.mat th{color:var(--muted);font-size:14px;font-weight:600}
  table.mat td.num,table.mat th.num{text-align:right;white-space:nowrap}
  .knapp{color:#ff8f86}
  .step-h{font-size:30px;font-weight:800;margin:2px 0 6px}
  .step-sub{color:var(--muted);font-size:16px}
  .count{font-size:16px;color:var(--muted)}
  a.back{display:inline-flex;align-items:center;gap:8px;color:var(--text);text-decoration:none;font-size:17px;font-weight:700;background:var(--panel2);border:1px solid var(--line);border-radius:14px;padding:12px 20px;min-height:48px}
  a.back:active{background:var(--gruen);color:#06130d}
</style>
</head><body><div class="wrap">

<?php if (!$werkUid): // ===================== PIN-LOGIN ===================== ?>
  <div class="topbar">
    <div class="brand"><img src="/assets/bulkify-logo-white.png" alt="Produktion" style="height:30px;vertical-align:middle;display:inline-block"></div>
    <?= werk_lang_switcher($lang, $id) ?>
  </div>
  <div class="login">
    <div style="font-size:22px;margin-top:10px"><?= h($T['pin_enter']) ?></div>
    <div class="muted" style="font-size:15px"><?= h($T['pin_hint']) ?></div>
    <?php if ($fehler): ?><div class="flash err" style="margin-top:16px"><?= h($fehler) ?></div><?php endif; ?>
    <form method="post" id="pinform">
      <input type="hidden" name="aktion" value="werk_login">
      <input type="hidden" name="pin" id="pin">
      <div class="pindisp" id="disp"></div>
      <div class="keys">
        <?php foreach (['1','2','3','4','5','6','7','8','9'] as $k): ?><div class="key" data-k="<?= $k ?>"><?= $k ?></div><?php endforeach; ?>
        <div class="key" data-k="del" style="font-size:22px">&#9003;</div>
        <div class="key" data-k="0">0</div>
        <div class="key" data-k="ok" style="background:var(--gruen);color:#06130d;font-size:22px">OK</div>
      </div>
    </form>
    <div style="margin-top:26px">
      <button id="pwaInstall" type="button" class="btn btn-ghost" style="display:none"><?= h($T['install']) ?></button>
      <div class="muted" style="font-size:13px;margin-top:12px"><?= h($T['install_hint']) ?></div>
    </div>
  </div>
  <script>
  (function(){
    var pin='', disp=document.getElementById('disp'), hid=document.getElementById('pin'), f=document.getElementById('pinform');
    function upd(){ disp.textContent = pin.replace(/./g,'•'); }
    document.querySelectorAll('.key').forEach(function(b){ b.addEventListener('click', function(){
      var k=b.getAttribute('data-k');
      if(k==='del'){ pin=pin.slice(0,-1); upd(); return; }
      if(k==='ok'){ if(pin.length>=4){ hid.value=pin; f.submit(); } return; }
      if(pin.length<8){ pin+=k; upd(); }
    });});
    document.addEventListener('keydown',function(e){ if(e.key>='0'&&e.key<='9'&&pin.length<8){pin+=e.key;upd();} else if(e.key==='Backspace'){pin=pin.slice(0,-1);upd();} else if(e.key==='Enter'&&pin.length>=4){hid.value=pin;f.submit();} });
  })();
  </script>

<?php elseif ($id <= 0): // ===================== KACHEL-LISTE ===================== ?>
  <?php
    $jobs = [];
    foreach (erp_produktionsauftraege('') as $pa) {
        $f = (int)$pa['schritte_fertig'];
        $ber = erp_pa_bereitschaft((int)$pa['id'], (string)$pa['status'], $f);
        if ((string)$pa['status'] === 'laufend' || ($ber['status'] ?? '') === 'bereit') { $pa['_lauf'] = (string)$pa['status'] === 'laufend'; $jobs[] = $pa; }
    }
    usort($jobs, function ($a, $b) {   // laufend zuerst, dann nach geplantem Termin
        if (($a['_lauf'] ?? false) !== ($b['_lauf'] ?? false)) return ($a['_lauf'] ?? false) ? -1 : 1;
        $ga = (string)($a['geplant_am'] ?? '') ?: '9999'; $gb = (string)($b['geplant_am'] ?? '') ?: '9999';
        return strcmp($ga, $gb);
    });
  ?>
  <div class="topbar">
    <div class="brand"><img src="/assets/bulkify-logo-white.png" alt="Produktion" style="height:30px;vertical-align:middle;display:inline-block"></div>
    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
      <?= werk_lang_switcher($lang, 0) ?>
      <span class="who"><?= h($werkName) ?></span>
      <form method="post" style="margin:0"><input type="hidden" name="aktion" value="werk_logout"><button class="btn btn-ghost" style="min-height:48px;padding:10px 18px;font-size:16px" type="submit"><?= h($T['logout']) ?></button></form>
    </div>
  </div>
  <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= h($flash) ?></div><?php endif; ?>
  <h1 style="font-size:24px;margin:0 0 14px"><?= h($T['what_produce']) ?></h1>
  <?php if (!$jobs): ?>
    <div class="panel muted"><?= h($T['no_jobs']) ?></div>
  <?php else: ?>
  <div class="cards">
    <?php foreach ($jobs as $pa): $g = (int)$pa['schritte_gesamt']; $f = (int)$pa['schritte_fertig']; $proz = $g > 0 ? round($f * 100 / $g) : 0; ?>
    <a class="card" href="?p=werk&id=<?= (int)$pa['id'] ?>">
      <div class="nr"><?= h((string)$pa['nummer']) ?></div>
      <div class="prod"><?= h((string)($pa['produkt_name'] ?: '–')) ?></div>
      <div class="meta"><?= menge_txt($pa['menge']) ?> <?= h($T['packages']) ?><?= !empty($pa['kunde']) ? ' · ' . h((string)$pa['kunde']) : '' ?></div>
      <span class="badge <?= !empty($pa['_lauf']) ? 'b-lauf' : 'b-bereit' ?>"><?= !empty($pa['_lauf']) ? h($T['in_production']) : h($T['producible']) ?></span>
      <div class="prog"><div style="width:<?= (int)$proz ?>%"></div></div>
      <div class="meta" style="margin-top:6px"><?= $g > 0 ? h($T['step']) . ' ' . $f . ' / ' . $g : h($T['ready']) ?></div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

<?php else: // ===================== SCHRITT-ANSICHT ===================== ?>
  <?php
    $pa = erp_pa($id);
    if (!$pa) { echo '<div class="panel">' . h($T['order_not_found']) . ' <a class="back" href="?p=werk">' . h($T['back']) . '</a></div></div></body></html>'; return; }
    $schritte = erp_pa_schritte($id);
    $total = count($schritte);
    $fertigCnt = 0; foreach ($schritte as $s) if ((int)$s['erledigt'] === 1) $fertigCnt++;
    $cur = null; foreach ($schritte as $s) if ((int)$s['erledigt'] === 0) { $cur = $s; break; }
    $alleFertig = $total > 0 && $fertigCnt >= $total;
    // Rohstoff-Rückgabe als Zwischenschritt: nach dem Mischen den geholten Rohstoff zurücklegen (Rest-Gewicht),
    // solange noch nicht erfasst und es überhaupt verbrauchte Rohstoff-Chargen gibt.
    $mischenDone = false; foreach ($schritte as $s) if ((string)$s['station'] === 'Mischen' && (int)$s['erledigt'] === 1) { $mischenDone = true; break; }
    $rueckListe = ($mischenDone && !erp_rohstoff_rueckgabe_erledigt($id)) ? erp_rohstoff_rueckgabe_offen($id) : [];
    $rueckOffen = !empty($rueckListe);
    // Chargenprobe VOR dem Mischen: je Rohstoff-Charge eine Probe (prod_probe). Erst danach geht es ans Mischen.
    $probenListe = ($cur && (string)$cur['station'] === 'Mischen') ? erp_rohstoff_proben_status($id) : [];
    $probenPflicht = false; foreach ($probenListe as $pr) if (empty($pr['hat_probe'])) { $probenPflicht = true; break; }
  ?>
  <div class="topbar">
    <a class="back" href="?p=werk">&larr; <?= h($T['back_all']) ?></a>
    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap"><?= werk_lang_switcher($lang, $id) ?><span class="who"><?= h($werkName) ?></span>
      <form method="post" style="margin:0"><input type="hidden" name="aktion" value="werk_logout"><button class="btn btn-ghost" style="min-height:44px;padding:8px 16px;font-size:15px" type="submit"><?= h($T['logout']) ?></button></form>
    </div>
  </div>
  <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= h($flash) ?></div><?php endif; ?>

  <div class="panel" style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
    <div><span style="font-size:24px;font-weight:800"><?= h((string)$pa['nummer']) ?></span> · <?= h((string)($pa['produkt_name'] ?? '–')) ?></div>
    <div class="count"><?= menge_txt($pa['menge']) ?> <?= h($T['packages']) ?> · <?= h($T['step']) ?> <?= min($fertigCnt + 1, $total) ?> / <?= $total ?></div>
  </div>

  <?php if ($probenPflicht): ?>
    <div class="panel" style="border-color:var(--lime)">
      <div class="step-sub"><?= h($T['before_mixing']) ?></div>
      <div class="step-h"><?= h($T['draw_sample_h']) ?></div>
      <div class="muted" style="font-size:17px;margin-bottom:6px"><?= $T['draw_sample_text'] ?></div>
      <table class="mat">
        <thead><tr><th><?= h($T['th_rawmaterial']) ?></th><th><?= h($T['th_charge']) ?></th><th class="num"><?= h($T['th_sample']) ?></th></tr></thead>
        <tbody>
          <?php foreach ($probenListe as $pr): ?>
          <tr>
            <td><?= h((string)$pr['name']) ?></td>
            <td class="muted"><?= h((string)($pr['charge_nr'] ?: '–')) ?><?php if (!empty($pr['charge_id'])): ?><div style="margin-top:6px"><?= $blinkBtn($pr['charge_id']) ?></div><?php endif; ?></td>
            <td class="num">
              <?php if (!empty($pr['hat_probe'])): ?><span style="color:var(--gruen);font-weight:700"><?= h($T['sample_recorded_badge']) ?></span>
              <?php else: ?>
                <form method="post" style="margin:0"><input type="hidden" name="aktion" value="werk_probe"><input type="hidden" name="pa_id" value="<?= (int)$id ?>"><input type="hidden" name="charge_id" value="<?= (int)$pr['charge_id'] ?>"><input type="hidden" name="item_id" value="<?= (int)$pr['item_id'] ?>"><button class="btn btn-ghost" type="submit" style="min-height:48px"><?= h($T['sample_taken_btn']) ?></button></form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="muted" style="font-size:14px;margin-top:12px"><?= h($T['samples_hint']) ?></div>
    </div>
  <?php elseif ($rueckOffen): ?>
    <div class="panel" style="border-color:var(--lime)">
      <div class="step-sub"><?= h($T['after_mixing']) ?></div>
      <div class="step-h"><?= h($T['return_h']) ?></div>
      <div class="muted" style="font-size:17px;margin-bottom:6px"><?= $T['return_text'] ?></div>
      <form method="post">
        <input type="hidden" name="aktion" value="werk_rueckgabe">
        <input type="hidden" name="pa_id" value="<?= (int)$id ?>">
        <table class="mat">
          <thead><tr><th><?= h($T['th_rawmaterial']) ?></th><th><?= h($T['th_charge']) ?></th><th class="num"><?= h($T['th_returned']) ?></th></tr></thead>
          <tbody>
            <?php foreach ($rueckListe as $r): $einh = (string)$r['einheit']; ?>
            <tr>
              <td><?= h((string)$r['item_name']) ?></td>
              <td class="muted"><?= h((string)($r['charge_nr'] ?: '–')) ?><?php if (!empty($r['charge_id'])): ?><div style="margin-top:6px"><?= $blinkBtn($r['charge_id']) ?></div><?php endif; ?></td>
              <td class="num"><input type="text" inputmode="decimal" name="rest[<?= (int)$r['charge_id'] ?>]" placeholder="0" style="font-size:19px;padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:var(--bg);color:var(--text);width:120px;text-align:right"> <?= h($einh) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div style="margin-top:20px"><button class="btn btn-lime btn-lg" type="submit"><?= h($T['return_save']) ?></button></div>
      </form>
    </div>
  <?php elseif ($alleFertig || !$cur): ?>
    <div class="panel" style="text-align:center;border-color:var(--gruen)">
      <div style="font-size:30px;font-weight:800;margin-bottom:8px"><?= h($T['done_h']) ?></div>
      <div class="muted" style="margin-bottom:18px"><?= h($T['done_text']) ?></div>
      <a class="btn btn-primary btn-lg" href="?p=werk" style="max-width:360px;margin:0 auto"><?= h($T['back_to_orders']) ?></a>
    </div>
  <?php else:
    $anl = werk_station_anleitung((string)$cur['station'], $lang);
    $mat = erp_schritt_material($id, (string)$cur['station']);
    $materialFehlt = false;
    foreach (($mat['zeilen'] ?? []) as $z)
        if (($z['pflicht'] ?? true) && empty($z['entnommen']) && isset($z['verfuegbar']) && (float)$z['verfuegbar'] + 0.0001 < (float)$z['menge']) { $materialFehlt = true; break; }
    $isGate = str_contains((string)$cur['station'], 'Freigabe');
    $istMischen = (string)$cur['station'] === 'Mischen';
    $cap  = $istMischen ? (float) str_replace(',', '.', (string)($_GET['cap'] ?? '')) : 0.0;
    $plan = $istMischen ? erp_mischer_plan($id, $cap) : null;
    $nz   = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
    // Scan-Pflicht: jede Pflicht-Zeile mit bekannter Charge muss vor dem Abschließen gescannt werden.
    $scanAktiv = false;
    foreach (($mat['zeilen'] ?? []) as $z)
        if (($z['pflicht'] ?? true) && !empty($z['charge_id'])) { $scanAktiv = true; break; }
  ?>
  <div class="panel" style="border-color:var(--gruen)">
    <div class="step-sub"><?= h($T['now_due']) ?> · <?= h($T['step']) ?> <?= $fertigCnt + 1 ?> <?= h($T['of']) ?> <?= $total ?></div>
    <div class="step-h"><?= h(werk_station_label((string)$cur['station'], $lang)) ?></div>
    <?php if ($anl !== ''): ?><div class="muted" style="font-size:18px;margin-bottom:6px"><?= h($anl) ?></div><?php endif; ?>

    <?php if ($istMischen && $plan && !empty($plan['ok'])): ?>
    <div class="panel" style="background:var(--panel2);border-color:var(--line);margin-top:16px">
      <div class="muted"><?= h($T['mix_total']) ?></div>
      <div style="font-size:24px;font-weight:800"><?= $nz($plan['total_kg'] ?? 0) ?> kg <span class="muted" style="font-size:16px;font-weight:400">(<?= number_format((int)($plan['einheiten'] ?? 0), 0, ',', '.') ?> <?= h($T['units']) ?>)</span></div>
      <form method="get" style="margin-top:12px;display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="p" value="werk"><input type="hidden" name="id" value="<?= (int)$id ?>">
        <div><div class="muted" style="font-size:14px;margin-bottom:4px"><?= h($T['kg_per_container']) ?></div>
          <input type="text" inputmode="decimal" name="cap" value="<?= h($cap > 0 ? $nz($cap) : '') ?>" placeholder="10" style="font-size:20px;padding:12px 14px;border-radius:12px;border:1px solid var(--line);background:var(--bg);color:var(--text);width:150px"></div>
        <button class="btn btn-ghost" type="submit" style="min-height:52px"><?= h($T['calc_containers']) ?></button>
      </form>
      <?php if (!empty($plan['gebinde'])): ?>
      <div class="muted" style="margin:16px 0 10px"><?= sprintf($T['mix_result'], (int)$plan['anzahl']) ?></div>
      <?php foreach ($plan['gebinde'] as $g): ?>
      <div style="border:1px solid var(--line);border-radius:14px;padding:14px 16px;margin-bottom:10px">
        <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:6px">
          <div style="font-size:21px;font-weight:800"><?= h($T['container']) ?> <?= (int)$g['nr'] ?> <span class="muted" style="font-size:15px;font-weight:400">/ <?= (int)$plan['anzahl'] ?></span></div>
          <div style="font-size:18px;font-weight:700"><?= $nz($g['kg']) ?> kg <?= h($T['total_word']) ?></div>
        </div>
        <table class="mat" style="margin-top:8px"><tbody>
          <?php foreach ($g['zutaten'] as $z): ?>
          <tr><td><?= h((string)$z['name']) ?></td><td class="num" style="font-weight:700;color:var(--lime)"><?= $nz($z['kg']) ?> kg</td></tr>
          <?php endforeach; ?>
        </tbody></table>
      </div>
      <?php endforeach; ?>
      <?php else: ?>
      <div class="muted" style="margin-top:10px"><?= h($T['mix_empty_hint']) ?></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($mat['zeilen'])): ?>
    <div class="muted" style="margin-top:14px"><?= h($T['get_from_stock']) ?><?php if (($mat['soll_menge'] ?? null) !== null): ?> · <?= h($T['needed']) ?> <strong style="color:var(--text)"><?= menge_txt($mat['soll_menge']) ?> <?= h((string)($mat['soll_einheit'] ?? '')) ?></strong><?php endif; ?>:</div>
    <table class="mat">
      <thead><tr><th><?= h($T['th_material']) ?></th><th class="num"><?= h($T['th_amount']) ?></th><th class="num"><?= h($T['th_stock']) ?></th><th class="num"><?= h($T['th_location']) ?></th></tr></thead>
      <tbody>
        <?php foreach ($mat['zeilen'] as $z): $pflicht = $z['pflicht'] ?? true;
              $knapp = $pflicht && isset($z['verfuegbar']) && (float)$z['verfuegbar'] + 0.0001 < (float)$z['menge']; ?>
        <tr>
          <td><?= h((string)$z['name']) ?><?php if (!empty($z['detail'])): ?> <span class="muted" style="font-size:14px">· <?= h((string)$z['detail']) ?><?= $pflicht ? '' : ' ' . h($T['info_note']) ?></span><?php endif; ?>
              <?php if (!empty($z['charge_id']) && ($pflicht || !empty($z['charge_nr']))): ?>
              <div class="muted" style="font-size:14px;margin-top:3px">
                <?php if (!empty($z['charge_nr'])): ?><?= h($T['th_charge']) ?> <strong style="color:var(--text)"><?= h((string)$z['charge_nr']) ?></strong> <span style="font-size:13px">(FEFO)</span><?php endif; ?>
                <?php if ($scanAktiv && $pflicht): ?><span class="scanstat" data-cid="<?= (int)$z['charge_id'] ?>" data-cnr="<?= h(strtoupper((string)($z['charge_nr'] ?? ''))) ?>" style="display:inline-block;margin-left:8px;padding:2px 10px;border-radius:999px;font-size:13px;font-weight:700;background:var(--panel2);border:1px solid var(--line);color:var(--muted)"><?= h($T['to_scan']) ?></span><?php endif; ?>
              </div>
              <?php endif; ?></td>
          <td class="num"><?= menge_txt($z['menge']) ?> <?= h((string)$z['einheit']) ?></td>
          <td class="num <?= $knapp ? 'knapp' : '' ?>"><?= isset($z['verfuegbar']) ? menge_txt($z['verfuegbar']) . ' ' . h((string)$z['einheit']) : '–' ?></td>
          <td class="num"><?= $blinkBtn($z['charge_id'] ?? 0) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <?php if ($scanAktiv && !$materialFehlt): ?>
    <div class="panel" style="margin-top:18px;background:var(--panel2);border-color:var(--line)">
      <div style="font-weight:700;font-size:18px;margin-bottom:4px"><?= h($T['confirm_batches_h']) ?></div>
      <div class="muted" style="font-size:14px;margin-bottom:12px"><?= h($T['scan_help']) ?> <span id="scancount" style="color:var(--text);font-weight:600"></span></div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <button type="button" id="camstart" class="btn btn-ghost" style="min-height:52px"><?= h($T['scan_with_cam']) ?></button>
        <input type="text" id="scaninput" autocomplete="off" autocapitalize="characters" placeholder="<?= h($T['scan_placeholder']) ?>" style="flex:1;min-width:180px;font-size:18px;padding:12px 14px;border-radius:12px;border:1px solid var(--line);background:var(--bg);color:var(--text)">
      </div>
      <div id="scanmsg" style="font-size:15px;margin-top:10px;min-height:20px"></div>
      <video id="camview" playsinline muted style="display:none;width:100%;max-width:440px;border-radius:12px;margin-top:12px;background:#000"></video>
    </div>
    <?php endif; ?>

    <div style="margin-top:22px">
      <?php if ($materialFehlt): ?>
        <div class="flash err" style="margin-bottom:14px"><?= h($T['material_missing']) ?></div>
        <button class="btn btn-primary btn-lg" disabled><?= $isGate ? h($T['release']) : h($T['done_btn']) ?></button>
      <?php else: ?>
        <form method="post" onsubmit="return confirm('<?= h($T['confirm_finish']) ?>');">
          <input type="hidden" name="aktion" value="werk_erledigt">
          <input type="hidden" name="schritt_id" value="<?= (int)$cur['id'] ?>">
          <input type="hidden" name="pa_id" value="<?= (int)$id ?>">
          <?php if ($istMischen && $cap > 0): ?><input type="hidden" name="cap" value="<?= h($nz($cap)) ?>"><?php endif; ?>
          <input type="hidden" name="scanned" id="scannedfield" value="">
          <button class="btn btn-lime btn-lg" type="submit" id="erledigtbtn"<?= $scanAktiv ? ' disabled data-scan-gate="1"' : '' ?>><?= $isGate ? h($T['release']) : h($T['done_next']) ?></button>
        </form>
        <?php if ($scanAktiv): ?><div class="muted" id="gatehint" style="font-size:14px;margin-top:8px"><?= h($T['scan_gate_hint']) ?></div><?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php // Ablauf-Überblick (klein) – welche Schritte schon erledigt sind. ?>
  <div class="panel">
    <div class="muted" style="margin-bottom:8px"><?= h($T['flow']) ?></div>
    <?php foreach ($schritte as $i => $s): $done = (int)$s['erledigt'] === 1; $isCur = $cur && (int)$s['id'] === (int)$cur['id']; ?>
      <div style="display:flex;align-items:center;gap:10px;padding:7px 0;<?= $isCur ? 'font-weight:700' : '' ?>">
        <span style="width:24px;text-align:center;color:<?= $done ? 'var(--gruen)' : ($isCur ? 'var(--lime)' : 'var(--muted)') ?>"><?= $done ? '✓' : ($i + 1) ?></span>
        <span style="<?= !$done && !$isCur ? 'color:var(--muted)' : '' ?>"><?= h(werk_station_label((string)$s['station'], $lang)) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

<?php endif; ?>

<script>
var WT = <?= json_encode(werk_js_texte($T), JSON_UNESCAPED_UNICODE) ?>;
// PWA: Service Worker registrieren (Installation am Tablet). Installieren-Button auf dem Login,
// sobald der Browser die Installation anbietet (Android/Chrome). iOS: ueber Teilen -> Zum Startbildschirm.
if ('serviceWorker' in navigator) { window.addEventListener('load', function(){ navigator.serviceWorker.register('/produktion/werk-sw.js').catch(function(){}); }); }
(function(){
  var dp = null, b = document.getElementById('pwaInstall');
  window.addEventListener('beforeinstallprompt', function(e){ e.preventDefault(); dp = e; if (b) b.style.display = 'inline-flex'; });
  if (b) b.addEventListener('click', function(){ if (dp) { dp.prompt(); dp = null; b.style.display = 'none'; } });
  window.addEventListener('appinstalled', function(){ if (b) b.style.display = 'none'; });
})();
// Scan-Bestätigung: jede Pflicht-Charge per Kamera (QR) oder Handscanner bestätigen, erst dann "Erledigt".
(function(){
  var stats = document.querySelectorAll('.scanstat');
  if (!stats.length) return;
  function msg(t, ok){ var m=document.getElementById('scanmsg'); if(m){ m.textContent=t; m.style.color = ok ? 'var(--gruen)' : 'var(--err)'; } }
  function parse(s){
    s = (s||'').trim(); if(!s) return {};
    var m = s.match(/[?&]id=(\d+)/);            // QR-URL .../lager/?p=charge&id=123
    if(m) return {cid:m[1]};
    if(/^\d+$/.test(s)) return {cid:s};          // nackte Charge-ID
    return {cnr:s.toUpperCase()};                // Chargennummer als Text
  }
  function gate(){
    var ok=0; stats.forEach(function(x){ if(x.getAttribute('data-ok')==='1') ok++; });
    var c=document.getElementById('scancount'); if(c) c.textContent = WT.js_confirmed_tpl.replace('%A%',ok).replace('%B%',stats.length);
    var f=document.getElementById('scannedfield');
    if(f){ var ids=[]; stats.forEach(function(x){ if(x.getAttribute('data-ok')==='1') ids.push(x.getAttribute('data-cid')); }); f.value=ids.join(','); }
    var b=document.getElementById('erledigtbtn'), h=document.getElementById('gatehint');
    if(b && b.getAttribute('data-scan-gate')==='1'){ var done = ok>=stats.length; b.disabled=!done; if(h) h.style.display = done ? 'none' : ''; }
  }
  function confirmScan(raw){
    var p=parse(raw), el=null;
    stats.forEach(function(x){ if(el) return;
      if(p.cid && x.getAttribute('data-cid')===String(p.cid)) el=x;
      else if(p.cnr && x.getAttribute('data-cnr') && x.getAttribute('data-cnr')===p.cnr) el=x; });
    if(!el){ msg(WT.js_unknown_charge+raw, false); return false; }
    if(el.getAttribute('data-ok')==='1'){ msg(WT.js_already, true); return true; }
    el.setAttribute('data-ok','1'); el.textContent=WT.js_confirmed_badge;
    el.style.background='rgba(29,158,117,.18)'; el.style.color='#7fe3c2'; el.style.borderColor='var(--gruen)';
    msg(WT.js_charge_ok, true); gate(); return true;
  }
  var inp=document.getElementById('scaninput');
  if(inp) inp.addEventListener('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); if(inp.value.trim()){ confirmScan(inp.value); inp.value=''; } } });
  // Kamera-Scan (Android/Chrome: BarcodeDetector)
  var cam=document.getElementById('camstart'), video=document.getElementById('camview');
  if(cam){
    if(!('BarcodeDetector' in window)){ cam.disabled=true; cam.textContent=WT.js_cam_unsupported; }
    else {
      var stream=null, det=null, run=false;
      function stop(){ run=false; if(stream){ stream.getTracks().forEach(function(t){t.stop();}); stream=null; } if(video){ video.style.display='none'; } cam.textContent=WT.scan_with_cam; }
      async function loop(){ if(!run) return;
        try{ var codes=await det.detect(video); if(codes&&codes.length) confirmScan(codes[0].rawValue); }catch(e){}
        if(run) setTimeout(loop, 400); }
      async function start(){
        try{ det=new BarcodeDetector({formats:['qr_code']});
          stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'}});
          video.srcObject=stream; video.style.display='block'; await video.play(); run=true; cam.textContent=WT.js_cam_off; loop();
        }catch(e){ msg(WT.js_cam_unavailable+(e&&e.message?e.message:e), false); }
      }
      cam.addEventListener('click', function(){ run?stop():start(); });
      window.addEventListener('pagehide', stop);
    }
  }
  gate();
})();
// Pick-to-Light: Blinker im Lager leuchten lassen, ohne die Seite neu zu laden (Scrollposition bleibt).
(function(){
  document.querySelectorAll('.werk-blink').forEach(function(btn){
    btn.addEventListener('click', function(){
      var cid = btn.getAttribute('data-cid'); if (!cid || btn.disabled) return;
      var orig = btn.textContent; btn.disabled = true; btn.textContent = WT.js_showing;
      var body = 'aktion=werk_blink&js=1&charge_id=' + encodeURIComponent(cid);
      fetch('?p=werk', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body})
        .then(function(r){ return r.json(); })
        .then(function(j){ btn.textContent = j && j.ok ? WT.js_lit : WT.js_not_triggered; })
        .catch(function(){ btn.textContent = WT.js_error; })
        .then(function(){ setTimeout(function(){ btn.disabled = false; btn.textContent = orig; }, 2500); });
    });
  });
})();
</script>
</div></body></html>
