<?php
// Produktionsaufträge aus den (aus v3 importierten) offenen Kundenaufträgen erzeugen.
//
// Hintergrund: In v3 war EIN Auftrag zugleich Kundenauftrag und Produktionsboard. In v4 ist
// das getrennt: `auftrag` (Kundenbestellung, bereits importiert) und `produktionsauftrag` (PR,
// was die Produktions-Seite mit Stationen/Schritten zeigt). Dieses Tool legt für jeden OFFENEN
// (nicht versendeten) Auftrag einen Produktionsauftrag an – mit Status/Fortschritt aus dem
// Auftragsstatus – damit die Produktion gefüllt ist. Arbeitet rein auf v4-Daten (kein v3-Zugang
// nötig; läuft daher auch direkt auf beta).
//
// Aufruf:
//   php tools/produktionsauftraege_aus_auftraegen.php            # Trockenlauf (zeigt nur, was käme)
//   php tools/produktionsauftraege_aus_auftraegen.php --write     # legt an
//   php tools/produktionsauftraege_aus_auftraegen.php --reset --write   # vorher die selbst erzeugten PR löschen
//
// Idempotent: legt nie zwei PR zum selben Auftrag an. Selbst erzeugte PR sind über
// `produktionsauftrag.v3_id` (= Auftrags-v3_id) erkennbar; manuell/auto erzeugte bleiben unberührt.
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/schema.php';
init_schema();

$WRITE = in_array('--write', $argv, true);
$RESET = in_array('--reset', $argv, true);

ensure_column('produktionsauftrag', 'v3_id', 'INT NULL');   // markiert die hier erzeugten PR (idempotent/rücksetzbar)

// Auftragsstatus (aus v3 abgeleitet) -> Produktionsauftrag-Status + Fortschritt.
//   offen          -> PR offen,   kein Schritt erledigt
//   in_produktion  -> PR laufend, erster Schritt (Bereitstellen) erledigt
//   erledigt (v3 verpackt) -> PR laufend, alle Schritte außer dem letzten (Versand-Freigabe) erledigt
$statusMap = ['offen' => 'offen', 'in_produktion' => 'laufend', 'erledigt' => 'laufend'];

echo ($WRITE ? 'SCHREIBLAUF' : 'TROCKENLAUF (nichts wird geschrieben)') . " – Produktionsaufträge aus offenen Aufträgen\n\n";

if ($RESET) {
    $n = (int) scalar("SELECT COUNT(*) FROM produktionsauftrag WHERE v3_id IS NOT NULL");
    echo "RESET: $n selbst erzeugte Produktionsaufträge (+ deren Schritte) werden gelöscht.\n";
    if ($WRITE) {
        q("DELETE FROM produktion_schritt WHERE pa_id IN (SELECT id FROM (SELECT id FROM produktionsauftrag WHERE v3_id IS NOT NULL) t)");
        q("DELETE FROM produktionsauftrag WHERE v3_id IS NOT NULL");
    }
    echo "\n";
}

// Kandidaten: importierte, noch nicht versendete Aufträge (offen + in Produktion + verpackt/erledigt).
$auftraege = all("SELECT a.*, p.name AS produkt_name, k.firma,
                         (SELECT r.darreichungsform FROM produkt pp LEFT JOIN rezeptur r ON r.id=pp.rezeptur_id WHERE pp.id=a.produkt_id) AS form
                  FROM auftrag a
                  LEFT JOIN produkt p ON p.id=a.produkt_id
                  LEFT JOIN kunden k ON k.id=a.kunde_id
                  WHERE a.v3_id IS NOT NULL AND a.status IN ('offen','in_produktion','erledigt')
                  ORDER BY a.id");

$w = ['neu' => 0, 'schon_pr' => 0, 'gesamt_kandidaten' => count($auftraege)];
foreach ($auftraege as $a) {
    $aid = (int)$a['id'];
    // Schon ein Produktionsauftrag zu diesem Auftrag? Dann nichts tun (keine Dublette).
    if (scalar("SELECT id FROM produktionsauftrag WHERE auftrag_id=? LIMIT 1", [$aid])) { $w['schon_pr']++; continue; }

    $prStatus = $statusMap[$a['status']] ?? 'offen';
    $form = $a['form'] ?: 'kapsel';
    $art  = 'fremd';   // Standard wie die Auto-Kette (verkürzter Weg); im PR-Detail auf Eigenproduktion umstellbar
    $schritte = produktionsschritte_fuer($form, true);   // true = Zukauf/verkürzter Weg (6 Stationen)

    printf("  Auftrag %-8s %-22s %-20s  Status %-13s -> PR %s\n",
        (string)$a['nummer'], mb_substr((string)($a['firma'] ?? ''), 0, 22), mb_substr((string)($a['produkt_name'] ?? '–'), 0, 20), (string)$a['status'], $prStatus);

    if (!$WRITE) { $w['neu']++; continue; }

    q("INSERT INTO produktionsauftrag (nummer,auftrag_id,kunde_id,produkt_id,menge,stueck,verpackung_id,produktionsart,status,v3_id)
       VALUES (?,?,?,?,?,?,?,?,?,?)",
      [naechste_nummer('PR'), $aid, $a['kunde_id'] ?: null, $a['produkt_id'] ?: null,
       (int)$a['menge'], (int)($a['stueck'] ?? 0), $a['verpackung_id'] ?: null, $art, $prStatus, (int)$a['v3_id']]);
    $paid = (int)insert_id();

    // Stationen anlegen
    foreach ($schritte as $i => $station) {
        q("INSERT INTO produktion_schritt (pa_id,station,sort,erledigt) VALUES (?,?,?,0)", [$paid, $station, $i]);
    }
    // Fortschritt gemäß Auftragsstatus abhaken
    $letzter = count($schritte) - 1;
    if ($a['status'] === 'in_produktion') {
        q("UPDATE produktion_schritt SET erledigt=1, erledigt_at=? WHERE pa_id=? AND sort=0", [gmdate('Y-m-d H:i:s'), $paid]);
    } elseif ($a['status'] === 'erledigt') {
        q("UPDATE produktion_schritt SET erledigt=1, erledigt_at=? WHERE pa_id=? AND sort<?", [gmdate('Y-m-d H:i:s'), $paid, $letzter]);
    }
    $w['neu']++;
}

echo "\nERGEBNIS:\n";
printf("  Kandidaten (offen/nicht versendet): %d\n", $w['gesamt_kandidaten']);
printf("  %s Produktionsauftrag: %d\n", $WRITE ? 'Angelegt' : 'Würde anlegen', $w['neu']);
printf("  Übersprungen (hatten schon einen PR): %d\n", $w['schon_pr']);
if (!$WRITE) echo "\nHinweis: Das war ein Trockenlauf. Mit --write wird angelegt.\n";
