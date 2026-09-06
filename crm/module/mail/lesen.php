<?php
// E-Mail einfügen, den Rest macht das Programm. Route: ?p=mail
//
// Ablauf in drei Schritten, bewusst nicht in einem:
//   1. Mail einfügen, auf „Auslesen" drücken.
//   2. Das Programm zeigt, WAS es tun würde - an wen die Notiz geht, wann erinnert wird.
//   3. Ein Klick auf „Übernehmen", dann ist es erledigt.
// Schritt 2 kostet zwei Sekunden und verhindert, dass eine falsch verstandene Mail still
// im falschen Kundenkonto landet.
require_once BX_ROOT . '/core/mail_ki.php';
require_once BX_ROOT . '/core/kontakt.php';

$fehler = '';
$text   = trim((string)($_POST['text'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tun = (string)($_POST['tun'] ?? '');

    if ($tun === 'lesen') {
        $r = mail_ki_lesen($text);
        if (!$r['ok']) {
            $fehler = $r['fehler'];
        } else {
            // Ergebnis in der Sitzung halten - „Übernehmen" soll die KI nicht noch einmal fragen.
            $_SESSION['mail'] = ['daten' => $r['daten'], 'treffer' => $r['treffer'],
                                 'plan' => mail_ki_plan($r['daten'], $r['treffer']), 'text' => $text];
            header('Location: ?p=mail'); exit;
        }

    } elseif ($tun === 'uebernehmen') {
        $m = $_SESSION['mail'] ?? null;
        if (!$m) { $fehler = 'Da war nichts mehr zu übernehmen – bitte noch einmal einlesen.'; }
        else {
            $d    = $m['daten'];
            $plan = $m['plan'];
            // Ziel kann von Hand geändert worden sein (Auswahlfeld in der Vorschau).
            $wahl = (string)($_POST['ziel'] ?? ($plan['ziel_art'] . ':' . $plan['ziel_id']));
            [$art, $id] = array_pad(explode(':', $wahl, 2), 2, '0');
            $id = (int)$id;
            $tage = max(0, min(365, (int)($_POST['tage'] ?? $plan['tage'])));
            $notiz = trim((string)($_POST['notiz'] ?? $plan['notiz']));

            if ($art === 'kunde' && $id > 0) {
                kunde_verlauf($id, 'mail', $notiz, crm_uid());
                if ($tage > 0) kunde_wiedervorlage($id, 'Nachfassen: ' . ($d['betreff'] ?: $plan['ziel_text']), $tage, crm_uid());
                unset($_SESSION['mail']);
                header('Location: ?p=kunde&id=' . $id . '&ok=notiert'); exit;
            }
            if ($art === 'kontakt' && $id > 0) {
                kontakt_verlauf($id, 'mail', $notiz, crm_uid());
                if ($tage > 0) kontakt_wiedervorlage($id, 'Nachfassen: ' . ($d['betreff'] ?: $plan['ziel_text']), $tage, crm_uid());
                unset($_SESSION['mail']);
                header('Location: ?p=kontakt&id=' . $id . '&ok=notiert'); exit;
            }
            if ($art === 'neu') {
                $kid = kontakt_anlegen([
                    'name'     => $d['name'] !== '' ? $d['name'] : ($d['firma'] !== '' ? $d['firma'] : 'Ohne Namen'),
                    'firma'    => $d['firma'],
                    'email'    => $d['email'],
                    'telefon'  => $d['telefon'],
                    'quelle'   => 'mail',
                    'notiz'    => $notiz,
                    'wert_eur' => $d['wert'] !== null ? (string)$d['wert'] : '',
                ], crm_uid());
                if ($tage > 0) kontakt_wiedervorlage($kid, 'Nachfassen: ' . ($d['betreff'] ?: $d['name']), $tage, crm_uid());
                unset($_SESSION['mail']);
                header('Location: ?p=kontakt&id=' . $kid . '&ok=1'); exit;
            }
            unset($_SESSION['mail']);
            header('Location: ?p=mail&ok=nichts'); exit;
        }

    } elseif ($tun === 'verwerfen') {
        unset($_SESSION['mail']);
        header('Location: ?p=mail'); exit;
    }
}

$m = $_SESSION['mail'] ?? null;

kopf('E-Mail einlesen', 'mail');
seitenkopf('E-Mail einlesen', 'Mail einfügen – der Rest passiert von selbst.');
if ($fehler !== '')             hinweis($fehler, 'warn');
if (isset($_GET['ok']))         hinweis('Nichts angelegt – die Mail brauchte keinen Vorgang.');
if (!ki_bereit())               hinweis('Die KI ist hier nicht eingerichtet – ohne sie kann die Mail nicht ausgelesen werden.', 'warn');
?>

<?php if (!$m): ?>
  <form method="post">
    <input type="hidden" name="tun" value="lesen">
    <div class="karte"><div class="rumpf">
      <div class="bx-field">
        <label for="text">E-Mail hier einfügen</label>
        <textarea id="text" name="text" required autofocus style="min-height:260px"
          placeholder="Alles markieren, kopieren, hier einfügen – Kopfzeilen, Signatur und der zitierte Verlauf dürfen mit rein."><?= h($text) ?></textarea>
      </div>
      <button class="btn btn-primary" type="submit" <?= ki_bereit() ? '' : 'disabled' ?>>Auslesen</button>
      <span class="muted" style="margin-left:10px;font-size:var(--fs-sm)">Gespeichert wird noch nichts.</span>
    </div></div>
  </form>

<?php else: $d = $m['daten']; $plan = $m['plan']; $arten = mail_ki_arten(); ?>

  <div class="karte"><div class="rumpf">
    <div class="bx-row" style="justify-content:space-between;align-items:baseline;gap:12px">
      <h2 style="margin:0"><?= h($arten[$d['art']] ?? 'Mail') ?></h2>
      <span class="muted"><?= h(trim(($d['firma'] !== '' ? $d['firma'] . ' – ' : '') . $d['name'])) ?></span>
    </div>
    <?php if ($d['betreff'] !== ''): ?>
      <p style="margin:10px 0 4px"><strong><?= h($d['betreff']) ?></strong></p>
    <?php endif; ?>
    <p class="muted" style="margin:4px 0 0"><?= nl2br(h($d['zusammenfassung'] ?: $d['wunsch'])) ?></p>
    <?php if ($d['wert'] !== null || $d['tage'] !== null): ?>
      <p class="muted" style="margin:8px 0 0;font-size:var(--fs-sm)">
        <?= $d['wert'] !== null ? 'Grob ' . h(eur((float)$d['wert'])) : '' ?>
        <?= $d['wert'] !== null && $d['tage'] !== null ? ' · ' : '' ?>
        <?= $d['tage'] !== null ? 'Frist laut Mail: ' . (int)$d['tage'] . ' Tage' : '' ?>
      </p>
    <?php endif; ?>
  </div></div>

  <form method="post">
    <input type="hidden" name="tun" value="uebernehmen">
    <div class="karte"><div class="rumpf">
      <h2>Das würde passieren</h2>

      <div class="bx-field">
        <label for="ziel">Gehört zu</label>
        <select id="ziel" name="ziel">
          <?php foreach ($m['treffer'] as $t): ?>
            <option value="<?= h($t['art']) ?>:<?= (int)$t['id'] ?>"
              <?= ($plan['ziel_art'] === $t['art'] && $plan['ziel_id'] === (int)$t['id']) ? 'selected' : '' ?>>
              <?= h($t['text']) ?> · <?= $t['art'] === 'kunde' ? 'Kunde' : 'Kontakt' ?> (<?= h($t['grund']) ?>)
            </option>
          <?php endforeach; ?>
          <option value="neu:0" <?= $plan['ziel_art'] === 'neu' ? 'selected' : '' ?>>
            Neuer Kontakt: <?= h($plan['ziel_text'] ?: 'Ohne Namen') ?>
          </option>
          <option value="nichts:0" <?= $plan['ziel_art'] === 'nichts' ? 'selected' : '' ?>>Nichts anlegen</option>
        </select>
        <?php if ($plan['grund'] !== ''): ?>
          <p class="muted" style="margin:6px 0 0;font-size:var(--fs-sm)">Gefunden über: <?= h($plan['grund']) ?></p>
        <?php endif; ?>
      </div>

      <div class="bx-field">
        <label for="notiz">Notiz, die angelegt wird</label>
        <textarea id="notiz" name="notiz" style="min-height:110px"><?= h($plan['notiz']) ?></textarea>
      </div>

      <div class="bx-field">
        <label for="tage">Erinnere mich</label>
        <select id="tage" name="tage">
          <?php foreach (['0' => 'nicht', '1' => 'morgen', '3' => 'in 3 Tagen', '7' => 'in einer Woche',
                          '14' => 'in zwei Wochen', '30' => 'in einem Monat'] as $k => $v): ?>
            <option value="<?= h((string)$k) ?>" <?= (int)$k === (int)$plan['tage'] ? 'selected' : '' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (!$d['antwort_noetig']): ?>
          <p class="muted" style="margin:6px 0 0;font-size:var(--fs-sm)">Die Mail erwartet keine Antwort – deshalb ohne Erinnerung.</p>
        <?php endif; ?>
      </div>

      <div class="bx-row" style="gap:8px">
        <button class="btn btn-primary" type="submit">Übernehmen</button>
        <button class="btn btn-ghost" type="submit" name="tun" value="verwerfen" formnovalidate>Verwerfen</button>
      </div>
    </div></div>
  </form>

  <details class="karte"><summary class="rumpf" style="cursor:pointer">Eingefügte Mail ansehen</summary>
    <div class="rumpf" style="padding-top:0"><p class="muted" style="white-space:pre-wrap;font-size:var(--fs-sm)"><?= h(mb_substr((string)$m['text'], 0, 4000)) ?></p></div>
  </details>
<?php endif; ?>
<?php fuss('mail');
