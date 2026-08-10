<?php
/**
 * Govee - gemeinsame Bibliothek
 *
 * Liegt unter webfrontend/html/, weil der Miniserver-Endpunkt sie ebenso
 * braucht wie die Oberflaeche und der Dienst. So gibt es EINE Datei statt
 * dreier Kopien, die auseinanderlaufen.
 *
 * ------------------------------------------------------------------
 * Die drei Wege zu einer Govee-Leuchte
 * ------------------------------------------------------------------
 *
 *   LAN     Die eigentliche Betriebsart. In der Govee-App muss unter
 *           Geraeteeinstellungen "LAN Control" eingeschaltet sein. Danach
 *           spricht das Geraet UDP:
 *             4001  Multicast 239.255.255.250 - Gearaetesuche (scan)
 *             4002  der Port, auf dem das Geraet ANTWORTET (beim Anfragenden)
 *             4003  der Port, auf dem das Geraet Befehle ENTGEGENNIMMT
 *           Befehlsvorrat der dokumentierten Schnittstelle: turn, brightness,
 *           colorwc, devStatus. Quelle: die Herstelleranleitung
 *           https://app-h5.govee.com/user-manual/wlan-guide
 *
 *   ptReal  Ein vom Hersteller nicht dokumentierter Befehl derselben
 *           LAN-Schnittstelle. Er nimmt die BLE-Befehle entgegen, die die
 *           Govee-App sonst per Bluetooth schickt - base64-kodiert:
 *             {"msg":{"cmd":"ptReal","data":{"command":[CMD, CMD, ...]}}}
 *           Damit werden Szenen, Musikbetrieb und die Segment-/Pixelsteuerung
 *           erreichbar, die die dokumentierte Schnittstelle nicht kennt.
 *           Quelle: loxforum.com, Beitrag 446672 von MarkusCosi
 *           (Govee: BLE > local API - Segmentsteuerung / Szenen).
 *
 *   Cloud   Die offizielle Entwicklerschnittstelle mit API-Schluessel:
 *             https://openapi.api.govee.com/router/api/v1/...
 *           Nur fuer Geraete gedacht, die kein LAN Control koennen. Sie
 *           braucht Internet, ein Govee-Konto und unterliegt einer
 *           Anfragegrenze. Quelle: developer.govee.com/reference
 *
 * ------------------------------------------------------------------
 * Warum die ptReal-Bauteile hier nachgebaut und nicht abgeschrieben sind
 * ------------------------------------------------------------------
 *
 * Jeder ptReal-Befehl ist ein 20 Byte langer Block: 19 Nutzbytes, dahinter
 * eine XOR-Pruefsumme ueber genau diese 19 Bytes, das Ganze base64-kodiert.
 * Laengere Befehle werden auf mehrere solcher Bloecke verteilt (a3-Pakete).
 *
 * Nachgebaute Protokolle werden gegen das Original gemessen, nicht gegen die
 * eigene Vorstellung davon (Hausregel). Die Funktion gv_selbsttest_faelle()
 * enthaelt deshalb 34 Faelle, deren Sollwert im Forumsbeitrag steht - vom
 * einfachen Ein/Aus ueber die Szene "Aurora" bis zu allen fuenfzehn Stufen
 * des Prozentbalkens. Der Knopf "Selbsttest" im Reiter Test rechnet sie durch
 * und vergleicht Zeichen fuer Zeichen. Am 09.08.2026 stimmten alle 34.
 *
 * Praefix 'gv_', weil LBWeb::lbheader() SDK-Globale setzt.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

if (!function_exists('gv_e')) {
    function gv_e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

/* Die Ports der Govee-LAN-Schnittstelle. Sie sind fest, nicht einstellbar. */
define('GV_PORT_SUCHE',   4001);
define('GV_PORT_ANTWORT', 4002);
define('GV_PORT_BEFEHL',  4003);
define('GV_MULTICAST',    '239.255.255.250');

/* Adresse der offiziellen Entwicklerschnittstelle. */
define('GV_CLOUD', 'https://openapi.api.govee.com');


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

function gv_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) {
        foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
            if (is_dir($k)) {
                $home = $k;
                break;
            }
        }
    }
    /* Der Pluginordner ergibt sich aus dem Ablageort dieser Datei. Der
     * MD5-Schluessel aus der plugindatabase.json wird bewusst NICHT benutzt -
     * er wird aus Autorenname, E-Mail und Plugin-Name gebildet und aendert
     * sich bei jedem Fork. */
    $dir = basename(dirname(__FILE__));
    /* Frueher wurde hier auf den festen Namen "govee" zurueckgefallen, sobald
     * config/plugins/<ordner> noch fehlte - etwa im Augenblick der
     * Installation. Haengt LoxBerry bei einer Zweitinstallation einen Zaehler
     * an (govee_01, weil der Name schon belegt war), zeigten deren Pfade damit
     * auf die ERSTE Installation: gemeinsame Konfiguration - und darin steht
     * der API-Schluessel -, gemeinsame Warteschlange, gemeinsames Protokoll.
     *
     * LBPPLUGINDIR ist die Auskunft von LoxBerry selbst und bleibt deshalb.
     * Der feste Name greift nur noch dort, wo der ermittelte nachweislich kein
     * Plugin-Ordner sein kann: aus dem ausgepackten Archiv heraus heisst er
     * "html". */
    $lbp = getenv('LBPPLUGINDIR');
    if ($lbp) {
        $dir = $lbp;
    } elseif ($dir === '' || $dir === '.' || $dir === '/' || $dir === 'html') {
        $dir = 'govee';
    }
    if ($home) {
        $p = array(
            'home'      => $home,
            'plugin'    => $dir,
            'configdir' => $home . '/config/plugins/' . $dir,
            'config'    => $home . '/config/plugins/' . $dir . '/govee.json',
            'geheim'    => $home . '/config/plugins/' . $dir . '/geheim.json',
            'sicherung' => $home . '/config/plugins/' . $dir . '.backup.json',
            'datadir'   => $home . '/data/plugins/' . $dir,
            'bindir'    => $home . '/bin/plugins/' . $dir,
            'logdir'    => $home . '/log/plugins/' . $dir,
            'log'       => $home . '/log/plugins/' . $dir . '/govee.log',
        );
    } else {
        $basis = dirname(dirname(__DIR__));
        $p = array(
            'home'      => '',
            'plugin'    => $dir,
            'configdir' => $basis . '/config',
            'config'    => $basis . '/config/govee.json',
            'geheim'    => $basis . '/config/geheim.json',
            'sicherung' => $basis . '/config/govee.backup.json',
            'datadir'   => $basis . '/data',
            'bindir'    => $basis . '/bin',
            'logdir'    => $basis . '/log',
            'log'       => $basis . '/log/govee.log',
        );
    }
    return $p;
}

/* ==================================================================
 * Konfiguration
 * ================================================================== */

function gv_vorgaben()
{
    return array(
        'geraete'       => array(),
        'intervall'     => 30,     // Sekunden zwischen zwei Statusabfragen
        'suchtakt'      => 0,      // Minuten; 0 = nur auf Knopfdruck suchen
        'mqtt_ein'      => 1,
        'mqtt_topic'    => 'govee',
        'steuerung_ein' => 0,
        'cloud_ein'     => 0,
        'cloud_takt'    => 5,      // Minuten; die Cloud hat eine Anfragegrenze
        'aktionstoken'  => '',
        'wartezeit'     => 6,
        'pt_frei'       => 0,      // rohe ptReal-Befehle ueber den Endpunkt?
    );
}

function gv_json_lesen($pfad)
{
    if (!is_file($pfad)) {
        return array();
    }
    $d = json_decode((string) @file_get_contents($pfad), true);
    return is_array($d) ? $d : array();
}

/**
 * Erst in eine Nebendatei, dann umbenennen - so liest niemand eine halb
 * geschriebene Datei. Die Rechte gehoeren an das ANLEGEN, nicht hinterher:
 * "schreiben, dann chmod" laesst die Datei fuer die Dauer des Schreibens mit
 * den Vorgaben der umask stehen. Die Nebendatei traegt die PID im Namen,
 * sonst zerlegen zwei gleichzeitige Schreiber einander die Datei.
 */
function gv_json_schreiben($pfad, $daten, $rechte = null)
{
    $ordner = dirname($pfad);
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return false;
    }
    $tmp = $pfad . '.tmp.' . getmypid();
    $json = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $fh = @fopen($tmp, 'c');
    if ($fh === false) {
        return false;
    }
    if ($rechte !== null) {
        @chmod($tmp, $rechte);
    }
    $ok = ftruncate($fh, 0) && fwrite($fh, $json) !== false;
    fflush($fh);
    fclose($fh);
    if (!$ok) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $pfad)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function gv_config()
{
    $p = gv_paths();
    // Selbstheilung: fehlende oder leere Konfiguration aus der Sicherung holen.
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    if (($roh === '' || $roh === '{}') && is_file($p['sicherung'])) {
        @mkdir($p['configdir'], 0775, true);
        @copy($p['sicherung'], $p['config']);
    }
    return array_merge(gv_vorgaben(), gv_json_lesen($p['config']));
}

function gv_config_speichern($cfg)
{
    $p = gv_paths();
    if (!gv_json_schreiben($p['config'], $cfg, 0600)) {
        return false;
    }
    @copy($p['config'], $p['sicherung']);
    @chmod($p['sicherung'], 0600);
    return true;
}

/**
 * Der Cloud-Schluessel steht in einer EIGENEN Datei mit Rechten 0600 - nicht
 * in der Konfiguration, die die Oberflaeche anzeigt (Hausregel).
 */
function gv_geheim()
{
    return array_merge(array('cloud_key' => ''), gv_json_lesen(gv_paths()['geheim']));
}

function gv_geheim_speichern($g)
{
    return gv_json_schreiben(gv_paths()['geheim'], $g, 0600);
}

/**
 * Geraeteliste, 1-basiert, nur vollstaendige Eintraege.
 *
 *   art=lan    braucht ip
 *   art=cloud  braucht sku und device (die Geraetekennung aus der Cloud)
 *
 * pixel  = Anzahl der einzeln ansprechbaren Pixel im Graffiti-Betrieb.
 *          Bei der H6076-Stehlampe sind das 14 Gruppen zu je 6 LEDs.
 *          0 heisst: das Geraet kann keine Segmente, die Balkenanzeige und
 *          die Segmentbefehle werden dann abgewiesen statt ins Leere gesendet.
 */
