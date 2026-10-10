<?php
// Produktions-Durchspiel-Testdaten: ein KOMPLETT isoliertes Testset, um die Produktion (auch online)
// durchzuspielen, OHNE echte Daten anzufassen. Alles trägt den Marker 'TESTSEED-DURCHSPIEL' (Kunde, Items,
// Rezepturen, Produkte) bzw. 'TESTSEED-' (Chargen) und lässt sich darüber gemeinsam wieder entfernen.
// Verwendet eigene Test-Rohstoffe/-Verpackungen (keine echten Items), damit nichts Echtes berührt wird.

require_once BX_ROOT . '/core/schema.php';

function td_marker(): string { return 'TESTSEED-DURCHSPIEL'; }

// Test-Kunde (anlegen oder finden). Eindeutig über die Notiz-Marke.
function td_kunde(bool $anlegen = true): int {
    $id = (int) scalar("SELECT id FROM kunden WHERE notiz LIKE ? ORDER BY id LIMIT 1", ['%' . td_marker() . '%']);
    if ($id || !$anlegen) return $id;
    q("INSERT INTO kunden (firma, notiz) VALUES (?,?)", ['Durchspiel-Testbetrieb', td_marker()]);
    return (int) insert_id();
}

// Ein Test-Item anlegen-oder-finden (über Notiz-Marke + Name eindeutig) und mit viel Bestand füllen.
function td_item(string $name, string $kategorie, array $extra, float $bestand, string $einheit): int {
    $id = (int) scalar("SELECT id FROM item WHERE name=? AND notiz LIKE ? LIMIT 1", [$name, '%' . td_marker() . '%']);
    if (!$id) {
        $felder = array_merge(['artikelnummer'=>naechste_nummer('TST'), 'name'=>$name, 'kategorie'=>$kategorie,
                               'einheit'=>$einheit, 'preis_bezug'=>$einheit, 'notiz'=>td_marker(), 'gesperrt'=>0], $extra);
        $cols = implode(',', array_keys($felder));
        $ph   = implode(',', array_fill(0, count($felder), '?'));
        q("INSERT INTO item ($cols) VALUES ($ph)", array_values($felder));
        $id = (int) insert_id();
    }
    // Bestand: eine große freie Test-Charge, falls noch keine da.
    if (!scalar("SELECT id FROM charge WHERE item_id=? AND charge_nr LIKE 'TESTSEED-%' LIMIT 1", [$id]))
        q("INSERT INTO charge (charge_nr,item_id,menge,menge_verfuegbar,einheit,status,wareneingang)
           VALUES (?,?,?,?,?, 'frei', CURDATE())", ['TESTSEED-' . $id, $id, $bestand, $bestand, $einheit]);
    return $id;
}

// Legt das komplette Testset an (idempotent: ist schon eins da, passiert nichts). Rückgabe: Statustext-Teile.
function testdaten_durchspiel_anlegen(): array {
    $kid = td_kunde(true);
    if ((int) scalar("SELECT COUNT(*) FROM auftrag WHERE kunde_id=?", [$kid]) > 0)
        return ['ok'=>true, 'schon'=>true, 'n'=>0, 'msg'=>'Testset ist bereits angelegt.'];

    // Eigene Test-Items (isoliert) mit großem Bestand.
    $roh1 = td_item('Testrohstoff Alpha', 'rohstoff', ['form'=>'pulver'], 100000, 'kg');
    $roh2 = td_item('Testrohstoff Beta',  'rohstoff', ['form'=>'pulver'], 100000, 'kg');
    $kaps = td_item('Testkapsel Größe 0', 'rohstoff', ['form'=>'kapselhuelle'], 2000000, 'Stk');
    $dose = td_item('Testdose 150 ml',    'verpackung', ['form'=>'dose', 'verpackung_rolle'=>'primaer'], 200000, 'Stk');
    $beut = td_item('Testbeutel 250 g',   'verpackung', ['form'=>'beutel', 'verpackung_rolle'=>'primaer'], 200000, 'Stk');
    $flas = td_item('Testflasche 100 ml', 'verpackung', ['form'=>'flasche', 'verpackung_rolle'=>'primaer'], 200000, 'Stk');

    // Kapselgröße 0 (für Kapsel/Softgel) – Referenz aus den Stammdaten.
    seed_kapsel_referenz();
    $kg0 = (int) scalar("SELECT id FROM kapselgroesse WHERE name LIKE '%0%' AND name NOT LIKE '%00%' ORDER BY fuellmenge_mg LIMIT 1")
         ?: (int) scalar("SELECT id FROM kapselgroesse ORDER BY fuellmenge_mg LIMIT 1");

    // form => [label, verpackung_id, einheiten_pro_packung, menge, kapselgroesse?, einheit_fuellmenge?, tabletten_form?]
    $FORMS = [
        'kapsel'   => ['Kapsel',   $dose, 120, 1000, $kg0,  null,  null,            [[$roh1,500],[$roh2,100]], $kaps],
        'tablette' => ['Tablette', $dose,  90, 1000, null,  null,  'rund_mit_bruch',[[$roh1,400],[$roh2,150]], 0],
        'softgel'  => ['Softgel',  $dose,  60, 1000, $kg0,  null,  null,            [[$roh1,10]],              $kaps],
        'pulver'   => ['Pulver',   $beut, 250,  500, null,  250,   null,            [[$roh1,1000],[$roh2,500]],0],
        'fluessig' => ['Flüssig',  $flas, 250,  500, null,  250,   null,            [[$roh1,20]],              0],
        'stick'    => ['Stick',    $beut,  30, 1000, null,  3,     null,            [[$roh1,1000]],            0],
        'gummi'    => ['Gummi',    $dose,  60, 1000, null,  2.5,   null,            [[$roh1,80],[$roh2,50]],   0],
    ];
    $n = 0;
    foreach ($FORMS as $form => $d) {
        [$lbl, $vid, $epp, $menge, $kgid, $fuell, $tform, $zutaten, $leer] = $d;
        $name = 'Test ' . $lbl . ' (Durchspiel)';
        // Rezeptur
        q("INSERT INTO rezeptur (nummer,name,kunde_id,darreichungsform,status,notiz,freigabe_name,freigabe_am,kapselgroesse_id,einheit_fuellmenge,tabletten_form)
           VALUES (?,?,?,?,?,?,?,NOW(),?,?,?)",
          [naechste_nummer('RZ'), $name, $kid, $form, 'freigegeben', td_marker(), 'Testseed', $kgid ?: null, $fuell, $tform]);
        $rid = insert_id(); $sort = 0;
        foreach ($zutaten as [$iid, $mg]) {
            $bez = (string) scalar("SELECT name FROM item WHERE id=?", [$iid]);
            q("INSERT INTO rezeptur_zutat (rezeptur_id,item_id,bezeichnung,menge_mg,sort) VALUES (?,?,?,?,?)", [$rid, $iid, $bez, $mg, $sort++]);
        }
        // Produkt (Verpackung + Leerkapsel gesetzt, Etikett später freigegeben)
        q("INSERT INTO produkt (nummer,name,kunde_id,rezeptur_id,verpackung_id,leerkapsel_id,einheiten_pro_packung,einnahme_pro_tag,status,notiz)
           VALUES (?,?,?,?,?,?,?,?,?,?)",
          [naechste_nummer('P'), $name, $kid, $rid, $vid, ($leer ?: null), $epp, 1, 'aktiv', td_marker()]);
        $pid = insert_id();
        // Auftrag (offen) + Etikett freigegeben (hebt die harte Sperre)
        $vk = 0.50; $netto = round($menge * $vk, 2);
        q("INSERT INTO auftrag (nummer,kunde_id,produkt_id,menge,vk_stueck,gesamt_netto,status,stueck,verpackung_id,rezeptur_id,produkt_bezeichnung,produkt_form,etikett_freigegeben,etikett_freigabe_am,etikett_freigabe_von)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,NOW(),?)",
          [naechste_nummer('AB'), $kid, $pid, $menge, $vk, $netto, 'offen', $epp, $vid, $rid, $name, $form, 'Testseed']);
        $aid = insert_id();
        // Produktionsauftrag (eigen) -> Status 'vorbereitung'
        produktionsauftrag_aus_auftrag($aid, 'eigen');
        $n++;
    }
    if (function_exists('bedarf_bump')) bedarf_bump();
    return ['ok'=>true, 'schon'=>false, 'n'=>$n, 'msg'=>$n . ' Test-Aufträge (je Darreichungsform) angelegt, inkl. Test-Kunde, Test-Rohstoffen/-Verpackungen und Lagerbestand.'];
}

// Entfernt das komplette Testset wieder – NUR die mit dem Marker versehenen Daten.
function testdaten_durchspiel_loeschen(): array {
    $kid = td_kunde(false);
    $del = ['auftraege'=>0, 'produkte'=>0, 'rezepturen'=>0, 'items'=>0, 'kunde'=>0];
    if ($kid) {
        $aufIds = array_map('intval', array_column(all("SELECT id FROM auftrag WHERE kunde_id=?", [$kid]), 'id'));
        $paIds  = array_map('intval', array_column(all("SELECT id FROM produktionsauftrag WHERE kunde_id=? OR auftrag_id IN (" . ($aufIds ? implode(',', $aufIds) : '0') . ")", [$kid]), 'id'));
        if ($paIds) { $in = implode(',', $paIds); q("DELETE FROM prod_probe WHERE pa_id IN ($in)"); q("DELETE FROM reservierung WHERE pa_id IN ($in)"); }
        if ($aufIds) { $in = implode(',', $aufIds); q("DELETE FROM reservierung WHERE auftrag_id IN ($in)"); }
        foreach ($aufIds as $aid) { auftrag_komplett_loeschen($aid); $del['auftraege']++; }
        // Produkte + Rezepturen des Test-Kunden
        $del['produkte'] = count(all("SELECT id FROM produkt WHERE kunde_id=?", [$kid]));
        q("DELETE FROM produkt WHERE kunde_id=?", [$kid]);
        $rezIds = array_map('intval', array_column(all("SELECT id FROM rezeptur WHERE kunde_id=?", [$kid]), 'id'));
        if ($rezIds) { $in = implode(',', $rezIds);
            q("DELETE FROM rezeptur_zutat WHERE rezeptur_id IN ($in)");
            q("DELETE FROM item WHERE kategorie='fertig' AND rezeptur_id IN ($in)");   // evtl. Bulk-Items
            q("DELETE FROM rezeptur WHERE id IN ($in)");
            $del['rezepturen'] = count($rezIds);
        }
    }
    // Test-Items (eigene Rohstoffe/Verpackungen) + deren Chargen
    $itemIds = array_map('intval', array_column(all("SELECT id FROM item WHERE notiz LIKE ?", ['%' . td_marker() . '%']), 'id'));
    if ($itemIds) { $in = implode(',', $itemIds); q("DELETE FROM charge WHERE item_id IN ($in)"); q("DELETE FROM item WHERE id IN ($in)"); $del['items'] = count($itemIds); }
    q("DELETE FROM charge WHERE charge_nr LIKE 'TESTSEED-%'");   // Sicherheits-Aufräumen
    if ($kid) { q("DELETE FROM kunden WHERE id=? AND notiz LIKE ?", [$kid, '%' . td_marker() . '%']); $del['kunde'] = 1; }
    if (function_exists('bedarf_bump')) bedarf_bump();
    return $del;
}

function testdaten_durchspiel_stat(): array {
    $kid = td_kunde(false);
    return [
        'vorhanden' => $kid > 0,
        'auftraege' => $kid ? (int) scalar("SELECT COUNT(*) FROM auftrag WHERE kunde_id=?", [$kid]) : 0,
        'produkte'  => $kid ? (int) scalar("SELECT COUNT(*) FROM produkt WHERE kunde_id=?", [$kid]) : 0,
        'items'     => (int) scalar("SELECT COUNT(*) FROM item WHERE notiz LIKE ?", ['%' . td_marker() . '%']),
    ];
}
