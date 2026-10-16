<?php
// Durchlaufzeiten-Tracker: misst je Vorgang die Dauer zwischen den Meilensteinen der Kundenreise
// (Rezepturanfrage -> Vorschlag -> Annahme -> Produktanfrage -> Angebot -> Bestätigung -> Produktion fertig)
// und die Gesamtzeit. Datenquelle: durchlaufzeiten_rows() in core/schema.php. Route: ?p=durchlaufzeiten (nur Admin).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

if (!has_role('admin')) {
    render_header('dashboard', 'Durchlaufzeiten');
    bx_head('Durchlaufzeiten', '', bx_btn('Zurück', '?p=dashboard', 'ghost'));
    echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:16px">Die Durchlaufzeiten sind nur für Administratoren.</div>';
    render_footer();
    return;
}

$kunde_id = ($_GET['kunde'] ?? '') !== '' ? (int)$_GET['kunde'] : null;
$von = trim((string)($_GET['von'] ?? ''));
$bis = trim((string)($_GET['bis'] ?? ''));

$rows = durchlaufzeiten_rows($kunde_id, $von ?: null, $bis ?: null);
$kunden = all("SELECT id, firma FROM kunden ORDER BY firma");

// Dauer in Sekunden -> menschlich. Negative/leere -> null (Anzeige „–").
$sek = function (?string $a, ?string $b): ?int {
    if (!$a || !$b) return null;
    $d = strtotime($b) - strtotime($a);
    return $d >= 0 ? $d : null;
};
$fmt = function (?int $s): string {
    if ($s === null) return '<span class="muted">–</span>';
    if ($s < 60) return 'unter 1 Min';
    $d = intdiv($s, 86400); $h = intdiv($s % 86400, 3600); $m = intdiv($s % 3600, 60);
    if ($d > 0) return $d . ' T' . ($h > 0 ? ' ' . $h . ' Std' : '');
    if ($h > 0) return $h . ' Std' . ($m > 0 ? ' ' . $m . ' Min' : '');
    return max(1, $m) . ' Min';
};

// Segmente der Kette (Start-/End-Meilenstein + kurze Überschrift + Erklärung).
$SEG = [
    ['m1', 'm2', 'Reaktion',       'Rezepturanfrage → Vorschlag gesendet'],
    ['m2', 'm3', 'Kundenentscheid', 'Vorschlag → Rezeptur angenommen'],
    ['m3', 'm4', 'bis Produktanfrage', 'Rezeptur angenommen → Produkt angefragt'],
    ['m4', 'm5', 'Angebotszeit',    'Produktanfrage → Angebot erstellt'],
    ['m5', 'm6', 'Kundenbestätigung', 'Angebot erstellt → Angebot bestätigt'],
    ['m6', 'm7', 'Produktion',      'Angebot bestätigt → Produktion fertig'],
];

// Dauern je Zeile berechnen + für die Durchschnitte sammeln.
$sumSeg = array_fill(0, count($SEG), 0); $cntSeg = array_fill(0, count($SEG), 0);
$sumGes = 0; $cntGes = 0;
$jetzt = gmdate('Y-m-d H:i:s');
foreach ($rows as $i => $r) {
    $ms = $r['ms']; $segVals = [];
    foreach ($SEG as $j => $s) {
        $d = $sek($ms[$s[0]], $ms[$s[1]]);
        $segVals[$j] = $d;
        if ($d !== null) { $sumSeg[$j] += $d; $cntSeg[$j]++; }
    }
    $rows[$i]['segVals'] = $segVals;
    $gesamt = $sek($ms['m1'], $ms['m7']);
    $rows[$i]['gesamt'] = $gesamt;
    if ($gesamt !== null) { $sumGes += $gesamt; $cntGes++; }
    // „läuft seit": letzter erreichter Meilenstein -> jetzt (nur wenn noch nicht fertig).
    $rows[$i]['fertig'] = $ms['m7'] !== null;
    $letzter = null; foreach (['m1','m2','m3','m4','m5','m6','m7'] as $mk) if ($ms[$mk] !== null) $letzter = $ms[$mk];
    $rows[$i]['laeuft'] = (!$rows[$i]['fertig'] && $letzter) ? $sek($letzter, $jetzt) : null;
}

