# LoxBerry-Plugin: Matter to Loxone

Bindet **Matter-Geräte** an Loxone an — Lampen, Steckdosen, Sensoren,
Thermostate, Behänge. Übersetzt Matter-Attribute in sprechende MQTT-Themen und
nimmt umgekehrt Schaltbefehle von Loxone entgegen.

> **Ungeprüft am echten Gerät.** Gebaut ohne Matter-Hardware und ohne
> laufenden Matter-Server; geprüft gegen eine Attrappe, die das dokumentierte
> Protokoll nachbildet. Deshalb 0.9.x und nicht 1.0.0. Was sich nur an einer
> echten Anlage messen lässt, steht am Ende dieser Datei unter *Was nicht
> geprüft ist*.

## Neu in 0.9.19

**Eine Prüfzeile im Reiter *Test*: „Antwortet der Border-Router?"** Sie fragt
dieselbe Adresse ab wie der Knopf im Reiter *Geräte anlernen* — und
**speichert nichts**. Das ist ihr ganzer Zweck: der Vorbehalt aus 0.9.18 („an
einem laufenden Border-Router hat den Abruf niemand gemessen") lässt sich
damit ausräumen, ohne dass ein Prüflauf die hinterlegten Netzdaten anfasst.

Drei Ausgänge, und der Strich ist einer davon:

| Lage | Zeile |
|---|---|
| keine Adresse eingetragen | Strich — die Zeile trifft nicht zu; das Dataset lässt sich auch von Hand eintragen |
| Dataset geliefert | Häkchen, mit der Länge und dem Hinweis, ob es zum gespeicherten passt, davon abweicht oder ob noch keines gespeichert ist |
| Adresse steht da, Abruf trägt nicht | Kreuz, mit der Meldung, woran es lag — abgewiesene Adresse, keine Antwort, HTTP-Code, kein Dataset |

Ein abweichendes Dataset ist ausdrücklich **kein** Kreuz: es heißt, dass am
Border-Router ein neues Thread-Netz angelegt wurde. Ob man es übernehmen will,
entscheidet der Bediener mit dem Knopf, nicht die Prüfzeile.

Das Dataset selbst erscheint **weder im Antworttext noch im
Zwischenspeicher** — es ist ein Netzschlüssel, und die Zeile merkt sich nur
seine Länge und ob es zum gespeicherten passt. Zwischengespeichert wird wie
bei den beiden anderen Netzzeilen (120 Sekunden, Schlüssel ist die Adresse),
und wie sie läuft sie nur, wenn der Reiter *Test* der offene ist.

## Neu in 0.9.17

**Die Matter-Fabric überlebte kein Plugin-Update.** Der Container bekam
`data/plugins/matter2lox/matter` als `/data` eingehängt — und genau diesen
Baum räumt der LoxBerry-Installer bei jedem Upgrade vollständig ab. Am
`plugininstall.pl` nachgemessen (Zweig `master`, 03.09.2026): der
Upgrade-Zweig ruft in Zeile 886 `purge_installation`, und die löscht in Zeile
1631 `data/plugins/<ordner>/` ohne jede Bedingung — anders als den Log-Ordner,
der an `"all"` hängt und ein Upgrade übersteht. `preupgrade.sh` sicherte davon
nichts. Weil `AUTOMATIC_UPDATES` an ist, wäre das ohne Zutun des Anwenders
passiert: **jedes angelernte Gerät hätte nach jedem Update neu angelernt
werden müssen.** Drei Texte im Plugin behaupteten dabei das Gegenteil, unter
anderem der Warnkasten „Der Datenordner wird nie mitgelöscht".

Fabric und Zertifikate liegen jetzt **neben** dem Datenordner
(`data/plugins/matter2lox.matter`). Was neben dem Ordner liegt, überlebt —
dieselbe Bauart hat die Zweitschrift der Konfiguration seit jeher.
`preupgrade.sh` zieht einen vorhandenen Bestand einmalig um.

> **Nach dem Update auf 0.9.17 einmal Hand anlegen:** Der laufende Container
> zeigt noch auf den alten Pfad. Im Reiter *Einstellungen* einmal
> *Container entfernen* und dann *Container anlegen* drücken. Die angelernten
> Geräte bleiben dabei erhalten — die Fabric ist ja umgezogen, nicht gelöscht.
> Der Reiter *Test* sagt in der Zeile „Liegt die Matter-Fabric an der
> richtigen Stelle?", ob das noch aussteht.

**Dieselbe Falle traf die Gerätenummern.** `nummern.json` lag im selben
gelöschten Baum. Nach jedem Update entstanden die Nummern neu aus der
sortierten Knotenliste — und war zwischenzeitlich ein Gerät aus der Fabric
gefallen, zeigten `MATTER_3_…`, `geraet3/…` und `&geraet=3` danach still auf
ein anderes Gerät. Genau der Fehler, den 0.9.10 behoben hat, kehrte bei jedem
Update zurück. Auch diese Datei liegt jetzt neben dem Ordner.

**Eine unvollständige Sicherungsdatei setzte alles zurück.** Beim
Zurückspielen wurden nur *unbekannte* Schlüssel beanstandet; ein *fehlender*
wurde lautlos durch die Werkseinstellung ersetzt. Eine Datei mit einem
einzigen bekannten Schlüssel wurde angenommen („1 Wert übernommen") und
räumte dabei Aktionstoken, WLAN-Passwort, Thread-Dataset und beide Freigaben
ab. Beim nächsten Seitenaufruf wurde der Werkszustand dann auch noch über die
Zweitschrift geschrieben. Jetzt gilt die eigene Regel auch hier: **eine halb
gültige Datei ändert gar nichts.**

**Beim Zurückspielen wurde kein einziger Wert geprüft.** Nur die Schlüssel.
Ein Feld statt einer Zeichenkette im `aktionstoken` machte aus dem Vergleich
im Endpunkt die Zeichenkette `Array` — der Endpunkt war damit mit
`?token=Array` bedienbar, samt allen schaltenden Befehlen. Eine Zeichenkette
statt einer Zahl im `server_port` ließ den Dienst bei jedem Start mit
`ValueError` sterben, und der minütliche Wächter startete ihn endlos neu.
Jetzt läuft jeder Wert durch dieselbe Prüfung wie im Formular.

**Der unangemeldete Endpunkt legte Dateien an, bevor das Token geprüft war.**
Gemessen: ein Aufruf ohne Token hinterließ bei beschädigter Konfiguration drei
neue Dateien und schrieb die Konfiguration aus der Zweitschrift zurück. Die
Lesefunktion hat jetzt einen Schalter; der Endpunkt ruft sie nur lesend auf.

**Die Rohdurchreichung ließ sich nicht einschalten.** Ihr Haken steht im
Reiter *MQTT*, gelesen wurde er im Handler des Reiters *Einstellungen* — der
ihn nie mitgeschickt bekommt. Folge: jedes Speichern der Einstellungen
schaltete sie ab, und der Haken selbst tat nichts.

**Zustände gehen jetzt retained hinaus.** Bis 0.9.16 wurde gar nichts
retained veröffentlicht: nach einem Neustart des Brokers, des Gateways oder
des Miniservers war ein Fensterkontakt, der sich zwei Tage nicht bewegt, zwei
Tage lang unbekannt. Zustände (Schalter, Kontakt, Schloss, Erreichbarkeit)
sind jetzt retained, Messwerte mit Zeitbezug nicht, und das Lebenszeichen
bleibt es nie — retained zeigte es immer „lebt".

Dazu, kürzer:

* Der Dienst wird nach einem Update wieder gestartet, wenn er vorher lief;
  `preupgrade.sh` hält ihn sauber an (erst der Sollmerker, dann der Prozess) —
  bis 0.9.16 startete ihn der Wächter mitten ins Update hinein, und danach
  lief er gar nicht wieder an.
* `?selftest=1&token=…` am Endpunkt, mit den drei festgelegten Antworten.
* Vier Pflichtzeilen im Reiter *Test*: Konfigurationslage, Formularmerkmal,
  Themenliste gegen Sendecode, Eindeutigkeit der Suchmuster. Dazu die Zeile
  zur Fabric.
* Die Selbstprüfung läuft nur noch, wenn der Reiter *Test* der offene ist.
  Bis 0.9.16 liefen ihre Netzabrufe bei **jedem** Seitenaufruf.
* Der Merker „zuletzt gesendet" wird erst nach dem Senden fortgeschrieben und
  nur für das, was wirklich hinausging.
* Ein Verbindungsabriss bleibt nicht mehr stumm: der Lauschauftrag wird
  ausgewertet. Ein Fehler im eigenen Code wird nicht mehr als
  Verbindungsstörung etikettiert.
* Die Warteschlange trägt einen Zeitstempel; ein Stellbefehl verfällt nach
  fünf Minuten, statt Tage später überraschend zu wirken. Ihre Dateien
  bekommen `0600` — sie tragen WLAN-Passwort und Anlerncode.
* Die Wartezeit wird nicht mehr still auf 20 s gekappt; das Anlernen darf
  seine zwei Minuten haben, wie Hilfe und Knopftext es sagen.
* Prozesserkennung argumentweise statt als Teilzeichenkette.
* Der Farbton kommt zusätzlich in Grad (`farbton_grad`), passend zum
  schreibenden Befehl — wie schon die Farbtemperatur in Kelvin.
* Die Knopffarben stimmen wieder mit der Legende überein; „Log leeren" räumt
  auch die rotierte Hälfte.

## Neu in 0.9.16

Der Abo-Satz im Reiter *MQTT* und im Reiter *Einbindung in Loxone* greift der
Gateway-Fassung nicht mehr vor: unter V2 steht dort, dass nichts einzutragen
ist. Dazu der Abstieg auf den Benutzer `loxberry` in `bin/dienst.sh`.
(0.9.17 hat die Ortsangabe nachgetragen, die dabei im Fall „Fassung nicht
feststellbar" verlorengegangen war.)

## Neu in 0.9.15

`clearstatcache()` vor der Log-Kappung. In einem langlebigen Prozess hält PHP
die Antwort von `stat()` zwischen, und die Kappung öffnet dann nie. Folgen
hatte das hier nicht — die PHP-Aufrufer dieses Plugins sind alle kurzlebig,
und der Dauerläufer ist Python und rotiert selbst.

## Neu in 0.9.14

Ein Wachposten gegen fremde Formulare: jedes der Formulare trägt ein Merkmal,
das aus einem Merkwort abgeleitet wird, und eine zentrale Prüfung steht vor
allen Handlern. Ohne sie genügte ein einziger fremder POST im Browser eines
angemeldeten Bedieners, um das Aktionstoken neu zu würfeln — danach
beantwortet der Endpunkt jeden virtuellen Eingang mit 403, und ein virtueller
Eingang wertet die Antwort nicht aus. Der Ausfall bliebe still.

## Neu in 0.9.13

Die Handler der Sicherungsknöpfe stehen vor `LBWeb::lbheader()`. Dahinter ist
der Seitenkopf geschrieben, `header()` kommt zu spät, und der Knopf lieferte
eine HTML-Seite mit angehängtem JSON statt einer Datei.

## Neu in 0.9.12

Die Knöpfe *Einstellungen sichern* und *Einstellungen zurückspielen*. Zweck
ist der Umzug auf einen zweiten LoxBerry; die Datei trägt deshalb das
Aktionstoken und alle Zugangsdaten, und der Hinweis am Knopf sagt das.

## Neu in 0.9.11

**Eine Korrektur, und sie betrifft die Anleitung, nicht den Code.**

In 0.9.10 trug die Baustein-Liste im Reiter *Einbindung in Loxone* noch das
alte Suchmuster — `\iALTER=\i\v` statt `\i;ALTER=\i\v`, und ebenso bei
`1_SCHALTER` und `1_HELLIGKEIT`. Die erzeugte XML-Vorlage war seit 0.9.10
richtig; die Tabelle daneben, die man **von Hand** in Loxone Config nachbaut,
war es nicht. Zwei Anleitungen desselben Plugins, die sich widersprachen — und
wer der Tabelle folgte, baute genau die Verwechslung ein, gegen die die Vorlage
absichert: `1_TEMPERATUR=` steckt wörtlich in `11_TEMPERATUR=`, und Loxone
nimmt den ersten Treffer.

Ursache war eine Suche über den halben Ordner: Die Korrektur in 0.9.10 hatte
nur `webfrontend/` durchsucht, das Muster steht aber auch in den Sprachdateien.

**Berichtigt und zusammengeführt.** Die fünf Texte sind jetzt
Formatzeichenketten, und die Baustein-Liste füllt sie aus derselben Funktion,
aus der auch die XML-Vorlage ihr Muster holt. Das Muster steht damit an genau
einer Stelle im Plugin; eine Suche über den ganzen Ordner findet es nirgends
mehr wörtlich.

> **Wer 0.9.10 von Hand nachgebaut hat, prüft die fünf Befehlserkennungen der
> virtuellen Eingänge.** Vor jedem Namen gehört ein Semikolon. Wer die Vorlage
> importiert hat, ist nicht betroffen — dort war sie schon richtig.

## Neu in 0.9.10

Aus einer Durchsicht Zeile für Zeile am 17.08.2026. Fünf der sechs Punkte
erzeugten still falsche Werte oder Datenverlust — nichts davon hat sich je
gemeldet.

**Die Spannung war der Strom.** In der Cluster-Tabelle stand
`ElectricalPowerMeasurement` (0x0090) mit der Spannung auf Attribut 5 und dem
Strom auf 6. Attribut 5 ist aber `ActiveCurrent` und 6 `ReactiveCurrent`; die
Spannung ist Attribut 4. Eine Steckdose an 230 V mit 0,43 A hat als „Spannung"
den Wert **0,4** veröffentlicht, und der Strom fehlte meist ganz, weil der
Blindstrom selten vorhanden ist. Gemessen mit der echten Übersetzungsfunktion:
alt `spannung 0.435`, neu `spannung 230.1, strom 0.435, leistung 100.05`.
Nachgeprüft an den zap-templates von `project-chip/connectedhomeip`.
**Wer diesen Cluster benutzt, muss die Loxone-Vorlage neu einlesen.**

**Die Gerätenummer verschob sich.** Sie entstand aus dem Platz in der
sortierten Knotenliste. Fiel ein Gerät aus der Fabric, rückte jedes
nachfolgende um eins vor — und `MATTER_3_…`, `geraet3/…` und `&geraet=3`
zeigten danach auf ein anderes Gerät. Die Zuordnung steht jetzt in
`data/plugins/matter2lox.nummern.json` — seit 0.9.17 **neben** dem
Datenordner, weil der Installer den Ordner bei jedem Upgrade abräumt (bis
0.9.16 lag sie darin und ging bei jedem Update verloren). Sie wird nie
verändert und auch nach dem Entfernen eines Geräts nicht neu vergeben. Bestehende Anlagen behalten ihre
Nummern: beim ersten Lauf entsteht die Datei genau so, wie die Zählung bisher
ausfiel. Dazu ist jedes Gerät jetzt auch über `&knoten=<Knotennummer>`
erreichbar — die vergibt der Matter-Server, sie hängt an keiner Zählung des
Plugins.

**Das Suchmuster traf die falsche Stelle.** Loxone sucht die Zeichenkette
wörtlich und nimmt den ersten Treffer; `1_TEMPERATUR=` steckt in
`11_TEMPERATUR=`. Bei einer Bridge mit beiden Endpunkten las der Eingang für
Endpunkt 1 den Wert von 11 — ohne Fehlermeldung. Nachgestellt und gemessen:
`MATTER_1_1_TEMPERATUR` lieferte 22 statt 21. Das Muster trägt jetzt das
Semikolon (`\i;1_TEMPERATUR=\i\v`). **Auch dafür muss die Vorlage neu
eingelesen werden**; bestehende Eingänge laufen unverändert weiter, nur eben
mit dem alten Muster.

**Eine beschädigte Konfiguration vernichtete die Sicherung.** War
`matter2lox.json` vorhanden, aber kein gültiges JSON, fiel alles auf die
Werkseinstellung zurück; das leere Aktionstoken löste die Erzeugung eines
neuen aus, und das Speichern schrieb die Werkseinstellung auch über die
Zweitschrift. In einem Zug fort waren: Token (womit jede Loxone-Adresse
ungültig wurde), Steuerungsfreigabe, MQTT-Präfix, WLAN-Passwort und
Thread-Dataset. Jetzt bleibt die beschädigte Datei einmalig als `.kaputt`
liegen, die Zweitschrift wird gelesen und die Konfiguration daraus
wiederhergestellt, und die Sicherung wird nur noch überschrieben, wenn wirklich
eine Konfiguration mit Token gespeichert wird.

**`abruf` hat nichts abgerufen.** Der Befehl gab „Sofortabruf eingeplant"
zurück und schickte dem Matter-Server nichts; das Abbild wurde nur aus dem
Speicher neu geschrieben — war der leer, entstand ein leeres Abbild, also genau
in der Lage, für die der Befehl gedacht ist. Jetzt: ohne Geräteangabe
`get_nodes`, mit `&geraet=` oder `&knoten=` ein `interview_node` für diesen
einen Knoten. Gemeldet wird, was geschah, nicht was geplant war.

**Und der Dienst sendet nicht mehr bei jeder Regung alles.** Bis 0.9.9 löste
jedes einzelne `attribute_updated` zwei Dateischreibvorgänge und das
vollständige Neusenden aller Themen aller Geräte aus; ein bewegter Dimmer
erzeugte damit hunderte Telegramme je Sekunde. Neu:

* **Sendetakt** (Vorgabe 2 s): Änderungen werden gesammelt und dann in einem
  Zug gesendet — und nur die, die sich wirklich geändert haben. `0` stellt das
  alte Verhalten wieder her.
* **Herzschlag** (Vorgabe 60 s): `matter/online` und `matter/ts` kommen auch
  dann, wenn sich nichts geändert hat — und während einer Störung mit `ok=0`.
  Ohne dieses Lebenszeichen sieht ein toter Dienst in Loxone genauso aus wie
  ein ruhiges Haus, weil die zuletzt gesendeten Werte einfach stehen bleiben.
* `matter/geraetN/knoten` nennt die Knotennummer.
* Die `cache.json` entfällt. Sie wurde bei jedem Ereignis geschrieben und von
  niemandem gelesen; eine vorhandene wird beim Start weggeräumt.
* Der Ereignispfad liest keine Dateien mehr. Bisher wurden je Ereignis die
  Plugin-Konfiguration und die `general.json` des LoxBerry neu eingelesen.

**Die Oberfläche prüft sich jetzt selbst.** Vier Stellen in `index.php` müssen
dieselben sechs Reiternamen führen: die Positivliste, die Reiterleiste, die
`id` der Flächen und die Verweise. Fehlt ein Name in der Positivliste, ist der
Reiter anklickbar, aber nach jedem Absenden springt die Seite zurück auf
Einstellungen. Bisher stand darüber nur ein Kommentar, der das zusicherte — ein
Kommentar ist kein Beleg. Ebenso wird jetzt nachgesehen, ob `mt_vorgaben()` und
`VORGABEN` in `bin/matter_dienst.py` dieselben Schlüssel führen; auch das
verlangte die Datei bisher nur im Kommentar. Dazu zwei Zeilen zum Betrieb: ob
der Dienst noch **lebt** (der Herzschlag hinterlegt seinen Zeitstempel, eine
Prozessnummer allein sagt nichts) und welche Schemafassung der Matter-Server
spricht.

Die Reiterleiste steht dafür wieder ausgeschrieben statt als Schleife.
`hausstandard_pruefen.py` sucht die Reiter wörtlich im Quelltext und konnte sie
bis 0.9.9 **gar nicht messen** — es meldete „nicht gemessen", was leicht wie
„in Ordnung" aussieht. Jetzt misst es sie, und die Übereinstimmung prüft
zusätzlich der Reiter *Test* nach.

**„Keine Geräte" ist kein Fehler mehr, solange nie verbunden wurde.** Die Zeile
stand bisher auch auf einer frisch installierten Anlage auf Rot, an der noch
gar nichts angelernt sein kann. Ein Kreuz, das nichts bedeutet, ist schlimmer
als keine Prüfung — man sucht dann dort.

**Die Oberfläche wartet nicht mehr bei jedem Aufruf auf den Matter-Server.**
Der Verbindungsversuch der Selbstprüfung lief bei *jedem* Seitenaufruf, mit
drei Sekunden Zeitüberlauf — auch wenn nur das Protokoll gefragt war. Das
Ergebnis wird jetzt 30 Sekunden zwischengespeichert, sein Alter steht dabei,
und nach jedem Eingriff an Dienst oder Container wird es verworfen.

**Der Wurzelordner wird geprüft, bevor er gilt.** Die Ableitung `zwei Ebenen
über bin/plugins/<ordner>` stimmt installiert, aber nicht im entpackten Archiv;
der Rückfall darunter griff praktisch nie. Gemessen wurde dort ein Pluginname
`bin` und Ordner an einer Stelle, an der kein LoxBerry liegt — der Dienst hätte
in fremde Verzeichnisse geschrieben und trotzdem Erfolg gemeldet. Jetzt muss
die abgeleitete Wurzel wirklich ein `config/plugins` und ein `webfrontend`
enthalten, sonst greifen `LBHOMEDIR` und die Suche; der Selbsttest sagt es
deutlich, wenn nichts passt.

**Szenentaster — und warum sie vorher nicht gehen konnten.** Ein Tastendruck
kommt bei Matter **ausschließlich als Ereignis** (`node_event`); ein Attribut,
das man abfragen könnte, gibt es nicht. `_ereignis()` hat `node_event` bis 0.9.9
stillschweigend verworfen — damit war jeder Szenentaster für dieses Plugin
unerreichbar, ganz gleich was in der Cluster-Tabelle stand. Jetzt merkt sich der
Dienst je Endpunkt das letzte Ereignis und veröffentlicht vier Themen:

| Thema | Bedeutung |
|---|---|
| `taste` | 0 eingerastet, 1 gedrückt, 2 lang gedrückt, 3 kurz losgelassen, 4 lang losgelassen, 5 Mehrfachdruck läuft, 6 Mehrfachdruck fertig |
| `taste_zaehler` | zählt jedes Ereignis hoch |
| `taste_position` | Stellung, auf die sich das Ereignis bezieht — leer, wenn das Gerät keine mitschickt |
| `taste_zeit` | Unix-Zeit |

**Auf `taste_zaehler` triggert man in Loxone**, nicht auf `taste`: wer zweimal
dieselbe Taste drückt, erzeugt zweimal denselben Code, und ein Eingang, der auf
Wertänderung reagiert, sähe den zweiten Druck nicht. Gemessen mit drei
Ereignissen hintereinander: `taste` bleibt 1, der Zähler geht auf 2.
Ebenfalls neu behandelt: `endpoint_added` und `endpoint_removed`, die Bridges
zur Laufzeit schicken.

**Elf neue Cluster.** Alle Nummern am 17.08.2026 einzeln gegen die
zap-templates von `project-chip/connectedhomeip` geprüft, alle als
`_ungeprueft` gekennzeichnet — an einem Gerät hat sie niemand gemessen.

| Cluster | Nummer | Wofür |
|---|---|---|
| `Switch` | 0x003B | Szenentaster, siehe oben |
| `SmokeCoAlarm` | 0x005C | Rauch, Kohlenmonoxid, Batterie, Lebensende, Verschmutzung |
| `CarbonDioxideConcentrationMeasurement` | 0x040D | CO₂ |
| `Pm25ConcentrationMeasurement` | 0x042A | Feinstaub |
| `TotalVolatileOrganicCompounds…` | 0x042E | VOC, samt Stufe |
| `RvcRunMode` | 0x0054 | Saugroboter, Betriebsart |
| `RvcOperationalState` | 0x0061 | Saugroboter, Zustand und Restzeit |
| `ValveConfigurationAndControl` | 0x0081 | Ventile, Bewässerung, Wasserstopp |
| `TemperatureControl` | 0x0056 | Sollwert und Stufe, etwa am Backofen |
| `ThermostatUserInterfaceConfiguration` | 0x0204 | Tastensperre am Thermostat |
| `WaterHeaterMode` | 0x009E | Warmwasser-Betriebsart |

Zwei Fallen, die dabei umgangen sind: Bei den Luftgüte-Clustern ist der
Messwert ein **Gleitkommawert**, keine Ganzzahl — dafür gibt es den neuen Typ
`gleitkomma`; und die **Einheit** steht in Attribut 8 und wird deshalb **nicht
angenommen**, sondern als eigenes Thema (`co2_einheit` und so weiter)
mitveröffentlicht. Bei `RvcOperationalState` gilt die Grenze `max 3` von
`OperationalState` ausdrücklich **nicht**: das Datenmodell benutzt dort `enum8`,
damit die gerätespezifischen Werte ab 64 durchkommen.

**Geräte lassen sich umbenennen.** Der Name geht in das **Gerät**, nicht in eine
Liste des Plugins: `BasicInformation.NodeLabel` (Cluster 40, Attribut 5) ist
laut Datenmodell beschreibbar, `char_string` mit Länge 32. Das Gerät heißt
danach auch in Apple Home oder Google Home so, und das Plugin muss keine
Zuordnung pflegen, die auseinanderlaufen kann. Zu finden im Reiter *Geräte
anlernen* unter *Angelernte Geräte verwalten*; setzt die Freigabe schreibender
Befehle voraus.

**Eine Störung löscht die Werte nicht mehr.** Scheiterte schon der
Verbindungsaufbau — der Container steht —, wurde `loxone.json` mit einer
**leeren** Geräteliste überschrieben, und weil dabei auch der Zeitstempel neu
gesetzt wurde, sprang `ALTER` auf 0. Die Oberfläche zeigte 0 Geräte, der
Endpunkt antwortete `GERAET_UNBEKANNT`, und beides sah taufrisch aus, während
die Geräte die ganze Zeit in der Fabric standen. Jetzt bleiben die zuletzt
bekannten Werte stehen und `ALTER` wächst weiter: die Werte sind **alt, nicht
weg**. Gemessen — Störung nach einer Stunde: alt `0 Geräte, ALTER 0`, neu
`2 Geräte, ALTER 3600, OK=0`.

**Farbe.** Neu sind `farbton` (0–360 Grad), `saettigung` (0–100 %) und `farbe`
(beides in einem). Umgerechnet wird im Plugin — Matter zählt 0–254. Dazu
veröffentlicht das Plugin `farbtemperatur_kelvin` **zusätzlich** zum
Mired-Wert: Loxone rechnet in Kelvin, Matter in Mired, und wer beides
nebeneinander sah, hielt es für einen Fehler. Ein zusammengesetztes
Loxone-Farbformat wird bewusst **nicht** angenommen: wie es an einem virtuellen
Ausgang ankäme, ist hier nicht gemessen, und geraten wird nicht.

**Türschloss, mit eigenem Haken.** `sperren` und `entsperren` brauchen die
Freigabe *Schlösser schalten zulassen* — zusätzlich zur allgemeinen. Wer Lampen
aus Loxone schalten lässt, will damit nicht die Haustür freigeben. Beide
Befehle gehen als *timed invoke* hinaus; das Datenmodell verlangt es, und ohne
weist das Gerät ab.

**Weitere Befehle:** `identify` (das Gerät macht sich bemerkbar — die Antwort
auf „welches ist Knoten 7?"), `luefter` (Sollwert). Der Lüfter-Istwert wird neu
gelesen: `514/2` ist der **Soll**wert, der Istwert ist `514/3`. Das Thema heißt
deshalb jetzt `luefter_soll` statt `luefter_prozent`.

**Betrieb:**

* **Matter-Server aktualisieren** — „Abbild holen" allein wirkt nicht, der
  laufende Container hängt an seiner Kennung. Der neue Knopf zieht, entfernt,
  legt neu an und nennt die Kennung **vorher und nachher**. Gibt es nichts
  Neues, wird der Container *nicht* angehalten und das steht ausdrücklich da.
  Die Kachel zeigt jetzt auch, welches Abbild wirklich läuft.
* **Fabric sichern** — der Datenordner als `tar.gz` zum Herunterladen. Kein
  Archiv liefert ihn mit, und wer ihn verliert, muss jedes Gerät neu anlernen.
  Zurückspielen geht bewusst **nicht** über die Oberfläche: ein Endpunkt, der
  ein beliebiges Archiv auspackt, wäre eine Hintertür. Der Befehl dafür steht
  im Reiter.
* **MQTT-Probewert** — prüft die ganze Kette ohne ein einziges Matter-Gerät.
* **Nur diese Gerätenummern veröffentlichen** — bei einer Bridge mit fünfzig
  Endpunkten der Unterschied zwischen benutzbar und unbenutzbar.
* Die Selbstprüfung **ruft den eigenen Endpunkt jetzt wirklich über HTTP auf.**
  `html/` und `htmlauth/` liegen installiert in getrennten Bäumen, und keine
  Leseprüfung sieht das.

**Kleineres:** Die Einheit steht jetzt **am virtuellen Eingang**
(`Unit="<v.1> °C"`) statt nur im Kommentar — Loxone zeigt sie damit am Wert an.
Der Gerätetyp steht in der Gerätetabelle (dreizehn übersetzte Namen lagen
ungenutzt da). Das Kopplungsfenster gibt jetzt auch den **QR-Text** aus, nicht
nur den manuellen Code. Der Reiter *Geräte anlernen* sagt, dass seine Knöpfe
ohne die Steuerungsfreigabe nicht wirken — sie ist ab Werk aus. Eine
Ausgangsvorlage ohne einen einzigen schaltbaren Wert wird nicht mehr
ausgeliefert, sondern erklärt. `mt_mqtt_senden()` benutzt `stream_socket_client()`
statt `socket_create()` (eine fehlende Erweiterung wäre dort kein abfangbarer,
sondern ein fataler Fehler), `mt_cache()` ist entfallen, und es gibt ein
`dpkg/apt`. `ColorTemperatureMireds` beginnt laut Spezifikation bei 1, nicht 0.

Dazu: `UMR.MWH` und `UMR.ENERGIE_STRUCT` fehlten in beiden Sprachdateien — in
der Übersetzungstabelle des Reiters MQTT standen dort in drei Zeilen wörtlich
die Schlüsselnamen. Und die Ereignisthemen kämen in derselben Tabelle gar nicht
vor, weil sie nicht unter `cluster` stehen; sie haben jetzt eigene Zeilen. 533
Sprachschlüssel, deutsch und englisch deckungsgleich.

> **Zwei Themen sind umbenannt** (`luefter_prozent` → `luefter_soll`), und die
> Suchmuster tragen jetzt ein Semikolon. Wer diese Werte benutzt, erzeugt die
> Loxone-Vorlage neu und liest sie ein; die alten Eingänge laufen unverändert
> weiter, behalten aber den alten Stand.

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
aus, dass `loxone.json` alle Schlüssel enthält. Die Datei kann aus einer älteren Fassung des Dienstes stammen, der im selben
Lauf noch geschrieben hat. Ein Plugin-Update übersteht sie **nicht** — sie
liegt im Datenordner, den der Installer abräumt; der Dienst schreibt sie beim
nächsten Lauf neu. Fehlt dann ein
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

Was das Plugin seit 0.9.18 abnimmt, ist das Abschreiben des Datasets. Wer einen
eigenen Border-Router auf Grundlage von OpenThread betreibt (`ot-br-posix`, das
Abbild `openthread/otbr`, der Border-Router von Home Assistant), trägt im Reiter
*Anlernen* dessen Adresse ein und holt das aktive Dataset auf Knopfdruck: das
Plugin fragt dort den REST-Dienst (ab Werk Port 8081, `GET /node/dataset/active`
mit `Accept: text/plain`) und legt die zurückgegebene Hexkette in das Feld
darüber. **Apple und Google geben ihr Dataset auf diesem Weg nicht heraus** —
dort bleibt es beim Abschreiben von Hand. An den Matter-Server übergeben wird
das Dataset auch danach erst auf Knopfdruck; der Abruf schaltet nichts ein.

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
| ColorControl | `farbtemperatur_mired`, `farbtemperatur_kelvin`, `farbton_roh`, `saettigung` | Kelvin = 1000000 ÷ Mired |
| TemperatureMeasurement | `temperatur` | ÷100 |
| RelativeHumidityMeasurement | `feuchte` | ÷100 |
| IlluminanceMeasurement | `helligkeit_lux` | 10^((v−1)/10000) |
| OccupancySensing | `bewegung` | unterstes Bit |
| BooleanState | `kontakt` | 0/1 |
| Thermostat | `ist_temperatur`, `soll_heizen`, `soll_kuehlen`, `betriebsart` | ÷100 |
| WindowCovering | `position` | ÷100, 0 = ganz offen |
| PowerSource | `batterie` | ÷2 (halbe Prozent) |
| ElectricalPowerMeasurement | `leistung`, `spannung`, `strom` | ÷1000 |
| Switch *(Ereignis)* | `taste`, `taste_zaehler`, `taste_position`, `taste_zeit` | — |
| SmokeCoAlarm | `rauch_alarm`, `co_alarm`, `rauch_batterie`, `rauch_lebensende` … | — |
| CO₂ · PM2,5 · VOC | `co2`, `pm25`, `voc` samt `…_einheit` | Gleitkomma, Einheit aus Attribut 8 |
| RvcRunMode · RvcOperationalState | `rvc_modus`, `rvc_zustand`, `rvc_restzeit` | — |
| ValveConfigurationAndControl | `ventil_zustand`, `ventil_stellung`, `ventil_rest` | — |
| FanControl | `luefter_modus`, `luefter_soll`, `luefter_ist` | — |
| TemperatureControl · ThermostatUI · WaterHeaterMode | `tc_soll`, `tastensperre`, `ww_modus` | ÷100 bzw. — |

**Alle 33 Cluster stehen im Reiter *MQTT*** — diese Tabelle ist eine Auswahl.
Unbekannte Attribute lassen sich auf Wunsch roh mitveröffentlichen; verloren
geht nichts.

## Endpunkte für Loxone

Alle Aufrufe brauchen das Token aus dem Reiter *Einbindung in Loxone*.

Jedes Gerät ist auf zwei Wegen ansprechbar: über `&geraet=N`, die Gerätenummer
des Plugins, oder über `&knoten=M`, die Knotennummer des Matter-Servers. Die
Gerätenummer ist seit 0.9.10 fest; die Knotennummer hängt an keiner Zählung des
Plugins. Ist beides angegeben, gewinnt `&knoten=`.

| Aufruf | Zweck |
|---|---|
| `?token=T&aktion=status&geraet=N` | alle Werte eines Geräts in einer Zeile |
| `?token=T&aktion=statusalle` | **alle** Geräte in einer Zeile, Marken mit Gerätenummer (`MATTER_3_1_TEMPERATUR`) — das ist der Endpunkt der Sammelvorlage |
| `?token=T&aktion=wert&geraet=N&endpunkt=E&thema=X` | **nur die Zahl** — ein virtueller HTTP-Eingang braucht dann keine Befehlserkennung |
| `?token=T&aktion=liste` | alle Geräte |
| `?token=T&aktion=roh` | vollständiges Abbild als JSON |
| `?token=T&aktion=abruf` | ohne Geräteangabe: Bestand neu holen. Mit `&geraet=` oder `&knoten=`: diesen Knoten neu auslesen. Braucht keine Steuerungsfreigabe |
| `?token=T&aktion=ein\|aus\|umschalten&geraet=N&endpunkt=E` | schalten |
| `?token=T&aktion=helligkeit&wert=0..100` | dimmen |
| `?token=T&aktion=farbtemperatur&wert=<Kelvin>` | Farbtemperatur |
| `?token=T&aktion=rollo&wert=0..100` | Behang (0 = ganz offen) |
| `?token=T&aktion=rollo_auf\|rollo_zu\|rollo_stopp` | Behang fahren und anhalten |
| `?token=T&aktion=soll_heizen\|soll_kuehlen&wert=<Grad>` | Thermostat-Sollwerte |
| `?token=T&aktion=betriebsart&wert=0..9` | Thermostat-Betriebsart |
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

## Fassung 0.9.15 — der Stat-Zwischenspeicher
Die Protokollkappung (512 000 Byte) stand in
`webfrontend/html/mt_lib.php:342`. PHP merkt sich aber die Antworten von
`stat()`: innerhalb **eines** Prozesses sieht `filesize()` die erste Größe
und danach nie wieder eine neue — `file_put_contents(…, FILE_APPEND)` macht
den Eintrag nicht ungültig. Die Kappung fällt dann still aus.

Gemessen am 29.08.2026, 20 000 Zeilen im selben Prozess:

| | ohne `clearstatcache` | mit |
|---|---|---|
| PHP 7.4.33 | 1 220 000 Byte, **nicht gekappt** | 220 332 Byte, gekappt |
| PHP 8.4.24 | 220 332 Byte, gekappt | 220 332 Byte, gekappt |

Die beiden PHP-Fassungen verhalten sich also verschieden — und LoxBerry 3.x
fährt 7.4. Wer nur unter 8.4 misst, sieht den Fehler nie. Folgen hatte das
hier nicht: die Aufrufer sind kurzlebig, und ein **frischer** Prozess kappt
richtig. Eine Funktion darf aber nicht davon abhängen, wer sie wie oft ruft.

Abhilfe: `clearstatcache(true, …)` **vor** dem Tor. Der zweite Parameter
räumt zusätzlich den `realpath`-Eintrag dieser einen Datei; der
`stat`-Zwischenspeicher wird ohnehin vollständig verworfen (unter PHP 7.4.33
nachgemessen — ein `clearstatcache(true, $a)` verwarf auch den Wert einer
Datei `$b`). Dasselbe Muster tragen Robonect,
Saugroboter, SignalBot, Octopus, Sprachsteuerung und WärmepumpeCloud schon
länger — es ist am 29.08.2026 im ganzen Bestand nachgezogen worden.

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

**Zum Nachfolger, nachgesehen am 17.08.2026:** `matterjs-server` beschreibt
sich selbst als „drop-in replacement" für den Python Matter Server mit einer
kompatiblen WebSocket-Schnittstelle und führt fünf ausdrücklich gewollte
Abweichungen auf (Test-Knotennummern, Verhalten beim Zurücksetzen des
Fabric-Labels, Speicherformat, `attribute_subscriptions`, Einspielen eigener
OTA-Dateien). Keine davon berührt einen Pfad, den dieses Plugin benutzt. Ein
Praxistest ist das nicht — nur die Auskunft des Projekts, und die war bisher
nicht nachgesehen worden.

## Was nicht geprüft ist

Damit niemand mehr Verlass in diese Zeilen legt, als sie tragen:

* **Alles, was einen laufenden Matter-Server braucht.** Die Anmeldung, das
  Anlernen eines Geräts, die Wirkung der Cluster-Befehle am Gerät, das
  Verhalten unter Last. Geprüft ist gegen eine Attrappe des dokumentierten
  Protokolls, nicht gegen Hardware.
* **Die Container-Verwaltung**, weil dafür ein Docker auf einem 64-Bit-LoxBerry
  nötig ist.
* **Der Abruf des Thread-Datasets beim Border-Router.** Gebaut nach der
  Schnittstellenbeschreibung von `ot-br-posix` (`GET /node/dataset/active`,
  Port 8081, TLV-Hexkette bei `Accept: text/plain`); an einem laufenden
  Border-Router hat ihn niemand gemessen. Gemessen sind die Wege daneben: eine
  abgewiesene Adresse, eine ausbleibende Antwort und eine Antwort, die kein
  Dataset ist, führen jeweils zu ihrer eigenen Meldung und ändern nichts an der
  Konfiguration.

  Seit 0.9.19 lässt sich das ohne Risiko nachholen: die Zeile *Antwortet der
  Border-Router?* im Reiter *Test* fragt ab, ohne zu speichern. Zu beachten
  ist, dass **fertige Border-Router aus dem Handel diese Schnittstelle nicht
  anbieten** — Apple, Google und Amazon halten das Dataset hinter Konto und
  Schlüsselbund. Es antwortet nur ein selbst betriebener OpenThread-Border-
  Router, etwa das Add-on von Home Assistant oder `ot-br-posix` auf einem
  Raspberry Pi mit 802.15.4-Funkmodul. Dessen Abrufdienst ist unangemeldet und
  gehört deshalb ins lokale Netz.
* **Die MQTT-Weiterleitung an den Miniserver.** Der Sendeweg über den
  UDP-Eingang des Gateways ist gebaut und gelesen, aber nicht an einer Anlage
  gemessen.
* **Wie der Matter-Server die `EnergyMeasurementStruct` über die
  WebSocket-Schnittstelle benennt.** Belegt ist nur die Spezifikationsseite:
  Feldname `Energy`, Feldnummer 0, Einheit mWh. Das Plugin nimmt beide Formen
  an und gibt lieber nichts zurück als eine erfundene Zahl.
* Die Cluster mit `_ungeprueft` in `templates/matter_cluster.json`
  — zurzeit **16 Cluster**. Ihre Nummern stammen aus der Spezifikation,
  gemessen hat sie an einem Gerät niemand. Aufgezählt werden sie nicht hier,
  sondern im Reiter *Test*: eine zweite Liste läuft auseinander, und genau das
  war bis 0.9.16 der Fall (die README nannte fünf, die Datei führte sechzehn).

Geprüft ist dagegen: PHP-Syntax unter 7.4 und 8.4, die Oberfläche gerendert
unter beiden Fassungen und im Aktualisierungsfall, die erzeugten
Loxone-Vorlagen auf Wohlgeformtheit, die Formulare auf Vollständigkeit, alle
Sprachschlüssel in beiden Sprachen, und die Lage im installierten Aufbau mit
getrennten Bäumen für `html/` und `htmlauth/`.
