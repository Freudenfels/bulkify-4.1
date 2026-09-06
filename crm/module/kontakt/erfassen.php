<?php
// Schnell erfassen. Route: ?p=erfassen
// Der Fall: Du stehst auf der Messe oder liest eine WhatsApp-Nachricht und willst in zehn Sekunden
// festhalten, dass da jemand etwas will. Pflicht ist nur Name oder Firma.
//
// Drei Wege hinein:
//   1. Von Hand tippen.
//   2. Aus WhatsApp teilen -> Text landet oben, die KI fuellt die Felder.
//   3. Visitenkarte fotografieren -> die KI liest sie ab.
// Gespeichert wird in allen drei Faellen erst auf Knopfdruck.
require_once BX_ROOT . '/core/kontakt.php';
require_once BX_ROOT . '/core/kontakt_ki.php';
require_once BX_ROOT . '/core/dublette.php';

$fehler = '';
$hinweisKi = '';
$vor = ['name' => '', 'firma' => '', 'email' => '', 'telefon' => '', 'quelle' => 'whatsapp',
        'notiz' => '', 'wert' => '', 'erinnern' => '3'];

// Aus dem Teilen-Menue kommt der Text per GET (Link) oder POST (Web Share Target).
$geteilt = trim((string)($_POST['text'] ?? $_GET['text'] ?? ''));
if ($geteilt === '') $geteilt = trim((string)($_POST['title'] ?? $_GET['title'] ?? ''));
$geteiltUrl = trim((string)($_POST['url'] ?? $_GET['url'] ?? ''));
if ($geteiltUrl !== '') $geteilt = trim($geteilt . "\n" . $geteiltUrl);
if ($geteilt !== '') $vor['notiz'] = $geteilt;

// Ein geteiltes Bild kommt als Datei mit - dann gleich als Visitenkarte lesen.
$geteiltesBild = (!empty($_FILES['bild']['name']) && ($_FILES['bild']['error'] ?? 1) === UPLOAD_ERR_OK)
              || (!empty($_FILES['datei']['name']) && ($_FILES['datei']['error'] ?? 1) === UPLOAD_ERR_OK);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($vor as $k => $_) if (isset($_POST[$k])) $vor[$k] = (string)$_POST[$k];
    $tun = (string)($_POST['tun'] ?? ($geteiltesBild ? 'bild' : ''));

    if ($tun === 'bild') {
        $r = erfassen_bild_lesen();
        if (!$r['ok']) $fehler = $r['fehler'];
        else { $vor = erfassen_uebernehmen($vor, $r['daten']); $hinweisKi = $r['fehler'] ?: 'Von der Karte gelesen – bitte kurz prüfen.'; }

    } elseif ($tun === 'lesen') {
        $r = kontakt_ki_lesen($vor['notiz']);
        if (!$r['ok']) $fehler = $r['fehler'];
        else { $vor = erfassen_uebernehmen($vor, $r['daten']); $hinweisKi = $r['fehler'] ?: 'Aus dem Text gefüllt – bitte kurz prüfen.'; }

    } elseif ($tun === 'speichern') {
        if (trim($vor['name']) === '' && trim($vor['firma']) === '') {
            $fehler = 'Ohne Namen oder Firma geht es nicht – notfalls „Unbekannt, Messe Köln“.';
        } else {
            $id = kontakt_anlegen([
                'name'     => trim($vor['name']) !== '' ? $vor['name'] : $vor['firma'],
                'firma'    => $vor['firma'],
                'telefon'  => $vor['telefon'],
                'email'    => $vor['email'],
                'quelle'   => $vor['quelle'],
                'notiz'    => $vor['notiz'],
                'wert_eur' => $vor['wert'],
            ], crm_uid());
            $tage = max(0, min(90, (int)$vor['erinnern']));
            if ($tage > 0) kontakt_wiedervorlage($id, 'Nachfassen: ' . ($vor['name'] ?: $vor['firma']), $tage, crm_uid());
            header('Location: ?p=kontakt&id=' . $id . '&ok=1'); exit;
        }
    }
}

// Steht schon jemand mit diesem Namen im System? Erst pruefen, wenn es etwas zu pruefen gibt.
$dubletten = (trim($vor['name']) !== '' || trim($vor['firma']) !== '')
    ? dublette_suchen($vor['name'], $vor['firma'], $vor['email'], $vor['telefon'])
    : [];

