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

lg_schema();

$token = (string)($_GET['token'] ?? '');
if ($token === '' || !hash_equals(lg_bruecke_token(), $token)) json_antwort(['ok' => false, 'meldung' => 'Schlüssel falsch.'], 403);

// Lebenszeichen - daran sieht man im Lager-Programm, ob die Bruecke laeuft.
lg_meta_schreiben('bruecke_zuletzt', jetzt_utc());
if (!empty($_SERVER['HTTP_USER_AGENT'])) lg_meta_schreiben('bruecke_programm', mb_substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 120));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $ok = (string)($_POST['ok'] ?? '') === '1';
    q("UPDATE lg_befehl SET status=?, antwort=?, erledigt=? WHERE id=? AND status='abgeholt'",
      [$ok ? 'ok' : 'fehler', mb_substr((string)($_POST['antwort'] ?? ''), 0, 500), jetzt_utc(), $id]);
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
json_antwort(['befehle' => $befehle]);
