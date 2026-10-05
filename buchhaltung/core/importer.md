# importer.php — Bulk-Import (alte Angebote + Rechnungen)

Import-Assistent: PDFs stapelweise hochladen -> KI liest aus/ordnet ein -> Kunde zuordnen -> Rechnung<->Angebot
verknuepfen -> Zahlungen -> uebernehmen. Erzeugt BLEIBENDE Datensaetze; das Staging ist temporaer/loeschbar.

## Tabellen
- Staging (temporaer): `bu_imp_item` (je Datei: art, kunde, betraege, angebot_ref, link_item_id, ergebnis_*),
  `bu_imp_pos` (Angebots-Positionen Produkt/Verpackung/Etikett, gruppe = Produktblock), `bu_imp_zahlung`.
- Bleibend: Rechnungen -> `beleg` (kunde_sichtbar=1, original_datei, neue Spalte `beleg.imp_angebot_id`),
  Angebote -> `bu_imp_angebot` (+ `bu_imp_angebot_pos`). `bu_kunde_alias` (Name->Kunde, Gruppen).

## Kernfunktionen
- `imp_datei_hinzufuegen($batch,$file)`: speichert Datei, `imp_ki_auslesen()`, Kundenmatch, legt Item (+Pos) an.
- `imp_kunde_match($name)`: Alias (Annapurna/Pure Health/CW Media -> Pure Health NL DE) > Fuzzy-Firma > neu.
  `imp_item_kunde_setzen()` fixiert bei Zuordnung die Alias-Gruppe; `imp_item_kunde_neu()` legt Kunde an (erp_kunde_anlegen).
- `imp_auto_verknuepfen($batch)`: Rechnung.angebot_ref -> Angebot-Item (Nummer).
- `imp_zahlung_add/_del/_summe`: Teilzahlungen (Datum+Betrag) je Rechnung-Item.
- `imp_uebernehmen($batch)`: Angebote -> bu_imp_angebot(+Pos); Rechnungen -> beleg (kunde_sichtbar) + Zahlungen
  (zahlung_erfassen -> Status offen/teilbezahlt/bezahlt) + Verweis imp_angebot_id. Idempotent (uebernommene uebersprungen).
- `imp_batch_loeschen($batch)`: Staging + nicht-uebernommene Dateien weg; uebernommene Belege/Angebote bleiben.

Kundenschreibzugriff nur ueber die Naht: `erp_kunde_anlegen()` (einzige Schreibstelle in kunden).
