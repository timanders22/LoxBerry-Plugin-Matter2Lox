#!REPLACELBPBINDIR/venv/bin/python3
"""Matter to Loxone - Bruecke zwischen Matter-Server und Loxone.

WAS DIESES PLUGIN IST UND WAS NICHT
-----------------------------------
Es ist die Bruecke, NICHT der Matter-Controller. Matter verlangt einen
zertifizierten Controller mit eigener Fabric, Zertifikaten, Inbetriebnahme
ueber Bluetooth und IPv6-Multicast; den gibt es fertig als
python-matter-server (CSA-zertifiziert, dasselbe Stueck, das auch Home
Assistant benutzt). Dieser Dienst spricht dessen WebSocket-Schnittstelle,
uebersetzt die Matter-Attribute in sprechende Werte und reicht sie ueber das
LoxBerry-MQTT-Gateway an den Miniserver weiter. Umgekehrt nimmt er Befehle aus
einer Warteschlange an und setzt sie in Matter-Cluster-Befehle um.

PROTOKOLL
---------
Alles Folgende ist der Schnittstellenbeschreibung und dem Quelltext von
python-matter-server entnommen (docs/websockets_api.md, common/models.py,
client/client.py), nichts davon ist geraten:

  - Verbindung:  ws://<host>:5580/ws
  - Beim Verbinden sendet der Server eine ServerInfoMessage.
  - Befehl:      {"message_id": "...", "command": "...", "args": {...}}
  - Antwort:     {"message_id": "...", "result": ...}
                 oder {"message_id": "...", "error_code": n, "details": "..."}
  - Ereignis:    {"event": "attribute_updated", "data": [...]}
  - start_listening liefert als Ergebnis den vollstaendigen Bestand aller Knoten.
  - Attributpfad: ENDPUNKT/CLUSTER/ATTRIBUT (als Zeichenkette)
  - attribute_updated:  data = [node_id, attribute_path, neuer_wert]

Aufrufe:
    matter_dienst.py               Dienst (Dauerbetrieb)
    matter_dienst.py --einmal      einmal verbinden, Bestand holen, Ende
    matter_dienst.py --selbsttest  Pruefungen ohne Matter-Server, Klartext
"""

from __future__ import annotations

import asyncio
import json
import logging
import os
import re
import signal
import socket
import sys
import time
import uuid
from logging.handlers import RotatingFileHandler
from pathlib import Path


def lb_wurzel_ermitteln():
    """Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.

    Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
    config/plugins UND webfrontend enthaelt. Trifft die uebliche
    Installation genauso wie eine an einem anderen Ort.
    """
    d = os.path.dirname(os.path.abspath(__file__))
    for _ in range(8):
        if os.path.isdir(os.path.join(d, "config", "plugins")) \
                and os.path.isdir(os.path.join(d, "webfrontend")):
            return d
        eltern = os.path.dirname(d)
        if eltern == d:
            break
        d = eltern
    return ""


def mqtt_wert_saeubern(wert):
    """Einen Wert fuer den UDP-Eingang des MQTT-Gateways unschaedlich machen.

    Das Gateway liest zeilenweise. Ein Zeilenumbruch im Wert zerlegt die
    Uebertragung, und aus den Bruchstuecken bildet das Gateway erfundene
    Themen. Ein Tabulator schadet ebenso, weil Leerzeichen Thema und Wert
    trennt.
    """
    text = str(wert)
    for zeichen in ("\r\n", "\r", "\n", "\t"):
        text = text.replace(zeichen, " ")
    while "  " in text:
        text = text.replace("  ", " ")
    return text.strip()


# ---------------------------------------------------------------------------
# Pfade aus dem EIGENEN Ablageort ableiten.
#
# Nicht ueber LoxBerry::System: das leitet den Pluginordner aus dem Aufrufort
# ab und liefert bei einem Start aus postinstall.sh oder aus dem Cron ueberall
# Leerstring - der Dienst werkelte dann gegen /-Pfade und meldete Erfolg.
# ---------------------------------------------------------------------------
SELF = Path(__file__).resolve().parent            # <home>/bin/plugins/<ordner>
PNAME = SELF.name


def _ist_lbwurzel(d) -> bool:
    """Sieht dieses Verzeichnis wirklich wie eine LoxBerry-Wurzel aus?"""
    try:
        return (d / "config" / "plugins").is_dir() and (d / "webfrontend").is_dir()
    except OSError:
        return False


# Bis 0.9.9 stand hier nur "if len(SELF.parents) >= 3: LBHOME = SELF.parents[2]".
# Die Bedingung ist fast immer wahr, der Rueckfall darunter griff also praktisch
# nie. Im installierten Aufbau (<home>/bin/plugins/<ordner>) stimmt die
# Ableitung; aus einem entpackten Archiv heraus zeigte sie zwei Ebenen zu weit
# nach oben - gemessen wurde ein PNAME "bin" und Konfigurations- und
# Datenordner an einer Stelle, an der kein LoxBerry liegt. Der Dienst haette
# dort in fremde Verzeichnisse geschrieben und trotzdem Erfolg gemeldet.
#
# Deshalb wird die abgeleitete Wurzel jetzt geprueft, bevor sie gilt.
LBHOME = None
if len(SELF.parents) >= 3 and _ist_lbwurzel(SELF.parents[2]):
    LBHOME = SELF.parents[2]
if LBHOME is None:
    umgebung = os.environ.get("LBHOMEDIR") or ""
    if umgebung and _ist_lbwurzel(Path(umgebung)):
        LBHOME = Path(umgebung)
if LBHOME is None:
    gesucht = lb_wurzel_ermitteln()
    if gesucht:
        LBHOME = Path(gesucht)
if LBHOME is None:
    # Nichts gefunden. Lieber die alte Ableitung als ein Leerstring, der
    # gegen /-Pfade werkelt - aber der Selbsttest sagt es dann deutlich.
    LBHOME = SELF.parents[2] if len(SELF.parents) >= 3 else SELF
    LBHOME_GERATEN = True
else:
    LBHOME_GERATEN = False

PDATA = LBHOME / "data" / "plugins" / PNAME
PLOG = LBHOME / "log" / "plugins" / PNAME
PCONFIG = LBHOME / "config" / "plugins" / PNAME
PTEMPLATES = LBHOME / "templates" / "plugins" / PNAME

DATEI_CONFIG = PCONFIG / "matter2lox.json"
DATEI_LOXONE = PDATA / "loxone.json"
# Bis 0.9.9 wurde daneben eine cache.json mit dem vollstaendigen Knotenabzug
# geschrieben - bei JEDEM Ereignis, und gelesen hat sie niemand (mt_cache() in
# mt_lib.php wurde nie aufgerufen). Sie entfaellt; eine vorhandene wird beim
# Start einmal weggeraeumt.
DATEI_ALTCACHE = PDATA / "cache.json"
# Zuordnung Knotennummer -> Geraetenummer. Siehe nummern_zuordnen().
#
# Sie liegt seit 0.9.17 NEBEN dem Datenordner, nicht darin. Der LoxBerry-
# Installer raeumt data/plugins/<ordner>/ bei jedem Upgrade vollstaendig ab
# (plugininstall.pl, Zweig master: purge_installation in Zeile 886 des
# Upgrade-Zweigs, die Loeschung in Zeile 1631). Bis 0.9.16 war die Datei damit
# nach jedem Update fort, und die Geraetenummern entstanden neu aus der
# sortierten Knotenliste - genau der Fehler, den 0.9.10 behoben hat.
# Nachbarn des Ordners ueberleben; preupgrade.sh zieht eine alte Datei um.
DATEI_NUMMERN = PDATA.parent / (PNAME + ".nummern.json")
DATEI_NUMMERN_ALT = PDATA / "nummern.json"
# Fabric und Zertifikate des Containers - aus demselben Grund daneben.
ORDNER_FABRIC = PDATA.parent / (PNAME + ".matter")
DATEI_ZUSTAND = PDATA / "zustand.json"
ORDNER_BEFEHLE = PDATA / "befehle"
ORDNER_ANTWORTEN = PDATA / "antworten"
DATEI_LOG = PLOG / "matter2lox.log"

VORGABEN = {
    "server_host": "127.0.0.1",
    "server_port": 5580,
    "eigener_container": 1,
    "container_name": "matter-server",
    "container_abbild": "ghcr.io/matter-js/python-matter-server:stable",
    "bluetooth_adapter": 0,
    "mqtt_ein": 1,
    "mqtt_topic": "matter",
    "roh_ein": 0,
    "steuerung_ein": 0,
    "aktionstoken": "",
    "wartezeit": 8,
    "wlan_ssid": "",
    "wlan_passwort": "",
    "thread_dataset": "",
    # Kuerzester Abstand zwischen zwei Veroeffentlichungen, in Sekunden.
    # 0 schaltet die Bremse ab und stellt das Verhalten bis 0.9.9 wieder her.
    "sendetakt": 2,
    # Abstand des Herzschlags in Sekunden, 0 schaltet ihn ab.
    "herzschlag": 60,
    # Geraetenummern, die ueber MQTT hinausgehen sollen. Leer = alle.
    "mqtt_nur": "",
    # Schloesser schalten. Ein ZWEITER Haken ZUSAETZLICH zu steuerung_ein:
    # befehl_ausfuehren() prueft steuerung_ein, bevor es schloss_ein prueft,
    # und der Endpunkt tut dasselbe. Wer Lampen aus Loxone schalten will, hat
    # damit die Haustuer noch nicht freigegeben. (Bis 0.9.16 stand hier
    # "bewusst NICHT an steuerung_ein gehaengt" - das war eine Beschreibung,
    # die der Code an zwei Stellen widerlegt.)
    "schloss_ein": 0,
}

# Wie lange ein Stellbefehl in der Warteschlange gueltig bleibt. Was laenger
# liegt, wird verworfen und gemeldet, statt spaeter ueberraschend zu wirken.
BEFEHL_VERFALL_S = 300

# Was wirklich eine Verbindungsstoerung ist. OSError deckt die Netzschicht ab
# (ConnectionError erbt davon), dazu Zeitueberschreitung, fehlendes Paket und
# ein abgerissener Strom.
VERBINDUNGSFEHLER = (OSError, asyncio.TimeoutError, ImportError, EOFError)


def ist_verbindungsfehler(err: BaseException) -> bool:
    """Verbindungsstoerung oder Fehler im eigenen Code?

    Die Unterscheidung entscheidet, wie gemeldet wird. Bis 0.9.16 fing ein
    einziger except-Zweig alles und schrieb jeden KeyError als "Verbindung zum
    Matter-Server: ..." ins Protokoll - eine behauptete Ursache, keine
    gemessene, und gedrosselt auf eine Meldung je Viertelstunde.

    Die Ausnahmen der websockets-Bibliothek erben nicht von OSError; sie
    werden am Modulnamen ihrer Klasse erkannt, damit hier kein Import noetig
    ist (websockets wird bewusst erst in verbinden() geladen).
    """
    if isinstance(err, VERBINDUNGSFEHLER):
        return True
    modul = str(getattr(type(err), "__module__", ""))
    return modul.split(".", 1)[0] == "websockets"

