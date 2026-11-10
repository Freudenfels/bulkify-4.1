# Preis & Unterlagen erfassen (`?p=rohstoff_erfassen`)

Das ist das Ziel des Pop-ups „Preis & Unterlagen“ aus `core/rohstoff_unterlagen.php` (`rohstoff_erfassen_dialog()` / `rohstoff_erfassen_knopf()`). Stand 10.11.2026, Nico: „direkt einen Preis ab Menge via Popup eintragen und ein CoA oder Spec hochladen, was dann automatisch die Infos füllt“.

Die Route nimmt nur POST an. Danach geht es zurück auf die aufrufende Seite, erlaubt sind nur interne `?p=`-Links.

## Was gespeichert wird
- **Preis ab Menge**: Mit Lieferant und Preis entsteht eine neue Zeile in `lieferant_preis` (EUR, je kg, `quelle='manuell'`). Danach zieht `item_ek_aus_lieferantenpreis()` den EK nach. Zahlen werden deutsch gelesen (`zahl_lesen`, z. B. 1.234,50 ab 1.000). Ohne Lieferanten wird kein Preis gespeichert, es kommt ein Hinweis.
- **Unterlage**: Spezifikation, CoA oder Laboranalyse landet über `dokument_upload()` am Rohstoff, mit dem gewählten Lieferanten.
- **Infos automatisch füllen** (Haken, Standard an, nur wenn die KI eingerichtet ist):
  - `spec_ki_nach_upload()` liest die Unterlage aus. Bei einer CoA entsteht eine Vorab-Charge; Grenzwerte, Kennwerte und Wirkstoffe (wichtig für die NRV) werden ergänzt.
  - `spec_ki_uebernehmen()` füllt danach **nur leere** Stammdatenfelder. Vorhandene Werte bleiben.

Die Rückmeldung zeigt `rohstoff_erfassen_meldung()` oben auf der Seite (Preis gespeichert / Unterlage abgelegt / KI-Ergebnis).

## Wo der Knopf steht
- Übersicht „Rohstoff-Unterlagen“: letzte Spalte je Rohstoff
- Kasten „Unterlagen der Rohstoffe“ auf Anfrage- und Rezepturseite: je Zeile
- Rohstoff-Anfrage: unter „Rohstoff-Preis berechnen“ und je Alternative
