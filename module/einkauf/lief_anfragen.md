# module/einkauf/lief_anfragen.php – Lieferanten-Anfragen & Preise

Route `?p=lief_anfragen` (Rollen einkauf/finance, admin). Zentrale Übersicht **aller** an Lieferanten
gestellten Anfragen (RFQ) inkl. des vom Lieferanten abgegebenen Preises.

## Warum
Der Preis einer Fremdfertigungs-Anfrage (auch zu einer noch NICHT vom Kunden freigegebenen Rezeptur)
steht in `lieferant_angebot` (eine Offerte je `lieferant_anfrage`, + `lieferant_angebot_staffel`). Bisher
war er nur verstreut sichtbar: im Rezeptur-Detail (Panel „Fremdfertigung angefragt bei") und im
Lieferant-Detail (Reiter „Preise / Angebote"). Diese Seite zeigt alles an **einer** Stelle.

## Ansicht
Reiter **Alle / Preis ausstehend / Preis da**, Suche (Lieferant/Rezeptur/Nr.). Spalten: Nr., Lieferant,
**Was** (Fremdfertigung → Link zur Rezeptur, Rohstoff → Link zum Rohstoff, sonst Freitext), Menge,
**Angebotspreis** (aus `lieferant_angebot`, mit Währung/Einheit, Mindestmenge, Lieferzeit), Status, Datum.
Rein lesend – Angebote erfassen/annehmen weiterhin im Lieferant-/Rezeptur-Detail.

## Link & Kunde (Fix)
- Die „Was"-Spalte verlinkt Fremdfertigungs-Anfragen auf **`?p=rezeptur_detail&id=`** (nicht `?p=rezeptur`, das ist die Rezeptur-LISTE und ignoriert die id → führte „ins Nichts").
- Bei Fremdfertigung steht unter dem Rezepturnamen der **Kunde** (aus `rezeptur.kunde_id`), damit man ohne Klick sieht, für wen angefragt wurde. Die Suche findet Anfragen jetzt auch über den **Kundennamen** (`kz.firma`).
