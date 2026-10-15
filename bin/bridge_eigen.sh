#!/bin/bash
# Matter to Loxone 0.9.42 - ist dieser Container die eigene Matter-Bridge?
#
# Aufruf:  bash bridge_eigen.sh <ordner> <name>
#   <ordner>  Pluginordner (Name, kein Pfad), z. B. matter2lox
#   <name>    Containername, z. B. matter2lox-bridge
# Umgebung: MT_DOCKER_FRIST = Frist je docker-Aufruf in Sekunden (Vorgabe 30).
# Ausgabe: EINE Zeile - der Grund (eigen) bzw. was gefunden wurde.
# Rueckgabe:
#   0  eigen: Label de.loxberry.plugin.bridge=<ordner>
#   1  fremd: es gibt ihn, aber er traegt dieses Label nicht
#   2  es gibt keinen Container dieses Namens
#   3  nicht zu klaeren - der Aufrufer faellt geschlossen aus
#
# EIGENES Label, nicht de.loxberry.plugin.folder= (Befund B1, Entwurf 1.5):
# mit dem Label des Matter-Servers zaehlte die Bridge in mt_container_andere(),
# und "Einstellungen uebernehmen" nahme sie als den abzuloesenden Server.
# Kein Altbestand ohne Label: die Bridge gibt es erst seit 0.9.42. Ein
# fremder matterbridge-Container des Anwenders (auch unter demselben Namen)
# bleibt damit immer unberuehrt.
# Dieselbe Regel steht in uninstall/uninstall (Aenderungen an BEIDEN Stellen).
# Liest nur (docker inspect), aendert nichts, schreibt nichts.
ORD="${1:-}"
NAME="${2:-}"
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
    echo "Docker antwortet nicht verwertbar (Rueckgabe $RC): $(printf '%s' "$FEHL" | head -n 1 | cut -c1-160)"
    exit 3
fi
LABEL=$(timeout -k 2 "$FRIST" docker inspect --type container -f '{{index .Config.Labels "de.loxberry.plugin.bridge"}}' -- "$NAME" 2>/dev/null)
[ "$LABEL" = "<no value>" ] && LABEL=""
if [ -n "$LABEL" ] && [ "$LABEL" = "$ORD" ]; then
    echo "Label de.loxberry.plugin.bridge=$ORD"
    exit 0
fi
ABBILD=$(timeout -k 2 "$FRIST" docker inspect --type container -f '{{.Config.Image}}' -- "$NAME" 2>/dev/null)
echo "traegt nicht das Label de.loxberry.plugin.bridge=$ORD (Label '${LABEL:-keines}', Abbild '${ABBILD:-unbekannt}') - wird nicht angefasst"
exit 1
