# module/start.php – Lager-Startseite (Übersicht, Route `uebersicht`)

Landing-Seite des Lagers (Standard-Route nach Login). Gibt einen Überblick:

- **Schnellzugriffe**: Wareneingang · Finden · Warenausgang · Erwartete Lieferungen.
- **Kennzahlen-Kacheln** (`erp_lager_kennzahlen()`): Lager 1 (Chargen/Artikel), Lager 2 (Chargen/Kunden),
  Quarantäne, MHD bald (90 T), MHD abgelaufen, Erwartete Lieferungen (+ überfällig), Blinker-Batterie
  (`leiste_batterie_zahl`). Warn-/Err-Farbe, wenn > 0; jede Kachel verlinkt die passende Seite.
- **MHD im Blick** (`erp_mhd_kritisch`): abgelaufen zuerst, dann nächste 90 Tage.
- **Letzte Bewegungen** (`lg_bewegungen`).

Dashboard-Zahlen über die Naht `erp.php`; Lager-eigenes (Bewegungen/Batterie) direkt. Default-Route in
`public/lager/index.php`; Menüeintrag „Übersicht" zuoberst in `core/layout.php`.
