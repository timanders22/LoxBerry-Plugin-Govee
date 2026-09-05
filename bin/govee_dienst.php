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
 *
 * Protokolliert wird ausschliesslich in die Datei. Das Startskript leitet
 * stdout ohnehin dorthin um - ein zweiter Kanal schriebe jede Zeile doppelt.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$gv_lib = null;
foreach (array(
    dirname(dirname(__DIR__)) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/gv_lib.php',
    dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/gv_lib.php',
    dirname(__DIR__) . '/webfrontend/html/gv_lib.php',
) as $gv_kandidat) {
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

$gv_bekannt = array('--selbsttest', '--einmal');
foreach (array_slice($argv, 1) as $gv_arg) {
    if (!in_array($gv_arg, $gv_bekannt, true)) {
        fwrite(STDERR, "Unbekannter Schalter: " . $gv_arg . "
"
             . "Aufruf: " . basename($argv[0]) . " [--selbsttest | --einmal]
"
             . "  ohne Schalter laeuft der Dienst dauerhaft;
"
             . "  gestartet und angehalten wird er ueber bin/dienst.sh.
");
        exit(2);
    }
}

$gv_einmal = in_array('--einmal', $argv, true);
$gv_p = gv_paths();
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
 * Einmal oeffnen und halten. Gelingt das nicht, laeuft schon ein zweiter
 * Dienst - dann wird beendet statt danebengefunkt. */
list($gv_horcher, $gv_meldung) = gv_antwortport_oeffnen();
if ($gv_horcher === null) {
    gv_log('ABBRUCH: ' . $gv_meldung);
    gv_zustand_schreiben($gv_meldung);
    exit(1);
}
gv_log('Antwortport ' . GV_PORT_ANTWORT . ' geoeffnet.');

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
        list($ok, $fehler) = gv_udp_senden($g['ip'], GV_PORT_BEFEHL, $frage);
        if (!$ok) {
            gv_log_gebremst('send_' . $nr, 'Geraet ' . $nr . ' (' . $g['name'] . '): ' . $fehler);
            continue;
        }
        $offen[$g['ip']] = $nr;
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

/** Das Abbild fuer Endpunkt und Oberflaeche schreiben und veroeffentlichen. */
function gv_abbild_schreiben($treffer)
{
    $p = gv_paths();
    $cfg = gv_config();
    $alt = gv_loxone();
    $altg = isset($alt['geraete']) && is_array($alt['geraete']) ? $alt['geraete'] : array();

    $neu = array();
    $ok_gesamt = 0;
    foreach (gv_geraete() as $nr => $g) {
        $z = isset($treffer[$nr]) ? $treffer[$nr] : null;
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
            $eintrag['ok'] = 0;
            /* Zaehler fehlgeschlagener Abrufe IN FOLGE. Er trennt "hakt kurz"
             * von "seit Stunden tot"; in Loxone will man die Meldung erst beim
             * zweiten oder dritten Fehlversuch. Zurueckgesetzt wird er nur,
             * wenn wirklich Werte kamen. */
            $eintrag['fehl'] = (isset($vorher['fehl']) ? (int) $vorher['fehl'] : 0) + 1;
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
     * beantwortet das Lebenszeichen in zustand.json, und zwar getrennt. */
    $ts_alt = isset($alt['ts']) ? (int) $alt['ts'] : 0;
    $fehler_folge = $ok_gesamt > 0
        ? 0
        : ((isset($alt['fehler_folge']) ? (int) $alt['fehler_folge'] : 0) + 1);
    gv_json_schreiben($p['datadir'] . '/loxone.json', array(
        'ts'           => $ok_gesamt > 0 ? time() : $ts_alt,
        'ok'           => $ok_gesamt > 0 ? 1 : 0,
        'fehler_folge' => $fehler_folge,
        'geraete'      => $neu,
    ));

    if (!empty($cfg['mqtt_ein'])) {
        /* Ein Herzschlag: 'lebt' und 'ts' gehen in JEDEM Durchlauf hinaus,
         * auch waehrend einer Stoerung. Ohne ihn hoert ein toter Dienst
         * einfach auf zu senden, die zuletzt gesendeten Werte bleiben stehen,
         * und in Loxone sieht ein toter Dienst aus wie ein ruhiges Haus. */
        $jetzt = time();
        $paare = array(
            'ok'           => $ok_gesamt > 0 ? 1 : 0,
            'geraete'      => count($neu),
            'ts'           => $jetzt,
            'lebt'         => 1,
            'fehler_folge' => $fehler_folge,
        );
        foreach ($neu as $nr => $e) {
            $pfx = 'geraet' . $nr . '/';
            $ts_g = isset($e['ts']) ? (int) $e['ts'] : 0;
            $paare[$pfx . 'name'] = $e['name'];
            $paare[$pfx . 'erreichbar'] = (int) $e['ok'];
            $paare[$pfx . 'alter'] = $ts_g > 0 ? max(0, $jetzt - $ts_g) : -1;
            $paare[$pfx . 'fehl'] = isset($e['fehl']) ? (int) $e['fehl'] : 0;
            foreach (array('an', 'hell', 'kelvin', 'r', 'g', 'b', 'hex') as $f) {
                if ($e[$f] !== null) {
                    $paare[$pfx . $f] = $e[$f];
                }
            }
        }
        gv_mqtt_senden($paare, trim((string) $cfg['mqtt_topic'], '/'));
    }
    return $ok_gesamt;
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
        $treffer = gv_runde($horcher, gv_geraete(), 2);
        $n = gv_abbild_schreiben($treffer);
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

    if ($jetzt - $gv_letzte_runde >= max(5, (int) $cfg['intervall'])) {
        $gv_letzte_runde = $jetzt;
        $treffer = gv_runde($gv_horcher, gv_geraete(), 2);
        gv_abbild_schreiben($treffer);
        gv_zustand_schreiben('');
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
            $gv_cloudtreffer = gv_runde_cloud(gv_geraete());
            if ($gv_cloudtreffer) {
                gv_abbild_schreiben(array_replace(gv_runde($gv_horcher, gv_geraete(), 2),
                                                  $gv_cloudtreffer));
            }
        }
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
