# agb.php – Allgemeine Geschäftsbedingungen (versioniert)

## Wozu
Die AGB, die der Kunde beim **verbindlichen Annehmen** einer Rezeptur oder eines Angebots
bestätigen muss. Sie sind **versioniert**: Eine Fassung ist aktiv, ältere bleiben stehen.
Beim Annehmen wird die dann geltende Versionsbezeichnung am Vorgang gespeichert
(`rezeptur.agb_version`, `angebot.agb_version`) – damit ist später belegbar, welcher Text galt.

> Der mitgelieferte Text ist ein **Entwurf** und muss anwaltlich geprüft werden.

## Tabelle `agb`
| Spalte | Bedeutung |
|---|---|
| `version` | Bezeichnung der Fassung, z. B. `1.0 (Entwurf)` oder ein Datum |
| `inhalt` | Der Text als **HTML** (vom Team gepflegt, wird ungefiltert ausgegeben) |
| `aktiv` | `1` = die aktuell gültige Fassung; es gibt immer höchstens eine |
| `angelegt` | Zeitpunkt (UTC) |

## Funktionen
- `agb_aktuell()` – die gültige Fassung als Zeile, oder `null`.
- `agb_fassung($id)` – eine bestimmte (ältere) Fassung, für den Blick zurück.
- `agb_version()` – nur die Versionsbezeichnung der gültigen Fassung (leer, wenn keine da ist).
- `agb_speichern($version, $inhalt)` – legt eine **neue** Fassung an und setzt die bisherige
  auf inaktiv. Ohne Versionsbezeichnung wird das heutige Datum genommen. Nichts wird überschrieben:
  alte Fassungen bleiben als Beleg erhalten.
- `agb_seed_wenn_leer()` – legt einmalig den Entwurf an, solange keine Fassung existiert.
  Wird beim Aufruf des Kundenportals und der Einstellungen ausgeführt.
- `agb_entwurf_text()` – der Ausgangstext; Firmenname und Sitz kommen aus den Einstellungen
  (`beleg_firma()`), eingesetzt über die Platzhalter `{FIRMA}` und `{SITZ}`.

## Wo es auftaucht
- **Kundenportal:** Seite `?p=portal&token=…&v=agb` (Link unten in der Seitenleiste, kein
  Menüpunkt). `&fassung=<id>` zeigt eine ältere Fassung.
- **Bestätigungs-Dialog:** zweiter Pflicht-Haken „Ich akzeptiere die AGB (Fassung X)" mit Link,
  der die AGB in einem neuen Tab öffnet. Serverseitig geprüft (`$freigabeName()` in
  `module/portal/kunde.php`): ohne Haken passiert nichts – solange überhaupt eine Fassung
  hinterlegt ist.
- **Einstellungen → AGB:** Text bearbeiten und als neue Fassung speichern, darunter die Liste
  aller Fassungen.

## Verwandt
- `module/portal/kunde.php` – Portal, Dialog und Annahme.
- `core/schema.php` – Tabelle `agb` und die Spalten `agb_version`.
