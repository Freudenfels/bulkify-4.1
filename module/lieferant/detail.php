<?php
// Lieferantenkonto (Cockpit) & Bearbeiten – gleiches Muster wie Kundenkonto
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$id  = $_GET['id'] ?? 'neu';
$neu = ($id === 'neu' || !is_numeric($id));

require_once BX_ROOT . '/core/nachricht.php';
require_once BX_ROOT . '/core/lieferant_dateien.php';

$KATS = ['rohstoff'=>'Rohstoff','verpackung'=>'Verpackung','verbrauch'=>'Verbrauch','maschine'=>'Maschine','labor'=>'Labor','fertigprodukt'=>'Fertige Produkte'];
$FORMEN = ['kapsel'=>'Kapsel','tablette'=>'Tablette','softgel'=>'Softgel','stick'=>'Stick','pulver'=>'Pulver','fluessig'=>'Flüssig'];

$fehler = '';
// Katalog des Lieferanten: je Zeile entscheiden, ob daraus ein Artikel wird.
if (!$neu && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['aktion'] ?? ''), ['kat_uebernehmen', 'kat_ablehnen', 'kat_alle', 'kat_upload'], true)) {
    require_once BX_ROOT . '/core/lieferant_katalog.php';
    $akt = (string)$_POST['aktion'];
    $n = 0; $fehler = '';
    if ($akt === 'kat_upload') {
        // Preisliste, die uns der Lieferant gemailt hat: erst in die Dateiablage, dann liest die KI sie.
        // Gleicher Weg wie im Portal - nur laedt hier das Team hoch statt der Lieferant.
        require_once BX_ROOT . '/core/lieferant_dateien.php';
        $f = lieferant_datei_upload((int)$id, 'team');
        if ($f !== '') { header('Location: ?p=lieferant&id=' . (int)$id . '&fehler=' . urlencode($f) . '#katalog'); exit; }
        $d = one("SELECT id, datei FROM dokument WHERE objekt_typ='lieferant' AND objekt_id=? ORDER BY id DESC LIMIT 1", [(int)$id]);
        $r = $d ? katalog_einlesen((int)$id, BX_UPLOADS . '/' . basename((string)$d['datei']), (int)$d['id'])
                : ['ok' => false, 'fehler' => 'Datei nicht gefunden.'];
        header('Location: ?p=lieferant&id=' . (int)$id
             . ($r['ok'] ? '&katgelesen=' . (int)$r['anzahl'] : '&fehler=' . urlencode($r['fehler'])) . '#katalog'); exit;
    }
    if ($akt === 'kat_ablehnen') {
        katalog_ablehnen((int)($_POST['zeile_id'] ?? 0)); $n = 1;
    } elseif ($akt === 'kat_uebernehmen') {
        $r = katalog_uebernehmen((int)($_POST['zeile_id'] ?? 0), (int)($_POST['item_id'] ?? 0) ?: null, !empty($_POST['preis_mit']));
        if ($r['ok']) $n = 1; else $fehler = $r['msg'];
    } else {
        // Alle offenen Zeilen auf einmal – für lange Kataloge, bei denen jede Zeile stimmt.
        foreach (katalog_zeilen((int)$id, 'neu') as $z) { $r = katalog_uebernehmen((int)$z['id'], null, true); if ($r['ok']) $n++; }
    }
    header('Location: ?p=lieferant&id=' . (int)$id . ($fehler !== '' ? '&fehler=' . urlencode($fehler) : '&kat=' . $n) . '#katalog'); exit;
}

// Rückfragen und Dateiablage – eigene POST-Wege (die Panels haben eigene Formulare).
if (!$neu && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'nachricht') {
    $f = nachricht_post_verarbeiten((int)$id, 'team', (string)(current_user()['name'] ?? 'Team'));
    header('Location: ?p=lieferant&id=' . (int)$id . ($f === '' ? '&nachricht=1' : '&fehler=' . urlencode($f)) . '#rueckfragen'); exit;
}
if (!$neu && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['aktion'] ?? ''), ['dok_upload', 'dok_del'], true)) {
    $f = $_POST['aktion'] === 'dok_upload' ? lieferant_datei_upload((int)$id, 'team') : lieferant_datei_loeschen((int)$id, (int)($_POST['dok_id'] ?? 0), 'team');
    header('Location: ?p=lieferant&id=' . (int)$id . ($f === '' ? '&dok=1' : '&fehler=' . urlencode($f)) . '#dok'); exit;
}
// Zugang und Preisanfragen – die eigenen POST-Wege, damit das Stammdaten-Formular unberuehrt bleibt.
if (!$neu && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'einladung_mailen') {
    $einl = lieferant_einladung((int)$id, mail_basis_url());
    $f = mail_lieferant_einladung((int)$id, (string)$einl['link']);
    header('Location: ?p=lieferant&id=' . (int)$id . ($f === '' ? '&gemailt=1' : '&mailfehler=' . urlencode($f))); exit;
}
if (!$neu && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'einladen') {
    lieferant_einladung((int)$id);
    header('Location: ?p=lieferant&id=' . (int)$id . '&eingeladen=1'); exit;
}
if (!$neu && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'anfrage') {
    lieferant_anfrage_stellen((int)$id,
        ($_POST['item_id'] ?? '') !== '' ? (int)$_POST['item_id'] : null,
        (string)($_POST['betreff'] ?? ''),
        ($_POST['menge'] ?? '') !== '' ? zahl_lesen((string)$_POST['menge'], true) : null,
        (string)($_POST['einheit'] ?? ''), (string)($_POST['notiz'] ?? ''), isset($_POST['coa']),
        ['art' => (string)($_POST['art'] ?? ''), 'form' => (string)($_POST['form'] ?? ''),
         'stueck_je_packung' => (int)($_POST['stueck_je_packung'] ?? 0),
         'kapselgroesse_id'  => (int)($_POST['kapselgroesse_id'] ?? 0),
         'rezeptur_id'       => (int)($_POST['rezeptur_id'] ?? 0)]);
    header('Location: ?p=lieferant&id=' . (int)$id . '&angefragt=1'); exit;
}
if (!$neu && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'angebot_annehmen') {
    $f = lieferant_angebot_annehmen((int)($_POST['angebot_id'] ?? 0));
    header('Location: ?p=lieferant&id=' . (int)$id . ($f === '' ? '&uebernommen=1' : '&fehler=' . urlencode($f))); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = fn($k) => trim($_POST[$k] ?? '');
    if ($f('firma') === '') {
        $fehler = 'Firma ist ein Pflichtfeld.';
    } else {
        $felder = ['lieferantennummer','firma','ansprechpartner','email','telefon','gesperrt','sprache','kategorien','fertig_formen','webseite',
                   'strasse','hausnummer','plz','ort','land','ust_id',
                   'waehrung','zahlungsart','zahlungsziel_tage','lieferzeit_tage','mindestbestellwert','notiz'];
        $vals = array_map($f, $felder);
        $vals[array_search('gesperrt', $felder)]   = isset($_POST['gesperrt']) ? 1 : 0;
        foreach (['zahlungsziel_tage','lieferzeit_tage','mindestbestellwert'] as $nf) { $ix = array_search($nf, $felder); if (trim((string)$vals[$ix]) === '') $vals[$ix] = 0; }
        $katsSel = array_keys(array_intersect_key($KATS, (array)($_POST['kat'] ?? [])));
        $vals[array_search('kategorien', $felder)] = implode(',', $katsSel);
        // Formen nur speichern, wenn „Fertige Produkte" gewählt ist
        $formenSel = in_array('fertigprodukt', $katsSel, true)
            ? array_keys(array_intersect_key($FORMEN, (array)($_POST['form'] ?? [])))
            : [];
        $vals[array_search('fertig_formen', $felder)] = implode(',', $formenSel);
        if ($neu) {
            if (trim($vals[array_search('lieferantennummer', $felder)]) === '') $vals[array_search('lieferantennummer', $felder)] = naechste_nummer('L');
            $ph = implode(',', array_fill(0, count($felder), '?'));
            q("INSERT INTO lieferanten (" . implode(',', $felder) . ") VALUES ($ph)", $vals);
            $id = insert_id();
            log_aktivitaet('lieferant', (int)$id, 'team', 'Lieferant angelegt.', 'notiz');
        } else {
            $set = implode(',', array_map(fn($c) => "$c=?", $felder));
            $vals[] = (int)$id;
            q("UPDATE lieferanten SET $set WHERE id=?", $vals);
        }
        header('Location: ?p=lieferant&id=' . $id . '&gespeichert=1'); exit;
    }
}

