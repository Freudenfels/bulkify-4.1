<?php
// Buchhaltung – Finanz-Hub (Route: buchhaltung, Rolle finance)
// Reiter: Übersicht, Offene Posten (je Kunde), Auswertung (Umsatz), Prüfung (GoBD/Nummernkreis), Export.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/buchhaltung.php';
require_once BX_ROOT . '/core/kreditor.php';
kreditor_init();

$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
$tab = preg_replace('/[^a-z]/', '', $_GET['tab'] ?? 'uebersicht') ?: 'uebersicht';

render_header('buchhaltung', 'Buchhaltung');
bx_head('Buchhaltung', 'Finanzübersicht · Forderungen, Verbindlichkeiten, Export');
bx_tabs([
    'uebersicht'       => 'Übersicht',
    'op'               => 'Offene Posten',
    'verbindlichkeiten'=> 'Verbindlichkeiten',
    'auswertung'       => 'Auswertung',
    'pruefung'         => 'Prüfung',
    'export'           => 'Export',
], $tab, '?p=buchhaltung');

// Kennzahl-Kachel
function fkachel(string $k, $v, string $href, string $farbe = ''): void {
    echo '<a class="bx-card" style="text-decoration:none;min-width:170px" href="' . h($href) . '">'
       . '<div class="k">' . h($k) . '</div><div class="v" style="' . $farbe . '">' . $v . '</div></a>';
}

