<?php
/**
 * Matter to Loxone - gemeinsame Bibliothek
 *
 * Liegt unter webfrontend/html/, weil der Miniserver-Endpunkt sie ebenso
 * braucht wie die Oberflaeche. So gibt es EINE Datei statt zweier Kopien.
 *
 * Dieses Plugin ist die BRUECKE, nicht der Matter-Controller. Der Controller
 * ist der zertifizierte python-matter-server; er laeuft in einem eigenen
 * Container. Diese Bibliothek spricht ihn nie selbst an - sie liest den
 * Zwischenspeicher, den bin/matter_dienst.py schreibt, verwaltet den Container
 * ueber die Docker-Kommandozeile und legt Befehle in einer Warteschlange ab.
 *
 * Praefix 'mt_', weil LBWeb::lbheader() SDK-Globale setzt.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

if (!function_exists('mt_e')) {
    function mt_e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins, data/plugins UND config/system/general.json enthaelt
 * (Regeln/06). Das trifft die uebliche Installation genauso wie eine an einem
 * anderen Ort; aus einem entpackten Archiv ausserhalb jeder Anlage findet es
 * nichts und gibt einen Leerstring zurueck, den der Aufrufer abfangen muss.
 *
 * Bis 0.9.28 genuegten config/plugins und webfrontend - in WSL gemessen
 * (25.09.2026, Pruefung-Matter2Lox-0.9.29, Faelle W7 und W9) galt damit ein
 * fremder Baum ohne general.json als Wurzel.
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/data/plugins')
                && is_file($d . '/config/system/general.json')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

/* Die Wurzel: erst die Umgebung, dann die Suche - und DANACH NICHTS MEHR.
 *
 * Bis 0.9.28 folgte als dritte Stufe ein fest verdrahteter Systempfad (das
 * Heimatverzeichnis des Benutzers loxberry), in mt_paths() und in mt_t().
 * Er macht jede Suche wirkungslos und trifft auf einem anders installierten
 * LoxBerry die falsche Anlage (in WSL gemessen 25.09.2026,
 * Pruefung-Matter2Lox-0.9.29, Fall W8: der Zugriff geschah aus jedem
 * ausgepackten Archiv). Ein gesetztes LBHOMEDIR gilt mit config/plugins UND
 * data/plugins darunter - general.json wird hier nicht verlangt, damit
 * Attrappen ohne sie (Werkzeuge/lb) weiter tragen. Rueckgabe '' heisst
 * "keine Wurzel". Bauart tb_lbhome(), Spotpreis-Tibber 0.9.19. */
function mt_lbhome()
{
    $h = getenv('LBHOMEDIR');
    if ($h && is_dir($h . '/config/plugins') && is_dir($h . '/data/plugins')) {
        return rtrim($h, '/');
    }
    return lb_wurzel_ermitteln();
}

function mt_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = mt_lbhome();
    // Der Pluginordner ergibt sich aus dem Ablageort dieser Datei. Der
    // MD5-Schluessel aus der plugindatabase.json wird bewusst NICHT benutzt -
    // er wird aus Autorenname, E-Mail und Plugin-Name gebildet und aendert
    // sich bei jedem Fork.
    $dir = basename(dirname(__FILE__));
    /* LBPPLUGINDIR ist die Auskunft von LoxBerry selbst und geht vor. Der
     * feste Name greift nur, wo der abgeleitete nachweislich kein
     * Pluginordner sein kann - aus dem ausgepackten Archiv heisst er 'html'.
     * Bis 0.9.28 entschied hier zusaetzlich, ob config/plugins/<ordner>
     * schon existiert; bei einer Zweitinstallation (matter2lox_01) fiel die
     * Ermittlung damit auf die ERSTE Installation zurueck (Bauart
     * tb_paths(), Spotpreis-Tibber 0.9.19). */
    $lbp = basename(rtrim((string) getenv('LBPPLUGINDIR'), '/'));
    $lbp_gilt = ($lbp !== '' && !in_array($lbp, array('.', '/', 'html', 'htmlauth', 'bin', 'plugins'), true));
    if ($lbp_gilt) {
        $dir = $lbp;
    } elseif (in_array($dir, array('', '.', '/', 'html', 'htmlauth', 'bin', 'plugins'), true)) {
        $dir = 'matter2lox';
    }
    /* Archivmodus. Die Pfade DER ANLAGE gelten nur, wenn diese Bibliothek
     * dort installiert liegt (<Wurzel>/webfrontend/html/plugins/<ordner>,
     * physisch verglichen) oder der Aufrufer Wurzel UND Ordner ausdruecklich
     * nennt ($LBHOMEDIR und $LBPPLUGINDIR - so arbeiten die Pruefwerkzeuge
     * mit ihrer Attrappe). Sonst ist das ein ausgepacktes Archiv oder ein
     * Pruefordner: alles bleibt in dessen eigenem Ordner, und die Knoepfe fuer
     * Dienst und Container verweigern (mt_dienst(), mt_container()).
     *
     * Bis 0.9.28 nahm ein Archiv unterhalb einer echten Wurzel diese Wurzel
     * und den festen Namen 'matter2lox' - Konfiguration, Token, Warteschlange
     * und Dienst der Anlage; mit $LBHOMEDIR allein, wie es am Geraet in
     * /etc/environment steht, ebenso. Der Knopf "Dienst anhalten" hielt aus
     * dem Archiv heraus den Dienst der Anlage an, "Container entfernen"
     * entfernte ihren Container (in WSL gemessen 25.09.2026,
     * Pruefung-Matter2Lox-0.9.29, Faelle A2 bis A4). */
    $gefunden = $home;
    if ($home !== '') {
        $soll = @realpath($home . '/webfrontend/html/plugins/' . basename(__DIR__));
        $ist = @realpath(__DIR__);
        $installiert = ($soll !== false && $ist !== false && $soll === $ist);
        $ausdruecklich = $lbp_gilt && $home === rtrim((string) getenv('LBHOMEDIR'), '/');
        if (!$installiert && !$ausdruecklich) {
            $home = '';
        }
    }
    if ($home !== '') {
        $p = array(
            'home'      => $home,
            'plugin'    => $dir,
            'configdir' => $home . '/config/plugins/' . $dir,
            'config'    => $home . '/config/plugins/' . $dir . '/matter2lox.json',
            'sicherung' => $home . '/config/plugins/' . $dir . '.backup.json',
            'datadir'   => $home . '/data/plugins/' . $dir,
            /* Fabric und Geraetenummern liegen NEBEN dem Datenordner, nicht
             * darin. Grund steht ueber mt_fabric_pfad(). */
            'fabric'    => $home . '/data/plugins/' . $dir . '.matter',
            'nummern'   => $home . '/data/plugins/' . $dir . '.nummern.json',
            'bindir'    => $home . '/bin/plugins/' . $dir,
            'logdir'    => $home . '/log/plugins/' . $dir,
            'log'       => $home . '/log/plugins/' . $dir . '/matter2lox.log',
            /* O1: die Einmalmeldung nach der Umleitung (Regeln/04). */
            'einmal'    => $home . '/data/plugins/' . $dir . '/einmalmeldung.json',
            /* M5: jedes je benutzte MQTT-Praefix, NEBEN dem Konfigordner. */
            'praefixe'  => $home . '/config/plugins/' . $dir . '.mqtt_praefixe.json',
            /* Tuer-1: die je unter haus/tuer/ gesendeten Themen (DATEI_HAUS im
             * Dienst), NEBEN dem Konfigordner. */
            'haus'      => $home . '/config/plugins/' . $dir . '.haus_themen.json',
            'tabelle'   => $home . '/templates/plugins/' . $dir . '/matter_cluster.json',
            'archiv'    => '',
        );
    } else {
        $basis = dirname(dirname(__DIR__));
        $p = array(
            'home' => '', 'plugin' => $dir,
            // Die gefundene Wurzel, wenn diese Datei NICHT darin installiert
            // liegt (Archivmodus) - fuer die Meldung; sonst leer.
            'archiv'    => $gefunden,
            'configdir' => $basis . '/config',
            'config'    => $basis . '/config/matter2lox.json',
            'sicherung' => $basis . '/config/matter2lox.backup.json',
            'datadir'   => $basis . '/data',
            'fabric'    => $basis . '/data.matter',
            'nummern'   => $basis . '/data.nummern.json',
            'bindir'    => $basis . '/bin',
            'logdir'    => $basis . '/log',
            'log'       => $basis . '/log/matter2lox.log',
            'einmal'    => $basis . '/data/einmalmeldung.json',
            'praefixe'  => $basis . '/config/matter2lox.mqtt_praefixe.json',
            'haus'      => $basis . '/config/matter2lox.haus_themen.json',
            'tabelle'   => $basis . '/templates/matter_cluster.json',
        );
    }
    return $p;
}

/**
 * Die Fassung - aus EINER Quelle, der plugin.cfg.
 *
 * Keine Konstante im Code: die pflegt niemand mit. fassung_setzen.py kennt
 * die drei .cfg und die README, sonst nichts. parse_ini_file() scheitert an
 * der plugin.cfg, weil die mit '#' kommentiert und PHPs INI-Zerleger nur ';'
 * kennt - deshalb die Kommentarzeilen vorher heraus.
 */
function mt_fassung()
{
    /* NEU: zuerst LoxBerry selbst fragen.
     *
     * Am Geraet gemessen (07.09.2026): plugininstall.pl liest die
     * plugin.cfg aus dem Auspackordner und loescht sie danach -
     * installiert wird sie NIRGENDWOHIN. Eine Fassungsfunktion, die nur
     * Dateien kennt, gibt auf jeder Installation eine leere Zeichenkette
     * zurueck; im Arbeitsordner faellt das nie auf, weil dort der
     * Archivfall der Kandidatenliste immer trifft.
     *
     * LBSystem::pluginversion() (loxberry_system.php:403) liest die
     * plugindatabase.json und ist die Auskunft von LoxBerry selbst.
     * Die Dateikandidaten darunter bleiben stehen - sie tragen den
     * Auspackordner, und der ist der Pruefstand. */
    if (class_exists('LBSystem', false)
        && method_exists('LBSystem', 'pluginversion')) {
        /* Ueber den Ordnernamen fragen (Regeln/03): ohne Argument haengt die
         * Antwort am ersten eingebundenen Skript - am Geraet gemessen
         * 17.09.2026: aus einem fremden Einstieg (php -r) NULL, mit dem
         * Ordnernamen die installierte Fassung. Installiert liegt diese Datei
         * unter webfrontend/html(auth)/plugins/<ordner>/. */
        $aus = @LBSystem::pluginversion(basename(__DIR__));
        if ($aus !== null && trim((string) $aus) !== '') {
            return trim((string) $aus);
        }
    }
    static $v = null;
    if ($v !== null) {
        return $v;
    }
    $v = '';
    $p = mt_paths();
    /* Der Pfad in der Anlage nur MIT Wurzel. Bis 0.9.28 wurde er auch mit
     * leerer Wurzel gebildet - aus dem ausgepackten Archiv also
     * /data/system/install/... ab der Laufwerkswurzel (in WSL gemessen
     * 25.09.2026, Pruefung-Matter2Lox-0.9.29, Fall P2). */
    $kandidaten = array(dirname(dirname(__DIR__)) . '/plugin.cfg');
    if ($p['home'] !== '') {
        array_unshift($kandidaten, $p['home'] . '/data/system/install/' . $p['plugin'] . '/plugin.cfg');
    }
    foreach ($kandidaten as $kand) {
        if (!is_file($kand)) {
            continue;
        }
        $roh = (string) @file_get_contents($kand);
        $d = @parse_ini_string(preg_replace('/^[ \t]*#.*$/m', '', $roh), true, INI_SCANNER_RAW);
        if (is_array($d) && isset($d['PLUGIN']['VERSION'])) {
            $v = trim((string) $d['PLUGIN']['VERSION']);
            break;
        }
    }
    return $v;
}

/** Voreinstellungen. Muessen zu VORGABEN in bin/matter_dienst.py passen. */
function mt_vorgaben()
{
    return array(
        'server_host'       => '127.0.0.1',
        'server_port'       => 5580,
        'eigener_container' => 1,
        /* 0.9.35 (Nr. 1): nicht mehr "matter-server" - so heisst der
         * Container in der Anleitung des Matter-Servers, und ein eigener
         * Server des Anwenders trug oft denselben Namen. Eine gespeicherte
         * Konfiguration behaelt ihren Namen. */
        'container_name'    => 'matter2lox-server',
        'container_abbild'  => 'ghcr.io/matter-js/python-matter-server:stable',
        'bluetooth_adapter' => 0,
        'mqtt_ein'          => 1,
        'mqtt_topic'        => 'matter',
        'roh_ein'           => 0,
        'steuerung_ein'     => 0,
        'aktionstoken'      => '',
        'wartezeit'         => 8,
        'wlan_ssid'         => '',
        'wlan_passwort'     => '',
        'thread_dataset'    => '',
        'thread_br'         => '',
        'sendetakt'         => 2,
        'herzschlag'        => 60,
        'mqtt_nur'          => '',
        'schloss_ein'       => 0,
        /* Tuer-1 (Verbesserungsbau 30.09.2026): Tueren und Schloesser
         * zusaetzlich unter haus/tuer/<name>/... melden. Ab Werk aus. */
        'tuer_haus'         => 0,
        /* 0.9.35 (Nr. 1, Vertrag): der eigene Container lauscht nur auf
         * 127.0.0.1 (--listen-address), solange server_host 127.0.0.1 oder
         * localhost ist. Ab Werk ein. */
        'server_lokal'      => 1,
        /* 0.9.35 (Nr. 1): --primary-interface am Container, leer = weglassen. */
        'primary_interface' => '',
        /* 0.9.35 (Nr. 1): zweites Token NUR fuer lesende Aktionen am Endpunkt
         * (status, statusalle, wert, liste, roh); leer = keines. */
        'lesetoken'         => '',
        /* 0.9.35 (Nr. 1): Adresse fuer die Loxone-Vorlagen (Host/IP[:Port]);
         * leer = IP des LoxBerry. */
        'loxone_adresse'    => '',
    );
}

function mt_json_lesen($pfad)
{
    if (!is_file($pfad)) {
        return array();
    }
    $d = json_decode((string) @file_get_contents($pfad), true);
    return is_array($d) ? $d : array();
}

/**
 * Erst in eine Nebendatei, dann umbenennen.
 *
 * Drei Dinge, die bis 0.9.16 fehlten:
 *
 * 1. Die Nebendatei traegt die Prozessnummer. Cron, Dienst, Oberflaeche und
 *    Endpunkt schreiben dieselben Dateien; mit einem festen '.tmp' zerlegen
 *    zwei gleichzeitige Schreiber einander die Datei.
 * 2. Die Rechte stehen VOR dem Inhalt. Zwischen file_put_contents und chmod
 *    lag sonst ein Fenster, in dem WLAN-Passwort und Thread-Dataset mit der
 *    Standardmaske (ueblich 0644) fuer alle lesbar waren.
 * 3. Verglichen wird gegen strlen(), nicht gegen false. Eine kurze Schreibung
 *    (volle Platte) gibt die Zahl der geschriebenen Byte zurueck, nicht false,
 *    und ist genauso kaputt.
 */
function mt_json_schreiben($pfad, $daten, $rechte = null)
{
    $ordner = dirname($pfad);
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return false;
    }
    $json = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $tmp = $pfad . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) {
        return false;
    }
    if ($rechte !== null) {
        @chmod($tmp, $rechte);
    }
    $geschrieben = @fwrite($fh, $json);
    @ftruncate($fh, $geschrieben === false ? 0 : $geschrieben);
    @fclose($fh);
    if ($geschrieben !== strlen($json)) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $pfad)) {
        @unlink($tmp);
        return false;
    }
    if ($rechte !== null) {
        @chmod($pfad, $rechte);
    }
    return true;
}

/**
 * Die Konfiguration lesen, mit Selbstheilung.
 *
 * Bis 0.9.9 wurde die Zweitschrift NUR bei einer leeren Datei oder bei "{}"
 * gezogen. War die Datei vorhanden, aber ungueltiges JSON - halb geschrieben,
 * ein Zeichen verrutscht -, gab mt_json_lesen() stillschweigend ein leeres
 * Feld zurueck, und die Werkseinstellung lag darueber. Danach lief folgende
 * Kette an, ausgeloest durch nichts weiter als einen Aufruf der Oberflaeche:
 *
 *   1. aktionstoken leer  -> mt_token() erzeugte ein NEUES. Jede Adresse in
 *      der Loxone-Projektdatei war damit ungueltig, ohne eine Meldung.
 *   2. mt_token() ruft mt_config_speichern() -> die Werkseinstellung wurde
 *      ueber die beschaedigte Datei geschrieben.
 *   3. mt_config_speichern() kopierte das Ergebnis auf die SICHERUNG - die
 *      letzte gute Fassung war damit ebenfalls fort.
 *
 * Verloren waren in einem Zug: Aktionstoken, Steuerungsfreigabe,
 * MQTT-Praefix, WLAN-Passwort und Thread-Dataset.
 *
 * Jetzt gilt: ungueltiges JSON ist ein FEHLER. Die beschaedigte Datei bleibt
 * einmalig als .kaputt liegen, es gibt genau eine Protokollzeile, und die
 * Zweitschrift wird GELESEN, nicht blind kopiert - zurueckgeschrieben wird
 * erst durch mt_config_speichern(), also erst nach gelungenem Lesen. Der
 * Zusatz ist wichtig: eine Heilung, die nur liest und nie schreibt, zieht bei
 * jedem Aufruf erneut und protokolliert dabei jedes Mal.
 */
function mt_config($schreiben_erlaubt = true)
{
    $p = mt_paths();
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';

    if ($roh !== '' && $roh !== '{}') {
        $geprueft = json_decode($roh, true);
        if (is_array($geprueft)) {
            mt_config_lage('ok');
            return array_merge(mt_vorgaben(), $geprueft);
        }
        /* Ungueltiges JSON. Melden, .kaputt ablegen - aber NUR aus dem
         * angemeldeten Bereich: der Endpunkt legt nichts an. */
        mt_config_lage('kaputt');
        if ($schreiben_erlaubt) {
            mt_log_gebremst('config_kaputt', 'FEHLER: ' . $p['config'] . ' ist kein gueltiges JSON ('
                . json_last_error_msg() . ', ' . strlen($roh) . ' Byte). Die beschaedigte Datei '
                . 'bleibt als .kaputt liegen.');
            if (!is_file($p['config'] . '.kaputt')) {
                @copy($p['config'], $p['config'] . '.kaputt');
                @chmod($p['config'] . '.kaputt', 0600);
            }
        }
    } elseif ($roh === '{}') {
        mt_config_lage('leer');
    } else {
        mt_config_lage('fehlt');
    }

    /* Zweitschrift: lesen, pruefen, EINMAL zurueckschreiben. */
    if (is_file($p['sicherung'])) {
        $zweit = mt_json_lesen($p['sicherung']);
        if ($zweit) {
            mt_config_lage('zweitschrift');
            // Lesen allein genuegt nicht: bin/matter_dienst.py liest dieselbe
            // Datei und wuerde weiter mit der Werkseinstellung laufen, waehrend
            // die Oberflaeche die guten Werte zeigt. Deshalb wird die
            // Konfiguration hier wirklich wiederhergestellt - aber ueber
            // mt_json_schreiben(), NICHT ueber mt_config_speichern(): die
            // Sicherung bleibt unangetastet, sie ist ja die Quelle.
            if ($schreiben_erlaubt) {
                if (!is_dir($p['configdir'])) {
                    @mkdir($p['configdir'], 0775, true);
                }
                mt_json_schreiben($p['config'], $zweit, 0600);
            }
            return array_merge(mt_vorgaben(), $zweit);
        }
        if (is_file($p['sicherung']) && trim((string) @file_get_contents($p['sicherung'])) !== '') {
            /* Die Zweitschrift ist da, aber selbst unlesbar. Bis 0.9.16 wurde
             * sie danach kommentarlos mit der Werkseinstellung ueberschrieben.
             * Jetzt bleibt sie als .kaputt liegen, und die Lage sagt es. */
            mt_config_lage('beide_kaputt');
            if ($schreiben_erlaubt) {
                mt_log_gebremst('sicherung_kaputt', 'FEHLER: auch die Zweitschrift '
                    . $p['sicherung'] . ' ist kein gueltiges JSON. Sie bleibt als .kaputt '
                    . 'liegen. Es wird nichts gespeichert, bis jemand eingreift.');
                if (!is_file($p['sicherung'] . '.kaputt')) {
                    @copy($p['sicherung'], $p['sicherung'] . '.kaputt');
                    @chmod($p['sicherung'] . '.kaputt', 0600);
                }
            }
        }
    }
    return array_merge(mt_vorgaben(), mt_json_lesen($p['config']));
}

/**
 * Die Lage der Konfiguration, gemerkt fuer den Reiter Test.
 *
 * Fuenf Ausgaenge: ok, fehlt, leer, zweitschrift, kaputt, beide_kaputt. Bis
 * 0.9.16 wusste die Oberflaeche davon nichts - die Selbstheilung ist der
 * teuerste Mechanismus dieses Plugins, und keine Zeile sagte, ob sie gerade
 * gegriffen hat.
 */
function mt_config_lage($setzen = null)
{
    static $lage = 'ungeprueft';
    if ($setzen !== null) {
        $lage = $setzen;
    }
    return $lage;
}

/**
 * Darf gespeichert werden?
 *
 * Nein, solange die Konfiguration als beschaedigt gilt UND keine brauchbare
 * Zweitschrift dahinterstand. Sonst laeuft die Kette von 0.9.9 wieder an:
 * Werkseinstellung -> leeres Token -> neues Token -> Werkseinstellung
 * gespeichert -> Zweitschrift ueberschrieben.
 */
function mt_config_darf_schreiben()
{
    return !in_array(mt_config_lage(), array('kaputt', 'beide_kaputt'), true);
}

function mt_config_speichern($cfg)
{
    $p = mt_paths();
    /* Wache: solange die Konfiguration als beschaedigt gilt und keine
     * brauchbare Zweitschrift dahinterstand, wird gar nichts geschrieben.
     * Wer speichert, waehrend die Lage unklar ist, macht aus einem lesbaren
     * Schaden einen endgueltigen. */
    if (!mt_config_darf_schreiben()) {
        return false;
    }
    // Die Konfiguration enthaelt WLAN-Passwort und Thread-Dataset - beides
    // sind Netzzugangsdaten. Deshalb 0600, nicht 0644.
    if (!mt_json_schreiben($p['config'], $cfg, 0600)) {
        return false;
    }
    // Wache: die Sicherung wird NUR ueberschrieben, wenn wirklich eine
    // Konfiguration gespeichert wurde. Ohne Aktionstoken ist das die blanke
    // Werkseinstellung - und die ueber eine gute Sicherung zu schreiben, war
    // genau der Schaden, den diese Datei bis 0.9.9 angerichtet hat.
    if (trim((string) (isset($cfg['aktionstoken']) ? $cfg['aktionstoken'] : '')) !== '') {
        @copy($p['config'], $p['sicherung']);
        @chmod($p['sicherung'], 0600);
    }
    return true;
}

/** Die Cluster-Tabelle: EINE Datei fuer Dienst und Oberflaeche. */
function mt_tabelle()
{
    static $t = null;
    if ($t !== null) {
        return $t;
    }
    $p = mt_paths();
    foreach (array($p['tabelle'], dirname(dirname(__DIR__)) . '/templates/matter_cluster.json') as $kand) {
        $d = mt_json_lesen($kand);
        if (!empty($d['cluster'])) {
            $t = $d;
            return $t;
        }
    }
    $t = array('cluster' => array(), 'geraetetyp' => array());
    return $t;
}

/** Zufallstoken fuer den unangemeldeten Endpunkt. */
function mt_token_erzeugen($laenge = 24)
{
    $zeichen = 'abcdefghijkmnpqrstuvwxyz23456789';
    $t = '';
    for ($i = 0; $i < $laenge; $i++) {
        $t .= $zeichen[random_int(0, strlen($zeichen) - 1)];
    }
    return $t;
}

/**
 * Das Aktionstoken - erzeugt beim ERSTEN Anlegen und danach nie wieder.
 *
 * Unterschieden wird an der rohen Datei, nicht an der ergaenzten
 * Konfiguration: mt_vorgaben() liefert den Schluessel immer mit, damit kann
 * array_key_exists() auf dem Ergebnis nichts mehr unterscheiden.
 *
 *   Schluessel fehlt in der Datei -> noch nie gesetzt -> erzeugen
 *   Schluessel da, aber leer      -> bewusst geleert  -> in Ruhe lassen
 *
 * Bis 0.9.16 wurde jedes geleerte Token beim naechsten Seitenaufruf neu
 * gewuerfelt. Wer den Zugang absichtlich schliessen wollte, bekam ihn
 * zurueck - und die Adressen im Miniserver wurden dabei stumm ungueltig.
 * Wieder einschalten laesst es sich jederzeit mit dem Knopf im Reiter
 * "Einbindung in Loxone".
 */
function mt_token()
{
    $p = mt_paths();
    $cfg = mt_config();
    if (trim((string) $cfg['aktionstoken']) !== '') {
        return (string) $cfg['aktionstoken'];
    }
    $roh = mt_json_lesen($p['config']);
    if (array_key_exists('aktionstoken', $roh)) {
        return '';   // bewusst geleert
    }
    $cfg['aktionstoken'] = mt_token_erzeugen();
    if (!mt_config_speichern($cfg)) {
        return '';
    }
    return (string) $cfg['aktionstoken'];
}

/* ---------------- Zwischenspeicher ---------------- */

function mt_loxone()
{
    return mt_json_lesen(mt_paths()['datadir'] . '/loxone.json');
}

function mt_zustand()
{
    return mt_json_lesen(mt_paths()['datadir'] . '/zustand.json');
}

/* mt_cache() ist mit 0.9.10 entfallen. Sie las eine cache.json, die der
 * Dienst bei JEDEM Ereignis schrieb und die niemand gelesen hat - die
 * Funktion selbst wurde im ganzen Plugin nie aufgerufen. Der Dienst schreibt
 * die Datei nicht mehr und raeumt eine vorhandene beim Start weg. */

function mt_geraete()
{
    $l = mt_loxone();
    return isset($l['geraete']) && is_array($l['geraete']) ? $l['geraete'] : array();
}

/* ==================================================================
 * Matter2Lox-b1 (Verbesserungsbau 30.09.2026): "zuletzt gesehen" und
 * "Themen dieses Geraets abraeumen" im Reiter Einstellungen
 * ================================================================== */

/** Die festen Geraetenummern: Knotennummer => Geraetenummer (Nummerndatei). */
function mt_nummern()
{
    $d = mt_json_lesen(mt_paths()['nummern']);
    $aus = array();
    foreach ((isset($d['nummern']) && is_array($d['nummern']) ? $d['nummern'] : array()) as $k => $nr) {
        if (preg_match('/^[0-9]{1,9}$/', (string) $k) && is_numeric($nr) && (int) $nr > 0) {
            $aus[(int) $k] = (int) $nr;
        }
    }
    return $aus;
}

/**
 * Geraetenummern ohne Geraet: die Nummerndatei vergibt eine Nummer nie neu,
 * auch nicht nach dem Entfernen des Knotens. Unter diesen Nummern stehen im
 * Broker hoechstens noch "-" (Entscheidung 5) - genau dafuer ist der Knopf
 * "Themen dieses Geraets abraeumen" da. Rueckgabe: Geraetenummer => Knoten.
 */
function mt_geraete_entfernt($geraete)
{
    $aus = array();
    foreach (mt_nummern() as $knoten => $nr) {
        if (!isset($geraete[(string) $nr])) {
            $aus[$nr] = $knoten;
        }
    }
    ksort($aus);
    return $aus;
}

/** Alle Nummern, fuer die der Knopf gilt: bekannte und entfernte Geraete. */
function mt_geraetenummern()
{
    $geraete = mt_geraete();
    $aus = array();
    foreach (array_keys($geraete) as $nr) {
        if (preg_match('/^[1-9][0-9]{0,2}$/', (string) $nr)) {
            $aus[] = (int) $nr;
        }
    }
    foreach (array_keys(mt_geraete_entfernt($geraete)) as $nr) {
        if ($nr >= 1 && $nr <= 999) {
            $aus[] = (int) $nr;
        }
    }
    return array_values(array_unique($aus));
}

/** "zuletzt gesehen" als Text: Zeit und Abstand, oder der Satz fuer "nie". */
function mt_zuletzt_text($ts)
{
    if (!is_numeric($ts) || (int) $ts <= 0) {
        return mt_t('EINST.ZULETZT_NIE');
    }
    $ab = time() - (int) $ts;
    if ($ab < 120) {
        $vor = max(0, $ab) . ' s';
    } elseif ($ab < 7200) {
        $vor = (int) floor($ab / 60) . ' min';
    } elseif ($ab < 172800) {
        $vor = (int) floor($ab / 3600) . ' h';
    } else {
        $vor = (int) floor($ab / 86400) . ' d';
    }
    return sprintf(mt_t('EINST.ZULETZT_VOR'), date('Y-m-d H:i', (int) $ts), $vor);
}

/**
 * matter_dienst.py mit einem Abraeum-Schalter aufrufen (b1, Tuer-1), als der
 * Benutzer der Oberflaeche, mit Frist. Der Dienst fragt den Broker selbst
 * (Zugang aus der general.json; das Kennwort geht nie ueber eine
 * Befehlszeile) und liest nach. Rueckgabe: array(rc, Zeilen ohne
 * <OK>/<INFO>/<WARNING>). rc wie praefix_leeren(): 0 geleert oder nicht
 * nachpruefbar gesendet, 1 es steht noch etwas, 2 nicht moeglich; -1 Aufruf
 * unmoeglich (Archiv, kein Python, kein Skript).
 */
function mt_dienst_leeren($schalter)
{
    $p = mt_paths();
    if ($p['home'] === '') {
        return array(-1, array(mt_t('EINST.ARCHIV_VERWEIGERT')));
    }
    $skript = $p['bindir'] . '/matter_dienst.py';
    $py = $p['bindir'] . '/venv/bin/python3';
    if (!is_file($py)) {
        $gef = array();
        @exec('command -v python3 2>/dev/null', $gef);
        $py = isset($gef[0]) ? trim($gef[0]) : '';
    }
    if ($py === '' || !is_file($skript)) {
        return array(-1, array(sprintf(mt_t('EINST.LEEREN_KEIN_PYTHON'), $skript)));
    }
    $args = '';
    foreach ((array) $schalter as $s) {
        $args .= ' ' . escapeshellarg((string) $s);
    }
    $aus = array();
    $rc = 0;
    @exec('env PYTHONDONTWRITEBYTECODE=1 LBHOMEDIR=' . escapeshellarg($p['home'])
          . ' LBPPLUGINDIR=' . escapeshellarg($p['plugin']) . ' timeout -k 5 90 '
          . escapeshellarg($py) . ' ' . escapeshellarg($skript) . $args . ' < /dev/null 2>&1',
          $aus, $rc);
    $zeilen = array();
    foreach ($aus as $z) {
        $z = trim(preg_replace('/^<(OK|INFO|WARNING|ERROR)>\s*/', '', (string) $z));
        if ($z !== '') {
            $zeilen[] = $z;
        }
    }
    return array((int) $rc, $zeilen);
}

