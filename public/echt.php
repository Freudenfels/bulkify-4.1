<?php
// echt.php – ÖFFENTLICHE Echtheits-/Qualitätsprüfung (kein Login).
// Aufruf: /echt.php?t=<token>  (das Token steht als QR-Code auf dem bulkify-CoA/Spec einer Charge).
// Zeigt NUR kundensichere Angaben zum Rohstoff dieser Charge (dieselben wie im Dokument) und bestätigt,
// dass sie von bulkify (Maniso GmbH) veröffentlicht wurden. Logik: core/echtheit.php. KEINE internen Daten.
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/schema.php';
require_once __DIR__ . '/../core/pdf_beleg.php';   // beleg_firma()
require_once __DIR__ . '/../core/echtheit.php';

init_schema();   // sichert neue Spalten (echt_token); nach dem ersten Lauf pro Deploy nur eine meta_get-Abfrage

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');                    // Tokens nicht indexieren
header('Referrer-Policy: no-referrer');

$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$fmtD = fn($d) => $d ? date('d.m.Y', strtotime((string) $d)) : '';

// Zwei Einstiege: ?t=<token> = Charge (CoA/Spec-QR), ?p=<token> = Produkt (PIB-QR).
$tProd  = isset($_GET['p']) ? (string) $_GET['p'] : '';
$modus  = $tProd !== '' ? 'produkt' : 'charge';
$token  = $modus === 'produkt' ? $tProd : (isset($_GET['t']) ? (string) $_GET['t'] : '');
$daten  = null;
try {
    $daten = $modus === 'produkt' ? produkt_echt_daten($token) : charge_echt_daten($token);
} catch (\Throwable $ex) { error_log('echt.php: ' . $ex->getMessage()); $daten = null; }

$firmaName = function_exists('beleg_firma') ? (string) (beleg_firma()['name'] ?? 'Maniso GmbH') : 'Maniso GmbH';

