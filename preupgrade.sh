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

# ---------- Die Wurzel: GELESEN, nicht geraten ----------
#
# Bis 0.9.20 stand hier NUR die Zeile darueber - ohne Rueckfall und ohne
# Pruefung. Bleiben $5 und $LBHOMEDIR leer, war BASE leer, und die naechsten
# Zeilen legten /data/plugins/<ordner>.upgrade_laeuft ab der LAUFWERKSWURZEL
# an (in WSL gemessen, Pruefung-Govee-0.9.21, Fall W4). Gesucht wird
# aufwaerts nach config/plugins, data/plugins UND config/system/general.json
# (Regeln/06); ohne Wurzel wird gewarnt statt vollzogen. Bauart VolkswagenID
# 0.9.24; dieselbe Suche steht in postinstall.sh, postupgrade.sh und
# uninstall/uninstall.
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
    echo "<WARNING> config/system/general.json. Es wurde nichts angelegt, nichts"
    echo "<WARNING> gesichert und kein Dienst angehalten."
    exit 1
fi

# ---------- Zuerst die Marke "Aktualisierung laeuft" ----------
# Sie steht VOR allem anderen, damit sie auch dann liegt, wenn weiter unten
# etwas schiefgeht. Der Installer legt die Cron-Datei rund eine Minute vor
# postinstall.sh neu an; in dieser Luecke ist der Datenordner schon geloescht,
# die neuen Dateien liegen aber bereit (Regeln/06, am Geraet am 08.09.2026 am
# Installationsprotokoll gemessen: preupgrade 03:31:30, Cron neu 03:31:32,
# postinstall erst 03:32:24).
#
# Der minuetliche Waechter dieser Linie startet in der Luecke nichts - er
# verlangt data/plugins/<ordner>/soll_laufen, und den hat purge_installation
# gerade mitgeloescht (Pruefung-Govee-0.9.19, Faelle A1 und A4). Der Knopf
# "Dienst starten" in der Oberflaeche aber schon: er startete dort einen
# Dienst und legte soll_laufen wieder an, sodass ein bewusst angehaltener
# Dienst nach dem Upgrade lief (Faelle A5 bis A7, D1/D2).
#
# Die Marke liegt NEBEN dem Datenordner - im Ordner loeschte sie
# purge_installation mit. Im Inhalt steht die Unixzeit; bin/dienst.sh nimmt
# sie nur, solange sie hoechstens eine Stunde alt ist.
mkdir -p "$BASE/data/plugins" 2>/dev/null
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
date +%s > "$MARKE" 2>/dev/null
if [ -s "$MARKE" ]; then
    echo "<OK> Dienststart bis zum Ende der Aktualisierung gesperrt."
else
    echo "<WARNING> Die Marke $MARKE liess sich nicht anlegen - der Dienst"
    echo "<WARNING> koennte waehrend der Aktualisierung anlaufen."
fi

# Ob der Dienst nach dem Upgrade wieder anlaufen soll, muss HIER festgehalten
# werden - vor dem Aufraeumen des Installers - und NEBEN dem Datenordner.
# Zwischen preupgrade und postinstall loescht purge_installation
# data/plugins/<ordner>/ restlos (Regeln/06). Bis 0.9.15 las postupgrade.sh
# den Merker soll_laufen IN diesem Ordner: er war dort nie mehr zu finden, ein
# laufender Dienst blieb nach jedem Upgrade aus, und das Protokoll sagte "lief
# vor dem Upgrade nicht". Am Geraet so gesehen: nach dem Upgrade auf 0.9.15
# (08.09.2026) lief Govee neun Tage nicht. Im Merker steht der Zeitpunkt;
# postupgrade.sh nimmt ihn nur, wenn er hoechstens eine Stunde alt ist - der
# Rest eines abgebrochenen Upgrades soll keine spaetere Neuinstallation
# starten.
MERKER="$BASE/data/plugins/$PFOLDER.soll_laufen"
rm -f "$MERKER"
if [ -f "$BASE/data/plugins/$PFOLDER/soll_laufen" ]; then
    date +%s > "$MERKER" 2>/dev/null \
        && echo "<INFO> Der Dienst soll laufen - Merker fuer postupgrade gesetzt."
fi