/** Tuer-1: die je unter haus/tuer/ gesendeten Themen => Geraetenummer. */
function mt_haus_gemerkt()
{
    $d = mt_json_lesen(mt_paths()['haus']);
    $aus = array();
    foreach ((isset($d['themen']) && is_array($d['themen']) ? $d['themen'] : array()) as $t => $nr) {
        if (preg_match('#^haus/tuer/[a-z0-9_\-]{1,60}/(offen|verriegelt)$#', (string) $t)) {
            $aus[(string) $t] = (int) $nr;
        }
    }
    ksort($aus);
    return $aus;
}

/* ==================================================================
 * X-3 (Verbesserungsbau 30.09.2026): "Einstellungen sichern" warnt, wenn
 * ein gespeicherter Wert das eigene Zurueckspielen nicht bestuende
 * ================================================================== */

/** Schluessel, die in einer aelteren Sicherung fehlen duerfen (Tuer-1). */
function mt_sicherung_neue_schluessel()
{
    /* 0.9.35 (Nr. 1): die vier neuen Schluessel duerfen in einer Sicherung
     * bis 0.9.34 fehlen; sie behalten dann ihre Vorgabe. */
    return array('tuer_haus', 'server_lokal', 'primary_interface', 'lesetoken', 'loxone_adresse');
}

/**
 * Welche gespeicherten Werte wuerde das Zurueckspielen abweisen? Geprueft
 * wird mit DERSELBEN Funktion wie dort (mt_wert_pruefen) und gegen dieselbe
 * Schluesselliste (mt_vorgaben); als Gegenprobe laeuft danach
 * mt_sicherung_lesen() ueber die ganze Datei - weist sie ab, ohne dass ein
 * Name gefunden wurde, steht "*" da. Rueckgabe: Liste der NAMEN, nie Werte.
 */
function mt_sicherung_altwerte($cfg)
{
    $bekannt = mt_vorgaben();
    $namen = array();
    foreach ((array) $cfg as $k => $w) {
        $k = (string) $k;
        if ($k !== '' && $k[0] === '_') {
            continue;
        }
        if (!array_key_exists($k, $bekannt) || mt_wert_pruefen($k, $w) !== '') {
            $namen[] = $k;
        }
    }
    if (!$namen) {
        $erg = mt_sicherung_lesen((string) json_encode($cfg));
        if ($erg[0] === null) {
            $namen[] = '*';
        }
    }
    return $namen;
}

/* ==================================================================
 * X-2 (Regeln/04, Abschnitt "Nach einer Beanstandung stehen die
 * eingetippten Werte wieder im Formular", Hausregel seit 30.09.2026)
 *
 * Mit der Einmalmeldung (0600, Datenordner, 120 s, beim GET gelesen und
 * geloescht) reisen die Eingaben des EINEN beanstandeten Formulars - nur
 * dessen Felder aus der Liste unten, nur nach einer Beanstandung. Nie ein
 * Geheimnis: WLAN-Passwort und Thread-Dataset (es traegt den Netzschluessel)
 * stehen als 'geheim' darin, damit sie markiert werden koennen; ihr Wert
 * reist nie mit, das Feld zeigt den gespeicherten Stand bzw. den Platzhalter.
 * ================================================================== */

/** Die Felder je Formular (Name des versteckten Feldes), mit ihrer Art. */
function mt_eingabe_felder()
{
    return array(
        'speichern'      => array('eigener_container' => 'haken', 'server_host' => 'text',
                                  'server_port' => 'text', 'container_name' => 'text',
                                  'container_abbild' => 'text', 'bluetooth_adapter' => 'text',
                                  'wartezeit' => 'text', 'sendetakt' => 'text', 'herzschlag' => 'text',
                                  'steuerung_ein' => 'haken', 'schloss_ein' => 'haken',
                                  // 0.9.35 (Nr. 1)
                                  'server_lokal' => 'haken', 'primary_interface' => 'text'),
        'save_mqtt'      => array('mqtt_ein' => 'haken', 'mqtt_topic' => 'text', 'mqtt_nur' => 'text',
                                  'roh_ein' => 'haken', 'tuer_haus' => 'haken'),
        'netz_speichern' => array('wlan_ssid' => 'text', 'wlan_passwort' => 'geheim',
                                  'thread_dataset' => 'geheim'),
        'br_holen'       => array('thread_br' => 'text'),
        // 0.9.35 (Nr. 1): Adresse fuer die Loxone-Vorlagen, eigenes Formular.
        'save_loxone'    => array('loxone_adresse' => 'text'),
        /* 0.9.35 (Nr. 4): die Felder der Reiter Anlernen und Test bleiben nach
         * der Umleitung stehen - auch nach einem Erfolg, nicht nur nach einer
         * Beanstandung. Der Code vom Geraet reist nie mit (ein zweiter Druck
         * soll ihn nicht unbemerkt erneut senden). */
        'anlernen'       => array('code' => 'geheim', 'anlern_ip' => 'text', 'nur_netz' => 'haken'),
        'verwalten'      => array('test_geraet' => 'text', 'geraetename' => 'text'),
        'schalten'       => array('test_geraet' => 'text', 'test_endpunkt' => 'text', 'test_wert' => 'text'),
    );
}

/** Die Eingaben eines abgewiesenen POST fuer die Einmalmeldung. */
function mt_eingaben_sammeln($formular, $beanstandet)
{
    $liste = mt_eingabe_felder();
    if (!isset($liste[$formular])) {
        return array();
    }
    $werte = array();
    foreach ($liste[$formular] as $k => $art) {
        if ($art === 'geheim') {
            continue;                       // nie mitnehmen
        }
        if ($art === 'haken') {
            $werte[$k] = isset($_POST[$k]) ? 1 : 0;
        } else {
            $werte[$k] = (isset($_POST[$k]) && is_string($_POST[$k])) ? substr($_POST[$k], 0, 1000) : '';
        }
    }
    $felder = array();
    foreach ((array) $beanstandet as $k) {
        if (is_string($k) && isset($liste[$formular][$k]) && !in_array($k, $felder, true)) {
            $felder[] = $k;
        }
    }
    return array('formular' => $formular, 'werte' => $werte, 'felder' => $felder);
}

/** Die Eingaben aus der Einmalmeldung - nur, was die Liste kennt. */
function mt_eingaben_pruefen($e)
{
    $liste = mt_eingabe_felder();
    if (!is_array($e) || !isset($e['formular']) || !is_string($e['formular'])
        || !isset($liste[$e['formular']])) {
        return array();
    }
    $f = $e['formular'];
    $werte = array();
    if (isset($e['werte']) && is_array($e['werte'])) {
        foreach ($liste[$f] as $k => $art) {
            if ($art === 'geheim' || !array_key_exists($k, $e['werte'])) {
                continue;
            }
            $w = $e['werte'][$k];
            if ($art === 'haken') {
                $werte[$k] = empty($w) ? 0 : 1;
            } elseif (is_string($w)) {
                $werte[$k] = $w;
            }
        }
    }
    $felder = array();
    if (isset($e['felder']) && is_array($e['felder'])) {
        foreach ($e['felder'] as $k) {
            if (is_string($k) && isset($liste[$f][$k])) {
                $felder[] = $k;
            }
        }
    }
    return array('formular' => $f, 'werte' => $werte, 'felder' => $felder);
}

/** Traegt die Seite gerade die Eingaben dieses Formulars? */
function mt_eingaben_aktiv($formular)
{
    $e = isset($GLOBALS['mt_eingaben']) ? $GLOBALS['mt_eingaben'] : array();
    return is_array($e) && isset($e['formular']) && $e['formular'] === $formular;
}

/** Der anzuzeigende Wert: die Eingabe nach einer Beanstandung, sonst der gespeicherte. */
function mt_eingabe($formular, $feld, $gespeichert)
{
    if (!mt_eingaben_aktiv($formular)) {
        return $gespeichert;
    }
    $w = $GLOBALS['mt_eingaben']['werte'];
    return array_key_exists($feld, $w) ? $w[$feld] : $gespeichert;
}

/** Markierung eines beanstandeten Feldes (Klasse und aria-invalid). */
function mt_markierung($formular, $feld)
{
    if (!mt_eingaben_aktiv($formular)) {
        return '';
    }
    return in_array($feld, $GLOBALS['mt_eingaben']['felder'], true)
        ? ' class="sm-beanstandet" aria-invalid="true"' : '';
}

function mt_serverinfo()
{
    $l = mt_loxone();
    return isset($l['server']) && is_array($l['server']) ? $l['server'] : array();
}

/** Alter des Abbilds in Sekunden, oder -1 wenn es keines gibt. */
function mt_alter()
{
    $l = mt_loxone();
    return isset($l['ts']) ? max(0, time() - (int) $l['ts']) : -1;
}

/**
 * OK fuer den Endpunkt (C3, Durchgang 30.09.2026, Entscheidung 4).
 *
 * OK=1 nur, wenn das Abbild eine Verbindung meldet UND der Herzschlag des
 * Dienstes in zustand.json hoechstens 3 x Takt alt ist. Bis 0.9.30 kam OK
 * allein aus loxone.json: ein mit kill -9 beendeter Dienst meldete fuer immer
 * OK=1 (gemessen, Bericht code C3). ALTER bleibt unveraendert daneben; es
 * waechst in einem ruhigen Haus auch bei gesundem Dienst, weil loxone.json
 * nur bei Aenderungen geschrieben wird - als Altersgrenze taugt es nicht.
 * Ist der Herzschlag abgeschaltet (0), gibt es kein Erzeugnis, an dem sich
 * das Alter messen liesse; dann bleibt es bei der Aussage aus loxone.json
 * (Hilfe und Feldtabelle sagen es).
 */
function mt_ok_endpunkt($lox, $cfg)
{
    if (!is_array($lox) || empty($lox['ok'])) {
        return 0;
    }
    $hz = isset($cfg['herzschlag']) && is_numeric($cfg['herzschlag']) ? (int) $cfg['herzschlag'] : 60;
    $hz = max(0, min(3600, $hz));
    if ($hz === 0) {
        return 1;
    }
    $z = mt_zustand();
    $hs = isset($z['herzschlag']) && is_numeric($z['herzschlag']) ? (int) $z['herzschlag'] : 0;
    return ($hs > 0 && time() - $hs <= 3 * $hz) ? 1 : 0;
}

/* ---------------- Protokollierung ---------------- */

