<?php
// Produktions-Kalender – planen, wann welcher Produktionsauftrag produziert wird (Baustein 2).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? '';
    $paId = (int)($_POST['pa_id'] ?? 0);
    if ($aktion === 'plan' && $paId) {
        $d = trim($_POST['datum'] ?? '');
        q("UPDATE produktionsauftrag SET geplant_am=? WHERE id=?", [$d ?: null, $paId]);
    } elseif ($aktion === 'unplan' && $paId) {
        q("UPDATE produktionsauftrag SET geplant_am=NULL WHERE id=?", [$paId]);
    }
    header('Location: ?p=kalender' . (($_GET['monat'] ?? '') !== '' ? '&monat=' . urlencode($_GET['monat']) : '')); exit;
}

$monat = $_GET['monat'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $monat)) $monat = date('Y-m');
$first = $monat . '-01';
$ts = strtotime($first);
$tageImMonat   = (int) date('t', $ts);
$startWochentag = (int) date('N', $ts);   // 1=Mo .. 7=So
$prev = date('Y-m', strtotime($first . ' -1 month'));
$next = date('Y-m', strtotime($first . ' +1 month'));
$heute = date('Y-m-d');
$MON =['01'=>'Januar','02'=>'Februar','03'=>'März','04'=>'April','05'=>'Mai','06'=>'Juni','07'=>'Juli','08'=>'August','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Dezember'];
$titel = $MON[substr($monat,5,2)] . ' ' . substr($monat,0,4);

$geplant = all("SELECT pa.*, p.name AS produkt_name, k.firma AS kunde
                FROM produktionsauftrag pa LEFT JOIN produkt p ON p.id=pa.produkt_id LEFT JOIN kunden k ON k.id=pa.kunde_id
                WHERE pa.geplant_am BETWEEN ? AND ?
                ORDER BY pa.geplant_am, pa.prio", [$first, date('Y-m-t', $ts)]);
$byDay = [];
foreach ($geplant as $g) { $byDay[(int)date('j', strtotime($g['geplant_am']))][] = $g; }

$ungeplant = all("SELECT pa.*, p.name AS produkt_name, k.firma AS kunde
                  FROM produktionsauftrag pa LEFT JOIN produkt p ON p.id=pa.produkt_id LEFT JOIN kunden k ON k.id=pa.kunde_id
                  WHERE pa.status IN ('offen','laufend') AND pa.geplant_am IS NULL
                  ORDER BY pa.prio, pa.angelegt");

render_header('kalender', 'Produktions-Kalender');
bx_head('Produktions-Kalender', 'Plane, wann welcher Auftrag produziert wird.');
?>
<div class="bx-row" style="justify-content:space-between;align-items:center;margin-bottom:12px">
  <a class="btn btn-ghost btn-sm" href="?p=kalender&monat=<?= $prev ?>">&#8592; <?= h($MON[substr($prev,5,2)]) ?></a>
  <h2 style="margin:0"><?= h($titel) ?></h2>
  <a class="btn btn-ghost btn-sm" href="?p=kalender&monat=<?= $next ?>"><?= h($MON[substr($next,5,2)]) ?> &#8594;</a>
</div>

<div class="bx-panel">
  <div class="bx-cal-head">
    <?php foreach (['Mo','Di','Mi','Do','Fr','Sa','So'] as $wd): ?><div><?= $wd ?></div><?php endforeach; ?>
  </div>
  <div class="bx-cal">
    <?php for ($i = 1; $i < $startWochentag; $i++): ?><div class="bx-cal-cell bx-cal-empty"></div><?php endfor; ?>
    <?php for ($tag = 1; $tag <= $tageImMonat; $tag++): $datum = sprintf('%s-%02d', $monat, $tag); $istHeute = $datum === $heute; ?>
      <div class="bx-cal-cell<?= $istHeute ? ' bx-cal-today' : '' ?>">
        <div class="bx-cal-day"><?= $tag ?></div>
        <?php foreach ($byDay[$tag] ?? [] as $g): ?>
          <a class="bx-cal-item" href="?p=produktionsauftrag&id=<?= (int)$g['id'] ?>" title="<?= h(($g['produkt_name'] ?: '') . ($g['kunde'] ? ' · ' . $g['kunde'] : '')) ?>">
            <span class="bx-cal-prio bx-cal-prio-<?= (int)($g['prio'] ?? 2) ?>"></span><?= h($g['nummer'] ?: ('#'.$g['id'])) ?> · <?= h($g['produkt_name'] ?: '–') ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endfor; ?>
  </div>
</div>

<?php if ($ungeplant): ?>
<div class="bx-panel">
  <h2>Noch nicht eingeplant (<?= count($ungeplant) ?>)</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Prio</th><th>Bereit</th><th>Auftrag</th><th>Produkt</th><th>Kunde</th><th style="width:260px">Einplanen auf</th></tr></thead>
    <tbody>
      <?php foreach ($ungeplant as $g): $ber = produktion_bereitschaft((int)$g['id']); ?>
        <tr>
          <td><?= prio_badge((int)($g['prio'] ?? 2)) ?></td>
          <td><?= bereitschaft_badge($ber['status']) ?></td>
          <td><a href="?p=produktionsauftrag&id=<?= (int)$g['id'] ?>"><?= h($g['nummer'] ?: ('#'.$g['id'])) ?></a></td>
          <td><?= h($g['produkt_name'] ?: '–') ?></td>
          <td><?= $g['kunde'] ? h($g['kunde']) : '<span class="muted">–</span>' ?></td>
          <td>
            <form method="post" class="bx-row" style="gap:6px;margin:0">
              <input type="hidden" name="aktion" value="plan"><input type="hidden" name="pa_id" value="<?= (int)$g['id'] ?>">
              <input type="date" name="datum" required style="max-width:150px">
              <button class="btn btn-primary btn-sm" type="submit">Einplanen</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
<?php render_footer(); ?>
