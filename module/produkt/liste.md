# produkt/liste.php – Produkt-Liste

**Zweck:** Übersicht aller Produkte. Ein **Produkt (SKU)** = Rezeptur + Verpackung + Kunde – das konkrete, verkaufbare Produkt.

**Was passiert hier:**
1. `seed_produkt_if_empty()` – legt lokal ein Demo-Produkt an (Magnesium Komplex · 120 Kapseln).
2. Liest alle Produkte inkl. Kunde, Rezeptur- und Verpackungsname (Joins).
3. **Suche** nach Nummer, Produkt, Kunde, Rezeptur. **Sortierung** Standard = zuletzt geändert.
4. Tabelle: **Nummer · Produkt · Kunde · Rezeptur · Verpackung · Einh./Pack · Status.**
   - Klick öffnet das Produkt (`?p=produkt&id=...`).
5. Button „Neues Produkt" (Nummer P-… automatisch).
