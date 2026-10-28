<?php
// Warenarten prüfen (Teil 3 Block K) – der „Fertigware"-Topf sauber in Bulkware vs. Fertigprodukt getrennt.
// Die DB-WERTE bleiben: 'fertig' = Bulkware (lose, mit Rezeptur), 'verkaufsfertig' = Fertigprodukt (abgefüllt+etikettiert, mit Produkt).
// Diese Seite klassifiziert vorhandene Artikel anhand echter Signale (item.produkt_id / item.rezeptur_id) und
// schlägt Korrekturen vor. Angewendet wird nur auf Klick, je Artikel ein gezieltes UPDATE – nie ein pauschales Update.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

// Vorschlag je Artikel: eindeutiges Signal -> Zielkategorie; sonst 'unklar'.
$vorschlag = function (array $it): array {
    $kat  = (string)($it['kategorie'] ?? '');
    $prod = (int)($it['produkt_id'] ?? 0) > 0;     // mit Produkt verknüpft -> verkaufsfertig (Fertigprodukt)
    $rez  = (int)($it['rezeptur_id'] ?? 0) > 0;    // mit Rezeptur verknüpft -> fertig (Bulkware)
    if ($prod && !$rez)      $soll = 'verkaufsfertig';
    elseif ($rez && !$prod)  $soll = 'fertig';
    else                     $soll = '';           // beides oder keins -> nicht automatisch entscheidbar
    if ($soll === '')        return ['ziel'=>'', 'status'=>'unklar',
                                     'grund'=> ($prod && $rez) ? 'Produkt UND Rezeptur verknüpft' : 'weder Produkt noch Rezeptur verknüpft'];
    if ($soll === $kat)      return ['ziel'=>$kat, 'status'=>'ok',
                                     'grund'=> $soll === 'fertig' ? 'Rezeptur verknüpft → Bulkware' : 'Produkt verknüpft → Fertigprodukt'];
    return ['ziel'=>$soll, 'status'=>'korrektur',
            'grund'=> $soll === 'verkaufsfertig' ? 'hat Produkt, keine Rezeptur → Fertigprodukt' : 'hat Rezeptur, kein Produkt → Bulkware'];
};

// Einen Artikel sicher umklassifizieren (nur zwischen den beiden Töpfen, nur gültiges Ziel).
$setzen = function (int $itemId, string $ziel): bool {
    if ($itemId <= 0 || !in_array($ziel, ['fertig','verkaufsfertig'], true)) return false;
    q("UPDATE item SET kategorie=? WHERE id=? AND kategorie IN ('fertig','verkaufsfertig')", [$ziel, $itemId]);
    return true;
};

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    if ($aktion === 'einzeln') {
        $ok = $setzen((int)($_POST['item_id'] ?? 0), (string)($_POST['ziel'] ?? ''));
        header('Location: ?p=warenart_pruefung' . ($ok ? '&ok=1' : '&err=1')); exit;
    }
    if ($aktion === 'alle_vorschlaege') {
        // Serverseitig neu berechnen (POST-Liste wird nicht vertraut) und alle eindeutigen Korrekturen anwenden.
        $n = 0;
        foreach (all("SELECT id, kategorie, produkt_id, rezeptur_id FROM item WHERE kategorie IN ('fertig','verkaufsfertig')") as $it) {
            $v = $vorschlag($it);
            if ($v['status'] === 'korrektur' && $setzen((int)$it['id'], $v['ziel'])) $n++;
        }
        header('Location: ?p=warenart_pruefung&alle=' . $n); exit;
    }
}

