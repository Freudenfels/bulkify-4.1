# CLAUDE.md – Projektkontext bulkify Dashboard 4.1

> Wird von jeder Claude-Code-Session automatisch gelesen. Gibt einer frischen Session (neuer PC, neuer Chat) sofort Kontext + Regeln. Kurz + aktuell halten.

## Was ist das?
Clean-Slate-Neuaufbau des bulkify-ERP (Nahrungsergänzungs-Lohnhersteller, Marke **bulkify**). PHP 8.3 + **MariaDB/MySQL**, kein Framework, serverseitig gerendertes HTML. Front-Controller `public/index.php` (Whitelist `?p=<route>`). Ziel: Prozesse/Seiten vereinfachen, Doppelungen killen. Ablauf: Anfrage → Rezeptur/Vorschlag → Angebot → Auftrag → Produktion → Lager → Versand.

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

## Git-Rhythmus (Multi-PC)
Pull am Start, commit+push nach jedem fertigen Schritt. Immer nur an EINEM PC gleichzeitig am selben Branch. Einzige gemeinsame Wahrheit = GitHub.

## Wer
Ansprechpartner: **Nico** (thomalla@freudenfels.de). Stil: direkt, knapp, umsetzungsorientiert.
