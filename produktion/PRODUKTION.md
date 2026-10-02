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

**Abgrenzung / offener Punkt:** Die eigentliche Produktionslogik (Schritt abschließen inkl.
FEFO-/Chargen-Entnahme, Mangel-Guard, Bereitschaft) lebt bisher im Dashboard in `core/schema.php`
(`produktion_schritt_erledigen()` etc.). Dieses Programm **liest** aktuell nur. Soll es selbst
Schritte abschließen, kommt die Schreib-Logik als benannte Funktion in `erp.php` – entweder eigene
Umsetzung oder (sauberer) nachdem die Dashboard-Funktionen in eine gemeinsam nutzbare Bibliothek
ausgelagert wurden. **Niemals** `core/schema.php` des Dashboards hier einbinden (zieht das ganze
Dashboard herein).

## Kern-Dateien
- `core/config.php` – DB-Zugang (dieselbe secrets.php wie Dashboard/Lager), Sitzung `BXPROD`, UTC.
- `core/db.php` – `q/all/one/scalar/insert_id` + `tabelle_da()` (wie Dashboard/Lager).
- `core/erp.php` – **die Naht** (Auth-Reads + `erp_produktionsauftraege()`, `erp_pa()`, `erp_pa_schritte()`; Writes als TODO).
- `core/auth.php` – Login mit den **Mitarbeiter-Logins des Dashboards** (`benutzer`), `pr_*`-Funktionen.
- `core/ui.php` – `h/fmt_zeit/menge_txt/flash/seitenkopf/pa_badge`.
- `core/layout.php` – `kopf()/fuss()/pr_nav()`.

## Seiten (Stand Gerüst)
- `?p=login` – Anmeldung. `?p=liste` – Produktionsaufträge (offen/in Arbeit) mit Fortschritt.
  `?p=pa&id=…` – Detail (Kopf + Schritte, read-only).

## Arbeiten im eigenen Chat
Ein Chat, der **nur** im Ordner `produktion/` (+ `public/produktion/`) arbeitet, kollidiert praktisch
nie mit anderen Chats. Geteilte Risiko-Dateien bleiben nur `core/schema.php` (DB-Schema) und
`core/auth.php` (Dashboard-Routen) – die gehören dem Dashboard; hier normalerweise nicht anfassen.
Koordination wie immer über die **Rebase-Ampel** (`git pull --rebase` vor jedem Push). Gezielt stagen
(`git add produktion/ public/produktion/`), nicht `git add -A`.

## Lokal testen
Dashboard-Server starten (`php -S 127.0.0.1:8741 -t public`) und `/produktion/?p=liste` aufrufen;
lokal geht `?p=autologin&token=<benutzer.login_token>`.
