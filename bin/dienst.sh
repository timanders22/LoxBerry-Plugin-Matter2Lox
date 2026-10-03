#!/bin/bash
# Matter to Loxone - Start, Stopp und Waechter des Abrufdienstes.
#
# Wurzel und Ordnername kommen aus der UMGEBUNG, solange sie etwas sagt
# ($LBHOMEDIR, $LBPPLUGINDIR); erst danach aus dem Ablageort - siehe
# "Wurzel und Ordnername" weiter unten. Nicht ueber LoxBerry::System: das
# leitet den Pluginordner aus dem Aufrufort ab; wird dieses Skript aus
# postinstall.sh oder aus dem Cron gestartet, kommt dort ueberall
# Leerstring zurueck - das Skript werkelt dann gegen /-Pfade und meldet
# trotzdem Erfolg.

# readlink -f loest Symlinks auf, BEVOR das Verzeichnis bestimmt wird.
# LoxBerry legt Daemons als Symlink unter system/daemons/plugins/ ab; von
# dort aufgerufen ergaebe dirname "$0" den Pfad .../system/daemons/plugins,
# der Pluginname waere buchstaeblich "plugins", und PID-Datei, Sollmerker
# und Logdatei landeten neben dem eigenen Ordner statt darin. Die
# Oberflaeche saehe den Dienst dann nie laufen, und der Waechter startete
# ihn im Minutentakt ein zweites Mal.

# ------------------------------------------------ Als loxberry laufen
#
# Wird dieses Skript als root aufgerufen, gehoerten PID-Datei, Sollmerker und
# Protokoll danach root. Die Oberflaeche laeuft als loxberry und koennte den
# Dienst anschliessend weder anhalten noch neu starten: sie darf die Dateien
# nicht mehr schreiben. Schlimmer noch, 'dienst.sh stop' meldet dann Erfolg -
# das kill scheitert, aber das rm der PID-Datei gelingt, weil das Verzeichnis
# loxberry gehoert. Der Dienst laeuft weiter und ist nur noch ueber die
# Prozessliste zu finden.
#
# Wann tritt das ein? Nicht im Regelfall: der LoxBerry-Cron startet die
# Skripte unter cron.01min als loxberry, und die Oberflaeche ruft ohne sudo
# auf. Der Zweig greift bei einem von Hand mit sudo abgesetzten Aufruf und
# bei einem Cronjob, den jemand nach /etc/cron.d gelegt hat.
# (Bis 0.9.16 standen hier zwei Saetze nebeneinander: der eine sagte, der
#  Cron laufe "je nach Ablage" als root, der andere, der Zweig sei "ohnehin
#  unerreichbar". Einer von beiden musste falsch sein. Unter welchem Benutzer
#  der Cron dieser Anlage startet, ist NICHT gemessen - deshalb steht hier
#  jetzt, wann der Zweig greift, und nicht, ob er es tut.)
#
# Der Abstieg geschieht EINMAL und bevor irgendetwas angelegt wird. exec,
# damit kein zusaetzlicher Prozess stehen bleibt. '-s /bin/bash' ausdruecklich:
# ohne das nimmt su die Login-Shell aus /etc/passwd. Steht dort nologin oder
# /bin/false, endet dieses Skript hier still und ohne Meldung - und weil es
# 'exec' ist, kaeme nicht einmal ein Rueckgabewert zurueck.
#
# Der Pruefausdruck ist woertlich uebernommen aus
# LoxBerry-Plugin-Dashboard-0.9.12, dort seit dem 16.08.2026 in Betrieb; der
# Erklaertext hier ist eigener.
#
# Was der Abstieg NICHT tut: Dateien reparieren, die schon root gehoeren. Auf
# einer Anlage, auf der der Schaden bereits eingetreten ist, hilft nur das
# chown darunter - und das darf nur laufen, solange wir noch root sind.

