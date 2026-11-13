<?php
// Unterlagen-Regel Lieferanten (Paket 12.11.2026 Teil 4; seit 13.11.2026 Quarantäne-Sperre statt Bestell-Sperre, Regel Nico):
//  - Preis abgeben geht auch OHNE Unterlagen; der Preis trägt dann das Kennzeichen „Unterlagen fehlen“.
//  - Kalkulieren, Angebot erstellen und BESTELLEN gehen immer, auf jedem Weg (nur gelber Hinweis + Badge „Unterlagen fehlen“).
//    Beim Bestellen entsteht EINE Aufgabe „Unterlagen nachfordern“ je Bestellung (lu_nachfordern_aufgabe), die sich selbst erledigt.
//  - Die Ware darf eingebucht werden, bleibt aber in QUARANTÄNE (charge.unterlagen_fehlt=1), bis die Unterlage da ist
//    (lu_charge_nach_eingang). Freigabe vorher nur Admin mit Grund (lu_freigabe_sperre / charge_quarantaene_freigeben).
//    Kommt die Unterlage, bleibt die Charge in Quarantäne und die QM bekommt die Aufgabe „Unterlage da, Charge freigeben“ (lu_abgleich).
//  - Artikel ohne Unterlagenpflicht (Schrauben, Masken, Handschuhe …): item.kein_dokument=1 (nur Admin/QM, lu_kein_dokument_setzen).
//  - „versendet“ meldet der Lieferant erst mit der CoA je Charge (siehe bestellung_versand_coa_fehlt in bestellung_chargen.php).
// Was gebraucht wird:
//  - je eingesetztem Rohstoff (Katalog-Artikel) eine CoA ODER Spezifikation – liegt sie am Rohstoff schon vor, zählt sie;
//  - bei Fremdfertigung, Premix und Bulk zusätzlich eine Spezifikation des Gesamtprodukts VON DIESEM LIEFERANTEN
//    (dokument objekt_typ='rezeptur', objekt_id=Rezeptur, lieferant_id=Lieferant, typ spec).
// Der Stand wird immer frisch berechnet – lädt der Lieferant nach, verschwindet der Punkt sofort. Siehe lieferant_unterlagen.md.

