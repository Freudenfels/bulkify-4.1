# core/laboranalyse.php – Laboranalysen (Labortests / CoA)

Hilfsfunktionen rund um Laborberichte / Analysenzertifikate fertiger Produkte.

## Ablage
Laboranalysen liegen in der generischen Tabelle `dokument` mit `typ='analyse'`.
Verknüpfung über `objekt_typ`:
- `produkt` → gilt für **alle** Bestellungen des Kunden mit diesem Produkt.
- `auftrag` → genau **diese** Bestellung (Charge). Upload im Auftrag (`module/auftrag/detail.php`).

Sichtbar im Kundenportal nur mit `dokument.kunde_sichtbar=1`.
`dokument.dok_datum` = Datum des Berichts (Analysendatum) für die Sortierung; leer → `angelegt`.

## Funktionen
- `laboranalyse_ki_vorschlag($pfad)` – KI liest den Bericht und schlägt Produkt + Analysendatum (+ Charge) vor.
  Nur **Vorschlag**, der Mensch bestätigt. Läuft nur auf beta (KI-Schlüssel serverseitig).
- `laboranalysen_fuer_kunde($kunde_id)` – alle freigegebenen Analysen zu gekauften Produkten und zu eigenen
  Bestellungen; je Zeile Datum, Produkt, Auftrag/Charge, Datei. Basis für den Portal-Reiter „Labortest".
- `laboranalysen_alle($suche)` – Admin-Überblick über alle Analysen (Produkt/Kunde/Bezug aufgelöst).

## Wer nutzt das
- `module/lager/laboranalysen.php` (Admin-Reiter: Upload + KI-Vorschlag + Verknüpfung + Liste)
- `module/auftrag/detail.php` (Upload je Bestellung/Charge)
- `module/portal/kunde.php` (Kunden-Reiter „Labortest" + Auslieferung `v=analyse_datei`, ownership-geprüft)
