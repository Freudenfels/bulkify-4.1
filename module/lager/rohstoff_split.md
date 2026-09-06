# module/lager/rohstoff_split.php – Rohstoffe aufschlüsseln (Seite)

Seite `?p=rohstoff_split` (Nav: Lager → „Rohstoffe aufschlüsseln"; Rolle
production/einkauf/labor/admin). Zeigt alle Rohstoffe mit zu langem Namen (>70 Zeichen)
und lässt sie in einzelne Rohstoffe zerlegen.

## Bedienung
- Reiter **offen / übernommen / übersprungen**.
- **KI-Vorschläge erzeugen** (nur beta): füllt je Rohstoff eine Variantenliste vor.
- Je Rohstoff: der Original-Name steht oben, darunter eine **editierbare Textarea**
  (eine Variante pro Zeile, aus dem KI-Vorschlag vorbefüllt bzw. der Original-Name).
  **Aufschlüsseln übernehmen** legt die Varianten an; **Überspringen** markiert als erledigt.

## Was beim Übernehmen passiert
Die **erste Zeile** bleibt am Original-Datensatz (umbenannt, Original-Name in die Notiz),
jede weitere Zeile wird ein **neuer Rohstoff**. Logik in `core/rohstoff_split.php`.

Der manuelle Weg ist überall nutzbar und wurde end-to-end verifiziert; die KI ist nur der
Vorbefüller (nur beta).
