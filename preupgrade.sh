#!/bin/bash
# Matter to Loxone - preupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# DIESES SKRIPT IST DAS EINZIGE RETTUNGSFENSTER.
#
# Am plugininstall.pl des LoxBerry nachgemessen (Zweig master, 03.09.2026):
#   :857  preupgrade wird ausgefuehrt      <- hier stehen wir
#   :886  purge_installation               <- raeumt ab
#   :1629 rm -rf config/plugins/<ordner>/
#   :1631 rm -rf data/plugins/<ordner>/    <- ohne Bedingung
#   :1316 postinstall                      <- Rueckgabefenster
# Der Log-Ordner (:1653) haengt an "all" und ueberlebt ein Upgrade; config und
# data nicht. Was ueberleben soll, muss also VON HIER neben den Ordner.
#
# Bis 0.9.16 sicherte dieses Skript nur die Konfigurations-JSON und behauptete
# im Kommentar, der Matter-Container bleibe unberuehrt. Der Container blieb es
# auch - sein DATENORDNER aber nicht: der Bindmount zeigte auf
# data/plugins/<ordner>/matter, und der wurde bei jedem Update geloescht.
# Jedes angelernte Geraet haette danach neu angelernt werden muessen.
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
MT_RC=$?
if [ "$MT_RC" -ne 0 ]; then
    if [ "$MT_RC" -eq 1 ]; then
        echo "<FAIL> Unzulaessiger Ordnername '$PFOLDER'."
    else
        echo "<FAIL> Der LoxBerry-Wurzelordner liess sich nicht bestimmen."
    fi
    echo "<FAIL> Es wurde NICHTS gesichert. Das Update wird abgebrochen (Rueckgabe 2),"
    echo "<FAIL> es wurde nichts geloescht. Bitte melden."
    # I4 (Durchgang 30.09.2026): 2, nicht 1. plugininstall.pl wertet 1 nur
    # als Fehlerzeile und raeumt danach ab (:855-874); ab 2 bricht es VOR dem
    # Abraeumen ab (Bericht installer I4).
    exit 2
fi

PDATA="$BASE/data/plugins/$PFOLDER"
PBIN="$BASE/bin/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"

FEHLER=0

# ------------------------------------------------------------ 0. Marke
# ALS ERSTES, noch vor dem Anhalten: "Aktualisierung laeuft".
#
# Der Installer legt die Cron-Datei rund eine Minute vor postinstall.sh
# neu an, und zwischen diesem Skript und dem Dienststart liegen ein
# 'python3 -m venv' und ein 'pip install websockets' - zusammen
# regelmaessig mehrere Minuten. In dieser Luecke sind Konfiguration,
# Sollmerker und PID-Datei geloescht. Der Waechter greift dort ins Leere
# (er verlangt data/plugins/<ordner>/soll_laufen), der Knopf "Dienst
# starten" der Oberflaeche aber nicht: am 18.09.2026 in WSL gemessen,
# startete er den Dienst mitten in der Installation, und der Waechter hielt
# ihn danach am Leben, weil 'start' den Sollmerker neu anlegt.
# Solange die Marke juenger als eine Stunde ist, startet kein Weg den
# Dienst; postinstall.sh entfernt sie nach dem Start, postupgrade.sh noch
# einmal zur Sicherheit, uninstall raeumt sie weg.
# Sie liegt NEBEN dem Datenordner, weil purge_installation den Ordner
# selbst loescht (Regeln/06).
mkdir -p "$BASE/data/plugins" 2>/dev/null
date +%s > "$MARKE" 2>/dev/null
if grep -qx '[0-9][0-9]*' "$MARKE" 2>/dev/null; then
    echo "<OK> Dienststart bis zum Ende der Installation gesperrt."
else
    # 0.9.35 (Nr. C): die Marke ist seit 0.9.30 TRAGEND - an ihr erkennen
    # preinstall.sh und postinstall.sh die Aktualisierung. Fehlt sie, haelt
    # postinstall.sh das Update fuer eine Neuinstallation: die Konfiguration
    # (Token, WLAN, Thread) wird beiseitegelegt statt zurueckgespielt, und
    # der Dienst startet nicht. Bis 0.9.34 war das nur eine Warnung.
    echo "<FAIL> Die Marke $MARKE liess sich nicht anlegen. Ohne sie wuerde die"
    echo "<FAIL> Aktualisierung als Neuinstallation behandelt und die Einstellungen"
    echo "<FAIL> nicht uebernommen. Ist data/plugins voll oder schreibgeschuetzt?"
    FEHLER=1
