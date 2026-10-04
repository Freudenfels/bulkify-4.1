# Buchhaltung – Finanz-Hub (`hub.php`)

Landeseite des Buchhaltungs-Bereichs. Route `buchhaltung` (Menü „Buchhaltung → Belege"),
nur Rolle `finance`. Vorher lief der Menüpunkt ins Leere (Route war in `auth.php` erlaubt,
aber in `public/index.php` nicht gemappt → 404). Diese Seite füllt die Lücke.

## Was zeigt die Seite?
- **Kennzahl-Kacheln:**
  - Offene Posten – Brutto minus Zahlungseingänge über alle nicht stornierten Rechnungen
    (erfasst auch Teilzahlungen korrekt).
  - Davon überfällig – offener Rest, bei dem `faellig < heute`.
  - Offene Rechnungen – Anzahl (Status `offen`/`teilbezahlt`).
  - Umsatz Jahr / Monat – Netto gestellter Rechnungen minus Gutschriften, ohne Storno.
  - Gutschriften – Anzahl.
- **Schnellaktionen:** Links zu Rechnungsliste, Storno-Rechnung, Alt-/Auftrags-Import, „+ Rechnung erstellen".
- **Zwei Panels:** Überfällige Rechnungen (älteste zuerst, mit Tagen über Fälligkeit) und Zuletzt bezahlt.

## Datenquellen
- `beleg` (`typ`, `status`, `netto`, `brutto`, `datum`, `faellig`, `kunde_id`), `zahlung` (`beleg_id`, `betrag`),
  `kunden` (`firma`). Geld in `beleg` ist DECIMAL(14,2) in Euro – direkt formatiert, nicht in Cent.
- `beleg.status` wird bei Zahlungseingang fortgeschrieben (`beleg_zahlstatus()` in `core/schema.php`),
  darum sind die Status-basierten Zählungen verlässlich.

## Grenzen / Ausbau
Reiner Überblick (read-only). Zielbild (siehe `BUCHHALTUNG.md`): weitere Reiter
(Kunden/Finanz/Auswertung), DATEV-/OP-Export, E-Rechnung. Hier bewusst noch nicht gebaut.