# ---------- Den eigenen Dienst anhalten ----------
# Beendet wird erst NACH einer argumentweisen Gegenprobe, und die steht vor
# JEDEM Signal - auch vor dem harten. Prozessnummern werden wiederverwendet:
# liegt eine alte dienst.pid herum und traegt ihre Zahl inzwischen einen
# fremden Vorgang, beendete das erste Signal genau den. Bis 0.9.18 ging hier
# beides ungeprueft hinaus. In WSL gemessen (Pruefung-Govee-0.9.18, Faelle A1
# bis A3, 18.09.2026): ein "sleep 600", dessen Nummer in dienst.pid stand, war
# nach preupgrade.sh tot - und die Meldung behauptete dazu "Laufender Dienst
# angehalten - er haelt den UDP-Port 4002."
#
# Geprueft werden drei Dinge (Regeln/03, "Prozesse argumentweise erkennen"):
# argv[0] ist ein PHP, argv[1] ist GENAU dieser Dienstpfad - nicht nur der
# Dateiname, sonst traefe es "nano <pfad>/govee_dienst.php" ebenso wie den
# Dienst einer zweiten Installation im Nachbarordner -, und der Prozess gehoert
# dem Dienstbenutzer. Vorbild: LoxBerry-Plugin-APC-UPS-1.2.11 (apc_ist_dienst),
# LoxBerry-Plugin-Midea2Lox-4.5.7 (eigener_dienst).
GV_DIENST="$BASE/bin/plugins/$PFOLDER/govee_dienst.php"
# Der Dienst laeuft als loxberry (bin/dienst.sh steigt dorthin ab); wo es den
# Benutzer nicht gibt, als der eigene.
GV_UID=$(id -u loxberry 2>/dev/null || id -u)

