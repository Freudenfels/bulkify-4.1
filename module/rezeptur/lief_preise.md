# module/rezeptur/lief_preise.php – Rezeptur-Preise (Fremdfertigung)

Gesamtübersicht aller **Lieferanten-Angebote je Rezeptur** – das V4-Gegenstück zur V3-Seite
`rezept_preise.php`, aber mit **Verlinkung zur Rezeptur und zum Produkt** in V4.

## Quelle
`rezeptur_lief_angebot` (u. a. aus dem V3-Import, Stufe 4 – `tools/v3_import.php`). Join auf
`rezeptur` (Name, Darreichungsform) und `lieferanten` (Firma). Es sind **Kapsel-/Herstellpreise je
Rezeptur** (Fremdfertigung) – keine Endprodukte, daher bewusst **keine Produkt-Spalte**.

## Seite
- Route `?p=rezept_preise` (Menü **Produkt → Rezeptur-Preise**). Rollen: production, einkauf, labor.
- Spalten: Nr. (rezeptur.nummer), Rezeptur (Link → `?p=rezeptur_detail&id=`), Form, Lieferant,
  Preis (4 Nachkommastellen €, nicht fett), Einheit, **Menge = Staffel** (Mengenstaffel je Preis).
  Mehrere Zeilen je Rezeptur = die Staffeln; sortiert nach Rezeptur, dann Menge. Kein Status.
- Suche über Rezeptur- oder Lieferantenname; Schalter **„nur mit Preis"** (blendet 0/leer aus).
- Sortierung: Rezepturname, dann Angebote mit echtem Preis zuerst. Limit 2.000.

## Abgrenzung
- Erfassen/Bearbeiten einzelner Angebote läuft weiter im Panel „Lieferanten-Angebote (Fremdfertigung)"
  auf der Rezeptur-Detailseite (`module/rezeptur/detail.php`).
- Nicht zu verwechseln mit **EK-Preisliste** (`lief_preisliste`, Rohstoff-EK je kg) und
  **Lieferanten-Preise** (`lieferant_preise`, strukturierte Rohstoff↔Lieferant-Preise für die Kalkulation).
