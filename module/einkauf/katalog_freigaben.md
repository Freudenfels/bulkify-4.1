# einkauf/katalog_freigaben.php – „Katalog-Freigaben" (Einkauf)

**Zweck:** Zentrale Stelle im Menü **Einkauf**, an der das Team **alles** prüft, was Lieferanten in ihrem Portal unter „Mein Katalog" hochladen oder eintragen – Preisliste, CoA/Spezifikation und manuelle Zeilen. Route `?p=katalog_freigaben`.

**Warum es das gibt:** Solche Uploads landen als Zeilen in `lieferant_katalog` mit `status='neu'`. Vorher konnte man sie nur einzeln im jeweiligen Lieferantenkonto (Reiter „Katalog") prüfen – man musste also wissen, welcher Lieferant etwas hochgeladen hat. Diese Seite zeigt alle offenen Uploads über **alle** Lieferanten auf einen Blick. (Nicht zu verwechseln mit **Lager → Freigaben**: das sind Kunden-Freigaben von Spezifikationen/CoA an bereits bestehenden Rohstoffen/Chargen.)

**Bedienung:** Tabelle mit Lieferant (Link ins Konto, Reiter Katalog) · Artikel · Typ · Preis · **Im Bestand?** · Aktion. Jede Zeile hat einen **Ansehen**-Knopf, der ein **Popup** öffnet – so muss man nicht in die Detailseite wechseln.

**Matchen zuerst (Stand 2026-10-08):** Gibt es einen **eindeutigen** Treffer – gleiche CAS **oder** exakt gleicher Name (`$starkTreffer`, nicht gesperrt) –, zeigt die Spalte „Im Bestand?" direkt den Treffer (Badge „vorhanden" + R-Nummer·Name) und die Zeile bekommt als **Haupt-Button** „**Diesem Rohstoff zuordnen**" (1 Klick → `kat_uebernehmen` mit `item_id`, Preis + CoA wandern dorthin). „Ansehen" wird dann zur Nebenaktion (Ghost); im Popup ist „Als neuen Artikel anlegen" ebenfalls nur noch Ghost. So entsteht **keine Dublette**, wenn derselbe Stoff schon angelegt ist (ein Rohstoff, viele Lieferanten über Kürzel/Kennung). Ohne eindeutigen Treffer bleibt es beim bekannten „evtl. vorhanden (N)"/„neu"-Badge und „Ansehen" als Haupt-Button.

**Popup je Zeile** (`.bx-dialog`, theme-tauglich; liegt bewusst **außerhalb** der Tabelle, sonst löst der Browser ein `<dialog>` im `<table>` heraus):
- **Angaben des Lieferanten:** Name, Original/Englisch, CAS, Spezifikation, Herkunft, Preis + ab Menge, Notiz.
- **Aus CoA/Spezifikation gelesen:** falls die Zeile aus einem CoA/Spec-Upload stammt (`ki_json`), die erkannten Wirkstoffe (mit Gehalt %) und Kennwerte.
- **Schon im Bestand?** Liste möglicher Treffer via `katalog_aehnliche()` (gleiche CAS, gleicher Name, ähnliche Namensteile) – jeder mit **Öffnen** (Link zum Rohstoff/Produkt) und **Preis dorthin** (ordnet den Preis dem bestehenden Artikel zu). Kein Treffer → Hinweis „wahrscheinlich neu".
- Bei einem **eindeutigen** Treffer (`$starkTreffer`) steht zusätzlich ein hervorgehobener Kasten „Diesem Rohstoff zuordnen" ganz unten vor den Schlussknöpfen.
- Unten: **Als neuen Artikel anlegen** (bei eindeutigem Treffer nur Ghost) und **ablehnen**.

**Aktionen:**
- **Anlegen / Preis dorthin** (`kat_uebernehmen`): ohne `item_id` neuer Artikel + EK-Preis; mit `item_id` (aus „Preis dorthin") wandert der Preis zum bestehenden Artikel (`katalog_uebernehmen()`).
- **ablehnen** (`kat_ablehnen`): verwirft die Zeile (`katalog_ablehnen()`).

**Doppelte vermeiden:** `katalog_aehnliche($zeile, $limit)` (in `core/lieferant_katalog.php`) sucht bestehende Artikel über gleiche CAS, gleichen Namen und aussagekräftige Namensteile (≥4 Zeichen), damit derselbe Rohstoff nicht mehrfach angelegt wird.

Dieselben Funktionen nutzt der Reiter „Katalog" im Lieferantenkonto (`module/lieferant/detail.php`); die Seite hier ist nur die gesammelte Sicht. Details der Katalog-Logik: `core/lieferant_katalog.md`.

**Menü & Zähler:** Eintrag in `core/layout.php` (Gruppe Einkauf) mit Badge = Zahl offener Zeilen (`SELECT COUNT(*) FROM lieferant_katalog WHERE status='neu'`). Rechte: `einkauf`, `finance` (+ admin) in `core/auth.php`. Zusätzlich zeigt die **Lieferantenliste** eine Spalte „Zu prüfen" je Lieferant (siehe `module/lieferant/liste.md`).

## Preis-Aktualisierungen aus dem Portal (Stand 2026-10-08)
Hier landen auch **Preis-Aktualisierungen**, die ein Lieferant im Portal für einen ihm zugeordneten Artikel vorschlägt (`katalog_preis_vorschlag()`, Abschnitt „Von bulkify bei Ihnen geführt"). Diese Zeilen haben `item_id` gesetzt und `katalog_aehnliche()` zeigt den bestehenden Artikel als „gleicher Name"-Treffer → mit „Preis dorthin" wandert der neue Preis in `lieferant_preis` des Artikels (kein neuer Artikel).
