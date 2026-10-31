# pdf_pib.php – Produktinformationsblatt (PIB)

## Wozu
Stellt dem Kunden ein **Produktinformationsblatt** bereit, damit er sein **Etikett gestalten** kann. Zwei Quellen, mit klarer Rangfolge:
1. **Hochgeladenes PIB** (Team, je Produkt) – hat Vorrang.
2. **Auto-PIB** – wird sonst automatisch aus den vorhandenen Produktdaten erzeugt.

**Kundensicher:** Das PIB darf NIE auf Zukauf/Bulk hinweisen (siehe Memory „Kunde kein Zukauf verraten"). Es enthält nur die label-relevanten Angaben.

**Echtheits-/Qualitäts-QR (Produktebene):** Das Auto-PIB (`pib_pdf_render`, nur bei echtem Produkt mit `produkt_id>0`) bekommt am Ende über `spec_qr_panel()` ein Feld „Echtheit online prüfen" mit QR-Code. Der QR führt auf die öffentliche Produktseite `public/echt.php?p=<token>` (Token aus `produkt_echt_url()` in `core/echtheit.php`), die das Produkt bestätigt und seine ausgestellten Chargen listet. Fehlt der Link, bleibt das PIB unverändert.

## Funktionen
- `pib_pdf_bauen($produkt_id)` – erzeugt das Auto-PIB als PDF-Bytes (MiniPDF + die `spec_*`-Layouthelfer aus `pdf_spec.php`). Inhalt: Identität (Produkt, Darreichungsform, Einheiten pro Packung, Stand), **Zutaten je Einheit**, **Nährwert-/Wirkstoffdeklaration** (je Einheit, mit % NRV), plus Pflichtangaben-Hinweis für die Etikettengestaltung.
- `pib_naehr($rezeptur_id)` – aggregiert die Wirkstoffe der Rezeptur (rezeptur_zutat × item_wirkstoff × naehrstoff) zu Nährwerten je Einheit. Nutzt die zentrale `wirkstoff_mg_je_mg()`, deckt also auch **I.E.-basierte** Gehalte (Vitamin D/A/E) ab; die Nährwerttabelle zeigt neben mg/µg zusätzlich die **I.E.** und die **Etiketteinheit** (µg RE, mg α-TE …), wo am Nährstoff hinterlegt. Core-lokal (die Portal-Funktion `pt_naehr` ist portal-lokal).
- `pib_datei($produkt_id)` – das vom Team hochgeladene PIB (dokument `objekt_typ='produkt'`, `typ='pib'`), sonst null.
- `pib_upload($produkt_id, $feld='pib')` / `pib_del($produkt_id)` – Upload/Entfernen (PDF/PNG/JPG).
- `pib_ausliefern($produkt_id, $dateiname)` – setzt Header + gibt das PIB aus: hochgeladenes (Vorrang) sonst Auto-PIB. `false`, wenn das Produkt fehlt.

## Wer nutzt es
- **Kundenportal** (`module/portal/kunde.php`): Route `v=pib&aid=` (nur eigener Auftrag) → `pib_ausliefern()`. Button „Produktinformationsblatt (PIB) herunterladen" auf der Bestellungs-Ansicht, neben dem Etikett-Upload.
- **Intern** (`module/produkt/detail.php` + Route `produkt_pib`): PIB ansehen/hochladen/entfernen je Produkt.

## Grenzen
Das Auto-PIB kennt nur, was die App hat (Rezeptur/Nährwerte/Darreichung). COA-/Laborwerte, Garantie-Texte und exakte Pflichtangaben sind NICHT enthalten – dafür ein fertiges PIB (z. B. aus der Skill `bulkify-produktinfo`) hochladen.

## Rezepturbasiertes PIB (ohne Produkt)
Der Render-Körper steckt in `pib_pdf_render(array $prod, int $produkt_id, …)`. `pib_pdf_bauen($produkt_id)` lädt das Produkt und delegiert; `pib_pdf_bauen_rezeptur($rezeptur_id)` baut ein minimales $prod aus der Rezeptur (rez_id, Form, Name; Verpackung/Einheiten/Leerkapsel leer) und rendert ohne Produkt. Produktspezifische Teile (Behälter-Fallback aus Auftrag, Novel-Food-Snapshot) laufen nur bei `produkt_id>0`. Genutzt von der Route `rezeptur_pib` (module/rezeptur/pib.php).

## Aufschlüsselung je Form (elementarer Gehalt)
Die Zutaten-Tabelle zeigt je Verbindung/Form zusätzlich „– davon <Nährstoff>" mit dem elementaren Gehalt (z. B. Magnesiumbisglycinat → davon Magnesium). Datenquelle: `item_wirkstoff` je Zutat (`$zutWirk`, Menge × `wirkstoff_mg_je_mg()`), analog zu `pib_naehr()`, nur pro Zutat statt summiert. Die summierten Gesamtwerte je Nährstoff stehen weiterhin in der „Nährwert-/Wirkstoffdeklaration". Formen ohne hinterlegten Wirkstoffgehalt bekommen keine „davon"-Zeile.
