# lager/wareneingang.php – Wareneingang

**Zweck:** Eingehende Ware als **Charge** buchen und Quarantäne freigeben.

**Was passiert hier:**
- **Buchen (POST `aktion=buchen`):** `wareneingang_buchen()` legt eine Charge an (Menge, Charge-Nr, MHD, Lieferant) und gibt die neue Charge-ID zurück (die ID wird **vor** `bedarf_bump()` gesichert, da `meta_set()` sonst `LAST_INSERT_ID` auf 0 setzt – das hatte vorher die „gebucht/fehler"-Meldung verfälscht). Rohstoffe/Fertigware starten in **Quarantäne**, Verpackungen sofort **frei**. Menge = verfügbare Menge.
- **Tracking-Code(s) Paket/Palette:** Feld zum **Scannen** des Sendungs-/Barcodes vom Paket- oder Palettenetikett (`charge.tracking`, TEXT, je Zeile ein Code). Ein Barcode-Scanner tippt Code + Enter → jeder Scan landet in einer eigenen Zeile (Textarea, Live-Zähler „N Pakete erfasst"). Beim Buchen werden die Codes (getrimmt, dedupliziert) an die Charge gehängt – so ist der **Erhalt belegt und nachverfolgbar**, gerade bei vielen Paketen. Die Chargenliste zeigt den ersten Code + „+N Pakete" (Tooltip mit allen); eine **Suche** (GET `such`) findet Chargen über Tracking-Code, Charge-Nr oder Artikel (bis 100 Treffer) – z. B. „ist Paket X angekommen?".
- **Abgleich mit CoA-Vorab-Charge:** Wurde zu diesem Rohstoff schon eine Charge aus einer CoA vorab angelegt (gleiche Chargen-Nr., noch keine Ware: `wareneingang IS NULL`, Menge 0), bucht `wareneingang_buchen()` **diese** Charge ein (Menge/MHD/Datum, Status) statt eine Dublette anzulegen – die Analysewerte aus der CoA bleiben so an der Charge. Siehe `core/spec_ki.md` (`spec_ki_coa_charge`).
- **Freigeben (POST `aktion=freigeben`):** setzt eine Charge von Quarantäne auf **frei** (damit für Produktion nutzbar).
- **Anzeige:** Buchungsformular (Artikel, Menge, Charge, MHD, Lieferant, Notiz) + Liste der **letzten Chargen** mit Status und Freigabe-Button.
- **Artikel-Feld = durchsuchbares Kombifeld** (nicht mehr ein Dropdown mit >1200 Einträgen): Texteingabe filtert live (Substring im Namen, max. 50 Treffer), Auswahl per Klick oder Enter (Pfeiltasten navigieren). Der eigentliche Wert steht in einem verstecktem `item_id`; ein Submit-Guard verlangt eine echte Auswahl aus der Liste (zusätzlich zur Server-Prüfung). Die Artikel werden einmalig als JSON eingebettet (nur `rohstoff`/`verpackung`/`fertig`/`verkaufsfertig`, `gesperrt=0`). Reines Vanilla-JS, keine Bibliothek.

**Zusammenhang:** Der freie Bestand aus den Chargen erscheint im Warenlager und auf dem Rohstoff (Reiter „Lager"). Später zieht die Produktion echte Chargen daraus (FEFO).
