# core/pib_pruefung.php – PIB prüfen, bevor der Kunde es bekommt (Paket 13.11.2026, Teil 3)

Eine Stelle für alle Wege. Spalten am Auftrag: `pib_pruefung` (NULL = Altauftrag ohne Prüfpflicht, `offen`, `frei`), `pib_freigegeben_am/_von`, `pib_stand` (Fingerabdruck), `pib_mail_am`.

- **Neuer Auftrag:** `auftrag_name_snapshot()` (alle Anlage-Wege) ruft `pib_pruefung_anlegen()`. Es setzt `offen` und legt die Aufgabe „PIB prüfen – AB-xxxx, Produkt“ an (dringend, Ziel `?p=rezeptur_detail&id=…#pibpruefen`). Nur Produkt-Aufträge. Altaufträge bekommen nichts, ihr Download bleibt offen.
- **Rezeptur-Seite:** `pib_wartend_fuer_rezeptur()` liefert die wartenden Aufträge für den Kasten „PIB prüfen“. Knopf „PIB geprüft“ → `pib_freigeben()`: Es gibt frei, erledigt die Aufgabe, protokolliert und mailt.
- **Unvollständig:** `pib_unvollstaendig($produkt_id)` prüft Rezeptur, Zutaten, Einheiten/Füllmenge je Packung, Einnahme pro Tag und Wirkstoffgehalte. Fehlt etwas, ist der Knopf gesperrt, am Kasten steht „PIB unvollständig: …“, und es geht keine Mail raus.
- **Portal:** `pib_kunde_darf($aid)` sperrt den Download serverseitig (403). `pib_gesperrt_html()` zeigt einen ausgegrauten Knopf mit Schloss und „PIB wird geprüft“.
- **Mail nach der Freigabe:** `pib_mail_kunde()` schickt eine Mail: Auftrag bestätigt, PIB im Anhang, bei fehlendem Etikett die Bitte mit Upload-Link. DE für DE/AT/CH/LI, sonst EN (Land am Kunden; ein Sprachfeld gibt es nicht).
- **Rezeptur geändert:** `pib_fingerabdruck()` erfasst Zutaten und Mengen, Form, Kapselgröße, fixierte Nährwerte und Produktangaben. Wirkstoffgehalte am Rohstoff gehören bewusst nicht dazu (CoA/Spec innerhalb der Spec lösen keine Neuprüfung aus).
  - Weicht der Fingerabdruck ab, nimmt `pib_status()` bzw. `pib_rezeptur_geaendert()` (nach dem Speichern der Rezeptur) die Freigabe zurück: neue Aufgabe, Download gesperrt.
- **Datum auf dem PIB:** `pib_stand_fuer_auftrag()` liefert:
  - freigegeben → „Freigegeben am“
  - Altauftrag → „Stand“ = Auftragsbestätigung
  - sonst „Entwurf – nicht freigegeben“

  Dazu kommt immer „Heruntergeladen am“.
