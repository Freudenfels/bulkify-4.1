<?php
// Rohstoff / Item – anlegen & bearbeiten (mit leichtem Cockpit)
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/dokument_ui.php';
require_once BX_ROOT . '/core/anfrage_ui.php';   // Preisanfrage-Popup + Status-Badge

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
// Werte aus einem hochgeladenen Lieferanten-PDF VORSCHLAGEN (nicht speichern).
// Der Vorschlag landet im Formular und muss geprueft werden – ein falscher Wert auf einem
// Analysenzertifikat, das an den Kunden geht, waere schlimmer als ein leeres Feld.
$anVorschlag = null; $anVorschlagFehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'analyse_lesen' && !$neu) {
    require_once BX_ROOT . '/core/coa_lesen.php';
    $cid = (int)($_POST['charge_id'] ?? 0);
    $did = (int)($_POST['dok_id'] ?? 0);
    $d = $did ? one("SELECT datei FROM dokument WHERE id=? AND objekt_typ='item' AND objekt_id=?", [$did, (int)$id]) : null;
    if (!$d) {
        $anVorschlagFehler = 'Dokument nicht gefunden.';
    } else {
        $r = coa_werte_lesen(BX_UPLOADS . '/' . basename((string)$d['datei']));
        if (!$r['lesbar']) {
            $anVorschlagFehler = 'Aus diesem PDF laesst sich kein Text lesen – vermutlich ein Scan. Bitte die Werte von Hand eintragen.';
        } elseif (!$r['zeilen']) {
            $anVorschlagFehler = 'Text gelesen, aber keine bekannten Parameter gefunden. Bitte von Hand eintragen.';
        } else {
            $anVorschlag = ['charge_id' => $cid, 'zeilen' => $r['zeilen'], 'kopf' => $r['kopf']];
        }
    }
}
// Einen NEUEN Rohstoff aus einer Spezifikation anlegen: Datei hochladen, auslesen, das
// Anlegeformular damit vorbelegen. Gespeichert wird erst, wenn der Mensch auf Speichern drückt –
// die Datei liegt so lange nur in data/uploads und wird beim Speichern am Rohstoff abgelegt.
$neuKiFehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'spec_neu') {
    require_once BX_ROOT . '/core/spec_ki.php';
    if (!ki_bereit()) {
        $neuKiFehler = 'Die KI ist nicht eingerichtet (Einstellungen → KI).';
    } elseif (empty($_FILES['neu_spec']['name']) || ($_FILES['neu_spec']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        $neuKiFehler = 'Bitte eine Datei auswählen.';
    } else {
        if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
        $orig = (string)$_FILES['neu_spec']['name'];
        $ext  = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($orig, PATHINFO_EXTENSION)));
        $fn   = 'neu_' . bin2hex(random_bytes(6)) . ($ext ? '.' . $ext : '');
        if (!move_uploaded_file($_FILES['neu_spec']['tmp_name'], BX_UPLOADS . '/' . $fn)) {
            $neuKiFehler = 'Die Datei konnte nicht gespeichert werden.';
        } else {
            $r = spec_ki_lesen(BX_UPLOADS . '/' . $fn);
            if (!$r['ok']) { @unlink(BX_UPLOADS . '/' . $fn); $neuKiFehler = $r['fehler']; }
            else {
                $_SESSION['rohstoff_ki'] = ['datei' => $fn, 'orig' => $orig, 'ergebnis' => $r];
                header('Location: ?p=rohstoff&id=neu&ausspec=1'); exit;
            }
        }
    }
}
// Den vorbereiteten Vorschlag wieder verwerfen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'spec_neu_weg') {
    if (!empty($_SESSION['rohstoff_ki']['datei'])) @unlink(BX_UPLOADS . '/' . basename($_SESSION['rohstoff_ki']['datei']));
    unset($_SESSION['rohstoff_ki']);
    header('Location: ?p=rohstoff&id=neu'); exit;
}

