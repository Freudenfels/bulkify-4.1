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

## Charge + externe (Fremdlager-)Ware
- `dokument.charge_nr` speichert die auf dem Bericht genannte Chargennummer ausgeschrieben (KI liest sie vor;
  Mensch bestätigt). Wird im Admin, im Auftrag und im Kunden-Reiter „Labortest" angezeigt.
- Upload-Quelle „extern (Fremdlager / Drittanbieter)": Dropdown aus `lager2_produkte()` (Fertigware im
  Fremdlager, auch nicht von uns hergestellt). Verknüpfung wie sonst über `objekt_typ='produkt'`.
- `laboranalysen_fuer_kunde()` zeigt daher auch Produkte, die dem Kunden gehören (`produkt.kunde_id`) – nicht
  nur gekaufte –, damit externe Fremdlager-Ware ohne eigenen Auftrag beim Kunden erscheint.
- Neue Drittanbieter-Ware zuerst im Fremdlager einbuchen (`?p=lager2`), dann steht sie im „extern"-Dropdown.

## Abgleich mit dem System (Hinweise)
`laboranalyse_hinweise($ki, $pid_gewaehlt=0)` vergleicht die vom Bericht gelesenen Daten mit dem System und
gibt Hinweise (kein Blocker): kein eindeutiges Produkt erkannt; Produktname weicht ab; Chargennummer nicht im
System gefunden; Charge gehört laut System zu einem anderen Produkt. Anzeige im Vorschlag-Schritt der
Admin-Seite (gelber Kasten). Charge-Vergleich ist tolerant (Bindestrich/Leerzeichen/Gross-Klein egal).

## Charge -> Bestellung (direkte Kopplung)
`laboranalyse_auftraege_zu_charge($charge)` ermittelt über die Chargennummer (Produktionsauftrag -> Auftrag)
die konkrete(n) Bestellung(en) inkl. Kunde. Im Admin-Upload ist „Bestellung (aus Charge)" die bevorzugte
Zuordnung (`objekt_typ='auftrag'`), damit bei gleichem Produkt an mehrere Kunden nur die richtige Bestellung/der
richtige Kunde gekoppelt wird. Findet die Charge keinen Auftrag (z. B. Lagerproduktion), greift „Produkt (alle
Bestellungen)" oder „extern (Fremdlager)".

## Manuelle Kaskade: Produkt -> Auftrag -> Charge
Im Admin-Upload wählt man 1. Produkt, 2. Bestellung (gefiltert auf das Produkt; „alle Bestellungen" = am
Produkt hinterlegen), 3. Charge (Datalist der Chargen der Bestellung; die vom Bericht erkannte Charge ist
vorausgewählt, Freitext möglich). Abhängige Dropdowns clientseitig (LAB_ORDERS/LAB_CHARGES, kein Nachladen).
Bei gewähltem Auftrag -> `objekt_typ='auftrag'`, sonst `objekt_typ='produkt'`. KI füllt Produkt/Bestellung/Charge vor.

## Befund (bestanden/auffällig) + Menge im Dropdown
- KI beurteilt NUR die im Bericht geprüften Parameter: alle in Spezifikation -> `befund='bestanden'`, ein Wert
  außerhalb -> `auffaellig`, sonst `unklar`. Kein Urteil darüber, ob „genug" getestet wurde (keine Belehrung).
- `dokument.befund` speichert das (KI-Vorschlag, im Formular editierbar). Anzeige als Ampel-Badge (grün/rot,
  `laboranalyse_befund_label()`) im Admin, im Auftrag und im Kunden-Reiter „Labortest". `unklar`/leer = kein Badge.
- Produkt-Dropdown im Admin zeigt die Menge je Packung (z. B. „· 120 Kapseln"), außer die Variante hat sie schon im Namen.

## KI-Zuordnung: Kunde/Marke + Menge + aktive Bestellungen
Der KI-Vorschlag bekommt jetzt (a) die Produktliste MIT Menge je Packung und (b) die aktiven Bestellungen mit
Kunde, Marke (kunde_marke) und Menge. Er matcht primär auf die eindeutige Bestellung (auftrag_id) über
Kunde/Marke + Produkt + Menge und ordnet zusätzlich das Produkt zu (bei gleichnamigen entscheidet die Menge,
z. B. Astaxanthin 60 vs 90 Kapseln). Rückgabe zusätzlich: auftrag_id, kunde, menge. Die gematchte Bestellung
wird in der Kaskade (Produkt->Auftrag->Charge) vorausgewählt.

## Weitere Quellen + „ohne Zuordnung"
Der Admin-Upload kennt jetzt zusätzlich:
- **Rohstoff / Artikel (Lieferant)**: `objekt_typ='item'` (Rohstoffe + zugekaufte Fertigware), optional `lieferant_id`.
  So lassen sich Laboranalysen/CoA von Lieferanten zu Rohstoffen und Fertigprodukten annehmen (erscheinen auch am
  Rohstoff-Detail). Grosse Liste -> Live-Filter im Formular.
- **keine Zuordnung**: `objekt_typ='offen'`, `objekt_id=0`, `kunde_sichtbar=0` (nie im Kundenportal). Beim Speichern
  muss man das per Popup bestätigen; in der Liste erscheint das Badge „ohne Zuordnung" (Spalte Bezug) zum späteren Zuordnen.
`laboranalysen_alle()` löst Rohstoff/Artikel (item) + Lieferant mit auf.
