# lieferant/preisliste.php – „Rohstoff-Preise" (Lieferantenportal)

**Zweck:** Der Lieferant sieht und pflegt die **Rohstoff-Preise**, die wir bei ihm führen. Route `?p=lieferant_preisliste`, Menüpunkt **„Rohstoff-Preise"**. Das Menü zeigt diesen Punkt nur Materiallieferanten bzw. Lieferanten mit geführten Rohstoffen (siehe `portal_layout.md`, Menü-Gating).

**Umbau 2026-10-08 – „Preisliste = geführte Rohstoffe":** Es gab drei Preis-Orte beim Lieferanten (Portfolio-Katalog, geführte Artikel mit Staffel, flache Preisliste). Nico-Entscheidung: Diese Seite ist jetzt die **echte Rohstoff-Preisseite** und zeigt als **Hauptinhalt die von bulkify geführten Rohstoffe** (`lieferant_gefuehrte_artikel($lid)` → `item` + `lieferant_preis`) **mit Staffel/Währung**. „Mein Katalog" ist davon getrennt = reines Portfolio (was der Lieferant anbietet).

**Datenbasis:**
- **Hauptinhalt – geführte Rohstoffe:** `lieferant_gefuehrte_artikel($lid)` (Hauptlieferant ODER eigener Staffelpreis). Je Artikel: unsere Spezifikation (Wirkstoffe/Kennwerte), **Ihr Preis** (Staffeln in `lieferant_preis`, Währung je Zeile). Button **„Preis aktualisieren"** → Popup `#dlgPreis` → POST `aktion=preis_vorschlag` → `katalog_preis_vorschlag()`. Der neue Preis geht als **Prüf-Zeile ans Team** (Katalog-Freigaben), **nie direkt live** (gleicher Weg wie früher im Katalog-Reiter).
- **Zusatz unten – Alt-Format:** Tabelle `lieferant_preisliste` (frei eingetippt, `rohstoff_name`/`eur_kg`/`einheit`/`stand`, **ohne Staffel**). Wird nur angezeigt, wenn solche Zeilen existieren – damit beim Umbau nichts verloren geht. Weiter direkt pflegbar (speichern/hinzufügen/löschen).

**4-Wochen-Regel:** gilt (Stand 2026-10-08) weiter für die **Alt-Format-Zeilen** (`lieferant_preisliste`); der rote „überfällig"-Hinweis oben erscheint nur, wenn solche Zeilen vorhanden und überfällig sind. (Offener Ausbau: Fälligkeit auch auf die Staffel-Preise der geführten Rohstoffe anwenden.) Je Lieferant ein Intervall `lieferanten.preis_intervall_tage` (Standard **28**; aus v3 `preis_update_tage` übernommen). Helfer in `core/schema.php`:
- `lieferant_preisliste_fuer($lid)` · `lieferant_preise_stand($lid)` (neuestes Datum) · `lieferant_preise_alter_tage($lid)` · `lieferant_preis_intervall($lid)`.
- `lieferant_preise_veraltet($lid)` – true, wenn der neueste Stand älter als das Intervall ist (Preise ohne Stand gelten als überfällig; keine Preise = nicht überfällig).
- `lieferant_preise_bestaetigen($lid)` – setzt `stand=heute` für alle Zeilen (erfüllt die Regel in einem Klick).

**Bedienung (Portal):**
- Ist die Preisliste überfällig, erscheint oben ein **roter Hinweis** mit Button **„Alle als aktuell bestätigen"** (setzt alle `stand` auf heute). Sonst ein grüner „aktuell"-Hinweis.
- Tabelle: Rohstoff · Preis (inline editierbar, „Aktualisieren" setzt `stand=heute`) · Stand (mit „!" wenn älter als Intervall) · Löschen. Darunter „Hinzufügen" (Rohstoff · Preis · Einheit).
- Die Preis-Zeile ist **einzeilig** (`flex-wrap:nowrap`): Eingabefeld · Einheit · „Aktualisieren" nebeneinander (die `.bx-row`-Voreinstellung `flex-wrap:wrap` würde den Button sonst darunter umbrechen).

**Intern:** Auf der Lieferanten-Detailseite (Reiter „Preise / Angebote") zeigt ein Panel **„Preisliste des Lieferanten"** dieselben Preise + ein Badge **aktuell/überfällig** (Alter in Tagen).

**Import:** `tools/v3_import.php` verknüpft die Preislisten-Zeilen mit dem Lieferanten (Name) und übernimmt das Intervall aus v3. Greift auf beta nach erneutem Import; die Namenszuordnung bestehender Zeilen läuft zusätzlich einmalig über die Migration (`preisliste_lief_link`).
