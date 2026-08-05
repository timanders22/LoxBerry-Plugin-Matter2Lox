# LoxBerry-Plugin: Matter to Loxone

Bindet **Matter-Geräte** an Loxone an — Lampen, Steckdosen, Sensoren,
Thermostate, Behänge. Übersetzt Matter-Attribute in sprechende MQTT-Themen und
nimmt umgekehrt Schaltbefehle von Loxone entgegen.

> **Fassung 0.9.0 — ungeprüft am echten Gerät.** Gebaut ohne Matter-Hardware
> und ohne laufenden Matter-Server; geprüft gegen eine Attrappe, die das
> dokumentierte Protokoll nachbildet. Deshalb 0.9.0 und nicht 1.0.0.

## Das Plugin ist die Brücke, nicht der Controller

Matter verlangt einen zertifizierten Controller mit eigener Fabric,
Zertifikaten, Bluetooth-Inbetriebnahme und IPv6-Multicast. Den gibt es fertig:
den **Matter-Server der Open Home Foundation** — dasselbe Stück, das Home
Assistant benutzt, CSA-zertifiziert. Er läuft in einem Container; dieses Plugin
spricht seine WebSocket-Schnittstelle an.

Das ist kein Umweg, sondern die einzige seriöse Bauweise. Der Unterschied zum
heutigen Zustand ist, dass **kein Home Assistant und kein Node-RED mehr nötig
sind**: Container, Brücke und Loxone-Anbindung liegen in einem Plugin.

    Matter-Gerät ──Thread/WLAN──> Matter-Server (Container)
                                        │ WebSocket
                                  Brückendienst (dieses Plugin)
                                        │ MQTT / HTTP
                                     Miniserver

## Drei Voraussetzungen, die das Plugin nicht herstellen kann

