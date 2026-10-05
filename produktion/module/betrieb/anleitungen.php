<?php
// Anleitungen: Kurz-Arbeitsanweisung je Produktionsstation (Referenz für die Mitarbeiter).
// Zieht aus station_anleitung_text(); später erweiterbar um hochgeladene SOPs.
$stationen = [
    'Rohstoffe bereitstellen', 'Mischen', 'Verkapselung', 'Tablettierung', 'Softgel-Herstellung',
    'Stick-Abfüllung', 'Pulver-Abfüllung', 'Abfüllung', 'Fertigware bereitstellen',
    'Zwischenkontrolle', 'Verpacken', 'Etikettieren', 'Beipackzettel beilegen', 'Umkarton',
    'Rückstellmuster ziehen', 'Qualitätsprüfung', 'Produktions-Freigabe', 'Versand-Freigabe', 'Einlagern (Bulk)',
];
kopf('Anleitungen', 'anleitungen');
seitenkopf('Anleitungen', 'Kurz-Arbeitsanweisung je Produktionsschritt');
?>
<div class="bx-panel">
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Schritt</th><th>Anleitung</th></tr></thead>
    <tbody>
      <?php foreach ($stationen as $st): $txt = station_anleitung_text($st); ?>
      <tr>
        <td><strong><?= h($st) ?></strong></td>
        <td><?= $txt !== '' ? h($txt) : '<span class="muted">–</span>' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12px;margin:12px 0 0">Diese Kurz-Anleitungen erscheinen auch direkt im Produktionsmodus beim jeweiligen Schritt. Ausführliche SOP-Dokumente zum Hochladen können wir hier ergänzen.</p>
</div>
<?php fuss();
