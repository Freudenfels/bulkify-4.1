<?php
// Sammelanfrage: einem Lieferanten auf einen Schlag alles anfragen, was wir wirklich brauchen.
//
// Der Fall: Ein Lieferant ist neu dabei. Statt fuenfzig Preisanfragen von Hand zu tippen, stellt
// man ihm mit einem Klick alle Positionen aus unseren Rezepturen - und zieht so die Preise nach.
//
// Zwei Richtungen, je nachdem was der Lieferant ueberhaupt liefert:
//   'rohstoffe'  - jeder Rohstoff, der in einer Rezeptur vorkommt (fuer Rohstoffhaendler)
//   'rezepturen' - jede Rezeptur als fertiges Produkt (fuer Lohnhersteller)
//
// Was schon einmal bei DIESEM Lieferanten angefragt wurde, wird uebersprungen. Zweimal dieselbe
// Anfrage zu schicken ist peinlich und macht die Liste beim Lieferanten unbrauchbar.
require_once __DIR__ . '/schema.php';

// Rezepturen, die zaehlen: alles ausser abgelehnt und Entwurf - danach wird wirklich produziert.
function sammel_rezeptur_status(): array { return ['freigegeben', 'eingefroren', 'aktiv']; }

// Rohstoffe aus allen Rezepturen, die dieser Lieferant noch nicht angefragt bekommen hat.
function sammel_rohstoffe(int $lieferant_id): array {
    $st = sammel_rezeptur_status();
    $in = implode(',', array_fill(0, count($st), '?'));
    return all(
        "SELECT DISTINCT i.id, i.name, i.artikelnummer, i.form, i.einheit
         FROM rezeptur_zutat z
         JOIN rezeptur r ON r.id = z.rezeptur_id AND r.status IN ($in)
         JOIN item i     ON i.id = z.item_id AND i.gesperrt = 0
         WHERE z.item_id IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM lieferant_anfrage la WHERE la.lieferant_id = ? AND la.item_id = i.id)
         ORDER BY i.name", array_merge($st, [$lieferant_id]));
}

// Rezepturen, die dieser Lieferant noch nicht als Fertigprodukt angefragt bekommen hat.
function sammel_rezepturen(int $lieferant_id): array {
    $st = sammel_rezeptur_status();
    $in = implode(',', array_fill(0, count($st), '?'));
    return all(
        "SELECT r.id, r.nummer, r.name, r.darreichungsform, r.kapselgroesse_id
         FROM rezeptur r
         WHERE r.status IN ($in)
           AND NOT EXISTS (SELECT 1 FROM lieferant_anfrage la WHERE la.lieferant_id = ? AND la.rezeptur_id = r.id)
         ORDER BY r.name", array_merge($st, [$lieferant_id]));
}

// Die Anfragen wirklich stellen. Rueckgabe: [anzahl, hinweis].
// $menge ist die Bezugsmenge, auf die sich der Preis beziehen soll - darf leer bleiben, dann
// nennt der Lieferant seinen Preis samt Staffel und Mindestmenge selbst.
function sammel_anfragen_stellen(int $lieferant_id, string $was, string $notiz, ?float $menge = null): array {
    if ($lieferant_id <= 0) return [0, 'Lieferant fehlt.'];
    $notiz = trim($notiz);
    $n = 0;

    if ($was === 'rohstoffe') {
        foreach (sammel_rohstoffe($lieferant_id) as $i) {
            lieferant_anfrage_stellen($lieferant_id, (int)$i['id'], (string)$i['name'], $menge, '', $notiz, true,
                ['art' => 'rohstoff']);
            $n++;
        }
        return [$n, $n === 0 ? 'Es gibt nichts Neues anzufragen - alle Rohstoffe aus unseren Rezepturen liegen bei diesem Lieferanten schon an.' : ''];
    }

    if ($was === 'rezepturen') {
        foreach (sammel_rezepturen($lieferant_id) as $r) {
            $form = (string)($r['darreichungsform'] ?? '');
            lieferant_anfrage_stellen($lieferant_id, null, (string)$r['name'], $menge, '', $notiz, true,
                ['art' => 'fertigprodukt', 'form' => $form,
                 'kapselgroesse_id' => (int)($r['kapselgroesse_id'] ?? 0), 'rezeptur_id' => (int)$r['id']]);
            $n++;
        }
        return [$n, $n === 0 ? 'Es gibt nichts Neues anzufragen - alle Rezepturen liegen bei diesem Lieferanten schon an.' : ''];
    }

    return [0, 'Unbekannte Auswahl.'];
}
