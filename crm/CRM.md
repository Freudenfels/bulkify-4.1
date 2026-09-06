# crm/ – das CRM im Dashboard-Projekt

> Eigener Bereich, gleiches Repo. Wer am Dashboard arbeitet, fasst diesen Ordner normalerweise nicht an – und umgekehrt.

## Was ist das?
Ein zweites Programm neben dem Dashboard. Es beantwortet genau eine Frage: **Wer wartet auf mich?**
Anfragen, Angebote ohne Antwort, Rückfragen, Wiedervorlagen und Termine an einer Stelle, sortiert
nach Wartezeit. Dazu Kontakte für Leute, die noch kein Kundenkonto haben.

Zielgerät ist das **Handy**. Das Dashboard ist ein Werkzeug für den Rechner, dieses hier für unterwegs.

## Wo was liegt
| Ort | Was |
|---|---|
| `crm/core/`, `crm/module/` | der Code – **nicht** über das Web erreichbar |
| `public/crm/` | Einstieg (`index.php`), Assets, Manifest, Service Worker |
| `crm/data/` | Log und kurzzeitige Uploads (gitignored) |

Erreichbar unter **`/crm/`** – also `beta.bulkify.pro/crm/`. Das Dashboard bleibt unter `/`.

## Gemeinsam mit dem Dashboard
- **Dieselbe Datenbank.** Das CRM liest dessen Vorgänge mit.
- **Dieselben Logins** (Tabelle `benutzer`), aber eine **eigene Sitzung** (`BXCRM`). Man meldet sich
  zweimal an; dafür kann keins das andere aussperren.
- **Dieselbe `secrets.php`** – sie liegt eine Ebene höher und wird automatisch gefunden. Eine
  gepflegte Stelle für Datenbank und Anthropic-Schlüssel.
- **Derselbe Deploy.** Keine zweite Subdomain, kein zweites Repo.

## Aussehen
Kein eigenes. Das CRM laedt **dasselbe Stylesheet wie das Dashboard** (`/assets/app.css`) und
benutzt dessen Klassen; dazu kommt `public/crm/assets/crm.css` mit den wenigen Ergaenzungen fuer
die Wartezeilen (alle mit `crm-` davor). Menue links, Burger auf dem Handy, dunkler Modus -
alles wie im Dashboard, inklusive derselben Einstellung fuer hell/dunkel.

## Die wichtigste Regel: eine einzige Naht
**Alle** Zugriffe auf Dashboard-Tabellen (`kunden`, `angebot`, `portal_anfrage`, `rezeptur_anfrage`,
`aufgabe`, `nachricht`, `lieferant_anfrage`, `benutzer` …) stehen ausschließlich in
**`crm/core/erp.php`**. Nirgendwo sonst. Ändert sich am Dashboard eine Spalte, ist genau diese eine
Datei zu prüfen – dann weiß man auch, wo man sucht.

- **Gelesen** wird viel.
- **Geschrieben** wird ins Dashboard nur an einer Stelle: `erp_kunde_anlegen()` (aus einem Kontakt
  einen Kunden machen). Sonst nie – kein UPDATE, kein DELETE.
- **Eigene Daten** stehen in Tabellen mit Präfix `crm_`. Nur die legt `crm/core/schema.php` an.

## Was drin ist
- **Wer wartet auf mich** (`/crm/?p=wartet`) – zwei Reiter, sortiert nach Wartezeit, mit den
  Knöpfen „in 3 Tagen“ und „erledigt“.
- **Automatische Wiedervorlage** nach dem Angebotsversand (`wartet_automatik()`), fällig ab
  Versand + `CRM_ANGEBOT_NACHFASSEN` Tagen.
- **Kontakte** mit Verlauf, Wiedervorlage und „Zum Kunden machen“.
- **Schnell erfassen** – auch Ziel des Android-Teilen-Menüs, mit KI-Auslesen von Text und Foto.
- **Termine**, **Kunden mit Verlauf**, **Mehr**.
- **KI:** Visitenkarte fotografieren, Text auslesen, Antwortvorschlag, Tagesbriefing. Alle vier
  füllen nur vor – gespeichert und verschickt wird nie automatisch.
- **Dublettenprüfung** beim Erfassen (rein rechnerisch, ohne KI).

## Noch nicht gebaut
Morgenmail, E-Mail-Eingang, WhatsApp Cloud API.

## Lokal starten
Derselbe Server wie fürs Dashboard – das CRM liegt einfach darunter:
```
php -S 127.0.0.1:8741 -t public
```
Dashboard: `http://127.0.0.1:8741/` · CRM: `http://127.0.0.1:8741/crm/`

Das Schema der `crm_`-Tabellen baut sich per `crm_schema()` bei jedem Aufruf selbst auf.

## Regeln (wie im Dashboard)
1. Zu jeder `.php` eine co-located `.md`.
2. Keine Emojis in der UI. Großzügige Abstände.
3. **Handy zuerst.** Jede Seite zuerst auf 390 px, danach für breite Bildschirme.
4. Zeit immer UTC speichern, Anzeige über `fmt_zeit()`.
5. **Nie ein DELETE über Dashboard-Tabellen.** Das CRM löscht ausschließlich in `crm_`-Tabellen.
