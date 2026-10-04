# Buchhaltung – E-Rechnung-Download (`rechnung_xml.php`)

Route `rechnung_xml` (Rolle `finance`). `?id=<beleg>` liefert die Rechnung/Gutschrift als
EN16931-konformes CII-XML (ZUGFeRD-Profil) zum Download. Erzeugung in
`core/erechnung.php` (`erechnung_cii_xml()`). Verlinkt aus der Rechnungs-Detailansicht
(`module/beleg/detail.php`, Button „E-Rechnung (XML)").
