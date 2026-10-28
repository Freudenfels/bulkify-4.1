# module/einkauf/lief_anfragen.php – Lieferanten-Anfragen & Preise

Route `?p=lief_anfragen` (Rollen einkauf/finance, admin). Zentrale Übersicht **aller** an Lieferanten
gestellten Anfragen (RFQ) inkl. des vom Lieferanten abgegebenen Preises.

## Warum
Der Preis einer Fremdfertigungs-Anfrage (auch zu einer noch NICHT vom Kunden freigegebenen Rezeptur)
steht in `lieferant_angebot` (eine Offerte je `lieferant_anfrage`, + `lieferant_angebot_staffel`). Bisher
war er nur verstreut sichtbar: im Rezeptur-Detail (Panel „Fremdfertigung angefragt bei") und im
Lieferant-Detail (Reiter „Preise / Angebote"). Diese Seite zeigt alles an **einer** Stelle.

## Ansicht
Oben **Typ-Chips** (`&typ=`): Alle Typen / Rohstoffe (`art='rohstoff'`) / Fertigprodukte (`art='fertigprodukt'`) –
Zähler je Typ. Darunter die Reiter **Alle / Preis ausstehend / Preis da**, Suche (Lieferant/Rezeptur/Nr.). Spalten: Nr., Lieferant,
**Was** (Fremdfertigung → Link zur Rezeptur, Rohstoff → Link zum Rohstoff, sonst Freitext), Menge,
**Angebotspreis** (aus `lieferant_angebot`, mit Währung/Einheit, Mindestmenge, Lieferzeit), Status, Datum.
Rein lesend – Angebote erfassen/annehmen weiterhin im Lieferant-/Rezeptur-Detail.

## Link & Kunde (Fix)
- Die „Was"-Spalte verlinkt Fremdfertigungs-Anfragen auf **`?p=rezeptur_detail&id=`** (nicht `?p=rezeptur`, das ist die Rezeptur-LISTE und ignoriert die id → führte „ins Nichts").
- Bei Fremdfertigung steht unter dem Rezepturnamen der **Kunde** (aus `rezeptur.kunde_id`), damit man ohne Klick sieht, für wen angefragt wurde. Die Suche findet Anfragen jetzt auch über den **Kundennamen** (`kz.firma`).
- Die „Was"-Spalte zeigt bei Fremdfertigung immer die **Rezepturnummer** mit (`RZ-… · Name`), nicht nur den Namen (gleich im Detail `lief_anfrage_detail.php`).
- **Rückfragen des Lieferanten** landen als `nachricht` an der jeweiligen Anfrage/Bestellung (Panel „Rückfragen" im Detail). Seit 2026-10: schreibt der Lieferant, legt `nachricht_senden()` (`core/nachricht.php`) zusätzlich eine **Dashboard-Benachrichtigung** an (Team-Postfach `?p=benachrichtigungen`, mit Deep-Link zur Anfrage/Bestellung) – vorher war die Frage nur im jeweiligen Detail sichtbar.

## Zurückgezogene Anfragen
Wird einem Lieferanten die **Rohstoff-Berechtigung** entzogen (Kategorie `rohstoff` abgewählt, s. `module/lieferant/detail.php`), setzt `lieferant_rohstoff_anfragen_zurueckziehen()` (`core/schema.php`) alle noch **offenen** Rohstoff-Anfragen dieses Lieferanten auf Status `zurueckgezogen` (kein Löschen – Historie bleibt). Hier: eigenes Badge **„zurückgezogen"**, und sie zählen/zeigen **nicht** mehr unter „Preis ausstehend".

## E1 – Massenanfragen bereinigen (2026-10-28)
Oben (nur Admin) ein Panel „Massenanfragen aufräumen": listet Lieferanten mit **≥ 20 offenen, unbeantworteten** Anfragen (`lieferanten_mit_massenanfragen()`), je Zeile ein Button „N offene bereinigen" (POST `aktion=anfragen_bereinigen`). Löscht über `lieferant_anfragen_bereinigen()` **nur** `lieferant_anfrage`-Zeilen ohne Angebot und mit offenem Status – Rohstoffe, Angebote und beantwortete Anfragen bleiben.
