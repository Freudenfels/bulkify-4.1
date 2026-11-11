<?php
// Naht der Inventur zum Dashboard – SCHREIBEND (11.11.2026). Nur nach Nicos Entscheidung in der Auswertung.
// Nichts wird gelöscht: Ausgebucht = Menge 0 (Status leer) mit Verweis. Jede Feldänderung landet in aenderung_log
// (route 'lager_inventur'); die Bewegung selbst schreibt core/inventur_buchen.php in lg_bewegung (typ 'inventur').
require_once __DIR__ . '/erp_inventur.php';

function erp_inv_log(string $typ, int $id, string $feld, $alt, $neu): void {
    if (!tabelle_da('aenderung_log')) return;
    $u = function_exists('lg_uid') && lg_uid() ? erp_benutzer(lg_uid()) : null;
    q("INSERT INTO aenderung_log (objekt_typ,objekt_id,feld,alt,neu,benutzer_id,benutzer,route,erstellt) VALUES (?,?,?,?,?,?,?,?,?)",
      [$typ, $id, $feld, $alt === null ? null : (string)$alt, $neu === null ? null : (string)$neu, $u ? (int)$u['id'] : null, $u ? (string)$u['name'] : null, 'lager_inventur', gmdate('Y-m-d H:i:s')]);
}
// Bestand um $delta ändern (Einheit der Charge). Gebinde-Objekt: Gebinde und Charge; Charge ohne Gebinde: nur Charge.
// Nie unter 0. Rückgabe ['ok','alt','neu','einheit','item_name'].
function erp_inv_korrektur(int $charge_id, int $gebinde_id, float $delta): array {
    $c = one("SELECT c.*, i.name AS item_name FROM charge c JOIN item i ON i.id=c.item_id WHERE c.id=?", [$charge_id]);
    if (!$c) return ['ok' => false, 'msg' => 'Charge nicht gefunden.'];
    $alt = (float)$c['menge_verfuegbar'];
    if ($gebinde_id > 0) {
        $g = one("SELECT * FROM charge_gebinde WHERE id=? AND charge_id=?", [$gebinde_id, $charge_id]);
        if (!$g) return ['ok' => false, 'msg' => 'Gebinde nicht gefunden.'];
        $gNeu = round(max(0.0, (float)$g['menge_verfuegbar'] + $delta), 3);
        $delta = $gNeu - (float)$g['menge_verfuegbar'];   // nie unter 0
        q("UPDATE charge_gebinde SET menge_verfuegbar=?, status=? WHERE id=?",
          [$gNeu, $gNeu <= 0.0005 ? 'leer' : ($gNeu >= (float)$g['menge'] - 0.0005 ? 'voll' : 'angebrochen'), $gebinde_id]);
        if ($gNeu > (float)$g['menge']) q("UPDATE charge_gebinde SET menge=? WHERE id=?", [$gNeu, $gebinde_id]);   // mehr gefunden als je drin war
        erp_inv_log('charge_gebinde', $gebinde_id, 'menge_verfuegbar', $g['menge_verfuegbar'], $gNeu);
    }
    $neu = round(max(0.0, $alt + $delta), 3);
    $st = (string)$c['status'];
    q("UPDATE charge SET menge_verfuegbar=?, status=? WHERE id=?", [$neu, $neu <= 0.0005 ? 'leer' : ($st === 'leer' ? 'frei' : $st), $charge_id]);
    erp_inv_log('charge', $charge_id, 'menge_verfuegbar', $alt, $neu);
    erp_bedarf_bump();
    return ['ok' => true, 'alt' => $alt, 'neu' => $neu, 'delta' => round($neu - $alt, 3), 'einheit' => (string)$c['einheit'], 'item_name' => (string)$c['item_name']];
}
// Ausbuchen (Gebinde bzw. ganze Charge auf 0) mit Verweis in der Notiz. Nichts wird gelöscht.
function erp_inv_ausbuchen(int $charge_id, int $gebinde_id, string $verweis): array {
    if ($gebinde_id > 0) $soll = (float) scalar("SELECT menge_verfuegbar FROM charge_gebinde WHERE id=?", [$gebinde_id]);
    else $soll = (float) scalar("SELECT menge_verfuegbar FROM charge WHERE id=?", [$charge_id]);
    $r = erp_inv_korrektur($charge_id, $gebinde_id, -$soll);
    if ($r['ok'] && $verweis !== '') q("UPDATE charge SET notiz=TRIM(CONCAT(COALESCE(notiz,''), ?)) WHERE id=?", [' | ' . $verweis, $charge_id]);
    return $r;
}
// Einheitenfehler: Menge (gebucht + verfügbar, auch der Gebinde) mit $faktor umrechnen und Einheit setzen.
function erp_inv_einheit_korrigieren(int $charge_id, float $faktor, string $einheit): array {
    $c = one("SELECT * FROM charge WHERE id=?", [$charge_id]);
    if (!$c || $faktor <= 0) return ['ok' => false, 'msg' => 'Charge oder Faktor ungültig.'];
    $einheit = erp_einheit_norm($einheit) ?: (string)$c['einheit'];
    q("UPDATE charge SET menge=ROUND(menge*?,3), menge_verfuegbar=ROUND(menge_verfuegbar*?,3), einheit=? WHERE id=?", [$faktor, $faktor, $einheit, $charge_id]);
    if (tabelle_da('charge_gebinde')) q("UPDATE charge_gebinde SET menge=ROUND(menge*?,3), menge_verfuegbar=ROUND(menge_verfuegbar*?,3), einheit=? WHERE charge_id=?", [$faktor, $faktor, $einheit, $charge_id]);
    erp_inv_log('charge', $charge_id, 'menge_verfuegbar', $c['menge_verfuegbar'], round((float)$c['menge_verfuegbar'] * $faktor, 3));
    erp_inv_log('charge', $charge_id, 'einheit', $c['einheit'], $einheit);
    erp_bedarf_bump();
    return ['ok' => true, 'alt' => (float)$c['menge_verfuegbar'], 'neu' => round((float)$c['menge_verfuegbar'] * $faktor, 3), 'alt_einheit' => (string)$c['einheit'], 'einheit' => $einheit];
}
// Darf die Charge auf einen anderen Artikel? Gleiche Regeln wie „Artikel zusammenführen": Kapselgröße, Füllgewicht,
// Kapselmaterial, Rezeptur, Warenart, Einheit. Rückgabe: Liste der Sperrgründe (leer = erlaubt).
function erp_inv_umbuchen_sperren(int $von_item, int $ziel_item): array {
    $sel = "SELECT i.id, i.kategorie, i.einheit, i.material, i.rezeptur_id, COALESCE(i.kapselgroesse_id, r.kapselgroesse_id) AS kg, r.einheit_fuellmenge AS ff
            FROM item i LEFT JOIN rezeptur r ON r.id=i.rezeptur_id WHERE i.id=?";
    $a = one($sel, [$von_item]); $b = one($sel, [$ziel_item]);
    if (!$a || !$b) return ['Artikel nicht gefunden'];
    $g = [];
    if ((int)$a['id'] === (int)$b['id']) $g[] = 'derselbe Artikel';
    if ((string)$a['kategorie'] !== (string)$b['kategorie']) $g[] = 'andere Warenart';
    if (erp_einheit_norm((string)$a['einheit']) !== erp_einheit_norm((string)$b['einheit'])) $g[] = 'andere Einheit';
    if ((int)$a['kg'] !== (int)$b['kg'] && ((int)$a['kg'] || (int)$b['kg'])) $g[] = 'andere Kapselgröße';
    if (abs((float)$a['ff'] - (float)$b['ff']) > 0.0005) $g[] = 'anderes Füllgewicht';
    if (trim((string)$a['material']) !== '' && trim((string)$b['material']) !== '' && mb_strtolower(trim((string)$a['material'])) !== mb_strtolower(trim((string)$b['material']))) $g[] = 'anderes Kapselmaterial';
    if ((int)$a['rezeptur_id'] && (int)$b['rezeptur_id'] && (int)$a['rezeptur_id'] !== (int)$b['rezeptur_id']) $g[] = 'Bulk/Premix verschiedener Rezepturen';
    return $g;
}
function erp_inv_artikel_umbuchen(int $charge_id, int $ziel_item): array {
    $c = one("SELECT id, item_id FROM charge WHERE id=?", [$charge_id]);
    if (!$c) return ['ok' => false, 'msg' => 'Charge nicht gefunden.'];
    $sp = erp_inv_umbuchen_sperren((int)$c['item_id'], $ziel_item);
    if ($sp) return ['ok' => false, 'msg' => 'Umbuchen gesperrt: ' . implode(', ', $sp) . '.'];
    q("UPDATE charge SET item_id=? WHERE id=?", [$ziel_item, $charge_id]);
    erp_inv_log('charge', $charge_id, 'item_id', $c['item_id'], $ziel_item);
    erp_bedarf_bump();
    return ['ok' => true, 'alt_item' => (int)$c['item_id'], 'ziel' => (string) scalar("SELECT name FROM item WHERE id=?", [$ziel_item])];
}
// Klärfall „gefunden, nicht gebucht": Charge anlegen (Wareneingang ohne Bestellung, Grund „inventur") + ein Gebinde mit der
// beim Zählen gedruckten Inventarnummer. Lager 2: Kundenware mit Kunde. Rückgabe ['ok','charge_id'].
function erp_inv_klaerfall_buchen(array $z, string $grund, int $kunde_id = 0): array {
    $it = erp_inv_item((int)$z['item_id']);
    if (!$it || (float)$z['ist'] <= 0) return ['ok' => false, 'msg' => 'Artikel oder Menge fehlt.'];
    $notiz = 'Inventur: gefunden ohne Etikett (Inv ' . $z['kf_inventar_nr'] . ')' . ((string)($z['kf_notiz'] ?? '') !== '' ? ' – ' . $z['kf_notiz'] : '');
    $cid = $kunde_id > 0
        ? erp_wareneingang_buchen_fremd((int)$it['id'], (float)$z['ist'], (string)($z['kf_charge_nr'] ?? ''), $z['kf_mhd'] ?: null, $kunde_id, $notiz, (string)$z['einheit'])
        : erp_wareneingang_buchen((int)$it['id'], (float)$z['ist'], (string)($z['kf_charge_nr'] ?? ''), $z['kf_mhd'] ?: null, null, $notiz, 'auto', (string)$z['einheit']);
    if (!$cid) return ['ok' => false, 'msg' => 'Charge konnte nicht angelegt werden.'];
    q("UPDATE charge SET we_grund='inventur', we_grund_notiz=? WHERE id=?", [mb_substr($grund, 0, 255), $cid]);
    if (tabelle_da('charge_gebinde')) {
        $c = one("SELECT menge_verfuegbar, einheit, standort FROM charge WHERE id=?", [$cid]);
        q("INSERT INTO charge_gebinde (charge_id,nummer,anzahl_gesamt,menge,menge_verfuegbar,einheit,standort,status,inventar_nr,angelegt) VALUES (?,1,1,?,?,?,?,'voll',?,?)",
          [$cid, (float)$c['menge_verfuegbar'], (float)$c['menge_verfuegbar'], (string)$c['einheit'], (string)($c['standort'] ?: 'lager1'), (string)$z['kf_inventar_nr'] ?: null, jetzt_utc()]);
    }
    erp_inv_log('charge', $cid, 'angelegt', null, 'Inventur-Klärfall');
    erp_bedarf_bump();
    return ['ok' => true, 'charge_id' => $cid, 'gebinde_id' => tabelle_da('charge_gebinde') ? (int) scalar("SELECT id FROM charge_gebinde WHERE charge_id=? ORDER BY id DESC LIMIT 1", [$cid]) : 0];
}
// Hinweis an Nico (Dashboard-Benachrichtigung), z. B. Korrektur an Kundenware (Lager 2).
function erp_inv_benachrichtigen(string $betreff, string $text, string $link = ''): void {
    if (!tabelle_da('benachrichtigung')) return;
    q("INSERT INTO benachrichtigung (betreff, text, link, art, wichtig, erstellt) VALUES (?,?,?,'lager',1,?)",
      [mb_substr($betreff, 0, 255), mb_substr($text, 0, 4000), mb_substr($link, 0, 255) ?: null, gmdate('Y-m-d H:i:s')]);
}
// Andere Chargen desselben Artikels (Auswahl „Doppelbuchung: doppelt zu …").
function erp_inv_chargen_gleicher_artikel(int $item_id, int $ausser): array {
    return all("SELECT id, charge_nr, menge_verfuegbar, einheit, wareneingang FROM charge WHERE item_id=? AND id<>? ORDER BY id DESC LIMIT 30", [$item_id, $ausser]);
}
// Artikel derselben Warenart (Auswahl „Falscher Artikel / Dublette").
function erp_inv_artikel_gleicher_art(int $item_id): array {
    $k = (string) scalar("SELECT kategorie FROM item WHERE id=?", [$item_id]);
    return all("SELECT id, name, einheit FROM item WHERE kategorie=? AND id<>? AND COALESCE(gesperrt,0)=0 ORDER BY name", [$k, $item_id]);
}
