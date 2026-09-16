# produkt/pib.php – PIB ansehen (intern)

Kleiner Auslieferer für die Route `?p=produkt_pib&id=<produkt_id>`. Gibt das **Produktinformationsblatt** eines Produkts direkt aus (PDF/Bild) – ein vom Team hochgeladenes PIB hat Vorrang, sonst das automatisch erzeugte. Logik in `core/pdf_pib.php` (`pib_ausliefern()`). Rechteprüfung übernimmt der Front-Controller (interne Route). Genutzt vom Button „PIB ansehen" im Produktdetail.
