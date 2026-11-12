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
$istAdminW = $werkUid && !empty($wu) && in_array('admin', array_map('trim', explode(',', (string)($wu['rollen'] ?? ''))), true);
require_once BX_ROOT . '/core/erp_ablauf.php';   // Ein Weg (11.11.2026): Tagesplan + geführter Ablauf

// ---- POST (Login / Logout / Schritt erledigen) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    if ($aktion === 'werk_login') {
        $u = erp_benutzer_per_pin((string)($_POST['pin'] ?? ''));
        if ($u) { $_SESSION['werk_uid'] = (int)$u['id']; weiter('?p=werk'); }
        $_SESSION['werk_fehler'] = $T['pin_wrong']; weiter('?p=werk');
    }
    if ($aktion === 'werk_logout') { unset($_SESSION['werk_uid']); weiter('?p=werk'); }
    // Soll-Kette 6: Kartonetiketten drucken (Verpackungsschritt) – Kartonanzahl rechnet das System.
    if ($werkUid && $aktion === 'werk_karton') {
        $paId = (int)($_POST['pa_id'] ?? 0);
        $zahl = fn($k) => (int) preg_replace('/\D+/', '', (string)($_POST[$k] ?? ''));
        $r = erp_karton_drucken($paId, $zahl('karton_stueck'), $zahl('karton_menge'), (string)($_POST['karton_charge'] ?? ''), (string)($_POST['karton_mhd'] ?? ''));
        $_SESSION['werk_flash'] = (string)$r['meldung']; $_SESSION['werk_flash_ok'] = !empty($r['ok']);
        weiter('?p=werk&id=' . $paId);
    }
    // Soll-Kette 4: Teilmenge am Tablet melden – komplett (mit Etikett, bucht Fertigware) oder bis vor dem Etikettieren.
    if ($werkUid && $aktion === 'werk_teilmenge') {
        $paId = (int)($_POST['pa_id'] ?? 0);
        // 13.11.2026: EIN Weg – Teilmengen nur noch im geführten Ablauf Abfüllen/Verpacken (pa_teilmenge_buchen); hier nichts buchen.
        $_SESSION['werk_flash'] = (string)($T['tm_nur_ablauf'] ?? 'Teilmengen bitte im Ablauf Abfüllen/Verpacken melden.');
        $_SESSION['werk_flash_ok'] = false;
        weiter('?p=werk&id=' . $paId);
    }
    // Ein Weg (11.11.2026): geführter Ablauf Abfüllen/Verpacken (Start, Scan, Kartons, Teilmenge, Chargenwechsel, Abschluss).
    if ($werkUid && str_starts_with($aktion, 'wa_')) require __DIR__ . '/werk_ablauf_post.php';
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
        $r = erp_schritt_abschliessen($sid, $werkName ?: 'Mitarbeiter', $werkUid);
        // Mischen: je Mischbehälter eine Gebinde-Untercharge anlegen (ein Etikett je Behälter, FEFO beim Abfüllen).
        if (!empty($r['ok']) && ($r['station'] ?? '') === 'Mischen') {
            $cap = pr_menge_parse((string)($_POST['cap'] ?? ''));
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
            $g[$cid] = pr_menge_parse((string)$val);   // 13.11.2026
        }
        // Gewicht & Bestand pro Gebinde: je gescanntem Karton/Sack das neu gewogene Ist-Gewicht (+ Bestätigung „verschlossen“).
        $gb = [];
        foreach ((array)($_POST['rest_g'] ?? []) as $gid => $val) {
            if (trim((string)$val) === '') continue;
            $v = trim((string)$val);
            $gb[(int)$gid] = pr_menge_parse($v);   // 13.11.2026: 1.234,5 / 25,5 / 25.5 / 1.500 wie überall
        }
        if ($gb && empty($_POST['verschlossen'])) {
            $_SESSION['werk_flash'] = $T['return_closed_req']; $_SESSION['werk_flash_ok'] = false;
            weiter('?p=werk&id=' . $paId);
        }
        $n = erp_rohstoff_rueckgabe_speichern($paId, $g, $werkName ?: 'Mitarbeiter', $gb);
        $_SESSION['werk_flash'] = $T['fl_return_saved'] . ($n > 0 ? ' (' . $n . ')' : '') . '.';
        $_SESSION['werk_flash_ok'] = true;
        weiter('?p=werk&id=' . $paId);
    }
    if ($werkUid && $aktion === 'werk_probe') {
        $paId = (int)($_POST['pa_id'] ?? 0);
        $pid  = erp_rohstoff_probe_ziehen($paId, (int)($_POST['charge_id'] ?? 0), (int)($_POST['item_id'] ?? 0), $werkName ?: 'Mitarbeiter');
        $msg  = $T['fl_sample_ok'];
        if ($pid > 0 && function_exists('pr_lager_druck_probe')) {   // Proben-Etikett lautlos über die Lager-Brücke drucken
            $d = pr_lager_druck_probe($pid);
            if (!empty($d['ok'])) { if (function_exists('erp_probe_etikett_gedruckt')) erp_probe_etikett_gedruckt($pid); $msg .= ' ' . $T['fl_label_sent']; }
            else { $msg .= ' ' . $T['fl_label_fail']; }
        }
        $_SESSION['werk_flash'] = $msg; $_SESSION['werk_flash_ok'] = true;
        weiter('?p=werk&id=' . $paId);
    }
    if ($werkUid && $aktion === 'werk_eimer_fertig') {
        // Einen Mischbehälter (Eimer) abschließen: Gebinde anlegen + Etikett drucken. Letzter Eimer schließt Mischen ab.
        $paId = (int)($_POST['pa_id'] ?? 0);
        $nr   = (int)($_POST['nr'] ?? 0);
        $cap  = pr_menge_parse((string)($_POST['cap'] ?? ''));
        $r = erp_mischer_eimer_abschliessen($paId, $cap, $nr, $werkName ?: 'Mitarbeiter');
        if (!empty($r['ok'])) {
            $msg = sprintf($T['fl_eimer_done'], (int)$r['nr'], (int)$r['anzahl']);
            if (!empty($r['sub_id']) && function_exists('pr_lager_druck_gebinde')) {
                $dr = pr_lager_druck_gebinde((int)$r['sub_id']);
                $msg .= ' ' . (!empty($dr['ok']) ? $T['fl_label_sent'] : $T['fl_label_fail']);
            }
            if (!empty($r['alle_fertig'])) {   // alle Eimer fertig -> Mischen-Schritt abschließen
                $sid = 0; foreach (erp_pa_schritte($paId) as $s) if ((string)$s['station'] === 'Mischen' && (int)$s['erledigt'] === 0) { $sid = (int)$s['id']; break; }
                if ($sid) erp_schritt_abschliessen($sid, $werkName ?: 'Mitarbeiter', $werkUid);
                $msg .= ' ' . $T['fl_mix_done'];
            }
            $_SESSION['werk_flash'] = $msg; $_SESSION['werk_flash_ok'] = true;
        } else {
            $_SESSION['werk_flash'] = $r['msg'] ?: $T['fl_step_fail']; $_SESSION['werk_flash_ok'] = false;
        }
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
    if ($werkUid && $aktion === 'werk_gebinde_scan') {
        // Teil 4 (Schritt 2): ein gescanntes Gebinde in die Produktion umbuchen (NUR Ortswechsel, keine Abbuchung).
        // AJAX vom Scan-Panel: liefert die charge_id zurück, damit das Scan-Gate die zugehörige Charge bestätigt.
        $gid = (int)($_POST['gebinde_id'] ?? 0);
        $r = (function_exists('erp_gebinde_scan_nach_produktion') && $gid > 0)
           ? erp_gebinde_scan_nach_produktion($gid) : ['ok' => false, 'charge_id' => 0];
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => !empty($r['ok']), 'charge_id' => (int)($r['charge_id'] ?? 0),
                          'nummer' => (int)($r['nummer'] ?? 0), 'anzahl' => (int)($r['anzahl'] ?? 0)]);
        exit;
    }
    if ($werkUid && $aktion === 'werk_restmischung') {
        // Teil 4 (Schritt 5): übrige gemischte Bulkware (kg) als Bulkware zur Rezeptur in Lager 1 einbuchen.
        $paId = (int)($_POST['pa_id'] ?? 0);
        $kg   = pr_menge_parse((string)($_POST['menge'] ?? '0'));   // Tausenderpunkt (Block D)
        $r = (function_exists('erp_restmischung_einbuchen') && $paId > 0)
           ? erp_restmischung_einbuchen($paId, $kg, $werkName ?: 'Mitarbeiter')
           : ['ok' => false, 'msg' => $T['fl_rest_fail']];
        if (!empty($r['ok'])) {
            $kgTxt = rtrim(rtrim(number_format((float)$r['menge'], 3, ',', '.'), '0'), ',');
            $_SESSION['werk_flash'] = sprintf($T['fl_rest_ok'], $kgTxt, (string)$r['charge_nr'], date('d.m.Y', strtotime((string)$r['mhd'])));
            $_SESSION['werk_flash_ok'] = true;
        } else {
            $_SESSION['werk_flash'] = (string)($r['msg'] ?? $T['fl_rest_fail']); $_SESSION['werk_flash_ok'] = false;
        }
        weiter('?p=werk&id=' . $paId);
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
    // Ein Weg (11.11.2026): genau die Reihenfolge aus dem Reiter Plan (Dashboard). Laufende zuerst, nicht machbare grau mit Grund –
    // ist der oberste blockiert, rückt der nächste machbare nach vorne (erp_wa_tagesplan).
    $anwL = erp_teilmenge_anweisungen();   // Soll-Kette 4b: Anweisungen vom Büro
    $jobs = erp_wa_tagesplan();
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
  <?php if ($anwL): ?>
  <div class="panel" style="border-color:var(--lime)">
    <div class="step-sub"><?= h($T['instr_h'] ?? 'Anweisungen vom Büro') ?></div>
    <?php foreach ($anwL as $aw): ?>
      <a href="?p=werk&id=<?= (int)$aw['pa_id'] ?>" style="display:block;color:inherit;text-decoration:none;padding:10px 0;border-bottom:1px solid var(--line);font-size:18px">
        <strong><?= menge_txt($aw['menge']) ?> <?= h($T['packages']) ?></strong> <?= h($aw['umfang'] === 'vor_etikett' ? ($T['tm_prelabel_btn'] ?? 'bis vor dem Etikettieren') : ($T['tm_full_btn'] ?? 'komplett fertig')) ?>
        · <?= h((string)$aw['nummer']) ?> · <?= h((string)($aw['produkt'] ?? '')) ?><?= (int)$aw['prio'] === 1 ? ' <span class="badge b-lauf">' . h($T['prio_high'] ?? 'Priorität hoch') . '</span>' : '' ?>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <h1 style="font-size:24px;margin:0 0 14px"><?= h($T['what_produce']) ?> – <?= h($T['wa_dayplan']) ?></h1>
  <?php if (!$jobs): ?>
    <div class="panel muted"><?= h($T['no_jobs']) ?></div>
  <?php else: ?>
  <div class="cards">
    <?php foreach ($jobs as $pa): $m = $pa['_m']; $grau = !$m['machbar']; $proz = (float)$pa['menge'] > 0 ? min(100, round((float)$pa['produziert'] * 100 / (float)$pa['menge'])) : 0; ?>
    <a class="card" href="<?= $grau ? '#' : '?p=werk&id=' . (int)$pa['id'] ?>"<?= $grau ? ' style="opacity:.45;pointer-events:none"' : '' ?>>
      <div class="nr"><?= h((string)$pa['nummer']) ?><?= $pa['_heute'] ? ' <span class="badge b-bereit" style="margin:0 0 0 6px">' . h($T['wa_today_badge']) . '</span>' : '' ?></div>
      <div class="prod"><?= h((string)($pa['produkt'] ?: '–')) ?></div>
      <div class="meta"><?= h(sprintf($T['wa_target'], menge_txt($pa['menge']))) ?><?= !empty($pa['kunde']) ? ' · ' . h((string)$pa['kunde']) : '' ?></div>
      <div class="meta"><?= $pa['geplant_am'] ? h(date('d.m.', strtotime((string)$pa['geplant_am']))) . ' · ' : '' ?><?= h((string)($pa['linie'] ?: $T['wa_line_open'])) ?><?= $pa['mitarbeiter'] ? ' · ' . h((string)$pa['mitarbeiter']) : '' ?></div>
      <span class="badge <?= $pa['_lauf'] ? 'b-lauf' : 'b-bereit' ?>"><?= $pa['_lauf'] ? h($T['in_production']) : ($grau ? h($T['wa_not_doable']) : ($m['stufe'] === 'bis_etikett' ? h($T['wa_until_label']) : h($T['producible']))) ?></span>
      <?php if ($m['grund'] !== ''): ?><div class="meta" style="margin-top:6px;color:#e0b54c"><?= h(werk_msg($m['grund'], $lang)) ?></div><?php endif; ?>
      <div class="prog"><div style="width:<?= (int)$proz ?>%"></div></div>
      <div class="meta" style="margin-top:6px"><?= h(sprintf($T['wa_produced_of'], menge_txt($pa['produziert']), menge_txt($pa['menge']))) ?></div>
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
    <?php
      // Teil-/Restmenge + „jetzt machbar" (St-08/S-12): nach einer Teilmenge zeigt das Werk, wie viel NOCH zu produzieren ist und wie viel
      // das vorhandene Material jetzt hergibt – dieselbe Restmenge wie Auftrag, Einkauf und Prod-App.
      $wkGeb = (float) erp_produktion_gebucht($id); $wkRest = (float) erp_produktion_rest($id);
      $wkTm  = ($wkRest > 0 && $fertigCnt < $total) ? erp_teilmenge_machbar($id) : null;
    ?>
    <?php if ($wkGeb > 0 || $wkTm): ?>
    <div style="flex-basis:100%;font-size:17px;margin-top:6px">
      <?php if ($wkGeb > 0): ?><span class="muted"><?= h(sprintf($T['rest_of'], menge_txt($wkRest), menge_txt($pa['menge']))) ?></span><?php endif; ?>
      <?php if ($wkTm): ?>
        <?php if ($wkTm['sperre'] !== ''): ?> <strong style="color:#e0786c"><?= h($T['label_blocked']) ?></strong> <?= h($wkTm['sperre']) ?>
        <?php else: ?> · <strong><?= h($T['doable_now']) ?>:</strong> <?= menge_txt($wkTm['komplett']) ?> <?= h($T['doable_full']) ?><?= $wkTm['komplett'] < $wkTm['rest'] && $wkTm['limit'] !== '' ? ' <span class="muted">(' . h($T['limited_by']) . ' ' . h($wkTm['limit']) . ')</span>' : '' ?><?php if ($wkTm['vor_etikett'] > $wkTm['komplett']): ?> · <?= menge_txt($wkTm['vor_etikett']) ?> <?= h($T['doable_before_label']) ?><?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php $waAktiv = !$alleFertig && in_array((string)$pa['status'], ['offen', 'laufend'], true) && erp_wa_aktiv($id);
  if ($waAktiv): require __DIR__ . '/werk_ablauf.php'; else:   // Ein Weg (11.11.2026): Abfüllen bis Verpacken im geführten Ablauf ?>
  <?php // Soll-Kette 4: Teilmenge am Tablet – was jetzt je Stufe machbar ist, Rest bleibt offen im Auftrag.
    $mbW = (!$alleFertig && in_array((string)$pa['status'], ['offen', 'laufend'], true)) ? erp_stufen_machbar($id) : null;
    if ($mbW && $mbW['rest'] > 0): $nW = fn($x) => menge_txt($x); $anwW = erp_teilmenge_anweisungen($id); $aw1 = $anwW[0] ?? null; ?>
  <div class="panel"<?= $aw1 ? ' style="border-color:var(--lime)"' : '' ?>>
    <div class="step-sub"><?= h($T['tm_h'] ?? 'Teilmenge produzieren') ?></div>
    <?php foreach ($anwW as $aw): ?>
      <div style="font-size:19px;margin:6px 0;color:var(--lime)"><?= h($T['instr_one'] ?? 'Anweisung:') ?> <strong><?= menge_txt($aw['menge']) ?> <?= h($T['packages']) ?></strong> <?= h($aw['umfang'] === 'vor_etikett' ? ($T['tm_prelabel_btn'] ?? 'bis vor dem Etikettieren') : ($T['tm_full_btn'] ?? 'komplett fertig')) ?><?= (int)$aw['prio'] === 1 ? ' · ' . h($T['prio_high'] ?? 'Priorität hoch') : '' ?></div>
    <?php endforeach; ?>
    <div style="font-size:17px;margin:6px 0 10px;line-height:1.6">
      <?= h($T['tm_production'] ?? 'Herstellung') ?>: <strong><?= $nW($mbW['herstellung']) ?></strong> ·
      <?= h($T['doable_before_label']) ?>: <strong><?= $nW($mbW['vor_etikett']) ?></strong> ·
      <?= h($T['doable_full']) ?>: <strong><?= $nW($mbW['komplett']) ?></strong> <?= h($T['packages']) ?>
      <?php if ($mbW['vorproduziert'] > 0): ?><br><span class="muted"><?= h(sprintf($T['hint_prelabeled'] ?? '%s Pkg bis vor dem Etikett fertig', $nW($mbW['vorproduziert']))) ?></span><?php endif; ?>
      <?php if ($mbW['sperre'] !== ''): ?><br><span style="color:#e0786c"><?= h($mbW['sperre']) ?></span><?php endif; ?>
    </div>
    <div style="font-size:15px;margin:0"><?= h($T['tm_nur_ablauf'] ?? 'Teilmengen bitte im Ablauf Abfüllen/Verpacken melden.') ?></div>
    <div class="muted" style="font-size:14px;margin-top:8px"><?= h($T['tm_note'] ?? 'Gemeldet wird, was wirklich gemacht wurde. Der Rest bleibt im Auftrag offen, bis das Material da ist.') ?></div>
  </div>
  <?php endif; ?>
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
            <?php $mitGeb = false; foreach ($rueckListe as $r): $einh = (string)$r['einheit']; $gebR = erp_rueckgabe_gebinde((int)$r['charge_id']); if ($gebR) $mitGeb = true;
              $inpSt = 'font-size:19px;padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:var(--bg);color:var(--text);width:120px;text-align:right'; ?>
            <tr>
              <td><?= h((string)$r['item_name']) ?></td>
              <td class="muted"><?= h((string)($r['charge_nr'] ?: '–')) ?><?php if (!empty($r['charge_id'])): ?><div style="margin-top:6px"><?= $blinkBtn($r['charge_id']) ?></div><?php endif; ?></td>
              <td class="num"><?php if ($gebR): // je gescanntem Karton/Sack das Ist-Gewicht (blind, ohne Sollwert) ?>
                <?php foreach ($gebR as $gr): ?>
                <div style="margin-bottom:6px;white-space:nowrap"><span class="muted" style="font-size:15px;margin-right:8px"><?= h(sprintf($T['return_box'], (int)$gr['nummer'], (int)$gr['anzahl_gesamt'])) ?></span><input type="text" inputmode="decimal" name="rest_g[<?= (int)$gr['id'] ?>]" placeholder="0" style="<?= $inpSt ?>"> <?= h($einh) ?></div>
                <?php endforeach; ?>
              <?php else: ?><input type="text" inputmode="decimal" name="rest[<?= (int)$r['charge_id'] ?>]" placeholder="0" style="<?= $inpSt ?>"> <?= h($einh) ?><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php if ($mitGeb): ?>
        <label style="display:flex;gap:12px;align-items:center;margin-top:16px;font-size:17px"><input type="checkbox" name="verschlossen" value="1" style="width:26px;height:26px"> <?= h($T['return_closed']) ?></label>
        <?php endif; ?>
        <div style="margin-top:20px"><button class="btn btn-lime btn-lg" type="submit"><?= h($T['return_save']) ?></button></div>
      </form>
    </div>
  <?php elseif ($cur && (string)$cur['station'] === 'Mischen'): // ===== MISCHEN: Eimer für Eimer =====
    $anlM = werk_station_anleitung('Mischen', $lang);
    $capLocked = erp_mischer_cap($id);
    $capM = $capLocked;   // Ein Weg (11.11.2026): keine Eingabe mehr – das System gibt die Eimer vor (Handbuch 4.8)
    $planM = erp_mischer_plan($id, $capM);
    $NM    = (int)($planM['anzahl'] ?? 0);
    $doneM = erp_mischer_fertig_anzahl($id);
    $nzM   = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
  ?>
    <div class="panel" style="border-color:var(--gruen)">
      <div class="step-sub"><?= h($T['now_due']) ?> · <?= h($T['step']) ?> <?= $fertigCnt + 1 ?> <?= h($T['of']) ?> <?= $total ?></div>
      <div class="step-h"><?= h(werk_station_label('Mischen', $lang)) ?></div>
      <?php if ($anlM !== ''): ?><div class="muted" style="font-size:18px;margin-bottom:6px"><?= h($anlM) ?></div><?php endif; ?>

      <div class="panel" style="background:var(--panel2);border-color:var(--line);margin-top:16px">
        <div class="muted"><?= h($T['mix_total']) ?></div>
        <div style="font-size:24px;font-weight:800"><?= $nzM($planM['total_kg'] ?? 0) ?> kg <span class="muted" style="font-size:16px;font-weight:400">(<?= number_format((int)($planM['einheiten'] ?? 0), 0, ',', '.') ?> <?= h($T['units']) ?>)</span></div>

        <?php if (false): // Behältergröße wird nicht mehr eingetippt ?>
        <form method="get" style="margin-top:12px;display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
          <input type="hidden" name="p" value="werk"><input type="hidden" name="id" value="<?= (int)$id ?>">
          <div><div class="muted" style="font-size:14px;margin-bottom:4px"><?= h($T['kg_per_container']) ?></div>
            <input type="text" inputmode="decimal" name="cap" value="<?= h($capM > 0 ? $nzM($capM) : '') ?>" placeholder="10" style="font-size:20px;padding:12px 14px;border-radius:12px;border:1px solid var(--line);background:var(--bg);color:var(--text);width:150px"></div>
          <button class="btn btn-ghost" type="submit" style="min-height:52px"><?= h($T['calc_containers']) ?></button>
        </form>
        <?php endif; ?>

        <?php if (!empty($planM['ok']) && !empty($planM['gebinde'])): ?>
        <div class="muted" style="margin:16px 0 6px"><?= h($T['eimer_hint']) ?></div>
        <div style="font-weight:800;font-size:18px;margin-bottom:8px"><?= h(sprintf($T['eimer_progress'], $doneM, $NM)) ?></div>
        <div class="prog" style="margin-bottom:14px"><div style="width:<?= $NM > 0 ? round($doneM * 100 / $NM) : 0 ?>%"></div></div>
        <?php foreach ($planM['gebinde'] as $g): $i = (int)$g['nr']; $fertig = ($i <= $doneM); $istDran = ($i === $doneM + 1); ?>
        <div style="border:1px solid <?= $istDran ? 'var(--lime)' : 'var(--line)' ?>;border-radius:14px;padding:14px 16px;margin-bottom:10px">
          <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:6px">
            <div style="font-size:21px;font-weight:800"><?= h($T['container']) ?> <?= !empty($g['buchstabe']) ? h((string)$g['buchstabe']) : $i ?> <span class="muted" style="font-size:15px;font-weight:400">(<?= $i ?> / <?= $NM ?>)</span></div>
            <div style="font-size:18px;font-weight:700"><?= $nzM($g['kg']) ?> kg <?= h($T['total_word']) ?></div>
          </div>
          <?php if (!empty($g['grund'])): ?><div style="color:var(--lime);font-size:16px;margin-top:6px"><?= h(werk_msg((string)$g['grund'], $lang)) ?></div><?php endif; ?>
          <table class="mat" style="margin-top:8px"><tbody>
            <?php foreach ($g['zutaten'] as $z): ?>
            <tr><td><?= h((string)$z['name']) ?><?= !empty($z['charge']) ? '<div class="muted" style="font-size:14px">' . h(sprintf($T['wa_older_first'], (string)$z['charge'])) . '</div>' : '' ?></td><td class="num" style="font-weight:700;color:var(--lime)"><?= $nzM($z['kg']) ?> kg</td></tr>
            <?php endforeach; ?>
          </tbody></table>
          <div style="margin-top:12px">
            <?php if ($fertig): ?>
              <span style="color:var(--gruen);font-weight:800;font-size:18px"><?= h($T['eimer_done']) ?></span>
            <?php elseif ($istDran): ?>
              <form method="post" style="margin:0">
                <input type="hidden" name="aktion" value="werk_eimer_fertig">
                <input type="hidden" name="pa_id" value="<?= (int)$id ?>">
                <input type="hidden" name="nr" value="<?= $i ?>">
                <input type="hidden" name="cap" value="<?= h($nzM($capM)) ?>">
                <button class="btn btn-lime btn-lg" type="submit"><?= h(sprintf($T['eimer_fertig_btn'], $i)) ?></button>
              </form>
            <?php else: ?>
              <span class="muted" style="font-size:16px"><?= h($T['eimer_wait']) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php elseif ($capLocked <= 0): ?>
        <div class="muted" style="margin-top:10px"><?= h($T['mix_empty_hint']) ?></div>
        <?php endif; ?>
      </div>
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
    $materialFehlt = false; $materialQuar = true;   // Nacht 1: fehlt NUR, was in Quarantäne liegt -> eigener Hinweis „wartet auf QM"
    foreach (($mat['zeilen'] ?? []) as $z)
        if (($z['pflicht'] ?? true) && empty($z['entnommen']) && isset($z['verfuegbar']) && (float)$z['verfuegbar'] + 0.0001 < (float)$z['menge']) {
            $materialFehlt = true;
            if ((float)$z['verfuegbar'] + (float)($z['quarantaene'] ?? 0) + 0.0001 < (float)$z['menge']) $materialQuar = false;
        }
    $isGate = str_contains((string)$cur['station'], 'Freigabe');
    $istMischen = (string)$cur['station'] === 'Mischen';
    $cap  = $istMischen ? pr_menge_parse((string)($_GET['cap'] ?? '')) : 0.0;
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
    <div class="muted" style="margin-top:14px"><?= h($T['get_from_stock']) ?><?php if (($mat['soll_menge'] ?? null) !== null): ?> · <?= h($T['needed']) ?> <strong style="color:var(--text)"><?= h(menge_einh_txt($mat['soll_menge'], (string)($mat['soll_einheit'] ?? ''))) ?></strong><?php endif; ?>:</div>
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
          <td class="num"><?= h(menge_einh_txt($z['menge'], (string)$z['einheit'])) ?></td>
          <td class="num <?= $knapp ? 'knapp' : '' ?>"><?= isset($z['verfuegbar']) ? h(menge_einh_txt($z['verfuegbar'], (string)$z['einheit'])) : '–' ?><?php if (!empty($z['quarantaene'])): ?><br><span style="font-size:13px;color:#e8c766">+ <?= h(menge_einh_txt($z['quarantaene'], (string)$z['einheit'])) ?> <?= h($T['in_quarantine'] ?? 'in Quarantäne') ?></span><?php endif; ?></td>
          <td class="num"><?= $blinkBtn($z['charge_id'] ?? 0) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <?php if ($scanAktiv && !$materialFehlt): ?>
    <div class="panel" id="scanpanel" style="margin-top:18px;background:var(--panel2);border-color:var(--line)">
      <div style="font-weight:700;font-size:18px;margin-bottom:4px"><?= h($T['confirm_batches_h']) ?></div>
      <div class="muted" style="font-size:14px;margin-bottom:12px"><?= h($T['scan_help']) ?> <span id="scancount" style="color:var(--text);font-weight:600"></span></div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <button type="button" id="camstart" class="btn btn-ghost" style="min-height:52px"><?= h($T['scan_with_cam']) ?></button>
        <input type="text" id="scaninput" autocomplete="off" autocapitalize="characters" placeholder="<?= h($T['scan_placeholder']) ?>" style="flex:1;min-width:180px;font-size:18px;padding:12px 14px;border-radius:12px;border:1px solid var(--line);background:var(--bg);color:var(--text)">
      </div>
      <div id="scanmsg" style="font-size:15px;margin-top:10px;min-height:20px"></div>
      <div id="camwrap" style="display:none;position:relative;max-width:440px;margin-top:12px">
        <video id="camview" playsinline muted style="width:100%;border-radius:12px;background:#000;display:block"></video>
        <button type="button" id="camclose" aria-label="<?= h($T['js_cam_off']) ?>" style="position:absolute;top:8px;right:8px;width:46px;height:46px;border-radius:50%;border:none;background:rgba(0,0,0,.65);color:#fff;font-size:24px;font-weight:700;line-height:1;cursor:pointer">&#10005;</button>
      </div>
    </div>
    <?php endif; ?>

    <div style="margin-top:22px">
      <?php $qmSperre = in_array((string)$cur['station'], erp_qm_stationen(), true) && !erp_darf_qm($werkUid);   // St-11a ?>
      <?php if ($qmSperre): ?>
        <div class="flash err" style="margin-bottom:14px"><?= h($T['qm_only'] ?? 'Diesen Schritt schließt QM ab (Vier-Augen-Prinzip).') ?></div>
        <button class="btn btn-primary btn-lg" disabled><?= $isGate ? h($T['release']) : h($T['done_btn']) ?></button>
      <?php elseif ($materialFehlt): ?>
        <div class="flash err" style="margin-bottom:14px"><?= h($materialQuar ? ($T['material_quarantine'] ?? $T['material_missing']) : $T['material_missing']) ?></div>
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

  <?php // Soll-Kette 6: Kartonetiketten als letzter Teil des Verpackens (bzw. beim Umkarton, wenn es ihn gibt).
    $kStat = in_array('Umkarton', array_map(fn($s) => (string)$s['station'], $schritte), true) ? 'Umkarton' : 'Verpacken';
    if ($cur && (string)$cur['station'] === $kStat): $kv = erp_karton_vorschlag($id); ?>
  <div class="panel">
    <div class="step-sub"><?= h($T['carton_h'] ?? 'Kartonetiketten') ?></div>
    <div class="muted" style="font-size:16px;margin:4px 0 10px"><?= h($T['carton_hint'] ?? 'Wie viele Packungen gehen in einen Karton? Das System rechnet die Kartons aus und druckt alle Etiketten auf einmal (100 × 150 mm).') ?></div>
    <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin:0">
      <input type="hidden" name="aktion" value="werk_karton"><input type="hidden" name="pa_id" value="<?= (int)$id ?>">
      <?php $kIn = 'font-size:19px;padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:var(--bg);color:var(--text)'; ?>
      <label style="display:flex;flex-direction:column;gap:4px;font-size:14px" class="muted"><?= h($T['carton_per'] ?? 'Pkg je Karton') ?><input type="text" inputmode="numeric" name="karton_stueck" value="<?= $kv['stueck'] > 0 ? (int)$kv['stueck'] : '' ?>" required style="<?= $kIn ?>;width:130px" oninput="kartonRechnen()"></label>
      <label style="display:flex;flex-direction:column;gap:4px;font-size:14px" class="muted"><?= h($T['packages']) ?><input type="text" inputmode="numeric" name="karton_menge" value="<?= (int)$kv['menge'] ?>" required style="<?= $kIn ?>;width:130px" oninput="kartonRechnen()"></label>
      <label style="display:flex;flex-direction:column;gap:4px;font-size:14px" class="muted"><?= h($T['th_charge'] ?? 'Charge') ?><input type="text" name="karton_charge" value="<?= h($kv['charge']) ?>" style="<?= $kIn ?>;width:150px"></label>
      <label style="display:flex;flex-direction:column;gap:4px;font-size:14px" class="muted">MHD<input type="date" name="karton_mhd" value="<?= h($kv['mhd']) ?>" style="<?= $kIn ?>"></label>
      <button class="btn btn-lime" type="submit" style="min-height:52px"><?= h($T['carton_print'] ?? 'Kartonetiketten drucken') ?></button>
      <div id="kartonAnz" style="font-size:18px;padding-bottom:12px"></div>
    </form>
    <script>
    function kartonRechnen(){ var f=document.querySelector('input[name=karton_stueck]').form, s=parseInt((f.karton_stueck.value||'').replace(/\D/g,''),10)||0, m=parseInt((f.karton_menge.value||'').replace(/\D/g,''),10)||0, o=document.getElementById('kartonAnz');
      if(!s||!m){ o.textContent=''; return; } var n=Math.ceil(m/s), r=m-(n-1)*s; o.textContent='= '+n+' <?= h($T['cartons'] ?? 'Kartons') ?>'+(r!==s?' ('+(n-1)+' × '+s+', 1 × '+r+')':''); }
    kartonRechnen();
    </script>
  </div>
  <?php endif; ?>

  <?php if ($mischenDone): // Teil 4 (Schritt 5): Restmischung ins Lager 1 einbuchen – nach dem Mischen verfügbar. ?>
  <div class="panel">
    <div class="step-h" style="font-size:20px"><?= h($T['rest_h']) ?></div>
    <div class="muted" style="font-size:15px;margin:4px 0 12px"><?= h($T['rest_hint']) ?></div>
    <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center" onsubmit="return confirm('<?= h($T['rest_save']) ?>?');">
      <input type="hidden" name="aktion" value="werk_restmischung">
      <input type="hidden" name="pa_id" value="<?= (int)$id ?>">
      <input type="text" inputmode="decimal" name="menge" placeholder="<?= h($T['rest_ph']) ?>" required style="font-size:19px;padding:12px 14px;border-radius:12px;border:1px solid var(--line);background:var(--bg);color:var(--text);width:140px;text-align:right">
      <span style="font-weight:700">kg</span>
      <button class="btn btn-ghost btn-lg" type="submit"><?= h($T['rest_save']) ?></button>
    </form>
  </div>
  <?php endif; ?>

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
  <?php endif; // $waAktiv ?>

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
  var camStop = null;   // wird gesetzt, sobald die Kamera läuft (zum Auto-Abschalten)
  function msg(t, ok){ var m=document.getElementById('scanmsg'); if(m){ m.textContent=t; m.style.color = ok ? 'var(--gruen)' : 'var(--err)'; } }
  function parse(s){
    s = (s||'').trim(); if(!s) return {};
    var g = s.match(/[?&]bg=(\d+)/);            // Gebinde-QR .../lager/?p=gebinde&bg=123 (Teil 4)
    if(g) return {bg:g[1]};
    var m = s.match(/[?&]id=(\d+)/);            // Charge-QR (Alt-Etikett) .../lager/?p=charge&id=123
    if(m) return {cid:m[1]};
    if(/^\d+$/.test(s)) return {cid:s};          // nackte Charge-ID
    return {cnr:s.toUpperCase()};                // Chargennummer als Text
  }
  // Eine Charge (per cid oder cnr) im Gate bestätigen. Rückgabe true, wenn eine passende Pflicht-Charge getroffen wurde.
  function confirmCharge(cid, cnr){
    var el=null;
    stats.forEach(function(x){ if(el) return;
      if(cid && x.getAttribute('data-cid')===String(cid)) el=x;
      else if(cnr && x.getAttribute('data-cnr') && x.getAttribute('data-cnr')===cnr) el=x; });
    if(!el) return false;
    if(el.getAttribute('data-ok')!=='1'){
      el.setAttribute('data-ok','1'); el.textContent=WT.js_confirmed_badge;
      el.style.background='rgba(29,158,117,.18)'; el.style.color='#7fe3c2'; el.style.borderColor='var(--gruen)';
    }
    return true;
  }
  function gate(){
    var ok=0; stats.forEach(function(x){ if(x.getAttribute('data-ok')==='1') ok++; });
    var c=document.getElementById('scancount'); if(c) c.textContent = WT.js_confirmed_tpl.replace('%A%',ok).replace('%B%',stats.length);
    var f=document.getElementById('scannedfield');
    if(f){ var ids=[]; stats.forEach(function(x){ if(x.getAttribute('data-ok')==='1') ids.push(x.getAttribute('data-cid')); }); f.value=ids.join(','); }
    var b=document.getElementById('erledigtbtn'), h=document.getElementById('gatehint');
    var alle = ok>=stats.length;
    if(b && b.getAttribute('data-scan-gate')==='1'){ b.disabled=!alle; if(h) h.style.display = alle ? 'none' : ''; }
    if(alle && camStop){ camStop(); }                       // alles bestätigt -> Kamera automatisch aus
    var sp=document.getElementById('scanpanel'); if(sp) sp.style.display = alle ? 'none' : '';  // Scan-Panel ausblenden, nur noch "Erledigt"
  }
  function confirmScan(raw){
    var p=parse(raw);
    // Gebinde-QR: erst serverseitig in die Produktion umbuchen (Ortswechsel), dann die zugehörige Charge bestätigen.
    if(p.bg){
      fetch('?p=werk',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'aktion=werk_gebinde_scan&gebinde_id='+encodeURIComponent(p.bg)})
        .then(function(r){return r.json();})
        .then(function(j){
          if(j && j.ok && j.charge_id){ if(confirmCharge(j.charge_id, null)){ msg(WT.js_charge_ok, true); gate(); } else { msg(WT.js_unknown_charge+raw, false); } }
          else { msg(WT.js_unknown_charge+raw, false); }
        }).catch(function(){ msg(WT.js_unknown_charge+raw, false); });
      return true;
    }
    var el=null;
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
  var cam=document.getElementById('camstart'), video=document.getElementById('camview'),
      camwrap=document.getElementById('camwrap'), camclose=document.getElementById('camclose');
  if(cam){
    if(!('BarcodeDetector' in window)){ cam.disabled=true; cam.textContent=WT.js_cam_unsupported; }
    else {
      var stream=null, det=null, run=false;
      function stop(){ run=false; if(stream){ stream.getTracks().forEach(function(t){t.stop();}); stream=null; } if(camwrap){ camwrap.style.display='none'; } cam.textContent=WT.scan_with_cam; }
      async function loop(){ if(!run) return;
        try{ var codes=await det.detect(video); if(codes&&codes.length) confirmScan(codes[0].rawValue); }catch(e){}
        if(run) setTimeout(loop, 400); }
      async function start(){
        try{ det=new BarcodeDetector({formats:['qr_code']});
          stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'}});
          video.srcObject=stream; if(camwrap) camwrap.style.display='block'; await video.play(); run=true; cam.textContent=WT.js_cam_off; loop();
        }catch(e){ msg(WT.js_cam_unavailable+(e&&e.message?e.message:e), false); }
      }
      camStop = stop;   // damit die Kamera auch automatisch ausgeht, wenn alles bestätigt ist
      cam.addEventListener('click', function(){ run?stop():start(); });
      if(camclose) camclose.addEventListener('click', stop);
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
