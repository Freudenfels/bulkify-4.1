<?php
// Rohstoff / Item – anlegen & bearbeiten (mit leichtem Cockpit)
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/dokument_ui.php';

$KAT  = ['rohstoff'=>'Rohstoff','verpackung'=>'Verpackung','verbrauch'=>'Verbrauch','fertig'=>'Fertigware','verkaufsfertig'=>'Verkaufsfertig','maschine'=>'Maschine'];
$FORM = ['pulver'=>'Pulver','granulat'=>'Granulat','fluessig'=>'Flüssig','oel'=>'Öl','paste'=>'Paste','kristallin'=>'Kristallin','kapselhuelle'=>'Kapselhülle (leer)'];
$id  = $_GET['id'] ?? 'neu';
$neu = ($id === 'neu' || !is_numeric($id));

$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'preis_add' && !$neu) {
    $lid = (int)($_POST['lp_lieferant'] ?? 0);
    $preis = (float)str_replace(',', '.', $_POST['lp_preis'] ?? '0');
    $mengeab = (float)str_replace(',', '.', $_POST['lp_menge_ab'] ?? '0');
    if ($lid && $preis > 0) q("INSERT INTO lieferant_preis (item_id,lieferant_id,menge_ab,preis,waehrung,stand) VALUES (?,?,?,?, 'EUR', CURDATE())", [(int)$id, $lid, $mengeab, $preis]);
    header('Location: ?p=rohstoff&id=' . $id . '&preisok=1#'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'preis_del' && !$neu) {
    q("DELETE FROM lieferant_preis WHERE id=? AND item_id=?", [(int)($_POST['preis_id'] ?? 0), (int)$id]);
    header('Location: ?p=rohstoff&id=' . $id . '&preisok=1'); exit;
}
// Analysewerte einer Charge erfassen – daraus entsteht UNSER Analysenzertifikat (CoA).
// Die Unterlagen des Vorlieferanten sind die Quelle; weitergegeben wird das bulkify-Dokument.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'analyse_save' && !$neu) {
    $cid = (int)($_POST['charge_id'] ?? 0);
    if ($cid && scalar("SELECT id FROM charge WHERE id=? AND item_id=?", [$cid, (int)$id])) {
        q("DELETE FROM charge_analyse WHERE charge_id=?", [$cid]);
        $sort = 0;
        foreach (($_POST['a_par'] ?? []) as $i2 => $par) {
            $par = trim((string)$par); if ($par === '') continue;
            q("INSERT INTO charge_analyse (charge_id,parameter,spezifikation,ergebnis,methode,sort) VALUES (?,?,?,?,?,?)",
              [$cid, mb_substr($par, 0, 120),
               mb_substr(trim((string)($_POST['a_spec'][$i2] ?? '')), 0, 120) ?: null,
               mb_substr(trim((string)($_POST['a_erg'][$i2] ?? '')), 0, 120) ?: null,
               mb_substr(trim((string)($_POST['a_met'][$i2] ?? '')), 0, 120) ?: null, $sort++]);
        }
    }
    header('Location: ?p=rohstoff&id=' . (int)$id . '&tab=chargen&analyse=1'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'dok_upload' && !$neu) {
    dokument_upload('item', (int)$id);
    header('Location: ?p=rohstoff&id=' . $id . '&tab=dok&gespeichert=1'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'dok_frei' && !$neu) {
    dokument_freigabe_toggle('item', (int)$id, (int)($_POST['dok_id'] ?? 0));
    header('Location: ?p=rohstoff&id=' . $id . '&tab=dok'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'dok_del' && !$neu) {
    dokument_delete('item', (int)$id, (int)($_POST['dok_id'] ?? 0));
    header('Location: ?p=rohstoff&id=' . $id . '&tab=dok'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === '') {
    $f = fn($k) => trim($_POST[$k] ?? '');
    if ($f('name') === '') {
        $fehler = 'Name ist ein Pflichtfeld.';
    } else {
        $felder = ['artikelnummer','name','name_en','name_lat','cas','kategorie','form',
                   'material','farbe','kapselgroesse_id','leergewicht_mg',
                   'dichte','allergene','herkunft','overage_prozent','einheit',
                   'ek_preis','preis_bezug','vk_aufschlag_prozent','haupt_lieferant_id','gesperrt','notiz',
                   // Spezifikation
                   'synonym','ec_nr','bot_quelle','herkunftsland','haltbarkeit','lagerbedingungen','zusaetze',
                   'vegan','gvo_frei','bestrahlt','tse_bse_frei','zertifikate','spec_nr','spec_version','spec_gueltig_ab'];
        $vals = array_map($f, $felder);
        $vals[array_search('gesperrt', $felder)] = isset($_POST['gesperrt']) ? 1 : 0;
        $vals[array_search('haupt_lieferant_id', $felder)] = ($_POST['haupt_lieferant_id'] ?? '') !== '' ? (int)$_POST['haupt_lieferant_id'] : null;
        $vals[array_search('kapselgroesse_id', $felder)] = ($_POST['kapselgroesse_id'] ?? '') !== '' ? (int)$_POST['kapselgroesse_id'] : null;
        if (trim($_POST['leergewicht_mg'] ?? '') === '') $vals[array_search('leergewicht_mg', $felder)] = null;
        if (trim($_POST['dichte'] ?? '') === '') $vals[array_search('dichte', $felder)] = null;
        // Ja/Nein/Unbekannt-Flags + Datum: leer -> NULL
        foreach (['vegan','gvo_frei','bestrahlt','tse_bse_frei'] as $bf) { $ix = array_search($bf, $felder); $vals[$ix] = ($vals[$ix] === '' ? null : (int)$vals[$ix]); }
        if (trim($_POST['spec_gueltig_ab'] ?? '') === '') $vals[array_search('spec_gueltig_ab', $felder)] = null;
        // VK-Aufschlag: bei Produktionsrolle ist das Feld ausgeblendet -> vorhandenen Wert behalten (nicht überschreiben)
        if (!darf_verkauf()) {
            $vals[array_search('vk_aufschlag_prozent', $felder)] = $neu ? null : scalar("SELECT vk_aufschlag_prozent FROM item WHERE id=?", [$id]);
        } elseif (trim($_POST['vk_aufschlag_prozent'] ?? '') === '') {
            $vals[array_search('vk_aufschlag_prozent', $felder)] = null;
        }
        foreach (['ek_preis','overage_prozent'] as $nf) { $ix = array_search($nf, $felder); if (trim((string)$vals[$ix]) === '') $vals[$ix] = 0; }
        if ($neu) {
            if (trim($vals[array_search('artikelnummer', $felder)]) === '') $vals[array_search('artikelnummer', $felder)] = naechste_nummer(item_prefix($vals[array_search('kategorie', $felder)]));
            $ph = implode(',', array_fill(0, count($felder), '?'));
            q("INSERT INTO item (" . implode(',', $felder) . ") VALUES ($ph)", $vals);
            $id = insert_id();
            log_aktivitaet('item', (int)$id, 'team', 'Rohstoff angelegt.', 'notiz');
        } else {
            $set = implode(',', array_map(fn($c) => "$c=?", $felder));
            $vals[] = (int)$id;
            q("UPDATE item SET $set WHERE id=?", $vals);
        }
        // Wirkstoffe synchronisieren (mehrere möglich; neuer Name -> Nährstoff wird angelegt)
        q("DELETE FROM item_wirkstoff WHERE item_id=?", [(int)$id]);
        $wn = $_POST['wirk_name'] ?? []; $wg = $_POST['wirk_gehalt'] ?? [];
        foreach ($wn as $i => $nm) {
            $nm = trim($nm); if ($nm === '') continue;
            $nid = naehrstoff_id_by_name($nm);
            $g = trim($wg[$i] ?? ''); $g = $g === '' ? null : $g;
            if ($nid) q("INSERT INTO item_wirkstoff (item_id,naehrstoff_id,gehalt_prozent,sort) VALUES (?,?,?,?)", [(int)$id, $nid, $g, $i]);
        }
        // Kennwerte (Parameter · Wert) synchronisieren
        q("DELETE FROM item_kennwert WHERE item_id=?", [(int)$id]);
        $kp = $_POST['kw_param'] ?? []; $kw = $_POST['kw_wert'] ?? [];
        foreach ($kp as $i => $pn) { $pn = trim($pn); if ($pn === '') continue; q("INSERT INTO item_kennwert (item_id,parameter,wert,sort) VALUES (?,?,?,?)", [(int)$id, $pn, trim($kw[$i] ?? ''), (int)$i]); }
        // Spec-PDF hochladen (in data/uploads, außerhalb public)
        if (!empty($_FILES['spec_pdf']['name']) && is_uploaded_file($_FILES['spec_pdf']['tmp_name'] ?? '')) {
            if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
            $fn = 'spec_' . (int)$id . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $_FILES['spec_pdf']['name']);
            if (move_uploaded_file($_FILES['spec_pdf']['tmp_name'], BX_UPLOADS . '/' . $fn))
                q("UPDATE item SET spec_pdf=? WHERE id=?", [$fn, (int)$id]);
        }
        header('Location: ?p=rohstoff&id=' . $id . '&gespeichert=1'); exit;
    }
}

$neuForm = ($_GET['form'] ?? '') === 'kapselhuelle' ? 'kapselhuelle' : 'pulver';
$neuDefault = $neuForm === 'kapselhuelle'
    ? ['kategorie'=>'rohstoff','form'=>'kapselhuelle','einheit'=>'Stück','preis_bezug'=>'Stück','gesperrt'=>0]
    : ['kategorie'=>'rohstoff','form'=>'pulver','einheit'=>'kg','preis_bezug'=>'kg','gesperrt'=>0];
$it = $neu ? $neuDefault : one("SELECT * FROM item WHERE id=?", [(int)$id]);
if (!$it) { $neu = true; $it = $neuDefault; }
$v = fn($key) => h((string)($it[$key] ?? ''));
$gesperrt = (int)($it['gesperrt'] ?? 0) === 1;
$lieferanten = all("SELECT id, firma FROM lieferanten ORDER BY firma");
seed_kapselgroesse_if_empty();
$KAPSELN = all("SELECT id, name, fuellmenge_mg FROM kapselgroesse ORDER BY fuellmenge_mg ASC");
seed_naehrstoff_if_empty();
$naehrstoffe = all("SELECT * FROM naehrstoff ORDER BY ist_nrv DESC, kategorie, sort, name");
$wirkstoffe = $neu ? [] : all("SELECT iw.*, n.name AS n_name, n.nrv_wert, n.einheit AS n_einheit, n.ist_nrv
                               FROM item_wirkstoff iw JOIN naehrstoff n ON n.id=iw.naehrstoff_id
                               WHERE iw.item_id=? ORDER BY iw.sort, iw.id", [(int)$id]);
$kennwerte = $neu ? [] : all("SELECT * FROM item_kennwert WHERE item_id=? ORDER BY sort, id", [(int)$id]);
if (!$neu) { seed_aktivitaet_if_empty(); $verlauf = verlauf_fuer('item', (int)$id); } else { $verlauf = []; }
$charges = $neu ? [] : all("SELECT c.*, l.firma AS lieferant_firma FROM charge c LEFT JOIN lieferanten l ON l.id=c.lieferant_id WHERE c.item_id=? ORDER BY c.status, c.mhd", [(int)$id]);
$bestand_frei = $neu ? 0 : item_bestand((int)$id, true);
$bestand_qua  = $neu ? 0 : (item_bestand((int)$id, false) - $bestand_frei);
if (!$neu) seed_lieferant_preis_if_empty();
$preise = $neu ? [] : all("SELECT lp.*, l.firma FROM lieferant_preis lp LEFT JOIN lieferanten l ON l.id=lp.lieferant_id WHERE lp.item_id=? ORDER BY lp.preis ASC, lp.menge_ab ASC", [(int)$id]);
$preis_lieferanten = all("SELECT id, firma FROM lieferanten ORDER BY firma");

function bx_bald(string $modul): void {
    echo '<div class="bx-tablewrap"><table class="bx-table"><tbody><tr><td class="muted">'
       . 'Sobald das Modul <strong>' . h($modul) . '</strong> steht, erscheint hier automatisch, wo dieser Rohstoff verwendet wird.'
       . '</td></tr></tbody></table></div>';
}

render_header('rohstoffe', $neu ? 'Neuer Rohstoff' : $it['name']);
bx_head($neu ? 'Neuer Rohstoff' : $v('name'),
        $neu ? 'Item anlegen' : trim(($v('artikelnummer') ? $v('artikelnummer').' · ' : '') . ($KAT[$it['kategorie']] ?? $it['kategorie']) . ($v('name_lat') ? ' · '.$v('name_lat') : '')),
        bx_btn('Zurück zur Liste', '?p=rohstoffe', 'ghost'));

if (isset($_GET['gespeichert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b">' . h($fehler) . '</div>';

if (!$neu) {
    $pr = number_format((float)$it['ek_preis'], (float)$it['ek_preis'] < 1 ? 4 : 2, ',', '.');
    echo '<div class="bx-cards">';
    echo '<div class="bx-card"><div class="k">Status</div><div class="v">' . ($gesperrt ? bx_badge('gesperrt','err') : bx_badge('aktiv','ok')) . '</div></div>';
    echo '<div class="bx-card"><div class="k">Kategorie</div><div class="v">' . h($KAT[$it['kategorie']] ?? $it['kategorie']) . '</div></div>';
    echo '<div class="bx-card"><div class="k">EK-Preis</div><div class="v">' . $pr . ' €/' . h($it['preis_bezug']) . '</div></div>';
    echo '<div class="bx-card"><div class="k">Bestand (frei)</div><div class="v">' . ($bestand_frei>0 ? h(rtrim(rtrim(number_format($bestand_frei,3,',','.'),'0'),',')).' '.h($it['einheit']) : '<span class="muted">0</span>') . '</div></div>';
    echo '</div>';
}
?>
<form method="post" class="bx-form" enctype="multipart/form-data">
  <div class="settabs" id="itabs">
    <a href="#" class="on" data-tab="stamm">Stammdaten</a>
    <a href="#" data-tab="quali">Wirkstoff &amp; Qualität</a>
    <a href="#" data-tab="spec">Spezifikation</a>
    <a href="#" data-tab="dok">Dokumente (CoA/Spec)</a>
    <a href="#" data-tab="ek">Einkauf</a>
    <?php if (!$neu): ?>
    <a href="#" data-tab="lager">Lager</a>
    <a href="#" data-tab="verw">Verwendung</a>
    <a href="#" data-tab="verlauf">Verlauf</a>
    <?php endif; ?>
  </div>

  <section data-panel="stamm">
    <div class="bx-panel"><div class="bx-grid">
      <div class="bx-field"><label>Artikelnummer <?= bx_hint('leer lassen = wird automatisch vergeben (R-/VP-/FP-… je Kategorie)') ?></label><input type="text" name="artikelnummer" value="<?= $v('artikelnummer') ?>" placeholder="<?= $neu ? 'automatisch' : '' ?>"></div>
      <div class="bx-field"><label>Name (deutsch)</label><input type="text" name="name" value="<?= $v('name') ?>" required></div>
      <div class="bx-field"><label>Name (englisch)</label><input type="text" name="name_en" value="<?= $v('name_en') ?>"></div>
      <div class="bx-field"><label>Lateinischer Name <?= bx_hint('botanischer/pharmazeutischer Name, z. B. Withania somnifera') ?></label><input type="text" name="name_lat" value="<?= $v('name_lat') ?>"></div>
      <div class="bx-field"><label>CAS-Nummer <?= bx_hint('eindeutige Stoff-Nummer, z. B. Ascorbinsäure 50-81-7') ?></label><input type="text" name="cas" value="<?= $v('cas') ?>" placeholder="z. B. 50-81-7"></div>
      <div class="bx-field"><label>Kategorie</label>
        <select name="kategorie">
          <?php foreach ($KAT as $key=>$lbl): ?>
            <option value="<?= $key ?>" <?= ($it['kategorie']??'')===$key?'selected':'' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Form <?= bx_hint('physikalische Form – steuert später die Rohstoffauswahl je Rezeptur (flüssiges Rezept → flüssige Rohstoffe zuerst). „Kapselhülle" = leere Kapseln vom Lieferanten.') ?></label>
        <select name="form" id="f_form">
          <?php foreach ($FORM as $key=>$lbl): ?>
            <option value="<?= $key ?>" <?= ($it['form']??'')===$key?'selected':'' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field" data-only="kapselhuelle"><label>Kapselgröße</label>
        <select name="kapselgroesse_id">
          <option value="">– wählen –</option>
          <?php foreach ($KAPSELN as $kg): ?>
            <option value="<?= (int)$kg['id'] ?>" <?= (int)($it['kapselgroesse_id']??0)===(int)$kg['id']?'selected':'' ?>><?= h($kg['name']) ?> (bis <?= (int)$kg['fuellmenge_mg'] ?> mg)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field" data-only="kapselhuelle"><label>Material <?= bx_hint('Hülle: HPMC (pflanzlich), Gelatine (tierisch), Pullulan') ?></label>
        <input type="text" name="material" value="<?= $v('material') ?>" list="kapselmaterial" placeholder="z. B. HPMC">
        <datalist id="kapselmaterial"><option value="HPMC"><option value="Gelatine"><option value="Pullulan"></datalist>
      </div>
      <div class="bx-field" data-only="kapselhuelle"><label>Farbe</label><input type="text" name="farbe" value="<?= $v('farbe') ?>" placeholder="z. B. transparent, weiß"></div>
      <div class="bx-field" data-only="kapselhuelle"><label>Leergewicht (mg) <?= bx_hint('Gewicht der leeren Hülle – zählt zum Bruttogewicht, nicht zum Wirkstoff') ?></label><input type="number" step="0.01" name="leergewicht_mg" value="<?= $v('leergewicht_mg') ?>"></div>
      <div class="bx-field"><label>Basiseinheit</label>
        <select name="einheit">
          <?php foreach (['kg'=>'kg','g'=>'g','Stück'=>'Stück','L'=>'Liter','ml'=>'ml'] as $key=>$lbl): ?>
            <option value="<?= $key ?>" <?= ($it['einheit']??'')===$key?'selected':'' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Rohstoff sperren <?= bx_hint('gesperrte Rohstoffe können nicht in neue Rezepturen aufgenommen werden') ?></label>
        <div class="bx-check" style="padding-top:8px">
          <input type="checkbox" name="gesperrt" id="f_gesperrt" value="1" <?= $gesperrt?'checked':'' ?>>
          <label for="f_gesperrt" style="margin:0">Rohstoff ist gesperrt</label>
        </div>
      </div>
    </div>
    <div class="bx-field"><label>Notiz (intern)</label><textarea name="notiz"><?= $v('notiz') ?></textarea></div>
    </div>
  </section>

  <section data-panel="quali" hidden>
    <div class="bx-panel">
      <h2>Wirkstoffe <?= bx_hint('was im Rohstoff steckt – Basis für die Deklaration (davon … mg, % NRV). Auswahl aus der Nährstoffliste oder neuen Namen eintippen. Mehrere möglich.') ?></h2>
      <div id="wirkrows">
        <?php $wrows = $wirkstoffe ?: [['n_name'=>'','gehalt_prozent'=>'']]; foreach ($wrows as $w): ?>
        <div class="bx-row wirkrow" style="flex-wrap:nowrap;margin-bottom:8px">
          <input type="text" name="wirk_name[]" value="<?= h($w['n_name']) ?>" list="naehrstoffliste" placeholder="Wirkstoff, z. B. Magnesium" style="flex:1">
          <input type="number" step="0.01" name="wirk_gehalt[]" value="<?= h($w['gehalt_prozent']) ?>" placeholder="Gehalt %" style="width:130px">
          <button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('.wirkrow').remove()">entfernen</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" id="addWirk">+ Wirkstoff</button>
      <datalist id="naehrstoffliste">
        <?php foreach ($naehrstoffe as $n): ?><option value="<?= h($n['name']) ?>"><?php endforeach; ?>
      </datalist>
      <div class="muted" style="margin-top:8px">Gehalt = Anteil dieses Wirkstoffs im Rohstoff (z. B. Kurkuma-Extrakt = 95 % Curcumin, Magnesiumcitrat = 16 % Magnesium).</div>
    </div>

    <div class="bx-panel"><div class="bx-grid">
      <div class="bx-field"><label>Dichte (g/ml) <?= bx_hint('Schüttdichte – bestimmt, wie viel in eine Kapsel passt') ?></label><input type="number" step="0.001" name="dichte" value="<?= $v('dichte') ?>"></div>
      <div class="bx-field"><label>Standard-Overage / Verlust (%) <?= bx_hint('Zuschlag/Schwund, der beim Einsatz einkalkuliert wird') ?></label><input type="number" step="0.01" name="overage_prozent" value="<?= $v('overage_prozent') ?>"></div>
      <div class="bx-field"><label>Herkunft</label><input type="text" name="herkunft" value="<?= $v('herkunft') ?>" placeholder="z. B. EU, Indien, China"></div>
    </div>
    <div class="bx-field"><label>Allergene <?= bx_hint('z. B. Gluten, Soja, Laktose – erscheint später auf Etikett/Deklaration') ?></label><input type="text" name="allergene" value="<?= $v('allergene') ?>" placeholder="kommagetrennt, oder „keine"></div>
    </div>
  </section>

  <section data-panel="spec" hidden>
    <div class="bx-panel"><h2>Identität</h2><div class="bx-grid">
      <div class="bx-field"><label>Synonym / RM-Nr</label><input type="text" name="synonym" value="<?= $v('synonym') ?>" placeholder="z. B. RM940"></div>
      <div class="bx-field"><label>EC-Nummer</label><input type="text" name="ec_nr" value="<?= $v('ec_nr') ?>"></div>
      <div class="bx-field"><label>Botanische Quelle / Pflanzenteil</label><input type="text" name="bot_quelle" value="<?= $v('bot_quelle') ?>" placeholder="z. B. Theobroma cacao – Bohne"></div>
      <div class="bx-field"><label>Herkunftsland</label><input type="text" name="herkunftsland" value="<?= $v('herkunftsland') ?>"></div>
    </div>
    <div class="muted">Name (lat.) und CAS-Nr. stehen im Reiter „Stammdaten".</div>
    </div>

    <div class="bx-panel">
      <h2>Charakteristische Kennwerte <?= bx_hint('das Unterscheidende – z. B. Fett 10–12 %, pH 7,5, Wirkstoff ≥ 95 %. Die Reinheits-Grenzwerte (Schwermetalle/Mikro/Mykotoxine) bleiben im PDF.') ?></h2>
      <div id="kwrows">
        <?php $krows = $kennwerte ?: [['parameter'=>'','wert'=>'']]; foreach ($krows as $kwr): ?>
        <div class="bx-row kwrow" style="flex-wrap:nowrap;margin-bottom:8px">
          <input type="text" name="kw_param[]" value="<?= h($kwr['parameter']) ?>" placeholder="Parameter, z. B. Fettgehalt" style="flex:1">
          <input type="text" name="kw_wert[]" value="<?= h($kwr['wert']) ?>" placeholder="Wert, z. B. 10–12 %" style="flex:1">
          <button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('.kwrow').remove()">entfernen</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" id="addKw">+ Kennwert</button>
    </div>

    <div class="bx-panel"><h2>Deklaration &amp; Status</h2>
      <?php $jn = function ($name, $cur) { $o = [''=>'– unbekannt –','1'=>'ja','0'=>'nein']; $s = '<select name="'.$name.'">'; foreach ($o as $val=>$lbl) { $s .= '<option value="'.$val.'" '.(((string)($cur ?? ''))===(string)$val?'selected':'').'>'.$lbl.'</option>'; } return $s.'</select>'; }; ?>
      <div class="bx-grid">
        <div class="bx-field"><label>Vegan / vegetarisch</label><?= $jn('vegan', $it['vegan'] ?? null) ?></div>
        <div class="bx-field"><label>GVO-frei</label><?= $jn('gvo_frei', $it['gvo_frei'] ?? null) ?></div>
        <div class="bx-field"><label>Bestrahlt / ETO</label><?= $jn('bestrahlt', $it['bestrahlt'] ?? null) ?></div>
        <div class="bx-field"><label>TSE/BSE-frei</label><?= $jn('tse_bse_frei', $it['tse_bse_frei'] ?? null) ?></div>
        <div class="bx-field"><label>Zertifikate</label><input type="text" name="zertifikate" value="<?= $v('zertifikate') ?>" placeholder="z. B. Bio, Fair Trade"></div>
        <div class="bx-field"><label>Zusätze / Verarbeitungshilfsstoffe</label><input type="text" name="zusaetze" value="<?= $v('zusaetze') ?>" placeholder="z. B. E501i, E330"></div>
      </div>
    </div>

    <div class="bx-panel"><h2>Handling</h2>
      <div class="bx-field" style="max-width:280px"><label>Haltbarkeit</label><input type="text" name="haltbarkeit" value="<?= $v('haltbarkeit') ?>" placeholder="z. B. 24 Monate"></div>
      <div class="bx-field"><label>Lagerbedingungen</label><textarea name="lagerbedingungen" placeholder="kühl & trocken, 18–22 °C, 50–60 % rF"><?= $v('lagerbedingungen') ?></textarea></div>
    </div>

    <div class="bx-panel"><h2>Spec-Dokument</h2><div class="bx-grid">
      <div class="bx-field"><label>Spez-Nr</label><input type="text" name="spec_nr" value="<?= $v('spec_nr') ?>"></div>
      <div class="bx-field"><label>Version</label><input type="text" name="spec_version" value="<?= $v('spec_version') ?>"></div>
      <div class="bx-field"><label>Gültig ab</label><input type="date" name="spec_gueltig_ab" value="<?= $v('spec_gueltig_ab') ?>"></div>
      <div class="bx-field"><label>Spec-PDF <?= bx_hint('das vollständige Lieferanten-Spec – Reinheit/Grenzwerte etc.') ?></label><input type="file" name="spec_pdf" accept="application/pdf"></div>
    </div>
    <?php if (!$neu && !empty($it['spec_pdf'])): ?>
      <div style="margin-top:8px"><?= bx_btn('Spec-PDF herunterladen', '?p=spec_pdf&id=' . (int)$id, 'primary') ?> <span class="muted"><?= h($it['spec_pdf']) ?></span></div>
    <?php endif; ?>
    </div>
  </section>

  <section data-panel="ek" hidden>
    <div class="bx-panel"><div class="bx-grid">
      <div class="bx-field"><label>EK-Preis <?= bx_hint('Einkaufspreis je Bezugseinheit') ?></label><input type="number" step="0.0001" name="ek_preis" value="<?= $v('ek_preis') ?>"></div>
      <?php if (darf_verkauf()): ?><div class="bx-field"><label>VK-Aufschlag (%) <?= bx_hint('Nur für Rohstoff-Weiterverkauf an Kunden. Leer = globaler Aufschlag aus den Einstellungen.') ?></label><input type="number" step="0.1" name="vk_aufschlag_prozent" value="<?= $v('vk_aufschlag_prozent') ?>" placeholder="<?= h((string)(float)meta_get('aufschlag_rohstoff','30')) ?> (Standard)"></div><?php endif; ?>
      <div class="bx-field"><label>Preis je</label>
        <select name="preis_bezug">
          <?php foreach (['kg'=>'kg','g'=>'g','Stück'=>'Stück','L'=>'Liter'] as $key=>$lbl): ?>
            <option value="<?= $key ?>" <?= ($it['preis_bezug']??'')===$key?'selected':'' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Haupt-Lieferant</label>
        <select name="haupt_lieferant_id">
          <option value="">– keiner –</option>
          <?php foreach ($lieferanten as $lf): ?>
            <option value="<?= $lf['id'] ?>" <?= (int)($it['haupt_lieferant_id']??0)===(int)$lf['id']?'selected':'' ?>><?= h($lf['firma']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div></div>
  </section>

  <?php if (!$neu): ?>
  <section data-panel="lager" hidden>
    <div class="bx-cards">
      <div class="bx-card"><div class="k">Bestand (frei)</div><div class="v"><?= $bestand_frei>0 ? h(rtrim(rtrim(number_format($bestand_frei,3,',','.'),'0'),',')).' '.$v('einheit') : '<span class="muted">0</span>' ?></div></div>
      <div class="bx-card"><div class="k">Quarantäne</div><div class="v" style="<?= $bestand_qua>0?'color:var(--warn)':'' ?>"><?= $bestand_qua>0 ? h(rtrim(rtrim(number_format($bestand_qua,3,',','.'),'0'),',')).' '.$v('einheit') : '<span class="muted">0</span>' ?></div></div>
      <div class="bx-card"><div class="k">Chargen</div><div class="v"><?= count($charges) ?></div></div>
    </div>
    <div class="bx-panel">
      <h2>Chargen <?= bx_hint('Wareneingang bucht neue Chargen; Rohstoffe starten in Quarantäne') ?></h2>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Charge</th><th class="bx-num">Verfügbar</th><th>MHD</th><th>Lieferant</th><th>Wareneingang</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (!$charges): ?><tr><td colspan="7" class="muted">Noch keine Chargen. Über „Wareneingang" buchen.</td></tr><?php endif; ?>
        <?php foreach ($charges as $c): ?>
          <tr>
            <td><?= h($c['charge_nr'] ?: '–') ?></td>
            <td class="bx-num"><?= h(rtrim(rtrim(number_format((float)$c['menge_verfuegbar'],3,',','.'),'0'),',')).' '.h($c['einheit']) ?></td>
            <td><?= $c['mhd'] ? h(date('d.m.Y',strtotime($c['mhd']))) : '<span class="muted">–</span>' ?></td>
            <td><?= $c['lieferant_firma'] ? h($c['lieferant_firma']) : '<span class="muted">–</span>' ?></td>
            <td><?= $c['wareneingang'] ? h(date('d.m.Y',strtotime($c['wareneingang']))) : '' ?></td>
            <td><?= match($c['status']){'frei'=>bx_badge('frei','ok'),'quarantaene'=>bx_badge('Quarantäne','warn'),'gesperrt'=>bx_badge('gesperrt','err'),default=>bx_badge(status_text($c['status']))} ?></td>
            <td class="bx-num">
              <a class="btn btn-ghost btn-sm" target="_blank" href="?p=coa_bulkify&id=<?= (int)$c['id'] ?>" title="Analysenzertifikat im bulkify-Layout – das geht an den Kunden">&#8681; CoA</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <div style="margin-top:12px"><?= bx_btn('Wareneingang buchen', '?p=wareneingang', 'primary') ?></div>
    </div>
      <?php // Analysewerte je Charge – die Grundlage für unser CoA. Der Lieferant schickt sein
            // Zertifikat; die Werte tragen wir hier ein und geben sie im bulkify-Layout weiter. ?>
      <?php if ($charges): ?>
      <div class="bx-panel">
        <h2 style="margin-top:0">Analysenwerte je Charge <?= bx_hint('Aus dem CoA des Lieferanten übertragen. Daraus entsteht unser Analysenzertifikat im bulkify-Layout – das Original des Lieferanten geht nicht an den Kunden.') ?></h2>
        <?php if (isset($_GET['analyse'])): ?><div class="badge-ok" style="padding:8px 12px;margin-bottom:10px">Analysenwerte gespeichert.</div><?php endif; ?>
        <div class="bx-field" style="max-width:320px"><label>Charge</label>
          <select id="an_charge" onchange="anZeige()">
            <?php foreach ($charges as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['charge_nr'] ?: ('Charge ' . (int)$c['id'])) ?></option><?php endforeach; ?>
          </select></div>
        <?php foreach ($charges as $c):
            $werte = all("SELECT * FROM charge_analyse WHERE charge_id=? ORDER BY sort, id", [(int)$c['id']]);
            // Vorschlagszeilen, damit man nicht vor einem leeren Blatt sitzt.
            if (!$werte) $werte = [
                ['parameter'=>'Aussehen', 'spezifikation'=>'', 'ergebnis'=>'', 'methode'=>'visuell'],
                ['parameter'=>'Identität', 'spezifikation'=>'entspricht', 'ergebnis'=>'', 'methode'=>'FT-IR'],
                ['parameter'=>'Gehalt', 'spezifikation'=>'', 'ergebnis'=>'', 'methode'=>'HPLC'],
                ['parameter'=>'Schwermetalle', 'spezifikation'=>'', 'ergebnis'=>'', 'methode'=>'ICP-MS'],
                ['parameter'=>'Gesamtkeimzahl', 'spezifikation'=>'', 'ergebnis'=>'', 'methode'=>'Ph. Eur.'],
            ];
            while (count($werte) < count($werte) + 2) { $werte[] = ['parameter'=>'', 'spezifikation'=>'', 'ergebnis'=>'', 'methode'=>'']; if (count($werte) > 12) break; }
        ?>
        <form method="post" class="an_form" data-charge="<?= (int)$c['id'] ?>" style="display:none">
          <input type="hidden" name="aktion" value="analyse_save">
          <input type="hidden" name="charge_id" value="<?= (int)$c['id'] ?>">
          <div class="bx-tablewrap"><table class="bx-table">
            <thead><tr><th>Parameter</th><th>Spezifikation</th><th>Ergebnis</th><th>Methode</th></tr></thead>
            <tbody><?php foreach ($werte as $w): ?>
              <tr><td><input type="text" name="a_par[]" value="<?= h($w['parameter']) ?>" style="width:100%"></td>
                  <td><input type="text" name="a_spec[]" value="<?= h($w['spezifikation'] ?? '') ?>" style="width:100%"></td>
                  <td><input type="text" name="a_erg[]" value="<?= h($w['ergebnis'] ?? '') ?>" style="width:100%"></td>
                  <td><input type="text" name="a_met[]" value="<?= h($w['methode'] ?? '') ?>" style="width:100%"></td></tr>
            <?php endforeach; ?></tbody>
          </table></div>
          <div class="bx-row" style="gap:10px;margin-top:10px">
            <button class="btn btn-primary" type="submit">Analysenwerte speichern</button>
            <a class="btn btn-ghost" target="_blank" href="?p=coa_bulkify&id=<?= (int)$c['id'] ?>">&#8681; CoA ansehen</a>
          </div>
        </form>
        <?php endforeach; ?>
        <script>
        function anZeige(){
          var v = document.getElementById('an_charge').value;
          document.querySelectorAll('.an_form').forEach(function(f){ f.style.display = (f.getAttribute('data-charge') === v) ? '' : 'none'; });
        }
        anZeige();
        </script>
      </div>
      <?php endif; ?>
  </section>
  <section data-panel="verw" hidden><div class="bx-panel"><h2>Verwendung in Rezepturen</h2><?php bx_bald('Rezepturen'); ?></div></section>
  <section data-panel="verlauf" hidden>
    <div class="bx-panel"><h2>Verlauf</h2><?php bx_chat($verlauf, $v('name')); ?></div>
  </section>
  <?php endif; ?>

  <div class="bx-row" style="margin-top:var(--sp-4)">
    <button class="btn btn-primary" type="submit"><?= $neu ? 'Rohstoff anlegen' : 'Speichern' ?></button>
    <a class="btn btn-ghost" href="?p=rohstoffe">Abbrechen</a>
  </div>
</form>

<?php if (!$neu): ?>
<?php if (isset($_GET['preisok'])) echo '<div class="bx-panel badge-ok" data-panel="ek" hidden style="padding:12px 16px">Preis gespeichert.</div>'; ?>
<section data-panel="ek" hidden>
  <div class="bx-panel">
    <h2>Lieferantenpreise (Staffel) <?= bx_hint('Angebote der Lieferanten je Menge – günstigster ist markiert. Basis für den Einkauf.') ?></h2>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Lieferant</th><th class="bx-num">ab Menge</th><th class="bx-num">Preis</th><th>Stand</th><th></th></tr></thead>
      <tbody>
      <?php if (!$preise): ?><tr><td colspan="5" class="muted">Noch keine Preise. Unten eintragen oder per Preisanfrage einholen.</td></tr><?php endif; ?>
      <?php $best = $preise ? (float)$preise[0]['preis'] : null; foreach ($preise as $pz): $ist_best = $best !== null && abs((float)$pz['preis'] - $best) < 0.0001; ?>
        <tr<?= $ist_best ? ' style="font-weight:600"' : '' ?>>
          <td><?= h($pz['firma'] ?: '–') ?> <?= $ist_best ? bx_badge('günstigster','ok') : '' ?></td>
          <td class="bx-num"><?= rtrim(rtrim(number_format((float)$pz['menge_ab'],3,',','.'),'0'),',') ?> <?= h($it['einheit']) ?></td>
          <td class="bx-num"><?= number_format((float)$pz['preis'], (float)$pz['preis']<1?4:2, ',', '.') ?> <?= h($pz['waehrung']) ?>/<?= h($it['preis_bezug']) ?></td>
          <td><?= $pz['stand'] ? h(date('d.m.Y', strtotime($pz['stand']))) : '' ?></td>
          <td style="text-align:right"><form method="post" style="display:inline"><input type="hidden" name="aktion" value="preis_del"><input type="hidden" name="preis_id" value="<?= (int)$pz['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">×</button></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <form method="post" class="bx-row" style="margin-top:12px;align-items:flex-end;gap:10px">
      <input type="hidden" name="aktion" value="preis_add">
      <div class="bx-field" style="margin:0"><label>Lieferant</label>
        <select name="lp_lieferant" required><option value="">– wählen –</option>
          <?php foreach ($preis_lieferanten as $l): ?><option value="<?= $l['id'] ?>"><?= h($l['firma']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field" style="margin:0;width:120px"><label>ab Menge</label><input type="number" step="0.001" name="lp_menge_ab" value="0"></div>
      <div class="bx-field" style="margin:0;width:120px"><label>Preis</label><input type="number" step="0.0001" name="lp_preis" required></div>
      <button class="btn btn-ghost btn-sm" type="submit">Preis hinzufügen</button>
    </form>
  </div>
</section>
<section data-panel="dok" hidden><?php dokument_panel('item', (int)$id, $lieferanten); ?></section>
<?php endif; ?>

<script>
(function(){
  var tabs = document.querySelectorAll('#itabs a');
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
  var urlTab = new URLSearchParams(location.search).get('tab');
  if (urlTab) { var t0 = document.querySelector('#itabs a[data-tab="' + urlTab + '"]'); if (t0) t0.click(); }
  // Kapselhülle: eigene Felder nur bei Form=kapselhuelle zeigen
  var formSel = document.getElementById('f_form');
  function applyForm(fromUser){
    var isKap = formSel && formSel.value === 'kapselhuelle';
    document.querySelectorAll('[data-only="kapselhuelle"]').forEach(function(el){ el.style.display = isKap ? '' : 'none'; });
    if (isKap && fromUser){
      var eh=document.querySelector('select[name="einheit"]'); if (eh && eh.value==='kg') eh.value='Stück';
      var pb=document.querySelector('select[name="preis_bezug"]'); if (pb && pb.value==='kg') pb.value='Stück';
    }
  }
  if (formSel) formSel.addEventListener('change', function(){ applyForm(true); });
  applyForm(false);
  var addW = document.getElementById('addWirk');
  if (addW) addW.addEventListener('click', function(){
    var row = document.createElement('div');
    row.className = 'bx-row wirkrow';
    row.style.cssText = 'flex-wrap:nowrap;margin-bottom:8px';
    row.innerHTML = '<input type="text" name="wirk_name[]" list="naehrstoffliste" placeholder="Wirkstoff, z. B. Magnesium" style="flex:1">'
      + '<input type="number" step="0.01" name="wirk_gehalt[]" placeholder="Gehalt %" style="width:130px">'
      + '<button type="button" class="btn btn-ghost btn-sm">entfernen</button>';
    row.querySelector('button').addEventListener('click', function(){ row.remove(); });
    document.getElementById('wirkrows').appendChild(row);
  });
  var addK = document.getElementById('addKw');
  if (addK) addK.addEventListener('click', function(){
    var row = document.createElement('div');
    row.className = 'bx-row kwrow';
    row.style.cssText = 'flex-wrap:nowrap;margin-bottom:8px';
    row.innerHTML = '<input type="text" name="kw_param[]" placeholder="Parameter, z. B. Fettgehalt" style="flex:1">'
      + '<input type="text" name="kw_wert[]" placeholder="Wert, z. B. 10–12 %" style="flex:1">'
      + '<button type="button" class="btn btn-ghost btn-sm">entfernen</button>';
    row.querySelector('button').addEventListener('click', function(){ row.remove(); });
    document.getElementById('kwrows').appendChild(row);
  });
})();
</script>
<?php
render_footer();
