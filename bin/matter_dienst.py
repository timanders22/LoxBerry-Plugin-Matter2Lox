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
    matter_dienst.py --mqtt-leeren zurueckbehaltene Themen der Linie im Broker
                                   leeren (aus uninstall/uninstall) - unter dem
                                   eingestellten und jedem gemerkten Praefix
    matter_dienst.py --themen      die Themenstaemme, die der Dienst bildet, als
                                   JSON (Pruefzeile im Reiter Test); schreibt nichts

Ein unbekannter Schalter endet mit Rueckgabe 2 und startet nichts (seit
0.9.30, C6; bis dahin lief "--selftest" als zweiter Dienst).
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
import types
import uuid
from logging.handlers import RotatingFileHandler
from pathlib import Path


def lb_wurzel_ermitteln():
    """Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.

    Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
    config/plugins, data/plugins UND config/system/general.json enthaelt
    (Regeln/06). Bis 0.9.26 genuegten config/plugins und webfrontend - ein
    Rest-Baum aus einem Pruefstand ohne general.json galt dann als Wurzel
    (Fall P7 in Pruefung-Matter2Lox-0.9.27; dieselbe Klasse wie der
    Raumklima-Vorfall vom 05.09.2026).
    """
    d = os.path.dirname(os.path.abspath(__file__))
    for _ in range(8):
        if os.path.isdir(os.path.join(d, "config", "plugins")) \
                and os.path.isdir(os.path.join(d, "data", "plugins")) \
                and os.path.isfile(os.path.join(d, "config", "system", "general.json")):
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
# Wurzel und Ordnername werden GELESEN, nicht geraten.
#
# Nicht ueber LoxBerry::System: das leitet den Pluginordner aus dem Aufrufort
# ab und liefert bei einem Start aus postinstall.sh oder aus dem Cron ueberall
# Leerstring - der Dienst werkelte dann gegen /-Pfade und meldete Erfolg.
#
# Bis 0.9.26 stand hier "PNAME = SELF.name", und die Wurzel war
# SELF.parents[2], sobald dort config/plugins und webfrontend lagen - VOR
# $LBHOMEDIR. In WSL gemessen (18.09.2026, Pruefung-Matter2Lox-0.9.27, Faelle
# P1, P2, P6; Bauart H1 aus Bestand-2026-09-18/klasse-H): aus einem
# Pruefarchiv unter <Wurzel>/pruefung/<plugin>/bin hiess der Ordner "bin",
# und schon "--selbsttest" legte in der LAUFENDEN Anlage log/plugins/bin an.
#
# Reihenfolge wie in bin/dienst.sh (Regeln/03, Stufe 1 ist die Umgebung):
#   Wurzel:      $LBHOMEDIR  ->  Aufwaertssuche mit general.json  ->  KEINE
#   Ordnername:  $LBPPLUGINDIR  ->  Ablageort
# Liegt dieses Skript danach nicht unter <Wurzel>/bin/plugins/<ordner>,
# startet main() nichts und legt nichts an (INSTALLIERT).
#
# Bis 0.9.28 folgte nach der Suche noch der Ablageort (SELF.parents[2]),
# sobald dort config/plugins und webfrontend lagen, und zuletzt dieser ganz
# ohne Pruefung. In einem fremden Baum ohne general.json war das doch wieder
# eine Wurzel: in WSL gemessen (25.09.2026, Pruefung-Matter2Lox-0.9.29, Fall
# W3) legte schon "--selbsttest" dort log/plugins/<ordner> an. Ohne Wurzel
# steigt main() jetzt mit einer Meldung aus (Regeln/06).
# ---------------------------------------------------------------------------
SELF = Path(__file__).resolve().parent            # <home>/bin/plugins/<ordner>
PNAME = (os.environ.get("LBPPLUGINDIR") or "").strip("/") or SELF.name

LBHOME = None
umgebung = os.environ.get("LBHOMEDIR") or ""
if umgebung and (Path(umgebung) / "config" / "plugins").is_dir() \
        and (Path(umgebung) / "data" / "plugins").is_dir():
    # resolve(): liegt die Wurzel hinter einem Verweis, vergleicht
    # INSTALLIERT unten zwei physische Pfade (Fall P4).
    LBHOME = Path(umgebung).resolve()
if LBHOME is None:
    gesucht = lb_wurzel_ermitteln()
    if gesucht:
        LBHOME = Path(gesucht).resolve()
LBHOME_GEFUNDEN = LBHOME is not None
if LBHOME is None:
    # Keine Wurzel. Die Pfade unten zeigen dann in den eigenen Ordner, nie
    # an die Laufwerkswurzel - benutzt werden sie nicht: main() steigt vorher
    # aus.
    LBHOME = SELF

# Laeuft dieses Skript wirklich AUS der Installation? Sonst schriebe es in
# eine Anlage unter einem Ordnernamen, den niemand gewollt hat.
INSTALLIERT = LBHOME_GEFUNDEN and SELF == LBHOME / "bin" / "plugins" / PNAME

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
    # 0.9.35 (Nr. 4): nicht mehr "matter-server" - so heisst der Container in
    # der offiziellen Anleitung des Matter-Servers, und ein eigener Server des
    # Anwenders (etwa fuer Home Assistant) trug denselben Namen.
    "container_name": "matter2lox-server",
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
    # Adresse des Border-Routers. Der Dienst benutzt sie nicht - er
    # bekommt das Dataset fertig aus der Konfiguration. Sie steht hier,
    # weil beide Seiten dieselben Vorgaben fuehren muessen; bis 0.9.22
    # fehlte sie und die Pruefzeile im Reiter Test stand dauerhaft rot.
    "thread_br": "",
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
    # Tuer-1 (Verbesserungsbau 30.09.2026): Tueren und Schloesser zusaetzlich
    # unter haus/tuer/<name>/offen und haus/tuer/<name>/verriegelt melden -
    # die Hausvereinbarung fuer Funkwacht und Beschattungswaechter. Ab Werk
    # aus: eine eingerichtete Anlage sendet nach dem Update nichts Neues.
    "tuer_haus": 0,
    # 0.9.35 (Nr. 3): der eigene Container lauscht nur auf 127.0.0.1. Bis
    # 0.9.34 war der Matter-Server im ganzen Netz ohne Anmeldung bedienbar -
    # Token, steuerung_ein und schloss_ein liefen dort ins Leere.
    "server_lokal": 1,
    # 0.9.35: Netzschnittstelle fuer --primary-interface des Containers.
    "primary_interface": "",
    # 0.9.35 (D5): zweites Token NUR fuer lesende Aufrufe des Endpunkts.
    "lesetoken": "",
    # 0.9.35 (Nr. 19): Adresse fuer die Loxone-Vorlagen; leer = LoxBerry-IP.
    "loxone_adresse": "",
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
# Die Erreichbarkeit je Geraet aus dem letzten Abbild - der Herzschlag
# frischt sie auf, weil sie seit 0.9.29 fluechtig geht.
_ERREICHBAR: dict[str, object] = {}


class WachsameRotation(RotatingFileHandler):
    """Umlaufender Protokollhandler, der eine geloeschte Datei neu oeffnet.

    `log/plugins` liegt auf einer Ramdisk (zram). Wird sie geleert, raeumt
    LoxBerrys `log_maint` auf, oder loescht jemand die Datei von Hand, dann
    schreibt ein einmal geoeffneter Handler bis zum Prozessende in einen
    Inode, den es nicht mehr gibt - ohne Fehlermeldung, ohne Datei, ohne
    Hinweis. Am Geraet gemessen (06.09.2026, Python 3.13.5): FileHandler und
    RotatingFileHandler verlieren die Zeile, WatchedFileHandler nicht.

    Die Standardbibliothek hat den WatchedFileHandler, aber nicht zusammen
    mit dem Umlauf. Deshalb hier beides: vor jeder Zeile Geraetenummer und
    Inode vergleichen, bei Abweichung neu oeffnen, nach jedem Umlauf die
    Kennung nachfuehren.
    """

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self._kennung = self._kennung_lesen()

    def _kennung_lesen(self):
        """(Geraetenummer, Inode) der Datei - None, wenn es sie nicht gibt."""
        try:
            s = os.stat(self.baseFilename)
        except OSError:
            return None
        return (s.st_dev, s.st_ino)

    def _nachfassen(self):
        """Neu oeffnen, wenn unter dem offenen Deskriptor eine andere (oder
        gar keine) Datei mehr liegt."""
        if self._kennung_lesen() == self._kennung:
            return
        if self.stream is not None:
            try:
                self.stream.flush()
            finally:
                self.stream.close()
                self.stream = None
        self.stream = self._open()
        self._kennung = self._kennung_lesen()

    def emit(self, record):
        try:
            self._nachfassen()
        except Exception:
            # Ein Fehlschlag beim Nachfassen darf die Zeile nicht kosten:
            # lieber in den alten Deskriptor schreiben als gar nicht.
            pass
        super().emit(record)

    def doRollover(self):
        super().doRollover()
        self._kennung = self._kennung_lesen()


