<?php
// Lager 2 (Fremdlager) – Fertigwaren-Bestand der Fulfillment-Kunden. Brücke zum Versandsystem über BSKU / Shopify-inventory_item_id.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

// BSKU vergeben
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'bsku') {
    $iid = (int)($_POST['item_id'] ?? 0);
    if ($iid && scalar("SELECT COUNT(*) FROM item WHERE id=? AND kategorie='verkaufsfertig'", [$iid])) bsku_ensure($iid);
    header('Location: ?p=lager2&gespeichert=1'); exit;
}
// Shopify-Verknüpfung (inventory_item_id) speichern
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'iid_save') {
    $iid = (int)($_POST['item_id'] ?? 0);
    $val = trim($_POST['inventory_item_id'] ?? '');
    if ($iid) q("UPDATE item SET shopify_inventory_item_id=? WHERE id=? AND kategorie='verkaufsfertig'", [$val ?: null, $iid]);
    header('Location: ?p=lager2&gespeichert=1'); exit;
}
// Fulfillment-Artikel abrufen (Richtung B: Dashboard zieht direkt)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'ff_pull') {
    $r = ff_feed_pull();
    header('Location: ?p=lager2&' . (!empty($r['ok']) ? 'ffok=' . (int)($r['count'] ?? 0) : 'fffehler=' . rawurlencode($r['error'] ?? 'Fehler'))); exit;
}
// Manuelle Einbuchung
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'einbuchen') {
    $pid   = (int)($_POST['produkt_id'] ?? 0);
    $menge = (float)str_replace(',', '.', $_POST['menge'] ?? '0');
    $ff    = $pid ? (bool) scalar("SELECT k.nutzt_fulfillment FROM produkt p JOIN kunden k ON k.id=p.kunde_id WHERE p.id=?", [$pid]) : false;
    if ($pid && $ff && $menge > 0) lager2_einbuchen($pid, $menge, trim($_POST['charge'] ?? '') ?: null, trim($_POST['mhd'] ?? '') ?: null, 'Manuelle Fremdlager-Einbuchung');
    header('Location: ?p=lager2&eingebucht=1'); exit;
}

