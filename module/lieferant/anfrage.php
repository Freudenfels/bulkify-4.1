<?php
// Lieferantenportal – Preisanfragen beantworten. Route: ?p=lieferant_anfrage[&id=<ID>]
require_once BX_ROOT . '/module/lieferant/portal_layout.php';
require_once BX_ROOT . '/core/dokument_ui.php';
if (!ist_lieferant()) { header('Location: ?p=lieferant_login'); exit; }

$lid = aktueller_lieferant_id();
$id  = (int)($_GET['id'] ?? 0);
$a   = $id ? one("SELECT af.*, i.name AS item_name, i.artikelnummer, i.einheit AS item_einheit
                  FROM lieferant_anfrage af LEFT JOIN item i ON i.id=af.item_id
                  WHERE af.id=? AND af.lieferant_id=?", [$id, $lid]) : null;

if ($a && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    $fehler = '';
    if ($aktion === 'angebot') {
        // Die erste Zeile ist der Hauptpreis mit der Menge, für die er gilt – sie zählt als
        // erste Staffel. Danach kommen die Zeilen, die der Lieferant selbst angehängt hat.
        $staffeln = [];
        // Die Schreibweise richtet sich nach der Sprache des Lieferanten: deutsch 250.000,
        // englisch und chinesisch 250,000.
        $spr = lp_sprache();
        $hauptMenge = zahl_lesen((string)($_POST['menge_haupt'] ?? ''), true, $spr);
        $hauptPreis = zahl_lesen((string)($_POST['preis'] ?? ''), false, $spr);
        if ($hauptMenge > 0 && $hauptPreis > 0) $staffeln[] = [$hauptMenge, $hauptPreis];
        foreach (($_POST['s_menge'] ?? []) as $i2 => $m)
            $staffeln[] = [zahl_lesen((string)$m, true, $spr), zahl_lesen((string)($_POST['s_preis'][$i2] ?? ''), false, $spr)];
        $fehler = lieferant_angebot_speichern($id, $lid,
            $hauptPreis,
            trim((string)($_POST['einheit_roh'] ?? $_POST['einheit'] ?? '')),
            ($_POST['mindestmenge'] ?? '') !== '' ? zahl_lesen((string)$_POST['mindestmenge'], true, $spr) : null,
            ($_POST['lieferzeit'] ?? '') !== '' ? (int)$_POST['lieferzeit'] : null,
            (string)($_POST['notiz'] ?? ''), $staffeln, (int)($_POST['preis_basis'] ?? 1));
        if ($fehler === '' && mail_bereit()) mail_team_preisanfrage($id);
    } elseif ($aktion === 'nachricht') {
        $fehler = nachricht_post_verarbeiten($lid, 'lieferant', (string)(current_user()['name'] ?? 'Lieferant'), 'lieferant_anfrage', $id, lp_sprache());
    } elseif ($aktion === 'dokument' && $a['item_id']) {
        // CoA/Spezifikation direkt am Artikel ablegen – dort sucht das Team sie.
        $_POST['dok_lieferant'] = (string)$lid;
        $dokId = dokument_upload('item', (int)$a['item_id']);
        // Gleich auslesen lassen: das Team findet den Vorschlag am Rohstoff und prueft ihn.
        // Der Lieferant schreibt damit KEINE Stammdaten – er liefert nur die Unterlage.
        if ($dokId) { require_once BX_ROOT . '/core/spec_ki.php'; $gelesen = spec_ki_nach_upload($dokId); }
    }
    header('Location: ?p=lieferant_anfrage&id=' . $id . ($fehler === '' ? '&ok=1' . (!empty($gelesen) ? '&gelesen=1' : '') : '&fehler=' . urlencode($fehler))); exit;
}

lp_head('bulkify – ' . lp_t('anfragen'));
lp_shell_start('lieferant_anfrage');
if (isset($_GET['ok']))     echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h(lp_t('gespeichert')) . (isset($_GET['gelesen']) ? ' ' . h(lp_t('datei_gelesen')) : '') . '</div>';
if (isset($_GET['fehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['fehler']) . '</div>';

if (!$a):
    $liste = all("SELECT af.*, i.name AS item_name, i.einheit AS item_einheit, ag.preis, ag.einheit AS ang_einheit
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
            <td><?= h(($r['item_name'] ?? '') !== '' ? $r['item_name'] : ($r['betreff'] ?? '–')) ?>
                <?php $typL = anfrage_art_label((string)($r['art'] ?? ''), (string)($r['form'] ?? ''), lp_sprache()); ?>
                <?php if ($typL !== ''): ?><div class="muted" style="font-size:12px"><?= h($typL) ?></div><?php endif; ?></td>
            <td class="bx-num"><?= $r['menge'] ? h(lp_num($r['menge'])) . ' ' . h(lp_einheit($r['einheit'] ?: ($r['item_einheit'] ?? ''), (float)$r['menge'])) : '–' ?></td>
            <td><?= $r['status'] === 'offen' ? h(lp_t('anfrage_offen')) : h(lp_t('anfrage_beant')) ?></td>
            <td class="bx-num"><a class="btn <?= $r['status'] === 'offen' ? 'btn-primary' : 'btn-ghost' ?> btn-sm" href="?p=lieferant_anfrage&id=<?= (int)$r['id'] ?>"><?= h($r['status'] === 'offen' ? lp_t('angebot_abgeben') : lp_t('ansehen')) ?></a></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </div>
<?php else:
    $ang = one("SELECT * FROM lieferant_angebot WHERE anfrage_id=?", [$id]);
    $staffeln = $ang ? all("SELECT * FROM lieferant_angebot_staffel WHERE angebot_id=? ORDER BY menge_ab", [(int)$ang['id']]) : [];
    // Die erste gespeicherte Staffel gehört zur Kopfzeile (Hauptpreis), der Rest steht darunter.
    // Ohne Angebot steht in der Kopfzeile die angefragte Menge.
    $hauptMenge = $staffeln ? (float)$staffeln[0]['menge_ab'] : (float)($a['menge'] ?? 0);
    if ($staffeln) array_shift($staffeln);
    // Die Einheit steht schon in der Anfrage (dort wird sie automatisch gesetzt) – der Lieferant
    // muss sie nicht raten. Ein bereits abgegebenes Angebot behält seine eigene Einheit.
    $einheit = trim((string)($ang['einheit'] ?? '')) ?: (trim((string)($a['einheit'] ?? '')) ?: trim((string)($a['item_einheit'] ?? '')));
    $zahl = fn($x, $n) => $x === '' || $x === null ? '' : rtrim(rtrim(number_format((float)$x, $n, '.', ''), '0'), '.');
?>
  <h1 style="margin-bottom:4px"><?= h($a['nummer']) ?></h1>
  <p class="bx-sub"><a href="?p=lieferant_anfrage">&larr; <?= h(lp_t('anfragen')) ?></a></p>

  <div class="bx-panel">
    <h2 style="margin-top:0"><?= h(($a['item_name'] ?? '') !== '' ? $a['item_name'] : ($a['betreff'] ?? '–')) ?></h2>
    <div class="bx-tablewrap"><table class="bx-table"><tbody>
      <?php $typ = anfrage_art_label((string)($a['art'] ?? ''), (string)($a['form'] ?? ''), lp_sprache()); ?>
      <?php if ($typ !== ''): ?><tr><td style="width:220px"><?= h(lp_t('produkttyp')) ?></td><td><?= h($typ) ?></td></tr><?php endif; ?>
      <?php if ($a['artikelnummer']): ?><tr><td style="width:220px"><?= h(lp_t('artikelnummer')) ?></td><td><?= h($a['artikelnummer']) ?></td></tr><?php endif; ?>
      <tr><td style="width:220px"><?= h(lp_t('gewuenscht')) ?></td><td><?= $a['menge'] ? h(lp_num($a['menge'])) . ' ' . h(lp_einheit($einheit, (float)$a['menge'])) : '–' ?></td></tr>
      <?php if ($a['stueck_je_packung']): ?><tr><td><?= h(lp_t('je_packung')) ?></td><td><?= h(lp_num($a['stueck_je_packung'], 0)) ?> <?= h(lp_einheit($einheit, (float)$a['stueck_je_packung'])) ?></td></tr><?php endif; ?>
      <?php if ($a['kapselgroesse_id']): $kgN = (string) scalar("SELECT name FROM kapselgroesse WHERE id=?", [(int)$a['kapselgroesse_id']]);
              // Die Größe steht deutsch in den Stammdaten („Größe 0"); international ist „#0" verständlich.
              $kgN = $kgN !== '' ? '#' . trim(str_ireplace(['Größe', 'Gr.', 'Gr'], '', $kgN)) : ''; ?>
        <?php if ($kgN !== ''): ?><tr><td><?= h(lp_t('kapselgroesse')) ?></td><td><?= h($kgN) ?></td></tr><?php endif; ?>
      <?php endif; ?>
      <?php if ($a['notiz']): ?><tr><td><?= h(lp_t('notiz')) ?></td><td style="white-space:pre-line"><?= h($a['notiz']) ?></td></tr><?php endif; ?>
      <?php if ((int)$a['coa_gewuenscht'] === 1): ?><tr><td>CoA / Spec</td><td><?= h(lp_t('coa_mitschicken')) ?></td></tr><?php endif; ?>
    </tbody></table></div>
  </div>

  <div class="bx-panel">
    <h2 style="margin-top:0"><?= h(lp_t('angebot_abgeben')) ?></h2>
    <?php if ($ang): ?><div class="muted" style="margin-bottom:10px"><?= h(lp_t('abgegeben_am')) ?> <?= h(date('d.m.Y', strtotime((string)$ang['angelegt']))) ?><?= $ang['status'] === 'angenommen' ? ' · ' . h(lp_t('angenommen')) : '' ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="aktion" value="angebot">
      <?php // Eine Zeile: links die Preisspalte, rechts daneben Basis, Mindestmenge und Lieferzeit.
            // Die Staffelzeilen stehen IN der Preisspalte, damit sie exakt gleich breit sind. ?>
      <div class="bx-row" style="gap:var(--sp-4);align-items:flex-start;margin-bottom:8px">
        <div class="bx-field" style="margin:0;flex:1 1 340px;max-width:520px">
          <label><?= h(lp_t('ihr_preis')) ?></label>
          <div class="bx-row" style="gap:10px;flex-wrap:nowrap;margin-bottom:8px">
            <input type="text" name="preis" required value="<?= h($ang ? $zahl($ang['preis'], 4) : '') ?>" placeholder="<?= h(lp_t('preis')) ?>" style="flex:1">
            <input type="text" name="menge_haupt" value="<?= h($hauptMenge > 0 ? $zahl($hauptMenge, 3) : '') ?>" placeholder="<?= h(lp_t('ab_menge')) ?>" style="flex:1">
          </div>
          <input type="hidden" name="einheit_roh" value="<?= h($einheit) ?>">
          <?php // Weitere Staffeln: je Zeile ein Preis und die Menge, ab der er gilt. Leere Zeilen
                // ignoriert das Speichern, deshalb braucht es keinen Entfernen-Knopf. ?>
          <div id="staffelRaster">
            <?php foreach ($staffeln as $s): ?>
              <div class="bx-row" style="gap:10px;flex-wrap:nowrap;margin-bottom:8px">
                <input type="text" name="s_preis[]" value="<?= h($zahl($s['preis'], 4)) ?>" placeholder="<?= h(lp_t('preis')) ?>" style="flex:1">
                <input type="text" name="s_menge[]" value="<?= h($zahl($s['menge_ab'], 3)) ?>" placeholder="<?= h(lp_t('ab_menge')) ?>" style="flex:1">
              </div>
            <?php endforeach; ?>
          </div>
          <button type="button" class="btn btn-ghost btn-sm" id="staffelPlus"
                  data-preis="<?= h(lp_t('preis')) ?>" data-menge="<?= h(lp_t('ab_menge')) ?>">+ <?= h(lp_t('staffel')) ?></button>
        </div>
        <div class="bx-field" style="margin:0;max-width:190px"><label><?= h(lp_t('preis_basis')) ?></label>
          <?php $pb = (int)($ang['preis_basis'] ?? 1); ?>
          <select name="preis_basis">
            <option value="1"<?= $pb === 1 ? ' selected' : '' ?>><?= h(lp_t('preis_je')) ?> <?= h(lp_einheit($einheit, 1)) ?></option>
            <option value="1000"<?= $pb === 1000 ? ' selected' : '' ?>><?= h(lp_t('je_1000')) ?> <?= h(lp_einheit($einheit, 1000)) ?></option>
          </select></div>
        <div class="bx-field" style="margin:0;max-width:150px"><label><?= h(lp_t('moq')) ?></label>
          <input type="text" name="mindestmenge" value="<?= h($ang ? $zahl($ang['mindestmenge'], 3) : '') ?>"></div>
        <div class="bx-field" style="margin:0;max-width:110px"><label><?= h(lp_t('lieferzeit')) ?></label>
          <input type="number" name="lieferzeit" value="<?= h((string)($ang['lieferzeit_tage'] ?? '')) ?>"></div>
      </div>
      <script>
      (function(){
        var raster = document.getElementById('staffelRaster'), knopf = document.getElementById('staffelPlus');
        if (!raster || !knopf) return;
        knopf.addEventListener('click', function(){
          // Beschriftungen kommen als data-Attribute vom Knopf – kein PHP in der JS-Zeichenkette.
          var zeile = document.createElement('div');
          zeile.className = 'bx-row';
          zeile.style.cssText = 'gap:10px;flex-wrap:nowrap;margin-bottom:8px';
          [['s_preis[]', knopf.dataset.preis], ['s_menge[]', knopf.dataset.menge]].forEach(function(p){
            var f = document.createElement('input');
            f.type = 'text'; f.name = p[0]; f.placeholder = p[1] || ''; f.style.flex = '1';
            zeile.appendChild(f);
          });
          raster.appendChild(zeile);
          zeile.firstElementChild.focus();
        });
      })();
      </script>
      <div class="bx-field"><label><?= h(lp_t('notiz')) ?></label><textarea name="notiz" rows="3"><?= h($ang['notiz'] ?? '') ?></textarea></div>
      <button class="btn btn-primary" type="submit"><?= h(lp_t('angebot_abgeben')) ?></button>
    </form>
  </div>

  <?= nachricht_panel($lid, 'lieferant', lp_sprache(), 'lieferant_anfrage', $id) ?>

  <?php if ($a['item_id']): ?>
  <div class="bx-panel">
    <h2 style="margin-top:0"><?= h(lp_t('dateien')) ?></h2>
    <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap">
      <input type="hidden" name="aktion" value="dokument">
      <div class="bx-field" style="margin:0"><label><?= h(lp_t('art')) ?></label>
        <select name="dok_typ"><option value="coa">CoA</option><option value="spec"><?= h(lp_t('spezifikation')) ?></option><option value="sonstiges"><?= h(lp_t('sonstiges')) ?></option></select></div>
      <div class="bx-field" style="margin:0"><label><?= h(lp_t('datei')) ?></label><input type="file" name="dok" required></div>
      <button class="btn btn-ghost" type="submit"><?= h(lp_t('hochladen')) ?></button>
    </form>
    <?php $docs = all("SELECT id, typ, titel, datei_orig, angelegt FROM dokument WHERE objekt_typ='item' AND objekt_id=? AND lieferant_id=? ORDER BY id DESC", [(int)$a['item_id'], $lid]);
    if ($docs): ?>
    <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table"><tbody>
      <?php foreach ($docs as $d): ?><tr><td><?= h(strtoupper($d['typ'])) ?></td><td><a href="?p=lieferant_dokument&id=<?= (int)$d['id'] ?>" target="_blank"><?= h($d['datei_orig']) ?></a></td><td class="bx-num muted"><?= h(date('d.m.Y', strtotime((string)$d['angelegt']))) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
<?php endif;
lp_shell_ende(); lp_foot();
