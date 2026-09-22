# lieferant/rezeptur_ansicht.php – Rezeptur ansehen (Lieferantenportal)

**Zweck:** Der Lieferant kann eine Rezeptur, die er für die Fremdfertigung bepreisen/produzieren soll, **read-only ansehen** – die Zusammensetzung je Einheit. Route `?p=lieferant_rezeptur&id=<rezeptur_id>`. Vorher gab es in „Rezeptur-Preise" nur Namen, aber keine Möglichkeit, die eigentliche Rezeptur einzusehen.

**Was gezeigt wird:** Name + Nummer, Darreichungsform, Kapselgröße (international als `#0`), Füllgewicht je Einheit und die **Bestandteile** (Name + Menge in mg je Einheit, aus `rezeptur_zutat`, Name-Snapshot bevorzugt).

**Was NICHT gezeigt wird:** kein Kundenbezug (`kunde_id`), keine Preise, keine internen Artikelnummern. Nur, was der Hersteller zum Produzieren braucht.

**Zugriffsschutz:** Nur Rezepturen der **für den Lieferanten freigeschalteten Darreichungsformen** (`lieferanten.fertig_formen`) sind sichtbar – gleiche Regel wie bei den Rezeptur-Preisen. Ruft er über die ID eine Rezeptur einer nicht freigeschalteten Form auf, erscheint „nicht gefunden oder nicht freigeschaltet". Die Seite prüft selbst `ist_lieferant()`.

**Erreichbar aus:** `module/lieferant/rezepturpreise.php` – der Rezeptur-Name in jeder bepreisten Zeile ist ein Link; im „Weitere Rezeptur bepreisen"-Formular erscheint nach der Auswahl ein „Rezeptur ansehen"-Button (öffnet in neuem Tab).

**Verdrahtung:** Route in `public/index.php` (`$routes` + `$LIEF_ROUTEN`), Rolle `['*']` in `core/auth.php` (dabei auch `lieferant_preisliste` und `lieferant_rezepturpreise` nachgetragen, die dort gefehlt hatten – echte Lieferanten bekamen sonst „Kein Zugriff"). i18n-Keys in `portal_layout.php` (de/en/zh): rez_ansehen, rez_zusammensetzung, bestandteil, menge_je_einheit, fuellgewicht, rez_nicht_da.