// Liegt am Rohstoff eine CoA oder Spezifikation vor (egal von wem hochgeladen)? Seit 13.11.2026 zählt auch eine CoA/Spec, die an einer
// Bestellposition dieses Artikels hängt (Chargen & Dokumente, Bestell-Pop-up) – so wirkt „Unterlage nachgereicht“ auf jedem Weg.
function lu_rohstoff_hat_dok(int $item_id): bool {
    $c = &$GLOBALS['lu_cache_dok'];
    if (!isset($c[$item_id]))
        $c[$item_id] = (int) scalar("SELECT COUNT(*) FROM dokument WHERE objekt_typ='item' AND objekt_id=? AND typ IN ('spec','coa','beides','analyse')", [$item_id]) > 0
            || (int) scalar("SELECT COUNT(*) FROM dokument d JOIN bestellung_position bp ON bp.id=d.bestellung_position_id
                             WHERE d.objekt_typ='bestellung' AND bp.item_id=? AND d.typ IN ('spec','coa','beides')", [$item_id]) > 0;
    return $c[$item_id];
}
// Hat der Lieferant eine Spezifikation des Gesamtprodukts (Rezeptur) abgegeben?
function lu_produkt_spec(int $lieferant_id, int $rezeptur_id): bool {
    if ($lieferant_id <= 0 || $rezeptur_id <= 0) return true;
    return (int) scalar("SELECT COUNT(*) FROM dokument WHERE objekt_typ='rezeptur' AND objekt_id=? AND lieferant_id=? AND typ IN ('spec','beides')",
                        [$rezeptur_id, $lieferant_id]) > 0;
}
// Rezeptur zu einem Artikel: Bulk-Artikel (kategorie fertig) und Premix-Artikel hängen an rezeptur_id.
function lu_item_rezeptur(int $item_id): int {
    if ($item_id <= 0) return 0;
    $it = one("SELECT rezeptur_id, kategorie, form FROM item WHERE id=?", [$item_id]);
    if (!$it || empty($it['rezeptur_id'])) return 0;
    if ((string)$it['kategorie'] === 'fertig' || (string)$it['form'] === 'premix') return (int)$it['rezeptur_id'];
    return 0;
}
// Braucht dieser Artikel überhaupt Unterlagen? Verpackung, Etiketten, Verbrauchsmaterial nicht – und Artikel mit
// „kein Dokument nötig“ (item.kein_dokument=1, 13.11.2026) auch nicht.
function lu_item_pflichtig(int $item_id): bool {
    $c = &$GLOBALS['lu_cache_pfl'];
    if (isset($c[$item_id])) return $c[$item_id];
    try { $r = one("SELECT kategorie, COALESCE(kein_dokument,0) AS kd FROM item WHERE id=?", [$item_id]); }
    catch (\Throwable $e) { $r = one("SELECT kategorie, 0 AS kd FROM item WHERE id=?", [$item_id]); }   // Spalte noch nicht da
    return $c[$item_id] = $r && (int)$r['kd'] !== 1 && in_array((string)$r['kategorie'], ['rohstoff', 'fertig'], true);
}
// Zwischenspeicher je Aufruf leeren (nach Upload bzw. „kein Dokument nötig“ im selben Aufruf).
function lu_cache_leeren(): void { $GLOBALS['lu_cache_dok'] = []; $GLOBALS['lu_cache_pfl'] = []; }

// Fehlende Unterlagen für Lieferant + (Artikel ODER Rezeptur). Rückgabe: Liste von
// ['art'=>'roh'|'produkt', 'item_id'=>int, 'rezeptur_id'=>int, 'name'=>string, 'braucht'=>'coa_spec'|'spec']
// $ohne_lieferant=true: Vorschau ohne Lieferant (nur die Rohstoff-Unterlagen, die Spezifikation des Gesamtprodukts hängt am Lieferanten).
function lu_fehlt(int $lieferant_id, int $item_id = 0, int $rezeptur_id = 0, bool $ohne_lieferant = false): array {
    if ($lieferant_id <= 0 && !$ohne_lieferant) return [];
    if ($rezeptur_id <= 0 && $item_id > 0) {
        if (!lu_item_pflichtig($item_id)) return [];
        $rezeptur_id = lu_item_rezeptur($item_id);
    }
    $out = [];
    if ($rezeptur_id > 0) {
        foreach (all("SELECT DISTINCT z.item_id, i.name FROM rezeptur_zutat z JOIN item i ON i.id=z.item_id
                      WHERE z.rezeptur_id=? AND z.item_id>0 AND i.kategorie='rohstoff' ORDER BY i.name", [$rezeptur_id]) as $z)
            if (lu_item_pflichtig((int)$z['item_id']) && !lu_rohstoff_hat_dok((int)$z['item_id']))   // „kein Dokument nötig“ zählt nicht
                $out[] = ['art' => 'roh', 'item_id' => (int)$z['item_id'], 'rezeptur_id' => $rezeptur_id, 'name' => (string)$z['name'], 'braucht' => 'coa_spec'];
        if ($lieferant_id > 0 && !lu_produkt_spec($lieferant_id, $rezeptur_id)) {
            $rn = one("SELECT nummer, name FROM rezeptur WHERE id=?", [$rezeptur_id]);
            $out[] = ['art' => 'produkt', 'item_id' => 0, 'rezeptur_id' => $rezeptur_id,
                      'name' => $rn ? trim((string)$rn['name'] . ' (' . (string)$rn['nummer'] . ')') : 'Rezeptur ' . $rezeptur_id, 'braucht' => 'spec'];
        }
        return $out;
    }
    if ($item_id > 0 && !lu_rohstoff_hat_dok($item_id))
        $out[] = ['art' => 'roh', 'item_id' => $item_id, 'rezeptur_id' => 0, 'name' => (string) scalar("SELECT name FROM item WHERE id=?", [$item_id]), 'braucht' => 'coa_spec'];
    return $out;
}

// Was braucht eine Bestellposition? Artikel, sonst (Bulk-Zukauf ohne Artikel) die Rezeptur des Auftrags.
function lu_position_fehlt(int $lieferant_id, array $bp): array {
    $iid = (int)($bp['item_id'] ?? 0);
    if ($iid > 0) return lu_fehlt($lieferant_id, $iid);
    $aid = (int)($bp['auftrag_id'] ?? 0);
    if ($aid > 0 && preg_match('/^(Bulk|Premix):/u', (string)($bp['bezeichnung'] ?? ''))) {
        $a = one("SELECT a.rezeptur_id, p.rezeptur_id AS p_rez FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id WHERE a.id=?", [$aid]);
        $rid = (int)($a['rezeptur_id'] ?? 0) ?: (int)($a['p_rez'] ?? 0);
        if ($rid > 0) return lu_fehlt($lieferant_id, 0, $rid);
    }
    return [];
}

// Bestellung: fehlende Unterlagen über alle Positionen (eindeutig). Online-Bestellungen/ohne Lieferant: nichts.
function lu_bestellung_fehlt(int $bestellung_id): array {
    $b = one("SELECT lieferant_id, bestellart FROM bestellung WHERE id=?", [$bestellung_id]);
    $lid = (int)($b['lieferant_id'] ?? 0);
    if ($lid <= 0 || (string)($b['bestellart'] ?? '') === 'online') return [];
    $out = [];
    // Fable-3 T2.5 (13.11.2026): stornierte Positionen (rest_storniert_am) zählen für die Sperre nicht mit.
    $ohneStorno = (function_exists('column_exists') && column_exists('bestellung_position', 'rest_storniert_am')) ? " AND rest_storniert_am IS NULL" : "";
    foreach (all("SELECT item_id, auftrag_id, bezeichnung FROM bestellung_position WHERE bestellung_id=? AND menge>0" . $ohneStorno, [$bestellung_id]) as $bp)
        foreach (lu_position_fehlt($lid, $bp) as $f) $out[$f['art'] . ':' . $f['item_id'] . ':' . $f['rezeptur_id']] = $f;
    return array_values($out);
}

// Wurde für diese Bestellung schon eine Admin-Ausnahme (mit Grund) erteilt?
function lu_ausnahme(int $bestellung_id): string {
    if (!function_exists('column_exists') || !column_exists('bestellung', 'unterlagen_ausnahme')) return '';
    return (string) scalar("SELECT unterlagen_ausnahme FROM bestellung WHERE id=?", [$bestellung_id]);
}
function lu_ausnahme_setzen(int $bestellung_id, string $grund, string $wer): void {
    q("UPDATE bestellung SET unterlagen_ausnahme=? WHERE id=?", [mb_substr(trim($grund) . ' (' . $wer . ', ' . gmdate('d.m.Y') . ')', 0, 250), $bestellung_id]);
    log_aktivitaet('bestellung', $bestellung_id, 'team', 'Bestellen trotz fehlender Unterlagen (Admin-Ausnahme): ' . trim($grund), 'status');
}

// Bestell-Sperre entfällt (Regel Nico 13.11.2026: Unterlagen fehlen = Quarantäne-Sperre beim Wareneingang, keine Bestell-Sperre).
// Bleibt als Hülle für alte Aufrufer: immer '' (frei). Den Hinweis liefert lu_bestellen_hinweis().
function lu_bestellen_sperre(int $bestellung_id): string { return ''; }

// Gelber Hinweis beim Bestellen: '' = nichts fehlt. Text wie „Beim Lieferanten fehlen noch: CoA oder Spezifikation (HPMC Leerkapseln #1).
// Die Ware bleibt nach dem Eingang in Quarantäne, bis die Unterlage da ist.“
function lu_hinweis_text(array $f): string {
    if (!$f) return '';
    return 'Beim Lieferanten fehlen noch: ' . implode(', ', array_map(fn($x) => lu_braucht_text($x['braucht']) . ' (' . $x['name'] . ')', $f))
         . '. Die Ware bleibt nach dem Eingang in Quarantäne, bis die Unterlage da ist.';
}
function lu_bestellen_hinweis(int $bestellung_id): string { return lu_hinweis_text(lu_bestellung_fehlt($bestellung_id)); }
// Gelber Kasten nach dem Bestellen (Einkaufsliste, Liste „Bestellt“): $ids = „12,34“ (Bestell-IDs aus ?lu_hinweis=). '' wenn nichts fehlt.
function lu_hinweis_panel(string $ids): string {
    $o = '';
    foreach (array_unique(array_filter(array_map('intval', explode(',', $ids)))) as $bid) {
        $t = lu_bestellen_hinweis($bid);
        if ($t === '') continue;
        $nr = (string) scalar("SELECT nummer FROM bestellung WHERE id=?", [$bid]);
        $o .= '<div><a href="?p=bestellung&id=' . $bid . '#unterlagen">' . h($nr) . '</a>: ' . h($t) . '</div>';
    }
    return $o === '' ? '' : '<div class="bx-panel" style="border-left:3px solid #C9A227;background:rgba(201,162,39,.08);padding:12px 16px">' . $o
        . '<div class="muted" style="font-size:12px;margin-top:4px">Bestellt ist bestellt. Die Aufgabe „Unterlagen nachfordern“ ist angelegt; der Lieferant sieht den Punkt im Portal unter „Fehlende Unterlagen“.</div></div>';
}

// Zentrale Stelle für JEDEN Bestellweg („heute bestellt“/„Direkt bestellt“, „über Portal senden“, „als bestellt markieren“,
// Beschaffungsweg am Auftrag, Produktionsauftrag, Einkauf mobil, Team bestätigt). Sperrt nichts mehr (13.11.2026): legt nur die
// Aufgabe „Unterlagen nachfordern“ an, wenn etwas fehlt. Rückgabe immer '' (Signatur bleibt für alte Aufrufer).
function lu_bestellen_pruefen(int $bestellung_id, string $ausnahme_grund = '', string $wer = ''): string {
    lu_nachfordern_aufgabe($bestellung_id);
    return '';
}

// Aufgabe „Unterlagen nachfordern: L-xxxx, Artikel, BE-Nr.“ an den Einkauf (Team-Aufgabe, ref_typ bestellung). Höchstens EINE offene
// je Bestellung; erledigt sich in lu_abgleich() selbst, sobald nichts mehr fehlt. Keine Mail – der Lieferant sieht den Punkt im
// Portal unter „Fehlende Unterlagen“.
function lu_nachfordern_aufgabe(int $bestellung_id): void {
    try {
        if (!function_exists('aufgabe_neu')) return;
        $f = lu_bestellung_fehlt($bestellung_id);
        if (!$f) return;
        if ((int) scalar("SELECT COUNT(*) FROM aufgabe WHERE status='offen' AND ref_typ='bestellung' AND ref_id=? AND titel LIKE 'Unterlagen nachfordern%'", [$bestellung_id]) > 0) return;
        $b = one("SELECT b.nummer, b.lieferant_id, l.lieferantennummer FROM bestellung b LEFT JOIN lieferanten l ON l.id=b.lieferant_id WHERE b.id=?", [$bestellung_id]);
        if (!$b) return;
        $lnr = trim((string)($b['lieferantennummer'] ?? '')) ?: 'L-' . (int)$b['lieferant_id'];
        $art = implode(', ', array_unique(array_column($f, 'name')));
        aufgabe_neu(mb_substr('Unterlagen nachfordern: ' . $lnr . ', ' . $art . ', ' . $b['nummer'], 0, 190),
            'Beim Lieferanten fehlen noch: ' . lu_liste_text($f) . '. Die Ware bleibt nach dem Eingang in Quarantäne, bis die Unterlage da ist. '
            . 'Der Lieferant sieht den Punkt im Portal unter „Fehlende Unterlagen“. Die Aufgabe erledigt sich selbst, sobald die Unterlage da ist.',
            2, null, null, null, 'bestellung', $bestellung_id, '?p=bestellung&id=' . $bestellung_id . '#unterlagen');
    } catch (\Throwable $e) {}
}

// Alter Sperr-Entwurf (Fable-4 T3.1, bis 13.11.2026): Entwurf mit bestellung.unterlagen_wartet = 1 zählt nicht als unterwegs (bp_rest_sql)
// und steht in „Zu bestellen“ als „Entwurf - Unterlagen fehlen“. NEUE setzt niemand mehr (13.11.2026) – das Kennzeichen wird nur noch
// gelöscht: wenn die Bestellung kein Entwurf mehr ist oder nichts mehr fehlt. „Schon bestellt am …“ an der Bestellung löscht es auch.
function lu_entwurf_markieren(int $bestellung_id): void {
    try {
        $b = one("SELECT id, status, COALESCE(unterlagen_wartet,0) AS w FROM bestellung WHERE id=?", [$bestellung_id]);
        if (!$b || (int)$b['w'] !== 1) return;
        if ((string)$b['status'] !== 'offen' || !lu_bestellung_fehlt($bestellung_id)) q("UPDATE bestellung SET unterlagen_wartet=0 WHERE id=?", [$bestellung_id]);
    } catch (\Throwable $e) { /* Spalte fehlt noch: nichts tun */ }
}
// Alle Sperr-Entwürfe neu prüfen (Einkaufsliste, Liste „Bestellt“): Unterlagen nachgereicht oder Bestellung inzwischen gesendet.
function lu_entwuerfe_pruefen(): void {
    try { foreach (all("SELECT id FROM bestellung WHERE unterlagen_wartet=1") as $r) lu_entwurf_markieren((int)$r['id']); } catch (\Throwable $e) {}
}
// Sperr-Entwürfe mit Positionen für „Zu bestellen“ (Einkaufsliste).
function lu_sperr_entwuerfe(): array {
    try {
        return all("SELECT b.id, b.nummer, b.lieferant_id, COALESCE(l.firma,'') AS lieferant, bp.id AS pos_id, COALESCE(NULLIF(bp.bezeichnung,''), i.name, '') AS artikel,
                           bp.menge, COALESCE(NULLIF(bp.einheit,''), i.einheit, '') AS einheit, bp.auftrag_id, a.nummer AS auftrag_nr
                    FROM bestellung b JOIN bestellung_position bp ON bp.bestellung_id=b.id LEFT JOIN item i ON i.id=bp.item_id
                    LEFT JOIN lieferanten l ON l.id=b.lieferant_id LEFT JOIN auftrag a ON a.id=bp.auftrag_id
                    WHERE b.status='offen' AND b.unterlagen_wartet=1 AND bp.rest_storniert_am IS NULL ORDER BY b.id DESC, bp.sort, bp.id");
    } catch (\Throwable $e) { return []; }
}

// --- Quarantäne-Sperre beim Wareneingang (13.11.2026) ---
// Fehlende Unterlagen für eine Charge (Lieferant der Charge + Artikel). Eigene Fertigware (pa_id) und Ware ohne Lieferant: nichts.
function lu_charge_fehlt(int $charge_id): array {
    $c = one("SELECT item_id, lieferant_id, pa_id FROM charge WHERE id=?", [$charge_id]);
    if (!$c || (int)($c['lieferant_id'] ?? 0) <= 0 || !empty($c['pa_id'])) return [];
    return lu_fehlt((int)$c['lieferant_id'], (int)$c['item_id']);
}
// Nach jedem Wareneingang (wareneingang_buchen im Dashboard, erp_wareneingang_buchen in der Lager-App): fehlen Unterlagen, geht die
// Charge IMMER in Quarantäne (auch wenn sie sonst frei wäre) und trägt charge.unterlagen_fehlt=1. Rückgabe true = gesperrt.
function lu_charge_nach_eingang(int $charge_id): bool {
    if ($charge_id <= 0) return false;
    try {
        $f = lu_charge_fehlt($charge_id);
        if (!$f) return false;
        $st = (string) scalar("SELECT status FROM charge WHERE id=?", [$charge_id]);
        if ($st === 'frei') q("UPDATE charge SET status='quarantaene' WHERE id=? AND status='frei'", [$charge_id]);
        if (in_array($st, ['frei', 'quarantaene'], true)) {
            q("UPDATE charge SET unterlagen_fehlt=1 WHERE id=?", [$charge_id]);
            if (function_exists('log_aktivitaet'))
                log_aktivitaet('charge', $charge_id, 'team', 'Quarantäne: CoA/Spec des Lieferanten fehlt (' . lu_liste_text($f) . '). Freigabe erst, wenn die Unterlage da ist.', 'status');
        }
        return true;
    } catch (\Throwable $e) { return false; }
}
// Grund für die Anzeige an der Charge ('' = keiner): „Quarantäne: CoA/Spec des Lieferanten fehlt“.
function lu_charge_grund(int $charge_id): string {
    $st = (string) scalar("SELECT status FROM charge WHERE id=?", [$charge_id]);
    return $st === 'quarantaene' && lu_charge_fehlt($charge_id) ? 'Quarantäne: CoA/Spec des Lieferanten fehlt' : '';
}
// Freigabe-Sperre: '' = darf (fachlich), sonst der Grund. charge_quarantaene_freigeben() lehnt dann ab (Admin-Ausnahme mit Grund).
function lu_freigabe_sperre(int $charge_id): string {
    $f = lu_charge_fehlt($charge_id);
    return $f ? 'Freigabe erst, wenn die CoA/Spec da ist. Es fehlt: ' . lu_liste_text($f) . '.' : '';
}
// Zelle „freigeben“ für Freigabe-Listen (Dashboard): gesperrt -> roter Text + Knopf „Unterlage hochladen“ (Pop-up mit lu_panel) und
// für Admin „trotzdem freigeben“ mit Pflicht-Grund. '' = nicht gesperrt (Seite zeigt ihren normalen Knopf). $ziel = Seite, die
// aktion=lu_upload (lu_upload_post) und aktion=q_frei_admin (charge_quarantaene_freigeben mit Grund) verarbeitet.
function lu_freigabe_zelle(int $charge_id, string $ziel): string {
    $f = lu_charge_fehlt($charge_id);
    if (!$f) return '';
    $c = one("SELECT lieferant_id FROM charge WHERE id=?", [$charge_id]);
    $dlg = 'luq' . $charge_id;
    $o = '<div style="color:#8f231b;font-size:12px;max-width:260px;white-space:normal;text-align:left">Freigabe erst, wenn die CoA/Spec da ist.</div>'
       . '<div class="bx-row" style="gap:6px;justify-content:flex-end;flex-wrap:nowrap;margin-top:4px">'
       . '<button type="button" class="btn btn-primary btn-sm" onclick="bxPopup.open(\'' . $dlg . '\')">Unterlage hochladen</button>';
    if (function_exists('has_role') && has_role('admin'))
        $o .= '<button type="button" class="btn btn-ghost btn-sm" onclick="bxPopup.open(\'' . $dlg . 'a\')" title="Nur Admin: trotz fehlender Unterlage freigeben (Grund Pflicht, wird vermerkt)">trotzdem freigeben</button>';
    $o .= '</div><dialog id="' . $dlg . '" class="bx-dialog" style="max-width:720px"><h2>Unterlage hochladen</h2>'
        . lu_panel((int)$c['lieferant_id'], $ziel . '&lu_lief=' . (int)$c['lieferant_id'], 'de', ['liste' => $f], true)
        . '<div class="bx-pop-fuss"><button type="button" class="btn btn-ghost" onclick="bxPopup.close(\'' . $dlg . '\', true)">Schließen</button></div></dialog>';
    if (function_exists('has_role') && has_role('admin'))
        $o .= '<dialog id="' . $dlg . 'a" class="bx-dialog" style="max-width:520px"><h2>Trotzdem freigeben</h2><form method="post" action="' . h($ziel) . '">'
            . '<input type="hidden" name="aktion" value="q_frei_admin"><input type="hidden" name="charge_id" value="' . $charge_id . '">'
            . '<p class="muted" style="margin-top:0">' . h(lu_liste_text($f)) . ' fehlt. Die Freigabe wird mit Grund an der Charge und im Protokoll vermerkt.</p>'
            . '<div class="bx-field"><label>Grund</label><input type="text" name="grund" data-pflicht data-pflicht-text="Grund fehlt" required></div>'
            . '<div class="bx-pop-fuss"><button type="button" class="btn btn-ghost" onclick="bxPopup.close(\'' . $dlg . 'a\', true)">Abbrechen</button><button class="btn btn-primary" type="submit">Freigeben</button></div></form></dialog>';
    return $o;
}

// POST einer Freigabe-Liste (Freigaben, Wareneingang): aktion=lu_upload (Unterlage im Namen des Lieferanten aus ?lu_lief=) bzw.
// aktion=q_frei_admin (Admin gibt trotz fehlender Unterlage frei, Grund Pflicht). Rückgabe null = nicht zuständig, sonst
// ['ok'=>bool, 'msg'=>string, 'did'=>int]. Bedarf/Material-komplett macht die Seite.
function lu_freigabe_post(): ?array {
    $a = (string)($_POST['aktion'] ?? '');
    if ($a === 'lu_upload') {
        $lid = (int)($_GET['lu_lief'] ?? 0);
        if ($lid <= 0) return ['ok' => false, 'msg' => 'Lieferant fehlt.', 'did' => 0];
        $r = lu_upload_post($lid, 'team');
        return ['ok' => $r['ok'], 'msg' => $r['ok'] ? 'Unterlage gespeichert. Die Charge bleibt in Quarantäne – jetzt normal freigeben.' : 'Datei nicht gespeichert (PDF oder Bild).', 'did' => (int)$r['did']];
    }
    if ($a === 'q_frei_admin') {
        $grund = trim((string)($_POST['grund'] ?? ''));
        if ($grund === '') return ['ok' => false, 'msg' => 'Grund fehlt – nicht freigegeben.', 'did' => 0];
        $ok = charge_quarantaene_freigeben((int)($_POST['charge_id'] ?? 0), $grund);
        return ['ok' => $ok, 'msg' => $ok ? 'Charge trotz fehlender Unterlage freigegeben (Admin-Ausnahme vermerkt).' : ((string)($GLOBALS['bx_freigabe_fehler'] ?? '') ?: 'Nicht freigegeben.'), 'did' => 0];
    }
    return null;
}

// Abgleich nach neuen Unterlagen (Upload, „kein Dokument nötig“, Seitenaufruf Einkauf/Freigaben/Wareneingang/Aufgaben):
//  - Aufgaben „Unterlagen nachfordern“ erledigen, wenn an der Bestellung nichts mehr fehlt (oder sie storniert ist);
//  - Chargen mit unterlagen_fehlt=1: Unterlage da -> bleibt in Quarantäne, Kennzeichen 2 und Aufgabe an die QM
//    „Unterlage da, Charge freigeben: …“ (einmalig). Schon freigegeben/leer -> Kennzeichen 0.
function lu_abgleich(): void {
    try {
        foreach (all("SELECT a.id, a.ref_id, b.status FROM aufgabe a LEFT JOIN bestellung b ON b.id=a.ref_id
                      WHERE a.status='offen' AND a.ref_typ='bestellung' AND a.titel LIKE 'Unterlagen nachfordern%'") as $a)
            if ((string)$a['status'] === 'storniert' || $a['status'] === null || !lu_bestellung_fehlt((int)$a['ref_id']))
                q("UPDATE aufgabe SET status='erledigt', erledigt_am=?, erledigt_von=NULL WHERE id=? AND status='offen'", [gmdate('Y-m-d H:i:s'), (int)$a['id']]);
    } catch (\Throwable $e) {}
    try {
        foreach (all("SELECT c.id, c.status, c.charge_nr, c.menge_verfuegbar, i.name FROM charge c JOIN item i ON i.id=c.item_id WHERE c.unterlagen_fehlt=1") as $c) {
            $cid = (int)$c['id'];
            if ((string)$c['status'] !== 'quarantaene' || (float)$c['menge_verfuegbar'] <= 0) { q("UPDATE charge SET unterlagen_fehlt=0 WHERE id=?", [$cid]); continue; }
            if (lu_charge_fehlt($cid)) continue;
            q("UPDATE charge SET unterlagen_fehlt=2 WHERE id=? AND unterlagen_fehlt=1", [$cid]);
            $cnr = trim((string)$c['charge_nr']) ?: '#' . $cid;
            if (function_exists('aufgabe_neu')
                && (int) scalar("SELECT COUNT(*) FROM aufgabe WHERE status='offen' AND ref_typ='charge' AND ref_id=? AND titel LIKE 'Unterlage da%'", [$cid]) === 0)
                aufgabe_neu(mb_substr('Unterlage da, Charge freigeben: ' . $c['name'] . ', Charge ' . $cnr, 0, 190),
                    'Die CoA/Spec des Lieferanten ist da. Die Charge bleibt in Quarantäne, bis die QM sie normal freigibt (Freigaben).',
                    2, null, null, null, 'charge', $cid, '?p=freigaben');
            if (function_exists('log_aktivitaet')) log_aktivitaet('charge', $cid, 'team', 'Unterlage des Lieferanten nachgereicht – Charge bleibt in Quarantäne, QM gibt frei.', 'status');
        }
    } catch (\Throwable $e) {}
}

// --- „kein Dokument nötig“ (13.11.2026) ---
function lu_darf_kein_dokument(): bool { return function_exists('has_role') && has_role('labor'); }   // Admin oder QM
function lu_kein_dokument_setzen(int $item_id, bool $an, string $grund, string $wer): bool {
    if ($item_id <= 0) return false;
    q("UPDATE item SET kein_dokument=?, kein_dokument_grund=?, kein_dokument_von=? WHERE id=?",
      [$an ? 1 : 0, $an ? (mb_substr(trim($grund), 0, 250) ?: null) : null, $an ? mb_substr($wer . ', ' . gmdate('d.m.Y'), 0, 190) : null, $item_id]);
    if (function_exists('log_aktivitaet'))
        log_aktivitaet('item', $item_id, 'team', ($an ? 'Kein Dokument nötig gesetzt' : 'Kein Dokument nötig entfernt') . ' (' . $wer . ')' . ($an && trim($grund) !== '' ? ': ' . trim($grund) : '') . '.', 'status');
    if (function_exists('bedarf_bump')) bedarf_bump();
    lu_cache_leeren();
    lu_abgleich();
    return true;
}
// Knopf „kein Dokument nötig“ für Listen fehlender Unterlagen (öffnet das Pop-up aus lu_kein_dokument_dialog). '' ohne Recht.
function lu_kein_dokument_knopf(int $item_id, string $name, string $zurueck): string {
    if ($item_id <= 0 || !lu_darf_kein_dokument()) return '';
    return '<button type="button" class="btn btn-ghost btn-sm lu-kd" data-item="' . $item_id . '" data-name="' . h($name) . '" data-zurueck="' . h($zurueck) . '"'
         . ' title="Dieser Artikel braucht keine CoA/Spezifikation (z. B. Schrauben, Masken, Handschuhe)">kein Dokument nötig</button>' . lu_kein_dokument_dialog();
}
// Pop-up einmal je Seite (optionaler Grund). Postet an ?p=kein_dokument (module/einkauf/kein_dokument.php).
function lu_kein_dokument_dialog(): string {
    static $da = false;
    if ($da) return '';
    $da = true;
    return '<dialog id="dlgKeinDok" class="bx-dialog" style="max-width:520px"><h2>Kein Dokument nötig</h2>'
         . '<form method="post" action="?p=kein_dokument"><input type="hidden" name="item_id" id="kdItem"><input type="hidden" name="zurueck" id="kdZurueck"><input type="hidden" name="an" value="1">'
         . '<p style="margin-top:0"><span id="kdName" style="font-weight:600"></span> braucht keine CoA/Spezifikation (z. B. Schrauben, Masken, Handschuhe). Der Punkt verschwindet sofort, auch im Lieferantenportal; die Ware wird normal eingebucht.</p>'
         . '<div class="bx-field"><label>Grund (optional)</label><input type="text" name="grund" maxlength="250" placeholder="z. B. Betriebsmittel, kein Lebensmittelkontakt"></div>'
         . '<div class="bx-pop-fuss"><button type="button" class="btn btn-ghost" onclick="bxPopup.close(\'dlgKeinDok\', true)">Abbrechen</button><button class="btn btn-primary" type="submit">Speichern</button></div></form></dialog>'
         . '<script>document.addEventListener("click",function(e){var b=e.target.closest&&e.target.closest(".lu-kd");if(!b)return;'
         . 'document.getElementById("kdItem").value=b.dataset.item;document.getElementById("kdZurueck").value=b.dataset.zurueck||"";'
         . 'document.getElementById("kdName").textContent=b.dataset.name||"";bxPopup.open("dlgKeinDok");});</script>';
}

function lu_braucht_text(string $b, string $sprache = 'de'): string {
    $t = ['coa_spec' => ['de' => 'CoA oder Spezifikation', 'en' => 'CoA or specification', 'zh' => 'CoA 或规格书'],
          'spec'     => ['de' => 'Spezifikation des Gesamtprodukts', 'en' => 'specification of the finished product', 'zh' => '成品规格书']];
    return $t[$b][$sprache] ?? $t[$b]['en'];
}
function lu_liste_text(array $f, string $sprache = 'de'): string {
    return implode(', ', array_map(fn($x) => $x['name'] . ' (' . lu_braucht_text($x['braucht'], $sprache) . ')', $f));
}

// Kennzeichen für Preis-Anzeigen: kleines Badge „Unterlagen fehlen“ mit Liste im Tooltip ('' = vollständig).
function lu_badge(int $lieferant_id, int $item_id = 0, int $rezeptur_id = 0, string $sprache = 'de'): string {
    $f = lu_fehlt($lieferant_id, $item_id, $rezeptur_id);
    if (!$f) return '';
    $txt = ['de' => 'Unterlagen fehlen', 'en' => 'Documents missing', 'zh' => '缺少文件'][$sprache] ?? 'Documents missing';
    return ' <span class="badge badge-warn" title="' . h(lu_liste_text($f, $sprache)) . '">' . h($txt) . '</span>';
}

// Alle offenen Punkte eines Lieferanten (Aufgabenliste im Portal): aus seinen Anfragen, Preisen und Fertigprodukt-Preisen.
// Rückgabe: Liste wie lu_fehlt, je Eintrag zusätzlich 'quelle' (Anfrage-Nummer bzw. Preis) – eindeutig je Unterlage.
function lu_offen_lieferant(int $lieferant_id): array {
    if ($lieferant_id <= 0) return [];
    $quellen = [];
    if (table_exists('lieferant_anfrage'))
        foreach (all("SELECT id, nummer, item_id, rezeptur_id FROM lieferant_anfrage WHERE lieferant_id=? AND COALESCE(status,'') NOT IN ('zurueckgezogen','abgelehnt','storniert') ORDER BY id DESC LIMIT 200", [$lieferant_id]) as $a)
            $quellen[] = [(int)$a['item_id'], (int)$a['rezeptur_id'], 'Anfrage ' . (string)($a['nummer'] ?: $a['id']), '?p=lieferant_anfrage&id=' . (int)$a['id']];
    if (table_exists('lieferant_preis'))
        foreach (all("SELECT DISTINCT item_id FROM lieferant_preis WHERE lieferant_id=? AND item_id IS NOT NULL", [$lieferant_id]) as $p)
            $quellen[] = [(int)$p['item_id'], 0, 'Preis', '?p=lieferant_preisliste'];
    if (table_exists('rezeptur_lief_angebot'))
        foreach (all("SELECT DISTINCT rezeptur_id FROM rezeptur_lief_angebot WHERE lieferant_id=? AND rezeptur_id IS NOT NULL", [$lieferant_id]) as $p)
            $quellen[] = [0, (int)$p['rezeptur_id'], 'Fertigprodukt-Preis', '?p=lieferant_rezepturpreise'];
    // 13.11.2026: Bestellungen (die der Lieferant sieht) – laufende sowie gelieferte, deren Charge wegen der Unterlage in Quarantäne wartet.
    try {
        foreach (all("SELECT b.id, b.nummer FROM bestellung b WHERE b.lieferant_id=? AND b.status NOT IN ('offen','storniert')
                        AND (b.status<>'geliefert' OR EXISTS (SELECT 1 FROM charge c JOIN bestellung_position bp ON bp.id=c.bestellung_position_id
                                                              WHERE bp.bestellung_id=b.id AND c.unterlagen_fehlt=1))
                      ORDER BY b.id DESC LIMIT 100", [$lieferant_id]) as $b)
            foreach (lu_bestellung_fehlt((int)$b['id']) as $f) {
                $k = $f['art'] . ':' . $f['item_id'] . ':' . $f['rezeptur_id'];
                if (!isset($bq[$k])) $bq[$k] = $f + ['quellen' => []];
                $bq[$k]['quellen']['Bestellung ' . $b['nummer']] = '?p=lieferant_bestellung&id=' . (int)$b['id'];
            }
    } catch (\Throwable $e) {}
    $out = $bq ?? [];
    foreach ($quellen as [$iid, $rid, $q, $link])
        foreach (lu_fehlt($lieferant_id, $iid, $rid) as $f) {
            $k = $f['art'] . ':' . $f['item_id'] . ':' . $f['rezeptur_id'];
            if (!isset($out[$k])) $out[$k] = $f + ['quellen' => []];
            $out[$k]['quellen'][$q] = $link;
        }
    return array_values($out);
}

// Upload aus der Aufgabenliste (Portal oder intern): Rohstoff -> am Rohstoff (CoA/Spec), Gesamtprodukt -> Spec an der Rezeptur.
// Rückgabe Dokument-ID (0 = nichts).
function lu_upload(int $lieferant_id, string $feld, string $art, int $item_id, int $rezeptur_id, string $typ, string $von = 'lieferant'): int {
    require_once __DIR__ . '/lieferant_dateien.php';
    if ($art === 'produkt') {
        $did = lieferant_unterlage_speichern($lieferant_id, $feld, 'spec', 'rezeptur', $rezeptur_id, 'Spezifikation Gesamtprodukt');
    } else {
        $did = lieferant_unterlage_speichern($lieferant_id, $feld, in_array($typ, ['coa', 'spec'], true) ? $typ : 'spec', 'item', $item_id);
    }
    if ($did && $von === 'team') q("UPDATE dokument SET hochgeladen_von='team' WHERE id=?", [$did]);
    if ($did && function_exists('bedarf_bump')) bedarf_bump();
    if ($did) { lu_cache_leeren(); lu_abgleich(); }   // 13.11.2026: Aufgabe „Unterlagen nachfordern“ erledigt sich, Chargen -> QM-Aufgabe
    return $did;
}

// Aufgabenliste „Diese Unterlagen fehlen noch“ (Portal und intern), mit Upload direkt in der Zeile.
// $nur: optional Filter auf eine Quelle (z. B. ['item_id'=>…, 'rezeptur_id'=>…] einer Anfrage). $sprache de/en/zh.
// Das Formular postet aktion=lu_upload an $ziel (Seite verarbeitet über lu_upload_post()).
function lu_panel(int $lieferant_id, string $ziel, string $sprache = 'de', ?array $nur = null, bool $team = false): string {
    $t = fn(string $de, string $en, string $zh) => $sprache === 'de' ? $de : ($sprache === 'zh' ? $zh : $en);
    $liste = isset($nur['liste']) ? (array)$nur['liste'] : ($nur ? lu_fehlt($lieferant_id, (int)($nur['item_id'] ?? 0), (int)($nur['rezeptur_id'] ?? 0)) : lu_offen_lieferant($lieferant_id));
    if (!$liste) return '';
    $o = '<div class="bx-panel" style="margin:4px 0 14px;padding:12px 14px;border-color:#e7c27a">'
       . '<div style="font-weight:600;margin-bottom:2px">' . h($t('Diese Unterlagen fehlen noch', 'These documents are still missing', '以下文件仍缺失')) . '</div>'
       . '<div class="muted" style="font-size:12px;margin-bottom:10px">' . h($team ? 'Unterlage hier im Namen des Lieferanten nachreichen (z. B. per Mail erhalten) – sie zählt sofort.' : $t(
            'Bitte fehlende Unterlagen hochladen, sonst bleibt die Ware bei uns gesperrt. Je Rohstoff genügt eine CoA oder Spezifikation; für das Gesamtprodukt brauchen wir Ihre Spezifikation.',
            'Please upload missing documents, otherwise the goods remain blocked at our site. One CoA or specification per raw material is enough; for the finished product we need your specification.',
            '请上传缺少的文件，否则货物将在我方被冻结。每种原料提供一份 CoA 或规格书即可；成品需要您的规格书。')) . '</div>'
       . '<div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>' . h($t('Unterlage', 'Document', '文件')) . '</th><th>' . h($t('für', 'for', '用于')) . '</th><th></th></tr></thead><tbody>';
    foreach ($liste as $i => $f) {
        $quellen = '';
        foreach (($f['quellen'] ?? []) as $q => $link) $quellen .= ($quellen ? ', ' : '') . '<a href="' . h($link) . '">' . h($q) . '</a>';
        $o .= '<tr><td style="min-width:200px">' . h($f['name']) . '<div class="muted" style="font-size:12px">' . h(lu_braucht_text($f['braucht'], $sprache)) . '</div></td>'
            . '<td class="muted" style="font-size:12px">' . ($quellen ?: '–') . '</td><td>'
            . '<form method="post" action="' . h($ziel) . '" enctype="multipart/form-data" class="bx-row" style="gap:8px;flex-wrap:nowrap;margin:0">'
            . '<input type="hidden" name="aktion" value="lu_upload"><input type="hidden" name="lu_art" value="' . h($f['art']) . '">'
            . '<input type="hidden" name="lu_item" value="' . (int)$f['item_id'] . '"><input type="hidden" name="lu_rez" value="' . (int)$f['rezeptur_id'] . '">'
            . ($f['art'] === 'roh'
                ? '<select name="lu_typ"><option value="coa">CoA</option><option value="spec">' . h($t('Spezifikation', 'Specification', '规格书')) . '</option></select>'
                : '<input type="hidden" name="lu_typ" value="spec">')
            . '<input type="file" name="lu_datei" accept="application/pdf,image/*" required>'
            . '<button class="bx-btn" type="submit">' . h($t('Hochladen', 'Upload', '上传')) . '</button></form>'
            . ($team && $f['art'] === 'roh' ? '<div style="margin-top:4px">' . lu_kein_dokument_knopf((int)$f['item_id'], (string)$f['name'], $ziel) . '</div>' : '') . '</td></tr>';
    }
    return $o . '</tbody></table></div></div>';
}

// POST aus lu_panel verarbeiten. Rückgabe ['ok'=>bool, 'did'=>int]. KI-Auswertung danach (wie beim Preisangebot).
// Darf dieser Lieferant zu diesem Artikel / dieser Rezeptur eine Unterlage hochladen? (Nachbesserung 13.11.2026)
// Nur, was in SEINER offenen Unterlagen-Liste steht: Aufgabenliste (Anfragen, Preise, Fertigprodukt-Preise) plus die fehlenden
// Unterlagen seiner laufenden Bestellungen. Sonst könnte ein Lieferant über lu_item/lu_rez Dateien an fremde Artikel hängen.
function lu_upload_erlaubt(int $lieferant_id, string $art, int $item_id, int $rezeptur_id): bool {
    if ($lieferant_id <= 0) return false;
    $liste = lu_offen_lieferant($lieferant_id);
    foreach (all("SELECT id FROM bestellung WHERE lieferant_id=? AND status NOT IN ('geliefert','storniert') ORDER BY id DESC LIMIT 200", [$lieferant_id]) as $b)
        foreach (lu_bestellung_fehlt((int)$b['id']) as $f) $liste[] = $f;
    foreach ($liste as $f) {
        if ($art === 'roh' && $f['art'] === 'roh' && (int)$f['item_id'] === $item_id) return true;
        if ($art === 'produkt' && $f['art'] === 'produkt' && (int)$f['rezeptur_id'] === $rezeptur_id) return true;
    }
    return false;
}
function lu_upload_post(int $lieferant_id, string $von = 'lieferant'): array {
    $art = (string)($_POST['lu_art'] ?? '') === 'produkt' ? 'produkt' : 'roh';
    $iid = (int)($_POST['lu_item'] ?? 0); $rid = (int)($_POST['lu_rez'] ?? 0);
    if ($art === 'roh' && $iid <= 0) return ['ok' => false, 'did' => 0];
    if ($art === 'produkt' && $rid <= 0) return ['ok' => false, 'did' => 0];
    if ($von !== 'team' && !lu_upload_erlaubt($lieferant_id, $art, $iid, $rid)) {   // Team (intern) darf weiter frei ablegen
        if (function_exists('log_aktivitaet')) {
            try { log_aktivitaet('lieferant', $lieferant_id, 'lieferant',
                                 'Unterlagen-Upload abgelehnt: ' . ($art === 'roh' ? 'Artikel #' . $iid : 'Rezeptur #' . $rid) . ' steht nicht in der offenen Unterlagen-Liste dieses Lieferanten', 'status'); } catch (\Throwable $e) {}
        }
        return ['ok' => false, 'did' => 0, 'abgelehnt' => true];
    }
    $did = lu_upload($lieferant_id, 'lu_datei', $art, $iid, $rid, (string)($_POST['lu_typ'] ?? 'spec'), $von);
    return ['ok' => $did > 0, 'did' => $did];
}
// Nach der Antwort an den Browser: KI liest die neue Unterlage (bulkify-Format), Rohstoff-Entwurf wird aktiv.
function lu_upload_nachlauf(int $did): void {
    if ($did <= 0) return;
    if (function_exists('ki_antwort_abschliessen')) ki_antwort_abschliessen();
    try {
        require_once __DIR__ . '/spec_ki.php';
        $d = one("SELECT objekt_typ, objekt_id FROM dokument WHERE id=?", [$did]);
        if ($d && $d['objekt_typ'] === 'item') {   // Spezifikation des Gesamtprodukts (rezeptur) wird nur abgelegt, nicht in Stammdaten gelesen
            spec_ki_nach_upload($did);
            if (function_exists('rohstoff_entwurf_aktivieren')) rohstoff_entwurf_aktivieren((int)$d['objekt_id']);
        }
    } catch (\Throwable $e) {}
}
