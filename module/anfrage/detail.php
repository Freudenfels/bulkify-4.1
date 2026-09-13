<?php
// Rezepturanfrage bearbeiten: Kundenwunsch -> Rohstoff-Zuordnung + Kapsel-Check -> Rezeptur erstellen
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$DFORM = ['kapsel'=>'Kapsel','tablette'=>'Tablette','softgel'=>'Softgel','stick'=>'Stick','pulver'=>'Pulver','fluessig'=>'Flüssig'];
$KAPSELFORMEN = ['kapsel','tablette','softgel'];
$id  = $_GET['id'] ?? 'neu';
$neu = ($id === 'neu' || !is_numeric($id));

// Wunsch-Zeilen aus dem Formular lesen
function wunsch_rows_from_post(): array {
    $out = [];
    $bez = $_POST['w_bez'] ?? []; $wm = $_POST['w_menge'] ?? []; $we = $_POST['w_einheit'] ?? [];
    $wn = $_POST['w_notiz'] ?? []; $wi = $_POST['w_item'] ?? []; $wf = $_POST['w_final'] ?? [];
    foreach ($bez as $i => $b) {
        $b = trim($b); $item = (int)($wi[$i] ?? 0);
        if ($b === '' && $item <= 0) continue;
        $out[] = ['bez'=>$b, 'menge'=>trim($wm[$i] ?? ''), 'einheit'=>trim($we[$i] ?? ''), 'notiz'=>trim($wn[$i] ?? ''),
                  'item_id'=>$item ?: null, 'final'=>trim($wf[$i] ?? '') === '' ? null : (float)str_replace(',', '.', $wf[$i])];
    }
    return $out;
}

// Rezepturvorschlag der KI: entwickeln und – nach Prüfung – als Wunschzeilen übernehmen.
// Beides läuft VOR dem allgemeinen Speichern, damit es die Zeilen nicht doppelt schreibt.
$kiFehler = '';
if (!$neu && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'ki_entwickeln') {
    require_once BX_ROOT . '/core/rezeptur_ki.php';
    $r = rezeptur_ki_entwickeln((int)$id);
    if ($r['ok']) { rezeptur_ki_merken((int)$id, $r); header('Location: ?p=anfrage&id=' . (int)$id . '&kiok=1'); exit; }
    header('Location: ?p=anfrage&id=' . (int)$id . '&kifehler=' . urlencode($r['fehler'])); exit;
}
if (!$neu && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'ki_zeilen') {
    require_once BX_ROOT . '/core/rezeptur_ki.php';
    $vor = rezeptur_ki_vorschlag((int)$id);
    $n = $vor ? rezeptur_ki_zeilen_uebernehmen((int)$id, (array)($vor['zutaten'] ?? [])) : 0;
    header('Location: ?p=anfrage&id=' . (int)$id . '&kizeilen=' . $n); exit;
}

// Kunde direkt aus der Anfrage anlegen und verknuepfen (Daten vorher pruefbar/editierbar).
// Alle weiteren Kundenfelder haben DB-Defaults; portal_rezeptur ist standardmaessig an.
if (!$neu && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'kunde_anlegen') {
    $g = fn($k) => trim((string)($_POST[$k] ?? ''));
    if ($g('neu_firma') === '') { header('Location: ?p=anfrage&id=' . (int)$id . '&kfehler=1'); exit; }
    q("INSERT INTO kunden (kundennummer, firma, ansprechpartner, email, telefon) VALUES (?,?,?,?,?)",
      [naechste_nummer('K'), mb_substr($g('neu_firma'), 0, 190),
       $g('neu_ansprechpartner') ?: null, $g('neu_email') ?: null, $g('neu_telefon') ?: null]);
    $kid = insert_id();
    q("UPDATE rezeptur_anfrage SET kunde_id=? WHERE id=?", [$kid, (int)$id]);
    $anr = (string) scalar("SELECT nummer FROM rezeptur_anfrage WHERE id=?", [(int)$id]);
    log_aktivitaet('kunde', $kid, 'team', 'Kunde aus Rezepturanfrage ' . $anr . ' angelegt und verknüpft.', 'notiz');
    header('Location: ?p=anfrage&id=' . (int)$id . '&kunde_neu=1'); exit;
}