function mt_log($text)
{
    $p = mt_paths();
    if (!is_dir($p['logdir'])) {
        @mkdir($p['logdir'], 0775, true);
    }
    clearstatcache(true, $p['log']);
    if (is_file($p['log']) && filesize($p['log']) > 512000) {
        // Rotation: die letzten 400 Zeilen behalten
        $rest = array_slice(file($p['log'], FILE_IGNORE_NEW_LINES) ?: array(), -400);
        @file_put_contents($p['log'], implode("\n", $rest) . "\n");
    }
    @file_put_contents($p['log'], '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n", FILE_APPEND);
}

/** Dieselbe Meldung hoechstens einmal je Zeitfenster - sonst wird die
 *  Logdatei durch eine Dauerstoerung unlesbar. */
function mt_log_gebremst($schluessel, $text, $sekunden = 3600)
{
    $f = mt_paths()['datadir'] . '/.meld_' . preg_replace('/[^a-z0-9_]/i', '', $schluessel);
    $letzte = is_file($f) ? (int) @file_get_contents($f) : 0;
    if (time() - $letzte >= $sekunden) {
        @file_put_contents($f, (string) time());
        mt_log($text);
    }
}

/* ==================================================================
 * Gleichwert-Unterdrueckung des Endpunkts (X-7, B-Nachzug 01.10.2026,
 * ENTSCHEIDUNGEN Nr. 19). Vorbild: die Schlossbremse in html/index.php,
 * EVCC 0.9.37 (Befehlsbremse) und der Heimkino-Nachzug.
 *
 * Ein Sollwert-Befehl mit DEMSELBEN Wert fuer dasselbe Geraet (Knoten und
 * Endpunkt) geht innerhalb von 60 s nicht erneut hinaus (HTTP 200,
 * UNVERAENDERT=1). Bis 0.9.34 ging jeder Aufruf eines flatternden
 * Loxone-Ausgangs bis zum Geraet. Ein anderer Wert geht sofort hinaus -
 * ein zusaetzliches 429 gibt es nicht (Dimmen schickt mehrere Werte je
 * Sekunde). Ereignisse (umschalten, rollo_auf/_zu/_stopp, identify) und die
 * Rohwege (attribut, befehl) gehen immer hinaus. sperren/entsperren haben
 * ihre eigene Schlossbremse und kommen hier nicht vor.
 *
 * Gemerkt wird der zuletzt ueber den Endpunkt GESENDETE Wert, nicht der
 * Zustand des Geraets: was in derselben Minute am Wandschalter, in einer App
 * oder im Reiter Test geschieht, sieht der Merker nicht.
 *
 * 0.9.35 (Nr. 4): Sperre JE GERAET statt einer fuer alle. Bis 0.9.34 hielt
 * ein Befehl die eine Merkerdatei ueber die ganze Wartezeit gesperrt - ein
 * langsames Geraet hielt damit jeden Sollwert-Befehl an jedes andere Geraet
 * an, Loxone-Ausgaenge liefen der Reihe nach in ihre Frist. Jetzt:
 *   - eine eigene Sperrdatei je Geraet (Knoten|Endpunkt) im Unterordner
 *     gleichwert/ des Datenordners, gehalten waehrend des ganzen Befehls -
 *     fuenf gleichzeitige gleiche Aufrufe an DASSELBE Geraet senden weiter
 *     genau einmal, andere Geraete warten nicht;
 *   - die gemeinsame Merkerdatei nur kurz zum Lesen und zum Nachfuehren;
 *     nachgefuehrt wird auf dem FRISCH gelesenen Stand, damit kein Befehl
 *     die Eintraege eines anderen Geraets mit einem alten Stand ueberschreibt;
 *   - jede Sperre mit LOCK_NB und Wiederholung bis etwa 2 s. Wer sie dann
 *     nicht hat, bekommt 503 (GRUND=BESCHAEFTIGT), statt bis zur Frist des
 *     Miniservers zu haengen.
 * Die Sperrdateien bleiben liegen (eine je Geraet und Endpunkt): eine
 * geloeschte Sperrdatei, die ein anderer Prozess noch offen hat, sperrt
 * nichts mehr.
 * ================================================================== */

/**
 * Merkerschluessel eines Sollwert-Befehls, '' fuer alle anderen.
 *
 * ein und aus teilen sich 'schalter': der Sollwert ist der Zustand, nicht
 * der Befehl - aus nach ein geht hinaus. farbton und saettigung sind
 * Teilwerte der Farbe und ebenso Sollwerte (Nr. 19 "Farbe").
 * 0.9.35 (Nr. 4): dazu loxfarbe, farbe_xy und lamelle.
 */
function mt_gleichwert_schluessel($aktion)
{
    $s = array(
        'ein' => 'schalter', 'aus' => 'schalter', 'helligkeit' => 'helligkeit',
        'farbtemperatur' => 'farbtemperatur', 'farbe' => 'farbe', 'farbton' => 'farbton',
        'saettigung' => 'saettigung', 'rollo' => 'rollo', 'soll_heizen' => 'soll_heizen',
        'soll_kuehlen' => 'soll_kuehlen', 'betriebsart' => 'betriebsart', 'luefter' => 'luefter',
        'loxfarbe' => 'loxfarbe', 'farbe_xy' => 'farbe_xy', 'lamelle' => 'lamelle',
    );
    return isset($s[$aktion]) ? $s[$aktion] : '';
}

/**
 * Welche gemerkten Sollwerte DESSELBEN Geraets ein Befehl veraendern kann -
 * sie verfallen nach dem Befehl, damit der naechste gleiche Wert hinausgeht.
 * '*' heisst: alle Eintraege des Knotens (Rohwege koennen alles aendern).
 *
 * - ein/aus/umschalten stellen den Schalter und koennen die Helligkeit
 *   aendern (On stellt die zuletzt gemerkte Stufe des Geraets her);
 * - helligkeit schaltet mit (MoveToLevelWithOnOff, 0 = Off);
 * - die Farbbefehle ueberschreiben einander;
 * - rollo_auf/_zu/_stopp bewegen den Behang.
 * 0.9.35 (Nr. 4): loxfarbe stellt Helligkeit, Schalter UND Farbe - es
 * verfaellt mit allen dreien in beide Richtungen. Hoehe und Lamelle koennen
 * einander mitnehmen (UpOrOpen faehrt beide, manche Antriebe kippen beim
 * Verfahren); im Zweifel geht der naechste gleiche Wert hinaus.
 */
function mt_gleichwert_verfaellt($aktion)
{
    $farbe = array('farbtemperatur', 'farbe', 'farbton', 'saettigung', 'farbe_xy', 'loxfarbe');
    if (in_array($aktion, array('ein', 'aus', 'umschalten'), true)) {
        return array('schalter', 'helligkeit', 'loxfarbe');
    }
    if ($aktion === 'helligkeit') {
        return array('schalter', 'loxfarbe');
    }
    if ($aktion === 'loxfarbe') {
        return array_values(array_merge(array('schalter', 'helligkeit'),
                                        array_diff($farbe, array('loxfarbe'))));
    }
    if (in_array($aktion, $farbe, true)) {
        return array_values(array_diff($farbe, array($aktion)));
    }
    if (in_array($aktion, array('rollo_auf', 'rollo_zu', 'rollo_stopp'), true)) {
        return array('rollo', 'lamelle');
    }
    if ($aktion === 'rollo') {
        return array('lamelle');
    }
    if ($aktion === 'lamelle') {
        return array('rollo');
    }
    if ($aktion === 'attribut' || $aktion === 'befehl') {
        return array('*');
    }
    return array();
}

/** Betrifft ein Befehl den Merker (pruefen oder nachfuehren)? */
function mt_gleichwert_betrifft($aktion)
{
    return mt_gleichwert_schluessel($aktion) !== '' || mt_gleichwert_verfaellt($aktion) !== array();
}

/**
 * Der verglichene Wert in einer Form: bei ein/aus der Befehl, sonst die Zahl
 * mit hoechstens drei Nachkommastellen und ohne Nullen am Ende (20, 20.0 und
 * 20,0 sind gleich). farbe traegt die Saettigung mit - ohne Angabe 100, wie
 * der Dienst sie dann setzt. 0.9.35 (Nr. 4): farbe_xy vergleicht x/y mit
 * vier Stellen (so fein loest Matter den Farbort auf, 1/65536), loxfarbe
 * die ganze Zahl.
 */
function mt_gleichwert_wert($befehl)
{
    $aktion = isset($befehl['aktion']) ? (string) $befehl['aktion'] : '';
    if ($aktion === 'ein' || $aktion === 'aus') {
        return $aktion;
    }
    $zahl = function ($v, $stellen = 3) {
        $t = rtrim(rtrim(sprintf('%.' . (int) $stellen . 'F', (float) $v), '0'), '.');
        return $t === '-0' ? '0' : $t;
    };
    if ($aktion === 'farbe_xy') {
        return $zahl(isset($befehl['x']) ? $befehl['x'] : 0, 4) . '/'
             . $zahl(isset($befehl['y']) ? $befehl['y'] : 0, 4);
    }
    if ($aktion === 'loxfarbe') {
        return isset($befehl['wert']) ? (string) (int) $befehl['wert'] : '';
    }
    $w = isset($befehl['wert']) ? $zahl($befehl['wert']) : '';
    if ($aktion === 'farbe') {
        $w .= '/' . $zahl(isset($befehl['saettigung']) ? $befehl['saettigung'] : 100);
    }
    return $w;
}

function mt_gleichwert_datei()
{
    return mt_paths()['datadir'] . '/befehl_gleichwert.json';
}

/** 0.9.35 (Nr. 4): Ordner der Sperrdateien je Geraet. */
function mt_gleichwert_ordner()
{
    return mt_paths()['datadir'] . '/gleichwert';
}

/**
 * 0.9.35 (Nr. 4): eine Datei oeffnen und sperren, ohne zu haengen.
 *
 * LOCK_NB mit Wiederholung alle 50 ms bis $frist Sekunden. Rueckgabe:
 * array(Dateizeiger|false, Grund) - Grund '' bei Erfolg, 'MERKER', wenn die
 * Datei sich nicht oeffnen laesst oder flock selbst scheitert, 'BESCHAEFTIGT',
 * wenn ein anderer Aufruf die Sperre ueber die Frist hinaus haelt.
 * "e" (close-on-exec): ein Kindprozess erbt die gesperrte Datei nicht
 * (Fehlerklasse 3, "Sperre vererbt sich an Kinder").
 */
function mt_gleichwert_sperren($datei, $art = LOCK_EX, $frist = 2.0)
{
    if (!is_dir(dirname($datei))) {
        return array(false, 'MERKER');
    }
    $fh = @fopen($datei, 'c+e');
    if ($fh === false) {
        return array(false, 'MERKER');
    }
    $ende = microtime(true) + (float) $frist;
    while (true) {
        $blockiert = 0;
        if (@flock($fh, $art | LOCK_NB, $blockiert)) {
            return array($fh, '');
        }
        /* flock scheitert auch ohne fremde Sperre (Dateisystem ohne
         * Sperren). Das ist kein "beschaeftigt", sondern ein Merkerfehler -
         * ohne $blockiert waere es 2 s Warten fuer nichts. */
        if (!$blockiert) {
            fclose($fh);
            return array(false, 'MERKER');
        }
        if (microtime(true) >= $ende) {
            fclose($fh);
            return array(false, 'BESCHAEFTIGT');
        }
        usleep(50000);
    }
}

/**
 * Die Sperre EINES Geraets ("Knoten|Endpunkt") nehmen.
 *
 * 0.9.35 (Nr. 4): bis 0.9.34 sperrte diese Funktion die gemeinsame
 * Merkerdatei ueber den ganzen Befehl. Rueckgabe wie mt_gleichwert_sperren().
 */
function mt_gleichwert_oeffnen($geraet)
{
    $ordner = mt_gleichwert_ordner();
    if (!is_dir($ordner)) {
        @mkdir($ordner, 0770, true);
    }
    $name = preg_replace('/[^0-9]+/', '_', (string) $geraet);
    return mt_gleichwert_sperren($ordner . '/' . $name . '.sperre');
}

/** Die Geraetesperre loesen. */
function mt_gleichwert_freigeben($fh)
{
    if (is_resource($fh)) {
        @flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/**
 * Den gemeinsamen Merker lesen - nur fuer die Dauer des Lesens gesperrt.
 * Rueckgabe: array(Merker|false, Grund). Unlesbarer INHALT gilt als leer
 * (dann geht der Befehl hinaus - im Zweifel senden, nie still verschlucken);
 * eine Datei, die sich nicht oeffnen oder sperren laesst, ergibt false.
 */
function mt_gleichwert_lesen()
{
    list($fh, $grund) = mt_gleichwert_sperren(mt_gleichwert_datei(), LOCK_SH);
    if ($fh === false) {
        return array(false, $grund);
    }
    $d = json_decode((string) stream_get_contents($fh), true);
    @flock($fh, LOCK_UN);
    fclose($fh);
    return array(is_array($d) ? $d : array(), '');
}

/** Sekunden seit DEMSELBEN Wert fuer dieses Geraet ("Knoten|Endpunkt"),
 * -1, wenn ein anderer Wert gemerkt ist oder der gemerkte das Fenster
 * verlassen hat. */
function mt_gleichwert_seit($merker, $geraet, $schluessel, $wert, $fenster = 60)
{
    if ($schluessel === '' || !isset($merker[$geraet]) || !is_array($merker[$geraet])
        || !isset($merker[$geraet][$schluessel]) || !is_array($merker[$geraet][$schluessel])) {
        return -1;
    }
    $e = $merker[$geraet][$schluessel];
    if (!isset($e['w'], $e['t']) || (string) $e['w'] !== (string) $wert) {
        return -1;
    }
    $seit = time() - (int) $e['t'];
    return ($seit >= 0 && $seit < $fenster) ? $seit : -1;
}

/**
 * Der Merker nach einem ausgefuehrten Befehl.
 *
 * - Was der Befehl veraendern kann, verfaellt (mt_gleichwert_verfaellt()),
 *   gelungen oder nicht - im Zweifel geht der naechste gleiche Wert hinaus.
 * - Gemerkt wird der eigene Wert nur, wenn der Dienst die Ausfuehrung
 *   bestaetigt hat (OK=1). OK=0, ohne Antwort oder ueberholt (OK=2): der
 *   eigene Eintrag faellt weg, ein Wiederholen geht hinaus.
 * - Eintraege ab 60 s werden nicht mitgeschleppt.
 */
function mt_gleichwert_nachher($merker, $knoten, $geraet, $aktion, $wert, $gelungen)
{
    $verfaellt = mt_gleichwert_verfaellt($aktion);
    $knoten_weg = in_array('*', $verfaellt, true);
    $jetzt = time();
    $neu = array();
    foreach ($merker as $g => $eintraege) {
        $g = (string) $g;
        if (!is_array($eintraege) || ($knoten_weg && strpos($g, (int) $knoten . '|') === 0)) {
            continue;
        }
        foreach ($eintraege as $k => $e) {
            $k = (string) $k;
            if (!is_array($e) || !isset($e['t'], $e['w'])) {
                continue;
            }
            $alter = $jetzt - (int) $e['t'];
            if ($alter < 0 || $alter >= 60 || ($g === $geraet && in_array($k, $verfaellt, true))) {
                continue;
            }
            $neu[$g][$k] = $e;
        }
    }
    $schl = mt_gleichwert_schluessel($aktion);
    if ($schl !== '') {
        if ($gelungen) {
            $neu[$geraet][$schl] = array('w' => (string) $wert, 't' => $jetzt);
        } else {
            unset($neu[$geraet][$schl]);
            if (isset($neu[$geraet]) && $neu[$geraet] === array()) {
                unset($neu[$geraet]);
            }
        }
    }
    return $neu;
}

/**
 * Den Merker nach dem Befehl nachfuehren: kurz sperren, FRISCH lesen,
 * mt_gleichwert_nachher() anwenden, schreiben, loesen.
 * Erfolg nur bei vollstaendig geschriebenem Inhalt (Regeln/03).
 * 0.9.35 (Nr. 4): ersetzt das Schreiben ueber den lange gehaltenen Zeiger.
 */
function mt_gleichwert_nachfuehren($knoten, $geraet, $aktion, $wert, $gelungen)
{
    list($fh, $grund) = mt_gleichwert_sperren(mt_gleichwert_datei());
    if ($fh === false) {
        mt_log_gebremst('gleichwert_schreiben', 'Die Merkerdatei der Gleichwert-Unterdrueckung ('
            . mt_gleichwert_datei() . ') liess sich zum Nachfuehren nicht sperren (' . $grund
            . ') - ein gleicher Sollwert geht dann erneut hinaus.');
        return false;
    }
    $alt = json_decode((string) stream_get_contents($fh), true);
    $neu = mt_gleichwert_nachher(is_array($alt) ? $alt : array(), $knoten, $geraet, $aktion,
                                 $wert, $gelungen);
    $roh = (string) json_encode((object) $neu);
    $ok = ftruncate($fh, 0) && rewind($fh) && @fwrite($fh, $roh) === strlen($roh) && fflush($fh);
    if (!$ok) {
        mt_log_gebremst('gleichwert_schreiben', 'Die Merkerdatei der Gleichwert-Unterdrueckung ('
            . mt_gleichwert_datei() . ') liess sich nicht schreiben - ein gleicher Sollwert geht dann '
            . 'erneut hinaus. Pruefen: Platz und Eigentuemer (loxberry).');
    }
    @flock($fh, LOCK_UN);
    fclose($fh);
    return $ok;
}

/* ---------------- Dienst ---------------- */

/**
 * Ist die Nummer ein Dienst dieses Plugins?
 *
 * ARGUMENTWEISE, nicht als Teilzeichenkette. Bis 0.9.25 entschied hier ein
 * strpos() ueber die ganze Befehlszeile; am 18.09.2026 in WSL gemessen,
 * meldete mt_dienst_pid() daraufhin einen fremden 'tail -f
 * .../matter_dienst.py' als laufenden Dienst (Fall F3). Geprueft werden
 * zwei Dinge, genau wie in bin/dienst.sh: das erste Argument ist unser
 * Skript, das nullte ist ein Python.
 */
function mt_ist_dienst($pid)
{
    $pid = (int) $pid;
    if ($pid <= 0 || !is_dir('/proc/' . $pid)) {
        return false;
    }
    $roh = (string) @file_get_contents('/proc/' . $pid . '/cmdline');
    if ($roh === '') {
        return false;
    }
    $args = explode("\0", $roh);
    if (count($args) < 2) {
        return false;
    }
    if (!preg_match('/^python[0-9.]*$/', basename($args[0]))) {
        return false;
    }
    return $args[1] === mt_paths()['bindir'] . '/matter_dienst.py';
}

function mt_dienst_pid()
{
    $f = mt_paths()['datadir'] . '/dienst.pid';
    if (!is_file($f)) {
        return 0;
    }
    $pid = (int) trim((string) @file_get_contents($f));
    return mt_ist_dienst($pid) ? $pid : 0;
}

/**
 * Die Marke "Aktualisierung laeuft".
 *
 * preupgrade.sh legt sie als Erstes an (Unixzeit), postinstall.sh entfernt
 * sie nach dem Dienststart, postupgrade.sh noch einmal, uninstall raeumt
 * sie weg. Sie liegt NEBEN dem Datenordner, weil purge_installation den
 * Ordner selbst loescht. Solange sie gilt, startet kein Weg den Dienst -
 * auch der Knopf "Dienst starten" nicht.
 *
 * Rueckgabe: array(gilt, alter). gilt = 1 nur bei einer Marke, die
 * hoechstens 3600 s alt und hoechstens 300 s "aus der Zukunft" ist - die Uhr
 * kann nach dem Setzen ein Stueck zurueckspringen (in WSL gemessen bis
 * 0,64 s; dieselbe Grenze wie marke_gilt() in bin/dienst.sh, Fall M5 in
 * Pruefung-Matter2Lox-0.9.27). alter = -1, wenn keine Marke liegt.
 * Diese Funktion urteilt nur fuer den Reiter Test - die Entscheidung
 * faellt in bin/dienst.sh, damit sie an EINER Stelle steht.
 */
function mt_upgrade_marke()
{
    $p = mt_paths();
    // datadir ist .../data/plugins/<ordner>; die Marke liegt daneben.
    $f = $p['datadir'] . '.upgrade_laeuft';
    if (!is_file($f)) {
        return array(0, -1);
    }
    $roh = trim((string) @file_get_contents($f));
    if ($roh === '' || !ctype_digit($roh)) {
        // Unlesbar: sie gilt nicht (dieselbe Entscheidung wie in
        // dienst.sh) - gesagt wird es trotzdem, Alter -2.
        return array(0, -2);
    }
    $alter = time() - (int) $roh;
    return array(($alter >= -300 && $alter < 3600) ? 1 : 0, $alter);
}

function mt_dienst_soll()
{
    return is_file(mt_paths()['datadir'] . '/soll_laufen') ? 1 : 0;
}

/** $befehl ist 'start', 'stop' oder 'restart'. Rueckgabe: array(ok, Ausgabe) */
function mt_dienst($befehl)
{
    if (!in_array($befehl, array('start', 'stop', 'restart'), true)) {
        return array(0, 'Unbekannter Befehl.');
    }
    // Aus einem ausgepackten Archiv (Archivmodus in mt_paths()) wird nichts
    // gestartet und nichts angehalten - bis 0.9.28 hielt dieser Knopf aus
    // einem Archiv unter der Anlage deren Dienst an (Fall A3).
    if (mt_paths()['home'] === '') {
        return array(0, mt_t('EINST.ARCHIV_VERWEIGERT'));
    }
    // Wer den Dienst anfasst, veraendert die Lage - die zwischengespeicherte
    // Antwort auf "nimmt jemand Verbindungen an?" gilt danach nicht mehr.
    mt_erreichbar_vergessen();
    $skript = mt_paths()['bindir'] . '/dienst.sh';
    if (!is_file($skript)) {
        return array(0, 'dienst.sh nicht gefunden: ' . $skript);
    }
    $ausgabe = array();
    $code = 0;
    /* Mit Frist: 'restart' wartet selbst hoechstens rund 15 Sekunden (bin/
     * dienst.sh: zehnmal 1 s auf das Ende, dann 1 s, dann 1 s Anlauf). Ein
     * haengendes Skript hielt bis 0.9.28 die Seite unbegrenzt an (in WSL
     * gemessen 25.09.2026, Pruefung-Matter2Lox-0.9.29, Fall T3). Nach 60 s
     * SIGTERM, nach weiteren 5 s SIGKILL (Muster 13 der Nachlese). */
    @exec('timeout -k 5 60 ' . escapeshellarg($skript) . ' ' . escapeshellarg($befehl) . ' 2>&1',
          $ausgabe, $code);
    if ($code === 124 || $code === 137) {
        $ausgabe[] = sprintf(mt_t('EINST.FRIST_ABGELAUFEN'), 60);
    }
    /* Dritter Wert: der Rueckgabewert selbst. 3 heisst seit 0.9.30 (O4):
     * eine Aktualisierung laeuft, es wurde nichts angefasst. */
    return array($code === 0 ? 1 : 0, implode("\n", $ausgabe), (int) $code);
}

/* ---------------- Befehlswarteschlange ----------------
 *
 * Sowohl der Miniserver-Endpunkt als auch der Reiter Test setzen Befehle ueber
 * diese eine Funktion ab. Zwei Kopien derselben Logik laufen zwangslaeufig
 * auseinander.
 *
 * Rueckgabe: array(ok, Meldung, ist). ok = 1 erledigt, 0 abgelehnt oder
 * nicht ausgefuehrt, 2 ohne Ergebnis: eingereiht ohne Warten, in Arbeit,
 * aber ohne Antwort in der Wartezeit, oder vom Dienst als "von einem
 * neueren Befehl desselben Geraets ueberholt" gemeldet. Es wird nie ein
 * Erfolg gemeldet, den niemand geprueft hat.
 * 0.9.35 (Nr. 2): drittes Element "ist" - der Ist-Wert nach dem Befehl, wenn
 * die Antwortdatei ihn traegt, sonst null. list() mit zwei Elementen liest
 * weiter nur ok und Meldung.
 * 0.9.35 (Nr. 3): $cfg - wer die Konfiguration schon gelesen hat (der
 * unangemeldete Endpunkt mit mt_config(false)), gibt sie mit. Ohne sie las
 * diese Funktion mt_config() MIT Schreibrecht - aus dem Endpunkt heraus
 * haette das eine Zweitschrift zurueckgeschrieben oder eine .kaputt angelegt.
 */
function mt_befehl_absetzen($befehl, $wartezeit = null, $cfg = null)
{
    $p = mt_paths();
    if (!is_array($cfg)) {
        $cfg = mt_config();
    }
    if ($wartezeit === null) {
        $wartezeit = isset($cfg['wartezeit']) ? (int) $cfg['wartezeit'] : 10;
    }
    /* Obergrenze 200 s, nicht 20. Bis 0.9.16 stand hier min(20, ...) - das
     * Anlernen uebergibt 190 s, vier weitere Aufrufer 70 s. Alles darueber
     * wurde still auf 20 gekappt, und weil die Oberflaeche jeden Ausgang
     * ausser 1 als Fehler zeigt, erschien ein noch laufendes Anlernen als
     * roter Fehler - waehrend Hilfe und Knopftext "bis zu zwei Minuten"
     * versprachen. */
    $wartezeit = max(0, min(200, (int) $wartezeit));

    /* Der Dienst muss laufen, sonst bleibt der Befehl liegen und wird
     * moeglicherweise Tage spaeter ausgefuehrt. Bei einem Schloss bewegt sich
     * dabei eine Tuer. Der Endpunkt prueft das seit jeher (html/index.php);
     * die Knoepfe des Reiters Test taten es bis 0.9.16 nicht. */
    if (mt_dienst_pid() === 0) {
        return array(0, mt_t('TEST.A_DIENST_GESTOPPT'), null);
    }

    $ordner = $p['datadir'] . '/befehle';
    if (!is_dir($ordner) && !@mkdir($ordner, 0700, true) && !is_dir($ordner)) {
        return array(0, 'Der Ordner fuer die Warteschlange liess sich nicht anlegen: ' . $ordner, null);
    }
    @chmod($ordner, 0700);
    /* 0.9.35 (Nr. 2): zeitlich sortierbarer Name (VERTRAG "Warteschlange"):
     * Sekunden, Mikrosekunden, dann Zufall gegen Gleichstand. Der Dienst
     * arbeitet in Namensreihenfolge ab und fuehrt je Geraet nur den
     * juengsten Stellbefehl eines Durchlaufs aus - mit einem reinen
     * Zufallsnamen war "juengster" nicht bestimmbar. */
    $jetzt = microtime(true);
    $sek = (int) floor($jetzt);
    $usek = min(999999, max(0, (int) round(($jetzt - $sek) * 1000000)));
    $kennung = sprintf('%010d%06d_%s', $sek, $usek, bin2hex(random_bytes(4)));
    $datei = $ordner . '/' . $kennung . '.json';
    /* Ein Befehl kann WLAN-Passwort, Thread-Dataset oder den Anlerncode
     * tragen. Deshalb 0600, und die Rechte VOR dem Inhalt - genau wie bei der
     * Konfiguration. Bis 0.9.16 stand hier gar kein chmod. Der Zeitstempel
     * kommt mit, damit der Dienst einen alten Befehl verwerfen kann;
     * 0.9.35 (Nr. 2) als Gleitkommazahl (microtime). */
    $befehl['ts'] = $jetzt;
    if (!mt_json_schreiben($datei, $befehl, 0600)) {
        return array(0, 'Der Befehl liess sich nicht ablegen: ' . $datei, null);
    }
    $antwort = $p['datadir'] . '/antworten/' . $kennung . '.json';
    /* Die Antwort auswerten. ok=2 vom Dienst heisst "ueberholt" und wird
     * unveraendert durchgereicht; ein unbekannter Wert gilt als 0. */
    $auswerten = function ($pfad) {
        $a = mt_json_lesen($pfad);
        $ok = isset($a['ok']) && is_numeric($a['ok']) ? (int) $a['ok'] : 0;
        if (!in_array($ok, array(0, 1, 2), true)) {
            $ok = 0;
        }
        $ist = isset($a['ist']) && (is_scalar($a['ist'])) ? $a['ist'] : null;
        return array($ok, (string) (isset($a['meldung']) && is_scalar($a['meldung']) ? $a['meldung'] : ''),
                     $ist);
    };
    for ($i = 0; $i < $wartezeit * 10; $i++) {
        if (is_file($antwort)) {
            return $auswerten($antwort);
        }
        usleep(100000);
    }
    /* Nachtrag B-Nachzug 01.10.2026: Wartezeit 0 heisst "einreihen, nicht
     * warten". Bis 0.9.34 lief die Warteschleife dann gar nicht, und die
     * Datei wurde gleich darauf wieder geloescht - der Befehl ging nie
     * hinaus (gemessen: OK=2, 0 Befehle am Dienst). Liegen bleibt sie nicht:
     * der Dienst verwirft Stellbefehle nach BEFEHL_VERFALL_S und beim Start
     * alles, was aelter als 60 s ist. */
    if ($wartezeit === 0) {
        return array(2, mt_t('EINST.M_BEFEHL_EINGEREIHT'), null);
    }
    /* 0.9.35 (Nr. 2): das Loeschen ENTSCHEIDET. Der Dienst beansprucht eine
     * Datei per rename X.json -> X.inarbeit, bevor er sie liest. Gelingt das
     * Loeschen, hatte er sie nicht - der Befehl ist sicher nicht
     * ausgefuehrt (0). Gelingt es nicht, ist er in Arbeit (2). Bis 0.9.34
     * hiess beides "ohne Antwort" (2), auch wenn sicher nichts geschah. */
    if (@unlink($datei)) {
        return array(0, sprintf(mt_t('EINST.M_BEFEHL_NICHT_AUSGEFUEHRT'), $wartezeit), null);
    }
    clearstatcache(true, $antwort);
    if (is_file($antwort)) {
        // Die Antwort kam genau zwischen der letzten Runde und dem Loeschen.
        return $auswerten($antwort);
    }
    return array(2, sprintf(mt_t('EINST.M_BEFEHL_LAEUFT_NOCH'), $wartezeit), null);
}

/* ---------------- MQTT-Gateway des LoxBerry ----------------
 *
 * Das MQTT-Gateway ist seit LoxBerry 3 Bestandteil des Systems, kein Plugin.
 * Es wird nicht nachinstalliert, sondern unter System -> MQTT Gateway
 * eingeschaltet.
 *
 * Mqtt.Brokerhost ist ab Werk auf 'localhost' gesetzt. Eine Pruefung darauf
 * beantwortet also NICHT die Frage, ob Nachrichten ankommen koennen -
 * massgeblich ist Gatewayautostart.
 */
/**
 * Einen Wert fuer den UDP-Eingang des MQTT-Gateways unschaedlich machen.
 *
 * Das Gateway liest ZEILENWEISE. Ein Zeilenumbruch im Wert - aus einer
 * Fehlermeldung des Betriebssystems, einem Geraetenamen oder der Ausgabe
 * eines Systembefehls - zerlegt die Uebertragung, und aus den Bruchstuecken
 * bildet das Gateway erfundene Themen. Ein Tabulator schadet ebenso, weil
 * Leerzeichen Thema und Wert trennt.
 */
function mt_mqtt_wert_saeubern($v)
{
    $wert = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $wert));
}

/**
 * Das Themenpraefix fuer die publish-Zeile.
 *
 * Ein Wert, der in eine zeilenorientierte Uebertragung geht, wird an EINER
 * Stelle gesaeubert - und zwar in BEIDEN Haelften der Zeile. Bis 0.9.16 wurde
 * nur der Wert gesaeubert, das Thema nicht; ueber eine zurueckgespielte
 * Sicherung liess sich damit ein Zeilenumbruch ins Datagramm bringen.
 * Dieselbe Funktion gibt es im Dienst (mqtt_praefix in matter_dienst.py).
 */
function mt_mqtt_praefix($cfg = null)
{
    if ($cfg === null) {
        $cfg = mt_config();
    }
    $p = is_scalar($cfg['mqtt_topic']) ? trim((string) $cfg['mqtt_topic'], "/ \t\r\n") : '';
    return preg_match('#^[A-Za-z0-9_/\-]{1,64}$#', $p) ? $p : 'matter';
}

function mt_mqtt_zustand()
{
    $p = mt_paths();
    $leer = array('gefunden' => 0, 'autostart' => 0, 'fassung' => 0, 'udpport' => 0, 'broker' => '',
                  'brokerport' => '', 'user' => '', 'pw' => '', 'lokal' => 0);
    if ($p['home'] === '') {
        return $leer;
    }
    $gen = mt_json_lesen($p['home'] . '/config/system/general.json');
    $m = array();
    if (isset($gen['Mqtt']) && is_array($gen['Mqtt'])) {
        $m = $gen['Mqtt'];
    } elseif (isset($gen['mqtt']) && is_array($gen['mqtt'])) {
        $m = $gen['mqtt'];
    }
    if (!$m) {
        return $leer;
    }
    $hol = function ($gross, $klein) use ($m) {
        if (isset($m[$gross])) {
            return $m[$gross];
        }
        return isset($m[$klein]) ? $m[$klein] : '';
    };
    return array(
        'gefunden'   => 1,
        'autostart'  => in_array((string) $hol('Gatewayautostart', 'gatewayautostart'), array('1', 'true'), true) ? 1 : 0,
        /* Die FASSUNG des MQTT-Gateways, ab Werk 1. Sie entscheidet, was der
         * Anwender eintragen muss: unter V1 jedes Thema von Hand, ab V2
         * erscheint die Themengruppe von selbst in den Subscriptions.
         * 0 heisst "nicht feststellbar" - dann wird nichts behauptet,
         * sondern es werden beide Faelle genannt. */
        'fassung'    => (int) $hol('Gatewayversion', 'gatewayversion'),
        'udpport'    => (int) $hol('Udpinport', 'udpinport'),
        'broker'     => (string) $hol('Brokerhost', 'brokerhost'),
        'brokerport' => (string) $hol('Brokerport', 'brokerport'),
        'user'       => (string) $hol('Brokeruser', 'brokeruser'),
        'pw'         => (string) $hol('Brokerpass', 'brokerpass'),
        'lokal'      => in_array((string) $hol('Uselocalbroker', 'uselocalbroker'), array('1', 'true'), true) ? 1 : 0,
    );
}

/**
 * Der Hinweis zum MQTT-Abo - in der Fassung, die zum GATEWAY passt.
 *
 * Bis hierher stand an den Ausgabestellen unbedingt "Ohne diesen Eintrag
 * kommt am Miniserver nichts an". Das gilt fuer Gateway V1, wo jedes Thema
 * von Hand einzutragen ist. Ab V2 erscheint die Themengruppe von selbst in
 * den Subscriptions - der Satz schickte jeden V2-Anwender zu einem
 * Eingabeplatz, den es nicht gibt.
 *
 * Drei Ausgaenge, nicht zwei: ist die Fassung nicht feststellbar, werden
 * BEIDE Faelle genannt statt einer behauptet.
 */
function mt_abo_text()
{
    $m = mt_mqtt_zustand();
    $f = isset($m['fassung']) ? (int) $m['fassung'] : 0;
    if ($f <= 0) {
        return mt_t('MQTT.ABO_UNBEKANNT');
    }
    $gemessen = ' <span class="sm-mono">'
              . sprintf(mt_t('MQTT.ABO_GEMESSEN'), $f) . '</span>';
    return mt_t($f >= 2 ? 'MQTT.ABO_V2' : 'MQTT.ABO_WARNUNG') . $gemessen;
}


/**
 * Werte ueber das LoxBerry-Gateway veroeffentlichen.
 *
 * Bewusst ueber den UDP-Eingang des Gateways und nicht mit einem eigenen
 * MQTT-Client: so muss das Plugin ueberhaupt keine Broker-Zugangsdaten
 * kennen, um zu senden. Das Gateway hat sie ohnehin.
 */
function mt_mqtt_senden(array $paare, $praefix)
{
    $z = mt_mqtt_zustand();
    if (!$z['udpport']) {
        mt_log_gebremst('mqtt_kein_port', 'MQTT: kein UDP-Eingangsport in der general.json gefunden - nichts gesendet.');
        return false;
    }
    if (!$z['autostart']) {
        mt_log_gebremst('mqtt_aus', 'MQTT: das Gateway ist nicht auf Autostart gestellt '
            . '(System, MQTT Gateway). Es wird gesendet, aber vermutlich hoert niemand zu.');
    }
    // Hier steht bewusst stream_socket_client. Der Weg ueber die
    // Sockets-Erweiterung schiede aus: sie ist nicht garantiert geladen, und
    // ihr Fehlen ist kein abfangbarer, sondern ein fataler Fehler. In einem
    // Cron, der nach /dev/null schreibt, sieht das niemand.
    // stream_socket_client() gehoert zum Kern und tut dasselbe.
    // (Der Name der anderen Funktion steht hier nicht ausgeschrieben - er
    //  wuerde von den Hauswerkzeugen als Fundstelle gelesen.)
    $fehler = 0;
    $text = '';
    $s = @stream_socket_client('udp://127.0.0.1:' . (int) $z['udpport'], $fehler, $text, 2);
    if (!$s) {
        mt_log_gebremst('mqtt_socket', 'MQTT: kein UDP-Socket moeglich (' . $text . ').');
        return false;
    }
    $gesendet = 0;
    foreach ($paare as $k => $v) {
        if ($v === null || $v === '') {
            continue;   // fehlender Wert: nichts senden statt eine erfundene 0
        }
        /* Beide Haelften der Zeile gesaeubert: das Thema ueber die
         * Positivliste, der Wert ueber den Ersatz der Steuerzeichen. */
        $msg = 'publish ' . preg_replace('#[^A-Za-z0-9_/\-]#', '_', (string) $praefix)
             . '/' . preg_replace('#[^A-Za-z0-9_/\-]#', '_', (string) $k)
             . ' ' . mt_mqtt_wert_saeubern($v);
        $n = @fwrite($s, $msg);
        if ($n === strlen($msg)) {
            $gesendet++;
        }
    }
    fclose($s);
    return $gesendet;
}

/**
 * Einen Probewert durch das Gateway schicken.
 *
 * Damit laesst sich die ganze Kette pruefen - Plugin, UDP-Eingang, Gateway,
 * Broker - ohne ein einziges Matter-Geraet. Rueckgabe: array(ok, Meldung).
 */
function mt_mqtt_probe()
{
    $z = mt_mqtt_zustand();
    if (!$z['gefunden']) {
        return array(0, mt_t('MQTT.M_PROBE_KEIN_GATEWAY'));
    }
    if (!$z['udpport']) {
        return array(0, mt_t('MQTT.M_PROBE_KEIN_PORT'));
    }
    $cfg = mt_config();
    $praefix = mt_mqtt_praefix($cfg);
    $wert = date('Y-m-d H:i:s');
    $anzahl = mt_mqtt_senden(array('probe' => $wert), $praefix);
    if (!$anzahl) {
        return array(0, mt_t('MQTT.M_PROBE_FEHL'));
    }
    // Gesendet ist nicht angekommen - das Gateway bestaetigt nichts. Genau das
    // gehoert dazugesagt, statt einen Erfolg zu melden, den niemand geprueft
    // hat.
    return array(1, sprintf(mt_t('MQTT.M_PROBE_OK'), mt_e($praefix . '/probe'), mt_e($wert),
                            (int) $z['udpport']));
}

/* ==================================================================
 * Loxone-Vorlagen
 *
 * Nachbau der Bausteine aus LoxBerry::LoxoneTemplateBuilder; das Modul gibt es
 * nur in Perl. Attributreihenfolge, CRLF als Zeilenende und der Tabulator vor
 * den Kindelementen entsprechen dem Original. Wortgleich uebernommen aus
 * LoxBerry-Plugin-APC-UPS-1.0.0 (ap_xml_virtual_in_http) - nicht neu
 * geschrieben, weil die Fassung dort geprueft ist.
 * ================================================================== */

function mt_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Grenzen und Analog/Digital aus dem Attributtyp ableiten.
 *
 * Bis 0.9.0 bekam JEDER Wert Analog="true" und MinVal/MaxVal auf Anschlag
 * (+/- 2147483647). Loxone zieht aus diesen Grenzen aber die Reglerbereiche
 * und die Plausibilitaetspruefung - wer alles offen laesst, verschenkt beides,
 * und ein Schalter wird zum Analogwert ueber vier Milliarden Stufen.
 *
 * WICHTIG: umgerechnet wird im PLUGIN, nicht in Loxone. Der virtuelle Eingang
 * liest den bereits fertigen Wert vom Status-Endpunkt. Deshalb bleiben
 * SourceVal/DestVal immer 1:1 - hier geht es allein um Analog/Digital und um
 * sinnvolle Grenzen in der FERTIGEN Einheit.
 *
 * Genauere Grenzen darf die Cluster-Tabelle je Attribut mitgeben (min, max,
 * einheit); der Typ ist nur die Rueckfallebene. Noetig ist das, weil
 * derselbe Typ Verschiedenes tragen kann: 'hundertstel' ist bei der
 * Temperatur -273..328 und bei der Feuchte 0..100.
 */
function mt_xml_grenzen($typ)
{
    switch ((string) $typ) {
        case 'bool':
        case 'bit0':
            return array('analog' => false, 'min' => 0, 'max' => 1);
        case 'prozent254':
        case 'halbprozent':
            return array('analog' => true, 'min' => 0, 'max' => 100);
        case 'hundertstel':
            return array('analog' => true, 'min' => -32768, 'max' => 32767);
        case 'zehntel':
            return array('analog' => true, 'min' => -3276, 'max' => 3276);
        case 'milli':
            return array('analog' => true, 'min' => -2147483, 'max' => 2147483);
        case 'lux':
            return array('analog' => true, 'min' => 0, 'max' => 200000);
        case 'mwh':
        case 'energie_struct':
            return array('analog' => true, 'min' => 0, 'max' => 1000000);
        case 'gleitkomma':
            // Rueckfallebene. Die Luftguete-Cluster geben eigene Grenzen mit;
            // ohne sie waere hier nichts Sinnvolles zu sagen, denn derselbe
            // Typ traegt ppm, ug/m3 und Bq/m3.
            return array('analog' => true, 'min' => 0, 'max' => 1000000);
        case 'text':
            // Text kann ein virtueller HTTP-Eingang nur, wenn er in Loxone
            // Config auf "Als Text" gestellt wird. Ein Attribut dafuer ist
            // hier nicht bekannt und wird deshalb NICHT erfunden - statt
            // dessen steht der Hinweis im Kommentar des Eingangs.
            return array('analog' => true, 'min' => 0, 'max' => 65535, 'text' => true);
    }
    return array('analog' => true, 'min' => -2147483647, 'max' => 2147483647);
}

function mt_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    // Reihenfolge und Zusatzfelder wortgleich aus den Ausfuhren DIESER Anlage
    // (VI_Marstek Speicher (LoxBerry-Plugin)_Test.xml, 12.08.2026): HintText
    // steht vorn, und als erstes Kindelement folgt <Info>. Ob Loxone Config
    // ohne sie einliest, ist nicht gemessen - die Vorlagen liefen bisher auch
    // ohne. Gemessen ist nur, dass Config sie SCHREIBT.
    $o .= '<VirtualInHttp ';
    $o .= 'HintText="" ';
    $o .= 'Title="' . mt_x($kopf['title']) . '" ';
    $o .= 'Comment="' . mt_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . mt_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . mt_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $g = mt_xml_grenzen(isset($c['typ']) ? $c['typ'] : '');
        $min = isset($c['min']) ? $c['min'] : $g['min'];
        $max = isset($c['max']) ? $c['max'] : $g['max'];
        $kommentar = isset($c['comment']) ? $c['comment'] : '';
        if (!empty($g['text'])) { $kommentar .= ' - in Loxone Config auf "Als Text" umstellen'; }
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . mt_x($c['title']) . '" ';
        $o .= 'Comment="' . mt_x(trim($kommentar)) . '" ';
        $o .= 'Check="' . mt_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        // Reihenfolge wie in den Ausfuhren dieser Anlage: erst Analog, dann
        // Signed. Bis 0.9.9 stand es umgekehrt - XML-semantisch belanglos,
        // aber das Muster ist das Muster.
        $o .= 'Analog="' . ($g['analog'] ? 'true' : 'false') . '" ';
        $o .= 'Signed="' . ($min < 0 ? 'true' : 'false') . '" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="1" ';
        $o .= 'DestValHigh="1" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="' . (int) $min . '" ';
        $o .= 'MaxVal="' . (int) $max . '" ';
        // Die Einheit gehoert an den Eingang, nicht in den Kommentar: Loxone
        // zeigt sie dann am Wert an. Form wortgleich aus den Ausfuhren
        // dieser Anlage (VI_Marstek..._Test.xml): "<v.1> %", "<v.1> °C".
        $o .= 'Unit="' . mt_x('<v.1>' . (!empty($c['einheit']) ? ' ' . $c['einheit'] : '')) . '" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Virtueller Ausgang: damit schaltet Loxone die Matter-Geraete.
 *
 * Bis 0.9.0 gab es dafuer gar keine Vorlage - der Anwender baute jeden
 * Ausgang samt Adresse von Hand, und das ist die aufwendigere Haelfte.
 * Aufbau nach dem geprueften Muster VQ_KEBA_P30_UDP.xml, hier aber ueber
 * HTTP statt UDP.
 */
function mt_xml_virtual_out($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    // Aufbau wortgleich aus VO_Rasenmaeher steuern (LoxBerry-Plugin)_Test.xml,
    // einer Ausfuhr aus dieser Anlage vom 12.08.2026: HintText und CmdInit am
    // Wurzelelement, <Info> als erstes Kind, und je Befehl die vollstaendige
    // Feldreihenfolge samt der leeren Felder. CloseAfterSend steht dort auf
    // "true" - der Befehl geht ueber HTTP, und die Verbindung danach offen zu
    // halten bringt nichts.
    $o .= '<VirtualOut ';
    $o .= 'HintText="" ';
    $o .= 'Title="' . mt_x($kopf['title']) . '" ';
    $o .= 'Comment="' . mt_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . mt_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'CmdInit="" ';
    $o .= 'CloseAfterSend="true" ';
    $o .= 'CmdSep=""';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'Title="' . mt_x($c['title']) . '" ';
        $o .= 'Comment="' . mt_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'CmdOnMethod="GET" ';
        $o .= 'CmdOffMethod="GET" ';
        $o .= 'CmdOn="' . mt_x(isset($c['on']) ? $c['on'] : '') . '" ';
        $o .= 'CmdOnHTTP="" ';
        $o .= 'CmdOnPost="" ';
        $o .= 'CmdOff="' . mt_x(isset($c['off']) ? $c['off'] : '') . '" ';
        $o .= 'CmdOffHTTP="" ';
        $o .= 'CmdOffPost="" ';
        $o .= 'CmdAnswer="" ';
        $o .= 'Analog="' . (!empty($c['analog']) ? 'true' : 'false') . '" ';
        $o .= 'Repeat="0" ';
        $o .= 'RepeatRate="0" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch. Deshalb muss language_en.ini immer
 * vollstaendig sein.
 *
 * Die Funktion setzt kein mt_paths() voraus, damit derselbe Block in jedes
 * Plugin passt. Der Pfad wird zweistufig gesucht:
 *   installiert: <home>/templates/plugins/<ordner>/lang
 *   Archiv:      <pluginwurzel>/templates/lang
 * ================================================================== */

function mt_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

function mt_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        /* Wurzel und Ordner wie mt_paths(): ohne festen Standardort dahinter,
         * und aus einem Archiv unter einer echten Wurzel die EIGENEN Texte.
         * Ohne Wurzel NUR die eigenen Sprachdateien - bis 0.9.28 wurde der
         * Installationspfad auch mit leerer Wurzel gebildet und abgefragt,
         * aus dem ausgepackten Archiv also /templates/plugins/html/lang ab
         * der Laufwerkswurzel (in WSL gemessen 25.09.2026,
         * Pruefung-Matter2Lox-0.9.29, Fall P3; Bauart zd_t(),
         * ZendureSolarFlow 0.9.26). */
        $p = mt_paths();
        $pfad = '';
        if ($p['home'] !== '' && is_dir($p['home'] . '/templates/plugins/' . $p['plugin'] . '/lang')) {
            $pfad = $p['home'] . '/templates/plugins/' . $p['plugin'] . '/lang';
        }
        if ($pfad === '') {
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . mt_sprache() . '.ini', true, INI_SCANNER_RAW);
        if (!is_array($texte)) {
            $texte = array();
        }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) {
            $texte = array_replace_recursive($rueck, $texte);
        }
        // INI_SCANNER_RAW liefert die Werte samt der Anfuehrungszeichen
        // zurueck, in die sie in der Datei stehen muessen. Die gehoeren nicht
        // in die Ausgabe.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) {
                continue;
            }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    $teile = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$teile[0]][$teile[1]]) ? $texte[$teile[0]][$teile[1]] : $schluessel;
}

/* ==================================================================
 * Der Matter-Server im Container
 *
 * Das Plugin startet und ueberwacht den Container, es baut ihn aber nicht
 * nach. Die Aufrufzeile folgt der Anleitung des Matter-Servers:
 *   --network=host                  mDNS braucht das Wirtsnetz
 *   --security-opt apparmor=unconfined   Bluetooth ueber D-Bus
 *   -v <daten>/matter:/data         Fabric und Zertifikate
 *   -v /run/dbus:/run/dbus:ro       Bluetooth
 * Der DATENORDNER wird nie mitgeloescht: darin liegt die Fabric. Wer ihn
 * loescht, muss jedes Geraet neu anlernen.
 * ================================================================== */

function mt_docker_da()
{
    $a = array();
    @exec('command -v docker 2>/dev/null', $a);
    return count($a) > 0 ? 1 : 0;
}

