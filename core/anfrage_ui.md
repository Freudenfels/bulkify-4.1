# anfrage_ui.php – Preisanfrage bei Lieferanten (Popup + Status)

## Wozu
Artikelzentrierte Preisanfrage: „Ich brauche Rohstoff X – bei wem frage ich an?" EIN Popup, EINE Route (`?p=preis_anfragen`), überall einsetzbar – auf der Rohstoffseite, der Rezeptur und im Angebots-Editor. Statt pro Seite einen eigenen Weg zu bauen.

## Funktionen
- `anfrage_status($item_id)` → `'preise'` (mind. 1 Lieferantenpreis in `lieferant_preis`), `'angefragt'` (offene `lieferant_anfrage`, noch kein Preis), `'keine'`.
- `anfrage_badge($item_id)` → farbiges Badge dazu („Preise liegen vor" / „angefragt" / „kein Preis").
- `anfrage_button($item_id, $label, $klasse)` → Knopf, der das Popup für diesen Rohstoff öffnet (alternativ inline `onclick="bxAnfrageOeffnen(id,this)"` mit `data-name` für den Popup-Titel).
- `anfrage_modal($lieferanten, $back)` → das Popup + JS **einmal je Seite** ausgeben. `$lieferanten` = Liste mit `id, firma[, land]`; `$back` = Rückkehrziel nach dem Senden.

### Fertigprodukt (Fremdfertigung) statt Einzel-Rohstoffe
Manche Lieferanten fertigen das ganze Produkt (Kapsel/Tablette/Premix). Dafür wird die **Rezeptur** als Fertigprodukt angefragt, nicht die einzelnen Rohstoffe.
- `anfrage_produkt_status($rezeptur_id)` → `'preise'` (mind. ein Lieferant hat ein Angebot abgegeben, `lieferant_anfrage.status='beantwortet'`), `'angefragt'` (offene Fertigprodukt-Anfrage), `'keine'`.
- `anfrage_produkt_badge($rezeptur_id)` → Badge dazu („Angebote liegen vor" / „angefragt" / „nicht angefragt").
- `anfrage_produkt_button($rezeptur_id, $name, $form, $label, $klasse)` → Knopf, der dasselbe Popup im Rezeptur-Modus öffnet (`onclick="bxAnfrageProduktOeffnen(rezId,this)"`, mit `data-name`/`data-form`).
- Das Popup trägt zusätzlich versteckte Felder `rezeptur_id` und `art`. Im Fertigprodukt-Modus ist `item_id` leer und `art='fertigprodukt'`.

## Ablauf
Das Popup postet an `module/einkauf/preis_anfragen.php` (Route `preis_anfragen`, Rollen einkauf/sales). Zwei Wege:
- **Rohstoff** (`item_id` gesetzt): je angekreuztem Lieferanten eine `lieferant_anfrage` (`lieferant_anfrage_stellen()`), Einheit aus dem Artikel.
- **Fertigprodukt** (`art=fertigprodukt` + `rezeptur_id`): `item_id` NULL, Betreff „Fertigprodukt (Bulk): <Rezepturname>", Form = Darreichungsform der Rezeptur, Einheit aus `anfrage_einheit_fuer_form()` (Kapsel/Tablette/kg …); `$opt=['art','form','rezeptur_id']`.

Wenn der E-Mail-Versand eingerichtet ist, geht direkt eine Mail (`mail_lieferant_anfrage()`, Sprache je Lieferant). Danach zurück zu `$back` mit `&angefragt=N&gemailt=M`.

## Eingesetzt in
- `module/lager/rohstoff_detail.php` – Panel „Lieferantenpreise", Badge + Knopf oben.
- `module/rezeptur/detail.php` – Panel „Rohstoffpreise", je Zutat Badge + Knopf; zusätzlich oben ein Block „Ganzes Produkt fremdfertigen lassen" (Fertigprodukt-Anfrage).
- `module/angebot/detail.php` – Panel „Rohstoffkosten je Lieferant", je Zutat Badge + Knopf; darüber je Rezeptur des Angebots ein Fertigprodukt-Knopf. So sieht das Team die Lieferantenpreise, bevor es den Angebotspreis macht.
- `module/intern/portal_anfrage_detail.php` – Panel „Rohstoffpreise" zur angefragten Rezeptur, plus Fertigprodukt-Block.
