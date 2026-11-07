<?php
// Produktion (neu) – neue Produktionsübersicht im Aufbau. Läuft parallel zur bestehenden Liste
// (module/produktion/liste.php), die unverändert bleibt. Wenn fertig, ersetzt sie die Liste.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

render_header('produktion_neu', 'Produktion (neu)');
bx_head('Produktion (neu)', 'im Aufbau');
?>
<div class="bx-panel">
  <div class="muted">Noch leer.</div>
</div>
<?php render_footer();
