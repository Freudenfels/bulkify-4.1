# auftrag/detail.php – Auftrag (Ansicht + Status)

**Zweck:** Zeigt eine Auftragsbestätigung und lässt den Produktions-/Bearbeitungsstatus setzen.

**Was passiert hier:**
- **POST:** speichert den Status (offen / in Produktion / erledigt).
- **Anzeige:** Kennzahlen (Status, Menge in Packungen, **Gesamtstückzahl** = Packungen × `einheiten_pro_packung`, **Kapsel/Tablette** via `produktion_groesse_label()`, **Herstellung** Eigenproduktion/Zukauf, VK/Stück, Netto gesamt) und Details mit Verlinkung zum **Angebot** und zur **Rechnung** (inkl. deren Zahlstatus).
- **Panel „Produktion & Beschaffung":** zeigt, ob selbst hergestellt (Eigenproduktion aus Rohstoffen) oder zugekauft (Fremdproduktion = fertige Bulkware), den verknüpften **Produktionsauftrag** (`produktionsauftrag.auftrag_id`) mit Status + Material-Bereitschaft (`produktion_bereitschaft()`), und die **Bestellungen zu diesem Auftrag** – bei welchem **Lieferanten** und mit welchem Status. Die Verknüpfung läuft über `bestellung_position.auftrag_id`. Ohne Bestellung: Hinweis „Rohstoffe/Bulkware noch offen" + Link zum Einkaufsbedarf.

**Herkunft:** Der Auftrag wird von `auftrag_aus_angebot()` erzeugt, sobald im Angebot eine Staffel bestätigt wird. Menge und VK stammen aus der bestätigten Staffel.
