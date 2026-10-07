<?php
// Produktbuilder (KI + deterministischer Rechner): aus einem Freitext-Wunsch einen Rezepturvorschlag
// erzeugen. Die KI schlägt Formulierung/Rohstoffe/Verzehrempfehlung vor; die EXAKTE Dosis-Rechnung
// (IE<->mg<->µg, Gehalt der Rohstoffe, Trägerauffüllung) macht der Rechner hier – nie die KI.
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/ki.php';

// --- Deterministischer Dosis-Rechner --------------------------------------------------------------

// Reine Wirkstoffmasse je Einheit in mg aus Zieldosis + Einheit (mg | µg | ie). Bei IE wird der
// Nährstoff-Faktor ie_mg (mg je 1 IE) gebraucht.
function pb_rein_mg(float $dosis, string $einheit, ?float $ie_mg): float {
    $einheit = strtolower(trim($einheit));
    if ($einheit === 'mg') return $dosis;
    if (in_array($einheit, ['µg', 'ug', 'mcg'], true)) return $dosis / 1000;
    if (in_array($einheit, ['ie', 'iu'], true)) return $ie_mg !== null && $ie_mg > 0 ? $dosis * $ie_mg : 0.0;
    if ($einheit === 'g') return $dosis * 1000;
    return $dosis; // Fallback: wie mg
}

// Benötigte ROHSTOFF-Menge je Einheit in mg, damit die Zieldosis erreicht wird – über den Gehalt des
// Rohstoffs (gehalt_wert + gehalt_einheit: prozent|mg_g|ug_g|ie_g|ie_kg). Nutzt wirkstoff_mg_je_mg
// (mg Wirkstoff je 1 mg Rohstoff). Rückgabe: ['roh_mg','rein_mg','ok','hinweis'].
function pb_zutat_rechnen(array $z): array {
    $dosis   = (float) str_replace(',', '.', (string)($z['ziel_dosis'] ?? 0));
    $einheit = (string)($z['ziel_einheit'] ?? 'mg');
    $ie_mg   = ($z['ie_mg'] ?? null) !== null && $z['ie_mg'] !== '' ? (float)$z['ie_mg'] : null;
    $gW      = ($z['gehalt_wert'] ?? null) !== null && $z['gehalt_wert'] !== '' ? (float) str_replace(',', '.', (string)$z['gehalt_wert']) : null;
    $gE      = (string)($z['gehalt_einheit'] ?? 'prozent');
    $rein = pb_rein_mg($dosis, $einheit, $ie_mg);
    if ($rein <= 0)  return ['roh_mg'=>0.0, 'rein_mg'=>0.0, 'ok'=>false, 'hinweis'=>'Keine/ungültige Zieldosis.'];
    if ($gW === null) return ['roh_mg'=>$rein, 'rein_mg'=>$rein, 'ok'=>false, 'hinweis'=>'Gehalt des Rohstoffs fehlt – Rohstoffmenge = reine Wirkstoffmenge (bitte Gehalt ergänzen).'];
    $frac = wirkstoff_mg_je_mg($gW, $gE, $ie_mg);   // mg Wirkstoff je mg Rohstoff
    if ($frac <= 0) return ['roh_mg'=>$rein, 'rein_mg'=>$rein, 'ok'=>false, 'hinweis'=>'Gehalt nicht umrechenbar (IE-Faktor fehlt?).'];
    return ['roh_mg'=> $rein / $frac, 'rein_mg'=>$rein, 'ok'=>true, 'hinweis'=>''];
}

// Nährstoff-Faktor ie_mg (mg je 1 IE) per Name finden – für die IE-Umrechnung (Vitamin D3/E/A …).
function pb_ie_mg(string $name): ?float {
    $name = trim($name);
    if ($name === '') return null;
    $v = scalar("SELECT ie_mg FROM naehrstoff WHERE name=? AND ie_mg IS NOT NULL ORDER BY id LIMIT 1", [$name]);
    if ($v !== null) return (float)$v;
    // Teiltreffer (z. B. „Vitamin D3 (Cholecalciferol)")
    $v = scalar("SELECT ie_mg FROM naehrstoff WHERE ie_mg IS NOT NULL AND (? LIKE CONCAT('%',name,'%') OR name LIKE CONCAT('%',?,'%')) ORDER BY CHAR_LENGTH(name) DESC LIMIT 1", [$name, $name]);
    return $v !== null ? (float)$v : null;
}

