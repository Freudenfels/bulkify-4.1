<?php
// Rechnung (Beleg) – Ansicht + Status (offen/bezahlt)
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    $aktion = $_POST['aktion'] ?? 'status';
    $akteur = (function_exists('current_user') && ($u = current_user())) ? $u['name'] : 'team';

    // Fuer den Kunden freigeben / Freigabe zurueckziehen (wie bei Angeboten).
    if ($aktion === 'freigeben' || $aktion === 'zurueckziehen') {
        $frei = $aktion === 'freigeben' ? 1 : 0;
        q("UPDATE beleg SET kunde_sichtbar=? WHERE id=?", [$frei, $id]);
        $bx = one("SELECT kunde_id, nummer, status FROM beleg WHERE id=?", [$id]);
        beleg_status_log_add($id, (string)($bx['status'] ?? 'offen'), $frei ? 'Für Kunde freigegeben' : 'Freigabe zurückgezogen', $akteur);
        if ($bx && $bx['kunde_id']) log_aktivitaet('kunde', (int)$bx['kunde_id'], 'team', 'Rechnung ' . $bx['nummer'] . ($frei ? ' für den Kunden freigegeben.' : ' – Freigabe zurückgezogen.'), 'beleg', 'beleg', $id);
        header('Location: ?p=rechnung&id=' . $id . '&freigabe=' . $frei); exit;
    }

    if ($aktion === 'zahlung') {
        $betrag = (float) str_replace(',', '.', trim($_POST['betrag'] ?? '0'));
        if ($betrag > 0) {
            zahlung_erfassen($id, $betrag, trim($_POST['datum'] ?? '') ?: null,
                             trim($_POST['konto'] ?? '') ?: null, trim($_POST['art'] ?? '') ?: null,
                             trim($_POST['zahl_notiz'] ?? ''), $akteur);
        }
        header('Location: ?p=rechnung&id=' . $id . '&gespeichert=1'); exit;
    }

    // Rechnung stornieren -> Gutschrift (negativ) erzeugen + Original auf 'storniert'
    if ($aktion === 'storno') {
        $grund = trim($_POST['grund'] ?? '');
        $gid = gutschrift_aus_rechnung($id, $grund, $akteur);
        header('Location: ?p=rechnung&id=' . ($gid ?: $id) . '&storniert=1'); exit;
    }

    // Guthaben auf diese Rechnung anrechnen (verrechnen)
    if ($aktion === 'guthaben_anrechnen') {
        $wunsch = (float) str_replace(',', '.', trim($_POST['betrag'] ?? '0'));
        $an = guthaben_anrechnen($id, $wunsch, $akteur);
        header('Location: ?p=rechnung&id=' . $id . '&angerechnet=' . number_format($an, 2, '.', '')); exit;
    }
    // Guthaben (dieses Gutschrift-Kunden) auszahlen
    if ($aktion === 'guthaben_auszahlen') {
        $kid    = (int)($_POST['kunde_id'] ?? 0);
        $wunsch = (float) str_replace(',', '.', trim($_POST['betrag'] ?? '0'));
        $aus = guthaben_auszahlen($kid, $wunsch, trim($_POST['notiz'] ?? ''), $akteur);
        header('Location: ?p=rechnung&id=' . $id . '&ausgezahlt=' . number_format($aus, 2, '.', '')); exit;
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

$istGut     = ($b['typ'] === 'gutschrift');
$guthaben   = $b['kunde_id'] ? kunde_guthaben((int)$b['kunde_id']) : 0.0;   // verfügbares Kunden-Guthaben
$positionen = all("SELECT * FROM beleg_position WHERE beleg_id=? ORDER BY sort, id", [$id]);
$stornoVon  = !empty($b['storno_von_id']) ? one("SELECT id,nummer FROM beleg WHERE id=?", [(int)$b['storno_von_id']]) : null;   // diese Gutschrift storniert ...
$stornoDurch= one("SELECT id,nummer FROM beleg WHERE storno_von_id=? AND typ='gutschrift'", [$id]);                            // ... diese Rechnung wurde storniert durch

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

$istFrei = (int)($b['kunde_sichtbar'] ?? 0) === 1;
// Freigeben/Zurueckziehen (wie bei Angeboten) – nur fuer Rechnungen, nicht bei Storno.
$freiBtn = '';
if (!$istGut && $b['status'] !== 'storniert') {
    $freiBtn = $istFrei
        ? '<form method="post" style="display:inline;margin:0"><input type="hidden" name="aktion" value="zurueckziehen"><button class="btn btn-ghost" type="submit">Freigabe zurückziehen</button></form> '
        : '<form method="post" style="display:inline;margin:0"><input type="hidden" name="aktion" value="freigeben"><button class="btn btn-primary" type="submit">Für Kunde freigeben</button></form> ';
}
render_header('rechnungen', $b['nummer']);
bx_head($b['nummer'], ($istGut ? 'Storno-Rechnung / Gutschrift' : 'Rechnung') . ($b['datum'] ? ' vom ' . date('d.m.Y', strtotime($b['datum'])) : ''),
        $freiBtn . bx_btn('PDF ansehen', '?p=' . ($istGut ? 'gutschrift_pdf' : 'rechnung_pdf') . '&id=' . $id, 'ghost') . ' ' . bx_btn('Zurück zur Liste', '?p=rechnungen', 'ghost'));
if (isset($_GET['freigabe'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . ($_GET['freigabe'] === '1' ? 'Rechnung für den Kunden freigegeben – jetzt im Portal sichtbar.' : 'Freigabe zurückgezogen – nicht mehr im Kundenportal sichtbar.') . '</div>';
if (isset($_GET['erstellt'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Rechnung aus dem Auftrag erstellt. Beträge/USt stammen aus dem Auftrag – bei Bedarf unten Zahlungen erfassen oder stornieren.</div>';
if (isset($_GET['gespeichert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
if (isset($_GET['storniert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Rechnung storniert – Gutschrift wurde erstellt.</div>';
if (isset($_GET['angerechnet'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . ((float)$_GET['angerechnet'] > 0 ? $eur((float)$_GET['angerechnet']) . ' Guthaben angerechnet.' : 'Kein Guthaben angerechnet (nichts verfügbar/offen).') . '</div>';
if (isset($_GET['ausgezahlt'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . ((float)$_GET['ausgezahlt'] > 0 ? $eur((float)$_GET['ausgezahlt']) . ' Guthaben als ausgezahlt verbucht.' : 'Kein Guthaben ausgezahlt.') . '</div>';
// Bezug-Hinweise
if ($stornoVon)  echo '<div class="bx-panel" style="padding:10px 14px">Storno zu Rechnung <a href="?p=rechnung&id=' . (int)$stornoVon['id'] . '">' . h($stornoVon['nummer']) . '</a>.</div>';
if ($stornoDurch) echo '<div class="bx-panel" style="padding:10px 14px;border-color:#e6c4c0">Diese Rechnung wurde storniert – Gutschrift <a href="?p=rechnung&id=' . (int)$stornoDurch['id'] . '">' . h($stornoDurch['nummer']) . '</a>.</div>';

echo '<div class="bx-cards">';
echo '<div class="bx-card"><div class="k">Status</div><div class="v">' . $zBadge($zs['status']) . '</div></div>';
echo '<div class="bx-card"><div class="k">Brutto</div><div class="v">' . $eur($b['brutto']) . '</div></div>';
echo '<div class="bx-card"><div class="k">Bezahlt</div><div class="v">' . $eur($zs['bezahlt']) . '</div></div>';
echo '<div class="bx-card"><div class="k">Offener Rest</div><div class="v">' . ($zs['rest'] > 0.005 ? '<strong>' . $eur($zs['rest']) . '</strong>' : $eur(0)) . '</div></div>';
if (!$istGut) echo '<div class="bx-card"><div class="k">Kundenportal</div><div class="v">' . ($istFrei ? bx_badge('freigegeben', 'ok') : bx_badge('nicht freigegeben', 'warn')) . '</div></div>';
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
    <?php if (!empty($b['leistung_datum'])): ?><div><div class="k muted">Leistungsdatum</div><div><?= h(date('d.m.Y', strtotime((string)$b['leistung_datum']))) ?></div></div><?php endif; ?>
    <?php if (!empty($b['faellig'])): ?><div><div class="k muted">Fällig bis</div><div><?= h(date('d.m.Y', strtotime((string)$b['faellig']))) ?><?php if (!empty($b['zahlungsziel_tage'])): ?> <span class="muted" style="font-size:12px">(<?= (int)$b['zahlungsziel_tage'] ?> Tage)</span><?php endif; ?></div></div><?php endif; ?>
  </div>
  <?php if (!empty($b['text'])): ?><div class="muted" style="margin-top:10px;white-space:pre-line;font-size:13px"><?= h((string)$b['text']) ?></div><?php endif; ?>
</div>

<?php if ($positionen): ?>
<div class="bx-panel">
  <h2>Positionen</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Pos.</th><th>Artikel-Nr.</th><th>Bezeichnung</th><th class="bx-num">Menge</th><th>Einheit</th><th class="bx-num">Einzelpreis</th><th class="bx-num">Gesamt</th></tr></thead>
    <tbody>
      <?php $i=0; foreach ($positionen as $p): $ep=(int)$p['preis_cent']/100; $ge=$ep*(float)$p['menge']; $i++; ?>
      <tr>
        <td><?= $i ?></td>
        <td><?= h($p['artikelnr'] ?: '–') ?></td>
        <td><?= h($p['bezeichnung']) ?><?php if ($p['beschreibung']): ?><div class="muted" style="font-size:12px;white-space:pre-line"><?= h($p['beschreibung']) ?></div><?php endif; ?></td>
        <td class="bx-num"><?= rtrim(rtrim(number_format((float)$p['menge'],2,',','.'),'0'),',') ?></td>
        <td><?= h($p['einheit'] ?: '') ?></td>
        <td class="bx-num"><?= $eur($ep) ?></td>
        <td class="bx-num"><?= $eur($ge) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php if ($b['typ'] === 'rechnung' && $b['status'] !== 'storniert' && !$stornoDurch): ?>
<details class="bx-form">
  <summary class="btn btn-ghost btn-sm" style="list-style:none">Rechnung stornieren</summary>
  <form method="post" style="margin-top:12px" onsubmit="return confirm('Rechnung <?= h($b['nummer']) ?> stornieren? Es wird eine Gutschrift erzeugt und die Rechnung auf „storniert“ gesetzt.');">
    <input type="hidden" name="aktion" value="storno">
    <div class="bx-panel">
      <p class="muted" style="margin-top:0">Erzeugt eine <strong>Storno-Rechnung (Gutschrift)</strong> mit negativen Beträgen als Ausgleich zu dieser Rechnung.<?php if ($zs['bezahlt'] > 0.005): ?> <strong>Achtung:</strong> Es wurden bereits <?= $eur($zs['bezahlt']) ?> gezahlt – ggf. Rückzahlung/Verrechnung beachten.<?php endif; ?></p>
      <div class="bx-field"><label>Grund (optional)</label><input type="text" name="grund" placeholder="z. B. falsche Menge, Kunde storniert"></div>
    </div>
    <button class="btn btn-primary" type="submit">Storno-Rechnung erstellen</button>
  </form>
</details>
<?php endif; ?>

<?php
$zahlungen = zahlungen_fuer($id);
$konten = bank_konten();
$artLbl = ['ueberweisung'=>'Überweisung','lastschrift'=>'Lastschrift','paypal'=>'PayPal','bar'=>'Bar','sonstiges'=>'Sonstiges'];
?>
<!-- Guthaben anrechnen (Rechnung) -->
<?php if (!$istGut && $b['status'] !== 'storniert' && $guthaben > 0.005 && $zs['rest'] > 0.005): $anrMax = min($guthaben, $zs['rest']); ?>
<form method="post" class="bx-form">
  <input type="hidden" name="aktion" value="guthaben_anrechnen">
  <div class="bx-panel" style="border-color:var(--gruen)">
    <h2 style="margin-top:0">Guthaben anrechnen</h2>
    <p class="muted" style="margin-top:0">Verfügbares Guthaben von <?= h($b['kunde_firma'] ?: 'Kunde') ?>: <strong><?= $eur($guthaben) ?></strong> · offener Rest dieser Rechnung: <strong><?= $eur($zs['rest']) ?></strong>.</p>
    <div class="bx-grid">
      <div class="bx-field"><label>Betrag anrechnen</label><input type="text" inputmode="decimal" name="betrag" value="<?= number_format($anrMax, 2, ',', '') ?>"></div>
    </div>
    <button class="btn btn-primary" type="submit">Guthaben anrechnen</button>
  </div>
</form>
<?php endif; ?>

<!-- Guthaben auszahlen (Gutschrift) -->
<?php if ($istGut && $guthaben > 0.005): ?>
<form method="post" class="bx-form" onsubmit="return confirm('Guthaben auszahlen und als Erstattung verbuchen?');">
  <input type="hidden" name="aktion" value="guthaben_auszahlen">
  <input type="hidden" name="kunde_id" value="<?= (int)$b['kunde_id'] ?>">
  <div class="bx-panel">
    <h2 style="margin-top:0">Guthaben auszahlen</h2>
    <p class="muted" style="margin-top:0">Verfügbares Guthaben von <?= h($b['kunde_firma'] ?: 'Kunde') ?>: <strong><?= $eur($guthaben) ?></strong>. Die Auszahlung wird als Verbrauch (Erstattung) verbucht.</p>
    <div class="bx-grid">
      <div class="bx-field"><label>Betrag auszahlen</label><input type="text" inputmode="decimal" name="betrag" value="<?= number_format($guthaben, 2, ',', '') ?>"></div>
      <div class="bx-field"><label>Notiz (optional)</label><input type="text" name="notiz" placeholder="z. B. Überweisung an Kunde"></div>
    </div>
    <button class="btn btn-primary" type="submit">Guthaben auszahlen</button>
  </div>
</form>
<?php endif; ?>

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
