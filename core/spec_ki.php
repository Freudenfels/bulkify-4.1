<?php
// Lieferantenunterlagen (Spezifikation oder Analysenzertifikat) mit Claude auslesen.
//
// Warum die KI und nicht die Textsuche: `core/coa_lesen.php` sucht im PDF-Text nach Stichworten.
// Das scheitert an eingescannten Unterlagen (kein Text) und an jedem Layout, das nicht ins Muster
// passt. Die API bekommt das PDF als Dokument – sie liest auch Scans und versteht Tabellen.
//
// WICHTIG: Diese Funktionen SCHLAGEN nur vor. Gespeichert wird erst, wenn ein Mensch geprüft hat.
require_once __DIR__ . '/ki.php';
require_once __DIR__ . '/coa_lesen.php';

// Die Stammdatenfelder eines Rohstoffs, die in einer Spezifikation stehen können.
// Schlüssel = Spalte in `item`, Wert = [Klartext für die KI, Art].
function spec_ki_felder(): array {
    return [
        'name'             => ['Handelsname/Bezeichnung des Rohstoffs', 'text'],
        'name_en'          => ['Englische Bezeichnung', 'text'],
        'name_lat'         => ['Lateinische/botanische Bezeichnung (z. B. Curcuma longa L.)', 'text'],
        'synonym'          => ['Synonym oder Kurzname', 'text'],
        'bot_quelle'       => ['Botanische Quelle mit Pflanzenteil (z. B. Curcuma longa, Wurzelstock)', 'text'],
        'cas'              => ['CAS-Nummer', 'text'],
        'ec_nr'            => ['EC- oder E-Nummer', 'text'],
        'herkunftsland'    => ['Herkunftsland des Rohstoffs', 'text'],
        'haltbarkeit'      => ['Mindesthaltbarkeit ab Herstellung (z. B. "24 Monate")', 'text'],
        'lagerbedingungen' => ['Lagerbedingungen (Temperatur, Feuchte, Licht)', 'text'],
        'zusaetze'         => ['Zusätze, Trägerstoffe, Fließmittel', 'text'],
        'allergene'        => ['Allergene; wenn ausdrücklich keine, dann "keine"', 'text'],
        'zertifikate'      => ['Zertifikate (z. B. Bio, Kosher, Halal, ISO, HACCP)', 'text'],
        'spec_nr'          => ['Spezifikations-Nummer des Lieferanten', 'text'],
        'spec_version'     => ['Version/Revision der Spezifikation', 'text'],
        'spec_gueltig_ab'  => ['Datum, ab dem die Spezifikation gilt', 'datum'],
        'vegan'            => ['Ist der Rohstoff als vegan ausgewiesen?', 'janein'],
        'gvo_frei'         => ['Ist er als GVO-frei / non-GMO ausgewiesen?', 'janein'],
        'bestrahlt'        => ['Wurde er bestrahlt (irradiated)? Steht "nicht bestrahlt", dann nein.', 'janein'],
        'tse_bse_frei'     => ['Ist er als TSE/BSE-frei ausgewiesen?', 'janein'],
        'dichte'           => ['Schüttdichte in g/ml als Zahl', 'zahl'],
    ];
}

