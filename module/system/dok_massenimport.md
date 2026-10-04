# module/system/dok_massenimport.php – Specs/CoAs Massen-Import (Seite)

Oberfläche zum Massen-Import von Spezifikationen/CoAs. Route `?p=dok_massenimport`
(Rollen: production, einkauf, labor). Logik steckt in `core/dokimport.php`.

## Drei Zustände (gesteuert über den aktiven Job)
1. **Kein Job** – Upload-Formular: mehrere PDFs (auch JPG/PNG/WEBP) auf einmal, bis 150 je Durchlauf.
2. **Job `offen`** – Fortschrittsbalken „X von Y gelesen", lädt sich alle 5 s selbst neu. Abbrechen möglich.
3. **Job `bereit`** – Match-Vorschau als Tabelle: Datei · Typ · KI-Sicherheit · zugeordneter Rohstoff
   (mit R-Nummer-Link + Trefferquelle) · Aktion. Pro Zeile manuell zuordnen/ändern (Datalist über
   Rohstoff-Namen) oder überspringen. „Alle N übernehmen" importiert die bestätigten Zeilen.

## Vorschau & Nachladen
- Jede Zeile hat „Ansehen" → Popup (Overlay mit iframe) der hochgeladenen Datei. Ausgeliefert inline über
  `?p=dok_massenimport&vorschau=<datei_id>` (PDF/Bild, rollengeschützt).
- „Weitere Dateien nachladen" gibt es im Fortschritt UND in der Vorschau (hängt an denselben Job an).

## Weggehen ist sicher
Das Einlesen läuft serverseitig (Worker stoßen sich selbst an, unabhängig vom Browser). Seite verlassen/Tab
schließen stoppt nichts; der Stand steht in der DB. Beim Zurückkommen zeigt die Seite Fortschritt/Vorschau,
und falls die Kette abgerissen ist (z. B. Server-Neustart), stößt das Öffnen der Fortschrittsseite die Worker
automatisch wieder an (nur wenn nichts „in Arbeit", aber noch „wartend" ist).

## Wichtig
- Es wird immer nur **intern** abgelegt (`kunde_sichtbar=0`) – Originale gehen nie an Kunden.
- Kein Treffer = Zeile bleibt offen (kein automatisches Neuanlegen von Rohstoffen).
- Braucht die KI (nur auf Server/beta/live eingerichtet); lokal ist der Upload deaktiviert.

## PRG-Aktionen
`upload`, `zuordnen`, `skip`/`unskip`, `import`, `abbrechen` – alle mit Redirect auf `?p=dok_massenimport`.
