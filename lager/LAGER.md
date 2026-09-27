# lager/ – das Lager-Programm (Pick-to-Light)

> Eigener Bereich, gleiches Repo, nach dem Muster des CRM. Wer am Dashboard arbeitet, fasst diesen Ordner normalerweise nicht an – und umgekehrt.

## Was ist das?
Ein drittes Programm neben Dashboard und CRM. Es verwaltet die **Lagerplätze** und steuert die **Lichtleisten** (Pick-to-Light, 100 Leisten von Jinzhishi/金之识). Zielgerät ist ein Tablet oder ein PC mit Handscanner am Regal.

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
**Alle** Zugriffe auf Dashboard-Tabellen stehen ausschließlich in **`lager/core/erp.php`**. Eigene Daten liegen in Tabellen mit Präfix `lg_`. Stand heute wird ins Dashboard nichts geschrieben.

## Wie ein Befehl zur Leiste kommt
```
Knopf "Leuchten" -> lg_befehl (offen) -> Brücke im Lager holt ab (bruecke.php)
                 -> http://<sender-ip>/light?code=FD10<leiste><farbe><dauer>50DF -> Leiste leuchtet
```
Der Server kommt nicht an eine IP im Lager, deshalb gibt es die Brücke: ein PowerShell-Programm auf einem Lager-PC. Alternativ gibt es den Weg **direkt** (Server im selben Netz) oder **cloud** (Hersteller-API über 4G, noch ungetestet). Details stehen in `core/led.md`.

Die Leisten melden **nichts zurück**. Auch der Knopf an der Leiste beendet nur den Piepton. Eine Bestätigung wie „entnommen“ muss deshalb in bulkify passieren, per Scan oder Klick.

## Zwei Modelle, ein Programm
Die zwei Räume arbeiten unterschiedlich, deshalb gibt es zwei Modelle:
- **Großes Lager (Chaos):** Leiste hängt an einer **Charge** (`lg_leiste.charge_id`), nicht am Platz. Finden per Klingeln. Kein festes Raster. Leisten sind im Umlauf: leer -> lösen -> neu binden.
- **Fulfillment (feste Plätze):** Leiste am festen **Platz** (`lg_platz`), Pick-and-Pack. (Im Aufbau.)

## Was drin ist
**Großes Lager**
- **Finden** (`?p=finden`): Rohstoff/Charge suchen, Leiste binden (Scan), Finden (klingeln), Aus, Lösen.
- **Leisten** (`?p=leisten`): alle Leisten im Umlauf, frei oder belegt.

**Fulfillment (feste Plätze)**
- **Feste Plätze** (`?p=plaetze`): Liste mit Leuchten-Knopf und Raster-Anlage für ganze Regale.
- **Platz** (`?p=platz&id=`): Angaben, Leiste, Sender, Leucht-Test mit Farbe und Dauer.
- **Leisten zuordnen** (`?p=zuordnen`): Erstmontage per Scan, die Leiste leuchtet zur Bestätigung grün.

**System**
- **Sender und Brücke** (`?p=sender`, Admin): Sender, Status der Brücke, Download des Brückenprogramms, Protokoll.

## Nächste Schritte
1. Chargen und Artikel einem Lagerplatz zuordnen (über `erp.php`).
2. „Finden“-Knopf: Das Fach eines Artikels oder einer Charge leuchtet auf.
3. Picklisten für Produktionsaufträge und den Versand: alle Fächer leuchten, eine Farbe je Auftrag, bestätigen bucht ab (FEFO, über `erp.php`).
