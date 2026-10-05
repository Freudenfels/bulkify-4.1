# module/dienstleistung/angebot.php - DL-Angebot (Editor)

Route `?p=dl_angebot&id=<ID>`. Bearbeitet ein Dienstleistungs-Angebot (DA-).

- Kopf: Kunde, gültig bis, Notiz.
- Positionen: aus dem DL-Katalog hinzufügen (`dl_position_add`), Menge/Preis je Zeile editierbar (`dl_position_update`), löschen (`dl_position_del`). Preis/Einheit/MwSt kommen aus dem Katalog, je Angebot überschreibbar.
- Status: gesendet/abgelehnt.
- "Angebot bestätigen -> DL-Auftrag anlegen": setzt Status bestätigt und erzeugt den DL-Auftrag (`dl_auftrag_aus_angebot`, Nummernkreis DB-).

Alle Summen/Logik in `core/dienstleistung.php`.
