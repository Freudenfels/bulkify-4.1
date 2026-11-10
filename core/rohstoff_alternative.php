<?php
// Alternative zu einer Rohstoff-Anfrage (Nico 10.11.2026): Der Kunde fragt z. B. Creapure an, das gerade nicht lieferbar ist.
// Das Team markiert den Lieferstatus (mit Hinweis für den Kunden) und schlägt eine oder mehrere Alternativen aus dem Rohstoff-
// Katalog vor. Nach „Dem Kunden zeigen“ sieht der Kunde die Vorschläge im Portal unter „Zu bestätigen“ und wählt eine Alternative
// (die Anfrage wechselt auf diesen Rohstoff) oder bleibt beim Original. Geteilt von Dashboard und Kundenportal; braucht nur db.php
// (Tabellen legt core/schema.php an).

const ROHSTOFF_LIEFERSTATUS = ['' => 'lieferbar', 'nicht_lieferbar' => 'derzeit nicht lieferbar', 'eingestellt' => 'nicht mehr erhältlich'];

// Ein Satz für Kunde/Mail: „Creapure ist derzeit nicht lieferbar (wieder ab Januar).“ – leer, wenn lieferbar.
function rohstoff_lieferstatus_text(array $pa): string {
    $st = (string)($pa['lieferstatus'] ?? '');
    $bet = trim((string)($pa['original_betreff'] ?? '')) ?: trim((string)($pa['betreff'] ?? '')) ?: 'Der angefragte Rohstoff';
    $h = trim((string)($pa['lieferstatus_hinweis'] ?? ''));
    if ($st === '' || !isset(ROHSTOFF_LIEFERSTATUS[$st])) return $h;
    return $bet . ' ist ' . ROHSTOFF_LIEFERSTATUS[$st] . ($h !== '' ? ' (' . $h . ')' : '') . '.';
}

