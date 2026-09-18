#!/bin/bash
# Govee - postupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# postinstall.sh laeuft beim Upgrade ohnehin - der Installer ruft es immer
# auf. Wuerde dieses Skript es zusaetzlich starten, liefe es ZWEIMAL, mit
# allem, was darin nicht idempotent ist. Deshalb steht hier nur das, was
# ausschliesslich nach einem Upgrade zu tun ist: den Dienst wieder starten,
# wenn er vorher laufen sollte.
SELF=$(cd "$(dirname "$0")" && pwd)
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-govee}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

# Der Merker liegt NEBEN dem Datenordner (siehe preupgrade.sh). Bis 0.9.15
# stand hier nur der Blick IN den Ordner, und der ist zu diesem Zeitpunkt vom
# Installer schon geleert - der Blick fand nie etwas, und die Meldung "lief
# nicht" stimmte auch dann nicht, wenn er lief.
MERKER="$BASE/data/plugins/$PFOLDER.soll_laufen"
DIENST="$BASE/bin/plugins/$PFOLDER/dienst.sh"
SOLL=0

# ---------- Die Marke "Aktualisierung laeuft" faellt hier ----------
# Dieses Skript ist das LETZTE, das LoxBerry in dieser Linie aufruft:
# preroot, preinstall, preupgrade, postinstall, postupgrade, postroot
# (Regeln/06) - preroot.sh, preinstall.sh und postroot.sh gibt es hier nicht.
#
# Entfernt wird die Marke ueber einen trap auf EXIT, nicht am Dateiende: dann
# faellt sie auch, wenn dieses Skript vorzeitig aussteigt. Ohne das bliebe der
# Dienst nach einer abgebrochenen Aktualisierung eine Stunde gesperrt, ohne
# dass irgendwo stuende, warum.
#
# Der trap laeuft NACH dem Dienststart weiter unten. Das ist Absicht: waehrend
# "dienst.sh start" den Merker soll_laufen anlegt und bis es die PID-Datei
# geschrieben hat, ist ein Fenster offen, in dem der Minutentakt denselben
# Dienst ein zweites Mal startet. Solange die Marke liegt, ist dieses Fenster
# zu. Der eigene Start bekommt deshalb die Ausnahme GV_START_TROTZ_MARKE=1
# (bin/dienst.sh, marke_sperrt(); Vorbild Chromecast4lox 1.3.10, dort in 400
# Waechterlaeufen gemessen).
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
gv_marke_weg() { rm -f "$MARKE"; }
trap gv_marke_weg EXIT
if [ -f "$MERKER" ]; then
    T=$(cat "$MERKER" 2>/dev/null)
    JETZT=$(date +%s)
    case "$T" in
        ''|*[!0-9]*) ;;
        *) ALTER=$((JETZT - T))
           [ "$ALTER" -ge 0 ] && [ "$ALTER" -le 3600 ] && SOLL=1 ;;
    esac
    rm -f "$MERKER"
fi
if [ "$SOLL" -eq 1 ] && [ -f "$DIENST" ]; then
    # Ueber die Shell, nicht unmittelbar: ein fehlendes Ausfuehrungsrecht soll
    # den Start nicht still verhindern (dieselbe Begruendung wie in
    # cron/cron.01min).
    if GV_START_TROTZ_MARKE=1 /bin/bash "$DIENST" start; then
        echo "<OK> Dienst nach dem Upgrade wieder gestartet."
    else
        echo "<WARNING> Der Dienst lief vor dem Upgrade, liess sich aber nicht wieder starten - Reiter Einstellungen, Knopf 'Dienst starten'."
    fi
else
    echo "<INFO> Der Dienst lief vor dem Upgrade nicht und bleibt angehalten."
fi
echo "<OK> postupgrade abgeschlossen."
exit 0
