# ki.php – Anbindung an die Anthropic-API (Claude)

## Wozu
Die Grundlage für alle KI-Funktionen im Dashboard. Ruft die Anthropic-API direkt über curl auf – **ohne Composer**, genau wie der Mailversand über einen Socket läuft. So ändert sich am Deploy nichts: Datei hochladen, fertig.

## Schlüssel
Der Schlüssel steht in `secrets.php` im Projektstamm (gitignored, wird nie committet und nicht deployt):

```php
define('ANTHROPIC_API_KEY', 'sk-ant-...');
```

Akzeptiert werden auch die Namen `ANTHROPIC_KEY` und `AI_API_KEY` sowie die Umgebungsvariable `ANTHROPIC_API_KEY`. Der Schlüssel wird **nirgends geloggt oder angezeigt** – die Oberfläche sieht über `ki_status()` nur, ob er da ist und wie er endet.

## Funktionen
- `ki_bereit()` – ist die KI einsatzbereit (Schlüssel vorhanden, curl da)? Steuert Knöpfe und Hinweise.
- `ki_status()` – für die Einstellungen: bereit, Quelle, letzte vier Zeichen, Modell.
- `ki_frage($inhalt, $opt)` – eine Frage an Claude. Rückgabe `['ok'=>true,'text'=>…,'usage'=>['ein'=>…,'aus'=>…],'ms'=>…]` oder `['ok'=>false,'fehler'=>'Klartext']`. **Wirft nie** – ein Fehler darf keinen Vorgang abbrechen.
- `ki_json($inhalt, $opt)` – dasselbe, erwartet aber JSON zurück und liefert es unter `daten`. Schneidet Codeblöcke und Begleitsätze weg.
- `ki_log()` – schreibt jede Anfrage nach `data/ki.log` (Zweck, Modell, Dauer, Tokens, gekürzte Antwort).

`$inhalt` ist der Text der Nutzer-Nachricht oder ein fertiges content-Array (z. B. Text plus Dokument).

**Optionen:** `system` (Rolle/Regeln), `modell` (Standard `KI_MODELL`), `max_tokens` (Standard 8000), `denken` (true = adaptives Nachdenken, für alles Kniffligere), `aufwand` (`low`…`max`, Standard hoch), `timeout` (Standard 180 s), `versuche` (Standard 3), `zweck` (nur fürs Log).

## Modelle
- `KI_MODELL` = `claude-opus-5` – Standard für alles.
- `KI_MODELL_SCHNELL` = `claude-haiku-4-5` – nur für einfache Arbeiten (Übersetzen, Umformulieren), muss je Aufruf ausdrücklich gewählt werden.

Beide lassen sich in `secrets.php` überschreiben, falls auf dem Server etwas anderes laufen soll.

## Robustheit
- Bei Überlast (429) oder Serverfehlern wird bis zu dreimal versucht, mit wachsender Pause. Bei 400/401/403 sofort Schluss – ein zweiter Versuch ändert daran nichts.
- Lehnt die KI eine Anfrage ab (`stop_reason: refusal`), kommt das als klarer Fehler zurück, nicht als leere Antwort.

## Beispiel
```php
require_once BX_ROOT . '/core/ki.php';
$r = ki_json('Lies aus diesem CoA die Analysewerte …', [
    'system' => 'Du bist Qualitätsprüfer bei einem Nahrungsergänzungs-Hersteller.',
    'denken' => true,
    'zweck'  => 'coa-lesen',
]);
if ($r['ok']) { /* $r['daten'] verwenden */ } else { /* $r['fehler'] anzeigen */ }
```

## Dateien
`ki_datei_block($pfad)` macht aus einem PDF oder Bild einen Inhaltsblock (PDF als Dokument, Bild als Bild, bis 25 MB). `ki_datei_frage($pfad, $anweisung, $opt)` schickt Datei plus Anweisung; mit `'json' => true` kommt die Antwort geparst zurück. **PDFs gehen als Dokument an die API – damit werden auch Scans gelesen**, an denen `core/pdf_text.php` scheitert.

## Was hier NICHT hingehört
Fachlogik. `ki.php` kennt nur die API. Was gefragt wird, steht in der jeweiligen Funktion (z. B. `core/coa_lesen.php`).