$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = fn($k) => trim($_POST[$k] ?? '');
    $kunde_id = ($_POST['kunde_id'] ?? '') !== '' ? (int)$_POST['kunde_id'] : null;
    $form = $f('darreichungsform') ?: 'kapsel';
    if ($neu) {
        q("INSERT INTO rezeptur_anfrage (nummer,kunde_id,darreichungsform,produktname,notiz,status) VALUES (?,?,?,?,?,?)",
          [naechste_nummer('RZA'), $kunde_id, $form, $f('produktname') ?: null, $f('notiz'), $f('status') ?: 'neu']);
        $id = insert_id();
    } else {
        q("UPDATE rezeptur_anfrage SET kunde_id=?,darreichungsform=?,produktname=?,notiz=?,status=? WHERE id=?",
          [$kunde_id, $form, $f('produktname') ?: null, $f('notiz'), $f('status'), (int)$id]);
    }
    $rows = wunsch_rows_from_post();
    q("DELETE FROM rezeptur_anfrage_wunsch WHERE anfrage_id=?", [(int)$id]);
    foreach ($rows as $i => $r) {
        q("INSERT INTO rezeptur_anfrage_wunsch (anfrage_id,bezeichnung,wunsch_menge,einheit,notiz,item_id,menge_final,sort)
           VALUES (?,?,?,?,?,?,?,?)", [(int)$id, $r['bez'], $r['menge'], $r['einheit'], $r['notiz'], $r['item_id'], $r['final'], $i]);
    }

    if (($_POST['aktion'] ?? '') === 'rezeptur_erstellen') {
        // Schutz gegen Duplikate: hat die Anfrage schon einen Vorschlag, dort weiterarbeiten statt neu anlegen.
        $vorhanden = (int) scalar("SELECT rezeptur_id FROM rezeptur_anfrage WHERE id=?", [(int)$id]);
        if ($vorhanden > 0) { header('Location: ?p=rezeptur_detail&id=' . $vorhanden); exit; }
        // Ohne zugeordnete Rohstoffe kein Vorschlag – sonst bekäme der Kunde einen leeren Vorschlag.
        $zutatRows = array_values(array_filter($rows, fn($r) => $r['item_id'] && $r['final'] !== null));
        if (!$zutatRows) { header('Location: ?p=anfrage&id=' . $id . '&leer=1'); exit; }
        $name = $f('rez_name') ?: $f('produktname') ?: ('Rezeptur aus ' . scalar("SELECT nummer FROM rezeptur_anfrage WHERE id=?", [(int)$id]));
        $anr  = scalar("SELECT nummer FROM rezeptur_anfrage WHERE id=?", [(int)$id]);
        // Direkt als Vorschlag anlegen = an den Kunden gesendet (er sieht ihn sofort im Portal).
        q("INSERT INTO rezeptur (nummer,name,kunde_id,darreichungsform,status,notiz) VALUES (?,?,?,?,?,?)",
          [naechste_nummer('RZ'), $name, $kunde_id, $form, 'vorschlag', 'Aus Anfrage ' . $anr]);
        $rid = insert_id();
        $pos = 0;
        foreach ($zutatRows as $r) {
            $bez = scalar("SELECT name FROM item WHERE id=?", [$r['item_id']]);
            q("INSERT INTO rezeptur_zutat (rezeptur_id,item_id,bezeichnung,menge_mg,sort) VALUES (?,?,?,?,?)",
              [$rid, $r['item_id'], $bez, $r['final'], $pos++]);
        }
        q("UPDATE rezeptur_anfrage SET rezeptur_id=?, status='beantwortet' WHERE id=?", [$rid, (int)$id]);
        if ($kunde_id) log_aktivitaet('kunde', $kunde_id, 'team', 'Vorschlag aus Anfrage ' . $anr . ' erstellt und an den Kunden gesendet.', 'rezeptur', 'rezeptur', $rid);
        header('Location: ?p=rezeptur_detail&id=' . $rid . '&gesendet=1'); exit;
    }
    $anker = preg_replace('/[^a-z0-9_-]/i', '', (string)($_POST['anker'] ?? ''));   // an der Arbeitsstelle bleiben statt oben
    header('Location: ?p=anfrage&id=' . $id . '&ok=1' . ($anker !== '' ? '#' . $anker : '')); exit;
}

$a = $neu ? ['darreichungsform'=>'kapsel','status'=>'neu'] : one("SELECT * FROM rezeptur_anfrage WHERE id=?", [(int)$id]);
if (!$a) { $neu = true; $a = ['darreichungsform'=>'kapsel','status'=>'neu']; }
$v = fn($k) => h((string)($a[$k] ?? ''));
$form = $a['darreichungsform'] ?? 'kapsel';
$istKapsel = in_array($form, $KAPSELFORMEN, true);

