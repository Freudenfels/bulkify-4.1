<?php
// CRM-Demo – eigenständige App (Route ?p=crmdemo&m=<modul>). Nur crmdemo_*-Tabellen (isoliert).
// Module: dashboard | kunden | katalog | produktentwickler | angebote | rechnungen | produktion | chat | finanzen | firma.
// Rollen (Demo, oben umschaltbar): Verkauf · Pricing/Sourcing · Produktion · Buchhaltung · Admin.
// Ansicht-only: Belege werden als DIN-A4 am Bildschirm in Kundensprache gezeigt (kein Download).
require_once BX_ROOT . '/core/crmdemo.php';

crmdemo_schema();
crmdemo_seed();   // beim ersten Aufruf Beispieldaten (nur wenn leer)

$m   = preg_replace('/[^a-z_]/', '', (string)($_GET['m'] ?? 'dashboard')) ?: 'dashboard';
$akt = $_POST['aktion'] ?? '';
$rolle = cd_rolle();
$cent = fn($s) => (int) round((float) str_replace(',', '.', (string)$s) * 100);
$mnf  = fn($v) => rtrim(rtrim(number_format((float)$v, 2, ',', '.'), '0'), ',');

// Netto eines Angebots aus seinen Positionen neu berechnen.
$angebot_netto = function (int $aid): int {
    $s = 0;
    foreach (all("SELECT menge,preis_cent FROM crmdemo_angebot_pos WHERE angebot_id=?", [$aid]) as $p)
        $s += (int) round((float)$p['menge'] * (int)$p['preis_cent']);
    q("UPDATE crmdemo_angebot SET netto_cent=? WHERE id=?", [$s, $aid]);
    return $s;
};

