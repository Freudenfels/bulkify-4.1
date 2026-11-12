<?php
// Angebot bearbeiten (Hybrid): Kopf (Kunde/Produkt/Status/Marge/Produktionszeit/Notiz)
// + Positionen automatisch erzeugt & überschreibbar, mit interner Marge (nur intern).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/anfrage_ui.php';   // Preisanfrage-Popup + Status-Badges
require_once BX_ROOT . '/core/rohstoff_moq.php';  // eigene MOQ je Rohstoff: Hinweis „unter MOQ"
require_once BX_ROOT . '/core/preis_gueltig.php'; // Preis-Gültigkeit: abgelaufene EK-Preise markieren, Angebotsgültigkeit
require_once BX_ROOT . '/core/ki.php';           // KI-Angebots-PDF-Erfassung
require_once BX_ROOT . '/core/dienstleistung.php'; // DL-Katalog-Positionen (dl_position_add) + Kundenwunsch bei DL-Angeboten

$id  = $_GET['id'] ?? 'neu';
$neu = ($id === 'neu' || !is_numeric($id));

$fehler = '';
// Nachricht an den Kunden zu diesem Angebot (Antwort auf eine Portal-Rückfrage, Report 5): speichern, Mail, im Portal sichtbar.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$neu && ($_POST['aktion'] ?? '') === 'kunde_nachricht') {
    $an = one("SELECT nummer, kunde_id FROM angebot WHERE id=?", [(int)$id]);
    $txt = trim((string)($_POST['text'] ?? ''));
    if ($an && (int)$an['kunde_id'] > 0 && $txt !== '') {
        $cu = function_exists('current_user') ? current_user() : null;
        kunde_nachricht_senden((int)$an['kunde_id'], 0, (int)$id, 'team', $txt, (string)($cu['name'] ?? ''));
        kunde_nachricht_gelesen((int)$an['kunde_id'], 0, (int)$id, 'team');
        if (mail_bereit()) { $kidM = (int)$an['kunde_id']; $nrM = (string)$an['nummer'];
            nach_antwort(fn() => mail_kunde_einfach($kidM, 'Antwort zu Ihrem Angebot ' . $nrM, "Wir haben Ihnen zu Ihrem Angebot " . $nrM . " geantwortet:\n\n" . $txt, 'meine_anfragen')); }
    }
    header('Location: ?p=angebot&id=' . (int)$id . '&knok=1#kundennachricht'); exit;
}
// Preis-Gültigkeit (11.11.2026): Angebotsgültigkeit auf den kürzesten EK-Preis setzen bzw. abgelaufenes Angebot verlängern.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$neu && in_array($_POST['aktion'] ?? '', ['gueltig_ek_setzen', 'angebot_verlaengern'], true)) {
    $neuBis = ($_POST['aktion'] === 'gueltig_ek_setzen') ? (angebot_ek_gueltig_bis((int)$id)['bis'] ?? null) : angebot_gueltig_bis_default((int)$id);
    if ($neuBis) {
        q("UPDATE angebot SET gueltig_bis=? WHERE id=?", [$neuBis, (int)$id]);
        log_aktivitaet('angebot', (int)$id, 'team', 'Angebot gültig bis ' . date('d.m.Y', strtotime($neuBis)) . ($_POST['aktion'] === 'angebot_verlaengern' ? ' (verlängert).' : ' (kürzester EK-Preis).'), 'notiz');
    }
    header('Location: ?p=angebot&id=' . (int)$id . '&gueltigok=1'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? 'kopf_save';
    // Im Bearbeiten-Modus bleiben (Nico 12.11.2026): Speichern der Tabelle soll nicht in die Übersicht springen, sondern
    // die Tabelle mit neuem Gesamtpreis zeigen. Die Formulare posten auf die aktuelle URL, ?edit=1 ist also mit dabei.
    $editQ = isset($_GET['edit']) ? '&edit=1' : '';
    $f = fn($k) => trim($_POST[$k] ?? '');

    if ($aktion === 'kopf_save') {
        $kunde_id   = ($_POST['kunde_id'] ?? '') !== '' ? (int)$_POST['kunde_id'] : null;
        $produkt_id = ($_POST['produkt_id'] ?? '') !== '' ? (int)$_POST['produkt_id'] : null;
        // Ein Produkt im Kopf ist OPTIONAL: Es dient nur der Preismatrix, aus der der Kunde im Portal eine
        // Zelle wählt. Ein Angebot kann genauso aus Positionen bestehen (Rezeptur, Rohstoff, Dienstleistung) –
        // gerade bei einer Rezeptur-Anfrage gibt es noch gar kein Produkt, es entsteht erst aus dem Angebot.
        {
            // Der Status wird NICHT über die Kopfdaten gesetzt – dafür gibt es die Knöpfe „An Kunden senden"
            // und „Zurückziehen". Speichern darf niemals versehentlich etwas beim Kunden sichtbar machen.
            $status  = $neu ? 'offen' : (string) (scalar("SELECT status FROM angebot WHERE id=?", [(int)$id]) ?: 'offen');
            $gueltig = $f('gueltig_bis') !== '' ? $f('gueltig_bis') : null;
            $marge   = $f('marge') !== '' ? (float)str_replace(',', '.', $f('marge')) : null;
            $pz      = $f('produktionszeit') !== '' ? (float)str_replace(',', '.', $f('produktionszeit')) : null;
            // Jahresabnahmevertrag: Häkchen + Jahresmenge + Festpreis (dann kein Einzelauftrag, sondern Kontingent).
            $jv  = !empty($_POST['jahresvertrag']) ? 1 : 0;
            $jm  = $f('jahresmenge') !== '' ? (int)str_replace(['.', ' '], '', $f('jahresmenge')) : null;
            $jvk = $f('jahres_vk') !== '' ? (float)str_replace(',', '.', $f('jahres_vk')) : null;
            $jlz = $f('jahres_laufzeit_monate') !== '' ? max(1, (int)$f('jahres_laufzeit_monate')) : 12;
            if ($produkt_id && (int) scalar("SELECT COUNT(*) FROM produkt_preis WHERE produkt_id=?", [$produkt_id]) === 0) produkt_matrix_generieren($produkt_id);
            if ($neu) {
                q("INSERT INTO angebot (nummer,kunde_id,produkt_id,status,gueltig_bis,notiz,marge_override,produktionszeit_wochen,jahresvertrag,jahresmenge,jahres_vk,jahres_laufzeit_monate) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                  [naechste_nummer('AN'), $kunde_id, $produkt_id, $status, $gueltig, $f('notiz'), $marge, $pz, $jv, $jm, $jvk, $jlz]);
                $id = insert_id();
                if ($kunde_id) log_aktivitaet('kunde', $kunde_id, 'team', 'Angebot erstellt.', 'angebot', 'angebot', (int)$id);
            } else {
                q("UPDATE angebot SET kunde_id=?,produkt_id=?,status=?,gueltig_bis=?,notiz=?,marge_override=?,produktionszeit_wochen=?,jahresvertrag=?,jahresmenge=?,jahres_vk=?,jahres_laufzeit_monate=? WHERE id=?",
                  [$kunde_id, $produkt_id, $status, $gueltig, $f('notiz'), $marge, $pz, $jv, $jm, $jvk, $jlz, (int)$id]);
            }
            // Kundenspezifischer Produktname (je Produkt+Kunde) – nur setzen, wenn ausgefüllt (leer löscht NICHT,
            // um ihn nicht versehentlich beim Kopf-Speichern zu verlieren; Entfernen geht am Produkt).
            if ($produkt_id && $kunde_id && $f('kn_name') !== '') produkt_kundenname_setzen((int)$produkt_id, (int)$kunde_id, $f('kn_name'));
            header('Location: ?p=angebot&id=' . $id . '&gespeichert=1' . $editQ); exit;
        }
    } elseif ($aktion === 'ki_pdf' && !$neu) {
        // Angebots-PDF (v3) per KI auslesen und die Positionen + Staffel dieses Angebots damit füllen.
        if (!ki_bereit()) { header('Location: ?p=angebot&id=' . $id . '&kifehler=' . urlencode('KI ist nicht eingerichtet (nur auf beta).')); exit; }
        if (empty($_FILES['ki_datei']['tmp_name']) || !is_uploaded_file($_FILES['ki_datei']['tmp_name'])) { header('Location: ?p=angebot&id=' . $id . '&kifehler=' . urlencode('Bitte eine Datei wählen.')); exit; }
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo((string)$_FILES['ki_datei']['name'], PATHINFO_EXTENSION)));
        $tmp = BX_UPLOADS . '/ki_ang_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file($_FILES['ki_datei']['tmp_name'], $tmp)) { header('Location: ?p=angebot&id=' . $id . '&kifehler=' . urlencode('Datei nicht gespeichert.')); exit; }
        $anw = "Dies ist ein Angebots-PDF (Nahrungsergänzung, Lohnherstellung). Lies ALLE Positionszeilen und die "
             . "Tabelle 'Preis je fertiges Produkt' aus. Antworte NUR mit JSON:\n"
             . '{"positionen":[{"artikelnr":"","bezeichnung":"","beschreibung":"","menge":0,"einheit":"Stk.","preis":0}],"staffel":[{"menge":0,"stueck":0,"preis_pkg":0}]}'
             . "\nJede Positionszeile EINZELN (Herstellung, Glas/Verpackung, Deckel, Etikett …) mit ihrem Einzelpreis je Packung aus der Spalte 'Preis' (nicht Gesamt). menge = Spalte 'Menge'. beschreibung = die Zusatzzeilen unter der Bezeichnung. Zahlen mit Punkt als Dezimaltrennzeichen, keine Tausenderpunkte. staffel = Tabelle 'Preis je fertiges Produkt' (menge = ab Menge Packungen, stueck = Stück je Packung, preis_pkg = Preis/Packung).";
        $r = ki_datei_frage($tmp, $anw, ['json' => true, 'zweck' => 'Angebot erfassen', 'max_tokens' => 4000]);
        if (empty($r['ok'])) { @unlink($tmp); header('Location: ?p=angebot&id=' . $id . '&kifehler=' . urlencode('KI: ' . ($r['fehler'] ?? 'Dokument nicht lesbar.'))); exit; }
        $n = angebot_ki_pdf_uebernehmen((int)$id, (array)($r['daten'] ?? []));
        // Original-PDF behalten und ans Angebot hängen – wird dem Kunden/Team als Download bevorzugt ausgeliefert.
        if ($ext === 'pdf') {
            $alt = one("SELECT datei FROM dokument WHERE objekt_typ='angebot' AND objekt_id=? AND typ='angebot_original'", [(int)$id]);
            if ($alt && $alt['datei']) { @unlink(BX_UPLOADS . '/' . basename((string)$alt['datei'])); q("DELETE FROM dokument WHERE objekt_typ='angebot' AND objekt_id=? AND typ='angebot_original'", [(int)$id]); }
            q("INSERT INTO dokument (objekt_typ,objekt_id,typ,titel,datei,datei_orig,kunde_sichtbar) VALUES ('angebot',?,?,?,?,?,1)",
              [(int)$id, 'angebot_original', 'Original-Angebot (PDF)', basename($tmp), mb_substr((string)($_FILES['ki_datei']['name'] ?? 'angebot.pdf'), 0, 190)]);
        } else {
            @unlink($tmp);   // Bilder werden nicht als Kunden-PDF ausgeliefert
        }
        header('Location: ?p=angebot&id=' . $id . ($n > 0 ? '&kiok=' . $n : '&kifehler=' . urlencode('Keine Positionen erkannt.'))); exit;
    } elseif ($aktion === 'pos_save' && !$neu) {
        // Alles oder nichts: Speichern loescht erst alle Positionen und schreibt sie neu.
        // Bricht eine Zeile ab, stuende das Angebot sonst halb leer da.
        db()->beginTransaction();
        try {
        q("DELETE FROM angebot_position WHERE angebot_id=?", [(int)$id]);
        $bez = $_POST['p_bez'] ?? []; $art = $_POST['p_art'] ?? []; $mng = $_POST['p_menge'] ?? [];
        $einh = $_POST['p_einheit'] ?? []; $preis = $_POST['p_preis'] ?? []; $mwst = $_POST['p_mwst'] ?? [];
        $ek = $_POST['p_ek'] ?? []; $besch = $_POST['p_besch'] ?? []; $quelle = $_POST['p_quelle'] ?? []; $grp = $_POST['p_gruppe'] ?? [];
        $prez = $_POST['p_rez'] ?? []; $pstk = $_POST['p_stk'] ?? []; $pvid = $_POST['p_vid'] ?? [];
        $pkspez = $_POST['p_kspez'] ?? [];   // Punkt 6: Verpackung bedruckt/kundenspezifisch (0/1 je Zeile)
        $pblanko = $_POST['p_blanko'] ?? []; // Standbodenbeutel: 1 = Ausnahme „Blanko + Etikett" (Standard vollbedruckt)
        $ptausch = $_POST['p_tausch'] ?? []; // Beutel von Hand gewählt: neue Item-ID je Verpackungszeile
        $tauschGruppe = [];                  // Gruppe => neuer Behälter (für die Herstellungszeile)
        $kidT = (int) scalar("SELECT kunde_id FROM angebot WHERE id=?", [(int)$id]) ?: null;
        $sort = 0;
        foreach ($bez as $i => $b) {
            $b = trim($b); if ($b === '') continue;
            $gv = strtoupper(trim($grp[$i] ?? '')); $gv = ($gv !== '' && ctype_alpha($gv)) ? substr($gv, 0, 2) : null;
            $pEur = (float)str_replace(',', '.', $preis[$i] ?? '0');
            $qv = in_array($quelle[$i] ?? '', ['herstellung','verpackung','manuell'], true) ? $quelle[$i] : 'manuell';
            $tid = (int)($ptausch[$i] ?? 0);
            if ($qv === 'verpackung' && $tid > 0 && ($nb = one("SELECT id, name, artikelnummer, volumen_ml, etikett_format FROM item WHERE id=? AND verpackungsart='beutel' AND gesperrt=0", [$tid]))) {
                $tm = (int) round((float)str_replace(',', '.', $mng[$i] ?? '1')) ?: 1;
                $tt = verpackung_zeile_teile(['rolle' => 'Verpackung'] + $nb);
                $pref = preg_match('/^([A-Z]{1,2}\)\s*)/', $b, $pm) ? $pm[1] : '';   // „A) " der Option behalten
                $b = $pref . $tt['bezeichnung']; $besch[$i] = $tt['beschreibung']; $art[$i] = (string)($nb['artikelnummer'] ?? '');
                $pEur = vk_fuer_kunde(verpackung_vk_bei_menge($tid, $tm), $kidT);
                $ek[$i] = (string) pack_ek_bei_menge($tid, $tm);
                $pvid[$i] = $tid;
                $tauschGruppe[$gv ?? ''] = $tid;
            }
            q("INSERT INTO angebot_position (angebot_id,sort,artikelnr,bezeichnung,beschreibung,menge,einheit,preis_cent,preis_e4,ek_cent,mwst_satz,quelle,gruppe,rezeptur_id,stueck,verpackung_id,kundenspezifisch,blanko_etikett) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
              [(int)$id, $sort++, trim($art[$i] ?? ''), $b, trim($besch[$i] ?? ''),
               (float)str_replace(',', '.', $mng[$i] ?? '0'), trim($einh[$i] ?? ''),
               (int) round($pEur * 100), (int) round($pEur * 10000),
               (int) round((float)str_replace(',', '.', $ek[$i] ?? '0') * 100),
               mwst_normalisieren((float)str_replace(',', '.', $mwst[$i] ?? '0')), $qv, $gv,
               (int)($prez[$i] ?? 0) ?: null, (int)($pstk[$i] ?? 0) ?: null, (int)($pvid[$i] ?? 0) ?: null,
               ($qv === 'verpackung' && !empty($pkspez[$i])) ? 1 : 0,
               ($qv === 'verpackung' && !empty($pblanko[$i])) ? 1 : 0]);
        }
        // Getauschter Beutel gilt auch für die Herstellungszeile derselben Option (daraus entstehen Produkt + Auftrag).
        foreach ($tauschGruppe as $tg => $tid)
            q("UPDATE angebot_position SET verpackung_id=? WHERE angebot_id=? AND quelle='herstellung' AND COALESCE(gruppe,'')=?", [$tid, (int)$id, (string)$tg]);
        } catch (Throwable $e) { db()->rollBack(); throw $e; }
        db()->commit();
        // Mehrere Mengen-Varianten in derselben Gruppe wieder auftrennen (je Variante ein Buchstabe).
        angebot_gruppen_normalisieren((int)$id);
        // Angebots-Staffel ist ein veralteter Cache, sobald die Positionen bearbeitet wurden (z. B. Menge
        // 4500 -> 10000): entfernen, damit Kundenportal-Übersicht UND Annahme die aktuellen Positionen
        // verwenden (wie das PDF). Nur wenn es jetzt Positionen gibt (reines Staffel-Angebot bleibt erhalten).
        angebot_staffel_cache_verwerfen((int)$id);
        // „Speichern & an Kunden senden" (Button unten bei den Positionen): direkt nach dem Speichern senden.
        if (!empty($_POST['und_senden'])) {
            $st  = (string) scalar("SELECT status FROM angebot WHERE id=?", [(int)$id]);
            $anz = (int) scalar("SELECT COUNT(*) FROM angebot_position WHERE angebot_id=?", [(int)$id]);
            if ($st === 'offen' && $anz > 0) {
                q("UPDATE angebot SET status='gesendet', preise_kunde=1 WHERE id=?", [(int)$id]);
                q("UPDATE angebot SET gueltig_bis=? WHERE id=? AND (gueltig_bis IS NULL OR gueltig_bis < CURDATE())", [angebot_gueltig_bis_default((int)$id), (int)$id]);
                angebot_produkte_sichern((int)$id);
                $kd = (int) scalar("SELECT kunde_id FROM angebot WHERE id=?", [(int)$id]);
                if ($kd) {
                    q("UPDATE portal_anfrage SET status='beantwortet' WHERE id=(SELECT anfrage_id FROM angebot WHERE id=?) AND status<>'beantwortet'", [(int)$id]);
                    log_aktivitaet('kunde', $kd, 'team', 'Angebot ' . scalar("SELECT nummer FROM angebot WHERE id=?", [(int)$id]) . ' gespeichert und an den Kunden gesendet.', 'angebot', 'angebot', (int)$id);
                }
                $f = ($kd && mail_bereit()) ? mail_kunde_angebot((int)$id) : '';
                header('Location: ?p=angebot&id=' . $id . '&gesendet=1' . ($f !== '' ? '&mailfehler=' . urlencode($f) : '')); exit;
            }
            if ($anz === 0) { header('Location: ?p=angebot&id=' . $id . '&sendfehler=leer'); exit; }
            // Status nicht mehr Entwurf -> nur gespeichert (kein erneutes Senden).
            header('Location: ?p=angebot&id=' . $id . '&gespeichert=1&sendfehler=status#positionen'); exit;
        }
        // Sicherheitsnetz: Wird ein bereits gesendetes Angebot nachbearbeitet, werden die Preise fuer den
        // Kunden automatisch GESPERRT. So sieht der Kunde nie einen Zwischenstand – erst nach erneuter Freigabe.
        $warnP = '';
        if ((int) scalar("SELECT preise_kunde FROM angebot WHERE id=?", [(int)$id]) === 1) {
            q("UPDATE angebot SET preise_kunde=0 WHERE id=?", [(int)$id]);
            $warnP = '&preisgesperrt=1';
        }
        header('Location: ?p=angebot&id=' . $id . '&gespeichert=1' . $editQ . $warnP . '#positionen'); exit;
    } elseif ($aktion === 'preise_freigeben' && !$neu) {
        // Preise ausdruecklich fuer den Kunden freigeben (Bestaetigung erfolgt im UI-Popup).
        q("UPDATE angebot SET preise_kunde=1 WHERE id=?", [(int)$id]);
        $kd = (int) scalar("SELECT kunde_id FROM angebot WHERE id=?", [(int)$id]);
        if ($kd) log_aktivitaet('kunde', $kd, 'team', 'Preise fuer Angebot ' . scalar("SELECT nummer FROM angebot WHERE id=?", [(int)$id]) . ' fuer den Kunden freigegeben.', 'angebot', 'angebot', (int)$id);
        // Kunde: Angebot nach Bearbeitung wieder freigegeben (nur wenn es beim Kunden liegt).
        if ($kd && mail_bereit() && (string) scalar("SELECT status FROM angebot WHERE id=?", [(int)$id]) === 'gesendet') nach_antwort(fn() => mail_kunde_angebot_aktualisiert((int)$id));
        header('Location: ?p=angebot&id=' . $id . '&preisfrei=1'); exit;
    } elseif ($aktion === 'preise_sperren' && !$neu) {
        // Preise wieder sperren – der Kunde sieht das Angebot dann nicht mehr.
        q("UPDATE angebot SET preise_kunde=0 WHERE id=?", [(int)$id]);
        $kd = (int) scalar("SELECT kunde_id FROM angebot WHERE id=?", [(int)$id]);
        if ($kd) log_aktivitaet('kunde', $kd, 'team', 'Preise fuer Angebot ' . scalar("SELECT nummer FROM angebot WHERE id=?", [(int)$id]) . ' fuer den Kunden gesperrt.', 'angebot', 'angebot', (int)$id);
        header('Location: ?p=angebot&id=' . $id . '&preisgesperrt=1'); exit;
    } elseif ($aktion === 'senden' && !$neu) {
        // Der einzige Weg, ein Angebot beim Kunden sichtbar zu machen. Leere Angebote gehen nicht raus.
        $st  = (string) scalar("SELECT status FROM angebot WHERE id=?", [(int)$id]);
        $anz = (int) scalar("SELECT COUNT(*) FROM angebot_position WHERE angebot_id=?", [(int)$id]);
        if ($st !== 'offen')  { header('Location: ?p=angebot&id=' . $id . '&sendfehler=status'); exit; }
        if ($anz === 0)       { header('Location: ?p=angebot&id=' . $id . '&sendfehler=leer'); exit; }
        // Senden gibt die Preise fuer den Kunden frei (er sieht das Angebot erst dann im Portal).
        q("UPDATE angebot SET status='gesendet', preise_kunde=1 WHERE id=?", [(int)$id]);
        // Gültigkeit zählt ab dem Tag, an dem das Angebot beim Kunden landet – ein alter Entwurf
        // wäre sonst schon abgelaufen. Ein selbst gesetztes Datum in der Zukunft bleibt stehen.
        q("UPDATE angebot SET gueltig_bis=? WHERE id=? AND (gueltig_bis IS NULL OR gueltig_bis < CURDATE())",
          [angebot_gueltig_bis_default((int)$id), (int)$id]);
        // Jetzt – und erst jetzt – gelten die angebotenen Konfigurationen als eigene Produkte
        // (Rezeptur x Menge + Verpackung), und der Kunde darf deren Preise im Portal sehen.
        angebot_produkte_sichern((int)$id);
        $kd = (int) scalar("SELECT kunde_id FROM angebot WHERE id=?", [(int)$id]);
        if ($kd) {
            q("UPDATE portal_anfrage SET status='beantwortet' WHERE id=(SELECT anfrage_id FROM angebot WHERE id=?) AND status<>'beantwortet'", [(int)$id]);
            log_aktivitaet('kunde', $kd, 'team', 'Angebot ' . scalar("SELECT nummer FROM angebot WHERE id=?", [(int)$id]) . ' an den Kunden gesendet.', 'angebot', 'angebot', (int)$id);
        }
        // Der Kunde bekommt den Portal-Link per Mail – wenn der Versand eingerichtet ist.
        $f = ($kd && mail_bereit()) ? mail_kunde_angebot((int)$id) : '';
        header('Location: ?p=angebot&id=' . $id . '&gesendet=1' . ($f !== '' ? '&mailfehler=' . urlencode($f) : '')); exit;
    } elseif ($aktion === 'team_annehmen' && !$neu) {
        // Annahme DURCH DAS TEAM im Auftrag des Kunden (Kunde hat per Mail/Telefon zugesagt), S-15: gleicher Weg wie die
        // Portal-Annahme (Positionen -> auftrag_aus_positionen, reine Staffel -> auftrag_aus_angebot). Wer + wie bestätigt hat,
        // ist Pflicht und wird als Freigabe gespeichert. Entsteht kein Auftrag, wird nichts gespeichert.
        $angT = one("SELECT * FROM angebot WHERE id=? AND status='gesendet'", [(int)$id]);
        $kanal = mb_substr(trim((string)($_POST['bestaetigt_durch'] ?? '')), 0, 150);
        if (!$angT || ($angT['kategorie'] ?? '') === 'dienstleistung' || !empty($angT['jahresvertrag'])) { header('Location: ?p=angebot&id=' . $id . '&teamannahme=status'); exit; }
        if ($kanal === '' || ($_POST['bestaetigt_ok'] ?? '') !== '1') { header('Location: ?p=angebot&id=' . $id . '&teamannahme=angaben'); exit; }
        $cu = function_exists('current_user') ? current_user() : null;
        $wer = 'Team ' . trim((string)($cu['name'] ?? '')) . ' – ' . $kanal;
        $hatPosT = (int) scalar("SELECT COUNT(*) FROM angebot_position WHERE angebot_id=? AND quelle='herstellung'", [(int)$id]) > 0;
        $neuA = null;
        if ($hatPosT) {
            $neuA = auftrag_aus_positionen((int)$id, preg_replace('/[^A-Z]/', '', strtoupper((string)($_POST['gruppe'] ?? ''))) ?: null);
        } else {
            $sidT = (int)($_POST['staffel'] ?? 0);
            if ($sidT > 0 && (int) scalar("SELECT COUNT(*) FROM angebot_staffel WHERE id=? AND angebot_id=?", [$sidT, (int)$id]) === 1) {
                q("UPDATE angebot_staffel SET bestaetigt=0 WHERE angebot_id=?", [(int)$id]);
                q("UPDATE angebot_staffel SET bestaetigt=1 WHERE id=?", [$sidT]);
                q("UPDATE angebot SET status='bestaetigt' WHERE id=?", [(int)$id]);
                $neuA = auftrag_aus_angebot((int)$id);
                if (!$neuA) { q("UPDATE angebot SET status='gesendet' WHERE id=?", [(int)$id]); q("UPDATE angebot_staffel SET bestaetigt=0 WHERE angebot_id=?", [(int)$id]); }
            }
        }
        if (!$neuA) { header('Location: ?p=angebot&id=' . $id . '&teamannahme=fehler'); exit; }
        q("UPDATE angebot SET freigabe_name=?, freigabe_am=UTC_TIMESTAMP() WHERE id=?", [mb_substr($wer, 0, 190), (int)$id]);
        if ($angT['kunde_id']) log_aktivitaet('kunde', (int)$angT['kunde_id'], 'team', 'Angebot ' . $angT['nummer'] . ' im Auftrag des Kunden angenommen (' . $kanal . ').', 'angebot', 'angebot', (int)$id);
        if (mail_bereit()) nach_antwort(fn() => mail_angebot_angenommen((int)$id, (int)$neuA));
        header('Location: ?p=auftrag&id=' . (int)$neuA . '&teamangenommen=1'); exit;
    } elseif ($aktion === 'zurueckziehen' && !$neu) {
        // Zurückziehen = zurück in den ENTWURF ('offen'). Beim Kunden verschwindet es damit sofort
        // (das Portal zeigt nur 'gesendet'), hier bleibt dasselbe Angebot bearbeitbar.
        $st = (string) scalar("SELECT status FROM angebot WHERE id=?", [(int)$id]);
        if ($st === 'gesendet') {
            q("UPDATE angebot SET status='offen', preise_kunde=0 WHERE id=?", [(int)$id]);
            $kd = (int) scalar("SELECT kunde_id FROM angebot WHERE id=?", [(int)$id]);
            if ($kd) log_aktivitaet('kunde', $kd, 'team', 'Angebot ' . scalar("SELECT nummer FROM angebot WHERE id=?", [(int)$id]) . ' zurückgezogen.', 'angebot', 'angebot', (int)$id);
            header('Location: ?p=angebot&id=' . $id . '&zurueckgezogen=1'); exit;
        }
        header('Location: ?p=angebot&id=' . $id . '&zzfehler=1'); exit;
    } elseif ($aktion === 'angebot_hard_loeschen' && !$neu) {
        // NUR dieses Angebot löschen (samt Staffeln/Positionen). Die verknüpfte Anfrage bleibt erhalten und
        // wird wieder auf „offen/in Prüfung" gesetzt, damit ein neues Angebot gebaut werden kann. Ein evtl.
        // verknüpfter Auftrag wird nur GELÖST, nicht gelöscht. Ausschließlich per exakter ID – kein pauschales DELETE.
        $aid = (int)$id;
        $anfrageId = (int) scalar("SELECT anfrage_id FROM angebot WHERE id=?", [$aid]);
        $kd = (int) scalar("SELECT kunde_id FROM angebot WHERE id=?", [$aid]);
        $nr = (string) scalar("SELECT nummer FROM angebot WHERE id=?", [$aid]);
        q("UPDATE auftrag SET angebot_id=NULL WHERE angebot_id=?", [$aid]);
        q("DELETE FROM angebot_staffel WHERE angebot_id=?", [$aid]);
        q("DELETE FROM angebot_position WHERE angebot_id=?", [$aid]);
        q("DELETE FROM angebot WHERE id=?", [$aid]);
        // Anfrage NICHT löschen – nur wieder öffnen (sofern nicht vom Kunden abgelehnt).
        if ($anfrageId > 0) q("UPDATE portal_anfrage SET status='neu' WHERE id=? AND status NOT IN ('abgelehnt')", [$anfrageId]);
        if ($kd) log_aktivitaet('kunde', $kd, 'team', 'Angebot ' . $nr . ' gelöscht – die Anfrage bleibt bestehen.', 'angebot');
        header('Location: ?p=angebote&geloescht=1'); exit;
    } elseif ($aktion === 'pos_reset' && !$neu) {
        q("DELETE FROM angebot_position WHERE angebot_id=?", [(int)$id]);
        header('Location: ?p=angebot&id=' . $id . '&zurueckgesetzt=1' . $editQ); exit;
    } elseif ($aktion === 'rezeptur_verknuepfen' && !$neu) {
        // Angebot ohne Rezeptur mit einer bestehenden Rezeptur verknüpfen (Override am Angebot).
        $rzid = (int)($_POST['rezeptur_id'] ?? 0);
        if ($rzid > 0 && scalar("SELECT id FROM rezeptur WHERE id=?", [$rzid])) {
            q("UPDATE angebot SET rezeptur_id=? WHERE id=?", [$rzid, (int)$id]);
            $pidA = (int) scalar("SELECT produkt_id FROM angebot WHERE id=?", [(int)$id]);
            if ($pidA && !(int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [$pidA])) q("UPDATE produkt SET rezeptur_id=? WHERE id=?", [$rzid, $pidA]);
            header('Location: ?p=angebot&id=' . $id . '&rezverk=1' . $editQ); exit;
        }
        header('Location: ?p=angebot&id=' . $id . '&rezverkfehler=1' . $editQ); exit;
    } elseif (in_array($aktion, ['add_rezeptur','add_rohstoff','add_dienstleistung'], true) && !$neu) {
        $aRow = one("SELECT kunde_id, marge_override FROM angebot WHERE id=?", [(int)$id]);
        $kid = (int)($aRow['kunde_id'] ?? 0) ?: null;
        $mo = ($aRow['marge_override'] ?? '') !== '' && $aRow['marge_override'] !== null ? (float)$aRow['marge_override'] : null;
        if ($aktion === 'add_rezeptur') {
            $rid = (int)($_POST['add_rezeptur_id'] ?? 0);
            $stk = (int)($_POST['add_stueck'] ?? 0) ?: 1;
            // Mehrere Bestellmengen auf einmal („1000, 2500, 5000"): je Menge eine eigene Gruppe. So sieht der
            // Kunde eine Staffel, und der Preis je Packung sinkt mit der Menge (Rohstoff-Staffeln greifen).
            $mengen = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,;]+/', (string)($_POST['add_menge'] ?? ''))), fn($m) => $m > 0)));
            sort($mengen);
            if (!$mengen) $mengen = [1];
            $verps = array_values(array_filter([(int)($_POST['add_verp_id'] ?? 0), (int)($_POST['add_deckel_id'] ?? 0), (int)($_POST['add_etikett_id'] ?? 0)]));
            if ($rid) foreach ($mengen as $menge) angebot_gruppe_anhaengen((int)$id, angebot_rezeptur_zeilen($rid, $stk, $verps, $menge, $mo, $kid));
            $_SESSION['ang_add'][(int)$id] = ['rezeptur_id'=>$rid, 'stueck'=>$stk, 'menge'=>implode(', ', $mengen),
                'verp_id'=>(int)($_POST['add_verp_id'] ?? 0), 'deckel_id'=>(int)($_POST['add_deckel_id'] ?? 0), 'etikett_id'=>(int)($_POST['add_etikett_id'] ?? 0)];
        } elseif ($aktion === 'add_rohstoff') {
            $iid = (int)($_POST['add_rohstoff_id'] ?? 0);
            $mng = (float)str_replace(',', '.', $_POST['add_menge'] ?? '0');
            if ($iid) angebot_gruppe_anhaengen((int)$id, angebot_rohstoff_zeile($iid, $mng, trim($_POST['add_einheit'] ?? ''), $kid));
        } else { // add_dienstleistung
            // Aus dem DL-Katalog gewählt? Dann über dl_position_add – das setzt dienstleistung_id (für den späteren
            // DL-Auftrag) und den kundenspezifischen Preis (dl_kundenpreis). Sonst Freitext-Zuschlag wie bisher.
            $dlid = (int)($_POST['add_dl_id'] ?? 0);
            if ($dlid > 0 && function_exists('dl_position_add')) {
                $mng = (float)str_replace(',', '.', $_POST['add_dl_menge'] ?? '1') ?: 1;
                dl_position_add((int)$id, $dlid, $mng);
                header('Location: ?p=angebot&id=' . $id . '&gespeichert=1' . $editQ . '#positionen'); exit;
            }
            $bez = trim($_POST['add_bez'] ?? '');
            if ($bez !== '') {
                $preis = (float)str_replace(',', '.', $_POST['add_preis'] ?? '0');
                $mng = (float)str_replace(',', '.', $_POST['add_menge'] ?? '1') ?: 1;
                $mwst = ($_POST['add_mwst'] ?? '') !== '' ? mwst_normalisieren((float)str_replace(',', '.', $_POST['add_mwst'])) : angebot_ust_satz($kid);
                angebot_gruppe_anhaengen((int)$id, [['artikelnr'=>'', 'bezeichnung'=>$bez, 'beschreibung'=>trim($_POST['add_besch'] ?? ''), 'menge'=>$mng, 'einheit'=>trim($_POST['add_einheit'] ?? ''), 'preis_cent'=>(int)round($preis*100), 'preis_e4'=>(int)round($preis*10000), 'ek_cent'=>0, 'mwst_satz'=>$mwst, 'quelle'=>'manuell']]);
            }
        }
        header('Location: ?p=angebot&id=' . $id . '&gespeichert=1' . $editQ . '#positionen'); exit;
    } elseif ($aktion === 'anfrage_uebernehmen' && !$neu) {
        // Der Kunde hat seine Anfrage (z. B. Menge) geaendert. Die neu angefragte Konfiguration aus der
        // verknuepften Portal-Anfrage als Positionen ins Angebot uebernehmen (ersetzt die bisherigen).
        $aRow  = one("SELECT kunde_id, marge_override, anfrage_id FROM angebot WHERE id=?", [(int)$id]);
        $kid   = (int)($aRow['kunde_id'] ?? 0) ?: null;
        $mo    = ($aRow['marge_override'] ?? '') !== '' && $aRow['marge_override'] !== null ? (float)$aRow['marge_override'] : null;
        $anfId = (int)($aRow['anfrage_id'] ?? 0);
        $pa    = $anfId ? one("SELECT * FROM portal_anfrage WHERE id=?", [$anfId]) : null;
        $rid   = $pa ? (int)($pa['rezeptur_id'] ?: scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [(int)$pa['produkt_id']])) : 0;
        if ($pa && $rid) {
            $wform = (string) scalar("SELECT darreichungsform FROM rezeptur WHERE id=?", [$rid]) ?: 'kapsel';
            $vtyp  = (string)($pa['verpackung_typ'] ?? '');
            $pos = all("SELECT stueck, fuellmenge_g, menge FROM portal_anfrage_pos WHERE anfrage_id=? ORDER BY sort, id", [$anfId]);
            if (!$pos) $pos = [['stueck'=>$pa['stueck'], 'fuellmenge_g'=>$pa['fuellmenge_g'], 'menge'=>$pa['menge']]];
            // Nur gueltige Staffelzeilen (Stueck UND Menge > 0) – je Zeile eine Gruppe (A, B, C …).
            $gueltig = [];
            foreach ($pos as $z) {
                $stk = anfrage_groesse($z['stueck'] ?? null, $z['fuellmenge_g'] ?? null, $wform); $menge = (int)$z['menge'];
                if ($stk > 0 && $menge > 0) $gueltig[] = ['stueck'=>$stk, 'menge'=>$menge];
            }
            if ($gueltig) {
                // Bereits vergebene Preise je Konfiguration merken (Herstellung/Verpackung nach Stueck + Artikel +
                // Behaelter). Aendert der Kunde nur die MENGE, bleibt der Preis je Packung erhalten – der Preis wird
                // NICHT neu berechnet. Nur wo es keine passende alte Position gibt (z. B. neue Staffel/anderes Stueck),
                // greift die automatische Kalkulation.
                $altPreis = [];
                $pkey = fn($quelle, $stueck, $art, $verp) => ($quelle ?: 'manuell') . '|' . (int)$stueck . '|' . trim((string)$art) . '|' . (int)$verp;
                foreach (all("SELECT quelle, stueck, artikelnr, verpackung_id, preis_cent, preis_e4, ek_cent, mwst_satz FROM angebot_position WHERE angebot_id=?", [(int)$id]) as $o) {
                    $key = $pkey($o['quelle'], $o['stueck'], $o['artikelnr'], $o['verpackung_id'] ?? 0);
                    if (!isset($altPreis[$key])) $altPreis[$key] = $o;   // erste Uebereinstimmung je Konfiguration
                }
                db()->beginTransaction();
                try {
                    // Positionen direkt ersetzen (NICHT via angebot_gruppe_anhaengen, das sonst die leere
                    // Automatik als Gruppe A einfrieren wuerde). Gruppenbuchstaben je Staffelzeile.
                    q("DELETE FROM angebot_position WHERE angebot_id=?", [(int)$id]);
                    $sort = 0; $gi = 0;
                    foreach ($gueltig as $z) {
                        $verps = [];
                        foreach (passende_behaelter_fuer($rid, $wform, $z['stueck']) as $cand)
                            if (verpackung_passt_zu_typ((int)$cand, $vtyp ?: null)) { $verps[] = (int)$cand; break; }
                        $letter = chr(65 + $gi++);
                        foreach (angebot_rezeptur_zeilen($rid, $z['stueck'], $verps, $z['menge'], $mo, $kid) as $p) {
                            // Vorhandenen Preis dieser Konfiguration wiederverwenden, statt neu zu rechnen.
                            $key = $pkey($p['quelle'] ?? 'manuell', $p['stueck'] ?? 0, $p['artikelnr'] ?? '', $p['verpackung_id'] ?? 0);
                            if (isset($altPreis[$key])) {
                                $p['preis_cent'] = (int)$altPreis[$key]['preis_cent'];
                                $p['preis_e4']   = $altPreis[$key]['preis_e4'] ?? null;
                                $p['ek_cent']    = (int)$altPreis[$key]['ek_cent'];
                                $p['mwst_satz']  = (float)$altPreis[$key]['mwst_satz'];
                            }
                            q("INSERT INTO angebot_position (angebot_id,sort,artikelnr,bezeichnung,beschreibung,menge,einheit,preis_cent,preis_e4,ek_cent,mwst_satz,quelle,gruppe,rezeptur_id,stueck,verpackung_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                              [(int)$id, $sort++, $p['artikelnr'] ?? '', $p['bezeichnung'], $p['beschreibung'] ?? '', (float)$p['menge'], $p['einheit'] ?? '', (int)$p['preis_cent'], (($p['preis_e4'] ?? null) !== null ? (int)$p['preis_e4'] : null), (int)($p['ek_cent'] ?? 0), (float)($p['mwst_satz'] ?? 0), $p['quelle'] ?? 'manuell', $letter, $p['rezeptur_id'] ?? null, $p['stueck'] ?? null, $p['verpackung_id'] ?? null]);
                        }
                    }
                    // Positions-Bezeichnungen (A) B) … / ohne Prefix bei nur einer Gruppe) konsistent setzen.
                    $allp = all("SELECT id,bezeichnung,gruppe FROM angebot_position WHERE angebot_id=? ORDER BY sort,id", [(int)$id]);
                    foreach (angebot_positionen_prefix($allp) as $p) q("UPDATE angebot_position SET bezeichnung=? WHERE id=?", [$p['bezeichnung'], (int)$p['id']]);
                } catch (Throwable $e) { db()->rollBack(); throw $e; }
                db()->commit();
                angebot_staffel_cache_verwerfen((int)$id);   // veraltete Staffel entfernen -> Portal nutzt die neuen Positionen
                q("UPDATE angebot SET preise_kunde=0 WHERE id=?", [(int)$id]);   // Zwischenstand nicht sichtbar bis zur Freigabe
                header('Location: ?p=angebot&id=' . $id . '&uebernommen=1#positionen'); exit;
            }
        }
        header('Location: ?p=angebot&id=' . $id . '&uebernahmefehler=1#positionen'); exit;
    }
}

