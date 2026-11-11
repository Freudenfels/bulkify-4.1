<?php
// Chargenverfolgung – Übersicht aller Chargen + Rückverfolgung (Rohstoff ↔ Produkt) über produktion_verbrauch.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/charge_korrektur.php';   // Menge korrigieren / Verknüpfung lösen (Block B, 11.11.2026)

$chId = (int)($_GET['id'] ?? 0);

$chBadge = fn($s) => match ($s) {
    'frei'        => bx_badge('frei','ok'),
    'quarantaene' => bx_badge('Quarantäne','warn'),
    'leer'        => bx_badge('leer'),
    default       => bx_badge($s),
};
$katLbl = kategorie_labels();   // einheitliche Labels (Bulkware / Fertigprodukt), Block K
$num = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');

// ---------- Detail ----------
if ($chId) {
    $c = one("SELECT c.*, i.name AS item_name, i.kategorie AS kategorie, l.firma AS lieferant_firma,
                     a.nummer AS auftrag_nr, ak.firma AS auftrag_kunde
              FROM charge c LEFT JOIN item i ON i.id=c.item_id LEFT JOIN lieferanten l ON l.id=c.lieferant_id
              LEFT JOIN auftrag a ON a.id=c.auftrag_id LEFT JOIN kunden ak ON ak.id=a.kunde_id
              WHERE c.id=?", [$chId]);
    if (!$c) { render_header('chargen','Charge'); bx_head('Charge nicht gefunden','', bx_btn('Zurück','?p=chargen','ghost')); render_footer(); exit; }

    // Block B (11.11.2026): Menge korrigieren (nur Admin) und Verknüpfung zur Bestellung lösen. Eine Funktion für alle Wege.
    $wer = (string)(current_user()['name'] ?? 'Team');
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'menge_korr') {
        if (!has_role('admin')) { header('Location: ?p=chargen&id=' . $chId . '&kfehler=' . rawurlencode('Nur Admin darf Mengen korrigieren.')); exit; }
        $beleg = charge_korr_beleg_speichern($_FILES['korr_beleg'] ?? [], $chId);
        $r = charge_menge_korrigieren($chId, (string)($_POST['korr_was'] ?? ''), zahl_lesen((string)($_POST['korr_menge'] ?? ''), true),
                                      (string)($_POST['korr_grund'] ?? ''), (string)($_POST['korr_notiz'] ?? ''), $wer, $beleg);
        header('Location: ?p=chargen&id=' . $chId . ($r['ok'] ? '&kok=' . rawurlencode(trim($r['meldung'] . ' ' . $r['hinweis'])) : '&kfehler=' . rawurlencode($r['meldung']))); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'verkn_loesen') {
        if (!has_role('admin')) { header('Location: ?p=chargen&id=' . $chId . '&kfehler=' . rawurlencode('Nur Admin.')); exit; }
        $r = webe_charge_loesen($chId, $wer);
        header('Location: ?p=chargen&id=' . $chId . ($r['ok'] ? '&kok=' : '&kfehler=') . rawurlencode($r['msg'])); exit;
    }
    $istAdminC = has_role('admin');
    $bestBezug = !empty($c['bestellung_position_id']) ? one("SELECT bp.id, bp.menge, bp.einheit, COALESCE(bp.geliefert_menge,0) AS geliefert, b.id AS bid, b.nummer, b.status
                       FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id WHERE bp.id=?", [(int)$c['bestellung_position_id']]) : null;
    $korrListe = charge_korr_liste($chId);

    // Wohin ging diese Charge? (nur Rohstoff/Verpackung sinnvoll) – aus produktion_verbrauch
    $verwendet = all("SELECT v.menge, v.einheit, pa.id AS pa_id, pa.nummer AS pa_nr, pa.status AS pa_status,
                             p.name AS produkt_name, pa.kunde_id, k.firma AS kunde_firma
                      FROM produktion_verbrauch v
                      JOIN produktionsauftrag pa ON pa.id=v.pa_id
                      LEFT JOIN produkt p ON p.id=pa.produkt_id
                      LEFT JOIN kunden k ON k.id=pa.kunde_id
                      WHERE v.charge_id=? ORDER BY v.id", [$chId]);

    // Woraus besteht diese (Fertigwaren-)Charge? Über pa_id; Fallback für alte Chargen: charge_nr = PR-Nummer.
    $bestandteile = [];
    $pa = null;
    if (!empty($c['pa_id'])) $pa = one("SELECT id, nummer FROM produktionsauftrag WHERE id=?", [(int)$c['pa_id']]);
    if (!$pa) $pa = one("SELECT id, nummer FROM produktionsauftrag WHERE nummer=?", [$c['charge_nr']]);
    if ($pa) {
        $bestandteile = all("SELECT v.menge, v.einheit, i.name AS item_name, c2.id AS charge_id, c2.charge_nr, c2.mhd
                             FROM produktion_verbrauch v
                             LEFT JOIN item i ON i.id=v.item_id
                             LEFT JOIN charge c2 ON c2.id=v.charge_id
                             WHERE v.pa_id=? ORDER BY v.id", [(int)$pa['id']]);
    }

    render_header('chargen', 'Charge ' . ($c['charge_nr'] ?: $chId));
    bx_head('Charge ' . h($c['charge_nr'] ?: ('#' . $chId)), h(($katLbl[$c['kategorie']] ?? $c['kategorie']) . ' · ' . $c['item_name']),
            bx_btn('Zurück zur Übersicht', '?p=chargen', 'ghost'));

    echo '<div class="bx-cards">';
    echo '<div class="bx-card"><div class="k">Status</div><div class="v">' . $chBadge($c['status']) . '</div></div>';
    $stift = fn(string $was) => $istAdminC ? ' <button type="button" class="btn btn-ghost btn-sm" style="padding:0 6px" title="Menge korrigieren (nur Admin)" onclick="var d=document.getElementById(\'dlgKorr\');d.querySelector(\'[name=korr_was]\').value=\'' . $was . '\';d.showModal()">&#9998;</button>' : '';
    echo '<div class="bx-card"><div class="k">Menge (verfügbar)' . $stift('verfuegbar') . '</div><div class="v">' . $num($c['menge_verfuegbar']) . ' ' . h($c['einheit']) . '</div></div>';
    echo '<div class="bx-card"><div class="k">Ursprung' . $stift('ursprung') . '</div><div class="v">' . $num($c['menge']) . ' ' . h($c['einheit']) . '</div></div>';
    echo '<div class="bx-card"><div class="k">MHD</div><div class="v">' . ($c['mhd'] ? h(date('d.m.Y', strtotime($c['mhd']))) : '–') . '</div></div>';
    echo '</div>';
    if (isset($_GET['kok'])) echo '<div class="bx-panel badge-ok" style="padding:10px 14px">' . h((string)$_GET['kok']) . '</div>';
    if (isset($_GET['kfehler'])) echo '<div class="bx-panel" style="padding:10px 14px;border-color:#e6c4c0;color:#8f231b">' . h((string)$_GET['kfehler']) . '</div>';
    if ($istAdminC) echo charge_korr_dialog($c, 'dlgKorr', '<input type="hidden" name="aktion" value="menge_korr">');
    ?>
    <div class="bx-panel">
      <h2 style="margin-top:0">Bestellung</h2>
      <?php if ($bestBezug): ?>
        <div class="bx-row" style="justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
          <div>Verknüpft mit <a href="?p=bestellung&id=<?= (int)$bestBezug['bid'] ?>"><?= h($bestBezug['nummer']) ?></a>
            <span class="muted">· bestellt <?= $num($bestBezug['menge']) ?> <?= h((string)$bestBezug['einheit']) ?> · geliefert <?= $num($bestBezug['geliefert']) ?> · <?= h(status_text((string)$bestBezug['status'])) ?></span></div>
          <?php if ($istAdminC): ?><form method="post" style="margin:0" onsubmit="return confirm('Verknüpfung mit <?= h($bestBezug['nummer']) ?> lösen? Die Bestellung rechnet die gelieferte Menge neu und geht ggf. wieder auf.');">
            <input type="hidden" name="aktion" value="verkn_loesen"><button class="btn btn-ghost btn-sm" type="submit">Verknüpfung lösen</button></form><?php endif; ?>
        </div>
      <?php else: ?><div class="muted">Mit keiner Bestellung verknüpft.</div><?php endif; ?>
    </div>
    <?php if ($korrListe): ?>
    <div class="bx-panel">
      <h2 style="margin-top:0">Korrekturen</h2>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Wann</th><th>Was</th><th class="bx-num">alt</th><th class="bx-num">neu</th><th class="bx-num">Differenz</th><th>Grund</th><th>Wer</th><th>Beleg</th></tr></thead>
        <tbody><?php foreach ($korrListe as $kr): $u = $kr['art'] === 'ursprung'; ?>
          <tr><td><?= h(fmt_zeit((string)$kr['angelegt'])) ?></td><td><?= $u ? 'Ursprungsmenge' : 'verfügbar' ?></td>
            <td class="bx-num"><?= $num($u ? $kr['menge_alt'] : $kr['verf_alt']) ?></td><td class="bx-num"><?= $num($u ? $kr['menge_neu'] : $kr['verf_neu']) ?></td>
            <td class="bx-num"><?= ((float)$kr['differenz'] > 0 ? '+' : '') . $num($kr['differenz']) ?> <?= h((string)$kr['einheit']) ?></td>
            <td><?= h(charge_korr_gruende()[$kr['grund']] ?? $kr['grund']) ?><?= $kr['notiz'] ? '<div class="muted" style="font-size:12px">' . h($kr['notiz']) . '</div>' : '' ?><?= $kr['gebinde'] || $kr['bestellung'] ? '<div class="muted" style="font-size:12px">' . h(trim($kr['gebinde'] . ' ' . $kr['bestellung'])) . '</div>' : '' ?></td>
            <td><?= h((string)$kr['von']) ?></td>
            <td><?= $kr['beleg'] ? '<a href="?p=dokument&id=' . (int)$kr['beleg'] . '" target="_blank">Beleg</a>' : '<span class="muted">-</span>' ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    </div>
    <?php endif; ?>
    <div class="bx-panel">
      <h2>Herkunft</h2>
      <div class="bx-grid">
        <div><div class="k muted">Artikel</div><div><?= h($c['item_name']) ?></div></div>
        <div><div class="k muted">Lieferant</div><div><?= $c['lieferant_firma'] ? h($c['lieferant_firma']) : '<span class="muted">–</span>' ?></div></div>
        <div><div class="k muted">Wareneingang</div><div><?= $c['wareneingang'] ? h(fmt_zeit($c['wareneingang'], 'd.m.Y')) : '<span class="muted">–</span>' ?></div></div>
        <div><div class="k muted">Für Auftrag</div><div><?php if ($c['auftrag_id']): ?><a href="?p=auftrag&id=<?= (int)$c['auftrag_id'] ?>"><?= h($c['auftrag_nr']) ?></a><?= $c['auftrag_kunde'] ? ' · ' . h($c['auftrag_kunde']) : '' ?><?php else: ?><span class="muted">Lager / allgemein</span><?php endif; ?></div></div>
      </div>
      <?php if ($c['notiz']): ?><div style="margin-top:10px"><div class="k muted">Notiz</div><div><?= h($c['notiz']) ?></div></div><?php endif; ?>
    </div>

    <?php if ($bestandteile): ?>
    <div class="bx-panel">
      <h2>Zusammensetzung (eingesetzte Chargen)</h2>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Rohstoff</th><th>Charge</th><th class="bx-num">Menge</th><th>MHD</th></tr></thead>
        <tbody>
          <?php foreach ($bestandteile as $b): ?>
            <tr>
              <td><?= h($b['item_name']) ?></td>
              <td><?php if ($b['charge_id']): ?><a href="?p=chargen&id=<?= (int)$b['charge_id'] ?>"><?= h($b['charge_nr']) ?></a><?php else: ?><span class="muted">–</span><?php endif; ?></td>
              <td class="bx-num"><?= $num($b['menge']) ?> <?= h($b['einheit']) ?></td>
              <td><?= $b['mhd'] ? h(date('d.m.Y', strtotime($b['mhd']))) : '<span class="muted">–</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php endif; ?>

    <div class="bx-panel">
      <h2>Verwendet in Produktionen</h2>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Produktionsauftrag</th><th>Produkt</th><th>Kunde</th><th>Kundencharge</th><th class="bx-num">Eingesetzt</th></tr></thead>
        <tbody>
          <?php if (!$verwendet): ?><tr><td colspan="5" class="muted">Diese Charge wurde bisher in keiner Produktion eingesetzt.</td></tr><?php endif; ?>
          <?php foreach ($verwendet as $v):
                // Entstandene Fertigware-/Kundencharge(n) dieses PA (die aus dieser Bulk-Charge abgefüllt wurden).
                $kchg = ($v['pa_id'] ?? 0) ? all("SELECT fc.id, fc.charge_nr FROM charge fc JOIN item fi ON fi.id=fc.item_id
                        WHERE fc.pa_id=? AND fi.kategorie IN ('fertig','verkaufsfertig') AND fc.id<>? ORDER BY fc.id", [(int)$v['pa_id'], $chId]) : []; ?>
            <tr>
              <td><a href="?p=produktionsauftrag&id=<?= (int)$v['pa_id'] ?>"><?= h($v['pa_nr'] ?: ('#' . $v['pa_id'])) ?></a></td>
              <td><?= $v['produkt_name'] ? h($v['produkt_name']) : '<span class="muted">–</span>' ?></td>
              <td><?= kunde_link($v['kunde_id'] ?? null, $v['kunde_firma']) ?></td>
              <td><?php if ($kchg): foreach ($kchg as $ii => $kc): ?><?= $ii ? ', ' : '' ?><a href="?p=chargen&id=<?= (int)$kc['id'] ?>"><?= h((string)($kc['charge_nr'] ?: ('#' . $kc['id']))) ?></a><?php endforeach; else: ?><span class="muted">–</span><?php endif; ?></td>
              <td class="bx-num"><?= $num($v['menge']) ?> <?= h($v['einheit']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php
    render_footer(); exit;
}

// ---------- Liste ----------
$q = trim($_GET['q'] ?? '');
$where = ''; $params = [];
if ($q !== '') { $where = "WHERE (c.charge_nr LIKE ? OR i.name LIKE ?)"; $like = '%' . str_replace('%', '', $q) . '%'; $params = [$like, $like]; }
$rows = all("SELECT c.*, i.name AS item_name, i.kategorie AS kategorie, l.firma AS lieferant_firma
             FROM charge c LEFT JOIN item i ON i.id=c.item_id LEFT JOIN lieferanten l ON l.id=c.lieferant_id
             $where ORDER BY c.angelegt DESC", $params);

render_header('chargen', 'Chargen');
bx_head('Chargenverfolgung', 'Alle Chargen mit Rückverfolgung Rohstoff ↔ Produkt.');
?>
<form method="get" class="bx-row" style="gap:8px;margin-bottom:16px">
  <input type="hidden" name="p" value="chargen">
  <input type="text" name="q" value="<?= h($q) ?>" placeholder="Charge-Nr. oder Artikel suchen" style="max-width:340px">
  <button class="btn btn-primary" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost" href="?p=chargen">Zurücksetzen</a><?php endif; ?>
</form>
<div class="bx-panel">
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Charge</th><th>Artikel</th><th>Art</th><th class="bx-num">Verfügbar</th><th>MHD</th><th>Lieferant</th><th>Status</th></tr></thead>
    <tbody>
      <?php if (!$rows): ?><tr><td colspan="7" class="muted">Keine Chargen gefunden.</td></tr><?php endif; ?>
      <?php foreach ($rows as $c): ?>
        <tr style="cursor:pointer" onclick="location.href='?p=chargen&id=<?= (int)$c['id'] ?>'">
          <td><?= h($c['charge_nr'] ?: ('#' . $c['id'])) ?></td>
          <td><?= $c['item_name'] ? h($c['item_name']) : '<span class="muted">–</span>' ?></td>
          <td><?= h($katLbl[$c['kategorie']] ?? $c['kategorie']) ?><?= !empty($c['kapselgroesse_id']) ? ' · ' . h(kapselgroesse_name((int)$c['kapselgroesse_id'])) : '' ?></td>
          <td class="bx-num"><?= $num($c['menge_verfuegbar']) ?> <?= h($c['einheit']) ?></td>
          <td><?= $c['mhd'] ? h(date('d.m.Y', strtotime($c['mhd']))) : '<span class="muted">–</span>' ?></td>
          <td><?= $c['lieferant_firma'] ? h($c['lieferant_firma']) : '<span class="muted">–</span>' ?></td>
          <td><?= $chBadge($c['status']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php render_footer(); ?>