fi

# Gruppe 8 (0.9.44, E12): die Befehlsbruecke anhalten (stop beendet Dienst UND mosquitto_sub). Beim Umstieg von
# 0.9.43 gibt es sie noch nicht - dann nichts. Die Marke von eben haelt den Waechter in cron.01min bis zum Ende
# der Installation ab (befehlsbruecke.sh start prueft dieselbe Marke).
BBSH="$PBIN/befehlsbruecke.sh"
if [ -f "$BBSH" ]; then
    MT_BB=$(timeout 40 bash "$BBSH" stop 2>&1)
    echo "<INFO> Befehlsbruecke: ${MT_BB:-keine Antwort}"
fi

# Ist die Nummer $1 ein Dienst dieses Plugins? Argumentweise: argv[0] ist
# ein Python, argv[1] ist genau unser Skript. Bis 0.9.25 wurde nur argv[1]
# verglichen; am 18.09.2026 in WSL gemessen, beendete dieses Skript damit
# einen fremden 'tail -f .../matter_dienst.py'. Dieselbe Probe steht in
# bin/dienst.sh und in uninstall/uninstall.
mt_ist_dienst() {
    [ -r "/proc/$1/cmdline" ] || return 1
    tr '\0' '\n' 2>/dev/null < "/proc/$1/cmdline" | {
        IFS= read -r a0 || exit 1
        IFS= read -r a1 || exit 1
        case "${a0##*/}" in python|python3|python3.*) ;; *) exit 1 ;; esac
        [ "$a1" = "$2" ]
    }
}

# ------------------------------------------------------------ 1. Dienst
# Erst den Sollmerker wegnehmen, dann anhalten - genau in dieser Reihenfolge.
# Bis 0.9.16 wurde nur die PID gekillt und der Merker blieb liegen. Zwischen
# diesem Skript und postinstall liegen ein 'python3 -m venv' und ein
# 'pip install websockets', zusammen regelmaessig laenger als eine Minute; der
# minuetliche Waechter fand in diesem Fenster den Merker und startete den
# ALTEN Dienst neu. Kurz darauf loeschte purge_installation PID-Datei und
# Merker - zurueck blieb ein Dienst ohne PID-Datei, fuer die Oberflaeche
# unsichtbar und ueber 'dienst.sh stop' nicht mehr erreichbar.
LIEF=0
# 0.9.35 (Nr. C): auch der Sollmerker zaehlt. Ist der Dienst gerade
# abgestuerzt (Waechterluecke bis eine Minute, oder ein Absturzkreislauf, den
# genau dieses Update beheben soll), blieb er bis 0.9.34 nach dem Update aus.
[ -f "$PDATA/soll_laufen" ] && LIEF=1
if [ -x "$PBIN/dienst.sh" ]; then
    if "$PBIN/dienst.sh" status >/dev/null 2>&1; then
        LIEF=1
    fi
    "$PBIN/dienst.sh" stop >/dev/null 2>&1
else
    rm -f "$PDATA/soll_laufen" 2>/dev/null
fi

# Zweiter Griff, argumentweise: ein Dienst ohne PID-Datei (siehe oben) wird
# von dienst.sh nicht gefunden. Dieselbe Suche wie in uninstall/uninstall.
SKRIPT="$PBIN/matter_dienst.py"
# Der Dienst gehoert loxberry. Gibt es den Benutzer nicht (Pruefstand),
# gilt der eigene - sonst faende die Suche gar nichts.
DIENST_UID=$(id -u loxberry 2>/dev/null || id -u)
for D in /proc/[0-9]*; do
    P=$(basename "$D")
    [ -r "$D/cmdline" ] || continue
    # Nur Prozesse des Dienstbenutzers ansehen; fremde Nummern werden gar
    # nicht erst geprueft.
    [ "$(stat -c %u "$D" 2>/dev/null)" = "$DIENST_UID" ] || continue
    if mt_ist_dienst "$P" "$SKRIPT"; then
        LIEF=1
        kill "$P" 2>/dev/null
        for i in 1 2 3 4 5; do
            mt_ist_dienst "$P" "$SKRIPT" || break
            sleep 1
        done
        # Vor dem harten Signal erneut ARGUMENTWEISE pruefen, nicht nur
        # "kill -0": die Nummer kann inzwischen ein anderer Prozess tragen.
        # Bis 0.9.28 stand hier ein blankes "kill -9" (in WSL gemessen
        # 25.09.2026, Pruefung-Matter2Lox-0.9.29, Fall D1).
        if mt_ist_dienst "$P" "$SKRIPT"; then
            kill -9 "$P" 2>/dev/null
            sleep 1
        fi
        if mt_ist_dienst "$P" "$SKRIPT"; then
            echo "<WARNING> Ein Dienst ohne PID-Datei laeuft weiter (PID $P)."
            FEHLER=1
        else
            echo "<INFO> Ein Dienst ohne PID-Datei wurde beendet (PID $P)."
        fi
    fi
