#!/bin/bash
# Govee - Start, Stopp und Waechter des Abrufdienstes.
#
# Die Pfade werden aus dem EIGENEN Ablageort abgeleitet, nicht ueber
# LoxBerry::System. Grund: LoxBerry::System leitet den Pluginordner aus dem
# Aufrufort ab; wird dieses Skript aus postinstall.sh oder aus dem Cron
# gestartet, kommt dort ueberall Leerstring zurueck - das Skript werkelt dann
# gegen /-Pfade und meldet trotzdem Erfolg.

# readlink -f loest Symlinks auf, BEVOR das Verzeichnis bestimmt wird.
# LoxBerry legt Daemons als Symlink unter system/daemons/plugins/ ab; von
# dort aufgerufen ergaebe dirname "$0" den Pfad .../system/daemons/plugins,
# der Pluginname waere buchstaeblich "plugins", und PID-Datei, Sollmerker
# und Logdatei landeten neben dem eigenen Ordner statt darin. Die
# Oberflaeche saehe den Dienst dann nie laufen, und der Waechter startete
# ihn im Minutentakt ein zweites Mal.
# Als loxberry laufen, nicht als root.
#
# Der minuetliche Waechter kommt aus dem Cron. Laeuft der als root - und je
# nach Ablage des Cronjobs tut er das -, dann gehoerten PID-Datei, Sollmerker
# und Protokoll danach root. Die Oberflaeche laeuft als loxberry und koennte
# den Dienst anschliessend weder anhalten noch neu starten: sie darf die
# Dateien nicht mehr schreiben. Schlimmer noch, 'dienst.sh stop' meldet dann
# Erfolg - das kill scheitert, aber das rm der PID-Datei gelingt, weil das
# Verzeichnis loxberry gehoert. Der Dienst laeuft weiter und ist nur noch
# ueber die Prozessliste zu finden.
#
# Deshalb setzt sich das Skript selbst herunter, EINMAL und bevor es
# irgendetwas anlegt. exec, damit kein zusaetzlicher Prozess stehen bleibt.
# '-s /bin/bash' ausdruecklich: ohne das nimmt su die Login-Shell aus
# /etc/passwd. Steht dort nologin oder /bin/false, endet dieses Skript hier
# still und ohne Meldung - und weil es 'exec' ist, kaeme nicht einmal ein
# Rueckgabewert zurueck. Auf einem regulaeren LoxBerry ist der Zweig ohnehin
# unerreichbar (der Cron laeuft bereits als loxberry); er greift nur, wenn
# jemand von Hand mit sudo aufruft.
#
# Woertlich uebernommen aus LoxBerry-Plugin-Dashboard-0.9.12, dort seit dem
# 16.08.2026 in Betrieb. Ueber den Bestand gezaehlt am 31.08.2026: 15 von 17
# dienst.sh hatten den Abstieg nicht, obwohl REGELN_2 ihn seit langem
# verlangt.
if [ "$(id -u)" = "0" ] && id loxberry >/dev/null 2>&1; then
    exec su -s /bin/bash loxberry -c "$(printf '%q ' "$0" "$@")"
fi

