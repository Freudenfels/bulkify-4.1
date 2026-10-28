<?php
// Lieferanten-Anfragen & Preise – zentrale Übersicht ALLER an Lieferanten gestellten Anfragen (RFQ)
// inkl. des vom Lieferanten abgegebenen Preises. Route: ?p=lief_anfragen
// Hintergrund: Der Preis einer Fremdfertigungs-Anfrage (auch zu einer noch nicht freigegebenen Rezeptur)
// steht in lieferant_angebot. Bisher nur im Rezeptur-Detail bzw. Lieferant-Detail sichtbar – hier an EINER
// Stelle, mit Preis und Link zur Rezeptur/zum Lieferanten.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

// E1: offene (unbeantwortete) Massenanfragen eines Lieferanten bereinigen (nur Admin).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'anfragen_bereinigen' && has_role('admin')) {
    $n = lieferant_anfragen_bereinigen((int)($_POST['lieferant_id'] ?? 0));
    header('Location: ?p=lief_anfragen&bereinigt=' . $n); exit;
}

$q = trim((string)($_GET['q'] ?? ''));
$f = in_array($_GET['f'] ?? 'alle', ['alle', 'offen', 'beantwortet'], true) ? $_GET['f'] : 'alle';
// Typ-Filter: Rohstoffe vs. Fertigprodukte (aus lieferant_anfrage.art), plus „Alle".
$typ = in_array($_GET['typ'] ?? 'alle', ['alle', 'rohstoff', 'fertig'], true) ? $_GET['typ'] : 'alle';

$ARTLBL = ['rohstoff'=>'Rohstoff','fertigprodukt'=>'Fremdfertigung','verpackung'=>'Verpackung','verbrauch'=>'Verbrauch','sonstiges'=>'Sonstiges'];

$where = '1=1'; $args = [];
if ($f === 'offen')        $where .= " AND ag.id IS NULL AND la.status NOT IN ('zurueckgezogen','geschlossen')"; // noch kein Preis abgegeben, aktiv
elseif ($f === 'beantwortet') $where .= " AND ag.id IS NOT NULL";      // Preis liegt vor
if ($typ === 'rohstoff')   $where .= " AND la.art='rohstoff'";
elseif ($typ === 'fertig') $where .= " AND la.art='fertigprodukt'";
if ($q !== '') {
    $like = '%' . str_replace('\\', '', $q) . '%';
    $where .= " AND (l.firma LIKE ? OR la.nummer LIKE ? OR la.betreff LIKE ? OR i.name LIKE ? OR rz.name LIKE ? OR rz.synonyme LIKE ? OR kz.firma LIKE ?)";
    array_push($args, $like, $like, $like, $like, $like, $like, $like);
}

