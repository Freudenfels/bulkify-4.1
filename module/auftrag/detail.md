# auftrag/detail.php – Auftrag (Ansicht + Status)

**Zweck:** Zeigt eine Auftragsbestätigung und lässt den Produktions-/Bearbeitungsstatus setzen.

**Was passiert hier:**
- **POST:** speichert Status **und Preis**: **Menge (Packungen)** + **VK je Packung** (`vk_stueck`) sind editierbar, **Netto gesamt** wird automatisch als `Menge × VK` neu berechnet (`gesamt_netto`, gleiche Formel wie `auftrag_aus_angebot()`). So lassen sich Aufträge mit fehlendem/falschem Preis (z. B. 0,00 aus dem Import) direkt korrigieren. Eine kleine Live-Vorschau zeigt das Netto schon beim Tippen.
- **Anzeige:** Kennzahlen (Status, Menge in Packungen, **Gesamtstückzahl** = Packungen × `einheiten_pro_packung`, **Kapsel/Tablette** via `produktion_groesse_label()`, **Herstellung** Eigenproduktion/Zukauf, VK/Stück, Netto gesamt) und Details mit Verlinkung zum **Angebot** und zur **Rechnung** (inkl. deren Zahlstatus).
- **Panel „Produktion & Beschaffung":** zeigt, ob selbst hergestellt (Eigenproduktion aus Rohstoffen) oder zugekauft (Fremdproduktion = fertige Bulkware), den verknüpften **Produktionsauftrag** (`produktionsauftrag.auftrag_id`) mit Status + Material-Bereitschaft (`produktion_bereitschaft()`), und die **Bestellungen zu diesem Auftrag** – bei welchem **Lieferanten** und mit welchem Status. Die Verknüpfung läuft über `bestellung_position.auftrag_id`. Ohne Bestellung: Hinweis „Rohstoffe/Bulkware noch offen" + Link zum Einkaufsbedarf.

- **Panel „EK-Preise & Express-Bestellung" (nur Admin):** zeigt je benötigtem Rohstoff (aus `produktion_materialbedarf()`) die benötigte/fehlende Menge und **wo bestellbar** – alle Lieferantenpreise aus `lieferant_preis` (Firma, Staffel, EK je Einheit, günstigste zuerst). Je Lieferant ein **Express-Bestellung**-Knopf (`aktion=express_bestellung` → `auftrag_express_bestellung()` in `core/schema.php`): legt **direkt** eine Bestellung (Entwurf) mit den **fehlenden** Mengen an, die der Lieferant anbietet (passender Staffelpreis), verknüpft über `bestellung_position.auftrag_id`, und öffnet sie – **überspringt Einkaufsbedarf/-liste**. Absenden an den Lieferanten dann wie gewohnt in der Bestellung. Rein intern, nie im Kundenportal.

**Herkunft:** Der Auftrag wird von `auftrag_aus_angebot()` erzeugt, sobald im Angebot eine Staffel bestätigt wird. Menge und VK stammen aus der bestätigten Staffel.
