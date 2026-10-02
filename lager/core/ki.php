<?php
// Schlanker KI-Client NUR fuers Lager. Warum eigen und nicht core/ki.php des Dashboards?
// Das Dashboard-ki.php zieht core/schema.php -> core/config.php nach (Dashboard-Kern) und wuerde
// im Lager-Runtime Konstanten doppelt definieren. Das Lager ist (wie das CRM) self-contained:
// eigene db.php, eigenes layout.php - und hier eben ein eigener, abhaengigsfreier KI-Aufruf.
//
// Der Schluessel kommt aus derselben secrets.php, die lager/core/config.php ohnehin laedt
// (Konstante ANTHROPIC_API_KEY) oder aus der Umgebung. Er wird nie geloggt.
//
// Reicht fuer: Lieferschein/Dokument (Foto oder PDF) an Claude schicken und strukturiert
// (JSON) zurueckbekommen. Mehr braucht das Lager nicht.

if (!defined('LG_KI_MODELL'))   define('LG_KI_MODELL', 'claude-opus-5');
if (!defined('LG_KI_VERSION'))  define('LG_KI_VERSION', '2023-06-01');

// Schluessel aus secrets.php-Konstante oder Umgebung. Leer = KI nicht eingerichtet.
function lg_ki_key(): string {
    foreach (['ANTHROPIC_API_KEY', 'ANTHROPIC_KEY', 'AI_API_KEY'] as $k)
        if (defined($k) && trim((string)constant($k)) !== '') return trim((string)constant($k));
    $env = getenv('ANTHROPIC_API_KEY');
    return $env !== false ? trim($env) : '';
}
function lg_ki_bereit(): bool { return lg_ki_key() !== '' && function_exists('curl_init'); }

// Eine Datei (Foto/PDF) als Inhaltsblock fuer die API aufbereiten. Rueckgabe: Block oder null.
function lg_ki_datei_block(string $pfad): ?array {
    if (!is_file($pfad) || filesize($pfad) > 25 * 1024 * 1024) return null;   // 25 MB API-Grenze
    $ext = strtolower(pathinfo($pfad, PATHINFO_EXTENSION));
    $bild = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
    $roh = @file_get_contents($pfad);
    if ($roh === false || $roh === '') return null;
    $daten = base64_encode($roh);
    if ($ext === 'pdf')     return ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $daten]];
    if (isset($bild[$ext])) return ['type' => 'image',    'source' => ['type' => 'base64', 'media_type' => $bild[$ext], 'data' => $daten]];
    return null;
}

// Frage an Claude. $inhalt = fertiges content-Array (Bloecke). Wirft nie.
// Rueckgabe: ['ok'=>true,'text'=>..] oder ['ok'=>false,'fehler'=>..].
function lg_ki_frage(array $inhalt, array $opt = []): array {
    $key = lg_ki_key();
    if ($key === '')                   return ['ok' => false, 'fehler' => 'Kein Anthropic-Schluessel hinterlegt (secrets.php: ANTHROPIC_API_KEY).'];
    if (!function_exists('curl_init')) return ['ok' => false, 'fehler' => 'Die PHP-Erweiterung curl fehlt auf diesem Server.'];

    $payload = [
        'model'      => (string)($opt['modell'] ?? LG_KI_MODELL),
        'max_tokens' => (int)($opt['max_tokens'] ?? 8000),
        'messages'   => [['role' => 'user', 'content' => $inhalt]],
    ];
    if (trim((string)($opt['system'] ?? '')) !== '') $payload['system'] = (string)$opt['system'];

    $timeout = max(20, (int)($opt['timeout'] ?? 180));
    @set_time_limit($timeout + 30);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['content-type: application/json', 'x-api-key: ' . $key, 'anthropic-version: ' . LG_KI_VERSION],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) return ['ok' => false, 'fehler' => 'Verbindungsfehler: ' . $cerr];
    $j = json_decode((string)$resp, true);
    if ($http !== 200) return ['ok' => false, 'fehler' => 'API-Fehler (' . $http . ')' . (($j['error']['message'] ?? '') ? ': ' . $j['error']['message'] : '')];
    if (($j['stop_reason'] ?? '') === 'refusal') return ['ok' => false, 'fehler' => 'Die KI hat die Anfrage abgelehnt.'];
    $text = '';
    foreach ((array)($j['content'] ?? []) as $blk)
        if (($blk['type'] ?? '') === 'text') $text .= (string)($blk['text'] ?? '');
    return ['ok' => true, 'text' => $text];
}

// Wie lg_ki_frage, aber erwartet JSON und gibt zusaetzlich 'daten' (dekodiert) zurueck.
function lg_ki_json(array $inhalt, array $opt = []): array {
    $opt['system'] = trim(($opt['system'] ?? '') . "\n\nAntworte ausschliesslich mit gueltigem JSON, ohne Text davor oder danach, ohne Markdown-Codeblock.");
    $r = lg_ki_frage($inhalt, $opt);
    if (!$r['ok']) return $r;
    $t = trim($r['text']);
    if (str_starts_with($t, '```')) $t = trim(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $t));
    $a = strcspn($t, '{[');
    if ($a < strlen($t)) $t = substr($t, $a);
    $daten = json_decode($t, true);
    if (!is_array($daten)) return ['ok' => false, 'fehler' => 'Die Antwort war kein gueltiges JSON.', 'text' => $r['text']];
    $r['daten'] = $daten;
    return $r;
}

