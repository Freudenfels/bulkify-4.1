<?php
// Rückfragen zwischen Team und Lieferant – EIN Baustein für zwei Seiten:
// intern (Lieferantenkonto, Bestellung) und im Lieferantenportal (Übersicht, Bestellung, Preisanfrage).
// Eine Nachricht hängt immer am Lieferanten; optional zusätzlich an einer Bestellung oder
// Preisanfrage (bezug_typ/bezug_id), damit sie dort im Zusammenhang steht.
// Zeiten werden in UTC gespeichert (gmdate) und über fmt_zeit() angezeigt.
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/lieferant_dateien.php';   // Datei-Endungen/Max + BX_UPLOADS (für Anhänge)

// Nachricht speichern. $akteur = 'team' | 'lieferant'. Gibt die neue id zurück, 0 bei leerem Text.
function nachricht_senden(int $lieferant_id, string $akteur, string $autor, string $text, ?string $bezug_typ = null, ?int $bezug_id = null): int {
    $text = trim($text);
    if ($lieferant_id <= 0 || $text === '') return 0;
    $akteur = $akteur === 'lieferant' ? 'lieferant' : 'team';
    $bezug_typ = in_array($bezug_typ, ['bestellung', 'lieferant_anfrage'], true) ? $bezug_typ : null;
    $bezug_id  = $bezug_typ ? (int)$bezug_id : null;
    q("INSERT INTO nachricht (lieferant_id, bezug_typ, bezug_id, akteur, autor, text, erstellt) VALUES (?,?,?,?,?,?,?)",
      [$lieferant_id, $bezug_typ, $bezug_id, $akteur, mb_substr(trim($autor), 0, 190), mb_substr($text, 0, 4000), gmdate('Y-m-d H:i:s')]);
    $id = (int) insert_id();
    // Die andere Seite erfährt es per Mail – wenn der Versand eingerichtet ist. Ein Fehler stoppt nichts.
    if (function_exists('mail_nachricht') && function_exists('mail_bereit') && mail_bereit()) mail_nachricht($lieferant_id, $akteur, $text, $bezug_typ, $bezug_id);
    // Schreibt der LIEFERANT, bekommt das Team zusätzlich eine Dashboard-Benachrichtigung mit EIGENEM Typ
    // „Nachricht vom Lieferanten" (klar unterscheidbar von System-Ereignissen). Betreff nennt Lieferant
    // (Nummer + Name), Produkt/Rohstoff und Vorgang; der Text steht im Nachrichtentext. Der Link öffnet
    // direkt den Verlauf in der Team-Übersicht und springt zu dieser Nachricht.
    if ($akteur === 'lieferant' && function_exists('benachrichtigung_add')) {
        $lf     = one("SELECT firma, lieferantennummer FROM lieferanten WHERE id=?", [$lieferant_id]);
        $firma  = trim((string)($lf['firma'] ?? ''));
        $nummer = trim((string)($lf['lieferantennummer'] ?? ''));
        $wer    = ($firma !== '' ? $firma : ('Lieferant #' . $lieferant_id)) . ($nummer !== '' ? ' (' . $nummer . ')' : '');
        $teile  = [$wer];
        $produkt = nachricht_bezug_produkt($bezug_typ, $bezug_id);
        if ($produkt !== '') $teile[] = $produkt;
        $bezugLbl = nachricht_bezug_label($bezug_typ, $bezug_id);
        if ($bezugLbl !== '') $teile[] = $bezugLbl;
        $link = '?p=lief_nachrichten&lief=' . $lieferant_id
              . ($bezug_typ ? '&bt=' . $bezug_typ . '&bi=' . (int)$bezug_id : '')
              . '#m' . $id;
        benachrichtigung_add('Nachricht vom Lieferanten: ' . implode(' · ', $teile), $text, $link, 'lieferant_nachricht');
    }
    return $id;
}

// Nachrichten eines Lieferanten, älteste zuerst. Mit Bezug nur die zu dieser Bestellung/Anfrage.
function nachrichten_fuer(int $lieferant_id, ?string $bezug_typ = null, ?int $bezug_id = null): array {
    if ($bezug_typ && $bezug_id)
        return all("SELECT * FROM nachricht WHERE lieferant_id=? AND bezug_typ=? AND bezug_id=? ORDER BY id", [$lieferant_id, $bezug_typ, $bezug_id]);
    return all("SELECT * FROM nachricht WHERE lieferant_id=? ORDER BY id", [$lieferant_id]);
}

