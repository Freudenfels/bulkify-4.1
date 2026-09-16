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