// Der Auftrag an die KI. Bewusst streng: nichts erfinden, nichts umrechnen, nichts ergänzen.
function spec_ki_anweisung(): string {
    $felder = '';
    foreach (spec_ki_felder() as $k => $f) $felder .= "  \"$k\": " . $f[0] . ' [' . $f[1] . "]\n";
    $params = implode(', ', array_keys(coa_parameter()));

    return <<<TXT
Lies dieses Dokument eines Rohstoff-Lieferanten aus.

Bestimme zuerst die Art:
  "spec" = Spezifikation / Produktdatenblatt (beschreibt den Rohstoff allgemein, mit Grenzwerten)
  "coa"  = Analysenzertifikat / Certificate of Analysis (Messwerte EINER Charge)
  "beides" = enthält beides
  "unklar" = weder noch

Gib NUR dieses JSON zurück:
{
  "typ": "spec|coa|beides|unklar",
  "sicherheit": "hoch|mittel|niedrig",
  "stamm": { Felder siehe unten, nur die gefundenen },
  "wirkstoffe": [ { "name": "", "gehalt_prozent": null } ],
  "kennwerte": [ { "parameter": "", "wert": "" } ],
  "cas_vorschlag": null,
  "charge": { "charge_nr": null, "mhd": null, "herstelldatum": null, "menge": null, "einheit": null },
  "werte": [ { "parameter": "", "spezifikation": "", "ergebnis": "", "methode": "" } ],
  "hinweise": [ "kurze Anmerkungen, z. B. unleserliche Stellen" ]
}

Felder für "stamm":
$felder
Regeln, an die du dich halten musst:
- Übernimm NUR, was tatsächlich im Dokument steht. Nichts ergänzen, nichts aus Fachwissen ableiten, nichts raten. (Einzige Ausnahme: "cas_vorschlag", siehe unten.)
- Ein Feld, das nicht im Dokument steht, lässt du WEG (nicht null, nicht leerer Text).
- [datum] im Format JJJJ-MM-TT. [janein] als true oder false. [zahl] als Zahl ohne Einheit, Punkt als Dezimaltrennzeichen.
- [text] wörtlich aus dem Dokument, höchstens gekürzt. Nicht übersetzen.
- "wirkstoffe": die standardisierten Wirk-/Leitsubstanzen mit ihrem Gehalt. Der Assay bzw. die Standardisierung gehört hierher (z. B. "Assay [Content of Iron] NLT 3.0% NMT 5.0%", "Standardisation: Iron content" -> name "Iron", gehalt_prozent 3.0). Bei einer Spanne den unteren Wert (NLT/min) als gehalt_prozent nehmen. name wörtlich aus dem Dokument. Keine Verunreinigungen/Trägerstoffe, keine Excipients. Steht kein Gehalt, gehalt_prozent null. Nichts gefunden -> leere Liste.
- "kennwerte": charakteristische, unterscheidende Kennwerte als Parameter+Wert-Paare: Assay-Spanne, Extraktverhältnis/DEV (z. B. "10:1"), pH, Schüttdichte, Partikelgröße/Mesh, Wassergehalt/Loss on Drying, Löslichkeit. NICHT die reinen Sicherheits-Grenzwerte (Schwermetalle, Mikrobiologie, Mykotoxine, Pestizide, Lösungsmittelreste) – die bleiben im PDF. "wert" wörtlich aus dem Dokument (z. B. "NLT 3.0% / NMT 5.0%", "10:1").
- "cas_vorschlag": NUR füllen, wenn im Dokument KEINE CAS-Nummer steht (oder dort "TBA"/"n/a") UND der Rohstoff ein eindeutiger Reinstoff mit allgemein bekannter CAS ist (z. B. Vitamine, definierte Salze/Verbindungen). Dann die bekannte CAS als Vorschlag. Bei Pflanzenextrakten, Mischungen oder Unsicherheit: null. Wenn gesetzt, in "hinweise" vermerken, dass die CAS aus Fachwissen kommt und geprüft werden muss.
- "werte": jede Zeile der Analysetabelle. "spezifikation" ist der Grenzwert (z. B. "≤ 3 ppm"), "ergebnis" der gemessene Wert. Steht nur eins von beidem, lass das andere leer.
- Gebräuchliche Parameternamen, wenn sie passen: $params. Sonst den Namen aus dem Dokument.
- Bei "sicherheit": "niedrig", wenn das Dokument schlecht lesbar ist oder du viel raten müsstest.
TXT;
}

