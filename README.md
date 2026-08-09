# LoxBerry-Plugin: Matter to Loxone

Bindet **Matter-Geräte** an Loxone an — Lampen, Steckdosen, Sensoren,
Thermostate, Behänge. Übersetzt Matter-Attribute in sprechende MQTT-Themen und
nimmt umgekehrt Schaltbefehle von Loxone entgegen.

> **Fassung 0.9.1 — ungeprüft am echten Gerät.** Gebaut ohne Matter-Hardware
> und ohne laufenden Matter-Server; geprüft gegen eine Attrappe, die das
> dokumentierte Protokoll nachbildet. Deshalb 0.9.1 und nicht 1.0.0.

## Neu in 0.9.2

**`abruf` scheiterte, wenn kein Gerät im Zwischenspeicher stand.**
Gemeldet von einem Mitleser als „Edge-Case, in der Praxis fast irrelevant" —
nachgestellt ist er es nicht. `&geraet=` steht ohne Angabe auf 1, und die
Prüfung auf ein unbekanntes Gerät stand **vor** der Auswertung der Aktion:

```
ohne Abbild (frisch installiert):
  MATTER;OK=0;GRUND=GERAET_UNBEKANNT;N=0;ALTER=-1
Dienst läuft, Verbindung zum Matter-Server verloren, geraete leer:
  MATTER;OK=0;GRUND=GERAET_UNBEKANNT;N=0;ALTER=…
```

Der zweite Fall ist der wunde Punkt: Die Geräte stehen in der Fabric, nur der
Zwischenspeicher ist leer — also genau die Lage, in der man `abruf` aufruft.
Der Befehl, der sie beheben soll, war dann gesperrt, und die Begründung
(„Gerät unbekannt") führte auf die falsche Fährte.

Dass es ein Versehen war und keine Absicht, steht im selben Skript: Von der
Steuerungsfreigabe war `abruf` seit jeher ausgenommen (`$mt_aktion !== 'abruf'`)
— an einer Stelle also gerätunabhängig behandelt, an der anderen nicht.
Gerätunabhängige Aktionen werden jetzt vor der Geräteprüfung abgefangen; der
Befehl landet in beiden Fällen in der Warteschlange. Gerätegebundene Aktionen
(`ein`, `helligkeit`, …) bleiben unverändert geschützt.

**Ohne JavaScript war die Oberfläche leer.**
`.sm-seite` steht auf `display:none`, sichtbar wird eine Fläche erst durch
`.sm-active` — und diese Klasse setzte ausschließlich das JavaScript am
Seitenende. Im ausgelieferten HTML kam `sm-active` gar nicht vor, nur in den
zwei CSS-Regeln. Kacheln und Reiterleiste standen da, darunter nichts.
`$mt_tab` wurde serverseitig längst ermittelt, aber nur ans JavaScript
weitergereicht. Jetzt setzt der Server die Klasse selbst; alle sechs Reiter
sind über `?form=…` auch ohne JavaScript erreichbar.

**Sechs PHP-8-Warnungen mitten in der Seite.**
Zugriffe wie `$mt_g['hersteller']` und `$srv['schema_version']` gingen davon
aus, dass `loxone.json` alle Schlüssel enthält. Die Datei überdauert
Aktualisierungen und kann aus einer älteren Fassung stammen. Fehlt dann ein
Schlüssel, ist das unter PHP 7.4 eine Notice, die das `error_reporting`
verschluckt — unter PHP 8 eine Warning, und die steht im Seitenkörper, einmal
je Gerät und Spalte. Beide Fassungen liefern jetzt zeichengleiche Ausgabe ohne
eine einzige Meldung.

**`uninstall` ergänzt — vor allem wegen des Containers.**
Der Matter-Server-Container wird mit `--restart=unless-stopped` angelegt.
Docker startet ihn damit bei jedem Systemstart wieder, auch lange nachdem das
Plugin entfernt wurde: Port 5580 belegt, Fabric gehalten, in keiner
LoxBerry-Übersicht mehr sichtbar. Dazu entfernt die Datei die
Konfigurationssicherung außerhalb des Plugin-Ordners (darin stehen
WLAN-Passwort, Thread-Dataset und Aktionstoken) und weist darauf hin, dass mit
dem Datenordner die Fabric verschwindet.

Außerdem: `statusalle` und `abruf` waren im Kopfkommentar des Endpunkts und in
dieser Tabelle nicht aufgeführt, obwohl beide seit 0.9.1 vorhanden sind.
`bin/__pycache__/` entfernt. 409 Sprachschlüssel, deutsch und englisch
deckungsgleich, keiner verwaist.

## Neu in 0.9.1

**Fünf weitere Cluster** — damit sind es 22 statt 17:

| Cluster | Nummer | Wofür |
|---|---|---|
| `OperationalState` | 0x0060 | Betriebszustand, laufende Phase, Restzeit |
| `LaundryWasherMode` | 0x0051 | Waschprogramm |
| `ElectricalEnergyMeasurement` | 0x0091 | Energie, Bezug und Einspeisung getrennt |
| `WaterHeaterManagement` | 0x0094 | Warmwasser: Heizanforderung, Füllstand, Boost |
| `EnergyEvse` | 0x0099 | Wallbox: Zustand, Fehler, Ladestand, Strom, Dauer, Energie |

**Bessere Grenzen im Virtuellen Eingang.** Bis 0.9.0 bekam *jeder* Wert
`Analog="true"` und `MinVal`/`MaxVal` auf Anschlag (±2 147 483 647). Loxone
zieht aus diesen Grenzen aber die Reglerbereiche und die
Plausibilitätsprüfung — wer alles offen lässt, verschenkt beides, und ein
Schalter wird zum Analogwert über vier Milliarden Stufen. Jetzt leiten sich
Analog/Digital und die Grenzen aus dem Attributtyp ab; die Cluster-Tabelle darf
je Attribut genauere mitgeben.

**Eine Vorlage für den Virtuellen Ausgang.** Bis 0.9.0 gab es dafür keine — der
Anwender baute jeden Ausgang samt Adresse von Hand, und das ist die
aufwendigere Hälfte der Arbeit.

**Ehrlich bei der Energiestruktur.** `ElectricalEnergyMeasurement` liefert
keine blanke Zahl, sondern eine Struktur. Wie der Matter-Server sie über die
WebSocket-Schnittstelle benennt, ließ sich ohne ein solches Gerät nicht
nachmessen. Das Plugin nimmt deshalb beide gängigen Formen an — Feldname und
Feldnummer aus der Spezifikation — und gibt **nichts** zurück, wenn keine
passt, statt eine erfundene Zahl.

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
| `?token=T&aktion=statusalle` | **alle** Geräte in einer Zeile, Marken mit Gerätenummer (`MATTER_3_1_TEMPERATUR`) — das ist der Endpunkt der Sammelvorlage |
| `?token=T&aktion=wert&geraet=N&endpunkt=E&thema=X` | **nur die Zahl** — ein virtueller HTTP-Eingang braucht dann keine Befehlserkennung |
| `?token=T&aktion=liste` | alle Geräte |
| `?token=T&aktion=roh` | vollständiges Abbild als JSON |
| `?token=T&aktion=abruf` | Sofortabruf aller Knoten anstoßen — **gerätunabhängig**, braucht kein `&geraet=` und keine Steuerungsfreigabe |
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
