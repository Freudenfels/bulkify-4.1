# lieferant/unterlagen.php – Fehlende Unterlagen (`?p=lieferant_unterlagen`)

Aufgabenliste im Lieferantenportal (Paket 12.11.2026 Teil 4): „Diese Unterlagen fehlen noch“ je Anfrage bzw. Preis.
- je Rohstoff der angefragten Rezeptur bzw. je angefragtem Rohstoff: **CoA oder Spezifikation**
- bei Fremdfertigung, Premix und Bulk zusätzlich die **Spezifikation des Gesamtprodukts**
- Upload direkt in der Zeile; erledigte Punkte verschwinden, der Zähler im Menü sinkt.

Preise ohne Unterlagen sind gespeichert und tragen das Kennzeichen „Unterlagen fehlen“. Bestellen kann bulkify erst,
wenn alles da ist. Logik in `core/lieferant_unterlagen.php`.