_LAUF = True
_LOG = logging.getLogger("matter2lox")
_LETZTE_MELDUNG: dict[str, float] = {}
# Zuletzt veroeffentlichte Paare - Grundlage der Delta-Veroeffentlichung.
_LETZTE_PAARE: dict[str, str] = {}


# ---------------------------------------------------------------------------
# Protokollierung - ausschliesslich in die Datei. Das Startskript leitet die
# Ausgabe ohnehin dorthin um; ein zweiter Kanal schriebe jede Zeile doppelt.
# ---------------------------------------------------------------------------
def log_einrichten() -> None:
    PLOG.mkdir(parents=True, exist_ok=True)
    _LOG.setLevel(logging.INFO)
    try:
        h: logging.Handler = RotatingFileHandler(
            DATEI_LOG, maxBytes=512000, backupCount=1, encoding="utf-8"
        )
    except OSError as err:
        h = logging.StreamHandler(sys.stderr)
        print(f"Logdatei nicht beschreibbar ({err}) - schreibe nach stderr.", file=sys.stderr)
    h.setFormatter(logging.Formatter("[%(asctime)s] %(levelname)s %(message)s", "%Y-%m-%d %H:%M:%S"))
    _LOG.handlers = [h]
    _LOG.propagate = False


def melde_gebremst(schluessel: str, text: str, sekunden: int = 3600) -> None:
    """Dieselbe Meldung hoechstens einmal je Zeitfenster - sonst wird die
    Logdatei durch eine Dauerstoerung unlesbar."""
    jetzt = time.time()
    if jetzt - _LETZTE_MELDUNG.get(schluessel, 0) >= sekunden:
        _LETZTE_MELDUNG[schluessel] = jetzt
        _LOG.warning(text)


def json_lesen(pfad: Path) -> dict:
    try:
        with pfad.open("r", encoding="utf-8") as f:
            d = json.load(f)
        return d if isinstance(d, dict) else {}
    except (OSError, ValueError):
        return {}


def json_schreiben(pfad: Path, daten, rechte: int | None = None) -> bool:
    """Erst in eine Nebendatei, dann umbenennen - so liest die Oberflaeche nie
    eine halb geschriebene Datei."""
    try:
        pfad.parent.mkdir(parents=True, exist_ok=True)
        tmp = pfad.with_suffix(pfad.suffix + ".tmp")
        with tmp.open("w", encoding="utf-8") as f:
            json.dump(daten, f, ensure_ascii=False, indent=1, default=str)
        if rechte is not None:
            os.chmod(tmp, rechte)
        os.replace(tmp, pfad)
        return True
    except (OSError, TypeError, ValueError) as err:
        _LOG.error("Datei %s konnte nicht geschrieben werden: %s", pfad, err)
        return False


def config() -> dict:
    c = dict(VORGABEN)
    gelesen = json_lesen(DATEI_CONFIG)
    if not gelesen and DATEI_CONFIG.is_file():
        # Datei da, aber nichts Brauchbares darin. Bis 0.9.9 lief der Dienst
        # dann stillschweigend mit der Werkseinstellung weiter - also ohne
        # Steuerungsfreigabe, mit dem Vorgabe-Praefix und ohne Zugangsdaten,
        # waehrend die Oberflaeche aus der Zweitschrift die richtigen Werte
        # zeigte. Zwei Herren an einer Datei.
        try:
            roh_text = ""
            try:
                roh_text = DATEI_CONFIG.read_text(encoding="utf-8").strip()
            except OSError:
                pass
            # "{}" ist tadelloses JSON, nur leer - so legt postinstall.sh
            # die Datei an. Bis 0.9.16 meldete jede frische Installation
            # deshalb "laesst sich nicht als JSON lesen".
            leer = roh_text in ("", "{}")
        except OSError:
            leer = True
        zweit = PCONFIG.parent / (PNAME + ".backup.json")
        ersatz = json_lesen(zweit)
        if ersatz:
            if not leer:
                melde_gebremst(
                    "config_kaputt",
                    f"{DATEI_CONFIG} laesst sich nicht als JSON lesen - es wird mit der "
                    f"Zweitschrift {zweit} weitergearbeitet. Die Oberflaeche stellt die Datei "
                    "beim naechsten Aufruf wieder her.", 3600)
            gelesen = ersatz
        elif not leer:
            melde_gebremst(
                "config_kaputt",
                f"{DATEI_CONFIG} laesst sich nicht als JSON lesen, und es gibt keine "
                "brauchbare Zweitschrift. Der Dienst laeuft mit der Werkseinstellung - "
                "Steuerung aus, Themenpraefix 'matter'.", 3600)
    c.update(gelesen)
    # Dieselbe try-Form wie bei sendetakt/herzschlag zwei Zeilen tiefer.
    # Bis 0.9.16 stand hier ein blankes int(): ein nicht numerischer Wert
    # in der Konfiguration (von Hand gesetzt oder aus einer Sicherung
    # zurueckgespielt) liess den Dienst bei JEDEM Start mit ValueError
    # sterben, und der minuetliche Waechter startete ihn endlos neu.
    for feld, klein, gross, vorgabe in (("server_port", 1, 65535, 5580),
                                        ("wartezeit", 0, 200, 8)):
        try:
            c[feld] = max(klein, min(gross, int(c.get(feld) or vorgabe)))
        except (TypeError, ValueError):
            melde_gebremst(
                "config_" + feld,
                f"{feld} in der Konfiguration ist keine Zahl "
                f"({c.get(feld)!r}) - es gilt die Vorgabe {vorgabe}.")
            c[feld] = vorgabe
    # 0 ist hier ein zulaessiger Wert und heisst "aus" - deshalb NICHT ueber
    # "or", das die 0 verschluckte und stillschweigend die Vorgabe naehme.
    for feld, klein, gross, vorgabe in (("sendetakt", 0, 60, 2),
                                        ("herzschlag", 0, 3600, 60)):
        try:
            c[feld] = max(klein, min(gross, int(c.get(feld, vorgabe))))
        except (TypeError, ValueError):
            c[feld] = vorgabe
    host = str(c.get("server_host") or "127.0.0.1").strip()
    c["server_host"] = host if re.match(r"^[A-Za-z0-9\.\-:_\[\]]{1,80}$", host) else "127.0.0.1"
    return c


# ---------------------------------------------------------------------------
# Cluster-Tabelle: EINE Datei fuer Dienst und Oberflaeche.
# ---------------------------------------------------------------------------
def tabelle() -> dict:
    for kandidat in (
        PTEMPLATES / "matter_cluster.json",              # installiert
        SELF.parent.parent / "templates" / "matter_cluster.json",
        SELF.parent / "templates" / "matter_cluster.json",
        Path(__file__).resolve().parent.parent / "templates" / "matter_cluster.json",
    ):
        if kandidat.is_file():
            d = json_lesen(kandidat)
            if d.get("cluster"):
                return d
    _LOG.error("matter_cluster.json wurde nicht gefunden - es wird nichts uebersetzt.")
    return {"cluster": {}, "geraetetyp": {}}


def umrechnen(typ: str, wert):
    """Rohwert in einen brauchbaren Wert umrechnen.

    Die Faktoren stammen aus der Matter-Spezifikation. Passt ein Wert nicht
    ins erwartete Muster, wird None zurueckgegeben - eine erfundene 0 waere
    eine stille Falschaussage.
    """
    if wert is None:
        return None
    try:
        if typ == "text":
            return str(wert)
        if typ == "bool":
            return 1 if wert in (True, 1, "1", "true", "True") else 0
        if typ == "bit0":
            return int(wert) & 1
        if typ == "energie_struct":
            # ElectricalEnergyMeasurement liefert keine blanke Zahl, sondern
            # eine EnergyMeasurementStruct. Gebraucht wird daraus das Feld
            # 'energy' in Milliwattstunden.
            #
            # UNGEPRUEFT: wie der Matter-Server die Struktur ueber die
            # WebSocket-Schnittstelle benennt, liess sich ohne ein solches
            # Geraet nicht nachmessen. Deshalb werden beide gaengigen Formen
            # angenommen - der Feldname und die Feldnummer aus der
            # Spezifikation. Passt keine, wird None zurueckgegeben statt
            # einer erfundenen Zahl.
            if not isinstance(wert, dict):
                return None
            roh = None
            for schluessel in ("energy", "Energy", "0"):
                if schluessel in wert:
                    roh = wert[schluessel]
                    break
            if roh is None:
                return None
            return round(float(roh) / 1000000, 3)
        zahl = float(wert)
        if typ == "zahl":
            return int(zahl) if float(zahl).is_integer() else round(zahl, 3)
        if typ == "gleitkomma":
            # Die Luftguete-Cluster melden ein 'single', keine Ganzzahl. Ein
            # CO2-Wert von 812,5 ppm darf nicht zu 812 werden.
            return round(zahl, 3)
        if typ == "hundertstel":
            return round(zahl / 100, 2)
        if typ == "zehntel":
            return round(zahl / 10, 2)
        if typ == "milli":
            return round(zahl / 1000, 3)
        if typ == "halbprozent":
            return round(zahl / 2, 1)
        if typ == "prozent254":
            return round(zahl * 100 / 254, 1)
        if typ == "lux":
            # Spezifikation: MeasuredValue = 10000 * log10(lux) + 1
            if zahl <= 0:
                return 0
            return round(10 ** ((zahl - 1) / 10000), 1)
        if typ == "mwh":
            # Matter zaehlt Milliwattstunden, Loxone will kWh.
            return round(zahl / 1000000, 3)
    except (TypeError, ValueError):
        return None
    return None


# ---------------------------------------------------------------------------
# MQTT ueber das LoxBerry-Gateway
#
# Das MQTT-Gateway ist seit LoxBerry 3 Bestandteil des Systems, kein Plugin.
# Es wird nicht nachinstalliert, sondern unter System -> MQTT Gateway
# eingeschaltet.
#
# Achtung: Mqtt.Brokerhost ist ab Werk gesetzt ("localhost"). Eine Pruefung
# darauf beantwortet also NICHT die Frage, ob Nachrichten ankommen koennen -
# massgeblich ist Gatewayautostart.
#
# Gesendet wird ueber den UDP-Eingang des Gateways: so braucht das Plugin
# ueberhaupt keine Broker-Zugangsdaten.
# ---------------------------------------------------------------------------
def mqtt_zustand() -> dict:
    gen = json_lesen(LBHOME / "config" / "system" / "general.json")
    m = gen.get("Mqtt") or gen.get("mqtt") or {}
    autostart = m.get("Gatewayautostart", m.get("gatewayautostart"))
    try:
        udp = int(m.get("Udpinport", m.get("udpinport")))
    except (TypeError, ValueError):
        udp = 0
    return {
        "gefunden": bool(m),
        "autostart": 1 if str(autostart) in ("1", "true", "True") else 0,
        "udpport": udp,
        "broker": str(m.get("Brokerhost", m.get("brokerhost", ""))),
        "brokerport": str(m.get("Brokerport", m.get("brokerport", ""))),
    }


def mqtt_praefix(cfg: dict) -> str:
    """Das Themenpraefix, gesaeubert.

    Ein Wert, der in eine zeilenorientierte Uebertragung geht, wird an EINER
    Stelle gesaeubert - und in BEIDEN Haelften der Zeile. Bis 0.9.16 wurde nur
    der Wert gesaeubert; ein Zeilenumbruch im Praefix (moeglich ueber eine
    zurueckgespielte Sicherung) zerlegte das Datagramm.
    """
    roh = cfg.get("mqtt_topic")
    p = str(roh).strip("/ \t\r\n") if isinstance(roh, (str, int, float)) else ""
    return p if re.match(r"^[A-Za-z0-9_/\-]{1,64}$", p) else "matter"


