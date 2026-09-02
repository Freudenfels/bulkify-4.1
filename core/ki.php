<?php
// Anbindung an die Anthropic-API (Claude) – wie der Mailversand ohne Composer, direkt über curl.
// Der Schlüssel steht in `secrets.php` im Projektstamm (gitignored, nie committen):
//     define('ANTHROPIC_API_KEY', 'sk-ant-...');
// Alternativ die Umgebungsvariable ANTHROPIC_API_KEY. Der Schlüssel wird nie geloggt.
//
// Jede Anfrage landet zusätzlich in `data/ki.log` (Modell, Dauer, Tokens, gekürzte Antwort) –
// damit nachvollziehbar bleibt, was die KI gesagt hat und was sie gekostet hat.
require_once __DIR__ . '/schema.php';

// Standardmodell für alle KI-Funktionen. Das schnelle ist nur für einfache Arbeiten gedacht
// (Übersetzen, Umformulieren) und muss je Aufruf ausdrücklich gewählt werden.
if (!defined('KI_MODELL'))         define('KI_MODELL', 'claude-opus-5');
if (!defined('KI_MODELL_SCHNELL')) define('KI_MODELL_SCHNELL', 'claude-haiku-4-5');
if (!defined('KI_VERSION'))        define('KI_VERSION', '2023-06-01');   // Header anthropic-version

// Der Schlüssel – aus secrets.php (Konstante) oder aus der Umgebung. Leer = nicht eingerichtet.
function ki_key(): string {
    foreach (['ANTHROPIC_API_KEY', 'ANTHROPIC_KEY', 'AI_API_KEY'] as $k)
        if (defined($k) && trim((string)constant($k)) !== '') return trim((string)constant($k));
    $env = getenv('ANTHROPIC_API_KEY');
    return $env !== false ? trim($env) : '';
}
// Ist die KI einsatzbereit? Steuert Knöpfe und Hinweise in der Oberfläche.
function ki_bereit(): bool { return ki_key() !== '' && function_exists('curl_init'); }

// Was die Oberfläche über die Einrichtung anzeigen darf – ohne den Schlüssel zu verraten.
function ki_status(): array {
    $key = ki_key();
    return [
        'bereit'  => ki_bereit(),
        'quelle'  => $key === '' ? '' : (defined('ANTHROPIC_API_KEY') || defined('ANTHROPIC_KEY') || defined('AI_API_KEY') ? 'secrets.php' : 'Umgebungsvariable'),
        'endet'   => $key === '' ? '' : '…' . mb_substr($key, -4),   // nur die letzten vier Zeichen
        'modell'  => KI_MODELL,
        'curl'    => function_exists('curl_init'),
    ];
}

