#!/bin/bash
# Matter to Loxone - preupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Der Matter-Container wird NICHT angefasst: in seinem Datenordner liegen die
# Fabric und die Zertifikate. Wer den loescht, muss jedes Geraet neu anlernen.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-matter2lox}"
BASE="${ARGV5:-$LBHOMEDIR}"

PID="$BASE/data/plugins/$PFOLDER/dienst.pid"
if [ -f "$PID" ]; then
    kill "$(cat "$PID")" 2>/dev/null || true
    sleep 2
    kill -9 "$(cat "$PID")" 2>/dev/null || true
    rm -f "$PID"
    echo "<INFO> Laufender Dienst angehalten."
fi

CF="$BASE/config/plugins/$PFOLDER/matter2lox.json"
if [ -f "$CF" ]; then
    cp -p "$CF" "$BASE/config/plugins/$PFOLDER.backup.json"
    chmod 600 "$BASE/config/plugins/$PFOLDER.backup.json"
fi
echo "<OK> preupgrade abgeschlossen. Der Matter-Container bleibt unberuehrt."
exit 0
