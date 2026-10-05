# core/versand.php – Carrier-Schicht (Warenausgang)

Verbindet den Versand mit den Paketdiensten. Liest die Zugänge aus `lg_meta` (Einstellungen → Zugänge)
und den strukturierten Absender (Einstellungen → Formate), wählt den Carrier je Versandart und erzeugt
Label + Tracking.

- `versand_cfg()` – DHL- und Cargoboard-Zugänge aus `lg_meta`.
- `versand_absender_struktur()` – Absenderadresse (absender_name/strasse/… aus `lg_meta`).
- `versand_http($method,$url,$headers,$body=null)` – schlanker curl-Helfer (`['status','body','err','ct']`).
- **`versand_label_erstellen($versand_id)`** – Haupt-Einstieg. Prüft Empfänger + Absender + Zugang,
  **Paket → DHL** (`versand_dhl.php`), **Palette → Cargoboard** (`versand_cargoboard.php`). Bei Erfolg wird
  das Label (PDF) über `lg_versand_label_set()` gespeichert und die Sendungsnummer über
  `lg_versand_tracking_setzen()`. Fehlt ein Zugang/Absender → klare Meldung, kein API-Call.

- `versand_storno($versand_id)` – Sendung beim Carrier stornieren (aktuell DHL), löscht Label + Tracking.
- `versand_dhl_test()` – DHL-Zugang testen (`dhl_validate`, ohne echte Sendung) für „Verbindung testen".

Genutzt von [../module/versand/detail.md](../module/versand/detail.md) (Aktionen „label"/„storno") und
den Einstellungen (Zugänge → Verbindung testen). Keine Secrets im Code – alles in `lg_meta`.