// Alle Artikel der beiden Töpfe laden und klassifizieren.
$items = all("SELECT i.id, i.name, i.artikelnummer, i.kategorie, i.produkt_id, i.rezeptur_id,
                     p.name AS produkt_name, r.name AS rezeptur_name
              FROM item i
              LEFT JOIN produkt p ON p.id=i.produkt_id
              LEFT JOIN rezeptur r ON r.id=i.rezeptur_id
              WHERE i.kategorie IN ('fertig','verkaufsfertig')
              ORDER BY i.name");
$korrektur = []; $unklar = []; $okBulk = 0; $okFertig = 0;
foreach ($items as $it) {
    $v = $vorschlag($it);
    if      ($v['status'] === 'korrektur') $korrektur[] = $it + ['_v'=>$v];
    elseif  ($v['status'] === 'unklar')    $unklar[]    = $it + ['_v'=>$v];
    else { if ((string)$it['kategorie'] === 'fertig') $okBulk++; else $okFertig++; }
}

render_header('warenart_pruefung', 'Warenarten prüfen');
bx_head('Warenarten prüfen', 'Bulkware (lose, mit Rezeptur) vs. Fertigprodukt (abgefüllt + etikettiert, mit Produkt)',
    bx_hint('Vorschläge stützen sich auf echte Verknüpfungen: Rezeptur → Bulkware, Produkt → Fertigprodukt. Angewendet wird nur auf deinen Klick, je Artikel einzeln.'));

if (isset($_GET['ok']))   echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Warenart geändert.</div>';
if (isset($_GET['err']))  echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Konnte nicht ändern (ungültiges Ziel).</div>';
if (isset($_GET['alle'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . (int)$_GET['alle'] . ' Artikel automatisch korrigiert.</div>';

$katBadge = fn($k) => $k === 'fertig' ? bx_badge('Bulkware','info') : ($k === 'verkaufsfertig' ? bx_badge('Fertigprodukt','ok') : bx_badge(kategorie_label($k)));
?>
<div class="bx-panel">
  <div class="bx-row" style="gap:18px;flex-wrap:wrap">
    <div><div class="k muted">Bulkware (ok)</div><div style="font-size:20px"><?= number_format($okBulk,0,',','.') ?></div></div>
    <div><div class="k muted">Fertigprodukte (ok)</div><div style="font-size:20px"><?= number_format($okFertig,0,',','.') ?></div></div>
    <div><div class="k muted">Korrekturvorschläge</div><div style="font-size:20px;color:#8a6d00"><?= number_format(count($korrektur),0,',','.') ?></div></div>
    <div><div class="k muted">Unklar</div><div style="font-size:20px"><?= number_format(count($unklar),0,',','.') ?></div></div>
  </div>
</div>

<?php if ($korrektur): ?>
<div class="bx-panel">
  <div class="bx-row" style="align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
    <h2 style="margin:0">Korrekturvorschläge</h2>
    <form method="post" style="margin:0" onsubmit="return confirm('Alle <?= count($korrektur) ?> eindeutigen Vorschläge übernehmen?');">
      <input type="hidden" name="aktion" value="alle_vorschlaege">
      <button class="btn btn-primary btn-sm" type="submit">Alle Vorschläge übernehmen</button>
    </form>
  </div>
  <div class="muted" style="font-size:12px;margin:6px 0 10px">Eindeutige Fälle: genau eine Verknüpfung gesetzt, die der aktuellen Warenart widerspricht.</div>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Artikel</th><th>aktuell</th><th>Vorschlag</th><th>Grund</th><th>Aktion</th></tr></thead>
    <tbody>
    <?php foreach ($korrektur as $it): ?>
      <tr>
        <td><?= h((string)$it['name']) ?><?= $it['artikelnummer'] ? ' <span class="muted">· ' . h((string)$it['artikelnummer']) . '</span>' : '' ?>
            <?php if ($it['produkt_name']): ?><div class="muted" style="font-size:12px">Produkt: <?= h((string)$it['produkt_name']) ?></div><?php endif; ?>
            <?php if ($it['rezeptur_name']): ?><div class="muted" style="font-size:12px">Rezeptur: <?= h((string)$it['rezeptur_name']) ?></div><?php endif; ?></td>
        <td><?= $katBadge((string)$it['kategorie']) ?></td>
        <td><?= $katBadge((string)$it['_v']['ziel']) ?></td>
        <td class="muted" style="font-size:12px"><?= h((string)$it['_v']['grund']) ?></td>
        <td>
          <form method="post" style="margin:0">
            <input type="hidden" name="aktion" value="einzeln">
            <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
            <input type="hidden" name="ziel" value="<?= h((string)$it['_v']['ziel']) ?>">
            <button class="btn btn-ghost btn-sm" type="submit">Übernehmen</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php if ($unklar): ?>
<div class="bx-panel">
  <h2 style="margin-top:0">Unklar – bitte manuell zuordnen</h2>
  <div class="muted" style="font-size:12px;margin-bottom:10px">Weder eindeutig Bulkware noch Fertigprodukt (beides oder nichts verknüpft). Hier entscheidest du.</div>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Artikel</th><th>aktuell</th><th>Grund</th><th>Als Bulkware</th><th>Als Fertigprodukt</th></tr></thead>
    <tbody>
    <?php foreach ($unklar as $it): ?>
      <tr>
        <td><?= h((string)$it['name']) ?><?= $it['artikelnummer'] ? ' <span class="muted">· ' . h((string)$it['artikelnummer']) . '</span>' : '' ?></td>
        <td><?= $katBadge((string)$it['kategorie']) ?></td>
        <td class="muted" style="font-size:12px"><?= h((string)$it['_v']['grund']) ?></td>
        <td>
          <form method="post" style="margin:0"><input type="hidden" name="aktion" value="einzeln"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>"><input type="hidden" name="ziel" value="fertig">
            <button class="btn btn-ghost btn-sm" type="submit" <?= (string)$it['kategorie']==='fertig' ? 'disabled style="opacity:.5"' : '' ?>>→ Bulkware</button></form>
        </td>
        <td>
          <form method="post" style="margin:0"><input type="hidden" name="aktion" value="einzeln"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>"><input type="hidden" name="ziel" value="verkaufsfertig">
            <button class="btn btn-ghost btn-sm" type="submit" <?= (string)$it['kategorie']==='verkaufsfertig' ? 'disabled style="opacity:.5"' : '' ?>>→ Fertigprodukt</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php if (!$korrektur && !$unklar): ?>
<div class="bx-panel"><div class="muted">Alles sauber getrennt: <?= number_format($okBulk,0,',','.') ?> Bulkware, <?= number_format($okFertig,0,',','.') ?> Fertigprodukte. Kein Handlungsbedarf.</div></div>
<?php endif; ?>
<?php render_footer();
