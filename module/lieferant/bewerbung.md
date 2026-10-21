# lieferant/bewerbung.php – öffentliche Lieferanten-Bewerbung (Self-Registration)

Route `?p=lieferant_bewerbung` (öffentlich, in `$PUBLIC`). Mehrsprachig (DE/EN/ZH) über `lp_t`/`lp_sprachwahl`
wie das übrige Lieferantenportal; Standardsprache Englisch (internationale Lieferanten).

**Ablauf:** Der Lieferant trägt seine Daten selbst ein (Firma, Ansprechpartner, E-Mail, Telefon, Land,
Webseite, Währung, Kategorien, Nachricht) und legt ein Passwort fest. `lieferant_bewerbung_anlegen()`
(core/schema.php) legt einen **gesperrten** Lieferanten (`quelle='bewerbung'`, `gesperrt=1`) + einen
**inaktiven** Login (`benutzer.aktiv=0`, Rolle `lieferant`) an. Validierung: Firma/E-Mail Pflicht, Passwort
≥ 8, E-Mail nicht schon als Benutzer, Firma nicht schon als Lieferant vorhanden. Erfolgsseite: „wir prüfen".

**Login erst nach Freigabe:** `lieferant/login.php` prüft `benutzer ... aktiv=1` – ein Bewerber kommt also
nicht rein, bis das Team freigibt.

**Freigabe (Team):** Dashboard-Lieferantenliste zeigt offene Bewerbungen (Status-Badge „Bewerbung" +
Hinweisbanner mit Anzahl). Im Lieferant-Detail erscheint ein Freigabe-Panel; `bewerbung_freigeben`
→ `lieferant_bewerbung_freigeben()` entsperrt den Lieferanten (`gesperrt=0`) und aktiviert die Logins
(`benutzer.aktiv=1`).

Einstieg für Lieferanten: Button „Jetzt bewerben" auf der Login-Seite (`lp_t('bew_jetzt')`).

## Einladender Pitch (oben)
Über dem Formular steht ein mehrsprachiger Pitch-Block (`pitch_head/pitch_wer/pitch_b1..b4/pitch_invite` in `portal_layout.php`): kurz, was bulkify (Maniso GmbH) macht, Vorteile der Zusammenarbeit und eine Einladung „wir hätten Sie gern an Bord". So ist `?p=lieferant_bewerbung` die eine Seite, die das Team Lieferanten schicken kann: erst der Pitch, darunter das Bewerbungsformular (Selbst-Registrierung → gesperrter Lieferant + inaktiver Login bis zur Freigabe). DE/EN/ZH über die Sprachwahl oben.
