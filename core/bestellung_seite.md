# core/bestellung_seite.php – Bausteine der Bestellseite (13.11.2026)

Anlass: Nico zu BE-2956 – elf Blöcke, Positionen doppelt, Einbuchen doppelt, Stornieren dreifach. Jetzt überall dieselbe Gliederung: Kopf, Position, Reiter.
Genutzt von der Bestellseite (`module/einkauf/detail.php`), dem Bestell-Pop-up aus der Liste „Bestellt“ (`module/einkauf/bestellung_pop.php`) und Einkauf mobil (`module/einkauf/mobil.php`).

- `bs_daten($b)`: alles für Kopf und Reiter in einem Rutsch – Status-Stufe, Lieferant, Portal ja/nein, fehlende Unterlagen (`lu_bestellung_fehlt`), Rechnungen (Dokumente typ rechnung), Zahlstand (Spalte `bezahlt_am` oder alle Eingangsrechnungen der Buchhaltung bezahlt), Betrag gesamt (Summe Menge × EK ohne stornierte Positionen; online = Online-Preis), Teillieferung, Anzahl Chargen, ungelesene Nachrichten des Lieferanten, erste offene Position und Einbuchen-Adresse (mit vorgemerkter Charge).
- `bs_haupt($b, $d)`: der EINE nächste Schritt – Entwurf → „Bestellen“; bestellt/gesendet und noch offen → „Ware ist da - einbuchen“; mit Lieferant ohne Rechnung → „Rechnung erfassen“; Rechnung da, nicht bezahlt → „Als bezahlt markieren“; sonst keiner.
- `bs_haupt_link()`: derselbe Knopf als Link (Pop-up, mobil); Rechnung/bezahlt führen auf die Bestellseite mit `#rechnung` bzw. `#bezahlt` (öffnet dort das Pop-up).
- `bs_badges()`: Unterlagen fehlen, Rechnung offen, überfällig (T+n), Teillieferung, bezahlt.
- `bs_kopf_html($b, $d, $aktionen)`: Kopf-Karte – links Nummer, großer Status, Lieferant (Link), bestellt am, erwartet am, eingegangen, Badges; rechts Betrag und die übergebenen Knöpfe. Am Handy untereinander, Hauptknopf volle Breite.
- `bs_position_html($b, $stift)`: je Position eine Zeile (Artikel als Link, für Auftrag, bestellt, geliefert, offen, EK je Einheit in der Einheit der Position) mit Balken geliefert/bestellt. `$stift` = ID des Bearbeiten-Pop-ups (leer = kein Stift).
- `bs_reiter_html($tabs, $standard)`: Reiter-Leiste (am Handy waagerecht scrollbar) mit Zähler bzw. Punkt; Umschalten und Anker macht `bx-ui.js` (`data-bs-tab`/`data-bs-panel`), auch im nachgeladenen Pop-up.

CSS: Block „Bestellseite aufgeräumt“ in `public/assets/app.css` (`.bs-*`).

**Fable-6 T4.1 (13.11.2026):** Der Hauptknopf heißt bei offener Rechnung „In der Buchhaltung bezahlen“ und führt in die Buchhaltung (`er_link_bestellung`).
