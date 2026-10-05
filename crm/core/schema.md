# schema.php – die eigenen Tabellen

## Wozu
Legt die Tabellen des CRM an. **Alle** heißen `crm_...`, damit auf einen Blick klar ist, was uns gehört und was dem Dashboard. Dashboard-Tabellen werden hier nie angefasst – weder angelegt noch geändert.

Läuft wie im Dashboard bei jedem Aufruf und ist idempotent (`CREATE TABLE IF NOT EXISTS` + additive Spalten über `crm_spalte()`). Niemand muss ein Migrationsskript von Hand starten.

## Die Tabellen
| Tabelle | Wofür |
|---|---|
| `crm_kontakt` | Menschen und Firmen ohne Kundenkonto. Spalten u. a. `phase`/`phase_at` (Pipeline), `besitzer_id` (Zuständig), Segmentierung (`nische`, `volumen`, `prioritaet`, `kontaktart`, `erfahrung`, `zielmarkt`, `firmentyp`, `land`, `website`, `moeglichkeiten`, `besonderheiten`), strukturierte Anfrage (`anfrage_rezeptur/form/inhalt/vorhaben`) und `ki_ausgewertet` (siehe `lead_ki.md`) |
| `crm_kontakt_datei` | Dokumente am Kontakt (Angebot/Abschluss/Rechnung/Sonstiges); Datei in `data/kontakt_datei`, Download über `public/crm/kontakt_doc.php` |
| `crm_mail_eingang` | automatisch abgeholte Mails (E-Mail-Eingang) mit KI-Vorschau; Status `neu/angelegt/verworfen`, dedupe über `message_id`. Siehe `mail_abruf.md` |
| `crm_rezeptur_ki` | KI-Rezepturvorschlag je Kontakt/Kunde (JSON), wie `crm_briefing`. Siehe `rezeptur_ki.md` |
| `crm_todo` | abhakbare Aufgaben (Kategorie, optional Bezug kontakt/kunde, Fälligkeit, quelle manuell/ki/mail). Siehe `todo.md` |
| `crm_mitarbeiter` | eigenes Mailkonto je Benutzer (SMTP + Signatur), für den Versand aus dem CRM. Siehe `mail_senden.md` |
| `crm_verlauf` | jede Berührung eine Zeile |
| `crm_wiedervorlage` | „erinnere mich am …“ – hängt an einem Kontakt oder an einem Dashboard-Vorgang |
| `crm_termin` | Rückruf, Messe, Besuch – mit Uhrzeit |
| `crm_erledigt` | was in der Liste nicht mehr auftauchen soll (siehe `wartet.md`) |
| `crm_meta` | Einstellungen des CRM, z. B. die Adresse des Dashboards (`dashboard_url`) und der Token des Website-Eingangs (`lead_intake_token`, via `lead_intake_token()`) |

`crm_wiedervorlage.bezug_typ`/`bezug_id` zeigen wahlweise auf einen Kontakt oder auf einen Dashboard-Vorgang. Dorthin geschrieben wird **nichts** – die Wiedervorlage merkt sich nur, worum es geht.
