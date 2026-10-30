# nachricht.php – Rückfragen zwischen Team und Lieferant

## Wozu
Fragen und Antworten zu Bestellungen und Preisanfragen laufen sonst per Mail oder WeChat und sind nachher nirgends zu finden. Hier gibt es **ein Gespräch je Lieferant**, das beide Seiten sehen: das Team im Lieferantenkonto und an der Bestellung, der Lieferant im Portal (Menüpunkt „Rückfragen", dazu an jeder Bestellung und Preisanfrage).

## Datenmodell
Tabelle `nachricht` (in `core/schema.php`): `lieferant_id`, optional `bezug_typ` (`bestellung` | `lieferant_anfrage`) + `bezug_id`, `akteur` (`team` | `lieferant`), `autor` (Name), `text` (bis 4000 Zeichen), `gelesen_team`, `gelesen_lieferant`, `erstellt` (UTC). Eine Nachricht mit Bezug erscheint am Vorgang **und** im Gesamtverlauf des Lieferanten.

**Anhänge** hängen in der vorhandenen Tabelle `dokument` mit `objekt_typ='nachricht'`, `objekt_id=<nachricht.id>`, `lieferant_id` gesetzt, `kunde_sichtbar=0`. So lassen sich CoA, Spezifikationen und Fotos in **beide Richtungen** mitschicken. Download: Team über `?p=dokument&id=`, Portal über `?p=lieferant_dokument&id=` (Freigabe in `lieferant_darf_datei()` um `objekt_typ='nachricht'` erweitert).

## Funktionen
- `nachricht_senden($lieferant_id, $akteur, $autor, $text, $bezug_typ, $bezug_id)` – speichert und verschickt (wenn der Versand eingerichtet ist) eine Mail an die andere Seite (`mail_nachricht()`). Schreibt der **Lieferant**, wird zusätzlich eine **Dashboard-Benachrichtigung** fürs Team erzeugt – mit **eigenem Typ `lieferant_nachricht`** („Nachricht vom Lieferanten", klar unterscheidbar von System-Ereignissen). Der Betreff nennt Lieferant (Name + Nummer), Produkt/Rohstoff und Vorgang; der Text steht im Nachrichtentext. Der Deep-Link öffnet die **Team-Übersicht** `?p=lief_nachrichten` am passenden Verlauf und springt per `#m<id>` direkt zur Nachricht. Team-Nachrichten erzeugen keine Benachrichtigung.
- `nachricht_bezug_produkt($bezug_typ, $bezug_id)` – Produkt/Rohstoff zum Vorgang (Anfrage: Artikel bzw. Rezeptur; Bestellung: erste Position). Nur Beiwerk, nie blockierend (try/catch).
- `nachrichten_fuer($lieferant_id, $bezug_typ, $bezug_id)` – Verlauf, älteste zuerst; ohne Bezug **alles** (Gesamtverlauf).
- `nachrichten_thread($lieferant_id, $bezug_typ, $bezug_id)` – genau **ein** Thread; ohne Bezug nur die **bezuglosen** (allgemeines Gespräch). `nachrichten_thread_gelesen(...)` markiert genau diesen Thread als vom Team gelesen.
- `nachrichten_ungelesen($lieferant_id, $seite)` / `nachrichten_ungelesen_je_lieferant()` – Zähler. `nachrichten_ungelesen_gesamt()` – alle ungelesenen Lieferanten-Nachrichten (Sicht Team) für den Nav-Zähler „Nachrichten".
- `nachricht_anhang_speichern($nachricht_id, $lieferant_id, $von, $file_key='anhang')` – legt ein/mehrere `$_FILES['anhang']` als `dokument` ab (Endungen/Max aus `core/lieferant_dateien.php`). `nachricht_anhaenge($nachricht_id)` listet sie.
- `nachricht_post_verarbeiten(...)` – verarbeitet `aktion=nachricht` (Feld `text` **+** `anhang[]`); mit Datei darf der Text leer sein (Platzhalter „(Datei angehängt)"), speichert danach die Anhänge.
- `nachricht_panel($lieferant_id, $wer, $sprache, $bezug_typ, $bezug_id, $mitBezugLink, $suche='')` – Panel „Rückfragen" (Chat + Eingabefeld, jetzt mit Datei-Feld, Anker `id="m<id>"` je Nachricht und optionalem `<mark>`-Highlight von `$suche`). Formular ist `multipart/form-data`.

## Wo es hängt
- Intern: `module/einkauf/lief_nachrichten.php` (**zentrale Übersicht aller Verläufe**, Route `?p=lief_nachrichten`), `module/lieferant/detail.php` (Reiter „Rückfragen"), `module/einkauf/detail.php` (zur Bestellung), `module/einkauf/lief_anfrage_detail.php` (zur Anfrage). Erreichbar auch vom Rohstoff (`module/lager/rohstoff_detail.php` → Button „Lieferanten-Nachrichten").
- Portal: `module/lieferant/nachrichten.php` (Gesamtverlauf), `module/lieferant/bestellung.php`, `module/lieferant/anfrage.php` (je Vorgang).
- Global: `core/nachricht.php` wird in `public/index.php` gebootet, damit der Nav-Zähler überall stimmt.

## Sicherheit
Kein CSRF-Schutz (wie im ganzen Projekt). Der Lieferant kann nur an seinem eigenen Gespräch schreiben – die `lieferant_id` kommt aus dem Login, nie aus dem Formular.
