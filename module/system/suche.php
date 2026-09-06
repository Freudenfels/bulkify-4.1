<?php
// Globale Suche (Admin): ein Feld, das über alle wichtigen Bereiche sucht – Kunden,
// Lieferanten, Rohstoffe/Artikel, Produkte, Rezepturen, Angebote, Aufträge – und die
// Treffer gruppiert und anklickbar zeigt.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

if (!has_role('admin')) {
    render_header('dashboard', 'Suche');
    bx_head('Suche', '', bx_btn('Zurück', '?p=dashboard', 'ghost'));
    echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:16px">Die globale Suche ist nur für Administratoren.</div>';
    render_footer();
    return;
}

$q    = trim((string)($_GET['q'] ?? ''));
$like = '%' . $q . '%';
$LIMIT = 30;   // je Bereich; angezeigt werden max. 12, Rest als "und N weitere"

// Eine Trefferliste je Bereich: [titel, rows[], hrefFn, labelFn, subFn]
$gruppen = [];
$add = function (string $titel, array $rows, callable $hrefFn, callable $labelFn, ?callable $subFn = null) use (&$gruppen) {
    if ($rows) $gruppen[] = compact('titel', 'rows', 'hrefFn', 'labelFn', 'subFn');
};

if (mb_strlen($q) >= 2) {
    $f6 = array_fill(0, 6, $like);

    $add('Kunden',
        all("SELECT DISTINCT k.id, k.firma, k.ansprechpartner, k.ort, k.kundennummer
             FROM kunden k LEFT JOIN kunde_marke m ON m.kunde_id=k.id
             WHERE k.firma LIKE ? OR k.ansprechpartner LIKE ? OR k.email LIKE ? OR k.kundennummer LIKE ? OR k.ort LIKE ? OR m.name LIKE ?
             ORDER BY k.firma LIMIT $LIMIT", $f6),
        fn($r) => '?p=kunde&id=' . $r['id'],
        fn($r) => $r['firma'],
        fn($r) => trim(($r['kundennummer'] ? $r['kundennummer'] . ' · ' : '') . ($r['ansprechpartner'] ?: '') . ($r['ort'] ? ' · ' . $r['ort'] : '')));

    $add('Lieferanten',
        all("SELECT id, firma, ansprechpartner, ort, lieferantennummer FROM lieferanten
             WHERE firma LIKE ? OR ansprechpartner LIKE ? OR email LIKE ? OR lieferantennummer LIKE ? OR ort LIKE ? OR webseite LIKE ?
             ORDER BY firma LIMIT $LIMIT", $f6),
        fn($r) => '?p=lieferant&id=' . $r['id'],
        fn($r) => $r['firma'],
        fn($r) => trim(($r['lieferantennummer'] ? $r['lieferantennummer'] . ' · ' : '') . ($r['ansprechpartner'] ?: '') . ($r['ort'] ? ' · ' . $r['ort'] : '')));

    $KAT = ['rohstoff'=>'Rohstoff','verpackung'=>'Verpackung','verbrauch'=>'Verbrauch','fertig'=>'Fertigware','verkaufsfertig'=>'Verkaufsfertig','maschine'=>'Maschine'];
    $add('Rohstoffe / Artikel',
        all("SELECT id, name, name_lat, artikelnummer, kategorie FROM item
             WHERE name LIKE ? OR name_en LIKE ? OR name_lat LIKE ? OR synonym LIKE ? OR artikelnummer LIKE ?
             ORDER BY name LIMIT $LIMIT", array_fill(0, 5, $like)),
        fn($r) => '?p=rohstoff&id=' . $r['id'],
        fn($r) => $r['name'],
        fn($r) => trim(($r['artikelnummer'] ? $r['artikelnummer'] . ' · ' : '') . ($KAT[$r['kategorie']] ?? $r['kategorie']) . ($r['name_lat'] ? ' · ' . $r['name_lat'] : '')));

    $add('Produkte',
        all("SELECT id, nummer, name, kundenname FROM produkt
             WHERE name LIKE ? OR kundenname LIKE ? OR nummer LIKE ?
             ORDER BY name LIMIT $LIMIT", array_fill(0, 3, $like)),
        fn($r) => '?p=produkt&id=' . $r['id'],
        fn($r) => $r['name'],
        fn($r) => trim(($r['nummer'] ? $r['nummer'] . ' · ' : '') . ($r['kundenname'] ? 'Kundenname: ' . $r['kundenname'] : '')));

    $add('Rezepturen',
        all("SELECT id, nummer, name, darreichungsform FROM rezeptur
             WHERE name LIKE ? OR nummer LIKE ?
             ORDER BY name LIMIT $LIMIT", array_fill(0, 2, $like)),
        fn($r) => '?p=rezeptur_detail&id=' . $r['id'],
        fn($r) => $r['name'],
        fn($r) => trim(($r['nummer'] ? $r['nummer'] . ' · ' : '') . ($r['darreichungsform'] ?: '')));

    $add('Angebote',
        all("SELECT a.id, a.nummer, a.status, k.firma FROM angebot a LEFT JOIN kunden k ON k.id=a.kunde_id
             WHERE a.nummer LIKE ? OR k.firma LIKE ?
             ORDER BY a.id DESC LIMIT $LIMIT", [$like, $like]),
        fn($r) => '?p=angebot&id=' . $r['id'],
        fn($r) => ($r['nummer'] ?: 'Angebot #' . $r['id']),
        fn($r) => trim(($r['firma'] ?: '') . ' · ' . ($r['status'] ?: '')));

    $add('Aufträge',
        all("SELECT t.id, t.nummer, t.status, k.firma FROM auftrag t LEFT JOIN kunden k ON k.id=t.kunde_id
             WHERE t.nummer LIKE ? OR k.firma LIKE ?
             ORDER BY t.id DESC LIMIT $LIMIT", [$like, $like]),
        fn($r) => '?p=auftrag&id=' . $r['id'],
        fn($r) => ($r['nummer'] ?: 'Auftrag #' . $r['id']),
        fn($r) => trim(($r['firma'] ?: '') . ' · ' . ($r['status'] ?: '')));
}

$gesamt = array_sum(array_map(fn($g) => count($g['rows']), $gruppen));

render_header('suche', 'Suche');
bx_head('Globale Suche', $q !== '' ? $gesamt . ' Treffer für „' . h($q) . '"' : 'alles auf einmal durchsuchen', bx_btn('Zurück zum Dashboard', '?p=dashboard', 'ghost'));
?>
<form method="get" class="bx-form">
  <input type="hidden" name="p" value="suche">
  <div class="bx-panel">
    <div class="bx-row" style="gap:8px">
      <input class="bx-search" style="flex:1" type="text" name="q" value="<?= h($q) ?>" autofocus placeholder="Kunde, Lieferant, Rohstoff, Produkt, Rezeptur, Angebot- oder Auftragsnummer …">
      <button class="btn btn-primary" type="submit" data-busy="Suche…">Suchen</button>
      <?php if ($q !== ''): ?><a class="btn btn-ghost" href="?p=suche">Leeren</a><?php endif; ?>
    </div>
  </div>
</form>

<?php if ($q !== '' && mb_strlen($q) < 2): ?>
  <div class="bx-panel muted">Bitte mindestens 2 Zeichen eingeben.</div>
<?php elseif ($q !== '' && !$gruppen): ?>
  <div class="bx-panel muted">Keine Treffer für „<?= h($q) ?>".</div>
<?php elseif ($gruppen): ?>
  <?php foreach ($gruppen as $g): $rows = $g['rows']; $zeige = array_slice($rows, 0, 12); $mehr = count($rows) - count($zeige); ?>
    <div class="bx-panel">
      <div style="font-weight:600;margin-bottom:8px"><?= h($g['titel']) ?> <span class="muted" style="font-weight:400">(<?= count($rows) ?><?= count($rows) >= $LIMIT ? '+' : '' ?>)</span></div>
      <div class="bx-searchhits">
        <?php foreach ($zeige as $r): $sub = $g['subFn'] ? trim((string)($g['subFn'])($r), " ·") : ''; ?>
          <a class="bx-searchhit" href="<?= h(($g['hrefFn'])($r)) ?>">
            <span class="bx-searchhit-label"><?= h(($g['labelFn'])($r)) ?></span>
            <?php if ($sub !== ''): ?><span class="bx-searchhit-sub muted"><?= h($sub) ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
      <?php if ($mehr > 0): ?><div class="muted" style="font-size:12px;margin-top:8px">und <?= $mehr ?> weitere – Suche verfeinern.</div><?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php else: ?>
  <div class="bx-panel muted">Tippe oben einen Suchbegriff ein. Die Suche geht über Kunden, Lieferanten, Rohstoffe/Artikel, Produkte, Rezepturen, Angebote und Aufträge.</div>
<?php endif; ?>
<style>
.bx-searchhits{display:flex;flex-direction:column}
.bx-searchhit{display:flex;flex-wrap:wrap;align-items:baseline;gap:4px 12px;padding:8px 10px;border-radius:8px;text-decoration:none;color:inherit}
.bx-searchhit:hover{background:var(--bx-hover, rgba(0,0,0,.04))}
.bx-searchhit-label{font-weight:500}
.bx-searchhit-sub{font-size:12px}
</style>
<?php
render_footer();
