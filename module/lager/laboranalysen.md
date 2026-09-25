# module/lager/laboranalysen.php – Laboranalysen (Admin-Reiter)

Zentraler Ort, um Laborberichte / Analysenzertifikate (CoA) fertiger Produkte hochzuladen und mit einem
**Produkt** zu verknüpfen. Menü: Lager → Laboranalysen. Route `?p=laboranalysen`.

## Ablauf
1. **Hochladen & auswerten**: Datei (PDF/Bild) hochladen. Die KI schlägt Produkt + Analysendatum vor
   (`laboranalyse_ki_vorschlag`, nur beta). Die Datei wird sofort in `data/uploads` gespeichert.
2. **Vorschlag prüfen**: Produkt (Dropdown, KI-Vorschlag vorausgewählt), Analysendatum, Titel, Sichtbarkeit.
   Erst hier entsteht die Zeile in `dokument` (`objekt_typ='produkt'`, `typ='analyse'`).
3. **Liste**: alle Laboranalysen mit Datum/Produkt/Kunde/Bezug; Sichtbarkeit umschalten, löschen,
   Datei öffnen (`?p=dokument`). Live-Filter über das Suchfeld.

„im Kundenportal sichtbar" setzt `kunde_sichtbar=1` → erscheint im Kunden-Reiter „Labortest".

## Analyse EINER Bestellung (Charge)
Nicht hier, sondern direkt im Auftrag (`module/auftrag/detail.php`, Panel „Laboranalyse / Labortest").
Dort `objekt_typ='auftrag'`.

## Siehe auch
`core/laboranalyse.php` (Logik), `core/dokument_ui.php` (generische Ablage), `core/ki.php` (KI-Leser).
