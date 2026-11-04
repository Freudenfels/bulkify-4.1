<?php
// Eigene Gebinde-/Karton-Aufkleber (Spec 5.6). Zwei Modi in einer Seite:
//   ?p=gebinde&nr=GB-00012   -> Scan-Auflösung: Nummer -> Charge -> Produkt/Wareneingang/Lieferant.
//   ?p=gebinde&charge=<id>   -> Verwaltung: vorhandene Gebinde einer Charge + N neue erzeugen + Etiketten.
// Zweck: Material ohne Hersteller-Charge (Gläser, Deckel) wird je Gebinde rückverfolgbar ("Ersatz-Charge").

// --- Neue Gebinde erzeugen --------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'erzeugen') {
    $cid    = (int)($_POST['charge_id'] ?? 0);
    $anzahl = max(1, min(500, (int)($_POST['anzahl'] ?? 1)));
    $c = erp_charge_voll($cid);
    if (!$c) { flash('Charge nicht gefunden.', 'warn'); weiter('?p=bestand'); }
    $ref = trim(((string)($c['charge_nr'] ?? '')) . ($c['wareneingang'] ? ' · ' . date('d.m.Y', strtotime((string)$c['wareneingang'])) : ''));
    $neu = lg_gebinde_anlegen($cid, $anzahl, (int)($c['lieferant_id'] ?? 0) ?: null, $ref);
    if (function_exists('lg_charge_log_add')) lg_charge_log_add($cid, 'Gebinde-Aufkleber', '', '+' . count($neu) . ' (GB)');
    flash(count($neu) . ' Gebinde-Aufkleber erzeugt.', 'ok');
    weiter('?p=gebinde&charge=' . $cid . '&neu=' . count($neu));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'loeschen') {
    $gid = (int)($_POST['gebinde_id'] ?? 0);
    $cid = (int)($_POST['charge_id'] ?? 0);
    if ($gid > 0) lg_gebinde_del($gid);
    flash('Gebinde-Aufkleber entfernt.', 'ok');
    weiter('?p=gebinde&charge=' . $cid);
}

// --- Gewicht & Bestand pro Gebinde (04.11.2026): Entnahme und Rückgabe/Nachwiegen GENAU dieses Kartons ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['aktion'] ?? ''), ['geb_entnehmen', 'geb_rueckgabe'], true)) {
    $gid = (int)($_POST['gebinde_id'] ?? 0);
    if (($_POST['aktion'] ?? '') === 'geb_entnehmen') {
        $r = erp_gebinde_entnehmen($gid, erp_menge_parse((string)($_POST['menge'] ?? '0')), trim((string)($_POST['grund'] ?? '')));
    } elseif (trim((string)($_POST['ist'] ?? '')) === '') {
        $r = ['ok' => false, 'meldung' => 'Bitte das gewogene Ist-Gewicht eintragen.'];
    } elseif (empty($_POST['verschlossen'])) {
        $r = ['ok' => false, 'meldung' => 'Bitte bestätigen, dass das Gebinde ordentlich verschlossen ist.'];
    } else {
        $r = erp_gebinde_rueckgabe($gid, erp_menge_parse((string)$_POST['ist']), ((string)($_POST['anlass'] ?? '')) === 'nachwiegen' ? 'Nachwiegen' : 'Rückgabe');
    }
    flash((string)$r['meldung'], !empty($r['ok']) ? 'ok' : 'warn');
    weiter('?p=gebinde&bg=' . $gid);
}

// --- Etikettenrolle einem Auftrag zuordnen / wieder freigeben (Rückverfolgung) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'rolle_zuordnen') {
    $gid = (int)($_POST['gebinde_id'] ?? 0);
    $aid = (int)($_POST['auftrag_id'] ?? 0);
    $r = function_exists('erp_rolle_auftrag_zuordnen') ? erp_rolle_auftrag_zuordnen($gid, $aid) : ['ok' => false, 'meldung' => 'Nicht verfügbar.'];
    flash($r['meldung'], $r['ok'] ? 'ok' : 'warn');
    weiter('?p=gebinde&inv=' . rawurlencode((string)($_POST['inv'] ?? '')));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'rolle_freigeben') {
    $gid = (int)($_POST['gebinde_id'] ?? 0);
    $r = function_exists('erp_rolle_freigeben') ? erp_rolle_freigeben($gid) : ['ok' => false, 'meldung' => 'Nicht verfügbar.'];
    flash($r['meldung'], $r['ok'] ? 'ok' : 'warn');
    weiter('?p=gebinde&inv=' . rawurlencode((string)($_POST['inv'] ?? '')));
}

