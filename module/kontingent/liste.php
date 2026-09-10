<?php
// Kontingente / Rahmenverträge (Jahresverträge): Kunde ruft aus einer vereinbarten Gesamtmenge
// zum Festpreis Teilmengen ab. Diese Seite legt Kontingente an und zeigt den Verbrauch.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? '';
    if ($aktion === 'neu') {
        $kunde  = (int)($_POST['kunde_id'] ?? 0);
        $prod   = (int)($_POST['produkt_id'] ?? 0);
        $menge  = max(0, (int)($_POST['gesamt_menge'] ?? 0));
        $vk     = round((float) str_replace(',', '.', (string)($_POST['vk_stueck'] ?? '0')), 4);
        if ($kunde <= 0 || $prod <= 0 || $menge <= 0) { header('Location: ?p=kontingente&fehler=' . urlencode('Kunde, Produkt und Menge sind Pflicht.')); exit; }
        q("INSERT INTO kontingent (kunde_id,produkt_id,gesamt_menge,vk_stueck,gueltig_von,gueltig_bis,notiz,status)
           VALUES (?,?,?,?,?,?,?,'aktiv')",
          [$kunde, $prod, $menge, $vk,
           trim((string)($_POST['gueltig_von'] ?? '')) ?: null,
           trim((string)($_POST['gueltig_bis'] ?? '')) ?: null,
           trim((string)($_POST['notiz'] ?? '')) ?: null]);
        header('Location: ?p=kontingente&ok=1'); exit;
    }
    if ($aktion === 'beenden') {
        q("UPDATE kontingent SET status='beendet' WHERE id=?", [(int)($_POST['id'] ?? 0)]);
        header('Location: ?p=kontingente&ok=1'); exit;
    }
    if ($aktion === 'aktivieren') {
        q("UPDATE kontingent SET status='aktiv' WHERE id=?", [(int)($_POST['id'] ?? 0)]);
        header('Location: ?p=kontingente&ok=1'); exit;
    }
}

