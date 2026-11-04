<?php
// Soll-Kette 7 (Nico/Dustin 04.11.2026): Versandbereite Aufträge (Produktion abgeschlossen) je Kunde. Dustin legt die Versandart fest
// (Paket, Palette, Selbstabholung, Lager 2), fasst Aufträge eines Kunden zu EINER Sendung zusammen (Packstücke + Gesamtlieferschein in
// der Sendung) oder bucht ins Lager 2 um. Gebucht wird im Dashboard (Loopback), damit Lieferschein-Beleg, Status und Rechnung stimmen.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    if ($aktion === 'art') {
        foreach ((array)($_POST['art'] ?? []) as $aid => $art) erp_auftrag_versandart_setzen((int)$aid, (string)$art);
        flash('Versandart gespeichert.'); weiter('?p=versand_bereit');
    }
    if ($aktion === 'lager2') {
        $r = erp_dashboard_versand('fremdlager', (int)($_POST['auftrag_id'] ?? 0));
        flash(!empty($r['ok']) ? 'Ins Lager 2 umgebucht.' : (string)($r['msg'] ?? 'Umbuchen nicht möglich.'), !empty($r['ok']) ? 'ok' : 'warn');
        weiter('?p=versand_bereit');
    }
    if ($aktion === 'sendung') {
        $alle = []; foreach (erp_versandbereite_auftraege() as $a) $alle[(int)$a['id']] = $a;
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['aids'] ?? [])), fn($i) => isset($alle[$i])));
        $kunden = array_unique(array_map(fn($i) => (int)$alle[$i]['kunde_id'], $ids));
        $arten  = array_unique(array_map(fn($i) => (string)$alle[$i]['versandart'], $ids));
        if (!$ids) { flash('Bitte Aufträge anhaken.', 'warn'); weiter('?p=versand_bereit'); }
        if (count($kunden) > 1) { flash('Eine Sendung geht an EINEN Kunden – bitte nur Aufträge eines Kunden anhaken.', 'warn'); weiter('?p=versand_bereit'); }
        if (count($arten) > 1 || !in_array($arten[0] ?? '', ['paket', 'palette', 'abholung'], true)) { flash('Bitte allen angehakten Aufträgen dieselbe Versandart geben (Paket, Palette oder Selbstabholung).', 'warn'); weiter('?p=versand_bereit'); }
        $kid = (int)$kunden[0];
        $adr = erp_kunde_adressen($kid); $ad = $adr[0] ?? [];
        foreach ($adr as $x) if (!empty($x['bevorzugt'])) $ad = $x;
        $vid = lg_versand_anlegen(['kunde_id' => $kid, 'adress_quelle' => (string)($ad['quelle'] ?? 'frei'), 'empf_firma' => (string)($ad['firma'] ?? ''), 'empf_name' => (string)($ad['name'] ?? ''),
            'empf_strasse' => (string)($ad['strasse'] ?? ''), 'empf_hausnummer' => (string)($ad['hausnummer'] ?? ''), 'empf_plz' => (string)($ad['plz'] ?? ''),
            'empf_ort' => (string)($ad['ort'] ?? ''), 'empf_land' => (string)($ad['land'] ?? 'DE'), 'empf_email' => (string)($ad['email'] ?? ''),
            'empf_telefon' => (string)($ad['telefon'] ?? ''), 'typ' => (string)$arten[0],
            'notiz' => 'Aufträge ' . implode(', ', array_map(fn($i) => (string)$alle[$i]['nummer'], $ids))]);
        foreach ($ids as $aid) {
            $offen = (float)$alle[$aid]['menge'] - (float)$alle[$aid]['versendet'];
            foreach (erp_auftrag_fertig_chargen($aid) as $c) {
                if ($offen <= 0.0001) break;
                $m = min($offen, (float)$c['menge_verfuegbar']); $offen -= $m;
                q("INSERT INTO lg_versand_pos (versand_id,charge_id,item_id,bezeichnung,charge_nr,menge,einheit,auftrag_id) VALUES (?,?,?,?,?,?,?,?)",
                  [$vid, (int)$c['id'], (int)$c['item_id'], mb_substr((string)$alle[$aid]['produkt'] . ' (' . $alle[$aid]['nummer'] . ')', 0, 255), (string)$c['charge_nr'], $m, (string)($c['einheit'] ?: 'Stk'), $aid]);
            }
        }
        flash('Sendung angelegt – jetzt Packstücke aufbauen, Lieferschein drucken, versenden.'); weiter('?p=versand_detail&id=' . $vid);
    }
}

$zeilen = erp_versandbereite_auftraege();
$jeKunde = []; foreach ($zeilen as $z) $jeKunde[(string)($z['kunde'] ?: '–')][] = $z;
kopf('Versandbereit', 'versand_bereit');
seitenkopf('Versandbereit', 'Aufträge mit abgeschlossener Produktion. Versandart festlegen, Aufträge eines Kunden zu einer Sendung zusammenfassen oder ins Lager 2 buchen.');
flash_zeigen();
?>
<?php if (!$zeilen): ?>
  <div class="bx-panel muted">Kein Auftrag ist versandbereit.</div>
<?php else: foreach ($jeKunde as $kunde => $auf): ?>
<div class="bx-panel">
  <h2 style="margin-top:0"><?= h($kunde) ?></h2>
  <form method="post">
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th></th><th>Auftrag</th><th>Produkt</th><th class="bx-num">Offen</th><th class="bx-num">Fertigware frei</th><th>Versandart</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($auf as $a): $offen = (float)$a['menge'] - (float)$a['versendet']; ?>
        <tr>
          <td><?php if (in_array((string)$a['versandart'], ['paket', 'palette', 'abholung'], true)): ?><input type="checkbox" name="aids[]" value="<?= (int)$a['id'] ?>"><?php endif; ?></td>
          <td><?= h((string)$a['nummer']) ?></td>
          <td style="white-space:normal"><?= h((string)$a['produkt']) ?></td>
          <td class="bx-num"><?= h(menge_txt($offen)) ?> Pkg</td>
          <td class="bx-num"><?= h(menge_txt($a['fertig_frei'])) ?> Pkg</td>
          <td><select name="art[<?= (int)$a['id'] ?>]"><option value="">– wählen –</option>
            <?php foreach (erp_versandarten() as $k => $l): if ($k === 'lager2' && !(int)$a['fulfillment']) continue; ?><option value="<?= h($k) ?>" <?= (string)$a['versandart'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
          </select></td>
          <td style="text-align:right"><?php if ((string)$a['versandart'] === 'lager2'): ?>
            <button class="btn btn-primary btn-sm" type="submit" name="aktion" value="lager2" formnovalidate onclick="this.form.auftrag_id.value='<?= (int)$a['id'] ?>'; return confirm('Auftrag <?= h((string)$a['nummer']) ?> ins Lager 2 des Kunden umbuchen?')">Ins Lager 2 buchen</button>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <input type="hidden" name="auftrag_id" value="">
    <div class="bx-row" style="gap:var(--sp-3);margin-top:var(--sp-3);flex-wrap:wrap">
      <button class="btn btn-ghost" type="submit" name="aktion" value="art">Versandart speichern</button>
      <button class="btn btn-primary" type="submit" name="aktion" value="sendung">Sendung aus angehakten Aufträgen anlegen</button>
    </div>
  </form>
</div>
<?php endforeach; endif; ?>
<?php fuss(); ?>
