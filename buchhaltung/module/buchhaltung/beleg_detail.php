<?php
// Beleg-Detail: die KI-ausgelesenen Felder prüfen/korrigieren und erfassen; Datei ansehen; neu auslesen;
// verwerfen. Route: beleg_detail. ?id=<bu_beleg_eingang>
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/belegeingang.php';
be_init();

$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    $a = $_POST['aktion'] ?? '';
    if ($a === 'speichern') {
        be_update($id, [
            'belegart'       => $_POST['belegart'] ?? 'sonstiges',
            'lieferant_name' => $_POST['lieferant_name'] ?? '',
            'beleg_nummer'   => $_POST['beleg_nummer'] ?? '',
            'datum'          => $_POST['datum'] ?? null,
            'netto'          => str_replace(',', '.', (string)($_POST['netto'] ?? '0')),
            'ust_prozent'    => str_replace(',', '.', (string)($_POST['ust_prozent'] ?? '0')),
            'waehrung'       => $_POST['waehrung'] ?? 'EUR',
            'kategorie'      => $_POST['kategorie'] ?? '',
            'notiz'          => $_POST['notiz'] ?? '',
            'status'         => 'erfasst',
        ]);
        header('Location: ?p=beleg_detail&id=' . $id . '&ok=1'); exit;
    }
    if ($a === 'verwerfen') { be_status_setzen($id, 'verworfen'); header('Location: ?p=beleg_eingang&verworfen=1'); exit; }
    if ($a === 'ki_neu') {
        $r = be_get($id);
        if ($r && !empty($r['datei'])) {
            $ki = be_ki_auslesen(be_pfad($r['datei']));
            if ($ki['ok']) {
                $d = $ki['daten']; $d['status'] = $r['status'];
                be_update($id, $d);
                q("UPDATE bu_beleg_eingang SET ki_json=?, ki_ok=1 WHERE id=?", [$ki['roh'], $id]);
            }
            header('Location: ?p=beleg_detail&id=' . $id . ($ki['ok'] ? '&kineu=1' : '&kifehler=1')); exit;
        }
    }
}

$r = $id ? be_get($id) : null;
if (!$r) { render_header('beleg_eingang', 'Beleg'); bx_head('Beleg nicht gefunden', '', bx_btn('Zurück', '?p=beleg_eingang', 'ghost')); render_footer(); exit; }

$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
$ustDefault = (float) meta_get('ust_inland', 19);
$waehrungen = ['EUR' => '€', 'USD' => '$', 'CNY' => '¥', 'GBP' => '£', 'CHF' => 'CHF'];
$arten = ['eingangsrechnung' => 'Eingangsrechnung', 'quittung' => 'Quittung', 'sonstiges' => 'Sonstiges'];
$kats = ['Wareneinkauf','Bürobedarf','Reisekosten','Bewirtung','Kfz','Software','Gebühren','Telekommunikation','Miete','Verpackung','Marketing','Sonstiges'];

