# KI-Steckbrief am Rohstoff (`core/rohstoff_steckbrief.php`)

Stand 11.11.2026, Auftrag von Nico. Der Steckbrief ist eine ausführliche, sachliche Übersicht zu einem Rohstoff. Er ist **nur für das Team** gedacht.

## Unterschied zur KI-Info
Die KI-Info (`item.ki_info`) ist kurz und wird dem Kunden im Portal gezeigt. Der Steckbrief ist länger und erscheint **nie** im Kunden- oder Lieferantenportal.

## Inhalt
Die KI schreibt auf Deutsch, in fünf Abschnitten:
- **Was ist das:** Herkunft, Form, übliche Gehalte bzw. Standardisierung.
- **Einsatz in Nahrungsergänzungsmitteln:** typische Einsatzgebiete und typische Kombinationen.
- **Dosierung und Darreichung:** marktübliche Tagesdosis, Kapsel, Tablette oder Pulver.
- **Besonderheiten in der Verarbeitung:** Geschmack, Fließfähigkeit, Hygroskopie, Stabilität.
- **Regulatorische Hinweise:** Novel Food, EU-Health-Claims (nur wenn es einen zugelassenen gibt), Höchstmengen, Warnhinweise.

Regeln für die KI: keine Werbe- und keine Heilversprechen, kein Markdown, normale Bindestriche. Unsichere Angaben markiert sie mit „bitte prüfen“. Die Anzeige trägt immer den Hinweis „KI-generiert, intern, nicht geprüft“.

## Was die KI als Grundlage bekommt
`steckbrief_kontext()` sammelt aus unseren Stammdaten:
- Name, englischer und lateinischer Name, Synonyme, CAS, Beschaffenheit, DEV, Form
- Herkunft, Zusätze, Allergene, Haltbarkeit, Lagerung, vegan/GVO/bestrahlt
- Wirkstoffe mit Gehalt (`item_wirkstoff`)
- Novel-Food-Status aus unserem Abgleich
- zugelassene Health Claims aus der Tabelle `health_claim`, soweit welche zu den Wirkstoffen hinterlegt sind

## Funktionen
- `rohstoff_steckbrief_erzeugen($item_id)`: Fragt die KI über `ki_frage()` (dieselbe Anbindung wie „KI liest aus“, Zweck `rohstoff-steckbrief`). Speichert Text, Datum (UTC) und wer erstellt hat. Eigene Änderungen werden dabei zurückgesetzt.
- `rohstoff_steckbrief_speichern($item_id, $text)`: speichert die eigene Bearbeitung (Stift) mit Datum und Name.
- `rohstoff_steckbrief_block($item_id, $back)`: der Block „Steckbrief“ für interne Seiten.
  - Ohne Text: Knopf „KI-Steckbrief erstellen“.
  - Mit Text: Text, Erstellt- und Bearbeitet-Angabe, Stift (Pop-up mit Textfeld) und „neu erstellen“. „neu erstellen“ fragt vorher nach, weil es überschreibt.
  - Ist die KI nicht eingerichtet, ist der Knopf gesperrt.

## Spalten an `item`
`steckbrief`, `steckbrief_am`, `steckbrief_von`, `steckbrief_bearb_am`, `steckbrief_bearb_von`. Angelegt in `core/schema.php` und zusätzlich best effort in `steckbrief_schema()`.

## Wo eingebunden
- Rohstoffseite (`module/lager/rohstoff_detail.php`), unter der KI-Info
- Kunden-Rohstoffanfrage im Dashboard (`module/intern/portal_anfrage_detail.php`), sobald ein Rohstoff zugeordnet ist
- Lieferanten-Anfrage (`module/einkauf/lief_anfrage_detail.php`), wenn es um einen Rohstoff geht

Gespeichert wird über die Route `?p=rohstoff_steckbrief` (`module/lager/rohstoff_steckbrief.php`). Das Portal liest den Steckbrief an keiner Stelle.
