<?php
// Echtheits-/Qualitaets-QR je Charge.
// Idee: Auf dem bulkify-CoA/Spec steht ein QR-Code. Scannt der Kunde ihn, oeffnet sich die
// oeffentliche Pruefseite public/echt.php und bestaetigt den Rohstoff dieser Charge mit genau den
// Angaben, die auch im Dokument stehen (nur kundensichere Felder – nie Lieferant, EK, interne Logistik).
//
// Mechanik: KEINE Verschluesselung im Link, sondern ein ZUFAELLIGES, nicht erratbares Token (base62,
// ~128 Bit), das in der DB (charge.echt_token) auf die Charge zeigt. Vorteile: nicht hochzaehlbar,
// serverseitig widerrufbar (charge.echt_gesperrt), kurz genug fuer einen kleinen QR.
//
// Grenze (bewusst): Ein QR bestaetigt "diese Angaben hat bulkify fuer diese Charge veroeffentlicht"
// und ist nicht erratbar. Er schuetzt NICHT gegen das Abfotografieren/Kopieren des Codes auf eine
// Faelschung – das braeuchte Seriennummern je Einheit + Scanzaehler (spaeterer Ausbau).
require_once __DIR__ . '/schema.php';

// Basis-URL fuer den Pruef-Link: nutzt mail_basis_url() (Einstellung portal_url, sonst aktueller Host),
// damit alle absoluten Links aus einer Quelle kommen. Fallback, falls mail.php nicht geladen ist.
function echt_basis_url(): string {
    if (function_exists('mail_basis_url')) return mail_basis_url();
    $b = rtrim((string) meta_get('portal_url', ''), '/');
    if ($b === '') $b = (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] ?? 'off') !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $b;
}

// Zufaelliges, nicht erratbares Token: 22 Zeichen base62 (~128 Bit aus random_bytes).
function echt_token_neu(): string {
    $alpha = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz'; // 62
    $b = random_bytes(22); $s = '';
    for ($i = 0; $i < 22; $i++) $s .= $alpha[ord($b[$i]) % 62];
    return $s;
}

// Oeffentlicher Pruef-Link zu einem Token.
function echt_url(string $token): string {
    return echt_basis_url() . '/echt.php?t=' . rawurlencode($token);
}

// Token einer Charge holen – lazy: existiert keins, wird eins erzeugt und gespeichert.
// Gibt '' zurueck, wenn die Charge fehlt oder die Spalte (noch) nicht existiert (best effort, nie werfend).
function charge_echt_token(int $charge_id): string {
    if ($charge_id <= 0) return '';
    try {
        $cur = (string) scalar("SELECT echt_token FROM charge WHERE id=?", [$charge_id]);
        if ($cur !== '') return $cur;
        for ($try = 0; $try < 5; $try++) {
            $tok = echt_token_neu();
            if ((int) scalar("SELECT COUNT(*) FROM charge WHERE echt_token=?", [$tok]) > 0) continue; // Kollision (praktisch nie)
            q("UPDATE charge SET echt_token=? WHERE id=? AND (echt_token IS NULL OR echt_token='')", [$tok, $charge_id]);
            $cur = (string) scalar("SELECT echt_token FROM charge WHERE id=?", [$charge_id]);
            if ($cur !== '') return $cur;
        }
        return $cur;
    } catch (\Throwable $e) {
        return '';
    }
}

// Pruef-Link einer Charge (erzeugt bei Bedarf das Token). '' wenn nicht moeglich.
function charge_echt_url(int $charge_id): string {
    $tok = charge_echt_token($charge_id);
    return $tok !== '' ? echt_url($tok) : '';
}

