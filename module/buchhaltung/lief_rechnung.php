<?php
// Eingangsrechnung – Detail: Beträge, Zahlstatus, Zahlungen buchen, Kopf ändern, stornieren.
// Route: lief_rechnung (Rolle finance). ?id=<lieferant_rechnung>
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/kreditor.php';
kreditor_init();

$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    $aktion = $_POST['aktion'] ?? '';
    if ($aktion === 'zahlung') {
        $betrag = (float) str_replace(',', '.', (string)($_POST['betrag'] ?? '0'));
        $u = current_user();
        kr_zahlung_buchen($id, $betrag, $_POST['datum'] ?? date('Y-m-d'), $_POST['art'] ?? '', $_POST['notiz'] ?? '', $u['name'] ?? 'team');
        header('Location: ?p=lief_rechnung&id=' . $id . '&gebucht=1'); exit;
    }
    if ($aktion === 'kopf') {
        kr_rechnung_update($id, [
            'lief_nummer'       => $_POST['lief_nummer'] ?? '',
            'datum'             => $_POST['datum'] ?? null,
            'eingang_am'        => $_POST['eingang_am'] ?? null,
            'netto'             => str_replace(',', '.', (string)($_POST['netto'] ?? '0')),
            'ust_prozent'       => str_replace(',', '.', (string)($_POST['ust_prozent'] ?? '0')),
            'zahlungsziel_tage' => (int)($_POST['zahlungsziel_tage'] ?? 0),
            'notiz'             => $_POST['notiz'] ?? '',
        ]);
        header('Location: ?p=lief_rechnung&id=' . $id . '&gespeichert=1'); exit;
    }
    if ($aktion === 'storno') {
        kr_rechnung_stornieren($id, $_POST['grund'] ?? '');
        header('Location: ?p=lief_rechnung&id=' . $id . '&storniert=1'); exit;
    }
}

$r = $id ? kr_rechnung_get($id) : null;
if (!$r) { render_header('buchhaltung','Eingangsrechnung'); bx_head('Eingangsrechnung nicht gefunden','', bx_btn('Zurück','?p=buchhaltung&tab=verbindlichkeiten','ghost')); render_footer(); exit; }

$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
$zs = kr_zahlstatus($r);
$zahlungen = kr_zahlungen($id);
$badge = match ($zs['status']) {
    'bezahlt'     => bx_badge('bezahlt', 'ok'),
    'teilbezahlt' => bx_badge('teilbezahlt', 'info'),
    'offen'       => bx_badge('offen', 'warn'),
    'storniert'   => bx_badge('storniert', 'err'),
    default       => bx_badge($zs['status']),
};
$ueberfaellig = $r['faellig'] && $zs['status'] !== 'bezahlt' && $zs['status'] !== 'storniert' && strtotime($r['faellig']) < strtotime(date('Y-m-d'));

render_header('buchhaltung', 'Eingangsrechnung ' . $r['nummer']);
bx_head(trim(($r['nummer'] ?: 'Eingangsrechnung') . ' · ' . ($r['firma'] ?? '')),
        'Eingangsrechnung' . ($r['lief_nummer'] ? ' (Lieferant: ' . $r['lief_nummer'] . ')' : '') . ' · ' . $badge,
        bx_btn('Zurück', '?p=buchhaltung&tab=verbindlichkeiten', 'ghost'));
