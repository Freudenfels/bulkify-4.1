# lieferant/liste.php – Lieferantenliste

**Zweck:** Übersicht aller Lieferanten – eigener Stamm, getrennt von den Kunden.

**Was passiert hier:**
1. `seed_lieferanten_if_empty()` – legt lokal Demo-Lieferanten an, falls leer.
2. Liest alle Lieferanten.
3. **Suche:** Firma, Ansprechpartner, Ort, Lieferantennummer, E-Mail, Kategorien (`?q=`).
4. **Sortierung:** `?sort=` / `?dir=`, Standard = Firma A–Z.
5. Tabelle über `bx_table()` mit Spalten:
   **Lief.-Nr. · Firma · Ort · Land · Kategorien · Zu prüfen · Sprache · Status.**
   - Status = Badge aktiv / gesperrt, Sprache = DE/EN/ZH.
   - Klick auf eine Zeile öffnet das Lieferantenkonto (`?p=lieferant&id=...`).
6. Button „Neuer Lieferant" oben rechts.

**Zu prüfen (Katalog-Freigaben):** Fügt ein Lieferant in seinem Portal etwas zu „Mein Katalog" hinzu, steht die Zeile auf `status='neu'` und wartet auf unsere Prüfung. Damit man das findet, zeigt die Liste:
- ein **Banner oben** mit der Gesamtzahl offener Katalog-Zeilen (nur wenn > 0), und
- die Spalte **„Zu prüfen"** mit einem Badge je Lieferant, der **direkt in den Katalog-Reiter** springt (`?p=lieferant&id=…#katalog`, `event.stopPropagation()` verhindert den normalen Zeilen-Klick zur Übersicht).
Die Prüfung selbst (Anlegen / ablehnen) passiert im Lieferantenkonto, Reiter **Katalog** (siehe `detail.md`, `core/lieferant_katalog.md`). Quelle der Zähler: `SELECT lieferant_id, COUNT(*) FROM lieferant_katalog WHERE status='neu' GROUP BY lieferant_id`.

**Muster:** identisch zur Kundenliste – Daten holen → suchen → sortieren → `bx_table()`.