/** Rueckgabe: array(ok, Ausgabe). $frist in Sekunden, ohne Angabe 900 (pull, run) bzw. 30. */
function mt_docker($argumente, $frist = null)
{
    if (!mt_docker_da()) {
        return array(0, mt_t('EINST.M_AKT_KEIN_DOCKER'));
    }
    /* 0.9.35 (Nr. 9): Seitenbremse. Hat der Seitenaufruf Docker schon als
     * nicht ansprechbar gemessen, folgt kein weiterer Aufruf; und alle
     * Aufrufe zusammen bleiben in der Seitenfrist (mt_docker_seite()). */
    $seite = mt_docker_seite();
    if ($seite['lage'] !== '' && $seite['lage'] !== 'ok') {
        return array(0, mt_t('EINST.DOCKER_GESPERRT'));
    }
    $ausgabe = array();
    $code = 0;
    /* Mit Frist. Antwortet der Docker-Dienst nicht, haengt die
     * Kommandozeile - und mt_container_zustand() laeuft bei jedem Aufruf der
     * Oberflaeche. Bis 0.9.28 stand die Seite dann unbegrenzt (in WSL
     * gemessen 25.09.2026 mit einem haengenden docker, Pruefung-Matter2Lox-
     * 0.9.29, Fall T1). Abbild holen und Container anlegen (das ein fehlendes
     * Abbild zieht) duerfen 15 Minuten dauern, alles andere 30 s; danach
     * SIGTERM, nach weiteren 5 s SIGKILL (Muster 13 der Nachlese). */
    $wort = strtok(ltrim((string) $argumente), ' ');
    /* E1 (Welle 2): der Hintergrundvorgang "Matter-Server einrichten" gibt die
     * Restzeit seiner 15-Minuten-Frist mit; ohne Angabe wie bisher. */
    $frist = $frist === null ? (in_array($wort, array('pull', 'run'), true) ? 900 : 30) : max(1, (int) $frist);
    if ($seite['bis'] > 0) {
        $rest = $seite['bis'] - time();
        if ($rest < 1) {
            return array(0, mt_t('EINST.DOCKER_SEITENFRIST'));
        }
        $frist = min($frist, $rest);
    }
    @exec('timeout -k ' . ($seite['bis'] > 0 ? 1 : 5) . ' ' . $frist . ' docker ' . $argumente . ' 2>&1', $ausgabe, $code);
    if ($code === 124 || $code === 137) {
        $ausgabe[] = sprintf(mt_t('EINST.FRIST_ABGELAUFEN'), $frist);
    }
    return array($code === 0 ? 1 : 0, implode("\n", $ausgabe));
}

/**
 * 0.9.35 (Nr. 9): die Docker-Lage DIESES Seitenaufrufs und seine Frist.
 *
 * Bis 0.9.34 fragte jeder Seitenaufruf Docker mehrfach (Zustand, Fassung mit
 * drei Aufrufen, Ampel, Eigentumspruefung), jeder Aufruf mit 30 s Frist - bei
 * haengendem Docker stand die Seite minutenlang. Jetzt misst die Oberflaeche
 * zuerst mt_docker_lage(5) und traegt das Ergebnis hier ein: ist Docker nicht
 * "ok", verweigert mt_docker() jeden weiteren Aufruf sofort; ist er "ok",
 * bleiben alle Aufrufe zusammen innerhalb der Frist ($sekunden ab jetzt).
 * Der Hintergrundvorgang setzt nichts - dort gilt wie bisher die Frist je
 * Aufruf. Rueckgabe: array('lage' => ..., 'bis' => Unixzeit oder 0).
 */
function mt_docker_seite($lage = null, $sekunden = null)
{
    static $s = array('lage' => '', 'bis' => 0);
    if ($lage !== null) {
        $s['lage'] = (string) $lage;
        $s['bis'] = $sekunden !== null ? time() + max(1, (int) $sekunden) : 0;
    }
    return $s;
}

/** 'laeuft', 'gestoppt', 'fehlt' oder 'kein_docker'. $name: ein anderer
 *  Container als der eingestellte (0.9.35, Nr. 5/7). */
function mt_container_zustand($name = null)
{
    $cfg = mt_config();
    if ($name === null) {
        $name = mt_container_name($cfg);
    }
    if (!mt_docker_da()) {
        return 'kein_docker';
    }
    // 0.9.35 (Nr. 9): Docker auf dieser Seite schon als gestoert gemessen.
    $seite = mt_docker_seite();
    if ($seite['lage'] !== '' && $seite['lage'] !== 'ok') {
        return 'kein_docker';
    }
    list($ok, $aus) = mt_docker('inspect --type container -f {{.State.Running}} -- ' . escapeshellarg($name));
    if (!$ok) {
        /* 0.9.35 (Nr. 9): "fehlt" nur, wenn Docker das sagt. Eine abgelaufene
         * Frist oder ein anderer Fehler ist keine Aussage ueber den Container. */
        return stripos($aus, 'no such') !== false ? 'fehlt' : 'kein_docker';
    }
    return trim($aus) === 'true' ? 'laeuft' : 'gestoppt';
}

/** Der Containername, auf ein unbedenkliches Muster begrenzt. */
function mt_container_name($cfg = null)
{
    if ($cfg === null) {
        $cfg = mt_config();
    }
    $n = trim((string) $cfg['container_name']);
    // 0.9.35 (Nr. 1): Rueckfall wie die neue Vorgabe.
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,60}$/', $n) ? $n : 'matter2lox-server';
}

/** 0.9.35 (Nr. 5): das Label, an dem ein eigener Container erkannt wird. */
function mt_container_label()
{
    return 'de.loxberry.plugin.folder=' . mt_paths()['plugin'];
}

/* Das Abbild an EINER Stelle beurteilen.
 *
 * Bis 0.9.16 prueften 'Container anlegen' und 'Abbild holen' verschieden:
 * mt_container_befehl() fiel bei einem unbrauchbaren Wert auf die Vorgabe
 * zurueck, 'holen' und die Fassungsabfrage nahmen den rohen Wert. Damit
 * fragten sie nach einem anderen Abbild, als der Container wirklich startete -
 * eine falsche Auskunft, kein Einbruchsweg (escapeshellarg steht ueberall). */
function mt_container_abbild($cfg = null)
{
    if ($cfg === null) {
        $cfg = mt_config();
    }
    $abbild = is_scalar($cfg['container_abbild']) ? trim((string) $cfg['container_abbild']) : '';
    return preg_match('#^[A-Za-z0-9][A-Za-z0-9_./\-]{2,120}(:[A-Za-z0-9_.\-]{1,40})?$#', $abbild)
        ? $abbild : 'ghcr.io/matter-js/python-matter-server:stable';
}

/**
 * 0.9.35 (Nr. 2 und 5): die Soll-Gestalt des eigenen Containers, aus der
 * mt_container_befehl() die Aufrufzeile baut und gegen die
 * mt_container_abweichung() den laufenden Container haelt. EINE Quelle fuer
 * beides - sonst meldete der Vergleich Abweichungen, die die Aufrufzeile gar
 * nicht erzeugt.
 *
 * Neu gegenueber 0.9.34:
 *   --listen-address 127.0.0.1  bei server_lokal=1 und server_host 127.0.0.1
 *                               oder localhost: die WebSocket-Schnittstelle
 *                               (steuert JEDES Geraet) ist dann nicht mehr
 *                               aus dem ganzen Heimnetz erreichbar.
 *   --port <server_port>        nur wenn er nicht 5580 ist. Bewusst nicht
 *                               immer: so bleibt die Aufrufzeile bestehender
 *                               Anlagen gleich, und der Vergleich meldet
 *                               nach dem Update nur, was sich wirklich
 *                               aendert.
 *   --primary-interface <x>     wenn eingetragen.
 *   --bluetooth-adapter N und -v /run/dbus nur, wenn /run/dbus da ist UND
 *                               /sys/class/bluetooth/hci<N> - bis 0.9.34
 *                               genuegte /run/dbus, und ein Rechner ohne
 *                               diesen Adapter bekam einen Server, der beim
 *                               Start nach einem fehlenden Adapter fragt.
 * Rueckgabe: array(name, abbild, label, mounts (Quelle => Ziel), args).
 */
function mt_container_soll($cfg = null)
{
    if ($cfg === null) {
        $cfg = mt_config();
    }
    $p = mt_paths();
    $bt = is_numeric($cfg['bluetooth_adapter']) ? (int) $cfg['bluetooth_adapter'] : 0;
    $bt = ($bt >= 0 && $bt <= 9) ? $bt : 0;
    $mounts = array($p['fabric'] => '/data');
    /* 0.9.35 (Nr. E5): die Bauart haengt am Abbild. matterjs-server (laut
     * seiner Anleitung docs/cli.md und docs/docker.md) kennt
     * --paa-root-cert-dir nicht, waehlt die Fabric-ID ab Werk zufaellig
     * (python-matter-server: 1) und laeuft ohne root (USER 1000:1000) - er
     * braucht Schreibrecht auf /data. Deshalb: ohne --paa-root-cert-dir, mit
     * --fabricid 1, und --user mit Eigentuemer und Gruppe des Fabric-Ordners
     * (kein chown als root noetig). */
    $abbild = mt_container_abbild($cfg);
    $bauart = mt_abbild_bauart($abbild);
    $user = '';
    if ($bauart === 'matterjs') {
        $args = array('--storage-path', '/data');
        $user = mt_fabric_benutzer();
    } else {
        $args = array('--storage-path', '/data', '--paa-root-cert-dir', '/data/credentials');
    }
    $host = strtolower(trim((string) $cfg['server_host']));
    if ((string) $cfg['server_lokal'] === '1' && in_array($host, array('127.0.0.1', 'localhost'), true)) {
        $args[] = '--listen-address';
        $args[] = '127.0.0.1';
    }
    $port = is_numeric($cfg['server_port']) ? (int) $cfg['server_port'] : 5580;
    if ($port !== 5580 && $port >= 1 && $port <= 65535) {
        $args[] = '--port';
        $args[] = (string) $port;
    }
    $pi = trim((string) $cfg['primary_interface']);
    $tm = mt_textmuster();
    if ($pi !== '' && preg_match($tm['primary_interface'], $pi)) {
        $args[] = '--primary-interface';
        $args[] = $pi;
    }
    if (mt_bluetooth_da($bt)) {
        // Die Vorgabe-Befehlszeile des Abbilds muss vollstaendig wiederholt
        // werden, sobald man etwas anhaengt - so steht es in der Anleitung.
        $mounts['/run/dbus'] = '/run/dbus';
        $args[] = '--bluetooth-adapter';
        $args[] = (string) $bt;
    }
    if ($bauart === 'matterjs') {
        $args[] = '--fabricid';
        $args[] = '1';
    }
    return array(
        'name'   => mt_container_name($cfg),
        'abbild' => $abbild,
        'bauart' => $bauart,
        'user'   => $user,
        'label'  => mt_container_label(),
        'mounts' => $mounts,
        'args'   => $args,
    );
}

/** 0.9.35 (Nr. E5): 'matterjs' fuer ein matterjs-server-Abbild, sonst 'python'. */
function mt_abbild_bauart($abbild)
{
    return strpos((string) $abbild, 'matterjs-server') !== false ? 'matterjs' : 'python';
}

/** 0.9.35 (Nr. E5): die beiden Abbilder fuer den Umstieg und den Rueckweg. */
function mt_abbild_vorgabe($bauart)
{
    return $bauart === 'matterjs' ? 'ghcr.io/matter-js/matterjs-server:stable'
                                  : 'ghcr.io/matter-js/python-matter-server:stable';
}

/**
 * 0.9.35 (Nr. E5): uid:gid fuer --user beim matterjs-server - Eigentuemer und
 * Gruppe des Fabric-Ordners. Gibt es ihn noch nicht, legt ihn das Neuanlegen
 * als dieser Prozess an (mkdir 0700); dann dessen Kennungen.
 */
function mt_fabric_benutzer()
{
    $f = mt_fabric_pfad();
    if (is_dir($f)) {
        $u = @fileowner($f);
        $g = @filegroup($f);
        if ($u !== false && $g !== false) {
            return (int) $u . ':' . (int) $g;
        }
    }
    $u = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    $g = function_exists('posix_getegid') ? posix_getegid() : getmygid();
    return (int) $u . ':' . (int) $g;
}

/** 0.9.35 (Nr. 2): D-Bus da UND der Adapter hci<N> da? */
function mt_bluetooth_da($nr)
{
    return is_dir('/run/dbus') && file_exists('/sys/class/bluetooth/hci' . (int) $nr);
}

/** Die vollstaendige Aufrufzeile - auch fuer die Anzeige in der Oberflaeche.
 *  $name: ein anderer Name als der eingestellte (0.9.35, Nr. 6: das Neuanlegen
 *  legt unter dem eingestellten Namen an; der Parameter bleibt fuer Aufrufer). */
function mt_container_befehl($cfg = null, $name = null)
{
    $s = mt_container_soll($cfg);
    $zeile = 'run -d'
        . ' --name ' . mt_sh_arg($name !== null ? $name : $s['name'])
        . ' --restart=unless-stopped'
        /* I3 (Durchgang 30.09.2026, Entscheidung 9 sinngemaess): das Label
         * sagt, dass DIESES Plugin den Container betreibt. Nur einen solchen
         * entfernt die Deinstallation; bis 0.9.30 entfernte sie jeden
         * Container mit dem eingestellten Namen - auch einen eigenen
         * Matter-Server des Anwenders (gemessen, Bericht installer I3). */
        . ' --label ' . mt_sh_arg($s['label'])
        . ' --security-opt apparmor=unconfined'
        . ' --network=host'
        // 0.9.35 (Nr. E5): matterjs-server laeuft als Eigentuemer der Fabric.
        . ($s['user'] !== '' ? ' --user ' . mt_sh_arg($s['user']) : '');
    foreach ($s['mounts'] as $quelle => $ziel) {
        $zeile .= ' -v ' . mt_sh_arg($quelle . ':' . $ziel . ($ziel === '/run/dbus' ? ':ro' : ''));
    }
    $zeile .= ' ' . mt_sh_arg($s['abbild']);
    foreach ($s['args'] as $a) {
        $zeile .= ' ' . mt_sh_arg($a);
    }
    return $zeile;
}

/** 0.9.35 (Nr. 2): ein Argument fuer die Schale - unquotiert, wenn es nur aus
 *  unbedenklichen Zeichen besteht (lesbare Aufrufzeile), sonst escapeshellarg. */
function mt_sh_arg($a)
{
    $a = (string) $a;
    return preg_match('#^[A-Za-z0-9_./:=@%+\-]+$#', $a) === 1 ? $a : escapeshellarg($a);
}


/**
 * 0.9.35 (Nr. 5): Wie ist der Container $name wirklich angelegt?
 * docker inspect als JSON. Rueckgabe: array oder null (keiner, keine Antwort).
 */
function mt_container_ist($name)
{
    list($ok, $aus) = mt_docker('inspect --type container --format ' . escapeshellarg('{{json .}}')
                                . ' -- ' . escapeshellarg($name));
    if (!$ok) {
        return null;
    }
    $d = json_decode(trim($aus), true);
    if (!is_array($d)) {
        return null;
    }
    $mounts = array();
    foreach ((isset($d['Mounts']) && is_array($d['Mounts']) ? $d['Mounts'] : array()) as $m) {
        if (isset($m['Source'], $m['Destination'])) {
            $mounts[(string) $m['Source']] = (string) $m['Destination'];
        }
    }
    $labels = isset($d['Config']['Labels']) && is_array($d['Config']['Labels']) ? $d['Config']['Labels'] : array();
    return array(
        'name'    => ltrim(isset($d['Name']) ? (string) $d['Name'] : '', '/'),
        'abbild'  => isset($d['Config']['Image']) ? (string) $d['Config']['Image'] : '',
        'label'   => isset($labels['de.loxberry.plugin.folder'])
                     ? 'de.loxberry.plugin.folder=' . $labels['de.loxberry.plugin.folder'] : '',
        'mounts'  => $mounts,
        /* Config.Cmd ist genau das, was hinter dem Abbild auf der Aufrufzeile
         * stand (es ersetzt das CMD des Abbilds); Args kaeme nach dem
         * Einstiegspunkt und haengt vom Abbild ab. */
        'args'    => isset($d['Config']['Cmd']) && is_array($d['Config']['Cmd'])
                     ? array_map('strval', $d['Config']['Cmd']) : array(),
        'netz'    => isset($d['HostConfig']['NetworkMode']) ? (string) $d['HostConfig']['NetworkMode'] : '',
        // 0.9.35 (Nr. E5): --user (matterjs-server)
        'user'    => isset($d['Config']['User']) ? (string) $d['Config']['User'] : '',
        'laeuft'  => !empty($d['State']['Running']),
    );
}

/**
 * 0.9.35 (Nr. 5): Weicht der laufende Container von den Einstellungen ab?
 * "Container-Einstellungen wirken nicht" - bis 0.9.34 aenderte Speichern nur
 * die Konfiguration; der Container lief mit der alten Aufrufzeile weiter, und
 * nichts sagte es. Rueckgabe: Liste der Abweichungen als Text (leer = passt),
 * oder null, wenn sich der Container nicht lesen liess.
 */
function mt_container_abweichung($cfg = null, $name = null)
{
    $s = mt_container_soll($cfg);
    $ist = mt_container_ist($name !== null ? $name : $s['name']);
    if ($ist === null) {
        return null;
    }
    $ab = array();
    if ($ist['name'] !== $s['name']) {
        $ab[] = sprintf(mt_t('EINST.AB_NAME'), $ist['name'], $s['name']);
    }
    if ($ist['abbild'] !== $s['abbild']) {
        $ab[] = sprintf(mt_t('EINST.AB_ABBILD'), $ist['abbild'], $s['abbild']);
    }
    if ($ist['label'] !== $s['label']) {
        $ab[] = sprintf(mt_t('EINST.AB_LABEL'), $ist['label'] !== '' ? $ist['label'] : '-', $s['label']);
    }
    // 0.9.35 (Nr. E5): der Benutzer zaehlt nur, wenn die Soll-Gestalt einen nennt.
    if ($s['user'] !== '' && $ist['user'] !== $s['user']) {
        $ab[] = sprintf(mt_t('EINST.AB_USER'), $ist['user'] !== '' ? $ist['user'] : '-', $s['user']);
    } elseif ($s['user'] === '' && $ist['user'] !== '' && !in_array($ist['user'], array('0', 'root', '0:0'), true)) {
        $ab[] = sprintf(mt_t('EINST.AB_USER'), $ist['user'], '-');
    }
    if ($ist['netz'] !== '' && $ist['netz'] !== 'host') {
        $ab[] = sprintf(mt_t('EINST.AB_NETZ'), $ist['netz']);
    }
    $ist_m = array();
    foreach ($ist['mounts'] as $q => $z) {
        $echt = @realpath($q);
        $ist_m[($echt !== false ? $echt : $q) . ':' . $z] = $q . ':' . $z;
    }
    $soll_m = array();
    foreach ($s['mounts'] as $q => $z) {
        $echt = @realpath($q);
        $soll_m[($echt !== false ? $echt : $q) . ':' . $z] = $q . ':' . $z;
    }
    foreach (array_diff_key($soll_m, $ist_m) as $m) {
        $ab[] = sprintf(mt_t('EINST.AB_MOUNT_FEHLT'), $m);
    }
    foreach (array_diff_key($ist_m, $soll_m) as $m) {
        $ab[] = sprintf(mt_t('EINST.AB_MOUNT_ZUVIEL'), $m);
    }
    if ($ist['args'] !== $s['args']) {
        $ab[] = sprintf(mt_t('EINST.AB_ARGS'), implode(' ', $ist['args']), implode(' ', $s['args']));
    }
    return $ab;
}

/** $was ist 'anlegen', 'start', 'stop', 'restart', 'entfernen' oder 'holen'. */
function mt_container($was, $anderer = null)
{
    /* Aus einem ausgepackten Archiv (Archivmodus in mt_paths()) wird kein
     * Container angefasst: Docker ist systemweit, und der Container heisst
     * wie der der Anlage. Bis 0.9.28 entfernte dieser Knopf aus einem Archiv
     * heraus den Container der Anlage (Fall A4). */
    if (mt_paths()['home'] === '') {
        return array(0, mt_t('EINST.ARCHIV_VERWEIGERT'));
    }
    $cfg = mt_config();
    $name = mt_container_name($cfg);
    /* 0.9.35 (Nr. 7): ein anderer eigener Container (Label dieses Plugins,
     * anderer Name) - nur anhalten, starten und entfernen, mit derselben
     * Eigentumspruefung. */
    if ($anderer !== null) {
        if (!in_array($was, array('start', 'stop', 'entfernen'), true)
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,90}$/', (string) $anderer)) {
            return array(0, mt_t('EINST.FEHLER_CONTAINERBEFEHL'));
        }
        $name = (string) $anderer;
    }
    $p = mt_paths();
    // Nach jedem Eingriff am Container ist die zwischengespeicherte Antwort
    // auf "nimmt jemand Verbindungen an?" hinfaellig. Sonst stuende nach dem
    // Start noch bis zu einer halben Minute "nicht erreichbar".
    mt_erreichbar_vergessen();
    switch ($was) {
        case 'holen':
            return mt_docker('pull ' . escapeshellarg(mt_container_abbild($cfg)));
        case 'anlegen':
            if (!is_dir($p['fabric'])) {
                @mkdir($p['fabric'], 0700, true);
            }
            if (mt_container_zustand() !== 'fehlt') {
                // 0.9.35 (Nr. 10): Sprachschluessel statt fester deutscher Satz.
                return array(0, sprintf(mt_t('EINST.CONTAINER_SCHON_DA'), $name));
            }
            return mt_docker(mt_container_befehl($cfg));
        case 'start':
        case 'stop':
        case 'restart':
        case 'entfernen':
            /* Matter2Lox-a1 (Verbesserungsbau 30.09.2026): nur der EIGENE
             * Container - dieselbe Pruefung wie die Deinstallation
             * (bin/container_eigen.sh). Bis 0.9.32 starteten, hielten diese
             * vier Knoepfe jeden Container mit dem eingestellten Namen an,
             * starteten ihn neu oder entfernten ihn - auch einen eigenen
             * Matter-Server des Anwenders bei eigener_container=0. "Starten"
             * seit dem Nachtrag (ENTSCHEIDUNGEN Nr. 15): sonst startete der
             * Knopf einen fremden, bewusst angehaltenen Container. Nicht zu
             * klaeren heisst: nichts anfassen, den Befehl zum Abtippen nennen. */
            list($eigen, $grund) = mt_container_eigen($cfg, $name);
            if ($eigen === 2) {
                return array(0, sprintf(mt_t('EINST.CONTAINER_KEINER'), $name));
            }
            if ($eigen !== 1) {
                $hand = $was === 'entfernen' ? 'rm -f' : $was;
                return array(0, sprintf(mt_t('EINST.CONTAINER_NICHT_EIGEN'), $name, $grund,
                                        'docker ' . $hand . ' ' . $name));
            }
            if ($was === 'start') {
                return mt_docker('start ' . escapeshellarg($name));
            }
            if ($was === 'stop') {
                return mt_docker('stop ' . escapeshellarg($name));
            }
            if ($was === 'restart') {
                return mt_docker('restart ' . escapeshellarg($name));
            }
            // Nur der Container, NIE der Datenordner: darin liegt die Fabric.
            return mt_docker('rm -f ' . escapeshellarg($name));
    }
    // 0.9.35 (Nr. 10): Sprachschluessel statt fester Satz.
    return array(0, mt_t('EINST.FEHLER_CONTAINERBEFEHL'));
}

/**
 * Ist der Container mit dem eingestellten Namen der eigene? (Matter2Lox-a1)
 *
 * Gefragt wird bin/container_eigen.sh - dieselbe Datei, die uninstall/uninstall
 * vor dem Entfernen fragt. Rueckgabe: array(Stand, Satz). Stand 1 eigen,
 * 0 fremd, 2 kein Container dieses Namens, -1 nicht zu klaeren (kein Docker,
 * keine Antwort, Pruefdatei fehlt). Nur 1 erlaubt anhalten, neu starten und
 * entfernen; alles andere faellt geschlossen aus.
 */
function mt_container_eigen($cfg = null, $name = null)
{
    if ($cfg === null) {
        $cfg = mt_config();
    }
    $p = mt_paths();
    $skript = $p['bindir'] . '/container_eigen.sh';
    if (!is_file($skript)) {
        return array(-1, sprintf(mt_t('EINST.CONTAINER_PRUEFUNG_FEHLT'), $skript));
    }
    /* 0.9.35 (Nr. 9): Docker auf dieser Seite schon als gestoert gemessen -
     * dann nicht fragen; und die Frist des Seitenaufrufs gilt auch hier
     * (MT_DOCKER_FRIST je docker-Aufruf im Skript, Standard 30 s). */
    $seite = mt_docker_seite();
    if ($seite['lage'] !== '' && $seite['lage'] !== 'ok') {
        return array(-1, mt_t('EINST.DOCKER_GESPERRT'));
    }
    $je = 30;
    $gesamt = 100;
    if ($seite['bis'] > 0) {
        $gesamt = $seite['bis'] - time();
        if ($gesamt < 1) {
            return array(-1, mt_t('EINST.DOCKER_SEITENFRIST'));
        }
        $je = max(1, (int) floor($gesamt / 3));
    }
    $eigen = (int) (isset($cfg['eigener_container']) && (string) $cfg['eigener_container'] === '1');
    /* 0.9.35 (Nr. 3): vierter Wert = LoxBerry-Wurzel, damit das Skript die
     * Quelle des /data-Mounts gegen den Fabric-Pfad halten kann. */
    $aus = array();
    $rc = 0;
    @exec('env MT_DOCKER_FRIST=' . (int) $je . ' timeout -k 2 ' . (int) $gesamt . ' bash ' . escapeshellarg($skript)
          . ' ' . escapeshellarg($p['plugin'])
          . ' ' . escapeshellarg($name !== null ? (string) $name : mt_container_name($cfg)) . ' ' . $eigen
          . ($p['home'] !== '' ? ' ' . escapeshellarg($p['home']) : '') . ' 2>&1', $aus, $rc);
    $satz = trim(implode(' ', $aus));
    $stand = array(0 => 1, 1 => 0, 2 => 2);
    return array(isset($stand[$rc]) ? $stand[$rc] : -1, $satz !== '' ? $satz : 'Rueckgabe ' . (int) $rc);
}

/**
 * 0.9.35 (Nr. 5 und 7): eigene Container UNTER EINEM ANDEREN NAMEN als dem
 * eingestellten - nach einer Aenderung von container_name, nach der neuen
 * Vorgabe "matter2lox-server" oder bei eigener_container=0. Gefunden ueber
 * das Label (docker ps -a --filter label=...), dazu ein Altbestand ohne Label
 * unter dem alten Vorgabenamen "matter-server", wenn container_eigen.sh ihn
 * als eigen erkennt (Mount-Quelle = Fabric-Pfad dieses Plugins).
 * Umbenannte Reste eines Neuanlegens (<name>-alt-<zeit>) stehen mit drin.
 * Rueckgabe: Liste von array(name, zustand, abbild); leer auch bei Stoerung.
 */
function mt_container_andere($cfg = null)
{
    if ($cfg === null) {
        $cfg = mt_config();
    }
    $eigen_name = mt_container_name($cfg);
    list($ok, $aus) = mt_docker('ps -a --filter ' . escapeshellarg('label=' . mt_container_label())
                                . ' --format ' . escapeshellarg('{{.Names}}|{{.State}}|{{.Image}}'));
    $liste = array();
    if ($ok) {
        foreach (explode("\n", (string) $aus) as $z) {
            $t = explode('|', trim($z));
            if (count($t) >= 3 && $t[0] !== '' && $t[0] !== $eigen_name
                && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,90}$/', $t[0])) {
                $liste[$t[0]] = array('name' => $t[0], 'zustand' => $t[1], 'abbild' => $t[2]);
            }
        }
    }
    if ($eigen_name !== 'matter-server' && !isset($liste['matter-server'])) {
        list($e, ) = mt_container_eigen($cfg, 'matter-server');
        if ($e === 1) {
            $z = mt_container_zustand('matter-server');
            $liste['matter-server'] = array('name' => 'matter-server',
                'zustand' => $z === 'laeuft' ? 'running' : 'exited', 'abbild' => '');
        }
    }
    return array_values($liste);
}

/**
 * Was laeuft da wirklich? Kennung des Abbilds im laufenden Container, Kennung
 * des lokal vorliegenden Abbilds, und die Fassungsmarke, falls das Abbild eine
 * traegt.
 *
 * Ohne diese Auskunft laesst sich die Wirkung von "Abbild neu holen" gar nicht
 * beurteilen - und genau das war bis 0.9.9 der Fall: der Knopf zog das Abbild,
 * der laufende Container blieb auf dem alten Stand, und nichts sagte es.
 */
function mt_container_fassung($cfg = null)
{
    if ($cfg === null) {
        $cfg = mt_config();
    }
    $abbild = mt_container_abbild($cfg);
    $erg = array('container' => '', 'abbild' => '', 'marke' => '');
    list($ok, $aus) = mt_docker('inspect -f {{.Image}} ' . escapeshellarg(mt_container_name($cfg)));
    if ($ok) {
        $erg['container'] = trim($aus);
    }
    list($ok, $aus) = mt_docker('image inspect -f {{.Id}} ' . escapeshellarg($abbild));
    if ($ok) {
        $erg['abbild'] = trim($aus);
    }
    list($ok, $aus) = mt_docker('image inspect -f '
        . escapeshellarg('{{index .Config.Labels "org.opencontainers.image.version"}}') . ' '
        . escapeshellarg($abbild));
    if ($ok) {
        $marke = trim($aus);
        // Fehlt die Marke, gibt Docker "<no value>" aus - das ist keine Fassung.
        $erg['marke'] = ($marke === '' || strpos($marke, '<no value>') !== false) ? '' : $marke;
    }
    return $erg;
}

/**
 * Den Matter-Server wirklich aktualisieren.
 *
 * "Abbild holen" allein wirkt nicht: der laufende Container haengt an der
 * Kennung, mit der er angelegt wurde. Erst Neuanlegen bringt den neuen Stand.
 * Der Datenordner - und damit die Fabric - bleibt dabei unberuehrt; er haengt
 * am Ablageort, nicht am Container.
 *
 * Gemeldet wird die WIRKUNG: die Kennung vorher und nachher. Hat sich nichts
 * geaendert, steht das ausdruecklich da, statt einen Erfolg zu behaupten.
 *
 * 0.9.35 (Nr. 6): neu angelegt wird ueber mt_container_neu_anlegen() - mit
 * Rueckweg und automatischer Fabric-Sicherung. Bis 0.9.34 wurde der alte
 * Container erst entfernt und dann der neue angelegt; scheiterte das Anlegen,
 * stand gar kein Matter-Server mehr da. Nur aus dem Hintergrundvorgang.
 *
 * Rueckgabe: array(ok, Meldung)
 */