// Wie viele Nachrichten der Gegenseite hat diese Seite noch nicht gelesen? $seite = 'team' | 'lieferant'.
function nachrichten_ungelesen(int $lieferant_id, string $seite): int {
    $spalte = $seite === 'lieferant' ? 'gelesen_lieferant' : 'gelesen_team';
    $von    = $seite === 'lieferant' ? 'team' : 'lieferant';
    return (int) scalar("SELECT COUNT(*) FROM nachricht WHERE lieferant_id=? AND akteur=? AND $spalte=0", [$lieferant_id, $von]);
}
// Ungelesene je Lieferant (für Listen/Dashboard, Sicht Team): [lieferant_id => Anzahl].
function nachrichten_ungelesen_je_lieferant(): array {
    $out = [];
    foreach (all("SELECT lieferant_id, COUNT(*) n FROM nachricht WHERE akteur='lieferant' AND gelesen_team=0 GROUP BY lieferant_id") as $r)
        $out[(int)$r['lieferant_id']] = (int)$r['n'];
    return $out;
}
// Als gelesen markieren – alles vom Lieferanten bzw. Team, optional nur zu einem Bezug.
function nachrichten_gelesen_setzen(int $lieferant_id, string $seite, ?string $bezug_typ = null, ?int $bezug_id = null): void {
    $spalte = $seite === 'lieferant' ? 'gelesen_lieferant' : 'gelesen_team';
    $von    = $seite === 'lieferant' ? 'team' : 'lieferant';
    if ($bezug_typ && $bezug_id)
        q("UPDATE nachricht SET $spalte=1 WHERE lieferant_id=? AND akteur=? AND bezug_typ=? AND bezug_id=? AND $spalte=0", [$lieferant_id, $von, $bezug_typ, $bezug_id]);
    else
        q("UPDATE nachricht SET $spalte=1 WHERE lieferant_id=? AND akteur=? AND $spalte=0", [$lieferant_id, $von]);
}

// POST „aktion=nachricht" verarbeiten (Text im Feld „text"). Rückgabe '' oder Fehlertext in der Sprache.
function nachricht_post_verarbeiten(int $lieferant_id, string $wer, string $autor, ?string $bezug_typ = null, ?int $bezug_id = null, string $sprache = 'de'): string {
    $text = trim((string)($_POST['text'] ?? ''));
    // Datei(en) dabei? Dann darf der Text leer sein (nur Anhang senden).
    $fn = $_FILES['anhang']['name'] ?? '';
    $hatDatei = is_array($fn) ? (implode('', array_map('strval', $fn)) !== '') : ((string)$fn !== '');
    if ($text === '' && !$hatDatei) return $sprache === 'de' ? 'Bitte eine Nachricht eingeben.' : ($sprache === 'zh' ? '请输入留言内容。' : 'Please enter a message.');
    if ($text === '') $text = $sprache === 'de' ? '(Datei angehängt)' : ($sprache === 'zh' ? '（已附文件）' : '(file attached)');
    $id = nachricht_senden($lieferant_id, $wer, $autor, $text, $bezug_typ, $bezug_id);
    if ($id > 0 && $hatDatei) nachricht_anhang_speichern($id, $lieferant_id, $wer, 'anhang');
    return '';
}

// Bezug lesbar machen („Bestellung BE-2026-0012").
function nachricht_bezug_label(?string $bezug_typ, ?int $bezug_id, string $sprache = 'de'): string {
    if (!$bezug_typ || !$bezug_id) return '';
    if ($bezug_typ === 'bestellung') {
        $n = (string) scalar("SELECT nummer FROM bestellung WHERE id=?", [$bezug_id]);
        return ($sprache === 'de' ? 'Bestellung ' : ($sprache === 'zh' ? '订单 ' : 'Order ')) . $n;
    }
    $n = (string) scalar("SELECT nummer FROM lieferant_anfrage WHERE id=?", [$bezug_id]);
    return ($sprache === 'de' ? 'Preisanfrage ' : ($sprache === 'zh' ? '询价 ' : 'Price request ')) . $n;
}

