# anfrage/liste.php – Rezepturanfragen (Liste)

**Zweck:** Übersicht aller Rezepturanfragen (Kundenwünsche, die wir prüfen und in Rezepturen übersetzen).

**Aufbau wie die Portal-Anfragen** (Eingangsfach mit Archiv): **Ansicht-Tabs** (`.settabs`, `?ansicht=`) mit Zählern – **Offen** (Standard: `neu` + `in_bearbeitung` + `ueberarbeiten`) · **Beantwortet** · **Abgelehnt** · **Alle**. Beantwortete/abgelehnte Anfragen sind damit aus dem Arbeitsfach raus, aber im Archiv auffindbar.

**Was passiert hier:**
1. `seed_anfrage_if_empty()` – legt lokal eine Demo-Anfrage an.
2. Liest alle Anfragen inkl. Kunde, Anzahl Wünsche und – falls schon bearbeitet – die erzeugte Rezeptur-Nummer. Zähler je Ansicht werden einmal über alle Zeilen gebildet.
3. **Suche** (Nummer, Kunde, Wunsch-Produkt, Rezeptur) sucht über **alle** Status (die Ansicht zählt dann nicht). Ohne Suche wird nach Ansicht gefiltert. **Sortierung** über die Spaltenköpfe (`bx_table`), Standard = neueste zuerst; „überarbeiten"-Anfragen stehen immer zuoberst.
4. Tabelle: **Nummer · Rezeptur/Wunsch-Produkt · Kunde · Form · Wünsche · Rezeptur · Status · Angefragt**.
   - Klick öffnet die Anfrage-Bearbeitung (`?p=anfrage&id=...`).
5. Button „Neue Anfrage".
