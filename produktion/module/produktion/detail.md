# produktion/module/produktion/detail.php
Übersicht + Schrittliste eines Produktionsauftrags über `erp_pa()`/`erp_pa_schritte()`.

**Eine Übersichtskarte** mit Label/Wert-Zeilen (leere Felder werden ausgeblendet): Status, Produzierbar? (`erp_pa_bereitschaft`), Auftragseingang, Kunde, Produkt, Rezeptur, Kapselgröße, Menge (Packungen), Stück/Kapseln je VPE + gesamt, Charge (gebucht oder geplant `.A`) + MHD (`erp_pa_charge_info`), Verpackung (Name · Typ · Volumen · Material), Herstellung (eigen/fremd). Fehlt Material, erscheint zusätzlich das Panel **„Wartet auf Material"** mit benötigt/verfügbar/fehlt.

**Schritte:** der jeweils erste offene Schritt ist „als Nächstes" und trägt den **Abschließen**-Button. Der POST (`aktion=schritt_ab`, `schritt_id`) ruft `erp_schritt_abschliessen()` auf (FEFO-Entnahme, Mangel-Guard; letzter Schritt bucht Fertigware ein) und leitet danach um (PRG, Flash). Zeiten via `fmt_zeit()`.
