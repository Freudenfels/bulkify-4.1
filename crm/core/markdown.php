<?php
// Markdown anzeigen - klein gehalten, absichtlich.
//
// Die KI liefert den Fragenkatalog als Markdown. Wir brauchen davon nur, was der Prompt auch
// wirklich verlangt: Überschriften, fette Stellen, kursive Zwischentöne, Aufzählungen und
// waagerechte Striche. Eine vollständige Markdown-Bibliothek wäre hier mehr Risiko als Nutzen.
//
// Sicherheit: Der Text wird ZUERST vollständig escaped, danach werden nur die eigenen Zeichen
// wieder zu HTML. Was die KI schreibt, kann also kein HTML einschleusen.

function md_zu_html(string $md): string {
    $zeilen = preg_split("/\r\n|\n|\r/", trim($md));
    $out    = '';
    $liste  = null;   // 'ul' oder 'ol', solange eine offen ist
    $absatz = [];

    $schliesseListe = function () use (&$liste, &$out) {
        if ($liste !== null) { $out .= '</' . $liste . '>'; $liste = null; }
    };
    $schliesseAbsatz = function () use (&$absatz, &$out) {
        if ($absatz) { $out .= '<p>' . md_inline(implode(' ', $absatz)) . '</p>'; $absatz = []; }
    };

    foreach ($zeilen as $z) {
        $z = rtrim($z);

        if (trim($z) === '')            { $schliesseAbsatz(); $schliesseListe(); continue; }
        if (preg_match('/^\s*---+\s*$/', $z)) { $schliesseAbsatz(); $schliesseListe(); $out .= '<hr>'; continue; }

        // Überschriften: ### wird zu h3, ## zu h2, # zu h2 (h1 gehoert der Seite).
        if (preg_match('/^(#{1,6})\s+(.*)$/', $z, $m)) {
            $schliesseAbsatz(); $schliesseListe();
            $stufe = min(3, max(2, strlen($m[1])));
            $out .= '<h' . $stufe . '>' . md_inline($m[2]) . '</h' . $stufe . '>';
            continue;
        }

        // Aufzaehlung mit - oder *
        if (preg_match('/^\s*[-*]\s+(.*)$/', $z, $m)) {
            $schliesseAbsatz();
            if ($liste !== 'ul') { $schliesseListe(); $out .= '<ul>'; $liste = 'ul'; }
            $out .= '<li>' . md_inline($m[1]) . '</li>';
            continue;
        }
        // Nummerierte Aufzaehlung
        if (preg_match('/^\s*\d+[.)]\s+(.*)$/', $z, $m)) {
            $schliesseAbsatz();
            if ($liste !== 'ol') { $schliesseListe(); $out .= '<ol>'; $liste = 'ol'; }
            $out .= '<li>' . md_inline($m[1]) . '</li>';
            continue;
        }

        $schliesseListe();
        $absatz[] = $z;
    }
    $schliesseAbsatz();
    $schliesseListe();
    return $out;
}

// Innerhalb einer Zeile: **fett**, *kursiv*, `code`. Erst escapen, dann ersetzen.
function md_inline(string $t): string {
    $t = htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    $t = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $t);
    $t = preg_replace('/(?<![\w*])\*([^*\n]+?)\*(?![\w*])/u', '<em>$1</em>', $t);
    $t = preg_replace('/`([^`\n]+?)`/u', '<code>$1</code>', $t);
    return (string)$t;
}