// ---------------- POST-Handler (vor jeder Ausgabe) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- Kunden ---
    if ($akt === 'kunde_save') {
        $id = (int)($_POST['id'] ?? 0);
        $f = fn($n) => trim((string)($_POST[$n] ?? ''));
        $spr = in_array($f('sprache'), ['de','en','zh'], true) ? $f('sprache') : 'de';
        $wae = array_key_exists($f('waehrung'), cd_waehrungen()) ? $f('waehrung') : 'EUR';
        $zz = $f('zahlungsziel') !== '' ? (int)$f('zahlungsziel') : null;
        $cols = [$f('firma') ?: '(ohne Namen)', $f('ansprechpartner') ?: null, $f('email') ?: null, $f('telefon') ?: null,
                 $f('land') ?: null, $spr, $wae, $f('adresse') ?: null, $f('plz') ?: null, $f('ort') ?: null,
                 $f('ust_id') ?: null, $f('website') ?: null, $f('wechat') ?: null, $f('segment') ?: null,
                 $f('kundennummer') ?: null, $f('betreuer') ?: null, $zz, $f('liefer_adresse') ?: null, $f('branche') ?: null,
                 $f('notiz') ?: null];
        $spalten = "firma=?,ansprechpartner=?,email=?,telefon=?,land=?,sprache=?,waehrung=?,adresse=?,plz=?,ort=?,ust_id=?,website=?,wechat=?,segment=?,kundennummer=?,betreuer=?,zahlungsziel=?,liefer_adresse=?,branche=?,notiz=?";
        if ($id) {
            q("UPDATE crmdemo_kunde SET $spalten WHERE id=?", array_merge($cols, [$id]));
            header('Location: ' . cd_url('kunden', ['id' => $id])); exit;
        }
        q("INSERT INTO crmdemo_kunde (firma,ansprechpartner,email,telefon,land,sprache,waehrung,adresse,plz,ort,ust_id,website,wechat,segment,kundennummer,betreuer,zahlungsziel,liefer_adresse,branche,notiz) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", $cols);
        header('Location: ' . cd_url('kunden')); exit;
    }
    if ($akt === 'kunde_del') { $id=(int)($_POST['id']??0); q("DELETE FROM crmdemo_kunde WHERE id=?", [$id]); q("DELETE FROM crmdemo_mail WHERE kunde_id=?", [$id]); header('Location: ' . cd_url('kunden')); exit; }
    if ($akt === 'mail_add') {
        $kid=(int)($_POST['kunde_id']??0); $ri=($_POST['richtung']??'ein')==='aus'?'aus':'ein';
        q("INSERT INTO crmdemo_mail (kunde_id,richtung,betreff,text) VALUES (?,?,?,?)", [$kid,$ri,trim($_POST['betreff']??'') ?: '(ohne Betreff)',trim($_POST['text']??'') ?: null]);
        header('Location: ' . cd_url('kunden', ['id'=>$kid])); exit;
    }

    // --- Rohstoff-Katalog ---
    if ($akt === 'rohstoff_save') {
        $f = fn($n) => trim((string)($_POST[$n] ?? ''));
        q("INSERT INTO crmdemo_rohstoff (name,kategorie,wirkstoff,gehalt,form,herkunft,cas,moq_kg,notiz) VALUES (?,?,?,?,?,?,?,?,?)",
          [$f('name') ?: '(Rohstoff)', $f('kategorie') ?: null, $f('wirkstoff') ?: null, $f('gehalt') ?: null, $f('form') ?: null,
           $f('herkunft') ?: null, $f('cas') ?: null, $f('moq_kg') !== '' ? (float)str_replace(',','.',$f('moq_kg')) : null, $f('notiz') ?: null]);
        header('Location: ' . cd_url('katalog', ['id'=>insert_id()])); exit;
    }
    if ($akt === 'rohstoff_del') { $id=(int)($_POST['id']??0); q("DELETE FROM crmdemo_rohstoff WHERE id=?", [$id]); q("DELETE FROM crmdemo_rohstoff_preis WHERE rohstoff_id=?", [$id]); header('Location: ' . cd_url('katalog')); exit; }
    if ($akt === 'rohpreis_add') {
        $rid=(int)($_POST['rohstoff_id']??0);
        $wae=array_key_exists($_POST['waehrung']??'',cd_waehrungen())?$_POST['waehrung']:'EUR';
        q("INSERT INTO crmdemo_rohstoff_preis (rohstoff_id,datum,preis_cent,waehrung,lieferant) VALUES (?,?,?,?,?)",
          [$rid, ($_POST['datum']??'') ?: date('Y-m-d'), $cent($_POST['preis']??'0'), $wae, trim($_POST['lieferant']??'') ?: null]);
        header('Location: ' . cd_url('katalog', ['id'=>$rid])); exit;
    }

    // --- KI-Produktentwickler ---
    if ($akt === 'produkt_ki') {
        require_once BX_ROOT . '/core/ki.php';
        $name = trim($_POST['name'] ?? ''); $form = trim($_POST['form'] ?? 'kapsel'); $idee = trim($_POST['idee'] ?? '');
        $konzept = null;
        if (function_exists('ki_bereit') && ki_bereit()) {
            $sysLang = ['de'=>'Deutsch','en'=>'Englisch','zh'=>'Chinesisch'][cd_lang()] ?? 'Deutsch';
            $r = ki_json('Produktidee: ' . $idee . '. Darreichungsform: ' . $form . '. Erzeuge ein kompaktes Produktkonzept für ein Nahrungsergänzungsmittel.',
                ['system' => 'Du bist Produktentwickler für Nahrungsergänzungsmittel. Antworte auf ' . $sysLang . '. Gib NUR JSON: {"name":"","kurzbeschreibung":"","zutaten":[{"name":"","menge_mg":0}],"hinweise":""}. Erfinde keine unzulässigen Heilaussagen.']);
            if (!empty($r['ok']) && !empty($r['daten'])) $konzept = json_encode($r['daten'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        q("INSERT INTO crmdemo_produkt (name,form,idee,konzept) VALUES (?,?,?,?)", [$name ?: ($idee !== '' ? mb_substr($idee, 0, 60) : '(Idee)'), $form, $idee ?: null, $konzept]);
        header('Location: ' . cd_url('produktentwickler')); exit;
    }
    if ($akt === 'produkt_del') { q("DELETE FROM crmdemo_produkt WHERE id=?", [(int)($_POST['id'] ?? 0)]); header('Location: ' . cd_url('produktentwickler')); exit; }

    // --- Rezeptur-Katalog ---
    if ($akt === 'rezeptur_save') {
        $f = fn($n) => trim((string)($_POST[$n] ?? ''));
        // Zutaten: aus Rohstoff+mg-Zeilen (z_name[]/z_mg[]), sonst JSON (KI-Konzept), sonst Freitext.
        $zut = '';
        if (!empty($_POST['z_name']) && is_array($_POST['z_name'])) {
            $arr = [];
            foreach ($_POST['z_name'] as $i => $zn) {
                $zn = trim((string)$zn); if ($zn === '') continue;
                $mg = trim((string)($_POST['z_mg'][$i] ?? ''));
                $row = ['name' => $zn];
                if ($mg !== '') $row['menge_mg'] = (float) str_replace(',', '.', $mg);
                $arr[] = $row;
            }
            $zut = $arr ? json_encode($arr, JSON_UNESCAPED_UNICODE) : '';
        }
        if ($zut === '') $zut = $f('zutaten_json');
        if ($zut === '' && $f('zutaten') !== '') {
            $arr = [];
            foreach (preg_split('/\r?\n/', $f('zutaten')) as $z) { $z = trim($z); if ($z !== '') $arr[] = ['name'=>$z]; }
            $zut = $arr ? json_encode($arr, JSON_UNESCAPED_UNICODE) : null;
        }
        q("INSERT INTO crmdemo_rezeptur (name,form,kategorie,beschreibung,zutaten,erstellt_von,verwendet) VALUES (?,?,?,?,?,?,0)",
          [$f('name') ?: '(Rezeptur)', $f('form') ?: 'kapsel', $f('kategorie') ?: null, $f('beschreibung') ?: null, $zut ?: null, $rolle]);
        header('Location: ' . cd_url('rezepturen', ['id'=>insert_id()])); exit;
    }
    if ($akt === 'rezeptur_del') { q("DELETE FROM crmdemo_rezeptur WHERE id=?", [(int)($_POST['id'] ?? 0)]); q("UPDATE crmdemo_angebot_pos SET rezeptur_id=NULL WHERE rezeptur_id=?", [(int)($_POST['id'] ?? 0)]); header('Location: ' . cd_url('rezepturen')); exit; }

    // --- Angebote + Pricing-Workflow ---
    if ($akt === 'angebot_save') {
        $kid = (int)($_POST['kunde_id'] ?? 0) ?: null;
        $wae = $kid ? (string) scalar("SELECT waehrung FROM crmdemo_kunde WHERE id=?", [$kid]) : 'EUR';
        if (!array_key_exists($wae, cd_waehrungen())) $wae = 'EUR';
        q("INSERT INTO crmdemo_angebot (nummer,kunde_id,titel,waehrung,netto_cent,status) VALUES (?,?,?,?,0,'entwurf')",
          [cd_nummer('AN'), $kid, trim($_POST['titel'] ?? '') ?: 'Angebot', $wae]);
        $aid = insert_id(); $sort = 0;
        foreach ((array)($_POST['p_bez'] ?? []) as $i => $b) {
            $b = trim((string)$b); if ($b === '') continue;
            $mn = (float) str_replace(',', '.', (string)(($_POST['p_menge'][$i] ?? '1'))) ?: 1;
            $pc = $cent($_POST['p_preis'][$i] ?? '0');
            $typ = in_array($_POST['p_typ'][$i] ?? '', ['produkt','rohstoff','frei'], true) ? $_POST['p_typ'][$i] : 'produkt';
            $einheit = $typ === 'rohstoff' ? 'kg' : 'Stk.';
            $rez = (int)($_POST['p_rezid'][$i] ?? 0) ?: null;
            $roh = (int)($_POST['p_rohid'][$i] ?? 0) ?: null;
            if ($typ === 'produkt') {
                // Neue Position "in den Katalog aufnehmen"? -> Rezeptur anlegen und verknuepfen.
                if (!$rez && !empty($_POST['p_asrez'][$i])) {
                    q("INSERT INTO crmdemo_rezeptur (name,form,kategorie,erstellt_von,verwendet) VALUES (?,?,?,?,1)", [$b, 'kapsel', null, $rolle]);
                    $rez = insert_id();
                } elseif ($rez) {
                    q("UPDATE crmdemo_rezeptur SET verwendet = verwendet + 1 WHERE id=?", [$rez]);
                }
            } else { $rez = null; }
            if ($typ !== 'rohstoff') $roh = null;
            q("INSERT INTO crmdemo_angebot_pos (angebot_id,bezeichnung,menge,einheit,preis_cent,typ,rezeptur_id,rohstoff_id,sort) VALUES (?,?,?,?,?,?,?,?,?)", [$aid, $b, $mn, $einheit, $pc, $typ, $rez, $roh, $sort++]);
        }
        $angebot_netto($aid);
        header('Location: ' . cd_url('angebote', ['id' => $aid])); exit;
    }
    if ($akt === 'angebot_del') { $id=(int)($_POST['id']??0); q("DELETE FROM crmdemo_angebot_pos WHERE angebot_id=?", [$id]); q("DELETE FROM crmdemo_angebot WHERE id=?", [$id]); header('Location: ' . cd_url('angebote')); exit; }
    if ($akt === 'angebot_kalk_anfragen' && in_array($rolle,['verkauf','admin'],true)) {
        q("UPDATE crmdemo_angebot SET status='kalkulation' WHERE id=? AND status='entwurf'", [(int)($_POST['id']??0)]);
        header('Location: ' . cd_url('angebote', ['id'=>(int)($_POST['id']??0)])); exit;
    }
    if ($akt === 'angebot_kalk_speichern' && in_array($rolle,['pricing','admin'],true)) {
        $aid=(int)($_POST['id']??0);
        foreach ((array)($_POST['pos_id'] ?? []) as $i=>$pid) {
            $pid=(int)$pid; $pc=$cent($_POST['pos_preis'][$i] ?? '0');
            q("UPDATE crmdemo_angebot_pos SET preis_cent=? WHERE id=? AND angebot_id=?", [$pc,$pid,$aid]);
        }
        q("UPDATE crmdemo_angebot SET status='kalkuliert', kalk_notiz=? WHERE id=?", [trim($_POST['kalk_notiz']??'') ?: null, $aid]);
        $angebot_netto($aid);
        header('Location: ' . cd_url('angebote', ['id'=>$aid])); exit;
    }
    if ($akt === 'angebot_senden' && in_array($rolle,['verkauf','admin'],true)) {
        q("UPDATE crmdemo_angebot SET status='gesendet' WHERE id=? AND status='kalkuliert'", [(int)($_POST['id']??0)]);
        header('Location: ' . cd_url('angebote', ['id'=>(int)($_POST['id']??0)])); exit;
    }
    if ($akt === 'angebot_annehmen' && in_array($rolle,['verkauf','admin'],true)) {
        $a = one("SELECT * FROM crmdemo_angebot WHERE id=?", [(int)($_POST['id'] ?? 0)]);
        if ($a && $a['status'] === 'gesendet') {
            $ust = (float) cd_std('std_ust','19'); $netto = (int)$a['netto_cent']; $brutto = (int) round($netto * (1 + $ust / 100));
            q("INSERT INTO crmdemo_rechnung (nummer,kunde_id,angebot_id,waehrung,netto_cent,ust_prozent,brutto_cent,status,datum) VALUES (?,?,?,?,?,?,?, 'offen', CURDATE())",
              [cd_nummer('RE'), $a['kunde_id'], $a['id'], $a['waehrung'], $netto, $ust, $brutto]);
            q("UPDATE crmdemo_angebot SET status='angenommen' WHERE id=?", [(int)$a['id']]);
            header('Location: ' . cd_url('rechnungen')); exit;
        }
        header('Location: ' . cd_url('angebote', ['id'=>(int)($_POST['id']??0)])); exit;
    }

    // --- Rechnungen ---
    if ($akt === 'rechnung_status') { q("UPDATE crmdemo_rechnung SET status=? WHERE id=?", [($_POST['status'] ?? 'offen') === 'bezahlt' ? 'bezahlt' : 'offen', (int)($_POST['id'] ?? 0)]); header('Location: ' . cd_url('rechnungen')); exit; }

    // --- Produktion + Chargen ---
    if ($akt === 'produktion_save') {
        q("INSERT INTO crmdemo_produktion (kunde_id,produkt_id,titel,charge_nr,mhd,menge,status) VALUES (?,?,?,?,?,?, 'geplant')",
          [(int)($_POST['kunde_id'] ?? 0) ?: null, (int)($_POST['produkt_id'] ?? 0) ?: null, trim($_POST['titel'] ?? '') ?: 'Charge',
           cd_nummer('CH'), ($_POST['mhd']??'') ?: null, (int)($_POST['menge'] ?? 0)]);
        header('Location: ' . cd_url('produktion', ['id'=>insert_id()])); exit;
    }
    if ($akt === 'produktion_status') { $s=$_POST['status']??'geplant'; q("UPDATE crmdemo_produktion SET status=? WHERE id=?", [in_array($s,['geplant','in_produktion','fertig'],true)?$s:'geplant', (int)($_POST['id']??0)]); header('Location: ' . cd_url('produktion', ['id'=>(int)($_POST['id']??0)])); exit; }
    if ($akt === 'charge_zutat_add') {
        $pid=(int)($_POST['produktion_id']??0); $rid=(int)($_POST['rohstoff_id']??0) ?: null;
        $name = $rid ? (string) scalar("SELECT name FROM crmdemo_rohstoff WHERE id=?", [$rid]) : (trim($_POST['name']??'') ?: '(Rohstoff)');
        q("INSERT INTO crmdemo_charge_zutat (produktion_id,rohstoff_id,name,lot,menge_kg) VALUES (?,?,?,?,?)",
          [$pid,$rid,$name,trim($_POST['lot']??'') ?: null, ($_POST['menge_kg']??'')!=='' ? (float)str_replace(',','.',$_POST['menge_kg']) : null]);
        header('Location: ' . cd_url('produktion', ['id'=>$pid])); exit;
    }

    // --- KI-Rohstoff-Chat ---
    if ($akt === 'chat_frage') {
        require_once BX_ROOT . '/core/ki.php';
        $frage = trim($_POST['frage'] ?? '');
        $antwort = '';
        if ($frage !== '') {
            if (function_exists('ki_bereit') && ki_bereit()) {
                $sysLang = ['de'=>'Deutsch','en'=>'Englisch','zh'=>'Chinesisch'][cd_lang()] ?? 'Deutsch';
                $r = ki_frage($frage, ['system' => 'Du bist Sourcing-Experte für Rohstoffe in der Nahrungsergänzung (Extrakte, Vitamine, Mineralstoffe). Antworte kurz, sachlich und auf ' . $sysLang . '. Keine Heilaussagen.']);
                $antwort = is_string($r) ? $r : (string)($r['text'] ?? '');
            }
            if ($antwort === '') $antwort = cd_t('ki_nicht_bereit');
            q("INSERT INTO crmdemo_chat (rolle,frage,antwort) VALUES (?,?,?)", [$rolle, $frage, $antwort]);
        }
        header('Location: ' . cd_url('chat')); exit;
    }

    // --- KI-COA/Spec-Reader: Text auslesen, Rohstoff anlegen, Kunden-COA erzeugen ---
    if ($akt === 'coa_erstellen') {
        $text = trim($_POST['quelle'] ?? '');
        $ziel = in_array($_POST['zielsprache'] ?? '', ['de','en','zh'], true) ? $_POST['zielsprache'] : 'de';
        if ($text === '') { header('Location: ' . cd_url('coareader')); exit; }
        $ext = cd_coa_extract($text, $ziel);
        if (empty($ext['ok']) || empty($ext['daten'])) { header('Location: ' . cd_url('coareader', ['err'=>1])); exit; }
        $d = $ext['daten'];
        $name = trim((string)($d['produkt'] ?? '')) ?: (trim((string)($d['wirkstoff'] ?? '')) ?: 'Rohstoff');
        // Bestehenden Rohstoff finden, sonst neu anlegen.
        $rid = (int) scalar("SELECT id FROM crmdemo_rohstoff WHERE name=? OR (wirkstoff<>'' AND wirkstoff=?) LIMIT 1", [$name, (string)($d['wirkstoff'] ?? '')]);
        if (!$rid) {
            q("INSERT INTO crmdemo_rohstoff (name,kategorie,wirkstoff,gehalt,herkunft,notiz) VALUES (?,?,?,?,?,?)",
              [$name, (string)($d['kategorie'] ?? '') ?: null, (string)($d['wirkstoff'] ?? '') ?: null,
               (string)($d['gehalt'] ?? '') ?: null, (string)($d['herkunft'] ?? '') ?: null, 'Angelegt aus COA/Spec-Reader']);
            $rid = insert_id();
        }
        q("INSERT INTO crmdemo_coa (rohstoff_id,typ,sprache,produkt,charge,daten,quelle) VALUES (?,?,?,?,?,?,?)",
          [$rid, 'coa', $ziel, $name, (string)($d['charge'] ?? '') ?: null, json_encode($d, JSON_UNESCAPED_UNICODE), mb_substr($text,0,4000)]);
        header('Location: ' . cd_url('coareader', ['id'=>insert_id()])); exit;
    }
    if ($akt === 'coa_del') { q("DELETE FROM crmdemo_coa WHERE id=?", [(int)($_POST['id']??0)]); header('Location: ' . cd_url('coareader')); exit; }

    // --- Briefkopf / Firma (Absender + Logo) ---
    if ($akt === 'firma_save' && in_array($rolle,['admin'],true)) {
        foreach (['abs_name'=>'name','abs_adresse'=>'adresse','abs_ort'=>'ort','abs_land'=>'land','abs_kontakt'=>'kontakt','abs_ustid'=>'ustid'] as $mk=>$fn)
            cd_meta_set($mk, trim((string)($_POST[$fn] ?? '')));
        if (!empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
            $sz = (int)($_FILES['logo']['size'] ?? 0); $typ = (string)($_FILES['logo']['type'] ?? '');
            if ($sz > 0 && $sz <= 800*1024 && preg_match('~^image/(png|jpe?g|webp|gif)$~', $typ)) {
                $bin = file_get_contents($_FILES['logo']['tmp_name']);
                if ($bin !== false) cd_meta_set('logo_b64', 'data:' . $typ . ';base64,' . base64_encode($bin));
            }
        }
        if (($_POST['logo_entfernen']??'') === '1') cd_meta_set('logo_b64', '');
        header('Location: ' . cd_url('einstellungen', ['tab'=>'briefkopf'])); exit;
    }
    if ($akt === 'std_save' && in_array($rolle,['admin'],true)) {
        $w = array_key_exists($_POST['std_waehrung']??'', cd_waehrungen()) ? $_POST['std_waehrung'] : 'EUR';
        cd_meta_set('std_waehrung', $w);
        cd_meta_set('std_ust', (string)(float)str_replace(',','.',(string)($_POST['std_ust']??'19')));
        cd_meta_set('std_zahlungsziel', (string)(int)($_POST['std_zahlungsziel']??14));
        header('Location: ' . cd_url('einstellungen', ['tab'=>'standard'])); exit;
    }
}

// ---------------- Render ----------------
cd_head(cd_t($m));
cd_shell_start($m);
$eur = fn($c, $w='EUR') => cd_money((int)$c, $w);
cd_gate($m);

// Status-Badge fuer Angebote (Workflow) + Rechnung/Produktion.
$badgeA = function (string $s) {
    $map = ['entwurf'=>['s_entwurf','warn'],'kalkulation'=>['s_kalkulation','info'],'kalkuliert'=>['s_kalkuliert','info'],
            'gesendet'=>['s_gesendet','info'],'angenommen'=>['s_angenommen','ok'],'abgelehnt'=>['s_abgelehnt','warn']];
    [$k,$t] = $map[$s] ?? ['s_entwurf','warn'];
    return bx_badge(cd_t($k), $t);
};
$badgeR = fn($s) => bx_badge(cd_t($s==='bezahlt'?'bezahlt':'offen'), $s==='bezahlt'?'ok':'warn');
$badgeP = fn($s) => bx_badge(cd_t($s==='fertig'?'fertig':($s==='in_produktion'?'in_produktion':'geplant')), $s==='fertig'?'ok':($s==='in_produktion'?'info':'warn'));

// ================= DASHBOARD =================
if ($m === 'dashboard'):
    $z = crmdemo_kennzahlen(); ?>
    <h1 style="margin-bottom:4px"><?= h(cd_t('willkommen')) ?></h1>
    <p class="bx-sub"><?= h(cd_t('demo_hinweis')) ?> · <?= h(cd_t('rolle')) ?>: <?= h(cd_t('r_'.$rolle)) ?></p>
    <div class="bx-cards">
      <div class="bx-card"><div class="k"><?= h(cd_t('anzahl_kunden')) ?></div><div class="v"><?= (int)$z['kunden'] ?></div></div>
      <div class="bx-card"><div class="k"><?= h(cd_t('katalog')) ?></div><div class="v"><?= (int)$z['rohstoffe'] ?></div></div>
      <div class="bx-card"><div class="k"><?= h(cd_t('anzahl_angebote')) ?></div><div class="v"><?= (int)$z['angebote'] ?></div></div>
      <div class="bx-card"><div class="k"><?= h(cd_t('umsatz_offen')) ?></div><div class="v"><?= $eur($z['offen']) ?></div></div>
      <div class="bx-card"><div class="k"><?= h(cd_t('umsatz_bezahlt')) ?></div><div class="v"><?= $eur($z['bezahlt']) ?></div></div>
      <div class="bx-card"><div class="k"><?= h(cd_t('produktion')) ?></div><div class="v"><?= (int)$z['produktion'] ?></div></div>
    </div>
    <div class="bx-panel" style="margin-top:16px"><div class="bx-row" style="gap:10px;flex-wrap:wrap">
      <?php foreach (['kunden','katalog','angebote','chat'] as $mm) if (cd_darf($mm)): ?>
        <a class="btn btn-ghost" href="<?= h(cd_url($mm)) ?>"><?= h(cd_t($mm)) ?></a>
      <?php endif; ?>
    </div></div>

<?php // ================= KUNDEN (Liste + tiefes Profil + Postfach) =================
elseif ($m === 'kunden'):
    $kid = (int)($_GET['id'] ?? 0);
    if ($kid && ($k = one("SELECT * FROM crmdemo_kunde WHERE id=?", [$kid]))):
        $edit = ($_GET['edit'] ?? '') === '1'; ?>
      <div class="bx-row" style="justify-content:space-between;align-items:center">
        <h1 style="margin:0"><?= h($k['firma']) ?></h1>
        <div class="bx-row" style="gap:8px">
          <?php if (!$edit): ?><a class="btn btn-primary btn-sm" href="<?= h(cd_url('kunden', ['id'=>$kid,'edit'=>1])) ?>"><?= h(cd_t('bearbeiten')) ?></a><?php endif; ?>
          <a class="btn btn-ghost btn-sm" href="<?= h(cd_url('kunden')) ?>">← <?= h(cd_t('kunden')) ?></a>
        </div>
      </div>
      <p class="bx-sub"><?= $k['kundennummer']?h((string)$k['kundennummer']).' · ':'' ?><?= h((string)$k['segment']) ?> · <?= h(strtoupper((string)$k['sprache'])) ?> · <?= h((string)$k['waehrung']) ?><?= $k['land']?' · '.h((string)$k['land']):'' ?><?= $k['betreuer']?' · '.h(cd_t('betreuer')).': '.h((string)$k['betreuer']):'' ?></p>

      <?php if (!$edit): ?>
      <div class="bx-panel"><h2 style="margin-top:0"><?= h(cd_t('stammdaten')) ?></h2>
        <div class="bx-grid">
          <div><label class="muted"><?= h(cd_t('ansprechpartner')) ?></label><div><?= h((string)$k['ansprechpartner']) ?: '–' ?></div></div>
          <div><label class="muted"><?= h(cd_t('email')) ?></label><div><?= h((string)$k['email']) ?: '–' ?></div></div>
          <div><label class="muted"><?= h(cd_t('telefon')) ?></label><div><?= h((string)$k['telefon']) ?: '–' ?></div></div>
          <div><label class="muted"><?= h(cd_t('wechat')) ?></label><div><?= h((string)$k['wechat']) ?: '–' ?></div></div>
          <div><label class="muted"><?= h(cd_t('branche')) ?></label><div><?= h((string)$k['branche']) ?: '–' ?></div></div>
          <div><label class="muted"><?= h(cd_t('betreuer')) ?></label><div><?= h((string)$k['betreuer']) ?: '–' ?></div></div>
          <div><label class="muted"><?= h(cd_t('adresse')) ?></label><div><?= h(trim(($k['adresse']?:'').' '.($k['plz']?:'').' '.($k['ort']?:''))) ?: '–' ?></div></div>
          <div><label class="muted"><?= h(cd_t('liefer_adresse')) ?></label><div><?= h((string)$k['liefer_adresse']) ?: '–' ?></div></div>
          <div><label class="muted"><?= h(cd_t('zahlungsziel')) ?></label><div><?= $k['zahlungsziel']!==null ? (int)$k['zahlungsziel'].' d' : '–' ?></div></div>
          <div><label class="muted"><?= h(cd_t('ust_id')) ?></label><div><?= h((string)$k['ust_id']) ?: '–' ?></div></div>
          <div><label class="muted"><?= h(cd_t('website')) ?></label><div><?= h((string)$k['website']) ?: '–' ?></div></div>
        </div>
        <?php if ($k['notiz']): ?><p style="margin-top:10px"><?= nl2br(h((string)$k['notiz'])) ?></p><?php endif; ?>
        <div class="bx-row" style="gap:8px;margin-top:12px">
          <a class="btn btn-primary btn-sm" href="<?= h(cd_url('kunden', ['id'=>$kid,'edit'=>1])) ?>"><?= h(cd_t('bearbeiten')) ?></a>
          <form method="post" style="margin:0" onsubmit="return confirm('<?= h(cd_t('loeschen')) ?>?')"><input type="hidden" name="aktion" value="kunde_del"><input type="hidden" name="id" value="<?= $kid ?>"><button class="btn btn-ghost btn-sm" type="submit"><?= h(cd_t('loeschen')) ?></button></form>
        </div>
      </div>

      <div class="bx-panel"><h2 style="margin-top:0"><?= h(cd_t('verlauf')) ?></h2>
        <div class="bx-tablewrap"><table class="bx-table"><thead><tr><th><?= h(cd_t('datum')) ?></th><th><?= h(cd_t('position')) ?></th><th class="bx-num"><?= h(cd_t('summe')) ?></th><th><?= h(cd_t('status')) ?></th></tr></thead><tbody>
        <?php
          $rows = [];
          foreach (all("SELECT angelegt,nummer,titel,netto_cent,waehrung,status FROM crmdemo_angebot WHERE kunde_id=?", [$kid]) as $r)
              $rows[] = [$r['angelegt'], cd_t('angebote').' '.$r['nummer'].' · '.$r['titel'], $eur($r['netto_cent'],$r['waehrung']), $badgeA($r['status'])];
          foreach (all("SELECT angelegt,nummer,brutto_cent,waehrung,status FROM crmdemo_rechnung WHERE kunde_id=?", [$kid]) as $r)
              $rows[] = [$r['angelegt'], cd_t('rechnungen').' '.$r['nummer'], $eur($r['brutto_cent'],$r['waehrung']), $badgeR($r['status'])];
          foreach (all("SELECT angelegt,titel,charge_nr,menge,status FROM crmdemo_produktion WHERE kunde_id=?", [$kid]) as $r)
              $rows[] = [$r['angelegt'], cd_t('produktion').' '.$r['charge_nr'].' · '.$r['titel'], (int)$r['menge'], $badgeP($r['status'])];
          usort($rows, fn($a,$b)=>strcmp((string)$b[0],(string)$a[0]));
          if (!$rows): ?><tr><td colspan="4" class="muted"><?= h(cd_t('keine_daten')) ?></td></tr><?php endif;
          foreach ($rows as $r): ?><tr><td class="muted"><?= h(substr((string)$r[0],0,10)) ?></td><td><?= h((string)$r[1]) ?></td><td class="bx-num"><?= is_string($r[2])?$r[2]:h((string)$r[2]) ?></td><td><?= $r[3] ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
      </div>

      <div class="bx-panel"><h2 style="margin-top:0"><?= h(cd_t('postfach')) ?></h2>
        <?php $mails = all("SELECT * FROM crmdemo_mail WHERE kunde_id=? ORDER BY id DESC", [$kid]); if (!$mails): ?><p class="muted"><?= h(cd_t('keine_daten')) ?></p><?php endif;
        foreach ($mails as $ml): ?>
          <div style="border-left:3px solid <?= $ml['richtung']==='ein'?'var(--gruen,#2f8f5b)':'var(--line)' ?>;padding:4px 0 4px 10px;margin:8px 0">
            <div class="bx-row" style="justify-content:space-between"><strong><?= h((string)$ml['betreff']) ?></strong><span class="muted" style="font-size:12px"><?= $ml['richtung']==='ein'?'⟵ '.h(cd_t('kunde')):'⟶ '.h(cd_t('r_verkauf')) ?> · <?= h(substr((string)$ml['angelegt'],0,16)) ?></span></div>
            <?php if ($ml['text']): ?><div class="muted" style="font-size:13px;margin-top:2px"><?= nl2br(h((string)$ml['text'])) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
        <form method="post" style="margin-top:10px"><input type="hidden" name="aktion" value="mail_add"><input type="hidden" name="kunde_id" value="<?= $kid ?>">
          <div class="bx-grid">
            <div class="bx-field"><label><?= h(cd_t('betreff')) ?></label><input type="text" name="betreff"></div>
            <div class="bx-field"><label><?= h(cd_t('rolle')) ?></label><select name="richtung"><option value="ein"><?= h(cd_t('kunde')) ?> ⟶</option><option value="aus"><?= h(cd_t('r_verkauf')) ?> ⟶</option></select></div>
          </div>
          <div class="bx-field"><label><?= h(cd_t('notiz')) ?></label><textarea name="text" rows="2"></textarea></div>
          <button class="btn btn-ghost btn-sm" type="submit"><?= h(cd_t('senden')) ?></button>
        </form>
      </div>

      <?php else: /* Profil bearbeiten */ ?>
      <div class="bx-panel"><h2 style="margin-top:0"><?= h(cd_t('profil')) ?></h2>
        <?php cd_kunde_form($k, $mnf); ?>
      </div>
      <?php endif;

    else: /* Kundenliste */ ?>
      <h1 style="margin-bottom:12px"><?= h(cd_t('kunden')) ?></h1>
      <div class="bx-panel"><div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th><?= h(cd_t('firma_name')) ?></th><th><?= h(cd_t('ansprechpartner')) ?></th><th><?= h(cd_t('land')) ?></th><th><?= h(cd_t('sprache')) ?></th><th><?= h(cd_t('waehrung')) ?></th><th></th></tr></thead><tbody>
        <?php $ks = all("SELECT * FROM crmdemo_kunde ORDER BY firma"); if (!$ks): ?><tr><td colspan="6" class="muted"><?= h(cd_t('keine_daten')) ?></td></tr><?php endif;
        foreach ($ks as $k): ?>
          <tr><td><a href="<?= h(cd_url('kunden', ['id'=>(int)$k['id']])) ?>"><?= h($k['firma']) ?></a><?= $k['segment']?'<div class="muted" style="font-size:12px">'.h((string)$k['segment']).'</div>':'' ?></td>
            <td><?= h((string)$k['ansprechpartner']) ?></td><td><?= h((string)$k['land']) ?></td><td><?= h(strtoupper((string)$k['sprache'])) ?></td><td><?= h((string)$k['waehrung']) ?></td>
            <td style="text-align:right"><a class="btn btn-ghost btn-sm" href="<?= h(cd_url('kunden', ['id'=>(int)$k['id']])) ?>"><?= h(cd_t('oeffnen')) ?></a></td></tr>
        <?php endforeach; ?>
        </tbody></table></div></div>
      <div class="bx-panel"><h2 style="margin-top:0"><?= h(cd_t('neu')) ?></h2><?php cd_kunde_form(null, $mnf); ?></div>
    <?php endif;

// ================= KATALOG (Rohstoffe + KI-Ähnlichkeit + Preishistorie) =================
elseif ($m === 'katalog'):
    $rid = (int)($_GET['id'] ?? 0);
    if ($rid && ($r = one("SELECT * FROM crmdemo_rohstoff WHERE id=?", [$rid]))): ?>
      <div class="bx-row" style="justify-content:space-between;align-items:center">
        <h1 style="margin:0"><?= h($r['name']) ?></h1>
        <a class="btn btn-ghost btn-sm" href="<?= h(cd_url('katalog')) ?>">← <?= h(cd_t('katalog')) ?></a>
      </div>
      <p class="bx-sub"><?= h((string)$r['kategorie']) ?><?= $r['wirkstoff']?' · '.h((string)$r['wirkstoff']):'' ?><?= $r['gehalt']?' · '.h((string)$r['gehalt']):'' ?></p>
      <div class="bx-panel"><div class="bx-grid">
        <div><label class="muted"><?= h(cd_t('herkunft')) ?></label><div><?= h((string)$r['herkunft']) ?: '–' ?></div></div>
        <div><label class="muted"><?= h(cd_t('form')) ?></label><div><?= h((string)$r['form']) ?: '–' ?></div></div>
        <div><label class="muted"><?= h(cd_t('moq')) ?></label><div><?= $r['moq_kg']!==null?$mnf($r['moq_kg']):'–' ?></div></div>
        <div><label class="muted">CAS</label><div><?= h((string)$r['cas']) ?: '–' ?></div></div>
      </div><?php if ($r['notiz']): ?><p style="margin-top:8px"><?= nl2br(h((string)$r['notiz'])) ?></p><?php endif; ?></div>

      <div class="bx-panel"><h2 style="margin-top:0"><?= h(cd_t('preishistorie')) ?></h2>
        <div class="bx-tablewrap"><table class="bx-table"><thead><tr><th><?= h(cd_t('datum')) ?></th><th class="bx-num"><?= h(cd_t('preis_kg')) ?></th><th>Lieferant</th></tr></thead><tbody>
        <?php $ph = all("SELECT * FROM crmdemo_rohstoff_preis WHERE rohstoff_id=? ORDER BY datum DESC,id DESC", [$rid]); if (!$ph): ?><tr><td colspan="3" class="muted"><?= h(cd_t('keine_daten')) ?></td></tr><?php endif;
        foreach ($ph as $p): ?><tr><td><?= h((string)$p['datum']) ?></td><td class="bx-num"><?= $eur($p['preis_cent'],$p['waehrung']) ?></td><td><?= h((string)$p['lieferant']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php if (in_array($rolle,['pricing','admin'],true)): ?>
        <form method="post" style="margin-top:10px"><input type="hidden" name="aktion" value="rohpreis_add"><input type="hidden" name="rohstoff_id" value="<?= $rid ?>">
          <div class="bx-grid">
            <div class="bx-field"><label><?= h(cd_t('datum')) ?></label><input type="date" name="datum" value="<?= date('Y-m-d') ?>"></div>
            <div class="bx-field"><label><?= h(cd_t('preis_kg')) ?></label><input type="number" step="0.01" name="preis"></div>
            <div class="bx-field"><label><?= h(cd_t('waehrung')) ?></label><select name="waehrung"><?php foreach (cd_waehrungen() as $w=>$s): ?><option value="<?= $w ?>"><?= $w ?></option><?php endforeach; ?></select></div>
            <div class="bx-field"><label>Lieferant</label><input type="text" name="lieferant"></div>
          </div>
          <button class="btn btn-ghost btn-sm" type="submit" style="margin-top:8px"><?= h(cd_t('preis_erfassen')) ?></button>
        </form>
        <?php endif; ?>
      </div>
      <div class="bx-panel"><form method="post" onsubmit="return confirm('<?= h(cd_t('loeschen')) ?>?')" style="margin:0"><input type="hidden" name="aktion" value="rohstoff_del"><input type="hidden" name="id" value="<?= $rid ?>"><button class="btn btn-ghost btn-sm" type="submit"><?= h(cd_t('loeschen')) ?></button></form></div>

    <?php else:
      $anf = trim((string)($_GET['q'] ?? ''));
      $roh = all("SELECT * FROM crmdemo_rohstoff WHERE aktiv=1 ORDER BY name"); ?>
      <h1 style="margin-bottom:6px"><?= h(cd_t('katalog')) ?></h1>
      <div class="bx-panel"><h2 style="margin-top:0"><?= h(cd_t('matching')) ?></h2>
        <p class="muted" style="margin-top:0"><?= h(cd_t('matching_hint')) ?></p>
        <form method="get" class="bx-row" style="gap:8px;flex-wrap:wrap"><input type="hidden" name="p" value="crmdemo"><input type="hidden" name="m" value="katalog">
          <input type="text" name="q" value="<?= h($anf) ?>" placeholder="Ashwagandha 350 mg 5%" style="min-width:280px">
          <button class="btn btn-primary" type="submit"><?= h(cd_t('suchen')) ?></button>
        </form>
        <?php if ($anf !== ''):
          $scored = [];
          foreach ($roh as $x) { $s = cd_similarity($anf, $x); if ($s > 0.05) $scored[] = [$s, $x]; }
          usort($scored, fn($a,$b)=>$b[0]<=>$a[0]); ?>
          <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table"><thead><tr><th><?= h(cd_t('name')) ?></th><th><?= h(cd_t('gehalt')) ?></th><th style="width:180px"><?= h(cd_t('treffer')) ?></th></tr></thead><tbody>
          <?php if (!$scored): ?><tr><td colspan="3" class="muted"><?= h(cd_t('keine_daten')) ?></td></tr><?php endif;
          foreach (array_slice($scored,0,5) as [$s,$x]): $pct=round($s*100); ?>
            <tr><td><a href="<?= h(cd_url('katalog',['id'=>(int)$x['id']])) ?>"><?= h($x['name']) ?></a></td><td class="muted"><?= h((string)$x['gehalt']) ?></td>
              <td><div class="cd-match"><span style="width:<?= $pct ?>%"></span></div><span class="muted" style="font-size:12px"><?= $pct ?>%</span></td></tr>
          <?php endforeach; ?>
          </tbody></table></div>
        <?php endif; ?>
      </div>
      <div class="bx-panel"><div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th><?= h(cd_t('name')) ?></th><th><?= h(cd_t('kategorie')) ?></th><th><?= h(cd_t('gehalt')) ?></th><th><?= h(cd_t('herkunft')) ?></th><th class="bx-num"><?= h(cd_t('preis_kg')) ?></th></tr></thead><tbody>
        <?php if (!$roh): ?><tr><td colspan="5" class="muted"><?= h(cd_t('keine_daten')) ?></td></tr><?php endif;
        foreach ($roh as $x): $lp = one("SELECT preis_cent,waehrung FROM crmdemo_rohstoff_preis WHERE rohstoff_id=? ORDER BY datum DESC,id DESC LIMIT 1", [(int)$x['id']]); ?>
          <tr><td><a href="<?= h(cd_url('katalog',['id'=>(int)$x['id']])) ?>"><?= h($x['name']) ?></a></td><td><?= h((string)$x['kategorie']) ?></td><td class="muted"><?= h((string)$x['gehalt']) ?></td><td><?= h((string)$x['herkunft']) ?></td><td class="bx-num"><?= $lp?$eur($lp['preis_cent'],$lp['waehrung']):'–' ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div></div>
      <?php if (in_array($rolle,['pricing','admin','verkauf'],true)): ?>
      <div class="bx-panel"><h2 style="margin-top:0"><?= h(cd_t('neu')) ?></h2>
        <form method="post"><input type="hidden" name="aktion" value="rohstoff_save">
          <div class="bx-grid">
            <div class="bx-field"><label><?= h(cd_t('name')) ?></label><input type="text" name="name" required></div>
            <div class="bx-field"><label><?= h(cd_t('kategorie')) ?></label><input type="text" name="kategorie"></div>
            <div class="bx-field"><label><?= h(cd_t('wirkstoff')) ?></label><input type="text" name="wirkstoff"></div>
            <div class="bx-field"><label><?= h(cd_t('gehalt')) ?></label><input type="text" name="gehalt" placeholder="5% Withanolide"></div>
            <div class="bx-field"><label><?= h(cd_t('form')) ?></label><input type="text" name="form" placeholder="Pulver"></div>
            <div class="bx-field"><label><?= h(cd_t('herkunft')) ?></label><input type="text" name="herkunft" placeholder="IN"></div>
            <div class="bx-field"><label><?= h(cd_t('moq')) ?></label><input type="number" step="0.1" name="moq_kg"></div>
            <div class="bx-field"><label>CAS</label><input type="text" name="cas"></div>
          </div>
          <div class="bx-row" style="margin-top:10px"><button class="btn btn-primary" type="submit"><?= h(cd_t('anlegen')) ?></button></div>
        </form>
      </div>
      <?php endif; ?>
    <?php endif;

// ================= KI-PRODUKTENTWICKLER =================
elseif ($m === 'produktentwickler'):
    require_once BX_ROOT . '/core/ki.php';
    $kiBereit = function_exists('ki_bereit') && ki_bereit(); ?>
    <h1 style="margin-bottom:4px"><?= h(cd_t('produktentwickler')) ?></h1>
    <?php if (!$kiBereit): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:10px 14px"><?= h(cd_t('ki_nicht_bereit')) ?></div><?php endif; ?>
    <div class="bx-panel"><h2 style="margin-top:0"><?= h(cd_t('neu')) ?></h2>
      <form method="post"><input type="hidden" name="aktion" value="produkt_ki">
        <div class="bx-grid">
          <div class="bx-field"><label><?= h(cd_t('name')) ?></label><input type="text" name="name" placeholder="Immun Boost"></div>
          <div class="bx-field"><label><?= h(cd_t('form')) ?></label><select name="form"><?php foreach (['kapsel'=>'Kapsel','tablette'=>'Tablette','pulver'=>'Pulver','fluessig'=>'Flüssig'] as $kk=>$vv): ?><option value="<?= $kk ?>"><?= h($vv) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="bx-field"><label><?= h(cd_t('idee')) ?></label><textarea name="idee" rows="2" placeholder="Ziel, Wirkung, Zielgruppe …"></textarea></div>
        <div class="bx-row" style="margin-top:10px"><button class="btn btn-primary" type="submit" data-busy="…"><?= h(cd_t('entwickeln')) ?></button></div>
      </form>
    </div>
    <?php foreach (all("SELECT * FROM crmdemo_produkt ORDER BY id DESC") as $pr):
        $kz = $pr['konzept'] ? json_decode((string)$pr['konzept'], true) : null; ?>
    <div class="bx-panel"><div class="bx-row" style="justify-content:space-between;align-items:center">
        <h2 style="margin:0"><?= h($pr['name']) ?> <span class="muted" style="font-weight:400;font-size:13px"><?= h((string)$pr['form']) ?></span></h2>
        <form method="post" style="margin:0" onsubmit="return confirm('<?= h(cd_t('loeschen')) ?>?')"><input type="hidden" name="aktion" value="produkt_del"><input type="hidden" name="id" value="<?= (int)$pr['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">×</button></form></div>
      <?php if ($pr['idee']): ?><p class="muted" style="margin:4px 0"><?= h($pr['idee']) ?></p><?php endif; ?>
      <?php if (in_array($rolle,['verkauf','admin'],true)):
        $zjson = (is_array($kz) && !empty($kz['zutaten'])) ? json_encode($kz['zutaten'], JSON_UNESCAPED_UNICODE) : ''; ?>
        <form method="post" style="margin:6px 0">
          <input type="hidden" name="aktion" value="rezeptur_save">
          <input type="hidden" name="name" value="<?= h((string)(is_array($kz)&&!empty($kz['name'])?$kz['name']:$pr['name'])) ?>">
          <input type="hidden" name="form" value="<?= h((string)$pr['form'] ?: 'kapsel') ?>">
          <input type="hidden" name="beschreibung" value="<?= h((string)(is_array($kz)?($kz['kurzbeschreibung'] ?? ''):'')) ?>">
          <input type="hidden" name="zutaten_json" value="<?= h($zjson) ?>">
          <button class="btn btn-ghost btn-sm" type="submit"><?= h(cd_t('in_katalog')) ?></button>
        </form>
      <?php endif; ?>
      <?php if (is_array($kz)): ?>
        <?php if (!empty($kz['kurzbeschreibung'])): ?><p style="margin:6px 0"><?= h($kz['kurzbeschreibung']) ?></p><?php endif; ?>
        <?php if (!empty($kz['zutaten']) && is_array($kz['zutaten'])): ?>
        <table class="bx-table"><thead><tr><th><?= h(cd_t('name')) ?></th><th class="bx-num">mg</th></tr></thead><tbody>
          <?php foreach ($kz['zutaten'] as $z): ?><tr><td><?= h((string)($z['name'] ?? '')) ?></td><td class="bx-num"><?= h((string)($z['menge_mg'] ?? '')) ?></td></tr><?php endforeach; ?>
        </tbody></table><?php endif; ?>
        <?php if (!empty($kz['hinweise'])): ?><p class="muted" style="font-size:12px;margin-top:6px"><?= h($kz['hinweise']) ?></p><?php endif; ?>
      <?php elseif ($pr['konzept']): ?><pre style="white-space:pre-wrap;font-size:12px"><?= h((string)$pr['konzept']) ?></pre><?php endif; ?>
    </div>
    <?php endforeach;

// ================= KI-COA/SPEC-READER =================
elseif ($m === 'coareader'):
    require_once BX_ROOT . '/core/ki.php';
    $kiBereit = function_exists('ki_bereit') && ki_bereit();
    $cid = (int)($_GET['id'] ?? 0);
    if ($cid && ($c = one("SELECT c.*, r.name AS rname FROM crmdemo_coa c LEFT JOIN crmdemo_rohstoff r ON r.id=c.rohstoff_id WHERE c.id=?", [$cid]))):
        $d = $c['daten'] ? json_decode((string)$c['daten'], true) : [];
        $lang = in_array($c['sprache'], ['de','en','zh'], true) ? $c['sprache'] : 'de';
        $abs = cd_absender(); $logo = cd_logo_datauri();
        $an = (isset($d['analytik']) && is_array($d['analytik'])) ? $d['analytik'] : []; ?>
      <div class="bx-row" style="justify-content:space-between;align-items:center;margin-bottom:10px">
        <a class="btn btn-ghost btn-sm" href="<?= h(cd_url('coareader')) ?>">← <?= h(cd_t('coareader')) ?></a>
        <span class="muted" style="font-size:12px"><?= h(cd_tl('ansicht_hinweis',$lang)) ?></span>
      </div>
      <div class="cd-a4">
        <table style="margin-bottom:20px"><tr>
          <td style="vertical-align:top"><?php if ($logo): ?><img src="<?= h($logo) ?>" alt="" style="max-height:50px;max-width:200px"><?php else: ?><strong><?= h($abs['name']) ?></strong><?php endif; ?></td>
          <td style="text-align:right;vertical-align:top"><h1><?= h(cd_tl('coa',$lang)) ?></h1></td>
        </tr></table>
        <table style="margin-bottom:14px;font-size:13px"><tr>
          <td style="vertical-align:top"><strong><?= h((string)$c['produkt']) ?></strong><?php if (!empty($d['wirkstoff'])): ?><br><?= h((string)$d['wirkstoff']) ?><?php endif; ?><?php if (!empty($d['gehalt'])): ?><br><?= h((string)$d['gehalt']) ?><?php endif; ?></td>
          <td style="text-align:right;vertical-align:top"><?php if ($c['charge']): ?><?= h(cd_tl('charge',$lang)) ?>: <strong><?= h((string)$c['charge']) ?></strong><br><?php endif; ?><?= h(cd_tl('datum',$lang)) ?>: <?= h(substr((string)$c['angelegt'],0,10)) ?></td>
        </tr></table>
        <table><thead><tr class="cd-th">
          <th style="text-align:left"><?= h(cd_tl('parameter',$lang)) ?></th>
          <th style="text-align:left"><?= h(cd_tl('wert',$lang)) ?></th>
          <th style="text-align:left"><?= h(cd_tl('grenzwert',$lang)) ?></th>
          <th style="text-align:left"><?= h(cd_tl('methode',$lang)) ?></th></tr></thead><tbody>
          <?php if (!$an): ?><tr><td colspan="4" style="color:#888"><?= h(cd_tl('keine_daten',$lang)) ?></td></tr><?php endif;
          foreach ($an as $z): ?><tr style="border-bottom:1px solid #eee"><td><?= h((string)($z['parameter'] ?? '')) ?></td><td><?= h((string)($z['wert'] ?? '')) ?></td><td><?= h((string)($z['grenzwert'] ?? '')) ?></td><td><?= h((string)($z['methode'] ?? '')) ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <p style="margin-top:24px;font-size:12px;color:#555"><?= h($abs['name']) ?> · <?= h($abs['ort']) ?> · <?= h($abs['kontakt']) ?></p>
      </div>
      <div class="bx-panel" style="margin-top:12px"><form method="post" onsubmit="return confirm('<?= h(cd_t('loeschen')) ?>?')" style="margin:0"><input type="hidden" name="aktion" value="coa_del"><input type="hidden" name="id" value="<?= $cid ?>"><button class="btn btn-ghost btn-sm" type="submit"><?= h(cd_t('loeschen')) ?></button></form></div>

    <?php else: ?>
      <h1 style="margin-bottom:4px"><?= h(cd_t('coareader')) ?></h1>
      <?php if (!$kiBereit): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:10px 14px"><?= h(cd_t('ki_nicht_bereit')) ?></div><?php endif; ?>
      <?php if (($_GET['err'] ?? '') === '1'): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:10px 14px"><?= h(cd_t('ki_nicht_bereit')) ?></div><?php endif; ?>
      <div class="bx-panel">
        <p class="muted" style="margin-top:0"><?= h(cd_t('coa_intro')) ?></p>
        <form method="post"><input type="hidden" name="aktion" value="coa_erstellen">
          <div class="bx-field"><label><?= h(cd_t('quelle_text')) ?></label><textarea name="quelle" rows="7" placeholder="产品名称: 姜黄提取物&#10;批号: 20260115&#10;姜黄素含量: 95%&#10;重金属 铅: &lt;1 ppm ..."></textarea></div>
          <div class="bx-row" style="gap:12px;align-items:flex-end;flex-wrap:wrap">
            <div class="bx-field" style="margin:0"><label><?= h(cd_t('zielsprache')) ?></label><select name="zielsprache"><option value="de">Deutsch</option><option value="en">English</option></select></div>
            <button class="btn btn-primary" type="submit" data-busy="…"><?= h(cd_t('auslesen')) ?></button>
          </div>
        </form>
      </div>
      <div class="bx-panel"><h2 style="margin-top:0"><?= h(cd_t('coa_liste')) ?></h2>
        <div class="bx-tablewrap"><table class="bx-table"><thead><tr><th><?= h(cd_t('name')) ?></th><th><?= h(cd_t('charge')) ?></th><th><?= h(cd_t('sprache')) ?></th><th><?= h(cd_t('datum')) ?></th></tr></thead><tbody>
        <?php $coas = all("SELECT * FROM crmdemo_coa ORDER BY id DESC"); if (!$coas): ?><tr><td colspan="4" class="muted"><?= h(cd_t('keine_daten')) ?></td></tr><?php endif;
        foreach ($coas as $co): ?><tr><td><a href="<?= h(cd_url('coareader',['id'=>(int)$co['id']])) ?>"><?= h((string)$co['produkt']) ?></a></td><td><?= h((string)$co['charge']) ?></td><td><?= h(strtoupper((string)$co['sprache'])) ?></td><td class="muted"><?= h(substr((string)$co['angelegt'],0,10)) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
      </div>
    <?php endif;

// ================= REZEPTUR-KATALOG (geteilte, wachsende Bibliothek) =================
elseif ($m === 'rezepturen'):
    $rid = (int)($_GET['id'] ?? 0);
    if ($rid && ($rz = one("SELECT * FROM crmdemo_rezeptur WHERE id=?", [$rid]))):
        $zut = $rz['zutaten'] ? json_decode((string)$rz['zutaten'], true) : null; ?>
      <div class="bx-row" style="justify-content:space-between;align-items:center">
        <h1 style="margin:0"><?= h($rz['name']) ?> <span class="muted" style="font-size:14px"><?= h((string)$rz['form']) ?></span></h1>
        <a class="btn btn-ghost btn-sm" href="<?= h(cd_url('rezepturen')) ?>">← <?= h(cd_t('rezepturen')) ?></a>
      </div>
      <p class="bx-sub"><?= h((string)$rz['kategorie']) ?> · <?= h(cd_t('verwendet')) ?>: <?= (int)$rz['verwendet'] ?> · <?= h(cd_t('erstellt_von')) ?> <?= h(cd_t('r_'.($rz['erstellt_von']?:'admin'))) ?></p>
      <div class="bx-panel">
        <?php if ($rz['beschreibung']): ?><p style="margin-top:0"><?= nl2br(h((string)$rz['beschreibung'])) ?></p><?php endif; ?>
        <?php if (is_array($zut) && $zut): ?>
        <table class="bx-table"><thead><tr><th><?= h(cd_t('name')) ?></th><th class="bx-num">mg</th></tr></thead><tbody>
          <?php foreach ($zut as $z): ?><tr><td><?= h((string)($z['name'] ?? '')) ?></td><td class="bx-num"><?= isset($z['menge_mg'])?h((string)$z['menge_mg']):'' ?></td></tr><?php endforeach; ?>
        </tbody></table><?php endif; ?>
      </div>
      <div class="bx-panel"><h2 style="margin-top:0"><?= h(cd_t('vorgestellt_bei')) ?></h2>
        <?php $kd = all("SELECT DISTINCT k.firma FROM crmdemo_angebot_pos p JOIN crmdemo_angebot a ON a.id=p.angebot_id JOIN crmdemo_kunde k ON k.id=a.kunde_id WHERE p.rezeptur_id=? ORDER BY k.firma", [$rid]);
        if (!$kd): ?><p class="muted"><?= h(cd_t('keine_daten')) ?></p><?php else: ?>
          <div class="bx-row" style="gap:6px;flex-wrap:wrap"><?php foreach ($kd as $x): ?><?= bx_badge((string)$x['firma'], 'info') ?> <?php endforeach; ?></div>
        <?php endif; ?>
      </div>
      <div class="bx-panel"><form method="post" onsubmit="return confirm('<?= h(cd_t('loeschen')) ?>?')" style="margin:0"><input type="hidden" name="aktion" value="rezeptur_del"><input type="hidden" name="id" value="<?= $rid ?>"><button class="btn btn-ghost btn-sm" type="submit"><?= h(cd_t('loeschen')) ?></button></form></div>

    <?php else:
      $q = trim((string)($_GET['q'] ?? ''));
      if ($q !== '') {
          $like = '%' . $q . '%';
          $rows = all("SELECT * FROM crmdemo_rezeptur WHERE name LIKE ? OR kategorie LIKE ? OR zutaten LIKE ? ORDER BY verwendet DESC, name", [$like,$like,$like]);
      } else {
          $rows = all("SELECT * FROM crmdemo_rezeptur ORDER BY verwendet DESC, name");
      } ?>
      <h1 style="margin-bottom:6px"><?= h(cd_t('rezepturen')) ?></h1>
      <p class="bx-sub"><?= (int) scalar("SELECT COUNT(*) FROM crmdemo_rezeptur") ?> <?= h(cd_t('rezepturen')) ?></p>
      <div class="bx-panel">
        <form method="get" class="bx-row" style="gap:8px;flex-wrap:wrap"><input type="hidden" name="p" value="crmdemo"><input type="hidden" name="m" value="rezepturen">
          <input type="text" name="q" value="<?= h($q) ?>" placeholder="<?= h(cd_t('katalog_suche')) ?>" style="min-width:300px">
          <button class="btn btn-primary" type="submit"><?= h(cd_t('suchen')) ?></button>
        </form>
        <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table">
          <thead><tr><th><?= h(cd_t('name')) ?></th><th><?= h(cd_t('form')) ?></th><th><?= h(cd_t('kategorie')) ?></th><th class="bx-num"><?= h(cd_t('verwendet')) ?></th></tr></thead><tbody>
          <?php if (!$rows): ?><tr><td colspan="4" class="muted"><?= h(cd_t('keine_daten')) ?></td></tr><?php endif;
          foreach ($rows as $rz): ?><tr><td><a href="<?= h(cd_url('rezepturen',['id'=>(int)$rz['id']])) ?>"><?= h($rz['name']) ?></a></td><td><?= h((string)$rz['form']) ?></td><td class="muted"><?= h((string)$rz['kategorie']) ?></td><td class="bx-num"><?= (int)$rz['verwendet'] ?></td></tr><?php endforeach; ?>
          </tbody></table></div>
      </div>
      <?php if (in_array($rolle,['verkauf','pricing','admin'],true)): ?>
      <div class="bx-panel"><h2 style="margin-top:0"><?= h(cd_t('neu')) ?></h2>
        <form method="post"><input type="hidden" name="aktion" value="rezeptur_save">
          <div class="bx-grid">
            <div class="bx-field"><label><?= h(cd_t('name')) ?></label><input type="text" name="name" required></div>
            <div class="bx-field"><label><?= h(cd_t('form')) ?></label><select name="form"><?php foreach (['kapsel'=>'Kapsel','tablette'=>'Tablette','pulver'=>'Pulver','fluessig'=>'Flüssig'] as $kk=>$vv): ?><option value="<?= $kk ?>"><?= h($vv) ?></option><?php endforeach; ?></select></div>
            <div class="bx-field"><label><?= h(cd_t('kategorie')) ?></label><input type="text" name="kategorie"></div>
          </div>
          <div class="bx-field" style="margin-top:8px"><label><?= h(cd_t('beschreibung')) ?></label><input type="text" name="beschreibung"></div>
          <label style="display:block;margin:10px 0 6px"><?= h(cd_t('zutaten')) ?></label>
          <?php $rohL = all("SELECT name FROM crmdemo_rohstoff WHERE aktiv=1 ORDER BY name"); ?>
          <datalist id="rohnames"><?php foreach ($rohL as $ro): ?><option value="<?= h($ro['name']) ?>"><?php endforeach; ?></datalist>
          <div class="bx-tablewrap"><table class="bx-table"><thead><tr><th><?= h(cd_t('wirkstoff')) ?> / <?= h(cd_t('typ_rohstoff')) ?></th><th style="width:160px"><?= h(cd_t('mg_je_einheit')) ?></th></tr></thead>
            <tbody id="zutrows"><?php for ($i=0;$i<3;$i++): ?><tr><td><input type="text" name="z_name[]" list="rohnames"></td><td><input type="number" step="0.001" name="z_mg[]"></td></tr><?php endfor; ?></tbody></table></div>
          <button type="button" class="btn btn-ghost btn-sm" id="addzut"><?= h(cd_t('zutat_zeile')) ?></button>
          <div class="bx-row" style="margin-top:10px"><button class="btn btn-primary" type="submit"><?= h(cd_t('anlegen')) ?></button></div>
        </form>
        <script>document.getElementById('addzut').addEventListener('click',function(){var tb=document.getElementById('zutrows');var tr=document.createElement('tr');tr.innerHTML='<td><input type="text" name="z_name[]" list="rohnames"></td><td><input type="number" step="0.001" name="z_mg[]"></td>';tb.appendChild(tr);});</script>
      </div>
      <?php endif; ?>
    <?php endif;

// ================= ANGEBOTE (Liste + Detail mit Pricing-Workflow + A4) =================
elseif ($m === 'angebote'):
    $detail = (int)($_GET['id'] ?? 0);
    $a = $detail ? one("SELECT a.*, k.firma, k.sprache, k.adresse, k.plz, k.ort, k.land AS kland FROM crmdemo_angebot a LEFT JOIN crmdemo_kunde k ON k.id=a.kunde_id WHERE a.id=?", [$detail]) : null;
    if ($a && ($_GET['beleg'] ?? '') === '1'):
        cd_beleg_a4('angebot', $a, all("SELECT * FROM crmdemo_angebot_pos WHERE angebot_id=? ORDER BY sort,id", [$detail]), $eur);
    elseif ($a):
        $pos = all("SELECT * FROM crmdemo_angebot_pos WHERE angebot_id=? ORDER BY sort,id", [$detail]); ?>
      <div class="bx-row" style="justify-content:space-between;align-items:center">
        <h1 style="margin:0"><?= h((string)$a['titel']) ?> <span class="muted" style="font-size:14px"><?= h((string)$a['nummer']) ?></span></h1>
        <a class="btn btn-ghost btn-sm" href="<?= h(cd_url('angebote')) ?>">← <?= h(cd_t('angebote')) ?></a>
      </div>
      <p class="bx-sub"><?= h((string)($a['firma'] ?? '')) ?> · <?= $badgeA($a['status']) ?> · <?= h((string)$a['waehrung']) ?></p>

      <div class="bx-panel"><div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th><?= h(cd_t('position')) ?></th><th class="bx-num"><?= h(cd_t('menge')) ?></th><th class="bx-num"><?= h(cd_t('preis')) ?></th><th class="bx-num"><?= h(cd_t('summe')) ?></th></tr></thead><tbody>
        <?php foreach ($pos as $p): ?>
          <tr><td><?= h($p['bezeichnung']) ?><?= $p['typ']==='rohstoff'?' <span class="muted" style="font-size:11px">'.h(cd_t('typ_rohstoff')).'</span>':'' ?></td><td class="bx-num"><?= $mnf($p['menge']) ?> <span class="muted"><?= h((string)$p['einheit']) ?></span></td><td class="bx-num"><?= $eur($p['preis_cent'],$a['waehrung']) ?></td><td class="bx-num"><?= $eur((int) round((float)$p['menge']*(int)$p['preis_cent']),$a['waehrung']) ?></td></tr>
        <?php endforeach; ?>
          <tr style="font-weight:600"><td colspan="3"><?= h(cd_t('netto')) ?></td><td class="bx-num"><?= $eur($a['netto_cent'],$a['waehrung']) ?></td></tr>
        </tbody></table></div>
        <?php if ($a['kalk_notiz']): ?><p class="muted" style="font-size:12px;margin-top:8px"><?= h(cd_t('kalk_notiz')) ?>: <?= h((string)$a['kalk_notiz']) ?></p><?php endif; ?>
      </div>

      <?php // ---- Workflow-Aktionen je Rolle/Status ----
      $st = $a['status']; ?>
      <div class="bx-panel"><h2 style="margin-top:0">Workflow</h2><div class="bx-row" style="gap:10px;flex-wrap:wrap;align-items:center">
        <a class="btn btn-ghost" href="<?= h(cd_url('angebote',['id'=>$detail,'beleg'=>1])) ?>"><?= h(cd_t('beleg_ansehen')) ?></a>
        <?php if ($st==='entwurf' && in_array($rolle,['verkauf','admin'],true)): ?>
          <form method="post" style="margin:0"><input type="hidden" name="aktion" value="angebot_kalk_anfragen"><input type="hidden" name="id" value="<?= $detail ?>"><button class="btn btn-primary" type="submit"><?= h(cd_t('kalk_anfragen')) ?></button></form>
        <?php endif; ?>
        <?php if ($st==='kalkuliert' && in_array($rolle,['verkauf','admin'],true)): ?>
          <form method="post" style="margin:0"><input type="hidden" name="aktion" value="angebot_senden"><input type="hidden" name="id" value="<?= $detail ?>"><button class="btn btn-primary" type="submit"><?= h(cd_t('an_kunde_senden')) ?></button></form>
        <?php endif; ?>
        <?php if ($st==='gesendet' && in_array($rolle,['verkauf','admin'],true)): ?>
          <form method="post" style="margin:0"><input type="hidden" name="aktion" value="angebot_annehmen"><input type="hidden" name="id" value="<?= $detail ?>"><button class="btn btn-primary" type="submit"><?= h(cd_t('annehmen')) ?></button></form>
        <?php endif; ?>
        <form method="post" style="margin:0" onsubmit="return confirm('<?= h(cd_t('loeschen')) ?>?')"><input type="hidden" name="aktion" value="angebot_del"><input type="hidden" name="id" value="<?= $detail ?>"><button class="btn btn-ghost btn-sm" type="submit"><?= h(cd_t('loeschen')) ?></button></form>
      </div>

      <?php if (in_array($st,['kalkulation','kalkuliert'],true) && in_array($rolle,['pricing','admin'],true)): ?>
        <form method="post" style="margin-top:14px"><input type="hidden" name="aktion" value="angebot_kalk_speichern"><input type="hidden" name="id" value="<?= $detail ?>">
          <h3 style="margin:0 0 6px"><?= h(cd_t('r_pricing')) ?></h3>
          <div class="bx-tablewrap"><table class="bx-table"><thead><tr><th><?= h(cd_t('position')) ?></th><th class="bx-num"><?= h(cd_t('menge')) ?></th><th style="width:150px"><?= h(cd_t('preis')) ?> (<?= h((string)$a['waehrung']) ?>)</th></tr></thead><tbody>
          <?php foreach ($pos as $p): ?>
            <tr><td><?= h($p['bezeichnung']) ?><input type="hidden" name="pos_id[]" value="<?= (int)$p['id'] ?>"></td><td class="bx-num"><?= $mnf($p['menge']) ?></td>
              <td><input type="number" step="0.01" name="pos_preis[]" value="<?= number_format((int)$p['preis_cent']/100,2,'.','') ?>"></td></tr>
          <?php endforeach; ?>
          </tbody></table></div>
          <div class="bx-field" style="margin-top:8px"><label><?= h(cd_t('kalk_notiz')) ?></label><textarea name="kalk_notiz" rows="2"><?= h((string)$a['kalk_notiz']) ?></textarea></div>
          <button class="btn btn-primary" type="submit" style="margin-top:6px"><?= h(cd_t('kalk_uebernehmen')) ?></button>
        </form>
      <?php endif; ?>
      </div>

    <?php else: ?>
      <h1 style="margin-bottom:12px"><?= h(cd_t('angebote')) ?></h1>
      <div class="bx-panel"><div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th><?= h(cd_t('nummer')) ?></th><th><?= h(cd_t('kunde')) ?></th><th><?= h(cd_t('titel')) ?></th><th class="bx-num"><?= h(cd_t('netto')) ?></th><th><?= h(cd_t('status')) ?></th></tr></thead><tbody>
        <?php $as = all("SELECT a.*, k.firma FROM crmdemo_angebot a LEFT JOIN crmdemo_kunde k ON k.id=a.kunde_id ORDER BY a.id DESC"); if (!$as): ?><tr><td colspan="5" class="muted"><?= h(cd_t('keine_daten')) ?></td></tr><?php endif;
        foreach ($as as $a): ?><tr><td><a href="<?= h(cd_url('angebote', ['id'=>(int)$a['id']])) ?>"><?= h((string)$a['nummer']) ?></a></td><td><?= h((string)($a['firma'] ?? '')) ?></td><td><?= h((string)$a['titel']) ?></td><td class="bx-num"><?= $eur($a['netto_cent'],$a['waehrung']) ?></td><td><?= $badgeA($a['status']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
      <?php if (in_array($rolle,['verkauf','admin'],true)): ?>
      <div class="bx-panel"><h2 style="margin-top:0"><?= h(cd_t('neu')) ?></h2>
        <form method="post"><input type="hidden" name="aktion" value="angebot_save">
          <div class="bx-grid">
            <div class="bx-field"><label><?= h(cd_t('kunde')) ?></label><select name="kunde_id"><option value="">–</option><?php foreach (all("SELECT id,firma,waehrung FROM crmdemo_kunde ORDER BY firma") as $k): ?><option value="<?= (int)$k['id'] ?>"><?= h($k['firma']) ?> (<?= h((string)$k['waehrung']) ?>)</option><?php endforeach; ?></select></div>
            <div class="bx-field"><label><?= h(cd_t('titel')) ?></label><input type="text" name="titel" placeholder="Angebot"></div>
          </div>
          <label style="display:block;margin:10px 0 6px"><?= h(cd_t('position')) ?></label>
          <?php $rezList = all("SELECT id,name FROM crmdemo_rezeptur ORDER BY name");
                $rohList = all("SELECT id,name FROM crmdemo_rohstoff WHERE aktiv=1 ORDER BY name"); ?>
          <div class="bx-row" style="gap:8px;flex-wrap:wrap;margin-bottom:8px;align-items:center">
            <select id="rezpick"><option value="">– <?= h(cd_t('rezeptur')) ?> –</option>
              <?php foreach ($rezList as $rz): ?><option value="<?= (int)$rz['id'] ?>" data-name="<?= h($rz['name']) ?>"><?= h($rz['name']) ?></option><?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-ghost btn-sm" id="addprod"><?= h(cd_t('plus_produkt')) ?></button>
            <select id="rohpick"><option value="">– <?= h(cd_t('typ_rohstoff')) ?> –</option>
              <?php foreach ($rohList as $ro): ?><option value="<?= (int)$ro['id'] ?>" data-name="<?= h($ro['name']) ?>"><?= h($ro['name']) ?></option><?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-ghost btn-sm" id="addroh"><?= h(cd_t('plus_rohstoff')) ?></button>
            <button type="button" class="btn btn-ghost btn-sm" id="addfrei"><?= h(cd_t('plus_frei')) ?></button>
          </div>
          <div class="bx-tablewrap"><table class="bx-table" id="postab"><thead><tr><th style="width:130px"><?= h(cd_t('typ')) ?></th><th><?= h(cd_t('position')) ?></th><th style="width:100px"><?= h(cd_t('menge')) ?></th><th style="width:60px"><?= h(cd_t('einheit')) ?></th><th style="width:120px"><?= h(cd_t('preis')) ?></th><th style="width:110px;text-align:center"><?= h(cd_t('als_rezeptur')) ?></th></tr></thead>
            <tbody id="posrows"></tbody></table></div>
          <p class="muted" style="font-size:12px;margin:8px 0 0"><?= h(cd_t('als_rezeptur_hint')) ?></p>
          <div class="bx-row" style="margin-top:10px"><button class="btn btn-primary" type="submit"><?= h(cd_t('anlegen')) ?></button></div>
        </form>
        <script>
        (function(){
          var esc=function(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');};
          var EINHEIT={produkt:'Stk.',frei:'Stk.',rohstoff:'kg'};
          var TYPL={produkt:<?= json_encode(cd_t('typ_produkt')) ?>,rohstoff:<?= json_encode(cd_t('typ_rohstoff')) ?>,frei:<?= json_encode(cd_t('typ_frei')) ?>};
          var GESP=<?= json_encode(cd_t('gespeichert')) ?>;
          function addRow(typ,name,rezid,rohid){
            var tb=document.getElementById('posrows');var tr=document.createElement('tr');
            var kat;
            if(typ==='rohstoff'){kat='<input type="hidden" name="p_asrez[]" value="0"><span class="muted">–</span>';}
            else if(rezid>0){kat='<input type="hidden" name="p_asrez[]" value="0"><span class="muted" style="font-size:12px">'+GESP+'</span>';}
            else{kat='<input type="hidden" name="p_asrez[]" value="0"><input type="checkbox" onchange="this.previousElementSibling.value=this.checked?1:0">';}
            tr.innerHTML='<td><input type="hidden" name="p_typ[]" value="'+typ+'"><span class="muted" style="font-size:12px">'+TYPL[typ]+'</span></td>'
              +'<td><input type="hidden" name="p_rezid[]" value="'+(rezid||0)+'"><input type="hidden" name="p_rohid[]" value="'+(rohid||0)+'"><input type="text" name="p_bez[]" value="'+esc(name||'')+'"></td>'
              +'<td><input type="number" step="0.01" name="p_menge[]" value="1"></td>'
              +'<td style="text-align:center">'+EINHEIT[typ]+'</td>'
              +'<td><input type="number" step="0.01" name="p_preis[]"></td>'
              +'<td style="text-align:center">'+kat+'</td>';
            tb.appendChild(tr);
          }
          document.getElementById('addfrei').addEventListener('click',function(){addRow('frei','',0,0);});
          document.getElementById('addprod').addEventListener('click',function(){var s=document.getElementById('rezpick');var o=s.options[s.selectedIndex];addRow('produkt',o&&o.value?o.getAttribute('data-name'):'',o&&o.value?parseInt(o.value,10):0,0);s.selectedIndex=0;});
          document.getElementById('addroh').addEventListener('click',function(){var s=document.getElementById('rohpick');var o=s.options[s.selectedIndex];if(!o||!o.value)return;addRow('rohstoff',o.getAttribute('data-name'),0,parseInt(o.value,10));s.selectedIndex=0;});
          addRow('frei','',0,0);addRow('frei','',0,0);
        })();
        </script>
      </div>
      <?php endif; ?>
    <?php endif;

// ================= RECHNUNGEN (Liste + A4) =================
elseif ($m === 'rechnungen'):
    $detail = (int)($_GET['id'] ?? 0);
    $r = $detail ? one("SELECT r.*, k.firma, k.sprache, k.adresse, k.plz, k.ort, k.land AS kland, k.ust_id FROM crmdemo_rechnung r LEFT JOIN crmdemo_kunde k ON k.id=r.kunde_id WHERE r.id=?", [$detail]) : null;
    if ($r && ($_GET['beleg'] ?? '') === '1'):
        $pos = $r['angebot_id'] ? all("SELECT * FROM crmdemo_angebot_pos WHERE angebot_id=? ORDER BY sort,id", [(int)$r['angebot_id']]) : [];
        cd_beleg_a4('rechnung', $r, $pos, $eur);
    else: ?>
      <h1 style="margin-bottom:12px"><?= h(cd_t('rechnungen')) ?></h1>
      <div class="bx-panel"><div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th><?= h(cd_t('nummer')) ?></th><th><?= h(cd_t('kunde')) ?></th><th><?= h(cd_t('datum')) ?></th><th class="bx-num"><?= h(cd_t('netto')) ?></th><th class="bx-num"><?= h(cd_t('brutto')) ?></th><th><?= h(cd_t('status')) ?></th><th></th></tr></thead><tbody>
        <?php $rs = all("SELECT r.*, k.firma FROM crmdemo_rechnung r LEFT JOIN crmdemo_kunde k ON k.id=r.kunde_id ORDER BY r.id DESC"); if (!$rs): ?><tr><td colspan="7" class="muted"><?= h(cd_t('keine_daten')) ?></td></tr><?php endif;
        foreach ($rs as $r): ?>
          <tr><td><a href="<?= h(cd_url('rechnungen',['id'=>(int)$r['id'],'beleg'=>1])) ?>"><?= h((string)$r['nummer']) ?></a></td><td><?= h((string)($r['firma'] ?? '')) ?></td><td><?= h((string)$r['datum']) ?></td><td class="bx-num"><?= $eur($r['netto_cent'],$r['waehrung']) ?></td><td class="bx-num"><?= $eur($r['brutto_cent'],$r['waehrung']) ?></td><td><?= $badgeR($r['status']) ?></td>
            <td style="text-align:right"><form method="post" style="margin:0"><input type="hidden" name="aktion" value="rechnung_status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="status" value="<?= $r['status']==='bezahlt'?'offen':'bezahlt' ?>"><button class="btn btn-ghost btn-sm" type="submit"><?= $r['status']==='bezahlt'?h(cd_t('offen')):h(cd_t('bezahlt')) ?></button></form></td></tr>
        <?php endforeach; ?>
        </tbody></table></div></div>
    <?php endif;

// ================= PRODUKTION (Liste + Detail mit Chargen-Rückverfolgung) =================
elseif ($m === 'produktion'):
    $detail = (int)($_GET['id'] ?? 0);
    if ($detail && ($pr = one("SELECT pr.*, k.firma FROM crmdemo_produktion pr LEFT JOIN crmdemo_kunde k ON k.id=pr.kunde_id WHERE pr.id=?", [$detail]))):
        $next = ['geplant'=>'in_produktion','in_produktion'=>'fertig','fertig'=>'geplant'][$pr['status']] ?? 'geplant'; ?>
      <div class="bx-row" style="justify-content:space-between;align-items:center">
        <h1 style="margin:0"><?= h((string)$pr['titel']) ?> <span class="muted" style="font-size:14px"><?= h((string)$pr['charge_nr']) ?></span></h1>
        <a class="btn btn-ghost btn-sm" href="<?= h(cd_url('produktion')) ?>">← <?= h(cd_t('produktion')) ?></a>
      </div>
      <p class="bx-sub"><?= h((string)($pr['firma'] ?? '')) ?> · <?= $badgeP($pr['status']) ?> · <?= (int)$pr['menge'] ?> · <?= h(cd_t('mhd')) ?> <?= h((string)$pr['mhd']) ?: '–' ?></p>
      <div class="bx-panel"><form method="post" style="margin:0"><input type="hidden" name="aktion" value="produktion_status"><input type="hidden" name="id" value="<?= $detail ?>"><input type="hidden" name="status" value="<?= $next ?>"><button class="btn btn-primary" type="submit">→ <?= h(cd_t($next==='in_produktion'?'in_produktion':($next==='fertig'?'fertig':'geplant'))) ?></button></form></div>
      <div class="bx-panel"><h2 style="margin-top:0"><?= h(cd_t('rueckverfolgung')) ?></h2>
        <div class="bx-tablewrap"><table class="bx-table"><thead><tr><th><?= h(cd_t('name')) ?></th><th><?= h(cd_t('lot')) ?></th><th class="bx-num">kg</th></tr></thead><tbody>
        <?php $zt = all("SELECT * FROM crmdemo_charge_zutat WHERE produktion_id=? ORDER BY id", [$detail]); if (!$zt): ?><tr><td colspan="3" class="muted"><?= h(cd_t('keine_daten')) ?></td></tr><?php endif;
        foreach ($zt as $z): ?><tr><td><?= h((string)$z['name']) ?></td><td><?= h((string)$z['lot']) ?></td><td class="bx-num"><?= $z['menge_kg']!==null?$mnf($z['menge_kg']):'–' ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <form method="post" style="margin-top:10px"><input type="hidden" name="aktion" value="charge_zutat_add"><input type="hidden" name="produktion_id" value="<?= $detail ?>">
          <div class="bx-grid">
            <div class="bx-field"><label><?= h(cd_t('katalog')) ?></label><select name="rohstoff_id"><option value="">– (frei)</option><?php foreach (all("SELECT id,name FROM crmdemo_rohstoff WHERE aktiv=1 ORDER BY name") as $x): ?><option value="<?= (int)$x['id'] ?>"><?= h($x['name']) ?></option><?php endforeach; ?></select></div>
            <div class="bx-field"><label><?= h(cd_t('lot')) ?></label><input type="text" name="lot"></div>
            <div class="bx-field"><label>kg</label><input type="number" step="0.001" name="menge_kg"></div>
          </div>
          <button class="btn btn-ghost btn-sm" type="submit" style="margin-top:8px"><?= h(cd_t('zutat_hinzu')) ?></button>
        </form>
      </div>
    <?php else: ?>
      <h1 style="margin-bottom:12px"><?= h(cd_t('produktion')) ?></h1>
      <div class="bx-panel"><div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th><?= h(cd_t('charge')) ?></th><th><?= h(cd_t('titel')) ?></th><th><?= h(cd_t('kunde')) ?></th><th class="bx-num"><?= h(cd_t('menge')) ?></th><th><?= h(cd_t('status')) ?></th></tr></thead><tbody>
        <?php $ps = all("SELECT pr.*, k.firma FROM crmdemo_produktion pr LEFT JOIN crmdemo_kunde k ON k.id=pr.kunde_id ORDER BY pr.id DESC"); if (!$ps): ?><tr><td colspan="5" class="muted"><?= h(cd_t('keine_daten')) ?></td></tr><?php endif;
        foreach ($ps as $pr): ?><tr><td><a href="<?= h(cd_url('produktion',['id'=>(int)$pr['id']])) ?>"><?= h((string)$pr['charge_nr']) ?></a></td><td><?= h((string)$pr['titel']) ?></td><td><?= h((string)($pr['firma'] ?? '')) ?></td><td class="bx-num"><?= (int)$pr['menge'] ?></td><td><?= $badgeP($pr['status']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
      <div class="bx-panel"><h2 style="margin-top:0"><?= h(cd_t('neu')) ?></h2>
        <form method="post"><input type="hidden" name="aktion" value="produktion_save">
          <div class="bx-grid">
            <div class="bx-field"><label><?= h(cd_t('titel')) ?></label><input type="text" name="titel" placeholder="Charge / Produkt"></div>
            <div class="bx-field"><label><?= h(cd_t('kunde')) ?></label><select name="kunde_id"><option value="">–</option><?php foreach (all("SELECT id,firma FROM crmdemo_kunde ORDER BY firma") as $k): ?><option value="<?= (int)$k['id'] ?>"><?= h($k['firma']) ?></option><?php endforeach; ?></select></div>
            <div class="bx-field"><label><?= h(cd_t('menge')) ?></label><input type="number" name="menge" value="1000"></div>
            <div class="bx-field"><label><?= h(cd_t('mhd')) ?></label><input type="date" name="mhd"></div>
          </div>
          <div class="bx-row" style="margin-top:10px"><button class="btn btn-primary" type="submit"><?= h(cd_t('anlegen')) ?></button></div>
        </form>
      </div>
    <?php endif;

// ================= KI-ROHSTOFF-CHAT =================
elseif ($m === 'chat'):
    require_once BX_ROOT . '/core/ki.php';
    $kiBereit = function_exists('ki_bereit') && ki_bereit(); ?>
    <h1 style="margin-bottom:4px"><?= h(cd_t('chat')) ?></h1>
    <?php if (!$kiBereit): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:10px 14px"><?= h(cd_t('ki_nicht_bereit')) ?></div><?php endif; ?>
    <div class="bx-panel">
      <form method="post"><input type="hidden" name="aktion" value="chat_frage">
        <div class="bx-field"><label><?= h(cd_t('frage')) ?></label><textarea name="frage" rows="2" placeholder="z. B. Womit lässt sich Magnesiumcitrat sinnvoll kombinieren?"></textarea></div>
        <button class="btn btn-primary" type="submit" data-busy="…"><?= h(cd_t('senden')) ?></button>
      </form>
    </div>
    <?php foreach (all("SELECT * FROM crmdemo_chat ORDER BY id DESC LIMIT 30") as $c): ?>
    <div class="bx-panel"><p style="margin:0"><strong><?= h((string)$c['frage']) ?></strong></p>
      <?php if ($c['antwort']): ?><div class="muted" style="margin-top:6px;white-space:pre-wrap;font-size:13px"><?= h((string)$c['antwort']) ?></div><?php endif; ?>
      <div class="muted" style="font-size:11px;margin-top:6px"><?= h(cd_t('r_'.($c['rolle']?:'admin'))) ?> · <?= h(substr((string)$c['angelegt'],0,16)) ?></div>
    </div>
    <?php endforeach;

// ================= FINANZEN =================
elseif ($m === 'finanzen'):
    $z = crmdemo_kennzahlen(); ?>
    <h1 style="margin-bottom:12px"><?= h(cd_t('finanzen')) ?></h1>
    <div class="bx-cards">
      <div class="bx-card"><div class="k"><?= h(cd_t('umsatz_offen')) ?></div><div class="v"><?= $eur($z['offen']) ?></div></div>
      <div class="bx-card"><div class="k"><?= h(cd_t('umsatz_bezahlt')) ?></div><div class="v"><?= $eur($z['bezahlt']) ?></div></div>
      <div class="bx-card"><div class="k"><?= h(cd_t('summe')) ?></div><div class="v"><?= $eur($z['offen'] + $z['bezahlt']) ?></div></div>
    </div>
    <div class="bx-panel" style="margin-top:16px"><div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th><?= h(cd_t('nummer')) ?></th><th><?= h(cd_t('kunde')) ?></th><th class="bx-num"><?= h(cd_t('brutto')) ?></th><th><?= h(cd_t('status')) ?></th></tr></thead><tbody>
      <?php foreach (all("SELECT r.*, k.firma FROM crmdemo_rechnung r LEFT JOIN crmdemo_kunde k ON k.id=r.kunde_id ORDER BY r.status, r.id DESC") as $r): ?>
        <tr><td><a href="<?= h(cd_url('rechnungen',['id'=>(int)$r['id'],'beleg'=>1])) ?>"><?= h((string)$r['nummer']) ?></a></td><td><?= h((string)($r['firma'] ?? '')) ?></td><td class="bx-num"><?= $eur($r['brutto_cent'],$r['waehrung']) ?></td><td><?= $badgeR($r['status']) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div></div>

<?php // ================= EINSTELLUNGEN (Reiter: Briefkopf · Standardwerte, nur Admin) ===
elseif ($m === 'einstellungen'):
    $tab = in_array($_GET['tab'] ?? '', ['briefkopf','standard'], true) ? $_GET['tab'] : 'briefkopf';
    $abs = cd_absender(); $logo = cd_logo_datauri(); ?>
    <h1 style="margin-bottom:10px"><?= h(cd_t('einstellungen')) ?></h1>
    <div class="cd-rolchips" style="margin-bottom:14px">
      <a href="<?= h(cd_url('einstellungen',['tab'=>'briefkopf'])) ?>"<?= $tab==='briefkopf'?' class="on"':'' ?>><?= h(cd_t('set_briefkopf')) ?></a>
      <a href="<?= h(cd_url('einstellungen',['tab'=>'standard'])) ?>"<?= $tab==='standard'?' class="on"':'' ?>><?= h(cd_t('set_standard')) ?></a>
    </div>
    <?php if ($tab === 'briefkopf'): ?>
    <div class="bx-panel"><h2 style="margin-top:0"><?= h(cd_t('absender')) ?></h2>
      <form method="post" enctype="multipart/form-data"><input type="hidden" name="aktion" value="firma_save">
        <div class="bx-grid">
          <div class="bx-field"><label><?= h(cd_t('firma_name')) ?></label><input type="text" name="name" value="<?= h($abs['name']) ?>"></div>
          <div class="bx-field"><label><?= h(cd_t('adresse')) ?></label><input type="text" name="adresse" value="<?= h($abs['adresse']) ?>"></div>
          <div class="bx-field"><label><?= h(cd_t('ort')) ?></label><input type="text" name="ort" value="<?= h($abs['ort']) ?>"></div>
          <div class="bx-field"><label><?= h(cd_t('land')) ?></label><input type="text" name="land" value="<?= h($abs['land']) ?>"></div>
          <div class="bx-field"><label><?= h(cd_t('email')) ?></label><input type="text" name="kontakt" value="<?= h($abs['kontakt']) ?>"></div>
          <div class="bx-field"><label><?= h(cd_t('ust_id')) ?></label><input type="text" name="ustid" value="<?= h($abs['ustid']) ?>"></div>
        </div>
        <div class="bx-row" style="align-items:center;gap:16px;margin-top:12px">
          <?php if ($logo): ?><img src="<?= h($logo) ?>" alt="" style="max-height:56px;max-width:180px;background:#fff;padding:4px;border:1px solid var(--line)"><?php endif; ?>
          <div class="bx-field" style="margin:0"><label><?= h(cd_t('logo_hochladen')) ?></label><input type="file" name="logo" accept="image/*"></div>
          <?php if ($logo): ?><label class="muted" style="font-size:12px"><input type="checkbox" name="logo_entfernen" value="1"> <?= h(cd_t('loeschen')) ?></label><?php endif; ?>
        </div>
        <div class="bx-row" style="margin-top:12px"><button class="btn btn-primary" type="submit" data-busy="…"><?= h(cd_t('speichern')) ?></button></div>
      </form>
    </div>
    <?php else: ?>
    <div class="bx-panel"><h2 style="margin-top:0"><?= h(cd_t('set_standard')) ?></h2>
      <form method="post"><input type="hidden" name="aktion" value="std_save">
        <div class="bx-grid">
          <div class="bx-field"><label><?= h(cd_t('std_waehrung')) ?></label><select name="std_waehrung"><?php foreach (cd_waehrungen() as $w=>$s): ?><option value="<?= $w ?>"<?= cd_std('std_waehrung','EUR')===$w?' selected':'' ?>><?= $w ?></option><?php endforeach; ?></select></div>
          <div class="bx-field"><label><?= h(cd_t('std_ust')) ?></label><input type="number" step="0.1" name="std_ust" value="<?= h(cd_std('std_ust','19')) ?>"></div>
          <div class="bx-field"><label><?= h(cd_t('std_zahlungsziel')) ?></label><input type="number" name="std_zahlungsziel" value="<?= h(cd_std('std_zahlungsziel','14')) ?>"></div>
        </div>
        <div class="bx-row" style="margin-top:12px"><button class="btn btn-primary" type="submit"><?= h(cd_t('speichern')) ?></button></div>
      </form>
    </div>
    <?php endif; ?>

<?php endif;
cd_shell_ende();

// ===================== Hilfs-Renderer =====================

// Kundenformular (Neu + Bearbeiten).
function cd_kunde_form(?array $k, callable $mnf): void {
    $v = fn($f) => $k ? h((string)($k[$f] ?? '')) : '';
    $stdW = cd_std('std_waehrung','EUR'); $stdZ = cd_std('std_zahlungsziel','14');
    $sel = function($f,$opt) use ($k,$stdW) {
        $cur = $k ? (string)($k[$f] ?? '') : ($f==='waehrung' ? $stdW : '');
        return $cur === $opt ? ' selected' : '';
    }; ?>
    <form method="post"><input type="hidden" name="aktion" value="kunde_save"><?php if ($k): ?><input type="hidden" name="id" value="<?= (int)$k['id'] ?>"><?php endif; ?>
      <div class="bx-grid">
        <div class="bx-field"><label><?= h(cd_t('firma_name')) ?></label><input type="text" name="firma" value="<?= $v('firma') ?>" required></div>
        <div class="bx-field"><label><?= h(cd_t('kundennummer')) ?></label><input type="text" name="kundennummer" value="<?= $v('kundennummer') ?>"></div>
        <div class="bx-field"><label><?= h(cd_t('ansprechpartner')) ?></label><input type="text" name="ansprechpartner" value="<?= $v('ansprechpartner') ?>"></div>
        <div class="bx-field"><label><?= h(cd_t('betreuer')) ?></label><input type="text" name="betreuer" value="<?= $v('betreuer') ?>"></div>
        <div class="bx-field"><label><?= h(cd_t('email')) ?></label><input type="email" name="email" value="<?= $v('email') ?>"></div>
        <div class="bx-field"><label><?= h(cd_t('telefon')) ?></label><input type="text" name="telefon" value="<?= $v('telefon') ?>"></div>
        <div class="bx-field"><label><?= h(cd_t('wechat')) ?></label><input type="text" name="wechat" value="<?= $v('wechat') ?>"></div>
        <div class="bx-field"><label><?= h(cd_t('branche')) ?></label><input type="text" name="branche" value="<?= $v('branche') ?>"></div>
        <div class="bx-field"><label><?= h(cd_t('segment')) ?></label><input type="text" name="segment" value="<?= $v('segment') ?>"></div>
        <div class="bx-field"><label><?= h(cd_t('sprache')) ?></label><select name="sprache"><option value="de"<?= $sel('sprache','de') ?>>Deutsch</option><option value="en"<?= $sel('sprache','en') ?>>English</option><option value="zh"<?= $sel('sprache','zh') ?>>中文</option></select></div>
        <div class="bx-field"><label><?= h(cd_t('waehrung')) ?></label><select name="waehrung"><?php foreach (cd_waehrungen() as $w=>$s): ?><option value="<?= $w ?>"<?= $sel('waehrung',$w) ?>><?= $w ?></option><?php endforeach; ?></select></div>
        <div class="bx-field"><label><?= h(cd_t('zahlungsziel')) ?></label><input type="number" name="zahlungsziel" value="<?= $k ? $v('zahlungsziel') : h($stdZ) ?>"></div>
        <div class="bx-field"><label><?= h(cd_t('adresse')) ?></label><input type="text" name="adresse" value="<?= $v('adresse') ?>"></div>
        <div class="bx-field"><label><?= h(cd_t('plz')) ?></label><input type="text" name="plz" value="<?= $v('plz') ?>"></div>
        <div class="bx-field"><label><?= h(cd_t('ort')) ?></label><input type="text" name="ort" value="<?= $v('ort') ?>"></div>
        <div class="bx-field"><label><?= h(cd_t('land')) ?></label><input type="text" name="land" value="<?= $v('land') ?>" placeholder="DE"></div>
        <div class="bx-field"><label><?= h(cd_t('liefer_adresse')) ?></label><input type="text" name="liefer_adresse" value="<?= $v('liefer_adresse') ?>"></div>
        <div class="bx-field"><label><?= h(cd_t('ust_id')) ?></label><input type="text" name="ust_id" value="<?= $v('ust_id') ?>"></div>
        <div class="bx-field"><label><?= h(cd_t('website')) ?></label><input type="text" name="website" value="<?= $v('website') ?>"></div>
      </div>
      <div class="bx-field" style="margin-top:8px"><label><?= h(cd_t('notiz')) ?></label><textarea name="notiz" rows="2"><?= $v('notiz') ?></textarea></div>
      <div class="bx-row" style="margin-top:10px;gap:8px"><button class="btn btn-primary" type="submit"><?= h($k?cd_t('speichern'):cd_t('anlegen')) ?></button><?php if ($k): ?><a class="btn btn-ghost" href="<?= h(cd_url('kunden',['id'=>(int)$k['id']])) ?>"><?= h(cd_t('abbrechen')) ?></a><?php endif; ?></div>
    </form>
<?php }

// DIN-A4-Beleg in Kundensprache (nur Ansicht, kein Download).
function cd_beleg_a4(string $typ, array $doc, array $pos, callable $eur): void {
    $lang = in_array($doc['sprache'] ?? 'de', ['de','en','zh'], true) ? $doc['sprache'] : 'de';
    $abs = cd_absender(); $logo = cd_logo_datauri();
    $wae = (string)($doc['waehrung'] ?? 'EUR');
    $titelKey = $typ === 'rechnung' ? 'beleg_rechnung' : 'beleg_angebot';
    $backM = $typ === 'rechnung' ? 'rechnungen' : 'angebote';
    $netto = (int)($doc['netto_cent'] ?? 0);
    $ust = isset($doc['ust_prozent']) ? (float)$doc['ust_prozent'] : 0.0;
    $brutto = isset($doc['brutto_cent']) ? (int)$doc['brutto_cent'] : $netto;
    $mnf = fn($v) => rtrim(rtrim(number_format((float)$v, 2, ',', '.'), '0'), ',');
    ?>
    <div class="bx-row" style="justify-content:space-between;align-items:center;margin-bottom:10px">
      <a class="btn btn-ghost btn-sm" href="<?= h(cd_url($backM, ['id'=>(int)$doc['id']])) ?>">← <?= h(cd_t('zurueck')) ?></a>
      <span class="muted" style="font-size:12px"><?= h(cd_tl('ansicht_hinweis',$lang)) ?></span>
    </div>
    <div class="cd-a4">
      <table style="margin-bottom:22px"><tr>
        <td style="vertical-align:top"><?php if ($logo): ?><img src="<?= h($logo) ?>" alt="" style="max-height:52px;max-width:200px"><?php else: ?><strong style="font-size:16px"><?= h($abs['name']) ?></strong><?php endif; ?></td>
        <td style="text-align:right;vertical-align:top;font-size:12px;color:#444">
          <?= h($abs['name']) ?><br><?= h($abs['adresse']) ?><br><?= h($abs['ort']) ?><br><?= h($abs['land']) ?><br><?= h($abs['kontakt']) ?>
        </td></tr></table>
      <table style="margin-bottom:18px"><tr>
        <td style="vertical-align:top;font-size:13px">
          <div style="color:#888;font-size:11px"><?= h(cd_tl('beleg_an',$lang)) ?></div>
          <strong><?= h((string)($doc['firma'] ?? '')) ?></strong><br>
          <?= h(trim((string)($doc['adresse'] ?? ''))) ?><br>
          <?= h(trim((string)($doc['plz'] ?? '').' '.(string)($doc['ort'] ?? ''))) ?><br>
          <?= h((string)($doc['kland'] ?? '')) ?>
        </td>
        <td style="text-align:right;vertical-align:top">
          <h1><?= h(cd_tl($titelKey,$lang)) ?></h1>
          <div style="font-size:13px;margin-top:8px"><?= h(cd_tl('nummer',$lang)) ?>: <strong><?= h((string)($doc['nummer'] ?? '')) ?></strong><br>
          <?= h(cd_tl('datum',$lang)) ?>: <?= h(substr((string)($doc['datum'] ?? $doc['angelegt'] ?? ''),0,10)) ?></div>
        </td></tr></table>
      <table><thead><tr class="cd-th">
        <th style="text-align:left"><?= h(cd_tl('position',$lang)) ?></th>
        <th style="text-align:right;width:70px"><?= h(cd_tl('menge',$lang)) ?></th>
        <th style="text-align:right;width:110px"><?= h(cd_tl('preis',$lang)) ?></th>
        <th style="text-align:right;width:120px"><?= h(cd_tl('summe',$lang)) ?></th></tr></thead><tbody>
        <?php foreach ($pos as $p): ?>
        <tr style="border-bottom:1px solid #eee"><td><?= h($p['bezeichnung']) ?></td><td style="text-align:right"><?= $mnf($p['menge']) ?> <?= h((string)($p['einheit'] ?? '')) ?></td><td style="text-align:right"><?= $eur($p['preis_cent'],$wae) ?></td><td style="text-align:right"><?= $eur((int) round((float)$p['menge']*(int)$p['preis_cent']),$wae) ?></td></tr>
        <?php endforeach; ?>
        <tr><td colspan="3" style="text-align:right"><?= h(cd_tl('netto',$lang)) ?></td><td style="text-align:right"><?= $eur($netto,$wae) ?></td></tr>
        <?php if ($typ === 'rechnung'): ?>
        <tr><td colspan="3" style="text-align:right"><?= h(cd_tl('ust',$lang)) ?> <?= $mnf($ust) ?>%</td><td style="text-align:right"><?= $eur($brutto-$netto,$wae) ?></td></tr>
        <tr style="font-weight:700;border-top:2px solid #222"><td colspan="3" style="text-align:right"><?= h(cd_tl('brutto',$lang)) ?></td><td style="text-align:right"><?= $eur($brutto,$wae) ?></td></tr>
        <?php endif; ?>
      </tbody></table>
      <p style="margin-top:26px;font-size:12px;color:#555"><?php if ($typ==='rechnung'): ?><?= h(cd_tl('zahlbar',$lang)) ?><br><?php endif; ?><?= h($abs['name']) ?> · <?= h(cd_tl('ust_id',$lang)) ?> <?= h($abs['ustid']) ?></p>
    </div>
<?php }
