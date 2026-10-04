# angebote_ansicht.php — Nur-Lese-Angebote + Abgleich

Route `angebote_ansicht` (Rolle finance/admin). Rein lesend, verändert nichts.

## Reiter
- **Angebote** (`tab=liste`): Liste Nummer / Datum / Kunde / Produkt / Status / Summe (netto).
  Summe = bestätigte bzw. erste/kleinste Staffel (menge × VK); „(ab)" = weitere Staffeln. Mit Suche.
- **Abgleich** (`tab=abgleich`): je Kunde Angebot → Auftrag → Rechnung nebeneinander, um Lücken zu sehen
  (Angebot ohne Auftrag, Auftrag ohne Rechnung = „fehlt"). Kennzahlen oben: Aufträge ohne Rechnung,
  Angebote ohne Auftrag, Kunden im Abgleich. Freie Rechnungen (ohne Auftrag) werden mit aufgeführt.

## Datenquellen
- Angebote/Aufträge über die Naht `erp_angebote()` / `erp_auftraege()` (lesen `angebot`/`auftrag`/`kunden`
  gebündelt in `core/erp.php` — nirgends sonst direkter Zugriff auf diese Dashboard-Tabellen).
- Rechnungen aus der finanz-eigenen Tabelle `beleg` (direkt gelesen). Angebot/Auftrag als Netto, Rechnung als Brutto.