render_header('beleg_eingang', 'Beleg ' . ($r['lieferant_name'] ?: $r['id']));
$badge = match ($r['status']) { 'neu'=>bx_badge('neu','warn'),'erfasst'=>bx_badge('erfasst','info'),'verbucht'=>bx_badge('verbucht','ok'),'verworfen'=>bx_badge('verworfen','err'),default=>bx_badge($r['status']) };
$aktionen = ($r['datei'] ? bx_btn('Beleg ansehen', '?p=beleg_datei&id=' . $id, 'ghost') . ' ' : '') . bx_btn('Zurück', '?p=beleg_eingang', 'ghost');
bx_head(trim(($r['lieferant_name'] ?: 'Beleg') . ' · ' . $badge), 'Quelle: ' . (['upload'=>'Upload','foto'=>'Handy-Foto','mail'=>'E-Mail'][$r['quelle']] ?? $r['quelle']) . ' · hochgeladen ' . fmt_zeit($r['angelegt']), $aktionen);
foreach (['ok'=>'Beleg erfasst.','kineu'=>'Neu per KI ausgelesen.','kifehler'=>'KI-Auslesen nicht möglich.'] as $k=>$m) if (isset($_GET[$k])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h($m) . '</div>';
if (empty($r['ki_ok']) && $r['status'] === 'neu') echo '<div class="bx-panel" style="padding:12px 16px">Dieser Beleg wurde noch nicht erfolgreich von der KI gelesen – bitte Felder prüfen/ergänzen.</div>';
?>
<div class="bx-cards" style="align-items:flex-start">
  <div class="bx-panel" style="flex:1;min-width:360px">
    <h2 style="margin-top:0">Belegdaten</h2>
    <form method="post" class="bx-form">
      <input type="hidden" name="aktion" value="speichern">
      <div class="bx-row">
        <label>Belegart<select name="belegart"><?php foreach ($arten as $k=>$v): ?><option value="<?= $k ?>" <?= $r['belegart']===$k?'selected':'' ?>><?= h($v) ?></option><?php endforeach; ?></select></label>
        <label>Datum<input type="date" name="datum" value="<?= h($r['datum'] ?? '') ?>"></label>
      </div>
      <div class="bx-row">
        <label>Lieferant/Aussteller<input type="text" name="lieferant_name" value="<?= h($r['lieferant_name'] ?? '') ?>"></label>
        <label>Belegnummer<input type="text" name="beleg_nummer" value="<?= h($r['beleg_nummer'] ?? '') ?>"></label>
      </div>
      <div class="bx-row">
        <label>Kategorie
          <input type="text" name="kategorie" list="katliste" value="<?= h($r['kategorie'] ?? '') ?>">
          <datalist id="katliste"><?php foreach ($kats as $k): ?><option value="<?= h($k) ?>"><?php endforeach; ?></datalist>
        </label>
        <label>Währung<select name="waehrung"><?php foreach ($waehrungen as $k=>$s): ?><option value="<?= $k ?>" <?= strtoupper((string)$r['waehrung'])===$k?'selected':'' ?>><?= h($k) ?></option><?php endforeach; ?></select></label>
      </div>
      <div class="bx-row">
        <label>Netto<input type="text" inputmode="decimal" name="netto" value="<?= h(number_format((float)$r['netto'],2,',','')) ?>"></label>
        <label>USt %<input type="text" inputmode="decimal" name="ust_prozent" value="<?= h(number_format((float)$r['ust_prozent'],0)) ?>"></label>
        <label>Brutto (berechnet)<input type="text" value="<?= h($eur($r['brutto'])) ?>" disabled></label>
      </div>
      <label>Notiz<textarea name="notiz" rows="2"><?= h($r['notiz'] ?? '') ?></textarea></label>
      <p class="muted" style="margin:4px 0 0">USt-Betrag und Brutto werden aus Netto × USt berechnet. „Erfassen" nimmt den Beleg ins Steuerberater-Paket auf.</p>
      <div class="bx-row" style="margin-top:var(--sp-4)">
        <button class="btn btn-primary" type="submit">Erfassen</button>
      </div>
    </form>
    <div class="bx-row" style="margin-top:12px">
      <?php if ($r['datei']): ?>
      <form method="post" style="display:inline">
        <input type="hidden" name="aktion" value="ki_neu">
        <button class="btn btn-ghost btn-sm" type="submit" data-busy="KI liest …">Neu per KI auslesen</button>
      </form>
      <?php endif; ?>
      <form method="post" style="display:inline" onsubmit="return confirm('Beleg verwerfen? Er zählt dann nicht ins Steuerberater-Paket.');">
        <input type="hidden" name="aktion" value="verwerfen">
        <button class="btn btn-danger btn-sm" type="submit">Verwerfen</button>
      </form>
    </div>
  </div>
  <div class="bx-panel" style="flex:1;min-width:320px">
    <h2 style="margin-top:0">Beleg-Datei</h2>
    <?php if ($r['datei']): $ext = strtolower(pathinfo($r['datei'], PATHINFO_EXTENSION)); ?>
      <p class="muted" style="margin-top:0"><?= h($r['orig_name'] ?: basename($r['datei'])) ?></p>
      <?php if (in_array($ext, ['jpg','jpeg','png','webp','gif'], true)): ?>
        <a href="?p=beleg_datei&id=<?= $id ?>" target="_blank"><img src="?p=beleg_datei&id=<?= $id ?>" alt="Beleg" style="max-width:100%;border:1px solid var(--line);border-radius:var(--r-sm)"></a>
      <?php else: ?>
        <?= pdf_btn('?p=beleg_datei&id=' . $id, 'Beleg öffnen (' . strtoupper($ext) . ')') ?>
      <?php endif; ?>
    <?php else: ?>
      <p class="muted">Keine Datei hinterlegt.</p>
    <?php endif; ?>
  </div>
</div>
<?php
render_footer();
