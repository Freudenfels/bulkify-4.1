<?php
// Pop-up an einer Bestellung (Paket 13.11.2026, Teil 6.4): Klick auf eine Zeile in „Bestellt“ öffnet es über der Liste.
// Gedacht für Lieferanten ohne Portal-Zugang (z. B. Vita Actives): Nico trägt selbst ein, wenn CoA oder Versandinfo per Mail kommt.
// - Dokumente: CoA (je Charge), Spec, Rechnung, Lieferschein – Upload direkt hier
// - Versand: Versanddienst, Tracking-Nummern (mehrere, Tabelle lieferung_paket), voraussichtliches Lieferdatum
// - Sendung: Art (Paket/Palette/Container), Anzahl, Palettenart, Kisten/Kartons, Gewicht, Maße/Stellplätze
// - Notiz (intern)
// Gespeichert wird ohne Seitenwechsel (bx-ui.js); danach aktualisiert sich die Zeile in „Bestellt“.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/dokument_ui.php';
require_once BX_ROOT . '/core/bestellung_einzeln.php';
bestellung_sendung_schema();

$id = (int)($_GET['id'] ?? 0);
$b = $id ? one("SELECT b.*, l.firma AS lieferant_firma, l.lieferantennummer FROM bestellung b LEFT JOIN lieferanten l ON l.id=b.lieferant_id WHERE b.id=?", [$id]) : null;
if (!$b) { http_response_code(404); echo 'Bestellung nicht gefunden.'; exit; }
$wer = (string)(current_user()['name'] ?? 'Team');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string)($_POST['aktion'] ?? '');
    if ($a === 'pop_versand') {
        $eta = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['eta_geplant'] ?? '')) ? (string)$_POST['eta_geplant'] : null;
        q("UPDATE bestellung SET versandanbieter=?, eta_geplant=? WHERE id=?", [mb_substr(trim((string)($_POST['versandanbieter'] ?? '')), 0, 60) ?: null, $eta, $id]);
        $n = bestellung_tracking_setzen($id, (string)($_POST['tracking'] ?? ''), mb_substr(trim((string)($_POST['versandanbieter'] ?? '')), 0, 60));
        log_aktivitaet('bestellung', $id, 'team', 'Versand erfasst: ' . trim((string)($_POST['versandanbieter'] ?? '')) . ($n ? ', ' . $n . ' Tracking-Nummer(n)' : '') . ($eta ? ', erwartet ' . date('d.m.Y', strtotime($eta)) : '') . ' (' . $wer . ')', 'status');
    } elseif ($a === 'pop_sendung') {
        $int = fn($k) => ($v = (int) zahl_lesen((string)($_POST[$k] ?? ''), true)) > 0 ? $v : null;
        $art = in_array($_POST['sendung_art'] ?? '', array_keys(bestellung_sendung_arten()), true) ? (string)$_POST['sendung_art'] : null;
        $pal = in_array($_POST['palettenart'] ?? '', array_keys(bestellung_palettenarten()), true) ? (string)$_POST['palettenart'] : null;
        $gew = gewicht_lesen((string)($_POST['gewicht_kg'] ?? ''));   // Fable-4 T2.4: Gewicht (vorher Preis-Lesart: „1.250“ kg = 1,25)
        q("UPDATE bestellung SET sendung_art=?, sendung_anzahl=?, palettenart=?, kisten_anzahl=?, gewicht_kg=?, masse=?, stellplaetze=? WHERE id=?",
          [$art, $int('sendung_anzahl'), $pal, $int('kisten_anzahl'), $gew > 0 ? $gew : null, mb_substr(trim((string)($_POST['masse'] ?? '')), 0, 120) ?: null, $int('stellplaetze'), $id]);
        log_aktivitaet('bestellung', $id, 'team', 'Sendung erfasst: ' . bestellung_sendung_text($id) . ' (' . $wer . ')', 'status');
    } elseif ($a === 'pop_notiz') {
        q("UPDATE bestellung SET notiz=? WHERE id=?", [trim((string)($_POST['notiz'] ?? '')), $id]);
    } elseif ($a === 'pop_dok') {
        $did = dokument_upload('bestellung', $id);
        if ($did && ($cn = trim((string)($_POST['charge_nr'] ?? ''))) !== '') q("UPDATE dokument SET charge_nr=?, titel=COALESCE(titel, ?) WHERE id=?", [mb_substr($cn, 0, 60), 'CoA · Charge ' . mb_substr($cn, 0, 60), $did]);
        // Bezug zur (einzigen) Position, damit CoA/Spec genau dieser Bestellung und diesem Artikel gehören
        $bp = (int) scalar("SELECT id FROM bestellung_position WHERE bestellung_id=? ORDER BY sort, id LIMIT 1", [$id]);
        if ($did && $bp) q("UPDATE dokument SET bestellung_position_id=COALESCE(bestellung_position_id, ?) WHERE id=?", [$bp, $did]);
    }
    header('Location: ?p=bestellung_pop&id=' . $id); exit;
}