SELF=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)          # <home>/bin/plugins/<ordner>
PNAME=$(basename "$SELF")
LBHOMEDIR=$(cd "$SELF/../../.." && pwd)
PDATA="$LBHOMEDIR/data/plugins/$PNAME"
PLOG="$LBHOMEDIR/log/plugins/$PNAME"
PCONFIG="$LBHOMEDIR/config/plugins/$PNAME"
PID="$PDATA/dienst.pid"
SOLL="$PDATA/soll_laufen"
# Die Marke "Aktualisierung laeuft". Sie liegt NEBEN dem Datenordner, weil
# purge_installation data/plugins/<ordner>/ zwischen preupgrade.sh und
# postinstall.sh restlos abraeumt (Regeln/06) - im Ordner waere sie genau
# dann fort, wenn sie gebraucht wird. preupgrade.sh legt sie als Erstes an,
# postupgrade.sh - das letzte Hakenskript dieser Linie - entfernt sie wieder.
MARKE="$LBHOMEDIR/data/plugins/$PNAME.upgrade_laeuft"
LOGDATEI="$PLOG/govee.log"
# Eigene Datei fuer alles, was NEBEN dem Protokoll anfaellt: Meldungen des
# Starts und alles, was das PHP-Skript nach stderr schreibt, bevor sein
# Protokoll steht (Parsefehler, fehlende Erweiterung, Abbruch beim Laden).
#
# Bis 0.9.14 ging diese Ausgabe mit ">> $LOGDATEI" in DIESELBE Datei, in die
# bin/govee_dienst.php schreibt. Das haelt einen zweiten, anhaengenden Deskriptor auf diese
# Datei offen. Verschwindet sie - Ramdisk geleert, log_maint - dann zeigt der
# Deskriptor dieser Shell weiter auf die geloeschte Datei, und was er traegt,
# sieht niemand mehr. Am Geraet gemessen (06.09.2026): PID 200743 hielt govee.log auf
# den Deskriptoren 1 und 2 offen, beide auf der geloeschten Datei.
# Regel: genau einer schreibt in eine Protokolldatei.
STARTLOG="$PLOG/govee_start.log"
SKRIPT="$SELF/govee_dienst.php"
# Der Dienst laeuft als loxberry (siehe den Abstieg oben); wo es den Benutzer
# nicht gibt, als der eigene. Gebraucht fuer die Suche nach Diensten ohne
# PID-Datei: ohne Benutzerfilter liefe sie ueber fremde Prozesse.
DIENSTUID=$(id -u loxberry 2>/dev/null || id -u)

mkdir -p "$PDATA" "$PLOG" 2>/dev/null

laeuft() {
    [ -f "$PID" ] || return 1
    P=$(cat "$PID" 2>/dev/null)
    [ -n "$P" ] || return 1
    kill -0 "$P" 2>/dev/null || return 1
    # Nummernrecycling ausschliessen: der Prozess muss unser Skript sein.
    #
    # Argumentweise pruefen, nicht die ganze Befehlszeile durchsuchen:
    # /proc/<pid>/cmdline trennt die Argumente mit Nullbytes, und ein grep
    # darueber traefe auch einen Editor mit geoeffneter govee_dienst.php.
    # Geprueft werden zwei Dinge: das zweite Argument ist genau unser Skript,
    # und das erste ist ein PHP - "nano <pfad>/govee_dienst.php" fuehrt den
    # Pfad sonst ebenfalls als zweites Argument.
    ARGS=$(tr '\0' '\n' < "/proc/$P/cmdline" 2>/dev/null)
    [ "$(echo "$ARGS" | sed -n '2p')" = "$SKRIPT" ] || return 1
    echo "$ARGS" | sed -n '1p' | grep -qE '(^|/)php[0-9.]*$' || return 1
    return 0
}