# ------------------------------------------------ Wurzel und Ordnername
#
# GELESEN, nicht geraten (Regeln/03: Stufe 1 ist $LBHOMEDIR; Regeln/06:
# Wurzelsuche mit config/system/general.json; Bestand-2026-09-18/klasse-H/
# Ergebnis.md, Bauart H1). Bis 0.9.26 stand hier
#     PNAME=$(basename "$SELF")
#     LBHOMEDIR=$(cd "$SELF/../../.." && pwd)
# und im root-Zweig darueber dieselbe Rechnung noch einmal, mit einem
# "chown -R" als root auf das Ergebnis. Ein gesetztes $LBHOMEDIR wurde dabei
# UEBERSCHRIEBEN. In WSL gemessen (18.09.2026, Pruefung-Matter2Lox-0.9.27,
# Faelle H4-H8 und R3-R5): ein "status" aus einem Pruefarchiv unter
# <Wurzel>/pruefung/<plugin>/bin legte in der LAUFENDEN Anlage
# data/plugins/bin und log/plugins/bin an, und derselbe Aufruf als root
# reichte "chown -R" genau diese beiden Pfade - aus einem ausgepackten
# Archiv Pfade ausserhalb jeder Anlage.
#
# Zwei Stufen fuer die Wurzel, in dieser Reihenfolge:
#   1. $LBHOMEDIR aus der Umgebung, wenn dort config/plugins und
#      data/plugins liegen,
#   2. aufwaerts suchen, bis ein Verzeichnis config/plugins, data/plugins
#      UND config/system/general.json traegt (der dritte Nachweis seit dem
#      Raumklima-Vorfall, Regeln/06).
# Danach NICHTS mehr. Bis 0.9.28 stand als dritte Stufe "drei Ebenen ueber
# dem Ablageort" - in einem fremden Baum ohne general.json wurde der damit
# doch Wurzel: in WSL gemessen (25.09.2026, Pruefung-Matter2Lox-0.9.29,
# Faelle W1, W2) legte "start" dort data/ und log/ an, "stop" loeschte dort
# soll_laufen. Ohne Wurzel wird jetzt gewarnt und nichts getan (Regeln/06,
# "ohne brauchbare Wurzel warnen statt vollziehen").
# "pwd -P" auf beiden Seiten: liegt die Wurzel hinter einem Verweis, muss
# der Vergleich unten zwei physische Pfade vergleichen (Fall H12).
SELF=$(cd "$(dirname "$(readlink -f "$0")")" && pwd -P)       # <home>/bin/plugins/<ordner>
mt_wurzel_suchen() {
    mt_v="$SELF"
    mt_i=0
    while [ -n "$mt_v" ] && [ "$mt_v" != "/" ] && [ "$mt_i" -lt 8 ]; do
        if [ -d "$mt_v/config/plugins" ] && [ -d "$mt_v/data/plugins" ] \
           && [ -f "$mt_v/config/system/general.json" ]; then
            echo "$mt_v"
            return 0
        fi
        mt_v=$(dirname "$mt_v")
        mt_i=$((mt_i + 1))
    done
    return 1
}
if [ -n "${LBHOMEDIR:-}" ] && [ -d "$LBHOMEDIR/config/plugins" ] \
   && [ -d "$LBHOMEDIR/data/plugins" ]; then
    LBHOMEDIR=$(cd "$LBHOMEDIR" && pwd -P)
else
    LBHOMEDIR=$(mt_wurzel_suchen) || LBHOMEDIR=""
fi
if [ -z "$LBHOMEDIR" ]; then
    echo "FEHLER: kein LoxBerry-Wurzelverzeichnis gefunden - \$LBHOMEDIR ist nicht gesetzt," >&2
    echo "FEHLER: und oberhalb von $SELF traegt keines config/plugins, data/plugins und" >&2
    echo "FEHLER: config/system/general.json. Es wurde nichts gestartet, angehalten oder angelegt." >&2
    exit 1
fi
# Der Ordnername kommt aus $LBPPLUGINDIR, sonst aus dem Ablageort. Am Geraet
# steht $LBPPLUGINDIR in keiner Cron-Schale (Regeln/03) - dann traegt der
# Ablageort, und bei einer regulaeren Installation ist das genau richtig.
PNAME="${LBPPLUGINDIR:-}"
[ -n "$PNAME" ] || PNAME=$(basename "$SELF")
PBIN="$LBHOMEDIR/bin/plugins/$PNAME"
# Laeuft dieses Skript wirklich AUS der Installation? Was schreibt (start,
# restart, waechter, das chown als root), faellt sonst geschlossen aus.
INSTALLIERT=0
[ "$SELF" = "$PBIN" ] && INSTALLIERT=1

