<?php
// Lieferanten-Preise (strukturiert) – was zahlen wir bei welchem Lieferanten, sauber getrennt
// nach Rohstoffen (lieferant_preis) und Fertigprodukten/Zukauf (produkt_lieferant_preis).
// Anders als die flache „EK-Preisliste" (v3-Referenz) sind hier echte, verknüpfte Preise mit
// Staffel, Incoterm/Versandart und Quelle – gefüllt über Anfragen/Angebote und den EK-Import.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$q   = trim((string)($_GET['q'] ?? ''));
$tab = $_GET['tab'] ?? 'rohstoff';
if (!in_array($tab, ['rohstoff', 'fertigprodukt'], true)) $tab = 'rohstoff';

$VERS = versandart_liste();
$preisTxt = function($preis, $waehrung, $einheit) {
    if ($preis === null || $preis === '') return '<span class="muted">–</span>';
    $p = rtrim(rtrim(number_format((float)$preis, 4, ',', '.'), '0'), ',');
    return h($p) . ' ' . h($waehrung ?: 'EUR') . ($einheit ? ' / ' . h($einheit) : '');
};
$mengeTxt = fn($m, $einheit) => $m !== null && (float)$m > 0
    ? rtrim(rtrim(number_format((float)$m, 3, ',', '.'), '0'), ',') . ($einheit ? ' ' . h($einheit) : '')
    : '<span class="muted">–</span>';
$termTxt = function($inco, $vers) use ($VERS) {
    $t = array_filter([(string)$inco, $vers ? ($VERS[$vers] ?? $vers) : '']);
    return $t ? h(implode(' · ', $t)) : '<span class="muted">–</span>';
};

// Zählungen je Reiter
$anzRoh  = (int) scalar("SELECT COUNT(*) FROM lieferant_preis");
$anzFert = (int) scalar("SELECT COUNT(*) FROM produkt_lieferant_preis");

if ($tab === 'rohstoff') {
    $rows = all("SELECT lp.*, i.name AS artikel, i.einheit AS art_einheit,
                        COALESCE(l.firma, lp.lieferant_name, '') AS lieferant
                 FROM lieferant_preis lp
                 LEFT JOIN item i ON i.id=lp.item_id
                 LEFT JOIN lieferanten l ON l.id=lp.lieferant_id
                 ORDER BY artikel, lp.menge_ab");
} else {
    $rows = all("SELECT plp.*, p.name AS artikel, plp.einheit AS art_einheit, plp.groesse,
                        COALESCE(l.firma, plp.lieferant_name, '') AS lieferant
                 FROM produkt_lieferant_preis plp
                 LEFT JOIN produkt p ON p.id=plp.produkt_id
                 LEFT JOIN lieferanten l ON l.id=plp.lieferant_id
                 ORDER BY artikel, plp.menge_ab");
}
if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_values(array_filter($rows, function($r) use ($needle) {
        foreach (['artikel', 'lieferant'] as $f) if (mb_strpos(mb_strtolower((string)($r[$f] ?? '')), $needle) !== false) return true;
        return false;
    }));
}

render_header('lieferant_preise', 'Lieferanten-Preise');
bx_head('Lieferanten-Preise', count($rows) . ' Einträge · ' . ($tab === 'rohstoff' ? 'Rohstoffe' : 'Fertigprodukte'));
?>
<div class="settabs">
  <a href="?p=lieferant_preise&tab=rohstoff" class="<?= $tab==='rohstoff'?'on':'' ?>">Rohstoffe<?= $anzRoh ? ' (' . $anzRoh . ')' : '' ?></a>
  <a href="?p=lieferant_preise&tab=fertigprodukt" class="<?= $tab==='fertigprodukt'?'on':'' ?>">Fertigprodukte<?= $anzFert ? ' (' . $anzFert . ')' : '' ?></a>
</div>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="lieferant_preise">
  <input type="hidden" name="tab" value="<?= h($tab) ?>">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Artikel oder Lieferant …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=lieferant_preise&tab=<?= h($tab) ?>">zurücksetzen</a><?php endif; ?>
</form>
<div class="bx-panel">
  <?php if (!$rows): ?>
    <div class="muted"><?= $q !== '' ? 'Keine Treffer.' : ($tab === 'rohstoff'
        ? 'Noch keine Rohstoff-Lieferantenpreise. Sie entstehen, wenn ein Lieferanten-Angebot übernommen wird oder über den EK-Import.'
        : 'Noch keine Fertigprodukt-Zukaufpreise. Sie entstehen aus angenommenen Bulk-Angeboten oder dem EK-Import.') ?></div>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr>
      <th><?= $tab === 'rohstoff' ? 'Rohstoff' : 'Fertigprodukt' ?></th>
      <?php if ($tab === 'fertigprodukt'): ?><th>Größe</th><?php endif; ?>
      <th>Lieferant</th><th class="bx-num">ab Menge</th><th class="bx-num">Preis</th>
      <th>Lieferbedingung</th><th>Quelle</th><th>Stand</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?php if ($tab === 'rohstoff' && !empty($r['item_id'])): ?><a class="kundenlink" href="?p=rohstoff&id=<?= (int)$r['item_id'] ?>&tab=ek"><?= h($r['artikel'] ?: '–') ?></a><?php elseif ($tab === 'fertigprodukt' && !empty($r['produkt_id'])): ?><a class="kundenlink" href="?p=produkt&id=<?= (int)$r['produkt_id'] ?>"><?= h($r['artikel'] ?: '–') ?></a><?php else: ?><?= h($r['artikel'] ?: '–') ?><?php endif; ?></td>
        <?php if ($tab === 'fertigprodukt'): ?><td><?= $r['groesse'] ? h($r['groesse']) : '<span class="muted">–</span>' ?></td><?php endif; ?>
        <td><?= $r['lieferant'] !== '' ? h($r['lieferant']) : '<span class="muted">–</span>' ?></td>
        <td class="bx-num"><?= $mengeTxt($r['menge_ab'], $r['art_einheit']) ?></td>
        <td class="bx-num"><?= $preisTxt($r['preis'], $r['waehrung'], $r['art_einheit']) ?></td>
        <td><?= $termTxt($r['incoterm'] ?? '', $r['versandart'] ?? '') ?></td>
        <td class="muted"><?= $r['quelle'] ? h($r['quelle']) : '–' ?></td>
        <td class="muted"><?= !empty($r['stand']) ? h(date('d.m.Y', strtotime((string)$r['stand']))) : '–' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <p class="muted" style="font-size:12px;margin-top:10px">Strukturierte Lieferantenpreise (verknüpft, mit Staffel und Lieferbedingung). Mehrere Zeilen je Artikel = Mengenstaffeln bzw. verschiedene Lieferanten. Die flache Nachschlage-Liste aus v3 steht weiter unter „EK-Preisliste".</p>
</div>
<?php render_footer();
