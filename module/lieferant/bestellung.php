<?php
// Lieferantenportal – Bestellungen. Route: ?p=lieferant_bestellung[&id=<ID>]
// Ohne id die Liste, mit id die einzelne Bestellung samt Ablauf-Panel.
require_once BX_ROOT . '/module/lieferant/portal_layout.php';
require_once BX_ROOT . '/core/bestellung_ui.php';
if (!ist_lieferant()) { header('Location: ?p=lieferant_login'); exit; }

$lid = aktueller_lieferant_id();
$en  = lieferant_sprache() !== 'de';
$id  = (int)($_GET['id'] ?? 0);
// Nur eigene Bestellungen – die Pruefung haengt an jeder Abfrage, nicht an der Oberflaeche.
$b   = $id ? one("SELECT * FROM bestellung WHERE id=? AND lieferant_id=?", [$id, $lid]) : null;

if ($b && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    $wer    = (string)(current_user()['name'] ?? 'Lieferant');
    $fehler = '';
    if ($aktion === 'lief_bestaetigen') {
        $fehler = bestellung_bestaetigen($id, (string)($_POST['eta_geplant'] ?? ''), trim((string)($_POST['wer'] ?? '')) ?: $wer, $en);
    } elseif ($aktion === 'lief_station') {
        $fehler = bestellung_station_setzen($id, (string)($_POST['station'] ?? ''), $wer, $en);
    } elseif ($aktion === 'lief_versand') {
        q("UPDATE bestellung SET produktion_geplant=?, versandanbieter=?, versandart=?, tracking=? WHERE id=? AND lieferant_id=?", [
            trim((string)($_POST['produktion_geplant'] ?? '')) ?: null,
            mb_substr(trim((string)($_POST['versandanbieter'] ?? '')), 0, 60) ?: null,
            array_key_exists((string)($_POST['versandart'] ?? ''), versandarten()) ? $_POST['versandart'] : null,
            mb_substr(trim((string)($_POST['tracking'] ?? '')), 0, 120) ?: null, $id, $lid]);
    }
    header('Location: ?p=lieferant_bestellung&id=' . $id . ($fehler === '' ? '&ok=1' : '&fehler=' . urlencode($fehler))); exit;
}

lp_head('bulkify – ' . lp_t('bestellungen'));
lp_shell_start('lieferant_bestellung');

if (isset($_GET['ok']))     echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h(lp_t('gespeichert')) . '</div>';
if (isset($_GET['fehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['fehler']) . '</div>';

if (!$b):
    $liste = all("SELECT * FROM bestellung WHERE lieferant_id=? ORDER BY (status='geliefert'), angelegt DESC", [$lid]);
?>
  <h1 style="margin-bottom:4px"><?= h(lp_t('bestellungen')) ?></h1>
  <div class="bx-panel">
    <?php if (!$liste): ?><div class="muted"><?= h(lp_t('keine_best')) ?></div><?php else: ?>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th><?= h(lp_t('nummer')) ?></th><th><?= h(lp_t('datum')) ?></th><th><?= h(lp_t('termin')) ?></th><th><?= h(lp_t('status')) ?></th><th></th></tr></thead>
      <tbody><?php foreach ($liste as $r):
          $st = (string)$r['station'];
          $lbl = $st === '' ? '–' : ($en ? bestellung_stationen_en()[$st] : bestellung_stationen()[$st]); ?>
        <tr><td><?= h($r['nummer']) ?></td>
            <td><?= h(date('d.m.Y', strtotime((string)$r['angelegt']))) ?></td>
            <td><?= $r['eta_geplant'] ? h(date('d.m.Y', strtotime((string)$r['eta_geplant']))) : '<span class="muted">–</span>' ?></td>
            <td><?= h($lbl) ?></td>
            <td class="bx-num"><a class="btn btn-ghost btn-sm" href="?p=lieferant_bestellung&id=<?= (int)$r['id'] ?>"><?= h(lp_t('zur_bestellung')) ?></a></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </div>
<?php else:
    $pos = all("SELECT bp.*, i.name AS item_name, i.artikelnummer
                FROM bestellung_position bp LEFT JOIN item i ON i.id=bp.item_id
                WHERE bp.bestellung_id=? ORDER BY bp.sort, bp.id", [$id]);
    $eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
    $summe = 0.0; foreach ($pos as $p) $summe += (float)$p['menge'] * (float)$p['ek_preis'];
?>
  <h1 style="margin-bottom:4px"><?= h($b['nummer']) ?></h1>
  <p class="bx-sub"><a href="?p=lieferant_bestellung">&larr; <?= h(lp_t('bestellungen')) ?></a></p>

  <?= bestellung_ablauf_panel($b, 'lieferant', $en) ?>

  <div class="bx-panel">
    <div class="bx-row" style="justify-content:space-between;align-items:center">
      <h2 style="margin:0"><?= h(lp_t('positionen')) ?></h2>
      <a class="btn btn-ghost btn-sm" target="_blank" href="?p=lieferant_bestellung_pdf&id=<?= (int)$b['id'] ?>">&#8681; <?= h(lp_t('pdf')) ?></a>
    </div>
    <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table">
      <thead><tr><th><?= h(lp_t('artikel')) ?></th><th class="bx-num"><?= h(lp_t('menge')) ?></th><th><?= h(lp_t('einheit')) ?></th><th class="bx-num"><?= h(lp_t('preis')) ?></th><th class="bx-num"><?= h(lp_t('summe')) ?></th></tr></thead>
      <tbody><?php foreach ($pos as $p): ?>
        <tr><td><?= h(($p['item_name'] ?? '') !== '' ? $p['item_name'] : ($p['bezeichnung'] ?? '–')) ?>
              <?= $p['artikelnummer'] ? '<div class="muted" style="font-size:12px">' . h($p['artikelnummer']) . '</div>' : '' ?></td>
            <td class="bx-num"><?= rtrim(rtrim(number_format((float)$p['menge'], 3, ',', '.'), '0'), ',') ?></td>
            <td><?= h($p['einheit'] ?? '') ?></td>
            <td class="bx-num"><?= $eur($p['ek_preis']) ?></td>
            <td class="bx-num"><?= $eur((float)$p['menge'] * (float)$p['ek_preis']) ?></td></tr>
      <?php endforeach; ?>
        <tr style="font-weight:600"><td colspan="4"><?= h(lp_t('summe')) ?></td><td class="bx-num"><?= $eur($summe) ?></td></tr>
      </tbody>
    </table></div>
    <?php if (!empty($b['notiz'])): ?><div class="muted" style="margin-top:10px;white-space:pre-line"><?= h($b['notiz']) ?></div><?php endif; ?>
  </div>
<?php endif;
lp_shell_ende(); lp_foot();
