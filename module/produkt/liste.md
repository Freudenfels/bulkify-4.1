# produkt/liste.php – Produkt-Liste

**Zweck:** Übersicht aller Produkte. Ein **Produkt (SKU)** = Rezeptur + Verpackung + Kunde – das konkrete, verkaufbare Produkt.

**Was passiert hier:**
1. `seed_produkt_if_empty()` – legt lokal ein Demo-Produkt an (Magnesium Komplex · 120 Kapseln).
2. Liest alle Produkte inkl. Kunde, Rezeptur- und Verpackungsname (Joins).
3. **Suche** nach Nummer, Produkt, Kunde, Rezeptur. **Standard-Ansicht = nach Rezeptur gruppiert:** je Rezeptur eine
   Zwischenüberschrift (Rezepturnummer + Name), darunter alle Größen/Verpackungen (sortiert nach Einh./Pack, dann
   Verpackung, dann Nummer); Produkte ohne Rezeptur am Ende. Klick auf eine Spaltenüberschrift sortiert flach wie
   bisher, „Nach Rezeptur gruppieren" holt die Gruppen-Ansicht zurück. Reine Ansicht, keine Datenänderung.
4. Tabelle: **Nummer · Produkt · Kunde · Rezeptur · Verpackung · Einh./Pack · Status.**
   - Klick öffnet das Produkt (`?p=produkt&id=...`).
5. Button „Neues Produkt" (Nummer P-… automatisch).

Knopf „Kundenpreise“ öffnet die zentrale Kundenpreis-Liste (`?p=kundenpreise`).

**Schalter „Inaktive/gesperrte anzeigen“ (Block C, 11.11.2026):** standardmäßig aus, Gesperrte/Inaktive sind ausgeblendet (Lieferanten: offene Bewerbungen bleiben sichtbar; Kunden: Registrierungen bis Freigabe bleiben sichtbar).
