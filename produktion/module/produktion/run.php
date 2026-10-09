<?php
// Produktionsmodus – tablettaugliches Abarbeiten eines Produktionsauftrags Schritt für Schritt.
// Charge/MHD kommen vom System (nur Anzeige). Beim Abschließen wird protokolliert, WER den Schritt
// WANN erledigt hat (erledigt_von/at); Material wird nach FEFO abgebucht, der letzte Schritt bucht
// die Fertigware ein (erp_schritt_abschliessen). Admin kann Schritte direkt abhaken/zurücksetzen
// (reine Statuskorrektur über erp_schritt_status_setzen – ohne Lager-/Chargenbewegung).
$id = (int)($_GET['id'] ?? 0);
$akteur = (string)(pr_benutzer()['name'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    $schritt_id = (int)($_POST['schritt_id'] ?? 0);
    if ($aktion === 'erledigen') {
        // Maschine (Spec 9.1): bevorzugt aus gescanntem QR-Code, sonst aus der Auswahl – schon VOR dem
        // Abschluss bestimmen, damit die Reinigungs-Sperre (Spec 9.4) greifen kann.
        $mid = 0;
        $qr = trim((string)($_POST['maschine_qr'] ?? ''));
        if ($qr !== '') { $m = pr_maschine_per_qr($qr); if ($m) $mid = (int)$m['id']; }
        if ($mid === 0) $mid = (int)($_POST['maschine_id'] ?? 0);
        $station_vorab = erp_schritt_station($schritt_id);
        $hatMaschinen  = $station_vorab !== '' && count(pr_maschinen_fuer_station($station_vorab)) > 0;
        // HARTE SPERRE (Spec 9.4): Maschine beim Start als "nicht sauber" gemeldet -> blockieren.
        if ($hatMaschinen && $mid > 0 && (string)($_POST['sauber'] ?? '') === 'nein') {
            pr_maschine_reinigung_erfassen(['maschine_id'=>$mid, 'pa_id'=>$id, 'schritt_id'=>$schritt_id, 'sauber_bei_start'=>0, 'von'=>$akteur]);
            flash('Maschine als nicht sauber gemeldet – erst reinigen und bestätigen, dann den Schritt abschließen.', 'warn');
            weiter('?p=run&id=' . $id);
        }
        $r = erp_schritt_abschliessen($schritt_id, $akteur);
        $zusatz = '';
        if ($r['ok']) {
            foreach (pr_station_felder((string)$r['station']) as $feld) {
                $v = trim((string)($_POST['daten'][$feld['feld']] ?? ''));
                if ($v !== '') pr_daten_setzen($id, $feld['feld'], $v, $akteur);
            }
            // Maschine am Schritt festhalten.
            if ($mid > 0) {
                $mm = pr_maschine($mid);
                if ($mm) {
                    pr_daten_setzen($id, 'maschine_' . $schritt_id, (string)$mm['name'], $akteur);
                    pr_daten_setzen($id, 'maschine_id_' . $schritt_id, (string)$mid, $akteur);
                    $zusatz .= ' Maschine: ' . $mm['name'] . '.';
                }
            }
            // Umgebungsdaten (Spec 10): Temperatur/Luftfeuchte je Schritt erfassen (fließt später in den Bericht).
            $temp = trim((string)($_POST['umg_temp'] ?? ''));
            $feu  = trim((string)($_POST['umg_feuchte'] ?? ''));
            if ($temp !== '') pr_daten_setzen($id, 'temp_' . $schritt_id, $temp, $akteur);
            if ($feu !== '')  pr_daten_setzen($id, 'feuchte_' . $schritt_id, $feu, $akteur);
            // Produktionscharge CH/CHE anbinden + eingesetzte Rohstoff-Batches verknüpfen (Spec 7.5).
            $mm_menge = null;
            $mmv = trim((string)($_POST['daten']['mischmenge'] ?? ''));
            if ($mmv !== '') $mm_menge = (float) str_replace(',', '.', $mmv);
            $pc = erp_prod_charge_fuer_station($id, (string)$r['station'], $mid, $mm_menge, $akteur);
            if ($pc) {
                $zusatz .= ' Charge ' . $pc['nummer'] . ($pc['neu'] ? ' angelegt' : '')
                         . ($pc['verknuepft'] > 0 ? (', ' . $pc['verknuepft'] . ' Rohstoff-Charge(n) verknüpft') : '') . '.';
            }
            // Mischer-Kapazität (Spec 7.7): kg je Gebinde -> Gebinde-Unterchargen anlegen (ein Etikett je Gebinde).
            $capv = trim((string)($_POST['kg_pro_gebinde'] ?? ''));
            if ((string)$r['station'] === 'Mischen' && $capv !== '') {
                $cap = (float) str_replace(',', '.', $capv);
                if ($cap > 0) {
                    pr_daten_setzen($id, 'kg_pro_gebinde', $capv, $akteur);
                    $ng = erp_mischer_unterchargen_anlegen($id, $cap, $akteur);
                    if ($ng > 0) $zusatz .= ' ' . $ng . ' Gebinde-Untercharge(n) angelegt (' . $ng . ' Etikett(en)).';
                }
            }
            // Reinigung am Abschluss (Spec 9.4): bestätigt + unterschrieben (+ Bild optional) -> dokumentieren,
            // Maschine als gereinigt fortschreiben (Basis der Reinigungspläne).
            if ($mid > 0 && $hatMaschinen && !empty($_POST['gereinigt'])) {
                $unter = trim((string)($_POST['unterschrift'] ?? '')) ?: $akteur;
                $bild  = pr_reinigung_bild_speichern('reinigung_bild');
                pr_maschine_reinigung_erfassen(['maschine_id'=>$mid, 'pa_id'=>$id, 'schritt_id'=>$schritt_id,
                    'sauber_bei_start'=> ((string)($_POST['sauber'] ?? '') === 'ja' ? 1 : null),
                    'gereinigt'=>1, 'unterschrift'=>$unter, 'bild'=>$bild, 'von'=>$akteur]);
                $zusatz .= ' Reinigung bestätigt (' . $unter . ')' . ($bild ? ' mit Bild' : '') . '.';
            }
        }
        flash($r['ok'] ? (($r['fertig'] ? 'Letzter Schritt erledigt – Produktion fertig, Fertigware eingebucht.' : 'Schritt „' . $r['station'] . '" erledigt.') . $zusatz)
                       : ($r['msg'] ?: 'Schritt konnte nicht abgeschlossen werden.'), $r['ok'] ? 'ok' : 'warn');
    } elseif ($aktion === 'teilmenge') {
        $r = erp_teilmenge_produzieren($id, (float) str_replace(',', '.', (string)($_POST['menge'] ?? '0')), $akteur);
        if (!$r['ok'] && !empty($r['fehlt'])) {
            $t = []; foreach ($r['fehlt'] as $f) $t[] = (string)$f['name'] . ' (fehlt ' . menge_txt($f['fehlt']) . ' ' . (string)$f['einheit'] . ')';
            flash('Nicht genug Material: ' . implode(', ', $t) . '.', 'warn');
        } else flash($r['msg'], $r['ok'] ? 'ok' : 'warn');
    } elseif ($aktion === 'blink') {
        $modus = ($_POST['modus'] ?? 'an') === 'aus' ? 'aus' : 'an';
        $r = pr_lager_blink((int)($_POST['charge_id'] ?? 0), $modus);
        flash($r['ok'] ? ('Blinker im Lager: ' . ($r['meldung'] ?: ($modus === 'aus' ? 'aus.' : 'leuchtet.'))) : ('Blinker: ' . ($r['meldung'] ?: 'nicht ausgelöst.')), $r['ok'] ? 'ok' : 'warn');
    } elseif (($aktion === 'admin_done' || $aktion === 'admin_undo') && pr_ist_admin()) {
        $r = erp_schritt_status_setzen($schritt_id, $aktion === 'admin_done', $akteur);
        flash($r['ok'] ? ($aktion === 'admin_done' ? 'Schritt als erledigt markiert (Admin).' : 'Schritt zurückgesetzt (Admin).')
                       : ($r['msg'] ?: 'Konnte den Schritt nicht ändern.'), $r['ok'] ? 'ok' : 'warn');
    } elseif ($aktion === 'gebinde_status') {   // Spec 7.8: FEFO-Führung beim Abfüllen – Gebinde angefangen/leer melden
        $ok = erp_gebinde_status_setzen((int)($_POST['gebinde_id'] ?? 0), $id, (string)($_POST['status'] ?? ''));
        flash($ok ? 'Gebinde-Status aktualisiert.' : 'Gebinde-Status nicht geändert.', $ok ? 'ok' : 'warn');
    } elseif ($aktion === 'pause') {   // Spec 7.11/7.12: Pause/Schichtwechsel am cleanen Punkt (zwischen Schritten)
        $art = (string)($_POST['art'] ?? 'pause');
        pr_pause_erfassen(['pa_id'=>$id, 'art'=>$art, 'nach_station'=>(string)($_POST['nach_station'] ?? ''),
            'von'=>$akteur, 'an_wen'=>(string)($_POST['an_wen'] ?? ''), 'grund'=>(string)($_POST['grund'] ?? '')]);
        flash(($art === 'schichtende' ? 'Schichtwechsel' : 'Pause') . ' erfasst – an einem sauberen Punkt zwischen den Schritten.');
    }
    weiter('?p=run&id=' . $id);
}

$pa = erp_pa($id);
if (!$pa) { kopf('Produktionsmodus'); seitenkopf('Nicht gefunden'); echo '<div class="bx-panel"><a class="btn btn-ghost" href="?p=liste">Zurück</a></div>'; fuss(); return; }
$schritte = erp_pa_schritte($id);
$charge   = erp_pa_charge_info($id);
$istAdmin = pr_ist_admin();

$total = count($schritte);
$fertig_cnt = 0; $erster_offen = 0;
foreach ($schritte as $s) { if ((int)($s['erledigt'] ?? 0) === 1) $fertig_cnt++; elseif ($erster_offen === 0) $erster_offen = (int)$s['id']; }
$produziert = erp_produktion_gebucht($id);
$benoetigt  = (int)$pa['menge'];
$prod_rest  = max(0, $benoetigt - (int)round($produziert));
$prod_proz  = $benoetigt > 0 ? min(100, (int)round($produziert * 100 / $benoetigt)) : 0;
$daten      = pr_daten($id);   // erfasste Werte (Mischmenge, Gewichte, Muster)
$reinigungen = [];             // bestätigte Reinigungen je Schritt (Spec 9.4)
foreach (pr_maschine_reinigung_log_pa($id) as $rr) if (!empty($rr['gereinigt'])) $reinigungen[(int)$rr['schritt_id']] = $rr;
$cur = null;
foreach ($schritte as $s) if ((int)$s['id'] === $erster_offen) { $cur = $s; break; }

kopf($pa['nummer'] . ' – Produktionsmodus', 'liste');
seitenkopf('Produktionsmodus · ' . (string)$pa['nummer'], (string)($pa['produkt_name'] ?? ''),
    '<a class="btn btn-ghost btn-sm" href="?p=pa&id=' . $id . '">Zur Übersicht</a>');
if (($pa['status'] ?? '') === 'vorbereitung') {
    echo '<div class="bx-panel badge-err" style="padding:14px 18px">Dieser Auftrag ist noch in <strong>Vorbereitung</strong> und nicht zur Produktion freigegeben. '
       . 'Die Freigabe erfolgt im Dashboard unter „Vor-Produktion". Starten ist erst danach möglich.</div>';
    fuss(); return;
}
?>
<div class="bx-panel" style="margin-bottom:16px">
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px 28px">
    <div><div class="muted" style="font-size:13px">Menge</div><div><?= number_format((int)$pa['menge'], 0, ',', '.') ?> Packungen</div></div>
    <div><div class="muted" style="font-size:13px">Charge<?= $charge['gebucht'] ? '' : ' (geplant)' ?></div><div><?= h($charge['nr']) ?></div></div>
    <div><div class="muted" style="font-size:13px">MHD<?= $charge['gebucht'] ? '' : ' (+18 Mon.)' ?></div><div><?= $charge['mhd'] ? h(date('d.m.Y', strtotime($charge['mhd']))) : '–' ?></div></div>
    <div><div class="muted" style="font-size:13px">Fortschritt</div><div><?= $fertig_cnt ?> / <?= $total ?></div></div>
  </div>
  <p class="muted" style="font-size:12px;margin:10px 0 0">Chargennummer und MHD vergibt das System automatisch.</p>
</div>

<div class="bx-panel" style="margin-bottom:16px">
  <div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
    <div>Produziert <strong><?= number_format($produziert, 0, ',', '.') ?></strong> von <?= number_format($benoetigt, 0, ',', '.') ?>
      <?php if ($produziert > 0 && $prod_rest > 0): ?> <span class="badge badge-info">teilweise</span><?php elseif ($benoetigt > 0 && $prod_rest <= 0): ?> <span class="badge badge-ok">vollständig</span><?php endif; ?></div>
    <div class="muted"><?= $prod_proz ?>%</div>
  </div>
  <div style="height:12px;border-radius:6px;background:var(--line-2);overflow:hidden;margin-top:8px">
    <div style="height:100%;width:<?= $prod_proz ?>%;background:var(--gruen)"></div>
  </div>
  <?php if ($prod_rest > 0): ?>
  <form method="post" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:14px" onsubmit="return confirm('Teilmenge jetzt produzieren? Rohstoffe werden anteilig abgebucht und als Fertigware-Charge eingebucht.');">
    <input type="hidden" name="aktion" value="teilmenge">
    <div class="bx-field" style="margin:0;max-width:200px"><label>Teilmenge produzieren</label>
      <input type="number" name="menge" min="1" max="<?= (int)$prod_rest ?>" step="1" required placeholder="max. <?= (int)$prod_rest ?>"></div>
    <button type="submit" class="btn btn-primary">Produzieren &amp; einbuchen</button>
  </form>
  <?php endif; ?>
</div>

<?php if ($cur):
    $isGate = str_contains((string)$cur['station'], 'Freigabe');
    $anl = station_anleitung_text((string)$cur['station']);
    $mat = erp_schritt_material($id, (string)$cur['station']);
    $istMischen = (string)$cur['station'] === 'Mischen';
    $cap = $istMischen ? (float) str_replace(',', '.', (string)($_GET['cap'] ?? ($daten['kg_pro_gebinde']['wert'] ?? ''))) : 0.0;
    $capTxt = $cap > 0 ? rtrim(rtrim(number_format($cap, 3, '.', ''), '0'), '.') : '';
    $mischplan = $istMischen ? erp_mischer_plan($id, $cap) : null;
    // Fehlt PFLICHT-Material für diesen Schritt? Dann ist er (noch) nicht erledigbar.
    // Info-Zeilen (pflicht=false, z. B. Deckel/Etikett) sperren nicht – sie werden nicht abgebucht.
    $materialFehlt = false;
    foreach ($mat['zeilen'] as $z)
        if (($z['pflicht'] ?? true) && empty($z['entnommen']) && isset($z['verfuegbar']) && (float)$z['verfuegbar'] + 0.0001 < (float)$z['menge']) { $materialFehlt = true; break; } ?>
<div class="bx-panel" style="margin-bottom:16px;border-color:var(--gruen);background:rgba(29,158,117,.06)">
  <div class="muted">Jetzt dran · Schritt <?= $fertig_cnt + 1 ?> von <?= $total ?></div>
  <h2 style="margin:4px 0 8px;font-size:22px"><?= h((string)$cur['station']) ?></h2>
  <?php if ($anl !== ''): ?><p style="margin:0 0 12px;font-size:15px"><?= h($anl) ?></p><?php endif; ?>

  <?php if ($istMischen && !empty($mischplan['ok'])): ?>
  <div style="margin:0 0 14px;padding:12px 14px;border:1px solid var(--line-2);border-radius:8px">
    <div class="muted" style="font-size:13px">Mischer-Kapazität</div>
    <div style="margin:4px 0 8px">Gesamt anzumischen: <strong><?= menge_txt($mischplan['total_kg']) ?> kg</strong> (<?= number_format((int)$mischplan['einheiten'], 0, ',', '.') ?> Einheiten)</div>
    <form method="get" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap;margin:0">
      <input type="hidden" name="p" value="run"><input type="hidden" name="id" value="<?= (int)$id ?>">
      <div class="bx-field" style="margin:0;max-width:180px"><label>kg je Gebinde</label><input type="number" name="cap" min="0.001" step="0.001" value="<?= h($capTxt) ?>" placeholder="z. B. 8"></div>
      <button type="submit" class="btn btn-ghost btn-sm">Berechnen</button>
    </form>
    <?php if (!empty($mischplan['gebinde'])): ?>
    <div style="margin-top:10px">Ergibt <strong><?= (int)$mischplan['anzahl'] ?></strong> Gebinde – ein Etikett je Gebinde. Angefangenes Gebinde komplett durchziehen (FIFO).</div>
    <div class="bx-tablewrap" style="margin-top:6px"><table class="bx-table">
      <thead><tr><th>Gebinde</th><th class="bx-num">Menge</th><th>Je Zutat</th></tr></thead>
      <tbody>
        <?php foreach ($mischplan['gebinde'] as $g): ?>
        <tr><td><?= (int)$g['nr'] ?> / <?= (int)$mischplan['anzahl'] ?></td><td class="bx-num"><?= menge_txt($g['kg']) ?> kg</td>
          <td class="muted" style="font-size:12px"><?php $t = []; foreach ($g['zutaten'] as $z) $t[] = h((string)$z['name']) . ': ' . menge_txt($z['kg']) . ' kg'; echo implode(' · ', $t); ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="muted" style="font-size:12px;margin:8px 0 0">Beim Abschließen des Mischens wird je Gebinde eine Untercharge der Mischcharge angelegt.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php // FEFO-Gebinde-Führung beim Abfüllen (Spec 7.8): die beim Mischen angelegten Gebinde in Reihenfolge durchziehen.
  if (!$istMischen && !$isGate):
      $gebinde = erp_gebinde_unterchargen($id);
      if ($gebinde):
          $naechstesId = 0; foreach ($gebinde as $g) { if ((string)($g['status'] ?? '') !== 'abgefuellt') { $naechstesId = (int)$g['id']; break; } }
          $gLbl = ['gemischt'=>['offen', ''], 'angefangen'=>['angefangen', 'badge-warn'], 'abgefuellt'=>['leer', 'badge-ok']]; ?>
  <div style="margin:0 0 14px;padding:12px 14px;border:1px solid var(--line-2);border-radius:8px">
    <div class="muted" style="font-size:13px">Gebinde abfüllen · FEFO</div>
    <div style="margin:4px 0 8px;font-size:14px">Angefangenes/ältestes Gebinde zuerst komplett durchziehen. Das als Nächstes zu verwendende ist markiert.</div>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Gebinde</th><th class="bx-num">Menge</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($gebinde as $g): $st = (string)($g['status'] ?? ''); $lbl = $gLbl[$st] ?? [$st ?: '–', '']; $next = (int)$g['id'] === $naechstesId; ?>
        <tr<?= $next ? ' style="outline:2px solid var(--gruen);outline-offset:-2px"' : '' ?>>
          <td><strong><?= h((string)($g['gebinde'] ?: ($g['nummer'] ?? '–'))) ?></strong><?= $next ? ' <span class="muted" style="font-size:12px">· als Nächstes</span>' : '' ?></td>
          <td class="bx-num"><?= $g['menge'] !== null ? menge_txt($g['menge']) . ' ' . h((string)($g['einheit'] ?? '')) : '–' ?></td>
          <td><span class="badge <?= h($lbl[1]) ?>"><?= h($lbl[0]) ?></span></td>
          <td style="text-align:right;white-space:nowrap">
            <?php if ($st !== 'abgefuellt'): ?>
              <?php if ($st !== 'angefangen'): ?><form method="post" style="display:inline;margin:0"><input type="hidden" name="aktion" value="gebinde_status"><input type="hidden" name="gebinde_id" value="<?= (int)$g['id'] ?>"><input type="hidden" name="status" value="angefangen"><button class="btn btn-ghost btn-sm" type="submit">angefangen</button></form> <?php endif; ?>
              <form method="post" style="display:inline;margin:0" onsubmit="return confirm('Gebinde als leer (fertig abgefüllt) melden?');"><input type="hidden" name="aktion" value="gebinde_status"><input type="hidden" name="gebinde_id" value="<?= (int)$g['id'] ?>"><input type="hidden" name="status" value="abgefuellt"><button class="btn btn-primary btn-sm" type="submit">leer</button></form>
            <?php else: ?>
              <form method="post" style="display:inline;margin:0"><input type="hidden" name="aktion" value="gebinde_status"><input type="hidden" name="gebinde_id" value="<?= (int)$g['id'] ?>"><input type="hidden" name="status" value="gemischt"><button class="btn btn-ghost btn-sm" type="submit" title="zurücksetzen">&#8634;</button></form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; endif; ?>

  <?php if ($mat['zeilen']): ?>
  <div style="margin:0 0 14px">
    <div class="muted" style="font-size:13px;margin-bottom:6px">Aus dem Lager holen<?php if ($mat['soll_menge'] !== null): ?> · benötigt <strong><?= menge_txt($mat['soll_menge']) ?> <?= h((string)$mat['soll_einheit']) ?></strong><?php endif; ?>:</div>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Material</th><th class="bx-num">Menge</th><th class="bx-num">Bestand</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($mat['zeilen'] as $z):
            $pflicht = $z['pflicht'] ?? true;
            $knapp = $pflicht && isset($z['verfuegbar']) && (float)$z['verfuegbar'] + 0.0001 < (float)$z['menge']; ?>
        <tr>
          <td><?= h((string)$z['name']) ?><?php if (!empty($z['detail'])): ?> <span class="muted" style="font-size:12px">· <?= h((string)$z['detail']) ?><?= $pflicht ? '' : ' (zur Info)' ?></span><?php endif; ?></td>
          <td class="bx-num"><?= menge_txt($z['menge']) ?> <?= h((string)$z['einheit']) ?></td>
          <td class="bx-num"<?= $knapp ? ' style="color:#8f231b"' : '' ?>>
            <?= isset($z['verfuegbar']) ? menge_txt($z['verfuegbar']) . ' ' . h((string)$z['einheit']) : '' ?>
            <?php if (!empty($z['quarantaene'])): ?><br><span class="muted" style="font-size:11px">+ <?= menge_txt($z['quarantaene']) ?> in Quarantäne – erst freigeben</span><?php endif; ?>
          </td>
          <td class="bx-num">
            <?php if (!empty($z['charge_id'])): ?>
            <form method="post" style="margin:0;display:inline-flex;gap:4px">
              <input type="hidden" name="charge_id" value="<?= (int)$z['charge_id'] ?>">
              <button type="submit" name="aktion" value="blink" class="btn btn-ghost btn-sm" title="Blinker am Lagerplatz leuchten lassen">Im Lager blinken</button>
              <button type="submit" name="aktion" value="blink" class="btn btn-ghost btn-sm" title="Blinker ausschalten" onclick="this.form.querySelector('[name=modus]').value='aus'">Aus</button>
              <input type="hidden" name="modus" value="an">
            </form>
            <?php else: ?><span class="muted" style="font-size:12px">kein Blinker</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>

  <?php if ($materialFehlt): ?>
    <div class="bx-panel warn" style="margin:0 0 12px;padding:10px 14px">Noch nicht möglich: Das benötigte Material ist nicht vollständig im Lager. Bitte erst bereitstellen bzw. im Wareneingang buchen.</div>
    <button type="button" class="btn btn-primary" style="font-size:16px;padding:12px 28px" disabled><?= $isGate ? 'Freigeben' : 'Erledigt' ?></button>
    <?php if ($istAdmin): ?><div class="muted" style="font-size:12px;margin-top:8px">Admin: über „Abhaken" in der Ablaufliste lässt sich der Schritt notfalls trotzdem setzen (ohne Lagerabbuchung).</div><?php endif; ?>
  <?php else: ?>
  <form method="post" enctype="multipart/form-data" style="margin:0" onsubmit="return confirm('Schritt &quot;<?= h((string)$cur['station']) ?>&quot; jetzt abschließen?');">
    <input type="hidden" name="aktion" value="erledigen">
    <input type="hidden" name="schritt_id" value="<?= (int)$cur['id'] ?>">
    <?php if ($istMischen && $cap > 0): ?><input type="hidden" name="kg_pro_gebinde" value="<?= h($capTxt) ?>"><?php endif; ?>
    <?php $mtypen = pr_station_maschinentypen((string)$cur['station']);
          $maschinen_liste = $mtypen ? pr_maschinen_fuer_station((string)$cur['station']) : [];
          if ($mtypen): ?>
    <div class="bx-row" style="gap:12px;flex-wrap:wrap;margin:0 0 14px;align-items:flex-end">
      <div class="bx-field" style="margin:0;max-width:280px">
        <label>Maschine (<?= h(implode(' / ', array_map('pr_maschinentyp_label', $mtypen))) ?>) scannen oder wählen</label>
        <?php if ($maschinen_liste): ?>
        <select name="maschine_id">
          <option value="">— wählen —</option>
          <?php foreach ($maschinen_liste as $m): ?><option value="<?= (int)$m['id'] ?>"><?= h((string)$m['name']) ?><?= $m['qr_code'] ? ' (' . h((string)$m['qr_code']) . ')' : '' ?></option><?php endforeach; ?>
        </select>
        <?php else: ?>
        <div class="muted" style="font-size:12px">Keine Maschine dieses Typs angelegt – unter <a href="?p=einstellungen">Einstellungen</a> pflegen.</div>
        <?php endif; ?>
      </div>
      <div class="bx-field" style="margin:0;max-width:200px"><label>QR-Code</label><input type="text" name="maschine_qr" placeholder="QR der Maschine"></div>
    </div>
    <?php if ($maschinen_liste): ?>
    <div style="margin:0 0 14px;padding:12px 14px;border:1px solid var(--line-2);border-radius:8px">
      <div class="muted" style="font-size:13px">Reinigung</div>
      <div class="bx-row" style="gap:16px;flex-wrap:wrap;align-items:flex-end;margin-top:6px">
        <div class="bx-field" style="margin:0;max-width:220px"><label>Maschine beim Start sauber?</label>
          <select name="sauber" required><option value="">— bitte wählen —</option><option value="ja">Ja</option><option value="nein">Nein</option></select></div>
        <div class="bx-field" style="margin:0;max-width:240px"><label>Nach Nutzung gereinigt</label>
          <label style="display:flex;gap:8px;align-items:center;font-weight:400"><input type="checkbox" name="gereinigt" value="1"> bestätige Reinigung</label></div>
        <div class="bx-field" style="margin:0;max-width:220px"><label>Unterschrift (Name)</label><input type="text" name="unterschrift" value="<?= h($akteur) ?>"></div>
        <div class="bx-field" style="margin:0;max-width:240px"><label>Bild (optional)</label><input type="file" name="reinigung_bild" accept="image/*"></div>
      </div>
      <p class="muted" style="font-size:12px;margin:8px 0 0">„Nein" blockiert den Abschluss, bis die Maschine gereinigt und bestätigt ist.</p>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    <div class="bx-row" style="gap:12px;flex-wrap:wrap;margin:0 0 14px;align-items:flex-end">
      <div class="bx-field" style="margin:0;max-width:160px"><label>Temperatur (°C)</label><input type="text" name="umg_temp" value="<?= h((string)($daten['temp_' . $cur['id']]['wert'] ?? '')) ?>" placeholder="z. B. 21"></div>
      <div class="bx-field" style="margin:0;max-width:160px"><label>Luftfeuchte (%)</label><input type="text" name="umg_feuchte" value="<?= h((string)($daten['feuchte_' . $cur['id']]['wert'] ?? '')) ?>" placeholder="z. B. 45"></div>
    </div>
    <?php $felder = pr_station_felder((string)$cur['station']); if ($felder): ?>
    <div class="bx-row" style="gap:12px;flex-wrap:wrap;margin:0 0 14px">
      <?php foreach ($felder as $feld): ?>
      <div class="bx-field" style="margin:0;max-width:220px">
        <label><?= h($feld['label']) ?><?= $feld['einheit'] !== '' ? ' (' . h($feld['einheit']) . ')' : '' ?></label>
        <input type="text" name="daten[<?= h($feld['feld']) ?>]" value="<?= h((string)($daten[$feld['feld']]['wert'] ?? '')) ?>">
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary" style="font-size:16px;padding:12px 28px"><?= $isGate ? 'Freigeben' : 'Erledigt' ?></button>
  </form>
  <?php endif; ?>
</div>

<?php // Pause / Schichtwechsel (Spec 7.11/7.12) – nur HIER, zwischen den Schritten (sauberer Punkt).
$letzteStation = ($fertig_cnt > 0 && isset($schritte[$fertig_cnt - 1]['station'])) ? (string)$schritte[$fertig_cnt - 1]['station'] : 'Start';
$pausen = pr_pausen_fuer_pa($id); ?>
<div class="bx-panel" style="margin-bottom:16px">
  <h2 style="margin:0 0 4px;font-size:15px">Pause / Schichtwechsel</h2>
  <div class="muted" style="font-size:13px;margin-bottom:10px">Nur zwischen den Schritten eintragen – an einem sauberen Punkt. Aktuell nach: <strong><?= h($letzteStation) ?></strong>.</div>
  <form method="post" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="aktion" value="pause"><input type="hidden" name="nach_station" value="<?= h($letzteStation) ?>">
    <div class="bx-field" style="margin:0;max-width:170px"><label>Art</label>
      <select name="art"><option value="pause">Pause</option><option value="schichtende">Schichtwechsel</option></select></div>
    <div class="bx-field" style="margin:0;max-width:210px"><label>Übergabe an (optional)</label><input type="text" name="an_wen" placeholder="Name Nachfolger"></div>
    <div class="bx-field" style="margin:0;min-width:200px;flex:1"><label>Notiz (optional)</label><input type="text" name="grund" placeholder="z. B. Mittagspause"></div>
    <button class="btn btn-ghost" type="submit">Erfassen</button>
  </form>
  <?php if ($pausen): ?>
  <div class="bx-tablewrap" style="margin-top:10px"><table class="bx-table" style="margin:0">
    <thead><tr><th>Wann</th><th>Art</th><th>Nach</th><th>Von</th><th>Übergabe</th><th>Notiz</th></tr></thead>
    <tbody>
      <?php foreach ($pausen as $pz): ?>
      <tr><td><?= h(fmt_zeit((string)$pz['angelegt'])) ?></td>
          <td><?= ($pz['art'] ?? '') === 'schichtende' ? 'Schichtwechsel' : 'Pause' ?></td>
          <td class="muted"><?= h((string)($pz['nach_station'] ?? '')) ?></td>
          <td><?= h((string)($pz['von'] ?? '')) ?></td>
          <td><?= h((string)($pz['an_wen'] ?? '')) ?: '<span class="muted">–</span>' ?></td>
          <td class="muted"><?= h((string)($pz['grund'] ?? '')) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="bx-panel badge-ok" style="margin-bottom:16px;padding:14px 18px">Alle Schritte erledigt – die Produktion ist abgeschlossen.</div>
<?php endif; ?>

<div class="bx-panel">
  <h2 style="margin-top:0">Ablauf</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th class="bx-num">#</th><th>Station</th><th>Status</th><th>Erledigt von</th><th>Wann</th><?php if ($istAdmin): ?><th>Admin</th><?php endif; ?></tr></thead>
    <tbody>
      <?php foreach ($schritte as $i => $s):
          $done = (int)($s['erledigt'] ?? 0) === 1;
          $dran = (int)$s['id'] === $erster_offen; ?>
      <tr<?= $dran ? ' style="background:var(--panel-2)"' : '' ?>>
        <td class="bx-num"><?= (int)$i + 1 ?></td>
        <td><?= h((string)$s['station']) ?>
          <?php foreach (pr_station_felder((string)$s['station']) as $feld): if (!empty($daten[$feld['feld']]['wert'])): ?>
            <br><span class="muted" style="font-size:12px"><?= h($feld['label']) ?>: <?= h((string)$daten[$feld['feld']]['wert']) ?><?= $feld['einheit'] !== '' ? ' ' . h($feld['einheit']) : '' ?></span>
          <?php endif; endforeach; ?>
          <?php if (!empty($daten['maschine_' . $s['id']]['wert'])): ?>
            <br><span class="muted" style="font-size:12px">Maschine: <?= h((string)$daten['maschine_' . $s['id']]['wert']) ?></span>
          <?php endif; ?>
          <?php $kt = (string)($daten['temp_' . $s['id']]['wert'] ?? ''); $kf = (string)($daten['feuchte_' . $s['id']]['wert'] ?? '');
                if ($kt !== '' || $kf !== ''): ?>
            <br><span class="muted" style="font-size:12px">Klima: <?= $kt !== '' ? h($kt) . ' °C' : '' ?><?= ($kt !== '' && $kf !== '') ? ' / ' : '' ?><?= $kf !== '' ? h($kf) . ' %' : '' ?></span>
          <?php endif; ?>
          <?php if (!empty($reinigungen[(int)$s['id']])): ?>
            <br><span class="muted" style="font-size:12px">Gereinigt: <?= h((string)($reinigungen[(int)$s['id']]['unterschrift'] ?: $reinigungen[(int)$s['id']]['von'])) ?></span>
          <?php endif; ?>
        </td>
        <td><?= $done ? '<span class="badge badge-ok">erledigt</span>' : ($dran ? '<span class="badge badge-info">als Nächstes</span>' : '<span class="badge badge-warn">offen</span>') ?></td>
        <td class="muted"><?= h((string)($s['erledigt_von'] ?? '')) ?></td>
        <td class="muted"><?= h(fmt_zeit($s['erledigt_at'] ?? null)) ?></td>
        <?php if ($istAdmin): ?>
        <td class="bx-num">
          <form method="post" style="margin:0;display:inline">
            <input type="hidden" name="aktion" value="<?= $done ? 'admin_undo' : 'admin_done' ?>">
            <input type="hidden" name="schritt_id" value="<?= (int)$s['id'] ?>">
            <button type="submit" class="btn btn-ghost btn-sm"><?= $done ? 'Zurücksetzen' : 'Abhaken' ?></button>
          </form>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($istAdmin): ?>
  <p class="muted" style="font-size:12px;margin:10px 0 0">Admin: „Abhaken"/„Zurücksetzen" ändert nur den Schritt-Status (auch außer der Reihe) – ohne Lager-/Chargenbewegung. Das normale „Erledigt" oben bucht Material ab und am Ende die Fertigware ein.</p>
  <?php endif; ?>
</div>
<?php fuss();