$a = $neu ? ['status'=>'offen'] : one("SELECT * FROM angebot WHERE id=?", [(int)$id]);
// Vorbefuellung per Link (z. B. aus Fastaction): neues Angebot mit Kunde + Notiz vorbelegen.
if ($neu) {
    if (($_GET['kunde_id'] ?? '') !== '') $a['kunde_id'] = (int)$_GET['kunde_id'];
    if (($_GET['fa_notiz'] ?? '') !== '') $a['notiz'] = mb_substr((string)$_GET['fa_notiz'], 0, 500);
}
if (!$a) { $neu = true; $a = ['status'=>'offen']; }
$v = fn($k) => h((string)($a[$k] ?? ''));

$kunden   = all("SELECT id, firma, portal_token FROM kunden WHERE COALESCE(gesperrt,0)=0 OR id=? ORDER BY firma", [(int)($a['kunde_id'] ?? 0)]);   // Block C: gesperrte nicht auswählbar (der schon gewählte bleibt sichtbar)
$produkte = all("SELECT id, name FROM produkt WHERE COALESCE(status,'')<>'inaktiv' OR id=? ORDER BY name", [(int)($a['produkt_id'] ?? 0)]);   // Block C: inaktive Produkte nicht auswählbar
// Kataloge für „Position hinzufügen" (Typ zuerst)
$rezepturKatalog = all("SELECT id, name, darreichungsform FROM rezeptur WHERE status IN ('entwurf','vorschlag','eingefroren','freigegeben') ORDER BY name");
$rohstoffKatalog = all("SELECT id, name, artikelnummer, preis_bezug, beschaffenheit, dev FROM item WHERE kategorie='rohstoff' AND gesperrt=0 ORDER BY name");
$verpPrim   = all("SELECT id, name FROM item WHERE kategorie='verpackung' AND COALESCE(verpackung_rolle,'primaer')='primaer' AND gesperrt=0 ORDER BY name");
$verpDeckel = all("SELECT id, name FROM item WHERE kategorie='verpackung' AND verpackung_rolle='verschluss' AND gesperrt=0 ORDER BY name");
$verpEtik   = all("SELECT id, name FROM item WHERE kategorie='verpackung' AND verpackung_rolle='etikett' AND gesperrt=0 AND produkt_id IS NULL ORDER BY name");
// Dienstleistungs-Angebot: dieselbe reguläre Maske, nur mit DL-Katalog beim Position-Hinzufügen und dem
// Kundenwunsch aus der DL-Anfrage. So baut man EIN reguläres Angebot mit Dienstleistung + Produkten (Gläser, Etikett).
$istDL    = (string)($a['kategorie'] ?? 'produkt') === 'dienstleistung';
$dlKatalog = function_exists('dienstleistungen_alle') ? dienstleistungen_alle(true) : [];
$dlWunsch = (!$neu && !empty($a['anfrage_id']))
    ? one("SELECT nummer, betreff, notiz, dienstleistung_typ, zielpreis, wunsch_menge, wunsch_einheit FROM portal_anfrage WHERE id=?", [(int)$a['anfrage_id']])
    : null;