// --- Modus I: Inventaraufkleber (8 Ziffern, Altdaten 7) auflösen -> Etikettenrolle -------------------------
$inv = function_exists('erp_inventar_norm') ? erp_inventar_norm((string)($_GET['inv'] ?? '')) : preg_replace('/\D+/', '', (string)($_GET['inv'] ?? ''));
if (($_GET['inv'] ?? '') !== '') {
    $r = function_exists('erp_rolle_per_inventar') ? erp_rolle_per_inventar((string)$_GET['inv']) : null;
    kopf('Rolle ' . $inv, 'bestand');
    seitenkopf('Etikettenrolle ' . $inv, 'Gescannter Inventaraufkleber – Etikett, Kunde/Auftrag und Bestand.');
    flash_zeigen();
    if (!$r):
        hinweis('Kein Inventaraufkleber „' . h($inv) . '" gefunden.', 'warn');
    else:
        $c = erp_charge_voll((int)$r['charge_id']);
        $kundeTxt = !empty($r['fremd_kunde_id']) ? erp_kunde_name((int)$r['fremd_kunde_id']) : '';
        $auftragId = (int)($c['auftrag_id'] ?? 0);
        $mengeTxt = menge_txt($r['menge_verfuegbar']) . ' ' . (string)($r['einheit'] ?? '');
        $statLbl  = ['voll'=>'voll', 'angebrochen'=>'angebrochen', 'leer'=>'leer'][(string)$r['status']] ?? (string)$r['status'];
        $zugeordnet = (int)($r['auftrag_id'] ?? 0) > 0;
    ?>
    <div class="bx-panel" style="margin-bottom:var(--sp-5)">
      <h2 style="margin-top:0">Etikettenrolle</h2>
      <table class="bx-table"><tbody>
        <tr><td class="muted" style="width:200px">Inventaraufkleber</td><td class="lg-code" style="font-weight:600"><?= h($inv) ?></td></tr>
        <tr><td class="muted">Etikett</td><td><a class="lg-namelink" href="?p=charge&id=<?= (int)$r['charge_id'] ?>"><?= h((string)($r['item_name'] ?? '')) ?></a></td></tr>
        <tr><td class="muted">Menge auf der Rolle</td><td style="font-weight:600"><?= h($mengeTxt) ?></td></tr>
        <tr><td class="muted">Status</td><td><?= h($statLbl) ?></td></tr>
        <tr><td class="muted">Rolle</td><td><?= (int)$r['nummer'] ?> / <?= (int)$r['anzahl_gesamt'] ?></td></tr>
        <?php if ($kundeTxt !== ''): ?><tr><td class="muted">Kunde (Fremdlager)</td><td><?= h($kundeTxt) ?></td></tr><?php endif; ?>
        <tr><td class="muted">Zugeordneter Auftrag</td><td><?= $zugeordnet ? h((string)($r['auftrag_nr'] ?? ('#' . (int)$r['auftrag_id']))) : '<span class="muted">– frei –</span>' ?></td></tr>
        <tr><td class="muted">Charge</td><td class="lg-code"><?= h((string)($r['charge_nr'] ?? '')) ?: '–' ?></td></tr>
        <tr><td class="muted">Lieferant</td><td><?= h((string)($r['lieferant'] ?? '')) ?: '–' ?></td></tr>
        <tr><td class="muted">Wareneingang</td><td><?= !empty($r['wareneingang']) ? h(date('d.m.Y', strtotime((string)$r['wareneingang']))) : '–' ?></td></tr>
      </tbody></table>
      <div class="bx-row" style="gap:var(--sp-2);margin-top:var(--sp-3);flex-wrap:wrap">
        <a class="btn btn-primary" href="?p=charge&id=<?= (int)$r['charge_id'] ?>">Zur Charge</a>
        <?php if (!$zugeordnet && $auftragId > 0 && (string)$r['status'] !== 'leer'): ?>
        <form method="post" style="display:inline">
          <input type="hidden" name="aktion" value="rolle_zuordnen">
          <input type="hidden" name="gebinde_id" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="auftrag_id" value="<?= $auftragId ?>">
          <input type="hidden" name="inv" value="<?= h($inv) ?>">
          <button type="submit" class="btn btn-ghost">Rolle dem Auftrag <?= h((string)($c['auftrag_nr'] ?? ('#' . $auftragId))) ?> zuordnen</button>
        </form>
        <?php elseif ($zugeordnet): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Zuordnung dieser Rolle wieder lösen?')">
          <input type="hidden" name="aktion" value="rolle_freigeben">
          <input type="hidden" name="gebinde_id" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="inv" value="<?= h($inv) ?>">
          <button type="submit" class="btn btn-ghost">Zuordnung lösen</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php
    endif;
    fuss();
    return;
}