# Themen, die einen ZUSTAND tragen und deshalb retained gesendet werden:
# Loxone hat damit nach einem Neustart des Brokers, des Gateways oder des
# Miniservers sofort wieder den Stand. Alles Uebrige - Messwerte mit
# Zeitbezug und das Lebenszeichen - geht ohne Retain hinaus, damit kein alter
# Wert als aktueller erscheint. Hausstandard vom 03.09.2026.
#
# Bis 0.9.16 gab es gar kein Retain: ein Fensterkontakt, der sich zwei Tage
# nicht bewegt, war nach einem Broker-Neustart zwei Tage lang unbekannt.
ZUSTANDSTHEMEN = (
    "erreichbar", "name", "knoten", "schalter", "kontakt", "verschlossen",
    "besetzt", "rauch", "co", "batterie_niedrig", "ventil", "betriebsart",
    "luefter_stufe", "regen", "wasser", "tuer", "fenster", "bewegung",
    "sperre", "kindersicherung", "warmwasser", "programm", "zustand",
)


def ist_zustand(schluessel: str) -> bool:
    """Traegt dieses Thema einen Zustand (retained) oder einen Messwert?

    Der Vergleich laeuft ueber den letzten Pfadteil, weil die Themen
    'geraet3/1/schalter' heissen. Das Lebenszeichen (online, ok, ts, zaehler)
    ist NIE retained - retained zeigte es immer 'lebt'.
    """
    letzter = schluessel.rsplit("/", 1)[-1]
    if letzter in ("online", "ok", "ts", "zaehler", "probe"):
        return False
    return letzter in ZUSTANDSTHEMEN


def mqtt_senden(paare: dict, praefix: str) -> set:
    """Veroeffentlichen. Rueckgabe: die Schluessel, die WIRKLICH hinausgingen.

    Bis 0.9.16 gab die Funktion nichts zurueck, und der Aufrufer schrieb den
    Merker 'zuletzt gesendet' trotzdem fort - auch wenn gar nichts gesendet
    wurde (kein UDP-Port, kein Socket, Abbruch mitten in der Schleife). Weil
    danach nur noch Aenderungen hinausgehen, fehlten diese Werte dauerhaft.
    """
    gesendet: set = set()
    z = mqtt_zustand()
    if not z["udpport"]:
        melde_gebremst("mqtt_kein_port",
                       "MQTT: kein UDP-Eingangsport in der general.json gefunden - nichts gesendet.")
        return gesendet
    if not z["autostart"]:
        melde_gebremst("mqtt_aus",
                       "MQTT: das Gateway ist nicht auf Autostart gestellt (System, MQTT Gateway). "
                       "Es wird gesendet, aber vermutlich hoert niemand zu.")
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    except OSError as err:
        melde_gebremst("mqtt_socket", f"MQTT: Socket nicht moeglich ({err}).")
        return gesendet
    sauber_praefix = re.sub(r"[^A-Za-z0-9_/\-]", "_", str(praefix))
    try:
        for k, v in paare.items():
            if v is None or v == "":
                continue    # fehlender Wert: nichts senden statt einer erfundenen 0
            sauber_k = re.sub(r"[^A-Za-z0-9_/\-]", "_", str(k))
            befehl = "retain" if ist_zustand(sauber_k) else "publish"
            nachricht = (f"{befehl} {sauber_praefix}/{sauber_k} "
                         f"{mqtt_wert_saeubern(v)}").encode("utf-8")
            try:
                s.sendto(nachricht, ("127.0.0.1", z["udpport"]))
            except OSError as err:
                # Ein Fehler bei EINEM Thema beendet nicht den ganzen Durchgang;
                # gemeldet wird er, und der Schluessel gilt als nicht gesendet.
                melde_gebremst("mqtt_senden", f"MQTT: Senden fehlgeschlagen ({err}).")
                continue
            gesendet.add(k)
    finally:
        s.close()
    return gesendet


# ---------------------------------------------------------------------------
# Fehlermeldungen, die sagen, wer geantwortet hat
# ---------------------------------------------------------------------------
def fehlertext(err: Exception) -> str:
    name = type(err).__name__
    text = str(err) or name
    klein = text.lower()
    errno = getattr(err, "errno", None)
    if isinstance(err, asyncio.TimeoutError) or "timed out" in klein:
        return ("Zeitueberlauf: der Matter-Server hat nicht geantwortet. "
                "Laeuft der Container, und stimmen Adresse und Port?")
    if errno == 111 or "connection refused" in klein:
        return ("Verbindung abgewiesen (ECONNREFUSED): der Rechner ist erreichbar, aber auf "
                "diesem Port lauscht nichts. Meist laeuft der Matter-Server nicht - "
                "Reiter Einstellungen, Knopf Container starten.")
    if errno == 113 or "no route to host" in klein:
        return "Kein Weg zum Ziel (EHOSTUNREACH): pruefen Sie Netz und Adresse."
    if "network is unreachable" in klein:
        return "Netz nicht erreichbar (ENETUNREACH): der LoxBerry kommt in dieses Netz nicht hinein."
    if "name or service not known" in klein or "getaddrinfo" in klein:
        return ("Namensaufloesung fehlgeschlagen: der Hostname ist im Netz nicht bekannt. "
                "Statt des Namens die IP-Adresse eintragen.")
    if "<html" in klein or "<!doctype" in klein or "invalid status" in klein:
        return ("Es kam kein WebSocket zurueck, sondern eine Webseite - geantwortet hat also ein "
                "anderer Dienst auf diesem Port, nicht der Matter-Server. Port pruefen (Vorgabe 5580).")
    return f"{name}: {text}"


# ---------------------------------------------------------------------------
# Uebersetzung: aus dem Knotenbestand des Matter-Servers sprechende Werte
# ---------------------------------------------------------------------------
def name_saeubern(text: str) -> str:
    """Ein MQTT-taugliches Namensstueck: keine Schraegstriche, keine Leerzeichen."""
    t = re.sub(r"[^A-Za-z0-9_\-]+", "_", str(text)).strip("_")
    return t[:40] if t else ""


def knoten_abbilden(node: dict, tab: dict, cfg: dict) -> dict:
    """Einen Knoten in die Loxone-Sicht uebersetzen.

    Der Knoten kommt so, wie der Matter-Server ihn liefert: attributes ist ein
    Woerterbuch mit Schluesseln der Form ENDPUNKT/CLUSTER/ATTRIBUT.
    """
    cluster = tab.get("cluster", {})
    attribute = node.get("attributes") or {}
    node_id = node.get("node_id")

    info = {}
    endpunkte: dict[str, dict] = {}
    roh: dict[str, object] = {}

    for pfad, wert in attribute.items():
        teile = str(pfad).split("/")
        if len(teile) != 3:
            continue
        ep, cl, at = teile
        beschreibung = cluster.get(cl, {})
        feld = (beschreibung.get("attribute") or {}).get(at)
        if feld is None:
            # Unbekanntes Attribut: nur weiterreichen, wenn ausdruecklich
            # gewuenscht. Es geht damit nichts verloren.
            # Cluster 29 (Descriptor) bleibt aussen vor: er beschreibt den
            # Aufbau des Geraets, nicht seinen Zustand, und wird oben schon
            # fuer die Geraetetypen ausgewertet. In der Rohdurchreichung
            # waere er nur Rauschen auf dem Broker.
            if cfg.get("roh_ein") and cl != "29":
                roh[str(pfad)] = wert if not isinstance(wert, (dict, list)) else json.dumps(wert)
            continue
        neu = umrechnen(str(feld.get("typ") or "zahl"), wert)
        if beschreibung.get("nur_info"):
            # BasicInformation liegt immer auf Endpunkt 0 und beschreibt das
            # ganze Geraet, nicht einen einzelnen Endpunkt.
            info[str(feld["thema"])] = neu
            continue
        endpunkte.setdefault(ep, {})[str(feld["thema"])] = neu

    # Geraetetypen je Endpunkt - nur fuer die Anzeige
    typen: dict[str, list] = {}
    for pfad, wert in attribute.items():
        teile = str(pfad).split("/")
        if len(teile) == 3 and teile[1] == "29" and teile[2] == "0" and isinstance(wert, list):
            typen[teile[0]] = [
                str(e.get("deviceType")) for e in wert
                if isinstance(e, dict) and e.get("deviceType") is not None
            ]

    # Abgeleitetes: Loxone rechnet in Kelvin, Matter in Mired. Wer den Wert
    # liest und den Ausgang bedient, haette sonst zwei Einheiten fuer dieselbe
    # Sache vor sich - das sieht wie ein Fehler aus. Kein geratener Wert,
    # sondern der Kehrwert: Kelvin = 1000000 / Mired.
    for felder in endpunkte.values():
        mired = felder.get("farbtemperatur_mired")
        if isinstance(mired, (int, float)) and mired > 0:
            felder["farbtemperatur_kelvin"] = int(round(1000000 / mired))
        # Dasselbe fuer den Farbton: gelesen wird er als 0..254 (Matter),
        # geschrieben in Grad 0..360 (Aktion 'farbton', befehl_ausfuehren).
        # Bis 0.9.16 standen die beiden Einheiten unverbunden nebeneinander -
        # genau die Lage, die der Absatz darueber fuer Kelvin beschreibt.
        roh = felder.get("farbton_roh")
        if isinstance(roh, (int, float)) and 0 <= roh <= 254:
            felder["farbton_grad"] = int(round(roh * 360 / 254))

    bezeichnung = info.get("bezeichnung") or info.get("produkt") or f"Knoten {node_id}"
    return {
        "node_id": node_id,
        "name": str(bezeichnung),
        "kurz": name_saeubern(bezeichnung) or f"knoten{node_id}",
        "erreichbar": 1 if node.get("available") else 0,
        "bruecke": 1 if node.get("is_bridge") else 0,
        "hersteller": info.get("hersteller"),
        "produkt": info.get("produkt"),
        "firmware": info.get("firmware"),
        "endpunkte": endpunkte,
        "typen": typen,
        "roh": roh,
        "anzahl_attribute": len(attribute),
        "ts": int(time.time()),
    }


