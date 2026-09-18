#!/bin/bash
# Matter to Loxone - postupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Dies ist beim Upgrade das LETZTE Hakenskript, das LoxBerry ruft
# (Reihenfolge nach Regeln/06: preroot, preinstall, preupgrade,
# postinstall, postupgrade, postroot - ein postroot.sh fuehrt diese Linie
# nicht). Deshalb faellt hier die Marke "Aktualisierung laeuft", die
# preupgrade.sh als Erstes gelegt hat.
#
# postinstall.sh entfernt sie bereits selbst, unmittelbar NACH dem
# Dienststart. Diese Zeile ist der Fangnetz-Fall: steigt postinstall.sh
# vorher aus (kein 64-Bit-System, kein Python, websockets nicht
# installierbar), bliebe die Marke sonst bis zu einer Stunde liegen und
# der Knopf "Dienst starten" meldete die ganze Zeit, es laufe eine
# Aktualisierung.
SELF=$(cd "$(dirname "$0")" && pwd)
MARKE=""
if [ -n "${5:-}" ] && [ -d "${5:-}" ]; then
    MARKE="$5/data/plugins/${3:-matter2lox}.upgrade_laeuft"
elif [ -n "${LBHOMEDIR:-}" ] && [ -d "${LBHOMEDIR:-}" ]; then
    MARKE="$LBHOMEDIR/data/plugins/${3:-matter2lox}.upgrade_laeuft"
fi

if [ -x "$SELF/postinstall.sh" ]; then
    "$SELF/postinstall.sh" "$@"
    RC=$?
    [ -n "$MARKE" ] && rm -f "$MARKE" 2>/dev/null
    exit $RC
fi
[ -n "$MARKE" ] && rm -f "$MARKE" 2>/dev/null
echo "<FAIL> postinstall.sh nicht gefunden - Upgrade unvollstaendig."
exit 1
