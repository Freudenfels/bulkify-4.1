# anfrage_ui.php – Preisanfrage bei Lieferanten (Popup + Status)

## Wozu
Artikelzentrierte Preisanfrage: „Ich brauche Rohstoff X – bei wem frage ich an?" EIN Popup, EINE Route (`?p=preis_anfragen`), überall einsetzbar – auf der Rohstoffseite, der Rezeptur und im Angebots-Editor. Statt pro Seite einen eigenen Weg zu bauen.

## Funktionen
- `anfrage_status($item_id)` → `'preise'` (mind. 1 Lieferantenpreis in `lieferant_preis`), `'angefragt'` (offene `lieferant_anfrage`, noch kein Preis), `'keine'`.
- `anfrage_badge($item_id)` → farbiges Badge dazu („Preise liegen vor" / „angefragt" / „kein Preis").
- `anfrage_button($item_id, $label, $klasse)` → Knopf, der das Popup für diesen Rohstoff öffnet (alternativ inline `onclick="bxAnfrageOeffnen(id,this)"` mit `data-name` für den Popup-Titel).
- `anfrage_modal($lieferanten, $back)` → das Popup + JS **einmal je Seite** ausgeben. `$lieferanten` = Liste mit `id, firma[, land]`; `$back` = Rückkehrziel nach dem Senden.

## Ablauf
Das Popup postet an `module/einkauf/preis_anfragen.php` (Route `preis_anfragen`, Rollen einkauf/sales): je angekreuztem Lieferanten eine `lieferant_anfrage` (`lieferant_anfrage_stellen()`), und – wenn der E-Mail-Versand eingerichtet ist – direkt eine Mail (`mail_lieferant_anfrage()`, Sprache je Lieferant). Danach zurück zu `$back` mit `&angefragt=N&gemailt=M`.

## Eingesetzt in
- `module/lager/rohstoff_detail.php` – Panel „Lieferantenpreise", Badge + Knopf oben.
- `module/rezeptur/detail.php` – Panel „Rohstoffpreise", je Zutat Badge + Knopf.
- `module/angebot/detail.php` – Panel „Rohstoffkosten je Lieferant", je Zutat Badge + Knopf; so sieht das Team die Lieferantenpreise, bevor es den Angebotspreis macht.
