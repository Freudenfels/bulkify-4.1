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
        $r = erp_schritt_abschliessen($schritt_id, $akteur);
        if ($r['ok']) {
            foreach (pr_station_felder((string)$r['station']) as $feld) {
                $v = trim((string)($_POST['daten'][$feld['feld']] ?? ''));
                if ($v !== '') pr_daten_setzen($id, $feld['feld'], $v, $akteur);
            }
        }
        flash($r['ok'] ? ($r['fertig'] ? 'Letzter Schritt erledigt – Produktion fertig, Fertigware eingebucht.' : 'Schritt „' . $r['station'] . '" erledigt.')
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
$cur = null;
foreach ($schritte as $s) if ((int)$s['id'] === $erster_offen) { $cur = $s; break; }

kopf($pa['nummer'] . ' – Produktionsmodus', 'liste');
seitenkopf('Produktionsmodus · ' . (string)$pa['nummer'], (string)($pa['produkt_name'] ?? ''),
    '<a class="btn btn-ghost btn-sm" href="?p=pa&id=' . $id . '">Zur Übersicht</a>');
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
    // Fehlt PFLICHT-Material für diesen Schritt? Dann ist er (noch) nicht erledigbar.
    // Info-Zeilen (pflicht=false, z. B. Deckel/Etikett) sperren nicht – sie werden nicht abgebucht.
    $materialFehlt = false;
    foreach ($mat['zeilen'] as $z)
        if (($z['pflicht'] ?? true) && empty($z['entnommen']) && isset($z['verfuegbar']) && (float)$z['verfuegbar'] + 0.0001 < (float)$z['menge']) { $materialFehlt = true; break; } ?>
<div class="bx-panel" style="margin-bottom:16px;border-color:var(--gruen);background:rgba(29,158,117,.06)">
  <div class="muted">Jetzt dran · Schritt <?= $fertig_cnt + 1 ?> von <?= $total ?></div>
  <h2 style="margin:4px 0 8px;font-size:22px"><?= h((string)$cur['station']) ?></h2>
  <?php if ($anl !== ''): ?><p style="margin:0 0 12px;font-size:15px"><?= h($anl) ?></p><?php endif; ?>

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
  <form method="post" style="margin:0" onsubmit="return confirm('Schritt &quot;<?= h((string)$cur['station']) ?>&quot; jetzt abschließen?');">
    <input type="hidden" name="aktion" value="erledigen">
    <input type="hidden" name="schritt_id" value="<?= (int)$cur['id'] ?>">
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
