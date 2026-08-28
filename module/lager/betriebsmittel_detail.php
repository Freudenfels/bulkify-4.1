<?php
// Betriebsmittel anlegen & bearbeiten – Kartons, Verbrauchsgüter, Inventar, Maschinen, Sonstiges.
// Einfacher Bestand (keine Chargen). Elektrische Geräte: jährliche Prüfung (DGUV V3) mit Fälligkeit.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$KAT  = betriebsmittel_kategorien();
$id   = $_GET['id'] ?? 'neu';
$neu  = ($id === 'neu' || !is_numeric($id));
$katParam = $_GET['kat'] ?? '';
$fehler = '';

// „Prüfung heute erledigt" – setzt letzte Prüfung auf heute.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'pruef_erledigt' && is_numeric($id)) {
    q("UPDATE item SET letzte_pruefung=CURDATE(), elektrisch=1 WHERE id=?", [(int)$id]);
    log_aktivitaet('item', (int)$id, 'team', 'Geräteprüfung durchgeführt.', 'notiz');
    header('Location: ?p=betriebsmittel&id=' . $id . '&gespeichert=1'); exit;
}
// Löschen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'loeschen' && is_numeric($id)) {
    $k = scalar("SELECT kategorie FROM item WHERE id=?", [(int)$id]);
    q("DELETE FROM item WHERE id=? AND kategorie IN ('karton','verbrauch','inventar','maschine','sonstiges')", [(int)$id]);
    header('Location: ?p=lager&kat=' . urlencode((string)$k)); exit;
}
// Speichern
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'speichern') {
    $f   = fn($k) => trim($_POST[$k] ?? '');
    $kat = array_key_exists($f('kategorie'), $KAT) ? $f('kategorie') : 'sonstiges';
    if ($f('name') === '') {
        $fehler = 'Name ist ein Pflichtfeld.';
    } else {
        $elektrisch = isset($_POST['elektrisch']) ? 1 : 0;
        $intervall  = $f('pruef_intervall_monate') !== '' ? (int)$_POST['pruef_intervall_monate'] : ($elektrisch ? 12 : null);
        $letzte     = $f('letzte_pruefung') !== '' ? $f('letzte_pruefung') : null;
        $bestand    = $f('bestand_menge') !== '' ? (float)str_replace(',', '.', $_POST['bestand_menge']) : 0;
        $mindest    = $f('mindestbestand') !== '' ? (float)str_replace(',', '.', $_POST['mindestbestand']) : null;
        $ek         = $f('ek_preis') !== '' ? (float)str_replace(',', '.', $_POST['ek_preis']) : 0;
        $lief       = $f('haupt_lieferant_id') !== '' ? (int)$_POST['haupt_lieferant_id'] : null;
        $einheit    = $f('einheit') !== '' ? $f('einheit') : 'Stück';
        if ($neu) {
            $art = $f('artikelnummer') !== '' ? $f('artikelnummer') : naechste_nummer(item_prefix($kat));
            q("INSERT INTO item (artikelnummer,name,kategorie,einheit,ek_preis,preis_bezug,haupt_lieferant_id,
                                  bestand_menge,mindestbestand,elektrisch,pruef_intervall_monate,letzte_pruefung,notiz)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
              [$art, $f('name'), $kat, $einheit, $ek, 'Stück', $lief, $bestand, $mindest, $elektrisch, $intervall, $letzte, $f('notiz')]);
            $id = insert_id();
            log_aktivitaet('item', (int)$id, 'team', 'Betriebsmittel angelegt.', 'notiz');
        } else {
            q("UPDATE item SET name=?, kategorie=?, einheit=?, ek_preis=?, haupt_lieferant_id=?,
                               bestand_menge=?, mindestbestand=?, elektrisch=?, pruef_intervall_monate=?, letzte_pruefung=?, notiz=?
               WHERE id=?",
              [$f('name'), $kat, $einheit, $ek, $lief, $bestand, $mindest, $elektrisch, $intervall, $letzte, $f('notiz'), (int)$id]);
        }
        header('Location: ?p=betriebsmittel&id=' . $id . '&gespeichert=1'); exit;
    }
}

