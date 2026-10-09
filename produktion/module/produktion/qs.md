# produktion/module/produktion/qs.php
QS & Labor zu einem Produktionsauftrag (`?p=qs&id=…`), verlinkt von der Detailseite. Erfasste Werte in `pr_pa_daten`.

- **Proben & Rückstellmuster – 3-stufig (Spec 8)** (`aktion=probe_add`/`probe_del`): echte Einzelproben in der Dashboard-Tabelle `prod_probe` über die Naht (`erp_proben_fuer_pa`/`erp_probe_anlegen`/`erp_probe_loeschen`). Drei Ebenen: **Rohstoff** (je eingesetztem Batch – Auswahl aus den Rezeptur-Zutaten `erp_pa_zutaten` + Hersteller-Batch-Nr.), **Gebinde** (je Gebinde/Sub-Charge, Bezeichnung) und **Endprodukt-Rückstellmuster** mit Soll-Regel `erp_rueckstell_soll` = **max(5, Anzahl Gebinde)** (Anzahl Gebinde = erfasste Gebinde-Proben); Soll/Ist wird angezeigt. Jede Probe listbar + löschbar.
- (Alt) **Rückstellmuster** (`aktion=qs_muster`, `pr_pa_daten`): durch die 3-stufige Erfassung ersetzt; Aktion bleibt bestehen, wird aber nicht mehr angezeigt.
- **Laborprobe** (`aktion=qs_labor` / `qs_labor_versenden`): Anzahl, Labor; Button „An Labor versendet" setzt `laborprobe_versendet_am` + `laborstatus=versendet`. Status-Badge offen/bereitgestellt/versendet. Bericht-Upload & „abgeschlossen" kommen aus dem Dashboard (Labortests).
- **Erfasste Kontrollwerte**: Mischmenge/Kontrollgewicht aus dem Ablauf (read-only).
- **Qualitäts-Freigabe** (`aktion=qs_freigabe` / `qs_freigabe_zurueck`): setzt `qs_freigabe_am`/`qs_freigabe_von`.
- Die Seite ist zugleich das **druckbare QS-Freigabedokument** (Button „Drucken").

Status-Felder in `pr_pa_daten`: `rueckstellmuster_anzahl|charge|mhd|datum`, `laborprobe_anzahl|labor|versendet_am`, `laborstatus`, `qs_freigabe_am|von`.
Offen (Dashboard-Abstimmung): Laborstatus am Auftrag/Kundenportal anzeigen.
