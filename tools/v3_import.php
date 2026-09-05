<?php
// Gezielter v3 -> v4 Import (SQLite board.sqlite -> v4 MariaDB). NUR die Kunden mit Aktivität.
// Standard = TROCKENLAUF (liest nur, schreibt nichts). Erst mit --write wird geschrieben.
// Aufruf (SQLite-Treiber muss geladen sein):
//   php -d extension_dir=C:\php\ext -d extension=php_pdo_sqlite.dll tools/v3_import.php "C:/…/board.sqlite" [--write]
//
// Grundsätze: v3_id an jeder Zieltabelle (idempotent, keine Dubletten), Trockenlauf zuerst,
// Rezepturen als "eigene Rezeptur beim Kunden" (kunde_id gesetzt) ABER exklusiv=0 = für alle frei,
// Kunden-Bestätigung nur als Info (freigabe_name/_am). Was v3 nicht hat -> Nacharbeitsliste.

if (!defined('BX_ROOT')) define('BX_ROOT', dirname(__DIR__));
require_once dirname(__DIR__) . '/core/config.php';
require_once BX_ROOT . '/core/schema.php';
init_schema();   // stellt alle Tabellen sicher (u. a. produkt_kundenpreis, rezeptur.exklusiv)

$v3quelle = $argv[1] ?? '';
$WRITE  = in_array('--write', $argv, true);
if ($v3quelle === '') { fwrite(STDERR, "Quelle fehlt. Aufruf: php tools/v3_import.php <board.sqlite | mysql-DB-Name> [--write]\n"); exit(1); }

// Quelle: entweder eine SQLite-Datei (alter Stand) ODER ein MySQL-DB-Name (aktueller Dump, lokal eingespielt).
try {
    if (is_file($v3quelle)) { $v3 = new PDO('sqlite:' . $v3quelle); $v3treiber = 'sqlite'; }
    else { $v3 = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $v3quelle . ';charset=utf8mb4', DB_USER, DB_PASS); $v3treiber = 'mysql'; }
    $v3->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) { fwrite(STDERR, "v3-Quelle nicht lesbar (" . $v3quelle . "): " . $e->getMessage() . "\n"); exit(1); }
// Existiert eine Tabelle in der v3-Quelle? (treiberunabhängig)
function v3_hat_tabelle(PDO $v3, string $t): bool {
    try { $v3->query("SELECT 1 FROM `$t` LIMIT 1"); return true; } catch (Throwable $e) { return false; }
}
// Preis aus v3 lesen: „10,63", „4,41€", „10,95 € / Pkg." -> 10.63 / 4.41 / 10.95 (erste Zahl).
function v3_preis($s): float {
    $s = str_replace(',', '.', (string)$s);
    return preg_match('/[0-9]+(?:\.[0-9]+)?/', $s, $m) ? (float)$m[0] : 0.0;
}

// Darreichungsform v3 -> v4
function v3_form(?string $f): string {
    $f = mb_strtolower(trim((string)$f));
    return ['kapsel'=>'kapsel','pulver'=>'pulver','flüssig'=>'fluessig','fluessig'=>'fluessig','tablette'=>'tablette','stick'=>'stick'][$f] ?? 'kapsel';
}
// v4-Rohstoff per Name finden (für die Zutat-Verknüpfung). Nur Treffer-Schätzung im Trockenlauf.
function v4_item_by_name(string $name): ?int {
    $name = trim($name); if ($name === '') return null;
    $id = scalar("SELECT id FROM item WHERE kategorie='rohstoff' AND (name=? OR name_en=? OR synonym=?) LIMIT 1", [$name, $name, $name]);
    return $id ? (int)$id : null;
}

// --- Kunden-Bündelung: mehrere v3-Firmen sind in Wahrheit EINE Firma (Marke separat) ---
// Kanon: v3-Kunde-id => [firma (echte Firma), marke (Markenname)].
// Alias: v3-Kunde-id => kanonische v3-Kunde-id. Deren Rezepte/Anfragen/Aufträge landen beim Kanon.
// Beispiel: Annapurna ist nur die Marke; Firma ist Pure Health (in v3 auf 3 Datensätze verteilt).
$KUNDE_KANON = [
    3 => ['firma' => 'Pure Health', 'marke' => 'Annapurna'],   // v3 #3 Annapurna
];
$KUNDE_ALIAS = [
    603 => 3,   // v3 #603 PURE HEALTH ALLIANCE INC
    656 => 3,   // v3 #656 PURE HEALTH ALLIANCE INC. Zweigniederlassung
];
$kanonV3 = function (int $v3id) use ($KUNDE_ALIAS): int { return $KUNDE_ALIAS[$v3id] ?? $v3id; };