$pid  = (int)($a['produkt_id'] ?? 0);
$kid  = (int)($a['kunde_id'] ?? 0);
$form = $pid ? (string) scalar("SELECT r.darreichungsform FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id=?", [$pid]) : 'kapsel';
$defMarge = max(marge_typ_prozent($form ?: 'kapsel'), marge_min_prozent());
$defPz    = (float) meta_get('produktionszeit_wochen', 7);

render_header($istDL ? 'dienstleistungen' : 'angebote', $neu ? 'Neues Angebot' : ($a['nummer'] ?? 'Angebot'));
// Senden und Zurückziehen sind die einzigen Wege, die Sichtbarkeit beim Kunden zu ändern.
$st          = (string)($a['status'] ?? 'offen');
$preiseKunde = (int)($a['preise_kunde'] ?? 0) === 1;   // sieht der Kunde die Preise?
$kannSenden  = !$neu && $st === 'offen';
$kannZurueck = !$neu && $st === 'gesendet';
// Preise nachtraeglich freigeben/sperren – nur sinnvoll, wenn das Angebot schon raus ist (nicht Entwurf).
$kannPreisFreigeben = !$neu && $st !== 'offen' && !$preiseKunde;
$kannPreisSperren   = !$neu && $st !== 'offen' && $preiseKunde;
// Nach dem Senden zeigt die Seite eine ruhige Übersicht; „Bearbeiten" (?edit=1) schaltet in den Editor.
// Entwürfe (offen) und neue Angebote öffnen direkt im Editor.
$editMode = $neu || $st === 'offen' || isset($_GET['edit']);
$kopfBtn = bx_btn('Zurück zur Liste', $istDL ? '?p=dl_angebote' : '?p=angebote', 'ghost');
if (!$neu) $kopfBtn = '<a class="btn btn-ghost" style="margin-right:8px" target="_blank" title="Angebot als PDF ansehen – genau das, was der Kunde bekommt" href="?p=angebot_pdf&id=' . (int)$id . '">&#8681; PDF</a>' . $kopfBtn;
if (!$neu && (int)($a['jahresvertrag'] ?? 0) === 1) $kopfBtn = '<a class="btn btn-ghost" style="margin-right:8px" target="_blank" title="Jahresabnahmevertrag als PDF" href="?p=vertrag_pdf&id=' . (int)$id . '">&#8681; Vertrag</a>' . $kopfBtn;
// Angebot löschen: NUR das Angebot – die Anfrage bleibt bestehen und ist danach wieder offen für ein neues Angebot.
if (!$neu) $kopfBtn = '<form method="post" style="display:inline;margin-right:8px" onsubmit="return confirm(\'Dieses Angebot löschen? Die Anfrage bleibt bestehen und ist danach wieder offen für ein neues Angebot. Ein verknüpfter Auftrag wird nur gelöst.\');">'
    . '<input type="hidden" name="aktion" value="angebot_hard_loeschen">'
    . '<button class="btn btn-danger" type="submit" title="Angebot löschen (Anfrage bleibt bestehen)">Angebot löschen</button></form>' . $kopfBtn;
if ($kannZurueck) $kopfBtn = '<form method="post" style="display:inline;margin-right:8px" onsubmit="return confirm(\'Angebot zurückziehen? Es verschwindet beim Kunden und ist hier wieder bearbeitbar.\');">'
    . '<input type="hidden" name="aktion" value="zurueckziehen">'
    . '<button class="btn btn-ghost" type="submit">Zurückziehen</button></form>' . $kopfBtn;
if ($kannPreisSperren) $kopfBtn = '<form method="post" style="display:inline;margin-right:8px" onsubmit="return confirm(\'Preise für den Kunden sperren? Das Angebot verschwindet dann aus seinem Portal, bis du die Preise wieder freigibst.\');">'
    . '<input type="hidden" name="aktion" value="preise_sperren">'
    . '<button class="btn btn-ghost" type="submit">Preise sperren</button></form>' . $kopfBtn;
// Eigener Bestätigungsdialog (data-confirm, wie „An Kunden senden“) statt Browser-confirm() – Nico 12.11.2026: Knopf drehte minutenlang.
if ($kannPreisFreigeben) $kopfBtn = '<form method="post" style="display:inline;margin-right:8px">'
    . '<input type="hidden" name="aktion" value="preise_freigeben">'
    . '<button class="btn btn-primary" type="submit" data-confirm="Der Kunde sieht damit das geänderte Angebot inkl. Preise im Portal und bekommt eine Mail. Jetzt freigeben?" data-confirm-ok="Ja, freigeben">Preise freigeben</button></form>' . $kopfBtn;
if ($kannSenden) $kopfBtn = '<form method="post" style="display:inline;margin-right:8px">'
    . '<input type="hidden" name="aktion" value="senden">'
    . '<button class="btn btn-primary" type="submit" data-confirm="Angebot jetzt an den Kunden senden? Der Kunde wird damit das Angebot inkl. Preise im Portal sehen.">An Kunden senden</button></form>' . $kopfBtn;
// Übersicht <-> Editor: aus der Übersicht per „Bearbeiten" in den Editor, aus dem Editor zurück zur Übersicht.
if (!$neu && !$editMode) $kopfBtn = '<a class="btn btn-primary" style="margin-right:8px" href="?p=angebot&id=' . (int)$id . '&edit=1">Bearbeiten</a>' . $kopfBtn;
elseif (!$neu && $st !== 'offen') $kopfBtn = '<a class="btn btn-ghost" style="margin-right:8px" href="?p=angebot&id=' . (int)$id . '">Zur Übersicht</a>' . $kopfBtn;
// Verknüpfte Auftragsbestätigung(en): direkter Sprung Angebot -> Auftrag, falls aus diesem Angebot schon ein Auftrag
// entstanden ist. Steht ganz links, weil das bei einem bestätigten Angebot der nützlichste nächste Schritt ist.
if (!$neu) {
    $angAuftraege = all("SELECT id, nummer FROM auftrag WHERE angebot_id=? ORDER BY id", [(int)$id]);
    $auftBtns = '';
    foreach ($angAuftraege as $au)
        $auftBtns .= '<a class="btn btn-primary" style="margin-right:8px" href="?p=auftrag&id=' . (int)$au['id'] . '" title="Zur Auftragsbestätigung wechseln">Zum Auftrag ' . h((string)($au['nummer'] ?? '')) . ' &#8594;</a>';
    if ($auftBtns !== '') $kopfBtn = $auftBtns . $kopfBtn;
}
$stLabel = match ($st) { 'gesendet'=>'gesendet','bestaetigt'=>'bestätigt','abgelehnt'=>'abgelehnt','offen'=>'Entwurf', default=>$st };
bx_head($neu ? 'Neues Angebot' : $v('nummer'),
        $neu ? 'Positionen' : ($editMode ? 'Angebot bearbeiten' : 'Übersicht · ' . $stLabel),
        $kopfBtn);
