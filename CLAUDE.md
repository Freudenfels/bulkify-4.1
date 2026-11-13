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
- **Buchhaltung** unter `/buchhaltung/` - **eigenes Programm** (eigene Sitzung `BXBUCH`, eigener Login,
  Rolle `finance`/`admin`), wie CRM/Lager/Produktion. Code in `buchhaltung/core/` + `buchhaltung/module/`,
  Front Controller `public/buchhaltung/index.php`. **Alle** Zugriffe auf geteilte Dashboard-Tabellen nur in
  `buchhaltung/core/erp.php`. Finanz-eigene Tabellen (beleg*, zahlung, lieferant_rechnung/_zahlung, guthaben_bewegung)
  verwaltet die Buchhaltung; das Dashboard **liest** sie weiter. `beleg_firma()`/PDF-Helfer + `ki.php` bleiben im
  Dashboard-`core` (quer genutzt) und liegen als Kopie in `buchhaltung/core`. Details: `BUCHHALTUNG.md`.

## Lokal starten
```
php -S 127.0.0.1:8741 -t public
```
DB-Zugang: `core/config.php` (lokal `bulkify41`, User/Pass `bulkify`/`bulkify`). Schema baut sich per `init_schema()` bei jedem Aufruf selbst auf (CREATE IF NOT EXISTS + additive `ensure_column`). Erst-Admin: `seed_benutzer_if_empty()`.

## Soll-Handbuch (Vorrang)
Bei größeren Aufgaben (neue Abläufe, Umbauten über mehrere Seiten, Datenmodell, Menü) zuerst `docs/soll-handbuch.md` lesen.
Es hat Vorrang vor älteren MDs; neue Anweisungen von Nico gehen vor und werden danach im Handbuch nachgetragen (Abschnitt 14).

## Wichtige Regeln (bitte einhalten)
1. **Zu jeder .php-Datei eine co-located `.md`** in einfachem Deutsch (Doku). Bei Änderungen mitpflegen.
2. **Nie committen:** `data/` (Uploads/DB/Logs), `secrets.php`, echte Zugangsdaten – ist per `.gitignore` ausgeschlossen und bleibt es.
3. **Keine Emojis in der UI.** Feld-/Spaltenüberschriften nie fett. Großzügige Abstände. (Siehe UI-Memories.)
4. **Zeit:** immer UTC speichern, Anzeige via `fmt_zeit()` → Europe/Berlin.
5. **Verifizieren:** `php -l` + kurzer curl-Test (Admin-Autologin nur localhost). **Nie pauschale DELETEs in der DB** (es wird parallel gearbeitet).
6. **Demo-Seeding ist AUS** (`app_meta seed_demo_off=1`) – die Demo-Seeds legen sonst beim Seitenaufruf wieder Daten an.
7. **Lieferant auf Dokumenten und Etiketten nur als Lieferantennummer (L-xxxx), nie Name/Adresse** (Kundendokumente und alles im Lager; Regel Nico 13.11.2026). Interne Bildschirmseiten dürfen den Namen zeigen. `kunde_text_neutral()` ersetzt L-Nummern nie.

