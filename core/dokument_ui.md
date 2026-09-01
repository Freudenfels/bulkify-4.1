# dokument_ui.php – Dokumente (CoA, Spezifikation, Analyse)

**Zweck:** Gemeinsame Komponente für Dokumente an **Rohstoffen** (`objekt_typ='item'`) und **Produkten** (`objekt_typ='produkt'`). Wird von `lager/rohstoff_detail.php`, `lager/verpackung_detail.php` und `produkt/detail.php` eingebunden.

**Funktionen:**
- `dokument_typen()` – die vier Typen: CoA (Analysenzertifikat), Spezifikation, Laboranalyse, Sonstiges.
- `dokument_upload($objekt_typ,$objekt_id)` – nimmt das Feld `dok` entgegen (plus `dok_typ`, `dok_lieferant`, `dok_titel`, `dok_kunde`), legt die Datei unter einem zufälligen Namen in `data/uploads` ab (**außerhalb** des Web-Ordners) und schreibt die Zeile in `dokument`.
- `dokument_delete($objekt_typ,$objekt_id,$dok_id)` – löscht Datei und Datensatz.
- `dokumente_fuer($objekt_typ,$objekt_id)` – alle Dokumente eines Objekts (intern, mit Lieferant).
- `dokument_panel($objekt_typ,$objekt_id,$lieferanten)` – rendert Liste + Upload-Formular.

## Freigabe fürs Kundenportal

`dokument.kunde_sichtbar` (Standard **0 = intern**) entscheidet, ob ein Dokument im Kundenportal auftaucht. Nichts ist automatisch sichtbar – am Rohstoff hängen auch **Lieferanten-Spezifikationen**, die man in der Regel nicht weitergeben darf.

- `dokumente_fuer_kunde($objekt_typ,$objekt_id)` – liefert nur Freigegebenes (fürs Portal).
- `dokument_freigabe_toggle($objekt_typ,$objekt_id,$dok_id)` – schaltet die Freigabe um; Aktion `dok_frei` in Rohstoff- und Produkt-Detail.
- Beim Hochladen setzt das Häkchen **„im Kundenportal sichtbar"** die Freigabe direkt; in der Liste zeigt die Spalte **„Im Kundenportal"** den Zustand als Badge (`freigegeben` / `intern`) und schaltet auf Klick um.

**Auslieferung:** intern über `?p=dokument&id=…` (nur mit Mitarbeiter-Login), im Portal über `?p=portal_dok&token=…&id=…` (`module/portal/dokument_download.php`). Die Portal-Route ist öffentlich und prüft deshalb selbst: gültiges Portal-Token, `kunde_sichtbar=1` **und** die passende Bereichs-Freischaltung des Kunden. Ohne alle drei kommt 403 bzw. 404.
