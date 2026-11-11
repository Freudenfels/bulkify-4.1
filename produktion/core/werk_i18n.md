Neue Texte (DE/EN/UK) für Teilmengen und Hinweise (Soll-Kette 4a, 04.11.2026); `material_missing` verweist jetzt auf die Teilmenge.

Texte für Anweisungen (instr_h, instr_one, prio_high), Soll-Kette 4b.

Texte für Kartonetiketten (carton_*), Soll-Kette 6.

Texte `return_box`, `return_closed`, `return_closed_req` (Rückgabe je Karton, 04.11.2026) in DE/EN/UK.

**Ein Weg / geführter Ablauf auf EN und UK (Block F, 11.11.2026):** 94 neue Schlüssel `wa_*` (Ablauf, Tagesplan, Meldungen) in DE/EN/UK. `werk_msg($msg, $lang)` übersetzt Meldungen aus dem gemeinsamen Kern (`core/pa_ablauf.php`, `pa_kapsel.php`, `pa_eimer.php`, `core/produktion_plan.php`): erst ganze Sätze per Muster, dann Teilstücke. Unbekanntes bleibt Deutsch.