function gv_geraete()
{
    $cfg = gv_config();
    $out = array();
    $n = 0;
    foreach ((array) $cfg['geraete'] as $g) {
        if (!is_array($g)) {
            continue;
        }
        $art = (isset($g['art']) && $g['art'] === 'cloud') ? 'cloud' : 'lan';
        $ip = trim((string) (isset($g['ip']) ? $g['ip'] : ''));
        $sku = trim((string) (isset($g['sku']) ? $g['sku'] : ''));
        $device = trim((string) (isset($g['device']) ? $g['device'] : ''));
        if ($art === 'lan' && $ip === '') {
            continue;
        }
        if ($art === 'cloud' && ($sku === '' || $device === '')) {
            continue;
        }
        $n++;
        $out[$n] = array(
            'nr'     => $n,
            'name'   => trim((string) (isset($g['name']) ? $g['name'] : '')) !== ''
                        ? trim((string) $g['name']) : ('Govee ' . $n),
            'art'    => $art,
            'ip'     => $ip,
            'sku'    => $sku,
            'device' => $device,
            'pixel'  => isset($g['pixel']) ? max(0, min(200, (int) $g['pixel'])) : 0,
            'kmin'   => isset($g['kmin']) && $g['kmin'] !== '' ? max(1000, min(10000, (int) $g['kmin'])) : 2700,
            'kmax'   => isset($g['kmax']) && $g['kmax'] !== '' ? max(1000, min(10000, (int) $g['kmax'])) : 6500,
        );
    }
    return $out;
}

function gv_geraet($nr)
{
    $g = gv_geraete();
    $nr = max(1, (int) $nr);
    return isset($g[$nr]) ? $g[$nr] : null;
}

/* Zufallstoken fuer den unangemeldeten Endpunkt. */
function gv_token_erzeugen($laenge = 24)
{
    $zeichen = 'abcdefghijkmnpqrstuvwxyz23456789';
    $t = '';
    for ($i = 0; $i < $laenge; $i++) {
        $t .= $zeichen[random_int(0, strlen($zeichen) - 1)];
    }
    return $t;
}

function gv_token()
{
    $cfg = gv_config();
    if (trim((string) $cfg['aktionstoken']) === '') {
        $cfg['aktionstoken'] = gv_token_erzeugen();
        gv_config_speichern($cfg);
    }
    return (string) $cfg['aktionstoken'];
}

/* ==================================================================
 * Zwischenspeicher
 * ================================================================== */

function gv_loxone()
{
    return gv_json_lesen(gv_paths()['datadir'] . '/loxone.json');
}

function gv_zustand()
{
    return gv_json_lesen(gv_paths()['datadir'] . '/zustand.json');
}

function gv_gefunden()
{
    return gv_json_lesen(gv_paths()['datadir'] . '/gefunden.json');
}

function gv_werte()
{
    $l = gv_loxone();
    return isset($l['geraete']) && is_array($l['geraete']) ? $l['geraete'] : array();
}

/** Alter des Abbilds in Sekunden, oder -1 wenn es keines gibt. */
function gv_alter()
{
    $l = gv_loxone();
    return isset($l['ts']) ? max(0, time() - (int) $l['ts']) : -1;
}

/* ==================================================================
 * Protokollierung
 * ================================================================== */

function gv_log($text)
{
    $p = gv_paths();
    if (!is_dir($p['logdir'])) {
        @mkdir($p['logdir'], 0775, true);
    }
    /* log/plugins liegt auf einer Ramdisk. Eine unbegrenzt wachsende Logdatei
     * frisst deshalb Arbeitsspeicher, nicht Plattenplatz - Rotation ist hier
     * kein Feinschliff. */
    if (is_file($p['log']) && filesize($p['log']) > 512000) {
        $rest = array_slice(file($p['log'], FILE_IGNORE_NEW_LINES) ?: array(), -400);
        @file_put_contents($p['log'], implode("\n", $rest) . "\n");
    }
    @file_put_contents($p['log'], '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n", FILE_APPEND);
}

/** Dieselbe Meldung hoechstens einmal je Zeitfenster. */
function gv_log_gebremst($schluessel, $text, $sekunden = 3600)
{
    $f = gv_paths()['datadir'] . '/.meld_' . preg_replace('/[^a-z0-9_]/i', '', $schluessel);
    $letzte = is_file($f) ? (int) @file_get_contents($f) : 0;
    if (time() - $letzte >= $sekunden) {
        @file_put_contents($f, (string) time());
        gv_log($text);
    }
}

/**
 * Die letzten $anzahl Zeilen einer Datei, neueste zuerst.
 *
 * Rueckwaerts mit fseek, nicht file() und nicht exec("tail"). Gemessen an
 * 12.000 Zeilen (610 kB), je 20 Durchlaeufe: file() 0,37 ms und 2048 kB,
 * tail 2,17 ms, fseek 0,05 ms und 0 kB. Ein Prozessstart kostet mehr, als das
 * Einlesen je gespart hat.
 */
function gv_log_ende($datei, $anzahl = 400, $block = 8192)
{
    $fp = @fopen($datei, 'rb');
    if ($fp === false) {
        return array();
    }
    fseek($fp, 0, SEEK_END);
    $pos = ftell($fp);
    $puffer = '';
    $zeilen = array();
    while ($pos > 0 && count($zeilen) <= $anzahl) {
        $lese = (int) min($block, $pos);
        $pos -= $lese;
        fseek($fp, $pos, SEEK_SET);
        $puffer = fread($fp, $lese) . $puffer;
        $zeilen = explode("\n", $puffer);
    }
    fclose($fp);
    $zeilen = array_values(array_filter(array_map('rtrim', $zeilen), 'strlen'));
    return array_slice(array_reverse($zeilen), 0, $anzahl);
}

/* ==================================================================
 * Dienst
 * ================================================================== */

function gv_dienst_pid()
{
    $f = gv_paths()['datadir'] . '/dienst.pid';
    if (!is_file($f)) {
        return 0;
    }
    $pid = (int) trim((string) @file_get_contents($f));
    if ($pid <= 0 || !is_dir('/proc/' . $pid)) {
        return 0;
    }
    /* Argumentweise pruefen, nicht die ganze Befehlszeile durchsuchen:
     * /proc/<pid>/cmdline trennt die Argumente mit Nullbytes, ein grep
     * darueber traefe auch einen Editor mit geoeffneter Datei. */
    $roh = (string) @file_get_contents('/proc/' . $pid . '/cmdline');
    $args = explode("\0", $roh);
    if (!isset($args[1]) || basename($args[1]) !== 'govee_dienst.php') {
        return 0;
    }
    if (!preg_match('#(^|/)php[0-9.]*$#', isset($args[0]) ? $args[0] : '')) {
        return 0;
    }
    return $pid;
}

function gv_dienst_soll()
{
    return is_file(gv_paths()['datadir'] . '/soll_laufen') ? 1 : 0;
}

/** $befehl ist 'start', 'stop' oder 'restart'. Rueckgabe: array(ok, Ausgabe) */
function gv_dienst($befehl)
{
    if (!in_array($befehl, array('start', 'stop', 'restart'), true)) {
        return array(0, 'Unbekannter Befehl.');
    }
    $skript = gv_paths()['bindir'] . '/dienst.sh';
    if (!is_file($skript)) {
        return array(0, 'dienst.sh nicht gefunden: ' . $skript);
    }
    $ausgabe = array();
    $code = 0;
    @exec(escapeshellcmd($skript) . ' ' . escapeshellarg($befehl) . ' 2>&1', $ausgabe, $code);
    return array($code === 0 ? 1 : 0, implode("\n", $ausgabe));
}

/* ==================================================================
 * Befehlswarteschlange
 *
 * Sowohl der Miniserver-Endpunkt als auch der Reiter Test setzen Befehle ueber
 * diese eine Funktion ab. Zwei Kopien derselben Logik laufen zwangslaeufig
 * auseinander.
 *
 * Rueckgabe: array(ok, Meldung). ok = 1 erledigt, 0 abgelehnt,
 * 2 eingereiht, aber ohne Antwort in der Wartezeit - Ergebnis unbekannt.
 * Es wird nie ein Erfolg gemeldet, den niemand geprueft hat.
 * ================================================================== */

/* Obergrenze fuer eine Wartezeit, die aus einer Web-Anfrage kommt: der
 * Webserver bricht typischerweise nach 15 bis 30 Sekunden mit 504 ab. */
define('GV_WARTEN_WEB', 10);

function gv_befehl_absetzen($befehl, $wartezeit = null)
{
    $p = gv_paths();
    $cfg = gv_config();
    if ($wartezeit === null) {
        $wartezeit = (int) $cfg['wartezeit'];
    }
    $wartezeit = max(0, min(GV_WARTEN_WEB, (int) $wartezeit));

    $ordner = $p['datadir'] . '/befehle';
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return array(0, 'Der Ordner fuer die Warteschlange liess sich nicht anlegen: ' . $ordner);
    }
    $kennung = bin2hex(random_bytes(8));
    $datei = $ordner . '/' . $kennung . '.json';
    $tmp = $datei . '.tmp';
    /* json_encode gibt bei ungueltigem UTF-8 false zurueck. file_put_contents
     * macht daraus eine leere Zeichenkette, schreibt null Byte und meldet das
     * als Erfolg - der Rueckgabewert ist 0, nicht false, die Pruefung auf
     * "=== false" greift also nicht, und rename schiebt die leere Datei in die
     * Warteschlange. Der Dienst faende dort einen Befehl, den er nicht deuten
     * kann. Deshalb zuerst kodieren und den Rueckgabewert ansehen - so, wie
     * gv_json_schreiben() es schon immer tut. */
    $gv_js = json_encode($befehl);
    if ($gv_js === false) {
        return array(0, 'Der Befehl liess sich nicht als JSON darstellen (ungueltiges UTF-8).');
    }
    if (@file_put_contents($tmp, $gv_js) !== strlen($gv_js) || !@rename($tmp, $datei)) {
        @unlink($tmp);
        return array(0, 'Der Befehl liess sich nicht ablegen: ' . $datei);
    }
    $antwort = $p['datadir'] . '/antworten/' . $kennung . '.json';
    for ($i = 0; $i < $wartezeit * 10; $i++) {
        if (is_file($antwort)) {
            $a = gv_json_lesen($antwort);
            @unlink($antwort);   // gelesen ist erledigt
            return array((int) (isset($a['ok']) ? $a['ok'] : 0),
                         (string) (isset($a['meldung']) ? $a['meldung'] : ''));
        }
        usleep(100000);
    }
    return array(2, 'Eingereiht, aber der Dienst hat innerhalb von ' . $wartezeit . ' s nicht geantwortet.');
}

