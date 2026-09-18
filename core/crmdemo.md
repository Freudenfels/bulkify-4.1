# core/crmdemo.php – Kern der isolierten CRM-Demo

Eigenständiges Mini-System, um einem Lieferanten in einer kurzen Testphase zu zeigen, was das
Tool kann. **Vollständig isoliert:** greift nur auf eigene Tabellen `crmdemo_*` zu, kein Mix mit
echten bulkify-Daten, jederzeit löschbar. **Ansicht-only:** keine Downloads/Exporte – Belege
werden nur am Bildschirm gezeigt.

## Was hier steht
- **Schema** (`crmdemo_schema`): eigene Tabellen `crmdemo_meta/kunde/produkt/rohstoff/rohstoff_preis/
  angebot/angebot_pos/rechnung/produktion/charge_zutat/mail/chat`. Idempotent (CREATE IF NOT EXISTS)
  plus additive `ensure_column`-Migration, damit die frühere MVP-Version ohne Datenverlust nachwächst.
- **Sprachen DE/EN/ZH**: `cd_lang()` (Meta-gespeichert, per `?lang=` umschaltbar), `cd_t($key)` für die
  aktive Sprache, `cd_tl($key,$lang)` für eine feste Sprache (Belege in Kundensprache). Alle Texte in
  `crmdemo_i18n()`.
- **Rollen/Rechte** (Demo): `cd_rolle()` (per `?rolle=` umschaltbar), `cd_rechte()` mappt Rolle → sichtbare
  Module, `cd_darf($modul)` prüft, `cd_gate($modul)` sperrt. Rollen: Verkauf · Pricing/Sourcing ·
  Produktion · Buchhaltung · Admin. Admin kann jede Rolle „vorführen".
- **Geld/Währung**: `cd_waehrungen()` (EUR/USD/CNY), `cd_money($cent,$waehrung)`. Beträge immer als Cent
  gespeichert; Währung ist reine Anzeige (keine FX-Umrechnung in der Demo).
- **Briefkopf/Logo**: `cd_absender()` (Meta-Felder `abs_*`), `cd_logo_datauri()` (Logo als inline
  base64 in `crmdemo_meta.logo_b64` – kein Datei-URL, bleibt isoliert).
- **Layout**: `cd_head/cd_shell_start/cd_shell_ende` (eigenes Menü, Rollen-Chips, Sprach-Chips, Theme).
  Das Menü ist in `cd_shell_start()` logisch gruppiert (Übersicht · Vertrieb · Entwicklung & Katalog ·
  Fertigung · Finanzen & Buchhaltung · System); Gruppen ohne für die Rolle sichtbare Punkte entfallen.
- **KI-Ähnlichkeit lokal**: `cd_similarity($anfrage,$rohstoff)` – Wort-Overlap (70 %) + Zahlnähe (30 %).
  Löst das „Ashwagandha 350 mg vs. 360 mg"-Beispiel auch **ohne API-Schlüssel** (Extrakt 360 mg = Top-Treffer).
  Mit Schlüssel läuft zusätzlich der KI-Produktentwickler/-Chat über `core/ki.php`.
- **Seed/Reset/Löschen**: `crmdemo_seed()` (abschnittsweise idempotent) legt einen **kompletten
  Demo-Satz** an – je ein Angebot pro Status (Entwurf · Kalkulation angefragt · kalkuliert · gesendet ·
  angenommen · abgelehnt m. Grund), Rechnungen offen (CNY) und bezahlt (EUR) und Produktionen mit
  Chargen-Rückverfolgung – damit jeder Prozessschritt durchklickbar ist. `crmdemo_reset()` (Daten leeren),
  `crmdemo_loeschen()` (alle Tabellen droppen). Steuerung über den versteckten Reiter
  `?p=einstellungen&tab=crmdemo`.

## COA/Spec-Reader & Standardwerte
- `cd_coa_extract($text,$ziel)` – liest ein Lieferanten-COA/Spec (auch chinesisch) per KI aus und gibt
  strukturierte Werte in der Zielsprache zurück (`['ok','daten']`). Ohne Schlüssel `ok=false`. Ergebnis
  landet in `crmdemo_coa`; fehlt der Rohstoff, wird er in `crmdemo_rohstoff` angelegt.
- `cd_std($k,$default)` – Einmal-Einstellungen (Standard-Währung/USt/Zahlungsziel) aus `crmdemo_meta`.
- Angebotspositionen tragen `typ` (produkt|rohstoff|frei), `einheit` (Stk./kg), `rohstoff_id` und
  `rezeptur_id`.

## Rezeptur-Katalog
`crmdemo_rezeptur` ist die geteilte Bibliothek fertiger Rezepturen. Positionen im Angebot verweisen per
`crmdemo_angebot_pos.rezeptur_id` darauf; jede Verwendung erhöht `verwendet`. Neue, im Editor angehakte
Positionen werden automatisch als Rezeptur angelegt – so entsteht bei vielen Sales ein großer, aber
durchsuchbarer Katalog statt Wildwuchs.

## Mitarbeiter, Zuordnung, Dubletten
- `crmdemo_mitarbeiter` (Name, Rolle, `signatur_b64`, `stempel_b64`). `crmdemo_kunde.betreuer_id` ordnet
  einen Kunden einem Mitarbeiter zu – nur Admin darf zuordnen. Auf dem A4-Beleg erscheinen Unterschrift
  und Stempel des zugeordneten Mitarbeiters (`cd_mitarbeiter()`).
- `cd_kunde_dupes($firma,$email,$telefon)` – findet bestehende Kunden (Dublettenprüfung beim Neuanlegen).
- `crmdemo_kunde.fraud` – Betrugs-Markierung (Warnbanner im Profil/Liste).
- Beleg-Felder: `angebot`/`rechnung` haben `notiz`, `zahlungsbedingungen`, `versandart`; `rechnung`
  zusätzlich `bankverbindung`; `angebot.ablehnungsgrund` (Pflicht beim Ablehnen). Standardwerte dafür in
  `cd_std()` (`std_zahlungsbed`, `std_versandart`, `std_bank`).

## Isolation – Merksatz
Eine einzige Quelle für die Tabellenliste: `crmdemo_tabellen()`. Wer eine Tabelle hinzufügt, trägt sie
dort ein – dann greifen Reset und Löschen automatisch. Kein Zugriff auf echte bulkify-Tabellen.

## Rendering / Routen
Die Modul-Views liegen in `module/crmdemo/app.php` (Route `?p=crmdemo&m=<modul>`).
