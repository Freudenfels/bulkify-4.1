# nachricht.php – Rückfragen zwischen Team und Lieferant

## Wozu
Fragen und Antworten zu Bestellungen und Preisanfragen laufen sonst per Mail oder WeChat und sind nachher nirgends zu finden. Hier gibt es **ein Gespräch je Lieferant**, das beide Seiten sehen: das Team im Lieferantenkonto und an der Bestellung, der Lieferant im Portal (Menüpunkt „Rückfragen", dazu an jeder Bestellung und Preisanfrage).

## Datenmodell
Tabelle `nachricht` (in `core/schema.php`): `lieferant_id`, optional `bezug_typ` (`bestellung` | `lieferant_anfrage`) + `bezug_id`, `akteur` (`team` | `lieferant`), `autor` (Name), `text` (bis 4000 Zeichen), `gelesen_team`, `gelesen_lieferant`, `erstellt` (UTC). Eine Nachricht mit Bezug erscheint am Vorgang **und** im Gesamtverlauf des Lieferanten.

## Funktionen
- `nachricht_senden($lieferant_id, $akteur, $autor, $text, $bezug_typ, $bezug_id)` – speichert und verschickt (wenn der Versand eingerichtet ist) eine Mail an die andere Seite (`mail_nachricht()` in `core/mail.php`).
- `nachrichten_fuer($lieferant_id, $bezug_typ, $bezug_id)` – Verlauf, älteste zuerst; ohne Bezug alles.
- `nachrichten_ungelesen($lieferant_id, $seite)` – wie viele Nachrichten der Gegenseite diese Seite noch nicht gesehen hat; `nachrichten_ungelesen_je_lieferant()` für Listen (Sicht Team).
- `nachrichten_gelesen_setzen(...)` – wird beim Anzeigen des Panels automatisch aufgerufen.
- `nachricht_post_verarbeiten($lieferant_id, $wer, $autor, $bezug_typ, $bezug_id, $sprache)` – verarbeitet `aktion=nachricht` (Feld `text`), gibt `''` oder den Fehler zurück.
- `nachricht_panel($lieferant_id, $wer, $sprache, $bezug_typ, $bezug_id, $mitBezugLink)` – das Panel „Rückfragen" (Chat + Eingabefeld) als HTML. Eigene Nachrichten stehen links, die der Gegenseite rechts. Beschriftungen in de/en/zh.

## Wo es hängt
- Intern: `module/lieferant/detail.php` (Reiter „Rückfragen", Gesamtverlauf mit Bezug-Links), `module/einkauf/detail.php` (nur zur Bestellung).
- Portal: `module/lieferant/nachrichten.php` (Gesamtverlauf), `module/lieferant/bestellung.php`, `module/lieferant/anfrage.php` (je Vorgang).

## Sicherheit
Kein CSRF-Schutz (wie im ganzen Projekt). Der Lieferant kann nur an seinem eigenen Gespräch schreiben – die `lieferant_id` kommt aus dem Login, nie aus dem Formular.
