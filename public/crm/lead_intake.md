# public/crm/lead_intake.php – Website-Eingang (bulkify.pro → CRM-Lead)

**Zweck:** Nimmt Anfragen vom Webseiten-Formular (bulkify.pro) entgegen und legt daraus automatisch
einen **CRM-Kontakt** an (`crm_kontakt`, Quelle `website`, Phase `neu`). Ersetzt das v3-`lead_intake.php`.

**Aufruf:** `POST <host>/crm/lead_intake.php` – kein Login, direkt erreichbar (wie `public/ds_api.php`).
Token-gesichert: Feld `token` **oder** Header `X-Intake-Token`, verglichen mit `lead_intake_token()`
(`crm/core/schema.php`, gespeichert in `crm_meta`, neu erzeugbar über CRM → „Mehr").

**Grundsatz (wie v3):** Die Mail der Webseite ist führend. Der Eingang darf den Absender **nie** stören –
jeder interne Fehler wird still mit `200 {ok:false}` quittiert; nur ein falscher/fehlender Token blockt mit **403**.

**Eingabe:** JSON-Body **oder** form-encoded. Pflicht: mindestens eins von `name` / `email` / `telefon`.
- Kontaktfelder: `name, firma, email, telefon, whatsapp`
- Freitext (wird zu Notiz + Verlaufseintrag): `anliegen, ziel, produktform, menge, support, vertrieb,
  erfahrung, rohstoffe, wirkstoffe, rezeptur, rohstoff_extra, nachricht`

**Dublettencheck:** gleiche `email` oder (auf Ziffern normalisierte) `telefon` → hängt einen Verlaufseintrag
an den bestehenden Kontakt statt einen zweiten anzulegen. Mehrfaches Absenden erzeugt keine Karteileichen.

**Antwort:** `{"ok":true,"kontakt_id":<id>,"duplicate":true|false}` bzw. `{"ok":false,"error":...}`.

**Verwendet:** `kontakt_anlegen()` / `kontakt_verlauf()` (`crm/core/kontakt.php`), `crm_quellen()`
(`crm/core/ui.php`), `crm_schema()` + `lead_intake_token()` (`crm/core/schema.php`). Bewusst **ohne**
`dublette_suchen`/`erp.php`, damit der öffentliche Endpunkt schlank bleibt und die DB nur crm_-Tabellen berührt.

**Webseite anbinden:** Das Formular auf bulkify.pro (/bulkifyv2, nicht in diesem Repo) postet die Felder
zusätzlich zur bestehenden Mail an diese URL und schickt den Token mit. URL + Token stehen im CRM unter „Mehr".
