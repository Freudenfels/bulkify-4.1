<?php
require_once __DIR__ . '/gewicht.php';   // gewicht_lesen(), tara_aus_id() (13.11.2026)
// Kapselzahl aus dem Gewicht (Soll-Handbuch 4.8, Nico 11.11.2026 Ergänzung 5). Gemeinsam (nur q/one/all/scalar).
// - Stichproben: mehrmals je 10 Kapseln wiegen -> Ø-Gewicht je Kapsel (pa_unter_charge.kapsel_mg).
// - Sack wiegen: Kapseln = (Sack − Tara des Sacktyps aus lg_tara) ÷ Ø-Gewicht (pa_unter_charge.kapseln_bulk = Summe aller Säcke).
// - Gegenprobe beim Schließen der Unter-Charge: Gläser × Kapseln je Glas gegen die Kapselzahl. Abweichung über der Toleranz
//   (app_meta kapsel_toleranz_prozent, Standard 2 %) = nur Hinweis für den Mitarbeiter + Aufgabe ans Büro (kein Stopp).

function pa_kapsel_toleranz(): float { $v = (float) str_replace(',', '.', pa_meta('kapsel_toleranz_prozent')); return $v > 0 ? $v : 2.0; }
function pa_kapselprobe(int $uc_id, float $gramm, int $anzahl, string $von): array {
    $uc = pa_unter_charge($uc_id);
    if (!$uc) return ['ok' => false, 'msg' => 'Keine laufende Unter-Charge.'];
    if ($gramm <= 0 || $anzahl <= 0) return ['ok' => false, 'msg' => 'Bitte das Gewicht der ' . $anzahl . ' Kapseln in Gramm eingeben.'];
    $mg = round($gramm * 1000 / $anzahl, 2);
    pa_ereignis((int)$uc['pa_id'], $uc_id, 'kapselprobe', $anzahl . ' Kapseln = ' . pa_mz($gramm) . ' g, also ' . pa_mz($mg) . ' mg je Kapsel', (string)$mg, '', '', $von);
    $avg = (float) scalar("SELECT AVG(CAST(wert AS DECIMAL(12,3))) FROM pa_ereignis WHERE unter_charge_id=? AND typ='kapselprobe'", [$uc_id]);
    q("UPDATE pa_unter_charge SET kapsel_mg=? WHERE id=?", [round($avg, 2), $uc_id]);
    pa_kapseln_neu_rechnen($uc_id);
    $n = (int) scalar("SELECT COUNT(*) FROM pa_ereignis WHERE unter_charge_id=? AND typ='kapselprobe'", [$uc_id]);
    return ['ok' => true, 'msg' => 'Probe gespeichert: ' . pa_mz($mg) . ' mg je Kapsel. Ø aus ' . $n . ' Probe(n): ' . pa_mz($avg) . ' mg.' . ($n < 3 ? ' Bitte mehrmals wiegen (mind. 3 Proben).' : '')];
}
// 13.11.2026 (Fable-3 T1.1): Tara kommt nur als ID aus dem Formular, Wert und Name aus lg_tara (tara_aus_id()).
function pa_sackgewicht(int $uc_id, float $brutto_kg, int $tara_id, string $von): array {
    $tara = tara_aus_id($tara_id);
    if (!$tara) return ['ok' => false, 'msg' => 'Bitte den Gebindetyp (Tara) wählen.'];
    $tara_kg = (float)$tara['kg']; $tara_name = (string)$tara['name'];
    $uc = pa_unter_charge($uc_id);
    if (!$uc) return ['ok' => false, 'msg' => 'Keine laufende Unter-Charge.'];
    if ((float)$uc['kapsel_mg'] <= 0) return ['ok' => false, 'msg' => 'Erst Kapseln wiegen (Stichprobe), dann den Sack.'];
    $netto = round($brutto_kg - $tara_kg, 3);
    if ($netto <= 0) return ['ok' => false, 'msg' => 'Sackgewicht minus Tara muss größer 0 sein.'];
    $kap = (int) round($netto * 1e6 / (float)$uc['kapsel_mg']);
    pa_ereignis((int)$uc['pa_id'], $uc_id, 'sackgewicht', 'Sack ' . pa_mz($brutto_kg) . ' kg brutto − Tara ' . pa_mz($tara_kg) . ' kg' . ($tara_name !== '' ? ' (' . $tara_name . ')' : '') . ' = ' . pa_mz($netto) . ' kg netto, ca. ' . pa_mz($kap) . ' Kapseln', (string)$netto, '', '', $von);
    $sum = pa_kapseln_neu_rechnen($uc_id);
    return ['ok' => true, 'msg' => 'Sack: ' . pa_mz($netto) . ' kg netto = ca. ' . pa_mz($kap) . ' Kapseln. Diese Charge gesamt: ' . pa_mz($sum) . ' Kapseln.'];
}
// Kapselzahl der Unter-Charge = Summe Netto aller Säcke ÷ aktuelles Ø-Gewicht.
function pa_kapseln_neu_rechnen(int $uc_id): float {
    $mg = (float) scalar("SELECT kapsel_mg FROM pa_unter_charge WHERE id=?", [$uc_id]);
    $netto = (float) scalar("SELECT COALESCE(SUM(CAST(wert AS DECIMAL(14,3))),0) FROM pa_ereignis WHERE unter_charge_id=? AND typ='sackgewicht'", [$uc_id]);
    $k = $mg > 0 ? round($netto * 1e6 / $mg) : 0;
    q("UPDATE pa_unter_charge SET kapseln_bulk=? WHERE id=?", [$k ?: null, $uc_id]);
    return (float)$k;
}
// Gegenprobe beim Schließen: Gläser × Kapseln je Glas gegen die Kapselzahl aus dem Gewicht. Rückgabe Hinweistext oder ''.
function pa_gegenprobe(int $uc_id, string $von = ''): string {
    $uc = pa_unter_charge($uc_id);
    if (!$uc || (float)($uc['kapseln_bulk'] ?? 0) <= 0) return '';
    $je = (float) scalar("SELECT COALESCE(NULLIF(p.einheiten_pro_packung,0), NULLIF(a.stueck,0), 0) FROM produktionsauftrag pa LEFT JOIN produkt p ON p.id=pa.produkt_id LEFT JOIN auftrag a ON a.id=pa.auftrag_id WHERE pa.id=?", [(int)$uc['pa_id']]);
    $glaeser = (float) scalar("SELECT COALESCE(SUM(menge),0) FROM pa_teilmenge WHERE unter_charge_id=?", [$uc_id]);
    if ($je <= 0 || $glaeser <= 0) return '';
    $soll = $glaeser * $je; $ist = (float)$uc['kapseln_bulk'];
    $abw = ($ist - $soll) / $ist * 100;
    $txt = 'Gegenprobe ' . $uc['charge_nr'] . ': ' . pa_mz($glaeser) . ' Gläser × ' . pa_mz($je) . ' = ' . pa_mz($soll) . ' Kapseln, aus dem Gewicht ' . pa_mz($ist) . ' Kapseln (' . ($abw >= 0 ? '+' : '') . pa_mz(round($abw, 1)) . ' %).';
    if (abs($abw) <= pa_kapsel_toleranz()) { pa_ereignis((int)$uc['pa_id'], $uc_id, 'kapselprobe', $txt . ' Passt.', '', '', '', $von); return ''; }
    pa_ereignis((int)$uc['pa_id'], $uc_id, 'abweichung', $txt . ' Über der Toleranz von ' . pa_mz(pa_kapsel_toleranz()) . ' %.', '', '', '', $von);
    pa_aufgabe_buero((int)$uc['pa_id'], 'Produktion: Gegenprobe Kapseln ' . $uc['charge_nr'], $txt . ' Gemeldet von ' . ($von ?: 'Produktion') . '.', $von);
    $GLOBALS['pa_hinweis'] = 'Hinweis: ' . $txt . ' Das Büro ist informiert.';
    return $GLOBALS['pa_hinweis'];
}
