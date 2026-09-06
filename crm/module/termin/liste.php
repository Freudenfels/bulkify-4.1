<?php
// Termine: Rückruf, Messe, Besuch. Route: ?p=termine
// Bewusst schlicht - ein Termin ist hier nur „ich muss am Tag X um Y an Z denken". Wer einen
// echten Kalender braucht, nutzt seinen Kalender; hier geht es um den Bezug zum Kontakt.
require_once BX_ROOT . '/core/termin.php';
require_once BX_ROOT . '/core/kontakt.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tun = (string)($_POST['tun'] ?? '');
    if ($tun === 'neu') {
        $id = termin_anlegen($_POST, crm_uid());
        header('Location: ?p=termine' . ($id ? '&ok=1' : '&fehler=1')); exit;
    }
    if ($tun === 'erledigt') {
        termin_erledigen((int)($_POST['id'] ?? 0));
        header('Location: ?p=termine&ok=2'); exit;
    }
    header('Location: ?p=termine'); exit;
}

$offen  = termin_liste(false);
$fertig = termin_liste(true);

kopf('Termine', 'mehr');
seitenkopf('Termine', 'Was ansteht – und woran es hängt.');
if (isset($_GET['ok']))     hinweis($_GET['ok'] === '2' ? 'Erledigt.' : 'Termin eingetragen.');
if (isset($_GET['fehler'])) hinweis('Titel und Zeitpunkt sind Pflicht.', 'warn');
?>

<div class="karte"><div class="rumpf">
  <h2 style="margin-top:0">Neuer Termin</h2>
  <form method="post">
    <input type="hidden" name="tun" value="neu">
    <div class="feld">
      <label for="titel">Worum geht es</label>
      <input type="text" id="titel" name="titel" required placeholder="z. B. Rückruf Herr Frei">
    </div>
    <div class="zweispaltig">
      <div class="feld">
        <label for="start">Wann</label>
        <input type="datetime-local" id="start" name="start" required value="<?= h(termin_vorschlag()) ?>">
      </div>
      <div class="feld">
        <label for="ort">Wo (optional)</label>
        <input type="text" id="ort" name="ort" placeholder="z. B. Messe Köln, Halle 7">
      </div>
    </div>
    <div class="feld">
      <label for="kontakt_id">Gehört zu (optional)</label>
      <select id="kontakt_id" name="kontakt_id">
        <option value="">– kein Bezug –</option>
        <?php foreach (kontakt_liste() as $k): ?>
          <option value="<?= (int)$k['id'] ?>"><?= h(trim(((string)($k['firma'] ?? '') !== '' ? $k['firma'] . ' – ' : '') . $k['name'])) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn stark" type="submit">Eintragen</button>
  </form>
</div></div>

<?php if ($offen): ?>
<div class="karte">
  <div class="rumpf" style="padding-bottom:0"><h2 style="margin-top:0">Steht an</h2></div>
  <?php foreach ($offen as $t): ?>
    <div class="zeile">
      <div class="alter <?= h(termin_stufe((string)$t['start_at'])) ?>">
        <?= h(fmt_zeit((string)$t['start_at'], 'd.m.')) ?>
        <span class="art"><?= h(fmt_zeit((string)$t['start_at'], 'H:i')) ?></span>
      </div>
      <div class="mitte">
        <?php if ($t['bezug_typ'] === 'kontakt' && $t['bezug_id']): ?>
          <a class="titel" href="?p=kontakt&id=<?= (int)$t['bezug_id'] ?>"><?= h((string)$t['titel']) ?></a>
        <?php else: ?>
          <span class="titel"><?= h((string)$t['titel']) ?></span>
        <?php endif; ?>
        <span class="unter"><?= h(trim(((string)($t['ort'] ?? '') !== '' ? $t['ort'] . ' · ' : '') . ($t['kontakt_name'] ?? ''), ' ·')) ?></span>
        <div class="tuen">
          <form method="post" style="margin:0">
            <input type="hidden" name="tun" value="erledigt"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <button class="btn klein leise" type="submit">erledigt</button>
          </form>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
  <div class="karte"><div class="leer"><strong>Keine Termine.</strong>Nichts steht an.</div></div>
<?php endif; ?>

<?php if ($fertig): ?>
<div class="karte">
  <div class="rumpf" style="padding-bottom:0"><h2 style="margin-top:0">Erledigt</h2></div>
  <?php foreach (array_slice($fertig, 0, 15) as $t): ?>
    <div class="zeile">
      <div class="alter ruhig"><?= h(fmt_zeit((string)$t['start_at'], 'd.m.')) ?></div>
      <div class="mitte"><span class="titel" style="font-weight:400"><?= h((string)$t['titel']) ?></span></div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php fuss('mehr');
