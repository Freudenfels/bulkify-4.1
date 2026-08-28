<?php
// Einkaufsbedarf – Prüfen & Melden: je Kundenauftrag Produktionsart (eigen/fremd) festlegen und den Bedarf ans Einkauf melden.
// Danach arbeitet der Einkäufer die „Einkaufsliste" (?p=einkaufsliste) ab.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? '';
    $paId = (int)($_POST['pa_id'] ?? 0);
    if ($aktion === 'produktionsart' && $paId) {
        $art = ($_POST['produktionsart'] ?? 'eigen') === 'fremd' ? 'fremd' : 'eigen';
        q("UPDATE produktionsauftrag SET produktionsart=? WHERE id=?", [$art, $paId]);
    } elseif ($aktion === 'melden' && $paId) {
        q("UPDATE produktionsauftrag SET bedarf_gemeldet=? WHERE id=?", [gmdate('Y-m-d H:i:s'), $paId]);
        $nr = scalar("SELECT a.nummer FROM produktionsauftrag pa LEFT JOIN auftrag a ON a.id=pa.auftrag_id WHERE pa.id=?", [$paId]);
        header('Location: ?p=bedarf&gemeldet=' . urlencode((string)$nr)); exit;
    } elseif ($aktion === 'melden_zurueck' && $paId) {
        q("UPDATE produktionsauftrag SET bedarf_gemeldet=NULL WHERE id=?", [$paId]);
        header('Location: ?p=bedarf&zurueckgenommen=1'); exit;
    }
    header('Location: ?p=bedarf'); exit;
}

