<?php
// Produktinformationsblatt (PIB) eines Produkts ansehen – hochgeladenes hat Vorrang, sonst Auto-PIB.
// Interner Aufruf (Rechteprüfung erfolgt im Front-Controller). Gibt PDF/Bild direkt aus.
require_once BX_ROOT . '/core/pdf_pib.php';
$pid = (int)($_GET['id'] ?? 0);
$aid = (int)($_GET['aid'] ?? 0);   // 13.11.2026: mit Auftrag = Vorschau so, wie der Kunde es bekommt (Datum, Kundenname)
$kidA = $aid ? (int) scalar("SELECT kunde_id FROM auftrag WHERE id=? AND produkt_id=?", [$aid, $pid]) : 0;
if ($aid && !$kidA) $aid = 0;
$name = $pid ? (string) scalar("SELECT COALESCE(NULLIF(kundenname,''), name) FROM produkt WHERE id=?", [$pid]) : '';
if (!$pid || !pib_ausliefern($pid, 'Produktinfo-' . ($name !== '' ? $name : $pid), null, null, $aid, $kidA)) {
    http_response_code(404);
    echo 'Produktinformationsblatt nicht verfügbar.';
}
exit;
