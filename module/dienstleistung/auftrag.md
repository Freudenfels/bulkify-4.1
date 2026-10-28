# module/dienstleistung/auftrag.php - DL-Auftrag (Detail)

Route `?p=dl_auftrag&id=<ID>`. Zeigt Kopf, Positionen (aus dem zugehörigen DL-Angebot) und Status (offen/in Arbeit/erledigt).

- "DL-Rechnung erstellen" (`dl_rechnung_aus_auftrag`): erzeugt eine Rechnung mit eigenem Nummernkreis DR-, übernimmt die Positionen, optional Zahlungsziel + Portal-Freigabe. Die Rechnung ist ein normaler `beleg` (kategorie='dienstleistung') und erscheint auch im zentralen Kassenbuch der Buchhaltung; Bezahlung/Mahnung laufen dort.

## Fortschritt (Service-Schritte) + Endergebnis
- **Fortschritt:** hat der Auftrag materialisierte Schritte (`dl_auftrag_track`), erscheinen sie als Buttons; ein Klick setzt den aktuellen Stand (`aktion=schritt` → `dl_auftrag_schritt_setzen`, alle davor = erledigt). Ohne Schritte (Service ohne Workflow) bleibt der alte offen/in Arbeit/erledigt-Umschalter.
- **Endergebnis:** erlaubt der Service `ergebnis_upload` (oder es gibt schon Ergebnisse), gibt es ein Upload-Feld (`aktion=ergebnis_upload` → `dl_ergebnis_upload`). Bei `upload_schliesst_ab` wird der Auftrag abgeschlossen und der Kunde benachrichtigt. Download über `?p=dokument&id=`.
- Kopf zeigt den Service-Namen; der Kunde sieht den Fortschritt + das Endergebnis im Portal (kein Etikett/Produkt-Phasen für DL-Aufträge).

## Schritte-Heilung (2026-10-27)
- Beim Öffnen wird der Auftrag „geheilt" (`dl_auftrag_heilen`): fehlende Service-Verknüpfung wird aus der Angebotsposition nachgetragen und Schritte aus dem Katalog angelegt (idempotent, nicht bei storniert).
- Neuer Button im Fortschritt-Panel **„Schritte (neu) aus Service übernehmen"** (`aktion=schritte_sync` → `dl_auftrag_schritte_resync`): zieht nachträglich im Katalog geänderte Abläufe auf den Auftrag; Haken-Stand bleibt je Schrittname erhalten. Flash `syncok=N` / `syncleer=1`.

## F3/F4 – Arbeitsschritte editierbar + interne Kosten (2026-10-28)
Das Panel „Arbeitsschritte" ersetzt die reine Fortschritts-Anzeige: je Schritt **abhaken** (toggle, `schritt_toggle` → `dl_schritt_toggle`), **umbenennen** + **interne Kosten/Stk** speichern (`schritt_edit` → `dl_schritt_edit`), **entfernen** (`schritt_remove` → `dl_schritt_remove`), und unten **Schritt hinzufügen** (`schritt_add` → `dl_schritt_add`) – auch nachträglich bei laufendem Auftrag (Verlaufseintrag wer/wann). Keine erneute Kundenbestätigung nötig (individueller Festpreis). „Schritte neu aus Service übernehmen" bleibt. **F4:** `dl_auftrag_schritt.interne_kosten_stk` (+ `dienstleistung_schritt.interne_kosten_stk`) – nur Ablage, noch keine Auswertung/Automatik; getrennt von der Kundenberechnung. Auch ohne Service-Katalog lassen sich Schritte je Auftrag von Hand aufbauen (Konfektionierung).

## F1/F2 – Dienstleistungsanfrage & Konfektionierung
Die Dienstleistungsanfrage (Portal, `portal_anfrage` typ='dienstleistung' + `dienstleistung_typ`) hat die Unterarten u. a. **Laboranalyse, Konfektionierung, Rezepturentwicklung** (Namen „Kundenbeistellung"/„Fremdproduktion" bewusst NICHT verwendet). Bei **Konfektionierung** erfasst der Kunde im Portal, **was er anliefert** (Rohstoffe/fertige Ware + Menge) – wird der Notiz als „Anlieferung des Kunden: …" vorangestellt; die Arbeitsschritte legt bulkify fest (editierbar, s. o.). Konfektionierung läuft über den DL-Auftrag; ein **Einkauf wird dabei nicht ausgelöst** (DL-Aufträge tragen keinen Rohstoffbedarf). Bestehende DL-Aufträge behalten ihre DA-/DB-/DR-Nummern (nichts gelöscht).
