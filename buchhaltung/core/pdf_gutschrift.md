# pdf_gutschrift.php – PDF-Vorlage Gutschrift / Storno-Rechnung

`gutschrift_pdf_bauen(int $beleg_id): ?string` baut das PDF zu einem Beleg (typ=gutschrift) über `build_beleg_pdf`
(core/pdf_beleg.php) – gleiche Optik wie Angebot und Rechnung. Empfänger = Rechnungsadresse des Kunden (sonst Hauptadresse),
Positionen aus `beleg_positionen()`, Bezug „Storno zu Rechnung …" wenn `storno_von_id` gesetzt.
`gutschrift_pdf_ausliefern()` schickt es inline an den Browser.
