<?php
// Kurze Anleitung für den Zähler (11.11.2026): 5 Schritte mit Bild (Linien-Icons, keine Emojis). Auf der Inventur-Startseite
// und aufklappbar beim Zählen.
function inv_anleitung_schritte(): array {
    $s = 'fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"';
    return [
        ['Platz scannen', 'Barcode am Regalfach (LP-…) scannen oder den Platz wählen. Oben steht dann „Platz … · Zählung läuft“.',
         '<svg viewBox="0 0 64 64" ' . $s . '><rect x="6" y="8" width="52" height="48" rx="3"/><path d="M6 30h52M6 44h52"/><rect x="18" y="16" width="28" height="9" rx="1"/><path d="M22 18v5M25 18v5M29 18v5M31 18v5M35 18v5M38 18v5M42 18v5"/></svg>'],
        ['Gebinde scannen', 'Jeden Karton, Sack, Eimer scannen (Aufkleber mit QR oder Strichcode). Du siehst Artikel, Charge, MHD und die Einheit – aber nie, wie viel drin sein soll.',
         '<svg viewBox="0 0 64 64" ' . $s . '><path d="M10 22l22-10 22 10v24L32 56 10 46z"/><path d="M10 22l22 10 22-10M32 32v24"/><rect x="38" y="36" width="10" height="10"/><path d="M40 38h2v2h-2zM44 42h2v2h-2z"/></svg>'],
        ['Menge erfassen', 'Stück zählen und eintippen, oder „voll, original verschlossen“. Wiegbare Ware und Kapsel-Säcke auf die Waage: Brutto eintippen, Gebindetyp wählen – Netto bzw. Kapseln rechnet das System. Leer? „Gebinde leer“.',
         '<svg viewBox="0 0 64 64" ' . $s . '><path d="M12 50h40l-4-18H16z"/><path d="M22 32v-6a10 10 0 0 1 20 0v6"/><rect x="24" y="38" width="16" height="7" rx="1"/><path d="M28 41.5h8"/></svg>'],
        ['Ohne Etikett?', '„Gefunden ohne Etikett“: Artikel suchen, Charge vom Gebinde abtippen, MHD, Menge, Foto machen. Das neue Etikett kommt aus dem Drucker – gleich aufkleben.',
         '<svg viewBox="0 0 64 64" ' . $s . '><rect x="8" y="18" width="34" height="26" rx="3"/><circle cx="25" cy="31" r="7"/><path d="M15 18l3-5h14l3 5"/><rect x="40" y="34" width="16" height="20" rx="2"/><path d="M44 40h8M44 45h8M44 50h5"/></svg>'],
        ['Platz fertig', 'Wenn alles auf dem Platz erfasst ist: „Platz fertig“. Dann den nächsten Platz scannen. Unterbrechen ist jederzeit möglich – nichts geht verloren.',
         '<svg viewBox="0 0 64 64" ' . $s . '><circle cx="32" cy="32" r="22"/><path d="M21 33l7 7 15-16"/></svg>'],
    ];
}
function inv_anleitung_html(bool $zugeklappt = false): string {
    $h = '<style>.inv-an{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-top:10px}'
       . '.inv-an div{border:1px solid var(--line);border-radius:12px;padding:14px;background:var(--panel)}'
       . '.inv-an svg{width:56px;height:56px;color:var(--gruen);display:block;margin-bottom:8px}'
       . '.inv-an b{font-weight:600;display:block;margin-bottom:4px}.inv-an p{margin:0;font-size:14px;color:var(--muted)}</style>';
    $h .= '<details class="bx-panel"' . ($zugeklappt ? '' : ' open') . '><summary style="cursor:pointer;font-size:var(--fs-lg)">Anleitung: so zählst du (5 Schritte)</summary><div class="inv-an">';
    foreach (inv_anleitung_schritte() as $i => [$t, $txt, $svg])
        $h .= '<div>' . $svg . '<b>' . ($i + 1) . '. ' . h($t) . '</b><p>' . h($txt) . '</p></div>';
    return $h . '</div><p class="muted" style="margin:12px 0 0;font-size:13px">Blind zählen heißt: Du zählst, was wirklich da ist. Den Abgleich mit dem Bestand macht danach das Büro.</p></details>';
}
