# index.php – Der einzige Web-Einstieg (Front Controller)

**Zweck:** Jede Anfrage an bulkify 4.1 läuft durch diese eine Datei. Sie entscheidet, welche Seite (Modul) angezeigt wird. So gibt es keine 255 einzeln aufrufbaren Dateien mehr.

**Was passiert hier – Schritt für Schritt:**
1. Lädt `core/schema.php` und `core/layout.php` (damit DB, Bausteine und Layout bereitstehen).
2. Ruft `init_schema()` auf – stellt sicher, dass alle Tabellen existieren (harmlos, wenn sie schon da sind).
3. **Router:** Es gibt eine feste Liste erlaubter Seiten (`$routes`), z. B. `kunden` → `module/kunde/liste.php`. Der Parameter `?p=...` wählt die Seite. Unbekannte Werte landen automatisch auf dem Dashboard.
4. Bindet die gewählte Modul-Datei ein.

**Wichtig / Regel:**
- Neue Seiten werden **hier in `$routes` eingetragen** – sonst sind sie nicht erreichbar. Das ist die zentrale Sicherheits- und Ordnungsstelle (kein direkter Dateizugriff von außen).
- Der Web-Server zeigt nur den Ordner `public/` – alles andere (core, module, data) liegt geschützt dahinter.

## Feld-Änderungsprotokoll (Aufräumen 8)
Tabelle `aenderung_log` (objekt_typ, objekt_id, feld, alt, neu, benutzer, route, erstellt). `public/index.php` ruft bei jedem POST `aenderung_beobachten($route, $id)` auf: Der Hauptdatensatz der Seite (`aenderung_routen()`: Auftrag, Angebot, Produkt, Rezeptur, Kunde, Lieferant, Bestellung, Artikel, Produktionsauftrag, Kontingent, Dienstleistung, Benutzer) wird vorher gelesen und nach der Antwort (auch nach Redirect) verglichen. Jedes geänderte Feld wird mit alt/neu, Person und Zeit protokolliert (`aenderung_protokollieren()`). Technische und geheime Felder (Zeitstempel, Token, Passwort-Hash, Caches) sind ausgenommen. Anzeige: `?p=protokoll&ansicht=felder`, filterbar nach Bereich, Objekt-ID und Text. Positionen und Untertabellen werden nicht erfasst.
