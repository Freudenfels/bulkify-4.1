<?php
// Lieferantenportal – Bestellungen. Route: ?p=lieferant_bestellung[&id=<ID>]
// Ohne id die Liste, mit id die einzelne Bestellung samt Ablauf-Panel.
require_once BX_ROOT . '/module/lieferant/portal_layout.php';
require_once BX_ROOT . '/core/bestellung_ui.php';
if (!ist_lieferant()) { header('Location: ?p=lieferant_login'); exit; }

$lid = aktueller_lieferant_id();
$id  = (int)($_GET['id'] ?? 0);
// Nur eigene Bestellungen – die Pruefung haengt an jeder Abfrage, nicht an der Oberflaeche.
$b   = $id ? one("SELECT * FROM bestellung WHERE id=? AND lieferant_id=?", [$id, $lid]) : null;

if ($b && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    $wer    = (string)(current_user()['name'] ?? 'Lieferant');
    $fehler = '';
    if ($aktion === 'lief_bestaetigen') {
        $fehler = bestellung_bestaetigen($id, (string)($_POST['eta_geplant'] ?? ''), trim((string)($_POST['wer'] ?? '')) ?: $wer, lp_sprache());
        if ($fehler === '' && (int)$b['bestaetigt'] !== 1 && mail_bereit()) mail_team_bestellung($id, 'bestätigt');
    } elseif ($aktion === 'lief_station') {
        $fehler = bestellung_station_setzen($id, (string)($_POST['station'] ?? ''), $wer, lp_sprache());
        if ($fehler === '' && mail_bereit()) mail_team_bestellung($id, bestellung_stationen()[(string)$_POST['station']] ?? (string)$_POST['station']);
    } elseif ($aktion === 'nachricht') {
        $fehler = nachricht_post_verarbeiten($lid, 'lieferant', $wer, 'bestellung', $id, lp_sprache());
    } elseif ($aktion === 'lief_versand') {
        q("UPDATE bestellung SET produktion_geplant=?, versandanbieter=?, versandart=?, tracking=? WHERE id=? AND lieferant_id=?", [
            trim((string)($_POST['produktion_geplant'] ?? '')) ?: null,
            mb_substr(trim((string)($_POST['versandanbieter'] ?? '')), 0, 60) ?: null,
            array_key_exists((string)($_POST['versandart'] ?? ''), versandart_liste()) ? $_POST['versandart'] : null,
            mb_substr(trim((string)($_POST['tracking'] ?? '')), 0, 120) ?: null, $id, $lid]);
    } elseif ($aktion === 'pakete_add') {
        // Mehrere Tracking-Nummern (eine pro Zeile) + optional Anzahl Pakete (falls noch keine Nummern).
        $r = lieferung_pakete_hinzufuegen($id, (string)($_POST['trackings'] ?? ''), (string)($_POST['spediteur'] ?? ''));
        $anz = trim((string)($_POST['pakete_angekuendigt'] ?? ''));
        if ($anz !== '' && ctype_digit($anz)) q("UPDATE bestellung SET pakete_angekuendigt=? WHERE id=? AND lieferant_id=?", [(int)$anz, $id, $lid]);
        if (($r['neu'] ?? 0) > 0 && mail_bereit()) mail_team_bestellung($id, 'Versand-Pakete gemeldet');
    } elseif ($aktion === 'paket_del') {
        lieferung_paket_loeschen((int)($_POST['paket_id'] ?? 0), $id);
    } elseif ($aktion === 'bestell_dok_upload') {
        require_once BX_ROOT . '/core/lieferant_dateien.php';
        $fehler = bestell_dok_upload($id, $lid, (string)($_POST['bd_typ'] ?? 'sonstiges'), lp_sprache());
    } elseif ($aktion === 'bestell_dok_del') {
        $dd = (int)($_POST['dok_id'] ?? 0);
        if ($dd) { $row = one("SELECT datei FROM dokument WHERE id=? AND objekt_typ='bestellung' AND objekt_id=? AND lieferant_id=?", [$dd, $id, $lid]);
                   if ($row) { @unlink(BX_UPLOADS . '/' . basename((string)$row['datei'])); q("DELETE FROM dokument WHERE id=?", [$dd]); } }
    }
    header('Location: ?p=lieferant_bestellung&id=' . $id . ($fehler === '' ? '&ok=1' : '&fehler=' . urlencode($fehler))); exit;
}

