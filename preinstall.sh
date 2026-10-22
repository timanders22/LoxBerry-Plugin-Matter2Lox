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
# ---- Wurzelblock (M3, seit 0.9.44; in preinstall, postinstall, preupgrade und postupgrade woertlich gleich) ----
# Bauform Alexa NG 1.0.6 (ax_ist_wurzel) und Intercom 2.2.23 (ic_wurzel). Ordner aus $3 mit Schranke ('' . .. und
# alles mit / oder ..); Wurzel aus $5, sonst LBHOMEDIR, sonst vom Ablageort aufwaerts gesucht (Regeln/06). Eine
# Wurzel gilt nur mit config/plugins, data/plugins UND config/system/general.json - ein Rest alter Prueflaeufe hat
# die dritte nie. Bis 0.9.43 prueften postinstall und preupgrade $5/LBHOMEDIR nur auf config/plugins, postupgrade
# nur -d, und preinstall wies den Ordnernamen "." nicht ab (Pruefbericht M3: mit "." trafen chown -R und chmod
# ganz data/plugins).
# mt_wurzel setzt PFOLDER und BASE; Rueckgabe 0 = erkannt, 1 = Ordnername unzulaessig, 2 = keine LoxBerry-Wurzel.
mt_ist_wurzel() {
    [ -n "$1" ] && [ -d "$1/config/plugins" ] && [ -d "$1/data/plugins" ] && [ -f "$1/config/system/general.json" ]
}
mt_wurzel_suchen() {
    v=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd)
    i=0
    while [ -n "$v" ] && [ "$v" != "/" ] && [ $i -lt 8 ]; do
        if mt_ist_wurzel "$v"; then printf '%s\n' "$v"; return 0; fi
        v=$(dirname "$v"); i=$((i + 1))
    done
    return 1
}
mt_wurzel() {
    PFOLDER="${1:-matter2lox}"
    case "$PFOLDER" in
        ''|.|..|*/*|*..*) return 1 ;;
    esac
    BASE=""
    for MT_K in "$2" "$LBHOMEDIR"; do
        if mt_ist_wurzel "$MT_K"; then BASE="$MT_K"; break; fi
    done
    [ -n "$BASE" ] || BASE=$(mt_wurzel_suchen)
    [ -n "$BASE" ] || return 2
    return 0
}
# ---- Ende Wurzelblock ----
mt_wurzel "$3" "$5"
case $? in
    1) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
    2) echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('${5:-$LBHOMEDIR}') - nichts beiseitegelegt."; exit 0 ;;
esac
[ -f "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" ] && exit 0

BEISEITE=""
FEST=""
# 0.9.42: dazu die Zweitschriften der Bridge (Loxone-Zugang der Bridge).
for ZIEL in "$BASE/config/plugins/$PFOLDER.backup.json" \
            "$BASE/config/plugins/$PFOLDER.backup.json.kaputt" \
            "$BASE/config/plugins/$PFOLDER.backup.bridge.json" \
            "$BASE/config/plugins/$PFOLDER.backup.bridge_zugang.json"; do
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
# 0.9.42: Kopplungsdaten einer frueheren Matter-Bridge werden weiterbenutzt.
BRD="$BASE/data/plugins/$PFOLDER.bridge"
if [ -d "$BRD" ] && [ -n "$(ls -A "$BRD" 2>/dev/null)" ]; then
    WEITER="$WEITER $BRD"
fi
# 0.9.35 (E7): die Deinstallation laesst eine letzte Sicherung der Fabric
# liegen. Sie wird nicht von selbst eingespielt; der Hinweis nennt sie.
SICHER="$BASE/data/plugins/$PFOLDER.fabric_sicherungen"
if [ -d "$SICHER" ] && [ -n "$(ls -A "$SICHER" 2>/dev/null)" ]; then
    echo "<INFO> Es liegen Sicherungen einer frueheren Matter-Fabric vor ($SICHER). Zurueckspielen: Reiter Einstellungen, Fuer Fortgeschrittene, Fabric wiederherstellen."
fi
if [ -n "$BEISEITE" ] || [ -n "$FEST" ] || [ -n "$WEITER" ]; then
    T="<WARNING> Neuinstallation: Einstellungen und Zugangsdaten einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && T="$T Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && T="$T Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    [ -n "$WEITER" ] && T="$T Liegen noch da und werden weiterbenutzt (Matter-Fabric bzw. Geraetenummern einer frueheren Installation):$WEITER"
    echo "$T"
fi
exit 0
