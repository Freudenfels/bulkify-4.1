<?php
// PA-Seite: geführter Ablauf (Nico 11.11.2026, Teil 1/5/6/7). Eingebunden aus detail.php. Erwartet: $id, $pa.
// - Kopf: Produkt, Kunde, Auftrag, Soll, produziert/offen, aktuelle Unter-Charge, MHD, Status.
// - Unter-Chargen mit Teilmengen und Kartons, Chargenprotokoll je Unter-Charge.
// - „Teilmenge nachtragen" (ersetzt Easy Production): Datum, Menge, Charge (Vorschlag, änderbar mit Grund), MHD, Mitarbeiter,
//   Bulk-Charge, Foto optional, „schon geliefert". Gleiche Buchung wie in der Werk-App (core/pa_ablauf.php).
$ucs = pa_unter_chargen($id);
$akt = pa_aktuelle_unter_charge($id);
$gebuchtA = produktion_gebucht($id); $restA = produktion_rest($id);
$nfA = fn($x) => number_format((float)$x, 0, ',', '.');
$bulkWahl = all("SELECT c.id, c.charge_nr, c.menge_verfuegbar, c.einheit, c.mhd FROM charge c JOIN item i ON i.id=c.item_id
                 WHERE i.kategorie='fertig' AND c.status='frei' AND c.menge_verfuegbar>0 AND c.fremd_kunde_id IS NULL
                   AND (c.auftrag_id=? OR (c.auftrag_id IS NULL AND i.rezeptur_id=?)) ORDER BY (c.mhd IS NULL), c.mhd, c.id",
                [(int)($pa['auftrag_id'] ?? 0), (int)($pa['rezeptur_id'] ?: scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [(int)$pa['produkt_id']]))]);
// Fable-4 T1.1 (13.11.2026): die Bulk-Charge der laufenden Unter-Charge steht immer in der Auswahl (auch mit Bestand 0), dazu die Eimer
// (Mischgebinde, prod_charge) dieses PA – Wert „e<id>“. So muss ein Nachtrag für heute nie „ohne“ nehmen.
if ($akt && (int)$akt['bulk_charge_id'] > 0 && !in_array((int)$akt['bulk_charge_id'], array_map('intval', array_column($bulkWahl, 'id')), true))
    if ($bAkt = one("SELECT id, charge_nr, menge_verfuegbar, einheit, mhd FROM charge WHERE id=?", [(int)$akt['bulk_charge_id']])) array_unshift($bulkWahl, $bAkt);
$eimerWahl = [];
try { $eimerWahl = all("SELECT id, nummer AS nr, menge, einheit FROM prod_charge WHERE pa_id=? OR id=? ORDER BY id DESC", [$id, $akt ? (int)$akt['prod_charge_id'] : 0]); } catch (\Throwable $e) {}
$mitarbeiterA = all("SELECT id, name FROM benutzer WHERE aktiv=1 AND lieferant_id IS NULL ORDER BY name");
// Vorschlag für heute: gleicher Bulk wie die laufende Unter-Charge UND heute = gleicher Buchstabe, sonst der nächste (Nico 12.11.2026).
$vorgabeA = pa_vorgabe($id, $akt ? (int)$akt['bulk_charge_id'] : (int)($bulkWahl[0]['id'] ?? 0), $akt ? (int)$akt['prod_charge_id'] : 0);
?>
<?php // Kopfzeile (Kunde, Auftrag, Soll, produziert/offen, Unter-Charge, MHD, Status) entfällt (13.11.2026): steht schon in den Kacheln oben. ?>