if (!$neu && !empty($a['angelegt'])) echo '<div class="muted" style="font-size:12px;margin:-6px 0 10px">Erstellt am ' . h(fmt_zeit($a['angelegt'], 'd.m.Y H:i')) . (!empty($a['aktualisiert']) && $a['aktualisiert'] !== $a['angelegt'] ? ' · zuletzt geändert ' . h(fmt_zeit($a['aktualisiert'], 'd.m.Y H:i')) : '') . ' Uhr</div>';
if (isset($_GET['angefragt']))     echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . (int)$_GET['angefragt'] . ' Preisanfrage(n) verschickt' . (isset($_GET['gemailt']) && (int)$_GET['gemailt'] > 0 ? ', davon ' . (int)$_GET['gemailt'] . ' per E-Mail' : '') . '. Sobald ein Lieferant antwortet, steht der Preis hier.</div>';
if (isset($_GET['gespeichert']))   echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
if (isset($_GET['teamannahme'])) { $tm = (string)$_GET['teamannahme'];
    echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">'
       . ($tm === 'angaben' ? 'Bitte angeben, wer wie zugesagt hat, und die Zusage bestätigen.'
          : ($tm === 'status' ? 'Nur ein gesendetes Produkt-Angebot kann für den Kunden angenommen werden.'
          : 'Es konnte kein Auftrag angelegt werden – zur gewählten Option fehlt die Konfiguration (Produkt/Rezeptur, Menge je Packung).')) . '</div>'; }
// Team-Annahme im Auftrag des Kunden (S-15): z. B. Zusage per Mail/Telefon oder Handelsware ohne Portal-Annahme.
if (!$neu && $st === 'gesendet' && !$istDL && empty($a['jahresvertrag'])) {
    $tGruppen = array_column(all("SELECT DISTINCT TRIM(gruppe) AS g FROM angebot_position WHERE angebot_id=? AND quelle='herstellung' AND TRIM(COALESCE(gruppe,''))<>'' ORDER BY g", [(int)$id]), 'g');
    $tHatPos  = (int) scalar("SELECT COUNT(*) FROM angebot_position WHERE angebot_id=? AND quelle='herstellung'", [(int)$id]) > 0;
    $tStaffel = $tHatPos ? [] : all("SELECT id, menge, vk_stueck FROM angebot_staffel WHERE angebot_id=? ORDER BY sort, id", [(int)$id]);
    if ($tHatPos || $tStaffel) {
        echo '<details class="bx-panel" style="padding:12px 16px;margin-bottom:14px"><summary style="cursor:pointer">Für den Kunden annehmen <span class="muted" style="font-size:12px">– Zusage kam per Mail/Telefon</span></summary>';
        echo '<form method="post" style="margin-top:14px" onsubmit="return confirm(\'Angebot jetzt verbindlich für den Kunden annehmen? Es entstehen Auftrag und Rechnung.\');">';
        echo '<input type="hidden" name="aktion" value="team_annehmen">';
        echo '<div class="bx-row" style="gap:18px;align-items:flex-end;flex-wrap:wrap">';
        if (count($tGruppen) > 1) {
            echo '<label class="bx-field" style="min-width:160px"><span>Option</span><select name="gruppe">';
            foreach ($tGruppen as $g) echo '<option value="' . h($g) . '">Option ' . h($g) . '</option>';
            echo '</select></label>';
        } elseif (count($tGruppen) === 1) {
            echo '<input type="hidden" name="gruppe" value="' . h($tGruppen[0]) . '">';
        }
        if ($tStaffel) {
            echo '<label class="bx-field" style="min-width:220px"><span>Menge</span><select name="staffel">';
            foreach ($tStaffel as $s) echo '<option value="' . (int)$s['id'] . '">' . number_format((float)$s['menge'], 0, ',', '.') . ' Pkg · ' . number_format((float)$s['vk_stueck'], 2, ',', '.') . ' € je Pkg</option>';
            echo '</select></label>';
        }
        echo '<label class="bx-field" style="min-width:320px;flex:1"><span>Zugesagt durch (Name, Kanal, Datum)</span><input type="text" name="bestaetigt_durch" maxlength="150" required placeholder="z. B. Frau Muster per Mail vom 02.11."></label>';
        echo '</div>';
        echo '<label style="display:flex;gap:8px;align-items:center;margin-top:12px;font-size:13px"><input type="checkbox" name="bestaetigt_ok" value="1" required> Der Kunde hat verbindlich zugesagt.</label>';
        echo '<div style="margin-top:16px"><button class="btn btn-primary" type="submit">Verbindlich annehmen</button></div>';
        echo '</form></details>';
    }
}
if (isset($_GET['importok']))      echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . ((string)$_GET['importok'] === 'zugeordnet' ? 'Alt-Angebot zugeordnet – Rezeptur/Glas/Preis übernommen und PDF angehängt. Bitte Positionen/Preise prüfen.' : 'Neues Angebot aus dem Import angelegt – Positionen/Preise prüfen und ggf. senden.') . '</div>';
if (isset($_GET['nachbestellung'])) { $nbq = (string)$_GET['nachbestellung'];
  echo '<div class="bx-panel badge-ok" style="padding:12px 16px"><strong>Nachbestell-Angebot als Entwurf erstellt</strong> – '
     . ($nbq === 'klon' ? 'aus dem letzten Angebot des Kunden geklont (gleiche Produkte/Preise).' : 'je Produkt eine Position mit <strong>aktuellen Preisen</strong> aus der Preismatrix.')
     . ' Jetzt <strong>Mengen/Preise prüfen</strong> und senden.</div>'; }
