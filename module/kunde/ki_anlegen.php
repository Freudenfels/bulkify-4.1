<?php
// Neuen Kunden per KI aus einem eingefügten Text (Adressblock/Impressum/Signatur) vorbereiten.
// Die KI zerlegt den Text in Stammdaten-Felder; danach landet man im normalen Neuanlage-Formular
// (?p=kunde&id=neu) mit vorbefüllten Feldern und prüft/speichert dort. So bleibt die Kontrolle beim Team.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['aktion'] ?? '') !== 'ki_kunde') {
    header('Location: ?p=kunden'); exit;
}
$text = trim((string)($_POST['text'] ?? ''));
if ($text === '') { header('Location: ?p=kunden&kifehler=' . urlencode('Bitte einen Text einfügen.')); exit; }

require_once BX_ROOT . '/core/ki.php';
if (!ki_bereit()) {
    // Ohne KI: Text als Notiz mitgeben, Rest füllt das Team von Hand.
    $_SESSION['kunde_ki'] = ['notiz' => $text, '_hinweis' => 'KI ist nicht eingerichtet – bitte Felder von Hand ausfüllen.'];
    header('Location: ?p=kunde&id=neu&ki=1'); exit;
}

$system = "Du bist die Stammdaten-Assistenz eines Lohnherstellers. Aus einem eingefügten Text (Adressblock, "
    . "Impressum, E-Mail-Signatur o. ä.) ziehst du die Kundendaten. Gib NUR JSON zurück, exakt in dieser Form:\n"
    . '{"firma":"","ansprechpartner":"","email":"","telefon":"","strasse":"","hausnummer":"","plz":"","ort":"","land":"","ust_id":"","notiz":""}' . "\n"
    . "Regeln:\n"
    . "- firma = Firmenname (ohne Adresse).\n"
    . "- Deutsche/österreichische Adresse: Straße in \"strasse\", Hausnummer getrennt in \"hausnummer\". "
    . "Bei anderen Ländern (z. B. Hongkong, UK, US) KEINE Hausnummer abtrennen – die komplette Straßen-/Gebäude-/Etagen-Zeile(n) in \"strasse\" (mehrere Zeilen mit Komma verbinden), \"hausnummer\" leer lassen.\n"
    . "- land = ISO-2-Ländercode (DE, AT, CH, HK, GB, US …), aus dem Text ableiten; wenn unklar, leer lassen.\n"
    . "- plz und ort nur, wenn eindeutig erkennbar.\n"
    . "- ust_id NUR für eine echte Umsatzsteuer-/VAT-ID (EU-USt-IdNr., VAT-Nummer). "
    . "Andere Registernummern (z. B. BRN, Business Registration Number, Company No., HRB) NICHT hier, sondern unverändert in \"notiz\".\n"
    . "- notiz = alles Wichtige, das in kein Feld passt (Registernummern, Zusätze), knapp. Sonst leer.\n"
    . "- Nichts erfinden: unbekannte Felder leer lassen.";
$r = ki_json("Text:\n\n" . $text, ['system' => $system, 'max_tokens' => 1500, 'zweck' => 'kunde_anlegen']);
if (empty($r['ok'])) {
    $_SESSION['kunde_ki'] = ['notiz' => $text, '_hinweis' => 'KI-Analyse fehlgeschlagen (' . ($r['fehler'] ?? 'unbekannt') . ') – bitte Felder von Hand ausfüllen.'];
    header('Location: ?p=kunde&id=neu&ki=1'); exit;
}
$d = is_array($r['daten'] ?? null) ? $r['daten'] : [];
$clean = fn($k) => trim((string)($d[$k] ?? ''));
$land = strtoupper(preg_replace('/[^A-Za-z]/', '', $clean('land')));
$pre = [
    'firma'          => $clean('firma'),
    'ansprechpartner'=> $clean('ansprechpartner'),
    'email'          => $clean('email'),
    'telefon'        => $clean('telefon'),
    'strasse'        => $clean('strasse'),
    'hausnummer'     => $clean('hausnummer'),
    'plz'            => $clean('plz'),
    'ort'            => $clean('ort'),
    'land'           => (strlen($land) === 2 ? $land : ''),
    'ust_id'         => $clean('ust_id'),
    'notiz'          => $clean('notiz'),
];
$pre['_hinweis'] = 'Aus Text übernommen – bitte prüfen und ergänzen, dann speichern.';
$_SESSION['kunde_ki'] = $pre;
header('Location: ?p=kunde&id=neu&ki=1'); exit;
