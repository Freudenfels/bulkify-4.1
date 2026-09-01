# Portal-Anfrage – Detail (portal_anfrage_detail.php)

## Wozu
Wenn ein Kunde im Portal etwas anfragt (Produkt, Rohstoff oder Dienstleistung), landet die
Anfrage in der Liste `Portal-Anfragen`. Diese Seite ist die Detailansicht **einer** Anfrage.
Hier sieht das Team den genauen Wunsch des Kunden und kann **Preise zurücksenden** –
also aus der Anfrage ein Angebot machen.

Route: `?p=portal_anfrage&id=<ID>`  (Rollen: sales, production, einkauf, admin)

## Was man hier sieht
- **Kunde / Typ / Status / Eingegangen** als Kärtchen oben.
- **Wunsch des Kunden**: bei einer Produktanfrage Produkt, Größe je Packung
  (Stück, *oder* Füllmenge in Gramm bei Pulver/Granulat bzw. in Milliliter bei Flüssig),
  Verpackungstyp, Anzahl Packungen.
  Bei Rohstoff/Dienstleistung Betreff und gewünschte Menge + Einheit.
- **Angebot abgeben** (bei Produktanfragen – mit hinterlegtem Produkt über die Preismatrix, bei einer Rezeptur-Anfrage über den Angebots-Editor).
- **Anfrage aus einer Rezeptur:** Hat der Kunde eine Rezeptur angenommen, für die es noch kein Produkt gibt, kommt die Anfrage mit `portal_anfrage.rezeptur_id` (und ohne `produkt_id`) herein. Statt der Preismatrix erscheint dann ein Hinweis plus **„Im Angebots-Editor bauen"** – dort wird die Rezeptur als Position gebaut (`angebot_rezeptur_zeilen()`), und beim Senden entsteht daraus das Produkt (`angebot_produkte_sichern()`).
- **Bearbeitungsstatus** zum manuellen Setzen.
- **Rezeptur im Klartext:** Bei einer Rezeptur-Anfrage stehen Darreichungsform und die komplette Zusammensetzung (Zutat + mg, Summe je Einheit) direkt hier – man muss kein Angebot anlegen, um zu sehen, worum es geht.
- **Nicht machbar:** Panel unter dem Wunsch. Mit Pflicht-Begründung absagen (`aktion=anfrage_absagen`) – Status `abgelehnt`, Grund in `portal_anfrage.absage_grund`, der Kunde liest ihn im Portal. Ein noch nicht gesendeter Entwurf wird verworfen und die Angebotsnummer wieder freigegeben. Solange abgesagt ist, verschwindet „Angebot abgeben" (kein Widerspruch auf dem Bildschirm); **Absage zurücknehmen** holt die Anfrage zurück in Bearbeitung.
- **Entwurf verwerfen:** neben jedem Angebot im Status `offen` – löscht es samt Positionen und gibt die Nummer zurück. Gesendete oder bestätigte Angebote sind davon ausgenommen.

## Angebot abgeben (der Kern)
Man gibt **keine Einzelpreise** ein – das System rechnet die ganze Preismatrix
(Packungsgröße × Bestellmenge; Größe = Stück, Gramm bei Pulver, Milliliter bei Flüssig).
Jede Darreichungsform wird gerechnet – kommen keine Preise heraus, fehlt die
**Behälter-Fassung** (Einstellungen → Produktion & Rezeptur), nicht die Engine.
Steuerbar sind vor dem Senden:

- **Marge (%)** – VK = EK × (1 + Marge); Standard = Marge je Form (`marge_typ_<form>`,
  Boden `marge_min`). Wird als `angebot.marge_override` gespeichert.
- **Produktionszeit (Wochen)** – `angebot.produktionszeit_wochen` (leer = globaler Wert).
- **Hinweis an den Kunden** – wird an die Angebots-Notiz gehängt.

