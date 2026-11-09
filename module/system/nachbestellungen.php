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
    if ($aktion === 'kp_uebernehmen') {
        $r = nachbestellung_kundenpreis_uebernehmen($id);
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

// Entscheidungsgrundlage je Nachbestellung (Nico 09.11.2026): Konfiguration mit Links, Preisquellen, frühere Aufträge.
function nb_info_render(array $n, array $i, callable $eur, callable $paket): void {
    $kv = fn(string $l, string $v) => '<div class="nb-kv"><span class="muted">' . h($l) . '</span><span>' . $v . '</span></div>';
    $q = $i['quelle'];
    echo '<div class="nb-grid">';
    // 1) Was wird nachbestellt
    echo '<div><div class="nb-tit">Konfiguration</div>';
    echo $kv('Packungsgröße', h($paket($n['stueck'], $n['produkt_id'] ?? 0)));
    if ($i['items']) foreach ($i['items'] as $it)
        echo $kv($it['rolle'], '<a href="?p=verpackung&id=' . (int)$it['id'] . '">' . h((string)$it['name']) . '</a>' . (!empty($it['artikelnummer']) ? ' <span class="muted">' . h((string)$it['artikelnummer']) . '</span>' : ''));
    else echo $kv('Verpackung', '<span style="color:#8f231b">keine hinterlegt</span>');
    if ($q) {
        echo $kv('Vorauftrag', '<a href="?p=auftrag&id=' . (int)$q['id'] . '">' . h((string)($q['nummer'] ?: '#' . (int)$q['id'])) . '</a> <span class="muted">' . h(fmt_zeit((string)$q['angelegt'], 'd.m.Y')) . ' · ' . number_format((int)$q['menge'], 0, ',', '.') . ' Pkg</span>');
        echo $kv('Etikett-Freigabe', (int)($q['etikett_freigegeben'] ?? 0) === 1 ? 'freigegeben (wird übernommen)' : '<span style="color:#8a6d00">am Vorauftrag nicht freigegeben</span>');
    } else echo $kv('Vorauftrag', '<span class="muted">aus Angebot, kein Auftrag</span>');
    echo '</div>';
    // 2) Woher kann der Preis kommen
    echo '<div><div class="nb-tit">Preis-Herkunft je Pkg</div>';
    if ($i['staffel']) {
        $t = []; foreach ($i['staffel'] as $s) $t[] = 'ab ' . number_format((int)$s['menge'], 0, ',', '.') . ': ' . $eur($s['vk']);
        echo $kv('Angebote', h(implode(' · ', $t)));
    } else echo $kv('Angebote', '<span class="muted">kein Angebot mit Preis</span>');
    echo $kv('Vorauftrag', $i['quelle_preis'] > 0 ? $eur($i['quelle_preis']) : '<span class="muted">ohne Preis</span>');
    $lr = $i['letzte_re'];
    echo $kv('Zuletzt berechnet', $lr ? $eur($lr['re_preis']) . ' <span class="muted">' . h($lr['re_nr']) . ' · ' . h((string)($lr['nummer'] ?: '#' . (int)$lr['id'])) . '</span>' : '<span class="muted">keine Rechnung</span>');
    $kp = $i['kundenpreis'];
    echo $kv('Kundenpreis', $kp ? $eur($kp['gesamt']) . ' <span class="muted">= Herstellung ' . $eur($kp['herstellung']) . ' + Verpackung ' . $eur($kp['verpackung']) . ($kp['staffel'] > 0 ? ' · Staffel ab ' . number_format($kp['staffel'], 0, ',', '.') : '') . '</span>'
                                     : '<span class="muted">nicht hinterlegt</span> <a href="?p=kundenpreise&ohne=1">Kundenpreise</a>');
    if ($i['ek'] > 0) {
        $ref = (float)$n['vk_stueck'] > 0 ? (float)$n['vk_stueck'] : (float)($kp['gesamt'] ?? 0);
        $marge = $ref > 0 ? round(($ref - $i['ek']) / $ref * 100) : null;
        echo $kv('EK ca.', $eur($i['ek']) . ' <span class="muted">= Füllung ' . $eur($i['ek_fuellung']) . ' + Verpackung ' . $eur($i['ek_verpackung']) . '</span>' . ($marge !== null ? ' <span class="' . ($marge < marge_min_prozent() ? '' : 'muted') . '"' . ($marge < marge_min_prozent() ? ' style="color:#8f231b"' : '') . '>· Marge ' . (int)$marge . ' %</span>' : ''));
    } else echo $kv('EK ca.', '<span class="muted">nicht berechenbar (Rezeptur ohne Preise)</span>');
    echo '</div>';
    // 3) Frühere Aufträge
    echo '<div><div class="nb-tit">Frühere Aufträge</div>';
    if (!$i['historie']) echo '<div class="muted">keine</div>';
    else {
        echo '<table class="nb-hist"><tbody>';
        foreach ($i['historie'] as $h)
            echo '<tr><td><a href="?p=auftrag&id=' . (int)$h['id'] . '">' . h((string)($h['nummer'] ?: '#' . (int)$h['id'])) . '</a></td>'
               . '<td class="muted">' . h(fmt_zeit((string)$h['angelegt'], 'd.m.Y')) . '</td>'
               . '<td class="bx-num">' . number_format((int)$h['menge'], 0, ',', '.') . '</td>'
               . '<td class="bx-num">' . ($h['preis'] > 0 ? $eur($h['preis']) : '<span class="muted">0 €</span>') . '</td>'
               // Packungsgröße/Behälter nur zeigen, wenn sie von dieser Nachbestellung abweichen.
               . '<td class="muted">' . h(implode(' · ', array_filter([
                     (int)$h['stueck'] !== (int)$n['stueck'] ? $paket($h['stueck'], $n['produkt_id'] ?? 0) : '',
                     (int)($h['verpackung_id'] ?? 0) !== (int)($q['verpackung_id'] ?? 0) ? (string)($h['verp_name'] ?? '') : '']))) . '</td></tr>';
        echo '</tbody></table>';
    }
    echo '</div></div>';
}

render_header('nachbestellungen', 'Nachbestellungen');
echo '<style>
#nbOffen td:nth-child(2),#nbOffen td:nth-child(6){white-space:normal;min-width:200px}
.nb-info td{background:var(--panel-2);white-space:normal;padding:10px 12px 14px}
.nb-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:12px 28px;font-size:13px}
.nb-tit{color:var(--muted,#6b7280);font-size:12px;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px}
.nb-kv{display:flex;gap:10px;padding:2px 0}.nb-kv>span:first-child{flex:0 0 120px}
.nb-hist td{padding:2px 10px 2px 0;border:0;white-space:nowrap}
</style>';
bx_head('Nachbestellungen', count($offen) . ' zu prüfen',
    bx_hint('Vom Kunden verbindlich zum zuletzt vereinbarten Preis ausgelöst. In der Regel nur Preis bestätigen und freigeben – der Auftrag entsteht dann automatisch inkl. übernommener Etikett-Freigabe.'));

if (isset($_GET['ok']))      echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Bestätigt & freigegeben – <a href="?p=auftrag&id=' . (int)$_GET['ok'] . '">Auftrag öffnen</a>.</div>';
if (isset($_GET['preisok'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Neuer Preis gesetzt – der Kunde gibt ihn im Portal frei.</div>';
if (isset($_GET['fehler']))  echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['fehler']) . '</div>';

// Inhalt je Packung formgerecht (Pulver „100 g/Pkg", Kapseln „60 Kapseln/Pkg") statt pauschal „Stk/Pkg" (St-13).
$paket = fn($stk, $pid = 0) => (int)$stk > 0 ? form_groessen_label(auftrag_darreichungsform(['produkt_id'=>(int)$pid]), (float)$stk) . '/Pkg' : 'Pkg';
?>
<div class="bx-panel">
  <h2 style="margin-top:0">Zu prüfen</h2>
  <?php if (!$offen): ?><div class="muted">Keine offenen Nachbestellungen.</div><?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table" id="nbOffen">
    <thead><tr><th>Kunde</th><th>Produkt</th><th class="bx-num">Menge</th><th class="bx-num">Preis / Pkg</th><th class="bx-num">Gesamt netto</th><th>Status / Aktion</th></tr></thead>
    <tbody>
    <?php foreach ($offen as $n): $menge = (int)$n['menge']; $vk = (float)$n['vk_stueck']; $netto = $menge * $vk; $info = nachbestellung_entscheidung($n);
        // Ohne Preis (Altauftrag mit 0 €): Vorschlag aus den zentralen Kundenpreisen, Freigabe erst mit Preis.
        $vor = 0.0;
        if ($vk <= 0) {
            $verpQ = (int)($n['quelle_auftrag_id'] ?? 0) ? (int) scalar("SELECT COALESCE(verpackung_id,0) FROM auftrag WHERE id=?", [(int)$n['quelle_auftrag_id']]) : 0;
            $vor = nachbestellung_kundenpreis_vk((int)$n['kunde_id'], (int)$n['produkt_id'], (int)$n['stueck'], $menge, $verpQ ?: null);
        } ?>
      <tr>
        <td><?= kunde_link($n['kunde_id'], (string)($n['kunde'] ?: ('Kunde #' . (int)$n['kunde_id']))) ?></td>
        <td><?= (int)$n['produkt_id'] ? '<a href="?p=produkt&id=' . (int)$n['produkt_id'] . '">' . h((string)($n['produkt'] ?: '–')) . '</a>' : h((string)($n['produkt'] ?: '–')) ?> <span class="muted"><?= h($paket($n['stueck'], $n['produkt_id'] ?? 0)) ?></span></td>
        <td class="bx-num"><?= number_format($menge, 0, ',', '.') ?></td>
        <td class="bx-num"><?= $vk > 0 ? $eur($vk) : '<span style="color:#8f231b">fehlt</span>' . ($vor > 0 ? '<div class="muted" style="font-size:12px">Kundenpreis ' . $eur($vor) . '</div>' : '') ?></td>
        <td class="bx-num"><?= $vk > 0 ? $eur($netto) : ($vor > 0 ? '<span class="muted">' . $eur($menge * $vor) . '</span>' : '–') ?></td>
        <td>
          <?php if ((string)$n['status'] === 'preis_neu'): ?>
            <?= bx_badge('wartet auf Kundenfreigabe', 'warn') ?> <span class="muted" style="font-size:12px">neuer Preis <?= $eur((float)$n['vk_neu']) ?></span>
          <?php else: ?>
          <?php if ($vk <= 0): ?><div style="font-size:12px;color:#8f231b;margin-bottom:4px">Kein Preis am Vorauftrag – erst Preis festlegen, dann entsteht der Auftrag.<?= $vor <= 0 ? ' Kein Kundenpreis hinterlegt (<a href="?p=kundenpreise&ohne=1">Kundenpreise</a>).' : '' ?></div>
          <?php elseif (!empty($n['grund'])): ?><div class="muted" style="font-size:12px;color:#8a6d00;margin-bottom:4px"><?= h((string)$n['grund']) ?></div><?php endif; ?>
          <div class="bx-row" style="gap:8px;align-items:center;flex-wrap:wrap">
            <?php if ($vk > 0): ?>
            <form method="post" style="margin:0" onsubmit="return confirm('Preis bestätigen und Auftrag freigeben?');">
              <input type="hidden" name="aktion" value="bestaetigen"><input type="hidden" name="nb_id" value="<?= (int)$n['id'] ?>">
              <button class="btn btn-primary btn-sm" type="submit" data-busy="…">Preis bestätigen &amp; freigeben</button>
            </form>
            <?php elseif ($vor > 0): ?>
            <form method="post" style="margin:0" onsubmit="return confirm('Kundenpreis übernehmen und Auftrag freigeben?');">
              <input type="hidden" name="aktion" value="kp_uebernehmen"><input type="hidden" name="nb_id" value="<?= (int)$n['id'] ?>">
              <button class="btn btn-primary btn-sm" type="submit" data-busy="…">Kundenpreis <?= $eur($vor) ?> übernehmen &amp; freigeben</button>
            </form>
            <?php endif; ?>
            <details<?= ($vk <= 0 && $vor <= 0) ? ' open' : '' ?>>
              <summary class="btn btn-ghost btn-sm" style="list-style:none;cursor:pointer">Preis anpassen</summary>
              <form method="post" class="bx-row" style="gap:6px;align-items:flex-end;margin:8px 0 0;flex-wrap:wrap">
                <input type="hidden" name="aktion" value="preis_anpassen"><input type="hidden" name="nb_id" value="<?= (int)$n['id'] ?>">
                <label style="display:flex;flex-direction:column;gap:3px;font-size:12px" class="muted">Neuer Preis / Pkg (€)
                  <input type="text" name="vk_neu" inputmode="decimal" value="<?= ($vk > 0 || $vor > 0) ? number_format($vk > 0 ? $vk : $vor, 2, ',', '.') : '' ?>" style="width:120px;text-align:right"></label>
                <label style="display:flex;flex-direction:column;gap:3px;font-size:12px" class="muted">Hinweis an den Kunden (optional)
                  <input type="text" name="grund" style="min-width:220px" placeholder="z. B. Rohstoffpreis gestiegen"></label>
                <button class="btn btn-ghost btn-sm" type="submit">An Kunde zur Freigabe</button>
              </form>
            </details>
          </div>
          <?php endif; ?>
        </td>
      </tr>
      <tr class="nb-info"><td colspan="6"><?php nb_info_render($n, $info, $eur, $paket); ?></td></tr>
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
        <td><?= kunde_link($n['kunde_id'], (string)($n['kunde'] ?: ('Kunde #' . (int)$n['kunde_id']))) ?></td>
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