# Dieselbe Probe fuer eine BELIEBIGE Nummer, zusaetzlich mit dem Benutzer -
# gebraucht fuer die Suche nach Diensten ohne PID-Datei. Wortgleich mit
# preupgrade.sh und uninstall/uninstall.
ist_dienst() {   # $1 PID
    [ -r "/proc/$1/cmdline" ] || return 1
    [ "$(stat -c %u "/proc/$1" 2>/dev/null)" = "$DIENSTUID" ] || return 1
    # cat statt Umlenkung: sonst meldet die Schale einen Prozess, der zwischen
    # Auflistung und Lesen endet, auf der Fehlerausgabe - und die landet ueber
    # gv_dienst() in der Oberflaeche.
    ROH=$(cat "/proc/$1/cmdline" 2>/dev/null | tr '\0' '\n')
    [ -n "$ROH" ] || return 1
    A0=$(printf '%s\n' "$ROH" | sed -n '1p')
    A1=$(printf '%s\n' "$ROH" | sed -n '2p')
    [ -n "$A0" ] && [ -n "$A1" ] || return 1
    case "${A0##*/}" in php|php[0-9.]*) ;; *) return 1 ;; esac
    case "$A1" in
        /*) ZIEL=$A1 ;;
        *)  WD=$(readlink "/proc/$1/cwd" 2>/dev/null) || return 1
            ZIEL="${WD% (deleted)}/$A1" ;;
    esac
    [ "$ZIEL" = "$SKRIPT" ]
}
dienste_suchen() {
    for D in /proc/[0-9]*; do
        ist_dienst "${D#/proc/}" && echo "${D#/proc/}"
    done
    return 0
}
# Beendet jeden eigenen Dienst, der gerade laeuft - auch den, der in keiner
# PID-Datei steht. Zehn Sekunden Zeit, dann hart; vor jedem Signal steht die
# Probe oben.
waisen_beenden() {
    LISTE=$(dienste_suchen)
    [ -n "$LISTE" ] || return 0
    kill $LISTE 2>/dev/null
    N=0
    while [ $N -lt 10 ] && [ -n "$(dienste_suchen)" ]; do
        sleep 1
        N=$((N + 1))
    done
    REST=$(dienste_suchen)
    [ -n "$REST" ] && kill -9 $REST 2>/dev/null
    echo $LISTE
}

# Laeuft gerade eine Aktualisierung dieses Plugins?
#
# Gemessen (Pruefung-Govee-0.9.19, Faelle A5 bis A7 und D1/D2, 18.09.2026):
# ohne diese Frage startete der Knopf "Dienst starten" der Oberflaeche mitten
# in der Upgrade-Luecke einen Dienst - und legte dabei soll_laufen an. Von da
# an hielt der Minutentakt ihn am Leben, auch wenn der Dienst vor dem Upgrade
# BEWUSST angehalten worden war (am Geraet ist er seit dem 08.09.2026 aus).
#
# Vier Ausgaenge, alle gemessen:
#   Marke juenger als 3600 s  -> gesperrt (Fall C1)
#   Marke aelter, aus der Zukunft, leer oder unlesbar -> sie gilt nicht
#                                (Faelle C2 bis C5; eine abgebrochene
#                                Installation darf den Dienst nicht fuer
#                                immer stilllegen)
#   keine lesbare Uhr         -> die Pruefung faellt GESCHLOSSEN aus
#                                (CLAUDE.md 4; Fall C6)
#   GV_START_TROTZ_MARKE=1    -> Ausnahme fuer das letzte Hakenskript
#                                (Fall C10)
#
# Die Ausnahme gehoert postupgrade.sh: es startet den Dienst, BEVOR es die
# Marke entfernt. Faellt die Marke vorher, ist das Fenster zwischen dem
# "touch soll_laufen" hier unten und dem Schreiben der PID-Datei fuer den
# Minutentakt offen (bei Chromecast4lox 1.3.10 in 400 Waechterlaeufen
# gemessen, Regeln/06).
marke_sperrt() {
    [ -f "$MARKE" ] || return 1
    [ "${GV_START_TROTZ_MARKE:-0}" = "1" ] && return 1
    JETZT=$(date +%s 2>/dev/null)
    case "$JETZT" in ''|*[!0-9]*) return 0 ;; esac
    SEIT=$(cat "$MARKE" 2>/dev/null)
    case "$SEIT" in ''|*[!0-9]*) return 1 ;; esac
    ALTER=$((JETZT - SEIT))
    [ "$ALTER" -lt 0 ] && return 1
    [ "$ALTER" -le 3600 ]
}

starten() {
    if laeuft; then
        echo "laeuft bereits (PID $(cat "$PID"))"
        return 0
    fi
    # Diese Frage steht VOR dem touch auf soll_laufen weiter unten. Stuende
    # sie dahinter, legte der abgewiesene Start den Merker trotzdem an, und
    # der Waechter startete den Dienst eine Minute spaeter doch (Fall A6).
    # Rueckgabewert 0: eine laufende Aktualisierung ist kein Fehlschlag, und
    # postupgrade.sh soll deswegen nicht "liess sich nicht starten" melden.
    if marke_sperrt; then
        echo "Eine Aktualisierung dieses Plugins laeuft - der Dienst wird danach gestartet."
        return 0
    fi
    if ! command -v php >/dev/null 2>&1; then
        echo "FEHLER: PHP nicht gefunden - ohne PHP laeuft der Dienst nicht."
        return 1
    fi
    if [ ! -f "$SKRIPT" ]; then
        echo "FEHLER: $SKRIPT fehlt. Plugin neu installieren."
        return 1
    fi
    if [ ! -f "$PCONFIG/govee.json" ]; then
        echo "FEHLER: Konfiguration fehlt ($PCONFIG/govee.json). Erst die Oberflaeche oeffnen."
        return 1
    fi
    touch "$SOLL"
    # Die Ausgabe des Dienstes geht in die Startdatei, NICHT in das Protokoll:
    # dort schreibt allein das Programm selbst. Beim Start gekappt, damit sie
    # nur die Ausgabe EINES Laufes sammelt und nicht unbegrenzt waechst.
    : > "$STARTLOG"
    nohup php "$SKRIPT" >> "$STARTLOG" 2>&1 &
    echo $! > "$PID"
    sleep 1
    if laeuft; then
        echo "gestartet (PID $(cat "$PID"))"
        return 0
    fi
    echo "FEHLER: Start fehlgeschlagen - siehe $STARTLOG und $LOGDATEI"
    echo "Haeufigste Ursache: der UDP-Port 4002 ist schon belegt. Nur ein"
    echo "Programm kann ihn halten, und die Govee-Leuchten antworten nur dorthin."
    rm -f "$PID"
    return 1
}

anhalten() {
    rm -f "$SOLL"
    ETWAS=0
    if laeuft; then
        P=$(cat "$PID")
        kill "$P" 2>/dev/null
        for i in 1 2 3 4 5 6 7 8 9 10; do
            laeuft || break
            sleep 1
        done
        # laeuft() prueft die Befehlszeile erneut - hart beendet wird also nur,
        # was immer noch der eigene Dienst ist.
        if laeuft; then
            kill -9 "$P" 2>/dev/null
            sleep 1
        fi
        ETWAS=1
    fi
    rm -f "$PID"
    # Und jeder eigene Dienst, der in KEINER PID-Datei steht. Es gibt ihn:
    # purge_installation loescht data/plugins/<ordner>/ beim Upgrade restlos
    # (Regeln/06), ein Start von Hand schreibt sie gar nicht erst, und
    # uninstall/uninstall ruft dieses stop, bevor es selbst aufraeumt. Bis
    # 0.9.18 meldete "stop" in dieser Lage "laeuft nicht", und der Dienst hielt
    # den UDP-Port 4002 weiter. In WSL gemessen (Pruefung-Govee-0.9.18,
    # Fall C4, 18.09.2026).
    WAISEN=$(waisen_beenden)
    if [ -n "$WAISEN" ]; then
        echo "angehalten; zusaetzlich ein Dienst ohne PID-Datei beendet (PID $WAISEN)"
        return 0
    fi
    if [ "$ETWAS" = "1" ]; then
        echo "angehalten"
        return 0
    fi
    echo "laeuft nicht"
    return 0
}

case "$1" in
    start)   starten ;;
    stop)    anhalten ;;
    restart) anhalten; sleep 1; starten ;;
    status)
        if laeuft; then
            echo "laeuft $(cat "$PID")"
            exit 0
        fi
        echo "gestoppt"
        exit 1
        ;;
    selbsttest)
        php "$SKRIPT" --selbsttest
        exit $?
        ;;
    waechter)
        # Nur neu starten, wenn der Dienst laufen SOLL. Ein bewusst
        # angehaltener Dienst bleibt angehalten.
        if [ -f "$SOLL" ] && ! laeuft; then
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: Dienst lief nicht, wird neu gestartet." >> "$LOGDATEI"
            starten >> "$STARTLOG" 2>&1
        fi
        ;;
    *)
        echo "Aufruf: $0 {start|stop|restart|status|selbsttest|waechter}"
        exit 2
        ;;
esac
