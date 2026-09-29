<?php
/**
 * Govee - Abrufdienst
 *
 * Bewusst OHNE Shebang-Zeile. Aufgerufen wird die Datei ausschliesslich als
 * Argument von php (aus dienst.sh und aus postinstall.sh); die CLI-Fassung
 * von PHP entfernt eine Shebang-Zeile zwar selbst, jeder andere Aufrufweg
 * gibt sie aber als Text aus - beim Selbsttest stand sie so als erste Zeile
 * vor dem Ergebnis.
 *
 * Aufgaben:
 *   1. Den Antwortport 4002 halten. Das ist der Kern: die Govee-Leuchten
 *      schicken ihre Antwort IMMER an Port 4002 des Anfragenden. Nur ein
 *      Prozess kann diesen Port haben. Deshalb fragt der Dienst ab, und
 *      Oberflaeche wie Miniserver-Endpunkt reichen ihre Wuensche ueber eine
 *      Warteschlange an ihn weiter, statt selbst zu funken.
 *   2. Im eingestellten Takt devStatus abfragen und das Abbild schreiben.
 *   3. Die Werte ueber das MQTT-Gateway veroeffentlichen.
 *   4. Die Warteschlange abarbeiten.
 *
 * Aufruf:
 *   php govee_dienst.php               Dienst starten (macht dienst.sh)
 *   php govee_dienst.php --selbsttest  Nachbau gegen die Sollwerte messen
 *   php govee_dienst.php --einmal      einen Durchlauf, dann beenden
 *   php govee_dienst.php --mqtt-leeren zurueckbehaltene MQTT-Themen abraeumen
 *                                      (aus uninstall/uninstall)
 *
 * Protokolliert wird ausschliesslich in die Datei. Das Startskript leitet
 * stdout ohnehin dorthin um - ein zweiter Kanal schriebe jede Zeile doppelt.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Bibliothek finden. Installiert oder Archiv entscheidet der eigene
 * Ablageort: installiert liegt diese Datei unter <home>/bin/plugins/<ordner>,
 * im ausgepackten Archiv unter <archiv>/bin. Bis 0.9.20 wurden drei
 * Kandidaten der Reihe nach probiert, und der erste lautete aus einem Archiv
 * unter / /webfrontend/html/plugins/bin/gv_lib.php ab der Laufwerkswurzel -
 * was dort lag, lief als Bibliothek, auch wenn die eigene daneben lag (in WSL
 * gemessen, Pruefung-Govee-0.9.21, Fall C4). Bauart ZendureSolarFlow 0.9.26. */
$gv_lib = null;
if (basename(dirname(__DIR__)) === 'plugins') {
    $gv_kandidaten = array(dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/'
        . basename(__DIR__) . '/gv_lib.php');
} else {
    $gv_kandidaten = array(dirname(__DIR__) . '/webfrontend/html/gv_lib.php');
}
foreach ($gv_kandidaten as $gv_kandidat) {
    if (is_file($gv_kandidat)) {
        $gv_lib = $gv_kandidat;
        break;
    }
}
if ($gv_lib === null) {
    fwrite(STDERR, "gv_lib.php nicht gefunden - Plugin neu installieren.\n");
    exit(2);
}
require_once $gv_lib;

/* ---------------- Selbsttest ----------------
 * Ohne Geraet, ohne Netz: rechnet die ptReal-Bauteile durch und vergleicht
 * sie mit den Sollwerten aus dem Forumsbeitrag. Ein Nachbau, der nur
 * "laeuft", ist nicht geprueft. */
if (in_array('--selbsttest', $argv, true)) {
    list($anzahl, $fehl, $text) = gv_selbsttest();
    echo $text, "\n";
    exit($fehl > 0 ? 1 : 0);
}

/* Nur bekannte Schalter. Ohne diese Wache startete JEDER unbekannte
 * Schalter den Dienst - `--hilfe` tat es am 05.09.2026 an der Anlage, ohne
 * ein Wort auszugeben. Ein Werkzeug, das auf eine Frage mit einem
 * Dauerlauf antwortet, ist eine Falle. */
/* Befehle, die die Statusabfrage NICHT sichtbar macht - siehe die Messung
 * in gv_befehl_lan(). */
define('GV_STILLE_BEFEHLE', array('szene', 'segment', 'musik', 'balken', 'pt'));

