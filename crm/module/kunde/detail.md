# detail.php – ein Kunde

Route `?p=kunde&id=…`. Zeigt, was das Dashboard über den Kunden weiß (nur lesend), und was das CRM ergänzt: **Verlauf**, **Wiedervorlage**, **Fragenkatalog**, **KI-Rezepturvorschlag** (Freitext-Idee → Vorschlag, `core/rezeptur_ki.php`, gespeichert in `crm_rezeptur_ki`) und **Antwortvorschlag**.

Das ist der Grund, warum es diese Seite gibt: Im Dashboard steht, was bestellt wurde – aber nirgends, was zuletzt besprochen wurde. Genau das steht hier.