$it = $neu
    ? ['kategorie' => (array_key_exists($katParam, $KAT) ? $katParam : 'inventar'), 'elektrisch' => 0, 'pruef_intervall_monate' => 12]
    : one("SELECT * FROM item WHERE id=? AND kategorie IN ('karton','verbrauch','inventar','maschine','sonstiges')", [(int)$id]);
if (!$it) { $neu = true; $it = ['kategorie' => 'inventar', 'elektrisch' => 0, 'pruef_intervall_monate' => 12]; }
$v = fn($k) => h((string)($it[$k] ?? ''));
$elektrisch  = (int)($it['elektrisch'] ?? 0) === 1;
$lieferanten = all("SELECT id, firma FROM lieferanten ORDER BY firma");
$pruef = !$neu ? pruefung_status($it) : null;

render_header('lager', $neu ? 'Neues Betriebsmittel' : $it['name']);
bx_head($neu ? 'Neues Betriebsmittel' : $v('name'),
        $neu ? 'Anlegen' : trim(($v('artikelnummer') ? $v('artikelnummer') . ' · ' : '') . ($KAT[$it['kategorie']] ?? $it['kategorie'])),
        bx_btn('Zurück zum Warenlager', '?p=lager&kat=' . h($it['kategorie']), 'ghost'));

