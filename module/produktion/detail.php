<?php
// Produktionsauftrag – Stationen/Gates der Reihe nach abarbeiten
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/item_link.php';   // Material anklickbar (Nico 12.11.2026)
// Material-Tabelle (Nico 12.11.2026: „auf das Produkt kommen und sehen, wann es bestellt wurde“): je Zeile die Bestellungen.
// Artikel: Bestellungen dieses Auftrags + offene Bestellungen des Artikels. Zukauf-Bulk (ohne Lagerartikel): die Bulk-Bestellungen
// des Auftrags (Freitext-Position bzw. Fertigware-Artikel). Rückgabe: Liste von Bestellzeilen (neueste zuerst, höchstens 3).
function pa_material_bestellungen(int $auftrag_id, int $item_id, int $produkt_id): array {
    $sel = "SELECT DISTINCT b.id, b.nummer, b.status, b.station, b.bestelldatum, b.eta_geplant, b.angekommen_am, b.bestaetigt, l.firma
            FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id LEFT JOIN lieferanten l ON l.id=b.lieferant_id WHERE b.status<>'storniert' AND ";
    if ($item_id > 0)
        return all($sel . "bp.item_id=? AND (bp.auftrag_id=? OR (b.status NOT IN ('geliefert') AND b.angekommen_am IS NULL)) ORDER BY b.id DESC LIMIT 3", [$item_id, $auftrag_id]);
    if ($auftrag_id <= 0) return [];
    return all($sel . "bp.auftrag_id=? AND (bp.item_id IS NULL OR bp.item_id IN (SELECT id FROM item WHERE produkt_id=? AND kategorie IN ('fertig','verkaufsfertig')))
                       ORDER BY b.id DESC LIMIT 3", [$auftrag_id, $produkt_id]);
}
function pa_material_bestellung_html(array $b): string {
    $st = bestellung_stationen()[(string)$b['station']] ?? '';
    $stand = (string)$b['status'] === 'geliefert' || !empty($b['angekommen_am']) ? 'geliefert' . (!empty($b['angekommen_am']) ? ' ' . date('d.m.', strtotime((string)$b['angekommen_am'])) : '')
           : ((string)$b['status'] === 'offen' ? 'Entwurf' : ($st !== '' ? $st : ((int)$b['bestaetigt'] === 1 ? 'bestätigt' : status_text((string)$b['status']))));
    return '<div style="font-size:12px;margin-top:2px"><a href="?p=bestellung&amp;id=' . (int)$b['id'] . '">' . h((string)$b['nummer']) . '</a>'
         . ($b['firma'] ? ' <span class="muted">· ' . h((string)$b['firma']) . '</span>' : '')
         . ($b['bestelldatum'] ? ' <span class="muted">· bestellt ' . h(date('d.m.Y', strtotime((string)$b['bestelldatum']))) . '</span>' : '')
         . ' <span class="muted">· ' . h($stand) . '</span>'
         . (!empty($b['eta_geplant']) && empty($b['angekommen_am']) ? ' <span class="muted">· erwartet ' . h(date('d.m.Y', strtotime((string)$b['eta_geplant']))) . '</span>' : '')
         . '</div>';
}
// Zukauf-Bulk ohne Lagerartikel: der Bulk-Artikel der Rezeptur (Bulkware) als Ziel des Links.
function pa_material_bulk_item(int $produkt_id): int {
    if ($produkt_id <= 0) return 0;
    return (int) (scalar("SELECT i.id FROM item i JOIN produkt p ON p.rezeptur_id=i.rezeptur_id WHERE p.id=? AND i.kategorie='fertig' ORDER BY COALESCE(i.gesperrt,0), i.id LIMIT 1", [$produkt_id]) ?: 0);
}
// Artikel-IDs der fehlenden Materialien für die Fehlermeldung (Links „Material öffnen“).
function pa_teilitems_q(array $r): string {
    $ids = array_values(array_unique(array_filter(array_map(fn($f) => (int)($f['item_id'] ?? 0), (array)($r['fehlt'] ?? [])))));
    return $ids ? '&teilitems=' . implode(',', array_slice($ids, 0, 10)) : '';
}

$id = (int)($_GET['id'] ?? 0);

// Nächste offene Station als erledigt markieren (zentrale Logik in core/schema.php)
// Unter-Charge im Dashboard abschließen / wieder öffnen (Nico 12.11.2026: „Status läuft – keine Ahnung, wie ich den ändere“).
// Abschließen = wie Chargenwechsel/Ende in der Werk-App (pa_unter_charge_schliessen: Gegenprobe, Protokoll, Ereignis).
// Wieder öffnen nur, wenn keine andere Unter-Charge dieses PA läuft (es läuft immer höchstens eine).
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['aktion'] ?? ''), ['uc_schliessen', 'uc_oeffnen'], true)) {
    $wer = (string)(current_user()['name'] ?? 'Team');
    $ucId = (int)($_POST['uc_id'] ?? 0);
    $ucR = $ucId ? one("SELECT * FROM pa_unter_charge WHERE id=? AND pa_id=?", [$ucId, $id]) : null;
    $msg = '';
    if (!$ucR) $msg = 'Unter-Charge nicht gefunden.';
    elseif ($_POST['aktion'] === 'uc_schliessen') {
        if ((string)$ucR['status'] !== 'fertig') pa_unter_charge_schliessen($ucId, $wer, 'im Dashboard abgeschlossen');
    } else {
        $andere = (int) scalar("SELECT COUNT(*) FROM pa_unter_charge WHERE pa_id=? AND status='laeuft' AND id<>?", [$id, $ucId]);
        if ($andere > 0) $msg = 'Es läuft schon eine andere Unter-Charge. Bitte die zuerst abschließen.';
        else {
            q("UPDATE pa_unter_charge SET status='laeuft', abgeschlossen_am=NULL WHERE id=?", [$ucId]);
            pa_ereignis($id, $ucId, 'wiederanlauf', 'Unter-Charge ' . $ucR['charge_nr'] . ' im Dashboard wieder geöffnet.', '', '', '', $wer);
        }
    }
    header('Location: ?p=produktionsauftrag&id=' . $id . ($msg !== '' ? '&teilfehler=' . urlencode($msg) : '&nachok=' . urlencode('Unter-Charge ' . ($ucR['charge_nr'] ?? '') . ($_POST['aktion'] === 'uc_schliessen' ? ' abgeschlossen.' : ' wieder geöffnet.'))) . '#unterchargen'); exit;
}

// Produktion per KI melden (13.11.2026, Teil 4): Auswerten/Auswahl/Verwerfen; gebucht wird unten über „nachtragen“.
require_once __DIR__ . '/_ki_melden.php';
if ($id) pki_ui_post($id, '?p=produktionsauftrag&id=' . $id);
// Ein Weg (11.11.2026): Teilmenge nachtragen (ersetzt Easy Production) – gleiche Buchung wie in der Werk-App (core/pa_ablauf.php).
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'nachtragen') {
    $wer = (string)(current_user()['name'] ?? 'Team');
    $bulkWert = (string)($_POST['bulk_charge_id'] ?? '0');   // „<id>“ = Bulk-Charge, „e<id>“ = Eimer (Fable-4 T1.1)
    $eimer = $bulkWert !== '' && $bulkWert[0] === 'e' ? (int) substr($bulkWert, 1) : 0;
    $bulk = $eimer > 0 ? 0 : (int)$bulkWert;
    $menge = zahl_lesen((string)($_POST['menge'] ?? '0'), true);
    $charge = trim((string)($_POST['charge_nr'] ?? ''));
    $datumN = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['datum'] ?? '')) ? (string)$_POST['datum'] : pa_tag_heute();
    if ($charge !== '' && $charge === trim((string)($_POST['charge_vorschlag'] ?? ''))) $charge = '';   // unveränderter Vorschlag = System vergibt
    // 13.11.2026 (Fable-3 T1.4): ein Weg für jedes Datum – pa_nachtrag_buchen() findet bzw. legt die Unter-Charge des gewählten Tages an,
    // lässt die laufende Unter-Charge in Ruhe und verwirft bei einem Fehler alles (kein Buchstabe verbraucht).
    $foto = pa_foto_speichern($_FILES['foto'] ?? [], 'nachtrag' . $id);
    $r = pa_nachtrag_buchen($id, $menge, ['bulk_charge_id' => $bulk, 'prod_charge_id' => $eimer, 'datum' => $datumN, 'charge_nr' => $charge, 'grund' => (string)($_POST['grund'] ?? ''), 'mhd' => (string)($_POST['mhd'] ?? '')],
        ['mitarbeiter' => (string)($_POST['mitarbeiter'] ?? ''), 'rueckstell' => (int) zahl_lesen((string)($_POST['rueckstell'] ?? '0'), true), 'foto' => $foto, 'geliefert' => !empty($_POST['geliefert']),
         'vor_etikett' => ($_POST['umfang'] ?? '') === 'vor_etikett'], $wer);
    $uc = (int)($r['unter_charge_id'] ?? 0);
    // 13.11.2026 (Teil 4): per KI gemeldet -> Vermerk mit Originaltext im Chargenprotokoll und Verlauf (wer gemeldet, wer bestätigt).
    $kiText = trim((string)($_POST['ki_text'] ?? ''));
    if ($r['ok'] && $kiText !== '') {
        $kiTxt = 'per KI gemeldet von ' . (trim((string)($_POST['ki_von'] ?? '')) ?: $wer) . ', bestätigt von ' . $wer . ': „' . mb_substr($kiText, 0, 500) . '“';
        pa_ereignis($id, $uc ?: null, 'ki_meldung', $kiTxt, '', '', '', $wer);
        $aidK = (int) scalar("SELECT COALESCE(auftrag_id,0) FROM produktionsauftrag WHERE id=?", [$id]);
        if ($aidK > 0) log_aktivitaet('auftrag', $aidK, 'team', 'Produktion ' . (string) scalar("SELECT nummer FROM produktionsauftrag WHERE id=?", [$id]) . ': ' . $kiTxt, 'status');
        unset($_SESSION['pki_' . (int)($_POST['ki_ursprung'] ?? 0)]);
    }
    if ($r['ok'] && !empty($_POST['geliefert']) && !empty($r['charge_id'])) {
        // Schon geliefert (wie Easy Production): die nachgetragene Menge gleich per Lieferschein wieder ausbuchen – kein Phantombestand.
        q("UPDATE charge SET menge_verfuegbar=GREATEST(0, menge_verfuegbar-?), status=IF(menge_verfuegbar<=0.0001,'leer',status) WHERE id=?", [$menge, (int)$r['charge_id']]);
        $a = !empty($pa0 = one("SELECT auftrag_id, kunde_id FROM produktionsauftrag WHERE id=?", [$id])) && $pa0['auftrag_id'] ? one("SELECT * FROM auftrag WHERE id=?", [(int)$pa0['auftrag_id']]) : null;
        if ($a) {
            $netto = (int)$a['menge'] > 0 ? round((float)$a['gesamt_netto'] * $menge / (int)$a['menge'], 2) : (float)$a['gesamt_netto'];
            q("INSERT INTO beleg (nummer,typ,auftrag_id,kunde_id,menge,netto,ust_prozent,ust_betrag,brutto,status,datum) VALUES (?,'lieferschein',?,?,?,?,0,0,?,'offen',?)",
              [naechste_nummer('LS'), (int)$a['id'], ((int)$a['kunde_id'] ?: null), $menge, $netto, $netto, (string)($_POST['datum'] ?? date('Y-m-d'))]);
        }
        pa_ereignis($id, $uc, 'teilmenge', 'Schon geliefert: ' . number_format($menge, 0, ',', '.') . ' Stk per Lieferschein wieder ausgebucht.', '', '', '', $wer);
    }
    header('Location: ?p=produktionsauftrag&id=' . $id . ($r['ok'] ? '&nachok=' . urlencode($r['msg']) : '&teilfehler=' . urlencode($r['msg']) . pa_teilitems_q($r) . '&nachtragen=1') . '#unterchargen'); exit;
}

