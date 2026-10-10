<?php
// Mitarbeiter-Vollbild-App (Kiosk/Tablet) – rein schrittweise Produktion. Eigener PIN-Login
// (benutzer.pin_hash, vom Admin im Dashboard gesetzt). Kein Team-Login nötig. Nutzt dieselben
// Schritt-/Material-Funktionen wie ?p=run, aber großflächig/touch und ohne Sidebar/Abkürzungen.

$werkUid  = (int)($_SESSION['werk_uid'] ?? 0);
$werkName = '';
if ($werkUid) { $wu = erp_benutzer($werkUid); if ($wu) { $werkName = (string)$wu['name']; } else { $werkUid = 0; unset($_SESSION['werk_uid']); } }

// ---- POST (Login / Logout / Schritt erledigen) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    if ($aktion === 'werk_login') {
        $u = erp_benutzer_per_pin((string)($_POST['pin'] ?? ''));
        if ($u) { $_SESSION['werk_uid'] = (int)$u['id']; weiter('?p=werk'); }
        $_SESSION['werk_fehler'] = 'PIN nicht erkannt.'; weiter('?p=werk');
    }
    if ($aktion === 'werk_logout') { unset($_SESSION['werk_uid']); weiter('?p=werk'); }
    if ($werkUid && $aktion === 'werk_erledigt') {
        $sid = (int)($_POST['schritt_id'] ?? 0);
        $paId = (int)($_POST['pa_id'] ?? 0);
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
        $_SESSION['werk_flash']    = $r['ok'] ? (($r['fertig'] ?? false) ? 'Fertig – Produktion abgeschlossen, Fertigware eingebucht.' : 'Schritt erledigt.') : ($r['msg'] ?: 'Schritt konnte nicht abgeschlossen werden.');
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
        $_SESSION['werk_flash'] = 'Rohstoff-Rückgabe gespeichert' . ($n > 0 ? ' (' . $n . ')' : '') . '.';
        $_SESSION['werk_flash_ok'] = true;
        weiter('?p=werk&id=' . $paId);
    }
    if ($werkUid && $aktion === 'werk_probe') {
        $paId = (int)($_POST['pa_id'] ?? 0);
        erp_rohstoff_probe_ziehen($paId, (int)($_POST['charge_id'] ?? 0), (int)($_POST['item_id'] ?? 0), $werkName ?: 'Mitarbeiter');
        $_SESSION['werk_flash'] = 'Probe erfasst.'; $_SESSION['werk_flash_ok'] = true;
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
        $_SESSION['werk_flash'] = ($r['ok'] ? 'Blinker im Lager: ' : 'Blinker: ') . ($r['meldung'] ?: ($r['ok'] ? ($modus === 'aus' ? 'aus.' : 'leuchtet.') : 'nicht ausgelöst.'));
        $_SESSION['werk_flash_ok'] = !empty($r['ok']);
        weiter('?p=werk&id=' . (int)($_POST['pa_id'] ?? 0));
    }
    weiter('?p=werk');
}

$flash   = $_SESSION['werk_flash'] ?? ''; $flashOk = !empty($_SESSION['werk_flash_ok']);
$fehler  = $_SESSION['werk_fehler'] ?? '';
unset($_SESSION['werk_flash'], $_SESSION['werk_flash_ok'], $_SESSION['werk_fehler']);

$id = (int)($_GET['id'] ?? 0);

// Pick-to-Light: großer Touch-Button, der den Blinker der FEFO-Charge im Lager leuchten lässt (AJAX, ohne Reload).
$blinkBtn = function($cid, string $extra = '') {
    $cid = (int)$cid;
    if ($cid <= 0) return '<span class="muted" style="font-size:14px">kein Blinker</span>';
    return '<button type="button" class="werk-blink btn btn-ghost" data-cid="' . $cid . '"'
         . ' style="min-height:48px;padding:10px 18px;font-size:16px;' . $extra . '">Platz zeigen</button>';
};

// ---- Ausgabe: eigenes Vollbild-Dokument (keine Sidebar) ----
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="de"><head>
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
  .topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 4px 18px}
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
  /* Login */
  .login{max-width:420px;margin:6vh auto 0;text-align:center}
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
  a.back{color:var(--muted);text-decoration:none;font-size:16px}
</style>
</head><body><div class="wrap">

