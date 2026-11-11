<?php
// Testumgebung: Datenstand von live holen (Paket 12.11.2026 Teil 2). Nur Admin, nur in der Testumgebung.
// Läuft Tabelle für Tabelle per fetch, damit kein Server-Zeitlimit greift. Richtung immer live -> test.
require_once __DIR__ . '/../../core/test_abzug.php';

$sperre = ta_sperre();
$aktion = (string)($_POST['aktion'] ?? '');
if ($aktion !== '') {
    header('Content-Type: application/json; charset=utf-8');
    while (ob_get_level() > 0) ob_end_clean();
    if ($sperre !== '') { echo json_encode(['ok' => false, 'msg' => $sperre]); exit; }
    @set_time_limit(300);
    try {
        if ($aktion === 'liste') { echo json_encode(['ok' => true, 'tabellen' => ta_tabellen()]); exit; }
        if ($aktion === 'tabelle') {
            $t = (string)($_POST['t'] ?? '');
            $n = ta_tabelle($t);
            if ($t === 'benutzer') ta_fertig();   // letzte Tabelle: Zeitpunkt merken
            echo json_encode(['ok' => true, 'n' => $n]); exit;
        }
        if ($aktion === 'dateien') { echo json_encode(ta_dateien(1500)); exit; }
        if ($aktion === 'fertig') { init_schema(); ta_fertig(); echo json_encode(['ok' => true]); exit; }
    } catch (Throwable $e) { echo json_encode(['ok' => false, 'msg' => $e->getMessage()]); exit; }
    echo json_encode(['ok' => false, 'msg' => 'Unbekannte Aktion.']); exit;
}

render_header('test_abzug', 'Datenstand von live holen');
echo '<div class="bx-panel" style="padding:16px 20px">';
echo '<p>Kopiert alle Tabellen der Live-Datenbank in die Datenbank dieser Testumgebung. Was hier in der Testumgebung geändert wurde, ist danach weg. Live wird nur gelesen, nie verändert.</p>';
$am = (string) meta_get('test_abzug_am', '');
echo '<p>Letzter Abzug: ' . ($am !== '' ? h(fmt_zeit($am)) : 'noch keiner') . '</p>';
if ($sperre !== '') {
    echo '<p style="color:#8f231b">' . h($sperre) . '</p></div>';
    render_footer();
    return;
}
?>
<p><label><input type="checkbox" id="ta-dateien" checked> fehlende Upload-Dateien (Dokumente, Bilder) mitkopieren</label></p>
<button class="bx-btn" id="ta-start" type="button">Abzug starten</button>
<div id="ta-stand" style="margin-top:14px"></div>
</div>
<script>
(function () {
  const stand = document.getElementById('ta-stand');
  async function post(d) {
    const fd = new FormData(); Object.entries(d).forEach(([k, v]) => fd.append(k, v));
    const r = await fetch('?p=test_abzug', {method: 'POST', body: fd, credentials: 'same-origin'});
    return r.json();
  }
  document.getElementById('ta-start').addEventListener('click', async function () {
    if (!confirm('Alle Daten dieser Testumgebung werden durch den Live-Stand ersetzt. Weiter?')) return;
    this.disabled = true;
    const l = await post({aktion: 'liste'});
    if (!l.ok) { stand.textContent = l.msg; return; }
    let dateiText = '';
    if (document.getElementById('ta-dateien').checked) {   // Dateien zuerst: nach der Tabelle „benutzer“ kann die Anmeldung abgelaufen sein
      for (let k = 0; k < 200; k++) {
        stand.textContent = 'Dateien werden kopiert …';
        const d = await post({aktion: 'dateien'});
        dateiText = d.msg;
        if (!d.ok || d.msg.indexOf('noch nicht fertig') < 0) break;
      }
    }
    let i = 0, fehler = 0;
    for (const t of l.tabellen) {   // app_meta zuerst, benutzer zuletzt (setzt auch den Zeitpunkt des Abzugs)
      i++; stand.textContent = 'Tabelle ' + i + ' von ' + l.tabellen.length + ': ' + t;
      try { const r = await post({aktion: 'tabelle', t: t}); if (!r.ok) { fehler++; console.warn(t, r.msg); } }
      catch (e) { fehler++; console.warn(t, e); }
    }
    stand.textContent = 'Fertig: ' + l.tabellen.length + ' Tabellen' + (fehler ? ', ' + fehler + ' mit Fehler (siehe Browser-Konsole)' : '') + '. ' + dateiText + ' Bitte neu anmelden (Benutzer kommen jetzt aus live).';
  });
})();
</script>
<?php
render_footer();