// --- Modus 0: Scan-Auflösung eines BESTANDS-Gebindes (?bg=<charge_gebinde.id>) -----------------
// Teil 4: der QR auf den Wareneingangs-Etiketten führt auf GENAU dieses Gebinde (Restmenge/Ort).
$bg = (int)($_GET['bg'] ?? 0);
if ($bg > 0) {
    $g = function_exists('erp_charge_gebinde') ? erp_charge_gebinde($bg) : null;
    $c = $g ? erp_charge_voll((int)$g['charge_id']) : null;
    kopf('Gebinde', 'bestand');
    seitenkopf('Gebinde' . ($g ? ' ' . (int)$g['nummer'] . ' / ' . (int)$g['anzahl_gesamt'] : ''),
        'Gescanntes Gebinde – Restmenge, Ort und Zuordnung zur Charge.');
    flash_zeigen();
    if (!$g):
        hinweis('Dieses Gebinde wurde nicht gefunden (evtl. gelöschte Charge).', 'warn');
    else:
        $ortLbl  = ['lager1'=>'Lager 1', 'produktion'=>'Produktion', 'lager2'=>'Lager 2'][(string)$g['standort']] ?? (string)$g['standort'];
        $statLbl = ['voll'=>'voll', 'angebrochen'=>'angebrochen', 'leer'=>'leer'][(string)$g['status']] ?? (string)$g['status'];
        $fmtG = fn($v) => rtrim(rtrim(number_format((float)$v, 3, ',', '.'), '0'), ',');
        $einhG = (string)($g['einheit'] ?? '');
        $mengeTxt = $fmtG($g['menge_verfuegbar']) . ' ' . $einhG;
        $liefTxt  = (string)($c['lieferant'] ?? '') . (($c['lieferant_nr'] ?? '') ? ' · ' . (string)$c['lieferant_nr'] : '');
        // Lagerort: Kiste bzw. Blinker der Charge, dazu der Ort des Gebindes (Lager 1 / Produktion).
        $platz = '';
        if (function_exists('blinker_fuer_charge')) {
            $bf = blinker_fuer_charge((int)$g['charge_id']);
            if (!empty($bf['kiste'])) $platz = 'Kiste ' . (string)($bf['kiste']['kiste_name'] ?? '');
            elseif (!empty($bf['leiste'])) $platz = 'Blinker ' . (string)($bf['leiste']['code'] ?? '');
        }
        $istRolle = !empty($g['inventar_nr']);
        $gewogen  = !empty($g['gewogen']);
        $istGew   = (bool) preg_match('/^(kg|g|mg|l|ml)$/i', trim($einhG));
    ?>
    <div class="bx-panel" style="margin-bottom:var(--sp-5)">
      <h2 style="margin-top:0">Gebinde <?= (int)$g['nummer'] ?> / <?= (int)$g['anzahl_gesamt'] ?></h2>
      <table class="bx-table"><tbody>
        <tr><td class="muted" style="width:200px"><?= $istGew ? 'Aktuelles Gewicht' : 'Aktuelle Menge' ?></td><td style="font-weight:600;font-size:20px"><?= h($mengeTxt) ?>
          <span class="muted" style="font-weight:400;font-size:13px"><?= $gewogen ? '· gewogen' . (!empty($g['gewogen_am']) ? ' am ' . h(fmt_zeit((string)$g['gewogen_am'])) : '') : '· gleichmäßig verteilt (nachwiegen)' ?></span></td></tr>
        <tr><td class="muted">Ursprünglich</td><td><?= h($fmtG($g['menge']) . ' ' . $einhG) ?></td></tr>
        <tr><td class="muted">Ort</td><td><?= h($ortLbl) ?><?= $platz !== '' ? ' · ' . h($platz) : '' ?></td></tr>
        <tr><td class="muted">Status</td><td><?= h($statLbl) ?></td></tr>
        <?php if ($c): ?>
        <tr><td class="muted">Produkt / Material</td><td><a class="lg-namelink" href="?p=charge&id=<?= (int)$g['charge_id'] ?>"><?= h((string)($c['item_name'] ?? '')) ?></a></td></tr>
        <tr><td class="muted">Charge (Lieferant)</td><td class="lg-code"><?= h((string)($c['charge_nr'] ?? '')) ?: '–' ?></td></tr>
        <tr><td class="muted">Lieferant</td><td><?= h($liefTxt) ?: '–' ?></td></tr>
        <tr><td class="muted">MHD</td><td><?= $c['mhd'] ? h(date('d.m.Y', strtotime((string)$c['mhd']))) : '–' ?></td></tr>
        <tr><td class="muted">Wareneingang</td><td><?= $c['wareneingang'] ? h(date('d.m.Y', strtotime((string)$c['wareneingang']))) : '–' ?></td></tr>
        <?php endif; ?>
      </tbody></table>
      <?php if ($c): ?>
      <div class="bx-row" style="gap:var(--sp-2);margin-top:var(--sp-3)">
        <a class="btn btn-primary" href="?p=charge&id=<?= (int)$g['charge_id'] ?>">Zur Charge</a>
        <a class="btn btn-ghost" href="?p=etikett&ids=<?= (int)$g['charge_id'] ?>">Etiketten der Charge</a>
      </div>
      <?php endif; ?>
    </div>
    <?php if ($c && !$istRolle): ?>
    <div class="bx-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:var(--sp-4);margin-bottom:var(--sp-5)">
      <form method="post" class="bx-panel" style="margin:0">
        <input type="hidden" name="aktion" value="geb_entnehmen"><input type="hidden" name="gebinde_id" value="<?= (int)$g['id'] ?>">
        <h3 style="margin-top:0">Entnehmen</h3>
        <p class="muted" style="margin-top:0">Bucht genau dieses Gebinde ab (und die Charge um dieselbe Menge).</p>
        <div class="bx-row" style="gap:var(--sp-2);align-items:flex-end;flex-wrap:wrap">
          <div class="bx-field" style="margin:0;flex:0 1 160px"><label>Menge (<?= h($einhG) ?>)</label><input type="text" name="menge" inputmode="decimal" placeholder="0" required></div>
          <div class="bx-field" style="margin:0;flex:1 1 160px"><label>Zweck <span class="muted">(optional)</span></label><input type="text" name="grund" placeholder="z. B. Auftrag, Mischen"></div>
          <button class="btn btn-primary" type="submit">Entnehmen</button>
        </div>
      </form>
      <form method="post" class="bx-panel" style="margin:0">
        <input type="hidden" name="aktion" value="geb_rueckgabe"><input type="hidden" name="gebinde_id" value="<?= (int)$g['id'] ?>">
        <h3 style="margin-top:0"><?= (string)$g['standort'] === 'produktion' ? 'Rückgabe aus der Produktion' : 'Nachwiegen' ?></h3>
        <p class="muted" style="margin-top:0">Gebinde wiegen und das Ist-Gewicht eintragen. Das Gebinde steht danach auf diesem Wert, die Differenz wird als Verbrauch bzw. Schwund gebucht.</p>
        <input type="hidden" name="anlass" value="<?= (string)$g['standort'] === 'produktion' ? 'rueckgabe' : 'nachwiegen' ?>">
        <div class="bx-row" style="gap:var(--sp-2);align-items:flex-end;flex-wrap:wrap">
          <div class="bx-field" style="margin:0;flex:0 1 160px"><label>Ist-Gewicht (<?= h($einhG) ?>)</label><input type="text" name="ist" inputmode="decimal" placeholder="0" required></div>
          <label style="display:flex;gap:8px;align-items:center;flex:1 1 200px;padding-bottom:10px"><input type="checkbox" name="verschlossen" value="1" required style="width:20px;height:20px"> Gebinde ist ordentlich verschlossen</label>
          <button class="btn btn-primary" type="submit">Speichern</button>
        </div>
      </form>
    </div>
    <?php endif; ?>
    <?php
    endif;
    fuss();
    return;
}

