# system/menu_editor.php – Menü-Editor (global)

Admin-Werkzeug, um das Haupt-Menü **für alle** anzupassen: Gruppen und Einträge per **Drag & Drop**
sortieren, Überschriften umbenennen, eigene Gruppen anlegen, Einträge ausblenden. Route `?p=menu_editor`,
Menü „System → Menü". Rolle: **nur Admin**.

## Wie es funktioniert
- `core/layout.php`: `bx_nav_default()` = Standard-Navigation (welche Seiten es gibt – die Quelle).
  `bx_nav_registry()` = jeder Punkt einmal flach (`key => label/def/group`).
  `bx_nav()` legt die gespeicherte Anpassung (app_meta **`menu_layout`**, JSON) über den Standard.
- Gespeichertes JSON: `{ "groups":[ {"label":…, "keys":[…]}, … ], "hidden":[…] }`.
- **Nichts geht verloren:** Punkte, die (noch) nirgends einsortiert sind (z. B. neue Seiten), hängt `bx_nav()`
  automatisch an ihre Standardgruppe an – gibt es die nicht, an eine Gruppe **„Weitere"**.
- **Zurücksetzen** löscht `menu_layout` → wieder der Code-Standard.

## Wichtig für die Weiterentwicklung
Neue Seiten einfach wie bisher in `bx_nav_default()` eintragen – sie erscheinen automatisch (ggf. unter
„Weitere"), bis der Admin sie einsortiert. Achtung: Existiert eine Admin-Anpassung, bestimmt **nicht** mehr
die Reihenfolge in `bx_nav_default()` die Anzeige, sondern `menu_layout`. Siehe [[bulkify-menue-umbau]].
