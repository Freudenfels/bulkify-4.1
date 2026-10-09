<?php
// DIE NAHT des Buchhaltungs-Programms. Zugriffe auf GETEILTE Dashboard-Tabellen laufen hier gebündelt:
// - benutzer (Login/Sitzung)  - app_meta (Einstellungen: ust_inland, Firma, Wechselkurse …)
// - nummernkreis (fortlaufende Belegnummern, EINE Quelle für Dashboard + Buchhaltung)
// - aktivitaet (Kunden-Verlauf)
// Finanz-EIGENE Tabellen (beleg*, zahlung, lieferant_rechnung/_zahlung) stehen NICHT hier, sondern in
// schema.php/finanz.php. Wer eine geteilte Spalte umbenennt, prüft genau diese Datei.
require_once __DIR__ . '/db.php';

// ---- benutzer (Mitarbeiter-Logins, geteilt mit dem Dashboard; nur Lesen) --------------------------
function erp_benutzer_per_mail(string $email): ?array {
    return one("SELECT * FROM benutzer WHERE email=? AND aktiv=1", [trim(mb_strtolower($email))]);
}
function erp_benutzer_per_token(string $token): ?array {
    if ($token === '') return null;
    return one("SELECT * FROM benutzer WHERE login_token=? AND aktiv=1", [$token]);
}
function erp_benutzer(int $id): ?array {
    return $id ? one("SELECT * FROM benutzer WHERE id=? AND aktiv=1", [$id]) : null;
}
function erp_dashboard_url(): string { return '/'; }

