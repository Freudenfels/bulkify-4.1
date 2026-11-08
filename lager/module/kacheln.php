<?php
// Kachel-Seiten der Lager-App (Nico 08.11.2026): Umbuchen, Versand, Lager 1, Lager 2. Gleiche Optik wie „Einbuchen".
// Jede Kachel führt auf eine VORHANDENE Seite – hier wird nichts neu gebaut, nur zusammengeführt. Zähler zeigen Offenes.
$seite = (string)($GLOBALS['p'] ?? ($_GET['p'] ?? ''));
$quar  = lg_quarantaene_zahl();
$seiten = [
    'umbuchen' => ['Umbuchen', 'Ware im Lager bewegen – Lagerplatz zuweisen, ins Lager 2 geben, Kisten verwalten.', [
        ['einlagern', 'Einlagern', 'Lagerplatz zuweisen', null],
        ['umlagern', 'Nach Lager 2', 'Fertige Ware ins Lager 2 des Kunden geben', null],
        ['kisten', 'Kisten', 'Kisten anlegen, befüllen, finden', null],
    ]],
    'versand_start' => ['Versand', 'Ware raus – Sendungen anlegen, Lieferscheine, Warenausgang.', [
        ['versand_bereit', 'Versandbereit', null, ['zahl' => count(erp_versandbereite_auftraege()), 'text' => 'versandbereite Aufträge', 'leer' => 'Fertige Aufträge zu Sendungen zusammenfassen']],
        ['versand', 'Warenausgang', 'Sendungen, Lieferschein und Label', null],
        ['lieferschein_neu', 'Lieferschein erstellen', 'Kunde und Produkte wählen – Lieferschein ohne Auftrag', null],
    ]],
    'lager1' => ['Lager 1', 'Unser Bestand – was da ist und was noch freigegeben werden muss.', [
        ['bestand', 'Bestand', 'Alle Chargen nach Kategorie', null],
        ['quarantaene', 'Quarantäne', null, ['zahl' => $quar, 'text' => 'Charge(n) warten auf Freigabe', 'leer' => 'Nichts in Quarantäne']],
    ]],
    'lager2' => ['Lager 2', 'Fremdlager – Ware, die Kunden gehört.', [
        ['l2_bestand', 'Fremdlager-Bestand', 'Kundenware je Kunde', null],
        ['l2_artikel', 'Artikel', 'Artikelkatalog des Fremdlagers', null],
    ]],
];
$s = $seiten[$seite] ?? $seiten['lager1'];

kopf($s[0], $seite);
seitenkopf($s[0], $s[1]);
flash_zeigen();
echo '<div class="lg-kacheln">';
foreach ($s[2] as [$route, $titel, $unter, $z]) {
    $sub = $unter;
    $badge = '';
    if ($z !== null) {
        $sub = $z['zahl'] > 0 ? $z['zahl'] . ' ' . $z['text'] : $z['leer'];
        if ($z['zahl'] > 0) $badge = '<span class="lg-kachel-z">' . (int)$z['zahl'] . '</span>';
    }
    echo '<a class="lg-kachel" href="?p=' . h($route) . '"><div class="lg-kachel-t">' . h($titel) . $badge . '</div><div class="lg-kachel-s">' . h((string)$sub) . '</div></a>';
}
echo '</div>';
fuss();
