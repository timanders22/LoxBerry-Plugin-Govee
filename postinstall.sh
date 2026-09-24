#!/bin/bash
# Govee - postinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# postinstall laeuft IMMER, auch beim Upgrade - in plugininstall.pl gibt es
# dort kein if($isupgrade). Alles hier muss deshalb mehrfach ausfuehrbar sein,
# ohne Schaden anzurichten.
#
# Das Plugin ist reines PHP: keine virtuelle Python-Umgebung, kein Umweg um
# PEP 668 herum, keine Paketinstallation an dieser Stelle. Das einzige Paket
# (php-curl, nur fuer den Cloud-Weg) steht in dpkg/apt - dort installiert es
# LoxBerry mit den noetigen Rechten. Ein "apt-get install" hier koennte gar
# nicht gelingen: postinstall.sh laeuft als Benutzer loxberry, apt braucht root.

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-govee}"
BASE="${ARGV5:-$LBHOMEDIR}"

# ---------- Die Wurzel: GELESEN, nicht geraten ----------
#
# $5 (vom Installer) oder $LBHOMEDIR, wenn dort config/plugins und
# data/plugins liegen - sonst vom eigenen Ablageort AUFWAERTS SUCHEN, bis ein
# Verzeichnis config/plugins, data/plugins UND config/system/general.json
# traegt (Regeln/06). LoxBerry::System taugt hier nicht, weil es den
# Pluginordner aus dem Aufrufort ableitet und aus postinstall.sh heraus
# ueberall Leerstring liefert. Bis 0.9.20 stand als Rueckfall "$SELF/../.."
# ohne general.json: in einem fremden Baum legte dieses Skript dort Daten-,
# Protokoll- und Konfigurationsordner samt govee.json an und meldete
# "Installation abgeschlossen" (in WSL gemessen, Pruefung-Govee-0.9.21,
# Fall W1). Bauart VolkswagenID 0.9.24.
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
    echo "<WARNING> config/system/general.json. Es wurde nichts angelegt und"
    echo "<WARNING> nichts zurueckgespielt."
    exit 1
fi

PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PLOG="$BASE/log/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"

mkdir -p "$PDATA/befehle" "$PDATA/antworten" "$PLOG" "$PCONFIG" || {
    echo "<FAIL> Ordner konnten nicht angelegt werden."
    exit 1
}
chmod 755 "$PDATA" "$PLOG" 2>/dev/null
# Im Konfigurationsordner liegt die Datei mit dem Cloud-Schluessel.
chmod 700 "$PCONFIG" 2>/dev/null

[ -f "$PCONFIG/govee.json" ] || echo '{}' > "$PCONFIG/govee.json"
chmod 600 "$PCONFIG/govee.json" 2>/dev/null
[ -f "$PCONFIG/geheim.json" ] && chmod 600 "$PCONFIG/geheim.json" 2>/dev/null

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

# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zweitschrift zurueckspielen (uebersteht Update UND Neuinstallation) - nach
# INHALT, nie nach Groesse. Zurueckgespielt wird nur, wenn die Datei KEINEN
# und die Zweitschrift EINEN Inhalt traegt; eine Datei mit Inhalt wird nie
# ueberschrieben. Eine verdraengte Datei, die nicht leer und nicht "{}" ist,
# bleibt als <datei>.kaputt.<zeit> (0600) daneben liegen.
#
# Bis 0.9.20 stand hier zweimal ein Zurueckspielen nach Groesse bzw. nach der
# Pruefsumme der mitgelieferten Vorgabe, ohne die Zweitschrift anzusehen: eine
# abgeschnittene govee.json blieb stehen, eine "{}"-Zweitschrift wurde als
# "wiederhergestellt" gemeldet, ein leerer Cloud-Schluessel verhinderte das
# Zurueckholen des guten, und die alte Zweitschrift aus 0.9.8 wurde geloescht,
# auch wenn sie nicht uebernommen war (in WSL gemessen, Pruefung-Govee-0.9.21,
# Faelle Z3 bis Z6; Klasse C, Bestand-2026-09-18/klasse-C/Ergebnis.md 3c-3e).
NETZ_BASE="$BASE"
NETZ_PDIR="$PFOLDER"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$PCONFIG/govee.json"
gv_zurueck() {   # $1 Datei, $2 Zweitschrift, $3 Art, $4 Name fuer die Meldung
    [ -f "$2" ] || return 0
    gv_inhalt "$1" "$3"; gv_z=$?
    [ "$gv_z" = 0 ] && return 0
    gv_inhalt "$2" "$3"; gv_s=$?
    if [ "$gv_z" = 2 ] || [ "$gv_s" = 2 ]; then
        echo "<WARNING> Der Inhalt von $4 bzw. der Zweitschrift liess sich nicht pruefen"
        echo "<WARNING> (php fehlt) - nichts zurueckgespielt. Die Zweitschrift liegt unter $2."
        return 0
    fi
    if [ "$gv_s" = 1 ]; then
        echo "<INFO> Die Zweitschrift $(basename "$2") traegt keine Einstellungen - $4 nicht zurueckgespielt."
        return 0
    fi
    if [ -s "$1" ] && [ "$(cat "$1" 2>/dev/null)" != "{}" ]; then
        gv_weg="$1.kaputt.$(date +%Y%m%d%H%M%S)"
        if cp -p "$1" "$gv_weg" 2>/dev/null; then
            chmod 600 "$gv_weg" 2>/dev/null
            echo "<INFO> Die bisherige $4 trug keine Einstellungen; sie liegt als $(basename "$gv_weg") daneben."
        fi
    fi
    if cp -p "$2" "$1" 2>/dev/null; then
        chmod 600 "$1" 2>/dev/null
        echo "<OK> $4 aus der Zweitschrift wiederhergestellt."
    else
        echo "<WARNING> $4 liess sich nicht zurueckspielen. Die Zweitschrift"
        echo "<WARNING> liegt unter $2 und kann von Hand kopiert werden."
    fi
}
# Der alte Name aus 0.9.8 wird uebernommen, falls er auf dieser Anlage noch
# liegt - eine alte Zweitschrift soll nicht verwaisen, und zwei Namen fuer
# dieselbe Sache soll es hinterher nicht mehr geben. Geloescht wird sie erst,
# wenn sie uebernommen ist oder nichts Neues traegt.
ALT="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.govee.json"
if [ -f "$ALT" ]; then
    gv_inhalt "$BK" config; gv_n=$?
    gv_inhalt "$ALT" config; gv_a=$?
    if [ "$gv_n" = 2 ] || [ "$gv_a" = 2 ]; then
        echo "<WARNING> Die Zweitschrift aus 0.9.8 liess sich nicht pruefen - sie bleibt liegen: $ALT"
    elif [ "$gv_n" = 1 ] && [ "$gv_a" = 0 ]; then
        if cp -p "$ALT" "$BK" 2>/dev/null; then
            chmod 0600 "$BK" 2>/dev/null
            echo "<OK> Zweitschrift aus 0.9.8 uebernommen."
            rm -f "$ALT"
        else
            echo "<WARNING> Die Zweitschrift aus 0.9.8 liess sich nicht uebernehmen - sie bleibt liegen: $ALT"
        fi
    else
        rm -f "$ALT"
    fi
