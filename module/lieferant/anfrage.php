<?php
// Lieferantenportal – Preisanfragen beantworten. Route: ?p=lieferant_anfrage[&id=<ID>]
require_once BX_ROOT . '/module/lieferant/portal_layout.php';
require_once BX_ROOT . '/core/dokument_ui.php';
if (!ist_lieferant()) { header('Location: ?p=lieferant_login'); exit; }

$lid = aktueller_lieferant_id();
$en  = lp_sprache() !== 'de';
$id  = (int)($_GET['id'] ?? 0);
$a   = $id ? one("SELECT af.*, i.name AS item_name, i.artikelnummer, i.einheit AS item_einheit
                  FROM lieferant_anfrage af LEFT JOIN item i ON i.id=af.item_id
                  WHERE af.id=? AND af.lieferant_id=?", [$id, $lid]) : null;

if ($a && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    $fehler = '';
    if ($aktion === 'angebot') {
        $staffeln = [];
        foreach (($_POST['s_menge'] ?? []) as $i2 => $m)
            $staffeln[] = [(float)str_replace(',', '.', (string)$m), (float)str_replace(',', '.', (string)($_POST['s_preis'][$i2] ?? '0'))];
        $fehler = lieferant_angebot_speichern($id, $lid,
            (float)str_replace(',', '.', (string)($_POST['preis'] ?? '0')),
            trim((string)($_POST['einheit'] ?? '')),
            ($_POST['mindestmenge'] ?? '') !== '' ? (float)str_replace(',', '.', (string)$_POST['mindestmenge']) : null,
            ($_POST['lieferzeit'] ?? '') !== '' ? (int)$_POST['lieferzeit'] : null,
            (string)($_POST['notiz'] ?? ''), $staffeln);
        if ($fehler === '' && mail_bereit()) mail_team_preisanfrage($id);
    } elseif ($aktion === 'dokument' && $a['item_id']) {
        // CoA/Spezifikation direkt am Artikel ablegen – dort sucht das Team sie.
        $_POST['dok_lieferant'] = (string)$lid;
        dokument_upload('item', (int)$a['item_id']);
    }
    header('Location: ?p=lieferant_anfrage&id=' . $id . ($fehler === '' ? '&ok=1' : '&fehler=' . urlencode($fehler))); exit;
}

lp_head('bulkify – ' . lp_t('anfragen'));
lp_shell_start('lieferant_anfrage');
if (isset($_GET['ok']))     echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h(lp_t('gespeichert')) . '</div>';
if (isset($_GET['fehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['fehler']) . '</div>';
$t = fn(string $de, string $enT) => $en ? $enT : $de;

if (!$a):
    $liste = all("SELECT af.*, i.name AS item_name, ag.preis, ag.einheit AS ang_einheit
                  FROM lieferant_anfrage af LEFT JOIN item i ON i.id=af.item_id
                  LEFT JOIN lieferant_angebot ag ON ag.anfrage_id=af.id
                  WHERE af.lieferant_id=? ORDER BY (af.status<>'offen'), af.angelegt DESC", [$lid]);
?>
  <h1 style="margin-bottom:4px"><?= h(lp_t('anfragen')) ?></h1>
  <div class="bx-panel">
    <?php if (!$liste): ?><div class="muted"><?= h(lp_t('keine_anfragen')) ?></div><?php else: ?>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th><?= h(lp_t('nummer')) ?></th><th><?= h(lp_t('artikel')) ?></th><th class="bx-num"><?= h(lp_t('gewuenscht')) ?></th><th><?= h(lp_t('status')) ?></th><th></th></tr></thead>
      <tbody><?php foreach ($liste as $r): ?>
        <tr><td><?= h($r['nummer']) ?></td>
            <td><?= h(($r['item_name'] ?? '') !== '' ? $r['item_name'] : ($r['betreff'] ?? '–')) ?></td>
            <td class="bx-num"><?= $r['menge'] ? rtrim(rtrim(number_format((float)$r['menge'], 3, ',', '.'), '0'), ',') . ' ' . h($r['einheit'] ?? '') : '–' ?></td>
            <td><?= $r['status'] === 'offen' ? h(lp_t('anfrage_offen')) : h(lp_t('anfrage_beant')) ?></td>
            <td class="bx-num"><a class="btn <?= $r['status'] === 'offen' ? 'btn-primary' : 'btn-ghost' ?> btn-sm" href="?p=lieferant_anfrage&id=<?= (int)$r['id'] ?>"><?= h($r['status'] === 'offen' ? lp_t('angebot_abgeben') : $t('ansehen', 'View')) ?></a></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </div>
<?php else:
    $ang = one("SELECT * FROM lieferant_angebot WHERE anfrage_id=?", [$id]);
    $staffeln = $ang ? all("SELECT * FROM lieferant_angebot_staffel WHERE angebot_id=? ORDER BY menge_ab", [(int)$ang['id']]) : [];
    while (count($staffeln) < 3) $staffeln[] = ['menge_ab' => '', 'preis' => ''];
    $einheit = $ang['einheit'] ?? ($a['item_einheit'] ?? $a['einheit'] ?? '');
    $zahl = fn($x, $n) => $x === '' || $x === null ? '' : rtrim(rtrim(number_format((float)$x, $n, '.', ''), '0'), '.');
?>
  <h1 style="margin-bottom:4px"><?= h($a['nummer']) ?></h1>
  <p class="bx-sub"><a href="?p=lieferant_anfrage">&larr; <?= h(lp_t('anfragen')) ?></a></p>

  <div class="bx-panel">
    <h2 style="margin-top:0"><?= h(($a['item_name'] ?? '') !== '' ? $a['item_name'] : ($a['betreff'] ?? '–')) ?></h2>
    <div class="bx-tablewrap"><table class="bx-table"><tbody>
      <?php if ($a['artikelnummer']): ?><tr><td style="width:220px"><?= h($t('Artikelnummer', 'Part no.')) ?></td><td><?= h($a['artikelnummer']) ?></td></tr><?php endif; ?>
      <tr><td style="width:220px"><?= h(lp_t('gewuenscht')) ?></td><td><?= $a['menge'] ? h($zahl($a['menge'], 3)) . ' ' . h($a['einheit'] ?? '') : '–' ?></td></tr>
      <?php if ($a['notiz']): ?><tr><td><?= h(lp_t('notiz')) ?></td><td style="white-space:pre-line"><?= h($a['notiz']) ?></td></tr><?php endif; ?>
      <?php if ((int)$a['coa_gewuenscht'] === 1): ?><tr><td>CoA / Spec</td><td><?= h($t('bitte mitschicken', 'please attach')) ?></td></tr><?php endif; ?>
    </tbody></table></div>
  </div>

  <div class="bx-panel">
    <h2 style="margin-top:0"><?= h(lp_t('angebot_abgeben')) ?></h2>
    <?php if ($ang): ?><div class="muted" style="margin-bottom:10px"><?= h(lp_t('abgegeben_am')) ?> <?= h(date('d.m.Y', strtotime((string)$ang['angelegt']))) ?><?= $ang['status'] === 'angenommen' ? ' · ' . h($t('angenommen', 'accepted')) : '' ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="aktion" value="angebot">
      <div class="bx-grid">
        <div class="bx-field"><label><?= h(lp_t('ihr_preis')) ?></label>
          <input type="text" name="preis" required value="<?= h($ang ? $zahl($ang['preis'], 4) : '') ?>" placeholder="12.50"></div>
        <div class="bx-field"><label><?= h(lp_t('einheit')) ?></label>
          <input type="text" name="einheit" value="<?= h($einheit) ?>" placeholder="kg"></div>
        <div class="bx-field"><label><?= h($t('Mindestmenge (MOQ)', 'Minimum order quantity')) ?></label>
          <input type="text" name="mindestmenge" value="<?= h($ang ? $zahl($ang['mindestmenge'], 3) : '') ?>"></div>
        <div class="bx-field"><label><?= h($t('Lieferzeit (Tage)', 'Lead time (days)')) ?></label>
          <input type="number" name="lieferzeit" value="<?= h((string)($ang['lieferzeit_tage'] ?? '')) ?>"></div>
      </div>
      <div style="margin-top:6px"><strong><?= h($t('Mengenstaffeln', 'Volume tiers')) ?></strong>
        <div class="muted" style="font-size:12px;margin-bottom:8px"><?= h(lp_t('staffel_hinweis')) ?></div>
        <div class="bx-tablewrap"><table class="bx-table">
          <thead><tr><th><?= h(lp_t('ab_menge')) ?></th><th><?= h(lp_t('preis')) ?></th></tr></thead>
          <tbody><?php foreach ($staffeln as $s): ?>
            <tr><td><input type="text" name="s_menge[]" value="<?= h($zahl($s['menge_ab'], 3)) ?>" style="width:100%"></td>
                <td><input type="text" name="s_preis[]" value="<?= h($zahl($s['preis'], 4)) ?>" style="width:100%"></td></tr>
          <?php endforeach; ?></tbody>
        </table></div>
      </div>
      <div class="bx-field"><label><?= h(lp_t('notiz')) ?></label><textarea name="notiz" rows="3"><?= h($ang['notiz'] ?? '') ?></textarea></div>
      <button class="btn btn-primary" type="submit"><?= h(lp_t('angebot_abgeben')) ?></button>
    </form>
  </div>

  <?php if ($a['item_id']): ?>
  <div class="bx-panel">
    <h2 style="margin-top:0"><?= h(lp_t('dateien')) ?></h2>
    <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap">
      <input type="hidden" name="aktion" value="dokument">
      <div class="bx-field" style="margin:0"><label><?= h($t('Art', 'Type')) ?></label>
        <select name="dok_typ"><option value="coa">CoA</option><option value="spec"><?= h($t('Spezifikation', 'Specification')) ?></option><option value="sonstiges"><?= h($t('Sonstiges', 'Other')) ?></option></select></div>
      <div class="bx-field" style="margin:0"><label><?= h($t('Datei', 'File')) ?></label><input type="file" name="dok" required></div>
      <button class="btn btn-ghost" type="submit"><?= h(lp_t('hochladen')) ?></button>
    </form>
    <?php $docs = all("SELECT typ, titel, datei_orig, angelegt FROM dokument WHERE objekt_typ='item' AND objekt_id=? AND lieferant_id=? ORDER BY id DESC", [(int)$a['item_id'], $lid]);
    if ($docs): ?>
    <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table"><tbody>
      <?php foreach ($docs as $d): ?><tr><td><?= h(strtoupper($d['typ'])) ?></td><td><?= h($d['datei_orig']) ?></td><td class="bx-num muted"><?= h(date('d.m.Y', strtotime((string)$d['angelegt']))) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
<?php endif;
lp_shell_ende(); lp_foot();
