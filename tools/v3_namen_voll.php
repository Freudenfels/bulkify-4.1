<?php
// Vollen (ungekuerzten) v3-Rohstoffnamen nach item.name_v3 uebernehmen.
// Grund: der v3-Import kappt name auf 190 Zeichen; die laengsten Namen sind dadurch abgeschnitten.
// Fuer das Aufschluesseln (rohstoff_split) braucht die KI/der Mensch aber den kompletten Namen.
//
// Aufruf:
//   php tools/v3_namen_voll.php <v3-DB|sqlite>            # Trockenlauf (zeigt, wie viele gefuellt wuerden)
//   php tools/v3_namen_voll.php <v3-DB|sqlite> --write     # schreiben
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/schema.php';
init_schema();

$quelle = $argv[1] ?? '';
$WRITE  = in_array('--write', $argv, true);
if ($quelle === '') { fwrite(STDERR, "Quelle fehlt. Aufruf: php tools/v3_namen_voll.php <v3-DB|board.sqlite> [--write]\n"); exit(1); }
try {
    $v3 = is_file($quelle) ? new PDO('sqlite:' . $quelle)
        : new PDO('mysql:host=' . DB_HOST . ';dbname=' . $quelle . ';charset=utf8mb4', DB_USER, DB_PASS);
    $v3->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) { fwrite(STDERR, "v3-Quelle nicht lesbar: " . $e->getMessage() . "\n"); exit(1); }

// v3-Rohstoffnamen (voll) je v3-id einlesen
$namen = [];
foreach ($v3->query("SELECT id, name_de FROM rohstoffe")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $namen[(int)$r['id']] = trim((string)$r['name_de']);
}
echo ($WRITE ? 'SCHREIBLAUF' : 'TROCKENLAUF') . " – volle v3-Namen -> item.name_v3\n";

// Nur dort setzen, wo der v3-Name laenger ist als der (gekappte) v4-Name – sonst bringt es nichts.
$rows = all("SELECT id, name, v3_id FROM item WHERE kategorie='rohstoff' AND v3_id IS NOT NULL");
$n = 0; $laenger = 0;
foreach ($rows as $it) {
    $voll = $namen[(int)$it['v3_id']] ?? '';
    if ($voll === '') continue;
    if (mb_strlen($voll) > mb_strlen((string)$it['name'])) {
        $laenger++;
        if ($WRITE) q("UPDATE item SET name_v3=? WHERE id=?", [$voll, (int)$it['id']]);
        $n++;
        if ($laenger <= 5) echo "  " . $it['name'] . "  (v4 " . mb_strlen((string)$it['name']) . " -> v3 " . mb_strlen($voll) . " Zeichen)\n";
    } elseif ($WRITE) {
        // Kurze Namen: name_v3 leer lassen (nicht noetig).
    }
}
echo "\n" . ($WRITE ? "Gesetzt" : "Wuerde setzen") . ": $n (laengere v3-Namen als v4).\n";
if (!$WRITE) echo "Mit --write schreiben.\n";
