# echt.php – öffentliche Echtheits-/Qualitätsprüfung

**Aufruf:** `/echt.php?t=<token>` (Charge, QR auf dem CoA/Spec) oder `/echt.php?p=<token>` (Produkt, QR auf dem PIB) – kein Login. Tokens aus `core/echtheit.php`.

Bei `?p=` zeigt die Seite das **Produkt** (Name, Darreichungsform) + die Liste der **bestätigten Chargen** mit Link auf deren Charge-Prüfung (`?t=`). Rest (Zustände, Technik) wie unten.

Zeigt im bulkify-Look (dunkelgrün/lime, keine Emojis) einen **Qualitätsnachweis zur Charge**:
- **Echtheit bestätigt** (grünes Badge) + Rohstoff (Anzeige-Name), Charge, MHD, Herkunft, Spezifikations-Nr.
- Tabelle **Analysenwerte** (Parameter · Spezifikation · Ergebnis · Methode), falls erfasst.
- Allergene, falls vorhanden.
- Hinweis „Bestätigt durch Maniso GmbH / Marke bulkify".

Nur **kundensichere** Felder (Quelle: `charge_echt_daten()`), nie interne Daten.

## Zustände
- Token unbekannt/ungültig → neutrale Seite „Kein gültiger Code" (HTTP 404), **ohne** Datenpreisgabe.
- Token widerrufen (`charge.echt_gesperrt=1`) → „Dieser Nachweis wurde zurückgezogen".
- Gültig → der Nachweis.

## Technik
Bootstrap wie `public/rohstoffe_public.php`: `core/config.php` + `core/schema.php` + `init_schema()`, dazu `core/pdf_beleg.php` (Firmenname) und `core/echtheit.php`. `X-Robots-Tag: noindex` + `<meta robots noindex>` – Tokens sollen nicht indexiert werden. Fehler werden geloggt und als „Kein gültiger Code" gezeigt (kein 500 nach außen).

Direkt erreichbar (eigene Datei unter `public/`), **nicht** über den `?p=`-Front-Controller.
