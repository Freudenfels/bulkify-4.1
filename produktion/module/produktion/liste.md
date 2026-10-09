# produktion/module/produktion/liste.php
Produktionsaufträge (`?p=liste`): nur **aktive** Aufträge (Vorbereitung + offen + laufend). Abgeschlossene stehen im **Archiv** (`?p=archiv`). Zeile klickbar → Detail.

**Reiter** (`.settabs`, über `?tab=`): nach Produzierbarkeit (aus `erp_pa_bereitschaft`) einsortiert, jeweils mit Zähler:
- **Alle** – alle aktiven.
- **Bereit** – produzierbar, noch nicht gestartet (`bereit`).
- **Laufend** – läuft bereits (`laeuft`, mind. ein Schritt erledigt).
- **Gesperrt** – nicht startbar: in Vorbereitung (nicht freigegeben) **oder** wartet auf Material (`wartet`).
Dashboard-Links (`?p=liste&tab=alle` / `tab=laufend`) landen so direkt im passenden Reiter. Unbekannter Reiter → Alle.

**Suche** (clientseitig, sofort): filtert über PR-Nummer, Auftragsnummer, Produkt, Darreichungsform und Kunde; Zähler „X angezeigt" aktualisiert sich live.

**Sortierung** (clientseitig, klickbare Spaltenköpfe `.bx-sort`, Pfeil zeigt Richtung, immer nur ein aktiver Kopf):
- **Produzierbar?** – **Standard**: „in Produktion" (laufend) und „produzierbar" (bereit) **immer oben**, Gesperrtes (Vorbereitung / wartet auf Material) unten; innerhalb der Gruppe nach Auftragseingang alt → neu. Rang aus `_eimer` (laufend 0, bereit 1, gesperrt 2) als `data-prio`; serverseitig vorsortiert (`usort` prio + Eingang) und über den Spaltenkopf umsortierbar.
- **Auftragseingang** – alt → neu; erneuter Klick kippt auf neu → alt.
- **Wann dran** (geplant_am) und **Status** (Rang vorbereitung→offen→laufend) ebenfalls sortierbar.
Reiterwechsel lädt neu (Zähler kommen vom Server); Suche und Sortierung laufen ohne Reload.

Spalten: Nr. (+Auftrag), Produkt (+Form), Kunde, Menge, Wann dran, Auftragseingang, Produzierbar? (`bereit_badge`), Fortschritt, Status (`pa_badge`). Gesperrte Zeilen sind abgeblendet.
