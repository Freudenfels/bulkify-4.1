# einkauf/lieferant_preise.php – Lieferanten-Preise (strukturiert)

**Zweck:** Zeigt die **echten, verknüpften** Lieferantenpreise – sauber getrennt nach **Rohstoffen** und **Fertigprodukten (Zukauf)**. Route `?p=lieferant_preise`, Menü „Einkauf → Lieferanten-Preise".

**Reiter (`?tab=`):**
- **Rohstoffe** (Standard): aus `lieferant_preis` (item-basiert), verknüpft mit `item` (Name/Einheit) und `lieferanten` (Firma, sonst `lieferant_name`).
- **Fertigprodukte:** aus `produkt_lieferant_preis` (produktbasiert, mit `groesse`/`einheit`).

**Spalten:** Artikel (Rohstoff/Produkt, verlinkt), ggf. Größe, Lieferant, **ab Menge** (Staffel `menge_ab`), **Preis** (`preis` + Währung + je Einheit), **Lieferbedingung** (Incoterm · Versandart über `versandart_liste()`), **Quelle**, **Stand**. Mehrere Zeilen je Artikel = Mengenstaffeln bzw. verschiedene Lieferanten. Suche nach Artikel/Lieferant, Zähler je Reiter.

**Abgrenzung zur „EK-Preisliste" (`lief_preisliste`):** Jene ist der **flache v3-Abzug** (`lieferant_preisliste`: Freitext-Name/EUR-kg, Lieferant oft leer, gemischt) – nur Nachschlage-Referenz. Diese Ansicht zeigt die **strukturierten** Preise, die über angenommene **Lieferanten-Angebote** und den **EK-Import** entstehen (`quelle`/`ek_import_id`). Deshalb anfangs dünn befüllt und wächst mit der Nutzung.