// Datenbündel für die öffentliche Prüfseite – NUR kundensichere Felder, wie im bulkify-CoA/Spec.
// Rückgabe:
//   null                      -> Token ungültig/unbekannt (die Seite zeigt eine neutrale "nicht bestätigt"-Seite)
//   ['gesperrt'=>true]        -> Token widerrufen
//   ['gesperrt'=>false, ...]  -> Daten (name, charge_nr, mhd, herkunft, spec, allergene, werte[], ist_coa, firma)
function charge_echt_daten(string $token): ?array {
    $token = trim($token);
    if ($token === '' || !preg_match('/^[0-9A-Za-z]{10,32}$/', $token)) return null;
    try {
        $c = one("SELECT c.id, c.charge_nr, c.mhd, c.echt_gesperrt,
                         i.name AS item_name, i.spec_nr, i.spec_version, i.herkunftsland, i.allergene,
                         i.beschaffenheit, i.dev
                  FROM charge c JOIN item i ON i.id=c.item_id
                  WHERE c.echt_token=?", [$token]);
    } catch (\Throwable $e) {
        return null;
    }
    if (!$c) return null;
    if ((int) ($c['echt_gesperrt'] ?? 0) === 1) return ['gesperrt' => true];

    $itLike = ['name' => (string) $c['item_name'], 'beschaffenheit' => (string) ($c['beschaffenheit'] ?? ''), 'dev' => (string) ($c['dev'] ?? '')];
    $anzName = function_exists('rohstoff_anzeige_name') ? rohstoff_anzeige_name($itLike) : (string) $c['item_name'];
    $istCoa  = function_exists('charge_coa_hat_analysewerte') ? (bool) charge_coa_hat_analysewerte((int) $c['id']) : false;

    $werte = [];
    try {
        $werte = all("SELECT parameter, spezifikation, ergebnis, methode
                      FROM charge_analyse WHERE charge_id=? ORDER BY sort, id", [(int) $c['id']]);
    } catch (\Throwable $e) { $werte = []; }

    $firma = function_exists('beleg_firma') ? beleg_firma() : ['name' => 'Maniso GmbH'];

    return [
        'gesperrt'  => false,
        'ist_coa'   => $istCoa,
        'name'      => $anzName,
        'charge_nr' => (string) ($c['charge_nr'] ?? ''),
        'mhd'       => $c['mhd'] ?? null,
        'herkunft'  => (string) ($c['herkunftsland'] ?? ''),
        'spec'      => trim((string) ($c['spec_nr'] ?? '') . ' ' . (string) ($c['spec_version'] ?? '')),
        'allergene' => (string) ($c['allergene'] ?? ''),
        'werte'     => $werte,
        'firma'     => (string) ($firma['name'] ?? 'Maniso GmbH'),
    ];
}

// ---------------------------------------------------------------------------
// Produkt-Ebene (QR im PIB) – fuehrt auf die Produktseite mit den bestaetigten Chargen.
// ---------------------------------------------------------------------------

// Oeffentlicher Produkt-Link zu einem Token (eigener Parameter ?p=, getrennt von der Charge ?t=).
function echt_url_produkt(string $token): string {
    return echt_basis_url() . '/echt.php?p=' . rawurlencode($token);
}

// Token eines Produkts holen – lazy, kollisionssicher, nie werfend.
function produkt_echt_token(int $produkt_id): string {
    if ($produkt_id <= 0) return '';
    try {
        $cur = (string) scalar("SELECT echt_token FROM produkt WHERE id=?", [$produkt_id]);
        if ($cur !== '') return $cur;
        for ($try = 0; $try < 5; $try++) {
            $tok = echt_token_neu();
            if ((int) scalar("SELECT COUNT(*) FROM produkt WHERE echt_token=?", [$tok]) > 0) continue;
            q("UPDATE produkt SET echt_token=? WHERE id=? AND (echt_token IS NULL OR echt_token='')", [$tok, $produkt_id]);
            $cur = (string) scalar("SELECT echt_token FROM produkt WHERE id=?", [$produkt_id]);
            if ($cur !== '') return $cur;
        }
        return $cur;
    } catch (\Throwable $e) {
        return '';
    }
}

// Pruef-Link eines Produkts (erzeugt bei Bedarf das Token). '' wenn nicht moeglich.
function produkt_echt_url(int $produkt_id): string {
    $tok = produkt_echt_token($produkt_id);
    return $tok !== '' ? echt_url_produkt($tok) : '';
}

// Datenbündel für die öffentliche Produktseite – NUR kundensichere Felder.
// Rückgabe wie charge_echt_daten: null (unbekannt), ['gesperrt'=>true], sonst Daten inkl. Liste der
// für dieses Produkt ausgestellten (= mit eigenem QR versehenen, nicht gesperrten) Chargen.
function produkt_echt_daten(string $token): ?array {
    $token = trim($token);
    if ($token === '' || !preg_match('/^[0-9A-Za-z]{10,32}$/', $token)) return null;
    try {
        $pr = one("SELECT p.id, p.name, p.echt_gesperrt,
                          COALESCE(NULLIF(p.kundenname,''), p.name) AS anzeige,
                          r.darreichungsform
                   FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id
                   WHERE p.echt_token=?", [$token]);
    } catch (\Throwable $e) {
        return null;
    }
    if (!$pr) return null;
    if ((int) ($pr['echt_gesperrt'] ?? 0) === 1) return ['gesperrt' => true];

    // Ausgestellte Chargen dieses Produkts: über Produktionsauftrag ODER Fertigware-Item verknüpft,
    // und nur solche mit eigenem, nicht gesperrtem Echtheits-Token (= wir haben dafür ein CoA/QR ausgegeben).
    $chargen = [];
    try {
        $chargen = all("SELECT DISTINCT c.charge_nr, c.mhd, c.echt_token
                        FROM charge c
                        WHERE c.echt_token IS NOT NULL AND c.echt_token <> '' AND c.echt_gesperrt=0
                          AND ( c.pa_id IN (SELECT id FROM produktionsauftrag WHERE produkt_id=?)
                             OR c.item_id IN (SELECT id FROM item WHERE produkt_id=?) )
                        ORDER BY c.mhd DESC, c.id DESC", [(int) $pr['id'], (int) $pr['id']]);
    } catch (\Throwable $e) { $chargen = []; }

    $formLbl = ['kapsel'=>'Kapseln','tablette'=>'Tabletten','softgel'=>'Softgels','stick'=>'Sticks',
                'pulver'=>'Pulver','granulat'=>'Granulat','fluessig'=>'Flüssig','gel'=>'Gel','gummi'=>'Fruchtgummi'][$pr['darreichungsform'] ?? ''] ?? '';
    $firma = function_exists('beleg_firma') ? beleg_firma() : ['name' => 'Maniso GmbH'];

    return [
        'gesperrt' => false,
        'name'     => (string) $pr['anzeige'],
        'form'     => $formLbl,
        'chargen'  => $chargen,
        'firma'    => (string) ($firma['name'] ?? 'Maniso GmbH'),
    ];
}
