# lager/wareneingang.php – Wareneingang

**Zweck:** Eingehende Ware als **Charge** buchen und Quarantäne freigeben.

**Was passiert hier:**
- **Buchen (POST `aktion=buchen`):** `wareneingang_buchen()` legt eine Charge an (Menge, Charge-Nr, MHD, Lieferant). Rohstoffe/Fertigware starten in **Quarantäne**, Verpackungen sofort **frei**. Menge = verfügbare Menge.
- **Abgleich mit CoA-Vorab-Charge:** Wurde zu diesem Rohstoff schon eine Charge aus einer CoA vorab angelegt (gleiche Chargen-Nr., noch keine Ware: `wareneingang IS NULL`, Menge 0), bucht `wareneingang_buchen()` **diese** Charge ein (Menge/MHD/Datum, Status) statt eine Dublette anzulegen – die Analysewerte aus der CoA bleiben so an der Charge. Siehe `core/spec_ki.md` (`spec_ki_coa_charge`).
- **Freigeben (POST `aktion=freigeben`):** setzt eine Charge von Quarantäne auf **frei** (damit für Produktion nutzbar).
- **Anzeige:** Buchungsformular (Artikel, Menge, Charge, MHD, Lieferant, Notiz) + Liste der **letzten Chargen** mit Status und Freigabe-Button.

**Zusammenhang:** Der freie Bestand aus den Chargen erscheint im Warenlager und auf dem Rohstoff (Reiter „Lager"). Später zieht die Produktion echte Chargen daraus (FEFO).
