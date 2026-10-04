<?php
// Öffentliche Handy-Foto-Upload-Seite (Token-geschützt, OHNE Login). Route: beleg_foto (public).
// Zweck: unterwegs schnell einen Beleg abfotografieren – er landet als „neu" im Posteingang (Quelle foto).
// Keine Liste, kein Zugriff auf Daten – nur Hochladen. Eigene, schlanke HTML-Hülle (keine Team-Navigation).
require_once BX_ROOT . '/core/belegeingang.php';
be_init();

$token = (string)($_GET['token'] ?? ($_POST['token'] ?? ''));
$ok = be_token_ok($token);

$meldung = ''; $erfolg = false;
if ($ok && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $n = 0;
    $files = $_FILES['belege'] ?? null;
    if ($files && is_array($files['name'])) {
        for ($i = 0; $i < count($files['name']); $i++) {
            if (($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK) continue;
            $one = ['name'=>$files['name'][$i],'type'=>$files['type'][$i],'tmp_name'=>$files['tmp_name'][$i],'error'=>$files['error'][$i],'size'=>$files['size'][$i]];
            $g = be_datei_speichern($one);
            if (!$g) continue;
            $ki = be_ki_auslesen($g['pfad']);
            be_anlegen(($ki['ok'] ? $ki['daten'] : []) + ['quelle'=>'foto','datei'=>$g['datei'],'orig_name'=>$g['orig'],'mime'=>$g['mime'],'status'=>'neu','ki_ok'=>$ki['ok'],'ki_json'=>$ki['roh'] ?? null]);
            $n++;
        }
    }
    $erfolg = $n > 0;
    $meldung = $erfolg ? "$n Beleg(e) eingegangen – danke! Du kannst gleich den nächsten fotografieren." : 'Keine gültige Datei – bitte erneut versuchen (PDF/Foto).';
}

$css = (int) @filemtime(dirname(BX_ROOT) . '/public/assets/app.css');
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="de"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Beleg hochladen – <?= htmlspecialchars(BX_MARKE) ?></title>
<link rel="stylesheet" href="/assets/app.css?v=<?= $css ?>">
<style>body{background:var(--bg,#0f1613)}.wrap{max-width:460px;margin:6vh auto;padding:0 16px}</style>
</head><body>
<div class="wrap">
  <div class="bx-panel">
    <h1 style="margin-top:0"><?= htmlspecialchars(BX_MARKE) ?> <span class="muted">Beleg hochladen</span></h1>
    <?php if (!$ok): ?>
      <p>Dieser Link ist ungültig oder abgelaufen. Bitte im Buchhaltungs-Posteingang einen neuen Foto-Link erzeugen.</p>
    <?php else: ?>
      <?php if ($meldung): ?><div class="bx-panel <?= $erfolg ? 'badge-ok' : '' ?>" style="padding:10px 14px;<?= $erfolg ? '' : 'border-color:#e6c4c0;color:#8f231b' ?>"><?= htmlspecialchars($meldung) ?></div><?php endif; ?>
      <p class="bx-sub">Beleg abfotografieren oder PDF wählen – die KI liest ihn aus, du musst nichts eintippen.</p>
      <form method="post" enctype="multipart/form-data" class="bx-form">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <div class="bx-field">
          <input type="file" name="belege[]" accept="image/*,application/pdf" capture="environment" multiple required>
        </div>
        <div class="bx-row" style="margin-top:12px">
          <button class="btn btn-primary" type="submit" data-busy="Wird hochgeladen …">Hochladen</button>
        </div>
      </form>
      <p class="muted" style="margin-top:14px;font-size:12px">Tipp: diese Seite als Lesezeichen/Startbildschirm speichern.</p>
    <?php endif; ?>
  </div>
</div>
</body></html>
<?php
exit;
