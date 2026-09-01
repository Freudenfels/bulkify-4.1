# pdf_angebot.php – Angebots-PDF (eine Quelle für Team und Kunde)

## Wozu
Baut das PDF zu einem Angebot. Vorher steckte der Aufbau **nur im Kundenportal** – das Team kam
nur über den Kunden-Portallink daran, und ohne Kunde am Angebot gab es gar kein PDF. Jetzt liegt
alles hier, und beide Seiten rufen dieselbe Funktion: Was das Team sieht, ist genau das, was beim
Kunden ankommt.

Es wird **keine Datei gespeichert** – das PDF entsteht bei jedem Aufruf neu aus dem aktuellen
Stand des Angebots. Änderst du Positionen, ändert sich das PDF mit.

## Funktionen
- `angebot_pdf_bauen(int $angebot_id): ?string` – liefert das PDF als String, oder `null`,
  wenn es das Angebot nicht gibt **oder kein Kunde daran hängt** (ohne Empfänger kein Beleg).
- `angebot_pdf_ausliefern(int $angebot_id, string $nummer): bool` – setzt die Header und gibt
  das PDF aus (inline im Browser, Dateiname `Angebot_<Nr>.pdf`). `false` = nichts zu liefern.

## Was ins PDF kommt
- **Kopf:** Angebotsnummer, Datum, **Gültig bis** (aus `angebot.gueltig_bis`, sonst Datum +
  `angebot_gueltig_tage`), Kundennummer, Bezug zur Anfrage, Anschrift und USt-IdNr. des Kunden.
- **Begleittext:** der Hinweis aus der Notiz (alles nach dem „—") plus die Produktionszeit
  (Angebot vor globalem Wert), ausdrücklich als unverbindlich.
- **Positionen:** die gespeicherten Positionen, sonst die automatische Kalkulation.
  Bei einem Angebot mit **mehreren Varianten** (Gruppen A, B, C) nur die erste Variante plus die
  gruppenlosen Zuschläge – die Gruppen sind eine *Wahl*, keine Bestellzeilen; sonst stünde unter
  dem PDF eine Summe über Varianten, die niemand zusammen bestellt.
- **„Preis je fertiges Produkt":** die Staffel. Bei Matrix-Angeboten aus dem Produkt
  (`angebot_staffel_gruppen()`), bei Rezeptur-Angeboten aus den Optionen
  (`angebot_staffel_aus_optionen()`) – dort stehen alle Varianten mit Preis je Packung und je Stück.
- **USt:** Inland der Satz aus den Einstellungen, sonst 0 % (EU-/Export-Lieferung).

## Wer ruft es auf
- `module/angebot/pdf.php` – interne Route `?p=angebot_pdf&id=<ID>` (Rollen sales, finance, admin).
- `module/portal/kunde.php` – `?p=portal&token=…&v=angebot_pdf&aid=<ID>` für den Kunden
  (das Download-Icon auf der Angebotskarte).

## Verwandt
- `core/pdf_beleg.php` – die Belegvorlage (Briefkopf, Positionstabelle, Summen, Staffel).
