# module/system/alt_rechnungen.php – Rechnungen nachtragen (TEMPORÄR)

Temporäres Admin-Werkzeug, um **originale Rechnungen/Dokumente zu (alten) Aufträgen** nachzutragen, damit
sie im Kundenportal erscheinen. Route `?p=alt_rechnungen`, Menü: System. Admin-only (Default-Gating).

## Ablauf
- Liste aller Aufträge (Suche live, tolerant gegen Bindestrich). „alt (v3)"-Badge = `auftrag.v3_id` gesetzt.
- „Dokumente…" öffnet ein Popup (`<dialog>`, per `?aid=` server-seitig gerendert und offen) mit allen Infos
  zum Auftrag, den bereits hochgeladenen Dokumenten (löschbar) und einem Upload-Formular (mehrere Dateien).
- Upload speichert je Datei eine `dokument`-Zeile: `objekt_typ='auftrag'`, `typ` ∈ rechnung/angebot/ab/sonstiges,
  `kunde_sichtbar=1`, optional Titel/Datum.

## Portal
Die hochgeladenen Dokumente erscheinen in der Bestell-Detailansicht (`v=bestellung`) im Panel „Dokumente"
unter „Hochgeladene Dokumente". Auslieferung über `v=auftrag_dok&id=<dok>` (ownership-geprüft: nur eigener
Auftrag, nur `kunde_sichtbar=1`). Da nur hier hochgeladen wird, betrifft das faktisch nur alte Aufträge.

## Später entfernen
Wie der v3-Import-Upload wieder rausnehmen: Route (`public/index.php`), Menüpunkt (`core/layout.php`), Datei.
Die Portal-Anzeige/`v=auftrag_dok` kann bleiben. Siehe Memory `alt-rechnungen-reiter-temporaer`.
