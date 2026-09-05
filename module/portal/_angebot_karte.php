<?php
/**
 * Teilvorlage: eine Angebots-Karte im Kundenportal.
 * Erwartet im Scope: $a (angebot-Zeile mit Zusatzfeldern), $inf (=$angInfo[$a['id']]),
 *   $st (=$staffelMap[$a['id']]), $accept (bool), sowie die globalen Helfer
 *   $mg, $eur, $kid, $ustP, $std_menge_ang, $portalLink, $titelFuer.
 * $accept=true  -> Menge/Angebot kann per Klick verbindlich angenommen werden (Ort: „Meine Anfragen").
 * $accept=false -> reine Lese-Ansicht (Ort: „Angebote" = Datenablage): Preise sichtbar, keine Knöpfe.
 */
$offen = $a['status'] === 'gesendet';
$canAccept = $offen && !empty($accept);
$einh = (int)$a['einheiten_pro_packung'];
$formPl = ['kapsel'=>'Kapseln','tablette'=>'Tabletten','softgel'=>'Softgels','stick'=>'Sticks','pulver'=>'Portionen','granulat'=>'Portionen','fluessig'=>'ml'];
$mengeLbl = $einh . ' ' . ($formPl[$inf['form']] ?? 'Stück');
$gPack = ($inf['istPulver'] && $inf['portionG'] > 0) ? $mg($einh * $inf['portionG']) . ' g pro Packung' : '';
// Packungs-Label je Staffel aus deren Stückzahl (v3 menge_pro_vpe): Kapseln/Tabletten als Anzahl,
// Pulver/Granulat als Gramm-Packung (Portionen × Tagesdosis) – für den Kunden greifbarer (z. B. „250 g").
$paketLbl = function (int $stk) use ($inf, $mg, $formPl, $mengeLbl) {
    if ($stk <= 0) return $mengeLbl;
    if (in_array($inf['form'], ['pulver','granulat'], true) && $inf['portionG'] > 0)
        return $mg($stk * $inf['portionG']) . ' g';
    return $stk . ' ' . ($formPl[$inf['form']] ?? 'Stück');
};
?>
<details class="bx-panel pt-ang" id="a<?= (int)$a['id'] ?>" style="scroll-margin-top:16px"<?= !empty($open) ? ' open' : '' ?>>
  <summary>
    <span style="font-size:var(--fs-md);flex:1;min-width:0"><span style="color:var(--gold)"><?= h($a['nummer']) ?></span> <strong><?= h($titelFuer($a)) ?></strong></span>
    <span class="bx-row" style="gap:10px;align-items:center">
      <?= $offen ? bx_badge($canAccept ? 'Angebot liegt vor – bitte wählen' : 'Angebot liegt vor','info')
           : ($a['status']==='bestaetigt' ? bx_badge('bestätigt','ok') : bx_badge('abgelehnt','err')) ?>
      <?= pdf_btn($portalLink('angebot_pdf') . '&aid=' . (int)$a['id'], 'PDF', true, 'Angebot als PDF herunterladen') ?>
    </span>
  </summary>
  <div class="muted" style="margin-top:10px;font-size:13px">Eingegangen: <?= h(fmt_zeit($a['angelegt'])) ?> Uhr<?= $a['aktualisiert'] ? ' · Angebot vom ' . h(fmt_zeit($a['aktualisiert'])) . ' Uhr' : '' ?></div>
  <?php if ($inf['verp'] || $inf['deckel'] || $inf['etikett']): ?>
  <div class="muted" style="font-size:13px"><?= $inf['verp'] ? 'Verpackung: ' . h($inf['verp']) : '' ?><?= $inf['deckel'] ? ' · Deckel: ' . h($inf['deckel']) : '' ?><?= $inf['etikett'] ? ' · Etikett: ' . h($inf['etikett']) : '' ?></div>
  <?php endif; ?>
  <div class="muted" style="font-size:13px">Produktionszeit: <strong><?= 'ca. ' . rtrim(rtrim(number_format($inf['prodzeit'],1,',','.'),'0'),',') . ' Wochen' ?></strong> (unverbindlicher Schätzwert)</div>

  <?php if ($inf['zutaten']): ?>
  <details style="margin-top:10px">
    <summary style="cursor:pointer;color:var(--gruen)">Rezeptur ansehen</summary>
    <div class="bx-tablewrap" style="margin-top:8px"><table class="bx-table">
      <thead><tr><th>Zutat</th><th class="bx-num">Menge je Einheit</th></tr></thead>
      <tbody><?php foreach ($inf['zutaten'] as $z): ?><tr><td><?= h($z['bezeichnung']) ?></td><td class="bx-num"><?= $mg($z['menge_mg']) ?> mg</td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?php if ($inf['nutr']): ?>
    <div class="bx-tablewrap" style="margin-top:8px"><table class="bx-table">
      <thead><tr><th>Nährstoff</th><th class="bx-num">je Einheit</th><th class="bx-num">% NRV</th></tr></thead>
      <tbody><?php foreach ($inf['nutr'] as $n): $betr = $n['einheit']==='µg' ? $mg($n['mg']*1000).' µg' : $mg($n['mg']).' mg'; $pct='–'; if($n['nrv']!==null&&$n['nrv']!==''){ $nrvMg=$n['einheit']==='µg'?(float)$n['nrv']/1000:(float)$n['nrv']; if($nrvMg>0) $pct=number_format($n['mg']/$nrvMg*100,0,',','.').' %'; } ?><tr><td><?= h($n['name']) ?></td><td class="bx-num"><?= $betr ?></td><td class="bx-num"><?= $pct ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </details>
  <?php endif; ?>

  <?php if ($offen && $inf['matrix']): ?>
  <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table">
    <thead><tr><th>Menge / Verpackung</th><th class="bx-num">Anzahl Verpackungen</th><th>Preis</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($std_menge_ang as $bm): foreach (std_groessen_fuer($inf['form']) as $stk):
        $cell = $inf['matrix'][$stk][$bm] ?? null;
        $lbl = form_groessen_label($inf['form'], (float)$stk);
        $gp = ($inf['form'] === 'stick' && $inf['portionG'] > 0) ? $mg($stk * $inf['portionG']) . ' g pro Packung' : ''; ?>
      <tr>
        <td><?= h($lbl) ?><?= $gp ? '<div class="muted" style="font-size:12px">' . h($gp) . '</div>' : '' ?></td>
        <td class="bx-num"><?= number_format($bm, 0, ',', '.') ?></td>
        <?php if ($cell):
            $hCent = (int) round(vk_fuer_kunde($cell['vk'], $kid) * 100);
            $pCent = verpackung_cent_je_pack((int)$a['produkt_id'], $bm, $kid, (int)$cell['verp']);
            $vk = ($hCent + $pCent) / 100; $netto = ($hCent + $pCent) * $bm / 100; $brutto = $netto * (1 + $ustP/100); ?>
          <td><strong><?= $eur($vk) ?> / Pkg.</strong><div class="muted" style="font-size:12px"><?= $pCent > 0 ? 'Herstellung ' . $eur($hCent/100) . ' + Verpackung ' . $eur($pCent/100) . ' · ' : '' ?>Gesamt <?= $eur($netto) ?> netto<?= $ustP > 0 ? ' · ' . $eur($brutto) . ' brutto (inkl. ' . $mg($ustP) . ' % MwSt)' : '' ?></div></td>
          <td class="bx-num"><?php if ($canAccept): ?><button class="btn btn-primary btn-sm" style="white-space:nowrap" type="button" onclick="bxBestaetigen('Menge verbindlich annehmen', 'Mit der Annahme bestellen Sie verbindlich. Wir starten danach Einkauf und Produktion; eine Stornierung ist nach der Rohstoffbestellung nicht mehr m&ouml;glich. Die Produktionszeit ist ein unverbindlicher Sch&auml;tzwert.', 'Ich habe das Angebot gepr&uuml;ft und bestelle verbindlich.', {aktion:'zelle_annehmen', angebot_id:'<?= (int)$a['id'] ?>', stueck:'<?= $stk ?>', verpackung_id:'<?= (int)$cell['verp'] ?>', bestellmenge:'<?= $bm ?>'})">Diese Menge annehmen</button><?php endif; ?></td>
        <?php else: ?>
          <td><?= bx_badge('Nicht machbar','err') ?><div class="muted" style="font-size:12px">Diese Menge ist so nicht produzierbar</div></td>
          <td></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; endforeach; ?>
    </tbody>
  </table></div>
  <?php elseif ($offen && !$st && $inf['opt']['optionen']):
        $optn = $inf['opt']['optionen']; $extra = $inf['opt']['extra'];
        $besch = (string)($optn[0]['beschreibung'] ?? '');
        if (count($optn) > 1 && strpos($besch, "\n") !== false) $besch = substr($besch, 0, strrpos($besch, "\n")); ?>
  <?php if ($besch !== '' && !$inf['zutaten']): ?>
  <details style="margin-top:10px">
    <summary style="cursor:pointer;color:var(--gruen)">Rezeptur ansehen</summary>
    <div class="muted" style="margin-top:8px;white-space:pre-line;font-size:13px"><?= h($besch) ?></div>
  </details>
  <?php endif; ?>
  <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table">
    <thead><tr><th>Menge / Verpackung</th><th>Anzahl Verpackungen</th><th>Preis</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($optn as $o): $netto = $o['netto']; $brutto = $netto * (1 + $ustP/100); ?>
      <tr>
        <td><?= h($o['groesse'] !== '' ? $o['groesse'] : $o['titel']) ?>
          <?php if ($o['verpackung'] !== ''): ?><div class="muted" style="font-size:12px"><?= h($o['verpackung']) ?></div><?php endif; ?>
        </td>
        <td><?= number_format($o['pakete'], 0, ',', '.') ?></td>
        <td style="max-width:260px"><strong><?= $eur($o['pro_pkg']) ?> / Pkg.</strong>
          <div class="muted" style="font-size:12px;white-space:normal">Gesamt: <?= $eur($netto) ?> netto<?= $ustP > 0 ? ' · ' . $eur($brutto) . ' brutto (inkl. ' . $mg($ustP) . ' % MwSt)' : '' ?></div>
        </td>
        <td class="bx-num">
          <?php if ($canAccept && $o['waehlbar']): ?>
          <button class="btn btn-primary btn-sm" style="white-space:nowrap" type="button" onclick="bxBestaetigen('Menge verbindlich annehmen', '<div class=\'bx-panel\' style=\'margin:0 0 12px;padding:12px 14px\'><strong><?= h(trim(($o['groesse'] !== '' ? $o['groesse'] : $o['titel']) . ($o['verpackung'] !== '' ? ' · ' . $o['verpackung'] : ''))) ?></strong><br><?= number_format($o['pakete'], 0, ',', '.') ?> Packungen · <?= $eur($o['pro_pkg']) ?> je Packung<br>Gesamt <?= $eur($o['netto']) ?> netto<?= $ustP > 0 ? ' · ' . $eur($o['netto'] * (1 + $ustP/100)) . ' brutto' : '' ?></div>Mit der Annahme bestellen Sie verbindlich. Wir starten danach Einkauf und Produktion; eine Stornierung ist nach der Rohstoffbestellung nicht mehr m&ouml;glich. Die Produktionszeit ist ein unverbindlicher Sch&auml;tzwert.', 'Ich habe das Angebot gepr&uuml;ft und bestelle verbindlich.', {aktion:'angebot_annehmen', angebot_id:'<?= (int)$a['id'] ?>', gruppe:'<?= h($o['gruppe']) ?>'})">Diese Menge annehmen</button>
          <?php elseif ($canAccept): ?><span class="muted" style="font-size:12px">Bitte kurz melden</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php foreach ($extra as $x): ?>
      <tr><td colspan="2"><?= h($x['bezeichnung']) ?><div class="muted" style="font-size:12px">wird zusätzlich berechnet</div></td>
          <td colspan="2" class="bx-num"><?= $eur((float)$x['menge'] * (int)$x['preis_cent'] / 100) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php elseif ($offen && !$st && $inf['pos']):
        $sumNetto = 0; foreach ($inf['pos'] as $p) $sumNetto += $p['menge'] * $p['preis_cent'] / 100; ?>
  <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table">
    <thead><tr><th>Position</th><th class="bx-num">Menge</th><th class="bx-num">Preis / Einheit</th><th class="bx-num">Gesamt</th></tr></thead>
    <tbody>
    <?php foreach ($inf['pos'] as $p): ?>
      <tr>
        <td><?= h($p['bezeichnung']) ?><?= $p['beschreibung'] ? '<div class="muted" style="font-size:12px;white-space:pre-line">' . h($p['beschreibung']) . '</div>' : '' ?></td>
        <td class="bx-num"><?= rtrim(rtrim(number_format($p['menge'],2,',','.'),'0'),',') ?> <?= h($p['einheit']) ?></td>
        <td class="bx-num"><?= $eur($p['preis_cent']/100) ?></td>
        <td class="bx-num"><?= $eur($p['menge'] * $p['preis_cent']/100) ?></td>
      </tr>
    <?php endforeach; ?>
      <tr style="font-weight:600"><td colspan="3">Gesamt netto</td><td class="bx-num"><?= $eur($sumNetto) ?></td></tr>
      <?php if ($ustP > 0): ?><tr><td colspan="3" class="muted">inkl. <?= $mg($ustP) ?> % MwSt</td><td class="bx-num muted"><?= $eur($sumNetto * (1 + $ustP/100)) ?> brutto</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?php if ($canAccept): ?>
  <div class="bx-row" style="justify-content:flex-end;margin-top:10px">
    <?php if ($inf['annehmbar']): ?>
    <button class="btn btn-primary" type="button" onclick="bxBestaetigen('Angebot verbindlich annehmen', 'Mit der Annahme bestellen Sie verbindlich. Wir starten danach Einkauf und Produktion; eine Stornierung ist nach der Rohstoffbestellung nicht mehr m&ouml;glich. Die Produktionszeit ist ein unverbindlicher Sch&auml;tzwert.', 'Ich habe das Angebot gepr&uuml;ft und bestelle verbindlich.', {aktion:'angebot_annehmen', angebot_id:'<?= (int)$a['id'] ?>'})">Angebot annehmen</button>
    <?php else: ?><div class="muted">Zum Annehmen bitte kurz bei uns melden.</div><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php elseif ($offen): ?>
  <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table">
    <thead><tr><th>Menge</th><th class="bx-num">Preis / Pkg.</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($st as $s): $vk = vk_fuer_kunde((float)$s['vk_stueck'], $kid); $netto = $vk * (int)$s['menge']; $brutto = $netto * (1 + $ustP/100); ?>
      <tr><td><?= number_format((int)$s['menge'],0,',','.') ?> × <?= h($paketLbl((int)($s['stueck'] ?? 0))) ?></td>
        <td><strong><?= $eur($vk) ?></strong><div class="muted" style="font-size:12px">Gesamt <?= $eur($netto) ?> netto<?= $ustP>0?' · '.$eur($brutto).' brutto':'' ?></div></td>
        <td class="bx-num"><?php if ($canAccept): ?><button class="btn btn-primary btn-sm" style="white-space:nowrap" type="button" onclick="bxBestaetigen('Menge verbindlich annehmen', 'Mit der Annahme bestellen Sie verbindlich. Wir starten danach Einkauf und Produktion; eine Stornierung ist nach der Rohstoffbestellung nicht mehr m&ouml;glich. Die Produktionszeit ist ein unverbindlicher Sch&auml;tzwert.', 'Ich habe das Angebot gepr&uuml;ft und bestelle verbindlich.', {aktion:'bestaetigen', angebot_id:'<?= (int)$a['id'] ?>', staffel:'<?= (int)$s['id'] ?>'})">Diese Menge annehmen</button><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php elseif ($a['status'] === 'bestaetigt'):
        $sel = null; foreach ($st as $s) if ((int)$s['bestaetigt'] === 1) { $sel = $s; break; }
        // Direkt zur verknüpften Bestellung springen (aus diesem Angebot entstandener Auftrag); sonst zur Bestell-Liste.
        $auftragId = (int) scalar("SELECT id FROM auftrag WHERE angebot_id=? ORDER BY id DESC LIMIT 1", [(int)$a['id']]);
        $bestLink  = $auftragId ? $portalLink('bestellung') . '&aid=' . $auftragId : $portalLink('bestellungen');
        $bestTxt   = $auftragId ? 'Zur Bestellung' : 'Zu den Bestellungen'; ?>
    <?php if ($sel): $vk = vk_fuer_kunde((float)$sel['vk_stueck'], $kid); $netto = $vk * (int)$sel['menge']; $brutto = $netto * (1 + $ustP/100); ?>
    <div class="bx-panel" style="margin-top:12px;padding:12px 14px;border-color:var(--gruen)">
      <div><strong>Angenommene Menge:</strong> <?= number_format((int)$sel['menge'],0,',','.') ?> × <?= h($paketLbl((int)($sel['stueck'] ?? 0))) ?> · <strong><?= $eur($vk) ?></strong> / Pkg. · Gesamt <?= $eur($netto) ?> netto<?= $ustP > 0 ? ' · ' . $eur($brutto) . ' brutto' : '' ?></div>
      <div class="muted" style="font-size:13px;margin-top:4px">Verbindlich angenommen.</div>
      <div style="margin-top:10px"><a class="btn btn-primary btn-sm" href="<?= $bestLink ?>"><?= $bestTxt ?></a></div>
    </div>
    <?php else: ?>
    <div style="margin-top:12px">
      <div class="muted">Angebot bestätigt.</div>
      <div style="margin-top:8px"><a class="btn btn-primary btn-sm" href="<?= $bestLink ?>"><?= $bestTxt ?></a></div>
    </div>
    <?php endif; ?>
  <?php else: ?>
  <div class="muted" style="margin-top:12px"><?= $a['ablehnung_grund'] ? 'Abgelehnt: ' . h($a['ablehnung_grund']) : 'Abgelehnt.' ?></div>
  <?php endif; ?>

  <?php if ($canAccept): ?>
  <div class="bx-row" style="justify-content:flex-end;margin-top:12px;gap:8px">
    <details>
      <summary class="btn btn-ghost btn-sm" style="list-style:none">Ablehnen</summary>
      <form method="post" style="margin-top:8px;display:flex;gap:8px;justify-content:flex-end;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="aktion" value="angebot_ablehnen"><input type="hidden" name="angebot_id" value="<?= (int)$a['id'] ?>">
        <input type="text" name="grund" placeholder="Grund (optional)" style="max-width:280px">
        <button class="btn btn-danger btn-sm" type="submit">Angebot ablehnen</button>
      </form>
    </details>
  </div>
  <?php endif; ?>
</details>
