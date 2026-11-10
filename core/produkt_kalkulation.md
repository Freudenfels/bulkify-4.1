# core/produkt_kalkulation.php – Kosten, Beschaffung und Kunden eines Produkts (nur lesen)

Gehört zur Produktseite (`module/produkt/detail.php`, Umbau 09.11.2026). Hier wird **nichts gespeichert**.

## produkt_kalkulation($produkt_id, $packungen)
Kosten **je Packung**, aufgeschlüsselt, je **Bezugsweg** eine Spalte:
- **Zukauf fertig** (Kapsel/Tablette, bei Pulver: fertige Mischung): günstigster Lieferant aus den Zukaufpreisen am Produkt (`produkt_lieferant_preis`, auch Freitext-Lieferanten wie „Wellgreen") und den Fremdfertigungs-Angeboten an der Rezeptur (`rezeptur_lief_angebot`). Preis je Packung = Preis je Kapsel × Kapseln (bzw. €/kg × g).
- **Premix:** Premix-Preis je kg (Rezeptur-Angebot in kg oder Lieferantenpreis am Premix-Artikel) × Mischung je Packung + Leerkapseln.
- **Einzelrohstoffe:** je Zutat mg je Packung × günstigster Lieferantenpreis zur Gesamtmenge (`rohstoff_ek_detail`), sonst EK am Rohstoff; dazu Leerkapseln (bzw. Presshilfsstoffe, Grundmasse, Trägerflüssigkeit).
- **Verpackung** (für alle Wege gleich): Glas/Behälter, Deckel, Etikett (auch automatisch aus dem Glas) über die EK-Staffel (`pack_ek_bei_menge`), Umkarton anteilig (EK ÷ Packungen je Karton), Beipackzettel.
- **Herstellung:** es gibt keinen hinterlegten Satz – wird angezeigt, aber nicht gerechnet.

**Staffel nach Menge** wie im Auftrag: je Lieferant die größte Staffel bis zur Bedarfsmenge, darunter die kleinste (mit Hinweis Mindestmenge).
**Fehlt ein Preis**, steht `null` in der Zeile und der Weg ist `vollstaendig=false` – es wird nie still mit 0 gerechnet.
**Standard-Weg:** zuletzt im Produktionsauftrag des Produkts gewählter Weg, sonst Handelsware bzw. vollständiger Zukaufpreis → Zukauf, sonst Einzelrohstoffe.

Gleiche Bausteine wie der Beschaffungsweg am Auftrag (`auftrag_beschaffungswege`): `staffel_je_lieferant`, `rohstoff_ek_detail`, `rezeptur_premixitem`, `produkt_leerkapsel_id`, `pack_ek_bei_menge`.

## produkt_kosten_kurz($produkt_id, $packungen)
Summe des Standard-Wegs (für eine Kurzanzeige).

## produkt_rezeptur_ansicht($produkt_id)
Zusammensetzung je Rohstoff (mg je Einheit, welche Nährstoffe er liefert) und Tages-Deklaration (je Einheit, je Tag, % NRV). Pulver: Rezeptur = 1 Tagesdosis.

## produkt_beschaffung($produkt_id, $packungen)
Für den Reiter Beschaffung: Zukauf-Lieferanten mit allen Staffeln (Land, Portal-Account, Lieferzeit, Mindestbestellwert, Ansprechpartner, Dokumente), Rohstoffe der Rezeptur mit Lieferantenpreisen und Lagerbestand, Verpackung mit Lieferant/EK/Bestand/unterwegs, letzte Bestellungen (Rohstoffe, Verpackung, Bulk-/Premix-Artikel, Freitext-Positionen zu Aufträgen des Produkts), Preisanfragen mit Antwort.

## produkt_kunden($produkt_id)
Eine Zeile je Kunde aus Name beim Kunden, Kundenpreisen, Kundenetikett (Version, Design freigegeben, im Lager / unterwegs / Bedarf offene Aufträge), letztem Angebot und letzter Rechnung.

## kunde_name_voll($kunde_id, $firma)
Einheitliche Kundenanzeige auf der Produktseite: „Kürzel (Firma)", z. B. „FuturaYou (Bio Medical Company GmbH)". Ohne Kürzel nur die Firma. Das Kürzel kommt aus `kunden.kuerzel`; `kunde_link()` zeigt sonst nur das Kürzel, andere Stellen nur die Firma – daher bisher zwei Schreibweisen.

## Premix in der Beschaffung (10.11.2026)
`produkt_beschaffung()`: bei Kapsel/Tablette sind Rezeptur-Preise in kg Premix-Preise – eigene Gruppe je Lieferant (`premix=true`, Quelle „Premix (Rezeptur)“), nach den Stückpreisen sortiert, nie „günstigster“. Preisanfragen tragen `form` (Anzeige „Premix (je kg)“).

## Premix-Preise am Premix-Artikel (Teil 7)
`produkt_beschaffung()` nimmt Lieferantenpreise am Premix-Artikel der Rezeptur als eigene Premix-Gruppe auf (Quelle „Premix-Artikel“, Badge „Premix je kg“). Die Kalkulation rechnet den Premix-Weg mit Premix je kg × Füllgewicht + Leerkapseln. Sie las den Premix-Artikel schon vorher.
