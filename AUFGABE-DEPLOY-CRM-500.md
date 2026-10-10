# Problem: CRM-Kontaktseiten werfen HTTP 500 (Deploy nötig)

## Symptom
Auf **app.bulkify.pro** werfen **alle** CRM-Kontaktseiten einen Fehler:
`/crm/?p=kontakt&id=<beliebig>` → **HTTP 500** (nicht nur ein Kunde, sondern jeder Kontakt).
Login, Pipeline, To-Dos, Kundenseiten usw. funktionieren normal.

## Ursache (bereits gefunden)
Die Tabelle **`crm_kontakt_datei`** (für die Dokumente-Karte am Kontakt) wurde auf dem Live-Server
nie angelegt. Grund: Die Spalte hieß `stored` — und **`STORED` ist in MySQL 8 ein reserviertes Wort**.
Ohne Backticks scheiterte das `CREATE TABLE` auf dem Live-Server **still** (lokal MariaDB fiel es nicht
auf). Da die Tabelle fehlt, wirft `kontakt_dateien()` beim Lesen den 500.

## Fix-Status
**Der Fix ist im Code behoben und nach `main` gepusht:**
- Spalte in `CREATE` und `INSERT` als `` `stored` `` gebacktickt (`crm/core/schema.php`, `crm/core/kontakt.php`).
- Zusätzlich die Lese-Helfer abgesichert (try/catch), damit eine fehlende Tabelle nie die ganze Seite killt.
- Relevanter Commit: **`0528ad4`** ("CRM-Fix: crm_kontakt_datei - Spalte 'stored' backticken").
- `origin/main` enthält alles (aktueller HEAD z. B. `17096ab`).

**Das Problem ist NUR, dass der Live-Server diesen Stand noch nicht deployed hat** — er läuft noch auf
einem älteren Commit (der Stacktrace zeigt die alte Dateiversion). Seit mehreren Minuten zieht der
Auto-Deploy nichts nach.

## Was zu tun ist (eins von beiden)

### A) Deploy auslösen (bevorzugt)
Den aktuellen `main`-Stand auf den Live-Server (app.bulkify.pro, Pfad `/homepages/1/d4295818566/htdocs/bulkify4.1`)
ziehen (`git pull` bzw. der übliche Deploy-Weg). Danach legt das Selbst-Schema (`crm_schema()`) die Tabelle
beim nächsten Seitenaufruf **automatisch** an — nichts weiter nötig.

### B) Sofort-Fix direkt in der DB (unabhängig vom Deploy)
Falls der Deploy nicht schnell geht: dieses SQL einmal auf der Datenbank **`dbs16065185`** ausführen
(z. B. phpMyAdmin). Dann ist die Tabelle sofort da und die Kontaktseiten laufen wieder:

```sql
CREATE TABLE IF NOT EXISTS crm_kontakt_datei (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kontakt_id INT NOT NULL,
  kategorie VARCHAR(20) NOT NULL DEFAULT 'sonstiges',
  original VARCHAR(255) NOT NULL,
  `stored` VARCHAR(190) NOT NULL,
  groesse INT NOT NULL DEFAULT 0,
  benutzer_id INT NULL,
  angelegt DATETIME NOT NULL,
  KEY (kontakt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Hinweis
Die anderen neuen CRM-Tabellen (crm_todo, crm_rezeptur_ki, crm_mitarbeiter, crm_kunde_profil,
crm_mail_eingang) haben **kein** reserviertes Wort und wurden live korrekt angelegt — betroffen ist
ausschließlich `crm_kontakt_datei`.
