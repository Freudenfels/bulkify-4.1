<?php
// Produktseite (Umbau Nico 09.11.2026, Vorbild Auftragsseite): Kopf + Kacheln (ändern per Stift -> Fenster -> Speichern,
// jede Kachel speichert für sich) + Reiter Rezeptur · Kunden · Beschaffung · Produktion · Kalkulation · Dokumente.
// Je Sache genau eine Stelle. Neuanlage (?p=produkt&id=neu) ist ein kurzes Formular; danach geht es hier weiter.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/dokument_ui.php';
require_once BX_ROOT . '/core/preis_gueltig.php';   // „gültig bis" an Zukaufpreisen
require_once BX_ROOT . '/core/spec_ki.php';             // Spec/CoA am Produkt per KI auslesen
require_once BX_ROOT . '/core/anfrage_ui.php';          // „Beim Lieferanten anfragen" (Fertigprodukt-Anfrage)
require_once BX_ROOT . '/core/produkt_kalkulation.php'; // Kosten je Packung, Beschaffung, Kunden (nur lesen)
require_once BX_ROOT . '/core/produkt_bestellungen.php'; // Reiter Bestellungen: wer hat gekauft, Charge + MHD (nur lesen)

$DFORM = ['kapsel'=>'Kapsel','tablette'=>'Tablette','softgel'=>'Softgel','stick'=>'Stick','gummi'=>'Fruchtgummi','gel'=>'Gel','pulver'=>'Pulver','fluessig'=>'Flüssig','granulat'=>'Granulat'];
$id  = $_GET['id'] ?? 'neu';
$neu = ($id === 'neu' || !is_numeric($id));
$istPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$aktion = (string)($_POST['aktion'] ?? '');
$zurueck = fn(string $q = '', string $anker = '') => header('Location: ?p=produkt&id=' . (int)$id . $q . ($anker !== '' ? '#' . $anker : ''));
$fehler = '';

// ---------------------------------------------------------------- Aktionen (bestehendes Produkt)
if ($istPost && !$neu) {
    $pid = (int)$id;
    if ($aktion === 'matrix') { $n = produkt_matrix_generieren($pid); $zurueck('&matrix=' . $n . '&tab=kalkulation'); exit; }
    if ($aktion === 'dok_upload') {
        // EIN Upload für alle Dokumenttypen; bei Spec/CoA/Analyse liest die KI aus (nur beta) und merkt den Vorschlag am Dokument.
        $dokId = dokument_upload('produkt', $pid);
        $gelesen = ($dokId && in_array($_POST['dok_typ'] ?? '', ['spec', 'coa', 'analyse'], true)) ? (spec_ki_nach_upload($dokId) ? 1 : 0) : 0;
        $zurueck('&gespeichert=1&tab=dokumente' . ($gelesen ? '&kigelesen=1' : ''), 'dok'); exit;
    }
    if ($aktion === 'pib_upload') { require_once BX_ROOT . '/core/pdf_pib.php'; pib_upload($pid); $zurueck('&pib=1&tab=dokumente', 'pibfeld'); exit; }
    if ($aktion === 'pib_del')    { require_once BX_ROOT . '/core/pdf_pib.php'; pib_del($pid);    $zurueck('&pibweg=1&tab=dokumente', 'pibfeld'); exit; }
    if ($aktion === 'spec_uebernehmen') {
        $vor = spec_ki_vorschlag((int)($_POST['dok_id'] ?? 0)); $stamm = (array)($vor['stamm'] ?? []); $n = 0;
        foreach (['haltbarkeit' => 60, 'allergene' => 255] as $f => $len)
            if (trim((string)($stamm[$f] ?? '')) !== '') { q("UPDATE produkt SET `$f`=? WHERE id=?", [mb_substr((string)$stamm[$f], 0, $len), $pid]); $n++; }
        $zurueck('&specueb=' . $n . '&tab=dokumente', 'dok'); exit;
    }
    if ($aktion === 'zk_preis_add') {
        $lid   = ($_POST['zk_lieferant'] ?? '') !== '' ? (int)$_POST['zk_lieferant'] : null;
        $lname = trim((string)($_POST['zk_lieferant_name'] ?? ''));
        $preis = zahl_lesen((string)($_POST['zk_preis'] ?? '0'));   // Preis: Komma als Dezimaltrenner (0,0228)
        $mab   = zahl_lesen((string)($_POST['zk_menge_ab'] ?? '0'), true);   // Menge: Tausenderpunkt (60.000)
        $inco  = array_key_exists($_POST['zk_incoterm'] ?? '', incoterm_liste()) ? $_POST['zk_incoterm'] : null;
        $vers  = array_key_exists($_POST['zk_versandart'] ?? '', versandart_liste()) ? $_POST['zk_versandart'] : null;
        $zkEinh = ($_POST['zk_einheit'] ?? '') === 'packung' ? 'packung' : (($_POST['zk_einheit'] ?? '') === 'kg' ? 'kg' : 'kapsel');
        if ($preis > 0) q("INSERT INTO produkt_lieferant_preis (produkt_id,lieferant_id,lieferant_name,menge_ab,preis,einheit,incoterm,versandart,waehrung,stand,gueltig_bis) VALUES (?,?,?,?,?,?,?,?, 'EUR', CURDATE(), ?)",
            [$pid, $lid, $lname ?: null, $mab, $preis, $zkEinh, $inco, $vers, pg_lesen((string)($_POST['zk_gueltig_bis'] ?? ''))]);
        $zurueck('&zkok=1&tab=beschaffung', 'zukauf'); exit;
    }
    if ($aktion === 'zk_preis_del') { q("DELETE FROM produkt_lieferant_preis WHERE id=? AND produkt_id=?", [(int)($_POST['preis_id'] ?? 0), $pid]); $zurueck('&tab=beschaffung', 'zukauf'); exit; }
    // Kundenpreis anlegen/ändern (vereinbarter VK je Packung ohne Verpackung) – im Reiter Kunden je Zeile.
    if ($aktion === 'kp_add') {
        $kpk = (int)($_POST['kp_kunde'] ?? 0);
        $kpm = trim((string)($_POST['kp_menge'] ?? '')) !== '' ? max(0, (int) str_replace('.', '', (string)$_POST['kp_menge'])) : null;
        $kpa = trim((string)($_POST['kp_anzahl'] ?? '')) !== '' ? max(0, (int) str_replace('.', '', (string)$_POST['kp_anzahl'])) : null;
        $kpp = zahl_lesen((string)($_POST['kp_preis'] ?? '0'));
        $kpn = trim((string)($_POST['kp_notiz'] ?? ''));
        if ($kpk > 0 && $kpp > 0) {
            $kpEx = $kpm ? (int) scalar("SELECT id FROM produkt_kundenpreis WHERE produkt_id=? AND kunde_id=? AND menge_pro_vpe=? AND COALESCE(anzahl_vpe,0)=? LIMIT 1", [$pid, $kpk, $kpm, (int)($kpa ?? 0)]) : 0;
            if ($kpEx) q("UPDATE produkt_kundenpreis SET preis=?, notiz=? WHERE id=?", [$kpp, $kpn ?: null, $kpEx]);
            else q("INSERT INTO produkt_kundenpreis (produkt_id,kunde_id,menge_pro_vpe,anzahl_vpe,preis,notiz) VALUES (?,?,?,?,?,?)", [$pid, $kpk, $kpm, $kpa, $kpp, $kpn ?: null]);
            log_aktivitaet('produkt', $pid, 'team', 'Kundenpreis gesetzt: ' . number_format($kpp, 2, ',', '.') . ' € je Packung' . ($kpm ? ' (' . $kpm . ' je VPE)' : '') . '.', 'notiz');
        }
        if (trim((string)($_POST['kn_name'] ?? '')) !== '' && $kpk > 0) produkt_kundenname_setzen($pid, $kpk, trim((string)$_POST['kn_name']));
        $zurueck('&kpok=1&tab=kunden', 'kundennamen'); exit;
    }
    if ($aktion === 'kp_del') { q("DELETE FROM produkt_kundenpreis WHERE id=? AND produkt_id=?", [(int)($_POST['kp_id'] ?? 0), $pid]); $zurueck('&tab=kunden', 'kundennamen'); exit; }
    if ($aktion === 'kn_setzen') {
        $knk = (int)($_POST['kn_kunde'] ?? 0); $knn = trim((string)($_POST['kn_name'] ?? ''));
        if ($knk > 0) {
            produkt_kundenname_setzen($pid, $knk, $knn);
            log_aktivitaet('produkt', $pid, 'team', $knn !== '' ? 'Kundenspezifischer Name gesetzt für Kunde #' . $knk . ': „' . $knn . '".' : 'Kundenspezifischer Name entfernt für Kunde #' . $knk . '.', 'notiz');
        }
        $zurueck('&knok=1&tab=kunden', 'kundennamen'); exit;
    }
    if ($aktion === 'kn_del') { produkt_kundenname_setzen($pid, (int)($_POST['kn_kunde'] ?? 0), ''); $zurueck('&tab=kunden', 'kundennamen'); exit; }
    if ($aktion === 'dok_frei') { dokument_freigabe_toggle('produkt', $pid, (int)($_POST['dok_id'] ?? 0)); $zurueck('&tab=dokumente', 'dok'); exit; }
    if ($aktion === 'dok_del')  { dokument_delete('produkt', $pid, (int)($_POST['dok_id'] ?? 0));          $zurueck('&tab=dokumente', 'dok'); exit; }
    if ($aktion === 'novelfood_pruefen') {
        $erg = produkt_novelfood_pruefen($pid);
        if ($erg['status'] !== 'unklar') q("UPDATE produkt SET novelfood_status=? WHERE id=?", [$erg['status'], $pid]);
        $_SESSION['nf_ergebnis'] = $erg;
        $zurueck('&nfcheck=1'); exit;
    }
    if ($aktion === 'loeschen') {
        if (!has_role('admin')) { $zurueck(); exit; }
        $r = produkt_loeschen($pid);
        if (!empty($r['ok'])) { header('Location: ?p=produkte&geloescht=1'); exit; }
        $_SESSION['prod_del_fehler'] = $r['fehler'] ?? 'Löschen fehlgeschlagen.';
        $zurueck(); exit;
    }
    // Eine Kachel speichern: genau das Feld dieser Kachel (jede Kachel für sich).
    if ($aktion === 'kachel') {
        $alt = one("SELECT * FROM produkt WHERE id=?", [$pid]) ?: [];
        $feld = (string)($_POST['feld'] ?? '');
        $iid = fn($k) => ($_POST[$k] ?? '') !== '' ? (int)$_POST[$k] : null;
        $txt = '';
        switch ($feld) {
            case 'name':
                $name = trim((string)($_POST['name'] ?? ''));
                if ($name === '') { $zurueck('&kfehler=' . urlencode('Name ist ein Pflichtfeld.')); exit; }
                $name = produkt_name_versioniert($name, $pid);
                $syn = trim((string)($_POST['synonyme'] ?? ''));
                $altAnz = (string)($alt['name'] ?? '');
                if ($altAnz !== '' && $altAnz !== $name && mb_stripos($syn, $altAnz) === false) $syn = trim(($syn !== '' ? $syn . "\n" : '') . $altAnz);   // alter Name wird Synonym
                q("UPDATE produkt SET name=?, synonyme=?, kundenname=? WHERE id=?", [$name, $syn ?: null, trim((string)($_POST['kundenname'] ?? '')) ?: null, $pid]);
                $txt = 'Name/Synonyme geändert'; break;
            case 'rezeptur':
                q("UPDATE produkt SET rezeptur_id=? WHERE id=?", [$iid('rezeptur_id'), $pid]); $txt = 'Rezeptur geändert'; break;
            case 'kapsel':
                if (!empty($alt['rezeptur_id'])) { q("UPDATE rezeptur SET kapselgroesse_id=? WHERE id=?", [$iid('kapselgroesse_id'), (int)$alt['rezeptur_id']]); $txt = 'Kapselgröße an der Rezeptur geändert'; }
                break;
            case 'menge':
                $m = (int) str_replace('.', '', (string)($_POST['einheiten_pro_packung'] ?? '0'));
                q("UPDATE produkt SET einheiten_pro_packung=? WHERE id=?", [max(0, $m), $pid]); $txt = 'Menge je Packung: ' . $m; break;
            case 'verpackung':
                $vp = $iid('verpackung_id');
                q("UPDATE produkt SET verpackung_id=? WHERE id=?", [$vp, $pid]);
                if ((int)($alt['verpackung_id'] ?? 0) !== (int)($vp ?? 0)) produkt_name_behaelter_nachziehen($pid, (int)($alt['verpackung_id'] ?? 0), (int)($vp ?? 0), 'produkt');
                if (function_exists('bedarf_bump')) bedarf_bump();
                $txt = 'Glas/Behälter geändert'; break;
            case 'deckel':
                q("UPDATE produkt SET verschluss_id=? WHERE id=?", [$iid('verschluss_id'), $pid]); if (function_exists('bedarf_bump')) bedarf_bump(); $txt = 'Deckel geändert'; break;
            case 'leerkapsel':
                q("UPDATE produkt SET leerkapsel_id=? WHERE id=?", [$iid('leerkapsel_id'), $pid]); $txt = 'Leerkapsel geändert'; break;
            case 'verzehr':
                $t = (float) str_replace(',', '.', (string)($_POST['einnahme_pro_tag'] ?? '1'));
                q("UPDATE produkt SET einnahme_pro_tag=? WHERE id=?", [$t > 0 ? $t : 1, $pid]); $txt = 'Verzehr je Tag geändert'; break;
            case 'haltbarkeit':
                q("UPDATE produkt SET haltbarkeit=?, allergene=? WHERE id=?", [mb_substr(trim((string)($_POST['haltbarkeit'] ?? '')), 0, 60) ?: null, mb_substr(trim((string)($_POST['allergene'] ?? '')), 0, 255) ?: null, $pid]);
                $txt = 'Haltbarkeit/Allergene geändert'; break;
            case 'status':
                $st = in_array($_POST['status'] ?? '', ['entwurf', 'aktiv', 'inaktiv'], true) ? $_POST['status'] : 'entwurf';
                q("UPDATE produkt SET status=? WHERE id=?", [$st, $pid]); $txt = 'Status: ' . $st; break;
            case 'novelfood':
                $nf = in_array($_POST['novelfood_status'] ?? '', ['unklar','konform','novel_food','pruefung'], true) ? $_POST['novelfood_status'] : 'unklar';
                q("UPDATE produkt SET novelfood_status=? WHERE id=?", [$nf, $pid]); $txt = 'Novel-Food-Status geändert'; break;
            case 'mwst':
                if (has_role('admin')) { $ms = trim((string)($_POST['mwst_satz'] ?? '')); q("UPDATE produkt SET mwst_satz=? WHERE id=?", [$ms === '' ? null : mwst_normalisieren((float) str_replace(',', '.', $ms)), $pid]); $txt = 'MwSt-Satz geändert'; }
                break;
            case 'katalog':
                $exkl = isset($_POST['exklusiv']) ? 1 : 0;
                $kid = $exkl ? $iid('kunde_id') : null;   // Kunde ist nur bei exklusiv der Besitzer
                if ((int)($alt['extern'] ?? 0) === 1) { $exkl = 1; $kid = $kid ?: ((int)($alt['kunde_id'] ?? 0) ?: null); }
                q("UPDATE produkt SET exklusiv=?, kunde_id=? WHERE id=?", [$exkl, $kid, $pid]); $txt = $exkl ? 'exklusiv gesetzt' : 'Katalogprodukt'; break;
            case 'art':
                $art = (string)($_POST['art'] ?? 'eigen');
                $ext = $art === 'fulfillment' ? 1 : 0; $hw = $art === 'handelsware' ? 1 : 0;
                if ($ext && empty($alt['kunde_id']) && !$iid('kunde_id')) { $zurueck('&kfehler=' . urlencode('Für „Nur Fulfillment" bitte den Kunden wählen.')); exit; }
                q("UPDATE produkt SET extern=?, handelsware=? WHERE id=?", [$ext, $hw, $pid]);
                if ($ext) q("UPDATE produkt SET exklusiv=1, kunde_id=COALESCE(?, kunde_id), rezeptur_id=NULL WHERE id=?", [$iid('kunde_id'), $pid]);   // Kundenware: gehört dem Kunden, keine Rezeptur
                $txt = 'Art des Produkts: ' . $art; break;
            case 'produktion':
                // Reiter Produktion: Stückliste (Karton, Packungen je Umkarton, Beipackzettel, abweichendes Etikett), Standard-Produktionsweg, Notiz.
                q("UPDATE produkt SET karton_id=?, karton_inhalt=?, beipack_id=?, etikett_id=?, notiz=? WHERE id=?",
                  [$iid('karton_id'), trim((string)($_POST['karton_inhalt'] ?? '')) === '' ? null : max(0, (int) str_replace('.', '', (string)$_POST['karton_inhalt'])),
                   $iid('beipack_id'), $iid('etikett_id'), trim((string)($_POST['notiz'] ?? '')), $pid]);
                if (has_role('admin')) foreach (['abfuellen', 'etikettieren', 'karton', 'beipack'] as $wf) q("UPDATE produkt SET weg_$wf=? WHERE id=?", [isset($_POST['weg_' . $wf]) ? 1 : 0, $pid]);
                if (function_exists('bedarf_bump')) bedarf_bump();
                $txt = 'Stückliste/Produktionsweg geändert';
                log_aktivitaet('produkt', $pid, 'team', $txt . '.', 'notiz');
                $zurueck('&gespeichert=1&tab=produktion'); exit;
        }
        if ($txt !== '') log_aktivitaet('produkt', $pid, 'team', $txt . '.', 'notiz');
        $zurueck('&gespeichert=1'); exit;
    }
}

