<?php
// ============================================================================
// CRM-Demo (Lieferanten-Beta) – EIGENSTÄNDIGES, ISOLIERTES Mini-System.
// Zweck: einem Lieferanten in einer kurzen Testphase zeigen, was das Tool kann:
//   CRM (tiefe Profile + Postfach) · Rohstoff-Katalog mit KI-Ähnlichkeit ·
//   Preishistorie · Pricing-Workflow (Verkauf -> Pricing -> zurueck) ·
//   Angebote/Rechnungen als DIN-A4-Bildschirmansicht in Kundensprache ·
//   Produktion mit Chargen-Rueckverfolgung · KI-Rohstoff-Chat · Rollen/Rechte.
//
// ISOLATION: greift AUSSCHLIESSLICH auf eigene Tabellen `crmdemo_*` zu – kein Mix
// mit echten bulkify-Daten. Vollstaendig loeschbar (crmdemo_loeschen()).
// Belege (Angebot/Rechnung) sind als A4-Druckseite herunterladbar (Als PDF speichern).
// SPRACHE: Deutsch / English / 中文 umschaltbar (cd_lang / cd_t).
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS crmdemo_meta (k VARCHAR(64) PRIMARY KEY, v MEDIUMTEXT NULL)$eng");
    $pdo->exec("CREATE TABLE IF NOT EXISTS crmdemo_kunde (
        id INT AUTO_INCREMENT PRIMARY KEY, firma VARCHAR(190) NOT NULL, ansprechpartner VARCHAR(190) NULL,
        email VARCHAR(190) NULL, telefon VARCHAR(60) NULL, land VARCHAR(60) NULL,
        sprache VARCHAR(5) NOT NULL DEFAULT 'de', waehrung VARCHAR(3) NOT NULL DEFAULT 'EUR',
        adresse VARCHAR(190) NULL, plz VARCHAR(20) NULL, ort VARCHAR(120) NULL,
        ust_id VARCHAR(40) NULL, website VARCHAR(190) NULL, wechat VARCHAR(80) NULL,
        segment VARCHAR(40) NULL, kundennummer VARCHAR(30) NULL, betreuer VARCHAR(80) NULL,
        betreuer_id INT NULL, fraud TINYINT NOT NULL DEFAULT 0,
        zahlungsziel INT NULL, liefer_adresse VARCHAR(190) NULL, branche VARCHAR(80) NULL,
        notiz TEXT NULL, angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$eng");
    $pdo->exec("CREATE TABLE IF NOT EXISTS crmdemo_produkt (
        id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL, form VARCHAR(30) NULL,
        idee TEXT NULL, konzept MEDIUMTEXT NULL, angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$eng");
    // Rezeptur-Katalog: geteilte Bibliothek fertiger Rezepturen. Waechst, wenn Sales einem
    // Kunden eine Rezeptur vorstellen -> im Angebot wieder waehlbar (kein Wildwuchs bei 25 Sales).
    $pdo->exec("CREATE TABLE IF NOT EXISTS crmdemo_rezeptur (
        id INT AUTO_INCREMENT PRIMARY KEY, nummer VARCHAR(30) NULL, name VARCHAR(190) NOT NULL, form VARCHAR(30) NULL,
        kategorie VARCHAR(80) NULL, beschreibung TEXT NULL, zutaten MEDIUMTEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'entwurf', freigabe_von VARCHAR(20) NULL, freigabe_am DATETIME NULL,
        preis_cent INT NULL, preis_notiz TEXT NULL,
        erstellt_von VARCHAR(40) NULL, verwendet INT NOT NULL DEFAULT 0,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$eng");
    // Rohstoff-Katalog + Preishistorie (fuer KI-Aehnlichkeit + Sourcing) --------
    $pdo->exec("CREATE TABLE IF NOT EXISTS crmdemo_rohstoff (
        id INT AUTO_INCREMENT PRIMARY KEY, nummer VARCHAR(30) NULL, name VARCHAR(190) NOT NULL, kategorie VARCHAR(80) NULL,
        wirkstoff VARCHAR(120) NULL, gehalt VARCHAR(60) NULL, form VARCHAR(40) NULL,
        herkunft VARCHAR(80) NULL, cas VARCHAR(40) NULL, moq_kg DECIMAL(12,2) NULL,
        notiz TEXT NULL, aktiv TINYINT NOT NULL DEFAULT 1,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$eng");
    // Dokumente je Rohstoff (Upload wie in v4; inline base64, kein Datei-URL) ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS crmdemo_dokument (
        id INT AUTO_INCREMENT PRIMARY KEY, rohstoff_id INT NOT NULL, name VARCHAR(190) NULL,
        typ VARCHAR(80) NULL, groesse INT NULL, daten MEDIUMTEXT NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$eng");
    $pdo->exec("CREATE TABLE IF NOT EXISTS crmdemo_rohstoff_preis (
        id INT AUTO_INCREMENT PRIMARY KEY, rohstoff_id INT NOT NULL, datum DATE NULL,
        preis_cent INT NOT NULL DEFAULT 0, waehrung VARCHAR(3) NOT NULL DEFAULT 'EUR',
        einheit VARCHAR(10) NOT NULL DEFAULT 'kg', lieferant VARCHAR(120) NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$eng");
    // Angebote + Positionen (mit Pricing-Workflow) -----------------------------
    $pdo->exec("CREATE TABLE IF NOT EXISTS crmdemo_angebot (
        id INT AUTO_INCREMENT PRIMARY KEY, nummer VARCHAR(30) NULL, kunde_id INT NULL, titel VARCHAR(190) NULL,
        waehrung VARCHAR(3) NOT NULL DEFAULT 'EUR', netto_cent INT NOT NULL DEFAULT 0,
        status VARCHAR(24) NOT NULL DEFAULT 'entwurf', kalk_notiz TEXT NULL, gueltig_bis DATE NULL,
        notiz TEXT NULL, zahlungsbedingungen VARCHAR(190) NULL, versandart VARCHAR(120) NULL,
        ablehnungsgrund TEXT NULL, angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$eng");
    $pdo->exec("CREATE TABLE IF NOT EXISTS crmdemo_angebot_pos (
        id INT AUTO_INCREMENT PRIMARY KEY, angebot_id INT NOT NULL, bezeichnung VARCHAR(190) NOT NULL,
        menge DECIMAL(12,2) NOT NULL DEFAULT 1, einheit VARCHAR(20) NULL, preis_cent INT NOT NULL DEFAULT 0,
        typ VARCHAR(20) NOT NULL DEFAULT 'produkt', rezeptur_id INT NULL, rohstoff_id INT NULL,
        sort INT NOT NULL DEFAULT 0)$eng");
    $pdo->exec("CREATE TABLE IF NOT EXISTS crmdemo_rechnung (
        id INT AUTO_INCREMENT PRIMARY KEY, nummer VARCHAR(30) NULL, kunde_id INT NULL, angebot_id INT NULL,
        waehrung VARCHAR(3) NOT NULL DEFAULT 'EUR', netto_cent INT NOT NULL DEFAULT 0,
        ust_prozent DECIMAL(5,2) NOT NULL DEFAULT 19, brutto_cent INT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'offen', datum DATE NULL,
        notiz TEXT NULL, zahlungsbedingungen VARCHAR(190) NULL, versandart VARCHAR(120) NULL, bankverbindung TEXT NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$eng");
    // Produktion + Chargen (Rueckverfolgbarkeit) -------------------------------
    $pdo->exec("CREATE TABLE IF NOT EXISTS crmdemo_produktion (
        id INT AUTO_INCREMENT PRIMARY KEY, kunde_id INT NULL, produkt_id INT NULL, titel VARCHAR(190) NULL,
        charge_nr VARCHAR(40) NULL, mhd DATE NULL, menge INT NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT 'geplant',
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$eng");
    $pdo->exec("CREATE TABLE IF NOT EXISTS crmdemo_charge_zutat (
        id INT AUTO_INCREMENT PRIMARY KEY, produktion_id INT NOT NULL, rohstoff_id INT NULL,
        name VARCHAR(190) NULL, lot VARCHAR(60) NULL, menge_kg DECIMAL(12,3) NULL)$eng");
    // Simuliertes Kunden-Postfach ---------------------------------------------
    $pdo->exec("CREATE TABLE IF NOT EXISTS crmdemo_mail (
        id INT AUTO_INCREMENT PRIMARY KEY, kunde_id INT NULL, richtung VARCHAR(4) NOT NULL DEFAULT 'ein',
        betreff VARCHAR(190) NULL, text MEDIUMTEXT NULL, gelesen TINYINT NOT NULL DEFAULT 0,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$eng");
    // KI-Rohstoff-Chat-Verlauf -------------------------------------------------
    $pdo->exec("CREATE TABLE IF NOT EXISTS crmdemo_chat (
        id INT AUTO_INCREMENT PRIMARY KEY, rolle VARCHAR(20) NULL, frage TEXT NULL, antwort MEDIUMTEXT NULL,
        angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$eng");
    // Mitarbeiter (Demo): Zuordnung von Kunden + eigene Unterschrift/Stempel fuer Angebote.
    $pdo->exec("CREATE TABLE IF NOT EXISTS crmdemo_mitarbeiter (
        id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, rolle VARCHAR(20) NULL,
        email VARCHAR(190) NULL, signatur_b64 MEDIUMTEXT NULL, stempel_b64 MEDIUMTEXT NULL,
        aktiv TINYINT NOT NULL DEFAULT 1, angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$eng");
    // KI-COA/Spec-Reader: eingelesenes Lieferanten-Dokument -> Kunden-COA (DE/EN).
    $pdo->exec("CREATE TABLE IF NOT EXISTS crmdemo_coa (
        id INT AUTO_INCREMENT PRIMARY KEY, rohstoff_id INT NULL, typ VARCHAR(10) NOT NULL DEFAULT 'coa',
        sprache VARCHAR(5) NOT NULL DEFAULT 'de', produkt VARCHAR(190) NULL, charge VARCHAR(60) NULL,
        daten MEDIUMTEXT NULL, quelle MEDIUMTEXT NULL, angelegt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$eng");
    // Additive Migration: aus der frueheren MVP-Version fehlende Spalten ergaenzen.
    if (function_exists('ensure_column')) {
        ensure_column('crmdemo_kunde', 'sprache',  "VARCHAR(5) NOT NULL DEFAULT 'de'");
        ensure_column('crmdemo_kunde', 'waehrung', "VARCHAR(3) NOT NULL DEFAULT 'EUR'");
        ensure_column('crmdemo_kunde', 'adresse',  "VARCHAR(190) NULL");
        ensure_column('crmdemo_kunde', 'plz',      "VARCHAR(20) NULL");
        ensure_column('crmdemo_kunde', 'ort',      "VARCHAR(120) NULL");
        ensure_column('crmdemo_kunde', 'ust_id',   "VARCHAR(40) NULL");
        ensure_column('crmdemo_kunde', 'website',  "VARCHAR(190) NULL");
        ensure_column('crmdemo_kunde', 'wechat',   "VARCHAR(80) NULL");
        ensure_column('crmdemo_kunde', 'segment',  "VARCHAR(40) NULL");
        ensure_column('crmdemo_angebot', 'waehrung',  "VARCHAR(3) NOT NULL DEFAULT 'EUR'");
        ensure_column('crmdemo_angebot', 'kalk_notiz', "TEXT NULL");
        ensure_column('crmdemo_angebot', 'gueltig_bis', "DATE NULL");
        ensure_column('crmdemo_rechnung', 'waehrung', "VARCHAR(3) NOT NULL DEFAULT 'EUR'");
        ensure_column('crmdemo_produktion', 'charge_nr', "VARCHAR(40) NULL");
        ensure_column('crmdemo_produktion', 'mhd', "DATE NULL");
        ensure_column('crmdemo_angebot_pos', 'rezeptur_id', "INT NULL");
        ensure_column('crmdemo_angebot_pos', 'typ', "VARCHAR(20) NOT NULL DEFAULT 'produkt'");
        ensure_column('crmdemo_angebot_pos', 'rohstoff_id', "INT NULL");
        foreach (['kundennummer'=>"VARCHAR(30) NULL",'betreuer'=>"VARCHAR(80) NULL",'zahlungsziel'=>"INT NULL",
                  'liefer_adresse'=>"VARCHAR(190) NULL",'branche'=>"VARCHAR(80) NULL",
                  'betreuer_id'=>"INT NULL",'fraud'=>"TINYINT NOT NULL DEFAULT 0"] as $c=>$d)
            ensure_column('crmdemo_kunde', $c, $d);
        foreach (['notiz'=>"TEXT NULL",'zahlungsbedingungen'=>"VARCHAR(190) NULL",'versandart'=>"VARCHAR(120) NULL",
                  'ablehnungsgrund'=>"TEXT NULL"] as $c=>$d) ensure_column('crmdemo_angebot', $c, $d);
        foreach (['notiz'=>"TEXT NULL",'zahlungsbedingungen'=>"VARCHAR(190) NULL",'versandart'=>"VARCHAR(120) NULL",
                  'bankverbindung'=>"TEXT NULL"] as $c=>$d) ensure_column('crmdemo_rechnung', $c, $d);
        foreach (['nummer'=>"VARCHAR(30) NULL",'status'=>"VARCHAR(20) NOT NULL DEFAULT 'entwurf'",
                  'freigabe_von'=>"VARCHAR(20) NULL",'freigabe_am'=>"DATETIME NULL",'preis_cent'=>"INT NULL",
                  'preis_notiz'=>"TEXT NULL"] as $c=>$d) ensure_column('crmdemo_rezeptur', $c, $d);
        ensure_column('crmdemo_rohstoff', 'nummer', "VARCHAR(30) NULL");
    }
}

// --- Alle eigenen Tabellen (eine Quelle fuer Loeschen/Reset). ----------------
function crmdemo_tabellen(): array {
    return ['crmdemo_charge_zutat','crmdemo_angebot_pos','crmdemo_angebot','crmdemo_rechnung',
            'crmdemo_produktion','crmdemo_produkt','crmdemo_rezeptur','crmdemo_coa','crmdemo_dokument','crmdemo_rohstoff_preis','crmdemo_rohstoff',
            'crmdemo_mail','crmdemo_chat','crmdemo_mitarbeiter','crmdemo_kunde'];
}

// --- Meta (Sprache, Rolle, Nummernkreise, Briefkopf). ------------------------
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

// --- Sprache: de | en | zh (Meta-gespeichert, per Link umschaltbar). ----------
function cd_lang(): string {
    $l = strtolower(trim((string)($_GET['lang'] ?? '')));
    if (in_array($l, ['de','en','zh'], true)) { cd_meta_set('lang', $l); return $l; }
    $s = cd_meta_get('lang', 'de');
    return in_array($s, ['de','en','zh'], true) ? $s : 'de';
}
function cd_t(string $key): string {
    static $T = null;
    if ($T === null) $T = crmdemo_i18n();
    $l = cd_lang();
    return $T[$key][$l] ?? ($T[$key]['de'] ?? $key);
}
// Uebersetzung in einer bestimmten Sprache (fuer Belege in Kundensprache).
function cd_tl(string $key, string $lang): string {
    static $T = null;
    if ($T === null) $T = crmdemo_i18n();
    if (!in_array($lang, ['de','en','zh'], true)) $lang = 'de';
    return $T[$key][$lang] ?? ($T[$key]['de'] ?? $key);
}
function crmdemo_i18n(): array {
    return [
        'app'            => ['de'=>'CRM Demo','en'=>'CRM Demo','zh'=>'CRM 演示'],
        'untertitel'     => ['de'=>'Vertrieb · Katalog · Produktion · Finanzen','en'=>'Sales · Catalog · Production · Finance','zh'=>'销售 · 目录 · 生产 · 财务'],
        'demo_hinweis'   => ['de'=>'Testumgebung mit eigenen Daten – jederzeit löschbar.','en'=>'Test environment with its own data – deletable any time.','zh'=>'带独立数据的测试环境 — 可随时删除。'],
        'dashboard'      => ['de'=>'Übersicht','en'=>'Overview','zh'=>'概览'],
        'kunden'         => ['de'=>'Kunden','en'=>'Customers','zh'=>'客户'],
        'konversation'   => ['de'=>'Konversation','en'=>'Conversations','zh'=>'沟通记录'],
        'konv_intro'     => ['de'=>'Alle Nachrichten mit den Kunden auf einen Blick.','en'=>'All customer messages at a glance.','zh'=>'一览所有客户沟通。'],
        'eingehend'      => ['de'=>'Eingehend','en'=>'Incoming','zh'=>'接收'],
        'ausgehend'      => ['de'=>'Ausgehend','en'=>'Outgoing','zh'=>'发送'],
        'alle_kunden'    => ['de'=>'Alle Kunden','en'=>'All customers','zh'=>'所有客户'],
        'katalog'        => ['de'=>'Rohstoff-Katalog','en'=>'Material catalog','zh'=>'原料目录'],
        'rezepturen'     => ['de'=>'Rezeptur-Katalog','en'=>'Formulation catalog','zh'=>'配方目录'],
        'produktentwickler'=>['de'=>'Produktentwickler (KI)','en'=>'Product developer (AI)','zh'=>'产品开发（AI）'],
        'coareader'      => ['de'=>'COA/Spec-Reader (KI)','en'=>'COA/Spec reader (AI)','zh'=>'COA/规格读取（AI）'],
        'einstellungen'  => ['de'=>'Einstellungen','en'=>'Settings','zh'=>'设置'],
        'ki'             => ['de'=>'KI-Werkzeuge','en'=>'AI tools','zh'=>'AI 工具'],
        'ki_intro'       => ['de'=>'Alle KI-Funktionen an einem Ort – jede ist auch direkt auf der passenden Seite verlinkt.','en'=>'All AI features in one place – each is also linked on its related page.','zh'=>'所有 AI 功能集中于此 — 也可在相应页面直接打开。'],
        'pe_desc'        => ['de'=>'Aus einer Produktidee ein Konzept mit Zutaten generieren.','en'=>'Turn a product idea into a concept with ingredients.','zh'=>'将产品创意生成含配料的方案。'],
        'coa_desc'       => ['de'=>'Lieferanten-COA/Spec auslesen und Kunden-COA erstellen.','en'=>'Read supplier COA/spec and build a customer COA.','zh'=>'读取供应商 COA/规格并生成客户 COA。'],
        'chat_desc'      => ['de'=>'Fragen zu Rohstoffen, Kombinationen und Sourcing.','en'=>'Questions on materials, combinations and sourcing.','zh'=>'关于原料、组合与采购的问答。'],
        'angebote'       => ['de'=>'Angebote','en'=>'Quotes','zh'=>'报价'],
        'rechnungen'     => ['de'=>'Rechnungen','en'=>'Invoices','zh'=>'发票'],
        'produktion'     => ['de'=>'Produktion','en'=>'Production','zh'=>'生产'],
        'chat'           => ['de'=>'KI-Rohstoff-Chat','en'=>'AI material chat','zh'=>'AI 原料问答'],
        'finanzen'       => ['de'=>'Finanzen','en'=>'Finance','zh'=>'财务'],
        'firma'          => ['de'=>'Briefkopf / Firma','en'=>'Letterhead / Company','zh'=>'抬头 / 公司'],
        'verlassen'      => ['de'=>'Demo verlassen','en'=>'Leave demo','zh'=>'退出演示'],
        'rolle'          => ['de'=>'Rolle','en'=>'Role','zh'=>'角色'],
        'r_verkauf'      => ['de'=>'Verkauf','en'=>'Sales','zh'=>'销售'],
        'r_entwicklung'  => ['de'=>'Entwicklung','en'=>'R&D','zh'=>'研发'],
        'r_pricing'      => ['de'=>'Pricing / Sourcing','en'=>'Pricing / Sourcing','zh'=>'定价 / 采购'],
        'r_produktion'   => ['de'=>'Produktion','en'=>'Production','zh'=>'生产'],
        'r_buchhaltung'  => ['de'=>'Buchhaltung','en'=>'Accounting','zh'=>'会计'],
        'r_admin'        => ['de'=>'Admin','en'=>'Admin','zh'=>'管理员'],
        'kein_recht'     => ['de'=>'Für diese Rolle nicht sichtbar.','en'=>'Not visible for this role.','zh'=>'该角色无法查看。'],
        'neu'            => ['de'=>'Neu','en'=>'New','zh'=>'新建'],
        'speichern'      => ['de'=>'Speichern','en'=>'Save','zh'=>'保存'],
        'anlegen'        => ['de'=>'Anlegen','en'=>'Create','zh'=>'创建'],
        'abbrechen'      => ['de'=>'Abbrechen','en'=>'Cancel','zh'=>'取消'],
        'loeschen'       => ['de'=>'Löschen','en'=>'Delete','zh'=>'删除'],
        'zurueck'        => ['de'=>'Zurück','en'=>'Back','zh'=>'返回'],
        'oeffnen'        => ['de'=>'Öffnen','en'=>'Open','zh'=>'打开'],
        'profil'         => ['de'=>'Profil','en'=>'Profile','zh'=>'资料'],
        'firma_name'     => ['de'=>'Firma','en'=>'Company','zh'=>'公司'],
        'ansprechpartner'=> ['de'=>'Ansprechpartner','en'=>'Contact','zh'=>'联系人'],
        'email'          => ['de'=>'E-Mail','en'=>'Email','zh'=>'电子邮箱'],
        'telefon'        => ['de'=>'Telefon','en'=>'Phone','zh'=>'电话'],
        'land'           => ['de'=>'Land','en'=>'Country','zh'=>'国家'],
        'adresse'        => ['de'=>'Adresse','en'=>'Address','zh'=>'地址'],
        'plz'            => ['de'=>'PLZ','en'=>'ZIP','zh'=>'邮编'],
        'ort'            => ['de'=>'Ort','en'=>'City','zh'=>'城市'],
        'ust_id'         => ['de'=>'USt-IdNr.','en'=>'VAT ID','zh'=>'税号'],
        'website'        => ['de'=>'Website','en'=>'Website','zh'=>'网站'],
        'wechat'         => ['de'=>'WeChat','en'=>'WeChat','zh'=>'微信'],
        'sprache'        => ['de'=>'Sprache','en'=>'Language','zh'=>'语言'],
        'waehrung'       => ['de'=>'Währung','en'=>'Currency','zh'=>'货币'],
        'segment'        => ['de'=>'Segment','en'=>'Segment','zh'=>'细分'],
        'notiz'          => ['de'=>'Notiz','en'=>'Note','zh'=>'备注'],
        'kunde'          => ['de'=>'Kunde','en'=>'Customer','zh'=>'客户'],
        'postfach'       => ['de'=>'Postfach','en'=>'Mailbox','zh'=>'邮箱'],
        'tab_ueber'      => ['de'=>'Übersicht','en'=>'Overview','zh'=>'概览'],
        'kontakt'        => ['de'=>'Kontakt','en'=>'Contact','zh'=>'联系'],
        'umsatz'         => ['de'=>'Umsatz (bezahlt)','en'=>'Revenue (paid)','zh'=>'营收（已付）'],
        'offene_posten'  => ['de'=>'Offene Posten','en'=>'Open items','zh'=>'未结款'],
        'kunde_seit'     => ['de'=>'Kunde seit','en'=>'Customer since','zh'=>'客户始于'],
        'letzte_angebote'=> ['de'=>'Letzte Angebote','en'=>'Recent quotes','zh'=>'最近报价'],
        'letzte_rechnungen'=>['de'=>'Letzte Rechnungen','en'=>'Recent invoices','zh'=>'最近发票'],
        'aktiv'          => ['de'=>'Aktiv','en'=>'Active','zh'=>'活跃'],
        'anzahl'         => ['de'=>'Anzahl','en'=>'Count','zh'=>'数量'],
        'betreff'        => ['de'=>'Betreff','en'=>'Subject','zh'=>'主题'],
        'verlauf'        => ['de'=>'Verlauf','en'=>'History','zh'=>'记录'],
        'name'           => ['de'=>'Name','en'=>'Name','zh'=>'名称'],
        'kategorie'      => ['de'=>'Kategorie','en'=>'Category','zh'=>'类别'],
        'wirkstoff'      => ['de'=>'Wirkstoff','en'=>'Active','zh'=>'活性成分'],
        'gehalt'         => ['de'=>'Gehalt / Spezifikation','en'=>'Content / spec','zh'=>'含量 / 规格'],
        'herkunft'       => ['de'=>'Herkunft','en'=>'Origin','zh'=>'产地'],
        'moq'            => ['de'=>'MOQ (kg)','en'=>'MOQ (kg)','zh'=>'起订量 (kg)'],
        'preis_kg'       => ['de'=>'Preis / kg','en'=>'Price / kg','zh'=>'单价 / kg'],
        'preishistorie'  => ['de'=>'Preishistorie','en'=>'Price history','zh'=>'价格历史'],
        'preis_erfassen' => ['de'=>'Preis erfassen','en'=>'Add price','zh'=>'录入价格'],
        'aehnliche'      => ['de'=>'Ähnliche im Katalog','en'=>'Similar in catalog','zh'=>'目录中的相似项'],
        'matching'       => ['de'=>'KI-Ähnlichkeitssuche','en'=>'AI similarity match','zh'=>'AI 相似度匹配'],
        'matching_hint'  => ['de'=>'Anfrage eintragen (z. B. „Ashwagandha 350 mg 5% Withanolide") – das System findet die nächste Übereinstimmung im Katalog.','en'=>'Enter a request (e.g. “Ashwagandha 350 mg 5% withanolides”) – the system finds the closest catalog match.','zh'=>'输入需求（例如“Ashwagandha 350 mg 5% 睡茄内酯”）— 系统会在目录中找到最接近的匹配。'],
        'suchen'         => ['de'=>'Suchen','en'=>'Search','zh'=>'搜索'],
        'treffer'        => ['de'=>'Übereinstimmung','en'=>'Match','zh'=>'匹配度'],
        'form'           => ['de'=>'Darreichungsform','en'=>'Dosage form','zh'=>'剂型'],
        'idee'           => ['de'=>'Produktidee / Ziel','en'=>'Product idea / goal','zh'=>'产品创意 / 目标'],
        'konzept'        => ['de'=>'Konzept','en'=>'Concept','zh'=>'方案'],
        'entwickeln'     => ['de'=>'Konzept mit KI entwickeln','en'=>'Generate concept with AI','zh'=>'用 AI 生成方案'],
        'titel'          => ['de'=>'Titel','en'=>'Title','zh'=>'标题'],
        'position'       => ['de'=>'Position','en'=>'Item','zh'=>'项目'],
        'menge'          => ['de'=>'Menge','en'=>'Qty','zh'=>'数量'],
        'preis'          => ['de'=>'Preis','en'=>'Price','zh'=>'价格'],
        'netto'          => ['de'=>'Netto','en'=>'Net','zh'=>'净额'],
        'brutto'         => ['de'=>'Brutto','en'=>'Gross','zh'=>'含税'],
        'ust'            => ['de'=>'USt','en'=>'VAT','zh'=>'增值税'],
        'status'         => ['de'=>'Status','en'=>'Status','zh'=>'状态'],
        'datum'          => ['de'=>'Datum','en'=>'Date','zh'=>'日期'],
        'gueltig_bis'    => ['de'=>'Gültig bis','en'=>'Valid until','zh'=>'有效期至'],
        'nummer'         => ['de'=>'Nummer','en'=>'No.','zh'=>'编号'],
        'summe'          => ['de'=>'Summe','en'=>'Total','zh'=>'合计'],
        'beleg_ansehen'  => ['de'=>'Beleg ansehen','en'=>'View document','zh'=>'查看单据'],
        'herunterladen'  => ['de'=>'Herunterladen (PDF)','en'=>'Download (PDF)','zh'=>'下载 (PDF)'],
        'drucken'        => ['de'=>'Drucken / Als PDF speichern','en'=>'Print / Save as PDF','zh'=>'打印 / 另存为 PDF'],
        'rechnung_aus'   => ['de'=>'Rechnung erstellen','en'=>'Create invoice','zh'=>'生成发票'],
        'kalk_anfragen'  => ['de'=>'Kalkulation anfragen','en'=>'Request pricing','zh'=>'申请核价'],
        'kalk_uebernehmen'=>['de'=>'Preise übernehmen','en'=>'Apply prices','zh'=>'应用价格'],
        'an_kunde_senden'=> ['de'=>'An Kunden senden','en'=>'Send to customer','zh'=>'发送给客户'],
        'annehmen'       => ['de'=>'Als angenommen markieren','en'=>'Mark accepted','zh'=>'标记为已接受'],
        'kalk_notiz'     => ['de'=>'Kalkulationsnotiz (Pricing)','en'=>'Pricing note','zh'=>'核价备注'],
        // Status-Labels
        's_entwurf'      => ['de'=>'Entwurf','en'=>'Draft','zh'=>'草稿'],
        's_kalkulation'  => ['de'=>'Kalkulation angefragt','en'=>'Pricing requested','zh'=>'待核价'],
        's_kalkuliert'   => ['de'=>'Kalkuliert','en'=>'Priced','zh'=>'已核价'],
        's_gesendet'     => ['de'=>'Gesendet','en'=>'Sent','zh'=>'已发送'],
        's_angenommen'   => ['de'=>'Angenommen','en'=>'Accepted','zh'=>'已接受'],
        's_abgelehnt'    => ['de'=>'Abgelehnt','en'=>'Rejected','zh'=>'已拒绝'],
        'offen'          => ['de'=>'offen','en'=>'open','zh'=>'未结'],
        'bezahlt'        => ['de'=>'bezahlt','en'=>'paid','zh'=>'已付'],
        'geplant'        => ['de'=>'geplant','en'=>'planned','zh'=>'计划中'],
        'in_produktion'  => ['de'=>'in Produktion','en'=>'in production','zh'=>'生产中'],
        'fertig'         => ['de'=>'fertig','en'=>'done','zh'=>'完成'],
        'charge'         => ['de'=>'Charge','en'=>'Batch','zh'=>'批号'],
        'mhd'            => ['de'=>'MHD','en'=>'Best before','zh'=>'保质期'],
        'rueckverfolgung'=> ['de'=>'Rückverfolgbarkeit (eingesetzte Rohstoffe)','en'=>'Traceability (materials used)','zh'=>'可追溯性（所用原料）'],
        'lot'            => ['de'=>'Lot','en'=>'Lot','zh'=>'批次'],
        'zutat_hinzu'    => ['de'=>'Rohstoff-Lot hinzufügen','en'=>'Add material lot','zh'=>'添加原料批次'],
        'keine_daten'    => ['de'=>'Noch keine Einträge.','en'=>'No entries yet.','zh'=>'暂无记录。'],
        'anzahl_kunden'  => ['de'=>'Kunden','en'=>'Customers','zh'=>'客户数'],
        'anzahl_angebote'=> ['de'=>'Angebote','en'=>'Quotes','zh'=>'报价数'],
        'umsatz_offen'   => ['de'=>'Offene Rechnungen','en'=>'Open invoices','zh'=>'未结发票'],
        'umsatz_bezahlt' => ['de'=>'Bezahlt','en'=>'Paid','zh'=>'已付'],
        'ki_nicht_bereit'=> ['de'=>'Die KI ist nur auf dem Server (beta) aktiv. Lokal wird eine einfache Berechnung genutzt.','en'=>'AI runs only on the server (beta). Locally a simple calculation is used.','zh'=>'AI 仅在服务器（beta）上运行。本地使用简单计算。'],
        'willkommen'     => ['de'=>'Willkommen in der CRM-Demo','en'=>'Welcome to the CRM demo','zh'=>'欢迎使用 CRM 演示'],
        'position_hinzu' => ['de'=>'+ Position','en'=>'+ Item','zh'=>'+ 项目'],
        'rezeptur'       => ['de'=>'Rezeptur','en'=>'Formulation','zh'=>'配方'],
        'aus_rezeptur'   => ['de'=>'Aus Rezeptur übernehmen','en'=>'Add from formulation','zh'=>'从配方添加'],
        'als_rezeptur'   => ['de'=>'In Katalog aufnehmen','en'=>'Add to catalog','zh'=>'加入目录'],
        'als_rezeptur_hint'=>['de'=>'Neue Positionen, die hier angehakt sind, landen als Rezeptur im Katalog – beim nächsten Angebot direkt wählbar.','en'=>'New items ticked here are saved to the formulation catalog – selectable in the next quote.','zh'=>'勾选的新项目会保存到配方目录，下次报价可直接选择。'],
        'beschreibung'   => ['de'=>'Beschreibung','en'=>'Description','zh'=>'说明'],
        'zutaten'        => ['de'=>'Zutaten','en'=>'Ingredients','zh'=>'成分'],
        'verwendet'      => ['de'=>'Verwendet','en'=>'Used','zh'=>'使用次数'],
        'vorgestellt_bei'=> ['de'=>'Vorgestellt bei','en'=>'Presented to','zh'=>'已介绍给'],
        'erstellt_von'   => ['de'=>'Erstellt von','en'=>'Created by','zh'=>'创建人'],
        'katalog_suche'  => ['de'=>'Katalog durchsuchen (Name, Kategorie, Zutat)','en'=>'Search catalog (name, category, ingredient)','zh'=>'搜索目录（名称、类别、成分）'],
        'in_katalog'     => ['de'=>'In den Rezeptur-Katalog speichern','en'=>'Save to formulation catalog','zh'=>'保存到配方目录'],
        'gespeichert'    => ['de'=>'Im Katalog','en'=>'In catalog','zh'=>'已在目录'],
        // Rezeptur-Workflow
        'neue_rezeptur'  => ['de'=>'+ Neue Rezeptur','en'=>'+ New formulation','zh'=>'+ 新配方'],
        'rez_status'     => ['de'=>'Status','en'=>'Status','zh'=>'状态'],
        'rs_entwurf'     => ['de'=>'Entwurf','en'=>'Draft','zh'=>'草稿'],
        'rs_freigegeben' => ['de'=>'Freigegeben','en'=>'Approved','zh'=>'已批准'],
        'rs_kalkuliert'  => ['de'=>'Kalkuliert','en'=>'Priced','zh'=>'已核价'],
        'rez_freigeben'  => ['de'=>'Rezeptur freigeben (Entwicklung/Produktion)','en'=>'Approve formulation (R&D/Production)','zh'=>'批准配方（研发/生产）'],
        'rez_an_pricing' => ['de'=>'Nach Freigabe: Pricing berechnet den Preis.','en'=>'After approval: Pricing calculates the price.','zh'=>'批准后：定价核算价格。'],
        'rez_preis'      => ['de'=>'Preis (je Einheit)','en'=>'Price (per unit)','zh'=>'单价'],
        'rez_preis_setzen'=>['de'=>'Preis berechnen & übernehmen','en'=>'Set price','zh'=>'核算并保存价格'],
        'freigegeben_von'=> ['de'=>'Freigegeben von','en'=>'Approved by','zh'=>'批准人'],
        // Rohstoff-Nummer + Dokumente
        'roh_nummer'     => ['de'=>'Rohstoff-Nr.','en'=>'Material no.','zh'=>'原料编号'],
        'dokumente'      => ['de'=>'Dokumente','en'=>'Documents','zh'=>'文件'],
        'dok_hochladen'  => ['de'=>'Dokument hochladen (PDF/Bild)','en'=>'Upload document (PDF/image)','zh'=>'上传文件（PDF/图片）'],
        'dok_ansehen'    => ['de'=>'Ansehen','en'=>'View','zh'=>'查看'],
        'roh_neu'        => ['de'=>'+ Neuer Rohstoff','en'=>'+ New material','zh'=>'+ 新原料'],
        'zutat_katalog'  => ['de'=>'Zutat aus Katalog (unbekannte werden neu angelegt)','en'=>'Ingredient from catalog (unknown ones are created)','zh'=>'从目录选择成分（未知项将新建）'],
        // Positionstyp im Angebot
        'typ'            => ['de'=>'Typ','en'=>'Type','zh'=>'类型'],
        'einheit'        => ['de'=>'Einheit','en'=>'Unit','zh'=>'单位'],
        'typ_produkt'    => ['de'=>'Fertigprodukt (Rezeptur)','en'=>'Finished product (formulation)','zh'=>'成品（配方）'],
        'typ_rohstoff'   => ['de'=>'Rohstoff','en'=>'Raw material','zh'=>'原料'],
        'typ_frei'       => ['de'=>'Freie Position','en'=>'Free item','zh'=>'自由项目'],
        'plus_produkt'   => ['de'=>'+ Fertigprodukt','en'=>'+ Finished product','zh'=>'+ 成品'],
        'plus_rohstoff'  => ['de'=>'+ Rohstoff','en'=>'+ Raw material','zh'=>'+ 原料'],
        'plus_frei'      => ['de'=>'+ Freie Position','en'=>'+ Free item','zh'=>'+ 自由项目'],
        'mg_je_einheit'  => ['de'=>'mg je Einheit','en'=>'mg per unit','zh'=>'每单位 mg'],
        'zutat_zeile'    => ['de'=>'+ Rohstoff-Zeile','en'=>'+ ingredient row','zh'=>'+ 原料行'],
        // Profil / CRM
        'bearbeiten'     => ['de'=>'Bearbeiten','en'=>'Edit','zh'=>'编辑'],
        'kundennummer'   => ['de'=>'Kundennummer','en'=>'Customer no.','zh'=>'客户编号'],
        'betreuer'       => ['de'=>'Betreuer (Verkauf)','en'=>'Account manager','zh'=>'客户经理'],
        'zahlungsziel'   => ['de'=>'Zahlungsziel (Tage)','en'=>'Payment terms (days)','zh'=>'付款期限（天）'],
        'liefer_adresse' => ['de'=>'Lieferadresse','en'=>'Delivery address','zh'=>'收货地址'],
        'branche'        => ['de'=>'Branche','en'=>'Industry','zh'=>'行业'],
        'stammdaten'     => ['de'=>'Stammdaten','en'=>'Master data','zh'=>'主数据'],
        // COA/Spec-Reader
        'coa_intro'      => ['de'=>'Lieferanten-COA oder -Spezifikation (auch chinesisch) einfügen. Die KI liest die Werte aus, legt den Rohstoff an, falls er fehlt, und erzeugt ein Kunden-COA in der gewählten Sprache.','en'=>'Paste a supplier COA or specification (Chinese too). The AI extracts the values, creates the material if missing, and produces a customer COA in the chosen language.','zh'=>'粘贴供应商 COA 或规格（中文亦可）。AI 会提取数值，若原料不存在则创建，并生成所选语言的客户 COA。'],
        'quelle_text'    => ['de'=>'COA/Spec-Text','en'=>'COA/Spec text','zh'=>'COA/规格文本'],
        'zielsprache'    => ['de'=>'Zielsprache (Kunde)','en'=>'Target language (customer)','zh'=>'目标语言（客户）'],
        'auslesen'       => ['de'=>'Auslesen & Kunden-COA erstellen','en'=>'Extract & build customer COA','zh'=>'提取并生成客户 COA'],
        'coa'            => ['de'=>'Kunden-COA','en'=>'Customer COA','zh'=>'客户 COA'],
        'coa_liste'      => ['de'=>'Erstellte COAs','en'=>'Created COAs','zh'=>'已生成的 COA'],
        'parameter'      => ['de'=>'Parameter','en'=>'Parameter','zh'=>'参数'],
        'wert'           => ['de'=>'Wert','en'=>'Result','zh'=>'结果'],
        'grenzwert'      => ['de'=>'Spezifikation','en'=>'Specification','zh'=>'规格'],
        'methode'        => ['de'=>'Methode','en'=>'Method','zh'=>'方法'],
        'coa_neu_rohstoff'=>['de'=>'Neuer Rohstoff angelegt','en'=>'New material created','zh'=>'已创建新原料'],
        'coa_bestehend'  => ['de'=>'Bestehender Rohstoff erkannt','en'=>'Existing material matched','zh'=>'匹配到已有原料'],
        'chargen_coas'   => ['de'=>'Chargen & COAs','en'=>'Batches & COAs','zh'=>'批次与 COA'],
        'coa_einlesen'   => ['de'=>'COA einlesen (chinesisch → Kunden-COA)','en'=>'Read COA (Chinese → customer COA)','zh'=>'读取 COA（中文 → 客户 COA）'],
        'coa_charge_hint'=> ['de'=>'Beim Einlesen wird die Charge erkannt und als Batch unter diesem Rohstoff gespeichert.','en'=>'On import the batch number is detected and stored under this material.','zh'=>'导入时会识别批号并保存到该原料下。'],
        // Settings
        'set_briefkopf'  => ['de'=>'Briefkopf','en'=>'Letterhead','zh'=>'抬头'],
        'set_standard'   => ['de'=>'Standardwerte','en'=>'Defaults','zh'=>'默认值'],
        'std_waehrung'   => ['de'=>'Standard-Währung','en'=>'Default currency','zh'=>'默认货币'],
        'std_ust'        => ['de'=>'Standard-USt (%)','en'=>'Default VAT (%)','zh'=>'默认增值税 (%)'],
        'std_zahlungsziel'=>['de'=>'Standard-Zahlungsziel (Tage)','en'=>'Default payment terms (days)','zh'=>'默认付款期限（天）'],
        'set_mitarbeiter'=> ['de'=>'Mitarbeiter','en'=>'Employees','zh'=>'员工'],
        'set_dokument'   => ['de'=>'Beleg-Vorgaben','en'=>'Document defaults','zh'=>'单据默认'],
        // Ablehnung / Bestaetigung
        'ablehnen'       => ['de'=>'Ablehnen','en'=>'Reject','zh'=>'拒绝'],
        'ablehnungsgrund'=> ['de'=>'Ablehnungsgrund (warum hat der Kunde abgelehnt?)','en'=>'Rejection reason (why did the customer decline?)','zh'=>'拒绝原因（客户为何拒绝？）'],
        'grund_pflicht'  => ['de'=>'Bitte einen Grund angeben.','en'=>'Please provide a reason.','zh'=>'请填写原因。'],
        'bestaetigt'     => ['de'=>'Vom Kunden bestätigt','en'=>'Confirmed by customer','zh'=>'客户已确认'],
        'nicht_bestaetigt'=>['de'=>'Noch nicht bestätigt','en'=>'Not yet confirmed','zh'=>'尚未确认'],
        // Fraud
        'fraud'          => ['de'=>'Betrugsverdacht','en'=>'Fraud','zh'=>'欺诈嫌疑'],
        'fraud_markieren'=> ['de'=>'Als Betrug markieren','en'=>'Flag as fraud','zh'=>'标记为欺诈'],
        'fraud_aufheben' => ['de'=>'Markierung aufheben','en'=>'Remove flag','zh'=>'取消标记'],
        'fraud_warnung'  => ['de'=>'Achtung: Dieser Kunde ist als Betrugsverdacht markiert.','en'=>'Warning: this customer is flagged as fraud.','zh'=>'警告：该客户被标记为欺诈嫌疑。'],
        // Beleg-Felder
        'beleg_notiz'    => ['de'=>'Notiz (erscheint auf dem Beleg)','en'=>'Note (shown on the document)','zh'=>'备注（显示在单据上）'],
        'zahlungsbed'    => ['de'=>'Zahlungsbedingungen','en'=>'Payment terms','zh'=>'付款条件'],
        'versandart'     => ['de'=>'Versandart','en'=>'Shipping method','zh'=>'运输方式'],
        'bankverbindung' => ['de'=>'Bankverbindung','en'=>'Bank details','zh'=>'银行信息'],
        'beleg_speichern'=> ['de'=>'Beleg-Angaben speichern','en'=>'Save document details','zh'=>'保存单据信息'],
        // Mitarbeiter / Zuordnung
        'mitarbeiter'    => ['de'=>'Mitarbeiter','en'=>'Employee','zh'=>'员工'],
        'zugeordnet'     => ['de'=>'Zugeordnet','en'=>'Assigned to','zh'=>'负责人'],
        'zuordnen'       => ['de'=>'Zuordnen','en'=>'Assign','zh'=>'分配'],
        'nur_admin_zuordnen'=>['de'=>'Nur Admin kann Kunden zuordnen.','en'=>'Only admin can assign customers.','zh'=>'仅管理员可分配客户。'],
        'signatur'       => ['de'=>'Unterschrift','en'=>'Signature','zh'=>'签名'],
        'stempel'        => ['de'=>'Stempel','en'=>'Stamp','zh'=>'印章'],
        'signatur_hoch'  => ['de'=>'Unterschrift hochladen (PNG/JPG)','en'=>'Upload signature (PNG/JPG)','zh'=>'上传签名（PNG/JPG）'],
        'stempel_hoch'   => ['de'=>'Stempel hochladen (PNG/JPG)','en'=>'Upload stamp (PNG/JPG)','zh'=>'上传印章（PNG/JPG）'],
        // Globale Suche / Dublette
        'suche_global'   => ['de'=>'Suche: E-Mail, Firma, Telefon','en'=>'Search: email, company, phone','zh'=>'搜索：邮箱、公司、电话'],
        'dublette_titel' => ['de'=>'Kunde ist bereits im System','en'=>'Customer already exists','zh'=>'客户已存在'],
        'dublette_admin' => ['de'=>'Bitte Kontakt mit dem Vorgesetzten aufnehmen – dieser Kunde ist bereits angelegt.','en'=>'Please contact your supervisor – this customer already exists.','zh'=>'请联系主管 — 该客户已存在。'],
        'trotzdem_anlegen'=>['de'=>'Trotzdem anlegen (Admin)','en'=>'Create anyway (admin)','zh'=>'仍然创建（管理员）'],
        'schnellanlage'  => ['de'=>'+ Schnellanlage','en'=>'+ Quick add','zh'=>'+ 快速创建'],
        'schnellanlage_t'=> ['de'=>'Kunde schnell anlegen','en'=>'Quick-add customer','zh'=>'快速创建客户'],
        'schnell_hint'   => ['de'=>'Nur das Nötigste – danach öffnet sich das Profil zum Fertig-Bearbeiten.','en'=>'Just the essentials – the full profile opens next to finish.','zh'=>'仅填必要项 — 随后打开完整资料继续编辑。'],
        'alle_zeigen'    => ['de'=>'Alle','en'=>'All','zh'=>'全部'],
        'nur_zugeordnet' => ['de'=>'Nur zugeordnete','en'=>'Assigned only','zh'=>'仅已分配'],
        'weiter_profil'  => ['de'=>'Anlegen & Profil öffnen','en'=>'Create & open profile','zh'=>'创建并打开资料'],
        'frage'          => ['de'=>'Frage zum Rohstoff','en'=>'Material question','zh'=>'原料问题'],
        'senden'         => ['de'=>'Senden','en'=>'Send','zh'=>'发送'],
        'logo'           => ['de'=>'Logo','en'=>'Logo','zh'=>'标志'],
        'logo_hochladen' => ['de'=>'Logo hochladen (PNG/JPG)','en'=>'Upload logo (PNG/JPG)','zh'=>'上传标志（PNG/JPG）'],
        'absender'       => ['de'=>'Absender (Ihr Unternehmen)','en'=>'Sender (your company)','zh'=>'发件方（贵公司）'],
        'beleg_angebot'  => ['de'=>'ANGEBOT','en'=>'QUOTATION','zh'=>'报价单'],
        'beleg_rechnung' => ['de'=>'RECHNUNG','en'=>'INVOICE','zh'=>'发票'],
        'beleg_von'      => ['de'=>'Von','en'=>'From','zh'=>'来自'],
        'beleg_an'       => ['de'=>'An','en'=>'To','zh'=>'致'],
        'zahlbar'        => ['de'=>'Zahlbar innerhalb 14 Tagen ohne Abzug.','en'=>'Payable within 14 days net.','zh'=>'请于 14 天内付款。'],
        'ansicht_hinweis'=> ['de'=>'Nur Ansicht – kein Download in der Demo.','en'=>'View only – no download in the demo.','zh'=>'仅供查看 — 演示中不可下载。'],
    ];
}

// --- Rollen / Rechte (Demo: Admin kann jede Rolle „vorfuehren"). --------------
function cd_rollen(): array { return ['verkauf','entwicklung','pricing','produktion','buchhaltung','admin']; }
function cd_rolle(): string {
    $r = strtolower(trim((string)($_GET['rolle'] ?? '')));
    if (in_array($r, cd_rollen(), true)) { cd_meta_set('rolle', $r); return $r; }
    $s = cd_meta_get('rolle', 'admin');
    return in_array($s, cd_rollen(), true) ? $s : 'admin';
}
// Welche Module darf eine Rolle sehen?
function cd_rechte(string $rolle): array {
    $map = [
        'verkauf'     => ['dashboard','kunden','konversation','katalog','rezepturen','produktentwickler','coareader','angebote','rechnungen','chat','ki'],
        'entwicklung' => ['dashboard','katalog','rezepturen','produktentwickler','coareader','chat','ki'],
        'pricing'     => ['dashboard','katalog','rezepturen','coareader','angebote','chat','ki'],
        'produktion'  => ['dashboard','produktion','katalog','rezepturen'],
        'buchhaltung' => ['dashboard','rechnungen','finanzen','kunden','konversation'],
        'admin'       => ['dashboard','kunden','konversation','katalog','rezepturen','produktentwickler','coareader','angebote','rechnungen','produktion','chat','finanzen','ki','einstellungen'],
    ];
    return $map[$rolle] ?? $map['admin'];
}
function cd_darf(string $modul): bool { return in_array($modul, cd_rechte(cd_rolle()), true); }

// --- Geld/Währung. -----------------------------------------------------------
function cd_waehrungen(): array { return ['EUR'=>'€','USD'=>'$','CNY'=>'¥']; }
function cd_money(int $cent, string $waehrung = 'EUR'): string {
    $sym = cd_waehrungen()[$waehrung] ?? '€';
    $n = number_format($cent / 100, 2, ',', '.');
    return $waehrung === 'EUR' ? $n . ' ' . $sym : $sym . ' ' . $n;
}

// --- Briefkopf/Logo (inline base64, kein Datei-URL – bleibt isoliert). --------
function cd_logo_datauri(): string { return cd_meta_get('logo_b64', ''); }
function cd_absender(): array {
    return [
        'name'    => cd_meta_get('abs_name', 'Musterhersteller GmbH'),
        'adresse' => cd_meta_get('abs_adresse', 'Beispielstraße 1'),
        'ort'     => cd_meta_get('abs_ort', '12345 Musterstadt'),
        'land'    => cd_meta_get('abs_land', 'Deutschland'),
        'kontakt' => cd_meta_get('abs_kontakt', 'kontakt@example.com'),
        'ustid'   => cd_meta_get('abs_ustid', 'DE000000000'),
    ];
}

// --- Standardwerte (einmalige Einstellungen). --------------------------------
function cd_std(string $k, string $default = ''): string {
    static $def = ['std_waehrung'=>'EUR','std_ust'=>'19','std_zahlungsziel'=>'14',
                   'std_zahlungsbed'=>'Zahlbar innerhalb 14 Tagen ohne Abzug.',
                   'std_versandart'=>'Spedition / Kurier', 'std_bank'=>'Musterbank · IBAN DE00 0000 0000 0000 0000 00 · BIC XXXXDEXX'];
    return cd_meta_get($k, $default !== '' ? $default : ($def[$k] ?? ''));
}

// --- Rohstoff im Katalog finden oder neu anlegen (Zutaten waelen aus Katalog). -
function cd_rohstoff_find_or_create(string $name): int {
    $name = trim($name); if ($name === '') return 0;
    $id = (int) scalar("SELECT id FROM crmdemo_rohstoff WHERE name=? LIMIT 1", [$name]);
    if ($id) return $id;
    q("INSERT INTO crmdemo_rohstoff (nummer,name,kategorie,notiz) VALUES (?,?,?,?)",
      [cd_nummer('RM'), $name, null, 'Automatisch aus Rezeptur angelegt']);
    return insert_id();
}

// --- Mitarbeiter (Demo). -----------------------------------------------------
function cd_mitarbeiter_list(): array { return all("SELECT * FROM crmdemo_mitarbeiter WHERE aktiv=1 ORDER BY name"); }
function cd_mitarbeiter(int $id): ?array { return $id ? one("SELECT * FROM crmdemo_mitarbeiter WHERE id=?", [$id]) : null; }

// --- Dublettenpruefung: existiert schon ein Kunde mit gleicher Firma/E-Mail/Tel? -
function cd_kunde_dupes(string $firma, string $email, string $telefon, int $exceptId = 0): array {
    $firma = trim($firma); $email = trim($email); $telefon = preg_replace('/\D+/', '', $telefon);
    $wo = []; $pa = [];
    if ($firma !== '')   { $wo[] = "firma = ?";                 $pa[] = $firma; }
    if ($email !== '')   { $wo[] = "email = ?";                 $pa[] = $email; }
    if ($telefon !== '') { $wo[] = "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(telefon,''),' ',''),'-',''),'/',''),'+','') = ?"; $pa[] = $telefon; }
    if (!$wo) return [];
    $sql = "SELECT id, firma, email, telefon FROM crmdemo_kunde WHERE (" . implode(' OR ', $wo) . ")";
    if ($exceptId) { $sql .= " AND id <> ?"; $pa[] = $exceptId; }
    return all($sql, $pa);
}

// --- KI-COA/Spec-Reader: Lieferantentext -> strukturierte Werte in Zielsprache. -
// Gibt ['ok'=>bool,'daten'=>[...]] zurueck. Ohne API-Schluessel: ok=false.
function cd_coa_extract(string $text, string $ziel): array {
    require_once __DIR__ . '/ki.php';
    if (!function_exists('ki_bereit') || !ki_bereit()) return ['ok'=>false, 'daten'=>null];
    $lang = ['de'=>'Deutsch','en'=>'Englisch','zh'=>'Chinesisch'][$ziel] ?? 'Deutsch';
    $sys = 'Du liest Analysenzertifikate (COA) und Spezifikationen von Rohstoff-Lieferanten für '
         . 'Nahrungsergänzung, auch auf Chinesisch. Übersetze Parameter-Bezeichnungen nach ' . $lang . '. '
         . 'Gib NUR JSON: {"produkt":"","charge":"","kategorie":"","wirkstoff":"","gehalt":"","herkunft":"",'
         . '"analytik":[{"parameter":"","wert":"","grenzwert":"","methode":""}]}. '
         . 'Zahlen und Einheiten unverändert übernehmen. Nichts erfinden – fehlende Felder leer lassen.';
    $r = ki_json('COA/Spezifikation:' . "\n" . mb_substr($text, 0, 6000), ['system'=>$sys]);
    return ['ok'=>!empty($r['ok']) && !empty($r['daten']), 'daten'=>$r['daten'] ?? null];
}

// --- Eigenständiges Layout (nutzt app.css, eigenes Menü, Sprach-/Rollenwahl). -
function cd_url(string $m, array $extra = []): string {
    $q = array_merge(['p' => 'crmdemo', 'm' => $m], $extra);
    return '?' . http_build_query($q);
}
function cd_head(string $titel): void {
    $cssV = (int) @filemtime(BX_ROOT . '/public/assets/app.css');
    $l = cd_lang();
    echo '<!doctype html><html lang="' . h($l) . '"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . h($titel . ' · ' . cd_t('app')) . '</title>'
       . '<link rel="stylesheet" href="assets/app.css?v=' . $cssV . '">'
       . '<style>.cd-a4{background:#fff;color:#111;max-width:820px;margin:0 auto;padding:44px 52px;border:1px solid var(--line);box-shadow:0 1px 8px rgba(0,0,0,.08)}'
       . '.cd-a4 table{width:100%;border-collapse:collapse}.cd-a4 h1{font-size:22px;letter-spacing:2px;margin:0}'
       . '.cd-a4 .cd-th{border-bottom:2px solid #222}.cd-a4 td,.cd-a4 th{padding:7px 6px;font-size:13px}'
       . '.cd-match{height:8px;border-radius:5px;background:var(--line);overflow:hidden}.cd-match>span{display:block;height:100%;background:var(--gruen,#2f8f5b)}'
       . '.cd-rolchips a{display:inline-block;padding:2px 9px;border:1px solid var(--line);border-radius:20px;font-size:12px;margin:2px 4px 0 0;color:var(--muted);text-decoration:none}'
       . '.cd-rolchips a.on{background:var(--gruen,#2f8f5b);color:#fff;border-color:transparent}'
       . '.cd-az{display:flex;flex-wrap:wrap;gap:3px}.cd-az a{padding:2px 7px;border-radius:6px;font-size:12px;color:var(--muted);text-decoration:none}'
       . '.cd-az a.on{background:var(--gruen,#2f8f5b);color:#fff}'
       . '.cd-konv{display:block;text-decoration:none;color:var(--text,inherit);border-left:3px solid var(--line);border-bottom:1px solid var(--line);border-radius:6px;padding:8px 12px;margin:2px 0;transition:background .12s}'
       . '.cd-konv:hover{background:rgba(127,127,127,.14)}.cd-konv .cd-firma{color:var(--gruen,#2f8f5b);font-weight:600}'
       . '.bx-table tr.cd-click{cursor:pointer}.bx-table tr.cd-click:hover{background:rgba(127,127,127,.10)}'
       . '.cd-tabbar{display:flex;flex-wrap:wrap;gap:4px;border-bottom:1px solid var(--line);margin:16px 0 16px}'
       . '.cd-tabbar a{padding:8px 14px;border-radius:8px 8px 0 0;font-size:14px;color:var(--muted);text-decoration:none;border:1px solid transparent;border-bottom:none;margin-bottom:-1px}'
       . '.cd-tabbar a.on{color:var(--text);background:var(--panel);border-color:var(--line)}'
       . '.cd-modal{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:flex-start;justify-content:center;z-index:60;padding:56px 16px}'
       . '.cd-modal.on{display:flex}.cd-modal .box{background:var(--panel,#fff);color:var(--text,#111);border:1px solid var(--line);border-radius:12px;max-width:460px;width:100%;padding:22px;box-shadow:0 12px 44px rgba(0,0,0,.32)}</style>'
       . '<script>(function(){try{var t=localStorage.getItem("bx-theme");if(t==="dark"||t==="light")document.documentElement.setAttribute("data-theme",t);}catch(e){}})();</script>'
       . '</head><body>';
}
function cd_shell_start(string $aktiv): void {
    $rolle = cd_rolle();
    // Flache Navigation. Die einzelnen KI-Funktionen erscheinen NICHT im Menue,
    // sondern jeweils als Button auf der passenden Seite + gebuendelt unter "KI-Werkzeuge".
    $menu = ['dashboard','kunden','konversation','angebote','rechnungen','rezepturen','katalog','produktion','finanzen','ki','einstellungen'];
    $l = cd_lang();
    echo '<div class="bx-shell"><aside class="bx-side">'
       . '<div class="bx-brand"><img src="assets/bulkify-logo-white.png" alt="" class="bx-logo"><span class="bx-ver">' . h(cd_t('app')) . '</span></div>'
       . '<nav>';
    foreach ($menu as $key) {
        if (!cd_darf($key)) continue;
        echo '<a href="' . h(cd_url($key)) . '"' . ($aktiv === $key ? ' class="on"' : '') . '>' . h(cd_t($key)) . '</a>';
    }
    // Rollen-Umschalter (Demo) ------------------------------------------------
    echo '<div class="bx-navgroup" style="margin-top:14px">' . h(cd_t('rolle')) . '</div><div class="cd-rolchips" style="padding:0 14px 6px">';
    foreach (cd_rollen() as $r)
        echo '<a href="' . h(cd_url($aktiv, ['rolle'=>$r])) . '"' . ($rolle === $r ? ' class="on"' : '') . '>' . h(cd_t('r_' . $r)) . '</a>';
    echo '</div>';
    // Sprache + Theme + Verlassen --------------------------------------------
    echo '<div class="bx-userbox"><div class="cd-rolchips">';
    foreach (['de'=>'DE','en'=>'EN','zh'=>'中文'] as $lc=>$lbl)
        echo '<a href="' . h(cd_url($aktiv, ['lang'=>$lc])) . '"' . ($l === $lc ? ' class="on"' : '') . '>' . h($lbl) . '</a>';
    echo '</div><div class="bx-row" style="gap:14px;margin-top:10px;flex-wrap:wrap">'
       . '<button type="button" class="bx-themebtn" data-dunkel="Dunkel" data-hell="Hell">Dunkel/Hell</button>'
       . '<a class="muted" style="font-size:12px" href="?p=einstellungen&tab=crmdemo">' . h(cd_t('verlassen')) . '</a>'
       . '</div></div></nav></aside><main class="bx-main">';
}
function cd_shell_ende(): void {
    echo '</main></div>';
    if (function_exists('bx_theme_script')) echo bx_theme_script();
    if (function_exists('bx_busy_script')) echo bx_busy_script();
    echo '</body></html>';
}
// Zaun: Modul fuer aktuelle Rolle gesperrt -> Hinweis + Abbruch.
function cd_gate(string $modul): void {
    if (cd_darf($modul)) return;
    echo '<div class="bx-panel" style="max-width:520px"><h2 style="margin-top:0">' . h(cd_t('kein_recht')) . '</h2>'
       . '<p class="muted">' . h(cd_t('rolle')) . ': ' . h(cd_t('r_' . cd_rolle())) . '</p>'
       . '<a class="btn btn-ghost" href="' . h(cd_url('dashboard')) . '">' . h(cd_t('dashboard')) . '</a></div>';
    cd_shell_ende();
    exit;
}

// --- Kennzahlen (nur eigene Tabellen). ---------------------------------------
function crmdemo_kennzahlen(): array {
    return [
        'kunden'    => (int) scalar("SELECT COUNT(*) FROM crmdemo_kunde"),
        'angebote'  => (int) scalar("SELECT COUNT(*) FROM crmdemo_angebot"),
        'offen'     => (int) scalar("SELECT COALESCE(SUM(brutto_cent),0) FROM crmdemo_rechnung WHERE status='offen'"),
        'bezahlt'   => (int) scalar("SELECT COALESCE(SUM(brutto_cent),0) FROM crmdemo_rechnung WHERE status='bezahlt'"),
        'produktion'=> (int) scalar("SELECT COUNT(*) FROM crmdemo_produktion WHERE status<>'fertig'"),
        'rohstoffe' => (int) scalar("SELECT COUNT(*) FROM crmdemo_rohstoff WHERE aktiv=1"),
    ];
}

// --- Lokale Ähnlichkeitssuche (ohne KI): Token-Overlap + Zahlnaehe. -----------
// Loest das „Ashwagandha 350 mg" vs „360 mg"-Beispiel auch ohne API-Schluessel.
function cd_similarity(string $anfrage, array $roh): float {
    $norm = function(string $s): array {
        $s = mb_strtolower($s);
        $s = preg_replace('/[^\p{L}\p{N}%\.\s]/u', ' ', $s);
        return array_values(array_filter(preg_split('/\s+/u', $s)));
    };
    $ziel = ($roh['name'] ?? '') . ' ' . ($roh['wirkstoff'] ?? '') . ' ' . ($roh['gehalt'] ?? '') . ' ' . ($roh['kategorie'] ?? '') . ' ' . ($roh['form'] ?? '');
    $a = $norm($anfrage); $b = $norm($ziel);
    if (!$a || !$b) return 0.0;
    // Wort-Overlap (Jaccard-artig, gewichtet zugunsten der Anfrage).
    $wortTreffer = 0; $zahlenA = []; $zahlenB = [];
    foreach ($a as $w) { if (preg_match('/^\d+(?:[.,]\d+)?%?$/', $w)) $zahlenA[] = (float) str_replace([',','%'], ['.',''], $w); }
    foreach ($b as $w) { if (preg_match('/^\d+(?:[.,]\d+)?%?$/', $w)) $zahlenB[] = (float) str_replace([',','%'], ['.',''], $w); }
    $setB = array_unique($b);
    foreach (array_unique($a) as $w) if (in_array($w, $setB, true)) $wortTreffer++;
    $wortScore = $wortTreffer / max(1, count(array_unique($a)));
    // Zahlnaehe: bester relativer Abstand zwischen je einer Zahl aus A und B.
    $zahlScore = 0.0;
    if ($zahlenA && $zahlenB) {
        $best = 1.0;
        foreach ($zahlenA as $x) foreach ($zahlenB as $y) {
            $d = abs($x - $y) / max(1.0, max($x, $y));
            if ($d < $best) $best = $d;
        }
        $zahlScore = 1.0 - $best; // 1 = identisch, 0 = weit weg
    }
    // Gesamt: Wortueberlappung dominiert, Zahlnaehe verfeinert.
    $score = 0.7 * $wortScore + 0.3 * $zahlScore;
    return max(0.0, min(1.0, $score));
}

// --- Löschen / Reset / Seed. -------------------------------------------------
function crmdemo_loeschen(): void {
    $pdo = db();
    foreach (crmdemo_tabellen() as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
    $pdo->exec("DROP TABLE IF EXISTS crmdemo_meta");
}
function crmdemo_reset(): void {
    crmdemo_schema();
    foreach (crmdemo_tabellen() as $t) q("DELETE FROM $t");
    q("DELETE FROM crmdemo_meta WHERE k LIKE 'seq_%'");
}
function crmdemo_seed(): void {
    crmdemo_schema();
    // Abschnittsweise idempotent: jede Sektion nur seeden, wenn ihre Tabelle leer ist.
    if ((int) scalar("SELECT COUNT(*) FROM crmdemo_mitarbeiter") === 0)
        q("INSERT INTO crmdemo_mitarbeiter (name,rolle,email) VALUES
            ('Max Sales','verkauf','max@example.com'),
            ('Julia Preis','pricing','julia@example.com'),
            ('Chen Li','verkauf','chen@example.com')");
    $k1 = 0; $k3 = 0;
    if ((int) scalar("SELECT COUNT(*) FROM crmdemo_kunde") === 0) {
        // Kunden (mit Sprache/Waehrung fuer Belege) ---------------------------
        q("INSERT INTO crmdemo_kunde (firma,ansprechpartner,email,land,sprache,waehrung,adresse,plz,ort,segment,notiz) VALUES
            ('Nordic Wellness AB','Erik Lind','erik@nordicwellness.se','SE','en','EUR','Storgatan 12','11122','Stockholm','Handelsmarke','Interesse an Magnesium-Linie'),
            ('VitaPrime GmbH','Anna Weber','anna@vitaprime.de','DE','de','EUR','Industrieweg 4','40468','Düsseldorf','Eigenmarke','3 Produkte geplant'),
            ('Sunrise Health Co., Ltd.','Li Wei','li.wei@sunrisehealth.cn','CN','zh','CNY','建国路 88 号','100022','北京','Distributor','Ashwagandha & Vitamin D3')");
        $ks = all("SELECT id FROM crmdemo_kunde ORDER BY id");
        $k1 = (int)$ks[0]['id']; $k3 = (int)$ks[2]['id'];
        // Kunden den Mitarbeitern zuordnen (Demo).
        $mit = all("SELECT id FROM crmdemo_mitarbeiter ORDER BY id");
        if (count($mit) >= 3) {
            q("UPDATE crmdemo_kunde SET betreuer_id=? WHERE id=?", [(int)$mit[0]['id'], $k1]);
            q("UPDATE crmdemo_kunde SET betreuer_id=? WHERE id=?", [(int)$mit[0]['id'], (int)$ks[1]['id']]);
            q("UPDATE crmdemo_kunde SET betreuer_id=? WHERE id=?", [(int)$mit[2]['id'], $k3]);
        }
        $k2 = (int)$ks[1]['id'];
        q("INSERT INTO crmdemo_mail (kunde_id,richtung,betreff,text) VALUES
            (?, 'ein','Anfrage Ashwagandha 350 mg','Hallo, könnt ihr Ashwagandha 350 mg mit 5% Withanoliden anbieten? Menge 500 kg.'),
            (?, 'aus','Re: Anfrage Ashwagandha 350 mg','Gern – wir haben 360 mg / 5% im Katalog, das passt technisch. Angebot folgt.'),
            (?, 'ein','Muster Magnesium Complex','Können wir vorab 3 Muster der Magnesium-Kapseln bekommen?'),
            (?, 'aus','Re: Muster Magnesium Complex','Klar, Muster gehen morgen raus. Tracking folgt per Mail.'),
            (?, 'ein','Frage zur Haltbarkeit','Wie lange ist das Vitamin D3+K2 haltbar und wie lagern wir es am besten?'),
            (?, 'aus','Re: Frage zur Haltbarkeit','Mindestens 24 Monate, kühl und trocken lagern. Details im Produktinfoblatt.'),
            (?, 'ein','Nachbestellung geplant','Wir planen eine Nachbestellung Magnesium Complex für Q3, bitte Angebot.')", [$k3,$k3,$k1,$k1,$k2,$k2,$k1]);
    }
    if ((int) scalar("SELECT COUNT(*) FROM crmdemo_produkt") === 0)
        q("INSERT INTO crmdemo_produkt (name,form,idee) VALUES ('Magnesium Complex','kapsel','Magnesium für Muskeln & Nerven, gut verträglich')");
    if ((int) scalar("SELECT COUNT(*) FROM crmdemo_rezeptur") === 0) {
        $z1 = json_encode([['name'=>'Magnesiumcitrat','menge_mg'=>375],['name'=>'Vitamin B6','menge_mg'=>1.4]], JSON_UNESCAPED_UNICODE);
        $z2 = json_encode([['name'=>'Ashwagandha-Extrakt (5% Withanolide)','menge_mg'=>360],['name'=>'Schwarzer Pfeffer-Extrakt','menge_mg'=>5]], JSON_UNESCAPED_UNICODE);
        $z3 = json_encode([['name'=>'Vitamin D3','menge_mg'=>0.025],['name'=>'Vitamin K2 (MK-7)','menge_mg'=>0.075]], JSON_UNESCAPED_UNICODE);
        q("INSERT INTO crmdemo_rezeptur (name,form,kategorie,beschreibung,zutaten,erstellt_von,verwendet,status,freigabe_von,freigabe_am,preis_cent) VALUES
            ('Magnesium Complex','kapsel','Mineralstoffe','Magnesium für Muskeln & Nerven, gut verträglich',?, 'verkauf', 1,'kalkuliert','entwicklung',NOW(),249),
            ('Ashwagandha 360 mg','kapsel','Pflanzenextrakte','Adaptogen, standardisiert auf 5% Withanolide',?, 'verkauf', 2,'freigegeben','produktion',NOW(),NULL),
            ('Vitamin D3 + K2','kapsel','Vitamine','Klassische Kombination für Knochen & Immunsystem',?, 'verkauf', 0,'entwurf',NULL,NULL,NULL)",
            [$z1,$z2,$z3]);
    }
    if ((int) scalar("SELECT COUNT(*) FROM crmdemo_rohstoff") === 0) {
        // Rohstoff-Katalog ----------------------------------------------------
        q("INSERT INTO crmdemo_rohstoff (name,kategorie,wirkstoff,gehalt,form,herkunft,moq_kg,notiz) VALUES
            ('Ashwagandha-Extrakt (5% Withanolide)','Pflanzenextrakt','Withania somnifera','5% Withanolide','Pulver','IN',25,'KSM-artig, wasserlöslich'),
            ('Ashwagandha-Wurzelpulver','Pflanzenpulver','Withania somnifera','roh, unstandardisiert','Pulver','IN',50,'günstige Variante'),
            ('Magnesiumcitrat','Mineralstoff','Magnesium','16% elementar','Pulver','DE',100,NULL),
            ('Vitamin D3 100.000 I.E./g','Vitamin','Cholecalciferol','100.000 I.E./g','Öl/Pulver','CH',5,NULL),
            ('Kurkuma-Extrakt 95%','Pflanzenextrakt','Curcuma longa','95% Curcumin','Pulver','IN',25,NULL)");
        $r1 = (int) scalar("SELECT id FROM crmdemo_rohstoff WHERE name LIKE 'Ashwagandha-Extrakt%' LIMIT 1");
        q("INSERT INTO crmdemo_rohstoff_preis (rohstoff_id,datum,preis_cent,waehrung,lieferant) VALUES
            (?, DATE_SUB(CURDATE(),INTERVAL 180 DAY), 4200,'EUR','Shandong'),
            (?, DATE_SUB(CURDATE(),INTERVAL 90 DAY), 3900,'EUR','Shandong'),
            (?, DATE_SUB(CURDATE(),INTERVAL 20 DAY), 3750,'EUR','Nutra Yunnan')", [$r1,$r1,$r1]);
    }

    // Nummern nachziehen (idempotent): jede Rezeptur RZ-…, jeder Rohstoff RM-….
    foreach (all("SELECT id FROM crmdemo_rohstoff WHERE nummer IS NULL OR nummer='' ORDER BY id") as $r)
        q("UPDATE crmdemo_rohstoff SET nummer=? WHERE id=?", [cd_nummer('RM'), (int)$r['id']]);
    foreach (all("SELECT id FROM crmdemo_rezeptur WHERE nummer IS NULL OR nummer='' ORDER BY id") as $r)
        q("UPDATE crmdemo_rezeptur SET nummer=? WHERE id=?", [cd_nummer('RZ'), (int)$r['id']]);

    // --- Beleg-Demo: je ein Angebot pro Status + Rechnungen + Produktion. -----
    // So laesst sich jeder Schritt der Prozesskette komplett durchklicken.
    if ((int) scalar("SELECT COUNT(*) FROM crmdemo_angebot") > 0) return;
    $cust = all("SELECT id, waehrung FROM crmdemo_kunde ORDER BY id");
    if (count($cust) < 3) return;
    $nordic = (int)$cust[0]['id']; $vita = (int)$cust[1]['id']; $sun = (int)$cust[2]['id'];
    $rezMag = (int) scalar("SELECT id FROM crmdemo_rezeptur WHERE name='Magnesium Complex' LIMIT 1");
    $rezAsh = (int) scalar("SELECT id FROM crmdemo_rezeptur WHERE name='Ashwagandha 360 mg' LIMIT 1");
    $rezD3  = (int) scalar("SELECT id FROM crmdemo_rezeptur WHERE name='Vitamin D3 + K2' LIMIT 1");
    $rohKur = (int) scalar("SELECT id FROM crmdemo_rohstoff WHERE name LIKE 'Kurkuma%' LIMIT 1");

    // Angebot + Positionen anlegen; row = [bez, menge, preis_cent, typ, einheit, rezeptur_id, rohstoff_id].
    $mkAng = function (int $kid, string $titel, string $wae, string $status, array $rows, array $opt = []) : int {
        q("INSERT INTO crmdemo_angebot (nummer,kunde_id,titel,waehrung,status,zahlungsbedingungen,versandart,notiz,ablehnungsgrund,gueltig_bis) VALUES (?,?,?,?,?,?,?,?,?,?)",
          [cd_nummer('AN'), $kid, $titel, $wae, $status,
           $opt['zb'] ?? cd_std('std_zahlungsbed'), $opt['va'] ?? cd_std('std_versandart'),
           $opt['notiz'] ?? null, $opt['grund'] ?? null, $opt['gueltig'] ?? date('Y-m-d', strtotime('+30 days'))]);
        $aid = insert_id(); $sort = 0; $netto = 0;
        foreach ($rows as $r) {
            q("INSERT INTO crmdemo_angebot_pos (angebot_id,bezeichnung,menge,einheit,preis_cent,typ,rezeptur_id,rohstoff_id,sort) VALUES (?,?,?,?,?,?,?,?,?)",
              [$aid, $r[0], $r[1], $r[4], $r[2], $r[3], $r[5] ?: null, $r[6] ?: null, $sort++]);
            $netto += (int) round($r[1] * $r[2]);
        }
        q("UPDATE crmdemo_angebot SET netto_cent=? WHERE id=?", [$netto, $aid]);
        return $aid;
    };
    // Rechnung aus einem Angebot ableiten.
    $mkRech = function (int $aid, string $status) : int {
        $a = one("SELECT * FROM crmdemo_angebot WHERE id=?", [$aid]);
        $ust = (float) cd_std('std_ust','19'); $netto = (int)$a['netto_cent']; $brutto = (int) round($netto * (1 + $ust/100));
        $datum = $status === 'bezahlt' ? date('Y-m-d', strtotime('-18 days')) : date('Y-m-d', strtotime('-2 days'));
        q("INSERT INTO crmdemo_rechnung (nummer,kunde_id,angebot_id,waehrung,netto_cent,ust_prozent,brutto_cent,status,datum,zahlungsbedingungen,versandart,bankverbindung) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
          [cd_nummer('RE'), $a['kunde_id'], $aid, $a['waehrung'], $netto, $ust, $brutto, $status, $datum,
           $a['zahlungsbedingungen'], $a['versandart'], cd_std('std_bank')]);
        return insert_id();
    };

    // Ein Angebot je Workflow-Status ------------------------------------------
    $mkAng($vita, 'Vitamin D3 + K2 – 60 Kapseln', 'EUR', 'entwurf',
        [['Vitamin D3 + K2 (60 Kaps.)', 2000, 0, 'produkt', 'Stk.', $rezD3, 0]]);
    $mkAng($vita, 'Ashwagandha 360 – 90 Kapseln', 'EUR', 'kalkulation',
        [['Ashwagandha 360 (90 Kaps.)', 1500, 0, 'produkt', 'Stk.', $rezAsh, 0]]);
    $mkAng($nordic, 'Magnesium Complex – 60 Kapseln', 'EUR', 'kalkuliert',
        [['Magnesium Complex (60 Kaps.)', 1000, 249, 'produkt', 'Stk.', $rezMag, 0]]);
    $mkAng($nordic, 'Kurkuma-Extrakt 95% – Bulk', 'EUR', 'gesendet',
        [['Kurkuma-Extrakt 95% (Rohware)', 25, 4200, 'rohstoff', 'kg', 0, $rohKur]],
        ['notiz'=>'Muster vorab gewünscht.', 'zb'=>'50% Anzahlung, Rest vor Versand', 'va'=>'DAP Hamburg']);
    $aAcc = $mkAng($sun, 'Ashwagandha 360 – Bulk 5.000 Stk.', 'CNY', 'angenommen',
        [['Ashwagandha 360 Kapseln (Bulk)', 5000, 320, 'produkt', 'Stk.', $rezAsh, 0]]);
    $mkAng($vita, 'Magnesium Tabletten – 120 Stk.', 'EUR', 'abgelehnt',
        [['Magnesium Tabletten (120 Stk.)', 1000, 289, 'produkt', 'Stk.', $rezMag, 0]],
        ['grund'=>'Preis zu hoch – Kunde hat sich für einen anderen Anbieter entschieden.']);
    $aPaid = $mkAng($nordic, 'Magnesium Complex – Nachbestellung', 'EUR', 'angenommen',
        [['Magnesium Complex (60 Kaps.)', 3000, 240, 'produkt', 'Stk.', $rezMag, 0]]);

    // Rechnungen: eine offen (Sunrise/CNY), eine bezahlt (Nordic/EUR) ----------
    $mkRech($aAcc, 'offen');
    $mkRech($aPaid, 'bezahlt');

    // Produktion + Chargen-Rueckverfolgung ------------------------------------
    q("INSERT INTO crmdemo_produktion (kunde_id,produkt_id,titel,charge_nr,mhd,menge,status) VALUES (?,?,?,?,?,?,?)",
      [$sun, null, 'Ashwagandha 360 – Charge', cd_nummer('CH'), date('Y-m-d', strtotime('+2 years')), 5000, 'in_produktion']);
    $pid = insert_id();
    $rohAsh = (int) scalar("SELECT id FROM crmdemo_rohstoff WHERE name LIKE 'Ashwagandha-Extrakt%' LIMIT 1");
    q("INSERT INTO crmdemo_charge_zutat (produktion_id,rohstoff_id,name,lot,menge_kg) VALUES
        (?,?, 'Ashwagandha-Extrakt 360 mg','LOT-ASH-2601', 90.000),
        (?,NULL,'Leerkapsel HPMC Gr. 0','LOT-CAP-114', 3.500)", [$pid,$rohAsh,$pid]);
    q("INSERT INTO crmdemo_produktion (kunde_id,produkt_id,titel,charge_nr,mhd,menge,status) VALUES (?,?,?,?,?,?,?)",
      [$nordic, null, 'Magnesium Complex – Charge', cd_nummer('CH'), date('Y-m-d', strtotime('+2 years')), 3000, 'geplant']);
}
