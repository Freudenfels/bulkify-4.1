<?php
// Wiederverwendbare UI-Bausteine bulkify 4.1 – eine Quelle fuer das Aussehen.
// Jede Liste/jedes Formular wird hierdurch gebaut, nie HTML von Hand pro Seite.
require_once __DIR__ . '/layout.php';

// Seitenkopf mit optionalen Aktions-Buttons rechts
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

// Statuswert aus der Datenbank in lesbares Deutsch – MIT Umlauten. In der DB stehen die Werte
// bewusst ohne (bestaetigt, zurueckgezogen …); auf dem Bildschirm gehören sie richtig geschrieben.
// Unbekanntes wird nur aufgehübscht (Unterstrich raus, erster Buchstabe groß), nie verschluckt.
function status_text(string $s): string {
    $map = [
        'neu'            => 'neu',
        'offen'          => 'offen',
        'in_bearbeitung' => 'in Bearbeitung',
        'beantwortet'    => 'beantwortet',
        'gesendet'       => 'gesendet',
        'bestaetigt'     => 'bestätigt',
        'abgelehnt'      => 'abgelehnt',
        'zurueckgezogen' => 'zurückgezogen',
        'ueberarbeiten'  => 'überarbeiten',
        'vorschlag'      => 'Vorschlag',
        'eingefroren'    => 'eingefroren',
        'freigegeben'    => 'freigegeben',
        'erledigt'       => 'erledigt',
        'storniert'      => 'storniert',
        'bestellt'       => 'bestellt',
        'geliefert'      => 'geliefert',
        'teilgeliefert'  => 'teilgeliefert',
        'versendet'      => 'versendet',
        'bezahlt'        => 'bezahlt',
        'ueberfaellig'   => 'überfällig',
        'quarantaene'    => 'Quarantäne',
        'gesperrt'       => 'gesperrt',
        'aktiv'          => 'aktiv',
        'inaktiv'        => 'inaktiv',
        'entwurf'        => 'Entwurf',
        'in_produktion'  => 'in Produktion',
        'produziert'     => 'produziert',
    ];
    $s = trim($s);
    return $map[$s] ?? ucfirst(str_replace('_', ' ', $s));
}
function bx_badge(string $text, string $kind = ''): string {
    $c = $kind ? ' badge-' . h($kind) : '';
    return '<span class="badge' . $c . '">' . h($text) . '</span>';
}

// Kundenname als Link zum Kunden-Cockpit (?p=kunde&id=…). In klickbaren Listenzeilen verhindert
// event.stopPropagation(), dass zusätzlich der Zeilen-Klick auslöst. Ohne Firma: „–", ohne id: nur Text.
function kunde_link($kunde_id, ?string $firma): string {
    if ($firma === null || $firma === '') return '<span class="muted">–</span>';
    if (!$kunde_id) return h($firma);
    return '<a href="?p=kunde&id=' . (int)$kunde_id . '" onclick="event.stopPropagation()">' . h($firma) . '</a>';
}

// Prioritäts-Badge (1=Hoch, 2=Normal, 3=Niedrig)
function prio_badge(int $p): string {
    return match ($p) {
        1 => bx_badge('Hoch', 'err'),
        3 => bx_badge('Niedrig'),
        default => bx_badge('Normal', 'info'),
    };
}

// Hinweis als ⓘ (Tooltip). Wichtige Infos so, statt Textwueste.
function bx_hint(string $text): string {
    return '<span class="hint" title="' . h($text) . '"></span>';
}

// Reiter. $tabs = ['slug'=>'Label']. Aktiver aus ?tab. baseUrl ohne tab-Param.
function bx_tabs(array $tabs, string $aktiv, string $baseUrl): void {
    echo '<div class="settabs">';
    foreach ($tabs as $slug => $label) {
        $on = $slug === $aktiv ? ' class="on"' : '';
        $sep = strpos($baseUrl, '?') === false ? '?' : '&';
        echo '<a' . $on . ' href="' . h($baseUrl . $sep . 'tab=' . $slug) . '">' . h($label) . '</a>';
    }
    echo '</div>';
}

/*
 Liste rendern.
 $cols: ['key' => ['label'=>..,'sort'=>bool,'num'=>bool,'render'=>fn($row)=>string]]
 $rows: array of assoc rows
 $opts: ['baseUrl'=>?p=..., 'sort'=>aktuelleSpalte, 'dir'=>'asc|desc', 'rowUrl'=>fn($row)=>href, 'empty'=>text]
*/
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

// Verknüpftes Objekt -> Ziel-URL. Zentral: sobald ein Modul steht, greift der Sprung automatisch.
function aktivitaet_link(?string $ref_typ, $ref_id): ?string {
    $ref_id = (int)$ref_id;
    if (!$ref_typ || !$ref_id) return null;
    $map = [
        'rezeptur'   => 'rezeptur_detail',
        'produkt'    => 'produkt',
        'angebot'    => 'angebot',
        'anfrage'    => 'anfrage',
        'auftrag'    => 'auftrag',
        'bestellung' => 'bestellung',
        'beleg'      => 'beleg',
        'dokument'   => 'dokument',
    ];
    if (!isset($map[$ref_typ])) return null;
    return '?p=' . $map[$ref_typ] . '&id=' . $ref_id;
}

// Aktivitätsverlauf als Chat rendern. links = team (wir), rechts = Gegenstelle (Kunde/Lieferant), mittig = system.
function bx_chat(array $eintraege, string $gegenName = 'Gegenstelle'): void {
    if (!$eintraege) { echo '<div class="muted">Noch keine Aktivitäten.</div>'; return; }
    echo '<div class="bx-chat">';
    foreach ($eintraege as $e) {
        $akteur = $e['akteur'] ?? 'system';
        // Seite bestimmen: team = links, system = mittig, alles andere = rechts (Gegenstelle)
        $side = $akteur === 'team' ? 'team' : ($akteur === 'system' ? 'system' : 'gegen');
        $who  = $akteur === 'team' ? 'bulkify (wir)' : ($akteur === 'system' ? 'System' : $gegenName);
        $link = aktivitaet_link($e['ref_typ'] ?? null, $e['ref_id'] ?? 0);
        echo '<div class="bx-msg ' . $side . '">';
        if ($akteur !== 'system') echo '<div class="who">' . h($who) . '</div>';
        echo '<div class="bubble">';
        if ($link) echo '<a class="bx-actlink" href="' . h($link) . '">' . h($e['text']) . '</a>';
        else       echo h($e['text']);
        $meta = [];
        if (!empty($e['typ']))      $meta[] = $e['typ'];
        if (!empty($e['erstellt'])) $meta[] = fmt_zeit($e['erstellt']);
        if ($meta) echo '<div class="meta">' . h(implode(' · ', $meta)) . '</div>';
        echo '</div></div>';
    }
    echo '</div>';
}

// Rows serverseitig sortieren (case-insensitiv, Zahlen numerisch)
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
