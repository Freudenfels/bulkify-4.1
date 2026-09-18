# module/crmdemo/app.php – Views der CRM-Demo

Eigenständige App unter `?p=crmdemo&m=<modul>`. Nutzt nur `crmdemo_*`-Tabellen (siehe
`core/crmdemo.php`). Ansicht-only, DE/EN/ZH, Rollen-Gating über `cd_gate()`.

## Module
- **dashboard** – Kennzahlen + Schnellzugriff (rollenabhängig).
- **kunden** – Liste → **tiefes Profil** (Stammdaten inkl. Kundennummer, Betreuer, Zahlungsziel,
  Branche, Liefer-/Rechnungsadresse, Sprache/Währung/WeChat), **Verlauf** (alte Angebote + Rechnungen
  + Produktion des Kunden), **simuliertes Postfach** (ein/aus). Prominenter **Bearbeiten**-Button →
  `?edit=1`; Anlegen/Bearbeiten über `cd_kunde_form()`. Gedacht als Kopie des echten bulkify-Kundenprofils.
  **Globale Suche** (E-Mail/Firma/Telefon/Ansprechpartner) + **Dublettenprüfung** beim Neuanlegen
  (`cd_kunde_dupes()`): existiert der Kunde schon, Meldung „Kontakt mit Vorgesetzten aufnehmen"; nur
  Admin kann per „Trotzdem anlegen" übersteuern. **Fraud-Markierung** (Warnbanner) und **Zuordnung zu
  einem Mitarbeiter** – nur Admin ordnet zu (`crmdemo_mitarbeiter`, Spalte `kunde.betreuer_id`).
- **coareader** – **KI-COA/Spec-Reader**: Lieferanten-COA/Spezifikation (auch chinesisch) einfügen →
  KI liest Werte aus (`cd_coa_extract`), legt den Rohstoff an, falls er fehlt, und erzeugt ein
  **Kunden-COA in DE/EN** (DIN-A4-Bildschirmansicht, `crmdemo_coa`). Ohne API-Schlüssel: Hinweis, läuft auf beta.
- **katalog** – Rohstoff-Katalog mit **KI-/lokaler Ähnlichkeitssuche** (Feld oben, z. B.
  „Ashwagandha 350 mg 5%") und Detail mit **Preishistorie** (Pricing/Admin darf Preise erfassen).
- **rezepturen** – **geteilter Rezeptur-Katalog** (fertige Formulierungen). Durchsuchbar (Name/
  Kategorie/Zutat), nach Verwendung sortiert. Detail zeigt Zutaten, **Verwendet**-Zähler und
  **„Vorgestellt bei"** (Kunden, denen die Rezeptur schon angeboten wurde). Wächst automatisch:
  siehe Angebotseditor. KI-Konzepte lassen sich mit „In den Rezeptur-Katalog speichern" übernehmen.
- **produktentwickler** – KI-Konzept aus Idee (JSON: Kurzbeschreibung/Zutaten/Hinweise), Fallback-Hinweis
  ohne Schlüssel.
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

## DIN-A4-Beleg (`cd_beleg_a4`)
`?p=crmdemo&m=angebote|rechnungen&id=<id>&beleg=1` rendert Angebot/Rechnung als A4-Blatt **in der
Sprache des Kunden** (`kunde.sprache`), mit Logo/Absender aus dem Briefkopf, Netto/USt/Brutto.
Nur Ansicht – bewusst kein Download/PDF in der Demo.

## Rollen (Demo-Umschalter oben)
Verkauf · Pricing/Sourcing · Produktion · Buchhaltung · Admin. `cd_gate()` sperrt Module, die die
aktive Rolle nicht sehen darf. Admin sieht alles und kann jede Rolle vorführen.

## Zugang / Verwaltung
Versteckter Reiter `?p=einstellungen&tab=crmdemo` (Seed/Reset/Löschen).
