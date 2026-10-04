<?php
// Kisten: ein Behaelter mit EINEM Blinker, in dem viele verschiedene Chargen liegen. Damit muss
// nicht an jedes Kleinteil ein Blinker - man sucht ein Produkt, die ganze Kiste blinkt.
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/erp.php';
require_once __DIR__ . '/leiste.php';

function kiste(int $id): ?array { return one("SELECT * FROM lg_kiste WHERE id=?", [$id]); }

// Alle Kisten mit Blinker-Code und Anzahl Inhalt.
function kiste_alle(): array {
    return all("SELECT k.*, l.code AS blinker, l.id AS leiste_id,
                       (SELECT COUNT(*) FROM lg_kiste_inhalt ki WHERE ki.kiste_id=k.id) AS anzahl
                FROM lg_kiste k LEFT JOIN lg_leiste l ON l.kiste_id = k.id
                ORDER BY k.name");
}
function kiste_anlegen(string $name, string $notiz = '', string $barcode = ''): int {
    q("INSERT INTO lg_kiste (name, notiz, barcode, angelegt) VALUES (?,?,?,?)",
      [$name, $notiz ?: null, ($barcode = trim($barcode)) !== '' ? $barcode : null, jetzt_utc()]);
    return insert_id();
}
function kiste_speichern(int $id, string $name, string $notiz = '', string $barcode = ''): void {
    q("UPDATE lg_kiste SET name=?, notiz=?, barcode=?, aktualisiert=? WHERE id=?",
      [$name, $notiz ?: null, ($barcode = trim($barcode)) !== '' ? $barcode : null, jetzt_utc(), $id]);
}
// Kiste anhand ihres aufgeklebten Barcodes finden (zum Scannen beim Einbuchen).
function kiste_per_barcode(string $barcode): ?array {
    $barcode = trim($barcode);
    if ($barcode === '') return null;
    return one("SELECT * FROM lg_kiste WHERE barcode=? LIMIT 1", [$barcode]);
}
function kiste_loeschen(int $id): void {
    q("UPDATE lg_leiste SET kiste_id=NULL, aktualisiert=? WHERE kiste_id=?", [jetzt_utc(), $id]);
    q("DELETE FROM lg_kiste_inhalt WHERE kiste_id=?", [$id]);
    q("DELETE FROM lg_kiste WHERE id=? LIMIT 1", [$id]);
}

// Der Blinker, der an dieser Kiste haengt (0..1).
function kiste_blinker(int $kiste_id): ?array { return one("SELECT * FROM lg_leiste WHERE kiste_id=?", [$kiste_id]); }

// Inhalt einer Kiste: je Zeile die Charge (mit Produktinfo aus dem Dashboard) + Fach-Hinweis.
function kiste_inhalt(int $kiste_id): array {
    $rows = all("SELECT * FROM lg_kiste_inhalt WHERE kiste_id=? ORDER BY id", [$kiste_id]);
    foreach ($rows as &$r) $r['charge'] = erp_charge((int)$r['charge_id']);
    return $rows;
}
// In welcher Kiste liegt diese Charge (falls ueberhaupt)? Inkl. Fach und Kistenname.
function kiste_fuer_charge(int $charge_id): ?array {
    return one("SELECT ki.*, k.name AS kiste_name FROM lg_kiste_inhalt ki
                JOIN lg_kiste k ON k.id = ki.kiste_id WHERE ki.charge_id=?", [$charge_id]);
}

// Charge einer Kiste zuordnen (mit optionalem Fach). Rueckgabe: Fehlertext oder ''.
function kiste_charge_zuordnen(int $kiste_id, int $charge_id, string $fach = ''): string {
    if (!erp_charge($charge_id)) return 'Diese Charge gibt es nicht.';
    if (leiste_fuer_charge($charge_id)) return 'An dieser Charge hängt schon ein eigener Blinker. Erst dort lösen.';
    $vorhanden = kiste_fuer_charge($charge_id);
    if ($vorhanden && (int)$vorhanden['kiste_id'] !== $kiste_id) {
        return 'Diese Charge liegt schon in Kiste "' . $vorhanden['kiste_name'] . '". Erst dort entfernen.';
    }
    q("INSERT INTO lg_kiste_inhalt (kiste_id, charge_id, fach, angelegt) VALUES (?,?,?,?)
       ON DUPLICATE KEY UPDATE kiste_id=VALUES(kiste_id), fach=VALUES(fach)",
      [$kiste_id, $charge_id, $fach ?: null, jetzt_utc()]);
    return '';
}
function kiste_charge_entfernen(int $charge_id): void {
    q("DELETE FROM lg_kiste_inhalt WHERE charge_id=?", [$charge_id]);
}

// Blinker an eine Kiste binden (statt an eine Charge). Rueckgabe: Fehlertext oder ''.
function leiste_binden_kiste(string $code, int $kiste_id): string {
    if (!kiste($kiste_id)) return 'Diese Kiste gibt es nicht.';
    $l = leiste_sicherstellen($code);
    if ($l['charge_id']) return 'Blinker ' . $code . ' hängt an einer Charge. Erst dort lösen.';
    if ($l['kiste_id'] && (int)$l['kiste_id'] !== $kiste_id) return 'Blinker ' . $code . ' hängt schon an einer anderen Kiste.';
    $andere = kiste_blinker($kiste_id);
    if ($andere && (int)$andere['id'] !== (int)$l['id']) return 'An dieser Kiste hängt schon Blinker ' . $andere['code'] . '.';
    q("UPDATE lg_leiste SET kiste_id=?, charge_id=NULL, gebunden_am=?, aktualisiert=? WHERE id=?",
      [$kiste_id, jetzt_utc(), jetzt_utc(), (int)$l['id']]);
    return '';
}

// DER AUFLOESER fuers Finden: welcher Blinker soll fuer diese Charge blinken, und wo liegt sie?
// Rueckgabe: ['leiste'=>?array, 'ort'=>string, 'kiste'=>?array]
//   - eigener Blinker an der Charge  -> den nehmen, ort=''
//   - Charge in einer Kiste          -> Blinker der Kiste, ort="Kiste X" (+ Fach)
function blinker_fuer_charge(int $charge_id): array {
    $direkt = leiste_fuer_charge($charge_id);
    if ($direkt) return ['leiste' => $direkt, 'ort' => '', 'kiste' => null];

    $k = kiste_fuer_charge($charge_id);
    if ($k) {
        $ort = 'Kiste ' . $k['kiste_name'] . ($k['fach'] ? ', Fach ' . $k['fach'] : '');
        return ['leiste' => kiste_blinker((int)$k['kiste_id']), 'ort' => $ort, 'kiste' => $k];
    }
    return ['leiste' => null, 'ort' => '', 'kiste' => null];
}
