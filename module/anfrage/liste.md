# anfrage/liste.php – Rezepturanfragen (Liste)

**Zweck:** Übersicht aller Rezepturanfragen (Kundenwünsche, die wir prüfen und in Rezepturen übersetzen).

**Was passiert hier:**
1. `seed_anfrage_if_empty()` – legt lokal eine Demo-Anfrage an.
2. Liest alle Anfragen inkl. Kunde, Anzahl Wünsche und – falls schon bearbeitet – die erzeugte Rezeptur-Nummer.
3. **Suche** nach Nummer, Kunde. **Sortierung** Standard = neueste zuerst.
4. Tabelle: **Nummer · Kunde · Wunsch-Produkt · Form · Wünsche · Rezeptur · Status** (neu / in Bearbeitung / beantwortet / abgelehnt).
   - Klick öffnet die Anfrage-Bearbeitung (`?p=anfrage&id=...`).
5. Button „Neue Anfrage".
