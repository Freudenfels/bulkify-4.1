# core/etikett_mass.php – Etikettmaß kommt nur von der Verpackung

Regel Nico 13.11.2026 (Fable-6 Teil 1, Anweisung `sofortfix-etikettgroesse`): Die Etikettgröße ist fest durch die Verpackung (Glas, Dose, Beutel). Eine Kundendatei ändert sie nie. Die Datei wird nur gegen dieses Maß geprüft (`etp_soll()` in `core/etikett_pruefung.php`).

## Funktionen
- `etikett_mass_von_verpackung($behaelter_id)` liefert das Soll-Maß eines Behälters. Quelle ist das generische Etikett des Behälters (`etikett_id_fuer_behaelter`). Hat es kein Maß, gilt das Endformat am Behälter (`etikett_final`, dazu `etikett_druck`).
- `etikett_behaelter_fuer_item($item_id)` bestimmt den Behälter, nach dem sich ein Kundenetikett richtet. Das ist der Behälter des jüngsten Auftrags an diesem Etikett (neutrale Basis bei bedruckten Behältern), sonst der Produkt-Behälter.
- `etikett_mass_auf_verpackung($item_id, $grund)` setzt das Maß eines Etikett-Artikels auf die Verpackung zurück. Die Änderung steht im Protokoll (`aenderung_log`, route `etikett_mass_verpackung`) und im Verlauf. Benutzt werden es:
  - vom Knopf in der Datenprüfung `etikett_mass_abweichung`
  - bei der Freigabe, wenn der Etikett-Artikel noch gar kein Maß hat
- `etikett_mass_zurueck_1311($ausfuehren)` setzt Maße zurück, die früher eine Datei geändert hatte.
  - Quelle: `aenderung_log` mit route `etikett_pdf_*`. Je Feld gilt der älteste Eintrag als „vorher“, der jüngste als Wert der Datei.
  - Zurückgeschrieben wird nur, wenn das Feld heute noch den Wert der Datei hat und Breite/Höhe vorher nicht leer waren. Sonst steht im Bericht „bitte Nico prüfen“ mit Grund.
  - Jede Rücknahme kommt ins Protokoll (route `etikett_mass_zurueck_1311`) und in den Verlauf („Maß zurück auf Verpackung, Regel 13.11.“).
  - Freigaben bleiben unberührt.
- `dpf_etmass_einmal()` läuft einmal auf live aus `init_schema()` (app_meta `dpf_etmass_erledigt`). Den Bericht speichert es in app_meta `dpf_etmass_ergebnis`; zu sehen ist er in der Datenprüfung „Etikett: Maß aus der Datei zurück auf die Verpackung“.

## Was sich dazu in anderen Dateien geändert hat
- `core/schema.php`:
  - `etikett_mass_aus_pdf_setzen()` ist entfernt.
  - `kundenetikett_nach_freigabe()` ändert das Maß nicht mehr.
  - `kundenetikett_version_anlegen(…, $vorgaenger_id, $verpackung_id)` übernimmt das Maß vom Vorgänger, sonst von der Verpackung des Auftrags.
  - `kundenetikett_vorlage($produkt_id, $verpackung_id)` nimmt den Behälter des Auftrags, wenn er übergeben wird.
- `core/etikett_pruefung.php`: „trotzdem verwenden“ speichert nur den Vermerk und legt eine dringende Aufgabe ans Team an. Der Artikel bleibt unverändert.
- `module/lager/verpackung_detail.php`: Etikett-Maße ändert nur ein Admin, und nur mit Grund (Protokoll route `etikett_mass_admin`). Alle anderen sehen einen Hinweis; ihre Änderung wird nicht übernommen.
