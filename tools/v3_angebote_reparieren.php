<?php
// Reparatur/Audit der v3-importierten Angebote (nur v4-DB, KEINE v3-Quelle nötig).
// Behebt die zwei häufigsten Import-Folgen bei Annapurna/Pure Health:
//   1) "Preise nicht hinterlegt" – angebot_staffel.vk_stueck bzw. angebot_position.preis_cent = 0,
//      obwohl in produkt_kundenpreis (aus v3 Stufe 2) ein echter Preis für dieselbe Konfiguration steht.
//   2) "Mengen falsch/fehlend" – angebot_staffel.stueck = 0 (Stück je Packung fehlt), obwohl
//      produkt_kundenpreis für dieselbe Bestellmenge ein menge_pro_vpe hat.
// Der Preis/Stück wird IMMER aus v3-eigenen Kundenpreisen genommen (nichts erfunden).
//
// Standard = TROCKENLAUF (zeigt nur, was passieren würde). Erst mit --write wird geschrieben.
// Aufruf:
//   php tools/v3_angebote_reparieren.php [kundenId] [--write]
//   (ohne kundenId = alle v3-importierten Angebote)

require_once dirname(__DIR__) . '/core/config.php';   // definiert BX_ROOT
require_once BX_ROOT . '/core/schema.php';
init_schema();

$WRITE   = in_array('--write', $argv, true);
$kFilter = 0;
foreach (array_slice($argv, 1) as $arg) { if ($arg !== '--write' && ctype_digit($arg)) { $kFilter = (int)$arg; break; } }

// Günstigster passender v3-Kundenpreis für eine Konfiguration (Produkt + Kunde). Reihenfolge der Genauigkeit:
// exakt (Bestellmenge UND Stück je Packung) > Bestellmenge > Stück je Packung > irgendein Preis des Produkts.
function kp_preis(int $kunde_id, int $produkt_id, int $menge, int $stueck): ?float {
    $rows = all("SELECT menge_pro_vpe, anzahl_vpe, preis FROM produkt_kundenpreis
                 WHERE kunde_id=? AND produkt_id=? AND preis IS NOT NULL AND preis>0", [$kunde_id, $produkt_id]);
    if (!$rows) return null;
    $rank = -1; $best = null;
    foreach ($rows as $r) {
        $mA = (int)($r['anzahl_vpe'] ?? 0); $mV = (int)($r['menge_pro_vpe'] ?? 0); $p = (float)$r['preis'];
        $r0 = ($menge > 0 && $mA === $menge && $stueck > 0 && $mV === $stueck) ? 4
            : (($menge > 0 && $mA === $menge) ? 3
            : (($stueck > 0 && $mV === $stueck) ? 2 : 1));
        if ($r0 > $rank) { $rank = $r0; $best = $p; }
    }
    return $best;
}
// Passendes menge_pro_vpe (Stück je Packung) für eine Bestellmenge aus den v3-Kundenpreisen.
function kp_stueck(int $kunde_id, int $produkt_id, int $menge): ?int {
    $rows = all("SELECT menge_pro_vpe, anzahl_vpe FROM produkt_kundenpreis
                 WHERE kunde_id=? AND produkt_id=? AND menge_pro_vpe IS NOT NULL AND menge_pro_vpe>0", [$kunde_id, $produkt_id]);
    foreach ($rows as $r) if ((int)($r['anzahl_vpe'] ?? 0) === $menge) return (int)$r['menge_pro_vpe'];
    // sonst der häufigste/erste vorhandene Wert
    return $rows ? (int)$rows[0]['menge_pro_vpe'] : null;
}

$where = "a.v3_id IS NOT NULL" . ($kFilter ? " AND a.kunde_id=" . $kFilter : "");
$angebote = all("SELECT a.id, a.nummer, a.kunde_id, a.produkt_id, a.status, k.firma
                 FROM angebot a LEFT JOIN kunden k ON k.id=a.kunde_id
                 WHERE $where ORDER BY a.id");

$w = ['preis_staffel'=>0, 'preis_position'=>0, 'stueck_staffel'=>0];
$offen = [];   // was auch danach noch fehlt (echte v3-Lücke -> manuell bepreisen)

foreach ($angebote as $a) {
    $aid = (int)$a['id']; $kid = (int)$a['kunde_id']; $pid = (int)$a['produkt_id'];
    $staffeln = all("SELECT id, menge, stueck, vk_stueck FROM angebot_staffel WHERE angebot_id=? ORDER BY sort,id", [$aid]);
    foreach ($staffeln as $s) {
        $sid = (int)$s['id']; $menge = (int)$s['menge']; $stueck = (int)$s['stueck']; $vk = (float)$s['vk_stueck'];
        // 1) Stück je Packung nachtragen (Menge-Anzeige)
        if ($stueck <= 0 && $pid) {
            $ns = kp_stueck($kid, $pid, $menge);
            if ($ns) { if ($WRITE) q("UPDATE angebot_staffel SET stueck=? WHERE id=?", [$ns, $sid]); $stueck = $ns; $w['stueck_staffel']++; }
        }
        // 2) Preis nachtragen
        if ($vk <= 0 && $pid) {
            $np = kp_preis($kid, $pid, $menge, $stueck);
            if ($np !== null) { if ($WRITE) q("UPDATE angebot_staffel SET vk_stueck=? WHERE id=?", [$np, $sid]); $vk = $np; $w['preis_staffel']++; }
        }
        if ($vk <= 0) $offen[] = sprintf('%s (%s): Staffel Menge %d – kein v3-Preis vorhanden', $a['nummer'], (string)$a['firma'], $menge);
    }
    // Angebotsposition (Beleg-Ansicht) analog bepreisen – nur ECHTE Positionen (Menge>0),
    // Platzhalter-Positionen (Menge 0) staffel-basierter Angebote bleiben unberührt.
    foreach (all("SELECT id, menge, stueck, preis_cent FROM angebot_position WHERE angebot_id=? AND preis_cent<=0 AND menge>0", [$aid]) as $p) {
        if (!$pid) continue;
        $np = kp_preis($kid, $pid, (int)$p['menge'], (int)$p['stueck']);
        if ($np !== null) { if ($WRITE) q("UPDATE angebot_position SET preis_cent=? WHERE id=?", [(int) round($np * 100), (int)$p['id']]); $w['preis_position']++; }
    }
}

echo "Angebote (v3-Import" . ($kFilter ? ", Kunde $kFilter" : "") . "): " . count($angebote) . "\n";
echo ($WRITE ? "GESCHRIEBEN:\n" : "WÜRDE ändern (Trockenlauf):\n");
printf("  Staffel-Preise nachgetragen:   %d\n", $w['preis_staffel']);
printf("  Positions-Preise nachgetragen: %d\n", $w['preis_position']);
printf("  Staffel Stück/Packung ergänzt: %d\n", $w['stueck_staffel']);
$offen = array_values(array_unique($offen));
echo "\nOhne v3-Preis (manuell bepreisen, " . count($offen) . "):\n";
foreach (array_slice($offen, 0, 40) as $o) echo "  - $o\n";
if (count($offen) > 40) echo "  … und " . (count($offen) - 40) . " weitere\n";
echo "\n" . ($WRITE ? "Fertig.\n" : ">> Trockenlauf. Mit --write schreiben.\n");
