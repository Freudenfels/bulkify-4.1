# lieferant/liste.php – Lieferantenliste

**Zweck:** Übersicht aller Lieferanten – eigener Stamm, getrennt von den Kunden.

**Was passiert hier:**
1. `seed_lieferanten_if_empty()` – legt lokal Demo-Lieferanten an, falls leer.
2. Liest alle Lieferanten.
3. **Suche:** Firma, Ansprechpartner, Ort, Lieferantennummer, E-Mail, Kategorien (`?q=`).
4. **Sortierung:** `?sort=` / `?dir=`, Standard = Firma A–Z.
5. Tabelle über `bx_table()` mit Spalten:
   **Lief.-Nr. · Firma · Ort · Land · Kategorien · Sprache · Status.**
   - Status = Badge aktiv / gesperrt, Sprache = DE/EN/ZH.
   - Klick auf eine Zeile öffnet das Lieferantenkonto (`?p=lieferant&id=...`).
6. Button „Neuer Lieferant" oben rechts.

**Muster:** identisch zur Kundenliste – Daten holen → suchen → sortieren → `bx_table()`.
