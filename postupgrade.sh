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
if ! mt_wurzel "$3" "$5"; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt oder Ordnername unzulaessig - die Marke 'Aktualisierung laeuft' wurde nicht geprueft."
    exit 0
fi
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -f "$MARKE" ]; then
    rm -f "$MARKE" 2>/dev/null && echo "<INFO> Die Marke 'Aktualisierung laeuft' lag noch und wurde entfernt."
fi
exit 0