lp_head('bulkify – ' . lp_t('bestellungen'));
lp_shell_start('lieferant_bestellung');

if (isset($_GET['ok']))     echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h(lp_t('gespeichert')) . '</div>';
if (isset($_GET['fehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['fehler']) . '</div>';

if (!$b):
    $liste = all("SELECT b.*,
                    (SELECT COALESCE(NULLIF(i.name,''), bp.bezeichnung)
                       FROM bestellung_position bp LEFT JOIN item i ON i.id=bp.item_id
                       WHERE bp.bestellung_id=b.id ORDER BY bp.sort, bp.id LIMIT 1) AS produkt,
                    (SELECT COUNT(*) FROM bestellung_position bp WHERE bp.bestellung_id=b.id) AS n_pos
                  FROM bestellung b WHERE b.lieferant_id=? ORDER BY (b.status='geliefert'), b.angelegt DESC", [$lid]);
?>
  <h1 style="margin-bottom:4px"><?= h(lp_t('bestellungen')) ?></h1>
  <div class="bx-panel">
    <?php if (!$liste): ?><div class="muted"><?= h(lp_t('keine_best')) ?></div><?php else: ?>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th><?= h(lp_t('nummer')) ?></th><th><?= h(lp_t('artikel')) ?></th><th><?= h(lp_t('datum')) ?></th><th><?= h(lp_t('termin')) ?></th><th><?= h(lp_t('status')) ?></th><th></th></tr></thead>
      <tbody><?php foreach ($liste as $r):
          $st = (string)$r['station'];
          $lbl = $st === '' ? lp_t('best_neu_lbl') : bestellung_stationen_fuer(lp_sprache())[$st]; ?>
        <tr><td><?= h($r['nummer']) ?></td>
            <td><?= ($r['produkt'] ?? '') !== '' ? h($r['produkt']) : '<span class="muted">–</span>' ?><?= (int)$r['n_pos'] > 1 ? ' <span class="muted" style="font-size:12px">+' . ((int)$r['n_pos'] - 1) . '</span>' : '' ?></td>
            <td><?= h(date('d.m.Y', strtotime((string)$r['angelegt']))) ?></td>
            <td><?= $r['eta_geplant'] ? h(date('d.m.Y', strtotime((string)$r['eta_geplant']))) : '<span class="muted">–</span>' ?></td>
            <td><?= h($lbl) ?></td>
            <td class="bx-num"><a class="btn btn-ghost btn-sm" href="?p=lieferant_bestellung&id=<?= (int)$r['id'] ?>"><?= h(lp_t('zur_bestellung')) ?></a></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </div>
<?php else:
    $pos = all("SELECT bp.*, i.name AS item_name, i.artikelnummer, i.rezeptur_id
                FROM bestellung_position bp LEFT JOIN item i ON i.id=bp.item_id
                WHERE bp.bestellung_id=? ORDER BY bp.sort, bp.id", [$id]);
    // Preise in der Währung DES LIEFERANTEN (USD/EUR/CNY) – Stückpreis mit 4 Nachkommastellen (Sub-Cent).
    $waehrung = (string) scalar("SELECT waehrung FROM lieferanten WHERE id=?", [$lid]) ?: 'EUR';
    $sym = ['EUR'=>'€', 'USD'=>'$', 'CNY'=>'¥'][$waehrung] ?? $waehrung;
    $money = fn($x, $dec = 2) => number_format((float)$x, $dec, ',', '.') . ' ' . $sym;
    $summe = 0.0; foreach ($pos as $p) $summe += (float)$p['menge'] * (float)$p['ek_preis'];
?>
  <h1 style="margin-bottom:4px"><?= h($b['nummer']) ?></h1>
  <p class="bx-sub"><a href="?p=lieferant_bestellung">&larr; <?= h(lp_t('bestellungen')) ?></a></p>

  <?= bestellung_ablauf_panel($b, 'lieferant', lp_sprache()) ?>
  <?= nachricht_panel($lid, 'lieferant', lp_sprache(), 'bestellung', $id) ?>

  <div class="bx-panel">
    <div class="bx-row" style="justify-content:space-between;align-items:center">
      <h2 style="margin:0"><?= h(lp_t('positionen')) ?></h2>
      <a class="btn btn-ghost btn-sm" target="_blank" href="?p=lieferant_bestellung_pdf&id=<?= (int)$b['id'] ?>">&#8681; <?= h(lp_t('pdf')) ?></a>
    </div>
    <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table">
      <thead><tr><th><?= h(lp_t('artikel')) ?></th><th class="bx-num"><?= h(lp_t('menge')) ?></th><th><?= h(lp_t('einheit')) ?></th><th class="bx-num"><?= h(lp_t('preis')) ?></th><th class="bx-num"><?= h(lp_t('summe')) ?></th></tr></thead>
      <tbody><?php foreach ($pos as $p): ?>
        <tr><td><?= h(($p['item_name'] ?? '') !== '' ? $p['item_name'] : ($p['bezeichnung'] ?? '–')) ?>
              <?= $p['artikelnummer'] ? '<div class="muted" style="font-size:12px">' . h($p['artikelnummer']) . '</div>' : '' ?>
              <?php if (!empty($p['rezeptur_id'])): ?><div style="margin-top:2px"><a class="btn btn-ghost btn-sm" href="?p=lieferant_rezeptur&id=<?= (int)$p['rezeptur_id'] ?>">Rezeptur ansehen</a></div><?php endif; ?></td>
            <td class="bx-num"><?= rtrim(rtrim(number_format((float)$p['menge'], 3, ',', '.'), '0'), ',') ?></td>
            <td><?= h($p['einheit'] ?? '') ?></td>
            <td class="bx-num"><?= $money($p['ek_preis'], 4) ?></td>
            <td class="bx-num"><?= $money((float)$p['menge'] * (float)$p['ek_preis'], 2) ?></td></tr>
      <?php endforeach; ?>
        <tr style="font-weight:600"><td colspan="4"><?= h(lp_t('summe')) ?></td><td class="bx-num"><?= $money($summe, 2) ?></td></tr>
      </tbody>
    </table></div>
    <?php if (!empty($b['notiz'])): ?><div class="muted" style="margin-top:10px;white-space:pre-line"><?= h($b['notiz']) ?></div><?php endif; ?>
  </div>

  <?php // Versand / Pakete: wie viele Kartons schickt der Lieferant und welche Tracking-Nummern.
    $pakete = lieferung_pakete($id);
    $pGesamt = count($pakete);
    $pAngek  = count(array_filter($pakete, fn($p) => (int)$p['angekommen'] === 1));
    $angekuendigt = (int)($b['pakete_angekuendigt'] ?? 0);
  ?>
  <div class="bx-panel">
    <h2 style="margin:0 0 4px">Versand / Pakete</h2>
    <p class="muted" style="margin:0 0 12px">Geben Sie an, wie viele Pakete/Kartons Sie schicken und die zugehörigen Tracking-Nummern (eine pro Zeile oder nacheinander scannen). Das Lager gleicht die Pakete beim Eingang damit ab.</p>
    <?php if ($pGesamt > 0): ?>
      <p style="margin:0 0 8px"><strong><?= $pAngek ?> / <?= $pGesamt ?></strong> angekommen<?= $angekuendigt > $pGesamt ? ' · angekündigt: ' . $angekuendigt : '' ?></p>
      <div class="bx-tablewrap" style="margin-bottom:12px"><table class="bx-table">
        <thead><tr><th>Tracking-Nr.</th><th>Spediteur</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($pakete as $p): ?>
            <tr>
              <td><?= h($p['tracking']) ?></td>
              <td><?= $p['spediteur'] ? h($p['spediteur']) : '<span class="muted">–</span>' ?></td>
              <td><?= (int)$p['angekommen'] === 1
                    ? '<span class="bx-ok">✓ angekommen</span>' . (!empty($p['angekommen_am']) ? ' <span class="muted" style="font-size:12px">· ' . h(fmt_zeit($p['angekommen_am'], 'd.m.Y')) . '</span>' : '')
                    : '<span class="muted">unterwegs</span>' ?></td>
              <td style="text-align:right"><?php if ((int)$p['angekommen'] !== 1): ?><form method="post" style="margin:0" onsubmit="return confirm('Diese Tracking-Nummer entfernen?');"><input type="hidden" name="aktion" value="paket_del"><input type="hidden" name="paket_id" value="<?= (int)$p['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">entfernen</button></form><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php elseif ($angekuendigt > 0): ?>
      <p style="margin:0 0 8px"><strong><?= $angekuendigt ?> Paket(e)</strong> angekündigt – Tracking-Nummern können Sie unten ergänzen.</p>
    <?php endif; ?>
    <form method="post" class="bx-form" style="margin:0">
      <input type="hidden" name="aktion" value="pakete_add">
      <div class="bx-grid">
        <div class="bx-field" style="grid-column:1/-1"><label>Tracking-/Sendungsnummern <span class="muted" style="font-weight:400">(eine pro Zeile)</span></label>
          <textarea name="trackings" rows="4" placeholder="z. B.&#10;1Z999AA10123456784&#10;1Z999AA10123456785"></textarea></div>
        <div class="bx-field"><label>Spediteur <span class="muted" style="font-weight:400">(optional)</span></label><input type="text" name="spediteur" placeholder="z. B. UPS, DHL, DPD"></div>
        <div class="bx-field"><label>Anzahl Pakete <span class="muted" style="font-weight:400">(optional, falls Nummern noch fehlen)</span></label><input type="number" name="pakete_angekuendigt" min="0" value="<?= $angekuendigt ?: '' ?>" style="max-width:140px"></div>
      </div>
      <div class="bx-row" style="margin-top:12px"><button class="btn btn-primary btn-sm" type="submit">Pakete speichern</button></div>
    </form>
  </div>

  <?php // Dokumente & Rechnung: der Lieferant lädt CoA, Rechnung o. Ä. direkt zu DIESER Bestellung hoch.
  require_once BX_ROOT . '/core/lieferant_dateien.php';
  $bdoks = bestell_dokumente($id);
  $BDTYP = ['coa'=>'CoA / Analysenzertifikat', 'rechnung'=>'Rechnung', 'spec'=>'Spezifikation', 'analyse'=>'Laboranalyse', 'sonstiges'=>'Sonstiges']; ?>
  <div class="bx-panel">
    <h2 style="margin:0 0 4px">Dokumente &amp; Rechnung</h2>
    <p class="muted" style="margin:0 0 12px">Laden Sie hier die Unterlagen zu dieser Bestellung hoch – z. B. das <strong>Analysenzertifikat (CoA)</strong> und Ihre <strong>Rechnung</strong>, damit wir zahlen können. Die Dokumente sind direkt mit dieser Bestellung verknüpft.</p>
    <?php if ($bdoks): ?>
    <div class="bx-tablewrap" style="margin-bottom:12px"><table class="bx-table">
      <thead><tr><th>Typ</th><th>Datei</th><th>Datum</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($bdoks as $d): ?>
        <tr><td><?= h($BDTYP[$d['typ']] ?? $d['typ']) ?></td>
            <td><a href="?p=lieferant_dokument&id=<?= (int)$d['id'] ?>" target="_blank" rel="noopener"><?= h($d['titel'] ?: ($d['datei_orig'] ?: 'Dokument')) ?></a></td>
            <td class="muted"><?= h(date('d.m.Y', strtotime((string)$d['angelegt']))) ?></td>
            <td style="text-align:right"><form method="post" style="margin:0" onsubmit="return confirm('Dokument entfernen?');"><input type="hidden" name="aktion" value="bestell_dok_del"><input type="hidden" name="dok_id" value="<?= (int)$d['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">entfernen</button></form></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="aktion" value="bestell_dok_upload">
      <div class="bx-grid">
        <div class="bx-field"><label>Typ</label><select name="bd_typ"><?php foreach ($BDTYP as $k=>$l): ?><option value="<?= $k ?>"<?= $k==='coa'?' selected':'' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
        <div class="bx-field"><label>Titel (optional)</label><input type="text" name="dok_titel" maxlength="190"></div>
        <div class="bx-field"><label>Datei <span class="muted" style="font-weight:400">(max. <?= lieferant_datei_max_mb() ?> MB)</span></label><input type="file" name="dok" required accept=".<?= implode(',.', lieferant_datei_endungen()) ?>"></div>
      </div>
      <div class="bx-row" style="margin-top:12px"><button class="btn btn-primary btn-sm" type="submit">Dokument hochladen</button></div>
    </form>
  </div>
<?php endif;
lp_shell_ende(); lp_foot();
