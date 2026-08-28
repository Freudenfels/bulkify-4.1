# kunde/liste.php – Kundenliste

**Zweck:** Die Übersicht aller Kunden – schlank, sortierbar, durchsuchbar.

**Was passiert hier:**
1. `seed_kunden_if_empty()` – legt lokal Demo-Kunden an, falls die Tabelle leer ist.
2. Liest alle Kunden.
3. **Suche:** filtert nach Firma, Ansprechpartner, Ort, Kundennummer, E-Mail (Feld `?q=`).
4. **Sortierung:** über `?sort=` und `?dir=`, Standard = zuletzt geändert oben.
5. Zeigt die Tabelle über `bx_table()` mit den Spalten:
   **Kundennr. · Firma · Ort · Ansprechpartner · Status · zuletzt geändert.**
   - Status = Badge **aktiv** oder **gesperrt**.
   - Klick auf eine Zeile öffnet das Kundenkonto (`?p=kunde&id=...`).
6. Button „Neuer Kunde" oben rechts.

**Regel / Muster:** Diese Datei ist die **Vorlage** für alle weiteren Listen (Lieferanten, Partner …): Daten holen → suchen → sortieren → `bx_table()`. Keine eigene Tabellen-Optik.