$rows = all("SELECT k.*, kd.firma AS kunde, COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt,
                    (SELECT id FROM dokument d WHERE d.objekt_typ='kontingent' AND d.objekt_id=k.id AND d.typ='jv_signiert' ORDER BY d.id DESC LIMIT 1) AS sig_dok
             FROM kontingent k LEFT JOIN kunden kd ON kd.id=k.kunde_id LEFT JOIN produkt p ON p.id=k.produkt_id
             ORDER BY (k.status='wartet_freigabe') DESC, (k.status='aktiv') DESC, k.angelegt DESC");
$statusBadge = fn($s) => match ($s) {
    'aktiv'           => bx_badge('aktiv', 'ok'),
    'wartet_vertrag'  => bx_badge('wartet auf Vertrag', 'info'),
    'wartet_freigabe' => bx_badge('Vertrag prüfen', 'warn'),
    'beendet'         => bx_badge('beendet', 'info'),
    default           => bx_badge((string)$s),
};
$kunden   = all("SELECT id, firma FROM kunden WHERE gesperrt=0 ORDER BY firma");
$produkte = all("SELECT id, COALESCE(NULLIF(kundenname,''), name) AS name FROM produkt ORDER BY name");
$eur = fn($x) => number_format((float)$x, 4, ',', '.');

render_header('kontingente', 'Kontingente');
bx_head('Kontingente / Jahresverträge', count($rows) . ' Verträge', bx_hint('Rahmenvertrag: Kunde ruft aus einer vereinbarten Gesamtmenge zum Festpreis ab. Jeder Abruf im Kundenportal erzeugt einen Auftrag und senkt den Rest.'));
if (isset($_GET['ok']))     echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
if (isset($_GET['fehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['fehler']) . '</div>';
?>
<div class="bx-panel">
  <?php if (!$rows): ?><div class="muted">Noch keine Kontingente. Unten anlegen.</div>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Kunde</th><th>Produkt</th><th class="bx-num">vereinbart</th><th class="bx-num">abgerufen</th><th class="bx-num">Rest</th><th class="bx-num">VK / Pkg</th><th>gültig bis</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $rest = (int)$r['gesamt_menge'] - (int)$r['abgerufen']; $abgelaufen = !empty($r['gueltig_bis']) && (string)$r['gueltig_bis'] < gmdate('Y-m-d'); ?>
      <tr>
        <td><?= kunde_link($r['kunde_id'] ?? null, $r['kunde']) ?></td>
        <td><?= h($r['produkt'] ?: '–') ?></td>
        <td class="bx-num"><?= number_format((int)$r['gesamt_menge'], 0, ',', '.') ?></td>
        <td class="bx-num"><?= number_format((int)$r['abgerufen'], 0, ',', '.') ?></td>
        <td class="bx-num"><strong><?= number_format($rest, 0, ',', '.') ?></strong></td>
        <td class="bx-num"><?= $eur($r['vk_stueck']) ?> &euro;</td>
        <td><?= $r['gueltig_bis'] ? h(date('d.m.Y', strtotime((string)$r['gueltig_bis']))) . ($abgelaufen ? ' <span style="color:#8f231b;font-size:12px">abgelaufen</span>' : '') : '<span class="muted">–</span>' ?></td>
        <td><?= $statusBadge($r['status']) ?></td>
        <td class="bx-num" style="white-space:nowrap">
          <?php if ($r['sig_dok']): ?><a class="btn btn-ghost btn-sm" target="_blank" href="?p=dokument&id=<?= (int)$r['sig_dok'] ?>">Vertrag</a><?php endif; ?>
          <form method="post" style="margin:0;display:inline">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <?php if ($r['status'] === 'wartet_freigabe'): ?><button class="btn btn-primary btn-sm" type="submit" name="aktion" value="aktivieren" title="Unterschriebenen Vertrag geprüft – Kontingent aktivieren">freigeben</button>
          <?php elseif ($r['status'] === 'wartet_vertrag'): ?><span class="muted" style="font-size:12px">wartet auf Kunde</span>
          <?php elseif ($r['status'] === 'aktiv'): ?><button class="btn btn-ghost btn-sm" type="submit" name="aktion" value="beenden">beenden</button>
          <?php else: ?><button class="btn btn-ghost btn-sm" type="submit" name="aktion" value="aktivieren">aktivieren</button><?php endif; ?>
        </form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0">Neues Kontingent</h2>
  <form method="post"><input type="hidden" name="aktion" value="neu">
    <div class="bx-grid">
      <div class="bx-field"><label>Kunde</label><select name="kunde_id" required><option value="">– wählen –</option>
        <?php foreach ($kunden as $k): ?><option value="<?= (int)$k['id'] ?>"><?= h($k['firma']) ?></option><?php endforeach; ?></select></div>
      <div class="bx-field"><label>Produkt</label><select name="produkt_id" required><option value="">– wählen –</option>
        <?php foreach ($produkte as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?></select></div>
      <div class="bx-field" style="max-width:160px"><label>Gesamtmenge (Packungen)</label><input type="number" name="gesamt_menge" min="1" required></div>
      <div class="bx-field" style="max-width:140px"><label>VK je Packung (netto)</label><input type="text" name="vk_stueck" placeholder="z. B. 0,84"></div>
      <div class="bx-field" style="max-width:160px"><label>Gültig von</label><input type="date" name="gueltig_von"></div>
      <div class="bx-field" style="max-width:160px"><label>Gültig bis</label><input type="date" name="gueltig_bis"></div>
    </div>
    <div class="bx-field"><label>Notiz</label><input type="text" name="notiz" placeholder="Vertragsnummer, Konditionen …"></div>
    <button class="btn btn-primary" type="submit">Kontingent anlegen</button>
  </form>
  <p class="muted" style="font-size:12px;margin-top:8px">Der Kunde sieht aktive Kontingente in seinem Portal und ruft dort seine Mengen ab – jeder Abruf wird ein Auftrag zum vereinbarten Preis, der Rest sinkt automatisch.</p>
</div>
<?php render_footer();
