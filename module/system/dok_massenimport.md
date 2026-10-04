# module/system/dok_massenimport.php – Specs/CoAs Massen-Import (Seite)

Oberfläche zum Massen-Import von Spezifikationen/CoAs. Route `?p=dok_massenimport`
(Rollen: production, einkauf, labor). Logik steckt in `core/dokimport.php`.

## Drei Zustände (gesteuert über den aktiven Job)
1. **Kein Job** – Upload-Formular: mehrere PDFs (auch JPG/PNG/WEBP) auf einmal, bis 150 je Durchlauf.
2. **Job `offen`** – Fortschrittsbalken „X von Y gelesen", lädt sich alle 5 s selbst neu. Abbrechen möglich.
3. **Job `bereit`** – Match-Vorschau als Tabelle: Datei · Typ · KI-Sicherheit · zugeordneter Rohstoff
   (mit R-Nummer-Link + Trefferquelle) · Aktion. Pro Zeile manuell zuordnen/ändern (Datalist über
   Rohstoff-Namen) oder überspringen. „Alle N übernehmen" importiert die bestätigten Zeilen.

## Wichtig
- Es wird immer nur **intern** abgelegt (`kunde_sichtbar=0`) – Originale gehen nie an Kunden.
- Kein Treffer = Zeile bleibt offen (kein automatisches Neuanlegen von Rohstoffen).
- Braucht die KI (nur auf Server/beta/live eingerichtet); lokal ist der Upload deaktiviert.

## PRG-Aktionen
`upload`, `zuordnen`, `skip`/`unskip`, `import`, `abbrechen` – alle mit Redirect auf `?p=dok_massenimport`.
