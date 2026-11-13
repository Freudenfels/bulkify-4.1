# Werk-App: geführter Ablauf – Ansicht (`werk_ablauf.php`)

Seit 11.11.2026. Wird in der Schritt-Ansicht von `werk.php` eingebunden, wenn `erp_wa_aktiv()` gilt. Die alte Stations-Ansicht samt Teilmenge-Box ist dann ausgeblendet.

1. **Start, Chargenwechsel oder Wiederanlauf**
   - Bulk scannen. Danach steht groß „**Charge 2785.B · MHD 14.10.2028**“ mit dem Hinweis „Stempel genau so einstellen“.
   - Linie wählen, dazu Pflichtfoto „Linie leer“ und Pflichtfoto „Stempelbild“.
   - Abweichende Charge nur mit Grund (geht als Aufgabe ans Büro).
   - **Wiederanlauf:** Ist der letzte Start einer laufenden Unter-Charge von einem früheren Tag, kommt der Start-Dialog erneut mit neuen Fotos. Seit 12.11.2026 (Nico) gibt es dabei den **nächsten Buchstaben** (neuer Produktionstag = neue Unter-Charge, Vorschlag aus `pa_vorgabe`). Die alte Unter-Charge wird mit „neuer Produktionstag“ abgeschlossen.
2. **Laufende Charge:** groß Charge und MHD, dazu produziert/offen.
3. **Material scannen:**
   - Falsches Material wird gesperrt.
   - Ein Bulk oder Eimer einer anderen Charge ergibt **STOPP**, bis die aktuelle fertig ist.
   - Ware in Quarantäne wird gesperrt.
4. **Kapselgewicht und Sack:** Stichproben und Sackgewicht mit Tara (Teil 9). Die Tara-Auswahl sendet die ID des Gebindetyps, den Wert holt der Server aus `lg_tara` (13.11.2026).
5. **Kartons:** je vollem Karton „Karton drucken“, Menge vorbelegt, Nummer fortlaufend, Druck über die Lager-Brücke (`pakarton`).
6. **Teilmenge fertig:**
   - Die Menge ist aus den noch nicht gebuchten Kartons vorbelegt.
   - Dazu Rückstellmuster und Pflichtfoto der Ware.
   - Charge und MHD sind vorgegeben.
   - Gebucht wird über `pa_teilmenge_buchen`.
7. **„Bulk leer – Chargenwechsel“** und **„Produktion abschließen“** (Rest kommt noch / Rest storniert).

Ist das Etikettieren gesperrt, steht oben ein Hinweis. Teilmengen werden dann bis vor dem Etikettieren gebucht.

**Sprachen (Block F, 11.11.2026):** Alle Texte des geführten Ablaufs laufen über `werk_texte()` (`wa_*`), Meldungen aus dem Kern über `werk_msg()`. Der Umschalter DE/EN/УКР wirkt auf Tagesplan, Start, Material-Scan, Kapselprobe, Kartons, Teilmenge, Abschluss und alle Meldungen.

**Voll produziert / Linie leer (13.11.2026, Werk-App Teil 3):** Start/Chargenwechsel/Wiederanlauf: Haken „Linie leer, keine Reste der vorigen Charge“ statt Foto; Stempelfoto bleibt.

**Charge-Abweichung nur bei echter Abweichung (13.11.2026):** `pa_charge_abweichend($charge, $vorgabe)` (core/pa_ablauf.php) ist die eine Regel: leer oder gleich der Vorgabe (Groß-/Kleinschreibung egal) = keine Abweichung. Nur dann gibt es das Ereignis „abweichung“ und die Aufgabe ans Büro (Start, Chargenwechsel, Nachtrag, KI-Karte); ein Grund ohne Abweichung wird ignoriert. Das Feld „Grund“ erscheint erst, wenn jemand die Charge ändert (`pa_grund_js()`, Element mit Klasse `pa-grund` und `data-vorgabe`).
