# portal/login.php – Kunden-Login (E-Mail + Passwort)

Route `?p=portal_login` (öffentlich). GET zeigt ein Anmeldeformular (E-Mail + Passwort), POST authentifiziert über `kunde_login()` (case-insensitive E-Mail, `password_verify`, nur nicht-gesperrte Kunden mit gesetztem Passwort). Erfolg → `$_SESSION[portal_kid]` + Weiterleitung ins Portal (`?p=portal`). Schon eingeloggt → direkt ins Portal.

**Erstzugang:** Ein neuer Kunde hat noch kein Passwort. Er kommt über den **Portal-/Erstzugang-Link** (Magic-Link `?p=portal&token=…`, aus der Kundenseite kopierbar) ins Portal; solange `kunden.passwort` leer ist, zeigt `portal/kunde.php` zuerst das Formular **Konto einrichten** (E-Mail + Passwort + fehlende Stammdaten, `aktion=konto_einrichten` → `kunde_passwort_setzen()`). Danach Anmeldung hier mit E-Mail/Passwort; der Magic-Link bleibt als Notfall-Zugang. Abmelden: `?p=portal&v=logout`.

## Registrierung (05.11.2026)
Unter dem Formular steht der Link „Noch kein Konto? Jetzt registrieren“ → `?p=portal_registrieren` ([registrieren.md](registrieren.md)). Wer sich selbst registriert hat und noch nicht freigegeben ist, bekommt bei richtigem Passwort die Meldung „Ihr Konto wird noch von bulkify geprüft …“ statt „E-Mail oder Passwort ist nicht korrekt“.