<?php if (!$werkUid): // ===================== PIN-LOGIN ===================== ?>
  <div class="topbar"><div class="brand"><img src="/assets/bulkify-logo-white.png" alt="Produktion" style="height:30px;vertical-align:middle;display:inline-block"></div></div>
  <div class="login">
    <div style="font-size:22px;margin-top:10px">PIN eingeben</div>
    <div class="muted" style="font-size:15px">Deinen Tablet-PIN hat dir der Produktionsleiter gegeben.</div>
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
      <button id="pwaInstall" type="button" class="btn btn-ghost" style="display:none">Auf dem Tablet installieren</button>
      <div class="muted" style="font-size:13px;margin-top:12px">Für Vollbild ohne Browser: die App über das Browser-Menü <strong>„Zur Startseite / Zum Startbildschirm hinzufügen"</strong> installieren.</div>
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
      if(pin.length<8){ pin+=k; upd(); if(pin.length>=4){ /* Auto-Absenden bei 4+ erst mit OK */ } }
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
    <div style="display:flex;align-items:center;gap:14px">
      <span class="who"><?= h($werkName) ?></span>
      <form method="post" style="margin:0"><input type="hidden" name="aktion" value="werk_logout"><button class="btn btn-ghost" style="min-height:48px;padding:10px 18px;font-size:16px" type="submit">Abmelden</button></form>
    </div>
  </div>
  <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= h($flash) ?></div><?php endif; ?>
  <h1 style="font-size:24px;margin:0 0 14px">Was möchtest du produzieren?</h1>
  <?php if (!$jobs): ?>
    <div class="panel muted">Aktuell ist kein Auftrag produzierbar oder in Produktion. Sobald Material da und freigegeben ist, erscheint er hier.</div>
  <?php else: ?>
  <div class="cards">
    <?php foreach ($jobs as $pa): $g = (int)$pa['schritte_gesamt']; $f = (int)$pa['schritte_fertig']; $proz = $g > 0 ? round($f * 100 / $g) : 0; ?>
    <a class="card" href="?p=werk&id=<?= (int)$pa['id'] ?>">
      <div class="nr"><?= h((string)$pa['nummer']) ?></div>
      <div class="prod"><?= h((string)($pa['produkt_name'] ?: '–')) ?></div>
      <div class="meta"><?= menge_txt($pa['menge']) ?> Packungen<?= !empty($pa['kunde']) ? ' · ' . h((string)$pa['kunde']) : '' ?></div>
      <span class="badge <?= !empty($pa['_lauf']) ? 'b-lauf' : 'b-bereit' ?>"><?= !empty($pa['_lauf']) ? 'in Produktion' : 'produzierbar' ?></span>
      <div class="prog"><div style="width:<?= (int)$proz ?>%"></div></div>
      <div class="meta" style="margin-top:6px"><?= $g > 0 ? ('Schritt ' . $f . ' / ' . $g) : 'bereit' ?></div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