## Oberflächen-Standard (Regel Nico 13.11.2026, im ganzen System)
Ein Baustein: `public/assets/bx-ui.js` (in allen Layouts eingebunden). Details: Handbuch Abschnitt 1, Punkt 11.
1. **Pop-up statt neuer Seite/neuem Fenster:** `<dialog class="bx-dialog">` – Titel + X oben, Knöpfe unten (Hauptknopf rechts), Inhalt scrollt innen, Escape/Klick daneben schließt (Rückfrage bei Ungespeichertem), Speichern ohne Seitenwechsel per `data-pop-ajax` + `data-pop-refresh`. Seite im Pop-up: `bxPopup.url()` / `data-pop-url`. Kein eigenes Dialog-CSS mehr bauen.
2. **Auswahl mit Suche:** jedes `<select>` mit mehr als 10 Einträgen automatisch (`public/assets/bx-rscombo.js`); Synonyme/Nummern über `data-such` an der Option.
3. **Lange Texte laufen:** einzeilig mit „…“ (`.bx-lauf`, in `bx_table()` automatisch für Namens-Spalten), beim Darüberfahren/Antippen Lauftext.
4. **Pflichtfelder (13.11.2026):** Pflichtfelder mit Sternchen, Prüfung vor dem Absenden, bei Fehlern nie Eingaben verlieren. Baustein `data-pflicht` (`public/assets/bx-pflicht.js`, lädt `bx-ui.js`): `data-pflicht` = immer, `data-pflicht="#feld=wert1,wert2"` bzw. `"#haken:checked"` = abhängig; fehlt etwas, wird nicht gesendet, das Feld wird rot mit „… fehlt“. Lehnt der Server ab: Seite mit allen Eingaben zurück (kein Redirect ohne Daten), Feld mit `class="bx-pflicht-fehlt" data-pflicht-msg="…"`.
5. **Knöpfe und Tippflächen (Fable-5, 13.11.2026):** im ganzen System nur **zwei Knopfgrößen** – normal (`.btn`, 36 px) und klein (`.btn-sm`, 30 px), keine eigenen Höhen per `style`. Am Handy (bis 860 px) sind Knöpfe, Menüzeilen und Glocke **mindestens 44 px** hoch (CSS in `app.css`).
6. **Handy ohne seitliches Scrollen:** Die Seite ist am Handy nie breiter als der Bildschirm (390 px). Tabellen stehen in `.bx-tablewrap` (eigener Scroll-Rahmen) oder werden mit `class="bx-table bx-karten-handy"` am Handy als Karten je Zeile gezeigt (Beschriftung setzt `bx-datei.js`). Kacheln (`.bx-cards`) am Handy 2 je Zeile.
7. **Datei-Felder:** rohe `<input type="file">` bekommen automatisch den Knopf „Datei wählen“ im bulkify-Stil (`bx-datei.js`). **Dunkler Modus:** Badges und Hinweise mit dunklem Grund und farbiger Schrift, Kontrast mindestens 4,5 : 1; Symbole ✓/✗ als SVG (`bx_sym()`), nicht als Schriftzeichen.

## Git-Rhythmus (Multi-PC & parallele Code-Chats)
Pull am Start, commit+push nach jedem **fertigen, getesteten** Schritt. Einzige gemeinsame Wahrheit = GitHub.

**Parallel arbeiten erlaubt** – am besten **ein Chat pro Bereich** (Lager `lager/`, CRM `crm/`, Dashboard `core/`+`module/`+`public/`; getrennte Ordner → verschiedene Dateien → kein Konflikt). Koordination = **Rebase-Ampel**: **vor jedem Push `git pull --rebase`, dann `git push`.** Wird der Push abgelehnt (anderer Chat war schneller = rot), einfach nochmal `git pull --rebase` und pushen (grün). **Niemals `git push --force`.** Echte Merge-Konflikte entstehen nur bei **gemeinsamen Dateien** – v. a. `core/schema.php` und die Nähte `*/core/erp.php`; dort anhalten und sauber lösen. Alle pushen nach `main` (Auto-Deploy), deshalb nur Fertiges committen.

## Memory-Abzug nach Google Drive
Nach **jeder** Änderung an den Memory-/MD-Dateien im Memory-Ordner **alle** `.md` (inkl. `MEMORY.md`) nach `G:\Meine Ablage\bulkify v4 md` kopieren.
- Gleichnamige Dateien dort **überschreiben**.
- Dort **nichts löschen**, was nicht aus dem Memory-Ordner stammt.
- Diese Kopie ist nur ein **Abzug** für Nico/Drive, **nicht** die Quelle – gearbeitet wird weiter im Memory-Ordner.

## Pläne vor dem Bauen (Regel Nico 11.11.2026)
Muss bei einer größeren Anweisung vor dem Bauen ein Vorschlag/Plan an Nico (welche Seiten, welche Tabellen, Entscheidungen), dann den Plan **immer als `plan-<thema>.md`** in den Drive-Ordner `G:\Meine Ablage\bulkify v4 md` legen und im Chat in einem Satz darauf hinweisen. Nach Freigabe Nicos Ergänzungen in dieselbe Datei einarbeiten.

## Checkliste am Ende jeder Anweisung (Regel Nico 11.11.2026)
Jede Arbeitsanweisung endet mit einer nummerierten Checkliste. Die Rückmeldung beantwortet jeden Punkt mit 'erledigt (Commit)' oder 'nicht erledigt (Grund)' und nennt den grünen Deploy-Lauf.

## Wer
Ansprechpartner: **Nico** (thomalla@freudenfels.de). Stil: direkt, knapp, umsetzungsorientiert.