done
rm -f "$PDATA/dienst.pid" 2>/dev/null
[ "$LIEF" -eq 1 ] && echo "<OK> Laufender Dienst angehalten." \
                  || echo "<INFO> Es lief kein Dienst."

# Merken, ob er lief - NEBEN dem Ordner, sonst ist der Merker gleich wieder
# weg. postinstall.sh startet danach wieder, wenn diese Marke da ist.
rm -f "$BASE/data/plugins/$PFOLDER.lief" 2>/dev/null
if [ "$LIEF" -eq 1 ]; then
    : > "$BASE/data/plugins/$PFOLDER.lief" 2>/dev/null
fi

# ----------------------------------------------------- 2. Konfiguration
CF="$PCONFIG/matter2lox.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"
# Gesichert wird nach INHALT, nie nach Groesse oder blossem Dasein. Dieselbe
# Datei ist die Zweitschrift der Oberflaeche (mt_paths()['sicherung']), und
# die schreibt mt_config_speichern() nur mit Aktionstoken. Bis 0.9.28
# kopierte dieses Skript die Konfiguration bedingungslos darueber - stand sie
# auf "{}" oder war sie beschaedigt, war die gute Zweitschrift mit Token,
# WLAN-Passwort und Thread-Dataset beim Upgrade fort (in WSL gemessen
# 25.09.2026, Pruefung-Matter2Lox-0.9.29, Fall K1).
# Stufe 2 = JSON-Objekt mit nicht leerem aktionstoken, 1 = JSON-Objekt mit
# mindestens einem Eintrag, 0 = nichts Brauchbares. Die Sicherung wird nur
# durch einen Stand ersetzt, der mindestens so viel traegt wie sie selbst.
mt_inhalt() {
    python3 -c '
import json, sys
try:
    with open(sys.argv[1], encoding="utf-8") as d:
        c = json.load(d)
except Exception:
    print(0); sys.exit(0)
if not isinstance(c, dict) or not c:
    print(0)
elif str(c.get("aktionstoken") or "").strip():
    print(2)
else:
    print(1)
' "$1" 2>/dev/null || echo 0
}
if [ -f "$CF" ]; then
    CF_STUFE=$(mt_inhalt "$CF")
    BK_STUFE=0
    [ -f "$BK" ] && BK_STUFE=$(mt_inhalt "$BK")
    if [ "$CF_STUFE" -ge 1 ] && [ "$CF_STUFE" -ge "$BK_STUFE" ]; then
        if cp -p "$CF" "$BK"; then
            chmod 600 "$BK"
            echo "<OK> Konfiguration gesichert: $PFOLDER.backup.json"
        else
            echo "<FAIL> Die Konfiguration liess sich NICHT sichern ($CF)."
            FEHLER=1
        fi
    elif [ "$BK_STUFE" -ge 1 ]; then
        echo "<INFO> Die Konfiguration traegt weniger als die vorhandene Sicherung"
        echo "<INFO> (kein Aktionstoken, leer oder nicht lesbar) - die Sicherung"
        echo "<INFO> $PFOLDER.backup.json bleibt unveraendert und wird nach dem Update zurueckgespielt."
    else
        echo "<INFO> Die Konfiguration ist leer oder nicht lesbar, eine brauchbare Sicherung gibt es nicht - nichts gesichert."
    fi
else
    echo "<INFO> Keine Konfiguration vorhanden - nichts zu sichern."
fi

