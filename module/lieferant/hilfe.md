# hilfe.php – Anleitung im Lieferantenportal (Route `?p=lieferant_hilfe`)

## Wozu
Ein neuer Lieferant soll ohne Rueckfrage loslegen koennen. Die Seite geht genau das Menue von oben nach unten durch: Anmelden, Uebersicht, Anfragen beantworten, Bestellungen, Mein Katalog, Dateien, Rueckfragen, Meine Daten, App aufs Handy.

## Sprachen
**Deutsch, English, 中文** – vollstaendig, nicht nur die Ueberschriften. Die Texte stehen direkt in der Datei (Array `$T`), nicht in `lp_t()`, weil sie nur hier vorkommen und sonst das Woerterbuch aufblaehen wuerden. Gewaehlt wird die Sprache wie ueberall im Portal ueber `lp_sprache()`.

## Aufbau
Je Abschnitt: Ueberschrift, ein Satz Einleitung, danach die Schritte als Liste. Nummeriert, damit man am Telefon sagen kann „Punkt 3".

## Pflegen
Aendert sich etwas am Portal, gehoert es hier in **allen drei** Sprachen nachgezogen. Fehlt eine Sprache, faellt die Seite auf Deutsch zurueck – das faellt niemandem auf und ist deshalb gefaehrlich.

## Sofortfix 13.11.2026: Unterlagen-Sperre wird Quarantäne-Sperre
Regel Nico: Unterlagen fehlen = Ware bleibt in Quarantäne. Bestellen und Einbuchen gehen immer. Artikel ohne Unterlagenpflicht: „kein Dokument nötig“ (Details `core/lieferant_unterlagen.md`).

Anleitung, Abschnitt Dateien: „Fehlt eine CoA oder Spezifikation, steht sie unter ‚Fehlende Unterlagen‘. Bitte fehlende Unterlagen hochladen, sonst bleibt die Ware bei uns gesperrt.“ (DE/EN/ZH).

## Neu geschrieben (13.11.2026)
DE/EN/中文, 16 Abschnitte mit Icon in der Reihenfolge des Menüs: Anmelden, Übersicht, Fehlende Unterlagen (Bestell-Sperre), Anfragen (Variante, Staffel, „Preis gültig bis“, CoA/Spec nötig, „Angebot abgeben/anpassen“), Bestellung bestätigen (Termin + Name; danach Termin nur über Rückfrage), Fortschritt, Chargen und Dokumente (CoA je Charge vor „versendet“), Aufkleber (Supplier no.), Versanddaten, Rechnung und Zahlung („bezahlt am“), Rohstoff- und Fertigprodukt-Preise, Katalog, Dateien, Rückfragen, Meine Daten mit Bankverbindung (keine „Zahlungsbedingungen“), Handy. 中文: CoA, 保质期, 资质证书 einheitlich.

**Fable-6 T5.1 (13.11.2026):** Die Anleitung in DE/EN/中文 nennt die Quarantäne-Regel: Fehlen Unterlagen, bestellen wir trotzdem, die gelieferte Ware bleibt aber in Quarantäne, bis die Unterlage da ist. Der Satz „können wir nicht bestellen“ ist entfernt.