if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'erledigen') {
    // Ein Weg (11.11.2026): Schritt-Knöpfe ohne Menge nur noch für Admin, mit Rückfrage und Grund (QM-Gates bleiben normal).
    $stE = (string) scalar("SELECT station FROM produktion_schritt WHERE id=? AND pa_id=?", [(int)($_POST['schritt'] ?? 0), $id]);
    if (!in_array($stE, produktion_qm_stationen(), true)) {
        if (!has_role('admin')) { header('Location: ?p=produktionsauftrag&id=' . $id . '&schrittfehler=' . urlencode('Produziert wird in der Werk-App mit Mengen. Abhaken ohne Menge nur für Admin.')); exit; }
        $grundE = trim((string)($_POST['grund'] ?? ''));
        if ($grundE === '') { header('Location: ?p=produktionsauftrag&id=' . $id . '&schrittfehler=' . urlencode('Bitte einen Grund angeben.')); exit; }
        pa_ereignis($id, null, 'abweichung', 'Schritt „' . $stE . '“ ohne Menge abgehakt (Admin): ' . $grundE, '', '', '', (string)(current_user()['name'] ?? ''));
    }
    // Einfacher Abhak-Modus: Schritt ohne Charge-Scan abschließen (es gibt noch keine Etiketten zum Scannen).
    // Material wird trotzdem nach FEFO abgebucht.
    $r = produktion_schritt_erledigen($id, (int)($_POST['schritt'] ?? 0), '', true);
    if (!$r['ok'] && $r['fehler'] === 'mangel') { header('Location: ?p=produktionsauftrag&id=' . $id . '&mangel=1'); exit; }
    if (!$r['ok']) { header('Location: ?p=produktionsauftrag&id=' . $id . '&schrittfehler=' . urlencode((string)$r['msg'])); exit; }   // z. B. Etikett nicht freigegeben, Vorbereitung
    header('Location: ?p=produktionsauftrag&id=' . $id . '&ok=1'); exit;
}

// God-Mode (nur Admin): die Produktion auf eine STUFE setzen – Gate überspringen, ohne Bestandsprüfung/-buchung
// und ohne Lager-Übergabe. Setzt die Schritte bis zur gewählten Stufe auf erledigt (schritt=0 = alles offen).
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'godmode_stufe' && has_role('admin')) {
    $r = produktion_godmode_stufe_setzen($id, (int)($_POST['schritt'] ?? 0));
    header('Location: ?p=produktionsauftrag&id=' . $id . ($r['ok'] ? '&godstufe=' . urlencode((string)$r['stufe']) : '&godfehler=' . urlencode($r['msg']))); exit;
}

// Teilmenge Fertigware einbuchen (Teilproduktion .A/.B/.C) – der Abschluss bucht später nur noch den Rest
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'teilmenge') {
    // 13.11.2026: EIN Weg – Teilmengen nur über pa_teilmenge_buchen() (Werk-App Abfüllen/Verpacken bzw. „Teilmenge nachtragen“).
    // Der alte Weg buchte an den Unter-Chargen vorbei; er bucht nichts mehr.
    header('Location: ?p=produktionsauftrag&id=' . $id . '&teilfehler=' . urlencode('Teilmengen bucht nur noch der geführte Ablauf: in der Werk-App unter Abfüllen/Verpacken bzw. hier unter „Teilmenge nachtragen“.')); exit;
    $r = produktion_teilmenge_produzieren($id, zahl_lesen((string)($_POST['menge'] ?? '0'), true),
        trim((string)($_POST['mhd'] ?? '')) ?: null, (string)($_POST['notiz'] ?? ''));
    if ($r['ok']) {
        $paT = one("SELECT nummer, kunde_id, auftrag_id FROM produktionsauftrag WHERE id=?", [$id]);
        if ($paT && $paT['kunde_id']) log_aktivitaet('kunde', (int)$paT['kunde_id'], 'team', 'Produktion ' . $paT['nummer'] . ': Teilmenge ' . number_format((float)$_POST['menge'], 0, ',', '.') . ' Stk als Charge ' . $r['charge_nr'] . ' eingebucht.', 'auftrag', 'auftrag', (int)$paT['auftrag_id']);
        header('Location: ?p=produktionsauftrag&id=' . $id . '&teil=' . urlencode($r['charge_nr'])); exit;
    }
    header('Location: ?p=produktionsauftrag&id=' . $id . '&teilfehler=' . urlencode($r['msg']) . pa_teilitems_q($r)); exit;
}

// Nachbuchen „komplett versendet" (Admin, Nico 06.11.2026): Auftrag steht schon auf versendet, gebucht wurde aber nichts.
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'nachbuchen_versendet' && has_role('admin')) {
    $rN = produktion_nachbuchen_versendet($id, !empty($_POST['lieferschein']), (string)(current_user()['name'] ?? ''));
    header('Location: ?p=produktionsauftrag&id=' . $id . '&' . ($rN['ok'] ? 'nachok=' : 'nachfehler=') . rawurlencode($rN['msg'])); exit;
}

// Chargennummer einer eingebuchten Fertigware-Charge korrigieren (falls sie eine andere Nummer bekommen hat).
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'charge_edit') {
    $cid = (int)($_POST['charge_id'] ?? 0);
    $neu = trim((string)($_POST['charge_nr'] ?? ''));
    if ($cid && $neu !== '') q("UPDATE charge SET charge_nr=? WHERE id=? AND pa_id=?", [$neu, $cid, $id]);   // nur Chargen DIESES Auftrags
    header('Location: ?p=produktionsauftrag&id=' . $id . '&chok=1'); exit;
}

// Priorität setzen
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'prio') {
    q("UPDATE produktionsauftrag SET prio=? WHERE id=?", [max(1, min(3, (int)($_POST['prio'] ?? 2))), $id]);
    header('Location: ?p=produktionsauftrag&id=' . $id); exit;
}
// Bestand für diesen Auftrag reservieren / freigeben
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'reservieren') {
    $n = auftrag_reservieren($id);
    header('Location: ?p=produktionsauftrag&id=' . $id . '&reserviert=' . $n); exit;
}
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'reservierung_frei') {
    auftrag_reservierung_freigeben($id);
    header('Location: ?p=produktionsauftrag&id=' . $id . '&resfrei=1'); exit;
}
// Etikett-Design hochladen / löschen (je Auftrag)
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['aktion'] ?? ''), ['etikett_upload', 'etikett_del'], true)) {
    $aid = (int) scalar("SELECT auftrag_id FROM produktionsauftrag WHERE id=?", [$id]);
    if ($aid) {
        if ($_POST['aktion'] === 'etikett_upload') etikett_upload($aid);
        else etikett_del($aid);
    }
    header('Location: ?p=produktionsauftrag&id=' . $id . '&etikett=1'); exit;
}
// Team bestätigt die Etikettenfreigabe manuell (Freigabe kam z. B. per Mail/Telefon statt übers Portal).
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'etikett_freigeben_team') {
    $aid = (int) scalar("SELECT auftrag_id FROM produktionsauftrag WHERE id=?", [$id]);
    $name = trim((string)($_POST['freigabe_name'] ?? ''));
    if ($aid) etikett_freigabe_setzen($aid, $name !== '' ? $name : 'Kunde (Team bestätigt)', 'team');
    header('Location: ?p=produktionsauftrag&id=' . $id . '&etikett=1'); exit;
}
// 1-Klick: Bestellungen aus dem Fehlbedarf anlegen
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'bedarf_bestellen') {
    // Warnung bei Doppelbestellung (Sofortfix 11.11.2026): offene Bestellungen der fehlenden Artikel -> Pop-up.
    require_once BX_ROOT . '/core/bestellung_doppelt.php';
    $bxDoppelt = bestellung_doppelt_treffer(array_column(auftrag_fehlbedarf($id), 'item_id'));
    if ($bxDoppelt && empty($_POST['doppelt_ok'])) bestellung_doppelt_stopp($bxDoppelt, '?p=produktionsauftrag&id=' . $id);
    $neu = bestellung_aus_bedarf($id);
    bestellung_doppelt_vermerken($neu, $bxDoppelt);
    // „Direkt bestellt (Telefon/Mail)": Entwürfe mit Lieferant sofort bestellt + bestätigt, keine Mail (ohne Lieferant bleibt Entwurf).
    // 13.11.2026: zentrale Unterlagen-Sperre – fehlen Unterlagen, bleibt die Bestellung Entwurf (Hinweis mit Nummern).
    $dGesperrt = [];
    if (($_POST['modus'] ?? '') === 'direkt')
        foreach ($neu as $nb) if ((int) scalar("SELECT COALESCE(lieferant_id,0) FROM bestellung WHERE id=?", [$nb]) > 0
                                 && bestellung_direkt_abschliessen((int)$nb, null, null, 'aus dem Produktionsauftrag') !== '')
            $dGesperrt[] = (string) scalar("SELECT nummer FROM bestellung WHERE id=?", [(int)$nb]);
    header('Location: ?p=produktionsauftrag&id=' . $id . '&bestellt=' . count($neu)
         . ($dGesperrt ? '&fehler=' . urlencode('Als Entwurf angelegt, nicht bestellt (Unterlagen des Lieferanten fehlen): ' . implode(', ', $dGesperrt) . '. Bestellen an der Bestellung, Ausnahme nur Admin mit Grund.') : '')); exit;
}
// Fehlende Rohstoffe direkt auf die Einkaufsliste setzen (Bedarf melden, ohne den Umweg über die Bedarf-Seite).
// Nur was WIRKLICH fehlt landet dort – Bestand und bereits Bestelltes rechnet die Einkaufsliste automatisch raus.
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'auf_einkaufsliste') {
    if (auftrag_offener_bedarf($id)) {
        q("UPDATE produktionsauftrag SET bedarf_gemeldet=COALESCE(bedarf_gemeldet, ?) WHERE id=?", [gmdate('Y-m-d H:i:s'), $id]);
        header('Location: ?p=einkaufsliste&aufgesetzt=1'); exit;
    }
    header('Location: ?p=produktionsauftrag&id=' . $id . '&nichts_offen=1'); exit;
}
// Geplantes Produktionsdatum setzen
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'geplant') {
    q("UPDATE produktionsauftrag SET geplant_am=? WHERE id=?", [trim($_POST['geplant_am'] ?? '') ?: null, $id]);
    header('Location: ?p=produktionsauftrag&id=' . $id); exit;
}
// Menge am Produktionsauftrag mit dem Auftrag abgleichen (nur Admin). Fix für PAs, deren Menge nach einer
// Auftrags-/Angebotsänderung veraltet ist (PA wurde früher mit höherer/niedrigerer Menge angelegt, die
// Änderung lief auf Angebot+Auftrag, nicht auf den schon existierenden PA -> Bedarf rechnet mit falscher Zahl).
// Übernimmt menge/stueck/verpackung_id vom verknüpften Auftrag; eine geplante Überproduktion (menge_produktion)
// bleibt erhalten. Nur sinnvoll, solange noch nichts eingebucht wurde.
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'menge_abgleich' && has_role('admin')) {
    $a = one("SELECT menge, stueck, verpackung_id FROM auftrag WHERE id=(SELECT auftrag_id FROM produktionsauftrag WHERE id=?)", [$id]);
    if ($a) {
        $altMenge = (int) scalar("SELECT menge FROM produktionsauftrag WHERE id=?", [$id]);
        $neuMenge = (int)$a['menge'];
        q("UPDATE produktionsauftrag SET menge=?, stueck=?, verpackung_id=? WHERE id=?",
          [$neuMenge, (int)($a['stueck'] ?? 0) ?: null, $a['verpackung_id'] ?: null, $id]);
        if (function_exists('bedarf_bump')) bedarf_bump();   // Rohstoff-/Einkaufsbedarf neu rechnen
        $paA = one("SELECT nummer, kunde_id, auftrag_id FROM produktionsauftrag WHERE id=?", [$id]);
        if ($paA && $paA['kunde_id']) log_aktivitaet('kunde', (int)$paA['kunde_id'], 'team',
            'Produktionsmenge ' . (string)$paA['nummer'] . ' an den Auftrag angeglichen: ' .
            number_format($altMenge, 0, ',', '.') . ' -> ' . number_format($neuMenge, 0, ',', '.') . ' Packungen.',
            'auftrag', 'auftrag', (int)($paA['auftrag_id'] ?? 0));
        header('Location: ?p=produktionsauftrag&id=' . $id . '&mengeok=1'); exit;
    }
    header('Location: ?p=produktionsauftrag&id=' . $id . '&mengefehler=1'); exit;
}
// Füllmenge je Packung (g) für Pulver/Granulat setzen (nur Admin). Bei diesen Formen ist die „Packungsgröße"
// die Füllmenge in Gramm je Packung (nicht Portionszahl). Schreibt auf Produkt (einheiten_pro_packung),
// Auftrag (stueck) und diesen PA (stueck), damit produktion_stueck_je_packung() überall denselben Wert liefert.
// Dient v. a. der Korrektur von Alt-/v3-Aufträgen, bei denen dort noch eine Portionszahl statt Gramm steht.
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'pulver_fuellmenge' && has_role('admin')) {
    $g = (int) round(zahl_lesen((string)((string)($_POST['fuellmenge_g'] ?? '0')), true));
    if ($g > 0) {
        $paF = one("SELECT nummer, kunde_id, auftrag_id, produkt_id, stueck FROM produktionsauftrag WHERE id=?", [$id]);
        $alt = (int)($paF['stueck'] ?? 0);
        q("UPDATE produktionsauftrag SET stueck=? WHERE id=?", [$g, $id]);
        if (!empty($paF['auftrag_id'])) q("UPDATE auftrag SET stueck=? WHERE id=?", [$g, (int)$paF['auftrag_id']]);
        if (!empty($paF['produkt_id'])) q("UPDATE produkt SET einheiten_pro_packung=? WHERE id=?", [$g, (int)$paF['produkt_id']]);
        if (function_exists('bedarf_bump')) bedarf_bump();
        if ($paF && $paF['kunde_id']) log_aktivitaet('kunde', (int)$paF['kunde_id'], 'team',
            'Füllmenge je Packung (Pulver) ' . (string)$paF['nummer'] . ' gesetzt: ' .
            number_format($alt, 0, ',', '.') . ' -> ' . number_format($g, 0, ',', '.') . ' g.',
            'auftrag', 'auftrag', (int)($paF['auftrag_id'] ?? 0));
        header('Location: ?p=produktionsauftrag&id=' . $id . '&fuellok=' . $g); exit;
    }
    header('Location: ?p=produktionsauftrag&id=' . $id . '&fuellfehler=1'); exit;
}
// Produktionsweg umstellen (verkürzt bei Zukauf / voll)
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['aktion'] ?? ''), ['weg_zukauf', 'weg_voll'], true)) {
    $fremd = ($_POST['aktion'] === 'weg_zukauf');
    // Über den zentralen Setter: setzt Produktionsart + markiert „festgelegt" (= Freigabe an die Produktion).
    $ok = produktionsauftrag_art_setzen($id, $fremd ? 'fremd' : 'eigen');
    if (!$ok && $fremd) {
        // Schon ein Schritt erledigt oder bestellt (Nico 06.11.2026): nachträglich auf fertige Kapseln – nur offene Herstellungsschritte tauschen.
        $rF = produktion_weg_fertigkapseln($id, (string)(current_user()['name'] ?? ''));
        header('Location: ?p=produktionsauftrag&id=' . $id . '&' . ($rF['ok'] ? 'wegfk=' : 'wegfkfehler=') . rawurlencode($rF['msg'])); exit;
    }
    header('Location: ?p=produktionsauftrag&id=' . $id . ($ok ? '&weg=1' : '&wegfehler=1')); exit;
}
// Rückabwicklung: Lieferant kann/will doch nicht – Fremd-Bulk-Bestellung stornieren (+ Lieferant informieren)
// und auf Eigenproduktion umstellen. Die harte Prüfung (schon geliefert / Schritt erledigt) macht die Funktion.
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'rueckabwicklung') {
    $res = produktion_rueckabwicklung_eigen($id, (string)(current_user()['name'] ?? ''));
    header('Location: ?p=produktionsauftrag&id=' . $id . '&' . ($res['ok'] ? 'rueck=' . rawurlencode($res['msg']) : 'rueckfehler=' . rawurlencode($res['msg']))); exit;
}

