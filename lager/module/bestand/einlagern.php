<?php
// Einlagern (Route ?p=einlagern): offene Übergaben der Produktion ans Lager. Ein Klick bucht die
// Fertigware ins richtige Lager (L1/L2) und schließt die Aufgabe. Die eigentliche Buchung läuft über
// den kanonischen Dashboard-Endpunkt (erp_einlager_buchen -> ?p=api_einlager), nicht im Lager nachgebaut.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'buchen') {
    $pa = (int)($_POST['pa_id'] ?? 0);
    $r = erp_einlager_buchen($pa);
    flash((string)($r['meldung'] ?? ($r['ok'] ? 'Eingelagert.' : 'Einlagern fehlgeschlagen.')), !empty($r['ok']) ? 'ok' : 'warn');
    weiter('?p=einlagern');
}

$aufg = function_exists('erp_einlager_aufgaben') ? erp_einlager_aufgaben() : [];

kopf('Einlagern', 'einlagern');
seitenkopf('Einlagern', 'Fertige Ware aus der Produktion mit einem Klick ins Lager buchen.');
flash_zeigen();
?>
<?php if (!$aufg): ?>
  <div class="bx-panel muted">Nichts einzulagern – es gibt gerade keine offenen Übergaben aus der Produktion.</div>
<?php else: ?>
<div class="bx-tablewrap">
  <table class="bx-table lg-karten">
    <thead><tr><th>Einlagern</th><th>Details</th><th>Seit</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($aufg as $a): ?>
      <tr>
        <td data-label=""><strong><?= h((string)$a['titel']) ?></strong></td>
        <td data-label="Details" class="muted"><?= h((string)($a['beschreibung'] ?? '')) ?></td>
        <td data-label="Seit" class="muted"><?= h(fmt_zeit((string)$a['angelegt'])) ?></td>
        <td data-label="" style="text-align:right">
          <form method="post" style="margin:0" onsubmit="return confirm('Fertige Ware jetzt einlagern und die Aufgabe schließen?')">
            <input type="hidden" name="aktion" value="buchen"><input type="hidden" name="pa_id" value="<?= (int)$a['pa_id'] ?>">
            <button class="btn btn-primary btn-sm" type="submit">Einlagern</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<div class="muted" style="font-size:12px;margin-top:var(--sp-3)">Ziel (Lager 1 oder Lager 2) steht im Titel und ergibt sich automatisch aus Produkt/Kunde (Fulfillment → Lager 2). Die Buchung übernimmt das Dashboard.</div>
<?php endif; ?>
<?php fuss();
