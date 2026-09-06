<?php
// Alle Kontakte. Route: ?p=kontakte
require_once BX_ROOT . '/core/kontakt.php';

$suche  = trim((string)($_GET['q'] ?? ''));
$archiv = ($_GET['a'] ?? '') === '1';
$liste  = kontakt_liste($suche, $archiv);

kopf('Kontakte', 'kontakte');
seitenkopf('Kontakte', $archiv ? 'Archiv' : 'Leute, die noch kein Kundenkonto haben',
    '<a class="btn stark" href="?p=erfassen">Neu</a>');
?>
<form method="get" style="margin-bottom:14px">
  <input type="hidden" name="p" value="kontakte">
  <?php if ($archiv): ?><input type="hidden" name="a" value="1"><?php endif; ?>
  <input type="search" name="q" value="<?= h($suche) ?>" placeholder="Name, Firma, Telefon, Notiz …">
</form>

<div class="reiter">
  <a href="?p=kontakte"<?= !$archiv ? ' class="an"' : '' ?>>Aktiv</a>
  <a href="?p=kontakte&a=1"<?= $archiv ? ' class="an"' : '' ?>>Archiv</a>
</div>

<?php if (!$liste): ?>
  <div class="karte"><div class="leer">
    <strong><?= $suche !== '' ? 'Nichts gefunden.' : 'Noch keine Kontakte.' ?></strong>
    <?= $suche !== '' ? 'Andere Schreibweise versuchen.' : 'Über „Erfassen“ legst du den ersten an.' ?>
  </div></div>
<?php else: ?>
  <div class="karte">
    <?php foreach ($liste as $k):
      $tage = tage_seit((string)$k['angelegt']); ?>
      <div class="zeile">
        <div class="alter <?= h(warte_stufe($tage)) ?>">
          <?= h(warte_text($tage)) ?>
          <span class="art"><?= h(crm_quellen()[$k['quelle']] ?? '') ?></span>
        </div>
        <div class="mitte">
          <a class="titel" href="?p=kontakt&id=<?= (int)$k['id'] ?>">
            <?= h(trim(((string)($k['firma'] ?? '') !== '' ? $k['firma'] . ' – ' : '') . $k['name'])) ?>
          </a>
          <span class="unter">
            <?= h(crm_phasen()[$k['phase']] ?? (string)$k['phase']) ?>
            <?= $k['wert_eur'] !== null ? ' · ' . eur((float)$k['wert_eur']) : '' ?>
            <?= $k['kunde_id'] ? ' · ist Kunde' : '' ?>
            <?= trim((string)($k['notiz'] ?? '')) !== '' ? ' · ' . h(mb_substr(trim((string)$k['notiz']), 0, 70)) : '' ?>
          </span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php fuss('kontakte');
