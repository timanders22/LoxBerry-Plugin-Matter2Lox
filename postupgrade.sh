#!/bin/bash
# Matter to Loxone - postupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Dies ist beim Upgrade das LETZTE Hakenskript, das LoxBerry ruft
# (Reihenfolge nach Regeln/06: preroot, preinstall, preupgrade,
# postinstall, postupgrade, postroot - ein postroot.sh fuehrt diese Linie
# nicht).
#
# I5 (Durchgang 30.09.2026): dieses Skript ruft postinstall.sh NICHT mehr
# auf. Der Installer hat es beim Upgrade schon ausgefuehrt (plugininstall.pl
# :1305 postinstall, :1330 postupgrade). Bis 0.9.30 lief es hier ein zweites
# Mal: venv-Pruefung und pip doppelt, und das Protokoll widersprach sich
# binnen Sekunden - "wurde wieder gestartet", dann "lief vorher nicht"
# (gemessen, Bericht installer I5). Seit I1 saehe ein zweiter Lauf ausserdem
# keine Marke mehr und hielte das Upgrade fuer eine Neuinstallation.
#
# Hier faellt nur noch die Marke "Aktualisierung laeuft", als Fangnetz:
# postinstall.sh entfernt sie selbst (nach dem Dienststart und per trap).
if [ -n "${5:-}" ] && [ -d "${5:-}" ]; then
    MARKE="$5/data/plugins/${3:-matter2lox}.upgrade_laeuft"
elif [ -n "${LBHOMEDIR:-}" ] && [ -d "${LBHOMEDIR:-}" ]; then
    MARKE="$LBHOMEDIR/data/plugins/${3:-matter2lox}.upgrade_laeuft"
else
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt - die Marke 'Aktualisierung laeuft' wurde nicht geprueft."
    exit 0
fi
if [ -f "$MARKE" ]; then
    rm -f "$MARKE" 2>/dev/null && echo "<INFO> Die Marke 'Aktualisierung laeuft' lag noch und wurde entfernt."
fi
exit 0