if [ "$(id -u)" = "0" ] && id loxberry >/dev/null 2>&1; then
    # Nur aus der Installation, und nur auf die GELESENEN Pfade. Aus einem
    # Pruefarchiv oder einem ausgepackten Archiv wird nichts umgeschrieben -
    # der Abstieg selbst geschieht trotzdem.
    if [ "$INSTALLIERT" = "1" ]; then
        for R in "$LBHOMEDIR/data/plugins/$PNAME" \
                 "$LBHOMEDIR/log/plugins/$PNAME"; do
            [ -d "$R" ] && chown -R loxberry:loxberry "$R" 2>/dev/null
        done
    fi
    exec su -s /bin/bash loxberry -c "$(printf '%q ' "$0" "$@")"
fi

PDATA="$LBHOMEDIR/data/plugins/$PNAME"
PLOG="$LBHOMEDIR/log/plugins/$PNAME"
PCONFIG="$LBHOMEDIR/config/plugins/$PNAME"
PID="$PDATA/dienst.pid"
SOLL="$PDATA/soll_laufen"
LOGDATEI="$PLOG/matter2lox.log"
# Die Ausgabe des Startvorgangs. Siehe starten() - sie darf NICHT in die
# Logdatei gehen, die Python selbst rotiert.
STARTLOG="$PLOG/matter2lox.start.log"
# Aus dem GELESENEN bin-Ordner, nicht aus dem Ablageort: installiert ist das
# derselbe Ordner. Aus einem ausgepackten Archiv mit gesetzter Umgebung sieht
# "status" so den Dienst der Anlage (Fall H4) - gestartet wird von dort
# nichts (siehe INSTALLIERT).
SKRIPT="$PBIN/matter_dienst.py"
PY="$PBIN/venv/bin/python3"
# Die Marke "Aktualisierung laeuft". preupgrade.sh legt sie als Erstes an,
# postinstall.sh entfernt sie nach dem Dienststart. Sie liegt NEBEN dem
# Datenordner, weil purge_installation den Ordner selbst loescht
# (Regeln/06). Gelesen wird sie in marke_gilt().
MARKE="$LBHOMEDIR/data/plugins/$PNAME.upgrade_laeuft"
MEINE_UID=$(id -u)

# Angelegt wird erst beim START (und vom Waechter vor seiner Protokollzeile),
# nicht bei jedem Aufruf. Bis 0.9.26 stand hier "mkdir -p" auf oberster
# Ebene - auch "status" und "stop" legten damit Ordner an. In der
# Upgrade-Luecke legte schon ein "status" data/plugins/<ordner> wieder an
# (Fall H8), und "der Ordner ist da" sagte dort nichts mehr ueber eine
# gelungene Ruecksicherung aus.
ordner_anlegen() {
    mkdir -p "$PDATA" "$PLOG" 2>/dev/null
}

# Ein Schutz faellt geschlossen aus (CLAUDE.md 4): wer aus einem Pruefarchiv
# oder einem ausgepackten Archiv startet, schriebe in die laufende Anlage -
# unter einem Ordnernamen, den niemand gewollt hat.
nicht_installiert() {
    echo "FEHLER: dieses Skript liegt nicht unter $PBIN -"
    echo "FEHLER: aus einem ausgepackten Archiv wird nichts gestartet und nichts angehalten."
}

# Ist die Nummer $1 ein Dienst DIESES Plugins? Argumentweise, wie in
# laeuft(): argv[0] ist ein Python, argv[1] ist genau unser Skript. Ein
# Editor mit der Datei offen, ein "tail -f" darauf oder ein Dienst aus
# einem anderen Baum wird nie getroffen (gemessen 18.09.2026: preupgrade
# und uninstall beendeten bis 0.9.25 ein "tail -f matter_dienst.py").
ist_dienst() {
    [ -r "/proc/$1/cmdline" ] || return 1
    tr '\0' '\n' 2>/dev/null < "/proc/$1/cmdline" | {
        IFS= read -r a0 || exit 1
        IFS= read -r a1 || exit 1
        case "${a0##*/}" in python|python3|python3.*) ;; *) exit 1 ;; esac
        [ "$a1" = "$SKRIPT" ]
    }
}

