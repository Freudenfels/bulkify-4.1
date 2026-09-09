<?php
// Website-Eingang: bulkify.pro-Anfrageformular -> CRM-Kontakt/Lead. Token-gesichert, KEIN Login.
// Wie v3 lead_intake.php: die Mail der Webseite ist fuehrend; hier darf nie etwas den Absender
// stoeren - jeder interne Fehler wird still quittiert (200 ok:false), nur ein falscher Token
// blockt (403). Direkt erreichbar unter <host>/crm/lead_intake.php (wie public/ds_api.php).
require_once __DIR__ . '/../../crm/core/config.php';
require_once __DIR__ . '/../../crm/core/db.php';
require_once __DIR__ . '/../../crm/core/schema.php';
require_once __DIR__ . '/../../crm/core/ui.php';        // crm_quellen()
require_once __DIR__ . '/../../crm/core/kontakt.php';   // kontakt_anlegen(), kontakt_verlauf()

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function li_out(int $code, array $a): void { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') li_out(405, ['ok' => false, 'error' => 'method', 'message' => 'POST erwartet.']);

crm_schema();   // crm_-Tabellen sicherstellen (idempotent)

// Eingaben: JSON-Body ODER klassisch form-encoded.
$raw  = file_get_contents('php://input');
$json = [];
if ($raw !== '' && stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
    $tmp = json_decode($raw, true);
    if (is_array($tmp)) $json = $tmp;
}
$d   = $json ?: $_POST;
$get = fn($k) => trim((string)($d[$k] ?? ''));

// Token pruefen (POST-Feld, Header X-Intake-Token, oder JSON-Feld "token").
$token = lead_intake_token();
$given = (string)($_POST['token'] ?? ($_SERVER['HTTP_X_INTAKE_TOKEN'] ?? ''));
if ($given === '') $given = (string)($d['token'] ?? '');
if ($token === '' || $given === '' || !hash_equals($token, $given)) {
    li_out(403, ['ok' => false, 'error' => 'auth', 'message' => 'Ungueltiger oder fehlender Token.']);
}

$name     = $get('name');
$firma    = $get('firma');
$email    = $get('email');
$telefon  = $get('telefon');
$whatsapp = $get('whatsapp');
if ($name === '' && $email === '' && $telefon === '') {
    li_out(200, ['ok' => false, 'error' => 'leer', 'message' => 'Weder Name noch E-Mail noch Telefon uebergeben.']);
}
// Ohne Namen behelfen wir uns mit Firma/E-Mail, damit die Liste nie leer aussieht.
if ($name === '') $name = $firma !== '' ? $firma : ($email !== '' ? $email : 'Website-Anfrage');

// Lesbarer Anfragetext (wie in der Website-Mail) - reine Freitext-Felder, nichts wird interpretiert.
$labels = ['anliegen' => 'Vorhaben', 'ziel' => 'Produktziel', 'produktform' => 'Produktform',
           'menge' => 'Wunschmenge', 'support' => 'Verkauf-Hilfe', 'vertrieb' => 'Vertriebskanal',
           'erfahrung' => 'Erfahrung', 'rohstoffe' => 'Rohstoffe'];
$zeilen = [];
foreach ($labels as $k => $lbl) { $v = $get($k); if ($v !== '') $zeilen[] = $lbl . ': ' . $v; }
foreach (['wirkstoffe' => 'Wirkstoff-Idee', 'rezeptur' => 'Rezeptur (Wunsch)',
          'rohstoff_extra' => 'Spezifikationen', 'nachricht' => 'Nachricht'] as $k => $lbl) {
    $v = $get($k); if ($v !== '') $zeilen[] = "\n" . $lbl . ":\n" . $v;
}
$text = trim(implode("\n", $zeilen));
if ($text === '') $text = 'Anfrage über die Website (keine weiteren Angaben).';

try {
    // Leichter Dublettencheck: gleiche E-Mail oder gleiche Telefonnummer -> an bestehenden Kontakt
    // haengen statt einen zweiten anzulegen (mehrfaches Absenden erzeugt keine Karteileichen).
    $dup = null;
    if ($email !== '') $dup = one("SELECT id FROM crm_kontakt WHERE email=? ORDER BY id LIMIT 1", [$email]);
    if (!$dup && $telefon !== '') {
        $tel = preg_replace('/\D+/', '', $telefon);
        if ($tel !== '' && strlen((string)$tel) >= 5)
            $dup = one("SELECT id FROM crm_kontakt WHERE REPLACE(REPLACE(REPLACE(REPLACE(telefon,' ',''),'-',''),'/',''),'(','') LIKE ? ORDER BY id LIMIT 1", ['%' . $tel]);
    }
    if ($dup) {
        $kid = (int)$dup['id'];
        kontakt_verlauf($kid, 'notiz', "Neue Anfrage über die Website:\n" . $text, 0);
        li_out(200, ['ok' => true, 'kontakt_id' => $kid, 'duplicate' => true]);
    }
    $kid = kontakt_anlegen([
        'name' => $name, 'firma' => $firma, 'email' => $email, 'telefon' => $telefon,
        'whatsapp' => $whatsapp, 'quelle' => 'website', 'notiz' => $text,
    ], 0);
    li_out(200, ['ok' => true, 'kontakt_id' => $kid, 'duplicate' => false]);
} catch (Throwable $e) {
    // Nie den Absender stoeren (die Website-Mail ist fuehrend). Fehler still quittieren.
    li_out(200, ['ok' => false, 'error' => 'intern', 'message' => 'Nicht gespeichert.']);
}
