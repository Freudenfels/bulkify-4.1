<?php
// Nur-Lese-Angebotsansicht der Buchhaltung. Route: angebote_ansicht (Rolle finance/admin).
// Reiter „Angebote" (Liste Nr./Datum/Kunde/Status/Summe) und „Abgleich" (je Kunde Angebot→Auftrag→Rechnung,
// um Lücken zu sehen: Angebot ohne Auftrag, Auftrag ohne Rechnung). Angebot/Auftrag kommen über die Naht
// (erp.php), Belege sind finanz-eigen und werden direkt gelesen. Nichts wird verändert.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';   // beleg-Tabelle (finanz-eigen)
require_once BX_ROOT . '/core/erp.php';

$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
$tab = preg_replace('/[^a-z]/', '', $_GET['tab'] ?? 'liste') ?: 'liste';
$q   = trim($_GET['q'] ?? '');

$statusBadge = fn(?string $s) => match ((string)$s) {
    'offen', 'gesendet'       => bx_badge(status_text((string)$s), 'warn'),
    'bestaetigt', 'erledigt', 'versendet', 'bezahlt' => bx_badge(status_text((string)$s), 'ok'),
    'teilbezahlt'             => bx_badge('teilbezahlt', 'info'),
    'abgelehnt', 'storniert'  => bx_badge(status_text((string)$s), 'err'),
    ''                        => '<span class="muted">–</span>',
    default                   => bx_badge(status_text((string)$s)),
};

render_header('angebote_ansicht', 'Angebote (Ansicht)');
bx_head('Angebote', 'Nur-Lese-Ansicht aus dem Vertrieb · Abgleich mit Aufträgen und Rechnungen');
bx_tabs(['liste' => 'Angebote', 'abgleich' => 'Abgleich'], $tab, '?p=angebote_ansicht');

// ===========================================================================
if ($tab === 'liste'):
// ===========================================================================
$rows = erp_angebote(null, $q);
$cols = [
    'nummer'      => ['label' => 'Nummer'],
    'angelegt'    => ['label' => 'Datum', 'render' => fn($r) => $r['angelegt'] ? h(fmt_zeit($r['angelegt'], 'd.m.Y')) : ''],
    'kunde_firma' => ['label' => 'Kunde', 'render' => fn($r) => kunde_link($r['kunde_id'] ?? null, $r['kunde_firma'])],
    'produkt_name'=> ['label' => 'Produkt', 'render' => fn($r) => $r['produkt_name'] ? h($r['produkt_name']) : '<span class="muted">–</span>'],
    'status'      => ['label' => 'Status', 'render' => fn($r) => $statusBadge($r['status'])],
    'summe_netto' => ['label' => 'Summe (netto)', 'num' => true, 'render' => fn($r) => $r['summe_netto'] !== null ? $eur($r['summe_netto']) . ($r['staffel_anzahl'] > 1 ? ' <span class="muted">(ab)</span>' : '') : '<span class="muted">–</span>'],
];
?>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="angebote_ansicht"><input type="hidden" name="tab" value="liste">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Nummer, Kunde, Produkt …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=angebote_ansicht">zurücksetzen</a><?php endif; ?>
  <span style="flex:1"></span>
  <span class="muted" style="align-self:center"><?= count($rows) ?> Angebote</span>
</form>
<?php
bx_table($cols, $rows, ['empty' => 'Keine Angebote gefunden.']);
// Summe-Spalte zeigt die bestätigte (sonst erste/kleinste) Staffel; „(ab)" = es gibt weitere Staffeln.
echo '<p class="muted" style="margin-top:8px">Summe = Netto der bestätigten bzw. ersten Staffel (menge × VK). „(ab)" = weitere Staffeln vorhanden. Angebote entstehen im Vertrieb; hier nur zur Ansicht.</p>';

// ===========================================================================
elseif ($tab === 'abgleich'):
// ===========================================================================
$angebote  = erp_angebote();
$auftraege = erp_auftraege();
$belege    = all("SELECT id, nummer, auftrag_id, kunde_id, brutto, status FROM beleg WHERE typ='rechnung'");

// Index: Auftrag je angebot_id; Rechnung je auftrag_id; freie Rechnungen (ohne Auftrag) je Kunde.
$auftragVonAngebot = [];
$auftraegeOhneAngebot = [];          // kunde_id => [auftrag,…]
foreach ($auftraege as $a) {
    if (!empty($a['angebot_id'])) $auftragVonAngebot[(int)$a['angebot_id']] = $a;
    else $auftraegeOhneAngebot[(int)$a['kunde_id']][] = $a;
}
$rechnungVonAuftrag = [];
$freieRechnungen = [];               // kunde_id => [beleg,…] (ohne Auftrag)
foreach ($belege as $b) {
    if (!empty($b['auftrag_id'])) $rechnungVonAuftrag[(int)$b['auftrag_id']][] = $b;
    else $freieRechnungen[(int)$b['kunde_id']][] = $b;
}

