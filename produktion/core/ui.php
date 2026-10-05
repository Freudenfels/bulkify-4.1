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
// Eine Dauer in Sekunden menschenlesbar machen (Sek/Min/Std/Tage). null -> „–".
function dauer_txt(?float $sek): string {
    if ($sek === null) return '–';
    $nz = fn(float $x) => rtrim(rtrim(number_format($x, 1, ',', '.'), '0'), ',');
    if ($sek < 60)        return round($sek) . ' Sek';
    if ($sek < 3600)      return $nz($sek / 60) . ' Min';
    if ($sek < 48 * 3600) return $nz($sek / 3600) . ' Std';
    return $nz($sek / 86400) . ' Tage';
}
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
// Kurze Arbeitsanweisung je Station (für den Produktionsmodus). Scan-Hinweise bewusst weggelassen –
// es gibt noch keine Etiketten/Barcodes; Material wird beim Abschließen nach FEFO abgebucht.
function station_anleitung_text(string $station): string {
    return match ($station) {
        'Rohstoffe bereitstellen'  => 'Benötigte Rohstoffe nach FEFO aus dem Lager holen und bereitstellen.',
        'Fertigware bereitstellen' => 'Zugekaufte fertige Bulkware bereitstellen.',
        'Mischen'                  => 'Rohstoffe gemäß Rezeptur gründlich und homogen mischen.',
        'Verkapselung'             => 'Kapseln befüllen.',
        'Tablettierung'            => 'Tabletten gemäß Vorgabe pressen.',
        'Softgel-Herstellung'      => 'Softgels herstellen.',
        'Stick-Abfüllung'          => 'Sticks abfüllen.',
        'Pulver-Abfüllung'         => 'Pulver abfüllen.',
        'Abfüllung'                => 'Produkt abfüllen.',
        'Verpacken'                => 'Produkt in die Verpackung abfüllen.',
        'Etikettieren'             => 'Alle Gebinde korrekt etikettieren (Charge, MHD, Kennzeichnung).',
        'Beipackzettel beilegen'   => 'Beipackzettel/Booklet beilegen.',
        'Umkarton'                 => 'Produkt in den Karton/die Umverpackung legen.',
        'Zwischenkontrolle'        => 'Zwischenkontrolle durchführen und das Kontrollgewicht erfassen.',
        'Rückstellmuster ziehen'   => 'Rückstellmuster und Labormuster ziehen und die Mengen erfassen.',
        'Qualitätsprüfung'         => 'Aussehen, Füllmenge, Dichtigkeit und Kennzeichnung prüfen.',
        'Produktions-Freigabe'     => 'Produktion kontrollieren und freigeben.',
        'Versand-Freigabe'         => 'Auftrag zum Versand freigeben.',
        default                    => '',
    };
}

// Welche Werte werden an einer Station erfasst? (Eigenproduktion-Datenerfassung.)
// Rückgabe: Liste [['feld','label','einheit'], …].
function pr_station_felder(string $station): array {
    return match ($station) {
        'Mischen'                => [['feld'=>'mischmenge', 'label'=>'Gemischte Menge', 'einheit'=>'kg']],
        'Zwischenkontrolle'      => [['feld'=>'kontrolle_gewicht', 'label'=>'Kontrollgewicht', 'einheit'=>'g']],
        // Rückstellmuster/Laborprobe werden auf der QS-Seite (?p=qs) erfasst, nicht als Schnellfeld.
        default                  => [],
    };
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