# ------------------------------- 2b. Bridge-Einstellungen (0.9.42, Beta)
# bridge.json (Auswahl) und bridge_zugang.json (Loxone-Benutzer, Kennwort,
# Frontend-Kennwort) liegen im Konfigordner, den purge_installation loescht.
# Die Oberflaeche schreibt ihre Zweitschrift neben den Ordner bei jedem
# Speichern; hier wird sie aufgefrischt. postinstall.sh spielt sie zurueck.
# Der Bridge-Container laeuft durch - sein Ordner liegt neben dem Datenordner.
# M4 (0.9.44, Pruefbericht): nur ein JSON-Objekt mit mindestens einem Schluessel ersetzt die Zweitschrift -
# ueber eine Nebendatei (0600 vor dem Inhalt) und mv, also atomar. Sonst eine INFO, und die Zweitschrift bleibt.
# Bis 0.9.43 ueberschrieb ein blankes cp sie auch mit einer leeren oder kaputten Datei.
for BRF in bridge bridge_zugang; do
    if [ -f "$PCONFIG/$BRF.json" ]; then
        BRZ="$BASE/config/plugins/$PFOLDER.backup.$BRF.json"
        if ! python3 -c 'import json, sys
d = json.load(open(sys.argv[1], encoding="utf-8"))
sys.exit(0 if isinstance(d, dict) and d else 1)' "$PCONFIG/$BRF.json" 2>/dev/null; then
            echo "<INFO> Die Bridge-Einstellung $BRF.json ist leer oder nicht lesbar - die Zweitschrift $PFOLDER.backup.$BRF.json bleibt unveraendert."
        elif (umask 077; cp "$PCONFIG/$BRF.json" "$BRZ.neu") 2>/dev/null && chmod 600 "$BRZ.neu" 2>/dev/null \
             && mv -f "$BRZ.neu" "$BRZ" 2>/dev/null; then
            echo "<OK> Bridge-Einstellung gesichert: $PFOLDER.backup.$BRF.json"
        else
            rm -f "$BRZ.neu" 2>/dev/null
            echo "<FAIL> Die Bridge-Einstellung $BRF.json liess sich NICHT sichern."
            FEHLER=1
        fi
    fi
done

# ------------------------------------------- 3. Fabric und Zertifikate
# Der Umzug geschieht mit mv, nicht mit cp: eine Fabric kann viele Megabyte
# gross sein, und auf der SD-Karte ist Platz nicht selbstverstaendlich.
# Gibt es das Ziel schon (zweites Update), bleibt es unangetastet - dann ist
# der Umzug beim ersten Update auf 0.9.17 bereits gelaufen.
FABALT="$PDATA/matter"
FABNEU="$BASE/data/plugins/$PFOLDER.matter"
if [ -d "$FABALT" ]; then
    if [ -d "$FABNEU" ]; then
        echo "<INFO> Fabric liegt bereits am neuen Ort. Der alte Ordner"
        echo "<INFO> ($FABALT) wird mit dem Datenordner entfernt."
    elif mv "$FABALT" "$FABNEU"; then
        chmod 700 "$FABNEU" 2>/dev/null
        echo "<OK> Matter-Fabric an den neuen Ort gebracht: $PFOLDER.matter"
        echo "<INFO> Sie liegt ab jetzt NEBEN dem Datenordner und ueberlebt"
        echo "<INFO> damit jedes weitere Update. Bis 0.9.16 lag sie darin und"
        echo "<INFO> wurde bei jedem Update geloescht."
        echo "<INFO> WICHTIG: Der Container zeigt noch auf den alten Pfad."
        echo "<INFO> Nach dem Update im Reiter Einstellungen einmal"
        echo "<INFO> 'Einstellungen uebernehmen' druecken (der Container wird mit"
        echo "<INFO> dem neuen Pfad neu angelegt). Die Geraete bleiben dabei angelernt."
    else
        echo "<FAIL> Die Matter-Fabric liess sich NICHT umziehen."
        echo "<FAIL> Sie liegt in $FABALT. Das Update wird deshalb abgebrochen,"
        echo "<FAIL> bevor der Installer den Ordner loescht (siehe Schluss)."
        # 0.9.35 (Nr. C): der haeufigste Grund ist ein Ordner, den Docker als
        # root angelegt hat; ein Verzeichnis-mv in einen anderen Elternordner
        # braucht Schreibrecht auf das Verzeichnis selbst. Der Befehl steht da.
        if [ -n "$(find "$FABALT" -maxdepth 0 ! -user "$(id -un)" 2>/dev/null)" ]; then
            echo "<FAIL> Der Ordner gehoert nicht dem Benutzer $(id -un) (Besitzer: $(stat -c %U "$FABALT" 2>/dev/null))."
            echo "<FAIL> Abhilfe auf der Konsole: sudo chown -R loxberry:loxberry '$FABALT'"
        fi
        echo "<FAIL> Bitte den Ordner von Hand sichern und das Update danach wiederholen."
        FEHLER=1
    fi
