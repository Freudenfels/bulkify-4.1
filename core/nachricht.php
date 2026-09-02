<?php
// Rückfragen zwischen Team und Lieferant – EIN Baustein für zwei Seiten:
// intern (Lieferantenkonto, Bestellung) und im Lieferantenportal (Übersicht, Bestellung, Preisanfrage).
// Eine Nachricht hängt immer am Lieferanten; optional zusätzlich an einer Bestellung oder
// Preisanfrage (bezug_typ/bezug_id), damit sie dort im Zusammenhang steht.
// Zeiten werden in UTC gespeichert (gmdate) und über fmt_zeit() angezeigt.
require_once __DIR__ . '/schema.php';

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
    if ($text === '') return $sprache === 'de' ? 'Bitte eine Nachricht eingeben.' : ($sprache === 'zh' ? '请输入留言内容。' : 'Please enter a message.');
    nachricht_senden($lieferant_id, $wer, $autor, $text, $bezug_typ, $bezug_id);
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

// Panel „Rückfragen": Verlauf als Chat + Eingabefeld. Gibt HTML zurück (echo-fähig).
//   $lieferant_id – wessen Gespräch
//   $wer          – 'team' oder 'lieferant' (wer gerade schreibt; steuert die Seite der Blasen)
//   $sprache      – de | en | zh
//   $bezug_typ/$bezug_id – nur die Nachrichten zu dieser Bestellung/Anfrage, neue hängen daran
//   $mitBezugLink – im Gesamtverlauf: Bezug je Nachricht anzeigen und verlinken
function nachricht_panel(int $lieferant_id, string $wer = 'team', string $sprache = 'de', ?string $bezug_typ = null, ?int $bezug_id = null, bool $mitBezugLink = false): string {
    $t = function (string $de, string $en, string $zh = '') use ($sprache) {
        if ($sprache === 'de') return $de;
        return ($sprache === 'zh' && $zh !== '') ? $zh : $en;
    };
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $firma = (string) scalar("SELECT firma FROM lieferanten WHERE id=?", [$lieferant_id]);
    $eintraege = nachrichten_fuer($lieferant_id, $bezug_typ, $bezug_id);
    // Was die Gegenseite geschrieben hat, gilt mit dem Anzeigen als gelesen.
    nachrichten_gelesen_setzen($lieferant_id, $wer, $bezug_typ, $bezug_id);

    $o  = '<div class="bx-panel"><h2 style="margin-top:0">' . $t('Rückfragen', 'Questions and answers', '留言与答复') . '</h2>';
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
            $o .= '<div class="bx-msg ' . ($eigene ? 'team' : 'gegen') . '"><div class="who">' . $h($who) . '</div><div class="bubble">' . nl2br($h($e['text']));
            $meta = [fmt_zeit($e['erstellt'])];
            if ($mitBezugLink && $e['bezug_typ'] && $e['bezug_id']) {
                $lbl = nachricht_bezug_label($e['bezug_typ'], (int)$e['bezug_id'], $sprache);
                $url = $wer === 'lieferant'
                     ? ($e['bezug_typ'] === 'bestellung' ? '?p=lieferant_bestellung&id=' : '?p=lieferant_anfrage&id=') . (int)$e['bezug_id']
                     : ($e['bezug_typ'] === 'bestellung' ? '?p=bestellung&id=' . (int)$e['bezug_id'] : '?p=lieferant&id=' . $lieferant_id);
                $meta[] = '<a href="' . $h($url) . '">' . $h($lbl) . '</a>';
            }
            $o .= '<div class="meta">' . implode(' · ', $meta) . '</div></div></div>';
        }
        $o .= '</div>';
    }
    $o .= '<form method="post">'
        . '<input type="hidden" name="aktion" value="nachricht">'
        . '<div class="bx-field" style="margin-bottom:10px"><label>' . $t('Neue Nachricht', 'New message', '新留言') . '</label>'
        . '<textarea name="text" rows="3" required maxlength="4000" placeholder="' . $t('Ihre Frage oder Antwort …', 'Your question or answer …', '您的问题或答复……') . '"></textarea></div>'
        . '<button class="btn btn-primary" type="submit">' . $t('Senden', 'Send', '发送') . '</button>'
        . '</form></div>';
    return $o;
}