fi
gv_zurueck "$CF" "$BK" config "govee.json"
gv_zurueck "$NETZ_CFG/geheim.json" "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.geheim.json" geheim "geheim.json"

# ---------- PHP pruefen ----------
if ! command -v php >/dev/null 2>&1; then
    echo "<FAIL> Es wurde kein PHP gefunden. LoxBerry bringt PHP normalerweise mit -"
    echo "<FAIL> ohne PHP laeuft weder die Oberflaeche noch der Dienst."
    exit 1
fi
echo "<INFO> PHP: $(php -v 2>/dev/null | head -1)"

# ---------- curl pruefen ----------
# Nur fuer den Cloud-Weg noetig. Fehlt es, wird das gemeldet und die
# Installation laeuft weiter - ein Plugin, das wegen eines nur teilweise
# gebrauchten Werkzeugs abbricht, waere unverhaeltnismaessig.
if php -r 'exit(function_exists("curl_init") ? 0 : 1);' >/dev/null 2>&1; then
    echo "<OK> Die PHP-Erweiterung curl ist geladen."
else
    echo "<INFO> Die PHP-Erweiterung curl fehlt - obwohl php-curl in dpkg/apt steht."
    echo "<INFO> Betroffen ist NUR der Cloud-Weg (Geraete ohne LAN Control)."
    echo "<INFO> Der Regelweg ueber das Heimnetz laeuft auch ohne curl."
    echo "<INFO> Nachholen mit: sudo apt install php-curl"
fi

# ---------- Selbsttest des Protokoll-Nachbaus ----------
# Ohne Netz und ohne Geraet: rechnet die ptReal-Befehle durch und vergleicht
# sie mit den aufgezeichneten Sollwerten. Schlaegt das fehl, stimmt an dieser
# Installation etwas nicht - dann lieber jetzt melden als spaeter raten.
if [ -f "$PBIN/govee_dienst.php" ]; then
    if AUS=$(php "$PBIN/govee_dienst.php" --selbsttest 2>&1); then
        echo "<OK> Selbsttest des Protokoll-Nachbaus: $(echo "$AUS" | head -1)"
    else
        echo "<INFO> Der Selbsttest des Protokoll-Nachbaus ist nicht sauber durchgelaufen:"
        echo "$AUS" | head -20 | sed 's/^/<INFO> /'
    fi
fi

# Kein chmod fuer Dateien unterhalb von bin/: der Installer setzt dort
# ohnehin rekursiv 755 (setrights("755","1",...) in plugininstall.pl). Ein
# zusaetzliches chmod schadet nicht, verdeckt aber, wenn anderswo eines fehlt.
chown -R loxberry:loxberry "$PBIN" "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null

echo "<OK> Installation abgeschlossen."
# Die Erstanleitung nur, wenn noch kein Geraet eingerichtet ist - entschieden
# am INHALT der Konfiguration nach dem Zurueckspielen, nicht an einer Marke.
# Bis 0.9.20 stand sie unbedingt hier und riet nach jedem Upgrade zur
# Einrichtung, zwei Zeilen vor "Dienst nach dem Upgrade wieder gestartet"
# aus postupgrade.sh (Regeln/06, "Nach einer Aktualisierung darf der
# Schlusstext nicht zur Erstinstallation raten"; in WSL gemessen,
# Pruefung-Govee-0.9.21, Fall E2). Ein Token allein ist keine Einrichtung: es
# entsteht beim ersten Oeffnen der Oberflaeche (Fall E3).
if gv_inhalt "$CF" geraete; then
    echo "<OK> Die Einstellungen mit den eingerichteten Geraeten sind uebernommen - es ist nichts weiter einzurichten."
else
    echo "<INFO> Naechste Schritte in der Plugin-Oberflaeche:"
    echo "<INFO>  1. Reiter Test, Knopf 'Geraete im Netz suchen' - dazu muss in der"
    echo "<INFO>     Govee-App je Leuchte 'LAN Control' eingeschaltet sein."
    echo "<INFO>  2. Reiter Einstellungen: Namen und Pixelzahl ergaenzen, speichern."
    echo "<INFO>  3. Dienst starten."
fi

exit 0
