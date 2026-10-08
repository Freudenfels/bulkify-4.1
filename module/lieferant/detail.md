# lieferant/detail.php – Lieferantenkonto (Cockpit) & Bearbeiten

## Reiter „Portalzugang" (Zugang + vom Lieferanten gepflegt)
Der eigene Reiter **Portalzugang** bündelt: **Einladungslink erzeugen** (`lieferant_einladung`, gilt einmal) und an den Lieferanten schicken – er legt Zugang und Passwort selbst an; besteht schon ein Zugang, stehen dort Benutzer, E-Mail und letzter Login. Über den Knöpfen steht, **in welcher Sprache** Einladung und Portal laufen (aus `lieferanten.sprache`). Ebenfalls hier: was der Lieferant selbst gepflegt hat (Logo, WeChat, WhatsApp).

> **Wichtig (Reiter-Struktur):** Diese Blöcke (Portalzugang) und der Block **Preisanfragen** lagen früher als nackte Panels **außerhalb** aller `data-panel`-Sections und erschienen dadurch auf **jedem** Reiter. Jetzt sind sie in Sections gekapselt: „Vom Lieferanten gepflegt" + „Zugang" → `data-panel="zugang"` (Reiter Portalzugang), „Preisanfragen" → `data-panel="angebote"` (Reiter Preise / Angebote). Beim Einfügen neuer Panels darauf achten, dass sie in einer `<section data-panel="…">` stehen.

## Preisanfragen (im Reiter „Preise / Angebote")
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
- **Portalzugang** – Einladung/Zugang zum Lieferantenportal + vom Lieferanten gepflegte Angaben (Logo, WeChat, WhatsApp).
- **Verlauf** – Chat (`bx_chat`): links wir, rechts Lieferant, Einträge klickbar.
- **Stammdaten** – Lief.-Nr., Firma, Ansprechpartner, E-Mail, Telefon, Sprache (DE/EN/ZH), Webseite, Sperr-Schalter, Liefer-Kategorien (Häkchen), Notiz.
  - **Liefer-Kategorien:** Rohstoff, Verpackung, Verbrauch, Maschine, Labor, **Fertige Produkte**. „Fertige Produkte" = fertig gefüllte Ware (Kapseln/Softgels/Sticks) – da kaufen wir das Endprodukt, keinen Rohstoff.
  - Ist „Fertige Produkte" angehakt, klappt eine **Formen-Auswahl** auf (Kapsel/Tablette/Softgel/Stick/Pulver/Flüssig) → so lassen sich spezialisierte Hersteller abbilden (z. B. reiner Softgel-Hersteller). Gespeichert in `fertig_formen` (CSV); wird geleert, wenn „Fertige Produkte" nicht gewählt ist.
- **Adresse** – strukturiert (Straße, Hausnummer, PLZ, Ort, Land, USt-ID).
- **Konditionen** – Währung (**USD Standard**, EUR, CNY), Zahlungsart, Zahlungsziel, Standard-Lieferzeit, Mindestbestellwert. Die Währung wird bei der Anlage gesetzt; ein neuer Lieferant startet auf **USD** (die meisten Lieferanten rechnen in USD).

**Kennzahlen-Kacheln:** Status, Bestellungen (folgt), Währung, Ø Lieferzeit.

**Technik-Hinweis:** Die Stammdaten-Reiter liegen in einem Formular; ein kleines JavaScript blendet den aktiven Reiter ein. Die Reiter **Dokumente** und **Rückfragen** liegen außerhalb dieses Formulars (sie haben eigene Formulare), werden aber von derselben Reiter-Logik geschaltet; ein Link mit `#dok` oder `#rueckfragen` öffnet den Reiter direkt.

**Muster:** wie `kunde/detail.php`. Der Verlauf teilt sich die zentrale Tabelle `aktivitaet` (über `objekt_typ='lieferant'`).

