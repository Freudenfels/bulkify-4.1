# core/mail_protokoll.php – Mail-Protokoll und Mail-Bremse

Eingeführt mit Fable-6 T0.4/T0.5 am 13.11.2026. Anlass: Am 13.11.2026 abends hat united-domains den Versand vom Webspace gesperrt (Connection refused auf 587 und 465).

- **Protokoll:** Jeder Versand-Versuch aus `mail_senden()` / `mail_senden_jetzt()` steht in der Tabelle `mail_protokoll` mit Zeit (UTC), Empfänger, Betreff, Status, Fehler und auslösender Seite. Mögliche Status:
  - `gesendet`
  - `fehler`
  - `warteschlange`
  - `versuch` (läuft gerade)
  - `aus` (Versand ausgeschaltet bzw. nicht eingerichtet)
  - `test` (Testumgebung)

  `data/mail.log` wird weiter geschrieben.
- **Bremse:** höchstens `MP_PRO_MINUTE` = 20 Versuche je 60 Sekunden. Darüber legt `mail_senden()` die Mail in die Tabelle `mail_warteschlange` (Anhänge als base64) und gibt `''` zurück. Für den Aufrufer gilt die Mail damit als angenommen.
- **Abarbeiten:** `mail_warteschlange_abarbeiten()` sendet der Reihe nach, so weit die Bremse es zulässt. Aufgerufen wird es an drei Stellen:
  - nach der Antwort an den Browser (`mail_warteschlange_nach_antwort()`, wartet bis zu 3 Minuten auf freie Plätze)
  - beim nächsten Seitenaufruf des Dashboards (`mail_warteschlange_pruefen()` in `public/index.php`)
  - im täglichen Cron
- **Liste:** `mp_liste(200, $suche)` liefert die letzten 200 Einträge für Einstellungen → E-Mail → Mail-Log (nur Admin). Mails aus der Zeit vor dem Protokoll liest `mp_log_lesen()` aus `data/mail.log`. Folgt dort ein „FEHLER:“-Eintrag, gilt die Mail als Fehler, sonst als „ok (laut Log)“.
- Alles ist best-effort: Gibt es die Tabelle nicht, wird ungebremst gesendet.