function mt_container_aktualisieren(array &$stand)
{
    $cfg = mt_config();
    if (!mt_docker_da()) {
        return array(0, mt_t('EINST.M_AKT_KEIN_DOCKER'));
    }
    $vorher = mt_container_fassung($cfg);
    if ($vorher['container'] === '') {
        return array(0, mt_t('EINST.M_AKT_KEIN_CONTAINER'));
    }
    /* Matter2Lox-a1: aktualisieren heisst neu anlegen - nur beim eigenen
     * Container, und gefragt wird VOR dem Abbildholen. */
    list($eigen, $grund) = mt_container_eigen($cfg);
    if ($eigen !== 1) {
        $n = mt_container_name($cfg);
        return array(0, mt_e(sprintf(mt_t('EINST.CONTAINER_NICHT_EIGEN'), $n, $grund,
                                     'docker rm -f ' . $n)));
    }
    $stand['schritt'] = 'holen';
    mt_ct_vorgang_schreiben($stand);
    list($ok, $aus) = mt_container('holen');
    if (!$ok) {
        return array(0, sprintf(mt_t('EINST.M_AKT_PULL_FEHL'), mt_e(substr($aus, 0, 300))));
    }
    $gezogen = mt_container_fassung($cfg);
    if ($gezogen['abbild'] !== '' && $gezogen['abbild'] === $vorher['container']) {
        // Nichts Neues. Den Container dafuer anzuhalten waere eine
        // Betriebsunterbrechung ohne jeden Gegenwert.
        $satz = sprintf(mt_t('EINST.M_AKT_UNVERAENDERT'),
                        mt_e(substr($vorher['container'], 0, 19)),
                        $gezogen['marke'] !== '' ? mt_e($gezogen['marke']) : '?');
        // 0.9.35 (Nr. 5): weichen die Einstellungen ab, wird das gesagt.
        $ab = mt_container_abweichung($cfg);
        if ($ab) {
            $satz .= ' ' . mt_t('EINST.M_AKT_ABWEICHUNG');
        }
        return array(1, $satz);
    }
    list($ok, $satz) = mt_container_neu_anlegen($cfg, mt_container_name($cfg), $stand,
                                                (int) $stand['start'] + mt_ct_frist());
    if (!$ok) {
        return array(0, $satz);
    }
    $nachher = mt_container_fassung($cfg);
    return array(1, sprintf(mt_t('EINST.M_AKT_OK'),
                            mt_e(substr($vorher['container'], 0, 19)),
                            mt_e(substr($nachher['container'], 0, 19)),
                            $nachher['marke'] !== '' ? mt_e($nachher['marke']) : '?') . ' ' . $satz);
}

/**
 * 0.9.35 (Nr. 5): "Einstellungen uebernehmen (Container neu anlegen)" - nur
 * aus dem Hintergrundvorgang. Neu angelegt wird der eigene Container unter
 * dem eingestellten Namen; gibt es den nicht, aber einen eigenen unter einem
 * anderen Namen (container_name geaendert, Label dieses Plugins), wird DER
 * abgeloest. Fehlt das Abbild, wird es vorher geholt. Rueckgabe array(ok, html).
 */
function mt_ct_uebernehmen(array &$stand)
{
    if (mt_paths()['home'] === '') {
        return array(0, mt_t('EINST.ARCHIV_VERWEIGERT'));
    }
    $cfg = mt_config();
    if ((string) $cfg['eigener_container'] !== '1') {
        return array(0, mt_t('EINST.V_NUR_EIGENER'));
    }
    $ende = (int) $stand['start'] + mt_ct_frist();
    list($lage, $lsatz) = mt_docker_lage(20);
    if ($lage !== 'ok') {
        $h = mt_docker_hinweis($lage);
        return array(0, $h !== '' ? $h : mt_e($lsatz));
    }
    $name = mt_container_name($cfg);
    list($eigen, $grund) = mt_container_eigen($cfg);
    if ($eigen === 1) {
        $alt = $name;
    } elseif ($eigen === 2) {
        $andere = mt_container_andere($cfg);
        $alt = '';
        foreach ($andere as $a) {
            // Ein laufender geht vor; Reste eines Neuanlegens (-alt-) nie.
            if (strpos($a['name'], '-alt-') !== false) {
                continue;
            }
            if ($alt === '' || $a['zustand'] === 'running') {
                $alt = $a['name'];
            }
        }
        if ($alt === '') {
            return array(0, sprintf(mt_t('EINST.U_NICHTS'), mt_e($name)));
        }
    } else {
        return array(0, mt_e(sprintf(mt_t('EINST.CONTAINER_NICHT_EIGEN'), $name, $grund,
                                     'docker rm -f ' . $name)));
    }
    $abbild = mt_container_abbild($cfg);
    list($da, ) = mt_docker('image inspect ' . escapeshellarg($abbild), 30);
    if (!$da) {
        $rest = $ende - time();
        if ($rest < 5) {
            return array(0, sprintf(mt_t('EINST.E_FRIST'), mt_ct_frist() / 60, mt_e(mt_t('EINST.V_S_HOLEN'))));
        }
        $stand['schritt'] = 'holen';
        mt_ct_vorgang_schreiben($stand);
        list($ok, $aus) = mt_docker('pull ' . escapeshellarg($abbild), $rest);
        if (!$ok) {
            return array(0, sprintf(mt_t('EINST.E_PULL_FEHL'), mt_e($abbild))
                            . ' <span class="sm-mono">' . mt_e(substr($aus, 0, 400)) . '</span>');
        }
    }
    return mt_container_neu_anlegen($cfg, $alt, $stand, $ende);
}

/**
 * 0.9.35 (Nr. E5): alle Matter-Server-Container dieses Rechners (Abbild
 * python-matter-server oder matterjs-server), mit Bauart, Zustand und der
 * Angabe, ob ihr /data die Fabric DIESES Plugins ist. Beide Bauarten duerfen
 * nie gleichzeitig auf demselben Datenordner laufen (matterjs-server schreibt
 * die Python-Dateien mit). Rueckgabe: Liste array(name, abbild, bauart,
 * laeuft, eigene_fabric); leer, wenn Docker nicht antwortet.
 */
function mt_container_bauarten()
{
    list($ok, $aus) = mt_docker('ps -a --format ' . escapeshellarg('{{.Names}}|{{.Image}}|{{.State}}'));
    if (!$ok) {
        return array();
    }
    $fabric = mt_fabric_pfad();
    $fecht = @realpath($fabric);
    $liste = array();
    foreach (explode("\n", (string) $aus) as $z) {
        $t = explode('|', trim($z));
        if (count($t) < 3 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,90}$/', $t[0])
            || (strpos($t[1], 'python-matter-server') === false && strpos($t[1], 'matterjs-server') === false)) {
            continue;
        }
        list($mok, $quelle) = mt_docker('inspect --type container -f '
            . escapeshellarg('{{range .Mounts}}{{if eq .Destination "/data"}}{{.Source}}{{end}}{{end}}')
            . ' -- ' . escapeshellarg($t[0]), 10);
        $quelle = trim((string) $quelle);
        $qecht = $quelle !== '' ? @realpath($quelle) : false;
        $liste[] = array('name' => $t[0], 'abbild' => $t[1], 'bauart' => mt_abbild_bauart($t[1]),
                         'laeuft' => $t[2] === 'running',
                         'eigene_fabric' => $mok && $quelle !== ''
                             && ($quelle === $fabric || ($qecht !== false && $qecht === $fecht)));
    }
    return $liste;
}

/**
 * 0.9.35 (Nr. 6): den eigenen Container MIT RUECKWEG neu anlegen.
 *
 *  1. den alten ($alt) umbenennen in <alt>-alt-<zeit> und anhalten,
 *  2. die Fabric sichern (mt_fabric_auto_sichern(), bei angehaltenem
 *     Container - sonst wird mitten in offene Dateien gepackt); scheitert
 *     das, wird NICHT neu angelegt,
 *  3. den neuen unter dem eingestellten Namen anlegen (docker run -d startet
 *     ihn) und 8 s beobachten: laeuft er und ist nicht neu gestartet worden?
 *  4. erst dann den alten entfernen. Sonst: den neuen entfernen, den alten
 *     zurueckbenennen und starten.
 * Die Fabric selbst wird nie angefasst. Rueckgabe array(ok, html).
 */
function mt_container_neu_anlegen($cfg, $alt, array &$stand, $ende)
{
    $p = mt_paths();
    $name = mt_container_name($cfg);
    $rest_name = substr((string) $alt, 0, 60) . '-alt-' . date('YmdHis');
    mt_erreichbar_vergessen();

    $stand['schritt'] = 'umbenennen';
    mt_ct_vorgang_schreiben($stand);
    $war_an = mt_container_zustand($alt) === 'laeuft';
    list($ok, $aus) = mt_docker('rename ' . escapeshellarg($alt) . ' ' . escapeshellarg($rest_name), 60);
    if (!$ok) {
        return array(0, sprintf(mt_t('EINST.N_RENAME_FEHL'), mt_e($alt))
                        . ' <span class="sm-mono">' . mt_e(substr($aus, 0, 300)) . '</span>');
    }
    mt_log('Container: ' . $alt . ' umbenannt in ' . $rest_name . ' (Rueckweg beim Neuanlegen).');
    $stand['schritt'] = 'anhalten';
    mt_ct_vorgang_schreiben($stand);
    list($ok, $aus) = mt_docker('stop -t 20 ' . escapeshellarg($rest_name), 60);
    if (!$ok) {
        $r = mt_container_rueckweg($rest_name, $alt, $war_an, '');
        return array(0, sprintf(mt_t('EINST.N_STOP_FEHL'), mt_e($alt))
                        . ' <span class="sm-mono">' . mt_e(substr($aus, 0, 300)) . '</span> ' . $r);
    }

    $stand['schritt'] = 'sichern';
    mt_ct_vorgang_schreiben($stand);
    list($sok, $swas) = mt_fabric_auto_sichern();
    if (!$sok) {
        $r = mt_container_rueckweg($rest_name, $alt, $war_an, '');
        return array(0, sprintf(mt_t('EINST.N_SICHERUNG_FEHL'), $swas) . ' ' . $r);
    }
    $gesichert = $swas !== '' ? sprintf(mt_t('EINST.N_GESICHERT'), mt_e(basename($swas))) : mt_t('EINST.N_NICHTS_ZU_SICHERN');

    $rest = (int) $ende - time();
    if ($rest < 15) {
        $r = mt_container_rueckweg($rest_name, $alt, $war_an, '');
        return array(0, sprintf(mt_t('EINST.E_FRIST'), mt_ct_frist() / 60, mt_e(mt_t('EINST.V_S_ANLEGEN'))) . ' ' . $r);
    }
    $stand['schritt'] = 'anlegen';
    mt_ct_vorgang_schreiben($stand);
    if (!is_dir($p['fabric'])) {
        @mkdir($p['fabric'], 0700, true);
    }
    list($ok, $aus) = mt_docker(mt_container_befehl($cfg), $rest - 10);
    $laeuft = $ok ? mt_container_bleibt($name, 8) : '';
    if (!$ok || $laeuft !== '') {
        $grund = !$ok ? substr($aus, 0, 400) : $laeuft;
        $r = mt_container_rueckweg($rest_name, $alt, $war_an, $name);
        return array(0, mt_t('EINST.N_NEU_FEHL') . ' <span class="sm-mono">' . mt_e($grund) . '</span> ' . $r);
    }
    list($ok, $aus) = mt_docker('rm -f ' . escapeshellarg($rest_name), 60);
    mt_log('Container: neu angelegt als ' . $name . ', ' . ($ok ? 'alter (' . $rest_name . ') entfernt.'
                                                               : 'alter (' . $rest_name . ') liess sich nicht entfernen.'));
    if (!$ok) {
        return array(1, sprintf(mt_t('EINST.N_OK_REST'), mt_e($name), mt_e($rest_name)) . ' ' . $gesichert);
    }
    return array(1, sprintf(mt_t('EINST.N_OK'), mt_e($name)) . ' ' . $gesichert);
}

/**
 * 0.9.35 (Nr. 6): Laeuft der frisch angelegte Container nach $sekunden noch,
 * ohne neu gestartet worden zu sein? Rueckgabe '' (ja) oder der Befund mit
 * den letzten Zeilen seines Protokolls.
 */
function mt_container_bleibt($name, $sekunden = 8)
{
    sleep(max(1, (int) $sekunden));
    list($ok, $aus) = mt_docker('inspect --type container -f '
        . escapeshellarg('{{.State.Running}}|{{.RestartCount}}|{{.State.ExitCode}}') . ' -- ' . escapeshellarg($name), 30);
    $t = explode('|', trim((string) $aus));
    if ($ok && count($t) === 3 && $t[0] === 'true' && (int) $t[1] === 0) {
        return '';
    }
    list(, $log) = mt_docker('logs --tail 15 ' . escapeshellarg($name), 30);
    $log = preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', (string) $log);
    return sprintf(mt_t('EINST.N_LAEUFT_NICHT'), isset($t[0]) ? $t[0] : '?', isset($t[1]) ? (int) $t[1] : -1,
                   isset($t[2]) ? (int) $t[2] : -1) . ' ' . substr(trim($log), -600);
}

/**
 * 0.9.35 (Nr. 6): Rueckweg - den neuen ($neu, falls angelegt und mit unserem
 * Label) entfernen, den alten zurueckbenennen und, wenn er lief, starten.
 * Rueckgabe: ein Satz (HTML) ueber das Ergebnis.
 */
function mt_container_rueckweg($rest_name, $alt, $war_an, $neu)
{
    if ($neu !== '') {
        $ist = mt_container_ist($neu);
        if ($ist !== null && $ist['label'] === mt_container_label()) {
            mt_docker('rm -f ' . escapeshellarg($neu), 60);
        }
    }
    list($ok, $aus) = mt_docker('rename ' . escapeshellarg($rest_name) . ' ' . escapeshellarg($alt), 60);
    if (!$ok) {
        mt_log('Container: Rueckweg gescheitert - ' . $rest_name . ' liess sich nicht zurueckbenennen.');
        return sprintf(mt_t('EINST.N_RUECK_FEHL'), mt_e($rest_name), mt_e($alt));
    }
    if ($war_an) {
        list($ok, ) = mt_docker('start ' . escapeshellarg($alt), 60);
        mt_log('Container: Rueckweg - ' . $alt . ' zurueckbenannt' . ($ok ? ' und gestartet.' : ', Start gescheitert.'));
        return sprintf(mt_t($ok ? 'EINST.N_RUECK_OK' : 'EINST.N_RUECK_START_FEHL'), mt_e($alt));
    }
    mt_log('Container: Rueckweg - ' . $alt . ' zurueckbenannt.');
    return sprintf(mt_t('EINST.N_RUECK_OK_AUS'), mt_e($alt));
}

/* ==================================================================
 * Matter-Server einrichten per Knopf, im Hintergrund (E1), und die Ampel
 * (E2) - Verbesserungsbau Welle 2, 30.09.2026.
 *
 * Muster: MGiSmart 1.1.20 (mg_gw_vorgang_*, mg_gw_ampel, bin/gateway_vorgang.php)
 * und Sprachsteuerung 0.11.12 (sp_ct_vorgang_*, bin/container_vorgang.php).
 * Bis 0.9.33 liefen "Container anlegen" und "Abbild holen" im Seitenaufruf
 * (mt_docker mit Frist 900 s): beim ersten Mal stand die Seite bis zu
 * 15 Minuten, und der Anwender musste die Reihenfolge (erst holen, dann
 * anlegen) selbst kennen.
 *
 * Unveraendert (Entscheidungen 11 und 15): die Aufrufzeile ist
 * mt_container_befehl(), die Eigentumspruefung ist EINE Stelle
 * (bin/container_eigen.sh ueber mt_container_eigen()), Fabric und
 * Datenordner werden nie angefasst, eigener_container=0 legt nichts an.
 * ================================================================== */

/** Hoechstdauer des Vorgangs "Matter-Server einrichten" in Sekunden. */
function mt_ct_frist()
{
    return 900;
}

/**
 * Ist Docker da und ansprechbar? Rueckgabe array(lage, satz) mit lage
 * ok | fehlt | kein_zugriff | dienst_aus | haengt | fehler; satz ist
 * schlichter Text (nicht maskiert). Bauart mg_docker_lage() (MGiSmart) bzw.
 * dk_zustand() (Docker NG): "docker info" mit Frist.
 */
function mt_docker_lage($sekunden = 10)
{
    if (!mt_docker_da()) {
        return array('fehlt', mt_t('EINST.A_DOCKER_FEHLT'));
    }
    $sekunden = max(1, (int) $sekunden);
    $aus = array();
    $rc = 0;
    @exec('timeout -k 2 ' . $sekunden . ' docker info --format ' . escapeshellarg('{{.ServerVersion}}')
          . ' 2>&1', $aus, $rc);
    $text = trim(implode(' ', $aus));
    if ($rc === 0) {
        return array('ok', sprintf(mt_t('EINST.A_DOCKER_OK'), substr($text, 0, 40)));
    }
    if ($rc === 124 || $rc === 137) {
        return array('haengt', sprintf(mt_t('EINST.A_DOCKER_HAENGT'), $sekunden));
    }
    $t = strtolower($text);
    if (strpos($t, 'permission denied') !== false) {
        return array('kein_zugriff', mt_t('EINST.A_DOCKER_ZUGRIFF'));
    }
    if (strpos($t, 'cannot connect') !== false || strpos($t, 'daemon running') !== false) {
        return array('dienst_aus', mt_t('EINST.A_DOCKER_DIENST'));
    }
    return array('fehler', sprintf(mt_t('EINST.A_DOCKER_FEHLER'), $rc, substr($text, 0, 200)));
}

/** Der lange Hinweis (HTML) zu einer Lage ohne Docker, oder ''. */
function mt_docker_hinweis($lage)
{
    if ($lage === 'fehlt') {
        return mt_t('EINST.DOCKER_FEHLT');
    }
    if (in_array($lage, array('kein_zugriff', 'dienst_aus'), true)) {
        return mt_t('EINST.DOCKER_KEIN_ZUGRIFF');
    }
    return '';
}

function mt_ct_vorgang_datei()
{
    return mt_paths()['datadir'] . '/container_vorgang.json';
}

/** Das Programm des Hintergrundvorgangs (installiert unter bin/plugins/<ordner>/). */
function mt_ct_vorgang_programm()
{
    return mt_paths()['bindir'] . '/container_vorgang.php';
}

/** Stand schreiben: atomar, Rechte 0600 vor dem Inhalt (mt_json_schreiben). */
function mt_ct_vorgang_schreiben(array $d)
{
    return mt_json_schreiben(mt_ct_vorgang_datei(), $d, 0600);
}

/** Laeuft der Prozess $pid wirklich als dieser Hintergrundvorgang? Argumentweise. */
function mt_ct_vorgang_prozess($pid)
{
    $pid = (int) $pid;
    if ($pid <= 0 || !is_readable('/proc/' . $pid . '/cmdline')) {
        return false;
    }
    $a = explode("\0", (string) @file_get_contents('/proc/' . $pid . '/cmdline'));
    return isset($a[1]) && $a[1] === mt_ct_vorgang_programm()
        && preg_match('#(^|/)php[0-9.]*\z#', (string) $a[0]) === 1;
}

/**
 * Der Stand des Hintergrundvorgangs. zustand: keiner | gestartet | laeuft |
 * fertig | fehler | abgebrochen. "abgebrochen": die Datei sagt "laeuft",
 * aber der Prozess ist fort - oder er ist nach 20 s nie angelaufen.
 */
function mt_ct_vorgang()
{
    $d = mt_json_lesen(mt_ct_vorgang_datei());
    if (!isset($d['zustand']) || !is_string($d['zustand'])) {
        return array('zustand' => 'keiner');
    }
    $d += array('vorgang' => '', 'start' => 0, 'pid' => 0, 'meldung' => '', 'schritt' => '', 'ende' => 0);
    /* 0.9.35 (Nr. 15): 'grund' sagt, woran ein Abbruch erkannt wurde -
     * prozess_fort (lief, Prozess ist weg) oder nie_angelaufen. */
    $d['grund'] = '';
    if ($d['zustand'] === 'laeuft' && !mt_ct_vorgang_prozess($d['pid'])) {
        $d['zustand'] = 'abgebrochen';
        $d['grund'] = 'prozess_fort';
    }
    if ($d['zustand'] === 'gestartet' && time() - (int) $d['start'] > 20) {
        $d['zustand'] = 'abgebrochen';
        $d['grund'] = 'nie_angelaufen';
    }
    return $d;
}

/** Laeuft gerade ein Vorgang? Dann sein Stand, sonst null. */
function mt_ct_vorgang_aktiv()
{
    $v = mt_ct_vorgang();
    return in_array($v['zustand'], array('gestartet', 'laeuft'), true) ? $v : null;
}

/**
 * Einen Hintergrundvorgang starten: 'einrichten', 'holen' oder
 * 'aktualisieren'. Kein Warten im Seitenaufbau (hoechstens 2 s, bis der
 * Vorgang seine Prozessnummer eingetragen hat). Zweimal starten geht nicht:
 * Pruefen und Eintragen stehen unter einer Sperre, die VOR dem Abzweigen
 * wieder freigegeben wird - eine offene Sperre vererbte sich sonst an den
 * Kindprozess (Muster sp_ct_vorgang_starten()). Aus dem Archivmodus wird
 * nichts gestartet (wie mt_container()). Rueckgabe array(ok, satz als HTML).
 */
function mt_ct_vorgang_starten($auftrag)
{
    // 0.9.35 (Nr. 5): 'uebernehmen' = Einstellungen uebernehmen (neu anlegen).
    if (!in_array($auftrag, array('einrichten', 'holen', 'aktualisieren', 'uebernehmen'), true)) {
        return array(0, mt_t('EINST.FEHLER_CONTAINERBEFEHL'));
    }
    $p = mt_paths();
    if ($p['home'] === '') {
        return array(0, mt_t('EINST.ARCHIV_VERWEIGERT'));
    }
    $cfg = mt_config();
    if (in_array($auftrag, array('einrichten', 'uebernehmen'), true) && (string) $cfg['eigener_container'] !== '1') {
        return array(0, mt_t('EINST.V_NUR_EIGENER'));
    }
    $prog = mt_ct_vorgang_programm();
    if (!is_file($prog) || !function_exists('proc_open')) {
        return array(0, sprintf(mt_t('EINST.V_PROGRAMM_FEHLT'), mt_e($prog)));
    }
    if (!is_dir($p['datadir'])) {
        @mkdir($p['datadir'], 0775, true);
    }
    $sperre = @fopen($p['datadir'] . '/container_vorgang.lock', 'c');
    if ($sperre === false) {
        // 0.9.35 (Nr. 15): gehoert die Datei root (Handstart), genuegt Lesen.
        $sperre = @fopen($p['datadir'] . '/container_vorgang.lock', 'r');
    }
    if ($sperre === false) {
        return array(0, mt_t('EINST.V_DATEI'));
    }
    /* 0.9.35 (Nr. 15): der laufende Vorgang haelt dieselbe Sperre fuer seine
     * ganze Laufzeit (bin/container_vorgang.php) - belegt heisst "laeuft". */
    if (!flock($sperre, LOCK_EX | LOCK_NB)) {
        fclose($sperre);
        return array(0, mt_t('EINST.V_LAEUFT_SCHON'));
    }
    $frei = mt_ct_vorgang_aktiv() === null;
    $geschrieben = $frei && mt_ct_vorgang_schreiben(array('vorgang' => $auftrag, 'zustand' => 'gestartet',
        'start' => time(), 'pid' => 0, 'schritt' => '', 'meldung' => ''));
    flock($sperre, LOCK_UN);
    fclose($sperre);
    if (!$frei) {
        return array(0, mt_t('EINST.V_LAEUFT_SCHON'));
    }
    if (!$geschrieben) {
        return array(0, mt_t('EINST.V_DATEI'));
    }
    $desk = array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'),
                  2 => array('file', '/dev/null', 'w'));
    $pipes = array();
    // setsid loest den Vorgang von Apache; "&" laesst die Schale sofort enden.
    $proc = @proc_open(array('sh', '-c', 'setsid "$0" "$@" </dev/null >/dev/null 2>&1 &', 'php', $prog, $auftrag),
                       $desk, $pipes);
    if (!is_resource($proc)) {
        mt_ct_vorgang_schreiben(array('vorgang' => $auftrag, 'zustand' => 'fehler', 'start' => time(),
            'ende' => time(), 'pid' => 0, 'schritt' => '', 'meldung' => mt_t('EINST.V_START_FEHL')));
        return array(0, mt_t('EINST.V_START_FEHL'));
    }
    proc_close($proc);
    mt_log('Container: Vorgang "' . $auftrag . '" gestartet.');
    // Gemeldet wird "gestartet" erst, wenn der Vorgang seine Prozessnummer
    // eingetragen hat - nicht auf den Rueckgabewert der Schale, die meldet nur,
    // dass sie abgezweigt hat (Muster sp_ct_vorgang_starten()).
    for ($i = 0; $i < 20; $i++) {
        $v = mt_ct_vorgang();
        if ($v['zustand'] !== 'gestartet') {
            return array(1, mt_t('EINST.V_GESTARTET_' . strtoupper($auftrag)));
        }
        usleep(100000);
    }
    return array(1, mt_t('EINST.V_NOCH_NICHT'));
}

/**
 * Der Stand fuer die Seite: array(klasse, html) oder null (nichts zu sagen).
 * Ein Ergebnis bleibt eine Stunde lang stehen.
 */
function mt_ct_vorgang_anzeige($v = null)
{
    if ($v === null) {
        $v = mt_ct_vorgang();
    }
    $z = $v['zustand'];
    $schritt = (isset($v['schritt']) && $v['schritt'] !== '') ? mt_t('EINST.V_S_' . strtoupper((string) $v['schritt'])) : '-';
    $art = mt_t('EINST.V_ART_' . strtoupper((string) (isset($v['vorgang']) && $v['vorgang'] !== '' ? $v['vorgang'] : 'einrichten')));
    if ($z === 'gestartet' || $z === 'laeuft') {
        return array('sm-hinweis', sprintf(mt_t('EINST.V_LAEUFT'), mt_e($art), max(0, time() - (int) $v['start']),
                                           mt_e($schritt)));
    }
    if ($z === 'abgebrochen') {
        /* 0.9.35 (Nr. 15): den moeglichen Grund nennen. Der Vorgang laeuft
         * als Kind des Webservers; ein Neustart von Apache (oder des
         * LoxBerry) beendet ihn mit, obwohl er per setsid abgeloest ist -
         * systemd raeumt die ganze Prozessgruppe des Dienstes ab. */
        $g = (isset($v['grund']) && $v['grund'] === 'nie_angelaufen') ? 'EINST.V_GRUND_NIE' : 'EINST.V_GRUND_FORT';
        return array('sm-warnung', sprintf(mt_t('EINST.V_ABGEBROCHEN'), mt_e($art), mt_e($schritt))
                                   . ' ' . sprintf(mt_t($g), mt_e(mt_paths()['logdir'] . '/container_vorgang.err')));
    }
    if (($z === 'fertig' || $z === 'fehler') && time() - (int) $v['ende'] < 3600) {
        $wann = date('d.m.Y H:i', (int) $v['ende']);
        // Die Meldung ist HTML, dessen veraenderliche Teile beim Schreiben
        // maskiert wurden (wie die Einmalmeldung, mt_einmal_schreiben()).
        return array($z === 'fertig' ? 'sm-hinweis' : 'sm-warnung',
                     sprintf(mt_t($z === 'fertig' ? 'EINST.V_FERTIG' : 'EINST.V_FEHLER'), mt_e($art), $wann)
                     . ' ' . (string) $v['meldung']);
    }
    return null;
}

/**
 * Den Matter-Server einrichten - nur aus dem Hintergrundvorgang.
 * 1. Docker ansprechbar? 2. Gibt es den Container schon? Eigen: laeuft ->
 * "schon eingerichtet", steht -> starten. Fremd oder nicht zu klaeren:
 * nichts anfassen. 3. Abbild da? Sonst holen. 4. Anlegen (mt_container_befehl,
 * docker run -d startet ihn). Alles zusammen hoechstens mt_ct_frist() ab dem
 * Knopfdruck. $stand wird fuer die Anzeige fortgeschrieben.
 * Rueckgabe array(ok, satz als HTML).
 */