Der Ablauf:
1. **Vorschau aktualisieren** (`aktion=angebot_vorschau`) rechnet die Matrix mit der
   eingegebenen Marge + Kundenrabatt neu und zeigt sie als Tabelle (inkl. „nicht machbar");
   kein Datensatz wird geschrieben.
2. **Angebot senden** (`aktion=angebot_abgeben`): stellt die Matrix sicher
   (`produkt_matrix_generieren`), legt das **Angebot** an (Status `gesendet`,
   Notiz `Aus Anfrage <Nr> — <Hinweis>`, `marge_override`, `produktionszeit_wochen`),
   setzt die Anfrage auf `beantwortet` und protokolliert.

Der Kunde sieht dann im Portal die Preismatrix **mit genau dieser Marge und Produktionszeit**
(auch im Angebots-PDF) und wählt eine Zelle. Bereits abgegebene Angebote werden hier verlinkt
aufgelistet (mit der gesetzten Marge), statt den Button erneut anzubieten – kein Doppel-Angebot.


## Angebot zurückziehen
Ein GESENDETES Angebot lässt sich **hier auf der Anfrage** zurückziehen – nicht nur im Editor. Der Knopf steht neben jedem Angebot in der Liste. Wirkung: Das Angebot geht zurück in den **Entwurf** (`status='offen'`), die **Anfrage auf „in Bearbeitung"**, und „Im Angebots-Editor bauen" erscheint wieder – zurückgezogene Angebote zählen nicht als „schon abgegeben". Im Portal fällt das Angebot damit aus der Annehmen-Logik und wird beim Kunden als „zurückgezogen" angezeigt. Ein bestätigtes Angebot lässt sich nicht zurückziehen, es hängt bereits an einem Auftrag.

**„Im Angebots-Editor bauen" hängt nie an einem erledigten Angebot an:** wiederverwendet wird nur ein Angebot im Status `offen` oder `gesendet`. Ist das vorige Angebot bestätigt oder abgelehnt, entsteht ein neues – ein angenommenes Angebot bleibt unangetastet.

Die Angebote zur Anfrage werden über `angebot.anfrage_id` gefunden (zusätzlich noch über die alte Notiz-Konvention „Aus Anfrage <Nr>", damit ältere Datensätze nicht verlorengehen).
## Rohstoff-Preis berechnen (bei Rohstoffanfragen)
Bei `typ = rohstoff` erscheint ein Panel **„Rohstoff-Preis berechnen"** mit derselben
Margen-Logik wie bei Produkt-Angeboten:

**VK = günstigster Lieferanten-EK (gestaffelt) × (1 + Aufschlag), danach Kundenrabatt.**

- **Rohstoff zuordnen:** aus dem Katalog vorbelegt (`portal_anfrage.rohstoff_id`), per Dropdown
  korrigierbar (Aktion `rohstoff_zuordnen`) – nötig, wenn der Kunde nur Freitext geschickt hat.
- **EK:** `rohstoff_ek_bei_menge()` – je Lieferant die passende Mengenstaffel
  (`lieferant_preis.menge_ab <= Menge`), dann der günstigste Lieferant; Fallback flacher
  `item.ek_preis`. Fremdwährungen werden aktuell nicht umgerechnet.
- **Aufschlag:** `rohstoff_aufschlag_prozent()` – eigener Wert am Rohstoff
  (`item.vk_aufschlag_prozent`), sonst globaler `aufschlag_rohstoff` (Einstellungen → Preise).
- **Kundenrabatt:** `vk_fuer_kunde()` (`kunden.rabatt_marge`), wie beim Produkt.

Angezeigt werden Karten (EK / Aufschlag / VK / VK für Kunde je Bezugseinheit), der Gesamtpreis
für die angefragte Menge und eine **Staffeltabelle** über die vorhandenen Lieferanten-Mengenstufen
(+ die angefragte Menge, hervorgehoben). Ohne EK erscheint ein Hinweis mit Link zum Rohstoff.

## Status – interne vs. Kundensicht
Intern gibt es: `neu`, `in_bearbeitung`, `beantwortet`, `abgelehnt`.
Der Kunde sieht daraus: **eingegangen** (neu), **in Bearbeitung** (in_bearbeitung),
**Angebot abgegeben** (beantwortet). Das Team kann den Status z. B. auf „in Bearbeitung"
setzen, während es Rohstoffpreise beim Lieferanten einholt.

## Verwandt
- `portal_anfragen.php` – die Eingangsliste (Zeilen sind anklickbar → diese Seite).
- `module/portal/kunde.php` – Kundensicht (Anfrage stellen + Angebot annehmen).
