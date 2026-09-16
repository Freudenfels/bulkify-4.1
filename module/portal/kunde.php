<?php
// Kundenportal – eigene, kundenfreundliche Ansicht per Magic-Link (Token). Keine internen Zahlen (EK/Marge).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/dokument_ui.php';

$token = preg_replace('/[^a-f0-9]/', '', $_GET['token'] ?? '');
agb_seed_wenn_leer();   // AGB-Entwurf anlegen, solange keine Fassung existiert

// Logout aus dem Kundenportal (E-Mail/Passwort-Session beenden).
if (($_GET['v'] ?? '') === 'logout') { unset($_SESSION['portal_kid']); header('Location: ?p=portal_login&abgemeldet=1'); exit; }

$k = $token ? one("SELECT * FROM kunden WHERE portal_token=?", [$token]) : null;
// Zugang auch per Kunden-Login (E-Mail+Passwort): Session-basiert. Magic-Link bleibt Backup.
if (!$k && !empty($_SESSION['portal_kid'])) $k = one("SELECT * FROM kunden WHERE id=?", [(int)$_SESSION['portal_kid']]);
if ($k) { $_SESSION['portal_kid'] = (int)$k['id']; if (!empty($k['portal_token'])) $token = (string)$k['portal_token']; }

// Erstzugang abschliessen: E-Mail + Passwort setzen und fehlende Stammdaten ergaenzen.
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'konto_einrichten') {
    $email = trim(mb_strtolower((string)($_POST['email'] ?? '')));
    $pw  = (string)($_POST['passwort'] ?? '');
    $pw2 = (string)($_POST['passwort2'] ?? '');
    $err = '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Bitte eine gültige E-Mail-Adresse eingeben.';
    elseif (strlen($pw) < 8) $err = 'Das Passwort muss mindestens 8 Zeichen haben.';
    elseif ($pw !== $pw2)    $err = 'Die beiden Passwörter stimmen nicht überein.';
    elseif ((int) scalar("SELECT COUNT(*) FROM kunden WHERE LOWER(email)=? AND id<>?", [$email, (int)$k['id']]) > 0)
        $err = 'Diese E-Mail-Adresse wird bereits verwendet.';
    if ($err === '') {
        q("UPDATE kunden SET email=?, ansprechpartner=COALESCE(NULLIF(?,''), ansprechpartner), telefon=COALESCE(NULLIF(?,''), telefon) WHERE id=?",
          [$email, trim((string)($_POST['ansprechpartner'] ?? '')), trim((string)($_POST['telefon'] ?? '')), (int)$k['id']]);
        kunde_passwort_setzen((int)$k['id'], $pw);
        $_SESSION['portal_kid'] = (int)$k['id'];
        log_aktivitaet('kunde', (int)$k['id'], 'kunde', 'Portal-Konto eingerichtet (E-Mail + Passwort gesetzt).', 'kunde');
        header('Location: ?p=portal&token=' . (string)($k['portal_token'] ?? '') . '&eingerichtet=1'); exit;
    }
    header('Location: ?p=portal&token=' . (string)($k['portal_token'] ?? '') . '&setupfehler=' . urlencode($err)); exit;
}

// Angebot bestätigen (Kundenaktion) -> löst Auftrag + Rechnung aus
// Angebot aus POSITIONEN annehmen (kein Produkt/keine Matrix) – hier entsteht das Produkt.
// Verbindliche Annahme: ohne gesetzten Haken und ohne Namen passiert nichts. Der Name gilt als
// Unterschrift und wird mit Zeitpunkt gespeichert – so ist belegt, wer wann freigegeben hat.
$freigabeName = function(): ?string {
    if (($_POST['bestaetigt'] ?? '') !== '1') return null;
    if (agb_aktuell() && ($_POST['agb'] ?? '') !== '1') return null;   // AGB muessen akzeptiert sein
    $n = trim((string)($_POST['freigabe_name'] ?? ''));
    return $n === '' ? null : mb_substr($n, 0, 190);
};
// Upsell aus dem Bestätigen-Popup: wenn der Kunde „Labortest dazubuchen" angehakt hat, legen wir nach der
// Annahme eine Dienstleistungsanfrage (Labortest) an, bezogen auf das Produkt des bestätigten Angebots.
function portal_labortest_upsell(array $kunde, int $angebotId): void {
    if (empty($_POST['labortest']) || empty($kunde['portal_dienstleistung'])) return;
    $pid = (int) scalar("SELECT produkt_id FROM auftrag WHERE angebot_id=? ORDER BY id DESC LIMIT 1", [$angebotId]);
    if (!$pid) return;
    q("INSERT INTO portal_anfrage (nummer,kunde_id,typ,dienstleistung_typ,betreff,status) VALUES (?,?, 'dienstleistung','labortest','Labortest / Analyse','neu')",
      [naechste_nummer('PAF'), (int)$kunde['id']]);
    $aid = insert_id();
    q("INSERT INTO portal_anfrage_pos (anfrage_id,produkt_id,sort) VALUES (?,?,0)", [$aid, $pid]);
    log_aktivitaet('kunde', (int)$kunde['id'], 'kunde', 'Labortest zum bestätigten Angebot dazugebucht (Upsell).', 'anfrage');
}
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'angebot_annehmen') {
    $aid = (int)($_POST['angebot_id'] ?? 0);
    $ang = $aid ? one("SELECT id FROM angebot WHERE id=? AND kunde_id=? AND status='gesendet'", [$aid, (int)$k['id']]) : null;
    $name = $freigabeName();
    if ($ang && $name === null) { header('Location: ?p=portal&token=' . $token . '&v=meine_anfragen&freigabefehlt=1'); exit; }
    if ($ang) {
        q("UPDATE angebot SET freigabe_name=?, freigabe_am=UTC_TIMESTAMP(), agb_version=? WHERE id=?", [$name, agb_version(), $aid]);
        $neuAuftrag = auftrag_aus_positionen($aid, preg_replace('/[^A-Z]/', '', strtoupper((string)($_POST['gruppe'] ?? ''))));
        portal_labortest_upsell($k, $aid);
        if (mail_bereit()) nach_antwort(fn() => mail_angebot_angenommen($aid, $neuAuftrag));
    }
    header('Location: ?p=portal&token=' . $token . '&v=bestellungen&bestaetigt=1'); exit;
}
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'bestaetigen') {
    $aid = (int)($_POST['angebot_id'] ?? 0); $sid = (int)($_POST['staffel'] ?? 0);
    $ang = $aid ? one("SELECT * FROM angebot WHERE id=? AND kunde_id=?", [$aid, (int)$k['id']]) : null;
    $name = $freigabeName();
    if ($ang && $name === null) { header('Location: ?p=portal&token=' . $token . '&v=meine_anfragen&freigabefehlt=1'); exit; }
    if ($ang && $ang['status'] === 'gesendet' && $sid > 0) {
        q("UPDATE angebot SET freigabe_name=?, freigabe_am=UTC_TIMESTAMP(), agb_version=? WHERE id=?", [$name, agb_version(), $aid]);
        q("UPDATE angebot_staffel SET bestaetigt=0 WHERE angebot_id=?", [$aid]);
        q("UPDATE angebot_staffel SET bestaetigt=1 WHERE id=? AND angebot_id=?", [$sid, $aid]);
        q("UPDATE angebot SET status='bestaetigt' WHERE id=?", [$aid]);
        log_aktivitaet('kunde', (int)$k['id'], 'kunde', 'Angebot ' . $ang['nummer'] . ' im Portal verbindlich bestätigt durch ' . $name . '.', 'angebot', 'angebot', $aid);
        $neuAuftrag = auftrag_aus_angebot($aid);
        portal_labortest_upsell($k, $aid);
        if (mail_bereit()) nach_antwort(fn() => mail_angebot_angenommen($aid, $neuAuftrag));
    }
    header('Location: ?p=portal&token=' . $token . '&v=bestellungen&ok=1'); exit;
}

// Etikett-Design zum Auftrag hochladen (Kunde) -> Team informieren, dass die Etiketten bestellt werden können.
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'etikett_upload') {
    $aid = (int)($_POST['auftrag_id'] ?? 0);
    if ($aid && (int) scalar("SELECT kunde_id FROM auftrag WHERE id=?", [$aid]) === (int)$k['id'] && etikett_upload($aid)) {
        log_aktivitaet('kunde', (int)$k['id'], 'kunde', 'Etikett-Design hochgeladen – Etiketten können bestellt werden.', 'auftrag', 'auftrag', $aid);
        if (mail_bereit()) nach_antwort(fn() => mail_team_etikett_hochgeladen($aid));
    }
    header('Location: ?p=portal&token=' . $token . '&v=bestellung&aid=' . $aid . '&etikett=1'); exit;
}
// Etikett-Design herunterladen (nur eigener Auftrag)
if ($k && ($_GET['v'] ?? '') === 'etikett_datei') {
    $aid = (int)($_GET['aid'] ?? 0);
    $ok = $aid && (int) scalar("SELECT kunde_id FROM auftrag WHERE id=?", [$aid]) === (int)$k['id'];
    $d = $ok ? etikett_datei($aid) : null;
    $pf = $d ? BX_UPLOADS . '/' . basename((string)$d['datei']) : '';
    if (!$d || !is_file($pf)) { http_response_code(404); echo 'Nicht gefunden.'; exit; }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($d['datei_orig'] ?: 'etikett')) . '"');
    header('Content-Length: ' . filesize($pf));
    readfile($pf); exit;
}

// Produktinformationsblatt (PIB) zum eigenen Auftrag herunterladen – Grundlage für die Etikettengestaltung.
if ($k && ($_GET['v'] ?? '') === 'pib') {
    $aid = (int)($_GET['aid'] ?? 0);
    $pid = $aid ? (int) scalar("SELECT produkt_id FROM auftrag WHERE id=? AND kunde_id=?", [$aid, (int)$k['id']]) : 0;
    require_once BX_ROOT . '/core/pdf_pib.php';
    if (!$pid || !pib_ausliefern($pid, 'Produktinfo-' . $aid)) { http_response_code(404); echo 'Produktinformationsblatt nicht verfügbar.'; }
    exit;
}

// Angebot ablehnen (mit Begründung) -> Status zurück, Team überarbeitet
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'angebot_ablehnen') {
    $aid = (int)($_POST['angebot_id'] ?? 0);
    $ang = $aid ? one("SELECT * FROM angebot WHERE id=? AND kunde_id=?", [$aid, (int)$k['id']]) : null;
    if ($ang && $ang['status'] === 'gesendet') {
        q("UPDATE angebot SET status='abgelehnt', ablehnung_grund=? WHERE id=?", [trim($_POST['grund'] ?? ''), $aid]);
        log_aktivitaet('kunde', (int)$k['id'], 'kunde', 'Angebot ' . $ang['nummer'] . ' im Portal abgelehnt' . (trim($_POST['grund'] ?? '') !== '' ? ': ' . trim($_POST['grund']) : '.'), 'angebot', 'angebot', $aid);
    }
    header('Location: ?p=portal&token=' . $token . '&v=meine_anfragen&abgelehnt=1'); exit;
}

// Angebot: eine Matrix-Zelle (Stückzahl × Bestellmenge × Verpackung) verbindlich annehmen -> Auto-Kette
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'zelle_annehmen') {
    $aid = (int)($_POST['angebot_id'] ?? 0);
    $ang = $aid ? one("SELECT * FROM angebot WHERE id=? AND kunde_id=?", [$aid, (int)$k['id']]) : null;
    $name = $freigabeName();
    if ($ang && $name === null) { header('Location: ?p=portal&token=' . $token . '&v=meine_anfragen&freigabefehlt=1'); exit; }
    if ($ang && $ang['status'] === 'gesendet') {
        q("UPDATE angebot SET freigabe_name=?, freigabe_am=UTC_TIMESTAMP(), agb_version=? WHERE id=?", [$name, agb_version(), $aid]);
        $auf = auftrag_aus_zelle($aid, (int)($_POST['stueck'] ?? 0), (int)($_POST['verpackung_id'] ?? 0), (int)($_POST['bestellmenge'] ?? 0));
        if ($auf) {
            q("UPDATE angebot SET status='bestaetigt' WHERE id=?", [$aid]);
            log_aktivitaet('kunde', (int)$k['id'], 'kunde', 'Angebot ' . $ang['nummer'] . ' im Portal verbindlich bestätigt durch ' . $name . '.', 'angebot', 'angebot', $aid);
            portal_labortest_upsell($k, $aid);
            if (mail_bereit()) nach_antwort(fn() => mail_angebot_angenommen($aid, $auf));
            header('Location: ?p=portal&token=' . $token . '&v=bestellungen&ok=1'); exit;
        }
    }
    header('Location: ?p=portal&token=' . $token . '&v=meine_anfragen'); exit;
}
// Angebot aus der Kundenliste entfernen (Löschen = ausblenden)
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'angebot_loeschen') {
    $aid = (int)($_POST['angebot_id'] ?? 0);
    if ($aid) q("UPDATE angebot SET kunde_ausgeblendet=1 WHERE id=? AND kunde_id=?", [$aid, (int)$k['id']]);
    header('Location: ?p=portal&token=' . $token . '&v=angebote&geloescht=1'); exit;
}

// Pflichtprüfung für eine Rezepturanfrage: Produktname muss da sein UND entweder eine Idee (Text)
// oder mindestens eine Zutat mit Bezeichnung UND Menge. „Nur Menge ohne Text" reicht nicht.
$anfrageGueltig = function(): bool {
    if (trim((string)($_POST['produktname'] ?? '')) === '') return false;
    if (trim((string)($_POST['notiz'] ?? '')) !== '') return true;
    $bez = $_POST['w_bez'] ?? []; $wm = $_POST['w_menge'] ?? [];
    foreach ((array)$bez as $i => $b) {
        if (trim((string)$b) !== '' && trim((string)($wm[$i] ?? '')) !== '') return true;
    }
    return false;
};

// Neue Rezepturanfrage vom Kunden entgegennehmen
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'anfrage_senden') {
    if (!$anfrageGueltig()) { header('Location: ?p=portal&token=' . $token . '&v=anfrage&fehlt=1'); exit; }
    $form = in_array($_POST['form'] ?? '', ['kapsel','tablette','softgel','stick','pulver','fluessig'], true) ? $_POST['form'] : 'kapsel';
    q("INSERT INTO rezeptur_anfrage (nummer,kunde_id,darreichungsform,produktname,notiz,status) VALUES (?,?,?,?,?,'neu')",
      [naechste_nummer('RZA'), (int)$k['id'], $form, trim($_POST['produktname'] ?? '') ?: null, trim($_POST['notiz'] ?? '')]);
    $aid = insert_id();
    $bez = $_POST['w_bez'] ?? []; $wm = $_POST['w_menge'] ?? []; $we = $_POST['w_einheit'] ?? [];
    foreach ($bez as $i => $b) {
        $b = trim($b); if ($b === '') continue;
        q("INSERT INTO rezeptur_anfrage_wunsch (anfrage_id,bezeichnung,wunsch_menge,einheit,sort) VALUES (?,?,?,?,?)",
          [$aid, $b, trim($wm[$i] ?? ''), trim($we[$i] ?? 'mg'), $i]);
    }
    log_aktivitaet('kunde', (int)$k['id'], 'kunde', 'Neue Rezepturanfrage im Portal eingereicht.', 'anfrage', 'anfrage', $aid);
    if (mail_bereit()) nach_antwort(fn() => mail_kunde_anfrage_eingang('rezeptur', (int)$aid));
    // Gleich einen Rezepturentwurf entwickeln lassen – das Team findet ihn beim Öffnen der Anfrage
    // vor und muss nicht bei null anfangen. Der Kunde sieht davon nichts; es ist ein interner Entwurf.
    // Wichtig: gerechnet wird im Hintergrund (?p=ki_job). Die KI braucht bis zu einer Minute –
    // so lange darf der Kunde nicht auf seine Bestaetigung warten.
    require_once BX_ROOT . '/core/ki_job.php';
    ki_job_starten('rezeptur', (int)$aid);
    header('Location: ?p=portal&token=' . $token . '&v=anfrage&anfrage=1'); exit;
}

// Rezepturanfrage bearbeiten – nur solange noch nicht in Bearbeitung (status='neu') und Eigentum des Kunden.
// Anfrage löschen (Kundenaktion) – nur die eigene, und nur solange ihm noch KEIN Angebot vorliegt.
// „in Bearbeitung" reicht nicht als Sperre: Diesen Status setzt schon das Öffnen des Angebots-Editors,
// der Kunde merkt davon nichts. Erst ein gesendetes (oder bestätigtes) Angebot bindet ihn.
// Ein interner Entwurf wird beim Löschen nur von der Anfrage gelöst, nicht weggeworfen.
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'anfrage_loeschen') {
    $typ = ($_POST['anf_typ'] ?? '') === 'rezeptur' ? 'rezeptur' : 'portal';
    $aid = (int)($_POST['anf_id'] ?? 0);
    $weg = false;
    if ($typ === 'rezeptur') {
        // Solange wir keinen Vorschlag geschickt haben (= noch keine Rezeptur erzeugt)
        $r = $aid ? one("SELECT id, rezeptur_id FROM rezeptur_anfrage WHERE id=? AND kunde_id=?", [$aid, (int)$k['id']]) : null;
        if ($r && empty($r['rezeptur_id'])) {
            q("DELETE FROM rezeptur_anfrage_wunsch WHERE anfrage_id=?", [$aid]);
            q("DELETE FROM rezeptur_anfrage WHERE id=?", [$aid]);
            $weg = true;
        }
    } else {
        $p = $aid ? one("SELECT id, nummer FROM portal_anfrage WHERE id=? AND kunde_id=?", [$aid, (int)$k['id']]) : null;
        // Gesperrt erst, wenn dem Kunden ein Angebot vorliegt – Entwürfe zählen nicht.
        $raus = $p ? (int) scalar("SELECT COUNT(*) FROM angebot WHERE anfrage_id=? AND status<>'offen'", [$aid]) : 1;
        if ($p && $raus === 0) {
            q("UPDATE angebot SET anfrage_id=NULL, notiz=CONCAT(COALESCE(notiz,''), ' (Anfrage ', ?, ' vom Kunden gelöscht)') WHERE anfrage_id=?", [$p['nummer'], $aid]);
            q("DELETE FROM portal_anfrage_pos WHERE anfrage_id=?", [$aid]);
            q("DELETE FROM portal_anfrage WHERE id=?", [$aid]);
            $weg = true;
        }
    }
    if ($weg) log_aktivitaet('kunde', (int)$k['id'], 'kunde', 'Anfrage im Portal gelöscht.', 'anfrage');
    header('Location: ?p=portal&token=' . $token . '&v=meine_anfragen&' . ($weg ? 'geloescht=1' : 'loeschfehler=1')); exit;
}
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'anfrage_bearbeiten') {
    $aid = (int)($_POST['anfrage_id'] ?? 0);
    $an  = $aid ? one("SELECT * FROM rezeptur_anfrage WHERE id=? AND kunde_id=? AND status='neu'", [$aid, (int)$k['id']]) : null;
    if ($an && !$anfrageGueltig()) { header('Location: ?p=portal&token=' . $token . '&v=anfrage&edit=' . $aid . '&fehlt=1'); exit; }
    if ($an) {
        $form = in_array($_POST['form'] ?? '', ['kapsel','tablette','softgel','stick','pulver','fluessig'], true) ? $_POST['form'] : 'kapsel';
        q("UPDATE rezeptur_anfrage SET darreichungsform=?, produktname=?, notiz=? WHERE id=?",
          [$form, trim($_POST['produktname'] ?? '') ?: null, trim($_POST['notiz'] ?? ''), $aid]);
        q("DELETE FROM rezeptur_anfrage_wunsch WHERE anfrage_id=?", [$aid]);   // Wunsch-Zeilen ersetzen (eigene Anfrage)
        $bez = $_POST['w_bez'] ?? []; $wm = $_POST['w_menge'] ?? []; $we = $_POST['w_einheit'] ?? [];
        foreach ($bez as $i => $b) {
            $b = trim($b); if ($b === '') continue;
            q("INSERT INTO rezeptur_anfrage_wunsch (anfrage_id,bezeichnung,wunsch_menge,einheit,sort) VALUES (?,?,?,?,?)",
              [$aid, $b, trim($wm[$i] ?? ''), trim($we[$i] ?? 'mg'), $i]);
        }
        log_aktivitaet('kunde', (int)$k['id'], 'kunde', 'Rezepturanfrage ' . $an['nummer'] . ' im Portal bearbeitet.', 'anfrage', 'anfrage', $aid);
    }
    header('Location: ?p=portal&token=' . $token . '&v=anfrage&geaendert=1'); exit;
}

// Rezeptur-Vorschlag annehmen -> eingefroren (verbindlich)
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'rezeptur_annehmen') {
    $rid = (int)($_POST['rezeptur_id'] ?? 0);
    $rez = $rid ? one("SELECT * FROM rezeptur WHERE id=? AND kunde_id=? AND status='vorschlag'", [$rid, (int)$k['id']]) : null;
    $name = $freigabeName();
    if ($rez && $name === null) { header('Location: ?p=portal&token=' . $token . '&v=rezeptur&rid=' . $rid . '&freigabefehlt=1'); exit; }
    if ($rez) {
        q("UPDATE rezeptur SET status='eingefroren', freigabe_name=?, freigabe_am=UTC_TIMESTAMP(), agb_version=? WHERE id=?", [$name, agb_version(), $rid]);
        log_aktivitaet('kunde', (int)$k['id'], 'kunde', 'Rezeptur ' . $rez['nummer'] . ' verbindlich angenommen durch ' . $name . '.', 'rezeptur', 'rezeptur', $rid);
    }
    // Kann der Kunde Produkte anfragen, ist der nächste Schritt „prodanfrage" (Menge/Verpackung).
    // Ohne Produkt-Freischaltung führt das ins Leere (fällt auf „start" zurück) – dann zurück auf
    // die Rezeptur selbst, die jetzt als angenommen angezeigt wird (mit Erfolgshinweis).
    // Nach dem Annehmen der eigenen Rezeptur direkt zum Schritt „Produkt anfragen" (Menge/Verpackung) –
    // die Rezeptur ist damit immer als Produkt anfragbar, auch ohne Katalog-Recht.
    header('Location: ?p=portal&token=' . $token . '&v=prodanfrage&rid=' . $rid . '&freigegeben=1'); exit;
}

// Abruf aus einem Jahresvertrag/Kontingent: erzeugt einen Auftrag zum vereinbarten Preis.
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'kontingent_abruf') {
    $konId = (int)($_POST['kontingent_id'] ?? 0);
    $menge = (int)($_POST['menge'] ?? 0);
    // Nur eigene Kontingente – Fremdzugriff verhindern.
    if (!$konId || !scalar("SELECT id FROM kontingent WHERE id=? AND kunde_id=?", [$konId, (int)$k['id']])) {
        header('Location: ?p=portal&token=' . $token . '&v=kontingente&kfehler=' . urlencode('Vertrag nicht gefunden.')); exit;
    }
    $r = kontingent_abruf($konId, $menge);
    if (!empty($r['ok'])) { header('Location: ?p=portal&token=' . $token . '&v=kontingente&abgerufen=' . $menge); exit; }
    header('Location: ?p=portal&token=' . $token . '&v=kontingente&kfehler=' . urlencode((string)($r['fehler'] ?? 'Abruf fehlgeschlagen.'))); exit;
}

// Jahresvertrag verbindlich abschließen: aus dem Jahresvertrags-Angebot ein Kontingent erzeugen
// (Status 'wartet_vertrag'). Braucht Häkchen + Name (verbindlicher als eine normale Bestellung).
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'jahresvertrag_abschliessen') {
    $aid  = (int)($_POST['angebot_id'] ?? 0);
    $name = trim((string)($_POST['freigabe_name'] ?? ''));
    $ok   = !empty($_POST['bestaetigt']);
    $ang  = $aid ? one("SELECT id FROM angebot WHERE id=? AND kunde_id=? AND jahresvertrag=1", [$aid, (int)$k['id']]) : null;
    if (!$ang || !$ok || $name === '') {
        header('Location: ?p=portal&token=' . $token . '&v=kontingente&kfehler=' . urlencode('Für den verbindlichen Abschluss fehlen Bestätigung und Name.')); exit;
    }
    q("UPDATE angebot SET freigabe_name=?, freigabe_am=UTC_TIMESTAMP(), agb_version=? WHERE id=?", [$name, agb_version(), $aid]);
    $r = kontingent_aus_angebot($aid, $name);
    if (!empty($r['ok'])) { header('Location: ?p=portal&token=' . $token . '&v=kontingente&jvok=1'); exit; }
    header('Location: ?p=portal&token=' . $token . '&v=kontingente&kfehler=' . urlencode((string)($r['fehler'] ?? 'Abschluss fehlgeschlagen.'))); exit;
}

// Unterschriebenen Jahresvertrag hochladen: Dokument ablegen + Kontingent auf 'wartet_freigabe'.
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'jahresvertrag_upload') {
    $konId = (int)($_POST['kontingent_id'] ?? 0);
    $kon = $konId ? one("SELECT id, status FROM kontingent WHERE id=? AND kunde_id=?", [$konId, (int)$k['id']]) : null;
    if (!$kon) { header('Location: ?p=portal&token=' . $token . '&v=kontingente&kfehler=' . urlencode('Vertrag nicht gefunden.')); exit; }
    if (empty($_FILES['vertrag']['tmp_name']) || !is_uploaded_file($_FILES['vertrag']['tmp_name'])) {
        header('Location: ?p=portal&token=' . $token . '&v=kontingente&kfehler=' . urlencode('Bitte die unterschriebene Datei wählen.')); exit;
    }
    $ext = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo((string)$_FILES['vertrag']['name'], PATHINFO_EXTENSION)));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
        header('Location: ?p=portal&token=' . $token . '&v=kontingente&kfehler=' . urlencode('Nur PDF oder Bild (PDF/JPG/PNG).')); exit;
    }
    if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
    $fn = 'jv_' . $konId . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['vertrag']['tmp_name'], BX_UPLOADS . '/' . $fn)) {
        header('Location: ?p=portal&token=' . $token . '&v=kontingente&kfehler=' . urlencode('Datei nicht gespeichert.')); exit;
    }
    q("DELETE FROM dokument WHERE objekt_typ='kontingent' AND objekt_id=? AND typ='jv_signiert'", [$konId]);
    q("INSERT INTO dokument (objekt_typ,objekt_id,typ,titel,datei,datei_orig,kunde_sichtbar) VALUES ('kontingent',?,?,?,?,?,1)",
      [$konId, 'jv_signiert', 'Unterschriebener Jahresvertrag', $fn, mb_substr((string)($_FILES['vertrag']['name'] ?? 'vertrag'), 0, 190)]);
    kontingent_status($konId, 'wartet_freigabe');
    log_aktivitaet('kunde', (int)$k['id'], 'kunde', 'Unterschriebenen Jahresvertrag hochgeladen – wartet auf Freigabe.', 'kontingent');
    header('Location: ?p=portal&token=' . $token . '&v=kontingente&jvupload=1'); exit;
}

// Rezeptur-Vorschlag ablehnen (Pflicht-Grund) -> Status abgelehnt, Team überarbeitet
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'rezeptur_ablehnen') {
    $rid   = (int)($_POST['rezeptur_id'] ?? 0);
    $grund = trim($_POST['grund'] ?? '');
    $rez   = $rid ? one("SELECT * FROM rezeptur WHERE id=? AND kunde_id=? AND status='vorschlag'", [$rid, (int)$k['id']]) : null;
    if ($rez && $grund !== '') {
        q("UPDATE rezeptur SET status='abgelehnt', ablehnung_grund=? WHERE id=?", [$grund, $rid]);
        // Verknüpfte Anfrage nachziehen, damit das Team in der Anfragen-Liste sofort sieht: Vorschlag abgelehnt → überarbeiten.
        q("UPDATE rezeptur_anfrage SET status='ueberarbeiten' WHERE rezeptur_id=? AND kunde_id=?", [$rid, (int)$k['id']]);
        log_aktivitaet('kunde', (int)$k['id'], 'kunde', 'Rezeptur-Vorschlag ' . $rez['nummer'] . ' im Portal abgelehnt: ' . $grund, 'rezeptur', 'rezeptur', $rid);
        header('Location: ?p=portal&token=' . $token . '&v=rezeptur&rid=' . $rid . '&abgelehnt=1'); exit;
    }
    header('Location: ?p=portal&token=' . $token . '&v=rezeptur&rid=' . $rid . '&ablehngrund=1'); exit;
}

