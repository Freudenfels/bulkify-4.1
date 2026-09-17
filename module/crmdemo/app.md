# module/crmdemo/app.php – CRM-Demo App

Router der CRM-Demo (`?p=crmdemo&m=<modul>`): dashboard | kunden | produktentwickler | angebote |
rechnungen | produktion | finanzen. Nutzt `core/crmdemo.php` (Layout, i18n, Schema, Nummern).
Alle Schreibzugriffe nur auf `crmdemo_*`. POST-Handler oben (kunde/produkt/angebot/rechnung/produktion),
danach Render je Modul. Beträge in Cent. Beim ersten Aufruf werden Schema + Beispieldaten angelegt.
