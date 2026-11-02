# mail.php – E-Mail-Versand

## Wozu
Verschickt Mails über **SMTP** – ohne Composer, ohne PHPMailer, wie in v3. Eingerichtet wird alles unter **Einstellungen → E-Mail** (z. B. die Zugangsdaten von United Domains); dort steht auch der **Testversand**.

Jede Mail wird zusätzlich nach `data/mail.log` geschrieben. Damit ist nachvollziehbar, was rausging – auch wenn der Versand gerade klemmt.

## Funktionen
- `mail_config()` – die Einstellungen (Host, Port, Verschlüsselung, Benutzer, Passwort, Absender, HELO).
- `mail_bereit()` – ist der Versand einsatzbereit? Steuert Knöpfe und Hinweise in der Oberfläche.
- `mail_senden($to, $betreff, $text)` – `''` = verschickt, sonst der Grund im Klartext. Wirft nie: ein fehlgeschlagener Versand darf keinen Vorgang abbrechen.
- `smtp_senden()` – der eigentliche Versand über einen Socket. STARTTLS (587), implizites TLS (465) und AUTH LOGIN.
- Vorlagen: `mail_lieferant_einladung()`, `mail_lieferant_bestellung()`, `mail_team()`.

## Sprache
Die Vorlagen an Lieferanten richten sich nach `lieferanten.sprache` (Deutsch, sonst Englisch).

## Welche Ereignisse eine Mail auslösen
Alle nur, wenn der Versand eingerichtet und eingeschaltet ist (`mail_bereit()`). Ein Mailfehler bricht nie den Vorgang ab; wo möglich zeigt die Seite den Grund.

