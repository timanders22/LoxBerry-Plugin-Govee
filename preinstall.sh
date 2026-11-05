#!/bin/bash

# Govee - preinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Neu im Verbesserungsbau vom 01.10.2026 (X-1, Entscheidung 1 vom 29.09.2026;
# Muster: Abfahrts-Assistent 1.6.16). Der Installer ruft dieses Skript bei
# JEDEM Einbau auf, nach dem Aufraeumen der alten Fassung und VOR dem Kopieren
# von Konfiguration, Cron-Datei und Oberflaeche (sbin/plugininstall.pl:
# preupgrade :846, purge :874, preinstall :877, Cron :990, HTML :1066 -
# Geraet/2026-09-05/08_plugininstall.pl).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt.
# Dann tut es nichts: die Zweitschriften braucht postinstall.sh zum
# Zurueckspielen. Seit 0.9.34 gilt eine Marke, die marke_sperrt() in
# bin/dienst.sh nicht mehr gelten liesse (aelter als 3600 s, mehr als 300 s aus
# der Zukunft, keine Zahl), als verwaist: Sie wird entfernt und gemeldet, und es
# geht weiter wie bei einer Neuinstallation. preupgrade.sh und dieses Skript
# trennt nur der Purge, also Sekunden; bis 0.9.33 liess der Rest einer
# abgebrochenen Aktualisierung eine spaetere Neuinstallation alte Token und den
# Cloud-Schluessel einspielen. Ohne lesbare Uhr gilt die Marke weiter (wie dort).
#
# Ohne Marke ist es eine NEUINSTALLATION. Liegengebliebene Zweitschriften
# einer frueheren Installation - <ordner>.backup.json (Konfiguration mit
# Aktionstoken), <ordner>.backup.govee.json (alter Name aus 0.9.8) und
# <ordner>.backup.geheim.json (Govee-API-Schluessel) - gehen nach <name>.alt,
# gemeldet mit genau einer <WARNING>.
#
# Warum schon hier und nicht erst in postinstall.sh: zwischen dem Kopieren und
# postinstall.sh fragt der Miniserver den Endpunkt mit seinem ALTEN Token ab.
# Bei leerer govee.json liest die Bibliothek die Zweitschrift, das Token
# passt, und der Abruf heilte die govee.json der neuen Installation aus der
# alten Zweitschrift (in WSL gemessen, vb_gov_bau_skripte/proben/x1_*).
# postinstall.sh legte die Zweitschrift danach zwar beiseite, die geheilte
# govee.json blieb aber stehen. Die Selbstheilung der Bibliothek liest .alt
# nie; die Deinstallation raeumt es ab.

ARGV3=$3
ARGV5=$5
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"
PFOLDER="${ARGV3:-govee}"
BASE="${ARGV5:-$LBHOMEDIR}"

# Wurzelsuche wie in preupgrade.sh, postinstall.sh und postupgrade.sh: ohne
# config/plugins, data/plugins UND config/system/general.json wird nichts
# angefasst (Regeln/06, Raumklima-Vorfall).
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
# Der Ordnername darf keinen Pfadtrenner tragen, sonst griffe mv/rm daneben.
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac

MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -f "$MARKE" ]; then
    GV_VERWAIST=0
    GV_JETZT=$(date +%s 2>/dev/null)
    GV_SEIT=$(cat "$MARKE" 2>/dev/null)
    case "$GV_JETZT" in
        ''|*[!0-9]*) ;;
        *)  case "$GV_SEIT" in
                ''|*[!0-9]*) GV_VERWAIST=1 ;;
                *)  GV_ALTER=$((GV_JETZT - GV_SEIT))
                    if [ "$GV_ALTER" -lt -300 ] || [ "$GV_ALTER" -gt 3600 ]; then GV_VERWAIST=1; fi ;;
            esac ;;
    esac
    if [ "$GV_VERWAIST" = 0 ]; then
        # Aktualisierung: nichts zu tun, postinstall.sh spielt zurueck.
        exit 0
    fi
    # Laesst sie sich nicht entfernen, findet postinstall.sh sie, aber keine Zweitschrift mehr: es spielt nichts zurueck.
    if rm -f "$MARKE" 2>/dev/null; then
        echo "<WARNING> Eine verwaiste Marke einer abgebrochenen Aktualisierung wurde entfernt - das hier gilt als Neuinstallation."
    else
        echo "<WARNING> Die verwaiste Marke $MARKE liess sich nicht entfernen - das hier gilt trotzdem als Neuinstallation."
    fi
fi

SB="$BASE/config/plugins/$PFOLDER.backup"
BEISEITE=""
FEST=""
for ZIEL in "$SB.json" "$SB.govee.json" "$SB.geheim.json"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            BEISEITE="$BEISEITE $ZIEL.alt"
            [ -f "$ZIEL.alt" ] && [ ! -L "$ZIEL.alt" ] && chmod 600 "$ZIEL.alt" 2>/dev/null
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done

if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    GV_TEXT="<WARNING> Neuinstallation: Einstellungen einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && GV_TEXT="$GV_TEXT Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && GV_TEXT="$GV_TEXT Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$GV_TEXT"
fi
exit 0
