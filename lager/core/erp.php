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

// --- Chargen des grossen Lagers (fuer das Chaos-Finden) ---------------------------------------
// Nur EIGENER Bestand: Fremdlager-Chargen (charge.fremd_kunde_id gesetzt = Kundenware) bleiben aussen
// vor, die gehoeren ins Fulfillment. Leere Chargen (status='leer' oder menge_verfuegbar<=0) auch nicht.
function erp_charge_select(): string {
    return "SELECT c.id, c.charge_nr, c.menge_verfuegbar, c.einheit, c.mhd, c.status,
                   i.name AS item_name, i.artikelnummer, i.kategorie
            FROM charge c JOIN item i ON i.id = c.item_id";
}
function erp_charge(int $id): ?array {
    if (!tabelle_da('charge')) return null;
    return one(erp_charge_select() . " WHERE c.id=?", [$id]);
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
    if ($menge === null || $menge <= 0 || $menge + 1e-9 >= $verf) {
        q("UPDATE charge SET fremd_kunde_id=? WHERE id=?", [$ziel, $charge_id]);
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
    q("UPDATE charge SET menge=GREATEST(menge-?,0), menge_verfuegbar=GREATEST(menge_verfuegbar-?,0) WHERE id=?",
      [$moved, $moved, $charge_id]);
    return ['ok' => true, 'meldung' => $fmt($moved) . ' ' . (string)$c['einheit'] . ' in den ' . $zielname
                         . ' umgebucht, ' . $fmt($verf - $moved) . ' bleiben.', 'neu_id' => $neu];
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
    if ($einheit === '') $einheit = (string) scalar("SELECT einheit FROM item WHERE id=?", [$item_id]) ?: 'Stk';
    q("INSERT INTO charge (charge_nr,item_id,menge,menge_verfuegbar,einheit,mhd,wareneingang,status,fremd_kunde_id,notiz,angelegt)
       VALUES (?,?,?,?,?,?,CURDATE(),'frei',?,?,?)",
      [$charge_nr ?: null, $item_id, $menge, $menge, $einheit, $mhd ?: null, $kunde_id, $notiz ?: 'Fremdlager-Wareneingang', gmdate('Y-m-d H:i:s')]);
    return (int) insert_id();
}

// Eine Charge mit ALLEN Feldern fuer die Detailansicht (inkl. Lieferant, Wareneingang, Tracking).
function erp_charge_voll(int $id): ?array {
    if (!tabelle_da('charge')) return null;
    $lief = tabelle_da('lieferanten');
    $sql = "SELECT c.*, i.name AS item_name, i.artikelnummer, i.kategorie, i.form, i.notiz AS item_notiz"
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
    return all("SELECT id, name, kategorie, einheit, form FROM item
                WHERE kategorie IN ('rohstoff','verpackung','verbrauch','fertig','verkaufsfertig')$w
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
    q("UPDATE nummernkreis SET naechste = naechste + 1 WHERE prefix = ?", [$prefix]);
    $r = one("SELECT naechste - 1 AS nr, stellen FROM nummernkreis WHERE prefix = ?", [$prefix]);
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
    return one("SELECT id, name, einheit, kategorie, form FROM item WHERE id=?", [$id]);
}

// Neuen Artikel direkt aus dem Lager anlegen (Wareneingang „neue Sache einpflegen"). Minimal: Name,
// Kategorie, Einheit. Keine Artikelnummer (die Nummernkreise des Dashboards sind hier nicht geladen) –
// das Team ergaenzt Details spaeter im Dashboard. Doppelte (gleicher Name + Kategorie) werden
// wiederverwendet. Gibt die item-id oder null.
function erp_item_anlegen(string $name, string $kategorie, string $einheit): ?int {
    if (!tabelle_da('item')) return null;
    $name = trim($name);
    if ($name === '') return null;
    $erlaubt = ['rohstoff', 'verpackung', 'verbrauch', 'fertig'];
    if (!in_array($kategorie, $erlaubt, true)) $kategorie = 'rohstoff';
    $einheit = erp_einheit_norm($einheit);
    if ($einheit === '') $einheit = $kategorie === 'rohstoff' ? 'kg' : 'Stk';
    $ex = scalar("SELECT id FROM item WHERE name=? AND kategorie=? LIMIT 1", [$name, $kategorie]);
    if ($ex) return (int)$ex;
    q("INSERT INTO item (artikelnummer, name, kategorie, einheit, preis_bezug, gesperrt, notiz)
       VALUES (NULL, ?, ?, ?, ?, 0, ?)",
      [$name, $kategorie, $einheit, $einheit, 'Im Lager beim Wareneingang angelegt.']);
    return (int) insert_id();
}

// „Waren, auf die wir warten" – beim Lieferanten bestellt, aber noch nicht angekommen (status='bestellt',
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
    $b = one("SELECT b.id, b.nummer, b.lieferant_id, lf.firma AS lieferant
              FROM bestellung b LEFT JOIN lieferanten lf ON lf.id = b.lieferant_id
              WHERE b.tracking IS NOT NULL AND b.tracking<>'' AND REPLACE(b.tracking,' ','') = ?
                AND b.angekommen_am IS NULL
              ORDER BY b.id DESC LIMIT 1", [$tc]);
    if (!$b) return ['ok' => false];
    $pos = [];
    if (tabelle_da('bestellung_position')) {
        foreach (all("SELECT bp.item_id, bp.menge, bp.einheit,
                             COALESCE(NULLIF(i.name,''), bp.bezeichnung) AS name, i.kategorie
                      FROM bestellung_position bp LEFT JOIN item i ON i.id = bp.item_id
                      WHERE bp.bestellung_id = ? ORDER BY bp.sort, bp.id", [(int)$b['id']]) as $p) {
            if (trim((string)($p['name'] ?? '')) === '') continue;
            $pos[] = [
                'name'      => (string)$p['name'],
                'menge'     => (float)$p['menge'],
                'einheit'   => erp_einheit_norm((string)($p['einheit'] ?? '')),
                'charge_nr' => '',
                'mhd'       => '',
                'warenart'  => (string)($p['kategorie'] ?? ''),
            ];
        }
    }
    return ['ok' => true, 'lieferant' => (string)$b['lieferant'], 'lieferant_id' => (int)$b['lieferant_id'],
            'nummer' => (string)$b['nummer'], 'positionen' => $pos];
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
        foreach (all("SELECT bp.item_id, bp.menge, bp.einheit,
                             COALESCE(NULLIF(i.name,''), bp.bezeichnung) AS name, i.kategorie
                      FROM bestellung_position bp LEFT JOIN item i ON i.id = bp.item_id
                      WHERE bp.bestellung_id = ? ORDER BY bp.sort, bp.id", [$id]) as $p) {
            if (trim((string)($p['name'] ?? '')) === '') continue;
            $pos[] = ['name' => (string)$p['name'], 'menge' => (float)$p['menge'],
                      'einheit' => erp_einheit_norm((string)($p['einheit'] ?? '')),
                      'charge_nr' => '', 'mhd' => '', 'warenart' => (string)($p['kategorie'] ?? '')];
        }
    }
    return ['ok' => true, 'lieferant' => (string)$b['lieferant'], 'lieferant_id' => (int)$b['lieferant_id'],
            'nummer' => (string)$b['nummer'], 'positionen' => $pos];
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
        return all("SELECT r.id, r.nummer, r.name, r.darreichungsform,
                           bi.id AS bulk_item_id, bi.einheit AS bulk_einheit
                      FROM rezeptur r
                      LEFT JOIN item bi ON bi.rezeptur_id = r.id AND bi.kategorie='fertig'
                     WHERE r.status <> 'entwurf'
                     ORDER BY r.name, r.id");
    } catch (Throwable $e) { return []; }
}

// Kanonisches Bulk-Item einer Rezeptur auflösen (read-only). Null, wenn es noch keines gibt
// (dann im Dashboard anlegen lassen – siehe ANTWORT-DASHBOARD-REZEPTUR-BULKITEM.md, Variante A).
function erp_rezeptur_bulkitem(int $rezeptur_id): ?int {
    if ($rezeptur_id <= 0 || !tabelle_da('item')) return null;
    try {
        $id = scalar("SELECT id FROM item WHERE rezeptur_id=? AND kategorie='fertig' LIMIT 1", [$rezeptur_id]);
        return $id ? (int)$id : null;
    } catch (Throwable $e) { return null; }
}

function erp_erwartete_lieferungen(): array {
    if (!tabelle_da('bestellung')) return [];
    $rows = all("SELECT b.id, b.nummer, b.bestelldatum, b.eta_geplant, b.tracking, b.versandanbieter,
                        b.notiz, b.lieferant_id, lf.firma AS lieferant
                 FROM bestellung b LEFT JOIN lieferanten lf ON lf.id = b.lieferant_id
                 WHERE b.status = 'bestellt' AND b.angekommen_am IS NULL
                 ORDER BY (b.eta_geplant IS NULL), b.eta_geplant, b.bestelldatum DESC, b.id DESC");
    foreach ($rows as &$r) {
        $pos = tabelle_da('bestellung_position')
            ? all("SELECT bp.item_id, bp.auftrag_id, bp.menge, bp.einheit,
                          COALESCE(NULLIF(i.name,''), bp.bezeichnung) AS name, i.kategorie, i.form
                   FROM bestellung_position bp LEFT JOIN item i ON i.id = bp.item_id
                   WHERE bp.bestellung_id = ? ORDER BY bp.sort, bp.id", [(int)$r['id']])
            : [];
        foreach ($pos as &$p)
            $p['kapselgroesse'] = erp_kapselgroesse_label((int)($p['item_id'] ?? 0), (int)($p['auftrag_id'] ?? 0));
        unset($p);
        $r['positionen'] = $pos;
    }
    unset($r);
    return $rows;
}

// Wareneingang buchen: legt eine Charge an (oder fuellt eine vorab aus einer CoA angelegte Charge).
// Rohstoff/Fertigware -> Quarantaene, sonst sofort frei. Rueckgabe: neue/aktualisierte charge.id oder null.
function erp_wareneingang_buchen(int $item_id, float $menge, string $charge_nr, ?string $mhd,
                                 ?int $lieferant_id, string $notiz = '', string $status = 'frei', string $einheit = ''): ?int {
    if (!tabelle_da('charge') || !tabelle_da('item')) return null;
    $it = one("SELECT kategorie, einheit FROM item WHERE id=?", [$item_id]);
    if (!$it || $menge <= 0) return null;
    // Eingegebene Einheit hat VORRANG vor den Stammdaten (z. B. Kapseln = Stk, nicht kg).
    $einheit = erp_einheit_norm(trim($einheit));
    if ($einheit === '') $einheit = (string)$it['einheit'];
    // Status wird beim Einbuchen gewaehlt (Standard: freigegeben). Quarantaene nur im Sonderfall.
    $status = in_array($status, ['frei', 'quarantaene', 'gesperrt'], true) ? $status : 'frei';
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
            erp_bedarf_bump();
            return (int)$vorab['id'];
        }
    }
    q("INSERT INTO charge (charge_nr,item_id,menge,menge_verfuegbar,einheit,lieferant_id,mhd,wareneingang,status,notiz,angelegt)
       VALUES (?,?,?,?,?,?,?,CURDATE(),?,?,?)",
      [$charge_nr ?: null, $item_id, $menge, $menge, $einheit, $lief, $mhd ?: null, $status, $notiz ?: null, jetzt_utc()]);
    $neu = (int) insert_id();
    erp_bedarf_bump();
    return $neu;
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
    if ($def['form'] !== '') {
        q("UPDATE item SET kategorie=?, form=? WHERE id=?", [$def['kategorie'], $def['form'], $item_id]);
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
    $txt = ['frei' => 'Freigegeben', 'quarantaene' => 'In Quarantäne', 'gesperrt' => 'Gesperrt'];
    return ['ok' => true, 'meldung' => 'Status: ' . $txt[$status]];
}

// Warenausgang: Menge von einer Charge abbuchen. Leer -> Status 'leer'. Fremdlager-Chargen sind tabu.
// Rueckgabe: ['ok'=>bool, 'meldung'=>?, 'leer'=>bool, 'rest'=>float, 'item_name'=>?, 'einheit'=>?].
function erp_charge_entnehmen(int $charge_id, float $menge): array {
    if (!tabelle_da('charge')) return ['ok' => false, 'meldung' => 'Keine Chargen vorhanden.'];
    $c = one("SELECT c.*, i.name AS item_name FROM charge c JOIN item i ON i.id=c.item_id WHERE c.id=?", [$charge_id]);
    if (!$c) return ['ok' => false, 'meldung' => 'Charge nicht gefunden.'];
    if (!empty($c['fremd_kunde_id'])) return ['ok' => false, 'meldung' => 'Fremdlager-Charge – Entnahme läuft über das Fulfillment.'];
    if ($menge <= 0) return ['ok' => false, 'meldung' => 'Bitte eine Menge größer 0 angeben.'];
    $verf = (float)$c['menge_verfuegbar'];
    if ($menge > $verf + 1e-9) return ['ok' => false, 'meldung' => 'Nur noch ' . menge_txt($verf) . ' ' . (string)$c['einheit'] . ' verfügbar.'];
    $rest = $verf - $menge;
    $leer = $rest <= 1e-9;
    q("UPDATE charge SET menge_verfuegbar=?, status=? WHERE id=?",
      [$leer ? 0 : $rest, $leer ? 'leer' : (string)$c['status'], $charge_id]);
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
    return all("SELECT id, name, kategorie, einheit, form FROM item
                WHERE kategorie IN ('rohstoff','verpackung','verbrauch','fertig','verkaufsfertig')$w
                  AND name LIKE ? ESCAPE '='
                ORDER BY (name=?) DESC, (name LIKE ? ESCAPE '=') DESC, CHAR_LENGTH(name), name
                LIMIT " . (int)$limit, [$enth, $name, $anf]);
}

// Welche Felder sind je Warenart Pflicht und geht die Ware in Quarantaene?
// $kategorie = item.kategorie, $form = item.form ('kapselhuelle' = Leerkapseln).
// Rueckgabe: ['mhd_pflicht'=>bool, 'charge_pflicht'=>bool, 'quarantaene'=>bool].
// (Die vom Nutzer gewuenschte Matrix. Ein Schalter je Artikel als Ausnahme kommt spaeter.)
function erp_warenart_regeln(string $kategorie, string $form = ''): array {
    if ($form === 'kapselhuelle')
        return ['mhd_pflicht' => true, 'charge_pflicht' => true, 'quarantaene' => true];
    return match ($kategorie) {
        'rohstoff'              => ['mhd_pflicht' => true,  'charge_pflicht' => true,  'quarantaene' => true],
        'fertig', 'verkaufsfertig' => ['mhd_pflicht' => true,  'charge_pflicht' => true,  'quarantaene' => true],
        'kapsel'               => ['mhd_pflicht' => true,  'charge_pflicht' => true,  'quarantaene' => true],
        'verpackung'           => ['mhd_pflicht' => false, 'charge_pflicht' => false, 'quarantaene' => false],
        'verbrauch'            => ['mhd_pflicht' => false, 'charge_pflicht' => false, 'quarantaene' => false],
        default                => ['mhd_pflicht' => false, 'charge_pflicht' => false, 'quarantaene' => false],
    };
}

// Eine Lieferschein-Position einem bestehenden Artikel zuordnen (oder als "neu" markieren).
// Rueckgabe reichert die Position an: item_id (0 = neu), item_name, kategorie, einheit, form,
// kandidaten[] (fuer die Auswahl), regeln[] (Pflichtfelder der erkannten/vermuteten Warenart).
function erp_position_zuordnen(array $pos): array {
    $kandidaten = erp_item_suchen((string)($pos['name'] ?? ''));
    $treffer = $kandidaten[0] ?? null;
    // Als sichere Zuordnung nur werten, wenn der Name exakt passt (sonst nur Vorschlag).
    $exakt = $treffer && mb_strtolower(trim((string)$treffer['name'])) === mb_strtolower(trim((string)($pos['name'] ?? '')));
    $kat = $exakt ? (string)$treffer['kategorie'] : ((string)($pos['warenart'] ?? '') ?: 'rohstoff');
    $form = $exakt ? (string)($treffer['form'] ?? '') : '';
    $pos['item_id']    = $exakt ? (int)$treffer['id'] : 0;
    $pos['item_name']  = $exakt ? (string)$treffer['name'] : (string)($pos['name'] ?? '');
    $pos['kategorie']  = $kat;
    $pos['form']       = $form;
    if (($pos['einheit'] ?? '') === '' && $exakt) $pos['einheit'] = (string)$treffer['einheit'];
    $pos['einheit']    = erp_einheit_norm((string)($pos['einheit'] ?? ''));   // einheitliche Einheit (Stk, kg, …)
    $pos['kandidaten'] = $kandidaten;
    $pos['regeln']     = erp_warenart_regeln($kat, $form);
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
