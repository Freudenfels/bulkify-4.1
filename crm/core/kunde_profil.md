# crm/core/kunde_profil.php – Kundenprofil + Vorgangs-Timeline

**Zweck:** Zwei Dinge für die Kunden-Multiansicht (Cockpit):

## Profil (CRM-Sicht)
`kunde_profil_lesen($kunde_id)` / `kunde_profil_speichern($kunde_id, $post)` – die CRM-eigenen Infos zu
einem Dashboard-Kunden: Qualifizierung (dieselben Felder wie beim Kontakt, `crm_segfelder()`) plus
freie **Infos**. Gespeichert in `crm_kunde_profil` (die Dashboard-`kunden`-Tabelle bleibt unangetastet –
Stammdaten werden nur gelesen und im Dashboard bearbeitet).

## Timeline
`kunde_timeline($kunde_id)` baut EINE zeitliche Liste aller Vorgänge: **Angebote, Aufträge, Rechnungen,
Rezepturen** – gelesen über die `erp.php`-Naht (`erp_angebote_/auftraege_/rechnungen_/rezepturen_fuer_kunde`).
Je Eintrag: Datum, Art, Titel, Status, Betrag und ein **Link ins Dashboard** (Direkt-Sprung). Neueste zuerst.

Beides wird in `crm/module/kunde/detail.php` angezeigt (Reiter „Übersicht" = Timeline + CRM-Werkzeuge,
Reiter „Profil" = Stammdaten + CRM-Profil).
