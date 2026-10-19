<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie Govee.
 *
 * Gruppe 3 / MQTT (0.9.30; Bauvorschrift wz_bild/GRUPPE3_BAUVORSCHRIFT.md, Entwurf Musterprojekt/entwurf_gruppe3_gv.txt
 * Abschnitt 3.2, ENTSCHEIDUNGEN_GRUPPE3_MQTT.md; Muster vb_g1_bruecke_bau/beispiel/bin/befehle_zuordnung.php). Die Bruecke
 * selbst (bin/befehlsbruecke.php und .sh) ist byte-gleich die Stammfassung Werkzeuge/gemeinsam/ - linieneigen ist nur
 * diese Datei.
 *
 * Regeln (Entwurf gemeinsam 2.5): die Datei gibt ein Feld zurueck, setzt keine Konfiguration und schreibt nichts.
 * 'tun' ruft gv_befehl_annehmen() - dieselbe Funktion wie der Endpunkt webfrontend/html/index.php - mit Quelle 'mqtt'
 * und gibt array(bool ok, string grund) zurueck (gv_befehl_ergebnis()).
 *
 * Themen je Leuchte <praefix>/befehl/geraet<N>/<name> oder .../alle/<name> (E8, wie die Werte govee/geraet<N>/...);
 * "alle" geht wie am Endpunkt an jede eingerichtete Leuchte. Sechs Befehle schalten und stehen hinter dem zweiten Haken
 * "Schreibende Befehle zulassen" (steuerung_ein, E7); abruf braucht ihn nicht (wie am Endpunkt). segment, musik, pt und
 * suche gehen nicht ueber MQTT (E10). Sperrzeiten (ENTSCHEIDUNGEN Gruppe 3, E9): Sollwerte und Balken 0 s, Szene 5 s,
 * Abruf 10 s. Die Bruecke wartet je Befehl hoechstens die Wartezeit des Plugins (ab Werk 6 s) auf den Dienst.
 * Quittung "uebergeben" fuer alles Schaltende: UDP an die Leuchte quittiert nicht (E11).
 */
if (!function_exists('gv_config')) {
    $gv_kand = array();
    if (basename(dirname(__DIR__)) === 'plugins') {
        $gv_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/gv_lib.php';
    } else {
        $gv_kand[] = dirname(__DIR__) . '/webfrontend/html/gv_lib.php';
    }
    foreach ($gv_kand as $gv_k) {
        if (is_file($gv_k)) { require_once $gv_k; break; }
    }
}
$gv_nr = function (array $t) {
    return $t['geraet'] === 'alle' ? 'alle' : (int) substr($t['geraet'], 6);
};
$gv_null = 'Sollwert: die Befehlsbremse des Plugins (60 s, gleicher Wert an dieselbe Leuchte) bleibt und antwortet '
         . "'unveraendert'; eine zweite Sperre in der Bruecke wiese Reglerwerte zusaetzlich als 'wiederholt' ab";
$gv_wert = function ($aktion, $feld, $titel, $kachel, $min, $max, $sperre, $grund) use ($gv_nr) {
    return array('art' => 'ganz', 'min' => $min, 'max' => $max, 'eingreifend' => true, 'sperre_s' => $sperre,
                 'begruendung' => $grund, 'titel' => $titel, 'kachel' => $kachel, 'quittung' => 'uebergeben',
                 'tun' => function ($w, $t) use ($aktion, $feld, $gv_nr) {
                     return gv_befehl_ergebnis(gv_befehl_annehmen(array('aktion' => $aktion, 'geraet' => $gv_nr($t),
                         $feld => (int) $w), gv_config(false), 'mqtt'));
                 });
};
return array(
    'fassung' => 1,
    'linie'   => 'govee',
    'plugin'  => 'Govee',
    'geladen' => function_exists('gv_config') && function_exists('gv_befehl_annehmen')
                 && function_exists('gv_befehl_ergebnis'),
    'zweiter_haken' => 'steuerung_ein',
    'einstellungen' => function () {
        return gv_befehle_einstellungen();
    },
    'protokoll' => function ($stufe, $text) {
        gv_log(($stufe === 'INFO' ? '' : $stufe . ' ') . $text);
    },
    'teile' => array('geraet' => 'geraet[1-9][0-9]?|alle'),
    'befehle' => array(
        '{geraet}/schalten' => array('art' => 'schalter', 'eingreifend' => true, 'sperre_s' => 0, 'begruendung' => $gv_null,
            'titel' => 'Govee Ein/Aus (MQTT)', 'kachel' => 'BEFEHL.K_SCHALTEN', 'quittung' => 'uebergeben',
            'tun' => function ($w, $t) use ($gv_nr) {
                return gv_befehl_ergebnis(gv_befehl_annehmen(array('aktion' => $w === '1' ? 'ein' : 'aus',
                    'geraet' => $gv_nr($t)), gv_config(false), 'mqtt'));
            }),
        '{geraet}/helligkeit' => $gv_wert('hell', 'wert', 'Govee Helligkeit (MQTT)', 'BEFEHL.K_HELLIGKEIT', 0, 100, 0, $gv_null),
        '{geraet}/kelvin' => $gv_wert('kelvin', 'wert', 'Govee Farbtemperatur (MQTT)', 'BEFEHL.K_KELVIN', 1000, 10000, 0, $gv_null),
        '{geraet}/farbe' => $gv_wert('farbe', 'wert', 'Govee Farbe als Zahl (MQTT)', 'BEFEHL.K_FARBE', 0, 16777215, 0, $gv_null),
        '{geraet}/szene' => $gv_wert('szene', 'nr', 'Govee Szene nach Kennung (MQTT)', 'BEFEHL.K_SZENE', 0, 255, 5,
            'Ereignis; schuetzt nur vor Doppelausloesung, dieselbe Szene darf kurz danach erneut gewaehlt werden'),
        '{geraet}/balken' => $gv_wert('balken', 'wert', 'Govee Balken in Prozent (MQTT)', 'BEFEHL.K_BALKEN', 0, 100, 0,
            'Ereignis; die Musterformel sendet nur beim Stufenwechsel'),
        'abruf' => array('art' => 'puls', 'eingreifend' => false, 'sperre_s' => 10,
            'begruendung' => 'Eine LAN-Runde dauert etwa 2 s; oefter abzufragen bringt nichts',
            'titel' => 'Govee Zustand jetzt abrufen (MQTT)', 'kachel' => 'BEFEHL.K_ABRUF', 'quittung' => 'ausgefuehrt',
            'tun' => function ($w, $t) {
                return gv_befehl_ergebnis(gv_befehl_annehmen(array('aktion' => 'abruf', 'geraet' => 1),
                    gv_config(false), 'mqtt'));
            }),
    ),
);
