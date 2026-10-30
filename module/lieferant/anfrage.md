# lieferant/anfrage.php – Preisanfragen beantworten

Route: `?p=lieferant_anfrage[&id=<ID>]`

Der Lieferant sieht seine Anfragen (offen zuerst) und gibt ein **Angebot** ab: Preis je Einheit, Einheit, Mindestmenge (MOQ), Lieferzeit und **Mengenstaffeln** (`lieferant_angebot_staffel`). Erneutes Senden überschreibt das eigene Angebot – die Anfrage geht dabei auf `beantwortet`.

Hängt die Anfrage an einem Artikel, kann der Lieferant hier auch **CoA und Spezifikation hochladen**; die Dateien landen über `dokument_upload()` direkt am Artikel (`objekt_typ='item'`) mit ihm als Lieferant – dort sucht sie das Team.

**CoA/Spec je Rohstoff ist PFLICHT – und Preis-Gate (Stand 2026-10-30):** Der Lieferant muss zu **jedem eingesetzten Rohstoff** eine **CoA ODER Spezifikation** liefern – **nicht** eine einzige Unterlage fürs ganze Produkt. Der Helfer `lief_anfrage_pflicht_rohstoffe($a)` (in `anfrage.php`) ermittelt die Pflicht-Rohstoffe: bei **Fremdfertigung** jede `rezeptur_zutat` mit `item_id` (Freitext-Zutaten ohne Artikel zählen nicht, weil nicht belegbar), sonst der eine angefragte Rohstoff (`lieferant_anfrage.item_id`). Je Rohstoff zeigt das Formular eine Zeile mit **Status** (`roh_liegt_vor` / `roh_fehlt`), einem Typ-Auswahlfeld (Spec/CoA) und einem Datei-Feld (`rohstoff_typ_<id>` / `rohstoff_datei_<id>`); fehlende sind clientseitig `required`.
- **Vorhandene Unterlage zählt:** Liegt am Rohstoff schon ein Dokument (`dokument` `objekt_typ='item'`, `typ IN ('spec','coa','beides','analyse')`), ist kein erneuter Upload nötig.
- **Echtes Preis-Gate:** Fehlt zu auch nur einem Rohstoff beides (keine vorhandene **und** keine mitgeschickte Unterlage), wird der Preis **nicht** gespeichert (`lp_t('roh_unterlage_fehlt')` + Liste der fehlenden Rohstoffe). Die Prüfung läuft **vor** `lieferant_angebot_speichern()` – anders als früher, wo der Preis zuerst gespeichert wurde.
- **Ablage + KI:** Jede Datei landet am **jeweiligen Rohstoff** (`lieferant_unterlage_speichern(..., 'item', $item_id)`, Lieferant, nicht kundensichtbar); die **KI-Auswertung läuft NACH der Antwort** (`ki_antwort_abschliessen()` + `spec_ki_nach_upload()` je Dokument, plus `rohstoff_entwurf_aktivieren()` je betroffenem Rohstoff). Die KI liest **jede Sprache** (auch Chinesisch) und ergänzt Grenzwerte/Wirkstoffe am Rohstoff – daraus erzeugt bulkify eine **eigene Spec/CoA**; das Original des Lieferanten bleibt intern.
- **Fallback:** Gibt es keine bekannten Rohstoffe (Fertigprodukt **ohne** Rezeptur und ohne Artikel), bleiben wie bisher zwei **optionale** Felder CoA/Spec (am Lieferanten abgelegt, mit Bezug zur Anfrage).