// Energetisierungs-Startdatum je Charge setzen/ändern/entfernen. Bewusst OHNE Admin-Sperre: Admin UND
// Lager-Mitarbeiter (die diese Seite ohnehin öffnen dürfen) sollen anpassen können, ab wann sie läuft.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['aktion'] ?? ''), ['energ_set', 'energ_clear'], true)) {
    $cid = (int)($_POST['charge_id'] ?? 0);
    // Sicherheit: nur Chargen von Kunden mit Energetisierungs-Freigabe (sonst keine Wirkung).
    $ok = $cid && (int) scalar("SELECT COUNT(*) FROM charge c
                                JOIN item i ON i.id=c.item_id AND i.kategorie='verkaufsfertig'
                                JOIN produkt p ON p.id=i.produkt_id JOIN kunden k ON k.id=p.kunde_id
                                WHERE c.id=? AND k.zeige_energetisierung=1", [$cid]) > 0;
    if ($ok) {
        if (($_POST['aktion'] ?? '') === 'energ_clear') {
            q("UPDATE charge SET energetisiert_am=NULL, energetisiert_von=NULL WHERE id=?", [$cid]);
        } else {
            $d = trim((string)($_POST['energ_datum'] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $wer = (function_exists('current_user') && ($cu = current_user())) ? (string)($cu['name'] ?? '') : '';
                q("UPDATE charge SET energetisiert_am=?, energetisiert_von=? WHERE id=?", [$d . ' 00:00:00', $wer !== '' ? $wer : 'Team', $cid]);
            }
        }
    }
    header('Location: ?p=lager2&energ=1'); exit;
}

$produkte = lager2_produkte();
// Chargen der Energetisierungs-Kunden (Fulfillment) mit Startdatum – wie im Kundenportal (frei + verfügbar).
$energChargen = all("SELECT c.id, c.charge_nr, c.menge_verfuegbar, c.mhd, c.energetisiert_am,
                            COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt_name, p.nummer AS produkt_nr, k.firma AS kunde
                     FROM charge c
                     JOIN item i ON i.id=c.item_id AND i.kategorie='verkaufsfertig'
                     JOIN produkt p ON p.id=i.produkt_id
                     JOIN kunden k ON k.id=p.kunde_id
                     WHERE k.zeige_energetisierung=1 AND c.status='frei' AND c.menge_verfuegbar>0
                     ORDER BY k.firma, p.nummer, c.id");
// Fulfillment-Kunden + deren Produkte (auch ohne bisherigen Bestand) für die Einbuchung
$ffProdukte = all("SELECT p.id, p.nummer, COALESCE(NULLIF(p.kundenname,''),p.name) AS anzeigename, k.firma AS kunde
                   FROM produkt p JOIN kunden k ON k.id=p.kunde_id
                   WHERE k.nutzt_fulfillment=1 ORDER BY k.firma, p.nummer");
$ffKunden = (int) scalar("SELECT COUNT(*) FROM kunden WHERE nutzt_fulfillment=1");
$mfmt = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');

render_header('lager2', 'Fremdlager');
bx_head('Fremdlager', $ffKunden . ' Fulfillment-Kunde(n) · ' . count($produkte) . ' Produkt(e)',
        bx_btn('+ Externes Produkt (Kundenware)', '?p=produkt&neu=1&extern=1', 'primary') . ' ' . bx_btn('Zum Warenlager', '?p=lager', 'ghost'));

if (isset($_GET['gespeichert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
if (isset($_GET['eingebucht'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Fertigware ins Fremdlager eingebucht.</div>';
if (isset($_GET['energ'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Energetisierung aktualisiert.</div>';

if (!$ffKunden) {
    echo '<div class="bx-panel"><div class="muted">Noch kein Kunde als Fulfillment-Kunde markiert. Im <a href="?p=kunden">Kunden</a>-Datensatz „Nutzt unser Fulfillment (Fremdlager)" setzen – dann erscheint dessen Fertigware hier.</div></div>';
    render_footer(); return;
}
?>
<div class="bx-panel">
  <h2 style="margin:0 0 4px">Fertigwaren-Bestand</h2>
  <p class="muted" style="margin:0;font-size:13px">Ware der Fulfillment-Kunden. Die <strong>BSKU</strong> ist die Brücke zum Versandsystem (dort per Dropdown dem Shop-Artikel zugeordnet) und dient als interne Nummer fürs Kisten-Etikett.</p>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Kunde</th><th>Produkt (Etikettenname)</th><th>BSKU</th><th class="bx-num">Bestand (frei)</th></tr></thead>
    <tbody>
      <?php if (!$produkte): ?><tr><td colspan="4" class="muted">Noch keine Fertigware eingebucht. Unten „Fertigware einbuchen" nutzen oder einen Produktionsauftrag abschließen.</td></tr><?php endif; ?>
      <?php foreach ($produkte as $r): ?>
        <tr>
          <td><?= h($r['kunde']) ?></td>
          <td><?= h($r['anzeigename']) ?><div class="muted" style="font-size:11px"><?= h($r['produkt_nr']) ?><?= $r['name'] !== $r['anzeigename'] ? ' · ' . h($r['name']) : '' ?></div></td>
          <td>
            <?php if ($r['bsku']): ?><span style="font-family:monospace;font-weight:600"><?= h($r['bsku']) ?></span>
            <?php else: ?><button class="btn btn-ghost btn-sm" type="submit" form="bsku<?= (int)$r['item_id'] ?>">BSKU vergeben</button><?php endif; ?>
          </td>
          <td class="bx-num"><strong><?= $mfmt($r['bestand']) ?></strong> Stück</td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php foreach ($produkte as $r): ?>
  <form id="bsku<?= (int)$r['item_id'] ?>" method="post" style="display:none"><input type="hidden" name="aktion" value="bsku"><input type="hidden" name="item_id" value="<?= (int)$r['item_id'] ?>"></form>
<?php endforeach; ?>

<?php if ($energChargen): ?>
<div class="bx-panel">
  <h2 style="margin:0 0 4px">Energetisierung</h2>
  <p class="muted" style="margin:0 0 10px;font-size:13px">Startdatum je Charge – ab diesem Tag läuft die Energetisierung <?= energ_tage() ?> Tage, dann „abgeschlossen". Beim Einbuchen wird automatisch der aktuelle Tag gesetzt; hier kannst du (Admin oder Lager) anpassen, <strong>ab wann</strong> sie läuft, oder sie entfernen. Der Kunde sieht den Stand in „Mein Lager".</p>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Kunde</th><th>Produkt</th><th>Charge</th><th class="bx-num">Bestand</th><th>Startdatum</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($energChargen as $c): $start = (string)($c['energetisiert_am'] ?? ''); $dval = $start !== '' ? date('Y-m-d', strtotime($start)) : ''; ?>
      <tr>
        <td><?= h($c['kunde']) ?></td>
        <td><?= h($c['produkt_name']) ?><div class="muted" style="font-size:11px"><?= h($c['produkt_nr']) ?></div></td>
        <td><?= $c['charge_nr'] ? h($c['charge_nr']) : '<span class="muted">ohne</span>' ?></td>
        <td class="bx-num"><?= $mfmt($c['menge_verfuegbar']) ?> Stk</td>
        <td>
          <form method="post" class="bx-row" style="gap:6px;align-items:center;margin:0">
            <input type="hidden" name="aktion" value="energ_set"><input type="hidden" name="charge_id" value="<?= (int)$c['id'] ?>">
            <input type="date" name="energ_datum" value="<?= h($dval) ?>" required>
            <button class="btn btn-ghost btn-sm" type="submit">Speichern</button>
          </form>
        </td>
        <td style="font-size:13px"><?= $start !== '' ? h(energ_text_kurz($start)) : '<span class="muted">nicht gestartet</span>' ?></td>
        <td class="bx-num"><?php if ($start !== ''): ?><form method="post" style="margin:0" onsubmit="return confirm('Energetisierung für diese Charge entfernen?');"><input type="hidden" name="aktion" value="energ_clear"><input type="hidden" name="charge_id" value="<?= (int)$c['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Entfernen</button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<form method="post" class="bx-form" style="margin-top:16px">
  <input type="hidden" name="aktion" value="einbuchen">
  <div class="bx-panel">
    <h2 style="margin-top:0">Fertigware einbuchen</h2>
    <p class="muted" style="margin-top:0;font-size:13px">Manuell Bestand ins Fremdlager legen (z.&nbsp;B. Erstbestand, Korrektur oder <strong>externe Kundenware</strong>). Bei Produktionsabschluss passiert das für Fulfillment-Kunden automatisch. Externe Produkte vorher über „+ Externes Produkt (Kundenware)" anlegen. <strong>Bei freigeschalteten Kunden wird jede Einlagerung automatisch energetisiert</strong> (der Kunde sieht es in „Mein Lager").</p>
    <div class="bx-grid">
      <div class="bx-field"><label>Produkt</label>
        <select name="produkt_id" required>
          <option value="">– wählen –</option>
          <?php foreach ($ffProdukte as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['kunde']) ?> · <?= h($p['anzeigename']) ?> (<?= h($p['nummer']) ?>)</option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Menge (Stück)</label><input type="number" step="1" min="1" name="menge" required placeholder="z. B. 500"></div>
      <div class="bx-field"><label>Charge (optional)</label><input type="text" name="charge" placeholder="z. B. BF26801"></div>
      <div class="bx-field"><label>MHD (optional)</label><input type="date" name="mhd"></div>
    </div>
    <div class="bx-row" style="margin-top:var(--sp-4)"><button class="btn btn-primary" type="submit">Einbuchen</button></div>
  </div>
</form>
<?php render_footer();