// Eine Lieferantenunterlage mit der KI auslesen. Das Ergebnis ist ein VORSCHLAG: es steht in
// $kiVorschlag und wird beim Rendern angezeigt, gespeichert wird erst auf Knopfdruck.
$kiVorschlag = null; $kiFehler = ''; $kiDok = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'ki_lesen' && !$neu) {
    require_once BX_ROOT . '/core/spec_ki.php';
    $did = (int)($_POST['dok_id'] ?? 0);
    $d = $did ? one("SELECT datei FROM dokument WHERE id=? AND objekt_typ='item' AND objekt_id=?", [$did, (int)$id]) : null;
    if (!$d) {
        $kiFehler = 'Dokument nicht gefunden.';
    } else {
        $r = spec_ki_lesen(BX_UPLOADS . '/' . basename((string)$d['datei']));
        if (!$r['ok']) { $kiFehler = $r['fehler']; }
        else { spec_ki_merken($did, $r); $kiVorschlag = $r; $kiDok = $did; }
    }
}
// Einen früher gemerkten Vorschlag wieder anzeigen (z. B. den, den der Lieferant ausgelöst hat).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'ki_zeigen' && !$neu) {
    require_once BX_ROOT . '/core/spec_ki.php';
    $did = (int)($_POST['dok_id'] ?? 0);
    $kiVorschlag = spec_ki_vorschlag($did);
    $kiDok = $did;
    if (!$kiVorschlag) $kiFehler = 'Zu dieser Datei liegt kein Vorschlag vor.';
}
// Geprüfte Felder in die Stammdaten übernehmen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'ki_uebernehmen' && !$neu) {
    require_once BX_ROOT . '/core/spec_ki.php';
    $did = (int)($_POST['dok_id'] ?? 0);
    $vor = spec_ki_vorschlag($did);
    $n = $vor ? spec_ki_uebernehmen((int)$id, (array)($vor['stamm'] ?? []), (array)($_POST['feld'] ?? []), true) : 0;
    header('Location: ?p=rohstoff&id=' . (int)$id . '&kiueb=' . $n . '#spec'); exit;
}
// Analysewerte aus dem Vorschlag an einer Charge speichern.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'ki_werte' && !$neu) {
    require_once BX_ROOT . '/core/spec_ki.php';
    $vor = spec_ki_vorschlag((int)($_POST['dok_id'] ?? 0));
    $cid = (int)($_POST['charge_id'] ?? 0);
    $n = ($vor && $cid) ? spec_ki_werte_speichern($cid, (array)($vor['werte'] ?? [])) : 0;
    header('Location: ?p=rohstoff&id=' . (int)$id . '&kiwerte=' . $n . '#lager'); exit;
}

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
    $dokId = dokument_upload('item', (int)$id);
    // Frisch hochgeladene Spezifikationen und CoA gleich auslesen - der Vorschlag wartet dann
    // im Reiter Spezifikation auf die Pruefung.
    $coaChargeNeu = 0;
    if ($dokId) {
        require_once BX_ROOT . '/core/spec_ki.php';
        spec_ki_nach_upload($dokId);
        // Ist es eine CoA? Dann direkt eine (Vorab-)Charge mit den Werten anlegen und die
        // Grenzwerte am Rohstoff ergaenzen (fehlende), damit nichts verloren geht.
        $erg = spec_ki_vorschlag($dokId);
        if ($erg) {
            $lief = (int) scalar("SELECT haupt_lieferant_id FROM item WHERE id=?", [(int)$id]) ?: null;
            $coaChargeNeu = (int) (spec_ki_coa_charge((int)$id, $erg, $lief) ?? 0);
            spec_ki_grenzwerte((int)$id, $erg);
        }
    }
    header('Location: ?p=rohstoff&id=' . $id . '&tab=dok&gespeichert=1' . ($coaChargeNeu ? '&coacharge=' . $coaChargeNeu : '')); exit;
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
        $war_neu = $neu;
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
        // Grenzwerte (Reinheit/Mikrobiologie · Sollwert) synchronisieren
        q("DELETE FROM item_grenzwert WHERE item_id=?", [(int)$id]);
        $gp = $_POST['gw_param'] ?? []; $gw = $_POST['gw_wert'] ?? [];
        foreach ($gp as $i => $pn) { $pn = trim($pn); if ($pn === '') continue; q("INSERT INTO item_grenzwert (item_id,parameter,grenzwert,sort) VALUES (?,?,?,?)", [(int)$id, $pn, trim($gw[$i] ?? ''), (int)$i]); }
        // Spec-PDF hochladen (in data/uploads, außerhalb public)
        if (!empty($_FILES['spec_pdf']['name']) && is_uploaded_file($_FILES['spec_pdf']['tmp_name'] ?? '')) {
            if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
            $fn = 'spec_' . (int)$id . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $_FILES['spec_pdf']['name']);
            if (move_uploaded_file($_FILES['spec_pdf']['tmp_name'], BX_UPLOADS . '/' . $fn))
                q("UPDATE item SET spec_pdf=? WHERE id=?", [$fn, (int)$id]);
        }
        // Kam der Rohstoff aus einer hochgeladenen Spezifikation? Dann gehört die Datei jetzt an ihn.
        $coaChargeNeu = 0;
        if ($war_neu && !empty($_SESSION['rohstoff_ki']['datei'])) {
            require_once BX_ROOT . '/core/spec_ki.php';
            $ki = $_SESSION['rohstoff_ki'];
            $typ = ((($ki['ergebnis']['typ'] ?? '') === 'coa') || (($ki['ergebnis']['typ'] ?? '') === 'beides')) ? 'coa' : 'spec';
            q("INSERT INTO dokument (objekt_typ,objekt_id,typ,titel,datei,datei_orig,kunde_sichtbar) VALUES ('item',?,?,?,?,?,0)",
              [(int)$id, $typ, 'Aus dem Anlegen: ' . mb_substr((string)$ki['orig'], 0, 150), $ki['datei'], $ki['orig']]);
            spec_ki_merken((int)insert_id(), (array)$ki['ergebnis']);
            // Ist es eine CoA? Dann direkt eine (Vorab-)Charge mit den gemessenen Werten anlegen –
            // die Grenzwerte am Rohstoff kommen bereits aus dem Formular (oben synchronisiert).
            $lief = ($_POST['haupt_lieferant_id'] ?? '') !== '' ? (int)$_POST['haupt_lieferant_id'] : null;
            $coaChargeNeu = (int) (spec_ki_coa_charge((int)$id, (array)$ki['ergebnis'], $lief) ?? 0);
            unset($_SESSION['rohstoff_ki']);
        }
        header('Location: ?p=rohstoff&id=' . $id . '&gespeichert=1' . ($coaChargeNeu ? '&coacharge=' . $coaChargeNeu : '')); exit;
    }
}

