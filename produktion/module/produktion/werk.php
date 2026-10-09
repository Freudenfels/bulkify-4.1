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
        $_SESSION['werk_flash']    = $r['ok'] ? (($r['fertig'] ?? false) ? 'Fertig – Produktion abgeschlossen, Fertigware eingebucht.' : 'Schritt erledigt.') : ($r['msg'] ?: 'Schritt konnte nicht abgeschlossen werden.');
        $_SESSION['werk_flash_ok'] = !empty($r['ok']);
        weiter('?p=werk&id=' . $paId . (!empty($r['fertig']) ? '&fertig=1' : ''));
    }
    weiter('?p=werk');
}

$flash   = $_SESSION['werk_flash'] ?? ''; $flashOk = !empty($_SESSION['werk_flash_ok']);
$fehler  = $_SESSION['werk_fehler'] ?? '';
unset($_SESSION['werk_flash'], $_SESSION['werk_flash_ok'], $_SESSION['werk_fehler']);

$id = (int)($_GET['id'] ?? 0);

// ---- Ausgabe: eigenes Vollbild-Dokument (keine Sidebar) ----
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="de"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="theme-color" content="#10210f">
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
  table.mat td.num{text-align:right;white-space:nowrap}
  .knapp{color:#ff8f86}
  .step-h{font-size:30px;font-weight:800;margin:2px 0 6px}
  .step-sub{color:var(--muted);font-size:16px}
  .count{font-size:16px;color:var(--muted)}
  a.back{color:var(--muted);text-decoration:none;font-size:16px}
</style>
</head><body><div class="wrap">

<?php if (!$werkUid): // ===================== PIN-LOGIN ===================== ?>
  <div class="topbar"><div class="brand">bulkify <b>Produktion</b></div></div>
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
    <div class="brand">bulkify <b>Produktion</b></div>
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

  <?php if ($alleFertig || !$cur): ?>
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
  ?>
  <div class="panel" style="border-color:var(--gruen)">
    <div class="step-sub">Jetzt dran · Schritt <?= $fertigCnt + 1 ?> von <?= $total ?></div>
    <div class="step-h"><?= h((string)$cur['station']) ?></div>
    <?php if ($anl !== ''): ?><div class="muted" style="font-size:18px;margin-bottom:6px"><?= h($anl) ?></div><?php endif; ?>

    <?php if (!empty($mat['zeilen'])): ?>
    <div class="muted" style="margin-top:14px">Aus dem Lager holen<?php if (($mat['soll_menge'] ?? null) !== null): ?> · benötigt <strong style="color:var(--text)"><?= menge_txt($mat['soll_menge']) ?> <?= h((string)($mat['soll_einheit'] ?? '')) ?></strong><?php endif; ?>:</div>
    <table class="mat">
      <thead><tr><th>Material</th><th class="num">Menge</th><th class="num">Bestand</th></tr></thead>
      <tbody>
        <?php foreach ($mat['zeilen'] as $z): $pflicht = $z['pflicht'] ?? true;
              $knapp = $pflicht && isset($z['verfuegbar']) && (float)$z['verfuegbar'] + 0.0001 < (float)$z['menge']; ?>
        <tr>
          <td><?= h((string)$z['name']) ?><?php if (!empty($z['detail'])): ?> <span class="muted" style="font-size:14px">· <?= h((string)$z['detail']) ?><?= $pflicht ? '' : ' (zur Info)' ?></span><?php endif; ?></td>
          <td class="num"><?= menge_txt($z['menge']) ?> <?= h((string)$z['einheit']) ?></td>
          <td class="num <?= $knapp ? 'knapp' : '' ?>"><?= isset($z['verfuegbar']) ? menge_txt($z['verfuegbar']) . ' ' . h((string)$z['einheit']) : '–' ?></td>
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

</div></body></html>
