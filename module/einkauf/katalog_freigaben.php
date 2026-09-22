<?php
// Einkauf – „Katalog-Freigaben". Route: ?p=katalog_freigaben
// Zentrale Stelle fuer ALLES, was Lieferanten in ihrem Portal hochladen/eintragen (Katalog, CoA/Spec,
// manuelle Zeilen). Diese Zeilen stehen auf status='neu' und warten auf unsere Entscheidung.
// Pro Zeile ein Popup: Upload-Details, Pruefung „schon im Bestand / aehnliche" (mit Oeffnen-Link und
// „Preis dorthin"), sowie „Als neuen Artikel anlegen" bzw. „ablehnen".
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/lieferant_katalog.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $akt = (string)($_POST['aktion'] ?? '');
    if ($akt === 'kat_ablehnen') {
        katalog_ablehnen((int)($_POST['zeile_id'] ?? 0));
        header('Location: ?p=katalog_freigaben&ab=1'); exit;
    }
    if ($akt === 'kat_uebernehmen') {
        $r = katalog_uebernehmen((int)($_POST['zeile_id'] ?? 0), (int)($_POST['item_id'] ?? 0) ?: null, !empty($_POST['preis_mit']));
        header('Location: ?p=katalog_freigaben' . ($r['ok'] ? '&ok=1' : '&fehler=' . urlencode($r['msg'] ?? 'Fehler'))); exit;
    }
    header('Location: ?p=katalog_freigaben'); exit;
}

