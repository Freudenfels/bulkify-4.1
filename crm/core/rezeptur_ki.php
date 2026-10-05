<?php
// KI-Rezepturvorschlag direkt im CRM - aus dem Anfrage-Text eines Leads einen herstellbaren,
// verkehrsfaehigen Vorschlag entwickeln (Zutaten + Mengen, Novel Food, Hoechstmengen, Health Claims,
// Machbarkeit, dazu die passende Kapselgroesse aus UNSERER Rechnung).
//
// Uebernimmt Prompt und Logik aus der Dashboard-Version (core/rezeptur_ki.php), liest Katalog,
// Rohstoff-Zuordnung und Kapselgroessen aber ueber die EINE Naht (core/erp.php) - das CRM fasst
// Dashboard-Tabellen nirgends direkt an. Prompt pflegbar in crm/prompts/rezepturvorschlag.md.
//
// WICHTIG: Entwurf fuer das Team, kein Freigabedokument. Gespeichert wird der Vorschlag nur am
// CRM-Vorgang (crm_rezeptur_ki). Die rechtliche Bewertung prueft ein Mensch; eine echte Rezeptur
// entsteht weiterhin im Dashboard (Knopf "Rezeptur anlegen").
require_once __DIR__ . '/ki.php';
require_once __DIR__ . '/erp.php';
require_once __DIR__ . '/schema.php';

function rezeptur_ki_formen(): array {
    return ['kapsel' => 'Kapseln', 'tablette' => 'Tabletten', 'softgel' => 'Softgels',
            'pulver' => 'Pulver', 'stick' => 'Sticks', 'fluessig' => 'Flüssig'];
}

function rezeptur_ki_prompt(string $form, array $katalog): string {
    $p = BX_ROOT . '/prompts/rezepturvorschlag.md';
    $basis = is_file($p) ? (string) file_get_contents($p) : 'Entwickle einen Rezepturvorschlag und gib NUR JSON zurueck.';
    $formLbl = rezeptur_ki_formen()[$form] ?? $form;
    $liste = $katalog ? "\n- " . implode("\n- ", array_values($katalog)) : ' (noch keiner hinterlegt)';
    return $basis . "\n\nDarreichungsform: " . $formLbl
         . "\n\nDiese Rohstoffe haben wir im Katalog:" . $liste;
}

// Aus freiem Anfrage-Text einen Vorschlag entwickeln. Rueckgabe ['ok'=>bool, ... , 'fehler'=>string].
function rezeptur_ki_entwickeln(string $text, string $form = 'kapsel'): array {
    $text = trim($text);
    if ($text === '')  return ['ok' => false, 'fehler' => 'Kein Anfrage-Text da, aus dem sich ein Vorschlag bauen lässt.'];
    if (!ki_bereit())  return ['ok' => false, 'fehler' => 'Die KI ist nicht eingerichtet.'];
    if (!array_key_exists($form, rezeptur_ki_formen())) $form = 'kapsel';

    $katalog = erp_rohstoff_katalog();
    $r = ki_json("Anfrage / Idee des Kunden:\n" . mb_substr($text, 0, 8000), [
        'system'     => rezeptur_ki_prompt($form, $katalog),
        'denken'     => true,
        'aufwand'    => 'high',
        'max_tokens' => 12000,
        'timeout'    => 300,
        'budget'     => 330,
        'zweck'      => 'rezeptur-entwickeln',
    ]);
    if (empty($r['ok']))                return ['ok' => false, 'fehler' => (string)($r['fehler'] ?? 'Fehler')];
    if (!is_array($r['daten'] ?? null)) return ['ok' => false, 'fehler' => 'Die Antwort war nicht lesbar.'];
    $d = $r['daten'];

    // Zutaten aufbereiten und - wo moeglich - auf unseren Katalog abbilden (ueber die Naht).
    $katUmgekehrt = [];
    foreach ($katalog as $iid => $nm) $katUmgekehrt[mb_strtolower($nm)] = $iid;
    $zutaten = []; $summe = 0.0;
    foreach ((array)($d['zutaten'] ?? []) as $z) {
        $bez = trim((string)($z['bezeichnung'] ?? ''));
        if ($bez === '') continue;
        $mg  = (float) str_replace(',', '.', (string)($z['menge_mg'] ?? 0));
        $kat = trim((string)($z['katalog'] ?? ''));
        $iid = $kat !== '' ? ($katUmgekehrt[mb_strtolower($kat)] ?? null) : null;
        if (!$iid) $iid = erp_rohstoff_finden($bez);
        $info = $iid ? erp_item_info((int)$iid) : ['name' => '', 'cas' => ''];
        $zutaten[] = [
            'bezeichnung' => mb_substr($bez, 0, 190),
            'menge_mg'    => $mg,
            'item_id'     => $iid ?: null,
            'item_name'   => $iid ? ($katalog[$iid] ?? $info['name']) : '',
            'cas'         => $info['cas'],
            'funktion'    => mb_substr(trim((string)($z['funktion'] ?? '')), 0, 190),
            'begruendung' => mb_substr(trim((string)($z['begruendung'] ?? '')), 0, 400),
        ];
        $summe += $mg;
    }

    // Kapselgroesse rechnen wir selbst - aus unseren Groessen, ueber die Naht.
    $kapsel = in_array($form, ['kapsel', 'softgel'], true) ? erp_kapsel_passend($summe) : null;

    return [
        'ok'            => true,
        'name'          => mb_substr(trim((string)($d['name'] ?? '')), 0, 190),
        'form'          => $form,
        'zutaten'       => $zutaten,
        'summe_mg'      => round($summe, 1),
        'tagesdosis'    => mb_substr(trim((string)($d['tagesdosis'] ?? '')), 0, 190),
        'novel_food'    => rezeptur_ki_liste($d['novel_food'] ?? [], ['stoff', 'bewertung', 'begruendung']),
        'hoechstmengen' => rezeptur_ki_liste($d['hoechstmengen'] ?? [], ['stoff', 'menge_mg', 'bewertung', 'begruendung']),
        'health_claims' => rezeptur_ki_liste($d['health_claims'] ?? [], ['stoff', 'claim', 'zulaessig']),
        'machbarkeit'   => [
            'bewertung' => mb_substr(trim((string)($d['machbarkeit']['bewertung'] ?? '')), 0, 40),
            'gruende'   => array_slice(array_map(fn($g) => mb_substr(trim((string)$g), 0, 300), (array)($d['machbarkeit']['gruende'] ?? [])), 0, 8),
        ],
        'kapsel'        => $kapsel,
        'hinweise'      => array_slice(array_map(fn($g) => mb_substr(trim((string)$g), 0, 300), (array)($d['hinweise'] ?? [])), 0, 8),
        'modell'        => $r['modell'] ?? '',
        'stand'         => gmdate('c'),
    ];
}

