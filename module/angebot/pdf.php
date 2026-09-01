<?php
// Angebots-PDF fuer das Team: ?p=angebot_pdf&id=<ID>
// Gleiche Datei, die auch der Kunde im Portal bekommt (core/pdf_angebot.php) – damit sieht das
// Team genau das, was beim Kunden ankommt, und braucht dafuer keinen Kunden-Portallink.
require_once BX_ROOT . '/core/pdf_angebot.php';

$id = (int)($_GET['id'] ?? 0);
$a  = $id ? one("SELECT id, nummer FROM angebot WHERE id=?", [$id]) : null;
if (!$a) { http_response_code(404); echo 'Angebot nicht gefunden.'; exit; }

// Ohne Kunde am Angebot gibt es keinen Empfaenger – dann sagen wir das, statt ein leeres PDF zu liefern.
if (!angebot_pdf_ausliefern($id, (string)$a['nummer'])) {
    http_response_code(409);
    echo 'Fuer dieses Angebot gibt es noch kein PDF: Es ist kein Kunde hinterlegt. Bitte in den Kopfdaten einen Kunden waehlen.';
}
exit;
