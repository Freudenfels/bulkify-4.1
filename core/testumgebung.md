# testumgebung.php – Testumgebung test.bulkify.pro (Paket 12.11.2026 Teil 2)

Eine kleine, eigenständige Datei, die **alle** Programme (Dashboard, Lager, Produktion, CRM, Buchhaltung) aus ihrer
`config.php` laden. Sie schaltet den Testmodus ein, wenn
- die `secrets.php` der Testumgebung `define('BX_TESTUMGEBUNG', true);` setzt, oder
- die Seite über einen Host aufgerufen wird, der mit `test.` beginnt.

Live (app.bulkify.pro) ist nie betroffen.

## Was der Testmodus macht
- **Band** oben auf jeder HTML-Seite (Dashboard, Werk-App, Lager-App, Kunden- und Lieferantenportal, Logins):
  „TESTUMGEBUNG - keine echten Daten“, mit Links zur Druckvorschau und zum Abzug.
- **noindex:** Kopf `X-Robots-Tag: noindex, nofollow` und `<meta name="robots">`; die `robots.txt` (Disallow /)
  legt nur der Deploy-Job der Testumgebung ab.
- **Mails:** `mail_senden()` und das CRM schicken alles nur an `BX_TEST_MAIL_AN` (Standard Nico), Betreff
  „[TEST an <echter Empfänger>] …“. Ist `BX_TEST_MAIL_AN` leer, wird nur ins Mail-Protokoll geschrieben.
  Die Adressen in den Daten bleiben unverändert (echte Kopie).
- **Aus:** Crons (`einkauf_cron.php`, `crm/mail_cron.php`), IMAP-Abruf des CRM, Blinker-Cloud, Carrier-Aufrufe
  (Versand-Labels) und die Druckbrücke. Druckaufträge gibt es nur als PDF in der Lager-App unter `?p=druck_vorschau`.
- KI-Aufrufe bleiben erlaubt.

## Funktionen
`bx_testumgebung()`, `bx_test_mail_an()`, `bx_test_mail_umleiten($to, $betreff)`, `bx_test_gesperrt($was)`,
`bx_test_band_html()`, `bx_test_start()` (Ausgabepuffer, setzt Band + noindex).
