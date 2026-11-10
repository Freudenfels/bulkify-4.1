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