$doks = all("SELECT id, typ, titel, datei_orig, charge_nr FROM dokument WHERE objekt_typ='bestellung' AND objekt_id=? ORDER BY id DESC", [$id]);
$pakete = bestellung_tracking_liste($id);
$pos = all("SELECT bp.menge, bp.einheit, COALESCE(NULLIF(bp.bezeichnung,''), i.name, '–') AS name FROM bestellung_position bp LEFT JOIN item i ON i.id=bp.item_id WHERE bp.bestellung_id=? ORDER BY bp.sort, bp.id", [$id]);
$sel = fn($opts, $cur) => implode('', array_map(fn($k, $l) => '<option value="' . h($k) . '"' . ((string)$cur === (string)$k ? ' selected' : '') . '>' . h($l) . '</option>', array_keys($opts), $opts));
$nf = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');

render_header('einkauf', 'Bestellung ' . $b['nummer']);
?>
<div data-pop-inhalt>
  <div class="muted" style="margin-bottom:10px"><a href="?p=bestellung&id=<?= $id ?>"><b style="font-weight:600"><?= h((string)$b['nummer']) ?></b></a> · <?= $b['lieferant_firma'] ? h((string)$b['lieferant_firma']) : 'ohne Lieferant' ?>
    · <?= h(implode(', ', array_map(fn($p) => $p['name'] . ' ' . $nf($p['menge']) . ' ' . $p['einheit'], $pos))) ?></div>

  <?php // Sofortfix 13.11.2026: Lieferung bestätigen = Wareneingang buchen (Charge, MHD, Menge), je offener Position.
  require_once BX_ROOT . '/core/wareneingang_bestellung.php';
  if (in_array((string)$b['status'], ['bestellt', 'gesendet'], true) && empty($b['angekommen_am']))
      foreach (all("SELECT bp.id, COALESCE(NULLIF(bp.bezeichnung,''), i.name, '–') AS name FROM bestellung_position bp LEFT JOIN item i ON i.id=bp.item_id
                    WHERE bp.bestellung_id=? AND bp.menge>0 AND bp.rest_storniert_am IS NULL AND COALESCE(bp.geliefert_menge,0) < bp.menge*0.98 ORDER BY bp.sort, bp.id", [$id]) as $wp):
          $wv = webe_vormerkung((int)$wp['id']); ?>
    <a class="btn btn-primary" style="margin:0 8px 8px 0" href="?p=wareneingang&bp=<?= (int)$wp['id'] ?><?= $wv ? '&bch=' . (int)$wv['id'] : '' ?>">Ware ist da - einbuchen<?= count($pos) > 1 ? ': ' . h((string)$wp['name']) : '' ?></a>
  <?php endforeach; ?>
  <h3 style="margin:8px 0 6px">Dokumente</h3>
  <?php if ($doks): ?><div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px"><?php foreach ($doks as $d): ?>
    <a class="btn btn-ghost btn-sm" target="_blank" rel="noopener" href="?p=dokument&id=<?= (int)$d['id'] ?>"><?= h((dokument_typen()[$d['typ']] ?? $d['typ']) . ($d['charge_nr'] ? ' · ' . $d['charge_nr'] : '') . ' · ' . ($d['datei_orig'] ?: '#' . $d['id'])) ?></a>
  <?php endforeach; ?></div><?php else: ?><p class="muted" style="margin:0 0 8px">Noch keine Dokumente.</p><?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:8px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="aktion" value="pop_dok">
    <div class="bx-field" style="margin:0"><label>Art</label><select name="dok_typ"><?= $sel(['coa' => 'CoA (je Charge)', 'spec' => 'Spec', 'rechnung' => 'Rechnung', 'lieferschein' => 'Lieferschein', 'sonstiges' => 'Sonstiges'], 'coa') ?></select></div>
    <div class="bx-field" style="margin:0;width:150px"><label>Charge (bei CoA)</label><input type="text" name="charge_nr"></div>
    <div class="bx-field" style="margin:0;min-width:220px"><label>Datei</label><input type="file" name="dok" required></div>
    <input type="hidden" name="dok_lieferant" value="<?= (int)($b['lieferant_id'] ?? 0) ?: '' ?>">
    <button class="btn btn-ghost" type="submit">Hochladen</button>
  </form>
  <p class="muted" style="font-size:12px;margin:4px 0 0">CoA mit KI-Prüfung und Vergleich gegen die bulkify-Spec: an der Bestellung im Block „Chargen und Dokumente“.</p>

  <h3 style="margin:18px 0 6px">Versand</h3>
  <form method="post">
    <input type="hidden" name="aktion" value="pop_versand">
    <div class="bx-grid">
      <div class="bx-field"><label>Versanddienst</label><input type="text" name="versandanbieter" list="dlVersand" value="<?= h((string)($b['versandanbieter'] ?? '')) ?>" placeholder="DHL, UPS, DPD, Spedition …">
        <datalist id="dlVersand"><option>DHL</option><option>UPS</option><option>DPD</option><option>GLS</option><option>FedEx</option><option>TNT</option><option>Spedition</option><option>Kühne + Nagel</option><option>DB Schenker</option></datalist></div>
      <div class="bx-field"><label>Voraussichtliches Lieferdatum</label><input type="date" name="eta_geplant" value="<?= h((string)($b['eta_geplant'] ?? '')) ?>"></div>
      <div class="bx-field" style="grid-column:1/-1"><label>Tracking-Nummern (eine je Zeile)</label><textarea name="tracking" rows="3" placeholder="z. B. 00340434161234567890"><?= h(implode("\n", array_column($pakete, 'tracking'))) ?></textarea></div>
    </div>
    <div class="bx-row" style="justify-content:flex-end;margin-top:8px"><button class="btn btn-primary" type="submit">Versand speichern</button></div>
  </form>

  <h3 style="margin:18px 0 6px">Sendung</h3>
  <form method="post">
    <input type="hidden" name="aktion" value="pop_sendung">
    <div class="bx-grid">
      <div class="bx-field"><label>Art</label><select name="sendung_art"><option value="">–</option><?= $sel(bestellung_sendung_arten(), $b['sendung_art'] ?? '') ?></select></div>
      <div class="bx-field"><label>Anzahl (z. B. 2 Paletten, 5 Pakete)</label><input type="text" inputmode="numeric" name="sendung_anzahl" value="<?= h((string)($b['sendung_anzahl'] ?? '')) ?>"></div>
      <div class="bx-field"><label>Palettenart</label><select name="palettenart"><option value="">–</option><?= $sel(bestellung_palettenarten(), $b['palettenart'] ?? '') ?></select></div>
      <div class="bx-field"><label>Kisten / Kartons</label><input type="text" inputmode="numeric" name="kisten_anzahl" value="<?= h((string)($b['kisten_anzahl'] ?? '')) ?>"></div>
      <div class="bx-field"><label>Gewicht gesamt (kg)</label><input type="text" inputmode="decimal" data-gewicht="kg" name="gewicht_kg" value="<?= $b['gewicht_kg'] !== null && $b['gewicht_kg'] !== '' ? h(zahl_feld($b['gewicht_kg'])) : '' ?>"></div>
      <div class="bx-field"><label>Stellplätze</label><input type="text" inputmode="numeric" name="stellplaetze" value="<?= h((string)($b['stellplaetze'] ?? '')) ?>"></div>
      <div class="bx-field" style="grid-column:1/-1"><label>Maße</label><input type="text" name="masse" value="<?= h((string)($b['masse'] ?? '')) ?>" placeholder="z. B. 120 × 80 × 160 cm"></div>
    </div>
    <div class="bx-row" style="justify-content:flex-end;margin-top:8px"><button class="btn btn-primary" type="submit">Sendung speichern</button></div>
  </form>

  <h3 style="margin:18px 0 6px">Notiz</h3>
  <form method="post">
    <input type="hidden" name="aktion" value="pop_notiz">
    <textarea name="notiz" rows="2" style="width:100%" placeholder="nur intern"><?= h((string)($b['notiz'] ?? '')) ?></textarea>
    <div class="bx-row" style="justify-content:flex-end;gap:8px;margin-top:8px"><a class="btn btn-ghost" href="?p=bestellung&id=<?= $id ?>">Ganze Bestellung öffnen</a><button class="btn btn-primary" type="submit">Notiz speichern</button></div>
  </form>
</div>
<?php render_footer();
