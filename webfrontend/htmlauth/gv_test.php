<?php
/**
 * Govee - die Aktionen des Reiters Test
 *
 * Die Selbstpruefung beantwortet OHNE Loxone, ob die Einrichtung traegt. Was
 * sich nur mit Geraet pruefen liesse, wird als solches benannt statt geraten.
 * Jedes Kreuz nennt die Abhilfe mit - eine Pruefzeile, die nur "nein" sagt,
 * hilft niemandem.
 */

function gv_pruefzeile($stand, $frage, $antwort)
{
    return array('stand' => $stand, 'frage' => $frage, 'antwort' => $antwort);
}

function gv_pruefungen()
{
    $p = gv_paths();
    $cfg = gv_config();
    $geheim = gv_geheim();
    $geraete = gv_geraete();
    $werte = gv_werte();
    $zeilen = array();

    $pid = gv_dienst_pid();
    $zeilen[] = gv_pruefzeile($pid > 0 ? 1 : 0, gv_t('TEST.F_DIENST'),
        $pid > 0 ? gv_t('TEST.A_DIENST_LAEUFT') . ' ' . $pid
                 : (gv_dienst_soll() ? gv_t('TEST.A_DIENST_SOLL_TOT') : gv_t('TEST.A_DIENST_GESTOPPT')));

    /* Die Prozessnummer beantwortet nicht, ob der Dienst noch ARBEITET - ein
     * Prozess kann dastehen und nichts mehr tun. Das Lebenszeichen kommt aus
     * zustand.json, das der Dienst in jeder Runde neu schreibt. */
    $lz = gv_dienst_lebenszeichen();
    $grenze = gv_altersgrenze($cfg);
    if ($pid === 0) {
        /* Ueber den Herzschlag eines Dienstes zu urteilen, der gar nicht
         * laeuft, ergibt ein Kreuz, das nichts bedeutet - den Grund nennt
         * schon die Zeile darueber. */
        $zeilen[] = gv_pruefzeile(-1, gv_t('TEST.F_LEBENSZEICHEN'),
            gv_t('TEST.A_LEBENSZEICHEN_KEIN_DIENST'));
    } elseif ($lz < 0) {
        $zeilen[] = gv_pruefzeile(0, gv_t('TEST.F_LEBENSZEICHEN'),
            gv_t('TEST.A_LEBENSZEICHEN_NIE'));
    } elseif ($lz <= $grenze) {
        $zeilen[] = gv_pruefzeile(1, gv_t('TEST.F_LEBENSZEICHEN'),
            sprintf(gv_t('TEST.A_LEBENSZEICHEN_OK'), (int) $lz));
    } else {
        $zeilen[] = gv_pruefzeile(0, gv_t('TEST.F_LEBENSZEICHEN'),
            sprintf(gv_t('TEST.A_LEBENSZEICHEN_ALT'), (int) $lz, (int) $grenze));
    }

    $zeilen[] = gv_pruefzeile(count($geraete) > 0 ? 1 : 0, gv_t('TEST.F_GERAETE'),
        count($geraete) > 0 ? sprintf(gv_t('TEST.A_GERAETE'), count($geraete))
                            : gv_t('TEST.A_KEINE_GERAETE'));

    /* Der Antwortport ist der Dreh- und Angelpunkt. Ist er frei, obwohl der
     * Dienst laufen soll, hoert niemand zu - dann bleibt jede Statusabfrage
     * ohne Ergebnis, und zwar lautlos. */
    if ($pid > 0) {
        $zeilen[] = gv_pruefzeile(1, gv_t('TEST.F_PORT'), sprintf(gv_t('TEST.A_PORT_DIENST'), GV_PORT_ANTWORT));
    } else {
        list($h, $meldung) = gv_antwortport_oeffnen();
        if ($h !== null) {
            fclose($h);
            $zeilen[] = gv_pruefzeile(-1, gv_t('TEST.F_PORT'), sprintf(gv_t('TEST.A_PORT_FREI'), GV_PORT_ANTWORT));
        } else {
            $zeilen[] = gv_pruefzeile(0, gv_t('TEST.F_PORT'), gv_e($meldung));
        }
    }

    /* Der Nachbau der ptReal-Befehle - ohne Netz und ohne Geraet pruefbar. */
    list($anzahl, $fehl, $unused) = gv_selbsttest();
    $zeilen[] = gv_pruefzeile($fehl === 0 ? 1 : 0, gv_t('TEST.F_NACHBAU'),
        $fehl === 0 ? sprintf(gv_t('TEST.A_NACHBAU_OK'), $anzahl)
                    : sprintf(gv_t('TEST.A_NACHBAU_FEHL'), $fehl, $anzahl));

    /* Je Geraet: hat es geantwortet? */
    foreach ($werte as $nr => $w) {
        $zeilen[] = gv_pruefzeile($w['ok'] ? 1 : 0,
            gv_e($w['name']) . ' <span class="sm-mono">' . gv_e($w['ip'] !== '' ? $w['ip'] : $w['art']) . '</span>',
            $w['ok'] ? sprintf(gv_t('TEST.A_GERAET_OK'),
                          $w['an'] === null ? '?' : ($w['an'] ? gv_t('ALLG.EIN') : gv_t('ALLG.AUS')),
                          $w['hell'] === null ? '?' : (int) $w['hell'])
                     : ($w['alter'] < 0 ? gv_t('TEST.A_GERAET_NIE')
                                        : sprintf(gv_t('TEST.A_GERAET_ALT'), (int) $w['alter'])));
    }

    /* Pixelzahl: ohne sie sind Balken und Segmente gesperrt. */
    $ohne = array();
    foreach ($geraete as $g) {
        if ($g['art'] === 'lan' && $g['pixel'] < 1) {
            $ohne[] = $g['name'];
        }
    }
    if (!$geraete) {
        $zeilen[] = gv_pruefzeile(-1, gv_t('TEST.F_PIXEL'), gv_t('TEST.A_PIXEL_KEINE'));
    } elseif ($ohne) {
        $zeilen[] = gv_pruefzeile(-1, gv_t('TEST.F_PIXEL'),
            sprintf(gv_t('TEST.A_PIXEL_OHNE'), gv_e(implode(', ', $ohne))));
    } else {
        $zeilen[] = gv_pruefzeile(1, gv_t('TEST.F_PIXEL'), gv_t('TEST.A_PIXEL_ALLE'));
    }

    /* Die Lage der Konfiguration. gv_config() ist oben schon gelaufen, die
     * Lage steht also fest. Vier Zustaende, vier Saetze - ein Zustand ohne
     * Satz ist einer, den der Anwender nie erfaehrt. */
    $lage = gv_config_lage();
    if ($lage === 'kaputt') {
        $zeilen[] = gv_pruefzeile(0, gv_t('TEST.F_KONFIG'),
            sprintf(gv_t('TEST.A_KONFIG_KAPUTT'), gv_e(basename($p['config']) . '.kaputt')));
    } elseif ($lage === 'zweitschrift') {
        $zeilen[] = gv_pruefzeile(0, gv_t('TEST.F_KONFIG'), gv_t('TEST.A_KONFIG_ZWEITSCHRIFT'));
    } elseif ($lage === 'leer') {
        $zeilen[] = gv_pruefzeile(-1, gv_t('TEST.F_KONFIG'), gv_t('TEST.A_KONFIG_LEER'));
    } else {
        $zeilen[] = gv_pruefzeile(1, gv_t('TEST.F_KONFIG'),
            is_file($p['sicherung'])
                ? sprintf(gv_t('TEST.A_KONFIG_OK'), date('d.m.Y H:i', (int) @filemtime($p['sicherung'])))
                : gv_t('TEST.A_KONFIG_OHNE_ZWEITSCHRIFT'));
    }

    /* Eigene Szenen. Ueber eine leere Menge wird nicht geurteilt: "alle 0
     * von 0 sind in Ordnung" ist kein Haken. Gezaehlt wird gegen die
     * Rohliste, damit eine abgewiesene Zeile auffaellt statt zu verschwinden. */
    $roh_szenen = isset($cfg['szenen']) && is_array($cfg['szenen']) ? $cfg['szenen'] : array();
    $gute_szenen = gv_szenen_eigen($cfg);
    if (!$roh_szenen) {
        $zeilen[] = gv_pruefzeile(-1, gv_t('TEST.F_SZENEN'), gv_t('TEST.A_SZENEN_KEINE'));
    } elseif (count($gute_szenen) === count($roh_szenen)) {
        $zeilen[] = gv_pruefzeile(1, gv_t('TEST.F_SZENEN'),
            sprintf(gv_t('TEST.A_SZENEN_OK'), count($gute_szenen)));
    } else {
        $zeilen[] = gv_pruefzeile(0, gv_t('TEST.F_SZENEN'),
            sprintf(gv_t('TEST.A_SZENEN_FEHL'),
                    count($roh_szenen) - count($gute_szenen), count($roh_szenen)));
    }

    $zu = gv_zustand();
    if (!empty($zu['fehler'])) {
        $zeilen[] = gv_pruefzeile(0, gv_t('TEST.F_LETZTER_FEHLER'),
            (!empty($zu['fehler_ts'])
                ? '<b>' . gv_e(date('d.m.Y H:i:s', (int) $zu['fehler_ts'])) . '</b> &middot; ' : '')
            . gv_e($zu['fehler']));
    }

    $m = gv_mqtt_zustand();
    if (!$m['gefunden']) {
        $zeilen[] = gv_pruefzeile(0, gv_t('TEST.F_MQTT'), gv_t('TEST.A_MQTT_NICHT_GEFUNDEN'));
    } elseif ($m['autostart']) {
        $zeilen[] = gv_pruefzeile(1, gv_t('TEST.F_MQTT'),
            gv_e($m['broker']) . ':' . gv_e($m['brokerport']) . ' (UDP ' . (int) $m['udpport'] . ')');
    } else {
        $zeilen[] = gv_pruefzeile(0, gv_t('TEST.F_MQTT'), gv_t('TEST.A_MQTT_AUS'));
    }

    /* Cloud: nur die FORM des Schluessels beurteilen, nie seinen Wert zeigen. */
    if (empty($cfg['cloud_ein'])) {
        $zeilen[] = gv_pruefzeile(-1, gv_t('TEST.F_CLOUD'), gv_t('TEST.A_CLOUD_AUS'));
    } else {
        $key = trim((string) $geheim['cloud_key']);
        if ($key === '') {
            $zeilen[] = gv_pruefzeile(0, gv_t('TEST.F_CLOUD'), gv_t('TEST.A_CLOUD_KEIN_KEY'));
        } elseif (!function_exists('curl_init')) {
            $zeilen[] = gv_pruefzeile(0, gv_t('TEST.F_CLOUD'), gv_t('TEST.A_CLOUD_KEIN_CURL'));
        } elseif (gv_cloud_sperre_lesen() > time()) {
            /* Ein Kontingent ist kein Fehler des Anwenders - aber er muss
             * erfahren, warum gerade nichts kommt. */
            $zeilen[] = gv_pruefzeile(-1, gv_t('TEST.F_CLOUD'),
                sprintf(gv_t('TEST.A_CLOUD_SPERRE'),
                        gv_e(date('H:i:s', gv_cloud_sperre_lesen())),
                        gv_cloud_sperre_lesen() - time()));
        } else {
            list($cl, $cts) = gv_cloud_liste();
            $zeilen[] = gv_pruefzeile(1, gv_t('TEST.F_CLOUD'),
                sprintf(gv_t('TEST.A_CLOUD_KEY'), strlen($key))
                . ($cts > 0 ? ' ' . sprintf(gv_t('TEST.A_CLOUD_LISTE'), count($cl),
                                            gv_e(date('d.m.Y H:i', $cts))) : ''));
        }
    }

    $zeilen[] = gv_pruefzeile(!empty($cfg['steuerung_ein']) ? 1 : -1, gv_t('TEST.F_STEUERUNG'),
        !empty($cfg['steuerung_ein']) ? gv_t('TEST.A_STEUERUNG_EIN') : gv_t('TEST.A_STEUERUNG_AUS'));

    $zeilen[] = gv_pruefzeile(!empty($cfg['pt_frei']) ? -1 : 1, gv_t('TEST.F_PTFREI'),
        !empty($cfg['pt_frei']) ? gv_t('TEST.A_PTFREI_EIN') : gv_t('TEST.A_PTFREI_AUS'));

    /* Traegt jedes Formular das Merkmal gegen fremde Absender?
     *
     * Gezaehlt wird in der eigenen Datei, nicht im Kopf: ein neues Formular
     * ohne das Feld faellt sonst erst dem auf, der darauf klickt - und der
     * bekommt dann eine Beanstandung, die er sich nicht erklaeren kann.
     * Die Zahl der angesehenen Stellen steht in der Antwort, damit eine Null
     * nicht wie "in Ordnung" aussieht. */
    $eigene = __DIR__ . '/index.php';
    $quelle = is_file($eigene) ? (string) @file_get_contents($eigene) : '';
    if ($quelle === '') {
        $zeilen[] = gv_pruefzeile(-1, gv_t('TEST.F_FORMULAR'), gv_t('TEST.A_FORMULAR_UNBEKANNT'));
    } else {
        $formulare = substr_count($quelle, '<form action="index.php" method="post"');
        $merkmale = substr_count($quelle, 'name="fmt" value=');
        if ($formulare === 0) {
            $zeilen[] = gv_pruefzeile(-1, gv_t('TEST.F_FORMULAR'), gv_t('TEST.A_FORMULAR_UNBEKANNT'));
        } elseif ($formulare === $merkmale) {
            $zeilen[] = gv_pruefzeile(1, gv_t('TEST.F_FORMULAR'),
                sprintf(gv_t('TEST.A_FORMULAR_OK'), $formulare));
        } else {
            $zeilen[] = gv_pruefzeile(0, gv_t('TEST.F_FORMULAR'),
                sprintf(gv_t('TEST.A_FORMULAR_FEHL'), $formulare - $merkmale, $formulare));
        }
    }

    $zeilen[] = gv_pruefzeile_reiter();
    $zeilen[] = gv_pruefzeile_themen();

    /* Der eigene Endpunkt. Drei Ausgaenge sind Pflicht: geantwortet und
     * plausibel, geantwortet und falsch, NICHT FESTSTELLBAR - "ich kann es
     * nicht messen" darf nicht wie "in Ordnung" aussehen. */
    $probe = gv_endpunkt_probe();
    if ($probe['lage'] === 'gut') {
        $zeilen[] = gv_pruefzeile(1, gv_t('TEST.F_ENDPUNKT'),
            sprintf(gv_t('TEST.A_ENDPUNKT_OK'), (int) $probe['alter']));
    } elseif ($probe['lage'] === 'falsch') {
        $zeilen[] = gv_pruefzeile(0, gv_t('TEST.F_ENDPUNKT'),
            sprintf(gv_t('TEST.A_ENDPUNKT_FALSCH'), (int) $probe['code'],
                    gv_e(substr((string) $probe['text'], 0, 120))));
    } else {
        $zeilen[] = gv_pruefzeile(-1, gv_t('TEST.F_ENDPUNKT'),
            sprintf(gv_t('TEST.A_ENDPUNKT_UNBEKANNT'), gv_e((string) $probe['text'])));
    }

    /* Die erzeugten Loxone-Vorlagen gegen den Parser halten. Der Anwender
     * merkte eine kaputte Vorlage sonst erst in Loxone Config - und dort
     * sucht er den Fehler bei sich. */
    $zeilen[] = gv_pruefzeile_vorlagen();

    return $zeilen;
}

