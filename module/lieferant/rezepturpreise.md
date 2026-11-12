# lieferant/rezepturpreise.php – „Fertigprodukt-Preise" (Fremdfertigung, Lieferantenportal)

**Zweck:** Der Lieferant trägt je **Rezeptur** seinen **Fremdfertigungspreis** ein (z. B. je Kapsel). **Kein „Annehmen"** – mehrere Lieferanten stehen nebeneinander und **unterbieten sich**; das Team sieht alle Preise und nimmt den günstigsten (interne Übersicht `?p=rezept_preise`). Route `?p=lieferant_rezepturpreise`, Menüpunkt **„Fertigprodukt-Preise"** (früher „Rezeptur-Preise" – umbenannt zur klaren Abgrenzung von den „Rohstoff-Preisen"). Das Menü zeigt diesen Punkt nur Lohnherstellern (siehe `portal_layout.md`, Menü-Gating).

**Daten:** `rezeptur_lief_angebot` (rezeptur_id · lieferant_id · preis · einheit · **stand**) + Staffeln in `rezeptur_lief_angebot_staffel` (ab_menge · preis). Beide aus dem v3-Import (Stufe 4, Quelle `lieferant_angebot` + `lieferant_angebot_staffel`).

**Vereinheitlicht + Festpreis mit Freigabe (Stand 2026-10):** Preise aus **Preisanfragen** (`lieferant_angebot` zu einer rezeptur-verknüpften Anfrage) werden beim Abgeben in **dieselbe** Tabelle `rezeptur_lief_angebot` gespiegelt (Bridge in `lieferant_angebot_speichern`, Altbestand per Migration `fix_la_rez_spiegel_v1`). Es gibt also nur noch **eine** Liste – kein getrennter read-only-Block mehr.
- **Festpreis:** `preis` ist der FREIGEGEBENE Preis; nur der fließt in die Kalkulation.
- **Änderung = Vorschlag:** Tippt der Lieferant einen bereits festgesetzten Preis um (oder antwortet mit anderem Preis auf eine neue Anfrage), wird das als `preis_vorschlag` gespeichert (`vorschlag_am`); der alte Preis bleibt gültig, bis **bulkify** freigibt (Einkauf → Preise → Fremdfertigung, Freigeben/Ablehnen). Der Lieferant sieht „Vorschlag (wartet auf Freigabe)" und nach dem Senden den Hinweis `preis_vorschlag_info`. Erst-Preis (noch keiner da) gilt sofort.
- **4-Wochen-Regel:** unverändert (Hinweis „veraltet" + „Alle als aktuell bestätigen"); bei Gleichpreis-Bestätigung wird nur `stand` neu gesetzt und ein offener Vorschlag verworfen.

**Nur freigeschaltete Formen:** der Lieferant darf nur Rezepturen der **Darreichungsformen bepreisen, die das Team für ihn freigeschaltet hat** (`lieferanten.fertig_formen`, CSV: kapsel/tablette/softgel/stick/pulver/fluessig; gesetzt auf der Lieferanten-Detailseite unter „Fertige Produkte"). Die Rezeptur-Auswahl („weitere Rezeptur bepreisen") ist auf diese Formen gefiltert; ist keine Form freigeschaltet, erscheint ein Hinweis und es kann nichts eingetragen werden. Oben stehen die für ihn freigeschalteten Formen.

**Bedienung:**
- Tabelle: Rezeptur (Name ist ein Link zu **`?p=lieferant_rezeptur`** – Zusammensetzung ansehen) · Form · **Ihr Preis** (inline editierbar, `flex-wrap:nowrap` → Feld · Einheit · „Aktualisieren" **einzeilig**) · Stand (mit „!" wenn überfällig) · Löschen.
- „**Weitere Rezeptur bepreisen**": tippbare Rezepturauswahl + Preis → neue Zeile (`status='angeboten'`, `stand=heute`). Nach der Auswahl erscheint ein „Rezeptur ansehen"-Button, um vor der Preisangabe die Zusammensetzung zu prüfen.
- **4-Wochen-Regel:** ist der neueste `stand` älter als `lieferanten.preis_intervall_tage` (Standard 28), roter Hinweis + „Alle als aktuell bestätigen" (setzt alle `stand=heute`).

**Intern:** `module/rezeptur/lief_preise.php` (`?p=rezept_preise`, Menü Produkt → Rezeptur-Preise) listet alle Lieferantenpreise je Rezeptur, sortiert nach Preis; der **günstigste** je Rezeptur ist mit Badge „günstigster" markiert. EK (Herstellpreis) + empf. VK (EK × Marge).