$kunden = all("SELECT id, firma FROM kunden ORDER BY firma");
$rohstoffe = all("SELECT id, name, artikelnummer, synonym, cas FROM item WHERE kategorie='rohstoff' AND gesperrt=0 ORDER BY name");
$kapseln = all("SELECT * FROM kapselgroesse ORDER BY sort, fuellmenge_mg");
$wuensche = $neu ? [] : all("SELECT * FROM rezeptur_anfrage_wunsch WHERE anfrage_id=? ORDER BY sort, id", [(int)$id]);
// Auto-Zuordnung vorschlagen, wo noch keine da ist
foreach ($wuensche as &$w) if (!$w['item_id']) $w['item_id'] = anfrage_auto_item($w['bezeichnung']);
unset($w);

function rohstoff_options(array $rohstoffe, $sel): string {
    $s = '<option value="">– Rohstoff wählen –</option>';
    foreach ($rohstoffe as $r) {
        $lbl = $r['name'] . ($r['cas'] ? ' · CAS ' . $r['cas'] : '');
        $s .= '<option value="' . (int)$r['id'] . '"' . ((int)$sel === (int)$r['id'] ? ' selected' : '') . '>' . h($lbl) . '</option>';
    }
    return $s;
}

render_header('anfragen', $neu ? 'Neue Anfrage' : ($a['nummer'] ?? 'Anfrage'));
bx_head($neu ? 'Neue Rezepturanfrage' : $v('nummer'),
        $neu ? 'Kundenwunsch erfassen' : 'Anfrage bearbeiten',
        bx_btn('Zurück zur Liste', '?p=anfragen', 'ghost'));