function mt_ct_einrichten(array &$stand)
{
    if (mt_paths()['home'] === '') {
        return array(0, mt_t('EINST.ARCHIV_VERWEIGERT'));
    }
    $cfg = mt_config();
    if ((string) $cfg['eigener_container'] !== '1') {
        return array(0, mt_t('EINST.V_NUR_EIGENER'));
    }
    $ende = (int) $stand['start'] + mt_ct_frist();
    $name = mt_container_name($cfg);
    list($lage, $lsatz) = mt_docker_lage(20);
    if ($lage !== 'ok') {
        $h = mt_docker_hinweis($lage);
        return array(0, $h !== '' ? $h : mt_e($lsatz));
    }
    list($eigen, $grund) = mt_container_eigen($cfg);
    if ($eigen === 1) {
        if (mt_container_zustand() === 'laeuft') {
            return array(1, sprintf(mt_t('EINST.E_SCHON'), mt_e($name), mt_e($grund)));
        }
        $stand['schritt'] = 'starten';
        mt_ct_vorgang_schreiben($stand);
        list($ok, $aus) = mt_container('start');
        if ($ok && mt_container_zustand() === 'laeuft') {
            return array(1, sprintf(mt_t('EINST.E_SCHON_GESTARTET'), mt_e($name)));
        }
        return array(0, sprintf(mt_t('EINST.E_START_FEHL'), mt_e($name))
                        . ' <span class="sm-mono">' . mt_e(substr($aus, 0, 400)) . '</span>');
    }
    if ($eigen !== 2) {
        // Fremd oder nicht zu klaeren: NICHTS anfassen (Entscheidung 11/15).
        return array(0, mt_e(sprintf(mt_t('EINST.CONTAINER_NICHT_EIGEN'), $name, $grund,
                                     'docker start ' . $name)));
    }
    /* 0.9.35 (Nr. 5): kein zweiter Container neben einem eigenen. Gibt es
     * unter einem anderen Namen schon einen (Label dieses Plugins, oder der
     * Altbestand "matter-server"), legt "Einrichten" nichts an - beide
     * stuenden sonst auf demselben Port und derselben Fabric. */
    $andere = mt_container_andere($cfg);
    if ($andere) {
        $namen = array();
        foreach ($andere as $a) {
            $namen[] = $a['name'];
        }
        return array(0, sprintf(mt_t('EINST.E_ANDERER'), mt_e(implode(', ', $namen)), mt_e($name)));
    }
    $abbild = mt_container_abbild($cfg);
    $geholt = '';
    list($da, ) = mt_docker('image inspect ' . escapeshellarg($abbild), 30);
    if (!$da) {
        $rest = $ende - time();
        if ($rest < 5) {
            return array(0, sprintf(mt_t('EINST.E_FRIST'), mt_ct_frist() / 60, mt_e(mt_t('EINST.V_S_HOLEN'))));
        }
        $stand['schritt'] = 'holen';
        mt_ct_vorgang_schreiben($stand);
        list($ok, $aus) = mt_docker('pull ' . escapeshellarg($abbild), $rest);
        if (!$ok) {
            // Die Restfrist ist abgelaufen: mt_docker() haengt dann den Satz
            // FRIST_ABGELAUFEN mit genau dieser Frist an (Rueckgabe 124/137).
            // Nicht ueber die Uhr entschieden - in WSL endete "timeout" bis zu
            // 1,5 s vor der Frist (gemessen 30.09.2026, frist_debug).
            if (strpos($aus, sprintf(mt_t('EINST.FRIST_ABGELAUFEN'), $rest)) !== false) {
                return array(0, sprintf(mt_t('EINST.E_FRIST'), mt_ct_frist() / 60, mt_e(mt_t('EINST.V_S_HOLEN'))));
            }
            return array(0, sprintf(mt_t('EINST.E_PULL_FEHL'), mt_e($abbild))
                            . ' <span class="sm-mono">' . mt_e(substr($aus, 0, 400)) . '</span>');
        }
        $geholt = mt_t('EINST.E_ABBILD_GEHOLT');
    }
    $rest = $ende - time();
    if ($rest < 5) {
        return array(0, sprintf(mt_t('EINST.E_FRIST'), mt_ct_frist() / 60, mt_e(mt_t('EINST.V_S_ANLEGEN'))));
    }
    $stand['schritt'] = 'anlegen';
    mt_ct_vorgang_schreiben($stand);
    $p = mt_paths();
    if (!is_dir($p['fabric'])) {
        @mkdir($p['fabric'], 0700, true);
    }
    mt_erreichbar_vergessen();
    list($ok, $aus) = mt_docker(mt_container_befehl($cfg), $rest);
    if (!$ok) {
        if (strpos($aus, sprintf(mt_t('EINST.FRIST_ABGELAUFEN'), $rest)) !== false) {
            return array(0, sprintf(mt_t('EINST.E_FRIST'), mt_ct_frist() / 60, mt_e(mt_t('EINST.V_S_ANLEGEN'))));
        }
        return array(0, mt_t('EINST.E_RUN_FEHL') . ' <span class="sm-mono">' . mt_e(substr($aus, 0, 400)) . '</span>');
    }
    $zu = mt_container_zustand();
    if ($zu === 'laeuft') {
        return array(1, sprintf(mt_t('EINST.E_OK'), mt_e($name), $geholt));
    }
    return array(0, sprintf(mt_t('EINST.E_STEHT'), mt_e($name), mt_e(mt_t('ALLG.CONT_' . strtoupper($zu)))));
}

/**
 * Den Auftrag ausfuehren - von bin/container_vorgang.php gerufen. Schreibt
 * den Stand (laeuft, Schritt, fertig/fehler mit Meldung) und ins Protokoll.
 * "aktualisieren" ist mt_container_aktualisieren() unveraendert, "holen" ist
 * mt_container('holen') unveraendert - nur eben im Hintergrund.
 */
function mt_ct_vorgang_ausfuehren($auftrag)
{
    $v = mt_json_lesen(mt_ct_vorgang_datei());
    $stand = array('vorgang' => $auftrag, 'zustand' => 'laeuft', 'pid' => getmypid(),
                   'start' => isset($v['start']) && (int) $v['start'] > 0 ? (int) $v['start'] : time(),
                   'schritt' => 'pruefen', 'meldung' => '');
    mt_ct_vorgang_schreiben($stand);
    mt_log('Container ' . $auftrag . ': Vorgang laeuft (PID ' . getmypid() . ').');
    try {
        if ($auftrag === 'einrichten') {
            list($ok, $satz) = mt_ct_einrichten($stand);
        } elseif ($auftrag === 'holen') {
            $stand['schritt'] = 'holen';
            mt_ct_vorgang_schreiben($stand);
            list($ok, $aus) = mt_container('holen');
            $satz = sprintf(mt_t($ok ? 'EINST.CONTAINER_OK' : 'EINST.CONTAINER_FEHL'), 'holen')
                  . ' <span class="sm-mono">' . mt_e(substr($aus, 0, $ok ? 200 : 800)) . '</span>';
        } elseif ($auftrag === 'uebernehmen') {
            // 0.9.35 (Nr. 5)
            list($ok, $satz) = mt_ct_uebernehmen($stand);
        } else {
            $stand['schritt'] = 'aktualisieren';
            mt_ct_vorgang_schreiben($stand);
            list($ok, $satz) = mt_container_aktualisieren($stand);
        }
    } catch (Throwable $e) {
        $ok = 0;
        $satz = sprintf(mt_t('EINST.V_AUSNAHME'), mt_e(substr($e->getMessage(), 0, 200)));
    }
    $stand['zustand'] = $ok ? 'fertig' : 'fehler';
    $stand['ende'] = time();
    $stand['meldung'] = (string) $satz;
    mt_ct_vorgang_schreiben($stand);
    mt_log('Container ' . $auftrag . ': ' . ($ok ? 'fertig' : 'nicht gelungen') . ' - '
           . trim(preg_replace('/\s+/', ' ', strip_tags((string) $satz))));
    return $ok ? 1 : 0;
}

/**
 * Die Ampel (E2): drei Zeilen, jede array(farbe, html) mit farbe
 * gruen | rot | grau. Unbekannt ist grau, nie gruen.
 *  container - laeuft der eigene Container? Grau: eigener Matter-Server
 *              (eigener_container=0), kein Docker / kein Zugriff, fremder
 *              Container, nicht zu klaeren.
 *  server    - antwortet der Matter-Server? Ist der Brueckendienst mit ihm
 *              verbunden (Herzschlag frisch), genuegt das; sonst die
 *              vorhandene Erreichbarkeitspruefung mt_erreichbar() mit ihrem
 *              30-s-Zwischenspeicher (hoechstens ein Verbindungsversuch je
 *              30 s, derselbe wie im Reiter Test) - und nur, wenn der eigene
 *              Container laeuft oder der Server nicht vom Plugin betrieben
 *              wird; sonst grau, ohne Verbindungsversuch.
 *  bruecke   - ist die Bruecke verbunden, kommen Werte? Aus dem Dienststand
 *              (dienst.pid, zustand.json, loxone.json, mt_ok_endpunkt()).
 * Dazu 'docker' (Lage oder '') fuer den langen Hinweis.
 */
function mt_ct_ampel($cfg = null, $dlage = null)
{
    if ($cfg === null) {
        $cfg = mt_config();
    }
    $adr = (string) $cfg['server_host'] . ':' . (int) $cfg['server_port'];
    $a = array('docker' => '', 'eigen' => 0, 'andere' => array());
    // Zeile 1
    if ((string) $cfg['eigener_container'] !== '1') {
        $a['container'] = array('grau', sprintf(mt_t('EINST.A_C_EIGENER'), mt_e($adr)));
    } else {
        /* 0.9.35 (Nr. 9): die Lage dieses Seitenaufrufs (index.php misst sie
         * einmal), nicht ein zweites "docker info". */
        list($lage, $lsatz) = is_array($dlage) ? $dlage : mt_docker_lage(5);
        $a['docker'] = $lage;
        if ($lage !== 'ok') {
            $a['container'] = array('grau', mt_e($lsatz));
        } else {
            $name = mt_container_name($cfg);
            list($eigen, $grund) = mt_container_eigen($cfg);
            if ($eigen === 1) {
                $a['eigen'] = 1;
                $a['container'] = mt_container_zustand() === 'laeuft'
                    ? array('gruen', sprintf(mt_t('EINST.A_C_LAEUFT'), mt_e($name), mt_e($grund)))
                    : array('rot', sprintf(mt_t('EINST.A_C_STEHT'), mt_e($name)));
            } elseif ($eigen === 2) {
                /* 0.9.35 (Nr. 5): ein eigener Container unter anderem Namen? */
                $a['andere'] = mt_container_andere($cfg);
                $namen = array();
                foreach ($a['andere'] as $an) {
                    $namen[] = $an['name'];
                }
                $a['container'] = $namen
                    ? array('rot', sprintf(mt_t('EINST.A_C_ANDERER'), mt_e($name), mt_e(implode(', ', $namen))))
                    : array('rot', sprintf(mt_t('EINST.A_C_KEINER'), mt_e($name)));
            } elseif ($eigen === 0) {
                $a['container'] = array('grau', sprintf(mt_t('EINST.A_C_FREMD'), mt_e($name), mt_e($grund)));
            } else {
                $a['container'] = array('grau', sprintf(mt_t('EINST.A_C_UNKLAR'), mt_e($grund)));
            }
        }
    }
    // Zeile 2 und 3
    $pid = mt_dienst_pid();
    $lox = mt_loxone();
    $verbunden = $pid > 0 && mt_ok_endpunkt($lox, $cfg) === 1;
    if ($verbunden) {
        $a['server'] = array('gruen', sprintf(mt_t('EINST.A_S_DIENST'), mt_e($adr)));
    } elseif ((string) $cfg['eigener_container'] === '1' && $a['container'][0] !== 'gruen') {
        // Betreibt das Plugin den Server selbst und laeuft dessen Container
        // nicht, wird gar nicht erst gefragt: unbekannt, grau.
        $a['server'] = array('grau', mt_t('EINST.A_S_OHNE'));
    } else {
        list($ok, $alter, $fehler) = mt_erreichbar();
        $a['server'] = $ok
            ? array('gruen', sprintf(mt_t('EINST.A_S_PORT'), mt_e($adr), (int) $alter))
            : array('rot', sprintf(mt_t('EINST.A_S_NEIN'), mt_e($adr), mt_e($fehler !== '' ? $fehler : '-'),
                                   (int) $alter));
    }
    $z = mt_zustand();
    if ($pid === 0) {
        $a['bruecke'] = array('rot', mt_t(mt_dienst_soll() ? 'EINST.A_B_TOT' : 'EINST.A_B_AUS'));
    } elseif ($verbunden) {
        $n = count(mt_geraete());
        $al = mt_alter();
        $a['bruecke'] = array('gruen', sprintf(mt_t('EINST.A_B_OK'), $n, $al < 0 ? '-' : (int) $al . ' s'));
    } elseif (!isset($z['ok']) && empty($lox)) {
        $a['bruecke'] = array('grau', mt_t('EINST.A_B_UNBEKANNT'));
    } elseif ((isset($z['ok']) && (int) $z['ok'] !== 1) || empty($lox['ok'])) {
        $a['bruecke'] = array('rot', sprintf(mt_t('EINST.A_B_FEHLER'),
            mt_e(isset($z['fehler']) && (string) $z['fehler'] !== '' ? (string) $z['fehler'] : '-')));
    } else {
        $hs = isset($z['herzschlag']) ? (int) $z['herzschlag'] : 0;
        $a['bruecke'] = array('rot', sprintf(mt_t('EINST.A_B_ALT'),
            $hs > 0 ? max(0, time() - $hs) : -1, 3 * (int) $cfg['herzschlag']));
    }
    return $a;
}

/**
 * Der Datenordner der Fabric - das Wertvollste, was dieses Plugin hat.
 *
 * REGELN_1 Abschnitt 9: was gesichert werden muss, ergibt sich aus dem Code,
 * nicht aus dem Archiv - und die wertvollen Dateien sind gerade die, die kein
 * Archiv mitliefert. Hier sind das Fabric und Zertifikate. Wer sie verliert,
 * muss JEDES Geraet zuruecksetzen und neu anlernen. Die uninstall-Datei warnt
 * davor seit jeher; einen Knopf zum Sichern gab es bis 0.9.9 nicht.
 *
 * SEIT 0.9.17 LIEGT SIE NEBEN DEM DATENORDNER, nicht darin.
 *
 * Bis 0.9.16 war der Bindmount <datadir>/matter. Am plugininstall.pl des
 * LoxBerry nachgemessen (Zweig master, 03.09.2026): der Upgrade-Zweig ruft in
 * Zeile 886 purge_installation, und die loescht in Zeile 1631
 * data/plugins/<ordner>/ vollstaendig - ohne Bedingung, anders als den
 * Log-Ordner. Jedes Plugin-Update hat damit die Fabric mitgenommen, und weil
 * AUTOMATIC_UPDATES an ist, ohne Zutun des Anwenders. Der Kommentar in
 * preupgrade.sh und der Warntext in der Oberflaeche haben bis 0.9.16 das
 * Gegenteil behauptet.
 *
 * Was neben dem Ordner liegt, ueberlebt: purge_installation loescht den
 * Ordner, nicht seine Nachbarn. Dieselbe Bauart hat die Zweitschrift der
 * Konfiguration seit jeher (<ordner>.backup.json).
 *
 * Rueckgabe: array(ok, Meldung oder Pfad)
 */
function mt_fabric_pfad()
{
    return mt_paths()['fabric'];
}

/* Der Ort BIS 0.9.16. Wird nur noch gebraucht, um einen Altbestand zu
 * erkennen und darauf hinzuweisen - geschrieben wird dort nichts mehr. */
function mt_fabric_pfad_alt()
{
    return mt_paths()['datadir'] . '/matter';
}

function mt_fabric_groesse($pfad = null)
{
    if ($pfad === null) {
        $pfad = mt_fabric_pfad();
    }
    if (!is_dir($pfad)) {
        return -1;
    }
    $summe = 0;
    /* Zugriffsprobe: liefert scandir hier nichts, ist der Ordner unlesbar,
     * und eine Summe von 0 waere eine Falschaussage. */
    if (!is_array(@scandir($pfad))) {
        return -1;
    }
    $stapel = array($pfad);
    while ($stapel) {
        $d = array_pop($stapel);
        foreach ((array) @scandir($d) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $d . '/' . $f;
            if (is_dir($p)) {
                $stapel[] = $p;
            } else {
                $summe += (int) @filesize($p);
            }
        }
    }
    return $summe;
}

/** Gibt es tar? Ohne das laesst sich nichts packen. */
function mt_tar_da()
{
    $a = array();
    @exec('command -v tar 2>/dev/null', $a);
    return count($a) > 0 ? 1 : 0;
}

/**
 * Den eigenen Endpunkt WIRKLICH ueber HTTP aufrufen.
 *
 * Das ist die teuerste Fehlerklasse dieses Hauses: html/ und htmlauth/ liegen
 * installiert in getrennten Baeumen, und eine Leseprüfung sieht das nie. Nur
 * der echte Aufruf beantwortet, ob die Seite, die Loxone bedient, ueberhaupt
 * antwortet.
 *
 * Aufbau wortgleich nach dem geprueften Vorbild aus EVCC 0.9.18
 * (ev_selbsttest_endpunkt): curl, wenn vorhanden, sonst ein Stromkontext.
 * Das Ergebnis wird zwischengespeichert - diese Zeile laeuft sonst bei jedem
 * Seitenaufruf, und dann ruft sich der Webserver bei jedem Klick selbst auf.
 *
 * Rueckgabe: array(ok, HTTP-Code, erste Zeile, Adresse, Alter)
 */
function mt_selbsttest_endpunkt($hoechstalter = 120)
{
    $url = mt_endpunkt_adresse('liste');
    /* Endung .cache, nicht .json: das hier ist ein Zwischenspeicher, keine
     * Einstellung, und Werkzeuge, die data/plugins nach *.json absuchen,
     * sollen ihn nicht fuer eine solche halten. */
    $f = mt_paths()['datadir'] . '/.endpunkt.cache';
    $d = mt_json_lesen($f);
    if (isset($d['ts'], $d['url']) && $d['url'] === $url) {
        $alter = time() - (int) $d['ts'];
        if ($alter >= 0 && $alter <= $hoechstalter) {
            return array((int) $d['ok'], (int) $d['code'], (string) $d['text'], $url, $alter);
        }
    }
    $kopf = array('User-Agent: LoxBerry-Matter2Lox-Selbsttest', 'Accept: text/plain');
    $body = false;
    $code = 0;
    $netzfehler = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => $kopf,
        ));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $netzfehler = (string) curl_error($ch);
        if (PHP_VERSION_ID < 80000) { curl_close($ch); }
    } else {
        $ctx = stream_context_create(array('http' => array(
            'method' => 'GET', 'timeout' => 10, 'ignore_errors' => true,
            'header' => implode("\r\n", $kopf))));
        $body = @file_get_contents($url, false, $ctx);
        /* C7 (Durchgang 30.09.2026): Hausform statt der Zauber-Variablen, die
         * PHP 8.5 als veraltet meldet. Ab 8.4 gibt es die Funktion; darunter
         * wird die Variable ueber ihren Namen gelesen. */
        $kz = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : null;
        if ($kz === null) {
            $kn = 'http_response_header';
            $kz = isset($$kn) ? $$kn : null;
        }
        if (is_array($kz) && isset($kz[0]) && preg_match('#\s(\d{3})\s#', $kz[0], $m)) {
            $code = (int) $m[1];
        }
    }
    if ($body === false) {
        $text = $netzfehler !== '' ? $netzfehler : mt_t('TEST.A_ENDPUNKT_KEINE_ANTWORT');
        $ok = 0;
    } else {
        $text = trim(strtok((string) $body, "\n"));
        if ($text === '' && $code >= 500) {
            // Genau das Bild eines Endpunkts, der mit einem fatalen Fehler
            // abbricht: Code 500 und ein leerer Rumpf, weil display_errors
            // dort aus ist. Der Grund steht dann nur im Fehlerprotokoll des
            // Webservers.
            $text = mt_t('TEST.A_ENDPUNKT_LEER');
        }
        $ok = ($code === 200 && strpos($text, 'LISTE;') === 0) ? 1 : 0;
    }
    mt_json_schreiben($f, array('url' => $url, 'ok' => $ok, 'code' => $code,
                                'text' => $text, 'ts' => time()));
    return array($ok, $code, $text, $url, 0);
}

/**
 * Das aktive Thread-Dataset beim Border-Router abholen.
 *
 * Bis 0.9.17 musste der Bediener die Hexkette von Hand abschreiben - aus der
 * Weboberflaeche seines Border-Routers, aus einem Containerprotokoll oder aus
 * der Oberflaeche von Home Assistant. Der OpenThread-Border-Router
 * (ot-br-posix und alles, was davon abstammt) fuehrt selbst einen
 * REST-Dienst, ab Werk auf Port 8081: GET /node/dataset/active gibt mit
 * 'Accept: text/plain' genau die TLV-Hexkette zurueck, die das Feld hier
 * erwartet.
 *
 * Was dieser Weg NICHT kann, und so steht es auch in der Hilfe: einen
 * Border-Router von Apple oder Google auslesen. Beide geben ihr Dataset nur
 * ueber die Schnittstelle ihres eigenen Oekosystems heraus. Das Feld bleibt
 * deshalb von Hand befuellbar - der Abruf ist die Abkuerzung fuer einen
 * eigenen Border-Router, nicht ihr Ersatz.
 *
 * Der Aufbau ist der von mt_selbsttest_endpunkt(): curl, wenn vorhanden,
 * sonst ein Stromkontext. Ausdruecklich ohne Umleitungen - die Antwort soll
 * von genau der Adresse kommen, die der Bediener eingetragen hat.
 *
 * Uebergeben wird nichts: das Dataset landet in der Konfiguration, nicht im
 * Matter-Server. Dafuer bleibt der Knopf daneben zustaendig. Eine neue
 * Funktion schaltet nichts ein.
 *
 * Rueckgabe: array(stand, text)
 *   1 = Dataset geholt, der Text traegt es
 *   0 = schon die Adresse taugt nicht, es wurde nichts abgerufen
 *   2 = abgerufen, aber nichts Brauchbares bekommen
 */
function mt_thread_dataset_holen($adresse)
{
    $adr = trim((string) $adresse);
    if ($adr === '') {
        return array(0, mt_t('ANLERN.BR_LEER'));
    }
    /* Rechnername oder IP, wahlweise mit Port. Eine IPv6-Adresse gehoert in
     * eckige Klammern, sonst laesst sich ihr Doppelpunkt nicht vom Port
     * unterscheiden. Eine vollstaendige Adresse mit http:// davor wird
     * abgewiesen und NICHT zurechtgeschnitten - der Bediener soll die
     * erwartete Form sehen, nicht raten, was das Feld aus seiner Eingabe
     * gemacht hat. */
    if (!preg_match('#^(\[[0-9A-Fa-f:]{2,45}\]|[A-Za-z0-9][A-Za-z0-9.\-]{0,80})(?::([0-9]{1,5}))?$#',
                    $adr, $teile)) {
        return array(0, mt_t('ANLERN.BR_FORM'));
    }
    $port = (isset($teile[2]) && $teile[2] !== '') ? (int) $teile[2] : 8081;
    if ($port < 1 || $port > 65535) {
        return array(0, mt_t('ANLERN.BR_FORM'));
    }
    $url = 'http://' . $teile[1] . ':' . $port . '/node/dataset/active';
    $kopf = array('User-Agent: LoxBerry-Matter2Lox', 'Accept: text/plain');
    $body = false;
    $code = 0;
    $netzfehler = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $kopf,
        ));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $netzfehler = (string) curl_error($ch);
        if (PHP_VERSION_ID < 80000) { curl_close($ch); }
    } else {
        $ctx = stream_context_create(array('http' => array(
            'method' => 'GET', 'timeout' => 10, 'ignore_errors' => true,
            'max_redirects' => 0, 'header' => implode("\r\n", $kopf))));
        $body = @file_get_contents($url, false, $ctx);
        /* C7: Hausform wie in mt_selbsttest_endpunkt(). */
        $kz = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : null;
        if ($kz === null) {
            $kn = 'http_response_header';
            $kz = isset($$kn) ? $$kn : null;
        }
        if (is_array($kz) && isset($kz[0]) && preg_match('#\s(\d{3})\s#', $kz[0], $m)) {
            $code = (int) $m[1];
        }
    }
    if ($body === false) {
        return array(2, sprintf(mt_t('ANLERN.BR_KEINE_ANTWORT'),
                     $netzfehler !== '' ? $netzfehler : $url));
    }
    $ds = trim((string) $body);
    /* 204 ist die Antwort eines Border-Routers, der laeuft, aber noch kein
     * Thread-Netz gebildet hat. Das ist kein Fehler der Adresse und keine
     * Stoerung - deshalb eine eigene Meldung, die sagt, was dort fehlt. */
    if ($code === 204 || ($code === 200 && $ds === '')) {
        return array(2, sprintf(mt_t('ANLERN.BR_KEIN_DATASET'), $code));
    }
    if ($code !== 200) {
        return array(2, sprintf(mt_t('ANLERN.BR_HTTP'), $code));
    }
    /* Manche Aufbauten geben die Zeichenkette als JSON heraus, obwohl
     * text/plain erbeten war - dann stehen Anfuehrungszeichen darum. Das ist
     * das Auspacken einer fremden Antwort, nicht das Zurechtbiegen einer
     * Eingabe: was danach kein Dataset ist, wird abgewiesen. */
    $ds = trim($ds, "\"'");
    if (!preg_match('/^[0-9A-Fa-f]{20,600}$/', $ds)) {
        return array(2, sprintf(mt_t('ANLERN.BR_KEIN_HEX'),
                     substr(mt_mqtt_wert_saeubern($ds), 0, 80)));
    }
    return array(1, $ds);
}

/**
 * Klartext zu den Geraetetypen eines Endpunkts.
 *
 * Der Dienst rechnet sie seit jeher aus und schreibt sie ins Abbild, und die
 * Tabelle fuehrt dreizehn uebersetzte Namen dafuer - gelesen hat sie bis 0.9.9
 * kein einziges PHP. Dreizehn verwaiste Sprachschluessel und eine Auskunft,
 * die dalag und niemandem nutzte.
 */
function mt_geraetetyp_text($typen, $tab = null)
{
    if ($tab === null) {
        $tab = mt_tabelle();
    }
    $karte = isset($tab['geraetetyp']) && is_array($tab['geraetetyp']) ? $tab['geraetetyp'] : array();
    $aus = array();
    foreach ((array) $typen as $nr) {
        $nr = (string) $nr;
        if (isset($karte[$nr])) {
            $aus[mt_t($karte[$nr])] = 1;
        }
    }
    return implode(', ', array_keys($aus));
}

/** Die letzten Zeilen des Containerprotokolls. */
function mt_container_log($zeilen = 200)
{
    $name = mt_container_name();
    list($ok, $aus) = mt_docker('logs --tail ' . (int) $zeilen . ' ' . escapeshellarg($name));
    // Programmprotokolle vor dem Auswerten von Farbcodes befreien - sonst
    // steht mitten im Text eine ANSI-Sequenz.
    return preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', (string) $aus);
}

/* ---------------- Voraussetzungen des Wirtssystems ---------------- */

/**
 * Matter beruht auf IPv6-Link-Local-Multicast. Das ist keine Feinheit:
 * ohne IPv6 laeuft gar nichts, auch nicht teilweise.
 */
function mt_ipv6_zustand()
{
    if (!is_file('/proc/net/if_inet6')) {
        return array('ok' => 0, 'text' => 'IPv6 ist im Kern nicht vorhanden.');
    }
    $aus = trim((string) @file_get_contents('/proc/sys/net/ipv6/conf/all/disable_ipv6'));
    if ($aus === '1') {
        return array('ok' => 0, 'text' => 'IPv6 ist abgeschaltet (disable_ipv6 = 1).');
    }
    $zeilen = file('/proc/net/if_inet6', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array();
    return array('ok' => 1, 'text' => count($zeilen) . ' IPv6-Adressen auf diesem Rechner');
}

/**
 * Nimmt auf dem eingestellten Port jemand Verbindungen an?
 *
 * Bis 0.9.9 stand dieser Verbindungsversuch unmittelbar in mt_pruefungen() -
 * und die laeuft bei JEDEM Aufruf der Oberflaeche, weil alle sechs Flaechen
 * mitgerendert werden. Wer nur ins Protokoll sehen wollte, wartete dafuer bis
 * zu drei Sekunden auf einen Matter-Server, den er gar nicht gefragt hatte.
 *
 * Das Ergebnis wird deshalb kurz zwischengespeichert. Damit die Antwort nicht
 * heimlich alt wird, gibt die Funktion ihr Alter mit zurueck, und die
 * Oberflaeche schreibt es hin. Nach einem Eingriff an Dienst oder Container
 * wird der Zwischenspeicher verworfen (mt_erreichbar_vergessen()) - sonst
 * stuende nach dem Start des Containers noch eine Minute lang "nicht
 * erreichbar".
 *
 * Rueckgabe: array(ok, Alter in Sekunden, Fehlertext)
 */
function mt_erreichbar($hoechstalter = 30)
{
    $cfg = mt_config();
    $host = (string) $cfg['server_host'];
    $port = (int) $cfg['server_port'];
    $f = mt_paths()['datadir'] . '/.erreichbar.cache';   // Zwischenspeicher, keine Einstellung
    $d = mt_json_lesen($f);
    if (isset($d['ts'], $d['host'], $d['port'], $d['ok'])
            && (string) $d['host'] === $host && (int) $d['port'] === $port) {
        $alter = time() - (int) $d['ts'];
        if ($alter >= 0 && $alter <= $hoechstalter) {
            return array((int) $d['ok'], $alter, (string) (isset($d['fehler']) ? $d['fehler'] : ''));
        }
    }
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, 3);
    $ok = 0;
    if ($fp) {
        $ok = 1;
        fclose($fp);
    }
    mt_json_schreiben($f, array('host' => $host, 'port' => $port, 'ok' => $ok,
                                'fehler' => $ok ? '' : (string) $errstr, 'ts' => time()));
    return array($ok, 0, $ok ? '' : (string) $errstr);
}

function mt_erreichbar_vergessen()
{
    @unlink(mt_paths()['datadir'] . '/.erreichbar.cache');
    // Die Fassung bis 0.9.16 mit aufraeumen, damit kein Rest liegen bleibt.
    @unlink(mt_paths()['datadir'] . '/.erreichbar.json');
}

function mt_architektur()
{
    $b = trim((string) @php_uname('m'));
    return array('bogen' => $b, 'ok' => in_array($b, array('x86_64', 'aarch64', 'arm64'), true) ? 1 : 0);
}

/** Ausgabe von matter_dienst.py --selbsttest. */
function mt_selbsttest_ausgabe()
{
    $p = mt_paths();
    $py = $p['bindir'] . '/venv/bin/python3';
    $skript = $p['bindir'] . '/matter_dienst.py';
    if (!is_file($py) || !is_file($skript)) {
        return "[FEHL] Die virtuelle Python-Umgebung oder matter_dienst.py fehlt.\n"
             . '       Erwartet: ' . $py . "\n                 " . $skript . "\n"
             . '       Abhilfe: Plugin neu installieren.';
    }
    $ausgabe = array();
    $code = 0;
    /* Mit Frist: der Selbsttest klopft einmal mit 3 s Frist beim Matter-Server
     * an und ist sonst in Sekundenbruchteilen fertig. Ein haengendes Python
     * hielt bis 0.9.28 die Seite unbegrenzt an (in WSL gemessen 25.09.2026,
     * Pruefung-Matter2Lox-0.9.29, Fall T2). */
    @exec('timeout -k 5 30 ' . escapeshellarg($py) . ' ' . escapeshellarg($skript) . ' --selbsttest 2>&1',
          $ausgabe, $code);
    if ($code === 124 || $code === 137) {
        $ausgabe[] = '[FEHL] ' . sprintf(mt_t('EINST.FRIST_ABGELAUFEN'), 30);
    }
    return implode("\n", $ausgabe);
}

/** Die Werte des Status-Endpunkts: Einheit und Sprachschluessel. */
function mt_status_felder()
{
    // Drittes Feld: der Typ fuer die Loxone-Vorlage. OK und ERREICH sind
    // Ja/Nein, nicht Analogwerte.
    return array(
        'OK'      => array('',  'MT_FELD.OK',      'bool'),
        'ERREICH' => array('',  'MT_FELD.ERREICH', 'bool'),
        'ALTER'   => array('s', 'MT_FELD.ALTER',   'zahl'),
    );
}