/* ==================================================================
 * LAN-Schnittstelle
 *
 * Bewusst mit Datenstroemen (stream_socket_*) statt mit socket_create():
 * die Erweiterung 'sockets' ist auf einem LoxBerry nicht garantiert geladen,
 * Datenstroeme sind Kernbestandteil von PHP. Dasselbe Argument wie bei
 * mb_strlen und ctype_digit.
 * ================================================================== */

/** Rueckgabe: array(ok, Meldung). Ein Fehler ist ein Fehler, kein leerer Wert. */
function gv_udp_senden($ip, $port, $text)
{
    $fehlnr = 0;
    $fehltext = '';
    $s = @stream_socket_client('udp://' . $ip . ':' . (int) $port, $fehlnr, $fehltext, 2);
    if ($s === false) {
        return array(0, gv_netzfehler($fehlnr, $fehltext, $ip));
    }
    $n = @fwrite($s, $text);
    fclose($s);
    if ($n === false || $n < strlen($text)) {
        return array(0, 'Es liessen sich nicht alle Bytes an ' . $ip . ' schreiben.');
    }
    return array(1, '');
}

/**
 * Betriebssystemfehler uebersetzen. Der nackte Errno-Text hilft niemandem:
 * ECONNREFUSED (erreichbar, aber niemand hoert) bedeutet etwas voellig
 * anderes als EHOSTUNREACH (kein Weg dorthin) oder eine Zeitueberschreitung.
 */
function gv_netzfehler($nr, $text, $ip)
{
    $nr = (int) $nr;
    if ($nr === 111) {
        return $ip . ' ist erreichbar, weist die Verbindung aber ab (ECONNREFUSED). '
             . 'Meist ist LAN Control in der Govee-App ausgeschaltet.';
    }
    if ($nr === 113) {
        return 'Kein Weg zu ' . $ip . ' (EHOSTUNREACH). Anderes Netz oder Geraet aus?';
    }
    if ($nr === 101) {
        return 'Das Netz zu ' . $ip . ' ist nicht erreichbar (ENETUNREACH).';
    }
    if ($nr === 110) {
        return 'Zeitueberschreitung zu ' . $ip . ' (ETIMEDOUT). Es antwortet nichts.';
    }
    return 'Netzfehler zu ' . $ip . ': ' . trim((string) $text) . ' (Nummer ' . $nr . ')';
}

/**
 * Auf dem Antwortport horchen.
 *
 * Rueckgabe: Liste von array('von' => IP, 'json' => Feld).
 * $sekunden ist die Gesamtdauer, $max die harte Obergrenze an Nachrichten -
 * jede Leseschleife bekommt eine Rundenobergrenze, sonst haelt eine
 * schwatzhafte Gegenstelle den Dienst fest.
 */
function gv_udp_horchen($handle, $sekunden = 2, $max = 60)
{
    $out = array();
    $ende = microtime(true) + max(0.2, (float) $sekunden);
    $runden = 0;
    while (microtime(true) < $ende && count($out) < $max && $runden < 5000) {
        $runden++;
        $rest = $ende - microtime(true);
        if ($rest <= 0) {
            break;
        }
        $lesen = array($handle);
        $schreiben = null;
        $fehler = null;
        $sek = (int) $rest;
        $usek = (int) (($rest - $sek) * 1000000);
        if (@stream_select($lesen, $schreiben, $fehler, $sek, $usek) < 1) {
            continue;
        }
        $von = '';
        $daten = @stream_socket_recvfrom($handle, 4096, 0, $von);
        if ($daten === false || $daten === '') {
            continue;
        }
        $j = json_decode($daten, true);
        if (!is_array($j)) {
            continue;   // kein JSON: kein Wert, aber auch kein Grund zum Abbruch
        }
        $out[] = array('von' => preg_replace('/:\d+$/', '', (string) $von), 'json' => $j);
    }
    return $out;
}

/** Den Antwortport oeffnen. Rueckgabe: array(handle|null, Meldung) */
function gv_antwortport_oeffnen()
{
    $fehlnr = 0;
    $fehltext = '';
    $h = @stream_socket_server('udp://0.0.0.0:' . GV_PORT_ANTWORT, $fehlnr, $fehltext,
                               STREAM_SERVER_BIND);
    if ($h === false) {
        if ((int) $fehlnr === 98) {
            return array(null, 'Der Antwortport ' . GV_PORT_ANTWORT . ' ist bereits belegt. '
                . 'Vermutlich laeuft der Abrufdienst - dann fuehrt er die Suche selbst aus.');
        }
        return array(null, 'Der Antwortport ' . GV_PORT_ANTWORT . ' liess sich nicht oeffnen: '
            . trim((string) $fehltext) . ' (Nummer ' . (int) $fehlnr . ')');
    }
    stream_set_blocking($h, false);
    return array($h, '');
}

/**
 * Geraetesuche per Multicast.
 *
 * Rueckgabe: array(Liste, Meldung). Die Liste enthaelt je Geraet
 * ip, sku, device, hardware, software.
 */
function gv_suche($sekunden = 3)
{
    list($h, $meldung) = gv_antwortport_oeffnen();
    if ($h === null) {
        return array(array(), $meldung);
    }
    $frage = json_encode(array('msg' => array(
        'cmd'  => 'scan',
        'data' => array('account_topic' => 'reserve'),
    )));
    list($ok, $fehler) = gv_udp_senden(GV_MULTICAST, GV_PORT_SUCHE, $frage);
    if (!$ok) {
        fclose($h);
        return array(array(), $fehler);
    }
    $antworten = gv_udp_horchen($h, $sekunden, 60);
    fclose($h);

    $liste = array();
    foreach ($antworten as $a) {
        $m = isset($a['json']['msg']) && is_array($a['json']['msg']) ? $a['json']['msg'] : array();
        $d = isset($m['data']) && is_array($m['data']) ? $m['data'] : array();
        if (!isset($m['cmd']) || $m['cmd'] !== 'scan') {
            continue;
        }
        $ip = isset($d['ip']) ? (string) $d['ip'] : $a['von'];
        $liste[$ip] = array(
            'ip'       => $ip,
            'sku'      => isset($d['sku']) ? (string) $d['sku'] : '',
            'device'   => isset($d['device']) ? (string) $d['device'] : '',
            'hardware' => isset($d['bleVersionHard']) ? (string) $d['bleVersionHard'] : '',
            'software' => isset($d['bleVersionSoft']) ? (string) $d['bleVersionSoft'] : '',
        );
    }
    ksort($liste);
    return array(array_values($liste), '');
}

/**
 * devStatus abfragen. Rueckgabe: array(Werte|null, Meldung).
 *
 * Ein Geraet, das nicht antwortet, liefert null - keine erfundene Null.
 */
function gv_status_abfragen($ip, $sekunden = 2)
{
    list($h, $meldung) = gv_antwortport_oeffnen();
    if ($h === null) {
        return array(null, $meldung);
    }
    list($ok, $fehler) = gv_udp_senden($ip, GV_PORT_BEFEHL,
        json_encode(array('msg' => array('cmd' => 'devStatus', 'data' => new stdClass()))));
    if (!$ok) {
        fclose($h);
        return array(null, $fehler);
    }
    $antworten = gv_udp_horchen($h, $sekunden, 20);
    fclose($h);
    foreach ($antworten as $a) {
        if ($a['von'] !== $ip) {
            continue;   // die Antwort eines anderen Geraets gehoert nicht hierher
        }
        $m = isset($a['json']['msg']) && is_array($a['json']['msg']) ? $a['json']['msg'] : array();
        $d = isset($m['data']) && is_array($m['data']) ? $m['data'] : array();
        if (!isset($m['cmd']) || $m['cmd'] !== 'devStatus') {
            continue;
        }
        $farbe = isset($d['color']) && is_array($d['color']) ? $d['color'] : array();
        return array(array(
            'an'     => isset($d['onOff']) ? (int) $d['onOff'] : null,
            'hell'   => isset($d['brightness']) ? (int) $d['brightness'] : null,
            'kelvin' => isset($d['colorTemInKelvin']) ? (int) $d['colorTemInKelvin'] : null,
            'r'      => isset($farbe['r']) ? (int) $farbe['r'] : null,
            'g'      => isset($farbe['g']) ? (int) $farbe['g'] : null,
            'b'      => isset($farbe['b']) ? (int) $farbe['b'] : null,
        ), '');
    }
    return array(null, 'Keine Antwort von ' . $ip . ' innerhalb von ' . $sekunden . ' s. '
        . 'Steht LAN Control in der Govee-App auf ein?');
}

/* ---------------- Die vier dokumentierten Befehle ---------------- */

function gv_msg($cmd, $data)
{
    return json_encode(array('msg' => array('cmd' => $cmd, 'data' => $data)));
}

function gv_cmd_schalten($an)
{
    return gv_msg('turn', array('value' => $an ? 1 : 0));
}

function gv_cmd_helligkeit($wert)
{
    return gv_msg('brightness', array('value' => max(1, min(100, (int) $wert))));
}

function gv_cmd_farbe($r, $g, $b)
{
    return gv_msg('colorwc', array(
        'color'            => array('r' => (int) $r, 'g' => (int) $g, 'b' => (int) $b),
        'colorTemInKelvin' => 0,
    ));
}

function gv_cmd_kelvin($k)
{
    return gv_msg('colorwc', array(
        'color'            => array('r' => 0, 'g' => 0, 'b' => 0),
        'colorTemInKelvin' => (int) $k,
    ));
}

/* ==================================================================
 * ptReal - die BLE-Befehle ueber die LAN-Schnittstelle
 * ================================================================== */

/**
 * Ein 20-Byte-Paket bauen: bis zu 19 Nutzbytes, mit Nullen aufgefuellt,
 * dahinter die XOR-Pruefsumme ueber genau diese 19 Bytes, base64-kodiert.
 *
 * Geprueft gegen die Beispiele des Forumsbeitrags - siehe
 * gv_selbsttest_faelle().
 */
