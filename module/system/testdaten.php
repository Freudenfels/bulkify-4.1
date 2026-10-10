<?php
// Testdaten-Werkzeug (nur Admin). Zwei Teile:
//  1) Produktions-Durchspiel-Testset: ein KOMPLETT isoliertes Set (eigener Test-Kunde, eigene Test-Rohstoffe/
//     -Verpackungen, je Darreichungsform Auftrag+Produktionsauftrag inkl. Bestand) – alles mit Marker
//     'TESTSEED-DURCHSPIEL'. Gemeinsam anlegen/löschen. Auch ONLINE nutzbar, weil nichts Echtes berührt wird.
//  2) Lager mit Testbeständen füllen (echte Items) – bewusst NUR lokal.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/tools/testdaten_durchspiel.php';
require_once BX_ROOT . '/tools/testdaten_lager.php';

if (!has_role('admin')) { render_header('testdaten', 'Testdaten'); bx_head('Testdaten', 'Nur für Admins.'); render_footer(); return; }

$lokal = ist_lokal();
$hinweis = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string)($_POST['aktion'] ?? '');
    if ($a === 'durchspiel_anlegen')      { $r = testdaten_durchspiel_anlegen();  $hinweis = ['ok', $r['msg']]; }
    elseif ($a === 'durchspiel_loeschen') { $d = testdaten_durchspiel_loeschen();  $hinweis = ['ok', 'Testset entfernt: ' . $d['auftraege'] . ' Aufträge, ' . $d['produkte'] . ' Produkte, ' . $d['rezepturen'] . ' Rezepturen, ' . $d['items'] . ' Test-Artikel' . ($d['kunde'] ? ' + Test-Kunde' : '') . '.']; }
    elseif ($a === 'fuellen' && $lokal)   { $r = testdaten_lager_fuellen();       $hinweis = ['ok', $r['neu'] . ' Artikel mit Testbestand gefüllt (' . $r['schon'] . ' hatten schon Bestand).']; }
    elseif ($a === 'reset' && $lokal)     { $n = testdaten_lager_zuruecksetzen(); $hinweis = ['ok', $n . ' Test-Chargen entfernt.']; }
}

render_header('testdaten', 'Testdaten');
bx_head('Testdaten', 'Produktions-Testset zum Durchspielen – gemeinsam anlegen und wieder löschen, ohne echte Daten anzufassen.');

if ($hinweis) echo '<div class="bx-panel ' . ($hinweis[0] === 'ok' ? 'badge-ok' : '') . '" style="padding:10px 14px">' . h($hinweis[1]) . '</div>';

$ds = testdaten_durchspiel_stat();
?>
<div class="bx-panel">
  <h2 style="margin-top:0;font-size:16px">Produktions-Testset (Durchspiel)</h2>
  <p class="muted" style="margin-top:0">Legt ein <strong>komplett isoliertes</strong> Testset an: einen Test-Kunden „Durchspiel-Testbetrieb", eigene Test-Rohstoffe und -Verpackungen (mit Lagerbestand) und je Darreichungsform (Kapsel, Tablette, Softgel, Pulver, Flüssig, Stick, Gummi) einen <strong>produktionsfertigen Auftrag</strong> inkl. Produktionsauftrag (Vor-Produktion) und freigegebenem Etikett. So kannst du die ganze Produktion durchspielen (auch in der Mitarbeiter-App), ohne echte Aufträge/Kunden/Lager anzufassen – und alles mit einem Klick wieder entfernen.</p>
  <div class="bx-row" style="gap:18px;flex-wrap:wrap;margin:10px 0">
    <div><div class="k muted" style="font-size:12px">Status</div><div><?= $ds['vorhanden'] ? bx_badge('angelegt','ok') : bx_badge('nicht angelegt') ?></div></div>
    <div><div class="k muted" style="font-size:12px">Test-Aufträge</div><div><strong><?= (int)$ds['auftraege'] ?></strong></div></div>
    <div><div class="k muted" style="font-size:12px">Test-Produkte</div><div><?= (int)$ds['produkte'] ?></div></div>
    <div><div class="k muted" style="font-size:12px">Test-Artikel</div><div><?= (int)$ds['items'] ?></div></div>
  </div>
  <div class="bx-row" style="gap:8px;flex-wrap:wrap">
    <?php if (!$ds['vorhanden']): ?>
      <form method="post" style="margin:0" data-busy="Lege an…"><input type="hidden" name="aktion" value="durchspiel_anlegen"><button class="btn btn-primary" type="submit">Testset anlegen</button></form>
    <?php else: ?>
      <form method="post" style="margin:0" onsubmit="return confirm('Komplettes Produktions-Testset (Test-Kunde, -Aufträge, -Produkte, -Rezepturen, -Artikel samt Bestand) wieder löschen? Nur die mit dem Marker TESTSEED-DURCHSPIEL versehenen Daten werden entfernt.');"><input type="hidden" name="aktion" value="durchspiel_loeschen"><button class="btn btn-danger" type="submit" data-busy="Lösche…">Testset löschen</button></form>
      <form method="post" style="margin:0" data-busy="Lege an…"><input type="hidden" name="aktion" value="durchspiel_anlegen"><button class="btn btn-ghost" type="submit">neu anlegen (falls Teile fehlen)</button></form>
    <?php endif; ?>
  </div>
  <p class="muted" style="font-size:12px;margin:10px 0 0">Sicher: Gelöscht wird ausschließlich, was den Marker <code>TESTSEED-DURCHSPIEL</code> trägt (Kunde/Artikel) bzw. an den Test-Aufträgen hängt. Echte Kunden, Aufträge, Rezepturen und Lagerbestände bleiben unberührt.</p>
</div>

<?php if ($lokal): $s = testdaten_lager_stat(); ?>
<div class="bx-panel">
  <h2 style="margin-top:0;font-size:16px">Echtes Lager mit Testbeständen füllen <span class="muted" style="font-weight:normal;font-size:13px">· nur lokal</span></h2>
  <p class="muted" style="margin-top:0">Legt für die in echten Rezepturen verwendeten <strong>Rohstoffe</strong> (<?= $s['roh'] ?>) und alle <strong>Verpackungen/Etiketten</strong> (<?= $s['verp'] ?>) je eine große freie Charge an – nur wo noch kein Bestand ist. Bewusst nur auf dem lokalen Server, weil es echte Items betrifft.</p>
  <div class="bx-row" style="gap:16px;flex-wrap:wrap;margin:10px 0">
    <div><div class="k muted" style="font-size:12px">Chargen mit Bestand</div><div><strong><?= $s['frei'] ?></strong></div></div>
    <div><div class="k muted" style="font-size:12px">davon Testdaten</div><div><?= $s['test'] ?></div></div>
  </div>
  <div class="bx-row" style="gap:8px;flex-wrap:wrap">
    <form method="post" style="margin:0" data-busy="Fülle Lager…"><input type="hidden" name="aktion" value="fuellen"><button class="btn btn-ghost" type="submit">Lager füllen</button></form>
    <form method="post" style="margin:0" onsubmit="return confirm('Alle unangetasteten Test-Chargen entfernen?');"><input type="hidden" name="aktion" value="reset"><button class="btn btn-ghost" type="submit">Testbestände entfernen</button></form>
  </div>
</div>
<?php endif; ?>
<?php render_footer(); ?>
