<?php
/**
 * Govee - Bedienoberflaeche
 *
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Test | Logdateien
 *
 * Diese Datei ist NUR Oberflaeche. Der Abruf laeuft im Dienst
 * (bin/govee_dienst.php), der Miniserver spricht mit
 * webfrontend/html/index.php. Ein Plugin, das den Abruf hier erledigt, ist
 * falsch gebaut - auch wenn es funktioniert.
 *
 * Praefix 'gv_', weil LBWeb::lbheader() SDK-Globale setzt.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Bibliothek einbinden. Sie liegt unter webfrontend/html/, weil Endpunkt und
 * Dienst sie ebenfalls brauchen - installiert unter
 * <home>/webfrontend/html/plugins/<ordner>/, im Archiv unter ../html/. */
$gv_gefunden_lib = false;
foreach (array(
    dirname(dirname(__DIR__)) . '/html/plugins/' . basename(__DIR__) . '/gv_lib.php',
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/gv_lib.php',
    dirname(__DIR__) . '/html/gv_lib.php',
) as $gv_kandidat) {
    if (is_file($gv_kandidat)) {
        require_once $gv_kandidat;
        $gv_gefunden_lib = true;
        break;
    }
}
if (!$gv_gefunden_lib) {
    echo '<p><b>Fehler:</b> gv_lib.php wurde nicht gefunden. Bitte das Plugin neu installieren.</p>';
    exit;
}
require_once __DIR__ . '/gv_test.php';

$gv_p = gv_paths();
if ($gv_p['home'] !== '' && is_file($gv_p['home'] . '/libs/phplib/loxberry_system.php')) {
    require_once $gv_p['home'] . '/libs/phplib/loxberry_system.php';
    require_once $gv_p['home'] . '/libs/phplib/loxberry_web.php';
}

/* Die Reiter, an EINER Stelle. Leiste, Pruefausdruck und die serverseitige
 * Klasse sm-active entstehen daraus - vergessen kann man nichts mehr. */
$gv_reiter = array(
    'settings' => 'REITER.EINSTELLUNGEN',
    'mqtt'     => null,                    // Eigenname, wird nicht uebersetzt
    'loxone'   => 'REITER.LOXONE',
    'test'     => 'REITER.TEST',
    'log'      => 'REITER.LOG',
);
$gv_muster = '/^tab-(' . implode('|', array_map(function ($k) {
    return preg_quote($k, '/');
}, array_keys($gv_reiter))) . ')$/';
$gv_tab = 'tab-settings';
if (isset($_POST['activetab']) && preg_match($gv_muster, (string) $_POST['activetab'])) {
    $gv_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form']) && preg_match($gv_muster, 'tab-' . (string) $_GET['form'])) {
    $gv_tab = 'tab-' . (string) $_GET['form'];
}

$gv_meldungen = array();
$gv_fehler = array();      // gesammelt, nicht ueberschrieben
$gv_testausgabe = '';
$gv_post = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST';

/* ---------------- Vorlage herunterladen ---------------- */
if ($gv_post && isset($_POST['vorlage'])) {
    $gv_art = (string) $_POST['vorlage'];
    $gv_nr = (isset($_POST['vorlage_nr']) && preg_match('/^[0-9]{1,2}$/', (string) $_POST['vorlage_nr']))
        ? (int) $_POST['vorlage_nr'] : 1;
    $gv_name = '';
    $gv_inhalt = '';
    if ($gv_art === 'ausgang') {
        list($gv_name, $gv_inhalt) = gv_vorlage_ausgang($gv_nr);
    } elseif ($gv_art === 'szenen') {
        list($gv_name, $gv_inhalt) = gv_vorlage_szenen($gv_nr);
    } elseif ($gv_art === 'eingang') {
        list($gv_name, $gv_inhalt) = gv_vorlage_eingang($gv_nr);
    }
    if ($gv_inhalt === '') {
        $gv_fehler[] = gv_t('LOX.FEHLER_VORLAGE');
        $gv_tab = 'tab-loxone';
    } else {
        header('Content-Type: application/xml; charset=utf-8');
        // Die Anfuehrungszeichen um den Dateinamen sind Pflicht: ohne sie
        // bricht jeder Name, der ein Leerzeichen enthaelt.
        header('Content-Disposition: attachment; filename="' . $gv_name . '"');
        echo $gv_inhalt;
        exit;
    }
}