if (isset($_GET['gespeichert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b">' . h($fehler) . '</div>';

if (!$neu) {
    echo '<div class="bx-cards">';
    echo '<div class="bx-card"><div class="k">Kategorie</div><div class="v">' . h($KAT[$it['kategorie']] ?? $it['kategorie']) . '</div></div>';
    echo '<div class="bx-card"><div class="k">Bestand</div><div class="v">' . rtrim(rtrim(number_format((float)($it['bestand_menge'] ?? 0), 3, ',', '.'), '0'), ',') . ' ' . h($it['einheit'] ?: 'Stück') . '</div></div>';
    echo '<div class="bx-card"><div class="k">EK-Preis</div><div class="v">' . number_format((float)($it['ek_preis'] ?? 0), 2, ',', '.') . ' €</div></div>';
    if ($pruef) {
        $kind = $pruef['stufe'] === 'faellig' ? 'err' : ($pruef['stufe'] === 'bald' ? 'warn' : ($pruef['stufe'] === 'offen' ? 'warn' : 'ok'));
        $txt  = $pruef['datum'] ? date('d.m.Y', strtotime($pruef['datum'])) . ' (' . $pruef['label'] . ')' : $pruef['label'];
        echo '<div class="bx-card"><div class="k">Nächste Prüfung</div><div class="v">' . bx_badge($txt, $kind) . '</div></div>';
    }
    echo '</div>';
}
?>
<form method="post" class="bx-form">
  <input type="hidden" name="aktion" value="speichern">
  <div class="bx-panel"><div class="bx-grid">
    <div class="bx-field"><label>Artikelnummer <?= bx_hint('leer lassen = automatisch (je Kategorie, z. B. IN-…, KA-…)') ?></label><input type="text" name="artikelnummer" value="<?= $v('artikelnummer') ?>" placeholder="<?= $neu ? 'automatisch' : '' ?>"<?= $neu ? '' : ' readonly' ?>></div>
    <div class="bx-field"><label>Name</label><input type="text" name="name" value="<?= $v('name') ?>" required placeholder="z. B. Versandkarton 400×300×200"></div>
    <div class="bx-field"><label>Kategorie</label>
      <select name="kategorie">
        <?php foreach ($KAT as $k => $lbl): ?><option value="<?= $k ?>" <?= ($it['kategorie'] ?? '') === $k ? 'selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="bx-field"><label>Einheit</label><input type="text" name="einheit" value="<?= h((string)($it['einheit'] ?? 'Stück')) ?>" placeholder="Stück"></div>
    <div class="bx-field"><label>Bestand</label><input type="number" step="0.001" name="bestand_menge" value="<?= h($it['bestand_menge'] !== null && $it['bestand_menge'] !== '' ? rtrim(rtrim(number_format((float)($it['bestand_menge'] ?? 0), 3, '.', ''), '0'), '.') : '') ?>" placeholder="0"></div>
    <div class="bx-field"><label>Mindestbestand <?= bx_hint('optional – Meldebestand für die Nachbestellung') ?></label><input type="number" step="0.001" name="mindestbestand" value="<?= h(($it['mindestbestand'] ?? '') !== '' && ($it['mindestbestand'] ?? null) !== null ? rtrim(rtrim(number_format((float)$it['mindestbestand'], 3, '.', ''), '0'), '.') : '') ?>" placeholder="–"></div>
    <div class="bx-field"><label>Haupt-Lieferant</label>
      <select name="haupt_lieferant_id">
        <option value="">– keiner –</option>
        <?php foreach ($lieferanten as $lf): ?><option value="<?= $lf['id'] ?>" <?= (int)($it['haupt_lieferant_id'] ?? 0) === (int)$lf['id'] ? 'selected' : '' ?>><?= h($lf['firma']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="bx-field"><label>EK-Preis (€)</label><input type="number" step="0.0001" name="ek_preis" value="<?= h(($it['ek_preis'] ?? '') !== '' && ($it['ek_preis'] ?? null) !== null ? rtrim(rtrim(number_format((float)$it['ek_preis'], 4, '.', ''), '0'), '.') : '') ?>" placeholder="0,00"></div>
  </div>
  <div class="bx-field"><label>Notiz (intern)</label><textarea name="notiz"><?= $v('notiz') ?></textarea></div>
  </div>

  <div class="bx-panel">
    <div class="bx-check" style="margin-bottom:6px">
      <input type="checkbox" name="elektrisch" id="f_elektrisch" value="1" <?= $elektrisch ? 'checked' : '' ?>>
      <label for="f_elektrisch" style="margin:0">Elektronisches Gerät – jährliche Prüfung (DGUV V3)</label>
    </div>
    <div id="pruefBox" class="bx-grid" style="<?= $elektrisch ? '' : 'display:none' ?>">
      <div class="bx-field"><label>Prüf-Intervall (Monate)</label><input type="number" step="1" min="1" name="pruef_intervall_monate" value="<?= h((string)($it['pruef_intervall_monate'] ?? 12)) ?>" placeholder="12"></div>
      <div class="bx-field"><label>Letzte Prüfung</label><input type="date" name="letzte_pruefung" value="<?= $v('letzte_pruefung') ?>"></div>
    </div>
    <p class="muted" style="font-size:12px;margin:8px 0 0">Der nächste Prüftermin ergibt sich automatisch aus letzter Prüfung + Intervall. Überfällige/bald fällige Geräte werden im Warenlager markiert.</p>
  </div>

  <div class="bx-row" style="margin-top:var(--sp-4)">
    <button class="btn btn-primary" type="submit"><?= $neu ? 'Anlegen' : 'Speichern' ?></button>
    <a class="btn btn-ghost" href="?p=lager&kat=<?= h($it['kategorie']) ?>">Abbrechen</a>
  </div>
</form>

<?php if (!$neu && $elektrisch): ?>
<form method="post" style="margin-top:14px">
  <input type="hidden" name="aktion" value="pruef_erledigt">
  <button class="btn btn-ghost" type="submit">Prüfung heute erledigt</button>
  <span class="muted" style="font-size:12px;margin-left:8px">setzt die letzte Prüfung auf heute und rechnet den nächsten Termin neu.</span>
</form>
<?php endif; ?>

<?php if (!$neu): ?>
<form method="post" style="margin-top:20px" onsubmit="return confirm('Betriebsmittel wirklich löschen?');">
  <input type="hidden" name="aktion" value="loeschen">
  <button class="btn btn-ghost btn-sm" type="submit" style="color:#8f231b">Löschen</button>
</form>
<?php endif; ?>

<script>
(function(){
  var cb = document.getElementById('f_elektrisch'), box = document.getElementById('pruefBox');
  if (cb && box) cb.addEventListener('change', function(){ box.style.display = cb.checked ? '' : 'none'; });
})();
</script>
<?php render_footer();