function gv_pt_paket($bytes)
{
    $b = array_values(array_map('intval', $bytes));
    if (count($b) > 19) {
        return null;   // passt nicht in ein Paket: Fehler, nicht abschneiden
    }
    while (count($b) < 19) {
        $b[] = 0;
    }
    $x = 0;
    $roh = '';
    foreach ($b as $v) {
        $v = $v & 0xFF;
        $x ^= $v;
        $roh .= chr($v);
    }
    return base64_encode($roh . chr($x));
}

/**
 * Eine laengere Nutzlast auf a3-Pakete verteilen.
 *
 *   a3 00 01 <Anzahl> <15 Datenbytes>   Pruefsumme
 *   a3 <Nr>           <17 Datenbytes>   Pruefsumme
 *   a3 ff             <17 Datenbytes>   Pruefsumme     (letztes Paket)
 *
 * Mindestens zwei Pakete: so macht es die Govee-App in ALLEN protokollierten
 * Beispielen, auch wenn die Nutzlast in eines passen wuerde. Ob ein Geraet
 * auch mit einem einzigen auskaeme, ist nicht gemessen - deshalb wird die
 * gemessene Form nachgebaut und nicht die vermutete.
 */
function gv_pt_multi($nutzlast)
{
    $n = count($nutzlast);
    $pakete = 2;
    if ($n > 15) {
        $pakete = 1 + (int) ceil(($n - 15) / 17);
    }
    if ($pakete > 20) {
        return null;   // harte Obergrenze; so lange Befehle gibt es nicht
    }
    $hol = function ($von, $wieviel) use ($nutzlast, $n) {
        $t = array();
        for ($i = $von; $i < $von + $wieviel; $i++) {
            $t[] = $i < $n ? ($nutzlast[$i] & 0xFF) : 0;
        }
        return $t;
    };
    $out = array();
    $out[] = gv_pt_paket(array_merge(array(0xa3, 0x00, 0x01, $pakete), $hol(0, 15)));
    for ($i = 1; $i < $pakete; $i++) {
        $nr = ($i === $pakete - 1) ? 0xff : $i;
        $out[] = gv_pt_paket(array_merge(array(0xa3, $nr), $hol(15 + 17 * ($i - 1), 17)));
    }
    foreach ($out as $p) {
        if ($p === null) {
            return null;
        }
    }
    return $out;
}

/**
 * Der Anstossbefehl, der den Graffiti-Betrieb einschaltet. Er wird in allen
 * protokollierten Segmentbefehlen als letzter mitgeschickt.
 */
function gv_pt_anstoss()
{
    return gv_pt_paket(array(0x33, 0x05, 0x0a, 0x20, 0x03));
}

/** Eine Szene mit Kennung unter 256: 33 05 04 <ID>. */
function gv_pt_szene_einfach($id)
{
    $id = (int) $id;
    if ($id < 0 || $id > 255) {
        return null;
    }
    return array(gv_pt_paket(array(0x33, 0x05, 0x04, $id)));
}

/** Ein/Aus als ptReal-Befehl: 33 01 <01|00>. */
function gv_pt_schalten($an)
{
    return array(gv_pt_paket(array(0x33, 0x01, $an ? 0x01 : 0x00)));
}

/** Helligkeit als ptReal-Befehl: 33 04 <0..64 hex>, also 0 bis 100 Prozent. */
function gv_pt_helligkeit($prozent)
{
    $p = max(0, min(100, (int) $prozent));
    return array(gv_pt_paket(array(0x33, 0x04, (int) round($p * 0x64 / 100))));
}

/**
 * Die Bewegungsarten des Graffiti-Betriebs.
 * Aus dem Forumsbeitrag; gemessen an der H6076-Stehlampe.
 */
function gv_bewegungsmodi()
{
    return array(
        0x0a => 'GV_BEW.RUNTER',
        0x09 => 'GV_BEW.HOCH',
        0x02 => 'GV_BEW.ZYKLUS',
        0x13 => 'GV_BEW.VERBLASSEN',
        0x0f => 'GV_BEW.FUNKELN',
        0x14 => 'GV_BEW.ATMEN',
    );
}

/**
 * Segment- beziehungsweise Pixelsteuerung (Graffiti-Betrieb).
 *
 * Nutzlast:
 *   03 <Bewegung> <Geschw> <HgIntens> <HgR> <HgG> <HgB> <AnzSegmente>
 *   je Segment: <AnzPixel> <R> <G> <B> <PixelID...>
 *
 * $segmente ist eine Liste aus array('ids' => array(...), 'rgb' => array(r,g,b)).
 * Rueckgabe: Liste von base64-Befehlen (a3-Pakete plus Anstossbefehl) oder null.
 */
function gv_pt_graffiti($bewegung, $geschw, $hg_intens, $hg_rgb, $segmente)
{
    $p = array(
        0x03,
        ((int) $bewegung) & 0xFF,
        max(0, min(0x64, (int) $geschw)),
        max(0, min(0x64, (int) $hg_intens)),
        max(0, min(255, (int) $hg_rgb[0])),
        max(0, min(255, (int) $hg_rgb[1])),
        max(0, min(255, (int) $hg_rgb[2])),
        count($segmente),
    );
    foreach ($segmente as $s) {
        $ids = array_values(array_map('intval', $s['ids']));
        $p[] = count($ids);
        $p[] = max(0, min(255, (int) $s['rgb'][0]));
        $p[] = max(0, min(255, (int) $s['rgb'][1]));
        $p[] = max(0, min(255, (int) $s['rgb'][2]));
        foreach ($ids as $id) {
            $p[] = $id & 0xFF;
        }
    }
    $pakete = gv_pt_multi($p);
    if ($pakete === null) {
        return null;
    }
    $pakete[] = gv_pt_anstoss();
    return $pakete;
}

/**
 * Segmentsteuerung, zweites Verfahren: Segmente als Bitmaske.
 *
 * Der Graffiti-Betrieb oben ist an der H6076-Stehlampe gemessen. Bei der
 * Lichterkette H70C4 funktioniert er NICHT - dort gibt es stattdessen zwei
 * kurze Einzelbefehle, die die Segmente ueber eine 16-Bit-Maske ansprechen:
 *
 *   Farbe:      33 05 15 01 R G B 00 00 00 00 00 ID1 ID2 00 00 00 00 00  XOR
 *   Intensitaet 33 05 15 02 Int ID1 ID2 00 .. 00                          XOR
 *
 * Die Maske ist kleinstwertiges Byte zuerst: Segment 1 ist 01 00, Segment 2
 * ist 02 00, Segment 9 ist 00 01, Segment 10 ist 00 02.
 *
 * ACHTUNG - anderer Beleggrad als beim Rest: fuer diese beiden Befehle steht
 * im Forumsbeitrag die Byte-Reihenfolge, aber KEINE fertig kodierte
 * Beispielzeichenkette. Der Selbsttest kann sie deshalb nur gegen die
 * Syntaxbeschreibung pruefen, nicht gegen eine Aufzeichnung. Ob die eigene
 * Leuchte dieses Verfahren versteht oder das Graffiti-Verfahren, sagt nur
 * ein Versuch.
 *
 * $segmente ist eine Liste aus array('nummern' => array(1,2,...),
 * 'rgb' => array(r,g,b)). Je Gruppe entsteht ein eigener Befehl.
 */
function gv_pt_segment_maske($segmente, $intensitaet = null)
{
    $out = array();
    foreach ($segmente as $s) {
        $maske = 0;
        foreach ($s['nummern'] as $n) {
            $n = (int) $n;
            if ($n < 1 || $n > 16) {
                return null;   // ausserhalb der 16 Bit: abweisen, nicht kappen
            }
            $maske |= 1 << ($n - 1);
        }
        $id1 = $maske & 0xFF;
        $id2 = ($maske >> 8) & 0xFF;
        $out[] = gv_pt_paket(array(
            0x33, 0x05, 0x15, 0x01,
            max(0, min(255, (int) $s['rgb'][0])),
            max(0, min(255, (int) $s['rgb'][1])),
            max(0, min(255, (int) $s['rgb'][2])),
            0, 0, 0, 0, 0,
            $id1, $id2,
        ));
        if ($intensitaet !== null) {
            $out[] = gv_pt_paket(array(
                0x33, 0x05, 0x15, 0x02,
                max(0, min(0x64, (int) $intensitaet)),
                $id1, $id2,
            ));
        }
    }
    foreach ($out as $p) {
        if ($p === null) {
            return null;
        }
    }
    return $out ? $out : null;
}

/** Die beiden Segmentverfahren, damit sie an einer Stelle stehen. */
function gv_segmentverfahren()
{
    return array(
        'graffiti' => 'GV_VERF.GRAFFITI',
        'maske'    => 'GV_VERF.MASKE',
    );
}

/**
 * Prozentbalken: die ersten n Pixel leuchten, der Rest bleibt dunkel.
 *
 * Genau die Befehlsfolge, die im Forumsbeitrag als Beispiel steht - alle
 * fuenfzehn Stufen sind im Selbsttest hinterlegt.
 */
function gv_pt_balken($prozent, $rgb, $pixel)
{
    $pixel = max(1, min(200, (int) $pixel));
    $prozent = max(0, min(100, (int) $prozent));
    $anzahl = (int) round($prozent * $pixel / 100);
    $segmente = array();
    if ($anzahl > 0) {
        $segmente[] = array('ids' => range(0, $anzahl - 1), 'rgb' => $rgb);
    }
    return gv_pt_graffiti(0x09, 0x00, 0x00, array(0, 0, 0), $segmente);
}

/**
 * Musikbetrieb: 33 05 0f <Art> <Empfindlichkeit>.
 *
 * ACHTUNG: Diese Form wurde an der H6076-Stehlampe gemessen. Andere Leuchten
 * benutzen 33 05 0c, 33 05 01 oder 33 05 13 - welche Form die eigene Leuchte
 * versteht, sagt nur ein Versuch. Deshalb ist die Kennung der Befehlsgruppe
 * einstellbar und nicht fest verdrahtet.
 */
function gv_pt_musik($gruppe, $art, $empfindlichkeit)
{
    $g = (int) $gruppe;
    if (!in_array($g, array(0x0f, 0x0c, 0x01, 0x13), true)) {
        return null;
    }
    return array(gv_pt_paket(array(0x33, 0x05, $g, ((int) $art) & 0xFF,
        max(0, min(0x64, (int) $empfindlichkeit)))));
}