// Produkt/Rohstoff zum Bezug (für Betreff/Übersicht): bei der Preisanfrage der angefragte Artikel
// bzw. die Rezeptur, bei der Bestellung die erste Position. '' wenn nichts eindeutig zuordenbar.
function nachricht_bezug_produkt(?string $bezug_typ, ?int $bezug_id): string {
    if (!$bezug_typ || !$bezug_id) return '';
    try {
        if ($bezug_typ === 'lieferant_anfrage') {
            $a = one("SELECT item_id, rezeptur_id, betreff FROM lieferant_anfrage WHERE id=?", [$bezug_id]);
            if (!$a) return '';
            if (!empty($a['item_id'])) { $n = (string) scalar("SELECT name FROM item WHERE id=?", [(int)$a['item_id']]); if ($n !== '') return $n; }
            if (!empty($a['rezeptur_id'])) { $n = (string) scalar("SELECT name FROM rezeptur WHERE id=?", [(int)$a['rezeptur_id']]); if ($n !== '') return $n; }
            return trim((string)($a['betreff'] ?? ''));
        }
        if ($bezug_typ === 'bestellung') {
            return (string) scalar("SELECT i.name FROM bestellung_position bp JOIN item i ON i.id=bp.item_id
                                    WHERE bp.bestellung_id=? AND bp.item_id IS NOT NULL ORDER BY bp.sort, bp.id LIMIT 1", [$bezug_id]);
        }
    } catch (Throwable $e) { /* Produkt ist nur Beiwerk – nie blockieren */ }
    return '';
}

// Verlauf genau EINES Threads. Mit Bezug: die Nachrichten zu diesem Vorgang. Ohne Bezug: nur die
// bezuglosen (allgemeines Gespräch) – anders als nachrichten_fuer(), das ohne Bezug ALLES liefert.
function nachrichten_thread(int $lieferant_id, ?string $bezug_typ = null, ?int $bezug_id = null): array {
    if ($bezug_typ && $bezug_id)
        return all("SELECT * FROM nachricht WHERE lieferant_id=? AND bezug_typ=? AND bezug_id=? ORDER BY id", [$lieferant_id, $bezug_typ, $bezug_id]);
    return all("SELECT * FROM nachricht WHERE lieferant_id=? AND bezug_typ IS NULL ORDER BY id", [$lieferant_id]);
}
// Einen Thread als vom Team gelesen markieren (nur die Nachrichten des Lieferanten in genau diesem Thread).
function nachrichten_thread_gelesen(int $lieferant_id, ?string $bezug_typ = null, ?int $bezug_id = null): void {
    if ($bezug_typ && $bezug_id)
        q("UPDATE nachricht SET gelesen_team=1 WHERE lieferant_id=? AND akteur='lieferant' AND bezug_typ=? AND bezug_id=? AND gelesen_team=0", [$lieferant_id, $bezug_typ, $bezug_id]);
    else
        q("UPDATE nachricht SET gelesen_team=1 WHERE lieferant_id=? AND akteur='lieferant' AND bezug_typ IS NULL AND gelesen_team=0", [$lieferant_id]);
}
// Gesamtzahl ungelesener Lieferanten-Nachrichten (Sicht Team) – für Nav-Zähler/Glocke.
function nachrichten_ungelesen_gesamt(): int {
    if (!table_exists('nachricht')) return 0;
    return (int) scalar("SELECT COUNT(*) FROM nachricht WHERE akteur='lieferant' AND gelesen_team=0");
}

// Anhänge einer Nachricht aus $_FILES[$file_key] (ein oder mehrere) in `dokument` ablegen
// (objekt_typ='nachricht'). $von = 'team' | 'lieferant'. Gibt die Zahl gespeicherter Dateien zurück.
function nachricht_anhang_speichern(int $nachricht_id, int $lieferant_id, string $von, string $file_key = 'anhang'): int {
    if ($nachricht_id <= 0 || empty($_FILES[$file_key]) || !defined('BX_UPLOADS')) return 0;
    $von = $von === 'lieferant' ? 'lieferant' : 'team';
    $f = $_FILES[$file_key];
    $namen = is_array($f['name'] ?? null) ? $f['name'] : [$f['name'] ?? ''];
    $tmps  = is_array($f['tmp_name'] ?? null) ? $f['tmp_name'] : [$f['tmp_name'] ?? ''];
    $errs  = is_array($f['error'] ?? null) ? $f['error'] : [$f['error'] ?? 1];
    $sizes = is_array($f['size'] ?? null) ? $f['size'] : [$f['size'] ?? 0];
    $endungen = function_exists('lieferant_datei_endungen') ? lieferant_datei_endungen() : ['pdf','png','jpg','jpeg','gif','webp','doc','docx','xls','xlsx','csv','txt','zip'];
    $maxB     = (function_exists('lieferant_datei_max_mb') ? lieferant_datei_max_mb() : 15) * 1024 * 1024;
    if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
    $n = 0;
    foreach ($namen as $i => $orig) {
        $orig = (string)$orig;
        if ($orig === '' || (int)($errs[$i] ?? 1) !== UPLOAD_ERR_OK) continue;
        $ext = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($orig, PATHINFO_EXTENSION)));
        if (!in_array($ext, $endungen, true)) continue;
        if ((int)($sizes[$i] ?? 0) > $maxB) continue;
        $fn = 'nachricht_' . $nachricht_id . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file((string)($tmps[$i] ?? ''), BX_UPLOADS . '/' . $fn)) continue;
        q("INSERT INTO dokument (objekt_typ,objekt_id,typ,lieferant_id,titel,datei,datei_orig,kunde_sichtbar,hochgeladen_von) VALUES ('nachricht',?,?,?,?,?,?,0,?)",
          [$nachricht_id, 'sonstiges', $lieferant_id, null, $fn, mb_substr($orig, 0, 255), $von]);
        $n++;
    }
    return $n;
}
// Anhänge einer Nachricht (für die Anzeige im Verlauf).
function nachricht_anhaenge(int $nachricht_id): array {
    if ($nachricht_id <= 0 || !table_exists('dokument')) return [];
    return all("SELECT id, typ, titel, datei, datei_orig FROM dokument WHERE objekt_typ='nachricht' AND objekt_id=? ORDER BY id", [$nachricht_id]);
}

