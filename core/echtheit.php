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
