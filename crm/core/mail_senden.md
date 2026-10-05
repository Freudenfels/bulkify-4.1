# crm/core/mail_senden.php – Antworten senden unter dem eigenen Mailkonto

**Zweck:** Jeder Mitarbeiter kann direkt aus dem CRM unter **seiner eigenen Adresse** antworten.

Die `benutzer`-Tabelle gehört dem Dashboard und wird nicht angefasst – das Mailkonto je Mitarbeiter
steht in der CRM-eigenen Tabelle **`crm_mitarbeiter`** (eintragbar unter *Einstellungen → Mein Mailkonto*):
Absender-Name/-Adresse, SMTP (Host/Port/Secure/User/Pass), Signatur.

## Funktionen
- `mitarbeiter_mail_konfig($benutzer_id)` – Konto lesen; `bereit` = Host + gültige Absender-Adresse da.
- `mitarbeiter_mail_speichern($benutzer_id, $post)` – Konto speichern (Passwort nur bei Eingabe überschrieben).
- `crm_mail_senden($benutzer_id, $to, $betreff, $text)` – senden; hängt die Signatur an. `''` = ok, sonst Klartext-Fehler.
- `crm_smtp_senden(...)` – minimaler Socket-SMTP (STARTTLS/SSL/AUTH LOGIN), übernommen aus `core/mail.php`.

## Verwendung
Auf der Kontakt-/Kundenseite hat „Antwort vorschlagen" einen **Senden**-Knopf (nur wenn ein Mailkonto
hinterlegt ist und der Vorgang eine E-Mail-Adresse hat). Senden ist eine bewusste Aktion (Rückfrage),
die gesendete Antwort landet im Verlauf. Reply-To = die eigene Adresse.
