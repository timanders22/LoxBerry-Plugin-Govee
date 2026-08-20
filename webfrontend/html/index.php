<?php
/**
 * Govee - Endpunkt fuer den Miniserver
 *
 * Liegt im unangemeldeten Bereich, damit Loxone ihn ohne Zugangsdaten
 * erreicht, und ist deshalb durch ein Token geschuetzt. Verglichen wird mit
 * hash_equals, also in gleichbleibender Zeit - ein einfaches == liesse sich
 * ueber die Antwortzeit Zeichen fuer Zeichen erraten.
 *
 *   /plugins/<ordner>/index.php?token=<TOKEN>&aktion=<Befehl>
 *
 * Lesend:
 *   status  [&geraet=N]   Zustand einer Leuchte
 *   liste                 alle eingerichteten Leuchten
 *   szenen                der Szenenkatalog
 *   roh                   vollstaendiges Abbild als JSON
 *
 * Schaltend (nur wenn im Reiter Einstellungen zugelassen):
 *   ein      [&geraet=N]
 *   aus      [&geraet=N]
 *   hell     &wert=1..100
 *   kelvin   &wert=<Bereich des Geraets>
 *   farbe    &hex=RRGGBB
 *   szene    &name=<Schluessel aus dem Katalog>
 *   balken   &wert=0..100 [&hex=RRGGBB]
 *   segment  &segmente=1-3:ff0000,7:00ff00 [&hg=RRGGBB] [&hgint=0..100]
 *            [&bewegung=<Zahl>] [&geschw=0..100]
 *   musik    &art=<Zahl> [&sens=0..100] [&gruppe=15|12|1|19]
 *   pt       &cmd=<base64>[,<base64>...]      nur wenn ausdruecklich erlaubt
 *   abruf                 sofort abfragen statt auf den Takt zu warten
 *   suche                 Geraetesuche im Netz anstossen
 *
 * Der Endpunkt spricht NIE selbst mit einer Leuchte. Er kann es gar nicht:
 * den Antwortport 4002 haelt der Dienst. Lesende Aufrufe beantwortet er aus
 * dem Zwischenspeicher, schaltende legt er in einer Warteschlange ab.
 *
 * Ein Strich als Wert bedeutet: dieses Feld wurde nicht geliefert. Es wird
 * bewusst keine 0 gesendet - eine 0 waere eine stille Falschaussage, und in
 * der Loxone-App saehe alles normal aus.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/gv_lib.php';
header('Content-Type: text/plain; charset=utf-8');

/* gv_config(false) liest nur. Der unangemeldete Endpunkt darf nichts
 * anlegen und nichts zurueckschreiben - auch keine Selbstheilung. */
$gv_cfg = gv_config(false);

/* ---------------- Token ---------------- */
$gv_soll = (string) $gv_cfg['aktionstoken'];
$gv_ist = isset($_GET['token']) ? (string) $_GET['token'] : '';
if ($gv_soll === '') {
    http_response_code(403);
    echo "FEHLER;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
    echo "Die Plugin-Oberflaeche wurde noch nie geoeffnet - es gibt noch kein Token.\n";
    exit;
}
if (!hash_equals($gv_soll, $gv_ist)) {
    http_response_code(403);
    echo "FEHLER;OK=0;GRUND=TOKEN\n";
    exit;
}

/* ---------------- Aktion (Weissliste) ---------------- */
$gv_lesend = array('status', 'liste', 'szenen', 'roh');
$gv_schaltend = array('ein', 'aus', 'hell', 'kelvin', 'farbe', 'szene', 'balken',
                      'segment', 'musik', 'pt', 'abruf', 'suche');
$gv_aktion = isset($_GET['aktion']) ? (string) $_GET['aktion'] : 'status';
if (!in_array($gv_aktion, array_merge($gv_lesend, $gv_schaltend), true)) {
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=UNBEKANNTE_AKTION\n";
    echo 'Erlaubt sind: ' . implode(', ', array_merge($gv_lesend, $gv_schaltend)) . "\n";
    exit;
}

/* ---------------- Parameter ----------------
 * Was nicht ins Muster passt, wird abgewiesen und gemeldet. Nie Zeichen
 * entfernen, nie zurechtbiegen: ein still umgeschriebener Wert fuehrt zu
 * einer Falschaussage, und die ist schlimmer als ein Fehler.
 */