/**
 * Reiterleiste, Bereiche und Positivliste gegeneinander zaehlen.
 *
 * Alle drei sind seit dieser Fassung ausgeschrieben, damit ein Prueflauf von
 * aussen sie sieht. Der Preis dafuer ist, dass sie auseinanderlaufen koennen -
 * und genau das misst diese Zeile. Sie liest die eigene Oberflaechendatei.
 */
function gv_pruefzeile_reiter()
{
    $eigene = __DIR__ . '/index.php';
    $quelle = is_file($eigene) ? (string) @file_get_contents($eigene) : '';
    if ($quelle === '') {
        return gv_pruefzeile(-1, gv_t('TEST.F_REITER'), gv_t('TEST.A_REITER_UNBEKANNT'));
    }
    preg_match_all('/data-ziel="tab-([a-z]+)"/', $quelle, $ml);
    preg_match_all('/class="sm-seite<\?= \$gv_tab === \x27tab-([a-z]+)\x27/', $quelle, $mb);
    preg_match('#\$gv_muster = \x27/\^tab-\(([a-z|]+)\)\$/\x27;#', $quelle, $mp);
    $leiste = $ml[1];
    $bereiche = $mb[1];
    $liste = isset($mp[1]) ? explode('|', $mp[1]) : array();
    sort($leiste);
    sort($bereiche);
    sort($liste);
    if (!$leiste || !$bereiche || !$liste) {
        return gv_pruefzeile(0, gv_t('TEST.F_REITER'),
            sprintf(gv_t('TEST.A_REITER_LEER'), count($leiste), count($bereiche), count($liste)));
    }
    if ($leiste !== $bereiche || $leiste !== $liste) {
        return gv_pruefzeile(0, gv_t('TEST.F_REITER'),
            sprintf(gv_t('TEST.A_REITER_ABWEICHUNG'),
                gv_e(implode(', ', $leiste)), gv_e(implode(', ', $bereiche)),
                gv_e(implode(', ', $liste))));
    }
    return gv_pruefzeile(1, gv_t('TEST.F_REITER'),
        sprintf(gv_t('TEST.A_REITER_OK'), count($leiste), gv_e(implode(', ', $leiste))));
}

