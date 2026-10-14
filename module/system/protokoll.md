# protokoll.php – Admin-Protokoll (wer hat wann was)

Route `?p=protokoll` (nur Admin), Menü System → Protokoll. Zeigt zentral die Tabelle `aktivitaet`, die
quer im System über `log_aktivitaet()` gefüllt wird (Statuswechsel, Angebote, Bestellungen, Rückfragen/
Nachrichten, PIB-Downloads …), neueste zuerst (max. 500).

Spalten: Zeitpunkt · Wer · Aktion (+ Typ) · Bereich · Objekt (verlinkt, wo es eine Seite gibt).
Filter: Freitext (Text/Person/Typ), Bereich (objekt_typ), Akteur (Team/Kunde/Lieferant/System).

**Wer:** Bei Team-Aktionen hält `log_aktivitaet()` jetzt die handelnde Person fest (`aktivitaet.benutzer`
= `current_user()['name']`); Kunde/Lieferant/System ohne Namen (die stecken im verknüpften Objekt).

Hinweis: Die Abdeckung hängt davon ab, wo `log_aktivitaet()` schon aufgerufen wird. Viele zentrale
Aktionen sind drin; weitere Bearbeitungs-/Speicher-Pfade lassen sich bei Bedarf mit einem
`log_aktivitaet(...)`-Aufruf ergänzen.