function gv_param($name, $muster, $vorgabe = '')
{
    if (!isset($_GET[$name]) || $_GET[$name] === '') {
        return $vorgabe;
    }
    $w = (string) $_GET[$name];
    if (!preg_match($muster, $w)) {
        http_response_code(400);
        echo "FEHLER;OK=0;GRUND=PARAMETER\n";
        echo 'Der Wert von ' . $name . " passt nicht ins erlaubte Muster.\n";
        exit;
    }
    return $w;
}

/* "alle" ist zugelassen: beim Lesen ergibt es den Sammelstatus, beim
 * Schalten geht der Befehl an jede eingerichtete Leuchte. */
$gv_nr       = gv_param('geraet',   '/^([0-9]{1,2}|alle)$/', '1');
/* Acht Ziffern, nicht fuenf: eine Farbe als eine Zahl geht bis 16777215.
 * Die Bereiche prueft danach jede Aktion fuer sich. */
$gv_wert     = gv_param('wert',     '/^[0-9]{1,8}$/', '');
$gv_hex      = gv_param('hex',      '/^[0-9a-fA-F]{6}$/', '');
$gv_hg       = gv_param('hg',       '/^[0-9a-fA-F]{6}$/', '');
$gv_hgint    = gv_param('hgint',    '/^[0-9]{1,3}$/', '');
$gv_bewegung = gv_param('bewegung', '/^[0-9]{1,3}$/', '');
$gv_geschw   = gv_param('geschw',   '/^[0-9]{1,3}$/', '');
$gv_art      = gv_param('art',      '/^[0-9]{1,3}$/', '');
$gv_sens     = gv_param('sens',     '/^[0-9]{1,3}$/', '');
$gv_gruppe   = gv_param('gruppe',   '/^(1|12|15|19)$/', '15');
$gv_name     = gv_param('name',     '/^[A-Za-z0-9_]{1,40}$/', '');
$gv_szenennr = gv_param('nr',       '/^[0-9]{1,3}$/', '');
$gv_verf     = gv_param('verfahren', '/^(graffiti|maske)$/', 'graffiti');
$gv_segmente = gv_param('segmente', '/^[0-9a-fA-F:,\-]{1,300}$/', '');
$gv_ptcmd    = gv_param('cmd',      '#^[A-Za-z0-9+/=,]{1,2000}$#', '');

/** Ein Strich statt einer erfundenen 0. Loxone behaelt dann den letzten Wert. */
function gv_w($v)
{
    if ($v === null || $v === '' || !is_numeric($v)) {
        return '-';
    }
    return (string) (0 + $v);
}

$gv_lox = gv_loxone();
$gv_alle = gv_werte();
$gv_alter = gv_alter();
$gv_g = ($gv_nr !== 'alle' && isset($gv_alle[$gv_nr])) ? $gv_alle[$gv_nr] : null;

/* ================= Lesende Aktionen ================= */

