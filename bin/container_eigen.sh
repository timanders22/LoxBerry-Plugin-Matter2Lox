#!/bin/bash
# Matter to Loxone - ist dieser Container der eigene?
#
# Aufruf:  bash container_eigen.sh <ordner> <name> <eigener_container 0|1>
# Ausgabe: EINE Zeile - der Grund (eigen) bzw. was gefunden wurde (fremd).
# Rueckgabe:
#   0  eigen: Label de.loxberry.plugin.folder=<ordner>, oder Altbestand ohne
#      Label bei eigener_container=1 mit einem Abbild des Matter-Servers
#      (python-matter-server bzw. matterjs-server)
#   1  fremd: es gibt ihn, aber er gehoert nicht zu diesem Plugin
#   2  es gibt keinen Container dieses Namens
#   3  nicht zu klaeren: kein Docker, keine Antwort, Name nicht plausibel -
#      der Aufrufer faellt geschlossen aus und fasst NICHTS an
#
# EINE Stelle fuer die Deinstallation (uninstall/uninstall) und fuer die
# Container-Knoepfe der Oberflaeche (mt_container_eigen() in mt_lib.php).
# Bis 0.9.32 stand die Pruefung nur in uninstall; die Knoepfe "anhalten",
# "neu starten" und "entfernen" fassten jeden Container mit dem eingestellten
# Namen an - auch einen eigenen Matter-Server des Anwenders, den das Plugin
# nur ueber seine Adresse benutzt (Verbesserung Matter2Lox-a1, Entscheidung
# 11 mit Nr. 9 sinngemaess). Bauart docker-ng 1.3.9 (Label statt Name).
#
# Liest nur (docker inspect), aendert nichts, schreibt nichts.
ORD="${1:-}"
NAME="${2:-}"
EIGEN="${3:-}"
case "$ORD" in
    [A-Za-z0-9]*) : ;;
    *) echo "Pluginordner '$ORD' sieht nicht plausibel aus"; exit 3 ;;
esac
case "$NAME" in
    [A-Za-z0-9]*) : ;;
    *) echo "Containername '$NAME' sieht nicht plausibel aus"; exit 3 ;;
esac
if ! command -v docker >/dev/null 2>&1; then
    echo "Docker ist nicht installiert"
    exit 3
fi
FEHL=$(timeout -k 5 30 docker inspect --type container -- "$NAME" 2>&1 >/dev/null)
RC=$?
if [ "$RC" -ne 0 ]; then
    case "$FEHL" in
        *"No such"*|*"no such"*) echo "Kein Container namens '$NAME' vorhanden"; exit 2 ;;
    esac
    # Keine Antwort (124/137), keine Rechte am Docker-Dienst, anderes: nicht
    # zu klaeren - dann wird nichts angefasst.
    echo "Docker antwortet nicht verwertbar (Rueckgabe $RC): $(printf '%s' "$FEHL" | head -n 1 | cut -c1-160)"
    exit 3
fi
LABEL=$(timeout -k 5 30 docker inspect --type container -f '{{index .Config.Labels "de.loxberry.plugin.folder"}}' -- "$NAME" 2>/dev/null)
[ "$LABEL" = "<no value>" ] && LABEL=""
ABBILD=$(timeout -k 5 30 docker inspect --type container -f '{{.Config.Image}}' -- "$NAME" 2>/dev/null)
if [ "$EIGEN" = "0" ]; then
    echo "eigener_container=0: in den Einstellungen steht, dass das Plugin keinen eigenen Matter-Server betreibt (Label '${LABEL:-keines}', Abbild '${ABBILD:-unbekannt}')"
    exit 1
fi
if [ -n "$LABEL" ] && [ "$LABEL" = "$ORD" ]; then
    echo "Label de.loxberry.plugin.folder=$ORD"
    exit 0
fi
if [ "$EIGEN" = "1" ] && [ -z "$LABEL" ]; then
    case "$ABBILD" in
        *python-matter-server*|*matterjs-server*)
            echo "Altbestand ohne Label, eigener Container, Abbild $ABBILD"
            exit 0 ;;
    esac
fi
echo "traegt nicht das Label de.loxberry.plugin.folder=$ORD und ist kein Altbestand dieses Plugins (Label '${LABEL:-keines}', Abbild '${ABBILD:-unbekannt}')"
exit 1