/* ---------------- Token-Hilfen fuer den Endpunkt ----------------
 *
 * 0.9.35 (Nr. 8): ein zweites Token NUR fuer lesende Aktionen
 * (Schluessel lesetoken, leer = keines). Wer den virtuellen Eingaengen eine
 * Adresse gibt, gibt ihr damit nicht mehr das Recht, Tueren aufzusperren:
 * die Eingangsvorlagen tragen das Lesetoken, sobald eines gesetzt ist, die
 * Ausgangsvorlagen immer das Aktionstoken.
 */

/** Die Aktionen, die das Lesetoken oeffnet - an EINER Stelle. */
function mt_lesende_aktionen()
{
    return array('status', 'statusalle', 'wert', 'liste', 'roh');
}

/** Das Lesetoken; '' heisst: keines eingerichtet. Ein leeres gilt nie. */
function mt_lesetoken($cfg = null)
{
    if (!is_array($cfg)) {
        $cfg = mt_config();
    }
    $t = isset($cfg['lesetoken']) && is_scalar($cfg['lesetoken']) ? (string) $cfg['lesetoken'] : '';
    return trim($t) === '' ? '' : $t;
}

/**
 * Welches Token hat der Aufrufer vorgelegt? 'aktion', 'lesen' oder ''.
 * Beide Vergleiche mit hash_equals (gleichbleibende Zeit). Ein leeres
 * Aktionstoken oeffnet nichts - auch nicht ueber das Lesetoken: wer das
 * Aktionstoken bewusst leert, schliesst den Endpunkt ganz (das fragt
 * html/index.php vorher ab).
 */
function mt_token_art($cfg, $ist)
{
    $ist = is_string($ist) ? $ist : '';
    $soll = isset($cfg['aktionstoken']) && is_scalar($cfg['aktionstoken']) ? (string) $cfg['aktionstoken'] : '';
    if ($soll !== '' && hash_equals($soll, $ist)) {
        return 'aktion';
    }
    $lese = mt_lesetoken($cfg);
    if ($lese !== '' && hash_equals($lese, $ist)) {
        return 'lesen';
    }
    return '';
}

/**
 * Rechner (und Port) fuer die Adressen in den Loxone-Vorlagen und den
 * Selbsttest des Endpunkts.
 *
 * 0.9.35 (Nr. 11): nicht mehr der Host-Kopf des Browsers. Bis 0.9.34 stand
 * in der Vorlage, womit der Bediener die Oberflaeche geoeffnet hatte - ein
 * Rechnername, den der Miniserver nicht aufloesen kann, "localhost" aus
 * einem Tunnel oder die Adresse eines Reverse-Proxys. Reihenfolge:
 *   1. loxone_adresse aus der Konfiguration, wenn gesetzt und gueltig;
 *   2. LBSystem::get_localip(), mit Port, wenn der Webserver nicht auf 80
 *      lauscht (nur, wenn die LoxBerry-Bibliothek geladen ist);
 *   3. der Host-Kopf wie bisher, sonst der Rechnername.
 * Eine IPv6-Adresse bekommt eckige Klammern; vorhandene bleiben stehen
 * (bis 0.9.34 entfernte der Filter die Klammern und zerstoerte die Adresse).
 */
function mt_endpunkt_host($cfg = null)
{
    if (!is_array($cfg)) {
        $cfg = mt_config();
    }
    $klammern = function ($h) {
        // Zwei oder mehr Doppelpunkte ohne Klammer: eine blanke IPv6-Adresse.
        if (strpos($h, '[') === false && substr_count($h, ':') >= 2) {
            return '[' . $h . ']';
        }
        return $h;
    };
    $eigen = isset($cfg['loxone_adresse']) && is_scalar($cfg['loxone_adresse'])
        ? trim((string) $cfg['loxone_adresse']) : '';
    if ($eigen !== '' && preg_match('/^[A-Za-z0-9.\-:\[\]]{1,80}$/', $eigen)) {
        return $klammern($eigen);
    }
    $ip = '';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'get_localip')) {
        try {
            $ip = trim((string) LBSystem::get_localip());
        } catch (Throwable $e) {
            $ip = '';
        }
    }
    if ($ip !== '' && preg_match('/^[0-9A-Fa-f.:]{2,45}$/', $ip)) {
        $host = $klammern($ip);
        $port = 0;
        try {
            if (function_exists('lbwebserverport')) {
                $port = (int) lbwebserverport();
            } elseif (class_exists('LBSystem', false) && method_exists('LBSystem', 'lbwebserverport')) {
                $port = (int) LBSystem::lbwebserverport();
            }
        } catch (Throwable $e) {
            $port = 0;
        }
        if ($port > 0 && $port !== 80) {
            $host .= ':' . $port;
        }
        return $host;
    }
    // Rueckfall wie bis 0.9.34 - mit [ ] im erlaubten Zeichenvorrat.
    $host = isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])
        ? preg_replace('/[^A-Za-z0-9\.\-:\[\]]/', '', $_SERVER['HTTP_HOST']) : '';
    return $host !== '' ? $host : (gethostname() ?: 'loxberry');
}

/** Basisadresse des Endpunkts ohne /index.php: http://<rechner>/plugins/<ordner> */
function mt_endpunkt_basis($cfg = null)
{
    return 'http://' . mt_endpunkt_host($cfg) . '/plugins/' . mt_paths()['plugin'];
}

/**
 * Adresse des eigenen Status-Endpunkts.
 * 0.9.35 (Nr. 8): lesende Aktionen tragen das Lesetoken, wenn eines gesetzt
 * ist, alle anderen das Aktionstoken. 0.9.35 (Nr. 11): Rechner aus
 * mt_endpunkt_host().
 */
function mt_endpunkt_adresse($aktion, $nummer = null)
{
    $lese = in_array((string) $aktion, mt_lesende_aktionen(), true) ? mt_lesetoken() : '';
    return mt_endpunkt_basis()
         . '/index.php?token=' . ($lese !== '' ? $lese : mt_token()) . '&aktion=' . $aktion
         . ($nummer !== null ? '&geraet=' . (int) $nummer : '');
}

/**
 * Das Suchmuster fuer die Befehlserkennung - an EINER Stelle.
 *
 * Das fuehrende Semikolon gehoert zwingend dazu. Loxone sucht die Zeichenkette
 * woertlich und nimmt den ERSTEN Treffer. Die Statuszeile lautet
 *   MATTER;OK=1;ERREICH=1;ALTER=5;1_TEMPERATUR=21;11_TEMPERATUR=22
 * und "1_TEMPERATUR=" steckt woertlich in "11_TEMPERATUR=". Ohne Semikolon
 * liest der Eingang fuer Endpunkt 1 unter Umstaenden den Wert von Endpunkt 11
 * - bei einer Bridge der Normalfall, und ohne jede Fehlermeldung. Dieselbe
 * Verwechslung ist bei der Waermepumpe schon einmal aufgetreten (SOLL= traf
 * die Stelle in WWSOLL=), siehe wp_lib.php.
 *
 * Jede Marke der Statuszeile ist durch das implode(';') in
 * webfrontend/html/index.php von einem Semikolon eingeleitet - auch OK, denn
 * davor steht "MATTER".
 *
 * Bis 0.9.9 stand dieses Muster an ELF Stellen: drei erzeugten es, zwei
 * zeigten es an - und sechs weitere standen in den Sprachdateien, in den
 * Parametern der Baustein-Liste. Die Korrektur vom 17.08.2026 hat nur
 * webfrontend/ durchsucht und die Sprachdateien uebersehen; gemeldet hat
 * es hinterher ein Bestandslauf von aussen. Eine Suche, die nur den Code
 * absucht, findet ein Muster nicht, das in einer Datendatei steht.
 *
 * Seither holt auch die Baustein-Liste ihr Muster hier - zwei Wege fuer
 * dieselbe Frage sind einer zu viel.
 */
function mt_check($marke)
{
    return '\i;' . $marke . '=\i\v';
}

/**
 * Die Befehle eines Geraets fuer die Vorlage.
 *
 * $markenpraefix muss zu dem Endpunkt passen, den die Vorlage abfragt:
 * 'status' liefert die Marken blank (TEMPERATUR=), 'statusalle' stellt die
 * Geraetenummer voran (MATTER_3_1_TEMPERATUR=). Steht in der Vorlage ein
 * anderes Suchmuster als der Endpunkt ausgibt, bleibt der Eingang stumm -
 * ohne Fehlermeldung.
 */
function mt_vorlage_cmds($nummer, $g, $tab, $mit_status = true, $markenpraefix = '')
{
    $cmds = array();
    if ($mit_status) {
        foreach (mt_status_felder() as $feld => $info) {
            $cmds[] = array(
                'title'   => 'MATTER_' . (int) $nummer . '_' . $feld,
                'comment' => trim(strip_tags(html_entity_decode(mt_t($info[1]), ENT_QUOTES, 'UTF-8'))),
                'check'   => mt_check($feld),
                'typ'     => isset($info[2]) ? $info[2] : 'zahl',
                'einheit' => $info[0],
            );
        }
    }
    // Je erkanntem Endpunkt und Thema ein Befehl - die Titel je erkanntem
    // Geraet ausgeben, nicht als Platzhalter.
    if ($g !== null && !empty($g['endpunkte'])) {
        foreach ($g['endpunkte'] as $ep => $felder) {
            foreach ($felder as $thema => $wert) {
                $marke = $markenpraefix . strtoupper($ep . '_' . $thema);
                $i = mt_thema_info($thema, $tab);
                $cmds[] = array(
                    'title'   => 'MATTER_' . (int) $nummer . '_' . strtoupper($ep . '_' . $thema),
                    'comment' => $i['text'],
                    'check'   => mt_check($marke),
                    'typ'     => $i['typ'],
                    'min'     => $i['min'],
                    'max'     => $i['max'],
                    'einheit' => $i['einheit'],
                );
            }
        }
    }
    return $cmds;
}

/** Vorlage fuer EIN Geraet. Rueckgabe: array(name, inhalt) */
function mt_vorlage($nummer = 1)
{
    $geraete = mt_geraete();
    $g = isset($geraete[(string) $nummer]) ? $geraete[(string) $nummer] : null;
    return array(
        'VI_matter_geraet' . (int) $nummer . '.xml',
        mt_xml_virtual_in_http(array(
            'title'   => 'Matter ' . (int) $nummer . ($g !== null ? ' ' . $g['name'] : ''),
            // 0.9.35 (Nr. 8/11): Lesetoken, wenn gesetzt; Rechner aus
            // mt_endpunkt_host().
            'address' => mt_endpunkt_adresse('status', $nummer),
            'polling' => '60',
            'comment' => 'Erzeugt vom LoxBerry-Plugin Matter to Loxone (' . date('d.m.Y') . ')',
        ), mt_vorlage_cmds($nummer, $g, mt_tabelle())),
    );
}

/**
 * Vorlage fuer ALLE Geraete in EINER Datei.
 *
 * Der Grund: bei zwanzig Matter-Geraeten waren das bisher zwanzig Knoepfe,
 * zwanzig Downloads und zwanzig Importe. Eine XML-Datei hat aber nur EIN
 * Wurzelelement, also kann sie auch nur EINE Adresse abfragen - deshalb
 * gibt es dafuer den Endpunkt 'statusalle', der alle Geraete in einer Zeile
 * liefert. Die Marken tragen die Geraetenummer bereits im Namen, an der
 * Befehlserkennung aendert sich damit nichts.
 */
function mt_vorlage_alle()
{
    $tab = mt_tabelle();
    $cmds = array();
    foreach (mt_status_felder() as $feld => $info) {
        $cmds[] = array(
            'title'   => 'MATTER_' . $feld,
            'comment' => trim(strip_tags(html_entity_decode(mt_t($info[1]), ENT_QUOTES, 'UTF-8'))),
            'check'   => mt_check($feld),
            'typ'     => isset($info[2]) ? $info[2] : 'zahl',
            'einheit' => $info[0],
        );
    }
    foreach (mt_geraete() as $nr => $g) {
        // Markenpraefix wie in der Ausgabe von 'statusalle'.
        foreach (mt_vorlage_cmds($nr, $g, $tab, false, 'MATTER_' . (int) $nr . '_') as $c) {
            $cmds[] = $c;
        }
    }
    return array(
        'VI_matter_alle.xml',
        mt_xml_virtual_in_http(array(
            'title'   => 'Matter alle Geraete',
            // 0.9.35 (Nr. 8/11): Lesetoken, wenn gesetzt.
            'address' => mt_endpunkt_adresse('statusalle'),
            'polling' => '60',
            'comment' => 'Alle Geraete in einer Datei. Erzeugt vom LoxBerry-Plugin '
                       . 'Matter to Loxone (' . date('d.m.Y') . ')',
        ), $cmds),
    );
}

/**
 * Vorlage fuer die virtuellen AUSGAENGE eines Geraets.
 *
 * Angeboten wird nur, was das Geraet laut Cluster-Tabelle auch kann: ohne
 * OnOff kein Schaltbefehl, ohne LevelControl kein Helligkeitsregler. Ein
 * Ausgang, der ins Leere geht, ist schlimmer als keiner.
 *
 * 0.9.35 (Nr. 10a): je Endpunkt UND Thema ein Befehl. Bis 0.9.34 merkte
 * sich die Vorlage je Thema nur den letzten Endpunkt ($themen[$thema] =
 * $ep) - eine Bridge oder eine Doppelsteckdose bekam genau einen Schalter.
 * Titel: hat das Geraet ein Thema nur auf EINEM Endpunkt, bleibt der Titel
 * wie bisher (MATTER_<N>_<AKTION>, Schalter MATTER_<N>_EIN_AUS) - wer eine
 * Einendpunkt-Vorlage schon eingelesen und verdrahtet hat, findet dieselben
 * Namen wieder. Erst wenn es dasselbe Thema auf mehreren Endpunkten gibt,
 * traegt der Titel die Endpunktnummer: MATTER_<N>_<EP>_<AKTION>.
 * 0.9.35 (Nr. 11): Rechner aus mt_endpunkt_host(); immer das Aktionstoken
 * (das Lesetoken oeffnet keinen Stellbefehl).
 */
function mt_vorlage_out($nummer = 1)
{
    $geraete = mt_geraete();
    $g = isset($geraete[(string) $nummer]) ? $geraete[(string) $nummer] : null;
    $themen = array();
    if ($g !== null && !empty($g['endpunkte']) && is_array($g['endpunkte'])) {
        foreach ($g['endpunkte'] as $ep => $felder) {
            foreach ((array) $felder as $thema => $wert) {
                $themen[(string) $thema][] = (string) $ep;
            }
        }
    }
    // Die Adresse des VirtualOut traegt nur Rechner und Pfad; der Rest steht
    // je Befehl.
    $adresse = mt_endpunkt_basis();
    $frage = '/index.php?token=' . mt_token() . '&geraet=' . (int) $nummer . '&aktion=';

    /* Die Endpunkte, auf denen eines der Themen vorkommt, aufsteigend. */
    $eps = function ($liste) use ($themen) {
        $r = array();
        foreach ((array) $liste as $t) {
            if (isset($themen[$t])) {
                $r = array_merge($r, $themen[$t]);
            }
        }
        $r = array_values(array_unique($r));
        sort($r, SORT_NUMERIC);
        return $r;
    };
    $text = function ($schl) {
        return trim(strip_tags(html_entity_decode(mt_t($schl), ENT_QUOTES, 'UTF-8')));
    };
    /* Titel und Kommentar je Endpunkt (siehe Kopf: ohne EP bei nur einem). */
    $kopf = function ($teil, $ep, $mehrere, $titel) use ($nummer, $text) {
        return array(
            'title'   => 'MATTER_' . (int) $nummer . '_' . ($mehrere ? strtoupper($ep) . '_' : '') . $teil,
            'comment' => $text($titel) . ($mehrere ? ' - ' . sprintf($text('LOX.A_ENDPUNKT'), $ep) : ''),
        );
    };

    $cmds = array();
    // Analogbefehl: EINE Adresse mit dem Wertplatzhalter (<v.0> ganze Zahl,
    // <v.1> eine Nachkommastelle).
    $wert = function ($thema, $titel, $aktion, $platz = '<v.0>') use (&$cmds, $eps, $kopf, $frage) {
        $liste = $eps($thema);
        foreach ($liste as $ep) {
            $c = $kopf(strtoupper($aktion), $ep, count($liste) > 1, $titel);
            $c['analog'] = true;
            $c['on'] = $frage . $aktion . '&endpunkt=' . $ep . '&wert=' . $platz;
            $cmds[] = $c;
        }
    };
    // Schaltbefehl: zwei Adressen. Die Aktionen stehen genau so in der
    // Weissliste von webfrontend/html/index.php - ein erfundenes
    // 'schalten&wert=1' wuerde dort mit UNBEKANNTE_AKTION abgewiesen, und in
    // Loxone sieht man davon nichts.
    $digital = function ($thema, $titel, $teil, $ein, $aus) use (&$cmds, $eps, $kopf, $frage) {
        $liste = $eps($thema);
        foreach ($liste as $ep) {
            $c = $kopf($teil, $ep, count($liste) > 1, $titel);
            $c['analog'] = false;
            $c['on'] = $frage . $ein . '&endpunkt=' . $ep;
            $c['off'] = $frage . $aus . '&endpunkt=' . $ep;
            $cmds[] = $c;
        }
    };
    $digital('schalter',              'LOX.A_SCHALTEN',       'EIN_AUS', 'ein', 'aus');
    $wert('helligkeit',               'LOX.A_HELLIGKEIT',     'helligkeit');
    $wert('farbtemperatur_mired',     'LOX.A_FARBTEMPERATUR', 'farbtemperatur');
    // Farbton und Saettigung als zwei getrennte Analogbefehle: ein
    // VirtualOutCmd traegt genau EINEN Wertplatzhalter. Genau so machen es die
    // Ausfuhren dieser Anlage bei Helligkeit und Kelvin auch.
    $wert('farbton_roh',              'LOX.A_FARBTON',        'farbton');
    $wert('saettigung',               'LOX.A_SAETTIGUNG',     'saettigung');
    // 0.9.35 (Nr. 10c): der Ausgang des Loxone-Lichtbausteins (RGB als
    // BBBGGGRRR oder Lumitech 20bbbtttt) in EINEM Analogbefehl - fuer jede
    // Leuchte mit Farbtemperatur oder Farbe. Der Dienst zerlegt den Wert.
    $wert(array('farbtemperatur_mired', 'farbton_roh', 'farbe_x'),
                                      'LOX.A_LOXFARBE',       'loxfarbe');
    $wert('position',                 'LOX.A_ROLLO',          'rollo');
    $wert('lamelle',                  'LOX.A_LAMELLE',        'lamelle');
    // 0.9.35 (Nr. 10b): Sollwerte mit einer Nachkommastelle - mit <v.0>
    // kamen 21,5 Grad als 22 an.
    $wert('soll_heizen',              'LOX.A_SOLL_HEIZEN',    'soll_heizen', '<v.1>');
    $wert('soll_kuehlen',             'LOX.A_SOLL_KUEHLEN',   'soll_kuehlen', '<v.1>');
    $wert('betriebsart',              'LOX.A_BETRIEBSART',    'betriebsart');
    $wert('luefter_soll',             'LOX.A_LUEFTER',        'luefter');
    // 0.9.35 (Nr. 10c): Schloss als Digitalausgang, Ein = sperren, Aus =
    // entsperren. Wirkt nur mit dem eigenen Haken schloss_ein (der Kommentar
    // sagt es); die Schlossbremse im Endpunkt gilt auch hier.
    $digital('schloss',               'LOX.A_SCHLOSS',        'SCHLOSS', 'sperren', 'entsperren');

    return array(
        'VQ_matter_geraet' . (int) $nummer . '.xml',
        mt_xml_virtual_out(array(
            'title'   => 'Matter ' . (int) $nummer . ($g !== null ? ' ' . $g['name'] : '') . ' Befehle',
            'address' => $adresse,
            'comment' => 'Schreibende Befehle müssen im Reiter Einstellungen freigegeben '
                       . 'sein. Erzeugt vom LoxBerry-Plugin Matter to Loxone ('
                       . date('d.m.Y') . ')',
        ), $cmds),
    );
}

/** Klartext zu einem uebersetzten Thema, aus der Cluster-Tabelle. */
function mt_thema_text($thema, $tab = null)
{
    $i = mt_thema_info($thema, $tab);
    return $i['text'];
}

/**
 * Alles, was die Cluster-Tabelle ueber ein Thema weiss: Klartext, Typ,
 * Grenzen, Einheit und ob die Zuordnung schon an einem Geraet nachgemessen
 * wurde.
 */
function mt_thema_info($thema, $tab = null)
{
    if ($tab === null) {
        $tab = mt_tabelle();
    }
    $bauen = function ($a, $clustername, $ungeprueft) {
        return array(
            'text'       => trim(strip_tags(html_entity_decode(mt_t($a['text']), ENT_QUOTES, 'UTF-8'))),
            'typ'        => isset($a['typ']) ? (string) $a['typ'] : 'zahl',
            'min'        => isset($a['min']) ? $a['min'] : null,
            'max'        => isset($a['max']) ? $a['max'] : null,
            'einheit'    => isset($a['einheit']) ? (string) $a['einheit'] : '',
            'ungeprueft' => $ungeprueft,
            'cluster'    => $clustername,
        );
    };
    foreach ($tab['cluster'] as $cl) {
        foreach ((array) (isset($cl['attribute']) ? $cl['attribute'] : array()) as $a) {
            if (isset($a['thema']) && $a['thema'] === $thema) {
                return $bauen($a, isset($cl['name']) ? (string) $cl['name'] : '',
                              !empty($cl['_ungeprueft']));
            }
        }
    }
    // Themen, die nicht aus einem Attribut stammen: aus einem Ereignis
    // (Tastendruck) oder ausgerechnet (Kelvin aus Mired). Ohne diesen zweiten
    // Blick stuende in der Tabelle des Reiters MQTT und im Kommentar des
    // virtuellen Eingangs nur der nackte Themenname.
    foreach (array('ereignisthemen' => 'Switch', 'abgeleitete_themen' => '') as $schl => $cl) {
        if (!isset($tab[$schl]['themen']) || !is_array($tab[$schl]['themen'])) {
            continue;
        }
        foreach ($tab[$schl]['themen'] as $a) {
            if (isset($a['thema']) && $a['thema'] === $thema) {
                return $bauen($a, $cl, $schl === 'ereignisthemen');
            }
        }
    }
    return array('text' => (string) $thema, 'typ' => 'zahl', 'min' => null, 'max' => null,
                 'einheit' => '', 'ungeprueft' => false, 'cluster' => '');
}


/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Die sieben Punkte aus REGELN_2, und der wichtigste ist der dritte: eine
 * halb gueltige Datei ueberschreibt GAR NICHTS. Wer eine Sicherung
 * zurueckspielt, will entweder den ganzen Stand oder gar keinen - eine zur
 * Haelfte uebernommene Konfiguration ist schlimmer als die alte, und man
 * sieht es ihr nicht an.
 *
 * Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust: sie
 * stammen aus einer anderen Fassung oder einem anderen Plugin.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte).
 */
