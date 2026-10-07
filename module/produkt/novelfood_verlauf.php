<?php
// Novel-Food Aktualisierungs-Verlauf: zeigt je Abgleichslauf (Button oder Monatsroutine),
// was NEU ist, was sich GEÄNDERT hat (Statuswechsel zuerst) und was ENTFERNT wurde.
// Gruppiert nach Lauf, neuester oben. Datenquelle: novelfood_lauf + novelfood_change.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$laeufe = all("SELECT * FROM novelfood_lauf ORDER BY gestartet_at DESC, id DESC LIMIT 60");

$vonLabel = ['manuell' => 'Manuell (Button)', 'auto' => 'Automatisch', 'cli' => 'Zeitplan'];
$artLabel = ['neu' => 'Neu', 'geaendert' => 'Geändert', 'entfernt' => 'Entfernt'];

render_header('novelfood', 'Novel Food – Aktualisierungs-Verlauf');
bx_head('Novel Food – Aktualisierungs-Verlauf', count($laeufe) . ' Lauf/Läufe protokolliert',
        bx_btn('Katalog aktualisieren', '?p=novelfood_import') . ' ' . bx_btn('Zur Schnellsuche', '?p=novelfood', 'ghost'));

if (!$laeufe): ?>
  <div class="bx-panel">
    <p class="muted" style="margin:0">Noch kein Abgleich gelaufen. Starte einen über
      <a href="?p=novelfood_import">Katalog aktualisieren</a> („Direkt aus dem EU-Katalog") – danach steht hier,
      was sich bei jedem Lauf geändert hat.</p>
  </div>
<?php render_footer(); return; endif; ?>

<?php foreach ($laeufe as $l):
    $lid = (int)$l['id'];
    $changes = all("SELECT * FROM novelfood_change WHERE lauf_id=? ORDER BY status_wechsel DESC, art, name", [$lid]);
    $status  = array_values(array_filter($changes, fn($c) => (int)$c['status_wechsel'] === 1));
    $neu     = array_values(array_filter($changes, fn($c) => $c['art'] === 'neu'));
    $geae    = array_values(array_filter($changes, fn($c) => $c['art'] === 'geaendert' && (int)$c['status_wechsel'] !== 1));
    $entf    = array_values(array_filter($changes, fn($c) => $c['art'] === 'entfernt'));
    $istFehler = ($l['status'] === 'fehler');
    $farbe = $istFehler ? 'var(--err)' : ((int)$l['anzahl_status'] > 0 ? 'var(--warn)' : 'var(--gruen)');
?>
  <div class="bx-panel" style="border-left:4px solid <?= $farbe ?>">
    <div class="bx-row" style="justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap">
      <div style="font-size:16px"><?= h(fmt_zeit((string)$l['gestartet_at'])) ?>
        <span class="muted" style="font-size:13px">· <?= h($vonLabel[$l['ausgeloest_von']] ?? (string)$l['ausgeloest_von']) ?><?= $l['benutzer'] ? ' · ' . h((string)$l['benutzer']) : '' ?></span>
      </div>
      <?php if ($istFehler): ?>
        <div style="color:var(--err);font-weight:600">Fehlgeschlagen</div>
      <?php else: ?>
        <div class="muted" style="font-size:13px"><?= (int)$l['anzahl_gesamt'] ?> im Katalog · <?= (int)$l['anzahl_uebersetzt'] ?> übersetzt · <?= number_format((int)$l['dauer_ms'] / 1000, 1, ',', '.') ?> s</div>
      <?php endif; ?>
    </div>

    <?php if ($istFehler): ?>
      <p style="color:var(--err);margin:10px 0 0"><?= h((string)$l['meldung']) ?></p>
    <?php else: ?>
      <div class="bx-cards" style="margin-top:12px">
        <div class="bx-card"><div class="k">Neu</div><div class="v" style="<?= (int)$l['anzahl_neu'] ? 'color:var(--gruen)' : '' ?>"><?= (int)$l['anzahl_neu'] ?></div></div>
        <div class="bx-card"><div class="k">Geändert</div><div class="v" style="<?= (int)$l['anzahl_geaendert'] ? 'color:var(--warn)' : '' ?>"><?= (int)$l['anzahl_geaendert'] ?></div></div>
        <div class="bx-card"><div class="k">davon Statuswechsel</div><div class="v" style="<?= (int)$l['anzahl_status'] ? 'color:var(--warn)' : '' ?>"><?= (int)$l['anzahl_status'] ?></div></div>
        <div class="bx-card"><div class="k">Entfernt</div><div class="v" style="<?= (int)$l['anzahl_entfernt'] ? 'color:var(--err)' : '' ?>"><?= (int)$l['anzahl_entfernt'] ?></div></div>
      </div>

      <?php if (!$changes): ?>
        <p class="muted" style="margin:12px 0 0">Keine Änderungen – der Katalog war bereits aktuell.</p>
      <?php else: ?>

        <?php if ($status): ?>
          <div style="margin-top:14px;font-weight:600;color:var(--warn)">Statuswechsel (<?= count($status) ?>) – rechtlich relevant</div>
          <div class="bx-tablewrap" style="margin-top:6px"><table class="bx-table">
            <thead><tr><th>Name</th><th>Status alt → neu</th></tr></thead>
            <tbody>
            <?php foreach ($status as $c): ?>
              <tr><td><?= h((string)$c['name']) ?><?php if ($c['code']): ?> <span class="muted" style="font-size:11px"><?= h((string)$c['code']) ?></span><?php endif; ?></td>
                  <td><span class="muted"><?= h((string)($c['status_alt'] ?: '–')) ?></span> → <strong style="color:var(--warn)"><?= h((string)($c['status_neu'] ?: '–')) ?></strong></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
        <?php endif; ?>

        <?php if ($neu): ?>
          <details style="margin-top:12px" <?= count($neu) <= 20 ? 'open' : '' ?>>
            <summary style="cursor:pointer;font-weight:600;color:var(--gruen)">Neu im Katalog (<?= count($neu) ?>)</summary>
            <div class="bx-tablewrap" style="margin-top:6px"><table class="bx-table">
              <thead><tr><th>Name</th><th>Status</th></tr></thead>
              <tbody>
              <?php foreach ($neu as $c): ?>
                <tr><td><?= h((string)$c['name']) ?><?php if ($c['code']): ?> <span class="muted" style="font-size:11px"><?= h((string)$c['code']) ?></span><?php endif; ?></td>
                    <td><?= h((string)($c['status_neu'] ?: '–')) ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table></div>
          </details>
        <?php endif; ?>

        <?php if ($geae): ?>
          <details style="margin-top:10px">
            <summary style="cursor:pointer;font-weight:600">Sonstige Änderungen ohne Statuswechsel (<?= count($geae) ?>)</summary>
            <div class="bx-tablewrap" style="margin-top:6px"><table class="bx-table">
              <thead><tr><th>Name</th><th>Geänderte Felder</th></tr></thead>
              <tbody>
              <?php foreach ($geae as $c): ?>
                <tr><td><?= h((string)$c['name']) ?></td><td class="muted" style="font-size:13px"><?= h((string)$c['felder']) ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table></div>
          </details>
        <?php endif; ?>

        <?php if ($entf): ?>
          <details style="margin-top:10px">
            <summary style="cursor:pointer;font-weight:600;color:var(--err)">Aus dem Katalog entfernt (<?= count($entf) ?>)</summary>
            <div class="bx-tablewrap" style="margin-top:6px"><table class="bx-table">
              <thead><tr><th>Name</th><th>letzter Status</th></tr></thead>
              <tbody>
              <?php foreach ($entf as $c): ?>
                <tr><td><?= h((string)$c['name']) ?><?php if ($c['code']): ?> <span class="muted" style="font-size:11px"><?= h((string)$c['code']) ?></span><?php endif; ?></td>
                    <td class="muted"><?= h((string)($c['status_alt'] ?: '–')) ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table></div>
          </details>
        <?php endif; ?>

      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<p class="muted" style="font-size:12px">Quelle: EU Novel Food Katalog (Kommission), direkt über die offene API abgeglichen. Statuswechsel sind rechtlich am wichtigsten – im Zweifel die offizielle Quelle prüfen.</p>
<?php
render_footer();