/**
 * Szenenkatalog.
 *
 * Jede Zeile ist im Forumsbeitrag 446672 protokolliert - keine ist geraten
 * und keine ist aus einer aehnlichen Leuchte uebertragen. Beim Einlesen sind
 * alle 14 Eintraege nachgerechnet worden: jedes Paket ergibt genau 20 Bytes
 * und traegt die richtige XOR-Pruefsumme.
 *
 * Das Kuerzel vorne nennt die Leuchte, an der die Folge aufgezeichnet wurde.
 * Eine Szene der einen Leuchte kann bei einer anderen nichts oder etwas
 * anderes bewirken - Govee vergibt die Kennungen je Modellreihe.
 */
function gv_szenen()
{
    return array(
        'h70c4_sonnenaufgang' => array('sku' => 'H70C4', 'name' => 'GV_SZENE.SONNENAUFGANG', 'cmd' => array(
            'MwUEAAAAAAAAAAAAAAAAAAAAADI=')),
        'h70c4_sonnenuntergang' => array('sku' => 'H70C4', 'name' => 'GV_SZENE.SONNENUNTERGANG', 'cmd' => array(
            'MwUEAQAAAAAAAAAAAAAAAAAAADM=')),
        'h70c4_kerzenlicht' => array('sku' => 'H70C4', 'name' => 'GV_SZENE.KERZENLICHT', 'cmd' => array(
            'MwUECQAAAAAAAAAAAAAAAAAAADs=')),
        'h70c4_aurora' => array('sku' => 'H70C4', 'name' => 'GV_SZENE.AURORA', 'cmd' => array(
            'owABBQICIAAAAAECAZw+AQAAACY=', 'owED8jIDCP+qCP8VAAD/AwCAAKE=',
            'owIAAAApAAAAAwIBrhkB+gAAA8c=', 'owPtAAZk/ggAAACLAP8AAAAI/1o=',
            'o/8VAAAAEAD8AAD/AAAAAAAAAFo=', 'MwUEIjAAAAAAAAAAAAAAAAAAACA=')),
        'h70c4_weihnachten' => array('sku' => 'H70C4', 'name' => 'GV_SZENE.WEIHNACHTEN', 'cmd' => array(
            'owABBgICKQAAAA8AAf//AAAUFIM=', 'owEC/AUGAAAA/wAAAAAAAAAAAKA=',
            'owL/AAAAAAAA/BAA5QApAAAAD3E=', 'owMAAf//AAAUFAL8BQb/AAAAAKM=',
            'owQAAAAAAP8AAAAAAAAAAgD8ErQ=', 'o/8A5QAAAAAAAAAAAAAAAAAAALk=',
            'MwUELTAAAAAAAAAAAAAAAAAAAC8=')),
        'h70c4_schneien' => array('sku' => 'H70C4', 'name' => 'GV_SZENE.SCHNEIEN', 'cmd' => array(
            'owABCQIFHQAAAAIAAf//AIAUFDI=', 'owED5RQCB1H/B+v/AACAAACAAOg=',
            'owIaAAIyGQIB/wADzgYQAIAUASA=', 'owP///8AAIAAAIAAGgACPAMCAXs=',
            'owT/AAPMAgIAjhQB////AACAAHM=', 'owUAgAAaAAIyAgIB/wAD0QwLACc=',
            'owaAFAH///8AAIAAAIAAGgACPOs=', 'owcFAgH/AAPTCgYAgBQB////AOs=',
            'o/8AgAAAgAAAAAAAAAAAAAAAAFw=', 'MwUEMjIAAAAAAAAAAAAAAAAAADI=')),
        'h70c4_sternenhimmel' => array('sku' => 'H70C4', 'name' => 'GV_SZENE.STERNENHIMMEL', 'cmd' => array(
            'owABDAIFKQAAAAECAU0zAn8BAYE=', 'owGDNAEGAAD/iwD/AAD/AP//AGY=',
            'owIA/4sA/wAA1wAAHQAgAAJ4GaM=', 'owMAAf8AA8wDAwM0AQMA//8AAKQ=',
            'owT//wAABAD/AACAACk1AAABAME=', 'owUDsgACyQH/gAAAyQcBAAAAAG0=',
            'owYBMgMAAQIAAAD///8WAP8SAJI=', 'owf8ACkyAAABAAOyAALKAf+YAF0=',
            'owgAywcBAAAAAAGPAwABAgAAAOg=', 'own///8UAP8QAPsAJiQAAAEAA1U=',
            'owr/AALKAf//AADKBwEAAAAAAVI=', 'o/8jAwABAf/McQQA9gAA/gAAADI=',
            'MwUEJDAAAAAAAAAAAAAAAAAAACY=')),
        'h70c4_feuer' => array('sku' => 'H70C4', 'name' => 'GV_SZENE.FEUER', 'cmd' => array(
            'owABCQIFGkMAAAECAcxlA4AUFN0=', 'owEC4woB/zkHFwD8EwD8ABokALM=',
            'owIAAQIBzGYBgBQUAoAUAf9HB6A=', 'owMXAP8AAIAAHQAAAAECAaoEA3o=',
            'owTaCgoA5RQC/wAA/0AHAACAAEk=', 'owUAgAAaJAAAAQIBy2YDgBQUAjY=',
            'owaAFAH/FwcVAP8AAIAAHQACjCY=', 'owcZAAFcNgPoAwMA9BQC/xwH/8Q=',
            'o/9ABwAAgAAAgAAAAAAAAAAAABs=', 'MwUEKjAAAAAAAAAAAAAAAAAAACg=')),
        'h70c4_abendrot' => array('sku' => 'H70C4', 'name' => 'GV_SZENE.ABENDROT', 'cmd' => array(
            'owABBwIDJgAAAAQCAYdcAwAUFF0=', 'owEDABQF/yoH/4RU/3QP/4lh/yE=',
            'owIgBwAAgAAAgAAdMgAAAgIB918=', 'owNMA3cyMgPcMgL/XiXrKf8XANk=',
            'owT8AAAAAB01AAACAgH4TQOEMnI=', 'owUyA9wyAv9eJeYX/hUA/AAAABk=',
            'o/8AAAAAAAAAAAAAAAAAAAAAAFw=', 'MwUEKzAAAAAAAAAAAAAAAAAAACk=')),
        'h70c4_milchstrasse' => array('sku' => 'H70C4', 'name' => 'GV_SZENE.MILCHSTRASSE', 'cmd' => array(
            'owABCAIEJmAAAAUCAZIAAgAUFHw=', 'owED5RQFB0n/CP8P/38A/wAAi+g=',
            'owIA/xAA/AAAgAAaAAAAAQIB/9U=', 'owNeAQAUFAAAFAEAABkAAIAAAHM=',
            'owSAACZkAAAFAgGSAAIAFBQD5RU=', 'owUUBQdJ/wj/D/9/AP8AAIsA//U=',
            'owYEAPcQAPwAGgACBQMAAUwAA+o=', 'o//GFBQAABQBlpaWAACAAACAABk=',
            'MwUEazAAAAAAAAAAAAAAAAAAAGk=')),
        'h70c4_himmel' => array('sku' => 'H70C4', 'name' => 'GV_SZENE.HIMMEL', 'cmd' => array(
            'owABBwIDKQAAAAQAAczMAIAUFAg=', 'owEDTxQGAP//Bk7+////Bj7+AHM=',
            'owL//wD/yAAAgAAAgAAdMQEADLc=', 'owMCAZUAA8kUFAItFAL//////zo=',
            'owT/FQD5EADqAB2BAgMCAgH/AC0=', 'owUDzBRkAi0UAv///////wEA9NU=',
            'o/8AAIAAAAAAAAAAAAAAAAAAANw=', 'MwUEbzAAAAAAAAAAAAAAAAAAAG0=')),
        'h70c4_musik_energie' => array('sku' => 'H70C4', 'name' => 'GV_SZENE.MUSIK_ENERGIE', 'cmd' => array(
            'MwUTBWMB/wAAAAAAAAAAAAAAAL0=')),
        'h6076_aurora' => array('sku' => 'H6076', 'name' => 'GV_SZENE.AURORA', 'cmd' => array(
            'owABBQICIAAAAAECAf8yAQAAAEk=', 'owEA+jIDAP8AAP//qv8AAwCAAEA=',
            'owIAAAAjAAAAAwIB/xkD+gAAAp8=', 'owP6AAR//wD//wCg//8A//8UAWs=',
            'o//vAAD/AAAAAAAAAAAAAAAAAEw=', 'MwUE0CsAAAAAAAAAAAAAAAAAAMk=')),
        'rgbic_aurora' => array('sku' => 'RGBIC', 'name' => 'GV_SZENE.AURORA', 'cmd' => array(
            'owABCQIEI0AAAAECAf8ZA8IKA+I=', 'owEC5jIED/8ICwf/B/j//wbpAGs=',
            'owIC+AEAgAAjQgAAAQAB/xgDu+Q=', 'owMKA4LnMgT/03LLhf8NS/9S/wE=',
            'owSZEgHNAAPkACNEAAABAAH/GIc=', 'owUDuwoDAuUyBAsH/w//CP8G6d0=',
            'owYH+P8QAcwBAIAAJkYAAAEAAZk=', 'owf/GAO7CgMC5TIFB/j/Cwf//y4=',
            'o/8G6Q//CNz/ERIB3QEAgAAAADY=', 'MwUE8QgAAAAAAAAAAAAAAAAAAMs=')),
    );
}

/** Die ptReal-Nachricht aus einer Liste base64-kodierter Befehle. */
function gv_pt_nachricht($cmds)
{
    return gv_msg('ptReal', array('command' => array_values($cmds)));
}

/**
 * Eine base64-Liste pruefen, wie sie ueber den Endpunkt hereinkommen kann.
 * Jeder Eintrag muss genau 20 Bytes ergeben und die richtige Pruefsumme
 * tragen. Was nicht passt, wird abgewiesen und gemeldet - nicht
 * zurechtgebogen und nicht stillschweigend weitergereicht.
 *
 * Rueckgabe: array(Liste|null, Meldung)
 */
