<?php
// Fastaction – kurze, wichtige Nachricht (oft eine Kundenanfrage) reinwerfen; Claude fasst zusammen,
// schlaegt konkrete naechste Schritte im ERP vor und erkennt Kunde/Rezeptur/Produkt/Menge. Die Seite
// legt daraus automatisch eine Aufgabe an (nichts geht verloren) und zeigt die Vorschlaege.
require_once __DIR__ . '/ki.php';

function fastaction_prompt(): string {
    return <<<TXT
Du bist Assistent im ERP eines Nahrungsergaenzungs-Lohnherstellers (Marke bulkify). Ein Mitarbeiter wirft
dir eine kurze, aber wichtige Nachricht rein – oft eine Kundenanfrage (z. B. eine Nachbestellung oder die
Bitte um ein Angebot), manchmal mit angehaengter Datei/Bild. Analysiere die Nachricht und schlage konkrete
naechste Schritte im ERP vor.

Gib AUSSCHLIESSLICH dieses JSON zurueck:
{
  "zusammenfassung": "1 kurzer Satz, worum es geht",
  "aufgabe": "was das Team konkret tun muss, als Imperativ in 1 Satz",
  "dringlichkeit": "hoch|mittel|niedrig",
  "erkannt": { "kunde": null, "rezeptur": null, "produkt": null, "menge": null, "einheit": null },
  "vorschlaege": [
    { "text": "konkreter Handlungsvorschlag als Frage, z. B. 'Fuer Kunde X und Rezeptur Y ein Angebot ueber 1.000 Dosen anlegen und an den Kunden senden?'", "typ": "angebot|anfrage|nachricht|bestellung|produktion|sonstiges" }
  ]
}

Regeln:
- Nichts erfinden. Erkannte Namen/Mengen NUR uebernehmen, wenn sie in der Nachricht/Datei stehen; sonst null.
- "menge" als Zahl (ohne Tausenderpunkt), "einheit" z. B. "Dosen", "Stueck", "kg".
- 1 bis 3 Vorschlaege, der wichtigste zuerst. Alles auf Deutsch.
- Wenn unklar, "dringlichkeit" auf "mittel" und einen Vorschlag "beim Kunden nachfragen".
TXT;
}

// Analysiert Text (+ optionale Datei). Rueckgabe: ['ok'=>true,'daten'=>[...]] oder ['ok'=>false,'fehler'=>'...'].
function fastaction_analyse(string $text, ?string $pfad = null): array {
    if (!ki_bereit()) return ['ok' => false, 'fehler' => 'Die KI ist nur auf beta verfuegbar (Schluessel serverseitig).'];
    $text = trim($text);
    $opt = ['json' => true, 'denken' => true, 'max_tokens' => 2000, 'timeout' => 180, 'zweck' => 'fastaction'];
    if ($pfad !== null && is_file($pfad)) {
        $anw = fastaction_prompt() . "\n\nZusaetzlicher Text vom Mitarbeiter:\n" . ($text !== '' ? $text : '(kein Text – bitte die Datei auswerten)');
        $r = ki_datei_frage($pfad, $anw, $opt);
    } else {
        if ($text === '') return ['ok' => false, 'fehler' => 'Bitte einen Text eingeben oder eine Datei hochladen.'];
        $r = ki_json($text, ['system' => fastaction_prompt()] + $opt);
    }
    if (!$r['ok']) return ['ok' => false, 'fehler' => $r['fehler'] ?? 'Die KI konnte die Nachricht nicht auswerten.'];
    $d = $r['daten'] ?? null;
    if (!is_array($d)) return ['ok' => false, 'fehler' => 'Unerwartete Antwort der KI.'];
    return ['ok' => true, 'daten' => $d, 'usage' => $r['usage'] ?? [], 'modell' => $r['modell'] ?? ''];
}

// Erkannte Namen zu echten Datensaetzen aufloesen (fuer Direkt-Links). Gibt ['kunde'=>?,'rezeptur'=>?,'produkt'=>?].
function fastaction_aufloesen(array $erkannt): array {
    $res = ['kunde' => null, 'rezeptur' => null, 'produkt' => null];
    $kn = trim((string)($erkannt['kunde'] ?? ''));
    if ($kn !== '') $res['kunde'] = one("SELECT id, firma FROM kunden WHERE firma LIKE ? ORDER BY (LOWER(firma)=LOWER(?)) DESC, LENGTH(firma) LIMIT 1", ['%' . $kn . '%', $kn]);
    $rn = trim((string)($erkannt['rezeptur'] ?? ''));
    if ($rn !== '') $res['rezeptur'] = one("SELECT id, name FROM rezeptur WHERE name LIKE ? ORDER BY (LOWER(name)=LOWER(?)) DESC, LENGTH(name) LIMIT 1", ['%' . $rn . '%', $rn]);
    $pn = trim((string)($erkannt['produkt'] ?? ''));
    if ($pn !== '') $res['produkt'] = one("SELECT id, name FROM produkt WHERE name LIKE ? ORDER BY (LOWER(name)=LOWER(?)) DESC, LENGTH(name) LIMIT 1", ['%' . $pn . '%', $pn]);
    return $res;
}

// Dringlichkeit -> Prioritaet (1=hoch,2=mittel,3=niedrig).
function fastaction_prio(string $dringlichkeit): int {
    return ['hoch' => 1, 'mittel' => 2, 'niedrig' => 3][strtolower(trim($dringlichkeit))] ?? 2;
}