if (isset($_GET['uebernommen']))   echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Angefragte Konfiguration übernommen – die Positionen wurden neu aufgebaut. Preise prüfen, dann freigeben/senden.</div>';
if (isset($_GET['uebernahmefehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Übernahme nicht möglich – zur verknüpften Anfrage fehlt eine Rezeptur/Konfiguration.</div>';
// Rezeptur-Verknüpfung (Referenz) – für ALLE Angebote sichtbar (auch bestätigte/Nur-Ansicht), daher hier
// vor der Übersicht/Editor-Verzweigung. Override am Angebot ODER Rezeptur des Kopf-Produkts ODER erste Positions-Rezeptur.
if (!$neu):
    $angRezId = (int)($a['rezeptur_id'] ?? 0);
    if (!$angRezId && $pid) $angRezId = (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [$pid]);
    if (!$angRezId) $angRezId = (int) scalar("SELECT rezeptur_id FROM angebot_position WHERE angebot_id=? AND COALESCE(rezeptur_id,0)>0 ORDER BY id LIMIT 1", [(int)$id]);
    $angRez = $angRezId ? one("SELECT nummer, name FROM rezeptur WHERE id=?", [$angRezId]) : null;
?>
<div class="bx-panel" style="padding:10px 14px;margin-bottom:14px;display:flex;flex-wrap:wrap;gap:10px;align-items:center">
  <span class="k muted">Rezeptur</span>
  <?php if ($angRez): ?>
    <a href="?p=rezeptur_detail&id=<?= (int)$angRezId ?>"><?= h($angRez['nummer']) ?></a><?= $angRez['name'] ? ' · ' . h($angRez['name']) : '' ?>
  <?php elseif (has_role('admin')): ?>
    <?php if (isset($_GET['rezverk'])): ?><span class="badge-ok" style="padding:3px 8px;border-radius:6px">Rezeptur verknüpft.</span><?php endif; ?>
    <form method="post" class="bx-row" style="gap:6px;align-items:center;margin:0;flex-wrap:wrap">
      <input type="hidden" name="aktion" value="rezeptur_verknuepfen">
      <select name="rezeptur_id" class="rscombo" style="min-width:240px" required>
        <option value="">– Rezeptur wählen –</option>
        <?php foreach (all("SELECT id, nummer, name FROM rezeptur WHERE COALESCE(status,'')<>'inaktiv' ORDER BY nummer DESC") as $rz): ?>
          <option value="<?= (int)$rz['id'] ?>"><?= h($rz['nummer']) ?><?= $rz['name'] ? ' · ' . h($rz['name']) : '' ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-ghost btn-sm" type="submit">Rezeptur verknüpfen</button>
    </form>
    <span class="muted" style="font-size:12px">Für Angebote ohne Rezeptur (z. B. Freitext/Import).</span>
  <?php else: ?>–<?php endif; ?>
</div>
<?php endif; ?>
<?php
if (isset($_GET['gesendet']))      echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Angebot an den Kunden gesendet – er sieht es jetzt im Portal (inkl. Preise).</div>';
if (isset($_GET['preisfrei']))     echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Preise freigegeben – der Kunde sieht das Angebot jetzt im Portal.</div>';
if (isset($_GET['preisgesperrt'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Preise sind für den Kunden <strong>gesperrt</strong> – das Angebot ist in seinem Portal nicht sichtbar. Nach der Prüfung mit „Preise freigeben" wieder sichtbar machen.</div>';
if (isset($_GET['mailfehler']))    echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">E-Mail an den Kunden nicht verschickt: ' . h((string)$_GET['mailfehler']) . '</div>';
if (isset($_GET['zurueckgezogen'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Angebot zurückgezogen – beim Kunden verschwunden, hier wieder als Entwurf bearbeitbar.</div>';
if (isset($_GET['zzfehler']))      echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Zurückziehen nicht mehr möglich – das Angebot ist bereits bestätigt oder abgelehnt.</div>';
if (($_GET['sendfehler'] ?? '') === 'leer')   echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Noch keine Positionen – ein leeres Angebot wird nicht gesendet. Füge unten mindestens eine Position hinzu.</div>';
if (($_GET['sendfehler'] ?? '') === 'status') echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Das Angebot ist nicht mehr im Entwurf – es wurde bereits gesendet oder beantwortet.</div>';
if (isset($_GET['zurueckgesetzt'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Positionen auf die automatische Berechnung zurückgesetzt.</div>';
if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b">' . h($fehler) . '</div>';
if (isset($_GET['kiok'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . (int)$_GET['kiok'] . ' Positionen aus dem PDF übernommen (Herstellung, Verpackung, Etikett) – bitte prüfen.</div>';
if (isset($_GET['kifehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['kifehler']) . '</div>';

// Kundenwunsch aus der DL-Anfrage (wie in der alten DL-Maske) – damit man beim Angebot sieht, was der Kunde wollte.
if ($istDL && $dlWunsch && (trim((string)($dlWunsch['betreff'] ?? '')) !== '' || trim((string)($dlWunsch['notiz'] ?? '')) !== '' || ($dlWunsch['zielpreis'] ?? null) !== null || ($dlWunsch['wunsch_menge'] ?? null) !== null)):
?>
<div class="bx-panel" style="border-color:var(--gruen);background:var(--panel-2)">
  <h2 style="margin-top:0;font-size:16px">Wunsch des Kunden <span class="muted" style="font-weight:normal;font-size:13px">(Anfrage <?= h((string)($dlWunsch['nummer'] ?? '')) ?>)</span></h2>
  <div class="bx-tablewrap"><table class="bx-table"><tbody>
    <?php if (trim((string)($dlWunsch['dienstleistung_typ'] ?? '')) !== ''): ?><tr><td style="width:200px" class="muted">Art</td><td><?= h((string)$dlWunsch['dienstleistung_typ']) ?></td></tr><?php endif; ?>
    <?php if (trim((string)($dlWunsch['betreff'] ?? '')) !== ''): ?><tr><td class="muted">Betreff</td><td><?= h((string)$dlWunsch['betreff']) ?></td></tr><?php endif; ?>
    <?php if (($dlWunsch['wunsch_menge'] ?? null) !== null && (float)$dlWunsch['wunsch_menge'] > 0): ?><tr><td class="muted">Gewünschte Menge</td><td><?= h(rtrim(rtrim(number_format((float)$dlWunsch['wunsch_menge'],3,',','.'),'0'),',')) ?> <?= h((string)($dlWunsch['wunsch_einheit'] ?? '')) ?></td></tr><?php endif; ?>
    <?php if (($dlWunsch['zielpreis'] ?? null) !== null && (float)$dlWunsch['zielpreis'] > 0): ?><tr><td class="muted">Zielpreis</td><td><?= number_format((float)$dlWunsch['zielpreis'],2,',','.') ?> €</td></tr><?php endif; ?>
    <?php if (trim((string)($dlWunsch['notiz'] ?? '')) !== ''): ?><tr><td class="muted">Notiz</td><td style="white-space:pre-line"><?= h((string)$dlWunsch['notiz']) ?></td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<?php endif;

// ===================== ÜBERSICHT (nach dem Senden, wie v3) =====================
// Ruhige, belegartige Ansicht: Kopfdaten (Kunde) · Positionen · Summen. „Bearbeiten" oben schaltet in den Editor.
if (!$neu && !$editMode) {
    $eur = fn($c) => number_format($c / 100, 2, ',', '.') . ' €';
    $posU = angebot_positionen((int)$id);
    $nettoU = 0; $ustGrp = [];
    foreach ($posU as $pp) {
        $g = angpos_netto_cent($pp);
        $nettoU += $g;
        $s = (string) mwst_normalisieren((float)$pp['mwst_satz']);
        $ustGrp[$s] = ($ustGrp[$s] ?? 0) + $g;
    }
    $ustSum = 0; foreach ($ustGrp as $s => $base) $ustSum += (int) round($base * ((float)$s) / 100);
    $bruttoU = $nettoU + $ustSum;
    $kd  = $kid ? one("SELECT * FROM kunden WHERE id=?", [$kid]) : null;
    $paNr = scalar("SELECT pa.nummer FROM produktionsauftrag pa JOIN auftrag au ON au.id=pa.auftrag_id WHERE au.angebot_id=? ORDER BY pa.id DESC LIMIT 1", [(int)$id]);
    // Echte (eingefrorene) Positionen -> Beleg-Ansicht mit Summe. Reines Staffelangebot -> die Staffeln, aus denen der Kunde wählt.
    $hatPos = angebot_hat_positionen((int)$id);
    ?>
    <div class="bx-panel">
      <h2 style="margin-top:0">Kopfdaten</h2>
      <?php if ($kd): $strasse = trim(($kd['strasse'] ?? '') . ' ' . ($kd['hausnummer'] ?? '')); $ort = trim(($kd['plz'] ?? '') . ' ' . ($kd['ort'] ?? '')); ?>
      <div style="line-height:1.7">
        <strong style="font-weight:600"><?= h($kd['firma'] ?? '') ?></strong>
        <?php if ($strasse !== ''): ?><br><?= h($strasse) ?><?php endif; ?>
        <?php if ($ort !== ''): ?><br><?= h($ort) ?><?php endif; ?>
        <?php if (($kd['kundennummer'] ?? '') !== ''): ?><br><span class="muted">Kunden-Nr.:</span> <?= h((string)$kd['kundennummer']) ?><?php endif; ?>
        <?php if ($paNr): ?><br><span class="muted">Produktionsauftrag:</span> <?= h((string)$paNr) ?><?php endif; ?>
        <?php if (($a['gueltig_bis'] ?? null)): ?><br><span class="muted">Gültig bis:</span> <?= h(date('d.m.Y', strtotime((string)$a['gueltig_bis']))) ?><?= angebot_abgelaufen($a) ? ' ' . bx_badge('abgelaufen', 'err') : '' ?><?php endif; ?>
      </div>
      <?php // Preis-Gültigkeit (11.11.2026): gilt das Angebot länger als der kürzeste EK-Preis darin? Nur Hinweis, keine Sperre.
        $ekBis = angebot_ek_gueltig_bis((int)$id);
        if (isset($_GET['gueltigok'])) echo '<div class="badge-ok" style="padding:6px 10px;border-radius:8px;margin-top:10px;display:inline-block">Gültigkeit gespeichert.</div>';
        if ($ekBis && !empty($a['gueltig_bis']) && (string)$a['gueltig_bis'] > $ekBis['bis'] && !in_array((string)$a['status'], ['bestaetigt', 'abgelehnt'], true)): ?>
      <div class="bx-panel" style="margin:10px 0 0;padding:10px 14px;border-left:3px solid var(--warn)">Angebot gilt länger als der <?= h($ekBis['name']) ?>-Preis (bis <?= h(date('d.m.Y', strtotime($ekBis['bis']))) ?>).
        <form method="post" style="display:inline;margin:0 0 0 8px"><input type="hidden" name="aktion" value="gueltig_ek_setzen"><button class="btn btn-ghost btn-sm" type="submit">Gültigkeit auf <?= h(date('d.m.Y', strtotime($ekBis['bis']))) ?> setzen</button></form></div>
      <?php endif; ?>
      <?php if (angebot_abgelaufen($a)): ?>
      <div class="bx-panel" style="margin:10px 0 0;padding:10px 14px;border-left:3px solid #8f231b">Das Angebot ist abgelaufen. Der Kunde kann es im Portal nicht mehr annehmen, nur eine Aktualisierung anfragen.
        <form method="post" style="display:inline;margin:0 0 0 8px"><input type="hidden" name="aktion" value="angebot_verlaengern"><button class="btn btn-primary btn-sm" type="submit">Verlängern bis <?= h(date('d.m.Y', strtotime(angebot_gueltig_bis_default((int)$id)))) ?></button></form>
        <span class="muted" style="font-size:12px;margin-left:6px">Mit neuen Preisen: Positionen unten neu berechnen und erneut senden.</span></div>
      <?php endif; ?>
      <?php else: ?><div class="muted">Kein Kunde in den Kopfdaten hinterlegt.</div><?php endif; ?>
      <?php if (($a['notiz'] ?? '') !== ''): ?><div style="margin-top:12px;white-space:pre-line"><span class="muted">Notiz:</span> <?= h((string)$a['notiz']) ?></div><?php endif; ?>
    </div>

    <?php if ($hatPos): ?>
    <div class="bx-panel">
      <h2 style="margin-top:0">Positionen</h2>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th style="width:34px">#</th><th style="width:90px">Art.-Nr.</th><th>Bezeichnung</th><th class="bx-num">Menge</th><th>Einheit</th><th class="bx-num">Preis/Einh</th><th class="bx-num">Gesamt</th></tr></thead>
        <tbody>
        <?php if (!$posU): ?><tr><td colspan="7" class="muted">Noch keine Positionen.</td></tr><?php endif; ?>
        <?php // Ohne eigene Artikelnummer (Produkt-SKU entsteht erst bei Auftragsannahme) die Rezepturnummer als
              // Referenz zeigen – so steht nie nur „–" an einer Herstellungsposition.
              $rezNrMap = [];
              foreach ($posU as $pp2) { $rid2 = (int)($pp2['rezeptur_id'] ?? 0);
                  if ($rid2 && !isset($rezNrMap[$rid2])) $rezNrMap[$rid2] = (string) scalar("SELECT nummer FROM rezeptur WHERE id=?", [$rid2]); } ?>
        <?php foreach ($posU as $i => $pp): $g = angpos_netto_cent($pp);
              $artnr = $pp['artikelnr'] !== '' ? (string)$pp['artikelnr'] : ($rezNrMap[(int)($pp['rezeptur_id'] ?? 0)] ?? ''); ?>
          <tr>
            <td class="muted"><?= $i + 1 ?></td>
            <td><?= $artnr !== '' ? h($artnr) . ($pp['artikelnr'] === '' ? ' <span class="muted" style="font-size:11px" title="Rezepturnummer – die Produkt-Artikelnummer entsteht bei der Auftragsannahme">(Rez.)</span>' : '') : '<span class="muted">–</span>' ?></td>
            <td>
              <strong style="font-weight:600"><?= h($pp['bezeichnung']) ?></strong>
              <?php if (trim((string)($pp['beschreibung'] ?? '')) !== ''): ?><div class="muted" style="font-size:13px;white-space:pre-line;margin-top:2px"><?= h((string)$pp['beschreibung']) ?></div><?php endif; ?>
            </td>
            <td class="bx-num"><?= rtrim(rtrim(number_format((float)$pp['menge'], 3, ',', '.'), '0'), ',') ?></td>
            <td><?= h((string)($pp['einheit'] ?? '')) ?></td>
            <td class="bx-num"><?= h(angpos_vk_str($pp)) ?> €</td>
            <td class="bx-num"><?= $eur($g) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>

    <div class="bx-panel">
      <div style="max-width:360px;margin-left:auto;font-size:15px;line-height:2">
        <div class="bx-row" style="justify-content:space-between"><span>Positionen netto</span><strong><?= $eur($nettoU) ?></strong></div>
        <?php foreach ($ustGrp as $s => $base): if ((float)$s <= 0) continue; ?>
          <div class="bx-row" style="justify-content:space-between"><span>USt. <?= rtrim(rtrim(number_format((float)$s,2,',',''),'0'),',') ?>% auf <?= $eur($base) ?></span><span><?= $eur((int) round($base * ((float)$s)/100)) ?></span></div>
        <?php endforeach; ?>
        <div class="bx-row" style="justify-content:space-between;border-top:1px solid var(--line);margin-top:6px;padding-top:6px;font-size:18px"><span>Endsumme</span><strong><?= $eur($bruttoU) ?></strong></div>
      </div>
    </div>
    <?php else: $optU = angebot_optionen((int)$id); ?>
    <div class="bx-panel">
      <h2 style="margin-top:0">Angebotene Preise – so sieht es der Kunde</h2>
      <div class="muted" style="font-size:12px;margin:-4px 0 10px">Der Kunde wählt im Portal eine dieser Zeilen. Gesamt netto je Zeile bei der angegebenen Packungszahl.</div>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Variante</th><th class="bx-num">Packungen</th><th class="bx-num">Preis / Packung</th><th class="bx-num">Preis / Stk</th><th class="bx-num">Gesamt netto</th></tr></thead>
        <tbody>
        <?php if (!$optU['optionen']): ?><tr><td colspan="5" class="muted">Noch keine Preiszeilen. Über „Bearbeiten" Positionen/Staffeln ergänzen.</td></tr><?php endif; ?>
        <?php foreach ($optU['optionen'] as $o): ?>
          <tr>
            <td><?= h(trim(($o['groesse'] !== '' ? $o['groesse'] : $o['titel']) . ($o['verpackung'] !== '' ? ' · ' . $o['verpackung'] : ''))) ?></td>
            <td class="bx-num"><?= number_format($o['pakete'], 0, ',', '.') ?></td>
            <td class="bx-num"><strong><?= $eur($o['pro_pkg'] * 100) ?></strong></td>
            <td class="bx-num"><?= (!$o['ist_fuell'] && $o['stueck'] > 0) ? (fn($v) => number_format($v, $v < 0.1 ? 4 : 2, ',', '.') . ' €')($o['pro_pkg'] / $o['stueck']) : '–' ?></td>
            <td class="bx-num"><?= $eur($o['netto'] * 100) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php foreach ($optU['extra'] as $x): ?>
          <tr><td colspan="4"><?= h($x['bezeichnung']) ?><span class="muted" style="font-size:12px"> · wird zusätzlich berechnet</span></td>
              <td class="bx-num"><?= $eur(angpos_netto_cent($x)) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php endif; ?>
    <?php
    // Rückfragen des Kunden auch in der Lese-Ansicht (Report 5).
    if (!empty($a['kunde_id'])) { echo kunde_nachricht_panel((int)$a['kunde_id'], 0, (int)$id); kunde_nachricht_gelesen((int)$a['kunde_id'], 0, (int)$id, 'team'); }
    render_footer();
    return;
}
?>
<?php if (!$neu && ki_bereit()): ?>
<details class="bx-panel" style="border-color:var(--gruen)" <?= isset($_GET['kifehler']) || isset($_GET['kiok']) ? 'open' : '' ?>>
  <summary style="cursor:pointer;font-weight:600">Angebot aus PDF einlesen (KI) <span class="muted" style="font-weight:400;font-size:13px">· nur bei Bedarf – z. B. altes v3-Angebot übernehmen</span></summary>
  <div style="margin-top:12px">
    <p class="muted" style="margin-top:0">Altes Angebots-PDF (z. B. aus v3) hochladen – die KI übernimmt die Positionen (Herstellung, Glas/Verpackung, Etikett) inkl. Preise und die Mengenstaffel in dieses Angebot. <strong>Ersetzt die aktuellen Positionen</strong> – danach prüfen.</p>
    <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap" onsubmit="return confirm('PDF einlesen? Die aktuellen Positionen dieses Angebots werden durch die aus dem PDF ersetzt.');">
      <input type="hidden" name="aktion" value="ki_pdf">
      <div class="bx-field" style="margin:0"><label>Angebots-PDF</label><input type="file" name="ki_datei" required accept=".pdf,.png,.jpg,.jpeg,.webp"></div>
      <button class="btn btn-primary" type="submit" data-busy="Liest PDF…">Einlesen</button>
      <span class="muted" style="font-size:12px;align-self:center">dauert 10–60 Sekunden</span>
    </form>
  </div>
</details>
<?php endif; ?>
<form method="post" class="bx-form">
  <input type="hidden" name="aktion" value="kopf_save">
  <details class="bx-panel" <?= $neu ? 'open' : '' ?>><summary style="cursor:pointer">Kopfdaten<span class="muted" style="font-size:13px"> · Kunde, Gültigkeit, Marge, Produktionszeit, Notiz</span></summary><div class="bx-grid" style="margin-top:12px">
    <div class="bx-field"><label>Kunde</label>
      <select name="kunde_id"><option value="">– keiner –</option>
        <?php foreach ($kunden as $k): ?><option value="<?= $k['id'] ?>" <?= $kid===(int)$k['id']?'selected':'' ?>><?= h($k['firma']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php // Das Produkt gehört nicht in die Kopfdaten – es ergibt sich aus den Positionen (Rezeptur × Menge +
          // Verpackung). Gesetzt wird es nur automatisch, wenn das Angebot aus einer Produktanfrage entsteht;
          // damit dieser Bezug beim Speichern nicht verlorengeht, reisen wir ihn unsichtbar mit. ?>
    <input type="hidden" name="produkt_id" value="<?= $pid ?: '' ?>">
    <?php if ($pid): ?>
    <div class="bx-field"><label>Produkt <span class="muted" style="font-size:11px">(intern)</span> <?= bx_hint('kommt aus der Produktanfrage – der Kunde wählt im Portal aus der Preismatrix dieses Produkts.') ?></label>
      <div style="padding:8px 0"><?= h((string) scalar("SELECT name FROM produkt WHERE id=?", [$pid]) ?: '–') ?></div>
    </div>
    <?php // Kundenspezifischer Name (so sieht der Kunde das Produkt). Teil der Kopf-Speicherung (kein Nested Form).
          $knEff = ($kid && function_exists('scalar')) ? (string) scalar("SELECT name FROM produkt_kunde_name WHERE produkt_id=? AND kunde_id=?", [$pid, $kid]) : ''; ?>
    <div class="bx-field"><label>Name beim Kunden <?= bx_hint('So heißt das Produkt bei DIESEM Kunden (Portal/Belege). Leer lassen = Standard-/interner Name. Wird mit „Kopfdaten speichern" übernommen; Entfernen am Produkt.') ?></label>
      <input type="text" name="kn_name" value="<?= h($knEff) ?>" placeholder="wie intern" <?= $kid ? '' : 'disabled title="erst einen Kunden wählen"' ?>>
    </div>
    <?php endif; ?>
    <?php // Status ist eine ANZEIGE – geändert wird er nur über „An Kunden senden" / „Zurückziehen"
          // bzw. durch den Kunden selbst (bestätigt/abgelehnt im Portal). ?>
    <div class="bx-field"><label>Status <?= bx_hint('Entwurf sieht nur das Team. Erst „An Kunden senden" macht das Angebot im Portal sichtbar.') ?></label>
      <div style="padding:8px 0">
        <?= match ($st) {
              'offen'      => bx_badge('Entwurf – noch nicht beim Kunden', 'info'),
              'gesendet'   => bx_badge('beim Kunden', 'ok'),
              'bestaetigt' => bx_badge('vom Kunden bestätigt', 'ok'),
              'abgelehnt'  => bx_badge('vom Kunden abgelehnt', 'err'),
              default      => bx_badge($st),
            } ?>
      </div>
    </div>
    <div class="bx-field"><label>Kunde sieht Preise <?= bx_hint('Erst wenn die Preise freigegeben sind, sieht der Kunde das Angebot samt Preisen im Portal. Beim Nachbearbeiten der Positionen werden sie automatisch gesperrt.') ?></label>
      <div style="padding:8px 0">
        <?= $preiseKunde ? bx_badge('Ja – Preise für den Kunden sichtbar', 'ok') : bx_badge('Nein – für den Kunden gesperrt', 'warn') ?>
      </div>
      <?php if (!empty($a['freigabe_name'])): ?>
      <div class="muted" style="font-size:12px">Verbindlich bestätigt durch <strong><?= h($a['freigabe_name']) ?></strong><?= $a['freigabe_am'] ? ' am ' . h(fmt_zeit($a['freigabe_am'])) . ' Uhr' : '' ?></div>
      <?php endif; ?>
    </div>
    <?php $ekBisF = $neu ? null : angebot_ek_gueltig_bis((int)$id); $gbF = $v('gueltig_bis') !== '' ? $v('gueltig_bis') : angebot_gueltig_bis_default($neu ? null : (int)$id); ?>
    <div class="bx-field"><label>Gültig bis</label><input type="date" name="gueltig_bis" value="<?= h($gbF) ?>">
      <?php if ($ekBisF): ?><div style="font-size:12px;margin-top:3px;<?= $gbF > $ekBisF['bis'] ? 'color:#8a6d1f' : '' ?>" class="<?= $gbF > $ekBisF['bis'] ? '' : 'muted' ?>"><?= $gbF > $ekBisF['bis'] ? 'Angebot gilt länger als der ' . h($ekBisF['name']) . '-Preis (bis ' . h(date('d.m.Y', strtotime($ekBisF['bis']))) . ')' : 'Kürzester EK-Preis: ' . h($ekBisF['name']) . ' bis ' . h(date('d.m.Y', strtotime($ekBisF['bis']))) ?></div><?php endif; ?></div>
    <div class="bx-field"><label>Marge (%) <?= bx_hint('wirkt auf die automatischen Positionen. Leer = Marge je Form ('.rtrim(rtrim(number_format($defMarge,2,',','.'),'0'),',').' %).') ?></label><input type="number" step="0.1" name="marge" value="<?= ($a['marge_override'] ?? '') !== '' && $a['marge_override'] !== null ? h(rtrim(rtrim(number_format((float)$a['marge_override'],2,'.',''),'0'),'.')) : '' ?>" placeholder="<?= h(rtrim(rtrim(number_format($defMarge,2,',','.'),'0'),',')) ?> (Standard)"></div>
    <div class="bx-field"><label>Produktionszeit (Wochen)</label><input type="number" step="0.5" name="produktionszeit" value="<?= ($a['produktionszeit_wochen'] ?? '') !== '' && $a['produktionszeit_wochen'] !== null ? h(rtrim(rtrim(number_format((float)$a['produktionszeit_wochen'],1,'.',''),'0'),'.')) : '' ?>" placeholder="<?= h(rtrim(rtrim(number_format($defPz,1,',','.'),'0'),',')) ?> (Standard)"></div>
  </div>
  <div class="bx-field"><label>Notiz</label><textarea name="notiz"><?= $v('notiz') ?></textarea></div>
  <?php $istJV = (int)($a['jahresvertrag'] ?? 0) === 1; ?>
  <div class="bx-field" style="border:1px solid var(--line);border-radius:8px;padding:12px 14px;margin-top:4px">
    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
      <input type="checkbox" name="jahresvertrag" value="1" id="jvChk" <?= $istJV ? 'checked' : '' ?> style="width:auto">
      <span><strong>Jahresabnahmevertrag</strong> – kein Einzelauftrag, sondern ein Kontingent, aus dem der Kunde übers Portal abruft</span>
    </label>
    <div class="bx-grid" id="jvFelder" style="margin-top:10px<?= $istJV ? '' : ';display:none' ?>">
      <div class="bx-field"><label>Jahresmenge (Packungen)</label><input type="number" min="1" name="jahresmenge" value="<?= ($a['jahresmenge'] ?? '') !== '' && $a['jahresmenge'] !== null ? (int)$a['jahresmenge'] : '' ?>" placeholder="z. B. 45000"></div>
      <div class="bx-field"><label>Festpreis je Packung (€)</label><input type="number" step="0.0001" name="jahres_vk" value="<?= ($a['jahres_vk'] ?? '') !== '' && $a['jahres_vk'] !== null ? h(rtrim(rtrim(number_format((float)$a['jahres_vk'],4,'.',''),'0'),'.')) : '' ?>" placeholder="z. B. 0,84"></div>
      <div class="bx-field"><label>Laufzeit (Monate)</label><input type="number" min="1" name="jahres_laufzeit_monate" value="<?= (int)($a['jahres_laufzeit_monate'] ?? 12) ?: 12 ?>"></div>
    </div>
    <div class="muted" style="font-size:12px;margin-top:6px">Der Kunde bestätigt das Angebot wie gewohnt, lädt den unterschriebenen Vertrag hoch; nach eurer Freigabe wird das Kontingent aktiv.</div>
  </div>
  <script>(function(){var c=document.getElementById('jvChk'),f=document.getElementById('jvFelder');if(c&&f)c.addEventListener('change',function(){f.style.display=c.checked?'':'none';});})();</script>
  <div class="bx-row"><button class="btn btn-primary" type="submit"><?= $neu ? 'Angebot anlegen' : 'Kopfdaten speichern' ?></button><a class="btn btn-ghost" href="?p=angebote">Abbrechen</a></div>
  </details>
</form>

<?php
// Positionen, Summen und PDF gibt es für JEDES gespeicherte Angebot – auch ohne Produkt im Kopf.
// (Vorher hing der ganze Bereich an $pid: Bei einer Rezeptur-Anfrage ohne Produkt blieb nur der Kopf stehen.)
if (!$neu):
    // Gruppen reparieren: liegen mehrere Mengen-Varianten faelschlich in derselben Gruppe, bekommt jede wieder
    // ihren eigenen Buchstaben (sonst wirft die „Preis je fertiges Produkt"-Zusammenfassung sie zusammen).
    angebot_gruppen_normalisieren((int)$id);
    // Herstell-EK der Positionen frisch aus Rezeptur/Lieferantenpreis ziehen – so erscheint ein erst spaeter
    // vom Lieferanten eingetragener Fertig-/Rohstoffpreis hier automatisch, statt als 0 stehen zu bleiben.
    angebot_herstellung_ek_auffrischen((int)$id);
    $pos = angebot_positionen((int)$id);
    // Einheit fuer die Spalte "Inhalt" (Stueck je Packung): je Rezeptur die Darreichungsform -> Kapseln/g/ml.
    $rezForm = [];
    foreach ($pos as $pp) { $rid = (int)($pp['rezeptur_id'] ?? 0); if ($rid && !isset($rezForm[$rid])) $rezForm[$rid] = (string) scalar("SELECT darreichungsform FROM rezeptur WHERE id=?", [$rid]) ?: ''; }
    $inhaltUnit = function($rid) use ($rezForm) { $f = $rezForm[(int)$rid] ?? ''; return $f === '' ? '' : (form_ist_fuellmenge($f) ? form_groessen_einheit($f) : form_plural($f)); };
    $ueberschrieben = angebot_hat_positionen((int)$id);
    $eur = fn($c) => number_format($c/100, 2, ',', '.') . ' €';
    // interne Summen
    $sumVk = 0; $sumEk = 0;
    foreach ($pos as $pp) { $sumVk += angpos_netto_cent($pp); $sumEk += $pp['menge'] * $pp['ek_cent']; }
    $marge = $sumVk - $sumEk; $margePct = $sumVk > 0 ? $marge / $sumVk * 100 : 0;
    $ktok = ''; foreach ($kunden as $k) if ((int)$k['id'] === $kid) { $ktok = $k['portal_token']; break; }
    // Wunsch aus der verknüpften Portal-Anfrage übernehmen: Rezeptur, Menge je Packung, Anzahl Packungen
    // und – aus dem Wunsch-Verpackungstyp – ein passender Behälter. Sonst müsste das Team alles abtippen.
    $wunsch = ['rezeptur_id'=>0, 'stueck'=>'', 'menge'=>'', 'verp_id'=>0, 'typ_label'=>''];
    $letzte = $_SESSION['ang_add'][(int)$id] ?? null;   // was zuletzt angehaengt wurde
    if (!empty($a['anfrage_id'])) {
        $wa = one("SELECT rezeptur_id, produkt_id, stueck, fuellmenge_g, verpackung_typ, menge FROM portal_anfrage WHERE id=?", [(int)$a['anfrage_id']]);
        if ($wa) {
            $wunsch['rezeptur_id'] = (int)($wa['rezeptur_id'] ?: scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [(int)$wa['produkt_id']]));
            $wform = $wunsch['rezeptur_id'] ? ((string) scalar("SELECT darreichungsform FROM rezeptur WHERE id=?", [$wunsch['rezeptur_id']]) ?: 'kapsel') : 'kapsel';
            $wunsch['stueck'] = anfrage_groesse($wa['stueck'] ?? null, $wa['fuellmenge_g'] ?? null, $wform);   // formrichtig: Kapselzahl aus stueck, nicht aus fuellmenge_g
            $wunsch['menge']  = (int)$wa['menge'];
            $wunsch['typ_label'] = (string)($wa['verpackung_typ'] ?? '');
            // Behälter zum Wunschtyp vorschlagen, der die Menge auch fasst
            if ($wunsch['rezeptur_id'] && $wunsch['stueck'] > 0) {
                foreach (passende_behaelter_fuer($wunsch['rezeptur_id'], $wform, (int)$wunsch['stueck']) as $cand)
                    if (verpackung_passt_zu_typ((int)$cand, $wunsch['typ_label'] ?: null)) { $wunsch['verp_id'] = (int)$cand; break; }
            }
        }
    }
    // Alle aktuell angefragten Staffelzeilen (auch nach einer Kunden-Aenderung der Menge) – Grundlage fuer den
    // Button "Angefragte Menge uebernehmen", der die Positionen aus der verknuepften Anfrage neu aufbaut.
    $anfStaffel = [];
    if (!empty($a['anfrage_id']) && $wunsch['rezeptur_id']) {
        $pap = all("SELECT stueck, fuellmenge_g, menge FROM portal_anfrage_pos WHERE anfrage_id=? ORDER BY sort, id", [(int)$a['anfrage_id']]);
        if (!$pap && !empty($wa)) $pap = [['stueck'=>$wa['stueck'], 'fuellmenge_g'=>$wa['fuellmenge_g'], 'menge'=>$wa['menge']]];
        foreach ($pap as $z) {
            $stk = anfrage_groesse($z['stueck'] ?? null, $z['fuellmenge_g'] ?? null, $wform ?? 'kapsel'); $mng = (int)$z['menge'];
            if ($stk > 0 && $mng > 0) $anfStaffel[] = ['stueck'=>$stk, 'menge'=>$mng];
        }
    }
    $anfLabel = implode(' · ', array_map(fn($s) => number_format($s['menge'], 0, ',', '.') . ' × ' . $s['stueck'] . '/Pkg', $anfStaffel));
?>
<div style="display:flex;flex-direction:column">
  <div class="bx-panel" style="order:2">
  <h2 style="margin-top:0">Position hinzufügen</h2>
  <p class="muted" style="margin-top:0">Erst den Typ wählen – dann kommt der passende Katalog. Jede Position wird als eigene Gruppe (A, B, C …) angehängt.</p>
  <?php if ($wunsch['rezeptur_id'] || $wunsch['stueck'] || $wunsch['menge']): ?>
    <div class="bx-panel" style="padding:10px 14px;margin-bottom:12px;background:var(--panel-2)">
      <strong>Aus der Anfrage übernommen:</strong>
      <?= $wunsch['stueck'] ? h($wunsch['stueck']) . ' je Packung · ' : '' ?>
      <?= $wunsch['menge'] ? number_format((int)$wunsch['menge'], 0, ',', '.') . ' Packungen' : '' ?>
      <?= $wunsch['typ_label'] ? ' · Wunsch: ' . h(['glas'=>'Glas','pet'=>'PET-Dose','pla'=>'PLA-Becher','beutel'=>'Standbodenbeutel','stick'=>'Stick','blister'=>'Blister'][$wunsch['typ_label']] ?? $wunsch['typ_label']) : '' ?>
      <div class="muted" style="font-size:12px">Die Felder unten sind vorbelegt – Werte prüfen und bei Bedarf ändern.</div>
    </div>
  <?php endif; ?>
  <div class="bx-field" style="max-width:280px"><label>Typ</label>
    <select id="addTyp">
      <option value="rezeptur">Rezeptur (Lohnherstellung)</option>
      <option value="rohstoff">Rohstoff</option>
      <option value="dienstleistung">Dienstleistung</option>
    </select>
  </div>

  <form method="post" data-add="rezeptur">
    <input type="hidden" name="aktion" value="add_rezeptur">
    <div class="bx-grid">
      <div class="bx-field"><label>Rezeptur</label>
        <select name="add_rezeptur_id" required><option value="">– wählen –</option>
          <?php foreach ($rezepturKatalog as $rz): ?><option value="<?= (int)$rz['id'] ?>" <?= $wunsch['rezeptur_id'] === (int)$rz['id'] ? 'selected' : '' ?>><?= h($rz['name']) ?><?= $rz['darreichungsform'] ? ' · '.h($rz['darreichungsform']) : '' ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Stückzahl / Füllmenge je Packung</label><input type="number" name="add_stueck" placeholder="z. B. 30" value="<?= $letzte['stueck'] ?? ($wunsch['stueck'] !== '' ? (int)$wunsch['stueck'] : '') ?>" required></div>
      <div class="bx-field"><label>Anzahl Packungen <?= bx_hint('Mehrere Mengen mit Komma, z. B. 1000, 2500, 5000 – je Menge entsteht eine wählbare Option mit eigenem Preis je Packung.') ?></label><input type="text" inputmode="numeric" name="add_menge" placeholder="z. B. 1000, 2500, 5000" value="<?= h((string)($letzte['menge'] ?? ($wunsch['menge'] !== '' ? (int)$wunsch['menge'] : ''))) ?>" required></div>
      <div class="bx-field"><label>Verpackung (Primär)</label><select name="add_verp_id"><option value="">– keine –</option><?php foreach ($verpPrim as $vp): ?><option value="<?= (int)$vp['id'] ?>" <?= ((int)($letzte['verp_id'] ?? $wunsch['verp_id']) === (int)$vp['id']) ? 'selected' : '' ?>><?= h($vp['name']) ?></option><?php endforeach; ?></select></div>
      <div class="bx-field"><label>Deckel (optional)</label><select name="add_deckel_id"><option value="">– keiner –</option><?php foreach ($verpDeckel as $vp): ?><option value="<?= (int)$vp['id'] ?>" <?= ((int)($letzte['deckel_id'] ?? 0) === (int)$vp['id']) ? 'selected' : '' ?>><?= h($vp['name']) ?></option><?php endforeach; ?></select></div>
      <div class="bx-field"><label>Etikett (optional)</label>
        <select name="add_etikett_id" id="add_etikett_id"><option value="">– keins –</option><?php foreach ($verpEtik as $vp): ?><option value="<?= (int)$vp['id'] ?>"><?= h($vp['name']) ?></option><?php endforeach; ?></select>
        <div class="muted" style="font-size:12px;margin-top:4px" id="etikHinweis"></div>
      </div>
    </div>
    <div class="bx-row" style="margin-top:10px"><button class="btn btn-primary" type="submit">Hinzufügen</button></div>
  </form>
  <?php // Etiketten haengen am Behaelter: gezeigt wird nur, was auf das am Behaelter hinterlegte
        // Endformat (item.etikett_final) passt. Fehlt das Format oder gibt es kein passendes
        // Etikett, sagt der Hinweis genau das - statt still eine leere Auswahl anzubieten. ?>
  <script>(function(){
    var map = <?= json_encode(etikett_zuordnung()) ?>;
    var hatEtiketten = <?= $verpEtik ? 'true' : 'false' ?>;
    var verp = document.querySelector('select[name="add_verp_id"]');
    var etik = document.getElementById('add_etikett_id');
    var hint = document.getElementById('etikHinweis');
    if (!verp || !etik) return;
    function upd(){
      var vid = verp.value, erlaubt = vid ? (map[vid] || []) : null;
      var sichtbar = 0;
      Array.prototype.forEach.call(etik.options, function(o){
        if (!o.value) return;
        var ok = !erlaubt || erlaubt.indexOf(parseInt(o.value,10)) >= 0;
        o.hidden = !ok; o.disabled = !ok; if (ok) sichtbar++;
      });
      if (etik.selectedOptions[0] && etik.selectedOptions[0].disabled) etik.value = '';
      if (!hatEtiketten) hint.textContent = 'Es sind noch keine Etiketten im Katalog angelegt (Lager > Verpackungen, Rolle "Etikett").';
      else if (!vid) hint.textContent = 'Erst die Verpackung wählen – dann stehen nur die dazu passenden Etiketten zur Auswahl.';
      else if (sichtbar === 0) hint.textContent = 'Für diese Verpackung ist kein passendes Etikett hinterlegt. Am Behälter fehlt das Etiketten-Endformat (B x H) oder es gibt noch kein Etikett in dieser Groesse.';
      else hint.textContent = sichtbar + (sichtbar === 1 ? ' passendes Etikett' : ' passende Etiketten') + ' zu dieser Verpackung.';
    }
    verp.addEventListener('change', upd); upd();
  })();</script>

  <form method="post" data-add="rohstoff" style="display:none">
    <input type="hidden" name="aktion" value="add_rohstoff">
    <div class="bx-grid">
      <div class="bx-field"><label>Rohstoff</label>
        <select name="add_rohstoff_id" required><option value="">– wählen –</option>
          <?php foreach ($rohstoffKatalog as $rs): ?><option value="<?= (int)$rs['id'] ?>"><?= h(rohstoff_anzeige_name($rs)) ?><?= $rs['artikelnummer'] ? ' · '.h($rs['artikelnummer']) : '' ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Menge</label><input type="number" step="0.001" name="add_menge" placeholder="z. B. 25" required></div>
      <div class="bx-field"><label>Einheit</label><select name="add_einheit"><?php foreach (['kg','g','Stk','L'] as $e): ?><option value="<?= $e ?>"><?= $e ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="bx-row" style="margin-top:10px"><button class="btn btn-primary" type="submit">Hinzufügen</button></div>
  </form>

  <form method="post" data-add="dienstleistung" style="display:none">
    <input type="hidden" name="aktion" value="add_dienstleistung">
    <?php if ($dlKatalog): ?>
    <div class="bx-grid">
      <div class="bx-field" style="min-width:320px"><label>Aus dem Dienstleistungs-Katalog</label>
        <select name="add_dl_id" class="rscombo"><option value="">– frei eintragen (unten) –</option>
          <?php foreach ($dlKatalog as $d): ?><option value="<?= (int)$d['id'] ?>"><?= h($d['name']) ?> (<?= h(dienstleistung_preis_text($d)) ?>)</option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field" style="max-width:120px"><label>Menge</label><input type="text" name="add_dl_menge" value="1" style="text-align:right"></div>
    </div>
    <div class="muted" style="font-size:12px;margin:4px 0 10px">Katalog-Leistung gewählt? Preis kommt automatisch (kundenspezifisch, falls hinterlegt) – Menge ggf. anpassen. Sonst unten frei eintragen.</div>
    <?php endif; ?>
    <div class="bx-grid">
      <div class="bx-field"><label>Bezeichnung (frei)</label><input type="text" name="add_bez" placeholder="z. B. Laboranalyse"></div>
      <div class="bx-field"><label>Menge</label><input type="number" step="0.01" name="add_menge" value="1"></div>
      <div class="bx-field"><label>Einheit</label><input type="text" name="add_einheit" placeholder="Stk / Pauschal"></div>
      <div class="bx-field"><label>Preis je Einheit (€)</label><input type="number" step="0.01" name="add_preis" placeholder="0,00"></div>
    </div>
    <div class="bx-row" style="margin-top:10px"><button class="btn btn-primary" type="submit">Hinzufügen</button></div>
  </form>
</div>

<div class="bx-panel" style="order:1">
  <div class="bx-row" style="justify-content:space-between;align-items:center">
    <h2 id="positionen" style="margin:0;scroll-margin-top:16px">Positionen <span class="muted" style="font-weight:400;font-size:13px"><?= $ueberschrieben ? 'manuell überschrieben' : 'automatisch berechnet' ?></span></h2>
    <div class="bx-row" style="gap:8px">
      <a class="btn btn-ghost btn-sm" target="_blank" href="?p=angebot_pdf&id=<?= (int)$id ?>">&#8681; PDF ansehen</a>
      <?php if ($ueberschrieben): ?>
        <form method="post" style="margin:0" onsubmit="return confirm('Positionen auf die automatische Berechnung zurücksetzen? Manuelle Änderungen gehen verloren.');"><input type="hidden" name="aktion" value="pos_reset"><button class="btn btn-ghost btn-sm" type="submit">Automatik wiederherstellen</button></form>
      <?php endif; ?>
    </div>
  </div>
  <p class="muted" style="margin-top:4px;font-size:13px">Automatisch erzeugt aus Produkt + Preismatrix + Verpackung (Dose/Deckel/Etikett kommen extra). Du kannst Menge, Preis, MwSt anpassen oder Positionen hinzufügen/entfernen. <strong>Speichern friert die Positionen ein</strong> (überschreibt die Automatik).</p>
  <?php if ($anfStaffel): ?>
  <div class="bx-panel" style="border-color:var(--gruen);background:var(--panel-2);padding:12px 14px;margin:0 0 12px">
    <div class="bx-row" style="justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
      <div>
        <strong>Vom Kunden angefragt:</strong> <?= h($anfLabel) ?>
        <div class="muted" style="font-size:12px;margin-top:2px">Baut die Positionen aus dieser Anfrage neu auf (ersetzt die aktuellen) – danach Preise prüfen und freigeben/senden.</div>
      </div>
      <form method="post" style="margin:0" onsubmit="return confirm('Angefragte Konfiguration übernehmen? Die aktuellen Positionen dieses Angebots werden dadurch ersetzt.');">
        <input type="hidden" name="aktion" value="anfrage_uebernehmen">
        <button class="btn btn-primary btn-sm" type="submit" style="white-space:nowrap">Angefragte Menge übernehmen</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <style>
    #postab{table-layout:fixed;width:100%}
    #postab th,#postab td{padding:6px 8px;vertical-align:middle;overflow:hidden}
    #postab input,#postab textarea{box-sizing:border-box;width:100%;display:block;min-width:0}
    #postab .p_besch{margin-top:4px;font-size:14px;color:var(--muted);resize:vertical;line-height:1.35}
    #postab .bx-num{white-space:nowrap;text-align:right}
  </style>
  <form method="post">
    <input type="hidden" name="aktion" value="pos_save">
    <datalist id="angEinhListe"><option value="Stk"><option value="Packung"><option value="kg"><option value="g"><option value="L"><option value="Beutel"></datalist>
    <table class="bx-table" id="postab">
      <colgroup>
        <col><col style="width:118px"><col style="width:88px"><col style="width:72px"><col style="width:92px"><col style="width:74px">
        <col style="width:80px"><col style="width:88px"><col style="width:96px"><col style="width:40px">
      </colgroup>
      <thead><tr>
        <th>Bezeichnung</th><th class="bx-num">Inhalt <?= bx_hint('Stk je Packung (z. B. 60 Kapseln). Notfalls hier direkt korrigieren – wird als Packungsgröße gespeichert.') ?></th><th class="bx-num">Menge</th><th>Einheit</th>
        <th class="bx-num">Preis/Einh €</th><th class="bx-num">MwSt %</th>
        <th class="bx-num">EK/Einh</th><th class="bx-num">Marge</th><th class="bx-num">Gesamt</th><th></th>
      </tr></thead>
      <tbody id="posrows">
        <?php foreach ($pos as $i => $pp): ?>
        <tr class="posrow">
          <td>
            <input type="text" name="p_bez[]" value="<?= h($pp['bezeichnung']) ?>">
            <textarea name="p_besch[]" class="p_besch" rows="<?= max(2, substr_count((string)($pp['beschreibung'] ?? ''), "\n") + 1) ?>" placeholder="Beschreibung / Rezeptur (optional)"><?= h($pp['beschreibung'] ?? '') ?></textarea>
            <input type="hidden" name="p_art[]" value="<?= h($pp['artikelnr'] ?? '') ?>">
            <input type="hidden" name="p_quelle[]" value="<?= h($pp['quelle'] ?? 'manuell') ?>">
            <?php if ($istDL): ?>
            <label style="display:inline-flex;align-items:center;gap:4px;font-size:11px;margin-top:4px" class="muted">Variante
              <input type="text" name="p_gruppe[]" value="<?= h($pp['gruppe'] ?? '') ?>" maxlength="1" placeholder="–" title="Varianten-Buchstabe A/B/C … (leer = Basis, immer enthalten). Der Kunde wählt beim Annehmen EINE Variante." style="width:44px;text-align:center;text-transform:uppercase"></label>
            <?php else: ?>
            <input type="hidden" name="p_gruppe[]" value="<?= h($pp['gruppe'] ?? '') ?>">
            <?php endif; ?>
            <input type="hidden" name="p_rez[]" value="<?= (int)($pp['rezeptur_id'] ?? 0) ?: '' ?>">
            <input type="hidden" name="p_vid[]" value="<?= (int)($pp['verpackung_id'] ?? 0) ?: '' ?>">
            <input type="hidden" name="p_tausch[]" class="p_tausch" value="">
            <input type="hidden" name="p_kspez[]" class="p_kspez" value="<?= (int)($pp['kundenspezifisch'] ?? 0) ?>">
            <input type="hidden" name="p_blanko[]" class="p_blanko" value="<?= (int)($pp['blanko_etikett'] ?? 0) ?>">
            <?php if (($pp['quelle'] ?? '') === 'verpackung'):
                // Standbodenbeutel? (Behälter der Zeile, sonst der Herstellungszeile derselben Option)
                $vpZeile = (int)($pp['verpackung_id'] ?? 0);
                if ($vpZeile <= 0) foreach ($pos as $hp) if (($hp['quelle'] ?? '') === 'herstellung' && (string)($hp['gruppe'] ?? '') === (string)($pp['gruppe'] ?? '') && (int)($hp['verpackung_id'] ?? 0) > 0) { $vpZeile = (int)$hp['verpackung_id']; break; }
                $istBeutelZeile = $vpZeile > 0 && (string) scalar("SELECT verpackungsart FROM item WHERE id=?", [verpackung_neutrale_basis($vpZeile) ?: $vpZeile]) === 'beutel';
                if ($istBeutelZeile): $bl = (int)($pp['blanko_etikett'] ?? 0);
                    // Beutel von Hand wählen (Nico 05.11.2026): die Automatik geht nach max. Füllgewicht, die echte Dichte
                    // je Stoff weicht ab. Auswahl = alle neutralen Standbodenbeutel; Wechsel speichert sofort (Preis/EK neu).
                    if (!isset($beutelListe)) $beutelListe = all("SELECT id, name, max_fuellgewicht_g FROM item WHERE kategorie='verpackung' AND COALESCE(verpackung_rolle,'primaer')='primaer'
                        AND verpackungsart='beutel' AND gesperrt=0 AND kunde_id IS NULL AND COALESCE(kundenspezifisch,0)=0 ORDER BY COALESCE(volumen_ml, max_fuellgewicht_g, 0), name");
                    $beutelIst = verpackung_neutrale_basis($vpZeile) ?: $vpZeile; ?>
            <label style="display:flex;align-items:center;gap:6px;font-size:11px;margin-top:4px;max-width:460px" class="muted bt-wahl"><style>.bt-wahl .rs-combo{flex:1 1 auto;min-width:0}.bt-wahl .rs-input{font-size:12px;padding:4px 8px}</style>Beutelgröße
              <select class="<?= count($beutelListe) > 10 ? 'rscombo' : '' ?>" style="font-size:11px;padding:2px 4px;max-width:260px" data-ist="<?= (int)$beutelIst ?>" onchange="if(!this.value||this.value===this.getAttribute('data-ist'))return;var r=this.closest('.posrow');r.querySelector('.p_tausch').value=this.value;this.form.submit()">
                <?php foreach ($beutelListe as $bt): ?><option value="<?= (int)$bt['id'] ?>" <?= (int)$bt['id'] === (int)$beutelIst ? 'selected' : '' ?>><?= h((string)$bt['name']) ?><?= (float)$bt['max_fuellgewicht_g'] > 0 ? ' · max. ' . h(rtrim(rtrim(number_format((float)$bt['max_fuellgewicht_g'], 1, ',', '.'), '0'), ',')) . ' g' : '' ?></option><?php endforeach; ?>
              </select></label>
            <label style="display:inline-flex;align-items:center;gap:6px;font-size:11px;margin-top:4px" class="muted" title="Standbodenbeutel: vollbedruckt ist Standard (eigener bedruckter Beutel je Produkt + Kunde, KEIN Etikett). Ausnahme: Blanko-Beutel + Etikett (Etikettformat individuell, nach Rücksprache).">
              Beutel
              <select style="font-size:11px;padding:2px 4px" onchange="var r=this.closest('.posrow');r.querySelector('.p_blanko').value=this.value;r.querySelector('.p_kspez').value=this.value==='1'?0:1">
                <option value="0" <?= $bl ? '' : 'selected' ?>>vollbedruckt (Standard, ohne Etikett)</option>
                <option value="1" <?= $bl ? 'selected' : '' ?>>blanko + Etikett (Ausnahme)</option>
              </select></label>
            <?php else: ?>
            <label style="display:inline-flex;align-items:center;gap:4px;font-size:11px;margin-top:4px" class="muted" title="Bedruckter, kundenspezifischer Behälter (kein Standard-Basic). Bei Annahme entsteht daraus ein eigener Behälter-Artikel je Produkt + Kunde – der Druck ersetzt das Etikett.">
              <input type="checkbox" <?= (int)($pp['kundenspezifisch'] ?? 0) ? 'checked' : '' ?> onchange="this.closest('.posrow').querySelector('.p_kspez').value=this.checked?1:0"> bedruckt / kundenspezifisch (ohne Etikett)</label>
            <?php endif; endif; ?>
            <input type="hidden" name="p_ek[]" class="p_ek" value="<?= h(number_format($pp['ek_cent']/100,4,'.','')) ?>">
            <?php if (($pp['quelle'] ?? '') === 'herstellung' && (int)($pp['rezeptur_id'] ?? 0) > 0 && (int)($pp['stueck'] ?? 0) > 0):
                $ekQ = rezeptur_ek_quelle((int)$pp['rezeptur_id'], (int)$pp['stueck'], (int) round((float)$pp['menge']));   // Nacht 3 (13a)
                if ($ekQ['quelle'] === 'schaetzung') $ekSchaetzung = true;
                if ($ekQ['text'] !== ''): ?>
            <div style="font-size:11px;margin-top:4px;color:<?= $ekQ['quelle'] === 'schaetzung' ? '#8f231b' : 'var(--muted)' ?>"><?= h($ekQ['text']) ?></div>
            <?php endif; endif; ?>
          </td>
          <?php $u=$inhaltUnit($pp['rezeptur_id']??0); ?>
          <td><div style="display:flex;align-items:center;gap:4px"><input type="number" step="1" min="0" name="p_stk[]" class="p_stk" data-unit="<?= h($u) ?>" value="<?= (int)($pp['stueck'] ?? 0) ?: '' ?>" placeholder="&ndash;" style="width:100%;text-align:right"><?php if($u!==''): ?><span class="muted" style="font-size:11px;white-space:nowrap"><?= h($u) ?></span><?php endif; ?></div></td>
          <td><input type="number" step="0.001" name="p_menge[]" class="p_menge" value="<?= h(rtrim(rtrim(number_format($pp['menge'],3,'.',''),'0'),'.')) ?>" style="width:100%"></td>
          <td><input type="text" name="p_einheit[]" value="<?= h($pp['einheit'] ?? '') ?>" list="angEinhListe" style="width:100%"></td>
          <td><input type="number" step="0.0001" min="0" name="p_preis[]" class="p_preis" value="<?= h(rtrim(rtrim(number_format(angpos_vk_eur($pp),4,'.',''),'0'),'.')) ?>" style="width:100%"></td>
          <td><?php $mwCur = mwst_normalisieren((float)$pp['mwst_satz']); ?><select name="p_mwst[]" style="width:100%"><?php foreach (mwst_saetze() as $ms): ?><option value="<?= (int)$ms ?>" <?= (int)$ms === (int)$mwCur ? 'selected' : '' ?>><?= (int)$ms ?> %</option><?php endforeach; ?></select></td>
          <td class="bx-num c_ek">–</td><td class="bx-num c_marge">–</td><td class="bx-num c_ges">–</td>
          <td><button type="button" class="btn btn-ghost btn-sm" title="Position löschen" onclick="var f=this.closest('form');this.closest('.posrow').remove();posRecalc();f.submit()">×</button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!empty($ekSchaetzung)): ?><div class="bx-panel" style="margin:10px 0 0;padding:10px 14px;border-left:3px solid #b23b2f;font-size:13px"><strong>Zukaufpreis fehlt:</strong> Mindestens eine Position soll zugekauft werden, es gibt aber noch keinen Bulk-/Zukaufpreis des Lieferanten. Der EK ist nur eine Rohstoff-Schätzung – den VK erst festlegen, wenn der Lieferantenpreis da ist.</div><?php endif; ?>
    <?php if ($istDL): ?>
    <div class="muted" style="font-size:12px;margin-top:6px">Varianten (A/B/C): Positionen mit demselben Buchstaben bilden EINE Variante; der Kunde wählt beim Annehmen genau eine davon. Positionen <strong>ohne</strong> Buchstaben gelten als Basis und sind in jeder Variante enthalten. Keine Buchstaben = ein festes Angebot ohne Auswahl.</div>
    <?php endif; ?>
    <div class="bx-row" style="justify-content:space-between;align-items:flex-start;margin-top:8px">
      <button type="button" class="btn btn-ghost btn-sm" id="addPos">+ freie Position</button>
      <span class="muted" style="font-size:12px;margin-left:8px">für Zuschläge/Dienstleistungen – Produkte bitte oben aus dem Katalog hinzufügen</span>
      <div style="text-align:right;font-size:14px">
        <div>Netto: <strong id="sumNetto"><?= $eur($sumVk) ?></strong></div>
        <div class="muted" style="font-size:12px" id="intMarge">Intern: EK <?= $eur($sumEk) ?> · Marge <?= $eur($marge) ?> (<?= number_format($margePct,0,',','.') ?> %)</div>
      </div>
    </div>
    <div class="bx-row" style="margin-top:10px;gap:10px">
      <button class="btn <?= $kannSenden ? 'btn-ghost' : 'btn-primary' ?>" type="submit">Positionen speichern</button>
      <?php if ($kannSenden): ?>
      <button class="btn btn-primary" type="submit" name="und_senden" value="1"
              data-confirm="Positionen speichern und das Angebot an den Kunden senden? Der Kunde sieht damit das Angebot inkl. Preise im Portal.">Speichern &amp; an Kunden senden</button>
      <?php endif; ?>
    </div>
  </form>

  <?php // Preis je Packung – genau die Zeilen, aus denen der Kunde im Portal auswaehlt.
        // In den Positionen stehen Herstellung und Verpackung getrennt; hier zusammengerechnet.
        $optE = angebot_optionen($id); ?>
  <?php if ($optE['optionen']): ?>
  <div class="bx-panel" style="margin-top:16px">
    <div style="margin-bottom:4px">Preis je Packung – so sieht es der Kunde</div>
    <div class="muted" style="font-size:12px;margin-bottom:10px">Stand nach dem letzten Speichern. Jede Gruppe ist im Kundenportal eine Zeile mit eigenem Knopf.</div>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Variante</th><th class="bx-num">Packungen</th><th class="bx-num">Preis / Packung</th><th class="bx-num">Preis / Stk</th><th class="bx-num">Gesamt netto</th></tr></thead>
      <tbody>
      <?php foreach ($optE['optionen'] as $o): ?>
        <tr>
          <td><?= h(trim(($o['groesse'] !== '' ? $o['groesse'] : $o['titel']) . ($o['verpackung'] !== '' ? ' · ' . $o['verpackung'] : ''))) ?></td>
          <td class="bx-num"><?= number_format($o['pakete'], 0, ',', '.') ?></td>
          <td class="bx-num"><strong><?= $eur($o['pro_pkg'] * 100) ?></strong></td>
          <td class="bx-num"><?= (!$o['ist_fuell'] && $o['stueck'] > 0) ? (fn($v) => number_format($v, $v < 0.1 ? 4 : 2, ',', '.') . ' €')($o['pro_pkg'] / $o['stueck']) : '–' ?></td>
          <td class="bx-num"><?= $eur($o['netto'] * 100) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php foreach ($optE['extra'] as $x): ?>
        <tr><td colspan="4"><?= h($x['bezeichnung']) ?><span class="muted" style="font-size:12px"> · wird zusätzlich berechnet</span></td>
            <td class="bx-num"><?= $eur(angpos_netto_cent($x)) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>

  <?php // MOQ je Rohstoff (Nico 11.11.2026): liegt die angebotene bzw. für die Auftragsmenge benötigte Menge unter
        // unserer Mindestmenge? Nur Hinweis - Nico entscheidet.
        $moqRows = angebot_moq_pruefung((int)$id); if ($moqRows): ?>
  <div class="bx-panel" style="margin-top:16px<?= array_filter($moqRows, fn($r) => $r['unter']) ? ';border-left:3px solid var(--warn)' : '' ?>">
    <div style="margin-bottom:4px">Mindestmengen (MOQ) je Rohstoff</div>
    <div class="muted" style="font-size:12px;margin-bottom:10px">Unsere Mindestmenge am Rohstoff gegen die Menge im Angebot. Bei Rezepturen: benötigte Menge für die Auftragsmenge (kleinste bis größte Option). Nur Hinweis, keine Sperre.</div>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Rohstoff</th><th>Woher</th><th class="bx-num">Menge im Angebot</th><th class="bx-num">MOQ</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($moqRows as $mr): $me = $mr['moq']['einheit']; ?>
        <tr>
          <td><a href="?p=rohstoff&id=<?= (int)$mr['item_id'] ?>"><?= h($mr['name']) ?></a></td>
          <td><?= h($mr['quelle'] === 'Rezeptur' ? 'Rezeptur (benötigt)' : 'Rohstoff-Position') ?></td>
          <td class="bx-num"><?= h(moq_zahl(round($mr['min'], 3))) ?><?= abs($mr['max'] - $mr['min']) > 0.0005 ? ' bis ' . h(moq_zahl(round($mr['max'], 3))) : '' ?> <?= h($me) ?></td>
          <td class="bx-num"<?= $mr['moq']['notiz'] !== '' ? ' title="' . h($mr['moq']['notiz']) . '"' : '' ?>><?= h(moq_wert($mr['moq'])) ?></td>
          <td><?= $mr['unter'] ? bx_badge('unter MOQ', 'warn') : bx_badge('ok', 'ok') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>

  <?php // Rohstoffkosten VOR dem Preis: was kostet uns die Rezeptur beim Lieferanten?
        // Zeigt je Zutat den guenstigsten Lieferanten-EK bei der groessten Bestellmenge und wo noch
        // kein Preis bekannt ist - dann steht dort "Preis anfragen".
        $rk = angebot_rohstoffkosten($id); if ($rk['zeilen']): $nfrk = fn($v,$d=2) => number_format($v, $d, ',', '.'); ?>
  <div class="bx-panel" style="margin-top:16px">
    <div style="margin-bottom:4px">Rohstoffkosten je Lieferant <span class="muted" style="font-size:12px">&ndash; Grundlage f&uuml;r deinen Preis</span></div>
    <div class="muted" style="font-size:12px;margin-bottom:10px">G&uuml;nstigster Lieferant bei der gr&ouml;&szlig;ten angebotenen Menge. Wo kein Preis steht, erst beim Lieferanten anfragen.</div>
    <?php // Alternative: statt der Einzel-Rohstoffe das ganze Produkt fremdfertigen lassen.
          $angRez = all("SELECT DISTINCT r.id, r.name, r.darreichungsform FROM angebot_position ap
                         JOIN rezeptur r ON r.id=ap.rezeptur_id WHERE ap.angebot_id=? AND ap.rezeptur_id IS NOT NULL AND ap.rezeptur_id>0 ORDER BY r.name", [(int)$id]);
          if ($angRez): ?>
    <div style="border:1px solid var(--line);border-radius:8px;padding:10px 12px;margin-bottom:12px">
      <div style="margin-bottom:6px">Oder das ganze Produkt fremdfertigen lassen (Bulk):</div>
      <?php foreach ($angRez as $ar): $arf = anfrage_formen()[$ar['darreichungsform']] ?? $ar['darreichungsform']; ?>
        <div class="bx-row" style="justify-content:space-between;align-items:center;gap:12px;padding:4px 0">
          <div><?= h($ar['name']) ?> <span class="muted" style="font-size:12px">· <?= h($arf) ?></span></div>
          <div style="display:flex;gap:8px;align-items:center">
            <?= anfrage_produkt_badge((int)$ar['id']) ?>
            <?= anfrage_produkt_button((int)$ar['id'], (string)$ar['name'], (string)$arf, 'Fertigprodukt anfragen') ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Rohstoff</th><th class="bx-num">Ben&ouml;tigt</th><th class="bx-num">EK / Einheit</th><th>Lieferant</th><th class="bx-num">Kosten</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php require_once BX_ROOT . '/core/lieferant_unterlagen.php';   // Paket 12.11.2026 Teil 4: Hinweis „Unterlagen fehlen“ (kalkulieren erlaubt, bestellen erst mit Unterlagen)
      foreach ($rk['zeilen'] as $z): ?>
        <tr>
          <td><a href="?p=rohstoff&id=<?= (int)$z['item_id'] ?>"><?= h($z['name']) ?></a></td>
          <td class="bx-num"><?= h($nfrk($z['bedarf'], $z['bedarf'] < 1 ? 3 : 1)) ?> <?= h($z['bezug']) ?></td>
          <td class="bx-num"><?= $z['ek'] !== null ? h($nfrk($z['ek'], 4)) . ' &euro;' : '<span style="color:#8f231b">&ndash;</span>' ?></td>
          <td><?= $z['lieferant'] !== '' ? h($z['lieferant']) . (!empty($z['lieferant_id']) ? lu_badge((int)$z['lieferant_id'], (int)$z['item_id']) : '') : '<span class="muted">&ndash;</span>' ?></td>
          <td class="bx-num"><?= $z['kosten'] !== null ? h($nfrk($z['kosten'])) . ' &euro;' : '<span class="muted">?</span>' ?></td>
          <td><?= !empty($z['abgelaufen']) ? '<span style="color:#8f231b;font-size:12px">Preis abgelaufen seit ' . h(date('d.m.Y', strtotime((string)$z['abgelaufen']))) . ' - neu anfragen</span>'
                  : ((($z['ek_quelle'] ?? '') === 'ek' ? bx_badge('EK am Rohstoff', 'info') : anfrage_badge((int)$z['item_id']))
                     . (!empty($z['gueltig_bis']) ? '<div>' . pg_anzeige($z['gueltig_bis']) . '</div>' : '')
                     . (!empty($z['abgelaufen_ignoriert']) ? '<div class="muted" style="font-size:11px">abgelaufene Preise anderer Lieferanten nicht genutzt</div>' : '')) ?></td>
          <td style="text-align:right"><button type="button" class="btn btn-ghost btn-sm" data-name="<?= h($z['name']) ?>" data-menge="<?= h(rtrim(rtrim(number_format((float)$z['bedarf'], 3, '.', ''), '0'), '.')) ?>" data-einheit="<?= h($z['bezug']) ?>"<?= !empty($z['abgelaufen']) && !empty($z['lieferant_id']) ? ' data-lieferant="' . (int)$z['lieferant_id'] . '" data-staffel="' . h(implode(', ', array_map(fn($x) => rtrim(rtrim(number_format((float)$x, 3, '.', ''), '0'), '.'), array_column(all("SELECT DISTINCT menge_ab FROM lieferant_preis WHERE item_id=? AND lieferant_id=? AND menge_ab>0 ORDER BY menge_ab", [(int)$z['item_id'], (int)$z['lieferant_id']]), 'menge_ab')))) . '"' : '' ?> onclick="bxAnfrageOeffnen(<?= (int)$z['item_id'] ?>,this)"><?= !empty($z['abgelaufen']) ? 'neu anfragen' : 'Preis anfragen' ?></button></td>
        </tr>
      <?php endforeach; ?>
        <tr style="font-weight:600"><td colspan="4">Rohstoffkosten gesamt (gr&ouml;&szlig;te Menge)</td><td class="bx-num"><?= h($nfrk($rk['summe'])) ?> &euro;</td><td colspan="2"></td></tr>
      </tbody>
    </table></div>
    <?php if (!empty($rk['unsicher'])): ?>
      <div style="margin-top:10px;color:#8f231b;font-size:13px">Summe unsicher: <?= (int)$rk['unsicher'] ?> Rohstoff(e) nur mit abgelaufenem Preis &ndash; neu anfragen, bevor du darauf ein Angebot baust.</div>
    <?php endif; ?>
    <?php if ($rk['ohne_preis'] > 0): ?>
      <div style="margin-top:10px;color:#8f231b;font-size:13px"><?= (int)$rk['ohne_preis'] ?> Rohstoff(e) ohne Lieferantenpreis &ndash; bitte zuerst anfragen, sonst ist die Kalkulation unvollst&auml;ndig.</div>
    <?php endif; ?>
  </div>
  <?php anfrage_modal(lieferanten_fuer_anfrage('rohstoff'), '?p=angebot&id=' . (int)$id); ?>
  <?php endif; ?>
</div>
</div>

<script>
(function(){
  var sel = document.getElementById('addTyp'); if (!sel) return;
  function upd(){
    document.querySelectorAll('[data-add]').forEach(function(f){ f.style.display = (f.getAttribute('data-add') === sel.value) ? '' : 'none'; });
  }
  sel.addEventListener('change', upd); upd();
})();
function nf(x,d){ return x.toLocaleString('de-DE',{minimumFractionDigits:d,maximumFractionDigits:d}); }
function posRecalc(){
  var netto=0, ekges=0;
  document.querySelectorAll('.posrow').forEach(function(r){
    var m=parseFloat((r.querySelector('.p_menge').value||'').replace(',','.'))||0;
    var p=parseFloat((r.querySelector('.p_preis').value||'').replace(',','.'))||0;
    var ek=parseFloat((r.querySelector('.p_ek').value||'').replace(',','.'))||0;
    var g=m*p, mg=(p-ek)*m;
    r.querySelector('.c_ek').textContent = ek?nf(ek,2)+' €':'–';
    r.querySelector('.c_marge').textContent = (p&&ek)?nf(mg,2)+' €':'–';
    r.querySelector('.c_ges').textContent = g?nf(g,2)+' €':'–';
    netto+=g; ekges+=m*ek;
  });
  document.getElementById('sumNetto').textContent=nf(netto,2)+' €';
  var marge=netto-ekges, pct=netto>0?marge/netto*100:0;
  document.getElementById('intMarge').textContent='Intern: EK '+nf(ekges,2)+' € · Marge '+nf(marge,2)+' € ('+nf(pct,0)+' %)';
}
(function(){
  document.getElementById('addPos').addEventListener('click',function(){
    var tr=document.createElement('tr'); tr.className='posrow';
    tr.innerHTML='<td><input type="text" name="p_bez[]">'
      +'<textarea name="p_besch[]" class="p_besch" rows="2" placeholder="Beschreibung / Rezeptur (optional)"></textarea>'
      +'<input type="hidden" name="p_art[]" value=""><input type="hidden" name="p_quelle[]" value="manuell"><?= $istDL ? '<label style=\"display:inline-flex;align-items:center;gap:4px;font-size:11px;margin-top:4px\" class=\"muted\">Variante <input type=\"text\" name=\"p_gruppe[]\" maxlength=\"1\" placeholder=\"–\" title=\"Varianten-Buchstabe A/B/C … (leer = Basis)\" style=\"width:44px;text-align:center;text-transform:uppercase\"></label>' : '<input type=\"hidden\" name=\"p_gruppe[]\" value=\"\">' ?><input type="hidden" name="p_kspez[]" class="p_kspez" value="0"><input type="hidden" name="p_blanko[]" class="p_blanko" value="0"><input type="hidden" name="p_ek[]" class="p_ek" value="0">'
      +'<input type="hidden" name="p_rez[]" value=""><input type="hidden" name="p_vid[]" value=""></td>'
      +'<td><input type="number" step="1" min="0" name="p_stk[]" class="p_stk" data-unit="" placeholder="&ndash;" style="width:100%;text-align:right"></td>'
      +'<td><input type="number" step="0.001" name="p_menge[]" class="p_menge"></td>'
      +'<td><input type="text" name="p_einheit[]" value="Stk" list="angEinhListe"></td>'
      +'<td><input type="number" step="0.0001" min="0" name="p_preis[]" class="p_preis"></td>'
      +'<td><select name="p_mwst[]" style="width:100%"><?php foreach (mwst_saetze() as $ms): ?><option value="<?= (int)$ms ?>"<?= (int)$ms === (int)mwst_normalisieren(angebot_ust_satz($kid)) ? ' selected' : '' ?>><?= (int)$ms ?> %</option><?php endforeach; ?></select></td>'
      +'<td class="bx-num c_ek">–</td><td class="bx-num c_marge">–</td><td class="bx-num c_ges">–</td>'
      +'<td><button type="button" class="btn btn-ghost btn-sm">×</button></td>';
    tr.querySelector('button').addEventListener('click',function(){tr.remove();posRecalc();});
    tr.querySelectorAll('input').forEach(function(i){i.addEventListener('input',posRecalc);});
    document.getElementById('posrows').appendChild(tr);
  });
  document.querySelectorAll('#postab input').forEach(function(i){i.addEventListener('input',posRecalc);});
  posRecalc();
  // Inhalt (Stueck je Packung) -> Groessen-Baustein im Beschreibungstext derselben Zeile mitziehen,
  // damit der Kunde nicht "69 Kapseln" liest, waehrend im Inhalt schon 60 steht.
  function syncInhalt(inp){
    var unit=(inp.getAttribute('data-unit')||'').trim(); if(!unit) return;
    var val=(inp.value||'').trim(); if(val==='') return;
    var row=inp.closest('.posrow'); var ta=row&&row.querySelector('.p_besch'); if(!ta||!ta.value) return;
    var esc=unit.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
    var re=new RegExp('\\b\\d[\\d.,]*\\s*'+esc+'\\b');
    if(re.test(ta.value)) ta.value=ta.value.replace(re, val+' '+unit);
  }
  document.querySelectorAll('#postab .p_stk').forEach(function(i){ i.addEventListener('change',function(){syncInhalt(i);}); });
})();
</script>

<?php // PDF-Vorschau direkt auf der Seite (wie in v3): zeigt genau das ausgelieferte PDF –
      // entweder das generierte bulkify-PDF oder das nachgereichte v3-Original. Das PDF wird
      // erst erzeugt, wenn das Panel offen ist (lazy), damit das Bearbeiten schnell bleibt. ?>
<details class="bx-panel" id="angPdfVorschau" open style="margin-top:16px">
  <summary style="cursor:pointer">PDF-Vorschau<span class="muted" style="font-weight:400;font-size:13px"> &middot; genau das, was der Kunde als Angebot bekommt</span></summary>
  <div style="margin-top:12px">
    <div class="bx-row" style="gap:8px;margin-bottom:8px;align-items:center;flex-wrap:wrap">
      <a class="btn btn-ghost btn-sm" target="_blank" href="?p=angebot_pdf&id=<?= (int)$id ?>">&#8681; In neuem Tab &ouml;ffnen</a>
      <button type="button" class="btn btn-ghost btn-sm" id="angPdfReload">Neu laden</button>
      <span class="muted" style="font-size:12px">Nach dem Speichern von Positionen hier neu laden, um die &Auml;nderung zu sehen.</span>
    </div>
    <iframe id="angPdfFrame" data-src="?p=angebot_pdf&id=<?= (int)$id ?>" title="Angebots-PDF Vorschau" style="width:100%;height:80vh;border:1px solid var(--line);border-radius:8px;background:#fff"></iframe>
  </div>
</details>
<script>
(function(){
  var d=document.getElementById('angPdfVorschau'); if(!d) return;
  var f=document.getElementById('angPdfFrame'); if(!f) return;
  var src=f.getAttribute('data-src');
  function load(){ f.src = src + '&_=' + Date.now(); }         // Cache umgehen, damit "Neu laden" wirklich neu baut
  function ensure(){ if(d.open && !f.getAttribute('src')) load(); }
  d.addEventListener('toggle', ensure);
  var rb=document.getElementById('angPdfReload'); if(rb) rb.addEventListener('click', function(){ d.open=true; load(); });
  ensure();
})();
</script>
<?php // Gestylte Bestaetigung fuer folgenreiche Aktionen (v. a. "An Kunden senden"). Ersetzt das native
      // confirm(), das manche Browser unterdruecken ("Weitere Dialoge dieser Seite verhindern") - dann
      // liefe die Aktion ohne Rueckfrage. Der eigene Dialog ist nicht unterdrueckbar. Greift auf jedes
      // Element mit data-confirm="...". Nach dem OK wird das Formular mit demselben Button abgeschickt. ?>
<script>
(function(){
  function dialog(text, onOk, okText){
    var ov=document.createElement('div');
    ov.style.cssText='position:fixed;inset:0;z-index:99999;background:rgba(18,20,23,.5);display:flex;align-items:center;justify-content:center;padding:16px';
    var p=document.createElement('div');
    p.style.cssText='background:var(--panel,#fff);color:var(--text,#1b241a);border:1px solid var(--line,#e2e5ea);border-radius:14px;max-width:440px;width:100%;padding:22px 22px 18px;box-shadow:0 24px 70px rgba(0,0,0,.35)';
    var t=document.createElement('div'); t.textContent=text; t.style.cssText='font-size:15px;line-height:1.45;margin-bottom:18px';
    var row=document.createElement('div'); row.style.cssText='display:flex;gap:10px;justify-content:flex-end';
    var no=document.createElement('button'); no.className='btn btn-ghost'; no.type='button'; no.textContent='Abbrechen';
    var yes=document.createElement('button'); yes.className='btn btn-primary'; yes.type='button'; yes.textContent=okText||'Ja, senden';
    row.appendChild(no); row.appendChild(yes); p.appendChild(t); p.appendChild(row); ov.appendChild(p); document.body.appendChild(ov);
    yes.focus();
    function zu(){ try{ov.remove();}catch(e){} document.removeEventListener('keydown',esc,true); }
    function esc(e){ if(e.key==='Escape'){e.preventDefault();zu();} }
    document.addEventListener('keydown',esc,true);
    no.addEventListener('click',zu);
    ov.addEventListener('click',function(e){ if(e.target===ov) zu(); });
    yes.addEventListener('click',function(){ zu(); onOk(); });
  }
  document.addEventListener('click', function(e){
    var b=e.target.closest('[data-confirm]'); if(!b) return;
    if(b.__ok) return;                       // bereits bestaetigt -> normal durchlassen
    e.preventDefault(); e.stopImmediatePropagation();
    dialog(b.getAttribute('data-confirm'), function(){
      b.__ok=true;
      var f=b.form||b.closest('form'); if(!f) return;
      if(f.requestSubmit) f.requestSubmit(b.type==='submit'?b:undefined);   // erhaelt den Submitter (z. B. und_senden)
      else { if(b.name){var h=document.createElement('input');h.type='hidden';h.name=b.name;h.value=b.value;f.appendChild(h);} f.submit(); }
    }, b.getAttribute('data-confirm-ok'));   // eigener OK-Text je Knopf (z. B. „Ja, freigeben“)
  }, true);
})();
</script>
<?php endif;
if (!$neu && !empty($a['kunde_id'])) { echo kunde_nachricht_panel((int)$a['kunde_id'], 0, (int)$id); kunde_nachricht_gelesen((int)$a['kunde_id'], 0, (int)$id, 'team'); }
render_footer();
