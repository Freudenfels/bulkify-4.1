# lieferant/detail.php – Lieferantenkonto (Cockpit) & Bearbeiten

## Zugang zum Lieferantenportal
Panel unten auf der Seite: **Einladungslink erzeugen** (`lieferant_einladung`, gilt einmal) und an den Lieferanten schicken – er legt Zugang und Passwort selbst an. Besteht schon ein Zugang, stehen dort Benutzer, E-Mail und letzter Login.

## Preisanfragen
Das Formular fragt zuerst, **was** angefragt wird (Rohstoff, Fertigprodukt, Verpackung, Verbrauch, Sonstiges). Je nach Art kommt die Form dazu (Fertigprodukt: Darreichungsform; Rohstoff: Lieferform), beim Fertigprodukt zusätzlich Einheiten je Packung, Kapselgröße und optional eine Rezeptur als Vorlage. Die **Einheit füllt sich selbst** – aus dem gewählten Artikel oder der Darreichungsform (`anfrage_einheit()`); sie lässt sich überschreiben. Die Artikel-Auswahl enthält auch **Fertigprodukte** (`kategorie=fertig`), nicht nur Rohstoffe und Verpackungen.

Anfrage an diesen Lieferanten stellen: **Artikel** (dann landen die Preise beim Annehmen automatisch als EK-Staffeln dort) oder Freitext, dazu Menge, Einheit, Notiz und ob CoA/Spezifikation mitkommen sollen. Darunter die Liste aller Anfragen mit der Antwort des Lieferanten (Preis, MOQ, Lieferzeit, Staffeln) und dem Knopf **Preise übernehmen** – der ersetzt die bisherigen EK-Staffeln dieses Lieferanten für den Artikel.

**Zweck:** Die 360°-Lieferantenseite – gleiches Muster wie das Kundenkonto, aber mit einkaufs-typischen Feldern.

**Was passiert hier:**
- **Speichern (POST):** prüft Pflichtfeld Firma, schreibt alle Felder in `lieferanten` (neu = INSERT + Verlaufseintrag „Lieferant angelegt", sonst UPDATE).
  - **Liefer-Kategorien** kommen aus Häkchen (`kat[...]`) und werden als CSV gespeichert (z. B. `rohstoff,verpackung`).
  - Sperr-Schalter und Kategorien werden gesondert in die Werte gesetzt.
- **Anzeige (GET):** lädt den Lieferanten und seinen Verlauf (`verlauf_fuer('lieferant', id)`).

**Die Reiter:**
- **Übersicht** – Kontakt, Liefer-Kategorien, letzte Preise/Bestellungen (Platzhalter bis Module stehen).
- **Preise / Angebote · Bestellungen · Dokumente** – Gerüst (`bx_bald()`), docken an, sobald die Module stehen.
- **Rechnungen** – Einkaufs-/Zahlungssicht: je Auftrag Betrag, Rechnung und Ampel-Status **bezahlt / offen / überfällig / keine Rechnung** (Auftrag ohne Rechnung). Aktuell beschriftete Vorschau mit Beispieldaten; echte Werte kommen aus Bestellungen + Buchhaltung.
- **Verlauf** – Chat (`bx_chat`): links wir, rechts Lieferant, Einträge klickbar.
- **Stammdaten** – Lief.-Nr., Firma, Ansprechpartner, E-Mail, Telefon, Sprache (DE/EN/ZH), Webseite, Sperr-Schalter, Liefer-Kategorien (Häkchen), Notiz.
  - **Liefer-Kategorien:** Rohstoff, Verpackung, Verbrauch, Maschine, Labor, **Fertige Produkte**. „Fertige Produkte" = fertig gefüllte Ware (Kapseln/Softgels/Sticks) – da kaufen wir das Endprodukt, keinen Rohstoff.
  - Ist „Fertige Produkte" angehakt, klappt eine **Formen-Auswahl** auf (Kapsel/Tablette/Softgel/Stick/Pulver/Flüssig) → so lassen sich spezialisierte Hersteller abbilden (z. B. reiner Softgel-Hersteller). Gespeichert in `fertig_formen` (CSV); wird geleert, wenn „Fertige Produkte" nicht gewählt ist.
- **Adresse** – strukturiert (Straße, Hausnummer, PLZ, Ort, Land, USt-ID).
- **Konditionen** – Währung (EUR/USD/CNY), Zahlungsart, Zahlungsziel, Standard-Lieferzeit, Mindestbestellwert.

**Kennzahlen-Kacheln:** Status, Bestellungen (folgt), Währung, Ø Lieferzeit.

**Technik-Hinweis:** Die Stammdaten-Reiter liegen in einem Formular; ein kleines JavaScript blendet den aktiven Reiter ein. Die Reiter **Dokumente** und **Rückfragen** liegen außerhalb dieses Formulars (sie haben eigene Formulare), werden aber von derselben Reiter-Logik geschaltet; ein Link mit `#dok` oder `#rueckfragen` öffnet den Reiter direkt.

**Muster:** wie `kunde/detail.php`. Der Verlauf teilt sich die zentrale Tabelle `aktivitaet` (über `objekt_typ='lieferant'`).

## Dokumente (Dateiablage)
Der Reiter **Dokumente** ist die gemeinsame Ablage mit dem Lieferanten (`lieferant_dateien_panel()` aus `core/lieferant_dateien.php`): Zertifikate, Spezifikationen, CoA, Sonstiges – von uns oder vom Lieferanten hochgeladen, dazu seine CoA/Spezifikationen aus Preisanfragen. POST `aktion=dok_upload` / `dok_del`.

## Rückfragen
Der Reiter **Rückfragen** (mit Zahl ungelesener Nachrichten) zeigt das ganze Gespräch mit dem Lieferanten (`nachricht_panel()` aus `core/nachricht.php`), jede Nachricht mit Bezug-Link zur Bestellung oder Preisanfrage. POST `aktion=nachricht` (ohne Bezug); zu einer Bestellung schreibt man besser direkt an der Bestellung.
