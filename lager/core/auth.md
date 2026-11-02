# lager/core/auth.php – Anmeldung

Es gelten dieselben Logins wie im Dashboard, das Lager hat aber eine eigene Sitzung. Hinein darf jeder Mitarbeiter mit einer Rolle, Lieferanten-Zugänge nicht (`lg_darf_rein`). Die Seite „Sender und Brücke“ sehen nur Admins (`lg_ist_admin`).

## QM-Gate (St-11)
Quarantäne-Ware freigeben (Status auf „frei") darf nur QM (Rolle `labor`) oder Admin – Dashboard `darf_qm_freigeben()`, Lager-App `lg_darf_qm_freigeben()`. Andere Rollen sehen „Freigabe durch QM" statt des Knopfs; ein direkter POST wird abgewiesen. Sperren oder zurück in Quarantäne darf weiterhin jeder.