// Ein Dokument auslesen. Rückgabe:
//   ['ok'=>true, 'typ'=>…, 'sicherheit'=>…, 'stamm'=>[…], 'charge'=>[…], 'werte'=>[…], 'hinweise'=>[…], 'usage'=>[…]]
//   ['ok'=>false, 'fehler'=>'Klartext']
function spec_ki_lesen(string $pfad): array {
    if (!ki_bereit()) return ['ok'=>false, 'fehler'=>'Die KI ist nicht eingerichtet (Einstellungen → KI).'];
    $r = ki_datei_frage($pfad, spec_ki_anweisung(), [
        'json'       => true,
        'denken'     => true,          // Tabellen und Grenzwerte sauber zu lesen lohnt das Nachdenken
        'max_tokens' => 8000,
        'timeout'    => 240,
        'zweck'      => 'spec-lesen',
    ]);
    if (!$r['ok']) return $r;

    $d = $r['daten'];
    $erlaubt = spec_ki_felder();
    $stamm = [];
    foreach ((array)($d['stamm'] ?? []) as $k => $v) {
        if (!isset($erlaubt[$k]) || $v === null || $v === '') continue;
        $stamm[$k] = spec_ki_wert($v, $erlaubt[$k][1]);
    }
    $werte = [];
    foreach ((array)($d['werte'] ?? []) as $z) {
        $p = trim((string)($z['parameter'] ?? ''));
        if ($p === '') continue;
        $werte[] = [
            'parameter'     => mb_substr($p, 0, 120),
            'spezifikation' => mb_substr(trim((string)($z['spezifikation'] ?? '')), 0, 120),
            'ergebnis'      => mb_substr(trim((string)($z['ergebnis'] ?? '')), 0, 120),
            'methode'       => mb_substr(trim((string)($z['methode'] ?? '')), 0, 120),
        ];
    }
    // Standardisierte Wirkstoffe (Assay/Standardisierung) – gehören in die Wirkstoff-Tabelle des Rohstoffs.
    $wirkstoffe = [];
    foreach ((array)($d['wirkstoffe'] ?? []) as $w) {
        $nm = trim((string)($w['name'] ?? ''));
        if ($nm === '') continue;
        $g = $w['gehalt_prozent'] ?? null;
        $wirkstoffe[] = [
            'name'           => mb_substr($nm, 0, 120),
            'gehalt_prozent' => ($g === null || $g === '') ? null : (float) str_replace(',', '.', (string)$g),
        ];
    }
    // Charakteristische Kennwerte (Assay-Spanne, Extraktverhältnis, pH, Dichte …) – ohne Sicherheits-Grenzwerte.
    $kennwerte = [];
    foreach ((array)($d['kennwerte'] ?? []) as $k) {
        $p = trim((string)($k['parameter'] ?? ''));
        if ($p === '') continue;
        $kennwerte[] = ['parameter' => mb_substr($p, 0, 120), 'wert' => mb_substr(trim((string)($k['wert'] ?? '')), 0, 120)];
    }
    $casVorschlag = trim((string)($d['cas_vorschlag'] ?? ''));
    if ($casVorschlag !== '') $casVorschlag = mb_substr($casVorschlag, 0, 30);
    $charge = [];
    foreach (['charge_nr', 'mhd', 'herstelldatum', 'menge', 'einheit'] as $k) {
        $v = $d['charge'][$k] ?? null;
        if ($v === null || $v === '') continue;
        $charge[$k] = in_array($k, ['mhd', 'herstelldatum'], true) ? spec_ki_wert($v, 'datum') : trim((string)$v);
    }
    $typ = (string)($d['typ'] ?? 'unklar');
    return [
        'ok'          => true,
        'typ'         => in_array($typ, ['spec', 'coa', 'beides', 'unklar'], true) ? $typ : 'unklar',
        'sicherheit'  => in_array(($d['sicherheit'] ?? ''), ['hoch', 'mittel', 'niedrig'], true) ? $d['sicherheit'] : 'mittel',
        'stamm'       => $stamm,
        'wirkstoffe'  => $wirkstoffe,
        'kennwerte'   => $kennwerte,
        'cas_vorschlag' => $casVorschlag,
        'charge'      => $charge,
        'werte'       => $werte,
        'hinweise'    => array_slice(array_map(fn($h) => mb_substr(trim((string)$h), 0, 300), (array)($d['hinweise'] ?? [])), 0, 8),
        'usage'       => $r['usage'] ?? [],
        'modell'      => $r['modell'] ?? '',
    ];
}

// Einen Wert in die Form bringen, die die Spalte erwartet.
function spec_ki_wert($v, string $art) {
    if ($art === 'janein') return is_bool($v) ? ($v ? 1 : 0) : (in_array(mb_strtolower(trim((string)$v)), ['ja','yes','true','1'], true) ? 1 : 0);
    if ($art === 'zahl')   return (float) str_replace(',', '.', (string)$v);
    if ($art === 'datum')  { $t = strtotime((string)$v); return $t ? date('Y-m-d', $t) : null; }
    return mb_substr(trim((string)$v), 0, 250);
}

