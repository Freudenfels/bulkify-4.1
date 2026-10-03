<?php
// Schnittstelle fuer das Brueckenprogramm im Lager (bruecke.ps1 auf einem Lager-PC).
//
// Warum: Der Server kommt nicht an die IP des Senders im Lager-Netz. Also fragt die Bruecke hier
// regelmaessig nach ("gibt es was zu leuchten?"), ruft den Sender im Lager selbst auf und meldet
// das Ergebnis zurueck. Sie braucht dafuer keine eigene Logik - sie bekommt fertige URLs.
//
//   GET  bruecke.php?token=...                 -> {"befehle":[{"id":12,"url":"http://.../light?code=..."}]}
//   POST bruecke.php?token=...  id, ok, antwort -> {"ok":true}
//
// Schutz: geheimer Schluessel (lg_meta bruecke_token), zu sehen unter "Sender und Bruecke".
require_once __DIR__ . '/../../lager/core/config.php';
require_once __DIR__ . '/../../lager/core/db.php';
require_once __DIR__ . '/../../lager/core/schema.php';
require_once __DIR__ . '/../../lager/core/ui.php';
require_once __DIR__ . '/../../lager/core/led.php';
require_once __DIR__ . '/../../lager/core/leiste.php';       // leiste_fuer_charge (Blinker aufs Etikett)
require_once __DIR__ . '/../../lager/core/kiste.php';        // blinker_fuer_charge (Blinker aufs Etikett)
require_once __DIR__ . '/../../lager/core/erp.php';          // erp_charge_voll fuers Etikett
require_once __DIR__ . '/../../lager/core/etikett_pdf.php';  // lg_etikett_pdf fuer Druckjobs

lg_schema();

$token = (string)($_GET['token'] ?? '');
if ($token === '' || !hash_equals(lg_bruecke_token(), $token)) json_antwort(['ok' => false, 'meldung' => 'Schlüssel falsch.'], 403);

// Lebenszeichen - daran sieht man im Lager-Programm, ob die Bruecke laeuft.
lg_meta_schreiben('bruecke_zuletzt', jetzt_utc());
if (!empty($_SERVER['HTTP_USER_AGENT'])) lg_meta_schreiben('bruecke_programm', mb_substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 120));
// Woher kommt der Kontakt? (Damit man erkennt, falls eine fremde/andere Maschine pollt.)
$bip = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
if ($bip !== '') lg_meta_schreiben('bruecke_ip', mb_substr(trim(explode(',', $bip)[0]), 0, 60));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Die Bruecke meldet beim Start die installierten Drucker -> fuer die Auswahl in den Einstellungen.
    if (isset($_POST['printers'])) {
        lg_meta_schreiben('drucker_liste', mb_substr((string)$_POST['printers'], 0, 3000));
        lg_meta_schreiben('drucker_standard', mb_substr((string)($_POST['standard'] ?? ''), 0, 190));
        json_antwort(['ok' => true]);
    }
    $ok = (string)($_POST['ok'] ?? '') === '1';
    $antwort = mb_substr((string)($_POST['antwort'] ?? ''), 0, 500);
    if (isset($_POST['druck_id'])) {                 // Rueckmeldung eines Druckjobs
        q("UPDATE lg_druckjob SET status=?, antwort=?, erledigt=? WHERE id=? AND status='abgeholt'",
          [$ok ? 'ok' : 'fehler', $antwort, jetzt_utc(), (int)$_POST['druck_id']]);
    } else {                                          // Rueckmeldung eines Blinker-Befehls
        q("UPDATE lg_befehl SET status=?, antwort=?, erledigt=? WHERE id=? AND status='abgeholt'",
          [$ok ? 'ok' : 'fehler', $antwort, jetzt_utc(), (int)($_POST['id'] ?? 0)]);
    }
    json_antwort(['ok' => true]);
}

// Was zu lange liegt, wird nicht mehr ausgeliefert (sonst leuchtet nach einem Ausfall alles auf).
$grenze = gmdate('Y-m-d H:i:s', time() - LG_BEFEHL_VERFALL_SEK);
q("UPDATE lg_befehl b JOIN lg_sender s ON s.id = b.sender_id
   SET b.status='verfallen', b.erledigt=? WHERE b.status='offen' AND s.weg='bruecke' AND b.angelegt < ?",
  [jetzt_utc(), $grenze]);

$befehle = [];
foreach (all("SELECT b.id, b.code, s.ip FROM lg_befehl b JOIN lg_sender s ON s.id = b.sender_id
              WHERE b.status='offen' AND s.weg='bruecke' ORDER BY b.id LIMIT 50") as $b) {
    // Nur ausliefern, wenn WIR ihn abholen - laufen versehentlich zwei Bruecken, leuchtet nichts doppelt.
    if (q("UPDATE lg_befehl SET status='abgeholt' WHERE id=? AND status='offen'", [(int)$b['id']])->rowCount() === 0) continue;
    if (trim((string)$b['ip']) === '') {
        q("UPDATE lg_befehl SET status='fehler', antwort='Keine IP beim Sender', erledigt=? WHERE id=?", [jetzt_utc(), (int)$b['id']]);
        continue;
    }
    $befehle[] = ['id' => (int)$b['id'], 'url' => led_lan_url((string)$b['ip'], (string)$b['code'])];
}

// --- Druckjobs: Etiketten, die der Lager-PC lautlos drucken soll (SumatraPDF) ----------------
q("UPDATE lg_druckjob SET status='verfallen', erledigt=? WHERE status='offen' AND angelegt < ?",
  [jetzt_utc(), gmdate('Y-m-d H:i:s', time() - 600)]);   // nach 10 min nicht mehr drucken
$druck = [];
$drucker = lg_meta_lesen('drucker_name', '');            // leer = Standarddrucker
foreach (all("SELECT id, ids, format FROM lg_druckjob WHERE status='offen' ORDER BY id LIMIT 10") as $j) {
    if (q("UPDATE lg_druckjob SET status='abgeholt' WHERE id=? AND status='offen'", [(int)$j['id']])->rowCount() === 0) continue;
    $pdf = lg_etikett_pdf(explode(',', (string)$j['ids']), (string)$j['format']);
    if ($pdf === null) { q("UPDATE lg_druckjob SET status='fehler', antwort='Charge nicht gefunden', erledigt=? WHERE id=?", [jetzt_utc(), (int)$j['id']]); continue; }
    $druck[] = ['id' => (int)$j['id'], 'pdf_b64' => base64_encode($pdf), 'drucker' => $drucker];
}

json_antwort(['befehle' => $befehle, 'druck' => $druck]);