$rows = all("SELECT la.id, la.nummer, la.art, la.betreff, la.menge, la.einheit, la.status, la.angelegt,
                    la.item_id, la.rezeptur_id, la.lieferant_id, la.coa_gewuenscht, l.firma,
                    ag.preis AS ang_preis, ag.einheit AS ang_einheit, ag.waehrung AS ang_waehrung,
                    ag.mindestmenge AS ang_min, ag.lieferzeit_tage AS ang_lz,
                    (SELECT COUNT(*) FROM dokument d WHERE d.typ IN ('coa','spec','beides','analyse')
                       AND ((d.objekt_typ='lieferant' AND d.lieferant_id=la.lieferant_id)
                            OR (la.item_id>0 AND d.objekt_typ='item' AND d.objekt_id=la.item_id))) AS coa_docs,
                    i.name AS item_name, i.artikelnummer AS item_nr,
                    rz.name AS rez_name, rz.nummer AS rez_nummer, kz.firma AS rez_kunde
             FROM lieferant_anfrage la
             LEFT JOIN lieferanten l ON l.id = la.lieferant_id
             LEFT JOIN lieferant_angebot ag ON ag.anfrage_id = la.id
             LEFT JOIN item i ON i.id = la.item_id
             LEFT JOIN rezeptur rz ON rz.id = la.rezeptur_id
             LEFT JOIN kunden kz ON kz.id = rz.kunde_id
             WHERE $where ORDER BY la.angelegt DESC LIMIT 500", $args);

// Zähler respektieren den Typ-Filter, damit die Zahlen zur Ansicht passen.
$cArt = ''; $cArgs = [];
if ($typ === 'rohstoff')   { $cArt = " AND la.art='rohstoff'"; }
elseif ($typ === 'fertig') { $cArt = " AND la.art='fertigprodukt'"; }
$cAlle = (int) scalar("SELECT COUNT(*) FROM lieferant_anfrage la WHERE 1=1$cArt", $cArgs);
$cOffen = (int) scalar("SELECT COUNT(*) FROM lieferant_anfrage la LEFT JOIN lieferant_angebot ag ON ag.anfrage_id=la.id WHERE ag.id IS NULL AND la.status NOT IN ('zurueckgezogen','geschlossen')$cArt", $cArgs);
$cBeant = (int) scalar("SELECT COUNT(*) FROM lieferant_anfrage la LEFT JOIN lieferant_angebot ag ON ag.anfrage_id=la.id WHERE ag.id IS NOT NULL$cArt", $cArgs);
// Typ-Zähler (unabhängig vom aktiven Preis-Tab, damit die Chips stabil bleiben)
$cTypRoh    = (int) scalar("SELECT COUNT(*) FROM lieferant_anfrage WHERE art='rohstoff'");
$cTypFertig = (int) scalar("SELECT COUNT(*) FROM lieferant_anfrage WHERE art='fertigprodukt'");
$cTypAlle   = (int) scalar("SELECT COUNT(*) FROM lieferant_anfrage");

$eur = fn($x, $w) => rtrim(rtrim(number_format((float)$x, 4, ',', '.'), '0'), ',') . ' ' . h($w ?: 'EUR');
$menge = fn($m, $e) => $m !== null && $m !== '' ? rtrim(rtrim(number_format((float)$m, 3, ',', '.'), '0'), ',') . ($e ? ' ' . h($e) : '') : '–';
$dat = fn($d) => $d ? fmt_zeit($d, 'd.m.Y') : '–';

render_header('lief_anfragen', 'Lieferanten-Anfragen & Preise');
bx_head('Lieferanten-Anfragen & Preise', 'Alle an Lieferanten gestellten Anfragen – mit dem abgegebenen Preis, inkl. Fremdfertigung zu (auch nicht freigegebenen) Rezepturen');
if (isset($_GET['bereinigt'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . (int)$_GET['bereinigt'] . ' offene (unbeantwortete) Preisanfragen bereinigt.</div>';
// E1: Lieferanten mit auffällig vielen offenen, unbeantworteten Anfragen (Massenanfragen) – gezielt bereinigbar.
if (has_role('admin')) {
    $massen = lieferanten_mit_massenanfragen(20);
    if ($massen) {
        echo '<div class="bx-panel" style="border-color:var(--warn);border-left:3px solid var(--warn);padding:12px 16px;margin-bottom:12px">';
        echo '<div style="font-weight:600;margin-bottom:6px">Massenanfragen aufräumen</div>';
        echo '<p class="muted" style="font-size:13px;margin:0 0 10px">Diese Lieferanten haben sehr viele <strong>offene, unbeantwortete</strong> Preisanfragen (z. B. versehentlich der ganze Katalog). Bereinigen löscht nur diese offenen Anfragen – Rohstoffe, Angebote und beantwortete Anfragen bleiben.</p>';
        foreach ($massen as $m) {
            echo '<div class="bx-row" style="gap:12px;align-items:center;flex-wrap:wrap;border:1px solid var(--line);border-radius:10px;padding:8px 12px;margin-bottom:6px">'
               . '<span style="flex:1 1 240px"><strong>' . h((string)($m['firma'] ?: ('#' . (int)$m['lieferant_id']))) . '</strong> · ' . (int)$m['n'] . ' offene Anfragen</span>'
               . '<form method="post" style="margin:0" onsubmit="return confirm(\'' . (int)$m['n'] . ' offene (unbeantwortete) Preisanfragen dieses Lieferanten löschen? Rohstoffe und beantwortete Anfragen bleiben.\');">'
               . '<input type="hidden" name="aktion" value="anfragen_bereinigen"><input type="hidden" name="lieferant_id" value="' . (int)$m['lieferant_id'] . '">'
               . '<button class="btn btn-ghost btn-sm" type="submit" style="color:#8f231b">' . (int)$m['n'] . ' offene bereinigen</button></form>'
               . '</div>';
        }
        echo '</div>';
    }
}
?>
<?php $typSuffix = ($typ !== 'alle' ? '&typ='.$typ : ''); ?>
<div class="settabs" style="margin:0 0 10px">
  <?php $typtab = function($key,$lbl,$cnt) use ($typ,$f,$q) {
      $on = $typ===$key ? ' class="on"' : '';
      $href = '?p=lief_anfragen&f='.$f.($key!=='alle'?'&typ='.$key:'').($q!==''?'&q='.urlencode($q):'');
      return '<a'.$on.' href="'.$href.'">'.$lbl.($cnt?' ('.$cnt.')':'').'</a>';
  }; ?>
  <?= $typtab('alle','Alle Typen',$cTypAlle) ?><?= $typtab('rohstoff','Rohstoffe',$cTypRoh) ?><?= $typtab('fertig','Fertigprodukte',$cTypFertig) ?>
</div>
<div class="bx-listbar">
  <?php $tab = function($key,$lbl) use ($f,$q,$typSuffix) { $on=$f===$key?' class="on"':''; $href='?p=lief_anfragen&f='.$key.$typSuffix.($q!==''?'&q='.urlencode($q):''); return '<a'.$on.' href="'.$href.'" style="margin-right:12px">'.$lbl.'</a>'; }; ?>
  <div><?= $tab('alle','Alle ('.$cAlle.')') ?><?= $tab('offen','Preis ausstehend ('.$cOffen.')') ?><?= $tab('beantwortet','Preis da ('.$cBeant.')') ?></div>
  <form method="get" style="margin-left:auto;display:flex;gap:6px">
    <input type="hidden" name="p" value="lief_anfragen"><input type="hidden" name="f" value="<?= h($f) ?>"><input type="hidden" name="typ" value="<?= h($typ) ?>">
    <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Lieferant, Rezeptur, Nr. …">
    <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
    <?php if ($q!==''): ?><a class="btn btn-ghost btn-sm" href="?p=lief_anfragen&f=<?= h($f) ?><?= $typSuffix ?>">zurücksetzen</a><?php endif; ?>
  </form>
</div>

<div class="bx-panel">
<?php if (!$rows): ?>
  <div class="muted">Keine Anfragen in dieser Ansicht.</div>
<?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Nr.</th><th>Lieferant</th><th>Was</th><th class="bx-num">Menge</th><th class="bx-num">Angebotspreis</th><th>Status</th><th>Datum</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        // "Was" + Link (Rezeptur-Fremdfertigung / Rohstoff / Freitext)
        if ((int)$r['rezeptur_id'] > 0) {
            $wasLink = '?p=rezeptur_detail&id=' . (int)$r['rezeptur_id'];   // rezeptur = Liste; Detail ist rezeptur_detail
            // Rezepturnummer immer mit zeigen (RZ-… · Name), nicht nur den Namen.
            $wasText = 'Fremdfertigung: ' . trim(((string)$r['rez_nummer'] !== '' ? $r['rez_nummer'] . ' · ' : '') . ($r['rez_name'] ?: 'Rezeptur'));
        } elseif ((int)$r['item_id'] > 0) {
            $wasLink = '?p=rohstoff&id=' . (int)$r['item_id'];
            $wasText = ($r['item_nr'] ? $r['item_nr'] . ' · ' : '') . ($r['item_name'] ?: 'Rohstoff');
        } else {
            $wasLink = null;
            $wasText = $r['betreff'] ?: '–';
        }
        $artBadge = $r['art'] ? '<span class="muted" style="font-size:11px">' . h($ARTLBL[$r['art']] ?? $r['art']) . '</span>' : '';
        $hatPreis = ($r['ang_preis'] !== null && $r['ang_preis'] !== '' && (float)$r['ang_preis'] > 0);
        // CoA/Spec angefordert, Preis ist da, aber keine Unterlagen hochgeladen -> fehlt (separat markieren).
        $coaFehlt = (int)($r['coa_gewuenscht'] ?? 0) === 1 && $hatPreis && (int)($r['coa_docs'] ?? 0) === 0;
    ?>
      <tr>
        <td style="white-space:nowrap"><a href="?p=lief_anfrage_detail&id=<?= (int)$r['id'] ?>"><?= h((string)($r['nummer'] ?: ('#'.$r['id']))) ?></a></td>
        <td style="max-width:200px;white-space:normal;overflow-wrap:anywhere"><?= h((string)($r['firma'] ?: '–')) ?></td>
        <td style="max-width:320px;white-space:normal;overflow-wrap:anywhere">
          <?php if ($wasLink): ?><a href="<?= $wasLink ?>"><?= h($wasText) ?></a><?php else: ?><?= h($wasText) ?><?php endif; ?>
          <?php if ((int)$r['rezeptur_id'] > 0 && !empty($r['rez_kunde'])): ?><div class="muted" style="font-size:11px">Kunde: <?= h((string)$r['rez_kunde']) ?></div><?php endif; ?>
          <?php if ($artBadge): ?><div><?= $artBadge ?></div><?php endif; ?>
        </td>
        <td class="bx-num"><?= $menge($r['menge'], $r['einheit']) ?></td>
        <td class="bx-num">
          <?php if ($hatPreis): ?>
            <strong style="font-weight:600"><?= $eur($r['ang_preis'], $r['ang_waehrung']) ?></strong><?= $r['ang_einheit'] ? ' <span class="muted">/ ' . h($r['ang_einheit']) . '</span>' : '' ?>
            <?php if ($r['ang_min'] || $r['ang_lz']): ?><div class="muted" style="font-size:11px"><?= $r['ang_min'] ? 'ab ' . $menge($r['ang_min'], $r['ang_einheit']) : '' ?><?= ($r['ang_min'] && $r['ang_lz']) ? ' · ' : '' ?><?= $r['ang_lz'] ? (int)$r['ang_lz'] . ' Tage' : '' ?></div><?php endif; ?>
          <?php else: ?>
            <span class="muted">ausstehend</span>
          <?php endif; ?>
        </td>
        <td><?php if (($r['status'] ?? '') === 'zurueckgezogen'): ?><?= bx_badge('zurückgezogen') ?><?php else: ?><?= $hatPreis ? bx_badge('Preis da','ok') : bx_badge('offen') ?><?php if ($coaFehlt): ?> <?= bx_badge('CoA/Spec fehlt','err') ?><?php endif; ?><?php endif; ?></td>
        <td style="white-space:nowrap"><?= $dat($r['angelegt']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if (count($rows) >= 500): ?><p class="muted" style="font-size:12px;margin-top:8px">Nur die neuesten 500 – mit der Suche eingrenzen.</p><?php endif; ?>
<?php endif; ?>
</div>
<?php render_footer();
