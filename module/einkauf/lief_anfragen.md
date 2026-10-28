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

## E6 – Angebotsvergleich nach Produkt (2026-10-28)
Umschalter „Liste / Vergleich (nach Produkt)" (`?p=lief_anfragen&ansicht=vergleich`). Vergleich gruppiert alle Anfragen MIT Angebot nach Produkt/Rohstoff (Rezeptur-, Item- oder Betreff-Schlüssel) und zeigt je Gruppe die Lieferantenangebote nebeneinander: Lieferant · Variante/Gehalt · Kapselgröße (+ Vorschlag E4) · Preis ab Menge (Staffel) · MOQ · Lieferzeit · Spec (da/fehlt). Günstigster Preis zuerst. Je Angebot „Preise übernehmen" (POST `aktion=angebot_annehmen` → `lieferant_angebot_annehmen`), setzt die EK-Staffel am Artikel/Produkt.

## E8 – Lieferantenrechnung bezahlen (an der Bestellung)
Die Zahlung steht auf der Bestelldetailseite (`module/einkauf/detail.php`, Panel „Zahlung"): Download der vom Lieferanten hochgeladenen Rechnung, „Als bezahlt markieren" mit Datum + Zahlungsweg + optionaler Überweisungsbestätigung (`bestellung_bezahlt_markieren()` → `bestellung.bezahlt_am/zahlungsweg` + `dokument` typ='zahlungsbeleg'). Der Lieferant sieht im Portal (`module/lieferant/bestellung.php`) den Status „bezahlt am …" und kann die Überweisungsbestätigung herunterladen (nicht löschen). Noch NICHT mit Buchhaltung/OP-Liste verknüpft.
