<?php
// DIE NAHT ZUM DASHBOARD. Die einzige Datei im CRM, die Tabellen des bulkify Dashboards kennt.
//
// Warum an einer Stelle: Das Dashboard wird weiterentwickelt, teilweise parallel. Wenn dort eine
// Spalte umbenannt wird, darf genau eine Datei kaputtgehen - diese. Ueberall sonst im CRM stehen
// nur `crm_`-Tabellen.
//
// Gelesen wird viel. Geschrieben wird ins Dashboard an genau EINER Stelle: erp_kunde_anlegen().
// Kein UPDATE, kein DELETE auf Dashboard-Daten. Nie.
require_once __DIR__ . '/db.php';

// Vorgaenge des Dashboards, auf die jemand wartet. Rueckgabe je Zeile:
//   typ, id, titel, unter, seit (UTC-Datetime), link (URL ins Dashboard), betrag, richtung
// richtung: 'sie'  = die warten auf uns   |  'wir' = wir warten auf andere
function erp_offene_vorgaenge(): array {
    $z = [];

    // --- Rezepturanfragen ohne Antwort ---------------------------------------------------------
    if (tabelle_da('rezeptur_anfrage')) {
        foreach (all("SELECT a.id, a.nummer, a.produktname, a.angelegt, a.rezeptur_id, k.firma
                      FROM rezeptur_anfrage a LEFT JOIN kunden k ON k.id = a.kunde_id
                      WHERE a.status IS NULL OR a.status NOT IN ('beantwortet','abgelehnt')
                      ORDER BY a.angelegt ASC") as $r) {
            $z[] = ['typ' => 'rezeptur_anfrage', 'id' => (int)$r['id'],
                'titel' => trim(($r['firma'] ?: 'Ohne Kunde') . ' – Rezepturanfrage ' . $r['nummer']),
                'unter' => trim((string)($r['produktname'] ?? '')) ?: 'ohne Produktnamen',
                'seit'  => (string)$r['angelegt'], 'link' => '?p=anfrage&id=' . (int)$r['id'],
                'betrag' => null, 'richtung' => 'sie'];
        }
    }

    // --- Produkt-, Rohstoff- und Dienstleistungsanfragen aus dem Kundenportal -------------------
    if (tabelle_da('portal_anfrage')) {
        foreach (all("SELECT a.id, a.nummer, a.typ, a.betreff, a.angelegt, k.firma
                      FROM portal_anfrage a LEFT JOIN kunden k ON k.id = a.kunde_id
                      WHERE a.status IS NULL OR a.status NOT IN ('beantwortet','abgelehnt')
                      ORDER BY a.angelegt ASC") as $r) {
            $art = ['produkt' => 'Produktanfrage', 'rohstoff' => 'Rohstoffanfrage',
                    'dienstleistung' => 'Dienstleistungsanfrage'][$r['typ']] ?? 'Anfrage';
            $z[] = ['typ' => 'portal_anfrage', 'id' => (int)$r['id'],
                'titel' => trim(($r['firma'] ?: 'Ohne Kunde') . ' – ' . $art . ' ' . $r['nummer']),
                'unter' => trim((string)($r['betreff'] ?? '')) ?: 'ohne Betreff',
                'seit'  => (string)$r['angelegt'],
                'link'  => '?p=portal_anfrage&id=' . (int)$r['id'],
                'betrag' => null, 'richtung' => 'sie'];
        }
    }

    // --- Angebote: gesendet, aber der Kunde hat nicht reagiert ---------------------------------
    // Das Dashboard merkt sich kein Versanddatum; `aktualisiert` ist der letzte Stand und damit
    // die ehrlichste Naeherung fuer "seit wann liegt das beim Kunden".
    if (tabelle_da('angebot')) {
        foreach (all("SELECT a.id, a.nummer, a.aktualisiert, a.angelegt, k.firma
                      FROM angebot a LEFT JOIN kunden k ON k.id = a.kunde_id
                      WHERE a.status = 'gesendet'
                      ORDER BY COALESCE(a.aktualisiert, a.angelegt) ASC") as $r) {
            $z[] = ['typ' => 'angebot', 'id' => (int)$r['id'],
                'titel' => trim(($r['firma'] ?: 'Ohne Kunde') . ' – Angebot ' . $r['nummer']),
                'unter' => 'gesendet, keine Reaktion',
                'seit'  => (string)($r['aktualisiert'] ?: $r['angelegt']),
                'link'  => '?p=angebot&id=' . (int)$r['id'],
                'betrag' => erp_angebot_summe((int)$r['id']), 'richtung' => 'wir'];
        }
    }

    // --- Entwuerfe: Angebot angefangen, nie gesendet -------------------------------------------
    if (tabelle_da('angebot')) {
        foreach (all("SELECT a.id, a.nummer, a.angelegt, k.firma
                      FROM angebot a LEFT JOIN kunden k ON k.id = a.kunde_id
                      WHERE a.status = 'offen' ORDER BY a.angelegt ASC") as $r) {
            $z[] = ['typ' => 'angebot_entwurf', 'id' => (int)$r['id'],
                'titel' => trim(($r['firma'] ?: 'Ohne Kunde') . ' – Angebot ' . $r['nummer']),
                'unter' => 'Entwurf, noch nicht gesendet',
                'seit'  => (string)$r['angelegt'],
                'link'  => '?p=angebot&id=' . (int)$r['id'],
                'betrag' => erp_angebot_summe((int)$r['id']), 'richtung' => 'sie'];
        }
    }

    // --- Rueckfragen von Lieferanten, die wir nicht beantwortet haben --------------------------
    if (tabelle_da('nachricht')) {
        foreach (all("SELECT n.id, n.text, n.erstellt, n.lieferant_id, l.firma
                      FROM nachricht n
                      LEFT JOIN lieferanten l ON l.id = n.lieferant_id
                      WHERE n.akteur = 'lieferant' AND (n.gelesen_team IS NULL OR n.gelesen_team = 0)
                      ORDER BY n.erstellt ASC") as $r) {
            $z[] = ['typ' => 'nachricht', 'id' => (int)$r['id'],
                'titel' => trim(($r['firma'] ?: 'Lieferant') . ' – Rueckfrage'),
                'unter' => mb_substr(trim((string)$r['text']), 0, 120),
                'seit'  => (string)$r['erstellt'],
                'link'  => '?p=lieferant&id=' . (int)$r['lieferant_id'] . '#rueckfragen',
                'betrag' => null, 'richtung' => 'sie'];
        }
    }

    // --- Preisanfragen, auf die der Lieferant noch nicht geantwortet hat -----------------------
    if (tabelle_da('lieferant_anfrage')) {
        foreach (all("SELECT a.id, a.nummer, a.betreff, a.angelegt, l.firma
                      FROM lieferant_anfrage a
                      LEFT JOIN lieferanten l ON l.id = a.lieferant_id
                      WHERE a.status = 'offen'
                        AND NOT EXISTS (SELECT 1 FROM lieferant_angebot g WHERE g.anfrage_id = a.id)
                      ORDER BY a.angelegt ASC") as $r) {
            $z[] = ['typ' => 'lieferant_anfrage', 'id' => (int)$r['id'],
                'titel' => trim(($r['firma'] ?: 'Lieferant') . ' – Preisanfrage ' . $r['nummer']),
                'unter' => trim((string)($r['betreff'] ?? '')) ?: 'ohne Betreff',
                'seit'  => (string)$r['angelegt'],
                'link'  => '?p=lieferant_anfrage&id=' . (int)$r['id'],
                'betrag' => null, 'richtung' => 'wir'];
        }
    }

    // --- Offene Aufgaben aus dem Dashboard ------------------------------------------------------
    if (tabelle_da('aufgabe')) {
        foreach (all("SELECT id, titel, beschreibung, faellig, angelegt
                      FROM aufgabe
                      WHERE erledigt_am IS NULL AND (status IS NULL OR status <> 'erledigt')
                      ORDER BY COALESCE(faellig, angelegt) ASC") as $r) {
            $z[] = ['typ' => 'aufgabe', 'id' => (int)$r['id'],
                'titel' => (string)$r['titel'],
                'unter' => mb_substr(trim((string)($r['beschreibung'] ?? '')), 0, 120) ?: 'Aufgabe',
                'seit'  => (string)($r['faellig'] ? $r['faellig'] . ' 00:00:00' : $r['angelegt']),
                'link'  => '?p=aufgaben', 'betrag' => null, 'richtung' => 'sie'];
        }
    }

    return $z;
}

// Angebotssumme - nur zur Anzeige. Das Dashboard rechnet den Preis an mehreren Stellen; hier
// reicht die Summe der Positionen, und wenn es die nicht gibt, eben nichts.
function erp_angebot_summe(int $angebot_id): ?float {
    if (!tabelle_da('angebot_position')) return null;
    try {
        $s = scalar("SELECT SUM(menge * preis_cent) / 100 FROM angebot_position WHERE angebot_id=?", [$angebot_id]);
        return $s === null ? null : (float)$s;
    } catch (Throwable $e) { return null; }
}

// Der aktuelle Stand eines Vorgangs - dieselbe Zahl, die in der Liste als "wartet seit" steht.
// Wird gebraucht, um beim Wegklicken zu merken, WORAUF sich das Wegklicken bezog.
function erp_stand(string $typ, int $id): string {
    foreach (erp_offene_vorgaenge() as $z) if ($z['typ'] === $typ && (int)$z['id'] === $id) return (string)$z['seit'];
    return '';
}

// --- Kunden ------------------------------------------------------------------------------------
function erp_kunden_suche(string $q, int $limit = 20): array {
    if (!tabelle_da('kunden')) return [];
    $q = trim($q);
    if ($q === '') return all("SELECT id, kundennummer, firma, ansprechpartner, email FROM kunden ORDER BY firma LIMIT ?", [$limit]);
    $w = '%' . $q . '%';
    return all("SELECT id, kundennummer, firma, ansprechpartner, email FROM kunden
                WHERE firma LIKE ? OR ansprechpartner LIKE ? OR email LIKE ? OR kundennummer LIKE ?
                ORDER BY firma LIMIT ?", [$w, $w, $w, $w, $limit]);
}
function erp_kunde(int $id): ?array {
    if (!tabelle_da('kunden')) return null;
    return one("SELECT * FROM kunden WHERE id=?", [$id]);
}

// Die EINZIGE Stelle, an der das CRM ins Dashboard schreibt: aus einem Kontakt einen Kunden machen.
// Die Kundennummer holt sich das Dashboard sonst aus seinem Nummernkreis - den fassen wir nicht an,
// sondern lassen das Feld leer. Das Dashboard vergibt sie beim ersten Speichern.
function erp_kunde_anlegen(array $daten): int {
    if (!tabelle_da('kunden')) return 0;
    q("INSERT INTO kunden (firma, ansprechpartner, email, telefon, notiz, angelegt)
       VALUES (?,?,?,?,?,?)",
      [mb_substr(trim((string)($daten['firma'] ?? '')), 0, 190) ?: 'Ohne Firma',
       mb_substr(trim((string)($daten['ansprechpartner'] ?? '')), 0, 190) ?: null,
       mb_substr(trim((string)($daten['email'] ?? '')), 0, 190) ?: null,
       mb_substr(trim((string)($daten['telefon'] ?? '')), 0, 60) ?: null,
       trim((string)($daten['notiz'] ?? '')) ?: null,
       gmdate('Y-m-d H:i:s')]);
    return insert_id();
}

// --- Benutzer (fuer die Anmeldung) --------------------------------------------------------------
function erp_benutzer_per_mail(string $email): ?array {
    if (!tabelle_da('benutzer')) return null;
    return one("SELECT id, name, email, pass_hash, rollen, aktiv FROM benutzer WHERE email=? AND aktiv=1",
               [trim(mb_strtolower($email))]);
}
// Nur fuer die Entwicklung am eigenen Rechner: Anmeldung ueber den Token, den das Dashboard
// ohnehin je Benutzer fuehrt. Wird ausschliesslich gelesen, nie geschrieben.
function erp_benutzer_per_token(string $token): ?array {
    if (!tabelle_da('benutzer') || trim($token) === '') return null;
    return one("SELECT id, name, email, rollen FROM benutzer WHERE login_token=? AND aktiv=1", [trim($token)]);
}

function erp_benutzer(int $id): ?array {
    if (!tabelle_da('benutzer')) return null;
    return one("SELECT id, name, email, rollen FROM benutzer WHERE id=? AND aktiv=1", [$id]);
}

// Adresse des Dashboards - fuer die Links aus der Liste heraus. Steht in crm_meta, weil das CRM
// unter einer anderen Adresse laeuft und den Weg zurueck kennen muss.
function erp_dashboard_url(): string {
    require_once __DIR__ . '/schema.php';
    return rtrim(crm_meta_lesen('dashboard_url', ''), '/');
}
