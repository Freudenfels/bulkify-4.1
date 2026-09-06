<?php
// Ein Kunde des Dashboards: Verlauf und Wiedervorlage. Route: ?p=kunde&id=
// Die Stammdaten stehen hier nur zum Lesen - geaendert werden sie im Dashboard.
require_once BX_ROOT . '/core/kontakt.php';
require_once BX_ROOT . '/core/antwort_ki.php';

$id = (int)($_GET['id'] ?? 0);
$k  = erp_kunde($id);
if (!$k) { kopf('Kunde'); seitenkopf('Nicht gefunden'); hinweis('Diesen Kunden gibt es nicht.', 'warn'); fuss('mehr'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tun = (string)($_POST['tun'] ?? '');
    if ($tun === 'verlauf') {
        kunde_verlauf($id, (string)($_POST['typ'] ?? 'notiz'), (string)($_POST['text'] ?? ''), crm_uid());
        header('Location: ?p=kunde&id=' . $id . '&ok=notiert'); exit;
    }
    if ($tun === 'erinnern') {
        $tage = max(0, min(365, (int)($_POST['tage'] ?? 3)));
        kunde_wiedervorlage($id, 'Nachfassen: ' . (string)$k['firma'], $tage, crm_uid(), (string)($_POST['notiz'] ?? ''));
        header('Location: ?p=kunde&id=' . $id . '&ok=erinnert'); exit;
    }
    if ($tun === 'antwort') {
        $_SESSION['antwort'] = antwort_ki_entwurf(
            (string)$k['firma'],
            'Bestandskunde, siehe Verlauf',
            kunde_verlauf_liste($id),
            (string)(crm_benutzer()['name'] ?? 'bulkify'));
        header('Location: ?p=kunde&id=' . $id . '#antwort'); exit;
    }
    if ($tun === 'antwort_verlauf') {
        kunde_verlauf($id, 'notiz', "Antwort gesendet:\n" . (string)($_POST['text'] ?? ''), crm_uid());
        unset($_SESSION['antwort']);
        header('Location: ?p=kunde&id=' . $id . '&ok=notiert'); exit;
    }
    header('Location: ?p=kunde&id=' . $id); exit;
}

$verlauf = kunde_verlauf_liste($id);
$wv      = kunde_wiedervorlagen($id);
$dash    = erp_dashboard_url();

kopf((string)$k['firma'], 'mehr');
seitenkopf((string)$k['firma'],
    trim((string)($k['kundennummer'] ?? '') . ' · ' . (string)($k['ansprechpartner'] ?? ''), ' ·'),
    $dash !== '' ? '<a class="btn btn-ghost" target="_blank" rel="noopener" href="' . h($dash . '/?p=kunde&id=' . $id) . '">Im Dashboard</a>' : '');

$m = (string)($_GET['ok'] ?? '');
if ($m !== '') hinweis($m === 'erinnert' ? 'Wiedervorlage gesetzt.' : 'Notiert.');
?>

<?php if ($wv): ?>
  <div class="karte"><div class="rumpf">
    <h2 style="margin-top:0">Wiedervorlage</h2>
    <?php foreach ($wv as $w): ?>
      <div class="muted"><?= h(fmt_zeit($w['faellig'] . ' 00:00:00', 'd.m.Y')) ?> · <?= h((string)$w['titel']) ?></div>
    <?php endforeach; ?>
  </div></div>
<?php endif; ?>

<div class="karte"><div class="rumpf">
  <h2 style="margin-top:0">Notiz hinzufügen</h2>
  <p class="muted" style="margin-bottom:12px">Was besprochen wurde – das steht im Dashboard nirgends.</p>
  <form method="post">
    <input type="hidden" name="tun" value="verlauf">
    <div class="bx-field"><textarea name="text" required placeholder="z. B. „will im Herbst nachbestellen, Preis nochmal ansehen“"></textarea></div>
    <div class="bx-grid">
      <div class="bx-field">
        <label for="typ">Art</label>
        <select id="typ" name="typ">
          <?php foreach (crm_verlauf_typen() as $tk => $tv): ?><option value="<?= h($tk) ?>"><?= h($tv) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field" style="display:flex;align-items:flex-end">
        <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center">Speichern</button>
      </div>
    </div>
  </form>

  <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;border-top:1px solid var(--linie-fein);padding-top:14px">
    <input type="hidden" name="tun" value="erinnern">
    <span class="muted">Erinnere mich</span>
    <select name="tage" style="width:auto">
      <option value="1">morgen</option>
      <option value="3" selected>in 3 Tagen</option>
      <option value="7">in einer Woche</option>
      <option value="14">in zwei Wochen</option>
      <option value="30">in einem Monat</option>
      <option value="90">in drei Monaten</option>
    </select>
    <button class="btn btn-ghost" type="submit">Setzen</button>
  </form>
</div></div>

<?php if (ki_bereit()): $ant = $_SESSION['antwort'] ?? null; unset($_SESSION['antwort']); ?>
<div class="karte" id="antwort"><div class="rumpf">
  <h2 style="margin-top:0">Antwort vorschlagen</h2>
  <?php if ($ant && !$ant['ok']): ?>
    <div class="hinweis warn"><?= h((string)$ant['fehler']) ?></div>
  <?php endif; ?>
  <?php if ($ant && $ant['ok']): ?>
    <p class="muted" style="margin:0 0 10px">Entwurf – lies drüber, ändere ihn, kopiere ihn. Verschickt wird hier nichts.</p>
    <form method="post">
      <input type="hidden" name="tun" value="antwort_verlauf">
      <div class="bx-field"><textarea name="text" style="min-height:190px"><?= h((string)$ant['text']) ?></textarea></div>
      <button class="btn btn-ghost" type="submit">Als gesendet im Verlauf vermerken</button>
    </form>
  <?php else: ?>
    <p class="muted" style="margin:0 0 10px">Schreibt aus dem Verlauf einen kurzen Entwurf.</p>
    <form method="post"><input type="hidden" name="tun" value="antwort">
      <button class="btn btn-ghost" type="submit">Entwurf schreiben</button></form>
  <?php endif; ?>
</div></div>
<?php endif; ?>

<?php if ($verlauf): ?>
<div class="karte">
  <div class="rumpf" style="padding-bottom:0"><h2 style="margin-top:0">Verlauf</h2></div>
  <?php foreach ($verlauf as $v): ?>
    <div class="crm-zeile">
      <div class="crm-alter ruhig"><?= h(fmt_zeit((string)$v['angelegt'], 'd.m.')) ?>
        <span class="art"><?= h(crm_verlauf_typen()[$v['typ']] ?? (string)$v['typ']) ?></span></div>
      <div class="crm-mitte">
        <span class="titel" style="font-weight:400"><?= nl2br(h((string)$v['text'])) ?></span>
        <?php if (($v['wer'] ?? '') !== ''): ?><span class="unter"><?= h((string)$v['wer']) ?></span><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
  <div class="karte"><div class="crm-leer"><strong>Noch nichts notiert.</strong>Die erste Notiz legst du oben an.</div></div>
<?php endif; ?>

<div class="karte"><div class="rumpf">
  <h2 style="margin-top:0">Kontakt</h2>
  <div class="muted">
    <?php foreach ([['E-Mail', $k['email'] ?? ''], ['Telefon', $k['telefon'] ?? ''],
                    ['Ort', trim(((string)($k['plz'] ?? '')) . ' ' . ((string)($k['ort'] ?? '')))]] as [$l, $v]):
      if (trim((string)$v) === '') continue; ?>
      <div><?= h($l) ?>: <?= h((string)$v) ?></div>
    <?php endforeach; ?>
  </div>
</div></div>
<?php fuss('mehr');
