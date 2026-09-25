# auftrag/detail.php – Auftrag (Ansicht + Status)

**Zweck:** Zeigt eine Auftragsbestätigung und lässt den Produktions-/Bearbeitungsstatus setzen.

**Was passiert hier:**
- **POST:** speichert Status **und Preis**: **Menge (Packungen)** + **VK je Packung** (`vk_stueck`) sind editierbar, **Netto gesamt** wird automatisch als `Menge × VK` neu berechnet (`gesamt_netto`, gleiche Formel wie `auftrag_aus_angebot()`). So lassen sich Aufträge mit fehlendem/falschem Preis (z. B. 0,00 aus dem Import) direkt korrigieren. Eine kleine Live-Vorschau zeigt das Netto schon beim Tippen.
- **Anzeige:** Kennzahlen (Status, Menge in Packungen, **Stück je Packung** = `einheiten_pro_packung` des Produkts (nur wenn gesetzt), **Gesamtstückzahl** = Packungen × `einheiten_pro_packung`, **Kapsel/Tablette** via `produktion_groesse_label()`, **Herstellung** Eigenproduktion/Zukauf, VK/Stück, Netto gesamt). Alle Werte (`.bx-cards .v`) laufen auf einer einheitlichen, ruhigen Größe (15px) – seitenlokaler `<style>` überschreibt das globale `--fs-xl` nur hier; Badges bleiben unberührt. und Details mit klickbaren Links zur **Rezeptur** (`?p=rezeptur_detail`, aus `produkt.rezeptur_id`), zum **Angebot** und zur **Rechnung** (inkl. deren Zahlstatus).
- **Panel „Produktion & Beschaffung":** zeigt, ob selbst hergestellt (Eigenproduktion aus Rohstoffen) oder zugekauft (Fremdproduktion = fertige Bulkware), den verknüpften **Produktionsauftrag** (`produktionsauftrag.auftrag_id`) mit Status + Material-Bereitschaft (`produktion_bereitschaft()`), und die **Bestellungen zu diesem Auftrag** – bei welchem **Lieferanten** und mit welchem Status. Die Verknüpfung läuft über `bestellung_position.auftrag_id`. Ohne Bestellung: Hinweis „Rohstoffe/Bulkware noch offen" + Link zum Einkaufsbedarf.

- **Panel „EK-Preise & Express-Bestellung" (nur Admin):** zwei Teile, rein intern (nie im Kundenportal).
  - **Rohstoffe:** je benötigtem Rohstoff (aus `produktion_materialbedarf()`) die benötigte/fehlende Menge und **wo bestellbar** – alle Lieferantenpreise aus `lieferant_preis` (Firma, Staffel, EK je Einheit, günstigste zuerst). Je Lieferant ein **Express-Bestellung**-Knopf (`aktion=express_bestellung` → `auftrag_express_bestellung()`): legt direkt eine Bestellung (Entwurf) mit den **fehlenden** Mengen an (passender Staffelpreis), verknüpft über `bestellung_position.auftrag_id` – **überspringt Einkaufsbedarf/-liste**.
  - **Fertigprodukt zukaufen (Bulk):** Zukaufpreise des Produkts aus `produkt_lieferant_preis` (günstigste zuerst, mit Größe/Lieferbedingung). **„Fertigprodukt anfragen"** (`anfrage_produkt_button()`) holt über die Rezeptur Preise bei Lieferanten ein. Je Lieferant mit Zukaufpreis ein **Express-Zukauf**-Knopf (`aktion=express_bulk` → `auftrag_express_bulk_bestellung()`): legt eine Bestellung mit **einer Bulk-Position** (item_id NULL, Menge = Packungen × Einheiten/Packung) zum passenden Staffelpreis an. So lassen sich auch **fertige Produkte** direkt bestellen/anfragen, nicht nur Rohstoffe.
  Absenden an den Lieferanten in beiden Fällen wie gewohnt in der Bestellung.

**Herkunft:** Der Auftrag wird von `auftrag_aus_angebot()` erzeugt, sobald im Angebot eine Staffel bestätigt wird. Menge und VK stammen aus der bestätigten Staffel.

## Zu Kontingent machen
Ein angenommener Auftrag mit Menge + VK (und Kunde/Produkt) kann per Button **„Zu Kontingent machen"**
(nur Admin) in ein **Kontingent** (Rahmen/Abruf) umgewandelt werden: `kontingent_aus_auftrag()` legt ein
aktives Kontingent (gesamt_menge = Auftragsmenge, vk_stueck) an und **storniert** den Ursprungsauftrag –
produziert wird danach über die Abrufe (`kontingent_abruf`, je Abruf ein Auftrag). Idempotent; blockiert,
wenn bereits eine bezahlte Rechnung existiert oder der Auftrag selbst aus einem Kontingent stammt.

## Löschen & zurück zur Anfrage
Button **„Löschen & zurück zur Anfrage"** (nur Admin, nicht bei versendet): `auftrag_zurueck_und_loeschen()`
löscht die Auftragsbestätigung samt Produktionsauftrag/Schritten und (unbezahlter) Rechnung
(`auftrag_komplett_loeschen`), setzt das zugehörige Angebot von `bestaetigt` zurück auf `gesendet`
(Staffel-Haken zurück) und springt zur **Anfrage** (`?p=portal_anfrage&id=…`), um sie anzupassen oder
neu zu senden. Blockiert bei bezahlter Rechnung oder bereits versendetem Auftrag.

## Laboranalyse / Labortest (je Bestellung/Charge)
Panel „Laboranalyse / Labortest" (zeigt ggf. die Fertigware-Charge). Admin lädt hier den Labortest/das CoA für **genau diese Bestellung** hoch → `dokument` (`objekt_typ='auftrag'`, `typ='analyse'`, optional `dok_datum`). „im Kundenportal sichtbar" = `kunde_sichtbar=1` → erscheint im Kunden-Reiter „Labortest". Aktionen: `analyse_upload`, `analyse_toggle`, `analyse_del`. Produkt-weite Analysen laufen über `?p=laboranalysen`.