# ---------------------------------------------------------------------------
# Verbindung zum Matter-Server
# ---------------------------------------------------------------------------
class MatterVerbindung:
    """Duenne Schicht ueber der WebSocket-Schnittstelle.

    Bewusst kein Nachbau des Matter-Protokolls - hier wird ausschliesslich die
    dokumentierte WebSocket-Schnittstelle des Matter-Servers bedient.
    """

    def __init__(self, cfg: dict) -> None:
        self.url = f"ws://{cfg['server_host']}:{int(cfg['server_port'])}/ws"
        self.ws = None
        self.server_info: dict = {}
        self.knoten: dict[int, dict] = {}
        # Letztes Ereignis je Knoten und Endpunkt. Siehe _taste().
        self.ereignisse: dict[int, dict] = {}
        self._warten: dict[str, asyncio.Future] = {}
        self._schleife: asyncio.AbstractEventLoop | None = None
        # Wird gesetzt, sobald start_listening den vollstaendigen Bestand
        # geliefert hat. Vorher ist jedes Abbild leer und damit irrefuehrend.
        self.bestand_da = asyncio.Event()

    async def verbinden(self):
        import websockets
        self._schleife = asyncio.get_running_loop()
        # open_timeout begrenzt das Warten auf den Handshake; ohne das haengt
        # ein Fehlversuch bis zum Betriebssystem-Zeitueberlauf.
        self.ws = await websockets.connect(self.url, open_timeout=10, ping_interval=30)
        # Der Server sendet unaufgefordert seine ServerInfoMessage.
        roh = await asyncio.wait_for(self.ws.recv(), timeout=10)
        nachricht = json.loads(roh)
        if "fabric_id" not in nachricht:
            raise RuntimeError(
                "Der Dienst auf diesem Port hat sich nicht als Matter-Server gemeldet. "
                "Erwartet wurde eine ServerInfoMessage mit fabric_id.")
        self.server_info = nachricht
        _LOG.info("Verbunden mit dem Matter-Server: SDK %s, Schema %s, Bluetooth %s",
                  nachricht.get("sdk_version"), nachricht.get("schema_version"),
                  "ja" if nachricht.get("bluetooth_enabled") else "nein")

    async def senden(self, befehl: str, args: dict | None = None, zeit: float = 30.0):
        """Befehl absetzen und auf das Ergebnis warten."""
        if self.ws is None:
            raise RuntimeError("nicht verbunden")
        kennung = uuid.uuid4().hex
        nachricht = {"message_id": kennung, "command": befehl}
        if args:
            nachricht["args"] = args
        future: asyncio.Future = self._schleife.create_future()
        self._warten[kennung] = future
        await self.ws.send(json.dumps(nachricht))
        try:
            return await asyncio.wait_for(future, timeout=zeit)
        finally:
            self._warten.pop(kennung, None)

    async def senden_ohne_warten(self, befehl: str, args: dict | None = None) -> None:
        if self.ws is None:
            raise RuntimeError("nicht verbunden")
        nachricht = {"message_id": uuid.uuid4().hex, "command": befehl}
        if args:
            nachricht["args"] = args
        await self.ws.send(json.dumps(nachricht))

    def _ergebnis(self, nachricht: dict) -> bool:
        """Antwort auf einen Befehl zuordnen. Rueckgabe: war es eine Antwort?"""
        kennung = nachricht.get("message_id")
        if kennung is None or kennung not in self._warten:
            return "message_id" in nachricht
        future = self._warten[kennung]
        if future.done():
            return True
        if "error_code" in nachricht:
            future.set_exception(RuntimeError(
                f"Der Matter-Server hat abgelehnt (Fehler {nachricht.get('error_code')}): "
                f"{nachricht.get('details') or 'ohne naehere Angabe'}"))
        else:
            future.set_result(nachricht.get("result"))
        return True

    async def lauschen(self, bei_aenderung) -> None:
        """start_listening absetzen und danach dauerhaft Ereignisse annehmen."""
        kennung = uuid.uuid4().hex
        await self.ws.send(json.dumps({"message_id": kennung, "command": "start_listening"}))
        # Als Ergebnis kommt der vollstaendige Bestand aller Knoten.
        while True:
            nachricht = json.loads(await self.ws.recv())
            if nachricht.get("message_id") == kennung:
                for k in nachricht.get("result") or []:
                    if isinstance(k, dict) and k.get("node_id") is not None:
                        self.knoten[int(k["node_id"])] = k
                _LOG.info("Bestand geholt: %d Knoten.", len(self.knoten))
                self.bestand_da.set()
                break
            self._ergebnis(nachricht)
        bei_aenderung()

        async for roh in self.ws:
            nachricht = json.loads(roh)
            if self._ergebnis(nachricht):
                continue
            if self._ereignis(nachricht):
                bei_aenderung()

    def _ereignis(self, nachricht: dict) -> bool:
        """Ein Ereignis einarbeiten. Rueckgabe: hat sich etwas geaendert?"""
        art = nachricht.get("event")
        daten = nachricht.get("data")
        if art in ("node_added", "node_updated"):
            if isinstance(daten, dict) and daten.get("node_id") is not None:
                self.knoten[int(daten["node_id"])] = daten
                return True
            return False
        if art == "node_removed":
            try:
                self.knoten.pop(int(daten), None)
                _LOG.info("Knoten %s wurde entfernt.", daten)
                return True
            except (TypeError, ValueError):
                return False
        if art == "attribute_updated":
            # data = [node_id, attribute_path, neuer_wert]
            if not isinstance(daten, list) or len(daten) != 3:
                return False
            node_id, pfad, wert = daten
            knoten = self.knoten.get(int(node_id))
            if knoten is None:
                return False
            knoten.setdefault("attributes", {})[str(pfad)] = wert
            return True
        if art == "node_event":
            return self._taste(daten)
        if art in ("endpoint_added", "endpoint_removed"):
            # Bridges melden Endpunkte zur Laufzeit an und ab. Die Attribute
            # selbst schickt der Server danach als node_updated; hier wird nur
            # dafuer gesorgt, dass das Abbild neu geschrieben wird.
            _LOG.info("Endpunkt-Ereignis %s: %s", art, daten)
            return True
        if art == "server_shutdown":
            _LOG.warning("Der Matter-Server faehrt herunter.")
            return False
        if art == "server_info_updated":
            if isinstance(daten, dict):
                self.server_info.update(daten)
            return False
        return False

    def _taste(self, daten) -> bool:
        """Ein node_event einarbeiten - vor allem Tastendruecke.

        Der Switch-Cluster 0x003B (59) meldet Tastendruecke AUSSCHLIESSLICH als
        Ereignis; ein Attribut, das man abfragen koennte, gibt es nicht. Bis
        0.9.9 hat _ereignis() node_event stillschweigend verworfen - damit war
        jeder Szenentaster fuer dieses Plugin unerreichbar, ganz gleich was in
        der Cluster-Tabelle stand.

        Gemerkt wird je Knoten und Endpunkt der letzte Ereigniscode, die
        Stellung, die Uhrzeit und ein Zaehler. Der Zaehler ist der Grund, warum
        das in Loxone taugt: zweimal dieselbe Taste ergibt zweimal denselben
        Code, und ein Eingang, der auf Wertaenderung reagiert, saehe den
        zweiten Druck sonst nicht.

        UNGEPRUEFT: wie der Matter-Server die Nutzlast des Ereignisses benennt,
        liess sich ohne Taster nicht nachmessen. Deshalb werden mehrere
        Schreibweisen angenommen, und wo keine passt, bleibt die Stellung leer
        statt eine erfundene 0 zu tragen.
        """
        if not isinstance(daten, dict):
            return False
        try:
            node_id = int(daten.get("node_id"))
            ep = str(int(daten.get("endpoint_id")))
            cluster_id = int(daten.get("cluster_id"))
            event_id = int(daten.get("event_id"))
        except (TypeError, ValueError):
            return False
        if cluster_id != 59:
            melde_gebremst(
                f"ereignis_{cluster_id}",
                f"Ereignisse von Cluster {cluster_id} werden nicht ausgewertet "
                f"(zuletzt Knoten {node_id}, Endpunkt {ep}, Ereignis {event_id}).", 3600)
            return False
        stellung = None
        nutz = daten.get("data")
        if isinstance(nutz, dict):
            for schluessel in ("NewPosition", "PreviousPosition",
                               "newPosition", "previousPosition"):
                if schluessel in nutz:
                    try:
                        stellung = int(nutz[schluessel])
                    except (TypeError, ValueError):
                        stellung = None
                    break
        je_knoten = self.ereignisse.setdefault(node_id, {})
        vorher = je_knoten.get(ep) or {}
        je_knoten[ep] = {
            "taste": event_id,
            "taste_zaehler": int(vorher.get("taste_zaehler") or 0) + 1,
            "taste_position": stellung,
            "taste_zeit": int(time.time()),
        }
        _LOG.info("Taste an Knoten %s, Endpunkt %s: Ereignis %s, Stellung %s.",
                  node_id, ep, event_id, stellung)
        return True

    async def schliessen(self) -> None:
        if self.ws is not None:
            try:
                await self.ws.close()
            except Exception:  # noqa: BLE001 - beim Schliessen ist jeder Fehler egal
                pass
            self.ws = None


