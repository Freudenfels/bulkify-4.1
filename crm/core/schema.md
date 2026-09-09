# schema.php – die eigenen Tabellen

## Wozu
Legt die Tabellen des CRM an. **Alle** heißen `crm_...`, damit auf einen Blick klar ist, was uns gehört und was dem Dashboard. Dashboard-Tabellen werden hier nie angefasst – weder angelegt noch geändert.

Läuft wie im Dashboard bei jedem Aufruf und ist idempotent (`CREATE TABLE IF NOT EXISTS` + additive Spalten über `crm_spalte()`). Niemand muss ein Migrationsskript von Hand starten.

## Die Tabellen
| Tabelle | Wofür |
|---|---|
| `crm_kontakt` | Menschen und Firmen ohne Kundenkonto |
| `crm_verlauf` | jede Berührung eine Zeile |
| `crm_wiedervorlage` | „erinnere mich am …“ – hängt an einem Kontakt oder an einem Dashboard-Vorgang |
| `crm_termin` | Rückruf, Messe, Besuch – mit Uhrzeit |
| `crm_erledigt` | was in der Liste nicht mehr auftauchen soll (siehe `wartet.md`) |
| `crm_meta` | Einstellungen des CRM, z. B. die Adresse des Dashboards (`dashboard_url`) und der Token des Website-Eingangs (`lead_intake_token`, via `lead_intake_token()`) |

`crm_wiedervorlage.bezug_typ`/`bezug_id` zeigen wahlweise auf einen Kontakt oder auf einen Dashboard-Vorgang. Dorthin geschrieben wird **nichts** – die Wiedervorlage merkt sich nur, worum es geht.
