# einkauf/preis_anfragen.php – zentrale Preisanfrage (POST)

Route: `?p=preis_anfragen` (POST; Rollen einkauf, sales, admin)

Nimmt aus dem Anfrage-Popup (`core/anfrage_ui.php`) eine Liste `anf_lieferant[]`, optional Menge/Notiz/CoA und ein `back`-Ziel entgegen. Zwei Modi:

- **Rohstoff:** `item_id` gesetzt → je Lieferant eine `lieferant_anfrage` zum Artikel (Einheit aus dem Artikel).
- **Fertigprodukt:** `art=fertigprodukt` + `rezeptur_id` → je Lieferant eine Anfrage ohne `item_id`, Betreff „Fertigprodukt (Bulk): <Rezepturname>", Form = Darreichungsform der Rezeptur, Einheit aus `anfrage_einheit_fuer_form()` (Kapsel/Tablette/kg …).
- **Bulk-Rohstoffe:** `item_ids` (kommagetrennt, aus „Alle anfragen" auf `rohstoffe_papiere`) → Schleife über **alle** Rohstoffe × **alle** gewählten Lieferanten, je Kombination eine `lieferant_anfrage` (CoA/Spez + optional Kilopreis). `angefragt=N` = Summe aller erzeugten Anfragen. Greift nur, wenn `art!='fertigprodukt'`. **Wichtig:** Im Bulk-Pfad werden erst alle Anfragen angelegt (schnell) und die **E-Mails erst NACH der Antwort** verschickt (`nach_antwort()`), weil synchroner SMTP-Versand (bis 15 s je Mail) bei vielen Kombinationen sonst ins PHP-Timeout läuft (500). Daher kein `&gemailt=` im Bulk-Redirect. **Eine Mail pro Lieferant:** Die erzeugten Anfrage-IDs werden je Lieferant gesammelt und über `mail_lieferant_anfrage_sammel($lid,$ids)` (core/mail.php) als **eine** Mail mit allen angefragten Rohstoffen verschickt – der Lieferant bekommt nicht je Rohstoff eine eigene Mail. Die einzelnen `lieferant_anfrage` bleiben pro Rohstoff bestehen (Status-Tracking).

Legt je gesperrt-freiem Lieferanten eine `lieferant_anfrage` an und – wenn der E-Mail-Versand eingerichtet ist – verschickt die Anfrage direkt (`mail_lieferant_anfrage`). Danach zurück zu `back` mit `&angefragt=N&gemailt=M`.

`back` wird auf interne relative Ziele beschränkt (kein Open-Redirect). Ohne Lieferanten – bzw. im Rohstoff-Modus ohne Artikel, im Fertigprodukt-Modus ohne gültige Rezeptur – kommt `&anffehler=1` zurück.

## Premix (10.11.2026)
Kommt `premix=1` mit einer Fertigprodukt-Anfrage an einer Kapsel-/Tabletten-Rezeptur, wird die Anfrage als Form Pulver, Einheit kg, ohne Kapselgröße gestellt. Betreff „Premix (fertige Mischung, lose): …“, die Notiz beginnt mit „Premix: fertige Mischung der Rezeptur, lose (nicht verkapselt), Preis je kg.“

## Premix (Teil 7, 10.11.2026)
Bei der ersten Premix-Anfrage wird der Premix-Artikel der Rezeptur angelegt („Premix · <Rezeptur>“). In die Notiz kommt die Zusammensetzung: je Rohstoff mg je Einheit und Anteil in % der Mischung, dazu „Bitte CoA/Spezifikation mitschicken“. Das Portal zeigt die Rezeptur zusätzlich als Tabelle, CoA oder Spezifikation je Rohstoff ist bei der Abgabe Pflicht.

**Zahlen-Lesart (13.11.2026, Fable-3 T2.9):** Betragsfelder lesen mit `betrag_lesen()`, Preise/Prozente/Kurse mit `zahl_lesen()`, Mengen mit `zahl_lesen(…, true)` – alle aus core/zahl_lesen.php (eine Regel mit dem Dashboard). „21.687,75“, „21687,75“ und „21687.75“ ergeben 21687,75.