function rezeptur_ki_liste($roh, array $felder): array {
    $out = [];
    foreach ((array)$roh as $z) {
        $zeile = [];
        foreach ($felder as $f) { $v = $z[$f] ?? ''; $zeile[$f] = is_bool($v) ? $v : mb_substr(trim((string)$v), 0, 400); }
        if (trim((string)($zeile[$felder[0]] ?? '')) === '') continue;
        $out[] = $zeile;
    }
    return array_slice($out, 0, 30);
}

// --- Merken / Holen / Loeschen (eigene Tabelle, wie der Fragenkatalog) --------------------------
function rezeptur_ki_merken(string $typ, int $id, array $vorschlag): void {
    if ($id <= 0 || empty($vorschlag['ok'])) return;
    q("INSERT INTO crm_rezeptur_ki (bezug_typ, bezug_id, inhalt, modell, stand) VALUES (?,?,?,?,?)
       ON DUPLICATE KEY UPDATE inhalt=VALUES(inhalt), modell=VALUES(modell), stand=VALUES(stand)",
      [$typ, $id, json_encode($vorschlag, JSON_UNESCAPED_UNICODE), (string)($vorschlag['modell'] ?? KI_MODELL), gmdate('Y-m-d H:i:s')]);
}
function rezeptur_ki_vorschlag(string $typ, int $id): ?array {
    $row = one("SELECT * FROM crm_rezeptur_ki WHERE bezug_typ=? AND bezug_id=?", [$typ, $id]);
    if (!$row) return null;
    $d = json_decode((string)$row['inhalt'], true);
    if (!is_array($d)) return null;
    $d['_stand'] = (string)$row['stand'];
    return $d;
}
function rezeptur_ki_loeschen(string $typ, int $id): void {
    q("DELETE FROM crm_rezeptur_ki WHERE bezug_typ=? AND bezug_id=?", [$typ, $id]);
}

