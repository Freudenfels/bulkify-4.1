# produktion/core/werk_i18n.php – Mehrsprachigkeit der Werk-App

Nur für die Mitarbeiter-Werk-App (`?p=werk`). Drei Sprachen: **Deutsch, English, Українська**.

- `werk_sprachen()` → `['de'=>'DE','en'=>'EN','uk'=>'УКР']`.
- `werk_lang()` → aktuelle Sprache aus Session, sonst Cookie `werk_lang`, sonst `de`.
- `werk_lang_setzen($l)` → Session + Cookie (`/produktion/`, 1 Jahr) setzen. In `werk.php` über `?setlang=` ausgelöst (dann Redirect ohne Parameter).
- `werk_lang_switcher($lang, $id)` → die drei Pillen (GET-Links), aktive Sprache grün.
- `werk_station_label($station, $lang)` / `werk_station_anleitung($station, $lang)` → übersetzen die deutschen Original-Stationsnamen bzw. `station_anleitung_text()` NUR für die Anzeige. Die Produktionslogik nutzt weiter den deutschen Originalnamen.
- `werk_texte($lang)` → assoziatives Array aller UI-Texte. Fehlende Schlüssel fallen via `array_merge($de, …)` automatisch auf Deutsch zurück.
- `werk_js_texte($T)` → Teilmenge für das eingebettete JavaScript (als `WT` per `json_encode` injiziert).

**Pflege:** Neue Texte immer als Schlüssel in **allen drei** Sprach-Arrays (`$de/$en/$uk`) ergänzen und in `werk.php` nur `$T['schlüssel']` verwenden – nie Klartext. Platzhalter: `%d`/`%s` via `sprintf` (PHP), `%A%`/`%B%` via `.replace()` (JS). Produkt-/Rohstoffnamen und Chargennummern sind Daten und werden NICHT übersetzt.
