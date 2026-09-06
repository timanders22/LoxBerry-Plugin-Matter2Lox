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

# Wurzelbestimmung mit Rueckfall - wie in postinstall.sh und uninstall.
# Bis 0.9.16 fehlte er hier als einzigem der drei Skripte; waren $5 und
# LBHOMEDIR leer, wurde aus dem Pfad "/config/plugins/...", die Sicherung
# unterblieb, und das Skript meldete trotzdem Erfolg.
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ]; then
    echo "<FAIL> Der LoxBerry-Wurzelordner liess sich nicht bestimmen."
    echo "<FAIL> Es wurde NICHTS gesichert. Bitte das Update abbrechen und melden."
    exit 1
fi

PDATA="$BASE/data/plugins/$PFOLDER"
PBIN="$BASE/bin/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"

FEHLER=0

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
for D in /proc/[0-9]*; do
    P=$(basename "$D")
    [ -r "$D/cmdline" ] || continue
    if tr '\0' '\n' < "$D/cmdline" 2>/dev/null | grep -qxF "$SKRIPT"; then
        LIEF=1
        kill "$P" 2>/dev/null
        for i in 1 2 3 4 5; do
            kill -0 "$P" 2>/dev/null || break
            sleep 1
        done
        kill -9 "$P" 2>/dev/null
        echo "<INFO> Ein Dienst ohne PID-Datei wurde beendet (PID $P)."
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
if [ -f "$CF" ]; then
    if cp -p "$CF" "$BK"; then
        chmod 600 "$BK"
        echo "<OK> Konfiguration gesichert: $PFOLDER.backup.json"
    else
        echo "<FAIL> Die Konfiguration liess sich NICHT sichern ($CF)."
        FEHLER=1
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
