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
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    # Ableitung aus dem eigenen Ablageort - LoxBerry::System taugt hier nicht,
    # weil es den Pluginordner aus dem Aufrufort ableitet und aus
    # postinstall.sh heraus ueberall Leerstring liefert.
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
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

# Sicherung zurueckspielen (uebersteht Update UND Neuinstallation)
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$PCONFIG/govee.json"
if [ -f "$BK" ]; then
    INHALT=$(cat "$CF" 2>/dev/null)
    if [ ! -s "$CF" ] || [ "$INHALT" = "{}" ]; then
        cp -p "$BK" "$CF" && echo "<OK> Konfiguration aus Sicherung wiederhergestellt."
    fi
fi

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
echo "<INFO> Naechste Schritte in der Plugin-Oberflaeche:"
echo "<INFO>  1. Reiter Test, Knopf 'Geraete im Netz suchen' - dazu muss in der"
echo "<INFO>     Govee-App je Leuchte 'LAN Control' eingeschaltet sein."
echo "<INFO>  2. Reiter Einstellungen: Namen und Pixelzahl ergaenzen, speichern."
echo "<INFO>  3. Dienst starten."

# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zurueckspielen aus der Zweitschrift - aber NUR, wenn die Datei des Nutzers
# wirklich verloren ist. Erkannt wird das an dreierlei: sie fehlt, sie ist
# leer, oder sie ist zeichengenau die mitgelieferte Vorgabe (Pruefsumme
# unten). Der letzte Fall ist der eigentliche: genau so sieht die Datei nach
# dem Kopierschritt des Installers aus.
#
# Eine gueltige Konfiguration wird NIE ueberschrieben. Eine Sicherung, die
# echte Einstellungen ersetzt, waere schlimmer als gar keine.
NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-govee}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
netz_zurueck_json() {
    soll=$1
    ziel="$NETZ_CFG/govee.json"
    zweit="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.json"
    datei="govee.json"
    [ -f "$zweit" ] || return 0
    verloren=0
    if [ ! -f "$ziel" ] || [ ! -s "$ziel" ]; then
        verloren=1
    else
        ist=$(sha256sum "$ziel" 2>/dev/null | cut -d" " -f1)
        [ -n "$ist" ] && [ "$ist" = "$soll" ] && verloren=1
    fi
    if [ "$verloren" = "1" ]; then
        if cp -p "$zweit" "$ziel" 2>/dev/null; then
            echo "<OK> $datei aus der Zweitschrift wiederhergestellt."
        else
            echo "<WARNING> $datei liess sich nicht zurueckspielen. Die Sicherung"
            echo "<WARNING> liegt unter $zweit und kann von Hand kopiert werden."
        fi
    fi
}
# Der alte Name aus 0.9.8 wird uebernommen, falls er auf dieser Anlage noch
# liegt - eine alte Zweitschrift soll nicht verwaisen, und zwei Namen fuer
# dieselbe Sache soll es hinterher nicht mehr geben.
ALT="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.govee.json"
NEU="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.json"
if [ -s "$ALT" ]; then
    if [ ! -s "$NEU" ]; then
        cp -p "$ALT" "$NEU" && chmod 0600 "$NEU" 2>/dev/null \
            && echo "<OK> Zweitschrift aus 0.9.8 uebernommen."
    fi
    rm -f "$ALT"
fi

netz_zurueck_json "ca3d163bab055381827226140568f3bef7eaac187cebd76878e0b63e9e442356"


# Zurueckspielen fuer Dateien OHNE mitgelieferte Vorgabe: es gibt nichts,
# womit man vergleichen koennte, also ist das Kriterium "fehlt oder leer".
# Eine vorhandene Datei wird nie ueberschrieben.
netz_ohne_vorgabe() {
    ziel="$NETZ_CFG/$1"
    zweit="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$1"
    [ -f "$zweit" ] || return 0
    if [ ! -s "$ziel" ]; then
        if cp -p "$zweit" "$ziel" 2>/dev/null; then
            chmod 0600 "$ziel" 2>/dev/null
            echo "<OK> $1 aus der Zweitschrift wiederhergestellt."
        else
            echo "<WARNING> $1 liess sich nicht zurueckspielen ($zweit)."
        fi
    fi
}
netz_ohne_vorgabe "geheim.json"

exit 0
