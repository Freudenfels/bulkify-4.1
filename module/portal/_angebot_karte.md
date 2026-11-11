# portal/_angebot_karte.php – eine Angebots-Karte im Kundenportal

Teilvorlage, die `kunde.php` je Angebot einbindet: „Meine Anfragen“ mit Reitern offen/zu bestätigen/Archiv sowie „Angebote“. Erwartet im Scope `$a` (Angebot), `$inf` (`$angInfoFuer($a)`), `$st` (Staffeln), `$accept` (Annehmen erlaubt) und die Portal-Helfer (`$mg`, `$eur`, `$kid`, `$ustP`, `$portalLink`, `$titelFuer` …).

Zeigt Status, PDF, Verpackung/Deckel/Etikett, die Rezeptur (Zutaten + Nährwerte), die Staffeln bzw. die Produkt-Matrix mit Preisen und, wenn erlaubt, die Knöpfe zum verbindlichen Annehmen (`bxBestaetigen`, mit Labortest-Upsell). Bei angenommenen Angeboten erscheint der echte Bestellstatus aus dem Auftrag, dazu Nachbestellung und Rückfrage.

## Pulver: Packungsgröße = g (03.11.2026)
`stueck` ist bei Pulver/Granulat die Füllmenge in g. Die Karte zeigt „300 g (≈ 60 Tagesdosen à 5 g)“ (`$paketTxt`, `tagesdosen_txt()`). Vorher stand fälschlich g × Portionsgewicht da (300 × 5 g = 1.500 g). Zutaten/Nährwerte heißen bei Pulver „je Tagesdosis“.

## Abgelaufen (11.11.2026)
- `$abgelaufen` setzt `$canAccept` auf falsch.
- Die Karte zeigt „Dieses Angebot ist abgelaufen … - bitte neues Angebot anfordern“ und den Knopf „Aktualisierung anfragen“.
