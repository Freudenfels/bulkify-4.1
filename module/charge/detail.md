# charge/detail.php – Produktionscharge Detail (CH/CHE)

Detail einer Produktionscharge (Spec 7.5 + 16). Route `?p=produktionscharge&id=`.

- **Kopfdaten:** Nr, Typ (CH/CHE), Status, Produktionsauftrag, Rezeptur, Produkt, Menge/Gebinde, Mitarbeiter, Maschine (best-effort aus der Produktions-Sub-App `pr_maschine`), Produktionstag, Angelegt, Notiz.
- **Unterchargen** (`prod_charge` mit `parent_id`): je Gebinde/Tag/Mitarbeiter eine (-A/-B/-C), verlinkt.
- **Eingesetzte Rohstoffe/Batches** (`prod_charge_rohstoff`): Rückverfolgung **rückwärts**; je Batch ein Link „Vorwärts verfolgen" (öffnet die Liste gefiltert auf die Batch-Nr → alle Chargen, in die der Batch floss). Rohstoffe haben KEINE eigene Nummer: `batch_nr` = Hersteller-Batchnummer (Regressgrundlage).

Daten via `prod_charge_voll()` (core/schema.php). Read-only.
