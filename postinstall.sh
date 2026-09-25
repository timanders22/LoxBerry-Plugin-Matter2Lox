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
# Wurzel: $5 bzw. LBHOMEDIR mit config/plugins darunter, sonst vom eigenen
# Ablageort AUFWAERTS ein Verzeichnis mit config/plugins, data/plugins UND
# config/system/general.json (Regeln/06), sonst keine. Bis 0.9.28 stand hier
# "zwei Ebenen ueber dem Ablageort", geprueft nur auf config/plugins: in WSL
# gemessen (25.09.2026, Pruefung-Matter2Lox-0.9.29, Fall W6) legte das Skript
# in einem fremden Baum ohne general.json data/plugins/<ordner> und die
# Fabric-, Log- und Konfigurationsordner an.
mt_wurzel_suchen() {
    v=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd)
    i=0
    while [ -n "$v" ] && [ "$v" != "/" ] && [ $i -lt 8 ]; do
        if [ -d "$v/config/plugins" ] && [ -d "$v/data/plugins" ] \
           && [ -f "$v/config/system/general.json" ]; then
            printf '%s\n' "$v"; return 0
        fi
        v=$(dirname "$v"); i=$((i + 1))
    done
    return 1
}
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ]; then
    BASE=$(mt_wurzel_suchen)
fi

if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ]; then
    echo "<FAIL> Der LoxBerry-Wurzelordner liess sich nicht bestimmen."
    echo "<FAIL> Ohne ihn wuerden Ordner im Wurzelverzeichnis des Systems"
    echo "<FAIL> angelegt. Die Installation wird abgebrochen."
    exit 1
fi

PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PLOG="$BASE/log/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"
VENV="$PBIN/venv"
# Fabric und Geraetenummern liegen NEBEN dem Datenordner. Der Installer
# loescht data/plugins/<ordner>/ bei jedem Upgrade vollstaendig
# (plugininstall.pl, purge_installation); Nachbarn ueberleben. Bis 0.9.16 lag
# die Fabric darin, und jedes Update kostete alle angelernten Geraete.
PFABRIC="$BASE/data/plugins/$PFOLDER.matter"
PNUMMERN="$BASE/data/plugins/$PFOLDER.nummern.json"

mkdir -p "$PDATA/befehle" "$PDATA/antworten" "$PFABRIC" "$PLOG" "$PCONFIG" || {
    echo "<FAIL> Ordner konnten nicht angelegt werden."
    exit 1
}
chmod 755 "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null
# In der Warteschlange liegen WLAN-Passwort, Thread-Dataset und Anlerncode.
chmod 700 "$PDATA/befehle" 2>/dev/null
# Hier liegen Fabric und Zertifikate des Matter-Controllers.
chmod 700 "$PFABRIC" 2>/dev/null

# Rueckfall, falls preupgrade.sh nicht lief (Neuinstallation ueber einen alten
# Bestand, von Hand ausgepackt): einen Altbestand einmal uebernehmen.
if [ -d "$PDATA/matter" ] && [ ! -e "$PFABRIC/.initialized" ]; then
    if [ -z "$(ls -A "$PFABRIC" 2>/dev/null)" ]; then
        if cp -a "$PDATA/matter/." "$PFABRIC/" 2>/dev/null; then
            chmod 700 "$PFABRIC" 2>/dev/null
            echo "<OK> Fabric vom alten Ort uebernommen."
        fi
    fi
fi
if [ -f "$PDATA/nummern.json" ] && [ ! -f "$PNUMMERN" ]; then
    cp -p "$PDATA/nummern.json" "$PNUMMERN" 2>/dev/null \
        && echo "<OK> Geraetenummern vom alten Ort uebernommen."
fi

[ -f "$PCONFIG/matter2lox.json" ] || echo '{}' > "$PCONFIG/matter2lox.json"
chmod 600 "$PCONFIG/matter2lox.json"

BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$PCONFIG/matter2lox.json"
# Zurueckgespielt wird nach INHALT, nie nach Groesse. Stufe 2 = JSON-Objekt
# mit nicht leerem aktionstoken, 1 = JSON-Objekt mit mindestens einem
# Eintrag, 0 = nichts Brauchbares (dieselbe Einteilung wie in preupgrade.sh).
# Die Sicherung ersetzt die Konfiguration nur, wenn diese kein Token traegt
# und die Sicherung mehr traegt als sie. Bis 0.9.28 entschied "leer oder
# genau {}": eine beschaedigte Datei ("xx") blieb stehen, und eine Sicherung
# "{}" wurde als "wiederhergestellt" gemeldet (in WSL gemessen 25.09.2026,
# Pruefung-Matter2Lox-0.9.29, Faelle K3 und K5).
mt_inhalt() {
    python3 -c '
import json, sys
try:
    with open(sys.argv[1], encoding="utf-8") as d:
        c = json.load(d)
except Exception:
    print(0); sys.exit(0)
if not isinstance(c, dict) or not c:
    print(0)
elif str(c.get("aktionstoken") or "").strip():
    print(2)
else:
    print(1)
' "$1" 2>/dev/null || echo 0
}
if [ -f "$BK" ]; then
    CF_STUFE=$(mt_inhalt "$CF")
    BK_STUFE=$(mt_inhalt "$BK")
    if [ "$CF_STUFE" -lt 2 ] && [ "$BK_STUFE" -gt "$CF_STUFE" ]; then
        if [ "$CF_STUFE" -eq 0 ] && [ -s "$CF" ] && [ "$(cat "$CF" 2>/dev/null)" != "{}" ] \
           && [ ! -e "$CF.kaputt" ]; then
            # Die beschaedigte Datei bleibt zum Nachsehen liegen, wie in
            # mt_config() der Oberflaeche.
            cp "$CF" "$CF.kaputt" 2>/dev/null && chmod 600 "$CF.kaputt" 2>/dev/null
        fi
        if cp "$BK" "$CF"; then
            if [ "$BK_STUFE" -eq 2 ]; then
                echo "<OK> Konfiguration aus Sicherung wiederhergestellt."
            else
                echo "<INFO> Konfiguration aus Sicherung wiederhergestellt - sie traegt kein Aktionstoken."
            fi
        else
            echo "<WARNING> Die Sicherung $PFOLDER.backup.json liess sich nicht zurueckspielen."
        fi
    elif [ "$CF_STUFE" -lt 2 ] && [ "$BK_STUFE" -eq 0 ]; then
        echo "<INFO> Die Sicherung $PFOLDER.backup.json ist leer oder nicht lesbar - nicht zurueckgespielt."
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

# Warum nicht einfach 'test -x': eine venv, deren Python nicht mehr startet
# (Systemwechsel von 3.11 auf 3.13, halb geloeschter Ordner), sieht von
# aussen heil aus. Deshalb wird sie einmal WIRKLICH aufgerufen - und wenn
# das misslingt, steht der Grund im Protokoll, statt dass hier stumm
# geloescht und neu gebaut wird.
BRAUCHBAR=0
if [ -x "$VENV/bin/python3" ]; then
    if PRUEFAUSGABE=$("$VENV/bin/python3" -c 'import sys' 2>&1); then
        BRAUCHBAR=1
    else
        echo "<INFO> Die vorhandene virtuelle Umgebung startet nicht und wird neu gebaut."
        echo "<INFO> Grund: $PRUEFAUSGABE"
    fi
elif [ -d "$VENV" ]; then
    echo "<INFO> In $VENV steht kein ausfuehrbares python3 - die Umgebung wird neu gebaut."
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
# Rueckgabewert allein genuegt nicht - es wird nachgesehen, ob es sich laden
# laesst. Die Fehlermeldung des Ladeversuchs wird MITGEGEBEN: ohne sie steht
# im Protokoll nur, dass es nicht ging, und die haeufigste Ursache (ein Wheel
# fuer die falsche Architektur oder eine fehlende Systembibliothek) bleibt
# unsichtbar.
if ! LADEFEHLER=$("$VENV/bin/python3" -c 'import websockets' 2>&1); then
    echo "<FAIL> websockets ist installiert, laesst sich aber nicht laden."
    echo "<FAIL> Grund: $LADEFEHLER"
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
chown -R loxberry:loxberry "$PFABRIC" 2>/dev/null
[ -f "$PNUMMERN" ] && chown loxberry:loxberry "$PNUMMERN" 2>/dev/null
chmod 600 "$PCONFIG/matter2lox.json"
chmod 700 "$PFABRIC" 2>/dev/null
chmod 700 "$PDATA/befehle" 2>/dev/null

