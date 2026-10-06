# erp.php — DIE NAHT der Buchhaltung
Alle Zugriffe auf GETEILTE Dashboard-Tabellen gebündelt: benutzer (Login, nur Lesen: erp_benutzer*_), app_meta (meta_get/meta_set), nummernkreis (naechste_nummer/nummer_zurueckgeben — EINE Quelle), aktivitaet (log_aktivitaet). Finanz-eigene Tabellen stehen NICHT hier (schema.php/finanz.php). Wer eine geteilte Spalte umbenennt, prüft genau diese Datei. Muster wie produktion/core/erp.php.

## Rezeptur-Zugriffe (neu)
Für „Rezeptur verknüpfen" in der Rechnung: `erp_rezepturen()` (Liste für den Picker), `erp_rezeptur($id)` (eine Rezeptur), `erp_auftrag_rezeptur_id($auftrag_id)` (löst die Produkt-Rezeptur des Auftrags auf). `erp_auftrag_produkt_rezeptur_setzen($auftrag_id,$rezeptur_id)` ist der einzige Produkt-Write – trägt die Rezeptur am Produkt NUR nach, wenn dort noch keine hinterlegt ist (überschreibt nie).
