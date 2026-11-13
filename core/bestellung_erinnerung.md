# core/bestellung_erinnerung.php – Bestell-Erinnerungen an Lieferanten (Cron)

Diese Regeln gelten seit der Fable-Prüfung 6 (P1/P2, 13.11.2026). Aufgerufen wird `ber_cron()` über `einkauf_erinnerungen_cron()` aus dem täglichen Cron `public/einkauf_cron.php`.

## Regeln
- **Eine Mail je Lieferant und Tag** mit allen fälligen Bestellungen (`mail_lieferant_erinnerung_sammel()` in `core/mail.php`). Bei genau einer Bestellung geht die gewohnte Einzel-Mail raus.
- **Fällig** ist eine Bestellung, wenn:
  - sie unbestätigt ist (Portal-Lieferant)
  - sie seit 3 Tagen offen ist
  - sie nicht geliefert, nicht storniert und kein Entwurf ist
  - die letzte Erinnerung mindestens 20 Stunden her ist
- **Nur offener Rest:** Mindestens eine Position hat Menge > geliefert und ist nicht „Rest storniert“ (`ber_offene_positionen()`).
- **Höchstens 3 Erinnerungen** je Bestellung (`bestellung.erinnert_anzahl`). Danach legt der Cron einmal die dringende Aufgabe „Lieferant reagiert nicht: BE-…“ ans Team an (`bestellung.erinnert_aufgabe_am`).
- **Ausgeschlossen:** gesperrte Lieferanten und Lieferanten ohne E-Mail.
- **`erinnert_am` und der Zähler** werden nur gesetzt, wenn die Mail wirklich rausging (`ber_vermerken()`). Das gilt auch für den Knopf „Erinnern“ an der Bestellung (`bestellung_erinnerung_senden()`).
- **Sperre gegen Doppellauf:** `cron_sperre_holen()` / `cron_sperre_freigeben()` über app_meta `cron_sperre` (verfällt nach 15 Minuten).

## Funktionen
- `ber_schema()` – neue Spalten `bestellung.erinnert_anzahl` und `bestellung.erinnert_aufgabe_am`.
- `ber_faellig($tage)` – fällige Bestellungen je Lieferant, ohne Seiteneffekt.
- `ber_cron($tage)` – der tägliche Lauf. Rückgabe: `gesendet` (Mails), `bestellungen`, `geprueft`, `aufgaben`, `fehler`.
- `ber_aufgaben_nach_max()` – Aufgabe nach 3 Erinnerungen.