// Eine Frage an Claude. Rückgabe:
//   ['ok'=>true,  'text'=>'…', 'usage'=>['ein'=>n,'aus'=>n], 'modell'=>'…', 'ms'=>n]
//   ['ok'=>false, 'fehler'=>'Klartext', 'http'=>n]
// Wirft nie – ein Fehler darf keinen Vorgang abbrechen (wie beim Mailversand).
//
// $inhalt ist der Text der Nutzer-Nachricht oder ein fertiges content-Array (z. B. mit Dokument).
// $opt: modell, max_tokens, system, denken (true = adaptiv), aufwand (low|medium|high|xhigh|max),
//       timeout, versuche, zweck (nur fürs Log).
function ki_frage(string|array $inhalt, array $opt = []): array {
    $start = microtime(true);
    $key = ki_key();
    if ($key === '')                  return ['ok'=>false, 'fehler'=>'Kein Anthropic-Schlüssel hinterlegt (secrets.php: ANTHROPIC_API_KEY).'];
    if (!function_exists('curl_init')) return ['ok'=>false, 'fehler'=>'Die PHP-Erweiterung curl fehlt auf diesem Server.'];

    $modell = (string)($opt['modell'] ?? KI_MODELL);
    $payload = [
        'model'      => $modell,
        'max_tokens' => (int)($opt['max_tokens'] ?? 8000),
        'messages'   => [['role' => 'user', 'content' => $inhalt]],
    ];
    if (trim((string)($opt['system'] ?? '')) !== '') $payload['system'] = (string)$opt['system'];
    // Adaptives Denken: Claude entscheidet selbst, wie viel Nachdenken die Aufgabe braucht.
    if (!empty($opt['denken'])) $payload['thinking'] = ['type' => 'adaptive'];
    // Aufwand steuert Tiefe und Tokenverbrauch – Standard ist hoch, „low" reicht für einfache Arbeiten.
    if (!empty($opt['aufwand'])) $payload['output_config'] = ['effort' => (string)$opt['aufwand']];

    $versuche = max(1, (int)($opt['versuche'] ?? 3));
    $timeout  = max(10, (int)($opt['timeout'] ?? 180));
    $letzter  = '';
    for ($n = 1; $n <= $versuche; $n++) {
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['content-type: application/json', 'x-api-key: ' . $key, 'anthropic-version: ' . KI_VERSION],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        $resp = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            $letzter = 'Verbindungsfehler: ' . $cerr;
        } else {
            $j = json_decode((string)$resp, true);
            if ($http === 200) {
                $text = '';
                foreach ((array)($j['content'] ?? []) as $blk)
                    if (($blk['type'] ?? '') === 'text') $text .= (string)($blk['text'] ?? '');
                $ms = (int) round((microtime(true) - $start) * 1000);
                $usage = ['ein' => (int)($j['usage']['input_tokens'] ?? 0), 'aus' => (int)($j['usage']['output_tokens'] ?? 0)];
                // Eine Absage ist kein Fehler, aber auch keine Antwort – sie muss sichtbar sein.
                if (($j['stop_reason'] ?? '') === 'refusal') {
                    ki_log($opt['zweck'] ?? '', $modell, $ms, $usage, 'ABGELEHNT: ' . (string)($j['stop_details']['explanation'] ?? ''));
                    return ['ok'=>false, 'fehler'=>'Die KI hat die Anfrage abgelehnt' . (($j['stop_details']['category'] ?? '') ? ' (' . $j['stop_details']['category'] . ')' : '') . '.'];
                }
                ki_log($opt['zweck'] ?? '', $modell, $ms, $usage, $text);
                return ['ok'=>true, 'text'=>$text, 'usage'=>$usage, 'modell'=>$modell, 'ms'=>$ms];
            }
            $letzter = 'API-Fehler (' . $http . ')' . (($j['error']['message'] ?? '') ? ': ' . $j['error']['message'] : '');
            // 400/401/403 sind unsere Schuld – ein zweiter Versuch ändert daran nichts.
            if ($http < 429 && $http !== 408) break;
        }
        if ($n < $versuche) sleep($n * 2);   // kurz warten, dann noch einmal (Überlast/Limit)
    }
    ki_log($opt['zweck'] ?? '', $modell, (int) round((microtime(true) - $start) * 1000), null, 'FEHLER: ' . $letzter);
    return ['ok'=>false, 'fehler'=>$letzter ?: 'Unbekannter Fehler.'];
}

// Eine Datei als Inhaltsblock für ki_frage/ki_json aufbereiten. PDFs gehen als Dokument an die API –
// damit liest Claude auch eingescannte Unterlagen, an denen die reine Textsuche scheitert.
// Bilder gehen als Bild. Rückgabe: Block-Array oder null (nicht lesbar / zu groß).
function ki_datei_block(string $pfad): ?array {
    if (!is_file($pfad) || filesize($pfad) > 25 * 1024 * 1024) return null;   // 25 MB Grenze der API
    $ext = strtolower(pathinfo($pfad, PATHINFO_EXTENSION));
    $bild = ["png" => "image/png", "jpg" => "image/jpeg", "jpeg" => "image/jpeg", "gif" => "image/gif", "webp" => "image/webp"];
    $roh = @file_get_contents($pfad);
    if ($roh === false || $roh === "") return null;
    $daten = base64_encode($roh);
    if ($ext === "pdf")            return ["type" => "document", "source" => ["type" => "base64", "media_type" => "application/pdf", "data" => $daten]];
    if (isset($bild[$ext]))        return ["type" => "image",    "source" => ["type" => "base64", "media_type" => $bild[$ext], "data" => $daten]];
    return null;
}

