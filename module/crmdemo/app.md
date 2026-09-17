# module/crmdemo/app.php – Views der CRM-Demo

Eigenständige App unter `?p=crmdemo&m=<modul>`. Nutzt nur `crmdemo_*`-Tabellen (siehe
`core/crmdemo.php`). Ansicht-only, DE/EN/ZH, Rollen-Gating über `cd_gate()`.

## Module
- **dashboard** – Kennzahlen + Schnellzugriff (rollenabhängig).
- **kunden** – Liste → **tiefes Profil** (Stammdaten inkl. Sprache/Währung/Adresse/WeChat),
  **Verlauf** (Angebote + Rechnungen + Produktion des Kunden), **simuliertes Postfach** (ein/aus).
  Anlegen/Bearbeiten über `cd_kunde_form()`.
- **katalog** – Rohstoff-Katalog mit **KI-/lokaler Ähnlichkeitssuche** (Feld oben, z. B.
  „Ashwagandha 350 mg 5%") und Detail mit **Preishistorie** (Pricing/Admin darf Preise erfassen).
- **produktentwickler** – KI-Konzept aus Idee (JSON: Kurzbeschreibung/Zutaten/Hinweise), Fallback-Hinweis
  ohne Schlüssel.
- **angebote** – Liste → Detail mit **Pricing-Workflow**: `entwurf → kalkulation → kalkuliert →
  gesendet → angenommen`. Verkauf fragt Kalkulation an / sendet / nimmt an; Pricing trägt Preise + Notiz ein.
  Annahme erzeugt automatisch die Rechnung.
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
