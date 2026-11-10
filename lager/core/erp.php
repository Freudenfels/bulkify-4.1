<?php
// DIE NAHT ZUM DASHBOARD. Die einzige Datei im Lager-Programm, die Tabellen des Dashboards kennt.
//
// Warum an einer Stelle: Das Dashboard wird weiterentwickelt (und irgendwann von v5 abgeloest).
// Aendert sich dort eine Spalte, darf genau diese Datei kaputtgehen - ueberall sonst im Lager
// stehen nur `lg_`-Tabellen.
//
// Stand jetzt: nur gelesen (Logins). Geschrieben wird ins Dashboard noch nirgends. Kommen spaeter
// Buchungen dazu (Entnahme fuer einen Produktionsauftrag), stehen sie als benannte Funktion hier.
require_once __DIR__ . '/db.php';

function erp_benutzer_per_mail(string $email): ?array {
    if (!tabelle_da('benutzer')) return null;
    return one("SELECT id, name, email, pass_hash, rollen, aktiv FROM benutzer WHERE email=? AND aktiv=1",
               [trim(mb_strtolower($email))]);
}
function erp_benutzer_per_token(string $token): ?array {
    if (!tabelle_da('benutzer') || trim($token) === '') return null;
    return one("SELECT id, name, email, rollen FROM benutzer WHERE login_token=? AND aktiv=1", [trim($token)]);
}
function erp_benutzer(int $id): ?array {
    if (!tabelle_da('benutzer')) return null;
    return one("SELECT id, name, email, rollen FROM benutzer WHERE id=? AND aktiv=1", [$id]);
}

// Das Dashboard liegt auf derselben Domain unter "/".
function erp_dashboard_url(): string { return '/'; }

// --- Material-Standort der Charge (Spec 6.2): lager1 | produktion | lager2 --------------------
// Die Spalte charge.standort gehoert dem Dashboard (core/schema.php). Sie wird hier NIE angelegt –
// solange sie fehlt, verhaelt sich alles wie 'lager1' (Default), nichts bricht. Erst wenn der
// Orchestrator die Spalte ergaenzt, greift der echte Standort-Wechsel.
function erp_charge_standort_spalte(): bool {
    static $da = null;
    if ($da !== null) return $da;
    if (!tabelle_da('charge')) return $da = false;
    try {
        $da = (int) scalar("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='charge' AND COLUMN_NAME='standort'") > 0;
    } catch (Throwable $e) { $da = false; }
    return $da;
}
// SELECT-Baustein: liefert den echten Standort, sonst konstant 'lager1'. $alias = Charge-Alias.
function erp_standort_sel(string $alias = 'c'): string {
    return erp_charge_standort_spalte()
        ? ", COALESCE($alias.standort,'lager1') AS standort"
        : ", 'lager1' AS standort";
}
// Standort einer Charge lesen (immer ein Wert; 'lager1' als Default).
function erp_charge_standort(int $charge_id): string {
    if ($charge_id <= 0 || !erp_charge_standort_spalte()) return 'lager1';
    $s = (string) scalar("SELECT standort FROM charge WHERE id=?", [$charge_id]);
    return in_array($s, ['lager1', 'produktion', 'lager2'], true) ? $s : 'lager1';
}
function erp_standort_label(string $s): string {
    return ['lager1' => 'Lager 1', 'produktion' => 'In Produktion', 'lager2' => 'Lager 2'][$s] ?? 'Lager 1';
}
// Standort setzen (Entnahme in die Produktion / Rueckgabe ins Lager 1). Der Blinker bleibt dran –
// nur der Standort wandert. Rueckgabe ['ok','meldung','alt']. Fehlt die Dashboard-Spalte: freundlicher
// Hinweis statt Fehler (der Orchestrator muss charge.standort erst ergaenzen).
function erp_charge_standort_setzen(int $charge_id, string $standort): array {
    if (!tabelle_da('charge')) return ['ok' => false, 'meldung' => 'Keine Charge-Tabelle.'];
    if (!in_array($standort, ['lager1', 'produktion', 'lager2'], true))
        return ['ok' => false, 'meldung' => 'Unbekannter Standort.'];
    if (!erp_charge_standort_spalte())
        return ['ok' => false, 'meldung' => 'Standort-Verfolgung ist noch nicht freigeschaltet (Spalte charge.standort fehlt – wird vom Dashboard ergänzt).'];
    $c = one("SELECT standort, fremd_kunde_id FROM charge WHERE id=?", [$charge_id]);
    if (!$c) return ['ok' => false, 'meldung' => 'Charge nicht gefunden.'];
    $alt = (string)($c['standort'] ?? 'lager1') ?: 'lager1';
    q("UPDATE charge SET standort=? WHERE id=?", [$standort, $charge_id]);
    return ['ok' => true, 'meldung' => 'Standort: ' . erp_standort_label($standort), 'alt' => erp_standort_label($alt)];
}

