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

// Fehlt-Anzeige mit Quarantäne (Nacht 1): deckt Ware in Quarantäne die Lücke, ist das KEIN Fehlbestand, sondern wartet auf die
// QM-Freigabe -> gelbes „in Quarantäne" statt rotem „fehlt". Reicht die Quarantäne nicht, „fehlt" mit Hinweis auf den Quarantäne-Teil.
// $menge = fertig formatierter Text (z. B. „12 kg"), $url = Freigabe-Link (leer = ohne Link).
function bx_fehlt_quar(float $fehlt, float $quar, string $fehltTxt, string $quarTxt, string $url = ''): string {
    $q = $quar > 1e-6 ? ($url !== '' ? '<a href="' . h($url) . '" style="text-decoration:none">' : '') . bx_badge('in Quarantäne ' . $quarTxt . ($url !== '' ? ' – freigeben' : ''), 'warn') . ($url !== '' ? '</a>' : '') : '';
    if ($quar > 1e-6 && $quar + 1e-6 >= $fehlt) return $q;
    return bx_badge('fehlt ' . $fehltTxt, 'err') . ($q !== '' ? ' ' . $q : '');
}

// Firmenname für Listen kürzen: nur den Teil VOR einem umschlossenen Trenner „ / " oder „ - "
// (z. B. „BioMedical Company / FuturaYou" -> „BioMedical Company", „Get Fit - Dr. Arnd Schürmann"
// -> „Get Fit"). Namen ohne umschlossenen Trenner (z. B. „Coca-Cola") bleiben unveraendert.
function firma_kurz(?string $firma): string {
    $f = trim((string)$firma);
    if ($f === '') return '';
    $teile = preg_split('/\s+[\/\-]\s+/', $f);
    return trim($teile[0] ?? $f);
}

// Kundenname als Link zum Kunden-Cockpit (?p=kunde&id=…). In klickbaren Listenzeilen verhindert
// event.stopPropagation(), dass zusätzlich der Zeilen-Klick auslöst. Ohne Firma: „–", ohne id: nur Text.
// Ist am Kunden ein internes Kürzel gepflegt, steht das Kürzel da (voller Name als Tooltip) – spart Platz in Tabellen.
function kunde_link($kunde_id, ?string $firma): string {
    if ($firma === null || $firma === '') return '<span class="muted">–</span>';
    if (!$kunde_id) return h($firma);
    $kz = kunde_kuerzel((int)$kunde_id);
    return '<a class="kundenlink" href="?p=kunde&id=' . (int)$kunde_id . '" onclick="event.stopPropagation()"'
         . ($kz !== '' ? ' title="' . h($firma) . '">' . h($kz) : '>' . h($firma)) . '</a>';
}

// Internes Kürzel eines Kunden ('' = keins). NUR für interne Tabellen – nie in PDFs, Mails oder im Kundenportal.
// Alle Kürzel werden einmal je Seitenaufruf geladen (eine Abfrage), danach aus dem Speicher.
function kunde_kuerzel(int $kunde_id): string {
    static $map = null;
    if ($map === null) {
        $map = [];
        try { foreach (all("SELECT id, kuerzel FROM kunden WHERE kuerzel IS NOT NULL AND kuerzel<>''") as $r) $map[(int)$r['id']] = (string)$r['kuerzel']; }
        catch (\Throwable $e) { /* Spalte (noch) nicht da */ }
    }
    return $map[$kunde_id] ?? '';
}

// Kundenname für interne Tabellen als Text (ohne Link): Kürzel, sonst Firma. Mit Tooltip, wenn gekürzt.
function kunde_anzeige($kunde_id, ?string $firma): string {
    $firma = (string)$firma;
    $kz = $kunde_id ? kunde_kuerzel((int)$kunde_id) : '';
    if ($kz === '') return $firma !== '' ? h($firma) : '<span class="muted">–</span>';
    return '<span title="' . h($firma) . '">' . h($kz) . '</span>';
}

// PDF-Download-Button (Dokument-Icon + Label). Klar als „hier gibt's ein PDF" erkennbar – statt eines nackten Pfeils.
// $stopRow=true in klickbaren Listenzeilen, damit der Zeilen-Klick nicht mit auslöst.
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
 $opts: ['baseUrl'=>?p=..., 'sort'=>aktuelleSpalte, 'dir'=>'asc|desc', 'rowUrl'=>fn($row)=>href, 'empty'=>text,
         'group'=>fn($row)=>HTML-Zwischenüberschrift (optional; neue Überschrift, sobald sich der Wert ändert –
                  Zeilen müssen dafür schon nach der Gruppe sortiert sein)]
