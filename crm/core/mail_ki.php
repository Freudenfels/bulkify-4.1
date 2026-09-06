<?php
// E-Mail einfügen, den Rest macht das Programm.
//
// Der Fall: Eine Mail kommt rein, du markierst sie, kopierst sie und fügst sie hier ein. Die KI
// liest heraus, wer schreibt, was er will und bis wann; das Programm sucht die Person in Kunden
// und Kontakten, und legt danach Notiz und Wiedervorlage an.
//
// Zwei Dinge sind bewusst so gebaut:
//   1. Es wird NICHTS ohne Bestätigung gespeichert. Angezeigt wird vorher, was passieren würde -
//      an wen die Notiz geht und wann erinnert wird. Ein Klick, dann ist es erledigt.
//   2. Nicht jede Mail ist ein Vorgang. Newsletter, Rechnungen und Werbung werden als solche
//      erkannt und ausdrücklich NICHT zu einem Kontakt gemacht. Sonst hat man nach einer Woche
//      dreissig Karteileichen.
require_once __DIR__ . '/ki.php';
require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/dublette.php';

// Was für eine Mail ist das? Steuert, was vorgeschlagen wird.
function mail_ki_arten(): array {
    return [
        'anfrage'     => 'Neue Anfrage',
        'antwort'     => 'Antwort auf unser Angebot',
        'nachfrage'   => 'Frage zu einem laufenden Vorgang',
        'bestellung'  => 'Bestellung',
        'rechnung'    => 'Rechnung oder Buchhaltung',
        'newsletter'  => 'Newsletter oder Werbung',
        'sonstiges'   => 'Sonstiges',
    ];
}

// Bei diesen Arten entsteht kein Kontakt - da will niemand etwas von uns.
function mail_ki_ohne_vorgang(): array { return ['rechnung', 'newsletter']; }

function mail_ki_anweisung(): string {
    $arten = implode('|', array_keys(mail_ki_arten()));
    return <<<TXT
Du liest eine eingegangene E-Mail und trägst heraus, was für den Vertrieb wichtig ist. Wir sind
bulkify, ein Lohnhersteller für Nahrungsergänzungsmittel. Es geht fast immer um Produkte, Mengen,
Preise und Termine.

Die Mail kann Kopfzeilen (Von, An, Betreff), eine Signatur, einen Haftungsausschluss und den
zitierten Verlauf darunter enthalten. Beziehe dich auf die NEUESTE Nachricht; der zitierte Verlauf
ist nur Hintergrund.

Antworte NUR mit diesem JSON, ohne Text drumherum:
{
  "art": "",              // eines von: $arten
  "name": "",             // wer schreibt. Aus Signatur oder Absender. Nicht erfinden.
  "firma": "",
  "email": "",
  "telefon": "",
  "betreff": "",
  "zusammenfassung": "",  // zwei bis drei Sätze auf Deutsch: worum geht es, was will derjenige
  "wunsch": "",           // ein Satz: die konkrete Bitte. Menge und Produkt nennen, wenn genannt.
  "wert_eur": null,       // grober Auftragswert in Euro, wenn ableitbar - sonst null
  "frist_tage": null,     // in wie vielen Tagen nachfassen? Nennt die Mail eine Frist
                          // ("bis Freitag", "diese Woche"), rechne sie in Tage um. Sonst null.
  "antwort_noetig": true, // erwartet derjenige eine Antwort von uns?
  "sprache": "de"         // Sprache der Mail (de, en, zh ...)
}

Regeln:
- Nichts erfinden. Was nicht dasteht, bleibt leer bzw. null.
- Deutsch schreiben, auch wenn die Mail englisch oder chinesisch ist.
- Zahlen als Zahl, ohne Punkt und ohne Währungszeichen.
- Newsletter, Werbung, automatische Benachrichtigungen und Rechnungen ehrlich als solche einordnen,
  auch wenn sie freundlich formuliert sind.
TXT;
}