# ---------------------------------------------------------------------------
# Befehle
#
# Die Form der Cluster-Befehle folgt den Datenklassen des Matter-SDK. Die
# Felder werden VOLLSTAENDIG mitgeschickt, auch die mit Vorgabewert: der
# Matter-Server bildet die Nutzlast auf die SDK-Datenklasse ab, und ein
# fehlendes Feld ist dort kein Vorgabewert, sondern ein Fehler.
# ---------------------------------------------------------------------------
async def befehl_ausfuehren(v: MatterVerbindung, b: dict, cfg: dict, tab: dict):
    """Rueckgabe: (ok, Meldung)."""
    aktion = str(b.get("aktion") or "")

    # Lesende und verwaltende Aktionen brauchen die Steuerungsfreigabe nicht.
    if aktion == "abruf":
        # Bis 0.9.9 stand hier nur "return (1, 'Sofortabruf eingeplant.')" -
        # es wurde KEIN Befehl an den Matter-Server geschickt. Das Abbild wurde
        # danach lediglich aus dem Speicherzwischenstand neu geschrieben; war
        # dieser leer, entstand ein leeres Abbild. Genau in dieser Lage ruft
        # man den Befehl aber auf.
        #
        # Ohne Knotennummer wird der Bestand neu geholt (get_nodes), mit
        # Knotennummer der eine Knoten neu interviewt (interview_node). Die
        # Namen und Argumente stammen aus APICommand in
        # matter_server/common/models.py und den Signaturen in
        # matter_server/server/device_controller.py.
        roh = b.get("knoten")
        if roh in (None, "", 0, "0"):
            try:
                erg = await v.senden("get_nodes", {"only_available": False}, zeit=60)
            except Exception as err:  # noqa: BLE001
                return (0, "Schritt get_nodes: " + fehlertext(err))
            if not isinstance(erg, list):
                return (0, "Schritt get_nodes: der Matter-Server hat keine Knotenliste "
                           f"geliefert, sondern {type(erg).__name__}.")
            gezaehlt = 0
            for k in erg:
                if isinstance(k, dict) and k.get("node_id") is not None:
                    v.knoten[int(k["node_id"])] = k
                    gezaehlt += 1
            return (1, f"Bestand neu geholt: {gezaehlt} Knoten.")
        try:
            node_id = int(roh)
        except (TypeError, ValueError):
            return (0, "Die Knotennummer ist keine Zahl.")
        if node_id not in v.knoten:
            return (0, f"Knoten {node_id} ist dem Matter-Server nicht bekannt. "
                       f"Bekannt sind: {', '.join(str(k) for k in sorted(v.knoten)) or 'keine'}.")
        try:
            # Ein Interview kann dauern; der Server meldet das Ergebnis danach
            # von selbst als node_updated.
            await v.senden("interview_node", {"node_id": node_id}, zeit=120)
        except Exception as err:  # noqa: BLE001
            return (0, f"Schritt interview_node fuer Knoten {node_id}: " + fehlertext(err))
        return (1, f"Knoten {node_id} wurde neu ausgelesen.")

    if not cfg.get("steuerung_ein"):
        return (0, "Die Steuerung ist ausgeschaltet. Reiter Einstellungen, "
                   "Haken Schreibende Befehle zulassen.")

    # ---- Verwaltung: Inbetriebnahme und Entfernen -------------------------
    if aktion == "anlernen":
        code = str(b.get("code") or "").strip()
        if not re.match(r"^(MT:[A-Z0-9.\-]{5,60}|[0-9]{11,21})$", code):
            return (0, "Das ist weder ein Matter-QR-Code (beginnt mit MT:) noch ein "
                       "manueller Kopplungscode (11 oder 21 Ziffern).")
        nur_netz = bool(b.get("nur_netz"))
        if not nur_netz and not v.server_info.get("bluetooth_enabled"):
            return (0, "Der Matter-Server meldet, dass er kein Bluetooth hat. Ein fabrikneues "
                       "Funkgeraet laesst sich damit nicht anlernen. Abhilfe: dem Container "
                       "/run/dbus durchreichen und --bluetooth-adapter setzen - oder das "
                       "Geraet zuerst mit einem anderen Controller ins Netz bringen und dann "
                       "hier mit 'nur im Netz suchen' anlernen.")
        if not nur_netz and not v.server_info.get("wifi_credentials_set") \
                and not v.server_info.get("thread_credentials_set"):
            return (0, "Es sind weder WLAN- noch Thread-Zugangsdaten hinterlegt. Ohne die kann "
                       "ein fabrikneues Geraet nicht ins Netz gebracht werden - im Reiter "
                       "Geraete anlernen eintragen.")
        try:
            erg = await v.senden("commission_with_code",
                                 {"code": code, "network_only": nur_netz}, zeit=180)
        except Exception as err:  # noqa: BLE001
            return (0, "Anlernen fehlgeschlagen: " + fehlertext(err))
        node_id = (erg or {}).get("node_id")
        if node_id is not None:
            v.knoten[int(node_id)] = erg
        return (1, f"Geraet angelernt, es hat die Knotennummer {node_id}.")

    if aktion == "wlan":
        ssid = str(b.get("ssid") or "")
        pw = str(b.get("passwort") or "")
        if ssid == "" or pw == "":
            return (0, "WLAN-Name und WLAN-Passwort duerfen nicht leer sein.")
        await v.senden("set_wifi_credentials", {"ssid": ssid, "credentials": pw})
        return (1, "WLAN-Zugangsdaten an den Matter-Server uebergeben.")

    if aktion == "thread":
        ds = str(b.get("dataset") or "").strip()
        if not re.match(r"^[0-9A-Fa-f]{20,600}$", ds):
            return (0, "Das Thread-Dataset muss eine Hexadezimalzeichenkette sein "
                       "(so, wie der Border-Router sie ausgibt).")
        await v.senden("set_thread_dataset", {"dataset": ds})
        return (1, "Thread-Dataset an den Matter-Server uebergeben.")

    node_id = b.get("knoten")
    try:
        node_id = int(node_id)
    except (TypeError, ValueError):
        return (0, "Es fehlt die Knotennummer.")
    if node_id not in v.knoten:
        return (0, f"Knoten {node_id} ist dem Matter-Server nicht bekannt. "
                   f"Bekannt sind: {', '.join(str(k) for k in sorted(v.knoten)) or 'keine'}.")

    if aktion == "entfernen":
        await v.senden("remove_node", {"node_id": node_id}, zeit=60)
        v.knoten.pop(node_id, None)
        return (1, f"Knoten {node_id} aus der Fabric entfernt.")

    if aktion == "fenster":
        erg = await v.senden("open_commissioning_window", {"node_id": node_id}, zeit=60)
        erg = erg or {}
        # Die Antwort traegt drei Felder: setup_pin_code, setup_manual_code und
        # setup_qr_code. Bis 0.9.9 wurde nur der manuelle Code gelesen und der
        # QR-Code verworfen - dabei ist er der bequemere Weg, weil ihn jede
        # Matter-App einlesen kann.
        teile = [f"Manueller Code: {erg.get('setup_manual_code')}"]
        if erg.get("setup_qr_code"):
            teile.append(f"QR-Text: {erg.get('setup_qr_code')}")
        return (1, "Kopplungsfenster geoeffnet. " + " | ".join(teile))

    if aktion == "anstupsen":
        erg = await v.senden("ping_node", {"node_id": node_id}, zeit=60)
        return (1, f"Antwort auf den Anstupser: {json.dumps(erg)}")

    if aktion == "name":
        # Der Name wird in das GERAET geschrieben, nicht in eine eigene Liste
        # des Plugins: BasicInformation.NodeLabel (Cluster 40, Attribut 5) ist
        # laut Datenmodell beschreibbar (char_string, length=32). Damit heisst
        # das Geraet auch in jeder anderen Fabric so - und das Plugin muss
        # keine Zuordnung pflegen, die auseinanderlaufen kann.
        neuer = str(b.get("bezeichnung") or "").strip()
        if neuer == "":
            return (0, "Der Name darf nicht leer sein.")
        if re.search(r"[\x00-\x1f\x7f]", neuer):
            return (0, "Der Name enthaelt Steuerzeichen.")
        laenge = len(neuer.encode("utf-8"))
        if laenge > 32:
            return (0, f"Der Name ist zu lang: NodeLabel laesst 32 Zeichen zu, dieser hat "
                       f"{laenge}. (Umlaute zaehlen doppelt, weil Matter in UTF-8 zaehlt.)")
        try:
            await v.senden("write_attribute",
                           {"node_id": node_id, "attribute_path": "0/40/5", "value": neuer},
                           zeit=60)
        except Exception as err:  # noqa: BLE001
            return (0, f"Schritt write_attribute 0/40/5 fuer Knoten {node_id}: "
                       + fehlertext(err))
        # Das Abbild traegt sonst den alten Namen, bis der Server die Aenderung
        # von sich aus meldet.
        knoten = v.knoten.get(node_id)
        if isinstance(knoten, dict):
            knoten.setdefault("attributes", {})["0/40/5"] = neuer
        return (1, f"Knoten {node_id} heisst jetzt {neuer}.")

    # ---- Geraetebefehle ---------------------------------------------------
    try:
        ep = int(b.get("endpunkt", 1))
    except (TypeError, ValueError):
        return (0, "Die Endpunktnummer ist keine Zahl.")

    async def cluster_befehl(cluster_id: int, name: str, nutzlast: dict, timed_ms=None):
        args = {"node_id": node_id, "endpoint_id": ep, "cluster_id": cluster_id,
                "command_name": name, "payload": nutzlast}
        if timed_ms is not None:
            # Manche Befehle verlangen einen "timed invoke" - das Datenmodell
            # kennzeichnet sie mit mustUseTimedInvoke. Ohne diese Angabe weist
            # das Geraet den Befehl ab. Betrifft hier LockDoor und UnlockDoor.
            args["timed_request_timeout_ms"] = int(timed_ms)
        return await v.senden("device_command", args, zeit=60)

    async def attribut_schreiben(pfad: str, wert):
        return await v.senden("write_attribute",
                              {"node_id": node_id, "attribute_path": pfad, "value": wert}, zeit=60)

    def zahl(feld: str, klein: float, gross: float):
        w = b.get(feld)
        try:
            f = float(w)
        except (TypeError, ValueError):
            return None
        return f if klein <= f <= gross else None

    try:
        if aktion in ("ein", "aus", "umschalten"):
            name = {"ein": "On", "aus": "Off", "umschalten": "Toggle"}[aktion]
            await cluster_befehl(6, name, {})
            return (1, f"Cluster OnOff, Befehl {name} an Knoten {node_id}, Endpunkt {ep} gesendet.")

        if aktion == "helligkeit":
            p = zahl("wert", 0, 100)
            if p is None:
                return (0, "Die Helligkeit muss zwischen 0 und 100 Prozent liegen.")
            # Matter zaehlt 0..254, nicht 0..100.
            stufe = int(round(p * 254 / 100))
            zeit10 = int(zahl("uebergang", 0, 600) or 0)
            await cluster_befehl(8, "MoveToLevelWithOnOff", {
                "level": stufe, "transitionTime": zeit10,
                "optionsMask": 0, "optionsOverride": 0})
            return (1, f"Helligkeit {p:.0f} % (Matter-Stufe {stufe} von 254) gesendet.")

        if aktion == "farbtemperatur":
            k = zahl("wert", 1000, 10000)
            if k is None:
                return (0, "Die Farbtemperatur muss zwischen 1000 und 10000 Kelvin liegen.")
            mired = int(round(1000000 / k))
            zeit10 = int(zahl("uebergang", 0, 600) or 0)
            await cluster_befehl(768, "MoveToColorTemperature", {
                "colorTemperatureMireds": mired, "transitionTime": zeit10,
                "optionsMask": 0, "optionsOverride": 0})
            return (1, f"Farbtemperatur {k:.0f} K (= {mired} Mired) gesendet.")

        if aktion == "rollo":
            p = zahl("wert", 0, 100)
            if p is None:
                return (0, "Die Position muss zwischen 0 und 100 Prozent liegen.")
            # Matter zaehlt Hundertstel Prozent, und 0 heisst GANZ OFFEN.
            await cluster_befehl(258, "GoToLiftPercentage",
                                 {"liftPercent100ths": int(round(p * 100))})
            return (1, f"Rollo-Position {p:.0f} % gesendet (0 = ganz offen).")

        if aktion in ("rollo_auf", "rollo_zu", "rollo_stopp"):
            name = {"rollo_auf": "UpOrOpen", "rollo_zu": "DownOrClose",
                    "rollo_stopp": "StopMotion"}[aktion]
            await cluster_befehl(258, name, {})
            return (1, f"Cluster WindowCovering, Befehl {name} gesendet.")

        # ---- Farbe -------------------------------------------------------
        # Loxone rechnet den Farbton in Grad (0..360) und die Saettigung in
        # Prozent; Matter zaehlt beides 0..254. Umgerechnet wird hier, damit
        # der virtuelle Ausgang einen blanken Analogwert schicken kann - genau
        # so, wie es die Ausfuhren dieser Anlage bei Kelvin und Helligkeit
        # auch tun. Ein zusammengesetztes Loxone-Farbformat wird NICHT
        # angenommen: wie es an einem virtuellen Ausgang ankaeme, ist hier
        # nicht gemessen, und geraten wird nicht.
        if aktion in ("farbton", "saettigung", "farbe"):
            zeit10 = int(zahl("uebergang", 0, 600) or 0)
            if aktion == "farbton":
                grad = zahl("wert", 0, 360)
                if grad is None:
                    return (0, "Der Farbton muss zwischen 0 und 360 Grad liegen.")
                stufe = int(round(grad * 254 / 360))
                await cluster_befehl(768, "MoveToHue", {
                    "hue": stufe, "direction": 0, "transitionTime": zeit10,
                    "optionsMask": 0, "optionsOverride": 0})
                return (1, f"Farbton {grad:.0f} Grad (Matter-Stufe {stufe} von 254) gesendet.")
            if aktion == "saettigung":
                p = zahl("wert", 0, 100)
                if p is None:
                    return (0, "Die Saettigung muss zwischen 0 und 100 Prozent liegen.")
                stufe = int(round(p * 254 / 100))
                await cluster_befehl(768, "MoveToSaturation", {
                    "saturation": stufe, "transitionTime": zeit10,
                    "optionsMask": 0, "optionsOverride": 0})
                return (1, f"Saettigung {p:.0f} % (Matter-Stufe {stufe} von 254) gesendet.")
            grad = zahl("wert", 0, 360)
            saet = zahl("saettigung", 0, 100)
            if grad is None:
                return (0, "Der Farbton muss zwischen 0 und 360 Grad liegen.")
            if saet is None:
                saet = 100.0
            await cluster_befehl(768, "MoveToHueAndSaturation", {
                "hue": int(round(grad * 254 / 360)),
                "saturation": int(round(saet * 254 / 100)),
                "transitionTime": zeit10, "optionsMask": 0, "optionsOverride": 0})
            return (1, f"Farbe gesendet: Farbton {grad:.0f} Grad, Saettigung {saet:.0f} %.")

        # ---- Schloss -----------------------------------------------------
        if aktion in ("sperren", "entsperren"):
            if not cfg.get("schloss_ein"):
                return (0, "Schloesser zu schalten ist gesperrt. Reiter Einstellungen, "
                           "Haken Schloesser schalten zulassen. Das ist bewusst ein "
                           "eigener Haken: wer Lampen schalten laesst, will damit nicht "
                           "zwangslaeufig die Haustuer aufsperren lassen.")
            name = "LockDoor" if aktion == "sperren" else "UnlockDoor"
            # Beide verlangen laut Datenmodell einen timed invoke
            # (mustUseTimedInvoke). Ohne den weist das Geraet ab.
            await cluster_befehl(257, name, {}, timed_ms=7000)
            return (1, f"Cluster DoorLock, Befehl {name} an Knoten {node_id} gesendet.")

        # ---- Welches Geraet ist das? --------------------------------------
        if aktion == "identify":
            sek = int(zahl("wert", 0, 300) or 15)
            await cluster_befehl(3, "Identify", {"identifyTime": sek})
            return (1, f"Knoten {node_id}, Endpunkt {ep} macht sich {sek} s lang bemerkbar.")

        # ---- Luefter ------------------------------------------------------
        if aktion == "luefter":
            p = zahl("wert", 0, 100)
            if p is None:
                return (0, "Die Luefterstufe muss zwischen 0 und 100 Prozent liegen.")
            # PercentSetting ist der SOLLWERT (Attribut 2). Der Istwert steht
            # in Attribut 3 und wird nur gelesen.
            await attribut_schreiben(f"{ep}/514/2", int(round(p)))
            return (1, f"Luefter-Sollwert {p:.0f} % geschrieben.")

        if aktion in ("soll_heizen", "soll_kuehlen"):
            grad = zahl("wert", -50, 100)
            if grad is None:
                return (0, "Der Sollwert muss zwischen -50 und 100 Grad liegen.")
            attribut = 18 if aktion == "soll_heizen" else 17
            await attribut_schreiben(f"{ep}/513/{attribut}", int(round(grad * 100)))
            return (1, f"Sollwert {grad:.1f} Grad geschrieben (Matter zaehlt Hundertstel).")

        if aktion == "betriebsart":
            m = zahl("wert", 0, 9)
            if m is None:
                return (0, "Die Betriebsart muss eine Zahl von 0 bis 9 sein.")
            await attribut_schreiben(f"{ep}/513/28", int(m))
            return (1, f"Betriebsart {int(m)} geschrieben.")

        # ---- Rohzugriff ---------------------------------------------------
        if aktion == "attribut":
            pfad = str(b.get("pfad") or "")
            if not re.match(r"^[0-9]{1,3}/[0-9]{1,5}/[0-9]{1,5}$", pfad):
                return (0, "Der Attributpfad muss die Form ENDPUNKT/CLUSTER/ATTRIBUT haben, "
                           "zum Beispiel 1/6/0.")
            await attribut_schreiben(pfad, b.get("wert"))
            return (1, f"Attribut {pfad} geschrieben.")

        if aktion == "befehl":
            try:
                cluster_id = int(b.get("cluster"))
            except (TypeError, ValueError):
                return (0, "Die Cluster-Nummer fehlt oder ist keine Zahl.")
            name = str(b.get("name") or "")
            if not re.match(r"^[A-Za-z][A-Za-z0-9]{0,48}$", name):
                return (0, "Der Befehlsname muss so geschrieben sein wie im Matter-SDK, "
                           "zum Beispiel MoveToLevelWithOnOff.")
            nutzlast = b.get("nutzlast")
            if isinstance(nutzlast, str):
                try:
                    nutzlast = json.loads(nutzlast) if nutzlast.strip() else {}
                except ValueError:
                    return (0, "Die Nutzlast ist kein gueltiges JSON.")
            if not isinstance(nutzlast, dict):
                nutzlast = {}
            erg = await cluster_befehl(cluster_id, name, nutzlast)
            return (1, f"Befehl {name} an Cluster {cluster_id} gesendet. "
                       f"Antwort: {json.dumps(erg)[:120]}")

    except Exception as err:  # noqa: BLE001 - jeder Fehler gehoert gemeldet
        return (0, fehlertext(err))

    return (0, f"Unbekannte Aktion: {aktion}")


