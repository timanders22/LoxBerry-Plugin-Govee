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

    $zu = gv_zustand();
    if (!empty($zu['fehler'])) {
        $zeilen[] = gv_pruefzeile(0, gv_t('TEST.F_LETZTER_FEHLER'), gv_e($zu['fehler']));
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
        } else {
            $zeilen[] = gv_pruefzeile(1, gv_t('TEST.F_CLOUD'),
                sprintf(gv_t('TEST.A_CLOUD_KEY'), strlen($key)));
        }
    }

    $zeilen[] = gv_pruefzeile(!empty($cfg['steuerung_ein']) ? 1 : -1, gv_t('TEST.F_STEUERUNG'),
        !empty($cfg['steuerung_ein']) ? gv_t('TEST.A_STEUERUNG_EIN') : gv_t('TEST.A_STEUERUNG_AUS'));

    $zeilen[] = gv_pruefzeile(!empty($cfg['pt_frei']) ? -1 : 1, gv_t('TEST.F_PTFREI'),
        !empty($cfg['pt_frei']) ? gv_t('TEST.A_PTFREI_EIN') : gv_t('TEST.A_PTFREI_AUS'));

    /* Die erzeugten Loxone-Vorlagen gegen den Parser halten. Der Anwender
     * merkte eine kaputte Vorlage sonst erst in Loxone Config - und dort
     * sucht er den Fehler bei sich. */
    $zeilen[] = gv_pruefzeile_vorlagen();

    return $zeilen;
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
        foreach (array('gv_vorlage_ausgang', 'gv_vorlage_szenen', 'gv_vorlage_eingang') as $f) {
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
            $katalog = gv_szenen();
            if (!isset($katalog[$s])) {
                return array(0, gv_t('TEST.M_SZENE_UNBEKANNT'));
            }
            return gv_befehl_absetzen(array('aktion' => 'szene', 'geraet' => (int) $nr, 'name' => $s));

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