// --- Modus 1: Scan-Auflösung (?nr=) -----------------------------------------------------------
$nr = strtoupper(trim((string)($_GET['nr'] ?? '')));
if ($nr !== '') {
    $g = lg_gebinde_per_nummer($nr);
    $c = $g ? erp_charge_voll((int)$g['charge_id']) : null;

    kopf('Gebinde ' . $nr, 'bestand');
    seitenkopf('Gebinde ' . $nr, 'Gescannter Gebinde-Aufkleber – Rückschluss auf Produkt und Wareneingang.');
    flash_zeigen();
    if (!$g):
        hinweis('Keine Gebinde-Nummer „' . $nr . '" gefunden. Vielleicht gehört der Aufkleber zu einer gelöschten Charge.', 'warn');
    else:
        $liefTxt = (string)($c['lieferant'] ?? '') . (($c['lieferant_nr'] ?? '') ? ' · ' . (string)$c['lieferant_nr'] : '');
    ?>
    <div class="bx-panel" style="margin-bottom:var(--sp-5)">
      <h2 style="margin-top:0">Zuordnung</h2>
      <table class="bx-table"><tbody>
        <tr><td class="muted" style="width:200px">Gebinde-Nummer</td><td class="lg-code"><?= h($nr) ?> <span class="muted">(Gebinde <?= (int)$g['laufnr'] ?>)</span></td></tr>
        <?php if ($c): ?>
        <tr><td class="muted">Produkt / Material</td><td><a class="lg-namelink" href="?p=charge&id=<?= (int)$g['charge_id'] ?>"><?= h((string)($c['item_name'] ?? '')) ?></a></td></tr>
        <tr><td class="muted">Charge (Lieferant)</td><td class="lg-code"><?= h((string)($c['charge_nr'] ?? '')) ?: '–' ?></td></tr>
        <tr><td class="muted">Lieferant</td><td><?= h($liefTxt) ?: '–' ?></td></tr>
        <tr><td class="muted">Wareneingang</td><td><?= $c['wareneingang'] ? h(date('d.m.Y', strtotime((string)$c['wareneingang']))) : '–' ?></td></tr>
        <tr><td class="muted">Erfasst am</td><td><?= h(fmt_zeit((string)$g['angelegt'])) ?></td></tr>
        <?php else: ?>
        <tr><td class="muted">Charge</td><td class="muted">Die zugehörige Charge gibt es nicht mehr (Ref.: <?= h((string)($g['wareneingang_ref'] ?? '')) ?>).</td></tr>
        <?php endif; ?>
      </tbody></table>
      <?php if ($c): ?>
      <div class="bx-row" style="gap:var(--sp-2);margin-top:var(--sp-3)">
        <a class="btn btn-primary" href="?p=charge&id=<?= (int)$g['charge_id'] ?>">Zur Charge</a>
        <a class="btn btn-ghost" href="?p=gebinde&charge=<?= (int)$g['charge_id'] ?>">Alle Gebinde dieser Charge</a>
      </div>
      <?php endif; ?>
    </div>
    <?php
    endif;
    fuss();
    return;
}

