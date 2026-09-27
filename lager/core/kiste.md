# lager/core/kiste.php – Kisten (Behälter mit einem Blinker)

Eine **Kiste** hat einen Blinker und fasst viele verschiedene Chargen. So muss nicht an jedes Kleinteil ein Blinker. Sucht man ein Produkt, blinkt die ganze Kiste; ein optionaler **Fach**-Hinweis („vorne links“) sagt, wo es in der Kiste liegt.

- Tabellen: `lg_kiste` (Name, Notiz), `lg_kiste_inhalt` (kiste_id, charge_id, fach – eine Charge in höchstens einer Kiste), und `lg_leiste.kiste_id` (Blinker an einer Kiste statt an einer Charge).
- `kiste_anlegen/speichern/loeschen`, `kiste_alle`, `kiste_inhalt`, `kiste_fuer_charge`.
- `kiste_charge_zuordnen($kiste,$charge,$fach)` / `kiste_charge_entfernen`. Lehnt ab, wenn die Charge schon einen eigenen Blinker hat oder in einer anderen Kiste liegt.
- `leiste_binden_kiste($code,$kiste)` – Blinker an eine Kiste hängen.
- **`blinker_fuer_charge($charge)`** – der Auflöser fürs Finden: eigener Blinker der Charge, sonst der Blinker der Kiste, in der sie liegt, plus Ort-Text „Kiste X, Fach Y“.
