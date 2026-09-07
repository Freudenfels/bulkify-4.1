<?php
// EK-Lieferanten zuordnen: die Text-Lieferanten aus den EK-Preisen (Wellgreen, Vitanics, Buxtrade …)
// echten Lieferanten-Datensätzen zuordnen bzw. neu anlegen und alle Preise per id verknüpfen.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/ek_ki.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $akt = $_POST['aktion'] ?? '';
    if ($akt === 'zuordnen') {
        $r = ek_lieferant_zuordnen((string)($_POST['name'] ?? ''), ($_POST['lid'] ?? '') !== '' ? (int)$_POST['lid'] : null);
        $_SESSION['ekl_flash'] = $r['ok'] ? (($r['neu'] ? 'Neu angelegt & verknüpft: ' : 'Verknüpft: ') . h($r['firma']) . ' (' . (int)$r['zeilen'] . ' Zeilen).') : 'Nicht zugeordnet.';
        header('Location: ?p=ek_lieferanten'); exit;
    }
    if ($akt === 'bulk_verknuepfen' || $akt === 'bulk_anlegen') {
        @set_time_limit(300);
        $mitVorschlag = ($akt === 'bulk_verknuepfen');
        $n = 0;
        foreach (all("SELECT DISTINCT lieferant FROM ek_import WHERE lieferant IS NOT NULL AND lieferant<>'' AND lieferant_id IS NULL") as $z) {
            $name = (string)$z['lieferant'];
            if (ek_lief_ist_muell($name)) continue;
            $kand = ek_lieferant_kandidat($name);
            if ($mitVorschlag && !$kand) continue;      // bulk_verknuepfen: nur mit Treffer
            if (!$mitVorschlag && $kand) continue;       // bulk_anlegen: nur ohne Treffer
            if (ek_lieferant_zuordnen($name, $kand)['ok']) $n++;
        }
        $_SESSION['ekl_flash'] = $n . ($mitVorschlag ? ' Namen mit bestehendem Lieferanten verknüpft.' : ' neue Lieferanten angelegt & verknüpft.');
        header('Location: ?p=ek_lieferanten'); exit;
    }
}

$namen = all("SELECT lieferant, COUNT(*) AS n FROM ek_import WHERE lieferant IS NOT NULL AND lieferant<>'' AND lieferant_id IS NULL GROUP BY lieferant ORDER BY n DESC");
$lieferanten = all("SELECT id, firma FROM lieferanten ORDER BY firma");
$offenMitTreffer = 0; $offenOhne = 0;
foreach ($namen as $z) { if (ek_lief_ist_muell((string)$z['lieferant'])) continue; if (ek_lieferant_kandidat((string)$z['lieferant'])) $offenMitTreffer++; else $offenOhne++; }
$verknuepft = (int) scalar("SELECT COUNT(DISTINCT lieferant) FROM ek_import WHERE lieferant_id IS NOT NULL");

$flash = $_SESSION['ekl_flash'] ?? null; unset($_SESSION['ekl_flash']);

render_header('ek_import', 'EK-Lieferanten zuordnen');
bx_head('EK-Lieferanten zuordnen', 'Text-Lieferanten aus den EK-Preisen echten Lieferanten zuordnen', bx_btn('Zurück', '?p=ek_import', 'ghost'));
if ($flash) echo '<div class="bx-panel badge-ok" style="padding:8px 12px">' . $flash . '</div>';
?>
<div class="bx-panel" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between">
  <div class="muted" style="font-size:13px"><?= count($namen) ?> Text-Lieferanten noch nicht verknüpft · davon <?= $offenMitTreffer ?> mit passendem Datensatz, <?= $offenOhne ?> neu anzulegen · <?= $verknuepft ?> bereits verknüpft</div>
  <div class="bx-row" style="gap:8px">
    <?php if ($offenMitTreffer > 0): ?><form method="post" style="margin:0"><input type="hidden" name="aktion" value="bulk_verknuepfen"><button class="btn btn-primary btn-sm" type="submit" data-busy="…">Alle <?= $offenMitTreffer ?> mit Treffer verknüpfen</button></form><?php endif; ?>
    <?php if ($offenOhne > 0): ?><form method="post" style="margin:0" onsubmit="return confirm('<?= $offenOhne ?> neue Lieferanten anlegen?');"><input type="hidden" name="aktion" value="bulk_anlegen"><button class="btn btn-ghost btn-sm" type="submit" data-busy="…">Alle <?= $offenOhne ?> übrigen anlegen</button></form><?php endif; ?>
  </div>
</div>

<div class="bx-panel">
<?php if (!$namen): ?>
  <div class="muted">Alle Lieferanten aus den EK-Preisen sind zugeordnet. 🎉</div>
<?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Lieferant (Text)</th><th class="bx-num">Zeilen</th><th>Vorschlag</th><th>Aktion</th></tr></thead>
    <tbody>
    <?php foreach ($namen as $z): $name = (string)$z['lieferant']; $muell = ek_lief_ist_muell($name); $kand = $muell ? null : ek_lieferant_kandidat($name);
          $kandName = $kand ? (string) scalar("SELECT firma FROM lieferanten WHERE id=?", [$kand]) : ''; ?>
      <tr>
        <td><?= h($name) ?></td>
        <td class="bx-num"><?= (int)$z['n'] ?></td>
        <td><?= $muell ? '<span class="muted">Marktplatz/Platzhalter – kein Lieferant</span>' : ($kand ? '→ ' . h($kandName) : '<span class="muted">neu</span>') ?></td>
        <td>
          <?php if ($muell): ?><span class="muted" style="font-size:12px">ignorieren</span>
          <?php else: ?>
            <div class="bx-row" style="gap:6px;flex-wrap:wrap;align-items:center">
              <?php if ($kand): ?>
                <form method="post" style="margin:0"><input type="hidden" name="aktion" value="zuordnen"><input type="hidden" name="name" value="<?= h($name) ?>"><input type="hidden" name="lid" value="<?= (int)$kand ?>"><button class="btn btn-primary btn-sm" type="submit" data-busy="…">mit „<?= h($kandName) ?>" verknüpfen</button></form>
              <?php else: ?>
                <form method="post" style="margin:0"><input type="hidden" name="aktion" value="zuordnen"><input type="hidden" name="name" value="<?= h($name) ?>"><button class="btn btn-primary btn-sm" type="submit" data-busy="…">neu anlegen</button></form>
              <?php endif; ?>
              <form method="post" style="margin:0;display:flex;gap:4px">
                <input type="hidden" name="aktion" value="zuordnen"><input type="hidden" name="name" value="<?= h($name) ?>">
                <select name="lid" style="font-size:12px;max-width:150px"><option value="">– anderer –</option><?php foreach ($lieferanten as $l): ?><option value="<?= $l['id'] ?>"><?= h($l['firma']) ?></option><?php endforeach; ?></select>
                <button class="btn btn-ghost btn-sm" type="submit">setzen</button>
              </form>
            </div>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</div>
<?php
render_footer();
