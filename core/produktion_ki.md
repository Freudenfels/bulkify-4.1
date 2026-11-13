# core/produktion_ki.php

Produktion per KI melden (Nico 13.11.2026, Werk-App Teil 4). Dashboard-Datei.

- `pki_darf()`: nur Rollen admin und production.
- `pki_auswerten($text)`: `ki_json()` mit festem Prompt, striktes JSON (produkt, kunde, pa_nummer, menge, einheit packung/kapsel/kg oder leer, charge, datum, bulk_charge, etikettiert, verpackt, mitarbeiter, notiz). Nichts erfinden, Unbekanntes leer. Ohne KI-Schlüssel oder bei Fehler: `ok=false` mit Hinweis.
- `pki_kandidaten($felder, $pa_vorgabe)`: offene PAs (offen/laufend, Auftrag nicht storniert, Produkt/Rezeptur nicht inaktiv, Rest > 0). Treffer über PA-Nummer, sonst Grundcharge (2766.B → PR-2766), sonst alle Wörter des Produktnamens, bei mehreren optional Kunde.
- `pki_karte($felder, $pa)`: Vorbelegung des Nachtrag-Formulars und Rückfragen: Einheit unklar (Kapseln = Gläser à N gegen Gläser, die größere als der Rest markiert), Menge > Rest, Charge weicht vom Systemvorschlag ab (nur mit Grund), „mit Etikett“ aber Etikett fehlt (nur bis vor dem Etikettieren), Bulk-Charge fehlt (ältere zuerst vorgeschlagen) oder nicht gefunden.
- `pki_meldung($text, $pa_vorgabe, $pa_wahl)`: alles zusammen; bei genau einem PA mit Karte, bei mehreren Auswahl.

Gebucht wird nie hier: Die Karte schickt das Formular „Teilmenge nachtragen“ der PA-Seite ab (aktion=nachtragen → `pa_nachtrag_buchen()`), mit `ki_text`/`ki_von` für den Vermerk „per KI gemeldet von …, bestätigt von …“ samt Originaltext.

**Charge-Abweichung nur bei echter Abweichung (13.11.2026):** `pa_charge_abweichend($charge, $vorgabe)` (core/pa_ablauf.php) ist die eine Regel: leer oder gleich der Vorgabe (Groß-/Kleinschreibung egal) = keine Abweichung. Nur dann gibt es das Ereignis „abweichung“ und die Aufgabe ans Büro (Start, Chargenwechsel, Nachtrag, KI-Karte); ein Grund ohne Abweichung wird ignoriert. Das Feld „Grund“ erscheint erst, wenn jemand die Charge ändert (`pa_grund_js()`, Element mit Klasse `pa-grund` und `data-vorgabe`).

## Nachbesserung Fable-5 (13.11.2026, Teil 1)
- **Rückfragen serverseitig (T1.1):** Die Karte hat eine Kennung `kid` in der Sitzung. Gebucht wird nur über `pki_karte_aus_sitzung()` + `pki_buchen_pruefen()`: Einheit gewählt (`ki_lesart` aus den erlaubten Werten), Bulk gewählt oder Haken „ohne Bulk“, bei Etikett-Sperre nur „bis vor dem Etikettieren“, Karte gehört zu diesem PA, Rolle admin/production. Text und Melder kommen aus der Sitzung.
- **„Magnesium 500“ (T1.2):** Zahl am Ende des Produktnamens ohne erkannte Menge = Menge (`menge_aus_name`, Lesart-Pflicht mit Rückfrage). Findet die Suche nichts, sucht sie ohne Zahl-Wörter erneut (`info.ohne_zahl`) und zeigt immer erst die Auswahl. Gesucht wird über Auftragsbezeichnung, Produkt, Rezeptur und Produkt-Rezeptur (`suchname`).
- **kg (T1.3):** über das Füllgewicht je Packung (Pulver: Packungsgröße in g, `fuell_g`) – „25 kg = 208 Dosen à 120 g“; ohne Füllgewicht rote Rückfrage „kg kann ich nicht umrechnen - bitte Packungen eintragen“, Mengenfeld bleibt leer.
- **Kein Bulk (T1.4):** keine freie Bulk-Charge = rote Rückfrage (Haken „ohne Bulk“ nötig); „Jetzt machbar: 0 (begrenzt durch …)“ als Hinweis.
- **Genannte Charge (T1.5):** erst unter den Unter-Chargen des PA gesucht (`charge_gleich`); Treffer = deren Tag und Bulk, keine Abweichung.
- **Zahl-Abgleich, PR-Nummer (T1.8):** die KI liefert `menge_text` wörtlich; `pki_menge_abgleich()` prüft gegen den Originaltext („1.000“ wird nie 1). Genannte PR-Nummer ohne offenen Treffer: Meldung `pa_nicht_offen` statt Namenssuche; auf der PA-Seite andere PR-Nummer: `pa_anders`. KI-Aufruf mit 30 s Zeitlimit, ein Versuch.