$aktiveKunden = [];
foreach ($v3->query("SELECT * FROM kunden ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $k) {
    $id = (int)$k['id'];
    $rez = (int)$v3->query("SELECT COUNT(*) FROM rezepte WHERE kunde_id=$id")->fetchColumn();
    $pa  = (int)$v3->query("SELECT COUNT(*) FROM produktanfrage WHERE kunde_id=$id")->fetchColumn();
    $stmt = $v3->prepare("SELECT COUNT(*) FROM auftraege WHERE kunde=?"); $stmt->execute([$k['firma']]);
    $auf = (int)$stmt->fetchColumn();
    if ($rez + $pa + $auf > 0) { $k['_rez']=$rez; $k['_pa']=$pa; $k['_auf']=$auf; $aktiveKunden[] = $k; }
}

echo str_repeat('=', 72) . "\n";
echo ($WRITE ? "SCHREIBLAUF" : "TROCKENLAUF (nichts wird geschrieben)") . " – v3 -> v4 Import\n";
echo "Aktive Kunden: " . count($aktiveKunden) . "\n" . str_repeat('=', 72) . "\n";

$nacharbeit = [];
$sum = ['kunden'=>0,'rezepturen'=>0,'zutaten'=>0,'zutaten_ohne_match'=>0,'produkte'=>0,'best_rez'=>0,'best_prod'=>0];

foreach ($aktiveKunden as $k) {
    $kid = (int)$k['id'];
    echo "\n#{$kid}  " . $k['firma'] . ($k['intern'] ? "  [INTERN]" : "") . "\n";
    echo "  Kunde: E-Mail " . ($k['email'] ?: '–');
    $adr = trim((string)($k['lieferadresse'] ?? ''));
    if ($adr === '') { echo " · KEINE Adresse"; $nacharbeit[] = "Kunde '{$k['firma']}': keine Adresse in v3"; }
    else echo " · Adresse als 1 Textfeld (v4 hat 5 -> Nacharbeit)";
    echo "\n";
    if ($adr !== '') $nacharbeit[] = "Kunde '{$k['firma']}': Adresse aufteilen (Straße/PLZ/Ort/Land)";
    $sum['kunden']++;

    // Rezepturen des Kunden
    $rezepte = $v3->query("SELECT * FROM rezepte WHERE kunde_id=$kid ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rezepte as $r) {
        $rid = (int)$r['id'];
        $zut = $v3->query("SELECT bezeichnung, menge_mg FROM rezept_zutaten WHERE rezept_id=$rid ORDER BY pos, id")->fetchAll(PDO::FETCH_ASSOC);
        $match = 0; foreach ($zut as $z) if (v4_item_by_name((string)$z['bezeichnung'])) $match++;
        $best = $v3->query("SELECT bestaetigt, bestaetigt_at, bestaetigt_von FROM rezept_kunde WHERE rezept_id=$rid AND kunde_id=$kid LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $bTxt = ($best && (int)$best['bestaetigt'] === 1) ? (" · bestätigt " . ($best['bestaetigt_at'] ?: '')) : "";
        printf("    Rezeptur: %-38s [%s] %d Zutaten (%d in v4 gefunden)%s\n",
            mb_substr((string)$r['name'],0,38), v3_form($r['form']), count($zut), $match, $bTxt);
        $sum['rezepturen']++; $sum['zutaten'] += count($zut); $sum['zutaten_ohne_match'] += (count($zut) - $match);
        if ($best && (int)$best['bestaetigt'] === 1) $sum['best_rez']++;
    }

    // Produktanfragen (= Produkte/Angebote)
    $pas = $v3->query("SELECT * FROM produktanfrage WHERE kunde_id=$kid ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pas as $p) {
        $rn = (string)$v3->query("SELECT name FROM rezepte WHERE id=" . (int)$p['rezept_id'])->fetchColumn();
        $conf = trim((string)($p['bestaetigt_at'] ?? '')) !== '';
        printf("    Produkt:  %-30s %s je %s Stk · %s%s\n",
            mb_substr($rn,0,30) ?: ('Rezept #'.$p['rezept_id']),
            $p['anzahl_vpe'] ?: '?', $p['menge_pro_vpe'] ?: '?',
            $p['verpackung'] ?: '–', $conf ? (' · BESTÄTIGT ' . ($p['angebot_preis'] ? $p['angebot_preis'].' EUR' : '')) : '');
        $sum['produkte']++; if ($conf) $sum['best_prod']++;
    }
}

// ---- SCHREIBEN (Stufe 1: Kunden + Rezepturen + Zutaten + Bestätigungen) ----
function v3_kapsel_id(?string $v): ?int {
    $v = trim((string)$v); if ($v === '') return null;
    $id = scalar("SELECT id FROM kapselgroesse WHERE name=?", ['Größe ' . $v]);
    return $id ? (int)$id : null;
}
// Auf die v4-Spaltenbreite kappen (v3 erlaubt mehr Zeichen als v4). NULL bleibt NULL.
function cut(?string $s, int $n = 190): ?string { if ($s === null) return null; return mb_substr(trim($s), 0, $n); }
if ($WRITE && in_array('--reset', $argv, true)) {
    // Sauberer Neustart: ALLE v3-importierten Datensätze (nur mit v3_id) löschen, dann frisch importieren.
    // Manuell in v4 angelegte Daten (ohne v3_id) bleiben unberührt. Reihenfolge: Kinder vor Eltern.
    foreach ([
        "DELETE FROM angebot_staffel WHERE angebot_id IN (SELECT id FROM (SELECT id FROM angebot WHERE v3_id IS NOT NULL) t)",
        "DELETE FROM angebot_position WHERE angebot_id IN (SELECT id FROM (SELECT id FROM angebot WHERE v3_id IS NOT NULL) t)",
        "DELETE FROM produkt_kundenpreis",
        "DELETE FROM rezeptur_lief_angebot",
        "DELETE FROM lieferant_preisliste",
        "DELETE FROM bestellung_position WHERE bestellung_id IN (SELECT id FROM (SELECT id FROM bestellung WHERE v3_id IS NOT NULL) t)",
        "DELETE FROM rezeptur_zutat WHERE rezeptur_id IN (SELECT id FROM (SELECT id FROM rezeptur WHERE v3_id IS NOT NULL) t)",
        "UPDATE auftrag SET angebot_id=NULL WHERE v3_id IS NOT NULL",
        "DELETE FROM angebot WHERE v3_id IS NOT NULL",
        "DELETE FROM auftrag WHERE v3_id IS NOT NULL",
        "DELETE FROM bestellung WHERE v3_id IS NOT NULL",
        "DELETE FROM produkt WHERE v3_id IS NOT NULL",
        "DELETE FROM rezeptur WHERE v3_id IS NOT NULL",
        "DELETE FROM item WHERE v3_id IS NOT NULL",
        "DELETE FROM kunden WHERE v3_id IS NOT NULL",
        "DELETE FROM lieferanten WHERE v3_id IS NOT NULL",
    ] as $sql) { try { q($sql); } catch (Throwable $e) { fwrite(STDERR, "reset: " . $e->getMessage() . "\n"); } }
    echo "RESET: alle v3-importierten Datensätze gelöscht.\n";
}
if ($WRITE) {
    // v3_id an den Zieltabellen (idempotent, keine Dubletten)
    ensure_column('kunden', 'v3_id', "INT NULL");
    ensure_column('rezeptur', 'v3_id', "INT NULL");
    ensure_column('item', 'v3_id', "INT NULL");

    // ---- Stufe 0: Rohstoffe (VOR den Rezepturen, damit Zutaten sich verknüpfen). KEINE Dubletten:
    // (1) idempotent über v3_id, (2) gleicher (normalisierter) Name -> bestehenden Rohstoff verknüpfen. ----
    $normName = fn($s) => preg_replace('/\s+/', ' ', mb_strtolower(trim((string)$s)));
    $vorhanden = [];   // normalisierter Name -> v4 item-id (bestehende + neu angelegte)
    foreach (all("SELECT id, name FROM item WHERE kategorie='rohstoff'") as $it) $vorhanden[$normName($it['name'])] = (int)$it['id'];
    $v3form = fn($a) => ['pulver'=>'pulver','flüssig'=>'fluessig','fluessig'=>'fluessig'][mb_strtolower(trim((string)$a))] ?? 'pulver';
    $w0 = ['roh_neu'=>0, 'roh_upd'=>0, 'roh_link'=>0];
    foreach ($v3->query("SELECT * FROM rohstoffe WHERE kategorie='rohstoff' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $rs) {
        $v3sid = (int)$rs['id'];
        $name  = cut($rs['name_de']); if ($name === '' || $name === null) continue;
        $nn    = $normName($rs['name_de']);
        $ekp   = ($rs['kilo_preis'] !== null && $rs['kilo_preis'] !== '') ? (float)str_replace(',', '.', (string)$rs['kilo_preis']) : 0.0;
        $ex = one("SELECT id FROM item WHERE v3_id=? AND kategorie='rohstoff'", [$v3sid]);
        if ($ex) {   // schon importiert -> aktualisieren
            q("UPDATE item SET name=?,name_en=?,name_lat=?,form=?,ek_preis=?,herkunft=? WHERE id=?",
              [$name, cut($rs['name_en']), cut($rs['name_lat']), $v3form($rs['art']), $ekp, cut($rs['herkunft']), (int)$ex['id']]);
            $vorhanden[$nn] = (int)$ex['id']; $w0['roh_upd']++;
        } elseif (isset($vorhanden[$nn])) {   // gleicher Name existiert bereits -> verknüpfen, NICHT doppelt anlegen
            q("UPDATE item SET v3_id=? WHERE id=? AND (v3_id IS NULL OR v3_id=?)", [$v3sid, $vorhanden[$nn], $v3sid]);
            $w0['roh_link']++;
        } else {   // neu anlegen
            q("INSERT INTO item (artikelnummer,name,name_en,name_lat,kategorie,form,einheit,preis_bezug,ek_preis,herkunft,notiz,v3_id)
               VALUES (?,?,?,?, 'rohstoff', ?, 'kg','kg', ?, ?, ?, ?)",
              [naechste_nummer('R'), $name, cut($rs['name_en']), cut($rs['name_lat']), $v3form($rs['art']), $ekp, cut($rs['herkunft']),
               'Aus v3 übernommen (v3-Rohstoff #' . $v3sid . ')', $v3sid]);
            $vorhanden[$nn] = (int)insert_id(); $w0['roh_neu']++;
        }
    }
    echo "\n" . str_repeat('=', 72) . "\nGESCHRIEBEN (Stufe 0 – Rohstoffe):\n";
    foreach ($w0 as $k => $v) printf("  %-10s %d\n", $k, $v);

    $w = ['kunde_neu'=>0,'kunde_upd'=>0,'rez_neu'=>0,'rez_upd'=>0,'zutat'=>0,'best'=>0];
    foreach ($aktiveKunden as $k) {
        if ((int)($k['intern'] ?? 0) === 1) continue;   // interner „Kunde" wird übersprungen
        $v3kid = (int)$k['id'];
        $kanon = $kanonV3($v3kid);
        $istAlias = ($kanon !== $v3kid);   // dieser v3-Kunde gehört zu einer anderen Firma (Bündelung)
        $adr = trim((string)($k['lieferadresse'] ?? '')); if (mb_strtolower($adr) === 'keine') $adr = '';
        if ($istAlias) {
            // Kein eigener Datensatz – Rezepte an den Kanon-Kunden hängen.
            $v4kid = (int) scalar("SELECT id FROM kunden WHERE v3_id=?", [$kanon]);
            if (!$v4kid) continue;   // Kanon kommt zuerst (kleinere id) – sollte existieren
        } else {
            $firma = $KUNDE_KANON[$v3kid]['firma'] ?? $k['firma'];   // echte Firma (Kanon override)
            $marke = $KUNDE_KANON[$v3kid]['marke'] ?? null;          // Markenname -> kunde_marke (White-Label)
            $notiz = 'Aus v3 übernommen (v3-Kunde #' . $v3kid . ')' . ($adr !== '' ? ' · Adresse laut v3: ' . $adr : '');
            $ex = one("SELECT id FROM kunden WHERE v3_id=?", [$v3kid]);
            if ($ex) { $v4kid = (int)$ex['id']; q("UPDATE kunden SET firma=?, email=?, notiz=? WHERE id=?", [cut($firma), cut($k["email"] ?: null), cut($notiz,500), $v4kid]); $w['kunde_upd']++; }
            else {
                q("INSERT INTO kunden (kundennummer,firma,email,notiz,portal_token,portal_rezeptur,portal_produkte,v3_id) VALUES (?,?,?,?,?,1,1,?)",
                  [naechste_nummer('K'), cut($firma), cut($k["email"] ?: null), cut($notiz,500), bin2hex(random_bytes(16)), $v3kid]);
                $v4kid = (int)insert_id(); $w['kunde_neu']++;
            }
            // Markenname als White-Label-Marke pflegen (kunde_marke), idempotent (kein Duplikat).
            if ($marke && !scalar("SELECT id FROM kunde_marke WHERE kunde_id=? AND name=?", [$v4kid, $marke])) {
                q("INSERT INTO kunde_marke (kunde_id,name,webseite,sort) VALUES (?,?,'',0)", [$v4kid, $marke]);
            }
        }
        foreach ($v3->query("SELECT * FROM rezepte WHERE kunde_id=$v3kid ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $v3rid = (int)$r['id'];
            $best = $v3->query("SELECT bestaetigt,bestaetigt_at,bestaetigt_von FROM rezept_kunde WHERE rezept_id=$v3rid AND kunde_id=$v3kid LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $confirmed = $best && (int)$best['bestaetigt'] === 1;
            $frName = $confirmed ? ($best['bestaetigt_von'] ?: $k['firma']) : null;
            $frAm   = $confirmed && $best['bestaetigt_at'] ? $best['bestaetigt_at'] : null;
            if ($confirmed) $w['best']++;
            $form = v3_form($r['form']); $kaps = v3_kapsel_id($r['kapselgroesse'] ?? '');
            $rnotiz = 'Aus v3 übernommen (v3-Rezept #' . $v3rid . ')' . ($confirmed ? ' · vom Kunden bestätigt' . ($frAm ? ' ' . $frAm : '') : '');
            $exR = one("SELECT id FROM rezeptur WHERE v3_id=?", [$v3rid]);
            if ($exR) { $v4rid = (int)$exR['id'];
                q("UPDATE rezeptur SET name=?,kunde_id=?,darreichungsform=?,kapselgroesse_id=?,exklusiv=0,status='freigegeben',freigabe_name=?,freigabe_am=?,notiz=? WHERE id=?",
                  [cut($r['name']), $v4kid, $form, $kaps, cut($frName), $frAm, cut($rnotiz,500), $v4rid]); $w['rez_upd']++;
            } else {
                q("INSERT INTO rezeptur (nummer,name,kunde_id,darreichungsform,kapselgroesse_id,exklusiv,status,freigabe_name,freigabe_am,notiz,v3_id) VALUES (?,?,?,?,?,0,'freigegeben',?,?,?,?)",
                  [naechste_nummer('RZ'), cut($r['name']), $v4kid, $form, $kaps, cut($frName), $frAm, cut($rnotiz,500), $v3rid]); $v4rid = (int)insert_id(); $w['rez_neu']++;
            }
            q("DELETE FROM rezeptur_zutat WHERE rezeptur_id=?", [$v4rid]);
            foreach ($v3->query("SELECT bezeichnung,menge_mg,pos FROM rezept_zutaten WHERE rezept_id=$v3rid ORDER BY pos,id")->fetchAll(PDO::FETCH_ASSOC) as $z) {
                $iid = v4_item_by_name((string)$z['bezeichnung']);
                q("INSERT INTO rezeptur_zutat (rezeptur_id,item_id,bezeichnung,menge_mg,sort) VALUES (?,?,?,?,?)",
                  [$v4rid, $iid, cut($z['bezeichnung']), (float)$z['menge_mg'], (int)$z['pos']]); $w['zutat']++;
            }
        }
    }
    // Hausrezepturen (v3 kunde_id NULL/0) als Katalog-Rezepturen (kunde_id NULL, exklusiv=0, freigegeben) –
    // werden von Aufträgen/Produktanfragen referenziert, gehören also mit.
    foreach ($v3->query("SELECT * FROM rezepte WHERE kunde_id IS NULL OR kunde_id=0 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $v3rid = (int)$r['id'];
        $form = v3_form($r['form']); $kaps = v3_kapsel_id($r['kapselgroesse'] ?? '');
        $rnotiz = 'Aus v3 übernommen (v3-Hausrezept #' . $v3rid . ')';
        $exR = one("SELECT id FROM rezeptur WHERE v3_id=?", [$v3rid]);
        if ($exR) { $v4rid = (int)$exR['id'];
            q("UPDATE rezeptur SET name=?,kunde_id=NULL,darreichungsform=?,kapselgroesse_id=?,exklusiv=0,status='freigegeben',notiz=? WHERE id=?",
              [cut($r['name']), $form, $kaps, cut($rnotiz,500), $v4rid]); $w['rez_upd']++;
        } else {
            q("INSERT INTO rezeptur (nummer,name,kunde_id,darreichungsform,kapselgroesse_id,exklusiv,status,notiz,v3_id) VALUES (?,?,NULL,?,?,0,'freigegeben',?,?)",
              [naechste_nummer('RZ'), cut($r['name']), $form, $kaps, cut($rnotiz,500), $v3rid]); $v4rid = (int)insert_id(); $w['rez_neu']++;
        }
        q("DELETE FROM rezeptur_zutat WHERE rezeptur_id=?", [$v4rid]);
        foreach ($v3->query("SELECT bezeichnung,menge_mg,pos FROM rezept_zutaten WHERE rezept_id=$v3rid ORDER BY pos,id")->fetchAll(PDO::FETCH_ASSOC) as $z) {
            $iid = v4_item_by_name((string)$z['bezeichnung']);
            q("INSERT INTO rezeptur_zutat (rezeptur_id,item_id,bezeichnung,menge_mg,sort) VALUES (?,?,?,?,?)",
              [$v4rid, $iid, cut($z['bezeichnung']), (float)$z['menge_mg'], (int)$z['pos']]); $w['zutat']++;
        }
    }
    // Alt-Läufe: falls ein Alias-Kunde noch als eigener v4-Datensatz existiert, alles auf den Kanon umhängen und löschen.
    foreach ($KUNDE_ALIAS as $aliasV3 => $kanonV3id) {
        $aliasV4 = (int) scalar("SELECT id FROM kunden WHERE v3_id=?", [$aliasV3]);
        $kanonV4 = (int) scalar("SELECT id FROM kunden WHERE v3_id=?", [$kanonV3id]);
        if ($aliasV4 && $kanonV4 && $aliasV4 !== $kanonV4) {
            foreach (['rezeptur','angebot','auftrag','produkt_kundenpreis','kunde_marke'] as $t)
                q("UPDATE `$t` SET kunde_id=? WHERE kunde_id=?", [$kanonV4, $aliasV4]);
            q("DELETE FROM kunden WHERE id=?", [$aliasV4]);
            echo "  Kunde-Bündelung: v4 #$aliasV4 (v3 #$aliasV3) in #$kanonV4 überführt.\n";
        }
    }
    echo "\n" . str_repeat('=', 72) . "\nGESCHRIEBEN (Stufe 1):\n";
    foreach ($w as $k => $v) printf("  %-12s %d\n", $k, $v);

    // ---- Stufe 2: Produkte (je Rezeptur) + Kundenpreise (aus produktanfrage) ----
    ensure_column('produkt', 'v3_id', "INT NULL");   // = v3 rezept_id (ein Produkt je Rezeptur als Anker)
    $w2 = ['produkt_neu'=>0,'preis_neu'=>0,'preis_upd'=>0,'ohne_produkt'=>0];
    $preisParse = fn($s) => trim((string)$s) === '' ? null : v3_preis($s);
    foreach ($aktiveKunden as $k) {
        if ((int)($k['intern'] ?? 0) === 1) continue;
        $v3kid = (int)$k['id'];
        $v4kid = (int) scalar("SELECT id FROM kunden WHERE v3_id=?", [$kanonV3($v3kid)]);
        if (!$v4kid) continue;
        foreach ($v3->query("SELECT * FROM produktanfrage WHERE kunde_id=$v3kid ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $v3rez = (int)$p['rezept_id'];
            // v4-Rezeptur zu diesem v3-Rezept
            $rz = one("SELECT id, name FROM rezeptur WHERE v3_id=?", [$v3rez]);
            if (!$rz) { $w2['ohne_produkt']++; continue; }   // Rezept nicht importiert (z. B. fremder Kunde)
            $v4rid = (int)$rz['id'];
            // Produkt-Anker je Rezeptur (v3_id = v3 rezept_id)
            $pr = one("SELECT id FROM produkt WHERE v3_id=?", [$v3rez]);
            if ($pr) { $pid = (int)$pr['id']; }
            else {
                q("INSERT INTO produkt (nummer,name,rezeptur_id,exklusiv,einheiten_pro_packung,status,notiz,v3_id)
                   VALUES (?,?,?,0,?, 'entwurf', ?, ?)",
                  [naechste_nummer('P'), cut($rz['name']), $v4rid, (int)($p['menge_pro_vpe'] ?: 0),
                   'Aus v3 übernommen (v3-Rezept #' . $v3rez . ')', $v3rez]);
                $pid = (int)insert_id(); $w2['produkt_neu']++;
            }
            // Kundenpreis-Zeile (idempotent über v3 produktanfrage.id)
            $preis = $preisParse($p['angebot_preis'] ?? '');
            $mv = $p['menge_pro_vpe'] !== null && $p['menge_pro_vpe'] !== '' ? (int)$p['menge_pro_vpe'] : null;
            $av = $p['anzahl_vpe'] !== null && $p['anzahl_vpe'] !== '' ? (int)$p['anzahl_vpe'] : null;
            $verp = cut($p['verpackung'] ?? '');
            $conf = trim((string)($p['bestaetigt_at'] ?? '')) !== '';
            $pnotiz = $conf ? ('bestätigt ' . $p['bestaetigt_at']) : null;
            $exP = one("SELECT id FROM produkt_kundenpreis WHERE v3_id=?", [(int)$p['id']]);
            if ($exP) {
                q("UPDATE produkt_kundenpreis SET produkt_id=?,kunde_id=?,menge_pro_vpe=?,anzahl_vpe=?,verpackung=?,preis=?,notiz=? WHERE id=?",
                  [$pid, $v4kid, $mv, $av, $verp, $preis, cut($pnotiz,255), (int)$exP['id']]); $w2['preis_upd']++;
            } else {
                q("INSERT INTO produkt_kundenpreis (produkt_id,kunde_id,menge_pro_vpe,anzahl_vpe,verpackung,preis,notiz,v3_id) VALUES (?,?,?,?,?,?,?,?)",
                  [$pid, $v4kid, $mv, $av, $verp, $preis, cut($pnotiz,255), (int)$p['id']]); $w2['preis_neu']++;
            }
        }
    }
    echo "\nGESCHRIEBEN (Stufe 2):\n";
    foreach ($w2 as $k => $v) printf("  %-14s %d\n", $k, $v);

    // ---- Stufe 3: Lieferanten + EK-Preisliste (Referenz) + Bestellungen ----
    ensure_column('lieferanten', 'v3_id', "INT NULL");
    ensure_column('bestellung', 'v3_id', "INT NULL");
    $w3 = ['lief_neu'=>0,'lief_upd'=>0,'preisliste'=>0,'best_neu'=>0,'best_upd'=>0];
    // Ländername -> ISO-2 (v4 land = VARCHAR(2))
    $land2 = function (?string $l): string {
        $l = mb_strtolower(trim((string)$l));
        $m = ['deutschland'=>'DE','germany'=>'DE','china'=>'CN','österreich'=>'AT','oesterreich'=>'AT','schweiz'=>'CH','italien'=>'IT','spanien'=>'ES','indien'=>'IN','usa'=>'US','vereinigte staaten'=>'US','niederlande'=>'NL','frankreich'=>'FR','polen'=>'PL','irland'=>'IE'];
        return $m[$l] ?? '';   // unbekannt/leer -> '' (Spalte ist NOT NULL)
    };
    // Lieferanten
    $mapLief = [];   // v3 lieferant_id -> v4 id
    foreach ($v3->query("SELECT * FROM lieferanten ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $v3lid = (int)$l['id'];
        $ex = one("SELECT id FROM lieferanten WHERE v3_id=?", [$v3lid]);
        if ($ex) { $v4lid = (int)$ex['id'];
            q("UPDATE lieferanten SET firma=?,ansprechpartner=?,email=?,land=?,kategorien=?,sprache=? WHERE id=?",
              [cut($l['firma']), cut($l['ansprechpartner']), cut($l['email']), $land2($l["land"]), cut($l['kategorien']), cut($l['sprache'] ?: 'de', 5), $v4lid]); $w3['lief_upd']++;
        } else {
            q("INSERT INTO lieferanten (lieferantennummer,firma,ansprechpartner,email,land,kategorien,sprache,notiz,v3_id) VALUES (?,?,?,?,?,?,?,?,?)",
              [naechste_nummer('L'), cut($l['firma']), cut($l['ansprechpartner']), cut($l['email']), $land2($l["land"]), cut($l['kategorien']), cut($l['sprache'] ?: 'de', 5), 'Aus v3 übernommen (v3-Lieferant #' . $v3lid . ')', $v3lid]);
            $v4lid = (int)insert_id(); $w3['lief_neu']++;
        }
        $mapLief[$v3lid] = $v4lid;
    }
    // EK-Preisliste (Referenz) – komplett neu aufbauen (idempotent per Wipe der importierten Zeilen)
    q("DELETE FROM lieferant_preisliste WHERE v3_id IS NOT NULL");
    foreach ($v3->query("SELECT * FROM preisliste ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $pl) {
        $eur = $pl['eur_kg'] !== null && $pl['eur_kg'] !== '' ? (float)str_replace(',', '.', (string)$pl['eur_kg']) : null;
        q("INSERT INTO lieferant_preisliste (rohstoff_name,lieferant,eur_kg,stand,v3_id) VALUES (?,?,?,?,?)",
          [cut($pl['name']), cut($pl['lieferant']), $eur, (!empty($pl['updated_at']) ? substr((string)$pl['updated_at'], 0, 10) : null), (int)$pl['id']]);
        $w3['preisliste']++;
    }
    // Bestellungen (alle) -> v4 bestellung + eine Position (Artikel als Text)
    $statusMap = ['offen'=>'offen','rohstoff_erhalten'=>'geliefert','versendet'=>'bestellt','versand_geplant'=>'bestellt','wartet_auf_zoll'=>'bestellt'];
    foreach ($v3->query("SELECT * FROM bestellungen ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $v3bid = (int)$b['id'];
        $v4lid = $mapLief[(int)$b['lieferant_id']] ?? null;
        $st = $statusMap[(string)$b['status']] ?? 'offen';
        $notiz = 'Aus v3 (#' . $v3bid . ', Status v3: ' . $b['status'] . ')' . (!empty($b['lief_charge']) ? ' · Charge ' . $b['lief_charge'] : '');
        $exB = one("SELECT id FROM bestellung WHERE v3_id=?", [$v3bid]);
        if ($exB) { $bid = (int)$exB['id'];
            q("UPDATE bestellung SET lieferant_id=?,status=?,notiz=?,bestelldatum=?,tracking=? WHERE id=?",
              [$v4lid, $st, cut($notiz,500), (!empty($b['created_at']) ? substr((string)$b['created_at'],0,10) : null), cut($b['tracking']), $bid]);
            q("DELETE FROM bestellung_position WHERE bestellung_id=?", [$bid]); $w3['best_upd']++;
        } else {
            q("INSERT INTO bestellung (nummer,lieferant_id,status,notiz,bestelldatum,tracking,v3_id) VALUES (?,?,?,?,?,?,?)",
              [naechste_nummer('BE'), $v4lid, $st, cut($notiz,500), (!empty($b['created_at']) ? substr((string)$b['created_at'],0,10) : null), cut($b['tracking']), $v3bid]);
            $bid = (int)insert_id(); $w3['best_neu']++;
        }
        $ekp = $b['einzelpreis'] !== null && $b['einzelpreis'] !== '' ? (float)str_replace(',', '.', (string)$b['einzelpreis']) : ($b['preis'] !== null && $b['preis'] !== '' ? (float)str_replace(',', '.', (string)$b['preis']) : 0.0);
        q("INSERT INTO bestellung_position (bestellung_id,item_id,menge,ek_preis,einheit,sort,bezeichnung) VALUES (?,?,?,?,?,0,?)",
          [$bid, null, (float)($b['menge'] ?: 0), $ekp, cut($b['einheit'], 20), cut($b['artikel'])]);
    }
    echo "\nGESCHRIEBEN (Stufe 3):\n";
    foreach ($w3 as $k => $v) printf("  %-12s %d\n", $k, $v);

    // ---- Stufe 4: Lieferanten-Angebote pro Rezeptur (Fremdfertigung) ----
    $w4 = ['angebot_neu'=>0,'angebot_upd'=>0,'ohne_rezeptur'=>0];
    foreach ($v3->query("SELECT * FROM lieferant_angebot ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $v3aid = (int)$a['id'];
        $rz = one("SELECT id FROM rezeptur WHERE v3_id=?", [(int)$a['rezept_id']]);
        if (!$rz) { $w4['ohne_rezeptur']++; continue; }   // Rezeptur nicht importiert -> überspringen
        $v4rid = (int)$rz['id'];
        $v4lid = $mapLief[(int)$a['lieferant_id']] ?? null;
        $preis = ($a['preis'] !== null && $a['preis'] !== '') ? (float)str_replace(',', '.', (string)$a['preis']) : null;
        $menge = ($a['menge'] !== null && $a['menge'] !== '') ? (float)str_replace(',', '.', (string)$a['menge']) : null;
        $exA = one("SELECT id FROM rezeptur_lief_angebot WHERE v3_id=?", [$v3aid]);
        if ($exA) {
            q("UPDATE rezeptur_lief_angebot SET rezeptur_id=?,lieferant_id=?,preis=?,einheit=?,menge=?,status=?,notiz=?,angenommen_am=? WHERE id=?",
              [$v4rid, $v4lid, $preis, cut($a['einheit'], 30), $menge, cut($a['status'], 20), cut($a['notiz'], 255), (!empty($a['angenommen_at']) ? $a['angenommen_at'] : null), (int)$exA['id']]); $w4['angebot_upd']++;
        } else {
            q("INSERT INTO rezeptur_lief_angebot (rezeptur_id,lieferant_id,preis,einheit,menge,status,notiz,angenommen_am,v3_id) VALUES (?,?,?,?,?,?,?,?,?)",
              [$v4rid, $v4lid, $preis, cut($a['einheit'], 30), $menge, cut($a['status'], 20), cut($a['notiz'], 255), (!empty($a['angenommen_at']) ? $a['angenommen_at'] : null), $v3aid]); $w4['angebot_neu']++;
        }
    }
    echo "\nGESCHRIEBEN (Stufe 4 – Lieferanten-Angebote):\n";
    foreach ($w4 as $k => $v) printf("  %-14s %d\n", $k, $v);

    // ---- Stufe 5: Aufträge (Kundenbestellungen) ----
    ensure_column('auftrag', 'v3_id', "INT NULL");
    // Kunde per Firmenname -> v4-Id (v3 auftraege.kunde ist ein Name).
    // Aus den v3-Firmennamen aufgebaut und über die Bündelung auf den Kanon gemappt,
    // damit auch alte Marken-/Zweigstellen-Namen (z. B. "Annapurna") den gemergten Kunden treffen.
    $firmaMap = [];
    foreach ($v3->query("SELECT id, firma FROM kunden")->fetchAll(PDO::FETCH_ASSOC) as $vk) {
        $v4 = (int) scalar("SELECT id FROM kunden WHERE v3_id=?", [$kanonV3((int)$vk['id'])]);
        if ($v4) $firmaMap[$normName($vk['firma'])] = $v4;
    }
    // Verpackung per Name -> v4-Verpackungsartikel (sonst NULL)
    $verpId = function (?string $name): ?int {
        $name = trim((string)$name); if ($name === '') return null;
        $id = scalar("SELECT id FROM item WHERE kategorie='verpackung' AND name=? LIMIT 1", [$name]);
        return $id ? (int)$id : null;
    };
    $w5 = ['auftrag_neu'=>0,'auftrag_upd'=>0,'ohne_kunde'=>0,'ohne_produkt'=>0];
    foreach ($v3->query("SELECT * FROM auftraege ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $v3aid = (int)$a['id'];
        $kid = $firmaMap[$normName($a['kunde'])] ?? null;
        if (!$kid) { $w5['ohne_kunde']++; continue; }   // z. B. interner „Kunde"
        // Produkt-Anker über das Rezept
        $pid = null;
        if (!empty($a['rezept_id'])) {
            $rz = one("SELECT id, name FROM rezeptur WHERE v3_id=?", [(int)$a['rezept_id']]);
            if ($rz) {
                $prow = one("SELECT id FROM produkt WHERE v3_id=?", [(int)$a['rezept_id']]);
                if ($prow) $pid = (int)$prow['id'];
                else {
                    q("INSERT INTO produkt (nummer,name,rezeptur_id,exklusiv,einheiten_pro_packung,status,notiz,v3_id) VALUES (?,?,?,0,?, 'entwurf', ?, ?)",
                      [naechste_nummer('P'), cut($rz['name']), (int)$rz['id'], (int)($a['menge_pro_vpe'] ?: 0), 'Aus v3 (Auftrag)', (int)$a['rezept_id']]);
                    $pid = (int)insert_id();
                }
            }
        }
        if (!$pid) $w5['ohne_produkt']++;
        // Status aus den v3-Stufen-Flags ableiten
        $flag = fn($v) => $v !== null && $v !== '' && (int)$v > 0;
        $st = $flag($a['versand'] ?? 0) ? 'versendet'
            : (($flag($a['verpackt'] ?? 0)) ? 'erledigt'
            : (($flag($a['produziert'] ?? 0)) ? 'in_produktion' : 'offen'));
        $menge  = (int)($a['anzahl_vpe'] ?: ($a['menge'] ?: 0));
        $stueck = (int)($a['menge_pro_vpe'] ?: ($a['stueckzahl'] ?: 0));
        $vid = $verpId($a['verpackung'] ?? '');
        // Preis aus der verknüpften Produktanfrage (anfrage_id -> angebot_preis) -> Netto = Preis je VPE × Menge.
        $vkStueck = 0.0; $netto = 0.0;
        if (!empty($a['anfrage_id'])) {
            $ap = $v3->query("SELECT angebot_preis FROM produktanfrage WHERE id=" . (int)$a['anfrage_id'])->fetchColumn();
            if ($ap !== false && $ap !== null && $ap !== '') {
                $p = v3_preis($ap);
                if ($p > 0) { $vkStueck = $p; $netto = $p * $menge; }
            }
        }
        $exA = one("SELECT id FROM auftrag WHERE v3_id=?", [$v3aid]);
        if ($exA) {
            q("UPDATE auftrag SET kunde_id=?,produkt_id=?,menge=?,stueck=?,verpackung_id=?,status=?,vk_stueck=?,gesamt_netto=? WHERE id=?",
              [$kid, $pid, $menge, $stueck, $vid, $st, $vkStueck, $netto, (int)$exA['id']]); $w5['auftrag_upd']++;
        } else {
            q("INSERT INTO auftrag (nummer,kunde_id,produkt_id,menge,stueck,verpackung_id,status,vk_stueck,gesamt_netto,v3_id) VALUES (?,?,?,?,?,?,?,?,?,?)",
              [naechste_nummer('AB'), $kid, $pid, $menge, $stueck, $vid, $st, $vkStueck, $netto, $v3aid]); $w5['auftrag_neu']++;
        }
    }
    echo "\nGESCHRIEBEN (Stufe 5 – Aufträge):\n";
    foreach ($w5 as $k => $v) printf("  %-14s %d\n", $k, $v);

    // ---- Stufe 6: Angebote (aus produktanfrage) – damit der Kunde sieht, was er vorliegen hat/hatte ----
    ensure_column('angebot', 'v3_id', "INT NULL");
    // Gibt es in dieser v3-DB die Staffel-Tabelle (neuere Exporte)?
    $hasPaStaffel = v3_hat_tabelle($v3, 'produktanfrage_staffel');
    $v3kMap = [];
    foreach (all("SELECT id, v3_id FROM kunden WHERE v3_id IS NOT NULL") as $kk) $v3kMap[(int)$kk['v3_id']] = (int)$kk['id'];
    $w6 = ['angebot_neu'=>0,'angebot_upd'=>0,'position'=>0,'verknuepft'=>0,'ohne_produkt'=>0];
    foreach ($v3->query("SELECT * FROM produktanfrage ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $pa) {
        // reine Anfrage ohne Angebot (status 'offen', kein Preis) überspringen – da gibt es kein Angebot
        $hatPreis = trim((string)($pa['angebot_preis'] ?? '')) !== '';
        $conf = trim((string)($pa['bestaetigt_at'] ?? '')) !== '';
        if ((string)$pa['status'] === 'offen' && !$hatPreis && !$conf) continue;
        $v3paid = (int)$pa['id'];
        $kid = $v3kMap[$kanonV3((int)$pa['kunde_id'])] ?? null; if (!$kid) continue;
        $rz = one("SELECT id, name FROM rezeptur WHERE v3_id=?", [(int)$pa['rezept_id']]);
        $pid = $rz ? (int) scalar("SELECT id FROM produkt WHERE v3_id=?", [(int)$pa['rezept_id']]) : null;
        if (!$pid) { $w6['ohne_produkt']++; }
        $rezName = $rz ? (string)$rz['name'] : ('Rezept #' . $pa['rezept_id']);
        $status = $conf ? 'bestaetigt' : ((string)$pa['status'] === 'abgelehnt' ? 'abgelehnt' : 'gesendet');
        $preis  = $hatPreis ? v3_preis($pa['angebot_preis']) : 0.0;
        $menge  = (int)($pa['anzahl_vpe'] ?: 0);
        $stueck = (int)($pa['menge_pro_vpe'] ?: 0);
        $vid = $verpId($pa['verpackung'] ?? '');
        $notiz = 'Aus v3 (Produktanfrage #' . $v3paid . ')' . ($conf ? ' · bestätigt ' . $pa['bestaetigt_at'] : '') . (!empty($pa['ablehnung_grund']) ? ' · abgelehnt: ' . $pa['ablehnung_grund'] : '');
        $exG = one("SELECT id FROM angebot WHERE v3_id=?", [$v3paid]);
        if ($exG) { $gid = (int)$exG['id'];
            q("UPDATE angebot SET kunde_id=?,produkt_id=?,status=?,notiz=? WHERE id=?", [$kid, $pid, $status, cut($notiz,500), $gid]);
            q("DELETE FROM angebot_position WHERE angebot_id=?", [$gid]);
            q("DELETE FROM angebot_staffel WHERE angebot_id=?", [$gid]); $w6['angebot_upd']++;
        } else {
            q("INSERT INTO angebot (nummer,kunde_id,produkt_id,status,notiz,v3_id) VALUES (?,?,?,?,?,?)",
              [naechste_nummer('AN'), $kid, $pid, $status, cut($notiz,500), $v3paid]); $gid = (int)insert_id(); $w6['angebot_neu']++;
        }
        // Eine Angebotsposition (Konfiguration + Preis)
        q("INSERT INTO angebot_position (angebot_id,sort,bezeichnung,menge,einheit,preis_cent,stueck,rezeptur_id,verpackung_id,quelle)
           VALUES (?,0,?,?, 'Pkg.', ?, ?, ?, ?, 'v3import')",
          [$gid, cut($rezName), $menge, (int) round($preis * 100), $stueck, ($rz ? (int)$rz['id'] : null), $vid]);
        $w6['position']++;
        // Preis-Staffeln (Menge + VK je Packung) – das sieht der Kunde als wählbare Angebotsmengen.
        // Neuere v3: alle Stufen aus produktanfrage_staffel; alte Exporte: die eine Konfiguration.
        $staffeln = [];
        if ($hasPaStaffel) {
            foreach ($v3->query("SELECT anzahl_vpe, preis, gewaehlt FROM produktanfrage_staffel WHERE anfrage_id=$v3paid ORDER BY anzahl_vpe")->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $sm = (int)($s['anzahl_vpe'] ?: 0);
                $sp = v3_preis($s['preis'] ?? '');
                // Staffel ohne eigenen Preis: Header-Angebotspreis übernehmen, wenn die Menge passt (sonst 0/„–").
                if ($sp <= 0 && $preis > 0 && $sm === $menge) $sp = $preis;
                if ($sm > 0) $staffeln[] = ['menge'=>$sm, 'preis'=>$sp, 'best'=>(int)($s['gewaehlt'] ?? 0)];
            }
        }
        if (!$staffeln && $menge > 0 && $preis > 0) $staffeln[] = ['menge'=>$menge, 'preis'=>$preis, 'best'=>($conf ? 1 : 0)];
        // Leere Zusatz-Staffeln (Menge ohne Preis) verwerfen, sobald es echte Preis-Staffeln gibt –
        // der Kunde soll keine preislosen Optionen wählen können. Reine Anfragen ohne jeden Preis bleiben (Menge sichtbar).
        $mitPreis = array_values(array_filter($staffeln, fn($s) => $s['preis'] > 0));
        if ($mitPreis) $staffeln = $mitPreis;
        $si = 0;
        foreach ($staffeln as $stf) {
            q("INSERT INTO angebot_staffel (angebot_id,menge,vk_stueck,bestaetigt,sort) VALUES (?,?,?,?,?)",
              [$gid, $stf['menge'], $stf['preis'], $stf['best'], $si++]);
        }
    }
    // Angenommene Angebote an ihren Auftrag hängen: v3 auftrag.anfrage_id -> v4 angebot.v3_id
    foreach ($v3->query("SELECT id, anfrage_id FROM auftraege WHERE anfrage_id IS NOT NULL AND anfrage_id>0")->fetchAll(PDO::FETCH_ASSOC) as $ar) {
        $gid = (int) scalar("SELECT id FROM angebot WHERE v3_id=?", [(int)$ar['anfrage_id']]);
        $aid = (int) scalar("SELECT id FROM auftrag WHERE v3_id=?", [(int)$ar['id']]);
        if ($gid && $aid) { q("UPDATE auftrag SET angebot_id=? WHERE id=?", [$gid, $aid]); q("UPDATE angebot SET anfrage_id=NULL WHERE id=?", [$gid]); $w6['verknuepft']++; }
    }
    echo "\nGESCHRIEBEN (Stufe 6 – Angebote):\n";
    foreach ($w6 as $k => $v) printf("  %-14s %d\n", $k, $v);
}

echo "\n" . str_repeat('=', 72) . "\n";
echo "SUMME würde angelegt:\n";
foreach ($sum as $k => $v) printf("  %-22s %d\n", $k, $v);
echo "\nNacharbeit (" . count($nacharbeit) . "):\n";
foreach (array_slice(array_unique($nacharbeit), 0, 40) as $n) echo "  - $n\n";
if ($sum['zutaten_ohne_match'] > 0) echo "  - {$sum['zutaten_ohne_match']} Zutaten ohne v4-Rohstoff-Treffer (Name abweichend -> nach Import verknüpfen)\n";
echo "\n" . ($WRITE ? "" : ">> Trockenlauf. Mit --write schreiben.\n");
