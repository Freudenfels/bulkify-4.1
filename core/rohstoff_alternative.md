# Alternative zu einer Rohstoff-Anfrage (`core/rohstoff_alternative.php`)

Stand 10.11.2026 (Nico): Ein Kunde fragt einen Rohstoff an, zum Beispiel Creapure, und der ist gerade nicht lieferbar. Dann kann das Team an der Anfrage eine oder mehrere **Alternativen** vorschlagen. Der Kunde entscheidet im Portal.

Die Datei wird von Dashboard und Kundenportal genutzt und braucht nur `db.php`. Die Tabellen legt `core/schema.php` an.

## Daten
- `portal_anfrage.lieferstatus`: leer = lieferbar, `nicht_lieferbar` oder `eingestellt`. Dazu `lieferstatus_hinweis` (sieht der Kunde, z. B. „wieder ab Ende Januar“)
- `portal_anfrage.alternativen_gesendet_am`: ab da sieht der Kunde die Vorschläge
- `portal_anfrage.alternativen_entschieden_am`: der Kunde hat gewählt oder abgelehnt
- `portal_anfrage.original_rohstoff_id` und `original_betreff`: der ursprünglich angefragte Rohstoff, wenn der Kunde auf die Alternative gewechselt hat
- Tabelle `portal_anfrage_alternative`: anfrage_id, item_id, hinweis (Begründung für den Kunden), richtpreis (optional, je Einheit), status (`vorgeschlagen` | `gewaehlt` | `abgelehnt`)

## Funktionen
- `rohstoff_lieferstatus_text($pa)`: ein Satz für Kunde und Mail, z. B. „Creapure ist derzeit nicht lieferbar (wieder ab Januar).“
- `rohstoff_alternativen($anfrage_id)`: Alternativen mit Name und Unterlagen-Stand
- `rohstoff_alternativen_bisher($item_id)`: was früher zu demselben Rohstoff angeboten wurde (Vorschläge fürs Team, gewählte zuerst)
- `rohstoff_alternativen_offen_kunde($kunde_id)`: gezeigte, noch nicht entschiedene Vorschläge (Portal „Zu bestätigen“)
- `rohstoff_alternative_waehlen($kunde_id, $alt_id)`:
  - Die Anfrage wechselt auf den neuen Rohstoff. Der Betreff wird zu „X (Alternative zu Y)“, das Original bleibt gemerkt.
  - Der Lieferstatus wird zurückgesetzt, die übrigen Vorschläge gehen auf `abgelehnt`.
  - Das Team bekommt eine Benachrichtigung und einen Verlaufseintrag.
- `rohstoff_alternativen_ablehnen($kunde_id, $anfrage_id)`: Der Kunde bleibt beim Original. Alle Vorschläge gehen auf `abgelehnt`, das Team bekommt eine Benachrichtigung.

## Ablauf
1. Team: An der Rohstoff-Anfrage (`?p=portal_anfrage&id=…`) im Block „Alternative anbieten“ den Lieferstatus mit Hinweis setzen und Alternativen aus dem Katalog hinzufügen, jeweils mit Begründung und optionalem Richtpreis.
2. „Dem Kunden zeigen“: Die Vorschläge erscheinen im Portal unter „Angebote & Anfragen“ → „Zu bestätigen“. Dazu geht die Mail-Vorlage `rohstoff_alternative` raus.
3. Kunde: „Diese Alternative anfragen“ oder „Nein danke, beim ursprünglichen Rohstoff bleiben“.
4. Danach wie gewohnt: Preis und Angebot zur (jetzt umgestellten) Anfrage. Ein neuer Vorschlag nach einer Entscheidung öffnet die Runde wieder.
