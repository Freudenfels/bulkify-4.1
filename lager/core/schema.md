# lager/core/schema.php – eigene Tabellen

Legt nur Tabellen mit dem Präfix `lg_` an und fasst Dashboard-Tabellen nie an. Gebaut wird nur, wenn sich diese Datei geändert hat (Marker `lg_meta.schema_build`).

| Tabelle | Inhalt |
|---|---|
| `lg_leiste` | Chaos-Modell (großes Lager): ein Blinker, an eine **Charge** gebunden (`charge_id`). `charge_id` NULL = frei. Code 6-stellig. Dazu `ausloesungen` + `verbrauch_sek` (Nutzung für die Akku-Schätzung) und `batterie_seit`. |
| `lg_platz` | Fulfillment (feste Plätze): Bereich, Regal, Ebene, Fach, Bezeichnung, Blinker (6-stelliger Code), Sender, Notiz. Jede Blinker gibt es nur einmal. |
| `lg_sender` | Sender: Name, Weg (`bruecke`, `direkt`, `cloud`), IP im Lager-Netz, Seriennummer, aktiv. |
| `lg_befehl` | Jeder Leuchtbefehl: Warteschlange für die Brücke und zugleich Protokoll. Status `offen`, `abgeholt`, `ok`, `fehler` oder `verfallen`. |
| `lg_meta` | Einstellungen: Schlüssel der Brücke (`bruecke_token`), letztes Lebenszeichen der Brücke (`bruecke_zuletzt`). |
| `lg_charge_info` | Zusatzinfos je Charge, die es im Dashboard nicht gibt: `aufteilen` (in Kartons splitten), `tracking` (Sendungs-/Paketnummer), `pakete`. |
| `lg_charge_log` | Änderungsprotokoll je Charge: `feld`, `alt`, `neu`, `benutzer_id`/`benutzer_name`, `angelegt`. Gefüllt von `lg_charge_log_add()` (überspringt unveränderte Werte), gelesen von `lg_charge_log_liste($id)`. Zeigt auf der Charge-Seite „wann hat wer was geändert". |
