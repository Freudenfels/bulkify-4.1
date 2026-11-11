# lager/core/inventur.php – Inventur (Grundlage)

Seit 11.11.2026. Die Inventur ist ein eigener Modus der Lager-App, getrennt vom Tagesgeschäft:
- Dustin zählt **blind**, er sieht keine Soll-Mengen.
- Das System vergleicht mit dem Buchbestand **zum Zeitpunkt des Scans**.
- Nico entscheidet jede Korrektur.

Dashboard-Zugriffe laufen nur über `erp_inventur.php` (Naht).

## Tabellen (alle `lg_`)
| Tabelle | Inhalt |
|---|---|
| `lg_platz` (vorhanden) | Lagerplatz Bereich-Regal-Ebene-Fach, neu `aktiv`, `lager` (lager1/lager2). Barcode = `LP-00012` (`inv_platz_code`) |
| `lg_platz_inhalt` | was wo liegt: je Gebinde (`gebinde_id`) bzw. Charge ohne Gebinde (`gebinde_id=0`) ein Platz |
| `lg_tara` | Gebindetypen mit Leergewicht (Sack, Karton, Eimer …) |
| `lg_inventur` | der Lauf: Name, Stichtag, Lager 1/2 (+ Kunde), Warenarten, ganzes Lager ja/nein, Zähler, Nachzählen ab x %, Toleranzen, Grenzen für „kleine Differenzen“, Status zaehlen → auswerten → abgeschlossen, Protokoll-Dateien |
| `lg_inventur_bereich` | Zählbereich = Platz (`p:<id>`) oder Kiste (`k:<id>`), Status offen/laeuft/fertig. `sperre` ist eindeutig, solange die Inventur offen ist. Damit liegt ein Bereich nie in zwei offenen Inventuren. |
| `lg_inventur_zaehlung` | jeder Scan mit Ist (Einheit des Artikels), Eingabe (brutto, Tara, Kapselgewicht), **Soll und gebuchtem Ort zum Scan-Zeitpunkt (eingefroren)**, Durchgang 1/2 (Nachzählen), Klärfall-Feldern |
| `lg_inventur_diff` | Abweichungen mit Art, Soll/Ist/Differenz/Wert, Status und Nicos Ursache/Ergebnis |

Korrekturen werden als Bewegung `lg_bewegung` mit Typ `inventur` gespeichert. Neue Spalten dort: `inventur_id` und `ursache`.

Das Schema baut sich über `inv_schema()` auf, aufgerufen aus `lg_schema()`. Der Build-Marker enthält auch die Änderungszeit dieser Datei.

## Funktionen
- **Plätze:**
  - `inv_platz_raster()` legt ein Raster an.
  - `inv_platz_per_code()` erkennt einen gescannten Platz.
  - `inv_ort_von()` liefert den gebuchten Ort (zuerst Platz, sonst Kiste).
  - `inv_platz_setzen()` bucht eine Umlagerung und schreibt sie in `lg_charge_log`.
- **Lauf:**
  - `inv_anlegen()`: ganzes Lager, oder einzelne Plätze/Kisten mit Sperrprüfung.
  - `inv_bereich_anlegen()`: legt beim ganzen Lager den Bereich erst beim Scannen an.
  - `inv_darf_zaehlen()`, `inv_fortschritt()`.

Zählen, Abgleich, Buchen und Protokoll liegen in eigenen Dateien. Sie werden oben eingebunden, wenn sie vorhanden sind.
