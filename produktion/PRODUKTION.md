# Produktion – eigenes Programm (`/produktion/`)

Eigenständiges Programm im selben Repo – **wie CRM und Lager**. Eigene Seite, eigene Sitzung, aber
dieselbe Datenbank wie das Dashboard. Gedacht als **Produktions-Arbeitsplatz** (Werk/Shop-Floor),
der in einem **eigenen Code-Chat** weiterentwickelt werden kann, ohne dem Dashboard in die Quere zu
kommen.

## Aufbau
- **Web-Einstieg:** `public/produktion/index.php` (Front Controller, Whitelist `?p=<route>`).
- **Code:** `produktion/core/` (Rahmen) + `produktion/module/` (Seiten). Nichts davon liegt in `public/`.
- **Eigene Sitzung:** `BXPROD` (Dashboard/CRM/Lager laufen auf derselben Domain).
- **Aussehen:** lädt dasselbe `/assets/app.css` wie das Dashboard, gleiche Klassen (`bx-*`).

## DIE NAHT (wichtigste Regel)
**Alle** Zugriffe auf Dashboard-Tabellen (`produktionsauftrag`, `produktion_schritt`, `charge`,
`auftrag`, `produkt`, `kunden`, `benutzer`) stehen **ausschließlich** in `produktion/core/erp.php`.
Überall sonst im Programm nur eigene `pr_`-Tabellen (falls welche dazukommen). Ändert sich im
Dashboard eine Spalte, darf genau diese eine Datei kaputtgehen – sonst nichts.

**Schreib-Logik (umgesetzt):** `erp_schritt_abschliessen($schritt_id, $akteur)` in `erp.php` schließt
den jeweils nächsten offenen Schritt ab – inkl. FEFO-/Chargen-Entnahme, Mangel-Guard, Statusfortschritt
und Fertigware-Einbuchung beim letzten Schritt. Entschieden wurde die **eigene Umsetzung im Seam**
(volle Isolation), nicht die gemeinsame Bibliothek. Das ist eine **bewusste Doppelung** der Dashboard-
Regeln (`core/schema.php`: `produktion_schritt_erledigen()` etc.) – Änderungen dort müssen hier
mitgezogen werden (Details in [core/erp.md](core/erp.md)). **Niemals** `core/schema.php` des Dashboards
hier einbinden (zieht die zweite `core/db.php` + das ganze Dashboard herein).

**Offener Punkt:** optionaler späterer Umbau auf eine gemeinsame Bibliothek (berührt `core/schema.php`
→ nur abgestimmt). Bereitschaft/Material-Vorschau auf der Detailseite noch nicht angezeigt.

## Kern-Dateien
- `core/config.php` – DB-Zugang (dieselbe secrets.php wie Dashboard/Lager), Sitzung `BXPROD`, UTC.
- `core/db.php` – `q/all/one/scalar/insert_id` + `tabelle_da()` (wie Dashboard/Lager).
- `core/erp.php` – **die Naht** (Auth-Reads + `erp_produktionsauftraege()`, `erp_pa()`, `erp_pa_schritte()`; Writes als TODO).
- `core/auth.php` – Login mit den **Mitarbeiter-Logins des Dashboards** (`benutzer`), `pr_*`-Funktionen.
- `core/ui.php` – `h/fmt_zeit/menge_txt/flash/seitenkopf/pa_badge`.
- `core/layout.php` – `kopf()/fuss()/pr_nav()`.

## Seiten
- `?p=login` – Anmeldung.
- `?p=dash` – **Dashboard/Startseite** (Standard nach Login): KPIs (Offen zu planen, In Planung,
  Laufend, Abgeschlossen, Ø Produktionszeit, Ø Durchlaufzeit) + Kurzlisten laufend/in Planung/offen.
- `?p=liste` – Produktionsaufträge in Reitern (Alle/Laufend/Abgeschlossen)
  mit „Produzierbar?" + Auftragseingang.
- `?p=pa&id=…` – Detail-Übersicht (ein Spaltenraster) + Rezeptur/Rohstoffbedarf + Schrittliste.
  Button **„In den Produktionsmodus"**.
- `?p=run&id=…` – **Produktionsmodus** (tablettauglich): nächster Schritt groß, „Erledigt"/„Freigeben"
  (FEFO-Entnahme/Mangel-Guard, letzter Schritt bucht Fertigware ein, protokolliert wer/wann).
  Admin kann Schritte direkt abhaken/zurücksetzen (reine Statuskorrektur, ohne Lagerbewegung).

## Arbeiten im eigenen Chat
Ein Chat, der **nur** im Ordner `produktion/` (+ `public/produktion/`) arbeitet, kollidiert praktisch
nie mit anderen Chats. Geteilte Risiko-Dateien bleiben nur `core/schema.php` (DB-Schema) und
`core/auth.php` (Dashboard-Routen) – die gehören dem Dashboard; hier normalerweise nicht anfassen.
Koordination wie immer über die **Rebase-Ampel** (`git pull --rebase` vor jedem Push). Gezielt stagen
(`git add produktion/ public/produktion/`), nicht `git add -A`.

## Lokal testen
Dashboard-Server starten (`php -S 127.0.0.1:8741 -t public`) und `/produktion/?p=liste` aufrufen;
lokal geht `?p=autologin&token=<benutzer.login_token>`.
