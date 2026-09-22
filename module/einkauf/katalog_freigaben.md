# einkauf/katalog_freigaben.php – „Katalog-Freigaben" (Einkauf)

**Zweck:** Zentrale Stelle im Menü **Einkauf**, an der das Team **alles** prüft, was Lieferanten in ihrem Portal unter „Mein Katalog" hochladen oder eintragen – Preisliste, CoA/Spezifikation und manuelle Zeilen. Route `?p=katalog_freigaben`.

**Warum es das gibt:** Solche Uploads landen als Zeilen in `lieferant_katalog` mit `status='neu'`. Vorher konnte man sie nur einzeln im jeweiligen Lieferantenkonto (Reiter „Katalog") prüfen – man musste also wissen, welcher Lieferant etwas hochgeladen hat. Diese Seite zeigt alle offenen Uploads über **alle** Lieferanten auf einen Blick. (Nicht zu verwechseln mit **Lager → Freigaben**: das sind Kunden-Freigaben von Spezifikationen/CoA an bereits bestehenden Rohstoffen/Chargen.)

**Bedienung:** Tabelle mit Lieferant (Link ins Konto, Reiter Katalog) · Artikel (+ Originalname/Herkunft/Notiz, + Hinweis „gibt es vielleicht schon" per `katalog_treffer()`) · Typ · Spezifikation · Preis · ab Menge · Aktion.
- **Anlegen** (`kat_uebernehmen`): legt den Artikel im Lager an und schreibt den EK-Preis dazu (`katalog_uebernehmen()`). Gibt es den Artikel schon (Name/CAS), heißt der Knopf **„Preis dorthin"** und der Preis wandert zum bestehenden Artikel.
- **ablehnen** (`kat_ablehnen`): verwirft die Zeile (`katalog_ablehnen()`).

Dieselben Funktionen nutzt der Reiter „Katalog" im Lieferantenkonto (`module/lieferant/detail.php`); die Seite hier ist nur die gesammelte Sicht. Details der Katalog-Logik: `core/lieferant_katalog.md`.

**Menü & Zähler:** Eintrag in `core/layout.php` (Gruppe Einkauf) mit Badge = Zahl offener Zeilen (`SELECT COUNT(*) FROM lieferant_katalog WHERE status='neu'`). Rechte: `einkauf`, `finance` (+ admin) in `core/auth.php`. Zusätzlich zeigt die **Lieferantenliste** eine Spalte „Zu prüfen" je Lieferant (siehe `module/lieferant/liste.md`).
