# partner/liste.php – Partnerliste

**Zweck:** Übersicht aller Partner. Ein Partner ist ein **Hybrid** – ein anderer Lohnhersteller, der bei uns bestellt (Kunden-Seite) **und** für uns fertigt (Lieferanten-Seite).

**Was passiert hier:**
1. `seed_partner_if_empty()` – legt lokal Demo-Partner inkl. SubKunden an, falls leer.
2. Liest alle Partner **plus** die Anzahl ihrer SubKunden (Unterabfrage auf `partner_subkunde`).
3. **Suche:** Firma, Ansprechpartner, Ort, Partnernummer, E-Mail.
4. **Sortierung:** Standard = Firma A–Z.
5. Tabelle über `bx_table()` mit Spalten:
   **Partner-Nr. · Firma · Ort · Land · SubKunden (Anzahl) · Sprache · Status.**
   - Klick auf eine Zeile öffnet das Partnerkonto (`?p=partner_detail&id=...`).
6. Button „Neuer Partner".

**Besonderheit:** Spalte **SubKunden** zeigt, wie viele Endkunden der Partner hinterlegt hat.