$neuForm = ($_GET['form'] ?? '') === 'kapselhuelle' ? 'kapselhuelle' : 'pulver';
$neuDefault = $neuForm === 'kapselhuelle'
    ? ['kategorie'=>'rohstoff','form'=>'kapselhuelle','einheit'=>'Stück','preis_bezug'=>'Stück','gesperrt'=>0]
    : ['kategorie'=>'rohstoff','form'=>'pulver','einheit'=>'kg','preis_bezug'=>'kg','gesperrt'=>0];
// Liegt ein ausgelesener Vorschlag bereit, füllt er das Anlegeformular – Feld für Feld sichtbar,
// änderbar, und gespeichert wird erst beim Klick auf Speichern.
$neuKi = $neu ? ($_SESSION['rohstoff_ki']['ergebnis'] ?? null) : null;
if ($neuKi && !empty($neuKi['stamm'])) $neuDefault = array_merge($neuDefault, (array)$neuKi['stamm']);
// CAS-Vorschlag der KI (aus Fachwissen) einsetzen, wenn im Dokument keine CAS stand.
$casVorschlag = '';
if ($neuKi) {
    $casVorschlag = trim((string)($neuKi['cas_vorschlag'] ?? ''));
    if ($casVorschlag !== '' && trim((string)($neuDefault['cas'] ?? '')) === '') $neuDefault['cas'] = $casVorschlag;
}
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
$grenzwerte = $neu ? [] : all("SELECT * FROM item_grenzwert WHERE item_id=? ORDER BY sort, id", [(int)$id]);
// Neuanlage aus einer Spezifikation: Wirkstoffe (Assay), Kennwerte und Grenzwerte aus dem KI-Vorschlag
// vorbelegen, damit z. B. Eisen-Gehalt und Keim-/Metallgrenzwerte nicht verloren gehen. Werden im
// Formular angezeigt, geprüft, dann gespeichert.
if ($neu && $neuKi) {
    foreach ((array)($neuKi['wirkstoffe'] ?? []) as $w) {
        $nm = trim((string)($w['name'] ?? '')); if ($nm === '') continue;
        $g = $w['gehalt_prozent'] ?? null;
        $wirkstoffe[] = ['n_name' => $nm, 'gehalt_prozent' => ($g === null || $g === '') ? '' : (string)$g];
    }
    foreach ((array)($neuKi['kennwerte'] ?? []) as $kw) {
        $p = trim((string)($kw['parameter'] ?? '')); if ($p === '') continue;
        $kennwerte[] = ['parameter' => $p, 'wert' => trim((string)($kw['wert'] ?? ''))];
    }
    // Grenzwerte = die Sollwerte (spezifikation) aus den Analysewerten (Schwermetalle, Mikrobiologie ...).
    $gwSeen = [];
    foreach ((array)($neuKi['werte'] ?? []) as $z) {
        $p = trim((string)($z['parameter'] ?? '')); $g = trim((string)($z['spezifikation'] ?? ''));
        if ($p === '' || $g === '' || isset($gwSeen[$p])) continue;
        $gwSeen[$p] = true;
        $grenzwerte[] = ['parameter' => $p, 'grenzwert' => $g];
    }
}
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

