# Werk-App: geführter Ablauf – Ansicht (`werk_ablauf.php`)

Seit 11.11.2026. Wird in der Schritt-Ansicht von `werk.php` eingebunden, wenn `erp_wa_aktiv()` gilt. Die alte Stations-Ansicht samt Teilmenge-Box ist dann ausgeblendet.

1. **Start, Chargenwechsel oder Wiederanlauf**
   - Bulk scannen. Danach steht groß „**Charge 2785.B · MHD 14.10.2028**“ mit dem Hinweis „Stempel genau so einstellen“.
   - Linie wählen, dazu Pflichtfoto „Linie leer“ und Pflichtfoto „Stempelbild“.
   - Abweichende Charge nur mit Grund (geht als Aufgabe ans Büro).
   - **Wiederanlauf:** Ist der letzte Start einer laufenden Unter-Charge von einem früheren Tag, kommt der Start-Dialog erneut, mit gleicher Charge und neuen Fotos.
2. **Laufende Charge:** groß Charge und MHD, dazu produziert/offen.
3. **Material scannen:**
   - Falsches Material wird gesperrt.
   - Ein Bulk oder Eimer einer anderen Charge ergibt **STOPP**, bis die aktuelle fertig ist.
   - Ware in Quarantäne wird gesperrt.
4. **Kapselgewicht und Sack:** Stichproben und Sackgewicht mit Tara (Teil 9).
5. **Kartons:** je vollem Karton „Karton drucken“, Menge vorbelegt, Nummer fortlaufend, Druck über die Lager-Brücke (`pakarton`).
6. **Teilmenge fertig:**
   - Die Menge ist aus den noch nicht gebuchten Kartons vorbelegt.
   - Dazu Rückstellmuster und Pflichtfoto der Ware.
   - Charge und MHD sind vorgegeben.
   - Gebucht wird über `pa_teilmenge_buchen`.
7. **„Bulk leer – Chargenwechsel“** und **„Produktion abschließen“** (Rest kommt noch / Rest storniert).

Ist das Etikettieren gesperrt, steht oben ein Hinweis. Teilmengen werden dann bis vor dem Etikettieren gebucht.

**Sprachen (Block F, 11.11.2026):** Alle Texte des geführten Ablaufs laufen über `werk_texte()` (`wa_*`), Meldungen aus dem Kern über `werk_msg()`. Der Umschalter DE/EN/УКР wirkt auf Tagesplan, Start, Material-Scan, Kapselprobe, Kartons, Teilmenge, Abschluss und alle Meldungen.
