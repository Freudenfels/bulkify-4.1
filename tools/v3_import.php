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

$v3pfad = $argv[1] ?? '';
$WRITE  = in_array('--write', $argv, true);
if ($v3pfad === '' || !is_file($v3pfad)) { fwrite(STDERR, "v3-Datei fehlt. Aufruf: php tools/v3_import.php <pfad/board.sqlite> [--write]\n"); exit(1); }

try { $v3 = new PDO('sqlite:' . $v3pfad); $v3->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
catch (Throwable $e) { fwrite(STDERR, "SQLite-Treiber fehlt oder Datei unlesbar: " . $e->getMessage() . "\n"); exit(1); }

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
        $adr = trim((string)($k['lieferadresse'] ?? '')); if (mb_strtolower($adr) === 'keine') $adr = '';
        $notiz = 'Aus v3 übernommen (v3-Kunde #' . $v3kid . ')' . ($adr !== '' ? ' · Adresse laut v3: ' . $adr : '');
        $ex = one("SELECT id FROM kunden WHERE v3_id=?", [$v3kid]);
        if ($ex) { $v4kid = (int)$ex['id']; q("UPDATE kunden SET firma=?, email=?, notiz=? WHERE id=?", [cut($k["firma"]), cut($k["email"] ?: null), cut($notiz,500), $v4kid]); $w['kunde_upd']++; }
        else {
            q("INSERT INTO kunden (kundennummer,firma,email,notiz,portal_token,portal_rezeptur,portal_produkte,v3_id) VALUES (?,?,?,?,?,1,1,?)",
              [naechste_nummer('K'), cut($k["firma"]), cut($k["email"] ?: null), cut($notiz,500), bin2hex(random_bytes(16)), $v3kid]);
            $v4kid = (int)insert_id(); $w['kunde_neu']++;
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
    echo "\n" . str_repeat('=', 72) . "\nGESCHRIEBEN (Stufe 1):\n";
    foreach ($w as $k => $v) printf("  %-12s %d\n", $k, $v);

    // ---- Stufe 2: Produkte (je Rezeptur) + Kundenpreise (aus produktanfrage) ----
    ensure_column('produkt', 'v3_id', "INT NULL");   // = v3 rezept_id (ein Produkt je Rezeptur als Anker)
    $w2 = ['produkt_neu'=>0,'preis_neu'=>0,'preis_upd'=>0,'ohne_produkt'=>0];
    $preisParse = function ($s): ?float {
        $s = trim((string)$s); if ($s === '') return null;
        $s = str_replace(['€', ' '], '', $s); $s = str_replace(',', '.', $s);
        return is_numeric($s) ? (float)$s : null;
    };
    foreach ($aktiveKunden as $k) {
        if ((int)($k['intern'] ?? 0) === 1) continue;
        $v3kid = (int)$k['id'];
        $v4kid = (int) scalar("SELECT id FROM kunden WHERE v3_id=?", [$v3kid]);
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
    $land2 = function (?string $l): ?string {
        $l = mb_strtolower(trim((string)$l));
        $m = ['deutschland'=>'DE','germany'=>'DE','china'=>'CN','österreich'=>'AT','oesterreich'=>'AT','schweiz'=>'CH','italien'=>'IT','spanien'=>'ES','indien'=>'IN','usa'=>'US','vereinigte staaten'=>'US','niederlande'=>'NL','frankreich'=>'FR','polen'=>'PL','irland'=>'IE'];
        return $m[$l] ?? null;
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
}

echo "\n" . str_repeat('=', 72) . "\n";
echo "SUMME würde angelegt:\n";
foreach ($sum as $k => $v) printf("  %-22s %d\n", $k, $v);
echo "\nNacharbeit (" . count($nacharbeit) . "):\n";
foreach (array_slice(array_unique($nacharbeit), 0, 40) as $n) echo "  - $n\n";
if ($sum['zutaten_ohne_match'] > 0) echo "  - {$sum['zutaten_ohne_match']} Zutaten ohne v4-Rohstoff-Treffer (Name abweichend -> nach Import verknüpfen)\n";
echo "\n" . ($WRITE ? "" : ">> Trockenlauf. Mit --write schreiben.\n");
