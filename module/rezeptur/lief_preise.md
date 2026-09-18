# module/rezeptur/lief_preise.php – Rezeptur-Preise (Fremdfertigung)

Gesamtübersicht aller **Lieferanten-Angebote je Rezeptur** – das V4-Gegenstück zur V3-Seite
`rezept_preise.php`, aber mit **Verlinkung zur Rezeptur und zum Produkt** in V4.

## Quelle
`rezeptur_lief_angebot` (u. a. aus dem V3-Import, Stufe 4 – `tools/v3_import.php`). Join auf
`rezeptur` (Name, Darreichungsform) und `lieferanten` (Firma). Das verknüpfte V4-Produkt kommt über
`produkt.rezeptur_id` (eine Rezeptur kann mehrere Produkte haben → erstes verlinkt, Rest als „+n").

## Seite
- Route `?p=rezept_preise` (Menü **Produkt → Rezeptur-Preise**). Rollen: production, einkauf, labor.
- Spalten: Rezeptur (Link → `?p=rezeptur_detail&id=`), Form, Produkt (Link → `?p=produkt&id=`),
  Lieferant, Preis (4 Nachkommastellen €), Einheit, Menge, Status (+ angenommen_am).
- Suche über Rezeptur- oder Lieferantenname; Schalter **„nur mit Preis"** (blendet 0/leer aus).
- Sortierung: Rezepturname, dann Angebote mit echtem Preis zuerst. Limit 2.000.

## Abgrenzung
- Erfassen/Bearbeiten einzelner Angebote läuft weiter im Panel „Lieferanten-Angebote (Fremdfertigung)"
  auf der Rezeptur-Detailseite (`module/rezeptur/detail.php`).
- Nicht zu verwechseln mit **EK-Preisliste** (`lief_preisliste`, Rohstoff-EK je kg) und
  **Lieferanten-Preise** (`lieferant_preise`, strukturierte Rohstoff↔Lieferant-Preise für die Kalkulation).
