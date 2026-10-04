# Buchhaltung – eigenes Programm (`/buchhaltung/`)

Kontext- und Arbeitsdatei für den **Buchhaltungs-Chat**. Zuerst lesen, dann loslegen.
Gilt zusätzlich zu `CLAUDE.md` (Projektregeln) – die Regeln dort bleiben.

## ZIEL (Entscheidung Nico, 2026-10-04): Buchhaltung ist ein EIGENES Programm — UMGESETZT
Die Finanzen laufen **getrennt**, **wie CRM/Lager/Produktion** – eigener Pfad `/buchhaltung/`,
eigener Login/eigene Sitzung (BXBUCH), eigene Naht. Die Migration aus dem Dashboard ist erfolgt
(2026-10-04): Front Controller `public/buchhaltung/index.php`, Code in `buchhaltung/core/` +
`buchhaltung/module/`, Naht `buchhaltung/core/erp.php`. Der „Ist-Stand" unten beschreibt weiterhin die
Funktionen/Routen (identische Seiten, jetzt unter `/buchhaltung/?p=…`) und bleibt als Referenz.

**Erledigt (Migrations-Checkliste):**
1. Skelett: `public/buchhaltung/index.php` + `buchhaltung/core/{config,db,auth,ui,layout,erp,schema,finanz}.php`
   (+ Kopien ki/tabelle_lesen/pdf_beleg/pdf_rechnung/pdf_gutschrift/minipdf, + `buchhaltung.php`/`kreditor.php`/`erechnung.php` verschoben).
2. Seiten umgezogen: `module/beleg/*` + `module/buchhaltung/*` → `buchhaltung/module/*`. Finanz-Funktionen
   verbatim in `buchhaltung/core/finanz.php`; Finanz-DDL in `buchhaltung/core/schema.php` (CREATE IF NOT EXISTS,
   Dashboard liest weiter).
3. Dashboard entkoppelt: Finanz-Routen aus `core/auth.php` + `public/index.php` raus (alte Links → 302 auf
   `/buchhaltung/`); „Rechnung erstellen"/Rechnungs-Links in Auftrag/Start/Kundenkonto zeigen auf `/buchhaltung/`;
   Buchhaltungs-Menügruppe entfernt, stattdessen `buchhaltung/` unter „Unterseiten" (Rolle finance/admin).
4. Getestet (`php -l` alle Dateien + curl aller Routen via BXBUCH-Autologin; Dashboard-Entkopplung geprüft).

**Bewusste Doppelung (wie im Spec vorgesehen):** `beleg_firma()`/PDF-Helfer + `ki.php`/`tabelle_lesen.php`
bleiben im Dashboard-`core` (quer genutzt von Spec/Angebot/Portal) UND als Kopie in `buchhaltung/core` (die
Sub-App darf Dashboard-`core/schema.php` nicht einbinden → db()-Kollision). Die Beleg-Lese-Funktionen bleiben
zusätzlich im Dashboard-`core/schema.php` (Auftrag-Detail/Kundenkonto/Portal lesen Belege weiter).

**Zielarchitektur (Muster wie `produktion/`, siehe `PRODUKTION.md`):**
- **Web-Einstieg:** `public/buchhaltung/index.php` (Front Controller, Whitelist `?p=<route>`).
- **Code:** `buchhaltung/core/` + `buchhaltung/module/` (nichts in `public/`). Erreichbar unter `/buchhaltung/`.
- **Eigene Sitzung:** z. B. `BXBUCH` (eigener Login mit den `benutzer`-Logins, Rolle `finance`/`admin`).
- **Aussehen:** lädt `/assets/app.css` wie das Dashboard (gleiche `bx-`-Klassen).
- **DIE NAHT:** `buchhaltung/core/erp.php` – **alle** Lese-Zugriffe auf Dashboard-Tabellen
  (`kunden`, `auftrag`, `lieferant(en)`, `bestellung`, `benutzer`, `app_meta`) laufen ausschließlich hier.
