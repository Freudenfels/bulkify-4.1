<?php
// Wiederverwendbare UI-Bausteine – Kopie der Dashboard-UI-Schicht (gleiche bx-Klassen), damit die
// umgezogenen Finanzseiten unverändert aussehen. Einziger Unterschied: kunde_link zeigt absolut aufs
// Dashboard-Kundencockpit (/?p=kunde&id=…), da die Buchhaltung keine eigene Kundenseite hat.
require_once __DIR__ . '/layout.php';

function bx_head(string $titel, string $sub = '', string $aktionen = ''): void {
    echo '<div class="bx-head"><div><h1>' . h($titel) . '</h1>';
    if ($sub !== '') echo '<p class="bx-sub">' . h($sub) . '</p>';
    echo '</div>';
    if ($aktionen !== '') echo '<div class="bx-row">' . $aktionen . '</div>';
    echo '</div>';
}

function bx_btn(string $label, string $href, string $variant = 'ghost'): string {
    return '<a class="btn btn-' . h($variant) . '" href="' . h($href) . '">' . h($label) . '</a>';
}

function status_text(string $s): string {
    $map = [
        'neu'=>'neu','offen'=>'offen','in_bearbeitung'=>'in Bearbeitung','beantwortet'=>'beantwortet',
        'gesendet'=>'gesendet','bestaetigt'=>'bestätigt','abgelehnt'=>'abgelehnt','zurueckgezogen'=>'zurückgezogen',
        'ueberarbeiten'=>'überarbeiten','vorschlag'=>'Vorschlag','eingefroren'=>'eingefroren','freigegeben'=>'freigegeben',
        'erledigt'=>'erledigt','storniert'=>'storniert','bestellt'=>'bestellt','geliefert'=>'geliefert',
        'teilgeliefert'=>'teilgeliefert','versendet'=>'versendet','bezahlt'=>'bezahlt','teilbezahlt'=>'teilbezahlt',
        'ueberfaellig'=>'überfällig','aktiv'=>'aktiv','inaktiv'=>'inaktiv','entwurf'=>'Entwurf','erstellt'=>'erstellt',
    ];
    $s = trim($s);
    return $map[$s] ?? ucfirst(str_replace('_', ' ', $s));
}
function bx_badge(string $text, string $kind = ''): string {
    $c = $kind ? ' badge-' . h($kind) : '';
    return '<span class="badge' . $c . '">' . h($text) . '</span>';
}

function firma_kurz(?string $firma): string {
    $f = trim((string)$firma);
    if ($f === '') return '';
    $teile = preg_split('/\s+[\/\-]\s+/', $f);
    return trim($teile[0] ?? $f);
}

// Kundenname als Link ins DASHBOARD-Kundencockpit (absolut, da die Buchhaltung keine Kundenseite hat).
function kunde_link($kunde_id, ?string $firma): string {
    if ($firma === null || $firma === '') return '<span class="muted">–</span>';
    if (!$kunde_id) return h($firma);
    return '<a class="kundenlink" href="/?p=kunde&id=' . (int)$kunde_id . '" target="_blank" onclick="event.stopPropagation()">' . h($firma) . '</a>';
}

function pdf_btn(string $href, string $label = 'PDF', bool $stopRow = false, string $title = ''): string {
    $stop = $stopRow ? ' onclick="event.stopPropagation()"' : '';
    $t = $title !== '' ? $title : 'Als PDF herunterladen';
    $svg = '<svg class="pdf-ico" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" '
         . 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>'
         . '<path d="M12 11v6"/><path d="M9.5 14.5 12 17l2.5-2.5"/></svg>';
    return '<a class="pdfbtn" href="' . h($href) . '" target="_blank" rel="noopener" title="' . h($t) . '"' . $stop . '>'
         . $svg . '<span>' . h($label) . '</span></a>';
}

function bx_hint(string $text): string {
    return '<span class="hint" title="' . h($text) . '"></span>';
}

function bx_tabs(array $tabs, string $aktiv, string $baseUrl): void {
    echo '<div class="settabs">';
    foreach ($tabs as $slug => $label) {
        $on = $slug === $aktiv ? ' class="on"' : '';
        $sep = strpos($baseUrl, '?') === false ? '?' : '&';
        echo '<a' . $on . ' href="' . h($baseUrl . $sep . 'tab=' . $slug) . '">' . h($label) . '</a>';
    }
    echo '</div>';
}

function bx_table(array $cols, array $rows, array $opts = []): void {
    $baseUrl = $opts['baseUrl'] ?? '';
    $sort    = $opts['sort'] ?? '';
    $dir     = ($opts['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
    $rowUrl  = $opts['rowUrl'] ?? null;

    echo '<div class="bx-tablewrap"><table class="bx-table"><thead><tr>';
    foreach ($cols as $key => $c) {
        $numcls = !empty($c['num']) ? ' class="bx-num"' : '';
        echo '<th' . $numcls . '>';
        if (!empty($c['sort']) && $baseUrl !== '') {
            $ndir = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
            $sep = strpos($baseUrl, '?') === false ? '?' : '&';
            $arw = $sort === $key ? '<span class="arw">' . ($dir === 'asc' ? '&#9650;' : '&#9660;') . '</span>' : '';
            echo '<a href="' . h($baseUrl . $sep . 'sort=' . $key . '&dir=' . $ndir) . '">' . h($c['label']) . ' ' . $arw . '</a>';
        } else {
            echo h($c['label']);
        }
        echo '</th>';
    }
    echo '</tr></thead><tbody>';

    if (!$rows) {
        echo '<tr><td colspan="' . count($cols) . '" class="muted">' . h($opts['empty'] ?? 'Keine Einträge.') . '</td></tr>';
    }
    foreach ($rows as $row) {
        $href = $rowUrl ? $rowUrl($row) : null;
        echo '<tr' . ($href ? ' style="cursor:pointer" onclick="location.href=\'' . h($href) . '\'"' : '') . '>';
        foreach ($cols as $key => $c) {
            $numcls = !empty($c['num']) ? ' class="bx-num"' : '';
            $val = isset($c['render']) ? $c['render']($row) : h((string)($row[$key] ?? ''));
            echo '<td' . $numcls . '>' . $val . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function bx_sort_rows(array $rows, string $key, string $dir = 'asc'): array {
    if ($key === '') return $rows;
    usort($rows, function($a, $b) use ($key) {
        $x = $a[$key] ?? ''; $y = $b[$key] ?? '';
        if (is_numeric($x) && is_numeric($y)) return $x <=> $y;
        return strcasecmp((string)$x, (string)$y);
    });
    if ($dir === 'desc') $rows = array_reverse($rows);
    return $rows;
}