// Zustand bestimmen
$zustand = 'ok';
if ($daten === null)                       { $zustand = 'unbekannt'; http_response_code(404); }
elseif (!empty($daten['gesperrt']))        { $zustand = 'gesperrt'; }
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Echtheitsprüfung · bulkify</title>
<style>
  :root{
    --dunkel:#10210f; --lime:#C0F24E; --gruen:#1D9E75; --gold:#C8A24B;
    --ink:#1b2a19; --muted:#5f6e5c; --line:#e2e8df; --bg:#f4f7f1; --card:#ffffff;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);font-family:Arial,Helvetica,sans-serif;line-height:1.5}
  .top{background:var(--dunkel);color:#fff;padding:22px 20px}
  .wrap{max-width:720px;margin:0 auto;padding:0 20px}
  .brand{font-size:24px;font-weight:700;letter-spacing:.3px}
  .brand span{color:var(--lime)}
  .top .sub{color:#cfe0c6;font-size:14px;margin-top:4px}
  main{max-width:720px;margin:0 auto;padding:24px 20px 48px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:24px;margin-top:22px}
  .badge{display:inline-flex;align-items:center;gap:10px;padding:10px 16px;border-radius:999px;font-weight:700;font-size:15px}
  .badge.ok{background:rgba(29,158,117,.12);color:var(--gruen)}
  .badge.warn{background:#fdecec;color:#b42318}
  .badge .dot{width:12px;height:12px;border-radius:50%}
  .badge.ok .dot{background:var(--gruen)}
  .badge.warn .dot{background:#b42318}
  h1{font-size:22px;margin:16px 0 2px}
  .muted{color:var(--muted)}
  dl.grid{display:grid;grid-template-columns:180px 1fr;gap:0;margin:18px 0 0;border:1px solid var(--line);border-radius:10px;overflow:hidden}
  dl.grid dt{background:#f0f4ee;color:#415a3e;padding:11px 14px;font-weight:400;border-bottom:1px solid var(--line)}
  dl.grid dd{margin:0;padding:11px 14px;border-bottom:1px solid var(--line)}
  dl.grid dt:last-of-type,dl.grid dd:last-of-type{border-bottom:0}
  @media(max-width:560px){dl.grid{grid-template-columns:1fr}dl.grid dt{border-bottom:0;padding-bottom:0;background:transparent}dl.grid dd{padding-top:2px}}
  table{width:100%;border-collapse:collapse;margin-top:12px;font-size:14px}
  thead th{background:var(--dunkel);color:#fff;text-align:left;padding:10px 12px;font-weight:400}
  tbody td{padding:9px 12px;border-bottom:1px solid var(--line);vertical-align:top}
  tbody tr:nth-child(even){background:#f7faf5}
  .sec{margin-top:26px;font-size:13px;color:#415a3e;font-weight:400;text-transform:uppercase;letter-spacing:.6px}
  .note{margin-top:20px;font-size:13px;color:var(--muted)}
  footer{max-width:720px;margin:0 auto;padding:0 20px 40px;color:var(--muted);font-size:12px}
  .foot-line{border-top:1px solid var(--line);margin-top:10px;padding-top:12px}
</style>
</head>
<body>
<header class="top">
  <div class="wrap">
    <div class="brand">bulkify<span>.</span></div>
    <div class="sub">Qualitäts- und Echtheitsnachweis</div>
  </div>
</header>

<main>
<?php if ($zustand === 'unbekannt'): ?>
  <div class="card">
    <span class="badge warn"><span class="dot"></span>Nicht bestätigt</span>
    <h1>Kein gültiger Code</h1>
    <p class="muted">Zu diesem Code liegt kein Nachweis vor. Bitte prüfen Sie, ob der QR-Code vollständig gescannt wurde. Bei Zweifeln wenden Sie sich an <?= $e($firmaName) ?>.</p>
  </div>

<?php elseif ($zustand === 'gesperrt'): ?>
  <div class="card">
    <span class="badge warn"><span class="dot"></span>Nicht mehr gültig</span>
    <h1>Dieser Nachweis wurde zurückgezogen</h1>
    <p class="muted">Der Code war einmal gültig, ist aber nicht mehr freigegeben. Bitte wenden Sie sich an <?= $e($firmaName) ?>.</p>
  </div>

<?php elseif ($modus === 'produkt'): ?>
  <div class="card">
    <span class="badge ok"><span class="dot"></span>Echtes bulkify-Produkt</span>
    <h1><?= $e($daten['name']) ?></h1>
    <p class="muted">Bestätigt durch <?= $e($daten['firma']) ?> · Marke bulkify<?= $daten['form'] !== '' ? ' · ' . $e($daten['form']) : '' ?></p>

    <?php if (!empty($daten['chargen'])): ?>
      <div class="sec">Bestätigte Chargen</div>
      <table>
        <thead><tr><th>Charge</th><th>Mindesthaltbar bis</th><th>Nachweis</th></tr></thead>
        <tbody>
        <?php foreach ($daten['chargen'] as $ch): ?>
          <tr>
            <td><?= $e(($ch['charge_nr'] ?? '') !== '' ? $ch['charge_nr'] : '–') ?></td>
            <td><?= $e($fmtD($ch['mhd'] ?? null) ?: '–') ?></td>
            <td><a href="echt.php?t=<?= $e(rawurlencode((string) $ch['echt_token'])) ?>">Charge prüfen</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="note">Für dieses Produkt sind derzeit keine einzelnen Chargen-Nachweise hinterlegt. Der Nachweis je Charge liegt dem jeweiligen Produkt als Analysenzertifikat bzw. Spezifikation bei.</p>
    <?php endif; ?>

    <p class="note">Diese Seite bestätigt, dass es sich um ein von <?= $e($daten['firma']) ?> (Marke bulkify) hergestelltes Produkt handelt.</p>
  </div>

<?php else: ?>
  <div class="card">
    <span class="badge ok"><span class="dot"></span>Echtheit bestätigt</span>
    <h1><?= $e($daten['name']) ?></h1>
    <p class="muted">Bestätigt durch <?= $e($daten['firma']) ?> · Marke bulkify<?= $daten['ist_coa'] ? ' · mit Analysenzertifikat (CoA)' : ' · Spezifikation' ?></p>

    <dl class="grid">
      <dt>Rohstoff</dt><dd><?= $e($daten['name']) ?></dd>
      <?php if ($daten['charge_nr'] !== ''): ?><dt>Charge</dt><dd><?= $e($daten['charge_nr']) ?></dd><?php endif; ?>
      <?php if (!empty($daten['mhd'])): ?><dt>Mindesthaltbar bis</dt><dd><?= $e($fmtD($daten['mhd'])) ?></dd><?php endif; ?>
      <?php if ($daten['herkunft'] !== ''): ?><dt>Herkunft</dt><dd><?= $e($daten['herkunft']) ?></dd><?php endif; ?>
      <?php if ($daten['spec'] !== ''): ?><dt>Spezifikation</dt><dd><?= $e($daten['spec']) ?></dd><?php endif; ?>
    </dl>

    <?php if (!empty($daten['werte'])): ?>
      <div class="sec">Analysenwerte</div>
      <table>
        <thead><tr><th>Parameter</th><th>Spezifikation</th><th>Ergebnis</th><th>Methode</th></tr></thead>
        <tbody>
        <?php foreach ($daten['werte'] as $w): ?>
          <tr>
            <td><?= $e($w['parameter'] ?? '') ?></td>
            <td><?= $e(($w['spezifikation'] ?? '') !== '' ? $w['spezifikation'] : '–') ?></td>
            <td><?= $e(($w['ergebnis'] ?? '') !== '' ? $w['ergebnis'] : '–') ?></td>
            <td><?= $e($w['methode'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <?php if ($daten['allergene'] !== ''): ?>
      <div class="sec">Allergene</div>
      <p style="margin-top:8px"><?= $e($daten['allergene']) ?></p>
    <?php endif; ?>

    <p class="note">Diese Seite bestätigt, dass die oben gezeigten Angaben zu dieser Charge von <?= $e($daten['firma']) ?> veröffentlicht wurden. Sie entspricht dem Analysenzertifikat bzw. der Spezifikation, die dem Produkt beiliegt.</p>
  </div>
<?php endif; ?>
</main>

<footer>
  <div class="foot-line">bulkify&reg; · Marke der <?= $e($firmaName) ?>. Öffentlicher Qualitätsnachweis, maschinell erzeugt.</div>
</footer>
</body>
</html>
