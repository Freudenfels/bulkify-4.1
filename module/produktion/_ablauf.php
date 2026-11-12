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
$mitarbeiterA = all("SELECT id, name FROM benutzer WHERE aktiv=1 AND lieferant_id IS NULL ORDER BY name");
// Vorschlag für heute: gleicher Bulk wie die laufende Unter-Charge UND heute = gleicher Buchstabe, sonst der nächste (Nico 12.11.2026).
$vorgabeA = pa_vorgabe($id, $akt ? (int)$akt['bulk_charge_id'] : (int)($bulkWahl[0]['id'] ?? 0), $akt ? (int)$akt['prod_charge_id'] : 0);
?>
<div class="bx-panel">
  <div class="bx-row" style="gap:28px;flex-wrap:wrap">
    <div><div class="muted" style="font-size:12px">Kunde</div><?= !empty($pa['kunde_id']) ? kunde_link((int)$pa['kunde_id'], (string)($pa['kunde_firma'] ?? '')) : '<span class="muted">Lager</span>' ?></div>
    <div><div class="muted" style="font-size:12px">Auftrag</div><?= !empty($pa['auftrag_id']) ? '<a href="?p=auftrag&id=' . (int)$pa['auftrag_id'] . '">' . h((string)$pa['auftrag_nr']) . '</a>' : '–' ?></div>
    <div><div class="muted" style="font-size:12px">Soll</div><?= $nfA($pa['menge']) ?> Stk</div>
    <div><div class="muted" style="font-size:12px">Produziert / offen</div><?= $nfA($gebuchtA) ?> / <?= $nfA($restA) ?> Stk</div>
    <div><div class="muted" style="font-size:12px">Aktuelle Unter-Charge</div><?= $akt ? '<b style="font-weight:600">' . h((string)$akt['charge_nr']) . '</b>' : '<span class="muted">keine laufend</span>' ?></div>
    <div><div class="muted" style="font-size:12px">MHD</div><?= $akt ? h(date('d.m.Y', strtotime((string)$akt['mhd']))) : '–' ?></div>
    <div><div class="muted" style="font-size:12px">Status</div><?= h((string)$pa['status']) ?></div>
  </div>
</div>

<div class="bx-panel" id="unterchargen">
  <div class="bx-row" style="justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <h2 style="margin:0">Unter-Chargen und Teilmengen</h2>
    <span class="muted" style="font-size:13px">Produziert wird in der Werk-App. Neuer Buchstabe bei anderem Bulk bzw. Eimer oder an einem neuen Produktionstag.</span>
  </div>
  <?php if (!$ucs): ?><p class="muted">Noch keine Unter-Charge. Start in der Werk-App (Bulk scannen, Fotos Linie und Stempel) oder unten „Teilmenge nachtragen“.</p>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Charge</th><th>Bulk / Eimer</th><th>MHD</th><th class="bx-num">Menge</th><th>Teilmengen</th><th class="bx-num">Kartons</th><th>Status</th><th>Protokoll</th></tr></thead><tbody>
  <?php foreach ($ucs as $u):
      $tmU = all("SELECT * FROM pa_teilmenge WHERE unter_charge_id=? ORDER BY id", [(int)$u['id']]);
      $kU = (int) scalar("SELECT COUNT(*) FROM pa_karton WHERE unter_charge_id=?", [(int)$u['id']]);
      $bulkTxt = $u['bulk_charge_id'] ? (string) scalar("SELECT COALESCE(charge_nr, CONCAT('#',id)) FROM charge WHERE id=?", [(int)$u['bulk_charge_id']]) : ($u['prod_charge_id'] ? 'Eimer ' . (string) scalar("SELECT CONCAT(nummer, COALESCE(sub_kennung,'')) FROM prod_charge WHERE id=?", [(int)$u['prod_charge_id']]) : '–'); ?>
    <tr style="vertical-align:top">
      <td><b style="font-weight:600"><?= h((string)$u['charge_nr']) ?></b><?= $u['fertig_charge_id'] ? ' <a href="?p=chargen&id=' . (int)$u['fertig_charge_id'] . '" class="muted" style="font-size:12px">Lager</a>' : '' ?><?= $u['abweichung_grund'] ? '<div class="muted" style="font-size:12px">abweichend: ' . h((string)$u['abweichung_grund']) . '</div>' : '' ?></td>
      <td><?= h($bulkTxt) ?></td>
      <td><?= $u['mhd'] ? h(date('d.m.Y', strtotime((string)$u['mhd']))) : '–' ?></td>
      <td class="bx-num"><?= $nfA(pa_uc_menge((int)$u['id'])) ?></td>
      <td style="white-space:normal;font-size:13px"><?php foreach ($tmU as $t): ?><div><?= h(date('d.m.', strtotime((string)($t['datum'] ?: $t['angelegt'])))) ?> · <?= $nfA($t['menge']) ?> Stk<?= $t['umfang'] === 'vor_etikett' ? ' (vor Etikett)' : '' ?><?= $t['art'] === 'nachtrag' ? ' · nachgetragen' : '' ?><?= $t['mitarbeiter'] ? ' · ' . h((string)$t['mitarbeiter']) : '' ?><?= $t['geliefert'] ? ' · schon geliefert' : '' ?></div><?php endforeach; ?></td>
      <td class="bx-num"><?= $kU ?></td>
      <td><?= $u['status'] === 'laeuft' ? bx_badge('läuft', 'info') : bx_badge('fertig', 'ok') ?>
        <?php if ($pa['status'] !== 'erledigt'): ?>
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
      <div class="bx-field" style="width:160px"><label>Produktionsdatum</label><input type="date" name="datum" value="<?= h(date('Y-m-d')) ?>" required></div>
      <div class="bx-field" style="width:150px"><label>Menge (Stk)</label><input type="text" inputmode="numeric" name="menge" required placeholder="max. <?= $nfA($restA) ?>"></div>
      <div class="bx-field" style="flex:1;min-width:240px"><label>Bulk-Charge</label>
        <select name="bulk_charge_id" id="naBulk"><option value="0">ohne (kein Bulk-Bezug)</option><?php foreach ($bulkWahl as $b): ?><option value="<?= (int)$b['id'] ?>"<?= $akt && (int)$akt['bulk_charge_id'] === (int)$b['id'] ? ' selected' : '' ?>><?= h(($b['charge_nr'] ?: '#' . $b['id']) . ' · ' . $nfA($b['menge_verfuegbar']) . ' ' . $b['einheit'] . ($b['mhd'] ? ' · MHD ' . date('d.m.Y', strtotime((string)$b['mhd'])) : '')) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="bx-row" style="gap:12px;flex-wrap:wrap">
      <div class="bx-field" style="width:170px"><label>Charge (Vorschlag)</label><input type="text" name="charge_nr" value="<?= h($vorgabeA['charge_nr']) ?>" data-vorgabe="<?= h($vorgabeA['charge_nr']) ?>"><input type="hidden" name="charge_vorschlag" value="<?= h($vorgabeA['charge_nr']) ?>"><div class="muted" style="font-size:12px">Unverändert lassen: das System vergibt je Bulk und Produktionstag den richtigen Buchstaben.</div></div>
      <div class="bx-field" style="flex:1;min-width:220px"><label>Grund, falls die Charge abweicht</label><input type="text" name="grund" placeholder="nur bei Abweichung"></div>
      <div class="bx-field" style="width:170px"><label>MHD</label><input type="date" name="mhd" value="<?= h($vorgabeA['mhd']) ?>"></div>
    </div>
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
