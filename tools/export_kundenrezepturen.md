# tools/export_kundenrezepturen.php – Kunden-Rezepturen gezielt nachliefern

## Wozu
Wenn Laptop und Server (beta) auseinandergelaufen sind und auf dem Server die **kundeneigenen
Rezepturen fehlen** (z. B. „Pure Health hat in v4 keine eigenen Rezepturen"), bringt ein kompletter
Dump den Server zwar auf Stand, würde aber die dort neueren Daten (Rohstoffe/Produkte/Aufträge)
überschreiben. Dieses Werkzeug erzeugt stattdessen ein **gezieltes, idempotentes `.sql`**, das NUR die
kundeneigenen Rezepturen (+ Zutaten) nachliefert – ohne alles andere anzufassen.

## Wie es verknüpft
Die Zuordnung läuft über **`v3_id`** (nicht über lokale IDs, die auf dem Server anders sind):
- Kunde auf dem Server = `(SELECT id FROM kunden WHERE v3_id=<v3-Kunde>)`.
- Rezeptur wird per `rezeptur.v3_id` gefunden: fehlt sie → anlegen; ist sie da (evtl. ohne kunde_id) → korrekt verknüpfen.
- Zutaten werden je Rezeptur neu aufgebaut (löschen + einfügen), `item_id` auf dem Server per Rohstoff-Name aufgelöst, sonst NULL (die Bezeichnung bleibt immer erhalten).
- Alle `NOT EXISTS`/Selbstbezüge sind mit Derived-Table gewrappt (MySQL-Fehler 1093 vermieden).

Nur Rezepturen, deren Kunde selbst eine `v3_id` hat, sind zuordenbar und werden exportiert.

## Aufruf
```
php tools/export_kundenrezepturen.php            # schreibt data/kundenrezepturen_<stamp>.sql
php tools/export_kundenrezepturen.php --apply    # zusaetzlich lokal ausfuehren (Selbsttest; muss No-Op sein)
```

## Ablauf für den Server
1. Lokal `v3_import.php --write` laufen lassen (falls nötig), damit die Rezepturen in der Laptop-DB stehen.
2. `php tools/export_kundenrezepturen.php` → erzeugt die `.sql` in `data/` (nicht im Git).
3. Die `.sql` über **?p=db_import** auf beta hochladen (Bestätigungswort `ERSETZEN`).
   Der Import führt jede Anweisung einzeln aus; es werden nur Rezepturen/Zutaten ergänzt/verknüpft.
4. Mehrfaches Hochladen schadet nicht (idempotent).

**Kein Dump/kein Reset** – dieses `.sql` ersetzt die DB NICHT, es ergänzt gezielt.