// ---- app_meta (Einstellungen, geteilt) – verbatim aus dem Dashboard -------------------------------
function &meta_cache(): array { static $c = []; return $c; }
function meta_get(string $k, $default = null) {
    $c = &meta_cache();
    if (!array_key_exists($k, $c)) {
        $v = scalar("SELECT v FROM app_meta WHERE k = ?", [$k]);
        $c[$k] = $v === false ? null : $v;
    }
    return $c[$k] === null ? $default : $c[$k];
}
function meta_set(string $k, $v): void {
    q("INSERT INTO app_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [$k, $v]);
    $c = &meta_cache();
    $c[$k] = (string)$v;
}

// GoBD scharfgeschaltet? Default AUS (Aufbau-/Migrationsphase: Belege/Beträge/Positionen frei korrigierbar).
// Scharf = festgeschriebene/freigegebene/bezahlte Belege sind unveränderbar (nur Storno/Gutschrift).
function gobd_scharf(): bool { return (string) meta_get('gobd_scharf', '0') === '1'; }

// ---- nummernkreis (fortlaufende Nummern, EINE geteilte Quelle) – verbatim -------------------------
function naechste_nummer(string $prefix): string {
    $prefix = strtoupper(trim($prefix));
    q("INSERT IGNORE INTO nummernkreis (prefix, naechste, stellen) VALUES (?, 2690, 4)", [$prefix]);
    q("UPDATE nummernkreis SET naechste = naechste + 1 WHERE prefix = ?", [$prefix]);
    $r = one("SELECT naechste - 1 AS nr, stellen FROM nummernkreis WHERE prefix = ?", [$prefix]);
    return $prefix . '-' . str_pad((string)$r['nr'], (int)$r['stellen'], '0', STR_PAD_LEFT);
}
function nummer_zurueckgeben(string $nummer): void {
    if (!preg_match('/^([A-Z]+)-(\d+)$/', strtoupper(trim($nummer)), $m)) return;
    q("UPDATE nummernkreis SET naechste = naechste - 1 WHERE prefix = ? AND naechste = ?", [$m[1], (int)$m[2] + 1]);
}

// ---- kunden (geteilt) ------------------------------------------------------------------------------
// Lesen: alle Kunden (für Zuordnung/Abgleich im Import). Nur id + firma + ust_id + kundennummer.
function erp_kunden_alle(): array {
    if (!tabelle_da('kunden')) return [];
    return all("SELECT id, firma, kundennummer, ust_id FROM kunden ORDER BY firma");
}
function erp_kunde(int $id): ?array {
    return ($id && tabelle_da('kunden')) ? one("SELECT * FROM kunden WHERE id=?", [$id]) : null;
}
// Fuzzy-Suche über die Firma (für den KI-Namensabgleich). Gibt die beste Übereinstimmung oder null.
function erp_kunde_per_firma(string $firma): ?array {
    $firma = trim($firma);
    if ($firma === '' || !tabelle_da('kunden')) return null;
    $exact = one("SELECT id, firma FROM kunden WHERE firma=? LIMIT 1", [$firma]);
    if ($exact) return $exact;
    return one("SELECT id, firma FROM kunden WHERE firma LIKE ? ORDER BY CHAR_LENGTH(firma) LIMIT 1", ['%' . $firma . '%']);
}
// EINZIGE Schreibstelle der Buchhaltung in die Kunden: Neuanlage (nur firma Pflicht). Gibt die neue id.
// Für den Rechnungs-Import („Kunde neu anlegen", wenn kein Treffer). Vergibt eine Kundennummer (K-…).
function erp_kunde_anlegen(string $firma, array $extra = []): int {
    $firma = trim($firma);
    if ($firma === '') return 0;
    $vorhanden = one("SELECT id FROM kunden WHERE firma=? LIMIT 1", [$firma]);
    if ($vorhanden) return (int)$vorhanden['id'];
    q("INSERT INTO kunden (kundennummer, firma, email, ort, land) VALUES (?,?,?,?,?)",
      [naechste_nummer('K'), $firma, trim((string)($extra['email'] ?? '')) ?: null,
       trim((string)($extra['ort'] ?? '')) ?: null, strtoupper((string)($extra['land'] ?? 'DE')) ?: 'DE']);
    return insert_id();
}

// ---- angebot / auftrag (geteilt, NUR LESEN; für die Buchhaltungs-Ansicht + Abgleich) --------------
// Angebote mit Kundenname + Produkt + repräsentativer Summe (bestätigte, sonst erste Staffel: menge×VK).
// $kunde_id filtert auf einen Kunden; $suche filtert (Nummer/Kunde) serverseitig im Aufrufer.
function erp_angebote(?int $kunde_id = null, string $suche = ''): array {
    if (!tabelle_da('angebot')) return [];
    $where = '1=1'; $args = [];
    if ($kunde_id) { $where .= ' AND a.kunde_id=?'; $args[] = $kunde_id; }
    $rows = all(
        "SELECT a.id, a.nummer, a.status, a.angelegt, a.gueltig_bis, a.kunde_id,
                k.firma AS kunde_firma, p.name AS produkt_name,
                (SELECT s.menge * s.vk_stueck FROM angebot_staffel s WHERE s.angebot_id=a.id
                   ORDER BY s.bestaetigt DESC, s.sort ASC, s.id ASC LIMIT 1) AS summe_netto,
                (SELECT COUNT(*) FROM angebot_staffel s WHERE s.angebot_id=a.id) AS staffel_anzahl
           FROM angebot a
           LEFT JOIN kunden k ON k.id=a.kunde_id
           LEFT JOIN produkt p ON p.id=a.produkt_id
          WHERE $where
          ORDER BY a.angelegt DESC, a.id DESC", $args);
    if ($suche !== '') {
        $n = mb_strtolower($suche);
        $rows = array_values(array_filter($rows, fn($r) =>
            mb_strpos(mb_strtolower((string)$r['nummer']), $n) !== false
            || mb_strpos(mb_strtolower((string)$r['kunde_firma']), $n) !== false
            || mb_strpos(mb_strtolower((string)$r['produkt_name']), $n) !== false));
    }
    return $rows;
}

// Einzelner Auftrag (voll) + Produktname. Für die Positionsübernahme in die Rechnung. Nur Lesen.
function erp_auftrag(int $id): ?array {
    if (!$id || !tabelle_da('auftrag')) return null;
    return one("SELECT a.*, p.name AS produkt_name FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id WHERE a.id=?", [$id]);
}

// Kontingent (Jahresvertrag) zu einem Abruf-Auftrag. Nur Lesen. Liefert die gewaehlte Option (gruppe) +
// den Festpreis je Packung (vk_stueck) + das Quell-Angebot – fuer die Aufschluesselung der Abruf-Rechnung.
function erp_kontingent(int $id): ?array {
    if (!$id || !tabelle_da('kontingent')) return null;
    return one("SELECT id, angebot_id, gruppe, vk_stueck FROM kontingent WHERE id=?", [$id]);
}

// Echte (hinterlegte) Angebotspositionen – Rohzeilen aus angebot_position (leere v3-Null-Zeilen raus).
// Für die aufgeschlüsselte Rechnungsposition. Nur Lesen. (Staffel-/Auto-Ableitung liegt im Dashboard.)
function erp_angebot_positionen(int $angebot_id): array {
    if (!$angebot_id || !tabelle_da('angebot_position')) return [];
    $rows = all("SELECT * FROM angebot_position WHERE angebot_id=? ORDER BY sort, id", [$angebot_id]);
    return array_values(array_filter($rows, fn($r) => (float)$r['menge'] > 1e-9 || (int)$r['preis_cent'] > 0));
}
// ALLE Angebotspositionen (1:1, ohne Filter) – für DL-Rechnungen (Positionen unverändert übernehmen). Nur Lesen.
function erp_dl_positionen(int $angebot_id): array {
    if (!$angebot_id || !tabelle_da('angebot_position')) return [];
    return all("SELECT * FROM angebot_position WHERE angebot_id=? ORDER BY sort, id", [$angebot_id]);
}

// Aufträge mit Kundenname (für den Abgleich Angebot→Auftrag→Rechnung). Nur Lesen.
function erp_auftraege(?int $kunde_id = null): array {
    if (!tabelle_da('auftrag')) return [];
    $where = '1=1'; $args = [];
    if ($kunde_id) { $where .= ' AND a.kunde_id=?'; $args[] = $kunde_id; }
    return all(
        "SELECT a.id, a.nummer, a.status, a.angebot_id, a.kunde_id, a.gesamt_netto, a.status_datum,
                k.firma AS kunde_firma, p.name AS produkt_name
           FROM auftrag a
           LEFT JOIN kunden k ON k.id=a.kunde_id
           LEFT JOIN produkt p ON p.id=a.produkt_id
          WHERE $where
          ORDER BY a.id DESC", $args);
}

// ---- rezeptur (geteilt, NUR LESEN; + optionaler Produkt-Write) ------------------------------------
// Für „Rezeptur verknüpfen" in der Rechnung (nachträglich Rezepturen an Belege hängen, v3-Import/Freitext).
// Liste für den Picker (nummer/name). Nummer absteigend = neueste zuerst.
function erp_rezepturen(int $limit = 1000): array {
    if (!tabelle_da('rezeptur')) return [];
    return all("SELECT id, nummer, name FROM rezeptur ORDER BY nummer DESC LIMIT " . max(1, $limit));
}
function erp_rezeptur(int $id): ?array {
    return ($id && tabelle_da('rezeptur')) ? one("SELECT id, nummer, name, kunde_id FROM rezeptur WHERE id=?", [$id]) : null;
}
// Rezeptur, die am Produkt des Auftrags hängt (zur Auflösung, wenn beleg.rezeptur_id leer ist). Nur Lesen.
function erp_auftrag_rezeptur_id(int $auftrag_id): int {
    if (!$auftrag_id || !tabelle_da('auftrag')) return 0;
    return (int) scalar("SELECT p.rezeptur_id FROM auftrag a JOIN produkt p ON p.id=a.produkt_id WHERE a.id=?", [$auftrag_id]);
}
// Optional: Rezeptur am Produkt des Auftrags NACHTRAGEN – nur wenn dort noch keine hinterlegt ist
// (damit Produktion/Specs sie kennen; so macht es auch das Dashboard). Gibt true, wenn gesetzt wurde.
function erp_auftrag_produkt_rezeptur_setzen(int $auftrag_id, int $rezeptur_id): bool {
    if (!$auftrag_id || !$rezeptur_id || !tabelle_da('auftrag')) return false;
    $pid = (int) scalar("SELECT produkt_id FROM auftrag WHERE id=?", [$auftrag_id]);
    if (!$pid) return false;
    if ((int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [$pid])) return false;  // schon belegt → nicht überschreiben
    q("UPDATE produkt SET rezeptur_id=? WHERE id=? AND (rezeptur_id IS NULL OR rezeptur_id=0)", [$rezeptur_id, $pid]);
    return true;
}

// ---- aktivitaet (Kunden-Verlauf, geteilt) – verbatim ----------------------------------------------
function log_aktivitaet(string $objekt_typ, int $objekt_id, string $akteur, string $text,
                        string $typ = '', string $ref_typ = '', int $ref_id = 0): void {
    if (!tabelle_da('aktivitaet')) return;
    q("INSERT INTO aktivitaet (objekt_typ,objekt_id,akteur,typ,text,ref_typ,ref_id,erstellt) VALUES (?,?,?,?,?,?,?,?)",
      [$objekt_typ, $objekt_id, $akteur, $typ ?: null, $text, $ref_typ ?: null, $ref_id ?: null, gmdate('Y-m-d H:i:s')]);
}

// ===================================================================================================
// Auftrag-Import (KI) – portiert aus dem Dashboard (core/schema.php), da die Finanz-App eigenständig ist
// und das Dashboard-core nicht laden darf. Schreibt in GETEILTE Tabellen (rezeptur, rezeptur_zutat,
// produkt, auftrag) und gehört deshalb in die Naht. EINZIGER Unterschied zur Dashboard-Fassung:
// produkt_aus_rezeptur() erzeugt KEINE Preismatrix (produkt_matrix_generieren = Dashboard-Preis-Engine).
// Für importierte Alt-Aufträge genügt das Produkt; die Matrix lässt sich im Dashboard bei Bedarf erzeugen.
// ===================================================================================================

// Angebot/AB-PDF per KI auslesen (Produkt, Rezeptur, Menge, Verpackung, Preis). Kein DB-Zugriff.
function auftrag_import_ki(string $pfad): array {
    require_once __DIR__ . '/ki.php';
    if (!ki_bereit()) return ['ok' => false, 'fehler' => 'KI ist nicht eingerichtet (Einstellungen → KI).'];
    $prompt = "Dies ist ein Angebot oder eine Auftragsbestätigung eines Lohnherstellers für Nahrungsergänzung. "
        . "Lies die Daten aus und gib NUR JSON zurück:\n"
        . '{"kunde_nr":"","kunde_name":"","ab_nummer":"","datum":"","produkt_name":"","darreichungsform":"kapsel",'
        . '"verpackung":"","stueck_je_packung":0,"menge":0,"vk_stueck":0,"gesamt_netto":0,"ust_prozent":19,'
        . '"zutaten":[{"name":"","menge_mg":0}]}' . "\n"
        . "Regeln: produkt_name = Name der Hauptposition (ohne die Rezeptur-Aufzählung). "
        . "zutaten = die in der Positionsbezeichnung aufgeführten Wirkstoffe mit mg je Einheit (z. B. 'NAC 300 mg'). "
        . "darreichungsform eines von kapsel|tablette|softgel|stick|pulver|fluessig. "
        . "verpackung = Behälter-Text, falls genannt (z. B. '150 ml Weithalsglas'), sonst ''. "
        . "stueck_je_packung = Kapseln/Stück je Packung (z. B. 120). menge = Anzahl Packungen (Bestellmenge). "
        . "vk_stueck = Preis je Packung (netto), gesamt_netto = Positionen-Netto gesamt. "
        . "Zahlen mit Punkt als Dezimaltrennzeichen, keine Tausenderpunkte. Nichts erfinden – Unbekanntes leer/0.";
    $r = ki_datei_frage($pfad, $prompt, ['json' => true, 'denken' => true, 'max_tokens' => 4000, 'timeout' => 240, 'zweck' => 'auftrag-import']);
    if (empty($r['ok'])) return ['ok' => false, 'fehler' => (string)($r['fehler'] ?? 'Das Dokument konnte nicht gelesen werden.')];
    $d = is_array($r['daten'] ?? null) ? $r['daten'] : [];
    $num = fn($x) => (float) str_replace(',', '.', (string)$x);
    $zut = [];
    foreach ((array)($d['zutaten'] ?? []) as $z) {
        if (!is_array($z)) continue;
        $n = trim((string)($z['name'] ?? '')); if ($n === '') continue;
        $zut[] = ['name' => mb_substr($n, 0, 190), 'menge_mg' => $num($z['menge_mg'] ?? 0)];
    }
    $formen = ['kapsel','tablette','softgel','stick','pulver','fluessig'];
    $form = strtolower(trim((string)($d['darreichungsform'] ?? 'kapsel')));
    return ['ok' => true, 'daten' => [
        'kunde_nr'   => trim((string)($d['kunde_nr'] ?? '')),
        'kunde_name' => trim((string)($d['kunde_name'] ?? '')),
        'ab_nummer'  => trim((string)($d['ab_nummer'] ?? '')),
        'datum'      => (is_string($d['datum'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['datum'])) ? $d['datum'] : null,
        'produkt_name' => mb_substr(trim((string)($d['produkt_name'] ?? '')), 0, 190),
        'darreichungsform' => in_array($form, $formen, true) ? $form : 'kapsel',
        'verpackung' => trim((string)($d['verpackung'] ?? '')),
        'stueck_je_packung' => (int) round($num($d['stueck_je_packung'] ?? 0)),
        'menge'      => (int) round($num($d['menge'] ?? 0)),
        'vk_stueck'  => round($num($d['vk_stueck'] ?? 0), 4),
        'gesamt_netto' => round($num($d['gesamt_netto'] ?? 0), 2),
        'ust_prozent' => $num($d['ust_prozent'] ?? 0),
        'zutaten'    => $zut,
    ]];
}

// Verpackung (Behälter) anhand Freitext finden (Volumen ml + Typwort). Liest GETEILTE item-Tabelle.
function verpackung_finden(string $text): ?int {
    $text = trim($text); if ($text === '') return null;
    $mlV = null; if (preg_match('/(\d+(?:[.,]\d+)?)\s*ml/i', $text, $m)) $mlV = (float) str_replace(',', '.', $m[1]);
    foreach (all("SELECT id, name, volumen_ml FROM item WHERE kategorie='verpackung'") as $it) {
        $nameTreffer = mb_stripos($text, (string)$it['name']) !== false || mb_stripos((string)$it['name'], $text) !== false;
        $volTreffer  = $mlV !== null && abs((float)($it['volumen_ml'] ?? 0) - $mlV) < 0.5;
        if ($nameTreffer || ($volTreffer && (mb_stripos($text, 'glas') !== false) === (mb_stripos((string)$it['name'], 'glas') !== false))) return (int)$it['id'];
    }
    return null;
}

// Rezeptur zu Name (+ Zutaten) finden oder neu anlegen. Liest/schreibt GETEILTE rezeptur/rezeptur_zutat.
function rezeptur_finden_oder_anlegen(string $name, string $form, array $zutaten, ?int $kunde_id): array {
    $name = trim($name) !== '' ? mb_substr(trim($name), 0, 190) : 'Rezeptur-Import';
    $treffer = one("SELECT id FROM rezeptur WHERE name=? ORDER BY (kunde_id<=>?) DESC, id LIMIT 1", [$name, $kunde_id]);
    if ($treffer) return ['id' => (int)$treffer['id'], 'neu' => false];
    q("INSERT INTO rezeptur (nummer,name,kunde_id,darreichungsform,status,notiz) VALUES (?,?,?,?,?,?)",
      [naechste_nummer('RZ'), $name, $kunde_id ?: null, $form ?: 'kapsel', 'eingefroren', 'Aus Angebot/AB importiert (KI).']);
    $rid = (int) insert_id();
    $sort = 0;
    foreach ($zutaten as $z) {
        $bez = trim((string)($z['name'] ?? '')); if ($bez === '') continue;
        $iid = scalar("SELECT id FROM item WHERE name=? AND kategorie='rohstoff' LIMIT 1", [$bez]);
        q("INSERT INTO rezeptur_zutat (rezeptur_id,item_id,bezeichnung,menge_mg,sort) VALUES (?,?,?,?,?)",
          [$rid, $iid ?: null, mb_substr($bez, 0, 190), (float)($z['menge_mg'] ?? 0), $sort++]);
    }
    return ['id' => $rid, 'neu' => true];
}

// Kleine Form-Label-Helfer (verbatim aus dem Dashboard) für den Produktnamen.
function form_groessen_einheit(string $form): string {
    if (in_array($form, ['pulver', 'granulat'], true)) return 'g';
    if (in_array($form, ['fluessig', 'gel'], true)) return 'ml';
    return '';
}
function form_plural(string $form): string {
    return ['kapsel'=>'Kapseln', 'tablette'=>'Tabletten', 'softgel'=>'Softgels', 'stick'=>'Sticks', 'gummi'=>'Gummis'][$form] ?? 'Stück';
}
function form_groessen_label(string $form, float $wert): string {
    $e = form_groessen_einheit($form);
    if ($e !== '') return rtrim(rtrim(number_format($wert, 1, ',', '.'), '0'), ',') . ' ' . $e;
    return (int) $wert . ' ' . form_plural($form);
}
// Eindeutigen Produktnamen bilden (… vN bei Dubletten). Liest GETEILTE produkt-Tabelle.
function produkt_name_versioniert(string $name, int $exclude_id = 0): string {
    $name = trim($name);
    if ($name === '') return $name;
    $base = preg_replace('/\s+v\d+$/i', '', $name);
    if ($base === '') $base = $name;
    $baseExists = false; $used = [];
    foreach (all("SELECT name FROM produkt WHERE id<>?", [$exclude_id]) as $o) {
        $on = trim((string) $o['name']);
        if (strcasecmp($on, $base) === 0) $baseExists = true;
        if (preg_match('/^' . preg_quote($base, '/') . '\s+v(\d+)$/i', $on, $m)) $used[(int) $m[1]] = true;
    }
    if (!$baseExists && !$used) return $base;
    $n = 2; while (isset($used[$n])) $n++;
    return $base . ' v' . $n;
}

// Produkt zu (Rezeptur x Packungsgröße + Behälter) finden oder anlegen. Liest/schreibt GETEILTE produkt.
// HINWEIS: ohne Preismatrix (produkt_matrix_generieren ist Dashboard-Domäne) – im Dashboard nacherzeugbar.
function produkt_aus_rezeptur(int $rezeptur_id, int $stueck, ?int $verp_id, ?int $vorlage_produkt_id = null): ?int {
    if ($rezeptur_id <= 0 || $stueck <= 0) return null;
    $treffer = one("SELECT id FROM produkt WHERE rezeptur_id=? AND einheiten_pro_packung=? AND (verpackung_id <=> ?) AND exklusiv=0 ORDER BY id LIMIT 1",
                   [$rezeptur_id, $stueck, $verp_id]);
    if ($treffer) return (int)$treffer['id'];
    $v = $vorlage_produkt_id ? one("SELECT * FROM produkt WHERE id=?", [$vorlage_produkt_id]) : null;
    $form = (string) scalar("SELECT darreichungsform FROM rezeptur WHERE id=?", [$rezeptur_id]) ?: 'kapsel';
    $rez  = (string) scalar("SELECT name FROM rezeptur WHERE id=?", [$rezeptur_id]) ?: 'Produkt';
    $behName = $verp_id ? (string) scalar("SELECT name FROM item WHERE id=?", [$verp_id]) : '';
    $name = produkt_name_versioniert($rez . ' · ' . form_groessen_label($form, (float)$stueck) . ($behName !== '' ? ' · ' . $behName : ''));
    q("INSERT INTO produkt (nummer,name,kunde_id,rezeptur_id,verpackung_id,verschluss_id,etikett_id,karton_id,beipack_id,leerkapsel_id,exklusiv,einheiten_pro_packung,einnahme_pro_tag,status,notiz)
       VALUES (?,?,NULL,?,?,?,?,?,?,?,0,?,?,?,?)",
      [naechste_nummer('P'), $name, $rezeptur_id, $verp_id,
       $v['verschluss_id'] ?? null, $v['etikett_id'] ?? null, $v['karton_id'] ?? null, $v['beipack_id'] ?? null, $v['leerkapsel_id'] ?? null,
       $stueck, $v['einnahme_pro_tag'] ?? 1, 'aktiv',
       'Aus einem Angebots-/AB-Import entstanden (Rezeptur x Menge + Verpackung). Preismatrix im Dashboard nacherzeugbar.']);
    return (int) insert_id();
}

// Auftrag aus den ausgelesenen Importdaten anlegen (idempotent über import_ref). Schreibt GETEILTE auftrag.
function auftrag_aus_import(int $kunde_id, array $d, string $status): array {
    if ($kunde_id <= 0) return ['ok' => false, 'fehler' => 'Kein Kunde gewählt.'];
    $status = in_array($status, ['offen','in_produktion','erledigt'], true) ? $status : 'erledigt';
    $ref = trim((string)($d['ab_nummer'] ?? ''));
    if ($ref !== '') {
        $ex = scalar("SELECT id FROM auftrag WHERE kunde_id=? AND import_ref=? LIMIT 1", [$kunde_id, $ref]);
        if ($ex) return ['ok' => true, 'auftrag_id' => (int)$ex, 'schon_da' => true];
    }
    $rez = rezeptur_finden_oder_anlegen((string)($d['produkt_name'] ?? ''), (string)($d['darreichungsform'] ?? 'kapsel'), (array)($d['zutaten'] ?? []), $kunde_id);
    $stueck = max(0, (int)($d['stueck_je_packung'] ?? 0));
    $verpId = !empty($d['verpackung']) ? verpackung_finden((string)$d['verpackung']) : null;
    $pid = produkt_aus_rezeptur($rez['id'], $stueck > 0 ? $stueck : 1, $verpId, null);
    $menge = max(0, (int)($d['menge'] ?? 0));
    $vk    = round((float)($d['vk_stueck'] ?? 0), 4);
    $netto = round((float)($d['gesamt_netto'] ?? 0), 2);
    if ($netto <= 0 && $vk > 0 && $menge > 0) $netto = round($vk * $menge, 2);
    $datum = (is_string($d['datum'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['datum'])) ? $d['datum'] : gmdate('Y-m-d');
    q("INSERT INTO auftrag (nummer,kunde_id,produkt_id,menge,stueck,verpackung_id,vk_stueck,gesamt_netto,status,status_datum,produkt_bezeichnung,produkt_form,import_ref)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
      [naechste_nummer('AB'), $kunde_id, $pid ?: null, $menge, $stueck ?: null, $verpId ?: null, $vk, $netto, $status, $datum,
       mb_substr((string)($d['produkt_name'] ?? ''), 0, 190) ?: null, (string)($d['darreichungsform'] ?? 'kapsel'), ($ref !== '' ? mb_substr($ref, 0, 60) : null)]);
    $aid = (int) insert_id();
    log_aktivitaet('kunde', $kunde_id, 'team', 'Auftrag aus Angebot/AB importiert' . ($ref !== '' ? ' (' . $ref . ')' : '') . '.', 'auftrag', 'auftrag', $aid);
    return ['ok' => true, 'auftrag_id' => $aid, 'produkt_id' => (int)$pid, 'rezeptur_id' => $rez['id'], 'rezeptur_neu' => $rez['neu'], 'schon_da' => false];
}
