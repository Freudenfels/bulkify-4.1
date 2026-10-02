# produktion/module/produktion/detail.php
Übersicht + Schrittliste eines Produktionsauftrags über `erp_pa()`/`erp_pa_schritte()`.

**Eine Übersichtskarte**, in ruhige Gruppen gegliedert (feine Trennlinien, grüne Gruppentitel, leere Felder fallen raus):
- **Auftrag:** Status, Produzierbar? (`erp_pa_bereitschaft`), Auftragseingang, Kunde, Herstellung (eigen/fremd).
- **Produkt:** Produkt, Rezeptur, Kapselgröße, Verpackung (Name · Typ · Volumen · Material, ohne Dopplung zum Namen).
- **Menge:** Packungen, Stück/Kapseln je VPE + gesamt.
- **Charge:** Chargennummer (gebucht oder geplant `.A`) + MHD (`erp_pa_charge_info`).

Fehlt Material, erscheint zusätzlich das Panel **„Wartet auf Material"** mit benötigt/verfügbar/fehlt.

**Schritte:** der jeweils erste offene Schritt ist „als Nächstes" und trägt den **Abschließen**-Button. Der POST (`aktion=schritt_ab`, `schritt_id`) ruft `erp_schritt_abschliessen()` auf (FEFO-Entnahme, Mangel-Guard; letzter Schritt bucht Fertigware ein) und leitet danach um (PRG, Flash). Zeiten via `fmt_zeit()`.
