# partner/detail.php – Partnerkonto (Cockpit) & Bearbeiten

**Zweck:** Die 360°-Partnerseite. Ein Partner ist **Hybrid**: er kauft bei uns (Kunden-Seite) und fertigt für uns (Lieferanten-Seite). Deshalb vereint diese Seite Konditionen aus beiden Welten – und statt Marken hat der Partner **SubKunden**.

**Was passiert hier:**
- **Speichern (POST):** prüft Pflichtfeld Firma, schreibt alle Felder in `partner` (neu = INSERT + Verlaufseintrag, sonst UPDATE).
  - Liefer-Kategorien + „Fertige Produkte"-Formen wie beim Lieferanten (CSV).
  - **SubKunden** werden synchronisiert (alte löschen, neue nicht-leere Zeilen einfügen) in `partner_subkunde`.
- **Anzeige (GET):** lädt Partner, SubKunden und Verlauf (`verlauf_fuer('partner', id)`).

**Die Reiter:**
- **Übersicht** – Kontakt, was er liefern/fertigen kann, und die SubKunden als Badges.
- **SubKunden** – die Kunden **des** Partners: Name + Kürzel, per „+" beliebig viele. Das Kürzel hilft später in der Produktion, die Endkunden auseinanderzuhalten.
- **Als Kunde** – seine Anfragen/Angebote/Bestellungen bei uns (Gerüst).
- **Als Lieferant** – unsere Anfragen/Bestellungen/Rechnungen an ihn (Gerüst).
- **Verlauf** – Chat (`bx_chat`); Kunden- und Lieferanten-Aktionen in einem Faden.
- **Stammdaten** – Partnernr., Firma, Ansprechpartner, Sprache, Webseite, Sperr-Schalter, Notiz.
- **Adresse** – strukturiert (Straße, Hausnummer, PLZ, Ort, Land, USt-ID).
- **Konditionen** – zwei Panels: **Als Kunde** (Zahlungsart, -ziel, Margen-Rabatt/Aufschlag) und **Als Lieferant** (Kategorien inkl. Formen, Währung, Zahlungsziel, Lieferzeit, Mindestbestellwert).

**Kennzahlen-Kacheln:** Status · SubKunden (Anzahl) · Umsatz als Kunde · Einkauf als Lieferant · Offene Rechnungen (die drei Geldwerte folgen mit den Modulen).

**Technik-Hinweis:** Alle Reiter in einem Formular; ein kleines JavaScript blendet Reiter ein, fügt SubKunden-Zeilen per „+" hinzu und klappt die Formen-Auswahl auf, wenn „Fertige Produkte" gewählt ist.