// Panel „Rückfragen": Verlauf als Chat + Eingabefeld. Gibt HTML zurück (echo-fähig).
//   $lieferant_id – wessen Gespräch
//   $wer          – 'team' oder 'lieferant' (wer gerade schreibt; steuert die Seite der Blasen)
//   $sprache      – de | en | zh
//   $bezug_typ/$bezug_id – nur die Nachrichten zu dieser Bestellung/Anfrage, neue hängen daran
//   $mitBezugLink – im Gesamtverlauf: Bezug je Nachricht anzeigen und verlinken
//   $suche        – optional: Suchbegriff im Verlauf hervorheben (Volltextsuche der Übersicht)
function nachricht_panel(int $lieferant_id, string $wer = 'team', string $sprache = 'de', ?string $bezug_typ = null, ?int $bezug_id = null, bool $mitBezugLink = false, string $suche = ''): string {
    $t = function (string $de, string $en, string $zh = '') use ($sprache) {
        if ($sprache === 'de') return $de;
        return ($sprache === 'zh' && $zh !== '') ? $zh : $en;
    };
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    // Suchbegriff im (bereits HTML-escapten) Text als <mark> hervorheben.
    $hl = function (string $htmlText) use ($suche): string {
        $s = trim($suche);
        if ($s === '') return $htmlText;
        $needle = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        return preg_replace('/(' . preg_quote($needle, '/') . ')/iu', '<mark>$1</mark>', $htmlText) ?? $htmlText;
    };
    $dl = $wer === 'lieferant' ? '?p=lieferant_dokument&id=' : '?p=dokument&id=';
    $firma = (string) scalar("SELECT firma FROM lieferanten WHERE id=?", [$lieferant_id]);
    $eintraege = nachrichten_fuer($lieferant_id, $bezug_typ, $bezug_id);
    // Was die Gegenseite geschrieben hat, gilt mit dem Anzeigen als gelesen.
    nachrichten_gelesen_setzen($lieferant_id, $wer, $bezug_typ, $bezug_id);

    $o  = '<div class="bx-panel" id="chat"><h2 style="margin-top:0">' . $t('Rückfragen', 'Questions and answers', '留言与答复') . '</h2>';
    $o .= '<p class="muted" style="margin-top:0">' . ($bezug_typ
          ? $t('Fragen und Antworten zu diesem Vorgang. Beide Seiten sehen dieselben Nachrichten.', 'Questions and answers on this item. Both sides see the same messages.', '关于此事项的问题与答复。双方看到相同的留言。')
          : $t('Fragen und Antworten zwischen bulkify und ' . $firma . '. Beide Seiten sehen dieselben Nachrichten.', 'Questions and answers between bulkify and ' . $firma . '. Both sides see the same messages.', 'bulkify 与 ' . $firma . ' 之间的问题与答复。双方看到相同的留言。'))
          . '</p>';
    if (!$eintraege) {
        $o .= '<div class="muted" style="margin-bottom:12px">' . $t('Noch keine Nachrichten.', 'No messages yet.', '暂无留言。') . '</div>';
    } else {
        $o .= '<div class="bx-chat" style="margin-bottom:14px">';
        foreach ($eintraege as $e) {
            // Eigene Nachrichten links, die der Gegenseite rechts – wie ein Chat aus Sicht des Lesers.
            $eigene = $e['akteur'] === $wer;
            $who = $e['akteur'] === 'team' ? 'bulkify' : $firma;
            if (trim((string)$e['autor']) !== '') $who .= ' · ' . $e['autor'];
            $o .= '<div class="bx-msg ' . ($eigene ? 'team' : 'gegen') . '" id="m' . (int)$e['id'] . '"><div class="who">' . $h($who) . '</div><div class="bubble">' . $hl(nl2br($h($e['text'])));
            // Anhänge (CoA, Spec, Fotos …) in beide Richtungen.
            foreach (nachricht_anhaenge((int)$e['id']) as $an) {
                $o .= '<div style="margin-top:6px"><a href="' . $dl . (int)$an['id'] . '" target="_blank" rel="noopener">'
                    . $h($an['titel'] ?: ($an['datei_orig'] ?: ('Datei #' . (int)$an['id']))) . '</a></div>';
            }
            $meta = [fmt_zeit($e['erstellt'])];
            if ($mitBezugLink && $e['bezug_typ'] && $e['bezug_id']) {
                $lbl = nachricht_bezug_label($e['bezug_typ'], (int)$e['bezug_id'], $sprache);
                $url = $wer === 'lieferant'
                     ? ($e['bezug_typ'] === 'bestellung' ? '?p=lieferant_bestellung&id=' : '?p=lieferant_anfrage&id=') . (int)$e['bezug_id']
                     : ($e['bezug_typ'] === 'bestellung' ? '?p=bestellung&id=' . (int)$e['bezug_id'] : '?p=lief_anfrage_detail&id=' . (int)$e['bezug_id']);
                $meta[] = '<a href="' . $h($url) . '">' . $h($lbl) . '</a>';
            }
            $o .= '<div class="meta">' . implode(' · ', $meta) . '</div></div></div>';
        }
        $o .= '</div>';
    }
    $maxMb = function_exists('lieferant_datei_max_mb') ? lieferant_datei_max_mb() : 15;
    $acc   = function_exists('lieferant_datei_endungen') ? ('.' . implode(',.', lieferant_datei_endungen())) : '';
    $o .= '<form method="post" enctype="multipart/form-data">'
        . '<input type="hidden" name="aktion" value="nachricht">'
        . '<div class="bx-field" style="margin-bottom:10px"><label>' . $t('Neue Nachricht', 'New message', '新留言') . '</label>'
        . '<textarea name="text" rows="3" maxlength="4000" placeholder="' . $t('Ihre Frage oder Antwort …', 'Your question or answer …', '您的问题或答复……') . '"></textarea></div>'
        . '<div class="bx-field" style="margin-bottom:10px"><label>' . $t('Anhang (optional)', 'Attachment (optional)', '附件（可选）')
        . ' <span class="muted" style="font-weight:normal">(' . $t('CoA, Spezifikation, Fotos …', 'CoA, specification, photos …', 'CoA、规格书、照片……') . ', ' . $t('max.', 'max.', '最大') . ' ' . $maxMb . ' MB)</span></label>'
        . '<input type="file" name="anhang[]" multiple' . ($acc !== '' ? ' accept="' . $h($acc) . '"' : '') . '></div>'
        . '<button class="btn btn-primary" type="submit">' . $t('Senden', 'Send', '发送') . '</button>'
        . '</form></div>';
    return $o;
}
