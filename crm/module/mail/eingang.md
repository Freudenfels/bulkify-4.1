# eingang.php – E-Mail-Eingang (Postfach)

Route `?p=eingang`. Zeigt die automatisch abgeholten Mails als Liste mit **KI-Vorschau**: Art
(Anfrage/Antwort/…), Absender, Betreff, Zusammenfassung und die vorgeschlagene **Zuordnung**
(bestehender Kunde/Kontakt oder neuer Kontakt) samt Wiedervorlage-Frist.

- **Postfach abrufen** (`tun=abrufen`) → `mail_abholen()`, holt neue Mails (per Redirect, damit ein
  Neuladen nicht erneut abruft). Läuft auch automatisch per Cron (`public/crm/mail_cron.php`).
- **Anlegen** (`tun=anlegen`) → `mail_eingang_anlegen()`: ein Klick macht daraus Kontakt/Notiz/
  Wiedervorlage bzw. hängt es an den bestehenden Kunden; danach direkt zu dessen Seite.
- **Verwerfen** (`tun=verwerfen`) → Mail still weglegen. Newsletter/Rechnungen werden als „kein Vorgang"
  markiert und nur zum Verwerfen angeboten.

Braucht die PHP-IMAP-Erweiterung und einen eingetragenen Zugang (Mehr → E-Mail-Eingang); fehlt eins,
sagt die Seite das. Die Zahl offener Mails steht als Badge am Menüpunkt. Logik: `crm/core/mail_abruf.php`.
