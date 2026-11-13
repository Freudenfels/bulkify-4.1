# module/produktion/_ki_melden.php

Feld „Produktion melden (KI)“ oben auf `?p=produktion` und auf jeder PA-Seite (dort mit PA-Vorgabe). Texteingabe und Knopf „Diktieren“ (Spracherkennung des Browsers, nur wenn vorhanden). Handytauglich (Feld bricht um).

- `pki_ui_post($pa_vorgabe, $zurueck)`: Auswerten (`ki_melden`), Auftrag wählen bei mehreren Treffern (`ki_wahl`), Verwerfen. Ergebnis in der Sitzung (`pki_<pa>`).
- `pki_ui_render($pa_vorgabe)`: Hinweis ohne KI (mit Link zu „Teilmenge nachtragen“), „kein offener PA“, Auswahl-Liste oder Bestätigungs-Karte. Rückfragen stehen rot; die Karte ist das vorbelegte Nachtrag-Formular (alles änderbar, Lesart/Grund ggf. Pflicht, Umfang komplett oder bis vor dem Etikettieren). Gebucht wird nur per Klick auf „Buchen“ über die PA-Seite (`pa_nachtrag_buchen()`).
- Nur Rollen admin und production.
