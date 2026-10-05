<?php
// Ein Kontakt: Stammdaten, Verlauf, Wiedervorlage, und der Weg zum Kunden. Route: ?p=kontakt&id=
require_once BX_ROOT . '/core/kontakt.php';
require_once BX_ROOT . '/core/antwort_ki.php';
require_once BX_ROOT . '/core/fragenkatalog_ki.php';
require_once BX_ROOT . '/core/lead_ki.php';
require_once BX_ROOT . '/core/rezeptur_ki.php';
require_once BX_ROOT . '/core/mail_senden.php';
require_once BX_ROOT . '/core/todo.php';
require_once BX_ROOT . '/core/markdown.php';

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
    if ($tun === 'todo_add') {
        todo_anlegen(['titel' => (string)($_POST['titel'] ?? ''), 'kategorie' => (string)($_POST['kategorie'] ?? 'aufgabe'),
                      'faellig' => (string)($_POST['faellig'] ?? ''), 'bezug_typ' => 'kontakt', 'bezug_id' => $id], crm_uid());
        header('Location: ?p=kontakt&id=' . $id . '&ok=todo'); exit;
    }
    if ($tun === 'todo_erledigt') {
        todo_erledigen('todo', (int)($_POST['tid'] ?? 0), crm_uid());
        header('Location: ?p=kontakt&id=' . $id); exit;
    }
    if ($tun === 'fragenkatalog') {
        $r = fragenkatalog_erzeugen(trim(((string)($k['firma'] ?? '') !== '' ? $k['firma'] . ' – ' : '') . $k['name']), ['Anliegen' => (string)($k['notiz'] ?? ''), 'Quelle' => crm_quellen()[$k['quelle']] ?? '',
             'Geschätzter Wert' => $k['wert_eur'] !== null ? eur((float)$k['wert_eur']) : '',
             'Telefon' => (string)($k['telefon'] ?? ''), 'E-Mail' => (string)($k['email'] ?? '')], implode("\n", array_map(fn($v) => fmt_zeit((string)$v['angelegt'], 'd.m.Y') . ': ' . $v['text'], kontakt_verlauf_liste($id))));
        if ($r['ok']) fragenkatalog_merken('kontakt', $id, $r['text']);
        header('Location: ?p=kontakt&id=' . $id . ($r['ok'] ? '#fragenkatalog' : '&ok=kifehler')); exit;
    }
    if ($tun === 'fragenkatalog_weg') {
        fragenkatalog_loeschen('kontakt', $id);
        header('Location: ?p=kontakt&id=' . $id); exit;
    }
    if ($tun === 'rezeptvorschlag') {
        // Anfrage-Text aus den strukturierten Feldern + Notiz zusammenbauen.
        $teile = [];
        foreach ([['anfrage_rezeptur', 'Produkt/Rezeptur'], ['anfrage_form', 'Form'], ['anfrage_inhalt', 'Menge'], ['anfrage_vorhaben', 'Vorhaben']] as $af) {
            if (trim((string)($k[$af[0]] ?? '')) !== '') $teile[] = $af[1] . ': ' . $k[$af[0]];
        }
        if (trim((string)($k['notiz'] ?? '')) !== '') $teile[] = "\n" . $k['notiz'];
        $text = trim(implode("\n", $teile));
        $form = (string)($_POST['form'] ?? 'kapsel');
        $r = rezeptur_ki_entwickeln($text, $form);
        if (!empty($r['ok'])) rezeptur_ki_merken('kontakt', $id, $r);
        header('Location: ?p=kontakt&id=' . $id . ($r['ok'] ? '#rezeptvorschlag' : '&ok=kifehler')); exit;
    }
    if ($tun === 'rezeptvorschlag_weg') {
        rezeptur_ki_loeschen('kontakt', $id);
        header('Location: ?p=kontakt&id=' . $id); exit;
    }
    if ($tun === 'ki_auswerten') {
        $r = lead_ki_auswerten($id, crm_uid());
        header('Location: ?p=kontakt&id=' . $id . ($r['ok'] ? '&ok=ausgewertet' : '&ok=kifehler')); exit;
    }
    if ($tun === 'angebot') {
        // Angebot erstellen: erst Kunde sicherstellen, dann in den Angebots-Flow des Dashboards springen.
        $kid = (int)($k['kunde_id'] ?? 0);
        if ($kid <= 0) $kid = kontakt_zu_kunde($id, crm_uid());
        if ($kid > 0) {
            kontakt_verlauf($id, 'angebot', 'Angebot im Dashboard angestoßen.', crm_uid());
            header('Location: ' . erp_dashboard_link('angebot&id=neu&kunde_id=' . $kid)); exit;
        }
        header('Location: ?p=kontakt&id=' . $id . '&ok=fehler'); exit;
    }
    if ($tun === 'rezept') {
        // Rezeptur anlegen: Kunde sicherstellen, dann in den Rezeptur-Flow des Dashboards springen.
        $kid = (int)($k['kunde_id'] ?? 0);
        if ($kid <= 0) $kid = kontakt_zu_kunde($id, crm_uid());
        kontakt_verlauf($id, 'notiz', 'Rezeptur im Dashboard angestoßen.', crm_uid());
        header('Location: ' . erp_dashboard_link('rezeptur_detail&id=neu')); exit;
    }
    if ($tun === 'datei_upload') {
        $r = kontakt_datei_speichern($id, $_FILES['datei'] ?? [], (string)($_POST['kategorie'] ?? 'sonstiges'), crm_uid());
        header('Location: ?p=kontakt&id=' . $id . ($r['ok'] ? '&ok=hochgeladen' : '&ok=uploadfehler')); exit;
    }
    if ($tun === 'datei_del') {
        kontakt_datei_loeschen((int)($_POST['did'] ?? 0), $id);
        header('Location: ?p=kontakt&id=' . $id . '&ok=geloescht'); exit;
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
    if ($tun === 'antwort_senden') {
        $text = (string)($_POST['text'] ?? '');
        $betreff = trim((string)($_POST['betreff'] ?? '')) ?: 'Ihre Anfrage bei bulkify';
        $fehler = crm_mail_senden(crm_uid(), (string)($k['email'] ?? ''), $betreff, $text);
        if ($fehler === '') {
            kontakt_verlauf($id, 'mail', "Antwort gesendet an " . (string)($k['email'] ?? '') . " (Betreff: " . $betreff . "):\n" . $text, crm_uid());
            unset($_SESSION['antwort']);
            header('Location: ?p=kontakt&id=' . $id . '&ok=gesendet'); exit;
        }
        $_SESSION['sendefehler'] = $fehler;
        header('Location: ?p=kontakt&id=' . $id . '#antwort'); exit;
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
$dateien     = kontakt_dateien($id);
$mitarbeiter = erp_mitarbeiter();
$kundeId     = (int)($k['kunde_id'] ?? 0);
$angebote    = $kundeId > 0 ? erp_angebote_fuer_kunde($kundeId) : [];
$rezepturen  = $kundeId > 0 ? erp_rezepturen_fuer_kunde($kundeId) : [];
$hatAnfrage  = trim((string)($k['anfrage_rezeptur'] ?? '') . ($k['anfrage_form'] ?? '') . ($k['anfrage_inhalt'] ?? '') . ($k['anfrage_vorhaben'] ?? '')) !== '';
$segFilled   = false;
foreach (array_keys(crm_segfelder()) as $sf) { if (trim((string)($k[$sf] ?? '')) !== '') { $segFilled = true; break; } }
foreach (['land', 'website', 'moeglichkeiten', 'besonderheiten'] as $sf) { if (trim((string)($k[$sf] ?? '')) !== '') { $segFilled = true; break; } }

kopf($k['name'], 'kontakte');
seitenkopf(trim(((string)($k['firma'] ?? '') !== '' ? $k['firma'] . ' – ' : '') . $k['name']),
    (crm_quellen()[$k['quelle']] ?? '') . ' · seit ' . fmt_zeit((string)$k['angelegt'], 'd.m.Y'),
    '<a class="btn btn-ghost" href="?p=kontakte">Zur Liste</a>');

$m = (string)($_GET['ok'] ?? '');
if ($m === 'kunde')       hinweis('Als Kunde im Dashboard angelegt.');
elseif ($m === 'fehler')  hinweis('Der Kunde konnte nicht angelegt werden.', 'warn');
elseif ($m === 'kifehler') hinweis('Die KI-Auswertung hat nicht geklappt. Bitte später erneut versuchen.', 'warn');
elseif ($m === 'uploadfehler') hinweis('Der Upload hat nicht geklappt.', 'warn');
elseif ($m !== '')        hinweis(['gespeichert' => 'Gespeichert.', 'notiert' => 'Notiert.',
                                   'erinnert' => 'Wiedervorlage gesetzt.', 'todo' => 'To-Do angelegt.', 'ausgewertet' => 'Anfrage ausgewertet – siehe Verlauf.',
                                   'hochgeladen' => 'Dokument hochgeladen.', 'geloescht' => 'Dokument gelöscht.',
                                   'gesendet' => 'Antwort gesendet.',
                                   '1' => 'Kontakt angelegt.'][$m] ?? 'Erledigt.');
$sendefehler = (string)($_SESSION['sendefehler'] ?? ''); unset($_SESSION['sendefehler']);
if ($sendefehler !== '') hinweis('Senden fehlgeschlagen: ' . $sendefehler, 'warn');
?>

<?php if ($wv): ?>
  <div class="karte"><div class="rumpf">
    <h2 style="margin-top:0">Wiedervorlage</h2>
    <?php foreach ($wv as $w): ?>
      <div class="muted"><?= h(fmt_zeit($w['faellig'] . ' 00:00:00', 'd.m.Y')) ?> · <?= h((string)$w['titel']) ?></div>
    <?php endforeach; ?>
  </div></div>
<?php endif; ?>

<?php $todos = todo_fuer_bezug('kontakt', $id); ?>
<div class="karte"><div class="rumpf">
  <h2 style="margin-top:0">To-Dos</h2>
  <?php foreach ($todos as $t): ?>
    <div class="crm-zeile" style="align-items:center">
      <div class="crm-mitte"><span class="titel" style="font-weight:400"><?= h((string)$t['titel']) ?></span>
        <span class="unter"><span class="crm-tag" style="display:inline-block"><?= h(crm_todo_kategorie_label((string)$t['kategorie'])) ?></span><?= $t['faellig'] ? ' · fällig ' . h(fmt_zeit($t['faellig'] . ' 00:00:00', 'd.m.Y')) : '' ?></span>
      </div>
      <form method="post" style="margin:0"><input type="hidden" name="tun" value="todo_erledigt"><input type="hidden" name="tid" value="<?= (int)$t['id'] ?>">
        <button class="btn btn-primary btn-sm" type="submit">Erledigt</button></form>
    </div>
  <?php endforeach; ?>
  <form method="post" style="margin-top:<?= $todos ? '12px' : '0' ?>">
    <input type="hidden" name="tun" value="todo_add">
    <div class="bx-field"><input type="text" name="titel" required placeholder="Aufgabe, z. B. „Muster schicken“"></div>
    <div class="bx-grid">
      <div class="bx-field"><label for="tkat">Kategorie</label>
        <select id="tkat" name="kategorie"><?php foreach (crm_todo_kategorien() as $kk => $kv): ?><option value="<?= h($kk) ?>"><?= h($kv) ?></option><?php endforeach; ?></select></div>
      <div class="bx-field"><label for="tfaellig">Fällig (optional)</label><input type="date" id="tfaellig" name="faellig"></div>
    </div>
    <button class="btn btn-ghost btn-sm" type="submit">To-Do hinzufügen</button>
  </form>
</div></div>

<div class="karte"><div class="rumpf">
  <h2 style="margin-top:0">Notiz hinzufügen</h2>
  <form method="post">
    <input type="hidden" name="tun" value="verlauf">
    <div class="bx-field">
      <textarea name="text" required placeholder="Was war? z. B. „angerufen, will Muster bis KW 38“"></textarea>
    </div>
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
    </select>
    <button class="btn btn-ghost" type="submit">Setzen</button>
  </form>
</div></div>

<?php // --- KI-Auswertung der Anfrage -----------------------------------------------------------
// Website-Leads werden beim Eingang automatisch ausgewertet. Hier kann man es anstossen bzw.
// wiederholen - z. B. nach einer neuen Notiz oder fuer Kontakte, die ohne KI hereinkamen. ?>
<?php if (ki_bereit() && trim((string)($k['notiz'] ?? '')) !== ''): ?>
<div class="karte"><div class="rumpf">
  <div class="bx-row" style="justify-content:space-between;align-items:baseline;gap:12px">
    <h2 style="margin:0">KI-Auswertung der Anfrage</h2>
    <?php if (!empty($k['ki_ausgewertet'])): ?>
      <span class="muted" style="font-size:var(--fs-sm)">zuletzt <?= h(fmt_zeit((string)$k['ki_ausgewertet'], 'd.m.Y H:i')) ?></span>
    <?php endif; ?>
  </div>
  <p class="muted" style="margin:8px 0 12px">Liest die Anfrage aus der Notiz: kurze Zusammenfassung,
    Produktform, Menge, grober Wert und der nächste Schritt. Setzt den geschätzten Wert (falls leer)
    und eine Wiedervorlage. Das Ergebnis steht danach im Verlauf.</p>
  <form method="post" style="margin:0">
    <input type="hidden" name="tun" value="ki_auswerten">
    <button class="btn <?= !empty($k['ki_ausgewertet']) ? 'btn-ghost btn-sm' : 'btn-primary' ?>" type="submit"
            data-busy="Die KI liest die Anfrage …"><?= !empty($k['ki_ausgewertet']) ? 'Neu auswerten' : 'Anfrage auswerten' ?></button>
  </form>
</div></div>
<?php endif; ?>

<?php // --- KI-Rezepturvorschlag (aus der Anfrage) -----------------------------------------------
$rv = rezeptur_ki_vorschlag('kontakt', $id);
$rvBasis = trim((string)($k['notiz'] ?? '') . ($k['anfrage_rezeptur'] ?? '') . ($k['anfrage_vorhaben'] ?? ''));
$rvForm = in_array((string)($k['anfrage_form'] ?? ''), array_keys(rezeptur_ki_formen()), true) ? (string)$k['anfrage_form']
        : (stripos((string)($k['anfrage_form'] ?? ''), 'kaps') !== false ? 'kapsel' : 'kapsel'); ?>
<?php if ($rv || (ki_bereit() && $rvBasis !== '')): ?>
<div class="karte" id="rezeptvorschlag"><div class="rumpf">
  <div class="bx-row" style="justify-content:space-between;align-items:baseline;gap:12px">
    <h2 style="margin:0">KI-Rezepturvorschlag</h2>
    <?php if ($rv && !empty($rv['_stand'])): ?><span class="muted" style="font-size:var(--fs-sm)">erstellt <?= h(fmt_zeit((string)$rv['_stand'], 'd.m.Y H:i')) ?></span><?php endif; ?>
  </div>
  <?php if (!$rv): ?>
    <p class="muted" style="margin:8px 0 12px">Entwickelt aus der Anfrage einen herstellbaren Vorschlag:
      Zutaten mit Mengen, Novel-Food- und Höchstmengen-Einschätzung, Health Claims, Machbarkeit und die
      passende Kapselgröße. <strong>Entwurf fürs Team – keine Freigabe.</strong></p>
  <?php endif; ?>
  <?php if (ki_bereit()): ?>
  <form method="post" style="margin:0 0 <?= $rv ? '14px' : '0' ?>">
    <input type="hidden" name="tun" value="rezeptvorschlag">
    <div class="bx-row" style="gap:8px;align-items:flex-end;flex-wrap:wrap">
      <div class="bx-field" style="margin:0;min-width:160px">
        <label for="rvform">Darreichungsform</label>
        <select id="rvform" name="form">
          <?php foreach (rezeptur_ki_formen() as $fk2 => $fl): ?><option value="<?= h($fk2) ?>" <?= $fk2 === $rvForm ? 'selected' : '' ?>><?= h($fl) ?></option><?php endforeach; ?>
        </select>
      </div>
      <button class="btn <?= $rv ? 'btn-ghost btn-sm' : 'btn-primary' ?>" type="submit"
              data-busy="Die KI entwickelt eine Rezeptur …"><?= $rv ? 'Neu entwickeln' : 'Rezepturvorschlag erstellen' ?></button>
      <span class="muted" style="font-size:var(--fs-sm)">Dauert etwa eine Minute.</span>
    </div>
  </form>
  <?php endif; ?>
  <?php if ($rv): ?>
    <div class="crm-md"><?= rezeptur_ki_html($rv) ?></div>
    <form method="post" style="margin-top:12px"><input type="hidden" name="tun" value="rezeptvorschlag_weg">
      <button class="btn btn-ghost btn-sm" type="submit">Verwerfen</button></form>
  <?php endif; ?>
</div></div>
<?php endif; ?>

<?php // --- Fragenkatalog fuers Erstgespraech (aus dem v3-CRM uebernommen) ---
$fk = fragenkatalog('kontakt', $id); ?>
<div class="karte" id="fragenkatalog"><div class="rumpf">
  <div class="bx-row" style="justify-content:space-between;align-items:baseline;gap:12px">
    <h2 style="margin:0">Fragenkatalog fürs Erstgespräch</h2>
    <?php if ($fk): ?>
      <span class="muted" style="font-size:var(--fs-sm)">erstellt <?= h(fmt_zeit((string)$fk['stand'], 'd.m.Y H:i')) ?></span>
    <?php endif; ?>
  </div>

  <?php if (!$fk): ?>
    <p class="muted" style="margin:8px 0 12px">Bereitet den Erstkontakt vor: Kurzbriefing, der fachliche
      Knackpunkt mit Zahlen, die Fragen an den Kunden, was wir ihm aktiv sagen müssen, und die Hausaufgaben
      auf beiden Seiten. <strong>Ein Entwurf für dich – keine Nachricht an den Kunden.</strong></p>
  <?php endif; ?>

  <?php if (ki_bereit()): ?>
    <form method="post" style="margin:0 0 <?= $fk ? '14px' : '0' ?>">
      <input type="hidden" name="tun" value="fragenkatalog">
      <button class="btn <?= $fk ? 'btn-ghost btn-sm' : 'btn-primary' ?>" type="submit"
              data-busy="Der Kollege denkt nach …"><?= $fk ? 'Neu erstellen' : 'Gesprächsvorbereitung erstellen' ?></button>
      <span class="muted" style="margin-left:10px;font-size:var(--fs-sm)">Dauert etwa eine Minute.</span>
    </form>
  <?php else: ?>
    <p class="muted" style="margin:0">Die KI ist hier nicht eingerichtet.</p>
  <?php endif; ?>

  <?php if ($fk): ?>
    <div class="crm-md"><?= md_zu_html((string)$fk['inhalt']) ?></div>
    <form method="post" style="margin-top:12px">
      <input type="hidden" name="tun" value="fragenkatalog_weg">
      <button class="btn btn-ghost btn-sm" type="submit">Verwerfen</button>
    </form>
  <?php endif; ?>
</div></div>

<?php if (ki_bereit()): $ant = $_SESSION['antwort'] ?? null; unset($_SESSION['antwort']); ?>
<div class="karte" id="antwort"><div class="rumpf">
  <h2 style="margin-top:0">Antwort vorschlagen</h2>
  <?php if ($ant && !$ant['ok']): ?>
    <div class="hinweis warn"><?= h((string)$ant['fehler']) ?></div>
  <?php endif; ?>
  <?php if ($ant && $ant['ok']): $mailBereit = mitarbeiter_mail_konfig(crm_uid())['bereit']; $hatEmail = trim((string)($k['email'] ?? '')) !== ''; ?>
    <p class="muted" style="margin:0 0 10px">Entwurf – lies drüber, ändere ihn. „Senden" verschickt ihn unter deinem Mailkonto.</p>
    <form method="post">
      <div class="bx-field"><label for="betreff">Betreff</label>
        <input type="text" id="betreff" name="betreff" value="Ihre Anfrage bei bulkify"></div>
      <div class="bx-field"><textarea name="text" style="min-height:190px"><?= h((string)$ant['text']) ?></textarea></div>
      <div class="bx-row" style="gap:8px;flex-wrap:wrap">
        <?php if ($mailBereit && $hatEmail): ?>
          <button class="btn btn-primary" type="submit" name="tun" value="antwort_senden"
                  onclick="return confirm('Antwort jetzt an <?= h((string)$k['email']) ?> senden?');"
                  data-busy="Wird gesendet …">Senden an <?= h((string)$k['email']) ?></button>
        <?php endif; ?>
        <button class="btn btn-ghost" type="submit" name="tun" value="antwort_verlauf">Als gesendet vermerken</button>
      </div>
      <?php if (!$mailBereit): ?><p class="muted" style="margin:8px 0 0;font-size:var(--fs-sm)">Zum direkten Senden ein Mailkonto unter Einstellungen → Mein Mailkonto hinterlegen.</p>
      <?php elseif (!$hatEmail): ?><p class="muted" style="margin:8px 0 0;font-size:var(--fs-sm)">Dieser Kontakt hat keine E-Mail-Adresse – trag sie unter „Daten" ein, dann kannst du senden.</p><?php endif; ?>
    </form>
  <?php else: ?>
    <p class="muted" style="margin:0 0 10px">Schreibt aus Notiz und Verlauf einen kurzen Entwurf – in der Sprache des Anliegens.</p>
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
<?php endif; ?>

<div class="karte"><div class="rumpf">
  <h2 style="margin-top:0">Daten</h2>
  <form method="post">
    <input type="hidden" name="tun" value="speichern">
    <div class="bx-field"><label for="name">Name</label>
      <input type="text" id="name" name="name" value="<?= h((string)$k['name']) ?>" required></div>
    <div class="bx-grid">
      <div class="bx-field"><label for="firma">Firma</label>
        <input type="text" id="firma" name="firma" value="<?= h((string)($k['firma'] ?? '')) ?>"></div>
      <div class="bx-field"><label for="phase">Phase</label>
        <select id="phase" name="phase">
          <?php foreach (crm_phasen() as $pk => $pv): ?>
            <option value="<?= h($pk) ?>" <?= $k['phase'] === $pk ? 'selected' : '' ?>><?= h($pv) ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <div class="bx-grid">
      <div class="bx-field"><label for="telefon">Telefon</label>
        <input type="tel" id="telefon" name="telefon" value="<?= h((string)($k['telefon'] ?? '')) ?>"></div>
      <div class="bx-field"><label for="email">E-Mail</label>
        <input type="email" id="email" name="email" value="<?= h((string)($k['email'] ?? '')) ?>"></div>
    </div>
    <div class="bx-grid">
      <div class="bx-field"><label for="quelle">Woher</label>
        <select id="quelle" name="quelle">
          <?php foreach (crm_quellen() as $qk => $qv): ?>
            <option value="<?= h($qk) ?>" <?= $k['quelle'] === $qk ? 'selected' : '' ?>><?= h($qv) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="bx-field"><label for="wert_eur">Geschätzter Wert</label>
        <input type="text" id="wert_eur" name="wert_eur" inputmode="decimal"
               value="<?= $k['wert_eur'] !== null ? h(number_format((float)$k['wert_eur'], 2, ',', '.')) : '' ?>"></div>
    </div>
    <div class="bx-grid">
      <div class="bx-field"><label for="besitzer_id">Zuständig</label>
        <select id="besitzer_id" name="besitzer_id">
          <option value="0">– niemand –</option>
          <?php $curB = (int)($k['besitzer_id'] ?? 0); foreach ($mitarbeiter as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= $curB === (int)$u['id'] ? 'selected' : '' ?>><?= h((string)$u['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="bx-field"><label for="land">Land / Region</label>
        <input type="text" id="land" name="land" value="<?= h((string)($k['land'] ?? '')) ?>" placeholder="z. B. Deutschland"></div>
    </div>
    <div class="bx-field"><label for="notiz">Notiz</label>
      <textarea id="notiz" name="notiz"><?= h((string)($k['notiz'] ?? '')) ?></textarea></div>

    <details class="crm-details" <?= $segFilled ? 'open' : '' ?> style="margin:4px 0 10px">
      <summary style="cursor:pointer">Qualifizierung &amp; Segmentierung</summary>
      <div style="margin-top:12px">
        <div class="bx-grid">
          <?php foreach (crm_segfelder() as $f => $def): [$lbl, $opts] = $def; ?>
            <div class="bx-field"><label for="seg_<?= h($f) ?>"><?= h($lbl) ?></label>
              <select id="seg_<?= h($f) ?>" name="<?= h($f) ?>">
                <option value="">– wählen –</option>
                <?php foreach ($opts as $ok => $ol): ?><option value="<?= h($ok) ?>" <?= ($k[$f] ?? '') === $ok ? 'selected' : '' ?>><?= h($ol) ?></option><?php endforeach; ?>
              </select></div>
          <?php endforeach; ?>
          <div class="bx-field"><label for="website">Website / Social</label>
            <input type="text" id="website" name="website" value="<?= h((string)($k['website'] ?? '')) ?>" placeholder="z. B. instagram.com/marke"></div>
        </div>
        <div class="bx-field"><label for="moeglichkeiten">Möglichkeiten / Potenzial</label>
          <input type="text" id="moeglichkeiten" name="moeglichkeiten" value="<?= h((string)($k['moeglichkeiten'] ?? '')) ?>" placeholder="z. B. plant eigene Marke, sucht Hersteller für 3 Produkte"></div>
        <div class="bx-field"><label for="besonderheiten">Besonderheiten</label>
          <input type="text" id="besonderheiten" name="besonderheiten" value="<?= h((string)($k['besonderheiten'] ?? '')) ?>" placeholder="z. B. nur vegan, Bio-Zertifikat wichtig, kleines Budget"></div>
      </div>
    </details>

    <details class="crm-details" <?= $hatAnfrage ? 'open' : '' ?> style="margin:4px 0 10px">
      <summary style="cursor:pointer">Anfrage (strukturiert)</summary>
      <div style="margin-top:12px">
        <div class="bx-field"><label for="anfrage_rezeptur">Rezeptur / Produkt</label>
          <input type="text" id="anfrage_rezeptur" name="anfrage_rezeptur" value="<?= h((string)($k['anfrage_rezeptur'] ?? '')) ?>"></div>
        <div class="bx-grid">
          <div class="bx-field"><label for="anfrage_form">Form</label>
            <input type="text" id="anfrage_form" name="anfrage_form" value="<?= h((string)($k['anfrage_form'] ?? '')) ?>" placeholder="z. B. Kapsel"></div>
          <div class="bx-field"><label for="anfrage_inhalt">Menge / Inhalt</label>
            <input type="text" id="anfrage_inhalt" name="anfrage_inhalt" value="<?= h((string)($k['anfrage_inhalt'] ?? '')) ?>" placeholder="z. B. 5.000 Dosen"></div>
        </div>
        <div class="bx-field"><label for="anfrage_vorhaben">Vorhaben</label>
          <input type="text" id="anfrage_vorhaben" name="anfrage_vorhaben" value="<?= h((string)($k['anfrage_vorhaben'] ?? '')) ?>" placeholder="z. B. bestehende Rezeptur herstellen"></div>
      </div>
    </details>

    <button class="btn btn-primary" type="submit">Speichern</button>
  </form>
</div></div>

<div class="karte"><div class="rumpf">
  <h2 style="margin-top:0">Verkauf</h2>
  <p class="muted" style="margin:0 0 12px">Angebot oder Rezeptur im Dashboard anlegen. Falls noch kein Kundenkonto besteht, wird es zuerst angelegt.</p>
  <div class="bx-row" style="gap:8px;flex-wrap:wrap">
    <form method="post" style="margin:0"><input type="hidden" name="tun" value="angebot">
      <button class="btn btn-primary btn-sm" type="submit">Angebot erstellen</button></form>
    <form method="post" style="margin:0"><input type="hidden" name="tun" value="rezept">
      <button class="btn btn-ghost btn-sm" type="submit">Rezeptur anlegen</button></form>
  </div>
  <?php if ($angebote): ?>
    <h3 style="margin:16px 0 6px;font-size:var(--fs-sm)">Angebote</h3>
    <?php foreach ($angebote as $a): ?>
      <div class="crm-zeile">
        <div class="crm-mitte">
          <?php if ($dash !== ''): ?><a class="titel" href="<?= h($dash . '/?p=angebot&id=' . (int)$a['id']) ?>" target="_blank" rel="noopener"><?= h((string)($a['nummer'] ?: 'Angebot #' . $a['id'])) ?></a>
          <?php else: ?><span class="titel"><?= h((string)($a['nummer'] ?: 'Angebot #' . $a['id'])) ?></span><?php endif; ?>
          <span class="unter"><?= h((string)$a['status']) ?><?= $a['summe'] !== null ? ' · ' . h(eur((float)$a['summe'])) : '' ?></span>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
  <?php if ($rezepturen): ?>
    <h3 style="margin:16px 0 6px;font-size:var(--fs-sm)">Rezepturen</h3>
    <?php foreach ($rezepturen as $rz): ?>
      <div class="crm-zeile">
        <div class="crm-mitte">
          <?php if ($dash !== ''): ?><a class="titel" href="<?= h($dash . '/?p=rezeptur_detail&id=' . (int)$rz['id']) ?>" target="_blank" rel="noopener"><?= h(trim((string)($rz['nummer'] ?? '') . ' ' . (string)($rz['name'] ?? ''))) ?></a>
          <?php else: ?><span class="titel"><?= h(trim((string)($rz['nummer'] ?? '') . ' ' . (string)($rz['name'] ?? ''))) ?></span><?php endif; ?>
          <span class="unter"><?= h((string)$rz['status']) ?></span>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div></div>

<div class="karte"><div class="rumpf">
  <h2 style="margin-top:0">Dokumente</h2>
  <p class="muted" style="margin:0 0 12px">Angebot, Abschluss, Rechnung oder Sonstiges am Kontakt ablegen.</p>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="tun" value="datei_upload">
    <div class="bx-grid">
      <div class="bx-field"><label for="kategorie">Kategorie</label>
        <select id="kategorie" name="kategorie">
          <?php foreach (kontakt_datei_kategorien() as $kk => $kv): ?><option value="<?= h($kk) ?>"><?= h($kv) ?></option><?php endforeach; ?>
        </select></div>
      <div class="bx-field"><label for="datei">Datei</label>
        <input type="file" id="datei" name="datei" required></div>
    </div>
    <button class="btn btn-ghost btn-sm" type="submit">Hochladen</button>
  </form>
  <?php if ($dateien): $katLbl = kontakt_datei_kategorien(); ?>
    <div style="margin-top:14px">
      <?php foreach ($dateien as $d): ?>
        <div class="crm-zeile">
          <div class="crm-alter ruhig"><?= h(fmt_zeit((string)$d['angelegt'], 'd.m.')) ?>
            <span class="art"><?= h($katLbl[$d['kategorie']] ?? (string)$d['kategorie']) ?></span></div>
          <div class="crm-mitte">
            <a class="titel" href="kontakt_doc.php?id=<?= (int)$d['id'] ?>" target="_blank" rel="noopener"><?= h((string)$d['original']) ?></a>
          </div>
          <form method="post" style="margin:0" onsubmit="return confirm('Dokument löschen?');">
            <input type="hidden" name="tun" value="datei_del"><input type="hidden" name="did" value="<?= (int)$d['id'] ?>">
            <button class="btn btn-ghost btn-sm" type="submit" title="Löschen">×</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
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
    <p class="muted" style="margin-bottom:12px">Legt im Dashboard einen Kunden mit diesen Daten an.
       Der Kontakt bleibt hier bestehen, damit der Verlauf nicht verloren geht.</p>
    <form method="post" onsubmit="return confirm('Im Dashboard einen Kunden anlegen?');">
      <input type="hidden" name="tun" value="kunde">
      <button class="btn btn-primary" type="submit">Zum Kunden machen</button>
    </form>
  <?php endif; ?>
</div></div>

<form method="post" style="margin-bottom:24px">
  <input type="hidden" name="tun" value="archiv">
  <button class="btn btn-ghost btn-sm" type="submit"><?= $k['archiviert'] ? 'Aus dem Archiv holen' : 'Archivieren' ?></button>
</form>
<?php fuss('kontakte');
