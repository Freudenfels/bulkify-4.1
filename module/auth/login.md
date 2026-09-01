# auth/login.php – Login-Seite

**Zweck:** Öffentliche Anmeldeseite für Mitarbeiter (E-Mail + Passwort). Eigenes schlankes Layout ohne Sidebar.

**Ablauf:**
- **POST:** `auth_login(email, pass)` – bei Erfolg Session gesetzt → Redirect `?p=dashboard`; sonst Fehlermeldung „E-Mail oder Passwort falsch."
- **GET:** zentriertes Formular (E-Mail, Passwort).

**Logo:** `public/assets/bulkify-logo-dark.png` – die dunkle Wortmarke mit transparentem Hintergrund; die Login-Karte ist fest weiß (`.lg-card`, eigenes Inline-CSS ohne `app.css`), daher passt sie in beiden Themes.

**Zugang:** Route `login` ist öffentlich (im Front Controller von der Login-Pflicht ausgenommen). Ein bereits angemeldeter Benutzer wird direkt zum Dashboard geleitet.

**Erst-Admin:** Beim ersten Start legt `seed_benutzer_if_empty()` einen Admin an – **admin@bulkify.local / admin** (danach unbedingt ändern).
