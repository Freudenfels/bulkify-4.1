# module/crmdemo/app.php – Views der CRM-Demo

Eigenständige App unter `?p=crmdemo&m=<modul>`. Nutzt nur `crmdemo_*`-Tabellen (siehe
`core/crmdemo.php`). Ansicht-only, DE/EN/ZH, Rollen-Gating über `cd_gate()`.

## Module
- **dashboard** – Kennzahlen + Schnellzugriff (rollenabhängig).
- **kunden** – Liste → **Kundenprofil als Cockpit** (angelehnt an das echte bulkify-Kundenprofil):
  **KPI-Kacheln** (Status/Fraud, Umsatz bezahlt, Offene Posten, Anzahl Angebote, Kunde seit, Betreuer)
  und **Reiter**: Übersicht (Kontakt + letzte Angebote/Rechnungen), Angebote, Rechnungen, Produktion,
  Postfach, Stammdaten. **Alle Vorgangs-Zeilen sind klickbar** und öffnen Angebot/Rechnung(Beleg)/
  Produktion (`.cd-click`). Bearbeiten → `?edit=1` (`cd_kunde_form()`). Aktiver Reiter wird gemerkt.
  **Globale Suche** (E-Mail/Firma/Telefon/Ansprechpartner) + **Dublettenprüfung** beim Neuanlegen
  (`cd_kunde_dupes()`): existiert der Kunde schon, Meldung „Kontakt mit Vorgesetzten aufnehmen"; nur
  Admin kann per „Trotzdem anlegen" übersteuern. **Fraud-Markierung** (Warnbanner) und **Zuordnung zu
  einem Mitarbeiter** – nur Admin ordnet zu (`crmdemo_mitarbeiter`, Spalte `kunde.betreuer_id`).
  Liste mit **A–Z-Leiste** (`?letter=`), **Zuordnungs-Filter** „Alle / Nur zugeordnete" (persistiert in
  `crmdemo_meta.kunden_filter`) und **Schnellanlage** als Popup (kleiner Button → Modal `#cdneu`, nur
  Firma/Kontakt/E-Mail/Telefon; `quick=1` → nach dem Anlegen direkt ins Profil im Bearbeiten-Modus).
- **konversation** – **Übersicht aller Kunden-Nachrichten** (kundenübergreifend, aus `crmdemo_mail`):
  Zeitleiste mit Ein-/Ausgehend-Kennzeichnung, Kundenfilter und Suche; Klick springt ins Kundenprofil.
  Das Erfassen einzelner Nachrichten läuft weiter im Postfach des jeweiligen Kunden.
- **coareader** – **KI-COA/Spec-Reader**: Lieferanten-COA/Spezifikation (auch chinesisch) einfügen →
  KI liest Werte aus (`cd_coa_extract`), legt den Rohstoff an, falls er fehlt, und erzeugt ein
  **Kunden-COA in DE/EN** (DIN-A4-Bildschirmansicht, `crmdemo_coa`). Ohne API-Schlüssel: Hinweis, läuft auf beta.
- **katalog** – Rohstoff-Katalog mit **KI-/lokaler Ähnlichkeitssuche**; jeder Rohstoff hat eine eigene
  **Nummer (RM-…)**. Detail mit **Preishistorie**, **Bearbeiten** (Name/CAS/Wirkstoff/… via `rohstoff_update`)
  und **Dokumenten-Upload** (`crmdemo_dokument`, PDF/Bild inline, ansehen/löschen) – wie in v4.
  Panel **„Chargen & COAs"**: chinesische COA einfügen → KI erzeugt ein **Kunden-COA in der Zielsprache**
  (EN/DE) UND liest die **Charge**; jede COA wird als **Batch unter dem Rohstoff** gespeichert
  (`crmdemo_coa.rohstoff_id`) und in der Charge-Liste angezeigt (COA-Ansicht je Batch).
- **rezepturen** – **geteilter Rezeptur-Katalog** (fertige Formulierungen) mit eigener **Nummer (RZ-…)**
  und **Freigabe-Workflow**: `entwurf → freigegeben (Entwicklung/Produktion) → kalkuliert (Pricing setzt
  Preis)`. Liste mit Nummer/Status, Suche, **„+ Neue Rezeptur"** als Popup (kein Scrollen zum Formular).
  Zutaten werden aus dem Rohstoff-Katalog gewählt (Datalist); unbekannte werden neu angelegt. Detail
  zeigt Zutaten, Status, Freigabe/Preis, **Verwendet** und **„Vorgestellt bei"**.
