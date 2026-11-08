# core/charge_norm.php – Chargennummern tolerant vergleichen

**Zweck:** Eine zentrale Stelle für den toleranten Chargen-Vergleich (Soll-Handbuch 4.5 Wareneingang, 4.12 Labor).
Punkte, Leerzeichen, Bindestriche, Unterstriche, Schrägstriche und Groß-/Kleinschreibung zählen nicht:
„2799.A" = „2799A" = „2799 a" = „2799-a".

**Funktionen:**
- `charge_norm($nr)` – Normalform für PHP-Vergleiche.
- `charge_norm_sql('c.charge_nr')` – dieselbe Normalform als SQL-Ausdruck (nur feste Spaltennamen einsetzen, nie Eingaben).
  Vergleich: `charge_norm_sql('c.charge_nr') . ' = ?'` mit Parameter `charge_norm($eingabe)`.
- `charge_gleich($a, $b)` – true, wenn beide nicht leer und in Normalform gleich.

**Ohne Abhängigkeiten:** wird von `core/schema.php` geladen und kann später von der Lager-App (Wareneingang, Schritt 6)
direkt eingebunden werden, ohne `core/schema.php` (Sub-Apps dürfen das nicht laden).

**Genutzt von (08.11.2026):** `core/laboranalyse.php` (Bericht ↔ Charge zuordnen), Labor-Upload am Auftrag (`module/auftrag/detail.php`, Hinweis bei Abweichung).