// ---------------------------------------------------------------- Neuanlage (kurzes Formular)
if ($istPost && $neu && $aktion === 'anlegen') {
    $name = trim((string)($_POST['name'] ?? ''));
    $art  = (string)($_POST['art'] ?? 'eigen');
    $ext  = $art === 'fulfillment' ? 1 : 0;
    $kid  = ($_POST['kunde_id'] ?? '') !== '' ? (int)$_POST['kunde_id'] : null;
    $exkl = ($ext || isset($_POST['exklusiv'])) ? 1 : 0;
    if ($name === '') $fehler = 'Name ist ein Pflichtfeld.';
    elseif ($ext && !$kid) $fehler = 'Für ein externes Produkt (Kundenware) bitte den Kunden wählen.';
    else {
        $rid = (!$ext && ($_POST['rezeptur_id'] ?? '') !== '') ? (int)$_POST['rezeptur_id'] : null;
        $vp  = ($_POST['verpackung_id'] ?? '') !== '' ? (int)$_POST['verpackung_id'] : null;
        $m   = (int) str_replace('.', '', (string)($_POST['einheiten_pro_packung'] ?? '0'));
        $name = produkt_name_versioniert($name, 0);
        q("INSERT INTO produkt (nummer,name,kunde_id,rezeptur_id,verpackung_id,exklusiv,extern,handelsware,einheiten_pro_packung,einnahme_pro_tag,status,novelfood_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
          [naechste_nummer('P'), $name, $exkl ? $kid : null, $rid, $vp, $exkl, $ext, $art === 'handelsware' ? 1 : 0, max(0, $m), 1, $ext ? 'aktiv' : 'entwurf', 'unklar']);
        $nid = (int) insert_id();
        log_aktivitaet('kunde', (int)($kid ?: 0), 'team', 'Produkt „' . $name . '" angelegt.', 'produkt', $nid);
        header('Location: ?p=produkt&id=' . $nid . '&gespeichert=1'); exit;
    }
}

