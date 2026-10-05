<?php
// DIE NAHT ZUM DASHBOARD. Einzige Datei im Produktions-Programm, die Dashboard-Tabellen kennt
// (produktionsauftrag, produktion_schritt, charge, auftrag, produkt, kunden, benutzer).
//
// Warum an EINER Stelle: Ändert sich im Dashboard eine Spalte, darf genau diese Datei kaputtgehen –
// überall sonst im Produktions-Programm stehen nur eigene `pr_`-Tabellen (falls welche dazukommen).
//
// WICHTIG (Abgrenzung): Die eigentliche Produktionslogik (Schritt abschließen inkl. FEFO/Chargen-
// Entnahme, Mangel-Guard, Bereitschaft) lebt bislang im Dashboard in core/schema.php. Dieses Programm
// LIEST hier nur. Sobald es selbst Schritte abschließen soll, kommt die Schreib-Logik als benannte
// Funktion HIER rein – entweder als eigene Umsetzung oder, sauberer, nachdem die Dashboard-Funktionen
// (produktion_schritt_erledigen() etc.) in eine von beiden Programmen nutzbare Bibliothek ausgelagert
// wurden. NICHT core/schema.php des Dashboards hier einbinden (zieht das ganze Dashboard herein).
require_once __DIR__ . '/db.php';

// --- Benutzer (gemeinsame Logins mit dem Dashboard) ------------------------------------------
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

