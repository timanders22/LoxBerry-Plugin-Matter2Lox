#!/bin/bash
# Matter to Loxone - ist dieser Container der eigene?
#
# Aufruf:  bash container_eigen.sh <ordner> <name> <eigener_container 0|1> [<lbhome>]
#   <ordner>  Pluginordner (Name, kein Pfad), z. B. matter2lox
#   <name>    Containername
#   <eigener_container>  der Haken aus der Konfiguration (0 oder 1)
#   <lbhome>  0.9.35 (Nr. 3), wahlweise: die LoxBerry-Wurzel. Fehlt er, gilt
#             $LBHOMEDIR, sonst die Wurzel aus dem eigenen Ablageort
#             (<wurzel>/bin/plugins/<ordner>/container_eigen.sh). Nur fuer
#             den Altbestand ohne Label gebraucht.
# Umgebung: MT_DOCKER_FRIST = Frist je docker-Aufruf in Sekunden (Vorgabe 30).
# Ausgabe: EINE Zeile - der Grund (eigen) bzw. was gefunden wurde (fremd).
# Rueckgabe:
#   0  eigen: Label de.loxberry.plugin.folder=<ordner> (gleich, wie der Haken
#      steht), oder Altbestand ohne Label bei eigener_container=1 mit einem
#      Abbild des Matter-Servers (python-matter-server bzw. matterjs-server)
#      UND /data aus dem Fabric-Pfad dieses Plugins
#      (<wurzel>/data/plugins/<ordner>.matter oder der alte Pfad
#      <wurzel>/data/plugins/<ordner>/matter)
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
# 0.9.35 (Nr. 3):
#  - Das Label wird VOR dem Haken geprueft: ein Container mit dem Label
#    dieses Plugins ist immer der eigene, auch bei eigener_container=0. Bis
#    0.9.34 war er dann "fremd", und nach dem Abhaken liess sich der eigene,
#    nicht mehr gebrauchte Container ueber die Oberflaeche weder anhalten noch
#    entfernen.
#  - Ein Altbestand ohne Label gilt nur noch als eigen, wenn sein /data aus
#    dem Fabric-Pfad dieses Plugins kommt. Bis 0.9.34 genuegte das Abbild -
#    ein eigener Matter-Server des Anwenders (etwa fuer Home Assistant) unter
#    dem alten Vorgabenamen "matter-server" galt damit als eigen.
#
# Liest nur (docker inspect), aendert nichts, schreibt nichts.
ORD="${1:-}"
NAME="${2:-}"
EIGEN="${3:-}"
WURZEL="${4:-}"
FRIST="${MT_DOCKER_FRIST:-30}"
case "$FRIST" in
    ''|*[!0-9]*) FRIST=30 ;;
esac
[ "$FRIST" -ge 1 ] 2>/dev/null || FRIST=1
case "$ORD" in
    [A-Za-z0-9]*) : ;;
    *) echo "Pluginordner '$ORD' sieht nicht plausibel aus"; exit 3 ;;
esac
case "$ORD" in
    */*|*..*) echo "Pluginordner '$ORD' sieht nicht plausibel aus"; exit 3 ;;
esac
case "$NAME" in
    [A-Za-z0-9]*) : ;;
    *) echo "Containername '$NAME' sieht nicht plausibel aus"; exit 3 ;;
esac
if ! command -v docker >/dev/null 2>&1; then
    echo "Docker ist nicht installiert"
    exit 3
fi
FEHL=$(timeout -k 2 "$FRIST" docker inspect --type container -- "$NAME" 2>&1 >/dev/null)
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
LABEL=$(timeout -k 2 "$FRIST" docker inspect --type container -f '{{index .Config.Labels "de.loxberry.plugin.folder"}}' -- "$NAME" 2>/dev/null)
[ "$LABEL" = "<no value>" ] && LABEL=""
# 0.9.35 (Nr. 3): das Label zuerst - unabhaengig vom Haken.
if [ -n "$LABEL" ] && [ "$LABEL" = "$ORD" ]; then
    echo "Label de.loxberry.plugin.folder=$ORD"
    exit 0
fi
ABBILD=$(timeout -k 2 "$FRIST" docker inspect --type container -f '{{.Config.Image}}' -- "$NAME" 2>/dev/null)
if [ "$EIGEN" = "0" ]; then
    echo "eigener_container=0 und nicht das Label dieses Plugins: in den Einstellungen steht, dass das Plugin keinen eigenen Matter-Server betreibt (Label '${LABEL:-keines}', Abbild '${ABBILD:-unbekannt}')"
    exit 1
fi
if [ "$EIGEN" = "1" ] && [ -z "$LABEL" ]; then
    case "$ABBILD" in
        *python-matter-server*|*matterjs-server*)
            # Die Wurzel: Argument, sonst LBHOMEDIR, sonst der eigene Ablageort.
            if [ -z "$WURZEL" ]; then
                WURZEL="${LBHOMEDIR:-}"
            fi
            if [ -z "$WURZEL" ]; then
                HIER=$(cd "$(dirname "$0")" 2>/dev/null && pwd -P)
                if [ -n "$HIER" ] && [ "$(basename "$(dirname "$HIER")")" = "plugins" ]; then
                    WURZEL=$(dirname "$(dirname "$(dirname "$HIER")")")
                fi
            fi
            QUELLE=$(timeout -k 2 "$FRIST" docker inspect --type container -f '{{range .Mounts}}{{if eq .Destination "/data"}}{{.Source}}{{end}}{{end}}' -- "$NAME" 2>/dev/null)
            if [ -z "$WURZEL" ]; then
                echo "Altbestand ohne Label mit Abbild $ABBILD, aber die LoxBerry-Wurzel ist unbekannt - die Herkunft von /data ($QUELLE) laesst sich nicht pruefen"
                exit 3
            fi
            WURZEL="${WURZEL%/}"
            NEU="$WURZEL/data/plugins/$ORD.matter"
            ALT="$WURZEL/data/plugins/$ORD/matter"
            QECHT=$(readlink -f -- "$QUELLE" 2>/dev/null)
            for KANDIDAT in "$NEU" "$ALT"; do
                KECHT=$(readlink -f -- "$KANDIDAT" 2>/dev/null)
                if [ -n "$QUELLE" ] && { [ "$QUELLE" = "$KANDIDAT" ] || { [ -n "$QECHT" ] && [ "$QECHT" = "$KECHT" ]; }; }; then
                    echo "Altbestand ohne Label, eigener Container, Abbild $ABBILD, /data aus $QUELLE"
                    exit 0
                fi
            done
            echo "Altbestand ohne Label mit Abbild $ABBILD, aber /data kommt aus '${QUELLE:-unbekannt}', nicht aus $NEU - das ist nicht der Container dieses Plugins"
            exit 1 ;;
    esac
fi
echo "traegt nicht das Label de.loxberry.plugin.folder=$ORD und ist kein Altbestand dieses Plugins (Label '${LABEL:-keines}', Abbild '${ABBILD:-unbekannt}')"
exit 1
