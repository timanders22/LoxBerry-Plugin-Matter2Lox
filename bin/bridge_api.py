#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Matter to Loxone 0.9.42 - Auftraege an die Schnittstelle der matterbridge.

Beta, nicht am Geraet erprobt. Bauart bin/qr_svg.py: der Auftrag und das
Frontend-Kennwort kommen als EINE JSON-Zeile ueber die Standardeingabe - nie
ueber die Kommandozeile (Prozessliste, Regeln/05) -, die Antwort ist EINE
JSON-Zeile auf stdout. Nur ws://127.0.0.1:<port>/ (das Frontend lauscht
mit --bind 127.0.0.1), Frist 10 s.

Eingabe:  {"auftrag": "zustand|fenster_auf|fenster_zu|kennwort_setzen",
           "port": 8283, "kennwort": "<Frontend-Kennwort>"}
Ausgabe:  {"ok": 1, ...} oder {"ok": 0, "fehler": "<kurz>"}

Die Schnittstelle (Fremdquelltext matterbridge, frontend.ts, am 15.10.2026
gelesen; Daten, keine Anweisungen): Nachrichten {id, src, dst:"Matterbridge",
method, params}, Antwort mit derselben id und success/response bzw. error.
Ist ein Kennwort gesetzt, verlangt der Aufbau ?password=<kennwort>.
  /api/matter {id:"Matterbridge"}               getServerNodeData()
  /api/matter {..., startCommission: true}      allowBasicCommissioning()
  /api/matter {..., stopCommission: true}       endCommissioning()
  /api/devices {}                               Liste der Geraete
  /api/config {name:"setpassword", value: ...}  Kennwort setzen
