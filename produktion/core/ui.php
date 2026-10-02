<?php
// Kleine Anzeige-Helfer – dieselben Namen wie im Dashboard (h, fmt_zeit), damit nichts umzudenken ist.
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fmt_zeit(?string $utc, string $fmt = 'd.m.Y H:i'): string {
    if (!$utc) return '';
    try {
        $dt = new DateTime($utc, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Europe/Berlin'));
        return $dt->format($fmt);
    } catch (Exception $e) { return $utc; }
}
function menge_txt($m): string {
    if ($m === null || $m === '') return '';
    return rtrim(rtrim(number_format((float)$m, 3, ',', '.'), '0'), ',');
}
function weiter(string $ziel): never { header('Location: ' . $ziel); exit; }
function json_antwort(array $daten, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($daten, JSON_UNESCAPED_UNICODE); exit;
}

// Kurze Rückmeldung, überlebt genau eine Weiterleitung.
function flash(string $text, string $art = 'ok'): void { $_SESSION['pr_flash'] = [$text, $art]; }
function flash_zeigen(): void {
    if (empty($_SESSION['pr_flash'])) return;
    [$text, $art] = $_SESSION['pr_flash']; unset($_SESSION['pr_flash']);
    hinweis($text, $art);
}
function hinweis(string $text, string $art = 'ok'): void {
    echo '<div class="bx-panel ' . ($art === 'warn' ? 'warn' : 'badge-ok') . '" style="padding:12px 16px">' . h($text) . '</div>';
}
function seitenkopf(string $titel, string $unter = '', string $aktion = ''): void {
    echo '<div class="bx-head"><div><h1>' . h($titel) . '</h1>';
    if ($unter !== '') echo '<p class="bx-sub">' . h($unter) . '</p>';
    echo '</div>';
    if ($aktion !== '') echo '<div class="bx-row">' . $aktion . '</div>';
    echo '</div>';
}
// Produktionsbereitschaft als Badge: ist der Auftrag produzierbar? (aus erp_pa_bereitschaft()['status']).
function bereit_badge(string $s): string {
    return match ($s) {
        'bereit' => '<span class="badge badge-ok">produzierbar</span>',
        'wartet' => '<span class="badge badge-warn">wartet auf Material</span>',
        'laeuft' => '<span class="badge badge-info">in Produktion</span>',
        'fertig' => '<span class="badge badge-ok">abgeschlossen</span>',
        default  => '<span class="badge">' . h($s) . '</span>',
    };
}

// Status eines Produktionsauftrags als Badge. Werte wie im Dashboard: offen/laufend/erledigt
// (ältere Varianten in_arbeit/fertig bleiben der Robustheit halber abgedeckt).
function pa_badge(?string $s): string {
    return match ((string)$s) {
        'offen'               => '<span class="badge badge-warn">offen</span>',
        'laufend', 'in_arbeit' => '<span class="badge badge-info">in Arbeit</span>',
        'erledigt', 'fertig'   => '<span class="badge badge-ok">erledigt</span>',
        'abgebrochen'         => '<span class="badge badge-err">abgebrochen</span>',
        default               => '<span class="badge">' . h((string)$s) . '</span>',
    };
}
