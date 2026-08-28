# auth/login.php – Login-Seite

**Zweck:** Öffentliche Anmeldeseite für Mitarbeiter (E-Mail + Passwort). Eigenes schlankes Layout ohne Sidebar.

**Ablauf:**
- **POST:** `auth_login(email, pass)` – bei Erfolg Session gesetzt → Redirect `?p=dashboard`; sonst Fehlermeldung „E-Mail oder Passwort falsch."
- **GET:** zentriertes Formular (E-Mail, Passwort).

**Zugang:** Route `login` ist öffentlich (im Front Controller von der Login-Pflicht ausgenommen). Ein bereits angemeldeter Benutzer wird direkt zum Dashboard geleitet.

**Erst-Admin:** Beim ersten Start legt `seed_benutzer_if_empty()` einen Admin an – **admin@bulkify.local / admin** (danach unbedingt ändern).
