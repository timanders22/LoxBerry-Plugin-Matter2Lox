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
if len(SELF.parents) >= 3:
    LBHOME = SELF.parents[2]
else:
    LBHOME = Path(os.environ.get("LBHOMEDIR") or lb_wurzel_ermitteln())

PDATA = LBHOME / "data" / "plugins" / PNAME
PLOG = LBHOME / "log" / "plugins" / PNAME
PCONFIG = LBHOME / "config" / "plugins" / PNAME
PTEMPLATES = LBHOME / "templates" / "plugins" / PNAME

DATEI_CONFIG = PCONFIG / "matter2lox.json"
DATEI_LOXONE = PDATA / "loxone.json"
DATEI_CACHE = PDATA / "cache.json"
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
}

_LAUF = True
_LOG = logging.getLogger("matter2lox")
_LETZTE_MELDUNG: dict[str, float] = {}


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
    c.update(json_lesen(DATEI_CONFIG))
    c["server_port"] = max(1, min(65535, int(c.get("server_port") or 5580)))
    c["wartezeit"] = max(0, min(60, int(c.get("wartezeit") or 8)))
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


def mqtt_senden(paare: dict, praefix: str) -> None:
    z = mqtt_zustand()
    if not z["udpport"]:
        melde_gebremst("mqtt_kein_port",
                       "MQTT: kein UDP-Eingangsport in der general.json gefunden - nichts gesendet.")
        return
    if not z["autostart"]:
        melde_gebremst("mqtt_aus",
                       "MQTT: das Gateway ist nicht auf Autostart gestellt (System, MQTT Gateway). "
                       "Es wird gesendet, aber vermutlich hoert niemand zu.")
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    except OSError as err:
        melde_gebremst("mqtt_socket", f"MQTT: Socket nicht moeglich ({err}).")
        return
    try:
        for k, v in paare.items():
            if v is None or v == "":
                continue    # fehlender Wert: nichts senden statt einer erfundenen 0
            nachricht = f"publish {praefix}/{k} {mqtt_wert_saeubern(v)}".encode("utf-8")
            s.sendto(nachricht, ("127.0.0.1", z["udpport"]))
    except OSError as err:
        melde_gebremst("mqtt_senden", f"MQTT: Senden fehlgeschlagen ({err}).")
    finally:
        s.close()


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
        if art == "server_shutdown":
            _LOG.warning("Der Matter-Server faehrt herunter.")
            return False
        if art == "server_info_updated":
            if isinstance(daten, dict):
                self.server_info.update(daten)
            return False
        return False

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
        return (1, "Sofortabruf eingeplant.")

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
        return (1, "Kopplungsfenster geoeffnet. Manueller Code: "
                   f"{(erg or {}).get('setup_manual_code')}")

    if aktion == "anstupsen":
        erg = await v.senden("ping_node", {"node_id": node_id}, zeit=60)
        return (1, f"Antwort auf den Anstupser: {json.dumps(erg)}")

    # ---- Geraetebefehle ---------------------------------------------------
    try:
        ep = int(b.get("endpunkt", 1))
    except (TypeError, ValueError):
        return (0, "Die Endpunktnummer ist keine Zahl.")

    async def cluster_befehl(cluster_id: int, name: str, nutzlast: dict):
        return await v.senden("device_command", {
            "node_id": node_id, "endpoint_id": ep,
            "cluster_id": cluster_id, "command_name": name, "payload": nutzlast}, zeit=60)

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
def abbild_schreiben(v: MatterVerbindung, cfg: dict, tab: dict, ok: int, fehler: str = "") -> dict:
    geraete: dict[str, dict] = {}
    for i, node_id in enumerate(sorted(v.knoten), start=1):
        geraete[str(i)] = knoten_abbilden(v.knoten[node_id], tab, cfg)

    lox = {
        "ok": ok,
        "ts": int(time.time()),
        "fehler": fehler,
        "anzahl": len(geraete),
        "server": {
            "sdk_version": v.server_info.get("sdk_version"),
            "schema_version": v.server_info.get("schema_version"),
            "bluetooth": 1 if v.server_info.get("bluetooth_enabled") else 0,
            "wlan_gesetzt": 1 if v.server_info.get("wifi_credentials_set") else 0,
            "thread_gesetzt": 1 if v.server_info.get("thread_credentials_set") else 0,
            "fabric_id": v.server_info.get("fabric_id"),
        },
        "geraete": geraete,
    }
    json_schreiben(DATEI_LOXONE, lox)
    json_schreiben(DATEI_CACHE, {"ts": int(time.time()), "knoten": v.knoten})

    if cfg.get("mqtt_ein"):
        praefix = str(cfg.get("mqtt_topic") or "matter").strip("/") or "matter"
        paare: dict[str, object] = {"ok": ok, "geraete": len(geraete)}
        for nr, g in geraete.items():
            basis = f"geraet{nr}"
            paare[f"{basis}/name"] = g["kurz"]
            paare[f"{basis}/erreichbar"] = g["erreichbar"]
            for ep, felder in g["endpunkte"].items():
                for thema, wert in felder.items():
                    paare[f"{basis}/{ep}/{thema}"] = wert
            for pfad, wert in (g.get("roh") or {}).items():
                paare[f"{basis}/roh/{pfad}"] = wert
        mqtt_senden(paare, praefix)
    return geraete


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

    fehler_folge = 0
    while _LAUF:
        cfg = config()
        v = MatterVerbindung(cfg)
        try:
            await v.verbinden()
            fehler_folge = 0
            zustand_schreiben(ok=1, fehler="", server=v.server_info)

            def schreiben() -> None:
                abbild_schreiben(v, config(), tab, 1)

            aufgabe = asyncio.ensure_future(v.lauschen(schreiben))
            # Erst wenn der Bestand da ist, hat ein Abbild Aussagekraft.
            # Ohne dieses Warten schriebe der Einmal-Lauf eine leere Liste.
            try:
                await asyncio.wait_for(asyncio.shield(v.bestand_da.wait()), timeout=30)
            except asyncio.TimeoutError:
                raise RuntimeError(
                    "Der Matter-Server hat auf start_listening binnen 30 s keinen "
                    "Knotenbestand geliefert.") from None

            # Waehrend gelauscht wird, im Sekundentakt die Warteschlange leeren.
            while _LAUF and not aufgabe.done():
                if not (PDATA / "soll_laufen").is_file():
                    _LOG.info("Der Merker soll_laufen ist weg - Dienst haelt an.")
                    break
                try:
                    if await warteschlange(v, config(), tab):
                        abbild_schreiben(v, config(), tab, 1)
                except Exception as err:  # noqa: BLE001
                    _LOG.error("Warteschlange: %s", fehlertext(err))
                if einmal:
                    break
                await asyncio.sleep(1)
            aufgabe.cancel()
            if einmal:
                abbild_schreiben(v, cfg, tab, 1)
                await v.schliessen()
                return 0
        except Exception as err:  # noqa: BLE001
            fehler_folge += 1
            text = fehlertext(err)
            melde_gebremst("verbindung", f"Verbindung zum Matter-Server: {text}", 900)
            abbild_schreiben(v, cfg, tab, 0, text)
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
        for _ in range(pause):
            if not _LAUF:
                break
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