// Einen Lieferschein (ein oder mehrere Fotos/PDFs) auslesen. Rueckgabe:
//   ['ok'=>true, 'lieferant'=>.., 'ls_nr'=>.., 'datum'=>'YYYY-MM-DD', 'positionen'=>[ ... ]]
// Jede Position: ['name','menge'(float),'einheit','charge_nr','mhd'('YYYY-MM-DD'|''),'warenart'].
// warenart ist die KI-Vermutung: rohstoff|verpackung|verbrauch|fertig|kapsel|'' (unklar).
function lg_lieferschein_lesen(array $dateipfade): array {
    if (!lg_ki_bereit()) return ['ok' => false, 'fehler' => 'KI ist nicht eingerichtet (kein Anthropic-Schluessel).'];
    $bloecke = [];
    foreach ($dateipfade as $p) { $b = lg_ki_datei_block((string)$p); if ($b) $bloecke[] = $b; }
    if (!$bloecke) return ['ok' => false, 'fehler' => 'Keine lesbare Datei (Foto als JPG/PNG oder PDF).'];

    $anweisung = <<<TXT
Du liest einen Lieferschein / eine Rechnung eines Wareneingangs fuer einen Nahrungsergaenzungs-Lohnhersteller.
Erfasse exakt, was auf dem Dokument steht - nichts erfinden. Mehrere Fotos koennen ein Dokument (mehrere Seiten) sein.

Gib JSON in genau dieser Form zurueck:
{
  "lieferant": "Firmenname des Absenders/Lieferanten oder \"\"",
  "ls_nr": "Lieferschein- oder Rechnungsnummer oder \"\"",
  "datum": "Lieferdatum als YYYY-MM-DD oder \"\"",
  "positionen": [
    {
      "name": "Artikel-/Produktbezeichnung wie auf dem Beleg",
      "menge": 0,
      "einheit": "kg | g | Stueck | L | ... (wie auf dem Beleg)",
      "charge_nr": "Chargen-/Los-/Batch-Nummer oder \"\"",
      "mhd": "Mindesthaltbarkeit/Verfall als YYYY-MM-DD oder \"\"",
      "warenart": "rohstoff | verpackung | verbrauch | fertig | kapsel | \"\""
    }
  ]
}

Regeln:
- menge ist eine Zahl (Punkt als Dezimaltrennzeichen), ohne Einheit.
- warenart ist deine beste Vermutung aus dem Kontext: Pulver/Extrakt/Vitamin = rohstoff; Glas/Dose/Flasche/Deckel = verpackung; Etikett/Karton/Beutel = verbrauch; fertige Kapseln/Tabletten als Endprodukt = fertig; Leerkapseln = kapsel. Unsicher = "".
- Felder, die nicht auf dem Beleg stehen, als "" bzw. 0 lassen. Keine Positionen erfinden.
TXT;

    $inhalt = $bloecke;
    $inhalt[] = ['type' => 'text', 'text' => $anweisung];
    $r = lg_ki_json($inhalt, ['zweck' => 'lieferschein', 'max_tokens' => 8000]);
    if (!$r['ok']) return $r;
    $d = $r['daten'];

    $positionen = [];
    foreach ((array)($d['positionen'] ?? []) as $p) {
        $name = trim((string)($p['name'] ?? ''));
        if ($name === '') continue;
        $positionen[] = [
            'name'      => $name,
            'menge'     => (float) str_replace(',', '.', (string)($p['menge'] ?? 0)),
            'einheit'   => trim((string)($p['einheit'] ?? '')),
            'charge_nr' => trim((string)($p['charge_nr'] ?? '')),
            'mhd'       => lg_ki_datum((string)($p['mhd'] ?? '')),
            'warenart'  => lg_ki_warenart((string)($p['warenart'] ?? '')),
        ];
    }
    return [
        'ok'         => true,
        'lieferant'  => trim((string)($d['lieferant'] ?? '')),
        'ls_nr'      => trim((string)($d['ls_nr'] ?? '')),
        'datum'      => lg_ki_datum((string)($d['datum'] ?? '')),
        'positionen' => $positionen,
    ];
}

// Datum auf YYYY-MM-DD normalisieren (oder '' wenn leer/unklar).
function lg_ki_datum(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    $t = strtotime($s);
    return $t ? date('Y-m-d', $t) : '';
}
// Warenart auf einen erlaubten Schluessel begrenzen.
function lg_ki_warenart(string $s): string {
    $s = strtolower(trim($s));
    return in_array($s, ['rohstoff', 'verpackung', 'verbrauch', 'fertig', 'kapsel'], true) ? $s : '';
}
