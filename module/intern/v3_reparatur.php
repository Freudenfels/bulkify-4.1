<?php
// TEMPORÄR (v3-Migration): Admin-Seite zum Nachtragen fehlender Preise/Mengen an v3-importierten
// Angeboten – damit man das auf beta per Button starten kann (keine Kommandozeile nötig).
// Logik: v3_angebote_reparieren() in core/schema.php. NACH Abschluss der Migration alles entfernen
// (diese Datei, core-Funktionen v3_angebote_reparieren/_treffer/_stueck, Route, Rolle, Menü, CLI-Tool).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

if (!has_role('admin')) { render_header('', 'Kein Zugriff'); echo '<div class="bx-panel">Nur für Admins.</div>'; render_footer(); exit; }

$kid = (int)($_GET['kunde'] ?? 0);
$erg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'reparieren') {
    $kidP = (int)($_POST['kunde'] ?? 0);
    $r = v3_angebote_reparieren($kidP ?: null, true);   // SCHREIBEN
    $_SESSION['v3rep'] = $r;
    header('Location: ?p=v3_reparatur' . ($kidP ? '&kunde=' . $kidP : '') . '&fertig=1'); exit;
}

// Vorschau (Trockenlauf) für die aktuelle Auswahl
$vorschau = v3_angebote_reparieren($kid ?: null, false);
$kunden = all("SELECT k.id, k.firma FROM kunden k WHERE EXISTS (SELECT 1 FROM angebot a WHERE a.kunde_id=k.id AND a.v3_id IS NOT NULL) ORDER BY k.firma");

render_header('angebote', 'v3-Angebote reparieren');
bx_head('v3-Angebote reparieren',
        'Trägt fehlende Preise und Stück je Packung an importierten Angeboten aus den v3-Kundenpreisen nach. Einmalige Migration.',
        bx_btn('Zur Angebotsliste', '?p=angebote', 'ghost'));

if (isset($_GET['fertig']) && !empty($_SESSION['v3rep'])) {
    $r = $_SESSION['v3rep']; unset($_SESSION['v3rep']);
    echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Fertig: '
       . (int)$r['preis_staffel'] . ' Staffel-Preise, ' . (int)$r['preis_position'] . ' Positions-Preise und '
       . (int)$r['stueck_staffel'] . ' Stück-je-Packung nachgetragen.</div>';
}
?>
<div class="bx-panel">
  <form method="get" class="bx-row" style="gap:10px;align-items:flex-end;margin:0">
    <input type="hidden" name="p" value="v3_reparatur">
    <div class="bx-field" style="margin:0;min-width:260px"><label>Kunde</label>
      <select name="kunde" onchange="this.form.submit()">
        <option value="0">– alle v3-Angebote –</option>
        <?php foreach ($kunden as $k): ?><option value="<?= (int)$k['id'] ?>" <?= $kid===(int)$k['id']?'selected':'' ?>><?= h($k['firma']) ?></option><?php endforeach; ?>
      </select>
    </div>
  </form>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0">Vorschau (Trockenlauf)</h2>
  <div class="bx-cards">
    <div class="bx-card"><div class="k">Angebote geprüft</div><div class="v"><?= (int)$vorschau['angebote'] ?></div></div>
    <div class="bx-card"><div class="k">Staffel-Preise nachtragbar</div><div class="v"><?= (int)$vorschau['preis_staffel'] ?></div></div>
    <div class="bx-card"><div class="k">Positions-Preise nachtragbar</div><div class="v"><?= (int)$vorschau['preis_position'] ?></div></div>
    <div class="bx-card"><div class="k">Stück/Packung ergänzbar</div><div class="v"><?= (int)$vorschau['stueck_staffel'] ?></div></div>
  </div>
  <?php $aenderbar = (int)$vorschau['preis_staffel'] + (int)$vorschau['preis_position'] + (int)$vorschau['stueck_staffel']; ?>
  <div style="margin-top:14px">
    <?php if ($aenderbar > 0): ?>
      <form method="post" onsubmit="return confirm('<?= $aenderbar ?> Werte aus den v3-Kundenpreisen nachtragen? Es werden nur leere (0) Preise/Mengen gefüllt.');">
        <input type="hidden" name="aktion" value="reparieren"><input type="hidden" name="kunde" value="<?= $kid ?>">
        <button class="btn btn-primary" type="submit" data-busy="…">Jetzt nachtragen (<?= $aenderbar ?>)</button>
      </form>
    <?php else: ?>
      <div class="muted">Nichts nachzutragen – alle geprüften Angebote haben Preise und Stück je Packung. </div>
    <?php endif; ?>
  </div>
</div>

<?php
// Die echten v3-Lücken (auch nach Nachtragen kein Preis) – müssen manuell bepreist werden.
$offen = [];
foreach ($vorschau['offen'] as $o) $offen[trim($o['nummer'])] = $o;
if ($offen): ?>
<div class="bx-panel">
  <h2 style="margin-top:0">Ohne v3-Preis – manuell bepreisen (<?= count($offen) ?>)</h2>
  <p class="muted" style="margin-top:0;font-size:13px">Für diese Angebote gibt es in v3 keinen Preis. Über den Reiter „Preise" in der Kundenansicht oder die Preismatrix bepreisen.</p>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Angebot</th><th>Kunde</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($offen, 0, 100) as $o): ?>
      <tr><td><?= h($o['nummer']) ?></td><td><?= h($o['firma']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif;
render_footer();