# Alle eigenen Dienste, auch die ohne PID-Datei.
#
# Warum es diese Suche gibt: purge_installation loescht data/plugins/<ordner>/
# bei JEDEM Upgrade und damit die PID-Datei. Ein Dienst, der das ueberlebt hat,
# war fuer laeuft() danach unsichtbar - der Knopf "Dienst starten" legte einen
# ZWEITEN daneben (am 18.09.2026 in WSL gemessen: 2 Prozesse nach dem zweiten
# Start). Nur eigene Prozesse: die Nummern fremder Benutzer werden gar nicht
# erst angesehen.
dienste_suchen() {
    for d in /proc/[0-9]*; do
        [ "$(stat -c %u "$d" 2>/dev/null)" = "$MEINE_UID" ] || continue
        ist_dienst "${d#/proc/}" && echo "${d#/proc/}"
    done
    return 0
}

# Gilt die Marke? 0 = ja, es laeuft eine Aktualisierung, nichts starten.
#
# Nur eine Marke, die hoechstens eine Stunde alt ist, zaehlt: eine
# abgebrochene Installation darf den Dienst nicht fuer immer stilllegen.
# Aelter, mehr als 300 s aus der Zukunft oder unlesbar - sie gilt nicht.
# Die 300 s Vorlauf: die Uhr kann ein Stueck zurueckspringen, nachdem
# preupgrade.sh die Marke gesetzt hat; in WSL sprang sie gemessen bis 0,64 s
# zurueck, und eine eben geschriebene Marke galt dann kurz nicht
# (Pruefung-VolkswagenID-0.9.23, dort 300 s). Hier Fall M1 und M2.
# Die Uhr wird gemessen, nicht angenommen: liefert "date" nichts (unter Last
# kann ein fork scheitern), rechnete die Schale mit einer leeren Zeichenkette,
# das Alter wuerde negativ, die Bedingung fiele durch, und der Dienst startete
# mitten in der Aktualisierung. Ein Schutz faellt geschlossen aus (CLAUDE.md
# Punkt 4): ohne Uhr gilt die Marke. Vorbild: Chromecast4lox 1.3.11,
# cron/cron.05min und daemon/daemon.
marke_gilt() {
    [ "${MT_START_TROTZ_MARKE:-0}" = "1" ] && return 1
    [ -f "$MARKE" ] || return 1
    seit=$(cat "$MARKE" 2>/dev/null)
    case "$seit" in ''|*[!0-9]*) seit=0 ;; esac
    jetzt=$(date +%s 2>/dev/null)
    case "$jetzt" in ''|*[!0-9]*) jetzt="" ;; esac
    [ -z "$jetzt" ] && return 0
    alter=$(( jetzt - seit ))
    [ "$alter" -ge -300 ] && [ "$alter" -lt 3600 ] && return 0
    return 1
}

# C2 (Durchgang 30.09.2026): Startsperre. Bis 0.9.30 stand zwischen "laeuft
# er schon?" und "nohup" nichts, was einen zweiten Aufrufer haette aufhalten
# koennen: zwei gleichzeitige "start" (Knopf und postinstall) oder zwei
# Waechter derselben Minute (cron.01min holt nach dem Uhrsprung beim Booten
# Minuten nach) ergaben in WSL fuenf von fuenf Runden zwei Dienste (Bericht
# code C2). Gesperrt wird auf dieses Skript selbst (flock auf Deskriptor 8),
# mit Warten bis 15 s: der zweite Aufrufer wartet, bis der erste seinen Start
# samt Nachsehen hinter sich hat, und fragt DANACH, ob schon einer laeuft.
# Ein zweites "exec 8<" im selben Lauf gaebe die Sperre frei - daher der
# Merker MT_SPERRE_GEHALTEN (der Waechter sperrt und ruft starten()). Der
# Dienst erbt den Deskriptor NICHT (8<&- beim Start), sonst hielte er die
# Sperre, solange er laeuft, und kein Neustart kaeme mehr durch
# (Regeln/03, "Sperre vererbt sich an Kinder"). Ohne flock bleibt es beim
# Verhalten bis 0.9.30; der Dienst sperrt ausserdem selbst (dienst.lock,
# matter_dienst.py). Bauart Bewaesserung 0.9.35.
MT_SPERRE_GEHALTEN=0
startsperre_nehmen() {
    [ "$MT_SPERRE_GEHALTEN" = "1" ] && return 0
    command -v flock >/dev/null 2>&1 || return 0
    [ -r "$0" ] || return 0
    exec 8<"$0"
    if flock -w 15 8; then
        MT_SPERRE_GEHALTEN=1
        return 0
    fi
    return 1
}

