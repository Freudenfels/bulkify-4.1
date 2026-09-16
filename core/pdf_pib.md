# pdf_pib.php – Produktinformationsblatt (PIB)

## Wozu
Stellt dem Kunden ein **Produktinformationsblatt** bereit, damit er sein **Etikett gestalten** kann. Zwei Quellen, mit klarer Rangfolge:
1. **Hochgeladenes PIB** (Team, je Produkt) – hat Vorrang.
2. **Auto-PIB** – wird sonst automatisch aus den vorhandenen Produktdaten erzeugt.

**Kundensicher:** Das PIB darf NIE auf Zukauf/Bulk hinweisen (siehe Memory „Kunde kein Zukauf verraten"). Es enthält nur die label-relevanten Angaben.

## Funktionen
- `pib_pdf_bauen($produkt_id)` – erzeugt das Auto-PIB als PDF-Bytes (MiniPDF + die `spec_*`-Layouthelfer aus `pdf_spec.php`). Inhalt: Identität (Produkt, Darreichungsform, Einheiten pro Packung, Stand), **Zutaten je Einheit**, **Nährwert-/Wirkstoffdeklaration** (je Einheit, mit % NRV), plus Pflichtangaben-Hinweis für die Etikettengestaltung.
- `pib_naehr($rezeptur_id)` – aggregiert die Wirkstoffe der Rezeptur (rezeptur_zutat × item_wirkstoff × naehrstoff) zu Nährwerten je Einheit. Core-lokal (die Portal-Funktion `pt_naehr` ist portal-lokal).
- `pib_datei($produkt_id)` – das vom Team hochgeladene PIB (dokument `objekt_typ='produkt'`, `typ='pib'`), sonst null.
- `pib_upload($produkt_id, $feld='pib')` / `pib_del($produkt_id)` – Upload/Entfernen (PDF/PNG/JPG).
- `pib_ausliefern($produkt_id, $dateiname)` – setzt Header + gibt das PIB aus: hochgeladenes (Vorrang) sonst Auto-PIB. `false`, wenn das Produkt fehlt.

## Wer nutzt es
- **Kundenportal** (`module/portal/kunde.php`): Route `v=pib&aid=` (nur eigener Auftrag) → `pib_ausliefern()`. Button „Produktinformationsblatt (PIB) herunterladen" auf der Bestellungs-Ansicht, neben dem Etikett-Upload.
- **Intern** (`module/produkt/detail.php` + Route `produkt_pib`): PIB ansehen/hochladen/entfernen je Produkt.

## Grenzen
Das Auto-PIB kennt nur, was die App hat (Rezeptur/Nährwerte/Darreichung). COA-/Laborwerte, Garantie-Texte und exakte Pflichtangaben sind NICHT enthalten – dafür ein fertiges PIB (z. B. aus der Skill `bulkify-produktinfo`) hochladen.
