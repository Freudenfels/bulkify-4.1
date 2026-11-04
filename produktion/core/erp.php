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
// Mitarbeiter per Tablet-PIN (Produktions-App): sucht unter allen aktiven Benutzern mit gesetzter PIN
// die/den mit passendem Hash. Nur Benutzer mit Produktions-Zugang (production/admin). 0–1 Treffer.
function erp_benutzer_per_pin(string $pin): ?array {
    $pin = preg_replace('/\D/', '', $pin);
    if (!tabelle_da('benutzer') || $pin === '') return null;
    foreach (all("SELECT id, name, email, rollen, pin_hash FROM benutzer WHERE aktiv=1 AND pin_hash IS NOT NULL AND pin_hash<>''") as $u) {
        $r = array_map('trim', explode(',', (string)($u['rollen'] ?? '')));
        if (!array_intersect(['admin', 'production'], $r)) continue;
        if (password_verify($pin, (string)$u['pin_hash'])) { unset($u['pin_hash']); return $u; }
    }
    return null;
}
// Das Dashboard liegt auf derselben Domain unter "/".
function erp_dashboard_url(): string { return '/'; }

// --- Rohstoff-Rückgabe nach dem Mischen (ganze Charge geholt, Rest zurücklegen) ----------------
// Verbrauchte ROHSTOFF-Chargen dieses Auftrags: der Mitarbeiter hat sie als ganze Charge geholt und
// meldet nach dem Mischen das zurückgelegte Rest-Gewicht. Blinker/Lagerplatz bleiben gleich.
function erp_rohstoff_rueckgabe_offen(int $pa_id): array {
    if ($pa_id <= 0 || !tabelle_da('produktion_verbrauch')) return [];
    return all("SELECT v.charge_id, v.item_id, i.name AS item_name, c.charge_nr,
                       c.menge_verfuegbar, COALESCE(NULLIF(c.einheit,''), NULLIF(i.einheit,''), 'kg') AS einheit
                FROM produktion_verbrauch v
                JOIN item i   ON i.id=v.item_id
                JOIN charge c ON c.id=v.charge_id
                WHERE v.pa_id=? AND i.kategorie='rohstoff'
                GROUP BY v.charge_id, v.item_id, i.name, c.charge_nr, c.menge_verfuegbar, c.einheit, i.einheit
                ORDER BY i.name", [$pa_id]);
}
function erp_rohstoff_rueckgabe_erledigt(int $pa_id): bool {
    $d = function_exists('pr_daten') ? pr_daten($pa_id) : [];
    return !empty($d['rohstoff_rueckgabe_done']['wert']);
}
// Rückgabe speichern: je Charge das zurückgelegte Gewicht als neuen Bestand setzen (menge_verfuegbar).
// $gewichte = [charge_id => rest]. Setzt ein „erledigt"-Flag, damit nicht erneut gefragt wird.
function erp_rohstoff_rueckgabe_speichern(int $pa_id, array $gewichte, string $von = ''): int {
    $n = 0;
    foreach (erp_rohstoff_rueckgabe_offen($pa_id) as $r) {
        $cid = (int)$r['charge_id']; if (!array_key_exists($cid, $gewichte)) continue;
        $rest = max(0.0, (float)$gewichte[$cid]);
        // Teil 4 (Schritt 3): blind. Der gewogene Wert IST der neue Bestand. Der rechnerisch erwartete Rest ist der
        // aktuell gebuchte Bestand (nach FEFO-Abbuchung) – die Differenz wird still als Schwund protokolliert,
        // der Mitarbeiter sieht nichts davon.
        $rechnerisch = (float) scalar("SELECT menge_verfuegbar FROM charge WHERE id=?", [$cid]);
        q("UPDATE charge SET menge_verfuegbar=? WHERE id=?", [$rest, $cid]);
        erp_schwund_erfassen((int)$r['item_id'], $cid, $pa_id, $rechnerisch, $rest, (string)$r['einheit'], $von);
        $n++;
    }
    if (function_exists('pr_daten_setzen')) pr_daten_setzen($pa_id, 'rohstoff_rueckgabe_done', '1', $von);
    if (function_exists('bedarf_bump')) bedarf_bump();
    return $n;
}

// --- Gebinde (Teil 4) über die Naht: Ortswechsel beim Scannen + Schwund beim Rückgabe-Wiegen ---------
// charge_gebinde/schwund/benachrichtigung sind GETEILTE Dashboard-Tabellen – hier nur Raw-SQL. Fehlt eine
// Tabelle (nur das Dashboard legt sie an), passiert nichts (graceful).
function erp_charge_gebinde_liste(int $charge_id): array {
    if ($charge_id <= 0 || !tabelle_da('charge_gebinde')) return [];
    return all("SELECT * FROM charge_gebinde WHERE charge_id=? ORDER BY nummer, id", [$charge_id]);
}
// Ein Gebinde einem Scan zuordnen und in die Produktion umbuchen (NUR Ortswechsel, keine Abbuchung).
// Rückgabe ['ok'=>bool, 'charge_id'=>int, 'nummer'=>int, 'anzahl'=>int] – charge_id für die Scan-Bestätigung.
function erp_gebinde_scan_nach_produktion(int $gebinde_id): array {
    if ($gebinde_id <= 0 || !tabelle_da('charge_gebinde')) return ['ok'=>false, 'charge_id'=>0];
    $g = one("SELECT id, charge_id, nummer, anzahl_gesamt, standort FROM charge_gebinde WHERE id=?", [$gebinde_id]);
    if (!$g) return ['ok'=>false, 'charge_id'=>0];
    if ((string)$g['standort'] !== 'produktion')
        q("UPDATE charge_gebinde SET standort='produktion' WHERE id=?", [$gebinde_id]);
    return ['ok'=>true, 'charge_id'=>(int)$g['charge_id'], 'nummer'=>(int)$g['nummer'], 'anzahl'=>(int)$g['anzahl_gesamt']];
}
// Schwund (Differenz rechnerisch vs. gewogen) still protokollieren + Benachrichtigung „nach oben":
//  mehr als erwartet IMMER, Schwund ab Grenze (app_meta schwund_grenze_kg, Default 1 kg). „Blind" – keine
//  Rückmeldung an den Mitarbeiter. Spiegelt schwund_erfassen() des Dashboards (core/schema.php).
function erp_schwund_erfassen(int $item_id, ?int $charge_id, ?int $pa_id, float $rechnerisch, float $gewogen,
                              string $einheit = 'kg', string $mitarbeiter = '', ?int $gebinde_id = null): void {
    if ($item_id <= 0 || !tabelle_da('schwund')) return;
    $diff = round($rechnerisch - $gewogen, 3);          // positiv = Schwund (weniger als erwartet)
    if (abs($diff) <= 0.0005) return;
    $auftrag_id = $pa_id ? (int) scalar("SELECT auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]) : null;
    q("INSERT INTO schwund (item_id,charge_id,gebinde_id,pa_id,auftrag_id,rechnerisch,gewogen,differenz,einheit,mitarbeiter,angelegt)
       VALUES (?,?,?,?,?,?,?,?,?,?,?)",
      [$item_id, $charge_id ?: null, $gebinde_id ?: null, $pa_id ?: null, $auftrag_id ?: null,
       round($rechnerisch, 3), round($gewogen, 3), $diff, $einheit, ($mitarbeiter ?: null), gmdate('Y-m-d H:i:s')]);
    $grenze = (float) str_replace(',', '.', (string) (scalar("SELECT v FROM app_meta WHERE k='schwund_grenze_kg'") ?: '1'));
    $mehr = $diff < 0;
    if (!($mehr || $diff > $grenze)) return;            // innerhalb Grenze -> nur stiller Eintrag
    if (!tabelle_da('benachrichtigung')) return;
    $name = (string) scalar("SELECT name FROM item WHERE id=?", [$item_id]);
    $chnr = $charge_id ? (string) scalar("SELECT charge_nr FROM charge WHERE id=?", [$charge_id]) : '';
    $nf = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
    $betreff = ($mehr ? 'Wiege-Abweichung (mehr als erwartet): ' : 'Schwund über Grenze: ') . $name . ($chnr ? ' · Charge ' . $chnr : '');
    $text = 'Rechnerisch ' . $nf($rechnerisch) . ' ' . $einheit . ', gewogen ' . $nf($gewogen) . ' ' . $einheit
          . ', Differenz ' . $nf(abs($diff)) . ' ' . $einheit . ' ' . ($mehr ? '(mehr – Mischung/Rezeptur prüfen)' : '(Schwund)')
          . ($mitarbeiter !== '' ? ' · Mitarbeiter: ' . $mitarbeiter : '') . ($pa_id ? ' · PA #' . $pa_id : '');
    $link = $charge_id ? ('?p=chargen&id=' . $charge_id) : ('?p=rohstoff&id=' . $item_id);
    $jetzt = gmdate('Y-m-d H:i:s');
    $hatArt = (bool) scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='benachrichtigung' AND column_name='art'");
    if ($hatArt) q("INSERT INTO benachrichtigung (betreff,text,link,art,wichtig,erstellt) VALUES (?,?,?,?,1,?)", [$betreff, $text, $link, 'wiegen', $jetzt]);
    else         q("INSERT INTO benachrichtigung (betreff,text,link,erstellt) VALUES (?,?,?,?)", [$betreff, $text, $link, $jetzt]);
}

// --- Chargenprobe je Rohstoff (vor dem Mischen) -----------------------------------------------
// Pro verwendeter Rohstoff-Charge ist je Produktionsauftrag eine Probe (prod_probe, Ebene rohstoff) nötig.
// Da die Probe pro PA erfasst wird, verlangt jede neue Produktion automatisch eine frische Probe – also auch
// dann, wenn eine Charge nach dem Zurücklegen wieder aus dem Lager verwendet wird ("neue Probe bei nächster
// Produktion, sofern wieder im Lager"). Grundlage sind die Mischen-Pflichtrohstoffe (FEFO-Charge).
function erp_rohstoff_proben_status(int $pa_id): array {
    if ($pa_id <= 0) return [];
    // Die Rohstoff-Chargen (FEFO) stehen am Schritt „Rohstoffe bereitstellen" – vor dem Mischen und
    // vor dem Verbrauch (der erst beim Mischen gebucht wird), also sind die Chargen hier bekannt.
    $mat = erp_schritt_material($pa_id, 'Rohstoffe bereitstellen');
    $out = [];
    foreach (($mat['zeilen'] ?? []) as $z) {
        if (!($z['pflicht'] ?? true)) continue;
        $cid = (int)($z['charge_id'] ?? 0); if ($cid <= 0) continue;
        $hat = tabelle_da('prod_probe') && (int) scalar("SELECT COUNT(*) FROM prod_probe WHERE pa_id=? AND charge_id=? AND ebene='rohstoff'", [$pa_id, $cid]) > 0;
        $out[] = ['charge_id'=>$cid, 'item_id'=>(int)($z['item_id'] ?? 0), 'name'=>(string)$z['name'],
                  'charge_nr'=>(string) scalar("SELECT charge_nr FROM charge WHERE id=?", [$cid]), 'hat_probe'=>$hat];
    }
    return $out;
}
function erp_rohstoff_proben_offen(int $pa_id): bool {
    foreach (erp_rohstoff_proben_status($pa_id) as $r) if (empty($r['hat_probe'])) return true;
    return false;
}
function erp_rohstoff_probe_ziehen(int $pa_id, int $charge_id, int $item_id, string $von = ''): int {
    if (!tabelle_da('prod_probe') || $charge_id <= 0) return 0;
    if ((int) scalar("SELECT COUNT(*) FROM prod_probe WHERE pa_id=? AND charge_id=? AND ebene='rohstoff'", [$pa_id, $charge_id]) > 0) return 0;
    $bn = (string) scalar("SELECT charge_nr FROM charge WHERE id=?", [$charge_id]);
    return erp_probe_anlegen(['pa_id'=>$pa_id, 'item_id'=>$item_id, 'charge_id'=>$charge_id, 'ebene'=>'rohstoff', 'batch_nr'=>$bn, 'anzahl'=>1, 'erfasst_von'=>$von]);
}

// --- Produktionsaufträge (nur lesen) ---------------------------------------------------------
// Liste der Produktionsaufträge mit Produkt/Kunde/Fortschritt + Auftragseingang.
// $status: '' = aktive (offen+laufend), 'alle' = alle, sonst genau dieser Status (offen|laufend|erledigt).
function erp_produktionsauftraege(string $status = '', bool $gateAware = true): array {
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
    // PreProduktionsauftrag: Vor-PAs (Status 'vorbereitung') sind hier SICHTBAR, aber gesperrt (nicht startbar).
    // Erst die Admin-Freigabe im Dashboard macht daraus einen startbaren Auftrag ('offen'). $gateAware bleibt
    // als Fallback, falls der Status in einer alten DB noch nicht existiert.
    // Stornierte Aufträge gehören nicht ins Werk (Nico 03.11.2026): auch ein noch aktiver PA eines stornierten
    // Auftrags (z. B. in ein Kontingent umgewandelte Jahresmenge) bleibt draußen – außer bei 'alle'.
    $ohneStorno = "NOT EXISTS (SELECT 1 FROM auftrag ax WHERE ax.id=pa.auftrag_id AND ax.status='storniert')";
    if ($status === 'alle')  { /* alle Status */ }
    elseif ($status !== '')  { $sql .= " WHERE pa.status=? AND $ohneStorno"; $params[] = $status; }
    else                     { $sql .= " WHERE pa.status IN ('vorbereitung','offen','laufend') AND $ohneStorno"; }   // aktive + Vorbereitung
    if ($status === 'erledigt') $sql .= " ORDER BY pa.aktualisiert DESC, pa.id DESC";  // zuletzt fertig zuerst
    else                        $sql .= " ORDER BY COALESCE(pa.prio,2), (pa.geplant_am IS NULL), pa.geplant_am, pa.id DESC";
    try { return all($sql, $params); }
    catch (\Throwable $e) { return $gateAware ? erp_produktionsauftraege($status, false) : []; }
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
                LEFT JOIN item vp    ON vp.id=COALESCE(pa.verpackung_id, a.verpackung_id, p.verpackung_id)   -- PA → Auftrag (bedruckter Kundenbeutel) → Produkt
                LEFT JOIN kunden k   ON k.id=pa.kunde_id
                WHERE pa.id=?", [$id]);
}
// Schritte eines Produktionsauftrags (Stationen in Reihenfolge).
function erp_pa_schritte(int $pa_id): array {
    if ($pa_id <= 0 || !tabelle_da('produktion_schritt')) return [];
    return all("SELECT * FROM produktion_schritt WHERE pa_id=? ORDER BY sort, id", [$pa_id]);
}
// Station (Name) eines einzelnen Schritts – für Prüfungen vor dem Abschließen (z. B. Reinigungs-Sperre).
function erp_schritt_station(int $schritt_id): string {
    if ($schritt_id <= 0 || !tabelle_da('produktion_schritt')) return '';
    return (string) scalar("SELECT station FROM produktion_schritt WHERE id=?", [$schritt_id]);
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
    // Zukauf „fertig" (Nacht 2a): die Ware ist schon abgefüllt/etikettiert -> keine Ausbau-Schritte.
    $fertig = $basis === 'zukauf' && erp_zukauf_fertig($pa_id);
    if (!$fertig && !empty($f['abfuellen']))    $steps[] = 'Verpacken';
    if (!$fertig && !empty($f['etikettieren'])) $steps[] = 'Etikettieren';
    if (!$fertig && !empty($f['beipack']))      $steps[] = 'Beipackzettel beilegen';
    if (!$fertig && !empty($f['karton']))       $steps[] = 'Umkarton';
    if ($basis !== 'zukauf')        $steps[] = 'Rückstellmuster ziehen';
    return array_merge($steps, ['Qualitätsprüfung', 'Versand-Freigabe']);   // Soll-Kette 2e (04.11.2026): Schritt „Produktions-Freigabe“ entfällt
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
    $pa = one("SELECT menge, produkt_id, auftrag_id, verpackung_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
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
                             'menge'=>$need, 'einheit'=>'Stk', 'verfuegbar'=>erp_item_bestand($kid), 'item_id'=>$kid];
            }
            break;
        case 'Fertigware bereitstellen':
            $zkFertig = erp_zukauf_fertig($pa_id);                   // fertige Ware: Packungen, nicht lose Einheiten (St-24)
            $soll_menge = $zkFertig ? (float)$pa['menge'] : (float)$pa['menge'] * erp_stueck_je_packung($pa);
            $soll_einheit = $zkFertig ? 'Pkg' : erp_fertigware_einheit($pa_id);        // Pulver: g (Packungen × g je Packung), sonst Stk
            $chargen = $zkFertig ? erp_zukauf_fertig_chargen($pa_id, 'frei') : erp_fertigware_chargen($pa_id, 'frei');   // Auftrag ODER Rezeptur-Bulk
            foreach ($chargen as $c)
                $zeilen[] = ['name'=>$c['name'], 'detail'=>'Charge ' . $c['charge_nr'], 'menge'=>(float)$c['menge_verfuegbar'],
                             'einheit'=>(string)($c['einheit'] ?? '') ?: $soll_einheit, 'verfuegbar'=>(float)$c['menge_verfuegbar'], 'item_id'=>(int)$c['item_id'], 'charge_id'=>(int)$c['id']];
            // Noch keine Fertigware im Lager: trotzdem klar ansagen, WAS und WIE VIEL bereitzustellen ist.
            if (!$chargen) {
                $pname = (string) scalar("SELECT COALESCE(NULLIF(p.kundenname,''), p.name, a.produkt_bezeichnung, r.name)
                                          FROM produktionsauftrag pa
                                          LEFT JOIN produkt p  ON p.id=pa.produkt_id
                                          LEFT JOIN auftrag a  ON a.id=pa.auftrag_id
                                          LEFT JOIN rezeptur r ON r.id=COALESCE(pa.rezeptur_id, p.rezeptur_id)
                                          WHERE pa.id=?", [$pa_id]);
                $zeilen[] = ['name'=>($pname !== '' ? $pname : 'Fertigware') . ' (zugekaufte Fertigware)', 'detail'=>'noch nicht im Lager gebucht',
                             'menge'=>$soll_menge, 'einheit'=>$soll_einheit, 'verfuegbar'=>0.0, 'item_id'=>0];
            }
            break;
        case 'Verpacken':
            // Beim Abschließen des Schritts werden Primärgebinde UND Deckel abgebucht (erp_verpackung_entnehmen); pflicht=false
            // heißt nur „kein Scan-Zwang" (Deckel/Etikett haben keinen Blinker) – die Abbuchung läuft trotzdem.
            // Behälter des AUFTRAGS (PA → Auftrag → Produkt), damit der bedruckte Kundenbehälter angezeigt wird (nicht der neutrale).
            $vid = (int)($pa['verpackung_id'] ?? 0);
            if ($vid <= 0 && !empty($pa['auftrag_id'])) $vid = (int) scalar("SELECT COALESCE(verpackung_id,0) FROM auftrag WHERE id=?", [(int)$pa['auftrag_id']]);
            if ($vid <= 0) $vid = (int) (scalar("SELECT verpackung_id FROM produkt WHERE id=?", [(int)$pa['produkt_id']]) ?: 0);
            if ($vid)
                $zeilen[] = ['name'=> (string) scalar("SELECT name FROM item WHERE id=?", [$vid]), 'detail'=>'Verpackung',
                             'menge'=>(float)$pa['menge'], 'einheit'=>'Stk', 'verfuegbar'=>erp_item_bestand($vid), 'item_id'=>$vid];
            $did = (int) (scalar("SELECT verschluss_id FROM produkt WHERE id=?", [(int)$pa['produkt_id']]) ?: 0);
            if ($did)
                $zeilen[] = ['name'=> (string) scalar("SELECT name FROM item WHERE id=?", [$did]), 'detail'=>'Deckel',
                             'menge'=>(float)$pa['menge'], 'einheit'=>'Stk', 'verfuegbar'=>erp_item_bestand($did), 'item_id'=>$did, 'pflicht'=>false];
            break;
        case 'Etikettieren':
            $eid = erp_etikett_item_id($pa_id);   // Kundenetikett DIESES Auftrags (nicht das eines anderen Kunden)
            if ($eid)
                $zeilen[] = ['name'=> (string) scalar("SELECT name FROM item WHERE id=?", [$eid]), 'detail'=>'Etikett',
                             'menge'=>(float)$pa['menge'], 'einheit'=>'Stk', 'verfuegbar'=>erp_item_bestand($eid), 'item_id'=>$eid, 'pflicht'=>false];
            break;
    }
    // Je Zeile die FEFO-Charge (für Blinker + Anzeige/Scan-Bestätigung), Quarantäne-Menge (Hinweis) und ob schon entnommen.
    // Restbuchung (S-13/St-08): „Menge" = was für diesen Schritt NOCH abzubuchen ist (Soll − bereits verbraucht), z. B. nach einer Teilmenge;
    // 'menge_gesamt' = Soll für den ganzen Auftrag. Fertigware-Zeilen (Chargenliste) bleiben unverändert.
    foreach ($zeilen as &$z)
        if (!empty($z['item_id']) && !isset($z['charge_id']) && $station !== 'Fertigware bereitstellen') {
            $z['menge_gesamt'] = (float)$z['menge'];
            $z['menge'] = max(0.0, (float)$z['menge'] - erp_verbraucht($pa_id, (int)$z['item_id']));
        }
    unset($z);
    foreach ($zeilen as &$z)
        if (!empty($z['item_id'])) {
            if (!isset($z['charge_id'])) $z['charge_id'] = erp_fefo_charge_id((int)$z['item_id']);
            if (!empty($z['charge_id']) && empty($z['charge_nr']))
                $z['charge_nr'] = (string) scalar("SELECT charge_nr FROM charge WHERE id=?", [(int)$z['charge_id']]);
            $z['quarantaene'] = erp_item_quarantaene((int)$z['item_id']);
            $z['entnommen'] = isset($z['menge_gesamt'])
                ? (float)$z['menge'] <= max(0.0001, (float)$z['menge_gesamt'] * 0.001)       // Soll komplett gebucht
                : (int) scalar("SELECT COUNT(*) FROM produktion_verbrauch WHERE pa_id=? AND item_id=?", [$pa_id, (int)$z['item_id']]) > 0;
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
// Proben-Etikett (Rückstellmuster) über die Lager-Brücke drucken – lautlos auf dem in den
// Lager-Einstellungen festgelegten Proben-Drucker. Legt nur einen Druckjob an (gleiche Loopback-
// Kette wie Pick-to-Light), die PDF erzeugt die Brücke selbst. Rückgabe ['ok','meldung'].
function pr_lager_druck_probe(int $probe_id): array {
    if ($probe_id <= 0) return ['ok'=>false, 'meldung'=>'Keine Probe angegeben.'];
    return pr_lager_blink_call('p=api_druck&typ=probe&id=' . $probe_id);
}
// Eimer-/Gebinde-Etikett (eine Mischgebinde-Untercharge) über die Lager-Brücke drucken.
function pr_lager_druck_gebinde(int $sub_charge_id): array {
    if ($sub_charge_id <= 0) return ['ok'=>false, 'meldung'=>'Kein Gebinde angegeben.'];
    return pr_lager_blink_call('p=api_druck&typ=gebinde&id=' . $sub_charge_id);
}
// Eine Probe als „Etikett gedruckt" markieren (Dashboard-Tabelle prod_probe, Raw-SQL über die Naht).
function erp_probe_etikett_gedruckt(int $probe_id): void {
    if ($probe_id > 0 && tabelle_da('prod_probe')) q("UPDATE prod_probe SET etikett_gedruckt=1 WHERE id=?", [$probe_id]);
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

// Existiert eine Spalte? (Für vorwärtskompatible Writes, bevor das Dashboard die Spalte anlegt.)
function erp_spalte_da(string $tabelle, string $spalte): bool {
    try {
        return (bool) scalar("SELECT COUNT(*) FROM information_schema.columns
                              WHERE table_schema=DATABASE() AND table_name=? AND column_name=?", [$tabelle, $spalte]);
    } catch (Throwable $e) { return false; }
}
// Beim Laborversand das Auftragsfeld setzen, damit Auftrag/Kundenportal den Status zeigen können.
// Vorwärtskompatibel: schreibt nur, wenn die Spalte (vom Dashboard) existiert.
function erp_auftrag_labor_versendet(int $pa_id): void {
    $auf = (int) scalar("SELECT auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$auf || !tabelle_da('auftrag')) return;
    if (erp_spalte_da('auftrag', 'labor_versendet_am'))
        q("UPDATE auftrag SET labor_versendet_am=CURDATE() WHERE id=? AND labor_versendet_am IS NULL", [$auf]);
}

// --- Kunden-Etikett am Auftrag --------------------------------------------------------------
// Hochgeladenes Etikett-Design des Kunden (Dokument am Auftrag, typ='etikett').
function erp_etikett_datei(int $pa_id): ?array {
    if (!tabelle_da('dokument')) return null;
    $auf = (int) scalar("SELECT auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$auf) return null;
    return one("SELECT id, datei, datei_orig, datei_hash, angelegt FROM dokument
                WHERE objekt_typ='auftrag' AND objekt_id=? AND typ='etikett' ORDER BY id DESC LIMIT 1", [$auf]);
}
// Wie oft kam dasselbe Etikett-Design (gleicher Datei-Hash) schon bei ANDEREN Aufträgen vor?
function erp_etikett_schon_verwendet(string $hash, int $pa_id): int {
    if (trim($hash) === '' || !tabelle_da('dokument')) return 0;
    $auf = (int) scalar("SELECT auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    return (int) scalar("SELECT COUNT(DISTINCT objekt_id) FROM dokument
                         WHERE objekt_typ='auftrag' AND typ='etikett' AND datei_hash=? AND objekt_id<>?", [$hash, $auf]);
}
// WELCHES Etikett gehört zu diesem Produktionsauftrag? Das KUNDENETIKETT des Auftrags – nie das eines anderen Kunden
// desselben Katalogprodukts: 1) die an den Auftrag gebundene Version (auftrag.etikett_item_id), 2) das aktuelle
// Kundenetikett dieses Kunden (item.kunde_id), 3) der Produkt-Slot NUR wenn er ein generisches Etikett ist (kein
// Kundenetikett eines Fremden). Spiegel von etikett_item_fuer_auftrag_kunde() im Dashboard.
// Behälter des PA (PA → Auftrag → Produkt) ist bedruckt (vollbedruckter Beutel)? Dann ersetzt der Druck das Etikett.
// Spiegel von auftrag_vollbedruckt()/verpackung_ist_bedruckt() im Dashboard.
function erp_vollbedruckt(int $pa_id): bool {
    if (!erp_spalte_da('item', 'kundenspezifisch')) return false;
    return (int) scalar("SELECT COALESCE(i.kundenspezifisch,0) FROM produktionsauftrag pa LEFT JOIN auftrag a ON a.id=pa.auftrag_id
                         LEFT JOIN produkt p ON p.id=pa.produkt_id
                         LEFT JOIN item i ON i.id=COALESCE(NULLIF(pa.verpackung_id,0), NULLIF(a.verpackung_id,0), p.verpackung_id)
                         WHERE pa.id=?", [$pa_id]) === 1;
}
function erp_etikett_item_id(int $pa_id): int {
    $pa = one("SELECT produkt_id, auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa || empty($pa['produkt_id'])) return 0;
    if (erp_vollbedruckt($pa_id)) return 0;   // vollbedruckt: kein Etikett
    $pid = (int)$pa['produkt_id']; $auf = (int)($pa['auftrag_id'] ?? 0);
    if ($auf && erp_spalte_da('auftrag', 'etikett_item_id')) {
        $b = (int) scalar("SELECT COALESCE(etikett_item_id,0) FROM auftrag WHERE id=?", [$auf]);
        if ($b > 0) return $b;
    }
    if ($auf && erp_spalte_da('item', 'kunde_id')) {
        $k = (int) scalar("SELECT COALESCE(kunde_id,0) FROM auftrag WHERE id=?", [$auf]);
        if ($k > 0) {
            $ke = (int) scalar("SELECT id FROM item WHERE verpackung_rolle='etikett' AND produkt_id=? AND kunde_id=? AND COALESCE(gesperrt,0)=0
                                ORDER BY COALESCE(etikett_version,1) DESC, id DESC LIMIT 1", [$pid, $k]);
            if ($ke > 0) return $ke;
        }
    }
    $slot = (int) (scalar("SELECT etikett_id FROM produkt WHERE id=?", [$pid]) ?: 0);
    if ($slot > 0 && (int) scalar("SELECT 1 FROM item WHERE id=? AND verpackung_rolle='etikett' AND produkt_id IS NOT NULL", [$slot])) return 0;   // fremdes Kundenetikett
    return $slot;
}
// Physisches Etikett: angekommen (Bestand frei), in Quarantäne, bestellt oder offen.
function erp_etikett_status(int $pa_id): array {
    $pa = one("SELECT produkt_id, auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa || empty($pa['produkt_id'])) return ['status'=>'kein_etikett'];
    $eid = erp_etikett_item_id($pa_id);
    if (!$eid) return ['status'=>'kein_etikett'];
    $name = (string) scalar("SELECT name FROM item WHERE id=?", [$eid]);
    $frei = erp_item_bestand($eid);
    if ($frei > 0)                   return ['status'=>'angekommen', 'name'=>$name, 'menge'=>$frei];
    $quar = erp_item_quarantaene($eid);
    if ($quar > 0)                   return ['status'=>'quarantaene', 'name'=>$name, 'menge'=>$quar];
    $auf = (int)($pa['auftrag_id'] ?? 0);
    $bestellt = ($auf && tabelle_da('bestellung_position')) ? (int) scalar(
        "SELECT COUNT(*) FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id
         WHERE bp.item_id=? AND bp.auftrag_id=? AND b.status NOT IN ('geliefert','storniert') AND b.angekommen_am IS NULL", [$eid, $auf]) : 0;
    return ['status'=>$bestellt > 0 ? 'bestellt' : 'offen', 'name'=>$name];
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
    if ($status === 'vorbereitung') return ['status'=>'vorbereitung', 'fehlend'=>[]];   // noch nicht freigegeben -> gesperrt
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
    if (erp_zukauf_fertig($pa_id)) {   // fertige Ware in Packungen (St-24)
        $verf = array_sum(array_map(fn($c)=> (float)$c['menge_verfuegbar'], erp_zukauf_fertig_chargen($pa_id, 'frei')));
        if ($verf + 0.0001 < (float)$pa['menge']) {
            $quar = array_sum(array_map(fn($c)=> (float)$c['menge_verfuegbar'], erp_zukauf_fertig_chargen($pa_id, 'quarantaene')));
            $fehlend[] = ['name'=>'Fertige Ware (Packungen)', 'benoetigt'=>(float)$pa['menge'], 'verfuegbar'=>$verf, 'fehlt'=>(float)$pa['menge'] - $verf, 'einheit'=>'Pkg', 'quarantaene'=>$quar];
        }
        return $fehlend;
    }
    $hatFertigware = count(erp_fertigware_chargen($pa_id, 'frei')) > 0 || count(erp_fertigware_chargen($pa_id, 'quarantaene')) > 0;
    if (erp_weg_basis($pa_id) === 'zukauf' || $hatFertigware) {
        $benoetigt = (float)$pa['menge'] * erp_rest_faktor($pa_id) * erp_stueck_je_packung($pa);   // nur der noch offene Rest (St-08)
        if ($benoetigt <= 0) return $fehlend;
        $verf = array_sum(array_map(fn($c)=> (float)$c['menge_verfuegbar'], erp_fertigware_chargen($pa_id, 'frei')));
        if ($verf + 0.0001 < $benoetigt) {
            $quar = array_sum(array_map(fn($c)=> (float)$c['menge_verfuegbar'], erp_fertigware_chargen($pa_id, 'quarantaene')));
            $fehlend[] = ['name'=>'Fertige Bulkware', 'benoetigt'=>$benoetigt, 'verfuegbar'=>$verf, 'fehlt'=>$benoetigt - $verf, 'einheit'=>erp_fertigware_einheit($pa_id), 'quarantaene'=>$quar];
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
        $voll = (float)$pa['menge'] * erp_stueck_je_packung($pa);
        $need = max(0.0, min($voll * erp_rest_faktor($pa_id), $voll - erp_verbraucht($pa_id, (int)$kid)));   // Rest (St-08)
        $verf = erp_item_bestand($kid);
        if ($need > 0 && $verf + 0.0001 < $need)
            $fehlend[] = ['name'=> scalar("SELECT name FROM item WHERE id=?", [$kid]), 'benoetigt'=>$need, 'verfuegbar'=>$verf, 'fehlt'=>$need-$verf, 'einheit'=>'Stk'];
    }
    // Verpackung (1 je Packung): der am PA/Auftrag gebundene Behälter (z. B. bedruckter Beutel des Kunden), sonst der des Produkts.
    $vid = (int) (scalar("SELECT COALESCE(NULLIF(pa.verpackung_id,0), NULLIF(a.verpackung_id,0), p.verpackung_id)
                          FROM produktionsauftrag pa LEFT JOIN auftrag a ON a.id=pa.auftrag_id LEFT JOIN produkt p ON p.id=pa.produkt_id
                          WHERE pa.id=?", [$pa_id]) ?: 0);
    if ($vid) {
        $need = max(0.0, min((float)$pa['menge'] * erp_rest_faktor($pa_id), (float)$pa['menge'] - erp_verbraucht($pa_id, $vid)));   // Rest (St-08)
        $verf = erp_item_bestand($vid);
        if ($need > 0 && $verf + 0.0001 < $need)
            $fehlend[] = ['name'=> scalar("SELECT name FROM item WHERE id=?", [$vid]), 'benoetigt'=>$need, 'verfuegbar'=>$verf, 'fehlt'=>$need-$verf, 'einheit'=>'Stk'];
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

// Material-Entnahme je Station (Spiegel von produktion_station_entnahme()). $bis = bis zu wie vielen Packungen kumuliert verbraucht sein
// soll (null = ganzer Auftrag), $trocken = nur prüfen. FEFO-Abbuchung läuft auch ohne Scan.
function erp_station_entnahme(int $pa_id, string $station, ?float $bis = null, bool $trocken = false): array {
    return match ($station) {
        'Rohstoffe bereitstellen'  => erp_rohstoffe_entnehmen($pa_id, $bis, $trocken),
        'Verkapselung'             => erp_kapseln_entnehmen($pa_id, $bis, $trocken),
        'Fertigware bereitstellen' => erp_fertigware_entnehmen($pa_id, $bis, $trocken),
        // fertige Ware (Zukauf „fertig", St-24): Verpackung + Etikett sind enthalten -> nichts abbuchen
        'Verpacken'                => erp_zukauf_fertig($pa_id) ? ['ok'=>true, 'fehlt'=>[]] : erp_verpackung_entnehmen($pa_id, $bis, $trocken),   // Behälter + Deckel
        'Etikettieren'             => erp_zukauf_fertig($pa_id) ? ['ok'=>true, 'fehlt'=>[]] : erp_etikett_entnehmen($pa_id, $bis, $trocken),      // Kundenetikett
        default                    => ['ok'=>true, 'fehlt'=>[]],
    };
}
// Etikett-Sperre (Spiegel von produktion_etikett_sperre(), S-12): braucht der Auftrag ein Etikett und ist es nicht vom Kunden
// freigegeben, gibt es keinen Produktionsschritt und keine Teilmenge. '' = frei, sonst der Grund.
function erp_etikett_sperre(int $pa_id): string {
    $aid = (int) scalar("SELECT auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if ($aid <= 0 || !tabelle_da('auftrag')) return '';
    $r = one("SELECT a.verpackung_id AS a_vp, a.etikett_freigegeben, p.etikett_id, p.verpackung_id AS p_vp
              FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id WHERE a.id=?", [$aid]);
    if (!$r) return '';
    $braucht = (int)($r['etikett_id'] ?? 0) > 0 || ((int)($r['a_vp'] ?? 0) ?: (int)($r['p_vp'] ?? 0)) > 0;
    if (!$braucht || (int)($r['etikett_freigegeben'] ?? 0) === 1) return '';
    if (erp_vollbedruckt($pa_id))   // vollbedruckter Beutel: Design-Freigabe wie beim Etikett (Nico 03.11.2026)
        return erp_etikett_datei($pa_id) !== null ? 'Das Beutel-Design (vollbedruckt) ist noch nicht vom Kunden freigegeben – ohne Freigabe kann nicht produziert werden.'
                                                 : 'Es fehlt noch das Beutel-Design (vollbedruckt) bzw. die Kundenfreigabe – ohne Freigabe kann nicht produziert werden.';
    return erp_etikett_datei($pa_id) !== null ? 'Das Etikett ist noch nicht vom Kunden freigegeben – ohne Freigabe kann nicht produziert werden.'
                                             : 'Es fehlt noch das Etikett-Design bzw. die Kundenfreigabe – ohne Freigabe kann nicht produziert werden.';
}

// Einen Produktionsschritt abschließen. Erzwingt die Reihenfolge (nur der erste offene Schritt),
// bucht Material nach FEFO ab (Mangel-Guard), markiert erledigt, aktualisiert den Auftragsstatus;
// beim letzten Schritt wird die Fertigware als Charge eingebucht und der Auftrag auf 'erledigt' gesetzt.
// Rückgabe: ['ok'=>bool,'fehler'=>?('nicht_gefunden'|'reihenfolge'|'mangel'),'msg'=>string,'fertig'=>bool,'station'=>string,'fehlt'=>array].
// QM-Gate (St-11a, Spiegel von produktion_qm_stationen()): QK, Produktions- und Versand-Freigabe nur QM (labor) oder Admin.
function erp_qm_stationen(): array { return ['Qualitätsprüfung', 'Produktions-Freigabe', 'Versand-Freigabe']; }
function erp_darf_qm(int $uid): bool {
    $u = $uid > 0 ? erp_benutzer($uid) : null;
    if (!$u) return false;
    return (bool) array_intersect(['admin', 'labor'], array_map('trim', explode(',', (string)$u['rollen'])));
}
// $uid = wer abschließt (Prod-App: pr_uid(), Werk-App: PIN-Benutzer). Ohne $uid keine QM-Schritte.
function erp_schritt_abschliessen(int $schritt_id, string $akteur, int $uid = 0): array {
    if ($schritt_id <= 0 || !tabelle_da('produktion_schritt'))
        return ['ok'=>false, 'fehler'=>'nicht_gefunden', 'msg'=>'Schritt nicht gefunden.', 'fertig'=>false, 'station'=>'', 'fehlt'=>[]];
    $schritt = one("SELECT id, pa_id, station, erledigt FROM produktion_schritt WHERE id=?", [$schritt_id]);
    if (!$schritt)
        return ['ok'=>false, 'fehler'=>'nicht_gefunden', 'msg'=>'Schritt nicht gefunden.', 'fertig'=>false, 'station'=>'', 'fehlt'=>[]];
    $pa_id = (int)$schritt['pa_id'];
    $station = (string)$schritt['station'];
    if (in_array($station, erp_qm_stationen(), true) && !erp_darf_qm($uid))
        return ['ok'=>false, 'fehler'=>'qm', 'msg'=>'„' . $station . '" schließt QM ab (Vier-Augen-Prinzip) – bitte an QM/Labor übergeben.', 'fertig'=>false, 'station'=>$station, 'fehlt'=>[]];

    // PreProduktionsauftrag: ein noch in Vorbereitung stehender Auftrag ist gesperrt (noch nicht freigegeben).
    if ((string) scalar("SELECT status FROM produktionsauftrag WHERE id=?", [$pa_id]) === 'vorbereitung')
        return ['ok'=>false, 'fehler'=>'vorbereitung', 'msg'=>'Für diesen Auftrag ist noch kein Beschaffungsweg festgelegt (Auftrag → Reiter Beschaffungsweg).', 'fertig'=>false, 'station'=>$station, 'fehlt'=>[]];

    // Etikett-Freigabe sperrt nur noch das ETIKETTIEREN (Soll-Kette 4, 04.11.2026: bis vor dem Etikett darf produziert werden).
    // Die Schritte danach (Beipack, Umkarton, QK, Versand) kommen erst nach dem Etikettieren an die Reihe.
    if ($station === 'Etikettieren' && ($sperre = erp_etikett_sperre($pa_id)) !== '')
        return ['ok'=>false, 'fehler'=>'etikett', 'msg'=>$sperre, 'fertig'=>false, 'station'=>$station, 'fehlt'=>[]];

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
        erp_fehlbestand_melden($pa_id, $station, $msg);   // Team-Benachrichtigung im Dashboard (Paket Mails)
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
        erp_an_lager_uebergeben($pa_id);                // an Lager zum Einlagern übergeben (bucht NICHT selbst ein)
        $pa = one("SELECT auftrag_id, kunde_id, nummer FROM produktionsauftrag WHERE id=?", [$pa_id]);
        if ($pa && $pa['auftrag_id'] && tabelle_da('auftrag')) {
            // war per Teilversand während der Produktion schon alles geliefert -> direkt 'versendet' (Spiegel auftrag_nach_produktion_status)
            $av = one("SELECT menge, versendet_menge FROM auftrag WHERE id=?", [(int)$pa['auftrag_id']]);
            $allesRaus = $av && (int)$av['menge'] > 0 && (int)($av['versendet_menge'] ?? 0) >= (int)$av['menge'];
            q("UPDATE auftrag SET status=?" . ($allesRaus ? ", status_datum=CURDATE()" : "") . " WHERE id=?", [$allesRaus ? 'versendet' : 'erledigt', (int)$pa['auftrag_id']]);
            if ($pa['kunde_id'])
                erp_log_aktivitaet('kunde', (int)$pa['kunde_id'], 'team',
                    'Produktion ' . $pa['nummer'] . ' abgeschlossen – an Lager zum Einlagern übergeben.',
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
    if ((string) scalar("SELECT status FROM produktionsauftrag WHERE id=?", [$pa_id]) === 'vorbereitung')
        return ['ok'=>false, 'msg'=>'Auftrag ist noch in Vorbereitung und nicht zur Produktion freigegeben.'];
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

// Anteil der Auftragsmenge, der NOCH produziert werden muss (Rest / Gesamt) – 1,0 solange nichts gebucht ist. Spiegel der Skalierung
// in auftrag_bedarf_rest(): überall dieselbe Restmenge (St-08).
function erp_rest_faktor(int $pa_id): float {
    $menge = (float) scalar("SELECT menge FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if ($menge <= 0) return 1.0;
    $geb = round(erp_produktion_gebucht($pa_id));
    return $geb <= 0 ? 1.0 : max(0.0, min(1.0, ($menge - $geb) / $menge));
}
// Als Bulk zugekaufte, fest dem Auftrag zugeteilte (reservierte) Menge – wird nicht selbst produziert (Spiegel auftrag_bulk_reserviert_stueck()).
function erp_auftrag_bulk_reserviert(int $auftrag_id): float {
    if ($auftrag_id <= 0 || !tabelle_da('reservierung')) return 0.0;
    $a = one("SELECT produkt_id, rezeptur_id FROM auftrag WHERE id=?", [$auftrag_id]);
    if (!$a) return 0.0;
    $rid = (int)($a['rezeptur_id'] ?? 0);
    if (!$rid && !empty($a['produkt_id'])) $rid = (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [(int)$a['produkt_id']]);
    $item = $rid ? (int) scalar("SELECT id FROM item WHERE rezeptur_id=? AND kategorie='fertig' LIMIT 1", [erp_rezeptur_stamm($rid)]) : 0;
    if (!$item) return 0.0;
    return (float) scalar("SELECT COALESCE(SUM(menge),0) FROM reservierung WHERE auftrag_id=? AND item_id=? AND status='aktiv'
                           AND (bestellung_position_id IS NOT NULL OR charge_id IS NOT NULL)", [$auftrag_id, $item]);
}
// Materialbedarf (Rohstoffe) eines Auftrags: je Zutat benötigte vs. verfügbare Menge. Spiegel von produktion_materialbedarf():
// Pulver/Granulat nach FÜLLGEWICHT (Massenanteil), als Bulk reservierter Teil abgezogen, schon verbrauchte Menge ('entnommen')
// zählt nicht mehr als fehlend ('offen' = benötigt − entnommen).
function erp_materialbedarf(int $pa_id): array {
    $pa = one("SELECT menge, produkt_id, rezeptur_id, auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return [];
    $fuellGesamtG = 0.0;
    if (erp_pa_ist_bulk($pa)) {
        $rid = (int)$pa['rezeptur_id'];
        $einheiten_total = (int)$pa['menge'];
    } else {
        $rid = (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [(int)$pa['produkt_id']]);
        if (!$rid) return [];
        $einheiten_total = (int)$pa['menge'] * erp_stueck_je_packung($pa);
        $form = (string) scalar("SELECT darreichungsform FROM rezeptur WHERE id=?", [$rid]);
        if (in_array($form, ['pulver', 'granulat'], true)) $fuellGesamtG = (float)$pa['menge'] * (float) erp_stueck_je_packung($pa);
        $resv = erp_auftrag_bulk_reserviert((int)($pa['auftrag_id'] ?? 0));
        if ($resv > 0) {
            if ($fuellGesamtG > 0) $fuellGesamtG = max(0.0, $fuellGesamtG - $resv);
            else $einheiten_total = max(0, $einheiten_total - (int)round($resv));
        }
    }
    $zutaten = all("SELECT z.item_id, z.menge_mg, i.name, i.einheit
                    FROM rezeptur_zutat z JOIN item i ON i.id=z.item_id WHERE z.rezeptur_id=?", [$rid]);
    // Soll-Kette 1: Weg „Premix“ -> EIN Premix-Artikel (kg) statt der Zutaten (Spiegel von produktion_materialbedarf()).
    static $hatWeg = null;
    if ($hatWeg === null) $hatWeg = (int) scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='produktionsauftrag' AND COLUMN_NAME='beschaffungsweg'") > 0;
    if ($hatWeg && (string) scalar("SELECT COALESCE(beschaffungsweg,'') FROM produktionsauftrag WHERE id=?", [$pa_id]) === 'premix') {
        $pmx = (int) scalar("SELECT id FROM item WHERE rezeptur_id=? AND kategorie='rohstoff' AND form='premix' LIMIT 1", [erp_rezeptur_stamm($rid)]);
        if ($pmx) {
            $sumMgP = 0.0; foreach ($zutaten as $zz) $sumMgP += (float)$zz['menge_mg'];
            $zutaten = [['item_id' => $pmx, 'menge_mg' => $sumMgP, 'name' => (string) scalar("SELECT name FROM item WHERE id=?", [$pmx]), 'einheit' => 'kg']];
        }
    }
    $summeMg = 0.0; if ($fuellGesamtG > 0) foreach ($zutaten as $zz) $summeMg += (float)$zz['menge_mg'];
    $out = [];
    foreach ($zutaten as $z) {
        if ($fuellGesamtG > 0) $mg = $fuellGesamtG * 1000.0 * ($summeMg > 0 ? (float)$z['menge_mg'] / $summeMg : 0.0);
        else                   $mg = (float)$z['menge_mg'] * $einheiten_total;
        $faktor = $z['einheit'] === 'g' ? 1e3 : 1e6;          // mg -> Basiseinheit (kg-Standard)
        $benoetigt = $mg / $faktor;
        $verf = erp_item_bestand((int)$z['item_id']);
        $entnommen = erp_verbraucht($pa_id, (int)$z['item_id']);
        $offen = max(0.0, min($benoetigt * erp_rest_faktor($pa_id), $benoetigt - $entnommen));   // Rest (St-08), siehe auftrag_bedarf_rest()
        $out[] = ['item_id'=>(int)$z['item_id'], 'name'=>$z['name'], 'einheit'=>$z['einheit'], 'menge_mg'=>(float)$z['menge_mg'],
                  'benoetigt'=>$benoetigt, 'entnommen'=>$entnommen, 'offen'=>$offen,
                  'verfuegbar'=>$verf, 'quarantaene'=>erp_item_quarantaene((int)$z['item_id']), 'fehlt'=>max(0.0, $offen - $verf)];
    }
    return $out;
}

// --- FEFO-Entnahme je Station (idempotent; blockiert bei zu wenig Bestand) -------------------
// Eine Menge eines Artikels nach FEFO aus freien eigenen Chargen abbuchen und als Verbrauch buchen.
function erp_fefo_abbuchen(int $pa_id, int $item_id, float $menge, string $einheit): void {
    $rest = $menge;
    $auf = (int) scalar("SELECT COALESCE(auftrag_id,0) FROM produktionsauftrag WHERE id=?", [$pa_id]);
    foreach (all("SELECT id, menge_verfuegbar FROM charge
                  WHERE item_id=? AND status='frei' AND menge_verfuegbar>0 AND fremd_kunde_id IS NULL
                  ORDER BY (mhd IS NULL), mhd ASC, id ASC", [$item_id]) as $c) {
        if ($rest <= 0.0001) break;
        $nimm = min($rest, (float)$c['menge_verfuegbar']);
        $neu  = (float)$c['menge_verfuegbar'] - $nimm;
        q("UPDATE charge SET menge_verfuegbar=?, status=? WHERE id=?", [$neu, $neu <= 0.0001 ? 'leer' : 'frei', $c['id']]);
        q("INSERT INTO produktion_verbrauch (pa_id,item_id,charge_id,menge,einheit,angelegt) VALUES (?,?,?,?,?,?)",
          [$pa_id, $item_id, $c['id'], $nimm, $einheit, gmdate('Y-m-d H:i:s')]);
        erp_rollen_abbuchen((int)$c['id'], $nimm, $pa_id, $auf);
        $rest -= $nimm;
    }
}
// Etikettenrollen (charge_gebinde mit Inventarnummer) einer Charge um die entnommene Menge verringern – Spiegel von
// produktion_rollen_abbuchen() im Dashboard (Sub-App darf dessen schema.php nicht laden). Ohne Rollen: nichts.
function erp_rollen_abbuchen(int $charge_id, float $menge, int $pa_id, int $auftrag_id): void {
    if ($menge <= 0.0001 || !tabelle_da('charge_gebinde') || !erp_spalte_da('charge_gebinde', 'inventar_nr')) return;
    $rest = $menge;
    foreach (all("SELECT id, menge_verfuegbar FROM charge_gebinde WHERE charge_id=? AND inventar_nr IS NOT NULL AND status<>'leer' AND menge_verfuegbar>0
                  ORDER BY (pa_id=?) DESC, (auftrag_id=?) DESC, (status='angebrochen') DESC, nummer, id", [$charge_id, $pa_id, $auftrag_id]) as $g) {
        if ($rest <= 0.0001) break;
        $nimm = min($rest, (float)$g['menge_verfuegbar']);
        $neu  = (float)$g['menge_verfuegbar'] - $nimm;
        q("UPDATE charge_gebinde SET menge_verfuegbar=?, status=?, pa_id=COALESCE(pa_id,?), auftrag_id=COALESCE(auftrag_id,?) WHERE id=?",
          [$neu, $neu <= 0.0001 ? 'leer' : 'angebrochen', $pa_id ?: null, $auftrag_id ?: null, $g['id']]);
        $rest -= $nimm;
    }
}
// Bis zu wie vielen Packungen (kumuliert) soll Material verbraucht sein? null = die ganze Menge des Auftrags (Spiegel Dashboard).
function erp_ziel_pakete(int $pa_id, ?float $bis = null): float {
    $menge = (float) scalar("SELECT menge FROM produktionsauftrag WHERE id=?", [$pa_id]);
    return $bis === null ? $menge : max(0.0, min($menge, $bis));
}
function erp_verbraucht(int $pa_id, int $item_id): float {
    return $item_id > 0 ? (float) scalar("SELECT COALESCE(SUM(menge),0) FROM produktion_verbrauch WHERE pa_id=? AND item_id=?", [$pa_id, $item_id]) : 0.0;
}
// Mehrere Artikel EINES Schritts ALLES-ODER-NICHTS entnehmen (Spiegel von produktion_posten_entnehmen()) – als RESTBUCHUNG (S-13):
// $posten tragen die SOLL-Menge (kumuliert); gebucht wird „Soll − bereits für dieses PA verbraucht". Idempotent UND bucht nach einer
// Teilmenge den Rest. FEFO, optional Ersatzartikel ('alternativ'), $trocken = nur prüfen, Verlaufseintrag am Auftrag ($log).
function erp_posten_entnehmen(int $pa_id, array $posten, bool $trocken = false, bool $log = true): array {
    $pa = one("SELECT nummer, auftrag_id, kunde_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    $auf = (int)($pa['auftrag_id'] ?? 0);
    $plan = []; $fehlt = [];
    foreach ($posten as $p) {
        $iid = (int)$p['item_id']; $alt = (int)($p['alternativ'] ?? 0); $soll = (float)$p['menge'];
        if ($iid <= 0 || $soll <= 0) continue;
        $einheit = (string)($p['einheit'] ?? 'Stk') ?: 'Stk';
        $brauch = $soll - erp_verbraucht($pa_id, $iid) - ($alt > 0 && $alt !== $iid ? erp_verbraucht($pa_id, $alt) : 0.0);
        if ($brauch <= max(0.0001, $soll * 0.001)) continue;           // Soll schon (Haupt- oder Ersatzartikel) gebucht
        $verf = erp_item_bestand($iid); $nutze = $iid; $istAlt = false;
        if ($verf + 0.0001 < $brauch && $alt > 0 && erp_item_bestand($alt) + 0.0001 >= $brauch) { $nutze = $alt; $istAlt = true; }
        elseif ($verf + 0.0001 < $brauch) {
            $fehlt[] = ['name'=>(string)(($p['name'] ?? '') !== '' ? $p['name'] : scalar("SELECT name FROM item WHERE id=?", [$iid])), 'benoetigt'=>$brauch, 'verfuegbar'=>$verf, 'fehlt'=>$brauch-$verf, 'einheit'=>$einheit, 'item_id'=>$iid];
            continue;
        }
        $plan[] = ['item_id'=>$nutze, 'menge'=>$brauch, 'einheit'=>$einheit, 'label'=>(string)($p['label'] ?? ''), 'alt'=>$istAlt];
    }
    if ($fehlt) return ['ok'=>false, 'fehlt'=>$fehlt, 'gebucht'=>[]];
    if ($trocken) return ['ok'=>true, 'fehlt'=>[], 'gebucht'=>[]];
    $gebucht = [];
    foreach ($plan as $b) {
        erp_fefo_abbuchen($pa_id, (int)$b['item_id'], (float)$b['menge'], $b['einheit']);
        $gebucht[] = ['name'=>(string)scalar("SELECT name FROM item WHERE id=?", [(int)$b['item_id']]), 'menge'=>(float)$b['menge'], 'alternativ'=>$b['alt'], 'label'=>$b['label']];
    }
    if ($log && $gebucht && $pa && (int)($pa['kunde_id'] ?? 0) > 0) {
        $zeilen = array_map(fn($g) => number_format($g['menge'], 0, ',', '.') . ' Stk ' . ($g['label'] ?: 'Artikel') . ' „' . $g['name'] . '"' . ($g['alternativ'] ? ' (Ersatzartikel – Kundenetikett ohne Bestand, Altbestand)' : ''), $gebucht);
        erp_log_aktivitaet('kunde', (int)$pa['kunde_id'], 'team', 'Produktion ' . (string)$pa['nummer'] . ': abgebucht – ' . implode('; ', $zeilen) . '.', 'auftrag', 'auftrag', $auf ?: null);
    }
    return ['ok'=>true, 'fehlt'=>[], 'gebucht'=>$gebucht];
}
// Rohstoffe entnehmen (Station „Rohstoffe bereitstellen" und Teilmenge) – RESTBUCHUNG bis $bis Packungen (S-13), siehe erp_posten_entnehmen().
function erp_rohstoffe_entnehmen(int $pa_id, ?float $bis = null, bool $trocken = false): array {
    $menge = (float) scalar("SELECT menge FROM produktionsauftrag WHERE id=?", [$pa_id]);
    $f = $menge > 0 ? erp_ziel_pakete($pa_id, $bis) / $menge : 1.0;
    $posten = [];
    foreach (erp_materialbedarf($pa_id) as $b)
        $posten[] = ['item_id'=>(int)$b['item_id'], 'menge'=>(float)$b['benoetigt'] * $f, 'einheit'=>(string)$b['einheit'], 'label'=>'Rohstoff', 'name'=>(string)$b['name']];
    return erp_posten_entnehmen($pa_id, $posten, $trocken, false);
}
// Leerkapseln entnehmen (Station Verkapselung) – Restbuchung bis $bis Packungen.
function erp_kapseln_entnehmen(int $pa_id, ?float $bis = null, bool $trocken = false): array {
    $pa = one("SELECT menge, produkt_id, auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return ['ok'=>true, 'fehlt'=>[]];
    $kid = erp_produkt_leerkapsel_id((int)$pa['produkt_id']);
    if (!$kid) return ['ok'=>true, 'fehlt'=>[]];                       // kein/uneindeutiges Kapselprodukt -> nichts abbuchen
    return erp_posten_entnehmen($pa_id, [['item_id'=>(int)$kid, 'menge'=>erp_ziel_pakete($pa_id, $bis) * erp_stueck_je_packung($pa), 'einheit'=>'Stk', 'label'=>'Leerkapseln',
        'name'=>(string) scalar("SELECT name FROM item WHERE id=?", [$kid])]], $trocken, false);
}
// Rezeptur-ID eines Auftrags (pa.rezeptur_id oder produkt.rezeptur_id).
function erp_pa_rezeptur_id(int $pa_id): int {
    $pa = one("SELECT produkt_id, rezeptur_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return 0;
    $rid = (int)($pa['rezeptur_id'] ?: 0);
    if (!$rid && !empty($pa['produkt_id'])) $rid = (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [(int)$pa['produkt_id']]);
    return $rid;
}
// Einheit der zugekauften Bulkware eines PA (Spiegel von produktion_fertigware_einheit): Pulver/Granulat g,
// Flüssig/Gel ml (Soll = Packungen × Füllmenge je Packung), sonst Stück. Vorher fest „Stk" („600.000 Stk", St-13).
function erp_fertigware_einheit(int $pa_id): string {
    $rid = erp_pa_rezeptur_id($pa_id);
    $f = $rid ? (string) scalar("SELECT darreichungsform FROM rezeptur WHERE id=?", [$rid]) : '';
    return in_array($f, ['pulver', 'granulat'], true) ? 'g' : (in_array($f, ['fluessig', 'gel'], true) ? 'ml' : 'Stk');
}
// Fehlbestand im Werk -> Team-Benachrichtigung im Dashboard (Mails verschickt nur das Dashboard). Gleiche Meldung binnen 10 Min. nur einmal.
function erp_fehlbestand_melden(int $pa_id, string $station, string $msg): void {
    if (!tabelle_da('benachrichtigung')) return;
    $nr = (string) scalar("SELECT nummer FROM produktionsauftrag WHERE id=?", [$pa_id]);
    $betreff = 'Fehlbestand: ' . ($nr ?: 'PA #' . $pa_id) . ' – ' . $station;
    if ((int) scalar("SELECT COUNT(*) FROM benachrichtigung WHERE betreff=? AND erstellt > ?", [$betreff, gmdate('Y-m-d H:i:s', time() - 600)]) > 0) return;
    $jetzt = gmdate('Y-m-d H:i:s'); $link = '?p=produktionsauftrag&id=' . $pa_id;
    $hatArt = (bool) scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='benachrichtigung' AND column_name='art'");
    if ($hatArt) q("INSERT INTO benachrichtigung (betreff,text,link,art,wichtig,erstellt) VALUES (?,?,?,?,1,?)", [$betreff, $msg, $link, 'auftrag', $jetzt]);
    else         q("INSERT INTO benachrichtigung (betreff,text,link,erstellt) VALUES (?,?,?,?)", [$betreff, $msg, $link, $jetzt]);
}
// Zukauf „fertig" (St-24, Spiegel von produktion_zukauf_fertig_chargen): die zugekaufte Ware sind schon verkaufsfertige Packungen
// am Auftrag. Bereitstellen prüft sie nur (kein Verbrauch); eingelagert/zugeordnet werden sie im Dashboard (Lieferanten-MHD bleibt).
function erp_zukauf_fertig(int $pa_id): bool {
    return (string) scalar("SELECT zukauf_art FROM produktionsauftrag WHERE id=?", [$pa_id]) === 'fertig';
}
function erp_zukauf_fertig_chargen(int $pa_id, string $status = 'frei'): array {
    $pa = one("SELECT auftrag_id, produkt_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa || !$pa['auftrag_id']) return [];
    return all("SELECT c.id, c.charge_nr, c.menge_verfuegbar, c.einheit, c.item_id, i.name
                FROM charge c JOIN item i ON i.id=c.item_id
                WHERE c.auftrag_id=? AND i.kategorie='verkaufsfertig' AND i.produkt_id=? AND c.fremd_kunde_id IS NULL
                  AND c.status=? AND c.menge_verfuegbar>0
                ORDER BY (c.mhd IS NULL), c.mhd ASC, c.id ASC", [(int)$pa['auftrag_id'], (int)$pa['produkt_id'], $status]);
}
// Zugekaufte fertige Bulkware eines Auftrags: entweder direkt AM AUFTRAG gebucht oder als Bulk der REZEPTUR
// (Kunde koppelt die Bulkware an die Rezeptur, nicht zwingend an den einzelnen Auftrag). $status: frei|quarantaene.
function erp_fertigware_chargen(int $pa_id, string $status = 'frei'): array {
    $auf = (int) scalar("SELECT auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    $rid = erp_rezeptur_stamm(erp_pa_rezeptur_id($pa_id));   // Bulk-Artikel hängt an der Stammrezeptur (G5)
    if (!$auf && !$rid) return [];
    return all("SELECT c.id, c.charge_nr, c.menge_verfuegbar, c.einheit, c.item_id, i.name
                FROM charge c JOIN item i ON i.id=c.item_id
                WHERE i.kategorie='fertig' AND c.status=? AND c.menge_verfuegbar>0 AND c.fremd_kunde_id IS NULL
                  AND (c.auftrag_id=? OR (?>0 AND i.rezeptur_id=?))
                ORDER BY (c.mhd IS NULL), c.mhd ASC, c.id ASC", [$status, $auf, $rid, $rid]);
}

// Zugekaufte fertige Bulkware (Kategorie 'fertig') FEFO abbuchen (Auftrag ODER Rezeptur-Bulk) – Restbuchung bis $bis Packungen.
function erp_fertigware_entnehmen(int $pa_id, ?float $bis = null, bool $trocken = false): array {
    $pa = one("SELECT menge, produkt_id, auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return ['ok'=>true, 'fehlt'=>[]];
    if (erp_zukauf_fertig($pa_id)) {   // fertige Ware: nur prüfen, nicht verbrauchen (St-24)
        $soll = erp_ziel_pakete($pa_id, $bis);
        $verf = array_sum(array_map(fn($c)=> (float)$c['menge_verfuegbar'], erp_zukauf_fertig_chargen($pa_id, 'frei')));
        if ($verf + 0.0001 < $soll)
            return ['ok'=>false, 'fehlt'=>[['name'=>'Fertige Ware (Packungen)', 'benoetigt'=>$soll, 'verfuegbar'=>$verf, 'fehlt'=>$soll - $verf, 'einheit'=>'Pkg']]];
        return ['ok'=>true, 'fehlt'=>[]];
    }
    $ziel = erp_ziel_pakete($pa_id, $bis) * erp_stueck_je_packung($pa);
    if ($ziel <= 0) return ['ok'=>true, 'fehlt'=>[]];
    $chargen = erp_fertigware_chargen($pa_id, 'frei');
    $ids = array_values(array_unique(array_map('intval', array_column($chargen, 'item_id'))));
    $schon = 0.0; foreach ($ids as $iid) $schon += erp_verbraucht($pa_id, $iid);
    $benoetigt = $ziel - $schon;
    if ($benoetigt <= max(0.0001, $ziel * 0.001)) return ['ok'=>true, 'fehlt'=>[]];
    $verf = array_sum(array_map(fn($c)=> (float)$c['menge_verfuegbar'], $chargen));
    if ($verf + 0.0001 < $benoetigt)
        return ['ok'=>false, 'fehlt'=>[['name'=>'Fertige Bulkware', 'benoetigt'=>$benoetigt, 'verfuegbar'=>$verf, 'fehlt'=>$benoetigt-$verf, 'einheit'=>erp_fertigware_einheit($pa_id)]]];
    if ($trocken) return ['ok'=>true, 'fehlt'=>[]];
    $rest = $benoetigt;
    foreach ($chargen as $c) {
        if ($rest <= 0.0001) break;
        $nimm = min($rest, (float)$c['menge_verfuegbar']);
        $neu  = (float)$c['menge_verfuegbar'] - $nimm;
        q("UPDATE charge SET menge_verfuegbar=?, status=? WHERE id=?", [$neu, $neu <= 0.0001 ? 'leer' : 'frei', $c['id']]);
        q("INSERT INTO produktion_verbrauch (pa_id,item_id,charge_id,menge,einheit,angelegt) VALUES (?,?,?,?,?,?)",
          [$pa_id, (int)$c['item_id'], $c['id'], $nimm, (string)($c['einheit'] ?? '') ?: erp_fertigware_einheit($pa_id), gmdate('Y-m-d H:i:s')]);   // Einheit der Charge (Pulver g)
        $rest -= $nimm;
    }
    return ['ok'=>true, 'fehlt'=>[]];
}
// Behälter (Primärgebinde) UND Deckel entnehmen (1 je Packung). Der Behälter des AUFTRAGS hat Vorrang
// (PA → Auftrag → Produkt: bedruckter Kundenbehälter). Spiegel von produktion_verpackung_entnehmen(); Restbuchung bis $bis Packungen.
function erp_verpackung_entnehmen(int $pa_id, ?float $bis = null, bool $trocken = false): array {
    $pa = one("SELECT menge, produkt_id, auftrag_id, verpackung_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return ['ok'=>true, 'fehlt'=>[]];
    $vid = (int)($pa['verpackung_id'] ?? 0);
    if ($vid <= 0 && !empty($pa['auftrag_id'])) $vid = (int) scalar("SELECT COALESCE(verpackung_id,0) FROM auftrag WHERE id=?", [(int)$pa['auftrag_id']]);
    if ($vid <= 0) $vid = (int) (scalar("SELECT verpackung_id FROM produkt WHERE id=?", [(int)$pa['produkt_id']]) ?: 0);
    $did = (int) (scalar("SELECT verschluss_id FROM produkt WHERE id=?", [(int)$pa['produkt_id']]) ?: 0);
    $menge = erp_ziel_pakete($pa_id, $bis);
    $posten = [];
    if ($vid > 0) $posten[] = ['item_id'=>$vid, 'menge'=>$menge, 'label'=>'Verpackung'];
    if ($did > 0) $posten[] = ['item_id'=>$did, 'menge'=>$menge, 'label'=>'Deckel'];
    if (!$posten) return ['ok'=>true, 'fehlt'=>[]];                    // weder Gebinde noch Deckel definiert -> nichts zu tun
    return erp_posten_entnehmen($pa_id, $posten, $trocken);
}
// Generisches Maß-Etikett zu einem Behälter (Spiegel von etikett_id_fuer_behaelter()/passende_etiketten_fuer() im Dashboard):
// Endformat des Behälters (item.etikett_final, „B x H") gegen die generischen Etiketten (produkt_id IS NULL) auf ±2 mm.
function erp_generisches_etikett_fuer_behaelter(int $verp_id): int {
    if ($verp_id <= 0) return 0;
    $mass = function (?string $s): ?array {
        return ($s && preg_match('/(\d+(?:[.,]\d+)?)\s*[x×*]\s*(\d+(?:[.,]\d+)?)/iu', $s, $m)) ? [(float)str_replace(',', '.', $m[1]), (float)str_replace(',', '.', $m[2])] : null;
    };
    $ziel = $mass((string) scalar("SELECT etikett_final FROM item WHERE id=?", [$verp_id]));
    if (!$ziel) return 0;
    foreach (all("SELECT id, breite_mm, hoehe_mm, etikett_format FROM item
                  WHERE kategorie='verpackung' AND verpackung_rolle='etikett' AND gesperrt=0 AND produkt_id IS NULL ORDER BY name") as $e) {
        $m = ($e['breite_mm'] && $e['hoehe_mm']) ? [(float)$e['breite_mm'], (float)$e['hoehe_mm']] : $mass($e['etikett_format']);
        if ($m && abs($m[0] - $ziel[0]) <= 2.0 && abs($m[1] - $ziel[1]) <= 2.0) return (int)$e['id'];
    }
    return 0;
}
// Etikett entnehmen (1 je Packung): das KUNDENETIKETT dieses Auftrags; bei fehlendem Bestand Ersatz = das generische
// Maß-Etikett des Behälters (Altbestand, der vor dem Kundenetikett-Fix dort eingebucht wurde), falls dort genug liegt.
// Spiegel von produktion_etikett_entnehmen(); Restbuchung bis $bis Packungen.
function erp_etikett_entnehmen(int $pa_id, ?float $bis = null, bool $trocken = false): array {
    $pa = one("SELECT menge, auftrag_id, produkt_id, verpackung_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    $eid = erp_etikett_item_id($pa_id);
    if (!$pa || $eid <= 0) return ['ok'=>true, 'fehlt'=>[]];            // kein Etikett nötig/definiert
    $vid = (int)($pa['verpackung_id'] ?? 0);
    if ($vid <= 0 && !empty($pa['auftrag_id'])) $vid = (int) scalar("SELECT COALESCE(verpackung_id,0) FROM auftrag WHERE id=?", [(int)$pa['auftrag_id']]);
    if ($vid <= 0) $vid = (int) scalar("SELECT COALESCE(verpackung_id,0) FROM produkt WHERE id=?", [(int)$pa['produkt_id']]);
    $gen = erp_generisches_etikett_fuer_behaelter($vid);
    return erp_posten_entnehmen($pa_id, [['item_id'=>$eid, 'menge'=>erp_ziel_pakete($pa_id, $bis), 'label'=>'Etikett', 'alternativ'=>($gen !== $eid ? $gen : 0)]], $trocken);
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
// Den noch offenen Rest der Produktionsmenge als eigene Charge einbuchen. Hinweis: Beim normalen
// PA-Abschluss wird NICHT mehr direkt eingebucht, sondern per erp_an_lager_uebergeben() an das Lager
// übergeben (das Lager bucht ein). Diese Funktion bleibt als Baustein (Teilmengen/Sonderfälle) erhalten.
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
    // Fertigcharge an den Auftrag binden (S-09, Spiegel von produktion_teilmenge_einbuchen): auftrag_id + pa_id.
    // QM-Gate (St-11d): eigene Fertigware kommt in Quarantäne, frei erst nach der QM-Freigabe (wie im Dashboard).
    q("INSERT INTO charge (charge_nr,item_id,menge,menge_verfuegbar,einheit,mhd,wareneingang,status,notiz,pa_id,auftrag_id,angelegt)
       VALUES (?,?,?,?, 'Stk', ?, CURDATE(), 'quarantaene', ?, ?, ?, ?)",
      [$charge_nr, $item_id, $menge, $menge, erp_mhd_standard(), 'Aus Produktion ' . $pa['nummer'], $pa_id, ((int)($pa['auftrag_id'] ?? 0) ?: null), gmdate('Y-m-d H:i:s')]);
    return insert_id();
}

// Stationen (mit Material) dieses Auftrags in Reihenfolge – Grundlage für Prüfung und anteilige Abbuchung einer Teilmenge.
function erp_pa_stationen(int $pa_id): array {
    return array_values(array_unique(array_map(fn($r) => (string)$r['station'], all("SELECT station FROM produktion_schritt WHERE pa_id=? ORDER BY sort", [$pa_id]))));
}
// Teilmenge PRODUZIEREN (S-12, Spiegel von produktion_teilmenge_produzieren()): prüft Etikett-Freigabe und das Material ALLER Stationen
// (Rohstoffe, Leerkapseln, Behälter, Deckel, Etikett) für die Teilmenge, bucht es ANTEILIG nach FEFO ab (Restbuchung) und bucht die
// Fertigware als Charge. Alles-oder-nichts. Rückgabe ['ok','msg','fehlt','charge_nr','fehler'?].
function erp_teilmenge_produzieren(int $pa_id, float $m, string $akteur): array {
    $m = round($m);
    $rest = erp_produktion_rest($pa_id);
    if ($m <= 0) return ['ok'=>false, 'msg'=>'Bitte eine Menge größer 0 angeben.', 'fehlt'=>[]];
    if ($m > $rest + 1e-6) return ['ok'=>false, 'msg'=>'Es sind nur noch ' . number_format($rest, 0, ',', '.') . ' offen – mehr kann nicht produziert werden.', 'fehlt'=>[]];
    if ((string) scalar("SELECT status FROM produktionsauftrag WHERE id=?", [$pa_id]) === 'vorbereitung')
        return ['ok'=>false, 'fehler'=>'vorbereitung', 'msg'=>'Der Auftrag ist noch in Vorbereitung und nicht zur Produktion freigegeben.', 'fehlt'=>[]];
    if (($sp = erp_etikett_sperre($pa_id)) !== '') return ['ok'=>false, 'fehler'=>'etikett', 'msg'=>$sp, 'fehlt'=>[]];
    $bis = erp_produktion_gebucht($pa_id) + $m;           // Material soll kumuliert bis zu dieser Packungszahl verbraucht sein
    $stationen = erp_pa_stationen($pa_id);
    $fehlt = [];
    foreach ($stationen as $st) {                          // 1) alles prüfen …
        $r = erp_station_entnahme($pa_id, $st, $bis, true);
        if (!($r['ok'] ?? true)) foreach (($r['fehlt'] ?? []) as $f) $fehlt[] = $f;
    }
    if ($fehlt) return ['ok'=>false, 'fehler'=>'mangel', 'msg'=>'Nicht genug Material für diese Teilmenge.', 'fehlt'=>$fehlt];
    foreach ($stationen as $st) erp_station_entnahme($pa_id, $st, $bis, false);   // 2) … dann buchen
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
            'Teilmenge ' . number_format($m, 0, ',', '.') . ' Stk produziert (Charge ' . $nr . ', Produktion ' . $pa['nummer'] . ').',
            'auftrag', 'auftrag', (int)($pa['auftrag_id'] ?? 0));
    erp_teilmenge_anweisung_verrechnen($pa_id, $m, 'komplett');   // Soll-Kette 4b
    return ['ok'=>true, 'msg'=>'Teilmenge eingebucht (Charge ' . $nr . ').', 'charge_nr'=>$nr, 'fehlt'=>[]];
}
// Soll-Kette 4b: offene Anweisungen „Teilmenge produzieren“ (Aufgaben ref_typ 'teilmenge') – für die Werk-Liste bzw. einen PA.
function erp_teilmenge_anweisungen(int $pa_id = 0): array {
    if (!tabelle_da('aufgabe') || !erp_spalte_da('aufgabe', 'menge')) return [];
    $sql = "SELECT a.id, a.ref_id AS pa_id, a.menge, a.umfang, a.prio, pa.nummer, COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt
            FROM aufgabe a JOIN produktionsauftrag pa ON pa.id=a.ref_id LEFT JOIN produkt p ON p.id=pa.produkt_id
            WHERE a.ref_typ='teilmenge' AND a.status='offen' AND COALESCE(a.menge,0) > 0" . ($pa_id > 0 ? " AND a.ref_id=" . (int)$pa_id : "") . "
            ORDER BY a.prio, a.id";
    return all($sql);
}
// Gemeldete Teilmenge verrechnen (Spiegel von teilmenge_anweisung_verrechnen()): „komplett“ deckt auch „bis vor dem Etikett“.
function erp_teilmenge_anweisung_verrechnen(int $pa_id, float $m, string $umfang, int $uid = 0): void {
    $passt = $umfang === 'komplett' ? ['komplett', 'vor_etikett'] : ['vor_etikett'];
    foreach (erp_teilmenge_anweisungen($pa_id) as $a) {
        if ($m <= 0 || !in_array((string)$a['umfang'], $passt, true)) continue;
        $rest = (float)$a['menge'] - $m;
        if ($rest <= 0.0001) { q("UPDATE aufgabe SET status='erledigt', menge=0, erledigt_am=?, erledigt_von=? WHERE id=?", [gmdate('Y-m-d H:i:s'), $uid ?: null, (int)$a['id']]); $m = -$rest; }
        else { q("UPDATE aufgabe SET menge=? WHERE id=?", [(int) round($rest), (int)$a['id']]); $m = 0; }
    }
}
// ===== Soll-Kette 4: Schritt-Logik statt Auftrags-Freigabe (Nico/Dustin 04.11.2026) =====
// Packungen, die schon BIS VOR DEM ETIKETTIEREN produziert sind (abgefüllt, warten auf Etiketten), kumuliert am PA.
function erp_vor_etikett_stand(int $pa_id): float {
    if (!erp_spalte_da('produktionsauftrag', 'menge_vor_etikett')) return erp_produktion_gebucht($pa_id);
    return max(erp_produktion_gebucht($pa_id), (float) scalar("SELECT COALESCE(menge_vor_etikett,0) FROM produktionsauftrag WHERE id=?", [$pa_id]));
}
// Größte Packungszahl m (0..$max), für die das Material der Stationen $stat bis kumuliert $basis+m reicht (Trockenlauf, Binärsuche).
function erp_machbar_bis(int $pa_id, array $stat, float $basis, int $max): array {
    $ok = function (int $m) use ($pa_id, $stat, $basis): ?array {
        foreach ($stat as $st) { $r = erp_station_entnahme($pa_id, $st, $basis + $m, true); if (!($r['ok'] ?? true)) return $r['fehlt'] ?? [['name' => $st]]; }
        return null;
    };
    if ($max <= 0) return [0, ''];
    if ($ok($max) === null) return [$max, ''];                 // Abkürzung: alles machbar
    if (($f1 = $ok(1)) !== null) return [0, (string)($f1[0]['name'] ?? '')];   // Abkürzung: gar nichts machbar
    $lo = 1; $hi = $max - 1;
    while ($lo < $hi) { $mid = intdiv($lo + $hi + 1, 2); if ($ok($mid) === null) $lo = $mid; else $hi = $mid - 1; }
    return [$lo, $lo < $max ? (string)(($ok($lo + 1)[0]['name'] ?? '')) : ''];
}
// Was ist JETZT je Stufe machbar? herstellung = bis zur fertigen Kapsel/Mischung (Rohstoffe/Premix, Leerkapseln),
// vor_etikett = zusätzlich abgefüllt (Dosen/Deckel), komplett = fertig mit Etikett (nur mit Etikett-Freigabe).
// vorproduziert = schon bis vor dem Etikett produziert, wartet auf Etiketten.
function erp_stufen_machbar(int $pa_id): array {
    $gebucht = erp_produktion_gebucht($pa_id);
    $rest = (int) floor(erp_produktion_rest($pa_id));
    $ve = erp_vor_etikett_stand($pa_id);
    $vorprod = (int) round($ve - $gebucht);
    $offenVe = max(0, $rest - $vorprod);
    $st = erp_pa_stationen($pa_id);
    $ausbau = ['Verpacken', 'Etikettieren', 'Beipackzettel beilegen', 'Umkarton'];
    [$h, $hl] = erp_machbar_bis($pa_id, array_values(array_filter($st, fn($s) => !in_array($s, $ausbau, true))), $ve, $offenVe);
    [$v, $vl] = erp_machbar_bis($pa_id, array_values(array_filter($st, fn($s) => $s !== 'Etikettieren')), $ve, $offenVe);
    $sperre = erp_etikett_sperre($pa_id);
    [$k, $kl] = $sperre !== '' ? [0, ''] : erp_machbar_bis($pa_id, $st, $gebucht, $rest);
    $pa = one("SELECT * FROM produktionsauftrag WHERE id=?", [$pa_id]) ?: [];
    return ['rest' => $rest, 'vorproduziert' => $vorprod, 'herstellung' => $h, 'vor_etikett' => $v, 'komplett' => $k,
            'limit_h' => $hl, 'limit_v' => $vl, 'limit_k' => $kl, 'sperre' => $sperre, 'stk' => $pa ? erp_stueck_je_packung($pa) : 0];
}
// Klarer Hinweis für die Produktion, wie weit es geht ('' = alles machbar). $T = werk_texte().
function erp_machbar_hinweis(array $m, array $T): string {
    $n = fn($x) => number_format((float)$x, 0, ',', '.');
    $t = [];
    if ($m['vorproduziert'] > 0) $t[] = sprintf($T['hint_prelabeled'] ?? '%s Pkg bis vor dem Etikett fertig', $n($m['vorproduziert']));
    if ($m['komplett'] >= $m['rest']) return implode(' · ', $t);
    if ($m['komplett'] > 0) $t[] = sprintf($T['hint_only_full'] ?? 'nur %1$s von %2$s Pkg komplett machbar', $n($m['komplett']), $n($m['rest']));
    elseif ($m['vor_etikett'] > 0) $t[] = sprintf($m['sperre'] !== '' ? ($T['hint_label_not_released'] ?? 'Etikett nicht freigegeben – nur bis vor dem Etikettieren: %s Pkg') : ($T['hint_no_labels'] ?? 'keine Etiketten – nur bis vor dem Etikettieren: %s Pkg'), $n($m['vor_etikett']));
    elseif ($m['herstellung'] > 0) $t[] = sprintf($T['hint_only_production'] ?? 'keine Verpackung – nur Herstellung: %1$s Pkg (= %2$s Stk)', $n($m['herstellung']), $n($m['herstellung'] * max(1, (int)$m['stk'])));
    elseif ($m['vorproduziert'] <= 0) $t[] = $T['hint_no_material'] ?? 'kein Material';
    return implode(' · ', $t);
}
// Teilmenge BIS VOR DEM ETIKETTIEREN produzieren (Soll-Kette 4): Material aller Stationen außer Etikettieren anteilig nach FEFO
// abbuchen (Restbuchung), Stand am PA merken. Die Fertigware entsteht erst mit dem Etikett (erp_teilmenge_produzieren). Alles-oder-nichts.
function erp_teilmenge_vor_etikett(int $pa_id, float $m, string $akteur): array {
    $m = round($m);
    $st = one("SELECT status, nummer, kunde_id, auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$st) return ['ok' => false, 'msg' => 'Auftrag nicht gefunden.', 'fehlt' => []];
    if ((string)$st['status'] === 'vorbereitung') return ['ok' => false, 'msg' => 'Für diesen Auftrag ist noch kein Beschaffungsweg festgelegt.', 'fehlt' => []];
    if (!erp_spalte_da('produktionsauftrag', 'menge_vor_etikett')) return ['ok' => false, 'msg' => 'Bitte einmal das Dashboard öffnen (Datenbank-Spalte fehlt noch).', 'fehlt' => []];
    $mb = erp_stufen_machbar($pa_id);
    $offen = max(0, $mb['rest'] - $mb['vorproduziert']);
    if ($m <= 0) return ['ok' => false, 'msg' => 'Bitte eine Menge größer 0 angeben.', 'fehlt' => []];
    if ($m > $offen) return ['ok' => false, 'msg' => 'Es sind nur noch ' . number_format($offen, 0, ',', '.') . ' Pkg offen.', 'fehlt' => []];
    $ve = erp_vor_etikett_stand($pa_id);
    $stat = array_values(array_filter(erp_pa_stationen($pa_id), fn($s) => $s !== 'Etikettieren'));
    $fehlt = [];
    foreach ($stat as $s) { $r = erp_station_entnahme($pa_id, $s, $ve + $m, true); if (!($r['ok'] ?? true)) foreach (($r['fehlt'] ?? []) as $f) $fehlt[] = $f; }
    if ($fehlt) return ['ok' => false, 'fehler' => 'mangel', 'msg' => 'Nicht genug Material für diese Teilmenge.', 'fehlt' => $fehlt];
    foreach ($stat as $s) erp_station_entnahme($pa_id, $s, $ve + $m, false);
    q("UPDATE produktionsauftrag SET menge_vor_etikett=?, status=IF(status='offen','laufend',status) WHERE id=?", [(int) round($ve + $m), $pa_id]);
    erp_reservierung_abgleichen($pa_id);
    if (!empty($st['kunde_id']))
        erp_log_aktivitaet('kunde', (int)$st['kunde_id'], 'team', number_format($m, 0, ',', '.') . ' Pkg bis vor dem Etikettieren produziert (Produktion ' . $st['nummer'] . ', ' . $akteur . ').', 'auftrag', 'auftrag', (int)($st['auftrag_id'] ?? 0));
    erp_teilmenge_anweisung_verrechnen($pa_id, $m, 'vor_etikett');
    return ['ok' => true, 'msg' => number_format($m, 0, ',', '.') . ' Pkg bis vor dem Etikettieren gebucht.', 'fehlt' => []];
}
// Teilproduktion-Rechner (Werk-/Prod-App): wie viele Packungen sind JETZT komplett machbar (alle Stationen inkl. Etikett) und wie viele
// bis vor dem Etikettieren? Exakt per Trockenlauf der Stationsentnahmen (Binärsuche über 0..Rest) – dieselbe Prüfung wie beim Buchen.
// Rückgabe ['rest','komplett','vor_etikett','limit'(Name des knappsten Materials für „komplett"),'sperre'(string)].
function erp_teilmenge_machbar(int $pa_id): array {
    $rest = (int) floor(erp_produktion_rest($pa_id));
    $gebucht = erp_produktion_gebucht($pa_id);
    $stationen = erp_pa_stationen($pa_id);
    $maxFuer = function (array $stat) use ($pa_id, $rest, $gebucht): array {
        $ok = function (int $m) use ($pa_id, $gebucht, $stat): ?array {
            foreach ($stat as $st) { $r = erp_station_entnahme($pa_id, $st, $gebucht + $m, true); if (!($r['ok'] ?? true)) return $r['fehlt'] ?? [['name'=>$st]]; }
            return null;
        };
        $lo = 0; $hi = $rest;
        while ($lo < $hi) { $mid = intdiv($lo + $hi + 1, 2); if ($ok($mid) === null) $lo = $mid; else $hi = $mid - 1; }
        $lim = $lo < $rest ? ($ok($lo + 1)[0]['name'] ?? '') : '';
        return [$lo, (string)$lim];
    };
    [$komplett, $limit] = $maxFuer($stationen);
    [$vorEt] = $maxFuer(array_values(array_filter($stationen, fn($s) => $s !== 'Etikettieren')));
    return ['rest'=>$rest, 'komplett'=>$komplett, 'vor_etikett'=>max($vorEt, $komplett), 'limit'=>$limit, 'sperre'=>erp_etikett_sperre($pa_id)];
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
      [erp_naechste_nummer('VF'), $name, 'verkaufsfertig', 'Stk', 'Stk', $produkt_id]);
    return insert_id();
}
// Stammrezeptur (G5): ALLE Varianten einer Rezeptur teilen EINEN Bulk-Artikel – wie rezeptur_stamm_id() im Dashboard.
// Ohne das fand die App bei einer Variante keinen bzw. legte einen zweiten Bulk-Artikel an (Soll-Kette 2c, 04.11.2026).
function erp_rezeptur_stamm(int $rezeptur_id): int {
    if ($rezeptur_id <= 0) return 0;
    try { $s = (int) scalar("SELECT stamm_rezeptur_id FROM rezeptur WHERE id=?", [$rezeptur_id]); } catch (Throwable $e) { $s = 0; }
    return ($s > 0 && $s !== $rezeptur_id) ? $s : $rezeptur_id;
}
function erp_rezeptur_bulkitem(int $rezeptur_id): ?int {
    if ($rezeptur_id <= 0) return null;
    $rezeptur_id = erp_rezeptur_stamm($rezeptur_id);   // Anlage und Suche an der Stammrezeptur, nie ein zweiter Bulk-Artikel je Variante
    $id = scalar("SELECT id FROM item WHERE rezeptur_id=? AND kategorie='fertig' LIMIT 1", [$rezeptur_id]);
    if ($id) return (int)$id;
    $rz = one("SELECT name, darreichungsform FROM rezeptur WHERE id=?", [$rezeptur_id]);
    if (!$rz) return null;
    $einheit = ($rz['darreichungsform'] === 'pulver') ? 'g' : (in_array($rz['darreichungsform'], ['fluessig','gel'], true) ? 'ml' : 'Stk');
    q("INSERT INTO item (artikelnummer,name,kategorie,form,einheit,preis_bezug,rezeptur_id) VALUES (?,?,?,?,?,?,?)",
      [erp_naechste_nummer('BULK'), $rz['name'] . ' – Bulk', 'fertig', (string)$rz['darreichungsform'], $einheit, $einheit, $rezeptur_id]);
    return insert_id();
}
// Teil 4 (Schritt 5): Restmischung ins Lager 1 einbuchen – die übrige gemischte Bulkware (in kg) als Charge des
// Rezeptur-Bulk-Artikels anlegen, mit der Mischcharge-Nummer (CH-…, sonst PR-Charge-Nr) und Standard-MHD.
// Status 'frei' (eigene Produktion, direkt wiederverwendbar), Standort Lager 1. Rückgabe ['ok','charge_nr','mhd','msg'].
function erp_restmischung_einbuchen(int $pa_id, float $menge_kg, string $von = ''): array {
    if ($pa_id <= 0 || $menge_kg <= 0 || !tabelle_da('charge')) return ['ok'=>false, 'msg'=>'Bitte eine Menge größer 0 angeben.'];
    $pa = one("SELECT id, produkt_id, rezeptur_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return ['ok'=>false, 'msg'=>'Auftrag nicht gefunden.'];
    $rid = (int)($pa['rezeptur_id'] ?? 0);
    if (!$rid && !empty($pa['produkt_id'])) $rid = (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [(int)$pa['produkt_id']]);
    if (!$rid) return ['ok'=>false, 'msg'=>'Dem Auftrag ist keine Rezeptur zugeordnet.'];
    $bulk = (int) (erp_rezeptur_bulkitem($rid) ?? 0);
    if (!$bulk) return ['ok'=>false, 'msg'=>'Kein Bulk-Artikel zur Rezeptur.'];
    $mc = erp_prod_charge_fuer_pa($pa_id);
    $chargeNr = ($mc && !empty($mc['nummer'])) ? (string)$mc['nummer'] : erp_charge_naechste_nr($pa_id);
    $mhd = erp_mhd_standard();
    $notiz = 'Restmischung eingebucht' . ($von !== '' ? ' (' . $von . ')' : '');
    $jetzt = gmdate('Y-m-d H:i:s');
    if (erp_spalte_da('charge', 'standort'))
        q("INSERT INTO charge (charge_nr,item_id,menge,menge_verfuegbar,einheit,mhd,wareneingang,status,standort,pa_id,notiz,angelegt)
           VALUES (?,?,?,?,?,?,CURDATE(),'frei','lager1',?,?,?)",
          [$chargeNr, $bulk, $menge_kg, $menge_kg, 'kg', $mhd, $pa_id, $notiz, $jetzt]);
    else
        q("INSERT INTO charge (charge_nr,item_id,menge,menge_verfuegbar,einheit,mhd,wareneingang,status,pa_id,notiz,angelegt)
           VALUES (?,?,?,?,?,?,CURDATE(),'frei',?,?,?)",
          [$chargeNr, $bulk, $menge_kg, $menge_kg, 'kg', $mhd, $pa_id, $notiz, $jetzt]);
    $cid = (int) insert_id();
    if (function_exists('bedarf_bump')) bedarf_bump();
    return ['ok'=>true, 'charge_id'=>$cid, 'charge_nr'=>$chargeNr, 'menge'=>$menge_kg, 'mhd'=>$mhd, 'msg'=>'Restmischung eingebucht'];
}
function erp_auftrag_ist_fulfillment(int $auftrag_id): bool {
    if ($auftrag_id <= 0) return false;
    return (bool) scalar("SELECT k.nutzt_fulfillment FROM auftrag a JOIN kunden k ON k.id=a.kunde_id WHERE a.id=?", [$auftrag_id]);
}
// Einlager-Ziel eines Auftrags: Fulfillment-Kunde -> Lager 2 (Fremdlager), sonst Lager 1 (Warenlager).
// Spiegelt einlager_ziel_fuer_pa() im Dashboard – beide Seiten müssen dasselbe Ziel bestimmen.
function erp_einlager_ziel(int $pa_id): array {
    $pa = one("SELECT auftrag_id, produkt_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    $ff = false;
    if ($pa) {
        if (!empty($pa['auftrag_id'])) $ff = erp_auftrag_ist_fulfillment((int)$pa['auftrag_id']);
        if (!$ff && !empty($pa['produkt_id'])) $ff = (bool) scalar("SELECT k.nutzt_fulfillment FROM produkt p JOIN kunden k ON k.id=p.kunde_id WHERE p.id=?", [(int)$pa['produkt_id']]);
    }
    return $ff ? ['ziel'=>'lager2', 'label'=>'Lager 2 (Fremdlager)'] : ['ziel'=>'lager1', 'label'=>'Lager 1 (Warenlager)'];
}
// Produktion an das Lager übergeben: legt eine Lager-Aufgabe „Einlagern … → Lager 1/2" an. Idempotent je PA.
// Spiegelt produktion_an_lager_uebergeben() im Dashboard (gleiche Tabelle aufgabe, gleicher ref_typ='einlagern').
// WICHTIG: Die Produktion bucht die Fertigware NICHT mehr selbst ein – das macht das Lager beim Einlagern.
function erp_an_lager_uebergeben(int $pa_id): int {
    if (!tabelle_da('aufgabe')) return 0;
    if ((int) scalar("SELECT COUNT(*) FROM aufgabe WHERE ref_typ='einlagern' AND ref_id=? AND status='offen'", [$pa_id]) > 0) return 0;
    $pa = one("SELECT pa.nummer, pa.auftrag_id, pa.menge, a.nummer AS auftrag_nr,
                      COALESCE(NULLIF(a.produkt_bezeichnung,''), p.name, rz.name) AS produkt, k.firma AS kunde
               FROM produktionsauftrag pa LEFT JOIN auftrag a ON a.id=pa.auftrag_id
               LEFT JOIN produkt p ON p.id=pa.produkt_id LEFT JOIN rezeptur rz ON rz.id=COALESCE(pa.rezeptur_id, p.rezeptur_id)
               LEFT JOIN kunden k ON k.id=pa.kunde_id WHERE pa.id=?", [$pa_id]);
    if (!$pa) return 0;
    $ziel  = erp_einlager_ziel($pa_id);
    $titel = 'Einlagern: ' . ($pa['produkt'] ?: ('PR ' . $pa['nummer'])) . ' → ' . $ziel['label'];
    $besch = trim(($pa['auftrag_nr'] ? 'Auftrag ' . $pa['auftrag_nr'] . ' · ' : '') . 'PR ' . $pa['nummer']
           . ($pa['kunde'] ? ' · ' . $pa['kunde'] : '') . ' · Menge ' . (int)$pa['menge']
           . '. Fertige Ware bitte in ' . $ziel['label'] . ' buchen.');
    q("INSERT INTO aufgabe (titel,beschreibung,prio,zugewiesen_an,erstellt_von,faellig,ref_typ,ref_id,angelegt)
       VALUES (?,?,?,?,?,?,?,?,?)",
      [$titel, $besch ?: null, 2, null, null, null, 'einlagern', $pa_id, gmdate('Y-m-d H:i:s')]);
    return (int) insert_id();
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
    // Hochzählen + Lesen unter derselben DB-Sperre wie naechste_nummer() im Dashboard (Aufräumen 16): keine Doppelnummern.
    $nrLock = (int) scalar("SELECT GET_LOCK(?, 5)", ['bx_nr_' . $prefix]) === 1;
    try {
        q("UPDATE nummernkreis SET naechste = naechste + 1 WHERE prefix = ?", [$prefix]);
        $r = one("SELECT naechste - 1 AS nr, stellen FROM nummernkreis WHERE prefix = ?", [$prefix]);
    } finally {
        if ($nrLock) scalar("SELECT RELEASE_LOCK(?)", ['bx_nr_' . $prefix]);
    }
    return $prefix . '-' . str_pad((string)$r['nr'], (int)$r['stellen'], '0', STR_PAD_LEFT);
}
function erp_log_aktivitaet(string $objekt_typ, int $objekt_id, string $akteur, string $text,
                            string $typ = '', string $ref_typ = '', int $ref_id = 0): void {
    if (!tabelle_da('aktivitaet')) return;
    q("INSERT INTO aktivitaet (objekt_typ,objekt_id,akteur,typ,text,ref_typ,ref_id,erstellt) VALUES (?,?,?,?,?,?,?,?)",
      [$objekt_typ, $objekt_id, $akteur, $typ ?: null, $text, $ref_typ ?: null, $ref_id ?: null, gmdate('Y-m-d H:i:s')]);
}

// =============================================================================================
// PRODUKTIONS-CHARGE CH/CHE (Spec 7.5 + 16) – RAW-SQL-NAHT zur Dashboard-Tabelle prod_charge.
//
// WICHTIG: Das Dashboard besitzt die Tabellen prod_charge / prod_charge_rohstoff und eigene Helfer in
// core/schema.php (prod_charge_anlegen etc.). Wir dürfen core/schema.php NICHT einbinden (db()-Kollision).
// Darum spiegeln wir die Schreib-/Lese-Logik hier per Raw-SQL. Diese Entität ist ZUSÄTZLICH zur
// Fertigprodukt-Charge (charge.charge_nr .A/.B bleibt unangetastet). CH… = intern gemischt, CHE… = extern.
// Nummernkreis identisch zum Dashboard (erp_naechste_nummer('CH'|'CHE') -> CH-2692).
// =============================================================================================

// Haupt-Produktionscharge eines Auftrags (jüngste ohne parent). Null, wenn noch keine existiert.
function erp_prod_charge_fuer_pa(int $pa_id): ?array {
    if ($pa_id <= 0 || !tabelle_da('prod_charge')) return null;
    return one("SELECT * FROM prod_charge WHERE pa_id=? AND parent_id IS NULL ORDER BY id DESC LIMIT 1", [$pa_id]);
}
// Neue HAUPT-Produktionscharge anlegen. $d['typ']='extern' -> CHE…, sonst CH…. Rückgabe ['id','nummer'].
function erp_prod_charge_anlegen(array $d): array {
    if (!tabelle_da('prod_charge')) return ['id'=>0, 'nummer'=>''];
    $typ    = (($d['typ'] ?? 'intern') === 'extern') ? 'extern' : 'intern';
    $nummer = erp_naechste_nummer($typ === 'extern' ? 'CHE' : 'CH');
    q("INSERT INTO prod_charge (nummer,typ,pa_id,rezeptur_id,produkt_id,gebinde,menge,einheit,mitarbeiter_id,maschine_id,status,tag,notiz)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
      [$nummer, $typ, $d['pa_id'] ?? null, $d['rezeptur_id'] ?? null, $d['produkt_id'] ?? null,
       mb_substr(trim((string)($d['gebinde'] ?? '')), 0, 80) ?: null,
       ($d['menge'] ?? null), mb_substr(trim((string)($d['einheit'] ?? '')), 0, 20) ?: null,
       ($d['mitarbeiter_id'] ?? null), ($d['maschine_id'] ?? null),
       mb_substr((string)($d['status'] ?? 'offen'), 0, 30), ($d['tag'] ?? gmdate('Y-m-d')), ($d['notiz'] ?? null)]);
    return ['id'=>insert_id(), 'nummer'=>$nummer];
}
// Untercharge zu einer Hauptcharge (je Gebinde/Tag/Mitarbeiter). sub_kennung automatisch A/B/C… wenn leer.
function erp_prod_charge_sub_anlegen(int $parent_id, array $d = []): array {
    if ($parent_id <= 0 || !tabelle_da('prod_charge')) return ['id'=>0, 'nummer'=>''];
    $p = one("SELECT nummer, typ, pa_id, rezeptur_id, produkt_id FROM prod_charge WHERE id=?", [$parent_id]);
    if (!$p) return ['id'=>0, 'nummer'=>''];
    $sub = strtoupper(trim((string)($d['sub_kennung'] ?? '')));
    if ($sub === '') { $n = (int) scalar("SELECT COUNT(*) FROM prod_charge WHERE parent_id=?", [$parent_id]); $sub = ($n < 26) ? chr(ord('A') + $n) : ('X' . ($n + 1)); }
    $nummer = $p['nummer'] . '-' . $sub;
    q("INSERT INTO prod_charge (nummer,typ,parent_id,sub_kennung,pa_id,rezeptur_id,produkt_id,gebinde,menge,einheit,mitarbeiter_id,maschine_id,status,tag,notiz)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
      [$nummer, $p['typ'], $parent_id, $sub, $p['pa_id'], $p['rezeptur_id'], $p['produkt_id'],
       mb_substr(trim((string)($d['gebinde'] ?? '')), 0, 80) ?: null,
       ($d['menge'] ?? null), mb_substr(trim((string)($d['einheit'] ?? '')), 0, 20) ?: null,
       ($d['mitarbeiter_id'] ?? null), ($d['maschine_id'] ?? null),
       mb_substr((string)($d['status'] ?? 'offen'), 0, 30), ($d['tag'] ?? gmdate('Y-m-d')), ($d['notiz'] ?? null)]);
    return ['id'=>insert_id(), 'nummer'=>$nummer];
}
// Maschine an einer Produktionscharge festhalten (Spec 9.1).
function erp_prod_charge_maschine(int $prod_charge_id, int $maschine_id): void {
    if ($prod_charge_id <= 0 || $maschine_id <= 0 || !tabelle_da('prod_charge')) return;
    q("UPDATE prod_charge SET maschine_id=? WHERE id=?", [$maschine_id, $prod_charge_id]);
}
// Einen eingesetzten Rohstoff(-Batch) mit einer Produktionscharge verknüpfen (Spec 7.5). batch_nr = Hersteller-Batch.
function erp_prod_charge_rohstoff_verknuepfen(int $prod_charge_id, array $d): int {
    if ($prod_charge_id <= 0 || !tabelle_da('prod_charge_rohstoff')) return 0;
    $batch = trim((string)($d['batch_nr'] ?? ''));
    if ($batch === '' && !empty($d['charge_id'])) $batch = (string) scalar("SELECT charge_nr FROM charge WHERE id=?", [(int)$d['charge_id']]);
    q("INSERT INTO prod_charge_rohstoff (prod_charge_id,item_id,charge_id,batch_nr,menge,einheit,erfasst_von)
       VALUES (?,?,?,?,?,?,?)",
      [$prod_charge_id, ($d['item_id'] ?? null), ($d['charge_id'] ?? null), mb_substr($batch, 0, 80) ?: null,
       ($d['menge'] ?? null), mb_substr(trim((string)($d['einheit'] ?? '')), 0, 20) ?: null,
       mb_substr(trim((string)($d['erfasst_von'] ?? '')), 0, 190) ?: null]);
    return insert_id();
}
// ===== Proben / Rueckstellmuster (Spec 8, 3-stufig) – Raw-SQL auf der Dashboard-Tabelle prod_probe =====
// Ebenen: rohstoff (je eingesetztem Rohstoff-Batch) | gebinde (je Gebinde) | endprodukt (Rueckstellmuster) | labor.
function erp_proben_fuer_pa(int $pa_id): array {
    if ($pa_id <= 0 || !tabelle_da('prod_probe')) return [];
    return all("SELECT p.*, i.name AS item_name FROM prod_probe p LEFT JOIN item i ON i.id=p.item_id
                WHERE p.pa_id=? ORDER BY FIELD(p.ebene,'rohstoff','gebinde','endprodukt','labor'), p.id", [$pa_id]);
}
function erp_probe_anlegen(array $d): int {
    if (!tabelle_da('prod_probe')) return 0;
    $ebenen = ['rohstoff', 'gebinde', 'endprodukt', 'labor'];
    q("INSERT INTO prod_probe (pa_id,prod_charge_id,item_id,charge_id,ebene,batch_nr,anzahl,bezeichnung,etikett_gedruckt,labor,erfasst_von)
       VALUES (?,?,?,?,?,?,?,?,?,?,?)",
      [($d['pa_id'] ?? null), ($d['prod_charge_id'] ?? null), ($d['item_id'] ?? null), ($d['charge_id'] ?? null),
       in_array($d['ebene'] ?? '', $ebenen, true) ? $d['ebene'] : 'endprodukt',
       mb_substr(trim((string)($d['batch_nr'] ?? '')), 0, 80) ?: null,
       (isset($d['anzahl']) && $d['anzahl'] !== '') ? (int)$d['anzahl'] : null,
       mb_substr(trim((string)($d['bezeichnung'] ?? '')), 0, 190) ?: null,
       !empty($d['etikett_gedruckt']) ? 1 : 0,
       mb_substr(trim((string)($d['labor'] ?? '')), 0, 190) ?: null,
       mb_substr(trim((string)($d['erfasst_von'] ?? '')), 0, 190) ?: null]);
    return insert_id();
}
function erp_probe_loeschen(int $id, int $pa_id): void {
    if ($id <= 0 || $pa_id <= 0 || !tabelle_da('prod_probe')) return;
    q("DELETE FROM prod_probe WHERE id=? AND pa_id=?", [$id, $pa_id]);
}
// Soll-Anzahl Endprodukt-Rueckstellmuster: max(5, Anzahl Gebinde). Anzahl Gebinde = erfasste Gebinde-Proben.
function erp_rueckstell_soll(int $pa_id): int {
    $geb = ($pa_id > 0 && tabelle_da('prod_probe')) ? (int) scalar("SELECT COUNT(*) FROM prod_probe WHERE pa_id=? AND ebene='gebinde'", [$pa_id]) : 0;
    return max(5, $geb);
}

// Schon mit dieser Charge verknüpfte Lager-Chargen (verhindert Doppel-Verknüpfung bei erneutem Lauf).
function erp_prod_charge_verknuepfte_chargen(int $prod_charge_id): array {
    if ($prod_charge_id <= 0 || !tabelle_da('prod_charge_rohstoff')) return [];
    return array_map('intval', array_column(
        all("SELECT DISTINCT charge_id FROM prod_charge_rohstoff WHERE prod_charge_id=? AND charge_id IS NOT NULL", [$prod_charge_id]), 'charge_id'));
}
// DAS WICHTIGSTE (Spec 7.5): Alle für diesen Auftrag verbrauchten Rohstoff-Chargen (produktion_verbrauch)
// als Rohstoff-Batches (batch_nr = charge.charge_nr) mit der Produktionscharge verknüpfen. Idempotent:
// bereits verknüpfte Lager-Chargen werden übersprungen. Rückgabe = Anzahl neu verknüpfter Batches.
function erp_prod_charge_rohstoffe_aus_verbrauch(int $pa_id, int $prod_charge_id, string $akteur = ''): int {
    if ($pa_id <= 0 || $prod_charge_id <= 0 || !tabelle_da('produktion_verbrauch')) return 0;
    $schon = erp_prod_charge_verknuepfte_chargen($prod_charge_id);
    $n = 0;
    foreach (all("SELECT v.item_id, v.charge_id, SUM(v.menge) AS menge, MAX(v.einheit) AS einheit, c.charge_nr
                  FROM produktion_verbrauch v LEFT JOIN charge c ON c.id=v.charge_id
                  WHERE v.pa_id=? GROUP BY v.item_id, v.charge_id, c.charge_nr", [$pa_id]) as $r) {
        $cid = (int)($r['charge_id'] ?? 0);
        if ($cid > 0 && in_array($cid, $schon, true)) continue;   // schon verknüpft
        erp_prod_charge_rohstoff_verknuepfen($prod_charge_id, [
            'item_id'=>(int)$r['item_id'], 'charge_id'=>$cid ?: null,
            'batch_nr'=>(string)($r['charge_nr'] ?? ''), 'menge'=>$r['menge'], 'einheit'=>(string)($r['einheit'] ?? ''),
            'erfasst_von'=>$akteur]);
        $n++;
    }
    return $n;
}
// Orchestrierung je Produktionsschritt: legt (falls nötig) die passende Produktionscharge an und verknüpft
// die eingesetzten Rohstoffe. Wird NACH dem erfolgreichen Schritt-Abschluss aus run.php aufgerufen.
// Rückgabe ['nummer'=>string,'neu'=>bool,'verknuepft'=>int] oder null (Station ohne Chargenbezug).
function erp_prod_charge_fuer_station(int $pa_id, string $station, int $maschine_id = 0, ?float $menge = null, string $akteur = ''): ?array {
    if (!tabelle_da('prod_charge')) return null;
    $pa = one("SELECT produkt_id, rezeptur_id, menge FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return null;
    $rid = erp_pa_rezeptur_id($pa_id);
    if ($station === 'Mischen') {
        $vorhanden = erp_prod_charge_fuer_pa($pa_id);
        if ($vorhanden && $vorhanden['typ'] === 'intern') { $pc = ['id'=>(int)$vorhanden['id'], 'nummer'=>(string)$vorhanden['nummer']]; $neu = false; }
        else { $pc = erp_prod_charge_anlegen(['typ'=>'intern', 'pa_id'=>$pa_id, 'rezeptur_id'=>$rid ?: null,
                       'produkt_id'=>$pa['produkt_id'] ?? null, 'menge'=>$menge, 'einheit'=>$menge !== null ? 'kg' : null,
                       'maschine_id'=>$maschine_id ?: null, 'status'=>'gemischt', 'notiz'=>'Mischcharge']); $neu = true; }
        if (!$pc['id']) return null;
        if ($maschine_id > 0) erp_prod_charge_maschine($pc['id'], $maschine_id);
        if ($menge !== null) q("UPDATE prod_charge SET menge=?, einheit='kg', status='gemischt' WHERE id=?", [$menge, $pc['id']]);
        $v = erp_prod_charge_rohstoffe_aus_verbrauch($pa_id, $pc['id'], $akteur);
        return ['nummer'=>$pc['nummer'], 'neu'=>$neu, 'verknuepft'=>$v];
    }
    if ($station === 'Fertigware bereitstellen') {
        // Zukauf: externe Charge CHE, die die bereitgestellte Fertigware-Charge als Batch hält.
        $vorhanden = erp_prod_charge_fuer_pa($pa_id);
        if ($vorhanden && $vorhanden['typ'] === 'extern') { $pc = ['id'=>(int)$vorhanden['id'], 'nummer'=>(string)$vorhanden['nummer']]; $neu = false; }
        else { $pc = erp_prod_charge_anlegen(['typ'=>'extern', 'pa_id'=>$pa_id, 'rezeptur_id'=>$rid ?: null,
                       'produkt_id'=>$pa['produkt_id'] ?? null, 'status'=>'fertig', 'notiz'=>'Zugekaufte Bulkware']); $neu = true; }
        if (!$pc['id']) return null;
        if ($maschine_id > 0) erp_prod_charge_maschine($pc['id'], $maschine_id);
        $v = erp_prod_charge_rohstoffe_aus_verbrauch($pa_id, $pc['id'], $akteur);
        return ['nummer'=>$pc['nummer'], 'neu'=>$neu, 'verknuepft'=>$v];
    }
    // Spätere Steps (Verkapselung/Abfüllen …): nur die Maschine an die bestehende Charge hängen.
    if ($maschine_id > 0) { $pc = erp_prod_charge_fuer_pa($pa_id); if ($pc) erp_prod_charge_maschine((int)$pc['id'], $maschine_id); }
    return null;
}

// --- Mischer-Kapazität & Rezeptur-Umrechnung (Spec 7.7) --------------------------------------
// Gesamt-Mischmasse eines Auftrags (kg, theoretisch) = Summe(menge_mg aller Zutaten) × Einheiten / 1e6.
// Mit $cap (kg je Gebinde) wird in Gebinde aufgeteilt: volle Gebinde à $cap, letztes mit dem Rest; je
// Gebinde die anteilige Rezeptur. Reine Berechnung (kein DB-Schreiben). Rückgabe:
// ['ok','total_kg','einheiten','anzahl','cap','gebinde'=>[['nr','kg','zutaten'=>[['name','kg']]]]].
function erp_mischer_plan(int $pa_id, float $cap = 0.0): array {
    $pa = one("SELECT menge, produkt_id, rezeptur_id, auftrag_id FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return ['ok'=>false];
    $rid = erp_pa_rezeptur_id($pa_id);
    if (!$rid) return ['ok'=>false];
    // Nur der noch offene REST wird gemischt (St-08); Pulver/Granulat nach Füllgewicht (Massenanteil), nicht nach Portionszahl.
    $restM = (float)$pa['menge'] * erp_rest_faktor($pa_id);
    $einheiten = erp_pa_ist_bulk($pa) ? (int)round($restM) : (int)round($restM) * erp_stueck_je_packung($pa);
    $zutaten = all("SELECT COALESCE(NULLIF(z.bezeichnung,''), i.name) AS name, z.menge_mg
                    FROM rezeptur_zutat z LEFT JOIN item i ON i.id=z.item_id
                    WHERE z.rezeptur_id=? ORDER BY z.sort, z.id", [$rid]);
    $total_mg = 0.0; foreach ($zutaten as $z) $total_mg += (float)$z['menge_mg'];
    $formM = (string) scalar("SELECT darreichungsform FROM rezeptur WHERE id=?", [$rid]);
    $pulver = !erp_pa_ist_bulk($pa) && in_array($formM, ['pulver', 'granulat'], true) && $total_mg > 0;
    if ($pulver) { $einheiten = (int)round($restM) * erp_stueck_je_packung($pa); $total_mg = 1.0; }   // g Füllgewicht; Zutat = Anteil an 1,0
    $total_kg = $pulver ? $einheiten / 1000.0 : $total_mg * $einheiten / 1e6;
    $out = ['ok'=>true, 'total_kg'=>$total_kg, 'einheiten'=>$einheiten, 'anzahl'=>0, 'cap'=>$cap, 'gebinde'=>[]];
    if ($cap <= 0 || $total_kg <= 0) return $out;
    $anzahl = (int) ceil($total_kg / $cap - 1e-9);
    $rest = $total_kg; $gebinde = [];
    for ($i = 1; $i <= $anzahl; $i++) {
        $kg = min($cap, $rest); $rest = max(0.0, $rest - $kg);
        $anteil = $total_kg > 0 ? $kg / $total_kg : 0.0;
        $zrows = [];
        $sumMgPulver = 0.0; if ($pulver) foreach ($zutaten as $zz) $sumMgPulver += (float)$zz['menge_mg'];
        foreach ($zutaten as $z) $zrows[] = ['name'=>(string)$z['name'], 'kg'=>($pulver
            ? ($sumMgPulver > 0 ? (float)$z['menge_mg'] / $sumMgPulver : 0.0) * $total_kg
            : ((float)$z['menge_mg'] * $einheiten / 1e6)) * $anteil];
        $gebinde[] = ['nr'=>$i, 'kg'=>$kg, 'zutaten'=>$zrows];
    }
    $out['anzahl'] = $anzahl; $out['gebinde'] = $gebinde;
    return $out;
}
// Gebinde-Unterchargen einer PA (beim Mischen angelegt), in FEFO-Reihenfolge (sub_kennung A,B,C = ältestes zuerst).
// Spec 7.8: beim Abfüllen führt das den Mitarbeiter – angefangenes/ältestes Gebinde zuerst durchziehen.
function erp_gebinde_unterchargen(int $pa_id): array {
    if ($pa_id <= 0 || !tabelle_da('prod_charge')) return [];
    $pc = erp_prod_charge_fuer_pa($pa_id);
    if (!$pc) return [];
    return all("SELECT id, nummer, sub_kennung, gebinde, menge, einheit, status FROM prod_charge
                WHERE parent_id=? ORDER BY (status='abgefuellt'), (status<>'angefangen'), sub_kennung, id", [(int)$pc['id']]);
}
// Status einer Gebinde-Untercharge setzen (nur Unterchargen): gemischt | angefangen | abgefuellt.
function erp_gebinde_status_setzen(int $sub_id, int $pa_id, string $status): bool {
    if ($sub_id <= 0 || $pa_id <= 0 || !in_array($status, ['gemischt', 'angefangen', 'abgefuellt'], true) || !tabelle_da('prod_charge')) return false;
    q("UPDATE prod_charge SET status=? WHERE id=? AND pa_id=? AND parent_id IS NOT NULL", [$status, $sub_id, $pa_id]);
    return true;
}

// Je Gebinde eine Untercharge unter der internen CH-Hauptcharge anlegen (Spec 7.7/7.8: ein Etikett je Gebinde).
// Legt nur an, wenn die Hauptcharge noch keine Unterchargen hat (idempotent). Rückgabe = Anzahl angelegter.
function erp_mischer_unterchargen_anlegen(int $pa_id, float $cap, string $akteur = ''): int {
    if ($cap <= 0) return 0;
    $pc = erp_prod_charge_fuer_pa($pa_id);
    if (!$pc || ($pc['typ'] ?? '') !== 'intern') return 0;
    if ((int) scalar("SELECT COUNT(*) FROM prod_charge WHERE parent_id=?", [(int)$pc['id']]) > 0) return 0;
    $plan = erp_mischer_plan($pa_id, $cap);
    if (empty($plan['ok']) || empty($plan['gebinde'])) return 0;
    $n = 0; $ges = (int)$plan['anzahl'];
    foreach ($plan['gebinde'] as $g) {
        $r = erp_prod_charge_sub_anlegen((int)$pc['id'], [
            'gebinde'=>'Gebinde ' . $g['nr'] . '/' . $ges . ' (' . rtrim(rtrim(number_format((float)$g['kg'], 3, ',', '.'), '0'), ',') . ' kg)',
            'menge'=>round((float)$g['kg'], 3), 'einheit'=>'kg', 'status'=>'gemischt']);
        if ($r['id']) $n++;
    }
    return $n;
}

// --- Mischen Eimer für Eimer (ein Etikett je Eimer; Rest-Rohstoff erst wenn ALLE Eimer fertig) -----
// Die gewählte Behältergröße (kg je Eimer) wird beim ersten Eimer festgeschrieben, damit die Anzahl stabil bleibt.
function erp_mischer_cap(int $pa_id): float {
    $d = function_exists('pr_daten') ? pr_daten($pa_id) : [];
    return (float) str_replace(',', '.', (string)($d['mischer_cap']['wert'] ?? '0'));
}
// Wie viele Eimer sind schon fertig? = Anzahl bereits angelegter Gebinde-Unterchargen.
function erp_mischer_fertig_anzahl(int $pa_id): int { return count(erp_gebinde_unterchargen($pa_id)); }

// Einen Eimer (Mischbehälter) abschließen: legt GENAU EINE Gebinde-Untercharge an (der Reihe nach).
// Rückgabe: ['ok','sub_id','nummer','nr','anzahl','alle_fertig','msg'].
function erp_mischer_eimer_abschliessen(int $pa_id, float $cap, int $eimer_nr, string $akteur = ''): array {
    if ($cap <= 0) $cap = erp_mischer_cap($pa_id);
    if ($cap <= 0) return ['ok'=>false, 'msg'=>'Keine Behältergröße eingegeben.'];
    if (erp_mischer_cap($pa_id) <= 0 && function_exists('pr_daten_setzen'))
        pr_daten_setzen($pa_id, 'mischer_cap', rtrim(rtrim(number_format($cap, 3, '.', ''), '0'), '.'), $akteur);
    $cap  = erp_mischer_cap($pa_id) ?: $cap;
    $plan = erp_mischer_plan($pa_id, $cap);
    if (empty($plan['ok']) || empty($plan['gebinde'])) return ['ok'=>false, 'msg'=>'Mischplan nicht berechenbar.'];
    $N    = (int)$plan['anzahl'];
    $done = erp_mischer_fertig_anzahl($pa_id);
    if ($done >= $N) return ['ok'=>false, 'msg'=>'Alle Eimer sind bereits fertig.', 'nr'=>$done, 'anzahl'=>$N, 'alle_fertig'=>true];
    if ($eimer_nr !== $done + 1) return ['ok'=>false, 'msg'=>'Dieser Eimer ist gerade nicht an der Reihe.', 'nr'=>$done, 'anzahl'=>$N, 'alle_fertig'=>false];
    // Mischcharge (Parent) sicherstellen + Rohstoffe verknüpfen (idempotent).
    erp_prod_charge_fuer_station($pa_id, 'Mischen', 0, round((float)$plan['total_kg'], 3), $akteur);
    $pc = erp_prod_charge_fuer_pa($pa_id);
    if (!$pc || ($pc['typ'] ?? '') !== 'intern') return ['ok'=>false, 'msg'=>'Mischcharge fehlt.', 'nr'=>$done, 'anzahl'=>$N, 'alle_fertig'=>false];
    $g  = $plan['gebinde'][$eimer_nr - 1] ?? null;
    if (!$g) return ['ok'=>false, 'msg'=>'Eimer unbekannt.', 'nr'=>$done, 'anzahl'=>$N, 'alle_fertig'=>false];
    $kg = round((float)$g['kg'], 3);
    $r  = erp_prod_charge_sub_anlegen((int)$pc['id'], [
        'gebinde'=>'Gebinde ' . $eimer_nr . '/' . $N . ' (' . rtrim(rtrim(number_format($kg, 3, ',', '.'), '0'), ',') . ' kg)',
        'menge'=>$kg, 'einheit'=>'kg', 'status'=>'gemischt']);
    $doneNeu = $done + 1;
    return ['ok'=>!empty($r['id']), 'sub_id'=>(int)($r['id'] ?? 0), 'nummer'=>(string)($r['nummer'] ?? ''),
            'nr'=>$eimer_nr, 'anzahl'=>$N, 'alle_fertig'=>($doneNeu >= $N),
            'msg'=>!empty($r['id']) ? '' : 'Gebinde konnte nicht angelegt werden.'];
}
