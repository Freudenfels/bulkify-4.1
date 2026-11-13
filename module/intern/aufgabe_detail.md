# module/intern/aufgabe_detail.php – Einzelansicht einer Aufgabe

Route `?p=aufgabe&id=…`. Voller Text, Zugewiesen, Fällig, Erstellt, bei offenen Aufgaben die Knöpfe Übernehmen/Erledigt (Fastaction: Sprung ins Notepad).

**Seit 13.11.2026 (Nico):**
- „Zurück zu Aufgaben“ führt immer zur offenen Liste; nur mit `von=archiv` (aus dem Archiv geöffnet) zurück ins Archiv.
- Erledigt → direkt zurück zur offenen Liste mit Meldung „Aufgabe erledigt.“.
- Eine erledigte Aufgabe zeigt oben „erledigt am … von …“ und **keine Knöpfe** (kein „Wieder öffnen“ mehr).
- Neue Zeile **Bezug** (Link zu PA, Auftrag, Bestellung, Artikel …) aus `aufgabe_bezug()` (core/schema.php).
