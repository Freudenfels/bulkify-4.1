# lager/core/inventur_zaehlen.php – Zählen (blind)

Seit 11.11.2026. **Keine Funktion gibt dem Zähler eine Soll-Menge zurück.** Beim Speichern eines Scans werden der Buchbestand (`soll`) und der gebuchte Ort (`soll_ort`) in `lg_inventur_zaehlung` eingefroren. Bewegungen nach dem Scan ändern die Zählung deshalb nicht.

- **`inv_scan()`** erkennt, was gescannt wurde:
  - Platz `LP-…` oder Kiste (Barcode bzw. Blinker an der Kiste): startet den Bereich.
  - Gebinde.
  - Charge: Hat sie Gebinde, wählt man das Gebinde (ohne Mengen).
  - Klärfall-Etikett dieser Inventur.
  - Sonst „unbekannt“ mit Hinweis auf „Gefunden ohne Etikett“.
- **`inv_objekt_info()`** zeigt: Artikel, Charge, MHD, Einheit (groß), Gebinde x von y, Inhalt „voll, original verschlossen“ und Eingabeart.
  - Der Inhalt „voll“ kommt aus der Gebinde-Menge beim Wareneingang, bei Chargen ohne Gebinde aus Menge ÷ Pakete.
- **Eingabeart (`inv_modus`):**
  - `wiegen` (kg/g): Brutto minus Tara aus `lg_tara` (änderbar). Das Formular sendet `tara_id`; solange das Tara-Feld nicht von Hand geändert ist (`tara_hand=0`), nimmt der Server den Wert aus `lg_tara` (`tara_aus_id()`), sonst die getippte Zahl (13.11.2026).
  - `kapsel` (Bulk-Kapseln in Stk): Kapseln = Netto ÷ Kapselgewicht. Das Kapselgewicht ist Füllgewicht der Rezeptur plus Leerkapsel und änderbar. Angezeigt werden kg und Stk.
  - `stk`: Menge oder „voll, original verschlossen“.
  - „Gebinde leer“ gibt Ist 0, die Ausbuchung wird dann vorgeschlagen.
  - **Gespeichert wird immer in der Einheit des Artikels** (`inv_ist_rechnen`).
- **`inv_zaehlen()`:** Wird dasselbe Objekt noch einmal gezählt, ersetzt das den alten Scan (`ersetzt=1`). Steht eine Nachzählung an, wird es Durchgang 2. Die erste Person darf dabei nicht selbst nachzählen, außer Admin.
- **`inv_klaerfall()`:** Ware ohne Barcode/Etikett. Erfasst werden Artikel, die blind abgetippte Charge, MHD, Menge und ein **Pflichtfoto**.
  - Der Klärfall bekommt sofort eine 8-stellige Inventarnummer.
  - Das Etikett wird gedruckt (Druckjob `inventur`); bei Bedarf lässt es sich auch in der App anzeigen.
  - Gebucht wird erst bei der Auswertung.
- **`inv_bereich_fertig()`:** „Platz fertig“. Danach entsteht der Abgleich für den Admin.
- **`inv_nachzaehl_aufgaben()`:** offene Nachzählungen für eine zweite Person, ohne Mengen.

**Gewichte (13.11.2026, Fable-3 T1.2):** Gewichts- und Wiegefelder lesen über `gewicht_lesen()` (core/gewicht.php): Punkt und Komma sind Dezimaltrenner („25.125“ kg = 25,125; „6.482“ g = 6,482; „25,000“ = 25), nur wenn beide vorkommen, gilt das letzte. Vorbelegungen ohne Tausenderpunkt (`gewicht_feld()`). Stückzahlen bleiben bei der Mengen-Lesart („12.000“ Stk = 12000). Hier: Brutto, Tara (nur bei Handeingabe) und mg je Kapsel.

**Inventur mit Blinkern (Sofortfix 13.11.2026):** `inv_auswahl($inv)` liefert die wählbaren Bereiche (Umfang = offene Bereiche; ganzes Lager = alle Kisten natürlich sortiert, dann aktive Plätze; ohne fertige, mit `blinker`). `inv_bereich_blinker($key, $an)` schaltet den Blinker einer Kiste blau/180 s bzw. aus (`leiste_finden`/`leiste_aus`); ohne Blinker Hinweis „kein Blinker“, Brücke weg: Hinweis „Blinker nicht erreichbar – zählen geht trotzdem“. `inv_naechster_bereich()` = nächste offene Kiste nach der fertigen. `inv_bereich_starten()`/`inv_bereich_fertig()` geben `key` zurück.
