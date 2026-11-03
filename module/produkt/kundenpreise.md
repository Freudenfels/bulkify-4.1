# produkt/kundenpreise.php – Zentrale Kundenpreis-Liste

**Route:** `?p=kundenpreise` (Admin, Vertrieb, Finanzen). Verlinkt aus der Produktliste („Kundenpreise“), aus der
Auftragsliste („Preise nachtragen“) und aus „Aufträge ohne Preis“. Menü: Vertrieb → Kundenpreise.

**Zweck:** Für Produkte, die Kunden bestellt haben, den **Herstellungspreis je Packung** an einer Stelle eintippen –
ohne jeden Auftrag/jedes Produkt einzeln zu öffnen.

**Zeilen:** jede Kombination Kunde × Produkt × Menge je Packung aus
1. vorhandenen `produkt_kundenpreis`-Einträgen und
2. bestellten Kombinationen aus `auftrag` (nicht storniert; Menge je Packung = `auftrag.stueck`, sonst
   `produkt.einheiten_pro_packung`), die noch keinen Kundenpreis haben – so tauchen auch nie bepreiste Altfälle auf.

Standard: alle Zeilen. Filter „nur ohne Preis“ (Arbeitsliste), Kunde, Produkt, Suchfeld (Kunde, Produkt, Rezeptur,
Verpackung, Auftragsnummer).

**Spalten:** Kunde · Produkt (Rezeptur-Name, darunter P-Nummer) · Menge je Pkg · Verpackung (neutraler Behälter,
„vollbedruckt“ wenn der Auftrag einen bedruckten Beutel hat) · Bestellmenge(n) + Aufträge ohne Preis · Herstellung je Pkg
(Eingabe) · Verpackung + Etikett je Pkg · VK je Pkg · Gesamt netto.

**Preis-Modell:** Eingabe = `produkt_kundenpreis.preis` („VK je Packung OHNE Verpackung“, `kundenpreis_setzen()`).
Verpackung + Deckel + Etikett = `verpackung_cent_je_pack($produkt, $bestellmenge, $kunde, $behaelter)` – EK-Staffel bei der
Bestellmenge × Aufschlag, Kundenrabatt, je Position auf Cent gerundet (wie Angebotszelle/Beleg). Behälter = der des
(unbepreisten bzw. jüngsten) Auftrags, sonst der des Produkts. Vollbedruckter Beutel: keine Etikett-Position.
Bestellmenge für Staffel/Gesamt: unbepreister Auftrag, sonst `anzahl_vpe`, sonst jüngster Auftrag.
Die berechneten Spalten laufen beim Tippen per JS mit (deutsche Schreibweise: 4,50 / 1.234,5 / 4.50); maßgeblich ist der
Server beim Speichern (`zahl_lesen()`).

**Speichern:** je Zeile („Speichern“) oder „Alle speichern“ (Enter = alle). Nur geänderte Zeilen aus der aktuellen Liste.
`kundenpreis_setzen()` mit Anzahl VPE = Bestellmenge, Notiz „manuell: Preisliste“, Eintrag im Feld-Änderungsprotokoll
(`produkt_kundenpreis`).

**Aufträge füllen (Teil 2, Schalter `app_meta kundenpreise_auftrag_fuellen`, Standard 0 = aus bis zur Freigabe):**
Unbepreiste Aufträge der Kombination (`COALESCE(gesamt_netto,0) <= 0`, wie `auftrag/preisliste.php`) bekommen
`vk_stueck` = Herstellung + Verpackung/Deckel/Etikett je Pkg (Behälter und Staffel des Auftrags) und `gesamt_netto` =
Menge × VK; protokolliert (`auftrag`). Schon bepreiste Aufträge werden nie überschrieben. **Belege werden NICHT angelegt
oder geändert.**

**Einschränkung:** `produkt_kundenpreis` ist je (Produkt, Kunde, Menge je Packung) eindeutig – ein Herstellungspreis je
Packungsgröße und Kunde, keine Staffel nach Bestellmenge.

Hinweis „ohne Etikett-Preis“: Der Behälter braucht ein Etikett, es ist aber kein Maß-Etikett (Etikett-Endformat am Behälter) mit Preis hinterlegt – das Etikett steckt dann nicht im VK.
