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

    /* Ob der Dienst laeuft, sagt seine Sperre, nicht die PID-Datei (C3):
     * nach einem Doppelstart lief er ohne PID-Datei, und diese Zeile meldete
     * "angehalten" (govee_agenten/code, Befund 2). Die PID steht nur dabei. */
    $laeuft = gv_dienst_laeuft();
    $pid = gv_dienst_pid();
    $zeilen[] = gv_pruefzeile($laeuft ? 1 : 0, gv_t('TEST.F_DIENST'),
        $laeuft ? ($pid > 0 ? gv_t('TEST.A_DIENST_LAEUFT') . ' ' . $pid : gv_t('TEST.A_DIENST_LAEUFT_OHNE_PID'))
                : (gv_dienst_soll() ? gv_t('TEST.A_DIENST_SOLL_TOT') : gv_t('TEST.A_DIENST_GESTOPPT')));

    /* Laeuft gerade eine Aktualisierung? Solange die Marke liegt, weist
     * bin/dienst.sh jeden Start ab - auch den ueber die Knoepfe im Reiter
     * Einstellungen. Ohne diese Zeile gaebe es die Regel, aber nichts, was
     * sie sichtbar macht (CLAUDE.md 6: zu jeder Regel gehoert das Werkzeug,
     * das sie findet). Drei Ausgaenge, drei Saetze; die liegengebliebene
     * Marke ist ein Kreuz, die laufende Aktualisierung nur ein Hinweis. */
    list($mk_liegt, $mk_gueltig, $mk_alter) = gv_upgrade_marke();
    if (!$mk_liegt) {
        $zeilen[] = gv_pruefzeile(1, gv_t('TEST.F_UPGRADE_MARKE'),
            gv_t('TEST.A_UPGRADE_KEINE'));
    } elseif ($mk_gueltig) {
        $zeilen[] = gv_pruefzeile(-1, gv_t('TEST.F_UPGRADE_MARKE'),
            sprintf(gv_t('TEST.A_UPGRADE_LAEUFT'), (int) $mk_alter));
    } else {
        $zeilen[] = gv_pruefzeile(0, gv_t('TEST.F_UPGRADE_MARKE'),
            sprintf(gv_t('TEST.A_UPGRADE_ALT'),
                gv_e($p['plugin'] . '.upgrade_laeuft')));
    }

    /* Die Prozessnummer beantwortet nicht, ob der Dienst noch ARBEITET - ein
     * Prozess kann dastehen und nichts mehr tun. Das Lebenszeichen kommt aus
     * zustand.json, das der Dienst in jeder Runde neu schreibt. */
    $lz = gv_dienst_lebenszeichen();
    $grenze = gv_altersgrenze($cfg);
    if (!$laeuft) {
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
    /* Ein Probebinden sagt nichts: UDP 4002 laesst sich neben dem Dienst ein
     * zweites Mal binden, und bis 0.9.22 meldete diese Zeile deshalb "Port
     * frei", waehrend ein Dienst ohne PID-Datei ihn hielt (C3,
     * govee_agenten/code Befund 3). Gefragt wird nur noch die Sperre. */
    if ($laeuft) {
        $zeilen[] = gv_pruefzeile(1, gv_t('TEST.F_PORT'), sprintf(gv_t('TEST.A_PORT_DIENST'), GV_PORT_ANTWORT));
    } else {
        $zeilen[] = gv_pruefzeile(-1, gv_t('TEST.F_PORT'), sprintf(gv_t('TEST.A_PORT_FREI'), GV_PORT_ANTWORT));
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

    /* Veroeffentlicht DIESES Plugin ueberhaupt? (Regeln/04, B46 aus
     * BatterieBMS 0.9.17, 06.09.2026)
     *
     * Die Zeile darunter liest den Autostart des GATEWAYS aus der
     * general.json - das ist eine Aussage ueber LoxBerry, nicht ueber dieses
     * Plugin. Steht der eigene Schalter auf aus, geht nichts an den Broker
     * und damit nichts an Loxone; der Reiter zeigte dazu trotzdem einen
     * gruenen Haken und konnte die beiden Faelle gar nicht unterscheiden.
     * Am Geraet gemessen (BatterieBMS, 06.09.2026): Dienst lief, Gateway
     * lief, 35 s Mithoeren am Broker bei 30 s Takt - keine einzige Nachricht.
     *
     * Grau statt rot: ausgeschaltet ist eine Entscheidung, kein Fehler. */
    $mqttEin = !empty($cfg['mqtt_ein']);
    $zeilen[] = gv_pruefzeile($mqttEin ? 1 : -1, gv_t('TEST.F_MQTT_EIN'),
        gv_t($mqttEin ? 'TEST.A_MQTT_EIN_JA' : 'TEST.A_MQTT_EIN_NEIN'));

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

    /* Traegt jedes Formular das Merkmal gegen fremde Absender - als
     * vollstaendiges Feld - und hat jedes Formular zum Hochladen sein
     * Dateifeld? Gelesen wird die eigene Datei (gv_pruefzeile_formulare()). */
    $zeilen[] = gv_pruefzeile_formulare();

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
 * Formulare der eigenen Oberflaeche pruefen (U2): traegt jedes das Merkmal
 * gegen fremde Absender als VOLLSTAENDIGES Feld, und hat jedes
 * multipart-Formular ein Dateifeld?
 *
 * Bis 0.9.22 zaehlte diese Zeile nur, wie oft das Feld fmt mit einem Wert im Quelltext steht, und
 * zeigte einen gruenen Haken, waehrend dem Merkmal im Formular
 * "Zurueckspielen" das schliessende "> fehlte: das Dateifeld verschwand im
 * Wert des versteckten Felds, und der Knopf hat nie gewirkt
 * (govee_agenten/oberflaeche, Befunde 1 und 2). Jetzt wird die Datei so
 * zerlegt, wie ein Browser sie liest (gv_formulare_pruefen()).
 * $quelle nur fuer die Eichung; sonst wird index.php daneben gelesen.
 */
function gv_pruefzeile_formulare($quelle = null)
{
    if ($quelle === null) {
        $eigene = __DIR__ . '/index.php';
        $quelle = is_file($eigene) ? (string) @file_get_contents($eigene) : '';
    }
    if ($quelle === '') {
        return gv_pruefzeile(-1, gv_t('TEST.F_FORMULAR'), gv_t('TEST.A_FORMULAR_UNBEKANNT'));
    }
    list($formulare, $maengel) = gv_formulare_pruefen($quelle);
    if ($formulare === 0) {
        /* Ueber eine leere Menge wird nicht geurteilt. */
        return gv_pruefzeile(-1, gv_t('TEST.F_FORMULAR'), gv_t('TEST.A_FORMULAR_UNBEKANNT'));
    }
    if ($maengel) {
        return gv_pruefzeile(0, gv_t('TEST.F_FORMULAR'),
            sprintf(gv_t('TEST.A_FORMULAR_FEHL'), count($maengel), $formulare, gv_e(implode('; ', $maengel))));
    }
    return gv_pruefzeile(1, gv_t('TEST.F_FORMULAR'), sprintf(gv_t('TEST.A_FORMULAR_OK'), $formulare));
}

/**
 * Rueckgabe: array(Zahl der Formulare, Maengel[]). Die Ausgabe des Merkmals
 * wird zu @@FMT@@, jede andere PHP-Stelle zu @@PHP@@; Kommentare, Skript
 * und Stil fallen weg. Ein POST-Formular braucht genau ein Feld fmt mit
 * genau diesem Wert - fehlt ihm das schliessende "> , frisst der Wert das
 * naechste Tag und ist nicht mehr @@FMT@@.
 */
function gv_formulare_pruefen($quelle)
{
    $h = str_replace('<?= gv_e($gv_fmt) ?>', '@@FMT@@', (string) $quelle);
    $h = preg_replace('/<\?(?:php|=).*?\?>/s', '@@PHP@@', $h);
    $h = preg_replace('/<!--.*?-->/s', '', $h);
    $h = preg_replace('#<(script|style)\b.*?</\1\s*>#is', '', $h);
    $formulare = 0;
    $maengel = array();
    $offen = null;
    $abschluss = function ($f) use (&$maengel) {
        if ($f['post'] && $f['fmt'] === 0) {
            $maengel[] = sprintf(gv_t('TEST.A_FORMULAR_M_FEHLT'), $f['nr']);
        } elseif ($f['post'] && ($f['fmt'] !== 1 || $f['fmt_gut'] !== 1)) {
            $maengel[] = sprintf(gv_t('TEST.A_FORMULAR_M_KAPUTT'), $f['nr']);
        }
        if ($f['multipart'] && $f['datei'] === 0) {
            $maengel[] = sprintf(gv_t('TEST.A_FORMULAR_M_DATEI'), $f['nr']);
        }
    };
    foreach (gv_html_tags($h) as $t) {
        if ($t['name'] === 'form') {
            if ($offen !== null) {
                $abschluss($offen);
            }
            $formulare++;
            $offen = array(
                'nr'        => $formulare,
                'post'      => isset($t['attr']['method']) && strtolower($t['attr']['method']) === 'post',
                'multipart' => isset($t['attr']['enctype'])
                               && strtolower($t['attr']['enctype']) === 'multipart/form-data',
                'fmt'       => 0,
                'fmt_gut'   => 0,
                'datei'     => 0,
            );
        } elseif ($t['name'] === '/form') {
            if ($offen !== null) {
                $abschluss($offen);
            }
            $offen = null;
        } elseif ($t['name'] === 'input' && $offen !== null) {
            if (isset($t['attr']['name']) && $t['attr']['name'] === 'fmt') {
                $offen['fmt']++;
                if (isset($t['attr']['value']) && $t['attr']['value'] === '@@FMT@@') {
                    $offen['fmt_gut']++;
                }
            }
            if (isset($t['attr']['type']) && strtolower($t['attr']['type']) === 'file') {
                $offen['datei']++;
            }
        }
    }
    if ($offen !== null) {
        $abschluss($offen);
    }
    return array($formulare, $maengel);
}

/**
 * Tags so zerlegen, wie ein Browser sie liest - so weit, wie die
 * Formularpruefung es braucht: ein Tag beginnt mit < und einem Buchstaben
 * oder /, ein Attributwert in "..." oder '...' darf < und > enthalten, das
 * Tag endet am ersten > ausserhalb davon. Doppelte Attribute: das erste gilt.
 * Rueckgabe: Liste von array('name' => 'input' | '/form' ..., 'attr' => ...).
 */
function gv_html_tags($h)
{
    $tags = array();
    $n = strlen($h);
    $i = 0;
    while ($i < $n) {
        $p = strpos($h, '<', $i);
        if ($p === false || $p + 1 >= $n) {
            break;
        }
        if (!preg_match('#[A-Za-z/]#', $h[$p + 1])) {
            $i = $p + 1;
            continue;
        }
        $j = $p + 1;
        $zu = '';
        if ($h[$j] === '/') {
            $zu = '/';
            $j++;
        }
        $name = '';
        while ($j < $n && preg_match('/[A-Za-z0-9]/', $h[$j])) {
            $name .= $h[$j];
            $j++;
        }
        $attr = array();
        while ($j < $n) {
            while ($j < $n && strpos(" \t\r\n/", $h[$j]) !== false) {
                $j++;
            }
            if ($j >= $n) {
                break;
            }
            if ($h[$j] === '>') {
                $j++;
                break;
            }
            $an = '';
            while ($j < $n && strpos(" \t\r\n/>=", $h[$j]) === false) {
                $an .= $h[$j];
                $j++;
            }
            while ($j < $n && strpos(" \t\r\n", $h[$j]) !== false) {
                $j++;
            }
            $wert = '';
            if ($j < $n && $h[$j] === '=') {
                $j++;
                while ($j < $n && strpos(" \t\r\n", $h[$j]) !== false) {
                    $j++;
                }
                if ($j < $n && ($h[$j] === '"' || $h[$j] === "'")) {
                    $ende = strpos($h, $h[$j], $j + 1);
                    if ($ende === false) {
                        $wert = (string) substr($h, $j + 1);
                        $j = $n;
                    } else {
                        $wert = (string) substr($h, $j + 1, $ende - $j - 1);
                        $j = $ende + 1;
                    }
                } else {
                    while ($j < $n && strpos(" \t\r\n>", $h[$j]) === false) {
                        $wert .= $h[$j];
                        $j++;
                    }
                }
            }
            $an = strtolower($an);
            if ($an !== '' && !array_key_exists($an, $attr)) {
                $attr[$an] = $wert;
            }
        }
        $tags[] = array('name' => $zu . strtolower($name), 'attr' => $attr);
        $i = $j;
    }
    return $tags;
}

/**
 * Die Themenliste gegen den Sendecode halten - in BEIDEN Richtungen (M4).
 *
 * Bis 0.9.22 suchte diese Zeile nur, ob 'hell' oder 'r' irgendwo in der
 * Dienstdatei steht, und nur in einer Richtung: ohne hell, r, g und b in der
 * Sendeschleife und mit einem zusaetzlichen Thema blieb sie gruen
 * (govee_agenten/mqtt, Befund 4). Jetzt baut sie die Paare mit derselben
 * Funktion, mit der der Dienst sendet (gv_mqtt_paare()), fuer ein Geraet,
 * das jeden Wert liefert, und vergleicht die Themen mit gv_mqtt_themen().
 * Dazu die Gegenprobe, dass der Dienst seine Paare wirklich dort baut.
 * $dienst_quelle nur fuer die Eichung.
 */
function gv_pruefzeile_themen($dienst_quelle = null)
{
    if ($dienst_quelle === null) {
        $p = gv_paths();
        $dienst = $p['bindir'] . '/govee_dienst.php';
        if (!is_file($dienst)) {
            /* Aus dem entpackten Archiv heraus liegt er woanders. */
            $dienst = dirname(dirname(__DIR__)) . '/bin/govee_dienst.php';
        }
        $dienst_quelle = is_file($dienst) ? (string) @file_get_contents($dienst) : '';
    }
    if ($dienst_quelle === '') {
        return gv_pruefzeile(-1, gv_t('TEST.F_THEMEN'), gv_t('TEST.A_THEMEN_UNBEKANNT'));
    }
    if (strpos($dienst_quelle, 'gv_mqtt_paare(') === false) {
        return gv_pruefzeile(0, gv_t('TEST.F_THEMEN'), gv_t('TEST.A_THEMEN_OHNE_BAUER'));
    }
    $jetzt = time();
    $cfg = gv_vorgaben();
    $probe = array(1 => array('name' => 'Probe', 'art' => 'lan', 'ts' => $jetzt, 'ok' => 1,
        'fehl' => 0, 'an' => 1, 'hell' => 50, 'kelvin' => 3000, 'r' => 1, 'g' => 2, 'b' => 3,
        'hex' => '010203'));
    $gesendet = array();
    foreach (array_keys(gv_mqtt_paare($probe, array('ok' => 1, 'geraete' => 1, 'fehler_folge' => 0),
                                      $cfg, $jetzt)) as $k) {
        $gesendet[preg_replace('#^geraet1/#', 'geraetN/', $k)] = true;
    }
    $tabelle = gv_mqtt_themen();
    if (!$gesendet || !$tabelle) {
        /* Ueber eine leere Menge wird nicht geurteilt. */
        return gv_pruefzeile(-1, gv_t('TEST.F_THEMEN'), gv_t('TEST.A_THEMEN_UNBEKANNT'));
    }
    $fehlt = array_keys(array_diff_key($tabelle, $gesendet));
    $extra = array_keys(array_diff_key($gesendet, $tabelle));
    if ($fehlt || $extra) {
        $teile = array();
        if ($fehlt) {
            $teile[] = sprintf(gv_t('TEST.A_THEMEN_FEHL'), gv_e(implode(', ', $fehlt)), count($tabelle));
        }
        if ($extra) {
            $teile[] = sprintf(gv_t('TEST.A_THEMEN_ZUSATZ'), gv_e(implode(', ', $extra)));
        }
        return gv_pruefzeile(0, gv_t('TEST.F_THEMEN'), implode(' ', $teile));
    }
    return gv_pruefzeile(1, gv_t('TEST.F_THEMEN'), sprintf(gv_t('TEST.A_THEMEN_OK'), count($tabelle)));
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
    /* Eigenes Merkmal fuer "die Anfrage ist durchgelaufen". Frueher stand die
     * Einstufung hinter `if ($erg['lage'] !== 'unbekannt')` - und 'lage'
     * blieb auf dem Startwert 'unbekannt', wenn alles GEKLAPPT hatte. Die
     * Wache lief also genau im Erfolgsfall nicht: 'gut' und 'falsch' waren
     * unerreichbar, die Zeile meldete immer "nicht feststellbar", auch bei
     * einem kaputten Endpunkt. */
    $messbar = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($adresse);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        $antwort = curl_exec($ch);
        $erg['code'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fehler = curl_error($ch);
        if (PHP_VERSION_ID < 80000) { curl_close($ch); }
        if ($antwort === false) {
            $erg['text'] = $fehler;
        } else {
            $erg['text'] = substr((string) $antwort, 0, 300);
            $messbar = true;
        }
    } elseif (ini_get('allow_url_fopen')) {
        /* 'ignore_errors': ohne das gibt file_get_contents bei HTTP 403 oder
         * 500 nur FALSE zurueck - ein KAPUTTER Endpunkt sah dann aus wie
         * "nicht messbar", also wie das harmloseste der drei Ergebnisse. Und
         * der Code wurde fest mit 200 eingetragen, ohne je gelesen zu sein. */
        $kontext = stream_context_create(array('http' => array(
            'timeout' => 3, 'ignore_errors' => true)));
        /* Statuscode ueber gv_http_abruf() statt ueber die vordefinierte Variable der Kopfzeilen
         * (C10): PHP 8.5 fuehrt die Variable als veraltet, und fiele sie weg,
         * verdeckte die Vorbelegung das still - Code 0, "nicht
         * feststellbar" (govee_agenten/code, ohne Befund geprueft). */
        list($antwort, $gv_code) = gv_http_abruf($adresse, $kontext);
        $erg['text'] = ($antwort === false) ? '' : substr((string) $antwort, 0, 300);
        $erg['code'] = (int) $gv_code;
        $messbar = ($antwort !== false && $erg['code'] > 0);
    } else {
        $erg['lage'] = 'unbekannt';
        $erg['text'] = 'weder curl noch allow_url_fopen';
    }

    if ($messbar) {
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
            if (gv_dienst_laeuft()) {
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
            if (gv_dienst_laeuft()) {
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
