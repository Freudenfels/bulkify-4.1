<?php
// Rechnung (Beleg) – Ansicht + Status (offen/bezahlt)
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    $aktion = $_POST['aktion'] ?? 'status';
    $akteur = (function_exists('current_user') && ($u = current_user())) ? $u['name'] : 'team';

    if ($aktion === 'zahlung') {
        $betrag = (float) str_replace(',', '.', trim($_POST['betrag'] ?? '0'));
        if ($betrag > 0) {
            zahlung_erfassen($id, $betrag, trim($_POST['datum'] ?? '') ?: null,
                             trim($_POST['konto'] ?? '') ?: null, trim($_POST['art'] ?? '') ?: null,
                             trim($_POST['zahl_notiz'] ?? ''), $akteur);
        }
        header('Location: ?p=rechnung&id=' . $id . '&gespeichert=1'); exit;
    }

    // Status manuell setzen (Override, z. B. storniert)
    $status = trim($_POST['status'] ?? 'offen');
    $notiz  = trim($_POST['status_notiz'] ?? '');
    $b = one("SELECT kunde_id,nummer,status FROM beleg WHERE id=?", [$id]);
    $alt = $b['status'] ?? '';
    if ($b && $status !== $alt) {
        q("UPDATE beleg SET status=? WHERE id=?", [$status, $id]);
        beleg_status_verlauf($id);   // sichert Backfill des „erstellt"-Eintrags vor dem neuen Eintrag
        beleg_status_log_add($id, $status, $notiz ?: 'manuell gesetzt', $akteur);
        if ($status === 'bezahlt' && $b['kunde_id']) log_aktivitaet('kunde', (int)$b['kunde_id'], 'team', 'Rechnung ' . $b['nummer'] . ' als bezahlt markiert.', 'beleg', 'beleg', $id);
    } elseif ($b && $notiz !== '') {
        beleg_status_log_add($id, $status, $notiz, $akteur);
    }
    header('Location: ?p=rechnung&id=' . $id . '&gespeichert=1'); exit;
}

