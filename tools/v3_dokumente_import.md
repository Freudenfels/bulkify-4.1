# tools/v3_dokumente_import.php – v3→v4 Dokumenten-Migration (Logik)

`v3_dok_import($v3dir, $commit=false)` übernimmt die v3-Dateien nach v4.
Quelle: `$v3dir/board.sqlite` + `$v3dir/uploads/`. Ziel: `dokument`-Zeilen + Kopie in `data/uploads`.

- `dateien` (je Auftrag, `gate`): rechnung→typ `rechnung` (kunde_sichtbar=1), etikett→`etikett`, coa/qualitaet→`analyse`,
  pib→`sonstiges`. Auftrag über `auftrag.v3_id`.
- `coa_dateien` (je Rohstoff): objekt_typ `item` über `item.v3_id`, typ coa/spec.
- `lieferant_zutat_doc`: CoA/Spec je Rohstoff (`item`), optional `lieferant_id` (falls `lieferanten.v3_id` existiert).

Idempotent über `dokument.v3_ref` (z. B. `dateien:123`). `$commit=false` = Trockenlauf. Nur wo Datei existiert und
das v4-Objekt gefunden wird. Aufgerufen von `module/system/v3_dok_import.php`.