<?php else: // ===================== SCHRITT-ANSICHT ===================== ?>
  <?php
    $pa = erp_pa($id);
    if (!$pa) { echo '<div class="panel">Auftrag nicht gefunden. <a class="back" href="?p=werk">Zurück</a></div></div></body></html>'; return; }
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
    <a class="back" href="?p=werk">&larr; Alle Aufträge</a>
    <div style="display:flex;align-items:center;gap:14px"><span class="who"><?= h($werkName) ?></span>
      <form method="post" style="margin:0"><input type="hidden" name="aktion" value="werk_logout"><button class="btn btn-ghost" style="min-height:44px;padding:8px 16px;font-size:15px" type="submit">Abmelden</button></form>
    </div>
  </div>
  <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= h($flash) ?></div><?php endif; ?>

  <div class="panel" style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
    <div><span style="font-size:24px;font-weight:800"><?= h((string)$pa['nummer']) ?></span> · <?= h((string)($pa['produkt_name'] ?? '–')) ?></div>
    <div class="count"><?= menge_txt($pa['menge']) ?> Packungen · Schritt <?= min($fertigCnt + 1, $total) ?> / <?= $total ?></div>
  </div>

  <?php if ($probenPflicht): ?>
    <div class="panel" style="border-color:var(--lime)">
      <div class="step-sub">Vor dem Mischen</div>
      <div class="step-h">Rohstoff-Probe ziehen</div>
      <div class="muted" style="font-size:17px;margin-bottom:6px">Von jeder Rohstoff-Charge eine <strong style="color:var(--text)">Chargenprobe</strong> (Rückstellmuster) ziehen und bestätigen. Erst danach geht es ans Mischen. (Jede Produktion braucht frische Proben – auch wenn die Charge zwischendurch wieder im Lager war.)</div>
      <table class="mat">
        <thead><tr><th>Rohstoff</th><th>Charge</th><th class="num">Probe</th></tr></thead>
        <tbody>
          <?php foreach ($probenListe as $pr): ?>
          <tr>
            <td><?= h((string)$pr['name']) ?></td>
            <td class="muted"><?= h((string)($pr['charge_nr'] ?: '–')) ?><?php if (!empty($pr['charge_id'])): ?><div style="margin-top:6px"><?= $blinkBtn($pr['charge_id']) ?></div><?php endif; ?></td>
            <td class="num">
              <?php if (!empty($pr['hat_probe'])): ?><span style="color:var(--gruen);font-weight:700">✓ erfasst</span>
              <?php else: ?>
                <form method="post" style="margin:0"><input type="hidden" name="aktion" value="werk_probe"><input type="hidden" name="pa_id" value="<?= (int)$id ?>"><input type="hidden" name="charge_id" value="<?= (int)$pr['charge_id'] ?>"><input type="hidden" name="item_id" value="<?= (int)$pr['item_id'] ?>"><button class="btn btn-ghost" type="submit" style="min-height:48px">Probe gezogen</button></form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="muted" style="font-size:14px;margin-top:12px">Sobald alle Proben erfasst sind, erscheint der Mischen-Schritt automatisch.</div>
    </div>
  <?php elseif ($rueckOffen): ?>
    <div class="panel" style="border-color:var(--lime)">
      <div class="step-sub">Nach dem Mischen</div>
      <div class="step-h">Rohstoff zurück ins Lager</div>
      <div class="muted" style="font-size:17px;margin-bottom:6px">Bring jeden Rohstoff zurück an seinen Platz (gleicher Blinker) und trag das <strong style="color:var(--text)">zurückgelegte Gewicht</strong> ein. Kleine Reste (unter ~500 g) kannst du verwerfen – dann 0 eintragen.</div>
      <form method="post">
        <input type="hidden" name="aktion" value="werk_rueckgabe">
        <input type="hidden" name="pa_id" value="<?= (int)$id ?>">
        <table class="mat">
          <thead><tr><th>Rohstoff</th><th>Charge</th><th class="num">zurückgelegt</th></tr></thead>
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
        <div style="margin-top:20px"><button class="btn btn-lime btn-lg" type="submit">Rückgabe speichern &amp; weiter</button></div>
      </form>
    </div>
  <?php elseif ($alleFertig || !$cur): ?>
    <div class="panel" style="text-align:center;border-color:var(--gruen)">
      <div style="font-size:30px;font-weight:800;margin-bottom:8px">Fertig ✓</div>
      <div class="muted" style="margin-bottom:18px">Alle Schritte erledigt – die Produktion ist abgeschlossen.</div>
      <a class="btn btn-primary btn-lg" href="?p=werk" style="max-width:360px;margin:0 auto">Zurück zu den Aufträgen</a>
    </div>
  <?php else:
    $anl = station_anleitung_text((string)$cur['station']);
    $mat = erp_schritt_material($id, (string)$cur['station']);
    $materialFehlt = false;
    foreach (($mat['zeilen'] ?? []) as $z)
        if (($z['pflicht'] ?? true) && empty($z['entnommen']) && isset($z['verfuegbar']) && (float)$z['verfuegbar'] + 0.0001 < (float)$z['menge']) { $materialFehlt = true; break; }
    $isGate = str_contains((string)$cur['station'], 'Freigabe');
    $istMischen = (string)$cur['station'] === 'Mischen';
    $cap  = $istMischen ? (float) str_replace(',', '.', (string)($_GET['cap'] ?? '')) : 0.0;
    $plan = $istMischen ? erp_mischer_plan($id, $cap) : null;
    $nz   = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
  ?>
  <div class="panel" style="border-color:var(--gruen)">
    <div class="step-sub">Jetzt dran · Schritt <?= $fertigCnt + 1 ?> von <?= $total ?></div>
    <div class="step-h"><?= h((string)$cur['station']) ?></div>
    <?php if ($anl !== ''): ?><div class="muted" style="font-size:18px;margin-bottom:6px"><?= h($anl) ?></div><?php endif; ?>

    <?php if ($istMischen && $plan && !empty($plan['ok'])): ?>
    <div class="panel" style="background:var(--panel2);border-color:var(--line);margin-top:16px">
      <div class="muted">Gesamt anzumischen</div>
      <div style="font-size:24px;font-weight:800"><?= $nz($plan['total_kg'] ?? 0) ?> kg <span class="muted" style="font-size:16px;font-weight:400">(<?= number_format((int)($plan['einheiten'] ?? 0), 0, ',', '.') ?> Einheiten)</span></div>
      <form method="get" style="margin-top:12px;display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="p" value="werk"><input type="hidden" name="id" value="<?= (int)$id ?>">
        <div><div class="muted" style="font-size:14px;margin-bottom:4px">kg je Mischbehälter</div>
          <input type="text" inputmode="decimal" name="cap" value="<?= h($cap > 0 ? $nz($cap) : '') ?>" placeholder="z. B. 10" style="font-size:20px;padding:12px 14px;border-radius:12px;border:1px solid var(--line);background:var(--bg);color:var(--text);width:150px"></div>
        <button class="btn btn-ghost" type="submit" style="min-height:52px">Behälter berechnen</button>
      </form>
      <?php if (!empty($plan['gebinde'])): ?>
      <div class="muted" style="margin:16px 0 10px">Ergibt <strong style="color:var(--text)"><?= (int)$plan['anzahl'] ?> Mischbehälter</strong> – ein Etikett je Behälter. Angefangenen Behälter komplett durchziehen (FIFO).</div>
      <?php foreach ($plan['gebinde'] as $g): ?>
      <div style="border:1px solid var(--line);border-radius:14px;padding:14px 16px;margin-bottom:10px">
        <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:6px">
          <div style="font-size:21px;font-weight:800">Mischbehälter <?= (int)$g['nr'] ?> <span class="muted" style="font-size:15px;font-weight:400">/ <?= (int)$plan['anzahl'] ?></span></div>
          <div style="font-size:18px;font-weight:700"><?= $nz($g['kg']) ?> kg gesamt</div>
        </div>
        <table class="mat" style="margin-top:8px"><tbody>
          <?php foreach ($g['zutaten'] as $z): ?>
          <tr><td><?= h((string)$z['name']) ?></td><td class="num" style="font-weight:700;color:var(--lime)"><?= $nz($z['kg']) ?> kg</td></tr>
          <?php endforeach; ?>
        </tbody></table>
      </div>
      <?php endforeach; ?>
      <?php else: ?>
      <div class="muted" style="margin-top:10px">Trage „kg je Mischbehälter" ein (z. B. die Behältergröße) – dann wird jeder Behälter einzeln mit den Rohstoff-Mengen angezeigt.</div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($mat['zeilen'])): ?>
    <div class="muted" style="margin-top:14px">Aus dem Lager holen<?php if (($mat['soll_menge'] ?? null) !== null): ?> · benötigt <strong style="color:var(--text)"><?= menge_txt($mat['soll_menge']) ?> <?= h((string)($mat['soll_einheit'] ?? '')) ?></strong><?php endif; ?>:</div>
    <table class="mat">
      <thead><tr><th>Material</th><th class="num">Menge</th><th class="num">Bestand</th><th class="num">Lagerplatz</th></tr></thead>
      <tbody>
        <?php foreach ($mat['zeilen'] as $z): $pflicht = $z['pflicht'] ?? true;
              $knapp = $pflicht && isset($z['verfuegbar']) && (float)$z['verfuegbar'] + 0.0001 < (float)$z['menge']; ?>
        <tr>
          <td><?= h((string)$z['name']) ?><?php if (!empty($z['detail'])): ?> <span class="muted" style="font-size:14px">· <?= h((string)$z['detail']) ?><?= $pflicht ? '' : ' (zur Info)' ?></span><?php endif; ?>
              <?php if (!empty($z['charge_nr'])): ?><div class="muted" style="font-size:14px;margin-top:3px">Charge <strong style="color:var(--text)"><?= h((string)$z['charge_nr']) ?></strong> <span style="font-size:13px">(FEFO)</span></div><?php endif; ?></td>
          <td class="num"><?= menge_txt($z['menge']) ?> <?= h((string)$z['einheit']) ?></td>
          <td class="num <?= $knapp ? 'knapp' : '' ?>"><?= isset($z['verfuegbar']) ? menge_txt($z['verfuegbar']) . ' ' . h((string)$z['einheit']) : '–' ?></td>
          <td class="num"><?= $blinkBtn($z['charge_id'] ?? 0) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <div style="margin-top:22px">
      <?php if ($materialFehlt): ?>
        <div class="flash err" style="margin-bottom:14px">Noch nicht möglich: Das benötigte Material ist nicht vollständig im Lager. Bitte erst bereitstellen bzw. im Wareneingang buchen.</div>
        <button class="btn btn-primary btn-lg" disabled><?= $isGate ? 'Freigeben' : 'Erledigt' ?></button>
      <?php else: ?>
        <form method="post" onsubmit="return confirm('Schritt „<?= h((string)$cur['station']) ?>“ jetzt abschließen?');">
          <input type="hidden" name="aktion" value="werk_erledigt">
          <input type="hidden" name="schritt_id" value="<?= (int)$cur['id'] ?>">
          <input type="hidden" name="pa_id" value="<?= (int)$id ?>">
          <?php if ($istMischen && $cap > 0): ?><input type="hidden" name="cap" value="<?= h($nz($cap)) ?>"><?php endif; ?>
          <button class="btn btn-lime btn-lg" type="submit"><?= $isGate ? 'Freigeben' : 'Erledigt – nächster Schritt' ?></button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php // Ablauf-Überblick (klein) – welche Schritte schon erledigt sind. ?>
  <div class="panel">
    <div class="muted" style="margin-bottom:8px">Ablauf</div>
    <?php foreach ($schritte as $i => $s): $done = (int)$s['erledigt'] === 1; $isCur = $cur && (int)$s['id'] === (int)$cur['id']; ?>
      <div style="display:flex;align-items:center;gap:10px;padding:7px 0;<?= $isCur ? 'font-weight:700' : '' ?>">
        <span style="width:24px;text-align:center;color:<?= $done ? 'var(--gruen)' : ($isCur ? 'var(--lime)' : 'var(--muted)') ?>"><?= $done ? '✓' : ($i + 1) ?></span>
        <span style="<?= !$done && !$isCur ? 'color:var(--muted)' : '' ?>"><?= h((string)$s['station']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

<?php endif; ?>

<script>
// PWA: Service Worker registrieren (Installation am Tablet). Installieren-Button auf dem Login,
// sobald der Browser die Installation anbietet (Android/Chrome). iOS: ueber Teilen -> Zum Startbildschirm.
if ('serviceWorker' in navigator) { window.addEventListener('load', function(){ navigator.serviceWorker.register('/produktion/werk-sw.js').catch(function(){}); }); }
(function(){
  var dp = null, b = document.getElementById('pwaInstall');
  window.addEventListener('beforeinstallprompt', function(e){ e.preventDefault(); dp = e; if (b) b.style.display = 'inline-flex'; });
  if (b) b.addEventListener('click', function(){ if (dp) { dp.prompt(); dp = null; b.style.display = 'none'; } });
  window.addEventListener('appinstalled', function(){ if (b) b.style.display = 'none'; });
})();
// Pick-to-Light: Blinker im Lager leuchten lassen, ohne die Seite neu zu laden (Scrollposition bleibt).
(function(){
  document.querySelectorAll('.werk-blink').forEach(function(btn){
    btn.addEventListener('click', function(){
      var cid = btn.getAttribute('data-cid'); if (!cid || btn.disabled) return;
      var orig = btn.textContent; btn.disabled = true; btn.textContent = 'Zeige…';
      var body = 'aktion=werk_blink&js=1&charge_id=' + encodeURIComponent(cid);
      fetch('?p=werk', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body})
        .then(function(r){ return r.json(); })
        .then(function(j){ btn.textContent = j && j.ok ? 'Leuchtet ✓' : ((j && j.meldung) || 'Nicht ausgelöst'); })
        .catch(function(){ btn.textContent = 'Fehler'; })
        .then(function(){ setTimeout(function(){ btn.disabled = false; btn.textContent = orig; }, 2500); });
    });
  });
})();
</script>
</div></body></html>