// --- Chargen des grossen Lagers (fuer das Chaos-Finden) ---------------------------------------
// Nur EIGENER Bestand: Fremdlager-Chargen (charge.fremd_kunde_id gesetzt = Kundenware) bleiben aussen
// vor, die gehoeren ins Fulfillment. Leere Chargen (status='leer' oder menge_verfuegbar<=0) auch nicht.
function erp_charge_select(): string {
    return "SELECT c.id, c.charge_nr, c.menge_verfuegbar, c.einheit, c.mhd, c.status,
                   i.name AS item_name, i.artikelnummer, i.kategorie" . erp_standort_sel('c') . "
            FROM charge c JOIN item i ON i.id = c.item_id";
}
function erp_charge(int $id): ?array {
    if (!tabelle_da('charge')) return null;
    return one(erp_charge_select() . " WHERE c.id=?", [$id]);
}
// Daten für ein Proben-Etikett (Rückstellmuster). Liest prod_probe + Rohstoff/Charge/Produktionsauftrag.
// Dashboard-Tabellen – wie überall im Lager nur über diese Naht (lager/core/erp.php).
function erp_probe_etikett_daten(int $probe_id): ?array {
    if (!tabelle_da('prod_probe') || $probe_id <= 0) return null;
    $p = one("SELECT pr.*, COALESCE(NULLIF(i.name,''), pr.bezeichnung) AS item_name, i.artikelnummer,
                     c.charge_nr, c.mhd, c.item_id AS c_item_id, pa.nummer AS pa_nummer
              FROM prod_probe pr
              LEFT JOIN item i   ON i.id = pr.item_id
              LEFT JOIN charge c ON c.id = pr.charge_id
              LEFT JOIN produktionsauftrag pa ON pa.id = pr.pa_id
              WHERE pr.id=?", [$probe_id]);
    return $p ?: null;
}
// Daten für ein Eimer-/Mischgebinde-Etikett (eine Gebinde-Untercharge aus dem Mischen).
function erp_mischgebinde_etikett_daten(int $sub_id): ?array {
    if (!tabelle_da('prod_charge') || $sub_id <= 0) return null;
    return one("SELECT sc.id, sc.nummer, sc.gebinde, sc.menge, sc.einheit, sc.tag,
                       pc.nummer AS haupt_nummer,
                       COALESCE(NULLIF(p.kundenname,''), p.name, a.produkt_bezeichnung, r.name) AS produkt_name,
                       pa.nummer AS pa_nummer
                FROM prod_charge sc
                LEFT JOIN prod_charge pc        ON pc.id = sc.parent_id
                LEFT JOIN produktionsauftrag pa ON pa.id = sc.pa_id
                LEFT JOIN produkt p             ON p.id  = sc.produkt_id
                LEFT JOIN auftrag a             ON a.id  = pa.auftrag_id
                LEFT JOIN rezeptur r            ON r.id  = sc.rezeptur_id
                WHERE sc.id=? AND sc.parent_id IS NOT NULL", [$sub_id]) ?: null;
}
// Suche ueber Rohstoffname, Artikelnummer und Chargennummer. Leere/Fremdlager-Chargen raus.
//
// Tolerant fuer die Sprache: die Eingabe wird in einzelne WOERTER zerlegt, und JEDES Wort muss
// irgendwo vorkommen (Name, Artikelnummer oder Chargennummer). So findet "Detox bitter Pulver"
// auch "Detox Bitterpulver", und "Ashwagandha KSM 66" trifft trotz Leerzeichen/Aussprache.
function erp_chargen_suche(string $q, int $limit = 30): array {
    if (!tabelle_da('charge') || !tabelle_da('item')) return [];
    $woerter = preg_split('/\s+/', trim($q), -1, PREG_SPLIT_NO_EMPTY);

    $where = ['c.fremd_kunde_id IS NULL', erp_pk_wo(), "(c.status IS NULL OR c.status <> 'leer')", 'c.menge_verfuegbar > 0'];
    $params = [];
    foreach ($woerter as $w) {
        $where[] = '(i.name LIKE ? OR i.artikelnummer LIKE ? OR c.charge_nr LIKE ?)';
        $like = '%' . $w . '%';
        array_push($params, $like, $like, $like);
    }
    $sql = erp_charge_select() . ' WHERE ' . implode(' AND ', $where)
         . ' ORDER BY i.name, c.mhd LIMIT ' . (int)$limit;
    return all($sql, $params);
}

// --- Bestandsansicht (alle eigenen Chargen, nach Kategorie) -----------------------------------
// Die Reiter im Bestand. Leerkapseln sind KEIN eigener Reiter – sie sind ein Rohstoff
// (kategorie=rohstoff + form=kapselhuelle, nur in Stück statt kg) und laufen unter "Rohstoffe".
// "Bulk / lose" = lose fertige Kapseln/Tabletten (kategorie=fertig, auch zugekauft),
// "Fertige Produkte" = fertig verpackt (kategorie=verkaufsfertig).
function erp_kategorien(): array {
    return [
        'rohstoff'       => 'Rohstoffe',
        'verpackung'     => 'Verpackung',
        'verbrauch'      => 'Verbrauch',
        'fertig'         => 'Bulk / lose',
        'verkaufsfertig' => 'Fertige Produkte',
    ];
}

// SQL-Bedingung fuer eine Lager-Kategorie (auf item i).
function erp_kategorie_bedingung(string $kat): string {
    return match ($kat) {
        'rohstoff'       => "i.kategorie='rohstoff'",          // inkl. Leerkapseln (form=kapselhuelle)
        'verpackung'     => "i.kategorie='verpackung'",
        'verbrauch'      => "i.kategorie='verbrauch'",
        'fertig'         => "i.kategorie='fertig'",            // Bulk / lose
        'verkaufsfertig' => "i.kategorie='verkaufsfertig'",    // fertig verpackt
        default          => '1',
    };
}

// WHERE-Bedingung, die im Papierkorb liegende (lager-seitig "geloeschte") Chargen ausblendet.
// $alias = Alias der charge-Tabelle in der jeweiligen Query.
function erp_pk_wo(string $alias = 'c'): string {
    return tabelle_da('lg_papierkorb') ? "$alias.id NOT IN (SELECT charge_id FROM lg_papierkorb)" : '1=1';
}

// Bestand auflisten. $kat = '' fuer alle, sonst ein Schluessel aus erp_kategorien().
// $mit_leer = auch leere/ausgebuchte Chargen zeigen.
// $sort: neu (Standard, neuste zuerst) | alt | name | mhd | menge
// ===== Soll-Kette 7: Versand aus Aufträgen (04.11.2026) =====
// Versandarten am Auftrag. „Lager 2“ = Einbuchen ins Fremdlager des Kunden statt Versand.
function erp_versandarten(): array { return ['paket' => 'Paket an die Kundenadresse', 'palette' => 'Palette', 'abholung' => 'Selbstabholung', 'lager2' => 'Einbuchen in Lager 2']; }
// Versandbereite Aufträge: Produktion abgeschlossen (Status erledigt) und noch nicht alles versendet, mit freier Fertigware.
function erp_versandbereite_auftraege(): array {
    if (!tabelle_da('auftrag')) return [];
    $va = erp_spalte_da('auftrag', 'versandart') ? 'a.versandart' : 'NULL';
    return all("SELECT a.id, a.nummer, a.kunde_id, a.menge, COALESCE(a.versendet_menge,0) AS versendet, $va AS versandart,
                       COALESCE(NULLIF(a.produkt_bezeichnung,''), p.name) AS produkt, k.firma AS kunde, COALESCE(k.nutzt_fulfillment,0) AS fulfillment,
                       (SELECT COALESCE(SUM(c.menge_verfuegbar),0) FROM charge c JOIN item i ON i.id=c.item_id
                         WHERE i.kategorie='verkaufsfertig' AND c.status='frei' AND c.fremd_kunde_id IS NULL AND c.menge_verfuegbar>0
                           AND (c.auftrag_id=a.id OR c.pa_id IN (SELECT pa.id FROM produktionsauftrag pa WHERE pa.auftrag_id=a.id))) AS fertig_frei
                FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id LEFT JOIN kunden k ON k.id=a.kunde_id
                WHERE a.status='erledigt' AND COALESCE(a.versendet_menge,0) < a.menge AND COALESCE(a.kategorie,'produkt') <> 'dienstleistung'
                ORDER BY k.firma, a.nummer");
}
function erp_auftrag_versandart_setzen(int $auftrag_id, string $art): bool {
    if (!isset(erp_versandarten()[$art]) || !erp_spalte_da('auftrag', 'versandart')) return false;
    return q("UPDATE auftrag SET versandart=? WHERE id=? AND status='erledigt'", [$art, $auftrag_id])->rowCount() > 0;
}
// Freie Fertigware-Chargen eines Auftrags (am Auftrag oder aus seinen Produktionsaufträgen), FEFO.
function erp_auftrag_fertig_chargen(int $auftrag_id): array {
    return all("SELECT c.id, c.charge_nr, c.item_id, c.menge_verfuegbar, c.einheit, c.mhd, i.name AS item_name
                FROM charge c JOIN item i ON i.id=c.item_id
                WHERE i.kategorie='verkaufsfertig' AND c.status='frei' AND c.fremd_kunde_id IS NULL AND c.menge_verfuegbar>0
                  AND (c.auftrag_id=? OR c.pa_id IN (SELECT pa.id FROM produktionsauftrag pa WHERE pa.auftrag_id=?))
                ORDER BY (c.mhd IS NULL), c.mhd, c.id", [$auftrag_id, $auftrag_id]);
}
// Dashboard-Buchung per Loopback (?p=api_versand, Token wie Einlagern): 'versenden' (auftrag_id, menge) bzw. 'fremdlager'.
function erp_dashboard_versand(string $aktion, int $auftrag_id, int $menge = 0): array {
    if (!function_exists('curl_init')) return ['ok' => false, 'msg' => 'PHP-curl fehlt.'];
    $token = erp_einlager_token();
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $ch = curl_init($scheme . '://' . (string)($_SERVER['HTTP_HOST'] ?? 'app.bulkify.pro') . '/?p=api_versand');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POSTFIELDS => http_build_query(['aktion' => $aktion, 'auftrag_id' => $auftrag_id, 'menge' => $menge, 'token' => $token]),
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 2, CURLOPT_POSTREDIR => 7]);
    if (defined('CURLSSLOPT_NATIVE_CA')) curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    $res = curl_exec($ch); $err = $res === false ? curl_error($ch) : ''; curl_close($ch);
    $j = json_decode((string)$res, true);
    return is_array($j) ? $j : ['ok' => false, 'msg' => 'Dashboard nicht erreichbar' . ($err !== '' ? ': ' . $err : '.')];
}
// Soll-Kette 6: Daten für die Kartonetiketten eines Produktionsauftrags (Werte des letzten Drucks am PA).
function erp_karton_etikett_daten(int $pa_id): ?array {
    if (!tabelle_da('produktionsauftrag') || !erp_spalte_da('produktionsauftrag', 'karton_stueck')) return null;
    $d = one("SELECT pa.nummer, pa.karton_stueck, pa.karton_menge, pa.karton_charge, pa.karton_mhd, a.nummer AS auftrag_nr, k.firma AS kunde,
                     COALESCE(NULLIF(a.produkt_bezeichnung,''), p.name) AS produkt
              FROM produktionsauftrag pa LEFT JOIN auftrag a ON a.id=pa.auftrag_id LEFT JOIN kunden k ON k.id=COALESCE(a.kunde_id, pa.kunde_id)
              LEFT JOIN produkt p ON p.id=pa.produkt_id WHERE pa.id=?", [$pa_id]);
    if (!$d || (int)$d['karton_stueck'] <= 0 || (int)$d['karton_menge'] <= 0) return null;
    return $d;
}
// Soll-Kette 3 (04.11.2026): Auftragsbezug je Charge – damit das Lager dasselbe sieht wie die Produktion.
// fuer_auftrag = Auftrag der Charge (sonst der Auftrag der Bestellposition, aus der sie kam) mit Kunde;
// reserviert_fuer = aktive Reservierungen auf diese Charge bzw. ihre Bestellposition; kapselgroesse = Name (Soll-Kette 2d).
function erp_bezug_sel(string $a = 'c'): string {
    $s = erp_spalte_da('charge', 'kapselgroesse_id') && tabelle_da('kapselgroesse')
        ? ", (SELECT kg.name FROM kapselgroesse kg WHERE kg.id=$a.kapselgroesse_id) AS kapselgroesse" : ", NULL AS kapselgroesse";
    $bpAuf = tabelle_da('bestellung_position') && erp_spalte_da('charge', 'bestellung_position_id')
        ? "(SELECT bpx.auftrag_id FROM bestellung_position bpx WHERE bpx.id=$a.bestellung_position_id)" : "NULL";
    $s .= tabelle_da('auftrag')
        ? ", (SELECT CONCAT(au.nummer, COALESCE(CONCAT(' · ', k.firma), '')) FROM auftrag au LEFT JOIN kunden k ON k.id=au.kunde_id
              WHERE au.id = COALESCE($a.auftrag_id, $bpAuf)) AS fuer_auftrag" : ", NULL AS fuer_auftrag";
    $s .= tabelle_da('reservierung') && tabelle_da('auftrag')
        ? ", (SELECT GROUP_CONCAT(DISTINCT au.nummer ORDER BY au.nummer SEPARATOR ', ') FROM reservierung r JOIN auftrag au ON au.id=r.auftrag_id
              WHERE r.status='aktiv' AND (r.charge_id=$a.id" . (erp_spalte_da('charge', 'bestellung_position_id') ? " OR (r.bestellung_position_id IS NOT NULL AND r.bestellung_position_id=$a.bestellung_position_id)" : "") . ")) AS reserviert_fuer"
        : ", NULL AS reserviert_fuer";
    return $s;
}
// Eine Zeile Text dazu (für Listen): „Größe 0 · für AB-2707 · Kunde · reserviert: AB-2710“.
function erp_bezug_text(array $c): string {
    $t = [];
    if (!empty($c['kapselgroesse'])) $t[] = (string)$c['kapselgroesse'];
    if (!empty($c['fuer_auftrag']))  $t[] = 'für ' . (string)$c['fuer_auftrag'];
    if (!empty($c['reserviert_fuer'])) $t[] = 'reserviert: ' . (string)$c['reserviert_fuer'];
    return implode(' · ', $t);
}
function erp_bestand(string $kat = '', string $q = '', bool $mit_leer = false, int $limit = 500, string $sort = 'neu'): array {
    if (!tabelle_da('charge') || !tabelle_da('item')) return [];
    $lief = tabelle_da('lieferanten');
    $where = ['c.fremd_kunde_id IS NULL', erp_pk_wo()];
    $params = [];
    if (!$mit_leer) $where[] = "(c.status IS NULL OR c.status<>'leer') AND c.menge_verfuegbar>0";
    if ($kat !== '' && isset(erp_kategorien()[$kat])) $where[] = '(' . erp_kategorie_bedingung($kat) . ')';
    foreach (preg_split('/\s+/', trim($q), -1, PREG_SPLIT_NO_EMPTY) as $w) {
        $where[] = '(i.name LIKE ? OR i.artikelnummer LIKE ? OR c.charge_nr LIKE ?)';
        $like = '%' . $w . '%'; array_push($params, $like, $like, $like);
    }
    $order = match ($sort) {
        'alt'   => 'c.wareneingang IS NULL, c.wareneingang ASC, c.id ASC',
        'name'  => 'i.name, c.mhd IS NULL, c.mhd',
        'mhd'   => 'c.mhd IS NULL, c.mhd ASC, i.name',
        'menge' => 'c.menge_verfuegbar DESC, i.name',
        default => 'c.wareneingang IS NULL, c.wareneingang DESC, c.id DESC',   // neu
    };
    $sql = "SELECT c.id, c.charge_nr, c.menge_verfuegbar, c.menge, c.einheit, c.mhd, c.status, c.wareneingang,
                   i.id AS item_id, i.name AS item_name, i.artikelnummer, i.kategorie, i.form"
         . erp_standort_sel('c') . erp_bezug_sel('c')
         . ($lief ? ", l.firma AS lieferant" : ", NULL AS lieferant") . "
            FROM charge c JOIN item i ON i.id=c.item_id"
         . ($lief ? " LEFT JOIN lieferanten l ON l.id=c.lieferant_id" : "") . "
            WHERE " . implode(' AND ', $where) . "
            ORDER BY $order LIMIT " . (int)$limit;
    return all($sql, $params);
}

// Anzahl je Kategorie (fuer die Reiter). Zaehlt nur nicht-leere eigene Chargen.
function erp_bestand_zaehlung(): array {
    $out = [];
    foreach (array_keys(erp_kategorien()) as $k) {
        $out[$k] = (int)scalar("SELECT COUNT(*) FROM charge c JOIN item i ON i.id=c.item_id
                                WHERE c.fremd_kunde_id IS NULL AND (c.status IS NULL OR c.status<>'leer')
                                  AND c.menge_verfuegbar>0 AND " . erp_pk_wo() . " AND (" . erp_kategorie_bedingung($k) . ")");
    }
    return $out;
}

// Eine Charge zwischen Lager 1 (eigener Bestand) und Lager 2 (Fremdlager eines Kunden) umbuchen.
// $kunde_id > 0 -> gehoert dem Kunden (Lager 2); null/0 -> zurueck in den eigenen Bestand (Lager 1).
// $menge: Teilmenge. null oder >= verfuegbar -> ganze Charge. Sonst wird die Charge GESPLITTET:
//   eine neue Charge (gleiche Chargennummer/MHD) mit der Teilmenge geht ins Ziel, der Rest bleibt.
function erp_charge_umbuchen(int $charge_id, ?int $kunde_id, ?float $menge = null): array {
    if (!tabelle_da('charge')) return ['ok' => false, 'meldung' => 'Keine Charge-Tabelle.'];
    $c = one("SELECT * FROM charge WHERE id=?", [$charge_id]);
    if (!$c) return ['ok' => false, 'meldung' => 'Charge nicht gefunden.'];
    if ($kunde_id && $kunde_id > 0 && tabelle_da('kunden') && !scalar("SELECT id FROM kunden WHERE id=?", [$kunde_id]))
        return ['ok' => false, 'meldung' => 'Kunde nicht gefunden.'];
    $ziel     = ($kunde_id && $kunde_id > 0) ? (int)$kunde_id : null;
    $zielname = $ziel ? 'Fremdlager (Lager 2)' : 'eigenen Bestand (Lager 1)';
    $verf     = (float)$c['menge_verfuegbar'];
    $fmt = fn(float $v): string => rtrim(rtrim(number_format($v, 3, ',', '.'), '0'), ',');

    // Ganze Charge umbuchen (keine/zu grosse Teilmenge).
    // Standort folgt dem Besitzer (S-16: Kundenware steht in Lager 2, eigene in Lager 1).
    $hatOrt = (int) scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='charge' AND COLUMN_NAME='standort'") > 0;
    if ($menge === null || $menge <= 0 || $menge + 1e-9 >= $verf) {
        q("UPDATE charge SET fremd_kunde_id=?" . ($hatOrt ? ", standort=?" : "") . " WHERE id=?", $hatOrt ? [$ziel, $ziel ? 'lager2' : 'lager1', $charge_id] : [$ziel, $charge_id]);
        return ['ok' => true, 'meldung' => 'Komplett in den ' . $zielname . ' umgebucht.'];
    }

    // Teilmenge: neue Charge mit der Teilmenge anlegen, Rest bleibt an der alten.
    $moved = $menge;
    q("INSERT INTO charge (charge_nr,item_id,menge,menge_verfuegbar,einheit,mhd,wareneingang,status,fremd_kunde_id,notiz,lieferant_id,auftrag_id,pa_id,bestellung_position_id,coa_freigegeben,angelegt)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
      [$c['charge_nr'], (int)$c['item_id'], $moved, $moved, $c['einheit'], $c['mhd'], $c['wareneingang'], $c['status'],
       $ziel, $c['notiz'], $c['lieferant_id'], $c['auftrag_id'], $c['pa_id'], $c['bestellung_position_id'] ?? null,
       $c['coa_freigegeben'] ?? 0, gmdate('Y-m-d H:i:s')]);
    $neu = (int) insert_id();
    if ($hatOrt) q("UPDATE charge SET standort=? WHERE id=?", [$ziel ? 'lager2' : 'lager1', $neu]);
    if (!empty($c['kapselgroesse_id'])) q("UPDATE charge SET kapselgroesse_id=? WHERE id=?", [(int)$c['kapselgroesse_id'], $neu]);   // Teilmenge erbt die Kapselgröße
    q("UPDATE charge SET menge=GREATEST(menge-?,0), menge_verfuegbar=GREATEST(menge_verfuegbar-?,0) WHERE id=?",
      [$moved, $moved, $charge_id]);
    erp_gebinde_abgleich($charge_id);
    return ['ok' => true, 'meldung' => $fmt($moved) . ' ' . (string)$c['einheit'] . ' in den ' . $zielname
                         . ' umgebucht, ' . $fmt($verf - $moved) . ' bleiben.', 'neu_id' => $neu];
}

// --- Umlagern Lager 1 -> Lager 2 (Nico 04.11.2026) --------------------------------------------------------------
// Freie, fertig produzierte Ware (verkaufsfertig, eigener Bestand in Lager 1, nicht Quarantäne) zum Umlagern ins Fremdlager
// des Kunden. Besitzer = Kunde des Auftrags der Charge, sonst des Produktionsauftrags, sonst der Kunde am Produkt (exklusiv).
// Gibt es keinen davon, bleibt der Besitzer offen (die Seite fragt nach – es wird nie geraten).
function erp_umlagern_kandidaten(): array {
    if (!tabelle_da('charge') || !tabelle_da('item')) return [];
    $pkn = tabelle_da('produkt_kunde_name');
    $rows = all("SELECT c.id, c.charge_nr, c.mhd, c.menge_verfuegbar, COALESCE(NULLIF(c.einheit,''), i.einheit, 'Stk') AS einheit,
                        c.auftrag_id, c.pa_id, i.id AS item_id, i.name AS item_name, i.produkt_id,
                        a.nummer AS auftrag_nr,
                        COALESCE(NULLIF(a.kunde_id,0), NULLIF(pa.kunde_id,0), NULLIF(p.kunde_id,0)) AS besitzer_id,
                        p.name AS produkt_name, p.kundenname AS produkt_kundenname
                 FROM charge c JOIN item i ON i.id=c.item_id
                 LEFT JOIN produktionsauftrag pa ON pa.id=c.pa_id
                 LEFT JOIN auftrag a ON a.id=COALESCE(c.auftrag_id, pa.auftrag_id)
                 LEFT JOIN produkt p ON p.id=i.produkt_id
                 WHERE i.kategorie='verkaufsfertig' AND c.status='frei' AND c.fremd_kunde_id IS NULL AND c.menge_verfuegbar>0
                   AND (c.standort IS NULL OR c.standort<>'lager2')
                 ORDER BY i.name, (c.mhd IS NULL), c.mhd, c.id");
    foreach ($rows as &$r) {
        $kid = (int)($r['besitzer_id'] ?? 0);
        $r['besitzer_id'] = $kid;
        $k = $kid ? one("SELECT firma, COALESCE(nutzt_fulfillment,0) AS ff FROM kunden WHERE id=?", [$kid]) : null;
        $r['besitzer'] = $k ? (string)$k['firma'] : '';
        $r['fulfillment'] = $k ? (int)$k['ff'] === 1 : false;
        // Anzeigename: kundenspezifischer Produktname des Besitzers, sonst Kundenname am Produkt, sonst Produkt/Artikel.
        $kn = ($pkn && $kid && !empty($r['produkt_id'])) ? (string) scalar("SELECT name FROM produkt_kunde_name WHERE produkt_id=? AND kunde_id=?", [(int)$r['produkt_id'], $kid]) : '';
        $r['anzeige'] = $kn !== '' ? $kn : ((string)($r['produkt_kundenname'] ?? '') !== '' ? (string)$r['produkt_kundenname'] : ((string)($r['produkt_name'] ?? '') ?: (string)$r['item_name']));
    }
    unset($r);
    return $rows;
}
// Eine Teilmenge (oder die ganze) Charge ins Lager 2 des Kunden umlagern: Lager 1 sinkt, die Menge liegt danach NUR im Lager 2
// (fremd_kunde_id = Kunde, standort lager2) – gleiche Charge-Nr./MHD/Auftrag. Bewegungslog Ausgang (Lager 1) + Eingang (Lager 2). Rückgabe ['ok','meldung'].
function erp_umlagern_lager2(int $charge_id, float $menge, int $kunde_id): array {
    $c = one("SELECT c.*, i.kategorie, i.name AS item_name FROM charge c JOIN item i ON i.id=c.item_id WHERE c.id=?", [$charge_id]);
    if (!$c) return ['ok' => false, 'meldung' => 'Charge nicht gefunden.'];
    if ((string)$c['kategorie'] !== 'verkaufsfertig') return ['ok' => false, 'meldung' => 'Nur fertig produzierte Ware wird hier umgelagert.'];
    if ((string)$c['status'] !== 'frei' || !empty($c['fremd_kunde_id'])) return ['ok' => false, 'meldung' => 'Charge ' . (string)$c['charge_nr'] . ' ist nicht frei in Lager 1.'];
    $verf = (float)$c['menge_verfuegbar'];
    $fmt = fn(float $v): string => rtrim(rtrim(number_format($v, 3, ',', '.'), '0'), ',');
    if ($menge <= 0) return ['ok' => false, 'meldung' => 'Bitte eine Menge größer 0 angeben.'];
    if ($menge > $verf + 1e-9) return ['ok' => false, 'meldung' => 'Charge ' . (string)$c['charge_nr'] . ': nur ' . $fmt($verf) . ' verfügbar.'];
    $k = $kunde_id > 0 ? one("SELECT firma, COALESCE(nutzt_fulfillment,0) AS ff FROM kunden WHERE id=?", [$kunde_id]) : null;
    if (!$k) return ['ok' => false, 'meldung' => 'Charge ' . (string)$c['charge_nr'] . ': Besitzer (Kunde) fehlt.'];
    if ((int)$k['ff'] !== 1) return ['ok' => false, 'meldung' => (string)$k['firma'] . ' nutzt kein Fulfillment – Lager 2 gibt es nur für Fulfillment-Kunden.'];
    $r = erp_charge_umbuchen($charge_id, $kunde_id, $menge);
    if (empty($r['ok'])) return $r;
    $ziel = !empty($r['neu_id']) ? (int)$r['neu_id'] : $charge_id;
    if (function_exists('lg_bewegung_log')) {   // Lager 1 raus, Lager 2 rein – keine Doppelzählung, beides nachvollziehbar
        lg_bewegung_log($charge_id, 'aus', $menge, (string)($c['einheit'] ?: 'Stk'), (string)$c['item_name'], 'Umlagern nach Lager 2 (' . (string)$k['firma'] . ')');
        lg_bewegung_log($ziel, 'ein', $menge, (string)($c['einheit'] ?: 'Stk'), (string)$c['item_name'], 'Umlagern aus Lager 1 (' . (string)$k['firma'] . ')' . ($ziel !== $charge_id ? ', aus Charge #' . $charge_id : ''));
    }
    erp_bedarf_bump();
    return ['ok' => true, 'meldung' => $fmt($menge) . ' ' . (string)($c['einheit'] ?: 'Stk') . ' ' . (string)$c['charge_nr'] . ' ins Lager 2 von ' . (string)$k['firma'] . ' umgelagert.'];
}



// Wahrscheinlicher Kunde einer (Fertigwaren-)Charge: aus Auftrag, sonst Produktionsauftrag, sonst Produkt.
function erp_charge_kunde_vorschlag(int $charge_id): ?int {
    if (!tabelle_da('charge')) return null;
    try {
        $c = one("SELECT auftrag_id, pa_id, item_id FROM charge WHERE id=?", [$charge_id]);
        if (!$c) return null;
        if (!empty($c['auftrag_id']) && tabelle_da('auftrag')) {
            $k = (int) scalar("SELECT kunde_id FROM auftrag WHERE id=?", [(int)$c['auftrag_id']]);
            if ($k > 0) return $k;
        }
        if (!empty($c['pa_id']) && tabelle_da('produktionsauftrag') && tabelle_da('auftrag')) {
            $k = (int) scalar("SELECT a.kunde_id FROM produktionsauftrag pa JOIN auftrag a ON a.id=pa.auftrag_id WHERE pa.id=?", [(int)$c['pa_id']]);
            if ($k > 0) return $k;
        }
        if (!empty($c['item_id']) && tabelle_da('item') && tabelle_da('produkt')) {
            $k = (int) scalar("SELECT p.kunde_id FROM item i JOIN produkt p ON p.id=i.produkt_id WHERE i.id=?", [(int)$c['item_id']]);
            if ($k > 0) return $k;
        }
    } catch (Throwable $e) { return null; }
    return null;
}

function erp_kunde_name(int $id): string {
    if ($id <= 0 || !tabelle_da('kunden')) return '';
    return (string) scalar("SELECT firma FROM kunden WHERE id=?", [$id]);
}

// Verkaufsfertig-Items (Bestand) eines Kunden – für die optionale Bestand-Verknüpfung im Lager-2-Katalog.
function erp_kunde_verkaufsfertig(int $kunde_id): array {
    if ($kunde_id <= 0 || !tabelle_da('item') || !tabelle_da('produkt')) return [];
    $hatAuftrag = tabelle_da('auftrag');
    $sql = "SELECT i.id, i.name, i.artikelnummer, i.bsku
            FROM item i JOIN produkt p ON p.id=i.produkt_id
            WHERE i.kategorie='verkaufsfertig' AND (p.kunde_id=?"
         . ($hatAuftrag ? " OR EXISTS (SELECT 1 FROM auftrag a WHERE a.produkt_id=p.id AND a.kunde_id=?)" : "")
         . ") ORDER BY i.name LIMIT 500";
    $params = $hatAuftrag ? [$kunde_id, $kunde_id] : [$kunde_id];
    try { return all($sql, $params); } catch (Throwable $e) { return []; }
}

// Alle Kunden fuer die Auswahl beim Versand (Warenausgang-Planung).
function erp_kunden_liste(): array {
    if (!tabelle_da('kunden')) return [];
    return all("SELECT id, firma FROM kunden ORDER BY firma");
}

// Produkt-Katalog eines Kunden fuer den Lieferschein-Generator: je Produkt Name, verknuepftes verkaufsfertiges
// Item (fuer EK/Gewicht), EK (Cent), VK (Cent, aus der Preismatrix) und Gewicht je Stueck (g). Ohne Kunde:
// alle Produkte (Suche $q). Der Versicherungswert rechnet sich spaeter aus EK bzw. VK/2.
function erp_versandkatalog(int $kunde_id = 0, string $q = ''): array {
    if (!tabelle_da('produkt')) return [];
    $w = []; $p = [];
    if ($kunde_id > 0) {
        $hatAuftrag = tabelle_da('auftrag');
        $w[] = '(p.kunde_id=?' . ($hatAuftrag ? ' OR EXISTS (SELECT 1 FROM auftrag a WHERE a.produkt_id=p.id AND a.kunde_id=?)' : '') . ')';
        $p[] = $kunde_id; if ($hatAuftrag) $p[] = $kunde_id;
    }
    foreach (preg_split('/\s+/', trim($q), -1, PREG_SPLIT_NO_EMPTY) as $t) {
        $w[] = '(p.name LIKE ? OR p.kundenname LIKE ? OR p.nummer LIKE ?)';
        $like = '%' . $t . '%'; array_push($p, $like, $like, $like);
    }
    $where  = $w ? 'WHERE ' . implode(' AND ', $w) : '';
    $hatVf  = tabelle_da('item');
    $hatPp  = tabelle_da('produkt_preis');
    $itemSub = fn($col) => $hatVf ? "(SELECT i.$col FROM item i WHERE i.produkt_id=p.id AND i.kategorie='verkaufsfertig' ORDER BY i.id LIMIT 1)" : 'NULL';
    $vkSub   = $hatPp ? "(SELECT MIN(pp.vk_preis) FROM produkt_preis pp WHERE pp.produkt_id=p.id AND pp.vk_preis>0)" : 'NULL';
    $sql = "SELECT p.id AS produkt_id, p.nummer, COALESCE(NULLIF(p.kundenname,''), p.name) AS name,
                   " . $itemSub('id') . " AS item_id,
                   " . $itemSub('ek_preis') . " AS ek_eur,
                   " . $itemSub('gewicht_g') . " AS gewicht_g,
                   $vkSub AS vk_eur
            FROM produkt p $where ORDER BY name LIMIT 500";
    try {
        return array_map(function ($r) {
            return [
                'produkt_id' => (int)$r['produkt_id'],
                'item_id'    => (int)($r['item_id'] ?? 0) ?: null,
                'nummer'     => (string)($r['nummer'] ?? ''),
                'name'       => (string)$r['name'],
                'ek_cent'    => $r['ek_eur'] !== null ? (int) round((float)$r['ek_eur'] * 100) : 0,
                'vk_cent'    => $r['vk_eur'] !== null ? (int) round((float)$r['vk_eur'] * 100) : 0,
                'gewicht_g'  => $r['gewicht_g'] !== null ? (int)$r['gewicht_g'] : 0,
            ];
        }, all($sql, $p));
    } catch (Throwable $e) { return []; }
}

// Ursprungsland eines Artikels als ISO2 (für die Zollerklärung) – aus item.herkunftsland (Freitext).
function erp_item_herkunft_iso2(int $item_id): string {
    if ($item_id <= 0 || !tabelle_da('item')) return '';
    $h = trim((string) scalar("SELECT herkunftsland FROM item WHERE id=?", [$item_id]));
    if ($h === '') return '';
    if (strlen($h) === 2 && ctype_alpha($h)) return strtoupper($h);
    $m = ['deutschland'=>'DE','germany'=>'DE','österreich'=>'AT','oesterreich'=>'AT','schweiz'=>'CH','switzerland'=>'CH',
        'frankreich'=>'FR','france'=>'FR','italien'=>'IT','italy'=>'IT','spanien'=>'ES','spain'=>'ES','niederlande'=>'NL',
        'belgien'=>'BE','indien'=>'IN','india'=>'IN','china'=>'CN','usa'=>'US','vereinigte staaten'=>'US','polen'=>'PL',
        'tschechien'=>'CZ','türkei'=>'TR','tuerkei'=>'TR','turkey'=>'TR','vietnam'=>'VN','marokko'=>'MA','ägypten'=>'EG',
        'aegypten'=>'EG','peru'=>'PE','brasilien'=>'BR','brazil'=>'BR','uk'=>'GB','england'=>'GB','grossbritannien'=>'GB',
        'großbritannien'=>'GB'];
    return $m[mb_strtolower($h)] ?? '';
}

// Adressen eines Kunden als Auswahl: Lieferadresse (bevorzugt) + Hauptadresse + Rechnungsadresse.
// Nur Adressen mit Inhalt. Jede: quelle/label/firma/name/strasse/hausnummer/plz/ort/land/email/telefon/bevorzugt.
// Weltweit: land ist ein 2-Buchstaben-Laendercode (Default DE).
function erp_kunde_adressen(int $kunde_id): array {
    if ($kunde_id <= 0 || !tabelle_da('kunden')) return [];
    $k = one("SELECT * FROM kunden WHERE id=?", [$kunde_id]);
    if (!$k) return [];
    $firma   = (string)($k['firma'] ?? '');
    $email   = (string)($k['email'] ?? '');
    $telefon = (string)($k['telefon'] ?? '');
    $hatLiefer = trim((string)($k['liefer_strasse'] ?? '') . ($k['liefer_ort'] ?? '') . ($k['liefer_plz'] ?? '')) !== '';
    $out = [];
    if ($hatLiefer) {
        $out[] = ['quelle' => 'liefer', 'label' => 'Lieferadresse', 'firma' => $firma, 'name' => '',
            'strasse' => (string)($k['liefer_strasse'] ?? ''), 'hausnummer' => (string)($k['liefer_hausnummer'] ?? ''),
            'plz' => (string)($k['liefer_plz'] ?? ''), 'ort' => (string)($k['liefer_ort'] ?? ''),
            'land' => strtoupper((string)($k['liefer_land'] ?? '') ?: (string)($k['land'] ?? 'DE')),
            'email' => $email, 'telefon' => $telefon, 'bevorzugt' => true];
    }
    $out[] = ['quelle' => 'haupt', 'label' => 'Hauptadresse', 'firma' => $firma, 'name' => '',
        'strasse' => (string)($k['strasse'] ?? ''), 'hausnummer' => (string)($k['hausnummer'] ?? ''),
        'plz' => (string)($k['plz'] ?? ''), 'ort' => (string)($k['ort'] ?? ''),
        'land' => strtoupper((string)($k['land'] ?? 'DE') ?: 'DE'),
        'email' => $email, 'telefon' => $telefon, 'bevorzugt' => !$hatLiefer];
    $hatRech = trim((string)($k['rechnung_strasse'] ?? '') . ($k['rechnung_ort'] ?? '')) !== '';
    if ($hatRech) {
        $out[] = ['quelle' => 'rechnung', 'label' => 'Rechnungsadresse', 'firma' => (string)($k['rechnung_firma'] ?? '') ?: $firma, 'name' => '',
            'strasse' => (string)($k['rechnung_strasse'] ?? ''), 'hausnummer' => (string)($k['rechnung_hausnummer'] ?? ''),
            'plz' => (string)($k['rechnung_plz'] ?? ''), 'ort' => (string)($k['rechnung_ort'] ?? ''),
            'land' => strtoupper((string)($k['rechnung_land'] ?? '') ?: (string)($k['land'] ?? 'DE')),
            'email' => $email, 'telefon' => $telefon, 'bevorzugt' => false];
    }
    return $out;
}

// Bestand einer Charge manuell auf einen neuen Wert setzen (Korrektur). Rueckgabe ['ok','meldung',...].
function erp_charge_menge_setzen(int $charge_id, float $neu, string $grund = ''): array {
    if (!tabelle_da('charge')) return ['ok' => false, 'meldung' => 'Keine Charge-Tabelle.'];
    if ($neu < 0) return ['ok' => false, 'meldung' => 'Menge darf nicht negativ sein.'];
    $c = one("SELECT menge_verfuegbar, status, einheit FROM charge WHERE id=?", [$charge_id]);
    if (!$c) return ['ok' => false, 'meldung' => 'Charge nicht gefunden.'];
    $alt = (float)$c['menge_verfuegbar'];
    $status = (string)$c['status'];
    if ($neu <= 1e-9) $status = 'leer';
    elseif ($status === 'leer') $status = 'frei';   // wieder Bestand -> aus "leer" zurueck auf frei
    q("UPDATE charge SET menge_verfuegbar=?, status=? WHERE id=?", [$neu, $status, $charge_id]);
    erp_gebinde_abgleich($charge_id);
    $fmt = rtrim(rtrim(number_format($neu, 3, ',', '.'), '0'), ',');
    return ['ok' => true, 'meldung' => 'Bestand auf ' . $fmt . ' ' . (string)$c['einheit'] . ' gesetzt.',
            'delta' => $neu - $alt, 'einheit' => (string)$c['einheit']];
}

// Kennzahlen fuer die Lager-Startseite (Uebersicht). Ein kompakter Satz Zahlen.
function erp_lager_kennzahlen(): array {
    $o = ['l1_chargen'=>0,'l1_artikel'=>0,'l2_chargen'=>0,'l2_kunden'=>0,'quarantaene'=>0,'mhd_bald'=>0,'mhd_ablauf'=>0];
    if (!tabelle_da('charge')) return $o;
    $aktiv = "(c.status IS NULL OR c.status<>'leer') AND c.menge_verfuegbar>0 AND " . erp_pk_wo();
    $o['l1_chargen'] = (int) scalar("SELECT COUNT(*) FROM charge c WHERE c.fremd_kunde_id IS NULL AND $aktiv");
    $o['l1_artikel'] = (int) scalar("SELECT COUNT(DISTINCT c.item_id) FROM charge c WHERE c.fremd_kunde_id IS NULL AND $aktiv");
    $o['l2_chargen'] = (int) scalar("SELECT COUNT(*) FROM charge c WHERE c.fremd_kunde_id IS NOT NULL AND $aktiv");
    $o['l2_kunden']  = (int) scalar("SELECT COUNT(DISTINCT c.fremd_kunde_id) FROM charge c WHERE c.fremd_kunde_id IS NOT NULL AND $aktiv");
    $o['quarantaene']= (int) scalar("SELECT COUNT(*) FROM charge c WHERE c.status='quarantaene' AND $aktiv");
    $o['mhd_bald']   = (int) scalar("SELECT COUNT(*) FROM charge c WHERE $aktiv AND c.mhd IS NOT NULL AND c.mhd>=CURDATE() AND c.mhd<=DATE_ADD(CURDATE(), INTERVAL 90 DAY)");
    $o['mhd_ablauf'] = (int) scalar("SELECT COUNT(*) FROM charge c WHERE $aktiv AND c.mhd IS NOT NULL AND c.mhd<CURDATE()");
    return $o;
}

// MHD-kritische Chargen (abgelaufen zuerst, dann bald ablaufend) – fuer die Startseite.
function erp_mhd_kritisch(int $tage = 90, int $limit = 10): array {
    if (!tabelle_da('charge') || !tabelle_da('item')) return [];
    $tage = max(0, $tage); $limit = max(1, $limit);
    return all("SELECT c.id, c.charge_nr, c.menge_verfuegbar, c.einheit, c.mhd, c.status, c.fremd_kunde_id,
                       i.name AS item_name, k.firma AS kunde
                FROM charge c JOIN item i ON i.id=c.item_id
                LEFT JOIN kunden k ON k.id=c.fremd_kunde_id
                WHERE (c.status IS NULL OR c.status<>'leer') AND c.menge_verfuegbar>0 AND " . erp_pk_wo() . "
                  AND c.mhd IS NOT NULL AND c.mhd <= DATE_ADD(CURDATE(), INTERVAL $tage DAY)
                ORDER BY c.mhd ASC LIMIT $limit");
}

// ===== Lager 2 (Fremdlager): Chargen, die einem Kunden gehoeren (charge.fremd_kunde_id gesetzt) =====
// Einheitliches Modell: "die Charge gehoert einem Kunden" = Lager 2. Gegenstueck zu erp_bestand (Lager 1).

// Fulfillment-Kunden fuer die Auswahl beim Fremdlager-Wareneingang.
function erp_fulfillment_kunden(): array {
    if (!tabelle_da('kunden')) return [];
    $hat = (int) scalar("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='kunden' AND COLUMN_NAME='nutzt_fulfillment'");
    $w = $hat ? 'WHERE nutzt_fulfillment=1' : '';
    return all("SELECT id, firma FROM kunden $w ORDER BY firma");
}

// Kunden, die aktuell Fremdbestand liegen haben (fuer Filter), mit Chargen-Anzahl.
function erp_bestand_fremd_kunden(): array {
    if (!tabelle_da('charge') || !tabelle_da('kunden')) return [];
    return all("SELECT k.id, k.firma, COUNT(*) AS chargen
                FROM charge c JOIN kunden k ON k.id=c.fremd_kunde_id
                WHERE c.fremd_kunde_id IS NOT NULL AND (c.status IS NULL OR c.status<>'leer') AND c.menge_verfuegbar>0 AND " . erp_pk_wo() . "
                GROUP BY k.id, k.firma ORDER BY k.firma");
}

// Fremdlager-Bestand (Lager 2), optional nach Kunde gefiltert.
function erp_bestand_fremd(int $kunde_id = 0, string $q = '', bool $mit_leer = false, int $limit = 500): array {
    if (!tabelle_da('charge') || !tabelle_da('item')) return [];
    $where = ['c.fremd_kunde_id IS NOT NULL', erp_pk_wo()];
    $params = [];
    if ($kunde_id > 0) { $where[] = 'c.fremd_kunde_id = ?'; $params[] = $kunde_id; }
    if (!$mit_leer) $where[] = "(c.status IS NULL OR c.status<>'leer') AND c.menge_verfuegbar>0";
    foreach (preg_split('/\s+/', trim($q), -1, PREG_SPLIT_NO_EMPTY) as $w) {
        $where[] = '(i.name LIKE ? OR i.artikelnummer LIKE ? OR c.charge_nr LIKE ? OR k.firma LIKE ?)';
        $like = '%' . $w . '%'; array_push($params, $like, $like, $like, $like);
    }
    return all("SELECT c.id, c.charge_nr, c.menge_verfuegbar, c.menge, c.einheit, c.mhd, c.status, c.wareneingang,
                       c.fremd_kunde_id, i.id AS item_id, i.name AS item_name, i.artikelnummer, i.kategorie, i.form,
                       k.firma AS kunde
                FROM charge c JOIN item i ON i.id=c.item_id
                LEFT JOIN kunden k ON k.id=c.fremd_kunde_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY k.firma, i.name, c.mhd IS NULL, c.mhd LIMIT " . (int)$limit, $params);
}

// Suche im Fremdlager (fuer Finden).
function erp_chargen_suche_fremd(string $q, int $kunde_id = 0, int $limit = 30): array {
    if (!tabelle_da('charge')) return [];
    $where = ['c.fremd_kunde_id IS NOT NULL', erp_pk_wo(), "(c.status IS NULL OR c.status<>'leer')", 'c.menge_verfuegbar>0'];
    $params = [];
    if ($kunde_id > 0) { $where[] = 'c.fremd_kunde_id = ?'; $params[] = $kunde_id; }
    foreach (preg_split('/\s+/', trim($q), -1, PREG_SPLIT_NO_EMPTY) as $w) {
        $where[] = '(i.name LIKE ? OR i.artikelnummer LIKE ? OR c.charge_nr LIKE ? OR k.firma LIKE ?)';
        $like = '%' . $w . '%'; array_push($params, $like, $like, $like, $like);
    }
    return all("SELECT c.id, c.charge_nr, c.menge_verfuegbar, c.einheit, c.mhd, i.name AS item_name, k.firma AS kunde
                FROM charge c JOIN item i ON i.id=c.item_id LEFT JOIN kunden k ON k.id=c.fremd_kunde_id
                WHERE " . implode(' AND ', $where) . " ORDER BY i.name LIMIT " . (int)$limit, $params);
}

// Fremdlager-Wareneingang: Kundenware einbuchen -> neue Charge, die dem Kunden gehoert (status 'frei').
function erp_wareneingang_buchen_fremd(int $item_id, float $menge, string $charge_nr, ?string $mhd, int $kunde_id, string $notiz = '', string $einheit = ''): ?int {
    if ($item_id <= 0 || $menge <= 0 || $kunde_id <= 0 || !tabelle_da('charge')) return null;
    $einheit = erp_einheit_norm(trim($einheit));   // Eingabe hat Vorrang
    $itEinheit = (string) scalar("SELECT einheit FROM item WHERE id=?", [$item_id]) ?: 'Stk';
    if ($einheit === '') $einheit = $itEinheit;
    [$menge, $einheit] = erp_menge_in_item_einheit($menge, $einheit, $itEinheit);   // g -> kg usw.
    // Kundenware steht im Lager 2 (S-16) – Standort mitsetzen, sonst erscheint sie unter Lager 1.
    $hatStandort = (bool) scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='charge' AND COLUMN_NAME='standort'");
    q("INSERT INTO charge (charge_nr,item_id,menge,menge_verfuegbar,einheit,mhd,wareneingang,status,fremd_kunde_id,notiz,angelegt" . ($hatStandort ? ",standort" : "") . ")
       VALUES (?,?,?,?,?,?,CURDATE(),'frei',?,?,?" . ($hatStandort ? ",'lager2'" : "") . ")",
      [$charge_nr ?: null, $item_id, $menge, $menge, $einheit, $mhd ?: null, $kunde_id, $notiz ?: 'Fremdlager-Wareneingang', gmdate('Y-m-d H:i:s')]);
    return (int) insert_id();
}

// Eine Charge mit ALLEN Feldern fuer die Detailansicht (inkl. Lieferant, Wareneingang, Tracking).
function erp_charge_voll(int $id): ?array {
    if (!tabelle_da('charge')) return null;
    $lief = tabelle_da('lieferanten');
    $kgSel = erp_bezug_sel('c');   // Kapselgröße + Auftragsbezug (Soll-Kette 2d/3)
    $sql = "SELECT c.*, i.name AS item_name, i.artikelnummer, i.kategorie, i.form, i.verpackung_rolle, i.notiz AS item_notiz" . $kgSel
         . ($lief ? ", l.firma AS lieferant, l.lieferantennummer AS lieferant_nr, l.id AS lieferant_id2" : "") . "
            FROM charge c JOIN item i ON i.id=c.item_id"
         . ($lief ? " LEFT JOIN lieferanten l ON l.id=c.lieferant_id" : "") . "
            WHERE c.id=?";
    return one($sql, [$id]);
}

// --- Produkt/Item-Stammdaten und Umfeld (fuer die ausfuehrliche Detailseite) -------------------
function erp_item_voll(int $id): ?array {
    if (!tabelle_da('item')) return null;
    return one("SELECT * FROM item WHERE id=?", [$id]);
}
// Zugehoeriges Fertigprodukt (nur wenn item ein verkaufsfertiges Produkt ist).
function erp_produkt(int $id): ?array {
    if (!tabelle_da('produkt')) return null;
    return one("SELECT id, nummer, name, kundenname, haltbarkeit, allergene, status FROM produkt WHERE id=?", [$id]);
}
// Wirkstoffe/Naehrstoffe eines Rohstoffs.
function erp_item_wirkstoffe(int $item_id): array {
    if (!tabelle_da('item_wirkstoff') || !tabelle_da('naehrstoff')) return [];
    return all("SELECT n.name, w.gehalt_wert, w.gehalt_einheit, w.gehalt_prozent
                FROM item_wirkstoff w JOIN naehrstoff n ON n.id = w.naehrstoff_id
                WHERE w.item_id=? ORDER BY w.sort, n.name", [$item_id]);
}
// Dokumente (Lieferschein, CoA, Spec ...) zum Item ODER zum Fertigprodukt.
function erp_item_dokumente(int $item_id, ?int $produkt_id = null): array {
    if (!tabelle_da('dokument')) return [];
    $sql = "SELECT id, typ, titel, datei_orig, dok_datum, charge_nr FROM dokument
            WHERE (objekt_typ='item' AND objekt_id=?)";
    $p = [$item_id];
    if ($produkt_id) { $sql .= " OR (objekt_typ='produkt' AND objekt_id=?)"; $p[] = $produkt_id; }
    $sql .= " ORDER BY COALESCE(dok_datum, angelegt) DESC, id DESC";
    return all($sql, $p);
}
function erp_dokument(int $id): ?array {
    if (!tabelle_da('dokument')) return null;
    return one("SELECT datei, datei_orig FROM dokument WHERE id=?", [$id]);
}
// Andere Chargen desselben Produkts/Rohstoffs (fuer die Uebersicht auf der Detailseite).
function erp_item_chargen(int $item_id, int $ausser_charge = 0): array {
    if (!tabelle_da('charge')) return [];
    return all("SELECT id, charge_nr, menge_verfuegbar, einheit, mhd, status
                FROM charge WHERE item_id=? AND fremd_kunde_id IS NULL AND id<>?
                ORDER BY mhd IS NULL, mhd", [$item_id, $ausser_charge]);
}

// Kategorie-Label fuer eine Charge/Item-Zeile (aus kategorie + form).
function erp_kategorie_label(array $c): string {
    if (($c['form'] ?? '') === 'kapselhuelle') return 'Leerkapseln';
    return match ((string)($c['kategorie'] ?? '')) {
        'rohstoff' => 'Rohstoff', 'verpackung' => 'Verpackung', 'verbrauch' => 'Verbrauch',
        'fertig' => 'Bulk / lose', 'verkaufsfertig' => 'Fertiges Produkt', 'maschine' => 'Maschine',
        default => (string)($c['kategorie'] ?? ''),
    };
}

// ==============================================================================================
// SCHREIBEND ins Dashboard (Warenlager-Manager). Bewusst HIER, weil es Dashboard-Tabellen anfasst.
// Wareneingang legt eine Charge an, Warenausgang bucht Bestand ab. Beides spiegelt die Dashboard-
// Logik (wareneingang_buchen / Quarantaene-Regel). Eigene Bewegungs-Historie steht in lg_bewegung.
// ==============================================================================================

// Dashboard-Bedarfs-Cache ungueltig machen (wie bedarf_bump() im Dashboard), damit Einkauf/Bedarf
// nach einer Lager-Buchung sofort stimmen. app_meta ist eine Dashboard-Tabelle -> nur hier.
function erp_bedarf_bump(): void {
    if (!tabelle_da('app_meta')) return;
    $v = (int) scalar("SELECT v FROM app_meta WHERE k='bedarf_version'");
    q("INSERT INTO app_meta (k,v) VALUES ('bedarf_version', ?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [(string)($v + 1)]);
}

// Buchbare Artikel fuer den Wareneingang (Rohstoff/Verpackung/Verbrauch/Fertigware), gesperrte raus.
function erp_items_eingang(): array {
    if (!tabelle_da('item')) return [];
    $hatGesperrt = (int) scalar("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='item' AND COLUMN_NAME='gesperrt'");
    $w = $hatGesperrt ? ' AND gesperrt=0' : '';
    $hatKg = (int) scalar("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='item' AND COLUMN_NAME='kapselgroesse_id'");
    $hatSyn = (int) scalar("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='item' AND COLUMN_NAME='synonyme'");
    // Einkauf gerade ziehen (Teil 1/5): Kapselgröße/Füllgewicht und Synonyme mitgeben - die Auswahl zeigt sie an.
    $rows = all("SELECT i.id, i.name, i.kategorie, i.einheit, i.form, i.verpackung_rolle" . ($hatKg ? ", i.kapselgroesse_id" : ", NULL AS kapselgroesse_id")
              . ", CONCAT_WS(' ', NULLIF(i.synonym,'')" . ($hatSyn ? ", NULLIF(i.synonyme,'')" : "") . ") AS syn,
                  COALESCE(kgi.name, kgr.name) AS kg_name, IF(r.darreichungsform IN ('kapsel','softgel','tablette'), r.einheit_fuellmenge, NULL) AS fuell_mg
                FROM item i LEFT JOIN kapselgroesse kgi ON kgi.id=i.kapselgroesse_id
                LEFT JOIN rezeptur r ON r.id=i.rezeptur_id LEFT JOIN kapselgroesse kgr ON kgr.id=r.kapselgroesse_id
                WHERE i.kategorie IN ('rohstoff','verpackung','verbrauch','fertig','verkaufsfertig')" . str_replace('gesperrt', 'i.gesperrt', $w) . "
                ORDER BY i.name");
    foreach ($rows as &$r) {
        $t = [];
        if ((string)($r['kg_name'] ?? '') !== '') $t[] = 'Größe ' . preg_replace('/^(Größe|Gr\.?)\s*/iu', '', (string)$r['kg_name']);
        if ((float)($r['fuell_mg'] ?? 0) > 0) $t[] = rtrim(rtrim(number_format((float)$r['fuell_mg'], 1, ',', '.'), '0'), ',') . ' mg';
        $r['kapsel'] = implode(' · ', $t);
    }
    unset($r);
    return $rows;
}
// Einkauf gerade ziehen (Teil 1): offene Bestellposition zu einem Artikel (Lieferschein-Scan, Rezeptur-Aufkleber, Etiketten).
// Bevorzugt derselbe Lieferant, dann der früheste Termin. Rückgabe Felder wie in erp_lieferung_positionen (bp_*) oder null.
function erp_offene_bp_fuer_item(int $item_id, int $lieferant_id = 0): ?array {
    if ($item_id <= 0 || !tabelle_da('bestellung_position')) return null;
    require_once __DIR__ . '/../../core/wareneingang_bestellung.php';
    webe_spalten();
    $r = one("SELECT bp.id, bp.bestellung_id, b.nummer, b.lieferant_id, " . erp_bp_rest_sql() . " AS rest, bp.einheit
              FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id
              WHERE bp.item_id=? AND b.status NOT IN ('geliefert','storniert','offen') AND b.angekommen_am IS NULL AND bp.rest_storniert_am IS NULL
                AND " . erp_bp_rest_sql() . " > 0
              ORDER BY (b.lieferant_id <=> ?) DESC, (b.eta_geplant IS NULL), b.eta_geplant, b.id LIMIT 1", [$item_id, $lieferant_id ?: null]);
    if (!$r) return null;
    return ['bp_id' => (int)$r['id'], 'bestellung_id' => (int)$r['bestellung_id'], 'bp_nummer' => (string)$r['nummer'], 'bp_rest' => (float)$r['rest'],
            'bp_einheit' => erp_einheit_norm((string)$r['einheit']), 'lieferant_id' => (int)$r['lieferant_id'], 'kapsel' => webe_kapsel_label($item_id)];
}
// Kapselgrößen-Stammdaten (id, name) für den Wareneingang (Leerkapseln) – klein nach groß.
function erp_kapselgroessen(): array {
    return tabelle_da('kapselgroesse') ? all("SELECT id, name FROM kapselgroesse ORDER BY fuellmenge_mg") : [];
}
// Kapselgröße aus dem Artikelnamen (Spiegel von kapselgroesse_aus_name() im Dashboard): „Gr. 0“, „Größe 00“, „#1“ -> id, sonst 0.
function erp_kapselgroesse_aus_name(string $name): int {
    if (!tabelle_da('kapselgroesse') || !preg_match('/(?:gr(?:ö|oe|o)?(?:ß|ss)?e?\.?|size|#)\s*(000|00|0|1|2|3|4|5)(?!\d)/iu', $name, $m)) return 0;
    return (int) scalar("SELECT id FROM kapselgroesse WHERE name=? LIMIT 1", ['Größe ' . $m[1]]);
}

// --- Etiketten einbuchen: Produkt -> Kundenetiketten (Design + Masse zum Abgleich) --------------
// Produkte, zu denen es einen Etikett-Artikel gibt (Kundenetikett je Produkt+Kunde oder Etikett-Slot am Produkt).
function erp_etikett_produkte(): array {
    if (!tabelle_da('produkt') || !tabelle_da('item')) return [];
    // Je Produkt UND Kunde ein Eintrag (Nico 03.11.2026: im Dropdown fehlte der Kundenname) – so findet man das
    // Etikett auch über den Kunden. Dazu Etiketten ohne Produkt (von Hand angelegt, Maß-Etiketten) als eigene Einträge.
    $rows = all("SELECT p.id AS pid, p.nummer, p.name, COALESCE(NULLIF(p.kundenname,''),'') AS kundenname,
                        COALESCE(i.kunde_id,0) AS kid, COALESCE(MAX(k.firma),'') AS firma, 0 AS iid
                 FROM item i
                 JOIN produkt p ON (i.produkt_id = p.id OR p.etikett_id = i.id)
                 LEFT JOIN kunden k ON k.id = i.kunde_id
                 WHERE i.verpackung_rolle='etikett' AND COALESCE(i.gesperrt,0)=0
                 GROUP BY p.id, p.nummer, p.name, p.kundenname, COALESCE(i.kunde_id,0)
                 ORDER BY p.name, firma");
    $ohne = all("SELECT 0 AS pid, i.artikelnummer AS nummer, i.name, '' AS kundenname,
                        COALESCE(i.kunde_id,0) AS kid, COALESCE(k.firma,'') AS firma, i.id AS iid
                 FROM item i LEFT JOIN kunden k ON k.id = i.kunde_id
                 WHERE i.verpackung_rolle='etikett' AND COALESCE(i.gesperrt,0)=0 AND i.produkt_id IS NULL
                   AND NOT EXISTS (SELECT 1 FROM produkt p WHERE p.etikett_id = i.id)
                 ORDER BY i.name");
    $out = [];
    foreach (array_merge($rows, $ohne) as $r) {
        $titel = trim(((string)$r['nummer'] !== '' ? $r['nummer'] . ' · ' : '') . $r['name']);
        $wer   = (string)$r['firma'] !== '' ? (string)$r['firma'] : ((int)$r['iid'] ? 'ohne Kunde' : 'Standard-Etikett');
        // Von Hand angelegte Etiketten tragen den Kunden schon im Namen -> nicht doppelt anhängen.
        $mitWer = (int)$r['iid'] && (string)$r['firma'] !== '' && mb_stripos($titel, ' · ' . $r['firma']) !== false ? $titel : $titel . ' · ' . $wer;
        $out[] = ['id' => (int)$r['pid'], 'kunde_id' => (int)$r['kid'], 'item_id' => (int)$r['iid'],
                  'n' => $mitWer, 'kundenname' => (string)$r['kundenname'], 'kunde' => (string)$r['firma']];
    }
    return $out;
}
// Ein Etikett-Artikel (item-Zeile mit Vorlage, Kunde, Design) -> Anzeige-Daten für die Sichtprüfung.
function erp_etikett_eintrag(array $r, int $produkt_id = 0): array {
    $dok = (int)($r['dok'] ?? 0);
    $datei = (string)($r['datei_orig'] ?: $r['datei'] ?? '');
    return [
        'id'       => (int)$r['id'],
        'name'     => (string)$r['name'],
        'kunde'    => (string)($r['firma'] ?? ''),
        'kunde_id' => (int)($r['kunde_id'] ?? 0),
        'version'  => (int)$r['version'],
        'kundenetikett' => $produkt_id > 0 && (int)($r['produkt_id'] ?? 0) === $produkt_id,
        'dok'      => $dok > 0 && (string)($r['datei'] ?? '') !== '' ? $dok : 0,
        'pdf'      => $datei === '' || strtolower(pathinfo($datei, PATHINFO_EXTENSION)) === 'pdf',
        'format'   => trim((string)($r['format'] ?? '')),
        'breite'   => $r['breite'] !== null ? (float)$r['breite'] : null,
        'hoehe'    => $r['hoehe'] !== null ? (float)$r['hoehe'] : null,
        'material' => trim((string)($r['material'] ?? '')),
    ];
}
function erp_etikett_sql(string $where): string {
    return "SELECT i.id, i.name, i.kunde_id, COALESCE(i.etikett_version,1) AS version, i.etikett_dokument_id AS dok,
                   COALESCE(NULLIF(i.etikett_format,''), v.etikett_format) AS format,
                   COALESCE(i.breite_mm, v.breite_mm) AS breite, COALESCE(i.hoehe_mm, v.hoehe_mm) AS hoehe,
                   COALESCE(NULLIF(i.material,''), v.material) AS material, i.produkt_id,
                   k.firma, d.datei, d.datei_orig
            FROM item i
            LEFT JOIN item v ON v.id=i.etikett_vorlage_id
            LEFT JOIN kunden k ON k.id=i.kunde_id
            LEFT JOIN dokument d ON d.id=i.etikett_dokument_id
            WHERE i.verpackung_rolle='etikett' AND COALESCE(i.gesperrt,0)=0 AND $where";
}
// Etikett-Artikel eines Produkts: je Kunde die aktuelle (nicht gesperrte) Version, dazu der Etikett-Slot des Produkts,
// falls er kein Kundenetikett ist. Masse/Material vom Artikel, sonst von der Mass-Vorlage (etikett_vorlage_id).
// 'dok' = freigegebenes Design (item.etikett_dokument_id), 'pdf' = ob es ein PDF ist.
function erp_etikett_zu_produkt(int $produkt_id): array {
    if ($produkt_id <= 0 || !tabelle_da('item')) return [];
    $slot = (int) scalar("SELECT COALESCE(etikett_id,0) FROM produkt WHERE id=?", [$produkt_id]);
    $rows = all(erp_etikett_sql('(i.produkt_id=? OR i.id=?)') . " ORDER BY (i.produkt_id IS NULL), k.firma, i.etikett_version DESC", [$produkt_id, $slot]);
    return array_map(fn($r) => erp_etikett_eintrag($r, $produkt_id), $rows);
}
// Ein einzelnes Etikett (Eintrag ohne Produkt im Dropdown, frisch angelegtes Etikett).
function erp_etikett_artikel(int $item_id): array {
    if ($item_id <= 0 || !tabelle_da('item')) return [];
    $r = one(erp_etikett_sql('i.id=?'), [$item_id]);
    return $r ? [erp_etikett_eintrag($r, (int)($r['produkt_id'] ?? 0))] : [];
}
// Maß „B x H" aus einem Format-Text lesen -> [breite, hoehe] oder null.
function erp_etikett_masse(?string $s): ?array {
    if (!$s || !preg_match('/(\d+(?:[.,]\d+)?)\s*[x×*]\s*(\d+(?:[.,]\d+)?)/iu', $s, $m)) return null;
    return [(float) str_replace(',', '.', $m[1]), (float) str_replace(',', '.', $m[2])];
}
// Maßgebliches Etikett-**Endformat (B x H mm) eines Produkts AUS DEM BEHÄLTER** (Glas/Dose). Das ist die
// Größe, gegen die ein geliefertes Etikett geprüft werden muss – NICHT die am Etikett-Artikel gespeicherte
// Größe. Behälter = jüngster Auftrags-Behälter (hat Vorrang, Admin kann je Auftrag ein anderes Glas setzen),
// sonst der am Produkt hinterlegte; das Endformat steht als item.etikett_final am Behälter. Read-only
// Spiegel von produkt_etikettmass() (ohne die Beutel-Neutralisierung, die für Etiketten nicht greift).
function erp_produkt_etikettmass(int $produkt_id): ?array {
    if ($produkt_id <= 0 || !tabelle_da('produkt') || !tabelle_da('item')) return null;
    $bid = 0;
    if (tabelle_da('auftrag')) $bid = (int) scalar("SELECT verpackung_id FROM auftrag WHERE produkt_id=? AND verpackung_id IS NOT NULL ORDER BY id DESC LIMIT 1", [$produkt_id]);
    if (!$bid) $bid = (int) scalar("SELECT verpackung_id FROM produkt WHERE id=?", [$produkt_id]);
    if (!$bid) return null;
    $b = one("SELECT name, etikett_final FROM item WHERE id=?", [$bid]);
    if (!$b) return null;
    $m = erp_etikett_masse((string)($b['etikett_final'] ?? ''));
    return [
        'breite' => $m ? $m[0] : null,
        'hoehe'  => $m ? $m[1] : null,
        'name'   => (string)($b['name'] ?? ''),
        'format' => trim((string)($b['etikett_final'] ?? '')),
    ];
}

// Etikett von Hand anlegen (Lager „Etiketten einbuchen“ -> „Anderes Etikett anlegen“, Nico 03.11.2026): Kunde und
// Produkt wählbar, beide optional. Mit Produkt gilt dieselbe Regel wie im Dashboard (Kundenetikett je Produkt +
// Kunde): gibt es dort schon ein aktuelles Etikett, wird KEIN zweites angelegt, sondern dieses zurückgegeben
// ('vorhanden'). Neue Kette = v1 (bzw. nach gesperrten Versionen die nächste). Maße leer -> vom Etikett-Slot des
// Produkts übernehmen. Ohne Produkt: Bezeichnung Pflicht, gleicher Name -> vorhandener Artikel.
// Rückgabe: ['ok'=>bool, 'id'=>int, 'vorhanden'=>bool, 'fehler'=>string].
function erp_etikett_anlegen(array $d): array {
    if (!tabelle_da('item')) return ['ok' => false, 'fehler' => 'Artikel-Tabelle fehlt.'];
    $kid = (int)($d['kunde_id'] ?? 0); $pid = (int)($d['produkt_id'] ?? 0);
    $kunde = $kid > 0 ? (string) scalar("SELECT firma FROM kunden WHERE id=?", [$kid]) : '';
    if ($kid > 0 && $kunde === '') return ['ok' => false, 'fehler' => 'Kunde nicht gefunden.'];
    $p = $pid > 0 ? one("SELECT id, name, kundenname, kunde_id, etikett_id FROM produkt WHERE id=?", [$pid]) : null;
    if ($pid > 0 && !$p) return ['ok' => false, 'fehler' => 'Produkt nicht gefunden.'];
    $bez = mb_substr(trim((string)($d['bezeichnung'] ?? '')), 0, 120);
    if (!$p && $bez === '') return ['ok' => false, 'fehler' => 'Bitte ein Produkt wählen oder eine Bezeichnung eingeben.'];
    $zahl = function ($v): ?float { $v = str_replace(',', '.', trim((string)$v)); return is_numeric($v) && (float)$v > 0 ? round((float)$v, 1) : null; };
    $b = $zahl($d['breite'] ?? ''); $h = $zahl($d['hoehe'] ?? ''); $mat = mb_substr(trim((string)($d['material'] ?? '')), 0, 100);
    $version = 1; $vorlage = null;
    if ($p) {
        $ex = (int) scalar("SELECT id FROM item WHERE verpackung_rolle='etikett' AND produkt_id=? AND COALESCE(kunde_id,0)=? AND COALESCE(gesperrt,0)=0
                            ORDER BY COALESCE(etikett_version,1) DESC, id DESC LIMIT 1", [$pid, $kid]);
        if ($ex) return ['ok' => true, 'id' => $ex, 'vorhanden' => true, 'fehler' => ''];
        $version = 1 + (int) scalar("SELECT COALESCE(MAX(etikett_version),0) FROM item WHERE verpackung_rolle='etikett' AND produkt_id=? AND COALESCE(kunde_id,0)=?", [$pid, $kid]);
        // Maße/Material vom Etikett-Slot des Produkts (bzw. dessen Maß-Vorlage), wenn nichts eingegeben.
        $slot = (int)($p['etikett_id'] ?? 0) ? one("SELECT COALESCE(i.breite_mm, v.breite_mm) AS b, COALESCE(i.hoehe_mm, v.hoehe_mm) AS h,
                                                           COALESCE(NULLIF(i.material,''), v.material) AS m, COALESCE(i.etikett_vorlage_id, v.id) AS vl
                                                    FROM item i LEFT JOIN item v ON v.id=i.etikett_vorlage_id WHERE i.id=?", [(int)$p['etikett_id']]) : null;
        if ($slot) {
            if ($b === null && $h === null) { $b = $slot['b'] !== null ? (float)$slot['b'] : null; $h = $slot['h'] !== null ? (float)$slot['h'] : null; }
            if ($mat === '') $mat = (string)($slot['m'] ?? '');
            $vorlage = $slot['vl'] ? (int)$slot['vl'] : null;
        }
        $pname = trim((string)($p['kundenname'] ?: $p['name']));
        $name = 'Etikett · ' . ($bez !== '' ? $bez : $pname) . ($kunde !== '' ? ' · ' . $kunde : '') . ' · v' . $version;
    } else {
        $name = 'Etikett · ' . $bez . ($kunde !== '' ? ' · ' . $kunde : '');
        $ex = (int) scalar("SELECT id FROM item WHERE verpackung_rolle='etikett' AND name=? AND COALESCE(gesperrt,0)=0 LIMIT 1", [$name]);
        if ($ex) return ['ok' => true, 'id' => $ex, 'vorhanden' => true, 'fehler' => ''];
    }
    $name = mb_substr($name, 0, 190);
    q("INSERT INTO item (artikelnummer, name, kategorie, verpackungsart, verpackung_rolle, produkt_id, kunde_id, einheit, preis_bezug,
                         breite_mm, hoehe_mm, material, etikett_version, etikett_vorlage_id, gesperrt, notiz)
       VALUES (?, ?, 'verpackung', 'etikett', 'etikett', ?, ?, 'Stk', 'Stk', ?, ?, ?, ?, ?, 0, ?)",
      [erp_naechste_nummer('VP'), $name, $p ? $pid : null, $kid ?: null, $b, $h, $mat !== '' ? $mat : null,
       $p ? $version : null, $vorlage, 'Im Lager unter „Etiketten einbuchen“ von Hand angelegt.']);
    $id = (int) insert_id();
    // Wie im Dashboard: ohne Kunden bzw. beim Kunden eines exklusiven Produkts ist es das Etikett des Produkts – aber nur,
    // wenn der Slot noch leer ist (ein vorhandenes Etikett am Produkt wird hier nie ersetzt).
    if ($p && $id && (int)($p['etikett_id'] ?? 0) === 0 && (!$kid || (int)($p['kunde_id'] ?? 0) === $kid))
        q("UPDATE produkt SET etikett_id=? WHERE id=?", [$id, $pid]);
    return ['ok' => $id > 0, 'id' => $id, 'vorhanden' => false, 'fehler' => $id > 0 ? '' : 'Anlegen fehlgeschlagen.'];
}

// Alle Produkte für die Auswahl „Anderes Etikett anlegen“ (Nummer · Name).
function erp_produkt_auswahl(): array {
    if (!tabelle_da('produkt')) return [];
    return all("SELECT id, nummer, name FROM produkt ORDER BY name");
}

// Typen fuer die Lager-2-Einbuchung -> item.kategorie (+ Verpackungs-Rolle + Verpackungsart) + ob "neu anlegen" erlaubt.
// Verkaufsprodukte werden hier NICHT neu angelegt (gehoeren zum Produkt-Lebenszyklus im Dashboard).
// 'art' = item.verpackungsart (beutel/stick …) – trennt Pouchbag von Rollenware (beide rolle=primaer).
function erp_l2_typ_defs(): array {
    return [
        'verkaufsprodukt' => ['label' => 'Verkaufsprodukt',     'kategorie' => 'verkaufsfertig', 'rolle' => '',        'art' => '',       'neu' => false],
        'rohstoff'        => ['label' => 'Rohstoff',            'kategorie' => 'rohstoff',       'rolle' => '',        'art' => '',       'neu' => true],
        'etikett'         => ['label' => 'Etikett',             'kategorie' => 'verpackung',     'rolle' => 'etikett', 'art' => '',       'neu' => true],
        'beipackzettel'   => ['label' => 'Beipackzettel',       'kategorie' => 'verpackung',     'rolle' => 'beipack', 'art' => '',       'neu' => true],
        'pouchbag'        => ['label' => 'Pouchbag',            'kategorie' => 'verpackung',     'rolle' => 'primaer', 'art' => 'beutel', 'neu' => true],
        'rollenware'      => ['label' => 'Rollenware (Stick)',  'kategorie' => 'verpackung',     'rolle' => 'primaer', 'art' => 'stick',  'neu' => true],
        'karton'          => ['label' => 'Karton',              'kategorie' => 'karton',         'rolle' => '',        'art' => '',       'neu' => true],
        'sonstiges'       => ['label' => 'Sonstiges',           'kategorie' => 'sonstiges',      'rolle' => '',        'art' => '',       'neu' => true],
    ];
}

// Buchbare Artikel fuer Lager 2 (alle Kategorien inkl. Karton/Sonstiges) + Verpackungs-Rolle (fuers Filtern je Typ).
function erp_items_l2(): array {
    if (!tabelle_da('item')) return [];
    $hatSpalte = fn(string $c): bool => (int) scalar("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='item' AND COLUMN_NAME=?", [$c]) > 0;
    $w = $hatSpalte('gesperrt') ? ' AND gesperrt=0' : '';
    $rolleSel = $hatSpalte('verpackung_rolle') ? ', verpackung_rolle AS rolle' : ", '' AS rolle";
    $artSel   = $hatSpalte('verpackungsart')   ? ', verpackungsart AS art'     : ", '' AS art";
    return all("SELECT id, name, kategorie, einheit, form $rolleSel $artSel FROM item
                WHERE kategorie IN ('rohstoff','verpackung','verbrauch','fertig','verkaufsfertig','karton','sonstiges')$w
                ORDER BY name");
}

function erp_lieferanten(): array {
    if (!tabelle_da('lieferanten')) return [];
    return all("SELECT id, firma FROM lieferanten ORDER BY firma");
}

// Firmennamen in bedeutende Wort-Tokens zerlegen (Rechtsform-/Fuellwoerter weg), damit
// "Vita Actives" und "Vita Actives Limited" als gleich erkannt werden.
function erp_name_tokens(string $s): array {
    $teile = preg_split('/[^a-z0-9äöüß]+/u', mb_strtolower(trim($s))) ?: [];
    $stop = ['gmbh','mbh','ag','kg','ohg','ug','gbr','se','ltd','limited','co','company','inc','corp',
             'bv','srl','sa','sl','sarl','und','and','the'];
    $out = [];
    foreach ($teile as $t) if ($t !== '' && mb_strlen($t) >= 2 && !in_array($t, $stop, true)) $out[] = $t;
    return array_values(array_unique($out));
}

// Naechste Nummer aus dem gemeinsamen Nummernkreis (gleiche Logik/Tabelle wie das Dashboard).
function erp_naechste_nummer(string $prefix): string {
    if (function_exists('naechste_nummer')) return naechste_nummer($prefix);
    $prefix = strtoupper(trim($prefix));
    if (!tabelle_da('nummernkreis')) return $prefix . '-' . date('ymdHis');
    q("INSERT IGNORE INTO nummernkreis (prefix, naechste, stellen) VALUES (?, 2690, 4)", [$prefix]);
    // Hochzählen + Lesen unter derselben DB-Sperre wie naechste_nummer() im Dashboard (Aufräumen 16): keine Doppelnummern.
    $nrLock = (int) scalar("SELECT GET_LOCK(?, 5)", ['bx_nr_' . $prefix]) === 1;
    try {
        q("UPDATE nummernkreis SET naechste = naechste + 1 WHERE prefix = ?", [$prefix]);
        $r = one("SELECT naechste - 1 AS nr, stellen FROM nummernkreis WHERE prefix = ?", [$prefix]);
    } finally {
        if ($nrLock) scalar("SELECT RELEASE_LOCK(?)", ['bx_nr_' . $prefix]);
    }
    return $r ? $prefix . '-' . str_pad((string)$r['nr'], (int)$r['stellen'], '0', STR_PAD_LEFT) : $prefix . '-' . date('ymdHis');
}

// Lieferant per Firmenname finden (exakt ODER aehnlich) oder neu anlegen -> id. Stellt sicher,
// dass der Lieferant eine Lieferantennummer hat (fuers Etikett). 0 = nicht moeglich.
function erp_lieferant_finden_oder_anlegen(string $name): int {
    $name = trim($name);
    if ($name === '' || !tabelle_da('lieferanten')) return 0;

    // 1) exakt
    $row = one("SELECT id, lieferantennummer FROM lieferanten WHERE firma=? LIMIT 1", [$name]);

    // 2) aehnlich (Token-Ueberschneidung) – z. B. "Vita Actives Limited" -> "Vita Actives"
    if (!$row) {
        $nt = erp_name_tokens($name);
        if ($nt) {
            $best = null; $bestScore = 0;
            foreach (all("SELECT id, firma, lieferantennummer FROM lieferanten") as $lf) {
                $ft = erp_name_tokens((string)$lf['firma']); if (!$ft) continue;
                $gem = count(array_intersect($nt, $ft)); $klein = min(count($nt), count($ft));
                if ($gem >= $klein && $gem > $bestScore) { $bestScore = $gem; $best = $lf; }  // kuerzere ganz enthalten
            }
            if ($best) $row = $best;
        }
    }

    // 3) gefunden -> Nummer nachtragen, falls keine da
    if ($row) {
        $id = (int)$row['id'];
        if (trim((string)($row['lieferantennummer'] ?? '')) === '') {
            q("UPDATE lieferanten SET lieferantennummer=? WHERE id=? AND (lieferantennummer IS NULL OR lieferantennummer='')",
              [erp_naechste_nummer('L'), $id]);
        }
        return $id;
    }

    // 4) neu anlegen – MIT Lieferantennummer
    try {
        q("INSERT INTO lieferanten (lieferantennummer, firma) VALUES (?, ?)", [erp_naechste_nummer('L'), $name]);
        $id = (int) insert_id();
        if ($id) return $id;
    } catch (Throwable $e) { /* Unique-Race -> unten nochmal lesen */ }
    $row = one("SELECT id FROM lieferanten WHERE firma=? LIMIT 1", [$name]);
    return $row ? (int)$row['id'] : 0;
}

// Ein Item knapp (Name/Einheit/Kategorie/Form) – fuer Anzeige nach der Auswahl.
function erp_item_basis(int $id): ?array {
    if (!tabelle_da('item')) return null;
    return one("SELECT id, name, einheit, kategorie, form, verpackung_rolle FROM item WHERE id=?", [$id]);
}

// Neuen Artikel direkt aus dem Lager anlegen (Wareneingang „neue Sache einpflegen"). Minimal: Name,
// Kategorie, Einheit. Keine Artikelnummer (die Nummernkreise des Dashboards sind hier nicht geladen) –
// das Team ergaenzt Details spaeter im Dashboard. Doppelte (gleicher Name + Kategorie) werden
// wiederverwendet. Gibt die item-id oder null.
// Soll-Kette 2b (04.11.2026): jede im Wareneingang neu angelegte Position als Hinweis ans Team (Benachrichtigung im
// Dashboard) – damit geprüft wird, ob es den Artikel schon gibt (zusammenführen) oder ob die Ware zu einer Bestellung gehört.
function erp_hinweis_neuer_artikel(int $item_id, string $name, string $warenart, int $charge_id, string $menge): void {
    if (!tabelle_da('benachrichtigung') || $item_id <= 0) return;
    $u = function_exists('lg_benutzer') ? lg_benutzer() : null;
    $betreff = 'Neuer Artikel im Wareneingang: ' . $name;
    $text = 'Im Lager neu angelegt (' . $warenart . ($menge !== '' ? ', ' . $menge : '') . (!empty($u['name']) ? ', von ' . $u['name'] : '')
          . '). Bitte prüfen: Gibt es den Artikel schon (dann zusammenführen) oder gehört die Ware zu einer Bestellung?';
    $link = $charge_id > 0 ? '?p=chargen&id=' . $charge_id : '?p=rohstoff&id=' . $item_id;
    try { q("INSERT INTO benachrichtigung (betreff,text,link,art,erstellt) VALUES (?,?,?,?,?)", [$betreff, $text, $link, 'artikel', gmdate('Y-m-d H:i:s')]); }
    catch (\Throwable $e) { try { q("INSERT INTO benachrichtigung (betreff,text,link,erstellt) VALUES (?,?,?,?)", [$betreff, $text, $link, gmdate('Y-m-d H:i:s')]); } catch (\Throwable $e2) {} }
}
function erp_item_anlegen(string $name, string $kategorie, string $einheit, string $rolle = '', string $art = ''): ?int {
    if (!tabelle_da('item')) return null;
    $name = trim($name);
    if ($name === '') return null;
    $erlaubt = ['rohstoff', 'verpackung', 'verbrauch', 'fertig', 'karton', 'sonstiges'];
    if (!in_array($kategorie, $erlaubt, true)) $kategorie = 'rohstoff';
    $einheit = erp_einheit_norm($einheit);
    if ($einheit === '') $einheit = $kategorie === 'rohstoff' ? 'kg' : 'Stk';
    $ex = scalar("SELECT id FROM item WHERE name=? AND kategorie=? LIMIT 1", [$name, $kategorie]);
    if ($ex) return (int)$ex;
    q("INSERT INTO item (artikelnummer, name, kategorie, einheit, preis_bezug, gesperrt, notiz)
       VALUES (NULL, ?, ?, ?, ?, 0, ?)",
      [$name, $kategorie, $einheit, $einheit, 'Im Lager beim Wareneingang angelegt.']);
    $id = (int) insert_id();
    $hatSpalte = fn(string $c): bool => (int) scalar("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='item' AND COLUMN_NAME=?", [$c]) > 0;
    // Verpackungs-Rolle (etikett/beipack/primaer …) + Verpackungsart (beutel/stick …) nur bei Kategorie verpackung.
    if ($id && $kategorie === 'verpackung') {
        if ($rolle !== '' && $hatSpalte('verpackung_rolle')) q("UPDATE item SET verpackung_rolle=? WHERE id=?", [mb_substr($rolle, 0, 20), $id]);
        if ($art   !== '' && $hatSpalte('verpackungsart'))   q("UPDATE item SET verpackungsart=? WHERE id=?",   [mb_substr($art,   0, 30), $id]);
    }
    return $id;
}

// „Waren, auf die wir warten" – beim Lieferanten bestellt, aber noch nicht angekommen (status 'bestellt' oder – seit 08.11.2026,
// Handbuch 4.5 – auch 'gesendet' = übers Portal geschickt, Lieferant hat noch nicht bestätigt; die Ware kann trotzdem kommen),
// kein Wareneingang). Mit erwartetem Termin (eta_geplant), Sendungsnummer (tracking) und Positionen,
// damit der Mitarbeiter sie bei Ankunft direkt einbuchen kann.
// Kapselgröße (z. B. "Größe 0") zu einer Position bestimmen – wenn es Kapseln sind.
// 1) direkt am Artikel (Leerkapsel: item.kapselgroesse_id), 2) über Auftrag -> Produkt -> Rezeptur.
// Leerer String, wenn keine Kapselgröße hinterlegt/bestimmbar ist.
function erp_kapselgroesse_label(?int $item_id, ?int $auftrag_id): string {
    if (!tabelle_da('kapselgroesse')) return '';
    if ($item_id) {
        $n = scalar("SELECT kg.name FROM item i JOIN kapselgroesse kg ON kg.id=i.kapselgroesse_id
                     WHERE i.id=? AND i.kapselgroesse_id IS NOT NULL", [$item_id]);
        if ($n) return (string)$n;
    }
    if ($auftrag_id && tabelle_da('auftrag') && tabelle_da('produkt') && tabelle_da('rezeptur')) {
        $n = scalar("SELECT kg.name FROM auftrag a
                     JOIN produkt p ON p.id = a.produkt_id
                     JOIN rezeptur r ON r.id = p.rezeptur_id
                     JOIN kapselgroesse kg ON kg.id = r.kapselgroesse_id
                     WHERE a.id=?", [$auftrag_id]);
        if ($n) return (string)$n;
    }
    return '';
}

// Erwartete Lieferung per Versandlabel/Tracking finden (für "Scan-to-Einbuchen").
// Scanner liefern die Nummer teils mit Leerzeichen/Prefix – deshalb über Leerzeichen-normalisiert
// exakt vergleichen. Rückgabe: ['ok'=>bool, 'lieferant','lieferant_id','nummer','positionen'=>[...]].
function erp_lieferung_per_tracking(string $tracking): array {
    $tc = preg_replace('/\s+/', '', trim($tracking));
    if ($tc === '' || !tabelle_da('bestellung')) return ['ok' => false];
    $bid = (int) scalar("SELECT b.id FROM bestellung b
              WHERE b.tracking IS NOT NULL AND b.tracking<>'' AND REPLACE(b.tracking,' ','') = ?
                AND b.angekommen_am IS NULL
              ORDER BY b.id DESC LIMIT 1", [$tc]);
    // Einkauf gerade ziehen (Teil 1): dieselben Positionen wie „Aus Liste wählen" (fest an die Bestellposition).
    return $bid > 0 ? erp_lieferung_positionen($bid) : ['ok' => false];
}

// Bestellung als „angekommen" markieren (Wareneingang) – nur wenn noch offen. Lässt die Kunden-
// Statusleiste automatisch auf „Rohstoff angekommen" springen und nimmt die Lieferung aus „erwartet".
// Teillieferung (Nacht 1c, Spiegel von bestellung_lieferung_erfassen() im Dashboard): Chargen auf die Positionen verteilen,
// geschlossen wird erst, wenn alle Positionen (mind. 98 %) geliefert sind. Ohne die Spalte (frische DB vor dem ersten
// Dashboard-Aufruf) wie bisher sofort schließen. Rückgabe ['komplett'=>bool, 'offen'=>int Positionen].
// Firmen-Absender aus den Dashboard-Einstellungen (app_meta firma_*), Defaults wie beleg_firma() im Dashboard (Aufräumen 10).
function erp_firma_absender(): array {
    $m = [];
    if (tabelle_da('app_meta')) foreach (all("SELECT k, v FROM app_meta WHERE k IN ('firma_name','firma_strasse','firma_hausnr','firma_plz','firma_ort','firma_land')") as $r) $m[$r['k']] = trim((string)$r['v']);
    $g = fn($k, $d) => ($m[$k] ?? '') !== '' ? $m[$k] : $d;
    return ['bulkify / ' . $g('firma_name', 'Maniso GmbH'), trim($g('firma_strasse', 'Ahornstraße') . ' ' . $g('firma_hausnr', '45')),
            trim($g('firma_plz', '26842') . ' ' . $g('firma_ort', 'Ostrhauderfehn')), $g('firma_land', 'Deutschland')];
}
function erp_bp_geliefert_da(): bool {
    static $da = null;
    if ($da === null) $da = tabelle_da('bestellung_position') && (int) scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='bestellung_position' AND column_name='geliefert_menge'") > 0;
    return $da;
}
function erp_bp_rest_sql(): string { return erp_bp_geliefert_da() ? "GREATEST(bp.menge - COALESCE(bp.geliefert_menge,0), 0)" : "bp.menge"; }
function erp_einheit_faktor(string $von, string $nach): float {
    $v = mb_strtolower(trim($von)); $n = mb_strtolower(trim($nach));
    if ($v === $n || $v === '' || $n === '') return 1.0;
    $f = ['g>kg' => 0.001, 'kg>g' => 1000, 'mg>g' => 0.001, 'g>mg' => 1000, 'mg>kg' => 0.000001, 'kg>mg' => 1000000, 'ml>l' => 0.001, 'l>ml' => 1000];
    return $f[$v . '>' . $n] ?? 1.0;
}
function erp_bestellung_lieferung_erfassen(int $bestellung_id, array $charge_ids, array $bp_je_charge = []): array {
    // Einkauf gerade ziehen (Teil 1-3, 10.11.2026): gemeinsame Stelle mit dem Dashboard (core/wareneingang_bestellung.php, ohne
    // core/schema.php). Jede Charge hängt an genau einer Position; gelieferte Menge = Summe der verknüpften Chargen.
    if ($bestellung_id <= 0 || !tabelle_da('bestellung')) return ['komplett' => false, 'offen' => 0];
    require_once __DIR__ . '/../../core/wareneingang_bestellung.php';
    foreach (array_filter(array_map('intval', $charge_ids)) as $cid) {
        $c = one("SELECT item_id FROM charge WHERE id=?", [$cid]);
        if (!$c) continue;
        $bp = (int)($bp_je_charge[$cid] ?? 0);
        if ($bp <= 0) {
            $kand = array_map('intval', array_column(all("SELECT id FROM bestellung_position WHERE bestellung_id=? AND item_id=? ORDER BY (COALESCE(geliefert_menge,0) >= menge), sort, id", [$bestellung_id, (int)$c['item_id']]), 'id'));
            if (!$kand) $kand = array_map('intval', array_column(all("SELECT id FROM bestellung_position WHERE bestellung_id=? AND item_id IS NULL ORDER BY sort, id", [$bestellung_id]), 'id'));
            $bp = $kand ? erp_position_fuer_charge($cid, $kand) : 0;
        }
        if ($bp <= 0) continue;
        webe_position_artikel_setzen($bp, (int)$c['item_id']);
        webe_charge_verknuepfen($cid, $bp, false);
        erp_charge_werte_aus_bestellung($cid);   // Analysewerte des geprüften CoA an die Charge
    }
    $st = webe_bestellung_stand($bestellung_id, true, 'Lager-App');
    return ['komplett' => $st['komplett'], 'offen' => count($st['offen']), 'mehr' => $st['mehr']];
}
// Welche Bestellposition gehört zu dieser Charge? Die mit derselben (tolerant verglichenen) Chargennummer aus
// bestellung_charge, sonst die erste, auf die gebucht wurde. Spiegel von bch_position_fuer_charge() (core/bestellung_chargen.php).
function erp_position_fuer_charge(int $charge_id, array $kandidaten): int {
    require_once __DIR__ . '/../../core/charge_norm.php';
    $kandidaten = array_values(array_unique(array_filter(array_map('intval', $kandidaten))));
    if (!$kandidaten) return 0;
    $nr = (string) scalar("SELECT charge_nr FROM charge WHERE id=?", [$charge_id]);
    if ($nr !== '' && tabelle_da('bestellung_charge')) {
        $in = implode(',', $kandidaten);
        foreach (all("SELECT bestellung_position_id, charge_nr FROM bestellung_charge WHERE bestellung_position_id IN ($in) ORDER BY id") as $z)
            if (charge_gleich((string)$z['charge_nr'], $nr)) return (int)$z['bestellung_position_id'];
    }
    return $kandidaten[0];
}
// Dokumente einer Charge aus ihrer Bestellposition (CoA mit gleicher Chargennummer bzw. ohne Nummer, Spezifikation).
// Spiegel von charge_bestell_dokumente() im Dashboard – nur lesen.
function erp_charge_bestell_dokumente(int $charge_id): array {
    if (!erp_spalte_da('charge', 'bestellung_position_id') || !erp_spalte_da('dokument', 'bestellung_position_id')) return [];
    require_once __DIR__ . '/../../core/charge_norm.php';
    $c = one("SELECT charge_nr, bestellung_position_id FROM charge WHERE id=?", [$charge_id]);
    if (!$c || empty($c['bestellung_position_id'])) return [];
    $docs = all("SELECT d.id, d.typ, d.titel, d.datei_orig, d.dok_datum, d.charge_nr, d.ki_daten" . (erp_spalte_da('dokument', 'coa_geprueft_am') ? ", d.coa_geprueft_am" : ", NULL AS coa_geprueft_am") . " FROM dokument d JOIN bestellung_position bp ON bp.id=d.bestellung_position_id
                 WHERE d.objekt_typ='bestellung' AND d.objekt_id=bp.bestellung_id AND d.bestellung_position_id=? ORDER BY d.id DESC",
                [(int)$c['bestellung_position_id']]);
    return array_values(array_filter($docs, fn($d) => (string)$d['typ'] !== 'coa' || trim((string)($d['charge_nr'] ?? '')) === ''
        || charge_gleich((string)$d['charge_nr'], (string)($c['charge_nr'] ?? ''))));
}
// Analysewerte des im Prüf-Popup bestätigten CoA (dokument.ki_daten.werte_bestaetigt) an die Charge (charge_analyse) –
// nur wenn die Charge noch keine Werte hat. Spiegel von bch_werte_an_charge() im Dashboard.
function erp_charge_werte_aus_bestellung(int $charge_id): int {
    if ($charge_id <= 0 || !tabelle_da('charge_analyse')) return 0;
    if ((int) scalar("SELECT COUNT(*) FROM charge_analyse WHERE charge_id=?", [$charge_id]) > 0) return 0;
    foreach (erp_charge_bestell_dokumente($charge_id) as $d) {
        if ((string)$d['typ'] !== 'coa' || empty($d['coa_geprueft_am'])) continue;
        $kd = json_decode((string)($d['ki_daten'] ?? ''), true);
        $w = is_array($kd) ? (array)($kd['werte_bestaetigt'] ?? $kd['werte'] ?? []) : [];
        $n = 0;
        foreach (array_values($w) as $i => $z) {
            if (trim((string)($z['parameter'] ?? '')) === '') continue;
            q("INSERT INTO charge_analyse (charge_id,parameter,spezifikation,ergebnis,methode,sort) VALUES (?,?,?,?,?,?)",
              [$charge_id, $z['parameter'], ($z['spezifikation'] ?? '') ?: null, ($z['ergebnis'] ?? '') ?: null, ($z['methode'] ?? '') ?: null, $i]);
            $n++;
        }
        if ($n) return $n;
    }
    return 0;
}
function erp_bestellung_angekommen(int $bestellung_id): void {
    if ($bestellung_id <= 0 || !tabelle_da('bestellung')) return;
    q("UPDATE bestellung SET angekommen_am=CURDATE() WHERE id=? AND angekommen_am IS NULL", [$bestellung_id]);
    // Bestellung SCHLIESSEN (S-06): sonst bleibt sie 'bestellt' und der Bedarf zählt die Ware zugleich als eingebucht
    // UND als bestellt (Doppelzählung), und der Dashboard-Knopf „liefern" würde sie ein zweites Mal einbuchen.
    q("UPDATE bestellung SET status='geliefert' WHERE id=? AND status IN ('offen','gesendet','bestellt')", [$bestellung_id]);
}

// Eine frisch eingebuchte Charge mit dem Auftrag verknüpfen. Das ist der EINE Schlüssel, an dem
// sowohl die Produktion „Ware da" erkennt ALS AUCH die Kunden-Statusleiste „Rohstoff angekommen"
// (mit Datum) auslöst – sonst sieht der Kunde bei Zukauf/Fremdproduktion nichts.
// ===== Kapselgröße an der Charge (Soll-Kette 2d, 04.11.2026) – Spiegel von charge_kapselgroesse_ermitteln() im Dashboard =====
function erp_spalte_da(string $tabelle, string $spalte): bool {
    static $m = [];
    $k = $tabelle . '.' . $spalte;
    if (!isset($m[$k])) $m[$k] = (int) scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?", [$tabelle, $spalte]) > 0;
    return $m[$k];
}
// Kapselgröße einer Rezeptur wie rezeptur_kapselgroesse(): gespeicherte Größe, sonst die kleinste, in die das Füllgewicht passt
// (über Dichte × Volumen, sonst Füllmenge). Nur Kapsel/Softgel.
function erp_rezeptur_kapselgroesse(int $rezeptur_id): int {
    if ($rezeptur_id <= 0 || !tabelle_da('kapselgroesse')) return 0;
    $r = one("SELECT kapselgroesse_id, darreichungsform FROM rezeptur WHERE id=?", [$rezeptur_id]);
    if (!$r || !in_array((string)$r['darreichungsform'], ['kapsel', 'softgel'], true)) return 0;
    if ((int)$r['kapselgroesse_id'] > 0) return (int)$r['kapselgroesse_id'];
    $w = (float) scalar("SELECT COALESCE(SUM(menge_mg),0) FROM rezeptur_zutat WHERE rezeptur_id=?", [$rezeptur_id]);
    if ($w <= 0) return 0;
    $mS = 0.0; $vS = 0.0;
    foreach (all("SELECT z.menge_mg, i.dichte FROM rezeptur_zutat z JOIN item i ON i.id=z.item_id WHERE z.rezeptur_id=? AND i.dichte>0", [$rezeptur_id]) as $z) {
        $mS += (float)$z['menge_mg']; $vS += (float)$z['menge_mg'] / (float)$z['dichte'];
    }
    $dichte = $vS > 0 ? $mS / $vS : 0.0;
    $vol = erp_spalte_da('kapselgroesse', 'volumen_ml');
    foreach (all("SELECT id, fuellmenge_mg" . ($vol ? ", volumen_ml" : ", 0 AS volumen_ml") . " FROM kapselgroesse ORDER BY fuellmenge_mg ASC") as $kg) {
        $kap = ($dichte > 0 && (float)$kg['volumen_ml'] > 0) ? (float)$kg['volumen_ml'] * $dichte * 1000.0 : (float)$kg['fuellmenge_mg'];
        if ($kap + 0.001 >= $w) return (int)$kg['id'];
    }
    return 0;
}
// Eine Größe für alle Rezepturen am Bulk-Artikel (Stamm + Varianten)? Sonst 0 = nicht eindeutig.
function erp_bulk_kapselgroesse(int $stamm_rezeptur_id): int {
    if ($stamm_rezeptur_id <= 0) return 0;
    $g = [];
    foreach (all("SELECT id FROM rezeptur WHERE id=? OR stamm_rezeptur_id=?", [$stamm_rezeptur_id, $stamm_rezeptur_id]) as $r)
        if (($x = erp_rezeptur_kapselgroesse((int)$r['id'])) > 0) $g[$x] = true;
    return count($g) === 1 ? (int) array_key_first($g) : 0;
}
function erp_charge_kapselgroesse_ermitteln(int $item_id, int $auftrag_id = 0): int {
    $it = one("SELECT kategorie, form, rezeptur_id" . (erp_spalte_da('item', 'kapselgroesse_id') ? ", kapselgroesse_id" : ", NULL AS kapselgroesse_id") . " FROM item WHERE id=?", [$item_id]);
    if (!$it) return 0;
    if ((string)$it['form'] === 'kapselhuelle') return (int)($it['kapselgroesse_id'] ?? 0);
    if ((string)$it['kategorie'] !== 'fertig') return 0;
    if ($auftrag_id > 0) {
        $rid = (int) scalar("SELECT COALESCE(a.rezeptur_id, p.rezeptur_id) FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id WHERE a.id=?", [$auftrag_id]);
        if ($rid && ($g = erp_rezeptur_kapselgroesse($rid)) > 0) return $g;
    }
    return erp_bulk_kapselgroesse((int)($it['rezeptur_id'] ?? 0));
}
// An der Charge speichern; $manuell (Auswahl im Wareneingang) gewinnt, sonst nur wenn leer bzw. bei $neu_rechnen (Auftrag kam dazu).
function erp_charge_kapselgroesse_setzen(int $charge_id, int $manuell = 0, bool $neu_rechnen = false): int {
    if ($charge_id <= 0 || !erp_spalte_da('charge', 'kapselgroesse_id')) return 0;
    $c = one("SELECT item_id, auftrag_id, kapselgroesse_id FROM charge WHERE id=?", [$charge_id]);
    if (!$c) return 0;
    if ($manuell <= 0 && !$neu_rechnen && (int)$c['kapselgroesse_id'] > 0) return (int)$c['kapselgroesse_id'];
    $g = $manuell > 0 ? $manuell : erp_charge_kapselgroesse_ermitteln((int)$c['item_id'], (int)($c['auftrag_id'] ?? 0));
    if ($g > 0 && $g !== (int)$c['kapselgroesse_id']) q("UPDATE charge SET kapselgroesse_id=? WHERE id=?", [$g, $charge_id]);
    return $g;
}
function erp_charge_auftrag_setzen(int $charge_id, int $auftrag_id): void {
    if ($charge_id <= 0 || $auftrag_id <= 0 || !tabelle_da('charge')) return;
    q("UPDATE charge SET auftrag_id=? WHERE id=? AND auftrag_id IS NULL", [$auftrag_id, $charge_id]);
    erp_charge_kapselgroesse_setzen($charge_id, 0, true);   // mit Auftrag ist die Größe eindeutig (Soll-Kette 2d)
}

// Charge "umhaengen": einer REZEPTUR zuordnen = an deren kanonisches Bulk-Item haengen (charge.item_id).
// So laufen im Lager ad-hoc angelegte Fertigware-Chargen (ohne Rezeptur, "– Bulk #0" o. Ae.) auf den
// richtigen Rezeptur-Bulk-Artikel zusammen -> der Bestand wird korrekt je Variante (Kapselgroesse) erfasst.
// Rueckgabe: ['ok'=>bool,'meldung'=>string,'item_name'=>?]. Fremdlager-Chargen sind tabu.
function erp_charge_rezeptur_zuordnen(int $charge_id, int $rezeptur_id): array {
    if (!tabelle_da('charge')) return ['ok' => false, 'meldung' => 'Keine Chargen vorhanden.'];
    $c = one("SELECT item_id, fremd_kunde_id FROM charge WHERE id=?", [$charge_id]);
    if (!$c) return ['ok' => false, 'meldung' => 'Charge nicht gefunden.'];
    if (!empty($c['fremd_kunde_id'])) return ['ok' => false, 'meldung' => 'Fremdlager-Charge – nicht umhängbar.'];
    if ($rezeptur_id <= 0) return ['ok' => false, 'meldung' => 'Bitte eine Rezeptur wählen.'];
    $bi = erp_rezeptur_bulkitem($rezeptur_id);
    if (!$bi) return ['ok' => false, 'meldung' => 'Für diese Rezeptur gibt es noch keinen Bulk-Lagerartikel – bitte zuerst im Dashboard anlegen (Rezeptur speichern).'];
    $nm = (string) scalar("SELECT name FROM item WHERE id=?", [$bi]);
    if ((int)$c['item_id'] === $bi) return ['ok' => true, 'meldung' => 'Charge ist bereits zugeordnet: ' . $nm, 'item_name' => $nm];
    q("UPDATE charge SET item_id=? WHERE id=?", [$bi, $charge_id]);
    return ['ok' => true, 'meldung' => 'Charge zugeordnet: ' . $nm, 'item_name' => $nm];
}

// Absicherung: Wird Fertigware/Bulk MANUELL (ohne gewählte Lieferung) gebucht, versuchen wir, sie
// einem offenen Auftrag zuzuordnen – aber NUR wenn es eindeutig ist (genau ein passender Auftrag,
// der dieses Bulk-Item noch nicht bekommen hat). Sonst 0 -> nichts raten (dann greift der Dashboard-Button).
function erp_auftrag_fuer_bulkitem(int $item_id): int {
    if ($item_id <= 0 || !tabelle_da('item') || !tabelle_da('auftrag') || !tabelle_da('produkt')) return 0;
    $rid = (int) scalar("SELECT rezeptur_id FROM item WHERE id=? AND kategorie='fertig'", [$item_id]);
    if ($rid <= 0) return 0;   // nur Fertigware/Bulk mit Rezeptur – Rohstoffe sind geteilter Bestand, nie auto-zuordnen
    $rows = all("SELECT a.id FROM auftrag a JOIN produkt p ON p.id=a.produkt_id
                 WHERE p.rezeptur_id=? AND a.status IN ('offen','in_produktion')
                   AND NOT EXISTS (SELECT 1 FROM charge c WHERE c.auftrag_id=a.id AND c.item_id=?)
                 LIMIT 2", [$rid, $item_id]);
    return count($rows) === 1 ? (int)$rows[0]['id'] : 0;
}

// Positionen einer bestimmten erwarteten Lieferung (Bestell-ID) – für "aus Liste wählen".
function erp_lieferung_positionen(int $id): array {
    if ($id <= 0 || !tabelle_da('bestellung')) return ['ok' => false];
    $b = one("SELECT b.id, b.nummer, b.lieferant_id, lf.firma AS lieferant
              FROM bestellung b LEFT JOIN lieferanten lf ON lf.id = b.lieferant_id
              WHERE b.id = ? AND b.angekommen_am IS NULL", [$id]);
    if (!$b) return ['ok' => false];
    $pos = [];
    if (tabelle_da('bestellung_position')) {
        require_once __DIR__ . '/../../core/wareneingang_bestellung.php';
        webe_spalten();
        $sel = "SELECT bp.id AS bp_id, bp.item_id, bp.auftrag_id, " . erp_bp_rest_sql() . " AS menge, bp.einheit, bp.rest_storniert_am,
                       COALESCE(NULLIF(i.name,''), bp.bezeichnung) AS name, i.kategorie, i.form, i.verpackung_rolle
                FROM bestellung_position bp LEFT JOIN item i ON i.id = bp.item_id
                WHERE bp.bestellung_id = ? ORDER BY bp.sort, bp.id";
        foreach (all($sel, [$id]) as $p) {
            if (trim((string)($p['name'] ?? '')) === '') continue;
            if ((float)$p['menge'] <= 0 || !empty($p['rest_storniert_am'])) continue;   // schon geliefert bzw. Rest storniert
            // Rezeptur der Position ableiten -> beim Einbuchen direkt als "Fertigware/Bulk" vorauswählen.
            // Hat die Position einen eigenen Nicht-Bulk-Artikel (Verpackung, Etikett, Rohstoff ...), bleibt dieser
            // samt Warenart erhalten (S-21) – nur ein Bulk-Artikel bzw. Freitext wird auf die Rezeptur gemappt.
            $rz = erp_rezeptur_zu_position((int)($p['item_id'] ?? 0), (int)($p['auftrag_id'] ?? 0), (string)$p['name']);
            $eigen = !$rz['rezeptur_id'] && (int)($p['item_id'] ?? 0) > 0;
            $pos[] = [
                'name'          => (string)$p['name'],
                'menge'         => (float)$p['menge'],
                'einheit'       => erp_einheit_norm((string)($p['einheit'] ?? '')),
                'charge_nr'     => '', 'mhd' => '',
                'warenart'      => $rz['rezeptur_id'] ? 'fertig' : ($eigen ? erp_item_warenart($p) : (string)($p['kategorie'] ?? '')),
                'verpackung_rolle' => $eigen ? (string)($p['verpackung_rolle'] ?? '') : '',
                'form'          => $eigen ? (string)($p['form'] ?? '') : '',
                'rezeptur_id'   => $rz['rezeptur_id'],
                'rezeptur_name' => $rz['rezeptur_name'],
                'auftrag_id'    => (int)($p['auftrag_id'] ?? 0),   // Charge beim Buchen mit dem Auftrag verknüpfen
                // bei Rezeptur-Treffer: das koppelbare Bulk-Item schon mitgeben; sonst der Artikel der Position
                'item_id'       => $rz['rezeptur_id'] ? $rz['bulk_item_id'] : ($eigen ? (int)$p['item_id'] : 0),
                'item_name'     => $rz['rezeptur_id'] ? $rz['bulk_item_name'] : ($eigen ? (string)$p['name'] : ''),
                // Einkauf gerade ziehen (Teil 1): die Position ist fest - Artikel und Einheit kommen aus der Bestellung.
                'bp_id'         => (int)$p['bp_id'],
                'bp_nummer'     => (string)$b['nummer'],
                'bp_rest'       => (float)$p['menge'],
                'kapsel'        => webe_kapsel_label($rz['rezeptur_id'] ? (int)$rz['bulk_item_id'] : (int)($p['item_id'] ?? 0)),
            ];
        }
    }
    return ['ok' => true, 'id' => (int)$b['id'], 'lieferant' => (string)$b['lieferant'], 'lieferant_id' => (int)$b['lieferant_id'],
            'nummer' => (string)$b['nummer'], 'positionen' => $pos];
}

// Rezeptur zu einer ankommenden Position bestimmen (read-only): 1) über das bestellte Bulk-Item,
// 2) über den Auftrag -> Produkt -> Rezeptur, 3) über exakten Rezepturnamen. Liefert zusätzlich das
// koppelbare Bulk-Item der Rezeptur (für die Vorauswahl beim Wareneingang).
function erp_rezeptur_zu_position(int $item_id, int $auftrag_id, string $name): array {
    $leer = ['rezeptur_id' => 0, 'rezeptur_name' => '', 'bulk_item_id' => 0, 'bulk_item_name' => ''];
    if (!tabelle_da('rezeptur') || !tabelle_da('item')) return $leer;
    $rid = 0;
    if ($item_id > 0) {
        $kat = scalar("SELECT kategorie FROM item WHERE id=?", [$item_id]);
        // Verpackung/Etikett/Rohstoff mit eigenem Artikel gehört nie zur Rezeptur (auch nicht über Auftrag oder Namen).
        if ($kat !== null && $kat !== false && (string)$kat !== 'fertig') return $leer;
        $rid = (int) scalar("SELECT rezeptur_id FROM item WHERE id=? AND kategorie='fertig' AND rezeptur_id IS NOT NULL", [$item_id]);
    }
    if ($rid <= 0 && $auftrag_id > 0 && tabelle_da('auftrag') && tabelle_da('produkt')) {
        $rid = (int) scalar("SELECT p.rezeptur_id FROM auftrag a JOIN produkt p ON p.id=a.produkt_id
                             WHERE a.id=? AND p.rezeptur_id IS NOT NULL", [$auftrag_id]);
    }
    if ($rid <= 0) {
        $n = trim($name);
        if ($n !== '') $rid = (int) scalar("SELECT id FROM rezeptur WHERE LOWER(TRIM(name))=LOWER(TRIM(?)) ORDER BY id LIMIT 1", [$n]);
    }
    if ($rid <= 0) return $leer;
    $rz = one("SELECT name FROM rezeptur WHERE id=?", [$rid]);
    $bi = one("SELECT id, name FROM item WHERE rezeptur_id=? AND kategorie='fertig' ORDER BY id LIMIT 1", [erp_rezeptur_stamm($rid)]);
    return [
        'rezeptur_id'    => $rid,
        'rezeptur_name'  => (string)($rz['name'] ?? ''),
        'bulk_item_id'   => (int)($bi['id'] ?? 0),
        'bulk_item_name' => (string)($bi['name'] ?? ''),
    ];
}

// Rezepturnummer (RZ-...) einer Charge – über Artikel->Produkt->Rezeptur, sonst Auftrag->Produkt->Rezeptur.
function erp_rezeptur_nr(?int $item_id, ?int $auftrag_id): string {
    if (!tabelle_da('rezeptur') || !tabelle_da('produkt')) return '';
    try {
        if ($item_id && tabelle_da('item')) {
            $n = scalar("SELECT r.nummer FROM item i JOIN produkt p ON p.id=i.produkt_id JOIN rezeptur r ON r.id=p.rezeptur_id
                         WHERE i.id=? AND r.nummer IS NOT NULL AND r.nummer<>''", [$item_id]);
            if ($n) return (string)$n;
        }
        if ($auftrag_id && tabelle_da('auftrag')) {
            $n = scalar("SELECT r.nummer FROM auftrag a JOIN produkt p ON p.id=a.produkt_id JOIN rezeptur r ON r.id=p.rezeptur_id
                         WHERE a.id=? AND r.nummer IS NOT NULL AND r.nummer<>''", [$auftrag_id]);
            if ($n) return (string)$n;
        }
    } catch (Throwable $e) { return ''; }
    return '';
}

// Rezepturen für den Picker beim Einbuchen fertiger Kapseln (Bulk). Read-only.
// Liefert je Rezeptur das kanonische Bulk-Item (item.rezeptur_id + kategorie='fertig'), falls schon da.
// Anlage des Bulk-Items bleibt kanonisch im Dashboard (rezeptur_bulkitem()); das Lager löst nur auf.
function erp_rezeptur_liste(): array {
    if (!tabelle_da('rezeptur')) return [];
    try {
        return all("SELECT r.id, r.nummer, r.name, r.darreichungsform, COALESCE(NULLIF(r.stamm_rezeptur_id,0), r.id) AS stamm_id,
                           kg.name AS kapselgroesse,
                           bi.id AS bulk_item_id, bi.einheit AS bulk_einheit
                      FROM rezeptur r
                      LEFT JOIN item bi ON bi.rezeptur_id = COALESCE(NULLIF(r.stamm_rezeptur_id,0), r.id) AND bi.kategorie='fertig'
                      LEFT JOIN kapselgroesse kg ON kg.id = r.kapselgroesse_id
                     WHERE r.status <> 'entwurf'
                     ORDER BY r.name, r.id");
    } catch (Throwable $e) { return []; }
}

// Rezepturnummer-Aufkleber (R…) scannen (Spec 5.2): aus einer gescannten Rezepturnummer die Rezeptur
// bestimmen -> fertige Position für den Wareneingang (kein Tippen, keine Fehlzuordnung durch Tippfehler).
// Tolerant: akzeptiert "R12345", "RZ-12345", nackte Ziffern. Rückgabe wie eine erwartete Position
// (rezeptur_id/-name + koppelbares Bulk-Item + warenart 'fertig') oder null, wenn nicht gefunden.
function erp_rezeptur_per_nummer(string $scan): ?array {
    if (!tabelle_da('rezeptur')) return null;
    $roh = strtoupper(trim($scan));
    if ($roh === '') return null;
    // Nummer-Varianten bilden: exakt, nur Ziffern, und Ziffern mit gängigen Präfixen.
    $ziffern = preg_replace('/\D+/', '', $roh);
    $kand = array_values(array_unique(array_filter([$roh, $ziffern,
        $ziffern !== '' ? 'R' . $ziffern : '', $ziffern !== '' ? 'RZ-' . $ziffern : ''], fn($x) => $x !== '')));
    $r = null;
    foreach ($kand as $k) {
        $r = one("SELECT id, nummer, name FROM rezeptur WHERE UPPER(TRIM(nummer))=? ORDER BY id LIMIT 1", [$k]);
        if ($r) break;
    }
    // Fallback: Nummer endet auf die Ziffern (z. B. gescannt ohne Präfix, gespeichert mit).
    if (!$r && $ziffern !== '') {
        $r = one("SELECT id, nummer, name FROM rezeptur WHERE REPLACE(REPLACE(UPPER(nummer),'RZ-',''),'R','')=? ORDER BY id LIMIT 1", [$ziffern]);
    }
    if (!$r) return null;
    $rid = (int)$r['id'];
    $bi  = tabelle_da('item') ? one("SELECT id, name, einheit FROM item WHERE rezeptur_id=? AND kategorie='fertig' ORDER BY id LIMIT 1", [erp_rezeptur_stamm($rid)]) : null;
    return [
        'name'          => (string)$r['name'],
        'menge'         => 0.0,
        'einheit'       => (string)($bi['einheit'] ?? ''),
        'charge_nr'     => '', 'mhd' => '',
        'warenart'      => 'fertig',
        'rezeptur_id'   => $rid,
        'rezeptur_name' => trim(((string)$r['nummer'] !== '' ? (string)$r['nummer'] . ' · ' : '') . (string)$r['name']),
        'item_id'       => (int)($bi['id'] ?? 0),
        'item_name'     => (string)($bi['name'] ?? ''),
    ];
}

// Kanonisches Bulk-Item einer Rezeptur auflösen (read-only). Null, wenn es noch keines gibt
// (dann im Dashboard anlegen lassen – siehe ANTWORT-DASHBOARD-REZEPTUR-BULKITEM.md, Variante A).
// Stammrezeptur (G5): ALLE Varianten einer Rezeptur teilen EINEN Bulk-Artikel – wie rezeptur_stamm_id() im Dashboard.
// Ohne das fand die App bei einer Variante keinen bzw. legte einen zweiten Bulk-Artikel an (Soll-Kette 2c, 04.11.2026).
function erp_rezeptur_stamm(int $rezeptur_id): int {
    if ($rezeptur_id <= 0) return 0;
    try { $s = (int) scalar("SELECT stamm_rezeptur_id FROM rezeptur WHERE id=?", [$rezeptur_id]); } catch (Throwable $e) { $s = 0; }
    return ($s > 0 && $s !== $rezeptur_id) ? $s : $rezeptur_id;
}
function erp_rezeptur_bulkitem(int $rezeptur_id): ?int {
    if ($rezeptur_id <= 0 || !tabelle_da('item')) return null;
    try {
        $id = scalar("SELECT id FROM item WHERE rezeptur_id=? AND kategorie='fertig' LIMIT 1", [erp_rezeptur_stamm($rezeptur_id)]);
        return $id ? (int)$id : null;
    } catch (Throwable $e) { return null; }
}

// ===== Einlagern: Produktion → Lager-Übergabe =====================================================
// Die Produktion legt eine Aufgabe (aufgabe.ref_typ='einlagern', ref_id=produktionsauftrag.id) an.
// Das Lager zeigt sie und bucht per EIN KLICK die Fertigware ein. Die Buchung selbst ist kanonische
// Dashboard-Logik (produktion_fertigware_einbuchen/BSKU/Lager-2) – die rufen wir NICHT nach (db()-Kollision,
// Divergenz), sondern über einen Loopback-Endpunkt im Dashboard (?p=api_einlager) auf. Gemeinsamer Token
// in app_meta['einlager_api_token'].
function erp_einlager_aufgaben(): array {
    if (!tabelle_da('aufgabe')) return [];
    return all("SELECT id AS aufgabe_id, titel, beschreibung, ref_id AS pa_id, angelegt
                FROM aufgabe WHERE ref_typ='einlagern' AND status='offen' ORDER BY id DESC LIMIT 100");
}
// Gemeinsamer Token (app_meta) – wird beim ersten Gebrauch erzeugt; die Dashboard-Seite prüft denselben Key.
function erp_einlager_token(): string {
    if (!tabelle_da('app_meta')) return '';
    $t = (string) scalar("SELECT v FROM app_meta WHERE k='einlager_api_token'");
    if ($t === '') {
        try { $t = bin2hex(random_bytes(16)); } catch (Throwable $e) { $t = md5(uniqid('', true)); }
        q("INSERT INTO app_meta (k,v) VALUES ('einlager_api_token', ?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [$t]);
    }
    return $t;
}
// Einlagern auslösen: ruft die kanonische Dashboard-Funktion per Loopback auf (gleicher Server).
// Rueckgabe ['ok'=>bool,'meldung'=>string,'ziel'=>?].
// QM-Freigabe an das Dashboard melden (?p=api_qm_freigabe): dort entstehen bei Fertigware Produktionsbericht-Freigabe und
// zusammenfassendes CoA (fertigware_qm_freigabe_nach). Best effort – die Freigabe selbst ist schon gebucht. Spiegel von erp_einlager_buchen().
function erp_qm_freigabe_melden(int $charge_id): array {
    if ($charge_id <= 0 || !function_exists('curl_init')) return ['ok' => false];
    $token = erp_einlager_token();
    if ($token === '') return ['ok' => false];
    $u = function_exists('lg_benutzer') ? lg_benutzer() : null;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = (string)($_SERVER['HTTP_HOST'] ?? 'app.bulkify.pro');
    $ch = curl_init($scheme . '://' . $host . '/?p=api_qm_freigabe');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45, CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_POSTFIELDS => http_build_query(['charge_id' => $charge_id, 'token' => $token, 'wer' => (string)($u['name'] ?? '')]),
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 2, CURLOPT_POSTREDIR => 7,
    ]);
    if (defined('CURLSSLOPT_NATIVE_CA')) curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    $res = curl_exec($ch); curl_close($ch);
    $j = json_decode((string)$res, true);
    return is_array($j) ? $j : ['ok' => false];
}
function erp_einlager_buchen(int $pa_id): array {
    if ($pa_id <= 0) return ['ok' => false, 'meldung' => 'Kein Produktionsauftrag angegeben.'];
    if (!function_exists('curl_init')) return ['ok' => false, 'meldung' => 'PHP-curl fehlt auf dem Server.'];
    $token = erp_einlager_token();
    if ($token === '') return ['ok' => false, 'meldung' => 'app_meta nicht verfügbar – Token kann nicht gesetzt werden.'];
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = (string)($_SERVER['HTTP_HOST'] ?? 'app.bulkify.pro');
    $url    = $scheme . '://' . $host . '/?p=api_einlager';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45, CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_POSTFIELDS => http_build_query(['pa_id' => $pa_id, 'token' => $token]),
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 2, CURLOPT_POSTREDIR => 7,  // http->https folgen, POST behalten
    ]);
    if (defined('CURLSSLOPT_NATIVE_CA')) curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    $res = curl_exec($ch);
    $st  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err !== '') return ['ok' => false, 'meldung' => 'Verbindungsfehler zum Dashboard: ' . $err];
    if ($st === 404) return ['ok' => false, 'meldung' => 'Dashboard-Endpunkt „api_einlager" fehlt noch – wird im Dashboard-Chat ergänzt.'];
    $j = json_decode((string)$res, true);
    if (is_array($j) && !empty($j['ok'])) return ['ok' => true, 'meldung' => 'Eingelagert in ' . (string)($j['label'] ?? 'das Lager') . '.', 'ziel' => (string)($j['ziel'] ?? '')];
    $msg = is_array($j) && ($j['meldung'] ?? $j['fehler'] ?? '') !== '' ? (string)($j['meldung'] ?? $j['fehler']) : ('Dashboard antwortete HTTP ' . $st);
    return ['ok' => false, 'meldung' => $msg];
}

// Best-effort-Warenart aus einem Freitext-Positionsnamen (nur wenn keine Item-/Auftrags-Verknuepfung
// vorliegt – z. B. alte Freitext-Bestellungen). Liefert einen Schluessel aus erp_kategorien() oder ''
// (= Sonstiges). Bewusst grob und ueberschreibbar: echte Zuordnung passiert beim Einbuchen.
function erp_warenart_raten(string $name): string {
    $n = mb_strtolower(trim($name));
    if ($n === '') return '';
    // Verpackung zuerst (eindeutige Material-Begriffe).
    if (preg_match('/etikett|label|karton|faltschachtel|dose|glas|flasche|deckel|verschluss|beutel|pouch|sleeve|folie|zipper|standbodenbeutel/u', $n)) return 'verpackung';
    // Fertige/ Bulk-Ware: Darreichungsformen und typische Produktbegriffe.
    if (preg_match('/kapsel|tablette|tabl\b|softgel|stick|pulver|granulat|komplex|premix|extrakt|\d\s*(mg|µg|mcg|g|iu)\b/u', $n)) return 'fertig';
    return '';
}

function erp_erwartete_lieferungen(): array {
    if (!tabelle_da('bestellung')) return [];
    $rows = all("SELECT b.id, b.nummer, b.bestelldatum, b.eta_geplant, b.tracking, b.versandanbieter,
                        b.notiz, b.lieferant_id, b.status, lf.firma AS lieferant
                 FROM bestellung b LEFT JOIN lieferanten lf ON lf.id = b.lieferant_id
                 WHERE b.status IN ('bestellt','gesendet') AND b.angekommen_am IS NULL
                 ORDER BY (b.eta_geplant IS NULL), b.eta_geplant, b.bestelldatum DESC, b.id DESC");
    foreach ($rows as &$r) {
        $pos = tabelle_da('bestellung_position')
            ? all("SELECT bp.item_id, bp.auftrag_id, " . erp_bp_rest_sql() . " AS menge, bp.einheit,
                          COALESCE(NULLIF(i.name,''), bp.bezeichnung) AS name, i.kategorie, i.form,
                          (SELECT CONCAT(au.nummer, COALESCE(CONCAT(' · ', k.firma), '')) FROM auftrag au LEFT JOIN kunden k ON k.id=au.kunde_id WHERE au.id=bp.auftrag_id) AS fuer_auftrag
                   FROM bestellung_position bp LEFT JOIN item i ON i.id = bp.item_id
                   WHERE bp.bestellung_id = ? ORDER BY bp.sort, bp.id", [(int)$r['id']])
            : [];
        foreach ($pos as &$p) {
            $p['kapselgroesse'] = erp_kapselgroesse_label((int)($p['item_id'] ?? 0), (int)($p['auftrag_id'] ?? 0));
            // Warenart fuer die Kategorie-Reiter: 1) Item-Kategorie, 2) auftragsgebundener Zukauf
            // fertiger Ware = 'fertig', 3) Freitext-Position ohne Verknuepfung -> aus dem Namen raten.
            $k = (string)($p['kategorie'] ?? '');
            if ($k === '' && !empty($p['auftrag_id'])) $k = 'fertig';
            if ($k === '') $k = erp_warenart_raten((string)($p['name'] ?? ''));
            $p['warenart'] = $k;
        }
        unset($p);
        $r['positionen'] = $pos;
    }
    unset($r);
    return $rows;
}

// Wareneingang buchen: legt eine Charge an (oder fuellt eine vorab aus einer CoA angelegte Charge).
// Rohstoff/Fertigware -> Quarantaene, sonst sofort frei. Rueckgabe: neue/aktualisierte charge.id oder null.
function erp_wareneingang_buchen(int $item_id, float $menge, string $charge_nr, ?string $mhd,
                                 ?int $lieferant_id, string $notiz = '', string $status = 'auto', string $einheit = ''): ?int {
    if (!tabelle_da('charge') || !tabelle_da('item')) return null;
    $it = one("SELECT kategorie, einheit FROM item WHERE id=?", [$item_id]);
    if (!$it || $menge <= 0) return null;
    // Eingegebene Einheit hat VORRANG vor den Stammdaten (z. B. Kapseln = Stk, nicht kg).
    $einheit = erp_einheit_norm(trim($einheit));
    if ($einheit === '') $einheit = (string)$it['einheit'];
    // Gewichts-/Volumen-Eingaben in die Artikel-Basiseinheit umrechnen (25.000 g -> 25 kg), damit der
    // Bestand nicht 1000x zu hoch zaehlt. Stk/Karton/… bleiben unveraendert.
    [$menge, $einheit] = erp_menge_in_item_einheit($menge, $einheit, (string)$it['einheit']);
    // Status: 'auto' (Standard) = EINE Regel für alle Wareneingangswege wie im Dashboard (item_braucht_quarantaene):
    // Rohstoffe, Bulk/Fertigware und verkaufsfertige Ware gehen in QUARANTÄNE und werden nach der Prüfung freigegeben,
    // Verpackung/Etikett/Verbrauch sind sofort frei (St-12). Ein ausdrücklicher Status ('frei'/'quarantaene'/'gesperrt') gilt weiter.
    // Seit 04.11.2026 (Nico): Quarantäne nur, wenn in den Einstellungen eingeschaltet – sonst ist alles sofort frei.
    if (!in_array($status, ['frei', 'quarantaene', 'gesperrt'], true))
        $status = erp_we_quarantaene() && in_array((string)$it['kategorie'], ['rohstoff', 'fertig', 'verkaufsfertig'], true) ? 'quarantaene' : 'frei';
    $charge_nr = trim($charge_nr);
    $lief = (tabelle_da('lieferanten') && $lieferant_id) ? $lieferant_id : null;

    // Vorab aus CoA angelegte Charge (gleiche Nummer, noch keine Ware) auffuellen statt Dublette.
    if ($charge_nr !== '') {
        $vorab = one("SELECT id, notiz FROM charge WHERE item_id=? AND wareneingang IS NULL AND menge<=0.0001 AND charge_nr=? ORDER BY id LIMIT 1",
                     [$item_id, $charge_nr]);
        if ($vorab) {
            $alt = trim((string)$vorab['notiz']);
            q("UPDATE charge SET menge=?, menge_verfuegbar=?, einheit=?, lieferant_id=COALESCE(?,lieferant_id),
                      mhd=COALESCE(?,mhd), wareneingang=CURDATE(), status=?, notiz=? WHERE id=?",
              [$menge, $menge, $einheit, $lief, $mhd ?: null, $status,
               trim(($alt !== '' ? $alt . ' | ' : '') . ($notiz ?: 'Ware eingegangen, mit CoA-Charge abgeglichen')),
               (int)$vorab['id']]);
            erp_charge_kapselgroesse_setzen((int)$vorab['id']);
            erp_bedarf_bump();
            return (int)$vorab['id'];
        }
    }
    q("INSERT INTO charge (charge_nr,item_id,menge,menge_verfuegbar,einheit,lieferant_id,mhd,wareneingang,status,notiz,angelegt)
       VALUES (?,?,?,?,?,?,?,CURDATE(),?,?,?)",
      [$charge_nr ?: null, $item_id, $menge, $menge, $einheit, $lief, $mhd ?: null, $status, $notiz ?: null, jetzt_utc()]);
    $neu = (int) insert_id();
    erp_charge_kapselgroesse_setzen($neu);   // Soll-Kette 2d: abgeleitet, nicht gemessen
    erp_bedarf_bump();
    return $neu;
}

// --- Gebinde je Charge (Teil 4) -------------------------------------------------------------
// charge_gebinde ist eine GETEILTE Dashboard-Tabelle (Bestand je Karton/Sack: Gewicht, Restmenge, Ort).
// Hier nur Raw-SQL über die Naht – das Dashboard legt die Tabelle an (init_schema). Fehlt sie, no-op.
// Je Gebinde ein Datensatz mit eigener Lauf-Nr (X von N). Seit 04.11.2026 (Gewicht & Bestand pro Gebinde) trägt der
// Wareneingang das ECHTE Gewicht je Karton ein (erp_charge_gebinde_mit_mengen, gewogen=1); die Charge ist die Summe der
// Gebinde. erp_charge_gebinde_anlegen() teilt nur noch für die alte Schnellmaske gleichmäßig (gewogen=0 -> Datenprüfung).
function erp_charge_gebinde_liste(int $charge_id): array {
    if ($charge_id <= 0 || !tabelle_da('charge_gebinde')) return [];
    return all("SELECT * FROM charge_gebinde WHERE charge_id=? ORDER BY nummer, id", [$charge_id]);
}
function erp_charge_gebinde(int $id): ?array {
    if ($id <= 0 || !tabelle_da('charge_gebinde')) return null;
    return one("SELECT g.*, c.charge_nr, c.mhd, c.lieferant_id, c.wareneingang, c.status AS charge_status, c.menge_verfuegbar AS charge_verfuegbar,
                       c.fremd_kunde_id, i.name AS item_name, i.artikelnummer
                FROM charge_gebinde g JOIN charge c ON c.id=g.charge_id JOIN item i ON i.id=c.item_id
                WHERE g.id=?", [$id]);
}
// Wareneingang: je Karton/Sack/Fass die eingegebene Menge als eigenes Gebinde (gewogen=1). Die Summe muss zum
// Chargenbestand passen (prüft der Aufrufer vor dem Buchen; hier nochmals als Schutz). Rückgabe: Anzahl angelegt.
function erp_charge_gebinde_mit_mengen(int $charge_id, array $mengen): int {
    if ($charge_id <= 0 || !tabelle_da('charge_gebinde') || !$mengen) return 0;
    if ((int) scalar("SELECT COUNT(*) FROM charge_gebinde WHERE charge_id=?", [$charge_id]) > 0) return 0;
    $c = one("SELECT menge_verfuegbar, einheit, standort FROM charge WHERE id=?", [$charge_id]);
    if (!$c) return 0;
    $mengen = array_values(array_map(fn($m) => round((float)$m, 3), $mengen));
    if (abs(array_sum($mengen) - (float)$c['menge_verfuegbar']) > 0.0005) return 0;
    $gw = erp_gebinde_spalte_da('gewogen'); $n = count($mengen); $jetzt = jetzt_utc();
    foreach ($mengen as $k => $m)
        q("INSERT INTO charge_gebinde (charge_id,nummer,anzahl_gesamt,menge,menge_verfuegbar,einheit,standort,status," . ($gw ? "gewogen,gewogen_am," : "") . "angelegt)
           VALUES (?,?,?,?,?,?,?,?," . ($gw ? "1,?," : "") . "?)",
          array_merge([$charge_id, $k + 1, $n, $m, $m, (string)($c['einheit'] ?? ''), (string)($c['standort'] ?? 'lager1') ?: 'lager1', $m > 0 ? 'voll' : 'leer'], $gw ? [$jetzt] : [], [$jetzt]));
    return $n;
}
// Gewicht & Bestand pro Gebinde (04.11.2026): Charge = Summe ihrer Gebinde. Wird der Chargenbestand an anderer Stelle
// geändert (Entnahme, Korrektur, Rückgabe je Charge), werden die Gebinde nachgezogen: weniger Bestand -> zuerst die in
// der Produktion gescannten, dann angebrochene, dann volle Gebinde; mehr Bestand -> aufs erste dieser Reihenfolge.
// Ohne Gebinde-Datensätze passiert nichts (Alt-Chargen gelten als ein Gebinde).
function erp_gebinde_abgleich(int $charge_id): void {
    if ($charge_id <= 0 || !tabelle_da('charge_gebinde')) return;
    $geb = all("SELECT id, menge, menge_verfuegbar FROM charge_gebinde WHERE charge_id=?
                ORDER BY (standort='produktion') DESC, (status='angebrochen') DESC, (status='leer') ASC, nummer, id", [$charge_id]);
    if (!$geb) return;
    $soll = (float) scalar("SELECT menge_verfuegbar FROM charge WHERE id=?", [$charge_id]);
    $diff = round(array_sum(array_map(fn($g) => (float)$g['menge_verfuegbar'], $geb)) - $soll, 3);
    if (abs($diff) <= 0.0005) return;
    if ($diff < 0) {
        $g = $geb[0]; $neu = round((float)$g['menge_verfuegbar'] - $diff, 3);
        q("UPDATE charge_gebinde SET menge_verfuegbar=?, status=? WHERE id=?", [$neu, $neu >= (float)$g['menge'] - 0.0005 ? 'voll' : 'angebrochen', $g['id']]);
        return;
    }
    foreach ($geb as $g) {
        if ($diff <= 0.0005) break;
        $v = (float)$g['menge_verfuegbar']; if ($v <= 0.0005) continue;
        $nimm = min($diff, $v); $neu = round($v - $nimm, 3);
        q("UPDATE charge_gebinde SET menge_verfuegbar=?, status=? WHERE id=?", [$neu, $neu <= 0.0005 ? 'leer' : 'angebrochen', $g['id']]);
        $diff = round($diff - $nimm, 3);
    }
}
// Entnahme per Scan: GENAU dieses Gebinde um $menge verringern (die Charge um dieselbe Menge). Rückgabe ['ok','meldung','rest'].
function erp_gebinde_entnehmen(int $gebinde_id, float $menge, string $grund = ''): array {
    $g = erp_charge_gebinde($gebinde_id);
    if (!$g) return ['ok' => false, 'meldung' => 'Gebinde nicht gefunden.'];
    if ($menge <= 0) return ['ok' => false, 'meldung' => 'Bitte eine Menge größer 0 angeben.'];
    $verf = (float)$g['menge_verfuegbar'];
    $fmt = fn(float $v): string => rtrim(rtrim(number_format($v, 3, ',', '.'), '0'), ',');
    if ($menge > $verf + 0.0005) return ['ok' => false, 'meldung' => 'In diesem Gebinde sind nur ' . $fmt($verf) . ' ' . (string)$g['einheit'] . '.'];
    $r = erp_charge_entnehmen((int)$g['charge_id'], $menge, false);
    if (empty($r['ok'])) return $r;
    $neu = round(max(0.0, $verf - $menge), 3);
    q("UPDATE charge_gebinde SET menge_verfuegbar=?, status=? WHERE id=?", [$neu, $neu <= 0.0005 ? 'leer' : 'angebrochen', $gebinde_id]);
    if (function_exists('lg_bewegung_log')) lg_bewegung_log((int)$g['charge_id'], 'aus', $menge, (string)$g['einheit'], (string)$g['item_name'],
        'Entnahme Gebinde ' . (int)$g['nummer'] . '/' . (int)$g['anzahl_gesamt'] . ($grund !== '' ? ' · ' . $grund : ''));
    return ['ok' => true, 'meldung' => $fmt($menge) . ' ' . (string)$g['einheit'] . ' aus Gebinde ' . (int)$g['nummer'] . '/' . (int)$g['anzahl_gesamt'] . ' entnommen, Rest ' . $fmt($neu) . ' ' . (string)$g['einheit'] . '.', 'rest' => $neu];
}
// Rückgabe aus der Produktion bzw. Nachwiegen: das Gebinde steht danach auf dem gewogenen Ist-Gewicht (gewogen=1, zurück
// in Lager 1), die Charge um die Differenz niedriger (Verbrauch/Schwund) bzw. höher. Änderungslog + Bewegung.
function erp_gebinde_rueckgabe(int $gebinde_id, float $ist, string $anlass = 'Rückgabe'): array {
    $g = erp_charge_gebinde($gebinde_id);
    if (!$g) return ['ok' => false, 'meldung' => 'Gebinde nicht gefunden.'];
    if ($ist < 0) return ['ok' => false, 'meldung' => 'Gewicht darf nicht negativ sein.'];
    $ist = round($ist, 3); $alt = round((float)$g['menge_verfuegbar'], 3); $diff = round($alt - $ist, 3);
    $cid = (int)$g['charge_id'];
    $fmt = fn(float $v): string => rtrim(rtrim(number_format($v, 3, ',', '.'), '0'), ',');
    $gw = erp_gebinde_spalte_da('gewogen');
    q("UPDATE charge_gebinde SET menge_verfuegbar=?, status=?, standort='lager1'" . ($gw ? ", gewogen=1, gewogen_am=?" : "") . " WHERE id=?",
      array_merge([$ist, $ist <= 0.0005 ? 'leer' : ($ist >= (float)$g['menge'] - 0.0005 ? 'voll' : 'angebrochen')], $gw ? [jetzt_utc()] : [], [$gebinde_id]));
    $cNeu = round(max(0.0, (float)$g['charge_verfuegbar'] - $diff), 3);
    $st = (string)$g['charge_status'];
    q("UPDATE charge SET menge_verfuegbar=?, status=? WHERE id=?", [$cNeu, $cNeu <= 0.0005 ? 'leer' : ($st === 'leer' ? 'frei' : $st), $cid]);
    erp_bedarf_bump();
    $u = function_exists('lg_uid') && lg_uid() ? erp_benutzer(lg_uid()) : null;
    if (tabelle_da('aenderung_log'))
        q("INSERT INTO aenderung_log (objekt_typ,objekt_id,feld,alt,neu,benutzer_id,benutzer,route,erstellt) VALUES (?,?,?,?,?,?,?,?,?)",
          ['charge_gebinde', $gebinde_id, 'menge_verfuegbar', (string)$alt, (string)$ist, $u ? (int)$u['id'] : null, $u ? (string)$u['name'] : null, 'lager_gebinde', gmdate('Y-m-d H:i:s')]);
    $txt = $anlass . ' Gebinde ' . (int)$g['nummer'] . '/' . (int)$g['anzahl_gesamt'] . ': gewogen ' . $fmt($ist) . ', vorher ' . $fmt($alt) . ' ' . (string)$g['einheit'];
    if (function_exists('lg_bewegung_log') && abs($diff) > 0.0005)
        lg_bewegung_log($cid, $diff > 0 ? 'aus' : 'korrektur', abs($diff), (string)$g['einheit'], (string)$g['item_name'], $txt . ($diff > 0 ? ' (Verbrauch/Schwund)' : ' (mehr als gebucht)'));
    return ['ok' => true, 'meldung' => 'Gebinde ' . (int)$g['nummer'] . '/' . (int)$g['anzahl_gesamt'] . ' steht auf ' . $fmt($ist) . ' ' . (string)$g['einheit']
            . (abs($diff) > 0.0005 ? ' – ' . $fmt(abs($diff)) . ' ' . (string)$g['einheit'] . ($diff > 0 ? ' als Verbrauch/Schwund gebucht.' : ' mehr als gebucht.') : '.'), 'diff' => $diff];
}
// N Gebinde für eine Charge anlegen (idempotent: nur wenn noch keine existieren). Rückgabe: Anzahl angelegt.
function erp_charge_gebinde_anlegen(int $charge_id, int $anzahl): int {
    if ($charge_id <= 0 || !tabelle_da('charge_gebinde')) return 0;
    $anzahl = max(1, min(500, $anzahl));
    if ((int) scalar("SELECT COUNT(*) FROM charge_gebinde WHERE charge_id=?", [$charge_id]) > 0) return 0;
    $c = one("SELECT menge_verfuegbar, einheit, standort FROM charge WHERE id=?", [$charge_id]);
    if (!$c) return 0;
    $total   = (float)$c['menge_verfuegbar'];
    $einheit = (string)($c['einheit'] ?? '');
    $ort     = (string)($c['standort'] ?? 'lager1') ?: 'lager1';
    // Stück/Kapseln/Tabletten ganzzahlig teilen, Gewicht/Volumen auf 3 Nachkommastellen.
    $istStueck = (bool) preg_match('/st(ü|u)?ck|^stk|tabl|kaps/iu', $einheit);
    $basis = $istStueck ? floor($total / $anzahl) : floor(($total / $anzahl) * 1000) / 1000;
    $jetzt = jetzt_utc();
    for ($k = 1; $k <= $anzahl; $k++) {
        $menge = ($k < $anzahl) ? $basis : round($total - $basis * ($anzahl - 1), 3);
        q("INSERT INTO charge_gebinde (charge_id,nummer,anzahl_gesamt,menge,menge_verfuegbar,einheit,standort,status,angelegt)
           VALUES (?,?,?,?,?,?,?,'voll',?)",
          [$charge_id, $k, $anzahl, $menge, $menge, $einheit, $ort, $jetzt]);
    }
    return $anzahl;
}

// --- Etikettenrollen: Inventaraufkleber statt Blinker (Spec Etiketten) ------------------------
// Eine Etikettenrolle = ein charge_gebinde-Datensatz mit der 8-stelligen Inventarnummer (Aufkleber in der Rolle;
// gescannt oder vom System vergeben und gedruckt). Altdaten: 7-stellig (frühere Auto-Nummern bzw. gekürzte Scans).
// charge_gebinde ist eine GETEILTE Dashboard-Tabelle; die Zusatzspalten legt das Dashboard an. Fehlt die
// Spalte noch (frischer Deploy, bevor das Dashboard-Schema lief), degradieren die Funktionen sauber.
function erp_gebinde_spalte_da(string $spalte): bool {
    static $cache = [];
    if (array_key_exists($spalte, $cache)) return $cache[$spalte];
    return $cache[$spalte] = (int) scalar("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='charge_gebinde' AND COLUMN_NAME=?", [$spalte]) > 0;
}
// Inventarnummer normalisieren: nur Ziffern, 8 Stellen (Aufkleber) – 7 Stellen nur noch für Altdaten (frühere
// Auto-Nummern 9xxxxxx bzw. Scans, die das alte 7er-Feld abgeschnitten hat). Sonst leer (ungültig).
function erp_inventar_norm(string $s): string {
    $d = preg_replace('/\D+/', '', trim($s));
    return (strlen($d) === 8 || strlen($d) === 7) ? $d : '';
}
// Ist die Inventarnummer noch frei (keine aktive = nicht leere Rolle trägt sie bereits)? $ausser = eigene id ignorieren.
function erp_inventar_frei(string $nr, int $ausser = 0): bool {
    $nr = erp_inventar_norm($nr);
    if ($nr === '' || !tabelle_da('charge_gebinde') || !erp_gebinde_spalte_da('inventar_nr')) return true;
    $n = (int) scalar("SELECT COUNT(*) FROM charge_gebinde WHERE inventar_nr=? AND status<>'leer' AND id<>?", [$nr, $ausser]);
    return $n === 0;
}
// N Etikettenrollen für eine Charge anlegen. $rollen = [['nr'=>'1234567','menge'=>2000.0], …].
// Menge je Rolle ist frei wählbar (Rollen sind oft unterschiedlich voll). Rückgabe: ['ok','angelegt','fehler'=>[]].
function erp_etikett_rollen_anlegen(int $charge_id, array $rollen): array {
    if ($charge_id <= 0 || !tabelle_da('charge_gebinde')) return ['ok' => false, 'angelegt' => 0, 'fehler' => ['Keine Charge.']];
    if (!erp_gebinde_spalte_da('inventar_nr')) return ['ok' => false, 'angelegt' => 0, 'fehler' => ['Inventar-Spalte fehlt (Dashboard-Schema noch nicht aktualisiert).']];
    $c = one("SELECT einheit, standort FROM charge WHERE id=?", [$charge_id]);
    if (!$c) return ['ok' => false, 'angelegt' => 0, 'fehler' => ['Charge nicht gefunden.']];
    $einheit = (string)($c['einheit'] ?? '');
    $ort     = (string)($c['standort'] ?? 'lager1') ?: 'lager1';
    $von = (int) scalar("SELECT COALESCE(MAX(nummer),0) FROM charge_gebinde WHERE charge_id=?", [$charge_id]);
    $jetzt = jetzt_utc(); $angelegt = 0; $fehler = []; $k = $von;
    $n = count($rollen);
    foreach ($rollen as $r) {
        $nr = erp_inventar_norm((string)($r['nr'] ?? ''));
        $menge = (float)($r['menge'] ?? 0);
        if ($nr === '')            { $fehler[] = 'Ungültige Inventarnummer (8 Ziffern).'; continue; }
        if (!erp_inventar_frei($nr)) { $fehler[] = 'Inventarnummer ' . $nr . ' ist schon vergeben.'; continue; }
        $k++;
        q("INSERT INTO charge_gebinde (charge_id,nummer,anzahl_gesamt,menge,menge_verfuegbar,einheit,standort,status,inventar_nr,angelegt)
           VALUES (?,?,?,?,?,?,?,'voll',?,?)",
          [$charge_id, $k, $von + $n, $menge, $menge, $einheit, $ort, $nr, $jetzt]);
        $angelegt++;
    }
    if ($angelegt > 0) q("UPDATE charge_gebinde SET anzahl_gesamt=(SELECT COUNT(*) FROM (SELECT id FROM charge_gebinde WHERE charge_id=?) t) WHERE charge_id=?", [$charge_id, $charge_id]);
    return ['ok' => $angelegt > 0, 'angelegt' => $angelegt, 'fehler' => $fehler];
}
// Nächste freie Auto-Inventarnummer (8-stellig, Band 90000000–99999999). Das System legt sie an, wenn beim Wareneingang
// kein Aufkleber gescannt wird, und druckt sie als Aufkleber für die Rolle. Jede Nummer wird gegen alle aktiven Rollen
// geprüft (keine Doppelnummer). $benutzt = in diesem Durchlauf schon vergebene Nummern (noch nicht in der DB).
function erp_inventar_naechste(array $benutzt = []): string {
    $start = 90000000;
    if (tabelle_da('charge_gebinde') && erp_gebinde_spalte_da('inventar_nr')) {
        $max = (int) scalar("SELECT COALESCE(MAX(CAST(inventar_nr AS UNSIGNED)),0) FROM charge_gebinde WHERE inventar_nr REGEXP '^9[0-9]{7}$'");
        if ($max >= $start) $start = $max + 1;
    }
    for ($nr = $start; $nr <= 99999999; $nr++) {
        $s = (string)$nr;
        if (!isset($benutzt[$s]) && erp_inventar_frei($s)) return $s;
    }
    for ($t = 0; $t < 50; $t++) {   // Band voll (sehr unwahrscheinlich) -> zufällig
        $s = (string)random_int(10000000, 99999999);
        if (!isset($benutzt[$s]) && erp_inventar_frei($s)) return $s;
    }
    return (string)random_int(10000000, 99999999);
}
// Etikettenrollen anlegen aus Gesamtmenge + Rollenzahl: Menge gleichmäßig verteilen (Rest auf die
// letzte Rolle), Inventarnummer je Rolle aus $nummern (gescannt) ODER automatisch vergeben. Kein
// Pflicht-Scan, keine Summenprüfung – das Buchen wird nie blockiert. Rückgabe wie erp_etikett_rollen_anlegen.
function erp_etikett_rollen_verteilen(int $charge_id, float $gesamt, int $anzahl, array $nummern = []): array {
    $anzahl = max(1, $anzahl);
    $gesamt = max(0.0, $gesamt);
    // Ganze Etiketten je Rolle; Rest (inkl. evtl. Nachkomma) auf die letzte Rolle.
    $basis  = floor($gesamt / $anzahl);
    $mengen = array_fill(0, $anzahl, $basis);
    $mengen[$anzahl - 1] = $gesamt - $basis * ($anzahl - 1);

    $rollen = []; $benutzt = []; $fehler = [];
    // Parallele Wareneingänge (zwei Tablets) dürfen nicht dieselbe Auto-Nummer ziehen (MAX+1): Nummernvergabe + Anlegen
    // laufen unter einer DB-Sperre (Aufräumen 13). Ohne Sperre (Timeout) geht es wie bisher weiter.
    $lock = (int) scalar("SELECT GET_LOCK('bx_inventar_nr', 5)") === 1;
    for ($k = 0; $k < $anzahl; $k++) {
        $roh = trim((string)($nummern[$k] ?? ''));
        if ($roh === '') {                       // leer -> automatisch vergeben
            $nr = erp_inventar_naechste($benutzt);
        } else {
            $nr = erp_inventar_norm($roh);
            if ($nr === '' || strlen($nr) !== 8) { $fehler[] = 'Inventarnummer „' . $roh . '" hat nicht 8 Ziffern – automatisch vergeben.'; $nr = erp_inventar_naechste($benutzt); }
            elseif (isset($benutzt[$nr]) || !erp_inventar_frei($nr)) { $fehler[] = 'Inventarnummer ' . $nr . ' schon vergeben – automatisch vergeben.'; $nr = erp_inventar_naechste($benutzt); }
        }
        $benutzt[$nr] = 1;
        $rollen[] = ['nr' => $nr, 'menge' => $mengen[$k]];
    }
    try { $res = erp_etikett_rollen_anlegen($charge_id, $rollen); }
    finally { if ($lock) scalar("SELECT RELEASE_LOCK('bx_inventar_nr')"); }
    $res['fehler'] = array_merge($fehler, $res['fehler'] ?? []);
    return $res;
}
// Etikettenrollen einer Charge (nur die mit Inventarnummer), inkl. zugeordnetem Auftrag.
function erp_charge_rollen(int $charge_id): array {
    if ($charge_id <= 0 || !tabelle_da('charge_gebinde') || !erp_gebinde_spalte_da('inventar_nr')) return [];
    $auf = tabelle_da('auftrag');
    return all("SELECT g.*" . ($auf ? ", a.nummer AS auftrag_nr" : "") . "
                FROM charge_gebinde g" . ($auf ? " LEFT JOIN auftrag a ON a.id=g.auftrag_id" : "") . "
                WHERE g.charge_id=? AND g.inventar_nr IS NOT NULL ORDER BY g.nummer, g.id", [$charge_id]);
}
// Inventarnummern der Rollen einer Charge nachträglich korrigieren (z. B. Aufkleber 11111111 aufsteigend, im System
// aber gekürzt bzw. automatisch vergeben). $neu = gebinde_id => Nummer (leer = unverändert). Regeln: nur Rollen dieser
// Charge, genau 8 Ziffern, keine Nummer doppelt (weder in der Eingabe noch bei einer anderen aktiven Rolle – Tausch
// innerhalb der Eingabe ist erlaubt). Jede Änderung landet im Feld-Protokoll des Dashboards (aenderung_log) und in der
// Lager-Bewegung. Rückgabe: ['geaendert'=>int, 'fehler'=>[...]].
function erp_rollen_inventar_korrigieren(int $charge_id, array $neu): array {
    $rollen = [];
    foreach (erp_charge_rollen($charge_id) as $g) $rollen[(int)$g['id']] = $g;
    $plan = []; $fehler = []; $seen = [];
    foreach ($neu as $gid => $roh) {
        $gid = (int)$gid; $roh = trim((string)$roh);
        if (!isset($rollen[$gid]) || $roh === '') continue;
        $d = preg_replace('/\D+/', '', $roh);
        $rl = 'Rolle ' . (int)$rollen[$gid]['nummer'];
        if (strlen($d) !== 8)  { $fehler[] = $rl . ': „' . $roh . '" hat nicht 8 Ziffern.'; continue; }
        if ($d === (string)$rollen[$gid]['inventar_nr']) continue;
        if (isset($seen[$d]))  { $fehler[] = $rl . ': ' . $d . ' steht doppelt in der Eingabe.'; continue; }
        $seen[$d] = $gid; $plan[$gid] = $d;
    }
    foreach ($plan as $gid => $d) {   // gegen andere aktive Rollen (die nicht selbst gerade umnummeriert werden)
        $andere = (int) scalar("SELECT id FROM charge_gebinde WHERE inventar_nr=? AND status<>'leer' AND id<>? LIMIT 1", [$d, $gid]);
        if ($andere && !isset($plan[$andere])) { $fehler[] = 'Rolle ' . (int)$rollen[$gid]['nummer'] . ': ' . $d . ' trägt schon eine andere Rolle.'; unset($plan[$gid]); }
    }
    if (!$plan) return ['geaendert' => 0, 'fehler' => $fehler];
    $u = function_exists('lg_uid') && lg_uid() ? erp_benutzer(lg_uid()) : null;
    $log = tabelle_da('aenderung_log');
    $c = erp_charge($charge_id);
    foreach ($plan as $gid => $d) {
        $alt = (string)$rollen[$gid]['inventar_nr'];
        q("UPDATE charge_gebinde SET inventar_nr=? WHERE id=? AND charge_id=?", [$d, $gid, $charge_id]);
        if ($log) q("INSERT INTO aenderung_log (objekt_typ,objekt_id,feld,alt,neu,benutzer_id,benutzer,route,erstellt) VALUES (?,?,?,?,?,?,?,?,?)",
                    ['charge_gebinde', $gid, 'inventar_nr', $alt, $d, $u ? (int)$u['id'] : null, $u ? (string)$u['name'] : null, 'lager_rollen', gmdate('Y-m-d H:i:s')]);
        if (function_exists('lg_bewegung_log')) lg_bewegung_log($charge_id, 'korrektur', 0, null, (string)($c['item_name'] ?? ''), 'Inventarnummer Rolle ' . (int)$rollen[$gid]['nummer'] . ': ' . $alt . ' -> ' . $d);
    }
    return ['geaendert' => count($plan), 'fehler' => $fehler];
}
// Eine Rolle per Inventarnummer auflösen – mit Produkt, Charge, Lieferant, Kunde und zugeordnetem Auftrag.
function erp_rolle_per_inventar(string $nr): ?array {
    $nr = erp_inventar_norm($nr);
    if ($nr === '' || !tabelle_da('charge_gebinde') || !erp_gebinde_spalte_da('inventar_nr')) return null;
    $lief = tabelle_da('lieferanten');
    $auf  = tabelle_da('auftrag');
    return one("SELECT g.*, c.charge_nr, c.item_id, c.fremd_kunde_id, c.wareneingang, c.mhd,
                       i.name AS item_name, i.artikelnummer"
             . ($lief ? ", l.firma AS lieferant, l.lieferantennummer AS lieferant_nr" : "")
             . ($auf  ? ", a.nummer AS auftrag_nr" : "") . "
                FROM charge_gebinde g
                JOIN charge c ON c.id=g.charge_id
                JOIN item i   ON i.id=c.item_id"
             . ($lief ? " LEFT JOIN lieferanten l ON l.id=c.lieferant_id" : "")
             . ($auf  ? " LEFT JOIN auftrag a ON a.id=g.auftrag_id" : "") . "
                WHERE g.inventar_nr IN (?, ?) ORDER BY (g.inventar_nr=?) DESC, (g.status<>'leer') DESC, g.id DESC LIMIT 1",
             // Altdaten: das frühere 7er-Eingabefeld hat 8-stellige Scans auf 7 Ziffern gekürzt -> auch die ersten 7 finden.
             [$nr, strlen($nr) === 8 ? substr($nr, 0, 7) : $nr, $nr]);
}
// Freie Etikettenrollen für ein Etikett-Item (für die Produktions-Auswahl „welche Rolle nimmst du").
// $auftrag_id>0: nur Rollen, die frei sind ODER bereits diesem Auftrag zugeordnet wurden.
function erp_etikett_rollen_fuer_item(int $item_id, int $auftrag_id = 0): array {
    if ($item_id <= 0 || !tabelle_da('charge_gebinde') || !erp_gebinde_spalte_da('inventar_nr')) return [];
    $w = $auftrag_id > 0 ? " AND (g.auftrag_id IS NULL OR g.auftrag_id=?)" : " AND g.auftrag_id IS NULL";
    $p = [$item_id]; if ($auftrag_id > 0) $p[] = $auftrag_id;
    return all("SELECT g.*, c.charge_nr FROM charge_gebinde g JOIN charge c ON c.id=g.charge_id
                WHERE c.item_id=? AND g.inventar_nr IS NOT NULL AND g.status<>'leer'$w
                ORDER BY g.angelegt, g.id", $p);
}
// Eine Rolle einem Auftrag/PA zuordnen (Produktion nimmt diese Rolle). Standort -> produktion.
// Rückgabe: ['ok'=>bool,'meldung'=>string].
function erp_rolle_auftrag_zuordnen(int $gebinde_id, int $auftrag_id, int $pa_id = 0): array {
    if (!tabelle_da('charge_gebinde') || !erp_gebinde_spalte_da('inventar_nr')) return ['ok' => false, 'meldung' => 'Etikettenrollen sind noch nicht freigeschaltet.'];
    $g = one("SELECT * FROM charge_gebinde WHERE id=? AND inventar_nr IS NOT NULL", [$gebinde_id]);
    if (!$g) return ['ok' => false, 'meldung' => 'Rolle nicht gefunden.'];
    if ((string)$g['status'] === 'leer') return ['ok' => false, 'meldung' => 'Diese Rolle ist aufgebraucht.'];
    $belegt = (int)($g['auftrag_id'] ?? 0);
    if ($belegt && $auftrag_id && $belegt !== $auftrag_id) return ['ok' => false, 'meldung' => 'Diese Rolle ist bereits einem anderen Auftrag zugeordnet.'];
    q("UPDATE charge_gebinde SET auftrag_id=?, pa_id=?, standort='produktion', verbraucht_am=? WHERE id=?",
      [$auftrag_id ?: null, $pa_id ?: null, jetzt_utc(), $gebinde_id]);
    return ['ok' => true, 'meldung' => 'Rolle ' . (string)$g['inventar_nr'] . ' dem Auftrag zugeordnet.'];
}
// Zuordnung einer Rolle wieder lösen (falsch gescannt) – zurück nach Lager 1, Auftrag/PA entfernt.
function erp_rolle_freigeben(int $gebinde_id): array {
    if (!tabelle_da('charge_gebinde') || !erp_gebinde_spalte_da('inventar_nr')) return ['ok' => false, 'meldung' => 'Etikettenrollen sind noch nicht freigeschaltet.'];
    $g = one("SELECT inventar_nr FROM charge_gebinde WHERE id=? AND inventar_nr IS NOT NULL", [$gebinde_id]);
    if (!$g) return ['ok' => false, 'meldung' => 'Rolle nicht gefunden.'];
    q("UPDATE charge_gebinde SET auftrag_id=NULL, pa_id=NULL, standort='lager1', verbraucht_am=NULL WHERE id=?", [$gebinde_id]);
    return ['ok' => true, 'meldung' => 'Rolle ' . (string)$g['inventar_nr'] . ' wieder freigegeben.'];
}

// Lieferant einer Charge setzen/aendern (per Name: finden oder neu anlegen). Nur eigener Bestand.
function erp_charge_lieferant_setzen(int $charge_id, string $name): array {
    if (!tabelle_da('charge')) return ['ok' => false, 'meldung' => 'Keine Chargen vorhanden.'];
    $c = one("SELECT fremd_kunde_id FROM charge WHERE id=?", [$charge_id]);
    if (!$c) return ['ok' => false, 'meldung' => 'Charge nicht gefunden.'];
    if (!empty($c['fremd_kunde_id'])) return ['ok' => false, 'meldung' => 'Fremdlager-Charge – hat keinen Lieferanten.'];
    $name = trim($name);
    if ($name === '') { q("UPDATE charge SET lieferant_id=NULL WHERE id=?", [$charge_id]); return ['ok' => true, 'meldung' => 'Lieferant entfernt.']; }
    $lid = erp_lieferant_finden_oder_anlegen($name);
    if (!$lid) return ['ok' => false, 'meldung' => 'Lieferant konnte nicht gesetzt werden.'];
    q("UPDATE charge SET lieferant_id=? WHERE id=?", [$lid, $charge_id]);
    return ['ok' => true, 'meldung' => 'Lieferant gesetzt: ' . $name];
}

// Einheit einer Charge korrigieren (z. B. Pulver faelschlich "Stk" -> "kg"). Normalisiert.
function erp_charge_einheit_setzen(int $charge_id, string $einheit): array {
    if (!tabelle_da('charge')) return ['ok' => false, 'meldung' => 'Keine Chargen vorhanden.'];
    if (!one("SELECT id FROM charge WHERE id=?", [$charge_id])) return ['ok' => false, 'meldung' => 'Charge nicht gefunden.'];
    $einheit = erp_einheit_norm(trim($einheit));
    if ($einheit === '') return ['ok' => false, 'meldung' => 'Bitte eine Einheit angeben.'];
    q("UPDATE charge SET einheit=? WHERE id=?", [$einheit, $charge_id]);
    return ['ok' => true, 'meldung' => 'Einheit: ' . $einheit];
}

// Warenarten beim Wareneingang -> echte Zuordnung auf item.kategorie + item.form.
// (Siehe INFO-LAGER-DATENMODELL-WARENEINGANG.md: KEINE Kategorie 'kapsel'.
//  Leerkapseln = rohstoff + Form kapselhuelle; fertige Kapseln/Bulk = fertig; verpackt = verkaufsfertig.)
function erp_warenart_defs(): array {
    return [
        'rohstoff'       => ['label' => 'Rohstoff',                 'kategorie' => 'rohstoff',       'form' => ''],
        'leerkapsel'     => ['label' => 'Leerkapseln',             'kategorie' => 'rohstoff',       'form' => 'kapselhuelle'],
        'fertig'         => ['label' => 'Bulk / lose (Kapseln/Tabletten)', 'kategorie' => 'fertig',   'form' => ''],
        'verkaufsfertig' => ['label' => 'Fertiges Produkt (verpackt)', 'kategorie' => 'verkaufsfertig', 'form' => ''],
        'etikett'        => ['label' => 'Etikett (Rolle)',         'kategorie' => 'verpackung',     'form' => '', 'rolle' => 'etikett'],
        'verpackung'     => ['label' => 'Verpackung',              'kategorie' => 'verpackung',     'form' => ''],
        'verbrauch'      => ['label' => 'Verbrauch / Betriebsmittel', 'kategorie' => 'verbrauch',   'form' => ''],
    ];
}

// Warenart am ARTIKEL setzen (Kategorie + Form). Leere Form nur ueberschreiben, wenn die Warenart eine
// feste Form vorgibt (kapselhuelle) – sonst eine evtl. vorhandene Form (pulver/tablette/…) nicht loeschen.
function erp_item_warenart_setzen(int $item_id, string $warenart): bool {
    if (!tabelle_da('item') || $item_id <= 0) return false;
    $def = erp_warenart_defs()[$warenart] ?? null;
    if (!$def) return false;
    // Etikett (Rolle): Kategorie verpackung + Verpackungs-Rolle 'etikett' – daran erkennt der Wareneingang,
    // dass statt eines Blinkers ein Inventaraufkleber je Rolle gefragt wird.
    if (!empty($def['rolle'])) {
        $hatRolle = (int) scalar("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='item' AND COLUMN_NAME='verpackung_rolle'") > 0;
        if ($hatRolle) q("UPDATE item SET kategorie=?, verpackung_rolle=? WHERE id=?", [$def['kategorie'], $def['rolle'], $item_id]);
        else           q("UPDATE item SET kategorie=? WHERE id=?", [$def['kategorie'], $item_id]);
        return true;
    }
    if ($def['form'] !== '') {
        q("UPDATE item SET kategorie=?, form=? WHERE id=?", [$def['kategorie'], $def['form'], $item_id]);
    } elseif ($warenart === 'rohstoff') {
        // Leerkapseln sind auch Rohstoffe: „Rohstoff“ gewählt darf die Form Kapselhülle NICHT löschen – sonst verschwinden
        // Kapselgröße/Material auf der Rohstoff-Seite (Nico 04.11.2026). Nur die Kategorie setzen.
        q("UPDATE item SET kategorie=? WHERE id=?", [$def['kategorie'], $item_id]);
    } else {
        // Wechsel weg von Leerkapseln: nur die kapselhuelle-Form leeren, andere Formen (pulver …) behalten.
        q("UPDATE item SET kategorie=?, form=IF(form='kapselhuelle','',form) WHERE id=?", [$def['kategorie'], $item_id]);
    }
    return true;
}
// Warenart einer Charge ändern = Warenart ihres Artikels setzen (Lager-Eingabe hat Vorrang).
function erp_charge_warenart_setzen(int $charge_id, string $warenart): array {
    if (!tabelle_da('charge')) return ['ok' => false, 'meldung' => 'Keine Chargen vorhanden.'];
    $c = one("SELECT item_id FROM charge WHERE id=?", [$charge_id]);
    if (!$c) return ['ok' => false, 'meldung' => 'Charge nicht gefunden.'];
    if (!erp_item_warenart_setzen((int)$c['item_id'], $warenart)) return ['ok' => false, 'meldung' => 'Unbekannte Warenart.'];
    return ['ok' => true, 'meldung' => 'Warenart: ' . (erp_warenart_defs()[$warenart]['label'] ?? $warenart)];
}
// Aktuelle Warenart eines Artikels (fuer die Vorauswahl) aus kategorie/form ableiten.
function erp_item_warenart(array $itemrow): string {
    if (((string)($itemrow['form'] ?? '')) === 'kapselhuelle') return 'leerkapsel';
    $k = (string)($itemrow['kategorie'] ?? '');
    // Etikett erkennt man an der Verpackungs-Rolle, nicht an der Kategorie (die ist 'verpackung').
    if ($k === 'verpackung' && ((string)($itemrow['verpackung_rolle'] ?? '')) === 'etikett') return 'etikett';
    return isset(erp_warenart_defs()[$k]) ? $k : ($k === 'verkaufsfertig' ? 'verkaufsfertig' : 'rohstoff');
}

// Status einer Charge aendern (Freigeben / Quarantaene / Sperren). Leere Chargen bleiben 'leer'.
// Rueckgabe: ['ok'=>bool, 'meldung'=>string].
function erp_charge_status_setzen(int $charge_id, string $status): array {
    if (!tabelle_da('charge')) return ['ok' => false, 'meldung' => 'Keine Chargen vorhanden.'];
    if (!in_array($status, ['frei', 'quarantaene', 'gesperrt'], true)) return ['ok' => false, 'meldung' => 'Unbekannter Status.'];
    $c = one("SELECT status, menge_verfuegbar FROM charge WHERE id=?", [$charge_id]);
    if (!$c) return ['ok' => false, 'meldung' => 'Charge nicht gefunden.'];
    if ((string)$c['status'] === 'leer' || (float)$c['menge_verfuegbar'] <= 0) return ['ok' => false, 'meldung' => 'Leere Charge – Status bleibt.'];
    q("UPDATE charge SET status=? WHERE id=?", [$status, $charge_id]);
    erp_bedarf_bump();   // freigegebene/gesperrte Ware ändert den verfügbaren Bestand -> Bedarf/Einkauf neu rechnen
    // QM-Freigabe einer Charge aus der Quarantäne -> Dashboard benachrichtigen (Fertigware: Bericht + CoA für den Kunden).
    if ($status === 'frei' && (string)$c['status'] === 'quarantaene') erp_qm_freigabe_melden($charge_id);
    $txt = ['frei' => 'Freigegeben', 'quarantaene' => 'In Quarantäne', 'gesperrt' => 'Gesperrt'];
    return ['ok' => true, 'meldung' => 'Status: ' . $txt[$status]];
}

// Alle Chargen in Quarantäne (eigenes Lager, ohne Fremdlager), optional nur ein Artikel – für die Freigabe-Liste.
function erp_quarantaene_chargen(int $item_id = 0): array {
    if (!tabelle_da('charge')) return [];
    return all("SELECT c.id, c.charge_nr, c.item_id, c.menge_verfuegbar, c.einheit, c.mhd, c.wareneingang, i.name AS item_name, i.kategorie,
                       COALESCE(lf.firma, CONCAT('Produktion ', pa.nummer)) AS lieferant
                FROM charge c JOIN item i ON i.id=c.item_id LEFT JOIN lieferanten lf ON lf.id=c.lieferant_id
                LEFT JOIN produktionsauftrag pa ON pa.id=c.pa_id
                WHERE c.status='quarantaene' AND c.fremd_kunde_id IS NULL AND c.menge_verfuegbar>0" . ($item_id > 0 ? " AND c.item_id=" . (int)$item_id : "") . "
                ORDER BY i.name, c.wareneingang DESC, c.id DESC");
}

// Warenausgang: Menge von einer Charge abbuchen. Leer -> Status 'leer'. Fremdlager-Chargen sind tabu.
// Rueckgabe: ['ok'=>bool, 'meldung'=>?, 'leer'=>bool, 'rest'=>float, 'item_name'=>?, 'einheit'=>?].
function erp_charge_entnehmen(int $charge_id, float $menge, bool $gebindeAbgleich = true): array {
    if (!tabelle_da('charge')) return ['ok' => false, 'meldung' => 'Keine Chargen vorhanden.'];
    $c = one("SELECT c.*, i.name AS item_name, i.kategorie FROM charge c JOIN item i ON i.id=c.item_id WHERE c.id=?", [$charge_id]);
    if (!$c) return ['ok' => false, 'meldung' => 'Charge nicht gefunden.'];
    if (!empty($c['fremd_kunde_id'])) return ['ok' => false, 'meldung' => 'Fremdlager-Charge – Entnahme läuft über das Fulfillment.'];
    // QM-Gate (St-11d): Fertigware in Quarantäne darf das Lager nicht verlassen, bevor QM freigegeben hat.
    if ((string)$c['status'] === 'quarantaene' && in_array((string)$c['kategorie'], ['fertig', 'verkaufsfertig'], true))
        return ['ok' => false, 'meldung' => 'Fertigware in Quarantäne – erst nach der QM-Freigabe entnehmen.'];
    if ($menge <= 0) return ['ok' => false, 'meldung' => 'Bitte eine Menge größer 0 angeben.'];
    $verf = (float)$c['menge_verfuegbar'];
    if ($menge > $verf + 1e-9) return ['ok' => false, 'meldung' => 'Nur noch ' . menge_txt($verf) . ' ' . (string)$c['einheit'] . ' verfügbar.'];
    $rest = $verf - $menge;
    $leer = $rest <= 1e-9;
    q("UPDATE charge SET menge_verfuegbar=?, status=? WHERE id=?",
      [$leer ? 0 : $rest, $leer ? 'leer' : (string)$c['status'], $charge_id]);
    if ($gebindeAbgleich) erp_gebinde_abgleich($charge_id);   // Gebinde folgen der Charge (ohne Scan: angebrochene zuerst)
    erp_bedarf_bump();
    return ['ok' => true, 'meldung' => '', 'leer' => $leer, 'rest' => $leer ? 0.0 : $rest,
            'item_name' => (string)$c['item_name'], 'einheit' => (string)$c['einheit']];
}

// --- Vollwertiger Wareneingang: Artikel-Matching + Warenart-Regeln ----------------------------

// Einheit auf einen einheitlichen Namen bringen, damit nicht "Stück", "stueck", "pcs", "Stk."
// alle nebeneinander im System stehen. Unbekanntes bleibt unveraendert (nur getrimmt).
// Deutsche Mengen-Eingabe robust in float: "25.000" = 25000 (Tausenderpunkt), "25,5" = 25.5,
// "1.234,5" = 1234.5. Ein einzelner Punkt mit genau 3er-Gruppen gilt als Tausender.
function erp_menge_parse(string $s): float {
    $s = preg_replace('/[^0-9.,\-]/', '', trim($s));
    if ($s === '' || $s === null) return 0.0;
    $hatK = strpos($s, ',') !== false;
    $hatP = strpos($s, '.') !== false;
    if ($hatK && $hatP)      { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
    elseif ($hatK)           { $s = str_replace(',', '.', $s); }
    elseif ($hatP && preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) { $s = str_replace('.', '', $s); }
    return (float)$s;
}

function erp_einheit_norm(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    $k = mb_strtolower(str_replace(['.', ' '], '', $s));
    static $map = [
        'stück'=>'Stk','stueck'=>'Stk','stk'=>'Stk','stck'=>'Stk','st'=>'Stk','stueck'=>'Stk',
        'pcs'=>'Stk','pc'=>'Stk','pce'=>'Stk','piece'=>'Stk','pieces'=>'Stk','ea'=>'Stk','each'=>'Stk','x'=>'Stk',
        'kg'=>'kg','kilogramm'=>'kg','kilo'=>'kg','kgs'=>'kg',
        'g'=>'g','gramm'=>'g','gramme'=>'g','gr'=>'g','grams'=>'g',
        'mg'=>'mg',
        'l'=>'L','liter'=>'L','litre'=>'L','ltr'=>'L','liters'=>'L',
        'ml'=>'ml',
        'rolle'=>'Rolle','rollen'=>'Rolle','roll'=>'Rolle','rolls'=>'Rolle',
        'karton'=>'Karton','kartons'=>'Karton','ktn'=>'Karton','carton'=>'Karton','cartons'=>'Karton',
        'palette'=>'Palette','paletten'=>'Palette','pal'=>'Palette','pallet'=>'Palette',
        'beutel'=>'Beutel','sack'=>'Sack','säcke'=>'Sack','saecke'=>'Sack','bag'=>'Beutel','bags'=>'Beutel',
        'packung'=>'Pack','packungen'=>'Pack','pack'=>'Pack','packs'=>'Pack','pkg'=>'Pack','pck'=>'Pack',
        'dose'=>'Dose','dosen'=>'Dose','can'=>'Dose',
        'flasche'=>'Flasche','flaschen'=>'Flasche','bottle'=>'Flasche','bottles'=>'Flasche',
    ];
    return $map[$k] ?? $s;
}

// Menge in die BASISEINHEIT des Artikels umrechnen, wenn die Eingabe-Einheit eine Gewichts- bzw.
// Volumen-Verwandte ist (g<->kg<->mg, ml<->L). So wird „25.000 g" bei einem kg-Artikel zu 25 kg
// gebucht – sonst zaehlt der Bestand die 25.000 faelschlich als kg (1000x). Kategoriale Einheiten
// (Stk, Karton, Beutel, …) bleiben wie eingegeben (z. B. Kapseln = Stk, nicht kg). Rueckgabe: [menge, einheit].
function erp_menge_in_item_einheit(float $menge, string $von, string $item_einheit): array {
    $von  = erp_einheit_norm(trim($von));
    $ziel = erp_einheit_norm(trim($item_einheit));
    if ($von === '' || $ziel === '' || $von === $ziel) return [$menge, $ziel !== '' ? $ziel : $von];
    $gewicht = ['mg' => 0.001, 'g' => 1.0, 'kg' => 1000.0];   // in Gramm
    $vol     = ['ml' => 1.0, 'L' => 1000.0];                   // in Milliliter
    if (isset($gewicht[$von], $gewicht[$ziel])) return [$menge * $gewicht[$von] / $gewicht[$ziel], $ziel];
    if (isset($vol[$von], $vol[$ziel]))         return [$menge * $vol[$von] / $vol[$ziel], $ziel];
    return [$menge, $von];   // nicht umrechenbar -> Eingabe behalten
}

// Artikel per (Teil-)Name suchen – fuer das Zuordnen einer Lieferschein-Position zu einem
// bestehenden Artikel. Reihenfolge: exakter Name, dann "faengt an mit", dann kuerzester Treffer.
// LIKE mit ESCAPE '=' (Projektregel: Backslash als Escape crasht MySQL live).
function erp_item_suchen(string $name, int $limit = 6): array {
    if (!tabelle_da('item')) return [];
    $name = trim($name);
    if ($name === '') return [];
    $hatGesperrt = (int) scalar("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='item' AND COLUMN_NAME='gesperrt'");
    $w   = $hatGesperrt ? ' AND gesperrt=0' : '';
    $esc = fn(string $s): string => str_replace(['=', '%', '_'], ['==', '=%', '=_'], $s);
    $enth = '%' . $esc($name) . '%';
    $anf  = $esc($name) . '%';
    return all("SELECT id, name, kategorie, einheit, form, verpackung_rolle FROM item
                WHERE kategorie IN ('rohstoff','verpackung','verbrauch','fertig','verkaufsfertig')$w
                  AND name LIKE ? ESCAPE '='
                ORDER BY (name=?) DESC, (name LIKE ? ESCAPE '=') DESC, CHAR_LENGTH(name), name
                LIMIT " . (int)$limit, [$enth, $name, $anf]);
}

// Welche Felder sind je Warenart Pflicht und geht die Ware in Quarantaene?
// $kategorie = item.kategorie, $form = item.form ('kapselhuelle' = Leerkapseln).
// Rueckgabe: ['mhd_pflicht'=>bool, 'charge_pflicht'=>bool, 'quarantaene'=>bool].
// (Die vom Nutzer gewuenschte Matrix. Ein Schalter je Artikel als Ausnahme kommt spaeter.)
// 'inventar_pflicht' => true: statt eines Blinkers wird je Rolle ein Inventaraufkleber gescannt (nur Etiketten).
// Schalter app_meta wareneingang_quarantaene (Dashboard: Einstellungen). Standard aus = Wareneingang sofort frei (Nico 04.11.2026).
function erp_we_quarantaene(): bool {
    static $an = null;
    if ($an === null) $an = tabelle_da('app_meta') && (string) scalar("SELECT v FROM app_meta WHERE k='wareneingang_quarantaene'") === '1';
    return $an;
}
function erp_warenart_regeln(string $kategorie, string $form = '', string $rolle = ''): array {
    // Etikett (verpackung + Rolle 'etikett'): kein MHD/keine Charge, sofort frei – aber Inventaraufkleber Pflicht.
    if ($kategorie === 'verpackung' && $rolle === 'etikett')
        return ['mhd_pflicht' => false, 'charge_pflicht' => false, 'quarantaene' => false, 'inventar_pflicht' => true];
    if ($form === 'kapselhuelle')
        return ['mhd_pflicht' => true, 'charge_pflicht' => true, 'quarantaene' => erp_we_quarantaene(), 'inventar_pflicht' => false];
    $r = match ($kategorie) {
        'rohstoff'              => ['mhd_pflicht' => true,  'charge_pflicht' => true,  'quarantaene' => true],
        'fertig', 'verkaufsfertig' => ['mhd_pflicht' => true,  'charge_pflicht' => true,  'quarantaene' => true],
        'kapsel'               => ['mhd_pflicht' => true,  'charge_pflicht' => true,  'quarantaene' => true],
        'verpackung'           => ['mhd_pflicht' => false, 'charge_pflicht' => false, 'quarantaene' => false],
        'verbrauch'            => ['mhd_pflicht' => false, 'charge_pflicht' => false, 'quarantaene' => false],
        default                => ['mhd_pflicht' => false, 'charge_pflicht' => false, 'quarantaene' => false],
    };
    $r['inventar_pflicht'] = false;
    $r['quarantaene'] = $r['quarantaene'] && erp_we_quarantaene();
    return $r;
}

// Eine Lieferschein-Position einem bestehenden Artikel zuordnen (oder als "neu" markieren).
// Rueckgabe reichert die Position an: item_id (0 = neu), item_name, kategorie, einheit, form,
// kandidaten[] (fuer die Auswahl), regeln[] (Pflichtfelder der erkannten/vermuteten Warenart).
function erp_position_zuordnen(array $pos): array {
    // Bereits einer Rezeptur zugeordnet (aus erp_lieferung_positionen) -> als "Fertigware/Bulk"
    // mit koppelbarem Bulk-Item übernehmen, Namenssuche überspringen.
    if (!empty($pos['rezeptur_id'])) {
        $pos['item_id']    = (int)($pos['item_id'] ?? 0);
        $pos['item_name']  = (string)($pos['item_name'] ?? $pos['name'] ?? '');
        $pos['kategorie']  = 'fertig';
        $pos['form']       = (string)($pos['form'] ?? '');
        $pos['einheit']    = erp_einheit_norm((string)($pos['einheit'] ?? ''));
        $pos['kandidaten'] = [];
        $pos['regeln']     = erp_warenart_regeln('fertig', '');
        return $pos;
    }
    $kandidaten = erp_item_suchen((string)($pos['name'] ?? ''));
    $treffer = $kandidaten[0] ?? null;
    // Hat die Position schon einen Artikel (aus der Bestellung), gilt genau dieser – nicht der Namenstreffer.
    if ((int)($pos['item_id'] ?? 0) > 0) {
        $eig = one("SELECT id, name, kategorie, form, verpackung_rolle, einheit FROM item WHERE id=?", [(int)$pos['item_id']]);
        if ($eig) { $treffer = $eig; $kandidaten = [$eig]; $pos['name'] = (string)$eig['name']; }
    }
    // Als sichere Zuordnung nur werten, wenn der Name exakt passt (sonst nur Vorschlag).
    $exakt = $treffer && mb_strtolower(trim((string)$treffer['name'])) === mb_strtolower(trim((string)($pos['name'] ?? '')));
    $kat = $exakt ? (string)$treffer['kategorie'] : ((string)($pos['warenart'] ?? '') ?: 'rohstoff');
    $form = $exakt ? (string)($treffer['form'] ?? '') : '';
    $rolle = $exakt ? (string)($treffer['verpackung_rolle'] ?? '') : '';
    $pos['item_id']    = $exakt ? (int)$treffer['id'] : 0;
    $pos['item_name']  = $exakt ? (string)$treffer['name'] : (string)($pos['name'] ?? '');
    $pos['kategorie']  = $kat;
    $pos['form']       = $form;
    $pos['verpackung_rolle'] = $rolle;
    if (($pos['einheit'] ?? '') === '' && $exakt) $pos['einheit'] = (string)$treffer['einheit'];
    $pos['einheit']    = erp_einheit_norm((string)($pos['einheit'] ?? ''));   // einheitliche Einheit (Stk, kg, …)
    $pos['kandidaten'] = $kandidaten;
    $pos['regeln']     = erp_warenart_regeln($kat, $form, $rolle);
    return $pos;
}

// Scan-Endpunkt-Token (Smartglass). Liegt im Dashboard unter app_meta['lager_scan_token'];
// erzeugt + angezeigt wird er in den Dashboard-Einstellungen (Reiter "Lager-Scan").
// Hier nur GELESEN - der Lager-Scan-Endpunkt prueft damit die Berechtigung.
function erp_scan_token(): string {
    if (!tabelle_da('app_meta')) return '';
    $t = scalar("SELECT v FROM app_meta WHERE k='lager_scan_token'");
    return (string) ($t ?? '');
}

// Anzahl Chargen in Quarantäne (Lager 1) – Zähler am Menüpunkt „Lager 1" und an der Kachel „Quarantäne".
function lg_quarantaene_zahl(): int {
    static $n = null;
    if ($n !== null) return $n;
    if (!tabelle_da('charge')) return $n = 0;
    return $n = (int) scalar("SELECT COUNT(*) FROM charge WHERE status='quarantaene' AND fremd_kunde_id IS NULL AND menge_verfuegbar>0");
}