function gv_pt_pruefen($cmds)
{
    $out = array();
    if (count($cmds) < 1 || count($cmds) > 30) {
        return array(null, 'Erlaubt sind 1 bis 30 Befehle, angekommen sind ' . count($cmds) . '.');
    }
    foreach ($cmds as $i => $c) {
        $c = trim((string) $c);
        if (!preg_match('#^[A-Za-z0-9+/]{27}=$#', $c)) {
            return array(null, 'Befehl ' . ($i + 1) . ' ist keine 20-Byte-Base64-Zeichenkette.');
        }
        $roh = base64_decode($c, true);
        if ($roh === false || strlen($roh) !== 20) {
            return array(null, 'Befehl ' . ($i + 1) . ' laesst sich nicht dekodieren.');
        }
        $x = 0;
        for ($k = 0; $k < 19; $k++) {
            $x ^= ord($roh[$k]);
        }
        if ($x !== ord($roh[19])) {
            return array(null, sprintf('Befehl %d traegt die Pruefsumme %02x, richtig waere %02x.',
                $i + 1, ord($roh[19]), $x));
        }
        $out[] = $c;
    }
    return array($out, '');
}

/**
 * Die Faelle des Selbsttests: Sollwerte aus dem Forumsbeitrag 446672.
 *
 * Jede Zeile ist array(Bezeichnung, erzeugte Liste, Sollliste). Wer den
 * Nachbau aendert, sieht hier sofort, ob er noch dasselbe erzeugt wie die
 * Govee-App. Ein Nachbau, der nur "laeuft", ist nicht geprueft.
 */
function gv_selbsttest_faelle()
{
    $f = array();

    $f[] = array('ptReal Ein', gv_pt_schalten(1), array('MwEBAAAAAAAAAAAAAAAAAAAAADM='));
    $f[] = array('ptReal Aus', gv_pt_schalten(0), array('MwEAAAAAAAAAAAAAAAAAAAAAADI='));
    $f[] = array('Anstoss Graffiti', array(gv_pt_anstoss()), array('MwUKIAMAAAAAAAAAAAAAAAAAAB8='));
    $f[] = array('Szene 0 (Sonnenaufgang)', gv_pt_szene_einfach(0), array('MwUEAAAAAAAAAAAAAAAAAAAAADI='));
    $f[] = array('Szene 1 (Sonnenuntergang)', gv_pt_szene_einfach(1), array('MwUEAQAAAAAAAAAAAAAAAAAAADM='));
    $f[] = array('Szene 9 (Kerzenlicht)', gv_pt_szene_einfach(9), array('MwUECQAAAAAAAAAAAAAAAAAAADs='));
    $f[] = array('Helligkeit 100 %', gv_pt_helligkeit(100), array('MwRkAAAAAAAAAAAAAAAAAAAAAFM='));

    /* Die fuenfzehn Stufen des Prozentbalkens, 14 Pixel, Gelb 64 64 00.
     * Der Sollwert ist die Befehlsfolge, die im Forumsbeitrag Stufe fuer
     * Stufe abgedruckt ist. */
    $balken = array(
        0  => array('owABAgMJAAAAAAAAAAAAAAAAAKo=', 'o/8AAAAAAAAAAAAAAAAAAAAAAFw='),
        1  => array('owABAgMJAAAAAAABAWRkAAAAAKo=', 'o/8AAAAAAAAAAAAAAAAAAAAAAFw='),
        2  => array('owABAgMJAAAAAAABAmRkAAABAKg=', 'o/8AAAAAAAAAAAAAAAAAAAAAAFw='),
        3  => array('owABAgMJAAAAAAABA2RkAAABAqs=', 'o/8AAAAAAAAAAAAAAAAAAAAAAFw='),
        4  => array('owABAgMJAAAAAAABBGRkAAABAqw=', 'o/8DAAAAAAAAAAAAAAAAAAAAAF8='),
        5  => array('owABAgMJAAAAAAABBWRkAAABAq0=', 'o/8DBAAAAAAAAAAAAAAAAAAAAFs='),
        6  => array('owABAgMJAAAAAAABBmRkAAABAq4=', 'o/8DBAUAAAAAAAAAAAAAAAAAAF4='),
        7  => array('owABAgMJAAAAAAABB2RkAAABAq8=', 'o/8DBAUGAAAAAAAAAAAAAAAAAFg='),
        8  => array('owABAgMJAAAAAAABCGRkAAABAqA=', 'o/8DBAUGBwAAAAAAAAAAAAAAAF8='),
        9  => array('owABAgMJAAAAAAABCWRkAAABAqE=', 'o/8DBAUGBwgAAAAAAAAAAAAAAFc='),
        10 => array('owABAgMJAAAAAAABCmRkAAABAqI=', 'o/8DBAUGBwgJAAAAAAAAAAAAAF4='),
        11 => array('owABAgMJAAAAAAABC2RkAAABAqM=', 'o/8DBAUGBwgJCgAAAAAAAAAAAFQ='),
        12 => array('owABAgMJAAAAAAABDGRkAAABAqQ=', 'o/8DBAUGBwgJCgsAAAAAAAAAAF8='),
        13 => array('owABAgMJAAAAAAABDWRkAAABAqU=', 'o/8DBAUGBwgJCgsMAAAAAAAAAFM='),
        14 => array('owABAgMJAAAAAAABDmRkAAABAqY=', 'o/8DBAUGBwgJCgsMDQAAAAAAAF4='),
    );
    $anstoss = gv_pt_anstoss();
    foreach ($balken as $n => $soll) {
        /* Der Balken rechnet in Prozent; hier wird die Stufe direkt gesetzt,
         * damit der Vergleich nicht an einer Rundung haengt. */
        $segmente = $n > 0 ? array(array('ids' => range(0, $n - 1), 'rgb' => array(0x64, 0x64, 0x00)))
                           : array();
        $f[] = array('Balken ' . $n . ' von 14 Pixeln',
            gv_pt_graffiti(0x09, 0x00, 0x00, array(0, 0, 0), $segmente),
            array($soll[0], $soll[1], $anstoss));
    }

    /* Das Segmentbeispiel aus dem Forumsbeitrag:
     * Pixel 1 rot, Pixel 5 gruen, Pixel 10 blau, Pixel 11 und 12 gelb. */
    $f[] = array('Segmente 1 rot, 5 gruen, 10 blau, 11+12 gelb',
        gv_pt_graffiti(0x09, 0x00, 0x01, array(0, 0, 0), array(
            array('ids' => array(0),      'rgb' => array(0xff, 0x00, 0x00)),
            array('ids' => array(4),      'rgb' => array(0x00, 0xff, 0x00)),
            array('ids' => array(9),      'rgb' => array(0x00, 0x00, 0xff)),
            array('ids' => array(10, 11), 'rgb' => array(0x64, 0x64, 0x00)),
        )),
        array('owABAgMJAAEAAAAEAf8AAAABAFA=', 'o///AAQBAAD/CQJkZAAKCwAAAFM=', $anstoss));

    /* Das zweite Segmentverfahren (Bitmaske, H70C4).
     *
     * Hier ist der Beleggrad ein anderer, und das gehoert dazugesagt: fuer
     * diese Befehle steht im Forumsbeitrag die Byte-Reihenfolge, aber keine
     * fertig kodierte Beispielzeichenkette. Der Sollwert unten ist deshalb
     * aus der Syntaxbeschreibung von Hand aufgeschrieben und hier kodiert -
     * geprueft wird damit, dass der Nachbau genau diese Bytes erzeugt, nicht,
     * dass eine Leuchte sie schon einmal angenommen hat.
     *
     * Segment 1 und 3 rot: Maske 0000 0101 = 05 00
     *   33 05 15 01 ff 00 00 00 00 00 00 00 05 00 00 00 00 00 00  XOR
     * Segment 9 und 10, Intensitaet 50 von 100 (0x32): Maske 00 03
     *   33 05 15 02 32 00 03 00 .. 00                              XOR
     */
    $f[] = array('Segmentmaske: 1 und 3 rot (Syntax, nicht aufgezeichnet)',
        gv_pt_segment_maske(array(array('nummern' => array(1, 3), 'rgb' => array(0xff, 0, 0)))),
        array(gv_pt_paket(array(0x33, 0x05, 0x15, 0x01, 0xff, 0x00, 0x00,
                                0, 0, 0, 0, 0, 0x05, 0x00))));
    $f[] = array('Segmentmaske: 9 und 10 rot, Intensitaet 50 (Syntax, nicht aufgezeichnet)',
        gv_pt_segment_maske(array(array('nummern' => array(9, 10), 'rgb' => array(0xff, 0, 0))), 0x32),
        array(gv_pt_paket(array(0x33, 0x05, 0x15, 0x01, 0xff, 0x00, 0x00,
                                0, 0, 0, 0, 0, 0x00, 0x03)),
              gv_pt_paket(array(0x33, 0x05, 0x15, 0x02, 0x32, 0x00, 0x03))));

    return $f;
}

/** Rueckgabe: array(Anzahl, Fehlschlaege, Textausgabe). */
function gv_selbsttest()
{
    $zeilen = array();
    $fehl = 0;
    $faelle = gv_selbsttest_faelle();
    foreach ($faelle as $fall) {
        list($name, $ist, $soll) = $fall;
        $ist = is_array($ist) ? $ist : array();
        $ok = ($ist === $soll);
        if (!$ok) {
            $fehl++;
        }
        $zeilen[] = ($ok ? '[ OK ] ' : '[FEHL] ') . $name;
        if (!$ok) {
            $zeilen[] = '       erzeugt : ' . implode(' , ', $ist);
            $zeilen[] = '       erwartet: ' . implode(' , ', $soll);
        }
    }

    /* Zweite Ebene: der Szenenkatalog. Jedes Paket muss 20 Bytes ergeben und
     * die richtige Pruefsumme tragen - so faellt eine beim Abschreiben
     * verstuemmelte Zeile auf, bevor sie an eine Leuchte geht. */
    foreach (gv_szenen() as $schluessel => $szene) {
        list($geprueft, $meldung) = gv_pt_pruefen($szene['cmd']);
        $ok = ($geprueft !== null);
        if (!$ok) {
            $fehl++;
        }
        $zeilen[] = ($ok ? '[ OK ] ' : '[FEHL] ') . 'Szenenkatalog ' . $schluessel
                  . ' (' . count($szene['cmd']) . ' Pakete)' . ($ok ? '' : ' - ' . $meldung);
    }

    $anzahl = count($faelle) + count(gv_szenen());
    array_unshift($zeilen, sprintf('%d Faelle geprueft, %d Fehlschlaege.', $anzahl, $fehl), '');
    return array($anzahl, $fehl, implode("\n", $zeilen));
}

