<?php
// Druckbarer Mahnbrief (eine Mahnung). Eigenständiges, schlankes HTML-Dokument (kein Sidebar-Shell),
// damit es sauber gedruckt / als PDF gespeichert werden kann. Route: mahnung (?id=<mahnung-id>).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/mahnung.php';
require_once BX_ROOT . '/core/pdf_beleg.php';   // beleg_firma()
require_once BX_ROOT . '/core/erp.php';         // erp_kunde()

$id = (int)($_GET['id'] ?? 0);
$m = $id ? mahn_get($id) : null;
if (!$m) { http_response_code(404); echo 'Mahnung nicht gefunden.'; exit; }

$cfg = mahn_config();
$fa = beleg_firma();
$k = !empty($m['kunde_id']) ? erp_kunde((int)$m['kunde_id']) : null;
$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';

// Empfängeradresse (Rechnungsadresse bevorzugt)
$empFirma = $k ? (trim((string)($k['rechnung_firma'] ?? '')) ?: (string)$k['firma']) : 'Kunde';
$str = $k ? (trim((string)($k['rechnung_strasse'] ?? '')) ?: (string)($k['strasse'] ?? '')) : '';
$hnr = $k ? (trim((string)($k['rechnung_hausnummer'] ?? '')) ?: (string)($k['hausnummer'] ?? '')) : '';
$plz = $k ? (trim((string)($k['rechnung_plz'] ?? '')) ?: (string)($k['plz'] ?? '')) : '';
$ort = $k ? (trim((string)($k['rechnung_ort'] ?? '')) ?: (string)($k['ort'] ?? '')) : '';
$land = $k ? (trim((string)($k['rechnung_land'] ?? '')) ?: (string)($k['land'] ?? '')) : '';
$empZeilen = array_values(array_filter([$empFirma, trim($str . ' ' . $hnr), trim($plz . ' ' . $ort),
    (strtoupper(trim($land)) !== '' && !in_array(strtoupper(trim($land)), ['DE','D','DEUTSCHLAND','GERMANY'], true)) ? $land : '']));