foreach (['erfasst'=>'Eingangsrechnung erfasst.','gebucht'=>'Zahlung gebucht.','gespeichert'=>'Gespeichert.','storniert'=>'Eingangsrechnung storniert.'] as $k=>$msg)
    if (isset($_GET[$k])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h($msg) . '</div>';
?>
<div class="bx-cards" style="align-items:flex-start">
  <div class="bx-panel" style="flex:1;min-width:320px">
    <h2>Beträge</h2>
    <div class="bx-tablewrap"><table class="bx-table"><tbody>
      <tr><td>Lieferant</td><td><?= $r['firma'] ? h($r['firma']) : '<span class="muted">–</span>' ?></td></tr>
      <tr><td>Rechnungsdatum</td><td><?= $r['datum'] ? h(date('d.m.Y', strtotime($r['datum']))) : '<span class="muted">–</span>' ?></td></tr>
      <tr><td>Fällig</td><td><?= $r['faellig'] ? h(date('d.m.Y', strtotime($r['faellig']))) . ($ueberfaellig ? ' <span style="color:var(--err)">überfällig</span>' : '') : '<span class="muted">–</span>' ?></td></tr>
      <?php if ($r['bestell_nummer']): ?><tr><td>Bestellung</td><td><a href="?p=einkauf"><?= h($r['bestell_nummer']) ?></a></td></tr><?php endif; ?>
      <tr><td>Netto</td><td class="bx-num"><?= $eur($r['netto']) ?></td></tr>
      <tr><td>Vorsteuer (<?= number_format((float)$r['ust_prozent'],0) ?> %)</td><td class="bx-num"><?= $eur($r['ust_betrag']) ?></td></tr>
      <tr><td><strong>Brutto</strong></td><td class="bx-num"><strong><?= $eur($r['brutto']) ?></strong></td></tr>
      <tr><td>Bereits bezahlt</td><td class="bx-num"><?= $eur($zs['bezahlt']) ?></td></tr>
      <tr><td><strong>Offen</strong></td><td class="bx-num"><strong style="<?= $zs['rest'] > 0 ? 'color:var(--warn)' : 'color:var(--gruen)' ?>"><?= $eur($zs['rest']) ?></strong></td></tr>
    </tbody></table></div>
  </div>
  <div class="bx-panel" style="flex:1;min-width:320px">
    <h2>Zahlungen</h2>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Datum</th><th>Art</th><th class="bx-num">Betrag</th></tr></thead>
      <tbody>
        <?php if (!$zahlungen): ?><tr><td colspan="3" class="muted">Noch keine Zahlung erfasst.</td></tr><?php endif; ?>
        <?php foreach ($zahlungen as $z): ?>
          <tr><td><?= $z['datum'] ? h(date('d.m.Y', strtotime($z['datum']))) : '' ?></td><td><?= h($z['art'] ?? '') ?><?= $z['notiz'] ? ' <span class="muted">· ' . h($z['notiz']) . '</span>' : '' ?></td><td class="bx-num"><?= $eur($z['betrag']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($zs['status'] !== 'storniert' && $zs['rest'] > 0.005): ?>
    <form method="post" class="bx-form" style="margin-top:12px">
      <input type="hidden" name="aktion" value="zahlung">
      <div class="bx-row">
        <label>Betrag (EUR)<input type="text" inputmode="decimal" name="betrag" value="<?= h(number_format($zs['rest'], 2, ',', '')) ?>" required></label>
        <label>Datum<input type="date" name="datum" value="<?= date('Y-m-d') ?>"></label>
        <label>Art<input type="text" name="art" placeholder="Überweisung"></label>
      </div>
      <div class="bx-row" style="margin-top:var(--sp-3)"><button class="btn btn-primary" type="submit">Zahlung buchen</button></div>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($zs['status'] !== 'storniert'): ?>
<details class="bx-panel" style="margin-top:16px">
  <summary class="btn btn-ghost btn-sm" style="list-style:none">Kopf bearbeiten</summary>
  <form method="post" class="bx-form" style="margin-top:12px">
    <input type="hidden" name="aktion" value="kopf">
    <div class="bx-row">
      <label>Rechnungsnummer (Lieferant)<input type="text" name="lief_nummer" value="<?= h($r['lief_nummer'] ?? '') ?>"></label>
      <label>Rechnungsdatum<input type="date" name="datum" value="<?= h($r['datum'] ?? '') ?>"></label>
      <label>Eingang<input type="date" name="eingang_am" value="<?= h($r['eingang_am'] ?? '') ?>"></label>
    </div>
    <div class="bx-row">
      <label>Netto (EUR)<input type="text" inputmode="decimal" name="netto" value="<?= h(number_format((float)$r['netto'], 2, ',', '')) ?>"></label>
      <label>Vorsteuer %<input type="text" inputmode="decimal" name="ust_prozent" value="<?= h(number_format((float)$r['ust_prozent'], 0)) ?>"></label>
      <label>Zahlungsziel (Tage)<input type="number" name="zahlungsziel_tage" value="<?= (int)$r['zahlungsziel_tage'] ?>" min="0"></label>
    </div>
    <label>Notiz<textarea name="notiz" rows="2"><?= h($r['notiz'] ?? '') ?></textarea></label>
    <div class="bx-row" style="margin-top:var(--sp-3)"><button class="btn btn-primary" type="submit">Kopf speichern</button></div>
  </form>
</details>
<details class="bx-panel" style="margin-top:16px">
  <summary class="btn btn-ghost btn-sm" style="list-style:none">Stornieren</summary>
  <form method="post" class="bx-form" style="margin-top:12px" onsubmit="return confirm('Eingangsrechnung <?= h($r['nummer']) ?> stornieren?');">
    <input type="hidden" name="aktion" value="storno">
    <label>Grund<input type="text" name="grund" placeholder="optional"></label>
    <div class="bx-row" style="margin-top:var(--sp-3)"><button class="btn btn-danger" type="submit">Stornieren</button></div>
  </form>
</details>
<?php endif; ?>
<?php
render_footer();
