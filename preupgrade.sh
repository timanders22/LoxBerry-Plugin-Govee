#!/bin/bash
# Govee - preupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Die Reihenfolge des Installers ist:
#   preupgrade -> config/* aus dem Archiv ueber config/plugins/<ordner>
#              -> postinstall -> postupgrade -> Cleaning
# Wer eine Konfiguration ueber das Upgrade retten will, muss das VOR dem
# Kopierschritt tun, also hier - und nicht nach /tmp, das auf dem LoxBerry
# fluechtig ist.
#
# ACHTUNG: $1 ist NICHT der Arbeitsordner, sondern eine zehnstellige
# Zufallskennung aus &generate(10). Der absolute Arbeitsordner steht im
# sechsten Argument. Deshalb wird hier ausschliesslich mit $3 und $5
# gearbeitet.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-govee}"
BASE="${ARGV5:-$LBHOMEDIR}"

PID="$BASE/data/plugins/$PFOLDER/dienst.pid"
if [ -f "$PID" ]; then
    kill "$(cat "$PID")" 2>/dev/null || true
    sleep 2
    kill -9 "$(cat "$PID")" 2>/dev/null || true
    rm -f "$PID"
    echo "<INFO> Laufender Dienst angehalten - er haelt den UDP-Port 4002."
fi

CF="$BASE/config/plugins/$PFOLDER/govee.json"
if [ -f "$CF" ]; then
    cp -p "$CF" "$BASE/config/plugins/$PFOLDER.backup.json" \
        && chmod 600 "$BASE/config/plugins/$PFOLDER.backup.json" 2>/dev/null \
        && echo "<OK> Konfiguration gesichert."
fi
# Die Datei mit dem Cloud-Schluessel WIRD neben den Ordner gesichert - und
# das war bis 0.9.8 anders begruendet. Hier stand, sie werde bewusst nicht
# gesichert, weil eine Sicherung daneben die Deinstallation ueberlebt und dort
# ein gueltiger API-Schluessel herrenlos stuende. Vier Zeilen weiter unten tat
# der spaeter angefuegte Block genau das - zwei Aussagen in einer Datei, von
# denen eine falsch sein musste.
#
# Aufgeloest wird das so: gesichert wird sie (der Installer raeumt den
# Konfigordner ab, und eine nie mitgelieferte Datei steht auf keiner Liste),
# und die Deinstallation loescht die Sicherung ausdruecklich mit. Genau dafuer
# gibt es uninstall/uninstall.
echo "<OK> preupgrade abgeschlossen."

# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zweitschrift NEBEN den Konfigurationsordner, zusaetzlich zur bisherigen
# Sicherung. Grund: der Installer kopiert config/* aus dem Archiv ueber
# config/plugins/<ordner> (plugininstall.pl Zeile 899, cp -r ohne -n) und
# ueberschreibt dabei die Datei des Nutzers. Bisher haing die Rettung allein
# an postupgrade.sh. Laeuft das aus irgendeinem Grund nicht durch, greift
# jetzt postinstall.sh auf diese Zweitschrift zu - sie liegt ausserhalb des
# ueberschriebenen Ordners und wird vom Installer nicht angefasst.
# EIN Verfahren, nicht zwei. Bis 0.9.8 entstanden hier drei Dateien flach
# nebeneinander - <ordner>.backup.json, <ordner>.backup.govee.json und
# <ordner>.backup.geheim.json -, von denen eine wie die Kurzform der anderen
# aussah. Die Konfiguration ist oben schon nach <ordner>.backup.json gesichert;
# ein zweites Mal unter anderem Namen bringt nichts und kostet Verwechslung.
NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-govee}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"


# NICHT MITGELIEFERTE Dateien - und gerade deshalb die wichtigen.
# Das Archiv liefert sie nie, also standen sie bis jetzt auf keiner Liste;
# geloescht werden sie vom Installer trotzdem, samt Token und Zugangsdaten.
if [ -s "$NETZ_CFG/geheim.json" ]; then
    cp -p "$NETZ_CFG/geheim.json" "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.geheim.json" 2>/dev/null \
        && chmod 0600 "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.geheim.json" 2>/dev/null
fi

exit 0
