# portal/registrieren.php – Kunden-Registrierung

Route `?p=portal_registrieren`, öffentlich (in `$PUBLIC`). Seit 05.11.2026. Auf der Kunden-Anmeldeseite steht der Link „Noch kein Konto? Jetzt registrieren“.

## Ablauf
1. **Formular:**
   - Pflicht: Firma, Ansprechpartner, E-Mail, Straße, PLZ, Ort, Land (Auswahl, Standard DE), Passwort zweimal (mind. 8 Zeichen), Haken „Ich bestelle als Unternehmen und meine Angaben stimmen“.
   - Optional: Telefon, Hausnummer, USt-IdNr., „Was haben Sie vor?“.
2. **Anlegen** über `kunde_registrierung_anlegen()` (`core/schema.php`):
   - neuer Kunde mit eigener Kundennummer, **gesperrt**, `quelle='registrierung'`, `registriert_am`, Passwort als Hash,
   - die Nachricht landet in der Notiz,
   - die USt-IdNr. wird groß und ohne Leerzeichen gespeichert.
   - Eine schon vorhandene E-Mail (Kunde oder Kunden-Mitarbeiter) wird abgelehnt: „Für diese E-Mail gibt es bereits ein Konto“.
   - Gleicher Firmenname wird nicht abgelehnt. Er wird dem Team als mögliche Dublette gemeldet.
3. **Mails** über `mail_kunde_registrierung_eingang()`, nach der Antwort:
   - Team bekommt immer eine Dashboard-Benachrichtigung, per Mail an Admins und CC, wenn der Versand an ist. Mit Link zum Kunden und Dubletten-Hinweis.
   - Kunde bekommt eine Eingangsbestätigung.
4. **Anmelden vor der Freigabe:** Die Anmeldeseite meldet bei richtigem Passwort „Ihr Konto wird noch von bulkify geprüft“ statt „falsch“.
5. **Freigabe durch das Team** auf der Kundenseite: gelber Hinweis „Hat sich am … selbst registriert und wartet auf Freigabe“ (mit Dubletten-Link) und Knopf **„Registrierung freigeben“**.
   - `kunde_registrierung_freigeben()` entsperrt den Kunden und schreibt Änderungslog und Verlauf.
   - Danach geht die Mail `mail_kunde_registrierung_freigegeben()` mit dem Anmelde-Link an den Kunden.
   - Nicht freigeben = einfach gesperrt lassen bzw. zusammenführen.

## Spam-Schutz
- verstecktes Feld (Honeypot) mit vorgetäuschtem Erfolg, es wird nichts angelegt,
- Mindest-Ausfüllzeit 3 s,
- höchstens 3 Registrierungen je Sitzung und Stunde.