# C8 (Durchgang 30.09.2026): der Waechter misst das Erzeugnis, nicht nur die
# Prozessnummer (Regeln/03 Abschnitt 5). Der Dienst schreibt seinen
# Herzschlag in zustand.json - verbunden mit ok=1, in einer Stoerung mit
# ok=0. Steht der Prozess da, laeuft laenger als 3 x Takt und hat seit mehr
# als 3 x Takt keinen Herzschlag geschrieben, haengt er. Bis 0.9.30 sah der
# Waechter nur, ob ein Prozess da ist (Bericht code C8). Bei herzschlag=0
# gibt es kein Erzeugnis - dann nur die Prozessprobe wie bisher.
HAENGER_MERKER="$PDATA/haenger_neustart"
herzschlag_takt() {
    t=$(sed -n 's/.*"herzschlag"[[:space:]]*:[[:space:]]*"\{0,1\}\([0-9][0-9]*\).*/\1/p' \
        "$PCONFIG/matter2lox.json" 2>/dev/null | head -n 1)
    case "$t" in ''|*[!0-9]*) t=60 ;; esac
    [ "$t" -gt 3600 ] && t=3600
    echo "$t"
}
haengt() {
    hz=$(herzschlag_takt)
    [ "$hz" -gt 0 ] || return 1
    grenze=$((3 * hz))
    P=$(cat "$PID" 2>/dev/null)
    case "$P" in ''|*[!0-9]*) return 1 ;; esac
    lz=$(ps -o etimes= -p "$P" 2>/dev/null | tr -d ' ')
    case "$lz" in ''|*[!0-9]*) return 1 ;; esac
    [ "$lz" -gt "$grenze" ] || return 1
    hs=$(sed -n 's/.*"herzschlag"[[:space:]]*:[[:space:]]*\([0-9][0-9]*\).*/\1/p' \
        "$PDATA/zustand.json" 2>/dev/null | head -n 1)
    case "$hs" in ''|*[!0-9]*) hs=0 ;; esac
    jetzt=$(date +%s 2>/dev/null)
    case "$jetzt" in ''|*[!0-9]*) return 1 ;; esac
    hs_alter=$((jetzt - hs))
    [ "$hs_alter" -gt "$grenze" ]
}

laeuft() {
    [ -f "$PID" ] || return 1
    P=$(cat "$PID" 2>/dev/null)
    [ -n "$P" ] || return 1
    kill -0 "$P" 2>/dev/null || return 1
    # Nummernrecycling ausschliessen: der Prozess muss unser Skript sein.
    #
    # ARGUMENTWEISE, nicht als Teilzeichenkette. Bis 0.9.16 stand hier
    # grep -qa ueber die ganze cmdline; das trifft auch einen Editor, der die
    # Datei geoeffnet hat, und jeden anderen Prozess, in dessen Aufrufzeile
    # der Dateiname vorkommt. Geprueft werden zwei Dinge: das erste Argument
    # ist genau unser Skript, und das nullte ist ein Python.
    # (uninstall/uninstall macht es seit jeher so.)
    [ -r "/proc/$P/cmdline" ] || return 1
    ARGS=$(tr '\0' '\n' < "/proc/$P/cmdline" 2>/dev/null)
    [ "$(echo "$ARGS" | sed -n '2p')" = "$SKRIPT" ] || return 1
    echo "$ARGS" | sed -n '1p' | grep -qE '(^|/)python[0-9.]*$' || return 1
    return 0
}

