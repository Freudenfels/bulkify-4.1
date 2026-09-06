<?php
// Kunden des Dashboards - hier nur zum Nachschlagen und um einen Verlauf daran zu haengen.
// Route: ?p=kunden. Geaendert wird an den Stammdaten nichts; dafuer ist das Dashboard da.
require_once BX_ROOT . '/core/kontakt.php';

$suche = trim((string)($_GET['q'] ?? ''));
$liste = erp_kunden_suche($suche, 100);

kopf('Kunden', 'mehr');
seitenkopf('Kunden', 'Aus dem Dashboard – hier siehst du, was zuletzt besprochen wurde.');
?>
<form method="get" style="margin-bottom:14px">
  <input type="hidden" name="p" value="kunden">
  <input type="search" name="q" value="<?= h($suche) ?>" placeholder="Firma, Ansprechpartner, Kundennummer …">
</form>

<?php if (!$liste): ?>
  <div class="karte"><div class="crm-leer">
    <strong><?= $suche !== '' ? 'Nichts gefunden.' : 'Keine Kunden.' ?></strong>
    <?= $suche !== '' ? 'Andere Schreibweise versuchen.' : 'Im Dashboard sind noch keine angelegt.' ?>
  </div></div>
<?php else: ?>
  <div class="karte">
    <?php foreach ($liste as $k):
      $anz = (int) scalar("SELECT COUNT(*) FROM crm_verlauf WHERE kunde_id=?", [(int)$k['id']]); ?>
      <div class="crm-zeile">
        <div class="crm-alter ruhig"><?= h((string)($k['kundennummer'] ?? '')) ?></div>
        <div class="crm-mitte">
          <a class="titel" href="?p=kunde&id=<?= (int)$k['id'] ?>"><?= h((string)$k['firma']) ?></a>
          <span class="unter">
            <?= h(trim((string)($k['ansprechpartner'] ?? ''))) ?>
            <?= $anz > 0 ? ' · ' . $anz . ' Notiz' . ($anz === 1 ? '' : 'en') : '' ?>
          </span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php fuss('mehr');
