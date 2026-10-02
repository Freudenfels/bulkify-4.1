<?php
// Kundenliste – Phase: Stammdaten
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

seed_kunden_if_empty();

$q    = trim($_GET['q']   ?? '');
$sort = $_GET['sort'] ?? 'aktualisiert';
$dir  = $_GET['dir']  ?? 'desc';

$rows = all("SELECT * FROM kunden");

// Marken je Kunde (White-Label) – für Anzeige und Suche
$markenByKunde = [];
foreach (all("SELECT kunde_id, name FROM kunde_marke WHERE name<>'' ORDER BY sort,id") as $m)
    $markenByKunde[(int)$m['kunde_id']][] = (string)$m['name'];

// Suche (Firma, Marke, Ansprechpartner, Ort, Kundennummer, E-Mail)
if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($rows, function($r) use ($needle, $markenByKunde) {
        foreach (['firma','ansprechpartner','ort','kundennummer','email'] as $f) {
            if (mb_strpos(mb_strtolower((string)$r[$f]), $needle) !== false) return true;
        }
        foreach ($markenByKunde[(int)$r['id']] ?? [] as $mn)
            if (mb_strpos(mb_strtolower($mn), $needle) !== false) return true;
        return false;
    });
}
$rows = bx_sort_rows($rows, $sort, $dir);

$statusBadge = fn($r) => (int)$r['gesperrt'] === 1 ? bx_badge('gesperrt', 'err') : bx_badge('aktiv', 'ok');
$datum = fn($r) => h(date('d.m.Y', strtotime($r['aktualisiert'])));

$cols = [
    'kundennummer'    => ['label' => 'Kundennr.', 'sort' => true],
    'firma'           => ['label' => 'Firma', 'sort' => true],
    'marke'           => ['label' => 'Marke', 'sort' => false, 'render' => fn($r) => h(implode(', ', $markenByKunde[(int)$r['id']] ?? []))],
    'ort'             => ['label' => 'Ort', 'sort' => true],
    'ansprechpartner' => ['label' => 'Ansprechpartner', 'sort' => true],
    'gesperrt'        => ['label' => 'Status', 'sort' => true, 'render' => $statusBadge],
    'aktualisiert'    => ['label' => 'zuletzt geändert', 'sort' => true, 'render' => $datum],
];

$kiKopf = '<button type="button" class="btn btn-ghost" onclick="document.getElementById(\'kundeKiDlg\').showModal()">Kunde aus Text (KI)</button>'
        . ' ' . bx_btn('Neuer Kunde', '?p=kunde&id=neu', 'primary');
render_header('kunden', 'Kunden');
bx_head('Kunden', count($rows) . ' Einträge', $kiKopf);
if (isset($_GET['geloescht'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Kunde gelöscht (' . (int)$_GET['geloescht'] . ' Datensätze entfernt).</div>';
if (isset($_GET['kifehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['kifehler']) . '</div>';
?>
<dialog id="kundeKiDlg" style="border:1px solid var(--line,#ddd);border-radius:12px;max-width:560px;width:92%;padding:0">
  <form method="post" action="?p=kunde_ki" style="margin:0;padding:18px 20px">
    <input type="hidden" name="aktion" value="ki_kunde">
    <div class="bx-row" style="justify-content:space-between;align-items:center;margin-bottom:6px">
      <h2 style="margin:0;font-size:17px">Kunde aus Text anlegen</h2>
      <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('kundeKiDlg').close()">Schließen</button>
    </div>
    <p class="muted" style="margin:0 0 10px">Adressblock, Impressum oder E-Mail-Signatur einfügen – die KI erkennt Firma, Adresse, Land usw. und füllt das Neuanlage-Formular. Dort prüfst du alles und speicherst.</p>
    <div class="bx-field" style="margin:0">
      <label>Text einfügen</label>
      <textarea name="text" rows="8" required autofocus style="width:100%;box-sizing:border-box;font-family:monospace;font-size:13px" placeholder="Nature Peak Limited&#10;UNIT 1005, 10F BOSS COMM CTR&#10;28 FERRY ST YAU MA TEI&#10;HONG KONG&#10;&#10;BRN: 81078614"></textarea>
    </div>
    <div class="bx-row" style="margin-top:var(--sp-3)">
      <button class="btn btn-primary" type="submit">Analysieren &amp; übernehmen</button>
    </div>
  </form>
</dialog>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="kunden">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Firma, Ort, Ansprechpartner …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=kunden">zurücksetzen</a><?php endif; ?>
</form>
<?php
bx_table($cols, array_values($rows), [
    'baseUrl' => '?p=kunden' . ($q !== '' ? '&q=' . urlencode($q) : ''),
    'sort'    => $sort,
    'dir'     => $dir,
    'rowUrl'  => fn($r) => '?p=kunde&id=' . $r['id'],
    'empty'   => 'Keine Kunden gefunden.',
]);
render_footer();
