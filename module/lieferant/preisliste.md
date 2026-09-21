# lieferant/preisliste.php – „Meine Preisliste" (Lieferantenportal)

**Zweck:** Der Lieferant sieht und pflegt seine **eigene Rohstoff-Preisliste** und aktualisiert sie regelmäßig. Route `?p=lieferant_preisliste`, Menüpunkt „Meine Preisliste".

**Datenbasis:** Tabelle `lieferant_preisliste` – gehört jetzt einem Lieferanten (`lieferant_id`; die v3-importierten Zeilen werden per Namensabgleich `firma == lieferant` zugeordnet, einmalig beim Migrieren). Felder je Zeile: `rohstoff_name`, `eur_kg`, `einheit` (Standard kg), `stand` (Datum des letzten Preises).

**4-Wochen-Regel:** je Lieferant ein Intervall `lieferanten.preis_intervall_tage` (Standard **28**; aus v3 `preis_update_tage` übernommen). Helfer in `core/schema.php`:
- `lieferant_preisliste_fuer($lid)` · `lieferant_preise_stand($lid)` (neuestes Datum) · `lieferant_preise_alter_tage($lid)` · `lieferant_preis_intervall($lid)`.
- `lieferant_preise_veraltet($lid)` – true, wenn der neueste Stand älter als das Intervall ist (Preise ohne Stand gelten als überfällig; keine Preise = nicht überfällig).
- `lieferant_preise_bestaetigen($lid)` – setzt `stand=heute` für alle Zeilen (erfüllt die Regel in einem Klick).

**Bedienung (Portal):**
- Ist die Preisliste überfällig, erscheint oben ein **roter Hinweis** mit Button **„Alle als aktuell bestätigen"** (setzt alle `stand` auf heute). Sonst ein grüner „aktuell"-Hinweis.
- Tabelle: Rohstoff · Preis (inline editierbar, „Aktualisieren" setzt `stand=heute`) · Stand (mit „!" wenn älter als Intervall) · Löschen. Darunter „Hinzufügen" (Rohstoff · Preis · Einheit).

**Intern:** Auf der Lieferanten-Detailseite (Reiter „Preise / Angebote") zeigt ein Panel **„Preisliste des Lieferanten"** dieselben Preise + ein Badge **aktuell/überfällig** (Alter in Tagen).

**Import:** `tools/v3_import.php` verknüpft die Preislisten-Zeilen mit dem Lieferanten (Name) und übernimmt das Intervall aus v3. Greift auf beta nach erneutem Import; die Namenszuordnung bestehender Zeilen läuft zusätzlich einmalig über die Migration (`preisliste_lief_link`).