- **Finanz-eigene Tabellen** (werden hier verwaltet/geschrieben): `beleg`, `beleg_position`,
  `beleg_status_log`, `zahlung`, `lieferant_rechnung`, `lieferant_zahlung`.

**Drei Kopplungen, die die Migration sauber lösen muss:**
1. **Ausgangsrechnung aus Auftrag:** entsteht künftig IM Buchhaltungs-Programm. Das Dashboard (Vertrieb/
   Auftrag) bekommt statt „Rechnung erstellen" einen **Link** nach `/buchhaltung/?p=rechnung_neu&auftrag=…`
   (wie es heute auf `produktion/` verlinkt). Die Erzeugungs-Logik (`rechnung_aus_auftrag()` u. a.) wandert
   in die Buchhaltung bzw. hinter deren Naht.
2. **Dashboard liest `beleg`** (Kundenkonto/Auftrag zeigen Rechnungsstatus). Die `beleg*`-Tabellen bleiben
   in **derselben DB** – gemeinsames **Lesen** ist ok; nur das **Schreiben** zieht komplett in die
   Buchhaltung. Wo das Dashboard `beleg` schreibt, wird auf Links/Weiterleitung umgestellt.
3. **`beleg_firma()` + PDF-Helfer** in `core/pdf_beleg.php` werden quer genutzt (Spec/Angebot/…). Die
   **bleiben im Dashboard-`core`**; die Buchhaltung bekommt eine **eigene** PDF-/Firmenstamm-Funktion
   (kleine bewusste Doppelung – die Sub-App darf Dashboard-`core/schema.php` NICHT einbinden, sonst zweite
   `db()`-Kollision; siehe `PRODUKTION.md`-Naht-Regel).

**Migrations-Checkliste (durch DIESEN Chat, nicht aufteilen):**
1. Skelett anlegen: `public/buchhaltung/index.php`, `buchhaltung/core/{config,db,auth,ui,layout,erp,schema}.php`
   (abschauen von `produktion/core/`). Eigene Sitzung `BXBUCH`, eigene Login-Seite.
2. Seiten umziehen: `module/beleg/*` + `module/buchhaltung/*` → `buchhaltung/module/*`; Dashboard-Reads auf
   `buchhaltung/core/erp.php` umstellen. Finanz-Logik (`core/buchhaltung.php`, `kreditor.php`, `erechnung.php`)
   nach `buchhaltung/core/` ziehen; `beleg*`-/`zahlung`-/`lieferant_rechnung/_zahlung`-DDL nach
   `buchhaltung/core/schema.php` (CREATE IF NOT EXISTS bleibt idempotent; Dashboard liest weiter).
3. Dashboard entkoppeln: Finanz-Routen aus `core/auth.php` + `public/index.php` entfernen; „Rechnung
   erstellen" & Rechnungs-Links auf `/buchhaltung/` umbiegen; Buchhaltungs-Gruppe aus dem Dashboard-Menü
   raus, stattdessen unter „Unterseiten" ein Link `buchhaltung/` (in `core/layout.php`, dort wo schon
   `crm/`/`lager/`/`produktion/` stehen).
4. Testen (`php -l` + curl, eigener Autologin), dann in EINEM Rutsch committen (viele Dateien bewegen sich).

> Bis die Migration läuft, gilt unten der **Ist-Stand** als Referenz/Quelle. Danach wird dieser Abschnitt
> zum Haupt-Teil und der Ist-Stand entfernt.

---

## Ist-Stand (Migrationsquelle): Buchhaltung liegt noch im Dashboard
Aktuell leben Belege/Rechnungen **im Dashboard** (Rolle `finance`), ohne eigene Naht – das wird nach
obiger Zielarchitektur herausgelöst. Dateien/Routen/Tabellen des Ist-Stands:

