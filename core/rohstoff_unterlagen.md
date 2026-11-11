# core/rohstoff_unterlagen.php – Unterlagen der Rohstoffe (Spezifikation/CoA) je Anfrage

**Stand 10.11.2026 (Nico: „ein Mitarbeiter soll das sehen, wenn er eine Anfrage bekommt, und gezielt abarbeiten“).**

Als Unterlage zählen Spezifikation, CoA und Analyse des Lieferanten am Rohstoff (`dokument` objekt_typ `item`). Die bulkify-Spezifikation zählt nicht, sie wird nur erzeugt.

- `rohstoff_unterlagen_stand($ids)`: je Rohstoff, ob eine Unterlage vorhanden ist, plus Preisanfragen beim Lieferanten (Anzahl, beantwortet, zuletzt, Lieferanten).
- `rezeptur_anfrage_rohstoff_ids($anfrage)`: zugeordnete Wunschzeilen und Zutaten der daraus erstellten Rezeptur.
- `anfragen_unterlagen_fehlen($rez, $portal)`: Zähler für die Anfragen-Liste (fehlend bzw. davon schon angefragt).
- `rohstoff_unterlagen_panel($ids, $kontext)`: Kasten „Unterlagen der Rohstoffe“.
  - **Zeilen:** Rohstoffe ohne Unterlage. „Noch nicht angefragt“ steht oben und ist vorgehakt, „angefragt, wartet“ und „beantwortet, ohne Unterlage“ folgen darunter.
  - **Knopf:** „Ausgewählte beim Lieferanten anfragen“ öffnet das Anfrage-Popup als Sammelanfrage (Preis je kg + CoA/Spezifikation).
  - **Je Zeile:** Link „Unterlage hochladen“ zum Rohstoff.
  - **Alles vorhanden:** nur eine grüne Zeile.

**Eingesetzt:**
- Anfrage-Seite (`module/anfrage/detail.php`, ganz oben),
- Rezepturseite bei Entwurf/Vorschlag (vor „Rohstoffpreise“),
- Anfragen-Liste (Spalte „Unterlagen“).

## Pop-up „Preis & Unterlagen“ (10.11.2026)
- `rohstoff_erfassen_knopf($item_id, $name)` gibt einen Knopf aus, der das Fenster für diesen Rohstoff öffnet.
- `rohstoff_erfassen_dialog($zurueck)` gibt das Fenster einmal je Seite aus. Darin: Lieferant (Suche), Preis je kg ab Menge, Upload Spec/CoA/Analyse und der Haken „Infos automatisch füllen“. Es sendet an `?p=rohstoff_erfassen`.
- `rohstoff_erfassen_meldung()` zeigt die Rückmeldung nach dem Speichern.

Der Kasten „Unterlagen der Rohstoffe“ hat je Zeile diesen Knopf statt des Links „Unterlage hochladen“. Siehe `module/anfrage/rohstoff_erfassen.md`.

## Preis-Gültigkeit (11.11.2026)
- Das Pop-up „Preis & Unterlagen“ enthält das Feld „Preis gültig bis“ (`pg_eingabe`).
- `bxRohErfassen(id, name, vorbelegung)` setzt optional das Datum vor.

## Preis schwankt (11.11.2026)
`rohstoff_erfassen_knopf()` übergibt bei „Preis schwankt“ die Vorbelegung „gültig bis“ an das Pop-up.
