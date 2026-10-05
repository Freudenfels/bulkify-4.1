# lager/core/erp.php – die Naht zum Dashboard

Die **einzige** Datei im Lager, die Tabellen des Dashboards kennt. Benennt jemand im Dashboard eine Spalte um, muss er nur diese Datei prüfen. Das gilt auch beim Umstieg auf v5: Dann wird nur diese Datei angepasst.

Stand heute wird hier nur gelesen, und zwar die Logins aus der Tabelle `benutzer`. Wenn später Buchungen dazukommen (zum Beispiel eine Entnahme für einen Produktionsauftrag), stehen sie hier als eigene, klar benannte Funktion.

**Kunden & Adressen (Versand, read-only):** `erp_kunden_liste()` (alle Kunden für die Auswahl) und `erp_kunde_adressen($kunde_id)` – baut die Adress-Auswahl aus den `kunden`-Spalten: **Lieferadresse (bevorzugt)**, Hauptadresse, Rechnungsadresse; jede mit `land` (ISO, weltweit). Genutzt beim Warenausgang (`?p=versand_detail`).

**Rezeptur ↔ Bulk-Item (read-only):** `erp_rezeptur_liste()` liefert die Rezepturen (≠ Entwurf) inkl. ihres kanonischen Bulk-Items für den Picker beim Einbuchen fertiger Kapseln; `erp_rezeptur_bulkitem($rezeptur_id)` löst das Bulk-Item auf (`item.rezeptur_id` + `kategorie='fertig'`). Die **Anlage** des Bulk-Items bleibt bewusst im Dashboard (`rezeptur_bulkitem()`), weil ein `require` der Dashboard-`core/schema.php` an doppelten `db()`-Definitionen scheitern würde. Details/Begründung: `ANTWORT-DASHBOARD-REZEPTUR-BULKITEM.md`.
