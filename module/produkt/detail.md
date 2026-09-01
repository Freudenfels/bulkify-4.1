# produkt/detail.php – Produkt anlegen & bearbeiten (mit Kalkulation)

**Zweck:** Führt die Stammdaten zum verkaufbaren **Produkt** zusammen: **Rezeptur × Einheiten je Packung + Verpackung**. Kundenneutral – ein Kunde steht nur bei exklusiven Produkten dran. Rechnet Kosten pro Packung, Reichweite und die Tages-Deklaration – die Vorstufe zum Angebot.

**Was passiert hier:**
- **Speichern (POST):** schreibt das Produkt in `produkt` inkl. der **kompletten Verpackungs-Stückliste** (Primär `verpackung_id`, `verschluss_id`, `etikett_id`, `karton_id`, `beipack_id`); neu = INSERT + P-Nummer. Beim Anlegen ein Verlaufseintrag am Kunden (klickbar zum Produkt).
- **Anzeige (GET):** lädt Kunden, Rezepturen, Verpackungen (nach Rolle gruppiert) und die Kapselgrößen. **Alle Rezepturen werden vorberechnet** (Nährstoffe je Einheit, Kosten je Einheit, Gewicht) und als JSON eingebettet; ebenso je Verpackung ihre **Kapsel-Fassung** (`pack_kapazitaet`), damit die Prüfung live im Browser läuft.

**Felder:** Produktname, Kunde (leer = Katalogprodukt), Status, **Rezeptur**, **Verzehr je Tag**, **Einheiten je Packung** (z. B. 120 Kapseln), Notiz – plus die **Stückliste**: Primärverpackung + Verschluss + Etikett + Faltschachtel + Beipackzettel (jede Auswahl zeigt nur Verpackungen ihrer Rolle) + **Leerkapsel** (nur Kapselprodukte: leer = automatisch nach Kapselgröße, Auswahl nur nötig, wenn mehrere Kapseln gleicher Größe existieren; beim Bearbeiten wird die effektive Kapsel als Hinweis gezeigt).

**Live-Kalkulation (rechnet sofort mit):**
- **Kosten / Packung** = Rezepturkosten je Einheit × Einheiten + EK der **ganzen Stückliste** (Summe über alle Slots).
- **Reichweite** = Einheiten ÷ Verzehr je Tag (Tage pro Packung).
- **Gewicht je Einheit** (aus der Rezeptur).
- **Verpackung (passt/zu klein):** prüft die **Primärverpackung** je Darreichungsform über die richtige Kennzahl:
  - **Kapsel/Softgel** → bestimmt aus dem Gewicht je Einheit die kleinste passende Kapselgröße, liest die Kapsel-Fassung der Dose und vergleicht mit der gewünschten Kapselzahl. „passt" / „zu klein" (+ Vorschlag anderer Dosen) / „keine Fassung hinterlegt" / „aufteilen" (Rezeptur passt in keine Kapsel).
  - **Pulver/Granulat/Stick** → Füllgewicht (g) gegen max. Füllgewicht der Verpackung.
  - **Tablette** → Tablettengewicht (Wirkstoffe + Presshilfsstoffe, % aus den Einstellungen) × Stückzahl gegen max. Füllgewicht.
  - **Flüssig** → Füllvolumen (ml, = Einheiten) gegen Volumen der Flasche.
- **Nährwerte pro Tagesdosis:** je Nährstoff die Menge × Verzehr/Tag und **% NRV** (z. B. bei 2 Kapseln/Tag: Magnesium 240 mg = 64 %).

**Katalog / Exklusiv:** Ein Produkt liegt standardmäßig im **gemeinsamen Katalog** (Häkchen `exklusiv` aus) und gehört niemandem. Exklusiv = nur für den gewählten Kunden sichtbar. Das Feld „Kunde" gilt **nur bei exklusiv** als Besitzer – ohne Häkchen wird `kunde_id` beim Speichern auf NULL gesetzt, sonst stünde in der Produktliste ein Kundenname bei einem Produkt, das jeder Kunde bestellen kann. Kundenspezifisch ist der **Preis** (Angebot + `kunden.rabatt_marge`), nicht das Produkt.

**Preis-Matrix (Panel unten):** „Matrix neu berechnen" erzeugt über `produkt_matrix_generieren()` die VK-Tabelle **Packungsgröße × passende Verpackung × Bestellmenge** (Zeilen = Größe+Verpackung, Spalten = Bestellmengen; je Zelle VK + kleiner EK). Die Spalte **Größe** meint je Darreichungsform etwas anderes: Stückzahl bei Kapsel/Tablette/Softgel/Stick, **Gramm** bei Pulver/Granulat, **Milliliter** bei Flüssig (Beschriftung über `form_groessen_label()`). EK je Packung = Rezeptur + Leerkapsel (Kapsel), + Presshilfsstoffe (Tablette) bzw. + Trägerflüssigkeit (Flüssig); der Behälter kommt separat als eigene Angebotsposition. Basis-VK ohne Kundenrabatt – schnelle interne Sale-Auskunft. Voraussetzung: Rezeptur + hinterlegte Behälter-Fassung (Kapseln je Größe, Füllgewicht in g bzw. Fassungsvermögen in ml).

**Dokumente (CoA & Spezifikation):** Panel unter der Preis-Matrix (nur bei bestehendem Produkt) – gemeinsame Komponente `core/dokument_ui.php` (Tabelle `dokument`, objekt_typ=produkt): CoA/Spec/Analyse hochladen, je Dokument optional mit **Lieferant**; Download über Route `dokument`, Löschen inline.

**Zwei Namen:** **Produktname (intern)** = `produkt.name` (unser Arbeitsname, z. B. „Zink") – heißen mehrere Produkte gleich, hängt `produkt_name_versioniert()` beim Speichern automatisch „ v2, v3 …" an (case-insensitiv, erster behält den Basisnamen). **Name für den Kunden** = `produkt.kundenname` (z. B. „Super Zink", leer = interner Name) – erscheint überall beim Kunden (Katalog, Produktdetail, Angebote, Bestellungen, Angebots-PDF, PPWR). Portal-Queries nutzen `COALESCE(NULLIF(kundenname,''), name)`; intern bleibt `name`.

**Zusammenhang:** Die Produktkosten (inkl. kompletter Verpackung) sind die Basis, auf die im **Angebot** die Marge kommt. Die Tages-Deklaration ist die Etikett-Angabe „pro Tagesdosis".