starten() {
    if [ "$INSTALLIERT" != "1" ]; then
        nicht_installiert
        return 1
    fi
    if ! startsperre_nehmen; then
        # 0.9.35 (Nr. 22): Rueckgabe 4, nicht 0. Bis 0.9.34 meldete
        # postinstall.sh daraufhin "wurde wieder gestartet", obwohl nichts
        # gestartet wurde. 4 heisst: nichts getan, ein anderer Start laeuft.
        echo "Ein anderer Start dieses Plugins laeuft seit ueber 15 Sekunden - jetzt wird nichts gestartet."
        return 4
    fi
    if laeuft; then
        echo "laeuft bereits (PID $(cat "$PID"))"
        return 0
    fi
    # Laeuft gerade eine Aktualisierung? Dann nichts starten. Dieser Weg
    # deckt ALLE Startwege dieses Plugins ab: den Waechter aus cron.01min,
    # den Knopf "Dienst starten" der Oberflaeche und den Start aus
    # postinstall.sh. Nur postinstall.sh setzt MT_START_TROTZ_MARKE=1 -
    # dort ist die Marke die eigene, und der Start ist der letzte Schritt
    # der Installation.
    # Anlass, gemessen am 18.09.2026 in WSL: in der Luecke zwischen
    # purge_installation und postinstall.sh startete der Knopf der
    # Oberflaeche den Dienst mitten in der Installation (Fall L5: 1 Prozess),
    # und der Waechter hielt ihn danach am Leben, weil "start" den
    # Sollmerker neu anlegt (Fall L6).
    # O4 (Durchgang 30.09.2026): Rueckgabe 3, nicht 0. Bis 0.9.30 meldete der
    # Knopf der Oberflaeche daraufhin "Dienst gestartet." - und der Satz
    # "wird danach gestartet" stimmte nur, wenn der Dienst vorher lief
    # (Bericht oberflaeche Nr. 7). 3 heisst: nichts getan, weil eine
    # Aktualisierung laeuft; die Oberflaeche sagt es so.
    if marke_gilt; then
        echo "Eine Aktualisierung dieses Plugins laeuft - jetzt wird nichts gestartet. Lief der Dienst vorher, startet ihn die Installation am Ende selbst; sonst danach erneut starten."
        return 3
    fi
    # Erst NACH der Markenpruefung: in der Upgrade-Luecke legt ein Start, der
    # nicht startet, auch keinen Ordner an.
    ordner_anlegen
    # Ein Dienst ohne PID-Datei (purge_installation hat sie geloescht) ist
    # fuer laeuft() unsichtbar. Er wird hier gefunden, statt einen zweiten
    # danebenzustellen; seine Nummer kommt zurueck in die PID-Datei, damit
    # die Oberflaeche ihn wieder sieht und "stop" ihn wieder erreicht.
    WAISE=$(dienste_suchen | head -n 1)
    if [ -n "$WAISE" ]; then
        echo "$WAISE" > "$PID"
        touch "$SOLL"
        echo "laeuft bereits (PID $WAISE, ohne PID-Datei gefunden)"
        return 0
    fi
    if [ ! -x "$PY" ]; then
        echo "FEHLER: virtuelle Python-Umgebung fehlt ($PY). Plugin neu installieren."
        return 1
    fi
    if [ ! -f "$SKRIPT" ]; then
        echo "FEHLER: $SKRIPT fehlt. Plugin neu installieren."
        return 1
    fi
    if [ ! -f "$PCONFIG/matter2lox.json" ]; then
        echo "FEHLER: Konfiguration fehlt ($PCONFIG/matter2lox.json). Erst die Oberflaeche oeffnen."
        return 1
    fi
    touch "$SOLL"
    # Die Ausgabe geht in eine EIGENE Startdatei, nicht in die Logdatei.
    #
    # Grund: das Python-Skript rotiert matter2lox.log selbst (RotatingFile-
    # Handler, 512 kB). Beim Rotieren wird umbenannt - der von nohup geerbte
    # Dateizeiger der Schale zeigt danach auf matter2lox.log.1 und nach der
    # zweiten Rotation auf eine geloeschte Datei. Alles, was Python nach
    # stderr schreibt (Ablaufverfolgungen, "Task exception was never
    # retrieved"), war ab da unsichtbar, und der Platz blieb belegt.
    # Genau ein Schreiber je Datei: Python die Logdatei, die Schale diese.
    # Sie wird bei jedem Start geleert (>), damit sie nicht waechst.
    : > "$STARTLOG"
    # 8<&-: der Dienst erbt die Startsperre nicht (C2).
    nohup "$PY" "$SKRIPT" > "$STARTLOG" 2>&1 8<&- &
    echo $! > "$PID"
    sleep 1
    if laeuft; then
        echo "gestartet (PID $(cat "$PID"))"
        return 0
    fi
    echo "FEHLER: Start fehlgeschlagen - siehe $STARTLOG und $LOGDATEI"
    [ -s "$STARTLOG" ] && tail -n 5 "$STARTLOG"
    rm -f "$PID"
    return 1
}

