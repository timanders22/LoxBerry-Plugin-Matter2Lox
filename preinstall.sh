#!/bin/bash
# Matter to Loxone - preinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Neu in 0.9.30 (I1, Entscheidung 1 vom 29.09.2026), Bauform AudiConnect
# 0.9.22. Der Installer ruft dieses Skript bei JEDEM Einbau auf, nach dem
# Aufraeumen der alten Fassung und VOR dem Kopieren von Konfiguration,
# Cron-Datei und Oberflaeche (sbin/plugininstall.pl: preupgrade :846, purge
# :874, preinstall :877, Cron :990, HTML :1066 -
# Geraet/2026-09-05/08_plugininstall.pl).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich; die 3600 s gelten nur fuer die Startsperre in
# bin/dienst.sh). Dann tut es nichts: die Zweitschrift braucht postinstall.sh.
#
# Ohne Marke ist es eine NEUINSTALLATION. Eine liegengebliebene Zweitschrift
# der Konfiguration (config/plugins/<ordner>.backup.json, samt einer
# .backup.json.kaputt) geht nach <name>.alt, der Startmerker
# data/plugins/<ordner>.lief wird entfernt, gemeldet mit genau einer
# <WARNING>. Bis 0.9.30 spielte postinstall.sh sie ungefragt zurueck -
# Aktionstoken, WLAN-Passwort und Thread-Dataset einer frueheren Installation
# - startete den Dienst und meldete eine Aktualisierung (in WSL gemessen,
# Bericht installer I1, Fall N2). Die Selbstheilung der Bibliothek liest .alt
# nie; die Deinstallation raeumt es ab.
#
# Fabric (data/plugins/<ordner>.matter) und Geraetenummern
# (data/plugins/<ordner>.nummern.json) bleiben liegen und werden
# weiterbenutzt - ob sie bei einer Neuinstallation beiseite gehoeren, ist
# nicht entschieden (Baubericht "Offen"). Liegen sie da, nennt die WARNING sie
# ausdruecklich; bis 0.9.30 geschah das ohne jede Meldung.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-matter2lox}"
BASE="${ARGV5:-$LBHOMEDIR}"
# Wurzelsuche wie in den uebrigen Hakenskripten: ohne config/plugins,
# data/plugins UND config/system/general.json wird nichts angefasst.
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac
[ -f "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" ] && exit 0

BEISEITE=""
FEST=""
for ZIEL in "$BASE/config/plugins/$PFOLDER.backup.json" \
            "$BASE/config/plugins/$PFOLDER.backup.json.kaputt"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            [ -f "$ZIEL.alt" ] && [ ! -L "$ZIEL.alt" ] && chmod 600 "$ZIEL.alt" 2>/dev/null
            BEISEITE="$BEISEITE $ZIEL.alt"
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done
MERKER="$BASE/data/plugins/$PFOLDER.lief"
if [ -e "$MERKER" ]; then
    rm -f "$MERKER" && BEISEITE="$BEISEITE (Startmerker $MERKER entfernt)"
fi
WEITER=""
FAB="$BASE/data/plugins/$PFOLDER.matter"
if [ -d "$FAB" ] && [ -n "$(ls -A "$FAB" 2>/dev/null)" ]; then
    WEITER="$WEITER $FAB"
fi
[ -f "$BASE/data/plugins/$PFOLDER.nummern.json" ] && WEITER="$WEITER $BASE/data/plugins/$PFOLDER.nummern.json"
if [ -n "$BEISEITE" ] || [ -n "$FEST" ] || [ -n "$WEITER" ]; then
    T="<WARNING> Neuinstallation: Einstellungen und Zugangsdaten einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && T="$T Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && T="$T Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    [ -n "$WEITER" ] && T="$T Liegen noch da und werden weiterbenutzt (Matter-Fabric bzw. Geraetenummern einer frueheren Installation):$WEITER"
    echo "$T"
fi
exit 0