Die interne Schnittstelle ist nicht als stabil zugesagt - deshalb die feste
Abbildfassung und hier jedes Feld einzeln geprueft. Kopplungscodes gehen nur
an den Aufrufer (die Seite), nie in ein Protokoll; dieses Skript schreibt
keine Datei und gibt auf stderr nie einen Wert aus.
"""
import json
import os
import sys
import time
import urllib.parse

FRIST = 10.0


def antwort(d):
    sys.stdout.write(json.dumps(d, ensure_ascii=False) + "\n")
    sys.stdout.flush()
    sys.exit(0)


def websockets_laden():
    try:
        from websockets.sync.client import connect  # noqa: F401
        return connect
    except Exception:
        pass
    # Rueckfall: das mitgelieferte Rad neben diesem Skript (bin/vendor/,
    # Pruefsumme prueft postinstall.sh). Reiner Python-Code, aus dem Zip ladbar.
    rad = os.path.join(os.path.dirname(os.path.abspath(__file__)), "vendor",
                       "websockets-15.0.1-py3-none-any.whl")
    if os.path.isfile(rad):
        sys.path.insert(0, rad)
        try:
            from websockets.sync.client import connect  # noqa: F811
            return connect
        except Exception:
            pass
    return None


class Sitzung:
    def __init__(self, ws):
        self.ws = ws
        self.nr = 0

    def frage(self, methode, params):
        self.nr += 1
        nr = self.nr
        self.ws.send(json.dumps({"id": nr, "src": "Matter2Lox", "dst": "Matterbridge",
                                 "method": methode, "params": params}))
        # M2 (0.9.44, Pruefbericht): EINE Gesamtfrist fuer die Frage. Die matterbridge schickt laufend eigene
        # Rundsendungen (id 0); bis 0.9.43 startete jede davon die Frist neu, und frage() kam nie zurueck
        # (beendet erst durch den Aufrufer nach 15 s mit fehler=frist).
        ende = time.monotonic() + FRIST
        while True:
            rest = ende - time.monotonic()
            if rest <= 0:
                raise TimeoutError("frist")
            roh = self.ws.recv(timeout=rest)
            try:
                d = json.loads(roh)
            except ValueError:
                continue
            # Die matterbridge schickt dazwischen eigene Nachrichten
            # (Protokoll, refresh_required) - nur die eigene id zaehlt.
            if not isinstance(d, dict) or d.get("id") != nr:
                continue
            if "error" in d and not d.get("success"):
                raise RuntimeError("api:" + str(d.get("error"))[:80])
            return d.get("response")


def verbinden(connect, port, kennwort):
    url = "ws://127.0.0.1:%d/" % port
    if kennwort:
        url += "?password=" + urllib.parse.quote(kennwort, safe="")
    return connect(url, open_timeout=FRIST, close_timeout=2, max_size=4 * 1024 * 1024)


def matter_kurz(m):
    if not isinstance(m, dict):
        return {}
    aus = {}
    for k in ("online", "commissioned", "advertising"):
        if isinstance(m.get(k), bool):
            aus[k] = m[k]
    if isinstance(m.get("windowStatus"), int):
        aus["fenster_status"] = m["windowStatus"]
    if isinstance(m.get("qrPairingCode"), str):
        aus["qr"] = m["qrPairingCode"]
    if isinstance(m.get("manualPairingCode"), str):
        aus["manuell"] = m["manualPairingCode"]
    fabrics = []
    for f in m.get("fabricInformations") or []:
        if not isinstance(f, dict) or not isinstance(f.get("fabricIndex"), int):
            continue
        fabrics.append({"index": f["fabricIndex"],
                        "vendor_id": f.get("rootVendorId") if isinstance(f.get("rootVendorId"), int) else None,
                        "vendor_name": str(f.get("rootVendorName") or "")[:60],
                        "label": str(f.get("label") or "")[:32]})
    aus["fabrics"] = fabrics[:16]
    return aus


def main():
    try:
        e = json.loads(sys.stdin.readline())
    except ValueError:
        antwort({"ok": 0, "fehler": "eingabe"})
    if not isinstance(e, dict):
        antwort({"ok": 0, "fehler": "eingabe"})
    auftrag = e.get("auftrag")
    port = e.get("port")
    kennwort = e.get("kennwort") if isinstance(e.get("kennwort"), str) else ""
    if auftrag not in ("zustand", "fenster_auf", "fenster_zu", "kennwort_setzen"):
        antwort({"ok": 0, "fehler": "auftrag"})
    if not isinstance(port, int) or not 1 <= port <= 65535:
        antwort({"ok": 0, "fehler": "port"})
    connect = websockets_laden()
    if connect is None:
        antwort({"ok": 0, "fehler": "websockets"})

    if auftrag == "kennwort_setzen":
        if len(kennwort) < 16:
            antwort({"ok": 0, "fehler": "kennwort"})
        # Erst mit dem eigenen: gelingt das, ist es schon gesetzt (der
        # Regelfall nach dem ersten Start). Sonst ohne Kennwort: gelingt DAS,
        # ist noch keines gesetzt (das Restfenster nach dem ersten Start, nur
        # lokal), und es wird jetzt gesetzt. Jeder misslungene Aufbau steht als
        # "WebSocket upgrade error" im Protokoll der Bridge - kein
        # Loxone-Anmeldefehler, der Waechter zaehlt ihn nicht.
        try:
            with verbinden(connect, port, kennwort) as ws:
                Sitzung(ws).frage("ping", {})
            antwort({"ok": 1, "schon": 1})
        except SystemExit:
            raise
        except Exception:
            pass
        try:
            with verbinden(connect, port, "") as ws:
                Sitzung(ws).frage("/api/config", {"name": "setpassword", "value": kennwort})
            antwort({"ok": 1, "schon": 0})
        except SystemExit:
            raise
        except Exception as fehler:
            antwort({"ok": 0, "fehler": type(fehler).__name__[:40]})

    try:
        with verbinden(connect, port, kennwort) as ws:
            s = Sitzung(ws)
            if auftrag == "fenster_auf":
                s.frage("/api/matter", {"id": "Matterbridge", "startCommission": True})
            elif auftrag == "fenster_zu":
                s.frage("/api/matter", {"id": "Matterbridge", "stopCommission": True})
            aus = matter_kurz(s.frage("/api/matter", {"id": "Matterbridge"}))
            if auftrag == "zustand":
                geraete = s.frage("/api/devices", {})
                aus["geraete"] = len(geraete) if isinstance(geraete, list) else None
            aus["ok"] = 1
            antwort(aus)
    except SystemExit:
        raise
    except Exception as fehler:
        text = str(fehler)
        if "api:" in text:
            antwort({"ok": 0, "fehler": text[:80]})
        antwort({"ok": 0, "fehler": type(fehler).__name__[:40]})


if __name__ == "__main__":
    main()