// Bestehenden Rohstoff (item) per Name/CAS finden – damit echte Gehalte/Dokumente (CoA/Spec) genutzt
// werden. Rückgabe: ['id','name','gehalt_wert','gehalt_einheit','ie_mg'] oder null.
function pb_rohstoff_match(string $name, string $cas = ''): ?array {
    $name = trim($name); $cas = trim($cas);
    $it = null;
    if ($cas !== '') $it = one("SELECT id, name FROM item WHERE kategorie='rohstoff' AND cas=? AND gesperrt=0 ORDER BY id LIMIT 1", [$cas]);
    if (!$it) $it = one("SELECT id, name FROM item WHERE kategorie='rohstoff' AND name=? AND gesperrt=0 ORDER BY id LIMIT 1", [$name]);
    if (!$it && $name !== '') {
        $like = '%' . addcslashes(str_replace('\\', '', $name), '%_') . '%';
        $it = one("SELECT id, name FROM item WHERE kategorie='rohstoff' AND gesperrt=0 AND name LIKE ? ESCAPE '=' ORDER BY CHAR_LENGTH(name) LIMIT 1", [$like]);
    }
    if (!$it) return null;
    // Leitwirkstoff + Gehalt des Rohstoffs (erster Wirkstoff mit Gehalt)
    $w = one("SELECT COALESCE(iw.gehalt_wert, iw.gehalt_prozent) AS gehalt_wert, COALESCE(iw.gehalt_einheit,'prozent') AS gehalt_einheit, n.ie_mg
              FROM item_wirkstoff iw JOIN naehrstoff n ON n.id=iw.naehrstoff_id
              WHERE iw.item_id=? AND COALESCE(iw.gehalt_wert, iw.gehalt_prozent) IS NOT NULL ORDER BY iw.sort, iw.id LIMIT 1", [(int)$it['id']]);
    return [
        'id' => (int)$it['id'], 'name' => (string)$it['name'],
        'gehalt_wert'    => $w['gehalt_wert'] ?? null,
        'gehalt_einheit' => $w['gehalt_einheit'] ?? null,
        'ie_mg'          => isset($w['ie_mg']) && $w['ie_mg'] !== null ? (float)$w['ie_mg'] : null,
    ];
}

// --- KI-Vorschlag ----------------------------------------------------------------------------------

// Formen, die der Builder kennt. Bezug = worauf sich die Zieldosis bezieht (1 Kapsel, 1 Tropfen, …).
function pb_formen(): array {
    return ['kapsel'=>'Kapsel','tablette'=>'Tablette','softgel'=>'Softgel','stick'=>'Stick (Portion)',
            'pulver'=>'Pulver (Portion)','fluessig'=>'Flüssig / Tropfen','gummi'=>'Fruchtgummi'];
}

// KI um einen strukturierten Rezepturvorschlag bitten. Die KI liefert Formulierung + Zieldosen + Gehalt-
// Schätzungen + Verzehrempfehlung + Hinweise; sie rechnet NICHT die mg aus (das macht pb_zutat_rechnen).
function pb_ki_vorschlag(string $wunsch, string $form, string $bezug, array $extra = []): array {
    if (!ki_bereit()) return ['ok'=>false, 'fehler'=>'KI ist nicht aktiv (kein Schlüssel hinterlegt).'];
    $formen = implode(', ', array_keys(pb_formen()));
    $sys = "Du bist Formulierungs-Experte für Nahrungsergänzungsmittel (Lohnhersteller). Erstelle aus dem "
         . "Wunsch einen produktionsfähigen, rechtlich sauberen Rezepturvorschlag. Antworte NUR mit JSON:\n"
         . "{\n"
         . '  "name": "kurzer Produktname",' . "\n"
         . '  "darreichungsform": "eine von: ' . $formen . '",' . "\n"
         . '  "bezug": "worauf sich die Dosis bezieht, z.B. 1 Tropfen / 1 Kapsel / 1 ml / tägliche Portion",' . "\n"
         . '  "kapselgroesse": "nur bei Kapsel, z.B. 0, 00, 1 (sonst leer)",' . "\n"
         . '  "zutaten": [ { "name": "Rohstoff-/Wirkstoffname (deutsch)", "cas": "falls bekannt",' . "\n"
         . '      "ziel_wirkstoff": "Leitnährstoff, z.B. Vitamin D3 (Cholecalciferol)",' . "\n"
         . '      "ziel_dosis": Zahl, "ziel_einheit": "mg|µg|IE",' . "\n"
         . '      "gehalt_wert": Zahl-oder-null (Gehalt des Rohstoffs), "gehalt_einheit": "prozent|mg_g|ug_g|ie_g|ie_kg",' . "\n"
         . '      "rolle": "wirkstoff|traeger|hilfsstoff" } ],' . "\n"
         . '  "verzehrempfehlung": "z.B. 1x täglich 2 Tropfen",' . "\n"
         . '  "verpackung_typ": "z.B. Tropfflasche 30 ml / Dose",' . "\n"
         . '  "hinweise": ["Höchstmengen/UL, Novel-Food, Health-Claims – kurz"]' . "\n"
         . "}\n"
         . "Wichtig: Für fettlösliche Vitamine (D3/K2/E/A) öllösliche Qualitäten + Trägeröl (z.B. MCT) als Zutat 'traeger'. "
         . "D3 in IE, K2 als MK-7 in µg. Trägeröl füllt den Rest. Gehalt realistisch schätzen (z.B. D3-Öl 100000 IE/g). "
         . "Zahlen als reine Zahlen (Punkt als Dezimaltrenner), KEINE Einheiten im Zahlfeld. Deutsch.";
    $u = "Wunsch: " . trim($wunsch) . "\nGewünschte Form: " . $form . "\nDosis bezieht sich auf: " . $bezug;
    foreach ($extra as $kk => $vv) if (trim((string)$vv) !== '') $u .= "\n" . $kk . ": " . $vv;
    $r = ki_json($u, ['system'=>$sys, 'zweck'=>'produktbuilder']);
    if (!$r['ok']) return ['ok'=>false, 'fehler'=>$r['fehler'] ?? 'KI-Fehler.'];
    return ['ok'=>true, 'daten'=>(array)($r['daten'] ?? [])];
}

// Vorschlag anreichern: je Zutat bestehenden Rohstoff matchen (echter Gehalt) + mg je Einheit berechnen.
function pb_vorschlag_rechnen(array $v): array {
    $zut = [];
    foreach ((array)($v['zutaten'] ?? []) as $z) {
        $z = (array)$z;
        $ieName = (string)($z['ziel_wirkstoff'] ?? $z['name'] ?? '');
        // IE-Faktor aus Nährstoffstamm (verlässlicher als KI-Schätzung)
        $ie_mg = pb_ie_mg($ieName);
        // Bestehenden Rohstoff suchen – echter Gehalt schlägt KI-Schätzung
        $match = pb_rohstoff_match((string)($z['name'] ?? ''), (string)($z['cas'] ?? ''));
        if ($match) {
            if ($match['gehalt_wert'] !== null)    { $z['gehalt_wert'] = $match['gehalt_wert']; $z['gehalt_einheit'] = $match['gehalt_einheit']; }
            if ($match['ie_mg'] !== null)          $ie_mg = $match['ie_mg'];
            $z['item_id']   = $match['id'];
            $z['item_name'] = $match['name'];
        }
        $z['ie_mg'] = $ie_mg;
        $calc = pb_zutat_rechnen($z);
        $z['menge_mg'] = round($calc['roh_mg'], 4);
        $z['rein_mg']  = round($calc['rein_mg'], 5);
        $z['calc_ok']  = $calc['ok'];
        $z['calc_hinweis'] = $calc['hinweis'];
        $zut[] = $z;
    }
    $v['zutaten'] = $zut;
    $v['gesamt_mg'] = round(array_sum(array_map(fn($z) => (float)($z['menge_mg'] ?? 0), $zut)), 3);
    return $v;
}

// Rezeptur (Entwurf) aus dem (ggf. editierten) Vorschlag anlegen. Rückgabe: ['ok','rezeptur_id','fehler'].
// $zutaten: [ ['name','item_id','menge_mg'], … ]. Optional Produkt mit einheiten_pro_packung.
function pb_anlegen(string $name, string $form, ?int $kunde_id, array $zutaten, ?string $kapselgroesse = null, ?string $notiz = null): array {
    $name = trim($name);
    if ($name === '') return ['ok'=>false, 'fehler'=>'Produktname fehlt.'];
    $form = array_key_exists($form, pb_formen()) ? $form : 'kapsel';
    $kapsId = null;
    if (in_array($form, ['kapsel','softgel'], true) && $kapselgroesse) {
        $kg = trim(preg_replace('/[^0-9A-Za-z]/', '', $kapselgroesse));
        $kapsId = (int) scalar("SELECT id FROM kapselgroesse WHERE REPLACE(REPLACE(name,'Größe',''),' ','')=? OR name=? ORDER BY id LIMIT 1", [$kg, $kapselgroesse]) ?: null;
    }
    q("INSERT INTO rezeptur (nummer,name,kunde_id,darreichungsform,kapselgroesse_id,exklusiv,status,notiz) VALUES (?,?,?,?,?,?, 'entwurf', ?)",
      [naechste_nummer('RZ'), $name, $kunde_id ?: null, $form, $kapsId, $kunde_id ? 1 : 0,
       trim('Per Produktbuilder (KI) erstellt. ' . (string)$notiz)]);
    $rid = (int) insert_id();
    $sort = 0;
    foreach ($zutaten as $z) {
        $iid = (int)($z['item_id'] ?? 0) ?: null;
        $bez = trim((string)($z['name'] ?? ''));
        $mg  = (float) str_replace(',', '.', (string)($z['menge_mg'] ?? 0));
        if ($bez === '' && !$iid) continue;
        if ($iid && $bez === '') $bez = (string) scalar("SELECT name FROM item WHERE id=?", [$iid]);
        q("INSERT INTO rezeptur_zutat (rezeptur_id,item_id,bezeichnung,menge_mg,sort) VALUES (?,?,?,?,?)",
          [$rid, $iid, $bez, $mg, $sort++]);
    }
    rezeptur_bulkitem($rid);
    return ['ok'=>true, 'rezeptur_id'=>$rid, 'fehler'=>''];
}