| | |
|---|---|
| **IPv6** | Matter beruht auf IPv6-Link-Local-Multicast. Ohne IPv6 läuft gar nichts. |
| **Flaches Netz** | Multicast-Filter (bei Unifi/Omada „Multicast Optimization"), mDNS-Weiterleitungen und VLAN-Trennung machen Matter kaputt — und zwar so, dass alles richtig eingerichtet aussieht. |
| **64 Bit** | Der Matter-Server unterstützt ausdrücklich nur 64-Bit-Systeme. |

Thread-Geräte brauchen zusätzlich einen Thread-Border-Router im Haus (Apple TV,
HomePod, Google Nest Hub, eigener Router). Der LoxBerry ist keiner.

## Aufbau

    bin/matter_dienst.py      Brückendienst: WebSocket, Übersetzung,
                              MQTT-Veröffentlichung, Befehlswarteschlange
    bin/dienst.sh             Start, Stopp, Wächter
    cron/cron.01min           minütlicher Wächter
    templates/matter_cluster.json   die Übersetzungstabelle — EINE Datei
                              für Dienst und Oberfläche
    webfrontend/htmlauth/     Oberfläche (sechs Reiter)
    webfrontend/html/         Endpunkt für den Miniserver + Bibliothek

Im venv liegt genau **ein** Paket: `websockets` (reines Python, keine
Übersetzung nötig, ab Python 3.9).

## Was übersetzt wird

Matter liefert Zahlen ohne Einheit. Das Plugin rechnet um, nach der
Matter-Spezifikation:

| Cluster | wird zu | Umrechnung |
|---|---|---|
| OnOff | `schalter` | 0/1 |
| LevelControl | `helligkeit` | 0–254 → 0–100 % |
| ColorControl | `farbtemperatur_mired`, `saettigung` | — |
| TemperatureMeasurement | `temperatur` | ÷100 |
| RelativeHumidityMeasurement | `feuchte` | ÷100 |
| IlluminanceMeasurement | `helligkeit_lux` | 10^((v−1)/10000) |
| OccupancySensing | `bewegung` | unterstes Bit |
| BooleanState | `kontakt` | 0/1 |
| Thermostat | `ist_temperatur`, `soll_heizen`, `soll_kuehlen`, `betriebsart` | ÷100 |
| WindowCovering | `position` | ÷100, 0 = ganz offen |
| PowerSource | `batterie` | ÷2 (halbe Prozent) |
| ElectricalPowerMeasurement | `leistung`, `spannung`, `strom` | ÷1000 |

Vollständig im Reiter *MQTT*. Unbekannte Attribute lassen sich auf Wunsch roh
mitveröffentlichen — verloren geht nichts.

## Endpunkte für Loxone

Alle Aufrufe brauchen das Token aus dem Reiter *Einbindung in Loxone*.

| Aufruf | Zweck |
|---|---|
| `?token=T&aktion=status&geraet=N` | alle Werte eines Geräts in einer Zeile |
| `?token=T&aktion=wert&geraet=N&endpunkt=E&thema=X` | **nur die Zahl** — ein virtueller HTTP-Eingang braucht dann keine Befehlserkennung |
| `?token=T&aktion=liste` | alle Geräte |
| `?token=T&aktion=roh` | vollständiges Abbild als JSON |
| `?token=T&aktion=ein\|aus\|umschalten&geraet=N&endpunkt=E` | schalten |
| `?token=T&aktion=helligkeit&wert=0..100` | dimmen |
| `?token=T&aktion=farbtemperatur&wert=<Kelvin>` | Farbtemperatur |
| `?token=T&aktion=rollo&wert=0..100` | Behang (0 = ganz offen) |
| `?token=T&aktion=soll_heizen&wert=<Grad>` | Thermostat |
| `?token=T&aktion=attribut&pfad=E/C/A&wert=…` | **Rohzugriff**: beliebiges Attribut schreiben |
| `?token=T&aktion=befehl&cluster=N&name=X&nutzlast=<JSON>` | **Rohzugriff**: beliebiger Cluster-Befehl |

**Ein Strich als Wert** heißt: dieses Feld gibt es bei diesem Gerät nicht. Es
wird bewusst keine 0 gesendet — eine 0 wäre eine stille Falschaussage.

## Anlernen

Im Reiter *Geräte anlernen*: WLAN-Zugangsdaten hinterlegen und an den Server
übergeben, dann den Code vom Gerät eintippen (QR-Text `MT:…` oder elfstelliger
Kopplungscode). Das dauert bis zu zwei Minuten.

Ein Gerät kann **in mehreren Fabrics gleichzeitig** sein: wer es schon in Apple
Home oder Google Home hat, lässt dort ein Kopplungsfenster öffnen und benutzt
den angezeigten Code hier. Umgekehrt geht es genauso.

## Der Datenordner ist heilig

In `data/plugins/matter2lox/matter` liegen Fabric und Zertifikate. *Container
entfernen* löscht nur den Container; die angelernten Geräte überleben. Wer den
Datenordner löscht, muss jedes Gerät zurücksetzen und neu anlernen.

## Lizenz

MIT — siehe [LICENSE](LICENSE). Alle Protokollangaben stammen aus
[python-matter-server](https://github.com/matter-js/python-matter-server)
(Apache 2.0).

## Der Matter-Server ist archiviert — was das heißt

Nachgesehen am 06.08.2026, und der Befund ist deutlicher als erwartet:

* Das Projekt ist von `home-assistant-libs` in die Organisation `matter-js`
  umgezogen. Der alte Verweis leitet weiter.
* Das Repository wurde am **23.06.2026 vom Eigentümer archiviert** und ist
  seither schreibgeschützt.
* Das Container-Abbild `ghcr.io/matter-js/python-matter-server:stable` **gibt
  es weiterhin** — Fassung 8.1.2, veröffentlicht vor rund acht Monaten, über
  1,1 Millionen Abrufe. Das Plugin läuft also.
* Nachfolger derselben Organisation ist
  [matterjs-server](https://github.com/matter-js/matterjs-server), in
  TypeScript neu gebaut und aktiv gepflegt.

**Was das praktisch bedeutet:** Es kommen keine neuen Fassungen des Servers
mehr; neue Gerätetypen und Fehlerbehebungen entstehen im Nachfolger. Bestehende
Installationen laufen weiter, solange das Abbild abrufbar bleibt.

**Was ausdrücklich nicht geprüft ist:** ob `matterjs-server` dieselbe
WebSocket-Schnittstelle spricht. Sollte er das nicht tun, wäre für den Umstieg
eine Anpassung des Brückendienstes nötig. Das ist eine offene Frage, keine
Vermutung in die eine oder andere Richtung.