## Übersetzung Einheit und Form (11.11.2026)
Nico hat gemeldet, dass „Kapsel“ und „Tablette“ im chinesischen Portal deutsch blieben.
- Die Einheit hinter dem Preis läuft über `lp_einheit()`, z. B. „€ / Kapsel“ → „€ / capsule“ bzw. „€ / 粒“, „Tablette“ → „tablet“ bzw. „片“.
- „Für Sie freigeschaltete Formen“ wird über `anfrage_art_label('', form)` übersetzt, z. B. „胶囊, 片剂“.
- Die Spalte Form zeigt nur die Form, z. B. „胶囊“. Vorher stand dort fälschlich „Rohstoff · Kapseln“ („原料 · 胶囊“), obwohl es um Fremdfertigung geht.
- In der Datenbank bleibt die Einheit deutsch. Übersetzt wird nur bei der Anzeige.

## Alle Preise auf einmal speichern (11.11.2026)
Eine Lieferantin (Mingyan) hat alle Preise eingetragen, danach waren sie weg. Bisher hatte jede Zeile ein eigenes Formular. Ein Klick auf „Aktualisieren“ schickte nur diese eine Zeile, die anderen Eingaben gingen beim Neuladen verloren.
- **Ein Formular für alle Zeilen:** Es heißt `rpForm` und hängt über das `form`-Attribut an den Eingabefeldern, so gibt es kein Formular im Formular. Jeder „Aktualisieren“-Knopf und der neue Knopf „Alle Preise speichern“ schicken alle Zeilen (`aktion=preis_save_alle`).
- **Nur Geändertes zählt:** Jede Zeile schickt ihren alten Wert in `vorher[id]` mit. Gespeichert werden nur Zeilen, deren Eingabe sich geändert hat. Die Regeln je Zeile sind unverändert (`$preisSpeichern`): Ein Erstpreis gilt sofort, ein gleicher Preis bestätigt den Stand, ein geänderter festgesetzter Preis wird ein Vorschlag zur Freigabe.
- **Nicht lesbare Eingabe:** Sie überschreibt nichts. Es erscheint die Meldung `preis_ungueltig` mit der Anzahl der betroffenen Zeilen.
- **Rückmeldung:** „Gespeichert (n)“ bzw. „Vorschlag (n)“. Das Team bekommt eine Benachrichtigung „Neue Fremdfertigungspreise“ mit Link zur Lieferantenseite.
- **Warnung beim Verlassen:** Wer die Seite mit ungespeicherten Eingaben verlässt, wird vom Browser gewarnt.
- **Zahlen-Eingabe:** `zahl_lesen()` versteht jetzt auch Vollbreiten-Ziffern aus chinesischer Eingabe („０．０５“, „。“) sowie Zusätze wie „€“, „¥“ oder „/粒“.

## Preis-Gültigkeit (11.11.2026)
Je Zeile gibt es die Spalte „Gültig bis“ mit Datumsfeld im gemeinsamen Formular, auch beim Hinzufügen. Eine geänderte Gültigkeit wird mit „Alle Preise speichern“ gesichert, auch ohne Preisänderung.

## Nur noch angefragte Rezepturen (11.11.2026)
- **Freie Auswahl entfällt:** „Für weitere Rezeptur bepreisen“ aus allen Rezepturen der freigeschalteten Formen ist weg. Die Auswahl lief blind nach Namen, und der Lieferant sah dabei auch Kundenrezepturen.
- **Block „Bitte bepreisen“:** offene Fremdfertigungs-Anfragen an diesen Lieferanten ohne Preis. Jede Zeile hat die Zusammensetzung aufklappbar und den Knopf „Angebot abgeben“ zur Anfrage.
- **Bepreiste Zeilen:** Auch dort ist die Zusammensetzung aufklappbar (`fertig_zusammensetzung_html`).
- **`preis_add`:** nimmt nur noch Rezepturen an, die bei diesem Lieferanten angefragt sind.

## Paket 12.11.2026 Teil 4 (Unterlagen-Regel)
Kennzeichen „Unterlagen fehlen“ am Fertigprodukt-Preis (Rohstoff-Unterlagen + Spezifikation des Gesamtprodukts) mit Link auf „Fehlende Unterlagen“.

## 6 Stellen (13.11.2026)
Preisfelder werden mit bis zu 6 Nachkommastellen vorbelegt, damit Sub-Cent-Preise beim erneuten Speichern erhalten bleiben.