$tab = ($_GET['tab'] ?? '') === 'uebergeben' ? 'uebergeben' : 'offen';   // Standard: noch nicht gemeldet
$alle = all("SELECT pa.*, a.nummer AS auftrag_nr, COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt, k.firma AS kunde
             FROM produktionsauftrag pa
             LEFT JOIN auftrag a ON a.id=pa.auftrag_id
             LEFT JOIN produkt p ON p.id=pa.produkt_id
             LEFT JOIN kunden k ON k.id=pa.kunde_id
             WHERE pa.status IN ('offen','laufend') AND pa.auftrag_id IS NOT NULL
             ORDER BY pa.prio, pa.angelegt");
// Reiter aufteilen: „offen" = noch nicht gemeldet; „übergeben" = gemeldet & noch nicht komplett bestellt
$offenPas = []; $uebergebenPas = [];
foreach ($alle as $pa) {
    if (empty($pa['bedarf_gemeldet'])) $offenPas[] = $pa;
    elseif (auftrag_offener_bedarf((int)$pa['id'])) $uebergebenPas[] = $pa;
}
$pas = $tab === 'uebergeben' ? $uebergebenPas : $offenPas;

render_header('bedarf', 'Einkaufsbedarf');
bx_head('Einkaufsbedarf', 'Prüfen (Eigen-/Fremdproduktion) und an den Einkauf melden.',
        bx_btn('Zur Einkaufsliste' . (count($uebergebenPas) ? ' (' . count($uebergebenPas) . ')' : ''), '?p=einkaufsliste', 'ghost'));
?>
<div class="settabs" style="margin:0 0 16px">
  <a href="?p=bedarf" class="<?= $tab === 'offen' ? 'on' : '' ?>">Noch nicht gemeldet<?= $offenPas ? ' (' . count($offenPas) . ')' : '' ?></a>
  <a href="?p=bedarf&tab=uebergeben" class="<?= $tab === 'uebergeben' ? 'on' : '' ?>">Übergeben<?= $uebergebenPas ? ' (' . count($uebergebenPas) . ')' : '' ?></a>
</div>
<?php
if (isset($_GET['zurueck'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Entwurf verworfen – der Bedarf steht wieder hier.</div>';
if (isset($_GET['gemeldet'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Bedarf für <strong>' . h($_GET['gemeldet']) . '</strong> an den Einkauf gemeldet – er erscheint jetzt in der <a href="?p=einkaufsliste">Einkaufsliste</a>.</div>';
if (isset($_GET['zurueckgenommen'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Meldung zurückgenommen.</div>';

$mfmt = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
?>
<?php if (!$pas): ?>
  <div class="bx-panel"><div class="muted"><?= $tab === 'uebergeben' ? 'Nichts an den Einkauf übergeben (bzw. schon alles bestellt).' : 'Kein offener Bedarf – alles gemeldet.' ?></div></div>
<?php else: foreach ($pas as $pa):
    $gemeldet = !empty($pa['bedarf_gemeldet']);
    $fremd    = ($pa['produktionsart'] ?? 'eigen') === 'fremd';
    $bedarf   = auftrag_bedarf((int)$pa['id']);                        // komplette Stückliste (bei Fremd: Bulk-Zukauf + Verpackung/Etiketten)
    $hatFehl  = false; foreach ($bedarf as $bb) if ((float)$bb['fehlt'] > 1e-6) { $hatFehl = true; break; }
?>
  <div class="bx-panel"<?= $gemeldet ? ' style="border-color:var(--gruen)"' : '' ?>>
    <div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:10px">
      <div>
        <a href="?p=produktionsauftrag&id=<?= (int)$pa['id'] ?>"><strong><?= h($pa['auftrag_nr'] ?: $pa['nummer']) ?></strong></a>
        · <?= h($pa['produkt'] ?: '–') ?><?= $pa['kunde'] ? ' · ' . h($pa['kunde']) : '' ?>
        <span class="muted">· <?= (int)$pa['menge'] ?> Packungen</span>
        <?= prio_badge((int)($pa['prio'] ?? 2)) ?>
        <?= $gemeldet ? bx_badge('an Einkauf gemeldet','ok') : bx_badge('noch nicht gemeldet','warn') ?>
      </div>
      <div class="bx-row" style="gap:8px;align-items:center">
        <form method="post" style="margin:0"><input type="hidden" name="aktion" value="produktionsart"><input type="hidden" name="pa_id" value="<?= (int)$pa['id'] ?>">
          <select name="produktionsart" onchange="this.form.submit()" title="Machen wir es selbst oder kaufen wir das fertige Produkt zu?">
            <option value="eigen" <?= !$fremd ? 'selected' : '' ?>>Eigenproduktion</option>
            <option value="fremd" <?= $fremd ? 'selected' : '' ?>>Fremdproduktion (zukaufen)</option>
          </select>
        </form>
        <?php if (!$gemeldet): ?>
          <form method="post" style="margin:0"><input type="hidden" name="aktion" value="melden"><input type="hidden" name="pa_id" value="<?= (int)$pa['id'] ?>">
            <button class="btn btn-primary btn-sm" type="submit">An Einkauf melden</button></form>
        <?php else: ?>
          <span class="muted" style="font-size:12px">gemeldet <?= h(fmt_zeit($pa['bedarf_gemeldet'], 'd.m.Y')) ?></span>
          <form method="post" style="margin:0"><input type="hidden" name="aktion" value="melden_zurueck"><input type="hidden" name="pa_id" value="<?= (int)$pa['id'] ?>">
            <button class="btn btn-ghost btn-sm" type="submit">zurücknehmen</button></form>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($fremd): ?>
      <div class="muted" style="margin-top:8px">Fremdproduktion: der <strong>Bulk (Kapseln/Tabletten/Pulver)</strong> wird zugekauft – <strong>Verpackung und Etiketten werden trotzdem gebraucht</strong> und sind hier gelistet.</div>
    <?php endif; ?>
    <?php if ($bedarf): ?>
      <div class="bx-tablewrap" style="margin-top:10px"><table class="bx-table">
        <thead><tr><th>Komponente (Stückliste)</th><th></th><th class="bx-num">Benötigt</th><th class="bx-num">Auf Lager</th><th class="bx-num">Fehlt</th></tr></thead>
        <tbody>
          <?php foreach ($bedarf as $f): $fehlt = (float)$f['fehlt']; ?>
            <tr>
              <td><?= h($f['name']) ?></td>
              <td><?= bx_badge($f['rolle']) ?></td>
              <td class="bx-num"><?= $mfmt($f['benoetigt']) ?> <?= h($f['einheit']) ?></td>
              <td class="bx-num"><?= $mfmt($f['verfuegbar']) ?> <?= h($f['einheit']) ?></td>
              <td class="bx-num"><?= $fehlt > 1e-6 ? '<strong style="color:#8f231b">' . $mfmt($fehlt) . ' ' . h($f['einheit']) . '</strong>' : '<span class="bx-ok">✓ auf Lager</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php if (!$hatFehl): ?><div class="muted" style="margin-top:8px"><span class="bx-ok">Material vollständig auf Lager</span> – nichts zu bestellen.</div><?php endif; ?>
    <?php endif; ?>
  </div>
<?php endforeach; endif; ?>
<?php render_footer(); ?>
