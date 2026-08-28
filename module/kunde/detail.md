# kunde/detail.php – Kundenkonto (Cockpit) & Bearbeiten

**Zweck:** Die 360°-Kundenseite: oben Kennzahlen, dann Reiter für Übersicht, Vorgänge und alle Stammdaten. Dient sowohl dem schnellen Überblick als auch dem Anlegen/Bearbeiten.

**Was passiert hier:**
- **Speichern (POST):** prüft Pflichtfeld Firma, schreibt alle Felder in `kunden` (neu = INSERT, sonst UPDATE). Danach:
  - **Marken & Webseiten** werden synchronisiert (alte löschen, neue nicht-leere Zeilen einfügen) in `kunde_marke`.
  - Bei neuem Kunden wird ein Verlaufseintrag „Kunde angelegt" geschrieben (`log_aktivitaet`).
  - Leitet zurück zur Seite mit Bestätigung.
- **Anzeige (GET):** lädt den Kunden, seine Marken und den Verlauf (`verlauf_fuer('kunde', id)`).

**Die Reiter:**
- **Übersicht** – Kontakt, Marken & Webseiten, letzte Vorgänge (Platzhalter bis Module stehen).
- **Angebote / Bestellungen / Rezepturen** – zeigen die **echten Vorgänge** des Kunden (Angebote, Aufträge + Rechnungen, Produkte + Rezepturen), alle anklickbar zum jeweiligen Detail. **Dokumente** noch Platzhalter.
- **Kennzahlen** sind real: Umsatz gesamt (Σ Rechnungs-Netto), Offene Posten (Σ Brutto offener Rechnungen), Bestellungen (Anzahl Aufträge).
- **Verlauf** – der Chat (`bx_chat`): links wir, rechts Kunde, Einträge klickbar zum verknüpften Objekt.
- **Stammdaten** – Kundennr., Firma, Ansprechpartner, E-Mail, Telefon, Sperr-Schalter, Notiz, Marken-Zeilen (+).
- **Adressen** – Haupt-, Rechnungs-, Lieferadresse, jeweils strukturiert (Straße, Hausnummer, PLZ, Ort, Land) – wegen DHL.
- **Zahlung & Konditionen** – Zahlungsart, -ziel, Rabatt-/Aufschlag-Marge.
- **Portal-Einstellungen** – **Freischaltungen** je Kunde (`portal_rezeptur/produkte/rohstoffe/dienstleistung`): welche Anfrage-Bereiche der Kunde im Portal sieht. Steuert das dynamische Portal-Menü. Dazu der Portal-Link (Magic-Link).

**Kennzahlen-Kacheln:** Status, Umsatz, Offene Posten, Bestellungen, Kunde seit (Umsatz/Posten/Bestellungen folgen mit den Modulen).

**Technik-Hinweis:** Alle Reiter liegen in **einem** Formular; ein kleines JavaScript blendet den aktiven Reiter ein und fügt Marken-Zeilen über „+" hinzu.

**Regel / Muster:** Vorlage für weitere Detail-/Konto-Seiten. Neue Vorgangs-Reiter docken an, indem ihr Modul beim Speichern `log_aktivitaet(...)` mit `ref_typ`/`ref_id` aufruft – dann ist der Verlaufseintrag automatisch klickbar.
