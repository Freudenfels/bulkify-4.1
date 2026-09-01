# lieferant/bestellung.php – Bestellungen im Lieferantenportal

Route: `?p=lieferant_bestellung[&id=<ID>]`

Ohne `id` die Liste, mit `id` die einzelne Bestellung: das **Ablauf-Panel** (`core/bestellung_ui.md`) zum Bestätigen mit Termin, Stationen pflegen und Versanddaten eintragen, darunter die Positionen mit Preisen und Summe sowie das Bestell-PDF.

**Zugriff:** Jede Abfrage filtert auf `lieferant_id` des angemeldeten Benutzers – die Prüfung hängt an der Abfrage, nicht an der Oberfläche. Eine fremde ID zeigt einfach die eigene Liste.
