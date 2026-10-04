# Eingangsrechnung – Detail (`lief_rechnung.php`)

Route `lief_rechnung` (Rolle `finance`). `?id=<lieferant_rechnung>`. Zeigt Beträge und Zahlstatus
(Netto/VSt/Brutto, bereits bezahlt, offen, Fälligkeit mit Überfällig-Markierung) und die Zahlungen.

Aktionen (POST): `zahlung` (Teil-/Restzahlung buchen – Status wird fortgeschrieben),
`kopf` (Kopf ändern, nur solange nicht storniert), `storno` (stornieren). Logik in `core/kreditor.php`.