// ===========================================================================
if ($tab === 'uebersicht'):
// ===========================================================================
$op = (float) scalar(
    "SELECT COALESCE(SUM(b.brutto - COALESCE(z.bez,0)),0) FROM beleg b
       LEFT JOIN (SELECT beleg_id, SUM(betrag) bez FROM zahlung GROUP BY beleg_id) z ON z.beleg_id=b.id
      WHERE b.typ='rechnung' AND b.status IN ('offen','teilbezahlt')");
$op_ueberfaellig = (float) scalar(
    "SELECT COALESCE(SUM(b.brutto - COALESCE(z.bez,0)),0) FROM beleg b
       LEFT JOIN (SELECT beleg_id, SUM(betrag) bez FROM zahlung GROUP BY beleg_id) z ON z.beleg_id=b.id
      WHERE b.typ='rechnung' AND b.status IN ('offen','teilbezahlt') AND b.faellig IS NOT NULL AND b.faellig < CURDATE()");
$anz_offen = (int) scalar("SELECT COUNT(*) FROM beleg WHERE typ='rechnung' AND status IN ('offen','teilbezahlt')");
$umsatz_jahr = (float) scalar(
    "SELECT COALESCE(SUM(CASE WHEN typ='gutschrift' THEN -netto ELSE netto END),0) FROM beleg
      WHERE typ IN ('rechnung','gutschrift') AND status<>'storniert' AND datum IS NOT NULL AND YEAR(datum)=YEAR(CURDATE())");
$umsatz_monat = (float) scalar(
    "SELECT COALESCE(SUM(CASE WHEN typ='gutschrift' THEN -netto ELSE netto END),0) FROM beleg
      WHERE typ IN ('rechnung','gutschrift') AND status<>'storniert' AND datum IS NOT NULL AND YEAR(datum)=YEAR(CURDATE()) AND MONTH(datum)=MONTH(CURDATE())");
$anz_gutschrift = (int) scalar("SELECT COUNT(*) FROM beleg WHERE typ='gutschrift'");
$verb = kr_op_summe();                 // offene Verbindlichkeiten (wir schulden Lieferanten)
$verb_ue = kr_op_ueberfaellig_summe();
$saldo = $op - $verb;                  // Forderungen minus Verbindlichkeiten

$ueberfaellig = all(
    "SELECT b.*, k.firma, (b.brutto - COALESCE(z.bez,0)) AS rest, DATEDIFF(CURDATE(), b.faellig) AS tage
       FROM beleg b LEFT JOIN kunden k ON k.id=b.kunde_id
       LEFT JOIN (SELECT beleg_id, SUM(betrag) bez FROM zahlung GROUP BY beleg_id) z ON z.beleg_id=b.id
      WHERE b.typ='rechnung' AND b.status IN ('offen','teilbezahlt') AND b.faellig IS NOT NULL AND b.faellig < CURDATE()
      ORDER BY b.faellig ASC LIMIT 8");
$zuletzt_bezahlt = all(
    "SELECT b.*, k.firma FROM beleg b LEFT JOIN kunden k ON k.id=b.kunde_id
      WHERE b.typ='rechnung' AND b.status='bezahlt' ORDER BY b.datum DESC, b.id DESC LIMIT 8");
$jahr = (int) date('Y');

echo '<div class="bx-cards">';
fkachel('Forderungen (offen)', $op > 0 ? $eur($op) : '<span class="muted">0 €</span>', '?p=buchhaltung&tab=op', $op > 0 ? 'color:var(--warn)' : '');
fkachel('davon überfällig', $op_ueberfaellig > 0 ? $eur($op_ueberfaellig) : '<span class="muted">0 €</span>', '?p=buchhaltung&tab=op', $op_ueberfaellig > 0 ? 'color:var(--err)' : '');
fkachel('Verbindlichkeiten (offen)', $verb > 0 ? $eur($verb) : '<span class="muted">0 €</span>', '?p=buchhaltung&tab=verbindlichkeiten', $verb > 0 ? 'color:var(--warn)' : '');
fkachel('davon überfällig', $verb_ue > 0 ? $eur($verb_ue) : '<span class="muted">0 €</span>', '?p=buchhaltung&tab=verbindlichkeiten', $verb_ue > 0 ? 'color:var(--err)' : '');
fkachel('Saldo (Ford. − Verb.)', $eur($saldo), '?p=buchhaltung&tab=verbindlichkeiten', $saldo >= 0 ? 'color:var(--gruen)' : 'color:var(--err)');
fkachel('Umsatz ' . $jahr, $eur($umsatz_jahr), '?p=buchhaltung&tab=auswertung', $umsatz_jahr > 0 ? 'color:var(--gruen)' : '');
echo '</div>';
?>
<form class="bx-listbar" method="get">
  <span class="muted" style="align-self:center">Schnellaktionen</span>
  <span style="flex:1"></span>
  <a class="btn btn-ghost btn-sm" href="?p=rechnungen">Alle Rechnungen</a>
  <a class="btn btn-ghost btn-sm" href="?p=gutschrift_neu">Storno-Rechnung</a>
  <a class="btn btn-ghost btn-sm" href="?p=rechnung_import">Alt-Rechnungen importieren</a>
  <a class="btn btn-ghost btn-sm" href="?p=auftrag_import">Auftrag aus Angebot importieren</a>
  <a class="btn btn-primary btn-sm" href="?p=rechnung_frei">+ Rechnung erstellen</a>
</form>
<div class="bx-cards" style="align-items:flex-start">
  <div class="bx-panel" style="flex:1;min-width:360px">
    <h2>Überfällige Rechnungen<?= $op_ueberfaellig > 0 ? ' · ' . h($eur($op_ueberfaellig)) : '' ?></h2>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Nummer</th><th>Kunde</th><th>Fällig</th><th class="bx-num">Offen</th></tr></thead>
      <tbody>
        <?php if (!$ueberfaellig): ?><tr><td colspan="4" class="muted">Keine überfälligen Rechnungen.</td></tr><?php endif; ?>
        <?php foreach ($ueberfaellig as $b): ?>
          <tr style="cursor:pointer" onclick="location.href='?p=rechnung&id=<?= (int)$b['id'] ?>'">
            <td><strong><?= h($b['nummer']) ?></strong></td>
            <td><?= kunde_link($b['kunde_id'] ?? null, $b['firma']) ?></td>
            <td><?= h(date('d.m.Y', strtotime($b['faellig']))) ?> <span class="muted">(<?= (int)$b['tage'] ?> T)</span></td>
            <td class="bx-num"><?= $eur($b['rest']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="bx-panel" style="flex:1;min-width:360px">
    <h2>Zuletzt bezahlt</h2>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Nummer</th><th>Kunde</th><th>Datum</th><th class="bx-num">Brutto</th></tr></thead>
      <tbody>
        <?php if (!$zuletzt_bezahlt): ?><tr><td colspan="4" class="muted">Noch keine bezahlten Rechnungen.</td></tr><?php endif; ?>
        <?php foreach ($zuletzt_bezahlt as $b): ?>
          <tr style="cursor:pointer" onclick="location.href='?p=rechnung&id=<?= (int)$b['id'] ?>'">
            <td><strong><?= h($b['nummer']) ?></strong></td>
            <td><?= kunde_link($b['kunde_id'] ?? null, $b['firma']) ?></td>
            <td><?= $b['datum'] ? h(date('d.m.Y', strtotime($b['datum']))) : '' ?></td>
            <td class="bx-num"><?= $eur($b['brutto']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>
<?php
// ===========================================================================
elseif ($tab === 'op'):
// ===========================================================================
$rows = bh_op_je_kunde();
$sumOffen = 0.0; $sumUe = 0.0;
foreach ($rows as $r) { $sumOffen += (float)$r['offen']; $sumUe += (float)$r['ueberfaellig']; }
?>
<div class="bx-cards">
  <?php fkachel('Offene Posten gesamt', $eur($sumOffen), '#', $sumOffen > 0 ? 'color:var(--warn)' : ''); ?>
  <?php fkachel('Davon überfällig', $eur($sumUe), '#', $sumUe > 0 ? 'color:var(--err)' : ''); ?>
  <?php fkachel('Kunden mit OP', count($rows) ?: '<span class="muted">0</span>', '#'); ?>
</div>
<form class="bx-listbar" method="get">
  <span class="muted" style="align-self:center">Offene Posten je Kunde</span>
  <span style="flex:1"></span>
  <a class="btn btn-ghost btn-sm" href="?p=beleg_export&art=op">OP-Liste als CSV</a>
</form>
<div class="bx-tablewrap"><table class="bx-table">
  <thead><tr><th>Kunde</th><th class="bx-num">Rechnungen</th><th class="bx-num">Offen</th><th class="bx-num">Überfällig</th></tr></thead>
  <tbody>
    <?php if (!$rows): ?><tr><td colspan="4" class="muted">Keine offenen Posten.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= kunde_link($r['kunde_id'] ?? null, $r['firma'] ?: '(ohne Kunde)') ?></td>
        <td class="bx-num"><?= (int)$r['anz'] ?></td>
        <td class="bx-num"><?= $eur($r['offen']) ?></td>
        <td class="bx-num"><?= (float)$r['ueberfaellig'] > 0 ? '<span style="color:var(--err)">' . h($eur($r['ueberfaellig'])) . '</span>' : '<span class="muted">–</span>' ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table></div>
<?php
// ===========================================================================
elseif ($tab === 'verbindlichkeiten'):
// ===========================================================================
$jeLief = kr_op_je_lieferant();
$offeneRg = kr_liste('offen');
$verb = kr_op_summe(); $verbUe = kr_op_ueberfaellig_summe();
?>
<div class="bx-cards">
  <?php fkachel('Verbindlichkeiten gesamt', $eur($verb), '#', $verb > 0 ? 'color:var(--warn)' : ''); ?>
  <?php fkachel('Davon überfällig', $eur($verbUe), '#', $verbUe > 0 ? 'color:var(--err)' : ''); ?>
  <?php fkachel('Lieferanten mit OP', count($jeLief) ?: '<span class="muted">0</span>', '#'); ?>
</div>
<form class="bx-listbar" method="get">
  <span class="muted" style="align-self:center">Wir schulden Lieferanten</span>
  <span style="flex:1"></span>
  <a class="btn btn-ghost btn-sm" href="?p=beleg_export&art=vop">Verbindlichkeiten als CSV</a>
  <a class="btn btn-primary btn-sm" href="?p=lief_rechnung_neu">+ Eingangsrechnung erfassen</a>
</form>
<div class="bx-cards" style="align-items:flex-start">
  <div class="bx-panel" style="flex:1;min-width:340px">
    <h2>Offene Beträge je Lieferant</h2>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Lieferant</th><th class="bx-num">Rechnungen</th><th class="bx-num">Offen</th><th class="bx-num">Überfällig</th></tr></thead>
      <tbody>
        <?php if (!$jeLief): ?><tr><td colspan="4" class="muted">Keine offenen Verbindlichkeiten.</td></tr><?php endif; ?>
        <?php foreach ($jeLief as $l): ?>
          <tr>
            <td><?= h($l['firma'] ?: '(ohne Lieferant)') ?></td>
            <td class="bx-num"><?= (int)$l['anz'] ?></td>
            <td class="bx-num"><?= $eur($l['offen']) ?></td>
            <td class="bx-num"><?= (float)$l['ueberfaellig'] > 0 ? '<span style="color:var(--err)">' . h($eur($l['ueberfaellig'])) . '</span>' : '<span class="muted">–</span>' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="bx-panel" style="flex:1;min-width:340px">
    <h2>Offene Eingangsrechnungen</h2>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Nummer</th><th>Lieferant</th><th>Fällig</th><th class="bx-num">Offen</th></tr></thead>
      <tbody>
        <?php if (!$offeneRg): ?><tr><td colspan="4" class="muted">Nichts offen.</td></tr><?php endif; ?>
        <?php foreach ($offeneRg as $r): $ue = $r['faellig'] && strtotime($r['faellig']) < strtotime(date('Y-m-d')); ?>
          <tr style="cursor:pointer" onclick="location.href='?p=lief_rechnung&id=<?= (int)$r['id'] ?>'">
            <td><strong><?= h($r['nummer']) ?></strong><?= $r['lief_nummer'] ? ' <span class="muted">· ' . h($r['lief_nummer']) . '</span>' : '' ?></td>
            <td><?= h($r['firma'] ?? '') ?></td>
            <td><?= $r['faellig'] ? ('<span' . ($ue ? ' style="color:var(--err)"' : '') . '>' . h(date('d.m.Y', strtotime($r['faellig']))) . '</span>') : '<span class="muted">–</span>' ?></td>
            <td class="bx-num"><?= $eur($r['rest']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>
<?php
// ===========================================================================
elseif ($tab === 'auswertung'):
// ===========================================================================
$jahre = bh_jahre();
$jahr = (int)($_GET['jahr'] ?? $jahre[0]);
if (!in_array($jahr, $jahre, true)) $jahr = $jahre[0];
$monate = bh_umsatz_monate($jahr);
$steuer = bh_umsatz_steuersatz($jahr);
$sumNetto = 0.0; $sumUst = 0.0; $sumBrutto = 0.0; $maxBrutto = 0.0;
foreach ($monate as $m) { $sumNetto += $m['netto']; $sumUst += $m['ust']; $sumBrutto += $m['brutto']; $maxBrutto = max($maxBrutto, $m['brutto']); }
$mn = ['', 'Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
?>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="buchhaltung"><input type="hidden" name="tab" value="auswertung">
  <label class="muted" style="align-self:center">Jahr</label>
  <select name="jahr" onchange="this.form.submit()">
    <?php foreach ($jahre as $j): ?><option value="<?= $j ?>" <?= $j === $jahr ? 'selected' : '' ?>><?= $j ?></option><?php endforeach; ?>
  </select>
  <span style="flex:1"></span>
  <a class="btn btn-ghost btn-sm" href="?p=beleg_export&art=belege&von=<?= $jahr ?>-01-01&bis=<?= $jahr ?>-12-31">Belege <?= $jahr ?> als CSV</a>
</form>
<div class="bx-cards">
  <?php fkachel('Umsatz netto ' . $jahr, $eur($sumNetto), '#', 'color:var(--gruen)'); ?>
  <?php fkachel('USt ' . $jahr, $eur($sumUst), '#'); ?>
  <?php fkachel('Brutto ' . $jahr, $eur($sumBrutto), '#'); ?>
</div>
<div class="bx-cards" style="align-items:flex-start">
  <div class="bx-panel" style="flex:2;min-width:420px">
    <h2>Umsatz je Monat <?= $jahr ?></h2>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Monat</th><th class="bx-num">Netto</th><th class="bx-num">USt</th><th class="bx-num">Brutto</th><th style="width:30%">&nbsp;</th></tr></thead>
      <tbody>
        <?php foreach ($monate as $i => $m): $pct = $maxBrutto > 0 ? round($m['brutto'] / $maxBrutto * 100) : 0; ?>
          <tr>
            <td><?= h($mn[$i]) ?></td>
            <td class="bx-num"><?= $m['netto'] != 0 ? h($eur($m['netto'])) : '<span class="muted">–</span>' ?></td>
            <td class="bx-num"><?= $m['ust'] != 0 ? h($eur($m['ust'])) : '<span class="muted">–</span>' ?></td>
            <td class="bx-num"><?= $m['brutto'] != 0 ? h($eur($m['brutto'])) : '<span class="muted">–</span>' ?></td>
            <td><div style="background:var(--gruen);height:12px;border-radius:3px;width:<?= $pct ?>%;min-width:<?= $pct > 0 ? 2 : 0 ?>px"></div></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td><strong>Summe</strong></td><td class="bx-num"><strong><?= $eur($sumNetto) ?></strong></td><td class="bx-num"><strong><?= $eur($sumUst) ?></strong></td><td class="bx-num"><strong><?= $eur($sumBrutto) ?></strong></td><td></td></tr></tfoot>
    </table></div>
  </div>
  <div class="bx-panel" style="flex:1;min-width:300px">
    <h2>Nach Steuersatz <?= $jahr ?></h2>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Satz</th><th class="bx-num">Netto</th><th class="bx-num">USt</th></tr></thead>
      <tbody>
        <?php if (!$steuer): ?><tr><td colspan="3" class="muted">Keine Umsätze.</td></tr><?php endif; ?>
        <?php foreach ($steuer as $s): ?>
          <tr><td><?= number_format((float)$s['ust_prozent'], 0) ?> %</td><td class="bx-num"><?= $eur($s['netto']) ?></td><td class="bx-num"><?= $eur($s['ust']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>
<?php
// ===========================================================================
elseif ($tab === 'pruefung'):
// ===========================================================================
$pr = bh_nummernkreis_pruefung();
?>
<p class="muted" style="margin:0 0 16px">GoBD-Kurzprüfung: fortlaufende Belegnummern (Lücken, Dubletten), chronologische Reihenfolge und Storno-Bezug. Rein lesend – es wird nichts verändert.</p>
<div class="bx-cards" style="align-items:flex-start">
  <?php foreach ($pr['kreise'] as $k): ?>
    <div class="bx-panel" style="flex:1;min-width:300px">
      <h2>Nummernkreis <?= h($k['prefix']) ?></h2>
      <div class="bx-tablewrap"><table class="bx-table"><tbody>
        <tr><td>Belege</td><td class="bx-num"><?= (int)$k['anzahl'] ?></td></tr>
        <tr><td>Von–Bis</td><td class="bx-num"><?= h($k['min']) ?> – <?= h($k['max']) ?></td></tr>
        <tr><td>Nächste Nummer (Zähler)</td><td class="bx-num"><?= $k['zaehler'] ? h($k['prefix'] . '-' . str_pad((string)$k['zaehler'],4,'0',STR_PAD_LEFT)) : '<span class="muted">–</span>' ?></td></tr>
        <tr><td>Lücken</td><td class="bx-num"><?= $k['luecken'] ? '<span style="color:var(--err)">' . count($k['luecken']) . '</span>' : '<span style="color:var(--gruen)">0</span>' ?></td></tr>
        <tr><td>Dubletten</td><td class="bx-num"><?= $k['dubletten'] ? '<span style="color:var(--err)">' . count($k['dubletten']) . '</span>' : '<span style="color:var(--gruen)">0</span>' ?></td></tr>
      </tbody></table></div>
      <?php if ($k['luecken']): ?><p style="margin:8px 0 0"><span class="muted">Fehlende Nummern:</span> <?= h(implode(', ', array_slice($k['luecken'], 0, 40))) ?><?= count($k['luecken']) > 40 ? ' …' : '' ?></p><?php endif; ?>
      <?php if ($k['dubletten']): ?><p style="margin:8px 0 0;color:var(--err)">Doppelt vergeben: <?= h(implode(', ', $k['dubletten'])) ?></p><?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<div class="bx-panel" style="margin-top:4px">
  <h2>Chronologie</h2>
  <?php if (!$pr['rueckdatiert']): ?>
    <p class="muted" style="margin:0">Keine Auffälligkeiten – Belegnummern laufen zeitlich aufsteigend.</p>
  <?php else: ?>
    <p style="margin:0 0 8px;color:var(--warn)">Mögliche Rückdatierung: spätere Nummer mit früherem Datum.</p>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Beleg</th><th>Datum</th><th>liegt vor</th><th>dessen Datum</th></tr></thead>
      <tbody>
        <?php foreach ($pr['rueckdatiert'] as $r): ?>
          <tr><td><?= h($r['nummer']) ?></td><td><?= h(date('d.m.Y', strtotime($r['datum']))) ?></td><td><?= h($r['vorher']) ?></td><td><?= h(date('d.m.Y', strtotime($r['vorher_datum']))) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>
<div class="bx-panel" style="margin-top:16px">
  <h2>Stornierte Rechnungen</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Nummer</th><th>Datum</th><th>Grund</th><th>Gutschrift/Storno</th></tr></thead>
    <tbody>
      <?php if (!$pr['storno']): ?><tr><td colspan="4" class="muted">Keine stornierten Rechnungen.</td></tr><?php endif; ?>
      <?php foreach ($pr['storno'] as $s): ?>
        <tr style="cursor:pointer" onclick="location.href='?p=rechnung&id=<?= (int)$s['id'] ?>'">
          <td><?= h($s['nummer']) ?></td>
          <td><?= $s['datum'] ? h(date('d.m.Y', strtotime($s['datum']))) : '' ?></td>
          <td><?= h($s['grund'] ?? '') ?></td>
          <td><?= $s['gutschriften'] ? h($s['gutschriften']) : '<span style="color:var(--warn)">keine</span>' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php
// ===========================================================================
elseif ($tab === 'export'):
// ===========================================================================
$jahr = (int) date('Y');
?>
<div class="bx-cards" style="align-items:flex-start">
  <div class="bx-panel" style="flex:1;min-width:320px">
    <h2>Offene-Posten-Liste</h2>
    <p class="muted">Alle offenen/teilbezahlten Rechnungen mit Restbetrag und Fälligkeit. CSV (Excel, UTF-8).</p>
    <a class="btn btn-primary btn-sm" href="?p=beleg_export&art=op">OP-Liste herunterladen</a>
  </div>
  <div class="bx-panel" style="flex:1;min-width:320px">
    <h2>Belege / Umsatz</h2>
    <p class="muted">Rechnungen und Gutschriften eines Zeitraums als CSV (Netto, USt, Brutto, Kunde, USt-IdNr).</p>
    <form class="bx-listbar" method="get" style="margin:0" action="">
      <input type="hidden" name="p" value="beleg_export"><input type="hidden" name="art" value="belege">
      <label class="muted" style="align-self:center">von</label><input type="date" name="von" value="<?= $jahr ?>-01-01">
      <label class="muted" style="align-self:center">bis</label><input type="date" name="bis" value="<?= $jahr ?>-12-31">
      <button class="btn btn-primary btn-sm" type="submit">CSV</button>
    </form>
  </div>
  <div class="bx-panel" style="flex:1;min-width:320px">
    <h2>DATEV (Buchungsstapel)</h2>
    <p class="muted">DATEV-EXTF-Buchungsstapel (Format 700) für den Steuerberater. Konten SKR03-Standard (Einstellungen), <strong>vor Produktiv-Import prüfen</strong>.</p>
    <form class="bx-listbar" method="get" style="margin:0" action="">
      <input type="hidden" name="p" value="beleg_export"><input type="hidden" name="art" value="datev">
      <label class="muted" style="align-self:center">von</label><input type="date" name="von" value="<?= $jahr ?>-01-01">
      <label class="muted" style="align-self:center">bis</label><input type="date" name="bis" value="<?= $jahr ?>-12-31">
      <button class="btn btn-primary btn-sm" type="submit">DATEV-CSV</button>
    </form>
  </div>
</div>
<h2 style="margin:20px 0 8px">Lieferanten / Verbindlichkeiten</h2>
<div class="bx-cards" style="align-items:flex-start">
  <div class="bx-panel" style="flex:1;min-width:320px">
    <h2>Offene Verbindlichkeiten</h2>
    <p class="muted">Alle offenen/teilbezahlten Eingangsrechnungen mit Restbetrag und Fälligkeit. CSV (Excel, UTF-8).</p>
    <a class="btn btn-primary btn-sm" href="?p=beleg_export&art=vop">Verbindlichkeiten herunterladen</a>
  </div>
  <div class="bx-panel" style="flex:1;min-width:320px">
    <h2>Eingangsrechnungen</h2>
    <p class="muted">Erfasste Lieferanten-Rechnungen eines Zeitraums als CSV (Netto, VSt, Brutto, Lieferant).</p>
    <form class="bx-listbar" method="get" style="margin:0" action="">
      <input type="hidden" name="p" value="beleg_export"><input type="hidden" name="art" value="lief_belege">
      <label class="muted" style="align-self:center">von</label><input type="date" name="von" value="<?= $jahr ?>-01-01">
      <label class="muted" style="align-self:center">bis</label><input type="date" name="bis" value="<?= $jahr ?>-12-31">
      <button class="btn btn-primary btn-sm" type="submit">CSV</button>
    </form>
  </div>
  <div class="bx-panel" style="flex:1;min-width:320px">
    <h2>DATEV (Rechnungseingang)</h2>
    <p class="muted">DATEV-EXTF-Stapel der Eingangsrechnungen (Wareneingang an Kreditor). Konten SKR03-Standard, <strong>vor Produktiv-Import prüfen</strong>.</p>
    <form class="bx-listbar" method="get" style="margin:0" action="">
      <input type="hidden" name="p" value="beleg_export"><input type="hidden" name="art" value="datev_ek">
      <label class="muted" style="align-self:center">von</label><input type="date" name="von" value="<?= $jahr ?>-01-01">
      <label class="muted" style="align-self:center">bis</label><input type="date" name="bis" value="<?= $jahr ?>-12-31">
      <button class="btn btn-primary btn-sm" type="submit">DATEV-CSV</button>
    </form>
  </div>
</div>
<div class="bx-panel" style="margin-top:16px">
  <h2>E-Rechnung (XML)</h2>
  <p class="muted" style="margin:0">Jede Rechnung lässt sich als EN16931-konformes CII-XML (ZUGFeRD-Profil) herunterladen – Button „E-Rechnung (XML)" in der Rechnungs-Detailansicht oder direkt über <code>?p=rechnung_xml&id=…</code>.</p>
</div>
<?php
endif;
render_footer();
