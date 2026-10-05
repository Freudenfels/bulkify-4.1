# versand/label.php – Versand-Label (PDF) ausgeben

Route `?p=versand_label&id=<versand_id>`. Streamt das vom Carrier erzeugte und gespeicherte Versand-Label
(PDF, aus `lg_versand_label`, `lg_versand_label()`). 404, wenn noch kein Label erzeugt wurde.
Erzeugt wird das Label über die Aktion „label" in [detail.md](detail.md) (`versand_label_erstellen()`).
