<?php
// Einkauf – „Katalog-Freigaben". Route: ?p=katalog_freigaben
// Zentrale Stelle fuer ALLES, was Lieferanten in ihrem Portal hochladen/eintragen (Katalog, CoA/Spec,
// manuelle Zeilen). Diese Zeilen stehen auf status='neu' und warten auf unsere Entscheidung: „Anlegen"
// (Artikel + EK-Preis) oder „ablehnen". Bisher ging das nur einzeln im Lieferantenkonto (Reiter Katalog);
// hier sieht das Team alle offenen Uploads ueber alle Lieferanten auf einen Blick.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/lieferant_katalog.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $akt = (string)($_POST['aktion'] ?? '');
    $fehler = '';
    if ($akt === 'kat_ablehnen') {
        katalog_ablehnen((int)($_POST['zeile_id'] ?? 0));
        header('Location: ?p=katalog_freigaben&ab=1'); exit;
    }
    if ($akt === 'kat_uebernehmen') {
        $r = katalog_uebernehmen((int)($_POST['zeile_id'] ?? 0), (int)($_POST['item_id'] ?? 0) ?: null, !empty($_POST['preis_mit']));
        header('Location: ?p=katalog_freigaben' . ($r['ok'] ? '&ok=1' : '&fehler=' . urlencode($r['msg'] ?? 'Fehler')) . '#z' . (int)($_POST['zeile_id'] ?? 0)); exit;
    }
    header('Location: ?p=katalog_freigaben'); exit;
}

// Alle offenen Zeilen ueber alle Lieferanten.
$rows = all("SELECT k.*, l.firma FROM lieferant_katalog k
             JOIN lieferanten l ON l.id=k.lieferant_id
             WHERE k.status='neu'
             ORDER BY l.firma, k.name");
$zahl = fn($x, $n) => $x === null || $x === '' ? '–' : rtrim(rtrim(number_format((float)$x, $n, ',', '.'), '0'), ',');

render_header('katalog_freigaben', 'Katalog-Freigaben');
bx_head('Katalog-Freigaben', count($rows) . ' offene Lieferanten-Uploads – prüfen und als Artikel anlegen oder ablehnen');

if (isset($_GET['ok'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Zeile übernommen – der Artikel steht jetzt im Lager (mit EK-Preis).</div>';
if (isset($_GET['ab'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Zeile abgelehnt.</div>';
if (isset($_GET['fehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['fehler']) . '</div>';
?>
<div class="bx-panel">
  <p class="muted" style="margin-top:0">Was Lieferanten in ihrem Portal unter <strong>Mein Katalog</strong> hochladen oder eintragen (Preisliste, CoA/Spezifikation, manuelle Zeilen), landet hier. <strong>Erst mit „Anlegen" entsteht daraus ein Artikel</strong> samt EK-Preis – vorher steht davon nichts im Lager. „Preis dorthin" erscheint, wenn es den Artikel über Name oder CAS schon gibt.</p>
  <?php if (!$rows): ?>
    <div class="muted">Keine offenen Uploads. Sobald ein Lieferant etwas hochlädt, erscheint es hier.</div>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr>
      <th>Lieferant</th><th>Artikel</th><th>Typ</th><th>Spezifikation</th>
      <th class="bx-num">Preis</th><th class="bx-num">ab Menge</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $z): $treffer = katalog_treffer($z); ?>
      <tr id="z<?= (int)$z['id'] ?>">
        <td><a class="kundenlink" href="?p=lieferant&id=<?= (int)$z['lieferant_id'] ?>#katalog"><?= h((string)$z['firma']) ?></a></td>
        <td><?= h((string)$z['name']) ?>
          <?php if (!empty($z['name_original']) && $z['name_original'] !== $z['name']): ?><div class="muted" style="font-size:12px">Original: <?= h((string)$z['name_original']) ?></div><?php endif; ?>
          <?php if ($z['herkunft'] || $z['notiz']): ?><div class="muted" style="font-size:12px"><?= h(trim((string)$z['herkunft'] . ' ' . (string)$z['notiz'])) ?></div><?php endif; ?>
          <?php if ($treffer): ?><div style="font-size:12px;color:#8a6d1f">gibt es vielleicht schon: <?= h($treffer['artikelnummer'] . ' ' . $treffer['name']) ?></div><?php endif; ?>
        </td>
        <td><?= h(anfrage_art_label($z['art'] === 'fertigprodukt' ? 'fertigprodukt' : 'rohstoff', (string)$z['form'])) ?></td>
        <td><?= h((string)$z['spezifikation']) ?></td>
        <td class="bx-num"><?= $z['preis'] !== null ? h($zahl($z['preis'], 4) . ' ' . $z['waehrung'] . ($z['einheit'] ? ' / ' . $z['einheit'] : '')) : '–' ?></td>
        <td class="bx-num"><?= $z['menge_ab'] !== null ? h($zahl($z['menge_ab'], 3)) : '–' ?></td>
        <td class="bx-num" style="white-space:nowrap">
          <form method="post" style="display:inline">
            <input type="hidden" name="aktion" value="kat_uebernehmen">
            <input type="hidden" name="zeile_id" value="<?= (int)$z['id'] ?>">
            <input type="hidden" name="preis_mit" value="1">
            <?php if ($treffer): ?><input type="hidden" name="item_id" value="<?= (int)$treffer['id'] ?>"><?php endif; ?>
            <button class="btn btn-primary btn-sm" type="submit"><?= $treffer ? 'Preis dorthin' : 'Anlegen' ?></button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('Diese Zeile ablehnen?');">
            <input type="hidden" name="aktion" value="kat_ablehnen">
            <input type="hidden" name="zeile_id" value="<?= (int)$z['id'] ?>">
            <button class="btn btn-ghost btn-sm" type="submit">ablehnen</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php render_footer();