anhalten() {
    # 0.9.35 (Nr. 21): unter der Startsperre. Bis 0.9.34 konnte ein Waechter,
    # der den Sollmerker noch gesehen hatte und auf die Sperre wartete, nach
    # einem "stop" starten() rufen - das legt den Sollmerker neu an, und der
    # eben angehaltene Dienst lief wieder. Bekommt "stop" die Sperre in 15 s
    # nicht, haelt es trotzdem an: Anhalten darf nie an einer Sperre haengen.
    startsperre_nehmen || true
    rm -f "$SOLL"
    # ALLE eigenen Dienste, nicht nur den aus der PID-Datei. Bis 0.9.25
    # endete dieser Weg bei "laeuft nicht", sobald die PID-Datei fehlte -
    # und der Dienst, den purge_installation um seine PID-Datei gebracht
    # hat, lief weiter, unsichtbar und ueber die Oberflaeche nicht mehr
    # erreichbar. Vor jedem Signal steht die argumentweise Probe.
    ZIELE=$(dienste_suchen)
    if [ -z "$ZIELE" ]; then
        rm -f "$PID"
        echo "laeuft nicht"
        return 0
    fi
    kill $ZIELE 2>/dev/null
    for i in 1 2 3 4 5 6 7 8 9 10; do
        [ -z "$(dienste_suchen)" ] && break
        sleep 1
    done
    REST=$(dienste_suchen)
    if [ -n "$REST" ]; then
        kill -9 $REST 2>/dev/null
        sleep 1
    fi
    # Die WIRKUNG nachsehen, nicht den Rueckgabewert von kill.
    REST=$(dienste_suchen)
    if [ -n "$REST" ]; then
        echo "FEHLER: der Dienst laeuft weiter (PID $(echo $REST)). Die PID-Datei bleibt liegen." >&2
        return 1
    fi
    rm -f "$PID"
    echo "angehalten"
    return 0
}

