# lief_aufkleber.php – Lieferanten-Gebindeaufkleber (Paket 12.11.2026 Teil 5)

Plan: `plan-lieferant-gebindeaufkleber.md` (freigegeben). Läuft im Dashboard **und** in der Lager-App (nur q/one/all/scalar,
nie core/schema.php).

- Tabelle `lief_aufkleber`: je gedrucktem Aufkleber Bestellung, Position, Lieferanten-Charge (`bestellung_charge`),
  Netto/Brutto (kg), Stück, Status `aktiv | storniert | erfasst`, beim Scan `charge_id`, `gebinde_id`, `erfasst_am/_von`.
- Neue Spalten: `bestellung_charge.herstell_datum`, `bestellung.aufkleber_abschluss`, `item.aufkleber_lagerung`
  (Shelf life/Storage am Artikel, sonst Standard). Paketzahl: vorhandene Spalte `bestellung.pakete_angekuendigt`.
- QR/Code: `la_code($id)` = `BX<id>-<5 Zeichen HMAC>` (Schlüssel `app_meta la_hmac_key`), `la_code_id($code)` prüft.
- `la_anlegen`, `la_stornieren`, `la_positionen`, `la_aktive`, `la_stand` (gedruckt/erfasst/netto/fehlt), `la_daten` (Inhalt
  fürs PDF), `la_ship_to` (Einstellung `firma_prod_adresse`, Standard Barßel), `la_versand_hinweis`.