- **produktentwickler** – KI-Konzept aus einer Idee. Erzeugt direkt eine **Rezeptur (Entwurf)** und
  öffnet sie (Zutaten aus dem KI-Konzept, unbekannte werden im Rohstoff-Katalog neu angelegt).
- **angebote** – Liste → Detail mit **Pricing-Workflow**: `entwurf → kalkulation → kalkuliert →
  gesendet → angenommen`. Verkauf fragt Kalkulation an / sendet / nimmt an; Pricing trägt Preise + Notiz ein.
  Annahme erzeugt automatisch die Rechnung. **Positionen aus Rezeptur wählbar** („Aus Rezeptur
  übernehmen") oder neue Positionen per Haken **„In Katalog aufnehmen"** – so wächst der geteilte
  Rezeptur-Katalog, und jede Verwendung erhöht den Zähler der Rezeptur (`angebot_pos.rezeptur_id`).
  **Positionstyp** je Zeile: Fertigprodukt (nach Rezeptur, Einheit „Stk.") · Rohstoff (aus Katalog,
  Einheit „kg") · freie Position. Einheit wird serverseitig aus `angebot_pos.typ` abgeleitet.
  **Bestätigt/Nicht-bestätigt**-Status; **Ablehnen** setzt Status abgelehnt und verlangt einen
  **Pflicht-Grund** (`ablehnungsgrund`). **Beleg-Angaben** je Beleg: Notiz, Zahlungsbedingungen,
  Versandart (Rechnung zusätzlich Bankverbindung) – erscheinen auf dem A4. Auf dem **A4** stehen zudem
  **Unterschrift + Stempel** des zugeordneten Mitarbeiters, damit Angebote natürlich wirken.
- **einstellungen** (nur Admin, Reiter) – Einmal-Einstellungen: **Briefkopf** (Absender + Logo),
  **Mitarbeiter** (anlegen + Unterschrift/Stempel-Upload je Mitarbeiter), **Standardwerte** (Währung/
  USt/Zahlungsziel) und **Beleg-Vorgaben** (Standard-Zahlungsbedingungen/Versandart/Bankverbindung),
  gespeichert in `crmdemo_meta` (`cd_std()`).
- **rechnungen** – Liste + Status (offen/bezahlt); Nummer öffnet den **DIN-A4-Beleg**.
- **produktion** – Chargen (Nr. + MHD) → Detail mit **Rückverfolgbarkeit** (eingesetzte Rohstoff-Lots
  aus dem Katalog). Statuskette geplant → in Produktion → fertig.
- **chat** – KI-Rohstoff-/Sourcing-Chat (graceful ohne Schlüssel), Verlauf gespeichert.
- **finanzen** – Summen offen/bezahlt + Rechnungsliste.
- **firma** – Briefkopf (Absender) + **Logo-Upload** (inline base64), nur Admin. Speist die A4-Belege.

## DIN-A4-Beleg (`cd_beleg_sheet` / `cd_beleg_a4` / `cd_beleg_print`)
`?p=crmdemo&m=angebote|rechnungen&id=<id>&beleg=1` rendert Angebot/Rechnung als A4-Blatt **in der
Sprache des Kunden** (`kunde.sprache`), mit Logo/Absender, Netto/USt/Brutto, Beleg-Angaben und
Unterschrift/Stempel des zugeordneten Mitarbeiters. Das reine Blatt liefert `cd_beleg_sheet()`.
**Herunterladen:** Knopf „Herunterladen (PDF)" öffnet `&druck=1` → `cd_beleg_print()` gibt eine
**eigenständige A4-Druckseite** (ohne App-Shell, `@page A4`, CJK-Fonts, Auto-`window.print()`) aus –
der Nutzer speichert sie im Druckdialog als PDF. Funktioniert auch für chinesische Belege (报价单/发票).

## Rollen (Demo-Umschalter oben)
Verkauf · Pricing/Sourcing · Produktion · Buchhaltung · Admin. `cd_gate()` sperrt Module, die die
aktive Rolle nicht sehen darf. Admin sieht alles und kann jede Rolle vorführen.

## Zugang / Verwaltung
Versteckter Reiter `?p=einstellungen&tab=crmdemo` (Seed/Reset/Löschen).
