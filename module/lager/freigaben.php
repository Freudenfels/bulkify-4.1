<?php
// Offene Kundenfreigaben: bulkify-Spezifikationen (je Rohstoff) und Analysenzertifikate (je Charge),
// die geprueft und freigegeben werden muessen, bevor der Kunde sie sieht. Damit nichts liegen bleibt.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

// Sammel-Freigabe: alle aktuell offenen Spezifikationen bzw. alle offenen CoAs in einem Rutsch freigeben
// (gleiche Bedingung wie die Listen unten). Datum/Von je Posten wie bei der Einzel-Freigabe.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['aktion'] ?? ''), ['spec_alle_frei','coa_alle_frei'], true)) {
    $wer   = trim((string)(current_user()['name'] ?? 'Team'));
    $jetzt = gmdate('Y-m-d H:i:s');
    if (($_POST['aktion']) === 'spec_alle_frei') {
        $n = q("UPDATE item SET spec_freigegeben=1, spec_freigabe_am=?, spec_freigabe_von=?
                WHERE kategorie='rohstoff' AND gesperrt=0 AND COALESCE(spec_freigegeben,0)=0
                  AND ( EXISTS (SELECT 1 FROM item_kennwert k WHERE k.item_id=item.id)
                     OR EXISTS (SELECT 1 FROM item_wirkstoff w WHERE w.item_id=item.id)
                     OR EXISTS (SELECT 1 FROM item_grenzwert g WHERE g.item_id=item.id)
                     OR (spec_pdf IS NOT NULL AND spec_pdf<>'') )", [$jetzt, $wer])->rowCount();
        log_aktivitaet('system', 0, 'team', $n . ' bulkify-Spezifikation(en) per Sammel-Freigabe für Kunden freigegeben.', 'notiz');
        header('Location: ?p=freigaben&specn=' . (int)$n); exit;
    }
    $n = q("UPDATE charge SET coa_freigegeben=1, coa_freigabe_am=?, coa_freigabe_von=?
            WHERE COALESCE(coa_freigegeben,0)=0 AND EXISTS (SELECT 1 FROM charge_analyse a WHERE a.charge_id=charge.id)", [$jetzt, $wer])->rowCount();
    log_aktivitaet('system', 0, 'team', $n . ' bulkify-CoA(s) per Sammel-Freigabe für Kunden freigegeben.', 'notiz');
    header('Location: ?p=freigaben&coan=' . (int)$n); exit;
}

// Rohstoffe mit Spec-Inhalt, aber noch nicht fuer den Kunden freigegeben.
$specOffen = all("SELECT i.id, i.artikelnummer, i.name,
        (SELECT COUNT(*) FROM item_kennwert k WHERE k.item_id=i.id) AS kennwerte,
        (SELECT COUNT(*) FROM item_wirkstoff w WHERE w.item_id=i.id) AS wirkstoffe,
        (SELECT COUNT(*) FROM item_grenzwert g WHERE g.item_id=i.id) AS grenzwerte
    FROM item i
    WHERE i.kategorie='rohstoff' AND i.gesperrt=0 AND COALESCE(i.spec_freigegeben,0)=0
      AND ( EXISTS (SELECT 1 FROM item_kennwert k WHERE k.item_id=i.id)
         OR EXISTS (SELECT 1 FROM item_wirkstoff w WHERE w.item_id=i.id)
         OR EXISTS (SELECT 1 FROM item_grenzwert g WHERE g.item_id=i.id)
         OR (i.spec_pdf IS NOT NULL AND i.spec_pdf<>'') )
    ORDER BY i.name");

// Chargen mit Analysenwerten (also einem CoA), aber CoA noch nicht freigegeben.
$coaOffen = all("SELECT c.id AS charge_id, c.charge_nr, c.mhd, c.wareneingang, i.id AS item_id, i.name,
        (SELECT COUNT(*) FROM charge_analyse a WHERE a.charge_id=c.id) AS werte
    FROM charge c JOIN item i ON i.id=c.item_id
    WHERE COALESCE(c.coa_freigegeben,0)=0
      AND EXISTS (SELECT 1 FROM charge_analyse a WHERE a.charge_id=c.id)
    ORDER BY (c.wareneingang IS NULL), c.wareneingang DESC, c.id DESC");

$nfDatum = fn($d) => $d ? date('d.m.Y', strtotime((string)$d)) : '–';

render_header('freigaben', 'Freigaben');
bx_head('Offene Freigaben', 'Kundendokumente, die noch geprüft und freigegeben werden müssen');
if (isset($_GET['specn'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . (int)$_GET['specn'] . ' Spezifikation(en) freigegeben.</div>';
if (isset($_GET['coan']))  echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . (int)$_GET['coan'] . ' Analysenzertifikat(e) freigegeben.</div>';
?>
<p class="muted" style="margin-top:0;max-width:760px">Der Kunde sieht eine Spezifikation bzw. ein Analysenzertifikat erst, wenn ihr es freigegeben habt. Hier stehen die noch <strong>nicht freigegebenen</strong> – zum Prüfen und Freigeben auf der jeweiligen Detailseite.</p>

<div class="bx-cards" style="margin-bottom:16px">
  <div class="bx-card"><div class="k">Spezifikationen offen</div><div class="v"><?= count($specOffen) ?></div></div>
  <div class="bx-card"><div class="k">Analysenzertifikate offen</div><div class="v"><?= count($coaOffen) ?></div></div>
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
    <thead><tr><th>Rohstoff</th><th>Artikelnr.</th><th class="bx-num">Wirkstoffe</th><th class="bx-num">Kennwerte</th><th class="bx-num">Grenzwerte</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($specOffen as $r): ?>
        <tr>
          <td><a href="?p=rohstoff&id=<?= (int)$r['id'] ?>&tab=spec"><?= h($r['name']) ?></a></td>
          <td class="muted"><?= h($r['artikelnummer'] ?: '–') ?></td>
          <td class="bx-num"><?= (int)$r['wirkstoffe'] ?></td>
          <td class="bx-num"><?= (int)$r['kennwerte'] ?></td>
          <td class="bx-num"><?= (int)$r['grenzwerte'] ?></td>
          <td class="bx-num"><a class="btn btn-primary btn-sm" href="?p=rohstoff&id=<?= (int)$r['id'] ?>&tab=spec">Prüfen &amp; freigeben</a></td>
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