# ---------- Dienst wieder anwerfen, wenn er vorher lief ----------
# Der Sollmerker liegt in data/plugins/<ordner>/ und wird vom Installer
# mitgeloescht. Bis 0.9.16 lief der Dienst nach einem Update deshalb gar
# nicht wieder an - bis jemand die Oberflaeche oeffnete und Start drueckte.
# In Loxone sah das aus wie ein ruhiges Haus: der Herzschlag, der genau das
# verhindern soll, schwieg ja ebenfalls.
#
# Zuerst aufraeumen, was in der Luecke angelaufen ist. Solange die Marke
# aus preupgrade.sh liegt, stammt jeder eigene Dienst aus der Zeit vor oder
# waehrend der Installation - etwa ein Knopfdruck in der Oberflaeche, der
# die Marke um Sekunden verpasst hat, oder ein Dienst aus der Zeit vor 0.9.26.
# Er laeuft mit altem Code und ohne PID-Datei; 'dienst.sh stop' sucht seit
# 0.9.26 argumentweise und findet auch ihn. Danach laeuft genau einer.
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
LIEF="$BASE/data/plugins/$PFOLDER.lief"
if [ -f "$MARKE" ] && [ -x "$PBIN/dienst.sh" ]; then
    MT_STOPP=$("$PBIN/dienst.sh" stop 2>&1)
    case "$MT_STOPP" in
        *angehalten*) echo "<INFO> Ein Dienst lief waehrend der Installation und wurde beendet." ;;
    esac
fi
if [ -f "$LIEF" ]; then
    rm -f "$LIEF"
    if [ -x "$PBIN/dienst.sh" ]; then
        # MT_START_TROTZ_MARKE=1: die Marke liegt noch (sie faellt erst
        # unten, NACH dem Start - sonst koennte der Minutentakt in die
        # Luecke zwischen Abraeumen und Start fallen). Nur hier wird sie
        # uebergangen; sie ist die eigene, und dies ist der letzte Schritt
        # der Installation. Vorbild: Chromecast4lox 1.3.11, postroot.sh.
        if su -s /bin/bash loxberry -c "MT_START_TROTZ_MARKE=1 $(printf '%q ' "$PBIN/dienst.sh" start)" >/dev/null 2>&1 \
           || MT_START_TROTZ_MARKE=1 "$PBIN/dienst.sh" start >/dev/null 2>&1; then
            echo "<OK> Der Dienst lief vor dem Update und wurde wieder gestartet."
        else
            echo "<INFO> Der Dienst lief vor dem Update, liess sich aber nicht"
            echo "<INFO> starten. Reiter Einstellungen, Knopf 'Dienst starten'."
        fi
    fi
else
    echo "<INFO> Der Dienst lief vorher nicht und wurde nicht gestartet."
fi
# Die Marke faellt NACH dem Start. postupgrade.sh entfernt sie noch einmal;
# bei einer Neuinstallation gibt es sie gar nicht.
rm -f "$MARKE" 2>/dev/null

# ---------- Schlusszeile ----------
# Dieses Skript laeuft bei der Erstinstallation UND bei jedem Upgrade
# (plugininstall.pl uebergibt kein Kennzeichen). Bis 0.9.27 stand die
# Anleitung "Container anlegen oder Adresse eintragen" nach jedem Upgrade,
# auch ueber einer eben zurueckgespielten Konfiguration.
# Entschieden wird nach dem INHALT von matter2lox.json nach dem
# Zurueckspielen, mit derselben Bedingung, nach der die Linie ihre
# Zweitschrift beurteilt (mt_config_speichern() in mt_lib.php: ohne
# Aktionstoken ist es "die blanke Werkseinstellung"): lesbares JSON-Objekt
# mit nicht leerem aktionstoken. Fehlt es, erscheint die Anleitung.
# Gemessen am 24.09.2026: Pruefung-Matter2Lox-0.9.28/postinstall_hinweis.md.
MT_EINGERICHTET=0
if "$PY" -c '
import json, sys
try:
    with open(sys.argv[1], encoding="utf-8") as d:
        c = json.load(d)
except Exception:
    sys.exit(1)
ok = isinstance(c, dict) and str(c.get("aktionstoken") or "").strip() != ""
sys.exit(0 if ok else 1)
' "$CF" 2>/dev/null; then
    MT_EINGERICHTET=1
fi

if [ "$MT_EINGERICHTET" -eq 1 ]; then
    echo "<OK> Aktualisierung abgeschlossen, Einstellungen uebernommen."
else
    echo "<OK> Installation abgeschlossen."
fi
if [ -d "$PDATA/matter" ]; then
    echo "<INFO> ACHTUNG: Der Matter-Container zeigt noch auf den alten"
    echo "<INFO> Datenpfad ($PDATA/matter), der beim naechsten Update"
    echo "<INFO> geloescht wird. Bitte im Reiter Einstellungen einmal"
    echo "<INFO> 'Container entfernen' und dann 'Container anlegen' druecken."
    echo "<INFO> Die angelernten Geraete bleiben dabei erhalten."
fi
if [ "$MT_EINGERICHTET" -eq 0 ]; then
    echo "<INFO> Weiter in der Plugin-Oberflaeche: Reiter Einstellungen, dort entweder"
    echo "<INFO> den Container anlegen lassen oder die Adresse eines vorhandenen"
    echo "<INFO> Matter-Servers eintragen."
fi
exit 0
