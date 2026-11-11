# Werk-App: geführter Ablauf – Aktionen (`werk_ablauf_post.php`)

Seit 11.11.2026. `werk.php` bindet die Datei für alle Aktionen `wa_*` ein. Gebucht wird über `core/pa_ablauf.php`.

| Aktion | Was passiert |
|---|---|
| `wa_start` | Bulk-Scan prüfen (Rolle Bulk, Eimer des PA, ältere Charge zuerst), Fotos speichern, `pa_starten` |
| `wa_scan` | Rolle prüfen: falsches Material = Sperre, Bulk/Eimer einer anderen Unter-Charge = STOPP, Quarantäne = Sperre; sonst `pa_scan_merken`. Jede Sperre landet im Protokoll. |
| `wa_karton` | `pa_karton_anlegen` + Druck `pakarton` |
| `wa_teilmenge` | Pflichtfoto, `pa_teilmenge_buchen` mit den offenen Kartons |
| `wa_kapselprobe`, `wa_sack` | Kapselgewicht und Sackgewicht (Teil 9) |
| `wa_abschluss` | `pa_abschliessen` |
