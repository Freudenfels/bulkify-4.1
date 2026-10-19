# einkauf/preis_anfragen.php – zentrale Preisanfrage (POST)

Route: `?p=preis_anfragen` (POST; Rollen einkauf, sales, admin)

Nimmt aus dem Anfrage-Popup (`core/anfrage_ui.php`) eine Liste `anf_lieferant[]`, optional Menge/Notiz/CoA und ein `back`-Ziel entgegen. Zwei Modi:

- **Rohstoff:** `item_id` gesetzt → je Lieferant eine `lieferant_anfrage` zum Artikel (Einheit aus dem Artikel).
- **Fertigprodukt:** `art=fertigprodukt` + `rezeptur_id` → je Lieferant eine Anfrage ohne `item_id`, Betreff „Fertigprodukt (Bulk): <Rezepturname>", Form = Darreichungsform der Rezeptur, Einheit aus `anfrage_einheit_fuer_form()` (Kapsel/Tablette/kg …).
- **Bulk-Rohstoffe:** `item_ids` (kommagetrennt, aus „Alle anfragen" auf `rohstoffe_papiere`) → Schleife über **alle** Rohstoffe × **alle** gewählten Lieferanten, je Kombination eine `lieferant_anfrage` (CoA/Spez + optional Kilopreis). `angefragt=N` = Summe aller erzeugten Anfragen. Greift nur, wenn `art!='fertigprodukt'`. **Wichtig:** Im Bulk-Pfad werden erst alle Anfragen angelegt (schnell) und die **E-Mails erst NACH der Antwort** verschickt (`nach_antwort()`), weil synchroner SMTP-Versand (bis 15 s je Mail) bei vielen Kombinationen sonst ins PHP-Timeout läuft (500). Daher kein `&gemailt=` im Bulk-Redirect.

Legt je gesperrt-freiem Lieferanten eine `lieferant_anfrage` an und – wenn der E-Mail-Versand eingerichtet ist – verschickt die Anfrage direkt (`mail_lieferant_anfrage`). Danach zurück zu `back` mit `&angefragt=N&gemailt=M`.

`back` wird auf interne relative Ziele beschränkt (kein Open-Redirect). Ohne Lieferanten – bzw. im Rohstoff-Modus ohne Artikel, im Fertigprodukt-Modus ohne gültige Rezeptur – kommt `&anffehler=1` zurück.
