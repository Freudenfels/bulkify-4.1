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
Der Service Worker legt nur **Icons und die Offline-Seite** in den Cache. **CSS kommt immer frisch vom Server** – sonst sieht man nach einem Deploy die alte Oberflaeche und haelt sie fuer kaputt. **Seiten mit Daten landen nie im Cache** – sonst koennte jemand ohne Anmeldung Kunden, Preise und Auftraege sehen, der das Geraet in die Hand bekommt. Ohne Netz erscheint `assets/offline.html`.

## Kundenportal
Bewusst **ohne** Manifest. Der Kunde kommt ueber einen persoenlichen Token-Link; eine installierte App wuerde auf `/` starten und ihn vor eine Login-Maske stellen, die er nicht bedienen kann.

## Grenzen
- Installieren geht nur ueber **HTTPS** (Ausnahme: localhost). Auf beta.bulkify.pro klappt es.
- Es gibt keine Push-Nachrichten und keinen Offline-Betrieb. Beides waere moeglich, ist aber bewusst nicht gebaut.
- Ein echtes APK fuer den Play Store waere ein eigener Schritt (Trusted Web Activity + Entwicklerkonto) und ist hier nicht enthalten.

## Icons
`public/assets/app-icon-*.png` – weisses bulkify-Logo auf `#10210F`. Erzeugt aus `bulkify-logo-white.png`; das maskable Icon hat mehr Rand, weil Android bis zu 20 % wegschneidet.

## Handy-Layout (Burger-Menü)
Bis **860 px** Fensterbreite liegt die Menüleiste nicht mehr neben dem Inhalt, sondern als **Schublade** darüber:

- Oben klebt eine schmale Kopfleiste mit **Burger** und Logo (`bx_mobilbar()`, in `core/layout.php`).
- Der Burger schiebt die Leiste ein; dahinter liegt eine dunkle Fläche (`bx_menue_scrim()`), Tippen darauf schließt sie. Genauso: Escape, ein Klick auf einen Menüpunkt, oder wenn das Fenster wieder breit wird.
- Geschaltet wird über `data-menue="auf"` am `<html>`-Element (`bx_menue_script()`), das CSS macht den Rest.
- Das Einklappen vom Rechner (`data-side="zu"`) gilt auf dem Handy **nicht** – dort entscheidet allein der Burger.

Dazu im selben Media-Query: Tabellen scrollen seitlich (`.bx-tablewrap{overflow-x:auto}`) statt abgeschnitten zu werden, Formularspalten stehen untereinander, Überschriften sind kleiner, Knöpfe fingerbreit.

Alle drei Oberflächen nutzen dieselben drei Bausteine: internes Dashboard, Lieferantenportal, Kundenportal. Login- und Einladungsseite haben kein Menü und bleiben außen vor.