$pa = $id ? one("SELECT pa.*, COALESCE(k.firma, ak.firma) AS kunde_firma,
                        COALESCE(NULLIF(pa.kunde_id,0), a.kunde_id) AS kunde_id_eff,
                        p.name AS produkt_name, a.nummer AS auftrag_nr, a.menge AS auftrag_menge, a.status AS auftrag_status,
                        a.produkt_bezeichnung AS auftrag_produkt_bez, a.produkt_form AS auftrag_produkt_form,
                        rz.name AS rezeptur_name, rz.darreichungsform AS rezeptur_form
                 FROM produktionsauftrag pa
                 LEFT JOIN kunden k ON k.id=pa.kunde_id LEFT JOIN produkt p ON p.id=pa.produkt_id
                 LEFT JOIN rezeptur rz ON rz.id=pa.rezeptur_id
                 LEFT JOIN auftrag a ON a.id=pa.auftrag_id
                 LEFT JOIN kunden ak ON ak.id=a.kunde_id WHERE pa.id=?", [$id]) : null;
if (!$pa) { render_header('produktion','Produktion'); bx_head('Produktionsauftrag nicht gefunden','', bx_btn('Zurück','?p=produktion','ghost')); render_footer(); exit; }

$schritte = all("SELECT * FROM produktion_schritt WHERE pa_id=? ORDER BY sort, id", [$id]);
$total = count($schritte);
$done  = count(array_filter($schritte, fn($s)=> (int)$s['erledigt'] === 1));
$firstOpenId = null;
// Baustein 3: Produktionsbereitschaft (Material komplett da?)
$ber = produktion_bereitschaft($id);
// Baustein 5: adaptiver Weg – fertige Bulkware für den Auftrag zugekauft?
$istZukauf = produktion_ist_zukauf((int)$pa['auftrag_id']);
$stationen = array_map(fn($s)=> $s['station'], $schritte);
$istVollerWeg = in_array('Mischen', $stationen, true);   // enthält Herstellungsschritte
// Fertige Bulkware am Auftrag (Zukauf) + noch nichts erledigt -> automatisch auf den kurzen Weg.
// So zeigt die Seite bei zugekaufter Ware KEINE Rohstoff-/Misch-Schritte mehr (kein Widerspruch).
if ($istZukauf && $istVollerWeg && $done === 0 && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    if (produktion_schritte_regenerieren($id, true)) {
        $schritte = all("SELECT * FROM produktion_schritt WHERE pa_id=? ORDER BY sort, id", [$id]);
        $stationen = array_map(fn($s)=> $s['station'], $schritte);
        $istVollerWeg = in_array('Mischen', $stationen, true);
        $done = count(array_filter($schritte, fn($s)=> (int)$s['erledigt'] === 1));
    }
}
$wegUmstellbar = $done === 0;                            // nur solange nichts erledigt
// Offene Herstellungsschritte (für „Auf fertige Kapseln umstellen“ nach erledigten Schritten bzw. trotz Bestellung)
$herstOffen = array_values(array_map(fn($s) => (string)$s['station'], array_filter($schritte, fn($s) => (int)$s['erledigt'] === 0
    && in_array((string)$s['station'], ['Rohstoffe bereitstellen', 'Mischen', 'Verkapselung', 'Tablettierung', 'Softgel-Herstellung', 'Stick-Abfüllung',
                                        'Pulver-Abfüllung', 'Abfüllung', 'Gummi-Herstellung (Gießen)', 'Gel-Abfüllung', 'Herstellung'], true))));
$firstOpenId = null;
foreach ($schritte as $s) if ((int)$s['erledigt'] === 0) { $firstOpenId = (int)$s['id']; break; }

$bedarf = produktion_materialbedarf($id);
// Leerkapsel-Bedarf (nur Kapselprodukte) – separat, damit er nicht doppelt (bereitstellen + verkapseln) abgebucht wird
$kapId = produkt_leerkapsel_id((int)$pa['produkt_id']);
$kapNeed = null;
if ($kapId) {
    $einhK = produktion_stueck_je_packung($pa);
    $needK = (float)$pa['menge'] * $einhK;
    $verfK = item_bestand($kapId, true);
    $kapNeed = ['item_id'=>(int)$kapId, 'name'=>scalar("SELECT name FROM item WHERE id=?", [$kapId]), 'benoetigt'=>$needK, 'verfuegbar'=>$verfK, 'fehlt'=>max(0.0, $needK - $verfK)];
}
$verbrauch = all("SELECT v.*, c.charge_nr, i.name AS item_name, l.firma AS lieferant FROM produktion_verbrauch v
                  LEFT JOIN charge c ON c.id=v.charge_id LEFT JOIN item i ON i.id=v.item_id
                  LEFT JOIN lieferanten l ON l.id=c.lieferant_id
                  WHERE v.pa_id=? ORDER BY v.id", [$id]);
// Zusammensetzung (Rezeptur-Zutaten) fuer die Anzeige auf der Detailseite.
$rezIdD = (int)($pa['rezeptur_id'] ?? 0);
if (!$rezIdD && !empty($pa['produkt_id'])) $rezIdD = (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [(int)$pa['produkt_id']]);
$zutatenD = $rezIdD ? all("SELECT z.menge_mg, COALESCE(NULLIF(z.bezeichnung,''), i.name) AS name FROM rezeptur_zutat z LEFT JOIN item i ON i.id=z.item_id WHERE z.rezeptur_id=? ORDER BY z.sort, z.id", [$rezIdD]) : [];
$mng = fn($x,$e) => rtrim(rtrim(number_format((float)$x,3,',','.'),'0'),',') . ' ' . h($e ?: '');
// Alle Fertigwaren-Chargen zu diesem Auftrag (.A, .B, .C …) – bei Teilproduktion mehrere.
$fwChargen = all("SELECT c.id, c.charge_nr, c.menge, c.menge_verfuegbar, c.mhd, c.status, c.wareneingang, c.item_id, i.artikelnummer, i.name
                   FROM charge c JOIN item i ON i.id=c.item_id WHERE c.pa_id=? ORDER BY c.id", [$id]);
$fertigware = $fwChargen[0] ?? null;
$fwGebucht  = produktion_gebucht($id);
$fwRest     = max(0.0, (float)$pa['menge'] - $fwGebucht);
// Standard-Chargennummer (PR-Nr + .A/.B/.C) und MHD (18 Monate) – für die Geräte-Eingabe, schon vor der Buchung sichtbar.
$fCharge   = one("SELECT charge_nr, mhd FROM charge WHERE pa_id=? ORDER BY id LIMIT 1", [$id]);
$chargeGeb = (bool)$fCharge;
$chargeNr  = $fCharge ? $fCharge['charge_nr'] : charge_naechste_nr($id);
$chargeMhd = ($fCharge && $fCharge['mhd']) ? $fCharge['mhd'] : mhd_standard();


render_header('produktion', $pa['nummer']);
// Fehlen wirklich Rohstoffe (nach Bestand + bereits Bestelltem)? Dann Knopf: direkt auf die Einkaufsliste.
$offenerBedarf  = ($pa['status'] !== 'erledigt') && auftrag_offener_bedarf($id);
$bedarfGemeldet = !empty($pa['bedarf_gemeldet']);
$btnEinkauf = $offenerBedarf
    ? ($bedarfGemeldet
        ? bx_btn('Auf der Einkaufsliste', '?p=einkaufsliste', 'ghost') . ' '
        : '<form method="post" style="display:inline;margin:0" onsubmit="return confirm(\'Die noch fehlenden Rohstoffe direkt auf die Einkaufsliste setzen? Bestand und bereits Bestelltes bleiben außen vor.\');">'
          . '<input type="hidden" name="aktion" value="auf_einkaufsliste">'
          . '<button class="btn btn-accent btn-sm" type="submit">Fehlende Rohstoffe → Einkaufsliste</button></form> ')
    : '';
// Produktname schon für den Kopf bestimmen, damit oben direkt steht, WAS produziert wird (nicht nur die PR-Nummer).
$produktName = (string)($pa['produkt_name'] ?? '') ?: (string)($pa['auftrag_produkt_bez'] ?? '');
if ($produktName === '' && !empty($pa['rezeptur_name'])) $produktName = (string)$pa['rezeptur_name'] . (pa_ist_bulk($pa) ? ' · Bulk' : '');
bx_head($pa['nummer'] . ($produktName !== '' ? ' · ' . $produktName : ''), 'Produktionsauftrag',
        ($pa['status'] !== 'erledigt' ? bx_btn('In der Werk-App produzieren', '/produktion/?p=werk', 'primary') . ' ' : '')
        . (!empty($pa['auftrag_id']) ? bx_btn('Zum Auftrag ' . (string)$pa['auftrag_nr'], '?p=auftrag&id=' . (int)$pa['auftrag_id'], 'ghost') . ' ' : '')
        . $btnEinkauf
        . pdf_btn('?p=produktionsauftrag_pdf&id=' . $id, 'Laufzettel drucken', false, 'Produktionsauftrag / Laufzettel als PDF') . ' '
        . bx_btn('Produktionsbericht', '?p=produktion_bericht&id=' . $id, $pa['status'] === 'erledigt' ? 'primary' : 'ghost') . ' '
        . bx_btn('Zurück zur Liste', '?p=produktion', 'ghost'));
if (isset($_GET['nichts_offen'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Nichts zu bestellen – alle Rohstoffe sind auf Lager oder bereits bestellt.</div>';
if (isset($_GET['angelegt'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Produktionsauftrag angelegt – der Materialbedarf ist jetzt berechenbar (Rohstoffe bestellbar).</div>';
if (isset($_GET['ok'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Station abgeschlossen.</div>';
if (isset($_GET['mangel'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Nicht genug Bestand für die Produktion – siehe Material unten. Bitte erst Wareneingang buchen.</div>';
if (isset($_GET['weg']))    echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Produktionsweg umgestellt.</div>';
if (isset($_GET['nachok'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h((string)$_GET['nachok']) . '</div>';
if (isset($_GET['nachfehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['nachfehler']) . '</div>';
if (isset($_GET['wegfk']))  echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h((string)$_GET['wegfk']) . '</div>';
if (isset($_GET['wegfkfehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['wegfkfehler']) . '</div>';
if (isset($_GET['wegfehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Eigen/Fremd bzw. der Weg kann nicht mehr geändert werden – es wurde bereits ein Schritt erledigt oder für den Auftrag bestellt.' . (($pa['produktionsart'] ?? '') === 'fremd' ? ' Für „doch selbst produzieren" den Knopf <strong>Rückabwicklung</strong> nutzen (storniert die Bulk-Bestellung mit).' : '') . '</div>';
if (isset($_GET['rueck']))    echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h((string)$_GET['rueck']) . '</div>';
if (isset($_GET['rueckfehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['rueckfehler']) . '</div>';
if (!empty($_GET['fehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['fehler']) . '</div>';   // 13.11.2026: z. B. Unterlagen-Sperre bei „Direkt bestellt“
if (isset($_GET['bestellt'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . ((int)$_GET['bestellt'] > 0 ? (int)$_GET['bestellt'] . ' Bestellung(en) als Entwurf angelegt – im Einkauf prüfen und absenden.' : 'Kein offener Fehlbedarf – nichts zu bestellen.') . '</div>';
if (isset($_GET['etikett'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Etikett-Design aktualisiert.</div>';
if (isset($_GET['reserviert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . ((int)$_GET['reserviert'] > 0 ? 'Bestand für ' . (int)$_GET['reserviert'] . ' Komponente(n) reserviert.' : 'Nichts zu reservieren (kein freier Bestand verfügbar).') . '</div>';
if (isset($_GET['resfrei'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Reservierungen freigegeben.</div>';
if (isset($_GET['teil'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Teilmenge als Charge ' . h((string)$_GET['teil']) . ' eingebucht.</div>';
if (isset($_GET['schrittfehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Schritt nicht abgeschlossen: ' . h((string)$_GET['schrittfehler']) . '</div>';
if (isset($_GET['teilfehler'])) {
    $tiLinks = [];
    foreach (array_slice(array_filter(array_map('intval', explode(',', (string)($_GET['teilitems'] ?? '')))), 0, 10) as $tiId) {
        $tiN = (string) scalar("SELECT name FROM item WHERE id=?", [$tiId]);
        if ($tiN !== '') $tiLinks[] = item_link_html($tiId, $tiN) . ' <span class="muted">(Bestand ' . h(rtrim(rtrim(number_format(item_bestand($tiId, true), 3, ',', '.'), '0'), ',')) . ')</span>';
    }
    echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Teilmenge nicht gebucht: ' . h((string)$_GET['teilfehler'])
       . ($tiLinks ? '<div style="margin-top:8px;color:var(--text)">Material öffnen: ' . implode(' · ', $tiLinks) . '</div>' : '') . '</div>';
}
if (isset($_GET['godstufe'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">God-Mode: Produktion auf Stufe „' . h((string)$_GET['godstufe']) . '" gesetzt – Gate übersprungen, ohne Bestandsbuchung.</div>';
if (isset($_GET['godfehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">God-Mode: ' . h((string)$_GET['godfehler']) . '</div>';
if (isset($_GET['mengeok'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Produktionsmenge an den Auftrag angeglichen – der Materialbedarf ist jetzt neu berechnet.</div>';
if (isset($_GET['mengefehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Abgleich nicht möglich – zu diesem Produktionsauftrag ist kein Auftrag verknüpft.</div>';
if (isset($_GET['fuellok'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Füllmenge je Packung auf ' . (int)$_GET['fuellok'] . ' g gesetzt – der Materialbedarf ist neu berechnet.</div>';
if (isset($_GET['fuellfehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Bitte eine Füllmenge in Gramm (größer 0) angeben.</div>';

// Warnung: PA-Menge weicht vom verknüpften Auftrag ab (z. B. Auftrag/Angebot nachträglich geändert). Der
// Bedarf rechnet mit der PA-Menge – läuft sie auseinander, stimmt der Rohstoffbedarf nicht. Nur Admin, nur
// wenn ein Auftrag verknüpft ist und er nicht storniert wurde. Überproduktion (menge_produktion) ist separat.
if (has_role('admin') && !empty($pa['auftrag_id']) && ($pa['auftrag_status'] ?? '') !== 'storniert'
    && (int)($pa['auftrag_menge'] ?? 0) > 0 && (int)$pa['menge'] !== (int)$pa['auftrag_menge']):
    $faktor = (int)$pa['auftrag_menge'] > 0 ? (float)$pa['menge'] / (float)$pa['auftrag_menge'] : 0; ?>
  <div class="bx-panel" style="border-left:3px solid var(--warn)">
    <div style="font-weight:600">Menge weicht vom Auftrag ab</div>
    <div class="muted" style="font-size:13px;margin:4px 0 10px">Dieser Produktionsauftrag steht auf
      <strong><?= number_format((int)$pa['menge'], 0, ',', '.') ?></strong> Packungen, der verknüpfte Auftrag
      <a href="?p=auftrag&id=<?= (int)$pa['auftrag_id'] ?>"><?= h((string)$pa['auftrag_nr']) ?></a> auf
      <strong><?= number_format((int)$pa['auftrag_menge'], 0, ',', '.') ?></strong><?php if ($faktor > 0 && abs($faktor - 1) > 0.001): ?>
      <span class="muted">(Faktor <?= rtrim(rtrim(number_format($faktor, 2, ',', '.'), '0'), ',') ?>&times;)</span><?php endif; ?>.
      Der Material-/Rohstoffbedarf rechnet mit der Menge dieses Produktionsauftrags – deshalb kann er zu hoch
      oder zu niedrig sein. Meist entsteht das, wenn Auftrag/Angebot nach dem Anlegen des Produktionsauftrags
      geändert wurden. Eine geplante Überproduktion bleibt beim Abgleich erhalten.</div>
    <form method="post" style="margin:0" onsubmit="return confirm('Produktionsmenge von <?= number_format((int)$pa['menge'],0,'.','.') ?> auf <?= number_format((int)$pa['auftrag_menge'],0,'.','.') ?> Packungen setzen (an den Auftrag angleichen)?');">
      <input type="hidden" name="aktion" value="menge_abgleich">
      <button class="btn btn-primary btn-sm" type="submit">Menge an Auftrag angleichen (<?= number_format((int)$pa['auftrag_menge'], 0, ',', '.') ?> Packungen)</button>
    </form>
  </div>
<?php endif;
// God-Mode (nur Admin): Produktion auf eine Stufe setzen – Gate überspringen, ohne Bestandsprüfung/-buchung.
if (has_role('admin')):
    $godSchritte = all("SELECT id, station, erledigt FROM produktion_schritt WHERE pa_id=? ORDER BY sort, id", [$id]);
    if ($godSchritte): ?>
  <div class="bx-panel" style="border-left:3px solid var(--warn)">
    <div style="font-weight:600">God-Mode (Admin): Produktion auf Stufe setzen</div>
    <div class="muted" style="font-size:13px;margin:4px 0 10px">Setzt die Produktion auf die gewählte Stufe und überspringt den Gate/„Zaun" (z.&nbsp;B. „kein Glas da") – <strong>ohne Bestandsprüfung, ohne Abbuchung, ohne Lager-Übergabe</strong>. Für Fälle, in denen du den echten Bestand (Gläser/Kapseln) nicht verbrauchen willst. Klick auf eine Stufe = alle Schritte bis dorthin gelten als erledigt, der Rest als offen.</div>
    <div class="bx-row" style="gap:6px;flex-wrap:wrap;align-items:center">
      <form method="post" style="margin:0" onsubmit="return confirm('Alle Schritte wieder auf OFFEN setzen?');"><input type="hidden" name="aktion" value="godmode_stufe"><input type="hidden" name="schritt" value="0"><button class="btn btn-ghost btn-sm" type="submit">Anfang (alles offen)</button></form>
      <span class="muted" style="font-size:12px">→</span>
      <?php foreach ($godSchritte as $gs): $gsDone = (int)$gs['erledigt'] === 1; ?>
        <form method="post" style="margin:0" onsubmit="return confirm('Produktion auf diese Stufe setzen (ohne Bestandsbuchung)?');">
          <input type="hidden" name="aktion" value="godmode_stufe"><input type="hidden" name="schritt" value="<?= (int)$gs['id'] ?>">
          <button class="btn btn-sm <?= $gsDone ? 'btn-primary' : 'btn-ghost' ?>" type="submit" title="Produktion auf diese Stufe setzen (Gate überspringen, ohne Buchung)"><?= h((string)$gs['station']) ?></button>
        </form>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; endif; ?>
<?php
// Nachbuchen „komplett versendet" (nur Admin): Auftrag ist versendet, aber Rest nicht eingebucht / Fertigware noch im Lager.
// Auch wenn der Auftrag wieder auf „In Produktion“ gestellt wurde: Produktion fertig abgehakt, aber Rest nie eingebucht.
if (has_role('admin') && !empty($pa['auftrag_id']) && !pa_ist_bulk($pa) && (string)($pa['auftrag_status'] ?? '') !== 'storniert'
    && ((string)($pa['auftrag_status'] ?? '') === 'versendet' || (in_array((string)$pa['status'], ['fertig', 'erledigt'], true) && produktion_rest($id) > 0.0001))):
    $nbRest = produktion_rest($id);
    $nbLager = (float) scalar("SELECT COALESCE(SUM(c.menge_verfuegbar),0) FROM charge c JOIN item i ON i.id=c.item_id
        WHERE (c.pa_id=? OR c.auftrag_id=?) AND i.kategorie='verkaufsfertig' AND c.fremd_kunde_id IS NULL AND c.status IN ('frei','quarantaene')", [$id, (int)$pa['auftrag_id']]);
    $nbVers = (float) scalar("SELECT COALESCE(versendet_menge,0) FROM auftrag WHERE id=?", [(int)$pa['auftrag_id']]);
    if ($nbRest > 0.0001 || $nbLager > 0.0001 || $nbVers + 0.0001 < (float)$pa['auftrag_menge']): ?>
  <div class="bx-panel" style="border-left:3px solid var(--warn)">
    <div style="font-weight:600">Schon an den Kunden versendet? Bestand nachbuchen</div>
    <div class="muted" style="font-size:13px;margin:4px 0 10px">Im System ist das noch nicht gebucht:
      <?= $nbRest > 0.0001 ? number_format($nbRest, 0, ',', '.') . ' Pkg nie eingebucht, ' : '' ?><?= number_format($nbLager, 0, ',', '.') ?> Pkg stehen noch im Lager.
      Der Knopf bucht den Rest ein, die Fertigware aus und das Material ab: fertige Kapseln, Gläser/Deckel, Etiketten – <strong>keine Rohstoffe</strong>.
      Was nicht im Lager ist, wird nur notiert. Der Auftrag steht danach auf „versendet“. Keine Mail an den Kunden, die Rechnung bleibt, wie sie ist.</div>
    <form method="post" class="bx-row" style="gap:12px;align-items:center;flex-wrap:wrap;margin:0" onsubmit="return confirm('Bestand für diesen versendeten Auftrag nachbuchen?');">
      <input type="hidden" name="aktion" value="nachbuchen_versendet">
      <label class="bx-check" style="margin:0"><input type="checkbox" name="lieferschein" value="1"> Lieferschein nachträglich anlegen</label>
      <button class="btn btn-primary btn-sm" type="submit">Nachbuchen: komplett versendet</button>
    </form>
  </div>
<?php endif; endif;

// Einheitliche, ruhige Wertgröße für alle Kennzahl-Karten dieser Seite.
echo '<style>.bx-cards .v{font-size:15px;line-height:1.4} details.bx-sek>summary{cursor:pointer;list-style:none;font-weight:600;font-size:15px;color:var(--gruen);padding:12px 2px}details.bx-sek>summary::-webkit-details-marker{display:none}details.bx-sek>summary::before{content:"\\25B8 ";color:var(--gruen)}details.bx-sek[open]>summary::before{content:"\\25BE "}</style>';
// Mengen-Aufschlüsselung: menge = Packungen, einheiten_pro_packung = Stück/Kapseln je Packung.
$einhProP  = produktion_stueck_je_packung($pa);
$formPa    = (string) scalar("SELECT r.darreichungsform FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id=?", [(int)$pa['produkt_id']]);
if ($formPa === '') $formPa = (string)($pa['rezeptur_form'] ?? '');           // Bulk-PA: Form aus der Rezeptur
if ($formPa === '') $formPa = (string)($pa['auftrag_produkt_form'] ?? '');   // Fallback: v3-Auftrag ohne Produkt
$stkWort   = in_array($formPa, ['kapsel','softgel'], true) ? 'Kapseln' : ($formPa === 'tablette' ? 'Tabletten' : 'Stk');
// Pulver/Granulat: Packungsgröße ist die FÜLLMENGE in Gramm je Packung (nicht Portionszahl) -> nach Gewicht anzeigen.
$istFuellGewicht = function_exists('form_groessen_einheit') && form_groessen_einheit($formPa) === 'g';
// $produktName ist bereits oben (vor bx_head) bestimmt.
$gesamtStk = $einhProP > 0 ? (int)$pa['menge'] * $einhProP : 0;
// Eingabe-Kacheln vorbereiten (Priorität, Geplant am) und Größe.
$prioSel = '<form method="post" style="margin:0"><input type="hidden" name="aktion" value="prio"><select name="prio" onchange="this.form.submit()">';
foreach (prio_liste() as $pk => $pl) $prioSel .= '<option value="' . $pk . '"' . ((int)$pa['prio'] === $pk ? ' selected' : '') . '>' . $pl . '</option>';
$prioSel .= '</select></form>';
$geplantForm = '<form method="post" style="margin:0"><input type="hidden" name="aktion" value="geplant"><input type="date" name="geplant_am" value="' . h((string)($pa['geplant_am'] ?? '')) . '" onchange="this.form.submit()"></form>';
$groesseLbl = produktion_groesse_label((int)$pa['produkt_id']);
$artBadge = ($pa['produktionsart'] ?? 'eigen') === 'fremd' ? bx_badge('Fremdproduktion','info') : bx_badge('Eigenproduktion','ok');
// Kacheln (Nico 08.11.2026): zuerst WAS produziert wird (Produkt, Größe, Mengen), dann der Stand der Produktion
// (ein „Stand" statt Status/Bereitschaft/Fortschritt einzeln), Planung/Charge; Kunde/Auftrag nur als Nebenzeile.
$istBulkPa = pa_ist_bulk($pa);
$prodKarteLabel = $istBulkPa ? 'Rezeptur (Bulk)' : 'Produkt';
$prodKarteZusatz = (!$istBulkPa && $produktName && empty($pa['produkt_id'])) ? ' <span class="muted" style="font-size:12px">(aus v3)</span>' : '';
$prodName = h($produktName ?: '–');
if (!$istBulkPa && !empty($pa['produkt_id'])) $prodName = '<a href="?p=produkt&id=' . (int)$pa['produkt_id'] . '">' . $prodName . '</a>';
// Stand: fertig / Beschaffungsweg offen / Material fehlt / X von N Schritten + nächster Schritt.
$naechsteStation = '';
foreach ($schritte as $sx) if ((int)$sx['erledigt'] !== 1) { $naechsteStation = (string)$sx['station']; break; }
if ($pa['status'] === 'erledigt')            $standHtml = bx_badge('fertig', 'ok');
elseif ($pa['status'] === 'vorbereitung')    $standHtml = bx_badge('Beschaffungsweg offen', 'warn');
elseif ($done === 0 && ($ber['status'] ?? '') === 'wartet') $standHtml = bx_badge('Material fehlt', 'err');
else $standHtml = ($total > 0 ? '<strong>' . $done . ' von ' . $total . '</strong> Schritten' : '<span class="muted">keine Schritte</span>')
    . ($naechsteStation !== '' ? '<div class="muted" style="font-size:12px">als Nächstes: ' . h($naechsteStation) . '</div>' : '');
$karte = fn(string $k, string $v, string $stil = '') => '<div class="bx-card"' . ($stil !== '' ? ' style="' . $stil . '"' : '') . '><div class="k">' . $k . '</div><div class="v">' . $v . '</div></div>';
// 1) Was wird produziert
echo '<div class="bx-cards">';
echo $karte($prodKarteLabel, '<span style="font-size:17px;font-weight:600">' . $prodName . '</span>' . $prodKarteZusatz, 'flex:2 1 280px');
if ($groesseLbl !== '') echo $karte('Kapsel/Tablette', h($groesseLbl));
// Packungen: produziert / Soll (Nico 12.11.2026) – wie „Produziert / offen“ unten, aber oben auf einen Blick.
$pkGeb = produktion_gebucht($id);
echo $karte('Packungen <span class="muted" style="font-weight:400">produziert / Soll</span>', ($pkGeb > 0 ? '<strong>' . number_format($pkGeb, 0, ',', '.') . '</strong> / ' : '<span class="muted">0 /</span> ') . number_format((int)$pa['menge'], 0, ',', '.'));
if ($istFuellGewicht) {
    // Füllgewicht je Packung (g) und Gesamt-Füllgewicht (als kg, wenn >= 1 kg).
    if ($einhProP > 0)  echo $karte('Füllmenge je Packung', number_format($einhProP, 0, ',', '.') . ' g');
    if ($gesamtStk > 0) echo $karte('Gesamt-Füllmenge', $gesamtStk >= 1000 ? rtrim(rtrim(number_format($gesamtStk / 1000, 3, ',', '.'), '0'), ',') . ' kg' : number_format($gesamtStk, 0, ',', '.') . ' g');
} else {
    if ($einhProP > 0)  echo $karte(h($stkWort) . ' je Packung', number_format($einhProP, 0, ',', '.'));
    if ($gesamtStk > 0) echo $karte(h($stkWort) . ' gesamt', number_format($gesamtStk, 0, ',', '.'));
}
// Überproduktion (aus der Vor-Produktion-Freigabe): geplante Produktionsmenge in Einheiten + Überschuss -> Rezeptur-Bulk.
if (!empty($pa['menge_produktion']) && (int)$pa['menge_produktion'] > $gesamtStk) {
    $ueber = (int)$pa['menge_produktion'] - $gesamtStk;
    echo $karte('Produktionsmenge (geplant)', number_format((int)$pa['menge_produktion'], 0, ',', '.')
       . ' <span class="muted" style="font-size:12px">+' . number_format($ueber, 0, ',', '.') . ' &rarr; Bulk</span>');
}
echo '</div>';
// 2) Stand + Planung + Charge
echo '<div class="bx-cards">';
echo $karte('Stand', $standHtml);
echo $karte('Art', $artBadge);
echo $karte('Priorität', $prioSel);
echo $karte('Geplant am', $geplantForm);
echo $karte('Charge · MHD' . ($chargeGeb ? '' : ' (geplant)'), h($chargeNr) . (count($fwChargen) > 1 ? ' <span class="muted" style="font-size:13px">+' . (count($fwChargen) - 1) . '</span>' : '')
    . ' <span class="muted">&middot;</span> ' . h(date('d.m.Y', strtotime($chargeMhd))));
echo '</div>';
// 3) Nebenzeile: Kunde, Auftrag, erstellt
$kundeIdEff = (int)($pa['kunde_id_eff'] ?? 0);
$neben = [];
$neben[] = 'Kunde: ' . ($pa['kunde_firma'] ? kunde_link($kundeIdEff ?: null, $pa['kunde_firma'], true) : 'Lager');
if (!empty($pa['auftrag_id'])) $neben[] = 'Auftrag <a href="?p=auftrag&id=' . (int)$pa['auftrag_id'] . '">' . h((string)$pa['auftrag_nr']) . '</a>';
if (!empty($pa['angelegt'])) $neben[] = 'erstellt ' . h(fmt_zeit($pa['angelegt'], 'd.m.Y H:i'));
echo '<div class="muted" style="font-size:13px;margin:-4px 0 16px">' . implode(' &middot; ', $neben) . '</div>';
if (!$chargeGeb) echo '<div class="bx-panel" style="padding:10px 14px;font-size:13px;color:var(--muted)">Chargennummer <strong>' . h($chargeNr) . '</strong> und MHD <strong>' . h(date('d.m.Y', strtotime($chargeMhd))) . '</strong> in die Produktionsgeräte eintragen. Teilproduktionen an weiteren Tagen erhalten dieselbe Basis mit <strong>.B</strong>, <strong>.C</strong> …</div>';

// Pulver/Granulat: Füllmenge je Packung (g) setzen/korrigieren (nur Admin). Wichtig für Alt-/v3-Aufträge, bei
// denen hier noch eine Portionszahl statt Gramm steht (dann rechnet der Bedarf zu niedrig). Portion = Summe der
// Rezeptur-mg; als Hilfe zeigen wir, wie viele Portionen die aktuelle Füllmenge ergibt.
if ($istFuellGewicht && has_role('admin')):
    $portionMg = (float) scalar("SELECT COALESCE(SUM(menge_mg),0) FROM rezeptur_zutat WHERE rezeptur_id=(SELECT rezeptur_id FROM produkt WHERE id=?)", [(int)$pa['produkt_id']]);
    if ($portionMg <= 0) $portionMg = (float) scalar("SELECT COALESCE(SUM(menge_mg),0) FROM rezeptur_zutat WHERE rezeptur_id=?", [(int)($pa['rezeptur_id'] ?? 0)]);
    $portionG = $portionMg > 0 ? $portionMg / 1000.0 : 0.0;
?>
  <div class="bx-panel" style="border-left:3px solid var(--gruen)">
    <div style="font-weight:600">Füllmenge je Packung (Pulver)</div>
    <div class="muted" style="font-size:13px;margin:4px 0 10px">Bei Pulver wird nach <strong>Gewicht</strong> gerechnet: aktuell <strong><?= number_format($einhProP, 0, ',', '.') ?> g</strong> je Packung<?php if ($portionG > 0 && ($tdTxt = tagesdosen_txt((float)$einhProP, $portionMg)) !== ''): ?> &middot; nur zur Kontrolle: <?= h($tdTxt) ?> (Rezeptur = 1 Tagesdosis)<?php endif; ?>. Steht hier versehentlich eine Zahl von Tagesdosen/Portionen (Alt-/v3-Auftrag), hier die echte Füllmenge in Gramm eintragen.</div>
    <form method="post" class="bx-row" style="gap:10px;align-items:flex-end;margin:0;flex-wrap:wrap">
      <input type="hidden" name="aktion" value="pulver_fuellmenge">
      <label style="display:flex;flex-direction:column;gap:3px;font-size:12px" class="muted">Füllmenge je Packung (g)
        <input type="text" inputmode="decimal" name="fuellmenge_g" value="<?= number_format($einhProP, 0, ',', '') ?>" style="min-width:140px"></label>
      <button class="btn btn-primary btn-sm" type="submit">Füllmenge setzen</button>
    </form>
  </div>
<?php endif;

$mfmt = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');

// ============ Panels in Puffer sammeln, danach in klarer Reihenfolge ausgeben ============

// --- Weg-Hinweis (verkürzt/voll) ---
ob_start(); ?>
<?php if ($istVollerWeg && $wegUmstellbar): ?>
<div class="bx-panel" style="border-color:var(--gruen);background:rgba(29,158,117,.06)">
  <?php if ($istZukauf): ?>
  <h2 style="margin-top:0">Fertige Bulkware erkannt – verkürzter Weg möglich</h2>
  <p style="margin-top:0">Für diesen Auftrag ist bereits <strong>fertige Bulkware</strong> (z. B. fertige Kapseln vom Lieferanten) eingegangen. Damit entfallen <strong>Rohstoffe bereitstellen, Mischen und Verkapseln</strong> – es bleibt nur Bereitstellen, Verpacken, Etikettieren und die Prüfung/Freigabe.</p>
  <?php else: ?>
  <h2 style="margin-top:0">Verkürzter Weg (Zukauf)</h2>
  <p style="margin-top:0">Hast du die <strong>Kapseln/Bulkware schon fertig</strong> (zugekauft, bereits im Lager)? Dann brauchst du keine Rohstoffe/Mischen/Verkapseln – der Weg verkürzt sich auf <strong>Fertigware bereitstellen · Verpacken · Etikettieren · Qualitätsprüfung · Freigaben</strong>.</p>
  <?php endif; ?>
  <?php if ($done === 0 && !empty($pa['auftrag_id'])): ?><button type="button" class="btn btn-primary" onclick="bxWegFestlegen(<?= (int)$pa['auftrag_id'] ?>)">Weg festlegen</button>
  <?php else: ?><form method="post" style="margin:0"><input type="hidden" name="aktion" value="weg_zukauf"><button class="btn btn-primary" type="submit">Verkürzten Weg anwenden</button></form><?php endif; ?>
</div>
<?php elseif ($istVollerWeg && $herstOffen && !pa_ist_bulk($pa)): ?>
<div class="bx-panel" style="padding:12px 16px">
  <div class="bx-row" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <span class="muted">Mit fertigen Kapseln produziert (aus dem Lager oder zugekauft)? Dann fallen die offenen Schritte <?= h(implode(', ', $herstOffen)) ?> weg, dafür kommt „Fertigware bereitstellen“. Erledigte Schritte bleiben.</span>
    <form method="post" style="margin:0"><input type="hidden" name="aktion" value="weg_zukauf"><button class="btn btn-ghost btn-sm" type="submit">Auf fertige Kapseln umstellen</button></form>
  </div>
</div>
<?php elseif (!$istVollerWeg && $wegUmstellbar): ?>
<div class="bx-panel" style="padding:12px 16px">
  <div class="bx-row" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <span class="muted">Dieser Auftrag läuft auf dem <strong>verkürzten Weg</strong> (zugekaufte Bulkware – ohne Mischen/Verkapseln).</span>
    <?php if ($done === 0 && !empty($pa['auftrag_id'])): ?><button type="button" class="btn btn-ghost btn-sm" onclick="bxWegFestlegen(<?= (int)$pa['auftrag_id'] ?>)">Weg festlegen</button>
    <?php else: ?><form method="post" style="margin:0"><input type="hidden" name="aktion" value="weg_voll"><button class="btn btn-ghost btn-sm" type="submit">Auf vollen Produktionsweg umstellen</button></form><?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php if (!empty($pa['auftrag_id'])) { require_once BX_ROOT . '/core/weg_festlegen.php'; echo weg_festlegen_dialog(); } ?>
<?php
// Rückabwicklung: Fremd-Bulk beim Lieferanten bestellt, soll aber doch selbst produziert werden.
$bulkBestellOffen = $pa['auftrag_id'] ? (int) scalar(
    "SELECT COUNT(*) FROM bestellung b JOIN bestellung_position bp ON bp.bestellung_id=b.id
     WHERE bp.auftrag_id=? AND bp.item_id IS NULL AND bp.bezeichnung LIKE 'Bulk%' AND b.status<>'storniert'",
    [(int)$pa['auftrag_id']]) : 0;
if (($pa['produktionsart'] ?? '') === 'fremd' && $bulkBestellOffen > 0 && $done === 0): ?>
<div class="bx-panel" style="border-color:#e6c4c0;background:rgba(230,196,192,.10)">
  <h2 style="margin-top:0">Doch selbst produzieren (Rückabwicklung)</h2>
  <p style="margin-top:0">Der Lieferant kann/will den Auftrag <strong>nicht</strong> fremdfertigen? Dieser Knopf <strong>storniert die Bulk-Bestellung beim Lieferanten</strong> (er wird per Nachricht und Mail informiert) und stellt auf <strong>Eigenproduktion</strong> um – danach steht der Rohstoffbedarf im Einkauf. Verpackungs-/Etikett-Bestellungen bleiben erhalten. Nur möglich, solange nichts produziert und keine Bulkware eingebucht ist.</p>
  <form method="post" style="margin:0" onsubmit="return confirm('Bulk-Bestellung stornieren, Lieferant informieren und auf Eigenproduktion umstellen?');">
    <input type="hidden" name="aktion" value="rueckabwicklung">
    <button class="btn btn-primary" type="submit">Doch selbst produzieren</button>
  </form>
</div>
<?php endif; ?>
<?php $panWeg = ob_get_clean();

// --- Wartet auf Material ---
ob_start(); ?>
<?php if ($ber['status'] === 'wartet' && $ber['fehlend']): ?>
<div class="bx-panel" style="border-color:#e6c4c0">
  <h2 style="margin-top:0">Wartet auf Material</h2>
  <p class="muted" style="margin-top:0">Für die Produktion fehlt noch Bestand. Sobald alles da ist, wird der Auftrag „produktionsbereit".</p>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Material</th><th class="bx-num">Benötigt</th><th class="bx-num">Verfügbar</th><th class="bx-num">Fehlt</th></tr></thead>
    <tbody>
      <?php foreach ($ber['fehlend'] as $f): $fehlt = (float)$f['benoetigt'] - (float)$f['verfuegbar']; ?>
        <tr>
          <td><?= item_link_html((int)($f['item_id'] ?? 0), (string)$f['name']) ?></td>
          <td class="bx-num"><?= $mfmt($f['benoetigt']) ?> <?= h($f['einheit']) ?></td>
          <td class="bx-num"><?= $mfmt($f['verfuegbar']) ?> <?= h($f['einheit']) ?></td>
          <td class="bx-num"><?= bx_fehlt_quar(max(0,$fehlt), (float)($f['quarantaene'] ?? 0), $mfmt(max(0,$fehlt)) . ' ' . $f['einheit'], $mfmt((float)($f['quarantaene'] ?? 0)) . ' ' . $f['einheit'], quarantaene_freigabe_url((int)($f['item_id'] ?? 0))) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <div style="margin-top:12px"><a class="btn btn-ghost btn-sm" href="?p=wareneingang">Zum Wareneingang</a></div>
</div>
<?php endif; ?>
<?php $panWartet = ob_get_clean();

// --- Jetzt dran (aktueller Schritt) ---
ob_start(); ?>
<?php if ($firstOpenId && $pa['status'] !== 'erledigt'):
    $curStep = null; foreach ($schritte as $s) if ((int)$s['id'] === $firstOpenId) { $curStep = $s; break; }
    $anl = station_anleitung($curStep['station']);
    $isGate = str_contains($curStep['station'], 'Freigabe');
    // Anleitungstext ohne den Scan-Satz anzeigen (es gibt noch keine Etiketten/Barcodes zum Scannen).
    $anlText = trim(preg_replace('/\s*[A-ZÄÖÜ]?[Ss]canne[^.]*\.\s*/u', ' ', $anl['text']));
?>
<div class="bx-panel" style="border-color:var(--gruen);background:rgba(29,158,117,.05)">
  <div class="muted">Jetzt dran – Schritt <?= $done + 1 ?> von <?= $total ?></div>
  <h2 style="margin:4px 0 8px"><?= h($curStep['station']) ?> <?= $isGate ? bx_badge('Gate','info') : '' ?></h2>
  <?php if ($anlText !== ''): ?><p style="margin:0 0 12px;font-size:15px"><?= h($anlText) ?></p><?php endif; ?>
  <?php $qmE = in_array($curStep['station'], produktion_qm_stationen(), true); if ($qmE || has_role('admin')): ?>
  <form method="post" style="margin:0"<?= $qmE ? '' : ' ' . pa_admin_grund_js() ?>>
    <input type="hidden" name="aktion" value="erledigen"><input type="hidden" name="grund" value="">
    <input type="hidden" name="schritt" value="<?= (int)$firstOpenId ?>">
    <button class="btn <?= $qmE ? 'btn-primary' : 'btn-ghost' ?>" type="submit"><?= $isGate ? 'Freigeben' : ($qmE ? 'Erledigt' : 'Ohne Menge abhaken (Admin)') ?></button>
  </form>
  <?php else: ?><p class="muted" style="margin:0">Produziert wird in der Werk-App mit Mengen (Teilmengen, Kartons) – oder unten „Teilmenge nachtragen“.</p><?php endif; ?>
</div>
<?php endif; ?>
<?php $panJetzt = ob_get_clean();

// --- Ablauf: Stationen & Freigaben ---
ob_start(); ?>
<div class="bx-panel">
  <h2 style="margin-top:0">Ablauf</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <tbody>
    <?php foreach ($schritte as $s):
        $isDone = (int)$s['erledigt'] === 1;
        $isNext = (int)$s['id'] === $firstOpenId;
        $isGate = str_contains($s['station'], 'Freigabe');
    ?>
      <tr<?= $isNext ? ' style="background:var(--panel-2)"' : '' ?>>
        <td style="width:44px;text-align:center;font-size:18px">
          <?= $isDone ? '<span class="bx-ok">&#10003;</span>' : ($isNext ? '&#9654;' : '<span class="muted">&#9675;</span>') ?>
        </td>
        <td>
          <strong<?= $isDone ? '' : ($isNext ? '' : ' class="muted"') ?>><?= h($s['station']) ?></strong>
          <?= $isGate ? ' ' . bx_badge('Gate','info') : '' ?>
        </td>
        <td class="muted"><?= $isDone && $s['erledigt_at'] ? h(fmt_zeit($s['erledigt_at'])) : '' ?><?= $isDone && !empty($s['erledigt_von']) ? ' · ' . h($s['erledigt_von']) : '' ?><?= $isDone && !empty($s['scan_charge']) ? ' · Charge ' . h($s['scan_charge']) : '' ?></td>
        <td style="width:160px;text-align:right">
          <?php if ($isNext): ?>
            <?php $qmS = in_array($s['station'], produktion_qm_stationen(), true); if ($qmS || has_role('admin')): ?>
            <form method="post" style="margin:0"<?= $qmS ? '' : ' ' . pa_admin_grund_js() ?>>
              <input type="hidden" name="aktion" value="erledigen"><input type="hidden" name="grund" value="">
              <input type="hidden" name="schritt" value="<?= (int)$s['id'] ?>">
              <button class="btn <?= $qmS ? 'btn-primary' : 'btn-ghost' ?> btn-sm" type="submit"><?= $isGate ? 'Freigeben' : ($qmS ? 'Erledigt' : 'abhaken (Admin)') ?></button>
            </form>
            <?php else: ?><span class="muted">in der Werk-App</span><?php endif; ?>
          <?php elseif ($isDone): ?>
            <span class="badge badge-ok">erledigt</span>
          <?php else: ?>
            <span class="muted">wartet</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php $panStationen = ob_get_clean();

// --- Fertigware eingebucht ---
ob_start(); ?>
<?php if ($fertigware): ?>
<div class="bx-panel" style="border-color:var(--gruen);background:var(--panel-2)">
  <h2>Fertigware eingebucht</h2>
  <div class="bx-row" style="justify-content:space-between;margin-bottom:10px">
    <div><strong><?= h($fertigware['name']) ?></strong> · <?= h($fertigware['artikelnummer']) ?></div>
    <div><?= bx_badge(number_format($fwGebucht,0,',','.') . ' von ' . number_format((float)$pa['menge'],0,',','.') . ' Stk gebucht', $fwRest > 0 ? 'warn' : 'ok') ?> <a class="btn btn-ghost btn-sm" href="?p=lager&kat=verkaufsfertig">zum Lager</a></div>
  </div>
  <div style="overflow-x:auto"><table class="bx-table">
    <thead><tr><th>Charge</th><th>Gebucht</th><th>Im Lager</th><th>MHD</th><th>Eingebucht am</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($fwChargen as $c): ?>
      <tr>
        <td>
          <form method="post" style="margin:0;display:flex;gap:4px;align-items:center">
            <input type="hidden" name="aktion" value="charge_edit"><input type="hidden" name="charge_id" value="<?= (int)$c['id'] ?>">
            <input type="text" name="charge_nr" value="<?= h($c['charge_nr']) ?>" style="width:120px;padding:3px 6px" title="Chargennummer bearbeiten">
            <button class="btn btn-ghost btn-sm" type="submit" title="Chargennummer speichern">✓</button>
            <a class="btn btn-ghost btn-sm" href="?p=chargen&id=<?= (int)$c['id'] ?>" title="Charge öffnen">↗</a>
          </form>
        </td>
        <td><?= number_format((float)$c['menge'],0,',','.') ?></td>
        <td><?= number_format((float)$c['menge_verfuegbar'],0,',','.') ?></td>
        <td><?= $c['mhd'] ? h(date('d.m.Y', strtotime($c['mhd']))) : '–' ?></td>
        <td><?= $c['wareneingang'] ? h(date('d.m.Y', strtotime($c['wareneingang']))) : '–' ?></td>
        <td><?= h(status_text($c['status'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
<?php $panFertig = ob_get_clean();

// --- Teilmenge einbuchen ---
ob_start(); ?>
<?php if ($pa['produkt_id'] && $fwRest > 0): ?>
<div class="bx-panel">
  <h2>Teilmenge einbuchen</h2>
  <p class="muted" style="margin-top:0">Wird an mehreren Tagen produziert, kann jede fertige Teilmenge sofort als eigene Charge ins Lager
    (<?= h(charge_naechste_nr($id)) ?> ist die nächste Nummer). Das Material (Rohstoffe, Kapseln, Behälter, Deckel, Etikett) wird <strong>anteilig</strong>
    abgebucht und vorher geprüft; beim Abschluss des Auftrags wird nur noch der Rest gebucht.
    Noch offen: <strong><?= number_format($fwRest,0,',','.') ?> Stk</strong>.</p>
  <?php $tmM = produktion_teilmenge_machbar($id); $tmSperre = produktion_etikett_sperre($id); ?>
  <div class="bx-panel" style="background:var(--panel-2);padding:10px 14px;margin:0 0 12px;border-left:3px solid <?= $tmSperre !== '' ? '#b23b2f' : 'var(--gruen,#1D9E75)' ?>">
    <?php if ($tmSperre !== ''): ?><strong style="color:#8f231b">Gesperrt:</strong> <?= h($tmSperre) ?>
    <?php else: ?>
      <strong>Jetzt machbar:</strong> <?= number_format($tmM['komplett'], 0, ',', '.') ?> Stk komplett fertig<?= $tmM['komplett'] < $tmM['rest'] && $tmM['limit_komplett'] !== '' ? ' <span class="muted">(begrenzt durch ' . h((string)$tmM['limit_komplett']) . ')</span>' : '' ?>
      <?php if ($tmM['vor_etikett'] > $tmM['komplett']): ?> · bis vor dem Etikettieren <?= number_format($tmM['vor_etikett'], 0, ',', '.') ?> Stk<?php endif; ?>
    <?php endif; ?>
  </div>
  <p class="muted" style="margin:0">Teilmengen bucht nur noch der geführte Ablauf: in der Werk-App unter Abfüllen/Verpacken bzw. hier unter „Teilmenge nachtragen“.</p>
</div>
<?php endif; ?>
<?php $panTeil = ob_get_clean();

// --- Zusammensetzung (Rezeptur) ---
ob_start(); ?>
<?php if ($zutatenD): $sumMg = 0.0; foreach ($zutatenD as $zz) $sumMg += (float)$zz['menge_mg']; ?>
<div class="bx-panel">
  <h2>Zusammensetzung je Einheit</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Bestandteil</th><th class="bx-num">mg je Einheit</th></tr></thead>
    <tbody>
      <?php foreach ($zutatenD as $zz): ?>
      <tr><td><?= h((string)$zz['name']) ?></td><td class="bx-num"><?= (float)$zz['menge_mg'] > 0 ? rtrim(rtrim(number_format((float)$zz['menge_mg'],3,',','.'),'0'),',') . ' mg' : '<span class="muted">–</span>' ?></td></tr>
      <?php endforeach; ?>
      <tr><td><strong>Füllgewicht</strong></td><td class="bx-num"><strong><?= rtrim(rtrim(number_format($sumMg,3,',','.'),'0'),',') ?> mg</strong></td></tr>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
<?php $panZus = ob_get_clean();

// --- Material (entnommen / Bedarf) – nur Eigenproduktion/Historie ---
ob_start(); ?>
<?php if ($verbrauch || ($istVollerWeg && ($bedarf || $kapNeed))): ?>
<div class="bx-panel">
  <?php if ($verbrauch): ?>
    <h2 style="margin-top:0">Entnommene Materialien (FEFO)</h2>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Rohstoff</th><th>Charge</th><th>Lieferant</th><th class="bx-num">Entnommen</th><th>Datum</th></tr></thead>
      <tbody>
      <?php foreach ($verbrauch as $vb): ?>
        <tr><td><?= item_link_html((int)($vb['item_id'] ?? 0), (string)($vb['item_name'] ?: '–')) ?></td><td><?= h($vb['charge_nr'] ?: '–') ?></td>
            <td class="muted"><?= h($vb['lieferant'] ?: '–') ?></td>
            <td class="bx-num"><?= $mng($vb['menge'], $vb['einheit']) ?></td>
            <td class="muted"><?= !empty($vb['angelegt']) ? h(date('d.m.Y', strtotime((string)$vb['angelegt']))) : '–' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="muted" style="margin-top:8px">Bestand wurde beim Bereitstellen abgebucht.</div>
  <?php else: ?>
    <h2 style="margin-top:0">Materialbedarf</h2>
    <?php if ($pa['status'] === 'erledigt'): ?>
      <div class="muted" style="margin:0 0 10px">Dieser Auftrag ist abgeschlossen, es wurde aber kein Material über die Produktion abgebucht (z. B. per God-Mode oder als Alt-Auftrag ohne Bestandsbuchung durchgelaufen). Die folgende Liste ist daher nur der rechnerische Bedarf – kein echter Fehlbestand.</div>
    <?php endif; ?>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Rohstoff</th><th class="bx-num">Benötigt</th><th class="bx-num">Verfügbar</th><th>Status</th></tr></thead>
      <tbody>
      <?php $erl = ($pa['status'] === 'erledigt'); ?>
      <?php foreach ($bedarf as $b): $ok = $b['fehlt'] <= 0.0001; ?>
        <tr>
          <td><?= item_link_html((int)($b['item_id'] ?? 0), (string)$b['name']) ?></td>
          <td class="bx-num"><?= $mng($b['benoetigt'], $b['einheit']) ?></td>
          <td class="bx-num"><?= $mng($b['verfuegbar'], $b['einheit']) ?></td>
          <td><?= $ok ? bx_badge('genug','ok') : ($erl ? bx_badge('nicht gebucht','info') : bx_fehlt_quar((float)$b['fehlt'], (float)($b['quarantaene'] ?? 0), html_entity_decode($mng($b['fehlt'], $b['einheit'])), html_entity_decode($mng((float)($b['quarantaene'] ?? 0), $b['einheit'])), quarantaene_freigabe_url((int)$b['item_id']))) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($kapNeed): $okK = $kapNeed['fehlt'] <= 0.0001; ?>
        <tr>
          <td><?= item_link_html((int)($kapNeed['item_id'] ?? 0), (string)$kapNeed['name']) ?> <?= bx_badge('Kapselhülle','info') ?></td>
          <td class="bx-num"><?= number_format($kapNeed['benoetigt'],0,',','.') ?> Stk</td>
          <td class="bx-num"><?= number_format($kapNeed['verfuegbar'],0,',','.') ?> Stk</td>
          <td><?= $okK ? bx_badge('genug','ok') : ($erl ? bx_badge('nicht gebucht','info') : bx_badge('fehlt ' . number_format($kapNeed['fehlt'],0,',','.') . ' Stk','err')) ?></td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table></div>
    <div class="muted" style="margin-top:8px">Beim Abschluss von „Rohstoffe bereitstellen" werden die Chargen nach FEFO (älteste MHD zuerst) abgebucht.</div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php $panMaterial = ob_get_clean();

// --- Einkaufsbedarf (Stückliste vs. Bestand) ---
ob_start();
// Materialbedarf auf den NOCH offenen Rest (schon produzierte Packungen abgezogen) – gleiche Quelle wie
// die Material-Übersicht der Auftragsseite und produktion_bereitschaft(), damit überall dieselbe Zahl steht.
$bedarfListe = auftrag_bedarf_rest($id);
$fehlListe   = auftrag_fehlbedarf($id);
$pgZiel = max(0, (int)$pa['menge']); $pgGeb = (int) round(produktion_gebucht($id)); $pgRest = max(0, $pgZiel - $pgGeb);
if ($bedarfListe):
    $rolleBadge = fn($r) => match ($r) {
        'Rohstoff'=>bx_badge('Rohstoff'), 'Leerkapsel'=>bx_badge('Leerkapsel'), 'Fertigware'=>bx_badge('Fertigware','info'),
        'Verpackung'=>bx_badge('Verpackung'), 'Deckel'=>bx_badge('Deckel'), 'Etikett'=>bx_badge('Etikett'),
        'Karton'=>bx_badge('Karton'), 'Beipackzettel'=>bx_badge('Beipack'), default=>bx_badge($r) };
?>
<div class="bx-panel"<?= $fehlListe ? ' style="border-color:#e6c4c0"' : '' ?>>
  <?php $hatReserviert = false; foreach ($bedarfListe as $rr) if (($rr['reserviert_eigen'] ?? 0) > 1e-6) { $hatReserviert = true; break; } ?>
  <div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
    <?php $nochFehlt = 0; foreach ($bedarfListe as $rr) if ((float)$rr['fehlt'] > 1e-6) $nochFehlt++; ?>
    <h2 style="margin:0">Material <?= $fehlListe ? bx_badge(count($fehlListe) . ' fehlt / zu bestellen','err') : ($nochFehlt ? bx_badge('nichts zu bestellen · ' . $nochFehlt . ' noch unterwegs','warn') : bx_badge('alles vorhanden','ok')) ?></h2>
    <div class="bx-row" style="gap:8px">
      <?php if ($pa['status'] !== 'erledigt'): ?>
        <form method="post" style="margin:0"><input type="hidden" name="aktion" value="reservieren">
          <button class="btn btn-ghost btn-sm" type="submit">Bestand reservieren</button></form>
        <?php if ($hatReserviert): ?>
          <form method="post" style="margin:0"><input type="hidden" name="aktion" value="reservierung_frei">
            <button class="btn btn-ghost btn-sm" type="submit">Reservierung freigeben</button></form>
        <?php endif; ?>
        <?php if ($pa['auftrag_id']): ?>
          <a class="btn btn-ghost btn-sm" href="?p=auftrag&id=<?= (int)$pa['auftrag_id'] ?>#bulkreserve" title="Mix-Produktion: einen Teil der Menge als fertige Bulkware zukaufen/reservieren – der Rest wird selbst produziert">Mix: Zukauf reservieren</a>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($fehlListe): ?>
        <a class="btn btn-accent btn-sm" href="?p=einkaufsliste">Zur Bestellseite</a>
        <form method="post" style="margin:0"><input type="hidden" name="aktion" value="bedarf_bestellen">
          <button class="btn btn-primary btn-sm" type="submit" title="Als Entwurf je Hauptlieferant anlegen">Bestellung(en) anlegen</button>
          <button class="btn btn-ghost btn-sm" type="submit" name="modus" value="direkt" title="Schon per Telefon/Mail beim Hauptlieferanten bestellt: sofort bestellt + bestätigt, keine Mail">Direkt bestellt</button></form>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($pgGeb > 0): ?>
    <div style="background:var(--panel-2);border-radius:8px;padding:8px 12px;margin-top:10px;font-size:13px">
      Restbedarf: <strong><?= number_format($pgGeb, 0, ',', '.') ?></strong> von <?= number_format($pgZiel, 0, ',', '.') ?> Packungen sind schon produziert – das Material unten gilt nur noch für die <strong><?= number_format($pgRest, 0, ',', '.') ?></strong> offenen Packungen.
    </div>
  <?php endif; ?>
  <?php $bulkResv = $pa['auftrag_id'] ? auftrag_bulk_reserviert_stueck((int)$pa['auftrag_id']) : 0.0;
        if ($bulkResv > 0): ?>
    <div style="background:var(--panel-2);border-radius:8px;padding:8px 12px;margin-top:10px;font-size:13px">
      Mix-Produktion: <strong><?= number_format($bulkResv, 0, ',', '.') ?> Stk</strong> sind als fertige Bulkware (Zukauf) reserviert – Rohstoff- &amp; Leerkapselbedarf unten gelten nur für den <strong>selbst produzierten</strong> Teil. Verpackung &amp; Etikett bleiben für die volle Menge.
    </div>
  <?php endif; ?>
  <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table">
    <thead><tr><th style="width:34px"></th><th>Komponente</th><th></th><th class="bx-num">Benötigt</th><th class="bx-num">Verfügbar (netto)</th><th class="bx-num">Reserviert</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($bedarfListe as $r): $fehlt = (float)$r['fehlt']; $res = (float)($r['reserviert_eigen'] ?? 0); $ok = $fehlt <= 1e-6;
            $tint = $ok ? 'rgba(29,158,117,.08)' : 'rgba(143,35,27,.08)'; ?>
        <tr style="background:<?= $tint ?>">
          <td style="text-align:center;font-size:16px"><?= $ok ? '<span class="bx-ok">&#10003;</span>' : '<span style="color:#8f231b">&#10007;</span>' ?></td>
          <td><?php $mIid = (int)($r['item_id'] ?? 0); $mLink = $mIid > 0 ? $mIid : pa_material_bulk_item((int)($pa['produkt_id'] ?? 0)); ?>
            <?= item_link_html($mLink, (string)$r['name']) ?>
            <?php foreach (pa_material_bestellungen((int)($pa['auftrag_id'] ?? 0), $mIid, (int)($pa['produkt_id'] ?? 0)) as $mB) echo pa_material_bestellung_html($mB); ?></td>
          <td><?= $rolleBadge($r['rolle']) ?></td>
          <td class="bx-num"><?= $mfmt($r['benoetigt']) ?> <?= h($r['einheit']) ?></td>
          <td class="bx-num"><?= $mfmt($r['verfuegbar']) ?> <?= h($r['einheit']) ?></td>
          <td class="bx-num"><?= $res > 1e-6 ? '<span class="bx-ok">' . $mfmt($res) . ' ' . h($r['einheit']) . '</span>' : '<span class="muted">–</span>' ?></td>
          <td><?= $ok ? bx_badge('vorhanden','ok') : bx_fehlt_quar((float)$fehlt, (float)($r['quarantaene'] ?? 0), $mfmt($fehlt) . ' ' . $r['einheit'], $mfmt((float)($r['quarantaene'] ?? 0)) . ' ' . $r['einheit'], quarantaene_freigabe_url((int)($r['item_id'] ?? 0))) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12px;margin:10px 0 0"><span class="bx-ok">grün</span> = auf Lager, <span style="color:#8f231b">rot</span> = fehlt noch. „Verfügbar (netto)" = freier Bestand minus Reservierungen anderer Aufträge.<?= $fehlListe ? ' „Zur Bestellseite" öffnet die Einkaufsliste; „Bestellung(en) anlegen" erzeugt je Hauptlieferant direkt einen Entwurf (mit Auftragsbezug, Netting gegen offene Bestellungen).' : '' ?></p>
</div>
<?php endif; $panEinkauf = ob_get_clean();

// --- Bestellungen für diesen Auftrag ---
ob_start();
$auftBestellungen = $pa['auftrag_id'] ? all(
    "SELECT DISTINCT b.id, b.nummer, b.status, l.firma AS lieferant
     FROM bestellung b JOIN bestellung_position bp ON bp.bestellung_id=b.id
     LEFT JOIN lieferanten l ON l.id=b.lieferant_id
     WHERE bp.auftrag_id=? ORDER BY b.id DESC", [(int)$pa['auftrag_id']]) : [];
if ($auftBestellungen):
    $darfEinkauf = function_exists('route_erlaubt') ? route_erlaubt('bestellung') : true;
    $beStatus = fn($s) => match ($s) { 'offen'=>bx_badge('Entwurf','info'),'bestellt'=>bx_badge('bestellt','warn'),'geliefert'=>bx_badge('geliefert','ok'),default=>bx_badge($s) };
?>
<div class="bx-panel">
  <h2 style="margin-top:0">Bestellungen für diesen Auftrag</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Bestellung</th><th>Lieferant</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($auftBestellungen as $b): ?>
        <tr<?= $darfEinkauf ? ' onclick="location.href=\'?p=bestellung&id=' . (int)$b['id'] . '\'" style="cursor:pointer"' : '' ?>>
          <td><?= $darfEinkauf ? '<a href="?p=bestellung&id=' . (int)$b['id'] . '">' . h($b['nummer']) . '</a>' : h($b['nummer']) ?></td>
          <td><?= $b['lieferant'] ? h($b['lieferant']) : '<span class="muted">– noch offen</span>' ?></td>
          <td><?= $beStatus($b['status']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; $panBestellungen = ob_get_clean();

// --- Wareneingänge für diesen Auftrag ---
ob_start();
$zugeChargen = $pa['auftrag_id'] ? all(
    "SELECT c.*, i.name AS item_name, i.kategorie AS kategorie, i.form AS form
     FROM charge c LEFT JOIN item i ON i.id=c.item_id
     WHERE c.auftrag_id=? ORDER BY c.angelegt DESC", [(int)$pa['auftrag_id']]) : [];
if ($zugeChargen):
    $katLbl = ['rohstoff'=>'Rohstoff','verpackung'=>'Verpackung','fertig'=>'Fertigware (Bulk)','verkaufsfertig'=>'Fertigware'];
?>
<div class="bx-panel">
  <h2 style="margin-top:0">Wareneingänge für diesen Auftrag</h2>
  <p class="muted" style="margin-top:0">Diese Lieferungen wurden gezielt für diesen Auftrag bestellt/eingebucht.</p>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Charge</th><th>Artikel</th><th>Art</th><th class="bx-num">Menge</th><th>MHD</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($zugeChargen as $c): ?>
        <tr onclick="location.href='?p=chargen&id=<?= (int)$c['id'] ?>'" style="cursor:pointer">
          <td><?= h($c['charge_nr'] ?: ('#'.$c['id'])) ?></td>
          <td><?= h($c['item_name'] ?: '–') ?></td>
          <td><?= h($katLbl[$c['kategorie']] ?? $c['kategorie']) ?><?= $c['form'] ? ' · ' . h($c['form']) : '' ?></td>
          <td class="bx-num"><?= $mng($c['menge_verfuegbar'], $c['einheit']) ?></td>
          <td><?= $c['mhd'] ? h(date('d.m.Y', strtotime($c['mhd']))) : '–' ?></td>
          <td><?= match($c['status']){'frei'=>bx_badge('freigegeben','ok'),'quarantaene'=>bx_badge('Quarantäne (Prüfung)','warn'),'leer'=>bx_badge('verbraucht'),default=>bx_badge(status_text($c['status']))} ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; $panWareneingaenge = ob_get_clean();

// --- Etikett-Design ---
ob_start();
$etHatSlot = (int) scalar("SELECT etikett_id FROM produkt WHERE id=?", [(int)$pa['produkt_id']]) > 0;
$etDok = $pa['auftrag_id'] ? etikett_datei((int)$pa['auftrag_id']) : null;
$etFrei = $pa['auftrag_id'] ? etikett_freigegeben((int)$pa['auftrag_id']) : false;
$etFa   = $etFrei ? one("SELECT etikett_freigabe_am, etikett_freigabe_von FROM auftrag WHERE id=?", [(int)$pa['auftrag_id']]) : null;
if ($etHatSlot):
  $etStatusBadge = $etFrei ? bx_badge('freigegeben','ok') : ($etDok ? bx_badge('nicht freigegeben – gesperrt','warn') : bx_badge('Design fehlt – gesperrt','warn'));
?>
<div class="bx-panel"<?= $etFrei ? '' : ' style="border-color:#e6c4c0"' ?>>
  <h2 style="margin-top:0">Etikett <?= $etStatusBadge ?></h2>
  <?php if ($etFrei): ?>
    <p style="margin:0 0 8px"><strong>Vom Kunden freigegeben</strong><?= !empty($etFa['etikett_freigabe_am']) ? ' am ' . h(fmt_zeit($etFa['etikett_freigabe_am'], 'd.m.Y')) : '' ?><?= !empty($etFa['etikett_freigabe_von']) ? ' durch ' . h($etFa['etikett_freigabe_von']) : '' ?>.</p>
  <?php else: ?>
    <p style="margin-top:0;color:#8f231b">Ohne <strong>Freigabe des Kunden</strong> können die Etiketten nicht bestellt und die Produktion nicht gestartet werden. Der Kunde gibt das Etikett im Portal frei – oder bestätige hier eine Freigabe, die anderweitig (Mail/Telefon) erteilt wurde.</p>
  <?php endif; ?>
  <?php if ($etDok): ?>
    <div class="bx-row" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
      <div><a href="?p=dokument&id=<?= (int)$etDok['id'] ?>" target="_blank"><?= h($etDok['datei_orig'] ?: 'Etikett-Design') ?></a> <span class="muted">· hochgeladen <?= h(fmt_zeit($etDok['angelegt'], 'd.m.Y')) ?></span></div>
      <div class="bx-row" style="gap:8px">
        <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:6px;margin:0"><input type="hidden" name="aktion" value="etikett_upload"><input type="file" name="etikett" required accept="application/pdf,image/*"><button class="btn btn-ghost btn-sm" type="submit">Ersetzen</button></form>
        <form method="post" style="margin:0" onsubmit="return confirm('Etikett-Design löschen?');"><input type="hidden" name="aktion" value="etikett_del"><button class="btn btn-ghost btn-sm" type="submit">Löschen</button></form>
      </div>
    </div>
  <?php else: ?>
    <p class="muted" style="margin:0 0 8px">Der Kunde kann das Etikett im Portal hochladen – oder lade die vom Kunden erhaltene Datei hier hoch.</p>
    <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:8px;align-items:center;margin:0"><input type="hidden" name="aktion" value="etikett_upload"><input type="file" name="etikett" required accept="application/pdf,image/*"><button class="btn btn-primary btn-sm" type="submit">Etikett-Design hochladen</button></form>
  <?php endif; ?>
  <?php if (!$etFrei): ?>
    <form method="post" class="bx-row" style="gap:8px;align-items:center;margin:10px 0 0;flex-wrap:wrap;border-top:1px solid var(--line);padding-top:10px">
      <input type="hidden" name="aktion" value="etikett_freigeben_team">
      <input type="text" name="freigabe_name" placeholder="Name der freigebenden Person (Kunde)" style="padding:7px 10px;border:1px solid var(--line);border-radius:8px;min-width:230px">
      <button class="btn btn-ghost btn-sm" type="submit">Freigabe des Kunden bestätigen</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; $panEtikett = ob_get_clean();

// --- Details ---
ob_start(); ?>
<div class="bx-panel">
  <h2 style="margin-top:0">Details</h2>
  <div class="bx-grid">
    <div><div class="k muted">Kunde</div><div><?= $pa['kunde_firma'] ? kunde_link((int)($pa['kunde_id_eff'] ?? 0) ?: null, $pa['kunde_firma'], true) : '–' ?></div></div>
    <div><div class="k muted">Aus Auftrag</div><div><?php if ($pa['auftrag_id']): ?><a href="?p=auftrag&id=<?= (int)$pa['auftrag_id'] ?>"><?= h($pa['auftrag_nr']) ?></a><?php else: ?>–<?php endif; ?></div></div>
  </div>
</div>
<?php $panDetails = ob_get_clean();

// ============ Ausgabe in klarer Reihenfolge ============
echo $panWeg;            // Weg-Hinweis (verkürzt/voll)
echo $panWartet;         // Wartet auf Material (Alarm)
pki_ui_render($id);   // Produktion melden (KI), 13.11.2026
require __DIR__ . '/_ablauf.php';   // Ein Weg (11.11.2026): Kopf, Unter-Chargen/Teilmengen/Kartons, Protokoll, Teilmenge nachtragen
echo $panJetzt;          // JETZT dran – die eine Aktion
echo $panEinkauf;        // Material (Komponenten vorhanden/fehlt) – prominent sichtbar
echo $panStationen;      // Ablauf (Checkliste)
echo $panFertig;         // Fertigware eingebucht
// $panTeil (alte „Teilmenge einbuchen“ mit eigener Charge je Buchung) entfällt – ersetzt durch „Teilmenge nachtragen“ (_ablauf.php).
echo $panZus;            // Zusammensetzung

// Einklappbar: Materialverbrauch (entnommen/FEFO) & Bestellungen (die Material-Übersicht steht oben prominent)
$grpMatEinkauf = trim($panMaterial . $panBestellungen);
if ($grpMatEinkauf !== '') {
    echo '<details class="bx-sek"><summary>Materialverbrauch &amp; Bestellungen</summary>' . $grpMatEinkauf . '</details>';
}
// Einklappbar: Wareneingänge, Etikett & Details
$grpRest = trim($panWareneingaenge . $panEtikett . $panDetails);
if ($grpRest !== '') {
    echo '<details class="bx-sek"><summary>Wareneingänge, Etikett &amp; Details</summary>' . $grpRest . '</details>';
}
render_footer();