<div class="bx-panel" id="unterchargen">
  <div class="bx-row" style="justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <h2 style="margin:0">Unter-Chargen und Teilmengen</h2>
    <span class="muted" style="font-size:13px">Produziert wird in der Werk-App. Neuer Buchstabe bei anderem Bulk bzw. Eimer oder an einem neuen Produktionstag.</span>
  </div>
  <?php if (!$ucs): ?><p class="muted">Noch keine Unter-Charge. Start in der Werk-App (Bulk scannen, Fotos Linie und Stempel) oder unten „Teilmenge nachtragen“.</p>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Charge</th><th>Produziert am</th><th>Bulk / Eimer</th><th>MHD</th><th class="bx-num">Menge</th><th>Teilmengen</th><th class="bx-num">Kartons</th><th>Status</th><th>Protokoll</th></tr></thead><tbody>
  <?php foreach ($ucs as $u):
      $tmU = all("SELECT * FROM pa_teilmenge WHERE unter_charge_id=? ORDER BY id", [(int)$u['id']]);
      $kU = (int) scalar("SELECT COUNT(*) FROM pa_karton WHERE unter_charge_id=? AND storniert_am IS NULL", [(int)$u['id']]);
      // Produziert am (Nico 13.11.2026): Tage der Teilmengen (Produktionsdatum), sonst Produktionstag bzw. Anlage der Unter-Charge
      $tageU = array_values(array_unique(array_filter(array_map(fn($t) => substr((string)($t['datum'] ?: $t['angelegt']), 0, 10), $tmU))));
      if (!$tageU) $tageU = [substr((string)($u['tag'] ?: $u['angelegt']), 0, 10)];
      sort($tageU);
      $prodAm = implode(', ', array_map(fn($d) => date('d.m.Y', strtotime($d)), $tageU));
      $bulkTxt = $u['bulk_charge_id'] ? (string) scalar("SELECT COALESCE(charge_nr, CONCAT('#',id)) FROM charge WHERE id=?", [(int)$u['bulk_charge_id']]) : ($u['prod_charge_id'] ? 'Eimer ' . (string) scalar("SELECT CONCAT(nummer, COALESCE(sub_kennung,'')) FROM prod_charge WHERE id=?", [(int)$u['prod_charge_id']]) : '–'); ?>
    <tr style="vertical-align:top">
      <td><b style="font-weight:600"><?= h((string)$u['charge_nr']) ?></b><?= $u['fertig_charge_id'] ? ' <a href="?p=chargen&id=' . (int)$u['fertig_charge_id'] . '" class="muted" style="font-size:12px">Lager</a>' : '' ?><?= $u['abweichung_grund'] ? '<div class="muted" style="font-size:12px">abweichend: ' . h((string)$u['abweichung_grund']) . '</div>' : '' ?></td>
      <td style="white-space:normal"><?= h($prodAm) ?></td>
      <td><?= h($bulkTxt) ?></td>
      <td><?= $u['mhd'] ? h(date('d.m.Y', strtotime((string)$u['mhd']))) : '–' ?></td>
      <td class="bx-num" style="white-space:nowrap"><?= $nfA(pa_uc_menge((int)$u['id'])) ?><?php if ($u['status'] === 'laeuft'): ?>
        <button type="button" class="bx-stift" style="margin-left:6px;vertical-align:middle" title="Menge korrigieren" onclick="bxPopup.open('dlgUcMenge<?= (int)$u['id'] ?>')"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></button>
        <dialog id="dlgUcMenge<?= (int)$u['id'] ?>" class="bx-dialog" style="text-align:left;white-space:normal"><h2>Menge <?= h((string)$u['charge_nr']) ?> korrigieren</h2>
          <form method="post"><input type="hidden" name="aktion" value="uc_menge"><input type="hidden" name="uc_id" value="<?= (int)$u['id'] ?>">
            <?php $ucVor = (float) scalar("SELECT COALESCE(SUM(menge),0) FROM pa_teilmenge WHERE unter_charge_id=? AND umfang='vor_etikett'", [(int)$u['id']]); ?>
            <div class="bx-grid"><div class="bx-field"><label>Komplett (Stk, mit Etikett)</label><input type="text" inputmode="numeric" name="menge" required data-pflicht data-pflicht-text="Menge fehlt" value="<?= h($nfA(pa_uc_menge((int)$u['id']))) ?>"></div>
              <?php if ($ucVor > 0 || (string)(produktion_etikett_sperre((int)$pa['id']) ?? '') !== ''): ?><div class="bx-field"><label>Bis vor Etikett (Stk)</label><input type="text" inputmode="numeric" name="menge_vor" required data-pflicht data-pflicht-text="Menge fehlt" value="<?= h($nfA($ucVor)) ?>"></div><?php endif; ?>
              <div class="bx-field"><label>Grund</label><input type="text" name="grund" required data-pflicht maxlength="200" placeholder="z. B. falsch gezählt"></div></div>
            <p class="muted" style="font-size:13px;margin:8px 0 0">Mehr: der Unterschied wird nachgebucht (gleiche Materialbuchung wie ein Nachtrag). Weniger: Fertigware zurück, Material laut den tatsächlichen Entnahmen zurück ins Lager, Kartons und Rückstellmuster werden angepasst. Nur Produktionsleitung und Admin.</p>
            <div class="bx-pop-fuss"><button class="btn btn-primary" type="submit">Menge speichern</button></div></form></dialog>
      <?php endif; ?></td>
      <td style="white-space:normal;font-size:13px"><?php foreach ($tmU as $t): ?><div><?= h(date('d.m.', strtotime((string)($t['datum'] ?: $t['angelegt'])))) ?> · <?= $nfA($t['menge']) ?> Stk<?= $t['umfang'] === 'vor_etikett' ? ' (vor Etikett)' : '' ?><?= $t['art'] === 'nachtrag' ? ' · nachgetragen' : '' ?><?= $t['mitarbeiter'] ? ' · ' . h((string)$t['mitarbeiter']) : '' ?><?= $t['geliefert'] ? ' · schon geliefert' : '' ?></div><?php endforeach; ?></td>
      <td class="bx-num"><?= $kU ?></td>
      <td><?= $u['status'] === 'laeuft' ? bx_badge('läuft', 'info') : bx_badge('fertig', 'ok') ?>
        <?php if ($pa['status'] !== 'erledigt' || $u['status'] === 'laeuft'): // Fable-4 T1.2: laufende Unter-Charge immer abschließbar, auch bei erledigtem PA ?>
        <form method="post" style="margin:6px 0 0" <?= $u['status'] === 'laeuft' ? 'onsubmit="return confirm(\'Unter-Charge ' . h((string)$u['charge_nr']) . ' abschließen? Weitere Teilmengen mit demselben Bulk laufen dann unter einem neuen Buchstaben.\');"' : '' ?>>
          <input type="hidden" name="aktion" value="<?= $u['status'] === 'laeuft' ? 'uc_schliessen' : 'uc_oeffnen' ?>"><input type="hidden" name="uc_id" value="<?= (int)$u['id'] ?>">
          <button class="btn btn-ghost btn-sm" type="submit"><?= $u['status'] === 'laeuft' ? 'abschließen' : 'wieder öffnen' ?></button>
        </form>
        <?php endif; ?></td>
      <td><?php if (function_exists('pa_protokoll_pdf')): ?><a href="?p=chargenprotokoll&uc=<?= (int)$u['id'] ?>" target="_blank" rel="noopener">PDF</a><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>

