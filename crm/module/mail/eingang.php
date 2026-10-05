<?php
// E-Mail-Eingang: automatisch abgeholte Mails als Liste mit KI-Vorschau. Route: ?p=eingang
// Ein Klick auf "Anlegen" macht daraus Kontakt/Notiz/Wiedervorlage (bzw. haengt es an einen
// bestehenden Kunden). "Verwerfen" legt die Mail still weg. Abgeholt wird per Knopf oder per Cron.
require_once BX_ROOT . '/core/mail_abruf.php';

$fehler = ''; $info = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tun = (string)($_POST['tun'] ?? '');
    if ($tun === 'abrufen') {
        $r = mail_abholen();
        if (!$r['ok']) $fehler = $r['fehler'];
        else $info = $r['anzahl'] > 0 ? ($r['anzahl'] . ($r['anzahl'] === 1 ? ' neue Mail abgeholt.' : ' neue Mails abgeholt.')) : 'Keine neuen Mails.';
        // Per Redirect, damit ein Neuladen nicht erneut abruft.
        $_SESSION['eingang_info'] = $info; $_SESSION['eingang_fehler'] = $fehler;
        header('Location: ?p=eingang'); exit;
    }
    if ($tun === 'anlegen') {
        $r = mail_eingang_anlegen((int)($_POST['id'] ?? 0), crm_uid());
        if ($r['ok']) { header('Location: ?p=' . ($r['ziel'] === 'kunde' ? 'kunde' : 'kontakt') . '&id=' . (int)$r['id'] . '&ok=1'); exit; }
        $_SESSION['eingang_fehler'] = $r['fehler']; header('Location: ?p=eingang'); exit;
    }
    if ($tun === 'verwerfen') {
        mail_eingang_verwerfen((int)($_POST['id'] ?? 0));
        header('Location: ?p=eingang'); exit;
    }
}

$info   = (string)($_SESSION['eingang_info'] ?? ''); unset($_SESSION['eingang_info']);
$fehler = (string)($_SESSION['eingang_fehler'] ?? ''); unset($_SESSION['eingang_fehler']);

$liste  = mail_eingang_liste('neu');
$letzter = mail_abruf_letzter();
$arten  = mail_ki_arten();

$abrufbtn = mail_abruf_bereit()
    ? '<form method="post" style="margin:0"><input type="hidden" name="tun" value="abrufen"><button class="btn btn-primary" type="submit" data-busy="Postfach wird abgerufen …">Postfach abrufen</button></form>'
    : '';

kopf('E-Mail-Eingang', 'eingang');
seitenkopf('E-Mail-Eingang',
    (count($liste) ? count($liste) . ' offen' : 'keine offenen') . ($letzter !== '' ? ' · zuletzt abgerufen ' . fmt_zeit($letzter, 'd.m. H:i') : ''),
    $abrufbtn);

if ($info !== '')   hinweis($info);
if ($fehler !== '') hinweis($fehler, 'warn');

if (!mail_abruf_moeglich()) {
    hinweis('Die PHP-IMAP-Erweiterung ist auf diesem Server nicht aktiv – ohne sie kann das Postfach nicht abgerufen werden. Bitte aktivieren (oder Bescheid geben, dann wird ein alternativer Abruf gebaut).', 'warn');
} elseif (!mail_imap_konfig()['vollstaendig']) {
    hinweis('Der IMAP-Zugang ist noch nicht eingetragen. Unter „Mehr“ → E-Mail-Eingang Host, Benutzer und Passwort hinterlegen.', 'warn');
}
?>

<?php if (!$liste): ?>
  <div class="karte"><div class="crm-leer">
    <strong>Keine offenen Mails.</strong>
    <?= mail_abruf_bereit() ? 'Mit „Postfach abrufen“ holst du die neuen Nachrichten.' : '' ?>
  </div></div>
<?php else: foreach ($liste as $row): $pp = mail_eingang_plan($row); $d = $pp['daten']; $plan = $pp['plan'];
    $kein = ($plan['ziel_art'] === 'nichts'); ?>
  <div class="karte"><div class="rumpf">
    <div class="bx-row" style="justify-content:space-between;align-items:baseline;gap:10px;flex-wrap:wrap">
      <h2 style="margin:0;font-size:1.05rem"><?= h($arten[$row['art']] ?? 'Mail') ?></h2>
      <span class="muted" style="font-size:var(--fs-sm)"><?= h(fmt_zeit((string)($row['datum'] ?? $row['angelegt']), 'd.m.Y H:i')) ?></span>
    </div>
    <p class="muted" style="margin:6px 0 0">
      <?= h(trim(((string)($row['von_name'] ?? '')) . (($row['von_email'] ?? '') !== '' ? ' <' . $row['von_email'] . '>' : ''))) ?>
    </p>
    <?php if (($row['betreff'] ?? '') !== ''): ?><p style="margin:8px 0 2px"><strong><?= h((string)$row['betreff']) ?></strong></p><?php endif; ?>
    <p class="muted" style="margin:4px 0 0"><?= nl2br(h((string)($d['zusammenfassung'] ?? $d['wunsch'] ?? ''))) ?></p>

    <div class="muted" style="margin:10px 0 0;font-size:var(--fs-sm)">
      <?php if ($kein): ?>
        Kein Vorgang nötig (<?= h($arten[$row['art']] ?? 'Sonstiges') ?>).
      <?php else: ?>
        Zuordnung: <strong><?= h($plan['ziel_text'] ?: 'Neuer Kontakt') ?></strong>
        <?= $plan['ziel_art'] === 'neu' ? ' (neu anlegen)' : ' (' . ($plan['ziel_art'] === 'kunde' ? 'Kunde' : 'Kontakt') . ')' ?>
        <?= $plan['grund'] !== '' ? ' · gefunden über ' . h($plan['grund']) : '' ?>
        <?= (int)$plan['tage'] > 0 ? ' · Wiedervorlage in ' . (int)$plan['tage'] . ' Tg' : '' ?>
      <?php endif; ?>
    </div>

    <div class="bx-row" style="gap:8px;margin-top:12px">
      <?php if (!$kein): ?>
        <form method="post" style="margin:0"><input type="hidden" name="tun" value="anlegen"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
          <button class="btn btn-primary btn-sm" type="submit"><?= $plan['ziel_art'] === 'neu' ? 'Als Kontakt anlegen' : 'Dem ' . ($plan['ziel_art'] === 'kunde' ? 'Kunden' : 'Kontakt') . ' zuordnen' ?></button></form>
      <?php endif; ?>
      <form method="post" style="margin:0"><input type="hidden" name="tun" value="verwerfen"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
        <button class="btn btn-ghost btn-sm" type="submit">Verwerfen</button></form>
    </div>

    <details style="margin-top:10px"><summary class="muted" style="cursor:pointer;font-size:var(--fs-sm)">Mail ansehen</summary>
      <p class="muted" style="white-space:pre-wrap;font-size:var(--fs-sm);margin-top:8px"><?= h(mb_substr((string)($row['body'] ?? ''), 0, 4000)) ?></p>
    </details>
  </div></div>
<?php endforeach; endif; ?>
<?php fuss('eingang');
