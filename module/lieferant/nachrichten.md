# lieferant/nachrichten.php – Rückfragen im Lieferantenportal

**Route:** `?p=lieferant_nachrichten` (nur für angemeldete Lieferanten; Whitelist `$LIEF_ROUTEN` in `public/index.php`).

**Was man sieht:** das ganze Gespräch zwischen bulkify und diesem Lieferanten als Chat (eigene Nachrichten links, bulkify rechts), jede Nachricht mit Zeit und, falls vorhanden, dem Bezug (Bestellung oder Preisanfrage, verlinkt). Darunter ein Eingabefeld.

**POST `aktion=nachricht`** (Feld `text`) → `nachricht_post_verarbeiten()` aus `core/nachricht.php`. Die `lieferant_id` kommt aus dem Login, nie aus dem Formular. Ist der Mailversand eingerichtet, bekommen alle Admins eine Mail.

Nachrichten zu einer bestimmten Bestellung oder Preisanfrage schreibt der Lieferant besser direkt dort (`lieferant_bestellung`, `lieferant_anfrage`) – dann hängen sie am Vorgang. Der Menüpunkt zeigt die Zahl ungelesener Nachrichten von bulkify.