// --- Modus 2: Verwaltung je Charge (?charge=) -------------------------------------------------
$cid = (int)($_GET['charge'] ?? 0);
$c = erp_charge_voll($cid);
if (!$c) { flash('Bitte eine Charge wählen.', 'warn'); weiter('?p=bestand'); }
$geb   = lg_gebinde_liste($cid);
$pakete = function_exists('lg_pakete') ? lg_pakete($cid) : 1;
$vorschlag = max(1, count($geb) > 0 ? 1 : $pakete);

kopf('Gebinde – ' . (string)$c['charge_nr'], 'bestand');
seitenkopf((string)$c['item_name'], 'Eigene Gebinde-Aufkleber (QR + Nummer) – für Verpackung ohne Hersteller-Charge.',
    '<a class="btn btn-ghost" href="?p=charge&id=' . $cid . '">Zur Charge</a>');
flash_zeigen();
?>
<div class="bx-panel" style="margin-bottom:var(--sp-5)">
  <h2 style="margin-top:0">Neue Gebinde-Aufkleber erzeugen</h2>
  <p class="muted" style="margin-top:0">Jedes Gebinde (z. B. jeder Karton) bekommt einen eigenen Aufkleber mit eigenem QR-Code und eigener Nummer. Beim Scan in der Produktion lässt sich darüber der Wareneingang/Lieferant zuordnen (Regress).</p>
  <form method="post" class="bx-row" style="gap:var(--sp-3);align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="aktion" value="erzeugen">
    <input type="hidden" name="charge_id" value="<?= $cid ?>">
    <div class="bx-field" style="margin:0;max-width:160px">
      <label>Anzahl Gebinde</label>
      <input type="number" name="anzahl" min="1" max="500" step="1" value="<?= (int)$vorschlag ?>">
    </div>
    <button type="submit" class="btn btn-primary">Aufkleber erzeugen</button>
    <?php if ($geb): ?><a class="btn btn-ghost" href="?p=gebinde_etikett&charge=<?= $cid ?>" target="_blank">Alle Etiketten öffnen (PDF)</a><?php endif; ?>
  </form>
  <?php if ($pakete > 1): ?><div class="muted" style="font-size:12px;margin-top:var(--sp-2)">Diese Lieferung ist als <?= (int)$pakete ?> Karton(s) erfasst.</div><?php endif; ?>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0">Vorhandene Gebinde (<?= count($geb) ?>)</h2>
  <?php if (!$geb): ?>
    <div class="muted">Für diese Charge gibt es noch keine Gebinde-Aufkleber.</div>
  <?php else: ?>
  <div class="bx-tablewrap">
    <table class="bx-table lg-karten">
      <thead><tr><th>Nummer</th><th>Gebinde</th><th>Erfasst</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($geb as $g): ?>
        <tr>
          <td data-label="Nummer" class="lg-code"><?= h((string)$g['nummer']) ?></td>
          <td data-label="Gebinde"><?= (int)$g['laufnr'] ?> / <?= count($geb) ?></td>
          <td data-label="Erfasst" class="muted"><?= h(fmt_zeit((string)$g['angelegt'])) ?></td>
          <td data-label="" style="text-align:right">
            <a class="btn btn-ghost btn-sm" href="?p=gebinde&nr=<?= rawurlencode((string)$g['nummer']) ?>">Auflösen</a>
            <form method="post" style="display:inline" onsubmit="return confirm('Diesen Gebinde-Aufkleber entfernen?')">
              <input type="hidden" name="aktion" value="loeschen"><input type="hidden" name="gebinde_id" value="<?= (int)$g['id'] ?>"><input type="hidden" name="charge_id" value="<?= $cid ?>">
              <button class="btn btn-ghost btn-sm lg-x" type="submit" title="Entfernen">×</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php
fuss();
