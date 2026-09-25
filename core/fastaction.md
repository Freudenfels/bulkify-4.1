# core/fastaction.php – Fastaction-Kernlogik (KI)

Prompt + Auswertung für den Schnell-Posteingang (`module/system/fastaction.php`).
- `fastaction_prompt()` – Anweisung an Claude: gibt striktes JSON zurück (zusammenfassung, aufgabe, dringlichkeit, erkannt{kunde,rezeptur,produkt,menge,einheit}, vorschlaege[{text,typ}]). Nichts erfinden, deutsch, 1–3 Vorschläge.
- `fastaction_analyse($text,$pfad=null)` – ruft `ki_json($text,...)` bzw. `ki_datei_frage($pfad,...)` (bei Datei/Bild). Rückgabe `['ok'=>bool,'daten'=>[...],'fehler'=>...]`. Braucht `ki_bereit()` (nur beta).
- `fastaction_aufloesen($erkannt)` – löst erkannte Namen per LIKE zu echten Datensätzen auf (kunde/rezeptur/produkt) für Direkt-Links.
- `fastaction_prio($dringlichkeit)` – hoch=1, mittel=2, niedrig=3.
