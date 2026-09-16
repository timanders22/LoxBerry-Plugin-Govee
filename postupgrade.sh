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
    if /bin/bash "$DIENST" start; then
        echo "<OK> Dienst nach dem Upgrade wieder gestartet."
    else
        echo "<WARNING> Der Dienst lief vor dem Upgrade, liess sich aber nicht wieder starten - Reiter Einstellungen, Knopf 'Dienst starten'."
    fi
else
    echo "<INFO> Der Dienst lief vor dem Upgrade nicht und bleibt angehalten."
fi
echo "<OK> postupgrade abgeschlossen."
exit 0