if (!$neu && !empty($a['angelegt'])) echo '<div class="muted" style="font-size:12px;margin:-6px 0 10px">Angefragt am ' . h(fmt_zeit($a['angelegt'], 'd.m.Y H:i')) . ' Uhr</div>';
if (isset($_GET['ok'])) echo '<div id="bxToast" class="badge-ok" style="position:fixed;top:16px;right:16px;z-index:9999;padding:10px 16px;border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.18);font-weight:600">Gespeichert.</div>';
if (isset($_GET['leer'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Bitte mindestens einen Rohstoff zuordnen (mit Menge in mg), bevor du den Vorschlag sendest.</div>';
if (!$neu && ($a['status'] ?? '') === 'ueberarbeiten') {
    $g = !empty($a['rezeptur_id']) ? (string) scalar("SELECT ablehnung_grund FROM rezeptur WHERE id=?", [(int)$a['rezeptur_id']]) : '';
    echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px"><strong>Vorschlag vom Kunden abgelehnt.</strong>'
       . ($g !== '' ? ' Grund: ' . h($g) : '')
       . ' Bitte die Rezeptur überarbeiten und erneut als Vorschlag senden.'
       . (!empty($a['rezeptur_id']) ? ' <a href="?p=rezeptur_detail&id=' . (int)$a['rezeptur_id'] . '">Vorschlag öffnen</a>' : '')
       . '</div>';
}
?>
<?php // Rezepturvorschlag der KI. Steht VOR dem Formular, weil er es füttert.
if (!$neu):
  require_once BX_ROOT . '/core/rezeptur_ki.php';
  $kiVor = rezeptur_ki_vorschlag((int)$id);
  $badge = fn($w) => in_array($w, ['unproblematisch', 'im Rahmen', 'gut'], true) ? 'ok'
                    : (in_array($w, ['novel_food', 'zu hoch', 'nicht machbar'], true) ? 'warn' : 'info');
?>
<?php if (isset($_GET['kiok'])): ?><div class="bx-panel badge-ok" style="padding:12px 16px">Vorschlag entwickelt – bitte unten prüfen.</div><?php endif; ?>
<?php if (isset($_GET['kizeilen'])): ?><div class="bx-panel badge-ok" style="padding:12px 16px"><?= (int)$_GET['kizeilen'] ?> Zutat(en) in die Wunschzeilen übernommen. Jetzt prüfen und speichern.</div><?php endif; ?>
<?php if (isset($_GET['kifehler'])): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px"><?= h((string)$_GET['kifehler']) ?></div><?php endif; ?>
<div class="bx-panel" style="border-color:var(--gruen)">
  <div class="bx-row" style="justify-content:space-between;align-items:center">
    <h2 style="margin:0">Rezeptur entwickeln (KI)</h2>
    <?php if (ki_bereit()): ?>
      <form method="post" style="margin:0"><input type="hidden" name="aktion" value="ki_entwickeln">
        <button class="btn <?= $kiVor ? 'btn-ghost' : 'btn-primary' ?> btn-sm" type="submit"><?= $kiVor ? 'Neu entwickeln' : 'Vorschlag entwickeln' ?></button></form>
    <?php endif; ?>
  </div>
  <?php // Ein vorhandener Vorschlag wird IMMER gezeigt – auch wenn gerade kein Schluessel da ist.
        if (!$kiVor && !ki_bereit()): ?>
    <div class="muted">Die KI ist nicht eingerichtet (Einstellungen &rarr; KI).</div>
  <?php elseif (!$kiVor): ?>
    <p class="muted" style="margin:8px 0 0">Aus der Idee des Kunden (Feld Notiz) entsteht ein Vorschlag mit Zutaten und Mengen, dazu eine Novel-Food-Einschätzung, ein Blick auf die Höchstmengen und die Machbarkeit. <strong>Ein Entwurf für dich – keine rechtliche Freigabe.</strong></p>
  <?php else: ?>
    <p class="muted" style="margin:8px 0 12px">Entwickelt am <?= h(date('d.m.Y H:i', strtotime((string)$kiVor['stand']))) ?> · <?= h($kiVor['modell'] ?? '') ?>. <strong>Entwurf – die rechtliche Bewertung prüfst du.</strong></p>
    <?php if ($kiVor['name']): ?><div><strong>Vorschlag:</strong> <?= h($kiVor['name']) ?><?= $kiVor['tagesdosis'] ? ' · ' . h($kiVor['tagesdosis']) : '' ?></div><?php endif; ?>

    <?php if ($kiVor['zutaten']): ?>
    <div class="bx-tablewrap" style="margin-top:10px"><table class="bx-table">
      <thead><tr><th>Zutat</th><th class="bx-num">mg je Einheit</th><th>Funktion</th><th>Rohstoff bei uns</th></tr></thead>
      <tbody><?php foreach ($kiVor['zutaten'] as $z): ?>
        <tr><td><?= h($z['bezeichnung']) ?><?php if ($z['begruendung']): ?><div class="muted" style="font-size:12px"><?= h($z['begruendung']) ?></div><?php endif; ?></td>
            <td class="bx-num"><?= h(rtrim(rtrim(number_format((float)$z['menge_mg'], 3, ',', '.'), '0'), ',')) ?></td>
            <td><?= h($z['funktion']) ?></td>
            <td><?= $z['item_id'] ? h($z['item_name']) : '<span class="muted">nicht im Katalog</span>' ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>

    <?php if (!empty($kiVor['kapsel'])): $k = $kiVor['kapsel']; ?>
      <div style="margin-top:10px"><strong>Füllgewicht:</strong> <?= h(number_format($k['fuellgewicht_mg'], 1, ',', '.')) ?> mg je Kapsel –
        <?= $k['passt'] ? 'passt in ' . h((string)$k['groesse']) : 'passt in KEINE unserer Kapseln (größte: ' . h((string)$k['groesste']) . ' mit ' . h(number_format((float)$k['groesste_mg'], 0, ',', '.')) . ' mg)' ?>
        <span class="muted" style="font-size:12px">– selbst nachgerechnet, nicht von der KI</span>
      </div>
    <?php endif; ?>

    <?php foreach ([['novel_food', 'Novel Food', ['stoff','bewertung','begruendung']],
                    ['hoechstmengen', 'Höchstmengen', ['stoff','menge_mg','bewertung','begruendung']],
                    ['health_claims', 'Health Claims', ['stoff','claim','zulaessig']]] as [$key, $titel, $sp]):
            $rows = (array)($kiVor[$key] ?? []); if (!$rows) continue; ?>
      <div style="margin-top:14px"><strong><?= h($titel) ?></strong></div>
      <div class="bx-tablewrap" style="margin-top:6px"><table class="bx-table"><tbody>
        <?php foreach ($rows as $z): ?>
          <tr><td style="width:220px"><?= h((string)$z[$sp[0]]) ?></td>
              <td><?php if ($key === 'health_claims'): ?>
                    <?= h((string)$z['claim']) ?> <?= !empty($z['zulaessig']) ? bx_badge('zulässig', 'ok') : bx_badge('nicht bestätigt', 'warn') ?>
                  <?php else: ?>
                    <?= isset($z['menge_mg']) && $z['menge_mg'] !== '' ? h($z['menge_mg']) . ' mg · ' : '' ?>
                    <?= bx_badge((string)$z['bewertung'], $badge((string)$z['bewertung'])) ?>
                    <?php if (!empty($z['begruendung'])): ?><div class="muted" style="font-size:12px"><?= h((string)$z['begruendung']) ?></div><?php endif; ?>
                  <?php endif; ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
    <?php endforeach; ?>

    <?php if (!empty($kiVor['machbarkeit']['bewertung'])): ?>
      <div style="margin-top:14px"><strong>Machbarkeit:</strong> <?= bx_badge((string)$kiVor['machbarkeit']['bewertung'], $badge((string)$kiVor['machbarkeit']['bewertung'])) ?></div>
      <ul class="muted" style="margin:6px 0 0;font-size:13px"><?php foreach ((array)$kiVor['machbarkeit']['gruende'] as $g): ?><li><?= h($g) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if (!empty($kiVor['hinweise'])): ?>
      <ul class="muted" style="margin:10px 0 0;font-size:13px"><?php foreach ((array)$kiVor['hinweise'] as $g): ?><li><?= h($g) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>

    <?php if ($kiVor['zutaten']): ?>
    <form method="post" style="margin-top:14px" onsubmit="return confirm('Die bisherigen Wunschzeilen werden durch die Zutaten des Vorschlags ersetzt.');">
      <input type="hidden" name="aktion" value="ki_zeilen">
      <button class="btn btn-primary" type="submit">Zutaten in die Wunschzeilen übernehmen</button>
      <span class="muted" style="font-size:12px;margin-left:8px">danach unten prüfen, speichern und den Vorschlag senden</span>
    </form>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (isset($_GET['kunde_neu'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Kunde angelegt und mit der Anfrage verknüpft.</div>'; ?>
<?php if (!$neu && empty($a['kunde_id'])): ?>
<div class="bx-panel" style="border-color:var(--gruen)">
  <h2 style="margin-top:0">Kunde anlegen &amp; verknüpfen</h2>
  <p class="muted" style="margin-top:0">Diese Anfrage hat noch keinen Kunden. Daten prüfen bzw. ergänzen und anlegen – oder unten einen bestehenden Kunden auswählen.</p>
  <?php if (isset($_GET['kfehler'])): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:10px 14px;margin-bottom:10px">Firma ist ein Pflichtfeld.</div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="aktion" value="kunde_anlegen">
    <div class="bx-grid">
      <div class="bx-field"><label>Firma</label><input type="text" name="neu_firma" required placeholder="Firmenname"></div>
      <div class="bx-field"><label>Ansprechpartner</label><input type="text" name="neu_ansprechpartner"></div>
      <div class="bx-field"><label>E-Mail</label><input type="email" name="neu_email"></div>
      <div class="bx-field"><label>Telefon</label><input type="text" name="neu_telefon"></div>
    </div>
    <button class="btn btn-primary" type="submit">Kunde anlegen &amp; verknüpfen</button>
  </form>
</div>
<?php endif; ?>

<form method="post" class="bx-form" id="block-zuordnung">
  <input type="hidden" name="anker" value="block-zuordnung">
  <div class="bx-panel"><div class="bx-grid">
    <div class="bx-field"><label>Kunde</label>
      <select name="kunde_id"><option value="">– keiner –</option>
        <?php foreach ($kunden as $k): ?><option value="<?= $k['id'] ?>" <?= (int)($a['kunde_id']??0)===(int)$k['id']?'selected':'' ?>><?= h($k['firma']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="bx-field"><label>Wunsch-Produktname <?= bx_hint('vom Kunden gewünschter Name; Vorschlag für den Rezepturnamen') ?></label><input type="text" name="produktname" value="<?= h((string)($a['produktname'] ?? '')) ?>" placeholder="z. B. Immun-Komplex Forte"></div>
    <div class="bx-field"><label>Darreichungsform</label>
      <select name="darreichungsform" onchange="this.form.submit()">
        <?php foreach ($DFORM as $key=>$lbl): ?><option value="<?= $key ?>" <?= $form===$key?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="bx-field"><label>Status</label>
      <select name="status">
        <?php foreach (['neu'=>'neu','in_bearbeitung'=>'in Bearbeitung','beantwortet'=>'beantwortet','ueberarbeiten'=>'Vorschlag abgelehnt – überarbeiten','abgelehnt'=>'abgelehnt'] as $key=>$lbl): ?>
          <option value="<?= $key ?>" <?= ($a['status']??'')===$key?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="bx-field"><label>Rezepturname (für die Erstellung)</label><input type="text" name="rez_name" placeholder="z. B. Immun-Komplex"></div>
  </div>
  <div class="bx-field"><label>Kundenwunsch / Notiz</label><textarea name="notiz"><?= $v('notiz') ?></textarea></div>
  </div>

  <div class="bx-panel">
    <h2>Wunsch → Zuordnung <?= bx_hint('links der Kundenwunsch (Laiensprache), rechts unsere Zuordnung zum echten Rohstoff + finale Menge je '.($istKapsel?'Kapsel':'Portion')) ?></h2>
    <table class="bx-table">
      <thead><tr>
        <th colspan="3" class="th-wunsch">Wunsch des Kunden</th>
        <th colspan="2" class="th-zuordnung">Unsere Zuordnung</th><th></th>
      </tr>
      <tr><th>Bezeichnung</th><th style="width:90px">Menge</th><th style="width:70px">Einh.</th>
          <th>Rohstoff (mit CAS)</th><th style="width:130px">Menge (mg)</th><th></th></tr></thead>
      <tbody id="wrows">
        <?php $wr = $wuensche ?: [['bezeichnung'=>'','wunsch_menge'=>'','einheit'=>'mg','notiz'=>'','item_id'=>'','menge_final'=>'']]; foreach ($wr as $w): ?>
        <tr class="wrow">
          <td><input type="text" name="w_bez[]" value="<?= h($w['bezeichnung']) ?>" placeholder="z. B. Vitamin C"></td>
          <td><input type="text" name="w_menge[]" value="<?= h($w['wunsch_menge']) ?>"></td>
          <td><select name="w_einheit[]" style="width:72px"><?php foreach (['mg','g','µg','IE','ml'] as $eh): ?><option value="<?= $eh ?>" <?= ($w['einheit'] ?? 'mg')===$eh?'selected':'' ?>><?= $eh ?></option><?php endforeach; ?></select></td>
          <td><select name="w_item[]"><?= rohstoff_options($rohstoffe, $w['item_id']) ?></select></td>
          <td><input type="number" step="0.001" class="wfinal" name="w_final[]" value="<?= h($w['menge_final']!==''&&$w['menge_final']!==null ? rtrim(rtrim(number_format((float)$w['menge_final'],3,'.',''),'0'),'.') : '') ?>"></td>
          <td><button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('.wrow').remove();kcheck()">×</button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <button type="button" class="btn btn-ghost btn-sm" id="addW">+ Zeile</button>
  </div>

  <div class="bx-panel" id="kapselpanel" <?= $istKapsel ? '' : 'style="display:none"' ?>>
    <h2>Kapsel-Check</h2>
    <div class="bx-row" style="gap:20px;align-items:center">
      <div class="bx-field" style="margin:0"><label>Zielgröße</label>
        <select id="kgroesse">
          <?php foreach ($kapseln as $kg): ?><option value="<?= (int)$kg['fuellmenge_mg'] ?>" <?= $kg['name']==='Größe 0'?'selected':'' ?>><?= h($kg['name']) ?> (<?= (int)$kg['fuellmenge_mg'] ?> mg)</option><?php endforeach; ?>
        </select>
      </div>
      <div>Summe je <?= $istKapsel?'Kapsel':'Portion' ?>: <strong id="ksumme">0 mg</strong></div>
      <div id="kstatus"></div>
    </div>
    <div class="muted" id="ksplit" style="margin-top:8px"></div>
  </div>
  <?php if (!$istKapsel): ?>
  <div class="bx-panel muted">Bei <?= h($DFORM[$form]) ?> rechnen wir pro <strong>Portion</strong> (z. B. 1 Löffel/Stick) – kein Kapsel-Limit.</div>
  <?php endif; ?>

  <div class="bx-row" style="margin-top:var(--sp-4)">
    <button class="btn btn-ghost" type="submit">Speichern</button>
    <?php if (!$neu && !empty($a['rezeptur_id'])): ?>
      <a class="btn btn-primary" href="?p=rezeptur_detail&id=<?= (int)$a['rezeptur_id'] ?>">Vorschlag öffnen</a>
      <span class="muted" style="font-size:12px;align-self:center">Zu dieser Anfrage gibt es bereits einen Vorschlag – dort überarbeiten (nicht neu erstellen). Erst mit Kundenzustimmung wird daraus eine Rezeptur.</span>
    <?php else: ?>
      <button class="btn btn-primary" type="submit" name="aktion" value="rezeptur_erstellen">Vorschlag erstellen &amp; senden</button>
    <?php endif; ?>
    <a class="btn btn-ghost" href="?p=anfragen">Abbrechen</a>
  </div>
</form>

<style>
  .rs-combo{position:relative}
  .rs-input{width:100%;box-sizing:border-box}
  .rs-list{position:absolute;left:0;top:100%;z-index:40;min-width:260px;max-width:440px;max-height:300px;overflow:auto;background:var(--panel);border:1px solid var(--line);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.16);margin-top:3px}
  .rs-list .rs-opt{padding:7px 11px;cursor:pointer;font-size:14px}
  .rs-list .rs-opt:hover,.rs-list .rs-opt.hl{background:var(--row-hover)}
  .rs-list .rs-opt .muted{font-size:12px}
  .rs-empty{padding:8px 12px;color:var(--muted);font-size:13px}
</style>
<script>
var OPTIONS =<?= json_encode(rohstoff_options($rohstoffe, 0), JSON_UNESCAPED_UNICODE) ?>;
// Aufgabe 2: durchsuchbare Rohstoff-Auswahl. Daten einmal eingebettet; das <select name="w_item[]">
// bleibt (versteckt) erhalten -> gleicher gespeicherter Wert, funktioniert auch ohne JS.
var ROHSTOFFE = <?= json_encode(array_map(fn($r)=>['id'=>(int)$r['id'],'n'=>(string)$r['name'],'a'=>(string)($r['artikelnummer'] ?? ''),'s'=>(string)($r['synonym'] ?? ''),'c'=>(string)($r['cas'] ?? '')], $rohstoffe), JSON_UNESCAPED_UNICODE) ?>;
function bxNorm(s){ return String(s).toLowerCase().replace(/ä/g,'ae').replace(/ö/g,'oe').replace(/ü/g,'ue').replace(/ß/g,'ss'); }
function bxEsc(s){ return String(s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
function bxRohstoffCombo(sel){
  if(!sel || sel.dataset.combo) return; sel.dataset.combo='1'; sel.style.display='none';
  var wrap=document.createElement('div'); wrap.className='rs-combo';
  sel.parentNode.insertBefore(wrap, sel); wrap.appendChild(sel);
  var box=document.createElement('input'); box.type='text'; box.className='rs-input'; box.autocomplete='off'; box.placeholder='Rohstoff suchen…';
  var cur=sel.options[sel.selectedIndex]; if(cur && sel.value) box.value=cur.textContent;
  var list=document.createElement('div'); list.className='rs-list'; list.hidden=true;
  wrap.appendChild(box); wrap.appendChild(list);
  var hl=-1, shown=[];
  function render(q){
    q=bxNorm((q||'').trim());
    shown = !q ? ROHSTOFFE.slice(0,50) : ROHSTOFFE.filter(function(r){ return bxNorm(r.n+' '+r.a+' '+r.s+' '+r.c).indexOf(q)>=0; }).slice(0,50);
    if(!shown.length){ list.innerHTML='<div class="rs-empty">Kein Rohstoff gefunden.</div>'; }
    else list.innerHTML=shown.map(function(r,i){ var sub=[r.a, r.c?('CAS '+r.c):'', r.s].filter(Boolean).join(' · '); return '<div class="rs-opt" data-i="'+i+'">'+bxEsc(r.n)+(sub?' <span class="muted">('+bxEsc(sub)+')</span>':'')+'</div>'; }).join('');
    hl=-1; list.hidden=false;
  }
  function paint(){ Array.prototype.forEach.call(list.querySelectorAll('.rs-opt'),function(o){o.classList.toggle('hl',+o.dataset.i===hl);}); var e=list.querySelector('.rs-opt.hl'); if(e)e.scrollIntoView({block:'nearest'}); }
  function pick(i){ var r=shown[i]; if(!r)return; sel.value=r.id; box.value=r.n; list.hidden=true; }
  box.addEventListener('input',function(){ sel.value=''; render(box.value); });
  box.addEventListener('focus',function(){ render(box.value); });
  box.addEventListener('keydown',function(e){
    if(list.hidden){ if(e.key==='ArrowDown'){ render(box.value); } return; }
    if(e.key==='ArrowDown'){ e.preventDefault(); hl=Math.min(hl+1,shown.length-1); paint(); }
    else if(e.key==='ArrowUp'){ e.preventDefault(); hl=Math.max(hl-1,0); paint(); }
    else if(e.key==='Enter'){ if(hl>=0){ e.preventDefault(); pick(hl); } }
    else if(e.key==='Escape'){ list.hidden=true; }
  });
  list.addEventListener('mousedown',function(e){ var o=e.target.closest('.rs-opt'); if(o){ e.preventDefault(); pick(+o.dataset.i); } });
  document.addEventListener('click',function(e){ if(!wrap.contains(e.target)) list.hidden=true; });
}
function nf(x){ return x.toLocaleString('de-DE'); }
function kcheck(){
  var panel=document.getElementById('kapselpanel');
  if (!panel || panel.style.display==='none') return;
  var total=0; document.querySelectorAll('.wfinal').forEach(function(i){ total += parseFloat((i.value||'').replace(',','.'))||0; });
  var cap=parseInt(document.getElementById('kgroesse').value)||0;
  document.getElementById('ksumme').textContent=nf(total)+' mg';
  var st=document.getElementById('kstatus'), sp=document.getElementById('ksplit');
  if (!total || !cap){ st.innerHTML=''; sp.textContent=''; return; }
  if (total<=cap){ st.innerHTML='<span class="badge badge-ok">passt</span>'; sp.textContent=''; }
  else {
    var n=Math.ceil(total/cap);
    st.innerHTML='<span class="badge badge-err">passt nicht</span>';
    sp.textContent='Vorschlag: auf '+n+' Kapseln/Tag aufteilen (je ~'+nf(Math.round(total/n))+' mg).';
  }
}
(function(){
  document.getElementById('addW').addEventListener('click', function(){
    var tr=document.createElement('tr'); tr.className='wrow';
    tr.innerHTML='<td><input type="text" name="w_bez[]"></td><td><input type="text" name="w_menge[]"></td>'
      +'<td><select name="w_einheit[]" style="width:72px"><option>mg</option><option>g</option><option>µg</option><option>IE</option><option>ml</option></select></td>'
      +'<td><select name="w_item[]">'+OPTIONS+'</select></td>'
      +'<td><input type="number" step="0.001" class="wfinal" name="w_final[]"></td>'
      +'<td><button type="button" class="btn btn-ghost btn-sm">×</button></td>';
    tr.querySelector('button').addEventListener('click',function(){tr.remove();kcheck();});
    tr.querySelector('.wfinal').addEventListener('input',kcheck);
    document.getElementById('wrows').appendChild(tr);
    bxRohstoffCombo(tr.querySelector('select[name="w_item[]"]'));   // neue Zeile: Suche aktivieren
  });
  document.querySelectorAll('.wfinal').forEach(function(i){i.addEventListener('input',kcheck);});
  var kg=document.getElementById('kgroesse'); if(kg) kg.addEventListener('change',kcheck);
  document.querySelectorAll('select[name="w_item[]"]').forEach(bxRohstoffCombo);   // bestehende Zeilen aufwerten
  kcheck();
})();
// Aufgabe 1: Nach dem Speichern an der Arbeitsstelle bleiben (Scroll merken + wiederherstellen) + Toast ausblenden.
(function(){
  var KEY='anfrage-scroll-<?= (int)$id ?>';
  // Vor jedem Absenden die aktuelle Scroll-Position merken (alle Formulare der Seite).
  document.querySelectorAll('form').forEach(function(f){
    f.addEventListener('submit', function(){ try{ sessionStorage.setItem(KEY, String(window.scrollY)); }catch(e){} });
  });
  var params=new URLSearchParams(location.search);
  // Nach einem Redirect mit Statusmeldung: exakt an die gemerkte Stelle zurueck (ueberschreibt den Anker-Sprung).
  var flash=['ok','kizeilen','kiok','kifehler','kunde_neu','leer'].some(function(p){ return params.has(p); });
  if(flash){
    try{ var y=sessionStorage.getItem(KEY); if(y!==null){ window.scrollTo(0, parseInt(y,10)||0); sessionStorage.removeItem(KEY); } }catch(e){}
    var t=document.getElementById('bxToast');
    if(t){ setTimeout(function(){ t.style.transition='opacity .5s'; t.style.opacity='0'; setTimeout(function(){ t.remove(); },600); }, 2600); }
  }
})();
</script>
<?php render_footer(); ?>
