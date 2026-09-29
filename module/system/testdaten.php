<?php
// Testdaten (NUR lokal): Lager mit Beständen füllen, damit man offline Produktion/Bestand/Einkauf
// durchspielen kann. Schreibt ausschliesslich Test-Chargen (Marker 'TESTDATEN'); auf beta/live gesperrt.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/tools/testdaten_lager.php';

$lokal = ist_lokal();
$hinweis = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $lokal) {
    $a = (string)($_POST['aktion'] ?? '');
    if ($a === 'fuellen')     { $r = testdaten_lager_fuellen();       $hinweis = ['ok', $r['neu'] . ' Artikel mit Testbestand gefüllt (' . $r['schon'] . ' hatten schon Bestand).']; }
    elseif ($a === 'reset')   { $n = testdaten_lager_zuruecksetzen(); $hinweis = ['ok', $n . ' Test-Chargen entfernt.']; }
}

render_header('testdaten', 'Testdaten (lokal)');
bx_head('Testdaten (lokal)', 'Nur für den lokalen Offline-Server: Lager mit Testbeständen füllen, um Produktion/Bestand/Einkauf durchzuspielen.');

if (!$lokal) {
    echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:14px 16px"><strong>Nur lokal verfügbar.</strong> Dieses Werkzeug schreibt Testdaten und ist deshalb ausschließlich auf dem lokalen Offline-Server (127.0.0.1) nutzbar – nicht auf beta/live.</div>';
    render_footer();
    return;
}

if ($hinweis) echo '<div class="bx-panel ' . ($hinweis[0] === 'ok' ? 'badge-ok' : '') . '" style="padding:10px 14px">' . h($hinweis[1]) . '</div>';
$s = testdaten_lager_stat();
?>
<div class="bx-panel">
  <h2 style="margin-top:0;font-size:16px">Lager mit Testbeständen füllen</h2>
  <p class="muted" style="margin-top:0">Legt für die in Rezepturen verwendeten <strong>Rohstoffe</strong> (<?= $s['roh'] ?>) und alle <strong>Verpackungen/Etiketten</strong> (<?= $s['verp'] ?>) je eine große, freie Charge an – nur wo noch kein Bestand ist. So laufen Produktion, Reservierung und Bestand, ohne dass alles „fehlt". Idempotent, jederzeit wieder entfernbar.</p>
  <div class="bx-row" style="gap:16px;flex-wrap:wrap;margin:10px 0">
    <div><div class="k muted" style="font-size:12px">Chargen mit Bestand</div><div><strong><?= $s['frei'] ?></strong></div></div>
    <div><div class="k muted" style="font-size:12px">davon Testdaten</div><div><?= $s['test'] ?></div></div>
  </div>
  <div class="bx-row" style="gap:8px;flex-wrap:wrap">
    <form method="post" style="margin:0" data-busy="Fülle Lager…"><input type="hidden" name="aktion" value="fuellen"><button class="btn btn-primary" type="submit">Lager füllen</button></form>
    <form method="post" style="margin:0" onsubmit="return confirm('Alle unangetasteten Test-Chargen entfernen?');"><input type="hidden" name="aktion" value="reset"><button class="btn btn-ghost" type="submit">Testbestände entfernen</button></form>
  </div>
</div>
<div class="bx-panel" style="font-size:13px;color:var(--muted)">
  Hinweis: Die Testbestände sind bewusst sehr groß, damit nichts „fehlt". Willst du den <strong>Einkauf</strong> (Bestellungen/Fehlmengen) durchspielen, entferne die Testbestände wieder – dann tauchen die Bedarfe auf.
</div>
<?php render_footer(); ?>
