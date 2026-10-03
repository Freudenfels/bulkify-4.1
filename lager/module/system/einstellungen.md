# system/einstellungen.php – Lager-Einstellungen (`?p=einstellungen`, nur Admin)

Hub für alles rund ums Lager:
- **Etikett & Drucker**: Standard-Etikettengröße (lg_meta `etikett_format`) + Drucker-Name
  (lg_meta `drucker_name`, leer = Standarddrucker).
- **Brücke auf dem Lager-PC**: Status (läuft/aus), Downloads – „Brücke einrichten (Hintergrund)"
  (`?p=bruecke_skript&art=hintergrund`, legt Windows-Aufgabe an, unsichtbar + Autostart) und
  „mit Fenster (zum Testen)" (`?p=bruecke_skript`), SumatraPDF-Link, Kurzanleitung. Die Brücke macht
  BEIDES: Blinker leuchten + Etiketten drucken. (Keine `.vbs` mehr – wird von Browsern als Virus blockiert.)
- **Blinker/Sender**: Links zu `?p=sender` (verwalten) und `?p=leisten` (Blinker testen).

Menüeintrag „Einstellungen" (System, Admin). Druckweg: SumatraPDF, siehe `bruecke/bruecke.ps1`.
