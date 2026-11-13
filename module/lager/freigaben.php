<?php
// Offene Kundenfreigaben: bulkify-Spezifikationen (je Rohstoff) und Analysenzertifikate (je Charge),
// die geprueft und freigegeben werden muessen, bevor der Kunde sie sieht. Damit nichts liegen bleibt.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/spec_ki.php';   // spec_snapshot_setzen (Freigabe = Snapshot)
require_once BX_ROOT . '/core/lieferant_unterlagen.php';   // Sofortfix 13.11.2026: Quarantäne-Sperre, solange CoA/Spec fehlt

// Offene Spec-Freigaben = (a) GEÄNDERT seit letzter Freigabe (spec_aenderung_offen=1) ODER
// (b) noch NIE freigegeben, aber mit Spec-Inhalt (Alt-Rückstand; neue Uploads geben automatisch frei).
$SPEC_HAT_DATEN = "( EXISTS (SELECT 1 FROM item_kennwert k WHERE k.item_id=i.id)
                   OR EXISTS (SELECT 1 FROM item_wirkstoff w WHERE w.item_id=i.id)
                   OR EXISTS (SELECT 1 FROM item_grenzwert g WHERE g.item_id=i.id)
                   OR (i.spec_pdf IS NOT NULL AND i.spec_pdf<>'') )";

// Ware in Quarantäne freigeben (Nico 04.11.2026): einzeln oder alle – gleiche Logik wie im Wareneingang (charge_quarantaene_freigeben).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['aktion'] ?? ''), ['q_frei','q_alle_frei'], true)) {
    if (!darf_qm_freigeben()) { header('Location: ?p=freigaben&nurqm=1'); exit; }
    $ids = ($_POST['aktion'] === 'q_frei')
        ? [(int)($_POST['charge_id'] ?? 0)]
        : array_map('intval', array_column(all("SELECT id FROM charge WHERE status='quarantaene' AND fremd_kunde_id IS NULL AND menge_verfuegbar>0"), 'id'));
    $n = 0; $gesperrt = [];
    foreach ($ids as $cid) {
        if ($cid <= 0) continue;
        if (charge_quarantaene_freigeben($cid)) $n++;
        elseif ((string)($GLOBALS['bx_freigabe_fehler'] ?? '') !== '') $gesperrt[] = (string)$GLOBALS['bx_freigabe_fehler'];
    }
    if ($n) { bedarf_bump(); nach_antwort(fn() => material_komplett_pruefen(true)); }
    header('Location: ?p=freigaben&qn=' . $n . ($gesperrt ? '&qsperre=' . rawurlencode(count($gesperrt) === 1 ? $gesperrt[0] : count($gesperrt) . ' Charge(n) nicht freigegeben – CoA/Spec des Lieferanten fehlt.') : '')); exit;
}
// Sofortfix 13.11.2026: Unterlage aus dem Pop-up hochladen bzw. Admin-Ausnahme mit Grund.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['aktion'] ?? ''), ['lu_upload', 'q_frei_admin'], true)) {
    if (!darf_qm_freigeben()) { header('Location: ?p=freigaben&nurqm=1'); exit; }
    $r = lu_freigabe_post();
    if ($r['ok'] && $_POST['aktion'] === 'q_frei_admin') { bedarf_bump(); nach_antwort(fn() => material_komplett_pruefen(true)); }
    header('Location: ?p=freigaben&' . ($r['ok'] ? 'qok=' : 'qsperre=') . rawurlencode($r['msg']));
    if (!empty($r['did'])) lu_upload_nachlauf((int)$r['did']);
    exit;
}
lu_abgleich();   // Unterlage nachgereicht -> Aufgabe an die QM