// --- Anzeige ------------------------------------------------------------------------------------
// Den Vorschlag als HTML rendern (keine Emojis, Feldueberschriften nicht fett).
function rezeptur_ki_html(array $v): string {
    $bw = function (string $s): string {
        $s = mb_strtolower($s);
        $rot  = ['zu hoch', 'novel_food', 'nicht machbar'];
        $gelb = ['pruefen', 'prüfen', 'nahe der obergrenze', 'kritisch'];
        $farbe = in_array($s, $rot, true) ? '#c0392b' : (in_array($s, $gelb, true) ? '#e08a1e' : '#1D9E75');
        return '<span style="color:' . $farbe . ';font-weight:600">' . h($s) . '</span>';
    };
    $o = '';
    if (($v['name'] ?? '') !== '')       $o .= '<p style="margin:0 0 4px"><strong>' . h((string)$v['name']) . '</strong></p>';
    if (($v['tagesdosis'] ?? '') !== '') $o .= '<p class="muted" style="margin:0 0 10px">' . h((string)$v['tagesdosis']) . '</p>';

    // Kapsel-Rechnung (unsere Tatsache).
    if (!empty($v['kapsel']) && is_array($v['kapsel'])) {
        $k = $v['kapsel'];
        if (!empty($k['passt'])) {
            $o .= '<p style="margin:0 0 10px">Füllgewicht ' . h(number_format((float)$k['fuellgewicht_mg'], 1, ',', '.')) . ' mg · passt in Kapselgröße <strong>' . h((string)$k['groesse']) . '</strong></p>';
        } else {
            $o .= '<p style="margin:0 0 10px">Füllgewicht ' . h(number_format((float)$k['fuellgewicht_mg'], 1, ',', '.')) . ' mg · passt in keine Standardgröße (größte: ' . h((string)$k['groesste']) . ', ' . h(number_format((float)$k['groesste_mg'], 0, ',', '.')) . ' mg). Auf mehr Einheiten verteilen.</p>';
        }
    }

    // Zutaten.
    if (!empty($v['zutaten'])) {
        $o .= '<h3 style="margin:14px 0 6px;font-size:var(--fs-sm)">Zutaten je Einheit</h3><div class="crm-reztab">';
        foreach ($v['zutaten'] as $z) {
            $menge = rtrim(rtrim(number_format((float)$z['menge_mg'], 2, ',', '.'), '0'), ',');
            $o .= '<div class="crm-zeile"><div class="crm-mitte"><span class="titel" style="font-weight:400">'
                . h((string)$z['bezeichnung']) . ' · ' . h($menge) . ' mg</span><span class="unter">'
                . (($z['item_id'] ?? null) ? 'im Katalog: ' . h((string)$z['item_name']) . (($z['cas'] ?? '') !== '' ? ' (CAS ' . h((string)$z['cas']) . ')' : '') : 'nicht im Katalog')
                . (($z['funktion'] ?? '') !== '' ? ' · ' . h((string)$z['funktion']) : '') . '</span></div></div>';
        }
        $o .= '</div>';
    }

    $block = function (string $titel, array $zeilen, callable $fmt) use (&$o) {
        if (!$zeilen) return;
        $o .= '<h3 style="margin:14px 0 6px;font-size:var(--fs-sm)">' . h($titel) . '</h3>';
        foreach ($zeilen as $z) $o .= '<p class="muted" style="margin:0 0 6px">' . $fmt($z) . '</p>';
    };
    $block('Novel Food', (array)($v['novel_food'] ?? []), fn($z) => h((string)$z['stoff']) . ': ' . $bw((string)$z['bewertung']) . (($z['begruendung'] ?? '') !== '' ? ' – ' . h((string)$z['begruendung']) : ''));
    $block('Höchstmengen', (array)($v['hoechstmengen'] ?? []), fn($z) => h((string)$z['stoff']) . (($z['menge_mg'] ?? '') !== '' ? ' (' . h((string)$z['menge_mg']) . ' mg)' : '') . ': ' . $bw((string)$z['bewertung']) . (($z['begruendung'] ?? '') !== '' ? ' – ' . h((string)$z['begruendung']) : ''));
    $block('Health Claims', (array)($v['health_claims'] ?? []), fn($z) => h((string)$z['stoff']) . (($z['claim'] ?? '') !== '' ? ' – „' . h((string)$z['claim']) . '"' : '') . ': ' . (empty($z['zulaessig']) ? '<span style="color:#c0392b;font-weight:600">nicht zulässig</span>' : '<span style="color:#1D9E75;font-weight:600">zulässig</span>'));

    if (!empty($v['machbarkeit']['bewertung'])) {
        $o .= '<h3 style="margin:14px 0 6px;font-size:var(--fs-sm)">Machbarkeit</h3><p style="margin:0 0 6px">' . $bw((string)$v['machbarkeit']['bewertung']) . '</p>';
        foreach ((array)($v['machbarkeit']['gruende'] ?? []) as $g) $o .= '<p class="muted" style="margin:0 0 4px">' . h((string)$g) . '</p>';
    }
    if (!empty($v['hinweise'])) {
        $o .= '<h3 style="margin:14px 0 6px;font-size:var(--fs-sm)">Hinweise</h3>';
        foreach ((array)$v['hinweise'] as $g) $o .= '<p class="muted" style="margin:0 0 4px">' . h((string)$g) . '</p>';
    }
    $o .= '<p class="muted" style="margin:14px 0 0;font-size:var(--fs-sm)">Entwurf fürs Team – Novel Food, Höchstmengen und Claims muss ein Mensch prüfen. Keine Freigabe.</p>';
    return $o;
}
