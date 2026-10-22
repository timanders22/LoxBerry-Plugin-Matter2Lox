<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie Matter2Lox.
 *
 * Gruppe 8 / MQTT (0.9.44; Bauvorschrift wz_bild/GRUPPE8_BAUVORSCHRIFT.md, Entwurf Musterprojekt/entwurf_gruppe8_mt.txt
 * Abschnitte 3.2-3.4, ENTSCHEIDUNGEN_GRUPPE8_MATTER2LOX.md E1-E14; Muster vb_g7_ic_bau/bin/befehle_zuordnung.php). Die
 * Bruecke selbst (bin/befehlsbruecke.php und .sh) ist byte-gleich die Stammfassung Werkzeuge/gemeinsam/ - linieneigen
 * ist nur diese Datei.
 *
 * Themen: <praefix>/befehl/geraet<N>/<ep>/<befehl> - symmetrisch zu den Werten <praefix>/geraet<N>/<ep>/<thema>.
 * <N> ist die feste Geraetenummer des Plugins (nummern.json, wie &geraet= am HTTP-Endpunkt), <ep> der Endpunkt
 * (wie &endpunkt=). Dazu <praefix>/befehl/geraet<N>/abruf (ein Geraet neu auslesen) und <praefix>/befehl/abruf
 * (den Bestand neu holen). 19 Geraetebefehle und zwei Abrufe; KEIN Rohweg (attribut/befehl), keine Farbe mit zwei
 * Werten (farbe, farbe_xy) - Entscheidung E4, Entwurf 3.5; Kopplung, Fabric und Bridge bleiben in der Oberflaeche.
 *
 * Regeln (Entwurf gemeinsam 2.5): die Datei gibt ein Feld zurueck, setzt keine Konfiguration und schreibt nichts. 'tun'
 * ruft mt_bb_tun() in mt_lib.php: Upgrade-Marke, Entsperren-Haken, Verbindung zum Matter-Server (sonst
 * KEINE_VERBINDUNG, E8), dann mt_steuern() - DIESELBE Funktion wie der HTTP-Endpunkt (Steuerungs- und Schlossfreigabe,
 * Bereiche, Schlossbremse, Gleichwert-Unterdrueckung, Warteschlange befehle/ ueber mt_befehl_absetzen()). Kein HTTP auf
 * sich selbst, kein Kindprozess. Stellbefehle, Tasten und Abrufe werden eingereiht, ohne zu warten (Quittung
 * "uebergeben", E5); nur das Schloss wartet wie HTTP (Wartezeit der Konfiguration, 8 s), damit die Schlossbremse den
 * ausgefuehrten Befehl merkt. Ueber MQTT eingereiht verfaellt ein Befehl nach 30 s (E8).
 *
 * Haken (E1-E3): "Befehle ueber MQTT annehmen" (befehle_mqtt_ein, ab Werk aus) schaltet die Bruecke; der zweite Haken
 * fuer eingreifende Befehle ist der VORHANDENE "Schreibende Befehle zulassen" (steuerung_ein, E2). Schloesser brauchen
 * zusaetzlich "Schloesser schalten zulassen" (schloss_ein, prueft mt_steuern), ENTsperren ueber MQTT ausserdem
 * "Schloesser ueber MQTT entsperren erlauben" (befehle_mqtt_entsperren, ab Werk aus, E3).
 *
 * Sperrzeiten (E6): 0 s fuer Stellbefehle, Tasten und Schloss - Matter2Lox prueft selbst und an EINER Stelle fuer
 * HTTP und MQTT. identify und die Abrufe behalten die Vorgabe 60 s. identify gilt als eingreifend (zweiter Haken):
 * der Endpunkt verlangt dafuer seit jeher "Schreibende Befehle zulassen" - eine Logik, dieselbe Antwort
 * (Abweichung vom Entwurf 3.2, der identify als nicht eingreifend fuehrte).
 */