Nimmt das Team das Angebot an, werden die Staffeln zu EK-Staffeln am Artikel (siehe `core/schema.md`, `lieferant_angebot_annehmen()`).
## E-Mail ans Team
Speichert der Lieferant sein Angebot zur Preisanfrage, bekommen alle Admins eine Mail mit Preis, Mindestmenge und Lieferzeit (`mail_team_preisanfrage()`), sofern der Versand eingerichtet ist.
## Rückfragen und Downloads
Unter dem Angebotsformular steht das Panel **Rückfragen** (`nachricht_panel()` mit Bezug `lieferant_anfrage`): Fragen und Antworten zu genau dieser Preisanfrage, POST `aktion=nachricht`. Die eigenen CoA/Spezifikationen sind jetzt verlinkt (`?p=lieferant_dokument&id=`).
## Produkttyp und Einheit
Die Anfrage sagt, **was** angefragt wird (`art`: Rohstoff, Fertigprodukt, Verpackung, Verbrauch, Sonstiges) und in welcher **Form** (`form`). Beide Arten haben eine Form, damit es überall gleich heißt: „Fertigprodukt · Kapseln" neben „Rohstoff · Pulver". Fertigprodukt: Kapsel, Tablette, Softgel, Stick, Pulver, Granulat, Flüssig. Rohstoff: Pulver, Granulat, Flüssig, Öl, Extrakt (wird aus `item.form` übernommen, wenn nichts gewählt ist). Daraus ergibt sich die **Einheit automatisch** (`anfrage_einheit()` in `core/schema.php`): Kapsel/Tablette/Softgel/Stick werden je Einheit bepreist, Pulver und Granulat je kg, Flüssiges je Liter; sonst zählt die Bezugsgröße am Artikel. Der Lieferant kann die Einheit nicht ändern (das Feld ist gesperrt), damit Angebote vergleichbar bleiben. Angezeigt wird sie in der richtigen Zahlform: „250.000 Kapseln", aber „Preis je Kapsel" (`einheit_wort()` in `core/schema.php`, im Portal über `lp_einheit($e, $menge)`).

## Preisbasis
Weil Kapseln üblicherweise je 1.000 gehandelt werden, wählt der Lieferant, ob sein Preis **je 1** oder **je 1.000** Einheiten gilt (`lieferant_angebot.preis_basis`). Beim Übernehmen der Preise teilt `lieferant_angebot_annehmen()` durch die Basis – am Artikel steht immer der Preis je einer Einheit.

## Sprachen
Alle Texte laufen über `lp_t()`; Zahlen über `lp_num()` (Deutsch 1.234,5 – English und Chinesisch 1,234.5) und Einheiten über `lp_einheit()` (Stück/Kapsel/Tablette … werden übersetzt, kg und L nicht).

## Angebot abgeben
Die erste Zeile ist **Preis und Menge**: der Preis, den der Lieferant nennt, und die Menge, für die er gilt – vorbelegt mit der angefragten Menge (`menge_haupt`). **„+ Staffel"** hängt **unmittelbar darunter** beliebig viele weitere Zeilen im selben Zuschnitt an (`s_preis[]` / `s_menge[]`) – Basis, Mindestmenge und Lieferzeit stehen **rechts daneben** in derselben Zeile; die Staffelzeilen liegen in derselben Spalte wie die Preiszeile und sind deshalb genau gleich breit.

Beim Speichern wird die erste Zeile als **erste Staffel** übernommen, danach die angehängten. Der Preis der ersten Zeile ist zugleich der Kopfpreis des Angebots (`lieferant_angebot.preis`), die Basis daneben sagt, ob er je Einheit oder je 1.000 gilt. Leere Zeilen werden ignoriert, ein Entfernen-Knopf erübrigt sich. Zahlen dürfen mit Tausendertrennung eingetippt werden – deutsch `250.000`, englisch und chinesisch `250,000` (`zahl_lesen()` in `core/schema.php`, Sprache aus `lp_sprache()`). Beim Wiederaufruf steht die erste gespeicherte Staffel in der Kopfzeile, der Rest darunter.

**Anfragen-Übersicht** (Listenansicht, kein `?id=`): getrennt in **Offene Anfragen** (`status='offen'`, oben, Handlungsbedarf) und **Bereits beantwortet** (eigener Abschnitt darunter, nur wenn vorhanden). So verschwinden abgegebene Angebote aus der offenen Liste und bleiben trotzdem einsehbar. Keys `lp_t('offene_anfragen')` / `lp_t('beantw_anfragen')`.