function mt_sicherung_lesen($roh)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(mt_t('EINST.SICH_KEIN_JSON')), 0);
    }
    $neu = mt_vorgaben();
    $bekannt = array_keys($neu);
    $anzahl = 0;
    $grenzen = mt_zahlgrenzen();
    foreach ($daten as $k => $w) {
        /* Der lesbare Kopf (_hinweis, _stand) wird UEBERGANGEN, nicht
         * beanstandet - Hausstandard. Bis 0.9.16 hat ihn diese Funktion als
         * fremden Schluessel abgewiesen und die ganze Datei verworfen. */
        if ($k !== '' && $k[0] === '_') {
            continue;
        }
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(mt_t('EINST.SICH_FREMD'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            continue;
        }
        /* Jeder WERT wird geprueft, nicht nur der Schluessel - gegen dieselbe
         * Positivliste wie im Formular. Bis 0.9.16 ging hier alles durch:
         * ein Feld als aktionstoken machte aus dem Vergleich im Endpunkt die
         * Zeichenkette "Array", und damit war der Endpunkt mit ?token=Array
         * bedienbar; eine Zeichenkette als server_port liess den Dienst bei
         * jedem Start mit ValueError sterben. */
        $grund = mt_wert_pruefen($k, $w);
        if ($grund !== '') {
            $mangel[] = sprintf(mt_t('EINST.SICH_WERT'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'), $grund);
            continue;
        }
        /* C9 (Durchgang 30.09.2026): Haken und Zahlen als Zahl uebernehmen.
         * Bis 0.9.30 blieb der Typ der Datei stehen: "schloss_ein": "0" kam
         * als Zeichenkette in die Konfiguration, die Oberflaeche las "aus",
         * der Dienst "ein" (gemessen, Bericht oberflaeche Nr. 3). */
        if (in_array($k, mt_haken(), true) || isset($grenzen[$k])) {
            $w = (int) $w;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    /* Ein FEHLENDER Schluessel ist ebenfalls ein Mangel. Bis 0.9.16 wurde er
     * lautlos durch die Werkseinstellung ersetzt: eine Datei mit einem
     * einzigen bekannten Schluessel wurde angenommen ("1 Wert uebernommen")
     * und setzte dabei Aktionstoken, WLAN-Passwort, Thread-Dataset und beide
     * Freigaben zurueck. Beim naechsten Seitenaufruf wurde die Werkseinstellung
     * dann auch noch ueber die Zweitschrift geschrieben. Der Kommentar oben
     * verspricht "eine halb gueltige Datei ueberschreibt GAR NICHTS" - das
     * gilt jetzt auch fuer die unvollstaendige. */
    /* Tuer-1 (Verbesserungsbau 30.09.2026): ein Schluessel, den eine
     * spaetere Fassung dazugebracht hat, darf in einer aelteren Sicherung
     * fehlen - er behaelt seine Vorgabe (ab Werk aus), und der Hinweis sagt
     * es. Sonst waere jede Sicherung bis 0.9.32 abgewiesen worden. Fuer alle
     * anderen gilt weiter: ein fehlender Schluessel ist ein Mangel. */
    $neu_fehlt = array_values(array_intersect(mt_sicherung_neue_schluessel(),
                                              array_diff($bekannt, array_keys($daten))));
    $fehlend = array_diff($bekannt, array_keys($daten), mt_sicherung_neue_schluessel());
    if ($fehlend) {
        $mangel[] = sprintf(mt_t('EINST.SICH_FEHLT'),
                             htmlspecialchars(implode(', ', $fehlend), ENT_QUOTES, 'UTF-8'));
    }
    if ($anzahl === 0) {
        $mangel[] = mt_t('EINST.SICH_LEER');
    }
    /* C5 (Durchgang 30.09.2026): ein LEERES Aktionstoken in der Datei loescht
     * das laufende nicht. Bis 0.9.30 wurde es angenommen - danach antwortete
     * jeder virtuelle Eingang mit 403 und dem falschen Satz "die Oberflaeche
     * wurde noch nie geoeffnet" (gemessen, Bericht code C5). Das laufende
     * Token bleibt, und der vierte Rueckgabewert sagt es. Ein Token leeren
     * laesst sich nur bewusst, nicht nebenbei ueber eine Datei. */
    $hinweise = array();
    if (!$mangel && $neu_fehlt) {
        $hinweise[] = sprintf(mt_t('EINST.SICH_NEU_VORGABE'), implode(', ', $neu_fehlt));
    }
    if (!$mangel && trim((string) $neu['aktionstoken']) === '') {
        $jetzt = mt_config(false);
        $neu['aktionstoken'] = is_scalar($jetzt['aktionstoken']) ? (string) $jetzt['aktionstoken'] : '';
        $hinweise[] = mt_t($neu['aktionstoken'] !== '' ? 'EINST.SICH_TOKEN_BEHALTEN'
                                                          : 'EINST.SICH_TOKEN_KEINS');
    }
    return array($mangel ? null : $neu, $mangel, $anzahl, $hinweise);
}

/**
 * Taugt dieser Wert fuer diese Einstellung?
 *
 * Rueckgabe: '' wenn er taugt, sonst der Grund im Klartext. Die Muster sind
 * dieselben wie im Speichern-Handler der Oberflaeche - eine Einstellung, die
 * ueber das Formular abgewiesen wuerde, darf ueber die Sicherungsdatei nicht
 * hereinkommen.
 */
/**
 * Die Grenzen der Zahlenfelder - an EINER Stelle (O6, Durchgang 30.09.2026).
 *
 * Formular (htmlauth/index.php) und Zurueckspielen (mt_wert_pruefen) lesen
 * beide hier. Bis 0.9.30 liess das Zurueckspielen wartezeit bis 200 zu, das
 * Formular bis 60: nach dem Zurueckspielen einer Sicherung mit 150 liess
 * sich der Reiter Einstellungen nicht mehr speichern (gemessen, Bericht
 * oberflaeche Nr. 9). Es gilt 60 - die Grenze, die das Formular seit jeher
 * zeigt; der Dienst klemmt ebenso (matter_dienst.py, config()).
 */
function mt_zahlgrenzen()
{
    return array('server_port' => array(1, 65535), 'wartezeit' => array(0, 60),
                 'bluetooth_adapter' => array(0, 9), 'sendetakt' => array(0, 60),
                 'herzschlag' => array(0, 3600));
}

/** Die Haken der Konfiguration - dieselbe Liste wie HAKEN im Dienst. */
function mt_haken()
{
    // 0.9.35 (Nr. 1): server_lokal neu im Haken-Satz (Vertrag, HAKEN im Dienst).
    return array('eigener_container', 'mqtt_ein', 'roh_ein', 'steuerung_ein', 'schloss_ein',
                 'tuer_haus', 'server_lokal');
}

/**
 * 0.9.35 (Nr. 1): die Muster der neuen Textfelder an EINER Stelle -
 * Formular (htmlauth/index.php) und Zurueckspielen (mt_wert_pruefen) lesen
 * beide hier. Werte laut Vertrag.
 */
function mt_textmuster()
{
    return array(
        'primary_interface' => '/^[A-Za-z0-9_.\-]{0,15}$/',
        'lesetoken'         => '/^[A-Za-z0-9_.\-]{0,64}$/',
        'loxone_adresse'    => '/^[A-Za-z0-9.\-:\[\]]{0,80}$/',
        'anlern_ip'         => '/^[0-9A-Fa-f:.]{2,45}$/',
    );
}

/**
 * Die Geraeteauswahl fuer MQTT (leer = alle): Geraetenummern, getrennt durch
 * Komma oder Semikolon, Leerzeichen daneben erlaubt. Dieselbe Pruefung im
 * Formular (Reiter MQTT) und beim Zurueckspielen. Nachtrag B-Nachzug
 * 01.10.2026: bis 0.9.34 nahmen beide auch reine Leerzeichen als Trenner
 * ("1 2"). Der Dienst trennt nur an Komma und Semikolon, las daraus keine
 * Nummer und veroeffentlichte dann ALLE Geraete - eine zurueckgespielte
 * Sicherung kam so ohne Meldung durch.
 */
function mt_mqtt_nur_gueltig($s)
{
    return is_string($s) && preg_match('/^([0-9]{1,3}( *[,;][ ,;]*[0-9]{1,3})*)?$/', $s) === 1;
}

function mt_wert_pruefen($schluessel, $wert)
{
    /* 1. Am Eingang: taugt der Wert ueberhaupt fuer eine Konfigurationsdatei? */
    if (!is_scalar($wert) || is_bool($wert)) {
        return mt_t('EINST.SICH_W_TYP');
    }
    $s = (string) $wert;
    if (strlen($s) > 4096) {
        return mt_t('EINST.SICH_W_LANG');
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $s) === 1) {
        return mt_t('EINST.SICH_W_STEUER');
    }

    /* 2. Je Schluessel: die Positivliste des Formulars. */
    $zahlen = mt_zahlgrenzen();
    if (isset($zahlen[$schluessel])) {
        if (preg_match('/^[0-9]+$/', $s) !== 1) {
            return mt_t('EINST.SICH_W_ZAHL');
        }
        if ((int) $s < $zahlen[$schluessel][0] || (int) $s > $zahlen[$schluessel][1]) {
            return sprintf(mt_t('EINST.SICH_W_BEREICH'),
                           $zahlen[$schluessel][0], $zahlen[$schluessel][1]);
        }
        return '';
    }
    if (in_array($schluessel, mt_haken(), true)) {
        return in_array($s, array('0', '1'), true) ? '' : mt_t('EINST.SICH_W_HAKEN');
    }
    $muster = array(
        'server_host'       => '/^[A-Za-z0-9][A-Za-z0-9\.\-:_\[\]]{0,80}$/',
        'container_name'    => '/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,60}$/',
        'container_abbild'  => '#^[A-Za-z0-9][A-Za-z0-9_./\-]{2,120}(:[A-Za-z0-9_.\-]{1,40})?$#',
        /* Das Thema geht roh in die publish-Zeile des Gateways. Ein
         * Zeilenumbruch darin zerlegt das Datagramm - deshalb steht dieselbe
         * enge Positivliste hier wie im Formular. */
        'mqtt_topic'        => '#^[A-Za-z0-9_/\-]{1,64}$#',
        /* mqtt_nur: mt_mqtt_nur_gueltig() weiter unten (Nachtrag). */
        'aktionstoken'      => '/^[A-Za-z0-9_.\-]{0,64}$/',
        'thread_dataset'    => '/^([0-9A-Fa-f]{20,600})?$/',
        /* Rechnername oder IP des Border-Routers, wahlweise mit Port. Leer
         * ist zulaessig: das Feld ist ein Weg zum Dataset, keine Pflicht.
         * Dasselbe Muster steht in mt_thread_dataset_holen() - dort mit den
         * Klammergruppen, die den Port herausloesen. */
        'thread_br'         => '#^(\[[0-9A-Fa-f:]{2,45}\]|[A-Za-z0-9][A-Za-z0-9.\-]{0,80})(:[0-9]{1,5})?$|^$#',
    );
    // 0.9.35 (Nr. 1): primary_interface, lesetoken, loxone_adresse.
    foreach (mt_textmuster() as $tk => $tm) {
        if ($tk !== 'anlern_ip') {
            $muster[$tk] = $tm;
        }
    }
    /* Nr. 19 (B-Nachzug 01.10.2026): ein Praefix nur aus Schraegstrichen
     * ergaebe nach dem Abschneiden ein leeres Praefix, und Dienst wie
     * Oberflaeche naehmen still die Vorgabe "matter" - dieselbe Regel wie im
     * Formular (Reiter MQTT). */
    if ($schluessel === 'mqtt_topic' && trim($s, '/') === '') {
        return mt_t('EINST.SICH_W_FORM');
    }
    if ($schluessel === 'mqtt_nur') {
        return mt_mqtt_nur_gueltig($s) ? '' : mt_t('EINST.SICH_W_FORM');
    }
    if (isset($muster[$schluessel])) {
        return preg_match($muster[$schluessel], $s) === 1 ? '' : mt_t('EINST.SICH_W_FORM');
    }
    /* wlan_ssid und wlan_passwort: alles ausser Steuerzeichen ist zulaessig -
     * ein WLAN-Passwort darf jedes druckbare Zeichen tragen. Die Pruefung am
     * Eingang oben hat das schon erledigt. */
    return '';
}


/* ==================================================================
 * WACHPOSTEN GEGEN FREMDE FORMULARE
 * ==================================================================
 *
 * htmlauth/ schuetzt gegen den UNANGEMELDETEN Aufruf. Es schuetzt nicht
 * dagegen, dass der Browser eines angemeldeten Bedieners ein Formular
 * abschickt, das auf einer fremden Seite steht - die Anmeldung schickt er
 * automatisch mit.
 *
 * Gemessen an Schwesterlinien (Skoda Connect 0.9.12, Midea 4.2.12, beide
 * am 27.08.2026): ein einziger fremder POST genuegte, um das Aktionstoken
 * neu zu wuerfeln. Danach beantwortet der Endpunkt jeden Virtuellen Eingang
 * mit 403 - und ein Virtueller Eingang wertet die Antwort NICHT aus. Der
 * Ausfall bleibt still.
 *
 * Der leere Fall wird eigens abgefangen: hash_equals('', '') ist in PHP
 * TRUE. Wer das Feld nicht vor dem Vergleich auf leer prueft, hat einen
 * Posten gebaut, den jeder passiert, der das Feld leer laesst.
 *
 * Das Merkmal wird aus $_POST und $_GET gelesen, nie aus $_REQUEST:
 * $_REQUEST enthaelt je nach variables_order auch Cookies.
 * ================================================================== */

function mt_merkwort()
{
    static $wort = null;
    if ($wort !== null) {
        return $wort;
    }
    $pfade = mt_paths();
    $verz  = isset($pfade['datadir']) ? $pfade['datadir'] : '';
    if ($verz === '') {
        return '';
    }
    $datei = $verz . '/formmerkwort';
    if (is_readable($datei)) {
        $roh = trim((string) @file_get_contents($datei));
        if (preg_match('/^[0-9a-f]{32,64}$/', $roh)) {
            $wort = $roh;
            return $wort;
        }
    }
    if (function_exists('random_bytes')) {
        $neu = bin2hex(random_bytes(24));
    } else {
        $neu = substr(hash('sha256', uniqid((string) mt_rand(), true) . microtime(true)), 0, 48);
    }
    if (!is_dir($verz)) {
        @mkdir($verz, 0775, true);
    }
    /* Rechte VOR dem Inhalt: zwischen Anlegen und chmod laege sonst ein
     * Fenster, in dem das Merkwort fuer alle lesbar ist. */
    $tmp = $datei . '.tmp';
    if (@file_put_contents($tmp, $neu) !== false) {
        @chmod($tmp, 0600);
        if (@rename($tmp, $datei)) {
            @chmod($datei, 0600);
        } else {
            @unlink($tmp);
        }
    }
    $wort = $neu;
    return $wort;
}

function mt_formtoken()
{
    $grund = mt_merkwort();
    return $grund === '' ? '' : hash_hmac('sha256', 'formular-v1', $grund);
}

/* Das versteckte Feld. Bewusst OHNE den Escape-Helfer des Plugins: der
 * steht bei einigen Linien in index.php und waere von hier aus nicht da.
 * Der Wert ist hexadezimal. */
function mt_fmt()
{
    return '<input data-role="none" type="hidden" name="fmt" value="'
         . htmlspecialchars(mt_formtoken(), ENT_QUOTES, 'UTF-8') . '">';
}

/** Rueckgabe: '' wenn die Anfrage durchgelassen wird, sonst der Grund. */
function mt_wachposten()
{
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        return '';
    }
    $soll = mt_formtoken();
    $ist = isset($_POST['fmt']) ? $_POST['fmt']
         : (isset($_GET['fmt']) ? $_GET['fmt'] : null);
    if (!is_string($ist) || $ist === '' || $soll === '') {
        return mt_t('WACHE.FEHLT');
    }
    if (!hash_equals($soll, $ist)) {
        return mt_t('WACHE.FALSCH');
    }
    return '';
}


/**
 * Welche Themen gehen zurueckbehalten (retained) hinaus?
 *
 * Gelesen wird die Liste des DIENSTES (ZUSTANDSTHEMEN in matter_dienst.py) -
 * er ist die Stelle, die sendet. Eine zweite Liste in PHP waere eine zweite
 * Wahrheit; genau daran lief die Tabelle bis 0.9.22 auseinander, ohne dass
 * es jemandem auffiel. Dasselbe Verfahren benutzt mt_pruef_themen().
 */
function mt_zustandsthemen()
{
    static $z = null;
    if ($z !== null) {
        return $z;
    }
    $z = array();
    $datei = mt_paths()['bindir'] . '/matter_dienst.py';
    $py = is_file($datei) ? (string) @file_get_contents($datei) : '';
    if (preg_match('/ZUSTANDSTHEMEN = \((.*?)\n\)/s', $py, $m)
            && preg_match_all('/"([^"]+)"/', $m[1], $t)) {
        $z = array_fill_keys($t[1], 1);
    }
    return $z;
}

/**
 * Geht dieses Thema retained hinaus? Gibt den fertigen Text zurueck.
 *
 * Das Lebenszeichen ist NIE retained - retained zeigte es immer "lebt" -,
 * ebenso seit 0.9.29 die Erreichbarkeit je Geraet (eine Aussage des
 * Matter-Servers, nicht des Geraets). Diese Namen beantwortet der Dienst in
 * ist_zustand() vorab (NIE_RETAINED), noch vor der Tabelle; hier stehen sie
 * aus demselben Grund vorn.
 */
function mt_nie_retained()
{
    return array('online', 'ok', 'ts', 'zaehler', 'probe', 'geraete', 'erreichbar');
}

function mt_retain_text($thema)
{
    $t = (string) $thema;
    if (in_array($t, mt_nie_retained(), true)) {
        return mt_t('MQTT.RETAIN_NEIN');
    }
    $z = mt_zustandsthemen();
    return isset($z[$t]) ? mt_t('MQTT.RETAIN_JA') : mt_t('MQTT.RETAIN_NEIN');
}


/* ==================================================================
 * Einmalmeldung fuer PRG (O1, Durchgang 30.09.2026; Regeln/04, Docker NG,
 * Raumklima; Bauform Heimkino 1.3.15)
 *
 * Jeder POST endet mit 303 auf index.php?form=<reiter>; was er zu sagen hat,
 * liegt bis zum naechsten GET in data/plugins/<ordner>/einmalmeldung.json
 * (0600, hoechstens 120 s alt). Gelesen wird NUR beim GET, und die Datei
 * wird dabei geloescht, VOR der Anzeige. Zugangsdaten stehen nie darin: die
 * Meldungen sind fertige Saetze, und die Ausgaben von Selbsttest und
 * Containerprotokoll reisen gar nicht mit - sie werden beim GET neu gebildet.
 * ================================================================== */

function mt_einmal_schreiben($daten)
{
    $daten['zeit'] = time();
    return mt_json_schreiben(mt_paths()['einmal'], $daten, 0600);
}

function mt_einmal_lesen()
{
    $datei = mt_paths()['einmal'];
    if (!is_file($datei)) {
        return array();
    }
    $roh = (string) @file_get_contents($datei);
    @unlink($datei);
    $d = json_decode($roh, true);
    if (!is_array($d) || !isset($d['zeit'])) {
        return array();
    }
    $alter = time() - (int) $d['zeit'];
    if ($alter > 120 || $alter < -5) {
        return array();
    }
    return $d;
}


/**
 * M5 (Durchgang 30.09.2026): MQTT-Praefixe merken.
 *
 * Beim Speichern eines neuen Praefixes merkt die Oberflaeche altes UND neues
 * in config/plugins/<ordner>.mqtt_praefixe.json - dieselbe Datei, die der
 * Dienst fuehrt (DATEI_PRAEFIXE). Die Deinstallation raeumt unter jedem
 * gemerkten Praefix ab; bis 0.9.30 nur unter dem eingestellten (gemessen,
 * Bericht mqtt Nr. 5: nach einem Wechsel blieben 126 Themen stehen).
 */
function mt_praefix_merken($liste)
{
    $datei = mt_paths()['praefixe'];
    $d = mt_json_lesen($datei);
    $alt = isset($d['praefixe']) && is_array($d['praefixe']) ? array_values($d['praefixe']) : array();
    $neu = array();
    foreach (array_merge($alt, (array) $liste) as $p) {
        $p = (string) $p;
        if (preg_match('#^[A-Za-z0-9_/\-]{1,64}$#', $p) && !in_array($p, $neu, true)) {
            $neu[] = $p;
        }
    }
    if ($neu === $alt) {
        return true;
    }
    return mt_json_schreiben($datei, array(
        '_hinweis' => 'MQTT-Praefixe, unter denen dieses Plugin je gesendet hat. Die '
                    . 'Deinstallation raeumt unter jedem davon die zurueckbehaltenen '
                    . 'Themen der Linie ab.',
        'praefixe' => $neu), 0600);
}


/**
 * O5 (Durchgang 30.09.2026): die Fabric in eine DATEI packen und den
 * Rueckgabewert von tar pruefen, bevor irgendetwas ausgeliefert wird.
 *
 * Bis 0.9.30 gingen die Kopfzeilen des Downloads vor einem
 * passthru('tar ...') hinaus; eine unlesbare Datei der Fabric ergab ein
 * gueltiges, aber unvollstaendiges Archiv mit HTTP 200 und ohne jede Meldung
 * (gemessen, Bericht oberflaeche Nr. 8). Gepackt wird mit umask 077 in den
 * Datenordner; der Aufrufer liefert aus und loescht.
 *
 * Rueckgabe: array(ok, Pfad oder Meldung, Zahl der Dateien im Archiv)
 */
function mt_fabric_packen($ziel = null)
{
    $p = mt_paths();
    $quelle = mt_fabric_pfad();
    /* 0.9.35 (Nr. 6): $ziel = fester Pfad (automatische Sicherung), sonst wie
     * bisher eine Datei im Datenordner, die der Aufrufer ausliefert. */
    $ordner = $ziel !== null ? dirname($ziel) : $p['datadir'];
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return array(0, sprintf(mt_t('EINST.M_FABRIC_TAR_FEHL'), -1, mt_e($ordner)), 0);
    }
    if ($ziel === null) {
        $ziel = $ordner . '/fabric_export.' . getmypid() . '.tar.gz';
    }
    $fehl = $ziel . '.err';
    $aus = array();
    $rc = 0;
    @exec('umask 077; timeout -k 5 120 tar -czf ' . escapeshellarg($ziel)
          . ' -C ' . escapeshellarg($quelle) . ' . 2>' . escapeshellarg($fehl), $aus, $rc);
    $meld = trim((string) @file_get_contents($fehl));
    @unlink($fehl);
    if ($rc !== 0 || !is_file($ziel)) {
        @unlink($ziel);
        $erste = $meld !== '' ? (string) strtok($meld, "\n") : '';
        return array(0, sprintf(mt_t('EINST.M_FABRIC_TAR_FEHL'), (int) $rc, mt_e($erste)), 0);
    }
    $liste = array();
    $rc2 = 0;
    @exec('tar -tzf ' . escapeshellarg($ziel) . ' 2>/dev/null', $liste, $rc2);
    $n = 0;
    foreach ($liste as $z) {
        if ($z !== '' && substr($z, -1) !== '/') {
            $n++;
        }
    }
    return array(1, $ziel, $n);
}


/* ==================================================================
 * 0.9.35 (Nr. 6 und 12): automatische Fabric-Sicherungen und das
 * Zurueckspielen einer Fabric ueber die Oberflaeche
 *
 * Vor JEDEM Neuanlegen des Containers (Aktualisieren, Einstellungen
 * uebernehmen) legt mt_container_neu_anlegen() eine Sicherung an:
 * data/plugins/<ordner>.fabric_sicherungen/fabric-<Datum>_<Zeit>.tar.gz,
 * Ordner 0700, Dateien 0600, hoechstens drei. NEBEN dem Datenordner, aus
 * demselben Grund wie die Fabric selbst (mt_fabric_pfad()): den Datenordner
 * raeumt jedes Plugin-Update ab.
 *
 * Bis 0.9.34 ging Zurueckspielen bewusst nur von Hand ("ein Endpunkt, der ein
 * beliebiges Archiv in den Datenordner auspackt, waere eine Hintertuer").
 * Jetzt geht es ueber die Oberflaeche - mit den Sicherungen, die diese
 * Hintertuer zumachen: angemeldeter Bereich, Formularmerkmal, Pflichthaken,
 * Liste VOR dem Auspacken geprueft (nur Dateien und Ordner, nur relative
 * Pfade, kein "..", keine Verweise), tar mit --no-same-owner, Container
 * vorher angehalten, die bisherige Fabric bleibt als .vorher-<Zeit> liegen.
 * ================================================================== */

function mt_fabric_sicherungsordner()
{
    return mt_paths()['datadir'] . '.fabric_sicherungen';
}

/** Die automatischen Sicherungen, neueste zuerst: array(name, pfad, groesse, zeit). */
function mt_fabric_sicherungen()
{
    $d = mt_fabric_sicherungsordner();
    $aus = array();
    foreach ((array) @scandir($d) as $f) {
        $f = (string) $f;
        if (preg_match('/^fabric-[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}\.tar\.gz$/', $f) && is_file($d . '/' . $f)) {
            $aus[$f] = array('name' => $f, 'pfad' => $d . '/' . $f,
                             'groesse' => (int) @filesize($d . '/' . $f), 'zeit' => (int) @filemtime($d . '/' . $f));
        }
    }
    krsort($aus);   // der Name traegt Datum und Zeit
    return array_values($aus);
}

/**
 * Eine automatische Sicherung anlegen und auf $behalten Stueck kuerzen.
 * Rueckgabe: array(ok, Pfad ('' = es gab nichts zu sichern) oder Meldung, Dateien).
 */
function mt_fabric_auto_sichern($behalten = 3)
{
    $quelle = mt_fabric_pfad();
    $inhalt = is_dir($quelle) ? @scandir($quelle) : array();
    if (!is_dir($quelle) || (is_array($inhalt) && count($inhalt) <= 2)) {
        return array(1, '', 0);
    }
    if (!mt_tar_da()) {
        return array(0, mt_t('EINST.M_FABRIC_KEIN_TAR'), 0);
    }
    $ordner = mt_fabric_sicherungsordner();
    if (!is_dir($ordner) && !@mkdir($ordner, 0700, true) && !is_dir($ordner)) {
        return array(0, sprintf(mt_t('EINST.FS_ORDNER'), mt_e($ordner)), 0);
    }
    @chmod($ordner, 0700);
    $ziel = $ordner . '/fabric-' . date('Y-m-d_His') . '.tar.gz';
    list($ok, $was, $n) = mt_fabric_packen($ziel);
    if (!$ok) {
        return array(0, $was, 0);
    }
    @chmod($ziel, 0600);
    foreach (array_slice(mt_fabric_sicherungen(), max(1, (int) $behalten)) as $alt) {
        @unlink($alt['pfad']);
    }
    mt_log('Fabric: automatische Sicherung ' . $ziel . ' (' . (int) $n . ' Dateien).');
    return array(1, $ziel, $n);
}

/**
 * Taugt dieses Archiv als Fabric? Die Liste wird VOR dem Auspacken geprueft:
 * nur Dateien und Ordner (keine Verweise, Geraete, Pipes), nur relative
 * Pfade, kein "..", kein Rueckstrich (tar maskiert damit Sonderzeichen),
 * hoechstens 20000 Eintraege und 512 MB. Rueckgabe array(ok, Dateien oder Meldung).
 */
function mt_fabric_archiv_pruefen($archiv)
{
    if (!is_file($archiv)) {
        return array(0, mt_t('EINST.FW_KEINE_DATEI'));
    }
    $namen = array();
    $rc = 0;
    @exec('LC_ALL=C timeout -k 5 60 tar -tzf ' . escapeshellarg($archiv) . ' 2>/dev/null', $namen, $rc);
    if ($rc !== 0 || !$namen) {
        return array(0, mt_t('EINST.FW_KEIN_TAR'));
    }
    $lang = array();
    $rc = 0;
    @exec('LC_ALL=C timeout -k 5 60 tar -tvzf ' . escapeshellarg($archiv) . ' 2>/dev/null', $lang, $rc);
    if ($rc !== 0 || count($lang) !== count($namen)) {
        return array(0, mt_t('EINST.FW_LISTE'));
    }
    if (count($namen) > 20000) {
        return array(0, mt_t('EINST.FW_ZU_VIELE'));
    }
    $summe = 0;
    $dateien = 0;
    foreach ($namen as $i => $n) {
        $n = (string) $n;
        $typ = substr((string) $lang[$i], 0, 1);
        if ($typ !== '-' && $typ !== 'd') {
            return array(0, sprintf(mt_t('EINST.FW_TYP'), mt_e(substr($n, 0, 120))));
        }
        if ($n === '' || $n[0] === '/' || strpos($n, '\\') !== false
            || preg_match('#(^|/)\.\.(/|$)#', $n) === 1) {
            return array(0, sprintf(mt_t('EINST.FW_PFAD'), mt_e(substr($n, 0, 120))));
        }
        if ($typ === '-') {
            $dateien++;
            if (preg_match('/^\S+\s+\S+\s+([0-9]+)\s/', (string) $lang[$i], $m)) {
                $summe += (int) $m[1];
            }
        }
    }
    if ($dateien === 0) {
        return array(0, mt_t('EINST.FW_LEER'));
    }
    if ($summe > 512 * 1024 * 1024) {
        return array(0, mt_t('EINST.FW_ZU_GROSS'));
    }
    return array(1, $dateien);
}

/**
 * Eine Fabric zurueckspielen - synchron, jeder Schritt mit Frist (anhalten
 * hoechstens 40 s je Container, auspacken 300 s, starten 60 s). Nicht,
 * solange ein Hintergrundvorgang laeuft (dieselbe Sperre wie dort).
 *  1. Archiv pruefen (mt_fabric_archiv_pruefen()),
 *  2. jeden laufenden eigenen Container anhalten (eingestellter Name und
 *     mt_container_andere()); laesst sich das nicht klaeren: abbrechen,
 *  3. die Fabric in <fabric>.vorher-<Zeit> umbenennen,
 *  4. auspacken (umask 077, --no-same-owner); scheitert das, kommt die
 *     bisherige Fabric zurueck,
 *  5. die angehaltenen Container wieder starten.
 * Rueckgabe array(ok, html).
 */
function mt_fabric_wiederherstellen($archiv)
{
    $p = mt_paths();
    if ($p['home'] === '') {
        return array(0, mt_t('EINST.ARCHIV_VERWEIGERT'));
    }
    if (!mt_tar_da()) {
        return array(0, mt_t('EINST.M_FABRIC_KEIN_TAR'));
    }
    $ziel = mt_fabric_pfad();
    if (substr($ziel, -7) !== '.matter') {
        return array(0, mt_t('EINST.FW_ZIEL'));
    }
    $sperre = @fopen($p['datadir'] . '/container_vorgang.lock', 'c');
    if ($sperre === false) {
        $sperre = @fopen($p['datadir'] . '/container_vorgang.lock', 'r');
    }
    if ($sperre === false || !flock($sperre, LOCK_EX | LOCK_NB) || mt_ct_vorgang_aktiv() !== null) {
        if ($sperre !== false) {
            fclose($sperre);
        }
        return array(0, mt_t('EINST.V_LAEUFT_SCHON'));
    }
    $erg = mt_fabric_wiederherstellen_gesperrt($archiv, $ziel);
    flock($sperre, LOCK_UN);
    fclose($sperre);
    return $erg;
}

function mt_fabric_wiederherstellen_gesperrt($archiv, $ziel)
{
    list($ok, $n) = mt_fabric_archiv_pruefen($archiv);
    if (!$ok) {
        return array(0, $n);
    }
    $cfg = mt_config();
    mt_erreichbar_vergessen();
    // 2. laufende eigene Container anhalten
    $angehalten = array();
    if (mt_docker_da()) {
        list($lage, $lsatz) = mt_docker_lage(10);
        if ($lage !== 'ok') {
            return array(0, sprintf(mt_t('EINST.FW_DOCKER'), mt_e($lsatz)));
        }
        $kandidaten = array();
        list($eigen, $grund) = mt_container_eigen($cfg);
        if ($eigen === -1) {
            return array(0, sprintf(mt_t('EINST.FW_UNKLAR'), mt_e($grund)));
        }
        if ($eigen === 1) {
            $kandidaten[] = mt_container_name($cfg);
        }
        foreach (mt_container_andere($cfg) as $a) {
            $kandidaten[] = $a['name'];
        }
        foreach ($kandidaten as $k) {
            if (mt_container_zustand($k) !== 'laeuft') {
                continue;
            }
            list($sok, $saus) = mt_docker('stop -t 20 ' . escapeshellarg($k), 40);
            if (!$sok) {
                $r = mt_fabric_starten($angehalten);
                return array(0, sprintf(mt_t('EINST.FW_STOP_FEHL'), mt_e($k))
                                . ' <span class="sm-mono">' . mt_e(substr($saus, 0, 200)) . '</span> ' . $r);
            }
            $angehalten[] = $k;
        }
    }
    // 3. umbenennen
    $vorher = '';
    if (file_exists($ziel)) {
        $vorher = $ziel . '.vorher-' . date('YmdHis');
        if (!@rename($ziel, $vorher)) {
            $r = mt_fabric_starten($angehalten);
            return array(0, sprintf(mt_t('EINST.FW_RENAME_FEHL'), mt_e($ziel)) . ' ' . $r);
        }
    }
    // 4. auspacken
    $fehl = dirname($ziel) . '/.fabric_auspacken.' . getmypid() . '.err';
    $rc = 0;
    $aus = array();
    if (!@mkdir($ziel, 0700, true)) {
        $rc = -1;
    } else {
        @exec('umask 077; LC_ALL=C timeout -k 5 300 tar -xzf ' . escapeshellarg($archiv) . ' -C ' . escapeshellarg($ziel)
              . ' --no-same-owner --no-overwrite-dir 2>' . escapeshellarg($fehl), $aus, $rc);
    }
    $meld = trim((string) @file_get_contents($fehl));
    @unlink($fehl);
    if ($rc !== 0) {
        if (is_dir($ziel)) {
            @exec('rm -rf -- ' . escapeshellarg($ziel));
        }
        $zurueck = $vorher !== '' && @rename($vorher, $ziel);
        $r = mt_fabric_starten($angehalten);
        mt_log('Fabric: Zurueckspielen gescheitert (tar ' . (int) $rc . ').');
        return array(0, sprintf(mt_t('EINST.FW_TAR_FEHL'), (int) $rc, mt_e((string) strtok($meld . "\n", "\n")))
                        . ' ' . ($vorher === '' ? '' : mt_t($zurueck ? 'EINST.FW_ALT_ZURUECK' : 'EINST.FW_ALT_NICHT_ZURUECK'))
                        . ' ' . $r);
    }
    // 5. starten
    $r = mt_fabric_starten($angehalten);
    mt_log('Fabric: zurueckgespielt (' . (int) $n . ' Dateien), bisherige Fabric: ' . ($vorher !== '' ? $vorher : '-') . '.');
    return array(1, sprintf(mt_t('EINST.FW_OK'), (int) $n)
                    . ($vorher !== '' ? ' ' . sprintf(mt_t('EINST.FW_VORHER'), mt_e($vorher)) : '') . ' ' . $r);
}

/** Die angehaltenen Container wieder starten; Rueckgabe ein Satz (HTML). */
function mt_fabric_starten(array $namen)
{
    if (!$namen) {
        return '';
    }
    $gut = array();
    $schlecht = array();
    foreach ($namen as $k) {
        list($ok, ) = mt_docker('start ' . escapeshellarg($k), 60);
        if ($ok) {
            $gut[] = $k;
        } else {
            $schlecht[] = $k;
        }
    }
    $satz = $gut ? sprintf(mt_t('EINST.FW_GESTARTET'), mt_e(implode(', ', $gut))) : '';
    if ($schlecht) {
        $satz .= ' ' . sprintf(mt_t('EINST.FW_START_FEHL'), mt_e(implode(', ', $schlecht)));
    }
    return trim($satz);
}


/**
 * Die festen Themen der Tabelle im Reiter MQTT - EINE Liste fuer die
 * Anzeige und fuer die Pruefzeile "Nennt die Themenliste, was der Dienst
 * wirklich sendet?" (M6). Wert: array(Ebene, Sprachschluessel).
 */
function mt_themen_fest()
{
    return array(
        'ok'         => array('', 'MQTT.B_OK'),
        'online'     => array('', 'MQTT.B_ONLINE'),
        'ts'         => array('', 'MQTT.B_TS'),
        'geraete'    => array('', 'MQTT.B_GERAETE'),
        'name'       => array('geraetN/', 'MQTT.B_NAME'),
        'knoten'     => array('geraetN/', 'MQTT.B_KNOTEN'),
        'erreichbar' => array('geraetN/', 'MQTT.B_ERREICH'),
    );
}

/**
 * Welche Themenstaemme bildet der Dienst? (M6, Durchgang 30.09.2026)
 *
 * Gefragt wird der Dienst selbst: matter_dienst.py --themen fuehrt einen
 * Knoten mit jedem Attribut der Tabelle durch dieselben Funktionen wie im
 * Betrieb und gibt die Staemme als JSON aus. Schreibt nichts.
 * Rueckgabe: array(Liste oder null, Grund, wenn null)
 */
function mt_themen_dienst()
{
    $p = mt_paths();
    $py = $p['bindir'] . '/venv/bin/python3';
    $skript = $p['bindir'] . '/matter_dienst.py';
    if (!is_file($py) || !is_file($skript)) {
        return array(null, $py);
    }
    $aus = array();
    $rc = 0;
    @exec('env PYTHONDONTWRITEBYTECODE=1 timeout -k 5 20 ' . escapeshellarg($py) . ' '
          . escapeshellarg($skript) . ' --themen 2>&1', $aus, $rc);
    $d = json_decode(implode("\n", $aus), true);
    if ($rc !== 0 || !is_array($d) || !isset($d['themen']) || !is_array($d['themen'])) {
        return array(null, 'rc ' . (int) $rc . ': ' . substr((string) (isset($aus[0]) ? $aus[0] : ''), 0, 120));
    }
    $liste = array();
    foreach ($d['themen'] as $t) {
        if (is_string($t)) {
            $liste[] = $t;
        }
    }
    return array($liste, '');
}