if (!function_exists('mt_bb_tun')) {
    $mt_bb_kand = array();
    if (basename(dirname(__DIR__)) === 'plugins') {
        $mt_bb_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/mt_lib.php';
    }
    $mt_bb_kand[] = dirname(__DIR__) . '/webfrontend/html/mt_lib.php';
    foreach ($mt_bb_kand as $mt_bb_k) {
        if (is_file($mt_bb_k)) { require_once $mt_bb_k; break; }
    }
}
$mt_bb_stell = 'Matter2Lox prueft selbst und an EINER Stelle fuer HTTP und MQTT: Gleichwert-Unterdrueckung (derselbe '
    . 'Sollwert je Geraet binnen 60 s, ein anderer Wert geht sofort hinaus), Schlossbremse und das Zusammenfassen im '
    . 'Dienst (je Geraet nur der juengste Stellbefehl). Eine Brueckensperre von 60 s je Name und Wert verschluckte '
    . 'ein-aus-ein binnen einer Minute (Probe, Entwurf 3.3).';
$mt_bb_taste = 'Taster - jeder Druck zaehlt.';
$mt_bb_schloss = 'sperren ist die sichere Richtung und idempotent; entsperren bremst das Plugin selbst (Schlossbremse, '
    . '0.9.35 Nr. 1).';
