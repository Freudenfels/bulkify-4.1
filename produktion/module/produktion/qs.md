# produktion/module/produktion/qs.php
QS & Labor zu einem Produktionsauftrag (`?p=qs&id=…`), verlinkt von der Detailseite. Erfasste Werte in `pr_pa_daten`.

- **Rückstellmuster** (`aktion=qs_muster`): Anzahl, Charge (Vorschlag = PR-Charge), MHD (Vorschlag +18 M), gezogen am.
- **Laborprobe** (`aktion=qs_labor` / `qs_labor_versenden`): Anzahl, Labor; Button „An Labor versendet" setzt `laborprobe_versendet_am` + `laborstatus=versendet`. Status-Badge offen/bereitgestellt/versendet. Bericht-Upload & „abgeschlossen" kommen aus dem Dashboard (Labortests).
- **Erfasste Kontrollwerte**: Mischmenge/Kontrollgewicht aus dem Ablauf (read-only).
- **Qualitäts-Freigabe** (`aktion=qs_freigabe` / `qs_freigabe_zurueck`): setzt `qs_freigabe_am`/`qs_freigabe_von`.
- Die Seite ist zugleich das **druckbare QS-Freigabedokument** (Button „Drucken").

Status-Felder in `pr_pa_daten`: `rueckstellmuster_anzahl|charge|mhd|datum`, `laborprobe_anzahl|labor|versendet_am`, `laborstatus`, `qs_freigabe_am|von`.
Offen (Dashboard-Abstimmung): Laborstatus am Auftrag/Kundenportal anzeigen.