# ---------------------------------------------------------------------------
# Protokollierung - ausschliesslich in die Datei. Das Startskript leitet die
# Ausgabe ohnehin dorthin um; ein zweiter Kanal schriebe jede Zeile doppelt.
# ---------------------------------------------------------------------------
def log_einrichten() -> None:
    PLOG.mkdir(parents=True, exist_ok=True)
    _LOG.setLevel(logging.INFO)
    try:
        h: logging.Handler = WachsameRotation(
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
        # C2 (Durchgang 30.09.2026): die Nebendatei traegt die Prozessnummer.
        # Bis 0.9.30 hiess sie fest <ziel>.tmp; liefen zwei Dienste, griff das
        # os.replace des einen ins Leere, sobald der andere schneller war.
        tmp = pfad.with_suffix(pfad.suffix + ".tmp.%d" % os.getpid())
        with tmp.open("w", encoding="utf-8") as f:
            json.dump(daten, f, ensure_ascii=False, indent=1, default=str)
        if rechte is not None:
            os.chmod(tmp, rechte)
        os.replace(tmp, pfad)
        return True
    except (OSError, TypeError, ValueError) as err:
        _LOG.error("Datei %s konnte nicht geschrieben werden: %s", pfad, err)
        return False


# C9 (Durchgang 30.09.2026): die Haken der Konfiguration. Sie werden an EINER
# Stelle gelesen (haken()), und config() gibt sie als Zahl 0/1 weiter.
#
# Bis 0.9.30 las der Dienst den Wahrheitswert der Rohangabe: eine
# zurueckgespielte Sicherung mit "schloss_ein": "0" (Zeichenkette) ergab
# True - die Oberflaeche zeigte "gesperrt", der Dienst fuehrte Schlossbefehle
# aus (gemessen, Bericht oberflaeche Nr. 3). Jetzt gilt nur 1, True und die
# Zeichenketten "1", "true", "ja", "on" als ein; alles andere als aus - ein
# Schutz faellt geschlossen aus.
HAKEN = ("eigener_container", "mqtt_ein", "roh_ein", "steuerung_ein", "schloss_ein",
         "tuer_haus", "server_lokal")


def haken(cfg: dict, feld: str) -> bool:
    w = cfg.get(feld)
    if isinstance(w, bool):
        return w
    if isinstance(w, (int, float)):
        return w == 1
    if isinstance(w, str):
        return w.strip().lower() in ("1", "true", "ja", "on")
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
    # O6 (Durchgang 30.09.2026): wartezeit 0..60 - dieselbe Grenze wie im
    # Formular und beim Zurueckspielen (mt_zahlgrenzen() in mt_lib.php). Bis
    # 0.9.30 stand hier und in der Sicherungspruefung 200, im Formular 60.
    for feld, klein, gross, vorgabe in (("server_port", 1, 65535, 5580),
                                        ("wartezeit", 0, 60, 8)):
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
    for feld in HAKEN:
        c[feld] = 1 if haken(c, feld) else 0
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
        if typ == "struct0":
            # 0.9.35 (E4): Feld 0 einer Struktur, roh mit Feldnummer als
            # Schluessel (ErrorStateStruct.ErrorStateID); der Feldname als
            # zweite Form wie bei energie_struct.
            if not isinstance(wert, dict):
                return None
            for schluessel in ("0", "errorStateID", "ErrorStateID"):
                if schluessel in wert:
                    try:
                        return int(wert[schluessel])
                    except (TypeError, ValueError):
                        return None
            return None
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
        if typ == "xy":
            # 0.9.35 (E1): ColorControl.CurrentX/CurrentY zaehlen in 1/65536
            # der CIE-Normfarbtafel (Spezifikation: x = CurrentX / 65536).
            return round(zahl / 65536, 4)
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
#
# Bis 0.9.22 traf die Liste die Themen NICHT. Gemessen am 15.09.2026 gegen
# matter_cluster.json: 19 der 23 Eintraege kamen als Thema ueberhaupt nicht
# vor - sie waren gegen eine fruehere Namensgebung geschrieben ("rauch" statt
# "rauch_alarm", "verschlossen" statt "schloss", "ventil" statt
# "ventil_zustand", "programm" statt "waschen_modus"). Von 87 Themen gingen
# 83 fluechtig hinaus; retained waren nur schalter, kontakt, bewegung und
# betriebsart. Nach einem Neustart des Brokers fehlten Loxone damit
# Schlosszustand, Behangposition, Rauchalarm, Wallbox-Zustand und die
# Kennung jedes Geraets. Die Zeile "Steht jedes zurueckbehaltene Thema auch
# in der Themenliste?" im Reiter Test misst das ab jetzt bei jedem Aufruf.
#
# Seit 0.9.29 steht die Erreichbarkeit je Geraet NICHT mehr in der Liste.
# Sie ist das Feld available des python-matter-servers: der setzt es, wenn
# SEINE Verbindung zum Geraet abreisst. Das ist die Aussage eines Dienstes in
# der Kette ueber sich selbst, nicht eine Meldung des Geraets - und stirbt
# dieser Dienst oder der Matter-Server, bliebe eine zurueckbehaltene 1 fuer
# immer stehen. Nach der Entscheidung des Hausherrn vom 19.09.2026
# (Regeln/07, Abschnitt 3) und ihrer Anwendung auf den SAIC-Gateway in
# MGiSmart 1.1.17 geht sie fluechtig hinaus, weiter bei jeder Aenderung, bei
# jedem Vollbild und mit jedem Herzschlag (herzschlag_senden()). Der Altwert
# im Broker wird einmal abgeraeumt (altlast_lage()).
ZUSTANDSTHEMEN = (
    # Geraeteebene: Name und Knotennummer baut der Dienst selbst, sie stehen
    # in keiner Cluster-Tabelle.
    "name", "knoten",
    # Schalten und Leuchten - der Stand einer Lampe ist ein Zustand, die
    # Helligkeit und die Farbe gehoeren dazu.
    "schalter", "helligkeit", "farbton_roh", "farbton_grad", "saettigung",
    "farbtemperatur_mired", "farbtemperatur_kelvin", "farbmodus",
    # 0.9.35 (E1): der Farbort der XY-Lampen
    "farbe_x", "farbe_y",
    # Melder und Kontakte
    "kontakt", "bewegung",
    # Heizung: Betriebsart und die zuletzt gueltigen Sollwerte
    "betriebsart", "soll_heizen", "soll_kuehlen",
    # Schloss und Behang
    "schloss", "position", "betriebszustand",
    # 0.9.35 (E2/E4): Lamelle, Zielposition, Tuerzustand am Schloss und was
    # das Thermostat gerade tut
    "lamelle", "position_ziel", "tuerzustand", "thermostat_betrieb",
    # Stromversorgung: der Ladestand bleibt, die Leistung nicht
    "batterie", "batterie_stufe",
    # Luefter: Stufe und Sollwert
    "luefter_modus", "luefter_soll", "luefter_ist",
    # Luftguete als Stufe; die Messwerte co2, pm25 und voc nicht
    "luftguete", "voc_stufe", "co2_einheit", "pm25_einheit", "voc_einheit",
    # Kennung des Geraets - aendert sich nie
    "hersteller", "produkt", "bezeichnung", "firmware",
    # Zaehlerstaende steigen nur; ein alter Stand ist nicht falsch
    "energie_bezug", "energie_einspeisung",
    # Programmablauf: Zustand und Phase ja, die Restzeit nicht
    "betrieb_zustand", "betrieb_phase",
    "rvc_zustand", "rvc_phase", "rvc_modus", "waschen_modus",
    # 0.9.35 (E4): der Fehlerzustand des Programmablaufs
    "betrieb_fehler", "rvc_fehler",
    # Wallbox: Zustand, Freigabe, Fehler, Belastbarkeit, Ladestand;
    # Dauer und geladene Energie der laufenden Sitzung nicht.
    "evse_zustand", "evse_versorgung", "evse_fehler", "evse_kapazitaet",
    "evse_max_strom", "evse_ladestand",
    # Warmwasser: Betriebsart, Boost und Fuellstand; die Anforderung nicht
    "ww_modus", "ww_boost", "ww_fuellstand",
    # Ventil: Zustand, Stellung, Fehler; Dauer und Restzeit nicht
    "ventil_zustand", "ventil_stellung", "ventil_fehler",
    # Temperaturwahl am Geraet
    "tc_soll", "tc_stufe",
    # Taster: was das Geraet KANN, und die Stellung eines rastenden Schalters.
    # Der Tastendruck selbst ist ein Ereignis und geht nie retained hinaus.
    "taster_stellungen", "taster_mehrfach_max", "taster_stellung",
    "tastensperre",
    # Rauch- und CO-Melder: jede Meldung ist ein Zustand
    "rauch_gesamt", "rauch_alarm", "co_alarm", "rauch_batterie",
    "rauch_stumm", "rauch_hwfehler", "rauch_lebensende",
    "rauch_verschmutzung",
)

# Namen, die 0.9.17 bis 0.9.22 zusaetzlich in der Retain-Liste fuehrten
# (gelesen aus den Archiven, Pruefung-Matter2Lox-0.9.29). Gesendet werden sie
# nicht mehr; die Deinstallation raeumt sie trotzdem mit ab, falls eine alte
# Fassung sie zurueckbehalten hinterlassen hat (mqtt_leeren()).
ALTE_ZUSTANDSTHEMEN = (
    "batterie_niedrig", "besetzt", "co", "fenster", "kindersicherung",
    "luefter_stufe", "programm", "rauch", "regen", "sperre", "tuer", "ventil",
    "verschlossen", "warmwasser", "wasser", "zustand",
)

# Nie retained, gleich was die Liste oben sagt: das Lebenszeichen (retained
# zeigte es immer "lebt"), die Zahl der Geraete und die Erreichbarkeit (eine
# Aussage des Matter-Servers bzw. dieses Dienstes, nicht des Geraets).
NIE_RETAINED = ("online", "ok", "ts", "zaehler", "probe", "geraete", "erreichbar")

# M6 (Durchgang 30.09.2026): die Kennung des Geraets (BasicInformation, liegt
# immer auf Endpunkt 0) geht seit 0.9.30 als geraetN/0/<thema> zurueckbehalten
# hinaus. Bis dahin nannte die Themenliste der Oberflaeche die vier Namen mit
# "zurueckbehalten: ja", der Dienst legte sie aber nur im Abbild ab und sendete
# sie nie (gemessen, Bericht mqtt Nr. 6).
MQTT_INFO = ("hersteller", "produkt", "bezeichnung", "firmware")

# M2/M3 (Durchgang 30.09.2026, Entscheidungen 5 und 8): ein Zustand ohne
# Aussage - null vom Geraet, ein Feld, das der Abruf nicht mehr liefert, ein
# entferntes Geraet - geht EINMAL als "-" zurueckbehalten hinaus, nie als
# leere Nutzlast und nie als stehenbleibender Altwert.
OHNE_AUSSAGE = "-"

# M4 (Durchgang 30.09.2026, Regeln/07): alle 30 Minuten der volle Satz. Der
# UDP-Eingang des Gateways verwirft unter Last Datagramme, und sendto()
# meldet auch dann Erfolg; ein verlorener Zustandswechsel blieb bis 0.9.30
# bis zur naechsten Aenderung falsch (gemessen, Bericht mqtt Nr. 4).
VOLLVERSAND_S = 1800
_VOLLVERSAND: dict = {"zuletzt": 0.0}

# Matter2Lox-b1 (Verbesserungsbau 30.09.2026): wann hat sich jedes Geraet
# zuletzt gemeldet? Gezaehlt wird jede Meldung, die der Matter-Server fuer den
# Knoten zustellt (node_added, node_updated, attribute_updated, node_event) -
# nicht das Abholen des Bestands beim Verbinden, das sagt ueber das Geraet
# nichts. Die Zeiten stehen in loxone.json ("zuletzt", je Knotennummer, auch
# fuer entfernte Knoten) und werden beim ersten Abbild eines Prozesses von
# dort wieder gelesen; ein Update leert sie mit dem Datenordner.
_ZULETZT: dict = {"geladen": False, "knoten": {}}


def zuletzt_merken(node_id) -> None:
    try:
        _ZULETZT["knoten"][int(node_id)] = int(time.time())
    except (TypeError, ValueError):
        pass


def ist_zustand(schluessel: str) -> bool:
    """Traegt dieses Thema einen Zustand (retained) oder einen Messwert?

    Der Vergleich laeuft ueber den letzten Pfadteil, weil die Themen
    'geraet3/1/schalter' heissen. NIE_RETAINED geht vor der Liste.
    """
    letzter = schluessel.rsplit("/", 1)[-1]
    if letzter in NIE_RETAINED:
        return False
    return letzter in ZUSTANDSTHEMEN


# ---------------------------------------------------------------------------
# Beim Broker nachlesen: was steht dort noch zurueckbehalten?
#
# Gesendet wird ueber den UDP-Eingang des Gateways, und dort meldet sendto()
# auch fuer ein verworfenes Datagramm Erfolg (Regeln/07, "Ein Absender merkt
# nichts davon"; an dieser Anlage verwirft der Eingang unter Last bis etwa
# 70 %). Ein Merker "abgeraeumt" darf deshalb nie auf dem Senden stehen,
# sondern erst auf der Antwort des Brokers selbst (Nachtrag 19.09.2026
# ebenda, Beschattungswaechter 0.9.19 und KODI-NG 1.2.7).
#
# Diese Linie hat kein paho (sie braucht allein websockets). Gefragt wird mit
# MQTT 3.1.1 von Hand - CONNECT, SUBSCRIBE mit QoS 0, DISCONNECT -, Bauart
# mqtt_behalten_liste() aus Weissware 0.9.34 (dort nach
# tb_mqtt_behalten_liste() in Spotpreis-Tibber 0.9.19). Zurueckbehaltenes
# liefert der Broker unmittelbar nach dem SUBACK, mit gesetztem
# Retain-Merkmal. Der Zugang kommt aus der general.json (Brokerhost,
# Brokerport, Brokeruser, Brokerpass; Regeln/07 Abschnitt 2); das Kennwort
# steht nur im CONNECT-Paket, nie in einem Protokoll oder einer Ausgabe.
#
# "Nicht zu fragen" sind: kein Brokerport in der general.json (dann wird
# NICHT 1883 angenommen), keine Verbindung, CONNACK ungleich 0, ein SUBACK
# mit einem Rueckgabecode ab 0x80 (etwa durch eine ACL abgelehnt) und keine
# Antwort. Das heisst nie "nichts belegt" - und nie Merker (Muster 11 der
# Nachlese, Beschattungswaechter 0.9.21).
# ---------------------------------------------------------------------------
def mqtt_zugang() -> dict:
    """Host, Port, Benutzer und Kennwort des Brokers aus der general.json.
    port 0 heisst: nicht angegeben oder unbrauchbar."""
    gen = json_lesen(LBHOME / "config" / "system" / "general.json")
    m = gen.get("Mqtt") or gen.get("mqtt") or {}
    if not isinstance(m, dict):
        m = {}

    def hol(gross: str, klein: str) -> str:
        w = m.get(gross, m.get(klein, ""))
        return "" if w is None else str(w)

    host = hol("Brokerhost", "brokerhost").strip()
    if host in ("", "localhost"):
        host = "127.0.0.1"
    try:
        port = int(hol("Brokerport", "brokerport").strip())
    except ValueError:
        port = 0
    if not 0 < port < 65536:
        port = 0
    return {"host": host, "port": port,
            "user": hol("Brokeruser", "brokeruser"),
            "pass": hol("Brokerpass", "brokerpass")}


def mqtt_behalten_liste(filter_liste, passt) -> tuple:
    """Fragt den Broker in EINER Verbindung, was er unter diesen Filtern
    zurueckbehaelt. Filter duerfen + und # tragen.

    Rueckgabe (lage, belegt). lage "ok": CONNACK 0 und jeder Filter im SUBACK
    angenommen - was dann nicht in belegt steht, ist leer. belegt enthaelt
    nur Themen, fuer die passt(thema) wahr ist, mit Retain-Merkmal und nicht
    leerer Nutzlast. "unbekannt": der Broker war nicht zu fragen.
    """
    soll = []
    for f in filter_liste:
        f = str(f)
        if f and f not in soll:
            soll.append(f)
    if not soll:
        return "ok", set()
    z = mqtt_zugang()
    if not z["port"]:
        return "unbekannt", set()

    def zk(text: str) -> bytes:
        b = text.encode("utf-8")
        return len(b).to_bytes(2, "big") + b

    def laenge(n: int) -> bytes:
        o = b""
        while True:
            b = n % 128
            n //= 128
            if n:
                b |= 128
            o += bytes([b])
            if not n:
                return o

    try:
        s = socket.create_connection((z["host"], z["port"]), timeout=2)
    except OSError:
        return "unbekannt", set()
    belegt = set()
    bestaetigt = False
    puffer = b""

    def lies(n: int) -> bytes:
        nonlocal puffer
        while len(puffer) < n:
            d = s.recv(4096)
            if not d:
                raise OSError("Verbindung beendet")
            puffer += d
        aus, puffer = puffer[:n], puffer[n:]
        return aus

    def paket() -> tuple:
        k = lies(1)[0]
        n, mult = 0, 1
        for _ in range(4):
            b = lies(1)[0]
            n += (b & 127) * mult
            mult *= 128
            if not b & 128:
                break
        return k, (lies(n) if n else b"")

    try:
        # 2 s fuer das CONNACK, danach die Restzeit des Fensters je Lesen.
        # Mit festen 1 s (wie in der Vorlage) scheiterte die Rueckfrage in
        # WSL unter Last - vier Pruefstaende nebeneinander - ab und zu schon
        # am CONNACK (Pruefung-Matter2Lox-0.9.29, Eichung Lauf 1, K08/R8).
        # Das ist ein sicherer Ausgang ("unbekannt", kein Merker), fragt
        # aber unnoetig oft nach.
        s.settimeout(2.0)
        flags = 0x02                                   # saubere Sitzung
        nutz = zk("m2lrueck%d" % os.getpid())
        if z["user"]:
            flags |= 0x80
            nutz += zk(z["user"])
            # Ein Kennwort ohne Benutzer laesst MQTT 3.1.1 nicht zu.
            if z["pass"]:
                flags |= 0x40
                nutz += zk(z["pass"])
        kopf = zk("MQTT") + bytes([4, flags]) + (10).to_bytes(2, "big")
        s.sendall(bytes([0x10]) + laenge(len(kopf) + len(nutz)) + kopf + nutz)
        k, r = paket()
        # CONNACK: Art 2, zweites Byte der Rueckgabecode. Ungleich 0 heisst
        # abgewiesen - nicht zu fragen.
        if k >> 4 == 2 and len(r) >= 2 and r[1] == 0:
            sub = (1).to_bytes(2, "big")
            for f in soll:
                sub += zk(f) + b"\x00"
            s.sendall(bytes([0x82]) + laenge(len(sub)) + sub)
            ende = time.monotonic() + 3.0
            while time.monotonic() < ende:
                s.settimeout(max(0.05, ende - time.monotonic()))
                try:
                    k, r = paket()
                except OSError:                        # Zeitablauf: nichts mehr gekommen
                    break
                art = k >> 4
                if art == 9:
                    # Je Filter ein Rueckgabebyte hinter der Paketkennung, in
                    # der Reihenfolge des SUBSCRIBE; ab 0x80 abgelehnt.
                    rc = r[2:]
                    if len(rc) != len(soll) or any(b >= 0x80 for b in rc):
                        break
                    bestaetigt = True
                    # Zurueckbehaltenes kommt unmittelbar nach dem SUBACK.
                    ende = min(ende, time.monotonic() + 1.0)
                elif art == 3 and len(r) >= 2 and bestaetigt:
                    tl = int.from_bytes(r[0:2], "big")
                    t = r[2:2 + tl].decode("utf-8", "replace")
                    versatz = 2 + tl + (2 if (k >> 1) & 3 else 0)
                    if (k & 1) and r[versatz:] and passt(t):
                        belegt.add(t)
            try:
                s.sendall(b"\xe0\x00")
            except OSError:
                pass
    except OSError:
        pass
    finally:
        s.close()
    return ("ok" if bestaetigt else "unbekannt"), belegt


# Frueher zurueckbehaltene Themen, die seit 0.9.29 fluechtig gehen. Der
# Merker im Datenordner traegt Praefix und Themenliste; ein Merker mit
# anderer Kennung gilt nicht (auch keiner, den eine kuenftige Fassung mit
# anderer Liste schriebe). purge_installation raeumt ihn bei jedem Upgrade
# mit ab (Regeln/06) - dann wird genau einmal nachgefragt.
ALTLAST_STAEMME = ("erreichbar",)
DATEI_RETAIN_GERAEUMT = PDATA / "retain_erreichbar_geraeumt"
# Wie oft der Broker hoechstens gefragt wird, solange kein Merker liegt (s),
# und wie bald nach einem Abraeumen nachgelesen wird. Die Rueckfrage haelt
# die Ereignisschleife hoechstens etwa 7 s an (2 s Verbindung, 2 s CONNACK,
# 3 s Abonnement); im Regelfall rund 1 s nach dem SUBACK.
ALTLAST_FRAGE_S = 300
ALTLAST_NACHLESEN_S = 10
_ALTLAST: dict = {"praefix": None, "lage": "", "belegt": set(), "gefragt": 0.0}


def _altlast_passt(praefix: str):
    muster = re.compile(r"^%s/geraet[0-9]+/(%s)$"
                        % (re.escape(praefix), "|".join(ALTLAST_STAEMME)))
    return lambda t: bool(muster.match(t))


def altlast_lage(praefix: str) -> tuple:
    """Stehen unter <praefix>/geraetN/erreichbar noch Altwerte im Broker?

    Rueckgabe (lage, belegt):
      Merker mit passender Kennung      -> ("erledigt", leer); gefragt wird
                                           nicht mehr;
      der Broker bestaetigt: nichts da  -> Merker schreiben, ("erledigt", leer);
      er nennt belegte Themen           -> ("belegt", genau diese), KEIN
                                           Merker - nach dem Abraeumen wird
                                           nachgelesen;
      er ist nicht zu fragen            -> ("unbekannt", leer), kein Merker;
                                           seit 0.9.30 (M7) wird dann
                                           nichts geloescht - bis dahin ging
                                           vor JEDEM Wert die Loeschung
                                           hinaus, mit jedem Herzschlag.
    """
    kennung = "leer-bestaetigt %s: %s" % (praefix, " ".join(ALTLAST_STAEMME))
    try:
        schon = DATEI_RETAIN_GERAEUMT.read_text(encoding="utf-8").strip()
    except OSError:
        schon = ""
    if schon == kennung:
        _ALTLAST.update(praefix=praefix, lage="erledigt", belegt=set())
        return "erledigt", set()
    jetzt = time.time()
    if _ALTLAST["praefix"] == praefix and _ALTLAST["lage"] \
            and jetzt - _ALTLAST["gefragt"] < ALTLAST_FRAGE_S:
        return _ALTLAST["lage"], set(_ALTLAST["belegt"])
    lage, belegt = mqtt_behalten_liste(
        ["%s/+/%s" % (praefix, st) for st in ALTLAST_STAEMME], _altlast_passt(praefix))
    _ALTLAST.update(praefix=praefix, gefragt=jetzt, belegt=set(belegt))
    if lage == "ok" and not belegt:
        try:
            DATEI_RETAIN_GERAEUMT.parent.mkdir(parents=True, exist_ok=True)
            DATEI_RETAIN_GERAEUMT.write_text(kennung + "\n", encoding="utf-8")
            _LOG.info("MQTT: unter %s/ steht keine frueher zurueckbehaltene Erreichbarkeit "
                      "mehr im Broker (vom Broker bestaetigt).", praefix)
        except OSError as err:
            melde_gebremst("retain_merker",
                           f"Der Merker {DATEI_RETAIN_GERAEUMT} liess sich nicht schreiben "
                           f"({err}); der Broker wird spaeter wieder gefragt.")
        _ALTLAST["lage"] = "erledigt"
        return "erledigt", set()
    if lage == "ok":
        _ALTLAST["lage"] = "belegt"
        return "belegt", set(belegt)
    grund = ("in der general.json steht kein Brokerport" if not mqtt_zugang()["port"]
             else "keine Verbindung, keine Antwort, Anmeldung abgewiesen oder Abonnement abgelehnt")
    melde_gebremst("mqtt_rueckfrage",
                   f"MQTT: der Broker liess sich nicht befragen, ob unter {praefix}/ noch "
                   f"frueher zurueckbehaltene Erreichbarkeiten stehen ({grund}). Es wird "
                   "nichts geloescht und kein Merker gesetzt; gefragt wird wieder in "
                   f"{ALTLAST_FRAGE_S} s.")
    _ALTLAST["lage"] = "unbekannt"
    return "unbekannt", set()


def _altlast_vorher_leeren(schluessel: str, praefix: str) -> bool:
    """Geht vor diesem Wert die leere retain-Nutzlast hinaus? Nur nach dem
    zuletzt bekannten Stand - gefragt wird hier nicht (altlast_lage())."""
    if schluessel.rsplit("/", 1)[-1] not in ALTLAST_STAEMME:
        return False
    if _ALTLAST["praefix"] != praefix:
        return False
    if _ALTLAST["lage"] == "unbekannt":
        # M7 (Durchgang 30.09.2026): ohne Antwort des Brokers wird NICHT
        # geleert. Bis 0.9.30 ging dann vor jedem Wert und mit jedem
        # Herzschlag eine leere retain-Nutzlast hinaus, und jeder Eingang in
        # Loxone sah je Minute kurz einen leeren Wert (Bericht mqtt Nr. 7).
        return False
    if _ALTLAST["lage"] == "belegt":
        return f"{praefix}/{schluessel}" in _ALTLAST["belegt"]
    return False


def mqtt_senden(paare: dict, praefix: str, allein_leeren=(), retain_alle: bool = False) -> set:
    """Veroeffentlichen. Rueckgabe: die Schluessel, die WIRKLICH hinausgingen.

    Bis 0.9.16 gab die Funktion nichts zurueck, und der Aufrufer schrieb den
    Merker 'zuletzt gesendet' trotzdem fort - auch wenn gar nichts gesendet
    wurde (kein UDP-Port, kein Socket, Abbruch mitten in der Schleife). Weil
    danach nur noch Aenderungen hinausgehen, fehlten diese Werte dauerhaft.

    allein_leeren: volle Themen, deren Altwert ohne nachfolgenden Wert
    geloescht wird (ein Geraet, das es nicht mehr gibt).

    retain_alle: jedes Thema geht zurueckbehalten hinaus, gleich was
    ist_zustand() sagt (Tuer-1: haus/tuer/<name>/offen und .../verriegelt
    sind Zustaende eines anderen Baums und stehen nicht in ZUSTANDSTHEMEN).
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
    geraeumt = 0
    try:
        for k, v in paare.items():
            if v is None or v == "":
                continue    # fehlender Wert: nichts senden statt einer erfundenen 0
            sauber_k = re.sub(r"[^A-Za-z0-9_/\-]", "_", str(k))
            befehl = "retain" if (retain_alle or ist_zustand(sauber_k)) else "publish"
            nachricht = (f"{befehl} {sauber_praefix}/{sauber_k} "
                         f"{mqtt_wert_saeubern(v)}").encode("utf-8")
            try:
                # Die leere retain-Nutzlast loescht den zurueckbehaltenen
                # Altwert (mqttgateway.pl:281, :311-315, :357; am Geraet am
                # 19.09.2026 belegt, Regeln/07). Sie geht UNMITTELBAR vor dem
                # gueltigen Wert hinaus: der virtuelle Eingang sieht kurz einen
                # leeren Wert und gleich dahinter den richtigen. Ein Leerzeichen
                # hinter dem Thema, sonst nichts - die Form, die das Gateway
                # als Loeschung liest.
                if _altlast_vorher_leeren(sauber_k, sauber_praefix):
                    s.sendto(f"retain {sauber_praefix}/{sauber_k} ".encode("utf-8"),
                             ("127.0.0.1", z["udpport"]))
                    geraeumt += 1
                s.sendto(nachricht, ("127.0.0.1", z["udpport"]))
            except OSError as err:
                # Ein Fehler bei EINEM Thema beendet nicht den ganzen Durchgang;
                # gemeldet wird er, und der Schluessel gilt als nicht gesendet.
                melde_gebremst("mqtt_senden", f"MQTT: Senden fehlgeschlagen ({err}).")
                continue
            gesendet.add(k)
        for thema in allein_leeren:
            try:
                s.sendto(f"retain {thema} ".encode("utf-8"), ("127.0.0.1", z["udpport"]))
                geraeumt += 1
            except OSError as err:
                melde_gebremst("mqtt_senden", f"MQTT: Senden fehlgeschlagen ({err}).")
    finally:
        s.close()
    # Nach einem Abraeumen bald beim Broker nachlesen - der Merker faellt
    # erst, wenn der Broker selbst nichts mehr nennt (altlast_lage()).
    if geraeumt and _ALTLAST["lage"] == "belegt":
        _ALTLAST["gefragt"] = time.time() - ALTLAST_FRAGE_S + ALTLAST_NACHLESEN_S
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
    #
    # 0.9.35 (Nr. 1): der Matter-Server reicht Strukturen ROH weiter, mit der
    # Feldnummer als Schluessel: DeviceTypeStruct = {"0": 21, "1": 1}
    # (matter_server/server/helpers/attributes.py; erst der Client des
    # Projekts setzt die Feldnamen ein, _get_descriptor_key() in
    # common/helpers/util.py). Bis 0.9.34 wurde nur "deviceType" gelesen -
    # typen blieb auf jeder echten Anlage leer, die Typanzeige auch, und
    # haus/tuer/<name>/offen ging nie hinaus, weil kein Kontakt den Typ 21
    # trug. Der Feldname bleibt als zweite Form stehen (Attrappe, Nachfolger).
    typen: dict[str, list] = {}
    for pfad, wert in attribute.items():
        teile = str(pfad).split("/")
        if len(teile) == 3 and teile[1] == "29" and teile[2] == "0" and isinstance(wert, list):
            liste = []
            for e in wert:
                if not isinstance(e, dict):
                    continue
                t = e.get("0", e.get(0, e.get("deviceType")))
                if t is not None:
                    liste.append(str(t))
            typen[teile[0]] = liste

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
        # M1 (Durchgang 30.09.2026): ein eigener Name. Bis 0.9.30 stand hier
        # "roh" - der Name der Rohdurchreichung weiter oben. Ein Farbton
        # ungleich 0 liess abbild_schreiben() danach an roh.items() scheitern,
        # und die Rohdurchreichung ging in keiner Lage hinaus (Bericht mqtt
        # Nr. 1).
        farbton = felder.get("farbton_roh")
        if isinstance(farbton, (int, float)) and 0 <= farbton <= 254:
            felder["farbton_grad"] = int(round(farbton * 360 / 254))
        # 0.9.35 (Nr. C): Matter zaehlt den Luftdruck in 0,1 kPa - das ist
        # genau hPa. luftdruck (kPa) bleibt fuer bestehende Anlagen stehen.
        druck = felder.get("luftdruck")
        if isinstance(druck, (int, float)):
            felder["luftdruck_hpa"] = round(druck * 10, 1)

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
        # M6: die Kennung fuer den Versand unter geraetN/0/<thema>.
        "info": {t: info.get(t) for t in MQTT_INFO},
        "endpunkte": endpunkte,
        "typen": typen,
        "roh": roh,
        "anzahl_attribute": len(attribute),
        "ts": int(time.time()),
    }


# ---------------------------------------------------------------------------
# Verbindung zum Matter-Server
# ---------------------------------------------------------------------------
# 0.9.35 (E3): die Druckarten der Tastenthemen (_taste()).
TASTE_ARTEN = ("kurz", "lang", "doppelt", "dreifach")
# Ereignisthemen, die als IMPULS gemeint sind (Gateway setzt sie nach dem
# Senden auf 0): sie gehen nur bei einem neuen Ereignis hinaus, nie mit dem
# Vollbild. Sonst saehe Loxone nach jedem Verbindungsaufbau und alle 30
# Minuten einen Tastendruck, den es nie gab.
EREIGNIS_IMPULSE = ("taste", "taste_anzahl") + tuple(f"taste_{a}" for a in TASTE_ARTEN)


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
        # 0.9.35 (Nr. 10): laufende Befehle, Sperren je Knoten, Grenze fuer
        # gleichzeitige Befehle und die noch nicht begonnenen Stellbefehle je
        # Gruppe (warteschlange()).
        self.auftraege: set = set()
        self.sperren: dict = {}
        self.gleichzeitig = asyncio.Semaphore(GLEICHZEITIG_MAX)
        self.offen_gruppen: dict = {}

    async def verbinden(self):
        import websockets
        self._schleife = asyncio.get_running_loop()
        # open_timeout begrenzt das Warten auf den Handshake; ohne das haengt
        # ein Fehlversuch bis zum Betriebssystem-Zeitueberlauf.
        #
        # 0.9.35 (Nr. 2): max_size=None. Die Vorgabe der Bibliothek ist 1 MiB
        # je Nachricht; die Antwort auf start_listening traegt den GANZEN
        # Knotenbestand und wird mit einer Bridge oder einigen Dutzend Geraeten
        # groesser. Dann brach die Verbindung mit 1009 ab, und der Dienst
        # verband sich endlos neu. Der Client des Projekts selbst liest ohne
        # Grenze (matter_server/client/connection.py, max_msg_size=0).
        self.ws = await websockets.connect(self.url, open_timeout=10, ping_interval=30,
                                           max_size=None)
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
                zuletzt_merken(daten["node_id"])
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
            zuletzt_merken(node_id)
            return True
        if art == "node_event":
            # Auch ein Ereignis, das nicht ausgewertet wird, ist eine Meldung
            # des Geraets (b1).
            if isinstance(daten, dict):
                zuletzt_merken(daten.get("node_id"))
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
        anzahl = None
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
            # 0.9.35 (E3): MultiPressOngoing (5) und MultiPressComplete (6)
            # tragen die Zahl der Druecke. Bis 0.9.34 war ein Doppelklick
            # von einem einfachen Druck nicht zu unterscheiden. Der Server
            # serialisiert die SDK-Datenklasse mit ihren Feldnamen
            # (camelCase); die anderen Schreibweisen wie oben.
            for schluessel in ("totalNumberOfPressesCounted", "currentNumberOfPressesCounted",
                               "TotalNumberOfPressesCounted", "CurrentNumberOfPressesCounted"):
                if schluessel in nutz:
                    try:
                        anzahl = int(nutz[schluessel])
                    except (TypeError, ValueError):
                        anzahl = None
                    break
        je_knoten = self.ereignisse.setdefault(node_id, {})
        vorher = je_knoten.get(ep) or {}
        neu = {
            "taste": event_id,
            "taste_zaehler": int(vorher.get("taste_zaehler") or 0) + 1,
            "taste_position": stellung,
            "taste_zeit": int(time.time()),
            # Nur bei Mehrfachdruck-Ereignissen; sonst ohne Aussage (None).
            "taste_anzahl": anzahl,
        }
        # 0.9.35 (E3): fertige Themen je Druckart - ein Eingang in Loxone je
        # Art, statt die Ereigniscodes selbst auszuwerten. Der Wert zaehlt die
        # Druecke dieser Art (er aendert sich bei jedem Druck); das Gateway
        # setzt ihn nach dem Senden auf 0 zurueck (mqtt_resetaftersend.cfg),
        # so kommt in Loxone je Druck ein Impuls an.
        #   lang      LongPress (2)
        #   kurz      MultiPressComplete (6) mit 1 Druck - oder, wenn der
        #             Taster keinen Mehrfachdruck kann, ShortRelease (3). Ein
        #             Taster mit Mehrfachdruck meldet beides; gezaehlt wird
        #             dann nur das Ende der Folge, sonst kaeme ein Doppelklick
        #             auch als kurz an.
        #   doppelt   MultiPressComplete mit 2 Druecken
        #   dreifach  MultiPressComplete mit 3 und mehr Druecken
        # Ob der Taster Mehrfachdruck kann, steht in der FeatureMap des
        # Switch-Clusters (Attribut 65532, Bit 4 MSM).
        fm = ((self.knoten.get(node_id) or {}).get("attributes") or {}).get(f"{ep}/59/65532")
        try:
            mehrfach = bool(int(fm) & 0x10)
        except (TypeError, ValueError):
            mehrfach = False
        art = None
        if event_id == 2:
            art = "lang"
        elif event_id == 6:
            n = anzahl if isinstance(anzahl, int) and anzahl > 0 else 1
            art = "kurz" if n == 1 else ("doppelt" if n == 2 else "dreifach")
        elif event_id == 3 and not mehrfach:
            art = "kurz"
        for a in TASTE_ARTEN:
            neu[f"taste_{a}"] = vorher.get(f"taste_{a}")
        if art is not None:
            neu[f"taste_{art}"] = int(vorher.get(f"taste_{art}") or 0) + 1
        je_knoten[ep] = neu
        _LOG.info("Taste an Knoten %s, Endpunkt %s: Ereignis %s, Stellung %s.",
                  node_id, ep, event_id, stellung)
        return True

    async def auftraege_abwarten(self, frist: float = 200.0) -> None:
        if self.auftraege:
            await asyncio.wait(list(self.auftraege), timeout=frist)

    async def auftraege_beenden(self) -> None:
        """Laufende Befehle abbrechen; jeder schreibt seine Antwort selbst."""
        offen = [a for a in self.auftraege if not a.done()]
        for a in offen:
            a.cancel()
        if offen:
            await asyncio.gather(*offen, return_exceptions=True)

    async def schliessen(self) -> None:
        await self.auftraege_beenden()
        if self.ws is not None:
            try:
                await self.ws.close()
            except Exception:  # noqa: BLE001 - beim Schliessen ist jeder Fehler egal
                pass
            self.ws = None



# ---------------------------------------------------------------------------
# Hilfen fuer die Befehle (0.9.35)
# ---------------------------------------------------------------------------
# Matter-Statuscodes (Interaction Model, Abschnitt 8.10), die beim Schreiben
# vorkommen. Unbekannte werden mit ihrer Nummer gemeldet.
MATTER_STATUS = {
    0x01: "FAILURE", 0x7D: "INVALID_SUBSCRIPTION", 0x7E: "UNSUPPORTED_ACCESS",
    0x7F: "UNSUPPORTED_ENDPOINT", 0x80: "INVALID_ACTION", 0x81: "UNSUPPORTED_COMMAND",
    0x85: "INVALID_COMMAND", 0x86: "UNSUPPORTED_ATTRIBUTE", 0x87: "CONSTRAINT_ERROR",
    0x88: "UNSUPPORTED_WRITE", 0x89: "RESOURCE_EXHAUSTED", 0x8B: "NOT_FOUND",
    0x8C: "UNREPORTABLE_ATTRIBUTE", 0x8D: "INVALID_DATA_TYPE", 0x8F: "UNSUPPORTED_READ",
    0x92: "DATA_VERSION_MISMATCH", 0x94: "TIMEOUT", 0x9B: "BUSY", 0xC3: "UNSUPPORTED_CLUSTER",
    0xC6: "NEEDS_TIMED_INTERACTION", 0xCB: "INVALID_IN_STATE",
}


def schreibstatus_fehler(erg) -> str:
    """Leer, wenn jedes geschriebene Attribut mit Status 0 bestaetigt wurde;
    sonst eine Beschreibung der Ablehnung. Ein Ergebnis ohne Statusangabe
    (None, Testknoten, andere Serverfassung) gilt als Erfolg - so verhielt es
    sich bis 0.9.34 immer."""
    if not isinstance(erg, list):
        return ""
    teile = []
    for e in erg:
        if not isinstance(e, dict):
            continue
        st = e.get("Status", e.get("status"))
        if isinstance(st, dict):
            st = st.get("Status", st.get("status"))
        try:
            st = int(st)
        except (TypeError, ValueError):
            continue
        if st != 0:
            teile.append(f"{MATTER_STATUS.get(st, 'Status')} (0x{st:02X})")
    return ", ".join(teile)


def setup_pin_aus_code(code: str):
    """Den Setup-Code (Passcode) aus dem manuellen Kopplungscode lesen.

    Matter-Spezifikation Kapitel 5.1.4, Unterpunkt 1: Ziffer 1 traegt die oberen Bits des
    Diskriminators, Ziffern 2-6 (Block 2) die unteren 14 Bit des Passcodes,
    Ziffern 7-10 (Block 3) die oberen 13 Bit. Die letzte Ziffer ist die
    Pruefziffer (Verhoeff) - sie wird hier geprueft, damit ein Tippfehler
    nicht als falscher Code beim Geraet ankommt. QR-Text (MT:) -> None.
    """
    code = re.sub(r"[\s\-]", "", str(code or ""))
    if not re.match(r"^[0-9]{11}([0-9]{10})?$", code):
        return None
    if not verhoeff_gueltig(code):
        return None
    block2 = int(code[1:6])
    block3 = int(code[6:10])
    pin = (block2 & 0x3FFF) | (block3 << 14)
    return pin if 0 < pin < 100000000 else None


_VERHOEFF_D = (
    (0, 1, 2, 3, 4, 5, 6, 7, 8, 9), (1, 2, 3, 4, 0, 6, 7, 8, 9, 5),
    (2, 3, 4, 0, 1, 7, 8, 9, 5, 6), (3, 4, 0, 1, 2, 8, 9, 5, 6, 7),
    (4, 0, 1, 2, 3, 9, 5, 6, 7, 8), (5, 9, 8, 7, 6, 0, 4, 3, 2, 1),
    (6, 5, 9, 8, 7, 1, 0, 4, 3, 2), (7, 6, 5, 9, 8, 2, 1, 0, 4, 3),
    (8, 7, 6, 5, 9, 3, 2, 1, 0, 4), (9, 8, 7, 6, 5, 4, 3, 2, 1, 0))
_VERHOEFF_P = (
    (0, 1, 2, 3, 4, 5, 6, 7, 8, 9), (1, 5, 7, 6, 2, 8, 3, 0, 9, 4),
    (5, 8, 0, 3, 7, 9, 6, 1, 4, 2), (8, 9, 1, 6, 0, 4, 3, 5, 2, 7),
    (9, 4, 5, 3, 1, 2, 6, 8, 7, 0), (4, 2, 8, 6, 5, 7, 3, 9, 0, 1),
    (2, 7, 9, 3, 8, 0, 6, 4, 1, 5), (7, 0, 4, 6, 9, 1, 3, 2, 5, 8))


def verhoeff_gueltig(ziffern: str) -> bool:
    c = 0
    for i, z in enumerate(reversed(ziffern)):
        c = _VERHOEFF_D[c][_VERHOEFF_P[i % 8][int(z)]]
    return c == 0


def rgb_zu_xy(r: float, g: float, b: float):
    """sRGB (0..1) -> CIE xy. Gammakorrektur und Matrix nach IEC 61966-2-1
    (D65). Schwarz hat keinen Farbort -> None."""
    def lin(c):
        return c / 12.92 if c <= 0.04045 else ((c + 0.055) / 1.055) ** 2.4
    r, g, b = lin(r), lin(g), lin(b)
    x = r * 0.4124 + g * 0.3576 + b * 0.1805
    y = r * 0.2126 + g * 0.7152 + b * 0.0722
    z = r * 0.0193 + g * 0.1192 + b * 0.9505
    summe = x + y + z
    if summe <= 0:
        return None
    return (x / summe, y / summe)


def rgb_zu_hs(r: float, g: float, b: float):
    """RGB (0..1) -> Farbton in Grad und Saettigung 0..1."""
    import colorsys
    h, s_, _v = colorsys.rgb_to_hsv(r, g, b)
    return (h * 360.0, s_)


def loxone_farbe_lesen(wert):
    """Den Wert eines Loxone-Lichtbausteins zerlegen.

    Lumitech: 20bbbtttt  - bbb Helligkeit 0..100 %, tttt Farbtemperatur in
              Kelvin (2700..6500); Rueckgabe ("ct", helligkeit, kelvin)
    RGB:      BBBGGGRRR  - je 0..100 %; Rueckgabe ("rgb", r, g, b)
    So beschreibt Loxone die Ausgaenge des Lichtbausteins fuer Smart Actuator
    RGBW und Lumitech. Ein Wert ausserhalb -> None.
    """
    try:
        n = int(float(str(wert).replace(",", ".")))
    except (TypeError, ValueError):
        return None
    if n < 0:
        return None
    if n >= 200000000:
        rest = n - 200000000
        hell = rest // 10000
        kelvin = rest % 10000
        if hell > 100 or not (kelvin == 0 or 1000 <= kelvin <= 10000):
            return None
        return ("ct", hell, kelvin)
    r = n % 1000
    g = (n // 1000) % 1000
    b = (n // 1000000) % 1000
    if r > 100 or g > 100 or b > 100:
        return None
    return ("rgb", r, g, b)


def farbfaehigkeit(knoten: dict, ep: int) -> dict:
    """Welche Farbwege kann der Endpunkt? Aus der FeatureMap des
    ColorControl-Clusters (Attribut 65532): Bit 0 Farbton/Saettigung, Bit 3
    XY, Bit 4 Farbtemperatur. Ohne FeatureMap: nichts bekannt -> XY, weil der
    Geraetetyp Extended Color Light (269) XY verlangt, Farbton/Saettigung nicht."""
    attr = (knoten or {}).get("attributes") or {}
    fm = attr.get(f"{ep}/768/65532")
    try:
        fm = int(fm)
    except (TypeError, ValueError):
        return {"hs": False, "xy": True, "ct": True, "bekannt": False}
    return {"hs": bool(fm & 1), "xy": bool(fm & 8), "ct": bool(fm & 16), "bekannt": True}


# Im Rohweg nie: Zugriffsrechte, Fabrics und Netzzugang des Geraets. Wer das
# Token und die Steuerungsfreigabe hat, soll damit kein Geraet aus fremden
# Fabrics werfen oder ihm Netz und Rechte nehmen koennen (0.9.35, Nr. 13).
# 31 AccessControl, 48 GeneralCommissioning, 49 NetworkCommissioning,
# 60 AdministratorCommissioning, 62 OperationalCredentials, 63 GroupKeyManagement.
ROHWEG_GESPERRT = (31, 48, 49, 60, 62, 63)
SCHLOSS_CLUSTER = 257

# ---------------------------------------------------------------------------
# Befehle
#
# Die Form der Cluster-Befehle folgt den Datenklassen des Matter-SDK. Die
# Felder werden VOLLSTAENDIG mitgeschickt, auch die mit Vorgabewert: der
# Matter-Server bildet die Nutzlast auf die SDK-Datenklasse ab, und ein
# fehlendes Feld ist dort kein Vorgabewert, sondern ein Fehler.
# ---------------------------------------------------------------------------
def rohweg_pruefen(cluster_id: int, cfg: dict) -> str:
    """0.9.35 (Nr. 13): der Rohweg umging bis 0.9.34 die Schlossfreigabe -
    befehl mit cluster=257 und attribut 1/257/... prueften schloss_ein
    nicht. Leer = erlaubt."""
    if cluster_id in ROHWEG_GESPERRT:
        return (f"Cluster {cluster_id} ist im Rohweg gesperrt: er verwaltet Zugriffsrechte, "
                "Fabrics oder den Netzzugang des Geraets.")
    if cluster_id == SCHLOSS_CLUSTER and not cfg.get("schloss_ein"):
        return ("Schloesser zu schalten ist gesperrt - das gilt auch fuer den Rohweg. "
                "Reiter Einstellungen, Haken Schloesser schalten zulassen.")
    return ""


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
        if not code.upper().startswith("MT:"):
            # Der manuelle Code steht auf Geraeten meist gegliedert
            # (1234-567-8901); Leerzeichen und Striche gehoeren nicht dazu.
            code = re.sub(r"[\s\-]", "", code)
        if not re.match(r"^(MT:[A-Z0-9.\-]{5,60}|[0-9]{11,21})$", code):
            return (0, "Das ist weder ein Matter-QR-Code (beginnt mit MT:) noch ein "
                       "manueller Kopplungscode (11 oder 21 Ziffern).")
        nur_netz = bool(b.get("nur_netz")) or bool(str(b.get("ip") or "").strip())
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
        ip = str(b.get("ip") or "").strip()
        try:
            if ip:
                # 0.9.35 (E5): Anlernen ueber die IP-Adresse - fuer ein Geraet,
                # das schon im Netz ist (Mehrfach-Fabric), wenn die Suche per
                # mDNS nicht durchkommt. commission_on_network braucht den
                # Setup-Code als Zahl; er steckt im manuellen Kopplungscode.
                if not re.match(r"^[0-9A-Fa-f:.]{2,45}$", ip):
                    return (0, "Die IP-Adresse des Geraets sieht nicht wie eine IPv4- oder "
                               "IPv6-Adresse aus.")
                pin = setup_pin_aus_code(code)
                if pin is None:
                    return (0, "Anlernen ueber die IP-Adresse geht nur mit dem manuellen "
                               "Kopplungscode (11 oder 21 Ziffern), nicht mit dem QR-Text.")
                erg = await v.senden("commission_on_network",
                                     {"setup_pin_code": pin, "ip_addr": ip}, zeit=180)
            else:
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
            erg = await v.senden("write_attribute",
                                 {"node_id": node_id, "attribute_path": "0/40/5", "value": neuer},
                                 zeit=60)
            fehl = schreibstatus_fehler(erg)       # 0.9.35 (Nr. 9)
            if fehl:
                raise RuntimeError(f"das Geraet hat abgelehnt: {fehl}")
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
        erg = await v.senden("write_attribute",
                             {"node_id": node_id, "attribute_path": pfad, "value": wert}, zeit=60)
        # 0.9.35 (Nr. 9): write_attribute wirft bei einer Ablehnung durch das
        # Geraet NICHT, es liefert je Attribut einen Status (Liste von
        # AttributeWriteResult, matter_server/server/sdk.py). Bis 0.9.34 galt
        # jede Antwort als Erfolg - ein Sollwert ausserhalb der Grenzen
        # (CONSTRAINT_ERROR) wurde mit OK=1 beantwortet und von der
        # Gleichwert-Bremse als ausgefuehrt gemerkt.
        fehl = schreibstatus_fehler(erg)
        if fehl:
            raise RuntimeError(f"Das Geraet hat das Schreiben von {pfad} abgelehnt: {fehl}")
        return erg

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
            zeit10 = int(zahl("uebergang", 0, 600) or 0)
            # Matter zaehlt 0..254, nicht 0..100.
            stufe = int(round(p * 254 / 100))
            if stufe <= 0:
                # 0.9.35 (Nr. C): 0 % heisst aus. Bis 0.9.34 ging Level 0
                # hinaus - das liegt unter MinLevel (Leuchten: 1), und je nach
                # Geraet blieb die Lampe auf kleinster Stufe an.
                await cluster_befehl(6, "Off", {})
                return (1, "Helligkeit 0 %: Cluster OnOff, Befehl Off gesendet.")
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

        if aktion == "lamelle":
            # 0.9.35 (E2): Lamellenwinkel. Matter zaehlt Hundertstel Prozent,
            # 0 = ganz offen - wie bei der Hoehe.
            p = zahl("wert", 0, 100)
            if p is None:
                return (0, "Die Lamellenstellung muss zwischen 0 und 100 Prozent liegen.")
            await cluster_befehl(258, "GoToTiltPercentage",
                                 {"tiltPercent100ths": int(round(p * 100))})
            return (1, f"Lamellenstellung {p:.0f} % gesendet (0 = ganz offen).")

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

        # ---- Farbe ueber XY und im Format des Loxone-Lichtbausteins -------
        # 0.9.35 (E1): Der Geraetetyp Extended Color Light verlangt XY, nicht
        # Farbton/Saettigung - viele Farblampen lehnten MoveToHue und
        # MoveToHueAndSaturation deshalb ab. Gewaehlt wird nach der FeatureMap.
        if aktion == "farbe_xy":
            x = zahl("x", 0, 1)
            y = zahl("y", 0, 1)
            if x is None or y is None:
                return (0, "x und y muessen zwischen 0 und 1 liegen (CIE-Normfarbtafel).")
            zeit10 = int(zahl("uebergang", 0, 600) or 0)
            await cluster_befehl(768, "MoveToColor", {
                "colorX": min(65279, int(round(x * 65536))),
                "colorY": min(65279, int(round(y * 65536))),
                "transitionTime": zeit10, "optionsMask": 0, "optionsOverride": 0})
            return (1, f"Farbort x={x:.4f} y={y:.4f} gesendet.")

        if aktion == "loxfarbe":
            lf = loxone_farbe_lesen(b.get("wert"))
            if lf is None:
                return (0, "Der Wert ist weder ein Loxone-RGB-Wert (BBBGGGRRR, je 0..100) "
                           "noch ein Lumitech-Wert (20bbbtttt).")
            zeit10 = int(zahl("uebergang", 0, 600) or 0)
            if lf[0] == "ct":
                _art, hell, kelvin = lf
                if hell <= 0:
                    await cluster_befehl(6, "Off", {})
                    return (1, "Lumitech-Wert mit Helligkeit 0: Off gesendet.")
                await cluster_befehl(8, "MoveToLevelWithOnOff", {
                    "level": max(1, int(round(hell * 254 / 100))), "transitionTime": zeit10,
                    "optionsMask": 0, "optionsOverride": 0})
                if kelvin:
                    await cluster_befehl(768, "MoveToColorTemperature", {
                        "colorTemperatureMireds": int(round(1000000 / kelvin)),
                        "transitionTime": zeit10, "optionsMask": 0, "optionsOverride": 0})
                return (1, f"Lumitech: Helligkeit {hell} %, Farbtemperatur {kelvin} K gesendet.")
            _art, r, g, bl = lf
            hell = max(r, g, bl)
            if hell <= 0:
                await cluster_befehl(6, "Off", {})
                return (1, "RGB-Wert 0: Off gesendet.")
            await cluster_befehl(8, "MoveToLevelWithOnOff", {
                "level": max(1, int(round(hell * 254 / 100))), "transitionTime": zeit10,
                "optionsMask": 0, "optionsOverride": 0})
            # Die Farbe ohne Helligkeit: auf den hellsten Kanal normiert.
            rn, gn, bn = r / hell, g / hell, bl / hell
            kann = farbfaehigkeit(v.knoten.get(node_id), ep)
            if kann["xy"] or not kann["hs"]:
                xy = rgb_zu_xy(rn, gn, bn)
                await cluster_befehl(768, "MoveToColor", {
                    "colorX": min(65279, int(round(xy[0] * 65536))),
                    "colorY": min(65279, int(round(xy[1] * 65536))),
                    "transitionTime": zeit10, "optionsMask": 0, "optionsOverride": 0})
                return (1, f"RGB {r}/{g}/{bl} %: Helligkeit {hell} %, Farbort "
                           f"x={xy[0]:.4f} y={xy[1]:.4f} gesendet.")
            grad, saet = rgb_zu_hs(rn, gn, bn)
            await cluster_befehl(768, "MoveToHueAndSaturation", {
                "hue": int(round(grad * 254 / 360)) % 255,
                "saturation": int(round(saet * 254)),
                "transitionTime": zeit10, "optionsMask": 0, "optionsOverride": 0})
            return (1, f"RGB {r}/{g}/{bl} %: Helligkeit {hell} %, Farbton {grad:.0f} Grad, "
                       f"Saettigung {saet * 100:.0f} % gesendet.")

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
            rohweg_fehler = rohweg_pruefen(int(pfad.split("/")[1]), cfg)
            if rohweg_fehler:
                return (0, rohweg_fehler)
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
            rohweg_fehler = rohweg_pruefen(cluster_id, cfg)
            if rohweg_fehler:
                return (0, rohweg_fehler)
            nutzlast = b.get("nutzlast")
            if nutzlast is None or (isinstance(nutzlast, str) and not nutzlast.strip()):
                nutzlast = {}
            elif isinstance(nutzlast, str):
                try:
                    nutzlast = json.loads(nutzlast)
                except ValueError:
                    return (0, "Die Nutzlast ist kein gueltiges JSON.")
            if not isinstance(nutzlast, dict):
                # 0.9.35 (Nr. C): bis 0.9.34 wurde [1,2] oder 5 still zu {} -
                # der Befehl ging ohne Argumente hinaus und meldete OK=1.
                return (0, "Die Nutzlast muss ein JSON-Objekt sein, zum Beispiel {\"level\": 100}.")
            # LockDoor/UnlockDoor verlangen auch im Rohweg einen timed invoke.
            timed = 7000 if cluster_id == SCHLOSS_CLUSTER else None
            erg = await cluster_befehl(cluster_id, name, nutzlast, timed_ms=timed)
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


def paare_bilden(geraete: dict, cfg: dict, ok: int) -> dict:
    """Die Themenpaare eines Abbilds, relativ zum Praefix.

    EINE Stelle fuer den Versand (abbild_schreiben()) und fuer die
    Selbstauskunft --themen, gegen die der Reiter Test die Themenliste der
    Oberflaeche haelt (M6, Durchgang 30.09.2026).

    Ein Zustand ohne Aussage (None oder leer) wird hier zu OHNE_AUSSAGE (M2);
    Messwerte ohne Wert bleiben None und gehen gar nicht hinaus.
    """
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
        info = g.get("info") or {}
        for thema in MQTT_INFO:
            paare[f"{basis}/0/{thema}"] = info.get(thema)
        for ep, felder in g["endpunkte"].items():
            for thema, wert in felder.items():
                paare[f"{basis}/{ep}/{thema}"] = wert
        for pfad, wert in (g.get("roh") or {}).items():
            paare[f"{basis}/roh/{pfad}"] = wert
    for k, w in list(paare.items()):
        if (w is None or w == "") and ist_zustand(k):
            paare[k] = OHNE_AUSSAGE
    return paare


def vollversand_faellig(jetzt: float | None = None) -> bool:
    """M4: ist der volle Satz wieder dran? Auch nach einem Uhrsprung zurueck."""
    jetzt = time.time() if jetzt is None else jetzt
    zuletzt = _VOLLVERSAND["zuletzt"]
    return jetzt - zuletzt >= VOLLVERSAND_S or jetzt < zuletzt


def abbild_schreiben(v: MatterVerbindung, cfg: dict, tab: dict, ok: int,
                     fehler: str = "", voll: bool = False) -> dict:
    if voll:
        _VOLLVERSAND["zuletzt"] = time.time()
    karte = nummern_zuordnen(v.knoten.keys())
    if not _ZULETZT["geladen"]:
        # b1: die Zeiten des vorigen Prozesses uebernehmen; frischere aus
        # diesem Prozess gehen vor.
        _ZULETZT["geladen"] = True
        alt = json_lesen(DATEI_LOXONE).get("zuletzt")
        for k, w in (alt.items() if isinstance(alt, dict) else ()):
            try:
                _ZULETZT["knoten"].setdefault(int(k), int(w))
            except (TypeError, ValueError):
                continue
    geraete: dict[str, dict] = {}
    for node_id in sorted(v.knoten):
        nr = karte.get(node_id)
        if nr is None:
            continue
        g = knoten_abbilden(v.knoten[node_id], tab, cfg)
        g["nummer"] = nr
        # b1: Zeit der letzten Meldung (None = seit Beginn der Aufzeichnung keine).
        g["zuletzt"] = _ZULETZT["knoten"].get(int(node_id))
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
        # b1: je Knotennummer die Zeit der letzten Meldung, auch fuer Knoten,
        # die es nicht mehr gibt (Reiter Einstellungen, "zuletzt gesehen").
        "zuletzt": {str(k): w for k, w in sorted(_ZULETZT["knoten"].items())},
    }
    json_schreiben(DATEI_LOXONE, lox)

    if cfg.get("mqtt_ein"):
        praefix = mqtt_praefix(cfg)
        paare = paare_bilden(geraete, cfg, ok)
        # Nur senden, was sich geaendert hat. Bis 0.9.9 ging bei JEDEM einzelnen
        # attribute_updated der vollstaendige Bestand aller Geraete erneut
        # hinaus - ein bewegter Dimmer erzeugte damit hunderte Telegramme je
        # Sekunde. 'voll' erzwingt das Vollbild; das geschieht nach jedem
        # Verbindungsaufbau, nach einem Praefixwechsel und seit 0.9.30 (M4)
        # alle 30 Minuten (vollversand_faellig()).
        if voll:
            # 0.9.35 (E3): Impulsthemen nicht wiederholen (EREIGNIS_IMPULSE).
            senden = {k: w for k, w in paare.items()
                      if k.rsplit("/", 1)[-1] not in EREIGNIS_IMPULSE
                      or _LETZTE_PAARE.get(k) != str(w)}
        else:
            senden = {k: w for k, w in paare.items() if _LETZTE_PAARE.get(k) != str(w)}
        # M2/M3 (Durchgang 30.09.2026): ein zurueckbehaltener Zustand, der im
        # neuen Abbild fehlt - ein Attribut, das nicht mehr kommt, ein
        # entferntes Geraet (node_removed), ein Geraet, das mqtt_nur abwaehlt -,
        # geht EINMAL als "-" hinaus und wird danach vergessen. Bis 0.9.30
        # blieb der Altwert im Broker stehen und galt nach jedem Neustart von
        # Broker, Gateway oder Miniserver als aktueller Stand (Bericht mqtt
        # Nr. 2 und 3).
        weg = [k for k in _LETZTE_PAARE
               if k not in paare and ist_zustand(k) and _LETZTE_PAARE[k] != OHNE_AUSSAGE]
        for k in weg:
            senden[k] = OHNE_AUSSAGE
        vergessen = [k for k in _LETZTE_PAARE
                     if k not in paare and (not ist_zustand(k) or _LETZTE_PAARE[k] == OHNE_AUSSAGE)]
        for k in vergessen:
            _LETZTE_PAARE.pop(k, None)
        # Altwerte der Erreichbarkeit (bis 0.9.28 retained). Steht einer
        # noch im Broker, geht sein Thema jetzt hinaus - Loeschung und Wert
        # hintereinander, auch wenn sich der Wert nicht geaendert hat. Das
        # Thema eines Geraets, das es nicht mehr gibt, wird allein geloescht.
        lage, belegt = altlast_lage(praefix)
        allein = []
        if lage == "belegt":
            for thema in sorted(belegt):
                k = thema[len(praefix) + 1:]
                if k in paare:
                    senden[k] = paare[k]
                else:
                    allein.append(thema)
        # Fuer den Herzschlag: die Erreichbarkeit geht fluechtig und wird
        # deshalb in seinem Takt aufgefrischt (herzschlag_senden()).
        _ERREICHBAR.clear()
        _ERREICHBAR.update({k: w for k, w in paare.items()
                            if k.rsplit("/", 1)[-1] == "erreichbar"})
        if senden or allein:
            # Fortgeschrieben wird nur, was sendto() angenommen hat. Das ist
            # KEIN Beleg, dass es ankam: der UDP-Eingang des Gateways verwirft
            # unter Last Datagramme, und sendto() meldet trotzdem Erfolg
            # (Regeln/07). Bis 0.9.30 sagte dieser Kommentar mehr zu, als
            # sendto() belegen kann; die Luecke schliesst der Vollversand alle
            # 30 Minuten (M4). Bis 0.9.16 stand der Merker sogar vor dem Senden.
            for k in mqtt_senden(senden, praefix, allein_leeren=allein):
                if k in paare:
                    _LETZTE_PAARE[k] = str(paare[k])
                else:
                    _LETZTE_PAARE.pop(k, None)     # einmal "-", dann vergessen
        else:
            _LETZTE_PAARE.update({k: str(w) for k, w in paare.items()})
        gateway_dateien_pflegen(cfg, paare)
    else:
        gateway_dateien_pflegen(cfg)
    # Tuer-1: unter haus/tuer/ (eigene Merker, eigener Baum) - oder, ist die
    # Einstellung aus, das Abraeumen frueher gesendeter Themen.
    haus_senden(geraete, cfg, voll)
    return geraete


# ---------------------------------------------------------------------------
# 0.9.35 (D1): die Dateien, die das MQTT-Gateway von LoxBerry in JEDEM
# Plugin-Konfigordner liest (sbin/mqttgateway.pl, read_extplugin_config()):
#
#   mqtt_subscriptions.cfg   je Zeile ein Abonnement - das Gateway abonniert
#                            die Themengruppe damit selbst. Bis 0.9.34 musste
#                            sie unter Gateway V1 von Hand eingetragen werden;
#                            das war die haeufigste Fehlerursache.
#   mqtt_resetaftersend.cfg  je Zeile ein Name in Gateway-Schreibweise (/ und
#                            % werden _); das Gateway schickt nach dem Wert
#                            eine 0 hinterher. Fuer die Tastenthemen: sonst
#                            saehe ein Eingang in Loxone den zweiten gleichen
#                            Tastendruck nicht.
#
# Geschrieben nur, wenn sich der Inhalt aendert; mit mqtt_ein=0 entfernt. Der
# Konfigordner wird bei jedem Upgrade geloescht - der Dienst legt beide beim
# naechsten Abbild wieder an.
# ---------------------------------------------------------------------------
DATEI_GW_ABOS = PCONFIG / "mqtt_subscriptions.cfg"
DATEI_GW_RESET = PCONFIG / "mqtt_resetaftersend.cfg"
GW_RESET_THEMEN = EREIGNIS_IMPULSE


def gateway_name(thema: str) -> str:
    return re.sub(r"[/%]", "_", thema)


def gateway_dateien_pflegen(cfg: dict, paare=None) -> None:
    if not cfg.get("mqtt_ein"):
        for d in (DATEI_GW_ABOS, DATEI_GW_RESET):
            if d.is_file():
                _datei_weg(d)
        return
    praefix = mqtt_praefix(cfg)
    abos = [f"{praefix}/#"]
    if cfg.get("tuer_haus"):
        abos.append(f"{HAUS_WURZEL}/#")
    # Je Endpunkt mit einem Taster (Switch-Cluster: taster_* aus den
    # Attributen, taste* aus den Ereignissen) ALLE Impulsthemen - nicht nur
    # die schon einmal gesendeten: die Ereignisse leben nur im Speicher und
    # sind nach einem Wiederverbinden fort; die Datei darf dann nicht fehlen.
    taster = {k.rsplit("/", 1)[0] for k in (paare or {})
              if k.count("/") == 2 and k.rsplit("/", 1)[-1].startswith(("taster_", "taste"))}
    reset = sorted({gateway_name(f"{praefix}/{basis}/{t}") for basis in taster
                    for t in GW_RESET_THEMEN})
    for datei, zeilen in ((DATEI_GW_ABOS, abos), (DATEI_GW_RESET, reset)):
        inhalt = "".join(z + "\n" for z in zeilen)
        try:
            alt = datei.read_text(encoding="utf-8") if datei.is_file() else None
        except OSError:
            alt = None
        if not zeilen:
            if alt is not None:
                _datei_weg(datei)
            continue
        if alt == inhalt:
            continue
        try:
            datei.parent.mkdir(parents=True, exist_ok=True)
            tmp = datei.with_name(datei.name + ".tmp.%d" % os.getpid())
            tmp.write_text(inhalt, encoding="utf-8")
            os.replace(tmp, datei)
            _LOG.info("MQTT-Gateway: %s geschrieben (%d Zeilen).", datei.name, len(zeilen))
        except OSError as err:
            melde_gebremst("gw_datei_" + datei.name,
                           f"MQTT-Gateway: {datei} liess sich nicht schreiben ({err}).")


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


def herzschlag_paare(ok: int) -> dict:
    """Die Themen des Lebenszeichens - EINE Stelle fuer den Versand und fuer
    die Selbstauskunft --themen."""
    return {"online": 1, "ok": int(ok), "ts": int(time.time())}


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
    paare = herzschlag_paare(ok)
    # Die Erreichbarkeit geht seit 0.9.29 fluechtig. Bis dahin hatte Loxone
    # sie nach einem Neustart von Broker, Gateway oder Miniserver sofort aus
    # dem Broker; jetzt bringt sie der Herzschlag spaetestens nach einem Takt
    # wieder. Nur bei bestehender Verbindung (ok=1): waehrend einer Stoerung
    # weiss der Dienst nichts Neues ueber die Geraete.
    if ok:
        paare.update(_ERREICHBAR)
    mqtt_senden(paare, praefix)


def zustand_schreiben(**felder) -> None:
    z = json_lesen(DATEI_ZUSTAND)
    z.update(felder)
    z["ts"] = int(time.time())
    z["pid"] = os.getpid()
    json_schreiben(DATEI_ZUSTAND, z)


# ---------------------------------------------------------------------------
# Warteschlange
# ---------------------------------------------------------------------------
def antwort_schreiben(kennung: str, ok: int, meldung: str, ist=None) -> None:
    ORDNER_ANTWORTEN.mkdir(parents=True, exist_ok=True)
    daten = {"ok": int(ok), "meldung": str(meldung), "ts": int(time.time())}
    if ist is not None:
        # 0.9.35 (E4): Ist-Wert nach dem Befehl, sofern er sich bestimmen laesst.
        daten["ist"] = ist
    json_schreiben(ORDNER_ANTWORTEN / f"{kennung}.json", daten)
    grenze = time.time() - 900
    for alt in ORDNER_ANTWORTEN.glob("*.json"):
        try:
            if alt.stat().st_mtime < grenze:
                alt.unlink()
        except OSError:
            pass


# ---------------------------------------------------------------------------
# 0.9.35 (Nr. 10 und 11): die Warteschlange blockiert die Hauptschleife nicht
# mehr, und die Reihenfolge stimmt.
#
# Bis 0.9.34 lief jeder Befehl INNERHALB der Hauptschleife (await), einer
# nach dem anderen: ein Geraet, das nicht antwortet, hielt alle anderen bis zu
# 60 s auf, ein Anlernen bis zu 180 s - und in der Zeit schrieb der Dienst
# keinen Herzschlag. Ab 3 x Takt (Vorgabe 180 s) startete der Waechter ihn
# dann als "haengend" neu, im schlimmsten Fall mitten im Anlernen. Dazu kam
# die Reihenfolge: die Dateinamen waren Zufall, sortiert wurde nach Namen -
# aus "helligkeit 30" und gleich danach "80" konnte 30 werden.
#
# Jetzt:
#   - der Endpunkt benennt die Datei nach der Zeit (mt_befehl_absetzen());
#   - der Dienst BEANSPRUCHT sie atomar (X.json -> X.inarbeit). Gelingt dem
#     Endpunkt nach Ablauf seiner Wartezeit das Loeschen von X.json, hatte der
#     Dienst sie sicher nicht - dann ist sie sicher NICHT ausgefuehrt;
#   - jeder Befehl laeuft als eigene Aufgabe; je Knoten eine Sperre (in
#     Eingangsreihenfolge, asyncio.Lock ist fair), Verwaltung (anlernen,
#     wlan, thread, Bestand holen) hat eine eigene; hoechstens GLEICHZEITIG_MAX
#     Befehle zugleich beim Matter-Server;
#   - von mehreren Stellbefehlen derselben Gruppe fuer dasselbe Geraet, die
#     noch nicht begonnen haben, wird nur der juengste ausgefuehrt; die
#     aelteren bekommen ok=2 ("ueberholt"). umschalten, rollo_auf/_zu/_stopp,
#     identify und die Rohwege werden nie zusammengefasst.
# ---------------------------------------------------------------------------
STELL_GRUPPEN = {
    "ein": "schalten", "aus": "schalten",
    "helligkeit": "helligkeit", "farbtemperatur": "farbtemperatur",
    "farbe": "farbe", "farbe_xy": "farbe_xy", "loxfarbe": "loxfarbe",
    "farbton": "farbton", "saettigung": "saettigung",
    "rollo": "rollo", "lamelle": "lamelle",
    "soll_heizen": "soll_heizen", "soll_kuehlen": "soll_kuehlen",
    "betriebsart": "betriebsart", "luefter": "luefter",
}
GLEICHZEITIG_MAX = 4
VERWALTUNG = ("anlernen", "wlan", "thread")


def befehl_gruppe(b: dict):
    g = STELL_GRUPPEN.get(str(b.get("aktion") or ""))
    if g is None:
        return None
    return (str(b.get("knoten")), str(b.get("endpunkt", 1)), g)


def befehl_sperrschluessel(b: dict) -> str:
    k = b.get("knoten")
    if b.get("aktion") in VERWALTUNG or k in (None, "", 0, "0"):
        return "verwaltung"
    return f"knoten:{k}"


# E4: welcher Ist-Wert gehoert zu welchem Befehl, und welcher Wert wird
# erwartet (None: keiner bestimmt, etwa beim Umschalten).
IST_QUELLE = {
    "ein": ("6/0", (1,)), "aus": ("6/0", (0,)), "umschalten": ("6/0", None),
    "sperren": ("257/0", (1,)), "entsperren": ("257/0", (2, 3)),
}
IST_WARTEN_S = 3.0


async def ist_ermitteln(v: "MatterVerbindung", b: dict):
    """Den Ist-Wert nach einem bestaetigten Befehl aus dem Knotenbestand.
    Gewartet wird hoechstens IST_WARTEN_S auf den erwarteten Wert; danach
    gilt, was dasteht - auch wenn sich ein Schloss noch bewegt."""
    q = IST_QUELLE.get(str(b.get("aktion") or ""))
    if q is None:
        return None
    try:
        node_id = int(b.get("knoten"))
        ep = int(b.get("endpunkt", 1))
    except (TypeError, ValueError):
        return None
    pfad = f"{ep}/{q[0]}"

    def lesen():
        w = ((v.knoten.get(node_id) or {}).get("attributes") or {}).get(pfad)
        if isinstance(w, bool):
            return int(w)
        try:
            return int(w)
        except (TypeError, ValueError):
            return None

    ende = time.monotonic() + (1.0 if q[1] is None else IST_WARTEN_S)
    while time.monotonic() < ende:
        w = lesen()
        if q[1] is not None and w in q[1]:
            return w
        await asyncio.sleep(0.2)
    return lesen()


def _datei_weg(pfad: Path) -> None:
    try:
        pfad.unlink()
    except OSError:
        pass


async def _auftrag(v: "MatterVerbindung", kennung: str, arbeit: Path, b: dict, cfg: dict,
                   tab: dict, eintrag, fertig) -> None:
    sperre = v.sperren.setdefault(befehl_sperrschluessel(b), asyncio.Lock())
    ok, meldung, ist = 0, "", None
    abgebrochen = False
    try:
        async with sperre:
            gruppe = befehl_gruppe(b)
            if eintrag is not None and eintrag.get("ueberholt"):
                ok, meldung = 2, ("Nicht ausgefuehrt: ein neuerer Befehl derselben Art fuer "
                                  "dasselbe Geraet hat ihn ueberholt.")
            else:
                if gruppe is not None and v.offen_gruppen.get(gruppe) is eintrag:
                    v.offen_gruppen.pop(gruppe, None)
                async with v.gleichzeitig:
                    ok, meldung = await befehl_ausfuehren(v, b, cfg, tab)
                    if ok == 1:
                        ist = await ist_ermitteln(v, b)
    except asyncio.CancelledError:
        abgebrochen = True
        ok, meldung = 0, ("Abgebrochen: der Dienst hat die Verbindung zum Matter-Server neu "
                          "aufgebaut oder endet. Ob der Befehl schon wirkte, ist offen.")
    except Exception as err:  # noqa: BLE001
        ok, meldung = 0, fehlertext(err)
    antwort_schreiben(kennung, ok, meldung, ist)
    _datei_weg(arbeit)
    _LOG.info("Befehl %s (%s): ok=%s %s", kennung, b.get("aktion"), ok, meldung)
    if fertig is not None:
        try:
            fertig()
        except Exception:  # noqa: BLE001
            pass
    if abgebrochen:
        raise asyncio.CancelledError()


async def warteschlange(v: "MatterVerbindung", cfg: dict, tab: dict, fertig=None) -> int:
    """Vorliegende Befehle beanspruchen und als Aufgaben starten. Rueckgabe:
    Zahl der angenommenen Befehle. fertig() wird nach jedem beendeten Befehl
    gerufen (Abbild neu schreiben)."""
    ORDNER_BEFEHLE.mkdir(parents=True, exist_ok=True)
    angenommen = []
    for datei in sorted(ORDNER_BEFEHLE.glob("*.json")):
        kennung = datei.stem
        arbeit = datei.with_suffix(".inarbeit")
        try:
            os.rename(datei, arbeit)
        except OSError:
            # Der Endpunkt hat sie eben zurueckgezogen (Wartezeit abgelaufen).
            continue
        b = json_lesen(arbeit)
        if not b:
            antwort_schreiben(kennung, 0, "Befehlsdatei war leer oder unlesbar.")
            _datei_weg(arbeit)
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
                _datei_weg(arbeit)
                continue
        angenommen.append((kennung, arbeit, b))
    for kennung, arbeit, b in angenommen:
        gruppe = befehl_gruppe(b)
        eintrag = None
        if gruppe is not None:
            vorher = v.offen_gruppen.get(gruppe)
            if vorher is not None:
                vorher["ueberholt"] = True
            eintrag = {"ueberholt": False, "kennung": kennung}
            v.offen_gruppen[gruppe] = eintrag
        aufgabe = asyncio.ensure_future(_auftrag(v, kennung, arbeit, b, cfg, tab, eintrag, fertig))
        v.auftraege.add(aufgabe)
        aufgabe.add_done_callback(v.auftraege.discard)
    return len(angenommen)


# Was beim Dienststart schon laenger in der Warteschlange liegt, hat keinen
# Absender mehr: mt_befehl_absetzen() wartet hoechstens 200 s auf die Antwort
# und raeumt die Datei danach selbst weg, und ohne laufenden Dienst reiht es
# gar nicht erst ein. Liegen geblieben ist sie also, weil der Absender nicht
# zu Ende kam. Die Verfallsgrenze in warteschlange() nimmt Einstellungen und
# Verwaltungsauftraege bewusst aus (wlan, thread, anlernen, entfernen, name);
# bis 0.9.28 fuehrte der Dienst einen solchen Auftrag deshalb beim naechsten
# Verbindungsaufbau aus, auch Tage spaeter - ein "entfernen" nimmt dabei ein
# Geraet aus der Fabric (in WSL gemessen 25.09.2026, Pruefung-Matter2Lox-
# 0.9.29, Fall B1). Beim Start wird deshalb JEDER Auftrag verworfen, der
# aelter als diese Grenze ist, mit Antwort und Protokollzeile. Bauart
# BatterieBMS 0.9.25 und ZendureSolarFlow 0.9.26 (dort ebenfalls 60 s).
BEFEHL_START_VERFALL_S = 60


def befehle_beim_start_verwerfen() -> int:
    """Rueckgabe: Zahl der verworfenen Auftraege."""
    if not ORDNER_BEFEHLE.is_dir():
        return 0
    verworfen = 0
    jetzt = time.time()
    # 0.9.35: ein Befehl, den ein frueherer Dienst schon beansprucht hatte und
    # nicht zu Ende brachte (Absturz, kill -9). Er wird nicht wiederholt - ob
    # er wirkte, weiss niemand; wiederholt wuerde womoeglich eine Tuer zweimal.
    for datei in sorted(ORDNER_BEFEHLE.glob("*.inarbeit")):
        b = json_lesen(datei)
        _datei_weg(datei)
        antwort_schreiben(datei.stem, 0, "Abgebrochen: der vorige Dienst endete, waehrend "
                                         "dieser Befehl lief. Ob er wirkte, ist offen; er wird "
                                         "nicht wiederholt.")
        _LOG.info("Befehl %s (%s) war beim Ende des vorigen Dienstes in Arbeit - nicht wiederholt.",
                  datei.stem, b.get("aktion"))
        verworfen += 1
    for datei in sorted(ORDNER_BEFEHLE.glob("*.json")):
        b = json_lesen(datei)
        ts = b.get("ts")
        if not isinstance(ts, (int, float)):
            try:
                ts = datei.stat().st_mtime
            except OSError:
                continue
        alter = jetzt - float(ts)
        if alter <= BEFEHL_START_VERFALL_S:
            continue
        try:
            datei.unlink()
        except OSError as err:
            _LOG.warning("Veralteter Auftrag %s liess sich nicht entfernen: %s", datei.name, err)
            continue
        antwort_schreiben(datei.stem, 0,
                          f"Verworfen beim Dienststart: der Auftrag lag {int(alter)} s in der "
                          f"Warteschlange (Grenze {BEFEHL_START_VERFALL_S} s). "
                          "Er wurde NICHT ausgefuehrt.")
        _LOG.info("Auftrag %s (%s) beim Dienststart verworfen, Alter %d s - nicht ausgefuehrt.",
                  datei.stem, b.get("aktion"), alter)
        verworfen += 1
    return verworfen


# ---------------------------------------------------------------------------
# Dienst
# ---------------------------------------------------------------------------
def signal_behandeln(*_):
    global _LAUF
    _LAUF = False
    _LOG.info("Beendigungssignal erhalten - Dienst haelt an.")


def soll_laufen_da() -> bool:
    return (PDATA / "soll_laufen").is_file()


async def dienst(einmal: bool = False) -> int:
    # C1 (Durchgang 30.09.2026): fehlt der Merker soll_laufen, endet der Dienst
    # ganz. Bis 0.9.30 verliess das "break" nur die innere Schleife; die
    # aeussere verband nach 5 s neu, alle ~5 s, mit Vollbild und Protokollzeile
    # "Dienst haelt an" (gemessen, Bericht code C1). Deshalb global.
    global _LAUF
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

    befehle_beim_start_verwerfen()

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
        # C4: die Adresse des Matter-Servers wurde geaendert - ohne Pause neu
        # verbinden.
        neu_verbinden = False
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

            # M5: das Praefix merken (und frueher benutzte abraeumen), bevor
            # das Vollbild hinausgeht.
            praefix_pflegen(lauf["cfg"])
            # Nach jedem Verbindungsaufbau einmal das Vollbild, damit ein
            # waehrend der Stoerung verpasstes Telegramm nicht dauerhaft fehlt.
            abbild_schreiben(v, lauf["cfg"], tab, 1, voll=True)
            lauf["offen"] = False
            letzte_sendung = time.time()
            letzter_herzschlag = time.time()
            herzschlag_senden(lauf["cfg"], 1)

            # Waehrend gelauscht wird, im Sekundentakt die Warteschlange leeren.
            while _LAUF and not aufgabe.done():
                if not einmal and not soll_laufen_da():
                    _LOG.info("Der Merker soll_laufen ist weg - der Dienst endet.")
                    _LAUF = False
                    break
                lauf["cfg"] = config()
                c = lauf["cfg"]
                # C4 (Durchgang 30.09.2026): Adresse und Port bei jedem Lesen
                # der Konfiguration mit denen der offenen Verbindung vergleichen.
                # Bis 0.9.30 griff eine neue Adresse erst, wenn die alte
                # Verbindung abriss - unter Umstaenden tagelang nicht (gemessen,
                # Bericht code C4).
                if (c["server_host"], int(c["server_port"])) \
                        != (cfg["server_host"], int(cfg["server_port"])):
                    _LOG.info("Die Adresse des Matter-Servers wurde geaendert (%s:%s -> %s:%s) - "
                              "der Dienst verbindet neu.", cfg["server_host"], cfg["server_port"],
                              c["server_host"], c["server_port"])
                    neu_verbinden = True
                    break
                try:
                    await warteschlange(v, c, tab,
                                        fertig=lambda: lauf.__setitem__("offen", True))
                except Exception as err:  # noqa: BLE001
                    _LOG.error("Warteschlange: %s", fehlertext(err))
                jetzt = time.time()
                if praefix_pflegen(c):
                    # M5: neues Praefix - dort sofort der volle Satz samt
                    # Lebenszeichen; das alte hat praefix_pflegen() abgeraeumt.
                    abbild_schreiben(v, c, tab, 1, voll=True)
                    herzschlag_senden(c, 1)
                    lauf["offen"] = False
                    letzte_sendung = jetzt
                    letzter_herzschlag = jetzt
                elif vollversand_faellig(jetzt):
                    # M4: der volle Satz alle 30 Minuten.
                    abbild_schreiben(v, c, tab, 1, voll=True)
                    lauf["offen"] = False
                    letzte_sendung = jetzt
                if lauf["offen"] and jetzt - letzte_sendung >= int(c.get("sendetakt") or 0):
                    abbild_schreiben(v, c, tab, 1)
                    lauf["offen"] = False
                    letzte_sendung = jetzt
                hz = int(c.get("herzschlag") or 0)
                if hz and jetzt - letzter_herzschlag >= hz:
                    herzschlag_senden(c, 1)
                    letzter_herzschlag = jetzt
                if einmal:
                    await v.auftraege_abwarten()
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
        if neu_verbinden:
            continue
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
            # C1: auch in der Wartezeit - sonst liefe ein Dienst ohne
            # Matter-Server nach dem Entfernen des Merkers weiter.
            if not soll_laufen_da():
                _LOG.info("Der Merker soll_laufen ist weg - der Dienst endet.")
                _LAUF = False
                break
            jetzt = time.time()
            if hz and jetzt - letzter_herzschlag >= hz:
                herzschlag_senden(cfg, 0)
                letzter_herzschlag = jetzt
            await asyncio.sleep(1)

    _LOG.info("Dienst beendet.")
    return 0


# ---------------------------------------------------------------------------
# Zurueckbehaltene Themen leeren - fuer die Deinstallation
#
# Regeln/07, Abschnitt 3: die Deinstallation raeumt die retained Themen der
# Linie ab. Bis 0.9.28 tat uninstall/uninstall das nicht und sagte nur, im
# Broker koennten Werte stehen bleiben: Zustaende, Name, Knotennummer und
# Erreichbarkeit jedes Geraets wurden nach dem Entfernen bei jedem Neustart
# von Broker oder Gateway wieder an den Miniserver ausgeliefert.
#
# Welche Themen: gefragt wird der Broker nach <praefix>/#; geleert wird davon
# nur, was diese Linie je zurueckbehalten gesendet hat - geraetN/name,
# geraetN/knoten, geraetN/erreichbar und geraetN/<endpunkt>/<thema> mit einem
# Thema aus ZUSTANDSTHEMEN oder ALTE_ZUSTANDSTHEMEN (die Vereinigung ueber die
# Archive 0.9.17 bis 0.9.28). Anderes unter demselben Praefix bleibt stehen.
# Ist der Broker nicht zu fragen, wird die Liste aus den Geraetenummern und
# dem letzten Abbild gebildet.
#
# Geloescht wird ueber den UDP-Eingang des Gateways ("retain <thema> " mit
# leerer Nutzlast); nach jeder Runde wird nachgelesen, hoechstens
# LEEREN_RUNDEN Runden. Bauart mqtt_leeren() aus Weissware 0.9.34.
# ---------------------------------------------------------------------------
LEEREN_RUNDEN = 3
LEEREN_PAUSE_S = 1.0


def _leer_passt(praefix: str):
    themen = "|".join(re.escape(t) for t in tuple(ZUSTANDSTHEMEN) + ALTE_ZUSTANDSTHEMEN)
    muster = re.compile(r"^%s/geraet[0-9]+/((name|knoten|erreichbar)|[0-9]+/(%s))$"
                        % (re.escape(praefix), themen))
    return lambda t: bool(muster.match(t))


def mqtt_leer_themen_aus_bestand(praefix: str) -> list:
    """Die Themen aus Geraetenummern und letztem Abbild - nur, wenn der
    Broker nicht zu fragen ist."""
    nummern = set()
    for w in (json_lesen(DATEI_NUMMERN).get("nummern") or {}).values():
        try:
            if int(w) > 0:
                nummern.add(int(w))
        except (TypeError, ValueError):
            continue
    geraete = json_lesen(DATEI_LOXONE).get("geraete") or {}
    if not isinstance(geraete, dict):
        geraete = {}
    passt = _leer_passt(praefix)
    themen = []
    for nr in sorted(nummern | {int(n) for n in geraete if str(n).isdigit()}):
        for st in ("name", "knoten", "erreichbar"):
            themen.append(f"{praefix}/geraet{nr}/{st}")
        for st in MQTT_INFO:
            themen.append(f"{praefix}/geraet{nr}/0/{st}")
        g = geraete.get(str(nr)) or {}
        for ep, felder in sorted((g.get("endpunkte") or {}).items()):
            if isinstance(felder, dict):
                for thema in sorted(felder):
                    t = f"{praefix}/geraet{nr}/{ep}/{thema}"
                    if passt(t):
                        themen.append(t)
    return themen


# M5 (Durchgang 30.09.2026): jedes je benutzte Praefix wird gemerkt, NEBEN
# dem Konfigordner (config/plugins/<ordner>.mqtt_praefixe.json; der Ordner
# selbst wird bei jedem Upgrade geloescht). Bis 0.9.30 blieben nach einem
# Praefixwechsel alle Zustaende unter dem alten Praefix im Broker stehen, und
# die Deinstallation raeumte nur das eingestellte ab (gemessen, Bericht mqtt
# Nr. 5: 126 Themen blieben). Die Oberflaeche merkt beim Speichern altes und
# neues Praefix, der Dienst beim Start und bei jedem Wechsel. Vergessen wird
# ein Praefix erst, wenn der Broker bestaetigt, dass darunter nichts mehr
# zurueckbehalten steht.
DATEI_PRAEFIXE = PCONFIG.parent / (PNAME + ".mqtt_praefixe.json")
_PRAEFIX: dict = {"aktiv": None}


def praefixe_gemerkt() -> list:
    aus = []
    for p in json_lesen(DATEI_PRAEFIXE).get("praefixe") or []:
        p = str(p)
        if re.match(r"^[A-Za-z0-9_/\-]{1,64}$", p) and p not in aus:
            aus.append(p)
    return aus


def _praefixe_schreiben(liste: list) -> bool:
    return json_schreiben(DATEI_PRAEFIXE, {
        "_hinweis": "MQTT-Praefixe, unter denen dieses Plugin je gesendet hat. Die "
                    "Deinstallation raeumt unter jedem davon die zurueckbehaltenen "
                    "Themen der Linie ab.",
        "praefixe": liste}, 0o600)


def praefix_merken(praefix: str) -> bool:
    liste = praefixe_gemerkt()
    if praefix in liste:
        return True
    return _praefixe_schreiben(liste + [praefix])


def praefix_vergessen(praefix: str) -> bool:
    liste = praefixe_gemerkt()
    if praefix not in liste:
        return True
    return _praefixe_schreiben([p for p in liste if p != praefix])


def praefix_pflegen(cfg: dict) -> bool:
    """Rueckgabe True: das Praefix hat gewechselt, das naechste Abbild muss
    voll hinausgehen. Beim ersten Aufruf je Prozess (Dienststart) werden die
    gemerkten, nicht mehr gueltigen Praefixe abgeraeumt."""
    if not cfg.get("mqtt_ein"):
        return False
    neu = mqtt_praefix(cfg)
    alt = _PRAEFIX["aktiv"]
    if alt == neu:
        return False
    _PRAEFIX["aktiv"] = neu
    if not praefix_merken(neu):
        melde_gebremst("praefix_merken", f"MQTT: das Praefix {neu} liess sich nicht in "
                       f"{DATEI_PRAEFIXE} merken.")
    if alt is not None:
        _LOG.info("MQTT: Themenpraefix %s -> %s. Unter %s/ geht der volle Satz hinaus, "
                  "unter %s/ wird abgeraeumt.", alt, neu, neu, alt)
        _LETZTE_PAARE.clear()
    abraeumen = ([alt] if alt is not None else []) \
        + [p for p in praefixe_gemerkt() if p not in (alt, neu)]
    for p in abraeumen:
        _rc, bestaetigt = praefix_leeren(p, melden=lambda t: _LOG.info("%s", t))
        if bestaetigt:
            praefix_vergessen(p)
    return alt is not None


def mqtt_leeren(runden: int = LEEREN_RUNDEN, pause: float = LEEREN_PAUSE_S) -> int:
    """Fuer die Deinstallation: das eingestellte Praefix und jedes gemerkte
    (M5). Rueckgabe wie praefix_leeren(), der schlechteste Ausgang zaehlt."""
    aktuell = mqtt_praefix(config())
    liste = [aktuell] + [p for p in praefixe_gemerkt() if p != aktuell]
    rc = 0
    for p in liste:
        r, _bestaetigt = praefix_leeren(p, runden, pause)
        rc = max(rc, r)
    # Tuer-1: die gemerkten Themen unter haus/tuer/ (still, wenn keine).
    return max(rc, haus_leeren(runden=runden, pause=pause))


def praefix_leeren(praefix: str, runden: int = LEEREN_RUNDEN, pause: float = LEEREN_PAUSE_S,
                   melden=print) -> tuple:
    """Rueckgabe (rc, bestaetigt). rc 0 geleert (vom Broker bestaetigt) oder
    nicht nachpruefbar gesendet, 1 es steht noch etwas bzw. das Senden
    scheiterte, 2 nicht moeglich. bestaetigt: der Broker hat nachgelesen und
    nennt nichts mehr. Schreibt kein Protokoll (ausser ueber 'melden') und
    legt nichts an."""
    return themen_leeren([f"{praefix}/#"], _leer_passt(praefix),
                         lambda: mqtt_leer_themen_aus_bestand(praefix), f"{praefix}/",
                         runden, pause, melden)


def themen_leeren(filter_liste: list, passt, ersatz, was: str,
                  runden: int = LEEREN_RUNDEN, pause: float = LEEREN_PAUSE_S,
                  melden=print) -> tuple:
    """Zurueckbehaltene Themen abraeumen und beim Broker nachlesen.

    filter_liste: die Abonnements der Rueckfrage; passt(thema): gehoert das
    Thema dieser Linie; ersatz(): die Themenliste, falls der Broker nicht zu
    fragen ist; was: der Baum fuer die Saetze ("matter/", "haus/tuer/").
    Bis zum Verbesserungsbau 30.09.2026 stand das allein in praefix_leeren();
    seitdem nutzen es auch geraet_leeren() (b1) und haus_leeren() (Tuer-1).
    Rueckgabe (rc, bestaetigt) wie praefix_leeren()."""
    z = mqtt_zustand()
    if not z["udpport"]:
        melden("<INFO> MQTT: in der general.json steht kein UDP-Eingangsport des Gateways - "
               f"zurueckbehaltene Themen unter {was} wurden nicht geleert.")
        return 2, False
    lage, belegt = mqtt_behalten_liste(filter_liste, passt)
    nachgelesen = lage == "ok"
    offen = sorted(belegt) if nachgelesen else list(ersatz())
    if nachgelesen and not offen:
        melden(f"<OK> MQTT: der Broker bestaetigt: unter {was} steht kein zurueckbehaltenes "
               "Thema dieses Plugins - nichts zu leeren.")
        return 0, True
    zu_leeren = len(offen)
    gesendet = 0
    runde = 0
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    except OSError as err:
        melden(f"<WARNING> MQTT: kein Socket ({err}) - zurueckbehaltene Themen unter {was} "
               "wurden nicht geleert.")
        return 2, False
    try:
        while offen and runde < max(1, int(runden)):
            if runde:
                time.sleep(pause)
            runde += 1
            for t in offen:
                s.sendto(f"retain {t} ".encode("utf-8"), ("127.0.0.1", z["udpport"]))
                gesendet += 1
            if nachgelesen:
                time.sleep(0.3)             # dem Gateway Zeit bis zum Broker lassen
                lage, belegt = mqtt_behalten_liste(filter_liste, passt)
                if lage == "ok":
                    offen = [t for t in offen if t in belegt]
                else:
                    nachgelesen = False
    except OSError as err:
        melden(f"<WARNING> MQTT: Senden an den UDP-Eingang {z['udpport']} gescheitert ({err}) - "
               f"zurueckbehaltene Themen unter {was} stehen womoeglich noch im Broker.")
        return 1, False
    finally:
        s.close()
    melden(f"<INFO> MQTT: {zu_leeren} Themen unter {was} mit leerer Nutzlast an den "
           f"UDP-Eingang {z['udpport']} des Gateways gesendet ({runde} Runde(n), "
           f"{gesendet} Datagramme).")
    if not z["autostart"]:
        melden("<INFO> MQTT: das Gateway steht nicht auf Autostart - vermutlich hat niemand "
               "zugehoert.")
    if nachgelesen and not offen:
        melden(f"<OK> MQTT: der Broker bestaetigt: keines der {zu_leeren} Themen unter {was} "
               "steht mehr zurueckbehalten.")
        return 0, True
    if nachgelesen:
        melden(f"<WARNING> MQTT: {len(offen)} Themen stehen noch zurueckbehalten im Broker "
               f"({', '.join(offen[:5])}{', ...' if len(offen) > 5 else ''}). "
               "Von Hand: mosquitto_pub -r -n -t <thema>")
        return 1, False
    melden("<INFO> MQTT: der Broker liess sich nicht befragen - nicht nachgelesen. Der "
           "UDP-Eingang verwirft unter Last Datagramme; was dort noch steht, zeigt "
           f"mosquitto_sub -v --retained-only -t '{filter_liste[0]}'.")
    return 0, False


# ---------------------------------------------------------------------------
# Matter2Lox-b1 (Verbesserungsbau 30.09.2026): die zurueckbehaltenen Themen
# EINES Geraets abraeumen - Knopf "Themen dieses Geraets abraeumen" im Reiter
# Einstellungen (matter_dienst.py --geraet-leeren N). Unter dem eingestellten
# und jedem gemerkten Praefix nur <praefix>/geraetN/..., und nur Themen, die
# die Linie zurueckbehalten sendet (_leer_passt); dazu die gemerkten Themen
# dieses Geraets unter haus/tuer/ (Tuer-1). Nachgelesen wie bei der
# Deinstallation. Sendet der Dienst das Geraet weiter, stehen seine Zustaende
# nach dem naechsten Vollversand (spaetestens 30 min) wieder da - der Knopf ist
# fuer entfernte und fuer abgewaehlte Geraete gedacht.
# ---------------------------------------------------------------------------
def geraet_leeren(nr: int, runden: int = LEEREN_RUNDEN, pause: float = LEEREN_PAUSE_S,
                  melden=print) -> int:
    aktuell = mqtt_praefix(config())
    liste = [aktuell] + [p for p in praefixe_gemerkt() if p != aktuell]
    rc = 0
    for p in liste:
        kopf = f"{p}/geraet{int(nr)}/"
        grund = _leer_passt(p)
        r, _bestaetigt = themen_leeren(
            [f"{kopf}#"],
            lambda t, g=grund, k=kopf: t.startswith(k) and g(t),
            lambda p=p, k=kopf: [t for t in mqtt_leer_themen_aus_bestand(p) if t.startswith(k)],
            kopf, runden, pause, melden)
        rc = max(rc, r)
    rc_haus = haus_leeren(nur_geraet=int(nr), runden=runden, pause=pause, melden=melden)
    # 0.9.35 (D2): der feste Name unter haus/tuer/ wird freigegeben - beim
    # naechsten Senden gilt der aktuelle Geraetename.
    namen = haus_namen_lesen()
    if str(int(nr)) in namen:
        namen.pop(str(int(nr)), None)
        if haus_namen_schreiben(namen):
            melden(f"<INFO> MQTT: der feste Name von Geraet {int(nr)} unter {HAUS_WURZEL}/ "
                   "wurde freigegeben.")
    return max(rc, rc_haus)


# ---------------------------------------------------------------------------
# Tuer-1 (Verbesserungsbau 30.09.2026, Anbieterseite): Tueren und Schloesser
# zusaetzlich unter dem Haus-Thema. Das ist die Hausvereinbarung fuer
# Funkwacht und Beschattungswaechter (Welle 3/4):
#
#     haus/tuer/<name>/offen       1 offen, 0 zu        retained
#     haus/tuer/<name>/verriegelt  1 verriegelt, 0 nicht retained
#     "-" retained                 keine Aussage (Wert null, Geraet entfernt;
#                                  Entscheidung 5)
#
# <name> ist die Geraetebezeichnung (NodeLabel, sonst Produkt), gesaeubert:
# klein, ae/oe/ue/ss fuer Umlaute, jedes andere Zeichen ausser a-z 0-9 _ -
# wird "_", hoechstens 40 Zeichen. So bleibt <name> EINE Themenebene, und der
# Name, den das MQTT-Gateway daraus baut (/ wird _), ist eindeutig:
# "Haustür" -> haus/tuer/haustuer/offen -> haus_tuer_haustuer_offen.
# Traegt ein Geraet mehrere Kontakte oder Schloesser, heisst jedes
# <name>_<endpunkt>; tragen zwei Geraete denselben Namen, bekommt das spaetere
# (hoehere Geraetenummer) _<geraetenummer> angehaengt.
#
# offen kommt aus BooleanState (kontakt) - nur an einem Endpunkt vom
# Geraetetyp Contact Sensor (21), nicht vom Wasser- oder Regenmelder. Nach der
# Matter-Spezifikation heisst StateValue true "Kontakt geschlossen";
# offen = 1 - kontakt. verriegelt kommt aus DoorLock.LockState: 1 (Locked)
# -> 1, 0/2/3 (nicht ganz verriegelt, entriegelt, entklinkt) -> 0.
#
# Voraussetzung: tuer_haus UND mqtt_ein. Ist tuer_haus aus, werden die je
# gesendeten Themen abgeraeumt (gemerkt NEBEN dem Konfigordner,
# config/plugins/<ordner>.haus_themen.json - so ueberstehen sie ein Update);
# vergessen wird ein Thema erst, wenn der Broker bestaetigt, dass es leer ist.
# Die Deinstallation raeumt ueber --mqtt-leeren ab.
# ---------------------------------------------------------------------------
HAUS_WURZEL = "haus/tuer"
DATEI_HAUS = PCONFIG.parent / (PNAME + ".haus_themen.json")
HAUS_KONTAKTTYP = "21"
HAUS_FRAGE_S = 300
_HAUS: dict = {"letzte": {}, "versucht": 0.0, "konflikte": [], "konflikt_gefragt": 0.0}
_HAUS_THEMA = re.compile(r"^haus/tuer/[a-z0-9_\-]{1,60}/(offen|verriegelt)$")
_UMLAUTE = (("ä", "ae"), ("ö", "oe"), ("ü", "ue"), ("ß", "ss"),
            ("Ä", "ae"), ("Ö", "oe"), ("Ü", "ue"))


def haus_name(text) -> str:
    t = str(text or "")
    for a, b in _UMLAUTE:
        t = t.replace(a, b)
    t = re.sub(r"[^a-z0-9_\-]+", "_", t.lower()).strip("_")
    return t[:40].strip("_")


def haus_paare(geraete: dict, feste_namen=None) -> tuple:
    """Rueckgabe (paare, herkunft): paare relativ zu haus/tuer
    ("<name>/offen": 0|1|"-"), herkunft je Schluessel die Geraetenummer.

    feste_namen (0.9.35, D2): {geraetenummer: name}. Steht ein Geraet darin,
    gilt dieser Name, gleich wie es inzwischen heisst; ein neues Geraet wird
    eingetragen. Bis 0.9.34 wanderte das Thema mit jeder Umbenennung - und
    bekam ein Geraet einen schon vergebenen Namen, rutschte das mit der
    hoeheren Nummer still auf <name>_<nr>. Funkwacht und Beschattungswaechter
    verloren die Tuer dann ohne Hinweis. Neu vergeben wird ein Name erst nach
    "Themen dieses Geraets abraeumen" (geraet_leeren())."""
    paare: dict = {}
    herkunft: dict = {}
    namen: dict = {}
    fest = feste_namen if isinstance(feste_namen, dict) else {}
    for nr in sorted(geraete, key=lambda n: int(n) if str(n).isdigit() else 0):
        if str(nr) in fest:
            namen[fest[str(nr)]] = nr
    for nr in sorted(geraete, key=lambda n: int(n) if str(n).isdigit() else 0):
        g = geraete[nr]
        endpunkte = g.get("endpunkte") or {}
        typen = g.get("typen") or {}
        kontakte = [ep for ep in sorted(endpunkte) if "kontakt" in (endpunkte[ep] or {})
                    and HAUS_KONTAKTTYP in [str(x) for x in (typen.get(ep) or [])]]
        schloesser = [ep for ep in sorted(endpunkte) if "schloss" in (endpunkte[ep] or {})]
        if not kontakte and not schloesser:
            # Kein Tuergeraet: es belegt keinen Namen unter haus/tuer/.
            continue
        if str(nr) in fest:
            name = fest[str(nr)]
        else:
            name = haus_name(g.get("name")) or f"geraet{nr}"
            if namen.get(name, nr) != nr:
                name = f"{name}_{nr}"
            namen[name] = nr
            if feste_namen is not None:
                feste_namen[str(nr)] = name
        for art, eps, feld in (("offen", kontakte, "kontakt"), ("verriegelt", schloesser, "schloss")):
            for ep in eps:
                roh = endpunkte[ep].get(feld)
                n = name if len(eps) == 1 else f"{name}_{ep}"
                try:
                    if roh is None or roh == "" or roh == OHNE_AUSSAGE:
                        w = OHNE_AUSSAGE
                    elif art == "offen":
                        w = 0 if int(roh) else 1
                    else:
                        w = 1 if int(roh) == 1 else 0
                except (TypeError, ValueError):
                    w = OHNE_AUSSAGE
                paare[f"{n}/{art}"] = w
                herkunft[f"{n}/{art}"] = int(nr) if str(nr).isdigit() else 0
    return paare, herkunft


DATEI_HAUS_NAMEN = PCONFIG.parent / (PNAME + ".haus_namen.json")


def haus_namen_lesen() -> dict:
    aus = {}
    d = json_lesen(DATEI_HAUS_NAMEN).get("namen")
    for nr, name in (d.items() if isinstance(d, dict) else ()):
        if str(nr).isdigit() and isinstance(name, str) and re.match(r"^[a-z0-9_\-]{1,50}$", name):
            aus[str(nr)] = name
    return aus


def haus_namen_schreiben(namen: dict) -> bool:
    if not namen:
        try:
            DATEI_HAUS_NAMEN.unlink()
        except FileNotFoundError:
            pass
        except OSError:
            return False
        return True
    return json_schreiben(DATEI_HAUS_NAMEN, {
        "_hinweis": "Feste Namen der Geraete unter haus/tuer/<name>/ (Geraetenummer -> Name). "
                    "Eine Umbenennung des Geraets verschiebt das Thema nicht; neu vergeben wird "
                    "ein Name nach 'Themen dieses Geraets abraeumen'.",
        "namen": dict(sorted(namen.items(), key=lambda x: int(x[0])))})


def haus_gemerkt() -> dict:
    """Die je unter haus/tuer/ gesendeten Themen: {thema: geraetenummer}."""
    aus = {}
    d = json_lesen(DATEI_HAUS).get("themen")
    for t, nr in (d.items() if isinstance(d, dict) else ()):
        if isinstance(t, str) and _HAUS_THEMA.match(t):
            try:
                aus[t] = int(nr)
            except (TypeError, ValueError):
                aus[t] = 0
    return aus


def _haus_schreiben(themen: dict) -> bool:
    if not themen:
        try:
            DATEI_HAUS.unlink()
        except FileNotFoundError:
            pass
        except OSError:
            return False
        return True
    return json_schreiben(DATEI_HAUS, {
        "_hinweis": "Themen unter haus/tuer/, die dieses Plugin je zurueckbehalten gesendet "
                    "hat (Einstellung tuer_haus). Abschalten und Deinstallation raeumen sie ab.",
        "themen": dict(sorted(themen.items()))}, 0o600)


def haus_senden(geraete: dict, cfg: dict, voll: bool = False) -> None:
    if not cfg.get("tuer_haus"):
        haus_aus_pflegen()
        return
    if not cfg.get("mqtt_ein"):
        return
    namen = haus_namen_lesen()
    vorher = dict(namen)
    paare, herkunft = haus_paare(geraete, namen)
    if namen != vorher and not haus_namen_schreiben(namen):
        melde_gebremst("haus_namen", f"MQTT: die festen Namen unter {HAUS_WURZEL}/ liessen sich "
                       f"nicht in {DATEI_HAUS_NAMEN} merken - nach einer Umbenennung kann das "
                       "Thema wandern.")
    letzte = _HAUS["letzte"]
    if voll:
        senden = dict(paare)
    else:
        senden = {k: w for k, w in paare.items() if letzte.get(k) != str(w)}
    # Entscheidung 5: was wegfaellt (Geraet entfernt, Endpunkt weg), geht
    # einmal als "-" hinaus und wird danach vergessen.
    for k in [k for k in letzte if k not in paare]:
        if letzte[k] != OHNE_AUSSAGE:
            senden[k] = OHNE_AUSSAGE
        else:
            letzte.pop(k, None)
    if not senden:
        return
    # ERST merken, dann senden: stirbt der Dienst dazwischen, raeumen
    # Abschalten und Deinstallation trotzdem ab.
    gemerkt = haus_gemerkt()
    neu = {f"{HAUS_WURZEL}/{k}": herkunft.get(k, 0) for k in senden
           if f"{HAUS_WURZEL}/{k}" not in gemerkt and _HAUS_THEMA.match(f"{HAUS_WURZEL}/{k}")}
    if neu:
        # 0.9.35 (D2): haus/tuer/ ist ein gemeinsamer Baum mehrerer Plugins.
        # Steht unter einem Thema, das dieses Plugin noch nie gesendet hat,
        # schon ein zurueckbehaltener Wert, gehoert er einem anderen Anbieter
        # - dann wird NICHT darueber geschrieben (und spaeter auch nicht
        # abgeraeumt). Ist der Broker nicht zu fragen, wird gesendet wie bisher.
        # Ein bekannter Konflikt wird hoechstens alle HAUS_FRAGE_S nachgefragt -
        # sonst hielte jede Rueckfrage die Ereignisschleife bei jedem Abbild an.
        jetzt = time.time()
        if 0 <= jetzt - _HAUS.get("konflikt_gefragt", 0.0) < HAUS_FRAGE_S:
            for t in [t for t in neu if t in (_HAUS.get("konflikte") or [])]:
                neu.pop(t, None)
                k = t[len(HAUS_WURZEL) + 1:]
                senden.pop(k, None)
                paare.pop(k, None)
        konflikte_vorher = list(_HAUS.get("konflikte") or [])
        lage, belegt = ("ok", set())
        if neu:
            lage, belegt = mqtt_behalten_liste(sorted(neu), lambda t: t in neu)
            _HAUS["konflikt_gefragt"] = jetzt
            if lage == "ok":
                _HAUS["konflikte"] = sorted(set(_HAUS.get("konflikte") or []) - set(neu))
        if lage == "ok" and belegt:
            for t in sorted(belegt):
                neu.pop(t, None)
                k = t[len(HAUS_WURZEL) + 1:]
                senden.pop(k, None)
                paare.pop(k, None)
            _HAUS["konflikte"] = sorted(set(_HAUS.get("konflikte") or []) | set(belegt))
            melde_gebremst("haus_konflikt",
                           f"MQTT: unter {', '.join(sorted(belegt))} steht schon ein Wert eines "
                           "anderen Anbieters. Dieses Plugin sendet dort nicht. Abhilfe: das "
                           "Geraet umbenennen und 'Themen dieses Geraets abraeumen' druecken.")
        if list(_HAUS.get("konflikte") or []) != konflikte_vorher:
            # Auch eine LEERE Liste wird geschrieben - sonst zeigte der Reiter
            # Test einen behobenen Konflikt weiter an.
            zustand_schreiben(haus_konflikte=list(_HAUS.get("konflikte") or []))
    if neu:
        gemerkt.update(neu)
        if not _haus_schreiben(gemerkt):
            melde_gebremst("haus_merken", f"MQTT: die Themen unter {HAUS_WURZEL}/ liessen sich "
                           f"nicht in {DATEI_HAUS} merken - Abschalten und Deinstallation "
                           "raeumen sie dann nicht ab.")
    for k in mqtt_senden(senden, HAUS_WURZEL, retain_alle=True):
        if k in paare:
            letzte[k] = str(paare[k])
        else:
            letzte.pop(k, None)


def haus_aus_pflegen() -> None:
    """tuer_haus ist aus: frueher gesendete Themen abraeumen, hoechstens alle
    HAUS_FRAGE_S Sekunden ein Versuch, solange der Broker nicht bestaetigt."""
    _HAUS["letzte"].clear()
    if not DATEI_HAUS.is_file():
        return
    jetzt = time.time()
    if 0 <= jetzt - _HAUS["versucht"] < HAUS_FRAGE_S:
        return
    _HAUS["versucht"] = jetzt
    haus_leeren(melden=lambda t: _LOG.info("%s", t))


def haus_leeren(nur_geraet=None, runden: int = LEEREN_RUNDEN, pause: float = LEEREN_PAUSE_S,
                melden=print) -> int:
    """Die gemerkten Themen unter haus/tuer/ abraeumen - alle oder die eines
    Geraets. Vergessen wird, was der Broker als leer bestaetigt. Ohne
    gemerkte Themen still, Rueckgabe 0. Rueckgabe sonst wie praefix_leeren()."""
    gemerkt = haus_gemerkt()
    ziel = sorted(t for t, nr in gemerkt.items() if nur_geraet is None or nr == nur_geraet)
    if not ziel:
        return 0
    menge = set(ziel)
    rc, bestaetigt = themen_leeren([f"{HAUS_WURZEL}/#"], lambda t: t in menge, lambda: ziel,
                                   f"{HAUS_WURZEL}/", runden, pause, melden)
    if bestaetigt:
        rest = {t: nr for t, nr in haus_gemerkt().items() if t not in menge}
        if not _haus_schreiben(rest):
            melden(f"<WARNING> MQTT: {DATEI_HAUS} liess sich nicht nachfuehren.")
            return max(rc, 1)
    return rc


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
    # 0.9.35 (Nr. 20): uname nennt den KERN. Raspberry Pi OS 32 Bit startet
    # auf Pi 4/5 einen 64-Bit-Kern - uname sagt aarch64, das Benutzerland
    # (und damit Docker und jedes Abbild) ist aber armhf. Massgeblich ist die
    # Wortbreite dieses Python-Prozesses.
    import struct
    bits = struct.calcsize("P") * 8
    if bogen in ("x86_64", "aarch64", "arm64") and bits == 64:
        zeilen.append(f"[OK]   Architektur {bogen} ist 64 Bit (Kern und Benutzerland)")
    elif bogen in ("x86_64", "aarch64", "arm64"):
        fehler += 1
        zeilen.append(f"[FEHL] Der Kern ist 64 Bit ({bogen}), das Benutzerland aber {bits} Bit - "
                      "der Matter-Server braucht ein 64-Bit-Betriebssystem, nicht nur einen "
                      "64-Bit-Kern")
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

    # Ohne Wurzel kommt main() gar nicht bis hierher (seit 0.9.29).
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


# M6 (Durchgang 30.09.2026): welche Themenstaemme bildet der Dienst? Ein
# Knoten, der JEDES Attribut der Cluster-Tabelle traegt, geht durch dieselben
# Funktionen wie im Betrieb (knoten_abbilden, _taste, paare_bilden,
# herzschlag_paare). Die Pruefzeile im Reiter Test haelt das Ergebnis in
# beiden Richtungen gegen die Themenliste der Oberflaeche. Bis 0.9.30 suchte
# sie nur die Woerter online, ok und ts im Quelltext und blieb gruen, waehrend
# vier Namen der Liste nie hinausgingen (Bericht oberflaeche Nr. 11).
def themen_selbstauskunft() -> dict:
    tab = tabelle()
    probe = {"bool": True, "bit0": 1, "text": "x", "energie_struct": {"energy": 1000000},
             "struct0": {"0": 0}}
    attr: dict[str, object] = {}
    for cl, c in (tab.get("cluster") or {}).items():
        ep = "0" if c.get("nur_info") else "1"
        for at, a in (c.get("attribute") or {}).items():
            attr[f"{ep}/{cl}/{at}"] = probe.get(str(a.get("typ") or "zahl"), 100)
    g = knoten_abbilden({"node_id": 1, "available": True, "attributes": attr}, tab,
                        dict(VORGABEN, roh_ein=0))
    stummel = types.SimpleNamespace(ereignisse={}, knoten={})
    for ereignis, n in ((2, None), (6, 1), (6, 3), (6, 2)):
        nutz = {"NewPosition": 1}
        if n is not None:
            nutz["totalNumberOfPressesCounted"] = n
        MatterVerbindung._taste(stummel, {"node_id": 1, "endpoint_id": 1, "cluster_id": 59,
                                          "event_id": ereignis, "data": nutz})
    for ep, felder in (stummel.ereignisse.get(1) or {}).items():
        ziel = g["endpunkte"].setdefault(ep, {})
        for thema, wert in felder.items():
            if wert is not None:
                ziel[thema] = wert
    paare = paare_bilden({"1": g}, dict(VORGABEN, mqtt_ein=1, roh_ein=0), 1)
    paare.update(herzschlag_paare(1))
    staemme = sorted({k.rsplit("/", 1)[-1] for k in paare})
    return {"themen": staemme, "retained": [t for t in staemme if ist_zustand(t)]}


# C2 (Durchgang 30.09.2026): nur EIN Dienst je Installation. Sperrdatei
# dienst.lock im Datenordner, fcntl.flock ohne Warten; der Deskriptor wird
# mit O_CLOEXEC geoeffnet und bleibt offen, solange der Dienst laeuft - das
# Betriebssystem gibt die Sperre beim Prozessende frei. Bis 0.9.30 ergaben
# zwei gleichzeitige "dienst.sh start" (oder zwei Waechter derselben Minute)
# fuenf von fuenf Runden zwei Dienste (gemessen, Bericht code C2). Die
# Startsperre in dienst.sh ist die erste Stufe, diese die zweite. Bauart
# Bewaesserung 0.9.35. Ohne fcntl oder ohne anlegbare Datei faellt sie offen
# aus (dann gilt nur die Sperre in dienst.sh).
DATEI_DIENSTSPERRE = PDATA / "dienst.lock"
_DIENSTSPERRE = None


def dienstsperre_nehmen() -> bool:
    global _DIENSTSPERRE
    try:
        import fcntl  # noqa: PLC0415
    except ImportError:
        return True
    try:
        PDATA.mkdir(parents=True, exist_ok=True)
        fd = os.open(str(DATEI_DIENSTSPERRE),
                     os.O_RDWR | os.O_CREAT | getattr(os, "O_CLOEXEC", 0), 0o644)
    except OSError as err:
        _LOG.warning("Sperrdatei %s nicht anlegbar (%s) - es gilt nur die Startsperre "
                     "in dienst.sh.", DATEI_DIENSTSPERRE, err)
        return True
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        os.close(fd)
        return False
    _DIENSTSPERRE = fd
    return True


# C6 (Durchgang 30.09.2026): die bekannten Schalter. Bis 0.9.30 lief jeder
# andere Aufruf ("--selftest", ein Tippfehler) als Dienst - neben dem
# eigentlichen und ohne PID-Datei (gemessen, Bericht code C6).
BEKANNTE_SCHALTER = ("--einmal", "--selbsttest", "--mqtt-leeren", "--themen",
                     "--geraet-leeren", "--haus-leeren")


def main() -> int:
    # b1: "--geraet-leeren N" traegt als einziger Schalter einen Wert
    # (Geraetenummer 1 bis 999). Fehlt er oder passt er nicht, wird nichts
    # getan (Rueckgabe 2), wie bei einem unbekannten Schalter.
    argumente = sys.argv[1:]
    geraet = None
    if "--geraet-leeren" in argumente:
        i = argumente.index("--geraet-leeren")
        wert = argumente[i + 1] if i + 1 < len(argumente) else ""
        if not re.match(r"^[1-9][0-9]{0,2}$", wert):
            print("[FEHL] --geraet-leeren braucht eine Geraetenummer von 1 bis 999. Es wurde "
                  "nichts getan.", file=sys.stderr)
            return 2
        geraet = int(wert)
        argumente = argumente[:i] + argumente[i + 2:]
    fremd = [a for a in argumente if a not in BEKANNTE_SCHALTER]
    if fremd:
        print("[FEHL] Unbekannter Schalter: %s. Bekannt sind: %s. Es wurde nichts "
              "gestartet und nichts angelegt." % (" ".join(fremd), ", ".join(BEKANNTE_SCHALTER)),
              file=sys.stderr)
        return 2
    # --themen schreibt nichts und legt nichts an; es braucht nur die
    # Cluster-Tabelle (auch aus dem ausgepackten Archiv).
    if "--themen" in sys.argv:
        print(json.dumps(themen_selbstauskunft(), ensure_ascii=False))
        return 0
    # VOR log_einrichten(): das legt den Logordner an. Ein Schutz faellt
    # geschlossen aus (CLAUDE.md 4) - auch "--selbsttest" aus einem
    # Pruefarchiv schreibt nichts in die Anlage (Faelle P1, P2, P5).
    if not LBHOME_GEFUNDEN:
        print("[FEHL] Es wurde kein LoxBerry-Wurzelverzeichnis gefunden: $LBHOMEDIR ist "
              f"nicht gesetzt, und oberhalb von {SELF} traegt keines config/plugins, "
              "data/plugins und config/system/general.json. Es wurde nichts gestartet "
              "und nichts angelegt.")
        return 1
    if not INSTALLIERT:
        print(f"[FEHL] Dieses Skript liegt nicht unter {LBHOME / 'bin' / 'plugins' / PNAME} - "
              "aus einem ausgepackten Archiv wird nichts gestartet und nichts angelegt.")
        return 1
    # VOR log_einrichten(): die Deinstallation legt nichts an und schreibt
    # kein Protokoll (uninstall/uninstall, Schritt 1b).
    if "--mqtt-leeren" in sys.argv:
        return mqtt_leeren()
    # b1 und Tuer-1: aus der Oberflaeche, als loxberry; legen nichts an und
    # schreiben kein Protokoll - wie --mqtt-leeren. Rueckgabe wie dort.
    if geraet is not None:
        return geraet_leeren(geraet)
    if "--haus-leeren" in sys.argv:
        return haus_leeren()
    log_einrichten()
    if "--selbsttest" in sys.argv:
        return selbsttest()
    einmal = "--einmal" in sys.argv
    if not einmal and not dienstsperre_nehmen():
        satz = ("Ein anderer Dienst dieses Plugins haelt die Sperre %s - dieser Start endet, "
                "ohne zu verbinden oder zu senden." % DATEI_DIENSTSPERRE)
        _LOG.warning(satz)
        print(satz, file=sys.stderr)
        return 3
    signal.signal(signal.SIGTERM, signal_behandeln)
    signal.signal(signal.SIGINT, signal_behandeln)
    try:
        return asyncio.run(dienst(einmal=einmal))
    except KeyboardInterrupt:
        return 0
    except Exception as err:  # noqa: BLE001
        _LOG.error("Dienst abgebrochen: %s", fehlertext(err))
        zustand_schreiben(ok=0, fehler=fehlertext(err))
        return 1


if __name__ == "__main__":
    sys.exit(main())
