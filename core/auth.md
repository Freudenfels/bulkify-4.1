# core/auth.php – Authentifizierung & Rollen

**Zweck:** Login-Zustand und rollenbasierte Rechte für interne Mitarbeiter. Kundenportale bleiben davon getrennt (passwortloser Magic-Link, Route `portal`).

**Rollen (`rollen_liste()`):** admin · sales · finance · einkauf · production · fulfillment · labor. **Mehrere Rollen pro Person** möglich (gespeichert als CSV in `benutzer.rollen`, z. B. „finance,einkauf"). **admin** sieht und darf alles.

**Rechte-Karte (`route_rollen_map()`):** je Route die erlaubten Rollen. `'*'` = jede angemeldete Person (z. B. dashboard). Unbekannte Route → nur admin (sicherer Default).

**Funktionen:**
- `current_user()` – Benutzerzeile aus der Session (`$_SESSION['uid']`), nur wenn aktiv; gecacht.
- `is_logged_in()`, `user_rollen()` (Array), `has_role($r)` (admin immer true).
- `route_erlaubt($route)` – darf der aktuelle Benutzer die Route? admin immer; sonst Schnittmenge der Rollen mit der Rechte-Karte.
- `auth_login($email,$pass)` – prüft `password_verify` gegen `benutzer.pass_hash`, setzt Session, stempelt `letzter_login`.
- `auth_logout()` – Session-UID entfernen.

**Wo verdrahtet:** `public/index.php` startet die Session, erzwingt Login (außer öffentliche Routen `login`/`portal`), prüft `route_erlaubt` und zeigt sonst „Kein Zugriff". `core/layout.php` filtert die Navigation über `route_erlaubt` und zeigt unten die Benutzer-Box mit Abmelden.

**Phase 1 Grenzen:** Rechte gelten pro **Seite/Modul**, noch nicht pro Einzel-Aktion (Gate). Aktionsschutz (nur Labor darf freigeben, nur Fulfillment versenden, nur Einkauf bestellen) kommt in Phase 2.

## Autologin (lokal)

Für bequemes Testen gibt es einen **Direktlink ohne Passwort**: `?p=autologin&token=<login_token>`. Jeder Benutzer hat ein `benutzer.login_token` (in init_schema automatisch gefüllt, `bin2hex(random_bytes)`). `auth_login_by_token()` loggt darüber ein – **nur von localhost** (`ist_lokal()`, Prüfung REMOTE_ADDR 127.0.0.1/::1), damit der Link kein Backdoor im Livebetrieb ist. Nach Erfolg Weiterleitung ins passende Dashboard (Werk-Bereich bzw. Verkaufs-Dashboard).
