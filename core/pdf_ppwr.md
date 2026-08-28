# Verpackungs-Konformität / PPWR-PDF (core/pdf_ppwr.php)

## Wozu
Erzeugt je **Bestellung** ein Kunden-Dokument „Verpackungs-Konformität (PPWR)" als PDF im
bulkify-Layout. Der Kunde lädt es im Portal unter **Bestellungen** jederzeit herunter (für
seine Unterlagen). Selbst-tragendes, generiertes Dokument – kein Zusammenführen der
hochgeladenen Original-PDFs (Merge-Lib fehlt); die Uploads dienen als interner Nachweis.

## Inhalt
- Briefkopf (Logo, Firma, Empfänger) wie beim Beleg.
- Titel „Verpackungs-Konformität" + PPWR-Bezug, Meta (Bestellung, Datum, Kunden-Nr., Produkt).
- **Komponenten-Tabelle** je Verpackung des Produkts (Dose/Deckel/Etikett …): Rolle,
  Bezeichnung, Material, Leergewicht (g), Maße (Ø/B × H × T mm).
- **Konformitätserklärung**: Verpackungsgewicht gesamt, PFAS-frei, Recyclingfähigkeit,
  DoC/Spezifikationen liegen vor, LUCID/EPR-Pflicht beim Inverkehrbringer.
- Ausstellerzeile (Ort/Datum, Maniso GmbH) + Rechts-Fußzeile.

## Funktion
`build_ppwr_pdf(array $b, array $komponenten): string` (in `core/pdf_ppwr.php`).
- `$b`: nummer (Bestellung), datum, empfaenger, adresse, produkt, kundennummer, ust_id.
- `$komponenten`: je Verpackung rolle, name, material, gewicht_g, volumen_ml, masse.
- Helfer `ppwr_masse($item)` baut den Maß-Text aus breite/hoehe/durchmesser/tiefe.
Firmendaten aus `beleg_firma()`.

## Aufruf
`module/portal/kunde.php`, Zweig `?v=ppwr_pdf&aid=<auftrag_id>` (Token, nur eigene Bestellung):
ermittelt die Verpackungen über `produkt_verpackung_items()` + item-Stammdaten, baut die
Komponenten und liefert `application/pdf` inline. Download-Link steht in der Portal-Ansicht
**Bestellungen** je Auftrag.

## Datenpflege
Material, Leergewicht und Maße kommen aus der Verpackung (Reiter Stammdaten in
`verpackung_detail.php`). Fehlen sie, steht „–" im Dokument – also je Verpackung pflegen.
Die hochgeladenen Nachweise (Reiter „Dokumente (PPWR)") sind der interne Beleg dahinter.
