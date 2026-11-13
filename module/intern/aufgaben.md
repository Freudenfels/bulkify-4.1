# intern/aufgaben.php – Aufgaben („Das musst du machen")

**Zweck:** Aufgaben an Produktionsmitarbeiter senden und abarbeiten. Admin/Vorarbeiter legen an, das Werk arbeitet ab. Route `?p=aufgaben` (Rollen production/labor/fulfillment, admin sowieso) – im Werk-Menü unter „Cockpit/Aufgaben", im Admin-Menü unter „Produktion".

**Zuweisung an Person ODER Team:** `aufgabe.zugewiesen_an` = `benutzer.id` (Person) oder **NULL = Team** (alle sehen sie, jeder kann sie übernehmen/abhaken). Ein Mitarbeiter sieht seine eigenen + die Team-Aufgaben (`aufgaben_fuer_benutzer($uid)`).

**Anlegen:** Titel, Details, **Priorität** (1=Hoch, 2=Normal, 3=Niedrig, `prio_liste()`/`prio_badge()`), Zuweisen an (Team/Person), Fällig bis. `aufgabe_neu(...)`.

**Abarbeiten:** Aktionen per POST – `erledigt` (setzt status+erledigt_am/von), `offen` (wieder öffnen), `uebernehmen` (Team-Aufgabe sich selbst zuweisen). Liste sortiert nach Prio, dann Fälligkeit; überfällige Termine rot. Umschalter offene/erledigte.

**Cockpit-Anbindung:** Das Werk-Cockpit zeigt „Meine Aufgaben" (eigene + Team) mit direktem „Erledigt"-Button (`aktion=aufgabe_erledigt`, bleibt auf dem Cockpit). Kachel „Meine offenen Aufgaben" + Nav-Badge (Anzahl offener Aufgaben, wie ungelesene Mails).

**Tabelle `aufgabe`:** titel, beschreibung, prio, status(offen|erledigt), zugewiesen_an, erstellt_von, faellig, ref_typ/ref_id (optionaler Bezug z. B. produktionsauftrag), erledigt_am/von, angelegt(UTC).

**Priorität auch am Produktionsauftrag:** `produktionsauftrag.prio` (Spalte, Default 2); setzbar auf der PA-Detailseite (Dropdown), Badge + Standard-Sortierung nach Prio in der Produktionsliste und im Cockpit.

**Seit 13.11.2026 (Nico):**
- Die Liste zeigt nur **offene** Aufgaben (Zähler im Menü zählt nur offene). „Wieder öffnen“ gibt es nicht mehr; die POST-Aktion `offen` bucht nichts.
- Nach **Erledigt** geht es zurück zur offenen Liste mit der kurzen Meldung „Aufgabe erledigt.“ (`?p=aufgaben&erledigt=1`).
- **Archiv** (`?p=aufgaben&zeige=archiv`, alter Link `zeige=erledigt` landet auch hier): nur lesen, schlichte Tabelle Titel (Link zur Detailseite mit `von=archiv`) · Bezug (`aufgabe_bezug()`, Auftrag/PA/Bestellung/Artikel als Link) · erstellt am · erledigt am · erledigt von. Neueste oben, Suchfeld (Titel, Text, erledigt von), höchstens 500 Zeilen.
