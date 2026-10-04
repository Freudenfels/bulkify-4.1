<?php
// Öffentliche Rohstoff-Daten für die Website (bulkify.pro-Rohstoff-Datenbank, SEO).
// Liefert NUR freigegebene Rohstoffe (item.website_sichtbar=1) und NUR sichere, öffentliche Felder:
// Name (DE/EN/lat), CAS, botanische Quelle, Form, Beschreibung, charakteristische Kennwerte (gefiltert),
// Wirkstoffe/Standardisierung. NIEMALS Preise, Lieferanten, Bestand, interne IDs oder Originaldokumente.
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/spec_ki.php';   // item_kennwerte_relevant()

// URL-Slug aus dem Namen bilden (stabil, eindeutig). Wird beim Freigeben gespeichert (item.web_slug).
function rohstoff_web_slug(string $name, int $id = 0): string {
    $s = mb_strtolower(trim($name));
    $s = strtr($s, ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss','é'=>'e','è'=>'e','á'=>'a','à'=>'a']);
    if (function_exists('iconv')) { $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s); if ($t !== false) $s = $t; }
    $s = preg_replace('/[^a-z0-9]+/', '-', strtolower($s));
    $s = trim((string)$s, '-');
    if ($s === '') $s = 'rohstoff';
    // Eindeutigkeit: kollidiert der Slug mit einem ANDEREN Rohstoff, haengen wir die id an.
    if ($id > 0) {
        $konflikt = (int) scalar("SELECT id FROM item WHERE web_slug=? AND id<>? LIMIT 1", [$s, $id]);
        if ($konflikt) $s .= '-' . $id;
    }
    return mb_substr($s, 0, 190);
}

// Beim Freigeben/Speichern den Slug setzen, falls noch keiner da ist.
function rohstoff_web_slug_sicherstellen(int $item_id): void {
    $it = one("SELECT id, name, web_slug FROM item WHERE id=?", [$item_id]);
    if (!$it) return;
    if (trim((string)($it['web_slug'] ?? '')) !== '') return;
    q("UPDATE item SET web_slug=? WHERE id=?", [rohstoff_web_slug((string)$it['name'], (int)$it['id']), $item_id]);
}

// Öffentliche Rohstoffliste (oder ein einzelner per $slug). Sichere Felder, Kennwerte gefiltert.
function rohstoff_public_liste(?string $slug = null): array {
    $where = "i.kategorie='rohstoff' AND COALESCE(i.website_sichtbar,0)=1";
    $args  = [];
    if ($slug !== null && $slug !== '') { $where .= " AND i.web_slug=?"; $args[] = $slug; }
    $items = all("SELECT id, name, name_en, name_lat, cas, bot_quelle, form, web_slug, web_beschreibung
                  FROM item i WHERE $where ORDER BY i.name", $args);
    $out = [];
    foreach ($items as $it) {
        $iid = (int)$it['id'];
        $slugV = trim((string)($it['web_slug'] ?? '')) !== '' ? (string)$it['web_slug'] : rohstoff_web_slug((string)$it['name'], $iid);
        $kennwerte = array_map(
            fn($k) => ['parameter' => (string)$k['parameter'], 'wert' => (string)$k['wert']],
            item_kennwerte_relevant($iid)
        );
        $wirkstoffe = [];
        foreach (all("SELECT n.name, iw.gehalt_prozent FROM item_wirkstoff iw
                      JOIN naehrstoff n ON n.id=iw.naehrstoff_id WHERE iw.item_id=? ORDER BY iw.sort, iw.id", [$iid]) as $w) {
            $g = $w['gehalt_prozent'];
            $wirkstoffe[] = ['name' => (string)$w['name'], 'gehalt_prozent' => ($g === null || $g === '') ? null : (float)$g];
        }
        $out[] = [
            'slug'         => $slugV,
            'name'         => (string)$it['name'],
            'name_en'      => (string)($it['name_en'] ?? ''),
            'name_lat'     => (string)($it['name_lat'] ?? ''),
            'cas'          => (string)($it['cas'] ?? ''),
            'bot_quelle'   => (string)($it['bot_quelle'] ?? ''),
            'kategorie'    => 'rohstoff',
            'form'         => (string)($it['form'] ?? ''),
            'beschreibung' => (string)($it['web_beschreibung'] ?? ''),
            'kennwerte'    => $kennwerte,
            'wirkstoffe'   => $wirkstoffe,
        ];
    }
    return $out;
}