$stufe = (int)$m['stufe'];
$label = mahn_stufe_label($stufe);
$text = (string)($cfg['stufen'][$stufe]['text'] ?? '');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html><html lang="de"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($label . ' ' . $m['nummer']) ?></title>
<style>
  :root{--ink:#1a1a1a;--muted:#666;--line:#ccc}
  *{box-sizing:border-box}
  body{font-family:'Segoe UI',Arial,sans-serif;color:var(--ink);background:#f3f4f6;margin:0;padding:24px}
  .sheet{background:#fff;max-width:760px;margin:0 auto;padding:48px 56px;box-shadow:0 1px 6px rgba(0,0,0,.12)}
  .toolbar{max-width:760px;margin:0 auto 16px;display:flex;gap:8px;justify-content:flex-end}
  .btn{display:inline-block;padding:8px 14px;border:1px solid var(--line);border-radius:8px;background:#fff;color:var(--ink);text-decoration:none;font-size:14px;cursor:pointer}
  .btn.primary{background:#10210f;color:#fff;border-color:#10210f}
  .firma{text-align:right;font-size:12px;color:var(--muted);line-height:1.5}
  .firma .nm{font-weight:600;color:var(--ink)}
  .absenderzeile{font-size:9px;color:var(--muted);border-bottom:1px solid var(--line);padding-bottom:3px;margin:28px 0 6px}
  .emp{font-size:15px;line-height:1.5;margin-bottom:28px}
  .datum{text-align:right;font-size:13px;color:var(--muted);margin-bottom:18px}
  h1{font-size:19px;margin:0 0 4px}
  .sub{color:var(--muted);font-size:13px;margin:0 0 20px}
  p{line-height:1.6;font-size:14px}
  table{width:100%;border-collapse:collapse;margin:18px 0}
  th,td{text-align:left;padding:8px 6px;border-bottom:1px solid var(--line);font-size:14px}
  td.num,th.num{text-align:right}
  tfoot td{font-weight:600;border-top:2px solid var(--ink);border-bottom:none}
  .konto{background:#f6f7f5;border:1px solid var(--line);border-radius:8px;padding:12px 16px;font-size:13px;line-height:1.6;margin-top:18px}
  .gruss{margin-top:28px;font-size:14px}
  @media print{body{background:#fff;padding:0}.toolbar{display:none}.sheet{box-shadow:none;max-width:none;margin:0;padding:24mm 20mm}}
</style></head>
<body>
<div class="toolbar">
  <a class="btn" href="?p=mahnlauf">Zurück</a>
  <a class="btn primary" href="javascript:window.print()">Drucken / als PDF speichern</a>
</div>
<div class="sheet">
  <div class="firma">
    <div class="nm"><?= $h($fa['name']) ?></div>
    <div><?= $h($fa['strasse']) ?></div>
    <div><?= $h($fa['plz_ort']) ?></div>
    <?php if ($fa['email'] !== ''): ?><div><?= $h($fa['email']) ?></div><?php endif; ?>
    <?php if (($fa['ust_id'] ?? '') !== ''): ?><div>USt-IdNr. <?= $h($fa['ust_id']) ?></div><?php endif; ?>
  </div>
  <div class="absenderzeile"><?= $h(trim($fa['name'] . ', ' . $fa['strasse'] . ', ' . $fa['plz_ort'], ', ')) ?></div>
  <div class="emp"><?php foreach ($empZeilen as $z): ?><div><?= $h($z) ?></div><?php endforeach; ?></div>
  <div class="datum"><?= $h(($fa['plz_ort'] ? explode(' ', trim($fa['plz_ort']), 2)[1] ?? '' : '') ) ?><?= $m['datum'] ? ', den ' . $h(date('d.m.Y', strtotime((string)$m['datum']))) : '' ?></div>

  <h1><?= $h($label) ?></h1>
  <div class="sub">Mahnung <?= $h($m['nummer']) ?> · zu Rechnung <?= $h($m['beleg_nr']) ?></div>

  <p>Sehr geehrte Damen und Herren,</p>
  <p><?= nl2br($h($text)) ?></p>

  <table>
    <thead><tr><th>Beleg</th><th>Rechnungsdatum</th><th>Fällig war</th><th class="num">Betrag</th></tr></thead>
    <tbody>
      <tr>
        <td>Rechnung <?= $h($m['beleg_nr']) ?></td>
        <td><?= $m['beleg_datum'] ? $h(date('d.m.Y', strtotime((string)$m['beleg_datum']))) : '' ?></td>
        <td><?= $m['beleg_faellig'] ? $h(date('d.m.Y', strtotime((string)$m['beleg_faellig']))) : '' ?></td>
        <td class="num"><?= $eur($m['offen']) ?></td>
      </tr>
      <?php if ((float)$m['gebuehr'] > 0): ?><tr><td colspan="3">Mahngebühr</td><td class="num"><?= $eur($m['gebuehr']) ?></td></tr><?php endif; ?>
      <?php if ((float)$m['zins'] > 0): ?><tr><td colspan="3">Verzugszinsen</td><td class="num"><?= $eur($m['zins']) ?></td></tr><?php endif; ?>
    </tbody>
    <tfoot><tr><td colspan="3">Zu zahlender Gesamtbetrag</td><td class="num"><?= $eur($m['summe']) ?></td></tr></tfoot>
  </table>

  <p>Bitte überweisen Sie den Gesamtbetrag von <strong><?= $eur($m['summe']) ?></strong> bis spätestens <strong><?= $m['faellig_neu'] ? $h(date('d.m.Y', strtotime((string)$m['faellig_neu']))) : '' ?></strong> auf das unten genannte Konto. Sollten Sie den Betrag zwischenzeitlich bereits überwiesen haben, betrachten Sie dieses Schreiben bitte als gegenstandslos.</p>

  <?php if (($fa['bank_de_iban'] ?? '') !== ''): ?>
  <div class="konto">
    <div><strong>Bankverbindung:</strong> <?= $h($fa['bank_de_name'] ?? '') ?></div>
    <div>IBAN: <?= $h($fa['bank_de_iban']) ?><?php if (($fa['bank_de_bic'] ?? '') !== ''): ?> · BIC: <?= $h($fa['bank_de_bic']) ?><?php endif; ?></div>
    <div>Verwendungszweck: <?= $h($m['beleg_nr']) ?></div>
  </div>
  <?php endif; ?>

  <div class="gruss">Mit freundlichen Grüßen<br><br><?= $h($fa['name']) ?></div>
</div>
</body></html>
<?php
// Kein render_footer – dies ist ein eigenständiges Druckdokument.
