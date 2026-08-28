<?php
// Verpackung anlegen & bearbeiten (Item mit kategorie=verpackung)
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$VART = ['dose'=>'Dose','flasche'=>'Flasche','blister'=>'Blister','beutel'=>'Beutel/Doypack','stick'=>'Stick','karton'=>'Karton','etikett'=>'Etikett'];
$id  = $_GET['id'] ?? 'neu';
$neu = ($id === 'neu' || !is_numeric($id));

$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'fuell_save' && is_numeric($id)) {
    // Füllmengen speichern: Kapseln je Größe + Pulver-Füllgewicht (g) + Flüssig-Volumen (ml)
    seed_kapselgroesse_if_empty();
    q("DELETE FROM pack_kapazitaet WHERE item_id=?", [(int)$id]);
    foreach (($_POST['kap'] ?? []) as $kgid => $stk) {
        $stk = (int)$stk;
        if ($stk > 0) q("INSERT INTO pack_kapazitaet (item_id,kapselgroesse_id,stueck) VALUES (?,?,?)", [(int)$id, (int)$kgid, $stk]);
    }
    $mfg = trim($_POST['max_fuellgewicht_g'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['max_fuellgewicht_g']) : null;
    $vol = trim($_POST['volumen_ml'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['volumen_ml']) : null;
    q("UPDATE item SET max_fuellgewicht_g=?, volumen_ml=? WHERE id=?", [$mfg, $vol, (int)$id]);
    header('Location: ?p=verpackung&id=' . $id . '&tab=fuell&gespeichert=1'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'ekstaffel_save' && is_numeric($id)) {
    // Behälter-EK-Staffel speichern (eigenes Formular)
    q("DELETE FROM pack_ek_staffel WHERE item_id=?", [(int)$id]);
    $mab = $_POST['ek_menge_ab'] ?? []; $ekp = $_POST['ek_preis'] ?? []; $elf = $_POST['ek_lieferant'] ?? [];
    foreach ($mab as $i => $mv) {
        $mengeab = (int)$mv; $preis = (float)str_replace(',', '.', $ekp[$i] ?? '0');
        $lief = ($elf[$i] ?? '') !== '' ? (int)$elf[$i] : null;
        if ($preis > 0) q("INSERT INTO pack_ek_staffel (item_id,menge_ab,ek_preis,lieferant_id) VALUES (?,?,?,?)", [(int)$id, $mengeab, $preis, $lief]);
    }
    // Flachen EK-Preis (Kennzahl/Fallback) aus der niedrigsten Staffelstufe ableiten.
    $basis = scalar("SELECT ek_preis FROM pack_ek_staffel WHERE item_id=? ORDER BY menge_ab ASC LIMIT 1", [(int)$id]);
    q("UPDATE item SET ek_preis=? WHERE id=?", [$basis !== null ? (float)$basis : 0, (int)$id]);
    header('Location: ?p=verpackung&id=' . $id . '&tab=ek&gespeichert=1'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'vkstaffel_save' && is_numeric($id)) {
    // Direkte VK-Staffel (überschreibt EK×Aufschlag). Leere Zeilen = keine Vorgabe.
    q("DELETE FROM pack_vk_staffel WHERE item_id=?", [(int)$id]);
    $mab = $_POST['vk_menge_ab'] ?? []; $vkp = $_POST['vk_preis'] ?? [];
    foreach ($mab as $i => $mv) {
        $preis = (float)str_replace(',', '.', $vkp[$i] ?? '0');
        if ($preis > 0) q("INSERT INTO pack_vk_staffel (item_id,menge_ab,vk_preis) VALUES (?,?,?)", [(int)$id, (int)$mv, $preis]);
    }
    header('Location: ?p=verpackung&id=' . $id . '&tab=verkauf&gespeichert=1'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'etikett_save' && is_numeric($id)) {
    // Etiketten-Mengenstaffel speichern. EK je Etikett = Gesamtpreis / Menge (sub-Cent-genau).
    q("DELETE FROM etikett_preis WHERE item_id=?", [(int)$id]);
    $mab = $_POST['et_menge_ab'] ?? []; $ges = $_POST['et_gesamt'] ?? [];
    foreach ($mab as $i => $mv) {
        $menge = (int)$mv; $gesamt = (float)str_replace(',', '.', $ges[$i] ?? '0');
        if ($menge > 0 && $gesamt > 0) {
            $stueck = round($gesamt / $menge, 4);
            q("INSERT INTO etikett_preis (item_id,menge_ab,ek_gesamt,ek_stueck) VALUES (?,?,?,?)", [(int)$id, $menge, $gesamt, $stueck]);
        }
    }
    header('Location: ?p=verpackung&id=' . $id . '&tab=etipreis&gespeichert=1'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'dok_upload' && is_numeric($id)) {
    $kat = in_array($_POST['dok_kategorie'] ?? '', ['ppwr','doc','spez','etikett','sonstiges'], true) ? $_POST['dok_kategorie'] : 'ppwr';
    if (!empty($_FILES['dok']['name']) && ($_FILES['dok']['error'] ?? 1) === UPLOAD_ERR_OK) {
        if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
        $orig = $_FILES['dok']['name'];
        $ext  = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($orig, PATHINFO_EXTENSION)));
        $fn   = 'vp_' . (int)$id . '_' . bin2hex(random_bytes(6)) . ($ext ? '.' . $ext : '');
        if (move_uploaded_file($_FILES['dok']['tmp_name'], BX_UPLOADS . '/' . $fn)) {
            q("INSERT INTO verpackung_dokument (item_id,titel,kategorie,datei,datei_orig) VALUES (?,?,?,?,?)",
              [(int)$id, trim($_POST['dok_titel'] ?? '') ?: null, $kat, $fn, $orig]);
        }
    }
    header('Location: ?p=verpackung&id=' . $id . '&tab=dok&gespeichert=1'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'dok_del' && is_numeric($id)) {
    $did = (int)($_POST['dok_id'] ?? 0);
    $d = one("SELECT datei FROM verpackung_dokument WHERE id=? AND item_id=?", [$did, (int)$id]);
    if ($d) { @unlink(BX_UPLOADS . '/' . basename((string)$d['datei'])); q("DELETE FROM verpackung_dokument WHERE id=? AND item_id=?", [$did, (int)$id]); }
    header('Location: ?p=verpackung&id=' . $id . '&tab=dok'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = fn($k) => trim($_POST[$k] ?? '');
    if ($f('name') === '') {
        $fehler = 'Name ist ein Pflichtfeld.';
    } else {
        $felder = ['artikelnummer','name','kategorie','verpackung_rolle','verpackungsart','material','etikett_format','etikett_druck','etikett_final','farbe',
                   'hoehe_mm','durchmesser_mm','breite_mm','tiefe_mm','gewicht_g','vk_aufschlag_prozent',
                   'einheit','preis_bezug','haupt_lieferant_id','gesperrt','notiz'];
        $vals = array_map($f, $felder);
        $vals[array_search('kategorie', $felder)] = 'verpackung';
        if (trim($vals[array_search('verpackung_rolle', $felder)]) === '') $vals[array_search('verpackung_rolle', $felder)] = 'primaer';
        $vals[array_search('einheit', $felder)]   = 'Stück';
        $vals[array_search('preis_bezug', $felder)] = 'Stück';
        $vals[array_search('gesperrt', $felder)]  = isset($_POST['gesperrt']) ? 1 : 0;
        $vals[array_search('haupt_lieferant_id', $felder)] = ($_POST['haupt_lieferant_id'] ?? '') !== '' ? (int)$_POST['haupt_lieferant_id'] : null;
        foreach (['hoehe_mm','durchmesser_mm','breite_mm','tiefe_mm','gewicht_g','vk_aufschlag_prozent'] as $nf) {
            if (trim($_POST[$nf] ?? '') === '') $vals[array_search($nf, $felder)] = null;
        }
        // VK-Aufschlag: Verkauf-Reiter ist bei Produktionsrolle ausgeblendet -> vorhandenen Wert behalten
        if (!darf_verkauf() && !$neu) $vals[array_search('vk_aufschlag_prozent', $felder)] = scalar("SELECT vk_aufschlag_prozent FROM item WHERE id=?", [$id]);
        if ($neu) {
            if (trim($vals[array_search('artikelnummer', $felder)]) === '') $vals[array_search('artikelnummer', $felder)] = naechste_nummer('VP');
            $ph = implode(',', array_fill(0, count($felder), '?'));
            q("INSERT INTO item (" . implode(',', $felder) . ") VALUES ($ph)", $vals);
            $id = insert_id();
            log_aktivitaet('item', (int)$id, 'team', 'Verpackung angelegt.', 'notiz');
        } else {
            $set = implode(',', array_map(fn($c) => "$c=?", $felder));
            $vals[] = (int)$id;
            q("UPDATE item SET $set WHERE id=?", $vals);
        }
        header('Location: ?p=verpackung&id=' . $id . '&gespeichert=1'); exit;
    }
}

$it = $neu ? ['gesperrt'=>0] : one("SELECT * FROM item WHERE id=? AND kategorie='verpackung'", [(int)$id]);
if (!$it) { $neu = true; $it = ['gesperrt'=>0]; }
$v = fn($k) => h((string)($it[$k] ?? ''));
$gesperrt = (int)($it['gesperrt'] ?? 0) === 1;
$lieferanten = all("SELECT id, firma FROM lieferanten ORDER BY firma");
$ROLLEN = verpackung_rollen();
$rolle  = ($it['verpackung_rolle'] ?? '') ?: 'primaer';
seed_kapselgroesse_if_empty();
seed_etikett_preise();   // Etiketten-EK je Gebinde (Labelisten, Stand Juni 2026), einmalig
seed_standbodenbeutel();   // Standbodenbeutel (Labelisten) + EK-Staffeln, einmalig
$KAPSELN = all("SELECT id, name, fuellmenge_mg FROM kapselgroesse ORDER BY fuellmenge_mg ASC");
$kapmap  = (!$neu) ? pack_kapazitaet_fuer((int)$id) : [];
$etikettstaffel = (!$neu) ? etikett_staffel((int)$id) : [];
$ekstaffel = (!$neu) ? all("SELECT s.*, l.firma AS lieferant_firma FROM pack_ek_staffel s LEFT JOIN lieferanten l ON l.id=s.lieferant_id WHERE s.item_id=? ORDER BY s.menge_ab", [(int)$id]) : [];
$vkstaffel = (!$neu) ? all("SELECT * FROM pack_vk_staffel WHERE item_id=? ORDER BY menge_ab", [(int)$id]) : [];
$dokumente = (!$neu) ? all("SELECT * FROM verpackung_dokument WHERE item_id=? ORDER BY kategorie, id DESC", [(int)$id]) : [];
$DOKKAT = ['ppwr'=>'PPWR-Nachweis', 'doc'=>'Konformität (DoC)', 'spez'=>'Spezifikation', 'etikett'=>'Etikett-Druckdatei', 'sonstiges'=>'Sonstiges'];
if (!$neu) { seed_aktivitaet_if_empty(); $verlauf = verlauf_fuer('item', (int)$id); } else { $verlauf = []; }

function bx_bald(string $modul): void {
    echo '<div class="bx-tablewrap"><table class="bx-table"><tbody><tr><td class="muted">'
       . 'Sobald das Modul <strong>' . h($modul) . '</strong> steht, erscheint hier, in welchen Produkten diese Verpackung verwendet wird.'
       . '</td></tr></tbody></table></div>';
}

render_header('verpackungen', $neu ? 'Neue Verpackung' : $it['name']);
bx_head($neu ? 'Neue Verpackung' : $v('name'),
        $neu ? 'Verpackung anlegen' : trim(($v('artikelnummer') ? $v('artikelnummer').' · ' : '') . ($VART[$it['verpackungsart']] ?? $it['verpackungsart'])),
        bx_btn('Zurück zur Liste', '?p=verpackungen', 'ghost'));

if (isset($_GET['gespeichert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b">' . h($fehler) . '</div>';

if (!$neu) {
    echo '<div class="bx-cards">';
    echo '<div class="bx-card"><div class="k">Status</div><div class="v">' . ($gesperrt ? bx_badge('gesperrt','err') : bx_badge('aktiv','ok')) . '</div></div>';
    echo '<div class="bx-card"><div class="k">Art</div><div class="v">' . h($VART[$it['verpackungsart']] ?? $it['verpackungsart'] ?: '–') . '</div></div>';
    echo '<div class="bx-card"><div class="k">Volumen</div><div class="v">' . ($it['volumen_ml']!==null ? rtrim(rtrim(number_format((float)$it['volumen_ml'],2,',','.'),'0'),',').' ml' : '–') . '</div></div>';
    echo '<div class="bx-card"><div class="k">EK-Preis</div><div class="v">' . number_format((float)($it['ek_preis']??0),2,',','.') . ' €</div></div>';
    echo '</div>';
}
?>
<form method="post" class="bx-form">
  <div class="settabs" id="vtabs">
    <a href="#" class="on" data-tab="stamm">Stammdaten</a>
    <a href="#" data-tab="ek">Einkauf</a>
    <?php if (darf_verkauf()): ?><a href="#" data-tab="verkauf">Verkauf</a><?php endif; ?>
    <?php if (!$neu): ?>
    <a href="#" data-tab="fuell" data-only="primaer">Füllmengen</a>
    <a href="#" data-tab="etipreis" data-only="primaer">Etikettenpreise</a>
    <a href="#" data-tab="dok">Dokumente (PPWR)</a>
    <a href="#" data-tab="verw">Verwendung</a>
    <a href="#" data-tab="verlauf">Verlauf</a>
    <?php endif; ?>
  </div>

  <section data-panel="stamm">
    <div class="bx-panel"><div class="bx-grid">
      <div class="bx-field"><label>Artikelnummer <?= bx_hint('leer lassen = wird automatisch vergeben (VP-…)') ?></label><input type="text" name="artikelnummer" value="<?= $v('artikelnummer') ?>" placeholder="<?= $neu ? 'automatisch (VP-…)' : '' ?>"></div>
      <div class="bx-field"><label>Name</label><input type="text" name="name" value="<?= $v('name') ?>" required placeholder="z. B. Dose 150 ml weiß"></div>
      <div class="bx-field"><label>Rolle in der Stückliste <?= bx_hint('Primärverpackung hält das Produkt direkt; Verschluss/Etikett/Karton/Beipackzettel ergänzen es') ?></label>
        <select name="verpackung_rolle" id="f_rolle">
          <?php foreach ($ROLLEN as $key=>$lbl): ?><option value="<?= $key ?>" <?= $rolle===$key?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field" data-only="primaer"><label>Art</label>
        <select name="verpackungsart">
          <?php foreach ($VART as $key=>$lbl): ?><option value="<?= $key ?>" <?= ($it['verpackungsart']??'')===$key?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Material</label><input type="text" name="material" value="<?= $v('material') ?>" placeholder="z. B. HDPE, Braunglas, Alu, Papier"></div>
      <div class="bx-field" data-only="etikett"><label>Etikett-Format <?= bx_hint('z. B. 100x70 mm – Wickeletikett / Frontetikett') ?></label><input type="text" name="etikett_format" value="<?= $v('etikett_format') ?>" placeholder="z. B. 100x70 mm"></div>
      <div class="bx-field"><label>Farbe</label><input type="text" name="farbe" value="<?= $v('farbe') ?>"></div>
      <div class="bx-field"><label><?= $rolle==='etikett' ? 'Breite (mm)' : 'Breite (mm)' ?> <?= bx_hint('bei Etiketten: Etikettenbreite; bei eckigen Behältern/Kartons: Breite') ?></label><input type="number" step="0.1" name="breite_mm" value="<?= $v('breite_mm') ?>"></div>
      <div class="bx-field"><label>Höhe (mm) <?= bx_hint('bei Etiketten: Etikettenhöhe; sonst Gesamthöhe des Behälters') ?></label><input type="number" step="0.1" name="hoehe_mm" value="<?= $v('hoehe_mm') ?>"></div>
      <div class="bx-field" data-only="primaer"><label>Durchmesser (mm) <?= bx_hint('bei runden Dosen/Flaschen') ?></label><input type="number" step="0.1" name="durchmesser_mm" value="<?= $v('durchmesser_mm') ?>"></div>
      <div class="bx-field" data-only="primaer"><label>Tiefe (mm) <?= bx_hint('bei eckigen Behältern / Kartons') ?></label><input type="number" step="0.1" name="tiefe_mm" value="<?= $v('tiefe_mm') ?>"></div>
      <div class="bx-field"><label>Leergewicht (g) <?= bx_hint('Gewicht der leeren Verpackung – Basis für die PPWR-Meldung') ?></label><input type="number" step="0.01" name="gewicht_g" value="<?= $v('gewicht_g') ?>"></div>
      <div class="bx-field" data-only="primaer"><label>Etikett – Endformat (B×H mm) <?= bx_hint('finales Etikettenmaß für dieses Gebinde, z. B. 56 x 143') ?></label><input type="text" name="etikett_final" value="<?= $v('etikett_final') ?>" placeholder="z. B. 56 x 143"></div>
      <div class="bx-field" data-only="primaer"><label>Etikett – Druckdatei (B×H mm) <?= bx_hint('Druckdatei-Maß = Endformat + 3 mm rundum, z. B. 62 x 149') ?></label><input type="text" name="etikett_druck" value="<?= $v('etikett_druck') ?>" placeholder="z. B. 62 x 149"></div>
      <div class="bx-field"><label>Verpackung sperren</label>
        <div class="bx-check" style="padding-top:8px">
          <input type="checkbox" name="gesperrt" id="f_gesperrt" value="1" <?= $gesperrt?'checked':'' ?>>
          <label for="f_gesperrt" style="margin:0">Verpackung ist gesperrt</label>
        </div>
      </div>
    </div>
    <div class="bx-field"><label>Notiz (intern)</label><textarea name="notiz"><?= $v('notiz') ?></textarea></div>
    </div>
  </section>

  <section data-panel="ek" hidden>
    <div class="bx-panel"><div class="bx-grid">
      <div class="bx-field"><label>Haupt-Lieferant</label>
        <select name="haupt_lieferant_id">
          <option value="">– keiner –</option>
          <?php foreach ($lieferanten as $lf): ?>
            <option value="<?= $lf['id'] ?>" <?= (int)($it['haupt_lieferant_id']??0)===(int)$lf['id']?'selected':'' ?>><?= h($lf['firma']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>EK je Stück <?= bx_hint('ergibt sich automatisch aus der niedrigsten Staffelstufe unten') ?></label><input type="text" value="<?= $it['ek_preis'] !== null && $it['ek_preis'] !== '' ? number_format((float)$it['ek_preis'],4,',','.') . ' €' : '–' ?>" disabled></div>
    </div>
    <p class="muted" style="font-size:12px">Der Einkaufspreis wird über die Mengenstaffel gepflegt; der effektive EK je Bestellmenge kommt automatisch aus der passenden Stufe.</p>
    </div>
  </section>

  <?php if (darf_verkauf()):
    $globalAuf = (float) meta_get('aufschlag_verpackung', 30);
    $effAuf = ($it['vk_aufschlag_prozent'] ?? '') !== '' && ($it['vk_aufschlag_prozent'] ?? null) !== null ? (float)$it['vk_aufschlag_prozent'] : $globalAuf;
  ?>
  <section data-panel="verkauf" hidden>
    <div class="bx-panel"><h2 style="margin-top:0">Verkauf</h2>
      <div class="bx-grid">
        <div class="bx-field"><label>VK-Aufschlag (%) <?= bx_hint('leer = globaler Verpackungs-Aufschlag ('.rtrim(rtrim(number_format($globalAuf,2,',','.'),'0'),',').' %). VK = EK × (1 + Aufschlag).') ?></label><input type="number" step="0.1" name="vk_aufschlag_prozent" value="<?= $v('vk_aufschlag_prozent') ?>" placeholder="<?= h(rtrim(rtrim(number_format($globalAuf,2,',','.'),'0'),',')) ?> (Standard)"></div>
      </div>
      <div style="font-weight:600;margin:12px 0 6px">Effektiver VK je Bestellmenge</div>
      <?php if ($ekstaffel): ?>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th class="bx-num">ab Bestellmenge (Stück)</th><th class="bx-num">EK je Stück</th><th class="bx-num">VK je Stück</th><th>Quelle</th></tr></thead>
        <tbody>
          <?php foreach ($ekstaffel as $er): $ek=(float)$er['ek_preis']; $mab=(int)$er['menge_ab'];
              $ovr = scalar("SELECT vk_preis FROM pack_vk_staffel WHERE item_id=? AND menge_ab<=? ORDER BY menge_ab DESC LIMIT 1", [(int)$id, $mab]);
              $vk = ($ovr !== null && $ovr !== false) ? (float)$ovr : $ek*(1+$effAuf/100); ?>
          <tr>
            <td class="bx-num"><?= number_format($mab,0,',','.') ?></td>
            <td class="bx-num"><?= number_format($ek,4,',','.') ?> €</td>
            <td class="bx-num"><strong><?= number_format($vk,4,',','.') ?> €</strong></td>
            <td><?= ($ovr !== null && $ovr !== false) ? bx_badge('fester VK','info') : ('Aufschlag '.rtrim(rtrim(number_format($effAuf,2,',','.'),'0'),',').' %') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <p class="muted" style="font-size:12px;margin-top:6px">VK = fester Preis (unten „Verkaufspreis von Hand") falls gesetzt, sonst EK × Aufschlag. Aufschlag-Änderung zuerst speichern.</p>
      <?php else: ?>
      <div class="muted">Noch keine EK-Mengenstaffel hinterlegt (Reiter Einkauf). Danach erscheinen hier die VK-Preise je Menge.</div>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; /* darf_verkauf */ ?>

  <?php if (!$neu): ?>
  <section data-panel="verw" hidden><div class="bx-panel"><h2>Verwendung in Produkten</h2><?php bx_bald('Produkte'); ?></div></section>
  <section data-panel="verlauf" hidden><div class="bx-panel"><h2>Verlauf</h2><?php bx_chat($verlauf, $v('name')); ?></div></section>
  <?php endif; ?>

  <div class="bx-row" style="margin-top:var(--sp-4)" id="hauptaktion">
    <button class="btn btn-primary" type="submit"><?= $neu ? 'Verpackung anlegen' : 'Speichern' ?></button>
    <a class="btn btn-ghost" href="?p=verpackungen">Abbrechen</a>
  </div>
</form>

<?php if (!$neu): ?>
<section data-panel="fuell" hidden>
  <form method="post">
    <input type="hidden" name="aktion" value="fuell_save">
    <div class="bx-panel">
      <h2 style="margin-top:0">Füllmengen je Darreichungsform</h2>
      <p class="muted" style="margin-top:0">Wie viel passt in diese Verpackung? Je Form die passende Kennzahl – steuert im Produkt die automatische Verpackungs-Zuordnung „passt / zu klein".</p>
      <div class="bx-grid" style="margin-bottom:10px">
        <div class="bx-field"><label>Pulver – max. Füllgewicht (g) <?= bx_hint('so viel Pulver/Granulat passt maximal rein') ?></label><input type="number" step="0.01" name="max_fuellgewicht_g" value="<?= $v('max_fuellgewicht_g') ?>" placeholder="z. B. 250"></div>
        <div class="bx-field"><label>Flüssig – Füllvolumen (ml) <?= bx_hint('Fassungsvermögen für Flüssigkeiten/Öle') ?></label><input type="number" step="0.01" name="volumen_ml" value="<?= $v('volumen_ml') ?>" placeholder="z. B. 100"></div>
      </div>
      <div style="font-weight:600;margin:12px 0 6px">Kapseln je Kapselgröße</div>
      <p class="muted" style="margin-top:0;font-size:12px">Nur ausfüllen, was du weißt (0/leer = passt nicht). Basis für die Verpackungs-Zuordnung bei Kapsel-Produkten.</p>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Kapselgröße</th><th class="bx-num">Füllmenge</th><th class="bx-num">passt so viele Stück rein</th></tr></thead>
        <tbody>
          <?php foreach ($KAPSELN as $kg): ?>
          <tr>
            <td><?= h($kg['name']) ?></td>
            <td class="bx-num muted"><?= (int)$kg['fuellmenge_mg'] ?> mg</td>
            <td class="bx-num"><input type="number" min="0" step="1" name="kap[<?= (int)$kg['id'] ?>]" value="<?= $kapmap[$kg['id']] ?? '' ?>" style="max-width:140px;text-align:right" placeholder="–"></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="bx-row" style="margin-top:var(--sp-4)">
        <button class="btn btn-primary" type="submit">Füllmengen speichern</button>
      </div>
    </div>
  </form>
</section>
<section data-panel="etipreis" hidden>
  <form method="post">
    <input type="hidden" name="aktion" value="etikett_save">
    <div class="bx-panel">
      <h2 style="margin-top:0">Etikettenpreise (Mengenstaffel)</h2>
      <p class="muted" style="margin-top:0">Einkaufspreis der Etiketten für dieses Gebinde je Auflage (Labelisten, Stand Juni 2026). Gib den <strong>Gesamtpreis</strong> je Bestellmenge ein – der Preis je Etikett wird automatisch berechnet (Gesamt ÷ Menge).</p>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th class="bx-num">ab Menge (Etiketten)</th><th class="bx-num">Gesamtpreis (€)</th><th class="bx-num">EK je Etikett</th></tr></thead>
        <tbody id="etrows">
          <?php $etrows = $etikettstaffel ?: [['menge_ab'=>'','ek_gesamt'=>'','ek_stueck'=>null]]; foreach ($etrows as $et):
              $stk = ($et['ek_stueck']!==null && $et['ek_stueck']!=='') ? number_format((float)$et['ek_stueck'],4,',','.').' &euro;' : '&ndash;'; ?>
          <tr>
            <td class="bx-num"><input type="number" min="0" step="1" name="et_menge_ab[]" value="<?= h((string)$et['menge_ab']) ?>" style="max-width:160px;text-align:right"></td>
            <td class="bx-num"><input type="number" min="0" step="0.01" name="et_gesamt[]" value="<?= ($et['ek_gesamt']!=='' && $et['ek_gesamt']!==null) ? (float)$et['ek_gesamt'] : '' ?>" style="max-width:140px;text-align:right"></td>
            <td class="bx-num muted et-stueck"><?= $stk ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="bx-row" style="margin-top:var(--sp-4)">
        <button type="button" class="btn btn-ghost btn-sm" id="etAdd">+ Staffel</button>
        <button class="btn btn-primary" type="submit">Etikettenpreise speichern</button>
      </div>
    </div>
  </form>
</section>
<section data-panel="ek" hidden>
  <form method="post">
    <input type="hidden" name="aktion" value="ekstaffel_save">
    <div class="bx-panel">
      <h2 style="margin-top:0">Mengenstaffel (EK)</h2>
      <p class="muted" style="margin-top:0">EK-Preis dieses Gebindes je Bestellmenge (mehr = günstiger). Basis für die automatische VK-Preismatrix der Produkte. Leer = flacher EK aus den Stammdaten.</p>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Lieferant</th><th class="bx-num">ab Bestellmenge (Stück)</th><th class="bx-num">EK je Gebinde (€)</th></tr></thead>
        <tbody id="ekrows">
          <?php $erows = $ekstaffel ?: [['menge_ab'=>'','ek_preis'=>'','lieferant_id'=>null]]; foreach ($erows as $er): ?>
          <tr>
            <td><select name="ek_lieferant[]"><option value="">– keiner –</option><?php foreach ($lieferanten as $lf): ?><option value="<?= (int)$lf['id'] ?>" <?= (int)($er['lieferant_id']??0)===(int)$lf['id']?'selected':'' ?>><?= h($lf['firma']) ?></option><?php endforeach; ?></select></td>
            <td class="bx-num"><input type="number" min="0" step="1" name="ek_menge_ab[]" value="<?= h((string)$er['menge_ab']) ?>" style="max-width:160px;text-align:right"></td>
            <td class="bx-num"><input type="number" min="0" step="0.0001" name="ek_preis[]" value="<?= $er['ek_preis']!=='' ? (float)$er['ek_preis'] : '' ?>" style="max-width:140px;text-align:right"></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="bx-row" style="margin-top:var(--sp-4)">
        <button type="button" class="btn btn-ghost btn-sm" id="ekAdd">+ Staffel</button>
        <button class="btn btn-primary" type="submit">EK-Staffel speichern</button>
      </div>
    </div>
  </form>
</section>
<section data-panel="verkauf" hidden>
  <form method="post">
    <input type="hidden" name="aktion" value="vkstaffel_save">
    <div class="bx-panel">
      <h2 style="margin-top:0">Verkaufspreis von Hand (VK-Staffel)</h2>
      <p class="muted" style="margin-top:0">Optionaler <strong>direkter VK</strong> je Bestellmenge – überschreibt die Aufschlags-Rechnung oben. Leer lassen = VK = EK × Aufschlag.</p>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th class="bx-num">ab Bestellmenge (Stück)</th><th class="bx-num">VK je Gebinde (€)</th></tr></thead>
        <tbody id="vkrows">
          <?php $vrows = $vkstaffel ?: [['menge_ab'=>'','vk_preis'=>'']]; foreach ($vrows as $vr): ?>
          <tr>
            <td class="bx-num"><input type="number" min="0" step="1" name="vk_menge_ab[]" value="<?= h((string)$vr['menge_ab']) ?>" style="max-width:160px;text-align:right"></td>
            <td class="bx-num"><input type="number" min="0" step="0.0001" name="vk_preis[]" value="<?= $vr['vk_preis']!=='' ? (float)$vr['vk_preis'] : '' ?>" style="max-width:140px;text-align:right"></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="bx-row" style="margin-top:var(--sp-4)">
        <button type="button" class="btn btn-ghost btn-sm" id="vkAdd">+ Staffel</button>
        <button class="btn btn-primary" type="submit">VK-Staffel speichern</button>
      </div>
    </div>
  </form>
</section>
<section data-panel="dok" hidden>
  <div class="bx-panel">
    <h2>Dokumente (PPWR &amp; Nachweise)</h2>
    <p class="muted" style="margin-top:0">Nachweise zu dieser Verpackung: PPWR-Unterlagen, Konformitätserklärung (DoC), Spezifikation, Etikett-Druckdatei u. a. Werden sicher außerhalb des Web-Ordners gespeichert.</p>
    <?php if ($dokumente): ?>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Kategorie</th><th>Titel / Datei</th><th>Hochgeladen</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($dokumente as $d): ?>
        <tr>
          <td><?= h($DOKKAT[$d['kategorie']] ?? $d['kategorie']) ?></td>
          <td><a href="?p=verpackung_dok&id=<?= (int)$d['id'] ?>" target="_blank"><?= h($d['titel'] ?: ($d['datei_orig'] ?: 'Dokument')) ?></a><?php if ($d['titel'] && $d['datei_orig']): ?><div class="muted" style="font-size:12px"><?= h($d['datei_orig']) ?></div><?php endif; ?></td>
          <td class="muted"><?= h(fmt_zeit($d['angelegt'], 'd.m.Y')) ?></td>
          <td style="text-align:right"><form method="post" style="margin:0" onsubmit="return confirm('Dokument löschen?');"><input type="hidden" name="aktion" value="dok_del"><input type="hidden" name="dok_id" value="<?= (int)$d['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Löschen</button></form></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php else: ?>
    <div class="muted" style="margin-bottom:12px">Noch keine Dokumente hinterlegt.</div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" style="margin-top:14px">
      <input type="hidden" name="aktion" value="dok_upload">
      <div class="bx-grid">
        <div class="bx-field"><label>Kategorie</label>
          <select name="dok_kategorie"><?php foreach ($DOKKAT as $key=>$lbl): ?><option value="<?= $key ?>"><?= h($lbl) ?></option><?php endforeach; ?></select>
        </div>
        <div class="bx-field"><label>Titel (optional)</label><input type="text" name="dok_titel" placeholder="z. B. PPWR-Konformität 2026"></div>
        <div class="bx-field"><label>Datei</label><input type="file" name="dok" required accept="application/pdf,image/*"></div>
      </div>
      <div class="bx-row" style="margin-top:var(--sp-4)"><button class="btn btn-primary" type="submit">Dokument hochladen</button></div>
    </form>
  </div>
</section>
<?php endif; ?>

<script>
(function(){
  var ekLiefCell = <?= json_encode('<td><select name="ek_lieferant[]"><option value="">– keiner –</option>' . implode('', array_map(fn($lf) => '<option value="' . (int)$lf['id'] . '">' . h($lf['firma']) . '</option>', $lieferanten)) . '</select></td>') ?>;
  var ekAdd = document.getElementById('ekAdd');
  if (ekAdd) ekAdd.addEventListener('click', function(){
    var tr = document.createElement('tr');
    tr.innerHTML = ekLiefCell
      + '<td class="bx-num"><input type="number" min="0" step="1" name="ek_menge_ab[]" style="max-width:160px;text-align:right"></td>'
      + '<td class="bx-num"><input type="number" min="0" step="0.0001" name="ek_preis[]" style="max-width:140px;text-align:right"></td>';
    document.getElementById('ekrows').appendChild(tr);
  });
  var vkAdd = document.getElementById('vkAdd');
  if (vkAdd) vkAdd.addEventListener('click', function(){
    var tr = document.createElement('tr');
    tr.innerHTML = '<td class="bx-num"><input type="number" min="0" step="1" name="vk_menge_ab[]" style="max-width:160px;text-align:right"></td>'
      + '<td class="bx-num"><input type="number" min="0" step="0.0001" name="vk_preis[]" style="max-width:140px;text-align:right"></td>';
    document.getElementById('vkrows').appendChild(tr);
  });
  var etAdd = document.getElementById('etAdd');
  if (etAdd) etAdd.addEventListener('click', function(){
    var tr = document.createElement('tr');
    tr.innerHTML = '<td class="bx-num"><input type="number" min="0" step="1" name="et_menge_ab[]" style="max-width:160px;text-align:right"></td>'
      + '<td class="bx-num"><input type="number" min="0" step="0.01" name="et_gesamt[]" style="max-width:140px;text-align:right"></td>'
      + '<td class="bx-num muted et-stueck">&ndash;</td>';
    document.getElementById('etrows').appendChild(tr);
  });
  // EK je Etikett live aus Gesamt / Menge berechnen (Anzeige)
  var etBody = document.getElementById('etrows');
  if (etBody) etBody.addEventListener('input', function(e){
    var tr = e.target.closest('tr'); if (!tr) return;
    var m = parseFloat((tr.querySelector('[name="et_menge_ab[]"]')||{}).value);
    var g = parseFloat((tr.querySelector('[name="et_gesamt[]"]')||{}).value);
    var cell = tr.querySelector('.et-stueck'); if (!cell) return;
    cell.textContent = (m > 0 && g > 0) ? (g/m).toFixed(4).replace('.', ',') + ' €' : '–';
  });
  var tabs = document.querySelectorAll('#vtabs a');
  var haupt = document.getElementById('hauptaktion');
  tabs.forEach(function(t){
    t.addEventListener('click', function(e){
      e.preventDefault();
      tabs.forEach(function(x){ x.classList.remove('on'); });
      t.classList.add('on');
      var tab = t.getAttribute('data-tab');
      document.querySelectorAll('[data-panel]').forEach(function(p){
        p.hidden = (p.getAttribute('data-panel') !== tab);
      });
      // Haupt-Speichern nur auf Formular-Reitern zeigen, nicht bei Reitern mit eigenem Speichern-Button
      if (haupt) haupt.style.display = (tab === 'fuell' || tab === 'dok' || tab === 'etipreis') ? 'none' : '';
    });
  });
  // Reiter aus der URL öffnen (?tab=…), z. B. nach Dokument-Upload
  var urlTab = new URLSearchParams(location.search).get('tab');
  if (urlTab) { var t0 = document.querySelector('#vtabs a[data-tab="' + urlTab + '"]'); if (t0) t0.click(); }
  // Rolle-abhängig: Felder/Reiter nur zeigen, die zur Rolle passen
  var rolleSel = document.getElementById('f_rolle');
  function applyRolle(){
    var r = rolleSel ? rolleSel.value : 'primaer';
    document.querySelectorAll('[data-only]').forEach(function(el){
      var show = (el.getAttribute('data-only') === r);
      if (el.classList.contains('bx-field')) el.style.display = show ? '' : 'none';
      else el.hidden = !show;   // Reiter (a) ausblenden
    });
  }
  if (rolleSel) rolleSel.addEventListener('change', applyRolle);
  applyRolle();
})();
</script>
<?php
render_footer();
