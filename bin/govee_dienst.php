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

$gv_einmal = in_array('--einmal', $argv, true);
$gv_p = gv_paths();
@mkdir($gv_p['datadir'] . '/befehle', 0775, true);
@mkdir($gv_p['datadir'] . '/antworten', 0775, true);

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

function gv_zustand_schreiben($fehler = '')
{
    gv_json_schreiben(gv_paths()['datadir'] . '/zustand.json', array(
        'ts'     => time(),
        'pid'    => getmypid(),
        'fehler' => (string) $fehler,
    ));
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
                'alter' => 0,
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
            $eintrag['alter'] = ((int) $eintrag['ts'] > 0) ? (time() - (int) $eintrag['ts']) : -1;
        }
        $neu[$nr] = $eintrag;
    }

    gv_json_schreiben($p['datadir'] . '/loxone.json', array(
        'ts'      => time(),
        'ok'      => $ok_gesamt > 0 ? 1 : 0,
        'geraete' => $neu,
    ));

    if (!empty($cfg['mqtt_ein'])) {
        $paare = array(
            'ok'      => $ok_gesamt > 0 ? 1 : 0,
            'geraete' => count($neu),
        );
        foreach ($neu as $nr => $e) {
            $pfx = 'geraet' . $nr . '/';
            $paare[$pfx . 'name'] = $e['name'];
            $paare[$pfx . 'erreichbar'] = (int) $e['ok'];
            $paare[$pfx . 'alter'] = (int) $e['alter'];
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

/**
 * Eine Segmentangabe der Form "1-3:ff0000,7:00ff00" auswerten.
 *
 * Pixel werden 1-basiert angegeben, weil der Anwender sie so zaehlt; intern
 * sind die Kennungen 0-basiert. Was nicht ins Muster passt, wird abgewiesen
 * und gemeldet - nie zurechtgebogen.
 *
 * Rueckgabe: array(Segmente|null, Meldung)
 */
function gv_segmente_lesen($text, $pixel)
{
    $segmente = array();
    $teile = explode(',', (string) $text);
    if (count($teile) > 20) {
        return array(null, 'Mehr als 20 Segmente sind nicht vorgesehen.');
    }
    foreach ($teile as $t) {
        $t = trim($t);
        if ($t === '') {
            continue;
        }
        if (!preg_match('/^([0-9]{1,3})(?:-([0-9]{1,3}))?:([0-9a-fA-F]{6})$/', $t, $m)) {
            return array(null, 'Der Abschnitt "' . $t . '" passt nicht ins Muster '
                . 'Pixel:RRGGBB oder VonPixel-BisPixel:RRGGBB.');
        }
        $von = (int) $m[1];
        /* Erst isset, dann vergleichen: nimmt die optionale Gruppe nicht
         * teil, fehlt $m[2] ganz - unter PHP 8 waere der Zugriff davor eine
         * Warnung. */
        $bis = (!isset($m[2]) || $m[2] === '') ? $von : (int) $m[2];
        if ($von < 1 || $bis < $von || $bis > $pixel) {
            return array(null, 'Der Abschnitt "' . $t . '" liegt ausserhalb von 1 bis ' . $pixel . '.');
        }
        $ids = array();
        for ($i = $von; $i <= $bis; $i++) {
            $ids[] = $i - 1;
        }
        $segmente[] = array('ids' => $ids, 'rgb' => array(
            hexdec(substr($m[3], 0, 2)), hexdec(substr($m[3], 2, 2)), hexdec(substr($m[3], 4, 2))));
    }
    if (!$segmente) {
        return array(null, 'Es wurde kein Segment angegeben.');
    }
    return array($segmente, '');
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
    $nr = isset($b['geraet']) ? (int) $b['geraet'] : 1;
    $g = gv_geraet($nr);
    if ($g === null) {
        return array(0, 'Geraet ' . $nr . ' ist nicht eingerichtet.');
    }

    /* Pflichtangaben je Aktion. Der Endpunkt prueft sie bereits, aber die
     * Warteschlange liegt im Dateisystem - eine unvollstaendige Datei darf
     * hier keine Warnung ausloesen, sondern muss eine Meldung ergeben. */
    $braucht = array(
        'hell'    => array('wert'),
        'kelvin'  => array('wert'),
        'balken'  => array('wert'),
        'farbe'   => array('r', 'g', 'b'),
        'szene'   => array('name'),
        'segment' => array('segmente'),
        'pt'      => array('cmd'),
    );
    if (isset($braucht[$aktion])) {
        foreach ($braucht[$aktion] as $pflicht) {
            if (!isset($b[$pflicht])) {
                return array(0, 'Dem Befehl ' . $aktion . ' fehlt die Angabe ' . $pflicht . '.');
            }
        }
    }

    /* --- Cloud-Geraete koennen nur die Grundbefehle --- */
    if ($g['art'] === 'cloud') {
        return gv_befehl_cloud($g, $aktion, $b);
    }

    $nachricht = null;
    switch ($aktion) {
        case 'ein':
            $nachricht = gv_cmd_schalten(1);
            break;
        case 'aus':
            $nachricht = gv_cmd_schalten(0);
            break;
        case 'hell':
            $nachricht = gv_cmd_helligkeit((int) $b['wert']);
            break;
        case 'kelvin':
            $k = (int) $b['wert'];
            if ($k < $g['kmin'] || $k > $g['kmax']) {
                return array(0, sprintf('%d K liegt ausserhalb des eingestellten Bereichs %d bis %d K.',
                    $k, $g['kmin'], $g['kmax']));
            }
            $nachricht = gv_cmd_kelvin($k);
            break;
        case 'farbe':
            $nachricht = gv_cmd_farbe((int) $b['r'], (int) $b['g'], (int) $b['b']);
            break;

        case 'szene':
            $szenen = gv_szenen();
            $s = isset($b['name']) ? (string) $b['name'] : '';
            if (!isset($szenen[$s])) {
                return array(0, 'Die Szene "' . $s . '" steht nicht im Katalog.');
            }
            $nachricht = gv_pt_nachricht($szenen[$s]['cmd']);
            break;

        case 'balken':
            if ($g['pixel'] < 1) {
                return array(0, 'Fuer ' . $g['name'] . ' ist keine Pixelzahl hinterlegt - '
                    . 'ohne sie laesst sich kein Balken bauen (Reiter Einstellungen).');
            }
            $rgb = array(0x64, 0x64, 0x00);
            if (isset($b['hex']) && preg_match('/^[0-9a-fA-F]{6}$/', (string) $b['hex'])) {
                $rgb = array(hexdec(substr($b['hex'], 0, 2)), hexdec(substr($b['hex'], 2, 2)),
                             hexdec(substr($b['hex'], 4, 2)));
            }
            $cmds = gv_pt_balken((int) $b['wert'], $rgb, $g['pixel']);
            if ($cmds === null) {
                return array(0, 'Der Balkenbefehl liess sich nicht bauen.');
            }
            $nachricht = gv_pt_nachricht($cmds);
            break;

        case 'segment':
            if ($g['pixel'] < 1) {
                return array(0, 'Fuer ' . $g['name'] . ' ist keine Pixelzahl hinterlegt.');
            }
            list($segmente, $meldung) = gv_segmente_lesen(isset($b['segmente']) ? $b['segmente'] : '',
                                                          $g['pixel']);
            if ($segmente === null) {
                return array(0, $meldung);
            }
            /* Zweites Verfahren: Segmente als Bitmaske (H70C4 und Verwandte).
             * Dort werden die Segmente ab 1 gezaehlt, nicht ab 0 - deshalb
             * die Kennungen um eins zurueckdrehen. */
            $verfahren = isset($b['verfahren']) ? (string) $b['verfahren'] : 'graffiti';
            if ($verfahren === 'maske') {
                $gruppen = array();
                foreach ($segmente as $s) {
                    $nummern = array();
                    foreach ($s['ids'] as $id) {
                        $nummern[] = $id + 1;
                    }
                    $gruppen[] = array('nummern' => $nummern, 'rgb' => $s['rgb']);
                }
                $cmds = gv_pt_segment_maske($gruppen,
                    isset($b['hgint']) ? (int) $b['hgint'] : null);
                if ($cmds === null) {
                    return array(0, 'Das Maskenverfahren fasst nur die Segmente 1 bis 16.');
                }
                $nachricht = gv_pt_nachricht($cmds);
                break;
            }
            $hg = array(0, 0, 0);
            if (isset($b['hg']) && preg_match('/^[0-9a-fA-F]{6}$/', (string) $b['hg'])) {
                $hg = array(hexdec(substr($b['hg'], 0, 2)), hexdec(substr($b['hg'], 2, 2)),
                            hexdec(substr($b['hg'], 4, 2)));
            }
            $cmds = gv_pt_graffiti(
                isset($b['bewegung']) ? (int) $b['bewegung'] : 0x09,
                isset($b['geschw']) ? (int) $b['geschw'] : 0,
                isset($b['hgint']) ? (int) $b['hgint'] : 0,
                $hg, $segmente);
            if ($cmds === null) {
                return array(0, 'Der Segmentbefehl passt nicht in die zulaessige Paketzahl.');
            }
            $nachricht = gv_pt_nachricht($cmds);
            break;

        case 'musik':
            $cmds = gv_pt_musik(isset($b['gruppe']) ? (int) $b['gruppe'] : 0x0f,
                                isset($b['art']) ? (int) $b['art'] : 0,
                                isset($b['sens']) ? (int) $b['sens'] : 0x64);
            if ($cmds === null) {
                return array(0, 'Unbekannte Befehlsgruppe fuer den Musikbetrieb.');
            }
            $nachricht = gv_pt_nachricht($cmds);
            break;

        case 'pt':
            if (empty($cfg['pt_frei'])) {
                return array(0, 'Rohe ptReal-Befehle sind gesperrt (Reiter Einstellungen).');
            }
            $roh = isset($b['cmd']) ? (array) $b['cmd'] : array();
            list($geprueft, $meldung) = gv_pt_pruefen($roh);
            if ($geprueft === null) {
                return array(0, $meldung);
            }
            $nachricht = gv_pt_nachricht($geprueft);
            break;

        default:
            return array(0, 'Unbekannte Aktion: ' . $aktion);
    }

    list($ok, $fehler) = gv_udp_senden($g['ip'], GV_PORT_BEFEHL, $nachricht);
    if (!$ok) {
        return array(0, $fehler);
    }
    /* Gesendet ist nicht bestaetigt: UDP kennt keine Quittung, und die
     * Govee-Leuchten antworten auf Steuerbefehle nicht. Genau das steht in
     * der Meldung - ein "erledigt", das niemand geprueft hat, waere gelogen. */
    return array(1, 'An ' . $g['name'] . ' (' . $g['ip'] . ') gesendet. '
        . 'UDP quittiert nicht; ob es angekommen ist, zeigt die naechste Statusabfrage.');
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
        list($antwort, $meldung) = gv_cloud_geraete();
        if ($antwort === null) {
            gv_log_gebremst('cloud', 'Cloud: ' . $meldung);
        } else {
            gv_json_schreiben($gv_p['datadir'] . '/cloud.json',
                array('ts' => time(), 'antwort' => $antwort));
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