/* ---------------- Einstellungen speichern ---------------- */
if ($gv_post && isset($_POST['speichern'])) {
    $gv_cfg = gv_config();

    /* Geraetetabelle: bis zu acht Zeilen. Nur Zeilen mit den noetigen Angaben
     * werden uebernommen; unvollstaendige werden gemeldet, nicht verschluckt. */
    $gv_neu = array();
    for ($gv_i = 0; $gv_i < 8; $gv_i++) {
        $gv_hol = function ($feld) use ($gv_i) {
            $a = isset($_POST[$feld]) ? (array) $_POST[$feld] : array();
            /* Nur Steuerzeichen, Anfuehrungszeichen und Leerraum entfernen -
             * ein hartes preg_replace auf eine Positivliste zerstoert
             * eingefuegte Werte (belegt am ACTi-Plugin am 26.07.2026). */
            return isset($a[$gv_i]) ? trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', (string) $a[$gv_i])) : '';
        };
        $gv_art = $gv_hol('g_art') === 'cloud' ? 'cloud' : 'lan';
        $gv_ip = $gv_hol('g_ip');
        $gv_sku = $gv_hol('g_sku');
        $gv_dev = $gv_hol('g_device');
        $gv_nm = $gv_hol('g_name');
        if ($gv_ip === '' && $gv_sku === '' && $gv_dev === '' && $gv_nm === '') {
            continue;   // leere Zeile
        }
        if ($gv_art === 'lan') {
            if ($gv_ip === '') {
                $gv_fehler[] = sprintf(gv_t('EINST.FEHLER_IP_FEHLT'), $gv_i + 1);
                continue;
            }
            // IPv4 oder Hostname zulassen - beides ist gebraeuchlich.
            if (!preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $gv_ip)
                && !preg_match('/^[A-Za-z0-9][A-Za-z0-9\.\-]{1,80}$/', $gv_ip)) {
                $gv_fehler[] = sprintf(gv_t('EINST.FEHLER_IP'), $gv_i + 1);
                continue;
            }
        } else {
            if ($gv_sku === '' || $gv_dev === '') {
                $gv_fehler[] = sprintf(gv_t('EINST.FEHLER_CLOUD_IDS'), $gv_i + 1);
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9]{1,16}$/', $gv_sku)
                || !preg_match('/^[0-9A-Fa-f:]{1,40}$/', $gv_dev)) {
                $gv_fehler[] = sprintf(gv_t('EINST.FEHLER_CLOUD_MUSTER'), $gv_i + 1);
                continue;
            }
        }
        $gv_zeile = array(
            'name'   => $gv_nm,
            'art'    => $gv_art,
            'ip'     => $gv_ip,
            'sku'    => $gv_sku,
            'device' => $gv_dev,
        );
        foreach (array('pixel' => array(0, 200), 'kmin' => array(1000, 10000),
                       'kmax' => array(1000, 10000)) as $gv_f => $gv_gr) {
            $gv_w = $gv_hol('g_' . $gv_f);
            if ($gv_w === '') {
                continue;   // leer = Vorgabe nehmen
            }
            if (!preg_match('/^[0-9]+$/', $gv_w)
                || (int) $gv_w < $gv_gr[0] || (int) $gv_w > $gv_gr[1]) {
                $gv_fehler[] = sprintf(gv_t('EINST.FEHLER_ZEILENWERT'),
                    $gv_i + 1, gv_t('EINST.T_' . strtoupper($gv_f)), $gv_gr[0], $gv_gr[1]);
                continue;
            }
            $gv_zeile[$gv_f] = (int) $gv_w;
        }
        if (isset($gv_zeile['kmin'], $gv_zeile['kmax']) && $gv_zeile['kmin'] >= $gv_zeile['kmax']) {
            $gv_fehler[] = sprintf(gv_t('EINST.FEHLER_KELVIN_REIHE'), $gv_i + 1);
            continue;
        }
        $gv_neu[] = $gv_zeile;
    }
    $gv_cfg['geraete'] = $gv_neu;

    foreach (array(
        'intervall'  => array(5, 3600),
        'suchtakt'   => array(0, 1440),
        'wartezeit'  => array(0, 10),
        'cloud_takt' => array(1, 1440),
    ) as $gv_feld => $gv_grenzen) {
        $gv_wert = isset($_POST[$gv_feld]) ? trim((string) $_POST[$gv_feld]) : '';
        if (!preg_match('/^[0-9]+$/', $gv_wert)) {
            $gv_fehler[] = sprintf(gv_t('EINST.FEHLER_ZAHL'), gv_t('EINST.L_' . strtoupper($gv_feld)));
            continue;
        }
        $gv_zahl = (int) $gv_wert;
        if ($gv_zahl < $gv_grenzen[0] || $gv_zahl > $gv_grenzen[1]) {
            $gv_fehler[] = sprintf(gv_t('EINST.FEHLER_BEREICH'),
                gv_t('EINST.L_' . strtoupper($gv_feld)), $gv_grenzen[0], $gv_grenzen[1]);
            continue;
        }
        $gv_cfg[$gv_feld] = $gv_zahl;
    }

    $gv_cfg['steuerung_ein'] = isset($_POST['steuerung_ein']) ? 1 : 0;
    $gv_cfg['pt_frei'] = isset($_POST['pt_frei']) ? 1 : 0;
    $gv_cfg['cloud_ein'] = isset($_POST['cloud_ein']) ? 1 : 0;


    /* Der Cloud-Schluessel steht in einer eigenen Datei mit Rechten 0600.
     * Ein leeres Feld loescht nichts - sonst stuende irgendwann ein leerer
     * Schluessel dort, ohne dass es jemand merkt. Zum Loeschen gibt es einen
     * eigenen Haken. */
    $gv_geheim = gv_geheim();
    $gv_key = isset($_POST['cloud_key']) ? trim((string) $_POST['cloud_key']) : '';
    if (isset($_POST['cloud_key_loeschen'])) {
        $gv_geheim['cloud_key'] = '';
        gv_geheim_speichern($gv_geheim);
        $gv_meldungen[] = gv_t('EINST.KEY_GELOESCHT');
    } elseif ($gv_key !== '') {
        /* Die FORM eines Geheimnisses darf beurteilt werden, sein Wert nie
         * angezeigt. Der Govee-Schluessel ist eine GUID mit 36 Zeichen -
         * wer etwas anderes einfuegt, laeuft sonst in eine Fehlermeldung des
         * Anbieters statt in eine verstaendliche hier. */
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $gv_key)) {
            $gv_fehler[] = sprintf(gv_t('EINST.FEHLER_KEY'), strlen($gv_key));
        } else {
            $gv_geheim['cloud_key'] = $gv_key;
            if (!gv_geheim_speichern($gv_geheim)) {
                $gv_fehler[] = sprintf(gv_t('EINST.FEHLER_SPEICHERN'), $gv_p['geheim']);
            }
        }
    }

    if (!$gv_fehler) {
        if (gv_config_speichern($gv_cfg)) {
            $gv_meldungen[] = gv_t('EINST.GESPEICHERT');
        } else {
            $gv_fehler[] = sprintf(gv_t('EINST.FEHLER_SPEICHERN'), $gv_p['config']);
        }
    }
    $gv_tab = 'tab-settings';

    /* mqtt_ein und mqtt_topic werden hier bewusst NICHT angefasst: sie wohnen im
     * Reiter MQTT und haben dort ein eigenes Formular. Die Konfiguration
     * kommt aus gv_config(), die Werte ueberleben also unveraendert. Stuende
     * hier weiter "isset($_POST['mqtt_ein']) ? 1 : 0", wuerde jedes Speichern
     * der Einstellungen MQTT stillschweigend abschalten. */
}

/* ---------------- MQTT (eigener Reiter, eigenes Formular) ----------------
 *
 * Eigenes Formular UND eigener Handler gehoeren zusammen. Loesten beide
 * Formulare denselben Handler aus, setzte dieser die Haken des jeweils
 * nicht abgeschickten Formulars per isset() auf 0 - der Benutzer verloere
 * Werte, die er nie gesehen hat. Der Handler laedt darum den Bestand und
 * ruehrt ausschliesslich die MQTT-Werte an. */
if ($gv_post && isset($_POST['save_mqtt'])) {
    $gv_mcfg = gv_config();
    $gv_mcfg['mqtt_ein'] = isset($_POST['mqtt_ein']) ? 1 : 0;
    $gv_mtopic = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '',
        (string) (isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : '')));
    if ($gv_mtopic === '' || !preg_match('#^[A-Za-z0-9_/\-]{1,64}$#', $gv_mtopic)) {
        $gv_fehler[] = gv_t('EINST.FEHLER_TOPIC');
    } else {
        $gv_mcfg['mqtt_topic'] = trim($gv_mtopic, '/');
    }
    if (!$gv_fehler) {
        if (gv_config_speichern($gv_mcfg)) {
        $gv_meldungen[] = gv_t('EINST.GESPEICHERT');
        }
    }
    $gv_tab = 'tab-mqtt';
}

/* ---------------- Gefundene Geraete uebernehmen ---------------- */
if ($gv_post && isset($_POST['uebernehmen'])) {
    $gv_cfg = gv_config();
    $gv_liste = gv_gefunden();
    $gv_vorhanden = array();
    foreach ((array) $gv_cfg['geraete'] as $gv_z) {
        if (is_array($gv_z) && isset($gv_z['ip'])) {
            $gv_vorhanden[(string) $gv_z['ip']] = true;
        }
    }
    $gv_dazu = 0;
    foreach ((array) (isset($gv_liste['liste']) ? $gv_liste['liste'] : array()) as $gv_f) {
        if (!is_array($gv_f) || !isset($gv_f['ip']) || isset($gv_vorhanden[$gv_f['ip']])) {
            continue;
        }
        if (count($gv_cfg['geraete']) >= 8) {
            $gv_fehler[] = gv_t('EINST.FEHLER_ZU_VIELE');
            break;
        }
        $gv_cfg['geraete'][] = array(
            'name'   => ($gv_f['sku'] !== '' ? $gv_f['sku'] : 'Govee') . ' ' . $gv_f['ip'],
            'art'    => 'lan',
            'ip'     => (string) $gv_f['ip'],
            'sku'    => (string) $gv_f['sku'],
            'device' => (string) $gv_f['device'],
        );
        $gv_vorhanden[$gv_f['ip']] = true;
        $gv_dazu++;
    }
    if ($gv_dazu > 0 && gv_config_speichern($gv_cfg)) {
        $gv_meldungen[] = sprintf(gv_t('EINST.UEBERNOMMEN'), $gv_dazu);
    } elseif ($gv_dazu === 0) {
        $gv_meldungen[] = gv_t('EINST.NICHTS_NEU');
    }
    $gv_tab = 'tab-settings';
}