## Dokumente (Dateiablage)
Der Reiter **Dokumente** ist die gemeinsame Ablage mit dem Lieferanten (`lieferant_dateien_panel()` aus `core/lieferant_dateien.php`): Zertifikate, Spezifikationen, CoA, Sonstiges – von uns oder vom Lieferanten hochgeladen, dazu seine CoA/Spezifikationen aus Preisanfragen. POST `aktion=dok_upload` / `dok_del`.

## Rückfragen
Der Reiter **Rückfragen** (mit Zahl ungelesener Nachrichten) zeigt das ganze Gespräch mit dem Lieferanten (`nachricht_panel()` aus `core/nachricht.php`), jede Nachricht mit Bezug-Link zur Bestellung oder Preisanfrage. POST `aktion=nachricht` (ohne Bezug); zu einer Bestellung schreibt man besser direkt an der Bestellung.

## Alles auf einmal anfragen
Im Reiter **Katalog** steht oben der Kasten **Alles auf einmal anfragen**: Ist ein Lieferant neu, bekommt er mit einem Klick alle Rohstoffe aus unseren Rezepturen (fuer Haendler) oder alle Rezepturen als Fertigprodukt (fuer Lohnhersteller) als Preisanfrage. Schon Angefragtes wird uebersprungen, es entstehen keine Dubletten. Details: `core/sammelanfrage.md`.

## Katalog
Der Reiter **Katalog** zeigt, was der Lieferant in seinem Portal hinterlegt oder als Preisliste hochgeladen hat (`core/lieferant_katalog.md`). **Preisliste einlesen** (nur bei eingerichteter KI) macht dasselbe von unserer Seite: Wer uns eine Liste per Mail schickt, muss sie nicht selbst hochladen - die Datei landet in der Ablage, die KI macht Zeilen daraus. Angelegt wird auch hier nichts von allein. Je Zeile steht **Anlegen** (neuer Artikel samt EK-Preis) oder – wenn es den Artikel über CAS-Nummer oder Namen schon gibt – **Preis dorthin**, dazu **ablehnen**. Für lange Kataloge gibt es „Alle übernehmen". Die Zahl am Reiter sind die noch offenen Zeilen.

## Preise / Angebote und Rechnungen (Reiter)
Der Reiter **Preise / Angebote** (und das Übersichts-Panel) zeigt jetzt die **echten** Angebote des Lieferanten aus den Preisanfragen (`$l_angTabelle`: Nummer, Artikel/Betreff, Preis je Einheit bzw. je 1.000, Lieferbedingung, Status übernommen/offen). Neue Preise holt man weiter unten im Bereich **Preisanfragen** ein. Der Reiter **Rechnungen** trägt keine Beispieldaten mehr – bis die Lieferanten-Rechnungserfassung angebunden ist, steht dort ein ehrlicher Platzhalter samt Verweis auf „Bestellungen" (und den Einkauf-Gesamtwert).

## Keine Anfragen + Shop-Zugang
- **Haken „Keine Anfragen senden"** (`lieferanten.keine_anfragen`): z. B. Onlineshops (Buxtrade), bei denen direkt gekauft wird. Solche Lieferanten erscheinen NICHT mehr im Preisanfrage-Popup (alle `anfrage_modal`-Listen + `preis_anfragen`-Validierung filtern `COALESCE(keine_anfragen,0)=0`).
- **Shop-Zugang** (`shop_login`, `shop_passwort`, + `webseite`): gemeinsamer Team-Login für den Shop, damit jeder Mitarbeiter bestellen kann. „anzeigen"-Knopf blendet das Passwort ein, „Zum Shop" öffnet die Webseite. Klartext-Speicherung (nur intern sichtbar) – Hinweis im Formular.

## Bankverbindung
Panel „Bankverbindung" – **formatoffen** (nicht IBAN-fix). Felder `bank_inhaber/bank_name/bank_land/bank_iban/bank_swift/bank_konto/bank_adresse/bank_waehrung/bank_zwischenbank/bank_notiz` an `lieferanten`. Chinesische Lieferanten zahlen oft über Drittland-Banken → SWIFT/BIC + Kontonummer statt IBAN, ggf. Zwischen-/Korrespondenzbank. Der Lieferant kann dieselben Felder selbst im Portal (`lieferant_profil`) pflegen; die **Buchhaltung** liest sie für die Zahlung.