**Nach dem Abgeben** (Angebot vorhanden, aber noch nicht angenommen) steht über dem Formular eine kleine **Zusammenfassung „Ihr abgegebenes Angebot"** (Preis + Basis + ab-Menge, Staffeln, MOQ, Lieferzeit, Lieferbedingung, Notiz) – so sieht der Lieferant sofort, was er angegeben hat; darunter kann er es unter **„Angebot anpassen"** weiter ändern. Dieselbe Tabelle wie im gesperrten Zustand, nur eben noch editierbar. Keys `lp_t('ihr_abg_angebot')` / `lp_t('angebot_anpassen')`.
## Hochgeladene Unterlagen werden gelesen
Lädt der Lieferant eine Spezifikation oder ein CoA hoch, liest die KI sie sofort aus (`spec_ki_nach_upload()`) und merkt den Vorschlag am Dokument. Der Lieferant sieht nur eine Bestätigung – **Stammdaten schreibt er damit nicht**. Das Team findet den Vorschlag am Rohstoff im Reiter Spezifikation und entscheidet dort.

## Lieferbedingung (Incoterm + Versandart)
Die Anfrage nennt die von uns gewünschte Lieferbedingung (Incoterm + Versandart, Standard DDP/Luft). Der Lieferant sieht diesen Wunsch und gibt sein Angebot **mit** Incoterm + Versandart ab (`lieferant_angebot.incoterm/versandart`, vorbelegt mit unserem Wunsch). Beim Übernehmen (`lieferant_angebot_annehmen()`) landen beide an jeder EK-Staffel (`lieferant_preis.incoterm/versandart`). Das Team sieht die angebotene Lieferbedingung in der Angebotsübersicht (Lieferanten-Detail) schon vor dem Annehmen.

## Angenommenes Angebot ist gesperrt
Hat das Team ein Angebot übernommen (`lieferant_angebot.status='angenommen'`), sind die Preise bereits als EK-Staffeln kopiert. Der Lieferant kann es dann **nicht mehr überschreiben**: das Formular weicht einer schreibgeschützten Übersicht (Preis, Staffeln, MOQ, Lieferzeit, Lieferbedingung) mit Hinweis (`lp_t('angebot_gesperrt')`); der POST-Weg lehnt ein erneutes Speichern zusätzlich ab. So laufen Angebot und übernommener EK-Preis nicht auseinander.