if ($gv_aktion === 'roh') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($gv_lox, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($gv_aktion === 'szenen') {
    $gv_k = gv_szenen_alle($gv_cfg);
    echo 'SZENEN;OK=1;N=' . count($gv_k) . "\n";
    foreach ($gv_k as $gv_s => $gv_z) {
        echo $gv_s . ';SKU=' . $gv_z['sku'] . ';PAKETE=' . count($gv_z['cmd'])
           . ';EIGEN=' . (empty($gv_z['eigen']) ? 0 : 1) . "\n";
    }
    exit;
}

if ($gv_aktion === 'liste') {
    echo 'LISTE;OK=' . (int) (!empty($gv_lox['ok'])) . ';N=' . count($gv_alle)
       . ';ALTER=' . $gv_alter . "\n";
    foreach ($gv_alle as $gv_i => $gv_z) {
        echo $gv_i . ';' . $gv_z['name'] . ';' . $gv_z['art'] . ';' . $gv_z['ip'] . ';'
           . 'SKU=' . $gv_z['sku'] . ';PIXEL=' . (int) $gv_z['pixel']
           . ';OK=' . (int) $gv_z['ok'] . "\n";
    }
    exit;
}

if ($gv_aktion === 'status' && $gv_nr === 'alle') {
    /* Ein Abruf fuer alle Leuchten. Die Feldnamen tragen die Geraetenummer
     * vorne, und jede Angabe steht hinter einem Semikolon - der Suchtext in
     * Loxone lautet also \i;G1_AN=\i\v und kann nichts anderes treffen. */
    printf("GOVEE;OK=%d;N=%d;ALTER=%d\n",
        (int) (!empty($gv_lox['ok'])), count($gv_alle), $gv_alter);
    foreach ($gv_alle as $gv_i => $gv_z) {
        $gv_zeile = '';
        foreach (array('AN' => 'an', 'HELL' => 'hell', 'KELVIN' => 'kelvin',
                       'R' => 'r', 'G' => 'g', 'B' => 'b') as $gv_f => $gv_k) {
            $gv_zeile .= ';G' . (int) $gv_i . '_' . $gv_f . '=' . gv_w($gv_z[$gv_k]);
        }
        $gv_zeile .= ';G' . (int) $gv_i . '_HEX=' . ($gv_z['hex'] === null ? '-' : $gv_z['hex']);
        $gv_zeile .= ';G' . (int) $gv_i . '_OK=' . (int) $gv_z['ok'];
        $gv_zeile .= ';G' . (int) $gv_i . '_ALTER=' . (int) $gv_z['alter'];
        $gv_zeile .= ';G' . (int) $gv_i . '_FEHL=' . (int) $gv_z['fehl'];
        echo $gv_zeile . "\n";
    }
    exit;
}

if ($gv_aktion === 'status') {
    if ($gv_g === null) {
        /* ALTER meinte hier bisher das Alter des GANZEN Abbilds, eine Zeile
         * weiter unten aber das Alter dieses einen Werts - dasselbe Suchmuster,
         * zwei Bedeutungen. Wer eine Geraetenummer eintraegt, die es nicht mehr
         * gibt (die Nummern rutschen beim Loeschen einer Zeile), fuetterte
         * damit den Ausfallvergleich in Loxone mit einer fremden Groesse.
         * -1 heisst "nicht gemessen", und das ist hier die Wahrheit. */
        printf("GOVEE;OK=0;GRUND=GERAET_UNBEKANNT;N=%d;ALTER=-1;FEHL=-1\n", count($gv_alle));
        exit;
    }
    /* FEHL steht HINTEN. Eine zusaetzliche Angabe am Ende laesst jedes
     * bestehende Suchmuster in Loxone unveraendert gueltig - das ist der
     * billigste Ort fuer eine Erweiterung. */
    printf("GOVEE;OK=%d;AN=%s;HELL=%s;KELVIN=%s;R=%s;G=%s;B=%s;HEX=%s;ALTER=%d;FEHL=%d\n",
        (int) $gv_g['ok'], gv_w($gv_g['an']), gv_w($gv_g['hell']), gv_w($gv_g['kelvin']),
        gv_w($gv_g['r']), gv_w($gv_g['g']), gv_w($gv_g['b']),
        ($gv_g['hex'] === null ? '-' : $gv_g['hex']), (int) $gv_g['alter'],
        (int) $gv_g['fehl']);
    exit;
}

/* ================= Schaltende Aktionen ================= */

if (!in_array($gv_aktion, array('abruf', 'suche'), true) && empty($gv_cfg['steuerung_ein'])) {
    http_response_code(403);
    echo "SET;OK=0;GRUND=STEUERUNG_AUS\n";
    echo "Schreibende Befehle sind gesperrt. Reiter Einstellungen, Haken 'Schreibende Befehle zulassen'.\n";
    exit;
}
$gv_befehl = array('aktion' => $gv_aktion,
                   'geraet' => ($gv_nr === 'alle') ? 'alle' : (int) $gv_nr);

if (in_array($gv_aktion, array('hell', 'kelvin', 'balken'), true)) {
    if ($gv_wert === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=WERT_FEHLT\n";
        exit;
    }
    $gv_befehl['wert'] = (int) $gv_wert;
    if ($gv_aktion === 'balken' && $gv_hex !== '') {
        $gv_befehl['hex'] = $gv_hex;
    }
} elseif ($gv_aktion === 'farbe') {
    if ($gv_hex === '' && $gv_wert === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=FARBWERT_FEHLT\n";
        echo "Die Farbe wird als hex=RRGGBB angegeben (zum Beispiel hex=ff8800)\n";
        echo "oder als eine Zahl wert=0..16777215, gerechnet r*65536 + g*256 + b.\n";
        echo "Die Zahl ist der Weg fuer einen virtuellen Ausgang: <v.0> traegt nur EINEN Wert.\n";
        exit;
    }
    if ($gv_hex !== '') {
        $gv_befehl['r'] = hexdec(substr($gv_hex, 0, 2));
        $gv_befehl['g'] = hexdec(substr($gv_hex, 2, 2));
        $gv_befehl['b'] = hexdec(substr($gv_hex, 4, 2));
    } else {
        if ((int) $gv_wert > 16777215) {
            http_response_code(400);
            echo "SET;OK=0;GRUND=FARBWERT_ZU_GROSS\n";
            echo "Eine Farbe als Zahl liegt zwischen 0 und 16777215.\n";
            exit;
        }
        $gv_befehl['wert'] = (int) $gv_wert;
    }
} elseif ($gv_aktion === 'szene') {
    if ($gv_name === '' && $gv_szenennr === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=NAME_FEHLT\n";
        echo "Entweder name=<Schluessel> (die Schluessel stehen unter aktion=szenen)\n";
        echo "oder nr=<0..255> fuer eine Szenenkennung ohne Katalogeintrag.\n";
        exit;
    }
    if ($gv_szenennr !== '') {
        $gv_befehl['nr'] = (int) $gv_szenennr;
    } else {
        $gv_befehl['name'] = $gv_name;
    }
} elseif ($gv_aktion === 'segment') {
    if ($gv_segmente === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=SEGMENTE_FEHLEN\n";
        echo "Form: segmente=1-3:ff0000,7:00ff00 - Pixel werden ab 1 gezaehlt.\n";
        exit;
    }
    $gv_befehl['segmente'] = $gv_segmente;
    $gv_befehl['verfahren'] = $gv_verf;
    if ($gv_hg !== '') {
        $gv_befehl['hg'] = $gv_hg;
    }
    if ($gv_hgint !== '') {
        $gv_befehl['hgint'] = (int) $gv_hgint;
    }
    if ($gv_bewegung !== '') {
        $gv_befehl['bewegung'] = (int) $gv_bewegung;
    }
    if ($gv_geschw !== '') {
        $gv_befehl['geschw'] = (int) $gv_geschw;
    }
} elseif ($gv_aktion === 'musik') {
    if ($gv_art === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=ART_FEHLT\n";
        exit;
    }
    $gv_befehl['art'] = (int) $gv_art;
    $gv_befehl['gruppe'] = (int) $gv_gruppe;
    $gv_befehl['sens'] = ($gv_sens === '') ? 100 : (int) $gv_sens;
} elseif ($gv_aktion === 'pt') {
    /* Dieselbe Sperre wie steuerung_ein wird auch hier am Tor geprueft.
     * Bis 0.9.8 wanderte der Befehl durch die Warteschlange ins Dateisystem,
     * nur damit der Dienst ihn ablehnt - zwei Orte fuer eine Sperre. */
    if (empty($gv_cfg['pt_frei'])) {
        http_response_code(403);
        echo "SET;OK=0;GRUND=PT_GESPERRT\n";
        echo "Rohe ptReal-Befehle sind gesperrt. Reiter Einstellungen, Haken 'Rohe ptReal-Befehle zulassen'.\n";
        exit;
    }
    if ($gv_ptcmd === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=CMD_FEHLT\n";
        exit;
    }
    $gv_befehl['cmd'] = explode(',', $gv_ptcmd);
}

/* Erst jetzt die Frage nach dem Dienst. Sie steht ABSICHTLICH hinter der
 * Pruefung der Parameter: wer einen gesperrten oder falsch geschriebenen
 * Befehl schickt, soll das erfahren und nicht "Der Dienst laeuft nicht" -
 * sonst sucht er an der falschen Stelle. */
if (gv_dienst_pid() === 0) {
    /* Nicht stillschweigend einreihen: ohne laufenden Dienst passiert nichts. */
    http_response_code(503);
    echo "SET;OK=0;GRUND=DIENST_LAEUFT_NICHT\n";
    echo "Der Abrufdienst laeuft nicht. Reiter Einstellungen, Knopf 'Dienst starten'.\n";
    exit;
}

list($gv_erg, $gv_meldung) = gv_befehl_absetzen($gv_befehl);
if ($gv_erg === 0) {
    http_response_code(500);
}
printf("SET;OK=%d;AKTION=%s;MELDUNG=%s\n", $gv_erg, $gv_aktion,
    str_replace(array("\r", "\n", ';'), ' ', $gv_meldung));