$l = $neu
    ? ['gesperrt'=>0,'land'=>'DE','sprache'=>'de','waehrung'=>'EUR','zahlungsart'=>'rechnung','zahlungsziel_tage'=>0]
    : one("SELECT * FROM lieferanten WHERE id=?", [(int)$id]);
if (!$l) { $neu = true; $l = ['gesperrt'=>0,'land'=>'DE','sprache'=>'de','waehrung'=>'EUR','zahlungsart'=>'rechnung']; }
$v = fn($key) => h((string)($l[$key] ?? ''));
$gesperrt = (int)($l['gesperrt'] ?? 0) === 1;
$aktKats = array_filter(explode(',', (string)($l['kategorien'] ?? '')));
$aktFormen = array_filter(explode(',', (string)($l['fertig_formen'] ?? '')));
$hatFertig = in_array('fertigprodukt', $aktKats, true);
if (!$neu) { seed_aktivitaet_if_empty(); $verlauf = verlauf_fuer('lieferant', (int)$id); } else { $verlauf = []; }
$ungelesen = $neu ? 0 : nachrichten_ungelesen((int)$id, 'team');
require_once BX_ROOT . '/core/lieferant_katalog.php';
$katOffen = $neu ? 0 : katalog_offen((int)$id);
// Echte Bestellungen dieses Lieferanten
$l_bestellungen = $neu ? [] : all("SELECT b.*, (SELECT COALESCE(SUM(menge*ek_preis),0) FROM bestellung_position p WHERE p.bestellung_id=b.id) AS summe,
                                    (SELECT COUNT(*) FROM bestellung_position p WHERE p.bestellung_id=b.id) AS pos
                                    FROM bestellung b WHERE b.lieferant_id=? ORDER BY b.angelegt DESC", [(int)$id]);
$l_einkauf = 0.0; foreach ($l_bestellungen as $lb) $l_einkauf += (float)$lb['summe'];
$l_beur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
$l_bBadge = fn($s) => match ($s) { 'offen'=>bx_badge('offen','info'),'bestellt'=>bx_badge('bestellt','warn'),'geliefert'=>bx_badge('geliefert','ok'),default=>bx_badge($s) };
$l_bestTabelle = function($rows) use ($l_beur, $l_bBadge) {
    echo '<div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Nummer</th><th class="bx-num">Positionen</th><th class="bx-num">Summe</th><th>Status</th></tr></thead><tbody>';
    if (!$rows) echo '<tr><td colspan="4" class="muted">Noch keine Bestellungen bei diesem Lieferanten.</td></tr>';
    foreach ($rows as $b) echo '<tr style="cursor:pointer" onclick="location.href=\'?p=bestellung&id=' . (int)$b['id'] . '\'"><td><strong>' . h($b['nummer']) . '</strong></td><td class="bx-num">' . (int)$b['pos'] . '</td><td class="bx-num">' . $l_beur($b['summe']) . '</td><td>' . $l_bBadge($b['status']) . '</td></tr>';
    echo '</tbody></table></div>';
};

function bx_bald(string $modul): void {
    echo '<div class="bx-tablewrap"><table class="bx-table"><tbody><tr><td class="muted">'
       . 'Sobald das Modul <strong>' . h($modul) . '</strong> steht, erscheinen hier automatisch alle '
       . h($modul) . ' dieses Lieferanten.</td></tr></tbody></table></div>';
}

render_header('lieferanten', $neu ? 'Neuer Lieferant' : $l['firma']);
bx_head($neu ? 'Neuer Lieferant' : $v('firma'),
        $neu ? 'Stammdaten anlegen' : trim(($v('lieferantennummer') ? $v('lieferantennummer') . ' · ' : '') . $v('ort') . ' · ' . $v('land')),
        bx_btn('Zurück zur Liste', '?p=lieferanten', 'ghost'));

if (isset($_GET['gespeichert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b">' . h($fehler) . '</div>';

if (!$neu) {
    echo '<div class="bx-cards">';
    echo '<div class="bx-card"><div class="k">Status</div><div class="v">' . ($gesperrt ? bx_badge('gesperrt','err') : bx_badge('aktiv','ok')) . '</div></div>';
    echo '<div class="bx-card"><div class="k">Einkauf gesamt</div><div class="v">' . ($l_einkauf>0 ? number_format($l_einkauf,2,',','.').' €' : '<span class="muted">–</span>') . '</div></div>';
    echo '<div class="bx-card"><div class="k">Produkte hergestellt</div><div class="v muted">–</div></div>';
    echo '<div class="bx-card"><div class="k">Offene Rechnungen</div><div class="v muted">–</div></div>';
    echo '<div class="bx-card"><div class="k">Ø Lieferzeit</div><div class="v">' . ((int)($l['lieferzeit_tage']??0) ?: '–') . '<span style="font-size:14px"> Tage</span></div></div>';
    echo '</div>';
}
?>
<form method="post" class="bx-form">
  <div class="settabs" id="lieftabs">
    <?php if (!$neu): ?>
    <a href="#" class="on" data-tab="ueber">Übersicht</a>
    <a href="#" data-tab="angebote">Preise / Angebote</a>
    <a href="#" data-tab="bestell">Bestellungen</a>
    <a href="#" data-tab="rechnungen">Rechnungen</a>
    <a href="#" data-tab="dok">Dokumente</a>
    <a href="#" data-tab="katalog">Katalog<?= $katOffen > 0 ? ' (' . $katOffen . ')' : '' ?></a>
    <a href="#" data-tab="rueckfragen">Rückfragen<?= $ungelesen > 0 ? ' (' . $ungelesen . ' neu)' : '' ?></a>
    <a href="#" data-tab="verlauf">Verlauf</a>
    <a href="#" data-tab="stamm">Stammdaten</a>
    <?php else: ?>
    <a href="#" class="on" data-tab="stamm">Stammdaten</a>
    <?php endif; ?>
    <a href="#" data-tab="adr">Adresse</a>
    <a href="#" data-tab="kond">Konditionen</a>
  </div>

  <?php if (!$neu): ?>
  <section data-panel="ueber">
    <div class="bx-panel">
      <h2>Kontakt</h2>
      <div class="bx-grid">
        <div><div class="k muted">Ansprechpartner</div><div><?= $v('ansprechpartner') ?: '–' ?></div></div>
        <div><div class="k muted">E-Mail</div><div><?= $v('email') ?: '–' ?></div></div>
        <div><div class="k muted">Telefon</div><div><?= $v('telefon') ?: '–' ?></div></div>
        <div><div class="k muted">Sprache</div><div><?= h(strtoupper($v('sprache'))) ?></div></div>
      </div>
      <?php if ($aktKats): ?>
      <hr class="bx"><div class="k muted">Liefer-Kategorien</div>
      <div class="bx-row" style="margin-top:6px"><?php foreach ($aktKats as $kk) echo bx_badge($KATS[$kk] ?? $kk, $kk==='fertigprodukt'?'info':''); ?></div>
      <?php if ($hatFertig && $aktFormen): ?>
        <div class="k muted" style="margin-top:10px">Fertige Produkte – Formen</div>
        <div class="bx-row" style="margin-top:6px"><?php foreach ($aktFormen as $ff) echo bx_badge($FORMEN[$ff] ?? $ff, 'ok'); ?></div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
    <div class="bx-panel"><h2>Letzte Preise / Angebote</h2><?php bx_bald('Angebote'); ?></div>
    <div class="bx-panel"><h2>Letzte Bestellungen</h2><?php $l_bestTabelle(array_slice($l_bestellungen,0,5)); ?></div>
  </section>

  <section data-panel="angebote" hidden><div class="bx-panel"><h2>Preise / Angebote</h2><?php bx_bald('Angebote'); ?></div></section>
  <section data-panel="bestell" hidden><div class="bx-panel"><h2>Bestellungen (<?= count($l_bestellungen) ?>)</h2><?php $l_bestTabelle($l_bestellungen); ?></div></section>

  <section data-panel="rechnungen" hidden>
    <div class="bx-panel">
      <h2>Rechnungen &amp; Zahlungen <?= bx_hint('Zeigt je Auftrag, ob eine Rechnung vorliegt und ob sie bezahlt ist') ?></h2>
      <div class="bx-row" style="margin-bottom:12px">
        <?= bx_badge('bezahlt','ok') ?> <?= bx_badge('offen','warn') ?> <?= bx_badge('überfällig','err') ?> <?= bx_badge('keine Rechnung','info') ?>
        <span class="muted">Vorschau mit Beispieldaten – echte Werte erscheinen, sobald Bestellungen &amp; Buchhaltung angebunden sind.</span>
      </div>
      <?php
      $beispiel = [
          ['B-3007','12.08.2026','2.100,00 €','R-5501','26.08.2026','bezahlt','ok'],
          ['B-3012','18.08.2026','850,00 €','R-5540','01.09.2026','offen','warn'],
          ['B-3015','05.08.2026','1.400,00 €','R-5480','19.08.2026','überfällig','err'],
          ['B-3020','20.08.2026','3.200,00 €','—','—','keine Rechnung','info'],
      ];
      ?>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Auftrag</th><th>Datum</th><th class="bx-num">Betrag</th><th>Rechnung</th><th>fällig</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($beispiel as $b): ?>
          <tr>
            <td><?= h($b[0]) ?></td><td><?= h($b[1]) ?></td><td class="bx-num"><?= h($b[2]) ?></td>
            <td><?= $b[3]==='—' ? '<span class="muted">—</span>' : h($b[3]) ?></td>
            <td><?= h($b[4]) ?></td><td><?= bx_badge($b[5],$b[6]) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </section>

  <section data-panel="verlauf" hidden>
    <div class="bx-panel">
      <h2>Aktivitätsverlauf <?= bx_hint('links = wir (bulkify), rechts = Lieferant. Jede Aktion wird automatisch protokolliert') ?></h2>
      <?php bx_chat($verlauf, $v('firma')); ?>
    </div>
  </section>
  <?php endif; ?>

  <section data-panel="stamm" <?= $neu ? '' : 'hidden' ?>>
    <div class="bx-panel"><div class="bx-grid">
      <div class="bx-field"><label>Lieferantennummer <?= bx_hint('leer lassen = wird automatisch vergeben (L-…)') ?></label><input type="text" name="lieferantennummer" value="<?= $v('lieferantennummer') ?>" placeholder="<?= $neu ? 'automatisch (L-…)' : '' ?>"></div>
      <div class="bx-field"><label>Firma</label><input type="text" name="firma" value="<?= $v('firma') ?>" required></div>
      <div class="bx-field"><label>Ansprechpartner</label><input type="text" name="ansprechpartner" value="<?= $v('ansprechpartner') ?>"></div>
      <div class="bx-field"><label>E-Mail</label><input type="email" name="email" value="<?= $v('email') ?>"></div>
      <div class="bx-field"><label>Telefon</label><input type="text" name="telefon" value="<?= $v('telefon') ?>"></div>
      <div class="bx-field"><label>Sprache <?= bx_hint('Kommunikationssprache im Lieferantenportal') ?></label>
        <select name="sprache">
          <?php foreach (['de'=>'Deutsch','en'=>'English','zh'=>'中文'] as $s=>$lbl): ?>
            <option value="<?= $s ?>" <?= ($l['sprache']??'')===$s?'selected':'' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Webseite</label><input type="text" name="webseite" value="<?= $v('webseite') ?>" placeholder="https://…"></div>
      <div class="bx-field"><label>Lieferant sperren <?= bx_hint('gesperrte Lieferanten werden bei Preisanfragen/Bestellungen nicht mehr vorgeschlagen') ?></label>
        <div class="bx-check" style="padding-top:8px">
          <input type="checkbox" name="gesperrt" id="f_gesperrt" value="1" <?= $gesperrt?'checked':'' ?>>
          <label for="f_gesperrt" style="margin:0">Lieferant ist gesperrt</label>
        </div>
      </div>
    </div>
    <div class="bx-field"><label>Liefer-Kategorien <?= bx_hint('Was kann der Lieferant liefern? Steuert später die automatische Preisanfrage') ?></label>
      <div class="bx-row">
        <?php foreach ($KATS as $key=>$lbl): ?>
          <label class="bx-check"><input type="checkbox" name="kat[<?= $key ?>]" value="1" <?= in_array($key,$aktKats,true)?'checked':'' ?> <?= $key==='fertigprodukt'?'id="kat_fertig"':'' ?>> <?= $lbl ?></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="bx-field" id="formenBlock" <?= $hatFertig ? '' : 'hidden' ?>>
      <label>Fertige Produkte – welche Formen? <?= bx_hint('z. B. reiner Softgel-Hersteller. Steuert, welche fertigen Produkte hier eingekauft werden können') ?></label>
      <div class="bx-row">
        <?php foreach ($FORMEN as $key=>$lbl): ?>
          <label class="bx-check"><input type="checkbox" name="form[<?= $key ?>]" value="1" <?= in_array($key,$aktFormen,true)?'checked':'' ?>> <?= $lbl ?></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="bx-field"><label>Notiz (intern)</label><textarea name="notiz"><?= $v('notiz') ?></textarea></div>
    </div>
  </section>

  <section data-panel="adr" hidden>
    <div class="bx-panel">
      <h2>Adresse</h2>
      <div class="bx-grid">
        <div class="bx-field"><label>Straße / Hausnummer</label>
          <div class="bx-row" style="flex-wrap:nowrap">
            <input type="text" name="strasse" value="<?= $v('strasse') ?>" style="flex:1" placeholder="Straße">
            <input type="text" name="hausnummer" value="<?= $v('hausnummer') ?>" style="width:100px" placeholder="Nr.">
          </div>
        </div>
        <div class="bx-field"><label>PLZ / Ort</label>
          <div class="bx-row" style="flex-wrap:nowrap">
            <input type="text" name="plz" value="<?= $v('plz') ?>" style="width:110px" placeholder="PLZ">
            <input type="text" name="ort" value="<?= $v('ort') ?>" style="flex:1" placeholder="Ort">
          </div>
        </div>
        <div class="bx-field"><label>Land</label><input type="text" name="land" value="<?= $v('land') ?>" maxlength="2"></div>
        <div class="bx-field"><label>USt-ID</label><input type="text" name="ust_id" value="<?= $v('ust_id') ?>"></div>
      </div>
    </div>
  </section>

  <section data-panel="kond" hidden>
    <div class="bx-panel"><div class="bx-grid">
      <div class="bx-field"><label>Währung <?= bx_hint('EK oft in Fremdwährung – hier die Standardwährung des Lieferanten') ?></label>
        <select name="waehrung">
          <?php foreach (['EUR'=>'EUR €','USD'=>'USD $','CNY'=>'CNY ¥'] as $s=>$lbl): ?>
            <option value="<?= $s ?>" <?= ($l['waehrung']??'')===$s?'selected':'' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Zahlungsart</label>
        <select name="zahlungsart">
          <?php foreach (['rechnung'=>'Rechnung','vorkasse'=>'Vorkasse'] as $s=>$lbl): ?>
            <option value="<?= $s ?>" <?= ($l['zahlungsart']??'')===$s?'selected':'' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Zahlungsziel (Tage)</label><input type="number" name="zahlungsziel_tage" value="<?= $v('zahlungsziel_tage') ?>"></div>
      <div class="bx-field"><label>Standard-Lieferzeit (Tage)</label><input type="number" name="lieferzeit_tage" value="<?= $v('lieferzeit_tage') ?>"></div>
      <div class="bx-field"><label>Mindestbestellwert</label><input type="number" step="0.01" name="mindestbestellwert" value="<?= $v('mindestbestellwert') ?>"></div>
    </div></div>
  </section>

  <div class="bx-row" style="margin-top:var(--sp-4)">
    <button class="btn btn-primary" type="submit"><?= $neu ? 'Lieferant anlegen' : 'Speichern' ?></button>
    <a class="btn btn-ghost" href="?p=lieferanten">Abbrechen</a>
  </div>
</form>

<script>
(function(){
  var tabs = document.querySelectorAll('#lieftabs a');
  tabs.forEach(function(t){
    t.addEventListener('click', function(e){
      e.preventDefault();
      tabs.forEach(function(x){ x.classList.remove('on'); });
      t.classList.add('on');
      document.querySelectorAll('[data-panel]').forEach(function(p){
        p.hidden = (p.getAttribute('data-panel') !== t.getAttribute('data-tab'));
      });
    });
  });
  // Ein Reiter aus dem Link (#dok, #rueckfragen) wird direkt geöffnet – z. B. nach dem Speichern dort.
  var hashTab = (location.hash || '').replace('#', '');
  if (hashTab) tabs.forEach(function(t){ if (t.getAttribute('data-tab') === hashTab) t.click(); });
  // „Fertige Produkte" -> Formen-Auswahl ein/ausblenden
  var katFertig = document.getElementById('kat_fertig');
  var formenBlock = document.getElementById('formenBlock');
  if (katFertig && formenBlock) {
    katFertig.addEventListener('change', function(){ formenBlock.hidden = !katFertig.checked; });
  }
})();
</script>
<?php if (!$neu): ?>
<?php // Dokumente und Rückfragen liegen außerhalb des Stammdaten-Formulars (eigene Formulare), die Reiter-Logik greift trotzdem. ?>
<section data-panel="dok" hidden>
  <?php if (isset($_GET['dok'])): ?><div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div><?php endif; ?>
  <?php if (isset($_GET['fehler'])): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px"><?= h((string)$_GET['fehler']) ?></div><?php endif; ?>
  <?= lieferant_dateien_panel((int)$id, 'team', 'de') ?>
</section>
<section data-panel="katalog" hidden id="katalog">
  <?php if (isset($_GET['katgelesen'])): ?><div class="bx-panel badge-ok" style="padding:12px 16px">Liste gelesen &ndash; <?= (int)$_GET['katgelesen'] ?> Zeile(n) stehen unten zur Pr&uuml;fung.</div><?php endif; ?>
  <?php if (isset($_GET['fehler'])): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px"><?= h((string)$_GET['fehler']) ?></div><?php endif; ?>
  <?php if (isset($_GET['kat'])): ?><div class="bx-panel badge-ok" style="padding:12px 16px"><?= (int)$_GET['kat'] ?> Zeile(n) übernommen – die Artikel stehen jetzt im Lager.</div><?php endif; ?>
  <div class="bx-panel">
    <div class="bx-row" style="justify-content:space-between;align-items:center">
      <h2 style="margin:0">Katalog des Lieferanten</h2>
      <?php $katZeilen = katalog_zeilen((int)$id); $katNeu = array_values(array_filter($katZeilen, fn($z) => $z['status'] === 'neu'));
            // eigene Zahlformatierung: die des Preis-Panels weiter unten gibt es hier noch nicht
            $katZahl = fn($x, $n2) => $x === null || $x === '' ? '–' : rtrim(rtrim(number_format((float)$x, $n2, ',', '.'), '0'), ','); ?>
      <?php if ($katNeu): ?>
        <form method="post" style="margin:0" onsubmit="return confirm('Alle <?= count($katNeu) ?> offenen Zeilen als Artikel anlegen?');">
          <input type="hidden" name="aktion" value="kat_alle">
          <button class="btn btn-ghost btn-sm" type="submit">Alle <?= count($katNeu) ?> übernehmen</button></form>
      <?php endif; ?>
    </div>
    <?php if (ki_bereit()): ?>
    <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:8px;align-items:center;margin:10px 0 0">
      <input type="hidden" name="aktion" value="kat_upload">
      <input type="file" name="dok" required accept=".pdf,.csv,.txt,.xlsx,.xls,image/*">
      <button class="btn btn-ghost btn-sm" type="submit">Preisliste einlesen</button>
      <span class="muted" style="font-size:12px">PDF, Excel oder CSV &ndash; die KI macht daraus Zeilen, angelegt wird nichts.</span>
    </form>
    <?php endif; ?>
    <p class="muted" style="margin-top:6px">Was der Lieferant in seinem Portal hinterlegt oder als Liste hochgeladen hat. <strong>Erst mit „Anlegen" entsteht daraus ein Artikel</strong> samt EK-Preis – vorher steht davon nichts im Lager.</p>
    <?php if (!$katZeilen): ?>
      <div class="muted">Noch nichts hinterlegt. Der Lieferant pflegt seinen Katalog unter <strong>Mein Katalog</strong> im Portal.</div>
    <?php else: ?>
    <div class="bx-tablewrap" style="margin-top:10px"><table class="bx-table">
      <thead><tr><th>Artikel</th><th>Typ</th><th>Spezifikation</th><th class="bx-num">Preis</th><th class="bx-num">ab Menge</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($katZeilen as $z):
              $offen = $z['status'] === 'neu';
              $treffer = $offen ? katalog_treffer($z) : null; ?>
        <tr>
          <td><?= h($z['name']) ?>
            <?php if ($z['herkunft'] || $z['notiz']): ?><div class="muted" style="font-size:12px"><?= h(trim((string)$z['herkunft'] . ' ' . (string)$z['notiz'])) ?></div><?php endif; ?>
            <?php if ($treffer): ?><div style="font-size:12px;color:#8a6d1f">gibt es vielleicht schon: <?= h($treffer['artikelnummer'] . ' ' . $treffer['name']) ?></div><?php endif; ?>
          </td>
          <td><?= h(anfrage_art_label($z['art'] === 'fertigprodukt' ? 'fertigprodukt' : 'rohstoff', (string)$z['form'])) ?></td>
          <td><?= h((string)$z['spezifikation']) ?></td>
          <td class="bx-num"><?= $z['preis'] !== null ? $katZahl($z['preis'], 4) . ' ' . h($z['waehrung']) . ($z['einheit'] ? ' / ' . h($z['einheit']) : '') : '–' ?></td>
          <td class="bx-num"><?= $z['menge_ab'] !== null ? $katZahl($z['menge_ab'], 3) : '–' ?></td>
          <td><?= $offen ? bx_badge('offen', 'info') : ($z['status'] === 'uebernommen' ? bx_badge('übernommen', 'ok') : bx_badge('abgelehnt')) ?>
            <?php if ($z['item_id']): ?><div style="font-size:12px"><a href="?p=rohstoff&id=<?= (int)$z['item_id'] ?>"><?= h((string)$z['artikelnummer']) ?></a></div><?php endif; ?>
          </td>
          <td class="bx-num"><?php if ($offen): ?>
            <form method="post" style="display:inline">
              <input type="hidden" name="aktion" value="kat_uebernehmen">
              <input type="hidden" name="zeile_id" value="<?= (int)$z['id'] ?>">
              <input type="hidden" name="preis_mit" value="1">
              <?php if ($treffer): ?><input type="hidden" name="item_id" value="<?= (int)$treffer['id'] ?>"><?php endif; ?>
              <button class="btn btn-primary btn-sm" type="submit"><?= $treffer ? 'Preis dorthin' : 'Anlegen' ?></button></form>
            <form method="post" style="display:inline">
              <input type="hidden" name="aktion" value="kat_ablehnen">
              <input type="hidden" name="zeile_id" value="<?= (int)$z['id'] ?>">
              <button class="btn btn-ghost btn-sm" type="submit">ablehnen</button></form>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</section>

<section data-panel="rueckfragen" hidden>
  <?php if (isset($_GET['nachricht'])): ?><div class="bx-panel badge-ok" style="padding:12px 16px">Nachricht gesendet.</div><?php endif; ?>
  <?php if (isset($_GET['fehler'])): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px"><?= h((string)$_GET['fehler']) ?></div><?php endif; ?>
  <?= nachricht_panel((int)$id, 'team', 'de', null, null, true) ?>
</section>
<?php endif; ?>
<?php if (!$neu):
  // --- Zugang zum Lieferantenportal ---
  $hatZugang  = lieferant_hat_zugang((int)$id);
  $offeneEinl = one("SELECT * FROM lieferant_einladung WHERE lieferant_id=? AND eingeloest=0 ORDER BY id DESC LIMIT 1", [(int)$id]);
  $basis = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
?>
<?php // Was der Lieferant selbst gepflegt hat – Logo und die Kontaktwege, die im Asiengeschaeft zaehlen. ?>
<?php if (!empty($l['logo']) || !empty($l['wechat']) || !empty($l['whatsapp'])): ?>
<div class="bx-panel">
  <h2 style="margin-top:0">Vom Lieferanten gepflegt</h2>
  <div class="bx-row" style="gap:24px;align-items:center;flex-wrap:wrap">
    <?php if (!empty($l['logo'])): ?><img src="?p=lieferant_logo&id=<?= (int)$id ?>" alt="" style="max-height:60px;max-width:200px"><?php endif; ?>
    <?php if (!empty($l['wechat'])): ?><div><div class="muted" style="font-size:12px">WeChat</div><div><?= h($l['wechat']) ?></div></div><?php endif; ?>
    <?php if (!empty($l['whatsapp'])): ?><div><div class="muted" style="font-size:12px">WhatsApp</div><div><?= h($l['whatsapp']) ?></div></div><?php endif; ?>
  </div>
</div>
<?php endif; ?>
<div class="bx-panel">
  <h2 style="margin-top:0">Zugang zum Lieferantenportal</h2>
  <?php if (isset($_GET['eingeladen'])): ?><div class="badge-ok" style="padding:8px 12px;margin-bottom:10px">Einladungslink erzeugt – bitte an den Lieferanten schicken.</div><?php endif; ?>
  <?php if (isset($_GET['gemailt'])): ?><div class="badge-ok" style="padding:8px 12px;margin-bottom:10px">Einladung per E-Mail verschickt.</div><?php endif; ?>
  <?php if (isset($_GET['mailfehler'])): ?><div style="border:1px solid #e6c4c0;color:#8f231b;padding:8px 12px;margin-bottom:10px;border-radius:8px">E-Mail nicht verschickt: <?= h((string)$_GET['mailfehler']) ?></div><?php endif; ?>
  <?php if ($hatZugang): ?>
    <p class="muted" style="margin-top:0">Dieser Lieferant hat einen Zugang und kann Bestellungen selbst bestätigen, den Fortschritt pflegen und Angebote abgeben.</p>
    <div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Benutzer</th><th>E-Mail</th><th>Letzter Login</th></tr></thead><tbody>
      <?php foreach (all("SELECT name,email,letzter_login FROM benutzer WHERE lieferant_id=? AND aktiv=1", [(int)$id]) as $bu): ?>
        <tr><td><?= h($bu['name']) ?></td><td><?= h($bu['email']) ?></td>
            <td><?= $bu['letzter_login'] ? h(fmt_zeit($bu['letzter_login'])) . ' Uhr' : '<span class="muted">noch nie</span>' ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  <?php else: ?>
    <p class="muted" style="margin-top:0">Noch kein Zugang. Erzeuge einen Einladungslink und schicke ihn an <strong><?= h($l['email'] ?: 'die hinterlegte E-Mail') ?></strong>. Der Lieferant setzt sein Passwort selbst; der Link gilt einmal.</p>
    <?php // In welcher Sprache schreiben wir? Das steht in den Stammdaten und gilt fuer Einladung, Portal und alle weiteren Mails.
          $sprLbl = ['de'=>'Deutsch', 'en'=>'English', 'zh'=>'中文 (Chinesisch)'][strtolower((string)($l['sprache'] ?? 'de'))] ?? 'English'; ?>
    <p class="muted" style="margin-top:-6px">Einladung und Portal laufen auf <strong><?= h($sprLbl) ?></strong>. Passt das nicht, unter <a href="#" onclick="document.querySelector('#lieftabs a[data-tab=stamm]').click();return false;">Stammdaten</a> die Sprache ändern, bevor du einlädst.</p>
    <?php if ($offeneEinl): ?>
      <div class="bx-field"><label>Einladungslink (offen)</label>
        <input type="text" readonly onclick="this.select()" value="<?= h($basis . '/?p=lieferant_einladung&token=' . $offeneEinl['token']) ?>" style="width:100%"></div>
    <?php endif; ?>
    <div class="bx-row" style="gap:10px;flex-wrap:wrap">
      <form method="post" style="margin:0"><input type="hidden" name="aktion" value="einladen">
      <button class="btn btn-primary" type="submit"><?= $offeneEinl ? 'Neuen Link erzeugen' : 'Einladungslink erzeugen' ?></button></form>
      <?php if (mail_bereit() && trim((string)$l['email']) !== ''): ?>
      <form method="post" style="margin:0"><input type="hidden" name="aktion" value="einladung_mailen">
        <button class="btn btn-ghost" type="submit">Einladung per E-Mail senden</button></form>
      <?php elseif (!mail_bereit()): ?><span class="muted" style="font-size:12px;align-self:center">E-Mail-Versand ist noch nicht eingerichtet (Einstellungen &rarr; E-Mail).</span><?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0">Preisanfragen</h2>
  <?php if (isset($_GET['angefragt'])): ?><div class="badge-ok" style="padding:8px 12px;margin-bottom:10px">Anfrage gestellt – der Lieferant sieht sie in seinem Portal.</div><?php endif; ?>
  <?php if (isset($_GET['uebernommen'])): ?><div class="badge-ok" style="padding:8px 12px;margin-bottom:10px">Angebot angenommen – die Preise stehen jetzt als EK-Staffeln am Artikel.</div><?php endif; ?>
  <?php if (isset($_GET['fehler'])): ?><div style="border:1px solid #e6c4c0;color:#8f231b;padding:8px 12px;margin-bottom:10px;border-radius:8px"><?= h((string)$_GET['fehler']) ?></div><?php endif; ?>
  <?php
  // Artikel mit ihrer Bezugsgröße – daraus füllt das Formular die Einheit selbst.
  $anfItems = all("SELECT id, name, kategorie, einheit, preis_bezug FROM item WHERE kategorie IN ('rohstoff','fertig','verpackung','verbrauch') AND gesperrt=0 ORDER BY kategorie, name");
  $itemEinheit = []; $itemArt = [];
  $katLbl = ['rohstoff'=>'Rohstoff', 'fertig'=>'Fertigprodukt', 'verpackung'=>'Verpackung', 'verbrauch'=>'Verbrauch'];
  foreach ($anfItems as $it) {
      $itemEinheit[(int)$it['id']] = trim((string)($it['preis_bezug'] ?: $it['einheit']));
      $itemArt[(int)$it['id']] = anfrage_art_fuer_item((int)$it['id']);
  }
  $formEinheit = [];
  foreach (array_keys(anfrage_formen()) as $fk) $formEinheit[$fk] = anfrage_einheit_fuer_form($fk);
  $formenJeArt = ['fertigprodukt' => array_keys(anfrage_formen_fuer_art('fertigprodukt')), 'rohstoff' => array_keys(anfrage_formen_fuer_art('rohstoff'))];
  $itemForm = [];
  foreach ($anfItems as $it) $itemForm[(int)$it['id']] = anfrage_form_fuer_item((int)$it['id']);
  ?>
  <form method="post" class="bx-grid" style="margin-bottom:14px" id="anfrageForm">
    <input type="hidden" name="aktion" value="anfrage">
    <div class="bx-field"><label>Was fragen wir an? <?= bx_hint('Bestimmt die Felder darunter und die Einheit, in der der Lieferant seinen Preis nennt.') ?></label>
      <select name="art" id="anf_art">
        <?php foreach (anfrage_arten() as $k => $lbl): ?><option value="<?= h($k) ?>"<?= $k === 'rohstoff' ? ' selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?>
      </select></div>
    <div class="bx-field" id="anf_form_feld" hidden><label id="anf_form_label">Form</label>
      <select name="form" id="anf_form">
        <?php foreach (anfrage_formen() as $k => $lbl): ?><option value="<?= h($k) ?>"><?= h($lbl) ?></option><?php endforeach; ?>
      </select></div>
    <div class="bx-field"><label>Artikel <?= bx_hint('Mit Artikel landen die Preise beim Annehmen automatisch als EK-Staffeln dort. Ohne Artikel ist es eine Freitext-Anfrage.') ?></label>
      <select name="item_id" id="anf_item"><option value="">– Freitext –</option>
        <?php foreach ($anfItems as $it): ?>
          <option value="<?= (int)$it['id'] ?>" data-art="<?= h($itemArt[(int)$it['id']]) ?>"><?= h($katLbl[$it['kategorie']] ?? $it['kategorie']) ?>: <?= h($it['name']) ?> (<?= h($itemEinheit[(int)$it['id']] ?: '–') ?>)</option>
        <?php endforeach; ?></select></div>
    <div class="bx-field"><label>Betreff (bei Freitext)</label><input type="text" name="betreff" maxlength="190" id="anf_betreff"></div>
    <div class="bx-field" id="anf_stk_feld" hidden><label>Einheiten je Packung <?= bx_hint('z. B. 90 Kapseln je Dose – nur zur Information für den Lieferanten') ?></label>
      <input type="number" name="stueck_je_packung" min="1" placeholder="z. B. 90"></div>
    <div class="bx-field" id="anf_kg_feld" hidden><label>Kapselgröße</label>
      <select name="kapselgroesse_id"><option value="">– offen –</option>
        <?php foreach (all("SELECT id, name FROM kapselgroesse ORDER BY sort") as $kg): ?><option value="<?= (int)$kg['id'] ?>"><?= h($kg['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="bx-field" id="anf_rez_feld" hidden><label>Rezeptur als Vorlage <?= bx_hint('optional – dann weiß der Lieferant, was hineinsoll') ?></label>
      <select name="rezeptur_id"><option value="">– keine –</option>
        <?php foreach (all("SELECT id, name, darreichungsform FROM rezeptur ORDER BY name") as $rz): ?><option value="<?= (int)$rz['id'] ?>" data-form="<?= h($rz['darreichungsform']) ?>"><?= h($rz['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="bx-field"><label>Menge <span id="anf_menge_hint" class="muted" style="font-weight:normal"></span></label><input type="text" name="menge" placeholder="z. B. 500"></div>
    <div class="bx-field"><label>Einheit <?= bx_hint('kommt automatisch aus Artikel bzw. Darreichungsform – nur überschreiben, wenn es abweicht') ?></label>
      <input type="text" name="einheit" id="anf_einheit" placeholder="kg"></div>
    <div class="bx-field" style="grid-column:1/-1"><label>Notiz an den Lieferanten</label><input type="text" name="notiz" maxlength="500"></div>
    <div class="bx-field"><label>CoA / Spezifikation</label>
      <div class="bx-check" style="padding-top:8px"><input type="checkbox" name="coa" id="anf_coa" value="1" checked><label for="anf_coa" style="margin:0">mit anfragen</label></div></div>
    <div class="bx-field" style="align-self:end"><button class="btn btn-primary" type="submit">Anfrage stellen</button></div>
  </form>
  <script>
  (function(){
    // Die Einheit tippt niemand ab: sie kommt aus dem Artikel, sonst aus der Darreichungsform.
    var art = document.getElementById('anf_art'), form = document.getElementById('anf_form'),
        item = document.getElementById('anf_item'), einheit = document.getElementById('anf_einheit'),
        formFeld = document.getElementById('anf_form_feld'), stkFeld = document.getElementById('anf_stk_feld'),
        kgFeld = document.getElementById('anf_kg_feld'), rezFeld = document.getElementById('anf_rez_feld'),
        hint = document.getElementById('anf_menge_hint');
    if (!art) return;
    var itemEinheit = <?= json_encode($itemEinheit, JSON_UNESCAPED_UNICODE) ?>;
    var formEinheit = <?= json_encode($formEinheit, JSON_UNESCAPED_UNICODE) ?>;
    var formenJeArt = <?= json_encode($formenJeArt, JSON_UNESCAPED_UNICODE) ?>;
    var itemForm    = <?= json_encode($itemForm, JSON_UNESCAPED_UNICODE) ?>;
    var formLabel   = document.getElementById('anf_form_label');
    var alleFormen  = Array.prototype.slice.call(form.options).map(function(o){ return {v: o.value, t: o.text}; });
    function formenSetzen(art){
      // Rohstoff und Fertigprodukt haben unterschiedliche Formen – die Auswahl zeigt nur die passenden.
      var erlaubt = formenJeArt[art] || [], alt = form.value;
      form.innerHTML = '';
      alleFormen.forEach(function(o){
        if (erlaubt.indexOf(o.v) === -1) return;
        var el = document.createElement('option'); el.value = o.v; el.text = o.t;
        if (o.v === alt) el.selected = true;
        form.appendChild(el);
      });
      formLabel.textContent = art === 'rohstoff' ? 'Lieferform' : 'Darreichungsform';
    }
    var handEingabe = false;
    einheit.addEventListener('input', function(){ handEingabe = einheit.value.trim() !== ''; });
    function aktualisieren(){
      var istFertig = art.value === 'fertigprodukt', hatForm = !!(formenJeArt[art.value] || []).length;
      formFeld.hidden = !hatForm;
      stkFeld.hidden  = !istFertig;
      rezFeld.hidden  = !istFertig;
      kgFeld.hidden   = !(istFertig && (form.value === 'kapsel' || form.value === 'softgel'));
      var iid = parseInt(item.value || '0', 10);
      // Stückware wird in ihrer Form bepreist, Schüttgut nach der Bezugsgröße des Artikels.
      var stueckForm = ['kapsel','tablette','softgel','stick'].indexOf(form.value) !== -1;
      var e = (hatForm && stueckForm) ? (formEinheit[form.value] || '')
            : (itemEinheit[iid] || (hatForm ? (formEinheit[form.value] || '') : (art.value === 'sonstiges' ? '' : 'Stück')));
      if (!handEingabe) einheit.value = e;
      hint.textContent = e ? '(in ' + e + ')' : '';
    }
    art.addEventListener('change', function(){ formenSetzen(art.value); aktualisieren(); });
    form.addEventListener('change', aktualisieren);
    item.addEventListener('change', function(){
      var opt = item.options[item.selectedIndex], a = opt && opt.getAttribute('data-art');
      if (a) { art.value = a; }        // der Artikel sagt selbst, was er ist
      formenSetzen(art.value);
      var iid = parseInt(item.value || '0', 10);
      if (itemForm[iid] && (formenJeArt[art.value] || []).indexOf(itemForm[iid]) !== -1) form.value = itemForm[iid];
      handEingabe = false;
      aktualisieren();
    });
    var rez = rezFeld.querySelector('select');
    rez.addEventListener('change', function(){
      var opt = rez.options[rez.selectedIndex], f = opt && opt.getAttribute('data-form');
      if (f && formEinheit[f] !== undefined) { form.value = f; handEingabe = false; aktualisieren(); }
    });
    formenSetzen(art.value);
    aktualisieren();
  })();
  </script>

  <?php $anfr = all("SELECT af.*, i.name AS item_name, ag.id AS ang_id, ag.preis, ag.einheit AS ang_einheit,
                            ag.mindestmenge, ag.lieferzeit_tage, ag.status AS ang_status, ag.preis_basis AS ang_basis
                     FROM lieferant_anfrage af LEFT JOIN item i ON i.id=af.item_id
                     LEFT JOIN lieferant_angebot ag ON ag.anfrage_id=af.id
                     WHERE af.lieferant_id=? ORDER BY af.angelegt DESC", [(int)$id]);
  // Nachkommanullen weg – aber nur, wenn es ueberhaupt Nachkommastellen gibt. Sonst wuerde
  // aus 1.000 die Zahl '1.', weil der Tausenderpunkt mit abgeschnitten wird.
  $zahl = fn($x, $n2) => $x === null || $x === '' ? '–'
      : ($n2 > 0 ? rtrim(rtrim(number_format((float)$x, $n2, ',', '.'), '0'), ',') : number_format((float)$x, 0, ',', '.'));
  if (!$anfr): ?><div class="muted">Noch keine Anfragen an diesen Lieferanten.</div>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Nummer</th><th>Artikel / Betreff</th><th>Produkttyp</th><th class="bx-num">Angefragt</th><th>Antwort</th><th>Status</th><th></th></tr></thead>
    <tbody><?php foreach ($anfr as $r): ?>
      <tr><td><?= h($r['nummer']) ?></td>
          <td><?= h(($r['item_name'] ?? '') !== '' ? $r['item_name'] : ($r['betreff'] ?? '–')) ?>
              <?php if ($r['stueck_je_packung']): ?><div class="muted" style="font-size:12px"><?= (int)$r['stueck_je_packung'] ?> <?= h(einheit_wort($r['einheit'] ?? '', (float)$r['stueck_je_packung'])) ?> je Packung<?= $r['kapselgroesse_id'] ? ' · ' . h((string) scalar("SELECT name FROM kapselgroesse WHERE id=?", [(int)$r['kapselgroesse_id']])) : '' ?></div><?php endif; ?></td>
          <td><?php $lbl = anfrage_art_label((string)($r['art'] ?? ''), (string)($r['form'] ?? '')); echo $lbl !== '' ? h($lbl) : '<span class="muted">–</span>'; ?></td>
          <td class="bx-num"><?= $r['menge'] ? $zahl($r['menge'], 3) . ' ' . h(einheit_wort($r['einheit'] ?? '', (float)$r['menge'])) : '–' ?></td>
          <td><?php if ($r['ang_id']): $basis = (int)($r['ang_basis'] ?? 1) === 1000 ? 1000 : 1; ?><strong><?= $zahl($r['preis'], 4) ?> €</strong> / <?= $basis === 1000 ? '1.000 ' : '' ?><?= h($r['ang_einheit'] ? einheit_wort($r['ang_einheit'], $basis) : '–') ?>
                <div class="muted" style="font-size:12px">
                  <?= $r['mindestmenge'] ? 'MOQ ' . $zahl($r['mindestmenge'], 3) . ' · ' : '' ?>
                  <?= $r['lieferzeit_tage'] ? (int)$r['lieferzeit_tage'] . ' Tage' : '' ?>
                  <?php $stf = all("SELECT menge_ab,preis FROM lieferant_angebot_staffel WHERE angebot_id=? ORDER BY menge_ab", [(int)$r['ang_id']]);
                        if ($stf) { $tx = []; foreach ($stf as $s) $tx[] = $zahl($s['menge_ab'], 0) . '+: ' . $zahl($s['preis'], 4) . ' €'; echo '<br>' . h(implode(' · ', $tx)); } ?>
                </div>
              <?php else: ?><span class="muted">–</span><?php endif; ?></td>
          <td><?= $r['status'] === 'offen' ? bx_badge('offen', 'info') : ($r['status'] === 'beantwortet' ? bx_badge('beantwortet', 'warn') : bx_badge('übernommen', 'ok')) ?></td>
          <td class="bx-num"><?php if ($r['ang_id'] && $r['ang_status'] !== 'angenommen' && $r['item_id']): ?>
                <form method="post" style="margin:0" onsubmit="return confirm('Angebot annehmen? Die Preise ersetzen die bisherigen EK-Staffeln dieses Lieferanten für den Artikel.');">
                  <input type="hidden" name="aktion" value="angebot_annehmen"><input type="hidden" name="angebot_id" value="<?= (int)$r['ang_id'] ?>">
                  <button class="btn btn-primary btn-sm" type="submit">Preise übernehmen</button></form>
              <?php endif; ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php
render_footer();
