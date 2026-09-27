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

// Menge huebsch: 3.500 -> "3.500", 250.000 -> "250". Ohne ueberfluessige Nullen.
function menge_txt($m): string {
    if ($m === null || $m === '') return '';
    return rtrim(rtrim(number_format((float)$m, 3, ',', '.'), '0'), ',');
}

// MHD-Datum (Y-m-d) als d.m.Y mit Ampel: rot = abgelaufen, orange = < 60 Tage.
function mhd_html(?string $mhd): string {
    if (!$mhd) return '<span class="muted">–</span>';
    $tage = (int)floor((strtotime($mhd) - strtotime('today')) / 86400);
    $klasse = $tage < 0 ? 'mhd-rot' : ($tage < 60 ? 'mhd-orange' : '');
    $txt = date('d.m.Y', strtotime($mhd));
    if ($tage < 0)      $txt .= ' (abgelaufen)';
    elseif ($tage < 60) $txt .= ' (' . $tage . ' T)';
    return '<span class="' . $klasse . '">' . h($txt) . '</span>';
}

// Status einer Charge als Badge.
function status_badge(?string $s): string {
    return match ((string)$s) {
        'frei'       => '<span class="badge badge-ok">frei</span>',
        'quarantaene'=> '<span class="badge badge-warn">Quarantäne</span>',
        'gesperrt'   => '<span class="badge badge-err">gesperrt</span>',
        'leer'       => '<span class="badge">leer</span>',
        default      => '<span class="badge">' . h((string)$s) . '</span>',
    };
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
