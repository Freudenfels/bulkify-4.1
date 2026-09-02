# lieferant/katalog.php – „Mein Katalog" im Lieferantenportal

**Route:** `?p=lieferant_katalog` (nur für angemeldete Lieferanten).

**Wozu:** Der Lieferant zeigt, was er anbietet – ohne dass wir ihn erst anfragen müssen.

**Zwei Wege:**
- **Liste hochladen** (`aktion=liste_hoch`): PDF, Bild oder CSV, auch als Scan. Die Datei landet zuerst in der Dateiablage (damit sie nachvollziehbar bleibt), dann liest die KI sie aus (`katalog_einlesen()`) und legt je Artikel eine Zeile an.
- **Von Hand eintragen** (`aktion=zeile_neu`): Bezeichnung, Typ, Form, Spezifikation, Herkunft, Preis, Währung, Einheit, ab Menge, Notiz.

Offene Zeilen kann der Lieferant selbst wieder löschen (`aktion=zeile_weg`); übernommene nicht mehr. Der Status je Zeile zeigt ihm, ob wir sie schon geprüft haben.

**Wichtig:** Aus einer Zeile wird **kein** Artikel bei uns. Das entscheidet das Team im Lieferantenkonto, Reiter Katalog. Zahlen werden in der Schreibweise des Lieferanten gelesen (`zahl_lesen()` mit seiner Sprache).
