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
| Angebot an den Kunden gesendet | Kunde, mit Portal-Link | `mail_kunde_angebot()` |
| Kunde nimmt Angebot an | Kunde (Auftragsbestätigung) + alle Admins | `mail_angebot_angenommen()` |
| Anfrage abgesagt („nicht machbar") | Kunde, mit Begründung | `mail_kunde_absage()` |
| Lieferant bestätigt Bestellung oder setzt Station | alle Admins | `mail_team_bestellung()` |
| Lieferant beantwortet Preisanfrage | alle Admins | `mail_team_preisanfrage()` |
| Neue Rückfrage/Antwort (`core/nachricht.php`) | die andere Seite: Lieferant (de/en) oder alle Admins | `mail_nachricht()` |

„Alle Admins" = aktive Benutzer mit Rolle `admin` ohne Lieferantenbindung (`mail_team()`).

## Links in Mails
`mail_basis_url()` nimmt die Einstellung `portal_url`, sonst den aktuellen Host. `mail_link_kundenportal($kunde_id, $ansicht)` baut den passwortlosen Portal-Link. Kunden werden auf Deutsch angeschrieben. Lieferanten nach `lieferanten.sprache` – **Deutsch, Englisch oder Chinesisch** (`mail_lief_sprache()`), also in derselben Sprache wie ihr Portal. Betreff und Text gehen UTF-8-kodiert raus, chinesische Zeichen kommen also sauber an. **PDFs bleiben de/en**, dafür fehlt eine eingebettete CJK-Schrift.
