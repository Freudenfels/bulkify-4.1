<?php
// Die Startseite: "Wer wartet auf mich". Route: ?p=wartet
// Zwei Reiter, weil es zwei verschiedene Sorgen sind:
//   sie = die warten auf mich (meine Bringschuld)   |   wir = ich warte auf andere
require_once BX_ROOT . '/core/wartet.php';
require_once BX_ROOT . '/core/briefing_ki.php';

// --- Aktionen ----------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $typ   = preg_replace('/[^a-z_]/', '', (string)($_POST['typ'] ?? ''));
    $id    = (int)($_POST['id'] ?? 0);
    $stand = (string)($_POST['stand'] ?? '');
    $ziel  = '?p=wartet' . (($_POST['r'] ?? '') === 'wir' ? '&r=wir' : '');
    if ($typ !== '' && $id > 0) {
        if (($_POST['tun'] ?? '') === 'erledigt') {
            wartet_erledigen($typ, $id, $stand, crm_uid());
            header('Location: ' . $ziel . '&ok=erledigt'); exit;
        }
        if (($_POST['tun'] ?? '') === 'spaeter') {
            $tage = max(1, min(90, (int)($_POST['tage'] ?? 3)));
            wartet_spaeter($typ, $id, (string)($_POST['titel'] ?? 'Wiedervorlage'), $stand, $tage, crm_uid());
            header('Location: ' . $ziel . '&ok=spaeter'); exit;
        }
    }
    header('Location: ' . $ziel); exit;
}

$richtung = ($_GET['r'] ?? 'sie') === 'wir' ? 'wir' : 'sie';
$zeilen   = wartet_zeilen($richtung);
$andere   = count(wartet_zeilen($richtung === 'sie' ? 'wir' : 'sie'));
$dash     = erp_dashboard_url();

kopf('Wartet', 'wartet');
seitenkopf('Wer wartet auf mich', $richtung === 'sie'
    ? 'Sortiert nach Wartezeit – oben steht, was am längsten liegt.'
    : 'Hier liegt der Ball bei anderen – aber nachhaken musst du.');

if (isset($_GET['ok'])) hinweis($_GET['ok'] === 'spaeter' ? 'Auf Wiedervorlage gelegt.' : 'Erledigt.');
?>

<?php $briefing = ($richtung === 'sie' && $zeilen) ? briefing_text(isset($_GET['neu'])) : ''; ?>
<?php if ($briefing !== ''): ?>
  <div class="karte"><div class="rumpf">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:baseline">
      <strong>Heute</strong>
      <a class="leise" style="font-size:13px" href="?p=wartet&neu=1">neu schreiben</a>
    </div>
    <p style="margin:8px 0 0"><?= nl2br(h($briefing)) ?></p>
  </div></div>
<?php endif; ?>

<div class="reiter">
  <a href="?p=wartet"<?= $richtung === 'sie' ? ' class="an"' : '' ?>>Die warten auf mich<?= $richtung === 'sie' ? ' · ' . count($zeilen) : ' · ' . $andere ?></a>
  <a href="?p=wartet&r=wir"<?= $richtung === 'wir' ? ' class="an"' : '' ?>>Ich warte auf andere<?= $richtung === 'wir' ? ' · ' . count($zeilen) : ' · ' . $andere ?></a>
</div>

<?php if (!$zeilen): ?>
  <div class="karte"><div class="leer">
    <strong>Nichts offen.</strong>
    <?= $richtung === 'sie' ? 'Niemand wartet gerade auf dich.' : 'Du wartest gerade auf niemanden.' ?>
  </div></div>
<?php else: ?>
  <div class="karte">
    <?php foreach ($zeilen as $z):
      // Links zeigen ins Dashboard - solange dessen Adresse nicht hinterlegt ist, kein Link.
      $ziel = $z['link'] !== '' && str_starts_with($z['link'], '?p=')
            ? ($dash !== '' ? $dash . '/' . $z['link'] : '')
            : $z['link'];
      if (in_array($z['typ'], ['kontakt', 'wiedervorlage', 'termin'], true)) $ziel = $z['link'];
    ?>
      <div class="zeile">
        <div class="alter <?= h($z['stufe']) ?>">
          <?= h(warte_text((int)$z['tage'])) ?>
          <span class="art"><?= h(zeilen_art($z['typ'])) ?></span>
        </div>
        <div class="mitte">
          <?php if ($ziel !== ''): ?>
            <a class="titel" href="<?= h($ziel) ?>"<?= str_starts_with($ziel, 'http') ? ' target="_blank" rel="noopener"' : '' ?>><?= h($z['titel']) ?></a>
          <?php else: ?>
            <span class="titel"><?= h($z['titel']) ?></span>
          <?php endif; ?>
          <span class="unter"><?= h($z['unter']) ?><?= $z['betrag'] !== null ? ' · ' . eur((float)$z['betrag']) : '' ?></span>

          <div class="tuen">
            <form method="post" style="margin:0">
              <input type="hidden" name="tun" value="spaeter"><input type="hidden" name="typ" value="<?= h($z['typ']) ?>">
              <input type="hidden" name="id" value="<?= (int)$z['id'] ?>"><input type="hidden" name="stand" value="<?= h((string)$z['seit']) ?>">
              <input type="hidden" name="titel" value="<?= h($z['titel']) ?>"><input type="hidden" name="tage" value="3">
              <input type="hidden" name="r" value="<?= h($richtung) ?>">
              <button class="btn klein" type="submit">in 3 Tagen</button>
            </form>
            <form method="post" style="margin:0">
              <input type="hidden" name="tun" value="erledigt"><input type="hidden" name="typ" value="<?= h($z['typ']) ?>">
              <input type="hidden" name="id" value="<?= (int)$z['id'] ?>"><input type="hidden" name="stand" value="<?= h((string)$z['seit']) ?>">
              <input type="hidden" name="r" value="<?= h($richtung) ?>">
              <button class="btn klein leise" type="submit">erledigt</button>
            </form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($dash === ''): ?>
    <p class="leise">Die Vorgänge liegen im Dashboard. Hinterlege dessen Adresse unter <a href="?p=mehr">Mehr</a>,
       dann führt jede Zeile direkt dorthin.</p>
  <?php endif; ?>
<?php endif; ?>

<?php fuss('wartet');
