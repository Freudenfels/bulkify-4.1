# dl_rechnung_neu.php – Dienstleistungs-Rechnung (DR-) erstellen

Vorschau- und Freigabe-Seite für eine **Dienstleistungs-Rechnung** aus einem DL-Auftrag.
Route: `?p=dl_rechnung_neu&auftrag=<DB-Auftrag-ID>`.

## Zweck
Der Einstieg kommt aus dem Dienstleistungen-Modul (Dashboard): DL-Auftrag → „Rechnung in der
Buchhaltung erstellen" verlinkt hierher. Die Seite zeigt **erst eine Vorschau** (Kunde, Positionen
1:1 aus dem verknüpften DA-Angebot, Netto/USt/Brutto, Zahlungsziel, Checkbox „für Kunde freigeben").

Erst der Button **„Verbindlich erstellen"** ruft `dl_rechnung_aus_auftrag()` (in `core/finanz.php`)
auf und zieht damit die `DR-`-Nummer. Rechtlich wichtig: die Nummer wird erst bei Freigabe vergeben,
damit der Nummernkreis lückenlos bleibt und keine Nummern „verbrannt" werden.

Danach: Redirect auf die normale Beleg-Detailseite (`?p=rechnung&id=…`), wo Bearbeiten/Stornieren/
Freigeben/PDF/E-Rechnung bereits vorhanden sind.

## Verhalten
- Nur Aufträge mit `auftrag.kategorie='dienstleistung'`; sonst Hinweis statt Formular.
- **Idempotent**: existiert schon eine nicht stornierte Rechnung zum Auftrag, führt der Button nur
  dorthin (kein Doppel-Beleg). Ein Hinweis-Banner verlinkt die bestehende Rechnung.
- Positionen kommen über die Naht `erp_dl_positionen()` (liest `angebot_position` 1:1).

## Grenzen
Kein Direktzugriff auf geteilte Dashboard-Tabellen – alles über `core/erp.php`. Die Rechnung
selbst (`beleg`/`beleg_position`) gehört der Buchhaltung.