// Alternativen einer Anfrage (mit Rohstoffname, Unterlagen-Stand).
function rohstoff_alternativen(int $anfrage_id): array {
    return all("SELECT a.*, i.name, i.artikelnummer, i.beschaffenheit, i.dev, i.preis_bezug,
                       EXISTS(SELECT 1 FROM dokument d WHERE d.objekt_typ='item' AND d.objekt_id=i.id AND d.typ IN ('spec','coa','beides','analyse')) AS hat_dok
                FROM portal_anfrage_alternative a JOIN item i ON i.id=a.item_id WHERE a.anfrage_id=? ORDER BY a.id", [$anfrage_id]);
}

// Früher zu demselben Rohstoff angebotene Alternativen (Vorschlagsliste fürs Team), häufigste zuerst.
function rohstoff_alternativen_bisher(int $item_id, int $ohne_anfrage = 0): array {
    if ($item_id <= 0) return [];
    return all("SELECT a.item_id, i.name, COUNT(*) n, SUM(a.status='gewaehlt') gewaehlt, MAX(a.hinweis) hinweis
                FROM portal_anfrage_alternative a JOIN portal_anfrage p ON p.id=a.anfrage_id JOIN item i ON i.id=a.item_id
                WHERE COALESCE(p.original_rohstoff_id, p.rohstoff_id)=? AND a.anfrage_id<>? AND a.item_id<>?
                GROUP BY a.item_id, i.name ORDER BY gewaehlt DESC, n DESC LIMIT 5", [$item_id, $ohne_anfrage, $item_id]);
}

// Offene (dem Kunden gezeigte, noch nicht entschiedene) Alternativ-Vorschläge eines Kunden – fürs Portal.
function rohstoff_alternativen_offen_kunde(int $kunde_id): array {
    $out = [];
    foreach (all("SELECT id, nummer, betreff, original_betreff, rohstoff_id, wunsch_menge, wunsch_einheit, lieferstatus, lieferstatus_hinweis, alternativen_gesendet_am
                  FROM portal_anfrage WHERE kunde_id=? AND typ='rohstoff' AND alternativen_gesendet_am IS NOT NULL AND alternativen_entschieden_am IS NULL
                    AND status NOT IN ('abgelehnt') ORDER BY alternativen_gesendet_am DESC", [$kunde_id]) as $p) {
        $p['alternativen'] = array_values(array_filter(rohstoff_alternativen((int)$p['id']), fn($a) => $a['status'] === 'vorgeschlagen'));
        if ($p['alternativen']) $out[(int)$p['id']] = $p;
    }
    return $out;
}

// Kunde wählt eine Alternative: Anfrage wechselt auf diesen Rohstoff (Original bleibt gemerkt), übrige Vorschläge abgelehnt.
function rohstoff_alternative_waehlen(int $kunde_id, int $alt_id): bool {
    $a = one("SELECT a.*, p.kunde_id, p.rohstoff_id, p.betreff, p.original_rohstoff_id, p.original_betreff, p.nummer, i.name
              FROM portal_anfrage_alternative a JOIN portal_anfrage p ON p.id=a.anfrage_id JOIN item i ON i.id=a.item_id
              WHERE a.id=? AND a.status='vorgeschlagen' AND p.alternativen_gesendet_am IS NOT NULL AND p.alternativen_entschieden_am IS NULL", [$alt_id]);
    if (!$a || (int)$a['kunde_id'] !== $kunde_id) return false;
    $aid = (int)$a['anfrage_id'];
    $orig = trim((string)($a['original_betreff'] ?? '')) ?: (string)$a['betreff'];
    q("UPDATE portal_anfrage_alternative SET status=IF(id=?,'gewaehlt','abgelehnt') WHERE anfrage_id=? AND status='vorgeschlagen'", [$alt_id, $aid]);
    q("UPDATE portal_anfrage SET original_rohstoff_id=COALESCE(original_rohstoff_id, rohstoff_id), original_betreff=COALESCE(original_betreff, betreff),
              rohstoff_id=?, betreff=?, alternativen_entschieden_am=UTC_TIMESTAMP(), lieferstatus=NULL, lieferstatus_hinweis=NULL, status=IF(status='beantwortet','in_bearbeitung',status) WHERE id=?",
      [(int)$a['item_id'], mb_substr((string)$a['name'] . ' (Alternative zu ' . $orig . ')', 0, 255), $aid]);
    $txt = 'Kunde hat zur Anfrage ' . $a['nummer'] . ' die Alternative ' . $a['name'] . ' statt ' . $orig . ' gewählt.';
    if (function_exists('log_aktivitaet')) log_aktivitaet('kunde', $kunde_id, 'kunde', $txt, 'anfrage', 'portal_anfrage', $aid);
    if (function_exists('benachrichtigung_add')) benachrichtigung_add('Alternative gewählt: ' . $a['nummer'], $txt . ' Bitte Preis/Angebot dazu abgeben.', '?p=portal_anfrage&id=' . $aid, 'anfrage');
    return true;
}

// Kunde bleibt beim Original: alle Vorschläge abgelehnt, Anfrage bleibt wie sie ist.
function rohstoff_alternativen_ablehnen(int $kunde_id, int $anfrage_id): bool {
    $p = one("SELECT nummer, betreff FROM portal_anfrage WHERE id=? AND kunde_id=? AND alternativen_gesendet_am IS NOT NULL AND alternativen_entschieden_am IS NULL", [$anfrage_id, $kunde_id]);
    if (!$p) return false;
    q("UPDATE portal_anfrage_alternative SET status='abgelehnt' WHERE anfrage_id=? AND status='vorgeschlagen'", [$anfrage_id]);
    q("UPDATE portal_anfrage SET alternativen_entschieden_am=UTC_TIMESTAMP() WHERE id=?", [$anfrage_id]);
    $txt = 'Kunde möchte zur Anfrage ' . $p['nummer'] . ' keine Alternative und bleibt bei ' . $p['betreff'] . '.';
    if (function_exists('log_aktivitaet')) log_aktivitaet('kunde', $kunde_id, 'kunde', $txt, 'anfrage', 'portal_anfrage', $anfrage_id);
    if (function_exists('benachrichtigung_add')) benachrichtigung_add('Keine Alternative: ' . $p['nummer'], $txt, '?p=portal_anfrage&id=' . $anfrage_id, 'anfrage');
    return true;
}
