# core/ek_ki.php – KI-Zuordnung der EK-Preislisten

Ordnet die importierten EK-Zeilen (`ek_import`) den v4-Rohstoffen/Produkten zu.

## Ablauf je Zeile
1. **Kandidaten-Vorauswahl in PHP** (`ek_kandidaten`): Token-Überlappung des EK-Namens
   mit allen Rohstoff-/Produktnamen (inkl. en/lat/synonym). Nur die ~20 besten gehen an
   die KI – nicht alle 1.188 Rohstoffe (spart Tokens).
2. **KI wählt** (`ek_ki_match`): aus den Kandidaten den passenden (oder keinen), mit
   Zuversicht 0–100. Halluzinierte ids (nicht in der Kandidatenliste) werden verworfen.

## Funktionen
- `ek_ki_batch($typ, $limit)` – die nächsten `$limit` offenen Zeilen vorschlagen; setzt
  `item_id`/`produkt_id` + `ki_score` + Status `vorschlag` bzw. `kein_treffer`.
- `ek_bestaetigen($id)` – Vorschlag/Zuordnung annehmen → Status `bestaetigt`; bei
  Rohstoff wird `lieferant_preis` geschrieben (`ek_lieferant_preis_schreiben`).
- `ek_manuell_zuordnen($id, $eingabe)` – freie Namens-/Nummerneingabe auflösen + bestätigen.
- `ek_verwerfen($id)` – Zuordnung löschen, Status `verworfen`.
- `ek_lieferant_id($name)` – Roh-Lieferantenname unscharf auf `lieferanten.id`; legt
  KEINEN Lieferanten an (unbekannte bleiben als `lieferant_preis.lieferant_name` Text).

## Übernahme in lieferant_preis
Bestätigte Rohstoff-Zeilen → `lieferant_preis` (item_id, preis, `lieferant_name`,
`quelle='ek_import'`, `ek_import_id` für Idempotenz). Damit füllen sich „Preis ab" und
der „Lief."-Marker in der Rohstoffliste automatisch. `lieferant_id` darf NULL sein
(viele CSV-Lieferanten sind Rohnamen/Agenten ohne Lieferanten-Datensatz).

## Wichtig
Die KI läuft nur auf **beta** (`ki_bereit()`), lokal ist der Batch-Knopf deaktiviert –
die manuelle Zuordnung funktioniert überall. Fertigprodukt-Zeilen bleiben intern
(Zukauf, nie Kundensicht); sie bekommen `produkt_id`, aber keinen lieferant_preis.
