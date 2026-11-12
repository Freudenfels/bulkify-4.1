# layout.php – Seitenrahmen & Navigation

**Zweck:** Der gemeinsame Rahmen jeder Seite: Kopf, linke Navigation, Fuß. Damit sehen alle Seiten gleich aus.

**Was passiert hier:**
- `h($text)` – macht Text sicher für die Ausgabe (schützt vor kaputtem HTML / Angriffen). Wird überall genutzt.
- `fmt_zeit($utc)` – rechnet eine gespeicherte UTC-Zeit in **Berliner Zeit** um für die Anzeige.
- `bx_nav()` – die **zentrale Navigations-Definition** (Gruppen wie Vertrieb, **Anfragen**, Produktion, Lager … mit ihren Seiten). Nur hier wird das Menü gepflegt. Ein Eintrag ist entweder `slug => Label` (Link `?p=slug`) **oder** ein Array `['label','route','href','typ'|'ort']` für Punkte mit Query-Parameter (z. B. die Gruppe **Anfragen**: Rezepturanfragen + Produkt-/Rohstoff-/Dienstleistungsanfragen, die alle auf `portal_anfragen` mit unterschiedlichem `typ` zeigen; aktiver Punkt wird über route + `typ` erkannt).
- **Lager-Gruppe (2026-10-29):** **Warenlager** = Gesamtsystem (alle Lager), darunter **Lager 1** und **Fremdlager** (Lager 2), danach **Wareneingang**. Alle drei Warenlager-Punkte zeigen auf dieselbe Seite `?p=lager` (`bestand_liste.php`) mit dem Filter `&ort=…` (`alle`/`lager1`/`lager2`); unterschieden werden sie über den Diskriminator `'ort'` im Nav-Eintrag. `render_header()` erkennt den aktiven Punkt über route + `ort` (`$curOrt = $_GET['ort'] ?? 'alle'`). Der frühere separate Menüpunkt `lager2` (eigene Seite `lager2.php`) ist aus dem Menü raus; die Route bleibt für Altlinks bestehen. Gilt gleich im Haupt- und im Werk-Menü (`bx_nav_werk`).
- `bx_nav_werk()` – **eigenes, schlankes Menü für den Werk-Bereich** (Produktionsmitarbeiter): Cockpit · Produktion · Warenwirtschaft (Bestand/Wareneingang/Chargen/Rohstoffe/Verpackungen/Nährstoffe/Versand) · Entwicklung (Rezepturen/Rezepturanfragen). Kein Verkauf/Kunden/Rechnungen. `render_header()` wählt dieses Menü automatisch, wenn `ist_produktionsbereich()` (core/auth.php) wahr ist (Produktionsrolle ohne admin/sales/finance) – dann steht in der Marke „bulkify **Werk**". Login + index.php leiten solche Benutzer auf `?p=werk` (Cockpit) statt aufs Verkaufs-Dashboard.
- `render_header($aktiv, $titel)` – gibt Kopf, Sidebar-Menü (aktiver Punkt markiert) und den Anfang des Hauptbereichs aus. **Filtert die Navigation nach Rolle** über `route_erlaubt()` (aus `core/auth.php`): eine Seite erscheint nur, wenn die Rolle sie sehen darf; leere Gruppen werden ausgeblendet. Unten die **Benutzer-Box** (Name · Rollen · Abmelden). Ganz unten unter „Weiteres" ein **Lager-Link** (`lager/` → `/lager/`, für Admin, Produktion, Versand, Einkauf, Labor) und ein **CRM-Link** (`crm/` → `/crm/`, nur Admins); beides sind eigene Apps und läuft nicht über `?p=`, steht deshalb außerhalb des Menü-Loops.
- **Marke/Logo in der Sidebar:** `public/assets/bulkify-logo-white.png` – die **weiße** Wortmarke mit transparentem Hintergrund, weil die Sidebar dunkelgrün ist (`--dunkelgruen`, im Dark Mode unverändert). Vorher lag hier ein JPG, das keine Transparenz kann; dessen weißer Kasten wurde per CSS (`background:#fff;border-radius;padding`) kaschiert – beides ist raus, `.bx-brand .bx-logo` setzt jetzt nur noch die Höhe. Die dunkle Variante (`bulkify-logo-dark.png`) gehört auf helle Flächen (Login-Karte). Das JPG in `assets/` bleibt, weil die PDF-Erzeugung (`core/pdf_beleg.php`, `core/pdf_ppwr.php`) über MiniPDF nur JPEG einbetten kann.
- **Menübreite + Einklappen:** Die Breite steckt in der CSS-Variable `--side-w` (Standard 224px, `.bx-side` nutzt sie für `width` und `flex`). Am rechten Rand liegt ein fixierter Ziehgriff (`.bx-sidegriff`, 6px, `cursor:col-resize`) – ziehen verstellt die Breite zwischen **180 und 420px**, Doppelklick setzt auf 224px zurück. Der Griff ist fokussierbar: Pfeiltasten verstellen in 10er-Schritten (mit Shift 40er), Home setzt zurück. Einklappen über „Menü einklappen" im Sidebar-Fuß (`.bx-sidebtn`) setzt `data-side="zu"` auf `<html>`; dann ist die Leiste ausgeblendet und der fixierte Knopf `.bx-sideauf` („Menü") oben links klappt sie wieder auf. Beides wird in `localStorage` gemerkt (`bx-side-w`, `bx-side-zu`) und – wie beim Dark Mode – **schon im `<head>`** gesetzt, damit beim Laden nichts springt. Verdrahtung: `bx_side_script()` im Footer.
- `bx_anfrage_counts()` – zählt **offene Anfragen je Nav-Punkt** (offen = Status NOT IN `beantwortet`/`abgelehnt`): `anfragen` = `rezeptur_anfrage`, `paf_produkt`/`paf_rohstoff`/`paf_dienstleistung` = `portal_anfrage` je `typ`. Die drei Menüeinträge **Produktanfragen / Rohstoffanfragen / Dienstleistungsanfragen** verlinken jeweils mit ihrem `&typ=` auf `portal_anfragen`. Wer alle Typen auf einmal sehen will, stellt auf der Seite im Dropdown „Alle Typen" ein (Seiten-Default ist ohnehin `alle`, der Menü-Link setzt den Typ). `render_header()` hängt bei jedem dieser Punkte ein **Badge** (`.bx-navbadge`, wie ungelesene Mails) mit der Zahl an, solange > 0. Fehlertolerant (try/catch, Guard `function_exists('scalar')`), damit das Layout auch ohne die Tabellen lädt.
- Weitere Zähler in `render_header()` (nicht in `bx_anfrage_counts`): `aufgaben`, `bedarf`, `einkaufsliste`, **`auftraege`** (Aufträge mit Status NOT IN `versendet`/`storniert` = noch nicht fertig) und **`freigaben`** (offene Kundenfreigaben = Rohstoffe mit Spec-Inhalt ohne `spec_freigegeben` **plus** Chargen mit CoA-Werten ohne `coa_freigegeben`). Das Badge am Menüpunkt **Freigaben** zählt alle offenen – egal ob die Unterlage von Lieferant, Kunde oder uns kam.
- `render_footer()` – schließt die Seite ab (inkl. `bx_theme_script()` für den Dark-Mode-Umschalter).
- `bx_busy_script()` – **Lade-Rückmeldung**. Zwei Ebenen:
  - **Beim Absenden** (`submit`-Event): der geklickte Knopf bekommt einen Spinner (`.bx-spin`) und `aria-busy`, wird gegen Doppelklick gesperrt (erst nach dem Absenden via `setTimeout(0)`, damit sein Wert im POST bleibt), und oben läuft ein dezenter Ladebalken (`#bxLadebalken`). Der (übersetzte) Knopftext bleibt stehen, nur der Spinner kommt davor; mit `data-busy="…"` je Knopf ein eigener Text (z. B. „Wird ausgelesen…"). Ausnahmen: `data-no-busy`, `target="_blank"`/`formtarget="_blank"`.
  - **Bei jeder echten Navigation** (`beforeunload`): ein **Voll-Overlay** (`#bxOverlay`, halbtransparenter Schleier + zentraler Spinner `.bxo-spin`, z-index 99998) graut die ganze Seite aus und sperrt Klicks, bis die nächste Seite geladen ist. Greift für Formulare, Links, Zeilenklicks (`onclick=location.href`) und Zurück/Vor gleichermaßen. **Downloads, neuer Tab (`target=_blank`) und reine `#`-Anker entladen die Seite nicht → dort bewusst kein Overlay.** Failsafe: wird eine Navigation doch abgebrochen, verschwindet das Overlay nach 15 s von selbst.
  - Styles fürs Overlay bringt die Funktion selbst als `<style id="bxBusyStyle">` mit (unabhängig vom Stylesheet), damit es in allen drei Layouts wirkt. Der Ladebalken-Style steht in `assets/app.css`. Bei Zurück-Navigation (bfcache, `pageshow persisted`) wird alles zurückgesetzt. Eingebunden in `render_footer()` (intern), `lp_foot()` (Lieferantenportal), `portal_foot()` (Kundenportal).
- `bx_tabs_persist_script()` – **Reiter merken** (Stand 2026-10-08). Auf allen internen Seiten mit Reitern (`[data-tab]`-Links + `[data-panel]`-Sektionen: Lieferant/Kunde/Partner/Produkt/Auftrag/Lager-Detail). Merkt den zuletzt geklickten Reiter je Seite in `sessionStorage` (Key `bxtab:<p>:<id>`) und stellt ihn **nach vollständigem Parsen** (`DOMContentLoaded`) wieder her – ruft nur `.click()` auf die vorhandenen Reiter-Handler. Behebt zwei Dinge: (1) nach dem Speichern (POST→Redirect ohne Reiter-Info) springt man nicht mehr auf Reiter 1 zurück; (2) ein per `#hash` angesprungener Reiter (z. B. `#zugang` nach „Einladungslink erzeugen") zeigt seinen **Inhalt** auch dann, wenn das Panel im HTML NACH dem seiteneigenen Reiter-Skript steht – vorher klickte das Inline-Skript mitten im Parsen ins Leere (Tab aktiv, Panel noch nicht da → „zeigt nichts"). Vorrang: `#hash` vor gemerktem Reiter. Eingebunden nur in `render_footer()` (intern).
- **Dark Mode:** umschaltbar für alle (intern + Portal). Ein Init-Script im `<head>` setzt das gespeicherte Theme (`localStorage['bx-theme']`) auf `<html data-theme="dark|light">` **vor** dem Rendern (kein Flash). Der Button `.bx-themebtn` (in der Sidebar) schaltet um und merkt sich die Wahl. Farben kommen aus den Tokens unter `:root[data-theme="dark"]` in `app.css` – da fast alles CSS-Variablen nutzt, greift es überall. Login-Seite und Portal (`portal_head`/`portal_foot`) binden dasselbe Init-/Wiring-Script ein.

**Wichtig / Regel:**
- Menüpunkte werden **nur in `bx_nav()`** geändert – nicht in einzelnen Seiten.
- Wer welche Seite sehen darf, steht **nicht** hier, sondern in `route_rollen_map()` in [core/auth.php](auth.php).
- Jede Modul-Seite ruft am Anfang `render_header(...)` und am Ende `render_footer()` auf.

## Novel-Food News im Menü (Stand 2026-10-08)
`bx_nav_default()` enthält jetzt `novelfood_verlauf => 'Novel-Food News'` in den Gruppen Produkt und Entwicklung (Route existiert bereits: Aktualisierungs-Verlauf je Katalog-Lauf). Vorher war der Verlauf nur über den Button „Was ist neu (Verlauf)" auf der Novel-Food-Seite erreichbar.

## Menü-Badge „Anfragen & Preise" (Stand 2026-10-08)
`bx_nav()` setzt `$anfCount['lief_anfragen']` = Anzahl neu abgegebener, noch nicht bearbeiteter Lieferantenpreise (`lieferant_anfrage` JOIN `lieferant_angebot` WHERE `ag.status='offen'`). So sieht der Einkauf am Menü, wenn ein Lieferant einen Preis abgegeben hat (zusätzlich zur E-Mail `mail_team_preisanfrage` und der „Preis da"-Ansicht auf der Seite).

## Produktionsfreigabe im Menü (03.11.2026)
Gruppe Produktion, Key `produktionsfreigabe` (Route `produktion_vorbereitung`, nur Admin). Ein eigener Key, damit ein früher ausgeblendeter `produktion_vorbereitung` im gespeicherten `menu_layout` ihn nicht verdeckt. Bei gespeichertem Menü hängt er hinten an die Gruppe Produktion und lässt sich im Menü-Editor verschieben. Zähler `$anfCount['produktionsfreigabe']` = Produktionsaufträge mit Status `vorbereitung` ohne stornierte Aufträge, also dieselbe Menge wie die Liste.

Badge **Freigaben** (Lager) zählt seit 04.11.2026 auch Chargen in Quarantäne (Lager 1, mit Bestand).

Menüpunkt `produktionsfreigabe` heißt seit 04.11.2026 **Beschaffungsweg offen** (Zähler = Aufträge ohne festgelegten Weg).

**Suchfeld-Pflichtfeld (06.11.2026):** Ein `<select class="rscombo" required>` ist versteckt – war es leer, brach der Browser das Absenden **still** ab (er kann versteckte Felder nicht markieren; z. B. Easy Production „Vorschau passiert nichts“ ohne Mitarbeiter). Jetzt fängt `bx_rscombo_script()` das `invalid`-Ereignis ab: Das Suchfeld bekommt einen roten Rahmen (`.rs-invalid`), den Fokus und die Meldung „Bitte aus der Liste auswählen.“. Nach der Auswahl ist beides weg.

## Neue Menüpunkte bei gespeichertem Menü (08.11.2026)
Hat der Admin das Menü im Menü-Editor gespeichert (`app_meta menu_layout`), landen neue, dort noch nicht
einsortierte Punkte in ihrer Standardgruppe **direkt hinter ihrem Vorgänger aus `bx_nav_default()`** (falls der in
der Gruppe steht), sonst am Gruppenende. Beispiel: „Produktion (neu)" (`produktion_neu`) erscheint direkt unter „Produktion".

## Menü „Einfach / Ausführlich" (Schritt 2 Soll-Handbuch, 08.11.2026)
Gilt vor den älteren Absätzen oben (Anfragen-Gruppe mit 4 Punkten und Lager-Gruppe mit 3 Punkten sind damit überholt).
- **Umschalter je Nutzer:** Spalte `benutzer.menu_modus` (`einfach` / `ausfuehrlich`; leer = `einfach`). `bx_menu_modus()` liest ihn.
  In der Benutzer-Box unten steht `bx_menu_umschalter()` („Menü: Einfach | Ausführlich"); der Knopf postet auf die aktuelle Seite,
  `public/index.php` speichert am Nutzer und lädt dieselbe Seite neu. Werk-Nutzer (`bx_nav_werk()`) haben keinen Umschalter, ihr Menü ist unverändert.
- **Einfach** = `bx_nav_einfach()` (Stand 08.11.2026): **eine flache Liste ohne Gruppen** (kein Auf-/Zuklappen), feste Reihenfolge
  im Code: Anfragen · Angebote · Aufträge · Einkauf (Einkaufsliste) · Bestellt (`?p=einkauf`, Liste der Bestellungen, Icon truck; bleibt auf einer geöffneten Bestellung markiert) · Produktion · Lager (`?p=lager`) · Produkte · Rezepturen · Rohstoffe ·
  Buchhaltung (`buchhaltung/`, nur mit Rolle `finance` ausdrücklich in `benutzer.rollen`). Hängt **nicht** am Menü-Editor (`menu_layout`).
  Rechte filtern wie immer über `route_erlaubt()` – was eine Rolle nicht darf, fehlt. Rückgabe `['' => [key => def]]`; `render_header()`
  gibt im Modus einfach statt Gruppenkopf ein `<div class="bx-navflat">` aus. Jeder Punkt hat links ein Linien-Icon aus `bx_nav_icon($key)`
  (Inline-SVG im Lucide-Stil, `currentColor`, 19 px; inbox, file-text, clipboard-check, shopping-cart, factory, warehouse, package,
  flask-conical, leaf, calculator; `aufgaben` = list-checks für „Zu erledigen"). Größer: 15 px Schrift, 10 px Innenabstand, Zähler 22 px;
  am Handy 16 px. Seitenleiste bleibt 224 px. Platz für „Zu erledigen" ganz oben (kommt mit Schritt 4).
  **Benachrichtigungen** (08.11.2026) ist der erste Punkt (Icon bell, später unter „Zu erledigen"); Zähler = ungelesene des Nutzers, sitzt an der
  Glocke (ab 100 „99+"), bei 0 keiner. Im Modus einfach entfällt dafür der Benachrichtigungs-Link unten in der Benutzer-Box (Ausführlich und Werk
  unverändert). Rechte unverändert: Die Route `benachrichtigungen` ist laut Rollen-Map nur Admin – andere Rollen sehen den Punkt nicht.
  Das Logo links oben führt zur Startseite (`?p=dashboard`, Werk `?p=werk`).
- **Ausführlich** = `bx_nav()` wie bisher (Menü-Editor bestimmt Gruppen und Reihenfolge) plus Unterprogramme.
- **Anfragen = ein Punkt** (`anfragen`, Seite `module/anfrage/liste.php` mit allen Arten). Felder `alt_route`/`alt_href`: darf der Nutzer
  `anfragen` nicht, aber `portal_anfragen` (Einkauf), zeigt derselbe Punkt auf `?p=portal_anfragen`. `auch_aktiv` markiert den Punkt auch auf
  `portal_anfragen`. Zähler = offene Rezeptur- + Portal-Anfragen, soweit sichtbar. Die Keys `paf_*` gibt es im Menü nicht mehr (Zähler intern weiter).
- **Lager = ein Punkt** (`lager` → `?p=lager`); Lager 1 / Lager 2 sind Filter-Reiter auf der Seite. `lager_l1`/`lager_fremd` sind aus dem
  Hauptmenü raus (nur noch im Werk-Menü). **Rohstoffe** stehen in der Gruppe Produkt (bei gespeichertem Menü gilt weiter der Menü-Editor).
- Alte Bedarfsliste `?p=bedarf` war schon nicht im Menü; die Seite bleibt bis Schritt 5 unverändert. Jede ausgeblendete Seite bleibt per Link erreichbar.

- `bx_rscombo_script()` (10.11.2026): Die Suche berücksichtigt zusätzlich `data-such` an der Option, z. B. Synonyme eines Artikels.

## Rohstoff-Unterlagen (10.11.2026)
Unter der Gruppe **Anfragen** steht neu `rohstoff_unterlagen` („Rohstoff-Unterlagen“, Seite `module/anfrage/rohstoff_unterlagen.php`). Im Menü „Einfach“ steht der Punkt direkt nach Anfragen, mit eigenem Icon (Dokument mit Ausrufezeichen). In gespeicherten Menü-Layouts landet er automatisch hinter „Anfragen“.
- `bx_rscombo_script()` (11.11.2026, Nico: „Dropdowns immer mit Eingabe“): **jedes** `<select>` mit mehr als 10 Einträgen wird automatisch zum Suchfeld, nicht nur `class="rscombo"` (das erzwingt es weiter auch bei weniger).
- **Getippt statt angeklickt (12.11.2026, Nico: Wellgreen-Nachbestellung kam ohne Lieferant an):** Verlässt man das Suchfeld, ohne einen Vorschlag anzuklicken, übernimmt `autoPick()` einen **eindeutigen** Treffer selbst (erst exakter Name, sonst genau ein Teiltreffer). Das passiert synchron beim Verlassen, also vor dem Klick auf „Bestellen“/„Speichern“. Mehrdeutig oder kein Treffer: Der Wert bleibt wie vorher.
  - Auch nachträglich eingefügte Selects (neue Zeilen wie „+ Position“, Pop-ups) über einen MutationObserver.
  - Ausnahme: `data-norscombo` am Select oder an einem Eltern-Element; Mehrfachauswahl und `size>1` bleiben nativ.
  - Optionen werden bei jedem Öffnen frisch aus dem Select gelesen (per JS neu befüllte Listen bleiben aktuell); Werte, die andere Skripte setzen, werden nachgezogen.
  - Die leere Option (z. B. „Lager / allgemein“) erscheint als Platzhalter und oben in der Liste, damit man zurück auf leer wählen kann. Lange Einträge laufen beim Darüberfahren durch (Marquee), `title` zeigt den vollen Text.


Nach `<main>`: einmaliges Pop-up „Es gibt schon eine offene Bestellung“ (`core/bestellung_doppelt.php`), wenn die Sitzung `bx_doppelt` trägt (Sofortfix 11.11.2026).

`render_footer()` gibt oben auf der Seite den Hinweis „Preis abgelaufen - Summe unsicher“ aus, wenn auf der Seite mit einem abgelaufenen Preis gerechnet wurde (Block D, 11.11.2026).

## Lade-Kringel nur bei echtem Absenden (12.11.2026)
`bx_busy_script()` lauscht jetzt in der **Bubble-Phase** und prüft `e.defaultPrevented`. Vorher lief er in der Capture-Phase vor dem `onsubmit="return confirm(...)"` eines Formulars. Bei „Abbrechen“ oder einem unterdrückten Dialog wurde nichts gesendet, der Knopf blieb aber gesperrt und drehte endlos (Nico: „Preise freigeben dreht seit Minuten“).

Rückfall: Hat 8 Sekunden nach dem Klick keine Navigation begonnen (`beforeunload` setzt `nav`), wird der Knopf wieder freigegeben. Lange Anfragen (z. B. KI) sind davon nicht betroffen, weil die Navigation dort schon begonnen hat.
