# Testumgebung test.bulkify.pro einrichten (für Nico, einmalig)

Stand 12.11.2026. Der Code ist fertig (Testmodus, Band, Mail-Umleitung, Sperren, Druckvorschau, Abzug-Skript,
Deploy-Workflow `deploy-test.yml`). Es fehlt nur, was im Hosting und bei GitHub von Hand gemacht werden muss.
**beta.bulkify.pro bleibt unangetastet** (V3 als Notfall-Backup).

## Ist-Stand (geprüft 11.11.2026)
- `app.bulkify.pro` zeigt auf 217.160.0.224 (Hosting von live).
- `test.bulkify.pro` zeigt heute auf **23.227.38.65** – das ist eine Shopify-Adresse (vermutlich der Shop bzw. ein
  Sammel-Eintrag *.bulkify.pro). Die Subdomain ist im Hosting also **noch nicht angelegt**.

## Schritte im Hosting (gleiches Paket wie app.bulkify.pro)
1. **Ordner** anlegen, z. B. `bulkify-test` – neben dem Live-Ordner, **nicht** darin und **nicht** der beta-Ordner.
2. **Subdomain** `test.bulkify.pro` anlegen und als Zielverzeichnis `bulkify-test/public` eintragen
   (genau wie bei app.bulkify.pro der Ordner `…/public`).
3. **DNS:** Der Eintrag für `test` muss auf das Hosting zeigen (wie `app`), nicht mehr auf Shopify.
   Wenn das Hosting die Subdomain selbst verbindet, reicht Schritt 2; sonst A-Eintrag `test` auf dieselbe Adresse wie `app`.
4. **PHP-Version** der Subdomain: dieselbe wie bei app.bulkify.pro (PHP 8.3).
5. **SSL-Zertifikat** für test.bulkify.pro aktivieren (wie bei app).
6. **Datenbank** neu anlegen (MySQL/MariaDB), z. B. „bulkify_test“. Name, Host, Benutzer, Passwort notieren.
7. **Optional, empfohlen:** für die Live-Datenbank einen zweiten Benutzer nur mit Leserecht (SELECT, SHOW VIEW).
   Den trägt man unten als LIVE_DB_USER ein. Geht das im Hosting nicht, den normalen Live-Zugang nehmen –
   das Abzug-Skript liest nur und öffnet die Verbindung schreibgeschützt.
8. Datei **`bulkify-test/secrets.php`** anlegen (per SFTP, wird nie hochgeladen oder überschrieben):

```
<?php
define('BX_TESTUMGEBUNG', true);            // Testmodus: Band, Mails nur an Nico, kein Druck, keine Crons
define('BX_TEST_MAIL_AN', 'thomalla@freudenfels.de');   // '' = gar keine Mails, nur Protokoll

// eigene Test-Datenbank (Schritt 6)
define('DB_HOST', '…');  define('DB_PORT', '3306');
define('DB_NAME', '…');  define('DB_USER', '…');  define('DB_PASS', '…');

// Quelle für den Abzug live -> test (Schritt 7), nur lesen
define('LIVE_DB_HOST', '…');  define('LIVE_DB_PORT', '3306');
define('LIVE_DB_NAME', '…');  define('LIVE_DB_USER', '…');  define('LIVE_DB_PASS', '…');
define('LIVE_DATA_DIR', '/pfad/zum/live-ordner/data');   // für Dokumente/Bilder; leer lassen = ohne Dateien
```
   Falls die Live-`secrets.php` weitere Werte enthält (z. B. KI-Schlüssel), diese ebenfalls übernehmen – KI ist in test erlaubt.
9. **Keine Cronjobs** für den Test-Ordner einrichten (sie würden ohnehin sofort „Testumgebung: Cron aus“ melden).

## Schritt bei GitHub
10. Repository → Settings → Secrets and variables → Actions → **New repository secret**
    `TEST_SERVER_PATH` = absoluter Pfad des Test-Ordners auf dem Server (Schreibweise wie beim vorhandenen
    `SERVER_PATH`, nur mit `bulkify-test`). Ohne dieses Secret überspringt der Test-Deploy still.
11. Actions → „Deploy Testumgebung (test.bulkify.pro)“ → **Run workflow** (erster Lauf lädt alles hoch, danach
    läuft er nach jedem Live-Deploy automatisch und lädt nur Geändertes).

## Daten holen
12. https://test.bulkify.pro öffnen, mit dem eigenen Admin anmelden (beim allerersten Mal ist die Test-DB leer:
    Erst-Admin wie bei einer Neuinstallation; danach gelten die Logins von live).
13. Im roten Band „Datenstand von live holen“ → „Abzug starten“. Dauert je nach Datenmenge einige Minuten.
    Jederzeit wiederholbar. Alternativ per SSH: `php tools/test_abzug.php`.

## Prüfen
- Jede Seite zeigt oben das rote Band „TESTUMGEBUNG - keine echten Daten“.
- Lager-App → Etikett drucken: Meldung „Testumgebung: nicht gedruckt“, PDF unter „Druckvorschau“ im Band.
- Eine Aktion mit Kundenmail (z. B. Angebot senden): Mail kommt nur bei Nico an, Betreff „[TEST an kunde@…]“.
- https://test.bulkify.pro/robots.txt zeigt „Disallow: /“.