// Sammel-Freigabe: alle aktuell offenen Spezifikationen bzw. alle offenen CoAs in einem Rutsch freigeben.
// Spec-Freigabe schreibt je Rohstoff den Snapshot (spec_snapshot_setzen) – der Kunde sieht ab dann diesen Stand.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['aktion'] ?? ''), ['spec_alle_frei','coa_alle_frei'], true)) {
    $wer   = trim((string)(current_user()['name'] ?? 'Team'));
    $jetzt = gmdate('Y-m-d H:i:s');
    if (($_POST['aktion']) === 'spec_alle_frei') {
        $ids = all("SELECT i.id FROM item i
                    WHERE i.kategorie='rohstoff' AND i.gesperrt=0
                      AND ( COALESCE(i.spec_aenderung_offen,0)=1
                         OR (COALESCE(i.spec_freigegeben,0)=0 AND $SPEC_HAT_DATEN) )");
        foreach ($ids as $r) spec_snapshot_setzen((int)$r['id'], $wer, false);
        $n = count($ids);
        log_aktivitaet('system', 0, 'team', $n . ' bulkify-Spezifikation(en) per Sammel-Freigabe für Kunden freigegeben.', 'notiz');
        header('Location: ?p=freigaben&specn=' . (int)$n); exit;
    }
    $n = q("UPDATE charge SET coa_freigegeben=1, coa_freigabe_am=?, coa_freigabe_von=?
            WHERE COALESCE(coa_freigegeben,0)=0 AND EXISTS (SELECT 1 FROM charge_analyse a WHERE a.charge_id=charge.id)", [$jetzt, $wer])->rowCount();
    log_aktivitaet('system', 0, 'team', $n . ' bulkify-CoA(s) per Sammel-Freigabe für Kunden freigegeben.', 'notiz');
    header('Location: ?p=freigaben&coan=' . (int)$n); exit;
}

// Rohstoffe, deren Kundenfreigabe aussteht: geändert (erneute Freigabe) oder nie freigegeben (Alt-Rückstand).
$specOffen = all("SELECT i.id, i.artikelnummer, i.name, COALESCE(i.spec_aenderung_offen,0) AS geaendert,
        (SELECT COUNT(*) FROM item_kennwert k WHERE k.item_id=i.id) AS kennwerte,
        (SELECT COUNT(*) FROM item_wirkstoff w WHERE w.item_id=i.id) AS wirkstoffe,
        (SELECT COUNT(*) FROM item_grenzwert g WHERE g.item_id=i.id) AS grenzwerte
    FROM item i
    WHERE i.kategorie='rohstoff' AND i.gesperrt=0
      AND ( COALESCE(i.spec_aenderung_offen,0)=1
         OR (COALESCE(i.spec_freigegeben,0)=0 AND $SPEC_HAT_DATEN) )
    ORDER BY COALESCE(i.spec_aenderung_offen,0) DESC, i.name");

// Chargen mit Analysenwerten (also einem CoA), aber CoA noch nicht freigegeben.
$coaOffen = all("SELECT c.id AS charge_id, c.charge_nr, c.mhd, c.wareneingang, i.id AS item_id, i.name,
        (SELECT COUNT(*) FROM charge_analyse a WHERE a.charge_id=c.id) AS werte
    FROM charge c JOIN item i ON i.id=c.item_id
    WHERE COALESCE(c.coa_freigegeben,0)=0
      AND EXISTS (SELECT 1 FROM charge_analyse a WHERE a.charge_id=c.id)
    ORDER BY (c.wareneingang IS NULL), c.wareneingang DESC, c.id DESC");

$nfDatum = fn($d) => $d ? date('d.m.Y', strtotime((string)$d)) : '–';

// Ware in Quarantäne (Lager 1): Herkunft = Lieferant ODER Produktionsauftrag (eigene Fertigware).
$quarOffen = all("SELECT c.id, c.charge_nr, c.unterlagen_fehlt, c.menge_verfuegbar, c.einheit, c.mhd, COALESCE(c.wareneingang, DATE(c.angelegt)) AS eingang,
        i.id AS item_id, i.name, i.kategorie, l.firma AS lieferant, pa.nummer AS pa_nummer, a.nummer AS auftrag_nr, c.auftrag_id
    FROM charge c JOIN item i ON i.id=c.item_id
    LEFT JOIN lieferanten l ON l.id=c.lieferant_id
    LEFT JOIN produktionsauftrag pa ON pa.id=c.pa_id
    LEFT JOIN auftrag a ON a.id=c.auftrag_id
    WHERE c.status='quarantaene' AND c.fremd_kunde_id IS NULL AND c.menge_verfuegbar>0
    ORDER BY COALESCE(c.wareneingang, DATE(c.angelegt)) ASC, i.name");
$darfQ = darf_qm_freigeben();

render_header('freigaben', 'Freigaben');
bx_head('Offene Freigaben', 'Ware in Quarantäne und Kundendokumente, die noch freigegeben werden müssen');
if (isset($_GET['specn'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . (int)$_GET['specn'] . ' Spezifikation(en) freigegeben.</div>';
if (isset($_GET['coan']))  echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . (int)$_GET['coan'] . ' Analysenzertifikat(e) freigegeben.</div>';
if (isset($_GET['qn']))    echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . (int)$_GET['qn'] . ' Charge(n) aus der Quarantäne freigegeben – zählt sofort als verfügbar.</div>';
if (isset($_GET['qok']))     echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h((string)$_GET['qok']) . '</div>';
if (isset($_GET['qsperre'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['qsperre']) . '</div>';
if (isset($_GET['nurqm'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Freigeben aus der Quarantäne darf nur QM oder Admin.</div>';
?>
<p class="muted" style="margin-top:0;max-width:820px">Beim <strong>Erst-Upload</strong> werden Spezifikation und CoA automatisch freigegeben. Hier stehen nur noch die Fälle, die eine <strong>Freigabe-Entscheidung</strong> brauchen: <strong>geänderte</strong> Specs (der Kunde sieht noch die alte Version, bis ihr die Änderung freigebt) und etwaige Alt-Rückstände, die noch nie freigegeben wurden. CoAs ohne Analysewerte bleiben unten stehen, bis sie geprüft sind.</p>

<div class="bx-cards" style="margin-bottom:16px">
  <div class="bx-card"><div class="k">Ware in Quarantäne</div><div class="v"><?= count($quarOffen) ?></div></div>
  <div class="bx-card"><div class="k">Spezifikationen offen</div><div class="v"><?= count($specOffen) ?></div></div>
  <div class="bx-card"><div class="k">Analysenzertifikate offen</div><div class="v"><?= count($coaOffen) ?></div></div>
</div>

<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <h2 style="margin:0">Ware in Quarantäne</h2>
    <?php if ($quarOffen && $darfQ): ?>
    <form method="post" style="margin:0" onsubmit="return confirm('Alle <?= count($quarOffen) ?> Chargen aus der Quarantäne freigeben? Bei eigener Fertigware wird damit auch der Produktionsbericht für den Kunden freigegeben.');">
      <input type="hidden" name="aktion" value="q_alle_frei">
      <button class="btn btn-primary btn-sm" type="submit">Alle freigeben (<?= count($quarOffen) ?>)</button>
    </form>
    <?php endif; ?>
  </div>
  <?php if (!$quarOffen): ?>
    <div class="muted">Nichts in Quarantäne.</div>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Artikel</th><th>Warenart</th><th>Charge</th><th class="bx-num">Menge</th><th>MHD</th><th>Eingang</th><th>Herkunft</th><th>Auftrag</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($quarOffen as $c): ?>
        <tr>
          <td><?= h($c['name']) ?></td>
          <td class="muted"><?= h(kategorie_label((string)$c['kategorie'])) ?></td>
          <td><?= h($c['charge_nr'] ?: ('#' . (int)$c['id'])) ?><?php $qGrund = lu_charge_grund((int)$c['id']); if ($qGrund !== ''): ?><div style="font-size:11px;color:#8f231b"><?= h($qGrund) ?></div><?php elseif ((int)($c['unterlagen_fehlt'] ?? 0) === 2): ?><div class="muted" style="font-size:11px">Unterlage nachgereicht – bitte prüfen und freigeben</div><?php endif; ?></td>
          <td class="bx-num"><?= number_format((float)$c['menge_verfuegbar'], ((float)$c['menge_verfuegbar'] == floor((float)$c['menge_verfuegbar'])) ? 0 : 3, ',', '.') ?> <?= h($c['einheit'] ?: 'Stk') ?></td>
          <td><?= $nfDatum($c['mhd']) ?></td>
          <td><?= $nfDatum($c['eingang']) ?></td>
          <td><?= $c['pa_nummer'] ? 'Produktion ' . h($c['pa_nummer']) : h($c['lieferant'] ?: '–') ?></td>
          <td><?= $c['auftrag_nr'] ? '<a href="?p=auftrag&id=' . (int)$c['auftrag_id'] . '">' . h($c['auftrag_nr']) . '</a>' : '<span class="muted">–</span>' ?></td>
          <td class="bx-num"><?php if ($darfQ): $qZelle = lu_freigabe_zelle((int)$c['id'], '?p=freigaben'); ?>
            <?php if ($qZelle !== ''): echo $qZelle; else: ?>
            <form method="post" style="margin:0;display:inline"><input type="hidden" name="aktion" value="q_frei"><input type="hidden" name="charge_id" value="<?= (int)$c['id'] ?>"><button class="btn btn-primary btn-sm" type="submit">freigeben</button></form>
            <?php endif; ?>
          <?php else: ?><span class="muted">nur QM</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <h2 style="margin:0">Spezifikationen zur Freigabe</h2>
    <?php if ($specOffen): ?>
    <form method="post" style="margin:0" onsubmit="return confirm('Alle <?= count($specOffen) ?> offenen Spezifikationen für die Kunden freigeben?');">
      <input type="hidden" name="aktion" value="spec_alle_frei">
      <button class="btn btn-primary btn-sm" type="submit">Alle freigeben (<?= count($specOffen) ?>)</button>
    </form>
    <?php endif; ?>
  </div>
  <?php if (!$specOffen): ?>
    <div class="muted">Keine offenen Spezifikationen – alles freigegeben.</div>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Rohstoff</th><th>Artikelnr.</th><th>Status</th><th class="bx-num">Wirkstoffe</th><th class="bx-num">Kennwerte</th><th class="bx-num">Grenzwerte</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($specOffen as $r): ?>
        <tr>
          <td><a href="?p=rohstoff&id=<?= (int)$r['id'] ?>&tab=spec"><?= h($r['name']) ?></a></td>
          <td class="muted"><?= h($r['artikelnummer'] ?: '–') ?></td>
          <td><?= (int)$r['geaendert'] === 1 ? bx_badge('geändert','warn') : bx_badge('neu','info') ?></td>
          <td class="bx-num"><?= (int)$r['wirkstoffe'] ?></td>
          <td class="bx-num"><?= (int)$r['kennwerte'] ?></td>
          <td class="bx-num"><?= (int)$r['grenzwerte'] ?></td>
          <td class="bx-num"><a class="btn btn-primary btn-sm" href="?p=rohstoff&id=<?= (int)$r['id'] ?>&tab=spec"><?= (int)$r['geaendert'] === 1 ? 'Änderung prüfen' : 'Prüfen &amp; freigeben' ?></a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <h2 style="margin:0">Analysenzertifikate (CoA) zur Freigabe</h2>
    <?php if ($coaOffen): ?>
    <form method="post" style="margin:0" onsubmit="return confirm('Alle <?= count($coaOffen) ?> offenen Analysenzertifikate für die Kunden freigeben?');">
      <input type="hidden" name="aktion" value="coa_alle_frei">
      <button class="btn btn-primary btn-sm" type="submit">Alle freigeben (<?= count($coaOffen) ?>)</button>
    </form>
    <?php endif; ?>
  </div>
  <?php if (!$coaOffen): ?>
    <div class="muted">Keine offenen Analysenzertifikate – alles freigegeben.</div>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Rohstoff</th><th>Charge</th><th>MHD</th><th>Wareneingang</th><th class="bx-num">Werte</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($coaOffen as $c): ?>
        <tr>
          <td><a href="?p=rohstoff&id=<?= (int)$c['item_id'] ?>&tab=lager"><?= h($c['name']) ?></a></td>
          <td><?= h($c['charge_nr'] ?: ('#' . (int)$c['charge_id'])) ?></td>
          <td><?= $nfDatum($c['mhd']) ?></td>
          <td><?= $c['wareneingang'] ? $nfDatum($c['wareneingang']) : '<span class="muted">CoA vorab</span>' ?></td>
          <td class="bx-num"><?= (int)$c['werte'] ?></td>
          <td class="bx-num">
            <a class="btn btn-ghost btn-sm" target="_blank" href="?p=coa_bulkify&id=<?= (int)$c['charge_id'] ?>">CoA ansehen</a>
            <a class="btn btn-primary btn-sm" href="?p=rohstoff&id=<?= (int)$c['item_id'] ?>&tab=lager">Zur Freigabe</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
