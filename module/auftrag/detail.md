# auftrag/detail.php – Auftrag (Ansicht + Status)

**Zweck:** Zeigt eine Auftragsbestätigung und lässt den Produktions-/Bearbeitungsstatus setzen.

**Was passiert hier:**
- **POST:** speichert den Status (offen / in Produktion / erledigt).
- **Anzeige:** Kennzahlen (Status, Menge, VK/Stück, Netto gesamt) und Details mit Verlinkung zum **Angebot** und zur **Rechnung** (inkl. deren Zahlstatus).

**Herkunft:** Der Auftrag wird von `auftrag_aus_angebot()` erzeugt, sobald im Angebot eine Staffel bestätigt wird. Menge und VK stammen aus der bestätigten Staffel.