## Fremdfertigungs-Preise im Reiter „Preise / Angebote" (Stand 2026-10-08)
Der Reiter zeigt zusätzlich die **Fremdfertigungs-Preise** dieses Lieferanten aus `rezeptur_lief_angebot` (Panel „Fremdfertigung – Rezepturpreise"). Diese Preise sind dem Lieferanten über `lieferant_id` zugeordnet und erscheinen in `einkauf_preise` unter „Fremdfertigung", wurden auf der Lieferanten-Seite aber bisher nicht angezeigt (nur v4-`lieferant_angebot` + Portal-Preisliste). Betrifft v. a. aus v3 übernommene Preise (z. B. Wellgreen). Read-only; Spalten: Rezeptur (Nr. + Name), Preis/Einheit, ab Menge, Status (angenommen/erfasst), Stand.

## Dublette zusammenführen (Stand 2026-10-08)
Admin-Abschnitt über der Gefahrenzone: einen doppelten Lieferanten (Suchfeld-Dropdown: Firma/Nummer/Land) in DIESEN überführen. POST `aktion=zusammenfuehren` + `quelle_id` → `lieferant_zusammenfuehren(quelle, dieser)`. Für Fälle wie KI-Lesefehler beim Lieferschein (z. B. „VitaActives"/„Vita Actives Limited", „Vitamin B.V."/„Vitanics B.V.", „Packari"/„Packari GmbH", Wellnature/Wellgreen). Alle Preise/Anfragen/Bestellungen/Hauptlieferant-Zuordnungen wandern mit; der Quell-Datensatz wird gelöscht.

## Gelieferte Ware am Lieferanten (Stand 2026-10-08)
Im Reiter „Bestellungen" steht zusätzlich das Panel „Gelieferte Ware (N)" – alle Chargen, die von diesem Lieferanten eingegangen sind (`charge.lieferant_id`). Spalten: Artikel (Rohstoff verlinkt), Charge-Nr, Menge, verfügbar, Wareneingang, MHD, Status (Quarantäne/frei/gesperrt/leer). Read-only.

## Partner: Lieferant darf auch bestellen (Stand 2026-10-08)
Im Reiter **Konditionen** oben das Panel **Partner**. Statt eines eigenen Partner-Datensatzes (Modul `partner` bleibt davon unberührt) bekommt ein bestehender Lieferant hier zusätzliche **Kundenrechte** (Fall „Alex": fragt als Lieferant an UND bestellt wie ein Kunde).
- Haken **„darf auch wie ein Kunde bei uns bestellen (Partner)"** → `lieferanten.ist_partner`.
- Beim Speichern mit Haken: `lieferant_partner_verknuepfen($id)` (in `core/schema.php`) stellt einen verknüpften `kunden`-Datensatz sicher → vorhandene Verknüpfung gewinnt, sonst Kunde gleicher Firma verknüpfen, sonst neu anlegen (Stammdaten aus dem Lieferanten, `naechste_nummer('K')`); `lieferanten.kunde_id` wird gemerkt.
- **Partner-Marge %** wird an **einer** Quelle gehalten: `kunden.rabatt_marge` des verknüpften Kunden (am Lieferanten KEIN eigenes Marge-Feld). So greift die normale Kunden-Preislogik (wirkt auf die Marge, nie unter EK).
- Anzeige: Link zum verknüpften Kunden (`?p=kunde&id=`). Ausschalten lässt die Verknüpfung bestehen, nur das Flag fällt.
- **Phase 1** = nur dieses Fundament (Schalter/Marge/Verknüpfung), noch KEINE Portal-Seiten. Nächster Schritt wäre, dem Lieferantenportal die Kundensicht („Meine Anfragen"/Angebote) auf die `kunde_id` zu geben.
