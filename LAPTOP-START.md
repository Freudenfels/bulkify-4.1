# Laptop-Start – bulkify 4.1 (Weiterarbeiten auf einem anderen Rechner)

> Diese Datei liest du (bzw. Claude Code) zuerst, wenn du auf dem **Laptop** weitermachst.
> Ergänzt `CLAUDE.md` (Projektregeln) – die wird von jeder Claude-Code-Session automatisch gelesen.
> **Alles Wichtige liegt im Repo** (GitHub `Freudenfels/bulkify-4.1`), daher wandert es automatisch mit.

---

## 1. Was ist das / wo liegt alles
bulkify-ERP, PHP 8.3 + MariaDB/MySQL, kein Framework, serverseitig gerendertes HTML. Ein Repo mit
mehreren Programmen (je eigener Ordner + Front-Controller). Details stehen in **`CLAUDE.md`**.

| Programm      | URL           | Ordner                                   | eigene Doku            |
|---------------|---------------|-------------------------------------------|------------------------|
| Dashboard     | `/`           | `core/`, `module/`, `public/`             | `CLAUDE.md`            |
| CRM           | `/crm/`       | `crm/`, `public/crm/`                     | `crm/CRM.md`           |
| Lager         | `/lager/`     | `lager/`, `public/lager/`                 | `lager/LAGER.md`       |
| Produktion    | `/produktion/`| `produktion/`, `public/produktion/`       | `produktion/PRODUKTION.md` |
| Buchhaltung   | `/buchhaltung/`| `buchhaltung/`, `public/buchhaltung/`    | `BUCHHALTUNG.md`       |

**Regel „eine Naht je Sub-App":** Jede Sub-App greift auf geteilte Dashboard-Tabellen NUR über ihre
eigene `*/core/erp.php` zu (z. B. `produktion/core/erp.php`). Nie das Dashboard-`core/schema.php` in einer
Sub-App requiren (db()-Kollision).

Zu **jeder `.php` gibt es eine `.md`** daneben (Doku in einfachem Deutsch) – beim Ändern mitpflegen.

---

## 2. Laptop einmalig einrichten
```bash
git clone git@github.com:Freudenfels/bulkify-4.1.git
cd bulkify-4.1
```
1. **PHP 8.3** installieren (muss im PATH sein: `php -v`).
2. **MariaDB/MySQL** lokal: Datenbank `bulkify41`, Benutzer `bulkify` / Passwort `bulkify`
   (Zugang steht in `core/config.php`, lokaler Zweig). Leere DB reicht – das **Schema baut sich beim
   ersten Seitenaufruf selbst auf** (`init_schema()`, CREATE IF NOT EXISTS + additive Spalten).
3. **`secrets.php`** (nicht im Git!): nur nötig für echte Zugänge (SMTP, KI-Keys, `LG_BLINK_TOKEN`).
   Lokal zum Entwickeln meist nicht erforderlich. Nicht committen.
4. Starten:
   ```bash
   php -S 127.0.0.1:8741 -t public
   ```
5. **Erst-Admin** wird automatisch angelegt (`seed_benutzer_if_empty()`). Login-Token für bequemes
   Testen: `?p=autologin&token=<benutzer.login_token>` (nur localhost). Den Token findest du mit:
   ```bash
   php -r 'require "core/config.php"; require "core/db.php"; foreach(all("SELECT id,email,rollen,login_token FROM benutzer WHERE aktiv=1") as $u) echo $u["id"]."  ".$u["email"]."  [".$u["rollen"]."]  ".$u["login_token"]."\n";'
   ```
   Sub-Apps (Produktion/Lager/CRM/Buchhaltung) haben jeweils einen eigenen Autologin
   `/<app>/?p=autologin&token=<login_token>` (nur localhost).