| Ereignis | Wer bekommt die Mail | Vorlage |
|---|---|---|
| Lieferant einladen | Lieferant (de/en/zh) | `mail_lieferant_einladung()` |
| Bestellung erteilt („als bestellt markieren" oder Einkaufsliste mit Bestelldatum) | Lieferant (de/en/zh) | `mail_lieferant_bestellung()` |
| Kunde stellt eine Anfrage (Rezeptur/Produkt/Rohstoff/Dienstleistung) | Kunde (Eingangsbestätigung) | `mail_kunde_anfrage_eingang()` |
| Angebot an den Kunden gesendet | Kunde, mit Portal-Link | `mail_kunde_angebot()` |
| Kunde nimmt Angebot an (Staffel, Matrix-Zelle oder Positionen) | Kunde (Auftragsbestätigung) + alle Admins | `mail_angebot_angenommen()` |
| Anfrage abgesagt („nicht machbar") | Kunde, mit Begründung | `mail_kunde_absage()` |
| Lieferant bestätigt Bestellung oder setzt Station | alle Admins | `mail_team_bestellung()` |
| Lieferant beantwortet Preisanfrage | alle Admins | `mail_team_preisanfrage()` |
| Kunde lädt ein Etikett-Design hoch (Portal) | alle Admins – Etiketten können bestellt werden | `mail_team_etikett_hochgeladen()` |
| Neue Rückfrage/Antwort (`core/nachricht.php`) | die andere Seite: Lieferant (de/en) oder alle Admins | `mail_nachricht()` |

„Alle Admins" = aktive Benutzer mit Rolle `admin` ohne Lieferantenbindung (`mail_team()`).

## Editierbare Kunden-Texte (Einstellungen → E-Mail-Texte)
Der Wortlaut der vier Kunden-Mails lässt sich anpassen; solange nichts hinterlegt ist, gilt der Standardtext im Code (Verhalten dann identisch zu früher).
- `mail_vorlagen()` – Registry: je Schlüssel `titel`, `beschreibung`, Standard-`betreff`/`text` und die erlaubten `platzhalter` (Name → Erklärung). Schlüssel: `anfrage_eingang`, `angebot`, `auftrag`, `absage`.
- `mail_vorlage($key)` – effektiver `['betreff','text']`: gespeicherte Fassung aus `app_meta` (`mailtpl_<key>_betreff` / `_text`), sonst Standard. **Leerer gespeicherter Wert = Standard** (so wirkt „Feld leeren + speichern" bzw. „Zurücksetzen").
- `mail_render($key, $vars)` – ersetzt Platzhalter `{name}` durch die Werte und gibt `['betreff','text']` zurück.
- `mail_kunde_anfrage_eingang($quelle, $id)` – Eingangsbestätigung; `$quelle` = `'portal'` (portal_anfrage) oder `'rezeptur'` (rezeptur_anfrage).

Die Mails an **Lieferanten** (mehrsprachig) und die internen **Team**-Hinweise sind bewusst nicht editierbar – sie hängen an Sprache/Logik.

## Links in Mails
`mail_basis_url()` nimmt die Einstellung `portal_url`, sonst den aktuellen Host. `mail_link_kundenportal($kunde_id, $ansicht)` baut den passwortlosen Portal-Link. Kunden werden auf Deutsch angeschrieben. Lieferanten nach `lieferanten.sprache` – **Deutsch, Englisch oder Chinesisch** (`mail_lief_sprache()`), also in derselben Sprache wie ihr Portal. Betreff und Text gehen UTF-8-kodiert raus, chinesische Zeichen kommen also sauber an. **PDFs bleiben de/en**, dafür fehlt eine eingebettete CJK-Schrift.

## Vorlage Preisanfrage
`mail_lieferant_anfrage($anfrage_id)` schickt eine Preisanfrage an den Lieferanten (Sprache je `lieferanten.sprache`, mit Link ins Portal). Wird von der zentralen Route `?p=preis_anfragen` genutzt, sobald der Versand eingerichtet ist.

## Zusätzliche Benachrichtigungs-Adressen (CC)
`mail_team()` geht an alle aktiven Admin-Benutzer (ohne Lieferantenbindung) PLUS an die Adressen aus
`app_meta.benachrichtigung_cc` (komma-/semikolon-/zeilengetrennt, nur gültige Adressen, dedupliziert) –
siehe `mail_team_cc_adressen()`. So bekommen Team-Mitglieder ohne Admin-Login (z. B. Tabea
albers@bulkify.pro) ALLE Team-Benachrichtigungen per Mail: Lieferanten-Rückfragen, beantwortete
Preisanfragen, angenommene Angebote, Etikett-Uploads, neue Bestellungen. Pflege in Einstellungen →
E-Mail → „Zusätzliche Benachrichtigungs-Adressen". Beim ersten Start wird der Schlüssel einmalig mit
`albers@bulkify.pro` vorbelegt (danach frei änderbar; leeren = niemand extra).

## Keine Vorbelegung der CC-Adresse (Paket Mails)
`init_schema()` belegt `benachrichtigung_cc` nicht mehr fest mit albers@bulkify.pro vor – sonst bekäme Tabea von jeder Test-/Entwicklerinstallation mit echtem SMTP Testmails. Live ist der Wert gespeichert und bleibt; neue Installationen pflegen ihn in den Einstellungen.

## Korrekturen (Paket Mails)
- **Doppelversand-Sperre:** `mail_team()` verschickt dieselbe Team-Mail (gleicher Betreff) innerhalb von 2 Minuten nur einmal (ein Schlüssel `app_meta mail_team_zuletzt` mit den Betreff-Hashes der letzten 2 Minuten); die Dashboard-Benachrichtigung entsteht trotzdem.
- **Etikett freigegeben:** eigene Team-Mail `mail_team_etikett_freigegeben()` („Etikett freigegeben: AB-…") statt erneut „Etikett-Design hochgeladen"; dazu Bestätigung an den Kunden `mail_kunde_etikett_freigegeben()` (wer, wann, Link auf die Bestellung).
- **DL-Angebot:** `mail_kunde_angebot()` verlinkt bei Dienstleistungen auf „Meine Anfragen" (dort steht das DL-Angebot), sonst „Angebote".
- **Auftragsbestätigung:** Vorlage `auftrag` erwähnt die Rechnung nur noch, wenn sie für den Kunden freigegeben ist (Platzhalter `{rechnung_hinweis}`). Ohne entstandenen Auftrag geht **keine** Auftragsbestätigung raus (vorher „Auftragsbestätigung  von …" ohne Nummer).
- **Annahme-Mail ans Team:** nennt, was wirklich angelegt wurde (Auftrag/DL-Auftrag, Rechnung ggf. als Entwurf, Produktionsauftrag nur wenn vorhanden) und ob das Team für den Kunden angenommen hat; ohne Auftrag „Achtung: Es ist KEIN Auftrag entstanden". Vorher stand pauschal „Rechnung und Produktionsauftrag ebenfalls" (falsch bei DL/Handelsware).
- Helfer: `mail_kunde_einfach($kunde_id, $betreff, $kern, $ansicht)` (Abschaltung, Adresse, Anrede, Portal-Link, Gruß), `mail_zeit($utc)` (Ortszeit).

## Neue Kunden-Mails (Paket Mails)
Alle über `mail_kunde_einfach()` (Abschaltung je Kunde, Adresse, Anrede, Portal-Link), Produktnamen wie der Kunde sie kennt, Beträge deutsch:
| Funktion | Auslöser | Link |
|---|---|---|
| `mail_kunde_rezepturvorschlag($rid)` | Vorschlag aus Anfrage erstellt/aktualisiert, Rezeptur auf „Vorschlag" gesetzt | `v=rezeptur&rid=` |
| `mail_kunde_versand($aid,$ls,$menge,$voll)` | `auftrag_versenden_menge()` – Lieferung/Teil-/Restlieferung mit LS-Nummer, offener Rest, Rechnung (falls freigegeben) | `v=bestellung&aid=` |
| `mail_kunde_fremdlager($aid)` | `auftrag_ins_fremdlager()` – Ware liegt im Kundenlager | `v=fremdprodukte` |
| `mail_kunde_rechnung_freigegeben($beleg)` | Buchhaltung „Für Kunde freigeben", Auftrag „Rechnung neu berechnen" (wenn dabei freigegeben) | `v=rechnungen` |
| `mail_nachbestellung($nb,'eingang'\|'preis_neu'\|'bestaetigt')` | Nachbestellung angelegt (Kunde **und Team**), neuer Preis zur Freigabe, bestätigt (AB mit Nummer) | `v=bestellungen` / `v=bestellung&aid=` |
| `mail_kunde_angebot_aktualisiert($an)` | Angebot: „Preise freigeben" bei gesendetem Angebot | `v=angebote` / `v=meine_anfragen` (DL) |
| `mail_kunde_zugang($kid)` | Kundenkonto: Knopf „Per Mail senden" am Erstzugang | Portal |
| `mail_kunde_etikett_freigegeben($aid)` | Etikett-Freigabe im Portal | `v=bestellung&aid=` |
Bei Lieferung (`auftrag_rechnung_bei_lieferung`) geht keine eigene Rechnungsmail raus – die Versand-/Fremdlager-Mail nennt die Rechnung mit.
