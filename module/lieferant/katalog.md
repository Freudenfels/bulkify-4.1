# lieferant/katalog.php – „Mein Katalog" im Lieferantenportal

**Route:** `?p=lieferant_katalog` (nur für angemeldete Lieferanten).

**Wozu:** Der Lieferant zeigt, was er anbietet – ohne dass wir ihn erst anfragen müssen.

**Zwei Wege:**
- **Liste hochladen** (`aktion=liste_hoch`): PDF, Bild oder CSV, auch als Scan. Die Datei landet zuerst in der Dateiablage (damit sie nachvollziehbar bleibt), dann liest die KI sie aus (`katalog_einlesen()`) und legt je Artikel eine Zeile an.
- **Von Hand eintragen** (`aktion=zeile_neu`): Bezeichnung, Typ, Form, Spezifikation, Herkunft, Preis, Währung, Einheit, ab Menge, Notiz.

Offene Zeilen kann der Lieferant selbst **bearbeiten** (`aktion=zeile_save`) oder löschen (`aktion=zeile_weg`); übernommene nicht mehr. „Bearbeiten" füllt das untere Formular per JS mit den Werten der Zeile (setzt Titel/Knopf auf Bearbeiten, `zeile_id`), „Abbrechen" schaltet zurück auf Neuanlage. Der Status je Zeile zeigt ihm, ob wir sie schon geprüft haben.

**Wichtig:** Aus einer Zeile wird **kein** Artikel bei uns. Das entscheidet das Team im Lieferantenkonto, Reiter Katalog. Zahlen werden in der Schreibweise des Lieferanten gelesen (`zahl_lesen()` mit seiner Sprache).

**Popups (`.bx-dialog`):** Katalog hochladen, CoA/Spec hochladen und „Manuell" laufen als `<dialog>`. Die Klasse setzt `background:var(--panel)`, `color:var(--text)` und `border:var(--line)`, damit das Popup dem Theme folgt – vorher war es im **Dark-Mode** weiß (UA-Default), mit kaum lesbaren hellgrauen Labels über dunklen Feldern.

## Abschnitt „Von bulkify bei Ihnen geführt" (Stand 2026-10-08)
Zweiter Abschnitt auf derselben Seite (Gegenrichtung zum Selbst-Einreichen oben): zeigt `lieferant_gefuehrte_artikel($lid)` – die Rohstoffe, die bulkify bei diesem Lieferanten führt (Hauptlieferant ODER eigener Staffelpreis). Spalten: Artikel (+ Hauptlieferant-Badge, Artikelnr., lat. Name, CAS), Produkttyp, **Unsere Anforderung** (Wirkstoffe mit Gehalt + Kennwerte), **Ihr Preis** (nur seine eigenen Staffeln). Je Zeile Button „Preis aktualisieren" → Popup `#dlgPreis` → POST `aktion=preis_vorschlag` → `katalog_preis_vorschlag()`. Der Vorschlag landet als Prüf-Zeile beim Team (Katalog-Freigaben), nicht direkt live. Alles über `lp_t()` DE/EN/ZH übersetzt.