case "$1" in
    start)   starten ;;
    stop)
        # Aus einem Archiv heraus haelt "stop" nichts an. SKRIPT kommt aus
        # dem GELESENEN bin-Ordner der Anlage - bis 0.9.28 beendete ein
        # "stop" aus einem ausgepackten Archiv mit gesetztem $LBHOMEDIR
        # (am Geraet steht es in /etc/environment) den Dienst der Anlage
        # (in WSL gemessen 25.09.2026, Pruefung-Matter2Lox-0.9.29, Fall A1).
        if [ "$INSTALLIERT" != "1" ]; then
            nicht_installiert
            exit 1
        fi
        anhalten ;;
    restart)
        # Aus einem Archiv heraus haelt "restart" nichts an: sonst stuende
        # der Dienst der Anlage danach, weil starten() dort verweigert
        # (Fall H15).
        if [ "$INSTALLIERT" != "1" ]; then
            nicht_installiert
            exit 1
        fi
        # O4 (Durchgang 30.09.2026): waehrend einer Aktualisierung haelt
        # "restart" nichts an. Bis 0.9.30 hielt der Knopf "Neu starten" einen
        # laufenden Dienst an, starten() verweigerte danach wegen der Marke,
        # und die Oberflaeche meldete "Dienst neu gestartet." (Bericht
        # oberflaeche Nr. 7).
        if marke_gilt; then
            echo "Eine Aktualisierung dieses Plugins laeuft - der Dienst wurde nicht angefasst. Die Installation startet ihn am Ende selbst, wenn er vorher lief."
            exit 3
        fi
        anhalten; sleep 1; starten ;;
    status)
        if laeuft; then
            echo "laeuft $(cat "$PID")"
            exit 0
        fi
        # Auch ohne PID-Datei nachsehen - sonst meldet "status" nach einem
        # Upgrade "gestoppt", waehrend der Dienst laeuft.
        WAISE=$(dienste_suchen | head -n 1)
        if [ -n "$WAISE" ]; then
            echo "laeuft $WAISE (ohne PID-Datei)"
            exit 0
        fi
        echo "gestoppt"
        exit 1
        ;;
    waechter)
        # Waehrend einer Aktualisierung nichts starten und nichts
        # protokollieren. starten() prueft die Marke ebenfalls; hier steht
        # sie, damit der Minutentakt das Protokoll nicht vollschreibt.
        if marke_gilt; then
            exit 0
        fi
        # Nur neu starten, wenn der Dienst laufen SOLL. Ein bewusst
        # angehaltener Dienst bleibt angehalten. Nur aus der Installation:
        # sonst schriebe ein Waechter aus einem Archiv seine Zeile in das
        # Protokoll der Anlage (Fall H14). Der Logordner liegt auf der
        # RAM-Platte und kann fehlen - er wird vor der Zeile angelegt
        # (Fall H11).
        #
        # C2 (Durchgang 30.09.2026): die ganze Frage "laeuft er? sonst
        # starten" steht unter der Startsperre. Zwei Waechter derselben
        # Sekunde laufen damit nacheinander, und der zweite findet den Dienst
        # des ersten. Bekommt ein Waechter die Sperre in 15 s nicht, tut er
        # nichts - der naechste kommt in einer Minute.
        if [ "$INSTALLIERT" = "1" ] && [ -f "$SOLL" ]; then
            startsperre_nehmen || exit 0
            # 0.9.35 (Nr. 21): nach dem Warten auf die Sperre noch einmal
            # nachsehen - in der Zwischenzeit kann "stop" den Sollmerker
            # entfernt haben.
            [ -f "$SOLL" ] || exit 0
            if ! laeuft; then
                ordner_anlegen
                echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: Dienst lief nicht, wird neu gestartet." >> "$LOGDATEI"
                starten >> "$LOGDATEI" 2>&1
            elif haengt; then
                # C8: Neustart hoechstens alle 15 Minuten, mit genau einer
                # Zeile je Neustart (gebremst). Der Sollmerker wird nach dem
                # Anhalten neu gelegt, damit ein gescheiterter Start in der
                # naechsten Minute ueber den Zweig darueber wiederholt wird.
                M=$(stat -c %Y "$HAENGER_MERKER" 2>/dev/null)
                case "$M" in ''|*[!0-9]*) M=0 ;; esac
                SEIT=$(( $(date +%s) - M ))
                if [ "$SEIT" -ge 900 ] || [ "$SEIT" -lt 0 ]; then
                    ordner_anlegen
                    touch "$HAENGER_MERKER"
                    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: der Dienst (PID $(cat "$PID" 2>/dev/null)) laeuft, aber sein Herzschlag in zustand.json ist seit $hs_alter s aus (Grenze 3 x $hz s) - er haengt und wird neu gestartet. Naechster solcher Neustart fruehestens in 15 Minuten." >> "$LOGDATEI"
                    anhalten >> "$LOGDATEI" 2>&1
                    touch "$SOLL"
                    starten >> "$LOGDATEI" 2>&1
                fi
            fi
        fi
        ;;
    *)
        echo "Aufruf: $0 {start|stop|restart|status|waechter}"
        exit 2
        ;;
esac