// Nach Kunde gruppieren (Reihenfolge: Kunden mit Angeboten, dann Kunden nur mit Aufträgen/Rechnungen).
$kunden = [];   // kunde_id => ['firma'=>, 'angebote'=>[]]
foreach ($angebote as $ang) { $kid = (int)$ang['kunde_id']; $kunden[$kid]['firma'] = $ang['kunde_firma']; $kunden[$kid]['angebote'][] = $ang; }
foreach ($auftraegeOhneAngebot as $kid => $as) { if (!isset($kunden[$kid])) $kunden[$kid]['firma'] = $as[0]['kunde_firma']; }
foreach ($freieRechnungen as $kid => $bs) { if (!isset($kunden[$kid]['firma'])) $kunden[$kid]['firma'] = null; }

$luecken = 0;
foreach ($auftraege as $a) if (empty($rechnungVonAuftrag[(int)$a['id']]) && !in_array($a['status'], ['storniert'], true)) $luecken++;
$angOhneAuftrag = 0;
foreach ($angebote as $ang) if (empty($auftragVonAngebot[(int)$ang['id']]) && !in_array($ang['status'], ['abgelehnt','storniert'], true)) $angOhneAuftrag++;
?>
<div class="bx-cards">
  <?php
    $kach = function(string $k, $v, string $farbe='') { echo '<div class="bx-card" style="min-width:200px"><div class="k">'.h($k).'</div><div class="v" style="'.$farbe.'">'.$v.'</div></div>'; };
    $kach('Aufträge ohne Rechnung', $luecken ?: '<span class="muted">0</span>', $luecken ? 'color:var(--err)' : 'color:var(--gruen)');
    $kach('Angebote ohne Auftrag', $angOhneAuftrag ?: '<span class="muted">0</span>', $angOhneAuftrag ? 'color:var(--warn)' : 'color:var(--gruen)');
    $kach('Kunden im Abgleich', count($kunden) ?: '<span class="muted">0</span>');
  ?>
</div>
<p class="muted" style="margin:0 0 12px">Je Zeile: Angebot → zugehöriger Auftrag → Rechnung. „fehlt" markiert eine Lücke (Angebot ohne Auftrag, Auftrag ohne Rechnung). Angebot/Auftrag als Netto, Rechnung als Brutto. Rein lesend.</p>

<?php if (!$kunden): ?><div class="bx-panel"><p class="muted" style="margin:0">Keine Daten für den Abgleich.</p></div><?php endif; ?>
<?php foreach ($kunden as $kid => $kd):
    $zeilen = [];
    // 1) je Angebot eine Zeile
    foreach (($kd['angebote'] ?? []) as $ang) {
        $auf = $auftragVonAngebot[(int)$ang['id']] ?? null;
        $rech = $auf ? ($rechnungVonAuftrag[(int)$auf['id']] ?? []) : [];
        $zeilen[] = ['ang' => $ang, 'auf' => $auf, 'rech' => $rech];
    }
    // 2) Aufträge dieses Kunden ohne Angebot
    foreach (($auftraegeOhneAngebot[$kid] ?? []) as $auf) {
        $rech = $rechnungVonAuftrag[(int)$auf['id']] ?? [];
        $zeilen[] = ['ang' => null, 'auf' => $auf, 'rech' => $rech];
    }
    // 3) freie Rechnungen dieses Kunden (ohne Auftrag)
    foreach (($freieRechnungen[$kid] ?? []) as $b) {
        $zeilen[] = ['ang' => null, 'auf' => null, 'rech' => [$b]];
    }
    if (!$zeilen) continue;
    $fehlt = '<span style="color:var(--err)">fehlt</span>';
?>
  <div class="bx-panel" style="margin-bottom:16px">
    <h2 style="margin-top:0"><?= $kd['firma'] ? kunde_link($kid, $kd['firma']) : '<span class="muted">(ohne Kunde)</span>' ?></h2>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Angebot</th><th class="bx-num">netto</th><th>Auftrag</th><th class="bx-num">netto</th><th>Rechnung</th><th class="bx-num">brutto</th></tr></thead>
      <tbody>
        <?php foreach ($zeilen as $z): $ang=$z['ang']; $auf=$z['auf']; $rech=$z['rech']; ?>
          <tr>
            <td><?= $ang ? h($ang['nummer']).' '.$statusBadge($ang['status']) : '<span class="muted">—</span>' ?></td>
            <td class="bx-num"><?= $ang && $ang['summe_netto']!==null ? $eur($ang['summe_netto']) : '<span class="muted">–</span>' ?></td>
            <td><?php if ($auf): ?><?= h($auf['nummer']).' '.$statusBadge($auf['status']) ?><?php elseif ($ang && !in_array($ang['status'],['abgelehnt','storniert'],true)): ?><?= $fehlt ?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            <td class="bx-num"><?= $auf ? $eur($auf['gesamt_netto']) : '<span class="muted">–</span>' ?></td>
            <td><?php if ($rech): foreach ($rech as $b): ?><a href="?p=rechnung&id=<?= (int)$b['id'] ?>"><?= h($b['nummer']) ?></a> <?= $statusBadge($b['status']) ?><?php endforeach; elseif ($auf && !in_array($auf['status'],['storniert'],true)): ?><?= $fehlt ?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            <td class="bx-num"><?php if ($rech) { $sum=0; foreach($rech as $b) $sum+=(float)$b['brutto']; echo $eur($sum); } else echo '<span class="muted">–</span>'; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
<?php endforeach; ?>
<?php
endif;
render_footer();
