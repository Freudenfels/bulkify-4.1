# module/dienstleistung/auftrag.php - DL-Auftrag (Detail)

Route `?p=dl_auftrag&id=<ID>`. Zeigt Kopf, Positionen (aus dem zugehörigen DL-Angebot) und Status (offen/in Arbeit/erledigt).

- "DL-Rechnung erstellen" (`dl_rechnung_aus_auftrag`): erzeugt eine Rechnung mit eigenem Nummernkreis DR-, übernimmt die Positionen, optional Zahlungsziel + Portal-Freigabe. Die Rechnung ist ein normaler `beleg` (kategorie='dienstleistung') und erscheint auch im zentralen Kassenbuch der Buchhaltung; Bezahlung/Mahnung laufen dort.

## Leistungsbeschreibung / KI-Zusammenfassung (2026-10-28)
Im Rechnungs-Panel (`#rechnung`) gibt es ein Feld **Leistungsbeschreibung**, das als Rechnungstext (`beleg.text`) in die DR-Rechnung fließt. Statt alles von Hand zusammenzusuchen, fasst **„Mit KI zusammenfassen"** (`aktion=ki_zusammenfassung` → `dl_auftrag_ki_zusammenfassung`) den ganzen Auftrag zusammen: Leistung/Service, die abgerechneten Positionen (Service-Gruppe), erledigte + offene Arbeitsschritte, hochgeladene Endergebnisse und den Verlauf. Der Vorschlag wird in `auftrag.dl_leistungstext` gespeichert und ist frei editierbar. Der KI-Button erscheint nur bei eingerichteter KI (`ki_bereit()`); ohne KI trägt man den Text selbst ein.
- Ein Formular, zwei Buttons: **„Nur Text speichern"** (`aktion=leistungstext_speichern`) und **„DL-Rechnung erstellen"** (`aktion=rechnung`). Letzterer speichert den (ggf. editierten) Text mit und rechnet in einem Schritt ab – kein getrenntes Speichern nötig. Flash `kiok=1` / `kierr=<text>`.

## Fortschritt (Service-Schritte) + Endergebnis
- **Fortschritt:** hat der Auftrag materialisierte Schritte (`dl_auftrag_track`), erscheinen sie als Buttons; ein Klick setzt den aktuellen Stand (`aktion=schritt` → `dl_auftrag_schritt_setzen`, alle davor = erledigt). Ohne Schritte (Service ohne Workflow) bleibt der alte offen/in Arbeit/erledigt-Umschalter.
- **Endergebnis:** erlaubt der Service `ergebnis_upload` (oder es gibt schon Ergebnisse), gibt es ein Upload-Feld (`aktion=ergebnis_upload` → `dl_ergebnis_upload`). Bei `upload_schliesst_ab` wird der Auftrag abgeschlossen und der Kunde benachrichtigt. Download über `?p=dokument&id=`.
- Kopf zeigt den Service-Namen; der Kunde sieht den Fortschritt + das Endergebnis im Portal (kein Etikett/Produkt-Phasen für DL-Aufträge).

## Schritte-Heilung (2026-10-27)
- Beim Öffnen wird der Auftrag „geheilt" (`dl_auftrag_heilen`): fehlende Service-Verknüpfung wird aus der Angebotsposition nachgetragen und Schritte aus dem Katalog angelegt (idempotent, nicht bei storniert).
- Neuer Button im Fortschritt-Panel **„Schritte (neu) aus Service übernehmen"** (`aktion=schritte_sync` → `dl_auftrag_schritte_resync`): zieht nachträglich im Katalog geänderte Abläufe auf den Auftrag; Haken-Stand bleibt je Schrittname erhalten. Flash `syncok=N` / `syncleer=1`.

## F3/F4 – Arbeitsschritte editierbar + interne Kosten (2026-10-28)
Das Panel „Arbeitsschritte" ersetzt die reine Fortschritts-Anzeige: je Schritt **abhaken** (toggle, `schritt_toggle` → `dl_schritt_toggle`), **umbenennen** + **interne Kosten/Stk** speichern (`schritt_edit` → `dl_schritt_edit`), **entfernen** (`schritt_remove` → `dl_schritt_remove`), und unten **Schritt hinzufügen** (`schritt_add` → `dl_schritt_add`) – auch nachträglich bei laufendem Auftrag (Verlaufseintrag wer/wann). Keine erneute Kundenbestätigung nötig (individueller Festpreis). „Schritte neu aus Service übernehmen" bleibt. **F4:** `dl_auftrag_schritt.interne_kosten_stk` (+ `dienstleistung_schritt.interne_kosten_stk`) – nur Ablage, noch keine Auswertung/Automatik; getrennt von der Kundenberechnung. Auch ohne Service-Katalog lassen sich Schritte je Auftrag von Hand aufbauen (Konfektionierung).

