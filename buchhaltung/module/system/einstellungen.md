# einstellungen.php — Buchhaltungs-Einstellungen

Route `einstellungen` (finance/admin). Aktuell: **GoBD scharfschalten**.

`app_meta gobd_scharf` (Default '0' = aus, Aufbaumodus). Helfer `gobd_scharf()` in core/erp.php.
- **Aus (Aufbaumodus):** Belege/Beträge/Positionen frei korrigierbar – für Import/Nachtragen alter Rechnungen.
  „Rechnungsbetrag aus Positionen übernehmen" geht bei jeder nicht-stornierten Rechnung.
- **Scharf:** festgeschriebene/freigegebene/bezahlte Belege unveränderbar; Betragsübernahme nur bei Entwürfen
  (offen, nicht freigegeben, unbezahlt). `gobd_scharf_am` merkt den Zeitpunkt.
Ausgewertet in module/beleg/detail.php (Aktion betraege_aus_positionen + Button-Anzeige).
