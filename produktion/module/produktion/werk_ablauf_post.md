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

**Zahlen (Block D, 11.11.2026):** Mengen-, Gewichts- und Wertfelder lesen deutsch (`erp_menge_parse` bzw. `pr_menge_parse`): 135.000 = 135000, 12,5 = 12,5, 0.045 = 0,045.

**Sprachen (Block F, 11.11.2026):** Alle Texte des geführten Ablaufs laufen über `werk_texte()` (`wa_*`), Meldungen aus dem Kern über `werk_msg()`. Der Umschalter DE/EN/УКР wirkt auf Tagesplan, Start, Material-Scan, Kapselprobe, Kartons, Teilmenge, Abschluss und alle Meldungen.

## Ältere Charge zuerst (13.11.2026)
Die Ausnahme „jüngere Charge trotzdem“ prüft der Server: nur Admin, nur mit Grund (`aelter_grund`). Sie steht als Ereignis „abweichung“ im Chargenprotokoll. Bulk ohne MHD wird nach Wareneingang verglichen (`erp_wa_aeltere_bulk()`).

**Gewichte (13.11.2026, Fable-3 T1.2):** Gewichts- und Wiegefelder lesen über `gewicht_lesen()` (core/gewicht.php): Punkt und Komma sind Dezimaltrenner („25.125“ kg = 25,125; „6.482“ g = 6,482; „25,000“ = 25), nur wenn beide vorkommen, gilt das letzte. Vorbelegungen ohne Tausenderpunkt (`gewicht_feld()`). Stückzahlen bleiben bei der Mengen-Lesart („12.000“ Stk = 12000). Hier: Kapselprobe (g) und Sack brutto (kg); die Tara kommt als ID (`tara_id`) und wird aus `lg_tara` geholt.

**Ausnahme „jüngere Charge trotzdem“ (13.11.2026, Fable-3 T1.5):** Die Admin-Prüfung nimmt den PIN-Nutzer der Werk-App (`$istAdminW` aus werk.php, Sitzung `werk_uid`), nicht die Anmeldung der Produktions-App. Admin per PIN + Grund = Start geht, Ereignis „Admin-Ausnahme“ im Chargenprotokoll; Nicht-Admin oder ohne Grund = abgelehnt.

**Voll produziert / Linie leer (13.11.2026, Werk-App Teil 3):** `wa_start` sendet `linie_leer` (Haken) und das Stempelfoto an `pa_starten()`.