// Produktanfrage (aus dem Katalog): Stück/Verpackung/Menge
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'produkt_anfrage') {
    // Der Kunde wählt entweder ein fertiges Produkt („p<id>") ODER eine eigene Rezeptur („r<id>"),
    // für die es noch kein Produkt gibt. Aus der Rezeptur entsteht das Produkt erst mit dem Angebot.
    $wahl = trim((string)($_POST['produkt_id'] ?? ''));
    $pid = 0; $rezWahl = 0;
    if (preg_match('/^r(\d+)$/', $wahl, $m)) {
        $rezWahl = (int)$m[1];
        // nur eigene angenommene oder freigegebene Katalog-Rezepturen
        if (!scalar("SELECT id FROM rezeptur WHERE id=? AND ((kunde_id=? AND status IN ('eingefroren','freigegeben')) OR (kunde_id IS NULL AND status='freigegeben'))",
                    [$rezWahl, (int)$k['id']])) $rezWahl = 0;
    } else {
        $pid = (int) preg_replace('/\D/', '', $wahl);
    }
    // Eigene/freigegebene Rezeptur: braucht nur das Rezeptur-Recht. Katalog-Produkt: braucht das Produkt-Recht.
    $erlaubt = ($rezWahl && !empty($k['portal_rezeptur'])) || ($pid && !empty($k['portal_produkte']));
    if ($erlaubt) {
        // Darreichungsform des gewählten Produkts/der Rezeptur -> „Anzahl pro Verpackung" ist Stück oder Füllmenge.
        $form = $rezWahl
            ? (string) scalar("SELECT darreichungsform FROM rezeptur WHERE id=?", [$rezWahl])
            : (string) scalar("SELECT r.darreichungsform FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id=?", [$pid]);
        $istFuell = in_array($form, ['pulver','granulat','fluessig','gel'], true);
        $vtyp = trim($_POST['verpackung_typ'] ?? '') ?: null;
        // Task 3: Staffel = mehrere Zeilen aus „Anzahl pro Verpackung" + „Menge VPE" (Anzahl Verpackungen).
        $anz = $_POST['st_anzahl'] ?? []; $vpe = $_POST['st_vpe'] ?? [];
        $zeilen = [];
        foreach ((array)$anz as $i => $a) {
            $aA = (int) round((float) str_replace(',', '.', (string)$a));            // Anzahl pro Verpackung
            $mV = (int) round((float) str_replace(',', '.', (string)($vpe[$i] ?? ''))); // Menge VPE (Packungen)
            if ($aA <= 0 && $mV <= 0) continue;
            $zeilen[] = ['anzahl' => $aA > 0 ? $aA : null, 'vpe' => $mV > 0 ? $mV : null];
        }
        // Fallback: Einzelfelder (falls ohne JS gesendet)
        if (!$zeilen) {
            $aA = (int) round((float) str_replace(',', '.', (string)($_POST['stueck'] ?? $_POST['fuellmenge_g'] ?? '0')));
            $mV = (int) round((float) str_replace(',', '.', (string)($_POST['menge'] ?? '0')));
            if ($aA > 0 || $mV > 0) $zeilen[] = ['anzahl' => $aA > 0 ? $aA : null, 'vpe' => $mV > 0 ? $mV : null];
        }
        if ($zeilen) {
            $mapAnzahl = fn($a) => $istFuell ? [null, $a] : [$a, null];   // -> [stueck, fuellmenge_g]
            [$hStk, $hFg] = $mapAnzahl($zeilen[0]['anzahl']);
            q("INSERT INTO portal_anfrage (nummer,kunde_id,typ,produkt_id,rezeptur_id,stueck,fuellmenge_g,verpackung_typ,menge,notiz,status) VALUES (?,?,?,?,?,?,?,?,?,?,'neu')",
              [naechste_nummer('PAF'), (int)$k['id'], 'produkt', $pid ?: null, $rezWahl ?: null, $hStk, $hFg, $vtyp, $zeilen[0]['vpe'], trim($_POST['notiz'] ?? '')]);
            $paf = insert_id();
            $sort = 0;
            foreach ($zeilen as $z) {
                [$stk, $fgv] = $mapAnzahl($z['anzahl']);
                q("INSERT INTO portal_anfrage_pos (anfrage_id,produkt_id,rezeptur_id,stueck,fuellmenge_g,verpackung_typ,menge,sort) VALUES (?,?,?,?,?,?,?,?)",
                  [$paf, $pid ?: null, $rezWahl ?: null, $stk, $fgv, $vtyp, $z['vpe'], $sort++]);
            }
            log_aktivitaet('kunde', (int)$k['id'], 'kunde', 'Produktanfrage im Portal gestellt' . (count($zeilen) > 1 ? ' (' . count($zeilen) . ' Staffeln)' : '') . '.', 'anfrage');
            if (mail_bereit()) nach_antwort(fn() => mail_kunde_anfrage_eingang('portal', (int)$paf));
        }
    }
    // Auf der Anfragenliste landen statt zurück im Katalog – dort sieht der Kunde seine Anfrage sofort stehen.
    header('Location: ?p=portal&token=' . $token . '&v=meine_anfragen&gesendet=1'); exit;
}
// Menge einer bestehenden Produktanfrage ÄNDERN (z. B. Kunde hat ein Angebot, will jetzt mehr Stück).
// Die Anfrage wird bearbeitet (keine neue), das vorliegende Angebot geht zurück in den Entwurf
// (verschwindet beim Kunden), das Team überarbeitet es. Nur solange NOCH KEIN Auftrag existiert.
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'produkt_anfrage_bearbeiten') {
    $paf = (int)($_POST['paf_id'] ?? 0);
    $p = $paf ? one("SELECT * FROM portal_anfrage WHERE id=? AND kunde_id=? AND typ='produkt'", [$paf, (int)$k['id']]) : null;
    // Bereits bestellt (Auftrag da)? Dann nicht mehr änderbar – der Kunde muss neu anfragen/nachbestellen.
    $hatAuftrag = $p ? (int) scalar("SELECT COUNT(*) FROM angebot a JOIN auftrag au ON au.angebot_id=a.id WHERE a.anfrage_id=? AND a.kunde_id=?", [$paf, (int)$k['id']]) : 1;
    if ($p && !$hatAuftrag) {
        // Darreichungsform bestimmt, ob die Packungsgröße eine Füllmenge (g/ml) oder eine Stückzahl ist.
        $form = (string) scalar("SELECT COALESCE(r.darreichungsform, r2.darreichungsform) FROM portal_anfrage pa
                     LEFT JOIN produkt pr ON pr.id=pa.produkt_id LEFT JOIN rezeptur r ON r.id=pr.rezeptur_id
                     LEFT JOIN rezeptur r2 ON r2.id=pa.rezeptur_id WHERE pa.id=?", [$paf]);
        $istFuell = form_ist_fuellmenge($form);
        // Staffelzeilen einlesen (Anzahl je Verpackung + Menge Verpackungen) – wie beim Anlegen.
        $anz = (array)($_POST['st_anzahl'] ?? []); $vpe = (array)($_POST['st_vpe'] ?? []);
        $zeilen = [];
        foreach ($anz as $i => $a) {
            $aA = (int) round((float) str_replace(',', '.', (string)$a));
            $mV = (int) round((float) str_replace(',', '.', (string)($vpe[$i] ?? '0')));
            if ($aA > 0 || $mV > 0) $zeilen[] = ['anzahl' => $aA ?: null, 'vpe' => $mV ?: null];
        }
        if ($zeilen) {
            $mapAnzahl = fn($a) => $istFuell ? [null, $a] : [$a, null];   // -> [stueck, fuellmenge_g]
            [$hStk, $hFg] = $mapAnzahl($zeilen[0]['anzahl']);
            q("UPDATE portal_anfrage SET stueck=?, fuellmenge_g=?, menge=?, notiz=?, status='neu' WHERE id=?",
              [$hStk, $hFg, $zeilen[0]['vpe'], trim($_POST['notiz'] ?? ''), $paf]);
            q("DELETE FROM portal_anfrage_pos WHERE anfrage_id=?", [$paf]);
            $sort = 0;
            foreach ($zeilen as $z) {
                [$stk, $fgv] = $mapAnzahl($z['anzahl']);
                q("INSERT INTO portal_anfrage_pos (anfrage_id,produkt_id,rezeptur_id,stueck,fuellmenge_g,verpackung_typ,menge,sort) VALUES (?,?,?,?,?,?,?,?)",
                  [$paf, $p['produkt_id'] ?: null, $p['rezeptur_id'] ?: null, $stk, $fgv, $p['verpackung_typ'], $z['vpe'], $sort++]);
            }
            // Vorliegende (noch nicht bestätigte) Angebote zu dieser Anfrage in den Entwurf zurücksetzen:
            // Sie verschwinden beim Kunden (Status 'offen' + Preise gesperrt), das Team überarbeitet sie.
            q("UPDATE angebot SET status='offen', preise_kunde=0 WHERE anfrage_id=? AND kunde_id=? AND status<>'bestaetigt'", [$paf, (int)$k['id']]);
            log_aktivitaet('kunde', (int)$k['id'], 'kunde', 'Menge einer Produktanfrage im Portal geändert – Angebot bitte überarbeiten.', 'anfrage', 'anfrage', $paf);
            if (mail_bereit()) nach_antwort(fn() => mail_kunde_anfrage_eingang('portal', (int)$paf));
        }
    }
    header('Location: ?p=portal&token=' . $token . '&v=meine_anfragen&geaendert=1'); exit;
}
// Rohstoffanfrage – MEHRERE Rohstoffe je Absendung; pro Rohstoff eine eigene Anfrage (=> je Rohstoff ein eigenes Angebot).
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'rohstoff_anfrage') {
    if ($k['portal_rohstoffe']) {
        $namen = $_POST['roh_name'] ?? []; $mengen = $_POST['roh_menge'] ?? []; $einh = $_POST['roh_einheit'] ?? [];
        $ziele = $_POST['roh_zielpreis'] ?? []; $notizen = $_POST['roh_notiz'] ?? [];
        $n = 0; $erstePaf = 0;
        foreach ($namen as $i => $nm) {
            $nm = trim((string)$nm); if ($nm === '') continue;
            $wm = (float) str_replace(',', '.', (string)($mengen[$i] ?? '0'));
            $we = in_array($einh[$i] ?? '', ['kg','g','t','Stück','L'], true) ? $einh[$i] : null;
            $zp = (float) str_replace(',', '.', (string)($ziele[$i] ?? '0'));
            $rid = (int) scalar("SELECT id FROM item WHERE kategorie='rohstoff' AND name=? LIMIT 1", [$nm]);   // Katalog-Treffer -> item-Id
            q("INSERT INTO portal_anfrage (nummer,kunde_id,typ,betreff,notiz,wunsch_menge,wunsch_einheit,rohstoff_id,zielpreis,status) VALUES (?,?, 'rohstoff', ?,?,?,?,?,?, 'neu')",
              [naechste_nummer('PAF'), (int)$k['id'], $nm, trim((string)($notizen[$i] ?? '')), $wm > 0 ? $wm : null, $wm > 0 ? $we : null, $rid ?: null, $zp > 0 ? $zp : null]);
            if (!$erstePaf) $erstePaf = insert_id();
            $n++;
        }
        if ($n) {
            log_aktivitaet('kunde', (int)$k['id'], 'kunde', $n . ' Rohstoffanfrage(n) im Portal gestellt.', 'anfrage');
            // Eine Eingangsbestätigung für die Absendung (Portal-Link zeigt alle Positionen).
            if (mail_bereit() && $erstePaf) nach_antwort(fn() => mail_kunde_anfrage_eingang('portal', (int)$erstePaf));
        }
    }
    header('Location: ?p=portal&token=' . $token . '&v=meine_anfragen&gesendet=1'); exit;
}
// Dienstleistungsanfrage (Freitext) – Typen/Labortest folgen separat
// Dienstleistungs-Typen (für Lohnhersteller). Labortest bezieht sich auf ein oder mehrere Produkte.
$DIENST_TYPEN = [
    'labortest'       => 'Labortest / Analyse',
    'abfuellung'      => 'Reine Abfüllung',
    'sourcing'        => 'Sourcing / Beschaffung',
    'konfektionierung'=> 'Konfektionierung / Verpackung',
    'lagerung'        => 'Lagerung / Fulfillment',
    'beratung'        => 'Beratung / Regulatorik',
    'sonstiges'       => 'Sonstiges',
];
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'dienstleistung_anfrage') {
    if ($k['portal_dienstleistung']) {
        $dtyp = array_key_exists($_POST['dienstleistung_typ'] ?? '', $DIENST_TYPEN) ? $_POST['dienstleistung_typ'] : 'sonstiges';
        $prodIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['dl_produkt'] ?? [])))));
        // Labortest MUSS sich auf mindestens ein Produkt beziehen.
        $ok = $dtyp !== 'labortest' || $prodIds;
        if ($ok) {
            $betreff = trim($_POST['betreff'] ?? '') ?: $DIENST_TYPEN[$dtyp];
            q("INSERT INTO portal_anfrage (nummer,kunde_id,typ,dienstleistung_typ,betreff,notiz,status) VALUES (?,?, 'dienstleistung', ?,?,?, 'neu')",
              [naechste_nummer('PAF'), (int)$k['id'], $dtyp, $betreff, trim($_POST['notiz'] ?? '')]);
            $aid = insert_id();
            if ($dtyp === 'labortest') {
                $sort = 0;
                foreach ($prodIds as $pid) {
                    // nur Produkte, die der Kunde sehen darf
                    if (scalar("SELECT id FROM produkt WHERE id=? AND status='aktiv' AND (exklusiv=0 OR kunde_id=?)", [$pid, (int)$k['id']]))
                        q("INSERT INTO portal_anfrage_pos (anfrage_id,produkt_id,sort) VALUES (?,?,?)", [$aid, $pid, $sort++]);
                }
            }
            log_aktivitaet('kunde', (int)$k['id'], 'kunde', 'Dienstleistungsanfrage (' . $DIENST_TYPEN[$dtyp] . ') im Portal gestellt.', 'anfrage');
            if (mail_bereit()) nach_antwort(fn() => mail_kunde_anfrage_eingang('portal', (int)$aid));
        }
    }
    header('Location: ?p=portal&token=' . $token . '&v=meine_anfragen&gesendet=1'); exit;
}

$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
$mg  = fn($x) => rtrim(rtrim(number_format((float)$x, 2, ',', '.'), '0'), ',');   // Zahl kompakt (für Angebots-Karte, global)

// Kopf-/Fußzeile des Portals (eigene, ohne internes Menü)
function portal_head(string $titel): void {
    echo "<!doctype html><html lang=\"de\"><head><meta charset=\"utf-8\">";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">";
    echo "<title>" . h($titel) . "</title><link rel=\"stylesheet\" href=\"assets/app.css?v=" . (int) @filemtime(BX_ROOT . '/public/assets/app.css') . "\">";
    echo "<style>"
       . ".pt-badge{display:inline-block;background:var(--lime);color:#10210f;border-radius:10px;padding:0 7px;font-size:12px;font-weight:600}"
       . ".pt-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:16px 0;max-width:760px}"
       . ".pt-card{background:var(--panel);border:1px solid var(--line);border-radius:var(--r);padding:14px 16px}"
       . ".pt-card .k{font-size:12px;color:var(--muted)}.pt-card .val{font-size:22px;font-weight:600;margin-top:4px}"
       // Einstiegs-Auswahl (Leeransicht): eine Karte je freigegebenem Anfrage-Typ
       . ".pt-choice{display:flex;flex-wrap:wrap;gap:12px;margin:14px 0 4px}"
       . ".pt-choice-card{flex:1 1 200px;min-width:180px;background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:16px;text-decoration:none;color:inherit;transition:border-color .15s}"
       . ".pt-choice-card:hover{border-color:var(--gruen);text-decoration:none}"
       . ".pt-choice-card .t{font-weight:600;font-size:15px}"
       . ".pt-choice-card .s{color:var(--muted);font-size:13px;margin-top:4px;line-height:1.45}"
       . ".pt-choice-go{display:inline-block;margin-top:10px;color:var(--gruen);font-weight:600;font-size:13px}"
       // Unterreiter (Typ-Filter): dezente Pillen, klar abgesetzt von den Hauptreitern darüber
       . ".pt-subtabs{display:flex;flex-wrap:wrap;gap:6px;border:none;margin:0 0 14px}"
       . ".pt-subtabs a{padding:4px 12px;font-size:13px;color:var(--muted);border:1px solid var(--line);border-radius:999px;background:transparent;line-height:1.6}"
       . ".pt-subtabs a:hover{color:var(--text);text-decoration:none;background:var(--panel-2)}"
       . ".pt-subtabs a.on{color:var(--dunkelgruen);background:var(--panel-2);border-color:var(--gruen);font-weight:600}"
       . ':root[data-theme="dark"] .pt-subtabs a.on{color:var(--lime)}</style>';
    echo "<script>(function(){try{var t=localStorage.getItem('bx-theme');if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>";
    echo "</head><body>";
}
function portal_foot(): void { echo (function_exists('bx_theme_script') ? bx_theme_script() : '') . (function_exists('bx_side_scroll_script') ? bx_side_scroll_script() : '') . (function_exists('bx_busy_script') ? bx_busy_script() : '') . "</body></html>"; }

if (!$k) {
    // Kein gültiger Token und keine Login-Session -> zur Kunden-Anmeldung.
    header('Location: ?p=portal_login'); exit;
}

// Interne Vorschau: ist ein Mitarbeiter (kein Lieferant) angemeldet und ruft das Portal auf,
// überspringen wir die Erstzugangs-Seite – so kann das Team das Kundenkonto ansehen, auch wenn
// der Kunde sein Passwort noch nicht gesetzt hat. Der echte Kunde (ohne interne Session) sieht sie.
$internVorschau = function_exists('is_logged_in') && is_logged_in() && function_exists('ist_lieferant') && !ist_lieferant();

// Erstzugang: solange kein Passwort gesetzt ist, muss der Kunde zuerst sein Konto einrichten
// (E-Mail + Passwort + fehlende Stammdaten). Danach greift das normale Portal.
if (empty($k['passwort']) && !$internVorschau) {
    $setupErr = (string)($_GET['setupfehler'] ?? '');
    portal_head('Konto einrichten · bulkify');
    echo '<div style="max-width:460px;margin:6vh auto;padding:0 16px">'
       . '<div style="text-align:center;margin-bottom:18px"><img src="assets/bulkify-logo-dark.png" alt="bulkify" style="height:38px"></div>'
       . '<div class="bx-panel"><h1 style="margin:0 0 4px;font-size:22px">Willkommen bei bulkify</h1>'
       . '<p class="bx-sub" style="margin-top:0">Bitte richten Sie einmalig Ihren Zugang ein: E-Mail und Passwort. Danach melden Sie sich künftig damit an.</p>';
    if ($setupErr !== '') echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:10px 14px;margin-bottom:12px">' . h($setupErr) . '</div>';
    echo '<form method="post">'
       . '<input type="hidden" name="aktion" value="konto_einrichten">'
       . '<div class="bx-field"><label>Firma</label><input type="text" value="' . h((string)($k['firma'] ?? '')) . '" disabled></div>'
       . '<div class="bx-field"><label>Ansprechpartner</label><input type="text" name="ansprechpartner" value="' . h((string)($k['ansprechpartner'] ?? '')) . '" placeholder="Vor- und Nachname"></div>'
       . '<div class="bx-field"><label>Telefon</label><input type="text" name="telefon" value="' . h((string)($k['telefon'] ?? '')) . '"></div>'
       . '<div class="bx-field"><label>E-Mail (Ihr Login)</label><input type="email" name="email" required value="' . h((string)($k['email'] ?? '')) . '" placeholder="name@firma.de" autocomplete="email"></div>'
       . '<div class="bx-field"><label>Passwort (mind. 8 Zeichen)</label><input type="password" name="passwort" required minlength="8" autocomplete="new-password"></div>'
       . '<div class="bx-field"><label>Passwort wiederholen</label><input type="password" name="passwort2" required minlength="8" autocomplete="new-password"></div>'
       . '<button class="btn btn-primary" type="submit" style="width:100%;margin-top:6px">Zugang einrichten &amp; anmelden</button>'
       . '</form></div></div>';
    portal_foot(); exit;
}

