<?php
// Produktionsmodus – tablettaugliches Abarbeiten eines Produktionsauftrags Schritt für Schritt.
// Charge/MHD kommen vom System (nur Anzeige). Beim Abschließen wird protokolliert, WER den Schritt
// WANN erledigt hat (erledigt_von/at); Material wird nach FEFO abgebucht, der letzte Schritt bucht
// die Fertigware ein (erp_schritt_abschliessen). Admin kann Schritte direkt abhaken/zurücksetzen
// (reine Statuskorrektur über erp_schritt_status_setzen – ohne Lager-/Chargenbewegung).
$id = (int)($_GET['id'] ?? 0);
$akteur = (string)(pr_benutzer()['name'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    $schritt_id = (int)($_POST['schritt_id'] ?? 0);
    if ($aktion === 'erledigen') {
        $r = erp_schritt_abschliessen($schritt_id, $akteur);
        flash($r['ok'] ? ($r['fertig'] ? 'Letzter Schritt erledigt – Produktion fertig, Fertigware eingebucht.' : 'Schritt „' . $r['station'] . '" erledigt.')
                       : ($r['msg'] ?: 'Schritt konnte nicht abgeschlossen werden.'), $r['ok'] ? 'ok' : 'warn');
    } elseif (($aktion === 'admin_done' || $aktion === 'admin_undo') && pr_ist_admin()) {
        $r = erp_schritt_status_setzen($schritt_id, $aktion === 'admin_done', $akteur);
        flash($r['ok'] ? ($aktion === 'admin_done' ? 'Schritt als erledigt markiert (Admin).' : 'Schritt zurückgesetzt (Admin).')
                       : ($r['msg'] ?: 'Konnte den Schritt nicht ändern.'), $r['ok'] ? 'ok' : 'warn');
    }
    weiter('?p=run&id=' . $id);
}

$pa = erp_pa($id);
if (!$pa) { kopf('Produktionsmodus'); seitenkopf('Nicht gefunden'); echo '<div class="bx-panel"><a class="btn btn-ghost" href="?p=liste">Zurück</a></div>'; fuss(); return; }
$schritte = erp_pa_schritte($id);
$charge   = erp_pa_charge_info($id);
$istAdmin = pr_ist_admin();

$total = count($schritte);
$fertig_cnt = 0; $erster_offen = 0;
foreach ($schritte as $s) { if ((int)($s['erledigt'] ?? 0) === 1) $fertig_cnt++; elseif ($erster_offen === 0) $erster_offen = (int)$s['id']; }
$cur = null;
foreach ($schritte as $s) if ((int)$s['id'] === $erster_offen) { $cur = $s; break; }

kopf($pa['nummer'] . ' – Produktionsmodus', 'liste');
seitenkopf('Produktionsmodus · ' . (string)$pa['nummer'], (string)($pa['produkt_name'] ?? ''),
    '<a class="btn btn-ghost btn-sm" href="?p=pa&id=' . $id . '">Zur Übersicht</a>');
?>
<div class="bx-panel" style="margin-bottom:16px">
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px 28px">
    <div><div class="muted" style="font-size:13px">Menge</div><div><?= number_format((int)$pa['menge'], 0, ',', '.') ?> Packungen</div></div>
    <div><div class="muted" style="font-size:13px">Charge<?= $charge['gebucht'] ? '' : ' (geplant)' ?></div><div><?= h($charge['nr']) ?></div></div>
    <div><div class="muted" style="font-size:13px">MHD<?= $charge['gebucht'] ? '' : ' (+18 Mon.)' ?></div><div><?= $charge['mhd'] ? h(date('d.m.Y', strtotime($charge['mhd']))) : '–' ?></div></div>
    <div><div class="muted" style="font-size:13px">Fortschritt</div><div><?= $fertig_cnt ?> / <?= $total ?></div></div>
  </div>
  <p class="muted" style="font-size:12px;margin:10px 0 0">Chargennummer und MHD vergibt das System automatisch.</p>
</div>

<?php if ($cur):
    $isGate = str_contains((string)$cur['station'], 'Freigabe');
    $anl = station_anleitung_text((string)$cur['station']);
    $mat = erp_schritt_material($id, (string)$cur['station']); ?>
<div class="bx-panel" style="margin-bottom:16px;border-color:var(--gruen);background:rgba(29,158,117,.06)">
  <div class="muted">Jetzt dran · Schritt <?= $fertig_cnt + 1 ?> von <?= $total ?></div>
  <h2 style="margin:4px 0 8px;font-size:22px"><?= h((string)$cur['station']) ?></h2>
  <?php if ($anl !== ''): ?><p style="margin:0 0 12px;font-size:15px"><?= h($anl) ?></p><?php endif; ?>

  <?php if ($mat['zeilen']): ?>
  <div style="margin:0 0 14px">
    <div class="muted" style="font-size:13px;margin-bottom:6px">Aus dem Lager holen<?php if ($mat['soll_menge'] !== null): ?> · benötigt <strong><?= menge_txt($mat['soll_menge']) ?> <?= h((string)$mat['soll_einheit']) ?></strong><?php endif; ?>:</div>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Material</th><th class="bx-num">Menge</th><th class="bx-num">Bestand</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($mat['zeilen'] as $z):
            $knapp = isset($z['verfuegbar']) && (float)$z['verfuegbar'] + 0.0001 < (float)$z['menge']; ?>
        <tr>
          <td><?= h((string)$z['name']) ?><?php if (!empty($z['detail'])): ?> <span class="muted" style="font-size:12px">· <?= h((string)$z['detail']) ?></span><?php endif; ?></td>
          <td class="bx-num"><?= menge_txt($z['menge']) ?> <?= h((string)$z['einheit']) ?></td>
          <td class="bx-num"<?= $knapp ? ' style="color:#8f231b"' : '' ?>><?= isset($z['verfuegbar']) ? menge_txt($z['verfuegbar']) . ' ' . h((string)$z['einheit']) : '' ?></td>
          <td class="bx-num"><span class="muted" style="font-size:12px" title="Pick-to-Light folgt">Blinker folgt</span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>

  <form method="post" style="margin:0" onsubmit="return confirm('Schritt &quot;<?= h((string)$cur['station']) ?>&quot; jetzt abschließen?');">
    <input type="hidden" name="aktion" value="erledigen">
    <input type="hidden" name="schritt_id" value="<?= (int)$cur['id'] ?>">
    <button type="submit" class="btn btn-primary" style="font-size:16px;padding:12px 28px"><?= $isGate ? 'Freigeben' : 'Erledigt' ?></button>
  </form>
</div>
<?php else: ?>
<div class="bx-panel badge-ok" style="margin-bottom:16px;padding:14px 18px">Alle Schritte erledigt – die Produktion ist abgeschlossen.</div>
<?php endif; ?>

<div class="bx-panel">
  <h2 style="margin-top:0">Ablauf</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th class="bx-num">#</th><th>Station</th><th>Status</th><th>Erledigt von</th><th>Wann</th><?php if ($istAdmin): ?><th>Admin</th><?php endif; ?></tr></thead>
    <tbody>
      <?php foreach ($schritte as $i => $s):
          $done = (int)($s['erledigt'] ?? 0) === 1;
          $dran = (int)$s['id'] === $erster_offen; ?>
      <tr<?= $dran ? ' style="background:var(--panel-2)"' : '' ?>>
        <td class="bx-num"><?= (int)$i + 1 ?></td>
        <td><?= h((string)$s['station']) ?></td>
        <td><?= $done ? '<span class="badge badge-ok">erledigt</span>' : ($dran ? '<span class="badge badge-info">als Nächstes</span>' : '<span class="badge badge-warn">offen</span>') ?></td>
        <td class="muted"><?= h((string)($s['erledigt_von'] ?? '')) ?></td>
        <td class="muted"><?= h(fmt_zeit($s['erledigt_at'] ?? null)) ?></td>
        <?php if ($istAdmin): ?>
        <td class="bx-num">
          <form method="post" style="margin:0;display:inline">
            <input type="hidden" name="aktion" value="<?= $done ? 'admin_undo' : 'admin_done' ?>">
            <input type="hidden" name="schritt_id" value="<?= (int)$s['id'] ?>">
            <button type="submit" class="btn btn-ghost btn-sm"><?= $done ? 'Zurücksetzen' : 'Abhaken' ?></button>
          </form>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($istAdmin): ?>
  <p class="muted" style="font-size:12px;margin:10px 0 0">Admin: „Abhaken"/„Zurücksetzen" ändert nur den Schritt-Status (auch außer der Reihe) – ohne Lager-/Chargenbewegung. Das normale „Erledigt" oben bucht Material ab und am Ende die Fertigware ein.</p>
  <?php endif; ?>
</div>
<?php fuss();