/* ==================================================================
 * Cloud - die offizielle Entwicklerschnittstelle
 *
 * Nur fuer Geraete, die kein LAN Control koennen. Sie hat eine Anfragegrenze
 * (Geraeteliste 30/min je Konto, Zustand 30/min je Geraet, Steuerung 2/s je
 * Geraet); der Dienst haelt sich mit dem Takt 'cloud_takt' darunter.
 * ================================================================== */

/**
 * Rueckgabe: array(Feld|null, Meldung).
 * Kommt HTML statt JSON zurueck, hat ein Gateway geantwortet und nicht die
 * Schnittstelle - das gehoert in die Meldung, sonst sucht man den Fehler beim
 * Schluessel, der laengst stimmt.
 */
function gv_cloud_anfrage($pfad, $daten = null)
{
    $g = gv_geheim();
    $key = trim((string) $g['cloud_key']);
    if ($key === '') {
        return array(null, 'Es ist kein Govee-API-Schluessel hinterlegt (Reiter Einstellungen).');
    }
    if (!function_exists('curl_init')) {
        return array(null, 'Die PHP-Erweiterung curl fehlt - ohne sie ist die Cloud nicht erreichbar.');
    }
    $ch = curl_init(GV_CLOUD . $pfad);
    $kopf = array(
        'Govee-API-Key: ' . $key,
        'Content-Type: application/json',
        'Accept: application/json',
        'Accept-Language: de-DE,de;q=0.9,en;q=0.8',
        'Accept-Encoding: gzip, deflate',
        'User-Agent: LoxBerry-Govee-Plugin/0.9.1',
    );
    curl_setopt($ch, CURLOPT_HTTPHEADER, $kopf);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    if ($daten !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($daten));
    }
    $antwort = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlfehler = curl_error($ch);
    curl_close($ch);

    if ($antwort === false) {
        return array(null, 'Die Cloud war nicht erreichbar: ' . $curlfehler);
    }
    if ($code === 429) {
        return array(null, 'Die Cloud hat die Anfragegrenze gemeldet (HTTP 429). '
            . 'Den Cloud-Takt im Reiter Einstellungen hoeher setzen.');
    }
    if ($code === 401 || $code === 403) {
        return array(null, 'Die Cloud weist den API-Schluessel ab (HTTP ' . $code . ').');
    }
    $j = json_decode($antwort, true);
    if (!is_array($j)) {
        $anfang = ltrim(substr((string) $antwort, 0, 40));
        if ($anfang !== '' && $anfang[0] === '<') {
            return array(null, 'Es kam HTML statt JSON zurueck (HTTP ' . $code . '). '
                . 'Geantwortet hat ein Gateway oder ein Anmeldeportal, nicht die Govee-Schnittstelle.');
        }
        return array(null, 'Die Antwort war kein JSON (HTTP ' . $code . '): ' . substr((string) $antwort, 0, 120));
    }
    if ($code !== 200) {
        return array(null, 'Die Cloud meldet HTTP ' . $code . ': '
            . (isset($j['message']) ? (string) $j['message'] : substr((string) $antwort, 0, 120)));
    }
    return array($j, '');
}

function gv_cloud_geraete()
{
    return gv_cloud_anfrage('/router/api/v1/user/devices');
}

function gv_cloud_zustand($sku, $device)
{
    return gv_cloud_anfrage('/router/api/v1/device/state', array(
        'requestId' => bin2hex(random_bytes(8)),
        'payload'   => array('sku' => $sku, 'device' => $device),
    ));
}

function gv_cloud_schalten($sku, $device, $typ, $instanz, $wert)
{
    return gv_cloud_anfrage('/router/api/v1/device/control', array(
        'requestId' => bin2hex(random_bytes(8)),
        'payload'   => array(
            'sku'        => $sku,
            'device'     => $device,
            'capability' => array('type' => $typ, 'instance' => $instanz, 'value' => $wert),
        ),
    ));
}

/* ==================================================================
 * MQTT-Gateway des LoxBerry
 *
 * Das Gateway ist seit LoxBerry 3 Bestandteil des Systems, kein Plugin.
 * Mqtt.Brokerhost ist ab Werk auf 'localhost' gesetzt - eine Pruefung darauf
 * beantwortet also NICHT die Frage, ob Nachrichten ankommen koennen.
 * Massgeblich ist Gatewayautostart.
 * ================================================================== */

/**
 * Einen Wert fuer den UDP-Eingang des MQTT-Gateways unschaedlich machen.
 *
 * Das Gateway liest ZEILENWEISE. Ein Zeilenumbruch im Wert - aus einer
 * Fehlermeldung des Betriebssystems, einem Geraetenamen oder der Ausgabe
 * eines Systembefehls - zerlegt die Uebertragung, und aus den Bruchstuecken
 * bildet das Gateway erfundene Themen. Ein Tabulator schadet ebenso, weil
 * Leerzeichen Thema und Wert trennt.
 */
function gv_mqtt_wert_saeubern($v)
{
    $wert = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $wert));
}

function gv_mqtt_zustand()
{
    $p = gv_paths();
    $leer = array('gefunden' => 0, 'autostart' => 0, 'udpport' => 0, 'broker' => '',
                  'brokerport' => '', 'lokal' => 0);
    if ($p['home'] === '') {
        return $leer;
    }
    $gen = gv_json_lesen($p['home'] . '/config/system/general.json');
    $m = array();
    if (isset($gen['Mqtt']) && is_array($gen['Mqtt'])) {
        $m = $gen['Mqtt'];
    } elseif (isset($gen['mqtt']) && is_array($gen['mqtt'])) {
        $m = $gen['mqtt'];
    }
    if (!$m) {
        return $leer;
    }
    $hol = function ($gross, $klein) use ($m) {
        if (isset($m[$gross])) {
            return $m[$gross];
        }
        return isset($m[$klein]) ? $m[$klein] : '';
    };
    return array(
        'gefunden'   => 1,
        'autostart'  => in_array((string) $hol('Gatewayautostart', 'gatewayautostart'), array('1', 'true'), true) ? 1 : 0,
        'udpport'    => (int) $hol('Udpinport', 'udpinport'),
        'broker'     => (string) $hol('Brokerhost', 'brokerhost'),
        'brokerport' => (string) $hol('Brokerport', 'brokerport'),
        'lokal'      => in_array((string) $hol('Uselocalbroker', 'uselocalbroker'), array('1', 'true'), true) ? 1 : 0,
    );
}

/**
 * Werte ueber den UDP-Eingang des Gateways veroeffentlichen. Bewusst nicht
 * mit einem eigenen MQTT-Client: so muss das Plugin ueberhaupt keine
 * Broker-Zugangsdaten kennen. Das Gateway hat sie ohnehin.
 */
function gv_mqtt_senden(array $paare, $praefix)
{
    $z = gv_mqtt_zustand();
    if (!$z['udpport']) {
        gv_log_gebremst('mqtt_kein_port',
            'MQTT: kein UDP-Eingangsport in der general.json gefunden - nichts gesendet.');
        return false;
    }
    if (!$z['autostart']) {
        gv_log_gebremst('mqtt_aus', 'MQTT: das Gateway ist nicht auf Autostart gestellt '
            . '(System, MQTT Gateway). Es wird gesendet, aber vermutlich hoert niemand zu.');
    }
    $fehlnr = 0;
    $fehltext = '';
    $s = @stream_socket_client('udp://127.0.0.1:' . (int) $z['udpport'], $fehlnr, $fehltext, 2);
    if ($s === false) {
        gv_log_gebremst('mqtt_socket', 'MQTT: der UDP-Eingang des Gateways liess sich nicht '
            . 'oeffnen: ' . trim((string) $fehltext));
        return false;
    }
    foreach ($paare as $k => $v) {
        if ($v === null || $v === '') {
            continue;   // fehlender Wert: nichts senden statt eine erfundene 0
        }
        $msg = 'publish ' . $praefix . '/' . $k . ' ' . gv_mqtt_wert_saeubern($v);
        @fwrite($s, $msg);
    }
    fclose($s);
    return true;
}

/** Alle Themen, die der Dienst veroeffentlicht, mit ihrer Bedeutung. */
function gv_mqtt_themen()
{
    return array(
        'ok'                => 'GV_MQTT.OK',
        'geraete'           => 'GV_MQTT.GERAETE',
        'geraetN/name'      => 'GV_MQTT.NAME',
        'geraetN/erreichbar' => 'GV_MQTT.ERREICHBAR',
        'geraetN/an'        => 'GV_MQTT.AN',
        'geraetN/hell'      => 'GV_MQTT.HELL',
        'geraetN/kelvin'    => 'GV_MQTT.KELVIN',
        'geraetN/r'         => 'GV_MQTT.R',
        'geraetN/g'         => 'GV_MQTT.G',
        'geraetN/b'         => 'GV_MQTT.B',
        'geraetN/hex'       => 'GV_MQTT.HEX',
        'geraetN/alter'     => 'GV_MQTT.ALTER',
    );
}

/* ==================================================================
 * Loxone-Vorlagen
 *
 * Nachbau der Bausteine aus LoxBerry::LoxoneTemplateBuilder; das Modul gibt es
 * nur in Perl. Attributreihenfolge, CRLF als Zeilenende und der Tabulator vor
 * den Kindelementen entsprechen dem geprueften PHP-Nachbau aus
 * LoxBerry-Plugin-APC-UPS (ap_xml_virtual_in_http).
 *
 * Die Bauform VirtualOut ist an den Mustern VQ_GOVEE_*.xml aus der laufenden
 * Anlage ausgerichtet: Comment und CmdOff stehen dort nur, wenn sie belegt
 * sind. Jene Musterdateien tragen LF als Zeilenende, der Nachbau CRLF - beide
 * Formen sind in Loxone Config eingelesen worden.
 * ================================================================== */

