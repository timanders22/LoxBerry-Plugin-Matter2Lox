#!/bin/bash
# Matter to Loxone - preupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# DIESES SKRIPT IST DAS EINZIGE RETTUNGSFENSTER.
#
# Am plugininstall.pl des LoxBerry nachgemessen (Zweig master, 03.09.2026):
#   :857  preupgrade wird ausgefuehrt      <- hier stehen wir
#   :886  purge_installation               <- raeumt ab
#   :1629 rm -rf config/plugins/<ordner>/
#   :1631 rm -rf data/plugins/<ordner>/    <- ohne Bedingung
#   :1316 postinstall                      <- Rueckgabefenster
# Der Log-Ordner (:1653) haengt an "all" und ueberlebt ein Upgrade; config und
# data nicht. Was ueberleben soll, muss also VON HIER neben den Ordner.
#
# Bis 0.9.16 sicherte dieses Skript nur die Konfigurations-JSON und behauptete
# im Kommentar, der Matter-Container bleibe unberuehrt. Der Container blieb es
# auch - sein DATENORDNER aber nicht: der Bindmount zeigte auf
# data/plugins/<ordner>/matter, und der wurde bei jedem Update geloescht.
# Jedes angelernte Geraet haette danach neu angelernt werden muessen.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-matter2lox}"

# Wurzelbestimmung - wie in postinstall.sh und uninstall: $5 bzw. LBHOMEDIR,
# wenn darunter config/plugins liegt, sonst vom eigenen Ablageort AUFWAERTS
# ein Verzeichnis mit config/plugins, data/plugins UND
# config/system/general.json (Regeln/06), sonst keine Wurzel.
# Bis 0.9.28 stand hier als Rueckfall "zwei Ebenen ueber dem Ablageort",
# geprueft nur auf config/plugins: in WSL gemessen (25.09.2026,
# Pruefung-Matter2Lox-0.9.29, Fall W5) legte das Skript in einem fremden
# Baum ohne general.json die Marke an und kopierte dort die Konfiguration.
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
    echo "<FAIL> Es wurde NICHTS gesichert. Bitte das Update abbrechen und melden."
    exit 1
fi

PDATA="$BASE/data/plugins/$PFOLDER"
PBIN="$BASE/bin/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"

FEHLER=0

# ------------------------------------------------------------ 0. Marke
# ALS ERSTES, noch vor dem Anhalten: "Aktualisierung laeuft".
#
# Der Installer legt die Cron-Datei rund eine Minute vor postinstall.sh
# neu an, und zwischen diesem Skript und dem Dienststart liegen ein
# 'python3 -m venv' und ein 'pip install websockets' - zusammen
# regelmaessig mehrere Minuten. In dieser Luecke sind Konfiguration,
# Sollmerker und PID-Datei geloescht. Der Waechter greift dort ins Leere
# (er verlangt data/plugins/<ordner>/soll_laufen), der Knopf "Dienst
# starten" der Oberflaeche aber nicht: am 18.09.2026 in WSL gemessen,
# startete er den Dienst mitten in der Installation, und der Waechter hielt
# ihn danach am Leben, weil 'start' den Sollmerker neu anlegt.
# Solange die Marke juenger als eine Stunde ist, startet kein Weg den
# Dienst; postinstall.sh entfernt sie nach dem Start, postupgrade.sh noch
# einmal zur Sicherheit, uninstall raeumt sie weg.
# Sie liegt NEBEN dem Datenordner, weil purge_installation den Ordner
# selbst loescht (Regeln/06).
mkdir -p "$BASE/data/plugins" 2>/dev/null
date +%s > "$MARKE" 2>/dev/null
if grep -qx '[0-9][0-9]*' "$MARKE" 2>/dev/null; then
    echo "<OK> Dienststart bis zum Ende der Installation gesperrt."
else
    echo "<WARNING> Die Marke $MARKE liess sich nicht anlegen - waehrend der"
    echo "<WARNING> Installation kann der Dienst ueber die Oberflaeche starten."
fi

# Ist die Nummer $1 ein Dienst dieses Plugins? Argumentweise: argv[0] ist
# ein Python, argv[1] ist genau unser Skript. Bis 0.9.25 wurde nur argv[1]
# verglichen; am 18.09.2026 in WSL gemessen, beendete dieses Skript damit
# einen fremden 'tail -f .../matter_dienst.py'. Dieselbe Probe steht in
# bin/dienst.sh und in uninstall/uninstall.
mt_ist_dienst() {
    [ -r "/proc/$1/cmdline" ] || return 1
    tr '\0' '\n' 2>/dev/null < "/proc/$1/cmdline" | {
        IFS= read -r a0 || exit 1
        IFS= read -r a1 || exit 1
        case "${a0##*/}" in python|python3|python3.*) ;; *) exit 1 ;; esac
        [ "$a1" = "$2" ]
    }
}

