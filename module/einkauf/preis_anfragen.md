# einkauf/preis_anfragen.php – zentrale Preisanfrage (POST)

Route: `?p=preis_anfragen` (POST; Rollen einkauf, sales, admin)

Nimmt aus dem Anfrage-Popup (`core/anfrage_ui.php`) `item_id`, eine Liste `anf_lieferant[]`, optional Menge/Notiz/CoA und ein `back`-Ziel entgegen. Legt je gesperrt-freiem Lieferanten eine `lieferant_anfrage` an und – wenn der E-Mail-Versand eingerichtet ist – verschickt die Anfrage direkt (`mail_lieferant_anfrage`). Danach zurück zu `back` mit `&angefragt=N&gemailt=M`.

`back` wird auf interne relative Ziele beschränkt (kein Open-Redirect). Ohne Artikel oder ohne Lieferanten kommt `&anffehler=1` zurück.
