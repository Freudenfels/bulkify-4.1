<?php
// Proben ans Labor senden (Nico 05.11.2026): Labor + Standort wählen, Aufträge (Proben) wählen, Probenzeilen prüfen,
// dann füllt das System das Auftragsformular des Labors (PDF) – zum Ausdrucken und in den Karton zur Probe.
// Auftraggeber = Kunde, Rechnung/Einsender = bulkify. Ablage je Auftrag als internes Dokument, Status „Probe beim Labor“.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/labor_auftrag.php';

$labore = labor_lieferanten();
$wer = (string)(current_user()['name'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'erzeugen') {
    $lid = (int)($_POST['lieferant_id'] ?? 0);
    $proben = [];
    foreach ((array)($_POST['aid'] ?? []) as $i => $aid) {
        $aid = (int)$aid; if ($aid <= 0) continue;
        $kid = (int) scalar("SELECT kunde_id FROM auftrag WHERE id=?", [$aid]);
        $proben[] = ['auftrag_id' => $aid, 'kunde_id' => $kid,
                     'material' => trim((string)($_POST['material'][$i] ?? '')), 'rueckverfolgbarkeit' => trim((string)($_POST['rv'][$i] ?? '')),
                     'umfang' => [trim((string)($_POST['u1'][$i] ?? '')), trim((string)($_POST['u2'][$i] ?? '')), trim((string)($_POST['u3'][$i] ?? ''))],
                     'beurteilung' => !empty($_POST['beurteilung'][$i]), 'express' => !empty($_POST['express'][$i])];
    }
    $r = labor_versand_anlegen($lid, trim((string)($_POST['standort'] ?? '')), $proben, trim((string)($_POST['bemerkung'] ?? '')), $wer);
    if (empty($r['ok'])) { header('Location: ?p=labor_senden&lieferant_id=' . $lid . '&fehler=' . urlencode($r['fehler'])); exit; }
    header('Location: ?p=labor_senden&fertig=' . implode(',', array_column($r['formulare'], 'versand_id'))); exit;
}

render_header('labor_senden', 'Ans Labor senden');
bx_head('Ans Labor senden', 'Auftragsformular des Labors automatisch ausfüllen: Kunde als Auftraggeber, bulkify als Rechnungsempfänger und Einsender, Produkt/Charge/MHD in der Probentabelle. Ausdrucken und zur Probe in den Karton legen.',
        bx_btn('Zu den Laborberichten', '?p=laboranalysen'));
if (!empty($_GET['fehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:10px 14px">' . h((string)$_GET['fehler']) . '</div>';

// --- Ergebnis --------------------------------------------------------------------------------------------------
if (!empty($_GET['fertig'])):
    $ids = array_filter(array_map('intval', explode(',', (string)$_GET['fertig'])));
    $rows = $ids ? all("SELECT v.*, l.firma AS labor, k.firma AS kunde FROM labor_versand v LEFT JOIN lieferanten l ON l.id=v.lieferant_id LEFT JOIN kunden k ON k.id=v.kunde_id WHERE v.id IN (" . implode(',', $ids) . ") ORDER BY v.id") : []; ?>
  <div class="bx-panel badge-ok" style="padding:12px 16px">
    <strong><?= count($rows) ?> Auftragsformular<?= count($rows) === 1 ? '' : 'e' ?> erstellt.</strong> Ausdrucken und zur Probe in den Karton legen. Die Aufträge stehen jetzt auf „Probe beim Labor“.
  </div>
  <div class="bx-panel">
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Labor</th><th>Auftraggeber (Kunde)</th><th class="bx-num">Proben</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $v): $dok = (int) scalar("SELECT id FROM dokument WHERE typ='labor_auftrag' AND datei=? LIMIT 1", [(string)$v['datei']]); ?>
        <tr><td><?= h((string)$v['labor']) ?><?= $v['standort'] ? ' · ' . h((string)$v['standort']) : '' ?></td><td><?= kunde_link($v['kunde_id'], $v['kunde']) ?></td>
            <td class="bx-num"><?= count(json_decode((string)$v['proben'], true) ?: []) ?></td>
            <td style="text-align:right"><?php if ($dok): ?><a class="btn btn-primary btn-sm" href="?p=dokument&id=<?= $dok ?>" target="_blank">PDF öffnen / drucken</a><?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div style="margin-top:12px"><?= bx_btn('Weitere Proben senden', '?p=labor_senden') ?></div>
  </div>
<?php render_footer(); return; endif;

if (!$labore): ?>
  <div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">
    Noch kein Labor mit Auftragsformular angelegt. Unter Lieferanten das Labor (z. B. GBA) öffnen, Kategorie „Labor“ anhaken und im Block „Labor“ die Formular-Vorlage, Standorte und Angebotsnummer eintragen.
  </div>
<?php render_footer(); return; endif;

$lid = (int)($_GET['lieferant_id'] ?? 0) ?: (count($labore) === 1 ? (int)$labore[0]['id'] : 0);
$labor = null; foreach ($labore as $l) if ((int)$l['id'] === $lid) $labor = $l;
$orte = $labor ? labor_standorte($labor) : [];
$standort = trim((string)($_GET['standort'] ?? '')) ?: (count($orte) === 1 ? $orte[0] : '');
$gewaehlt = array_values(array_unique(array_filter(array_map('intval', array_merge((array)($_GET['a'] ?? []), [(int)($_GET['auftrag'] ?? 0), (int)($_GET['extra'] ?? 0)])))));
$schritt2 = isset($_GET['weiter']) && $labor && $gewaehlt && (!$orte || in_array($standort, $orte, true));
$kand = labor_proben_kandidaten();
$kandIds = array_map(fn($r) => (int)$r['id'], $kand);

if (!$schritt2): ?>
<form method="get" class="bx-panel">
  <input type="hidden" name="p" value="labor_senden">
  <h2 style="margin-top:0;font-size:16px">1. Labor und Proben wählen</h2>
  <div class="bx-grid" style="max-width:720px">
    <div class="bx-field"><label>Labor</label>
      <select name="lieferant_id" onchange="this.form.submit()"><option value="">– Labor wählen –</option>
        <?php foreach ($labore as $l): ?><option value="<?= (int)$l['id'] ?>" <?= (int)$l['id'] === $lid ? 'selected' : '' ?>><?= h((string)$l['firma']) ?> · <?= h(labor_formulare()[(string)$l['labor_formular']]['titel']) ?></option><?php endforeach; ?>
      </select></div>
    <?php if ($orte): ?>
    <div class="bx-field"><label>Standort</label>
      <select name="standort"><option value="">– wählen –</option><?php foreach ($orte as $o): ?><option value="<?= h($o) ?>" <?= $o === $standort ? 'selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
  </div>
  <?php if (isset($_GET['weiter']) && $orte && !in_array($standort, $orte, true)): ?><div style="color:#8f231b;margin-bottom:10px">Bitte den Standort wählen.</div><?php endif; ?>
  <?php if (isset($_GET['weiter']) && !$gewaehlt): ?><div style="color:#8f231b;margin-bottom:10px">Bitte mindestens eine Probe (Auftrag) wählen.</div><?php endif; ?>
  <div class="muted" style="font-size:13px;margin:4px 0 8px">Aufträge mit Labortest (Kunde will Drittlabor oder Laboranalyse dazugebucht), für die noch kein Laborbericht vorliegt. Je Formular passen <?= $labor ? (int)labor_formulare()[(string)$labor['labor_formular']]['max_proben'] : 5 ?> Proben; je Kunde entsteht ein eigenes Formular.</div>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th style="width:36px"></th><th>Auftrag</th><th>Kunde</th><th>Produkt</th><th>Labor</th></tr></thead>
    <tbody>
    <?php if (!$kand): ?><tr><td colspan="5" class="muted">Keine offenen Aufträge mit Labortest. Eine andere Probe unten über „Weiterer Auftrag“ hinzufügen.</td></tr><?php endif; ?>
    <?php foreach ($kand as $r): ?>
      <tr><td><input type="checkbox" name="a[]" value="<?= (int)$r['id'] ?>" <?= in_array((int)$r['id'], $gewaehlt, true) ? 'checked' : '' ?>></td>
          <td><a href="?p=auftrag&id=<?= (int)$r['id'] ?>"><?= h((string)$r['nummer']) ?></a></td><td><?= kunde_link($r['kunde_id'], $r['firma']) ?></td><td style="white-space:normal"><?= h((string)$r['produkt']) ?></td>
          <td><?= $r['labor_versendet_am'] ? bx_badge('Probe beim Labor seit ' . date('d.m.Y', strtotime((string)$r['labor_versendet_am'])), 'warn') : '<span class="muted">noch nicht gesendet</span>' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php $weitere = all("SELECT a.id, a.nummer, k.firma, COALESCE(NULLIF(p.kundenname,''), NULLIF(a.produkt_bezeichnung,''), p.name) AS produkt
                         FROM auftrag a JOIN kunden k ON k.id=a.kunde_id LEFT JOIN produkt p ON p.id=a.produkt_id
                         WHERE a.status<>'storniert' ORDER BY a.id DESC LIMIT 400"); ?>
  <div class="bx-field" style="max-width:720px;margin-top:12px"><label>Weiterer Auftrag (optional)</label>
    <select name="extra" class="rscombo"><option value="">– keiner –</option>
      <?php foreach ($weitere as $w): if (in_array((int)$w['id'], $kandIds, true)) continue; ?><option value="<?= (int)$w['id'] ?>" <?= in_array((int)$w['id'], $gewaehlt, true) ? 'selected' : '' ?>><?= h($w['nummer'] . ' · ' . $w['firma'] . ' · ' . $w['produkt']) ?></option><?php endforeach; ?>
    </select></div>
  <button class="btn btn-primary" type="submit" name="weiter" value="1">Weiter: Proben prüfen</button>
</form>
<?php else:
    $f = labor_formulare()[(string)$labor['labor_formular']];
    $proben = []; foreach ($gewaehlt as $aid) { $pv = labor_probe_vorschlag($aid, (string)($labor['labor_umfang'] ?? '')); if ($pv) $proben[] = $pv; }
    $jeKunde = []; foreach ($proben as $p) $jeKunde[$p['kunde_id']][] = $p;
    $anzForm = 0; foreach ($jeKunde as $l) $anzForm += (int) ceil(count($l) / $f['max_proben']); ?>
<form method="post" class="bx-panel" data-busy="Erzeuge Auftragsformular …">
  <input type="hidden" name="aktion" value="erzeugen"><input type="hidden" name="lieferant_id" value="<?= (int)$labor['id'] ?>"><input type="hidden" name="standort" value="<?= h($standort) ?>">
  <h2 style="margin-top:0;font-size:16px">2. Proben prüfen · <?= h((string)$labor['firma']) ?><?= $standort !== '' ? ' ' . h($standort) : '' ?></h2>
  <div class="muted" style="font-size:13px;margin-bottom:10px">Rechnungsempfänger und Einsender: <?= h(labor_block_bulkify()[0]) ?> · Berichtsempfänger: <?= ((string)($labor['labor_bericht_an'] ?? 'bulkify')) === 'kunde' ? 'der Kunde' : h(labor_block_bulkify()[0]) ?> · Erste Proben: <?= (int)($labor['labor_erste_proben'] ?? 0) ? 'ja' : 'nein' ?> · Datum: heute
    · <?= $anzForm ?> Formular<?= $anzForm === 1 ? '' : 'e' ?></div>
  <?php foreach ($jeKunde as $kid => $liste): $kb = labor_block_kunde((int)$kid); ?>
    <div style="margin:14px 0 6px"><strong>Auftraggeber: <?= h($kb['firma'] ?: '–') ?></strong>
      <?= count($liste) > $f['max_proben'] ? ' <span class="muted">· ' . count($liste) . ' Proben → ' . (int)ceil(count($liste) / $f['max_proben']) . ' Formulare</span>' : '' ?></div>
    <?php if ($kb['fehlt']): ?><div class="bx-panel" style="border-color:#e6c4c0;background:#fbeae7;color:#8f231b;padding:10px 14px;margin:0 0 8px">
      Beim Kunden fehlt: <strong><?= h(implode(', ', $kb['fehlt'])) ?></strong>. Das Labor verlangt die USt-IdNr. des Auftraggebers – bitte am <a href="?p=kunde&id=<?= (int)$kid ?>" target="_blank">Kunden</a> nachtragen und diese Seite neu laden.</div><?php endif; ?>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Auftrag</th><th>Material / Produkt</th><th>Produktbezeichnung / Rückverfolgbarkeit</th><th>Untersuchungsumfang</th><th>Beurteilung</th><th>Express</th></tr></thead>
      <tbody>
      <?php foreach ($liste as $p): $i = $p['auftrag_id']; ?>
        <tr style="vertical-align:top"><td><input type="hidden" name="aid[<?= $i ?>]" value="<?= $i ?>"><?= h($p['nummer']) ?></td>
          <td style="white-space:normal;min-width:200px"><textarea name="material[<?= $i ?>]" rows="3" style="min-height:0"><?= h($p['material']) ?></textarea></td>
          <td style="white-space:normal;min-width:200px"><textarea name="rv[<?= $i ?>]" rows="3" style="min-height:0"><?= h($p['rueckverfolgbarkeit']) ?></textarea></td>
          <td style="white-space:normal;min-width:240px"><?php foreach ([1, 2, 3] as $j): ?><input type="text" name="u<?= $j ?>[<?= $i ?>]" value="<?= h((string)($p['umfang'][$j - 1] ?? '')) ?>" placeholder="Zeile <?= $j ?>" style="margin-bottom:4px"><?php endforeach; ?></td>
          <td><input type="checkbox" name="beurteilung[<?= $i ?>]" value="1" style="width:20px;height:20px"></td>
          <td><input type="checkbox" name="express[<?= $i ?>]" value="1" style="width:20px;height:20px"></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endforeach; ?>
  <div class="bx-field" style="margin-top:14px"><label>Bemerkung zum Auftrag (optional)</label><textarea name="bemerkung" rows="2" style="min-height:0"></textarea></div>
  <div class="bx-row" style="gap:10px">
    <button class="btn btn-primary" type="submit">Auftragsformular erzeugen</button>
    <a class="btn btn-ghost" href="?p=labor_senden&lieferant_id=<?= (int)$labor['id'] ?>&standort=<?= urlencode($standort) ?><?php foreach ($gewaehlt as $g) echo '&a[]=' . $g; ?>">Zurück</a>
  </div>
</form>
<?php endif;

$hist = labor_versand_liste(15);
if ($hist): ?>
<div class="bx-panel">
  <h2 style="margin-top:0;font-size:16px">Zuletzt erstellte Laboraufträge</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Datum</th><th>Labor</th><th>Auftraggeber</th><th>Proben</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($hist as $v): $pr = json_decode((string)$v['proben'], true) ?: []; $dok = (int) scalar("SELECT id FROM dokument WHERE typ='labor_auftrag' AND datei=? LIMIT 1", [(string)$v['datei']]); ?>
      <tr><td><?= h(date('d.m.Y', strtotime((string)$v['datum']))) ?></td><td><?= h((string)$v['labor']) ?><?= $v['standort'] ? ' · ' . h((string)$v['standort']) : '' ?></td><td><?= kunde_link($v['kunde_id'], $v['kunde']) ?></td>
          <td style="white-space:normal"><?= h(implode(', ', array_map(fn($x) => (string)($x['material'] ?? ''), $pr))) ?></td>
          <td style="text-align:right"><?php if ($dok): ?><a href="?p=dokument&id=<?= $dok ?>" target="_blank">PDF</a><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif;
render_footer();
