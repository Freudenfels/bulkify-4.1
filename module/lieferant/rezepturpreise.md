# lieferant/rezepturpreise.php – „Rezeptur-Preise" (Fremdfertigung, Lieferantenportal)

**Zweck:** Der Lieferant trägt je **Rezeptur** seinen **Fremdfertigungspreis** ein (z. B. je Kapsel). **Kein „Annehmen"** – mehrere Lieferanten stehen nebeneinander und **unterbieten sich**; das Team sieht alle Preise und nimmt den günstigsten (interne Übersicht `?p=rezept_preise`). Route `?p=lieferant_rezepturpreise`, Menüpunkt „Rezeptur-Preise".

**Daten:** `rezeptur_lief_angebot` (rezeptur_id · lieferant_id · preis · einheit · **stand**) + Staffeln in `rezeptur_lief_angebot_staffel` (ab_menge · preis). Beide aus dem v3-Import (Stufe 4, Quelle `lieferant_angebot` + `lieferant_angebot_staffel`).

**Bedienung:**
- Tabelle: Rezeptur · Form · **Ihr Preis** (inline editierbar, „Aktualisieren" setzt `stand=heute`) · Stand (mit „!" wenn überfällig) · Löschen.
- „**Weitere Rezeptur bepreisen**": tippbare Rezepturauswahl + Preis → neue Zeile (`status='angeboten'`, `stand=heute`).
- **4-Wochen-Regel:** ist der neueste `stand` älter als `lieferanten.preis_intervall_tage` (Standard 28), roter Hinweis + „Alle als aktuell bestätigen" (setzt alle `stand=heute`).

**Intern:** `module/rezeptur/lief_preise.php` (`?p=rezept_preise`, Menü Produkt → Rezeptur-Preise) listet alle Lieferantenpreise je Rezeptur, sortiert nach Preis; der **günstigste** je Rezeptur ist mit Badge „günstigster" markiert. EK (Herstellpreis) + empf. VK (EK × Marge).
