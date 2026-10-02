# lager/ – das Lager-Programm (Pick-to-Light)

> Eigener Bereich, gleiches Repo, nach dem Muster des CRM. Wer am Dashboard arbeitet, fasst diesen Ordner normalerweise nicht an – und umgekehrt.

## Was ist das?
Ein drittes Programm neben Dashboard und CRM – der **Warenlager-Manager**: **was kommt rein (Wareneingang), was geht raus (Warenausgang), wo liegt es (Bestand/Einlagern/Finden)**. Dazu steuert es die **Blinker** (Pick-to-Light, 100 Blinker von Jinzhishi/金之识) und verwaltet **Lagerplätze/Kisten**. Zielgerät ist ein Tablet oder ein PC mit Handscanner am Regal; bedienbar auch vom Admin. Siehe Memory „Lager Warenlager-Manager".

Warum getrennt: Das Lager soll sich ändern lassen, ohne das Dashboard anzufassen, und beim Umstieg auf v5 stehen bleiben. Nur `core/erp.php` wird dann angepasst.

## Wo was liegt
| Ort | Was |
|---|---|
| `lager/core/`, `lager/module/` | der Code, **nicht** über das Web erreichbar |
| `lager/bruecke/bruecke.ps1` | Vorlage für das Brückenprogramm im Lager |
| `public/lager/` | Einstieg (`index.php`), Schnittstelle für die Brücke (`bruecke.php`), Assets |

Erreichbar unter **`/lager/`**, also `beta.bulkify.pro/lager/`. Im Dashboard-Menü steht es unter „Weiteres“ (für Admin, Produktion, Versand, Einkauf und Labor).

## Gemeinsam mit dem Dashboard
- Dieselbe Datenbank, dieselben Logins (`benutzer`), eine **eigene Sitzung** (`BXLAGER`).
- Dieselbe `secrets.php` (eine Ebene höher) und derselbe Deploy.
- Dasselbe Aussehen (`/assets/app.css`), dazu `public/lager/assets/lager.css` mit Klassen `lg-...`.

## Die wichtigste Regel: eine einzige Naht
**Alle** Zugriffe auf Dashboard-Tabellen stehen ausschließlich in **`lager/core/erp.php`**. Eigene Daten liegen in Tabellen mit Präfix `lg_`. Seit dem Warenlager-Manager wird dort auch **geschrieben** – aber nur in genau benannten Funktionen: `erp_wareneingang_buchen()` (legt eine `charge` an), `erp_charge_entnehmen()` (bucht Bestand ab), `erp_bedarf_bump()` (macht den Dashboard-Bedarfs-Cache ungültig). Sie spiegeln die Dashboard-Logik (`wareneingang_buchen`/Quarantäne). Die Lager-eigene Bewegungs-Historie liegt in `lg_bewegung`.

## Wie ein Befehl zur Blinker kommt
```
Knopf "Leuchten" -> lg_befehl (offen) -> Brücke im Lager holt ab (bruecke.php)
                 -> http://<sender-ip>/light?code=FD10<leiste><farbe><dauer>50DF -> Blinker leuchtet
```
Der Server kommt nicht an eine IP im Lager, deshalb gibt es die Brücke: ein PowerShell-Programm auf einem Lager-PC. Alternativ gibt es den Weg **direkt** (Server im selben Netz) oder **cloud** (Hersteller-API über 4G, noch ungetestet). Details stehen in `core/led.md`.

Die Blinker melden **nichts zurück**. Auch der Knopf an der Blinker beendet nur den Piepton. Eine Bestätigung wie „entnommen“ muss deshalb in bulkify passieren, per Scan oder Klick.

## Zwei Modelle, ein Programm
Die zwei Räume arbeiten unterschiedlich, deshalb gibt es zwei Modelle:
- **Großes Lager (Chaos):** Blinker hängt an einer **Charge** (`lg_leiste.charge_id`), nicht am Platz. Finden per Klingeln. Kein festes Raster. Blinker sind im Umlauf: leer -> lösen -> neu binden.
- **Fulfillment (feste Plätze):** Blinker am festen **Platz** (`lg_platz`), Pick-and-Pack. (Im Aufbau.)

