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
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

function mt_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) {
        foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
            if (is_dir($k)) {
                $home = $k;
                break;
            }
        }
    }
    // Der Pluginordner ergibt sich aus dem Ablageort dieser Datei. Der
    // MD5-Schluessel aus der plugindatabase.json wird bewusst NICHT benutzt -
    // er wird aus Autorenname, E-Mail und Plugin-Name gebildet und aendert
    // sich bei jedem Fork.
    $dir = basename(dirname(__FILE__));
    if ($home && !is_dir($home . '/config/plugins/' . $dir)) {
        foreach (array(getenv('LBPPLUGINDIR'), 'matter2lox') as $kand) {
            if ($kand && is_dir($home . '/config/plugins/' . $kand)) {
                $dir = $kand;
                break;
            }
        }
    }
    if ($home) {
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
            'tabelle'   => $home . '/templates/plugins/' . $dir . '/matter_cluster.json',
        );
    } else {
        $basis = dirname(dirname(__DIR__));
        $p = array(
            'home' => '', 'plugin' => $dir,
            'configdir' => $basis . '/config',
            'config'    => $basis . '/config/matter2lox.json',
            'sicherung' => $basis . '/config/matter2lox.backup.json',
            'datadir'   => $basis . '/data',
            'fabric'    => $basis . '/data.matter',
            'nummern'   => $basis . '/data.nummern.json',
            'bindir'    => $basis . '/bin',
            'logdir'    => $basis . '/log',
            'log'       => $basis . '/log/matter2lox.log',
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
    static $v = null;
    if ($v !== null) {
        return $v;
    }
    $v = '';
    $p = mt_paths();
    foreach (array($p['home'] . '/data/system/install/' . $p['plugin'] . '/plugin.cfg',
                   dirname(dirname(__DIR__)) . '/plugin.cfg') as $kand) {
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
        'container_name'    => 'matter-server',
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

/* ---------------- Dienst ---------------- */

function mt_dienst_pid()
{
    $f = mt_paths()['datadir'] . '/dienst.pid';
    if (!is_file($f)) {
        return 0;
    }
    $pid = (int) trim((string) @file_get_contents($f));
    if ($pid <= 0 || !is_dir('/proc/' . $pid)) {
        return 0;
    }
    $cmd = (string) @file_get_contents('/proc/' . $pid . '/cmdline');
    return strpos($cmd, 'matter_dienst.py') !== false ? $pid : 0;
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
    // Wer den Dienst anfasst, veraendert die Lage - die zwischengespeicherte
    // Antwort auf "nimmt jemand Verbindungen an?" gilt danach nicht mehr.
    mt_erreichbar_vergessen();
    $skript = mt_paths()['bindir'] . '/dienst.sh';
    if (!is_file($skript)) {
        return array(0, 'dienst.sh nicht gefunden: ' . $skript);
    }
    $ausgabe = array();
    $code = 0;
    @exec(escapeshellcmd($skript) . ' ' . escapeshellarg($befehl) . ' 2>&1', $ausgabe, $code);
    return array($code === 0 ? 1 : 0, implode("\n", $ausgabe));
}

/* ---------------- Befehlswarteschlange ----------------
 *
 * Sowohl der Miniserver-Endpunkt als auch der Reiter Test setzen Befehle ueber
 * diese eine Funktion ab. Zwei Kopien derselben Logik laufen zwangslaeufig
 * auseinander.
 *
 * Rueckgabe: array(ok, Meldung). ok = 1 erledigt, 0 abgelehnt,
 * 2 eingereiht, aber ohne Antwort in der Wartezeit - Ergebnis unbekannt.
 * Es wird nie ein Erfolg gemeldet, den niemand geprueft hat.
 */
function mt_befehl_absetzen($befehl, $wartezeit = null)
{
    $p = mt_paths();
    $cfg = mt_config();
    if ($wartezeit === null) {
        $wartezeit = (int) $cfg['wartezeit'];
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
        return array(0, mt_t('TEST.A_DIENST_GESTOPPT'));
    }

    $ordner = $p['datadir'] . '/befehle';
    if (!is_dir($ordner) && !@mkdir($ordner, 0700, true) && !is_dir($ordner)) {
        return array(0, 'Der Ordner fuer die Warteschlange liess sich nicht anlegen: ' . $ordner);
    }
    @chmod($ordner, 0700);
    $kennung = bin2hex(random_bytes(8));
    $datei = $ordner . '/' . $kennung . '.json';
    /* Ein Befehl kann WLAN-Passwort, Thread-Dataset oder den Anlerncode
     * tragen. Deshalb 0600, und die Rechte VOR dem Inhalt - genau wie bei der
     * Konfiguration. Bis 0.9.16 stand hier gar kein chmod. Der Zeitstempel
     * kommt mit, damit der Dienst einen alten Befehl verwerfen kann. */
    $befehl['ts'] = time();
    if (!mt_json_schreiben($datei, $befehl, 0600)) {
        return array(0, 'Der Befehl liess sich nicht ablegen: ' . $datei);
    }
    $antwort = $p['datadir'] . '/antworten/' . $kennung . '.json';
    for ($i = 0; $i < $wartezeit * 10; $i++) {
        if (is_file($antwort)) {
            $a = mt_json_lesen($antwort);
            return array((int) (isset($a['ok']) ? $a['ok'] : 0),
                         (string) (isset($a['meldung']) ? $a['meldung'] : ''));
        }
        usleep(100000);
    }
    /* Nichts gehoert zu haben heisst nicht, dass nichts geschieht - aber die
     * unbearbeitete Datei bleibt nicht liegen. */
    if (is_file($datei)) {
        @unlink($datei);
    }
    return array(2, sprintf(mt_t('EINST.M_BEFEHL_STUMM'), $wartezeit));
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
        if (@fwrite($s, $msg) !== false) {
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
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
                if (is_dir($k)) {
                    $home = $k;
                    break;
                }
            }
        }
        $ordner = basename(dirname(__FILE__));
        $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
        if (!is_dir($pfad)) {
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

/** Rueckgabe: array(ok, Ausgabe) */
function mt_docker($argumente)
{
    if (!mt_docker_da()) {
        return array(0, 'Docker ist auf diesem LoxBerry nicht installiert.');
    }
    $ausgabe = array();
    $code = 0;
    @exec('docker ' . $argumente . ' 2>&1', $ausgabe, $code);
    return array($code === 0 ? 1 : 0, implode("\n", $ausgabe));
}

/** 'laeuft', 'gestoppt', 'fehlt' oder 'kein_docker' */
function mt_container_zustand()
{
    $cfg = mt_config();
    $name = mt_container_name($cfg);
    if (!mt_docker_da()) {
        return 'kein_docker';
    }
    list($ok, $aus) = mt_docker('inspect -f {{.State.Running}} ' . escapeshellarg($name));
    if (!$ok) {
        return 'fehlt';
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
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,60}$/', $n) ? $n : 'matter-server';
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

/** Die vollstaendige Aufrufzeile - auch fuer die Anzeige in der Oberflaeche. */
function mt_container_befehl($cfg = null)
{
    if ($cfg === null) {
        $cfg = mt_config();
    }
    $p = mt_paths();
    $name = mt_container_name($cfg);
    $abbild = mt_container_abbild($cfg);
    $bt = (int) $cfg['bluetooth_adapter'];
    $bt = ($bt >= 0 && $bt <= 9) ? $bt : 0;
    $daten = $p['fabric'];

    $zeile = 'run -d'
        . ' --name ' . escapeshellarg($name)
        . ' --restart=unless-stopped'
        . ' --security-opt apparmor=unconfined'
        . ' --network=host'
        . ' -v ' . escapeshellarg($daten . ':/data');
    if (is_dir('/run/dbus')) {
        $zeile .= ' -v /run/dbus:/run/dbus:ro';
    }
    $zeile .= ' ' . escapeshellarg($abbild)
        . ' --storage-path /data --paa-root-cert-dir /data/credentials';
    if (is_dir('/run/dbus')) {
        // Die Vorgabe-Befehlszeile des Abbilds muss vollstaendig wiederholt
        // werden, sobald man etwas anhaengt - so steht es in der Anleitung.
        $zeile .= ' --bluetooth-adapter ' . $bt;
    }
    return $zeile;
}

/** $was ist 'anlegen', 'start', 'stop', 'restart', 'entfernen' oder 'holen'. */
function mt_container($was)
{
    $cfg = mt_config();
    $name = mt_container_name($cfg);
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
                return array(0, 'Es gibt bereits einen Container mit dem Namen ' . $name
                              . '. Erst entfernen oder einen anderen Namen waehlen.');
            }
            return mt_docker(mt_container_befehl($cfg));
        case 'start':
            return mt_docker('start ' . escapeshellarg($name));
        case 'stop':
            return mt_docker('stop ' . escapeshellarg($name));
        case 'restart':
            return mt_docker('restart ' . escapeshellarg($name));
        case 'entfernen':
            // Nur der Container, NIE der Datenordner: darin liegt die Fabric.
            return mt_docker('rm -f ' . escapeshellarg($name));
    }
    return array(0, 'Unbekannter Containerbefehl.');
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
 * Kennung, mit der er angelegt wurde. Erst Entfernen und Neuanlegen bringt den
 * neuen Stand. Der Datenordner - und damit die Fabric - bleibt dabei
 * unberuehrt; er haengt am Ablageort, nicht am Container.
 *
 * Gemeldet wird die WIRKUNG: die Kennung vorher und nachher. Hat sich nichts
 * geaendert, steht das ausdruecklich da, statt einen Erfolg zu behaupten.
 *
 * Rueckgabe: array(ok, Meldung)
 */
function mt_container_aktualisieren()
{
    $cfg = mt_config();
    if (!mt_docker_da()) {
        return array(0, mt_t('EINST.M_AKT_KEIN_DOCKER'));
    }
    $vorher = mt_container_fassung($cfg);
    if ($vorher['container'] === '') {
        return array(0, mt_t('EINST.M_AKT_KEIN_CONTAINER'));
    }
    list($ok, $aus) = mt_container('holen');
    if (!$ok) {
        return array(0, sprintf(mt_t('EINST.M_AKT_PULL_FEHL'), mt_e(substr($aus, 0, 300))));
    }
    $gezogen = mt_container_fassung($cfg);
    if ($gezogen['abbild'] !== '' && $gezogen['abbild'] === $vorher['container']) {
        // Nichts Neues. Den Container dafuer anzuhalten waere eine
        // Betriebsunterbrechung ohne jeden Gegenwert.
        return array(1, sprintf(mt_t('EINST.M_AKT_UNVERAENDERT'),
                                mt_e(substr($vorher['container'], 0, 19)),
                                $gezogen['marke'] !== '' ? mt_e($gezogen['marke']) : '?'));
    }
    list($ok, $aus) = mt_container('entfernen');
    if (!$ok) {
        return array(0, sprintf(mt_t('EINST.M_AKT_RM_FEHL'), mt_e(substr($aus, 0, 300))));
    }
    list($ok, $aus) = mt_container('anlegen');
    if (!$ok) {
        return array(0, sprintf(mt_t('EINST.M_AKT_RUN_FEHL'), mt_e(substr($aus, 0, 300))));
    }
    $nachher = mt_container_fassung($cfg);
    return array(1, sprintf(mt_t('EINST.M_AKT_OK'),
                            mt_e(substr($vorher['container'], 0, 19)),
                            mt_e(substr($nachher['container'], 0, 19)),
                            $nachher['marke'] !== '' ? mt_e($nachher['marke']) : '?'));
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
        curl_close($ch);
    } else {
        $ctx = stream_context_create(array('http' => array(
            'method' => 'GET', 'timeout' => 10, 'ignore_errors' => true,
            'header' => implode("\r\n", $kopf))));
        $body = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0])
                && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
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
        curl_close($ch);
    } else {
        $ctx = stream_context_create(array('http' => array(
            'method' => 'GET', 'timeout' => 10, 'ignore_errors' => true,
            'max_redirects' => 0, 'header' => implode("\r\n", $kopf))));
        $body = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0])
                && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
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
    @exec(escapeshellcmd($py) . ' ' . escapeshellarg($skript) . ' --selbsttest 2>&1', $ausgabe);
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

/** Adresse des eigenen Status-Endpunkts. */
function mt_endpunkt_adresse($aktion, $nummer = null)
{
    $p = mt_paths();
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    return 'http://' . $host . '/plugins/' . $p['plugin']
         . '/index.php?token=' . mt_token() . '&aktion=' . $aktion
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
 */
function mt_vorlage_out($nummer = 1)
{
    $geraete = mt_geraete();
    $g = isset($geraete[(string) $nummer]) ? $geraete[(string) $nummer] : null;
    $themen = array();
    if ($g !== null && !empty($g['endpunkte'])) {
        foreach ($g['endpunkte'] as $ep => $felder) {
            foreach ($felder as $thema => $wert) {
                $themen[$thema] = (string) $ep;
            }
        }
    }
    $basis = mt_endpunkt_adresse('', $nummer);
    // Die Adresse des VirtualOut traegt nur Rechner und Pfad; der Rest steht
    // je Befehl. Deshalb wird sie hier wieder zerlegt.
    $teile = explode('/index.php?', $basis, 2);
    $adresse = $teile[0];
    $frage = '/index.php?token=' . mt_token() . '&geraet=' . (int) $nummer . '&aktion=';

    $cmds = array();
    // Analogbefehl: EINE Adresse mit dem Wertplatzhalter <v.0>.
    $wert = function ($thema, $titel, $aktion) use (&$cmds, $themen, $frage, $nummer) {
        if (!isset($themen[$thema])) { return; }
        $cmds[] = array(
            'title'   => 'MATTER_' . (int) $nummer . '_' . strtoupper($aktion),
            'comment' => trim(strip_tags(html_entity_decode(mt_t($titel), ENT_QUOTES, 'UTF-8'))),
            'analog'  => true,
            'on'      => $frage . $aktion . '&endpunkt=' . $themen[$thema] . '&wert=<v.0>',
        );
    };
    // Schaltbefehl: zwei Adressen. Die Aktionen heissen 'ein' und 'aus' -
    // genau so stehen sie in der Weissliste von webfrontend/html/index.php.
    // Ein erfundenes 'schalten&wert=1' wuerde dort mit UNBEKANNTE_AKTION
    // abgewiesen, und in Loxone sieht man davon nichts.
    $schalter = function ($thema, $titel) use (&$cmds, $themen, $frage, $nummer) {
        if (!isset($themen[$thema])) { return; }
        $ep = $themen[$thema];
        $cmds[] = array(
            'title'   => 'MATTER_' . (int) $nummer . '_EIN_AUS',
            'comment' => trim(strip_tags(html_entity_decode(mt_t($titel), ENT_QUOTES, 'UTF-8'))),
            'analog'  => false,
            'on'      => $frage . 'ein&endpunkt=' . $ep,
            'off'     => $frage . 'aus&endpunkt=' . $ep,
        );
    };
    $schalter('schalter',             'LOX.A_SCHALTEN');
    $wert('helligkeit',               'LOX.A_HELLIGKEIT',     'helligkeit');
    $wert('farbtemperatur_mired',     'LOX.A_FARBTEMPERATUR', 'farbtemperatur');
    // Farbton und Saettigung als zwei getrennte Analogbefehle: ein
    // VirtualOutCmd traegt genau EINEN Wertplatzhalter. Genau so machen es die
    // Ausfuhren dieser Anlage bei Helligkeit und Kelvin auch.
    $wert('farbton_roh',              'LOX.A_FARBTON',        'farbton');
    $wert('saettigung',               'LOX.A_SAETTIGUNG',     'saettigung');
    $wert('position',                 'LOX.A_ROLLO',          'rollo');
    $wert('soll_heizen',              'LOX.A_SOLL_HEIZEN',    'soll_heizen');
    $wert('soll_kuehlen',             'LOX.A_SOLL_KUEHLEN',   'soll_kuehlen');
    $wert('luefter_soll',             'LOX.A_LUEFTER',        'luefter');

    return array(
        'VQ_matter_geraet' . (int) $nummer . '.xml',
        mt_xml_virtual_out(array(
            'title'   => 'Matter ' . (int) $nummer . ($g !== null ? ' ' . $g['name'] : '') . ' Befehle',
            'address' => $adresse,
            'comment' => 'Schreibende Befehle muessen im Reiter Einstellungen freigegeben '
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
    $fehlend = array_diff($bekannt, array_keys($daten));
    if ($fehlend) {
        $mangel[] = sprintf(mt_t('EINST.SICH_FEHLT'),
                             htmlspecialchars(implode(', ', $fehlend), ENT_QUOTES, 'UTF-8'));
    }
    if ($anzahl === 0) {
        $mangel[] = mt_t('EINST.SICH_LEER');
    }
    return array($mangel ? null : $neu, $mangel, $anzahl);
}

/**
 * Taugt dieser Wert fuer diese Einstellung?
 *
 * Rueckgabe: '' wenn er taugt, sonst der Grund im Klartext. Die Muster sind
 * dieselben wie im Speichern-Handler der Oberflaeche - eine Einstellung, die
 * ueber das Formular abgewiesen wuerde, darf ueber die Sicherungsdatei nicht
 * hereinkommen.
 */
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
    $zahlen = array('server_port' => array(1, 65535), 'wartezeit' => array(0, 200),
                    'bluetooth_adapter' => array(0, 9), 'sendetakt' => array(0, 60),
                    'herzschlag' => array(0, 3600));
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
    $haken = array('eigener_container', 'mqtt_ein', 'roh_ein', 'steuerung_ein', 'schloss_ein');
    if (in_array($schluessel, $haken, true)) {
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
        'mqtt_nur'          => '/^([0-9]{1,3}([ ,;]+[0-9]{1,3})*)?$/',
        'aktionstoken'      => '/^[A-Za-z0-9_.\-]{0,64}$/',
        'thread_dataset'    => '/^([0-9A-Fa-f]{20,600})?$/',
        /* Rechnername oder IP des Border-Routers, wahlweise mit Port. Leer
         * ist zulaessig: das Feld ist ein Weg zum Dataset, keine Pflicht.
         * Dasselbe Muster steht in mt_thread_dataset_holen() - dort mit den
         * Klammergruppen, die den Port herausloesen. */
        'thread_br'         => '#^(\[[0-9A-Fa-f:]{2,45}\]|[A-Za-z0-9][A-Za-z0-9.\-]{0,80})(:[0-9]{1,5})?$|^$#',
    );
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
