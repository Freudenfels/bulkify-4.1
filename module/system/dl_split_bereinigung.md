# system/dl_split_bereinigung.php — Material-Split-Bereinigung (einmalig, Admin)

Route `?p=dl_split_bereinigung` (nur Admin). Entfernt DL-Aufträge, die durch die **alte** `dl_auftrag_aus_angebot`-Logik fälschlich als eigener Auftrag je Angebotsposition entstanden sind – auch für reine Material-/Zubehörpositionen (Standbodenbeutel, Glas, Deckel, Etikett). Richtig (neue Logik, `dl_positionen_gruppieren`): Material hängt am Service-Auftrag seiner Gruppe und wird über dessen DR-Rechnung mitabgerechnet.

**Kandidat** = `kategorie='dienstleistung'`, Status ≠ storniert, **keine** `dienstleistung_id`, **keine** `dl_auftrag_schritt`, und aus einem Angebot, das noch einen **echten** Service-Auftrag (`dienstleistung_id>0`) hat (= Split, kein Einzelauftrag). Material-Bezeichnung nur zur Anzeige (Position per Preis+Menge zugeordnet).

**Sicherheit:** Vorschau per Default, Löschen nur auf Klick. Kandidaten mit eigener (nicht stornierter) Rechnung werden **nicht** gelöscht, sondern markiert (erst Buchhaltung klären). Gelöscht wird gezielt je ID über `auftrag_komplett_loeschen()` (räumt evtl. Produktion/Charge/Beleg mit auf, löst Lieferantenbestellungen nur). Angebot + Positionen bleiben erhalten. Idempotent.

Voraussetzung: Die einmalige Service-Heilung (`dl_auftraege_service_backfill`, läuft beim Deploy) hat zuvor echten Service-Aufträgen ihre `dienstleistung_id` zurückgegeben – sonst würden auch sie als „ohne Service" erscheinen.