# ---------------------------------------------------------------------------
# Abbild schreiben und veroeffentlichen
# ---------------------------------------------------------------------------
def nummern_umziehen() -> None:
    """Eine Zuordnung vom alten Ort einmal an den neuen holen.

    Der Regelweg ist preupgrade.sh - der laeuft vor dem Abraeumen. Diese Zeile
    ist der Rueckfall fuer den Fall, dass jemand von Hand ausgepackt hat.
    """
    if DATEI_NUMMERN.is_file() or not DATEI_NUMMERN_ALT.is_file():
        return
    try:
        DATEI_NUMMERN.write_bytes(DATEI_NUMMERN_ALT.read_bytes())
        _LOG.info("Geraetenummern vom alten Ort uebernommen: %s -> %s",
                  DATEI_NUMMERN_ALT, DATEI_NUMMERN)
    except OSError as err:
        _LOG.warning("Geraetenummern liessen sich nicht uebernehmen: %s", err)


def nummern_zuordnen(knoten_ids) -> dict:
    """Feste Geraetenummern, die sich nicht mehr verschieben.

    Bis 0.9.9 war die Geraetenummer der Platz in der sortierten Knotenliste
    (enumerate(sorted(...))). Wurde ein Geraet aus der Fabric entfernt, rueckte
    jedes nachfolgende um eins vor - und der virtuelle Eingang MATTER_3_...,
    das Thema geraet3/... und die Adresse &geraet=3 zeigten danach still auf
    ein anderes Geraet. Kein Fehler, keine Meldung, nur falsche Werte.

    Deshalb steht die Zuordnung jetzt in einer Datei. Eine einmal vergebene
    Nummer wird nie veraendert und auch nach dem Entfernen des Geraets nicht
    neu vergeben - sonst erbte das naechste Geraet die Adressen des alten.

    Beim ERSTEN Lauf entsteht sie genau so, wie sie bis 0.9.9 entstanden waere:
    sortiert, ab 1. Eine bestehende Anlage behaelt damit ihre Loxone-Adressen.
    """
    nummern_umziehen()
    d = json_lesen(DATEI_NUMMERN)
    karte: dict[int, int] = {}
    for k, w in (d.get("nummern") or {}).items():
        try:
            karte[int(k)] = int(w)
        except (TypeError, ValueError):
            continue
    neu = [n for n in sorted(knoten_ids) if n not in karte]
    if neu:
        frei = (max(karte.values()) + 1) if karte else 1
        for n in neu:
            karte[n] = frei
            frei += 1
        if json_schreiben(DATEI_NUMMERN, {
            "_hinweis": "Feste Zuordnung Knotennummer -> Geraetenummer. Nicht von Hand "
                        "aendern: die Geraetenummer steht in den virtuellen Eingaengen "
                        "der Loxone-Projektdatei und in den MQTT-Themen.",
            "nummern": {str(k): w for k, w in sorted(karte.items())},
        }):
            for n in neu:
                _LOG.info("Knoten %s hat die feste Geraetenummer %s bekommen.", n, karte[n])
    return karte


def abbild_schreiben(v: MatterVerbindung, cfg: dict, tab: dict, ok: int,
                     fehler: str = "", voll: bool = False) -> dict:
    karte = nummern_zuordnen(v.knoten.keys())
    geraete: dict[str, dict] = {}
    for node_id in sorted(v.knoten):
        nr = karte.get(node_id)
        if nr is None:
            continue
        g = knoten_abbilden(v.knoten[node_id], tab, cfg)
        g["nummer"] = nr
        # Ereignisthemen dazu. Sie stammen nicht aus den Attributen und stehen
        # deshalb nicht im Knotenbestand - knoten_abbilden() kennt sie nicht.
        for ep, felder in (v.ereignisse.get(node_id) or {}).items():
            ziel = g["endpunkte"].setdefault(ep, {})
            for thema, wert in felder.items():
                if wert is not None:
                    ziel[thema] = wert
        geraete[str(nr)] = g

    lox = {
        "ok": ok,
        "ts": int(time.time()),
        "fehler": fehler,
        "anzahl": len(geraete),
        "server": {
            "sdk_version": v.server_info.get("sdk_version"),
            "schema_version": v.server_info.get("schema_version"),
            # Die kleinste Schemafassung, die der Server noch bedient. Das
            # Plugin fuehrt selbst KEINE Schemafassung - es benutzt eine
            # Handvoll Befehle, und eine Zahl dafuer zu erfinden waere eine
            # erfundene Zahl. Der Wert wird deshalb nur angezeigt, nicht
            # verglichen; beim Umstieg auf einen anderen Server ist er die
            # erste Stelle, an der man nachsieht.
            "min_schema": v.server_info.get("min_supported_schema_version"),
            "bluetooth": 1 if v.server_info.get("bluetooth_enabled") else 0,
            "wlan_gesetzt": 1 if v.server_info.get("wifi_credentials_set") else 0,
            "thread_gesetzt": 1 if v.server_info.get("thread_credentials_set") else 0,
            "fabric_id": v.server_info.get("fabric_id"),
        },
        "geraete": geraete,
    }
    json_schreiben(DATEI_LOXONE, lox)

    if cfg.get("mqtt_ein"):
        praefix = mqtt_praefix(cfg)
        # Auswahl, was ueberhaupt hinausgeht. Eine Bridge mit fuenfzig
        # Endpunkten ist sonst der Unterschied zwischen benutzbar und
        # unbenutzbar. Leer heisst: alles - das ist die Vorgabe, damit sich
        # fuer bestehende Anlagen nichts aendert.
        nur = set()
        for stueck in str(cfg.get("mqtt_nur") or "").replace(";", ",").split(","):
            stueck = stueck.strip()
            if stueck.isdigit():
                nur.add(stueck)
        paare: dict[str, object] = {"ok": ok, "geraete": len(geraete)}
        for nr, g in geraete.items():
            if nur and nr not in nur:
                continue
            basis = f"geraet{nr}"
            paare[f"{basis}/name"] = g["kurz"]
            paare[f"{basis}/erreichbar"] = g["erreichbar"]
            paare[f"{basis}/knoten"] = g["node_id"]
            for ep, felder in g["endpunkte"].items():
                for thema, wert in felder.items():
                    paare[f"{basis}/{ep}/{thema}"] = wert
            for pfad, wert in (g.get("roh") or {}).items():
                paare[f"{basis}/roh/{pfad}"] = wert
        # Nur senden, was sich geaendert hat. Bis 0.9.9 ging bei JEDEM einzelnen
        # attribute_updated der vollstaendige Bestand aller Geraete erneut
        # hinaus - ein bewegter Dimmer erzeugte damit hunderte Telegramme je
        # Sekunde. 'voll' erzwingt das Vollbild; das geschieht nach jedem
        # Verbindungsaufbau, damit ein verpasstes Telegramm nicht dauerhaft
        # fehlt.
        if voll:
            senden = paare
        else:
            senden = {k: w for k, w in paare.items() if _LETZTE_PAARE.get(k) != str(w)}
        if senden:
            # Fortgeschrieben wird NUR, was wirklich hinausging. Bis 0.9.16
            # stand der Merker vor dem Senden und wurde bedingungslos auf
            # alle Paare gesetzt - fiel das Gateway kurz aus, galten die
            # Werte als gesendet und fehlten danach dauerhaft, weil nur
            # noch Aenderungen hinausgehen.
            for k in mqtt_senden(senden, praefix):
                _LETZTE_PAARE[k] = str(paare[k])
        else:
            _LETZTE_PAARE.update({k: str(w) for k, w in paare.items()})
    return geraete