render_header('durchlaufzeiten', 'Durchlaufzeiten');
bx_head('Durchlaufzeiten', 'Wie lange von der Rezepturanfrage bis zur fertigen Produktion – je Vorgang und im Schnitt (' . count($rows) . ' Vorgänge)');
?>
<div class="bx-listbar">
  <form method="get" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;width:100%">
    <input type="hidden" name="p" value="durchlaufzeiten">
    <select name="kunde"><option value="">Alle Kunden</option><?php foreach ($kunden as $kc): ?><option value="<?= (int)$kc['id'] ?>" <?= $kunde_id===(int)$kc['id']?'selected':'' ?>><?= h($kc['firma']) ?></option><?php endforeach; ?></select>
    <label class="muted" style="font-size:13px">von</label><input type="date" name="von" value="<?= h($von) ?>">
    <label class="muted" style="font-size:13px">bis</label><input type="date" name="bis" value="<?= h($bis) ?>">
    <button class="btn btn-ghost btn-sm" type="submit">Filtern</button>
    <?php if ($kunde_id || $von || $bis): ?><a class="btn btn-ghost btn-sm" href="?p=durchlaufzeiten">zurücksetzen</a><?php endif; ?>
  </form>
</div>

<?php // Durchschnitte oben als Kacheln (nur Segmente/Gesamt mit mindestens einem Messwert). ?>
<div class="bx-cards" style="margin-bottom:var(--sp-4)">
  <?php foreach ($SEG as $j => $s): ?>
  <div class="bx-card" title="<?= h($s[3]) ?>"><div class="k"><?= h($s[2]) ?> <span class="muted">(Ø)</span></div>
    <div class="v"><?= $cntSeg[$j] ? $fmt((int) round($sumSeg[$j] / $cntSeg[$j])) : '<span class="muted">–</span>' ?></div>
    <div class="muted" style="font-size:11px"><?= (int)$cntSeg[$j] ?> gemessen</div></div>
  <?php endforeach; ?>
  <div class="bx-card" style="border-color:var(--gruen)" title="Rezepturanfrage → Produktion fertig"><div class="k">Gesamt <span class="muted">(Ø)</span></div>
    <div class="v"><?= $cntGes ? $fmt((int) round($sumGes / $cntGes)) : '<span class="muted">–</span>' ?></div>
    <div class="muted" style="font-size:11px"><?= (int)$cntGes ?> abgeschlossen</div></div>
</div>

<div class="bx-panel">
<?php if (!$rows): ?>
  <div class="muted">Keine Vorgänge in dieser Ansicht. Erfasst werden Vorgänge, die als Rezepturanfrage begonnen haben.</div>
<?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr>
      <th>Vorgang</th><th>Kunde</th><th style="white-space:nowrap">Start</th>
      <?php foreach ($SEG as $s): ?><th class="bx-num" title="<?= h($s[3]) ?>"><?= h($s[2]) ?></th><?php endforeach; ?>
      <th class="bx-num">Gesamt</th><th>Stand</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td style="white-space:nowrap"><?php if ($r['rez_id']): ?><a href="?p=rezeptur_detail&id=<?= (int)$r['rez_id'] ?>"><?= h($r['rez_nr'] ?: $r['anf_nr']) ?></a><?php else: ?><?= h($r['rez_nr'] ?: $r['anf_nr']) ?><?php endif; ?>
          <div class="muted" style="font-size:12px;max-width:220px;overflow-wrap:anywhere"><?= h((string)$r['rez_name']) ?></div></td>
        <td style="max-width:180px;overflow-wrap:anywhere"><?= $r['firma'] ? h($r['firma']) : '<span class="muted">–</span>' ?></td>
        <td style="white-space:nowrap" class="muted"><?= $r['ms']['m1'] ? h(fmt_zeit($r['ms']['m1'], 'd.m.Y')) : '–' ?></td>
        <?php foreach ($SEG as $j => $s): ?><td class="bx-num" style="white-space:nowrap"><?= $fmt($r['segVals'][$j]) ?></td><?php endforeach; ?>
        <td class="bx-num" style="white-space:nowrap"><strong><?= $fmt($r['gesamt']) ?></strong></td>
        <td style="white-space:nowrap"><?php if ($r['fertig']): ?><?= bx_badge('fertig','ok') ?><?php elseif ($r['laeuft'] !== null): ?><span class="muted" style="font-size:12px">läuft seit <?= $fmt($r['laeuft']) ?></span><?php else: ?><span class="muted">–</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12px;margin-top:8px">Jede Spalte = Dauer bis zum nächsten Meilenstein; „–" heißt, der Schritt ist (noch) nicht erreicht oder nicht sauber datierbar. Gesamt = Rezepturanfrage bis Produktion fertig. Durchschnitte oben zählen nur Vorgänge, bei denen der jeweilige Schritt vorliegt.</p>
<?php endif; ?>
</div>
<?php render_footer();
