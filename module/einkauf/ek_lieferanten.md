# module/einkauf/ek_lieferanten.php – EK-Lieferanten zuordnen

Seite `?p=ek_lieferanten` (Rolle einkauf/admin; Link aus „EK-Preise (Import)"). Ordnet die
**Text-Lieferanten** aus den EK-Preisen (Wellgreen, Vitanics, Buxtrade …) echten
`lieferanten`-Datensätzen zu bzw. legt sie neu an – und verknüpft alle Preise per id.

## Warum
EK-Import/Preise tragen den Lieferanten oft nur als **Text** (`ek_import.lieferant`,
`lieferant_preis.lieferant_name`), ohne `lieferant_id`. So sind sie kein „echter"
Lieferant (nicht managebar, kein Portal). Diese Seite schließt die Lücke.

## Funktion (`core/ek_ki.php`)
- `ek_lieferant_kandidat($name)` – bestehenden Lieferanten per normiertem Namen finden
  (klein, ohne Sonderzeichen/Leerzeichen → „vita actives" == „VitaActives").
- `ek_lieferant_zuordnen($name, $lid=null)` – verknüpfen (oder neu anlegen) und alle Verweise
  setzen: `ek_import.lieferant_id` (+ Text auf die Firma normalisiert), sowie
  `lieferant_preis`/`produkt_lieferant_preis.lieferant_id` per `lieferant_name` nachziehen.
- `ek_lief_ist_muell($name)` – Marktplatz/Platzhalter/Müll (Alibaba, „25kg", „5", <3 Zeichen)
  wird nicht angelegt.

## Bedienung
Liste je Text-Name: Zeilenzahl · Vorschlag (bestehender Lieferant oder „neu"). Pro Name
„verknüpfen"/„neu anlegen" oder Dropdown auf einen anderen Lieferanten. Bulk: „Alle mit
Treffer verknüpfen" + „Alle übrigen anlegen". Müll-Namen werden ignoriert.