else
    echo "<INFO> Kein alter Fabric-Ordner vorhanden."
fi

# ------------------------------------------------- 4. Geraetenummern
# Sie sind eine ADRESSE: sie stehen in den virtuellen Eingaengen der
# Loxone-Projektdatei (MATTER_3_...), in den MQTT-Themen (geraet3/...) und in
# den Endpunktadressen (&geraet=3). Ging die Datei verloren, entstanden sie
# neu aus der sortierten Knotenliste - und war zwischenzeitlich ein Geraet
# aus der Fabric gefallen, zeigte danach jede Adresse auf ein anderes Geraet.
NRALT="$PDATA/nummern.json"
NRNEU="$BASE/data/plugins/$PFOLDER.nummern.json"
if [ -f "$NRALT" ] && [ ! -f "$NRNEU" ]; then
    if cp -p "$NRALT" "$NRNEU"; then
        echo "<OK> Geraetenummern gesichert: $PFOLDER.nummern.json"
    else
        echo "<FAIL> Die Geraetenummern liessen sich NICHT sichern."
        echo "<FAIL> Nach dem Update koennen sich die Adressen verschieben."
        FEHLER=1
    fi
elif [ -f "$NRNEU" ]; then
    echo "<INFO> Geraetenummern liegen bereits am neuen Ort."
else
    echo "<INFO> Keine Geraetenummern vorhanden (noch kein Geraet angelernt)."
fi

# ------------------------------------------------------------- Schluss
# Die Erfolgsmeldung haengt an dem, was wirklich geschehen ist. Bis 0.9.16
# stand hier ein unbedingtes "<OK> preupgrade abgeschlossen".
if [ "$FEHLER" -eq 0 ]; then
    echo "<OK> preupgrade abgeschlossen. Fabric, Geraetenummern und"
    echo "<OK> Konfiguration liegen neben dem Plugin-Ordner und ueberstehen"
    echo "<OK> das Abraeumen durch den Installer."
    exit 0
fi
# I4 (Durchgang 30.09.2026): Abbruch mit 2, nicht 1. plugininstall.pl
# (:855-874) schreibt bei 1 nur eine Fehlerzeile und ruft danach
# purge_installation - data/plugins/<ordner>/ samt einer nicht umgezogenen
# Fabric war fort, obwohl die Meldung "Update abbrechen" verlangte (Bericht
# installer I4, Fall X3). Ab 2 ruft es &fail VOR dem Abraeumen. Weil danach
# die alte Fassung stehen bleibt, raeumt dieses Skript seine eigenen Spuren:
# die Marke faellt (sonst blieb der Dienststart eine Stunde gesperrt), und
# ein Dienst, der vorher lief, wird wieder gestartet.
echo "<FAIL> preupgrade mit Beanstandungen beendet - siehe oben."
echo "<FAIL> Das Update wird ABGEBROCHEN (Rueckgabe 2): der Installer bricht vor dem"
echo "<FAIL> Abraeumen ab, es wurde nichts geloescht. Die bisherige Fassung bleibt."
# 0.9.35 (Nr. C): die Plugin-Datenbank des LoxBerry traegt die NEUE Fassung
# schon vor diesem Skript (plugininstall.pl). Die Oberflaeche zeigt sie also,
# und die automatische Aktualisierung versucht es nicht noch einmal.
echo "<FAIL> ACHTUNG: Die Plugin-Verwaltung zeigt danach trotzdem die neue Fassungsnummer."
echo "<FAIL> Nach dem Beheben das Update von Hand erneut einspielen (Plugin-Verwaltung,"
echo "<FAIL> ZIP-Datei oder Adresse) - von selbst wird es nicht wiederholt."
rm -f "$MARKE" 2>/dev/null
rm -f "$BASE/data/plugins/$PFOLDER.lief" 2>/dev/null
if [ "$LIEF" -eq 1 ] && [ -x "$PBIN/dienst.sh" ]; then
    if "$PBIN/dienst.sh" start >/dev/null 2>&1; then
        echo "<INFO> Der Dienst lief vorher und wurde wieder gestartet."
    else
        echo "<WARNING> Der Dienst lief vorher, liess sich aber nicht wieder starten. Reiter Einstellungen, Knopf 'Dienst starten'."
    fi
fi
exit 2