## Menü-Reihenfolge
Nach dem täglichen Ablauf, **wichtigste Aktion oben**: **Erwartete Lieferungen · Wareneingang · Finden · Warenausgang · Bestand**, dann Gruppe **Verwaltung** (Bewegungen · Kisten · Blinker), dann **System** (nur Admin: Sender und Brücke). **Keine festen Plätze** (Fulfillment-Gruppe entfernt) – alles läuft im „fliegenden Modus" (Blinker an der Charge). Definition in `core/layout.php` (`lg_nav()`).

## Was drin ist
**Warenlager**
- **Erwartete Lieferungen** (`?p=erwartet`): „Waren, auf die wir warten" – beim Lieferanten bestellt, noch nicht angekommen (ETA + Sendungsnummer), je Position „Einbuchen" → Wareneingang vorbefüllt. Siehe [module/bestand/erwartet.md](module/bestand/erwartet.md).
- **Wareneingang** (`?p=eingang`): Artikel + Menge buchen → Charge wird angelegt → direkt zum Einlagern (Blinker/Kiste). Vorbefüllbar per `&item=&menge=&lieferant=&charge=`. Siehe [module/bestand/eingang.md](module/bestand/eingang.md).
- **Warenausgang** (`?p=ausgang`): Charge suchen, Menge abbuchen; leer → Blinker löst sich automatisch. Siehe [module/bestand/ausgang.md](module/bestand/ausgang.md).
- **Bewegungen** (`?p=bewegungen`): Historie rein/raus aus `lg_bewegung`. Siehe [module/bestand/bewegungen.md](module/bestand/bewegungen.md).
- **Bestand** (`?p=bestand`): alle eigenen Chargen nach Kategorie, MHD-Ampel, Spalte **Ort** (Kiste oder Blinker), je Zeile **Etikett**-Link, anklickbar → Charge-Detail (`?p=charge`, Produkt/Lieferung/Dokumente/weitere Chargen).
- **Karton-Etikett** (`?p=etikett&id=` / `&ids=`): Aufkleber für die Kartons als **PDF 100×70 mm** mit Name (Rohstoff/Rezeptur), Lieferant, Lieferanten-Charge, MHD, Menge, **„Karton X / N"** (Paketzahl aus dem Wareneingang) und **QR-Code** (führt auf die Charge im Lager). Eigener abhängigkeitsfreier QR-Encoder `core/qr.php`, gezeichnet mit MiniPDF. Siehe [module/bestand/etikett.md](module/bestand/etikett.md).
- **Finden** (`?p=finden`): Such-Popup (Tippen oder Sprache), Treffer antippen lässt den Blinker blinken. Liegt die Charge in einer Kiste, blinkt die Kiste (Ort-Hinweis „Kiste X, Fach Y“).
- **Kisten** (`?p=kisten`): ein Behälter mit einem Blinker fasst viele Chargen – nicht jedes Kleinteil braucht einen Blinker. Siehe `core/kiste.php`.
- **Blinker** (`?p=leisten`): alle Blinker mit Nutzung und Akku-Ampel; Warnbalken → **Batterie prüfen** (`?p=batterie`).

**Fulfillment (feste Plätze)**
- **Feste Plätze** (`?p=plaetze`): Liste mit Leuchten-Knopf und Raster-Anlage für ganze Regale.
- **Platz** (`?p=platz&id=`): Angaben, Blinker, Sender, Leucht-Test mit Farbe und Dauer.
- **Blinker zuordnen** (`?p=zuordnen`): Erstmontage per Scan, die Blinker leuchtet zur Bestätigung grün.

**System**
- **Sender und Brücke** (`?p=sender`, Admin): Sender, Status der Brücke, Download des Brückenprogramms, Protokoll.

## Nächste Schritte
1. Chargen und Artikel einem Lagerplatz zuordnen (über `erp.php`).
2. „Finden“-Knopf: Das Fach eines Artikels oder einer Charge leuchtet auf.
3. Picklisten für Produktionsaufträge und den Versand: alle Fächer leuchten, eine Farbe je Auftrag, bestätigen bucht ab (FEFO, über `erp.php`).