// --- Produktionsaufträge (nur lesen) ---------------------------------------------------------
// Liste der Produktionsaufträge mit Produkt/Kunde/Fortschritt + Auftragseingang.
// $status: '' = aktive (offen+laufend), 'alle' = alle, sonst genau dieser Status (offen|laufend|erledigt).
function erp_produktionsauftraege(string $status = ''): array {
    if (!tabelle_da('produktionsauftrag')) return [];
    $sql = "SELECT pa.*, a.nummer AS auftrag_nr, a.angelegt AS auftrag_eingang,
                   COALESCE(NULLIF(p.kundenname,''), p.name, r.name) AS produkt_name,
                   r.darreichungsform AS form, k.firma AS kunde,
                   (SELECT COUNT(*) FROM produktion_schritt s WHERE s.pa_id=pa.id) AS schritte_gesamt,
                   (SELECT COUNT(*) FROM produktion_schritt s WHERE s.pa_id=pa.id AND s.erledigt=1) AS schritte_fertig
            FROM produktionsauftrag pa
            LEFT JOIN auftrag a   ON a.id=pa.auftrag_id
            LEFT JOIN produkt p   ON p.id=pa.produkt_id
            LEFT JOIN rezeptur r  ON r.id=COALESCE(pa.rezeptur_id, p.rezeptur_id)
            LEFT JOIN kunden k    ON k.id=pa.kunde_id";
    $params = [];
    if ($status === 'alle')  { /* kein Filter */ }
    elseif ($status !== '')  { $sql .= " WHERE pa.status=?"; $params[] = $status; }
    else                     { $sql .= " WHERE pa.status IN ('offen','laufend')"; }   // aktive (Dashboard-Status)
    if ($status === 'erledigt') $sql .= " ORDER BY pa.aktualisiert DESC, pa.id DESC";  // zuletzt fertig zuerst
    else                        $sql .= " ORDER BY COALESCE(pa.prio,2), (pa.geplant_am IS NULL), pa.geplant_am, pa.id DESC";
    return all($sql, $params);
}
// Ein Produktionsauftrag – mit allen Übersichtsfeldern (Rezeptur, Kapselgröße, VPE, Verpackung, Kunde, Eingang).
function erp_pa(int $id): ?array {
    if ($id <= 0 || !tabelle_da('produktionsauftrag')) return null;
    return one("SELECT pa.*, a.nummer AS auftrag_nr, a.angelegt AS auftrag_eingang, a.stueck AS auftrag_stueck,
                       COALESCE(NULLIF(p.kundenname,''), p.name, r.name) AS produkt_name,
                       p.einheiten_pro_packung AS produkt_vpe,
                       r.name AS rezeptur_name, r.darreichungsform AS form, r.kapselgroesse_id,
                       kg.name AS kapselgroesse,
                       vp.name AS verpackung_name, vp.verpackungsart AS verpackung_art,
                       vp.volumen_ml AS verpackung_volumen, vp.material AS verpackung_material,
                       k.firma AS kunde
                FROM produktionsauftrag pa
                LEFT JOIN auftrag a  ON a.id=pa.auftrag_id
                LEFT JOIN produkt p  ON p.id=pa.produkt_id
                LEFT JOIN rezeptur r ON r.id=COALESCE(pa.rezeptur_id, p.rezeptur_id)
                LEFT JOIN kapselgroesse kg ON kg.id=r.kapselgroesse_id
                LEFT JOIN item vp    ON vp.id=COALESCE(pa.verpackung_id, p.verpackung_id, a.verpackung_id)
                LEFT JOIN kunden k   ON k.id=pa.kunde_id
                WHERE pa.id=?", [$id]);
}
// Schritte eines Produktionsauftrags (Stationen in Reihenfolge).
function erp_pa_schritte(int $pa_id): array {
    if ($pa_id <= 0 || !tabelle_da('produktion_schritt')) return [];
    return all("SELECT * FROM produktion_schritt WHERE pa_id=? ORDER BY sort, id", [$pa_id]);
}

// --- Produktionsweg je Auftrag (Ausbaustufen) ------------------------------------------------
// Der Weg ist in den produktion_schritt-Zeilen abgebildet (keine Extra-Tabelle). Grundweg
// (zukauf/eigen/bulk) bleibt, optionale Stufen (Verpacken/Etikettieren/Beipack/Umkarton) sind schaltbar.

// Grundweg eines Auftrags – anhand der vorhandenen Schritte bzw. Produkt/Rezeptur.
function erp_weg_basis(int $pa_id): string {
    $stationen = array_map(fn($s) => (string)$s['station'], erp_pa_schritte($pa_id));
    if (in_array('Fertigware bereitstellen', $stationen, true)) return 'zukauf';
    if (in_array('Einlagern (Bulk)', $stationen, true)) return 'bulk';
    $pa = one("SELECT produkt_id, rezeptur_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    return ($pa && erp_pa_ist_bulk($pa)) ? 'bulk' : 'eigen';
}
// Herstellungsschritt je Darreichungsform (Eigenproduktion).
function erp_weg_herstellung(int $pa_id): string {
    $pa = one("SELECT produkt_id, rezeptur_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    $form = $pa && erp_pa_ist_bulk($pa)
        ? (string) scalar("SELECT darreichungsform FROM rezeptur WHERE id=?", [(int)$pa['rezeptur_id']])
        : (string) scalar("SELECT r.darreichungsform FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id=?", [(int)($pa['produkt_id'] ?? 0)]);
    return match ($form) {
        'kapsel'=>'Verkapselung', 'tablette'=>'Tablettierung', 'softgel'=>'Softgel-Herstellung',
        'stick'=>'Stick-Abfüllung', 'pulver'=>'Pulver-Abfüllung', 'fluessig'=>'Abfüllung',
        'gummi'=>'Gummi-Herstellung (Gießen)', 'gel'=>'Gel-Abfüllung', default=>'Herstellung',
    };
}
// Aktuelle Weg-Schalter (abgeleitet aus den Schritten) + Grundweg + ob noch änderbar.
function erp_weg_lesen(int $pa_id): array {
    $stationen = array_map(fn($s) => (string)$s['station'], erp_pa_schritte($pa_id));
    return [
        'basis'      => erp_weg_basis($pa_id),
        'abfuellen'  => in_array('Verpacken', $stationen, true),
        'etikettieren'=> in_array('Etikettieren', $stationen, true),
        'beipack'    => in_array('Beipackzettel beilegen', $stationen, true),
        'karton'     => in_array('Umkarton', $stationen, true),
        'aenderbar'  => (int) scalar("SELECT COUNT(*) FROM produktion_schritt WHERE pa_id=? AND erledigt=1", [$pa_id]) === 0,
    ];
}
// Stationsfolge aus Grundweg + Schaltern bauen.
function erp_weg_stationen(int $pa_id, array $f): array {
    $basis = erp_weg_basis($pa_id);
    if ($basis === 'bulk') return ['Rohstoffe bereitstellen', 'Mischen', erp_weg_herstellung($pa_id), 'Qualitätsprüfung', 'Einlagern (Bulk)'];
    // Eigenproduktion mit Werks-Ablauf (Mischen, Herstellung, Zwischenkontrolle, …, Muster ziehen).
    $steps = $basis === 'zukauf'
        ? ['Fertigware bereitstellen']
        : ['Rohstoffe bereitstellen', 'Mischen', erp_weg_herstellung($pa_id), 'Zwischenkontrolle'];
    if (!empty($f['abfuellen']))    $steps[] = 'Verpacken';
    if (!empty($f['etikettieren'])) $steps[] = 'Etikettieren';
    if (!empty($f['beipack']))      $steps[] = 'Beipackzettel beilegen';
    if (!empty($f['karton']))       $steps[] = 'Umkarton';
    if ($basis !== 'zukauf')        $steps[] = 'Rückstellmuster ziehen';
    return array_merge($steps, ['Qualitätsprüfung', 'Produktions-Freigabe', 'Versand-Freigabe']);
}
// Weg anwenden: Schritte neu erzeugen (nur solange KEIN Schritt erledigt ist). Rückgabe ['ok','msg'].
function erp_weg_anwenden(int $pa_id, array $f): array {
    if ($pa_id <= 0 || !tabelle_da('produktion_schritt')) return ['ok'=>false, 'msg'=>'Auftrag nicht gefunden.'];
    if ((int) scalar("SELECT COUNT(*) FROM produktion_schritt WHERE pa_id=? AND erledigt=1", [$pa_id]) > 0)
        return ['ok'=>false, 'msg'=>'Der Weg lässt sich nicht mehr ändern – es wurde bereits ein Schritt erledigt.'];
    if (erp_weg_basis($pa_id) === 'bulk') return ['ok'=>false, 'msg'=>'Bulk-Produktion hat einen festen Weg (ohne Abfüllen/Verpacken).'];
    $stationen = erp_weg_stationen($pa_id, $f);
    q("DELETE FROM produktion_schritt WHERE pa_id=?", [$pa_id]);
    foreach ($stationen as $i => $station)
        q("INSERT INTO produktion_schritt (pa_id,station,sort,erledigt) VALUES (?,?,?,0)", [$pa_id, $station, $i]);
    q("UPDATE produktionsauftrag SET status='offen' WHERE id=?", [$pa_id]);
    return ['ok'=>true, 'msg'=>'Produktionsweg gespeichert.'];
}

// Anzahl Produktionsaufträge je Status (für Dashboard-Kennzahlen).
function erp_pa_count(string $status): int {
    if (!tabelle_da('produktionsauftrag')) return 0;
    if ($status === 'alle') return (int) scalar("SELECT COUNT(*) FROM produktionsauftrag");
    return (int) scalar("SELECT COUNT(*) FROM produktionsauftrag WHERE status=?", [$status]);
}

// Ø Produktionszeit = Dauer vom ersten bis zum letzten erledigten Schritt, gemittelt über abgeschlossene Aufträge.
// Rückgabe ['sekunden'=>?float, 'n'=>int Aufträge in der Messung].
function erp_produktionszeit_schnitt(): array {
    if (!tabelle_da('produktion_schritt')) return ['sekunden'=>null, 'n'=>0];
    $r = one("SELECT AVG(dur) AS avg_s, COUNT(*) AS n FROM (
                SELECT TIMESTAMPDIFF(SECOND, MIN(s.erledigt_at), MAX(s.erledigt_at)) AS dur
                FROM produktion_schritt s JOIN produktionsauftrag pa ON pa.id=s.pa_id
                WHERE pa.status='erledigt' AND s.erledigt=1 AND s.erledigt_at IS NOT NULL
                GROUP BY s.pa_id HAVING COUNT(*) >= 2 AND MAX(s.erledigt_at) > MIN(s.erledigt_at)
              ) t");
    return ['sekunden'=> ($r && $r['avg_s'] !== null) ? (float)$r['avg_s'] : null, 'n'=> $r ? (int)$r['n'] : 0];
}
// Ø Durchlaufzeit = vom Auftragseingang (auftrag.angelegt) bis zum letzten erledigten Schritt.
function erp_durchlaufzeit_schnitt(): array {
    if (!tabelle_da('produktionsauftrag') || !tabelle_da('auftrag')) return ['sekunden'=>null, 'n'=>0];
    $r = one("SELECT AVG(dur) AS avg_s, COUNT(*) AS n FROM (
                SELECT TIMESTAMPDIFF(SECOND, a.angelegt, MAX(s.erledigt_at)) AS dur
                FROM produktionsauftrag pa
                JOIN auftrag a ON a.id=pa.auftrag_id
                JOIN produktion_schritt s ON s.pa_id=pa.id AND s.erledigt=1 AND s.erledigt_at IS NOT NULL
                WHERE pa.status='erledigt'
                GROUP BY pa.id, a.angelegt
              ) t WHERE t.dur >= 0");
    return ['sekunden'=> ($r && $r['avg_s'] !== null) ? (float)$r['avg_s'] : null, 'n'=> $r ? (int)$r['n'] : 0];
}

// Was muss für den aktuellen Schritt konkret aus dem Lager geholt werden? Material + Menge + Bestand.
// Rückgabe ['soll_menge'=>?float,'soll_einheit'=>?string,'zeilen'=>[['name','detail','menge','einheit','verfuegbar','item_id','charge_id'?], …]].
// Leer für Stationen ohne Materialbezug (Mischen, Etikettieren, Freigaben …).
function erp_schritt_material(int $pa_id, string $station): array {
    $leer = ['soll_menge'=>null, 'soll_einheit'=>null, 'zeilen'=>[]];
    $pa = one("SELECT menge, produkt_id, auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return $leer;
    $zeilen = []; $soll_menge = null; $soll_einheit = null;
    switch ($station) {
        case 'Rohstoffe bereitstellen':
            foreach (erp_materialbedarf($pa_id) as $b)
                $zeilen[] = ['name'=>$b['name'], 'detail'=>'', 'menge'=>$b['benoetigt'], 'einheit'=>$b['einheit'],
                             'verfuegbar'=>$b['verfuegbar'], 'item_id'=>(int)$b['item_id']];
            break;
        case 'Verkapselung':
            $kid = erp_produkt_leerkapsel_id((int)$pa['produkt_id']);
            if ($kid) {
                $need = (float)$pa['menge'] * erp_stueck_je_packung($pa);
                $zeilen[] = ['name'=> (string) scalar("SELECT name FROM item WHERE id=?", [$kid]), 'detail'=>'Leerkapseln',
                             'menge'=>$need, 'einheit'=>'Stück', 'verfuegbar'=>erp_item_bestand($kid), 'item_id'=>$kid];
            }
            break;
        case 'Fertigware bereitstellen':
            $soll_menge = (float)$pa['menge'] * erp_stueck_je_packung($pa);
            $soll_einheit = 'Stück';
            $chargen = erp_fertigware_chargen($pa_id, 'frei');   // Auftrag ODER Rezeptur-Bulk
            foreach ($chargen as $c)
                $zeilen[] = ['name'=>$c['name'], 'detail'=>'Charge ' . $c['charge_nr'], 'menge'=>(float)$c['menge_verfuegbar'],
                             'einheit'=>'Stück', 'verfuegbar'=>(float)$c['menge_verfuegbar'], 'item_id'=>(int)$c['item_id'], 'charge_id'=>(int)$c['id']];
            // Noch keine Fertigware im Lager: trotzdem klar ansagen, WAS und WIE VIEL bereitzustellen ist.
            if (!$chargen) {
                $pname = (string) scalar("SELECT COALESCE(NULLIF(p.kundenname,''), p.name, a.produkt_bezeichnung, r.name)
                                          FROM produktionsauftrag pa
                                          LEFT JOIN produkt p  ON p.id=pa.produkt_id
                                          LEFT JOIN auftrag a  ON a.id=pa.auftrag_id
                                          LEFT JOIN rezeptur r ON r.id=COALESCE(pa.rezeptur_id, p.rezeptur_id)
                                          WHERE pa.id=?", [$pa_id]);
                $zeilen[] = ['name'=>($pname !== '' ? $pname : 'Fertigware') . ' (zugekaufte Fertigware)', 'detail'=>'noch nicht im Lager gebucht',
                             'menge'=>$soll_menge, 'einheit'=>'Stück', 'verfuegbar'=>0.0, 'item_id'=>0];
            }
            break;
        case 'Verpacken':
            // Primärgebinde wird beim Abschließen abgebucht (pflicht). Deckel nur zur Info (kein Entnahme-Zwang).
            $vid = (int) (scalar("SELECT verpackung_id FROM produkt WHERE id=?", [(int)$pa['produkt_id']]) ?: 0);
            if ($vid)
                $zeilen[] = ['name'=> (string) scalar("SELECT name FROM item WHERE id=?", [$vid]), 'detail'=>'Verpackung',
                             'menge'=>(float)$pa['menge'], 'einheit'=>'Stück', 'verfuegbar'=>erp_item_bestand($vid), 'item_id'=>$vid];
            $did = (int) (scalar("SELECT verschluss_id FROM produkt WHERE id=?", [(int)$pa['produkt_id']]) ?: 0);
            if ($did)
                $zeilen[] = ['name'=> (string) scalar("SELECT name FROM item WHERE id=?", [$did]), 'detail'=>'Deckel',
                             'menge'=>(float)$pa['menge'], 'einheit'=>'Stück', 'verfuegbar'=>erp_item_bestand($did), 'item_id'=>$did, 'pflicht'=>false];
            break;
        case 'Etikettieren':
            $eid = (int) (scalar("SELECT etikett_id FROM produkt WHERE id=?", [(int)$pa['produkt_id']]) ?: 0);
            if ($eid)
                $zeilen[] = ['name'=> (string) scalar("SELECT name FROM item WHERE id=?", [$eid]), 'detail'=>'Etikett',
                             'menge'=>(float)$pa['menge'], 'einheit'=>'Stück', 'verfuegbar'=>erp_item_bestand($eid), 'item_id'=>$eid, 'pflicht'=>false];
            break;
    }
    // Je Zeile die FEFO-Charge (für den Blinker), Quarantäne-Menge (Hinweis) und ob schon entnommen.
    foreach ($zeilen as &$z)
        if (!empty($z['item_id'])) {
            if (!isset($z['charge_id'])) $z['charge_id'] = erp_fefo_charge_id((int)$z['item_id']);
            $z['quarantaene'] = erp_item_quarantaene((int)$z['item_id']);
            $z['entnommen'] = (int) scalar("SELECT COUNT(*) FROM produktion_verbrauch WHERE pa_id=? AND item_id=?", [$pa_id, (int)$z['item_id']]) > 0;
        }
    unset($z);
    return ['soll_menge'=>$soll_menge, 'soll_einheit'=>$soll_einheit, 'zeilen'=>$zeilen];
}

// Charge-ID zu einer Chargennummer (für den Blinker-Test). Jüngste bei Dubletten.
function erp_charge_id_per_nr(string $nr): ?int {
    $nr = trim($nr);
    if ($nr === '' || !tabelle_da('charge')) return null;
    $id = scalar("SELECT id FROM charge WHERE charge_nr=? ORDER BY id DESC LIMIT 1", [$nr]);
    return $id ? (int)$id : null;
}

// Älteste frei verfügbare Charge eines Artikels (FEFO) – die, die als Nächstes entnommen würde.
function erp_fefo_charge_id(int $item_id): ?int {
    if ($item_id <= 0) return null;
    $c = one("SELECT id FROM charge WHERE item_id=? AND status='frei' AND menge_verfuegbar>0 AND fremd_kunde_id IS NULL
              ORDER BY (mhd IS NULL), mhd ASC, id ASC LIMIT 1", [$item_id]);
    return $c ? (int)$c['id'] : null;
}

// Pick-to-Light: den Blinker der angegebenen (Dashboard-)Charge im LAGER-Programm leuchten lassen.
// Die Blinker-Hardware/-Logik gehört dem Lager; wir rufen nur dessen internen Endpunkt auf
// (/lager/?p=api_blink), serverseitig, auth per Loopback oder gemeinsamem Token LG_BLINK_TOKEN.
// Wir fassen KEINE lg_-Tabellen an. Rückgabe ['ok'=>bool,'meldung'=>string].
function pr_lager_blink(int $charge_id, string $aktion = 'an'): array {
    if ($charge_id <= 0) return ['ok'=>false, 'meldung'=>'Keine Charge angegeben.'];
    return pr_lager_blink_call('p=api_blink&charge_id=' . $charge_id . '&aktion=' . rawurlencode($aktion));
}
// Direkt einen Blinker per Code leuchten lassen (Hardware-Test, auch Barcode mit „XD").
function pr_lager_blink_leiste(string $code, string $aktion = 'an'): array {
    $code = trim($code);
    if ($code === '') return ['ok'=>false, 'meldung'=>'Kein Blinker-Code.'];
    return pr_lager_blink_call('p=api_blink&leiste=' . rawurlencode($code) . '&aktion=' . rawurlencode($aktion));
}
// Gemeinsamer Aufruf des Lager-Blink-Endpunkts auf DERSELBEN Maschine.
// Problem auf dem Server: der eigene öffentliche HTTPS-Name lässt sich oft nicht aufrufen
// (Hairpin/TLS-Alert). Deshalb lenken wir den Aufruf per CURLOPT_RESOLVE fest auf 127.0.0.1
// (richtiger Host-Header/SNI, Verbindung aber lokal → echtes Loopback, Auth ohne Token).
// Fallback: direkter Aufruf über den Host (mit Token, falls gesetzt).
function pr_lager_blink_call(string $qs): array {
    $hatToken = defined('LG_BLINK_TOKEN') && LG_BLINK_TOKEN !== '';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
    $hostName = parse_url('//' . $host, PHP_URL_HOST) ?: $host;
    $port = parse_url('//' . $host, PHP_URL_PORT);
    $url = $scheme . '://' . $host . '/lager/?' . $qs;

    $versuche = [];
    // 1) Loopback erzwingen: Host/SNI bleiben echt, Verbindung geht auf 127.0.0.1 (kein Token nötig).
    $resolve = $port ? [$hostName . ':' . $port . ':127.0.0.1'] : [$hostName . ':443:127.0.0.1', $hostName . ':80:127.0.0.1'];
    $versuche[] = ['url'=>$url, 'resolve'=>$resolve, 'verify'=>false];
    // 2) Direkt über den Host (Hairpin), mit Token falls vorhanden.
    $versuche[] = ['url'=>$url . ($hatToken ? '&token=' . rawurlencode((string)LG_BLINK_TOKEN) : ''), 'resolve'=>null, 'verify'=>true];

    $letzte = 'Lager nicht erreichbar.';
    foreach ($versuche as $v) {
        $c = curl_init($v['url']);
        $opt = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>6, CURLOPT_PROXY=>'',
                CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>2];
        if ($v['resolve']) $opt[CURLOPT_RESOLVE] = $v['resolve'];
        if (!$v['verify']) { $opt[CURLOPT_SSL_VERIFYPEER] = false; $opt[CURLOPT_SSL_VERIFYHOST] = 0; }
        curl_setopt_array($c, $opt);
        $body = curl_exec($c);
        $err  = $body === false ? curl_error($c) : '';
        $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        if ($err !== '') { $letzte = 'Lager nicht erreichbar: ' . $err; continue; }
        if ($code === 403) { $letzte = 'Lager verweigert (Token nötig). In secrets.php LG_BLINK_TOKEN setzen.'; continue; }
        $j = json_decode((string)$body, true);
        if (!is_array($j)) { $letzte = 'Unerwartete Antwort vom Lager (HTTP ' . $code . ').'; continue; }
        return ['ok'=>!empty($j['ok']), 'meldung'=>(string)($j['meldung'] ?? '')];
    }
    return ['ok'=>false, 'meldung'=>$letzte];
}

// Zutaten der Rezeptur eines Auftrags (Zusammensetzung je Einheit). Rezeptur = pa.rezeptur_id oder produkt.rezeptur_id.
function erp_pa_zutaten(int $pa_id): array {
    $pa = one("SELECT produkt_id, rezeptur_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return [];
    $rid = (int)($pa['rezeptur_id'] ?: 0);
    if (!$rid && !empty($pa['produkt_id'])) $rid = (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [(int)$pa['produkt_id']]);
    if (!$rid) return [];
    return all("SELECT z.menge_mg, COALESCE(NULLIF(z.bezeichnung,''), i.name) AS name
                FROM rezeptur_zutat z LEFT JOIN item i ON i.id=z.item_id
                WHERE z.rezeptur_id=? ORDER BY z.sort, z.id", [$rid]);
}

// Chargennummer + MHD eines Auftrags: schon gebucht (aus charge) oder geplant (.A + heute+18 M).
function erp_pa_charge_info(int $pa_id): array {
    $c = one("SELECT charge_nr, mhd FROM charge WHERE pa_id=? ORDER BY id LIMIT 1", [$pa_id]);
    if ($c) return ['nr'=>(string)$c['charge_nr'], 'mhd'=>(string)($c['mhd'] ?? ''), 'gebucht'=>true,
                    'anzahl'=>(int) scalar("SELECT COUNT(*) FROM charge WHERE pa_id=?", [$pa_id])];
    return ['nr'=>erp_charge_naechste_nr($pa_id), 'mhd'=>erp_mhd_standard(), 'gebucht'=>false, 'anzahl'=>0];
}

// Produktionsbereitschaft: ist das Material komplett da? (Mangel vor Produktionsstart sichtbar machen.)
// Rückgabe ['status'=>'fertig'|'laeuft'|'bereit'|'wartet', 'fehlend'=>[...]]. Mirror von produktion_bereitschaft().
function erp_pa_bereitschaft(int $pa_id, ?string $status = null, ?int $schritte_fertig = null): array {
    if ($status === null) $status = (string) scalar("SELECT status FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if ($status === 'erledigt') return ['status'=>'fertig', 'fehlend'=>[]];
    if ($schritte_fertig === null) $schritte_fertig = (int) scalar("SELECT COUNT(*) FROM produktion_schritt WHERE pa_id=? AND erledigt=1", [$pa_id]);
    if ($schritte_fertig > 0) return ['status'=>'laeuft', 'fehlend'=>[]];
    $fehlend = erp_pa_fehlbedarf($pa_id);
    return ['status'=>$fehlend ? 'wartet' : 'bereit', 'fehlend'=>$fehlend];
}

// Fehlender Bestand für den Produktionsstart.
// Zukauf (fertige Bulkware): es zählt die zugekaufte Fertigware (frei) des Auftrags, NICHT die Rohstoffe.
// Eigen/Bulk: Rohstoffe + ggf. Leerkapseln + ggf. Verpackung.
function erp_pa_fehlbedarf(int $pa_id): array {
    $fehlend = [];
    $pa = one("SELECT menge, produkt_id, auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return $fehlend;
    // Zukauf erkennen: formaler Zukauf-Weg ODER es liegt schon zugekaufte fertige Bulkware am Auftrag
    // (frei oder Quarantäne) – dann zählt die Fertigware, nicht die Rohstoffe.
    $hatFertigware = count(erp_fertigware_chargen($pa_id, 'frei')) > 0 || count(erp_fertigware_chargen($pa_id, 'quarantaene')) > 0;
    if (erp_weg_basis($pa_id) === 'zukauf' || $hatFertigware) {
        $benoetigt = (float)$pa['menge'] * erp_stueck_je_packung($pa);
        if ($benoetigt <= 0) return $fehlend;
        $verf = array_sum(array_map(fn($c)=> (float)$c['menge_verfuegbar'], erp_fertigware_chargen($pa_id, 'frei')));
        if ($verf + 0.0001 < $benoetigt) {
            $quar = array_sum(array_map(fn($c)=> (float)$c['menge_verfuegbar'], erp_fertigware_chargen($pa_id, 'quarantaene')));
            $fehlend[] = ['name'=>'Fertige Bulkware', 'benoetigt'=>$benoetigt, 'verfuegbar'=>$verf, 'fehlt'=>$benoetigt - $verf, 'einheit'=>'Stück', 'quarantaene'=>$quar];
        }
        return $fehlend;
    }
    foreach (erp_materialbedarf($pa_id) as $b)
        if ((float)$b['fehlt'] > 0.0001)
            $fehlend[] = ['name'=>$b['name'], 'benoetigt'=>$b['benoetigt'], 'verfuegbar'=>$b['verfuegbar'], 'fehlt'=>$b['fehlt'], 'einheit'=>$b['einheit']];
    $pa = one("SELECT menge, produkt_id, auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return $fehlend;
    // Leerkapseln (nur Kapselprodukte, eindeutig bestimmbar)
    $kid = erp_produkt_leerkapsel_id((int)$pa['produkt_id']);
    if ($kid) {
        $need = (float)$pa['menge'] * erp_stueck_je_packung($pa);
        $verf = erp_item_bestand($kid);
        if ($need > 0 && $verf + 0.0001 < $need)
            $fehlend[] = ['name'=> scalar("SELECT name FROM item WHERE id=?", [$kid]), 'benoetigt'=>$need, 'verfuegbar'=>$verf, 'fehlt'=>$need-$verf, 'einheit'=>'Stück'];
    }
    // Verpackung (1 je Packung)
    $vid = (int) (scalar("SELECT verpackung_id FROM produkt WHERE id=?", [(int)$pa['produkt_id']]) ?: 0);
    if ($vid) {
        $need = (float)$pa['menge'];
        $verf = erp_item_bestand($vid);
        if ($verf + 0.0001 < $need)
            $fehlend[] = ['name'=> scalar("SELECT name FROM item WHERE id=?", [$vid]), 'benoetigt'=>$need, 'verfuegbar'=>$verf, 'fehlt'=>$need-$verf, 'einheit'=>'Stück'];
    }
    return $fehlend;
}

// =============================================================================================
// SCHREIBEN INS DASHBOARD – Produktionsschritt abschließen (FEFO-Entnahme + Mangel-Guard).
//
// BEWUSSTE DOPPELUNG: Diese Lager-/Chargen-Logik spiegelt das Dashboard
// (core/schema.php: produktion_schritt_erledigen() und Helfer). Beide Programme schreiben in
// DIESELBEN Tabellen (charge, produktion_verbrauch, produktion_schritt, produktionsauftrag,
// reservierung, aktivitaet, nummernkreis, app_meta, item). Ändert sich im Dashboard eine Regel
// (FEFO-Reihenfolge, Mangel-Schwelle 0.0001, Chargennummer .A/.B, MHD +18M, Fertigware-Einbuchung),
// MUSS sie hier mitgezogen werden. Die Naht bleibt: Dashboard-Tabellen nur in dieser Datei.
// Einzige bewusste Abweichung: erp_produkt_leerkapsel_id() nutzt nur die gepflegte Kapselgröße
// (rezeptur.kapselgroesse_id), nicht die gewichtsbasierte Auto-Berechnung des Dashboards – siehe erp.md.
// =============================================================================================

// Standort je Schritt: braucht die Station eine Material-Entnahme? (Scan-Prüfung bleibt vorerst
// außen vor – es gibt noch keine Etiketten zum Scannen; FEFO-Abbuchung läuft trotzdem.)
function erp_station_entnahme(int $pa_id, string $station): array {
    return match ($station) {
        'Rohstoffe bereitstellen'  => erp_rohstoffe_entnehmen($pa_id),
        'Verkapselung'             => erp_kapseln_entnehmen($pa_id),
        'Fertigware bereitstellen' => erp_fertigware_entnehmen($pa_id),
        'Verpacken'                => erp_verpackung_entnehmen($pa_id),
        default                    => ['ok'=>true, 'fehlt'=>[]],
    };
}

// Einen Produktionsschritt abschließen. Erzwingt die Reihenfolge (nur der erste offene Schritt),
// bucht Material nach FEFO ab (Mangel-Guard), markiert erledigt, aktualisiert den Auftragsstatus;
// beim letzten Schritt wird die Fertigware als Charge eingebucht und der Auftrag auf 'erledigt' gesetzt.
// Rückgabe: ['ok'=>bool,'fehler'=>?('nicht_gefunden'|'reihenfolge'|'mangel'),'msg'=>string,'fertig'=>bool,'station'=>string,'fehlt'=>array].
function erp_schritt_abschliessen(int $schritt_id, string $akteur): array {
    if ($schritt_id <= 0 || !tabelle_da('produktion_schritt'))
        return ['ok'=>false, 'fehler'=>'nicht_gefunden', 'msg'=>'Schritt nicht gefunden.', 'fertig'=>false, 'station'=>'', 'fehlt'=>[]];
    $schritt = one("SELECT id, pa_id, station, erledigt FROM produktion_schritt WHERE id=?", [$schritt_id]);
    if (!$schritt)
        return ['ok'=>false, 'fehler'=>'nicht_gefunden', 'msg'=>'Schritt nicht gefunden.', 'fertig'=>false, 'station'=>'', 'fehlt'=>[]];
    $pa_id = (int)$schritt['pa_id'];
    $station = (string)$schritt['station'];

    // Reihenfolge: nur der erste noch offene Schritt des Auftrags darf abgeschlossen werden.
    $firstOpen = one("SELECT id FROM produktion_schritt WHERE pa_id=? AND erledigt=0 ORDER BY sort, id LIMIT 1", [$pa_id]);
    if (!$firstOpen || (int)$firstOpen['id'] !== $schritt_id)
        return ['ok'=>false, 'fehler'=>'reihenfolge', 'msg'=>'Dieser Schritt ist gerade nicht an der Reihe.', 'fertig'=>false, 'station'=>$station, 'fehlt'=>[]];

    // Teilmengen-Schutz: Wenn schon Teilmengen gebucht wurden, darf der ABSCHLIESSENDE Schritt erst
    // erledigt werden, wenn die volle Menge produziert ist – sonst würde Restmenge ohne echte Produktion
    // als Fertigware eingebucht.
    $totalCnt = (int) scalar("SELECT COUNT(*) FROM produktion_schritt WHERE pa_id=?", [$pa_id]);
    $doneCnt  = (int) scalar("SELECT COUNT(*) FROM produktion_schritt WHERE pa_id=? AND erledigt=1", [$pa_id]);
    if ($doneCnt + 1 >= $totalCnt && erp_produktion_gebucht($pa_id) > 0 && erp_produktion_rest($pa_id) > 0.0001) {
        $geb = number_format(erp_produktion_gebucht($pa_id), 0, ',', '.');
        $ges = (int) scalar("SELECT menge FROM produktionsauftrag WHERE id=?", [$pa_id]);
        return ['ok'=>false, 'fehler'=>'teilmenge', 'fertig'=>false, 'station'=>$station, 'fehlt'=>[],
                'msg'=>'Erst ' . $geb . ' von ' . $ges . ' produziert. Bitte zuerst die Restmenge produzieren, bevor der Auftrag abgeschlossen wird.'];
    }

    // Material nach FEFO abbuchen (idempotent je Auftrag/Item). Reicht der Bestand nicht: abbrechen.
    $entnahme = erp_station_entnahme($pa_id, $station);
    if (!($entnahme['ok'] ?? true)) {
        $fehlt = $entnahme['fehlt'] ?? [];
        $teile = [];
        foreach ($fehlt as $f) {
            $fehlbetrag = rtrim(rtrim(number_format((float)($f['fehlt'] ?? 0), 3, ',', '.'), '0'), ',');
            $teile[] = (string)($f['name'] ?? 'Material') . ' (fehlt ' . $fehlbetrag . ' ' . (string)($f['einheit'] ?? '') . ')';
        }
        $msg = 'Nicht genug Bestand für diesen Schritt' . ($teile ? ': ' . implode(', ', $teile) . '.' : '.');
        return ['ok'=>false, 'fehler'=>'mangel', 'msg'=>$msg, 'fertig'=>false, 'station'=>$station, 'fehlt'=>$fehlt];
    }

    // Schritt erledigt markieren.
    q("UPDATE produktion_schritt SET erledigt=1, erledigt_at=?, erledigt_von=? WHERE id=?",
      [gmdate('Y-m-d H:i:s'), $akteur !== '' ? $akteur : null, $schritt_id]);
    erp_reservierung_abgleichen($pa_id);   // entnommene Items: Reservierung schließen

    // Auftragsstatus neu bestimmen (gleiche Werte wie das Dashboard: offen/laufend/erledigt).
    $total = (int) scalar("SELECT COUNT(*) FROM produktion_schritt WHERE pa_id=?", [$pa_id]);
    $done  = (int) scalar("SELECT COUNT(*) FROM produktion_schritt WHERE pa_id=? AND erledigt=1", [$pa_id]);
    $status = $done === 0 ? 'offen' : ($done >= $total ? 'erledigt' : 'laufend');
    q("UPDATE produktionsauftrag SET status=? WHERE id=?", [$status, $pa_id]);

    $fertig = ($status === 'erledigt');
    if ($fertig) {
        erp_auftrag_reservierung_freigeben($pa_id);     // Rest-Reservierungen freigeben
        erp_fertigware_einbuchen($pa_id);               // Fertigware als Charge einbuchen
        $pa = one("SELECT auftrag_id, kunde_id, nummer FROM produktionsauftrag WHERE id=?", [$pa_id]);
        if ($pa && $pa['auftrag_id'] && tabelle_da('auftrag')) {
            q("UPDATE auftrag SET status='erledigt' WHERE id=?", [(int)$pa['auftrag_id']]);
            if ($pa['kunde_id'])
                erp_log_aktivitaet('kunde', (int)$pa['kunde_id'], 'team',
                    'Produktion ' . $pa['nummer'] . ' abgeschlossen, Fertigware eingebucht, versandfrei.',
                    'auftrag', 'auftrag', (int)$pa['auftrag_id']);
        }
    }
    return ['ok'=>true, 'fehler'=>null, 'msg'=>'', 'fertig'=>$fertig, 'station'=>$station, 'fehlt'=>[]];
}

// Admin-Override: einen Schritt direkt auf erledigt/offen setzen – auch außer der Reihe.
// REINE Statuskorrektur: KEINE FEFO-Entnahme, KEINE Fertigware-Einbuchung (dafür ist das normale
// Abschließen da). Aktualisiert nur erledigt-Flag/Bediener/Zeit und den Auftragsstatus.
function erp_schritt_status_setzen(int $schritt_id, bool $erledigt, string $akteur): array {
    if ($schritt_id <= 0 || !tabelle_da('produktion_schritt')) return ['ok'=>false, 'msg'=>'Schritt nicht gefunden.'];
    $s = one("SELECT pa_id FROM produktion_schritt WHERE id=?", [$schritt_id]);
    if (!$s) return ['ok'=>false, 'msg'=>'Schritt nicht gefunden.'];
    $pa_id = (int)$s['pa_id'];
    if ($erledigt)
        q("UPDATE produktion_schritt SET erledigt=1, erledigt_at=?, erledigt_von=? WHERE id=?",
          [gmdate('Y-m-d H:i:s'), $akteur !== '' ? $akteur : null, $schritt_id]);
    else
        q("UPDATE produktion_schritt SET erledigt=0, erledigt_at=NULL, erledigt_von=NULL WHERE id=?", [$schritt_id]);
    $total = (int) scalar("SELECT COUNT(*) FROM produktion_schritt WHERE pa_id=?", [$pa_id]);
    $done  = (int) scalar("SELECT COUNT(*) FROM produktion_schritt WHERE pa_id=? AND erledigt=1", [$pa_id]);
    $status = $done === 0 ? 'offen' : ($done >= $total ? 'erledigt' : 'laufend');
    q("UPDATE produktionsauftrag SET status=? WHERE id=?", [$status, $pa_id]);
    return ['ok'=>true, 'fertig'=>($status === 'erledigt')];
}

// --- Bestand / Bedarf (gespiegelt aus dem Dashboard) -----------------------------------------
// Freier eigener Bestand eines Artikels (Fremdlager-Chargen gehören dem Kunden -> zählen nicht).
function erp_item_bestand(int $item_id): float {
    return (float) scalar("SELECT COALESCE(SUM(menge_verfuegbar),0) FROM charge
                           WHERE item_id=? AND status='frei' AND fremd_kunde_id IS NULL", [$item_id]);
}
// Menge in Quarantäne (eingebucht, aber noch nicht freigegeben) – zählt NICHT als verfügbar.
function erp_item_quarantaene(int $item_id): float {
    if ($item_id <= 0) return 0.0;
    return (float) scalar("SELECT COALESCE(SUM(menge_verfuegbar),0) FROM charge
                           WHERE item_id=? AND status='quarantaene' AND fremd_kunde_id IS NULL", [$item_id]);
}
// Stück/Kapseln je Packung: Produkt (einheiten_pro_packung), sonst Auftrag (stueck), sonst 0.
function erp_stueck_je_packung(array $pa): int {
    if (!empty($pa['produkt_id'])) { $e = (int) scalar("SELECT einheiten_pro_packung FROM produkt WHERE id=?", [(int)$pa['produkt_id']]); if ($e > 0) return $e; }
    if (!empty($pa['auftrag_id'])) { $s = (int) scalar("SELECT stueck FROM auftrag WHERE id=?", [(int)$pa['auftrag_id']]); if ($s > 0) return $s; }
    return 0;
}
// Bulk-Auftrag = ohne Produkt, aber mit Rezeptur.
function erp_pa_ist_bulk(array $pa): bool { return empty($pa['produkt_id']) && !empty($pa['rezeptur_id']); }

// Materialbedarf (Rohstoffe) eines Auftrags: je Zutat benötigte vs. verfügbare Menge.
function erp_materialbedarf(int $pa_id): array {
    $pa = one("SELECT menge, produkt_id, rezeptur_id, auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return [];
    if (erp_pa_ist_bulk($pa)) {
        $rid = (int)$pa['rezeptur_id'];
        $einheiten_total = (int)$pa['menge'];
    } else {
        $rid = (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [(int)$pa['produkt_id']]);
        if (!$rid) return [];
        $einheiten_total = (int)$pa['menge'] * erp_stueck_je_packung($pa);
    }
    $out = [];
    foreach (all("SELECT z.item_id, z.menge_mg, i.name, i.einheit
                  FROM rezeptur_zutat z JOIN item i ON i.id=z.item_id WHERE z.rezeptur_id=?", [$rid]) as $z) {
        $mg = (float)$z['menge_mg'] * $einheiten_total;
        $faktor = $z['einheit'] === 'g' ? 1e3 : 1e6;          // mg -> Basiseinheit (kg-Standard)
        $benoetigt = $mg / $faktor;
        $verf = erp_item_bestand((int)$z['item_id']);
        $out[] = ['item_id'=>(int)$z['item_id'], 'name'=>$z['name'], 'einheit'=>$z['einheit'], 'menge_mg'=>(float)$z['menge_mg'],
                  'benoetigt'=>$benoetigt, 'verfuegbar'=>$verf, 'quarantaene'=>erp_item_quarantaene((int)$z['item_id']), 'fehlt'=>max(0.0, $benoetigt - $verf)];
    }
    return $out;
}

// --- FEFO-Entnahme je Station (idempotent; blockiert bei zu wenig Bestand) -------------------
// Eine Menge eines Artikels nach FEFO aus freien eigenen Chargen abbuchen und als Verbrauch buchen.
function erp_fefo_abbuchen(int $pa_id, int $item_id, float $menge, string $einheit): void {
    $rest = $menge;
    foreach (all("SELECT id, menge_verfuegbar FROM charge
                  WHERE item_id=? AND status='frei' AND menge_verfuegbar>0 AND fremd_kunde_id IS NULL
                  ORDER BY (mhd IS NULL), mhd ASC, id ASC", [$item_id]) as $c) {
        if ($rest <= 0.0001) break;
        $nimm = min($rest, (float)$c['menge_verfuegbar']);
        $neu  = (float)$c['menge_verfuegbar'] - $nimm;
        q("UPDATE charge SET menge_verfuegbar=?, status=? WHERE id=?", [$neu, $neu <= 0.0001 ? 'leer' : 'frei', $c['id']]);
        q("INSERT INTO produktion_verbrauch (pa_id,item_id,charge_id,menge,einheit,angelegt) VALUES (?,?,?,?,?,?)",
          [$pa_id, $item_id, $c['id'], $nimm, $einheit, gmdate('Y-m-d H:i:s')]);
        $rest -= $nimm;
    }
}
function erp_rohstoffe_entnehmen(int $pa_id): array {
    if ((int) scalar("SELECT COUNT(*) FROM produktion_verbrauch WHERE pa_id=?", [$pa_id]) > 0) return ['ok'=>true, 'fehlt'=>[]];
    $bedarf = erp_materialbedarf($pa_id);
    $fehlt = array_values(array_filter($bedarf, fn($b) => $b['fehlt'] > 0.0001));
    if ($fehlt) return ['ok'=>false, 'fehlt'=>$fehlt];
    foreach ($bedarf as $b) erp_fefo_abbuchen($pa_id, (int)$b['item_id'], (float)$b['benoetigt'], (string)$b['einheit']);
    return ['ok'=>true, 'fehlt'=>[]];
}
function erp_kapseln_entnehmen(int $pa_id): array {
    $pa = one("SELECT menge, produkt_id, auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return ['ok'=>true, 'fehlt'=>[]];
    $kid = erp_produkt_leerkapsel_id((int)$pa['produkt_id']);
    if (!$kid) return ['ok'=>true, 'fehlt'=>[]];                       // kein/uneindeutiges Kapselprodukt -> nichts abbuchen
    if ((int) scalar("SELECT COUNT(*) FROM produktion_verbrauch WHERE pa_id=? AND item_id=?", [$pa_id, $kid]) > 0) return ['ok'=>true, 'fehlt'=>[]];
    $benoetigt = (float)$pa['menge'] * erp_stueck_je_packung($pa);
    if ($benoetigt <= 0) return ['ok'=>true, 'fehlt'=>[]];
    $verf = erp_item_bestand($kid);
    if ($verf + 0.0001 < $benoetigt)
        return ['ok'=>false, 'fehlt'=>[['name'=> scalar("SELECT name FROM item WHERE id=?", [$kid]), 'benoetigt'=>$benoetigt, 'verfuegbar'=>$verf, 'fehlt'=>$benoetigt-$verf, 'einheit'=>'Stück']]];
    erp_fefo_abbuchen($pa_id, $kid, $benoetigt, 'Stück');
    return ['ok'=>true, 'fehlt'=>[]];
}
// Rezeptur-ID eines Auftrags (pa.rezeptur_id oder produkt.rezeptur_id).
function erp_pa_rezeptur_id(int $pa_id): int {
    $pa = one("SELECT produkt_id, rezeptur_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return 0;
    $rid = (int)($pa['rezeptur_id'] ?: 0);
    if (!$rid && !empty($pa['produkt_id'])) $rid = (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [(int)$pa['produkt_id']]);
    return $rid;
}
// Zugekaufte fertige Bulkware eines Auftrags: entweder direkt AM AUFTRAG gebucht oder als Bulk der REZEPTUR
// (Kunde koppelt die Bulkware an die Rezeptur, nicht zwingend an den einzelnen Auftrag). $status: frei|quarantaene.
function erp_fertigware_chargen(int $pa_id, string $status = 'frei'): array {
    $auf = (int) scalar("SELECT auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    $rid = erp_pa_rezeptur_id($pa_id);
    if (!$auf && !$rid) return [];
    return all("SELECT c.id, c.charge_nr, c.menge_verfuegbar, c.item_id, i.name
                FROM charge c JOIN item i ON i.id=c.item_id
                WHERE i.kategorie='fertig' AND c.status=? AND c.menge_verfuegbar>0 AND c.fremd_kunde_id IS NULL
                  AND (c.auftrag_id=? OR (?>0 AND i.rezeptur_id=?))
                ORDER BY (c.mhd IS NULL), c.mhd ASC, c.id ASC", [$status, $auf, $rid, $rid]);
}

// Zugekaufte fertige Bulkware (Kategorie 'fertig') FEFO abbuchen (Auftrag ODER Rezeptur-Bulk).
function erp_fertigware_entnehmen(int $pa_id): array {
    $pa = one("SELECT menge, produkt_id, auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return ['ok'=>true, 'fehlt'=>[]];
    $benoetigt = (float)$pa['menge'] * erp_stueck_je_packung($pa);
    if ($benoetigt <= 0) return ['ok'=>true, 'fehlt'=>[]];
    $chargen = erp_fertigware_chargen($pa_id, 'frei');
    $verf = array_sum(array_map(fn($c)=> (float)$c['menge_verfuegbar'], $chargen));
    if ($verf + 0.0001 < $benoetigt)
        return ['ok'=>false, 'fehlt'=>[['name'=>'Fertige Bulkware', 'benoetigt'=>$benoetigt, 'verfuegbar'=>$verf, 'fehlt'=>$benoetigt-$verf, 'einheit'=>'Stück']]];
    $rest = $benoetigt;
    foreach ($chargen as $c) {
        if ($rest <= 0.0001) break;
        $nimm = min($rest, (float)$c['menge_verfuegbar']);
        $neu  = (float)$c['menge_verfuegbar'] - $nimm;
        q("UPDATE charge SET menge_verfuegbar=?, status=? WHERE id=?", [$neu, $neu <= 0.0001 ? 'leer' : 'frei', $c['id']]);
        q("INSERT INTO produktion_verbrauch (pa_id,item_id,charge_id,menge,einheit,angelegt) VALUES (?,?,?,?,?,?)",
          [$pa_id, (int)$c['item_id'], $c['id'], $nimm, 'Stück', gmdate('Y-m-d H:i:s')]);
        $rest -= $nimm;
    }
    return ['ok'=>true, 'fehlt'=>[]];
}
function erp_verpackung_entnehmen(int $pa_id): array {
    $pa = one("SELECT menge, produkt_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return ['ok'=>true, 'fehlt'=>[]];
    $vid = (int) (scalar("SELECT verpackung_id FROM produkt WHERE id=?", [(int)$pa['produkt_id']]) ?: 0);
    if (!$vid) return ['ok'=>true, 'fehlt'=>[]];                       // kein Gebinde definiert -> nichts zu tun
    if ((int) scalar("SELECT COUNT(*) FROM produktion_verbrauch WHERE pa_id=? AND item_id=?", [$pa_id, $vid]) > 0) return ['ok'=>true, 'fehlt'=>[]];
    $benoetigt = (float)$pa['menge'];
    $verf = erp_item_bestand($vid);
    if ($verf + 0.0001 < $benoetigt)
        return ['ok'=>false, 'fehlt'=>[['name'=> scalar("SELECT name FROM item WHERE id=?", [$vid]), 'benoetigt'=>$benoetigt, 'verfuegbar'=>$verf, 'fehlt'=>$benoetigt-$verf, 'einheit'=>'Stück']]];
    erp_fefo_abbuchen($pa_id, $vid, $benoetigt, 'Stück');
    return ['ok'=>true, 'fehlt'=>[]];
}

// Effektive Leerkapsel eines Produkts: manuelle Wahl hat Vorrang, sonst eindeutiger Treffer über
// die gepflegte Kapselgröße der Rezeptur. (Keine gewichtsbasierte Auto-Berechnung wie im Dashboard.)
function erp_produkt_leerkapsel_id(int $produkt_id): ?int {
    if ($produkt_id <= 0) return null;
    $manuell = scalar("SELECT leerkapsel_id FROM produkt WHERE id=?", [$produkt_id]);
    if ($manuell) return (int)$manuell;
    $rid = (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [$produkt_id]);
    if (!$rid) return null;
    $gid = (int) scalar("SELECT kapselgroesse_id FROM rezeptur WHERE id=? AND darreichungsform='kapsel'", [$rid]);
    if (!$gid) return null;
    $k = all("SELECT id FROM item WHERE kategorie='rohstoff' AND form='kapselhuelle' AND kapselgroesse_id=? AND gesperrt=0 ORDER BY id", [$gid]);
    return count($k) === 1 ? (int)$k[0]['id'] : null;   // nur bei Eindeutigkeit automatisch
}

// --- Reservierungen (gespiegelt) -------------------------------------------------------------
function erp_bedarf_bump(): void {
    if (!tabelle_da('app_meta')) return;
    $v = (int) scalar("SELECT v FROM app_meta WHERE k='bedarf_version'") + 1;
    q("INSERT INTO app_meta (k,v) VALUES ('bedarf_version',?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [(string)$v]);
}
function erp_reservierung_abgleichen(int $pa_id): void {
    if (!tabelle_da('reservierung')) return;
    $aid = (int) scalar("SELECT auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$aid) return;
    q("UPDATE reservierung SET status='verbraucht'
       WHERE auftrag_id=? AND status='aktiv' AND item_id IN (SELECT item_id FROM produktion_verbrauch WHERE pa_id=?)", [$aid, $pa_id]);
    erp_bedarf_bump();
}
function erp_auftrag_reservierung_freigeben(int $pa_id): void {
    if (!tabelle_da('reservierung')) return;
    q("UPDATE reservierung SET status='storniert' WHERE pa_id=? AND status='aktiv'", [$pa_id]);
    erp_bedarf_bump();
}

// --- Fertigware einbuchen (gespiegelt) -------------------------------------------------------
function erp_produktion_gebucht(int $pa_id): float {
    return (float) scalar("SELECT COALESCE(SUM(menge),0) FROM charge WHERE pa_id=?", [$pa_id]);
}
function erp_produktion_rest(int $pa_id): float {
    $menge = (float) scalar("SELECT menge FROM produktionsauftrag WHERE id=?", [$pa_id]);
    return max(0.0, $menge - erp_produktion_gebucht($pa_id));
}
// Den noch offenen Rest der Produktionsmenge als eigene Charge einbuchen (Abschluss normaler Aufträge).
function erp_fertigware_einbuchen(int $pa_id): ?int {
    return erp_teilmenge_einbuchen($pa_id, erp_produktion_rest($pa_id));
}
// Eine (Teil-)Menge Fertigware als eigene Charge (.A/.B/.C …, MHD +18M) einbuchen. Nie mehr als der offene Rest.
function erp_teilmenge_einbuchen(int $pa_id, float $menge): ?int {
    $menge = round($menge);
    if ($menge <= 0 || $menge > erp_produktion_rest($pa_id) + 1e-6) return null;
    $pa = one("SELECT nummer, produkt_id, rezeptur_id, auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return null;
    $istBulk = erp_pa_ist_bulk($pa);
    if (!$pa['produkt_id'] && !$istBulk) return null;   // ohne Produkt/Rezeptur kein Lagerartikel
    if ($istBulk) {
        $item_id = erp_rezeptur_bulkitem((int)$pa['rezeptur_id']);
    } else {
        $item_id = erp_produkt_lageritem((int)$pa['produkt_id']);
        if ($item_id && (erp_auftrag_ist_fulfillment((int)$pa['auftrag_id'])
            || (bool) scalar("SELECT k.nutzt_fulfillment FROM produkt p JOIN kunden k ON k.id=p.kunde_id WHERE p.id=?", [(int)$pa['produkt_id']])))
            erp_bsku_ensure($item_id);
    }
    if (!$item_id) return null;
    $charge_nr = erp_charge_naechste_nr($pa_id);
    q("INSERT INTO charge (charge_nr,item_id,menge,menge_verfuegbar,einheit,mhd,wareneingang,status,notiz,pa_id,angelegt)
       VALUES (?,?,?,?, 'Stück', ?, CURDATE(), 'frei', ?, ?, ?)",
      [$charge_nr, $item_id, $menge, $menge, erp_mhd_standard(), 'Aus Produktion ' . $pa['nummer'], $pa_id, gmdate('Y-m-d H:i:s')]);
    return insert_id();
}

// --- Teilmenge produzieren -------------------------------------------------------------------
// Rohstoffbedarf (+Leerkapseln) für eine Teilmenge von M Einheiten (proportional zur vollen Menge).
function erp_teilmenge_bedarf(int $pa_id, float $m): array {
    $menge = (float) scalar("SELECT menge FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if ($menge <= 0 || $m <= 0) return [];
    $faktor = $m / $menge;   // Anteil der Gesamtmenge
    $out = [];
    foreach (erp_materialbedarf($pa_id) as $b) {
        $need = (float)$b['benoetigt'] * $faktor;
        $out[] = ['item_id'=>(int)$b['item_id'], 'name'=>$b['name'], 'einheit'=>$b['einheit'],
                  'benoetigt'=>$need, 'verfuegbar'=>$b['verfuegbar'], 'fehlt'=>max(0.0, $need - (float)$b['verfuegbar'])];
    }
    // Leerkapseln (nur Kapselprodukte) anteilig
    $pa = one("SELECT produkt_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    $kid = $pa ? erp_produkt_leerkapsel_id((int)$pa['produkt_id']) : null;
    if ($kid) {
        $vpe = erp_stueck_je_packung(['produkt_id'=>(int)$pa['produkt_id'], 'auftrag_id'=>(int) scalar("SELECT auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id])]);
        $need = $m * $vpe;   // M Packungen × Kapseln je Packung (bei Bulk ist vpe über Auftrag/0 -> dann M direkt)
        if ($vpe <= 0) $need = $m;
        $verf = erp_item_bestand($kid);
        $out[] = ['item_id'=>$kid, 'name'=>(string) scalar("SELECT name FROM item WHERE id=?", [$kid]), 'einheit'=>'Stück',
                  'benoetigt'=>$need, 'verfuegbar'=>$verf, 'fehlt'=>max(0.0, $need - $verf), 'kapsel'=>true];
    }
    return $out;
}
// Eine Teilmenge produzieren: Rohstoffe (+Leerkapseln) anteilig nach FEFO verbrauchen und M als Fertigware-Charge
// einbuchen. Der Auftrag bleibt offen, bis die volle Menge produziert ist. Rückgabe ['ok','msg','fehlt','charge_nr'].
function erp_teilmenge_produzieren(int $pa_id, float $m, string $akteur): array {
    $m = round($m);
    $rest = erp_produktion_rest($pa_id);
    if ($m <= 0) return ['ok'=>false, 'msg'=>'Bitte eine Menge größer 0 angeben.', 'fehlt'=>[]];
    if ($m > $rest + 1e-6) return ['ok'=>false, 'msg'=>'Es sind nur noch ' . number_format($rest, 0, ',', '.') . ' offen – mehr kann nicht produziert werden.', 'fehlt'=>[]];
    $bedarf = erp_teilmenge_bedarf($pa_id, $m);
    $fehlt = array_values(array_filter($bedarf, fn($b) => (float)$b['fehlt'] > 0.0001));
    if ($fehlt) return ['ok'=>false, 'fehler'=>'mangel', 'msg'=>'Nicht genug Material für diese Teilmenge.', 'fehlt'=>$fehlt];
    // Material anteilig nach FEFO verbrauchen (additiv – die Schritt-Entnahme ist dadurch idempotent).
    foreach ($bedarf as $b) erp_fefo_abbuchen($pa_id, (int)$b['item_id'], (float)$b['benoetigt'], (string)$b['einheit']);
    $cid = erp_teilmenge_einbuchen($pa_id, $m);
    if (!$cid) return ['ok'=>false, 'msg'=>'Teilmenge konnte nicht eingebucht werden (Lagerartikel fehlt?).', 'fehlt'=>[]];
    $nr = (string) scalar("SELECT charge_nr FROM charge WHERE id=?", [$cid]);
    // Status auf laufend (falls noch offen), Reservierungen abgleichen.
    if ((string) scalar("SELECT status FROM produktionsauftrag WHERE id=?", [$pa_id]) === 'offen')
        q("UPDATE produktionsauftrag SET status='laufend' WHERE id=?", [$pa_id]);
    erp_reservierung_abgleichen($pa_id);
    $pa = one("SELECT kunde_id, auftrag_id, nummer FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if ($pa && $pa['kunde_id'])
        erp_log_aktivitaet('kunde', (int)$pa['kunde_id'], 'team',
            'Teilmenge ' . number_format($m, 0, ',', '.') . ' Stück produziert (Charge ' . $nr . ', Produktion ' . $pa['nummer'] . ').',
            'auftrag', 'auftrag', (int)($pa['auftrag_id'] ?? 0));
    return ['ok'=>true, 'msg'=>'Teilmenge eingebucht (Charge ' . $nr . ').', 'charge_nr'=>$nr, 'fehlt'=>[]];
}
// Basis-Chargennummer = PR-Nummer ohne Präfix; nächste Teilcharge mit Tagesbuchstabe .A/.B/.C …
function erp_charge_naechste_nr(int $pa_id): string {
    $nummer = (string) scalar("SELECT nummer FROM produktionsauftrag WHERE id=?", [$pa_id]);
    $basis  = trim(preg_replace('/^PR[-\s]*/i', '', $nummer));
    if ($basis === '') $basis = 'PR' . $pa_id;
    $n = (int) scalar("SELECT COUNT(*) FROM charge WHERE pa_id=?", [$pa_id]);
    $buchstabe = ($n >= 0 && $n < 26) ? chr(ord('A') + $n) : ('X' . ($n + 1));
    return $basis . '.' . $buchstabe;
}
function erp_mhd_standard(): string {
    $monate = (int) (scalar("SELECT v FROM app_meta WHERE k='mhd_monate_standard'") ?: 18);
    if ($monate <= 0) $monate = 18;
    return date('Y-m-d', strtotime(date('Y-m-d') . ' +' . $monate . ' months'));
}
function erp_produkt_lageritem(int $produkt_id): ?int {
    $id = scalar("SELECT id FROM item WHERE produkt_id=? AND kategorie='verkaufsfertig' LIMIT 1", [$produkt_id]);
    if ($id) return (int)$id;
    $name = scalar("SELECT name FROM produkt WHERE id=?", [$produkt_id]);
    if ($name === null) return null;
    q("INSERT INTO item (artikelnummer,name,kategorie,einheit,preis_bezug,produkt_id) VALUES (?,?,?,?,?,?)",
      [erp_naechste_nummer('VF'), $name, 'verkaufsfertig', 'Stück', 'Stück', $produkt_id]);
    return insert_id();
}
function erp_rezeptur_bulkitem(int $rezeptur_id): ?int {
    if ($rezeptur_id <= 0) return null;
    $id = scalar("SELECT id FROM item WHERE rezeptur_id=? AND kategorie='fertig' LIMIT 1", [$rezeptur_id]);
    if ($id) return (int)$id;
    $rz = one("SELECT name, darreichungsform FROM rezeptur WHERE id=?", [$rezeptur_id]);
    if (!$rz) return null;
    $einheit = ($rz['darreichungsform'] === 'pulver') ? 'g' : (in_array($rz['darreichungsform'], ['fluessig','gel'], true) ? 'ml' : 'Stück');
    q("INSERT INTO item (artikelnummer,name,kategorie,form,einheit,preis_bezug,rezeptur_id) VALUES (?,?,?,?,?,?,?)",
      [erp_naechste_nummer('BULK'), $rz['name'] . ' – Bulk', 'fertig', (string)$rz['darreichungsform'], $einheit, $einheit, $rezeptur_id]);
    return insert_id();
}
function erp_auftrag_ist_fulfillment(int $auftrag_id): bool {
    if ($auftrag_id <= 0) return false;
    return (bool) scalar("SELECT k.nutzt_fulfillment FROM auftrag a JOIN kunden k ON k.id=a.kunde_id WHERE a.id=?", [$auftrag_id]);
}
function erp_bsku_ensure(int $item_id): string {
    $b = (string) scalar("SELECT bsku FROM item WHERE id=?", [$item_id]);
    if ($b !== '') return $b;
    $seq = (int) (scalar("SELECT v FROM app_meta WHERE k='bsku_seq'") ?: 10000);
    if ($seq < 10000) $seq = 10000;
    for ($i = 0; $i < 100000; $i++) {
        $kand = (string)($seq + $i);
        if (!scalar("SELECT COUNT(*) FROM item WHERE bsku=?", [$kand])) {
            q("INSERT INTO app_meta (k,v) VALUES ('bsku_seq',?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [(string)($seq + $i + 1)]);
            q("UPDATE item SET bsku=? WHERE id=?", [$kand, $item_id]);
            return $kand;
        }
    }
    return '';
}
// Nächste fortlaufende Nummer aus dem gemeinsamen Nummernkreis (identisch zum Dashboard).
function erp_naechste_nummer(string $prefix): string {
    $prefix = strtoupper(trim($prefix));
    q("INSERT IGNORE INTO nummernkreis (prefix, naechste, stellen) VALUES (?, 2690, 4)", [$prefix]);
    q("UPDATE nummernkreis SET naechste = naechste + 1 WHERE prefix = ?", [$prefix]);
    $r = one("SELECT naechste - 1 AS nr, stellen FROM nummernkreis WHERE prefix = ?", [$prefix]);
    return $prefix . '-' . str_pad((string)$r['nr'], (int)$r['stellen'], '0', STR_PAD_LEFT);
}
function erp_log_aktivitaet(string $objekt_typ, int $objekt_id, string $akteur, string $text,
                            string $typ = '', string $ref_typ = '', int $ref_id = 0): void {
    if (!tabelle_da('aktivitaet')) return;
    q("INSERT INTO aktivitaet (objekt_typ,objekt_id,akteur,typ,text,ref_typ,ref_id,erstellt) VALUES (?,?,?,?,?,?,?,?)",
      [$objekt_typ, $objekt_id, $akteur, $typ ?: null, $text, $ref_typ ?: null, $ref_id ?: null, gmdate('Y-m-d H:i:s')]);
}