def abbild_stoerung(cfg: dict, fehler: str) -> None:
    """Bei einer Stoerung nur den Zustand neu schreiben, nicht die Werte.

    Bis 0.9.9 wurde hier abbild_schreiben() mit einem frischen, also LEEREN
    Verbindungsobjekt gerufen. Scheiterte schon verbinden() - der Container
    steht, das Netz ist weg -, ueberschrieb das die loxone.json mit einer
    leeren Geraeteliste. Und weil dabei auch ts neu gesetzt wurde, sprang
    ALTER auf 0. Die Oberflaeche zeigte 0 Geraete, der Endpunkt antwortete
    GERAET_UNBEKANNT, und beides sah taufrisch aus - waehrend die Geraete die
    ganze Zeit in der Fabric standen.

    Jetzt bleiben die zuletzt bekannten Werte stehen, und ts bleibt der
    Zeitpunkt der letzten ECHTEN Messung. ALTER waechst damit und sagt die
    Wahrheit: die Werte sind alt, nicht weg.
    """
    lox = json_lesen(DATEI_LOXONE)
    if not lox:
        # Noch nie etwas geschrieben - dann ist eine leere Liste richtig.
        lox = {"ts": int(time.time()), "anzahl": 0, "server": {}, "geraete": {}}
    lox["ok"] = 0
    lox["fehler"] = fehler
    if not lox.get("stoerung_seit"):
        lox["stoerung_seit"] = int(time.time())
    json_schreiben(DATEI_LOXONE, lox)
    if cfg.get("mqtt_ein"):
        praefix = mqtt_praefix(cfg)
        # Nur das Signal, nicht die Werte: die stehen im Broker und sind das
        # Letzte, was gemessen wurde. Sie jetzt zu ueberschreiben hiesse, eine
        # Stoerung als Messwert auszugeben.
        if "ok" in mqtt_senden({"ok": 0}, praefix):
            _LETZTE_PAARE["ok"] = "0"


def herzschlag_senden(cfg: dict, ok: int) -> None:
    """Lebenszeichen, unabhaengig davon, ob sich ein Wert geaendert hat.

    Der Dienst veroeffentlichte bis 0.9.9 ausschliesslich bei Ereignissen.
    Reisst die Verbindung zum Matter-Server ab oder stirbt der Dienst, hoert
    das Senden einfach auf - die zuletzt gesendeten Werte bleiben im Broker
    stehen, und in Loxone sieht ein toter Dienst genauso aus wie ein ruhiges
    Haus. Das ist die stille Falschaussage, gegen die dieses Thema hilft:
    'ts' laeuft weiter, solange der Dienst lebt.
    """
    if not cfg.get("herzschlag"):
        return
    # Der Zeitstempel geht IMMER in die zustand.json, auch wenn MQTT
    # abgeschaltet ist: daran erkennt der Reiter Test, ob der Dienst noch
    # lebt. Die Prozessnummer allein beantwortet das nicht - ein Prozess kann
    # dastehen und nichts mehr tun.
    zustand_schreiben(herzschlag=int(time.time()), herzschlag_ok=int(ok))
    if not cfg.get("mqtt_ein"):
        return
    praefix = mqtt_praefix(cfg)
    mqtt_senden({"online": 1, "ok": int(ok), "ts": int(time.time())}, praefix)


def zustand_schreiben(**felder) -> None:
    z = json_lesen(DATEI_ZUSTAND)
    z.update(felder)
    z["ts"] = int(time.time())
    z["pid"] = os.getpid()
    json_schreiben(DATEI_ZUSTAND, z)


# ---------------------------------------------------------------------------
# Warteschlange
# ---------------------------------------------------------------------------
def antwort_schreiben(kennung: str, ok: int, meldung: str) -> None:
    ORDNER_ANTWORTEN.mkdir(parents=True, exist_ok=True)
    json_schreiben(ORDNER_ANTWORTEN / f"{kennung}.json",
                   {"ok": int(ok), "meldung": str(meldung), "ts": int(time.time())})
    grenze = time.time() - 900
    for alt in ORDNER_ANTWORTEN.glob("*.json"):
        try:
            if alt.stat().st_mtime < grenze:
                alt.unlink()
        except OSError:
            pass


async def warteschlange(v: MatterVerbindung, cfg: dict, tab: dict) -> bool:
    """Alle vorliegenden Befehle abarbeiten. Rueckgabe: Abbild neu schreiben?"""
    ORDNER_BEFEHLE.mkdir(parents=True, exist_ok=True)
    geaendert = False
    for datei in sorted(ORDNER_BEFEHLE.glob("*.json")):
        kennung = datei.stem
        b = json_lesen(datei)
        try:
            datei.unlink()
        except OSError:
            pass
        if not b:
            antwort_schreiben(kennung, 0, "Befehlsdatei war leer oder unlesbar.")
            continue
        # Ein Befehl verfaellt. Bis 0.9.16 trug die Datei keinen Zeitstempel,
        # und diese Schleife arbeitete beim naechsten Verbindungsaufbau alles
        # ab, was im Ordner lag - auch das, was jemand vor Tagen bei stehendem
        # Dienst eingereiht hatte. Bei 'sperren'/'entsperren' bewegt sich dabei
        # eine Tuer. Einstellungen (wlan, thread) verfallen NICHT, sie sind
        # keine Stellbefehle.
        bleibt = ("wlan", "thread", "anlernen", "entfernen", "name")
        ts = b.get("ts")
        if b.get("aktion") not in bleibt and isinstance(ts, (int, float)):
            alter = time.time() - float(ts)
            if alter > BEFEHL_VERFALL_S:
                antwort_schreiben(kennung, 0,
                                  f"Verfallen: der Befehl lag {int(alter)} s in der "
                                  f"Warteschlange (Grenze {BEFEHL_VERFALL_S} s). "
                                  "Er wurde NICHT ausgefuehrt.")
                _LOG.info("Befehl %s (%s) verfallen, Alter %d s - nicht ausgefuehrt.",
                          kennung, b.get("aktion"), alter)
                continue
        try:
            ok, meldung = await befehl_ausfuehren(v, b, cfg, tab)
        except Exception as err:  # noqa: BLE001
            ok, meldung = 0, fehlertext(err)
        antwort_schreiben(kennung, ok, meldung)
        _LOG.info("Befehl %s (%s): ok=%s %s", kennung, b.get("aktion"), ok, meldung)
        geaendert = True
    return geaendert


# ---------------------------------------------------------------------------
# Dienst
# ---------------------------------------------------------------------------
def signal_behandeln(*_):
    global _LAUF
    _LAUF = False
    _LOG.info("Beendigungssignal erhalten - Dienst haelt an.")


