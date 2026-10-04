# Buchhaltung – Finanzbereich im Dashboard

Kontext- und Arbeitsdatei für einen **eigenen Buchhaltungs-Chat** (wie `LAGER.md` / `PRODUKTION.md`).
Zuerst lesen, dann loslegen. Gilt zusätzlich zu `CLAUDE.md` (Projektregeln) – die Regeln dort bleiben.

## Wichtiger Unterschied zu Lager/Produktion
Lager und Produktion sind **eigene Programme** in eigenen Ordnern (`lager/`, `produktion/`) mit einer
Naht (`*/core/erp.php`) zum Dashboard. **Buchhaltung ist das NICHT.** Belege/Rechnungen sind Kern-Daten
des Dashboards (Rechnung hängt an Auftrag, Kunde, USt), darum lebt die Buchhaltung **direkt im Dashboard**
(Rolle `finance`), ohne eigene DB-Naht. Ein eigener Chat ist trotzdem sinnvoll und kollisionsarm, weil er
fast nur **eigene Dateien** anfasst (`module/beleg/*`, die PDF-Bauer) – siehe „Deine Dateien".

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

Exporte `?p=beleg_export&art=…`: `op`/`belege`/`datev` (Debitoren), `vop`/`lief_belege`/`datev_ek` (Kreditoren).
CSV UTF-8+BOM; DATEV-EXTF-Buchungsstapel (Format 700, 125 Felder, SKR03-Default, per `app_meta` konfigurierbar;
vor Produktiv-Import prüfen). E-Rechnung `?p=rechnung_xml&id=…` – CII/EN16931-XML (ZUGFeRD-Profil),
Button in der Rechnungs-Detailansicht. Factur-X-Einbettung (XML in PDF/A-3) steht noch aus.

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
