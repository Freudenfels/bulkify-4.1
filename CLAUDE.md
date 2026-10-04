# CLAUDE.md – Projektkontext bulkify Dashboard 4.1

> Wird von jeder Claude-Code-Session automatisch gelesen. Gibt einer frischen Session (neuer PC, neuer Chat) sofort Kontext + Regeln. Kurz + aktuell halten.

## Was ist das?
Clean-Slate-Neuaufbau des bulkify-ERP (Nahrungsergänzungs-Lohnhersteller, Marke **bulkify**). PHP 8.3 + **MariaDB/MySQL**, kein Framework, serverseitig gerendertes HTML. Front-Controller `public/index.php` (Whitelist `?p=<route>`). Ziel: Prozesse/Seiten vereinfachen, Doppelungen killen. Ablauf: Anfrage → Rezeptur/Vorschlag → Angebot → Auftrag → Produktion → Lager → Versand.

## Programme in diesem Repo
- **Dashboard** unter `/` - Werkzeug fuer den Rechner (`core/`, `module/`, `public/`).
- **CRM** unter `/crm/` - Werkzeug fuers Handy (`crm/core/`, `crm/module/`, `public/crm/`).
  Beantwortet eine Frage: wer wartet auf mich. Details: `crm/CRM.md`.
  **Alle** Zugriffe des CRM auf Dashboard-Tabellen stehen in `crm/core/erp.php` - nirgends sonst.
  Wer hier eine Spalte umbenennt, prueft genau diese eine Datei.
- **Lager** unter `/lager/` - Lagerplaetze + Pick-to-Light (LED-Leisten) fuers Tablet am Regal
  (`lager/core/`, `lager/module/`, `public/lager/`). Gleiches Muster wie das CRM: eigene Tabellen `lg_`,
  **alle** Dashboard-Zugriffe nur in `lager/core/erp.php`. Details: `lager/LAGER.md`.
- **Produktion** unter `/produktion/` - Produktions-Arbeitsplatz (Werk/Shop-Floor), eigener Code-Chat.
  Gleiches Muster: eigene Sitzung `BXPROD`, **alle** Dashboard-Zugriffe nur in `produktion/core/erp.php`
  (Stand: liest nur; Schritt-Abschluss ist offener Ausbaupunkt). Details: `produktion/PRODUKTION.md`.
- **Buchhaltung** - Finanzbereich **IM** Dashboard (kein eigener Ordner, keine `erp.php`-Naht), Rolle
  `finance`, Dateien v. a. `module/beleg/*` + `core/pdf_beleg.php`/`pdf_rechnung.php`. Eigener Code-Chat
  möglich. Details + Datei-Grenzen: `BUCHHALTUNG.md`.

## Lokal starten
```
php -S 127.0.0.1:8741 -t public
```
DB-Zugang: `core/config.php` (lokal `bulkify41`, User/Pass `bulkify`/`bulkify`). Schema baut sich per `init_schema()` bei jedem Aufruf selbst auf (CREATE IF NOT EXISTS + additive `ensure_column`). Erst-Admin: `seed_benutzer_if_empty()`.

## Wichtige Regeln (bitte einhalten)
1. **Zu jeder .php-Datei eine co-located `.md`** in einfachem Deutsch (Doku). Bei Änderungen mitpflegen.
2. **Nie committen:** `data/` (Uploads/DB/Logs), `secrets.php`, echte Zugangsdaten – ist per `.gitignore` ausgeschlossen und bleibt es.
3. **Keine Emojis in der UI.** Feld-/Spaltenüberschriften nie fett. Großzügige Abstände. (Siehe UI-Memories.)
4. **Zeit:** immer UTC speichern, Anzeige via `fmt_zeit()` → Europe/Berlin.
5. **Verifizieren:** `php -l` + kurzer curl-Test (Admin-Autologin nur localhost). **Nie pauschale DELETEs in der DB** (es wird parallel gearbeitet).
6. **Demo-Seeding ist AUS** (`app_meta seed_demo_off=1`) – die Demo-Seeds legen sonst beim Seitenaufruf wieder Daten an.

## Git-Rhythmus (Multi-PC & parallele Code-Chats)
Pull am Start, commit+push nach jedem **fertigen, getesteten** Schritt. Einzige gemeinsame Wahrheit = GitHub.

**Parallel arbeiten erlaubt** – am besten **ein Chat pro Bereich** (Lager `lager/`, CRM `crm/`, Dashboard `core/`+`module/`+`public/`; getrennte Ordner → verschiedene Dateien → kein Konflikt). Koordination = **Rebase-Ampel**: **vor jedem Push `git pull --rebase`, dann `git push`.** Wird der Push abgelehnt (anderer Chat war schneller = rot), einfach nochmal `git pull --rebase` und pushen (grün). **Niemals `git push --force`.** Echte Merge-Konflikte entstehen nur bei **gemeinsamen Dateien** – v. a. `core/schema.php` und die Nähte `*/core/erp.php`; dort anhalten und sauber lösen. Alle pushen nach `main` (Auto-Deploy), deshalb nur Fertiges committen.

## Wer
Ansprechpartner: **Nico** (thomalla@freudenfels.de). Stil: direkt, knapp, umsetzungsorientiert.
