# kontingent/liste.php – Kontingente / Jahresverträge (Rahmenverträge)

**Zweck:** Jahresverträge abbilden: Ein Kunde vereinbart eine **Gesamtmenge** eines Produkts zum **Festpreis** und **ruft** über sein Portal nach und nach Teilmengen **ab**. Jeder Abruf wird automatisch ein Auftrag; die abgerufene Menge steigt, der Rest sinkt. Route `?p=kontingente`, Menü „Vertrieb → Kontingente" (intern).

**Daten (`kontingent`, in `core/schema.php`):** `kunde_id`, `produkt_id`, `gesamt_menge` (Packungen = Auftragseinheit), `abgerufen`, `vk_stueck` (vereinbarter VK je Packung), `gueltig_von`/`gueltig_bis`, `status` (aktiv|beendet), `notiz`. Rest = `gesamt_menge − abgerufen`.

**Diese Seite (nur intern):**
- **Liste** aller Kontingente mit vereinbart / abgerufen / Rest / VK / gültig bis / Status; aktive zuerst.
- **Anlegen** (`aktion=neu`): Kunde, Produkt, Gesamtmenge, VK je Packung, Laufzeit, Notiz.
- **beenden / aktivieren** (`aktion=beenden`/`aktivieren`): schaltet den Status.

**Abruf (Kundenportal, `module/portal/kunde.php`, View `kontingente`):** Der Kunde sieht seine aktiven Verträge (vereinbart/abgerufen/Rest, sein Preis, gültig bis) und ruft eine Menge ab (`aktion=kontingent_abruf`, max = Rest, Prüfung auf Eigentum + Laufzeit). `kontingent_abruf()` (in `core/schema.php`) erzeugt Auftrag (`auftrag.kontingent_id`) + Rechnung + Produktionsauftrag + Stationen zum vereinbarten Preis und schreibt `abgerufen` fort. Danach läuft der Auftrag normal durch Produktion/Versand. Es erscheint **kein** Zukauf/EK – nur der vereinbarte VK.

## Jahresvertrags-Ablauf (Status)
Kontingente entstehen jetzt auch **aus einem Angebot** (`angebot.jahresvertrag=1`): Der Kunde schließt im Portal ab → `kontingent_aus_angebot()` legt das Kontingent im Status **`wartet_vertrag`** an (Abruf gesperrt). Der Kunde lädt den **unterschriebenen Vertrag** hoch → **`wartet_freigabe`**. Hier prüft das Team den Upload (Spalte „Vertrag") und klickt **freigeben** → **`aktiv`** (jetzt abrufbar). Direkt angelegte Kontingente (Formular unten) sind sofort `aktiv`. Statusreihenfolge in der Liste: zu prüfende zuerst.