if (isset($_GET['gespeichert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.'
    . (isset($_GET['coacharge']) ? ' Aus der CoA wurde eine Charge angelegt (noch ohne Ware) – bei der Warenannahme wird sie über die Chargennummer abgeglichen und eingebucht.' : '')
    . '</div>';
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
<?php // Beim Anlegen sofort sichtbar – und ausserhalb des Formulars, weil es ein eigenes hat. ?>
  <?php // Beim Anlegen: aus einer Spezifikation heraus starten. Spart das Abtippen und ist der
        // Weg, über den jeder Rohstoff von Anfang an Papiere hat.
        if ($neu): require_once BX_ROOT . '/core/spec_ki.php'; ?>
  <div class="bx-panel" style="border-color:var(--gruen)"><h2 style="margin-top:0">Rohstoff aus einer Spezifikation anlegen</h2>
    <?php if ($neuKiFehler !== ''): ?><div style="border:1px solid #e6c4c0;color:#8f231b;padding:8px 12px;margin-bottom:10px;border-radius:8px"><?= h($neuKiFehler) ?></div><?php endif; ?>
    <?php if ($neuKi): $anz = count((array)($neuKi['stamm'] ?? []));
          $anzW = count((array)($neuKi['wirkstoffe'] ?? [])); $anzK = count((array)($neuKi['kennwerte'] ?? [])); ?>
      <div class="badge-ok" style="padding:8px 12px;margin-bottom:10px">
        <strong><?= h((string)($_SESSION['rohstoff_ki']['orig'] ?? '')) ?></strong> ausgelesen –
        <?= (int)$anz ?> Stammfeld(er)<?php
          if ($anzW) echo ', ' . (int)$anzW . ' Wirkstoff(e)';
          if ($anzK) echo ', ' . (int)$anzK . ' Kennwert(e)';
          if ($neuKi['werte'] ?? []) echo ', ' . count($neuKi['werte']) . ' Analysenwerte';
        ?> unten eingetragen. Bitte prüfen (Reiter „Wirkstoff &amp; Qualität" und „Spezifikation") und dann speichern.
      </div>
      <?php if ($casVorschlag !== ''): ?>
        <div class="bx-panel" style="border-color:#d8c48a;background:#fbf6e6;color:#6b571e;padding:8px 12px;margin-bottom:10px">
          CAS-Nummer <strong><?= h($casVorschlag) ?></strong> stand nicht im Dokument – von der KI aus Fachwissen vorgeschlagen. Bitte vor dem Speichern prüfen (Reiter „Stammdaten").
        </div>
      <?php endif; ?>
      <div class="bx-row" style="gap:10px;align-items:center">
        <?= bx_badge('erkannt als ' . ['spec'=>'Spezifikation','coa'=>'Analysenzertifikat','beides'=>'Spezifikation + CoA','unklar'=>'unklar'][$neuKi['typ']], $neuKi['typ'] === 'unklar' ? 'warn' : 'info') ?>
        <?= bx_badge('Sicherheit ' . $neuKi['sicherheit'], $neuKi['sicherheit'] === 'hoch' ? 'ok' : ($neuKi['sicherheit'] === 'niedrig' ? 'warn' : 'info')) ?>
        <form method="post" style="margin:0"><input type="hidden" name="aktion" value="spec_neu_weg">
          <button class="btn btn-ghost btn-sm" type="submit">Vorschlag verwerfen</button></form>
      </div>
      <?php foreach ((array)($neuKi['hinweise'] ?? []) as $hw): ?><div class="muted" style="font-size:12px;margin-top:6px">Hinweis: <?= h($hw) ?></div><?php endforeach; ?>
      <p class="muted" style="font-size:12px;margin:10px 0 0">Die Datei wird beim Speichern am Rohstoff abgelegt. Erkannte Analysenwerte kannst du danach im Reiter Spezifikation an eine Charge übernehmen.</p>
    <?php elseif (!ki_bereit()): ?>
      <div class="muted">Die KI ist nicht eingerichtet (Einstellungen &rarr; KI). Lege den Rohstoff von Hand an.</div>
    <?php else: ?>
      <p class="muted" style="margin-top:0">Lade die Spezifikation oder das CoA des Lieferanten hoch – auch als Scan. Die Felder unten werden daraus vorbelegt, du prüfst sie und speicherst.</p>
      <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="aktion" value="spec_neu">
        <div class="bx-field" style="margin:0"><label>Unterlage</label>
          <input type="file" name="neu_spec" required accept="application/pdf,image/*"></div>
        <button class="btn btn-primary" type="submit" data-busy="Wird ausgelesen&#8230;">Auslesen und Felder füllen</button>
        <span class="muted" style="font-size:12px;align-self:center">dauert 10–60 Sekunden</span>
      </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>


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

    <div class="bx-panel">
      <h2>Grenzwerte (Reinheit / Mikrobiologie) <?= bx_hint('dauerhafte Sollwerte am Rohstoff: Schwermetalle, Keimbelastung, Mykotoxine … aus der Spezifikation. Jede neue Charge (CoA) wird dagegen abgeglichen. Aus einer CoA werden diese automatisch vorbelegt.') ?></h2>
      <div id="gwrows">
        <?php $grows = $grenzwerte ?: [['parameter'=>'','grenzwert'=>'']]; foreach ($grows as $gwr): ?>
        <div class="bx-row gwrow" style="flex-wrap:nowrap;margin-bottom:8px">
          <input type="text" name="gw_param[]" value="<?= h($gwr['parameter'] ?? '') ?>" placeholder="Parameter, z. B. Blei (Pb)" style="flex:1">
          <input type="text" name="gw_wert[]" value="<?= h($gwr['grenzwert'] ?? '') ?>" placeholder="Grenzwert, z. B. ≤ 3 ppm" style="flex:1">
          <button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('.gwrow').remove()">entfernen</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" id="addGw">+ Grenzwert</button>
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
    <?php if (!$neu): ?>
      <p class="muted" style="margin:14px 0 6px;font-size:13px">Aus den Feldern oben entsteht <strong>unsere eigene</strong> Spezifikation im bulkify-Layout. Sie geht an den Kunden – das Dokument des Vorlieferanten bleibt intern.</p>
      <div><a class="btn btn-ghost" target="_blank" href="?p=spec_bulkify&id=<?= (int)$id ?>">bulkify-Spezifikation ansehen</a></div>
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
  <section data-panel="verw" hidden><div class="bx-panel"><h2>Verwendung in Rezepturen</h2><?php bx_bald('Rezepturen'); ?></div></section>
  <section data-panel="verlauf" hidden>
    <div class="bx-panel"><h2>Verlauf</h2><?php bx_chat($verlauf, $v('name')); ?></div>
  </section>
  <?php endif; ?>

  <div class="bx-row" style="margin-top:var(--sp-4)">
    <button class="btn btn-primary" type="submit" data-busy="<?= $neu ? 'Wird angelegt&#8230;' : 'Wird gespeichert&#8230;' ?>"><?= $neu ? 'Rohstoff anlegen' : 'Speichern' ?></button>
    <a class="btn btn-ghost" href="?p=rohstoffe">Abbrechen</a>
  </div>
</form>

<?php // Diese beiden Abschnitte haben eigene Formulare und stehen deshalb ausserhalb
      // des Stammdaten-Formulars. Die Reiter-Logik blendet sie trotzdem korrekt ein. ?>
<section data-panel="spec" hidden>
  <?php // Unterlagen des Lieferanten mit der KI auslesen. Nichts wird ungefragt gespeichert:
        // jedes Feld hat einen Haken, und übernommen wird nur, was angehakt ist.
        if (!$neu):
          require_once BX_ROOT . '/core/spec_ki.php';
          $kiDocs = all("SELECT id, typ, titel, datei_orig, ki_stand FROM dokument WHERE objekt_typ='item' AND objekt_id=? ORDER BY id DESC", [(int)$id]);
          $kiFelder = spec_ki_felder();
  ?>
  <div class="bx-panel" id="kispec"><h2>Unterlage auslesen (KI)</h2>
    <p class="muted" style="margin-top:0">Liest eine Spezifikation oder ein Analysenzertifikat des Lieferanten – auch eingescannte – und schlägt die Felder vor. <strong>Gespeichert wird nur, was du anhakst.</strong></p>
    <?php if (isset($_GET['kiueb'])): ?><div class="badge-ok" style="padding:8px 12px;margin-bottom:10px"><?= (int)$_GET['kiueb'] ?> Feld(er) übernommen.</div><?php endif; ?>
    <?php if ($kiFehler !== ''): ?><div style="border:1px solid #e6c4c0;color:#8f231b;padding:8px 12px;margin-bottom:10px;border-radius:8px"><?= h($kiFehler) ?></div><?php endif; ?>
    <?php if (!$kiDocs): ?>
      <div class="muted">Noch keine Unterlagen hinterlegt. Lade im Reiter <strong>Dokumente</strong> eine Spezifikation oder ein CoA hoch – oder der Lieferant tut es in seinem Portal.</div>
    <?php elseif (!ki_bereit()): ?>
      <div class="muted">Die KI ist nicht eingerichtet (Einstellungen &rarr; KI).</div>
    <?php else: ?>
      <form method="post" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="aktion" value="ki_lesen">
        <div class="bx-field" style="margin:0;min-width:320px"><label>Unterlage</label>
          <select name="dok_id">
            <?php foreach ($kiDocs as $d): ?>
              <option value="<?= (int)$d['id'] ?>"><?= h(strtoupper((string)$d['typ'])) ?> · <?= h($d['titel'] ?: $d['datei_orig']) ?><?= $d['ki_stand'] ? ' (schon gelesen)' : '' ?></option>
            <?php endforeach; ?>
          </select></div>
        <button class="btn btn-primary" type="submit" data-busy="Wird ausgelesen&#8230;">Auslesen</button>
        <span class="muted" style="font-size:12px;align-self:center">dauert je nach Umfang 10–60 Sekunden</span>
      </form>
    <?php endif; ?>

    <?php if ($kiVorschlag && !empty($kiVorschlag['ok'])): $st = (array)($kiVorschlag['stamm'] ?? []); ?>
      <div style="margin-top:16px">
        <div class="bx-row" style="gap:10px;align-items:center;margin-bottom:8px">
          <strong>Vorschlag</strong>
          <?= bx_badge('erkannt als ' . ['spec'=>'Spezifikation','coa'=>'Analysenzertifikat','beides'=>'Spezifikation + CoA','unklar'=>'unklar'][$kiVorschlag['typ']], $kiVorschlag['typ'] === 'unklar' ? 'warn' : 'info') ?>
          <?= bx_badge('Sicherheit ' . $kiVorschlag['sicherheit'], $kiVorschlag['sicherheit'] === 'hoch' ? 'ok' : ($kiVorschlag['sicherheit'] === 'niedrig' ? 'warn' : 'info')) ?>
        </div>
        <?php foreach ((array)($kiVorschlag['hinweise'] ?? []) as $hw): ?>
          <div class="muted" style="font-size:12px">Hinweis: <?= h($hw) ?></div>
        <?php endforeach; ?>
        <?php if (!$st): ?>
          <div class="muted" style="margin-top:8px">Keine Stammdatenfelder gefunden.</div>
        <?php else: ?>
        <form method="post" style="margin-top:10px">
          <input type="hidden" name="aktion" value="ki_uebernehmen">
          <input type="hidden" name="dok_id" value="<?= (int)$kiDok ?>">
          <div class="bx-tablewrap"><table class="bx-table">
            <thead><tr><th style="width:34px"></th><th>Feld</th><th>Bisher</th><th>Vorschlag</th></tr></thead>
            <tbody>
            <?php foreach ($st as $k => $wert):
                    $alt = trim((string)($it[$k] ?? ''));
                    $jn  = $kiFelder[$k][1] === 'janein';
                    $zeig = fn($w) => $w === '' || $w === null ? '–' : ($jn ? ((int)$w === 1 ? 'ja' : 'nein') : (string)$w);
                    $gleich = (string)$wert === $alt; ?>
              <tr>
                <td><input type="checkbox" name="feld[]" value="<?= h($k) ?>"<?= $gleich ? '' : ' checked' ?><?= $gleich ? ' disabled' : '' ?>></td>
                <td><?= h($kiFelder[$k][0]) ?></td>
                <td class="muted"><?= h($zeig($alt)) ?></td>
                <td><strong><?= h($zeig($wert)) ?></strong><?= $gleich ? ' <span class="muted">(unverändert)</span>' : '' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
          <div class="bx-row" style="margin-top:10px"><button class="btn btn-primary" type="submit">Angehakte Felder übernehmen</button></div>
        </form>
        <?php endif; ?>

        <?php $kw = (array)($kiVorschlag['werte'] ?? []); if ($kw): $chg = all("SELECT id, charge_nr FROM charge WHERE item_id=? ORDER BY id DESC", [(int)$id]); ?>
          <div style="margin-top:18px"><strong>Analysenwerte</strong> <span class="muted">(<?= count($kw) ?> Zeilen)</span>
            <?php if (($kiVorschlag['charge']['charge_nr'] ?? '') !== ''): ?><span class="muted"> · Charge laut Dokument: <?= h($kiVorschlag['charge']['charge_nr']) ?></span><?php endif; ?>
          </div>
          <div class="bx-tablewrap" style="margin-top:6px"><table class="bx-table">
            <thead><tr><th>Parameter</th><th>Spezifikation</th><th>Ergebnis</th><th>Methode</th></tr></thead>
            <tbody><?php foreach ($kw as $z): ?>
              <tr><td><?= h($z['parameter']) ?></td><td><?= h($z['spezifikation']) ?></td><td><?= h($z['ergebnis']) ?></td><td><?= h($z['methode']) ?></td></tr>
            <?php endforeach; ?></tbody>
          </table></div>
          <?php if ($chg): ?>
          <form method="post" class="bx-row" style="gap:10px;align-items:flex-end;margin-top:10px">
            <input type="hidden" name="aktion" value="ki_werte">
            <input type="hidden" name="dok_id" value="<?= (int)$kiDok ?>">
            <div class="bx-field" style="margin:0;max-width:260px"><label>An welche Charge?</label>
              <select name="charge_id"><?php foreach ($chg as $c): ?><option value="<?= (int)$c['id'] ?>"<?= ($kiVorschlag['charge']['charge_nr'] ?? '') === $c['charge_nr'] ? ' selected' : '' ?>><?= h($c['charge_nr']) ?></option><?php endforeach; ?></select></div>
            <button class="btn btn-ghost" type="submit" onclick="return confirm('Die bisherigen Analysenwerte dieser Charge werden ersetzt.');">Werte an die Charge speichern</button>
          </form>
          <?php else: ?><div class="muted" style="margin-top:8px">Für diesen Rohstoff gibt es noch keine Charge, an die die Werte gehören könnten.</div><?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</section>

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
      <?php if ($anVorschlagFehler): ?><div style="border:1px solid #e6c4c0;color:#8f231b;padding:8px 12px;margin-bottom:10px;border-radius:8px"><?= h($anVorschlagFehler) ?></div><?php endif; ?>
      <?php if ($anVorschlag): ?><div class="badge-ok" style="padding:8px 12px;margin-bottom:10px">Vorschlag aus dem PDF eingetragen – bitte prüfen und dann speichern.<?= !empty($anVorschlag['kopf']) ? ' Gelesen: ' . h(implode(' · ', array_map(fn($kk, $vv) => $kk . ' ' . $vv, array_keys($anVorschlag['kopf']), $anVorschlag['kopf']))) : '' ?></div><?php endif; ?>
      <?php $liefDoks = all("SELECT id, typ, datei_orig FROM dokument WHERE objekt_typ='item' AND objekt_id=? ORDER BY id DESC", [(int)$id]);
            if ($liefDoks): ?>
      <form method="post" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px">
        <input type="hidden" name="aktion" value="analyse_lesen">
        <input type="hidden" name="charge_id" id="an_charge_lesen" value="<?= (int)($charges[0]['id'] ?? 0) ?>">
        <div class="bx-field" style="margin:0;min-width:280px"><label>Werte aus Lieferanten-PDF vorschlagen
          <?= bx_hint('Liest das hochgeladene CoA/Spec aus und trägt gefundene Werte ins Formular ein. Nur bei PDFs mit echtem Text – ein Scan lässt sich nicht lesen. Geprüft wird immer von Hand.') ?></label>
          <select name="dok_id"><?php foreach ($liefDoks as $d): ?><option value="<?= (int)$d['id'] ?>"><?= h(strtoupper($d['typ'])) ?> · <?= h($d['datei_orig']) ?></option><?php endforeach; ?></select></div>
        <button class="btn btn-ghost" type="submit">Werte vorschlagen</button>
      </form>
      <?php endif; ?>
      <div class="bx-field" style="max-width:320px"><label>Charge</label>
        <select id="an_charge" onchange="anZeige()">
          <?php foreach ($charges as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['charge_nr'] ?: ('Charge ' . (int)$c['id'])) ?></option><?php endforeach; ?>
        </select></div>
      <?php foreach ($charges as $c):
          $werte = all("SELECT * FROM charge_analyse WHERE charge_id=? ORDER BY sort, id", [(int)$c['id']]);
          // Vorschlagszeilen, damit man nicht vor einem leeren Blatt sitzt.
          // Ein Vorschlag aus dem Lieferanten-PDF ersetzt die Standardzeilen.
          if ($anVorschlag && (int)$anVorschlag['charge_id'] === (int)$c['id']) {
              $werte = array_map(fn($v) => ['parameter'=>$v[0], 'spezifikation'=>$v[1], 'ergebnis'=>$v[2], 'methode'=>$v[3]], $anVorschlag['zeilen']);
          } elseif (!$werte) $werte = [
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
        var s = document.getElementById('an_charge'), v = s.value;
        var hid = document.getElementById('an_charge_lesen'); if (hid) hid.value = v;
        document.querySelectorAll('.an_form').forEach(function(f){ f.style.display = (f.getAttribute('data-charge') === v) ? '' : 'none'; });
      }
      anZeige();
      </script>
    </div>
    <?php endif; ?>
</section>


<?php if (!$neu): ?>
<?php if (isset($_GET['preisok'])) echo '<div class="bx-panel badge-ok" data-panel="ek" hidden style="padding:12px 16px">Preis gespeichert.</div>'; ?>
<section data-panel="ek" hidden>
  <div class="bx-panel">
    <div class="bx-row" style="justify-content:space-between;align-items:center">
      <h2 style="margin:0">Lieferantenpreise (Staffel) <?= bx_hint('Angebote der Lieferanten je Menge – günstigster ist markiert. Basis für den Einkauf.') ?></h2>
      <div class="bx-row" style="gap:10px;align-items:center">
        <?= anfrage_badge((int)$id) ?>
        <button type="button" class="btn btn-primary btn-sm" data-name="<?= $v('name') ?>" onclick="bxAnfrageOeffnen(<?= (int)$id ?>,this)">Preis anfragen</button>
      </div>
    </div>
    <?php if (isset($_GET['angefragt'])): ?><div class="badge-ok" style="padding:8px 12px;margin:10px 0"><?= (int)$_GET['angefragt'] ?> Preisanfrage(n) verschickt<?= isset($_GET['gemailt']) && (int)$_GET['gemailt'] > 0 ? ', davon ' . (int)$_GET['gemailt'] . ' per E-Mail' : '' ?>.</div><?php endif; ?>
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
<?php anfrage_modal(all("SELECT id, firma, land FROM lieferanten WHERE gesperrt=0 ORDER BY firma"), '?p=rohstoff&id=' . (int)$id . '&tab=ek'); ?>
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
  var addG = document.getElementById('addGw');
  if (addG) addG.addEventListener('click', function(){
    var row = document.createElement('div');
    row.className = 'bx-row gwrow';
    row.style.cssText = 'flex-wrap:nowrap;margin-bottom:8px';
    row.innerHTML = '<input type="text" name="gw_param[]" placeholder="Parameter, z. B. Blei (Pb)" style="flex:1">'
      + '<input type="text" name="gw_wert[]" placeholder="Grenzwert, z. B. ≤ 3 ppm" style="flex:1">'
      + '<button type="button" class="btn btn-ghost btn-sm">entfernen</button>';
    row.querySelector('button').addEventListener('click', function(){ row.remove(); });
    document.getElementById('gwrows').appendChild(row);
  });
})();
</script>
<?php
render_footer();