/* ---------------- Dienst ---------------- */
if ($gv_post && isset($_POST['dienst'])) {
    $gv_befehl = (string) $_POST['dienst'];
    list($gv_ok, $gv_ausgabe) = gv_dienst($gv_befehl);
    if ($gv_ok) {
        $gv_meldungen[] = gv_t('EINST.DIENST_' . strtoupper($gv_befehl)) . ' ' . gv_e($gv_ausgabe);
    } else {
        $gv_fehler[] = gv_e($gv_ausgabe);
    }
    $gv_tab = 'tab-settings';
}

/* ---------------- Neues Token ---------------- */
if ($gv_post && isset($_POST['token_neu'])) {
    $gv_cfg = gv_config();
    $gv_cfg['aktionstoken'] = gv_token_erzeugen();
    if (gv_config_speichern($gv_cfg)) {
        $gv_meldungen[] = gv_t('LOX.TOKEN_NEU');
    } else {
        $gv_fehler[] = sprintf(gv_t('EINST.FEHLER_SPEICHERN'), $gv_p['config']);
    }
    $gv_tab = 'tab-loxone';
}

/* ---------------- Log leeren ---------------- */
if ($gv_post && isset($_POST['log_leeren'])) {
    @mkdir(dirname($gv_p['log']), 0775, true);
    @file_put_contents($gv_p['log'], '[' . date('Y-m-d H:i:s') . '] ' . gv_t('LOG.GELEERT') . "\n");
    $gv_meldungen[] = gv_t('LOG.GELEERT');
    $gv_tab = 'tab-log';
}

/* ---------------- Reiter Test ---------------- */
if ($gv_post && isset($_POST['test'])) {
    list($gv_stand, $gv_text) = gv_test_aktion((string) $_POST['test']);
    if ($gv_stand === 1) {
        $gv_meldungen[] = gv_e($gv_text);
    } else {
        $gv_fehler[] = gv_e($gv_text);
    }
    $gv_tab = 'tab-test';
}
if ($gv_post && isset($_POST['selbsttest'])) {
    $gv_testausgabe = gv_selbsttest_ausgabe();
    $gv_tab = 'tab-test';
}

/* ---------------- Laden ---------------- */
$gv_cfg = gv_config();
$gv_geheim = gv_geheim();
$gv_token = gv_token();
$gv_geraete = gv_geraete();
$gv_werte = gv_werte();
$gv_zustand = gv_zustand();
$gv_such = gv_gefunden();
$gv_alter = gv_alter();
$gv_pid = gv_dienst_pid();
$gv_mqtt = gv_mqtt_zustand();
$gv_host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
    ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
    : (gethostname() ?: 'loxberry');
$gv_basis = 'http://' . $gv_host . '/plugins/' . $gv_p['plugin'] . '/index.php';
$gv_thema = trim((string) $gv_cfg['mqtt_topic'], '/');
// Nur das Ende lesen, nicht die ganze Datei - siehe gv_log_ende().
$gv_logzeilen = gv_log_ende($gv_p['log'], 400);

$gv_rahmen = class_exists('LBWeb', false);
if ($gv_rahmen) {
    LBWeb::lbheader('Govee', 'https://wiki.loxberry.de/', 'help.html');
}
?>
<style>
/* Hausstandard, wortgetreu aus VORLAGE_hausstandard.css.html uebernommen.
   Nicht neu erfinden: der Knopf-Fehler vom 30.07.2026 steckte in sieben
   Plugins gleichzeitig, weil jedes seine eigene Kopie hatte. */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
