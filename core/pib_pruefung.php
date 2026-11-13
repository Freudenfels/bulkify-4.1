<?php
// PIB prüfen, bevor der Kunde es bekommt (Paket 13.11.2026, Teil 3). EINE Stelle für alle Wege:
// - neuer Auftrag  -> pib_pruefung_anlegen() (aus auftrag_name_snapshot(), das alle Anlage-Wege aufrufen)
// - Rezeptur-Seite -> Kasten „PIB prüfen“, Knopf „PIB geprüft“ -> pib_freigeben()
// - Portal         -> pib_kunde_darf() sperrt Download und Link serverseitig
// - Rezeptur geändert -> pib_rezeptur_geaendert() bzw. beim Abruf pib_status() -> neue Prüfung
// Altaufträge (vor dieser Regel) haben pib_pruefung = NULL: keine Aufgabe, Download offen, „Stand“ = Auftragsbestätigung.
// Braucht core/schema.php (all/one/q, aufgabe_neu) – nur im Dashboard und Kundenportal benutzt.

function pib_schema(): void {
    static $ok = false; if ($ok) return; $ok = true;
    try {
        ensure_column('auftrag', 'pib_pruefung', "VARCHAR(10) NULL");          // NULL = Altauftrag (ohne Prüfpflicht) | offen | frei
        ensure_column('auftrag', 'pib_freigegeben_am', "DATETIME NULL");       // UTC
        ensure_column('auftrag', 'pib_freigegeben_von', "VARCHAR(190) NULL");
        ensure_column('auftrag', 'pib_stand', "CHAR(40) NULL");                // Fingerabdruck der Rezeptur zum Zeitpunkt der Freigabe
        ensure_column('auftrag', 'pib_mail_am', "DATETIME NULL");              // erste Mail (PIB + ggf. Etikett-Bitte) verschickt
    } catch (Throwable $e) {}
}

