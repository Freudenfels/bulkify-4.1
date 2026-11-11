<?php
// Gebindetypen mit Tara (11.11.2026, nur Admin): Leergewicht je Sack/Karton/Eimer. Beim Wiegen in der Inventur vorbelegt
// (änderbar); Netto = Brutto - Tara. Für Bulk-Kapseln: Kapseln = Netto / Kapselgewicht.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ((array)($_POST['name'] ?? []) as $tid => $name) {
        $name = trim((string)$name); $t = max(0, erp_menge_parse((string)($_POST['tara'][$tid] ?? '0')));
        $aktiv = !empty($_POST['aktiv'][$tid]) ? 1 : 0; $sort = (int)($_POST['sort'][$tid] ?? 0);
        if ($tid === 'neu') { if ($name !== '') q("INSERT INTO lg_tara (name, tara_kg, sort, aktiv) VALUES (?,?,?,1)", [mb_substr($name, 0, 120), $t, $sort]); }
        elseif ($name !== '') q("UPDATE lg_tara SET name=?, tara_kg=?, sort=?, aktiv=? WHERE id=?", [mb_substr($name, 0, 120), $t, $sort, $aktiv, (int)$tid]);
    }
    flash('Gebindetypen gespeichert.');
    weiter('?p=tara');
}
$liste = inv_tara_liste(false);
kopf('Gebindetypen', 'tara');
seitenkopf('Gebindetypen (Tara)', 'Leergewicht je Gebindetyp. Beim Wiegen in der Inventur vorbelegt und änderbar.', '<a class="btn btn-ghost" href="?p=inventur">Inventur</a>');
flash_zeigen();
?>
<form method="post" class="bx-panel" style="max-width:720px">
  <div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Gebindetyp</th><th>Tara (kg)</th><th>Reihenfolge</th><th>aktiv</th></tr></thead><tbody>
  <?php foreach ($liste as $t): $tid = (int)$t['id']; ?>
    <tr><td><input type="text" name="name[<?= $tid ?>]" value="<?= h((string)$t['name']) ?>"></td>
      <td><input type="text" inputmode="decimal" name="tara[<?= $tid ?>]" value="<?= h(number_format((float)$t['tara_kg'], 3, ',', '.')) ?>" style="width:110px;text-align:right"></td>
      <td><input type="number" name="sort[<?= $tid ?>]" value="<?= (int)$t['sort'] ?>" style="width:80px"></td>
      <td><input type="checkbox" name="aktiv[<?= $tid ?>]" value="1"<?= (int)$t['aktiv'] ? ' checked' : '' ?>></td></tr>
  <?php endforeach; ?>
    <tr><td><input type="text" name="name[neu]" placeholder="neuer Gebindetyp"></td><td><input type="text" inputmode="decimal" name="tara[neu]" placeholder="0,000" style="width:110px;text-align:right"></td><td><input type="number" name="sort[neu]" value="99" style="width:80px"></td><td></td></tr>
  </tbody></table></div>
  <button class="btn btn-primary" type="submit">Speichern</button>
</form>
<?php fuss();
