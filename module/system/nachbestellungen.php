<?php
// Nachbestellungen (Eingang) – die vom Kunden verbindlich ausgelösten Nachbestellungen zur kurzen PREISPRÜFUNG.
// 98 %: ein Klick „Preis bestätigen & freigeben" -> Auftrag entsteht (auftrag_aus_angebot), Etikett-Freigabe aus
// dem Vorauftrag übernommen. 2 %: „Preis anpassen" -> geht als kurze Freigabe zum Kunden zurück.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/nachbestellung.php';

nachbestellung_schema();

$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
$fehler = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    $id = (int)($_POST['nb_id'] ?? 0);
    if ($aktion === 'bestaetigen') {
        $r = nachbestellung_bestaetigen($id);
        if (isset($r['fehler'])) { header('Location: ?p=nachbestellungen&fehler=' . urlencode($r['fehler'])); exit; }
        header('Location: ?p=nachbestellungen&ok=' . (int)$r['auftrag_id']); exit;
    }
    if ($aktion === 'preis_anpassen') {
        $vk = (float) str_replace(['.', ','], ['', '.'], (string)($_POST['vk_neu'] ?? '0'));
        $r = nachbestellung_preis_anpassen($id, $vk, trim((string)($_POST['grund'] ?? '')));
        header('Location: ?p=nachbestellungen' . (isset($r['fehler']) ? '&fehler=' . urlencode($r['fehler']) : '&preisok=1')); exit;
    }
}

$offen = all("SELECT n.*, k.firma AS kunde, COALESCE(NULLIF(p.name,''),'') AS produkt
              FROM nachbestellung n LEFT JOIN kunden k ON k.id=n.kunde_id LEFT JOIN produkt p ON p.id=n.produkt_id
              WHERE n.status IN ('pruefung','preis_neu') ORDER BY n.id DESC");
$erledigt = all("SELECT n.*, k.firma AS kunde, COALESCE(NULLIF(p.name,''),'') AS produkt, a.nummer AS auftrag_nr
                 FROM nachbestellung n LEFT JOIN kunden k ON k.id=n.kunde_id LEFT JOIN produkt p ON p.id=n.produkt_id
                 LEFT JOIN auftrag a ON a.id=n.auftrag_id
                 WHERE n.status='bestaetigt' ORDER BY n.id DESC LIMIT 15");

render_header('nachbestellungen', 'Nachbestellungen');
bx_head('Nachbestellungen', count($offen) . ' zu prüfen',
    bx_hint('Vom Kunden verbindlich zum zuletzt vereinbarten Preis ausgelöst. In der Regel nur Preis bestätigen und freigeben – der Auftrag entsteht dann automatisch inkl. übernommener Etikett-Freigabe.'));

if (isset($_GET['ok']))      echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Bestätigt & freigegeben – <a href="?p=auftrag&id=' . (int)$_GET['ok'] . '">Auftrag öffnen</a>.</div>';
if (isset($_GET['preisok'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Neuer Preis gesetzt – der Kunde gibt ihn im Portal frei.</div>';
if (isset($_GET['fehler']))  echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['fehler']) . '</div>';

$paket = fn($stk) => (int)$stk > 0 ? number_format((int)$stk, 0, ',', '.') . ' Stk/Pkg' : 'Pkg';
?>
<div class="bx-panel">
  <h2 style="margin-top:0">Zu prüfen</h2>
  <?php if (!$offen): ?><div class="muted">Keine offenen Nachbestellungen.</div><?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Kunde</th><th>Produkt</th><th class="bx-num">Menge</th><th class="bx-num">Preis / Pkg</th><th class="bx-num">Gesamt netto</th><th>Status / Aktion</th></tr></thead>
    <tbody>
    <?php foreach ($offen as $n): $menge = (int)$n['menge']; $vk = (float)$n['vk_stueck']; $netto = $menge * $vk; ?>
      <tr>
        <td><?= h((string)($n['kunde'] ?: ('Kunde #' . (int)$n['kunde_id']))) ?></td>
        <td><?= h((string)($n['produkt'] ?: '–')) ?> <span class="muted"><?= h($paket($n['stueck'])) ?></span></td>
        <td class="bx-num"><?= number_format($menge, 0, ',', '.') ?></td>
        <td class="bx-num"><?= $eur($vk) ?></td>
        <td class="bx-num"><?= $eur($netto) ?></td>
        <td>
          <?php if ((string)$n['status'] === 'preis_neu'): ?>
            <?= bx_badge('wartet auf Kundenfreigabe', 'warn') ?> <span class="muted" style="font-size:12px">neuer Preis <?= $eur((float)$n['vk_neu']) ?></span>
          <?php else: ?>
          <div class="bx-row" style="gap:8px;align-items:center;flex-wrap:wrap">
            <form method="post" style="margin:0" onsubmit="return confirm('Preis bestätigen und Auftrag freigeben?');">
              <input type="hidden" name="aktion" value="bestaetigen"><input type="hidden" name="nb_id" value="<?= (int)$n['id'] ?>">
              <button class="btn btn-primary btn-sm" type="submit" data-busy="…">Preis bestätigen &amp; freigeben</button>
            </form>
            <details>
              <summary class="btn btn-ghost btn-sm" style="list-style:none;cursor:pointer">Preis anpassen</summary>
              <form method="post" class="bx-row" style="gap:6px;align-items:flex-end;margin:8px 0 0;flex-wrap:wrap">
                <input type="hidden" name="aktion" value="preis_anpassen"><input type="hidden" name="nb_id" value="<?= (int)$n['id'] ?>">
                <label style="display:flex;flex-direction:column;gap:3px;font-size:12px" class="muted">Neuer Preis / Pkg (€)
                  <input type="text" name="vk_neu" inputmode="decimal" value="<?= number_format($vk, 2, ',', '.') ?>" style="width:120px;text-align:right"></label>
                <label style="display:flex;flex-direction:column;gap:3px;font-size:12px" class="muted">Hinweis an den Kunden (optional)
                  <input type="text" name="grund" style="min-width:220px" placeholder="z. B. Rohstoffpreis gestiegen"></label>
                <button class="btn btn-ghost btn-sm" type="submit">An Kunde zur Freigabe</button>
              </form>
            </details>
          </div>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php if ($erledigt): ?>
<div class="bx-panel">
  <h2 style="margin-top:0">Zuletzt freigegeben</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Kunde</th><th>Produkt</th><th class="bx-num">Menge</th><th class="bx-num">Preis / Pkg</th><th>Auftrag</th></tr></thead>
    <tbody>
    <?php foreach ($erledigt as $n): ?>
      <tr>
        <td><?= h((string)($n['kunde'] ?: ('Kunde #' . (int)$n['kunde_id']))) ?></td>
        <td><?= h((string)($n['produkt'] ?: '–')) ?></td>
        <td class="bx-num"><?= number_format((int)$n['menge'], 0, ',', '.') ?></td>
        <td class="bx-num"><?= $eur((float)$n['vk_stueck']) ?></td>
        <td><?= $n['auftrag_id'] ? '<a href="?p=auftrag&id=' . (int)$n['auftrag_id'] . '">' . h((string)($n['auftrag_nr'] ?: ('#' . (int)$n['auftrag_id']))) . '</a>' : '–' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
<?php render_footer();