// Rezeptur und Produkt eines Auftrags.
function pib_auftrag(int $aid): ?array {
    pib_schema();
    return one("SELECT a.id, a.nummer, a.kunde_id, a.produkt_id, a.status, a.angelegt, COALESCE(a.kategorie,'produkt') AS kategorie,
                       a.pib_pruefung, a.pib_freigegeben_am, a.pib_freigegeben_von, a.pib_stand, a.pib_mail_am,
                       COALESCE(a.rezeptur_id, p.rezeptur_id) AS rezeptur_id, COALESCE(NULLIF(a.produkt_bezeichnung,''), p.name) AS produkt
                FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id WHERE a.id=?", [$aid]) ?: null;
}

// Fingerabdruck dessen, was im PIB steht und vom Team geprüft wurde: Zutaten + Mengen, Darreichungsform, Kapselgröße,
// fixierte Nährwerte, Produktangaben (Einheiten, Einnahme, Behälter, Leerkapsel, Allergene).
// Bewusst NICHT: Wirkstoffgehalte am Rohstoff (CoA-/Spec-Werte innerhalb der Spec lösen keine Neuprüfung aus, Teil 5).
function pib_fingerabdruck(int $rezeptur_id, int $produkt_id): string {
    $teile = [];
    foreach (all("SELECT COALESCE(item_id,0) AS i, COALESCE(bezeichnung,'') AS b, ROUND(menge_mg,3) AS m FROM rezeptur_zutat WHERE rezeptur_id=? ORDER BY item_id, bezeichnung, menge_mg", [$rezeptur_id]) as $z)
        $teile[] = 'z:' . $z['i'] . ':' . ($z['i'] ? '' : mb_strtolower(trim($z['b']))) . ':' . $z['m'];
    $r = one("SELECT darreichungsform, kapselgroesse_id FROM rezeptur WHERE id=?", [$rezeptur_id]) ?: [];
    $teile[] = 'r:' . ($r['darreichungsform'] ?? '') . ':' . ($r['kapselgroesse_id'] ?? '');
    try { foreach (all("SELECT * FROM rezeptur_naehrwert WHERE rezeptur_id=? ORDER BY id", [$rezeptur_id]) as $n) { unset($n['id']); $teile[] = 'n:' . json_encode($n); } } catch (Throwable $e) {}
    if ($produkt_id > 0) {
        $p = one("SELECT einheiten_pro_packung, einnahme_pro_tag, verpackung_id, leerkapsel_id, allergene FROM produkt WHERE id=?", [$produkt_id]) ?: [];
        $teile[] = 'p:' . json_encode($p);
    }
    return sha1(implode('|', $teile));
}

// Pflichtangaben, ohne die das PIB nicht vollständig ist. Leere Liste = vollständig.
function pib_unvollstaendig(int $produkt_id): array {
    $f = [];
    $p = one("SELECT p.*, r.darreichungsform FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id=?", [$produkt_id]);
    if (!$p) return ['Produkt fehlt'];
    if (empty($p['rezeptur_id'])) return ['keine Rezeptur am Produkt'];
    if ((int) scalar("SELECT COUNT(*) FROM rezeptur_zutat WHERE rezeptur_id=?", [(int)$p['rezeptur_id']]) === 0) $f[] = 'keine Zutaten in der Rezeptur';
    if ((float)($p['einheiten_pro_packung'] ?? 0) <= 0) $f[] = 'Einheiten bzw. Füllmenge je Packung';
    if ((float)($p['einnahme_pro_tag'] ?? 0) <= 0) $f[] = 'Verzehrempfehlung (Einnahme pro Tag)';
    $mitGehalt = (int) scalar("SELECT COUNT(DISTINCT z.item_id) FROM rezeptur_zutat z JOIN item_wirkstoff w ON w.item_id=z.item_id WHERE z.rezeptur_id=?", [(int)$p['rezeptur_id']]);
    if ($mitGehalt === 0) $f[] = 'Wirkstoffgehalte/NRV der Rohstoffe (Nährwerttabelle leer)';
    return $f;
}

// Neuer Auftrag: Prüfpflicht setzen und Aufgabe „PIB prüfen“ (dringend, Ziel = Rezeptur). Idempotent, nur Produkt-Aufträge.
function pib_pruefung_anlegen(int $aid): void {
    $a = pib_auftrag($aid);
    if (!$a || $a['pib_pruefung'] !== null || (int)$a['produkt_id'] <= 0 || $a['kategorie'] === 'dienstleistung') return;
    q("UPDATE auftrag SET pib_pruefung='offen' WHERE id=? AND pib_pruefung IS NULL", [$aid]);
    pib_aufgabe($a, 'Neuer Auftrag – bitte das Produktinformationsblatt prüfen, bevor der Kunde es bekommt.');
}
function pib_aufgabe(array $a, string $text): void {
    if (!function_exists('aufgabe_neu')) return;
    $offen = (int) scalar("SELECT COUNT(*) FROM aufgabe WHERE status='offen' AND ref_typ='auftrag' AND ref_id=? AND titel LIKE 'PIB prüfen%'", [(int)$a['id']]);
    if ($offen > 0) return;
    $rid = (int)$a['rezeptur_id'];
    aufgabe_neu('PIB prüfen – ' . $a['nummer'] . ', ' . ($a['produkt'] ?: 'Produkt'), $text . ' Prüfen und freigeben an der Rezeptur (Kasten „PIB prüfen“).',
        1, null, null, null, 'auftrag', (int)$a['id'], $rid > 0 ? '?p=rezeptur_detail&id=' . $rid . '#pibpruefen' : '?p=auftrag&id=' . (int)$a['id']);
}

// Stand eines Auftrags: '' (Altauftrag), 'offen', 'frei'. Prüft nebenbei, ob sich die Rezeptur seit der Freigabe geändert hat.
function pib_status(int $aid): string {
    $a = pib_auftrag($aid);
    if (!$a || $a['pib_pruefung'] === null) return '';
    if ($a['pib_pruefung'] === 'frei' && (string)$a['pib_stand'] !== '' && !in_array($a['status'], ['versendet', 'storniert'], true)
        && pib_fingerabdruck((int)$a['rezeptur_id'], (int)$a['produkt_id']) !== (string)$a['pib_stand']) {
        pib_neu_pruefen($aid, 'Die Rezeptur bzw. die Produktangaben haben sich seit der Freigabe geändert.');
        return 'offen';
    }
    return (string)$a['pib_pruefung'];
}
function pib_kunde_darf(int $aid): bool { return pib_status($aid) !== 'offen'; }

// Freigabe zurücknehmen: neue Prüfung, Download gesperrt, Aufgabe.
function pib_neu_pruefen(int $aid, string $grund): void {
    $a = pib_auftrag($aid); if (!$a || $a['pib_pruefung'] === null) return;
    q("UPDATE auftrag SET pib_pruefung='offen' WHERE id=?", [$aid]);
    if (function_exists('log_aktivitaet')) log_aktivitaet('auftrag', $aid, 'system', 'PIB muss neu geprüft werden: ' . $grund, 'pib');
    pib_aufgabe($a, $grund);
}
// Nach dem Speichern einer Rezeptur: alle freigegebenen, noch nicht versendeten Aufträge dieser Rezeptur neu prüfen, wenn sich etwas geändert hat.
function pib_rezeptur_geaendert(int $rezeptur_id): int {
    pib_schema(); $n = 0;
    foreach (all("SELECT a.id FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id
                  WHERE COALESCE(a.rezeptur_id, p.rezeptur_id)=? AND a.pib_pruefung='frei' AND a.status NOT IN ('versendet','storniert')", [$rezeptur_id]) as $r)
        if (pib_status((int)$r['id']) === 'offen') $n++;
    return $n;
}

// Wartende Aufträge einer Rezeptur (für den Kasten an der Rezeptur).
function pib_wartend_fuer_rezeptur(int $rezeptur_id): array {
    pib_schema();
    pib_rezeptur_geaendert($rezeptur_id);
    return all("SELECT a.id, a.nummer, a.produkt_id, a.kunde_id, k.firma, COALESCE(NULLIF(a.produkt_bezeichnung,''), p.name) AS produkt, a.angelegt
                FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id LEFT JOIN kunden k ON k.id=a.kunde_id
                WHERE COALESCE(a.rezeptur_id, p.rezeptur_id)=? AND a.pib_pruefung='offen' AND a.status NOT IN ('storniert')
                ORDER BY a.angelegt, a.id", [$rezeptur_id]);
}

// „PIB geprüft“: Freigabe, Aufgabe erledigen, Mail an den Kunden (PIB + ggf. Etikett-Bitte). Rückgabe ['ok'=>…, 'msg'=>…].
function pib_freigeben(int $aid, string $wer, ?int $uid = null): array {
    $a = pib_auftrag($aid);
    if (!$a) return ['ok' => false, 'msg' => 'Auftrag nicht gefunden.'];
    if ($a['pib_pruefung'] !== 'offen') return ['ok' => false, 'msg' => 'Für ' . $a['nummer'] . ' ist keine PIB-Prüfung offen.'];
    $fehlt = pib_unvollstaendig((int)$a['produkt_id']);
    if ($fehlt) return ['ok' => false, 'msg' => 'PIB unvollständig: ' . implode(', ', $fehlt) . '. Erst ergänzen, dann freigeben.'];
    $erste = $a['pib_freigegeben_am'] === null;
    q("UPDATE auftrag SET pib_pruefung='frei', pib_freigegeben_am=?, pib_freigegeben_von=?, pib_stand=? WHERE id=?",
      [gmdate('Y-m-d H:i:s'), mb_substr($wer, 0, 190), pib_fingerabdruck((int)$a['rezeptur_id'], (int)$a['produkt_id']), $aid]);
    q("UPDATE aufgabe SET status='erledigt', erledigt_am=?, erledigt_von=? WHERE status='offen' AND ref_typ='auftrag' AND ref_id=? AND titel LIKE 'PIB prüfen%'",
      [gmdate('Y-m-d H:i:s'), $uid, $aid]);
    if (function_exists('log_aktivitaet')) log_aktivitaet('auftrag', $aid, 'team', 'PIB geprüft und freigegeben' . ($wer !== '' ? ' (' . $wer . ')' : ''), 'pib');
    $mail = '';
    if (function_exists('pib_mail_kunde')) $mail = pib_mail_kunde($aid, $erste);
    return ['ok' => true, 'msg' => 'PIB für ' . $a['nummer'] . ' freigegeben.' . ($mail === '' ? ' Der Kunde hat eine Mail mit dem PIB bekommen.' : ' Keine Mail: ' . $mail)];
}

// Sprache für Kundenmails: DE für DE/AT/CH/LI, sonst EN (es gibt kein Sprachfeld am Kunden – Land entscheidet).
function pib_kunde_sprache(int $kunde_id): string {
    $l = strtoupper((string) scalar("SELECT COALESCE(NULLIF(rechnung_land,''), land, 'DE') FROM kunden WHERE id=?", [$kunde_id]));
    return in_array($l, ['DE', 'AT', 'CH', 'LI', ''], true) ? 'de' : 'en';
}

// Mail nach der Freigabe: Auftrag bestätigt, PIB im Anhang, fehlt das Etikett: Bitte mit Upload-Link. Rückgabe '' = verschickt.
function pib_mail_kunde(int $aid, bool $erste = true): string {
    if (!function_exists('mail_senden')) require_once __DIR__ . '/mail.php';
    require_once __DIR__ . '/pdf_pib.php';
    $a = pib_auftrag($aid); if (!$a || !$a['kunde_id']) return 'Kunde fehlt.';
    $k = one("SELECT id, firma, ansprechpartner, email FROM kunden WHERE id=?", [(int)$a['kunde_id']]);
    if (!$k) return 'Kunde fehlt.';
    if (function_exists('kunde_mails_aus') && kunde_mails_aus((int)$k['id'])) return 'Benachrichtigungen für diesen Kunden sind ausgeschaltet.';
    if (trim((string)$k['email']) === '') return 'Keine E-Mail-Adresse beim Kunden.';
    $name = function_exists('produkt_kundenname') ? produkt_kundenname((int)$a['produkt_id'], (int)$a['kunde_id']) : '';
    $name = $name !== '' ? $name : (string)$a['produkt'];
    $GLOBALS['pib_stand'] = ['art' => 'frei', 'datum' => (string) scalar("SELECT pib_freigegeben_am FROM auftrag WHERE id=?", [$aid])];
    $pdf = pib_pdf_bauen((int)$a['produkt_id'], null, null, (int)$a['kunde_id']);
    unset($GLOBALS['pib_stand']);
    $fehltEtikett = function_exists('auftrag_braucht_etikett') && function_exists('etikett_vorhanden') && auftrag_braucht_etikett($aid) && !etikett_vorhanden($aid);
    $link = function_exists('mail_link_kundenportal') ? mail_link_kundenportal((int)$k['id'], 'bestellung&aid=' . $aid) : '';
    $en = pib_kunde_sprache((int)$k['id']) === 'en';
    if (!function_exists('beleg_firma')) require_once __DIR__ . '/pdf_beleg.php';
    $fa = beleg_firma();
    $anrede = function_exists('mail_kunde_anrede') ? mail_kunde_anrede($k) : (string)$k['firma'];
    if ($en) {
        $betreff = ($erste ? 'Order confirmed – ' : 'Updated product information sheet – ') . $a['nummer'];
        $text = 'Hello ' . $anrede . ",\n\n" . ($erste ? 'we confirm your order ' . $a['nummer'] . ' (' . $name . ').' : 'the product information sheet for your order ' . $a['nummer'] . ' (' . $name . ') has been updated.')
              . "\nPlease find the checked product information sheet (PIB) attached." . ($fehltEtikett ? "\n\nWe have not received a label for this order yet. Without a label we cannot complete production. Please upload your label here:\n" . $link : '')
              . ($link !== '' && !$fehltEtikett ? "\n\nCustomer portal:\n" . $link : '') . "\n\nBest regards\n" . $fa['name'];
    } else {
        $betreff = ($erste ? 'Auftrag bestätigt – ' : 'Aktualisiertes Produktinformationsblatt – ') . $a['nummer'];
        $text = 'Guten Tag ' . $anrede . ",\n\n" . ($erste ? 'wir bestätigen Ihren Auftrag ' . $a['nummer'] . ' (' . $name . ').' : 'das Produktinformationsblatt zu Ihrem Auftrag ' . $a['nummer'] . ' (' . $name . ') wurde aktualisiert.')
              . "\nIm Anhang finden Sie das geprüfte Produktinformationsblatt (PIB)." . ($fehltEtikett ? "\n\nFür diesen Auftrag liegt uns noch kein Etikett vor. Ohne Etikett können wir die Produktion nicht abschließen. Bitte laden Sie Ihr Etikett hier hoch:\n" . $link : '')
              . ($link !== '' && !$fehltEtikett ? "\n\nIm Kundenportal:\n" . $link : '') . "\n\nViele Grüße\n" . $fa['name'];
    }
    $anh = $pdf ? [['name' => 'Produktinformationsblatt_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $a['nummer']) . '.pdf', 'daten' => function_exists('kunde_pdf_neutral') ? kunde_pdf_neutral($pdf) : $pdf, 'typ' => 'application/pdf']] : [];
    $r = mail_senden((string)$k['email'], $betreff, $text, null, $anh);
    if ($r === '') {
        q("UPDATE auftrag SET pib_mail_am=COALESCE(pib_mail_am, ?) WHERE id=?", [gmdate('Y-m-d H:i:s'), $aid]);
        if ($fehltEtikett && function_exists('etikett_erinnerung_vermerken')) etikett_erinnerung_vermerken($aid, true);
        if (function_exists('log_aktivitaet')) log_aktivitaet('auftrag', $aid, 'system', 'Mail an den Kunden: ' . $betreff . ' (PIB im Anhang' . ($fehltEtikett ? ', Bitte um Etikett' : '') . ')', 'mail');
    }
    return $r;
}

// Datum unten auf dem PIB (Teil 3.5) – wird von pib_pdf_render() gelesen. Setzt der Aufrufer $GLOBALS['pib_stand'] nicht,
// gilt: Auftrag frei -> „Freigegeben am“, Altauftrag -> „Stand“ = Auftragsbestätigung, sonst „Entwurf – nicht freigegeben“.
function pib_stand_fuer_auftrag(int $aid): array {
    $a = pib_auftrag($aid);
    if (!$a) return ['art' => 'entwurf', 'datum' => ''];
    if ($a['pib_pruefung'] === 'frei' && $a['pib_freigegeben_am']) return ['art' => 'frei', 'datum' => (string)$a['pib_freigegeben_am']];
    if ($a['pib_pruefung'] === null) return ['art' => 'alt', 'datum' => (string)$a['angelegt']];
    return ['art' => 'entwurf', 'datum' => ''];
}

// Portal: ausgegrauter PIB-Knopf mit Schloss und „PIB wird geprüft“ (Teil 3.3). Kein Link – der Server sperrt zusätzlich.
function pib_gesperrt_html(string $lbl = 'Produktinfo (PIB)', string $stil = ''): string {
    $schloss = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>';
    return '<span class="pib-gesperrt" style="display:inline-flex;flex-direction:column;align-items:center;gap:2px;' . $stil . '" title="Das Produktinformationsblatt wird vor der Freigabe vom bulkify-Team geprüft.">'
         . '<span class="btn btn-ghost btn-sm" aria-disabled="true" style="opacity:.55;cursor:not-allowed;display:inline-flex;gap:6px;align-items:center">' . $schloss . htmlspecialchars($lbl) . '</span>'
         . '<span class="muted" style="font-size:11px">PIB wird geprüft</span></span>';
}
