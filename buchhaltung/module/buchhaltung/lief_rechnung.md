# Eingangsrechnung – Detail (`lief_rechnung.php`)

Route `lief_rechnung` (Rolle `finance`). `?id=<lieferant_rechnung>`. Zeigt Beträge und Zahlstatus
(Netto/VSt/Brutto, bereits bezahlt, offen, Fälligkeit mit Überfällig-Markierung) und die Zahlungen.

Aktionen (POST): `zahlung` (Teil-/Restzahlung buchen – Status wird fortgeschrieben),
`kopf` (Kopf ändern, nur solange nicht storniert), `storno` (stornieren). Logik in `core/kreditor.php`.

## Original-Rechnung (neu)
Detail zeigt die angehängte Original-Rechnung („ansehen", ausgeliefert via ?datei=1 nach Login) und erlaubt Hochladen/Ersetzen (aktion datei_upload -> kr_datei_setzen).

**Zahlen-Lesart (13.11.2026, Fable-3 T2.9):** Betragsfelder lesen mit `betrag_lesen()`, Preise/Prozente/Kurse mit `zahl_lesen()`, Mengen mit `zahl_lesen(…, true)` – alle aus core/zahl_lesen.php (eine Regel mit dem Dashboard). „21.687,75“, „21687,75“ und „21687.75“ ergeben 21687,75.
