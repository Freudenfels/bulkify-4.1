# crm/core/imap_client.php – IMAP-Abruf ohne ext-imap

**Zweck:** Postfach über einen eigenen **TLS-Socket** abfragen, wenn die PHP-IMAP-Erweiterung auf dem
Server fehlt (so ist es auf dem Live-Server). Nutzt nur OpenSSL (Socket) und mbstring (Dekodierung) –
beides ist vorhanden.

## `class BxImap`
Kann genau so viel, wie der E-Mail-Eingang braucht, bewusst minimal und defensiv (Timeouts, wirft nicht):
`verbinden()`, `anmelden($user,$pass)` (IMAP LOGIN), `waehlen('INBOX')` (SELECT),
`ungelesen()` (UID SEARCH UNSEEN → UIDs), `roh($uid)` (UID FETCH BODY.PEEK[] – holt die rohe Mail,
setzt \Seen NICHT), `gelesen($uid)` (UID STORE +FLAGS \Seen), `abmelden()`. Fehlertext in `$imap->fehler`.

## MIME-Parser
`mail_raw_parsen($raw)` zerlegt eine rohe RFC822-Mail in `from_name/from_email/subject/date/body`:
- Header einlesen (gefaltete Zeilen zusammenführen), RFC2047-Betreff/Absender über `mb_decode_mimeheader`.
- Body: `multipart/*` an der boundary aufteilen (rekursiv), bevorzugt **text/plain**, sonst **text/html**
  entschlackt. Transfer-Encoding (base64, quoted-printable) und Zeichensatz → UTF-8. Anhänge werden
  übersprungen.

## Zusammenspiel
`crm/core/mail_abruf.php` wählt den Weg: `mail_abruf_via_ext()` → ext-imap, sonst
`mail_abruf_via_socket()` → dieser Client. Beide Wege münden in dieselbe Aufnahme
(`mail_eingang_aufnehmen()` → KI-Vorschau + `crm_mail_eingang`). Für den Abruf-Ablauf siehe `mail_abruf.md`.
