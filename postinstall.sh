#!/bin/bash
# Matter to Loxone - postinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Das Plugin ist die BRUECKE, nicht der Matter-Controller. Der Controller ist
# der offiziell zertifizierte Matter-Server (python-matter-server); er laeuft
# in einem eigenen Container, weil er Host-Netzwerk, mDNS und Bluetooth ueber
# D-Bus braucht.
#
# Diese Installation legt an: Ordner, Konfiguration und eine kleine virtuelle
# Python-Umgebung mit genau EINEM Paket (websockets). PEP 668 laesst ein
# systemweites pip3 install auf Debian 12/13 nicht zu - deshalb die venv.
# JEDER Rueckgabewert wird geprueft.

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-matter2lox}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PLOG="$BASE/log/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"
VENV="$PBIN/venv"

mkdir -p "$PDATA/befehle" "$PDATA/antworten" "$PDATA/matter" "$PLOG" "$PCONFIG" || {
    echo "<FAIL> Ordner konnten nicht angelegt werden."
    exit 1
}
chmod 755 "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null
# Hier liegen Fabric und Zertifikate des Matter-Controllers.
chmod 700 "$PDATA/matter" 2>/dev/null

[ -f "$PCONFIG/matter2lox.json" ] || echo '{}' > "$PCONFIG/matter2lox.json"
chmod 600 "$PCONFIG/matter2lox.json"

BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$PCONFIG/matter2lox.json"
if [ -f "$BK" ]; then
    INHALT=$(cat "$CF" 2>/dev/null)
    if [ ! -s "$CF" ] || [ "$INHALT" = "{}" ]; then
        cp -p "$BK" "$CF" && echo "<OK> Konfiguration aus Sicherung wiederhergestellt."
    fi
fi
chmod 600 "$PCONFIG/matter2lox.json"

# ---------- Architektur ----------
ARCH=$(uname -m)
case "$ARCH" in
    x86_64|aarch64|arm64)
        echo "<OK> Architektur $ARCH ist 64 Bit." ;;
    *)
        echo "<FAIL> Architektur $ARCH ist nicht 64 Bit."
        echo "<FAIL> Der Matter-Server laeuft ausdruecklich nur auf 64-Bit-Systemen."
        echo "<FAIL> Auf einem 32-Bit-Raspberry-Pi-OS gibt es keinen Weg - dort hilft"
        echo "<FAIL> nur ein Neuaufsetzen mit einem 64-Bit-Abbild."
        exit 1 ;;
esac

# ---------- IPv6 ----------
# Matter beruht auf IPv6-Link-Local-Multicast. Ohne IPv6 laeuft gar nichts.
if [ -e /proc/net/if_inet6 ] && [ "$(cat /proc/sys/net/ipv6/conf/all/disable_ipv6 2>/dev/null)" != "1" ]; then
    echo "<OK> IPv6 ist auf diesem System aktiv."
else
    echo "<INFO> ACHTUNG: IPv6 sieht abgeschaltet aus."
    echo "<INFO> Matter beruht auf IPv6-Link-Local-Multicast und wird ohne IPv6 NICHT"
    echo "<INFO> funktionieren. Der Reiter Test des Plugins prueft das erneut und nennt"
    echo "<INFO> die Abhilfe. Die Installation laeuft weiter."
fi

# ---------- Python ----------
PY=""
if command -v python3 >/dev/null 2>&1 && python3 -c 'import sys; sys.exit(0 if sys.version_info >= (3,9) else 1)'; then
    PY="python3"
fi
if [ -z "$PY" ]; then
    echo "<FAIL> Es wurde kein Python 3.9 oder neuer gefunden ($(python3 -V 2>&1))."
    exit 1
fi
echo "<INFO> Verwendetes Python: $($PY -V 2>&1)"

BRAUCHBAR=0
if [ -x "$VENV/bin/python3" ] && "$VENV/bin/python3" -c 'import sys' 2>/dev/null; then
    BRAUCHBAR=1
fi
if [ "$BRAUCHBAR" -eq 0 ]; then
    rm -rf "$VENV"
    if ! "$PY" -m venv "$VENV"; then
        echo "<FAIL> Virtuelle Umgebung konnte nicht angelegt werden ($VENV)."
        echo "<FAIL> Fehlt das Paket python3-venv? (apt install python3-venv)"
        exit 1
    fi
    echo "<OK> Virtuelle Umgebung angelegt: $VENV"
fi
"$VENV/bin/python3" -m pip install --upgrade pip >/dev/null 2>&1 || \
    echo "<INFO> pip liess sich nicht aktualisieren - weiter mit der vorhandenen Fassung."

echo "<INFO> Installiere websockets (benoetigt eine Internetverbindung) ..."
if ! "$VENV/bin/python3" -m pip install --no-cache-dir "websockets>=12"; then
    echo "<FAIL> Das Paket websockets liess sich nicht installieren."
    echo "<FAIL> Ohne dieses eine Paket kann der Dienst nicht mit dem Matter-Server reden."
    exit 1
fi
# Rueckgabewert allein genuegt nicht - es wird nachgesehen, ob es sich laden laesst.
if ! "$VENV/bin/python3" -c 'import websockets' 2>/dev/null; then
    echo "<FAIL> websockets ist installiert, laesst sich aber nicht laden."
    exit 1
fi
WSVER=$("$VENV/bin/python3" -c 'import websockets; print(websockets.__version__)' 2>/dev/null || echo "unbekannt")
echo "<OK> websockets geladen, Fassung $WSVER"

# ---------- Docker ----------
if command -v docker >/dev/null 2>&1; then
    if docker info >/dev/null 2>&1; then
        echo "<OK> Docker vorhanden und ansprechbar: $(docker --version 2>/dev/null)"
    else
        echo "<INFO> Docker ist installiert, antwortet aber nicht."
        echo "<INFO> Meist fehlt dem Benutzer loxberry die Gruppe docker:"
        echo "<INFO>   sudo usermod -aG docker loxberry   (danach neu anmelden)"
    fi
else
    echo "<INFO> Docker ist nicht installiert."
    echo "<INFO> Das ist nur dann ein Problem, wenn das Plugin den Matter-Server"
    echo "<INFO> selbst betreiben soll. Wer bereits einen Matter-Server hat,"
    echo "<INFO> traegt in den Einstellungen einfach dessen Adresse ein."
    echo "<INFO> Docker nachruesten: LoxBerry-Plugin Docker installieren."
fi

chmod 755 "$PBIN/dienst.sh" "$PBIN/matter_dienst.py" 2>/dev/null
chown -R loxberry:loxberry "$PBIN" "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null
chmod 600 "$PCONFIG/matter2lox.json"
chmod 700 "$PDATA/matter" 2>/dev/null

echo "<OK> Installation abgeschlossen."
echo "<INFO> Weiter in der Plugin-Oberflaeche: Reiter Einstellungen, dort entweder"
echo "<INFO> den Container anlegen lassen oder die Adresse eines vorhandenen"
echo "<INFO> Matter-Servers eintragen."
exit 0
