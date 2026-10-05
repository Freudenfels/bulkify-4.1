# crm/core/mail_abruf.php – automatischer E-Mail-Eingang

**Zweck:** Holt neue Mails aus einem Postfach (z. B. `crm@bulkify.pro`) und legt sie als „Eingang"
ab – mit KI-Vorschau. **Angelegt** (Kontakt/Notiz/Wiedervorlage) wird erst per Klick auf der
Eingang-Seite; der Abruf selbst schreibt nur nach `crm_mail_eingang`.

## Zugang
Steht in `crm_meta` (`imap_host`, `imap_port`, `imap_ssl`, `imap_user`, `imap_pass`, `imap_ordner`)
und ist über **Mehr → E-Mail-Eingang** pflegbar – bewusst nicht in der `secrets.php`, damit Nico ihn
selbst in der Oberfläche einträgt. `mail_imap_konfig()` / `mail_imap_speichern()`.

## Abruf
`mail_abholen($max=30)` holt die **UNGELESENEN** Mails (neueste zuerst), lässt jede von der KI
einordnen (`mail_ki_lesen()`), legt eine Zeile in `crm_mail_eingang` an und markiert die Mail als
gelesen. **Wirft nie**, dedupe über `message_id`. Gemeinsame Aufnahme beider Wege: `mail_eingang_aufnehmen()`.

**Zwei Abrufwege** (automatisch gewählt):
- `mail_abruf_via_ext()` – die **PHP-IMAP-Erweiterung** (`mail_abholen_imap`), wenn vorhanden.
- `mail_abruf_via_socket()` – unser **eigener TLS-Socket-Abruf** (`mail_abholen_socket` + `imap_client.php`),
  wenn ext-imap fehlt (so auf dem Live-Server). Braucht nur OpenSSL + mbstring.

`mail_abruf_moeglich()` ist true, sobald einer der beiden Wege geht – sonst sagt die Oberfläche es deutlich.

## Eingang verarbeiten
- `mail_eingang_liste('neu')`, `mail_eingang_zahl()`, `mail_eingang($id)`.
- `mail_eingang_plan($row)` rechnet **frisch** Treffer (`dublette_suchen`) + Plan (`mail_ki_plan`) –
  ohne erneuten KI-Aufruf, damit das Ziel aktuell ist.
- `mail_eingang_anlegen($id)` wendet den Plan an (wie die Bestätigung bei „E-Mail einlesen"):
  bestehender Kunde/Kontakt → Verlauf + Wiedervorlage, sonst neuer Kontakt (Quelle `mail`). Setzt Status.
- `mail_eingang_verwerfen($id)` legt die Mail still weg (Status `verworfen`).

## Cron
`mail_cron_token()` sichert `public/crm/mail_cron.php` – diese URL per Cron alle paar Minuten aufrufen,
dann füllt sich der Eingang von selbst. Ohne Cron geht es über „Postfach abrufen" auf der Eingang-Seite.