kopf('Erfassen', 'erfassen');
seitenkopf('Schnell erfassen', 'Wer hat sich gemeldet, und was will er?');
if ($fehler !== '')    hinweis($fehler, 'warn');
if ($hinweisKi !== '') hinweis($hinweisKi);
?>

<?php if ($dubletten): ?>
<div class="karte" style="border-color:var(--warm)">
  <div class="rumpf">
    <strong>Gibt es vielleicht schon</strong>
    <p class="leise" style="margin:4px 0 10px">Bevor du anlegst – vielleicht ist das derselbe.</p>
    <?php foreach ($dubletten as $d): ?>
      <div style="margin-bottom:6px">
        <a href="?p=<?= $d['art'] === 'kunde' ? 'kunde' : 'kontakt' ?>&id=<?= (int)$d['id'] ?>"><?= h($d['text']) ?></a>
        <span class="leise"><?= $d['art'] === 'kunde' ? 'Kunde' : 'Kontakt' ?> · <?= h($d['grund']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if (ki_bereit()): ?>
<form method="post" enctype="multipart/form-data">
  <input type="hidden" name="tun" value="bild">
  <div class="karte"><div class="rumpf">
    <div class="feld" style="margin-bottom:8px">
      <label for="bild">Visitenkarte oder Foto</label>
      <input type="file" id="bild" name="bild" accept="image/*,application/pdf" capture="environment"
             onchange="this.form.submit()">
    </div>
    <p class="leise" style="margin:0">Karte abfotografieren – die KI liest Name, Firma, Telefon und E-Mail ab.</p>
  </div></div>
</form>
<?php endif; ?>

<form method="post">
  <input type="hidden" name="tun" value="speichern" id="tun">

  <div class="karte"><div class="rumpf">
    <div class="feld">
      <label for="notiz">Worum geht es</label>
      <textarea id="notiz" name="notiz" placeholder="Nachricht hier einfügen – oder selbst schreiben, z. B. „Magnesium-Kapseln, 50.000 Stück, Angebot bis Freitag“"><?= h($vor['notiz']) ?></textarea>
      <?php if (ki_bereit()): ?>
        <button class="btn klein" type="submit" style="margin-top:8px"
                onclick="document.getElementById('tun').value='lesen'">Aus dem Text ausfüllen</button>
        <span class="leise" style="font-size:13px;margin-left:8px">Gespeichert wird dabei nichts.</span>
      <?php endif; ?>
    </div>
  </div></div>

  <div class="karte"><div class="rumpf">
    <div class="feld">
      <label for="name">Name</label>
      <input type="text" id="name" name="name" value="<?= h($vor['name']) ?>" placeholder="z. B. Lena Hoffmann">
    </div>
    <div class="zweispaltig">
      <div class="feld">
        <label for="firma">Firma</label>
        <input type="text" id="firma" name="firma" value="<?= h($vor['firma']) ?>">
      </div>
      <div class="feld">
        <label for="quelle">Woher</label>
        <select id="quelle" name="quelle">
          <?php foreach (crm_quellen() as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= $vor['quelle'] === $k ? 'selected' : '' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="zweispaltig">
      <div class="feld">
        <label for="telefon">Telefon</label>
        <input type="tel" id="telefon" name="telefon" value="<?= h($vor['telefon']) ?>">
      </div>
      <div class="feld">
        <label for="email">E-Mail</label>
        <input type="email" id="email" name="email" value="<?= h($vor['email']) ?>">
      </div>
    </div>
    <div class="zweispaltig">
      <div class="feld">
        <label for="wert">Geschätzter Wert (optional)</label>
        <input type="text" id="wert" name="wert" inputmode="decimal" placeholder="z. B. 4800" value="<?= h($vor['wert']) ?>">
      </div>
      <div class="feld">
        <label for="erinnern">Erinnere mich</label>
        <select id="erinnern" name="erinnern">
          <?php foreach (['0' => 'nicht', '1' => 'morgen', '3' => 'in 3 Tagen', '7' => 'in einer Woche',
                          '14' => 'in zwei Wochen', '30' => 'in einem Monat'] as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= $vor['erinnern'] === $k ? 'selected' : '' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <button class="btn stark" type="submit" style="width:100%;justify-content:center">Speichern</button>
  </div></div>
</form>
<?php fuss('erfassen');
