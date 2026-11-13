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

## Riegel und Datei-Marker (13.11.2026)
- Datei-Marker `.testumgebung` im Projektstamm des Test-Ordners: Testmodus auch auf der Kommandozeile (`bx_test_marker_da()`), bevor eine secrets.php geladen ist. Der Test-Deploy legt ihn an.
- `bx_test_riegel($pdo)`: jedes Programm ruft ihn direkt nach dem Verbinden. Testmodus + `app_meta umgebung=live` = sofort Abbruch (503). Live setzt den Marker auf app.bulkify.pro selbst.
- Sub-Apps (Lager, CRM, Buchhaltung, Produktion) nehmen im Test-Ordner nur die eigene secrets.php, nie `BULKIFY_SECRETS` oder Nachbar-/Elternordner.
- `bx_intern_host()`: interne Aufrufe Lager/Produktion -> Dashboard und QR-Links fallen im Testmodus nie auf app.bulkify.pro zurück.
- Beleg-Abruf der Buchhaltung (`be_mail_abholen`) im Testmodus gesperrt.

**Live ignoriert den Datei-Marker (13.11.2026, Fable-3 T4.4):** `bx_test_marker_da()` zählt die Datei `.testumgebung` bei Host `app.bulkify.pro` nie – ein von Hand in den Live-Ordner gelegter Marker schaltet live nicht in den Testmodus. Auf test./beta. und auf der Kommandozeile wirkt er weiter.

**Riegel Host mit Port / Kommandozeile (Fable-4 T4.7, 13.11.2026):** `bx_host_ist_live()` erkennt app.bulkify.pro auch mit Port oder Punkt am Ende. `bx_live_ordner()`: liegt der Code im Live-Ordner (Marker `.liveumgebung`, vom Live-Deploy angelegt, oder Pfad mit „app.bulkify.pro“), zählt ein `.testumgebung`-Marker nie – auch auf der Kommandozeile.