$rows = all("SELECT k.*, l.firma FROM lieferant_katalog k
             JOIN lieferanten l ON l.id=k.lieferant_id
             WHERE k.status='neu'
             ORDER BY l.firma, k.name");
// Je Zeile einmal die Ähnlichen + KI-Daten ermitteln (Tabelle und Popups nutzen dieselben Werte).
$data = array_map(fn($z) => [
    'z'        => $z,
    'aehnlich' => katalog_aehnliche($z),
    'ki'       => !empty($z['ki_json']) ? json_decode((string)$z['ki_json'], true) : null,
    'dlg'      => 'dlgZ' . (int)$z['id'],
], $rows);
$zahl = fn($x, $n) => $x === null || $x === '' ? '–' : rtrim(rtrim(number_format((float)$x, $n, ',', '.'), '0'), ',');
// Link zum bestehenden Artikel (Rohstoff bzw. Produkt).
$itemLink = fn($it) => '?p=' . (($it['kategorie'] ?? '') === 'rohstoff' ? 'rohstoff' : 'produkt') . '&id=' . (int)$it['id'];

render_header('katalog_freigaben', 'Katalog-Freigaben');
bx_head('Katalog-Freigaben', count($rows) . ' offene Lieferanten-Uploads – ansehen, prüfen, anlegen oder ablehnen');

if (isset($_GET['ok'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Erledigt – der Artikel steht jetzt im Lager (mit EK-Preis).</div>';
if (isset($_GET['ab'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Zeile abgelehnt.</div>';
if (isset($_GET['fehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['fehler']) . '</div>';
?>
<style>
  /* Popup folgt dem Theme (im Dark-Mode sonst weiss). */
  .bx-dialog{border:1px solid var(--line);border-radius:14px;max-width:720px;width:calc(100% - 32px);padding:22px 24px;
             background:var(--panel);color:var(--text);box-shadow:0 24px 70px rgba(0,0,0,.45);color-scheme:light dark}
  .bx-dialog h2,.bx-dialog h3{color:var(--text)}
  .bx-dialog::backdrop{background:rgba(0,0,0,.55)}
</style>
<div class="bx-panel">
  <p class="muted" style="margin-top:0">Was Lieferanten in ihrem Portal unter <strong>Mein Katalog</strong> hochladen oder eintragen (Preisliste, CoA/Spezifikation, manuelle Zeilen), landet hier. <strong>Erst mit „Anlegen" entsteht daraus ein Artikel</strong> samt EK-Preis. Über <strong>Ansehen</strong> prüfst du alle Angaben und ob es den Artikel vielleicht schon gibt.</p>
  <?php if (!$rows): ?>
    <div class="muted">Keine offenen Uploads. Sobald ein Lieferant etwas hochlädt, erscheint es hier.</div>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr>
      <th>Lieferant</th><th>Artikel</th><th>Typ</th><th class="bx-num">Preis</th><th>Im Bestand?</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($data as $d): $z = $d['z']; ?>
      <tr>
        <td><a class="kundenlink" href="?p=lieferant&id=<?= (int)$z['lieferant_id'] ?>#katalog"><?= h((string)$z['firma']) ?></a></td>
        <td><?= h((string)$z['name']) ?><?php if (!empty($z['name_original']) && $z['name_original'] !== $z['name']): ?><div class="muted" style="font-size:12px">Original: <?= h((string)$z['name_original']) ?></div><?php endif; ?></td>
        <td><?= h(anfrage_art_label($z['art'] === 'fertigprodukt' ? 'fertigprodukt' : 'rohstoff', (string)$z['form'])) ?></td>
        <td class="bx-num"><?= $z['preis'] !== null ? h($zahl($z['preis'], 4) . ' ' . $z['waehrung'] . ($z['einheit'] ? ' / ' . $z['einheit'] : '')) : '–' ?></td>
        <td><?= $d['aehnlich'] ? bx_badge('evtl. vorhanden (' . count($d['aehnlich']) . ')', 'warn') : bx_badge('neu', 'ok') ?></td>
        <td class="bx-num" style="white-space:nowrap">
          <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('<?= $d['dlg'] ?>').showModal()">Ansehen</button>
          <form method="post" style="display:inline" onsubmit="return confirm('Diese Zeile ablehnen?');">
            <input type="hidden" name="aktion" value="kat_ablehnen"><input type="hidden" name="zeile_id" value="<?= (int)$z['id'] ?>">
            <button class="btn btn-ghost btn-sm" type="submit">ablehnen</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php // Popups liegen BEWUSST ausserhalb der Tabelle – ein <dialog> im <table> wuerde der Browser aus der
      // Tabelle herausloesen und der Inhalt landete neben dem Popup.
foreach ($data as $d): $z = $d['z']; $aehnlich = $d['aehnlich']; $ki = $d['ki']; ?>
  <dialog id="<?= $d['dlg'] ?>" class="bx-dialog">
    <div class="bx-row" style="justify-content:space-between;align-items:flex-start;gap:10px">
      <h2 style="margin:0"><?= h((string)$z['name']) ?></h2>
      <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('<?= $d['dlg'] ?>').close()" aria-label="schließen">&#10005;</button>
    </div>

    <h3 style="margin:14px 0 6px;font-size:14px">Angaben des Lieferanten</h3>
    <div class="bx-tablewrap"><table class="bx-table"><tbody>
      <tr><td style="width:200px">Lieferant</td><td><?= h((string)$z['firma']) ?></td></tr>
      <tr><td>Typ</td><td><?= h(anfrage_art_label($z['art'] === 'fertigprodukt' ? 'fertigprodukt' : 'rohstoff', (string)$z['form'])) ?></td></tr>
      <?php if (!empty($z['name_original']) && $z['name_original'] !== $z['name']): ?><tr><td>Original-Name</td><td><?= h((string)$z['name_original']) ?></td></tr><?php endif; ?>
      <?php if (!empty($z['name_en'])): ?><tr><td>Englisch</td><td><?= h((string)$z['name_en']) ?></td></tr><?php endif; ?>
      <?php if (!empty($z['cas'])): ?><tr><td>CAS</td><td><?= h((string)$z['cas']) ?></td></tr><?php endif; ?>
      <?php if (!empty($z['spezifikation'])): ?><tr><td>Spezifikation</td><td><?= h((string)$z['spezifikation']) ?></td></tr><?php endif; ?>
      <?php if (!empty($z['herkunft'])): ?><tr><td>Herkunft</td><td><?= h((string)$z['herkunft']) ?></td></tr><?php endif; ?>
      <tr><td>Preis</td><td><?= $z['preis'] !== null ? h($zahl($z['preis'], 4) . ' ' . $z['waehrung'] . ($z['einheit'] ? ' / ' . $z['einheit'] : '')) : '–' ?><?= $z['menge_ab'] !== null ? ' · ab ' . h($zahl($z['menge_ab'], 3)) : '' ?></td></tr>
      <?php if (!empty($z['notiz'])): ?><tr><td>Notiz</td><td style="white-space:pre-line"><?= h((string)$z['notiz']) ?></td></tr><?php endif; ?>
    </tbody></table></div>

    <?php if (is_array($ki) && (!empty($ki['wirkstoffe']) || !empty($ki['kennwerte']))): ?>
    <h3 style="margin:16px 0 6px;font-size:14px">Aus CoA/Spezifikation gelesen</h3>
    <div class="bx-tablewrap"><table class="bx-table"><tbody>
      <?php foreach ((array)($ki['wirkstoffe'] ?? []) as $w): $nm = trim((string)($w['name'] ?? '')); if ($nm === '') continue; ?>
        <tr><td style="width:200px"><?= h($nm) ?></td><td><?= ($w['gehalt_prozent'] ?? '') !== '' ? h((string)$w['gehalt_prozent']) . ' %' : '<span class="muted">–</span>' ?></td></tr>
      <?php endforeach; ?>
      <?php foreach ((array)($ki['kennwerte'] ?? []) as $kw): $p = trim((string)($kw['parameter'] ?? '')); if ($p === '') continue; ?>
        <tr><td><?= h($p) ?></td><td><?= h((string)($kw['wert'] ?? '')) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>

    <h3 style="margin:16px 0 6px;font-size:14px">Schon im Bestand?</h3>
    <?php if (!$aehnlich): ?>
      <p class="muted" style="margin:0 0 12px">Kein ähnlicher Artikel gefunden – wahrscheinlich neu.</p>
    <?php else: ?>
      <div class="bx-tablewrap" style="margin-bottom:12px"><table class="bx-table"><tbody>
        <?php foreach ($aehnlich as $it): ?>
          <tr>
            <td><a class="kundenlink" href="<?= h($itemLink($it)) ?>" target="_blank" rel="noopener"><?= h((string)$it['artikelnummer']) ?> · <?= h((string)$it['name']) ?></a>
                <div class="muted" style="font-size:12px"><?= h((string)$it['grund']) ?><?= !empty($it['cas']) ? ' · CAS ' . h((string)$it['cas']) : '' ?></div></td>
            <td class="bx-num" style="white-space:nowrap">
              <a class="btn btn-ghost btn-sm" href="<?= h($itemLink($it)) ?>" target="_blank" rel="noopener">Öffnen</a>
              <form method="post" style="display:inline" onsubmit="return confirm('Preis diesem bestehenden Artikel zuordnen?');">
                <input type="hidden" name="aktion" value="kat_uebernehmen"><input type="hidden" name="zeile_id" value="<?= (int)$z['id'] ?>">
                <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>"><input type="hidden" name="preis_mit" value="1">
                <button class="btn btn-primary btn-sm" type="submit">Preis dorthin</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>

    <div class="bx-row" style="gap:8px;flex-wrap:wrap;margin-top:6px">
      <form method="post" style="margin:0" onsubmit="return confirm('Als NEUEN Artikel im Lager anlegen?');">
        <input type="hidden" name="aktion" value="kat_uebernehmen"><input type="hidden" name="zeile_id" value="<?= (int)$z['id'] ?>">
        <input type="hidden" name="preis_mit" value="1">
        <button class="btn btn-primary" type="submit">Als neuen Artikel anlegen</button>
      </form>
      <form method="post" style="margin:0" onsubmit="return confirm('Diese Zeile ablehnen?');">
        <input type="hidden" name="aktion" value="kat_ablehnen"><input type="hidden" name="zeile_id" value="<?= (int)$z['id'] ?>">
        <button class="btn btn-ghost" type="submit">ablehnen</button>
      </form>
    </div>
  </dialog>
<?php endforeach; ?>
<script>
  // Klick auf den dunklen Rand schliesst das Popup.
  document.querySelectorAll('.bx-dialog').forEach(function(d){ d.addEventListener('click', function(e){ if(e.target===d) d.close(); }); });
</script>
<?php render_footer();