// Direkt nach einem Upload auslesen und den Vorschlag am Dokument merken. Laeuft nur fuer
// Spezifikationen und CoA (nicht fuer Rechnungen o. ae.) und nur, wenn die KI eingerichtet ist.
// Gibt zurueck, ob etwas gelesen wurde - der Aufrufer entscheidet, ob er das anzeigt.
function spec_ki_nach_upload(int $dokument_id): bool {
    if ($dokument_id <= 0 || !ki_bereit()) return false;
    $d = one("SELECT typ, datei FROM dokument WHERE id=?", [$dokument_id]);
    if (!$d || !in_array((string)$d['typ'], ['coa', 'spec', 'analyse'], true)) return false;
    $pfad = BX_UPLOADS . '/' . basename((string)$d['datei']);
    if (!is_file($pfad)) return false;
    $r = spec_ki_lesen($pfad);
    if (!$r['ok']) return false;
    spec_ki_merken($dokument_id, $r);
    return true;
}

// Vorschlag am Dokument merken, damit ihn das Team später prüfen kann (auch wenn der Lieferant
// die Datei hochgeladen hat). Gespeichert wird der Rohvorschlag als JSON, nichts an den Stammdaten.
function spec_ki_merken(int $dokument_id, array $ergebnis): void {
    if ($dokument_id <= 0 || empty($ergebnis['ok'])) return;
    q("UPDATE dokument SET ki_daten=?, ki_stand=? WHERE id=?",
      [json_encode($ergebnis, JSON_UNESCAPED_UNICODE), gmdate('Y-m-d H:i:s'), $dokument_id]);
}
// Gemerkten Vorschlag zu einem Dokument holen (null = keiner da).
function spec_ki_vorschlag(int $dokument_id): ?array {
    $j = scalar("SELECT ki_daten FROM dokument WHERE id=?", [$dokument_id]);
    if (!$j) return null;
    $d = json_decode((string)$j, true);
    return is_array($d) ? $d : null;
}

// Vorschlag in die Stammdaten des Rohstoffs übernehmen. $felder = Liste der Spalten, die der
// Mensch freigegeben hat. Leere Felder am Artikel werden gefüllt; vorhandene nur mit $ueberschreiben.
// Rückgabe: Anzahl der geänderten Felder.
function spec_ki_uebernehmen(int $item_id, array $stamm, array $felder, bool $ueberschreiben = false): int {
    $erlaubt = spec_ki_felder();
    $it = one("SELECT * FROM item WHERE id=?", [$item_id]);
    if (!$it) return 0;
    $n = 0;
    foreach ($felder as $k) {
        if (!isset($erlaubt[$k]) || !array_key_exists($k, $stamm)) continue;
        $alt = $it[$k] ?? null;
        if (!$ueberschreiben && $alt !== null && trim((string)$alt) !== '') continue;
        q("UPDATE item SET `$k`=? WHERE id=?", [$stamm[$k], $item_id]);
        $n++;
    }
    if ($n) log_aktivitaet('item', $item_id, 'team', $n . ' Feld(er) aus einer Lieferantenunterlage übernommen (KI-Vorschlag, geprüft).', 'dokument', 'item', $item_id);
    return $n;
}

// Analysewerte eines CoA an einer Charge speichern (ersetzt die bisherigen Zeilen).
function spec_ki_werte_speichern(int $charge_id, array $werte): int {
    if ($charge_id <= 0 || !$werte) return 0;
    q("DELETE FROM charge_analyse WHERE charge_id=?", [$charge_id]);
    $n = 0;
    foreach ($werte as $i => $z) {
        if (trim((string)($z['parameter'] ?? '')) === '') continue;
        q("INSERT INTO charge_analyse (charge_id,parameter,spezifikation,ergebnis,methode,sort) VALUES (?,?,?,?,?,?)",
          [$charge_id, $z['parameter'], $z['spezifikation'] ?: null, $z['ergebnis'] ?: null, $z['methode'] ?: null, $i]);
        $n++;
    }
    return $n;
}
