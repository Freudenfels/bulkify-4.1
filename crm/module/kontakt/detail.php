<?php
// Ein Kontakt: Stammdaten, Verlauf, Wiedervorlage, und der Weg zum Kunden. Route: ?p=kontakt&id=
require_once BX_ROOT . '/core/kontakt.php';
require_once BX_ROOT . '/core/antwort_ki.php';

$id = (int)($_GET['id'] ?? 0);
$k  = kontakt($id);
if (!$k) { kopf('Kontakt'); seitenkopf('Nicht gefunden'); hinweis('Diesen Kontakt gibt es nicht (mehr).', 'warn'); fuss('kontakte'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tun = (string)($_POST['tun'] ?? '');
    if ($tun === 'speichern') {
        kontakt_speichern($id, $_POST);
        header('Location: ?p=kontakt&id=' . $id . '&ok=gespeichert'); exit;
    }
    if ($tun === 'verlauf') {
        kontakt_verlauf($id, (string)($_POST['typ'] ?? 'notiz'), (string)($_POST['text'] ?? ''), crm_uid());
        header('Location: ?p=kontakt&id=' . $id . '&ok=notiert'); exit;
    }
    if ($tun === 'erinnern') {
        $tage = max(0, min(365, (int)($_POST['tage'] ?? 3)));
        kontakt_wiedervorlage($id, 'Nachfassen: ' . $k['name'], $tage, crm_uid(), (string)($_POST['notiz'] ?? ''));
        header('Location: ?p=kontakt&id=' . $id . '&ok=erinnert'); exit;
    }
    if ($tun === 'antwort') {
        // Entwurf erzeugen und im Formular stehen lassen - verschickt wird nichts.
        $_SESSION['antwort'] = antwort_ki_entwurf(
            trim(((string)($k['firma'] ?? '') !== '' ? $k['firma'] . ' – ' : '') . $k['name']),
            (string)($k['notiz'] ?? ''),
            kontakt_verlauf_liste($id),
            (string)(crm_benutzer()['name'] ?? 'bulkify'));
        header('Location: ?p=kontakt&id=' . $id . '#antwort'); exit;
    }
    if ($tun === 'antwort_verlauf') {
        kontakt_verlauf($id, 'notiz', "Antwort gesendet:\n" . (string)($_POST['text'] ?? ''), crm_uid());
        unset($_SESSION['antwort']);
        header('Location: ?p=kontakt&id=' . $id . '&ok=notiert'); exit;
    }
    if ($tun === 'kunde') {
        $kid = kontakt_zu_kunde($id, crm_uid());
        header('Location: ?p=kontakt&id=' . $id . ($kid ? '&ok=kunde' : '&ok=fehler')); exit;
    }
    if ($tun === 'archiv') {
        q("UPDATE crm_kontakt SET archiviert = 1 - archiviert, aktualisiert=? WHERE id=?", [gmdate('Y-m-d H:i:s'), $id]);
        header('Location: ?p=kontakt&id=' . $id); exit;
    }
}

$verlauf = kontakt_verlauf_liste($id);
$wv      = kontakt_wiedervorlagen($id);
$dash    = erp_dashboard_url();

kopf($k['name'], 'kontakte');
seitenkopf(trim(((string)($k['firma'] ?? '') !== '' ? $k['firma'] . ' – ' : '') . $k['name']),
    (crm_quellen()[$k['quelle']] ?? '') . ' · seit ' . fmt_zeit((string)$k['angelegt'], 'd.m.Y'),
    '<a class="btn" href="?p=kontakte">Zur Liste</a>');

$m = (string)($_GET['ok'] ?? '');
if ($m === 'kunde')       hinweis('Als Kunde im Dashboard angelegt.');
elseif ($m === 'fehler')  hinweis('Der Kunde konnte nicht angelegt werden.', 'warn');
elseif ($m !== '')        hinweis(['gespeichert' => 'Gespeichert.', 'notiert' => 'Notiert.',
                                   'erinnert' => 'Wiedervorlage gesetzt.', '1' => 'Kontakt angelegt.'][$m] ?? 'Erledigt.');
?>

<?php if ($wv): ?>
  <div class="karte"><div class="rumpf">
    <h2 style="margin-top:0">Wiedervorlage</h2>
    <?php foreach ($wv as $w): ?>
      <div class="leise"><?= h(fmt_zeit($w['faellig'] . ' 00:00:00', 'd.m.Y')) ?> · <?= h((string)$w['titel']) ?></div>
    <?php endforeach; ?>
  </div></div>
<?php endif; ?>

<div class="karte"><div class="rumpf">
  <h2 style="margin-top:0">Notiz hinzufügen</h2>
  <form method="post">
    <input type="hidden" name="tun" value="verlauf">
    <div class="feld">
      <textarea name="text" required placeholder="Was war? z. B. „angerufen, will Muster bis KW 38“"></textarea>
    </div>
    <div class="zweispaltig">
      <div class="feld">
        <label for="typ">Art</label>
        <select id="typ" name="typ">
          <?php foreach (crm_verlauf_typen() as $tk => $tv): ?><option value="<?= h($tk) ?>"><?= h($tv) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="feld" style="display:flex;align-items:flex-end">
        <button class="btn stark" type="submit" style="width:100%;justify-content:center">Speichern</button>
      </div>
    </div>
  </form>

  <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;border-top:1px solid var(--linie-fein);padding-top:14px">
    <input type="hidden" name="tun" value="erinnern">
    <span class="leise">Erinnere mich</span>
    <select name="tage" style="width:auto">
      <option value="1">morgen</option>
      <option value="3" selected>in 3 Tagen</option>
      <option value="7">in einer Woche</option>
      <option value="14">in zwei Wochen</option>
      <option value="30">in einem Monat</option>
    </select>
    <button class="btn" type="submit">Setzen</button>
  </form>
</div></div>

<?php if (ki_bereit()): $ant = $_SESSION['antwort'] ?? null; unset($_SESSION['antwort']); ?>
<div class="karte" id="antwort"><div class="rumpf">
  <h2 style="margin-top:0">Antwort vorschlagen</h2>
  <?php if ($ant && !$ant['ok']): ?>
    <div class="hinweis warn"><?= h((string)$ant['fehler']) ?></div>
  <?php endif; ?>
  <?php if ($ant && $ant['ok']): ?>
    <p class="leise" style="margin:0 0 10px">Entwurf – lies drüber, ändere ihn, kopiere ihn. Verschickt wird hier nichts.</p>
    <form method="post">
      <input type="hidden" name="tun" value="antwort_verlauf">
      <div class="feld"><textarea name="text" style="min-height:190px"><?= h((string)$ant['text']) ?></textarea></div>
      <button class="btn" type="submit">Als gesendet im Verlauf vermerken</button>
    </form>
  <?php else: ?>
    <p class="leise" style="margin:0 0 10px">Schreibt aus Notiz und Verlauf einen kurzen Entwurf – in der Sprache des Anliegens.</p>
    <form method="post"><input type="hidden" name="tun" value="antwort">
      <button class="btn" type="submit">Entwurf schreiben</button></form>
  <?php endif; ?>
</div></div>
<?php endif; ?>

<?php if ($verlauf): ?>
<div class="karte">
  <div class="rumpf" style="padding-bottom:0"><h2 style="margin-top:0">Verlauf</h2></div>
  <?php foreach ($verlauf as $v): ?>
    <div class="zeile">
      <div class="alter ruhig"><?= h(fmt_zeit((string)$v['angelegt'], 'd.m.')) ?>
        <span class="art"><?= h(crm_verlauf_typen()[$v['typ']] ?? (string)$v['typ']) ?></span></div>
      <div class="mitte">
        <span class="titel" style="font-weight:400"><?= nl2br(h((string)$v['text'])) ?></span>
        <?php if (($v['wer'] ?? '') !== ''): ?><span class="unter"><?= h((string)$v['wer']) ?></span><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="karte"><div class="rumpf">
  <h2 style="margin-top:0">Daten</h2>
  <form method="post">
    <input type="hidden" name="tun" value="speichern">
    <div class="feld"><label for="name">Name</label>
      <input type="text" id="name" name="name" value="<?= h((string)$k['name']) ?>" required></div>
    <div class="zweispaltig">
      <div class="feld"><label for="firma">Firma</label>
        <input type="text" id="firma" name="firma" value="<?= h((string)($k['firma'] ?? '')) ?>"></div>
      <div class="feld"><label for="phase">Phase</label>
        <select id="phase" name="phase">
          <?php foreach (crm_phasen() as $pk => $pv): ?>
            <option value="<?= h($pk) ?>" <?= $k['phase'] === $pk ? 'selected' : '' ?>><?= h($pv) ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <div class="zweispaltig">
      <div class="feld"><label for="telefon">Telefon</label>
        <input type="tel" id="telefon" name="telefon" value="<?= h((string)($k['telefon'] ?? '')) ?>"></div>
      <div class="feld"><label for="email">E-Mail</label>
        <input type="email" id="email" name="email" value="<?= h((string)($k['email'] ?? '')) ?>"></div>
    </div>
    <div class="zweispaltig">
      <div class="feld"><label for="quelle">Woher</label>
        <select id="quelle" name="quelle">
          <?php foreach (crm_quellen() as $qk => $qv): ?>
            <option value="<?= h($qk) ?>" <?= $k['quelle'] === $qk ? 'selected' : '' ?>><?= h($qv) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="feld"><label for="wert_eur">Geschätzter Wert</label>
        <input type="text" id="wert_eur" name="wert_eur" inputmode="decimal"
               value="<?= $k['wert_eur'] !== null ? h(number_format((float)$k['wert_eur'], 2, ',', '.')) : '' ?>"></div>
    </div>
    <div class="feld"><label for="notiz">Notiz</label>
      <textarea id="notiz" name="notiz"><?= h((string)($k['notiz'] ?? '')) ?></textarea></div>
    <button class="btn stark" type="submit">Speichern</button>
  </form>
</div></div>

<div class="karte"><div class="rumpf">
  <?php if ($k['kunde_id']): ?>
    <p style="margin:0">Ist im Dashboard als Kunde angelegt.
      <?php if ($dash !== ''): ?>
        <a href="<?= h($dash . '/?p=kunde&id=' . (int)$k['kunde_id']) ?>" target="_blank" rel="noopener">Dort öffnen</a>
      <?php endif; ?>
    </p>
  <?php else: ?>
    <h2 style="margin-top:0">Wird ein Kunde daraus?</h2>
    <p class="leise" style="margin-bottom:12px">Legt im Dashboard einen Kunden mit diesen Daten an.
       Der Kontakt bleibt hier bestehen, damit der Verlauf nicht verloren geht.</p>
    <form method="post" onsubmit="return confirm('Im Dashboard einen Kunden anlegen?');">
      <input type="hidden" name="tun" value="kunde">
      <button class="btn stark" type="submit">Zum Kunden machen</button>
    </form>
  <?php endif; ?>
</div></div>

<form method="post" style="margin-bottom:24px">
  <input type="hidden" name="tun" value="archiv">
  <button class="btn leise klein" type="submit"><?= $k['archiviert'] ? 'Aus dem Archiv holen' : 'Archivieren' ?></button>
</form>
<?php fuss('kontakte');
