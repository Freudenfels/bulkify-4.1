<?php
// Link auf die Seite eines Artikels (Rohstoff, Verpackung/Etikett, Betriebsmittel, Bulk/Fertigware) – Nico 12.11.2026:
// „Ich kann nicht auf das Material klicken, um zum Material zu kommen.“ Standard-Reiter ist „Lager“ (Bestand, Chargen,
// Ort/Blinker, Bestand korrigieren), weil man von Material-Listen aus fast immer den Bestand sucht.

if (!function_exists('item_url')) {

function item_url(int $item_id, ?string $kategorie = null, string $tab = 'lager'): string {
    if ($item_id <= 0) return '';
    if ($kategorie === null) $kategorie = (string) scalar("SELECT kategorie FROM item WHERE id=?", [$item_id]);
    $route = 'rohstoff';
    if ($kategorie === 'verpackung') $route = 'verpackung';
    elseif ($kategorie !== '' && function_exists('ist_betriebsmittel_kat') && ist_betriebsmittel_kat($kategorie)) $route = 'betriebsmittel';
    return '?p=' . $route . '&id=' . $item_id . ($tab !== '' && $route !== 'betriebsmittel' ? '&tab=' . rawurlencode($tab) : '');
}
// Name als Link (Text wird hier escaped). Ohne item_id nur der Text.
function item_link_html(int $item_id, string $name, string $tab = 'lager'): string {
    $u = item_url($item_id, null, $tab);
    return $u === '' ? h($name) : '<a href="' . h($u) . '" title="Artikel öffnen (Bestand, Chargen, Ort)">' . h($name) . '</a>';
}

}
