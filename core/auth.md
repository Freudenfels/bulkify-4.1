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

## Autologin / Testlogin

Für bequemes Testen gibt es einen **Direktlink ohne Passwort**: `?p=autologin&token=<login_token>`. Jeder Benutzer hat ein `benutzer.login_token` (in init_schema automatisch gefüllt, `bin2hex(random_bytes)`). `auth_login_by_token()` loggt darüber ein. Nach Erfolg Weiterleitung ins passende Dashboard (Werk-Bereich bzw. Verkaufs-Dashboard).

Ob der Direktlink greift, entscheidet `testlogin_erlaubt()`:
- **Lokal** (`ist_lokal()`, REMOTE_ADDR 127.0.0.1/::1) immer.
- **Auf einem Server** nur, wenn der Testlogin bewusst freigeschaltet ist (`app_meta testlogin=1`) **und das Zeitfenster noch läuft** (`app_meta testlogin_bis`, UTC-Zeitstempel). Standard = aus, damit der Link kein Backdoor im Livebetrieb ist. Einschalten unter Einstellungen → Testlogin setzt `testlogin_bis` auf **jetzt + 4 Stunden**; nach Ablauf schaltet `testlogin_erlaubt()` den Schalter beim nächsten Aufruf selbst auf 0 (self-off), sodass kein offener Zugang vergessen werden kann. Erneut einschalten verlängert um weitere 4 Stunden.

Der Kunden-Direktlink läuft nicht über Autologin, sondern über den Portal-Magic-Link `?p=portal&token=<kunden.portal_token>` – der funktioniert unabhängig vom Testlogin-Schalter überall.

## Rollen-Map-Abgleich (St-10)
- Ergänzt: `rezeptur_detail`/`rezeptur_pib` + Innendienst (sales), `lief_anfrage_detail` (einkauf, finance), `auftrag`/`produkt_pib`/`lieferschein_pdf` + QM (labor), `wareneingang`/`lieferschein_pdf` + Lager (fulfillment), `kontingente`/`kontingent` (sales, finance), `rohstoffe_papiere` und Alt-Route `lieferant_preise` wie die Rohstoff-/Einkaufsseiten.
- Jede Route aus `$routes` in `public/index.php` steht jetzt in `route_rollen_map()`. System-/Import-/Bereinigungswerkzeuge und `benachrichtigungen` stehen bewusst mit `[]` (nur Admin) drin.
- Regel: neue Route = Eintrag in `index.php` UND hier (sonst Default nur Admin).

## QM-Gate (St-11)
Quarantäne-Ware freigeben (Status auf „frei") darf nur QM (Rolle `labor`) oder Admin – Dashboard `darf_qm_freigeben()`, Lager-App `lg_darf_qm_freigeben()`. Andere Rollen sehen „Freigabe durch QM" statt des Knopfs; ein direkter POST wird abgewiesen. Sperren oder zurück in Quarantäne darf weiterhin jeder.

- 05.11.2026: `labor_senden` (Ans Labor senden) mit denselben Rollen wie `laboranalysen` (production, einkauf, labor, sales).

## produktion_neu (08.11.2026)
`produktion_neu` => `[admin]` – neue Produktionsübersicht im Aufbau, erst nur Admin.

- `weg_festlegen` (10.11.2026): nur Admin, Inhalt des Pop-ups „Weg festlegen“.

- `artikel_zusammenfuehren` (10.11.2026): production, einkauf, labor für die „Meintest du …?“-Suche. Die Seite selbst prüft Admin.

## rohstoff_steckbrief (11.11.2026)
Route `rohstoff_steckbrief`: production, einkauf, labor, sales, finance (admin immer). Nur intern.

## Gesperrt = kein Zugang (Sofortfix 11.11.2026)
- `current_user()` prüft bei **jedem** Aufruf: Hängt der Benutzer an einem Lieferanten mit `lieferanten.gesperrt=1`, endet die Sitzung sofort (`$_SESSION['zugang_gesperrt']`), Weiterleitung auf den Lieferanten-Login mit „Zugang gesperrt, bitte bulkify kontaktieren“.
- `auth_login()` (Team-Login) und `auth_login_by_token()` lassen einen gesperrten Lieferanten ebenfalls nicht hinein.
- `lieferant_zugang_gesperrt($id)` und `zugang_gesperrt_text()` sind die gemeinsamen Helfer (auch fürs Kundenportal).
- Eine Lieferanten-Bewerbung (`quelle='bewerbung'`) hat ohnehin nur einen **inaktiven** Login bis zur Freigabe; durch die Sperre ist sie jetzt doppelt zu.

## Sofortfix 13.11.2026: Unterlagen-Sperre wird Quarantäne-Sperre
Regel Nico: Unterlagen fehlen = Ware bleibt in Quarantäne. Bestellen und Einbuchen gehen immer. Artikel ohne Unterlagenpflicht: „kein Dokument nötig“ (Details `core/lieferant_unterlagen.md`).

Neue Route `kein_dokument` in `route_rollen_map()`: nur Rolle `labor` (QM) und Admin.