## Rezeptur bei Fremdfertigung
Geht es um die **Fertigung eines Produkts** (Anfrage mit `rezeptur_id`, art `fertigprodukt`), zeigt die
Detailseite dem Lieferanten die **Rezeptur** (Panel „Rezeptur") – Inhaltsstoffe aus `rezeptur_zutat`
(`bezeichnung`, sonst Item-Name) mit **mg je Einheit** plus Füllgewicht. Ohne das wüsste der
Lohnhersteller nicht, was er herstellen soll. Bei reinen **Rohstoff**-Anfragen (kein `rezeptur_id`)
erscheint kein Rezeptur-Panel. Labels: `lp_t('rezeptur'|'wirkstoff'|'mg_je_einheit'|'fuellgewicht')`.

## Anzeige-Name ohne Kategorie-Praefix (2026-10-08)
In Liste + Detail zeigt die Artikel-Ueberschrift den Artikelnamen bzw. den Betreff, aber OHNE fuehrenden Kategorie-Praefix (z. B. "Fertigprodukt (Bulk): ") – die Art/Form steht ohnehin direkt darunter (anfrage_art_label). Helfer `$anzName()` strippt den Praefix aus `anfrage_arten()[art]`. Betrifft nur die Anzeige; der gespeicherte Betreff (E-Mail-Betreff) bleibt unveraendert.

## Rezeptur zusaetzlich pro 100 g (2026-10-08)
Die Rezeptur-Tabelle zeigt neben "mg je Einheit" jetzt eine Spalte "pro 100 g" (`lp_t(pro_100g)`): Masseanteil = menge_mg / Fuellgewicht x 100 g, adaptiv in g (>=1 g) bzw. mg. Fuer Bulk-Pulver leichter zu kalkulieren als je Einheit; Summe ergibt 100 g.

## Anklickbare Rohstoffe (Stand 2026-10-08)
In der Rezeptur-Tabelle sind Inhaltsstoffe mit `item_id` Links (`a.lp-roh[data-iid]`) → Rohstoff-Info-Popup (`lp_rohstoff_popup()`, lädt `?p=lieferant_rohstoff_info`): Identität + Beschaffenheit/DEV + Wirkstoff-Gehalte, ohne Preise/Lieferanten.

## Typ-Filter + zurückgezogene Anfragen (2026-10-24)
Über der Liste **Typ-Chips** (`&typ=`): Alle / Rohstoffe (`af.art='rohstoff'`) / Fertigprodukte (`af.art='fertigprodukt'`), mit Zählern (`lp_t(filter_alle/filter_rohstoffe/filter_fertig)`).
Anfragen mit Status `zurueckgezogen` (dem Lieferanten wurde die Rohstoff-Berechtigung entzogen) werden in der Liste **ausgeblendet** (`WHERE af.status<>'zurueckgezogen'`); ruft er eine solche Anfrage direkt per URL auf, erscheint ein Hinweis-Banner (`lp_t(anfrage_zurueck)`) und ein Angebot kann nicht mehr abgegeben werden.

## E2–E5, E7 (2026-10-28)
- **E2:** In der Anfragenliste ist die **ganze Zeile klickbar** (öffnet die Anfrage); der primäre „Angebot abgeben"-Button entfällt, stattdessen ein dezenter ›-Pfeil. Das Angebot wird erst in der geöffneten Anfrage abgegeben.
- **E3:** Im Angebotsformular neues Feld **„Ihre konkrete Variante / Gehalt"** (`lieferant_angebot.variante`). Hochgeladene Spec/CoA laufen durch den KI-Import (`spec_ki_nach_upload`). **Ersetzt (2026-10-30):** Der frühere einzelne Spec-Pflicht-Upload je Anfrage ist der **CoA/Spec-je-Rohstoff-Pflicht** gewichen (siehe oben, „CoA/Spec je Rohstoff ist PFLICHT"). CoA **oder** Spec genügt je Rohstoff; vorhandene zählen; Preis-Gate.
- **E4:** Bei Kapsel/Softgel-Anfragen kann der Lieferant eine **andere Kapselgröße vorschlagen** (`kapsel_vorschlag_id`) mit **Begründung** (`kapsel_vorschlag_grund`).
- **E5:** Plausibilität Füllgewicht vs. Kapsel: `kapsel_plausibilitaet($rezeptur_id,$kapselgroesse_id)` (`core/schema.php`); passt das Füllgewicht je Einheit nicht in eine einzelne Kapsel, zeigt die Anfrage einen Warnhinweis (mg/Kapsel, ~Kapseln je Dosis).
- **E7:** Bereits vorhanden – eine Lieferant-Rückfrage (`nachricht_senden` akteur=lieferant) erzeugt eine Benachrichtigung (`benachrichtigung_add`) mit Sprung-Link; das Menü „Benachrichtigungen" zeigt den ungelesen-Zähler als Badge (`core/layout.php`). Kein Code-Change nötig.

Neue Übersetzungsschlüssel in `portal_layout.php`: variante, variante_hint, spec_pflicht, kapsel_andere, kapsel_andere_grund, kapsel_passt_nicht (DE/EN/ZH).

Übersetzungsschlüssel für CoA/Spec je Rohstoff (2026-10-30): roh_unterlage_titel, roh_unterlage_hinweis, roh_liegt_vor, roh_fehlt, roh_unterlage_fehlt (DE/EN/ZH).
