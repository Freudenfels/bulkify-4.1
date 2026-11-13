# public/einkauf_cron.php – Automatische Bestell-Erinnerungen (B4)

Token-gesicherter Cron-Endpunkt (kein Login), analog `public/crm/mail_cron.php`.

**Aufruf (Server-Cron, z. B. 1×/Tag):** `<host>/einkauf_cron.php?token=<einkauf_cron_token>`
Den Token zeigt die „Bestellt"-Liste (`?p=einkauf`) im Admin-Aufklappkasten; er wird beim ersten `einkauf_cron_token()` in `app_meta` angelegt. Optional `&tage=N` (Überfälligkeits-Schwelle, Default 3).

Ruft `einkauf_erinnerungen_cron()` (`core/schema.php`) auf: erinnert alle **überfälligen, unbestätigten Portal-Bestellungen** (`bestellung_ueberfaellig()`), je Bestellung höchstens **1×/24 h** (`bestellung.erinnert_am`). Antwort JSON `{ok, gesendet, geprueft}`. Mailversand über Dashboard-SMTP (`mail_lieferant_erinnerung()`), nicht `mail()`.

**Seit 11.11.2026 (Block D)** prüft derselbe Cron zusätzlich bald ablaufende Preise (Rohstoff, Zukauf, Fremdfertigung; `pg_bald_pruefen`, einmal am Tag) und kennzeichnet Auto-EKs aus abgelaufenen Preisen (`pg_ek_abgelaufen_pruefen`). Antwort zusätzlich `preise_bald` und `ek_abgelaufen`.

**13.11.2026 (Paket Teil 4):** Gemeinsamer täglicher Einstieg. Zusätzlich `etikett_erinnerungen_cron()` (Etikett fehlt → Kunde erinnern, max. 1 Mail je Kunde und Tag) und `cron_lauf_merken()` (app_meta `cron_letzter_lauf`). Die Antwort enthält jetzt auch `etikett_erinnerung_kunden`, `etikett_erinnerung_auftraege` und `etikett_fehler`.

**Fable-6 (13.11.2026), Teil 0:**
- Der Cron lädt `core/pdf_beleg.php` (für `beleg_firma()` in den Erinnerungs-Mails). Vorher brach er mit einem Fatal Error ab, sobald eine Bestellung zu erinnern war.
- Jede Mail-Art läuft in einem eigenen `try/catch`: Bestell-Erinnerungen, Preise bald, EK abgelaufen, Etikett-Erinnerungen, Mail-Warteschlange. Stürzt eine ab, laufen die anderen weiter. Die Antwort meldet das unter `abbruch` und setzt `ok:false`. Einzelne Mails, die nicht rausgingen, stehen unter `fehler`; dann bleibt `ok:true`.
- Sperre gegen Doppellauf: `cron_sperre_holen()` (app_meta `cron_sperre`, 15 Minuten). Ein zweiter Aufruf bekommt HTTP 409 mit `ok:false`.
- Regeln der Bestell-Erinnerung stehen in `core/bestellung_erinnerung.php`:
  - eine Mail je Lieferant und Tag
  - nur Bestellungen mit offenem Rest
  - höchstens 3 Erinnerungen, danach die Aufgabe „Lieferant reagiert nicht: BE-…“
  - keine gesperrten Lieferanten
  - `erinnert_am` nur bei Erfolg
- Antwort: `ok`, `abbruch`, `gesendet` (Mails), `bestellungen`, `geprueft`, `aufgaben_lieferant_reagiert_nicht`, `preise_bald`, `ek_abgelaufen`, `etikett_erinnerung_kunden`, `etikett_erinnerung_auftraege`, `warteschlange_gesendet`, `fehler`.
- **Aufruf:** täglich 07:00 UTC (8 Uhr Winterzeit) über GitHub Actions `.github/workflows/cron-taeglich.yml`. Der united-domains-Webspace hat keine Cronjobs. Den Token gibt das Secret `CRON_TOKEN` mit; er muss dem Wert in app_meta `einkauf_cron_token` entsprechen, den die Einkauf-Liste dem Admin zeigt. Der Workflow ist rot bei einem HTTP-Fehler oder bei `ok:false`. Von Hand startet ihn „Run workflow“ (workflow_dispatch).
