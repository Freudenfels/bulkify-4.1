<?php
// Aus einem hingeworfenen Text einen Kontakt machen.
//
// Der Fall: Du bekommst eine WhatsApp-Nachricht, teilst sie in bulkify - und statt sechs Felder zu
// tippen, liest die KI heraus, wer schreibt, was er will und bis wann.
//
// Wichtig: Gespeichert wird NICHTS automatisch. Die KI fuellt nur das Formular aus, du prueft und
// drueckst auf Speichern. Bei einer falsch verstandenen Nachricht steht sonst Unsinn in den Daten.
require_once __DIR__ . '/ki.php';
require_once __DIR__ . '/ui.php';

function kontakt_ki_anweisung(): string {
    $quellen = implode('|', array_keys(crm_quellen()));
    return <<<TXT
Du liest eine hereingekommene Nachricht (WhatsApp, E-Mail, Notizzettel von einer Messe) und traegst
heraus, was fuer einen Vertriebskontakt wichtig ist. Wir sind ein Lohnhersteller fuer
Nahrungsergaenzungsmittel; es geht fast immer um Produkte, Mengen, Preise und Termine.

Antworte NUR mit diesem JSON, ohne Text drumherum:
{
  "name": "",           // Person, die schreibt. Wenn unbekannt: leer lassen, NICHT erfinden.
  "firma": "",          // Firma, wenn genannt
  "email": "",
  "telefon": "",
  "wunsch": "",         // ein bis zwei Saetze: was will derjenige? Menge und Produkt nennen.
  "wert_eur": null,     // grober Auftragswert in Euro, wenn er sich ableiten laesst - sonst null
  "frist_tage": null,   // in wie vielen Tagen sollte nachgefasst werden? Nennt die Nachricht eine
                        // Frist ("bis Freitag"), rechne sie in Tage um. Sonst null.
  "quelle": "",         // eines von: $quellen
  "sprache": "de"       // Sprache der Nachricht (de, en, zh ...)
}

Regeln:
- Nichts erfinden. Was nicht dasteht, bleibt leer bzw. null.
- "wunsch" in ganzen Saetzen und auf Deutsch, auch wenn die Nachricht englisch ist.
- Zahlen als Zahl, ohne Punkt und ohne Waehrungszeichen.
TXT;
}

// Rueckgabe: ['ok'=>bool, 'daten'=>array, 'fehler'=>string]
function kontakt_ki_lesen(string $text): array {
    $text = trim($text);
    if ($text === '')   return ['ok' => false, 'daten' => [], 'fehler' => 'Kein Text da.'];
    if (!ki_bereit())   return ['ok' => false, 'daten' => [], 'fehler' => 'Die KI ist nicht eingerichtet.'];

    $r = ki_json(kontakt_ki_anweisung() . "\n\n--- Nachricht ---\n" . mb_substr($text, 0, 8000), [
        'zweck'      => 'kontakt/lesen',
        'modell'     => KI_MODELL_SCHNELL,   // kurze Nachricht, einfache Aufgabe - das reicht
        'max_tokens' => 1200,
        'timeout'    => 60,
        'budget'     => 90,
    ]);
    if (empty($r['ok']))            return ['ok' => false, 'daten' => [], 'fehler' => (string)($r['fehler'] ?? 'Fehler')];
    if (!is_array($r['daten'] ?? null)) return ['ok' => false, 'daten' => [], 'fehler' => 'Die Antwort war nicht lesbar.'];

    return kontakt_ki_saeubern($r['daten']);
}

// Aus der Antwort der KI verlaessliche Felder machen: kappen, pruefen, Unsinn aussortieren.
// Text und Bild laufen beide hier durch.
function kontakt_ki_saeubern(array $d): array {
    $quellen = crm_quellen();
    $sauber = [
        'name'    => mb_substr(trim((string)($d['name'] ?? '')), 0, 190),
        'firma'   => mb_substr(trim((string)($d['firma'] ?? '')), 0, 190),
        'email'   => mb_substr(trim((string)($d['email'] ?? '')), 0, 190),
        'telefon' => mb_substr(trim((string)($d['telefon'] ?? '')), 0, 60),
        'wunsch'  => trim((string)($d['wunsch'] ?? '')),
        'wert'    => is_numeric($d['wert_eur'] ?? null) ? (float)$d['wert_eur'] : null,
        'tage'    => is_numeric($d['frist_tage'] ?? null) ? max(0, min(90, (int)$d['frist_tage'])) : null,
        'quelle'  => array_key_exists((string)($d['quelle'] ?? ''), $quellen) ? (string)$d['quelle'] : '',
    ];
    // Ohne Namen ist es kein Kontakt, sondern eine Notiz. Dann lieber ehrlich sagen, was fehlt.
    if ($sauber['name'] === '' && $sauber['firma'] === '') {
        return ['ok' => true, 'daten' => $sauber,
                'fehler' => 'Name und Firma standen nicht in der Nachricht - bitte selbst eintragen.'];
    }
    return ['ok' => true, 'daten' => $sauber, 'fehler' => ''];
}

