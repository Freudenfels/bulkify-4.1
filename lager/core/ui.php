<?php
// Kleine Helfer fuer die Anzeige. Bewusst dieselben Namen wie im Dashboard (h, fmt_zeit).

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Zeit wird immer in UTC gespeichert und in Berliner Zeit angezeigt.
function fmt_zeit(?string $utc, string $fmt = 'd.m.Y H:i'): string {
    if (!$utc) return '';
    try {
        $dt = new DateTime($utc, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Europe/Berlin'));
        return $dt->format($fmt);
    } catch (Exception $e) { return $utc; }
}

// "vor 4 s", "vor 3 min", "vor 2 h" - fuer "Bruecke zuletzt gemeldet".
function vor_wann(?string $utc): string {
    if (!$utc) return 'noch nie';
    $s = time() - strtotime($utc . ' UTC');
    if ($s < 60)    return 'vor ' . max(0, $s) . ' s';
    if ($s < 3600)  return 'vor ' . intdiv($s, 60) . ' min';
    if ($s < 86400) return 'vor ' . intdiv($s, 3600) . ' h';
    return fmt_zeit($utc);
}

// Kurze Rueckmeldung nach einer Aktion - ueberlebt genau eine Weiterleitung.
function flash(string $text, string $art = 'ok'): void { $_SESSION['lg_flash'] = [$text, $art]; }
function flash_zeigen(): void {
    if (empty($_SESSION['lg_flash'])) return;
    [$text, $art] = $_SESSION['lg_flash'];
    unset($_SESSION['lg_flash']);
    hinweis($text, $art);
}

function weiter(string $ziel): never { header('Location: ' . $ziel); exit; }

// Antwort fuer die Knoepfe, die per fetch() arbeiten (z. B. "Leuchten").
function json_antwort(array $daten, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($daten, JSON_UNESCAPED_UNICODE);
    exit;
}