# ------------------------------------------------------------ 1. Dienst
# Erst den Sollmerker wegnehmen, dann anhalten - genau in dieser Reihenfolge.
# Bis 0.9.16 wurde nur die PID gekillt und der Merker blieb liegen. Zwischen
# diesem Skript und postinstall liegen ein 'python3 -m venv' und ein
# 'pip install websockets', zusammen regelmaessig laenger als eine Minute; der
# minuetliche Waechter fand in diesem Fenster den Merker und startete den
# ALTEN Dienst neu. Kurz darauf loeschte purge_installation PID-Datei und
# Merker - zurueck blieb ein Dienst ohne PID-Datei, fuer die Oberflaeche
# unsichtbar und ueber 'dienst.sh stop' nicht mehr erreichbar.
LIEF=0
if [ -x "$PBIN/dienst.sh" ]; then
    if "$PBIN/dienst.sh" status >/dev/null 2>&1; then
        LIEF=1
    fi
    "$PBIN/dienst.sh" stop >/dev/null 2>&1
else
    rm -f "$PDATA/soll_laufen" 2>/dev/null
fi

# Zweiter Griff, argumentweise: ein Dienst ohne PID-Datei (siehe oben) wird
# von dienst.sh nicht gefunden. Dieselbe Suche wie in uninstall/uninstall.
SKRIPT="$PBIN/matter_dienst.py"
# Der Dienst gehoert loxberry. Gibt es den Benutzer nicht (Pruefstand),
# gilt der eigene - sonst faende die Suche gar nichts.
DIENST_UID=$(id -u loxberry 2>/dev/null || id -u)
for D in /proc/[0-9]*; do
    P=$(basename "$D")
    [ -r "$D/cmdline" ] || continue
    # Nur Prozesse des Dienstbenutzers ansehen; fremde Nummern werden gar
    # nicht erst geprueft.
    [ "$(stat -c %u "$D" 2>/dev/null)" = "$DIENST_UID" ] || continue
    if mt_ist_dienst "$P" "$SKRIPT"; then
        LIEF=1
        kill "$P" 2>/dev/null
        for i in 1 2 3 4 5; do
            mt_ist_dienst "$P" "$SKRIPT" || break
            sleep 1
        done
        # Vor dem harten Signal erneut ARGUMENTWEISE pruefen, nicht nur
        # "kill -0": die Nummer kann inzwischen ein anderer Prozess tragen.
        # Bis 0.9.28 stand hier ein blankes "kill -9" (in WSL gemessen
        # 25.09.2026, Pruefung-Matter2Lox-0.9.29, Fall D1).
        if mt_ist_dienst "$P" "$SKRIPT"; then
            kill -9 "$P" 2>/dev/null
            sleep 1
        fi
        if mt_ist_dienst "$P" "$SKRIPT"; then
            echo "<WARNING> Ein Dienst ohne PID-Datei laeuft weiter (PID $P)."
            FEHLER=1
        else
            echo "<INFO> Ein Dienst ohne PID-Datei wurde beendet (PID $P)."
        fi
    fi
done
rm -f "$PDATA/dienst.pid" 2>/dev/null
[ "$LIEF" -eq 1 ] && echo "<OK> Laufender Dienst angehalten." \
                  || echo "<INFO> Es lief kein Dienst."

# Merken, ob er lief - NEBEN dem Ordner, sonst ist der Merker gleich wieder
# weg. postinstall.sh startet danach wieder, wenn diese Marke da ist.
rm -f "$BASE/data/plugins/$PFOLDER.lief" 2>/dev/null
if [ "$LIEF" -eq 1 ]; then
    : > "$BASE/data/plugins/$PFOLDER.lief" 2>/dev/null
fi

# ----------------------------------------------------- 2. Konfiguration
CF="$PCONFIG/matter2lox.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"
# Gesichert wird nach INHALT, nie nach Groesse oder blossem Dasein. Dieselbe
# Datei ist die Zweitschrift der Oberflaeche (mt_paths()['sicherung']), und
# die schreibt mt_config_speichern() nur mit Aktionstoken. Bis 0.9.28
# kopierte dieses Skript die Konfiguration bedingungslos darueber - stand sie
# auf "{}" oder war sie beschaedigt, war die gute Zweitschrift mit Token,
# WLAN-Passwort und Thread-Dataset beim Upgrade fort (in WSL gemessen
# 25.09.2026, Pruefung-Matter2Lox-0.9.29, Fall K1).
# Stufe 2 = JSON-Objekt mit nicht leerem aktionstoken, 1 = JSON-Objekt mit
# mindestens einem Eintrag, 0 = nichts Brauchbares. Die Sicherung wird nur
# durch einen Stand ersetzt, der mindestens so viel traegt wie sie selbst.
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
if [ -f "$CF" ]; then
    CF_STUFE=$(mt_inhalt "$CF")
    BK_STUFE=0
    [ -f "$BK" ] && BK_STUFE=$(mt_inhalt "$BK")
    if [ "$CF_STUFE" -ge 1 ] && [ "$CF_STUFE" -ge "$BK_STUFE" ]; then
        if cp -p "$CF" "$BK"; then
            chmod 600 "$BK"
            echo "<OK> Konfiguration gesichert: $PFOLDER.backup.json"
        else
            echo "<FAIL> Die Konfiguration liess sich NICHT sichern ($CF)."
            FEHLER=1
        fi
    elif [ "$BK_STUFE" -ge 1 ]; then
        echo "<INFO> Die Konfiguration traegt weniger als die vorhandene Sicherung"
        echo "<INFO> (kein Aktionstoken, leer oder nicht lesbar) - die Sicherung"
        echo "<INFO> $PFOLDER.backup.json bleibt unveraendert und wird nach dem Update zurueckgespielt."
    else
        echo "<INFO> Die Konfiguration ist leer oder nicht lesbar, eine brauchbare Sicherung gibt es nicht - nichts gesichert."
    fi
