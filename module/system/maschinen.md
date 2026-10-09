# system/maschinen.php – Maschinenfuhrpark (Dashboard-Verwaltung)

Route `?p=maschinen` (Menü **Produktion → Maschinen**; Rechte `production` + Admin, `core/auth.php`). Spec 9.3.
Verwaltet **Maschinen** und **Räume** zentral im Dashboard. Zwei Reiter (`?tab=maschinen|raeume`).

Arbeitet auf den (geteilten) Tabellen **`pr_maschine`/`pr_raum`** – dieselbe DB wie die Produktions-Sub-App.
Es wird bewusst NICHTS umbenannt/kopiert: so bleiben bestehende IDs, **QR-Codes `MA-<id>`** und
`prod_charge.maschine_id` gültig. Die Tabellen legt `init_schema()` (core/schema.php) jetzt ebenfalls idempotent an.

Helfer in `core/schema.php`: `maschinen_typen()`/`maschinentyp_label()`, `maschine_intervalle()`/`maschine_intervall_label()`,
`maschine_liste()`, `maschine_name()`, `maschine_neu()` (QR default `MA-<id>`), `maschine_setzen()`, `maschine_loeschen()`
(Soft-Delete `aktiv=0`), `raum_liste()`/`raum_neu()`/`raum_loeschen()`. Typ-Codes sind 1:1 wie in der Sub-App
(`pr_maschinen_typen`), sonst bricht die Stations-Zuordnung in der Produktion.

**Zuständigkeiten:** Bearbeiten NUR hier. Die Produktions-Sub-App (`produktion/module/betrieb/einstellungen.php`) ist
auf **nur ansehen** gestellt und verlinkt hierher. Die **Reinigung + harte Sperre** bleiben im Werk (Sub-App, `run.php`,
`pr_maschine_reinigung`), greifen weiter per `maschine_id`. `module/charge/detail.php` und der Produktionsbericht
(`module/produktion/_bericht_inhalt.php`) zeigen den Maschinennamen über `maschine_name()` bzw. den Join auf `pr_maschine`.
