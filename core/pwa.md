# pwa.php – bulkify als App auf dem Handy

## Wozu
Es gibt keinen Play-Store-Download. bulkify ist eine **Web-App**: Android/Chrome und iPhone/Safari koennen die Seite auf den Startbildschirm legen, danach hat sie ein eigenes Icon, startet ohne Browserleiste und verhaelt sich wie eine App. Das ist dieselbe Software wie am Rechner – kein zweiter Datenstand, keine Synchronisation.

## Die drei Teile
| Teil | Datei | Wozu |
|---|---|---|
| Manifest | `public/manifest.webmanifest` | Name, Icon, Startadresse, Farben |
| Service Worker | `public/sw.js` | Voraussetzung fuers Installieren + Hinweisseite ohne Netz |
| Kopfzeilen | `core/pwa.php` → `pwa_head()` | verlinkt Manifest, Theme-Farbe, iPhone-Icon |

`pwa_script()` meldet den Service Worker an und steht am Seitenende. Beides steckt im internen Layout (`core/layout.php`), im Lieferantenportal (`module/lieferant/portal_layout.php`) und auf der Login-Seite.

## Startadresse
`start_url` ist `/` – also die normale Einstiegsseite. Der Router entscheidet dann selbst: nicht angemeldet → Login, Lieferant → Lieferantenportal, Team → Dashboard. Deshalb reicht **eine** App fuer alle; niemand braucht eine eigene.

## Was NICHT zwischengespeichert wird
Der Service Worker legt nur Dateien aus `/assets/` in den Cache (CSS, Icons). **Seiten mit Daten landen nie im Cache** – sonst koennte jemand ohne Anmeldung Kunden, Preise und Auftraege sehen, der das Geraet in die Hand bekommt. Ohne Netz erscheint `assets/offline.html`.

## Kundenportal
Bewusst **ohne** Manifest. Der Kunde kommt ueber einen persoenlichen Token-Link; eine installierte App wuerde auf `/` starten und ihn vor eine Login-Maske stellen, die er nicht bedienen kann.

## Grenzen
- Installieren geht nur ueber **HTTPS** (Ausnahme: localhost). Auf beta.bulkify.pro klappt es.
- Es gibt keine Push-Nachrichten und keinen Offline-Betrieb. Beides waere moeglich, ist aber bewusst nicht gebaut.
- Ein echtes APK fuer den Play Store waere ein eigener Schritt (Trusted Web Activity + Entwicklerkonto) und ist hier nicht enthalten.

## Icons
`public/assets/app-icon-*.png` – weisses bulkify-Logo auf `#10210F`. Erzeugt aus `bulkify-logo-white.png`; das maskable Icon hat mehr Rand, weil Android bis zu 20 % wegschneidet.
