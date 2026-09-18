# rezeptur/liste.php – Rezeptur-Liste

**Zweck:** Übersicht aller Formulierungen (Rezepturen).

**Was passiert hier:**
1. `seed_rezeptur_if_empty()` – legt lokal eine Demo-Rezeptur an (Magnesium Komplex).
2. Liest alle Rezepturen inkl. Kundenname (Join) und Anzahl Zutaten (Unterabfrage).
3. **Live-Suche** (clientseitig): es werden immer **alle** Zeilen gerendert und beim Tippen sofort gefiltert (kein Neuladen), inkl. Live-Trefferzahl („N von M") und „zurücksetzen". Gefiltert wird über den gesamten Zeilentext (Nummer, Name, Kunde, Form, Status …). `?q=` befüllt das Feld nur vor (Deep-Link). **Sortierung** Standard = zuletzt geändert oben.
4. Tabelle über `bx_table()`: **Nummer · Name · Kunde · Form · Zutaten · Status.**
   - Status-Badges: Entwurf / Vorschlag / freigegeben / eingefroren.
   - Klick öffnet die Rezeptur (`?p=rezeptur_detail&id=...`).
5. Button „Neue Rezeptur" (Nummer RZ-… wird automatisch vergeben).
