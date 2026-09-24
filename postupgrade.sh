#!/bin/bash
# Govee - postupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# postinstall.sh laeuft beim Upgrade ohnehin - der Installer ruft es immer
# auf. Wuerde dieses Skript es zusaetzlich starten, liefe es ZWEIMAL, mit
# allem, was darin nicht idempotent ist. Deshalb steht hier nur das, was
# ausschliesslich nach einem Upgrade zu tun ist: den Dienst wieder starten,
# wenn er vorher laufen sollte.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-govee}"
BASE="${ARGV5:-$LBHOMEDIR}"

# ---------- Die Wurzel: GELESEN, nicht geraten ----------
#
# Bis 0.9.20 stand hier als Rueckfall "$SELF/../.." ohne general.json: in
# einem fremden Baum startete dieses Skript dessen dienst.sh und raeumte
# dessen Upgrade-Marke ab (in WSL gemessen, Pruefung-Govee-0.9.21, Fall W2).
# Gesucht wird wie in preupgrade.sh aufwaerts nach config/plugins,
# data/plugins UND config/system/general.json (Regeln/06); ohne Wurzel wird
# gewarnt statt vollzogen.
gv_wurzel_suchen() {
    gv_v=$(cd "$1" 2>/dev/null && pwd -P) || return 1
    gv_i=0
    while [ -n "$gv_v" ] && [ "$gv_v" != "/" ] && [ "$gv_i" -lt 8 ]; do
        if [ -d "$gv_v/config/plugins" ] && [ -d "$gv_v/data/plugins" ] \
           && [ -f "$gv_v/config/system/general.json" ]; then
            echo "$gv_v"
            return 0
        fi
        gv_v=$(dirname "$gv_v")
        gv_i=$((gv_i + 1))
    done
    return 1
}
SELF=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ]; then
    BASE=$(gv_wurzel_suchen "$SELF") || BASE=""
fi
if [ -z "$BASE" ]; then
    echo "<WARNING> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden: weder als"
    echo "<WARNING> fuenftes Argument noch in \$LBHOMEDIR, und oberhalb von $SELF"
    echo "<WARNING> traegt kein Verzeichnis config/plugins, data/plugins und"
    echo "<WARNING> config/system/general.json. Es wurde kein Dienst gestartet"
    echo "<WARNING> und nichts abgeraeumt."
    exit 1
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
# Der Merker gilt, wenn er hoechstens 3600 s alt ist - und bis 300 s "aus der
# Zukunft", wie die Marke in bin/dienst.sh: die Uhr kann zwischen
# preupgrade.sh und hier ein Stueck zurueckspringen. Bis 0.9.19 galt jede
# Sekunde Zukunft als "nicht", und der Dienst blieb nach dem Upgrade aus; in
# WSL sprang die Uhr gemessen um 2 s zurueck, und zwei von vier Laeufen des
# Pruefstands 0.9.19 meldeten deshalb "lief vor dem Upgrade nicht"
# (Pruefung-Govee-0.9.20, Faelle M2/M3).
#
# Beide Zahlen werden VOR der Rechnung als Zahl geprueft: bash wertet in
# $(( )) den INHALT einer Variablen aus (Klasse M) - ein date, das statt einer
# Zahl etwas wie a[$(befehl)] liefert, fuehrte den Befehl aus (Fall M8b).
#
# Ohne lesbare Uhr gilt der Merker (Fall M7). Das ist hier die sichere Seite,
# anders als bei der Marke: preupgrade.sh loescht einen alten Merker als
# Erstes und legt ihn nur an, wenn soll_laufen lag, und uninstall raeumt ihn
# weg - ein Merker, den dieses Skript findet, stammt aus DIESEM Upgrade. Fiele
# die Pruefung geschlossen aus, bliebe ein Dienst, der lief, nach dem Upgrade
# aus (so am Geraet nach 0.9.15: neun Tage). Ein Merker ohne Zahl gilt weiter
# nicht - dann ist nicht zu sagen, woher er stammt (Fall M6).
if [ -f "$MERKER" ]; then
    T=$(cat "$MERKER" 2>/dev/null)
    JETZT=$(date +%s 2>/dev/null)
    case "$T" in
        ''|*[!0-9]*) ;;
        *) case "$JETZT" in
               ''|*[!0-9]*)
                   echo "<WARNING> Die Uhr ist nicht lesbar - der Merker aus diesem Upgrade gilt trotzdem."
                   SOLL=1 ;;
               *) ALTER=$((JETZT - T))
                  [ "$ALTER" -ge -300 ] && [ "$ALTER" -le 3600 ] && SOLL=1 ;;
           esac ;;
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