// Rückgabe: ['ok'=>bool, 'daten'=>array, 'treffer'=>array, 'fehler'=>string]
// 'treffer' sind mögliche bestehende Kunden/Kontakte - gefunden ohne KI, siehe dublette.php.
function mail_ki_lesen(string $text): array {
    $text = trim($text);
    if ($text === '')  return ['ok' => false, 'daten' => [], 'treffer' => [], 'fehler' => 'Kein Text da.'];
    if (!ki_bereit())  return ['ok' => false, 'daten' => [], 'treffer' => [], 'fehler' => 'Die KI ist nicht eingerichtet.'];

    $r = ki_json(mail_ki_anweisung() . "\n\n--- E-Mail ---\n" . mb_substr($text, 0, 20000), [
        'zweck'      => 'mail/lesen',
        'modell'     => KI_MODELL_SCHNELL,
        'max_tokens' => 1600,
        'timeout'    => 90,
        'budget'     => 120,
    ]);
    if (empty($r['ok']))                return ['ok' => false, 'daten' => [], 'treffer' => [], 'fehler' => (string)($r['fehler'] ?? 'Fehler')];
    if (!is_array($r['daten'] ?? null)) return ['ok' => false, 'daten' => [], 'treffer' => [], 'fehler' => 'Die Antwort war nicht lesbar.'];

    $d = $r['daten'];
    $arten = mail_ki_arten();
    $sauber = [
        'art'             => array_key_exists((string)($d['art'] ?? ''), $arten) ? (string)$d['art'] : 'sonstiges',
        'name'            => mb_substr(trim((string)($d['name'] ?? '')), 0, 190),
        'firma'           => mb_substr(trim((string)($d['firma'] ?? '')), 0, 190),
        'email'           => mb_substr(trim((string)($d['email'] ?? '')), 0, 190),
        'telefon'         => mb_substr(trim((string)($d['telefon'] ?? '')), 0, 60),
        'betreff'         => mb_substr(trim((string)($d['betreff'] ?? '')), 0, 190),
        'zusammenfassung' => trim((string)($d['zusammenfassung'] ?? '')),
        'wunsch'          => trim((string)($d['wunsch'] ?? '')),
        'wert'            => is_numeric($d['wert_eur'] ?? null) ? (float)$d['wert_eur'] : null,
        'tage'            => is_numeric($d['frist_tage'] ?? null) ? max(0, min(90, (int)$d['frist_tage'])) : null,
        'antwort_noetig'  => !isset($d['antwort_noetig']) || (bool)$d['antwort_noetig'],
        'sprache'         => mb_substr(trim((string)($d['sprache'] ?? 'de')), 0, 5),
    ];

    // Wer ist das? Ohne KI, rein rechnerisch - Rechtsformen, Schreibweisen, Telefonformate.
    $treffer = ($sauber['name'] !== '' || $sauber['firma'] !== '' || $sauber['email'] !== '')
        ? dublette_suchen($sauber['name'], $sauber['firma'], $sauber['email'], $sauber['telefon'])
        : [];

    return ['ok' => true, 'daten' => $sauber, 'treffer' => $treffer, 'fehler' => ''];
}

// Was würde passieren? Wird VOR dem Speichern angezeigt, damit man es einmal liest.
// Rückgabe: ['ziel_art'=>'kunde'|'kontakt'|'neu', 'ziel_id'=>int, 'ziel_text'=>string,
//            'notiz'=>string, 'tage'=>int, 'anlegen'=>bool]
function mail_ki_plan(array $daten, array $treffer): array {
    $ohneVorgang = in_array($daten['art'], mail_ki_ohne_vorgang(), true);

    // Der beste Treffer gewinnt: ein bestehender Kunde vor einem Kontakt.
    $ziel = null;
    foreach ($treffer as $t) { if ($t['art'] === 'kunde')   { $ziel = $t; break; } }
    if (!$ziel) foreach ($treffer as $t) { if ($t['art'] === 'kontakt') { $ziel = $t; break; } }

    $notiz = trim(($daten['betreff'] !== '' ? 'Betreff: ' . $daten['betreff'] . "\n" : '')
           . ($daten['zusammenfassung'] ?: $daten['wunsch']));

    // Wann erinnern: was in der Mail steht, sonst drei Tage - aber nur, wenn eine Antwort erwartet wird.
    $tage = $daten['tage'];
    if ($tage === null) $tage = $daten['antwort_noetig'] ? 3 : 0;
    if ($ohneVorgang)   $tage = 0;

    return [
        'ziel_art'  => $ziel ? $ziel['art'] : ($ohneVorgang ? 'nichts' : 'neu'),
        'ziel_id'   => $ziel ? (int)$ziel['id'] : 0,
        'ziel_text' => $ziel ? (string)$ziel['text']
                             : trim(($daten['firma'] !== '' ? $daten['firma'] . ' – ' : '') . $daten['name']),
        'grund'     => $ziel ? (string)$ziel['grund'] : '',
        'notiz'     => $notiz,
        'tage'      => (int)$tage,
        'anlegen'   => !$ziel && !$ohneVorgang,
    ];
}