## Standardschritte ohne Service (2026-10-29)
Hat ein DL-Auftrag **keinen verknüpften Service** (z. B. reine Abfüllung mit Beistellung, deren Angebotsposition fälschlich eine Rezeptur/Material trägt), läuft „aus Service übernehmen" ins Leere. Darum gibt es im Panel jetzt die **vorgegebenen Standard-Schrittnamen aus `dl_schritte_katalog()` als Checkbox-Liste** (sinnvolle Vorauswahl Bestätigung/Abfüllen/Etikettieren/Versand/Abschluss) → Button **„Ausgewählte Schritte anlegen"** (`aktion=schritte_standard` → `dl_auftrag_schritte_standard_anlegen`, legt nur an wenn leer, `dl_auftrag_status_ableiten`). Schon **ein** Schritt macht den Auftrag im Kundenportal sichtbar (Portal-Filter: Service ODER Schritt). Flash `ok`/`stdvorhanden`. Die freien „+ Schritt"-Felder haben jetzt ein `<datalist>` mit denselben Standardnamen (Konsistenz). Hintergrund-Regel: **eine Dienstleistung trägt nie eine Rezeptur** – die eigentliche Lösung ist, Abfüllung als Service (DL) statt Rezeptur anzubieten; dieser Button ist der schnelle Weg, einen Altfall sichtbar zu machen.

## F1/F2 – Dienstleistungsanfrage & Konfektionierung
Die Dienstleistungsanfrage (Portal, `portal_anfrage` typ='dienstleistung' + `dienstleistung_typ`) hat die Unterarten u. a. **Laboranalyse, Konfektionierung, Rezepturentwicklung** (Namen „Kundenbeistellung"/„Fremdproduktion" bewusst NICHT verwendet). Bei **Konfektionierung** erfasst der Kunde im Portal, **was er anliefert** (Rohstoffe/fertige Ware + Menge) – wird der Notiz als „Anlieferung des Kunden: …" vorangestellt; die Arbeitsschritte legt bulkify fest (editierbar, s. o.). Konfektionierung läuft über den DL-Auftrag; ein **Einkauf wird dabei nicht ausgelöst** (DL-Aufträge tragen keinen Rohstoffbedarf). Bestehende DL-Aufträge behalten ihre DA-/DB-/DR-Nummern (nichts gelöscht).

## Bezug + Laborbericht (07.11.2026)
- Neues Panel **Bezug** (`#bezug`, `aktion=bezug` → `dl_auftrag_bezug_setzen`): Produkt, Bestellung (AB-) und Charge, für die die Leistung ist. Gespeichert in `auftrag.produkt_id`, `auftrag.dl_bezug_auftrag_id`, `auftrag.dl_bezug_charge`.
- Vorbelegung über `dl_auftrag_bezug()`: von Hand zugeordnet, sonst Produkt der Bestellung, sonst das Produkt aus der DL-Anfrage (Hinweis „aus der Anfrage übernommen"). Eine einzige Fertigware-Charge der Bestellung wird als Charge vorgeschlagen.
- Fehlt bei einer Laboranalyse das Produkt, steht ein roter Hinweis im Panel.
- Der Upload ist jetzt **immer** da (außer bei Services „ohne Fortschritt"), nicht nur, wenn im Katalog `ergebnis_upload` gesetzt ist.
- Bei Laboranalysen (`dl_ist_labortest`) heißt er **Laborbericht**. Mit dem Haken „auch als Laboranalyse beim Produkt ablegen" entsteht zusätzlich eine eigene Dateikopie als `dokument typ='analyse'` an der Bestellung (sonst am Produkt), Befund „unklar", kundensichtbar. Damit erscheint der Bericht unter Laboranalysen und im Portal-Reiter Labortest.
- Ohne zugeordnetes Produkt ist der Haken gesperrt.
- Kopfzeile zeigt das Produkt mit an.
- Kopfbereich zeigt den Kunden immer mit vollem Firmennamen (nicht das interne Kürzel – das gilt nur in Tabellen), 08.11.2026

**Zahlen-Lesart (13.11.2026, Fable-3 T2.9):** Betragsfelder lesen mit `betrag_lesen()`, Preise/Prozente/Kurse mit `zahl_lesen()`, Mengen mit `zahl_lesen(…, true)` – alle aus core/zahl_lesen.php (eine Regel mit dem Dashboard). „21.687,75“, „21687,75“ und „21687.75“ ergeben 21687,75.
