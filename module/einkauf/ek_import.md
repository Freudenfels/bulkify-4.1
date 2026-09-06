# module/einkauf/ek_import.php – EK-Preise (Import)

Seite `?p=ek_import` (Nav: Einkauf → „EK-Preise (Import)"; Rolle einkauf/admin).
Zeigt die aus CSV eingelesenen Einkaufspreise (Tabelle `ek_import`).

## Zwei Reiter (`?typ=`)
- **Rohstoffe / Bulk** – Name (CSV) · Lieferant · EK (EUR/kg) · Zuordnung → Rohstoff.
- **Fertigprodukte – intern** – Produkt · Größe · Formulierung · Kapsel-EK · Menge ·
  Lieferant · Zuordnung → Produkt. **Zukauf-Preise, nie in der Kundensicht.**

Suche über Name/Lieferant/Formulierung/Notiz; Filter „nur nicht zugeordnete".

## Marktplatz-Links (Alibaba & Co.)
Steht im Lieferant-Feld eine URL (z. B. ein Alibaba-Produktlink), ist das kein
Lieferant. Der Button **„Links in Notiz verschieben"** (erscheint, solange es solche
Zeilen gibt) schiebt die URL in die **Notiz** und setzt den Lieferant auf die
**Plattform** (Alibaba/AliExpress/Marktplatz). So entstehen keine Lieferanten mit Link,
und die lange URL bläht die Tabelle nicht mehr auf (Preis-Spalte bleibt sichtbar).
`tools/ek_import.php` macht das bei neuen Importen automatisch (`ek_ist_link`/`ek_plattform`
in `core/ek_ki.php`, `ek_links_bereinigen()` für Bestandsdaten).
„Zuordnung" verlinkt den v4-Rohstoff/das Produkt, sobald `item_id`/`produkt_id` gesetzt ist.

## Zuordnung (Review-UI)
- **Status-Filter** (offen / KI-Vorschlag / bestätigt / kein Treffer / verworfen) neben
  dem Typ-Reiter.
- **KI-Zuordnung starten**: verarbeitet die nächsten N offenen Zeilen über
  `core/ek_ki.php` (Vorschlag mit Zuversicht in %). Knopf nur auf **beta** aktiv (KI dort).
- Je Zeile: bei einem Vorschlag **Bestätigen**/**Verwerfen**; sonst **manuelle Zuordnung**
  per Freitext (Datalist mit Rohstoff-/Produktnamen).

## Datenfluss
1. `tools/ek_import.php` füllt `ek_import` aus den CSVs (Rohnamen, noch ohne Zuordnung).
2. KI-Batch oder manuelle Eingabe setzt `item_id`/`produkt_id` + `status`.
3. **Bestätigen** → bei Rohstoff wird `lieferant_preis` geschrieben (`quelle='ek_import'`,
   idempotent über `ek_import_id`) → „Preis ab"/Lieferanten-Marker in der Rohstoffliste
   füllen sich automatisch. Fertigprodukt-Zeilen bekommen nur `produkt_id` (interne
   Fertigprodukt-Preisliste, nie Kundensicht).

Logik in `core/ek_ki.php`. Der manuelle Pfad ist überall verifiziert; der KI-Pfad läuft
identisch, nur mit KI-Vorschlag statt Handeingabe.
