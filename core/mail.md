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

## Was noch fehlt
Benachrichtigungen laufen bisher nur für die **Einladung**. Bestellung, Angebotsannahme und Anfrage-Antwort verschicken noch nichts automatisch – die Vorlagen dafür stehen bereit.