function gv_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function gv_xml_virtual_out($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut ';
    $o .= 'Title="' . gv_x($kopf['title']) . '" ';
    $o .= 'Comment="' . gv_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . gv_x($kopf['address']) . '" ';
    $o .= 'CloseAfterSend="true" ';
    $o .= 'CmdSep=""';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'Title="' . gv_x($c['title']) . '" ';
        if (isset($c['comment']) && $c['comment'] !== '') {
            $o .= 'Comment="' . gv_x($c['comment']) . '" ';
        }
        $o .= 'CmdOn="' . gv_x($c['on']) . '" ';
        if (isset($c['off']) && $c['off'] !== '') {
            $o .= 'CmdOff="' . gv_x($c['off']) . '" ';
        }
        $o .= 'Analog="' . (!empty($c['analog']) ? 'true' : 'false') . '"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}

function gv_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'Title="' . gv_x($kopf['title']) . '" ';
    $o .= 'Comment="' . gv_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . gv_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . gv_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . gv_x($c['title']) . '" ';
        $o .= 'Comment="' . gv_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . gv_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        $o .= 'Signed="false" ';
        $o .= 'Analog="true" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="1" ';
        $o .= 'DestValHigh="1" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="' . gv_x(isset($c['min']) ? $c['min'] : '0') . '" ';
        $o .= 'MaxVal="' . gv_x(isset($c['max']) ? $c['max'] : '100') . '"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Die Werte des Status-Endpunkts: Einheit, Grenzen und Sprachschluessel.
 * MinVal/MaxVal werden realistisch gesetzt, nicht pauschal auf +/-2147483647:
 * Loxone zieht daraus die Reglergrenzen und die Plausibilitaetspruefung.
 */
function gv_status_felder()
{
    return array(
        'AN'     => array('',  0, 1,     'GV_FELD.AN'),
        'HELL'   => array('%', 0, 100,   'GV_FELD.HELL'),
        'KELVIN' => array('K', 0, 10000, 'GV_FELD.KELVIN'),
        'R'      => array('',  0, 255,   'GV_FELD.R'),
        'G'      => array('',  0, 255,   'GV_FELD.G'),
        'B'      => array('',  0, 255,   'GV_FELD.B'),
        'OK'     => array('',  0, 1,     'GV_FELD.OK'),
        'ALTER'  => array('s', 0, 86400, 'GV_FELD.ALTER'),
    );
}

/** Text fuer ein XML-Attribut: Auszeichnung raus, Entitaeten aufloesen. */
function gv_klartext($schluessel)
{
    return trim(strip_tags(html_entity_decode(gv_t($schluessel), ENT_QUOTES, 'UTF-8')));
}

/**
 * Virtueller Ausgang je Geraet: Loxone spricht dabei UNMITTELBAR mit der
 * Leuchte, ohne Umweg ueber den LoxBerry. Das ist der schnellste Weg fuer
 * Ein/Aus, Helligkeit und Farbe - der LoxBerry wird dafuer nicht gebraucht.
 *
 * Rueckgabe: array(Name, Inhalt)
 */
function gv_vorlage_ausgang($nummer = 1)
{
    $g = gv_geraet($nummer);
    if ($g === null || $g['art'] !== 'lan') {
        return array('', '');
    }
    $name = $g['name'];
    $cmds = array();
    $cmds[] = array(
        'title'   => $name . ' - ' . gv_klartext('GV_XML.EINAUS'),
        'on'      => gv_cmd_schalten(1),
        'off'     => gv_cmd_schalten(0),
        'analog'  => false,
    );
    $cmds[] = array(
        'title'   => $name . ' - ' . gv_klartext('GV_XML.HELL'),
        'comment' => gv_klartext('GV_XML.HELL_K'),
        'on'      => str_replace('"value":100', '"value":<v.0>', gv_cmd_helligkeit(100)),
        'analog'  => true,
    );
    $cmds[] = array(
        'title'   => $name . ' - ' . gv_klartext('GV_XML.KELVIN'),
        'comment' => sprintf(gv_klartext('GV_XML.KELVIN_K'), $g['kmin'], $g['kmax']),
        'on'      => str_replace('"colorTemInKelvin":4000', '"colorTemInKelvin":<v.0>', gv_cmd_kelvin(4000)),
        'analog'  => true,
    );
    foreach (array(
        array('GV_XML.WARMWEISS', $g['kmin']),
        array('GV_XML.NEUTRALWEISS', 4000),
        array('GV_XML.KALTWEISS', $g['kmax']),
    ) as $w) {
        $cmds[] = array(
            'title'  => $name . ' - ' . gv_klartext($w[0]) . ' ' . $w[1] . ' K',
            'on'     => gv_cmd_kelvin($w[1]),
            'analog' => false,
        );
    }
    foreach (array(
        array('GV_XML.ROT',   255, 0, 0),
        array('GV_XML.GRUEN', 0, 255, 0),
        array('GV_XML.BLAU',  0, 0, 255),
    ) as $w) {
        $cmds[] = array(
            'title'  => $name . ' - ' . gv_klartext($w[0]),
            'on'     => gv_cmd_farbe($w[1], $w[2], $w[3]),
            'analog' => false,
        );
    }
    $cmds[] = array(
        'title'   => $name . ' - ' . gv_klartext('GV_XML.STATUS'),
        'comment' => gv_klartext('GV_XML.STATUS_K'),
        'on'      => gv_msg('devStatus', new stdClass()),
        'analog'  => false,
    );

    return array(
        'VQ_GOVEE_' . preg_replace('/[^A-Za-z0-9_]/', '_', $name) . '.xml',
        gv_xml_virtual_out(array(
            'title'   => 'GOVEE ' . $name . ($g['sku'] !== '' ? ' (' . $g['sku'] . ')' : ''),
            'comment' => sprintf(gv_klartext('GV_XML.KOPF_AUSGANG'), $g['ip']),
            'address' => '/dev/udp/' . $g['ip'] . '/' . GV_PORT_BEFEHL,
        ), $cmds),
    );
}

/**
 * Virtueller Ausgang mit den Szenen und dem Prozentbalken. Getrennt vom
 * Grundbefehlssatz, weil die ptReal-Befehle geraeteabhaengig sind: eine Szene
 * der einen Leuchte kann bei einer anderen nichts bewirken.
 */
function gv_vorlage_szenen($nummer = 1)
{
    $g = gv_geraet($nummer);
    if ($g === null || $g['art'] !== 'lan') {
        return array('', '');
    }
    $cmds = array();
    foreach (gv_szenen() as $schluessel => $szene) {
        $cmds[] = array(
            'title'   => $g['name'] . ' - ' . gv_klartext($szene['name']) . ' (' . $szene['sku'] . ')',
            'comment' => sprintf(gv_klartext('GV_XML.SZENE_K'), $szene['sku'], count($szene['cmd'])),
            'on'      => gv_pt_nachricht($szene['cmd']),
            'analog'  => false,
        );
    }
    if ($g['pixel'] > 0) {
        for ($p = 0; $p <= 100; $p += 10) {
            $bef = gv_pt_balken($p, array(0x64, 0x64, 0x00), $g['pixel']);
            if ($bef === null) {
                continue;
            }
            $cmds[] = array(
                'title'   => $g['name'] . ' - ' . sprintf(gv_klartext('GV_XML.BALKEN'), $p),
                'comment' => sprintf(gv_klartext('GV_XML.BALKEN_K'), $g['pixel']),
                'on'      => gv_pt_nachricht($bef),
                'analog'  => false,
            );
        }
    }
    return array(
        'VQ_GOVEE_' . preg_replace('/[^A-Za-z0-9_]/', '_', $g['name']) . '_Szenen.xml',
        gv_xml_virtual_out(array(
            'title'   => 'GOVEE ' . $g['name'] . ' ' . gv_klartext('GV_XML.SZENEN'),
            'comment' => sprintf(gv_klartext('GV_XML.KOPF_SZENEN'), $g['ip']),
            'address' => '/dev/udp/' . $g['ip'] . '/' . GV_PORT_BEFEHL,
        ), $cmds),
    );
}

/**
 * Virtueller Eingang: Loxone holt den Zustand vom Plugin ab. Das geht nur
 * ueber den LoxBerry, weil ein virtueller Eingang die UDP-Antwort auf Port
 * 4002 nur dann sieht, wenn der LoxBerry sie nicht schon abgeholt hat.
 */
function gv_vorlage_eingang($nummer = 1)
{
    $g = gv_geraet($nummer);
    if ($g === null) {
        return array('', '');
    }
    $p = gv_paths();
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    $cmds = array();
    foreach (gv_status_felder() as $feld => $info) {
        $cmds[] = array(
            'title'   => 'GOVEE_' . (int) $nummer . '_' . $feld,
            'comment' => gv_klartext($info[3]) . ($info[0] !== '' ? ' [' . $info[0] . ']' : ''),
            'check'   => '\i' . $feld . '=\i\v',
            'min'     => $info[1],
            'max'     => $info[2],
        );
    }
    $adresse = 'http://' . $host . '/plugins/' . $p['plugin']
             . '/index.php?token=' . gv_token() . '&aktion=status&geraet=' . (int) $nummer;
    return array(
        'VI_GOVEE_' . preg_replace('/[^A-Za-z0-9_]/', '_', $g['name']) . '.xml',
        gv_xml_virtual_in_http(array(
            'title'   => 'GOVEE ' . $g['name'] . ' ' . gv_klartext('GV_XML.ZUSTAND'),
            'address' => $adresse,
            'polling' => '60',
            'comment' => sprintf(gv_klartext('GV_XML.KOPF_EINGANG'), date('d.m.Y')),
        ), $cmds),
    );
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch. Deshalb muss language_en.ini immer
 * vollstaendig sein.
 *
 * Die Funktion setzt kein gv_paths() voraus, damit derselbe Block in jedes
 * Plugin passt. Der Pfad wird zweistufig gesucht:
 *   installiert: <home>/templates/plugins/<ordner>/lang
 *   Archiv:      <pluginwurzel>/templates/lang
 * ================================================================== */

function gv_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

function gv_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
                if (is_dir($k)) {
                    $home = $k;
                    break;
                }
            }
        }
        $ordner = basename(dirname(__FILE__));
        $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
        if (!is_dir($pfad)) {
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . gv_sprache() . '.ini', true, INI_SCANNER_RAW);
        if (!is_array($texte)) {
            $texte = array();
        }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) {
            $texte = array_replace_recursive($rueck, $texte);
        }
        /* INI_SCANNER_RAW liefert die Werte samt der Anfuehrungszeichen
         * zurueck, in die sie in der Datei stehen muessen. Die gehoeren nicht
         * in die Ausgabe. */
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) {
                continue;
            }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    $teile = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$teile[0]][$teile[1]]) ? $texte[$teile[0]][$teile[1]] : $schluessel;
}