/**
 * Die Themenliste gegen den Sendecode halten.
 *
 * gv_mqtt_themen() ist die Anleitung im Reiter MQTT, veroeffentlicht wird an
 * ganz anderer Stelle im Dienst. Nichts mass die beiden gegeneinander - und
 * genau so ist beim Waermepumpen-Plugin ALTER aus MQTT verschwunden, waehrend
 * die Tabelle es weiter auffuehrte.
 */
function gv_pruefzeile_themen()
{
    $p = gv_paths();
    $dienst = $p['bindir'] . '/govee_dienst.php';
    if (!is_file($dienst)) {
        /* Aus dem entpackten Archiv heraus liegt er woanders. */
        $dienst = dirname(dirname(__DIR__)) . '/bin/govee_dienst.php';
    }
    $quelle = is_file($dienst) ? (string) @file_get_contents($dienst) : '';
    if ($quelle === '') {
        return gv_pruefzeile(-1, gv_t('TEST.F_THEMEN'), gv_t('TEST.A_THEMEN_UNBEKANNT'));
    }
    $fehlt = array();
    foreach (array_keys(gv_mqtt_themen()) as $thema) {
        /* geraetN/x wird im Code als $pfx . 'x' gebaut. */
        $suche = (strpos($thema, 'geraetN/') === 0)
            ? "'" . substr($thema, 8) . "'"
            : "'" . $thema . "'";
        if (strpos($quelle, $suche) === false) {
            $fehlt[] = $thema;
        }
    }
    $anzahl = count(gv_mqtt_themen());
    if ($fehlt) {
        return gv_pruefzeile(0, gv_t('TEST.F_THEMEN'),
            sprintf(gv_t('TEST.A_THEMEN_FEHL'), gv_e(implode(', ', $fehlt)), $anzahl));
    }
    return gv_pruefzeile(1, gv_t('TEST.F_THEMEN'), sprintf(gv_t('TEST.A_THEMEN_OK'), $anzahl));
}