$gv_bekannt = array('--selbsttest', '--einmal', '--mqtt-leeren');
foreach (array_slice($argv, 1) as $gv_arg) {
    if (!in_array($gv_arg, $gv_bekannt, true)) {
        fwrite(STDERR, "Unbekannter Schalter: " . $gv_arg . "
"
             . "Aufruf: " . basename($argv[0]) . " [--selbsttest | --einmal | --mqtt-leeren]
"
             . "  ohne Schalter laeuft der Dienst dauerhaft;
"
             . "  gestartet und angehalten wird er ueber bin/dienst.sh.
");
        exit(2);
    }
}

/* Ohne Wurzel, oder aus einem ausgepackten Archiv heraus: nichts anlegen,
 * nichts abfragen, keinen Port binden (gv_keine_wurzel_abbruch() in
 * gv_lib.php). Bis 0.9.20 lief ein Archiv unter einer echten Wurzel hier als
 * Dienst bzw. Einmallauf der Anlage - mit deren Konfiguration, Warteschlange
 * und Protokoll (in WSL gemessen, Pruefung-Govee-0.9.21, Faelle B6/B7). Der
 * Selbsttest oben ist ausgenommen: er prueft nur den Nachbau. */
gv_keine_wurzel_abbruch('govee_dienst.php');

/* Aus der Deinstallation (M5): die zurueckbehaltenen Themen dieser Linie
 * abraeumen und enden - ohne Sperre, ohne Port, ohne Protokoll. */
if (in_array('--mqtt-leeren', $argv, true)) {
    foreach (gv_mqtt_abraeumen() as $gv_zeile) {
        echo $gv_zeile, "\n";
    }
    exit(0);
}

$gv_einmal = in_array('--einmal', $argv, true);
$gv_p = gv_paths();
/* PHP-Fehler des laufenden Dienstes gehoeren ins Protokoll (B48, 17.09.2026).
 *
 * dienst.sh startet mit 'nohup php ... >> <datei> 2>&1'; den Deskriptor haelt
 * die SCHALE. Loescht log_maint.pl die Datei (RAM-Scheibe, Regeln/06), zeigen
 * stdout und stderr auf einen geloeschten Inode. PHP-CLI am Geraet schreibt
 * Laufzeitfehler mit display_errors = stderr, log_errors = 1 und leerem
 * error_log genau dorthin - Warnungen und Absturzgruende gingen verloren.
 * Am Geraet gemessen an BatterieBMS (17.09.2026: fd 1/2 '(deleted)'), die
 * Abhilfe dort im Wegwerfbaum in beide Richtungen geeicht (Regeln/03, 'Die
 * dritte Protokollart'). error_log auf die Protokolldatei oeffnet sie je
 * Meldung neu und legt sie an, wenn sie fehlt - wie gv_log(). Die
 * Kommandozeilenzweige darueber bleiben bei stdout. */
ini_set('log_errors', '1');
ini_set('display_errors', '0');
ini_set('error_log', $gv_p['log']);

@mkdir($gv_p['datadir'] . '/befehle', 0775, true);
@mkdir($gv_p['datadir'] . '/antworten', 0775, true);

/* Nur EIN Dienst. Ohne diese Sperre banden zwei Dienste denselben
 * UDP-Port 4002 (am Geraet mit `ss -lunp` gesehen: zwei Sockel); die
 * Antworten der Leuchten verteilen sich dann auf beide, und jeder haelt
 * die Haelfte fuer Ausfall. Das Handle muss leben, solange der Dienst
 * lebt - deshalb steht es in einer Variablen und wird nicht geschlossen.
 * Der Einmallauf nimmt die Sperre auch: er fragt dieselben Geraete. */
$gv_sperre = @fopen($gv_p['datadir'] . '/dienst.sperre', 'c');
if ($gv_sperre === false) {
    fwrite(STDERR, "Sperrdatei nicht anzulegen: " . $gv_p['datadir'] . "/dienst.sperre
");
    exit(3);
}
if (!flock($gv_sperre, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Es laeuft bereits ein Govee-Dienst. Zweiter Start abgebrochen.
");
    exit(3);
}

gv_log('Dienst gestartet (PID ' . getmypid() . ', PHP ' . PHP_VERSION . ').');

/* Sauber beenden, wenn das Startskript SIGTERM schickt. pcntl ist nicht auf
 * jedem System geladen - ohne die Erweiterung endet der Dienst durch das
 * Signal selbst, nur ohne Abschiedszeile im Protokoll. */
$gv_laeuft = true;
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () {
        global $gv_laeuft;
        $gv_laeuft = false;
    });
    pcntl_signal(SIGINT, function () {
        global $gv_laeuft;
        $gv_laeuft = false;
    });
}

/* ---------------- Antwortport ----------------
 * Einmal oeffnen und halten. Vor einem zweiten Dienst schuetzt die Sperre
 * oben, nicht der Port: UDP 4002 laesst sich ein zweites Mal binden (C3,
 * govee_agenten/code Befund 3). Gelingt das Oeffnen nicht, wird beendet
 * statt danebengefunkt. */
list($gv_horcher, $gv_meldung) = gv_antwortport_oeffnen();
if ($gv_horcher === null) {
    gv_log('ABBRUCH: ' . $gv_meldung);
    gv_zustand_schreiben($gv_meldung);
    exit(1);
}
gv_log('Antwortport ' . GV_PORT_ANTWORT . ' geoeffnet.');
gv_alte_befehle_verwerfen();

/**
 * Das Lebenszeichen - und die letzte Stoerung.
 *
 * Ein leerer $fehler loescht die bisherige NICHT. Bis 0.9.8 tat er das, und
 * weil der Waechter den Dienst binnen einer Minute neu startet, war die
 * Ursache eines Absturzes verschwunden, bevor jemand hinsah. Quittiert wird
 * sie in der Oberflaeche.
 */
function gv_zustand_schreiben($fehler = '')
{
    $pfad = gv_paths()['datadir'] . '/zustand.json';
    $alt = gv_json_lesen($pfad);
    $neu = array(
        'ts'  => time(),
        'pid' => getmypid(),
    );
    if ((string) $fehler !== '') {
        $neu['fehler'] = (string) $fehler;
        $neu['fehler_ts'] = time();
    } else {
        $neu['fehler'] = isset($alt['fehler']) ? (string) $alt['fehler'] : '';
        $neu['fehler_ts'] = isset($alt['fehler_ts']) ? (int) $alt['fehler_ts'] : 0;
    }
    gv_json_schreiben($pfad, $neu);
}

/**
 * Eine Runde Statusabfrage ueber ALLE LAN-Geraete.
 *
 * Erst alle Fragen hinausschicken, dann einmal horchen - so dauert eine Runde
 * nicht (Anzahl x Wartezeit), sondern nur eine Wartezeit. Die Antworten
 * werden ueber die Absender-IP zugeordnet.
 */
function gv_runde($horcher, $geraete, $wartezeit = 2)
{
    $frage = gv_msg('devStatus', new stdClass());
    $offen = array();
    foreach ($geraete as $nr => $g) {
        if ($g['art'] !== 'lan') {
            continue;
        }
        /* Ein Name wird je Runde aufgeloest; zugeordnet wird ueber die IP,
         * von der die Leuchte antwortet (C6). Bis 0.9.22 stand hier der Name
         * als Schluessel, und eine so eingetragene Leuchte galt dauerhaft als
         * nicht erreichbar (govee_agenten/code, Befund 6). */
        $ziel = gv_adresse_aufloesen($g['ip']);
        if ($ziel === '') {
            gv_log_gebremst('name_' . $nr, 'Geraet ' . $nr . ' (' . $g['name'] . '): der Name '
                . $g['ip'] . ' ist nicht aufloesbar.');
            continue;
        }
        list($ok, $fehler) = gv_udp_senden($ziel, GV_PORT_BEFEHL, $frage);
        if (!$ok) {
            gv_log_gebremst('send_' . $nr, 'Geraet ' . $nr . ' (' . $g['name'] . '): ' . $fehler);
            continue;
        }
        $offen[$ziel] = $nr;
    }
    $treffer = array();
    if ($offen) {
        foreach (gv_udp_horchen($horcher, $wartezeit, 60) as $a) {
            if (!isset($offen[$a['von']])) {
                continue;
            }
            $m = isset($a['json']['msg']) && is_array($a['json']['msg']) ? $a['json']['msg'] : array();
            if (!isset($m['cmd']) || $m['cmd'] !== 'devStatus') {
                continue;
            }
            $d = isset($m['data']) && is_array($m['data']) ? $m['data'] : array();
            $farbe = isset($d['color']) && is_array($d['color']) ? $d['color'] : array();
            $treffer[$offen[$a['von']]] = array(
                /* Die Rohantwort, gekappt. Welche Felder eine bestimmte
                 * Leuchte ueberhaupt liefert, beantwortet nur sie selbst -
                 * die Zuordnung darunter verengt sie auf sechs bekannte. */
                'roh'    => substr((string) json_encode($d), 0, 600),
                'an'     => isset($d['onOff']) ? (int) $d['onOff'] : null,
                'hell'   => isset($d['brightness']) ? (int) $d['brightness'] : null,
                'kelvin' => isset($d['colorTemInKelvin']) ? (int) $d['colorTemInKelvin'] : null,
                'r'      => isset($farbe['r']) ? (int) $farbe['r'] : null,
                'g'      => isset($farbe['g']) ? (int) $farbe['g'] : null,
                'b'      => isset($farbe['b']) ? (int) $farbe['b'] : null,
            );
        }
    }
    return $treffer;
}

/**
 * Eine Runde ueber die CLOUD-Geraete.
 *
 * Bis 0.9.8 gab es sie nicht: gv_cloud_zustand() war definiert und wurde
 * nirgends aufgerufen. Ein als Cloud eingerichtetes Geraet liess sich
 * schalten, meldete am Endpunkt aber dauerhaft OK=0 und Striche.
 *
 * Sie laeuft im Cloud-Takt, nicht im LAN-Takt: die Schnittstelle hat eine
 * Anfragegrenze von 30 Abfragen je Minute und Geraet.
 */
function gv_runde_cloud($geraete)
{
    $treffer = array();
    foreach ($geraete as $nr => $g) {
        if ($g['art'] !== 'cloud') {
            continue;
        }
        list($antwort, $meldung) = gv_cloud_zustand($g['sku'], $g['device']);
        if ($antwort === null) {
            gv_log_gebremst('cloudzustand_' . $nr, 'Cloud-Zustand ' . $g['name'] . ': ' . $meldung);
            continue;
        }
        /* Die Antwort traegt eine Liste von Faehigkeiten. Gelesen wird, was
         * da ist - was fehlt, bleibt null und wird nicht erfunden. */
        $werte = array('an' => null, 'hell' => null, 'kelvin' => null,
                       'r' => null, 'g' => null, 'b' => null);
        $caps = isset($antwort['payload']['capabilities'])
            ? (array) $antwort['payload']['capabilities'] : array();
        foreach ($caps as $c) {
            $instanz = isset($c['instance']) ? (string) $c['instance'] : '';
            $wert = isset($c['state']['value']) ? $c['state']['value'] : null;
            if ($wert === null || !is_scalar($wert)) {
                continue;
            }
            if ($instanz === 'powerSwitch') {
                $werte['an'] = (int) $wert;
            } elseif ($instanz === 'brightness') {
                $werte['hell'] = (int) $wert;
            } elseif ($instanz === 'colorTemperatureK') {
                $werte['kelvin'] = (int) $wert;
            } elseif ($instanz === 'colorRgb') {
                $z = (int) $wert;
                $werte['r'] = ($z >> 16) & 0xFF;
                $werte['g'] = ($z >> 8) & 0xFF;
                $werte['b'] = $z & 0xFF;
            }
        }
        $werte['roh'] = substr((string) json_encode($caps), 0, 600);
        $treffer[$nr] = $werte;
    }
    return $treffer;
}

/** Die Nummern der Geraete einer Art - wer in einer Runde gefragt wird (M1). */
function gv_nummern_der_art($geraete, $art)
{
    $n = array();
    foreach ($geraete as $nr => $g) {
        if ($g['art'] === $art) {
            $n[$nr] = true;
        }
    }
    return $n;
}

/**
 * Das Abbild fuer Endpunkt und Oberflaeche schreiben und veroeffentlichen.
 *
 * $gefragt traegt die Nummern der Geraete, die in DIESER Runde gefragt
 * wurden (M1). Nur sie zaehlen ohne Antwort als Fehlversuch; alle uebrigen
 * behalten ihren Eintrag, und ok/fehler_folge werden nur ueber die gefragten
 * gebildet. Bis 0.9.22 fuehrte jede LAN-Runde ein Cloud-Geraet als
 * Fehlversuch: fehl stieg bis etwa 10, und MQTT meldete die gesunde Leuchte
 * die meiste Zeit als nicht erreichbar (govee_agenten/code Befund 4,
 * govee_agenten/mqtt Befund 1).
 */
function gv_abbild_schreiben($treffer, $gefragt)
{
    $p = gv_paths();
    $cfg = gv_config();
    $alt = gv_loxone();
    $altg = isset($alt['geraete']) && is_array($alt['geraete']) ? $alt['geraete'] : array();

    $neu = array();
    $ok_gesamt = 0;
    $gefragt_n = 0;
    foreach (gv_geraete() as $nr => $g) {
        $z = isset($treffer[$nr]) ? $treffer[$nr] : null;
        if ($z !== null || isset($gefragt[$nr])) {
            $gefragt_n++;
        }
        if ($z !== null) {
            $ok_gesamt++;
            $eintrag = array_merge(array(
                'name'  => $g['name'],
                'art'   => $g['art'],
                'ip'    => $g['ip'],
                'sku'   => $g['sku'],
                'pixel' => $g['pixel'],
                'ok'    => 1,
                'ts'    => time(),
                'fehl'  => 0,
            ), $z);
            $eintrag['hex'] = ($z['r'] === null || $z['g'] === null || $z['b'] === null)
                ? null : sprintf('%02X%02X%02X', $z['r'], $z['g'], $z['b']);
        } else {
            /* Kein neuer Wert: den alten behalten und ihn ausdruecklich als
             * alt kennzeichnen. Eine erfundene 0 waere eine stille
             * Falschaussage - in der Loxone-App saehe alles normal aus. */
            $vorher = isset($altg[$nr]) && is_array($altg[$nr]) ? $altg[$nr] : array();
            $eintrag = array_merge(array(
                'name' => $g['name'], 'art' => $g['art'], 'ip' => $g['ip'], 'sku' => $g['sku'],
                'pixel' => $g['pixel'], 'an' => null, 'hell' => null, 'kelvin' => null,
                'r' => null, 'g' => null, 'b' => null, 'hex' => null, 'ts' => 0,
            ), $vorher);
            if (isset($gefragt[$nr])) {
                $eintrag['ok'] = 0;
                /* Zaehler fehlgeschlagener Abrufe IN FOLGE. Er trennt "hakt
                 * kurz" von "seit Stunden tot"; in Loxone will man die Meldung
                 * erst beim zweiten oder dritten Fehlversuch. Zurueckgesetzt
                 * wird er nur, wenn wirklich Werte kamen. */
                $eintrag['fehl'] = (isset($vorher['fehl']) ? (int) $vorher['fehl'] : 0) + 1;
            } else {
                /* In dieser Runde nicht gefragt (M1): kein Fehlversuch. */
                $eintrag['ok'] = isset($vorher['ok']) ? (int) $vorher['ok'] : 0;
                $eintrag['fehl'] = isset($vorher['fehl']) ? (int) $vorher['fehl'] : 0;
            }
            /* 'alter' steht nicht mehr in der Datei: es wird beim LESEN
             * gerechnet (gv_werte). Ein Wert aus einer aelteren Fassung wird
             * hier entfernt, damit nicht zwei Wahrheiten nebeneinander stehen. */
            unset($eintrag['alter']);
        }
        $neu[$nr] = $eintrag;
    }

    /* Der Zeitstempel wird NUR bei Erfolg aufgefrischt. Vorher stand hier
     * bedingungslos time(); gv_alter() lieferte damit dauerhaft fast 0, auch
     * wenn seit Stunden keine Leuchte mehr geantwortet hatte - die Kachel
     * "Letzter Abruf" mass nur noch, dass der Dienst lebt. Dass er lebt,
     * beantwortet das Lebenszeichen in zustand.json, und zwar getrennt.
     *
     * ok und fehler_folge nur ueber die gefragten Geraete (M1); wurde keines
     * gefragt, bleiben beide stehen. */
    $ts_alt = isset($alt['ts']) ? (int) $alt['ts'] : 0;
    if ($gefragt_n > 0) {
        $ok_neu = $ok_gesamt > 0 ? 1 : 0;
        $fehler_folge = $ok_gesamt > 0
            ? 0
            : ((isset($alt['fehler_folge']) ? (int) $alt['fehler_folge'] : 0) + 1);
    } else {
        $ok_neu = isset($alt['ok']) ? (int) $alt['ok'] : 0;
        $fehler_folge = isset($alt['fehler_folge']) ? (int) $alt['fehler_folge'] : 0;
    }
    gv_json_schreiben($p['datadir'] . '/loxone.json', array(
        'ts'           => $ok_gesamt > 0 ? time() : $ts_alt,
        'ok'           => $ok_neu,
        'fehler_folge' => $fehler_folge,
        'geraete'      => $neu,
    ));

    if (!empty($cfg['mqtt_ein'])) {
        /* Der Herzschlag 'ts' aendert sich in jeder Runde und geht deshalb in
         * jeder hinaus, auch waehrend einer Stoerung; alles andere nur, wenn
         * es sich geaendert hat, und im vollen Satz (M6). Die Paare baut
         * gv_mqtt_paare() - dieselbe Funktion, gegen die der Reiter Test die
         * Themenliste haelt (M4). */
        $jetzt = time();
        $praefix = trim((string) $cfg['mqtt_topic'], '/');
        $paare = gv_mqtt_paare($neu, array('ok' => $ok_neu, 'geraete' => count($neu),
            'fehler_folge' => $fehler_folge), $cfg, $jetzt);
        list($weg_paare, $weg_leeren, $weg_nummern) = gv_mqtt_entfernte($neu, $cfg);
        if (gv_mqtt_runde_senden($paare, $weg_paare, $weg_leeren, $praefix, $jetzt) && $weg_nummern) {
            gv_mqtt_entfernte($neu, $cfg, $weg_nummern);
        }
    }
    return $ok_gesamt;
}

/**
 * Entfernte Geraete (M7): jede Nummer bis nr_hoechste, die nicht mehr in der
 * Liste steht, meldet EINMAL je Dienstlauf erreichbar 0, alter -1, fehl -1 -
 * wie der Endpunkt fuer ein unbekanntes Geraet -, und ihre zurueckbehaltenen
 * Themen werden abgeraeumt. Bis 0.9.22 verstummte ein entferntes Geraet
 * einfach; Loxone behielt erreichbar 1 und den letzten Zustand
 * (govee_agenten/mqtt, Befund 7).
 *
 * Mit $gemeldet (Liste der Nummern) wird nur vermerkt, dass die Meldung
 * hinausging - erst nach gelungenem Versand.
 * Rueckgabe: array(Paare, zu leerende Themen, Nummern).
 */
function gv_mqtt_entfernte($neu, $cfg, $gemeldet = null)
{
    static $schon = array();
    if ($gemeldet !== null) {
        foreach ($gemeldet as $nr) {
            $schon[(int) $nr] = true;
        }
        return null;
    }
    $hoechste = isset($cfg['nr_hoechste']) ? (int) $cfg['nr_hoechste'] : 0;
    foreach (array_keys($neu) as $nr) {
        $hoechste = max($hoechste, (int) $nr);
    }
    $paare = array();
    $leeren = array();
    $nummern = array();
    for ($nr = 1; $nr <= min(999, $hoechste); $nr++) {
        if (isset($neu[$nr])) {
            unset($schon[$nr]);
            continue;
        }
        if (isset($schon[$nr])) {
            continue;
        }
        $nummern[] = $nr;
        $pfx = 'geraet' . $nr . '/';
        $paare[$pfx . 'erreichbar'] = 0;
        $paare[$pfx . 'alter'] = -1;
        $paare[$pfx . 'fehl'] = -1;
        foreach (array_keys(gv_mqtt_themen()) as $t) {
            if (strpos($t, 'geraetN/') === 0 && gv_mqtt_retain($t)) {
                $leeren[] = $pfx . substr($t, 8);
            }
        }
    }
    return array($paare, $leeren, $nummern);
}

/**
 * Nur Aenderungen senden (M6): der volle Satz beim Start des Dienstes und
 * alle 30 Minuten, dazwischen nur, was sich gegenueber dem zuletzt
 * GESENDETEN Wert geaendert hat. Gemerkt wird erst nach gelungenem Versand
 * und je Praefix - ein neues Praefix bekommt sofort den vollen Satz. $immer
 * geht ungefiltert hinaus (entfernte Geraete, M7). Bis 0.9.22 ging in jeder
 * Runde der volle Satz hinaus, ohne Pause: 27 Datagramme in 0,26 ms
 * (govee_agenten/mqtt, Befund 6).
 */
function gv_mqtt_runde_senden($paare, $immer, $leeren, $praefix, $jetzt)
{
    static $letzte = array();
    static $voll = 0;
    static $fuer = null;
    $voll_jetzt = ($fuer !== $praefix || $voll === 0 || $jetzt - $voll >= 1800);
    if ($voll_jetzt) {
        $letzte = array();
    }
    $raus = array();
    foreach ($paare as $k => $v) {
        if ($v === null || $v === '') {
            continue;
        }
        if (array_key_exists($k, $letzte) && $letzte[$k] === gv_mqtt_wert_saeubern($v)) {
            continue;
        }
        $raus[$k] = $v;
    }
    foreach ($immer as $k => $v) {
        $raus[$k] = $v;
    }
    if (!$raus && !$leeren) {
        return true;
    }
    if (!gv_mqtt_senden($raus, $praefix, $leeren)) {
        return false;
    }
    if ($voll_jetzt) {
        $voll = $jetzt;
        $fuer = $praefix;
    }
    foreach ($raus as $k => $v) {
        $letzte[$k] = gv_mqtt_wert_saeubern($v);
    }
    foreach ($leeren as $k) {
        unset($letzte[$k]);
    }
    return true;
}

/* ==================================================================
 * Befehle aus der Warteschlange
 * ================================================================== */

function gv_antwort($kennung, $ok, $meldung)
{
    $ordner = gv_paths()['datadir'] . '/antworten';
    @mkdir($ordner, 0775, true);
    gv_json_schreiben($ordner . '/' . $kennung . '.json',
        array('ok' => (int) $ok, 'meldung' => (string) $meldung, 'ts' => time()));
}


/** Rueckgabe: array(ok, Meldung) */
function gv_befehl_ausfuehren($b, $horcher)
{
    $cfg = gv_config();
    $aktion = isset($b['aktion']) ? (string) $b['aktion'] : '';

    if ($aktion === 'abruf') {
        $alle_g = gv_geraete();
        $treffer = gv_runde($horcher, $alle_g, 2);
        $n = gv_abbild_schreiben($treffer, gv_nummern_der_art($alle_g, 'lan'));
        return array($n > 0 ? 1 : 0, $n > 0
            ? ($n . ' Geraet(e) haben geantwortet.')
            : 'Kein Geraet hat geantwortet.');
    }

    if ($aktion === 'suche') {
        /* Der Dienst haelt den Antwortport, also sucht auch er. */
        $frage = gv_msg('scan', array('account_topic' => 'reserve'));
        list($ok, $fehler) = gv_udp_senden(GV_MULTICAST, GV_PORT_SUCHE, $frage);
        if (!$ok) {
            return array(0, $fehler);
        }
        $liste = array();
        foreach (gv_udp_horchen($horcher, 3, 60) as $a) {
            $m = isset($a['json']['msg']) && is_array($a['json']['msg']) ? $a['json']['msg'] : array();
            if (!isset($m['cmd']) || $m['cmd'] !== 'scan') {
                continue;
            }
            $d = isset($m['data']) && is_array($m['data']) ? $m['data'] : array();
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
        gv_json_schreiben(gv_paths()['datadir'] . '/gefunden.json',
            array('ts' => time(), 'liste' => array_values($liste)));
        return array(count($liste) > 0 ? 1 : 0, count($liste) > 0
            ? (count($liste) . ' Geraet(e) gefunden.')
            : 'Es hat sich kein Geraet gemeldet. Steht LAN Control in der Govee-App auf ein?');
    }

    /* Ab hier wird geschaltet. */
    if (empty($cfg['steuerung_ein'])) {
        return array(0, 'Schreibende Befehle sind gesperrt (Reiter Einstellungen).');
    }
    /* Gruppenbefehl: an alle eingerichteten Leuchten. Gemeldet wird je Geraet,
     * nicht pauschal - ein "ok" fuer acht Leuchten, von denen zwei nicht
     * antworten, waere eine stille Falschaussage. */
    if (isset($b['geraet']) && (string) $b['geraet'] === 'alle') {
        $alle = gv_geraete();
        if (!$alle) {
            return array(0, 'Es ist kein Geraet eingerichtet.');
        }
        $teile = array();
        $gut = 0;
        foreach ($alle as $n => $unused) {
            $einzeln = $b;
            $einzeln['geraet'] = (int) $n;
            list($o, $m) = gv_befehl_ausfuehren($einzeln, $horcher);
            if ((int) $o === 1) {
                $gut++;
            }
            $teile[] = $n . ': ' . $m;
        }
        return array($gut > 0 ? 1 : 0,
            $gut . ' von ' . count($alle) . ' Geraeten angenommen. ' . implode(' | ', $teile));
    }

    $nr = isset($b['geraet']) ? (int) $b['geraet'] : 1;
    $g = gv_geraet($nr);
    if ($g === null) {
        return array(0, 'Geraet ' . $nr . ' ist nicht eingerichtet.');
    }

    /* Pflichtangaben und Nachrichtenbau stehen in der Bibliothek - der
     * Trockenlauf im Reiter Test ruft dieselben zwei Funktionen auf und
     * zeigt damit genau das, was hier hinausgeht. Zwei Kopien derselben
     * Logik laufen zwangslaeufig auseinander. */
    list($pok, $pmeldung) = gv_befehl_pruefen($aktion, $b);
    if (!$pok) {
        return array(0, $pmeldung);
    }

    /* --- Cloud-Geraete koennen nur die Grundbefehle --- */
    if ($g['art'] === 'cloud') {
        return gv_befehl_cloud($g, $aktion, $b);
    }

    list($nachricht, $bmeldung) = gv_nachricht_bauen($aktion, $g, $b, $cfg);
    if ($nachricht === null) {
        return array(0, $bmeldung);
    }

    list($ok, $fehler) = gv_udp_senden($g['ip'], GV_PORT_BEFEHL, $nachricht);
    if (!$ok) {
        return array(0, $fehler);
    }
    /* Gesendet ist nicht bestaetigt: UDP kennt keine Quittung, und die
     * Govee-Leuchten antworten auf Steuerbefehle nicht. Genau das steht in
     * der Meldung - ein "erledigt", das niemand geprueft hat, waere gelogen.
     *
     * Und der Verweis auf die Statusabfrage gilt nicht fuer alles: devStatus
     * meldet onOff, brightness, color und colorTem - eine BETRIEBSART meldet
     * es nicht. Am 05.09.2026 an einer H61A8 gemessen: Helligkeit 100 -> 40
     * und Farbe FF0113 -> 00FF00 standen binnen Sekunden in der Antwort;
     * Szenenkennung und Musikbetrieb aenderten sie ueberhaupt nicht, auch
     * nicht bei fuenf Abfragen im Vier-Sekunden-Takt. Auf eine Probe zu
     * verweisen, die nichts sehen kann, ist derselbe Fehler wie ein
     * ungeprueftes "erledigt". */
    $stille = in_array($aktion, GV_STILLE_BEFEHLE, true);
    return array(1, 'An ' . $g['name'] . ' (' . $g['ip'] . ') gesendet. '
        . 'UDP quittiert nicht; '
        . ($stille
            ? 'und die Statusabfrage zeigt diese Betriebsart nicht - ob sie angekommen ist, '
              . 'sieht nur, wer auf die Leuchte schaut.'
            : 'ob es angekommen ist, zeigt die naechste Statusabfrage.'));
}

/** Die Grundbefehle ueber die Cloud. Szenen und Segmente bleiben dem LAN vorbehalten. */
function gv_befehl_cloud($g, $aktion, $b)
{
    $cfg = gv_config();
    if (empty($cfg['cloud_ein'])) {
        return array(0, 'Die Cloud ist ausgeschaltet (Reiter Einstellungen).');
    }
    $typ = '';
    $instanz = '';
    $wert = 0;
    if ($aktion === 'ein' || $aktion === 'aus') {
        $typ = 'devices.capabilities.on_off';
        $instanz = 'powerSwitch';
        $wert = ($aktion === 'ein') ? 1 : 0;
    } elseif ($aktion === 'hell' && isset($b['wert']) && (int) $b['wert'] <= 0) {
        /* Helligkeit 0 heisst auch ueber die Cloud "aus" (U9), wie auf dem
         * LAN-Weg (gv_nachricht_bauen). Bis 0.9.22 wurde daraus 1 %, obwohl
         * die Vorlage "0 schaltet aus" zusagt. */
        $typ = 'devices.capabilities.on_off';
        $instanz = 'powerSwitch';
        $wert = 0;
    } elseif ($aktion === 'hell' && isset($b['wert'])) {
        $typ = 'devices.capabilities.range';
        $instanz = 'brightness';
        $wert = max(1, min(100, (int) $b['wert']));
    } elseif ($aktion === 'kelvin' && isset($b['wert'])) {
        $typ = 'devices.capabilities.color_setting';
        $instanz = 'colorTemperatureK';
        $wert = (int) $b['wert'];
    } elseif ($aktion === 'farbe' && isset($b['r'], $b['g'], $b['b'])) {
        $typ = 'devices.capabilities.color_setting';
        $instanz = 'colorRgb';
        $wert = (((int) $b['r']) << 16) + (((int) $b['g']) << 8) + ((int) $b['b']);
    } elseif ($aktion === 'farbe' && isset($b['wert'])) {
        /* Farbe als eine Zahl (r*65536 + g*256 + b), wie sie der Baustein
         * "Farbe als Zahl" der Vorlage "ueber LoxBerry" schickt - dieselbe
         * Form, die colorRgb erwartet. Bis 0.9.22 lehnte der Cloud-Weg sie ab
         * (Pruefung 29.09.2026), der Baustein wirkte nie. */
        $typ = 'devices.capabilities.color_setting';
        $instanz = 'colorRgb';
        $wert = max(0, min(16777215, (int) $b['wert']));
    } else {
        return array(0, 'Ueber die Cloud sind nur ein, aus, hell, kelvin und farbe moeglich. '
            . 'Szenen und Segmente brauchen den LAN-Weg.');
    }
    list($antwort, $meldung) = gv_cloud_schalten($g['sku'], $g['device'], $typ, $instanz, $wert);
    if ($antwort === null) {
        return array(0, $meldung);
    }
    $code = isset($antwort['code']) ? (int) $antwort['code'] : 0;
    if ($code !== 200) {
        return array(0, 'Die Cloud meldet Code ' . $code . ': '
            . (isset($antwort['msg']) ? (string) $antwort['msg'] : 'ohne Begruendung'));
    }
    return array(1, 'Die Cloud hat den Befehl fuer ' . $g['name'] . ' angenommen.');
}

/**
 * Veraltete Auftraege beim Dienststart verwerfen.
 *
 * Bis 0.9.20 fuehrte ein frisch gestarteter Dienst jeden liegenden Auftrag
 * aus, gleich wie alt: ein "ein" aus dem Reiter Test, eingereiht ohne
 * laufenden Dienst, schaltete die Leuchte beim naechsten Start ungefragt (in
 * WSL gemessen, Pruefung-Govee-0.9.21, Faelle K4/K5). Seit 0.9.21 reiht
 * gv_befehl_absetzen() ohne Dienst gar nicht mehr ein; was trotzdem liegt
 * (Dienst starb zwischen Pruefung und Abholen, Datenordner zurueckgesichert),
 * faellt hier heraus.
 *
 * 60 s wie BatterieBMS 0.9.25 und ZendureSolarFlow 0.9.26 (Entscheidung des
 * Hausherrn vom 18.09.2026): wer einreiht, wartet hoechstens GV_WARTEN_WEB =
 * 10 s auf die Antwort. Ein Zeitpunkt mehr als 5 s in der Zukunft gilt
 * ebenfalls als veraltet; ohne lesbaren Zeitpunkt zaehlt die Aenderungszeit
 * der Datei. Nur beim Start; im Betrieb holt der Dienst jeden Auftrag im
 * naechsten Durchlauf ab.
 */
function gv_alte_befehle_verwerfen()
{
    $ordner = gv_paths()['datadir'] . '/befehle';
    $jetzt = time();
    clearstatcache();
    foreach (glob($ordner . '/*.json') ?: array() as $datei) {
        $b = gv_json_lesen($datei);
        $ts = (isset($b['ts']) && preg_match('/^[0-9]{1,12}$/', (string) $b['ts']))
            ? (int) $b['ts'] : (int) @filemtime($datei);
        $alter = $ts > 0 ? $jetzt - $ts : null;
        if ($alter !== null && $alter <= 60 && $alter >= -5) {
            continue;
        }
        @unlink($datei);
        gv_antwort(basename($datei, '.json'), 0, 'Beim Dienststart verworfen: veraltet.');
        gv_log('Warteschlange beim Start: Auftrag '
             . (isset($b['aktion']) ? preg_replace('/[^a-z0-9_]/i', '', (string) $b['aktion']) : '?')
             . ($alter === null ? ' ohne lesbaren Zeitpunkt' : ' ' . $alter . ' s alt')
             . ' - VERWORFEN, nicht ausgefuehrt.');
    }
}

/** Die Warteschlange abarbeiten. Harte Obergrenze je Durchlauf. */
function gv_warteschlange($horcher)
{
    $ordner = gv_paths()['datadir'] . '/befehle';
    $dateien = @glob($ordner . '/*.json');
    if (!$dateien) {
        return;
    }
    sort($dateien);
    $n = 0;
    foreach ($dateien as $datei) {
        if (++$n > 20) {
            gv_log_gebremst('queue_voll', 'Warteschlange: mehr als 20 Befehle auf einmal - '
                . 'der Rest kommt im naechsten Durchlauf.', 300);
            break;
        }
        $kennung = basename($datei, '.json');
        $b = gv_json_lesen($datei);
        @unlink($datei);
        if (!$b) {
            gv_antwort($kennung, 0, 'Der Befehl liess sich nicht lesen.');
            continue;
        }
        list($ok, $meldung) = gv_befehl_ausfuehren($b, $horcher);
        gv_antwort($kennung, $ok, $meldung);
        gv_log('Befehl ' . (isset($b['aktion']) ? $b['aktion'] : '?')
            . ' -> ' . ($ok ? 'ok' : 'abgelehnt') . ': ' . $meldung);
    }
}

/** Antwortdateien aufraeumen, die niemand abgeholt hat. */
function gv_aufraeumen()
{
    $dateien = @glob(gv_paths()['datadir'] . '/antworten/*.json');
    if (!$dateien) {
        return;
    }
    $jetzt = time();
    $n = 0;
    foreach ($dateien as $d) {
        if (++$n > 500) {
            break;
        }
        if ($jetzt - (int) @filemtime($d) > 300) {
            @unlink($d);
        }
    }
}

/* ==================================================================
 * Hauptschleife
 * ================================================================== */

$gv_letzte_runde = 0;
$gv_letzte_suche = 0;
$gv_letzte_cloud = 0;
$gv_runden = 0;

do {
    $cfg = gv_config();
    $jetzt = time();

    /* Eine Schleife, ein Abbild, ein Versand (M6): faellt der Cloud-Takt in
     * dieselbe Schleife wie der LAN-Takt, gehen beide Ergebnisse gemeinsam
     * hinaus. Bis 0.9.22 fragte der Cloud-Takt die LAN-Leuchten ein zweites
     * Mal und sendete den vollen Satz ein zweites Mal. */
    $gv_treffer = array();
    $gv_gefragt = array();
    $gv_lan_lief = false;
    if ($jetzt - $gv_letzte_runde >= max(5, (int) $cfg['intervall'])) {
        $gv_letzte_runde = $jetzt;
        $gv_alle = gv_geraete();
        $gv_treffer = gv_runde($gv_horcher, $gv_alle, 2);
        $gv_gefragt = gv_nummern_der_art($gv_alle, 'lan');
        $gv_lan_lief = true;
    }

    if ((int) $cfg['suchtakt'] > 0 && $jetzt - $gv_letzte_suche >= (int) $cfg['suchtakt'] * 60) {
        $gv_letzte_suche = $jetzt;
        gv_befehl_ausfuehren(array('aktion' => 'suche'), $gv_horcher);
    }

    if (!empty($cfg['cloud_ein']) && $jetzt - $gv_letzte_cloud >= max(1, (int) $cfg['cloud_takt']) * 60) {
        $gv_letzte_cloud = $jetzt;
        if (gv_cloud_sperre_lesen() > $jetzt) {
            /* Ein uebersprungener Lauf ist KEIN Fehler: er ruehrt den Zustand
             * nicht an und sendet kein Lebenszeichen mit ok=0, sonst saehe ein
             * gestreckter Takt in Loxone aus wie ein Ausfall. */
            gv_log_gebremst('cloud_sperre', 'Cloud: Kontingent, bis '
                . date('H:i:s', gv_cloud_sperre_lesen()) . ' wird nicht abgerufen.', 600);
        } else {
            list($antwort, $meldung) = gv_cloud_geraete();
            if ($antwort === null) {
                gv_log_gebremst('cloud', 'Cloud: ' . $meldung);
            } else {
                gv_json_schreiben($gv_p['datadir'] . '/cloud.json',
                    array('ts' => time(), 'antwort' => $antwort));
            }
            /* Und der Zustand je Cloud-Geraet - der Grund, warum es diesen
             * Takt ueberhaupt gibt. */
            $gv_alle = gv_geraete();
            $gv_treffer = array_replace($gv_treffer, gv_runde_cloud($gv_alle));
            $gv_gefragt = $gv_gefragt + gv_nummern_der_art($gv_alle, 'cloud');
        }
    }

    if ($gv_lan_lief || $gv_gefragt) {
        gv_abbild_schreiben($gv_treffer, $gv_gefragt);
    }
    if ($gv_lan_lief) {
        gv_zustand_schreiben('');
    }

    gv_warteschlange($gv_horcher);

    if (++$gv_runden % 600 === 0) {
        gv_aufraeumen();
    }

    if ($gv_einmal) {
        break;
    }
    usleep(200000);
} while ($gv_laeuft);

fclose($gv_horcher);
gv_log('Dienst beendet.');
exit(0);
