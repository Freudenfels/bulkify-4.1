# core/produktion_ki.php

Produktion per KI melden (Nico 13.11.2026, Werk-App Teil 4). Dashboard-Datei.

- `pki_darf()`: nur Rollen admin und production.
- `pki_auswerten($text)`: `ki_json()` mit festem Prompt, striktes JSON (produkt, kunde, pa_nummer, menge, einheit packung/kapsel/kg oder leer, charge, datum, bulk_charge, etikettiert, verpackt, mitarbeiter, notiz). Nichts erfinden, Unbekanntes leer. Ohne KI-Schlüssel oder bei Fehler: `ok=false` mit Hinweis.
- `pki_kandidaten($felder, $pa_vorgabe)`: offene PAs (offen/laufend, Auftrag nicht storniert, Produkt/Rezeptur nicht inaktiv, Rest > 0). Treffer über PA-Nummer, sonst Grundcharge (2766.B → PR-2766), sonst alle Wörter des Produktnamens, bei mehreren optional Kunde.
- `pki_karte($felder, $pa)`: Vorbelegung des Nachtrag-Formulars und Rückfragen: Einheit unklar (Kapseln = Gläser à N gegen Gläser, die größere als der Rest markiert), Menge > Rest, Charge weicht vom Systemvorschlag ab (nur mit Grund), „mit Etikett“ aber Etikett fehlt (nur bis vor dem Etikettieren), Bulk-Charge fehlt (ältere zuerst vorgeschlagen) oder nicht gefunden.
- `pki_meldung($text, $pa_vorgabe, $pa_wahl)`: alles zusammen; bei genau einem PA mit Karte, bei mehreren Auswahl.

Gebucht wird nie hier: Die Karte schickt das Formular „Teilmenge nachtragen“ der PA-Seite ab (aktion=nachtragen → `pa_nachtrag_buchen()`), mit `ki_text`/`ki_von` für den Vermerk „per KI gemeldet von …, bestätigt von …“ samt Originaltext.

**Charge-Abweichung nur bei echter Abweichung (13.11.2026):** `pa_charge_abweichend($charge, $vorgabe)` (core/pa_ablauf.php) ist die eine Regel: leer oder gleich der Vorgabe (Groß-/Kleinschreibung egal) = keine Abweichung. Nur dann gibt es das Ereignis „abweichung“ und die Aufgabe ans Büro (Start, Chargenwechsel, Nachtrag, KI-Karte); ein Grund ohne Abweichung wird ignoriert. Das Feld „Grund“ erscheint erst, wenn jemand die Charge ändert (`pa_grund_js()`, Element mit Klasse `pa-grund` und `data-vorgabe`).