## Deine Dateien (gehören dem Buchhaltungs-Chat)
- `module/beleg/` – alle Beleg-/Rechnungs-Seiten (siehe „Seiten").
- `module/buchhaltung/` – Finanz-Hub (`hub.php`), Export-/E-Rechnung-Endpunkte (`export.php`, `rechnung_xml.php`),
  Eingangsrechnungen (`lief_rechnung_neu.php`, `lief_rechnung.php`).
- `core/buchhaltung.php` – Auswertungen, GoBD-Nummernkreis-Prüfung, CSV-/DATEV-Export (reine Leselogik).
- `core/kreditor.php` – Kreditoren/Verbindlichkeiten: eigene Tabellen `lieferant_rechnung`/`lieferant_zahlung`
  (per `kreditor_init()`, kein Eingriff in `core/schema.php`), Zahlungen, Kennzahlen, Kreditoren-Exporte.
- `core/erechnung.php` – E-Rechnung (CII/EN16931-XML, ZUGFeRD-Profil).
- `core/pdf_beleg.php` – Beleg-PDF-Layout (`beleg_firma()`, Grundlayout; auch von anderen PDFs genutzt – **vorsichtig** ändern).
- `core/pdf_rechnung.php` – Rechnungs-PDF.

## Geteilte Risiko-Dateien (gehören dem Dashboard – nur abgestimmt anfassen)
- `core/schema.php` – DB-Schema **und** die meisten Belog-/Rechnungs-Funktionen (siehe „Funktionen").
  Neue Spalten additiv per `ensure_column()`. Häufigste Konfliktdatei bei Parallelarbeit.
- `core/auth.php` – Routen-/Rollen-Map (neue Finanz-Route hier whitelisten).
- `public/index.php` – Route → Datei (neue Seite hier mappen).
Änderungen hier **anhalten und sauber mergen** (Rebase-Ampel), nicht blind überschreiben.

## Ist-Stand (gebaut): Belege & Rechnungen
Rollen-Gate: alles unter `finance`.

### Finanz-Hub (`?p=buchhaltung` → `module/buchhaltung/hub.php`)
Reiter: Übersicht (Forderungen/Verbindlichkeiten/Saldo), Offene Posten (Debitoren je Kunde),
**Verbindlichkeiten (Kreditoren je Lieferant + offene Eingangsrechnungen)**, Auswertung
(Umsatz je Monat/Steuersatz, Jahr-Auswahl), Prüfung (GoBD: Nummernkreis-Lücken/Dubletten/Chronologie/Storno), Export.

**Debitoren (die schulden uns):** Belege/Rechnungen (`module/beleg/*`), Zahlstatus via `beleg.status`/`zahlung`.
**Kreditoren (wir schulden denen):** Eingangsrechnungen `?p=lief_rechnung_neu` erfassen → Detail `?p=lief_rechnung&id=…`
(Zahlungen buchen, Status offen→teilbezahlt→bezahlt, stornieren). Tabellen `lieferant_rechnung`/`lieferant_zahlung`
in `core/kreditor.php`. Quelle für Vorbefüllung: `bestellung`/`bestellung_position` (EK-Wert).
**Fremdwährung:** China-Lieferanten rechnen in USD, der Rest in EUR. Je Eingangsrechnung `waehrung` + `fx_kurs`
(1 Währung = X EUR) + `fw_netto`; die Bücher speichern `netto/ust/brutto` in **EUR** (OP, Saldo, DATEV, CSV
durchgängig EUR). Währung aus `lieferanten.waehrung` vorbelegt, Kurs je Rechnung änderbar (Standard `app_meta kurs_<cur>`).

**Bankverbindung des Lieferanten (für Zahlungen):** liegt an `lieferanten.bank_*` – **formatoffen**, NICHT
IBAN-fix (China zahlt oft über Drittland-Banken: SWIFT/BIC + Kontonummer statt IBAN, ggf. Zwischenbank).
Felder: `bank_inhaber, bank_name, bank_land, bank_iban, bank_swift, bank_konto, bank_adresse,
bank_waehrung, bank_zwischenbank, bank_notiz`. Der Lieferant pflegt sie selbst im Portal
(`lieferant_profil`) oder das Team im Lieferant-Detail. Beim Zahlung-Buchen/Export diese Felder lesen
(nicht auf IBAN validieren; leere Felder sind normal).

Exporte `?p=beleg_export&art=…`: `op`/`belege`/`datev` (Debitoren), `vop`/`lief_belege`/`datev_ek` (Kreditoren).
CSV UTF-8+BOM; DATEV-EXTF-Buchungsstapel (Format 700, 125 Felder, SKR03-Default, per `app_meta` konfigurierbar;
vor Produktiv-Import prüfen). E-Rechnung `?p=rechnung_xml&id=…` – CII/EN16931-XML (ZUGFeRD-Profil),
Button in der Rechnungs-Detailansicht. Factur-X-Einbettung (XML in PDF/A-3) steht noch aus.

### Beleg-Posteingang mit KI (`?p=beleg_eingang`, Tabelle `bu_beleg_eingang`)
Belege (Eingangsrechnungen/Quittungen/Kassenbons) hochladen → **KI liest aus** (`be_ki_auslesen` via `ki_datei_frage`:
Belegart, Lieferant, Datum, Netto/USt/Brutto, Währung, Ausgabenkategorie) → prüfen/erfassen → Datei gespeichert
(`BX_UPLOADS/belege/JJJJ-MM/`). Kanäle: Online-Upload (`beleg_upload`), **Handy-Foto per Token-Link ohne Login**
(`beleg_foto`, public, Token in `app_meta belege_upload_token`), **E-Mail als Gerüst** (`be_mail_abholen`, braucht
php-imap + `app_meta belege_imap_*` – noch nicht aktiv). **Steuerberater-Paket**: `?p=beleg_export&art=stb_zip`
(ZIP = Excel-CSV `belege.csv` + alle Belegdateien; eigener ZIP-Writer `bu_zip`, da ZipArchive fehlen kann) bzw.
`art=stb_csv`. Engine: `core/belegeingang.php`; Seiten `beleg_eingang/beleg_upload/beleg_detail/beleg_datei/beleg_foto`.
Eigenes Register, unabhängig von den Kreditoren – optionaler „als Eingangsrechnung übernehmen"-Schritt ist noch offen.

### Seiten (`?p=…` → `module/beleg/…`)
- `rechnungen` → `rechnungen_liste.php` – Rechnungsliste (Übersicht/Filter).
- `rechnung&id=…` → `detail.php` – Beleg-Detail (Status, Positionen, Zahlstatus, Verlauf).
- `rechnung_neu` → `rechnung_neu.php` – Rechnung **aus Auftrag** (Vorschau + Eingaben → erstellen).
- `rechnung_frei` → `rechnung_frei.php` – Rechnung **frei / KI-gestützt** (ohne Auftrag; Freitext → KI → Rechnung).
- `rechnung_import` → `rechnung_import.php` – **Alt-Rechnungen** per Original-PDF + KI importieren.
- `auftrag_import` → `auftrag_import.php` – Auftrag aus Angebot/AB (+ Rechnung) per KI importieren.
- `gutschrift_neu` → `gutschrift_neu.php` – **Gutschrift / Storno-Rechnung** manuell.
- `gutschrift_pdf` / `rechnung_pdf` → PDF (intern, Ansehen/Download).

### Datenmodell (`core/schema.php`)
- `beleg` – `nummer`, `typ` (`rechnung|gutschrift|lieferschein`), `auftrag_id`, `kunde_id`,
  `netto/ust_prozent/ust_betrag/brutto`, `status` (`offen|bezahlt|storniert`), `datum`,
  `storno_von_id`, `grund`.
- `beleg_position` – Positionen (v. a. Gutschrift/Storno): `bezeichnung`, `menge`, `einheit`,
  `preis_cent` (Cent; bei Gutschrift negativ), `mwst_satz`.
- `beleg_status_log` – Status-/Zahlverlauf je Beleg.

### Wichtige Funktionen (in `core/schema.php`)
`rechnung_aus_auftrag()`, `rechnung_frei_erstellen()`, `rechnung_import_ki()`, `rechnung_alt_anlegen()`,
`rechnung_import_positionen_ki()`, `beleg_positionen_aus_auftrag()`, `beleg_staffel_aus_auftrag()`,
`beleg_status_log_add()`, `beleg_zahlstatus()`, `beleg_status_verlauf()`, `beleg_positionen()`,
`beleg_summen_aus_positionen()`, `gutschrift_erstellen()`, `gutschrift_aus_rechnung()`.

## Zielbild / Ausbau (eigener Chat baut das)
Langfristig soll die Buchhaltung das externe Rechnungssystem ersetzen (Vorbild v3-Unterprogramm):
- **Finanz-Hub** unter Route `buchhaltung` – gebaut (Reiter Übersicht/OP/Auswertung/Prüfung/Export).
- **GoBD**: festgeschriebene Belege sind **unveränderbar**; Korrektur nur über Storno/Gutschrift
  (`storno_von_id`), lückenlose Nummernkreise, Status-/Änderungs-Protokoll (`beleg_status_log`).
- **Nummernkreise** zentral (keine Lücken, kein Rückdatieren): Kurzprüfung im Reiter „Prüfung" gebaut.
- **E-Rechnung** (EN16931 / ZUGFeRD-CII-XML) aus `beleg` – XML gebaut; Factur-X (PDF/A-3-Einbettung) offen.
- **DATEV-/Export** + Finanz-Auswertung (offene Posten, Umsatz) – gebaut (CSV + DATEV-EXTF, Auswertung-Reiter).
- **Automatik** (siehe Memory): Rechnung bei Lieferung, Auto-Lieferantenbestellung, Fremdlager-Verrechnung – offen.
- **Noch offen:** Factur-X-PDF, DATEV-Konten-Einstellungen in der Settings-UI, Debitoren je Kunde, XSD-Validierung.
(Prüfe vor dem Bau jeweils, ob Teile schon existieren – nichts doppelt bauen.)

## Regeln (zusätzlich zu CLAUDE.md)
- **GoBD zuerst:** einen festgeschriebenen/gebuchten Beleg nie inhaltlich ändern oder löschen –
  immer Storno/Gutschrift. **Nie pauschale DELETEs** in der Live-DB.
- **Geld in Cent** rechnen (`preis_cent`), erst bei der Anzeige in Euro formatieren.
- **Zeit:** UTC speichern, Anzeige via `fmt_zeit()` → Europe/Berlin.
- **USt:** Inland aus `ust_inland`, EU-Ausland 0 % (siehe Adress-/USt-Logik im Dashboard) – nicht hart verdrahten.
- Zu jeder neuen `.php` eine co-located `.md`. Keine Emojis in der UI, Spaltenüberschriften nicht fett.
- **Verifizieren:** `php -l` + kurzer curl-Test (lokal `?p=autologin&token=<benutzer.login_token>`).

## Git / Parallelarbeit
Gezielt stagen (`git add module/beleg/ core/pdf_beleg.php core/pdf_rechnung.php` …), **nicht** `git add -A`.
Vor jedem Push `git pull --rebase`, dann `git push` (Rebase-Ampel). Nur Fertiges pushen (Auto-Deploy auf
app.bulkify.pro **und** beta.bulkify.pro). Bei Konflikten in `core/schema.php`/`core/auth.php` anhalten
und sauber mergen.

## Lokal testen
`php -S 127.0.0.1:8741 -t public` starten, dann z. B. `/?p=rechnungen` aufrufen.
Autologin (nur localhost): `/?p=autologin&token=<benutzer.login_token>` mit einem `finance`-Benutzer.
