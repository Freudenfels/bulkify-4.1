# gutschrift_neu.php – Storno-Rechnung / Gutschrift manuell erstellen

Route `?p=gutschrift_neu` (Rolle finance). Formular wie beim Angebot: **Kunde** wählen, **Datum**, **Grund/Bezug**
und **Positionen** (Artikel-Nr., Bezeichnung, mehrzeilige Beschreibung, Menge, Einheit, Einzelpreis, USt %).

Preise werden als normale (positive) Beträge eingegeben; beim Speichern werden sie als **Gutschrift = negativ**
gespeichert (`gutschrift_erstellen()` in core/schema.php). Danach Weiterleitung auf die Beleg-Ansicht `?p=rechnung&id=…`.

Gedacht für **alte Bestellungen**, aus denen etwas herausstorniert werden soll (frei, ohne Bezug auf eine bestehende Rechnung).

## Positionen aus Text einfügen (Auto-Übernahme)
Aufklappbereich „Positionen aus Text einfügen": Text aus altem Angebot/Rechnung einfügen → „Positionen übernehmen".
Der Parser (JS) erkennt je Position eine Kopfzeile `Pos Artikel-Nr Bezeichnung Menge Einheit Einzelpreis Gesamt`
(dt. Zahlen 1.000,00 / 7,54); Zeilen darunter werden zur Beschreibung. Artikel-Nr = Kürzel + Nummer (z. B. „VCB 1.32.8").
Füllt die Positionszeilen zum Prüfen/Anpassen; leere Vorlagezeilen werden ersetzt.