async def dienst(einmal: bool = False) -> int:
    cfg = config()
    tab = tabelle()
    _LOG.info("Dienst startet: Matter-Server %s:%s, Steuerung %s.",
              cfg["server_host"], cfg["server_port"],
              "ein" if cfg.get("steuerung_ein") else "aus")

    # Die cache.json wird seit 0.9.10 nicht mehr geschrieben; gelesen hat sie
    # ohnehin nie jemand. Eine vorhandene einmal wegraeumen, damit auf dem
    # Geraet kein Abzug von gestern liegen bleibt und wie ein Zwischenstand
    # aussieht.
    try:
        if DATEI_ALTCACHE.is_file():
            DATEI_ALTCACHE.unlink()
            _LOG.info("Die nicht mehr benutzte cache.json wurde entfernt.")
    except OSError as err:
        _LOG.warning("cache.json liess sich nicht entfernen: %s", err)

    fehler_folge = 0
    # Beide Uhren VOR der Schleife setzen. Sie werden im Erfolgsfall neu
    # gesetzt, aber der Wartezweig nach einem Fehlschlag liest sie auch dann,
    # wenn schon der erste verbinden() gescheitert ist - und das ist der
    # Normalfall, solange kein Matter-Server laeuft.
    letzte_sendung = 0.0
    letzter_herzschlag = 0.0
    while _LAUF:
        cfg = config()
        v = MatterVerbindung(cfg)
        # Der Ereignispfad liest KEINE Dateien mehr. Bis 0.9.9 rief jedes
        # einzelne attribute_updated config() auf, was die Konfiguration von
        # der Platte las, und danach mqtt_zustand(), was die general.json las.
        lauf = {"cfg": cfg, "offen": False}
        try:
            await v.verbinden()
            fehler_folge = 0
            zustand_schreiben(ok=1, fehler="", server=v.server_info)

            def schreiben() -> None:
                # Nur vormerken. Geschrieben und gesendet wird im Takt unten -
                # sonst loest ein bewegter Dimmer im Sekundentakt zwei
                # Dateischreibvorgaenge und den vollstaendigen Bestand aus.
                # Sendetakt 0 stellt das Verhalten bis 0.9.9 wieder her.
                if int(lauf["cfg"].get("sendetakt") or 0) <= 0:
                    abbild_schreiben(v, lauf["cfg"], tab, 1)
                else:
                    lauf["offen"] = True

            aufgabe = asyncio.ensure_future(v.lauschen(schreiben))
            # Erst wenn der Bestand da ist, hat ein Abbild Aussagekraft.
            # Ohne dieses Warten schriebe der Einmal-Lauf eine leere Liste.
            try:
                await asyncio.wait_for(asyncio.shield(v.bestand_da.wait()), timeout=30)
            except asyncio.TimeoutError:
                raise RuntimeError(
                    "Der Matter-Server hat auf start_listening binnen 30 s keinen "
                    "Knotenbestand geliefert.") from None

            # Nach jedem Verbindungsaufbau einmal das Vollbild, damit ein
            # waehrend der Stoerung verpasstes Telegramm nicht dauerhaft fehlt.
            abbild_schreiben(v, lauf["cfg"], tab, 1, voll=True)
            lauf["offen"] = False
            letzte_sendung = time.time()
            letzter_herzschlag = time.time()
            herzschlag_senden(lauf["cfg"], 1)

            # Waehrend gelauscht wird, im Sekundentakt die Warteschlange leeren.
            while _LAUF and not aufgabe.done():
                if not (PDATA / "soll_laufen").is_file():
                    _LOG.info("Der Merker soll_laufen ist weg - Dienst haelt an.")
                    break
                lauf["cfg"] = config()
                c = lauf["cfg"]
                try:
                    if await warteschlange(v, c, tab):
                        lauf["offen"] = True
                except Exception as err:  # noqa: BLE001
                    _LOG.error("Warteschlange: %s", fehlertext(err))
                jetzt = time.time()
                if lauf["offen"] and jetzt - letzte_sendung >= int(c.get("sendetakt") or 0):
                    abbild_schreiben(v, c, tab, 1)
                    lauf["offen"] = False
                    letzte_sendung = jetzt
                hz = int(c.get("herzschlag") or 0)
                if hz and jetzt - letzter_herzschlag >= hz:
                    herzschlag_senden(c, 1)
                    letzter_herzschlag = jetzt
                if einmal:
                    break
                await asyncio.sleep(1)
            # Der Ausgang des Lauschauftrags wird AUSGEWERTET. Bis 0.9.16
            # stand hier nur cancel(): warf lauschen() einen Fehler, endete
            # die innere Schleife, der Fehler wurde verworfen, der
            # except-Zweig lief nicht - keine Protokollzeile, kein
            # Stoerungsabbild, loxone.json behielt ok=1, und die Bremse
            # griff nicht, weil fehler_folge auf 0 stehenblieb.
            if aufgabe.done() and not aufgabe.cancelled():
                lausch_fehler = aufgabe.exception()
                if lausch_fehler is not None:
                    raise lausch_fehler
            aufgabe.cancel()
            if einmal:
                abbild_schreiben(v, lauf["cfg"], tab, 1, voll=True)
                await v.schliessen()
                return 0
        except Exception as err:  # noqa: BLE001
            fehler_folge += 1
            text = fehlertext(err)
            if ist_verbindungsfehler(err):
                melde_gebremst("verbindung", f"Verbindung zum Matter-Server: {text}", 900)
            else:
                # Ein Fehler im eigenen Code wird nicht als Verbindungsstoerung
                # etikettiert und nicht gedrosselt - er bekommt die volle
                # Ablaufverfolgung, sonst sucht ihn jemand im Netz.
                _LOG.exception("Fehler im Dienst (KEINE Verbindungsstoerung): %s", text)
            abbild_stoerung(cfg, text)
            zustand_schreiben(ok=0, fehler=text, fehler_folge=fehler_folge)
            if einmal:
                return 1
        finally:
            await v.schliessen()

        if not _LAUF:
            break
        # Nach mehreren Fehlschlaegen den Abstand vergroessern, statt gegen
        # einen nicht laufenden Server anzurennen.
        pause = min(300, 5 * max(1, fehler_folge))
        if fehler_folge >= 3:
            melde_gebremst("bremse",
                           f"{fehler_folge} Fehlversuche - naechster Versuch erst in {pause} s.", 1800)
        # Auch waehrend der Wartezeit schlaegt das Herz weiter - mit ok=0. Das
        # ist genau die Lage, fuer die es den Herzschlag gibt: der Dienst lebt,
        # der Matter-Server nicht. Ohne das Lebenszeichen waere in Loxone
        # nicht zu unterscheiden, ob die Bruecke steht oder nur nichts passiert.
        hz = int(cfg.get("herzschlag") or 0)
        # An der Uhr, nicht am Schleifenzaehler. Bis 0.9.16 stand hier
        # 'i % hz == 0'; bei pause=5 und hz=60 traf das nur bei i=0, also
        # einmal je Durchgang - der Herzschlag ging in den ersten Minuten
        # einer Stoerung alle 5 s hinaus statt alle 60. Die Hauptschleife
        # macht es seit jeher richtig.
        for _ in range(pause):
            if not _LAUF:
                break
            jetzt = time.time()
            if hz and jetzt - letzter_herzschlag >= hz:
                herzschlag_senden(cfg, 0)
                letzter_herzschlag = jetzt
            await asyncio.sleep(1)

    _LOG.info("Dienst beendet.")
    return 0


# ---------------------------------------------------------------------------
# Selbsttest - beantwortet ohne Loxone und ohne Matter-Server, ob es traegt
# ---------------------------------------------------------------------------
def selbsttest() -> int:
    cfg = config()
    tab = tabelle()
    zeilen = []
    fehler = 0

    v = sys.version_info
    zeilen.append(f"[OK]   Python {v.major}.{v.minor}.{v.micro}")
    try:
        import websockets
        zeilen.append(f"[OK]   Paket websockets geladen, Fassung {websockets.__version__}")
    except Exception as err:  # noqa: BLE001
        fehler += 1
        zeilen.append(f"[FEHL] Paket websockets laesst sich nicht laden: {err}")

    # Architektur - der Matter-Server laeuft nur auf 64 Bit
    bogen = os.uname().machine if hasattr(os, "uname") else "unbekannt"
    if bogen in ("x86_64", "aarch64", "arm64"):
        zeilen.append(f"[OK]   Architektur {bogen} ist 64 Bit")
    else:
        fehler += 1
        zeilen.append(f"[FEHL] Architektur {bogen} ist nicht 64 Bit - der Matter-Server "
                      "unterstuetzt ausdruecklich nur 64-Bit-Systeme")

    # IPv6 - ohne das laeuft Matter gar nicht
    ipv6 = Path("/proc/net/if_inet6").is_file()
    aus = Path("/proc/sys/net/ipv6/conf/all/disable_ipv6")
    abgeschaltet = aus.is_file() and aus.read_text().strip() == "1"
    if ipv6 and not abgeschaltet:
        try:
            zahl_adressen = len(Path("/proc/net/if_inet6").read_text().strip().splitlines())
        except OSError:
            zahl_adressen = 0
        zeilen.append(f"[OK]   IPv6 ist aktiv ({zahl_adressen} Adressen auf diesem Rechner)")
    else:
        fehler += 1
        zeilen.append("[FEHL] IPv6 ist abgeschaltet. Matter beruht auf IPv6-Link-Local-Multicast "
                      "und funktioniert ohne IPv6 NICHT - auch nicht teilweise.")

    zeilen.append(f"[INFO] Cluster-Tabelle: {len(tab.get('cluster', {}))} Cluster, "
                  f"{sum(len(c.get('attribute', {})) for c in tab.get('cluster', {}).values())} Attribute")
    if not tab.get("cluster"):
        fehler += 1
        zeilen.append("[FEHL] Die Cluster-Tabelle ist leer - matter_cluster.json wurde nicht gefunden")

    if LBHOME_GERATEN:
        fehler += 1
        zeilen.append(f"[FEHL] Der LoxBerry-Wurzelordner liess sich nicht bestimmen. Geraten "
                      f"wurde {LBHOME} - dort liegt kein config/plugins und kein webfrontend. "
                      "Der Dienst wuerde in fremde Verzeichnisse schreiben. Das passiert, wenn "
                      "das Plugin als entpacktes Archiv laeuft statt installiert.")
    else:
        zeilen.append(f"[OK]   LoxBerry-Wurzel gefunden und geprueft: {LBHOME}")

    for name, pfad in (("Konfiguration", PCONFIG), ("Daten", PDATA), ("Log", PLOG)):
        schreibbar = pfad.is_dir() and os.access(pfad, os.W_OK)
        zeilen.append(("[OK]   " if schreibbar else "[FEHL] ")
                      + f"Ordner {name} beschreibbar: {pfad}")
        if not schreibbar:
            fehler += 1

    # Ist der Matter-Server ueberhaupt erreichbar? Nur ein TCP-Anklopfen,
    # keine Anmeldung - das gehoert in den laufenden Dienst.
    try:
        with socket.create_connection((cfg["server_host"], int(cfg["server_port"])), timeout=3):
            zeilen.append(f"[OK]   Auf {cfg['server_host']}:{cfg['server_port']} nimmt jemand "
                          "Verbindungen an")
    except OSError as err:
        fehler += 1
        zeilen.append(f"[FEHL] {cfg['server_host']}:{cfg['server_port']} antwortet nicht ({err}). "
                      "Laeuft der Matter-Server?")

    m = mqtt_zustand()
    if not m["gefunden"]:
        fehler += 1
        zeilen.append("[FEHL] In der general.json des LoxBerry ist kein MQTT-Abschnitt zu finden")
    elif m["autostart"]:
        zeilen.append(f"[OK]   MQTT-Gateway auf Autostart, Broker {m['broker']}:{m['brokerport']}, "
                      f"UDP-Eingang {m['udpport']}")
    else:
        fehler += 1
        zeilen.append("[FEHL] Das MQTT-Gateway ist nicht auf Autostart gestellt "
                      "(System, MQTT Gateway). Ohne das kommt am Miniserver nichts an.")

    zeilen.append(f"[INFO] Schreibende Befehle: "
                  f"{'zugelassen' if cfg.get('steuerung_ein') else 'gesperrt'}, "
                  f"Rohdurchreichung: {'ein' if cfg.get('roh_ein') else 'aus'}")

    z = json_lesen(DATEI_ZUSTAND)
    if z:
        zeilen.append(f"[INFO] Letzter Zustand vor {int(time.time()) - int(z.get('ts') or 0)} s, "
                      f"ok={z.get('ok')}, Fehler: {z.get('fehler') or 'keiner'}")
        srv = z.get("server") or {}
        if srv:
            zeilen.append(f"[INFO] Matter-Server: SDK {srv.get('sdk_version')}, "
                          f"Schema {srv.get('schema_version')}, "
                          f"Bluetooth {'ja' if srv.get('bluetooth_enabled') else 'nein'}")
    else:
        zeilen.append("[INFO] Der Dienst hat noch nie verbunden")

    zeilen.append("")
    zeilen.append("Nicht geprueft, weil dafuer ein laufender Matter-Server und echte")
    zeilen.append("Matter-Geraete noetig sind:")
    zeilen.append("  - ob die Anmeldung am Matter-Server gelingt")
    zeilen.append("  - ob die Inbetriebnahme eines Geraets durchlaeuft")
    zeilen.append("  - ob die Cluster-Befehle am Geraet die erwartete Wirkung haben")
    zeilen.append("  - ob das Netz Matter ueberhaupt zulaesst (Multicast, mDNS, VLAN)")
    print("\n".join(zeilen))
    return 1 if fehler else 0


def main() -> int:
    log_einrichten()
    if "--selbsttest" in sys.argv:
        return selbsttest()
    signal.signal(signal.SIGTERM, signal_behandeln)
    signal.signal(signal.SIGINT, signal_behandeln)
    try:
        return asyncio.run(dienst(einmal="--einmal" in sys.argv))
    except KeyboardInterrupt:
        return 0
    except Exception as err:  # noqa: BLE001
        _LOG.error("Dienst abgebrochen: %s", fehlertext(err))
        zustand_schreiben(ok=0, fehler=fehlertext(err))
        return 1


if __name__ == "__main__":
    sys.exit(main())
