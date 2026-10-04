# module/intern/belege_ansicht.php – Rechnungen (Nur-Ansicht im Dashboard)

Route `?p=rechnungen_ansicht` (Rollen sales/finance, admin). Schnelle **Nur-Lese**-Liste der Rechnungen
(und Gutschriften) direkt im Dashboard – ohne sich in der Buchhaltung anzumelden.

## Warum
Die Buchhaltung ist ein **eigenes, abgeschottetes Programm** (`/buchhaltung/`). Beide greifen aber auf
dieselbe Tabelle **`beleg`** (gleiche DB) zu, darum kann das Dashboard hier einfach **mitlesen**.
Rein lesend: Erstellen, Zahlungen, GoBD-Export bleiben in der Buchhaltung.

## Was
Liste aus `beleg` (`typ IN ('rechnung','gutschrift')`) + Kundenname (`kunden.firma`): Nummer, Datum,
Kunde, Typ, Netto, Brutto, Status. Suche über Nummer/Kunde, neueste 500. Pro Zeile „öffnen" →
`buchhaltung/?p=rechnung&id=…` (öffnet die Buchhaltung, eigener Login).

## Grenze
Keine Schreibaktionen hier. Liegt bewusst NICHT in `$FINANZ_ROUTEN` (public/index.php), damit die Route
nicht nach `/buchhaltung/` umgeleitet wird. Angebote sind ohnehin im Dashboard (`?p=angebote`).
