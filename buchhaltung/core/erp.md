# erp.php — DIE NAHT der Buchhaltung
Alle Zugriffe auf GETEILTE Dashboard-Tabellen gebündelt: benutzer (Login, nur Lesen: erp_benutzer*_), app_meta (meta_get/meta_set), nummernkreis (naechste_nummer/nummer_zurueckgeben — EINE Quelle), aktivitaet (log_aktivitaet). Finanz-eigene Tabellen stehen NICHT hier (schema.php/finanz.php). Wer eine geteilte Spalte umbenennt, prüft genau diese Datei. Muster wie produktion/core/erp.php.

## Rezeptur-Zugriffe (neu)
Für „Rezeptur verknüpfen" in der Rechnung: `erp_rezepturen()` (Liste für den Picker), `erp_rezeptur($id)` (eine Rezeptur), `erp_auftrag_rezeptur_id($auftrag_id)` (löst die Produkt-Rezeptur des Auftrags auf). `erp_auftrag_produkt_rezeptur_setzen($auftrag_id,$rezeptur_id)` ist der einzige Produkt-Write – trägt die Rezeptur am Produkt NUR nach, wenn dort noch keine hinterlegt ist (überschreibt nie).

## Auftrag-Import (neu, portiert)
`auftrag_import_ki` (KI liest Angebot/AB; nutzt core/ki.php), `verpackung_finden`, `rezeptur_finden_oder_anlegen`,
`produkt_aus_rezeptur`, `auftrag_aus_import` – aus dem Dashboard (core/schema.php) in die Naht portiert, weil die
Finanz-App das Dashboard-core nicht laden darf. Schreiben in geteilte Tabellen (rezeptur, rezeptur_zutat, produkt,
auftrag). EINZIGER Unterschied zur Dashboard-Fassung: `produkt_aus_rezeptur` erzeugt KEINE Preismatrix
(`produkt_matrix_generieren` = Dashboard-Preis-Engine) – die ist im Dashboard bei Bedarf nacherzeugbar. Für
importierte Alt-Aufträge genügt das Produkt. Genutzt von module/beleg/auftrag_import.php.
