# reservierung_deckung.php – Reservierung ehrlich anzeigen

Seit 13.11.2026 (Sofortfix Reservierung/Wareneingang, Teil 3). Eine Reservierung ist keine Ware. Diese Datei rechnet für jede aktive Reservierung eines Artikels, wodurch sie gedeckt ist:

- **im Lager:** durch freien Bestand
- **unterwegs:** durch offene Bestellungen (`item_unterwegs`, ohne Sperr-Entwürfe und Storno)
- **ungedeckt:** weder noch (rot)

Produktions-Reservierungen werden zuerst gedeckt, dann manuelle nach Anlage. Reservierungen auf eine Charge zählen auf diese Charge, auf eine Bestellposition auf deren offenen Rest.

- `reservierung_deckung($item)` – je Reservierung und Summe.
- `reservierung_deckung_auftrag($item, $auftrag)` – Summe je Auftrag samt PR.
- `reservierung_deckung_text()` – Text „im Lager 0, unterwegs 0, ungedeckt 6 kg“.
- `res_einzig_offener_pa($auftrag)` – der einzige offene PR eines Auftrags.
- `res_pa_anzeige($r)` – der PR einer Reservierung: `pa_id` (aus der Produktion), `ziel_pa_id` (manuell, beim Anlegen gesetzt) oder für alte Reservierungen der einzige offene PR, nur angezeigt.
- `item_nicht_eingebuchte_vormerkungen($item)` – Bestellungen mit vorgemerkter, nicht eingebuchter Charge, für „BE-…: Charge eingetragen, nicht eingebucht – jetzt einbuchen“.

Genutzt in diesen Ansichten:
- Rohstoff: Kopf, Reiter Lager und Reservierungsliste mit den Spalten PR und Deckung
- PR: Spalte „Reserviert“, dazu der Grund bei „fehlt“
- Auftrag: Reiter Material
