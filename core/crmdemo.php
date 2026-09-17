<?php
// ============================================================================
// CRM-Demo (Lieferanten-Beta) – EIGENSTÄNDIGES, ISOLIERTES Mini-System.
// Zweck: einem Lieferanten in einer kurzen Testphase zeigen, was das Tool kann
// (CRM + KI-Produktentwickler + Angebote + Rechnungen + Produktion + Finanzen).
//
// ISOLATION: greift AUSSCHLIESSLICH auf eigene Tabellen `crmdemo_*` zu – kein Mix
// mit den echten bulkify-Daten. Vollständig löschbar (crmdemo_loeschen()).
// SPRACHE: Deutsch / 中文 umschaltbar (cd_lang / cd_t).
// ============================================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/layout.php';   // bx_badge / bx_theme_script / bx_busy_script

// --- Schema: eigene Tabellen, idempotent, nur einmal je Request geprüft. -----
function crmdemo_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $eng = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdo->exec("CREATE TABLE IF NOT EXISTS crmdemo_meta (k VARCHAR(64) PRIMARY KEY, v TEXT NULL)$eng");
    $pdo->exec("CREATE TABLE IF NOT EXISTS crmdemo_kunde (
        id INT AUTO_INCREMENT PRIMARY KEY, firma VARCHAR(190) NOT NULL, ansprechpartner VARCHAR(190) NULL,
        email VARCHAR(190) NULL, telefon VARCHAR(60) NULL, land VARCHAR(60) NULL, notiz TEXT NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$eng");
    $pdo->exec("CREATE TABLE IF NOT EXISTS crmdemo_produkt (
        id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL, form VARCHAR(30) NULL,
        idee TEXT NULL, konzept MEDIUMTEXT NULL, angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$eng");
    $pdo->exec("CREATE TABLE IF NOT EXISTS crmdemo_angebot (
        id INT AUTO_INCREMENT PRIMARY KEY, nummer VARCHAR(30) NULL, kunde_id INT NULL, titel VARCHAR(190) NULL,
        netto_cent INT NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT 'offen',
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$eng");
    $pdo->exec("CREATE TABLE IF NOT EXISTS crmdemo_angebot_pos (
        id INT AUTO_INCREMENT PRIMARY KEY, angebot_id INT NOT NULL, bezeichnung VARCHAR(190) NOT NULL,
        menge DECIMAL(12,2) NOT NULL DEFAULT 1, einheit VARCHAR(20) NULL, preis_cent INT NOT NULL DEFAULT 0, sort INT NOT NULL DEFAULT 0)$eng");
    $pdo->exec("CREATE TABLE IF NOT EXISTS crmdemo_rechnung (
        id INT AUTO_INCREMENT PRIMARY KEY, nummer VARCHAR(30) NULL, kunde_id INT NULL, angebot_id INT NULL,
        netto_cent INT NOT NULL DEFAULT 0, ust_prozent DECIMAL(5,2) NOT NULL DEFAULT 19, brutto_cent INT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'offen', datum DATE NULL, angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$eng");
    $pdo->exec("CREATE TABLE IF NOT EXISTS crmdemo_produktion (
        id INT AUTO_INCREMENT PRIMARY KEY, kunde_id INT NULL, produkt_id INT NULL, titel VARCHAR(190) NULL,
        menge INT NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT 'geplant',
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$eng");
}

// --- Meta (Sprache, Nummernkreise) auf der eigenen Meta-Tabelle. -------------
function cd_meta_get(string $k, string $default = ''): string {
    $v = scalar("SELECT v FROM crmdemo_meta WHERE k=?", [$k]);
    return $v === false || $v === null ? $default : (string)$v;
}
function cd_meta_set(string $k, string $v): void {
    q("INSERT INTO crmdemo_meta (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [$k, $v]);
}
function cd_nummer(string $prefix): string {
    $key = 'seq_' . $prefix;
    $n = (int) cd_meta_get($key, '1000') + 1;
    cd_meta_set($key, (string)$n);
    return $prefix . '-' . $n;
}

// --- Sprache: de | zh (in der eigenen Meta gespeichert, per Link umschaltbar). -
function cd_lang(): string {
    $l = strtolower(trim((string)($_GET['lang'] ?? '')));
    if (in_array($l, ['de', 'zh'], true)) { cd_meta_set('lang', $l); return $l; }
    $s = cd_meta_get('lang', 'de');
    return in_array($s, ['de', 'zh'], true) ? $s : 'de';
}
function cd_t(string $key): string {
    static $T = null;
    if ($T === null) $T = [
        'app'            => ['de'=>'CRM Demo', 'zh'=>'CRM 演示'],
        'untertitel'     => ['de'=>'Vertrieb · Produktentwicklung · Produktion · Finanzen', 'zh'=>'销售 · 产品开发 · 生产 · 财务'],
        'demo_hinweis'   => ['de'=>'Testumgebung mit eigenen Daten – jederzeit löschbar.', 'zh'=>'带独立数据的测试环境 — 可随时删除。'],
        'dashboard'      => ['de'=>'Übersicht', 'zh'=>'概览'],
        'kunden'         => ['de'=>'Kunden', 'zh'=>'客户'],
        'produktentwickler' => ['de'=>'Produktentwickler (KI)', 'zh'=>'产品开发（AI）'],
        'angebote'       => ['de'=>'Angebote', 'zh'=>'报价'],
        'rechnungen'     => ['de'=>'Rechnungen', 'zh'=>'发票'],
        'produktion'     => ['de'=>'Produktion', 'zh'=>'生产'],
        'finanzen'       => ['de'=>'Finanzen', 'zh'=>'财务'],
        'verlassen'      => ['de'=>'Demo verlassen', 'zh'=>'退出演示'],
        'neu'            => ['de'=>'Neu', 'zh'=>'新建'],
        'speichern'      => ['de'=>'Speichern', 'zh'=>'保存'],
        'anlegen'        => ['de'=>'Anlegen', 'zh'=>'创建'],
        'abbrechen'      => ['de'=>'Abbrechen', 'zh'=>'取消'],
        'loeschen'       => ['de'=>'Löschen', 'zh'=>'删除'],
        'firma'          => ['de'=>'Firma', 'zh'=>'公司'],
        'ansprechpartner'=> ['de'=>'Ansprechpartner', 'zh'=>'联系人'],
        'email'          => ['de'=>'E-Mail', 'zh'=>'电子邮箱'],
        'telefon'        => ['de'=>'Telefon', 'zh'=>'电话'],
        'land'           => ['de'=>'Land', 'zh'=>'国家'],
        'notiz'          => ['de'=>'Notiz', 'zh'=>'备注'],
        'kunde'          => ['de'=>'Kunde', 'zh'=>'客户'],
        'name'           => ['de'=>'Name', 'zh'=>'名称'],
        'form'           => ['de'=>'Darreichungsform', 'zh'=>'剂型'],
        'idee'           => ['de'=>'Produktidee / Ziel', 'zh'=>'产品创意 / 目标'],
        'konzept'        => ['de'=>'Konzept', 'zh'=>'方案'],
        'entwickeln'     => ['de'=>'Konzept mit KI entwickeln', 'zh'=>'用 AI 生成方案'],
        'titel'          => ['de'=>'Titel', 'zh'=>'标题'],
        'position'       => ['de'=>'Position', 'zh'=>'项目'],
        'menge'          => ['de'=>'Menge', 'zh'=>'数量'],
        'preis'          => ['de'=>'Preis', 'zh'=>'价格'],
        'netto'          => ['de'=>'Netto', 'zh'=>'净额'],
        'brutto'         => ['de'=>'Brutto', 'zh'=>'含税'],
        'ust'            => ['de'=>'USt', 'zh'=>'增值税'],
        'status'         => ['de'=>'Status', 'zh'=>'状态'],
        'datum'          => ['de'=>'Datum', 'zh'=>'日期'],
        'nummer'         => ['de'=>'Nummer', 'zh'=>'编号'],
        'rechnung_aus'   => ['de'=>'Rechnung erstellen', 'zh'=>'生成发票'],
        'summe'          => ['de'=>'Summe', 'zh'=>'合计'],
        'offen'          => ['de'=>'offen', 'zh'=>'未结'],
        'bezahlt'        => ['de'=>'bezahlt', 'zh'=>'已付'],
        'geplant'        => ['de'=>'geplant', 'zh'=>'计划中'],
        'in_produktion'  => ['de'=>'in Produktion', 'zh'=>'生产中'],
        'fertig'         => ['de'=>'fertig', 'zh'=>'完成'],
        'keine_daten'    => ['de'=>'Noch keine Einträge.', 'zh'=>'暂无记录。'],
        'anzahl_kunden'  => ['de'=>'Kunden', 'zh'=>'客户数'],
        'anzahl_angebote'=> ['de'=>'Angebote', 'zh'=>'报价数'],
        'umsatz_offen'   => ['de'=>'Offene Rechnungen', 'zh'=>'未结发票'],
        'umsatz_bezahlt' => ['de'=>'Bezahlt', 'zh'=>'已付'],
        'ki_nicht_bereit'=> ['de'=>'Die KI ist nur auf dem Server (beta) aktiv. Die Idee wird trotzdem gespeichert.', 'zh'=>'AI 仅在服务器（beta）上可用。创意仍会被保存。'],
        'willkommen'     => ['de'=>'Willkommen in der CRM-Demo', 'zh'=>'欢迎使用 CRM 演示'],
        'position_hinzu' => ['de'=>'+ Position', 'zh'=>'+ 项目'],
    ];
    $l = cd_lang();
    return $T[$key][$l] ?? ($T[$key]['de'] ?? $key);
}

// --- Eigenständiges Layout (nutzt app.css, eigenes Menü, Sprachumschalter). ---
function cd_url(string $m, array $extra = []): string {
    $q = array_merge(['p' => 'crmdemo', 'm' => $m], $extra);
    return '?' . http_build_query($q);
}
function cd_head(string $titel): void {
    $cssV = (int) @filemtime(BX_ROOT . '/public/assets/app.css');
    $l = cd_lang();
    echo '<!doctype html><html lang="' . ($l === 'zh' ? 'zh' : 'de') . '"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . h($titel . ' · ' . cd_t('app')) . '</title>'
       . '<link rel="stylesheet" href="assets/app.css?v=' . $cssV . '">'
       . '<script>(function(){try{var t=localStorage.getItem("bx-theme");if(t==="dark"||t==="light")document.documentElement.setAttribute("data-theme",t);}catch(e){}})();</script>'
       . '</head><body>';
}
function cd_shell_start(string $aktiv): void {
    $menu = ['dashboard'=>'dashboard', 'kunden'=>'kunden', 'produktentwickler'=>'produktentwickler',
             'angebote'=>'angebote', 'rechnungen'=>'rechnungen', 'produktion'=>'produktion', 'finanzen'=>'finanzen'];
    $l = cd_lang(); $other = $l === 'de' ? 'zh' : 'de'; $otherLbl = $l === 'de' ? '中文' : 'Deutsch';
    echo '<div class="bx-shell"><aside class="bx-side">'
       . '<div class="bx-brand"><img src="assets/bulkify-logo-white.png" alt="bulkify" class="bx-logo"><span class="bx-ver">' . h(cd_t('app')) . '</span></div>'
       . '<nav><div class="bx-navgroup">' . h(cd_t('untertitel')) . '</div>';
    foreach ($menu as $key => $tkey)
        echo '<a href="' . h(cd_url($key)) . '"' . ($aktiv === $key ? ' class="on"' : '') . '>' . h(cd_t($tkey)) . '</a>';
    echo '<div class="bx-userbox">'
       . '<a href="' . h(cd_url($aktiv, ['lang' => $other])) . '">' . h($otherLbl) . '</a>'
       . '<div class="bx-row" style="gap:14px;margin-top:10px;flex-wrap:wrap">'
       . '<button type="button" class="bx-themebtn" data-dunkel="Dunkel" data-hell="Hell">Dunkel/Hell</button>'
       . '<a class="muted" style="font-size:12px" href="?p=einstellungen&tab=crmdemo">' . h(cd_t('verlassen')) . '</a>'
       . '</div></div>'
       . '</nav></aside><main class="bx-main">';
}
function cd_shell_ende(): void {
    echo '</main></div>';
    if (function_exists('bx_theme_script')) echo bx_theme_script();
    if (function_exists('bx_busy_script')) echo bx_busy_script();
    echo '</body></html>';
}

// --- Kennzahlen (nur eigene Tabellen). ---------------------------------------
function crmdemo_kennzahlen(): array {
    return [
        'kunden'    => (int) scalar("SELECT COUNT(*) FROM crmdemo_kunde"),
        'angebote'  => (int) scalar("SELECT COUNT(*) FROM crmdemo_angebot"),
        'offen'     => (int) scalar("SELECT COALESCE(SUM(brutto_cent),0) FROM crmdemo_rechnung WHERE status='offen'"),
        'bezahlt'   => (int) scalar("SELECT COALESCE(SUM(brutto_cent),0) FROM crmdemo_rechnung WHERE status='bezahlt'"),
        'produktion'=> (int) scalar("SELECT COUNT(*) FROM crmdemo_produktion WHERE status<>'fertig'"),
    ];
}

// --- Löschen: ALLE eigenen Tabellen entfernen (nach der Testphase). ----------
function crmdemo_loeschen(): void {
    $pdo = db();
    foreach (['crmdemo_angebot_pos','crmdemo_angebot','crmdemo_rechnung','crmdemo_produktion','crmdemo_produkt','crmdemo_kunde','crmdemo_meta'] as $t)
        $pdo->exec("DROP TABLE IF EXISTS $t");
}
// --- Nur Daten leeren (Struktur bleibt) – für einen frischen Demo-Start. ------
function crmdemo_reset(): void {
    crmdemo_schema();
    foreach (['crmdemo_angebot_pos','crmdemo_angebot','crmdemo_rechnung','crmdemo_produktion','crmdemo_produkt','crmdemo_kunde'] as $t)
        q("DELETE FROM $t");
    q("DELETE FROM crmdemo_meta WHERE k LIKE 'seq_%'");
}
// --- Beispieldaten für eine überzeugende Vorführung. -------------------------
function crmdemo_seed(): void {
    crmdemo_schema();
    if ((int) scalar("SELECT COUNT(*) FROM crmdemo_kunde") > 0) return;
    q("INSERT INTO crmdemo_kunde (firma,ansprechpartner,email,land,notiz) VALUES
        ('Nordic Wellness AB','Erik Lind','erik@nordicwellness.se','SE','Interesse an Magnesium-Linie'),
        ('VitaPrime GmbH','Anna Weber','anna@vitaprime.de','DE','Eigenmarke, 3 Produkte geplant'),
        ('Health Republic Ltd','Tom Shaw','tom@healthrep.co.uk','GB','Bulk-Anfrage Vitamin D3')");
    $k1 = (int) scalar("SELECT id FROM crmdemo_kunde ORDER BY id LIMIT 1");
    q("INSERT INTO crmdemo_produkt (name,form,idee) VALUES ('Magnesium Complex','kapsel','Magnesium für Muskeln & Nerven, gut verträglich')");
    q("INSERT INTO crmdemo_angebot (nummer,kunde_id,titel,netto_cent,status) VALUES (?,?, 'Magnesium Complex – 60 Kapseln', 249000, 'offen')", [cd_nummer('AN'), $k1]);
    $a1 = (int) scalar("SELECT id FROM crmdemo_angebot ORDER BY id DESC LIMIT 1");
    q("INSERT INTO crmdemo_angebot_pos (angebot_id,bezeichnung,menge,einheit,preis_cent,sort) VALUES
        (?,'Herstellung Magnesium Complex (60 Kaps.)',1000,'Pkg.',249,0)", [$a1]);
}
