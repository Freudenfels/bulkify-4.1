# system/einstellungen.php – Lager-Einstellungen (`?p=einstellungen`, nur Admin)

Hub für alles rund ums Lager:
- **Etikett & Drucker**: Standard-Etikettengröße (lg_meta `etikett_format`) + Drucker-Name
  (lg_meta `drucker_name`, leer = Standarddrucker).
- **Brücke auf dem Lager-PC**: Status (läuft/aus), Download der kombinierten Brücke (`?p=bruecke_skript`),
  SumatraPDF-Link, Kurzanleitung. Die Brücke macht BEIDES: Blinker leuchten + Etiketten drucken.
- **Blinker/Sender**: Links zu `?p=sender` (verwalten) und `?p=leisten` (Blinker testen).

Menüeintrag „Einstellungen" (System, Admin). Druckweg: SumatraPDF, siehe `bruecke/bruecke.ps1`.