$kid = (int)$k['id'];
// Nährwerte je Einheit aus einer Rezeptur (aggregiert über die Wirkstoffe der Rohstoffe)
if (!function_exists('pt_naehr')) {
    function pt_naehr(int $rid): array {
        $n = [];
        foreach (all("SELECT z.menge_mg, iw.gehalt_prozent, na.name, na.nrv_wert, na.einheit
                      FROM rezeptur_zutat z JOIN item_wirkstoff iw ON iw.item_id=z.item_id
                      JOIN naehrstoff na ON na.id=iw.naehrstoff_id
                      WHERE z.rezeptur_id=? AND iw.gehalt_prozent IS NOT NULL", [$rid]) as $w) {
            $mgN = (float)$w['menge_mg'] * (float)$w['gehalt_prozent'] / 100;
            if (!isset($n[$w['name']])) $n[$w['name']] = ['name'=>$w['name'], 'mg'=>0.0, 'nrv'=>$w['nrv_wert'], 'einheit'=>$w['einheit']];
            $n[$w['name']]['mg'] += $mgN;
        }
        return array_values($n);
    }
}
// Angebote (offen zum Bestätigen + bereits bestätigte) – mit Produkt- & Verpackungsinfos wie in v3
$angebote = all("SELECT a.*, COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt_name, p.rezeptur_id, p.einheiten_pro_packung,
                        p.verpackung_id, p.verschluss_id, p.etikett_id, r.darreichungsform
                 FROM angebot a LEFT JOIN produkt p ON p.id=a.produkt_id LEFT JOIN rezeptur r ON r.id=p.rezeptur_id
                 WHERE a.kunde_id=? AND a.kunde_ausgeblendet=0
                   AND a.status <> 'offen'
                   AND a.preise_kunde = 1
                   -- Waisen-Angebote (Produkt/Rezeptur in v3 gelöscht) nicht im Kundenportal zeigen: kein Produkt UND
                   -- keine Rezeptur-Position => für den Kunden nicht handelbar. Intern (Cockpit) bleiben sie sichtbar.
                   AND (a.produkt_id IS NOT NULL
                        OR EXISTS (SELECT 1 FROM angebot_position ap WHERE ap.angebot_id=a.id AND ap.rezeptur_id IS NOT NULL AND ap.rezeptur_id>0))
                 ORDER BY a.angelegt DESC", [$kid]);
// Portal-Render ist schreibfrei (die POST-Handler oben sind bereits mit exit raus) -> Bestands-/Zeilen-Cache
// aktivieren, damit Verpackungs-/Preis-/Artikel-Lookups über alle Angebotskarten nur EINMAL laufen (dedupe).
if (!isset($GLOBALS['bx_stock_cache'])) $GLOBALS['bx_stock_cache'] = [];
// WICHTIG: Status 'offen' ist der interne ENTWURF – der Kunde darf ihn nicht sehen.
// Sonst erscheint ein Angebot beim Kunden, sobald es im Editor angelegt wird, also bevor
// überhaupt eine Position darin steht. Sichtbar wird es erst mit 'gesendet'.
// Staffeln + Angebots-Infos werden LAZY berechnet – erst wenn eine Angebotskarte wirklich
// gerendert wird. Ein Seitenaufruf zeigt nur einen Reiter (oft wenige oder keine Karten),
// vorher wurde das für ALLE Angebote des Kunden gebaut (hunderte Abfragen umsonst).
$staffelMap = [];  // Cache je Angebot-ID
$angInfo    = [];  // Cache je Angebot-ID
// --- Buendel-Vorladung fuer ALLE gezeigten Angebotskarten ---
// Statt je Karte einzeln Preismatrix, Zutaten, Naehrwerte, Staffeln und die Annehmbar-Zaehler
// abzufragen (das waren ~7 Abfragen je Angebot), holen wir sie hier in je EINER Abfrage vor.
// Die Closures unten lesen nur noch aus diesen Maps.
$prodIds = $rezIds = $angIds = $anfIds = [];
foreach ($angebote as $a) {
    if (!empty($a['produkt_id']))  $prodIds[(int)$a['produkt_id']]  = true;
    if (!empty($a['rezeptur_id'])) $rezIds[(int)$a['rezeptur_id']]  = true;
    $angIds[(int)$a['id']] = true;
    if (!empty($a['anfrage_id'])) $anfIds[(int)$a['anfrage_id']] = true;
}
$prodIds = array_keys($prodIds); $rezIds = array_keys($rezIds);
$angIds  = array_keys($angIds);  $anfIds = array_keys($anfIds);
$inList  = fn(array $ids) => implode(',', array_map('intval', $ids));

$matrixRows = [];   // produkt_id => Rohzeilen der Preismatrix (VK/EK roh; Marge rechnet die Closure)
if ($prodIds) foreach (all("SELECT produkt_id, stueck, bestellmenge, verpackung_id, ek_preis, vk_preis
                            FROM produkt_preis WHERE produkt_id IN (" . $inList($prodIds) . ") ORDER BY vk_preis ASC") as $mr) {
    $matrixRows[(int)$mr['produkt_id']][] = $mr;
}
$zutMap = $portionMap = [];   // rezeptur_id => Zutaten / Portionssumme (mg)
if ($rezIds) foreach (all("SELECT rezeptur_id, bezeichnung, menge_mg FROM rezeptur_zutat
                           WHERE rezeptur_id IN (" . $inList($rezIds) . ") ORDER BY sort, id") as $z) {
    $rz = (int)$z['rezeptur_id'];
    $zutMap[$rz][]   = ['bezeichnung'=>$z['bezeichnung'], 'menge_mg'=>$z['menge_mg']];
    $portionMap[$rz] = ($portionMap[$rz] ?? 0) + (float)$z['menge_mg'];
}
$nutrMap = [];   // rezeptur_id => Naehrwerte (gleiche Aggregation wie pt_naehr, gebuendelt)
if ($rezIds) foreach (all("SELECT z.rezeptur_id, z.menge_mg, iw.gehalt_prozent, na.name, na.nrv_wert, na.einheit
                           FROM rezeptur_zutat z JOIN item_wirkstoff iw ON iw.item_id=z.item_id
                           JOIN naehrstoff na ON na.id=iw.naehrstoff_id
                           WHERE z.rezeptur_id IN (" . $inList($rezIds) . ") AND iw.gehalt_prozent IS NOT NULL") as $w) {
    $rz = (int)$w['rezeptur_id'];
    $mgN = (float)$w['menge_mg'] * (float)$w['gehalt_prozent'] / 100;
    if (!isset($nutrMap[$rz][$w['name']])) $nutrMap[$rz][$w['name']] = ['name'=>$w['name'], 'mg'=>0.0, 'nrv'=>$w['nrv_wert'], 'einheit'=>$w['einheit']];
    $nutrMap[$rz][$w['name']]['mg'] += $mgN;
}
if ($angIds) foreach (all("SELECT * FROM angebot_staffel WHERE angebot_id IN (" . $inList($angIds) . ") ORDER BY sort, id") as $s) {
    $staffelMap[(int)$s['angebot_id']][] = $s;
}
foreach ($angIds as $id) if (!isset($staffelMap[$id])) $staffelMap[$id] = [];   // leere Angebote merken -> kein Nachladen
$posRezCount = $anfRezOk = [];   // Annehmbar-Zaehler je Angebot / je Anfrage
if ($angIds) foreach (all("SELECT angebot_id, COUNT(*) n FROM angebot_position
                           WHERE angebot_id IN (" . $inList($angIds) . ") AND rezeptur_id IS NOT NULL AND stueck > 0
                           GROUP BY angebot_id") as $c) $posRezCount[(int)$c['angebot_id']] = (int)$c['n'];
if ($anfIds) foreach (all("SELECT id FROM portal_anfrage
                           WHERE id IN (" . $inList($anfIds) . ") AND rezeptur_id IS NOT NULL AND COALESCE(stueck, fuellmenge_g, 0) > 0") as $c)
    $anfRezOk[(int)$c['id']] = true;
$produktionszeit = (float) meta_get('produktionszeit_wochen', 7);   // globaler Standard
$itemName = fn($id) => $id ? (string) scalar("SELECT name FROM item WHERE id=?", [(int)$id]) : '';
$staffelFuer = function(array $a) use (&$staffelMap) {
    $id = (int)$a['id'];
    if (!array_key_exists($id, $staffelMap)) $staffelMap[$id] = all("SELECT * FROM angebot_staffel WHERE angebot_id=? ORDER BY sort, id", [$id]);
    return $staffelMap[$id];
};
$angInfoFuer = function(array $a) use (&$angInfo, &$staffelMap, $itemName, $produktionszeit, $matrixRows, $zutMap, $portionMap, $nutrMap, $posRezCount, $anfRezOk) {
    $id = (int)$a['id'];
    if (array_key_exists($id, $angInfo)) return $angInfo[$id];
    $rid = (int)($a['rezeptur_id'] ?? 0);
    // je Angebot gesetzte Marge (überschreibt die Marge-je-Typ; VK wird dann aus EK gerechnet)
    $mo = ($a['marge_override'] ?? '') !== '' && $a['marge_override'] !== null ? (float)$a['marge_override'] : null;
    // Preismatrix des Produkts: [stueck][bestellmenge] = günstigste Zelle (vk + verpackung) – aus der Buendel-Vorladung
    $matrix = [];
    foreach ($matrixRows[(int)($a['produkt_id'] ?? 0)] ?? [] as $mr) {
        $s = (int)$mr['stueck']; $bm = (int)$mr['bestellmenge'];
        $vk = $mo !== null ? (float)$mr['ek_preis'] * (1 + $mo/100) : (float)$mr['vk_preis'];
        if (!isset($matrix[$s][$bm])) $matrix[$s][$bm] = ['vk'=>$vk, 'verp'=>(int)$mr['verpackung_id']];
    }
    // Positionen/Optionen liest die Karte ausschliesslich im Zweig "gesendet UND keine Matrix UND keine Staffel"
    // (positionsbasiertes Angebot). Fuer Matrix-/Staffel-Angebote NIE laden.
    $brauchtPos = $a['status'] === 'gesendet' && empty($matrix) && empty($staffelMap[$id]);
    // WICHTIG: im Kundenportal NUR die GESPEICHERTEN Positionen lesen – niemals die Live-Preis-Engine
    // (angebot_positionen()) anstossen. Der Kunde hat seinen Preis bereits; eine erneute EK-/Lieferanten-
    // Kalkulation je Zutat kostete auf der Remote-DB ~100 Abfragen JE KARTE (=> 127 s / ERR_SSL beim Laden).
    // Ist ein Angebot nicht eingefroren, sieht der Kunde keine Preistabelle (Knopf: "bitte kurz melden").
    $posGespeichert = $brauchtPos
        ? all("SELECT bezeichnung, beschreibung, menge, einheit, preis_cent, mwst_satz FROM angebot_position
               WHERE angebot_id=? AND (menge > 0 OR preis_cent > 0) ORDER BY sort, id", [$id])
        : [];
    return $angInfo[$id] = [
        'verp'    => $itemName($a['verpackung_id']),
        'deckel'  => $itemName($a['verschluss_id']),
        'etikett' => $itemName($a['etikett_id']),
        'form'    => $a['darreichungsform'] ?? '',
        'istPulver' => in_array($a['darreichungsform'] ?? '', ['pulver','stick','granulat'], true),   // Rezeptur beschreibt eine Portion (g je Packung anzeigen)
        'istFuell'  => form_ist_fuellmenge($a['darreichungsform'] ?? ''),                              // Packungsgröße ist eine Füllmenge (g/ml) statt einer Stückzahl
        'portionG' => $rid ? (float)($portionMap[$rid] ?? 0) / 1000 : 0,
        'zutaten' => $rid ? ($zutMap[$rid] ?? []) : [],
        'nutr'    => $rid ? array_values($nutrMap[$rid] ?? []) : [],
        'matrix'  => $matrix,
        // Positionen des Angebots (Rezeptur/Rohstoff/Dienstleistung). Nötig, wenn es keine Preismatrix gibt –
        // sonst sähe der Kunde bei einem Angebot aus Positionen nur eine leere Tabelle.
        'pos'     => array_map(fn($p) => ['bezeichnung'=>$p['bezeichnung'], 'beschreibung'=>$p['beschreibung'],
                                          'menge'=>(float)$p['menge'], 'einheit'=>$p['einheit'],
                                          'preis_cent'=>(int)$p['preis_cent'], 'mwst'=>(float)$p['mwst_satz']],
                                $posGespeichert),
        // Wählbare Optionen: je Gruppe (A, B, C …) eine Konfiguration mit Anzahl Packungen und
        // Preis je Packung – daraus wird die Auswahltabelle wie bei der Preismatrix.
        'opt'     => $brauchtPos ? angebot_optionen((int)$a['id']) : ['optionen'=>[], 'extra'=>[]],
        // Annehmbar ist ein Positions-Angebot nur, wenn die Konfiguration bekannt ist – aus der
        // Position selbst oder aus der zugehoerigen Anfrage. Sonst waere der Knopf ein Blindgaenger.
        'annehmbar' => $brauchtPos && (
              ($posRezCount[$id] ?? 0) > 0
           || !empty($anfRezOk[(int)($a['anfrage_id'] ?? 0)])
        ),
        'prodzeit'=> ($a['produktionszeit_wochen'] ?? '') !== '' && $a['produktionszeit_wochen'] !== null ? (float)$a['produktionszeit_wochen'] : $produktionszeit,
    ];
};
$std_stueck_ang = std_stueckzahlen();
$std_menge_ang  = std_bestellmengen();
// USt-Satz dieses Kunden (EU-Ausland/Kleinunternehmer 0 %, sonst Inland)
$land = $k['land'] ?? 'DE';
$ustP = (meta_get('kleinunternehmer','0') === '1' || $land !== 'DE') ? 0.0 : (float) meta_get('ust_inland', 19);
$auftraege = all("SELECT a.*, COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt_name FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id
                  WHERE a.kunde_id=? ORDER BY a.angelegt DESC", [$kid]);
$rechnungen = all("SELECT * FROM beleg WHERE kunde_id=? AND typ='rechnung' ORDER BY angelegt DESC", [$kid]);
$anfragen = all("SELECT a.*, r.name AS rezeptur_name, r.status AS rezeptur_status,
                 (SELECT COUNT(*) FROM rezeptur_anfrage_wunsch w WHERE w.anfrage_id=a.id) AS wunsch_anzahl
                 FROM rezeptur_anfrage a LEFT JOIN rezeptur r ON r.id=a.rezeptur_id WHERE a.kunde_id=? ORDER BY a.angelegt DESC", [$kid]);
// „Zu prüfen": ein Vorschlag liegt zur Freigabe bereit (verknüpfte Rezeptur im Status „vorschlag").
$anfPruef  = array_values(array_filter($anfragen, fn($a) => ($a['rezeptur_status'] ?? '') === 'vorschlag'));
$anfRest   = array_values(array_filter($anfragen, fn($a) => ($a['rezeptur_status'] ?? '') !== 'vorschlag'));
$DFORM_P = ['kapsel'=>'Kapsel','tablette'=>'Tablette','softgel'=>'Softgel','stick'=>'Stick','pulver'=>'Pulver','fluessig'=>'Flüssig'];
$anfBadge = fn($s) => match ($s) { 'neu'=>bx_badge('eingereicht','info'),'in_bearbeitung'=>bx_badge('in Prüfung','warn'),'beantwortet'=>bx_badge('Vorschlag erhalten','ok'),'ueberarbeiten'=>bx_badge('wird überarbeitet','warn'),'abgelehnt'=>bx_badge('abgelehnt','err'),default=>bx_badge($s) };
$vorschlaege = all("SELECT * FROM rezeptur WHERE kunde_id=? AND status='vorschlag' ORDER BY aktualisiert DESC", [$kid]);
// Kundenseitiger Status – bewusst nur vier Zustände (richtet sich nach dem ECHTEN Rezeptur-Stand):
//  in Prüfung  = liegt bei uns (nichts gesendet) · Vorschlag erhalten = wir haben einen Vorschlag gesendet
//  abgelehnt   = Vorschlag abgelehnt (vom Kunden oder von uns) · Rezeptur angelegt = final bestätigt (eingefroren)
$anfStatus = function($an) {
    $rs = $an['rezeptur_status'] ?? '';
    $as = $an['status'] ?? '';
    if ($rs === 'eingefroren') return bx_badge('Rezeptur angelegt','ok');
    if ($rs === 'vorschlag')   return bx_badge('Vorschlag erhalten','ok');
    if ($rs === 'abgelehnt' || $as === 'ueberarbeiten' || $as === 'abgelehnt') return bx_badge('abgelehnt','err');
    return bx_badge('in Prüfung','warn');   // neu / in Bearbeitung / Entwurf
};
$vorschlagZutaten = [];
foreach ($vorschlaege as $vs) $vorschlagZutaten[$vs['id']] = all("SELECT bezeichnung, menge_mg FROM rezeptur_zutat WHERE rezeptur_id=? ORDER BY sort,id", [$vs['id']]);

$aufBadge = fn($s) => match ($s) { 'offen'=>bx_badge('in Bearbeitung','info'),'in_produktion'=>bx_badge('in Produktion','warn'),'erledigt'=>bx_badge('versandbereit','info'),'versendet'=>bx_badge('versendet','ok'),default=>bx_badge($s) };
$reBadge  = fn($s) => match ($s) { 'bezahlt'=>bx_badge('bezahlt','ok'),'teilbezahlt'=>bx_badge('teilbezahlt','info'),'offen'=>bx_badge('offen','warn'),'storniert'=>bx_badge('storniert','err'),default=>bx_badge($s) };
// Feste Kunden-Phasen (wie v3) – der Kunde sieht KEINE internen Produktionsschritte, immer dieselben
// Phasen, egal ob eigene Rohstoff-Produktion oder zugekauftes Fertigprodukt (kein Zukauf-Verräter).
$AUFSTEPS = ['Bestätigt', 'Rohstoff bestellt', 'In Produktion', 'Qualitätsprüfung', 'Versandbereit', 'Versendet'];
// Aktuelle Phase (0..5) + Datum je Phase aus den vorhandenen Signalen ableiten.
if (!function_exists('kunde_auftrag_phase')) {
    function kunde_auftrag_phase(array $a): array {
        $aid = (int)$a['id']; $st = (string)$a['status'];
        $dates = array_fill(0, 6, null);
        $dates[0] = $a['angelegt'] ?? null;                                  // Bestätigt
        // Rohstoff bestellt: erste Bestellung, die auf diesen Auftrag zeigt (Rohstoffe ODER zugekaufter Bulk).
        $best = one("SELECT COALESCE(MIN(b.bestelldatum), MIN(b.angelegt)) d FROM bestellung b
                     JOIN bestellung_position bp ON bp.bestellung_id=b.id WHERE bp.auftrag_id=?", [$aid]);
        $bestellt = $best && !empty($best['d']);
        if ($bestellt) $dates[1] = $best['d'];
        // Produktion + Qualitätsprüfung aus den echten Schritten (nur intern; hier nur zur Phasenableitung).
        $pa = one("SELECT id, angelegt FROM produktionsauftrag WHERE auftrag_id=? ORDER BY id DESC LIMIT 1", [$aid]);
        $qcDate = null;
        if ($pa) {
            $dates[2] = $pa['angelegt'];
            foreach (all("SELECT station, erledigt, erledigt_at FROM produktion_schritt WHERE pa_id=? ORDER BY sort,id", [(int)$pa['id']]) as $s) {
                if ((int)$s['erledigt'] === 1 && stripos((string)$s['station'], 'Qualität') !== false) $qcDate = $s['erledigt_at'];
            }
        }
        $qcDone = $qcDate !== null;
        $dates[3] = $qcDate;
        if ($st === 'erledigt')  $dates[4] = $a['aktualisiert'] ?? null;
        if ($st === 'versendet') { $dates[5] = $a['aktualisiert'] ?? null; $dates[4] = $dates[4] ?? ($a['aktualisiert'] ?? null); }
        // Index der aktuellen Phase.
        if ($st === 'versendet')          $idx = 5;
        elseif ($st === 'erledigt')       $idx = 4;
        elseif ($st === 'in_produktion')  $idx = $qcDone ? 3 : 2;
        else                              $idx = $bestellt ? 1 : 0;   // offen = bestätigt / rohstoff bestellt
        return ['idx' => $idx, 'dates' => $dates];
    }
}

// Menüpunkte (nur freigeschaltete) + Gruppierung
$L = ['start' => 'Übersicht'];
// „Meine Anfragen" listet ALLE Anfragetypen – der Punkt gehört deshalb ins Menü, sobald irgendein
// Anfragebereich frei ist, nicht nur bei Rezepturen. Sonst läuft die Weiterleitung nach dem Absenden ins Leere.
if ($k['portal_rezeptur'] || $k['portal_produkte'] || $k['portal_rohstoffe'] || $k['portal_dienstleistung'])
    $L['meine_anfragen'] = 'Meine Anfragen';
if ($k['portal_rezeptur'])     { $L['rezepturen'] = 'Rezepturen';  $L['anfrage'] = 'Rezeptur anfragen'; }
// Produkt anfragen: mit Katalog-Recht immer; ohne Katalog, sobald der Kunde eine anfragbare eigene/freigegebene
// Rezeptur hat (angenommene Rezeptur -> als Produkt anfragen). So ist der Weg nach dem Annehmen nie eine Sackgasse.
$kannProduktAnfrage = !empty($k['portal_produkte']) || (!empty($k['portal_rezeptur']) &&
    (int) scalar("SELECT COUNT(*) FROM rezeptur WHERE (kunde_id=? AND status IN ('eingefroren','freigegeben')) OR (kunde_id IS NULL AND status='freigegeben')", [(int)$k['id']]) > 0);
if ($k['portal_produkte'])     { $L['produkte']   = 'Produkte';    $L['prodanfrage'] = 'Produkt anfragen'; }
elseif ($kannProduktAnfrage)   { $L['prodanfrage'] = 'Produkt anfragen'; }
if ($k['portal_rohstoffe'])    { $L['rohstoffe']  = 'Rohstoffe';   $L['rohanfrage'] = 'Rohstoff anfragen'; }
if ($k['portal_dienstleistung']) $L['dienstleistung'] = 'Dienstleistung anfragen';
// Jahresverträge/Kontingente: Menüpunkt nur, wenn der Kunde aktive Verträge hat.
$hatKontingente = (int) scalar("SELECT COUNT(*) FROM kontingent WHERE kunde_id=?", [(int)$k['id']])
    + (int) scalar("SELECT COUNT(*) FROM angebot WHERE kunde_id=? AND jahresvertrag=1 AND status<>'offen' AND kunde_ausgeblendet=0", [(int)$k['id']]);
if ($hatKontingente > 0) $L['kontingente'] = 'Jahresverträge';
$L += ['angebote' => 'Angebote', 'bestellungen' => 'Bestellungen', 'rechnungen' => 'Rechnungen'];
$NAVGROUPS = [
    ''          => ['start'],
    'Katalog'   => ['rezepturen', 'produkte', 'rohstoffe'],
    'Anfragen'  => ['meine_anfragen', 'anfrage', 'prodanfrage', 'rohanfrage', 'dienstleistung'],
    'Vorgänge'  => ['kontingente', 'angebote', 'bestellungen', 'rechnungen'],
];
// Detailansichten (kein Menüpunkt) – gültig je nach Freischaltung; hebt den Katalog-Punkt hervor
$detailParent = [];
if ($k['portal_rezeptur']) $detailParent['rezeptur'] = 'rezepturen';
if ($k['portal_produkte']) $detailParent['produkt']  = 'produkte';
if ($k['portal_rohstoffe']) $detailParent['rohstoff'] = 'rohstoffe';
$detailParent['bestellung'] = 'bestellungen';   // Bestell-Detail (eigene Bestellung)
$detailParent['menge_aendern'] = 'meine_anfragen';   // Menge einer Produktanfrage aendern (kein Menuepunkt)
$detailParent['agb'] = 'start';   // AGB: kein Menuepunkt, aber eine echte Seite (Fussleiste + Bestaetigungsdialog)
$view = $_GET['v'] ?? 'start';
if (!isset($L[$view]) && !isset($detailParent[$view])) $view = 'start';
$activeItem = $detailParent[$view] ?? $view;

// Suchbegriff für die Katalog-Listen (Produkte, Rezepturen, Rohstoffe).
// Gesucht wird über den Namen UND über die enthaltenen Rohstoffe – ein Kunde sucht eher
// nach „Magnesium" als nach unserem Produktnamen.
$q = trim((string)($_GET['q'] ?? ''));
$qLike = '%' . $q . '%';

// Katalog-Produkte (nicht exklusiv oder exklusiv für diesen Kunden), aktiv
$katalog = $k['portal_produkte'] ? all("SELECT p.id, COALESCE(NULLIF(p.kundenname,''), p.name) AS name, p.nummer, p.rezeptur_id, p.kunde_id, r.darreichungsform
    FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id
    WHERE p.status='aktiv' AND (p.exklusiv=0 OR p.kunde_id=?)
      AND (? = '' OR COALESCE(NULLIF(p.kundenname,''), p.name) LIKE ? OR r.name LIKE ?
           OR EXISTS (SELECT 1 FROM rezeptur_zutat z LEFT JOIN item i ON i.id=z.item_id
                      WHERE z.rezeptur_id=r.id AND (z.bezeichnung LIKE ? OR i.name LIKE ?)))
    ORDER BY name", [$kid, $q, $qLike, $qLike, $qLike, $qLike]) : [];
$primVerp  = all("SELECT id, name FROM item WHERE kategorie='verpackung' AND COALESCE(verpackung_rolle,'primaer')='primaer' AND gesperrt=0 ORDER BY name");
$stdStueck = std_stueckzahlen();
// Kundenseitige Verpackungstypen – wir wählen intern den perfekt passenden Behälter
$VTYPEN = ['glas'=>'Glas', 'pet'=>'PET-Dose', 'pla'=>'PLA-Becher', 'beutel'=>'Standbodenbeutel', 'stick'=>'Stick', 'blister'=>'Blister', 'karton'=>'Karton/Faltschachtel'];
// „ab"-Preise je Produkt (günstigste VK aus der Preismatrix, mit Kundenrabatt).
// Preise sind KUNDENSPEZIFISCH: sichtbar nur, wenn diesem Kunden das Produkt schon angeboten wurde.
// Alle anderen sehen „auf Anfrage" – sonst wandert die Kalkulation eines Kunden zum nächsten.
$preisFrei = kunde_produkt_preise($kid);
$abMap = [];
foreach (all("SELECT produkt_id, MIN(vk_preis) AS mn FROM produkt_preis GROUP BY produkt_id") as $r) $abMap[(int)$r['produkt_id']] = (float)$r['mn'];
$abPreis = fn($pid) => (isset($abMap[(int)$pid]) && isset($preisFrei[(int)$pid])) ? vk_fuer_kunde($abMap[(int)$pid], $kid) : null;
// Nährwerte je Einheit aus einer Rezeptur (aggregiert über die Wirkstoffe der Rohstoffe)
if (!function_exists('pt_naehr')) {
    function pt_naehr(int $rid): array {
        $n = [];
        foreach (all("SELECT z.menge_mg, iw.gehalt_prozent, na.name, na.nrv_wert, na.einheit
                      FROM rezeptur_zutat z JOIN item_wirkstoff iw ON iw.item_id=z.item_id
                      JOIN naehrstoff na ON na.id=iw.naehrstoff_id
                      WHERE z.rezeptur_id=? AND iw.gehalt_prozent IS NOT NULL", [$rid]) as $w) {
            $mgN = (float)$w['menge_mg'] * (float)$w['gehalt_prozent'] / 100;
            if (!isset($n[$w['name']])) $n[$w['name']] = ['name'=>$w['name'], 'mg'=>0.0, 'nrv'=>$w['nrv_wert'], 'einheit'=>$w['einheit']];
            $n[$w['name']]['mg'] += $mgN;
        }
        return array_values($n);
    }
}
// (Früher wurde hier jedes Katalog-Produkt mit Zutaten/Nährwerten/Preistabelle angereichert – auf JEDER
//  Portal-Seite, ~4 Abfragen je Produkt. Diese Felder werden aber nirgends gelesen: die Produktliste zeigt
//  nur den „ab"-Preis ($abPreis), das Produktdetail baut seine Zutaten/Nährwerte selbst ($prodZutaten/
//  $prodNaehr), und die Angebotskarten nutzen $angInfo. Der Loop war toter Code und ist entfernt.)
seed_kapselgroesse_if_empty();
$portalKapseln = all("SELECT id, name, fuellmenge_mg FROM kapselgroesse ORDER BY fuellmenge_mg ASC");
// Kleinste Kapselgröße, in die das Gesamt-Füllgewicht (mg) passt; null = größer als jede Standardkapsel.
$kapselFuer = function(float $totalMg) use ($portalKapseln): ?array {
    if ($totalMg <= 0) return null;
    foreach ($portalKapseln as $kg) if ((float)$kg['fuellmenge_mg'] >= $totalMg) return $kg;
    return null;
};
// Anzeige-Kapselgröße: am Rezept gespeicherte Größe hat Vorrang, sonst Richtwert nach Füllgewicht.
$kapselAnzeige = function(array $rezRow, float $totalMg) use ($portalKapseln, $kapselFuer): ?array {
    $gid = (int)($rezRow['kapselgroesse_id'] ?? 0);
    if ($gid > 0) foreach ($portalKapseln as $kg) if ((int)$kg['id'] === $gid) return $kg;
    return $kapselFuer($totalMg);
};
$portalAnfragen = all("SELECT pa.*, p.name AS produkt_name, i.name AS verp_name, rz.name AS rezeptur_name,
    (SELECT a.id FROM angebot a WHERE a.anfrage_id=pa.id AND a.kunde_id=pa.kunde_id AND a.kunde_ausgeblendet=0 AND a.status<>'offen' AND a.preise_kunde=1 ORDER BY a.id DESC LIMIT 1) AS angebot_id,
    (SELECT a.status FROM angebot a WHERE a.anfrage_id=pa.id AND a.kunde_id=pa.kunde_id AND a.kunde_ausgeblendet=0 AND a.status<>'offen' AND a.preise_kunde=1 ORDER BY a.id DESC LIMIT 1) AS angebot_status,
    (SELECT au.id FROM angebot a JOIN auftrag au ON au.angebot_id=a.id WHERE a.anfrage_id=pa.id AND a.kunde_id=pa.kunde_id ORDER BY au.id DESC LIMIT 1) AS auftrag_id,
    (SELECT au.status FROM angebot a JOIN auftrag au ON au.angebot_id=a.id WHERE a.anfrage_id=pa.id AND a.kunde_id=pa.kunde_id ORDER BY au.id DESC LIMIT 1) AS auftrag_status
    FROM portal_anfrage pa
    LEFT JOIN produkt p ON p.id=pa.produkt_id LEFT JOIN item i ON i.id=pa.verpackung_id
    LEFT JOIN rezeptur rz ON rz.id=pa.rezeptur_id
    WHERE pa.kunde_id=? ORDER BY pa.angelegt DESC", [$kid]);
// Produktanfragen, deren Menge der Kunde noch ändern darf: solange NOCH KEIN Auftrag existiert
// (auch wenn schon ein Angebot vorliegt). Danach ist die Konfiguration verbindlich -> nur Nachbestellen.
$mengeAenderbar = [];
foreach ($portalAnfragen as $pp)
    if (($pp['typ'] ?? '') === 'produkt' && empty($pp['auftrag_id'])) $mengeAenderbar[(int)$pp['id']] = true;
// Anzeigename einer Zeile (Angebot, Bestellung, Anfrage): Produktname, sonst die Rezeptur,
// sonst die erste Angebotsposition. Ohne Produkt stand hier sonst nur ein nichtssagendes „–".
$titelFuer = function(array $r): string {
    if (!empty($r['produkt_name'])) return (string)$r['produkt_name'];
    if (!empty($r['rezeptur_id'])) {
        $n = (string) scalar("SELECT name FROM rezeptur WHERE id=?", [(int)$r['rezeptur_id']]);
        if ($n !== '') return $n;
    }
    $ang = (int)($r['angebot_id'] ?? 0);
    if (!$ang && strpos((string)($r['nummer'] ?? ''), 'AN-') === 0) $ang = (int)($r['id'] ?? 0);
    if ($ang) {
        $b = (string) scalar("SELECT bezeichnung FROM angebot_position WHERE angebot_id=? ORDER BY sort, id LIMIT 1", [$ang]);
        if ($b !== '') return preg_replace('/^[A-Z]\)\s*/', '', $b);
    }
    return '–';
};
$pafBadge = fn($s) => match ($s) { 'neu'=>bx_badge('eingegangen','info'),'in_bearbeitung'=>bx_badge('in Bearbeitung','warn'),'beantwortet'=>bx_badge('Angebot abgegeben','ok'),'abgelehnt'=>bx_badge('nicht machbar','err'),default=>bx_badge($s) };
// Rezeptur-Katalog: eigene Rezepturen (ab Vorschlag) + freigegebene Hausrezepturen (allen Kunden verfügbar)
// „Meine Rezepturen" = die EIGENEN des Kunden (kunde_id), sobald angenommen/freigegeben, plus die
// freigegebenen Haus-/Katalog-Rezepturen (kunde_id NULL). Eigene zählen über kunde_id – nicht über das
// exklusiv-Flag (aus einer Anfrage entstandene Rezepturen sind nicht zwingend exklusiv).
// Vorschläge sind noch keine Rezeptur → erscheinen über die Übersicht („Vorschlag erhalten"), nicht hier.
$meineRezepturen = $k['portal_rezeptur'] ? all("SELECT * FROM rezeptur
    WHERE ((kunde_id=? AND status IN ('eingefroren','freigegeben'))
       OR (kunde_id IS NULL AND status='freigegeben'))
      AND (? = '' OR name LIKE ?
           OR EXISTS (SELECT 1 FROM rezeptur_zutat z LEFT JOIN item i ON i.id=z.item_id
                      WHERE z.rezeptur_id=rezeptur.id AND (z.bezeichnung LIKE ? OR i.name LIKE ?)))
    ORDER BY (kunde_id IS NULL), name", [$kid, $q, $qLike, $qLike, $qLike]) : [];
$rezBadge = fn($s) => match ($s) { 'vorschlag'=>bx_badge('Vorschlag','info'),'eingefroren'=>bx_badge('angenommen','ok'),'freigegeben'=>bx_badge('freigegeben','ok'),'abgelehnt'=>bx_badge('abgelehnt','err'),default=>bx_badge($s) };
$rid = (int)($_GET['rid'] ?? 0);
$DOKTYP = dokument_typen();   // CoA / Spezifikation / Laboranalyse – Beschriftung der Download-Links
$rezDetail = ($rid && $k['portal_rezeptur']) ? one("SELECT * FROM rezeptur WHERE id=?
    AND ((kunde_id=? AND status IN ('vorschlag','eingefroren','freigegeben','abgelehnt')) OR (kunde_id IS NULL AND status='freigegeben'))", [$rid, $kid]) : null;
// Zutaten inklusive item_id – damit je Rohstoff die freigegebenen Dokumente (CoA/Spec) verlinkt werden können
$rezZutaten = $rezDetail ? all("SELECT item_id, bezeichnung, menge_mg FROM rezeptur_zutat WHERE rezeptur_id=? ORDER BY sort, id", [$rid]) : [];
// Freigegebene Dokumente je Zutat-Rohstoff (nur, was intern ausdrücklich freigegeben wurde)
$rezDoks = [];
foreach ($rezZutaten as $z) if (!empty($z['item_id'])) {
    $dk = dokumente_fuer_kunde('item', (int)$z['item_id']);
    if ($dk) $rezDoks[(int)$z['item_id']] = $dk;
}

// Rohstoff-Katalog (Preis auf Anfrage) – ohne Leerkapseln
$rohkatalog = $k['portal_rohstoffe'] ? all("SELECT id, name, form, cas, name_lat, synonym, bot_quelle, herkunftsland FROM item
    WHERE kategorie='rohstoff' AND gesperrt=0 AND (form<>'kapselhuelle' OR form IS NULL)
      AND (? = '' OR name LIKE ? OR name_lat LIKE ? OR synonym LIKE ? OR cas LIKE ?)
    ORDER BY name", [$q, $qLike, $qLike, $qLike, $qLike]) : [];
// Produkt-Detail (aus dem Katalog)
$pid = (int)($_GET['pid'] ?? 0);
$prodDetail = ($pid && $k['portal_produkte']) ? one("SELECT p.*, COALESCE(NULLIF(p.kundenname,''), p.name) AS anzeige_name, r.darreichungsform, r.name AS rez_name
    FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id
    WHERE p.id=? AND p.status='aktiv' AND (p.exklusiv=0 OR p.kunde_id=?)", [$pid, $kid]) : null;
$prodZutaten = ($prodDetail && $prodDetail['rezeptur_id']) ? all("SELECT z.item_id, z.bezeichnung, z.menge_mg, i.allergene, i.vegan, i.gvo_frei
    FROM rezeptur_zutat z LEFT JOIN item i ON i.id=z.item_id WHERE z.rezeptur_id=? ORDER BY z.sort, z.id", [(int)$prodDetail['rezeptur_id']]) : [];
// Aus den verknüpften Rohstoffen: Wirkstoffe je Zutat + Nährwert-Aggregation je Einheit + Deklaration
$prodWirk = []; $prodNaehr = []; $prodAllergene = []; $veganFlags = []; $gvoFlags = [];
foreach ($prodZutaten as $z) {
    if (!$z['item_id']) continue;
    $al = trim((string)$z['allergene']);
    if ($al !== '' && mb_stripos($al, 'keine') === false) $prodAllergene[] = $al;
    $veganFlags[] = $z['vegan']; $gvoFlags[] = $z['gvo_frei'];
    foreach (all("SELECT n.name, n.nrv_wert, n.einheit, iw.gehalt_prozent
                  FROM item_wirkstoff iw JOIN naehrstoff n ON n.id=iw.naehrstoff_id WHERE iw.item_id=?", [(int)$z['item_id']]) as $w) {
        $prodWirk[$z['item_id']][] = ($w['gehalt_prozent'] !== null ? rtrim(rtrim(number_format((float)$w['gehalt_prozent'],2,',','.'),'0'),',') . ' % ' : '') . $w['name'];
        if ($w['gehalt_prozent'] === null) continue;
        $mgN = (float)$z['menge_mg'] * (float)$w['gehalt_prozent'] / 100;
        if (!isset($prodNaehr[$w['name']])) $prodNaehr[$w['name']] = ['name'=>$w['name'], 'mg'=>0.0, 'nrv'=>$w['nrv_wert'], 'einheit'=>$w['einheit']];
        $prodNaehr[$w['name']]['mg'] += $mgN;
    }
}
// Deklaration nur behaupten, wenn ALLE Rohstoffe bekannt & konform sind (sonst „–")
$aggFlag = function ($flags) { $known = array_filter($flags, fn($x) => $x !== null && $x !== ''); if (!$known || count($known) < count($flags)) return null; foreach ($known as $f) if ((int)$f === 0) return false; return true; };
$prodVegan = $prodDetail ? $aggFlag($veganFlags) : null;
$prodGvo   = $prodDetail ? $aggFlag($gvoFlags) : null;
$prodAllergene = array_values(array_unique($prodAllergene));
// Größen & Preise des Produkts (je Stückzahl der günstigste VK, mit Kundenrabatt).
// Nur für Produkte, die diesem Kunden angeboten wurden – sonst bleibt die Tabelle leer und es steht „auf Anfrage".
$prodPreise = [];
if ($prodDetail && isset($preisFrei[(int)$prodDetail['id']]))
    foreach (all("SELECT stueck, MIN(vk_preis) AS mn FROM produkt_preis WHERE produkt_id=? GROUP BY stueck ORDER BY stueck", [(int)$prodDetail['id']]) as $r)
        $prodPreise[] = ['stueck'=>(int)$r['stueck'], 'ab'=>vk_fuer_kunde((float)$r['mn'], $kid)];
$prodForm = $prodDetail['darreichungsform'] ?? '';
$prodIstFuell  = form_ist_fuellmenge($prodForm);   // Anfrage nach Füllmenge (Pulver g / Flüssig ml) statt Stückzahl
$prodFuellEinheit = form_groessen_einheit($prodForm) ?: 'g';
$prodPortionG = ($prodDetail && $prodDetail['rezeptur_id']) ? (float) scalar("SELECT COALESCE(SUM(menge_mg),0) FROM rezeptur_zutat WHERE rezeptur_id=?", [(int)$prodDetail['rezeptur_id']]) / 1000 : 0;
// Rohstoff-Detail (kundenfreundlich: Kennwerte + Deklaration, keine internen Daten)
$iid = (int)($_GET['iid'] ?? 0);
$rohDetail = ($iid && $k['portal_rohstoffe']) ? one("SELECT id, name, name_lat, form, cas, herkunft, synonym, bot_quelle, herkunftsland,
    haltbarkeit, lagerbedingungen, zusaetze, allergene, vegan, gvo_frei, bestrahlt, tse_bse_frei, zertifikate, spec_freigegeben
    FROM item WHERE id=? AND kategorie='rohstoff' AND gesperrt=0", [$iid]) : null;
$rohKennwerte = $rohDetail ? all("SELECT parameter, wert FROM item_kennwert WHERE item_id=? ORDER BY sort, id", [$iid]) : [];
// Freigegebene Analysenzertifikate (bulkify-Layout) zu diesem Rohstoff – nur was das Team freigegeben hat.
$rohCoas = $rohDetail ? all("SELECT id, charge_nr, mhd FROM charge WHERE item_id=? AND coa_freigegeben=1 ORDER BY (wareneingang IS NULL), wareneingang DESC, id DESC", [$iid]) : [];
$jaNein = fn($v) => $v === null || $v === '' ? null : ((int)$v === 1);
$FORMLBL_P = ['pulver'=>'Pulver','granulat'=>'Granulat','fluessig'=>'Flüssig','oel'=>'Öl','paste'=>'Paste','kristallin'=>'Kristallin','kapselhuelle'=>'Kapselhülle'];
$offenAngebote = count(array_filter($angebote, fn($a) => $a['status'] === 'gesendet'));
$offenRechnungen = array_values(array_filter($rechnungen, fn($r) => ($r['status'] ?? '') === 'offen'));
$offenBetrag = array_sum(array_map(fn($r) => (float)$r['brutto'], $offenRechnungen));
$inArbeit = count(array_filter($auftraege, fn($a) => $a['status'] !== 'versendet'));
// Menü-Zähler: was ist offen bzw. in Bearbeitung (Kundensicht) – als Badge am jeweiligen Menüpunkt.
$navKontingente = (int) scalar("SELECT COUNT(*) FROM angebot WHERE kunde_id=? AND jahresvertrag=1 AND status<>'offen' AND kunde_ausgeblendet=0 AND NOT EXISTS (SELECT 1 FROM kontingent kk WHERE kk.angebot_id=angebot.id)", [$kid])
                + (int) scalar("SELECT COUNT(*) FROM kontingent WHERE kunde_id=? AND status='wartet_vertrag'", [$kid]);
$navBadges = [
    'meine_anfragen' => $offenAngebote + count($anfPruef),
    'angebote'       => $offenAngebote,
    'bestellungen'   => $inArbeit,
    'rechnungen'     => count($offenRechnungen),
    'kontingente'    => $navKontingente,
];
$navBadgeTitel = [
    'meine_anfragen' => 'offene Vorgänge (Angebote zur Wahl / Vorschläge zur Prüfung / in Prüfung)',
    'angebote'       => 'Angebote zur Bestätigung',
    'bestellungen'   => 'Bestellungen in Bearbeitung',
    'rechnungen'     => 'offene Rechnungen',
    'kontingente'    => 'Jahresverträge, die auf Sie warten',
];
$portalLink = fn($v) => '?p=portal&token=' . $token . '&v=' . $v;
// Suchfeld für die Katalog-Listen. Behält Token und Ansicht bei, damit die Suche im Portal bleibt.
$sucheForm = function (string $v, string $platzhalter) use ($token, $q) { ?>
  <form method="get" class="bx-row" style="gap:8px;margin-bottom:14px;align-items:center">
    <input type="hidden" name="p" value="portal">
    <input type="hidden" name="token" value="<?= h($token) ?>">
    <input type="hidden" name="v" value="<?= h($v) ?>">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="<?= h($platzhalter) ?>" style="max-width:360px">
    <button class="btn btn-primary" type="submit">Suchen</button>
    <?php if ($q !== ''): ?><a class="btn btn-ghost" href="?p=portal&token=<?= h($token) ?>&v=<?= h($v) ?>">Zurücksetzen</a><?php endif; ?>
  </form>
<?php };
// Einheitliche Liste „Meine Anfragen" über ALLE Typen (Reiter nach Typ + Alle). Nach $portalLink, da dieser genutzt wird.
$typLabelP = ['rezeptur'=>'Rezeptur','produkt'=>'Produkt','rohstoff'=>'Rohstoff','dienstleistung'=>'Dienstleistung'];
$meineAnfRows = [];
foreach ($anfragen as $a) {
    $akt = null;
    if (($a['rezeptur_status'] ?? '') === 'vorschlag' && $a['rezeptur_id']) $akt = ['label'=>'Prüfen & entscheiden','href'=>$portalLink('rezeptur').'&rid='.(int)$a['rezeptur_id'],'primary'=>true];
    elseif (($a['status'] ?? '') === 'neu') $akt = ['label'=>'Bearbeiten','href'=>$portalLink('anfrage').'&edit='.(int)$a['id'],'primary'=>false];
    // Stufe (fuer Zaehlung/Reiter): wartet = Vorschlag zum Pruefen · erledigt = Rezeptur angelegt (eingefroren) ·
    // abgelehnt = abgelehnt/ueberarbeiten · offen = in Pruefung (neu/in Bearbeitung).
    $rs = $a['rezeptur_status'] ?? ''; $as2 = $a['status'] ?? '';
    $stufe = $rs === 'eingefroren' ? 'erledigt'
           : ($rs === 'vorschlag' ? 'wartet'
           : (($rs === 'abgelehnt' || $as2 === 'abgelehnt' || $as2 === 'ueberarbeiten') ? 'abgelehnt' : 'offen'));
    $rezLink = $akt['href'] ?? ((int)($a['rezeptur_id'] ?? 0) ? $portalLink('rezeptur') . '&rid=' . (int)$a['rezeptur_id'] : null);
    $meineAnfRows[] = ['typ'=>'rezeptur','nummer'=>$a['nummer'],'bez'=>($a['produktname'] ?: '(Rezeptur)'),'datum'=>$a['angelegt'],'status'=>$anfStatus($a),'aktion'=>$akt, 'loeschbar'=>empty($a['rezeptur_id']), 'del_typ'=>'rezeptur', 'del_id'=>(int)$a['id'],
        'link'=>$rezLink, 'stufe'=>$stufe];
}
foreach ($portalAnfragen as $p) {
    // Bei einer Rezeptur-Anfrage gibt es noch kein Produkt – dann den Rezepturnamen zeigen statt „Produkt".
    $bez = $p['typ']==='produkt'
        ? ($p['produkt_name'] ?: ($p['rezeptur_name'] ? $p['rezeptur_name'] . ' (aus Rezeptur)' : 'Produkt'))
        : ($p['betreff'] ?: ($typLabelP[$p['typ']] ?? 'Anfrage'));
    // Erledigt = Angebot bereits angenommen (es gibt einen Auftrag). Dann Bestell-Status zeigen + Nachbestellen.
    $erledigt = ($p['angebot_status'] ?? '') === 'bestaetigt';
    $aufSt = (string)($p['auftrag_status'] ?? '');
    $aufStLbl = match ($aufSt) { 'in_produktion'=>'in Produktion','erledigt'=>'versandbereit','versendet'=>'versendet', default=>'bestellt' };
    if ($erledigt) {
        $st = bx_badge($aufStLbl, $aufSt === 'versendet' ? 'ok' : 'info');
    } else {
        $st = ($p['status']==='beantwortet') ? bx_badge('Angebot erhalten','ok') : (($p['status']==='abgelehnt') ? (bx_badge('nicht machbar','err') . (!empty($p['absage_grund']) ? '<div class="muted" style="font-size:12px;white-space:normal;margin-top:4px">' . h($p['absage_grund']) . '</div>' : '')) : bx_badge('in Prüfung','warn'));
    }
    // Nächster Schritt je Zustand: erledigt -> Nachbestellen (neue Anfrage zum selben Produkt/Rezeptur);
    // Angebot liegt vor -> Menge annehmen (Karte #a<id> auf dieser Seite).
    $offLink = !empty($p['angebot_id']) ? $portalLink('meine_anfragen') . '#a' . (int)$p['angebot_id'] : null;
    if ($erledigt) {
        $nb = $portalLink('prodanfrage') . ((int)($p['produkt_id'] ?? 0) ? '&pid=' . (int)$p['produkt_id'] : ((int)($p['rezeptur_id'] ?? 0) ? '&rid=' . (int)$p['rezeptur_id'] : ''));
        $akt = ['label'=>'Nachbestellen','href'=>$nb,'primary'=>false];
    } else {
        $akt = $offLink ? ['label'=>'Menge annehmen','href'=>$offLink,'primary'=>true] : null;
    }
    $zeilenLink = ($erledigt && (int)($p['auftrag_id'] ?? 0)) ? $portalLink('bestellung') . '&aid=' . (int)$p['auftrag_id'] : $offLink;
    // Stufe: erledigt (bestellt) bzw. abgeschlossen (versendet) · abgelehnt (nicht machbar) · offen (in Prüfung/Angebot erhalten).
    $stufe = $erledigt ? ($aufSt === 'versendet' ? 'abgeschlossen' : 'erledigt')
           : (($p['status'] === 'abgelehnt') ? 'abgelehnt' : 'offen');
    $meineAnfRows[] = ['typ'=>$p['typ'],'nummer'=>$p['nummer'],'bez'=>$bez,'datum'=>$p['angelegt'],'status'=>$st,'aktion'=>$akt, 'loeschbar'=>empty($p['angebot_id']), 'del_typ'=>'portal', 'del_id'=>(int)$p['id'],
        'link'=>$zeilenLink, 'angebot_id'=>(int)($p['angebot_id'] ?? 0), 'erledigt'=>$erledigt, 'auftrag_id'=>(int)($p['auftrag_id'] ?? 0), 'stufe'=>$stufe];
}
usort($meineAnfRows, fn($x,$y) => strcmp((string)$y['datum'], (string)$x['datum']));
// Wirklich offene Anfragen (in Prüfung, noch kein Angebot) – für Zähler & Menü-Badge.
$pending      = array_values(array_filter($meineAnfRows, fn($r) => empty($r['angebot_id']) && ($r['stufe'] ?? '') === 'offen'));
$erledigtRows = array_values(array_filter($meineAnfRows, fn($r) => empty($r['angebot_id']) && in_array($r['stufe'] ?? '', ['erledigt','abgeschlossen','abgelehnt'], true)));
// Menü-Badge „Meine Anfragen": offene Angebote + Vorschläge zum Prüfen + Anfragen in Prüfung.
$navBadges['meine_anfragen'] = $offenAngebote + count($anfPruef) + count($pending);
$anfTabs = ['alle'=>'Alle'];
if ($k['portal_rezeptur'])       $anfTabs['rezeptur']='Rezepturen';
if ($k['portal_produkte'])       $anfTabs['produkt']='Produkte';
if ($k['portal_rohstoffe'])      $anfTabs['rohstoff']='Rohstoffe';
if ($k['portal_dienstleistung']) $anfTabs['dienstleistung']='Dienstleistung';
$atab = $_GET['atab'] ?? 'alle'; if (!isset($anfTabs[$atab])) $atab = 'alle';

// --- Angebot als PDF (bulkify-Belegvorlage, positionsbasiert) ---
// Spezifikation eines Rohstoffs im bulkify-Layout. Bewusst UNSER Dokument: die Unterlagen der
// Vorlieferanten kommen auf deren Briefpapier und gehen nicht an den Kunden.
if (($_GET['v'] ?? '') === 'spec_pdf') {
    $rid = (int)($_GET['rid'] ?? 0);
    // Nur Rohstoffe, die der Kunde im Katalog ohnehin sieht – UND deren Spezifikation freigegeben ist.
    $ok = $rid && $k['portal_rohstoffe'] && scalar("SELECT id FROM item WHERE id=? AND kategorie='rohstoff' AND gesperrt=0 AND spec_freigegeben=1", [$rid]);
    if (!$ok) { http_response_code(404); echo 'Nicht gefunden.'; exit; }
    require_once BX_ROOT . '/core/pdf_spec.php';
    $pdf = build_spec_pdf($rid);
    if ($pdf === null) { http_response_code(404); echo 'Nicht gefunden.'; exit; }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Spezifikation_' . $rid . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf; exit;
}
// Analysenzertifikat (CoA) einer Charge im bulkify-Layout – nur wenn freigegeben und der Rohstoff
// für den Kunden sichtbar ist.
if (($_GET['v'] ?? '') === 'coa_pdf') {
    $cid = (int)($_GET['cid'] ?? 0);
    $ok = $cid && $k['portal_rohstoffe'] && scalar("SELECT c.id FROM charge c JOIN item i ON i.id=c.item_id
             WHERE c.id=? AND c.coa_freigegeben=1 AND i.kategorie='rohstoff' AND i.gesperrt=0", [$cid]);
    if (!$ok) { http_response_code(404); echo 'Nicht gefunden.'; exit; }
    require_once BX_ROOT . '/core/pdf_spec.php';
    $pdf = build_coa_pdf($cid);
    if ($pdf === null) { http_response_code(404); echo 'Nicht gefunden.'; exit; }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="CoA_' . $cid . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf; exit;
}
if (($_GET['v'] ?? '') === 'angebot_pdf') {
    $aid = (int)($_GET['aid'] ?? 0);
    // Nur eigene Angebote – die Liste ist bereits auf den Kunden gefiltert.
    $a = null; foreach ($angebote as $x) if ((int)$x['id'] === $aid) { $a = $x; break; }
    if (!$a) { http_response_code(404); echo 'Angebot nicht gefunden.'; exit; }
    require_once BX_ROOT . '/core/pdf_angebot.php';
    if (!angebot_pdf_ausliefern($aid, (string)$a['nummer'])) { http_response_code(404); echo 'Angebot nicht gefunden.'; }
    exit;
}

// Jahresabnahmevertrag als PDF (Kunde) – nur eigenes Jahresvertrags-Angebot.
if (($_GET['v'] ?? '') === 'vertrag_pdf') {
    $aid = (int)($_GET['aid'] ?? 0);
    $a = $aid ? one("SELECT nummer FROM angebot WHERE id=? AND kunde_id=? AND jahresvertrag=1", [$aid, (int)$k['id']]) : null;
    if (!$a) { http_response_code(404); echo 'Vertrag nicht gefunden.'; exit; }
    require_once BX_ROOT . '/core/pdf_vertrag.php';
    $pdf = build_jahresvertrag_pdf($aid);
    if ($pdf === null) { http_response_code(409); echo 'Vertrag kann nicht erzeugt werden.'; exit; }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Jahresvertrag_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$a['nummer']) . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf; exit;
}

// Unterschriebenen Jahresvertrag (Scan) herunterladen – Kunde sieht seinen eigenen Upload.
if (($_GET['v'] ?? '') === 'jv_signiert') {
    $konId = (int)($_GET['kid'] ?? 0);
    $d = $konId ? one("SELECT d.datei, d.datei_orig FROM dokument d JOIN kontingent k ON k.id=d.objekt_id
                       WHERE d.objekt_typ='kontingent' AND d.objekt_id=? AND d.typ='jv_signiert' AND k.kunde_id=?
                       ORDER BY d.id DESC LIMIT 1", [$konId, (int)$k['id']]) : null;
    $pfad = $d ? BX_UPLOADS . '/' . basename((string)$d['datei']) : '';
    if (!$d || !is_file($pfad)) { http_response_code(404); echo 'Datei nicht gefunden.'; exit; }
    $ext = strtolower(pathinfo($pfad, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($ext === 'pdf' ? 'application/pdf' : 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext)));
    header('Content-Disposition: inline; filename="' . basename((string)($d['datei_orig'] ?: $d['datei'])) . '"');
    readfile($pfad); exit;
}

// --- Rohstoff-Details für das Anfrage-Popup (JSON, on-demand) – kundenfreundliche Felder + Kennwerte ---
if (($_GET['v'] ?? '') === 'rohstoff_info') {
    header('Content-Type: application/json; charset=utf-8');
    $iid = (int)($_GET['iid'] ?? 0);
    $it = ($k['portal_rohstoffe'] && $iid) ? one("SELECT name, name_lat, form, cas, synonym, ec_nr, bot_quelle, herkunftsland,
              allergene, haltbarkeit, lagerbedingungen, zusaetze, vegan, gvo_frei, bestrahlt, tse_bse_frei, zertifikate, spec_freigegeben
           FROM item WHERE id=? AND kategorie='rohstoff' AND gesperrt=0", [$iid]) : null;
    if (!$it) { echo '{}'; exit; }
    $rows = [];
    $add = function(string $label, $val) use (&$rows) { if ($val !== null && trim((string)$val) !== '') $rows[] = [$label, (string)$val]; };
    $jn  = fn($x) => ($x === null || $x === '') ? null : ((int)$x ? 'Ja' : 'Nein');
    $add('Darreichungsform', $FORMLBL_P[$it['form']] ?? $it['form']);
    $add('CAS', $it['cas']); $add('Lateinischer Name', $it['name_lat']); $add('Synonym', $it['synonym']);
    $add('EC-Nummer', $it['ec_nr']); $add('Botanische Quelle', $it['bot_quelle']); $add('Herkunftsland', $it['herkunftsland']);
    $add('Allergene', $it['allergene']); $add('Vegan', $jn($it['vegan'])); $add('GVO-frei', $jn($it['gvo_frei']));
    $add('Bestrahlt', $jn($it['bestrahlt'])); $add('TSE/BSE-frei', $jn($it['tse_bse_frei']));
    $add('Zertifikate', $it['zertifikate']); $add('Zusätze', $it['zusaetze']);
    $add('Haltbarkeit', $it['haltbarkeit']); $add('Lagerbedingungen', $it['lagerbedingungen']);
    foreach (all("SELECT parameter, wert FROM item_kennwert WHERE item_id=? ORDER BY sort, id", [$iid]) as $kw) $add((string)$kw['parameter'], $kw['wert']);
    // Freigegebene Dokumente: Spezifikation (falls spec_freigegeben) + CoAs freigegebener Chargen.
    $coaList = [];
    foreach (all("SELECT id, charge_nr, mhd FROM charge WHERE item_id=? AND coa_freigegeben=1 ORDER BY (wareneingang IS NULL), wareneingang DESC, id DESC", [$iid]) as $c)
        $coaList[] = ['cid' => (int)$c['id'], 'label' => ($c['charge_nr'] ?: ('#' . (int)$c['id'])) . ($c['mhd'] ? ' (MHD ' . date('m/Y', strtotime((string)$c['mhd'])) . ')' : '')];
    echo json_encode(['name' => $it['name'], 'rows' => $rows, 'spec' => ((int)$it['spec_freigegeben'] === 1), 'coas' => $coaList], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Verpackungs-Konformität (PPWR) je Bestellung als PDF ---
if (($_GET['v'] ?? '') === 'ppwr_pdf') {
    $aid = (int)($_GET['aid'] ?? 0);
    $auf = null; foreach ($auftraege as $x) if ((int)$x['id'] === $aid) { $auf = $x; break; }
    if (!$auf) { http_response_code(404); echo 'Bestellung nicht gefunden.'; exit; }
    require_once BX_ROOT . '/core/pdf_ppwr.php';
    $komp = [];
    foreach (produkt_verpackung_items((int)$auf['produkt_id']) as $vp) {
        $it = one("SELECT name, material, gewicht_g, volumen_ml, breite_mm, hoehe_mm, durchmesser_mm, tiefe_mm FROM item WHERE id=?", [$vp['id']]);
        if (!$it) continue;
        $komp[] = [
            'rolle'=>$vp['rolle'], 'name'=>$it['name'], 'material'=>$it['material'],
            'gewicht_g'=>$it['gewicht_g'], 'volumen_ml'=>$it['volumen_ml'], 'masse'=>ppwr_masse($it),
        ];
    }
    $adr = trim(($k['strasse'] ?? '') . ' ' . ($k['hausnummer'] ?? '')) . "\n" . trim(($k['plz'] ?? '') . ' ' . ($k['ort'] ?? ''));
    $pdf = build_ppwr_pdf([
        'nummer'=>$auf['nummer'], 'datum'=>$auf['angelegt'], 'empfaenger'=>$k['firma'], 'adresse'=>$adr,
        'produkt'=>$auf['produkt_name'] ?: '–', 'kundennummer'=>$k['kundennummer'] ?? '', 'ust_id'=>$k['ust_id'] ?? '',
    ], $komp);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Verpackungs-Konformitaet_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$auf['nummer']) . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdf; exit;
}

// --- Rechnung (RE) / Auftragsbestätigung (AB) je Bestellung als PDF ---
if (in_array(($_GET['v'] ?? ''), ['rechnung_pdf', 'ab_pdf'], true)) {
    $art = ($_GET['v'] === 'rechnung_pdf') ? 're' : 'ab';
    $aid = (int)($_GET['aid'] ?? 0);
    $auf = null; foreach ($auftraege as $x) if ((int)$x['id'] === $aid) { $auf = $x; break; }
    if (!$auf) { http_response_code(404); echo 'Bestellung nicht gefunden.'; exit; }
    $re = one("SELECT * FROM beleg WHERE auftrag_id=? AND typ='rechnung' ORDER BY id DESC LIMIT 1", [(int)$auf['id']]);
    if ($art === 're' && !$re) { http_response_code(404); echo 'Für diese Bestellung liegt noch keine Rechnung vor.'; exit; }
    require_once BX_ROOT . '/core/pdf_beleg.php';

    // USt: aus Rechnung, sonst Inland/Export
    $land = strtoupper(trim((string)($k['land'] ?? '')));
    $istInland = ($land === '' || in_array($land, ['DE','D','DEUTSCHLAND','GERMANY'], true));
    $ustSatz = $re ? (float)$re['ust_prozent'] : ($istInland ? (float) meta_get('ust_inland', 19) : 0.0);

    // Positionen: bevorzugt EINZELN wie im Angebot (Herstellung + Verpackung + Deckel + Etikett …) für die
    // bestätigte Konfiguration. Passt die Summe nicht exakt zum Auftrags-Netto, greift die Sammelposition.
    $nettoGesamt = $re ? (float)$re['netto'] : (float)$auf['gesamt_netto'];
    $menge = max(1, (int)$auf['menge']);
    $aufReco = ['angebot_id'=>$auf['angebot_id'] ?? null, 'menge'=>$menge, 'gesamt_netto'=>$nettoGesamt];
    $positionen = beleg_positionen_aus_auftrag($aufReco, $ustSatz);
    $produktStaffel = [];
    if ($positionen) {
        $produktStaffel = beleg_staffel_aus_auftrag(['menge'=>$menge, 'gesamt_netto'=>$nettoGesamt, 'stueck'=>$auf['stueck'] ?? 0, 'produkt_id'=>$auf['produkt_id'] ?? 0, 'produkt_name'=>$auf['produkt_name'] ?? '']);
    } else {
        // Fallback: eine Sammelposition (Netto exakt aus Beleg bzw. Auftrag).
        $preisCent = (int) round($nettoGesamt * 100 / $menge);
        $vName = $auf['verpackung_id'] ? scalar("SELECT name FROM item WHERE id=?", [(int)$auf['verpackung_id']]) : '';
        $besch = trim(((int)$auf['stueck'] ? (int)$auf['stueck'] . ' Stück je Packung' : '') . ($vName ? ' · ' . $vName : ''), ' ·');
        $positionen = [[
            'bezeichnung'  => $auf['produkt_name'] ?: 'Produkt',
            'beschreibung' => $besch,
            'menge'        => $menge,
            'einheit'      => 'Pkg.',
            'preis_cent'   => $preisCent,
            'ust_satz'     => $ustSatz,
        ]];
    }

    $adr = trim(($k['strasse'] ?? '') . ' ' . ($k['hausnummer'] ?? '')) . "\n" . trim(($k['plz'] ?? '') . ' ' . ($k['ort'] ?? ''));
    if (!$istInland && !empty($k['land'])) $adr .= "\n" . $k['land'];
    $zaMap = ['vorkasse'=>'Vorkasse','rechnung'=>'Rechnung','lastschrift'=>'Lastschrift','paypal'=>'PayPal'];
    $za = $zaMap[$k['zahlungsart'] ?? 'vorkasse'] ?? ucfirst((string)($k['zahlungsart'] ?? 'Vorkasse'));

    $angNr = $auf['angebot_id'] ? scalar("SELECT nummer FROM angebot WHERE id=?", [(int)$auf['angebot_id']]) : '';
    if ($art === 're') {
        $datum = $re['datum'] ?: $re['angelegt'];
        $ziel  = (int) meta_get('zahlungsziel_tage', 14);
        $b = [
            'belegart_label'    => 'Rechnung',
            'nummer'            => $re['nummer'],
            'datum'             => $datum,
            'faellig_bis'       => date('Y-m-d', strtotime($datum . ' +' . $ziel . ' days')),
            'bezug'             => 'Auftrag ' . $auf['nummer'],
            'kopf_text'         => 'Wir berechnen Ihnen wie folgt:',
            // Zahlungsziel gehört in die Zahlungsbedingung; „hinweis" bleibt frei -> Hinweis zur Herstellung
            // kommt aus den Einstellungen (bh_hinweis_herstellung).
            'zahlungsbedingung' => 'Zahlbar innerhalb von ' . $ziel . ' Tagen ohne Abzug.',
        ];
        $fname = 'Rechnung_' . $re['nummer'];
    } else {
        $b = [
            'belegart_label'    => 'Auftragsbestätigung',
            'nummer'            => $auf['nummer'],
            'datum'             => $auf['angelegt'],
            'bezug'             => $angNr ? ('Angebot ' . $angNr) : '',
            'kopf_text'         => 'Vielen Dank für Ihren Auftrag. Wir bestätigen Ihnen die folgende Bestellung:',
            'hinweis'           => '',
        ];
        $fname = 'Auftragsbestaetigung_' . $auf['nummer'];
    }
    $b += [
        'empfaenger'       => $k['firma'],
        'adresse'          => $adr,
        'kundennummer'     => $k['kundennummer'] ?? '',
        'ust_id'           => $k['ust_id'] ?? '',
        'zahlungsart_label'=> $za,
        'bearbeiter'       => '',
        'bearbeiter_email' => '',
        'staffel_ust'      => $ustSatz,   // Gesamt-inkl.-USt-Spalte in „Preis je fertiges Produkt"
    ];

    $pdf = build_beleg_pdf($b, $positionen, $produktStaffel);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9_-]/', '', $fname) . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdf; exit;
}

portal_head('Kundenportal · ' . $k['firma']);
?>
<div class="bx-shell">
  <aside class="bx-side">
    <div class="bx-brand"><img src="assets/bulkify-logo-white.png" alt="bulkify" class="bx-logo"><span class="bx-ver">Portal</span></div>
    <nav>
      <div class="bx-navgroup"><?= h($k['firma']) ?></div>
      <?php foreach ($NAVGROUPS as $gruppe => $keys):
          $sichtbar = array_values(array_filter($keys, fn($key) => isset($L[$key])));
          if (!$sichtbar) continue;
          if ($gruppe !== ''): ?><div class="bx-navgroup"><?= h($gruppe) ?></div><?php endif;
          foreach ($sichtbar as $key): ?>
            <a href="<?= $portalLink($key) ?>"<?= $activeItem===$key ? ' class="on"' : '' ?>><?= h($L[$key]) ?><?php
              $nv = (int)($navBadges[$key] ?? 0);
              if ($nv > 0): ?> <span class="pt-badge" style="float:right" title="<?= $nv ?> <?= h($navBadgeTitel[$key] ?? 'offen') ?>"><?= $nv ?></span><?php endif; ?></a>
          <?php endforeach;
      endforeach; ?>
      <div class="bx-userbox"><button type="button" class="bx-themebtn">Dunkler Modus</button></div>
      <div class="bx-userbox" style="padding-top:0"><a class="muted" style="font-size:12px" href="?p=portal&v=logout">Abmelden</a></div>
      <?php if (agb_aktuell()): ?><div class="bx-userbox" style="padding-top:0"><a class="muted" style="font-size:12px" href="<?= $portalLink('agb') ?>">AGB</a></div><?php endif; ?>
    </nav>
  </aside>
  <?= bx_menue_scrim() ?>
  <main class="bx-main"><?= bx_mobilbar() ?>
  <?php if (isset($_GET['ok'])): ?><div class="bx-panel badge-ok" style="padding:12px 16px">Vielen Dank – Ihre Bestätigung ist eingegangen. Wir starten die Bearbeitung.</div><?php endif; ?>
  <?php if (isset($_GET['anfrage'])): ?><div class="bx-panel badge-ok" style="padding:12px 16px">Ihre Rezepturanfrage ist eingegangen – wir prüfen sie und melden uns.</div><?php endif; ?>
  <?php if (isset($_GET['angenommen'])): ?><div class="bx-panel badge-ok" style="padding:12px 16px">Vielen Dank – die Rezeptur ist angenommen. Sie ist jetzt verbindlich festgelegt.</div><?php endif; ?>
  <?php if (isset($_GET['gesendet'])): ?><div class="bx-panel badge-ok" style="padding:12px 16px">Ihre Anfrage ist eingegangen – wir prüfen sie und melden uns mit einem Angebot.</div><?php endif; ?>
  <?php if (isset($_GET['geaendert'])): ?><div class="bx-panel badge-ok" style="padding:12px 16px">Ihre geänderte Menge ist eingegangen – wir überarbeiten das Angebot und melden uns.</div><?php endif; ?>
  <?php if (isset($_GET['geloescht'])): ?><div class="bx-panel badge-ok" style="padding:12px 16px">Anfrage gelöscht.</div><?php endif; ?>
  <?php if (isset($_GET['loeschfehler'])): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Diese Anfrage lässt sich nicht mehr löschen – wir sind bereits dabei oder haben Ihnen schon ein Angebot gemacht. Melden Sie sich bei uns, dann klären wir das.</div><?php endif; ?>

<?php if ($view === 'start'): ?>
  <h1 style="margin-bottom:4px">Willkommen, <?= h($k['ansprechpartner'] ?: $k['firma']) ?></h1>
  <p class="bx-sub">Ihr Überblick – wählen Sie im Menü einen Bereich für Details.</p>

  <?php if ($vorschlaege): $nV = count($vorschlaege); ?>
  <a href="#vorschlaege" class="bx-panel" style="display:flex;justify-content:space-between;align-items:center;gap:12px;text-decoration:none;color:inherit;border-color:var(--gruen);background:var(--panel-2)">
    <div><strong><?= $nV ?> <?= $nV === 1 ? 'Anfrage wurde beantwortet' : 'Anfragen wurden beantwortet' ?></strong> – Ihr <?= $nV === 1 ? 'Rezeptur-Vorschlag liegt' : 'Rezeptur-Vorschläge liegen' ?> zur Freigabe bereit.</div>
    <span class="btn btn-primary">Ansehen</span>
  </a>
  <?php endif; ?>

  <div class="pt-cards">
    <a class="pt-card" href="<?= $portalLink('meine_anfragen') ?>&oatab=zubestaetigen" style="text-decoration:none;color:inherit"><div class="k">Offene Angebote</div><div class="val"><?= $offenAngebote ?></div></a>
    <a class="pt-card" href="<?= $portalLink('bestellungen') ?>" style="text-decoration:none;color:inherit"><div class="k">Bestellungen in Arbeit</div><div class="val"><?= $inArbeit ?></div></a>
    <a class="pt-card" href="<?= $portalLink('rechnungen') ?>" style="text-decoration:none;color:inherit"><div class="k">Offene Rechnungen</div><div class="val"><?= $eur($offenBetrag) ?></div></a>
  </div>

  <?php if ($vorschlaege): ?>
  <div class="bx-panel" id="vorschlaege" style="border-color:var(--gruen);background:var(--panel-2)">
    <h2>Rezeptur-Vorschläge für Sie</h2>
    <p class="muted" style="margin-top:0">Wir haben Ihre Anfrage geprüft. Bitte schauen Sie sich den Vorschlag an und nehmen Sie ihn an, damit wir starten können.</p>
    <?php foreach ($vorschlaege as $vs): ?>
      <div class="bx-panel" style="background:var(--panel)">
        <div class="bx-row" style="justify-content:space-between"><div><strong><?= h($vs['name']) ?></strong> · <?= h($vs['nummer']) ?></div><div><?= bx_badge('Vorschlag','info') ?></div></div>
        <table class="bx-table" style="margin:10px 0"><thead><tr><th>Zutat</th><th class="bx-num">Menge je Einheit</th></tr></thead><tbody>
          <?php $vsum = 0; foreach ($vorschlagZutaten[$vs['id']] as $z): $vsum += (float)$z['menge_mg']; ?>
            <tr><td><?= h($z['bezeichnung']) ?></td><td class="bx-num"><?= rtrim(rtrim(number_format((float)$z['menge_mg'],2,',','.'),'0'),',') ?> mg</td></tr>
          <?php endforeach; ?>
          <tr style="font-weight:600"><td>Gesamt je Einheit</td><td class="bx-num"><?= rtrim(rtrim(number_format($vsum,2,',','.'),'0'),',') ?> mg</td></tr>
        </tbody></table>
        <?php if ($vs['darreichungsform'] === 'kapsel'): $vkg = $kapselAnzeige($vs, $vsum); ?>
          <div class="muted" style="margin:-4px 0 10px;font-size:13px">Kapselgröße: <strong><?= $vkg ? h($vkg['name']) : 'individuell (Füllgewicht > Standardkapsel)' ?></strong></div>
        <?php endif; ?>
        <span style="display:inline"><button class="btn btn-primary" type="button" onclick="bxBestaetigen('Rezeptur verbindlich annehmen', '<div class=\'bx-panel\' style=\'margin:0 0 12px;padding:12px 14px\'><strong><?= h(($vs['nummer'] ?? '') . ' ' . ($vs['name'] ?? '')) ?></strong></div>Mit der Annahme wird die Rezeptur <strong>eingefroren</strong>: Zusammensetzung und Mengen sind damit festgelegt und k&ouml;nnen nicht mehr ge&auml;ndert werden. &Auml;nderungen brauchen danach eine neue Rezeptur.', 'Ich habe die Rezeptur gepr&uuml;ft und nehme sie verbindlich an. Mir ist bewusst, dass sie danach nicht mehr ge&auml;ndert werden kann.', {aktion:'rezeptur_annehmen', rezeptur_id:'<?= (int)$vs['id'] ?>'})">Rezeptur verbindlich annehmen</button></span>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($offenAngebote): ?>
  <div class="bx-panel" style="border-color:#cfe0cb">
    <div class="bx-row" style="justify-content:space-between;align-items:center">
      <div>Sie haben <strong><?= $offenAngebote ?></strong> offene<?= $offenAngebote===1?'s':'' ?> Angebot<?= $offenAngebote===1?'':'e'?> zur Bestätigung.</div>
      <a class="btn btn-primary" href="<?= $portalLink('meine_anfragen') ?>&oatab=zubestaetigen">Menge wählen &amp; annehmen</a>
    </div>
  </div>
  <?php endif; ?>

<?php elseif ($view === 'anfrage'):
    // Bearbeiten-Modus: nur eigene Anfrage mit Status „neu" (noch nicht in Bearbeitung durch uns).
    $editAnf = null; $editWunsch = [];
    if (!empty($_GET['edit']) && $k) {
        $editAnf = one("SELECT * FROM rezeptur_anfrage WHERE id=? AND kunde_id=? AND status='neu'", [(int)$_GET['edit'], (int)$k['id']]);
        if ($editAnf) $editWunsch = all("SELECT * FROM rezeptur_anfrage_wunsch WHERE anfrage_id=? ORDER BY sort, id", [(int)$editAnf['id']]);
    }
    $ea = fn($key, $d = '') => h((string)($editAnf[$key] ?? $d));
    $wzeilen = $editAnf ? $editWunsch : [];   // im Bearbeiten-Modus vorhandene Zeilen, sonst leer (→ 3 Leerzeilen)
?>
  <h1 style="margin-bottom:4px"><?= $editAnf ? 'Anfrage bearbeiten' : 'Rezeptur anfragen' ?></h1>
  <div class="bx-panel">
    <?php if (isset($_GET['geaendert'])): ?><div class="bx-panel badge-ok" style="padding:10px 14px;margin-bottom:12px">Ihre Anfrage wurde aktualisiert.</div><?php endif; ?>
    <?php if (isset($_GET['fehlt'])): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:10px 14px;margin-bottom:12px">Bitte einen <strong>Wunsch-Produktnamen</strong> angeben und entweder <strong>Ihre Idee</strong> beschreiben oder mindestens eine <strong>Zutat mit Menge</strong> eintragen.</div><?php endif; ?>
    <p class="muted" style="margin-top:0"><?= $editAnf ? 'Sie können Ihre Anfrage <strong>' . h($editAnf['nummer']) . '</strong> ändern, solange wir sie noch nicht bearbeiten.' : 'Beschreiben Sie einfach Ihre Idee – konkrete Zutaten sind nicht nötig. Wir prüfen die Machbarkeit und melden uns mit einem Vorschlag.' ?></p>
    <form method="post" id="pf_anfrageform">
      <input type="hidden" name="aktion" value="<?= $editAnf ? 'anfrage_bearbeiten' : 'anfrage_senden' ?>">
      <?php if ($editAnf): ?><input type="hidden" name="anfrage_id" value="<?= (int)$editAnf['id'] ?>"><?php endif; ?>
      <div class="bx-grid">
        <div class="bx-field"><label>Wunsch-Produktname <?= bx_hint('Wie soll das Produkt heißen? Arbeitstitel – Sie können ihn später ändern.') ?></label><input type="text" name="produktname" required value="<?= $ea('produktname') ?>" placeholder="z. B. Immun-Komplex Forte"></div>
        <div class="bx-field"><label>Darreichungsform</label>
          <select name="form" id="pf_form"><?php foreach ($DFORM_P as $key=>$lbl): ?><option value="<?= $key ?>" <?= ($editAnf['darreichungsform'] ?? '')===$key?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?></select>
        </div>
      </div>
      <div class="bx-field" style="margin-bottom:16px"><label>Ihre Idee</label>
        <textarea name="notiz" rows="4" placeholder="z. B. veganes Produkt für besseren Schlaf, 2 Kapseln abends, gerne pflanzlich und ohne Melatonin"><?= $ea('notiz') ?></textarea>
        <div class="muted" style="font-size:13px;margin-top:4px">Zielgruppe, Wirkung, Wünsche – alles, was Ihnen wichtig ist. Je mehr Sie uns verraten, desto besser: Das hilft uns, Ihnen direkt die bestmögliche Rezeptur anzubieten.</div></div>
      <div class="muted" style="font-size:13px;margin-bottom:6px">Sie haben schon konkrete Zutaten? Dann tragen Sie sie hier ein – sonst lassen Sie die Tabelle einfach leer.</div>
      <table class="bx-table" style="margin-bottom:10px">
        <thead><tr><th>Wirkstoff / Zutat</th><th style="width:120px">Menge je Kapsel</th><th style="width:90px">Einheit</th><th></th></tr></thead>
        <tbody id="pwrows">
          <?php
          $rows = $wzeilen ?: array_fill(0, 3, ['bezeichnung'=>'','wunsch_menge'=>'','einheit'=>'mg']);
          foreach ($rows as $wz): ?>
          <tr class="pwrow">
            <td><input type="text" name="w_bez[]" value="<?= h((string)($wz['bezeichnung'] ?? '')) ?>" placeholder="z. B. Vitamin C"></td>
            <td><input type="text" name="w_menge[]" value="<?= h((string)($wz['wunsch_menge'] ?? '')) ?>"></td>
            <td><select name="w_einheit[]" style="width:80px"><?php foreach (['mg','g','µg','IE','ml'] as $eh): ?><option value="<?= $eh ?>" <?= ($wz['einheit'] ?? 'mg')===$eh?'selected':'' ?>><?= $eh ?></option><?php endforeach; ?></select></td>
            <td><button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('.pwrow').remove()">×</button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <button type="button" class="btn btn-ghost btn-sm" id="paddW">+ Zutat</button>
      <div id="pf_kapselcheck" style="display:none;margin-top:14px;border-radius:var(--r);padding:12px 14px;font-size:14px"></div>
      <button class="btn btn-primary" type="submit"><?= $editAnf ? 'Änderungen speichern' : 'Anfrage senden' ?></button>
      <?php if ($editAnf): ?><a class="btn btn-ghost" href="<?= $portalLink('anfrage') ?>&anfrage=1">Abbrechen</a><?php endif; ?>
    </form>
  </div>

  <div class="bx-panel"><div class="muted">Alle Ihre Anfragen und deren Stand finden Sie unter <a href="<?= $portalLink('meine_anfragen') ?>">Meine Anfragen</a>.</div></div>

<?php elseif ($view === 'meine_anfragen'): ?>
  <h1 style="margin-bottom:4px">Meine Anfragen</h1>
  <p class="bx-sub">Alle Ihre Anfragen und deren Stand. Liegt ein Angebot vor, wählen Sie hier die Menge und bestätigen verbindlich.</p>
  <?php if (isset($_GET['freigabefehlt'])): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Für die verbindliche Annahme fehlen die Bestätigung und Ihr Name.</div><?php endif; ?>
  <?php if (isset($_GET['abgelehnt'])): ?><div class="bx-panel badge-ok" style="padding:12px 16px">Ihre Rückmeldung ist eingegangen – wir überarbeiten das Angebot.</div><?php endif; ?>

  <?php
  $istLeer = !$meineAnfRows && empty($angebote);
  // Einstiegs-Optionen – nur die für diesen Kunden freigegebenen Anfrage-Typen.
  $anfrageOpt = [];
  if (!empty($k['portal_rezeptur']))      $anfrageOpt[] = ['t'=>'Rezeptur anfragen',      's'=>'Eigene Rezeptur von uns entwickeln lassen.', 'r'=>'anfrage'];
  if (!empty($k['portal_produkte']))      $anfrageOpt[] = ['t'=>'Produkt anfragen',       's'=>'Fertiges Produkt aus unserem Katalog.',      'r'=>'prodanfrage'];
  if (!empty($k['portal_rohstoffe']))     $anfrageOpt[] = ['t'=>'Rohstoff anfragen',      's'=>'Einzelnen Rohstoff anfragen.',                'r'=>'rohanfrage'];
  if (!empty($k['portal_dienstleistung']))$anfrageOpt[] = ['t'=>'Dienstleistung anfragen','s'=>'z. B. Abfüllung oder Verpackung.',            'r'=>'dienstleistung'];
  ?>
  <?php if ($istLeer): ?>
  <div class="bx-panel">
    <h2 style="margin-top:0">Noch keine Anfragen</h2>
    <p class="muted" style="margin-top:0">Sagen Sie uns einfach, was Sie brauchen:</p>
    <div class="pt-choice">
      <?php foreach ($anfrageOpt as $o): ?>
      <a class="pt-choice-card" href="<?= $portalLink($o['r']) ?>">
        <div class="t"><?= h($o['t']) ?></div>
        <div class="s"><?= h($o['s']) ?></div>
        <span class="pt-choice-go">Anfragen →</span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php else:
    // Reiter Offen · Bestätigt · Abgelehnt. $pending (in Prüfung) und $erledigtRows stehen global (oben berechnet).
    $offen_ang = array_values(array_filter($angebote, fn($x) => $x['status'] === 'gesendet'));
    $best_ang  = array_values(array_filter($angebote, fn($x) => $x['status'] === 'bestaetigt')); // in Arbeit + versendet
    $abgel_ang = array_values(array_filter($angebote, fn($x) => $x['status'] === 'abgelehnt'));
    // Angenommene Rezepturen (eingefroren = „Rezeptur angelegt") -> BESTÄTIGT; abgelehnte Anfragen -> ABGELEHNT.
    $bestRows = array_values(array_filter($erledigtRows, fn($r) => ($r['stufe'] ?? '') === 'erledigt'));
    $abglRows = array_values(array_filter($erledigtRows, fn($r) => in_array($r['stufe'] ?? '', ['abgelehnt','abgeschlossen'], true)));
    $nZuBest = count($offen_ang);                        // Angebote mit Preis – „Zu bestätigen"
    $nOffen  = count($anfPruef) + count($pending);       // Vorschläge (wartet auf Sie) + in Prüfung – noch ohne Preis
    $sumBest  = count($best_ang) + count($bestRows);     // bestätigt (in Arbeit/versendet) + Rezeptur angelegt
    $sumAbgel = count($abgel_ang) + count($abglRows);    // abgelehnt / nicht machbar
    // Unterreiter nach Typ: die Zähler oben bleiben GESAMT; hier nur die Anzeige-Listen filtern.
    $angTyp = [];
    // Typ je verknüpfter Anfrage in EINER Abfrage holen (statt einer pro Angebot – N+1 vermeiden).
    $anfIds = array_values(array_unique(array_filter(array_map(fn($a) => (int)($a['anfrage_id'] ?? 0), $angebote))));
    $anfTypMap = [];
    if ($anfIds) {
        $in = implode(',', array_fill(0, count($anfIds), '?'));
        foreach (all("SELECT id, typ FROM portal_anfrage WHERE id IN ($in)", $anfIds) as $row) $anfTypMap[(int)$row['id']] = (string)$row['typ'];
    }
    foreach ($angebote as $ang) { $angTyp[$ang['id']] = !empty($ang['anfrage_id']) ? ($anfTypMap[(int)$ang['anfrage_id']] ?? 'produkt') : 'produkt'; }
    // Aktiver Haupt-Reiter + Anzahl je Typ IN diesem Reiter (für die Zahlen an den Unterreitern) – aus den ungefilterten Listen.
    $oatab = in_array($_GET['oatab'] ?? '', ['zubestaetigen','bestaetigt','abgelehnt'], true) ? $_GET['oatab'] : 'offen';
    $typsInTab = [];
    if ($oatab === 'offen') {
        foreach ($anfPruef as $x)   $typsInTab[] = 'rezeptur';
        foreach ($pending as $r)    $typsInTab[] = $r['typ'] ?? '';
    } elseif ($oatab === 'zubestaetigen') {
        foreach ($offen_ang as $a)  $typsInTab[] = $angTyp[$a['id']] ?? 'produkt';
    } elseif ($oatab === 'bestaetigt') {
        foreach ($best_ang as $a)   $typsInTab[] = $angTyp[$a['id']] ?? 'produkt';
        foreach ($bestRows as $r)   $typsInTab[] = $r['typ'] ?? '';
    } else {
        foreach ($abgel_ang as $a)  $typsInTab[] = $angTyp[$a['id']] ?? 'produkt';
        foreach ($abglRows as $r)   $typsInTab[] = $r['typ'] ?? '';
    }
    $typCount = ['alle' => count($typsInTab)];
    foreach ($typsInTab as $t) $typCount[$t] = ($typCount[$t] ?? 0) + 1;
    $mt = fn($t) => $atab === 'alle' || $t === $atab;
    $offen_ang = array_values(array_filter($offen_ang, fn($a) => $mt($angTyp[$a['id']] ?? 'produkt')));
    $best_ang  = array_values(array_filter($best_ang,  fn($a) => $mt($angTyp[$a['id']] ?? 'produkt')));
    $abgel_ang = array_values(array_filter($abgel_ang, fn($a) => $mt($angTyp[$a['id']] ?? 'produkt')));
    $pending   = array_values(array_filter($pending,   fn($r) => $mt($r['typ'] ?? '')));
    $bestRows  = array_values(array_filter($bestRows,  fn($r) => $mt($r['typ'] ?? '')));
    $abglRows  = array_values(array_filter($abglRows,  fn($r) => $mt($r['typ'] ?? '')));
    $anfPruefShow = ($atab === 'alle' || $atab === 'rezeptur') ? $anfPruef : [];  // Vorschläge = Rezepturen
  ?>

  <?php if ($anfPruefShow): ?>
  <div class="bx-panel" style="border-color:var(--gruen);background:var(--panel-2)">
    <h2 style="margin-top:0">Wartet auf Sie (<?= count($anfPruefShow) ?>)</h2>
    <p class="muted" style="margin-top:0">Zu diesen Anfragen liegt ein Vorschlag bereit – bitte prüfen und annehmen oder ablehnen.</p>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Nummer</th><th>Wunschname</th><th>Form</th><th>Vorschlag</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($anfPruefShow as $an): ?>
        <tr>
          <td><?= h($an['nummer']) ?></td>
          <td><?= $an['produktname'] ? h($an['produktname']) : '<span class="muted">–</span>' ?></td>
          <td><?= h($DFORM_P[$an['darreichungsform']] ?? $an['darreichungsform']) ?></td>
          <td><?= $an['rezeptur_name'] ? h($an['rezeptur_name']) : '<span class="muted">–</span>' ?></td>
          <td style="text-align:right"><a class="btn btn-primary btn-sm" href="<?= $portalLink('rezeptur') ?>&rid=<?= (int)$an['rezeptur_id'] ?>">Prüfen &amp; entscheiden</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>

  <?php // Drei Reiter: Offen · Bestätigt (angenommen/bestellt/versendet + Rezeptur angelegt) · Abgelehnt. ($oatab oben gesetzt.) ?>
  <h2 style="margin:8px 0 6px">Ihre Vorgänge</h2>
  <div class="settabs" style="margin:0 0 8px">
    <a href="<?= $portalLink('meine_anfragen') ?>&oatab=offen&atab=<?= $atab ?>"      class="<?= $oatab === 'offen' ? 'on' : '' ?>">Offen<?= $nOffen ? ' (' . $nOffen . ')' : '' ?></a>
    <a href="<?= $portalLink('meine_anfragen') ?>&oatab=zubestaetigen&atab=<?= $atab ?>" class="<?= $oatab === 'zubestaetigen' ? 'on' : '' ?>">Zu bestätigen<?= $nZuBest ? ' (' . $nZuBest . ')' : '' ?></a>
    <a href="<?= $portalLink('meine_anfragen') ?>&oatab=bestaetigt&atab=<?= $atab ?>" class="<?= $oatab === 'bestaetigt' ? 'on' : '' ?>">Bestätigt<?= $sumBest ? ' (' . $sumBest . ')' : '' ?></a>
    <a href="<?= $portalLink('meine_anfragen') ?>&oatab=abgelehnt&atab=<?= $atab ?>"  class="<?= $oatab === 'abgelehnt' ? 'on' : '' ?>">Abgelehnt<?= $sumAbgel ? ' (' . $sumAbgel . ')' : '' ?></a>
  </div>
  <?php if (count($anfTabs) > 1): ?>
  <div class="pt-subtabs">
    <?php foreach ($anfTabs as $tk => $tl): $tc = (int)($typCount[$tk] ?? 0); ?>
      <a href="<?= $portalLink('meine_anfragen') ?>&oatab=<?= $oatab ?>&atab=<?= $tk ?>" class="<?= $atab === $tk ? 'on' : '' ?>"><?= h($tl) ?><?= $tc ? ' (' . $tc . ')' : '' ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php
  // Kleine Tabelle für Anfragen ohne Angebotskarte (Rezeptur angelegt / abgelehnt). Vor der if/elseif-Kette
  // definiert, damit sie in allen Reitern verfügbar ist.
  $anfrageTabelle = function(array $rows, string $titel, string $sub) use ($typLabelP) { if (!$rows) return; ?>
    <div class="bx-panel">
      <h2 style="margin:0 0 4px"><?= h($titel) ?></h2>
      <p class="muted" style="margin:0 0 12px;font-size:13px"><?= h($sub) ?></p>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Nummer</th><th>Typ</th><th>Bezeichnung</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= h($r['nummer']) ?></td>
            <td><?= h($typLabelP[$r['typ']] ?? $r['typ']) ?></td>
            <td><?= $r['bez'] ? h($r['bez']) : '<span class="muted">–</span>' ?></td>
            <td><?= $r['status'] ?></td>
            <td style="text-align:right"><?php if (!empty($r['link'])): ?><a class="btn btn-ghost btn-sm" href="<?= h($r['link']) ?>">ansehen</a><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  <?php }; ?>
  <?php if ($oatab === 'zubestaetigen'): ?>
    <?php if ($offen_ang): ?>
    <p class="muted" style="margin:0 0 12px">Klappen Sie ein Angebot auf, wählen Sie die gewünschte Menge und bestätigen Sie verbindlich.</p>
    <?php foreach ($offen_ang as $a): $st = $staffelFuer($a); $inf = $angInfoFuer($a); $accept = true; $open = true; include __DIR__ . '/_angebot_karte.php'; endforeach; ?>
    <?php else: ?><div class="bx-panel"><div class="muted">Aktuell liegt kein Angebot zum Bestätigen vor.</div></div><?php endif; ?>

  <?php elseif ($oatab === 'offen'): ?>
    <?php if ($pending): ?>
    <div class="bx-panel">
      <h2 style="margin:0 0 4px">In Prüfung</h2>
      <p class="muted" style="margin:0 0 12px;font-size:13px">Anfragen, zu denen wir uns mit einem Angebot melden.</p>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Nummer</th><th>Typ</th><th>Bezeichnung</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pending as $r): ?>
          <tr>
            <td><?= h($r['nummer']) ?></td>
            <td><?= h($typLabelP[$r['typ']] ?? $r['typ']) ?></td>
            <td><?= $r['bez'] ? h($r['bez']) : '<span class="muted">–</span>' ?></td>
            <td><?= $r['status'] ?></td>
            <td style="text-align:right">
              <div class="bx-row" style="gap:8px;justify-content:flex-end">
                <?php if ($r['aktion']): ?><a class="btn <?= $r['aktion']['primary'] ? 'btn-primary' : 'btn-ghost' ?> btn-sm" href="<?= h($r['aktion']['href']) ?>"><?= h($r['aktion']['label']) ?></a><?php endif; ?>
                <?php if (!empty($r['loeschbar'])): ?>
                <form method="post" style="margin:0" onsubmit="return confirm('Anfrage <?= h($r['nummer']) ?> wirklich löschen?');">
                  <input type="hidden" name="aktion" value="anfrage_loeschen"><input type="hidden" name="anf_typ" value="<?= h($r['del_typ']) ?>"><input type="hidden" name="anf_id" value="<?= (int)$r['del_id'] ?>">
                  <button class="btn btn-ghost btn-sm" type="submit">Löschen</button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php endif; ?>
    <?php if (!$pending && !$anfPruefShow): ?><div class="bx-panel"><div class="muted">Aktuell nichts Offenes. Neue Anfragen stellen Sie über das Menü links.</div></div><?php endif; ?>

  <?php elseif ($oatab === 'bestaetigt'): ?>
    <?php if (!$best_ang && !$bestRows): ?><div class="bx-panel"><div class="muted">Keine bestätigten Vorgänge.</div></div><?php endif; ?>
    <?php foreach ($best_ang as $a): $st = $staffelFuer($a); $inf = $angInfoFuer($a); $accept = false; $open = false; include __DIR__ . '/_angebot_karte.php'; endforeach; ?>
    <?php $anfrageTabelle($bestRows, 'Angenommene Rezepturen', 'Von Ihnen angenommen – die Rezeptur ist angelegt. Als nächstes können Sie sie als Produkt anfragen.'); ?>

  <?php else: /* abgelehnt */ ?>
    <?php if (!$abgel_ang && !$abglRows): ?><div class="bx-panel"><div class="muted">Nichts abgelehnt.</div></div><?php endif; ?>
    <?php foreach ($abgel_ang as $a): $st = $staffelFuer($a); $inf = $angInfoFuer($a); $accept = false; $open = false; include __DIR__ . '/_angebot_karte.php'; endforeach; ?>
    <?php $anfrageTabelle($abglRows, 'Abgelehnte Anfragen', 'Vom Kunden abgelehnt oder von uns als nicht machbar zurückgemeldet.'); ?>
  <?php endif; ?>
  <script>(function(){ var h=location.hash; if(h && /^#a\d+$/.test(h)){ var d=document.querySelector(h); if(d && d.tagName==='DETAILS'){ d.open=true; d.scrollIntoView(); } } })();</script>
  <?php endif; /* istLeer */ ?>

<?php elseif ($view === 'rezepturen'):
    // Eigene = Rezepturen DIESES Kunden (kunde_id == kid); Katalog = alle übrigen freigegebenen (kunde_id NULL/andere).
    $eigeneRez  = array_values(array_filter($meineRezepturen, fn($r) => (int)($r['kunde_id'] ?? 0) === $kid));
    $katalogRez = array_values(array_filter($meineRezepturen, fn($r) => (int)($r['kunde_id'] ?? 0) !== $kid));
    $rezTabelle = function(array $liste) use ($DFORM_P, $rezBadge, $portalLink) { ?>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Nummer</th><th>Name</th><th>Form</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($liste as $rz): ?>
          <tr>
            <td><?= h($rz['nummer']) ?></td>
            <td><?= h($rz['name']) ?></td>
            <td><?= h($DFORM_P[$rz['darreichungsform']] ?? $rz['darreichungsform']) ?></td>
            <td><?= $rezBadge($rz['status']) ?></td>
            <td style="text-align:right"><a class="btn btn-ghost btn-sm" href="<?= $portalLink('rezeptur') ?>&rid=<?= (int)$rz['id'] ?>">ansehen</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php }; ?>
  <h1 style="margin-bottom:4px">Rezepturen</h1>
  <p class="bx-sub">Ihre eigenen Rezepturen und unsere freigegebenen Katalog-Rezepturen. Wählen Sie eine für Details – oder stellen Sie eine neue Rezepturanfrage.</p>

  <div class="bx-panel">
    <?php $sucheForm('rezepturen', 'Rezeptur oder Rohstoff suchen, z. B. Zink'); ?>
    <?php if ($q !== '' && !$eigeneRez && !$katalogRez): ?>
      <div class="muted">Keine Rezeptur gefunden zu „<?= h($q) ?>". Sie können uns Ihre Wunsch-Rezeptur auch direkt anfragen.</div>
    <?php endif; ?>
  </div>

  <?php
  $rtab = ($_GET['rtab'] ?? '') === 'katalog' ? 'katalog' : (($_GET['rtab'] ?? '') === 'eigene' ? 'eigene' : (!$eigeneRez && $katalogRez ? 'katalog' : 'eigene'));
  $rezTabLink = fn($t) => $portalLink('rezepturen') . '&rtab=' . $t . ($q !== '' ? '&q=' . urlencode($q) : '');
  ?>
  <div class="bx-panel">
    <div class="settabs" style="margin:0 0 12px">
      <a href="<?= $rezTabLink('eigene') ?>"  class="<?= $rtab === 'eigene'  ? 'on' : '' ?>">Eigene<?= $eigeneRez  ? ' (' . count($eigeneRez)  . ')' : '' ?></a>
      <a href="<?= $rezTabLink('katalog') ?>" class="<?= $rtab === 'katalog' ? 'on' : '' ?>">Katalog<?= $katalogRez ? ' (' . count($katalogRez) . ')' : '' ?></a>
    </div>
    <?php if ($rtab === 'eigene'): ?>
      <?php if ($eigeneRez) { $rezTabelle($eigeneRez); } else { ?>
        <div class="muted"><?= $q !== '' ? 'Keine eigene Rezeptur passt zu „' . h($q) . '".' : 'Noch keine eigenen Rezepturen. Stellen Sie eine Rezepturanfrage – nach unserer Prüfung erscheint hier Ihr Vorschlag.' ?></div>
      <?php } ?>
    <?php else: ?>
      <?php if ($katalogRez) { $rezTabelle($katalogRez); } else { ?>
        <div class="muted">Aktuell keine freigegebenen Katalog-Rezepturen verfügbar.</div>
      <?php } ?>
    <?php endif; ?>
  </div>

<?php elseif ($view === 'rezeptur'): ?>
  <?php if (isset($_GET['freigegeben']) && $rezDetail): ?>
  <div class="bx-panel badge-ok" style="padding:14px 18px">
    <strong>Rezeptur freigegeben<?= ' – ' . h($rezDetail['nummer'] . ' ' . $rezDetail['name']) ?>.</strong>
    <?= !empty($rezDetail['freigabe_name']) ? '<div class="muted" style="font-size:13px;margin-top:4px">Bestätigt durch ' . h($rezDetail['freigabe_name']) . '.</div>' : '' ?>
    <div style="margin-top:6px">Vielen Dank – Ihre Freigabe ist bei uns eingegangen. Wir melden uns mit den nächsten Schritten.</div>
  </div>
  <?php endif; ?>
  <?php if (!$rezDetail): ?>
    <h1 style="margin-bottom:4px">Rezeptur</h1>
    <div class="bx-panel"><div class="muted">Rezeptur nicht gefunden.</div><div style="margin-top:12px"><a class="btn btn-ghost" href="<?= $portalLink('rezepturen') ?>">Zurück zur Liste</a></div></div>
  <?php else: $dfP = in_array($rezDetail['darreichungsform'], ['pulver','stick','granulat'], true) ? 'Portion' : 'Einheit'; ?>
    <div class="bx-row" style="justify-content:space-between;align-items:center">
      <h1 style="margin:0"><?= h($rezDetail['name']) ?></h1>
      <div class="bx-row" style="gap:8px">
        <?php // Angenommene eigene und freigegebene Katalog-Rezepturen kann der Kunde direkt als Produkt anfragen.
              if ($kannProduktAnfrage && in_array($rezDetail['status'], ['eingefroren','freigegeben'], true)): ?>
          <a class="btn btn-primary btn-sm" href="<?= $portalLink('prodanfrage') ?>&rid=<?= (int)$rezDetail['id'] ?>">Als Produkt anfragen</a>
        <?php endif; ?>
        <a class="btn btn-ghost btn-sm" href="<?= $portalLink('rezepturen') ?>">Zurück zur Liste</a>
      </div>
    </div>
    <p class="bx-sub"><?= h($rezDetail['nummer']) ?> · <?= h($DFORM_P[$rezDetail['darreichungsform']] ?? $rezDetail['darreichungsform']) ?> · <?= $rezBadge($rezDetail['status']) ?></p>
    <div class="bx-panel">
      <h2>Zutaten je <?= $dfP ?></h2>
      <?php if (!$rezZutaten): ?><div class="muted">Noch keine Zutaten hinterlegt.</div>
      <?php else: ?>
      <table class="bx-table"><thead><tr><th>Zutat</th><th class="bx-num">Menge je <?= $dfP ?></th><th>Dokumente</th></tr></thead><tbody>
        <?php $sum = 0; foreach ($rezZutaten as $z): $sum += (float)$z['menge_mg']; $dk = $rezDoks[(int)($z['item_id'] ?? 0)] ?? []; ?>
          <tr><td><?= h($z['bezeichnung']) ?></td><td class="bx-num"><?= rtrim(rtrim(number_format((float)$z['menge_mg'],2,',','.'),'0'),',') ?> mg</td>
            <td><?php if ($dk): foreach ($dk as $d): ?>
                  <a href="?p=portal_dok&token=<?= h($token) ?>&id=<?= (int)$d['id'] ?>" target="_blank" rel="noopener" style="margin-right:10px"><?= h($DOKTYP[$d['typ']] ?? $d['typ']) ?></a>
                <?php endforeach; else: ?><span class="muted">–</span><?php endif; ?></td></tr>
        <?php endforeach; ?>
        <tr style="font-weight:600"><td>Gesamt je <?= $dfP ?></td><td class="bx-num"><?= rtrim(rtrim(number_format($sum,2,',','.'),'0'),',') ?> mg</td><td></td></tr>
      </tbody></table>
      <div class="muted" style="font-size:12px;margin-top:8px">Analysenzertifikate und Spezifikationen zu den eingesetzten Rohstoffen, soweit wir sie freigegeben haben.</div>
      <?php if ($rezDetail['darreichungsform'] === 'kapsel'): $kg = $kapselAnzeige($rezDetail, $sum); $fix = !empty($rezDetail['kapselgroesse_id']); ?>
        <div style="margin-top:10px;font-size:14px"><strong>Kapselgröße:</strong>
          <?= $kg ? h($kg['name']) . ' <span class="muted">(fasst bis ' . (int)$kg['fuellmenge_mg'] . ' mg)</span>' : '<span class="muted">Füllgewicht zu groß für eine Standardkapsel – wir schlagen eine andere Form oder Aufteilung vor.</span>' ?>
          <?php if (!$fix): ?><div class="muted" style="font-size:12px">Richtwert aus dem Wirkstoffgewicht – die endgültige Größe legen wir mit der finalen Rezeptur (inkl. Hilfsstoffe) fest.</div><?php endif; ?>
        </div>
        <?php
        // Aufgabe 3: Passt die Tagesdosis nicht in eine Kapsel, dem Kunden verständlich zeigen, wie auf mehrere Kapseln pro Tag verteilt wird.
        $capMg = $kg ? (int)$kg['fuellmenge_mg'] : (int)((end($portalKapseln) ?: ['fuellmenge_mg'=>0])['fuellmenge_mg']);
        if ($capMg > 0 && $sum > $capMg): $nKap = (int)ceil($sum / $capMg); ?>
        <div class="bx-panel" style="margin-top:12px;background:var(--panel-2)">
          <div style="font-weight:600;margin-bottom:4px">Einnahme: <?= $nKap ?> Kapseln pro Tag</div>
          <div class="muted" style="font-size:13px;margin-bottom:10px">Die gesamte Tagesdosis ist zu viel für eine einzelne Kapsel. Sie wird gleichmäßig auf <?= $nKap ?> Kapseln pro Tag verteilt – jede Kapsel enthält anteilig alle Zutaten.</div>
          <div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Zutat</th><th class="bx-num">Tagesdosis</th><th class="bx-num">je Kapsel</th></tr></thead><tbody>
            <?php foreach ($rezZutaten as $z): $je = (float)$z['menge_mg'] / $nKap; ?>
              <tr><td><?= h($z['bezeichnung']) ?></td>
                <td class="bx-num"><?= rtrim(rtrim(number_format((float)$z['menge_mg'],1,',','.'),'0'),',') ?> mg</td>
                <td class="bx-num"><?= rtrim(rtrim(number_format($je,1,',','.'),'0'),',') ?> mg</td></tr>
            <?php endforeach; ?>
            <tr style="font-weight:600"><td>Füllgewicht je Kapsel</td><td class="bx-num"></td><td class="bx-num"><?= rtrim(rtrim(number_format($sum/$nKap,1,',','.'),'0'),',') ?> mg</td></tr>
          </tbody></table></div>
          <div class="muted" style="font-size:12px;margin-top:6px">Werte gerundet. Die endgültige Aufteilung stimmen wir mit der finalen Rezeptur ab.</div>
        </div>
        <?php endif; ?>
      <?php endif; ?>
      <?php endif; ?>
      <?php if ($rezDetail['status'] === 'vorschlag'): ?>
        <?php if (isset($_GET['ablehngrund'])): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:10px 14px;margin-top:12px">Bitte geben Sie einen Grund an, dann können wir den Vorschlag gezielt überarbeiten.</div><?php endif; ?>
        <div class="muted" style="margin-top:14px;font-size:13px">Das ist unser <strong>Vorschlag</strong> zu Ihrer Anfrage. Sind Sie einverstanden, nehmen Sie ihn hier verbindlich an – danach erstellen wir Angebot &amp; Produktion. Passt etwas nicht, lehnen Sie ihn mit einem kurzen Grund ab – dann überarbeiten wir ihn.</div>
        <div class="bx-row" style="margin-top:10px;gap:8px">
          <span style="margin:0"><button class="btn btn-primary" type="button" onclick="bxBestaetigen('Rezeptur verbindlich annehmen', '<div class=\'bx-panel\' style=\'margin:0 0 12px;padding:12px 14px\'><strong><?= h(($rezDetail['nummer'] ?? '') . ' ' . ($rezDetail['name'] ?? '')) ?></strong></div>Mit der Annahme wird die Rezeptur <strong>eingefroren</strong>: Zusammensetzung und Mengen sind damit festgelegt und k&ouml;nnen nicht mehr ge&auml;ndert werden. &Auml;nderungen brauchen danach eine neue Rezeptur.', 'Ich habe die Rezeptur gepr&uuml;ft und nehme sie verbindlich an. Mir ist bewusst, dass sie danach nicht mehr ge&auml;ndert werden kann.', {aktion:'rezeptur_annehmen', rezeptur_id:'<?= (int)$rezDetail['id'] ?>'})">Rezeptur verbindlich annehmen</button></span>
          <button type="button" class="btn btn-ghost" onclick="document.getElementById('rezRejModal').style.display='flex'">Ablehnen</button>
        </div>
        <div id="rezRejModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);align-items:center;justify-content:center;z-index:1000;padding:16px">
          <div class="bx-panel" style="max-width:460px;width:100%;margin:0">
            <h2 style="margin-top:0">Vorschlag ablehnen</h2>
            <p class="muted" style="margin-top:0;font-size:13px">Was passt nicht bzw. was sollen wir ändern? Ihr Grund hilft uns, den Vorschlag gezielt zu überarbeiten.</p>
            <form method="post">
              <input type="hidden" name="aktion" value="rezeptur_ablehnen">
              <input type="hidden" name="rezeptur_id" value="<?= (int)$rezDetail['id'] ?>">
              <div class="bx-field"><label>Grund (Pflicht)</label><textarea name="grund" required placeholder="z. B. bitte ohne Magnesiumstearat, Dosierung Vitamin C höher …"></textarea></div>
              <div class="bx-row" style="margin-top:12px;gap:8px">
                <button class="btn btn-primary" type="submit">Ablehnung senden</button>
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('rezRejModal').style.display='none'">Abbrechen</button>
              </div>
            </form>
          </div>
        </div>
      <?php elseif ($rezDetail['status'] === 'abgelehnt'): ?>
        <div class="bx-panel" style="margin-top:12px;padding:12px 14px"><strong>Sie haben diesen Vorschlag abgelehnt.</strong><?php if (!empty($rezDetail['ablehnung_grund'])): ?><div style="margin-top:4px">Grund: <?= h($rezDetail['ablehnung_grund']) ?></div><?php endif; ?><div class="muted" style="margin-top:6px;font-size:13px">Wir überarbeiten ihn und melden uns mit einem neuen Vorschlag.</div></div>
      <?php elseif ($rezDetail['status'] === 'eingefroren'): ?>
        <div class="muted" style="margin-top:12px">Diese Rezeptur haben Sie angenommen – sie ist verbindlich festgelegt.</div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

<?php elseif ($view === 'produkte'):
    $eigeneProd  = array_values(array_filter($katalog, fn($p) => (int)($p['kunde_id'] ?? 0) === $kid));
    $katalogProd = array_values(array_filter($katalog, fn($p) => (int)($p['kunde_id'] ?? 0) !== $kid));
    $prodTabelle = function(array $liste) use ($DFORM_P, $abPreis, $eur, $portalLink) { ?>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Produkt</th><th>Form</th><th class="bx-num">Preis</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($liste as $pk): $ab = $abPreis($pk['id']); ?>
          <tr><td><?= h($pk['name']) ?></td>
            <td><?= $pk['darreichungsform'] ? h($DFORM_P[$pk['darreichungsform']] ?? $pk['darreichungsform']) : '–' ?></td>
            <td class="bx-num"><?= $ab !== null ? 'ab '.$eur($ab).' *' : '<span class="muted">auf Anfrage</span>' ?></td>
            <td style="text-align:right"><a class="btn btn-ghost btn-sm" href="<?= $portalLink('produkt') ?>&pid=<?= (int)$pk['id'] ?>">ansehen</a></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="muted" style="font-size:12px;margin-top:8px">* zzgl. Verpackung und Etikett</div>
    <?php };
    $ptab = ($_GET['ptab'] ?? '') === 'katalog' ? 'katalog' : (($_GET['ptab'] ?? '') === 'eigene' ? 'eigene' : (!$eigeneProd && $katalogProd ? 'katalog' : 'eigene'));
    $prodTabLink = fn($t) => $portalLink('produkte') . '&ptab=' . $t . ($q !== '' ? '&q=' . urlencode($q) : '');
    ?>
  <h1 style="margin-bottom:4px">Produkte</h1>
  <p class="bx-sub">Ihre eigenen Produkte und unser Produktkatalog. Wählen Sie ein Produkt für Details und eine Anfrage.</p>
  <div class="bx-panel">
    <?php $sucheForm('produkte', 'Produkt oder Rohstoff suchen, z. B. Magnesium'); ?>
    <?php if (!$katalog): ?><div class="muted"><?= $q !== '' ? 'Kein Produkt gefunden zu „' . h($q) . '". Sie können es auch direkt anfragen.' : 'Aktuell sind keine Produkte verfügbar.' ?></div><?php endif; ?>
  </div>
  <?php if ($katalog): ?>
  <div class="bx-panel">
    <div class="settabs" style="margin:0 0 12px">
      <a href="<?= $prodTabLink('eigene') ?>"  class="<?= $ptab === 'eigene'  ? 'on' : '' ?>">Eigene<?= $eigeneProd  ? ' (' . count($eigeneProd)  . ')' : '' ?></a>
      <a href="<?= $prodTabLink('katalog') ?>" class="<?= $ptab === 'katalog' ? 'on' : '' ?>">Katalog<?= $katalogProd ? ' (' . count($katalogProd) . ')' : '' ?></a>
    </div>
    <?php if ($ptab === 'eigene'): ?>
      <?php if ($eigeneProd) { $prodTabelle($eigeneProd); } else { ?><div class="muted">Noch keine eigenen Produkte – diese entstehen aus Ihren angenommenen Angeboten.</div><?php } ?>
    <?php else: ?>
      <?php if ($katalogProd) { $prodTabelle($katalogProd); } else { ?><div class="muted">Aktuell keine Katalog-Produkte verfügbar.</div><?php } ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

<?php elseif ($view === 'produkt'): ?>
  <?php if (!$prodDetail): ?>
    <h1 style="margin-bottom:4px">Produkt</h1>
    <div class="bx-panel"><div class="muted">Produkt nicht gefunden.</div><div style="margin-top:12px"><a class="btn btn-ghost" href="<?= $portalLink('produkte') ?>">Zurück zum Katalog</a></div></div>
  <?php else: ?>
    <div class="bx-row" style="justify-content:space-between;align-items:center">
      <h1 style="margin:0"><?= h($prodDetail['anzeige_name'] ?? $prodDetail['name']) ?></h1>
      <a class="btn btn-ghost btn-sm" href="<?= $portalLink('produkte') ?>">Zurück zum Katalog</a>
    </div>
    <p class="bx-sub"><?= h($prodDetail['nummer']) ?><?= $prodDetail['darreichungsform'] ? ' · '.h($DFORM_P[$prodDetail['darreichungsform']] ?? $prodDetail['darreichungsform']) : '' ?></p>
    <?php $dfE = in_array($prodDetail['darreichungsform'] ?? '', ['pulver','stick','granulat'], true) ? 'Portion' : 'Einheit'; ?>
    <?php if ($prodZutaten): ?>
    <div class="bx-panel"><h2>Zusammensetzung</h2>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Zutat (aus unseren Rohstoffen)</th><th>Wirkstoffe</th><th class="bx-num">Menge je <?= $dfE ?></th></tr></thead>
        <tbody>
        <?php foreach ($prodZutaten as $z):
            $wl = $z['item_id'] && isset($prodWirk[$z['item_id']]) ? implode(' · ', $prodWirk[$z['item_id']]) : ''; ?>
          <tr>
            <td><?php if ($z['item_id'] && $k['portal_rohstoffe']): ?><a href="<?= $portalLink('rohstoff') ?>&iid=<?= (int)$z['item_id'] ?>"><?= h($z['bezeichnung']) ?></a><?php else: ?><?= h($z['bezeichnung']) ?><?php endif; ?></td>
            <td class="muted"><?= $wl !== '' ? h($wl) : '–' ?></td>
            <td class="bx-num"><?= rtrim(rtrim(number_format((float)$z['menge_mg'],2,',','.'),'0'),',') ?> mg</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php endif; ?>

    <?php if ($prodNaehr): ?>
    <div class="bx-panel"><h2>Nährwerte je <?= $dfE ?></h2>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Nährstoff</th><th class="bx-num">Menge</th><th class="bx-num">% NRV*</th></tr></thead>
        <tbody>
        <?php foreach ($prodNaehr as $n):
            $mg = $n['mg']; $betrag = $n['einheit'] === 'µg' ? rtrim(rtrim(number_format($mg*1000,1,',','.'),'0'),',').' µg' : rtrim(rtrim(number_format($mg,1,',','.'),'0'),',').' mg';
            $pct = '–'; if ($n['nrv'] !== null && $n['nrv'] !== '') { $nrvMg = $n['einheit']==='µg' ? (float)$n['nrv']/1000 : (float)$n['nrv']; if ($nrvMg > 0) $pct = number_format($mg/$nrvMg*100, 0, ',', '.') . ' %'; } ?>
          <tr><td><?= h($n['name']) ?></td><td class="bx-num"><?= $betrag ?></td><td class="bx-num"><?= $pct ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="muted" style="margin-top:6px;font-size:12px">* NRV = Nährstoffbezugswert je <?= $dfE ?>. Die Verzehrempfehlung (Einheiten/Tag) legen Sie fest.</div>
    </div>
    <?php endif; ?>

    <?php if ($prodAllergene || $prodVegan !== null || $prodGvo !== null): ?>
    <div class="bx-panel"><h2>Deklaration</h2>
      <div class="bx-tablewrap"><table class="bx-table"><tbody>
        <tr><td style="width:220px">Allergene</td><td><?= $prodAllergene ? h(implode(', ', $prodAllergene)) : 'keine deklarationspflichtigen Allergene' ?></td></tr>
        <?php if ($prodVegan !== null): ?><tr><td>Vegan / vegetarisch</td><td><?= $prodVegan ? 'ja' : 'nein' ?></td></tr><?php endif; ?>
        <?php if ($prodGvo !== null): ?><tr><td>GVO-frei</td><td><?= $prodGvo ? 'ja' : 'nein' ?></td></tr><?php endif; ?>
      </tbody></table></div>
      <div class="muted" style="margin-top:6px;font-size:12px">Automatisch aus den eingesetzten Rohstoffen abgeleitet.</div>
    </div>
    <?php endif; ?>
    <?php if ($prodPreise): ?>
    <div class="bx-panel"><h2>Größen &amp; Preise</h2>
      <p class="muted" style="margin-top:0">Richtpreise je Packung – der genaue Preis hängt von Verpackung und Bestellmenge ab (mehr = günstiger).</p>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Größe</th><th class="bx-num">Preis je Packung</th></tr></thead>
        <tbody>
        <?php foreach ($prodPreise as $pp): ?>
          <tr><td><?= $prodForm === 'stick' && $prodPortionG > 0
                      ? rtrim(rtrim(number_format($pp['stueck'] * $prodPortionG, 1, ',', '.'), '0'), ',') . ' g je Packung'
                      : h(form_groessen_label($prodForm, (float)$pp['stueck'])) . ' je Packung' ?></td>
              <td class="bx-num">ab <?= $eur($pp['ab']) ?> *</td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="muted" style="font-size:12px;margin-top:8px">* zzgl. Verpackung und Etikett</div>
    </div>
    <?php else: ?>
    <div class="bx-panel"><h2>Preis</h2>
      <p class="muted" style="margin-top:0">Für dieses Produkt liegt Ihnen noch kein Preis vor. Stellen Sie unten eine Anfrage – wir kalkulieren Ihre Mengen und melden uns mit einem Angebot.</p>
    </div>
    <?php endif; ?>
    <div class="bx-panel"><h2>Anfrage stellen</h2>
      <form method="post">
        <input type="hidden" name="aktion" value="produkt_anfrage">
        <input type="hidden" name="produkt_id" value="<?= (int)$prodDetail['id'] ?>">
        <div class="bx-grid">
          <?php // Menge frei eintippen – bei jeder Form. Das System rechnet auch Größen außerhalb des Standardrasters. ?>
          <div class="bx-field">
            <label><?= $prodIstFuell ? 'Füllmenge je Packung (' . h($prodFuellEinheit) . ')' : 'Stück je Packung' ?>
              <?= bx_hint('Tragen Sie Ihre Wunschmenge ein – wir kalkulieren genau diese Größe und wählen die passende Verpackung dazu.') ?></label>
            <input type="number" name="<?= $prodIstFuell ? 'fuellmenge_g' : 'stueck' ?>" min="1" step="1"
                   placeholder="<?= $prodIstFuell ? ($prodFuellEinheit === 'ml' ? 'z. B. 250' : 'z. B. 200') : 'z. B. 120' ?>">
          </div>
          <div class="bx-field"><label>Verpackungstyp <?= bx_hint('Sie wählen nur die Art – wir bestimmen das perfekt passende Gebinde in der richtigen Größe. Es werden nur zur Darreichungsform passende Typen angeboten.') ?></label>
            <?php $prodVtyp = verpackung_typen_fuer_form($prodForm); ?>
            <select name="verpackung_typ"><option value="">– egal / bitte empfehlen –</option><?php foreach ($VTYPEN as $tk=>$tl): if (!in_array($tk, $prodVtyp, true)) continue; ?><option value="<?= $tk ?>"><?= h($tl) ?></option><?php endforeach; ?></select>
          </div>
          <div class="bx-field"><label>Anzahl (Packungen) <?= bx_hint('Mehrere Mengen mit Komma für Staffelpreise, z. B. 1000, 2500, 5000') ?></label><input type="text" name="menge" placeholder="z. B. 1000, 2500, 5000"></div>
        </div>
        <div class="bx-field"><label>Notiz (optional)</label><textarea name="notiz" placeholder="Wünsche, Zieltermin …"></textarea></div>
        <button class="btn btn-primary" type="submit">Anfrage senden</button>
      </form>
    </div>
  <?php endif; ?>

<?php elseif ($view === 'menge_aendern'):
  $paf = (int)($_GET['paf'] ?? 0);
  $pa  = $paf ? one("SELECT * FROM portal_anfrage WHERE id=? AND kunde_id=? AND typ='produkt'", [$paf, $kid]) : null;
  $hatAuf = $pa ? (int) scalar("SELECT COUNT(*) FROM angebot a JOIN auftrag au ON au.angebot_id=a.id WHERE a.anfrage_id=? AND a.kunde_id=?", [$paf, $kid]) : 1;
  if (!$pa || $hatAuf): ?>
    <h1 style="margin-bottom:4px">Menge ändern</h1>
    <div class="bx-panel"><div class="muted">Diese Anfrage kann nicht (mehr) geändert werden – sie ist bereits bestellt oder nicht vorhanden.</div>
      <div style="margin-top:10px"><a class="btn btn-ghost" href="<?= $portalLink('meine_anfragen') ?>">Zurück zu Meine Anfragen</a></div></div>
  <?php else:
    $paForm  = (string) scalar("SELECT COALESCE(r.darreichungsform, r2.darreichungsform) FROM portal_anfrage pa
                   LEFT JOIN produkt pr ON pr.id=pa.produkt_id LEFT JOIN rezeptur r ON r.id=pr.rezeptur_id
                   LEFT JOIN rezeptur r2 ON r2.id=pa.rezeptur_id WHERE pa.id=?", [$paf]);
    $paFuell = form_ist_fuellmenge($paForm);
    $anzLbl  = $paFuell ? (form_groessen_einheit($paForm) === 'ml' ? 'Füllmenge pro Verpackung (ml)' : 'Füllmenge pro Verpackung (g)')
                        : ($paForm === 'stick' ? 'Sticks je Verpackung' : 'Anzahl pro Verpackung');
    $paName  = $pa['produkt_id'] ? (string) scalar("SELECT COALESCE(NULLIF(kundenname,''),name) FROM produkt WHERE id=?", [(int)$pa['produkt_id']])
             : ($pa['rezeptur_id'] ? (string) scalar("SELECT name FROM rezeptur WHERE id=?", [(int)$pa['rezeptur_id']]) : 'Produkt');
    $paPos   = all("SELECT stueck, fuellmenge_g, menge FROM portal_anfrage_pos WHERE anfrage_id=? ORDER BY sort, id", [$paf]);
    if (!$paPos) $paPos = [['stueck'=>$pa['stueck'], 'fuellmenge_g'=>$pa['fuellmenge_g'], 'menge'=>$pa['menge']]]; ?>
  <h1 style="margin-bottom:4px">Menge ändern</h1>
  <div class="bx-panel">
    <p class="muted" style="margin-top:0"><strong><?= h($paName) ?></strong> · <?= h($pa['nummer']) ?><br>
       Passen Sie die gewünschten Mengen an – wir überarbeiten Ihr Angebot und melden uns. Das bisherige Angebot wird dabei zurückgezogen.</p>
    <form method="post">
      <input type="hidden" name="aktion" value="produkt_anfrage_bearbeiten">
      <input type="hidden" name="paf_id" value="<?= $paf ?>">
      <div class="bx-tablewrap"><table class="bx-table" id="ma_staffel">
        <thead><tr><th><?= h($anzLbl) ?></th><th style="width:190px">Menge (Verpackungen)</th><th style="width:40px"></th></tr></thead>
        <tbody>
        <?php foreach ($paPos as $z): $anz = $paFuell ? (float)$z['fuellmenge_g'] : (int)$z['stueck']; ?>
          <tr class="ma_srow">
            <td><input type="number" name="st_anzahl[]" min="1" step="1" value="<?= $anz > 0 ? (int) round($anz) : '' ?>"></td>
            <td><input type="number" name="st_vpe[]" min="1" step="1" value="<?= (int)$z['menge'] > 0 ? (int)$z['menge'] : '' ?>"></td>
            <td><button type="button" class="btn btn-ghost btn-sm ma_del" title="Zeile entfernen">×</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <button type="button" class="btn btn-ghost btn-sm" id="ma_addrow">+ Staffel</button>
      <div class="bx-field" style="margin-top:14px"><label>Notiz (optional)</label><textarea name="notiz" placeholder="Wünsche, Zieltermin …"><?= h((string)$pa['notiz']) ?></textarea></div>
      <div class="bx-row" style="gap:10px;margin-top:8px">
        <button class="btn btn-primary" type="submit">Änderung senden</button>
        <a class="btn btn-ghost" href="<?= $portalLink('meine_anfragen') ?>">Abbrechen</a>
      </div>
    </form>
    <script>(function(){
      var tb=document.querySelector('#ma_staffel tbody'); if(!tb) return;
      var add=document.getElementById('ma_addrow');
      if(add) add.addEventListener('click',function(){
        var tr=document.createElement('tr'); tr.className='ma_srow';
        tr.innerHTML='<td><input type="number" name="st_anzahl[]" min="1" step="1"></td>'
          +'<td><input type="number" name="st_vpe[]" min="1" step="1"></td>'
          +'<td><button type="button" class="btn btn-ghost btn-sm ma_del" title="Zeile entfernen">×</button></td>';
        tb.appendChild(tr);
      });
      tb.addEventListener('click',function(e){ var b=e.target.closest('.ma_del'); if(!b) return; if(tb.querySelectorAll('.ma_srow').length>1) b.closest('tr').remove(); });
    })();</script>
  </div>
  <?php endif; ?>

<?php elseif ($view === 'prodanfrage'): ?>
  <h1 style="margin-bottom:4px">Produkt anfragen</h1>
  <?php if (isset($_GET['freigegeben'])):
        $frRez = $rid ? one("SELECT nummer, name, freigabe_name FROM rezeptur WHERE id=? AND kunde_id=?", [$rid, (int)$k['id']]) : null; ?>
  <div class="bx-panel badge-ok" style="padding:14px 18px">
    <strong>Rezeptur freigegeben<?= $frRez ? ' – ' . h($frRez['nummer'] . ' ' . $frRez['name']) : '' ?>.</strong>
    <?= $frRez && $frRez['freigabe_name'] ? '<div class="muted" style="font-size:13px;margin-top:4px">Bestätigt durch ' . h($frRez['freigabe_name']) . '.</div>' : '' ?>
    <div style="margin-top:6px">Nächster Schritt: Menge je Packung, Verpackung und Bestellmenge angeben – wir rechnen Ihnen den Preis dazu. Die Rezeptur ist unten schon ausgewählt.</div>
  </div>
  <?php endif; ?>
  <div class="bx-panel">
    <p class="muted" style="margin-top:0">Wählen Sie eine <strong>Ihrer Rezepturen</strong> oder ein Produkt aus dem Katalog, dazu Menge je Packung, Verpackung und Bestellmenge – wir melden uns mit einem Preis.</p>
    <?php if (!$katalog && !$meineRezepturen): ?><div class="muted">Es steht noch nichts zur Auswahl. Stellen Sie zuerst eine Rezepturanfrage – sobald Sie unseren Vorschlag angenommen haben, können Sie ihn hier als Produkt anfragen.</div>
    <?php else: ?>
    <form method="post">
      <input type="hidden" name="aktion" value="produkt_anfrage">
      <div class="bx-grid">
        <div class="bx-field"><label>Produkt</label>
          <?php // Eigene Rezepturen (selbst angelegt) getrennt von Katalog-Rezepturen (Haus) – bessere Übersicht.
                $paEigene  = array_values(array_filter($meineRezepturen, fn($r) => (int)($r['kunde_id'] ?? 0) === $kid));
                $paKatalog = array_values(array_filter($meineRezepturen, fn($r) => (int)($r['kunde_id'] ?? 0) !== $kid)); ?>
          <select name="produkt_id" id="pa_produkt" required><option value="">– wählen –</option>
            <?php if ($paEigene): ?>
            <optgroup label="Meine Rezepturen (noch kein fertiges Produkt)">
              <?php foreach ($paEigene as $rz): ?><option value="r<?= (int)$rz['id'] ?>" data-form="<?= h($rz['darreichungsform']) ?>" <?= $rid === (int)$rz['id'] ? 'selected' : '' ?>><?= h($rz['name']) ?> · <?= h($DFORM_P[$rz['darreichungsform']] ?? $rz['darreichungsform']) ?></option><?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
            <?php if ($paKatalog): ?>
            <optgroup label="Katalog-Rezepturen">
              <?php foreach ($paKatalog as $rz): ?><option value="r<?= (int)$rz['id'] ?>" data-form="<?= h($rz['darreichungsform']) ?>" <?= $rid === (int)$rz['id'] ? 'selected' : '' ?>><?= h($rz['name']) ?> · <?= h($DFORM_P[$rz['darreichungsform']] ?? $rz['darreichungsform']) ?></option><?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
            <?php if ($katalog): ?>
            <optgroup label="Produkte aus dem Katalog">
              <?php foreach ($katalog as $pk): ?><option value="p<?= (int)$pk['id'] ?>" data-form="<?= h($pk['darreichungsform']) ?>"><?= h($pk['name']) ?><?= $pk['darreichungsform'] ? ' · '.h($DFORM_P[$pk['darreichungsform']] ?? $pk['darreichungsform']) : '' ?></option><?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
          </select>
        </div>
        <div class="bx-field"><label>Verpackungstyp <?= bx_hint('Sie wählen nur die Art – wir bestimmen das perfekt passende Gebinde in der richtigen Größe. Es werden nur zur Darreichungsform passende Typen angeboten.') ?></label>
          <select name="verpackung_typ" id="pa_verp"><option value="">– egal / bitte empfehlen –</option><?php foreach ($VTYPEN as $tk=>$tl): ?><option value="<?= $tk ?>"><?= h($tl) ?></option><?php endforeach; ?></select>
        </div>
      </div>
      <!-- Task 3: Staffel – je Zeile Anzahl pro Verpackung + Menge VPE (Anzahl Verpackungen). -->
      <div style="margin-top:6px">
        <label style="display:block;margin-bottom:6px">Staffel <?= bx_hint('Fragen Sie mehrere Varianten auf einmal an: je Zeile die Anzahl pro Verpackung (z. B. 120 Kapseln) und die gewünschte Menge an Verpackungen (VPE). Wir rechnen Ihnen jede Zeile als Staffelpreis.') ?></label>
        <div class="bx-tablewrap"><table class="bx-table" id="pa_staffel">
          <thead><tr>
            <th><span class="pa_anzahl_lbl">Anzahl pro Verpackung</span></th>
            <th style="width:190px">Menge (Verpackungen / VPE)</th>
            <th style="width:40px"></th>
          </tr></thead>
          <tbody>
            <tr class="pa_srow">
              <td><input type="number" name="st_anzahl[]" min="1" step="1" placeholder="z. B. 120"></td>
              <td><input type="number" name="st_vpe[]" min="1" step="1" placeholder="z. B. 1000"></td>
              <td><button type="button" class="btn btn-ghost btn-sm pa_del" title="Zeile entfernen">×</button></td>
            </tr>
          </tbody>
        </table></div>
        <button type="button" class="btn btn-ghost btn-sm" id="pa_addrow">+ Staffel</button>
        <div id="pa_stickhint" class="muted" style="display:none;font-size:12px;margin-top:8px">Bei Sticks geben Sie die <strong>Sticks je Verpackung</strong> an (z. B. Karton mit je 30 Sticks) und darunter die gewünschte Anzahl Verpackungen.</div>
        <div id="pa_warn" style="display:none;margin-top:10px;border:1px solid #e6c4c0;background:#fbeeec;color:#8f231b;border-radius:8px;padding:10px 12px;font-size:13px"></div>
      </div>
      <div class="bx-field" style="margin-top:14px"><label>Notiz (optional)</label><textarea name="notiz" placeholder="Wünsche, Zieltermin …"></textarea></div>
      <button class="btn btn-primary" type="submit">Anfrage senden</button>
    </form>
    <script>(function(){
      var sel=document.getElementById('pa_produkt'); if(!sel) return;
      // Verpackungstyp je Darreichungsform (aus core/schema.php verpackung_typen_fuer_form()).
      var VTYP_LABELS=<?= json_encode($VTYPEN, JSON_UNESCAPED_UNICODE) ?>;
      var VTYP_FORM=<?= json_encode((function(){ $m=[]; foreach(['kapsel','tablette','softgel','gummi','pulver','granulat','stick','fluessig','gel'] as $f) $m[$f]=verpackung_typen_fuer_form($f); return $m; })(), JSON_UNESCAPED_UNICODE) ?>;
      var VTYP_ALL=<?= json_encode(verpackung_typen_fuer_form(null)) ?>;
      // Max. Kapseln je Verpackung (aus pack_kapazitaet) je Auswahl – fuer die Machbarkeitspruefung bei Kapseln.
      var PA_KAPMAX=<?= json_encode((function() use ($meineRezepturen, $katalog) {
          $m = [];
          foreach ($meineRezepturen as $rz) if (in_array($rz['darreichungsform'] ?? '', ['kapsel','softgel'], true)) { $x = kapsel_max_stueck_je_verpackung((int)$rz['id']); if ($x > 0) $m['r'.$rz['id']] = $x; }
          foreach ($katalog as $pk) if (in_array($pk['darreichungsform'] ?? '', ['kapsel','softgel'], true) && !empty($pk['rezeptur_id'])) { $x = kapsel_max_stueck_je_verpackung((int)$pk['rezeptur_id']); if ($x > 0) $m['p'.$pk['id']] = $x; }
          return $m;
      })(), JSON_UNESCAPED_UNICODE) ?>;
      function curForm(){ var o=sel.options[sel.selectedIndex]; return o?(o.getAttribute('data-form')||''):''; }
      function lbl(){
        var f=curForm();
        // Pulver/Granulat nach Gramm, Flüssig/Gel nach Milliliter, Stick = Sticks je Verpackung, sonst Stückzahl.
        var t = (f==='pulver'||f==='granulat') ? 'Füllmenge pro Verpackung (g)'
              : ((f==='fluessig'||f==='gel') ? 'Füllmenge pro Verpackung (ml)'
              : (f==='stick' ? 'Sticks je Verpackung' : 'Anzahl pro Verpackung'));
        Array.prototype.forEach.call(document.querySelectorAll('.pa_anzahl_lbl'), function(e){ e.textContent=t; });
        var sh=document.getElementById('pa_stickhint'); if(sh) sh.style.display=(f==='stick')?'':'none';
      }
      function paCheck(){
        var warn=document.getElementById('pa_warn'); if(!warn) return;
        var max=PA_KAPMAX[sel.value]||0;
        if(!max){ warn.style.display='none'; warn.textContent=''; return; }
        var over=false;
        Array.prototype.forEach.call(document.querySelectorAll('#pa_staffel .pa_srow input[name="st_anzahl[]"]'), function(i){ if((parseInt(i.value)||0)>max) over=true; });
        if(over){ warn.style.display=''; warn.textContent='In eine Standardverpackung passen bei dieser Kapselgröße max. ca. '+max+' Kapseln. Mehr ist nicht ohne Weiteres machbar – bitte eine kleinere Anzahl je Verpackung wählen, dann melden wir uns mit dem passenden Gebinde.'; }
        else { warn.style.display='none'; warn.textContent=''; }
      }
      function updVerp(){
        var vp=document.getElementById('pa_verp'); if(!vp) return;
        var f=curForm(); var allow=(f && VTYP_FORM[f]) ? VTYP_FORM[f] : VTYP_ALL;
        var keep=vp.value;
        var html='<option value="">– egal / bitte empfehlen –</option>';
        allow.forEach(function(k){ if(VTYP_LABELS[k]) html+='<option value="'+k+'">'+VTYP_LABELS[k]+'</option>'; });
        vp.innerHTML=html;
        if(keep && allow.indexOf(keep)>=0) vp.value=keep;   // vorherige Wahl behalten, wenn noch gültig
      }
      sel.addEventListener('change',function(){ lbl(); updVerp(); paCheck(); }); lbl(); updVerp(); paCheck();
      var tb=document.querySelector('#pa_staffel tbody');
      tb.addEventListener('input', paCheck);
      document.getElementById('pa_addrow').addEventListener('click',function(){
        var tr=document.createElement('tr'); tr.className='pa_srow';
        tr.innerHTML='<td><input type="number" name="st_anzahl[]" min="1" step="1" placeholder="z. B. 120"></td>'
          +'<td><input type="number" name="st_vpe[]" min="1" step="1" placeholder="z. B. 1000"></td>'
          +'<td><button type="button" class="btn btn-ghost btn-sm pa_del" title="Zeile entfernen">×</button></td>';
        tb.appendChild(tr);
      });
      tb.addEventListener('click',function(e){
        var b=e.target.closest('.pa_del'); if(!b) return;
        if(tb.querySelectorAll('.pa_srow').length>1) b.closest('.pa_srow').remove();
        else { b.closest('.pa_srow').querySelectorAll('input').forEach(function(i){i.value='';}); }
        paCheck();
      });
    })();</script>
    <?php endif; ?>
  </div>
  <?php $meineProd = array_filter($portalAnfragen, fn($a) => $a['typ']==='produkt'); if ($meineProd): ?>
  <div class="bx-panel"><h2>Meine Produktanfragen</h2>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Nr.</th><th>Produkt</th><th class="bx-num">Größe je Packung</th><th>Verpackungstyp</th><th class="bx-num">Anzahl</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($meineProd as $a): ?>
        <tr><td><?= h($a['nummer']) ?></td><td><?= h($titelFuer($a)) ?></td>
          <td class="bx-num"><?= $a['fuellmenge_g'] ? rtrim(rtrim(number_format((float)$a['fuellmenge_g'],1,',','.'),'0'),',').' g' : ($a['stueck'] ? (int)$a['stueck'].' Stk' : '–') ?></td>
          <td><?= h($a['verpackung_typ'] ? ($VTYPEN[$a['verpackung_typ']] ?? $a['verpackung_typ']) : '–') ?></td><td class="bx-num"><?= $a['menge'] ? (int)$a['menge'] : '–' ?></td><td><?= $pafBadge($a['status']) ?><?php if ($a['status']==='abgelehnt' && !empty($a['absage_grund'])): ?><div class="muted" style="font-size:12px;white-space:normal"><?= h($a['absage_grund']) ?></div><?php endif; ?></td>
          <td style="text-align:right"><?php if (!empty($a['angebot_id'])): ?>
            <?php // Link dorthin, wo der Kunde die Menge wählt und bestätigt (Angebotskarte in „Meine Anfragen"),
                  //  nicht in die reine Angebote-Liste. Bereits bestätigt -> Reiter „Bestätigt".
                  $oa = ($a['angebot_status'] ?? '') === 'bestaetigt' ? 'bestaetigt' : (($a['angebot_status'] ?? '') === 'abgelehnt' ? 'abgelehnt' : 'zubestaetigen'); ?>
            <a class="btn btn-primary btn-sm" href="<?= $portalLink('meine_anfragen') ?>&oatab=<?= $oa ?>#a<?= (int)$a['angebot_id'] ?>">Angebot ansehen &amp; wählen</a>
          <?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>

<?php elseif ($view === 'rohstoffe'): ?>
  <h1 style="margin-bottom:4px">Rohstoffe</h1>
  <p class="bx-sub">Unser Rohstoff-Katalog. Preise auf Anfrage.</p>
  <div class="bx-panel">
    <?php $sucheForm('rohstoffe', 'Rohstoff suchen – Name, lateinisch oder CAS'); ?>
    <?php if (!$rohkatalog): ?><div class="muted"><?= $q !== '' ? 'Kein Rohstoff gefunden zu „' . h($q) . '".' : 'Aktuell keine Rohstoffe verfügbar.' ?></div>
    <?php else: ?>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Name</th><th>Form</th><th>CAS</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rohkatalog as $ro): ?>
        <tr><td><?= h($ro['name']) ?></td><td><?= h($FORMLBL_P[$ro['form']] ?? $ro['form']) ?></td><td><?= h($ro['cas'] ?: '–') ?></td>
          <td style="text-align:right"><div class="bx-row" style="gap:6px;justify-content:flex-end">
            <a class="btn btn-primary btn-sm" href="<?= $portalLink('rohanfrage') ?>&iid=<?= (int)$ro['id'] ?>">Anfragen</a>
            <a class="btn btn-ghost btn-sm" href="<?= $portalLink('rohstoff') ?>&iid=<?= (int)$ro['id'] ?>">ansehen</a>
          </div></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

<?php elseif ($view === 'rohstoff'): ?>
  <?php if (!$rohDetail): ?>
    <h1 style="margin-bottom:4px">Rohstoff</h1>
    <div class="bx-panel"><div class="muted">Rohstoff nicht gefunden.</div><div style="margin-top:12px"><a class="btn btn-ghost" href="<?= $portalLink('rohstoffe') ?>">Zurück zum Katalog</a></div></div>
  <?php else: ?>
    <div class="bx-row" style="justify-content:space-between;align-items:center">
      <h1 style="margin:0"><?= h($rohDetail['name']) ?></h1>
      <div class="bx-row" style="gap:8px">
        <a class="btn btn-primary btn-sm" href="<?= $portalLink('rohanfrage') ?>&iid=<?= (int)$rohDetail['id'] ?>">Rohstoff anfragen</a>
        <a class="btn btn-ghost btn-sm" href="<?= $portalLink('rohstoffe') ?>">Zurück zum Katalog</a>
      </div>
    </div>
    <p class="bx-sub"><?= h($FORMLBL_P[$rohDetail['form']] ?? $rohDetail['form']) ?><?= $rohDetail['name_lat'] ? ' · '.h($rohDetail['name_lat']) : '' ?><?= $rohDetail['cas'] ? ' · CAS '.h($rohDetail['cas']) : '' ?></p>

    <?php if ((int)($rohDetail['spec_freigegeben'] ?? 0) === 1): ?>
    <div class="bx-panel">
      <h2>Spezifikation</h2>
      <p class="muted" style="margin-top:0">Unsere Spezifikation zu diesem Rohstoff – Kennzahlen, Gehalt, Erklärungen und Lagerung.</p>
      <a class="btn btn-ghost btn-sm" target="_blank" href="<?= $portalLink('spec_pdf') ?>&rid=<?= (int)$rohDetail['id'] ?>">&#8681; Spezifikation (PDF)</a>
    </div>
    <?php endif; ?>
    <?php if ($rohCoas): ?>
    <div class="bx-panel">
      <h2>Analysenzertifikate</h2>
      <p class="muted" style="margin-top:0">CoA je Charge im bulkify-Layout – soweit freigegeben.</p>
      <?php foreach ($rohCoas as $co): ?>
        <a class="btn btn-ghost btn-sm" target="_blank" style="margin:0 8px 8px 0" href="<?= $portalLink('coa_pdf') ?>&cid=<?= (int)$co['id'] ?>">&#8681; CoA <?= h($co['charge_nr'] ?: ('#' . (int)$co['id'])) ?><?= $co['mhd'] ? ' <span class="muted">(MHD ' . h(date('m/Y', strtotime((string)$co['mhd']))) . ')</span>' : '' ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php $rohDoks = dokumente_fuer_kunde('item', (int)$rohDetail['id']); if ($rohDoks): ?>
    <div class="bx-panel"><h2>Dokumente</h2>
      <p class="muted" style="margin-top:0">Analysenzertifikat und Spezifikation zu diesem Rohstoff.</p>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Typ</th><th>Dokument</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rohDoks as $d): ?>
          <tr><td><?= h($DOKTYP[$d['typ']] ?? $d['typ']) ?></td>
              <td><?= h($d['titel'] ?: ($d['datei_orig'] ?: 'Dokument')) ?></td>
              <td style="text-align:right"><a class="btn btn-ghost btn-sm" href="?p=portal_dok&token=<?= h($token) ?>&id=<?= (int)$d['id'] ?>" target="_blank" rel="noopener">öffnen</a></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php endif; ?>

    <?php if ($rohKennwerte): ?>
    <div class="bx-panel"><h2>Kennwerte</h2>
      <div class="bx-tablewrap"><table class="bx-table"><tbody>
        <?php foreach ($rohKennwerte as $kw): ?><tr><td><?= h($kw['parameter']) ?></td><td class="bx-num"><?= h($kw['wert']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    </div>
    <?php endif; ?>

    <?php
      // Eigenschaften nur zeigen, wenn befüllt
      $eig = [];
      if ($rohDetail['bot_quelle'])    $eig['Botanische Quelle'] = $rohDetail['bot_quelle'];
      if ($rohDetail['herkunftsland']) $eig['Herkunftsland'] = $rohDetail['herkunftsland'];
      if ($rohDetail['allergene'])     $eig['Allergene'] = $rohDetail['allergene'];
      if ($jaNein($rohDetail['vegan']) !== null)        $eig['Vegan / vegetarisch'] = $jaNein($rohDetail['vegan']) ? 'ja' : 'nein';
      if ($jaNein($rohDetail['gvo_frei']) !== null)     $eig['GVO-frei'] = $jaNein($rohDetail['gvo_frei']) ? 'ja' : 'nein';
      if ($jaNein($rohDetail['bestrahlt']) !== null)    $eig['Bestrahlt / ETO'] = $jaNein($rohDetail['bestrahlt']) ? 'ja' : 'nein';
      if ($jaNein($rohDetail['tse_bse_frei']) !== null) $eig['TSE/BSE-frei'] = $jaNein($rohDetail['tse_bse_frei']) ? 'ja' : 'nein';
      if ($rohDetail['zertifikate'])   $eig['Zertifikate'] = $rohDetail['zertifikate'];
      if ($rohDetail['zusaetze'])      $eig['Zusätze'] = $rohDetail['zusaetze'];
      if ($rohDetail['haltbarkeit'])   $eig['Haltbarkeit'] = $rohDetail['haltbarkeit'];
      if ($rohDetail['lagerbedingungen']) $eig['Lagerung'] = $rohDetail['lagerbedingungen'];
    ?>
    <?php if ($eig): ?>
    <div class="bx-panel"><h2>Eigenschaften &amp; Deklaration</h2>
      <div class="bx-tablewrap"><table class="bx-table"><tbody>
        <?php foreach ($eig as $lbl => $wert): ?><tr><td style="width:220px"><?= h($lbl) ?></td><td><?= h($wert) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    </div>
    <?php endif; ?>

    <div class="bx-panel"><h2>Anfrage stellen</h2>
      <p class="muted" style="margin-top:0">Preis auf Anfrage – nennen Sie uns die gewünschte Menge.</p>
      <form method="post">
        <input type="hidden" name="aktion" value="rohstoff_anfrage">
        <input type="hidden" name="betreff" value="<?= h($rohDetail['name']) ?>">
        <input type="hidden" name="rohstoff_id" value="<?= (int)$rohDetail['id'] ?>">
        <div class="bx-grid">
          <div class="bx-field"><label>Gewünschte Menge</label><input type="number" name="wunsch_menge" min="0" step="0.001" placeholder="z. B. 25"></div>
          <div class="bx-field"><label>Einheit</label><select name="wunsch_einheit"><?php foreach (['kg','g','t','Stück','L'] as $e): ?><option value="<?= $e ?>"><?= $e ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="bx-field"><label>Details (optional)</label><textarea name="notiz" placeholder="z. B. vegan, Zieltermin, Spezifikation"></textarea></div>
        <button class="btn btn-primary" type="submit">Anfrage senden</button>
      </form>
    </div>
  <?php endif; ?>

<?php elseif ($view === 'rohanfrage'):
    $meine = array_filter($portalAnfragen, fn($a) => $a['typ'] === 'rohstoff');
    // Vorbefüllung aus dem Katalog-Direktlink (&iid=): Name des Rohstoffs in die erste Zeile.
    $vorRohName = (int)($_GET['iid'] ?? 0) ? (string) scalar("SELECT name FROM item WHERE id=? AND kategorie='rohstoff'", [(int)$_GET['iid']]) : '';
    // Info je Katalog-Rohstoff für die Live-Anzeige + Detail-Popup nach der Auswahl.
    $rohInfoMap = [];
    foreach ($rohkatalog as $r) $rohInfoMap[$r['name']] = [
        'form' => ($FORMLBL_P[$r['form']] ?? $r['form']), 'cas' => ($r['cas'] ?: ''),
        'lat' => ($r['name_lat'] ?? '') ?: '', 'syn' => ($r['synonym'] ?? '') ?: '',
        'quelle' => ($r['bot_quelle'] ?? '') ?: '', 'land' => ($r['herkunftsland'] ?? '') ?: '',
        'id' => (int)$r['id'],
    ]; ?>
  <h1 style="margin-bottom:4px">Rohstoff anfragen</h1>
  <div class="bx-panel">
    <p class="muted" style="margin-top:0">Wählen Sie einen Rohstoff aus dem Katalog oder tippen Sie ihn ein. Sie können mehrere Rohstoffe auf einmal anfragen – Sie erhalten <strong>je Rohstoff ein eigenes Angebot</strong>, das Sie einzeln annehmen können.</p>
    <form method="post">
      <input type="hidden" name="aktion" value="rohstoff_anfrage">
      <datalist id="rohliste"><?php foreach ($rohkatalog as $r): ?><option value="<?= h($r['name']) ?>"></option><?php endforeach; ?></datalist>
      <div id="rohRows">
        <?php for ($i=0;$i<1;$i++): ?>
        <div class="rohrow bx-panel" style="background:var(--panel-2);padding:12px 14px;margin-bottom:10px">
          <div class="bx-field"><label>Rohstoff</label><input type="text" name="roh_name[]" list="rohliste" value="<?= $i === 0 ? h($vorRohName) : '' ?>" placeholder="Rohstoff wählen oder eintippen">
            <div class="rohinfo muted" style="font-size:12px;margin-top:6px"></div></div>
          <div class="bx-grid">
            <div class="bx-field"><label>Menge</label><input type="number" name="roh_menge[]" min="0" step="0.001" placeholder="z. B. 25"></div>
            <div class="bx-field"><label>Einheit</label><select name="roh_einheit[]"><?php foreach (['kg','g','t','Stück','L'] as $e): ?><option value="<?= $e ?>"><?= $e ?></option><?php endforeach; ?></select></div>
            <div class="bx-field"><label>Wunschpreis <span class="muted">(optional)</span> <?= bx_hint('Falls Sie schon eine Preisvorstellung haben – € pro Einheit (z. B. je kg). Kein Muss; hilft uns nur bei der Einordnung.') ?></label><input type="number" name="roh_zielpreis[]" min="0" step="0.01" placeholder="z. B. 12,50 € / kg"></div>
          </div>
          <div class="bx-field"><label>Notiz (optional)</label><input type="text" name="roh_notiz[]" placeholder="Spezifikation, Qualität, Termin …"></div>
          <div style="text-align:right"><button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('.rohrow').remove()">entfernen</button></div>
        </div>
        <?php endfor; ?>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" id="rohAdd">+ weiterer Rohstoff</button>
      <div style="margin-top:14px"><button class="btn btn-primary" type="submit">Anfrage senden</button></div>
    </form>
  </div>
  <div id="rohDetailModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:16px" onclick="if(event.target===this)this.style.display='none'">
    <div class="bx-panel" style="max-width:520px;width:100%;max-height:90vh;overflow:auto;margin:0">
      <div class="bx-row" style="justify-content:space-between;align-items:center;gap:10px">
        <h2 id="rohDetailTitel" style="margin:0">Rohstoff</h2>
        <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('rohDetailModal').style.display='none'">Schließen</button>
      </div>
      <div id="rohDetailBody" style="margin-top:12px"></div>
    </div>
  </div>
  <script>
  window.rohInfo = <?= json_encode($rohInfoMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var rohToken = '<?= h($token) ?>';
  function rohEsc(s){ var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
  function rohInfoZeige(inp){
    var box = inp.closest('.rohrow').querySelector('.rohinfo');
    var name = inp.value.trim(); var d = window.rohInfo[name];
    if (d) {
      var t = []; if (d.form) t.push(d.form); if (d.cas) t.push('CAS ' + d.cas);
      box.innerHTML = '';
      box.appendChild(document.createTextNode((t.join(' · ') || 'Im Katalog verfügbar') + ' · '));
      var a = document.createElement('a'); a.href = '#'; a.textContent = 'Details ansehen';
      a.addEventListener('click', function(ev){ ev.preventDefault(); rohDetailsShow(name); });
      box.appendChild(a);
    } else { box.textContent = name ? 'Nicht im Katalog – wir prüfen die Beschaffung.' : ''; }
  }
  function rohDetailsShow(name){
    var d = window.rohInfo[name]; if (!d) return;
    var body = document.getElementById('rohDetailBody');
    document.getElementById('rohDetailTitel').textContent = name;
    body.innerHTML = '<div class="muted">Lädt …</div>';
    document.getElementById('rohDetailModal').style.display = 'flex';
    fetch('?p=portal&token=' + rohToken + '&v=rohstoff_info&iid=' + d.id)
      .then(function(r){ return r.json(); })
      .then(function(j){
        var rows = (j && j.rows) || [];
        var html = '';
        if (rows.length) {
          html += '<table style="width:100%;border-collapse:collapse">';
          rows.forEach(function(r){ html += '<tr><td style="color:var(--muted);padding:4px 14px 4px 0;white-space:nowrap;vertical-align:top">'+rohEsc(r[0])+'</td><td style="padding:4px 0">'+rohEsc(r[1])+'</td></tr>'; });
          html += '</table>';
        } else { html += '<div class="muted">Zu diesem Rohstoff liegen noch keine weiteren Angaben vor.</div>'; }
        // Freigegebene Dokumente zum Download
        var docs = '';
        if (j && j.spec) docs += '<a class="btn btn-ghost btn-sm" style="margin:6px 6px 0 0" target="_blank" href="?p=portal&token='+rohToken+'&v=spec_pdf&rid='+d.id+'">Spezifikation (PDF)</a>';
        if (j && j.coas) j.coas.forEach(function(c){ docs += '<a class="btn btn-ghost btn-sm" style="margin:6px 6px 0 0" target="_blank" href="?p=portal&token='+rohToken+'&v=coa_pdf&cid='+c.cid+'">CoA '+rohEsc(c.label)+'</a>'; });
        if (docs) html += '<div style="margin-top:14px"><div style="color:var(--muted);font-size:13px;margin-bottom:2px">Dokumente</div>'+docs+'</div>';
        body.innerHTML = html;
      })
      .catch(function(){ body.innerHTML = '<div class="muted">Details konnten nicht geladen werden.</div>'; });
  }
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') { var m=document.getElementById('rohDetailModal'); if(m) m.style.display='none'; } });
  document.getElementById('rohRows').addEventListener('input', function(e){
    if (e.target.matches('input[name="roh_name[]"]')) rohInfoZeige(e.target);
  });
  // Enter in einem Eingabefeld soll NICHT das Formular abschicken (nur Katalog-Auswahl übernehmen).
  // Absenden ausschließlich über den Button „Anfrage senden".
  document.getElementById('rohRows').closest('form').addEventListener('keydown', function(e){
    if (e.key === 'Enter' && e.target.tagName === 'INPUT') e.preventDefault();
  });
  document.getElementById('rohAdd').addEventListener('click', function(){
    var rows = document.getElementById('rohRows'); var first = rows.querySelector('.rohrow');
    var c = first.cloneNode(true); c.querySelectorAll('input').forEach(function(x){ x.value=''; });
    var inf = c.querySelector('.rohinfo'); if (inf) inf.textContent = ''; rows.appendChild(c);
  });
  // Vorbefüllte erste Zeile gleich mit Info versehen
  document.querySelectorAll('#rohRows input[name="roh_name[]"]').forEach(function(x){ if (x.value.trim()) rohInfoZeige(x); });
  </script>
  <?php if ($meine): ?>
  <div class="bx-panel"><h2>Meine Rohstoffanfragen</h2>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Nr.</th><th>Rohstoff</th><th class="bx-num">Menge</th><th class="bx-num">Wunschpreis</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($meine as $a): ?>
        <tr><td><?= h($a['nummer']) ?></td><td><?= h($a['betreff'] ?: '–') ?></td>
          <td class="bx-num"><?= $a['wunsch_menge'] ? rtrim(rtrim(number_format((float)$a['wunsch_menge'],3,',','.'),'0'),',').' '.h($a['wunsch_einheit'] ?: '') : '–' ?></td>
          <td class="bx-num"><?= !empty($a['zielpreis']) ? $eur($a['zielpreis']) : '–' ?></td>
          <td><?= $pafBadge($a['status']) ?><?php if ($a['status']==='abgelehnt' && !empty($a['absage_grund'])): ?><div class="muted" style="font-size:12px;white-space:normal"><?= h($a['absage_grund']) ?></div><?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>

<?php elseif ($view === 'dienstleistung'):
    $meine = array_filter($portalAnfragen, fn($a) => $a['typ'] === 'dienstleistung');
    $vorTyp = array_key_exists($_GET['typ'] ?? '', $DIENST_TYPEN) ? $_GET['typ'] : '';
    // Produkte für den Labortest-Bezug (unabhängig von der Produkt-Freischaltung): eigene + Katalog.
    $laborProdukte = all("SELECT p.id, COALESCE(NULLIF(p.kundenname,''),p.name) AS name FROM produkt p
        WHERE p.status='aktiv' AND (p.exklusiv=0 OR p.kunde_id=?) ORDER BY (p.kunde_id<>?), name", [$kid, $kid]);
    $vorProd = (int)($_GET['pid'] ?? 0); ?>
  <h1 style="margin-bottom:4px">Dienstleistung anfragen</h1>
  <div class="bx-panel">
    <p class="muted" style="margin-top:0">Wählen Sie, worum es geht – wir melden uns mit einem Angebot.</p>
    <form method="post">
      <input type="hidden" name="aktion" value="dienstleistung_anfrage">
      <div class="bx-field"><label>Art der Dienstleistung</label>
        <select name="dienstleistung_typ" id="dlTyp" required>
          <option value="">– bitte wählen –</option>
          <?php foreach ($DIENST_TYPEN as $tk => $tl): ?><option value="<?= $tk ?>" <?= $vorTyp === $tk ? 'selected' : '' ?>><?= h($tl) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field" id="dlLaborBox" style="display:none">
        <label>Für welche Produkte? <span class="muted">(ein Labortest bezieht sich auf ein oder mehrere Produkte)</span></label>
        <?php if ($laborProdukte): ?>
        <div style="max-height:220px;overflow:auto;border:1px solid var(--line);border-radius:var(--r);padding:10px 12px">
          <?php foreach ($laborProdukte as $lp): ?>
          <label style="display:flex;gap:8px;align-items:center;padding:3px 0"><input type="checkbox" name="dl_produkt[]" value="<?= (int)$lp['id'] ?>" <?= $vorProd === (int)$lp['id'] ? 'checked' : '' ?>> <?= h($lp['name']) ?></label>
          <?php endforeach; ?>
        </div>
        <?php else: ?><div class="muted">Noch keine Produkte hinterlegt – beschreiben Sie den Labortest bitte im Detailfeld.</div><?php endif; ?>
      </div>
      <div class="bx-field"><label>Betreff <span class="muted">(optional)</span></label><input type="text" name="betreff" placeholder="Kurzbeschreibung – leer = Art der Dienstleistung"></div>
      <div class="bx-field"><label>Details (optional)</label><textarea name="notiz" placeholder="Worum geht es genau? Umfang, Termin, gewünschte Parameter …"></textarea></div>
      <button class="btn btn-primary" type="submit">Anfrage senden</button>
    </form>
  </div>
  <script>
  (function(){ var s=document.getElementById('dlTyp'), b=document.getElementById('dlLaborBox');
    function upd(){ b.style.display = s.value==='labortest' ? '' : 'none'; }
    s.addEventListener('change', upd); upd();
  })();
  </script>
  <?php if ($meine): ?>
  <div class="bx-panel"><h2>Meine Dienstleistungsanfragen</h2>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Nr.</th><th>Art</th><th>Betreff</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($meine as $a): ?>
        <tr><td><?= h($a['nummer']) ?></td>
          <td><?= h($DIENST_TYPEN[$a['dienstleistung_typ'] ?? ''] ?? '–') ?></td>
          <td><?= h($a['betreff'] ?: '–') ?></td>
          <td><?= $pafBadge($a['status']) ?><?php if ($a['status']==='abgelehnt' && !empty($a['absage_grund'])): ?><div class="muted" style="font-size:12px;white-space:normal"><?= h($a['absage_grund']) ?></div><?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>

<?php elseif ($view === 'angebote'): ?>
  <h1 style="margin-bottom:4px">Ihre Angebote</h1>
  <p class="muted" style="margin:0 0 16px">Übersicht Ihrer Angebote. Offene Angebote können Sie hier direkt prüfen, eine Menge wählen und verbindlich annehmen.</p>
  <?php if (!$angebote): ?><div class="bx-panel"><div class="muted">Aktuell liegen keine Angebote vor.</div></div><?php endif; ?>
  <?php foreach ($angebote as $a): $st = $staffelFuer($a); $inf = $angInfoFuer($a); $accept = true; $open = ($a['status'] === 'gesendet'); include __DIR__ . '/_angebot_karte.php'; endforeach; ?>

  <script>(function(){
    var h = location.hash; if (h && /^#a\d+$/.test(h)) { var d = document.querySelector(h); if (d && d.tagName === 'DETAILS') { d.open = true; d.scrollIntoView(); } }
  })();</script>

<?php elseif ($view === 'bestellungen'):
    $inArbeit    = array_values(array_filter($auftraege, fn($a) => $a['status'] !== 'versendet'));
    $abgeschlBest = array_values(array_filter($auftraege, fn($a) => $a['status'] === 'versendet'));
    $btab = ($_GET['btab'] ?? '') === 'abgeschlossen' ? 'abgeschlossen' : 'arbeit';
    $aktBest = $btab === 'abgeschlossen' ? $abgeschlBest : $inArbeit; ?>
  <h1 style="margin-bottom:4px">Ihre Bestellungen</h1>
  <p class="muted" style="margin:0 0 16px">Klicken Sie auf eine Bestellung, um alle Schritte, Rechnung und Details zu sehen.</p>
  <?php if (!$auftraege): ?><div class="bx-panel"><div class="muted">Noch keine Bestellungen.</div></div>
  <?php else: ?>
  <div class="settabs" style="margin:0 0 12px">
    <a href="<?= $portalLink('bestellungen') ?>&btab=arbeit"        class="<?= $btab === 'arbeit' ? 'on' : '' ?>">In Bearbeitung<?= $inArbeit ? ' (' . count($inArbeit) . ')' : '' ?></a>
    <a href="<?= $portalLink('bestellungen') ?>&btab=abgeschlossen" class="<?= $btab === 'abgeschlossen' ? 'on' : '' ?>">Abgeschlossene<?= $abgeschlBest ? ' (' . count($abgeschlBest) . ')' : '' ?></a>
  </div>
  <?php if (!$aktBest): ?><div class="bx-panel"><div class="muted"><?= $btab === 'abgeschlossen' ? 'Noch keine abgeschlossenen Bestellungen.' : 'Aktuell keine Bestellung in Bearbeitung.' ?></div></div><?php endif; ?>
  <?php endif; ?>
  <?php foreach ($aktBest as $a): $cur = kunde_auftrag_phase($a)['idx']; $complete = $a['status'] === 'versendet'; ?>
  <a class="bx-panel bx-order-row" href="<?= $portalLink('bestellung') ?>&aid=<?= (int)$a['id'] ?>" style="display:block;text-decoration:none;color:inherit">
    <div class="bx-row" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
      <div><strong><?= h($a['nummer']) ?></strong> · <?= h($titelFuer($a)) ?> <span class="muted">· <?= (int)$a['menge'] ?> Packungen</span><?= !empty($a['kontingent_id']) ? ' <span class="muted" style="font-size:12px">· aus Jahresvertrag</span>' : '' ?></div>
      <div class="bx-row" style="gap:10px;align-items:center"><?= $aufBadge($a['status']) ?><span class="muted" style="font-size:18px;line-height:1">&#8250;</span></div>
    </div>
    <ul class="bx-steps" style="margin-top:12px">
      <?php foreach ($AUFSTEPS as $i => $lbl):
          $cls = $i < $cur ? 'done' : ($i === $cur ? ($complete ? 'done' : 'current') : ''); ?>
        <li class="bx-step <?= $cls ?>">
          <span class="dot"><?= ($cls === 'done' || $cls === 'current') ? '&#10003;' : '' ?></span>
          <span class="lbl"><?= h($lbl) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </a>
  <?php endforeach; ?>

<?php elseif ($view === 'bestellung'):
    $aid = (int)($_GET['aid'] ?? 0);
    $a = null; foreach ($auftraege as $x) if ((int)$x['id'] === $aid) { $a = $x; break; }
    if (!$a): ?>
      <div class="bx-panel"><div class="muted">Bestellung nicht gefunden.</div>
        <div style="margin-top:12px"><a class="btn btn-ghost btn-sm" href="<?= $portalLink('bestellungen') ?>">Zurück zu den Bestellungen</a></div></div>
    <?php else:
      $complete = $a['status'] === 'versendet';
      $re  = one("SELECT * FROM beleg WHERE auftrag_id=? AND typ='rechnung' ORDER BY id DESC LIMIT 1", [(int)$a['id']]);
      $vName = $a['verpackung_id'] ? scalar("SELECT name FROM item WHERE id=?", [(int)$a['verpackung_id']]) : '';
      // Feste Kunden-Phasen (wie v3) – KEINE internen Produktionsschritte. Identisch für Rohstoff-
      // und Fertigprodukt-Bestellung, verrät also nie einen Zukauf.
      $ph = kunde_auftrag_phase($a); $cur = $ph['idx'];
      $track = [];
      foreach ($AUFSTEPS as $i => $lbl) {
          $done  = $complete || $i < $cur;
          $isCur = !$complete && $i === $cur;
          $track[] = ['label'=>$lbl, 'done'=>$done, 'current'=>$isCur, 'date'=>$ph['dates'][$i] ?? null];
      }
    ?>
  <div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px;margin-bottom:4px">
    <h1 style="margin:0"><?= h($a['nummer']) ?></h1>
    <a class="btn btn-ghost btn-sm" href="<?= $portalLink('bestellungen') ?>">&#8592; Alle Bestellungen</a>
  </div>
  <p class="muted" style="margin:0 0 16px"><?= h($titelFuer($a)) ?></p>

  <!-- Status-Kacheln -->
  <div class="bx-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px">
    <div class="bx-panel" style="margin:0"><div class="muted">Status</div><div style="margin-top:6px"><?= $aufBadge($a['status']) ?></div></div>
    <div class="bx-panel" style="margin:0"><div class="muted">Menge</div><div style="margin-top:6px"><?= (int)$a['menge'] ?> Packungen<?php if ((int)$a['stueck']): ?> &middot; <?= (int)$a['stueck'] ?> je Packung<?php endif; ?></div></div>
    <div class="bx-panel" style="margin:0"><div class="muted">Gesamtbetrag</div><div style="margin-top:6px"><strong><?= $eur($re ? $re['brutto'] : $a['gesamt_netto']) ?></strong><?php if ($re): ?> <span class="muted">brutto</span><?php endif; ?></div></div>
    <div class="bx-panel" style="margin:0"><div class="muted">Zahlung</div><div style="margin-top:6px"><?= $re ? $reBadge($re['status']) : '<span class="muted">–</span>' ?></div></div>
  </div>

  <?php
  $etDok  = etikett_datei((int)$a['id']);
  $etSlot = (int) scalar("SELECT etikett_id FROM produkt WHERE id=?", [(int)$a['produkt_id']]) > 0;
  if ($etSlot):
  ?>
  <div class="bx-panel" style="border-color:var(--gruen)">
    <h2 style="margin:0 0 8px;font-size:16px">Ihr Etikett-Design</h2>
    <?php if (isset($_GET['etikett'])): ?><div class="muted" style="margin-bottom:8px"><span class="bx-ok">Gespeichert.</span> Danke!</div><?php endif; ?>
    <p style="margin:0 0 10px"><a class="btn btn-ghost btn-sm" href="<?= $portalLink('pib') ?>&aid=<?= (int)$a['id'] ?>" target="_blank">Produktinformationsblatt (PIB) herunterladen</a> <span class="muted" style="font-size:12px">– Grundlage für Ihr Etikett (Zutaten, Nährwerte, Menge)</span></p>
    <?php if ($etDok): ?>
      <p style="margin-top:0">Hochgeladen: <a href="<?= $portalLink('etikett_datei') ?>&aid=<?= (int)$a['id'] ?>" target="_blank"><?= h($etDok['datei_orig'] ?: 'Etikett-Design') ?></a> <span class="muted">· <?= h(fmt_zeit($etDok['angelegt'], 'd.m.Y')) ?></span></p>
      <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:8px;align-items:center;margin:0"><input type="hidden" name="aktion" value="etikett_upload"><input type="hidden" name="auftrag_id" value="<?= (int)$a['id'] ?>"><input type="file" name="etikett" required accept="application/pdf,image/*"><button class="btn btn-ghost btn-sm" type="submit">Neues Design hochladen</button></form>
    <?php else: ?>
      <p style="margin-top:0">Für dieses Produkt brauchen wir Ihr <strong>Etikett-Design</strong>. Bitte laden Sie die Druckdatei (PDF oder Bild) hoch – erst dann können wir die Etiketten bestellen und in Produktion gehen.</p>
      <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:8px;align-items:center;margin:0"><input type="hidden" name="aktion" value="etikett_upload"><input type="hidden" name="auftrag_id" value="<?= (int)$a['id'] ?>"><input type="file" name="etikett" required accept="application/pdf,image/*"><button class="btn btn-primary btn-sm" type="submit">Etikett-Design hochladen</button></form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Fortschritt mit Datum (horizontal, wie Ladebalken) -->
  <div class="bx-panel">
    <h2 style="margin:0 0 18px;font-size:16px">Fortschritt</h2>
    <div style="overflow-x:auto">
      <ul class="bx-htrack">
        <?php foreach ($track as $t): $cls = $t['done'] ? 'done' : ($t['current'] ? 'current' : ''); ?>
          <li class="bx-hstep <?= $cls ?>">
            <span class="dot"><?= ($t['done'] || $t['current']) ? '&#10003;' : '' ?></span>
            <span class="lbl"><?= h($t['label']) ?></span>
            <span class="date"><?= $t['date'] ? h(fmt_zeit($t['date'], 'd.m.Y')) : '' ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>

  <!-- Bestelldetails + Rechnung nebeneinander -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:16px;align-items:start;margin-bottom:var(--sp-5)">
  <div class="bx-panel" style="margin:0">
    <h2 style="margin:0 0 14px;font-size:16px">Bestelldetails</h2>
    <div class="bx-tablewrap"><table class="bx-table">
      <tbody>
        <tr><td class="muted" style="width:150px">Produkt</td><td><?= h($titelFuer($a)) ?></td></tr>
        <?php if ((int)$a['stueck']): ?><tr><td class="muted">Stück je Packung</td><td><?= (int)$a['stueck'] ?></td></tr><?php endif; ?>
        <tr><td class="muted">Anzahl Packungen</td><td><?= (int)$a['menge'] ?></td></tr>
        <?php if ($vName): ?><tr><td class="muted">Verpackung</td><td><?= h($vName) ?></td></tr><?php endif; ?>
        <?php if ((float)$a['vk_stueck']): ?><tr><td class="muted">Preis je Packung</td><td><?= $eur($a['vk_stueck']) ?></td></tr><?php endif; ?>
        <tr><td class="muted">Bestellt am</td><td><?= h(fmt_zeit($a['angelegt'], 'd.m.Y')) ?></td></tr>
      </tbody>
    </table></div>
    <div class="bx-row" style="justify-content:flex-end;margin-top:14px">
      <a class="btn btn-ghost btn-sm" target="_blank" href="<?= $portalLink('ppwr_pdf') ?>&aid=<?= (int)$a['id'] ?>">Verpackungs-Konformität (PDF)</a>
    </div>
  </div>

  <div class="bx-panel" style="margin:0">
    <h2 style="margin:0 0 14px;font-size:16px">Rechnung</h2>
    <?php if (!$re): ?>
      <div class="muted">Für diese Bestellung liegt noch keine Rechnung vor.</div>
    <?php else: ?>
      <div class="bx-tablewrap"><table class="bx-table">
        <tbody>
          <tr><td class="muted" style="width:150px">Rechnungsnummer</td><td><?= h($re['nummer']) ?></td></tr>
          <tr><td class="muted">Rechnungsdatum</td><td><?= $re['datum'] ? h(fmt_zeit($re['datum'], 'd.m.Y')) : h(fmt_zeit($re['angelegt'], 'd.m.Y')) ?></td></tr>
          <tr><td class="muted">Netto</td><td class="bx-num"><?= $eur($re['netto']) ?></td></tr>
          <tr><td class="muted">USt (<?= rtrim(rtrim(number_format((float)$re['ust_prozent'],2,',','.'),'0'),',') ?> %)</td><td class="bx-num"><?= $eur($re['ust_betrag']) ?></td></tr>
          <tr><td class="muted">Brutto</td><td class="bx-num"><strong><?= $eur($re['brutto']) ?></strong></td></tr>
          <tr><td class="muted">Zahlungsstatus</td><td><?= $reBadge($re['status']) ?></td></tr>
        </tbody>
      </table></div>
    <?php endif; ?>
    <?php
      $angVorhanden = false;
      if (!empty($a['angebot_id'])) foreach ($angebote as $x) if ((int)$x['id'] === (int)$a['angebot_id']) { $angVorhanden = true; break; }
    ?>
    <div class="bx-row" style="gap:10px;flex-wrap:wrap;margin-top:14px">
      <?php if ($angVorhanden): ?><a class="btn btn-ghost" target="_blank" style="flex:1 1 200px;justify-content:center;padding:14px 16px;font-size:15px" href="<?= $portalLink('angebot_pdf') ?>&aid=<?= (int)$a['angebot_id'] ?>">Angebot (AN)</a><?php endif; ?>
      <a class="btn btn-ghost" target="_blank" style="flex:1 1 200px;justify-content:center;padding:14px 16px;font-size:15px" href="<?= $portalLink('ab_pdf') ?>&aid=<?= (int)$a['id'] ?>">Auftragsbestätigung (AB)</a>
      <?php if ($re): ?><a class="btn btn-ghost" target="_blank" style="flex:1 1 200px;justify-content:center;padding:14px 16px;font-size:15px" href="<?= $portalLink('rechnung_pdf') ?>&aid=<?= (int)$a['id'] ?>">Rechnung (RE)</a><?php endif; ?>
    </div>
  </div>
  </div>

  <?php endif; ?>

<?php elseif ($view === 'kontingente'):
  $nf  = fn($x) => number_format((int)$x, 0, ',', '.');
  $eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
  // Offene Jahresvertrags-Angebote (noch kein Kontingent) – zum verbindlichen Abschluss.
  $jvOffers = all("SELECT a.*, COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt
                   FROM angebot a LEFT JOIN produkt p ON p.id=a.produkt_id
                   WHERE a.kunde_id=? AND a.jahresvertrag=1 AND a.status<>'offen' AND a.kunde_ausgeblendet=0
                     AND NOT EXISTS (SELECT 1 FROM kontingent kk WHERE kk.angebot_id=a.id)
                   ORDER BY a.angelegt DESC", [(int)$k['id']]);
  // In Abwicklung: Kontingent existiert, aber noch nicht aktiv (wartet auf Vertrag / Freigabe).
  $konsPend = all("SELECT k.*, COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt,
                          (SELECT id FROM dokument d WHERE d.objekt_typ='kontingent' AND d.objekt_id=k.id AND d.typ='jv_signiert' ORDER BY d.id DESC LIMIT 1) AS sig
                   FROM kontingent k LEFT JOIN produkt p ON p.id=k.produkt_id
                   WHERE k.kunde_id=? AND k.status IN ('wartet_vertrag','wartet_freigabe') ORDER BY k.angelegt DESC", [(int)$k['id']]);
  $kons = all("SELECT k.*, COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt
               FROM kontingent k LEFT JOIN produkt p ON p.id=k.produkt_id
               WHERE k.kunde_id=? AND k.status='aktiv' ORDER BY k.angelegt DESC", [(int)$k['id']]); ?>
  <h1 style="margin-bottom:4px">Ihre Jahresverträge</h1>
  <p class="bx-sub">Jahresabnahmeverträge zum Festpreis. Nach Abschluss rufen Sie Ihre Mengen nach Bedarf ab – jeder Abruf wird eine Bestellung zum vereinbarten Preis.</p>
  <?php if (isset($_GET['abgerufen'])): ?><div class="bx-panel badge-ok" style="padding:12px 16px"><?= (int)$_GET['abgerufen'] ?> Packungen abgerufen – Ihre Bestellung wurde angelegt (siehe „Bestellungen").</div><?php endif; ?>
  <?php if (isset($_GET['jvok'])): ?><div class="bx-panel badge-ok" style="padding:12px 16px">Jahresvertrag abgeschlossen. Bitte laden Sie unten den <strong>unterschriebenen Vertrag</strong> hoch – nach unserer Prüfung wird er aktiv.</div><?php endif; ?>
  <?php if (isset($_GET['jvupload'])): ?><div class="bx-panel badge-ok" style="padding:12px 16px">Vielen Dank – der unterschriebene Vertrag ist eingegangen und wird von uns geprüft.</div><?php endif; ?>
  <?php if (isset($_GET['kfehler'])): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px"><?= h((string)$_GET['kfehler']) ?></div><?php endif; ?>

  <?php // 1) Offene Jahresvertrags-Angebote – verbindlich abschließen (alle Konditionen sichtbar).
  foreach ($jvOffers as $o):
      $jm = (int)($o['jahresmenge'] ?? 0); $jvk = (float)($o['jahres_vk'] ?? 0); $jmon = (int)($o['jahres_laufzeit_monate'] ?? 12) ?: 12; ?>
    <div class="bx-panel" style="border-color:var(--gruen)">
      <div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
        <h2 style="margin:0">Jahresabnahmevertrag: <?= h($o['produkt'] ?: 'Produkt') ?></h2>
        <span class="badge" style="background:var(--gruen);color:#fff;padding:2px 10px;border-radius:999px;font-size:12px">zum Abschluss</span>
      </div>
      <div class="bx-row" style="gap:24px;flex-wrap:wrap;margin:12px 0">
        <div><div class="k muted">Gesamtmenge</div><div><strong><?= $nf($jm) ?></strong> Packungen</div></div>
        <div><div class="k muted">Festpreis</div><div><?= $eur($jvk) ?> / Packung</div></div>
        <div><div class="k muted">Gesamtwert (netto)</div><div><?= $eur($jm * $jvk) ?></div></div>
        <div><div class="k muted">Laufzeit</div><div><?= $jmon ?> Monate</div></div>
      </div>
      <p class="muted" style="margin:0 0 10px;font-size:13px">Mit dem Abschluss verpflichten Sie sich, die Gesamtmenge innerhalb der Laufzeit abzunehmen. Sie rufen die Mengen später nach Bedarf hier ab – zum festen Preis. <a href="<?= $portalLink('vertrag_pdf') ?>&aid=<?= (int)$o['id'] ?>" target="_blank"><strong>Vertrag als PDF ansehen</strong></a>.</p>
      <form method="post" onsubmit="return confirm('Jahresabnahmevertrag über <?= $nf($jm) ?> Packungen verbindlich abschließen?');">
        <input type="hidden" name="aktion" value="jahresvertrag_abschliessen">
        <input type="hidden" name="angebot_id" value="<?= (int)$o['id'] ?>">
        <label style="display:flex;gap:8px;align-items:flex-start;line-height:1.45;margin-bottom:10px">
          <input type="checkbox" name="bestaetigt" value="1" required style="margin-top:3px;flex:none">
          <span>Ich schließe diesen Jahresabnahmevertrag über <strong><?= $nf($jm) ?> Packungen</strong> zum Festpreis <strong><?= $eur($jvk) ?>/Packung</strong> verbindlich ab und verpflichte mich zur Abnahme der Gesamtmenge innerhalb der Laufzeit.</span>
        </label>
        <div class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap">
          <div class="bx-field" style="margin:0;max-width:280px"><label>Ihr Name <span class="muted">(gilt als verbindliche Bestätigung)</span></label>
            <input type="text" name="freigabe_name" required autocomplete="name" placeholder="Vor- und Nachname"></div>
          <button class="btn btn-primary" type="submit">Jahresvertrag verbindlich abschließen</button>
        </div>
      </form>
    </div>
  <?php endforeach; ?>

  <?php // 2) In Abwicklung: Vertrag hochladen / wird geprüft.
  foreach ($konsPend as $kp): $wartetVertrag = ($kp['status'] === 'wartet_vertrag'); ?>
    <div class="bx-panel">
      <div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
        <h2 style="margin:0"><?= h($kp['produkt'] ?: 'Produkt') ?></h2>
        <span class="muted"><?= $nf($kp['gesamt_menge']) ?> Packungen · <?= $eur($kp['vk_stueck']) ?>/Packung</span>
      </div>
      <?php if ($wartetVertrag): ?>
        <p style="margin:10px 0">Bitte laden Sie den <strong>unterschriebenen Vertrag</strong> hoch. Nach unserer Prüfung wird der Vertrag aktiv und Sie können Mengen abrufen.
          <?php if ($kp['angebot_id']): ?><br><a href="<?= $portalLink('vertrag_pdf') ?>&aid=<?= (int)$kp['angebot_id'] ?>" target="_blank"><strong>Vertrag als PDF herunterladen</strong></a> → unterschreiben → einscannen → unten hochladen.<?php endif; ?></p>
        <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap">
          <input type="hidden" name="aktion" value="jahresvertrag_upload">
          <input type="hidden" name="kontingent_id" value="<?= (int)$kp['id'] ?>">
          <div class="bx-field" style="margin:0"><label>Unterschriebener Vertrag (PDF oder Foto)</label><input type="file" name="vertrag" required accept=".pdf,.jpg,.jpeg,.png,.webp"></div>
          <button class="btn btn-primary" type="submit">Hochladen</button>
        </form>
      <?php else: ?>
        <div class="bx-panel" style="background:var(--panel-2);padding:10px 14px;margin:10px 0 0">
          <strong>Vertrag eingegangen – wird von uns geprüft.</strong> Sobald wir ihn freigegeben haben, können Sie hier Ihre Mengen abrufen.
          <?php if ($kp['sig']): ?> <a href="<?= $portalLink('jv_signiert') ?>&kid=<?= (int)$kp['id'] ?>" target="_blank">Ihren Upload ansehen</a>.<?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php // 3) Aktive Verträge – Mengen abrufen.
  if (!$kons && !$jvOffers && !$konsPend): ?><div class="bx-panel"><div class="muted">Aktuell keine Jahresverträge.</div></div>
  <?php elseif ($kons): ?><h2 style="margin:22px 0 8px">Aktive Verträge</h2><?php endif; ?>
  <?php foreach ($kons as $kon): $rest = (int)$kon['gesamt_menge'] - (int)$kon['abgerufen'];
      $bis = $kon['gueltig_bis'] ? date('d.m.Y', strtotime((string)$kon['gueltig_bis'])) : null;
      $ab  = !empty($kon['gueltig_bis']) && (string)$kon['gueltig_bis'] < gmdate('Y-m-d'); ?>
    <div class="bx-panel">
      <div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
        <h2 style="margin:0"><?= h($kon['produkt'] ?: 'Produkt') ?></h2>
        <div class="muted">Ihr Preis: <?= number_format((float)$kon['vk_stueck'], 2, ',', '.') ?> &euro; / Packung<?= $bis ? ' · gültig bis ' . h($bis) : '' ?></div>
      </div>
      <div class="bx-row" style="gap:24px;flex-wrap:wrap;margin:12px 0">
        <div><div class="k muted">Vereinbart</div><div><?= $nf($kon['gesamt_menge']) ?></div></div>
        <div><div class="k muted">Abgerufen</div><div><?= $nf($kon['abgerufen']) ?></div></div>
        <div><div class="k muted">Rest</div><div><strong><?= $nf($rest) ?></strong></div></div>
      </div>
      <?php if ($ab): ?><div class="muted">Dieser Vertrag ist abgelaufen – bitte melden Sie sich bei uns.</div>
      <?php elseif ($rest <= 0): ?><div class="muted">Kontingent ausgeschöpft.</div>
      <?php else: ?>
      <form method="post" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap" onsubmit="return confirm('Menge verbindlich abrufen? Es entsteht eine Bestellung zum vereinbarten Preis.');">
        <input type="hidden" name="aktion" value="kontingent_abruf">
        <input type="hidden" name="kontingent_id" value="<?= (int)$kon['id'] ?>">
        <div class="bx-field" style="margin:0;max-width:200px"><label>Menge abrufen (max. <?= $nf($rest) ?>)</label>
          <input type="number" name="menge" min="1" max="<?= $rest ?>" required placeholder="z. B. 5000"></div>
        <button class="btn btn-primary" type="submit">Menge abrufen</button>
      </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

<?php elseif ($view === 'agb'):
  // Aktuelle Fassung, oder eine bestimmte über ?fassung=<id> – so lässt sich nachlesen, was bei
  // einer früheren Bestätigung galt. Der Inhalt ist bewusst HTML (vom Team gepflegt).
  $agbAnzeige = !empty($_GET['fassung']) ? agb_fassung((int)$_GET['fassung']) : agb_aktuell(); ?>
  <h1 style="margin-bottom:4px">Allgemeine Geschäftsbedingungen</h1>
  <?php if ($agbAnzeige): ?>
  <p class="bx-sub">Fassung <strong><?= h($agbAnzeige['version']) ?></strong><?= !empty($agbAnzeige['angelegt']) ? ' · Stand ' . h(date('d.m.Y', strtotime((string)$agbAnzeige['angelegt']))) : '' ?><?= (int)($agbAnzeige['aktiv'] ?? 0) === 1 ? '' : ' · frühere Fassung' ?></p>
  <div class="bx-panel" style="line-height:1.65;max-width:820px"><?= $agbAnzeige['inhalt'] ?></div>
  <?php else: ?>
  <div class="bx-panel"><div class="muted">Zurzeit sind keine AGB hinterlegt.</div></div>
  <?php endif; ?>
<?php elseif ($view === 'rechnungen'): ?>
  <h1 style="margin-bottom:4px">Ihre Rechnungen</h1>
  <div class="bx-panel">
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Nummer</th><th>Datum</th><th class="bx-num">Betrag</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php if (!$rechnungen): ?><tr><td colspan="5" class="muted">Noch keine Rechnungen.</td></tr><?php endif; ?>
      <?php foreach ($rechnungen as $r): ?>
        <tr><td><?= h($r['nummer']) ?></td><td><?= $r['datum']?h(date('d.m.Y',strtotime($r['datum']))):'' ?></td><td class="bx-num"><?= $eur($r['brutto']) ?></td><td><?= $reBadge($r['status']) ?></td>
          <td class="bx-num" style="white-space:nowrap"><?php if (!empty($r['auftrag_id'])): ?><a class="btn btn-ghost btn-sm" target="_blank" href="<?= $portalLink('rechnung_pdf') ?>&aid=<?= (int)$r['auftrag_id'] ?>">Rechnung (PDF)</a><?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
<?php endif; ?>
  </main>
</div>
<script>
(function(){
  var add=document.getElementById('paddW');
  if(add) add.addEventListener('click',function(){
    var tr=document.createElement('tr'); tr.className='pwrow';
    tr.innerHTML='<td><input type="text" name="w_bez[]" placeholder="z. B. Magnesium"></td>'
      +'<td><input type="text" name="w_menge[]"></td>'
      +'<td><select name="w_einheit[]" style="width:80px"><option>mg</option><option>g</option><option>µg</option><option>IE</option><option>ml</option></select></td>'
      +'<td><button type="button" class="btn btn-ghost btn-sm">×</button></td>';
    tr.querySelector('button').addEventListener('click',function(){tr.remove();kapUpdate();});
    document.getElementById('pwrows').appendChild(tr);
  });

  // Absenden nur mit Produktname UND (Idee ODER mind. eine Zutat mit Menge). Kein versehentliches Enter-Absenden.
  var pf=document.getElementById('pf_anfrageform');
  if(pf){
    pf.addEventListener('keydown',function(e){
      // Enter in normalen Feldern nicht absenden lassen; im Textarea bleibt der Zeilenumbruch erhalten.
      if(e.key==='Enter' && e.target && e.target.tagName!=='TEXTAREA' && e.target.type!=='submit'){ e.preventDefault(); }
    });
    pf.addEventListener('submit',function(e){
      var nEl=pf.querySelector('[name=produktname]'); var name=(nEl?nEl.value:'').trim();
      var idee=((pf.querySelector('[name=notiz]')||{}).value||'').trim();
      var hatZutat=false;
      pf.querySelectorAll('.pwrow').forEach(function(tr){
        var b=((tr.querySelector('[name="w_bez[]"]')||{}).value||'').trim();
        var m=((tr.querySelector('[name="w_menge[]"]')||{}).value||'').trim();
        if(b!=='' && m!=='') hatZutat=true;
      });
      if(name===''){ e.preventDefault(); alert('Bitte geben Sie einen Wunsch-Produktnamen an.'); if(nEl) nEl.focus(); return; }
      if(idee==='' && !hatZutat){ e.preventDefault(); alert('Bitte beschreiben Sie Ihre Idee oder tragen Sie mindestens eine Zutat mit Menge ein.'); return; }
    });
  }

  // Live-Kapsel-Check im Anfrageformular – erklärt dem Kunden freundlich, ob die Menge je Kapsel passt.
  var KAPS = <?= json_encode($portalKapseln ?? [], JSON_UNESCAPED_UNICODE) ?>;
  var maxKap = KAPS.length ? +KAPS[KAPS.length-1].fuellmenge_mg : 0;
  var formSel = document.getElementById('pf_form');
  var box = document.getElementById('pf_kapselcheck');
  function nf(x){ return Math.round(x).toLocaleString('de-DE'); }
  function istKapsel(){ var v=formSel?formSel.value:''; return v==='kapsel'||v==='softgel'; }
  function summeMg(){
    var mg=0, mengen=document.querySelectorAll('input[name="w_menge[]"]'), einh=document.querySelectorAll('[name="w_einheit[]"]');
    mengen.forEach(function(inp,i){
      var val=parseFloat((inp.value||'').replace(',','.'))||0;
      var u=(einh[i]?einh[i].value:'mg').toLowerCase().trim();
      if(u==='g') mg+=val*1000;
      else if(u==='µg'||u==='mcg'||u==='ug') mg+=val/1000;
      else if(u==='mg') mg+=val;
      // IE / ml: kein Gewicht -> nicht in die mg-Summe
    });
    return mg;
  }
  function pick(mg){ for(var i=0;i<KAPS.length;i++){ if(+KAPS[i].fuellmenge_mg>=mg) return KAPS[i]; } return null; }
  window.kapUpdate=function(){
    if(!box) return;
    if(!istKapsel()){ box.style.display='none'; return; }
    var mg=summeMg();
    if(mg<=0){ box.style.display='none'; return; }
    box.style.display='';
    var kap=pick(mg);
    if(kap){
      var gross = KAPS.indexOf(kap) >= KAPS.length-2;   // eine der zwei größten Kapseln
      if(!gross){
        box.style.background='#f3faf6'; box.style.border='1px solid #c3e8d7'; box.style.color='#14532d';
        box.innerHTML='<strong>Passt gut.</strong> Ihre Summe je Kapsel ist '+nf(mg)+' mg – das passt bequem in eine Kapsel der <strong>'+kap.name+'</strong>. '
          +'Je nach Pulverdichte kann sich das noch leicht verschieben; das prüfen wir final für Sie.';
      } else {
        box.style.background='#fff8ec'; box.style.border='1px solid #e8d6a8'; box.style.color='#6b4e12';
        box.innerHTML='<strong>Passt – aber knapp.</strong> Ihre Summe je Kapsel ist '+nf(mg)+' mg. Das passt nur in eine <strong>sehr große Kapsel ('+kap.name+')</strong>, die viele Menschen schwerer schlucken. '
          +'Wenn Sie mögen, reduzieren Sie die Menge je Kapsel oder teilen die Einnahme auf <strong>2 Kapseln pro Tag</strong> auf (dann ca. '+nf(mg/2)+' mg je Kapsel). Das stimmen wir gern mit Ihnen ab.';
      }
    } else {
      var proTag=Math.max(2, Math.ceil(mg/maxKap));
      box.style.background='#fbeceb'; box.style.border='1px solid #e6c4c0'; box.style.color='#7a231b';
      box.innerHTML='<strong>Bitte kurz anpassen.</strong> Ihre Summe je Kapsel ist '+nf(mg)+' mg. In eine Kapsel passen aber nur etwa <strong>'+nf(maxKap)+' mg</strong> Pulver (größte Kapsel). '
        +'Bitte verringern Sie die Menge je Kapsel – oder verteilen Sie die Wirkstoffe auf <strong>'+proTag+' Kapseln pro Tag</strong> (dann rund '+nf(mg/proTag)+' mg je Kapsel). '
        +'Das ist ein Richtwert; die genaue Füllmenge hängt von der Dichte der Rohstoffe ab und wird final mit Ihnen abgestimmt.';
    }
  };
  if(formSel) formSel.addEventListener('change',kapUpdate);
  document.addEventListener('input',function(e){ if(e.target && e.target.name==='w_menge[]') kapUpdate(); });
  document.addEventListener('change',function(e){ if(e.target && e.target.name==='w_einheit[]') kapUpdate(); });
  kapUpdate();
})();
</script>
<?php // Verbindliche Bestätigung: Nichts wird angenommen, ohne dass der Kunde bewusst zustimmt und
      // seinen Namen einträgt – der Name gilt als Unterschrift und wird mit Zeitpunkt gespeichert.
      // Ein Dialog für alles: die Knöpfe füllen ihn über bxBestaetigen(...) mit Titel, Text und Feldern. ?>
<div id="bxBestOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:16px">
  <div role="dialog" aria-modal="true" class="bx-panel" style="max-width:560px;width:100%;max-height:92vh;overflow:auto;margin:0">
    <h2 id="bxBestTitel" style="margin-top:0">Verbindlich annehmen</h2>
    <div id="bxBestText" class="muted" style="font-size:14px;line-height:1.5;margin-bottom:14px"></div>
    <form method="post" id="bxBestForm">
      <div id="bxBestFelder"></div>
      <div id="bxBestUpsell" style="display:none;border:1px solid var(--gruen);border-radius:var(--r);padding:10px 12px;margin-bottom:12px;background:var(--panel-2)">
        <label style="display:flex;gap:8px;align-items:flex-start;line-height:1.4;margin:0">
          <input type="checkbox" name="labortest" value="1" style="margin-top:3px;flex:none">
          <span><strong>Labortest dazubuchen</strong> <span class="muted">– für <span id="bxUpProd"></span>. Wir prüfen Ihr Produkt im Labor (z. B. Schwermetalle, Mikrobiologie) und melden uns mit einem separaten Angebot. Unverbindlich.</span></span>
        </label>
      </div>
      <label style="display:flex;gap:8px;align-items:flex-start;line-height:1.45;margin-bottom:12px">
        <input type="checkbox" name="bestaetigt" value="1" required style="margin-top:3px;flex:none">
        <span id="bxBestHaken">Ich habe alles geprüft und nehme verbindlich an.</span>
      </label>
      <?php $agbAkt = agb_aktuell(); if ($agbAkt): ?>
      <label style="display:flex;gap:8px;align-items:flex-start;line-height:1.45;margin-bottom:12px">
        <input type="checkbox" name="agb" value="1" required style="margin-top:3px;flex:none">
        <span>Ich akzeptiere die <a href="<?= $portalLink('agb') ?>" target="_blank">AGB (Fassung <?= h($agbAkt['version']) ?>)</a>.</span>
      </label>
      <?php endif; ?>
      <div class="bx-field"><label>Ihr Name <span class="muted">(gilt als verbindliche Bestätigung)</span></label>
        <input type="text" name="freigabe_name" required autocomplete="name" placeholder="Vor- und Nachname" style="width:100%;box-sizing:border-box"></div>
      <div class="bx-row" style="justify-content:flex-end;gap:10px;margin-top:16px">
        <button type="button" class="btn btn-ghost" onclick="bxBestZu()">Abbrechen</button>
        <button type="submit" class="btn btn-primary">Verbindlich annehmen</button>
      </div>
    </form>
  </div>
</div>
<script>
function bxBestZu(){ document.getElementById('bxBestOverlay').style.display='none'; }
// titel/text: was bestätigt wird · haken: Text neben der Checkbox · felder: {name: wert} für das POST
function bxBestaetigen(titel, text, haken, felder, upsell){
  document.getElementById('bxBestTitel').textContent = titel;
  document.getElementById('bxBestText').innerHTML = text;
  if (haken) document.getElementById('bxBestHaken').textContent = haken;
  var box = document.getElementById('bxBestFelder'); box.innerHTML = '';
  for (var n in felder) {
    var el = document.createElement('input'); el.type='hidden'; el.name=n; el.value=felder[n]; box.appendChild(el);
  }
  // Optionaler Upsell (Labortest): nur bei Angebots-Annahmen und wenn der Kunde Dienstleistungen anfragen darf.
  var up = document.getElementById('bxBestUpsell'), upCb = up.querySelector('input[name="labortest"]');
  upCb.checked = false;
  if (upsell && upsell.name) { document.getElementById('bxUpProd').textContent = upsell.name; up.style.display=''; }
  else { up.style.display='none'; }
  var ov = document.getElementById('bxBestOverlay');
  ov.style.display='flex';
  var eingabe = ov.querySelector('input[name="freigabe_name"]'); if (eingabe) setTimeout(function(){ eingabe.focus(); }, 50);
}
document.addEventListener('keydown', function(e){ if (e.key === 'Escape') bxBestZu(); });
document.getElementById('bxBestOverlay').addEventListener('click', function(e){ if (e.target === this) bxBestZu(); });
</script>
<?php portal_foot();
