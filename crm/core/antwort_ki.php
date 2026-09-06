<?php
// Antwortvorschlag: einen Text entwerfen, den du selbst verschickst.
//
// Wichtig, damit es kein Missverstaendnis gibt: Das CRM verschickt NICHTS. Es legt dir einen
// Entwurf hin, du liest ihn, aenderst ihn und kopierst ihn in WhatsApp oder ins Mailprogramm.
// Alles andere waere gefaehrlich - eine automatisch verschickte Antwort mit einem falschen Preis
// oder einer falschen Zusage kostet mehr, als der Entwurf einspart.
require_once __DIR__ . '/ki.php';
require_once __DIR__ . '/ui.php';

// $wer: Name/Firma des Gegenuebers. $verlauf: Liste [typ, text, angelegt], neueste zuerst.
// $absender: wie wir unterschreiben. $sprache: 'auto' oder de/en/zh.
function antwort_ki_entwurf(string $wer, string $anliegen, array $verlauf, string $absender, string $sprache = 'auto'): array {
    if (!ki_bereit()) return ['ok' => false, 'text' => '', 'fehler' => 'Die KI ist nicht eingerichtet.'];

    $bisher = '';
    foreach (array_slice($verlauf, 0, 8) as $v) {
        $bisher .= '- ' . fmt_zeit((string)$v['angelegt'], 'd.m.Y') . ' (' . (string)$v['typ'] . '): '
                 . mb_substr(trim((string)$v['text']), 0, 400) . "\n";
    }
    if ($bisher === '') $bisher = "- noch nichts notiert\n";

    $spracheHinweis = $sprache === 'auto'
        ? 'Schreib in der Sprache, in der das Anliegen formuliert ist. Im Zweifel Deutsch.'
        : 'Schreib auf ' . ['de' => 'Deutsch', 'en' => 'Englisch', 'zh' => 'Chinesisch'][$sprache] ?? 'Deutsch' . '.';

    $anweisung = <<<TXT
Du entwirfst eine kurze, freundliche Geschaeftsnachricht. Wir sind bulkify, ein Lohnhersteller fuer
Nahrungsergaenzungsmittel. Der Entwurf geht an einen Interessenten oder Kunden.

$spracheHinweis

Regeln:
- Kurz. Drei bis sechs Saetze, keine Floskelkaskaden.
- Per Sie, freundlich, direkt. Keine Werbesprache, keine Ausrufezeichen.
- KEINE Preise, KEINE Liefertermine, KEINE Zusagen erfinden. Steht es nicht im Verlauf, wird es
  nicht behauptet. Im Zweifel schreib, dass du dich mit den Zahlen meldest.
- Nenne konkret, was der naechste Schritt ist.
- Unterschreibe mit: $absender
- Antworte NUR mit dem fertigen Nachrichtentext, ohne Betreffzeile, ohne Anfuehrungszeichen,
  ohne Erklaerung drumherum.

Gegenueber: $wer
Anliegen: $anliegen

Bisheriger Verlauf (neueste zuerst):
$bisher
TXT;

    $r = ki_frage($anweisung, [
        'zweck'      => 'antwort/entwurf',
        'modell'     => KI_MODELL_SCHNELL,
        'max_tokens' => 900,
        'timeout'    => 60,
        'budget'     => 90,
    ]);
    if (empty($r['ok'])) return ['ok' => false, 'text' => '', 'fehler' => (string)($r['fehler'] ?? 'Fehler')];
    return ['ok' => true, 'text' => trim((string)($r['text'] ?? '')), 'fehler' => ''];
}