gv_ist_dienst() {   # $1 PID, $2 Dienstpfad, $3 UID ("" = Benutzer nicht pruefen)
    [ -r "/proc/$1/cmdline" ] || return 1
    if [ -n "$3" ]; then
        [ "$(stat -c %u "/proc/$1" 2>/dev/null)" = "$3" ] || return 1
    fi
    # Gelesen wird mit cat, nicht mit einer Umlenkung: endet der Prozess
    # zwischen Auflistung und Lesen, meldet die Schale die fehlgeschlagene
    # Umlenkung selbst auf die Fehlerausgabe - mitten in das Protokoll des
    # Installers hinein. Das 2>/dev/null am Ende der Zeile faengt sie nicht.
    gv_roh=$(cat "/proc/$1/cmdline" 2>/dev/null | tr '\0' '\n')
    [ -n "$gv_roh" ] || return 1
    gv_a0=$(printf '%s\n' "$gv_roh" | sed -n '1p')
    gv_a1=$(printf '%s\n' "$gv_roh" | sed -n '2p')
    # Genau zwei Argumente: ein Einmallauf wie 'php <dienst> --einmal' oder
    # '--selbsttest' ist kein Dienst (Regeln/06, argumentweise Erkennung).
    # Bis 0.9.20 wurde er hier beendet (in WSL gemessen,
    # Pruefung-Govee-0.9.21, Faelle D4/D5).
    [ -z "$(printf '%s\n' "$gv_roh" | sed -n '3p')" ] || return 1
    [ -n "$gv_a0" ] && [ -n "$gv_a1" ] || return 1
    case "${gv_a0##*/}" in php|php[0-9.]*) ;; *) return 1 ;; esac
    # Ein relativer Pfad wird gegen das Arbeitsverzeichnis des Prozesses
    # aufgeloest. dienst.sh startet zwar immer absolut; ein Start von Hand aus
    # dem bin-Ordner heraus muss aber genauso erkannt werden.
    case "$gv_a1" in
        /*) gv_ziel=$gv_a1 ;;
        *)  gv_wd=$(readlink "/proc/$1/cwd" 2>/dev/null) || return 1
            gv_ziel="${gv_wd% (deleted)}/$gv_a1" ;;
    esac
    [ "$gv_ziel" = "$2" ]
}
# Alle eigenen Dienste des Dienstbenutzers - unabhaengig von der PID-Datei.
gv_dienste_suchen() {   # $1 Dienstpfad, $2 UID
    for gv_d in /proc/[0-9]*; do
        gv_ist_dienst "${gv_d#/proc/}" "$1" "$2" && echo "${gv_d#/proc/}"
    done
    return 0
}
# Beendet sie (zehn Sekunden Zeit, dann hart) und gibt die Nummern aus.
gv_dienste_beenden() {  # $1 Dienstpfad, $2 UID
    gv_liste=$(gv_dienste_suchen "$1" "$2")
    [ -n "$gv_liste" ] || return 0
    kill $gv_liste 2>/dev/null
    gv_i=0
    while [ $gv_i -lt 10 ] && [ -n "$(gv_dienste_suchen "$1" "$2")" ]; do
        sleep 1
        gv_i=$((gv_i + 1))
    done
    gv_rest=$(gv_dienste_suchen "$1" "$2")
    [ -n "$gv_rest" ] && kill -9 $gv_rest 2>/dev/null
    echo $gv_liste
}

PID="$BASE/data/plugins/$PFOLDER/dienst.pid"
if [ -f "$PID" ]; then
    P=$(cat "$PID" 2>/dev/null)
    case "$P" in ''|*[!0-9]*) P="" ;; esac
    if [ -n "$P" ] && kill -0 "$P" 2>/dev/null && gv_ist_dienst "$P" "$GV_DIENST" "$GV_UID"; then
        kill "$P" 2>/dev/null
        GV_I=0
        while [ $GV_I -lt 10 ] && kill -0 "$P" 2>/dev/null; do
            sleep 1
            GV_I=$((GV_I + 1))
        done
        # Hart nur, wenn er noch lebt UND es immer noch unser Dienst ist: in
        # der Wartezeit kann die Nummer frei geworden und neu vergeben sein.
        if kill -0 "$P" 2>/dev/null && gv_ist_dienst "$P" "$GV_DIENST" "$GV_UID"; then
            kill -9 "$P" 2>/dev/null
        fi
        # Nur HIER gemeldet: eine liegengebliebene PID-Datei ist kein
        # laufender Dienst, und ein fremder Vorgang erst recht nicht.
        echo "<INFO> Laufender Dienst angehalten - er haelt den UDP-Port 4002."
    elif [ -n "$P" ] && kill -0 "$P" 2>/dev/null; then
        echo "<INFO> Die Nummer $P aus dienst.pid gehoert einem fremden Vorgang -"
        echo "<INFO> es wurde nichts beendet, die Datei wird entfernt."
    else
        echo "<INFO> Der Dienst lief nicht - es war nichts anzuhalten."
    fi
    rm -f "$PID"
fi

# Dazu jeder eigene Dienst OHNE PID-Datei. Die PID-Datei liegt im Datenordner,
# und den raeumt purge_installation zwischen preupgrade und postinstall
# restlos ab (Regeln/06); nach einem abgebrochenen Upgrade oder einem Start von
# Hand gibt es sie gar nicht. Ohne diesen Zweig liefe der alte Dienst durch das
# ganze Upgrade weiter, hielte den UDP-Port 4002, und der neue scheiterte
# genau daran. Nur PHP mit genau diesem Skript, nur der Dienstbenutzer - nie
# ein Teilwort systemweit. In WSL gemessen (Pruefung-Govee-0.9.18, Faelle A5
# und A6): ohne den Zweig lief der Waise nach preupgrade.sh weiter, und bei
# zwei eigenen Diensten blieb einer stehen.
WAISEN=$(gv_dienste_beenden "$GV_DIENST" "$GV_UID")
if [ -n "$WAISEN" ]; then
    echo "<INFO> Ein Dienst ohne PID-Datei lief und wurde beendet (PID $WAISEN)."
fi

# Traegt eine Datei INHALT? Rueckgabe 0 ja, 1 nein, 2 nicht pruefbar (kein php).
#   config   lesbares JSON-Objekt mit Aktionstoken ODER mindestens einem Geraet
#            ODER einem Wert, der von gv_vorgaben() der installierten
#            Bibliothek abweicht (eigenes MQTT-Thema, Takt ...). Ohne die
#            dritte Bedingung ging eine solche Einstellung beim Upgrade verloren
#            (in WSL gemessen, Pruefung-Govee-0.9.21, Fall Z9; Rueckschritt
#            klasse_g B1/E2). Fehlt die Bibliothek, ist das "nicht pruefbar".
#   geraete  mindestens ein Geraet - "eingerichtet" fuer das Schlusswort in
#            postinstall.sh; ein Token allein entsteht schon beim ersten
#            Oeffnen der Oberflaeche
#   geheim   nicht leerer Cloud-Schluessel
# Nie nach Groesse entscheiden (Muster 9 der Nachlese 24.09.2026; Klasse C,
# Bestand-2026-09-18/klasse-C/Ergebnis.md). Wortgleich in preupgrade.sh und
# postinstall.sh; Bauart sp_inhalt() aus Spotpreis-aWATTar 1.2.28.
gv_inhalt() {   # $1 Datei, $2 Art
    [ -s "$1" ] || return 1
    command -v php >/dev/null 2>&1 || return 2
    php -d allow_url_fopen=0 -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
        if (!is_array($d)) { exit(1); }
        $t = isset($d["aktionstoken"]) && is_string($d["aktionstoken"]) && trim($d["aktionstoken"]) !== "";
        $g = isset($d["geraete"]) && is_array($d["geraete"]) && count($d["geraete"]) > 0;
        $k = isset($d["cloud_key"]) && is_string($d["cloud_key"]) && trim($d["cloud_key"]) !== "";
        if ($argv[2] === "geheim") { exit($k ? 0 : 1); }
        if ($argv[2] === "geraete") { exit($g ? 0 : 1); }
        if ($t || $g) { exit(0); }
        if (!is_file($argv[3])) { exit(3); }
        require $argv[3];
        if (!function_exists("gv_vorgaben")) { exit(3); }
        foreach (gv_vorgaben() as $s => $v) {
            if (array_key_exists($s, $d) && $d[$s] != $v) { exit(0); }
        }
        exit(1);' "$1" "$2" "$BASE/webfrontend/html/plugins/$PFOLDER/gv_lib.php" >/dev/null 2>&1
    gv_irc=$?
    [ "$gv_irc" = 0 ] || [ "$gv_irc" = 1 ] || return 2
    return "$gv_irc"
}

# Gesichert wird nur, was INHALT traegt. Bis 0.9.20 ueberschrieb jede
# govee.json die Zweitschrift - auch "{}" oder eine abgeschnittene Datei -, und
# geheim.json wurde nach Groesse gesichert: ein leerer Cloud-Schluessel
# ueberschrieb die gute Sicherung (in WSL gemessen, Pruefung-Govee-0.9.21,
# Faelle Z1/Z2). Eine Datei ohne Inhalt laesst die vorhandene Sicherung
# unberuehrt; ist der Inhalt nicht pruefbar, wird nur gesichert, wo noch
# keine Sicherung liegt.
gv_sichern() {   # $1 Datei, $2 Sicherung, $3 Art (config|geheim), $4 Name fuer die Meldung
    [ -f "$1" ] || return 0
    gv_inhalt "$1" "$3"
    case $? in
        0)  if cp -p "$1" "$2" 2>/dev/null; then
                chmod 600 "$2" 2>/dev/null
                echo "<OK> $4 gesichert."
            else
                echo "<WARNING> $4 liess sich nicht sichern ($2)."
            fi ;;
        1)  if [ -f "$2" ]; then
                echo "<INFO> $(basename "$1") traegt keine Einstellungen - die vorhandene Sicherung bleibt unberuehrt."
            fi ;;
        *)  echo "<WARNING> Der Inhalt von $(basename "$1") liess sich nicht pruefen (php fehlt) -"
            echo "<WARNING> eine vorhandene Sicherung bleibt unberuehrt."
            if [ ! -f "$2" ] && cp -p "$1" "$2" 2>/dev/null; then
                chmod 600 "$2" 2>/dev/null
            fi ;;
    esac
}
CF="$BASE/config/plugins/$PFOLDER/govee.json"
gv_sichern "$CF" "$BASE/config/plugins/$PFOLDER.backup.json" config "Konfiguration"
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
NETZ_BASE="$BASE"
NETZ_PDIR="$PFOLDER"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"


# NICHT MITGELIEFERTE Dateien - und gerade deshalb die wichtigen.
# Das Archiv liefert sie nie, also standen sie bis jetzt auf keiner Liste;
# geloescht werden sie vom Installer trotzdem, samt Token und Zugangsdaten.
gv_sichern "$NETZ_CFG/geheim.json" "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.geheim.json" geheim "Cloud-Schluessel"

exit 0
