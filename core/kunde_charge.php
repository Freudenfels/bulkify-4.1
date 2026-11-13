<?php
// Charge beim Kunden (Nico 12.11.2026, Nachbesserung 13.11.2026): der Kunde sieht nur die GRUNDCHARGE (2775). Die Buchstaben
// (2775.A, 2775.B) stehen für unsere Produktionstage und bleiben intern. EINE Stelle für alle Kundenstellen: Lieferschein,
// Palettenetikett, Informationsdokument (CoA-Übersicht), Portal (Bestellung, Laborprüfung, Lager 2), Echtheitsseite,
// Laborauftrag, Download-Dateinamen.
// Fable-3 T3.1 (13.11.2026): gekürzt wird NUR eine eigene Charge (aus einem eigenen Produktionsauftrag: Unter-Charge bzw. Charge
// mit pa_id). Fremd- und Lieferantenchargen („AB-12.C“, „LOT.EU“) bleiben unverändert. Kleinbuchstaben („2775.a“) bei eigenen auch.
// Nur q/scalar – auch aus der Lager-App ladbar (ohne Datenbank-Funktion wird nichts gekürzt).
if (!function_exists('kunde_charge_anzeige')) {
    // Stammt die Charge aus einem eigenen Produktionsauftrag? (je Anfrage gemerkt)
    function kunde_charge_eigen(string $charge_nr): bool {
        static $cache = [];
        $nr = trim($charge_nr);
        if ($nr === '') return false;
        if (array_key_exists($nr, $cache)) return $cache[$nr];
        // Fable-4 T4.3 (13.11.2026): „eigen“ heißt: die Grundcharge (vor .A/.B) ist die Nummer eines eigenen Produktionsauftrags
        // (PR-2775 -> 2775, wie pa_basis()). Lieferantenchargen bei Zukauf „fertig“ (pa_id gesetzt, Nummer des Lieferanten) werden so
        // nie gekürzt; eigene Altware ohne pa_id/Unter-Charge-Zeile („2739.A“ von Hand eingebucht) wird gekürzt.
        $eigen = false;
        if (function_exists('scalar') && preg_match('/^(.+)\.[A-Za-z]{1,2}$/', $nr, $m)) {
            $basis = trim($m[1]);
            try {
                $eigen = (int) scalar("SELECT COUNT(*) FROM produktionsauftrag WHERE nummer IN (?, ?, ?)", ['PR-' . $basis, 'PR ' . $basis, $basis]) > 0
                      || (preg_match('/^PR(\d+)$/i', $basis, $pm) && (int) scalar("SELECT COUNT(*) FROM produktionsauftrag WHERE id=? AND (nummer IS NULL OR nummer='')", [(int)$pm[1]]) > 0);
            } catch (\Throwable $e) {}
        }
        return $cache[$nr] = $eigen;
    }
    // $eigen: true/false, wenn der Aufrufer es schon weiß (z. B. pa_id der Charge), sonst null = nachsehen.
    function kunde_charge_anzeige(?string $charge_nr, ?bool $eigen = null): string {
        $nr = trim((string)$charge_nr);
        if (!preg_match('/^(.+)\.([A-Za-z]{1,2})$/', $nr, $m)) return $nr;
        if ($eigen === null) $eigen = kunde_charge_eigen($nr);
        return $eigen ? $m[1] : $nr;
    }
    // Freitext (Dokument-Titel, Mails): eigene Chargen mit Buchstaben („Laboranalyse 2775.A“) auf die Grundcharge kürzen (Fable-3 T3.2).
    function kunde_charge_text(?string $t): string {
        $t = (string)$t;
        if ($t === '' || !preg_match('/\.[A-Za-z]{1,2}\b/', $t)) return $t;
        // Fable-4 T4.2: bei ungültigem UTF-8 liefert preg null -> Text unverändert, nie leer.
        return preg_replace_callback('/(?<![\p{L}\p{N}.\-])([\p{L}\p{N}\-]*\d[\p{L}\p{N}\-]*)\.([A-Za-z]{1,2})(?![\p{L}\p{N}])/u',
            fn($m) => kunde_charge_eigen($m[0]) ? $m[1] : $m[0], $t) ?? $t;
    }
    // Gehören zwei Chargen-Nummern beim Kunden zur selben Grundcharge?
    function kunde_charge_gleich(?string $a, ?string $b): bool {
        return kunde_charge_anzeige($a) === kunde_charge_anzeige($b);
    }
}
