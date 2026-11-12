# einstellungen.php — Buchhaltungs-Einstellungen

Route `einstellungen` (finance/admin). Aktuell: **GoBD scharfschalten**.

`app_meta gobd_scharf` (Default '0' = aus, Aufbaumodus). Helfer `gobd_scharf()` in core/erp.php.
- **Aus (Aufbaumodus):** Belege/Beträge/Positionen frei korrigierbar – für Import/Nachtragen alter Rechnungen.
  „Rechnungsbetrag aus Positionen übernehmen" geht bei jeder nicht-stornierten Rechnung.
- **Scharf:** festgeschriebene/freigegebene/bezahlte Belege unveränderbar; Betragsübernahme nur bei Entwürfen
  (offen, nicht freigegeben, unbezahlt). `gobd_scharf_am` merkt den Zeitpunkt.
Ausgewertet in module/beleg/detail.php (Aktion betraege_aus_positionen + Button-Anzeige).

**Zahlen-Lesart (13.11.2026, Fable-3 T2.9):** Betragsfelder lesen mit `betrag_lesen()`, Preise/Prozente/Kurse mit `zahl_lesen()`, Mengen mit `zahl_lesen(…, true)` – alle aus core/zahl_lesen.php (eine Regel mit dem Dashboard). „21.687,75“, „21687,75“ und „21687.75“ ergeben 21687,75.
