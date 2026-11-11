<?php
// Dokument-Download für das KUNDENPORTAL. Öffentliche Route – deshalb hier drei Prüfungen:
//   1. gültiges Portal-Token (echter Kunde),
//   2. Dokument ausdrücklich freigegeben (dokument.kunde_sichtbar=1),
//   3. Kunde hat den passenden Portal-Bereich freigeschaltet.
// Ohne alle drei gibt es die Datei nicht. Interne Unterlagen (Lieferanten-Specs) bleiben so draußen.
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/dokument_ui.php';   // dokument_ist_lieferant_original()
require_once BX_ROOT . '/core/kunde_neutral.php';  // Paket 12.11.2026 Teil 3: Lieferant nie für Kunden sichtbar

$token = preg_replace('/[^a-f0-9]/', '', $_GET['token'] ?? '');
$k = $token ? one("SELECT * FROM kunden WHERE portal_token=?", [$token]) : null;
if (!$k) { http_response_code(403); echo 'Zugang ungültig.'; exit; }
if ((int)($k['gesperrt'] ?? 0) === 1 && !(is_logged_in() && !ist_echter_lieferant())) { http_response_code(403); echo h(zugang_gesperrt_text()); exit; }   // gesperrter Kunde (Sofortfix 11.11.)

$id = (int)($_GET['id'] ?? 0);
$d  = $id ? one("SELECT * FROM dokument WHERE id=? AND kunde_sichtbar=1", [$id]) : null;
if (!$d) { http_response_code(404); echo 'Dokument nicht verfügbar.'; exit; }

// Harte Sperre: Original-Unterlagen des Lieferanten (Rohstoff-Spec/CoA/Analyse) gehen NIE an den Kunden –
// der Kunde bekommt ausschließlich unser bulkify-Dokument (spec_pdf/coa_pdf). Gilt unabhängig von kunde_sichtbar.
if (dokument_ist_lieferant_original((string)$d['objekt_typ'], (string)$d['typ']) || kunde_dok_gesperrt($d)) { http_response_code(404); echo 'Dokument nicht verfügbar.'; exit; }

// Bereichs-Freischaltung des Kunden prüfen: Rohstoff-Dokumente brauchen den Rohstoff- oder Rezeptur-Bereich,
// Produkt-Dokumente den Produkt-Bereich.
// Paket 12.11.2026 Teil 3: Zugriff nur auf EIGENE Objekte (vorher reichte bei Produkt-/sonstigen Dokumenten der Bereich).
$ot = (string)$d['objekt_typ']; $oid = (int)$d['objekt_id'];
if ($ot === 'item')         $erlaubt = !empty($k['portal_rohstoffe']) || !empty($k['portal_rezeptur']) || !empty($k['portal_produkte']);
elseif ($ot === 'auftrag')  $erlaubt = (int) scalar("SELECT COUNT(*) FROM auftrag WHERE id=? AND kunde_id=?", [$oid, (int)$k['id']]) > 0;   // nur Eigentümer
elseif ($ot === 'produkt')  $erlaubt = !empty($k['portal_produkte']) && (int) scalar("SELECT COUNT(*) FROM produkt p WHERE p.id=? AND (p.kunde_id=? OR EXISTS (SELECT 1 FROM auftrag a WHERE a.produkt_id=p.id AND a.kunde_id=?))", [$oid, (int)$k['id'], (int)$k['id']]) > 0;
elseif ($ot === 'firma')    $erlaubt = true;   // Herstellerzertifikate von bulkify für alle Kunden
else                        $erlaubt = false;
if (!$erlaubt) { http_response_code(403); echo 'Kein Zugriff.'; exit; }

$path = BX_UPLOADS . '/' . basename((string)$d['datei']);
if (!is_file($path)) { http_response_code(404); echo 'Datei nicht vorhanden.'; exit; }

kunde_datei_ausliefern($path, kunde_dateiname($d));   // neutraler Name, PDF-Metadaten leer
