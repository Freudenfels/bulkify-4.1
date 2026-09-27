# lager/core/erp.php – die Naht zum Dashboard

Die **einzige** Datei im Lager, die Tabellen des Dashboards kennt. Benennt jemand im Dashboard eine Spalte um, muss er nur diese Datei prüfen. Das gilt auch beim Umstieg auf v5: Dann wird nur diese Datei angepasst.

Stand heute wird hier nur gelesen, und zwar die Logins aus der Tabelle `benutzer`. Wenn später Buchungen dazukommen (zum Beispiel eine Entnahme für einen Produktionsauftrag), stehen sie hier als eigene, klar benannte Funktion.