// Eine Datei plus Anweisung an Claude schicken. Das Dokument steht VOR dem Text – so empfiehlt es
// die API, und die Antworten werden nachweislich besser.
//
// Tabellen (CSV, TXT, Excel) kann die API nicht als Datei entgegennehmen. Sie werden deshalb
// hier ausgelesen und als Text mitgeschickt – für die KI ist das sogar die klarere Form.
function ki_datei_frage(string $pfad, string $anweisung, array $opt = []): array {
    require_once __DIR__ . '/tabelle_lesen.php';
    if (ist_tabelle($pfad)) {
        $txt = tabelle_text($pfad);
        if ($txt === null || trim($txt) === '')
            return ["ok" => false, "fehler" => "Die Tabelle ist leer oder lässt sich nicht lesen. Bei .xls bitte als .xlsx oder .csv speichern."];
        $inhalt = [["type" => "text", "text" => "Inhalt der Datei \"" . basename($pfad) . "\" (Spalten mit Tabulator getrennt):\n\n" . $txt],
                   ["type" => "text", "text" => $anweisung]];
        return empty($opt["json"]) ? ki_frage($inhalt, $opt) : ki_json($inhalt, $opt);
    }
    $block = ki_datei_block($pfad);
    if (!$block) return ["ok" => false, "fehler" => "Die Datei lässt sich nicht lesen (PDF, Bild, CSV oder Excel; .xls bitte vorher als .xlsx speichern)."];
    $inhalt = [$block, ["type" => "text", "text" => $anweisung]];
    return empty($opt["json"]) ? ki_frage($inhalt, $opt) : ki_json($inhalt, $opt);
}

// Antwort als JSON erwarten. Claude schreibt gern noch einen Satz drumherum, deshalb wird der
// erste vollständige JSON-Block herausgeschnitten. Rückgabe wie ki_frage, zusätzlich 'daten'.
function ki_json(string|array $inhalt, array $opt = []): array {
    $opt['system'] = trim(($opt['system'] ?? '') . "\n\nAntworte ausschließlich mit gültigem JSON, ohne Text davor oder danach, ohne Markdown-Codeblock.");
    $r = ki_frage($inhalt, $opt);
    if (!$r['ok']) return $r;
    $t = trim($r['text']);
    if (str_starts_with($t, '```')) $t = trim(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $t));
    $a = strcspn($t, '{[');
    if ($a < strlen($t)) $t = substr($t, $a);
    $daten = json_decode($t, true);
    if (!is_array($daten)) return ['ok'=>false, 'fehler'=>'Die Antwort war kein gültiges JSON.', 'text'=>$r['text']];
    $r['daten'] = $daten;
    return $r;
}

// Mitschrift nach data/ki.log – ohne Schlüssel, Antwort gekürzt.
function ki_log(string $zweck, string $modell, int $ms, ?array $usage, string $text): void {
    if (!is_dir(BX_DATA)) @mkdir(BX_DATA, 0775, true);
    $zeile = gmdate('c') . '  ' . ($zweck !== '' ? $zweck : '-') . '  ' . $modell . '  ' . $ms . 'ms'
           . ($usage ? '  ein=' . $usage['ein'] . ' aus=' . $usage['aus'] : '')
           . "\n" . mb_substr(trim($text), 0, 2000) . "\n----\n";
    @file_put_contents(BX_DATA . '/ki.log', $zeile, FILE_APPEND);
}