> **Hinweis:** Die interne Loopback-Kette (z. B. Produktion → Lager „Blinker/Drucken") funktioniert
> auf dem lokalen `php -S`-Server NICHT zuverlässig (Single-Thread, blockiert sich selbst → Timeout).
> Auf dem echten Server läuft sie. Zum reinen Entwickeln ist das egal.

---

## 3. Git-Rhythmus (wichtig bei mehreren Rechnern/Chats)
- **Am Start:** `git pull --rebase`.
- **Nach jedem fertigen, getesteten Schritt:** `git add … && git commit && git pull --rebase && git push`.
- Wird der Push abgelehnt (anderer Chat war schneller): nochmal `git pull --rebase`, dann `git push`.
- **Nie `git push --force`.** Nur Fertiges committen (Auto-Deploy auf `main` → app.bulkify.pro + beta).
- Konflikte entstehen fast nur an gemeinsamen Dateien (`core/schema.php`, die Nähte `*/core/erp.php`)
  – dort anhalten und sauber lösen.

---

## 4. Arbeiten wie bisher: **ein Chat je Bereich**
Am besten pro Bereich einen eigenen Claude-Code-Chat öffnen (getrennte Ordner → verschiedene Dateien →
kaum Konflikte). Für jeden Bereich unten ein **Starter-Prompt** – einfach in einen neuen Chat kopieren.

### Dashboard-Chat
> Du bist der **Dashboard-Chat** von bulkify 4.1 (Repo `Freudenfels/bulkify-4.1`). Du arbeitest in
> `core/`, `module/`, `public/`. Lies zuerst `CLAUDE.md`. Halte den Git-Rhythmus ein (erst
> `git pull --rebase`, nach jedem fertigen Schritt committen + pushen). Zu jeder `.php` die `.md`
> mitpflegen. Offene Aufgaben für dich liegen als `AUFGABE-DASHBOARD-*.md` im Repo-Root.
> Lokal starten: `php -S 127.0.0.1:8741 -t public`.

### Produktions-Chat
> Du bist der **Produktions-Chat** von bulkify 4.1. Du arbeitest in `produktion/` und
> `public/produktion/`. Lies `CLAUDE.md` und `produktion/PRODUKTION.md`. Zugriff auf geteilte
> Dashboard-Tabellen NUR über `produktion/core/erp.php` (Naht). Git-Rhythmus + `.md`-Pflege wie immer.
> Die Mitarbeiter-Werk-App ist `?p=werk` (`produktion/module/produktion/werk.php`), Texte in
> `produktion/core/werk_i18n.php` (DE/EN/UK).

### Lager-Chat
> Du bist der **Lager-Chat** von bulkify 4.1. Du arbeitest in `lager/` und `public/lager/`. Lies
> `CLAUDE.md` und `lager/LAGER.md`. Dashboard-Zugriffe NUR über `lager/core/erp.php`. Die Druck-/Blinker-
> Brücke läuft auf dem Lager-PC (`public/lager/bruecke.php`, Drucker-Einstellungen in
> `lager/module/system/einstellungen.php`). Git-Rhythmus + `.md`-Pflege.

### CRM-Chat
> Du bist der **CRM-Chat** von bulkify 4.1. Du arbeitest in `crm/` und `public/crm/`. Lies `CLAUDE.md`
> und `crm/CRM.md`. Dashboard-Zugriffe NUR über `crm/core/erp.php`. Git-Rhythmus + `.md`-Pflege.

### Buchhaltungs-Chat
> Du bist der **Buchhaltungs-Chat** von bulkify 4.1. Du arbeitest in `buchhaltung/` und
> `public/buchhaltung/`. Lies `CLAUDE.md` und `BUCHHALTUNG.md`. Geteilte Dashboard-Tabellen NUR über
> `buchhaltung/core/erp.php`. Git-Rhythmus + `.md`-Pflege.

> Die **Website** ist ein eigenes Repo (`Freudenfels/bulkify-website`), nicht hier drin.

---

## 5. Wie sich die Chats gegenseitig Aufgaben geben
Chat-übergreifende Übergaben laufen über **Markdown-Dateien im Repo-Root**:
- `AUFGABE-<ZIELBEREICH>-<THEMA>.md` – ein Chat legt dem anderen eine Aufgabe hin.
- `RUECKMELDUNG-*.md` / `ANTWORT-*.md` / `STATUS-*.md` – Rückmeldungen/Zwischenstände.
So weiß jeder Chat, was ein anderer Bereich von ihm braucht. Erledigte Dateien darfst du löschen.

---

## 6. Aktueller Stand (zuletzt gemacht – Produktions-/Werk-App)
Zuletzt wurde die **Mitarbeiter-Werk-App** (`/produktion/?p=werk`) stark ausgebaut:
- **Dreisprachig** (Deutsch/English/Українська), Mitarbeiter wählt selbst (Umschalter + Cookie).
- **Scan-Bestätigung** je Pflicht-Charge (Kamera/QR oder Handscanner), Panel blendet aus wenn alle bestätigt.
- **Pick-to-Light** „Platz zeigen" je Material (Blinker über die Lager-Brücke).
- **Proben-Etikett** (Rückstellmuster) wird beim „Probe gezogen" automatisch über die Lager-Brücke gedruckt.
- **Mischen = Eimer für Eimer**: je Eimer ein Button „Eimer i fertig – Etikett drucken" (druckt ein
  Eimer-/Gebinde-Etikett), erst wenn ALLE Eimer fertig sind → Rohstoff-Rückgabe. Eigener Drucker
  `drucker_gebinde` in den Lager-Einstellungen.

**Noch am echten Server zu erledigen / zu testen** (Loopback-Druck geht nicht lokal):
- In den **Lager-Einstellungen → Drucker** die neuen Drucker wählen: „Drucker – Proben-Etikett"
  und „Drucker – Eimer-/Gebinde-Etikett" (leer = Standarddrucker). Brücke muss laufen.
- Werk-App am **Android-Tablet** installieren: Chrome → `app.bulkify.pro/produktion/?p=werk` → ⋮-Menü
  → „App installieren". (Die App läuft bewusst im Vollbild; Navigation in-App über „← Alle Aufträge".)

Detaillierte Doku jeweils in der co-located `.md` (z. B. `produktion/module/produktion/werk.md`).