<?php if ($pa['status'] !== 'erledigt' && $restA > 0): ?>
<details class="bx-panel" id="nachtragen"<?= isset($_GET['nachtragen']) ? ' open' : '' ?>>
  <summary style="cursor:pointer;font-size:var(--fs-lg)">Teilmenge nachtragen</summary>
  <p class="muted" style="font-size:13px">Für Produktion, die schon gelaufen ist (ersetzt Easy Production). Gleiche Buchung wie in der Werk-App: Material raus (die gewählte Bulk-Charge zuerst), Fertigware rein in die Charge der Unter-Charge.</p>
  <form method="post" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:12px;max-width:820px">
    <input type="hidden" name="aktion" value="nachtragen">
    <div class="bx-row" style="gap:12px;flex-wrap:wrap">
      <div class="bx-field" style="width:160px"><label>Produktionsdatum</label><input type="date" name="datum" value="<?= h(heute_ort()) ?>" required></div>
      <div class="bx-field" style="width:150px"><label>Menge (Stk)</label><input type="text" inputmode="numeric" name="menge" required placeholder="max. <?= $nfA($restA) ?>"></div>
      <div class="bx-field" style="flex:1;min-width:240px"><label>Bulk-Charge</label>
        <select name="bulk_charge_id" id="naBulk"><option value="0">ohne (kein Bulk-Bezug)</option><?php foreach ($bulkWahl as $b): ?><option value="<?= (int)$b['id'] ?>"<?= $akt && (int)$akt['bulk_charge_id'] === (int)$b['id'] ? ' selected' : '' ?>><?= h(($b['charge_nr'] ?: '#' . $b['id']) . ' · ' . $nfA($b['menge_verfuegbar']) . ' ' . $b['einheit'] . ($b['mhd'] ? ' · MHD ' . date('d.m.Y', strtotime((string)$b['mhd'])) : '') . ($akt && (int)$akt['bulk_charge_id'] === (int)$b['id'] ? ' · laufende Unter-Charge ' . $akt['charge_nr'] : '')) ?></option><?php endforeach; ?><?php foreach ($eimerWahl as $e): ?><option value="e<?= (int)$e['id'] ?>"<?= $akt && (int)$akt['prod_charge_id'] === (int)$e['id'] ? ' selected' : '' ?>><?= h('Eimer ' . $e['nr'] . ($akt && (int)$akt['prod_charge_id'] === (int)$e['id'] ? ' · laufende Unter-Charge ' . $akt['charge_nr'] : '')) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="bx-row" style="gap:12px;flex-wrap:wrap">
      <div class="bx-field" style="width:170px"><label>Charge (Vorschlag)</label><input type="text" name="charge_nr" value="<?= h($vorgabeA['charge_nr']) ?>" data-vorgabe="<?= h($vorgabeA['charge_nr']) ?>"><input type="hidden" name="charge_vorschlag" value="<?= h($vorgabeA['charge_nr']) ?>"><div class="muted" style="font-size:12px">Unverändert lassen: das System vergibt je Bulk und Produktionstag den richtigen Buchstaben.</div></div>
      <div class="bx-field pa-grund" data-vorgabe="<?= h($vorgabeA['charge_nr']) ?>" style="flex:1;min-width:220px"><label>Grund für die andere Charge</label><input type="text" name="grund" placeholder="Pflicht, weil die Charge vom Vorschlag abweicht"></div>
      <div class="bx-field" style="width:170px"><label>MHD</label><input type="date" name="mhd" value="<?= h($vorgabeA['mhd']) ?>"></div>
    </div>
    <?= pa_grund_js() ?>
    <div class="bx-row" style="gap:12px;flex-wrap:wrap;align-items:flex-end">
      <div class="bx-field" style="flex:1;min-width:220px"><label>Mitarbeiter</label><select name="mitarbeiter"><option value="">–</option><?php foreach ($mitarbeiterA as $m): ?><option><?= h((string)$m['name']) ?></option><?php endforeach; ?></select></div>
      <div class="bx-field" style="width:150px"><label>Rückstellmuster</label><input type="text" inputmode="numeric" name="rueckstell" value="0"></div>
      <div class="bx-field" style="flex:1;min-width:220px"><label>Foto (optional)</label><input type="file" name="foto" accept="image/*"></div>
    </div>
    <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="geliefert" value="1"> schon geliefert (Ware ist bereits beim Kunden – wird gleich per Lieferschein ausgebucht)</label>
    <div><button class="btn btn-primary" type="submit">Teilmenge nachtragen</button></div>
  </form>
</details>
<?php endif; ?>
