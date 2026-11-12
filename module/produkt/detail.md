# produkt/detail.php – Produktseite (Kopf + Kacheln + Reiter)

Umbau 09.11.2026 (Nico, Vorbild Auftragsseite): **je Sache eine Stelle**. Das frühere lange Formular mit einem „Speichern" ganz unten ist weg.

## Neuanlage (`?p=produkt&id=neu`)
Kurzes Formular: Name, Art (Eigenes / Handelsware / Nur Fulfillment), Rezeptur, Glas/Behälter, Menge je Packung, Kunde + exklusiv. `?extern=1&kunde_id=` belegt „Nur Fulfillment" vor. Danach geht es auf der Produktseite per Stift weiter.

## Kopf
Name (Stift: Name, Synonyme, Standard-Name für Kunden), P-Nummer, Status-Badge, Rezeptur-Link, Art. Knöpfe: Anfrage-Status + **„Beim Lieferanten anfragen"** (Pop-up `anfrage_modal`, Fertigprodukt-Anfrage der Rezeptur), Zurück, **Löschen** (nur Admin; Verwendungs-Liste nur nach fehlgeschlagenem Löschversuch).

## Kacheln (Stift → Fenster → Speichern, Aktion `kachel` mit `feld`)
Rezeptur · Darreichung/Kapselgröße (gilt an der Rezeptur) · Menge je Packung · Glas/Behälter (nur Primärverpackungen; „passt / zu klein" aus Kapsel-Fassung bzw. max. Füllgewicht/Volumen; Produktname zieht mit) · Deckel (nur Verschlüsse) · Leerkapsel (Kapsel) · Verzehr je Tag · Haltbarkeit (+ Allergene) · Status · Novel Food (+ automatisch prüfen) · MwSt (nur Admin) · Katalog/exklusiv · Art des Produkts · **Kosten je Packung** (Standard-Weg bei 1.000 Pkg, Klick → Reiter Kalkulation). Jede Kachel speichert nur ihr Feld und schreibt einen Verlaufseintrag.

## Reiter
| Reiter | Inhalt |
|---|---|
| Rezeptur | Zusammensetzung je Rohstoff (mg je Einheit, je Tagesdosis, welche Nährstoffe er liefert) + Tages-Deklaration (% NRV). Nur lesen, Link „Rezeptur bearbeiten". Hinweise: leere Rezeptur, Zutat ohne Menge, Rohstoff ohne Nährstoff-Zuordnung. |
| Kunden | Eine Zeile je Kunde (`produkt_kunden()`): „Kürzel (Firma)", Name beim Kunden, Etikett (Version, Design freigegeben, im Lager / unterwegs / Bedarf offene Aufträge), Kundenpreise (Badge „andere Packungsgröße"), letztes Angebot, letzte Rechnung je Pkg. Stift je Zeile: Name + Kundenpreise; Knopf „Kunde hinzufügen". Klappbar: alle Angebote und Rechnungen. Alter Anker `#kundennamen` öffnet diesen Reiter. |
| Beschaffung | Bezugswege mit Kosten je Packung (Standard markiert); Lieferanten für das Produkt (Staffeln, Incoterm, Versand, Stand, Land, Portal-Account, Lieferzeit, Mindestbestellwert, Ansprechpartner, Dokumente, „günstigster"; Preis hinzufügen per Fenster, löschen mit ×); Rohstoffe der Rezeptur (Bedarf, Lieferantenpreise, Lager); Verpackung (Lieferant, EK, Lager, unterwegs); letzte Bestellungen; Preisanfragen. Knopf „Preis anfragen" → Pop-up. |
| Produktion | Stückliste: Umkarton + Packungen je Umkarton, Beipackzettel, abweichendes Etikett (sonst automatisch aus dem Glas), Etiketten-Bestand gesamt, Standard-Produktionsweg (nur Admin), Notiz. Eigenes Speichern. |
| Kalkulation | `produkt_kalkulation()`: Zeilen Inhalt, Leerkapseln, Glas, Deckel, Etikett, Umkarton anteilig, Beipack, Herstellung, Summe – je Weg eine Spalte, Standard hervorgehoben. Mengenwahl 1.000 / 3.000 / 10.000 / frei (`km`). „Preis fehlt" statt 0, Summe dann „unvollständig". Einzelrohstoffe im Detail klappbar. Darunter die Preis-Matrix. |
| Dokumente | **Ein** Upload (`dokument_panel`, Aktion `dok_upload`) für CoA, Spezifikation, Laboranalyse, Zertifikat, Druckvorlage; bei Spec/CoA/Analyse liest die KI aus (nur beta), Ergebnisse unter „Von der KI ausgelesen" (+ Haltbarkeit/Allergene übernehmen). Darunter PIB ansehen / eigenes hochladen / entfernen. |

Bei **Nur Fulfillment** gibt es nur Kunden, Produktion und Dokumente.

## Suchfelder und Ladezeit
Auswahllisten mit mehr als 10 Einträgen sind Suchfelder (`rscombo`) und stehen nur in den Fenstern. Die Seite rechnet nicht mehr alle Rezepturen im Browser vor (früher JSON aller Rezepturen + Live-Rechnung), sie lädt nur das eine Produkt.

## Weitere Aktionen (unverändert)
`matrix`, `pib_upload`/`pib_del`, `spec_uebernehmen`, `zk_preis_add`/`zk_preis_del`, `kp_add`/`kp_del`, `kn_setzen`/`kn_del`, `dok_frei`/`dok_del`, `novelfood_pruefen`, `loeschen`. Nach jeder Aktion geht es zurück in den passenden Reiter (`&tab=`).

## Datenprüfung
Widersprüche am Produkt stehen unter `?p=datenpruefung`: Entwurf trotz Verwendung, Glas ohne Deckel, Kundenpreis mit anderer Packungsgröße, Kundenpreis weicht stark von der letzten Rechnung ab.

## Beim Lieferanten anfragen: Auswahl (10.11.2026)
„Beim Lieferanten anfragen“ (Kopf) und „Preis anfragen“ (Reiter Beschaffung) öffnen zuerst ein Fenster „Was soll angefragt werden?“:
- **Fertige Kapseln / Tabletten (Bulk)** – Lohnhersteller fertigt das Produkt, Preis je Kapsel/Tablette (bei Pulver: „Fertige Mischung (Bulk, je kg)“).
- **Premix (fertige Mischung, lose)** – nur bei Kapsel/Tablette: Mischung der Rezeptur ohne Kapsel, Preis je kg. Die Antwort erscheint im Reiter Beschaffung mit „Premix je kg“, in der Kalkulation in der Spalte Premix und am Auftrag im Beschaffungsweg Premix.
- **Rohstoffe einzeln** – Liste der Rezeptur-Rohstoffe (alle vorgewählt); einer → normale Preisanfrage, mehrere → Sammelanfrage (Preis je kg + CoA/Spezifikation).
Danach das bekannte Anfrage-Fenster; die Lieferantenliste zeigt je Art nur passende Lieferanten.

## Reiter Bestellungen (10.11.2026)
Der Reiter steht zwischen Kunden und Beschaffung und zeigt, wer das Produkt gekauft hat. Je Auftrag eine Zeile:
- Datum, Auftrag (Link), Kunde
- Menge in Stk, bei Teillieferung zusätzlich die versendete Menge
- Status: offen, in Produktion, versandbereit oder versendet
- **Charge** (Link zur Chargenverfolgung) und **MHD**. Bei mehreren Chargen steht jede Charge mit Menge in einer eigenen Zeile. Ist das MHD vorbei, kommt der Hinweis „abgelaufen“, eine Charge in Quarantäne bekommt einen eigenen Hinweis.

Ohne Charge steht „noch nicht produziert“ (bei offen oder in Produktion) bzw. „keine Charge zugeordnet“.

Oben stehen die Summen (Aufträge, Kunden, Stk) und ein Suchfeld, das nach Kunde, Auftrag oder Charge filtert. „Auch stornierte zeigen“ (`&bst=alle`) blendet stornierte Aufträge grau ein. Die Daten kommen aus `core/produkt_bestellungen.php`.

## Preis-Gültigkeit (11.11.2026)
Ein Zukaufpreis von Hand hat das Feld „Preis gültig bis“ (`produkt_lieferant_preis.gueltig_bis`).

## Abgelaufene Preise in der Kalkulation (11.11.2026)
Ein Weg mit abgelaufenem Rohstoffpreis zeigt „unsicher: Preis abgelaufen“ statt „unvollständig“. Die Zeile nennt das Ablaufdatum und verlinkt auf den Reiter Einkauf des Rohstoffs.

**Gesperrt/inaktiv (Block C, 11.11.2026):** Gesperrte Lieferanten/Kunden bzw. inaktive Produkte/Rezepturen stehen nicht mehr in den Auswahlfeldern. Ein schon gewählter Eintrag bleibt am Beleg sichtbar.

**Abgelaufene Preise (Block D, 11.11.2026):** Im Reiter Beschaffung tragen abgelaufene Zukauf-/Premix-Staffeln den Badge „abgelaufen“. Rohstoffpreise zeigen „abgelaufen seit …“ statt „günstigster“.

**Zahlen-Lesart (13.11.2026, Fable-3 T2.9):** Betragsfelder lesen mit `betrag_lesen()`, Preise/Prozente/Kurse mit `zahl_lesen()`, Mengen mit `zahl_lesen(…, true)` – alle aus core/zahl_lesen.php (eine Regel mit dem Dashboard). „21.687,75“, „21687,75“ und „21687.75“ ergeben 21687,75.