/**
 * Den EIGENEN Endpunkt wirklich aufrufen.
 *
 * Alle uebrigen Pruefzeilen sehen sich Dateien an. Nur diese eine spricht die
 * Stelle an, die spaeter der Miniserver anspricht - und nur sie findet die
 * Klasse, bei der html/ und htmlauth/ installiert in getrennten Baeumen
 * liegen und der Endpunkt mit HTTP 500 antwortet, ohne dass es jemand merkt.
 *
 * Serverseitig ist 127.0.0.1 dabei die RICHTIGE Adresse - das widerspricht
 * nicht der Regel "ein Knopf auf 127.0.0.1 kann nie funktionieren", die fuer
 * einen Link gilt, den ein Mensch anklickt.
 *
 * Der Aufruf kostet etwas und wird deshalb zwischengespeichert: alle Reiter
 * werden bei jedem Seitenaufbau mitgerendert, sonst riefe sich der Webserver
 * bei jedem Klick selbst auf.
 */
function gv_endpunkt_probe($erzwingen = false, $hoechstalter = 300)
{
    $p = gv_paths();
    $datei = $p['datadir'] . '/endpunkt_probe.json';
    $alt = gv_json_lesen($datei);
    if (!$erzwingen && isset($alt['ts']) && (time() - (int) $alt['ts']) < $hoechstalter) {
        $alt['alter'] = time() - (int) $alt['ts'];
        return $alt;
    }
    $geraete = gv_geraete();
    $nr = $geraete ? (int) array_keys($geraete)[0] : 1;
    $adresse = 'http://127.0.0.1/plugins/' . $p['plugin'] . '/index.php?token='
             . rawurlencode(gv_token()) . '&aktion=status&geraet=' . $nr;
    $erg = array('ts' => time(), 'alter' => 0, 'lage' => 'unbekannt', 'code' => 0, 'text' => '');

    if (function_exists('curl_init')) {
        $ch = curl_init($adresse);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        $antwort = curl_exec($ch);
        $erg['code'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fehler = curl_error($ch);
        curl_close($ch);
        if ($antwort === false) {
            $erg['lage'] = 'unbekannt';
            $erg['text'] = $fehler;
        } else {
            $erg['text'] = substr((string) $antwort, 0, 300);
        }
    } elseif (ini_get('allow_url_fopen')) {
        $kontext = stream_context_create(array('http' => array('timeout' => 3)));
        $antwort = @file_get_contents($adresse, false, $kontext);
        $erg['text'] = ($antwort === false) ? '' : substr((string) $antwort, 0, 300);
        $erg['code'] = ($antwort === false) ? 0 : 200;
        if ($antwort === false) {
            $erg['lage'] = 'unbekannt';
        }
    } else {
        $erg['lage'] = 'unbekannt';
        $erg['text'] = 'weder curl noch allow_url_fopen';
    }

    if ($erg['lage'] !== 'unbekannt') {
        if ($erg['code'] === 200 && strpos($erg['text'], 'GOVEE;') === 0) {
            $erg['lage'] = 'gut';
        } elseif ($erg['code'] === 0) {
            $erg['lage'] = 'unbekannt';
        } else {
            $erg['lage'] = 'falsch';
        }
    }
    gv_json_schreiben($datei, $erg);
    return $erg;
}

/** Jede erzeugbare Vorlage einmal durch simplexml_load_string schicken. */
function gv_pruefzeile_vorlagen()
{
    $geraete = gv_geraete();
    if (!$geraete) {
        return gv_pruefzeile(-1, gv_t('TEST.F_XML'), gv_t('TEST.A_XML_KEINE'));
    }
    $kaputt = array();
    $n = 0;
    $vorher = libxml_use_internal_errors(true);
    foreach (array_keys($geraete) as $nr) {
        foreach (array('gv_vorlage_ausgang', 'gv_vorlage_szenen', 'gv_vorlage_eingang',
                       'gv_vorlage_lox') as $f) {   // die Sammelvorlage danach, sie kennt keine Nummer
            list($name, $inhalt) = $f($nr);
            if ($inhalt === '') {
                continue;   // fuer dieses Geraet nicht vorgesehen
            }
            $n++;
            libxml_clear_errors();
            if (simplexml_load_string($inhalt) === false) {
                $kaputt[] = $name;
            }
        }
    }
    /* Die Sammelvorlage gibt es nur einmal, nicht je Geraet. */
    list($sname, $sinhalt) = gv_vorlage_eingang_alle();
    if ($sinhalt !== '') {
        $n++;
        libxml_clear_errors();
        if (simplexml_load_string($sinhalt) === false) {
            $kaputt[] = $sname;
        }
    }
    libxml_clear_errors();
    libxml_use_internal_errors($vorher);
    if ($kaputt) {
        return gv_pruefzeile(0, gv_t('TEST.F_XML'),
            sprintf(gv_t('TEST.A_XML_KAPUTT'), gv_e(implode(', ', $kaputt))));
    }
    return gv_pruefzeile(1, gv_t('TEST.F_XML'), sprintf(gv_t('TEST.A_XML_OK'), $n));
}

/** Ausgabe von govee_dienst.php --selbsttest, im Prozess statt per exec. */
function gv_selbsttest_ausgabe()
{
    list($anzahl, $fehl, $text) = gv_selbsttest();
    return $text;
}

/**
 * Fuehrt eine Aktion des Reiters Test aus.
 * Rueckgabe: array(stand, Meldung) - stand wie bei gv_befehl_absetzen.
 */
function gv_test_aktion($aktion)
{
    $nr = isset($_POST['test_geraet']) ? (string) $_POST['test_geraet'] : '1';
    /* Als Ganzzahl weitergeben: "01" bestuende die Pruefung, der Dienst
     * vergleicht aber mit Zahlen. */
    $nr = (string) (int) $nr;
    if (!preg_match('/^[0-9]{1,2}$/', $nr)) {
        return array(0, gv_t('TEST.M_GERAET_UNGUELTIG'));
    }
    $hol = function ($feld) {
        return isset($_POST[$feld]) ? trim((string) $_POST[$feld]) : '';
    };

    switch ($aktion) {
        case 'mitschnitt':
            $cfg = gv_config();
            $dauer = (int) $hol('test_mitschnitt');
            if ($dauer < 10 || $dauer > 600) {
                return array(0, gv_t('TEST.M_MITSCHNITT_UNGUELTIG'));
            }
            $cfg['mitschnitt_bis'] = time() + $dauer;
            if (!gv_config_speichern($cfg)) {
                return array(0, gv_t('TEST.M_MITSCHNITT_FEHL'));
            }
            return array(1, sprintf(gv_t('TEST.M_MITSCHNITT_AN'), $dauer));

        case 'mitschnitt_aus':
            $cfg = gv_config();
            $cfg['mitschnitt_bis'] = 0;
            gv_config_speichern($cfg);
            return array(1, gv_t('TEST.M_MITSCHNITT_AUS'));

        case 'endpunkt':
            $probe = gv_endpunkt_probe(true);
            if ($probe['lage'] === 'gut') {
                return array(1, sprintf(gv_t('TEST.M_ENDPUNKT_OK'), gv_e($probe['text'])));
            }
            return array(0, sprintf(gv_t('TEST.M_ENDPUNKT_FEHL'),
                (int) $probe['code'], gv_e((string) $probe['text'])));

        case 'suche':
            /* Laeuft der Dienst, hat er den Antwortport - dann muss auch er
             * suchen. Laeuft er nicht, sucht die Oberflaeche selbst. */
            if (gv_dienst_pid() > 0) {
                return gv_befehl_absetzen(array('aktion' => 'suche'), 8);
            }
            list($liste, $meldung) = gv_suche(3);
            if ($meldung !== '') {
                return array(0, $meldung);
            }
            gv_json_schreiben(gv_paths()['datadir'] . '/gefunden.json',
                array('ts' => time(), 'liste' => $liste));
            return array(count($liste) > 0 ? 1 : 0, count($liste) > 0
                ? sprintf(gv_t('TEST.M_GEFUNDEN'), count($liste))
                : gv_t('TEST.M_NICHTS_GEFUNDEN'));

        case 'abruf':
            if (gv_dienst_pid() > 0) {
                return gv_befehl_absetzen(array('aktion' => 'abruf'), 8);
            }
            $g = gv_geraet((int) $nr);
            if ($g === null) {
                return array(0, gv_t('TEST.M_GERAET_UNBEKANNT'));
            }
            if ($g['art'] !== 'lan') {
                return array(0, gv_t('TEST.M_NUR_LAN'));
            }
            list($werte, $meldung) = gv_status_abfragen($g['ip'], 2);
            if ($werte === null) {
                return array(0, $meldung);
            }
            return array(1, sprintf(gv_t('TEST.M_ABRUF'), gv_e($g['name']),
                $werte['an'] === null ? '?' : (int) $werte['an'],
                $werte['hell'] === null ? '?' : (int) $werte['hell'],
                $werte['kelvin'] === null ? '?' : (int) $werte['kelvin']));

        case 'ein':
        case 'aus':
            return gv_befehl_absetzen(array('aktion' => $aktion, 'geraet' => (int) $nr));

        case 'hell':
            $w = $hol('test_hell');
            if (!preg_match('/^[0-9]{1,3}$/', $w) || (int) $w < 1 || (int) $w > 100) {
                return array(0, gv_t('TEST.M_HELL_UNGUELTIG'));
            }
            return gv_befehl_absetzen(array('aktion' => 'hell', 'geraet' => (int) $nr, 'wert' => (int) $w));

        case 'kelvin':
            $w = $hol('test_kelvin');
            if (!preg_match('/^[0-9]{4,5}$/', $w)) {
                return array(0, gv_t('TEST.M_KELVIN_UNGUELTIG'));
            }
            return gv_befehl_absetzen(array('aktion' => 'kelvin', 'geraet' => (int) $nr, 'wert' => (int) $w));

        case 'farbe':
            $h = ltrim($hol('test_hex'), '#');
            if (!preg_match('/^[0-9a-fA-F]{6}$/', $h)) {
                return array(0, gv_t('TEST.M_HEX_UNGUELTIG'));
            }
            return gv_befehl_absetzen(array('aktion' => 'farbe', 'geraet' => (int) $nr,
                'r' => hexdec(substr($h, 0, 2)), 'g' => hexdec(substr($h, 2, 2)),
                'b' => hexdec(substr($h, 4, 2))));

        case 'szene':
            $s = $hol('test_szene');
            /* isset() nimmt nur Variablen, nicht das Ergebnis eines Aufrufs -
             * isset(gv_szenen()[$s]) waere ein Fehler beim Uebersetzen. */
            $katalog = gv_szenen_alle();
            if (!isset($katalog[$s])) {
                return array(0, gv_t('TEST.M_SZENE_UNBEKANNT'));
            }
            return gv_befehl_absetzen(array('aktion' => 'szene', 'geraet' => (int) $nr, 'name' => $s));

        case 'szenenr':
            $w = $hol('test_szenenr');
            if (!preg_match('/^[0-9]{1,3}$/', $w) || (int) $w > 255) {
                return array(0, gv_t('TEST.M_SZENENR_UNGUELTIG'));
            }
            return gv_befehl_absetzen(array('aktion' => 'szene', 'geraet' => (int) $nr,
                                            'nr' => (int) $w));

        case 'musik':
            $gruppe = (int) $hol('test_musik_gruppe');
            $gruppen = gv_musikgruppen();
            if (!isset($gruppen[$gruppe])) {
                return array(0, gv_t('TEST.M_MUSIK_GRUPPE'));
            }
            $art = $hol('test_musik_art');
            if (!preg_match('/^[0-9]{1,3}$/', $art) || (int) $art > 255) {
                return array(0, gv_t('TEST.M_MUSIK_ART'));
            }
            $sens = $hol('test_musik_sens');
            return gv_befehl_absetzen(array('aktion' => 'musik', 'geraet' => (int) $nr,
                'gruppe' => $gruppe, 'art' => (int) $art,
                'sens' => preg_match('/^[0-9]{1,3}$/', $sens) ? (int) $sens : 100));

        case 'balken':
            $w = $hol('test_balken');
            if (!preg_match('/^[0-9]{1,3}$/', $w) || (int) $w > 100) {
                return array(0, gv_t('TEST.M_BALKEN_UNGUELTIG'));
            }
            $h = ltrim($hol('test_balkenfarbe'), '#');
            $b = array('aktion' => 'balken', 'geraet' => (int) $nr, 'wert' => (int) $w);
            if ($h !== '') {
                if (!preg_match('/^[0-9a-fA-F]{6}$/', $h)) {
                    return array(0, gv_t('TEST.M_HEX_UNGUELTIG'));
                }
                $b['hex'] = $h;
            }
            return gv_befehl_absetzen($b);

        case 'segment':
            $s = $hol('test_segmente');
            if ($s === '' || !preg_match('/^[0-9a-fA-F:,\- ]{1,300}$/', $s)) {
                return array(0, gv_t('TEST.M_SEGMENT_UNGUELTIG'));
            }
            $verf = $hol('test_verfahren');
            $verfahren = gv_segmentverfahren();
            if (!isset($verfahren[$verf])) {
                $verf = 'graffiti';
            }
            $b = array('aktion' => 'segment', 'geraet' => (int) $nr,
                       'segmente' => str_replace(' ', '', $s), 'verfahren' => $verf);
            $bew = $hol('test_bewegung');
            if (preg_match('/^[0-9]{1,3}$/', $bew)) {
                $b['bewegung'] = (int) $bew;
            }
            return gv_befehl_absetzen($b);

        default:
            return array(0, gv_t('TEST.M_UNBEKANNT'));
    }
}

/**
 * Trockenlauf: zeigt, WAS ein Befehl senden wuerde.
 *
 * Er ruft dieselben zwei Funktionen wie der Dienst - gv_befehl_pruefen() und
 * gv_nachricht_bauen() - und sendet nichts. Deshalb braucht er weder eine
 * Verbindung noch einen laufenden Dienst; gerade dann will man es wissen.
 *
 * Rueckgabe: array(stand, Text)
 */
function gv_trockenlauf()
{
    $hol = function ($feld) {
        return isset($_POST[$feld]) ? trim((string) $_POST[$feld]) : '';
    };
    $nr = (int) $hol('test_geraet');
    $g = gv_geraet($nr > 0 ? $nr : 1);
    if ($g === null) {
        return array(0, gv_t('TEST.M_GERAET_UNBEKANNT'));
    }
    $aktion = $hol('test_trocken');
    $erlaubt = array('ein', 'aus', 'hell', 'kelvin', 'farbe', 'szene', 'szenenr',
                     'balken', 'segment', 'musik');
    if (!in_array($aktion, $erlaubt, true)) {
        return array(0, gv_t('TEST.M_UNBEKANNT'));
    }

    /* Dieselben Felder wie die echten Knoepfe daneben. */
    $b = array('geraet' => $nr);
    if ($aktion === 'hell')    { $b['wert'] = (int) $hol('test_hell'); }
    if ($aktion === 'kelvin')  { $b['wert'] = (int) $hol('test_kelvin'); }
    if ($aktion === 'balken')  {
        $b['wert'] = (int) $hol('test_balken');
        $h = ltrim($hol('test_balkenfarbe'), '#');
        if (preg_match('/^[0-9a-fA-F]{6}$/', $h)) { $b['hex'] = $h; }
    }
    if ($aktion === 'farbe') {
        $h = ltrim($hol('test_hex'), '#');
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $h)) {
            return array(0, gv_t('TEST.M_HEX_UNGUELTIG'));
        }
        $b['r'] = hexdec(substr($h, 0, 2));
        $b['g'] = hexdec(substr($h, 2, 2));
        $b['b'] = hexdec(substr($h, 4, 2));
    }
    if ($aktion === 'szene')   { $b['name'] = $hol('test_szene'); }
    if ($aktion === 'szenenr') { $aktion = 'szene'; $b['nr'] = (int) $hol('test_szenenr'); }
    if ($aktion === 'segment') {
        $b['segmente'] = str_replace(' ', '', $hol('test_segmente'));
        $b['verfahren'] = $hol('test_verfahren');
        $bew = $hol('test_bewegung');
        if (preg_match('/^[0-9]{1,3}$/', $bew)) { $b['bewegung'] = (int) $bew; }
    }
    if ($aktion === 'musik') {
        $b['art'] = (int) $hol('test_musik_art');
        $b['gruppe'] = (int) $hol('test_musik_gruppe');
        $b['sens'] = (int) $hol('test_musik_sens');
    }

    list($ok, $meldung) = gv_befehl_pruefen($aktion, $b);
    if (!$ok) {
        return array(0, $meldung);
    }
    list($nachricht, $meldung) = gv_nachricht_bauen($aktion, $g, $b);
    if ($nachricht === null) {
        return array(0, $meldung);
    }

    $zeilen = array();
    $zeilen[] = sprintf(gv_t('TEST.TROCKEN_KOPF'), $aktion, $g['name'], $g['ip'], GV_PORT_BEFEHL);
    $zeilen[] = '';
    $zeilen[] = $nachricht;
    $zeilen[] = '';
    $zeilen[] = sprintf(gv_t('TEST.TROCKEN_LAENGE'), strlen($nachricht));

    /* Bei ptReal die einzelnen Pakete in Hex danebenstellen - base64 sagt
     * niemandem etwas, die Bytes schon. */
    $j = json_decode($nachricht, true);
    if (isset($j['msg']['cmd']) && $j['msg']['cmd'] === 'ptReal'
        && isset($j['msg']['data']['command'])) {
        $zeilen[] = '';
        $zeilen[] = gv_t('TEST.TROCKEN_PAKETE');
        foreach ((array) $j['msg']['data']['command'] as $i => $c) {
            $roh = base64_decode((string) $c, true);
            $hex = '';
            for ($k = 0; $roh !== false && $k < strlen($roh); $k++) {
                $hex .= sprintf('%02x ', ord($roh[$k]));
            }
            $zeilen[] = sprintf('  %2d  %s', $i + 1, rtrim($hex));
        }
    }
    $zeilen[] = '';
    $zeilen[] = gv_t('TEST.TROCKEN_FUSS');
    return array(1, implode("\n", $zeilen));
}

/**
 * Eine Vorschau des Balkens als kleines SVG - damit man vor dem Senden sieht,
 * was ankommt. Reine Anzeige, sie spricht mit keinem Geraet.
 */
function gv_balken_svg($prozent, $pixel, $hex = '646400')
{
    $pixel = max(1, min(60, (int) $pixel));
    $prozent = max(0, min(100, (int) $prozent));
    $an = (int) round($prozent * $pixel / 100);
    $bw = 26;
    $luecke = 4;
    $w = $pixel * ($bw + $luecke) + $luecke;
    $h = 44;
    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" style="width:100%;max-width:' . $w
         . 'px;height:auto;background:#fafafa;border:1px solid #e0e0e0;border-radius:8px;"'
         . ' xmlns="http://www.w3.org/2000/svg">';
    for ($i = 0; $i < $pixel; $i++) {
        $x = $luecke + $i * ($bw + $luecke);
        $farbe = $i < $an ? ('#' . $hex) : '#e6e6e6';
        $svg .= '<rect x="' . $x . '" y="8" width="' . $bw . '" height="28" rx="5" ry="5" fill="'
              . gv_e($farbe) . '" stroke="#cccccc" stroke-width="1"/>';
    }
    return $svg . '</svg>';
}
