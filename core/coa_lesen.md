# coa_lesen.php – Werte aus einem Lieferanten-CoA vorschlagen

## Wozu
Aus dem Text eines Lieferanten-CoA (oder einer Spezifikation) die Analysenwerte **vorschlagen**. Bewusst „vorschlagen", nicht „übernehmen": Was hier herauskommt, landet im Formular und muss geprüft werden, bevor es gespeichert wird. Ein falscher Wert auf einem Analysenzertifikat, das an den Kunden geht, ist schlimmer als ein leeres Feld.

## Wie es liest
1. **Spaltenweise** – `pdf_text_extrahieren()` setzt Tabellenzeilen anhand der Textpositionen zusammen und trennt Spalten mit drei Leerzeichen. Wo das greift, ist die Zuordnung exakt: Parameter · Spezifikation · Ergebnis · Methode.
2. **Sonst geraten** – `coa_werte_trennen()`: Was eine Grenze nennt (min./max./≤/≥/NMT/NLT/Bereich), ist die Spezifikation; der letzte freistehende Wert ist das Ergebnis. Passt das nicht, bleibt das Feld leer.

Erkannt werden rund 19 Parameter mit deutschen und englischen Schreibweisen (Aussehen, Identität, Gehalt, Trocknungsverlust, pH, Asche, Partikelgröße, Schüttdichte, Blei/Cadmium/Quecksilber/Arsen, Schwermetalle, Keimzahl, Hefen/Schimmel, E. coli, Salmonellen …) sowie Methoden (HPLC, ICP-MS, Ph. Eur., USP …). Zusätzlich werden Kopfangaben gesucht: Chargennummer, MHD, Herstelldatum, Menge.

## Rückgabe
`['zeilen' => [[Parameter, Spezifikation, Ergebnis, Methode], …], 'kopf' => [...], 'text' => roh, 'lesbar' => bool]`.
`lesbar = false` heißt: aus dem PDF kommt kein Text (Scan) – dann sagt die Oberfläche das auch so.

## Wo es auftaucht
Rohstoff → Panel **Analysenwerte je Charge** → **Werte vorschlagen**. Der Vorschlag füllt das Formular; gespeichert wird erst mit **Analysenwerte speichern**.