else
    echo "<INFO> Keine Konfiguration vorhanden - nichts zu sichern."
fi

# ------------------------------------------- 3. Fabric und Zertifikate
# Der Umzug geschieht mit mv, nicht mit cp: eine Fabric kann viele Megabyte
# gross sein, und auf der SD-Karte ist Platz nicht selbstverstaendlich.
# Gibt es das Ziel schon (zweites Update), bleibt es unangetastet - dann ist
# der Umzug beim ersten Update auf 0.9.17 bereits gelaufen.
FABALT="$PDATA/matter"
FABNEU="$BASE/data/plugins/$PFOLDER.matter"
if [ -d "$FABALT" ]; then
    if [ -d "$FABNEU" ]; then
        echo "<INFO> Fabric liegt bereits am neuen Ort. Der alte Ordner"
        echo "<INFO> ($FABALT) wird mit dem Datenordner entfernt."
    elif mv "$FABALT" "$FABNEU"; then
        chmod 700 "$FABNEU" 2>/dev/null
        echo "<OK> Matter-Fabric an den neuen Ort gebracht: $PFOLDER.matter"
        echo "<INFO> Sie liegt ab jetzt NEBEN dem Datenordner und ueberlebt"
        echo "<INFO> damit jedes weitere Update. Bis 0.9.16 lag sie darin und"
        echo "<INFO> wurde bei jedem Update geloescht."
        echo "<INFO> WICHTIG: Der Container zeigt noch auf den alten Pfad."
        echo "<INFO> Nach dem Update im Reiter Einstellungen einmal"
        echo "<INFO> 'Container entfernen' und dann 'Container anlegen'"
        echo "<INFO> druecken. Die Geraete bleiben dabei angelernt."
    else
        echo "<FAIL> Die Matter-Fabric liess sich NICHT umziehen."
        echo "<FAIL> Sie liegt in $FABALT und wird vom Installer geloescht."
        echo "<FAIL> Bitte das Update abbrechen und den Ordner von Hand sichern."
        FEHLER=1
    fi
else
    echo "<INFO> Kein alter Fabric-Ordner vorhanden."
fi

# ------------------------------------------------- 4. Geraetenummern
# Sie sind eine ADRESSE: sie stehen in den virtuellen Eingaengen der
# Loxone-Projektdatei (MATTER_3_...), in den MQTT-Themen (geraet3/...) und in
# den Endpunktadressen (&geraet=3). Ging die Datei verloren, entstanden sie
# neu aus der sortierten Knotenliste - und war zwischenzeitlich ein Geraet
# aus der Fabric gefallen, zeigte danach jede Adresse auf ein anderes Geraet.
NRALT="$PDATA/nummern.json"
NRNEU="$BASE/data/plugins/$PFOLDER.nummern.json"
if [ -f "$NRALT" ] && [ ! -f "$NRNEU" ]; then
    if cp -p "$NRALT" "$NRNEU"; then
        echo "<OK> Geraetenummern gesichert: $PFOLDER.nummern.json"
    else
        echo "<FAIL> Die Geraetenummern liessen sich NICHT sichern."
        echo "<FAIL> Nach dem Update koennen sich die Adressen verschieben."
        FEHLER=1
    fi
elif [ -f "$NRNEU" ]; then
    echo "<INFO> Geraetenummern liegen bereits am neuen Ort."
else
    echo "<INFO> Keine Geraetenummern vorhanden (noch kein Geraet angelernt)."
fi

# ------------------------------------------------------------- Schluss
# Die Erfolgsmeldung haengt an dem, was wirklich geschehen ist. Bis 0.9.16
# stand hier ein unbedingtes "<OK> preupgrade abgeschlossen".
if [ "$FEHLER" -eq 0 ]; then
    echo "<OK> preupgrade abgeschlossen. Fabric, Geraetenummern und"
    echo "<OK> Konfiguration liegen neben dem Plugin-Ordner und ueberstehen"
    echo "<OK> das Abraeumen durch den Installer."
    exit 0
fi
echo "<FAIL> preupgrade mit Beanstandungen beendet - siehe oben."
exit 1
