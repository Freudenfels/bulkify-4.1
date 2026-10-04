# core/dokimport.php – Massen-Import von Specs/CoAs

## Wofür?
Viele Spezifikationen/CoAs als PDF auf einmal hochladen, die KI liest jede Datei nacheinander im
Hintergrund, ordnet sie einem **vorhandenen** Rohstoff zu, und nach der Prüfung durch den Menschen
werden alle bestätigten Dokumente an den Rohstoffen hinterlegt. Kein automatisches Neuanlegen von
Rohstoffen (kein Treffer = bleibt offen stehen).

## Tabellen (in core/schema.php)
- `dok_import_job`: ein Durchlauf. status `offen` (KI läuft) → `bereit` (Vorschau) → `fertig`/`abgebrochen`.
  Zählt `anzahl` (hochgeladen) und `gelesen` (KI-fertig inkl. Fehler).
- `dok_import_datei`: je PDF eine Zeile. status `offen`→`gelesen`/`fehler`→`importiert`/`uebersprungen`.
  Speichert `typ` (spec/coa), `sicherheit`, zugeordneten `item_id`, `quelle` (cas/name/fuzzy/manuell/''),
  und das vollständige KI-Ergebnis als `ki_json` (wird beim Import **wiederverwendet** – keine zweite KI-Analyse).

## Ablauf
1. `dokimport_job_neu($dateien, $user)` – Job + Zeilen anlegen, ersten Hintergrund-Lauf anstoßen.
2. `dokimport_datei_lesen($datei_id)` – läuft im Hintergrund (core/ki_job.php, art `dokimport`):
   `spec_ki_lesen()` + `spec_ki_match_item()`, Ergebnis speichern, **nächste** offene Datei anketten.
   Ist keine mehr offen → Job auf `bereit`.
3. Vorschau: `dokimport_zeilen()`, manuell zuordnen `dokimport_zuordnen()`, überspringen `dokimport_ueberspringen()`.
4. `dokimport_import($job_id)` – je bestätigter Zeile: Original als **internes** Dokument am Rohstoff
   (`kunde_sichtbar=0`, nie an Kunde) + `spec_ki_anwenden()` (Charge/Grenzwerte/Kennwerte/Wirkstoffe, additiv).
5. `dokimport_abbrechen($job_id)` – nicht übernommene Dateien aus data/uploads entfernen, Job schließen.

## Matching (spec_ki_match_item, in core/spec_ki.php)
Reihenfolge: CAS exakt → Artikelnummer/Name exakt → Name/Synonym-Teiltreffer (kürzester Name gewinnt).

## Hintergrund
Nutzt die vorhandene KI-Job-Mechanik (core/ki_job.php): kein Cron, keine Warteschlange – jede Datei
stößt per kurzem HTTP-Aufruf die nächste an. Jede KI-Analyse dauert bis ~4 Min, darum sequentiell.

## UI
`module/system/dok_massenimport.php`, Route `?p=dok_massenimport` (Rollen production/einkauf/labor),
verlinkt oben in der Rohstoff-Liste.
