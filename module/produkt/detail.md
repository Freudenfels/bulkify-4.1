# produkt/detail.php – Produkt anlegen & bearbeiten (mit Kalkulation)

**Zweck:** Führt die Stammdaten zum verkaufbaren **Produkt (SKU)** zusammen: Rezeptur + Verpackung + Kunde. Rechnet Kosten pro Packung, Reichweite und die Tages-Deklaration – die Vorstufe zum Angebot.

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
  - **Flüssig** → Füllvolumen (ml, = Einheiten) gegen Volumen der Flasche.
- **Nährwerte pro Tagesdosis:** je Nährstoff die Menge × Verzehr/Tag und **% NRV** (z. B. bei 2 Kapseln/Tag: Magnesium 240 mg = 64 %).

**Katalog / Exklusiv:** Ein Produkt liegt standardmäßig im **gemeinsamen Katalog** (Häkchen `exklusiv` aus). Exklusiv = nur für den gewählten Kunden sichtbar. Der Kunde ist also nur bei Exklusiv-Produkten relevant.

**Preis-Matrix (Panel unten):** „Matrix neu berechnen" erzeugt über `produkt_matrix_generieren()` die VK-Tabelle **Stückzahl × passende Verpackung × Bestellmenge** (Zeilen = Stück+Verpackung, Spalten = Bestellmengen; je Zelle VK + kleiner EK). Basis-VK ohne Kundenrabatt – schnelle interne Sale-Auskunft. Nur für Kapselprodukte mit Rezeptur + hinterlegter Behälter-Fassung.

**Dokumente (CoA & Spezifikation):** Panel unter der Preis-Matrix (nur bei bestehendem Produkt) – gemeinsame Komponente `core/dokument_ui.php` (Tabelle `dokument`, objekt_typ=produkt): CoA/Spec/Analyse hochladen, je Dokument optional mit **Lieferant**; Download über Route `dokument`, Löschen inline.

**Zwei Namen:** **Produktname (intern)** = `produkt.name` (unser Arbeitsname, z. B. „Zink") – heißen mehrere Produkte gleich, hängt `produkt_name_versioniert()` beim Speichern automatisch „ v2, v3 …" an (case-insensitiv, erster behält den Basisnamen). **Name für den Kunden** = `produkt.kundenname` (z. B. „Super Zink", leer = interner Name) – erscheint überall beim Kunden (Katalog, Produktdetail, Angebote, Bestellungen, Angebots-PDF, PPWR). Portal-Queries nutzen `COALESCE(NULLIF(kundenname,''), name)`; intern bleibt `name`.

**Zusammenhang:** Die Produktkosten (inkl. kompletter Verpackung) sind die Basis, auf die im **Angebot** die Marge kommt. Die Tages-Deklaration ist die Etikett-Angabe „pro Tagesdosis".