$b = $id ? one("SELECT b.*, k.firma AS kunde_firma, a.nummer AS auftrag_nr
                FROM beleg b LEFT JOIN kunden k ON k.id=b.kunde_id LEFT JOIN auftrag a ON a.id=b.auftrag_id
                WHERE b.id=?", [$id]) : null;
if (!$b) { render_header('rechnungen','Rechnung'); bx_head('Rechnung nicht gefunden','', bx_btn('Zurück','?p=rechnungen','ghost')); render_footer(); exit; }

$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
$zBadge = fn($s) => match ($s) {
    'bezahlt'     => bx_badge('bezahlt','ok'),
    'teilbezahlt' => bx_badge('teilbezahlt','info'),
    'offen'       => bx_badge('offen','warn'),
    'storniert'   => bx_badge('storniert','err'),
    'erstellt'    => bx_badge('erstellt','info'),
    default       => bx_badge($s),
};
$zs = beleg_zahlstatus($b);   // abgeleiteter Zahlstatus + bezahlt/rest

render_header('rechnungen', $b['nummer']);
bx_head($b['nummer'], 'Rechnung' . ($b['datum'] ? ' vom ' . date('d.m.Y', strtotime($b['datum'])) : ''), bx_btn('Zurück zur Liste', '?p=rechnungen', 'ghost'));
if (isset($_GET['gespeichert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';

echo '<div class="bx-cards">';
echo '<div class="bx-card"><div class="k">Status</div><div class="v">' . $zBadge($zs['status']) . '</div></div>';
echo '<div class="bx-card"><div class="k">Brutto</div><div class="v">' . $eur($b['brutto']) . '</div></div>';
echo '<div class="bx-card"><div class="k">Bezahlt</div><div class="v">' . $eur($zs['bezahlt']) . '</div></div>';
echo '<div class="bx-card"><div class="k">Offener Rest</div><div class="v">' . ($zs['rest'] > 0.005 ? '<strong>' . $eur($zs['rest']) . '</strong>' : $eur(0)) . '</div></div>';
echo '</div>';
?>
<div class="bx-panel">
  <h2>Details</h2>
  <div class="bx-grid">
    <div><div class="k muted">Kunde</div><div><?= kunde_link($b['kunde_id'] ?? null, $b['kunde_firma']) ?></div></div>
    <div><div class="k muted">Zu Auftrag</div><div><?php if ($b['auftrag_id']): ?><a href="?p=auftrag&id=<?= (int)$b['auftrag_id'] ?>"><?= h($b['auftrag_nr']) ?></a><?php else: ?>–<?php endif; ?></div></div>
    <div><div class="k muted">Art</div><div><?= h(ucfirst($b['typ'])) ?></div></div>
    <div><div class="k muted">Netto</div><div><?= $eur($b['netto']) ?></div></div>
    <div><div class="k muted">USt (<?= rtrim(rtrim(number_format((float)$b['ust_prozent'],2,',','.'),'0'),',') ?> %)</div><div><?= $eur($b['ust_betrag']) ?></div></div>
  </div>
</div>

<?php
$zahlungen = zahlungen_fuer($id);
$konten = bank_konten();
$artLbl = ['ueberweisung'=>'Überweisung','lastschrift'=>'Lastschrift','paypal'=>'PayPal','bar'=>'Bar','sonstiges'=>'Sonstiges'];
?>
<!-- Zahlung erfassen -->
<?php if ($b['typ'] === 'rechnung' && $b['status'] !== 'storniert'): ?>
<form method="post" class="bx-form">
  <input type="hidden" name="aktion" value="zahlung">
  <div class="bx-panel">
    <h2 style="margin-top:0">Zahlung erfassen</h2>
    <div class="bx-grid">
      <div class="bx-field"><label>Betrag</label><input type="text" inputmode="decimal" name="betrag" value="<?= $zs['rest'] > 0.005 ? number_format($zs['rest'],2,',','') : '' ?>" placeholder="0,00"></div>
      <div class="bx-field"><label>Überweisungsdatum (Valuta)</label><input type="date" name="datum"></div>
      <div class="bx-field"><label>Konto</label>
        <?php if ($konten): ?>
        <select name="konto">
          <?php foreach ($konten as $kt): ?><option value="<?= h($kt['key']) ?>"><?= h($kt['label']) ?></option><?php endforeach; ?>
        </select>
        <?php else: ?>
        <input type="text" name="konto" placeholder="Konto (in Einstellungen hinterlegen)">
        <?php endif; ?>
      </div>
      <div class="bx-field"><label>Art</label>
        <select name="art">
          <?php foreach ($artLbl as $key=>$lbl): ?><option value="<?= $key ?>"><?= $lbl ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Anmerkung (optional)</label><input type="text" name="zahl_notiz" placeholder="z. B. Teilzahlung 1. Rate"></div>
    </div>
  </div>
  <button class="btn btn-primary" type="submit">Zahlung buchen</button>
</form>
<?php endif; ?>

<!-- Zahlungseingänge -->
<div class="bx-panel">
  <h2>Zahlungseingänge</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Überweisungsdatum</th><th class="bx-num">Betrag</th><th>Konto</th><th>Art</th><th>Anmerkung</th><th>Erfasst</th></tr></thead>
    <tbody>
      <?php if (!$zahlungen): ?><tr><td colspan="6" class="muted">Noch keine Zahlungseingänge erfasst.</td></tr><?php endif; ?>
      <?php foreach ($zahlungen as $z): ?>
        <tr>
          <td><?= $z['datum'] ? h(date('d.m.Y', strtotime($z['datum']))) : '<span class="muted">–</span>' ?></td>
          <td class="bx-num"><?= $eur($z['betrag']) ?></td>
          <td><?= $z['konto'] ? h(bank_konto_label($z['konto'])) : '<span class="muted">–</span>' ?></td>
          <td><?= $z['art'] ? h($artLbl[$z['art']] ?? $z['art']) : '<span class="muted">–</span>' ?></td>
          <td><?= $z['notiz'] ? h($z['notiz']) : '<span class="muted">–</span>' ?></td>
          <td class="muted"><?= h(fmt_zeit($z['angelegt'], 'd.m.Y H:i')) ?><?= $z['akteur'] ? ' · ' . h($z['akteur']) : '' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($zahlungen): ?>
        <tr><td class="muted">Summe</td><td class="bx-num"><strong><?= $eur($zs['bezahlt']) ?></strong></td><td colspan="4" class="muted"><?= $zs['rest'] > 0.005 ? 'Offener Rest ' . $eur($zs['rest']) : 'Vollständig bezahlt' ?></td></tr>
      <?php endif; ?>
    </tbody>
  </table></div>
</div>

<!-- Status manuell (Override) -->
<details class="bx-form">
  <summary class="btn btn-ghost btn-sm" style="list-style:none">Status manuell setzen</summary>
  <form method="post" style="margin-top:12px">
    <input type="hidden" name="aktion" value="status">
    <div class="bx-panel"><div class="bx-grid">
      <div class="bx-field"><label>Status</label>
        <select name="status">
          <?php foreach (['offen'=>'offen','teilbezahlt'=>'teilbezahlt','bezahlt'=>'bezahlt','storniert'=>'storniert'] as $key=>$lbl): ?>
            <option value="<?= $key ?>" <?= $b['status']===$key?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Anmerkung (optional)</label><input type="text" name="status_notiz" placeholder="Grund der manuellen Änderung"></div>
    </div></div>
    <button class="btn btn-primary" type="submit">Status speichern</button>
  </form>
</details>

<?php $verlauf = beleg_status_verlauf($id); ?>
<div class="bx-panel">
  <h2>Statusverlauf</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Wann</th><th>Status</th><th>Wer</th><th>Anmerkung</th></tr></thead>
    <tbody>
      <?php if (!$verlauf): ?><tr><td colspan="4" class="muted">Noch keine Statusänderungen.</td></tr><?php endif; ?>
      <?php foreach (array_reverse($verlauf) as $v): ?>
        <tr>
          <td><?= h(fmt_zeit($v['angelegt'], 'd.m.Y H:i')) ?></td>
          <td><?= $zBadge($v['status']) ?></td>
          <td><?= $v['akteur'] ? h($v['akteur']) : '<span class="muted">System</span>' ?></td>
          <td><?= $v['notiz'] ? h($v['notiz']) : '<span class="muted">–</span>' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php render_footer(); ?>
