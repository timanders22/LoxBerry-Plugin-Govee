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

SOLL="$BASE/data/plugins/$PFOLDER/soll_laufen"
DIENST="$BASE/bin/plugins/$PFOLDER/dienst.sh"
if [ -f "$SOLL" ] && [ -x "$DIENST" ]; then
    "$DIENST" start && echo "<OK> Dienst nach dem Upgrade wieder gestartet."
else
    echo "<INFO> Der Dienst lief vor dem Upgrade nicht und bleibt angehalten."
fi
echo "<OK> postupgrade abgeschlossen."
exit 0
