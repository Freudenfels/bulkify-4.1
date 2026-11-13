# module/produktion/_ki_melden.php

Feld „Produktion melden (KI)“ oben auf `?p=produktion` und auf jeder PA-Seite (dort mit PA-Vorgabe). Texteingabe und Knopf „Diktieren“ (Spracherkennung des Browsers, nur wenn vorhanden). Handytauglich (Feld bricht um).

- `pki_ui_post($pa_vorgabe, $zurueck)`: Auswerten (`ki_melden`), Auftrag wählen bei mehreren Treffern (`ki_wahl`), Verwerfen. Ergebnis in der Sitzung (`pki_<pa>`).
- `pki_ui_render($pa_vorgabe)`: Hinweis ohne KI (mit Link zu „Teilmenge nachtragen“), „kein offener PA“, Auswahl-Liste oder Bestätigungs-Karte. Rückfragen stehen rot; die Karte ist das vorbelegte Nachtrag-Formular (alles änderbar, Lesart/Grund ggf. Pflicht, Umfang komplett oder bis vor dem Etikettieren). Gebucht wird nur per Klick auf „Buchen“ über die PA-Seite (`pa_nachtrag_buchen()`).
- Nur Rollen admin und production.

**Charge-Abweichung nur bei echter Abweichung (13.11.2026):** `pa_charge_abweichend($charge, $vorgabe)` (core/pa_ablauf.php) ist die eine Regel: leer oder gleich der Vorgabe (Groß-/Kleinschreibung egal) = keine Abweichung. Nur dann gibt es das Ereignis „abweichung“ und die Aufgabe ans Büro (Start, Chargenwechsel, Nachtrag, KI-Karte); ein Grund ohne Abweichung wird ignoriert. Das Feld „Grund“ erscheint erst, wenn jemand die Charge ändert (`pa_grund_js()`, Element mit Klasse `pa-grund` und `data-vorgabe`).
