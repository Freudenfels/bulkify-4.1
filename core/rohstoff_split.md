# core/rohstoff_split.php – Rohstoffe aufschlüsseln

Zerlegt zu lange Rohstoffnamen, in denen mehrere Varianten in EINEM Feld stecken
(verschiedene Molekulargewichte, Trägeröle, Qualitäten, Formen), in einzelne Rohstoffe.

## Funktionen
- `rohstoff_split_ki_one($item)` – KI schlägt für einen Namen eine Variantenliste vor
  (`{basis, varianten[]}`). Nur Vorbefüllung; läuft auf beta (`ki_bereit()`).
- `rohstoff_split_ki_batch($limit)` – für die nächsten langen Rohstoffe ohne Vorschlag
  einen KI-Vorschlag erzeugen und in `rohstoff_variante_vorschlag` ablegen.
- `rohstoff_split_anwenden($item_id, $varianten)` – die (vom Menschen geprüfte) Liste
  übernehmen: **Original-Datensatz wird zur 1. Variante** (umbenannt, Original-Name in die
  Notiz), für jede weitere Variante entsteht ein **neuer Rohstoff** (R-Nummer, Form/Einheit/
  Herkunft vom Original geerbt, EK 0). Referenzen (Rezepturen) bleiben am Original hängen.
- `rohstoff_split_verwerfen($item_id)` – als „übersprungen" markieren.

Schwelle: `ROHSTOFF_NAME_LANG = 70` Zeichen.

## Wichtig
- Der Mensch prüft/editiert die Varianten **vor** der Übernahme (Textarea, eine pro Zeile).
  Die KI ist nur Vorbefüllung; die manuelle Übernahme funktioniert überall.
- Nach dem Aufschlüsseln ist der Original-Name kurz → fällt aus der „offen"-Liste.
- Idempotenz/Verlauf über `rohstoff_variante_vorschlag` (UNIQUE item_id).
- Achtung: Ein erneuter voller v3-Import würde den (per v3_id verknüpften) Original-Namen
  wieder überschreiben. Die v3-Migration ist einmalig – nach dem Aufschlüsseln nicht erneut
  gegen dieselben Rohstoffe laufen lassen.
