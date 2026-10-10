# Aufgabe (Dashboard-Chat): Produktionsplanung – geplantes Datum je Auftrag setzen

> Für den **Dashboard-Chat** (`core/`, `module/`, `public/`). Vorbereitet vom Produktions-Chat.

## Kontext / Schnittstelle
Das Produktions-Programm (`/produktion/`) zeigt und sortiert Aufträge nach dem geplanten Produktionsdatum
**`produktionsauftrag.geplant_am`** (DATE, existiert bereits):
- **Produktionsaufträge** und **Produktionsmodus**: Spalte „Wann dran", Sortierung nach `geplant_am` (ohne Termin ans Ende).
- **Kalender** (`/produktion/?p=kalender`): Monatsraster, Aufträge am Tag ihres `geplant_am`.

Die Produktion **liest** `geplant_am` nur. **Gesetzt/geplant wird im Dashboard.** Einziger Vertrag: das eine Feld
`produktionsauftrag.geplant_am` (DATE). Keine weiteren Felder nötig.

## Stand
- Feld ist da (`ensure_column('produktionsauftrag','geplant_am',"DATE NULL")`).
- Auf der Dashboard-Produktionsauftrag-Detailseite gibt es bereits ein **„Geplant am"**-Formular (`aktion=geplant`).
- Es gibt bereits `module/produktion/kalender.php` im Dashboard.

## Aufgabe
Eine **Planungs-/Terminübersicht** im Dashboard, mit der der Admin die Produktionsdaten vergibt:
1. **Sammel-Setzen:** Liste der **offenen/laufenden** Produktionsaufträge mit je einem **Datumsfeld** (geplant am) zum schnellen Setzen/Ändern – ohne jeden Auftrag einzeln öffnen zu müssen. Sinnvolle Sortierung (ohne Termin zuerst, dann nach Datum/Prio). Nur schreiben: `UPDATE produktionsauftrag SET geplant_am=? WHERE id=?` (leer = Termin entfernen).
2. **Kalender-/Wochensicht (optional, falls noch nicht):** Aufträge je Tag anzeigen; idealerweise Termin per Klick/Drag setzen. Falls `module/produktion/kalender.php` das schon kann, nur um das Setzen ergänzen.
3. **Nur für Planungsberechtigte** (Admin/Produktionsleitung), passend zu eurer Rollenlogik.

## Nicht nötig / bitte nicht
- Keine neuen Felder/Tabellen (nur `geplant_am`).
- Keine Änderung an `/produktion/` – das macht der Produktions-Chat. Ihr setzt nur das Datum.

## Hinweise
- Additive Schemaänderung nur falls doch nötig; `geplant_am` existiert schon.
- Co-located `.md` pflegen. Rebase-Ampel: `git pull --rebase` vor Push, gezielt stagen.
- Ergebnis-Check: Datum im Dashboard setzen → erscheint sofort in `/produktion/` (Spalte „Wann dran", Kalender, Produktionsmodus-Sortierung).
