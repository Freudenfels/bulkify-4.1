# rezeptur/liste.php – Rezeptur-Liste

**Zweck:** Übersicht aller Formulierungen (Rezepturen).

**Was passiert hier:**
1. `seed_rezeptur_if_empty()` – legt lokal eine Demo-Rezeptur an (Magnesium Komplex).
2. Liest alle Rezepturen inkl. Kundenname (Join) und – je Rezeptur per Unterabfrage – den **Rohstoff-/Dokumenten-Status**:
   `zutat_anzahl` (alle Zutaten), `roh_anzahl` (distinct zugeordnete Lager-Rohstoffe), `roh_frei` (davon mit freiem
   Chargenbestand) und `dok_fehlen` (Rohstoffe ohne Spec/CoA – `dokument` objekt_typ='item', typ spec|coa|analyse).
3. **Live-Suche** (clientseitig): es werden immer **alle** Zeilen gerendert und beim Tippen sofort gefiltert (kein Neuladen), inkl. Live-Trefferzahl („N von M") und „zurücksetzen". Gefiltert wird über den gesamten Zeilentext (Nummer, Name, Kunde, Form, Status …). `?q=` befüllt das Feld nur vor (Deep-Link). **Sortierung** Standard = zuletzt geändert oben.
4. Tabelle über `bx_table()`: **Nr. · Rezeptur · Form · Kapselgröße · Kunde · Rohstoffe · Status** (angelehnt an die v3-Ansicht).
   - **Rezeptur:** Name **fett** + darunter eine **Formulierung**-Unterzeile (erste 3 Zutaten mit mg, „+N" für den Rest) – unterscheidet gleichnamige Rezepturen. Zutaten kommen in EINER Sammelabfrage (`$formuMap`).
   - **Kapselgröße:** fest gewählte (`rezeptur.kapselgroesse_id`) als „#0"; sonst bei Kapsel/Softgel **automatisch** die kleinste passende nach Füllgewicht (Näherung ohne Dichte) mit Zusatz „(auto)". Bei Nicht-Kapsel „–".
   - **Rohstoffe:** „N · frei X/Y" (grün = alle auf Lager, gelb = teils, rot = nichts) plus Hinweiszeile:
     rot „M Dokument(e) fehlen – hochladen" bzw. grün „Dokumente vollständig"; „Rezept leer – Rohstoffe hinzufügen"
     (keine Zutat) bzw. gelb „kein Rohstoff zugeordnet" (Zutaten ohne Lagerartikel). `(+k ohne Rohstoff)` wenn nur ein Teil zugeordnet ist.
   - **Status:** Status-Badge (Entwurf / Vorschlag / freigegeben / eingefroren) + Hinweis-Chip (leer / nicht zugeordnet / CoA/Spec fehlt).
   - Klick öffnet die Rezeptur (`?p=rezeptur_detail&id=...`) – dort werden Rohstoffe und Dokumente gepflegt.
5. Button „Neue Rezeptur" (Nummer RZ-… wird automatisch vergeben).