// ---------------------------------------------------------------- Daten
$kunden = all("SELECT id, firma FROM kunden WHERE COALESCE(gesperrt,0)=0 OR id=? ORDER BY firma", [$neu ? 0 : (int) scalar("SELECT COALESCE(kunde_id,0) FROM produkt WHERE id=?", [(int)$id])]);   // Block C: gesperrte nicht auswählbar (der schon gewählte bleibt sichtbar)
$rscombo = fn(array $liste) => count($liste) > 10 ? ' class="rscombo"' : '';
$optListe = function (array $rows, $cur, string $leer = '– keine –', ?callable $lbl = null) {
    $o = '<option value="">' . h($leer) . '</option>';
    foreach ($rows as $r) $o .= '<option value="' . (int)$r['id'] . '"' . ((int)$cur === (int)$r['id'] ? ' selected' : '') . '>' . h($lbl ? $lbl($r) : (string)$r['name']) . '</option>';
    return $o;
};
// Primärverpackungen = Gläser, Dosen, Beutel, Flaschen (Rolle primaer, ohne Kundenetiketten-Artikel); Deckel = nur Verschlüsse.
$verpListe = fn(string $rolle, int $pid = 0) => all("SELECT id, name FROM item WHERE kategorie='verpackung' AND gesperrt=0 AND COALESCE(verpackung_rolle,'primaer')=?
                                                       AND (? <> 'etikett' OR produkt_id IS NULL OR produkt_id=?) ORDER BY name", [$rolle, $rolle, $pid]);

if ($neu) {
    $rezepte = all("SELECT id, name, darreichungsform FROM rezeptur WHERE COALESCE(status,'')<>'inaktiv' ORDER BY name");   // Block C: inaktive Rezepturen nicht auswählbar
    $glaeser = $verpListe('primaer');
    $istExtern = isset($_GET['extern']);
    render_header('produkte', 'Neues Produkt');
    bx_head('Neues Produkt', 'Rezeptur × Menge je Packung × Behälter – danach alles weitere per Stift', bx_btn('Zurück zur Liste', '?p=produkte', 'ghost'));
    if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b">' . h($fehler) . '</div>';
    ?>
<form method="post" class="bx-form bx-panel" style="max-width:820px">
  <input type="hidden" name="aktion" value="anlegen">
  <div class="bx-grid">
    <div class="bx-field"><label>Produktname (intern)</label><input type="text" name="name" required value="<?= h((string)($_POST['name'] ?? '')) ?>" placeholder="z. B. Zink"></div>
    <div class="bx-field"><label>Art des Produkts</label>
      <select name="art"><option value="eigen">Eigenes Produkt</option><option value="handelsware">Handelsware (fertig zugekauft)</option><option value="fulfillment"<?= $istExtern ? ' selected' : '' ?>>Nur Fulfillment (Kundenware)</option></select></div>
    <div class="bx-field"><label>Rezeptur</label><select name="rezeptur_id"<?= $rscombo($rezepte) ?>><?= $optListe($rezepte, 0, '– wählen –', fn($r) => $r['name'] . ' · ' . ($DFORM[$r['darreichungsform']] ?? $r['darreichungsform'])) ?></select></div>
    <div class="bx-field"><label>Glas/Behälter</label><select name="verpackung_id"<?= $rscombo($glaeser) ?>><?= $optListe($glaeser, 0) ?></select></div>
    <div class="bx-field"><label>Menge je Packung <?= bx_hint('Kapseln/Tabletten je Packung, bei Pulver g, bei Flüssig ml.') ?></label><input type="text" inputmode="numeric" name="einheiten_pro_packung" placeholder="z. B. 90"></div>
    <div class="bx-field"><label>Kunde <?= bx_hint('Nur bei exklusiven Produkten bzw. Kundenware der Besitzer.') ?></label><select name="kunde_id"<?= $rscombo($kunden) ?>><?= $optListe($kunden, (int)($_GET['kunde_id'] ?? 0), '– Katalogprodukt –', fn($r) => kunde_name_voll((int)$r['id'], (string)$r['firma'], false)) ?></select></div>
  </div>
  <label class="bx-check" style="margin:6px 0 0"><input type="checkbox" name="exklusiv" value="1"> exklusiv (nur für den Kunden)</label>
  <div class="bx-row" style="margin-top:var(--sp-4)"><button class="btn btn-primary" type="submit">Produkt anlegen</button><a class="btn btn-ghost" href="?p=produkte">Abbrechen</a></div>
</form>
<?php
    render_footer();
    return;
}

$p = one("SELECT * FROM produkt WHERE id=?", [(int)$id]);
if (!$p) { render_header('produkte', 'Produkt'); echo '<div class="bx-panel">Produkt nicht gefunden. <a href="?p=produkte">Zur Liste</a></div>'; render_footer(); return; }
$pid = (int)$p['id'];
$istExtern = (int)($p['extern'] ?? 0) === 1;
$istHandel = (int)($p['handelsware'] ?? 0) === 1;
$rid = (int)($p['rezeptur_id'] ?? 0);
$rez = $rid ? one("SELECT id, nummer, name, darreichungsform, kapselgroesse_id FROM rezeptur WHERE id=?", [$rid]) : null;
$pForm = (string)($rez['darreichungsform'] ?? 'kapsel');
$schuett = form_groessen_einheit($pForm) !== '';
$einh = (int)($p['einheiten_pro_packung'] ?? 0);
$darf = has_role('admin') || has_role('einkauf') || has_role('sales') || has_role('production');
$eur = function ($x) { return preis_zahl($x) . ' €'; };   // zentrale Preis-Anzeige (13.11.2026)
$eur2 = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
$nf0 = fn($x) => number_format((float)$x, 0, ',', '.');
$fehlt = fn(string $t = 'fehlt') => '<span style="color:var(--warn)">' . h($t) . '</span>';
$itemName = fn($iid) => $iid ? (string) scalar("SELECT name FROM item WHERE id=?", [(int)$iid]) : '';

// Kalkulationsmenge (Packungen) für Staffeln: ?km= (Reiter Kalkulation), Standard 1.000.
$km = max(1, (int) str_replace('.', '', (string)($_GET['km'] ?? '1000')));
$kalk = produkt_kalkulation($pid, $km);
$stdWeg = $kalk['wege'][$kalk['standard']] ?? null;

// ---- Kopf
$kopfAkt = '';
$anfDarf = $rid > 0 && (has_role('admin') || has_role('einkauf') || has_role('sales'));
if ($anfDarf) $kopfAkt .= anfrage_produkt_badge($rid) . ' ' . '<button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById(\'dlgAnfWahl\').showModal()">Beim Lieferanten anfragen</button>' . ' ';
$kopfAkt .= bx_btn('Zurück zur Liste', '?p=produkte', 'ghost');
if (has_role('admin'))
    $kopfAkt .= ' <form method="post" style="display:inline" onsubmit="return confirm(\'Dieses Produkt endgültig löschen? Geht nur, wenn es nicht mehr verwendet wird (Auftrag/Angebot/Produktion/Bestand).\');"><input type="hidden" name="aktion" value="loeschen"><button class="btn btn-ghost btn-sm" type="submit" style="color:#8f231b">Löschen</button></form>';
$stLbl = ['entwurf' => ['Entwurf', 'warn'], 'aktiv' => ['aktiv', 'ok'], 'inaktiv' => ['inaktiv', '']][(string)$p['status']] ?? [(string)$p['status'], ''];
$stiftIco = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';
$stift = fn(string $dlg) => $darf ? '<button type="button" class="bx-stift" title="Bearbeiten" onclick="document.getElementById(\'' . $dlg . '\').showModal()">' . $stiftIco . '</button>' : '';
$untertitel = h((string)$p['nummer']) . ' · ' . bx_badge($stLbl[0], $stLbl[1])
            . ($rez ? ' · Rezeptur <a href="?p=rezeptur_detail&id=' . $rid . '">' . h((string)($rez['nummer'] ?: $rez['name'])) . '</a>' : '')
            . ($istExtern ? ' · ' . bx_badge('Nur Fulfillment', 'info') : ($istHandel ? ' · ' . bx_badge('Handelsware', 'info') : ''));
render_header('produkte', (string)$p['name']);
// Kopf wie bx_head(), aber mit Stift am Namen und Badges/Links in der Unterzeile (bx_head escaped beides).
echo '<div class="bx-head"><div><h1 style="display:flex;align-items:center;gap:10px">' . h((string)$p['name']) . ' ' . $stift('dlgName') . '</h1><p class="bx-sub">' . $untertitel . '</p></div><div class="bx-row">' . $kopfAkt . '</div></div>';
foreach ([['gespeichert', 'Gespeichert.'], ['kpok', 'Kundenpreis gespeichert.'], ['knok', 'Kundenname gespeichert.'], ['zkok', 'Zukaufpreis gespeichert.'],
          ['pib', 'PIB hochgeladen.'], ['pibweg', 'Hochgeladenes PIB entfernt – es gilt wieder das automatische.'], ['kigelesen', 'Dokument von der KI ausgelesen – Vorschlag unten.']] as [$g, $t])
    if (isset($_GET[$g])) { echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h($t) . '</div>'; break; }
if (isset($_GET['matrix'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . (int)$_GET['matrix'] . ' Preiszeilen berechnet.</div>';
if (isset($_GET['specueb'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . (int)$_GET['specueb'] . ' Feld(er) ins Produkt übernommen.</div>';
if (isset($_GET['angefragt'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Anfrage an ' . (int)$_GET['angefragt'] . ' Lieferant(en) gestellt' . ((int)($_GET['gemailt'] ?? 0) > 0 ? ', davon ' . (int)$_GET['gemailt'] . ' per Mail' : '') . ' – Antworten im Reiter Beschaffung.</div>';
if (isset($_GET['anffehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Anfrage nicht gestellt – bitte mindestens einen Lieferanten wählen.</div>';
if (isset($_GET['kfehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['kfehler']) . '</div>';
$prodDelFehler = (string)($_SESSION['prod_del_fehler'] ?? '');
if ($prodDelFehler !== '') {
    unset($_SESSION['prod_del_fehler']);
    echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h($prodDelFehler) . '</div>';
    if ($prodVerw = produkt_verwendung($pid)) {
        echo '<div class="bx-panel" style="border-left:3px solid var(--warn)"><h2 style="margin-top:0;font-size:16px">Wo wird dieses Produkt verwendet? <span class="muted" style="font-weight:400;font-size:13px">(' . count($prodVerw) . ')</span></h2><div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Typ</th><th>Eintrag</th></tr></thead><tbody>';
        foreach ($prodVerw as $vv) echo '<tr><td>' . h((string)$vv['typ']) . (!empty($vv['blocker']) ? ' ' . bx_badge('Blocker', 'warn') : '') . '</td><td>' . ($vv['url'] ? '<a href="' . h((string)$vv['url']) . '">' . h((string)$vv['label']) . '</a>' : h((string)$vv['label'])) . '</td></tr>';
        echo '</tbody></table></div></div>';
    }
}

// ---- Kacheln
$kap = $rez && in_array($pForm, ['kapsel', 'softgel'], true) ? (((int)($rez['kapselgroesse_id'] ?? 0)) ? one("SELECT id, name, fuellmenge_mg FROM kapselgroesse WHERE id=?", [(int)$rez['kapselgroesse_id']]) : rezeptur_kapselgroesse($rid)) : null;
$mengeLbl = $schuett ? 'Füllmenge je Packung' : ($pForm === 'tablette' ? 'Tabletten je Packung' : 'Menge je Packung');
$mengeTxt = $einh > 0 ? ($schuett ? $nf0($einh) . ' ' . form_groessen_einheit($pForm) : $nf0($einh) . ' ' . ($pForm === 'kapsel' || $pForm === 'softgel' ? 'Kapseln' : 'Stk')) : $fehlt('nicht gesetzt');
// Passt der Inhalt in das Glas? (Kapsel: Fassung je Kapselgröße; Schüttgut: max. Füllgewicht / Volumen)
$passt = '';
if (!empty($p['verpackung_id']) && $einh > 0) {
    $vpi = one("SELECT max_fuellgewicht_g, volumen_ml FROM item WHERE id=?", [(int)$p['verpackung_id']]) ?: [];
    if ($kap) { $cap = pack_kapazitaet_fuer((int)$p['verpackung_id'])[(int)$kap['id']] ?? 0;
        $passt = $cap <= 0 ? '<span class="muted">Fassung für ' . h((string)$kap['name']) . ' nicht hinterlegt</span>' : ($cap >= $einh ? '<span style="color:var(--gruen)">passt (max. ' . $nf0($cap) . ')</span>' : '<span style="color:var(--err)">zu klein (max. ' . $nf0($cap) . ')</span>'); }
    elseif (in_array($pForm, ['pulver', 'granulat'], true) && $vpi) { $mx = (float)($vpi['max_fuellgewicht_g'] ?? 0);
        $passt = $mx <= 0 ? '<span class="muted">max. Füllgewicht nicht hinterlegt</span>' : ($mx >= $einh ? '<span style="color:var(--gruen)">passt (max. ' . $nf0($mx) . ' g)</span>' : '<span style="color:var(--err)">zu klein (max. ' . $nf0($mx) . ' g)</span>'); }
    elseif (in_array($pForm, ['fluessig', 'gel'], true) && $vpi) { $mx = (float)($vpi['volumen_ml'] ?? 0);
        $passt = $mx <= 0 ? '<span class="muted">Volumen nicht hinterlegt</span>' : ($mx >= $einh ? '<span style="color:var(--gruen)">passt</span>' : '<span style="color:var(--err)">zu klein (' . $nf0($mx) . ' ml)</span>'); }
}
$leerEff = $pForm === 'kapsel' ? produkt_leerkapsel_id($pid) : 0;
$kosten = $stdWeg ? ($stdWeg['vollstaendig'] ? $eur2($stdWeg['summe']) : '<span style="color:var(--warn)">unvollständig</span>') : $fehlt('–');
$nfL = ['unklar' => 'ungeklärt', 'konform' => 'konform', 'novel_food' => 'enthält Novel Food', 'pruefung' => 'in Prüfung'];
$artTxt = $istExtern ? 'Nur Fulfillment' : ($istHandel ? 'Handelsware' : 'Eigenes Produkt');
$katTxt = (int)($p['exklusiv'] ?? 0) === 1 ? 'exklusiv' . (!empty($p['kunde_id']) ? ' · ' . kunde_name_voll((int)$p['kunde_id'], (string) scalar("SELECT firma FROM kunden WHERE id=?", [(int)$p['kunde_id']])) : '') : 'Katalog';
$msTxt = ($p['mwst_satz'] ?? null) === null || $p['mwst_satz'] === '' ? 'Standard (' . $nf0(meta_get('ust_inland', 19)) . ' %)' : $nf0($p['mwst_satz']) . ' %';
$kachel = function (string $label, string $wert, string $dlg = '', string $unter = '', string $extra = '') use ($stift) {
    return '<div class="bx-card"' . $extra . '><div class="k" style="display:flex;justify-content:space-between;align-items:center;gap:8px"><span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . h($label) . '</span>' . ($dlg !== '' ? $stift($dlg) : '') . '</div>'
         . '<div class="v" style="font-size:15px">' . $wert . '</div>' . ($unter !== '' ? '<div class="muted" style="font-size:12px;margin-top:4px">' . $unter . '</div>' : '') . '</div>';
};
echo '<div class="bx-cards pr-kacheln">';
if (!$istExtern) {
    echo $kachel('Rezeptur', $rez ? '<a href="?p=rezeptur_detail&id=' . $rid . '">' . h((string)$rez['name']) . '</a>' : $fehlt('keine'), 'dlgRez');
    echo $kachel('Darreichung', h($DFORM[$pForm] ?? $pForm) . ($kap ? ' · ' . h((string)$kap['name']) : ''), $kap || in_array($pForm, ['kapsel', 'softgel'], true) ? 'dlgKap' : '', $kap && empty($rez['kapselgroesse_id']) ? 'automatisch nach Füllgewicht' : '');
    echo $kachel($mengeLbl, $mengeTxt, 'dlgMenge');
}
echo $kachel('Glas/Behälter', !empty($p['verpackung_id']) ? '<a href="?p=verpackung&id=' . (int)$p['verpackung_id'] . '">' . h($itemName($p['verpackung_id'])) . '</a>' : $fehlt('keiner'), 'dlgVerp', $passt);
echo $kachel('Deckel', !empty($p['verschluss_id']) ? '<a href="?p=verpackung&id=' . (int)$p['verschluss_id'] . '">' . h($itemName($p['verschluss_id'])) . '</a>' : (!empty($p['verpackung_id']) && !verpackung_ist_bedruckt((int)$p['verpackung_id']) && stripos($itemName($p['verpackung_id']), 'beutel') === false ? $fehlt('keiner') : '<span class="muted">keiner</span>'), 'dlgDeckel');
if (!$istExtern && $pForm === 'kapsel')
    echo $kachel('Leerkapsel', $leerEff ? h($itemName($leerEff)) : $fehlt('nicht eindeutig'), 'dlgLeer', (int)($p['leerkapsel_id'] ?? 0) ? 'manuell gewählt' : ($leerEff ? 'automatisch nach Größe' : ''));
if (!$istExtern && !$schuett) echo $kachel('Verzehr je Tag', h(rtrim(rtrim(number_format((float)$p['einnahme_pro_tag'], 1, ',', '.'), '0'), ',')) . ' ' . ($pForm === 'kapsel' || $pForm === 'softgel' ? ((float)$p['einnahme_pro_tag'] == 1.0 ? 'Kapsel' : 'Kapseln') : 'Stk'), 'dlgVerzehr', $einh > 0 && (float)$p['einnahme_pro_tag'] > 0 ? 'reicht ' . $nf0($einh / (float)$p['einnahme_pro_tag']) . ' Tage' : '');
echo $kachel('Haltbarkeit', trim((string)($p['haltbarkeit'] ?? '')) !== '' ? h((string)$p['haltbarkeit']) : '<span class="muted">nicht gesetzt</span>', 'dlgHalt', trim((string)($p['allergene'] ?? '')) !== '' ? 'Allergene: ' . h((string)$p['allergene']) : '');
echo $kachel('Status', bx_badge($stLbl[0], $stLbl[1]), 'dlgStatus');
if (!$istExtern) echo $kachel('Novel Food', h($nfL[(string)($p['novelfood_status'] ?? 'unklar')] ?? 'ungeklärt'), 'dlgNf');
echo $kachel('MwSt', h($msTxt), has_role('admin') ? 'dlgMwst' : '');
echo $kachel('Katalog / exklusiv', $katTxt, 'dlgKat');
echo $kachel('Art des Produkts', h($artTxt), 'dlgArt');
if (!$istExtern) echo $kachel('Kosten je Packung', '<a href="#" data-goto="kalkulation">' . $kosten . '</a>', '', h($stdWeg['label'] ?? '') . ' · bei ' . $nf0($km) . ' Pkg');
echo '</div>';

// ---- Stift-Fenster (je Kachel genau ein Feld)
$dlgKopf = fn(string $t, string $dlg) => '<div class="bx-row" style="justify-content:space-between;align-items:center;gap:10px;margin-bottom:12px"><h2 style="margin:0;font-size:17px">' . h($t) . '</h2><button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById(\'' . $dlg . '\').close()" aria-label="schließen">&#10005;</button></div>';
$dlg = function (string $idd, string $titel, string $feld, string $inhalt, string $hinweis = '') use ($dlgKopf) {
    return '<dialog id="' . $idd . '" class="bx-dialog">' . $dlgKopf($titel, $idd) . '<form method="post" style="margin:0"><input type="hidden" name="aktion" value="kachel"><input type="hidden" name="feld" value="' . h($feld) . '">'
         . $inhalt . ($hinweis !== '' ? '<div class="muted" style="font-size:12px;margin-top:8px">' . $hinweis . '</div>' : '')
         . '<div style="margin-top:14px"><button class="btn btn-primary btn-sm" type="submit">Speichern</button></div></form></dialog>';
};
$fld = fn(string $label, string $input) => '<div class="bx-field" style="margin:0 0 10px"><label>' . $label . '</label>' . $input . '</div>';
if ($darf) {
    echo $dlg('dlgName', 'Name', 'name',
        $fld('Produktname (intern)', '<input type="text" name="name" required value="' . h((string)$p['name']) . '">')
      . $fld('Synonyme / frühere Namen ' . bx_hint('Intern mitsuchbar. Beim Umbenennen wird der alte Name automatisch ergänzt.'), '<textarea name="synonyme" rows="2">' . h((string)($p['synonyme'] ?? '')) . '</textarea>')
      . $fld('Standard-Name für Kunden (Fallback) ' . bx_hint('Gilt beim Kunden, wenn im Reiter Kunden kein eigener Name steht. Leer = interner Name.'), '<input type="text" name="kundenname" value="' . h((string)($p['kundenname'] ?? '')) . '">'),
        'Gleiche Namen werden automatisch mit v2, v3 … nummeriert.');
    if (!$istExtern) {
        $rezepte = all("SELECT id, name, darreichungsform FROM rezeptur WHERE COALESCE(status,'')<>'inaktiv' OR id=? ORDER BY name", [(int)$rid]);   // Block C: inaktive nicht auswählbar
        echo $dlg('dlgRez', 'Rezeptur', 'rezeptur', '<select name="rezeptur_id"' . $rscombo($rezepte) . ' style="width:100%">' . $optListe($rezepte, $rid, '– keine –', fn($r) => $r['name'] . ' · ' . ($DFORM[$r['darreichungsform']] ?? $r['darreichungsform'])) . '</select>',
                  'Die Zusammensetzung steht im Reiter Rezeptur. Ändern der Zutaten an der Rezeptur selbst.');
        $kgr = all("SELECT id, name, fuellmenge_mg FROM kapselgroesse ORDER BY fuellmenge_mg");
        echo $dlg('dlgKap', 'Kapselgröße', 'kapsel', '<select name="kapselgroesse_id" style="width:100%">' . $optListe($kgr, (int)($rez['kapselgroesse_id'] ?? 0), '– automatisch nach Füllgewicht –', fn($r) => $r['name'] . ' (' . number_format((float)$r['fuellmenge_mg'], 0, ',', '.') . ' mg)') . '</select>',
                  'Gilt an der Rezeptur – also für alle Produkte mit dieser Rezeptur. Füllgewicht laut Rezeptur: ' . number_format(rezeptur_gewicht_mg($rid), 0, ',', '.') . ' mg.');
        echo $dlg('dlgMenge', $mengeLbl, 'menge', $fld($mengeLbl . ($schuett ? ' (' . form_groessen_einheit($pForm) . ')' : ''), '<input type="text" inputmode="numeric" name="einheiten_pro_packung" value="' . ($einh ? h($nf0($einh)) : '') . '">'),
                  'Eine andere Packungsgröße für einen Kunden ist laut Handbuch ein eigenes Produkt.');
        echo $dlg('dlgVerzehr', 'Verzehr je Tag', 'verzehr', $fld('Einheiten je Tag', '<input type="text" inputmode="decimal" name="einnahme_pro_tag" value="' . h(rtrim(rtrim(number_format((float)$p['einnahme_pro_tag'], 2, ',', ''), '0'), ',')) . '">'), 'Basis für % NRV pro Tag und Reichweite.');
        if ($pForm === 'kapsel') {
            $leer = all("SELECT id, name FROM item WHERE kategorie='rohstoff' AND form='kapselhuelle' AND gesperrt=0 ORDER BY name");
            echo $dlg('dlgLeer', 'Leerkapsel', 'leerkapsel', '<select name="leerkapsel_id"' . $rscombo($leer) . ' style="width:100%">' . $optListe($leer, (int)($p['leerkapsel_id'] ?? 0), '– automatisch nach Größe –') . '</select>',
                      'Nur wählen, wenn es mehrere Kapseln gleicher Größe gibt (Material/Farbe).');
        }
        echo $dlg('dlgNf', 'Novel Food', 'novelfood', '<select name="novelfood_status" style="width:100%">' . implode('', array_map(fn($k, $l) => '<option value="' . $k . '"' . (($p['novelfood_status'] ?? 'unklar') === $k ? ' selected' : '') . '>' . h($l) . '</option>', array_keys($nfL), $nfL)) . '</select>')
           . '<script>document.getElementById("dlgNf").insertAdjacentHTML("beforeend",' . json_encode('<form method="post" style="margin:12px 0 0;padding-top:10px;border-top:1px solid var(--line)"><input type="hidden" name="aktion" value="novelfood_pruefen"><button class="btn btn-ghost btn-sm" type="submit">Automatisch prüfen (EU-Katalog)</button></form>') . ');</script>';
    }
    $glaeser = $verpListe('primaer'); $deckel = $verpListe('verschluss');
    echo $dlg('dlgVerp', 'Glas/Behälter', 'verpackung', '<select name="verpackung_id"' . $rscombo($glaeser) . ' style="width:100%">' . $optListe($glaeser, (int)($p['verpackung_id'] ?? 0)) . '</select>',
              'Nur Primärverpackungen (Gläser, Dosen, Beutel, Flaschen). Der Name „· 150 ml Glas" im Produktnamen zieht automatisch mit.');
    echo $dlg('dlgDeckel', 'Deckel', 'deckel', '<select name="verschluss_id"' . $rscombo($deckel) . ' style="width:100%">' . $optListe($deckel, (int)($p['verschluss_id'] ?? 0)) . '</select>', 'Nur Verschlüsse.');
    echo $dlg('dlgHalt', 'Haltbarkeit & Allergene', 'haltbarkeit', $fld('Haltbarkeit', '<input type="text" name="haltbarkeit" value="' . h((string)($p['haltbarkeit'] ?? '')) . '" placeholder="z. B. 24 Monate">') . $fld('Allergene', '<input type="text" name="allergene" value="' . h((string)($p['allergene'] ?? '')) . '" placeholder="z. B. keine">'),
              'Kann aus einem hochgeladenen Spec übernommen werden (Reiter Dokumente).');
    echo $dlg('dlgStatus', 'Status', 'status', '<select name="status" style="width:100%">' . implode('', array_map(fn($k, $l) => '<option value="' . $k . '"' . ($p['status'] === $k ? ' selected' : '') . '>' . $l . '</option>', ['entwurf', 'aktiv', 'inaktiv'], ['Entwurf', 'aktiv', 'inaktiv'])) . '</select>');
    if (has_role('admin')) { $curMs = ($p['mwst_satz'] ?? null) === null || $p['mwst_satz'] === '' ? '' : (string)(float)$p['mwst_satz'];
        echo $dlg('dlgMwst', 'MwSt-Satz', 'mwst', '<select name="mwst_satz" style="width:100%"><option value=""' . ($curMs === '' ? ' selected' : '') . '>Standard (19 %)</option>' . implode('', array_map(fn($v) => '<option value="' . $v . '"' . ($curMs === (string)(float)$v ? ' selected' : '') . '>' . $v . ' %</option>', ['19', '7', '0'])) . '</select>',
                  'Nur ändern, wenn ausnahmsweise ein anderer Satz gilt. Glas & Etikett bleiben bei 19 %. Nur Admin.'); }
    echo $dlg('dlgKat', 'Katalog / exklusiv', 'katalog',
        '<label class="bx-check" style="margin:0 0 10px"><input type="checkbox" name="exklusiv" value="1"' . ((int)($p['exklusiv'] ?? 0) === 1 ? ' checked' : '') . '> exklusiv (nur für den Kunden)</label>'
      . $fld('Kunde (Besitzer, nur bei exklusiv)', '<select name="kunde_id"' . $rscombo($kunden) . ' style="width:100%">' . $optListe($kunden, (int)($p['kunde_id'] ?? 0), '– Katalogprodukt –', fn($r) => kunde_name_voll((int)$r['id'], (string)$r['firma'], false)) . '</select>'),
        'Ohne Häkchen ist das Produkt ein Katalogprodukt und gehört niemandem – der Kunde wird dann entfernt.');
    $artCur = $istExtern ? 'fulfillment' : ($istHandel ? 'handelsware' : 'eigen');
    echo $dlg('dlgArt', 'Art des Produkts', 'art',
        implode('', array_map(fn($k, $l, $t) => '<label class="bx-check" style="margin:0 0 8px;align-items:flex-start"><input type="radio" name="art" value="' . $k . '"' . ($artCur === $k ? ' checked' : '') . '> <span>' . $l . '<br><span class="muted" style="font-size:12px">' . $t . '</span></span></label>',
            ['eigen', 'handelsware', 'fulfillment'], ['Eigenes Produkt', 'Handelsware', 'Nur Fulfillment'],
            ['Rezeptur, Produktion, Preise.', 'Fertig zugekauft und weiterverkauft – kein Produktionsauftrag, Versand aus dem Bestand.', 'Kundenware: wir lagern und versenden nur. Gehört dem Kunden, keine Rezeptur.']))
      . $fld('Kunde (nur bei Nur Fulfillment nötig)', '<select name="kunde_id"' . $rscombo($kunden) . ' style="width:100%">' . $optListe($kunden, (int)($p['kunde_id'] ?? 0), '– wie bisher –', fn($r) => kunde_name_voll((int)$r['id'], (string)$r['firma'], false)) . '</select>'));
}
if (isset($_GET['nfcheck']) && !empty($_SESSION['nf_ergebnis'])) { $nf = $_SESSION['nf_ergebnis']; unset($_SESSION['nf_ergebnis']);
    echo '<div class="bx-panel" style="font-size:13px;line-height:1.6">Novel-Food-Prüfung: ' . ($nf['grund'] ? h($nf['grund']) : (!$nf['treffer'] ? 'Keine Zutat im Novel-Food-Katalog gefunden – als konform eingestuft.' : 'Treffer: ' . h(implode(', ', array_map(fn($t) => $t['zutat'] . ' → ' . $t['stoff'] . ' (' . $t['status'] . ')', $nf['treffer']))))) . '</div>'; }

// ---- Reiter
$TABS = $istExtern ? ['kunden' => 'Kunden', 'bestellungen' => 'Bestellungen', 'produktion' => 'Produktion', 'dokumente' => 'Dokumente']
                   : ['rezeptur' => 'Rezeptur', 'kunden' => 'Kunden', 'bestellungen' => 'Bestellungen', 'beschaffung' => 'Beschaffung', 'produktion' => 'Produktion', 'kalkulation' => 'Kalkulation', 'dokumente' => 'Dokumente'];
$tab = isset($TABS[(string)($_GET['tab'] ?? '')]) ? (string)$_GET['tab'] : array_key_first($TABS);
echo '<div class="settabs" id="prodTabs" style="margin:16px 0">';
foreach ($TABS as $k => $l) echo '<a href="?p=produkt&id=' . $pid . '&tab=' . $k . '" data-tab="' . $k . '"' . ($k === $tab ? ' class="on"' : '') . '>' . h($l) . '</a>';
echo '</div>';
$panel = fn(string $k) => ' data-ppanel="' . $k . '"' . ($k !== $tab ? ' style="display:none"' : '');

// ===================================================================== Reiter Rezeptur
if (!$istExtern):
    $ra = produkt_rezeptur_ansicht($pid);
    $ohneN = array_filter($ra['zeilen'], fn($z) => !$z['naehrstoffe']);
    $ohneMg = array_filter($ra['zeilen'], fn($z) => $z['mg'] <= 0); ?>
<div<?= $panel('rezeptur') ?>>
<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <h2 style="margin:0">Zusammensetzung <?= $rez ? '<span class="muted" style="font-weight:400;font-size:14px">' . h((string)$rez['name']) . '</span>' : '' ?></h2>
    <?php if ($rid): ?><a class="btn btn-ghost btn-sm" href="?p=rezeptur_detail&id=<?= $rid ?>">Rezeptur bearbeiten</a><?php endif; ?>
  </div>
  <?php if (!$rid): ?><p class="muted">Keine Rezeptur gewählt – oben in der Kachel „Rezeptur" per Stift wählen.</p>
  <?php elseif (!$ra['zeilen']): ?><div class="badge-warn" style="padding:8px 12px;margin-top:10px">Die Rezeptur ist leer – keine Zutaten hinterlegt.</div>
  <?php else: ?>
    <?php if ($ohneMg): ?><div class="badge-warn" style="padding:8px 12px;margin-top:10px"><?= count($ohneMg) ?> Zutat(en) ohne Menge.</div><?php endif; ?>
    <?php if ($ohneN): ?><div class="muted" style="font-size:13px;margin-top:10px"><?= count($ohneN) ?> von <?= count($ra['zeilen']) ?> Rohstoffen ohne Nährstoff-Zuordnung (<?= h(implode(', ', array_map(fn($z) => $z['name'], $ohneN))) ?>) – sie zählen nicht zur Deklaration. Bei Füllstoffen richtig, bei Wirkstoffen am Rohstoff den Gehalt nachtragen.</div><?php endif; ?>
    <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table">
      <thead><tr><th>Rohstoff</th><th class="bx-num">mg je <?= $schuett ? 'Tagesdosis' : 'Einheit' ?></th><?php if (!$schuett): ?><th class="bx-num">mg je Tagesdosis</th><?php endif; ?><th>liefert</th></tr></thead>
      <tbody>
      <?php $sum = 0.0; foreach ($ra['zeilen'] as $z): $sum += $z['mg']; ?>
        <tr><td><a href="?p=rohstoff&id=<?= (int)$z['item_id'] ?>"><?= h($z['name']) ?></a></td>
          <td class="bx-num"><?= number_format($z['mg'], $z['mg'] < 10 ? 2 : 1, ',', '.') ?> mg</td>
          <?php if (!$schuett): ?><td class="bx-num"><?= number_format($z['mg'] * $ra['faktor'], 1, ',', '.') ?> mg</td><?php endif; ?>
          <td style="white-space:normal"><?= $z['naehrstoffe'] ? h(implode(' · ', array_map(fn($n) => $n['name'] . ($n['mg'] !== null ? ' ' . number_format($n['mg'], $n['mg'] < 1 ? 3 : 1, ',', '.') . ' mg' : ' (Gehalt fehlt)'), $z['naehrstoffe']))) : '<span class="muted">kein Nährstoff zugeordnet</span>' ?></td></tr>
      <?php endforeach; ?>
        <tr><td class="muted">Summe Füllung</td><td class="bx-num"><?= number_format($sum, 1, ',', '.') ?> mg</td><?php if (!$schuett): ?><td class="bx-num"><?= number_format($sum * $ra['faktor'], 1, ',', '.') ?> mg</td><?php endif; ?><td></td></tr>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>
<div class="bx-panel">
  <h2 style="margin-top:0">Tages-Deklaration <span class="muted" style="font-weight:400;font-size:14px"><?= $schuett ? '(1 Tagesdosis = Rezeptur)' : '(bei ' . h(rtrim(rtrim(number_format((float)$ra['faktor'], 1, ',', ''), '0'), ',')) . ' Einheiten je Tag)' ?></span></h2>
  <?php if (!$ra['deklaration']): ?><div class="muted">Keine deklarierbaren Wirkstoffe – den Rohstoffen sind keine Nährstoffe zugeordnet.</div>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Nährstoff</th><th class="bx-num">je Einheit</th><th class="bx-num">je Tag</th><th class="bx-num">% NRV</th></tr></thead><tbody>
  <?php foreach ($ra['deklaration'] as $d): $lbl = $d['anzeige'] ?: $d['einheit']; $f = fn($mg) => $d['einheit'] === 'µg' ? number_format($mg * 1000, 1, ',', '.') . ' ' . $lbl : number_format($mg, 1, ',', '.') . ' ' . $lbl; ?>
    <tr><td><?= h($d['name']) ?></td><td class="bx-num"><?= $f($d['mg_einheit']) ?></td><td class="bx-num"><?= $f($d['mg_tag']) ?><?= $d['ie_mg'] ? ' (' . number_format($d['mg_tag'] / (float)$d['ie_mg'], 0, ',', '.') . ' I.E.)' : '' ?></td>
      <td class="bx-num"><?= $d['nrv_proz'] !== null ? number_format($d['nrv_proz'], 0, ',', '.') . ' %' : '<span class="muted">keine NRV</span>' ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
</div>
<?php endif;

// ===================================================================== Reiter Kunden
$kundenRows = produkt_kunden($pid);
$kpG = form_groessen_einheit($pForm);
?>
<div<?= $panel('kunden') ?> id="kundennamen">
<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <h2 style="margin:0">Kunden <span class="muted" style="font-weight:400;font-size:14px">(<?= count($kundenRows) ?>)</span></h2>
    <div class="bx-row" style="gap:10px;align-items:center"><span class="muted" style="font-size:13px">Standard-Name: <?= trim((string)($p['kundenname'] ?? '')) !== '' ? h((string)$p['kundenname']) : 'wie intern' ?> <?= $stift('dlgName') ?></span>
      <?php if ($darf): ?><button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('dlgKundeNeu').showModal()">Kunde hinzufügen</button><?php endif; ?></div>
  </div>
  <?php if (!$kundenRows): ?><p class="muted">Noch kein Kunde: kein Name, kein Kundenpreis, kein Angebot.</p><?php else: ?>
  <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table pr-kunden">
    <thead><tr><th>Kunde</th><th>Name beim Kunden</th><th>Etikett</th><th>Kundenpreis je Pkg</th><th>Letztes Angebot</th><th>Letzte Rechnung</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($kundenRows as $kr): $kid = (int)$kr['kunde_id']; $et = $kr['etikett']; ?>
      <tr>
        <td><a class="kundenlink" href="?p=kunde&id=<?= $kid ?>"><?= kunde_name_voll($kid, (string)$kr['firma']) ?></a><?= $kr['auftraege'] ? '<div class="muted" style="font-size:12px">' . $kr['auftraege'] . ' Auftrag/Aufträge</div>' : '' ?></td>
        <td><?= $kr['name'] !== '' ? h($kr['name']) : '<span class="muted">wie Standard</span>' ?></td>
        <td style="white-space:normal;min-width:180px"><?php if ($et): ?>v<?= $et['version'] ?> · <?= $et['dok'] ? '<a href="?p=dokument&id=' . $et['dok'] . '" target="_blank" rel="noopener">Design freigegeben</a>' : '<span style="color:var(--warn)">Design nicht freigegeben</span>' ?>
            <div class="muted" style="font-size:12px">im Lager <?= $nf0($et['lager']) ?> Stk · unterwegs <?= $nf0($et['unterwegs']) ?> Stk · Bedarf offene Aufträge <?= $nf0($et['bedarf']) ?> Stk</div>
          <?php else: ?><span class="muted">kein Kundenetikett</span><?php endif; ?></td>
        <td style="white-space:normal;min-width:170px"><?php if (!$kr['preise']): ?><span class="muted">kein Kundenpreis</span><?php endif; foreach ($kr['preise'] as $kp):
              $abw = $kp['menge_pro_vpe'] !== null && $einh > 0 && (int)$kp['menge_pro_vpe'] !== $einh; ?>
            <div><?= $eur2((float)$kp['preis']) ?> <span class="muted" style="font-size:12px"><?= $kp['anzahl_vpe'] ? 'ab ' . $nf0($kp['anzahl_vpe']) : '' ?><?= $kp['menge_pro_vpe'] !== null ? ' · ' . h(form_groessen_label($pForm, (float)$kp['menge_pro_vpe'])) : '' ?></span><?= $abw ? ' ' . bx_badge('andere Packungsgröße', 'warn') : '' ?></div>
          <?php endforeach; ?></td>
        <td><?= $kr['angebot'] ? '<a href="?p=angebot&id=' . (int)$kr['angebot']['id'] . '">' . h((string)$kr['angebot']['nummer']) . '</a><div class="muted" style="font-size:12px">' . h(fmt_zeit((string)$kr['angebot']['angelegt'], 'd.m.Y')) . ' · ' . h(['offen' => 'Entwurf', 'gesendet' => 'gesendet', 'bestaetigt' => 'bestätigt', 'abgelehnt' => 'abgelehnt'][$kr['angebot']['status']] ?? $kr['angebot']['status']) . '</div>' : '<span class="muted">–</span>' ?></td>
        <td><?= $kr['rechnung'] ? h((string)$kr['rechnung']['nummer']) . '<div class="muted" style="font-size:12px">' . ($kr['rechnung']['datum'] ? h(date('d.m.Y', strtotime((string)$kr['rechnung']['datum']))) . ' · ' : '') . $eur2($kr['rechnung']['je_pkg']) . '/Pkg</div>' : '<span class="muted">–</span>' ?></td>
        <td style="text-align:right"><?= $stift('dlgKunde' . $kid) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12px;margin-top:8px">Kundenpreis = VK je Packung <strong>ohne</strong> Verpackung (Glas/Deckel/Etikett kommen im Angebot dazu). Der Kunde sieht im Portal seinen niedrigsten Preis als „ab". Eine andere Packungsgröße ist laut Handbuch ein eigenes Produkt.</p>
  <?php endif; ?>
</div>
<?php
// Stift je Kunde: Name + Kundenpreise dieses Kunden.
if ($darf) foreach ($kundenRows as $kr): $kid = (int)$kr['kunde_id']; ?>
<dialog id="dlgKunde<?= $kid ?>" class="bx-dialog">
  <?= $dlgKopf(kunde_name_voll($kid, (string)$kr['firma'], false), 'dlgKunde' . $kid) ?>
  <form method="post" class="bx-row" style="gap:8px;align-items:flex-end;margin:0 0 14px;flex-wrap:wrap"><input type="hidden" name="aktion" value="kn_setzen"><input type="hidden" name="kn_kunde" value="<?= $kid ?>">
    <div class="bx-field" style="margin:0;flex:1;min-width:220px"><label>Name beim Kunden <?= bx_hint('Der Kunde sieht überall diesen Namen. Leer = Standard-Name.') ?></label><input type="text" name="kn_name" value="<?= h($kr['name']) ?>"></div>
    <button class="btn btn-primary btn-sm" type="submit">Name speichern</button></form>
  <div style="font-size:13px;margin-bottom:6px" class="muted">Kundenpreise</div>
  <?php foreach ($kr['preise'] as $kp): ?>
    <form method="post" class="bx-row" style="gap:8px;align-items:center;margin:0 0 6px" onsubmit="return confirm('Kundenpreis löschen?');"><input type="hidden" name="aktion" value="kp_del"><input type="hidden" name="kp_id" value="<?= (int)$kp['id'] ?>">
      <span><?= $eur2((float)$kp['preis']) ?> <span class="muted"><?= $kp['anzahl_vpe'] ? 'ab ' . $nf0($kp['anzahl_vpe']) . ' Pkg' : '' ?><?= $kp['menge_pro_vpe'] !== null ? ' · ' . h(form_groessen_label($pForm, (float)$kp['menge_pro_vpe'])) : '' ?><?= $kp['notiz'] ? ' · ' . h((string)$kp['notiz']) : '' ?></span></span>
      <button class="btn btn-ghost btn-sm" type="submit">×</button></form>
  <?php endforeach; ?>
  <form method="post" class="bx-row" style="gap:8px;align-items:flex-end;margin-top:10px;flex-wrap:wrap"><input type="hidden" name="aktion" value="kp_add"><input type="hidden" name="kp_kunde" value="<?= $kid ?>">
    <div class="bx-field" style="margin:0;width:110px"><label><?= $kpG !== '' ? $kpG . ' je Pkg' : 'Stk je Pkg' ?></label><input type="text" inputmode="numeric" name="kp_menge" value="<?= $einh ?: '' ?>"></div>
    <div class="bx-field" style="margin:0;width:110px"><label>ab Pkg</label><input type="text" inputmode="numeric" name="kp_anzahl" placeholder="z. B. 1.000"></div>
    <div class="bx-field" style="margin:0;width:110px"><label>Preis je Pkg (€)</label><input type="text" inputmode="decimal" name="kp_preis" required placeholder="z. B. 4,05"></div>
    <div class="bx-field" style="margin:0;width:150px"><label>Notiz</label><input type="text" name="kp_notiz"></div>
    <button class="btn btn-primary btn-sm" type="submit">Preis speichern</button></form>
</dialog>
<?php endforeach;
if ($darf): ?>
<dialog id="dlgKundeNeu" class="bx-dialog">
  <?= $dlgKopf('Kunde hinzufügen', 'dlgKundeNeu') ?>
  <form method="post" style="margin:0"><input type="hidden" name="aktion" value="kp_add">
    <?= $fld('Kunde', '<select name="kp_kunde" required' . $rscombo($kunden) . ' style="width:100%">' . $optListe($kunden, 0, '– wählen –', fn($r) => kunde_name_voll((int)$r['id'], (string)$r['firma'], false)) . '</select>') ?>
    <?= $fld('Name beim Kunden (optional)', '<input type="text" name="kn_name">') ?>
    <div class="bx-row" style="gap:8px;flex-wrap:wrap">
      <div class="bx-field" style="margin:0;width:120px"><label><?= $kpG !== '' ? $kpG . ' je Pkg' : 'Stk je Pkg' ?></label><input type="text" inputmode="numeric" name="kp_menge" value="<?= $einh ?: '' ?>"></div>
      <div class="bx-field" style="margin:0;width:120px"><label>ab Pkg</label><input type="text" inputmode="numeric" name="kp_anzahl"></div>
      <div class="bx-field" style="margin:0;width:130px"><label>Preis je Pkg (€)</label><input type="text" inputmode="decimal" name="kp_preis" required></div>
    </div>
    <div style="margin-top:14px"><button class="btn btn-primary btn-sm" type="submit">Speichern</button></div>
  </form>
</dialog>
<?php endif;
// Alle Angebote und Rechnungen mit dem Produkt (klappbar).
$pAngebote = all("SELECT a.id, a.nummer, a.status, a.angelegt, k.firma AS kunde, a.kunde_id,
                    (SELECT ap.menge FROM angebot_position ap WHERE ap.angebot_id=a.id AND ap.rezeptur_id=? AND ?>0 ORDER BY ap.id LIMIT 1) AS pos_menge,
                    (SELECT ap.preis_cent FROM angebot_position ap WHERE ap.angebot_id=a.id AND ap.rezeptur_id=? AND ?>0 ORDER BY ap.id LIMIT 1) AS pos_preis
                  FROM angebot a LEFT JOIN kunden k ON k.id=a.kunde_id
                  WHERE a.produkt_id=? OR a.id IN (SELECT angebot_id FROM angebot_produkt WHERE produkt_id=?) ORDER BY a.id DESC", [$rid, $rid, $rid, $rid, $pid, $pid]);
$pRechnungen = all("SELECT b.id, b.nummer, b.netto, b.status, b.datum, k.firma AS kunde, b.kunde_id, a.menge FROM beleg b JOIN auftrag a ON a.id=b.auftrag_id LEFT JOIN kunden k ON k.id=b.kunde_id
                    WHERE a.produkt_id=? AND b.typ='rechnung' ORDER BY b.id DESC", [$pid]); ?>
<details class="bx-panel">
  <summary style="cursor:pointer">Alle Angebote (<?= count($pAngebote) ?>) und Rechnungen (<?= count($pRechnungen) ?>) mit diesem Produkt</summary>
  <?php if ($pAngebote): ?><div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table"><thead><tr><th>Angebot</th><th>Kunde</th><th class="bx-num">Menge</th><th class="bx-num">VK / Pkg</th><th>Status</th><th>Datum</th></tr></thead><tbody>
    <?php foreach ($pAngebote as $a2): ?><tr><td><a href="?p=angebot&id=<?= (int)$a2['id'] ?>"><?= h((string)$a2['nummer']) ?></a></td><td><?= $a2['kunde_id'] ? kunde_name_voll((int)$a2['kunde_id'], (string)$a2['kunde']) : '–' ?></td>
      <td class="bx-num"><?= $a2['pos_menge'] !== null ? $nf0($a2['pos_menge']) : '–' ?></td><td class="bx-num"><?= $a2['pos_preis'] !== null ? $eur((int)$a2['pos_preis'] / 100) : '–' ?></td>
      <td><?= h(['offen' => 'Entwurf', 'gesendet' => 'gesendet', 'bestaetigt' => 'bestätigt', 'abgelehnt' => 'abgelehnt'][$a2['status']] ?? $a2['status']) ?></td><td><?= h(fmt_zeit((string)$a2['angelegt'], 'd.m.Y')) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
  <?php if ($pRechnungen): ?><div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table"><thead><tr><th>Rechnung</th><th>Kunde</th><th class="bx-num">Menge</th><th class="bx-num">je Pkg</th><th class="bx-num">Netto</th><th>Status</th><th>Datum</th></tr></thead><tbody>
    <?php foreach ($pRechnungen as $b2): ?><tr><td><a target="_blank" href="/buchhaltung/?p=rechnung&id=<?= (int)$b2['id'] ?>"><?= h((string)$b2['nummer']) ?></a></td><td><?= $b2['kunde_id'] ? kunde_name_voll((int)$b2['kunde_id'], (string)$b2['kunde']) : '–' ?></td>
      <td class="bx-num"><?= $nf0($b2['menge']) ?></td><td class="bx-num"><?= (int)$b2['menge'] > 0 ? $eur2((float)$b2['netto'] / (int)$b2['menge']) : '–' ?></td><td class="bx-num"><?= $eur2($b2['netto']) ?></td><td><?= h((string)$b2['status']) ?></td>
      <td><?= $b2['datum'] ? h(date('d.m.Y', strtotime((string)$b2['datum']))) : '–' ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</details>
</div>
<?php
// ===================================================================== Reiter Beschaffung
if (!$istExtern):
    $bs = produkt_beschaffung($pid, $km);
    $lieferanten = all("SELECT id, firma FROM lieferanten WHERE gesperrt=0 ORDER BY firma"); ?>
<div<?= $panel('beschaffung') ?>>
<div class="bx-panel">
  <h2 style="margin-top:0">Bezugswege <span class="muted" style="font-weight:400;font-size:14px">je Packung bei <?= $nf0($km) ?> Pkg · die Wahl je Auftrag bleibt im Auftrag</span></h2>
  <div class="bx-cards">
  <?php foreach ($kalk['wege'] as $wk => $w): ?>
    <div class="bx-card"<?= $wk === $kalk['standard'] ? ' style="box-shadow:0 0 0 2px var(--gruen)"' : '' ?>><div class="k"><?= h($w['label']) ?><?= $wk === $kalk['standard'] ? ' · Standard' : '' ?></div>
      <div class="v" style="font-size:16px"><?= $w['inhalt_summe'] > 0 && $w['vollstaendig'] ? $eur2($w['summe']) : ($w['inhalt_summe'] > 0 ? $eur2($w['summe']) . ' <span style="color:var(--warn);font-size:12px">' . (!empty($w['unsicher']) ? 'unsicher: Preis abgelaufen' : 'unvollständig') . '</span>' : '<span class="muted">kein Preis</span>') ?></div>
      <div class="muted" style="font-size:12px">Inhalt <?= $w['inhalt_summe'] > 0 ? $eur($w['inhalt_summe']) : '–' ?> + Verpackung</div></div>
  <?php endforeach; ?>
  </div>
</div>
<div class="bx-panel" id="zukauf">
  <div class="bx-row" style="justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <h2 style="margin:0">Lieferanten für das Produkt <span class="muted" style="font-weight:400;font-size:14px">Zukauf fertig · Bulk · Premix</span></h2>
    <div class="bx-row" style="gap:8px"><?php if ($anfDarf): ?><?= '<button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById(\'dlgAnfWahl\').showModal()">Preis anfragen</button>' ?><?php endif; ?>
      <?php if ($darf): ?><button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('dlgZk').showModal()">Preis hinzufügen</button><?php endif; ?></div>
  </div>
  <?php if (!$bs['zukauf']): ?><p class="muted">Noch keine Zukaufpreise. „Preis hinzufügen" oder „Preis anfragen".</p><?php endif; ?>
  <?php $VERSZ = versandart_liste(); foreach ($bs['zukauf'] as $i => $z): $l = $z['lief']; ?>
    <div class="pr-lief">
      <div class="bx-row" style="justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:baseline">
        <div><?= $z['lieferant_id'] ? '<a href="?p=lieferant&id=' . $z['lieferant_id'] . '" style="font-size:15px">' . h($z['firma']) . '</a>' : '<span style="font-size:15px">' . h($z['firma']) . '</span> <span class="muted" style="font-size:12px">(nur Freitext, kein Lieferanten-Datensatz)</span>' ?>
          <?= !empty($z['premix']) ? bx_badge('Premix je kg', 'info') : ($i === 0 ? bx_badge('günstigster', 'ok') : '') ?></div>
        <div class="muted" style="font-size:12px"><?= h(implode(' · ', array_filter([$l['land'] ?? '', $z['portal'] ? 'Portal-Account' : ($z['lieferant_id'] ? 'kein Portal-Account' : ''), !empty($l['lieferzeit_tage']) ? 'Lieferzeit ' . (int)$l['lieferzeit_tage'] . ' Tage' : '', !empty($l['mindestbestellwert']) ? 'Mindestbestellwert ' . $eur2($l['mindestbestellwert']) : '', $l['ansprechpartner'] ?? '']))) ?></div>
      </div>
      <div class="bx-tablewrap" style="margin-top:6px"><table class="bx-table"><thead><tr><th class="bx-num">ab Menge</th><th class="bx-num">Preis</th><th>Incoterm</th><th>Versand</th><th>Stand</th><th>Quelle</th><th></th></tr></thead><tbody>
      <?php foreach ($z['staffeln'] as $s): ?><tr><td class="bx-num"><?= $s['ab'] > 0 ? $nf0($s['ab']) : '–' ?></td><td class="bx-num"><?= preis_zahl($s['preis']) . ('') ?> <?= h($s['waehrung'] === 'EUR' ? '€' : $s['waehrung']) ?>/<?= h(['kapsel' => 'Kapsel', 'packung' => 'Pkg'][$s['einheit']] ?? $s['einheit']) ?><?= !empty($s['abgelaufen']) ? ' ' . bx_badge('abgelaufen', 'err') : '' ?></td>
        <td><?= $s['incoterm'] !== '' ? h($s['incoterm']) : '–' ?></td><td><?= $s['versandart'] !== '' ? h($VERSZ[$s['versandart']] ?? $s['versandart']) : '–' ?></td><td><?= $s['stand'] ? h(date('d.m.Y', strtotime($s['stand']))) : '–' ?></td><td class="muted"><?= h($s['quelle']) ?></td>
        <td style="text-align:right"><?php if ($s['id'] && $darf): ?><form method="post" style="display:inline" onsubmit="return confirm('Zukaufpreis löschen?');"><input type="hidden" name="aktion" value="zk_preis_del"><input type="hidden" name="preis_id" value="<?= $s['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">×</button></form><?php endif; ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php if ($z['doks']): ?><div style="font-size:12px;margin-top:4px">Dokumente: <?= implode(' · ', array_map(fn($d) => '<a href="?p=dokument&id=' . (int)$d['id'] . '" target="_blank">' . h(strtoupper((string)$d['typ']) . ' ' . $d['datei_orig']) . '</a>', $z['doks'])) ?></div><?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php if ($darf): ?>
<dialog id="dlgZk" class="bx-dialog">
  <?= $dlgKopf('Zukaufpreis hinzufügen', 'dlgZk') ?>
  <form method="post" style="margin:0"><input type="hidden" name="aktion" value="zk_preis_add">
    <?= $fld('Lieferant', '<select name="zk_lieferant"' . $rscombo($lieferanten) . ' style="width:100%">' . $optListe($lieferanten, 0, '– Freitext unten –', fn($r) => (string)$r['firma']) . '</select>') ?>
    <?= $fld('oder Name (Freitext)', '<input type="text" name="zk_lieferant_name" placeholder="z. B. Wellgreen">') ?>
    <div class="bx-row" style="gap:8px;flex-wrap:wrap">
      <div class="bx-field" style="margin:0;width:110px"><label>ab Menge</label><input type="text" inputmode="numeric" name="zk_menge_ab" value="0"></div>
      <div class="bx-field" style="margin:0;width:110px"><label>Preis (€)</label><input type="text" inputmode="decimal" name="zk_preis" required placeholder="0,0228"></div>
      <div class="bx-field" style="margin:0;width:150px"><label>je</label><select name="zk_einheit"><option value="kapsel"<?= $istHandel ? '' : ' selected' ?>>Kapsel / Stk (lose)</option><option value="packung"<?= $istHandel ? ' selected' : '' ?>>Packung (fertige Ware)</option><option value="kg">kg (Mischung/Pulver)</option></select></div>
      <div class="bx-field" style="margin:0;width:110px"><label>Incoterm</label><select name="zk_incoterm"><option value="">–</option><?php foreach (incoterm_liste() as $k => $lbl): ?><option value="<?= $k ?>"><?= h($k) ?></option><?php endforeach; ?></select></div>
      <div class="bx-field" style="margin:0;width:120px"><label>Versand</label><select name="zk_versandart"><option value="">–</option><?php foreach (versandart_liste() as $k => $lbl): ?><option value="<?= $k ?>"><?= h($lbl) ?></option><?php endforeach; ?></select></div>
      <?= pg_eingabe('zk_gueltig_bis', null) ?>
    </div>
    <div style="margin-top:14px"><button class="btn btn-primary btn-sm" type="submit">Speichern</button></div>
  </form>
</dialog>
<?php endif; ?>
<div class="bx-panel">
  <h2 style="margin-top:0">Rohstoffe der Rezeptur <span class="muted" style="font-weight:400;font-size:14px">für Eigenproduktion</span></h2>
  <?php if (!$bs['rohstoffe']): ?><p class="muted">Keine Rezeptur-Zutaten.</p><?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Rohstoff</th><th class="bx-num">Bedarf je Pkg</th><th>Lieferanten (Preis ab Menge)</th><th class="bx-num">Lager frei</th></tr></thead><tbody>
  <?php foreach ($bs['rohstoffe'] as $r): $mgP = $schuett ? 0 : (float)$r['menge_mg'] * $einh; ?>
    <tr><td><a href="?p=rohstoff&id=<?= (int)$r['item_id'] ?>"><?= h((string)$r['name']) ?></a></td>
      <td class="bx-num"><?= $schuett ? '<span class="muted">anteilig</span>' : number_format($mgP / 1000, 1, ',', '.') . ' g' ?></td>
      <td style="white-space:normal"><?php if (!$r['preise']): ?><span style="color:var(--warn)">kein Lieferantenpreis</span><?= (float)$r['ek_preis'] > 0 ? ' <span class="muted">· EK am Rohstoff ' . $eur((float)$r['ek_preis']) . '/' . h((string)$r['bezug']) . '</span>' : '' ?>
        <?php else: $j = 0; foreach ($r['preise'] as $pr): if ($j++ >= 4) break; ?><div><?= h((string)($pr['firma'] ?: 'ohne Namen')) ?> · <?= $eur((float)$pr['preis']) ?>/<?= h((string)$r['bezug']) ?><?= (float)$pr['menge_ab'] > 0 ? ' ab ' . $nf0($pr['menge_ab']) : '' ?><?= !empty($pr['abgelaufen']) ? ' ' . bx_badge('abgelaufen seit ' . date('d.m.Y', strtotime((string)$pr['gueltig_bis'])), 'err') : ($j === 1 ? ' ' . bx_badge('günstigster', 'ok') : '') ?></div><?php endforeach; endif; ?></td>
      <td class="bx-num"><?= $nf0($r['bestand']) ?> <?= h((string)($r['einheit'] ?: 'g')) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<div class="bx-panel">
  <h2 style="margin-top:0">Verpackung</h2>
  <?php if (!$bs['verpackung']): ?><p class="muted">Keine Verpackung am Produkt.</p><?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Teil</th><th>Artikel</th><th>Lieferant</th><th class="bx-num">EK bei <?= $nf0($km) ?></th><th class="bx-num">Lager frei</th><th class="bx-num">unterwegs</th></tr></thead><tbody>
  <?php foreach ($bs['verpackung'] as $v): ?><tr><td><?= h($v['rolle']) ?></td><td><a href="?p=verpackung&id=<?= (int)$v['item']['id'] ?>"><?= h((string)$v['item']['name']) ?></a></td>
    <td><?= !empty($v['item']['firma']) ? h((string)$v['item']['firma']) : '<span class="muted">–</span>' ?></td><td class="bx-num"><?= $v['ek'] > 0 ? $eur($v['ek']) : $fehlt('Preis fehlt') ?></td>
    <td class="bx-num"><?= $nf0($v['bestand']) ?> Stk</td><td class="bx-num"><?= $nf0($v['unterwegs']) ?> Stk</td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<div class="bx-panel">
  <h2 style="margin-top:0">Letzte Bestellungen <span class="muted" style="font-weight:400;font-size:14px">zu diesem Produkt und seinen Rohstoffen/Verpackungen</span></h2>
  <?php if (!$bs['bestellungen']): ?><p class="muted">Noch keine Bestellungen.</p><?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Bestellung</th><th>Was</th><th>Lieferant</th><th class="bx-num">Menge</th><th class="bx-num">Preis</th><th>Datum</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($bs['bestellungen'] as $b): $dt = $b['bestelldatum'] ?: $b['angelegt']; ?><tr><td><a href="?p=bestellung&id=<?= (int)$b['id'] ?>"><?= h((string)($b['nummer'] ?: '#' . $b['id'])) ?></a></td><td><?= h((string)$b['was']) ?></td><td><?= h((string)$b['lieferant']) ?: '–' ?></td>
    <td class="bx-num"><?= number_format((float)$b['menge'], (float)$b['menge'] == floor((float)$b['menge']) ? 0 : 2, ',', '.') ?> <?= h((string)$b['einheit']) ?></td><td class="bx-num"><?= (float)$b['ek_preis'] > 0 ? $eur((float)$b['ek_preis']) : '–' ?></td>
    <td><?= $dt ? h(date('d.m.Y', strtotime((string)$dt))) : '–' ?></td><td><?php $stu = bestellung_stufe($b); echo bx_badge((string)$stu['label'], (string)$stu['kind']); ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<div class="bx-panel">
  <h2 style="margin-top:0">Preisanfragen</h2>
  <?php if (!$bs['anfragen']): ?><p class="muted">Keine Preisanfragen zu diesem Produkt oder seinen Rohstoffen.</p><?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Anfrage</th><th>Was</th><th>Lieferant</th><th>Status</th><th>Antwort</th><th>Datum</th></tr></thead><tbody>
  <?php foreach ($bs['anfragen'] as $a): ?><tr><td><a href="?p=lief_anfrage_detail&id=<?= (int)$a['id'] ?>"><?= h((string)($a['nummer'] ?: '#' . $a['id'])) ?></a></td>
    <td><?= $a['item'] !== '' ? h((string)$a['item']) : (!$schuett && in_array((string)($a['form'] ?? ''), ['pulver', 'granulat'], true) ? 'Premix (je kg)' : 'Fertigprodukt') ?></td><td><?= h((string)$a['firma']) ?></td><td><?= h((string)$a['status']) ?></td>
    <td><?= $a['ang_preis'] !== null ? $eur((float)$a['ang_preis']) . '/' . h((string)$a['ang_einheit']) . ($a['ang_mm'] ? ' · ab ' . $nf0($a['ang_mm']) : '') . ($a['ang_lz'] ? ' · ' . (int)$a['ang_lz'] . ' Tage' : '') : '<span class="muted">offen</span>' ?></td>
    <td><?= h(fmt_zeit((string)$a['angelegt'], 'd.m.Y')) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
</div>
<?php endif;

// ===================================================================== Reiter Produktion
$etAuto = (empty($p['etikett_id']) && !empty($p['verpackung_id'])) ? etikett_id_fuer_behaelter((int)$p['verpackung_id']) : 0;
$etb = etikett_bestand_info($pid);
$weg = ['abfuellen' => (int)($p['weg_abfuellen'] ?? 1), 'etikettieren' => (int)($p['weg_etikettieren'] ?? 1), 'beipack' => (int)($p['weg_beipack'] ?? 0), 'karton' => (int)($p['weg_karton'] ?? 0)];
$kartons = $verpListe('karton'); $beipacks = $verpListe('beipack'); $etiketten = $verpListe('etikett', $pid); ?>
<div<?= $panel('produktion') ?>>
<form method="post" class="bx-form bx-panel">
  <input type="hidden" name="aktion" value="kachel"><input type="hidden" name="feld" value="produktion">
  <h2 style="margin-top:0">Stückliste</h2>
  <div class="bx-grid">
    <div class="bx-field"><label>Umkarton</label><select name="karton_id"<?= $rscombo($kartons) ?>><?= $optListe($kartons, (int)($p['karton_id'] ?? 0)) ?></select></div>
    <div class="bx-field"><label>Packungen je Umkarton</label><input type="text" inputmode="numeric" name="karton_inhalt" value="<?= h((string)($p['karton_inhalt'] ?? '')) ?>" placeholder="z. B. 48"></div>
    <div class="bx-field"><label>Beipackzettel</label><select name="beipack_id"<?= $rscombo($beipacks) ?>><?= $optListe($beipacks, (int)($p['beipack_id'] ?? 0)) ?></select></div>
    <div class="bx-field"><label>Etikett <?= bx_hint('Leer = automatisch aus dem Glas. Nur füllen, wenn ein abweichendes Etikett gewünscht ist. Kundenetiketten (Design je Kunde) stehen im Reiter Kunden.') ?></label>
      <select name="etikett_id"<?= $rscombo($etiketten) ?>><?= $optListe($etiketten, (int)($p['etikett_id'] ?? 0), $etAuto ? '– automatisch aus dem Glas –' : '– keines –') ?></select>
      <?php if ($etAuto): ?><div class="muted" style="font-size:12px;margin-top:4px">automatisch aus dem Glas: <?= h($itemName($etAuto)) ?></div><?php endif; ?></div>
  </div>
  <?php if ($etb['hat_etikett']): ?><div class="muted" style="font-size:13px;margin:4px 0 8px">Kundenetiketten gesamt: im Lager <?= $nf0($etb['physisch']) ?> Stk · Bedarf offene Aufträge <?= $nf0($etb['kunde_rest']) ?> Stk<?= $etb['physisch'] < $etb['kunde_rest'] ? ' · <span style="color:var(--warn)">fehlen ' . $nf0($etb['kunde_rest'] - $etb['physisch']) . ' Stk</span>' : '' ?> (je Kunde im Reiter Kunden)</div><?php endif; ?>
  <?php if (has_role('admin')): ?>
  <h2>Standard-Produktionsweg <?= bx_hint('Welche Ausbaustufen beim Anlegen eines Produktionsauftrags gesetzt werden. Nur der Startzustand – je Auftrag im Produktions-Programm überschreibbar. Nur Admin.') ?></h2>
  <div class="bx-row" style="gap:18px;flex-wrap:wrap">
    <?php foreach (['abfuellen' => 'Abfüllen / Verpacken', 'etikettieren' => 'Etikettieren', 'beipack' => 'Beipackzettel beilegen', 'karton' => 'Umkarton / Umverpackung'] as $wk => $wl): ?>
      <label class="bx-check" style="margin:0"><input type="checkbox" name="weg_<?= $wk ?>" value="1"<?= $weg[$wk] ? ' checked' : '' ?>> <?= h($wl) ?></label>
    <?php endforeach; ?>
  </div>
  <?php else: ?><div class="muted" style="font-size:13px">Standard-Produktionsweg: <?= h(implode(' · ', array_keys(array_filter($weg)))) ?: '–' ?> (ändert nur Admin)</div><?php endif; ?>
  <div class="bx-field" style="margin-top:12px"><label>Notiz</label><textarea name="notiz" rows="2"><?= h((string)($p['notiz'] ?? '')) ?></textarea></div>
  <?php if ($darf): ?><button class="btn btn-primary btn-sm" type="submit">Speichern</button><?php endif; ?>
</form>
</div>
<?php
// ===================================================================== Reiter Kalkulation
if (!$istExtern):
    $wegKeys = array_keys($kalk['wege']);
    $matrix = all("SELECT pp.*, i.name AS verp FROM produkt_preis pp JOIN item i ON i.id=pp.verpackung_id WHERE pp.produkt_id=? ORDER BY pp.stueck, i.name, pp.bestellmenge", [$pid]);
    $matrixMengen = array_values(array_unique(array_map(fn($r) => (int)$r['bestellmenge'], $matrix))); sort($matrixMengen);
    $matrixGrid = [];
    foreach ($matrix as $r) { $key = $r['stueck'] . '|' . $r['verp']; $matrixGrid[$key]['stueck'] = (int)$r['stueck']; $matrixGrid[$key]['verp'] = $r['verp']; $matrixGrid[$key]['cells'][(int)$r['bestellmenge']] = ['ek' => (float)$r['ek_preis'], 'vk' => (float)$r['vk_preis']]; }
    $zelle = fn($z) => $z === null ? '<span class="muted">–</span>' : ($z['preis'] === null ? '<span style="color:var(--warn)">Preis fehlt</span>' : $eur($z['preis'])); ?>
<div<?= $panel('kalkulation') ?>>
<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <h2 style="margin:0">Kosten je Packung</h2>
    <form method="get" class="bx-row" style="gap:6px;align-items:center;margin:0"><input type="hidden" name="p" value="produkt"><input type="hidden" name="id" value="<?= $pid ?>"><input type="hidden" name="tab" value="kalkulation">
      <span class="muted" style="font-size:13px">Menge</span>
      <?php foreach ([1000, 3000, 10000] as $mm): ?><a class="btn btn-<?= $km === $mm ? 'primary' : 'ghost' ?> btn-sm" href="?p=produkt&id=<?= $pid ?>&tab=kalkulation&km=<?= $mm ?>"><?= $nf0($mm) ?></a><?php endforeach; ?>
      <input type="text" inputmode="numeric" name="km" value="<?= in_array($km, [1000, 3000, 10000], true) ? '' : h($nf0($km)) ?>" placeholder="andere" style="width:90px"><button class="btn btn-ghost btn-sm" type="submit">rechnen</button> <span class="muted" style="font-size:13px">Pkg</span></form>
  </div>
  <?php foreach ($kalk['hinweise'] as $hw): ?><div class="badge-warn" style="padding:8px 12px;margin-top:10px"><?= h($hw) ?></div><?php endforeach; ?>
  <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table pr-kalk">
    <thead><tr><th>Zeile</th><?php foreach ($kalk['wege'] as $wk => $w): ?><th class="bx-num"<?= $wk === $kalk['standard'] ? ' style="background:var(--row-hover)"' : '' ?>><?= h($w['label']) ?><?= $wk === $kalk['standard'] ? '<div class="muted" style="font-size:11px;font-weight:400">Standard · ' . h($kalk['standard_quelle']) . '</div>' : '' ?></th><?php endforeach; ?></tr></thead>
    <tbody>
      <tr><td>Inhalt</td><?php foreach ($kalk['wege'] as $wk => $w): $s = 0.0; $voll = true; foreach ($w['inhalt'] as $z) { if (in_array($z['text'], ['Leerkapseln', 'Presshilfsstoffe', 'Gummi-Grundmasse', 'Trägerflüssigkeit', 'Gel-Grundmasse'], true)) continue; if ($z['preis'] === null) $voll = false; else $s += $z['preis']; } ?>
        <td class="bx-num"<?= $wk === $kalk['standard'] ? ' style="background:var(--row-hover)"' : '' ?>><?= !$voll && $s <= 0 ? '<span style="color:var(--warn)">Preis fehlt</span>' : $eur($s) . (!$voll ? '<div style="color:var(--warn);font-size:11px">teils Preis fehlt</div>' : '') ?>
          <div class="muted" style="font-size:11px;white-space:normal;max-width:260px;margin-left:auto"><?= h($wk === 'rohstoffe' ? count(array_filter($w['inhalt'], fn($z) => !in_array($z['text'], ['Leerkapseln', 'Presshilfsstoffe', 'Gummi-Grundmasse', 'Trägerflüssigkeit', 'Gel-Grundmasse'], true))) . ' Rohstoffe – Aufstellung unten' : (string)($w['inhalt'][0]['detail'] ?? '')) ?></div></td><?php endforeach; ?></tr>
      <?php $zus = ['Leerkapseln', 'Presshilfsstoffe', 'Gummi-Grundmasse', 'Trägerflüssigkeit', 'Gel-Grundmasse'];
        foreach ($zus as $zn): $hat = false; foreach ($kalk['wege'] as $w) foreach ($w['inhalt'] as $z) if ($z['text'] === $zn) $hat = true; if (!$hat) continue; ?>
        <tr><td><?= h($zn) ?></td><?php foreach ($kalk['wege'] as $wk => $w): $zz = null; foreach ($w['inhalt'] as $z) if ($z['text'] === $zn) $zz = $z; ?>
          <td class="bx-num"<?= $wk === $kalk['standard'] ? ' style="background:var(--row-hover)"' : '' ?>><?= $zz ? $zelle($zz) : '<span class="muted">im Preis enthalten</span>' ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
      <?php foreach ($kalk['verpackung'] as $v): ?>
        <tr><td><?= h($v['text']) ?> <span class="muted" style="font-size:12px"><?= $v['link'] ? '<a href="' . h($v['link']) . '">' . h($v['detail']) . '</a>' : h($v['detail']) ?></span></td>
          <?php foreach ($wegKeys as $wk): ?><td class="bx-num"<?= $wk === $kalk['standard'] ? ' style="background:var(--row-hover)"' : '' ?>><?= !empty($v['leer']) ? '<span class="muted">–</span>' : $zelle($v) ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
      <tr><td>Herstellung <span class="muted" style="font-size:12px">kein Satz hinterlegt – nicht gerechnet</span></td><?php foreach ($wegKeys as $wk): ?><td class="bx-num muted"<?= $wk === $kalk['standard'] ? ' style="background:var(--row-hover)"' : '' ?>>–</td><?php endforeach; ?></tr>
      <tr style="font-weight:600"><td>Summe je Packung</td><?php foreach ($kalk['wege'] as $wk => $w): ?><td class="bx-num"<?= $wk === $kalk['standard'] ? ' style="background:var(--row-hover)"' : '' ?>><?= $w['inhalt_summe'] > 0 ? $eur2($w['summe']) : '–' ?><?= !$w['vollstaendig'] ? '<div style="color:var(--warn);font-size:11px;font-weight:400">' . (!empty($w['unsicher']) ? 'unsicher: Preis abgelaufen' : 'unvollständig') . '</div>' : '' ?></td><?php endforeach; ?></tr>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12px;margin-top:8px">Staffel nach Menge wie im Auftrag: je Lieferant die größte Staffel, deren Mindestmenge bei <?= $nf0($km) ?> Pkg erreicht ist; darunter gilt die kleinste Staffel (Hinweis Mindestmenge). Fehlt ein Preis, wird nicht mit 0 gerechnet – die Summe ist dann unvollständig. Bisher zeigte die Seite nur Rohstoff-EK am Rohstoff + EK aller Stücklisten-Artikel (ohne Zukauf, ohne Staffel).</p>
  <?php if (!empty($kalk['wege']['rohstoffe'])): ?>
  <details style="margin-top:8px"><summary style="cursor:pointer">Einzelrohstoffe im Detail</summary>
    <div class="bx-tablewrap" style="margin-top:8px"><table class="bx-table"><thead><tr><th>Rohstoff</th><th>Menge · Preis</th><th class="bx-num">je Pkg</th></tr></thead><tbody>
    <?php foreach ($kalk['wege']['rohstoffe']['inhalt'] as $z): ?><tr><td><?= $z['link'] ? '<a href="' . h($z['link']) . '">' . h($z['text']) . '</a>' : h($z['text']) ?></td><td class="muted"><?= h($z['detail']) ?></td><td class="bx-num"><?= $zelle($z) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></details>
  <?php endif; ?>
</div>
<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:center">
    <h2 style="margin:0">Preis-Matrix <?= bx_hint('VK-Kalkulation: Packungsgröße × passende Verpackung × Bestellmenge, VK = EK × Marge je Typ, ohne Kundenrabatt. Interne Sale-Auskunft.') ?></h2>
    <form method="post" style="margin:0"><input type="hidden" name="aktion" value="matrix"><button class="btn btn-primary btn-sm" type="submit">Matrix neu berechnen</button></form>
  </div>
  <?php if (!$matrix): ?><p class="muted">Noch keine Matrix. „Matrix neu berechnen" erzeugt alle Kombinationen (Rezeptur + hinterlegte Behälter-Fassung).</p>
  <?php else: ?>
    <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table"><thead><tr><th>Größe</th><th>Verpackung</th><?php foreach ($matrixMengen as $mn): ?><th class="bx-num"><?= $nf0($mn) ?> Stk</th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach ($matrixGrid as $row): ?><tr><td><?= h(form_groessen_label($pForm, (float)$row['stueck'])) ?></td><td><?= h((string)$row['verp']) ?></td>
      <?php foreach ($matrixMengen as $mn): $c = $row['cells'][$mn] ?? null; ?><td class="bx-num"><?php if ($c): ?><?= $eur2($c['vk']) ?><div class="muted" style="font-size:11px">EK <?= $eur2($c['ek']) ?></div><?php else: ?><span class="muted">–</span><?php endif; ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table></div>
    <div class="muted" style="margin-top:8px;font-size:12px">VK je Packung (Basis, ohne Kundenrabatt). Stand: <?= h(fmt_zeit($matrix[0]['stand'])) ?>.</div>
  <?php endif; ?>
</div>
</div>
<?php endif;

// ===================================================================== Reiter Bestellungen (Nico 10.11.2026)
// Wer hat das Produkt gekauft: je Auftrag Kunde, Menge, Status und die Fertigprodukt-Chargen mit MHD.
$bstAlle = ($_GET['bst'] ?? '') === 'alle';
$bstRows = produkt_bestellungen($pid, $bstAlle);
$bstStatus = ['offen' => 'offen', 'in_produktion' => 'in Produktion', 'erledigt' => 'versandbereit', 'versendet' => 'versendet', 'storniert' => 'storniert'];
$bstSum = array_sum(array_map(fn($a) => (int)$a['menge'], array_filter($bstRows, fn($a) => $a['status'] !== 'storniert')));
$bstKunden = count(array_unique(array_map(fn($a) => (int)$a['kunde_id'], $bstRows)));
$bstHeute = gmdate('Y-m-d'); ?>
<div<?= $panel('bestellungen') ?> id="bestellungen">
<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <h2 style="margin:0">Bestellungen <span class="muted" style="font-weight:400;font-size:14px">(<?= count($bstRows) ?> Aufträge · <?= $bstKunden ?> Kunde<?= $bstKunden === 1 ? '' : 'n' ?> · <?= $nf0($bstSum) ?> Stk)</span></h2>
    <div class="bx-row" style="gap:10px;align-items:center">
      <?php if ($bstRows): ?><input type="search" id="bstSuche" placeholder="Kunde, Auftrag oder Charge suchen" style="width:260px"><?php endif; ?>
      <a class="muted" style="font-size:13px" href="?p=produkt&id=<?= $pid ?>&tab=bestellungen<?= $bstAlle ? '' : '&bst=alle' ?>"><?= $bstAlle ? 'Stornierte ausblenden' : 'Auch stornierte zeigen' ?></a>
    </div>
  </div>
  <?php if (!$bstRows): ?><p class="muted">Noch keine Aufträge mit diesem Produkt.</p><?php else: ?>
  <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table pr-bst" id="bstTab">
    <thead><tr><th>Datum</th><th>Auftrag</th><th>Kunde</th><th class="bx-num">Menge</th><th>Status</th><th>Charge</th><th>MHD</th></tr></thead>
    <tbody>
    <?php foreach ($bstRows as $b): $st = (string)$b['status']; $vm = (int)($b['versendet_menge'] ?? 0); ?>
      <tr<?= $st === 'storniert' ? ' style="opacity:.55"' : '' ?>>
        <td style="white-space:nowrap"><?= h(date('d.m.Y', strtotime((string)$b['angelegt']))) ?></td>
        <td style="white-space:nowrap"><a href="?p=auftrag&id=<?= (int)$b['id'] ?>"><?= h((string)$b['nummer']) ?></a></td>
        <td><?= kunde_link($b['kunde_id'] ?? null, $b['firma'] !== null ? (string)$b['firma'] : null) ?></td>
        <td class="bx-num" style="white-space:nowrap"><?= $nf0((int)$b['menge']) ?> Stk<?= $vm > 0 && $vm < (int)$b['menge'] ? '<div class="muted" style="font-size:12px">' . $nf0($vm) . ' versendet</div>' : '' ?></td>
        <td><?= bx_badge($bstStatus[$st] ?? $st, $st === 'versendet' ? 'ok' : '') ?></td>
        <?php if (!$b['chargen']): ?>
          <td class="muted" colspan="2"><?= in_array($st, ['offen', 'in_produktion'], true) ? 'noch nicht produziert' : 'keine Charge zugeordnet' ?></td>
        <?php else: ?>
          <td style="white-space:nowrap"><?php foreach ($b['chargen'] as $i => $c): ?><div<?= $i ? ' style="margin-top:4px"' : '' ?>><a href="?p=chargen&id=<?= (int)$c['id'] ?>"><?= h((string)$c['charge_nr']) ?></a><?php if (count($b['chargen']) > 1): ?> <span class="muted" style="font-size:12px"><?= $nf0((float)$c['menge']) ?> Stk</span><?php endif; ?><?= $c['status'] === 'quarantaene' ? ' ' . bx_badge('Quarantäne', 'warn') : '' ?></div><?php endforeach; ?></td>
          <td style="white-space:nowrap"><?php foreach ($b['chargen'] as $i => $c): $mhd = (string)($c['mhd'] ?? ''); ?><div<?= $i ? ' style="margin-top:4px"' : '' ?>><?= $mhd !== '' ? h(date('d.m.Y', strtotime($mhd))) . ($mhd < $bstHeute ? ' ' . bx_badge('abgelaufen', 'warn') : '') : '<span class="muted">nicht gesetzt</span>' ?></div><?php endforeach; ?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12px;margin:10px 0 0">Charge = Fertigprodukt-Charge aus der Produktion dieses Auftrags bzw. dem Auftrag fest zugeordnet. Ware, die ohne Zuordnung aus dem allgemeinen Lager ging, hat hier keine Charge.</p>
  <?php endif; ?>
</div>
</div>
<script>
(function(){ var f=document.getElementById('bstSuche'), t=document.getElementById('bstTab'); if(!f||!t) return;
  f.addEventListener('input', function(){ var q=f.value.trim().toLowerCase();
    t.querySelectorAll('tbody tr').forEach(function(r){ r.style.display = (!q || r.textContent.toLowerCase().indexOf(q) >= 0) ? '' : 'none'; }); });
})();
</script>
<?php
// ===================================================================== Reiter Dokumente
require_once BX_ROOT . '/core/pdf_pib.php';
$pibDok = pib_datei($pid);
$lieferantenDok = $lieferanten ?? all("SELECT id, firma FROM lieferanten WHERE gesperrt=0 ORDER BY firma");
$specDocs = all("SELECT id, typ, datei_orig, ki_daten, angelegt FROM dokument WHERE objekt_typ='produkt' AND objekt_id=? AND typ IN ('spec','coa','analyse') AND ki_daten IS NOT NULL ORDER BY id DESC", [$pid]); ?>
<div<?= $panel('dokumente') ?>>
<div id="dok">
  <?php if (!ki_bereit()): ?><div class="muted" style="font-size:12px;margin:0 0 8px">Spec/CoA/Laboranalyse werden nach dem Hochladen von der KI ausgelesen (nur auf beta). Hochladen geht überall.</div><?php endif; ?>
  <?php dokument_panel('produkt', $pid, $lieferantenDok); ?>
</div>
<?php if ($specDocs): ?>
<div class="bx-panel">
  <h2 style="margin-top:0">Von der KI ausgelesen</h2>
  <?php foreach ($specDocs as $d): $vor = json_decode((string)$d['ki_daten'], true); if (!is_array($vor)) continue; $stamm = (array)($vor['stamm'] ?? []); $charge = (array)($vor['charge'] ?? []); $werte = (array)($vor['werte'] ?? []); ?>
    <div class="bx-panel" style="background:var(--panel-2);margin-top:10px">
      <div class="bx-row" style="justify-content:space-between;gap:10px;flex-wrap:wrap"><div><?= h(strtoupper((string)$d['typ'])) ?> · <a href="?p=dokument&id=<?= (int)$d['id'] ?>" target="_blank"><?= h((string)$d['datei_orig']) ?></a></div><span class="muted" style="font-size:12px"><?= h(fmt_zeit((string)$d['angelegt'])) ?></span></div>
      <div class="bx-grid" style="margin-top:8px;font-size:13px">
        <?php foreach (['haltbarkeit' => 'Haltbarkeit', 'allergene' => 'Allergene', 'zertifikate' => 'Zertifikate', 'lagerbedingungen' => 'Lagerung'] as $k => $lbl) if (!empty($stamm[$k])) echo '<div><span class="muted">' . $lbl . ':</span> ' . h((string)$stamm[$k]) . '</div>'; ?>
        <?php foreach (['charge_nr' => 'Charge', 'mhd' => 'MHD', 'herstelldatum' => 'Herstelldatum', 'menge' => 'Menge'] as $k => $lbl) if (!empty($charge[$k])) echo '<div><span class="muted">' . $lbl . ':</span> ' . h((string)$charge[$k]) . '</div>'; ?>
      </div>
      <?php if ($werte): ?><details style="margin-top:6px"><summary style="cursor:pointer;font-size:13px"><?= count($werte) ?> Analysewerte</summary><div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Parameter</th><th>Spezifikation</th><th>Ergebnis</th></tr></thead><tbody>
        <?php foreach (array_slice($werte, 0, 40) as $w): ?><tr><td><?= h((string)($w['parameter'] ?? '')) ?></td><td><?= h((string)($w['spezifikation'] ?? '')) ?></td><td><?= h((string)($w['ergebnis'] ?? '')) ?></td></tr><?php endforeach; ?>
      </tbody></table></div></details><?php endif; ?>
      <?php if ($d['typ'] === 'spec' && (!empty($stamm['haltbarkeit']) || !empty($stamm['allergene']))): ?>
        <form method="post" style="margin-top:10px"><input type="hidden" name="aktion" value="spec_uebernehmen"><input type="hidden" name="dok_id" value="<?= (int)$d['id'] ?>"><button class="btn btn-primary btn-sm" type="submit">Haltbarkeit/Allergene ins Produkt übernehmen</button></form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<div class="bx-panel" id="pibfeld">
  <h2 style="margin-top:0">Produktinformationsblatt (PIB) <?= bx_hint('Der Kunde bekommt beim Bestellen automatisch ein PIB aus den Produktdaten. Ein hochgeladenes PIB hat Vorrang.') ?></h2>
  <p class="muted" style="margin-top:0">Aktuell: <?= $pibDok ? 'hochgeladenes PIB (' . h((string)($pibDok['datei_orig'] ?: 'Datei')) . ')' : 'automatisch aus den Produktdaten erzeugt' ?></p>
  <?php if (!$pibDok && produkt_etikettmass($pid) === null): ?><div class="badge-warn" style="padding:8px 12px;margin-bottom:10px">Etikettmaß fehlt – das automatische PIB nennt dann „individuell – nach Rücksprache". Am Behälter das Endformat (B × H) hinterlegen.</div><?php endif; ?>
  <div class="bx-row" style="gap:10px;align-items:center;flex-wrap:wrap">
    <a class="btn btn-ghost btn-sm" href="?p=produkt_pib&id=<?= $pid ?>" target="_blank">PIB ansehen</a>
    <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:8px;align-items:center;margin:0"><input type="hidden" name="aktion" value="pib_upload"><input type="file" name="pib" required accept="application/pdf,image/*"><button class="btn btn-primary btn-sm" type="submit"><?= $pibDok ? 'PIB ersetzen' : 'Eigenes PIB hochladen' ?></button></form>
    <?php if ($pibDok): ?><form method="post" onsubmit="return confirm('Hochgeladenes PIB entfernen? Danach gilt wieder das automatische.')" style="margin:0"><input type="hidden" name="aktion" value="pib_del"><button class="btn btn-ghost btn-sm" type="submit">Hochgeladenes PIB entfernen</button></form><?php endif; ?>
  </div>
</div>
</div>
<style>
.pr-kacheln{grid-template-columns:repeat(auto-fill,minmax(190px,1fr))}
.pr-kacheln .bx-card a{color:inherit}
.pr-lief{padding:12px 0;border-top:1px solid var(--line-2)}
.pr-lief:first-of-type{border-top:0}
.pr-kunden td{vertical-align:top}
.pr-bst td{vertical-align:top}
.pr-kalk td{vertical-align:top}
</style>
<script>
(function(){
  // Reiter ohne Neuladen umschalten (Links funktionieren auch ohne JS). Alte Anker (#kundennamen, #zukauf …) öffnen ihren Reiter.
  var tabs = document.querySelectorAll('#prodTabs a[data-tab]'), panes = document.querySelectorAll('[data-ppanel]');
  function zeigen(k){ var ok=false; tabs.forEach(function(t){ var on=t.getAttribute('data-tab')===k; t.classList.toggle('on',on); if(on) ok=true; });
    if(!ok) return; panes.forEach(function(p){ p.style.display = p.getAttribute('data-ppanel')===k ? '' : 'none'; });
    try { var u=new URL(location.href); u.searchParams.set('tab',k); history.replaceState(null,'',u.toString()); } catch(e){} }
  tabs.forEach(function(t){ t.addEventListener('click', function(e){ e.preventDefault(); zeigen(t.getAttribute('data-tab')); }); });
  document.querySelectorAll('[data-goto]').forEach(function(a){ a.addEventListener('click', function(e){ e.preventDefault(); zeigen(a.getAttribute('data-goto')); document.getElementById('prodTabs').scrollIntoView({behavior:'smooth'}); }); });
  var h=(location.hash||'').replace('#',''), z=h?document.getElementById(h):null;
  if (z) { var pp=z.closest('[data-ppanel]'); if (pp && !new URLSearchParams(location.search).get('tab')) zeigen(pp.getAttribute('data-ppanel')); z.scrollIntoView(); }
})();
</script>
<?php
if ($anfDarf):
    // Anfrage-Auswahl (Nico 10.11.2026): fertige Kapseln/Tabletten, Premix (lose Mischung je kg) oder einzelne Rohstoffe.
    $awZut = all("SELECT DISTINCT z.item_id, i.name FROM rezeptur_zutat z JOIN item i ON i.id=z.item_id WHERE z.rezeptur_id=? ORDER BY z.menge_mg DESC", [$rid]);
    $awFertig = $schuett ? 'Fertige Mischung (Bulk, je ' . ($pForm === 'fluessig' || $pForm === 'gel' ? 'L' : 'kg') . ')'
              : ($pForm === 'tablette' ? 'Fertige Tabletten (Bulk)' : ($pForm === 'kapsel' || $pForm === 'softgel' ? 'Fertige Kapseln (Bulk)' : 'Fertiges Produkt (Bulk)'));
    $awFormLbl = (string)(anfrage_formen()[$pForm] ?? $pForm); ?>
<dialog id="dlgAnfWahl" class="bx-dialog" data-rid="<?= (int)$rid ?>" data-name="<?= h((string)$p['name']) ?>" data-form="<?= h($awFormLbl) ?>">
  <?= $dlgKopf('Beim Lieferanten anfragen', 'dlgAnfWahl') ?>
  <div class="muted" style="font-size:13px;margin-bottom:10px">Was soll angefragt werden?</div>
  <label style="display:flex;gap:10px;align-items:flex-start;padding:8px 0"><input type="radio" name="anfWas" value="fertig" checked>
    <span><?= h($awFertig) ?><span class="muted" style="display:block;font-size:12px">Ein Lieferant stellt das ganze Produkt her<?= $schuett ? '' : ', Preis je ' . h($awFormLbl) ?>.</span></span></label>
  <?php if (!$schuett): ?>
  <label style="display:flex;gap:10px;align-items:flex-start;padding:8px 0"><input type="radio" name="anfWas" value="premix">
    <span>Premix (fertige Mischung, lose)<span class="muted" style="display:block;font-size:12px">Die Mischung der Rezeptur ohne <?= $pForm === 'tablette' ? 'Pressen' : 'Kapsel' ?>, Preis je kg – wir <?= $pForm === 'tablette' ? 'pressen' : 'verkapseln' ?> selbst.</span></span></label>
  <?php endif; ?>
  <label style="display:flex;gap:10px;align-items:flex-start;padding:8px 0"><input type="radio" name="anfWas" value="rohstoff" <?= $awZut ? '' : 'disabled' ?>>
    <span>Rohstoffe einzeln<span class="muted" style="display:block;font-size:12px"><?= $awZut ? 'Preis je kg für die gewählten Rohstoffe der Rezeptur.' : 'Die Rezeptur hat keine Rohstoffe.' ?></span></span></label>
  <div id="anfWahlRoh" style="display:none;margin:4px 0 0 26px;max-height:220px;overflow:auto;border:1px solid var(--line);border-radius:8px;padding:6px 10px">
    <?php foreach ($awZut as $z): ?>
      <label style="display:flex;gap:8px;align-items:center;padding:3px 0"><input type="checkbox" class="anfWahlItem" value="<?= (int)$z['item_id'] ?>" data-name="<?= h((string)$z['name']) ?>" checked> <span><?= h((string)$z['name']) ?></span></label>
    <?php endforeach; ?>
  </div>
  <div style="margin-top:14px"><button type="button" class="btn btn-primary btn-sm" onclick="bxAnfWahlWeiter()">Weiter</button></div>
</dialog>
<script>
(function(){
  var d = document.getElementById('dlgAnfWahl');
  d.querySelectorAll('input[name=anfWas]').forEach(function(r){ r.addEventListener('change', function(){
    document.getElementById('anfWahlRoh').style.display = (d.querySelector('input[name=anfWas]:checked') || {}).value === 'rohstoff' ? '' : 'none';
  }); });
})();
function bxAnfWahlWeiter(){
  var d = document.getElementById('dlgAnfWahl'), rid = parseInt(d.getAttribute('data-rid'), 10);
  var w = (d.querySelector('input[name=anfWas]:checked') || {}).value;
  if (w === 'rohstoff') {
    var it = Array.prototype.slice.call(d.querySelectorAll('.anfWahlItem:checked'));
    if (!it.length) { alert('Bitte mindestens einen Rohstoff wählen.'); return; }
    d.close();
    if (it.length === 1) { bxAnfrageOeffnen(parseInt(it[0].value, 10), it[0]); return; }
    bxAnfrageBulkOeffnen(it.map(function(c){ return parseInt(c.value, 10); }),
      it.length + ' Rohstoffe aus ' + d.getAttribute('data-name') + ' \u2013 bei welchen Lieferanten anfragen? (Preis je kg, CoA/Spezifikation)');
    bxAnfrageModus('preis');
    return;
  }
  d.close();
  if (w === 'premix') bxAnfragePremixOeffnen(rid, d); else bxAnfrageProduktOeffnen(rid, d);
}
</script>
<?php anfrage_modal(lieferanten_fuer_anfrage_mehrere(['rohstoff', 'fertigprodukt']), '?p=produkt&id=' . $pid . '&tab=beschaffung');
endif;
render_footer();