/* Ein Eintrag: Art, Grenzen, eingreifend, Sperre, Titel, Kachel. tun() reicht Aktion, Teile und Wert an mt_bb_tun(). */
$mt_bb_e = function ($aktion, $art, $eingreifend, $titel, $kachel, $sperre, $grund, $extra = array()) {
    $e = array('art' => $art, 'eingreifend' => $eingreifend, 'titel' => 'Matter2Lox ' . $titel . ' (MQTT)',
               'kachel' => $kachel, 'quittung' => 'uebergeben',
               'tun' => function ($w, $t) use ($aktion) {
                   return mt_bb_tun($aktion, $t, (string) $w);
               });
    if ($sperre !== 60) {
        $e['sperre_s'] = $sperre;
        $e['begruendung'] = $grund;
    }
    return array_merge($e, $extra);
};
return array(
    'fassung' => 1,
    'linie'   => 'matter2lox',
    'plugin'  => 'Matter2Lox',
    'geladen' => function_exists('mt_bb_tun') && function_exists('mt_config')
                 && function_exists('mt_bb_einstellungen') && function_exists('mt_bb_protokoll'),
    'zweiter_haken' => 'steuerung_ein',
    'teile' => array('geraet' => 'geraet[1-9][0-9]{0,2}', 'ep' => '[0-9]{1,3}'),
    'einstellungen' => function () {
        return mt_bb_einstellungen();
    },
    'protokoll' => function ($stufe, $text) {
        mt_bb_protokoll($stufe, $text);
    },
    'befehle' => array(
        '{geraet}/{ep}/schalter'       => $mt_bb_e('schalter', 'schalter', true, 'Schalten', 'BEFEHL.K_SCHALTER', 0, $mt_bb_stell),
        '{geraet}/{ep}/umschalten'     => $mt_bb_e('umschalten', 'puls', true, 'Umschalten', 'BEFEHL.K_UMSCHALTEN', 0, $mt_bb_taste),
        '{geraet}/{ep}/helligkeit'     => $mt_bb_e('helligkeit', 'zahl', true, 'Helligkeit', 'BEFEHL.K_HELLIGKEIT', 0, $mt_bb_stell,
                                                   array('min' => 0, 'max' => 100)),
        '{geraet}/{ep}/farbtemperatur' => $mt_bb_e('farbtemperatur', 'ganz', true, 'Farbtemperatur', 'BEFEHL.K_FARBTEMPERATUR', 0,
                                                   $mt_bb_stell, array('min' => 1000, 'max' => 10000)),
        '{geraet}/{ep}/farbton'        => $mt_bb_e('farbton', 'ganz', true, 'Farbton', 'BEFEHL.K_FARBTON', 0, $mt_bb_stell,
                                                   array('min' => 0, 'max' => 360)),
        '{geraet}/{ep}/saettigung'     => $mt_bb_e('saettigung', 'zahl', true, 'Sättigung', 'BEFEHL.K_SAETTIGUNG', 0, $mt_bb_stell,
                                                   array('min' => 0, 'max' => 100)),
        '{geraet}/{ep}/loxfarbe'       => $mt_bb_e('loxfarbe', 'ganz', true, 'Loxone-Farbe', 'BEFEHL.K_LOXFARBE', 0, $mt_bb_stell,
                                                   array('min' => 0, 'max' => 299999999)),
        '{geraet}/{ep}/rollo'          => $mt_bb_e('rollo', 'zahl', true, 'Behang', 'BEFEHL.K_ROLLO', 0, $mt_bb_stell,
                                                   array('min' => 0, 'max' => 100)),
        '{geraet}/{ep}/lamelle'        => $mt_bb_e('lamelle', 'zahl', true, 'Lamelle', 'BEFEHL.K_LAMELLE', 0, $mt_bb_stell,
                                                   array('min' => 0, 'max' => 100)),
        '{geraet}/{ep}/rollo_auf'      => $mt_bb_e('rollo_auf', 'puls', true, 'Behang auf', 'BEFEHL.K_ROLLO_AUF', 0, $mt_bb_taste),
        '{geraet}/{ep}/rollo_zu'       => $mt_bb_e('rollo_zu', 'puls', true, 'Behang zu', 'BEFEHL.K_ROLLO_ZU', 0, $mt_bb_taste),
        '{geraet}/{ep}/rollo_stopp'    => $mt_bb_e('rollo_stopp', 'puls', true, 'Behang Stopp', 'BEFEHL.K_ROLLO_STOPP', 0, $mt_bb_taste),
        '{geraet}/{ep}/soll_heizen'    => $mt_bb_e('soll_heizen', 'zahl', true, 'Sollwert Heizen', 'BEFEHL.K_SOLL_HEIZEN', 0,
                                                   $mt_bb_stell, array('min' => -50, 'max' => 100)),
        '{geraet}/{ep}/soll_kuehlen'   => $mt_bb_e('soll_kuehlen', 'zahl', true, 'Sollwert Kühlen', 'BEFEHL.K_SOLL_KUEHLEN', 0,
                                                   $mt_bb_stell, array('min' => -50, 'max' => 100)),
        '{geraet}/{ep}/betriebsart'    => $mt_bb_e('betriebsart', 'ganz', true, 'Betriebsart', 'BEFEHL.K_BETRIEBSART', 0, $mt_bb_stell,
                                                   array('min' => 0, 'max' => 9)),
        '{geraet}/{ep}/luefter'        => $mt_bb_e('luefter', 'zahl', true, 'Lüfter', 'BEFEHL.K_LUEFTER', 0, $mt_bb_stell,
                                                   array('min' => 0, 'max' => 100)),
        '{geraet}/{ep}/identify'       => $mt_bb_e('identify', 'ganz', true, 'Bemerkbar machen', 'BEFEHL.K_IDENTIFY', 60, '',
                                                   array('min' => 1, 'max' => 300)),
        '{geraet}/{ep}/sperren'        => $mt_bb_e('sperren', 'puls', true, 'Schloss sperren', 'BEFEHL.K_SPERREN', 0, $mt_bb_schloss),
        '{geraet}/{ep}/entsperren'     => $mt_bb_e('entsperren', 'puls', true, 'Schloss entsperren', 'BEFEHL.K_ENTSPERREN', 0,
                                                   $mt_bb_schloss),
        '{geraet}/abruf'               => $mt_bb_e('abruf', 'puls', false, 'Gerät neu auslesen', 'BEFEHL.K_ABRUF_GERAET', 60, ''),
        'abruf'                        => $mt_bb_e('abruf', 'puls', false, 'Bestand neu holen', 'BEFEHL.K_ABRUF', 60, ''),
    ),
);