// --- Visitenkarte oder Foto -------------------------------------------------------------------
// Auf der Messe: Karte abfotografieren, teilen, fertig. Dieselbe Anweisung wie beim Text, nur geht
// zusaetzlich das Bild mit - die KI liest Name, Firma, Telefon und Mail direkt von der Karte ab.
function kontakt_ki_bild(string $pfad): array {
    if (!is_file($pfad)) return ['ok' => false, 'daten' => [], 'fehler' => 'Bild nicht gefunden.'];
    if (!ki_bereit())    return ['ok' => false, 'daten' => [], 'fehler' => 'Die KI ist nicht eingerichtet.'];

    $r = ki_datei_frage($pfad, kontakt_ki_anweisung()
        . "\n\nDas Bild ist meist eine Visitenkarte oder ein Foto einer Nachricht. Lies alles ab, was\n"
        . "darauf steht. Steht kein Wunsch drauf, lass \"wunsch\" leer - eine Visitenkarte allein sagt\n"
        . "noch nicht, was jemand will.", [
        'zweck'      => 'kontakt/bild',
        'max_tokens' => 1200,
        'timeout'    => 90,
        'budget'     => 150,
    ]);
    if (empty($r['ok'])) return ['ok' => false, 'daten' => [], 'fehler' => (string)($r['fehler'] ?? 'Fehler')];

    // ki_datei_frage liefert Text - das JSON muss hier herausgeschnitten werden.
    $text = (string)($r['text'] ?? '');
    $a = strpos($text, '{'); $b = strrpos($text, '}');
    $d = ($a !== false && $b !== false && $b > $a) ? json_decode(substr($text, $a, $b - $a + 1), true) : null;
    if (!is_array($d)) return ['ok' => false, 'daten' => [], 'fehler' => 'Die Antwort war nicht lesbar.'];

    return kontakt_ki_saeubern($d);
}

// --- Hilfen fuer die Erfassen-Seite ------------------------------------------------------------
// Hochgeladenes Bild pruefen, kurz ablegen, auslesen, wieder loeschen. Die Datei wird nicht
// aufbewahrt: Es geht um die Daten darauf, nicht um das Bild.
function erfassen_bild_lesen(): array {
    $f = null;
    foreach (['bild', 'datei'] as $feld) {
        if (!empty($_FILES[$feld]['name']) && ($_FILES[$feld]['error'] ?? 1) === UPLOAD_ERR_OK) { $f = $_FILES[$feld]; break; }
    }
    if (!$f) return ['ok' => false, 'daten' => [], 'fehler' => 'Kein Bild angekommen.'];
    if ((int)$f['size'] > 12 * 1024 * 1024) return ['ok' => false, 'daten' => [], 'fehler' => 'Das Bild ist groesser als 12 MB.'];

    $ext = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo((string)$f['name'], PATHINFO_EXTENSION)));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'], true))
        return ['ok' => false, 'daten' => [], 'fehler' => 'Nur Bilder oder PDF.'];

    $ordner = BX_ROOT . '/data';
    if (!is_dir($ordner)) @mkdir($ordner, 0775, true);
    $ziel = $ordner . '/karte_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $ziel))
        return ['ok' => false, 'daten' => [], 'fehler' => 'Das Bild konnte nicht abgelegt werden.'];

    $r = kontakt_ki_bild($ziel);
    @unlink($ziel);
    return $r;
}

// Was die KI gefunden hat, in das Formular uebernehmen - aber nur, wo sie etwas gefunden hat.
// Selbst Getipptes wird nie ueberschrieben, ausser das Feld war leer.
function erfassen_uebernehmen(array $vor, array $d): array {
    if (($d['name'] ?? '') !== '')    $vor['name']    = $d['name'];
    if (($d['firma'] ?? '') !== '')   $vor['firma']   = $d['firma'];
    if (($d['email'] ?? '') !== '')   $vor['email']   = $d['email'];
    if (($d['telefon'] ?? '') !== '') $vor['telefon'] = $d['telefon'];
    if (($d['quelle'] ?? '') !== '')  $vor['quelle']  = $d['quelle'];
    if (($d['wert'] ?? null) !== null) $vor['wert']   = (string)$d['wert'];
    if (($d['tage'] ?? null) !== null) $vor['erinnern'] = (string)max(1, (int)$d['tage']);
    if (($d['wunsch'] ?? '') !== '') {
        $vor['notiz'] = trim($vor['notiz']) === ''
            ? $d['wunsch']
            : $d['wunsch'] . "\n\n--- Original ---\n" . $vor['notiz'];
    }
    return $vor;
}
