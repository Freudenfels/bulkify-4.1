<?php
// Tagesbriefing: drei Saetze zu dem, was heute wirklich zaehlt.
//
// Die Liste sagt, WAS offen ist. Das Briefing sagt, WOMIT man anfaengt - und das ist bei zwanzig
// Zeilen die eigentliche Frage.
//
// Gerechnet wird hoechstens einmal je Tag und Listenstand. Ohne diese Bremse liefe bei jedem
// Seitenaufruf eine KI-Anfrage; das waere teuer und langsam, und der Text wuerde sich bei jedem
// Neuladen aendern - was den Eindruck macht, das Programm sei sich nicht sicher.
require_once __DIR__ . '/ki.php';
require_once __DIR__ . '/wartet.php';
require_once __DIR__ . '/schema.php';

// Liefert den Text oder '' (nicht eingerichtet, Fehler, oder nichts zu tun).
// $neu = true erzwingt eine neue Fassung.
function briefing_text(bool $neu = false): string {
    $zeilen = wartet_zeilen('sie');
    if (!$zeilen) return '';
    if (!ki_bereit()) return '';

    // Der Stand: Tag + welche Zeilen offen sind. Aendert sich eine Zeile, ist das Briefing veraltet.
    $abdruck = date('Y-m-d') . '|';
    foreach ($zeilen as $z) $abdruck .= $z['typ'] . $z['id'] . ':' . $z['tage'] . ';';
    $abdruck = md5($abdruck);

    if (!$neu && crm_meta_lesen('briefing_stand', '') === $abdruck) {
        return crm_meta_lesen('briefing_text', '');
    }

    $liste = '';
    foreach (array_slice($zeilen, 0, 25) as $z) {
        $liste .= '- ' . warte_text((int)$z['tage']) . ' | ' . zeilen_art((string)$z['typ']) . ' | '
                . mb_substr((string)$z['titel'], 0, 90) . ' | ' . mb_substr((string)$z['unter'], 0, 90)
                . ($z['betrag'] !== null ? ' | ' . number_format((float)$z['betrag'], 0, ',', '.') . ' EUR' : '')
                . "\n";
    }

    $anweisung = <<<TXT
Du bist die rechte Hand eines Lohnherstellers fuer Nahrungsergaenzungsmittel. Unten steht, was
gerade offen ist - sortiert nach Wartezeit, das Aelteste zuerst.

Schreib drei bis vier kurze Saetze, die beim Aufwachen helfen:
1. Wie die Lage insgesamt ist (eine Zahl, kein Roman).
2. Was am dringendsten ist und warum - nenne Namen.
3. Womit du anfangen wuerdest.

Regeln:
- Deutsch, sachlich, direkt. Kein "Guten Morgen", keine Motivationssprueche, keine Emojis.
- Nichts erfinden. Nur das nennen, was unten steht.
- Wenn alles frisch ist, sag genau das in einem Satz.
- Antworte NUR mit den Saetzen, ohne Ueberschrift und ohne Aufzaehlungszeichen.

Offen:
$liste
TXT;

    $r = ki_frage($anweisung, [
        'zweck'      => 'briefing',
        'modell'     => KI_MODELL_SCHNELL,
        'max_tokens' => 600,
        'timeout'    => 45,
        'budget'     => 60,
    ]);
    if (empty($r['ok'])) return '';

    $text = trim((string)($r['text'] ?? ''));
    if ($text === '') return '';
    crm_meta_schreiben('briefing_stand', $abdruck);
    crm_meta_schreiben('briefing_text', $text);
    return $text;
}
