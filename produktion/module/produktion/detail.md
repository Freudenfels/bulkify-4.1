# produktion/module/produktion/detail.php
Übersicht + Schrittliste eines Produktionsauftrags über `erp_pa()`/`erp_pa_schritte()`.

**Eine Übersichtskarte**: alle Felder in EINEM gleichmäßigen Spaltenraster (`auto-fit`, nutzt die volle Breite), Label über Wert – keine Gruppen-Überschriften, keine Trennlinien, keine Kacheln. Leere Felder fallen raus. Felder:
- **Auftrag:** Status, Produzierbar? (`erp_pa_bereitschaft`), Auftragseingang, Kunde, Herstellung (eigen/fremd).
- **Produkt:** Produkt, Rezeptur, Kapselgröße, Verpackung (Name · Typ · Volumen · Material, ohne Dopplung zum Namen).
- **Menge:** Packungen, Stück/Kapseln je VPE + gesamt.
- **Charge:** Chargennummer (gebucht oder geplant `.A`) + MHD (`erp_pa_charge_info`).

Verpackung wird immer gezeigt (bei fehlender Angabe „–"); Produktionstyp = Eigen-/Fremdproduktion. Darunter die Tabelle **„Rezeptur & Rohstoffbedarf"** (`erp_materialbedarf()`): je Rohstoff mg je Einheit, benötigte Gesamtmenge, Verfügbar, Status + Füllgewicht; ohne Rohstoff-Items fällt sie auf eine reine Zutatenliste zurück (`erp_pa_zutaten()`). Fehlt Material, erscheint zusätzlich das Panel **„Wartet auf Material"**.

Oben rechts der Button **„In den Produktionsmodus"** (`?p=run&id=…`).

**Schritte:** der jeweils erste offene Schritt ist „als Nächstes" und trägt den **Abschließen**-Button. Der POST (`aktion=schritt_ab`, `schritt_id`) ruft `erp_schritt_abschliessen()` auf (FEFO-Entnahme, Mangel-Guard; letzter Schritt bucht Fertigware ein) und leitet danach um (PRG, Flash). Zeiten via `fmt_zeit()`.
