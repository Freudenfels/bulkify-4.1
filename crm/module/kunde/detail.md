# detail.php – ein Kunde (Cockpit)

Route `?p=kunde&id=…`, zwei Reiter:
- **Übersicht** – die **Vorgangs-Timeline** (Angebote/Aufträge/Rechnungen/Rezepturen mit Direkt-Sprung
  ins Dashboard, `kunde_timeline()` in `core/kunde_profil.php`) plus die CRM-Werkzeuge: **Wiedervorlage**,
  **To-Dos**, **Verlauf/Notiz**, **Fragenkatalog**, **KI-Rezepturvorschlag** und **Antwortvorschlag/Senden**.
- **Profil** (`&tab=profil`) – Stammdaten (nur lesend, Bearbeiten im Dashboard) + das **CRM-Profil**
  (`crm_kunde_profil`): Qualifizierung (`crm_segfelder()`) und freie Infos (`tun=profil_speichern`).

Dazu eine **To-Do-Karte** (`core/todo.php`): offene Aufgaben des Kunden anlegen/abhaken; sie erscheinen auch in der zentralen To-Do-Liste.

Das ist der Grund, warum es diese Seite gibt: Im Dashboard steht, was bestellt wurde – aber nirgends, was zuletzt besprochen wurde. Genau das steht hier.
