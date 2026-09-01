<?php
// AGB (Allgemeine Geschaeftsbedingungen) - versioniert.
// Eine Fassung ist aktiv (aktiv=1). Beim verbindlichen Annehmen im Portal wird die dann
// gueltige Versionsbezeichnung am Vorgang gespeichert - damit ist belegbar, welche Fassung galt.
// Der Entwurfstext unten ist ein AUSGANGSTEXT und muss anwaltlich geprueft werden.

// Aktuell gueltige Fassung (oder null).
function agb_aktuell(): ?array {
    return one("SELECT * FROM agb WHERE aktiv=1 ORDER BY id DESC LIMIT 1");
}
// Bestimmte Fassung (fuer den Blick auf eine aeltere Version).
function agb_fassung(int $id): ?array {
    return one("SELECT * FROM agb WHERE id=?", [$id]);
}
// Versionsbezeichnung der aktuell gueltigen Fassung (leer, wenn keine hinterlegt ist).
function agb_version(): string {
    $a = agb_aktuell();
    return $a ? (string) $a['version'] : '';
}
// Neue Fassung speichern; die bisherige wird inaktiv (bleibt aber als Beleg erhalten).
function agb_speichern(string $version, string $inhalt): int {
    $version = trim($version) !== '' ? mb_substr(trim($version), 0, 40) : date('Y-m-d');
    q("UPDATE agb SET aktiv=0");
    q("INSERT INTO agb (version, inhalt, aktiv) VALUES (?,?,1)", [$version, $inhalt]);
    return insert_id();
}
// Einmalig einen Entwurf anlegen, solange keine Fassung existiert.
function agb_seed_wenn_leer(): void {
    if ((int) scalar("SELECT COUNT(*) FROM agb") > 0) return;
    agb_speichern('1.0 (Entwurf)', agb_entwurf_text());
}
// Ausgangstext, mit Firmenname und Sitz aus den Einstellungen.
function agb_entwurf_text(): string {
    $fa    = beleg_firma();
    $firma = h((string)($fa['name'] ?? ''));
    $sitz  = trim(((string)($fa['strasse'] ?? '')) . ', ' . ((string)($fa['plz_ort'] ?? '')), ', ');
    return str_replace(['{FIRMA}', '{SITZ}'], [$firma, $sitz !== '' ? ' (' . h($sitz) . ')' : ''], <<<'HTML'
<p><em>Entwurf – bitte vor produktivem Einsatz anwaltlich prüfen und an Ihr Geschäftsmodell anpassen.</em></p>

<h3>§ 1 Geltungsbereich</h3>
<p>Diese Allgemeinen Geschäftsbedingungen (AGB) gelten für alle Verträge zwischen {FIRMA}{SITZ} (nachfolgend „Hersteller") und dem Auftraggeber (nachfolgend „Kunde") über die Herstellung, Abfüllung und Konfektionierung von Nahrungsergänzungsmitteln und verwandten Produkten. Sie gelten ausschließlich gegenüber Unternehmern (B2B). Abweichende Bedingungen des Kunden werden nicht Vertragsbestandteil, es sei denn, der Hersteller stimmt ausdrücklich schriftlich zu.</p>

<h3>§ 2 Angebot und Vertragsschluss</h3>
<p>Angebote des Herstellers sind freibleibend. Ein Vertrag kommt erst mit der verbindlichen Annahme des Angebots durch den Kunden (z. B. Bestätigung im Kundenportal) und deren Bestätigung durch den Hersteller zustande. Mit der Annahme bestellt der Kunde verbindlich.</p>

<h3>§ 3 Rezeptur, Verkehrsfähigkeit und Verantwortung des Kunden</h3>
<p>Rezepturen, Dosierungen, Angaben und Kennzeichnungstexte werden – soweit nicht ausdrücklich anders vereinbart – vom Kunden vorgegeben bzw. freigegeben. Eine vom Hersteller vorab durchgeführte Prüfung ist ausschließlich <strong>technisch/rechnerisch</strong> (u. a. Mengen, Höchstmengen, Kapsel-/Formfüllung als Berechnung). Geschmack, Geruch, Löslichkeit sowie die tatsächliche Eignung für die gewählte Darreichungsform (z. B. Kapsel, Stick, Tablette, Flüssigkeit) sind damit nicht zugesichert und zeigen sich erst in Bemusterung/Produktion. Der Kunde ist – soweit nicht ausdrücklich schriftlich anders vereinbart – als Inverkehrbringer für die rechtliche Verkehrsfähigkeit des Produkts (u. a. zulässige Stoffe/Höchstmengen, Health Claims, Kennzeichnung) verantwortlich.</p>

<h3>§ 4 Mengen- und Fertigungstoleranzen</h3>
<p>Produktionsbedingt sind Abweichungen der gelieferten Gesamtmenge sowie der Füllmenge/Dosierung je Einheit von bis zu <strong>±10 %</strong> zulässig und branchenüblich; sie stellen keinen Sach­mangel dar und berechtigen nicht zur Minderung oder zum Rücktritt. Berechnet und geliefert wird die tatsächlich produzierte Menge innerhalb dieser Toleranz.</p>

<h3>§ 5 Liefer- und Produktionszeiten</h3>
<p>Angegebene Produktions- und Lieferzeiten sind <strong>unverbindliche Schätzwerte</strong> und können sich insbesondere durch Rohstoffverfügbarkeit, Analytik/Freigaben und Auslastung verschieben. Verbindliche (Fix-)Termine bedürfen der ausdrücklichen schriftlichen Vereinbarung. Teillieferungen sind zulässig, soweit für den Kunden zumutbar.</p>

<h3>§ 6 Preise und Zahlung</h3>
<p>Alle Preise verstehen sich netto zzgl. der gesetzlichen Umsatzsteuer. Sofern nichts anderes vereinbart ist, erfolgt die Zahlung per Vorkasse bzw. gemäß dem im Angebot genannten Zahlungsziel. Bei Zahlungsverzug gelten die gesetzlichen Regelungen.</p>

<h3>§ 7 Stornierung und Änderungen</h3>
<p>Nach verbindlicher Bestellung ist eine <strong>Stornierung nach Bestellung der Rohstoffe nicht mehr möglich</strong>. Bereits angefallene Kosten (u. a. Rohstoffe, Verpackung, Rüst-/Analytikkosten) sind vom Kunden zu tragen. Nachträgliche Änderungen bedürfen einer neuen Vereinbarung.</p>

<h3>§ 8 Bemusterung</h3>
<p>Muster (z. B. zur Prüfung von Geschmack, Optik, Formeignung) werden auf Wunsch und – soweit vereinbart – gegen gesonderte Berechnung erstellt. Ohne ausdrücklich beauftragte Bemusterung erfolgt die Produktion auf Grundlage der freigegebenen Rezeptur.</p>

<h3>§ 9 Gewährleistung und Untersuchungspflicht</h3>
<p>Der Kunde hat die Ware unverzüglich nach Erhalt zu untersuchen und erkennbare Mängel unverzüglich, spätestens innerhalb von 7 Tagen, schriftlich zu rügen; verdeckte Mängel unverzüglich nach Entdeckung. Im Übrigen gelten die gesetzlichen Gewährleistungsregeln für den unternehmerischen Verkehr. Abweichungen innerhalb der Toleranzen nach § 4 sind keine Mängel.</p>

<h3>§ 10 Haftung</h3>
<p>Der Hersteller haftet unbeschränkt bei Vorsatz und grober Fahrlässigkeit sowie nach dem Produkthaftungsgesetz und bei Verletzung von Leben, Körper oder Gesundheit. Bei einfacher Fahrlässigkeit haftet der Hersteller nur bei Verletzung wesentlicher Vertragspflichten, begrenzt auf den vertragstypischen, vorhersehbaren Schaden. Für Folgen einer vom Kunden vorgegebenen Rezeptur, Kennzeichnung oder Bewerbung (insb. Health Claims/Verkehrsfähigkeit) haftet der Hersteller nicht.</p>

<h3>§ 11 Eigentumsvorbehalt</h3>
<p>Die gelieferte Ware bleibt bis zur vollständigen Bezahlung Eigentum des Herstellers.</p>

<h3>§ 12 Rezepturen und geistiges Eigentum</h3>
<p>Vom Kunden eingebrachte Rezepturen bleiben dessen geistiges Eigentum. Vom Hersteller entwickelte Verfahren, Vorlagen und Optimierungen bleiben dessen Eigentum, soweit nicht ausdrücklich anders vereinbart. Lieferantenbeziehungen des Herstellers werden nicht offengelegt.</p>

<h3>§ 13 Höhere Gewalt</h3>
<p>Bei höherer Gewalt (u. a. Lieferengpässe, behördliche Maßnahmen, Betriebsstörungen ohne Verschulden) verlängern sich Fristen angemessen; ein Anspruch auf Schadensersatz wegen der Verzögerung besteht insoweit nicht.</p>

<h3>§ 14 Schlussbestimmungen</h3>
<p>Es gilt das Recht der Bundesrepublik Deutschland unter Ausschluss des UN-Kaufrechts. Gerichtsstand ist – soweit zulässig – der Sitz des Herstellers. Sollte eine Bestimmung unwirksam sein, bleibt die Wirksamkeit der übrigen unberührt.</p>

HTML);
}