*/
function bx_table(array $cols, array $rows, array $opts = []): void {
    $baseUrl = $opts['baseUrl'] ?? '';
    $sort    = $opts['sort'] ?? '';
    $dir     = ($opts['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
    $rowUrl  = $opts['rowUrl'] ?? null;

    echo '<div class="bx-tablewrap"><table class="bx-table"><thead><tr>';
    foreach ($cols as $key => $c) {
        $numcls = !empty($c['num']) ? ' class="bx-num"' : '';
        $thsty  = !empty($c['th']) ? ' style="' . h($c['th']) . '"' : '';   // optionale Spaltenbreite/-stil
        echo '<th' . $numcls . $thsty . '>';
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
    $group = $opts['group'] ?? null; $lastGroup = null;
    foreach ($rows as $row) {
        if ($group) {
            $g = (string) $group($row);
            if ($g !== $lastGroup) {
                echo '<tr class="bx-grouprow"><td colspan="' . count($cols) . '" style="background:var(--panel-2);padding-top:14px">' . $g . '</td></tr>';
                $lastGroup = $g;
            }
        }
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

// Nachrichten mit dem Kunden an einem Auftrag/Angebot (Rückfragen aus dem Portal + Team-Antworten). Formular schickt
// aktion=kunde_nachricht an die aktuelle Seite; die Seite speichert, mailt den Kunden und leitet zurück.
function kunde_nachricht_panel(int $kunde_id, int $auftrag_id, int $angebot_id, string $dataPanel = ''): string {
    if ($kunde_id <= 0 || !function_exists('kunde_nachrichten')) return '';
    $msgs = kunde_nachrichten($kunde_id, $auftrag_id, $angebot_id);
    $offen = count(array_filter($msgs, fn($m) => $m['akteur'] === 'kunde' && empty($m['gelesen_team'])));
    $o = '<div class="bx-panel" id="kundennachricht"' . ($dataPanel !== '' ? ' data-panel="' . h($dataPanel) . '"' : '') . '>';
    $o .= '<h2 style="margin-top:0">Nachrichten mit dem Kunden' . ($offen ? ' ' . bx_badge($offen . ' neu', 'warn') : '') . '</h2>';
    if (!$msgs) $o .= '<p class="muted" style="margin-top:0">Noch keine Rückfragen. Der Kunde kann im Portal an der Bestellung bzw. am Angebot fragen; Sie können hier auch zuerst schreiben.</p>';
    foreach ($msgs as $m) {
        $team = $m['akteur'] === 'team';
        $o .= '<div style="margin:0 0 10px;padding:10px 12px;border-radius:10px;border:1px solid var(--line);' . ($team ? 'background:var(--panel-2);margin-right:15%' : 'margin-left:15%') . '">'
            . '<div class="muted" style="font-size:12px;margin-bottom:4px">' . ($team ? 'bulkify' . ($m['benutzer'] ? ' · ' . h((string)$m['benutzer']) : '') : 'Kunde' . ($m['benutzer'] ? ' · ' . h((string)$m['benutzer']) : '')) . ' · ' . h(fmt_zeit((string)$m['erstellt'], 'd.m.Y H:i')) . '</div>'
            . nl2br(h((string)$m['text'])) . '</div>';
    }
    $o .= '<form method="post" style="margin-top:10px"><input type="hidden" name="aktion" value="kunde_nachricht">'
        . '<textarea name="text" rows="3" required placeholder="Antwort an den Kunden – geht per E-Mail raus und steht im Portal" style="width:100%"></textarea>'
        . '<div style="margin-top:8px"><button class="btn btn-primary btn-sm" type="submit">Senden</button></div></form></div>';
    return $o;
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

// Produkt (bzw. Artikel/Rezeptur) zu einem Verlaufs-Eintrag – für die Spalte „Produkt" im Aktivitätsverlauf.
// Über den Bezug (ref_typ/ref_id); ohne Bezug über eine Belegnummer im Text (AB-/AN-/PR-/RE-/DR-…). Je Bezug gecacht.
function aktivitaet_produkt(?string $ref_typ, $ref_id, string $text = ''): string {
    static $cache = [];
    $ref_id = (int)$ref_id;
    if ((!$ref_typ || $ref_id <= 0) && preg_match('/\b(AB|AN|PR|RE|DR|DB|DA)-\d+(?:\.\w+)?\b/u', $text, $m)) {
        $nr = preg_replace('/\..*$/', '', $m[0]);
        $tab = ['AB' => 'auftrag', 'DB' => 'auftrag', 'AN' => 'angebot', 'DA' => 'angebot', 'PR' => 'produktionsauftrag', 'RE' => 'beleg', 'DR' => 'beleg'][$m[1]];
        $key = 'nr:' . $nr;
        if (!array_key_exists($key, $cache)) {
            $id = (int) scalar("SELECT id FROM `$tab` WHERE nummer=? ORDER BY id DESC LIMIT 1", [$nr]);
            $cache[$key] = $id ? aktivitaet_produkt($tab, $id) : '';
        }
        return $cache[$key];
    }
    if (!$ref_typ || $ref_id <= 0) return '';
    $key = $ref_typ . ':' . $ref_id;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $n = '';
    try {
    switch ($ref_typ) {
        case 'auftrag':
            $n = (string) scalar("SELECT COALESCE(NULLIF(a.produkt_bezeichnung,''), p.name, d.name, '') FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id
                                  LEFT JOIN dienstleistung d ON d.id=a.dienstleistung_id WHERE a.id=?", [$ref_id]);
            break;
        case 'produktionsauftrag':
            $n = (string) scalar("SELECT COALESCE(p.name, '') FROM produktionsauftrag pa LEFT JOIN produkt p ON p.id=pa.produkt_id WHERE pa.id=?", [$ref_id]);
            break;
        case 'angebot':
            $n = (string) scalar("SELECT COALESCE(
                       (SELECT p.name FROM produkt p WHERE p.id=a.produkt_id),
                       (SELECT p.name FROM angebot_produkt ap JOIN produkt p ON p.id=ap.produkt_id WHERE ap.angebot_id=a.id ORDER BY ap.produkt_id LIMIT 1),
                       (SELECT r.name FROM angebot_position x JOIN rezeptur r ON r.id=x.rezeptur_id WHERE x.angebot_id=a.id AND x.quelle='herstellung' ORDER BY x.id LIMIT 1),
                       (SELECT x.bezeichnung FROM angebot_position x WHERE x.angebot_id=a.id ORDER BY x.id LIMIT 1), '')
                   FROM angebot a WHERE a.id=?", [$ref_id]);
            break;
        case 'rezeptur':  $n = (string) scalar("SELECT name FROM rezeptur WHERE id=?", [$ref_id]); break;
        case 'produkt':   $n = (string) scalar("SELECT name FROM produkt WHERE id=?", [$ref_id]); break;
        case 'item':      $n = (string) scalar("SELECT name FROM item WHERE id=?", [$ref_id]); break;
        case 'beleg':
            $aid = (int) scalar("SELECT COALESCE(auftrag_id,0) FROM beleg WHERE id=?", [$ref_id]);
            $n = $aid ? aktivitaet_produkt('auftrag', $aid) : (string) scalar("SELECT bezeichnung FROM beleg_position WHERE beleg_id=? ORDER BY sort, id LIMIT 1", [$ref_id]);
            break;
        case 'bestellung':
            $pos = all("SELECT COALESCE(NULLIF(bp.bezeichnung,''), i.name) AS n FROM bestellung_position bp LEFT JOIN item i ON i.id=bp.item_id WHERE bp.bestellung_id=? ORDER BY bp.id", [$ref_id]);
            $n = $pos ? (string)$pos[0]['n'] . (count($pos) > 1 ? ' +' . (count($pos) - 1) : '') : '';
            break;
        case 'dokument':
            $d = one("SELECT objekt_typ, objekt_id FROM dokument WHERE id=?", [$ref_id]);
            $n = $d ? aktivitaet_produkt((string)$d['objekt_typ'], (int)$d['objekt_id']) : '';
            break;
    }
    } catch (\Throwable $ex) { $n = ''; }   // Anzeige-Hilfe: nie die Seite sprengen
    return $cache[$key] = trim((string)$n);
}

// Aktivitätsverlauf als TABELLE (Nico 03.11.2026 – der Chat war unübersichtlich): neueste zuerst, je Zeile Datum,
// Ereignis (verlinkt), Produkt, Von (bulkify mit Benutzer / Gegenstelle / System) und Bereich. Darüber ein Suchfeld,
// das die Zeilen im Browser filtert (Produkt, Ereignis, Nummer …). Name bleibt bx_chat (überall eingebunden).
function bx_chat(array $eintraege, string $gegenName = 'Gegenstelle'): void {
    if (!$eintraege) { echo '<div class="muted">Noch keine Aktivitäten.</div>'; return; }
    $bereich = ['auftrag' => 'Auftrag', 'angebot' => 'Angebot', 'rezeptur' => 'Rezeptur', 'produkt' => 'Produkt', 'anfrage' => 'Anfrage',
                'beleg' => 'Rechnung', 'bestellung' => 'Bestellung', 'dokument' => 'Dokument', 'item' => 'Artikel', 'etikett' => 'Etikett',
                'lieferant_anfrage' => 'Lieferanten-Anfrage', 'notiz' => 'Notiz', 'kunde' => 'Kunde'];
    // Produkt kompakt: „Rezeptur · Größe" ohne den Behälter-Teil (voller Name als Tooltip).
    $kurz = function (string $n): string { $t = explode(' · ', $n); return count($t) > 2 ? $t[0] . ' · ' . $t[1] : $n; };
    usort($eintraege, fn($x, $y) => [(string)($y['erstellt'] ?? ''), (int)($y['id'] ?? 0)] <=> [(string)($x['erstellt'] ?? ''), (int)($x['id'] ?? 0)]);
    $uid = 'vl' . substr(md5(uniqid('', true)), 0, 6);
    echo '<div class="bx-verlauf">';
    echo '<input type="search" class="bx-search" id="' . $uid . 'q" placeholder="Verlauf durchsuchen: Produkt, Ereignis, Nummer …" style="max-width:420px;margin:0 0 10px">';
    echo '<div class="bx-tablewrap"><table class="bx-table" id="' . $uid . '"><thead><tr><th style="width:130px">Datum</th><th>Ereignis</th><th>Produkt</th><th>Von</th><th>Bereich</th></tr></thead><tbody>';
    foreach ($eintraege as $e) {
        $akteur = (string)($e['akteur'] ?? 'system');
        $wer = trim((string)($e['benutzer'] ?? ''));
        $von = $akteur === 'team' ? 'bulkify' . ($wer !== '' ? ' · ' . $wer : '') : ($akteur === 'system' ? 'System' : $gegenName . ($wer !== '' ? ' · ' . $wer : ''));
        $ref = (string)($e['ref_typ'] ?? '');
        $typ = (string)($e['typ'] ?? '');
        $ber = $bereich[$ref] ?? ($bereich[$typ] ?? ($typ !== '' ? ucfirst($typ) : '–'));
        $prod = aktivitaet_produkt($ref !== '' ? $ref : null, $e['ref_id'] ?? 0, (string)($e['text'] ?? ''));
        $link = aktivitaet_link($ref !== '' ? $ref : null, $e['ref_id'] ?? 0);
        echo '<tr class="' . ($akteur === 'team' ? 'vl-team' : ($akteur === 'system' ? 'vl-system' : 'vl-gegen')) . '">'
           . '<td style="white-space:nowrap">' . (!empty($e['erstellt']) ? h(fmt_zeit((string)$e['erstellt'], 'd.m.Y H:i')) : '–') . '</td>'
           . '<td style="white-space:normal;min-width:280px">' . ($link ? '<a class="bx-actlink" href="' . h($link) . '">' . h((string)$e['text']) . '</a>' : h((string)$e['text'])) . '</td>'
           . '<td style="white-space:normal;min-width:160px;max-width:280px"' . ($prod !== '' ? ' title="' . h($prod) . '"' : '') . '>' . ($prod !== '' ? h($kurz($prod)) : '<span class="muted">–</span>') . '</td>'
           . '<td style="white-space:nowrap">' . h($von) . '</td>'
           . '<td style="white-space:nowrap">' . h($ber) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<script>(function(){var q=document.getElementById("' . $uid . 'q"),t=document.getElementById("' . $uid . '");if(!q||!t)return;'
       . 'q.addEventListener("input",function(){var s=q.value.trim().toLowerCase();t.querySelectorAll("tbody tr").forEach(function(r){r.style.display=(!s||r.textContent.toLowerCase().indexOf(s)>=0)?"":"none";});});})();</script>';
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