/* Bedienelemente werden von jQuery Mobile umgebaut und bekommen einen eigenen
   Behaelter. Begrenzt man das Feld selbst, bleibt der Behaelter breit. */
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; white-space: pre-wrap; font-family: Consolas, "Courier New", monospace; }
.sm-log { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.82em;
    overflow: auto; margin: 8px 0; max-height: 420px; white-space: pre-wrap;
    font-family: Consolas, "Courier New", monospace; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
/* LoxBerry bringt jQuery Mobile mit. Das formatiert JEDES <button> mit eigenem
   Hintergrund UND eigenen Hover-Regeln. Ohne !important steht weisse Schrift
   auf hellgrauem Grund - und beim Ueberfahren weiss auf weiss. */
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
/* Statuskacheln - bewusst ein anderer Name als sm-knopfreihe. */
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
/* Eigene Hover- und Fokusfarben je Gruppe - sonst uebernimmt der Rahmen. */
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
/* Reiterinhalte: nur der aktive ist sichtbar. sm-active gehoert schon ins
   ausgelieferte HTML, sonst ist die Seite ohne JavaScript vollstaendig leer. */
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
</style>

<div class="sm-wrap">

<?php if ($gv_meldungen) { ?>
<div class="sm-hinweis"><?= implode('<br>', array_map('gv_e', $gv_meldungen)) ?></div>
<?php } ?>
<?php if ($gv_fehler) { ?>
<div class="sm-warnung"><b><?= gv_e(gv_t('ALLG.BEANSTANDUNG')) ?></b><br><?= implode('<br>', array_map('gv_e', $gv_fehler)) ?></div>
<?php } ?>

<div class="sm-kacheln">
  <div class="sm-kachel"><?= gv_e(gv_t('ALLG.DIENST')) ?>
    <b class="<?= $gv_pid ? 'sm-an' : 'sm-aus' ?>"><?= $gv_pid ? gv_e(gv_t('ALLG.LAEUFT')) : gv_e(gv_t('ALLG.GESTOPPT')) ?></b>
    <span class="sm-hilfe"><?= $gv_pid ? 'PID ' . (int) $gv_pid : gv_e(gv_t('ALLG.KEINE_PID')) ?></span>
  </div>
  <div class="sm-kachel"><?= gv_e(gv_t('ALLG.GERAETE')) ?>
    <b><?= count($gv_geraete) ?></b>
    <span class="sm-hilfe"><?php
      $gv_ok = 0;
      foreach ($gv_werte as $gv_w) { if (!empty($gv_w['ok'])) { $gv_ok++; } }
      echo (int) $gv_ok . ' ' . gv_e(gv_t('ALLG.ERREICHBAR'));
    ?></span>
  </div>
  <div class="sm-kachel"><?= gv_e(gv_t('ALLG.LETZTER_ABRUF')) ?>
    <b><?= $gv_alter < 0 ? '&ndash;' : (int) $gv_alter ?></b>
    <span class="sm-hilfe"><?= $gv_alter < 0 ? gv_e(gv_t('ALLG.NIE')) : gv_e(gv_t('ALLG.SEKUNDEN')) ?></span>
  </div>
  <div class="sm-kachel">MQTT
    <b class="<?= $gv_mqtt['autostart'] ? 'sm-an' : 'sm-aus' ?>"><?= $gv_mqtt['autostart'] ? gv_e(gv_t('ALLG.EIN')) : gv_e(gv_t('ALLG.AUS')) ?></b>
    <span class="sm-hilfe"><?= gv_e(gv_t('ALLG.GATEWAY')) ?></span>
  </div>
</div>

<?php if (!empty($gv_zustand['fehler'])) { ?>
<div class="sm-warnung"><b><?= gv_e(gv_t('ALLG.LETZTE_STOERUNG')) ?></b> <?= gv_e($gv_zustand['fehler']) ?></div>
<?php } ?>

<?php foreach ($gv_werte as $gv_nr => $gv_w) { ?>
<div class="sm-hinweis">
<b><?= gv_e($gv_w['name']) ?></b> (<?= gv_e(gv_t('ALLG.GERAET')) ?> <?= gv_e($gv_nr) ?>,
<span class="sm-mono"><?= gv_e($gv_w['ip'] !== '' ? $gv_w['ip'] : $gv_w['art']) ?></span>)
&middot; <?= gv_e(gv_t('ALLG.ZUSTAND')) ?>
<b class="<?= !empty($gv_w['an']) ? 'sm-an' : 'sm-aus' ?>"><?= $gv_w['an'] === null ? '&ndash;' : ($gv_w['an'] ? gv_e(gv_t('ALLG.EIN')) : gv_e(gv_t('ALLG.AUS'))) ?></b>
&middot; <?= gv_e(gv_t('ALLG.HELL')) ?> <?= $gv_w['hell'] === null ? '&ndash;' : gv_e($gv_w['hell']) . ' %' ?>
&middot; <?= gv_e(gv_t('ALLG.KELVIN')) ?> <?= empty($gv_w['kelvin']) ? '&ndash;' : gv_e($gv_w['kelvin']) . ' K' ?>
&middot; <?= gv_e(gv_t('ALLG.FARBE')) ?>
<?php if (!empty($gv_w['hex'])) { ?>
<span class="sm-mono">#<?= gv_e($gv_w['hex']) ?></span>
<i style="display:inline-block;width:14px;height:14px;border-radius:3px;border:1px solid #bbb;background:#<?= gv_e($gv_w['hex']) ?>;"></i>
<?php } else { ?>&ndash;<?php } ?>
<?php if (empty($gv_w['ok'])) { ?>
<div class="sm-hilfe"><?= gv_e(gv_t('ALLG.VERALTET')) ?>
<?= $gv_w['alter'] < 0 ? gv_e(gv_t('ALLG.NIE')) : ((int) $gv_w['alter'] . ' ' . gv_e(gv_t('ALLG.SEKUNDEN'))) ?></div>
<?php } ?>
</div>
<?php } ?>

<!-- Reiterleiste: echte Links, JavaScript faengt den Klick ab. So bleibt jeder
     Reiter verlinkbar und Eingaben in anderen Reitern gehen nicht verloren.
     Welcher Reiter offen ist, entscheidet der SERVER - sm-active steht schon
     im ausgelieferten HTML, an der Leiste und am Bereich. -->
<div class="sm-tabs">
<?php foreach ($gv_reiter as $gv_k => $gv_schl): $gv_id = 'tab-' . $gv_k; ?>
	<a class="sm-tab<?= $gv_tab === $gv_id ? ' sm-active' : '' ?>" data-ziel="<?= gv_e($gv_id) ?>"
	   href="index.php?form=<?= gv_e($gv_k) ?>"><?= $gv_schl === null ? 'MQTT' : gv_e(gv_t($gv_schl)) ?></a>
<?php endforeach; ?>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $gv_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">

<h2><?= gv_e(gv_t('EINST.H_DIENST')) ?></h2>
<p class="sm-hilfe"><?= gv_t('EINST.DIENST_ERKLAERUNG') ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= gv_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= gv_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="dienst" value="start"><?= gv_e(gv_t('EINST.K_START')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="restart"><?= gv_e(gv_t('EINST.K_NEUSTART')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="stop"><?= gv_e(gv_t('EINST.K_STOPP')) ?></button>
  </form>
</div>

<h2><?= gv_e(gv_t('EINST.H_SUCHE')) ?></h2>
<div class="sm-hinweis"><?= gv_t('EINST.SUCHE_ERKLAERUNG') ?></div>
<?php if (!empty($gv_such['liste'])) { ?>
<table class="sm-tbl">
<tr><th><?= gv_e(gv_t('EINST.T_IP')) ?></th><th><?= gv_e(gv_t('EINST.T_SKU')) ?></th>
    <th><?= gv_e(gv_t('EINST.T_DEVICE')) ?></th><th><?= gv_e(gv_t('EINST.T_FIRMWARE')) ?></th></tr>
<?php foreach ($gv_such['liste'] as $gv_f) { ?>
<tr><td><span class="sm-mono"><?= gv_e($gv_f['ip']) ?></span></td>
    <td><?= gv_e($gv_f['sku']) ?></td>
    <td><span class="sm-mono"><?= gv_e($gv_f['device']) ?></span></td>
    <td><?= gv_e($gv_f['hardware']) ?> / <?= gv_e($gv_f['software']) ?></td></tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?= sprintf(gv_e(gv_t('EINST.SUCHE_STAND')), date('d.m.Y H:i', (int) $gv_such['ts'])) ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= gv_t('LEGENDE.TECHNIK') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="uebernehmen" value="1"><?= gv_e(gv_t('EINST.K_UEBERNEHMEN')) ?></button>
  </form>
</div>
<?php } else { ?>
<p class="sm-hilfe"><?= gv_t('EINST.SUCHE_LEER') ?></p>
<?php } ?>

<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="speichern" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?= gv_e(gv_t('EINST.H_GERAETE')) ?></h2>
<div class="sm-hinweis"><?= gv_t('EINST.GERAETE_ERKLAERUNG') ?></div>
<table class="sm-tbl">
<tr><th style="width:28px;">#</th><th><?= gv_e(gv_t('EINST.T_NAME')) ?></th>
    <th style="width:90px;"><?= gv_e(gv_t('EINST.T_ART')) ?></th>
    <th><?= gv_e(gv_t('EINST.T_IP')) ?></th>
    <th style="width:80px;"><?= gv_e(gv_t('EINST.T_SKU')) ?></th>
    <th><?= gv_e(gv_t('EINST.T_DEVICE')) ?></th>
    <th style="width:70px;"><?= gv_e(gv_t('EINST.T_PIXEL')) ?></th>
    <th style="width:70px;"><?= gv_e(gv_t('EINST.T_KMIN')) ?></th>
    <th style="width:70px;"><?= gv_e(gv_t('EINST.T_KMAX')) ?></th></tr>
<?php
$gv_roh = isset($gv_cfg['geraete']) && is_array($gv_cfg['geraete']) ? $gv_cfg['geraete'] : array();
for ($gv_i = 0; $gv_i < 8; $gv_i++) {
    $gv_z = isset($gv_roh[$gv_i]) && is_array($gv_roh[$gv_i]) ? $gv_roh[$gv_i] : array();
    $gv_v = function ($k) use ($gv_z) { return isset($gv_z[$k]) ? (string) $gv_z[$k] : ''; };
?>
<tr>
<td><?= $gv_i + 1 ?></td>
<td><input data-role="none" type="text" name="g_name[]" value="<?= gv_e($gv_v('name')) ?>" size="14"></td>
<td><select data-role="none" name="g_art[]">
    <option value="lan"<?= $gv_v('art') !== 'cloud' ? ' selected' : '' ?>>LAN</option>
    <option value="cloud"<?= $gv_v('art') === 'cloud' ? ' selected' : '' ?>>Cloud</option>
</select></td>
<td><input data-role="none" type="text" name="g_ip[]" value="<?= gv_e($gv_v('ip')) ?>" size="14" placeholder="<?= $gv_i === 0 ? '192.168.1.50' : '' ?>"></td>
<td><input data-role="none" type="text" name="g_sku[]" value="<?= gv_e($gv_v('sku')) ?>" size="7" placeholder="<?= $gv_i === 0 ? 'H61A8' : '' ?>"></td>
<td><input data-role="none" type="text" name="g_device[]" value="<?= gv_e($gv_v('device')) ?>" size="18"></td>
<td><input data-role="none" type="text" name="g_pixel[]" value="<?= gv_e($gv_v('pixel')) ?>" size="3"></td>
<td><input data-role="none" type="text" name="g_kmin[]" value="<?= gv_e($gv_v('kmin')) ?>" size="4" placeholder="2700"></td>
<td><input data-role="none" type="text" name="g_kmax[]" value="<?= gv_e($gv_v('kmax')) ?>" size="4" placeholder="6500"></td>
</tr>
<?php } ?>
</table>
<div class="sm-hilfe"><?= gv_t('EINST.GERAETE_HILFE') ?></div>

<h2><?= gv_e(gv_t('EINST.H_TAKT')) ?></h2>
<div class="sm-feld">
  <label for="intervall"><?= gv_e(gv_t('EINST.L_INTERVALL')) ?></label>
  <input data-role="none" type="number" id="intervall" name="intervall" value="<?= gv_e($gv_cfg['intervall']) ?>" min="5" max="3600">
  <div class="sm-hilfe"><?= gv_t('EINST.H_INTERVALL') ?></div>
</div>
<div class="sm-feld">
  <label for="suchtakt"><?= gv_e(gv_t('EINST.L_SUCHTAKT')) ?></label>
  <input data-role="none" type="number" id="suchtakt" name="suchtakt" value="<?= gv_e($gv_cfg['suchtakt']) ?>" min="0" max="1440">
  <div class="sm-hilfe"><?= gv_t('EINST.H_SUCHTAKT') ?></div>
</div>
<div class="sm-feld">
  <label for="wartezeit"><?= gv_e(gv_t('EINST.L_WARTEZEIT')) ?></label>
  <input data-role="none" type="number" id="wartezeit" name="wartezeit" value="<?= gv_e($gv_cfg['wartezeit']) ?>" min="0" max="10">
  <div class="sm-hilfe"><?= gv_t('EINST.H_WARTEZEIT') ?></div>
</div>

<h2><?= gv_e(gv_t('EINST.H_STEUERUNG')) ?></h2>
<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="steuerung_ein" value="1"<?= !empty($gv_cfg['steuerung_ein']) ? ' checked' : '' ?>>
  <?= gv_e(gv_t('EINST.L_STEUERUNG')) ?></label>
  <div class="sm-hilfe"><?= gv_t('EINST.H_STEUERUNG_HILFE') ?></div>
</div>
<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="pt_frei" value="1"<?= !empty($gv_cfg['pt_frei']) ? ' checked' : '' ?>>
  <?= gv_e(gv_t('EINST.L_PTFREI')) ?></label>
  <div class="sm-hilfe"><?= gv_t('EINST.H_PTFREI') ?></div>
</div>

<?php /* MQTT stand hier bis zu dieser Fassung. Es wohnt jetzt
         vollstaendig im Reiter MQTT - eine Sache, eine Stelle. */ ?>

<h2><?= gv_e(gv_t('EINST.H_CLOUD')) ?></h2>
<div class="sm-warnung"><?= gv_t('EINST.CLOUD_WARNUNG') ?></div>
<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="cloud_ein" value="1"<?= !empty($gv_cfg['cloud_ein']) ? ' checked' : '' ?>>
  <?= gv_e(gv_t('EINST.L_CLOUD')) ?></label>
</div>
<div class="sm-feld">
  <label for="cloud_key"><?= gv_e(gv_t('EINST.L_KEY')) ?></label>
  <input data-role="none" type="password" id="cloud_key" name="cloud_key" value="" autocomplete="new-password"
         placeholder="<?= trim((string) $gv_geheim['cloud_key']) !== ''
             ? gv_e(sprintf(gv_t('EINST.KEY_HINTERLEGT'), strlen(trim((string) $gv_geheim['cloud_key']))))
             : gv_e(gv_t('EINST.KEY_LEER')) ?>">
  <div class="sm-hilfe"><?= gv_t('EINST.H_KEY') ?></div>
</div>
<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="cloud_key_loeschen" value="1">
  <?= gv_e(gv_t('EINST.L_KEY_LOESCHEN')) ?></label>
</div>
<div class="sm-feld">
  <label for="cloud_takt"><?= gv_e(gv_t('EINST.L_CLOUD_TAKT')) ?></label>
  <input data-role="none" type="number" id="cloud_takt" name="cloud_takt" value="<?= gv_e($gv_cfg['cloud_takt']) ?>" min="1" max="1440">
  <div class="sm-hilfe"><?= gv_t('EINST.H_CLOUD_TAKT') ?></div>
</div>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= gv_t('LEGENDE.AKTION_SPEICHERN') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= gv_e(gv_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $gv_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">

<h2>MQTT</h2>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="save_mqtt" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="mqtt_ein" value="1"<?= !empty($gv_cfg['mqtt_ein']) ? ' checked' : '' ?>>
  <?= gv_e(gv_t('EINST.L_MQTT')) ?></label>
</div>
<div class="sm-feld">
  <label for="mqtt_topic"><?= gv_e(gv_t('EINST.L_TOPIC')) ?></label>
  <input data-role="none" type="text" id="mqtt_topic" name="mqtt_topic" value="<?= gv_e($gv_cfg['mqtt_topic']) ?>">
  <div class="sm-hilfe"><?= gv_t('EINST.H_TOPIC') ?></div>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= gv_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= gv_e(gv_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
<h2><?= gv_e(gv_t('MQTT.H_ZUSTAND')) ?></h2>
<p class="sm-hilfe"><?= gv_t('MQTT.ERKLAERUNG') ?></p>
<table class="sm-tbl">
<tr><th style="width:38%;"><?= gv_e(gv_t('ALLG.EIGENSCHAFT')) ?></th><th><?= gv_e(gv_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= gv_e(gv_t('MQTT.T_GEFUNDEN')) ?></td>
    <td><?= $gv_mqtt['gefunden'] ? gv_e(gv_t('ALLG.JA')) : gv_e(gv_t('ALLG.NEIN')) ?></td></tr>
<tr><td><?= gv_e(gv_t('MQTT.T_AUTOSTART')) ?></td>
    <td class="<?= $gv_mqtt['autostart'] ? 'sm-an' : 'sm-aus' ?>"><?= $gv_mqtt['autostart'] ? gv_e(gv_t('ALLG.JA')) : gv_e(gv_t('ALLG.NEIN')) ?></td></tr>
<tr><td><?= gv_e(gv_t('MQTT.T_BROKER')) ?></td>
    <td><span class="sm-mono"><?= gv_e($gv_mqtt['broker']) ?>:<?= gv_e($gv_mqtt['brokerport']) ?></span></td></tr>
<tr><td><?= gv_e(gv_t('MQTT.T_UDP')) ?></td>
    <td><span class="sm-mono"><?= (int) $gv_mqtt['udpport'] ?></span></td></tr>
</table>
<?php if (!$gv_mqtt['autostart']) { ?>
<div class="sm-warnung"><?= gv_t('MQTT.WARNUNG_AUS') ?></div>
<?php } ?>

<h2><?= gv_e(gv_t('MQTT.H_ABO')) ?></h2>
<div class="sm-step">
<?= gv_t('MQTT.ABO_TEXT') ?>
<div class="sm-pre"><?= gv_e($gv_thema) ?>/#</div>
<b><?= gv_t('MQTT.ABO_WARNUNG') ?></b>
</div>

<h2><?= gv_e(gv_t('MQTT.H_THEMEN')) ?></h2>
<p class="sm-hilfe"><?= gv_t('MQTT.THEMEN_ERKLAERUNG') ?></p>
<table class="sm-tbl">
<tr><th style="width:40%;"><?= gv_e(gv_t('MQTT.T_THEMA')) ?></th><th><?= gv_e(gv_t('MQTT.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (gv_mqtt_themen() as $gv_thm => $gv_schl) { ?>
<tr><td><span class="sm-mono"><?= gv_e($gv_thema . '/' . $gv_thm) ?></span></td>
    <td><?= gv_t($gv_schl) ?></td></tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?= gv_t('MQTT.N_ERKLAERUNG') ?></p>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $gv_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= gv_e(gv_t('LOX.H_TITEL')) ?></h2>

<div class="sm-step"><b>1. <?= gv_e(gv_t('LOX.S1_T')) ?></b><br><?= gv_t('LOX.S1') ?></div>

<div class="sm-step"><b>2. <?= gv_e(gv_t('LOX.S2_T')) ?></b><br>
<?= gv_t('LOX.S2') ?>
<div class="sm-pre"><?= gv_e($gv_thema) ?>/#</div>
<b><?= gv_t('MQTT.ABO_WARNUNG') ?></b>
</div>

<div class="sm-step"><b>3. <?= gv_e(gv_t('LOX.S3_T')) ?></b><br>
<?= gv_t('LOX.S3') ?>
<table class="sm-tbl">
<tr><th><?= gv_e(gv_t('LOX.T_TITEL')) ?></th><th><?= gv_e(gv_t('LOX.T_EINHEIT')) ?></th>
    <th><?= gv_e(gv_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<?php
$gv_felder = gv_status_felder();
if ($gv_geraete) {
    foreach ($gv_geraete as $gv_nr => $gv_g) {
        foreach ($gv_felder as $gv_f => $gv_info) {
?>
<tr><td><span class="sm-mono">GOVEE_<?= (int) $gv_nr ?>_<?= gv_e($gv_f) ?></span></td>
    <td><?= gv_e($gv_info[0]) ?></td>
    <td><?= gv_e($gv_g['name']) ?>: <?= gv_t($gv_info[3]) ?></td></tr>
<?php   }
    }
} else { ?>
<tr><td colspan="3"><?= gv_t('LOX.KEINE_GERAETE') ?></td></tr>
<?php } ?>
</table>
<b><?= gv_t('LOX.IMPORT_WARNUNG') ?></b>
</div>

<div class="sm-step"><b>4. <?= gv_e(gv_t('LOX.S4_T')) ?></b><br>
<?= gv_t('LOX.S4') ?>
<table class="sm-tbl">
<tr><th style="width:22%;"><?= gv_e(gv_t('LOX.T_AKTION')) ?></th><th><?= gv_e(gv_t('LOX.T_ADRESSE')) ?></th></tr>
<tr><td><span class="sm-mono">status</span></td>
    <td><span class="sm-mono"><?= gv_e($gv_basis) ?>?token=<?= gv_e($gv_token) ?>&amp;aktion=status&amp;geraet=1</span></td></tr>
<tr><td><span class="sm-mono">ein</span> / <span class="sm-mono">aus</span></td>
    <td><span class="sm-mono">…?token=…&amp;aktion=ein&amp;geraet=1</span></td></tr>
<tr><td><span class="sm-mono">hell</span></td>
    <td><span class="sm-mono">…&amp;aktion=hell&amp;geraet=1&amp;wert=60</span> (1&ndash;100)</td></tr>
<tr><td><span class="sm-mono">kelvin</span></td>
    <td><span class="sm-mono">…&amp;aktion=kelvin&amp;geraet=1&amp;wert=2700</span></td></tr>
<tr><td><span class="sm-mono">farbe</span></td>
    <td><span class="sm-mono">…&amp;aktion=farbe&amp;geraet=1&amp;hex=ff8800</span></td></tr>
<tr><td><span class="sm-mono">szene</span></td>
    <td><span class="sm-mono">…&amp;aktion=szene&amp;geraet=1&amp;name=h70c4_kerzenlicht</span></td></tr>
<tr><td><span class="sm-mono">balken</span></td>
    <td><span class="sm-mono">…&amp;aktion=balken&amp;geraet=1&amp;wert=70&amp;hex=646400</span></td></tr>
<tr><td><span class="sm-mono">segment</span></td>
    <td><span class="sm-mono">…&amp;aktion=segment&amp;geraet=1&amp;segmente=1-3:ff0000,7:00ff00</span><br>
        <span class="sm-mono">…&amp;verfahren=maske</span> <?= gv_t('LOX.SEGMENT_MASKE') ?></td></tr>
<tr><td><span class="sm-mono">abruf</span></td>
    <td><span class="sm-mono">…&amp;aktion=abruf</span></td></tr>
</table>
<?= gv_t('LOX.S4_ODER') ?>
</div>

<div class="sm-step"><b>5. <?= gv_e(gv_t('LOX.S5_T')) ?></b><br><?= gv_t('LOX.S5') ?></div>

<h3><?= gv_e(gv_t('LOX.H_VORLAGEN')) ?></h3>
<p class="sm-hilfe"><?= gv_t('LOX.VORLAGEN_ERKLAERUNG') ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= gv_t('LEGENDE.TECHNIK_XML') ?></span>
</div>
<?php if ($gv_geraete) { foreach ($gv_geraete as $gv_nr => $gv_g) { ?>
<h3><?= gv_e($gv_g['name']) ?> <span class="sm-mono"><?= gv_e($gv_g['ip'] !== '' ? $gv_g['ip'] : $gv_g['art']) ?></span></h3>
<div class="sm-knopfreihe">
<?php if ($gv_g['art'] === 'lan') { ?>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="vorlage_nr" value="<?= (int) $gv_nr ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage" value="ausgang"><?= gv_e(gv_t('LOX.K_VQ')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="vorlage_nr" value="<?= (int) $gv_nr ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage" value="szenen"><?= gv_e(gv_t('LOX.K_VQ_SZENEN')) ?></button>
  </form>
<?php } ?>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="vorlage_nr" value="<?= (int) $gv_nr ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage" value="eingang"><?= gv_e(gv_t('LOX.K_VI')) ?></button>
  </form>
</div>
<?php } } else { ?>
<div class="sm-hinweis"><?= gv_t('LOX.KEINE_GERAETE') ?></div>
<?php } ?>

<h3><?= gv_e(gv_t('LOX.H_TOKEN')) ?></h3>
<p class="sm-hilfe"><?= gv_t('LOX.TOKEN_ERKLAERUNG') ?></p>
<div class="sm-pre"><?= gv_e($gv_token) ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= gv_t('LEGENDE.AKTION_TOKEN') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= gv_e(gv_t('LOX.K_TOKEN_NEU')) ?></button>
  </form>
</div>

<div class="sm-step"><b>6. <?= gv_e(gv_t('LOX.S6_T')) ?></b><br>
<?= gv_t('LOX.S6') ?>
<table class="sm-tbl">
<tr><th style="width:28px;">#</th><th><?= gv_e(gv_t('LOX.B_TYP')) ?></th><th><?= gv_e(gv_t('LOX.B_NAME')) ?></th>
    <th><?= gv_e(gv_t('LOX.B_PARAM')) ?></th><th><?= gv_e(gv_t('LOX.B_EIN')) ?></th></tr>
<tr><td>1</td><td><?= gv_t('LOX.B1_TYP') ?></td><td><span class="sm-mono">Govee Licht</span></td>
    <td><?= gv_t('LOX.B1_PARAM') ?></td><td><?= gv_t('LOX.B1_EIN') ?></td></tr>
<tr><td>2</td><td><?= gv_t('LOX.B2_TYP') ?></td><td><span class="sm-mono">Govee Ein/Aus</span></td>
    <td><?= gv_t('LOX.B2_PARAM') ?></td><td><?= gv_t('LOX.B2_EIN') ?></td></tr>
<tr><td>3</td><td><?= gv_t('LOX.B3_TYP') ?></td><td><span class="sm-mono">Govee Helligkeit</span></td>
    <td><?= gv_t('LOX.B3_PARAM') ?></td><td><?= gv_t('LOX.B3_EIN') ?></td></tr>
<tr><td>4</td><td><?= gv_t('LOX.B4_TYP') ?></td><td><span class="sm-mono">Govee Farbtemperatur</span></td>
    <td><?= gv_t('LOX.B4_PARAM') ?></td><td><?= gv_t('LOX.B4_EIN') ?></td></tr>
<tr><td>5</td><td><?= gv_t('LOX.B5_TYP') ?></td><td><span class="sm-mono">Govee Szene</span></td>
    <td><?= gv_t('LOX.B5_PARAM') ?></td><td><?= gv_t('LOX.B5_EIN') ?></td></tr>
<tr><td>6</td><td><?= gv_t('LOX.B6_TYP') ?></td><td><span class="sm-mono">Govee Balken PV</span></td>
    <td><?= gv_t('LOX.B6_PARAM') ?></td><td><?= gv_t('LOX.B6_EIN') ?></td></tr>
<tr><td>7</td><td><?= gv_t('LOX.B7_TYP') ?></td><td><span class="sm-mono">Govee Ausfall</span></td>
    <td><?= gv_t('LOX.B7_PARAM') ?></td><td><?= gv_t('LOX.B7_EIN') ?></td></tr>
<tr><td>8</td><td><?= gv_t('LOX.B8_TYP') ?></td><td><span class="sm-mono">Govee Meldung</span></td>
    <td><?= gv_t('LOX.B8_PARAM') ?></td><td><?= gv_t('LOX.B8_EIN') ?></td></tr>
</table>
<p><b><?= gv_e(gv_t('LOX.ZU_TITEL')) ?></b></p>
<p><?= gv_t('LOX.ZU_3') ?></p>
<p><?= gv_t('LOX.ZU_6') ?></p>
<p><?= gv_t('LOX.ZU_8') ?></p>
</div>

<div class="sm-step"><b>7. <?= gv_e(gv_t('LOX.S7_T')) ?></b><br><?= gv_t('LOX.S7') ?></div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $gv_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?= gv_e(gv_t('TEST.H_SELBSTPRUEFUNG')) ?></h2>
<p class="sm-hilfe"><?= gv_t('TEST.EINLEITUNG') ?></p>
<table class="sm-tbl">
<tr><th style="width:36px;">&nbsp;</th><th style="width:32%;"><?= gv_e(gv_t('TEST.T_FRAGE')) ?></th><th><?= gv_e(gv_t('TEST.T_BEFUND')) ?></th></tr>
<?php foreach (gv_pruefungen() as $gv_z) { ?>
<tr><td style="text-align:center;"><?php
    if ($gv_z['stand'] === 1) { echo '<span class="sm-an">&#10004;</span>'; }
    elseif ($gv_z['stand'] === 0) { echo '<span class="sm-aus">&#10008;</span>'; }
    else { echo '<span style="color:#888;">&#9679;</span>'; }
?></td><td><?= $gv_z['frage'] ?></td><td><?= $gv_z['antwort'] ?></td></tr>
<?php } ?>
</table>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= gv_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= gv_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= gv_t('LEGENDE.AKTION') ?></span>
</div>

<h3><?= gv_e(gv_t('TEST.H_LESEN')) ?></h3>
<div class="sm-knopfreihe">
  <a data-role="none" class="sm-btn sm-b-lesen" href="<?= gv_e($gv_basis) ?>?token=<?= gv_e($gv_token) ?>&amp;aktion=status&amp;geraet=1" target="_blank"><?= gv_e(gv_t('TEST.K_STATUS')) ?></a>
  <a data-role="none" class="sm-btn sm-b-lesen" href="<?= gv_e($gv_basis) ?>?token=<?= gv_e($gv_token) ?>&amp;aktion=liste" target="_blank"><?= gv_e(gv_t('TEST.K_LISTE')) ?></a>
  <a data-role="none" class="sm-btn sm-b-lesen" href="<?= gv_e($gv_basis) ?>?token=<?= gv_e($gv_token) ?>&amp;aktion=szenen" target="_blank"><?= gv_e(gv_t('TEST.K_SZENENLISTE')) ?></a>
</div>

<h3><?= gv_e(gv_t('TEST.H_TECHNIK')) ?></h3>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="selbsttest" value="1"><?= gv_e(gv_t('TEST.K_SELBSTTEST')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="suche"><?= gv_e(gv_t('TEST.K_SUCHE')) ?></button>
  </form>
  <a data-role="none" class="sm-btn sm-b-technik" href="<?= gv_e($gv_basis) ?>?token=<?= gv_e($gv_token) ?>&amp;aktion=roh" target="_blank"><?= gv_e(gv_t('TEST.K_ROH')) ?></a>
</div>
<?php if ($gv_testausgabe !== '') { ?>
<div class="sm-pre"><?= gv_e($gv_testausgabe) ?></div>
<?php } ?>

<h3><?= gv_e(gv_t('TEST.H_SCHALTEN')) ?></h3>
<div class="sm-warnung"><?= gv_t('TEST.SCHALTEN_WARNUNG') ?></div>
<?php if (empty($gv_cfg['steuerung_ein'])) { ?>
<div class="sm-hinweis"><?= gv_t('TEST.SCHALTEN_GESPERRT') ?></div>
<?php } ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<div class="sm-feld">
  <label for="test_geraet"><?= gv_e(gv_t('TEST.L_GERAET')) ?></label>
  <select data-role="none" id="test_geraet" name="test_geraet">
<?php foreach ($gv_geraete as $gv_nr => $gv_g) { ?>
    <option value="<?= (int) $gv_nr ?>"><?= (int) $gv_nr ?> &ndash; <?= gv_e($gv_g['name']) ?></option>
<?php } ?>
  </select>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="abruf"><?= gv_e(gv_t('TEST.K_ABRUF')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="ein"><?= gv_e(gv_t('TEST.K_EIN')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="aus"><?= gv_e(gv_t('TEST.K_AUS')) ?></button>
</div>

<div class="sm-feld">
  <label for="test_hell"><?= gv_e(gv_t('TEST.L_HELL')) ?></label>
  <input data-role="none" type="number" id="test_hell" name="test_hell" value="60" min="1" max="100">
</div>
<div class="sm-feld">
  <label for="test_kelvin"><?= gv_e(gv_t('TEST.L_KELVIN')) ?></label>
  <input data-role="none" type="number" id="test_kelvin" name="test_kelvin" value="2700" min="1000" max="10000" step="100">
</div>
<div class="sm-feld">
  <label for="test_hex"><?= gv_e(gv_t('TEST.L_HEX')) ?></label>
  <input data-role="none" type="text" id="test_hex" name="test_hex" value="ff8800" size="8">
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="hell"><?= gv_e(gv_t('TEST.K_HELL')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="kelvin"><?= gv_e(gv_t('TEST.K_KELVIN')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="farbe"><?= gv_e(gv_t('TEST.K_FARBE')) ?></button>
</div>

<div class="sm-feld">
  <label for="test_szene"><?= gv_e(gv_t('TEST.L_SZENE')) ?></label>
  <select data-role="none" id="test_szene" name="test_szene">
<?php foreach (gv_szenen() as $gv_s => $gv_sz) { ?>
    <option value="<?= gv_e($gv_s) ?>"><?= gv_e(gv_t($gv_sz['name'])) ?> &ndash; <?= gv_e($gv_sz['sku']) ?> (<?= count($gv_sz['cmd']) ?>)</option>
<?php } ?>
  </select>
  <div class="sm-hilfe"><?= gv_t('TEST.H_SZENE') ?></div>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="szene"><?= gv_e(gv_t('TEST.K_SZENE')) ?></button>
</div>

<div class="sm-feld">
  <label for="test_balken"><?= gv_e(gv_t('TEST.L_BALKEN')) ?></label>
  <input data-role="none" type="number" id="test_balken" name="test_balken" value="70" min="0" max="100">
</div>
<div class="sm-feld">
  <label for="test_balkenfarbe"><?= gv_e(gv_t('TEST.L_BALKENFARBE')) ?></label>
  <input data-role="none" type="text" id="test_balkenfarbe" name="test_balkenfarbe" value="646400" size="8">
  <div class="sm-hilfe"><?= gv_t('TEST.H_BALKENFARBE') ?></div>
</div>
<?php
$gv_vorschau_pixel = 14;
foreach ($gv_geraete as $gv_g) { if ($gv_g['pixel'] > 0) { $gv_vorschau_pixel = $gv_g['pixel']; break; } }
?>
<div><?= gv_balken_svg(70, $gv_vorschau_pixel, '646400') ?></div>
<div class="sm-hilfe"><?= sprintf(gv_t('TEST.H_VORSCHAU'), (int) $gv_vorschau_pixel) ?></div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="balken"><?= gv_e(gv_t('TEST.K_BALKEN')) ?></button>
</div>

<div class="sm-feld">
  <label for="test_segmente"><?= gv_e(gv_t('TEST.L_SEGMENTE')) ?></label>
  <input data-role="none" type="text" id="test_segmente" name="test_segmente" value="1-3:ff0000,7:00ff00" size="36">
  <div class="sm-hilfe"><?= gv_t('TEST.H_SEGMENTE') ?></div>
</div>
<div class="sm-feld">
  <label for="test_verfahren"><?= gv_e(gv_t('TEST.L_VERFAHREN')) ?></label>
  <select data-role="none" id="test_verfahren" name="test_verfahren">
<?php foreach (gv_segmentverfahren() as $gv_vf => $gv_vs) { ?>
    <option value="<?= gv_e($gv_vf) ?>"><?= gv_e(gv_t($gv_vs)) ?></option>
<?php } ?>
  </select>
  <div class="sm-hilfe"><?= gv_t('TEST.H_VERFAHREN') ?></div>
</div>
<div class="sm-feld">
  <label for="test_bewegung"><?= gv_e(gv_t('TEST.L_BEWEGUNG')) ?></label>
  <select data-role="none" id="test_bewegung" name="test_bewegung">
<?php foreach (gv_bewegungsmodi() as $gv_bw => $gv_bs) { ?>
    <option value="<?= (int) $gv_bw ?>"<?= $gv_bw === 0x09 ? ' selected' : '' ?>><?= gv_e(gv_t($gv_bs)) ?></option>
<?php } ?>
  </select>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="segment"><?= gv_e(gv_t('TEST.K_SEGMENT')) ?></button>
</div>
</form>

<div class="sm-warnung"><b><?= gv_e(gv_t('TEST.H_UNGEPRUEFT')) ?></b><br><?= gv_t('TEST.UNGEPRUEFT') ?></div>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?= $gv_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?= gv_e(gv_t('LOG.H_TITEL')) ?></h2>
<?php
if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) {
    echo LBWeb::loglist_html();
}
?>
<p class="sm-hilfe"><?= gv_t('LOG.ERKLAERUNG') ?><br>
<span class="sm-mono"><?= gv_e($gv_p['log']) ?></span></p>
<?php if ($gv_logzeilen) { ?>
<div class="sm-log"><?= gv_e(implode("\n", $gv_logzeilen)) ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= gv_t('LOG.LEER') ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= gv_t('LEGENDE.AKTION_LOG') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="log_leeren" value="1"><?= gv_e(gv_t('LOG.K_LEEREN')) ?></button>
  </form>
</div>
</div>

</div><!-- /sm-wrap -->

<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	// Der Server hat sm-active bereits gesetzt; dieser Aufruf richtet nur die
	// versteckten activetab-Felder aus und ist ansonsten wirkungslos.
	zeige(<?= json_encode($gv_tab) ?>);
})();
</script>
<?php
if ($gv_rahmen) {
    LBWeb::lbfooter();
}
