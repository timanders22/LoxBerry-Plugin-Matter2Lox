<?php
/**
 * Matter to Loxone - Matter-Bridge (Beta), 0.9.42
 *
 * Die Gegenrichtung zum Matter-Server: ausgewaehlte Loxone-Bausteine
 * erscheinen in Alexa, Google Home und Apple Home. Grundlage sind
 * matterbridge (Luligu, Apache-2.0) und das Plugin matterbridge-loxone
 * (andrasg, MIT), beide in EINEM eigenen Container. Entwurf und Belege:
 * Pruefung-Matter2Lox-0.9.42/entwurf_bridge.md (15.10.2026); die 15
 * Entscheidungen in Abschnitt 7 gelten wie empfohlen.
 *
 * Beta - nicht am Geraet erprobt. Ab Werk AUS (Regeln/04: ein Haken, der
 * etwas Ungemessenes einschaltet, steht ab Werk aus).
 *
 * Grundregeln dieser Datei:
 *  - Eigenes Label de.loxberry.plugin.bridge=<ordner>, NIE ...folder=
 *    (Befund B1: mit dem Label des Matter-Servers haelte "Einstellungen
 *    uebernehmen" die Bridge fuer den abzuloesenden Server). Kein bestehender
 *    Codepfad sieht das neue Label; die Eigentumspruefung steht in
 *    bin/bridge_eigen.sh und gespiegelt in uninstall/uninstall.
 *  - Feste Abbildfassung (Entscheidung 2), matterbridge-loxone 2.1.1 ueber
 *    npm ci mit der mitgelieferten Sperrdatei bin/bridge_npm/ (Entscheidung 1).
 *  - Kennungen nur DEZIMAL: matterbridge liest --vendorId mit
 *    Number.parseInt(wert, 10); "0xFFF1" ergaebe 0. 65521 = 0xFFF1,
 *    32768 = 0x8000 (Google-Projekt des Hausherrn).
 *  - Kein Geheimnis auf einer Kommandozeile, in mt_log, in der
 *    Einmalmeldung oder in der Oberflaeche. Loxone-Kennwort und
 *    Frontend-Kennwort stehen nur in bridge_zugang.json (0600, mit
 *    Zweitschrift) und in der abgeleiteten Plugin-Konfiguration (0600).
 *  - Kopplungscodes der Bridge gelten DAUERHAFT. Sie kommen nur in den
 *    Seitenaufbau (GET), nie ins Protokoll; jede Ausgabe des Containers
 *    laeuft durch mt_br_log_filter().
 *  - Waechter gegen die Anmeldesperre des Miniservers in drei Stufen:
 *    Testanmeldung vor jedem Start, Beobachtung der ersten 120 s (Stopp beim
 *    2. Anmeldefehler), minuetlich (Stopp beim 3. seit dem letzten Erfolg).
 *    Netzfehler zaehlen nie. Danach Sperrmerker, kein Takt startet neu.
 */

/* ---------------- feste Werte (Entscheidungen 2, 6, 9) ---------------- */

/** Entscheidung 2: feste Fassung, neue nur nach einer Messung. */
function mt_br_abbild()
{
    return 'luligu/matterbridge:3.10.13';
}

/** Entscheidung 1: die Fassung in bin/bridge_npm/package-lock.json. */
function mt_br_plugin_fassung()
{
    return '2.1.1';
}

function mt_br_matter_port()
{
    return 5550;
}

/** Frontend der Bridge, nur 127.0.0.1 (Entscheidung 5). */
function mt_br_frontend_port()
{
    return 8283;
}

/** 0xFFF1 = 65521, die Test-Hersteller-ID; dezimal Pflicht. */
function mt_br_vid()
{
    return 65521;
}

/** 0x8000 = 32768. */
function mt_br_pid()
{
    return 32768;
}

/** Waechter-Schwellen (Entscheidung 7). */
function mt_br_schwelle_start()
{
    return 2;
}

function mt_br_schwelle_betrieb()
{
    return 3;
}

/** Dauer der Beobachtung nach jedem Start in Sekunden (Stufe 2). Nur der
 *  Pruefstand darf sie ueber MT_PRUEFSTAND_BEOBACHTEN kuerzen (3 bis 120). */
function mt_br_beobachten_frist()
{
    $p = getenv('MT_PRUEFSTAND_BEOBACHTEN');
    if (is_string($p) && preg_match('/^[0-9]{1,3}$/', $p) && (int) $p >= 3 && (int) $p <= 120) {
        return (int) $p;
    }
    return 120;
}

/** Laenge des Kopplungsfensters fuer weitere Systeme (Sekunden). */
function mt_br_fenster_dauer()
{
    return 600;
}

function mt_br_label_schluessel()
{
    return 'de.loxberry.plugin.bridge';
}

function mt_br_label()
{
    return mt_br_label_schluessel() . '=' . mt_paths()['plugin'];
}

/** Der Containername: <ordner>-bridge (eine Zweitinstallation matter2lox_01
 *  bekommt matter2lox_01-bridge). */
function mt_br_name()
{
    $n = mt_paths()['plugin'] . '-bridge';
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,60}$/', $n) ? $n : 'matter2lox-bridge';
}

/** Alle Ablageorte der Bridge an EINER Stelle. Was ein Update ueberstehen
 *  muss, liegt NEBEN dem Daten- bzw. Konfigordner (Regeln/06:334). */
function mt_br_pfade()
{
    $p = mt_paths();
    $d = $p['datadir'];
    $bk = substr($p['sicherung'], 0, -5);
    return array(
        'ordner'      => $d . '.bridge',
        'sicherungen' => $d . '.bridge_sicherungen',
        'gesperrt'    => $d . '.bridge_gesperrt.json',
        'anmeldung'   => $d . '.bridge_anmeldung.json',
        'waechter'    => $d . '.bridge_waechter.json',
        'struktur'    => $d . '.bridge_struktur.json',
        'fenster'     => $d . '/bridge_fenster.json',
        'cfg'         => $p['configdir'] . '/bridge.json',
        'zugang'      => $p['configdir'] . '/bridge_zugang.json',
        'cfg_bk'      => $bk . '.bridge.json',
        'zugang_bk'   => $bk . '.bridge_zugang.json',
        'npm'         => $p['bindir'] . '/bridge_npm',
        'muster'      => $p['bindir'] . '/bridge_muster.json',
        'api'         => $p['bindir'] . '/bridge_api.py',
        'eigen'       => $p['bindir'] . '/bridge_eigen.sh',
    );
}

/* ---------------- Konfiguration und Zugang ---------------- */

function mt_br_vorgaben()
{
    /* namen_setzen: Entscheidung 6 - --vendorName/--productName nur, wenn
     * die Bridge beim ersten Anlegen keine Knotenablage vorfand (null = noch
     * nicht entschieden). */
    return array('bridge_ein' => 0, 'auswahl' => array(), 'namen_setzen' => null);
}

function mt_br_cfg()
{
    $f = mt_br_pfade();
    $d = mt_json_lesen($f['cfg']);
    if (!$d && is_file($f['cfg_bk'])) {
        $d = mt_json_lesen($f['cfg_bk']);
    }
    $c = array_merge(mt_br_vorgaben(), $d);
    $c['bridge_ein'] = ((string) $c['bridge_ein'] === '1') ? 1 : 0;
    $aw = array();
    foreach ((is_array($c['auswahl']) ? $c['auswahl'] : array()) as $k => $t) {
        if (is_string($k) && is_string($t) && mt_br_schluessel_gueltig($k) && preg_match('/^[a-z]{2,20}$/', $t)) {
            $aw[$k] = $t;
        }
    }
    $c['auswahl'] = $aw;
    $c['namen_setzen'] = ($c['namen_setzen'] === null) ? null : ((string) $c['namen_setzen'] === '1' ? 1 : 0);
    return $c;
}

/** Schreiben mit Zweitschrift neben dem Konfigordner (beide 0600). */
function mt_br_cfg_speichern($c)
{
    $f = mt_br_pfade();
    $c = array_intersect_key($c, mt_br_vorgaben());
    if (!mt_json_schreiben($f['cfg'], $c, 0600)) {
        return false;
    }
    @copy($f['cfg'], $f['cfg_bk']);
    @chmod($f['cfg_bk'], 0600);
    return true;
}

/** Benutzer, Kennwort und das Frontend-Kennwort. Rueckgabe ohne Ausgabe. */
function mt_br_zugang()
{
    $f = mt_br_pfade();
    $d = mt_json_lesen($f['zugang']);
    if (!$d && is_file($f['zugang_bk'])) {
        $d = mt_json_lesen($f['zugang_bk']);
    }
    $z = array('benutzer' => '', 'kennwort' => '', 'frontend_kennwort' => '', 'geaendert' => 0);
    foreach ($z as $k => $v) {
        if (isset($d[$k]) && is_scalar($d[$k])) {
            $z[$k] = is_int($v) ? (int) $d[$k] : (string) $d[$k];
        }
    }
    return $z;
}

function mt_br_zugang_speichern($z)
{
    $f = mt_br_pfade();
    $z = array(
        'benutzer' => (string) $z['benutzer'], 'kennwort' => (string) $z['kennwort'],
        'frontend_kennwort' => (string) $z['frontend_kennwort'], 'geaendert' => (int) $z['geaendert'],
    );
    if (!mt_json_schreiben($f['zugang'], $z, 0600)) {
        return false;
    }
    @copy($f['zugang'], $f['zugang_bk']);
    @chmod($f['zugang_bk'], 0600);
    return true;
}

/** Das Frontend-Kennwort: einmal erzeugt (32 Zeichen, random_bytes) und nie
 *  wieder (Regeln/05:149). Rueckgabe '' nur, wenn nicht zu speichern. */
function mt_br_frontend_kennwort()
{
    $z = mt_br_zugang();
    if (preg_match('/^[0-9a-f]{32}$/', $z['frontend_kennwort'])) {
        return $z['frontend_kennwort'];
    }
    $z['frontend_kennwort'] = bin2hex(random_bytes(16));
    return mt_br_zugang_speichern($z) ? $z['frontend_kennwort'] : '';
}

/** Die Geheimnisse, die der Protokollfilter woertlich ausblendet. */
function mt_br_geheimnisse()
{
    $z = mt_br_zugang();
    return array_values(array_filter(array($z['kennwort'], $z['frontend_kennwort']), function ($g) {
        return strlen((string) $g) >= 4;
    }));
}

/** Benutzername: druckbar, ohne Leerzeichen am Rand, hoechstens 64 Zeichen. */
function mt_br_benutzer_gueltig($b)
{
    return is_string($b) && $b === trim($b) && preg_match('/^[^\x00-\x1F\x7F:]{1,64}$/u', $b) === 1;
}

/* ---------------- Miniserver und Testanmeldung (Stufe 1) ---------------- */

/** Adresse und Port aus den LoxBerry-Miniserver-Einstellungen (nicht aus
 *  der Vorgabe 8080 des Plugins). Der erste Miniserver. null = keiner. */
function mt_br_miniserver()
{
    $p = mt_paths();
    $home = $p['home'] !== '' ? $p['home'] : $p['archiv'];
    if ($home === '') {
        return null;
    }
    $g = mt_json_lesen($home . '/config/system/general.json');
    $ms = isset($g['Miniserver']) && is_array($g['Miniserver']) ? $g['Miniserver'] : array();
    $nummern = array_keys($ms);
    sort($nummern);
    foreach ($nummern as $n) {
        $m = $ms[$n];
        $ip = is_array($m) && isset($m['Ipaddress']) ? trim((string) $m['Ipaddress']) : '';
        $port = is_array($m) && isset($m['Port']) && preg_match('/^[0-9]{1,5}$/', (string) $m['Port']) ? (int) $m['Port'] : 80;
        if ($ip !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-]{0,80}$/', $ip) && $port >= 1 && $port <= 65535) {
            return array('host' => $ip, 'port' => $port, 'name' => isset($m['Name']) ? mt_kurz((string) $m['Name'], 40) : '');
        }
    }
    return null;
}

/** Ein HTTP-GET. Rueckgabe array(code, rumpf, fehler); code 0 = keine Antwort. */
function mt_br_http($url, array $kopf, $frist)
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => (int) $frist,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => $kopf,
            CURLOPT_FOLLOWLOCATION => false,
        ));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fehler = $body === false ? curl_error($ch) : '';
        curl_close($ch);
        return array($body === false ? 0 : $code, $body === false ? '' : (string) $body, $fehler);
    }
    $ctx = stream_context_create(array('http' => array(
        'method' => 'GET', 'header' => implode("\r\n", $kopf), 'timeout' => (int) $frist,
        'ignore_errors' => true, 'follow_location' => 0,
    )));
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    /* Hausform (C7): die Funktion ab 8.4, darunter die Variable ueber ihren
     * Namen - PHP 8.5 meldet die Zauber-Variable als veraltet. */
    $kz = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : null;
    if ($kz === null) {
        $kn = 'http_response_header';
        $kz = isset($$kn) ? $$kn : null;
    }
    if (is_array($kz)) {
        foreach ($kz as $z) {
            if (preg_match('#^HTTP/[0-9.]+\s+([0-9]{3})#', $z, $m)) {
                $code = (int) $m[1];
            }
        }
    }
    return array($body === false ? 0 : $code, $body === false ? '' : (string) $body,
                 $body === false ? 'keine Verbindung' : '');
}

/**
 * Die einmalige Testanmeldung (Entwurf 3.9): GENAU EIN Abruf von
 * data/LoxAPP3.json mit Basic-Kopf, nie beim Seitenaufbau, nur auf Knopfdruck.
 * Rueckgabe array(ergebnis, Zahl der Bausteine) mit ergebnis
 * ok | leer | kein_ms | netz | abgelehnt | gesperrt | http | form.
 * Ein Netzfehler aendert den Merker nicht (kein Fehlversuch gezaehlt).
 */
function mt_br_testanmeldung()
{
    $f = mt_br_pfade();
    $z = mt_br_zugang();
    if ($z['benutzer'] === '' || $z['kennwort'] === '') {
        return array('leer', 0);
    }
    $ms = mt_br_miniserver();
    if ($ms === null) {
        return array('kein_ms', 0);
    }
    $url = 'http://' . $ms['host'] . ':' . $ms['port'] . '/data/LoxAPP3.json';
    list($code, $roh, ) = mt_br_http($url, array('Accept: application/json',
        'Authorization: Basic ' . base64_encode($z['benutzer'] . ':' . $z['kennwort'])), 15);
    $merk = array('ok' => 0, 'ts' => time(), 'zugang_ts' => (int) $z['geaendert'], 'ergebnis' => '', 'anzahl' => 0);
    if ($code === 0) {
        mt_log('Bridge: Testanmeldung - Miniserver ' . $ms['host'] . ':' . $ms['port'] . ' nicht erreichbar (zaehlt nicht als Fehlversuch).');
        return array('netz', 0);
    }
    $gesperrt = $code === 403 || preg_match('/4003|temporarily blocked|blocked/i', substr($roh, 0, 400)) === 1;
    if ($gesperrt) {
        $merk['ergebnis'] = 'gesperrt';
    } elseif ($code === 401) {
        $merk['ergebnis'] = 'abgelehnt';
    } elseif ($code !== 200) {
        $merk['ergebnis'] = 'http';
    }
    if ($merk['ergebnis'] !== '') {
        mt_json_schreiben($f['anmeldung'], $merk, 0600);
        mt_log('Bridge: Testanmeldung des Benutzers ' . $z['benutzer'] . ' abgewiesen (HTTP ' . (int) $code . ', ' . $merk['ergebnis'] . ').');
        return array($merk['ergebnis'], (int) $code);
    }
    $d = json_decode($roh, true);
    if (!is_array($d) || !isset($d['controls']) || !is_array($d['controls'])) {
        $merk['ergebnis'] = 'form';
        mt_json_schreiben($f['anmeldung'], $merk, 0600);
        return array('form', 0);
    }
    $struktur = mt_br_struktur_aus($d);
    $struktur['ts'] = time();
    $struktur['benutzer'] = $z['benutzer'];
    mt_json_schreiben($f['struktur'], $struktur, 0600);
    $merk['ok'] = 1;
    $merk['ergebnis'] = 'ok';
    $merk['anzahl'] = count($struktur['bausteine']);
    mt_json_schreiben($f['anmeldung'], $merk, 0600);
    mt_log('Bridge: Testanmeldung des Benutzers ' . $z['benutzer'] . ' am Miniserver gelungen, '
           . $merk['anzahl'] . ' Bausteine sichtbar.');
    return array('ok', $merk['anzahl']);
}

/** Nur was die Auswahl braucht: Raeume, Bausteine mit Typ, Name, Raum,
 *  Einheit und den Kreisen eines Lichtbausteins. */
function mt_br_struktur_aus(array $d)
{
    $raeume = array();
    foreach ((isset($d['rooms']) && is_array($d['rooms']) ? $d['rooms'] : array()) as $u => $r) {
        $raeume[(string) $u] = is_array($r) && isset($r['name']) ? mt_kurz((string) $r['name'], 60) : '';
    }
    $liste = array();
    foreach ($d['controls'] as $uuid => $c) {
        if (!is_array($c) || !mt_br_schluessel_gueltig((string) $uuid)) {
            continue;
        }
        $e = array(
            'uuid'   => (string) $uuid,
            'name'   => mt_kurz(isset($c['name']) ? (string) $c['name'] : '', 80),
            'typ'    => mt_kurz(isset($c['type']) ? (string) $c['type'] : '', 40),
            'raum'   => isset($c['room'], $raeume[(string) $c['room']]) ? $raeume[(string) $c['room']] : '',
            'format' => isset($c['details']['format']) ? mt_kurz((string) $c['details']['format'], 20) : '',
            'kreise' => array(),
        );
        if (isset($c['subControls']) && is_array($c['subControls'])) {
            foreach ($c['subControls'] as $su => $s) {
                if (is_array($s) && preg_match('#/AI[0-9]{1,3}$#', (string) $su) && mt_br_schluessel_gueltig((string) $su)) {
                    $e['kreise'][] = array('uuid' => (string) $su,
                        'name' => mt_kurz(isset($s['name']) ? (string) $s['name'] : '', 80),
                        'typ' => mt_kurz(isset($s['type']) ? (string) $s['type'] : '', 40));
                }
            }
        }
        $liste[] = $e;
        if (count($liste) >= 2000) {
            break;
        }
    }
    return array('bausteine' => $liste);
}

/** Loxone-UUID (8-4-4-16, klein) mit optionalem Kreis /AIn. */
function mt_br_schluessel_gueltig($k)
{
    return is_string($k) && preg_match('#^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{16}(/AI[0-9]{1,3})?$#', $k) === 1;
}

function mt_br_anmeldung()
{
    return mt_json_lesen(mt_br_pfade()['anmeldung']);
}

/** Gilt die Testanmeldung fuer den gespeicherten Zugang? */
function mt_br_anmeldung_gilt()
{
    $a = mt_br_anmeldung();
    $z = mt_br_zugang();
    return !empty($a['ok']) && isset($a['zugang_ts']) && (int) $a['zugang_ts'] === (int) $z['geaendert']
        && $z['benutzer'] !== '' && $z['kennwort'] !== '';
}

function mt_br_gesperrt()
{
    $s = mt_json_lesen(mt_br_pfade()['gesperrt']);
    return isset($s['ts']) ? $s : null;
}

/**
 * Darf Matter2Lox die Bridge starten? Nur mit gueltiger Testanmeldung, und
 * liegt ein Sperrmerker, nur mit einer Testanmeldung, die NACH der Sperre
 * gelang (Entwurf 3.11: nie Endlos-Neuversuch). Rueckgabe array(ok, Schluessel).
 */
function mt_br_freigabe()
{
    if (!mt_br_anmeldung_gilt()) {
        return array(0, 'BRIDGE.V_ANMELDUNG_FEHLT');
    }
    $s = mt_br_gesperrt();
    $a = mt_br_anmeldung();
    if ($s !== null && (int) $a['ts'] <= (int) $s['ts']) {
        return array(0, 'BRIDGE.V_GESPERRT_NEU');
    }
    return array(1, '');
}

/* ---------------- Typzuordnung (Entwurf 2.4) ---------------- */

/** Was die Bridge aus einem Loxone-Baustein macht. Nur diese Werte gehen in
 *  die Konfiguration (Regeln/05:54). Tore, Tueren, Alarm, Schloesser nie
 *  (Regeln/05:177). Stimmungen (mood) und Radio folgen in 0.9.43. */
function mt_br_typen()
{
    return array(
        'Switch'          => array('outlet', array('outlet', 'light'), ''),
        'Pushbutton'      => array('outlet', array('outlet', 'light'), 'BRIDGE.HW_TASTER'),
        'Jalousie'        => array('shade', array('shade'), 'BRIDGE.HW_JALOUSIE'),
        'Window'          => array('shade', array('shade'), 'BRIDGE.HW_UNGEMESSEN'),
        'InfoOnlyDigital' => array('contactsensor', array('contactsensor'), 'BRIDGE.HW_SENSOR'),
        'SmokeAlarm'      => array('smoke', array('smoke'), 'BRIDGE.HW_NUR_LESEN'),
        'AcControl'       => array('ac', array('ac'), 'BRIDGE.HW_AC'),
    );
}

function mt_br_grund($typ)
{
    if (in_array($typ, array('IRoomControllerV2', 'IRoomController'), true)) {
        return 'BRIDGE.GRAU_RAUMREGLER';
    }
    if (in_array($typ, array('Gate', 'CentralGate', 'Alarm', 'CentralAlarm', 'Intercom', 'IntercomV2',
                             'NfcCodeTouch', 'Door', 'DoorLock', 'SmartAlarm', 'AalSmartAlarm'), true)) {
        return 'BRIDGE.GRAU_SICHERHEIT';
    }
    if (in_array($typ, array('Radio', 'LightControllerV2', 'LightController'), true)) {
        return 'BRIDGE.GRAU_SPAETER';
    }
    return 'BRIDGE.GRAU_UNBEKANNT';
}

/** Matter-Typ eines Messwerts nach seiner Einheit (details.format). */
function mt_br_analog_typ($format)
{
    /* Erst den printf-Platzhalter (%.1f, %d ...) entfernen: er traegt selbst
     * ein %, und "%.0f" ohne Einheit galt sonst als Feuchte (im Pruefstand
     * gemessen, 15.10.2026). Was danach bleibt, ist die Einheit. */
    $f = preg_replace('/%[-+ 0#]*[0-9]*(\.[0-9]+)?[a-zA-Z]/', '', (string) $format);
    if (strpos($f, '°') !== false) {
        return 'temperature';
    }
    if (preg_match('/hPa|mbar/i', $f)) {
        return 'pressure';
    }
    if (strpos($f, '%') !== false) {
        return 'humidity';
    }
    return '';
}

/**
 * Die Zeilen der Auswahltabelle aus dem Zwischenspeicher der Testanmeldung:
 * je Zeile schluessel, name, raum, loxone_typ, vorschlag ('' = ausgegraut),
 * wahl (erlaubte Werte), grund (Sprachschluessel des Ausgrauens), hinweis.
 */
function mt_br_zeilen()
{
    $s = mt_json_lesen(mt_br_pfade()['struktur']);
    $aus = array();
    $typen = mt_br_typen();
    foreach ((isset($s['bausteine']) && is_array($s['bausteine']) ? $s['bausteine'] : array()) as $b) {
        if (!is_array($b) || !isset($b['uuid'], $b['typ']) || !mt_br_schluessel_gueltig((string) $b['uuid'])) {
            continue;
        }
        $z = array('schluessel' => (string) $b['uuid'], 'name' => (string) $b['name'], 'raum' => (string) $b['raum'],
                   'loxone_typ' => (string) $b['typ'], 'vorschlag' => '', 'wahl' => array(), 'grund' => '', 'hinweis' => '');
        $t = (string) $b['typ'];
        if (isset($typen[$t])) {
            list($z['vorschlag'], $z['wahl'], $z['hinweis']) = $typen[$t];
        } elseif ($t === 'InfoOnlyAnalog') {
            $a = mt_br_analog_typ(isset($b['format']) ? $b['format'] : '');
            if ($a !== '') {
                $z['vorschlag'] = $a;
                $z['wahl'] = array($a);
                $z['hinweis'] = 'BRIDGE.HW_SENSOR';
            } else {
                $z['grund'] = 'BRIDGE.GRAU_EINHEIT';
            }
        } else {
            $z['grund'] = mt_br_grund($t);
        }
        $aus[] = $z;
        /* Die Kreise eines Lichtbausteins (<UUID>/AIx) als lightoutput. */
        if ($t === 'LightControllerV2' && isset($b['kreise']) && is_array($b['kreise'])) {
            foreach ($b['kreise'] as $k) {
                if (!is_array($k) || !isset($k['uuid']) || !mt_br_schluessel_gueltig((string) $k['uuid'])) {
                    continue;
                }
                $kz = array('schluessel' => (string) $k['uuid'], 'name' => (string) $b['name'] . ' / ' . (string) $k['name'],
                            'raum' => (string) $b['raum'], 'loxone_typ' => 'LightControllerV2 / ' . (string) $k['typ'],
                            'vorschlag' => '', 'wahl' => array(), 'grund' => '', 'hinweis' => '');
                if (in_array((string) $k['typ'], array('Dimmer', 'Switch', 'ColorPickerV2', 'EIBDimmer'), true)) {
                    $kz['vorschlag'] = 'lightoutput';
                    $kz['wahl'] = array('lightoutput');
                    $kz['hinweis'] = 'BRIDGE.HW_KREIS';
                } else {
                    $kz['grund'] = 'BRIDGE.GRAU_UNBEKANNT';
                }
                $aus[] = $kz;
            }
        }
    }
    usort($aus, function ($a, $b) {
        $r = strcmp($a['raum'], $b['raum']);
        return $r !== 0 ? $r : strcmp($a['name'], $b['name']);
    });
    return $aus;
}

/** Die Auswahl gegen die Tabelle halten: nur bekannte, nicht ausgegraute
 *  Zeilen mit einem erlaubten Typ. Rueckgabe array(auswahl, verworfen). */
function mt_br_auswahl_pruefen(array $wunsch, array $zeilen)
{
    $erlaubt = array();
    foreach ($zeilen as $z) {
        if ($z['vorschlag'] !== '') {
            $erlaubt[$z['schluessel']] = $z['wahl'];
        }
    }
    $aus = array();
    $weg = 0;
    foreach ($wunsch as $k => $t) {
        if (is_string($k) && is_string($t) && isset($erlaubt[$k]) && in_array($t, $erlaubt[$k], true)) {
            $aus[$k] = $t;
        } else {
            $weg++;
        }
    }
    ksort($aus);
    return array($aus, $weg);
}

/** Die Liste uuidsandtypes fuer matterbridge-loxone: "<UUID>[/AIx],<typ>".
 *  Namen kommen nie hinein (MBL trennt an "," und schneidet am "="). */
function mt_br_uuidsandtypes(array $auswahl)
{
    list($aw, ) = mt_br_auswahl_pruefen($auswahl, mt_br_zeilen());
    $l = array();
    foreach ($aw as $k => $t) {
        $l[] = $k . ',' . $t;
    }
    return $l;
}

/**
 * Die Plugin-Konfiguration im Bridge-Ordner schreiben - atomar, 0600
 * (mt_json_schreiben setzt die Rechte vor dem Inhalt). Quelle der Wahrheit
 * sind bridge.json und bridge_zugang.json; diese Datei ist abgeleitet und
 * wird bei jedem Start neu geschrieben. Das Kennwort steht nur hier und in
 * bridge_zugang.json, nie im Protokoll. Rueckgabe array(ok, Zahl oder Grund).
 */
function mt_br_konfig_schreiben()
{
    $f = mt_br_pfade();
    $z = mt_br_zugang();
    $ms = mt_br_miniserver();
    if ($ms === null) {
        return array(0, mt_t('BRIDGE.V_KEIN_MS'));
    }
    if ($z['benutzer'] === '' || $z['kennwort'] === '') {
        return array(0, mt_t('BRIDGE.V_ANMELDUNG_FEHLT'));
    }
    $liste = mt_br_uuidsandtypes(mt_br_cfg()['auswahl']);
    $ziel = $f['ordner'] . '/.matterbridge/matterbridge-loxone.config.json';
    if (!is_dir(dirname($ziel)) && !@mkdir(dirname($ziel), 0700, true)) {
        return array(0, sprintf(mt_t('BRIDGE.V_ORDNER'), mt_e(dirname($ziel))));
    }
    $ok = mt_json_schreiben($ziel, array(
        'name' => 'matterbridge-loxone', 'type' => 'DynamicPlatform',
        'host' => $ms['host'], 'port' => (int) $ms['port'],
        'username' => $z['benutzer'], 'password' => $z['kennwort'],
        'uuidsandtypes' => $liste,
        'dumpcontrols' => false, 'dumpstates' => false, 'logevents' => false,
        'debug' => false, 'unregisterOnShutdown' => false,
    ), 0600);
    if (!$ok) {
        return array(0, sprintf(mt_t('BRIDGE.V_KONFIG_FEHL'), mt_e($ziel)));
    }
    mt_log('Bridge: Konfiguration geschrieben (' . count($liste) . ' Geraete, Benutzer ' . $z['benutzer']
           . ', Miniserver ' . $ms['host'] . ':' . $ms['port'] . ').');
    return array(1, count($liste));
}

/* ---------------- Protokollfilter (Entwurf 3.10) ---------------- */

/**
 * Container-Ausgabe fuer Anzeige, Download, Meldung und Protokoll. Der
 * Passcode der Bridge gilt dauerhaft - ein geleakter Code ist kein Zugang auf
 * Zeit. Ausgeblendet werden: die beiden Kennwoerter woertlich, QR-Adresse,
 * MT:-Texte, Passcode, manuelle Codes (11/21 Ziffern, auch gegliedert) und
 * Zeilen einer QR-Grafik aus Blockzeichen (U+2580 bis U+259F, ab 10 je Zeile).
 */
function mt_br_log_filter($text, $geheim = null)
{
    if ($geheim === null) {
        $geheim = mt_br_geheimnisse();
    }
    $t = preg_replace('/\x1B\[[0-9;?]*[A-Za-z]/', '', (string) $text);
    $aus = array();
    foreach (explode("\n", (string) $t) as $z) {
        foreach ($geheim as $g) {
            if (strlen((string) $g) >= 4) {
                $z = str_replace((string) $g, '***', $z);
            }
        }
        if (preg_match_all('/\xE2\x96[\x80-\x9F]/', $z) >= 10) {
            $aus[] = '[QR-Grafik ausgeblendet]';
            continue;
        }
        $z = preg_replace('#(qrcode\.html\?data=)\S*#i', '$1***', $z);
        $z = preg_replace('/MT:[0-9A-Z.\-]+/', 'MT:***', $z);
        $z = preg_replace('/(passcode|pin ?code|setup ?pin)(\s*[:=]?\s*)[^\s,;)]+/i', '$1$2***', $z);
        $z = preg_replace('/(?<![0-9])[0-9]{4}-?[0-9]{3}-?[0-9]{4}(?:-?[0-9]{5}-?[0-9]{5})?(?![0-9])/', '***', $z);
        $z = preg_replace('/(?<![0-9])[0-9]{21}(?![0-9])/', '***', $z);
        $aus[] = $z;
    }
    return implode("\n", $aus);
}

/** Die letzten Zeilen des Bridge-Protokolls, gefiltert. */
function mt_br_log($zeilen = 300)
{
    list($ok, $aus) = mt_docker('logs --tail ' . (int) $zeilen . ' ' . escapeshellarg(mt_br_name()), 30);
    return array($ok ? 1 : 0, mt_br_log_filter((string) $aus));
}

/* ---------------- Waechter-Muster (Entwurf 3.11) ---------------- */

/** EINE Tabelle fuer Waechter und Pruefstand (bin/bridge_muster.json). */
function mt_br_muster()
{
    static $m = null;
    if ($m !== null) {
        return $m;
    }
    $d = mt_json_lesen(mt_br_pfade()['muster']);
    $m = array('fehlerzeile' => array('error|fail|reconnect|refused|denied|reject'),
               'anmeldung' => array('4003', '\\b401\\b', 'unauthori[sz]ed', 'blocked', 'getjwt', 'authenticat'),
               'netz' => array('ECONNREFUSED', 'ETIMEDOUT', 'EHOSTUNREACH', 'ENETUNREACH', 'ECONNRESET', 'EAI_AGAIN', 'socket hang up', 'timed? ?out'),
               'erfolg' => array('Creating devices'));
    foreach (array_keys($m) as $k) {
        if (isset($d[$k]) && is_array($d[$k]) && $d[$k]) {
            $m[$k] = array_values(array_filter($d[$k], 'is_string'));
        }
    }
    return $m;
}

function mt_br_trifft(array $muster, $zeile)
{
    foreach ($muster as $mu) {
        if (@preg_match('~' . str_replace('~', '\\~', $mu) . '~i', $zeile) === 1) {
            return true;
        }
    }
    return false;
}

/** Zaehlt Anmeldefehler und Erfolge in einem Protokollausschnitt. Ein
 *  Netzfehler zaehlt nie - sonst hielte jeder Neustart des Miniservers die
 *  Bridge an. Rueckgabe array(fehler, erfolge, letzte Fehlzeile gefiltert). */
function mt_br_zaehlen($text)
{
    $m = mt_br_muster();
    $fehler = 0;
    $erfolg = 0;
    $letzte = '';
    foreach (explode("\n", preg_replace('/\x1B\[[0-9;?]*[A-Za-z]/', '', (string) $text)) as $z) {
        if (trim($z) === '') {
            continue;
        }
        if (mt_br_trifft($m['erfolg'], $z)) {
            $erfolg++;
            continue;
        }
        if (mt_br_trifft($m['fehlerzeile'], $z) && mt_br_trifft($m['anmeldung'], $z) && !mt_br_trifft($m['netz'], $z)) {
            $fehler++;
            $letzte = $z;
        }
    }
    return array($fehler, $erfolg, mt_kurz(mt_br_log_filter($letzte), 300));
}

/** Anhalten wegen Anmeldefehlern: docker stop, Sperrmerker, EINE
 *  LoxBerry-Benachrichtigung. Die Restart-Policy bleibt: unless-stopped haelt
 *  einen angehaltenen Container auch ueber einen Neustart von Docker an. */
function mt_br_sperren($stufe, $zahl, $zeile)
{
    $f = mt_br_pfade();
    $name = mt_br_name();
    list($ok, ) = mt_docker('stop -t 10 ' . escapeshellarg($name), 40);
    $war = mt_br_gesperrt();
    mt_json_schreiben($f['gesperrt'], array('ts' => time(), 'stufe' => (int) $stufe, 'zahl' => (int) $zahl,
        'zeile' => (string) $zeile, 'grund' => 'anmeldung', 'angehalten' => $ok ? 1 : 0), 0600);
    mt_log('Bridge: angehalten durch den Waechter (Stufe ' . (int) $stufe . ', ' . (int) $zahl
           . ' Anmeldefehler). Weitere Versuche haetten den Loxone-Benutzer gesperrt.'
           . ($ok ? '' : ' ACHTUNG: docker stop meldete einen Fehler.'));
    if ($war === null) {
        $p = mt_paths();
        if (!function_exists('notify') && $p['home'] !== '' && is_file($p['home'] . '/libs/phplib/loxberry_log.php')) {
            @include_once $p['home'] . '/libs/phplib/loxberry_log.php';
        }
        if (function_exists('notify')) {
            $z = mt_br_zugang();
            @notify($p['plugin'], 'bridge', sprintf(mt_t('BRIDGE.MELDUNG_GESPERRT'), $z['benutzer']), true);
        }
    }
    return $ok ? 1 : 0;
}

/* ---------------- Docker: Soll, Ist, Eigentum ---------------- */

/** Die Soll-Gestalt an EINER Stelle (Bauart mt_container_soll()). */
function mt_br_soll()
{
    $f = mt_br_pfade();
    $c = mt_br_cfg();
    $cfg = mt_config();
    $args = array('matterbridge', '--docker', '--bridge',
        '--port', (string) mt_br_matter_port(),
        '--frontend', (string) mt_br_frontend_port(), '--bind', '127.0.0.1',
        '--novirtual',
        '--vendorId', (string) mt_br_vid(), '--productId', (string) mt_br_pid());
    if ($c['namen_setzen'] === 1) {
        $args = array_merge($args, array('--vendorName', 'Matter2Lox', '--productName', 'Matter2Lox Bridge'));
    }
    $pi = trim((string) $cfg['primary_interface']);
    $tm = mt_textmuster();
    if ($pi !== '' && preg_match($tm['primary_interface'], $pi)) {
        $args[] = '--mdnsinterface';
        $args[] = $pi;
    }
    return array(
        'name'   => mt_br_name(),
        'abbild' => mt_br_abbild(),
        'label'  => mt_br_label(),
        'mounts' => array($f['ordner'] . '/Matterbridge' => '/root/Matterbridge',
                          $f['ordner'] . '/.matterbridge' => '/root/.matterbridge',
                          $f['ordner'] . '/.mattercert' => '/root/.mattercert'),
        'health' => array('--health-cmd', 'mb_health http://127.0.0.1:' . mt_br_frontend_port() . '/health',
                          '--health-interval', '60s', '--health-timeout', '10s',
                          '--health-start-period', '120s', '--health-retries', '5'),
        'args'   => $args,
    );
}

/** Kennungen dezimal? Jede Zahl nach --vendorId/--productId muss aus Ziffern
 *  bestehen - "0xFFF1" laese matterbridge als 0. */
function mt_br_kennungen_dezimal(array $args)
{
    foreach ($args as $i => $a) {
        if (in_array($a, array('--vendorId', '--productId', '--port', '--frontend'), true)) {
            if (!isset($args[$i + 1]) || !preg_match('/^[0-9]{1,5}$/', (string) $args[$i + 1])) {
                return false;
            }
        }
    }
    return true;
}

/** Die Aufrufzeile (ohne "docker"). '' = verweigert (Kennung nicht dezimal,
 *  falsches Label oder ein Geheimnis auf der Zeile). */
function mt_br_befehl($s = null)
{
    if ($s === null) {
        $s = mt_br_soll();
    }
    if (!mt_br_kennungen_dezimal($s['args']) || strpos($s['label'], mt_br_label_schluessel() . '=') !== 0) {
        return '';
    }
    $zeile = 'run -d --name ' . mt_sh_arg($s['name'])
        . ' --restart unless-stopped --stop-timeout 60'
        . ' --label ' . mt_sh_arg($s['label'])
        . ' --network host'
        . ' --memory 640m'
        . ' --log-opt max-size=2m --log-opt max-file=2';
    foreach ($s['health'] as $h) {
        $zeile .= ' ' . mt_sh_arg($h);
    }
    foreach ($s['mounts'] as $q => $z) {
        $zeile .= ' -v ' . mt_sh_arg($q . ':' . $z);
    }
    $zeile .= ' ' . mt_sh_arg($s['abbild']);
    foreach ($s['args'] as $a) {
        $zeile .= ' ' . mt_sh_arg($a);
    }
    foreach (mt_br_geheimnisse() as $g) {
        if (strpos($zeile, $g) !== false) {
            return '';
        }
    }
    return $zeile;
}

/** Ist der Container mit dem Bridge-Namen der eigene? (bin/bridge_eigen.sh)
 *  Rueckgabe array(Stand, Satz): 1 eigen, 0 fremd, 2 keiner, -1 unklar. */
function mt_br_eigen()
{
    $f = mt_br_pfade();
    if (!is_file($f['eigen'])) {
        return array(-1, sprintf(mt_t('EINST.CONTAINER_PRUEFUNG_FEHLT'), $f['eigen']));
    }
    $seite = mt_docker_seite();
    if ($seite['lage'] !== '' && $seite['lage'] !== 'ok') {
        return array(-1, mt_t('EINST.DOCKER_GESPERRT'));
    }
    $je = 30;
    $gesamt = 70;
    if ($seite['bis'] > 0) {
        $gesamt = $seite['bis'] - time();
        if ($gesamt < 1) {
            return array(-1, mt_t('EINST.DOCKER_SEITENFRIST'));
        }
        $je = max(1, (int) floor($gesamt / 2));
    }
    $aus = array();
    $rc = 0;
    @exec('env MT_DOCKER_FRIST=' . (int) $je . ' timeout -k 2 ' . (int) $gesamt . ' bash ' . escapeshellarg($f['eigen'])
          . ' ' . escapeshellarg(mt_paths()['plugin']) . ' ' . escapeshellarg(mt_br_name()) . ' 2>&1', $aus, $rc);
    $satz = trim(implode(' ', $aus));
    $stand = array(0 => 1, 1 => 0, 2 => 2);
    return array(isset($stand[$rc]) ? $stand[$rc] : -1, $satz !== '' ? $satz : 'Rueckgabe ' . (int) $rc);
}

/** docker inspect des Bridge-Containers (oder $name). null = keiner/keine Antwort. */
function mt_br_ist($name = null)
{
    $name = $name === null ? mt_br_name() : $name;
    list($ok, $aus) = mt_docker('inspect --type container --format ' . escapeshellarg('{{json .}}')
                                . ' -- ' . escapeshellarg($name), 30);
    if (!$ok) {
        return null;
    }
    $d = json_decode(trim((string) $aus), true);
    if (!is_array($d)) {
        return null;
    }
    $mounts = array();
    foreach ((isset($d['Mounts']) && is_array($d['Mounts']) ? $d['Mounts'] : array()) as $m) {
        if (isset($m['Source'], $m['Destination'])) {
            $mounts[(string) $m['Source']] = (string) $m['Destination'];
        }
    }
    $lab = isset($d['Config']['Labels']) && is_array($d['Config']['Labels']) ? $d['Config']['Labels'] : array();
    $hz = isset($d['State']['Health']) && is_array($d['State']['Health']) ? $d['State']['Health'] : array();
    return array(
        'name'    => ltrim(isset($d['Name']) ? (string) $d['Name'] : '', '/'),
        'abbild'  => isset($d['Config']['Image']) ? (string) $d['Config']['Image'] : '',
        'label'   => isset($lab[mt_br_label_schluessel()]) ? mt_br_label_schluessel() . '=' . $lab[mt_br_label_schluessel()] : '',
        'mounts'  => $mounts,
        'args'    => isset($d['Config']['Cmd']) && is_array($d['Config']['Cmd']) ? array_map('strval', $d['Config']['Cmd']) : array(),
        'laeuft'  => !empty($d['State']['Running']),
        'oom'     => !empty($d['State']['OOMKilled']),
        'health'  => isset($hz['Status']) && is_string($hz['Status']) ? $hz['Status'] : '',
        'neustarts' => isset($d['RestartCount']) ? (int) $d['RestartCount'] : 0,
    );
}

/** Weicht der laufende Bridge-Container von der Soll-Gestalt ab? Liste der
 *  Abweichungen (leer = passt) oder null (nicht zu lesen). */
function mt_br_abweichung($ist = null)
{
    $s = mt_br_soll();
    if ($ist === null) {
        $ist = mt_br_ist();
    }
    if ($ist === null) {
        return null;
    }
    $ab = array();
    if ($ist['abbild'] !== $s['abbild']) {
        $ab[] = sprintf(mt_t('EINST.AB_ABBILD'), $ist['abbild'], $s['abbild']);
    }
    if ($ist['label'] !== $s['label']) {
        $ab[] = sprintf(mt_t('EINST.AB_LABEL'), $ist['label'] !== '' ? $ist['label'] : '-', $s['label']);
    }
    foreach ($s['mounts'] as $q => $z) {
        if (!isset($ist['mounts'][$q]) || $ist['mounts'][$q] !== $z) {
            $ab[] = sprintf(mt_t('EINST.AB_MOUNT_FEHLT'), $q . ':' . $z);
        }
    }
    if ($ist['args'] !== $s['args']) {
        $ab[] = sprintf(mt_t('EINST.AB_ARGS'), implode(' ', $ist['args']), implode(' ', $s['args']));
    }
    return $ab;
}

/** Laeuft ein Container, der nicht der eigene ist, unter dem Namen der
 *  Bridge, wird nichts angefasst. 'kein' / 'laeuft' / 'steht' / 'fremd' / 'unklar'. */
function mt_br_zustand()
{
    list($e, $g) = mt_br_eigen();
    if ($e === 2) {
        return array('kein', $g, null);
    }
    if ($e === 0) {
        return array('fremd', $g, null);
    }
    if ($e !== 1) {
        return array('unklar', $g, null);
    }
    $ist = mt_br_ist();
    if ($ist === null) {
        return array('unklar', $g, null);
    }
    return array($ist['laeuft'] ? 'laeuft' : 'steht', $g, $ist);
}

/* ---------------- Voraussetzungen ---------------- */

/** Ist ein lokaler Port belegt? /proc/net (tcp: LISTEN, udp: gebunden).
 *  Rueckgabe 1 belegt, 0 frei, -1 nicht zu lesen. */
function mt_br_port_belegt($port, $udp)
{
    $dateien = $udp ? array('/proc/net/udp', '/proc/net/udp6') : array('/proc/net/tcp', '/proc/net/tcp6');
    $gelesen = false;
    $hex = sprintf('%04X', (int) $port);
    foreach ($dateien as $d) {
        $z = @file($d, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($z)) {
            continue;
        }
        $gelesen = true;
        foreach (array_slice($z, 1) as $zeile) {
            $t = preg_split('/\s+/', trim($zeile));
            if (count($t) < 4) {
                continue;
            }
            $lok = explode(':', $t[1]);
            if (strtoupper((string) end($lok)) === $hex && ($udp || $t[3] === '0A')) {
                return 1;
            }
        }
    }
    return $gelesen ? 0 : -1;
}

/** MemAvailable in MB, -1 = nicht zu lesen. */
function mt_br_speicher_frei()
{
    $t = (string) @file_get_contents('/proc/meminfo');
    return preg_match('/^MemAvailable:\s+([0-9]+)\s+kB/m', $t, $m) ? (int) floor((int) $m[1] / 1024) : -1;
}

/** Entwurf 3.3: eine .mattercert/pairing.json ueberschreibt Kommandozeile
 *  und Vorgaben (Kennungen, Passcode). Dann wird nicht gestartet. */
function mt_br_pairing_liegt()
{
    return is_file(mt_br_pfade()['ordner'] . '/.mattercert/pairing.json');
}

/* ---------------- Schnittstelle der matterbridge (bin/bridge_api.py) ---------------- */

/**
 * Ein Auftrag an bin/bridge_api.py: zustand | fenster_auf | fenster_zu |
 * kennwort_setzen. Auftrag und Frontend-Kennwort gehen ueber stdin (nie
 * ueber die Kommandozeile), die Antwort ist JSON. Frist 15 s.
 * Rueckgabe array mit 'ok' (0/1) und den Feldern der Antwort.
 */
function mt_br_api($auftrag, array $extra = array())
{
    $f = mt_br_pfade();
    if (!in_array($auftrag, array('zustand', 'fenster_auf', 'fenster_zu', 'kennwort_setzen'), true)) {
        return array('ok' => 0, 'fehler' => 'auftrag');
    }
    $py = mt_python();
    if ($py === '' || !is_file($f['api']) || !function_exists('proc_open')) {
        return array('ok' => 0, 'fehler' => 'python');
    }
    $z = mt_br_zugang();
    $ein = json_encode(array_merge($extra, array('auftrag' => $auftrag, 'port' => mt_br_frontend_port(),
        'kennwort' => $z['frontend_kennwort'])));
    $rohre = array();
    $proc = @proc_open(array($py, '-I', '-B', $f['api']),
                       array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $rohre);
    if (!is_resource($proc)) {
        return array('ok' => 0, 'fehler' => 'proc_open');
    }
    @fwrite($rohre[0], $ein . "\n");
    @fclose($rohre[0]);
    stream_set_blocking($rohre[1], false);
    $aus = '';
    $ende = microtime(true) + 15.0;
    while (!feof($rohre[1]) && microtime(true) < $ende && strlen($aus) < 262144) {
        $l = array($rohre[1]);
        $w = null;
        $e = null;
        if (@stream_select($l, $w, $e, 0, 200000) > 0) {
            $aus .= (string) @fread($rohre[1], 65536);
        }
    }
    $fertig = feof($rohre[1]);
    @fclose($rohre[1]);
    @fclose($rohre[2]);
    if (!$fertig) {
        @proc_terminate($proc, 9);
        @proc_close($proc);
        return array('ok' => 0, 'fehler' => 'frist');
    }
    @proc_close($proc);
    $d = json_decode(trim($aus), true);
    return is_array($d) && isset($d['ok']) ? $d : array('ok' => 0, 'fehler' => 'antwort');
}

/** Die Antwort von "zustand" pruefen und auf das Noetige kuerzen. Codes nur
 *  in der erwarteten Form, sonst leer. */
function mt_br_zustand_pruefen($d)
{
    $z = array('ok' => 0, 'online' => null, 'commissioned' => null, 'advertising' => null,
               'qr' => '', 'manuell' => '', 'fabrics' => array(), 'geraete' => null, 'fehler' => '');
    if (!is_array($d) || empty($d['ok'])) {
        $z['fehler'] = is_array($d) && isset($d['fehler']) && is_string($d['fehler']) ? mt_kurz($d['fehler'], 60) : 'antwort';
        return $z;
    }
    $z['ok'] = 1;
    foreach (array('online', 'commissioned', 'advertising') as $k) {
        $z[$k] = isset($d[$k]) && is_bool($d[$k]) ? $d[$k] : null;
    }
    if (isset($d['qr']) && is_string($d['qr']) && preg_match('/^MT:[0-9A-Z.\-]{5,60}$/', $d['qr'])) {
        $z['qr'] = $d['qr'];
    }
    if (isset($d['manuell']) && is_string($d['manuell'])) {
        $m = preg_replace('/[^0-9]/', '', $d['manuell']);
        if (preg_match('/^([0-9]{11}|[0-9]{21})$/', $m)) {
            $z['manuell'] = $m;
        }
    }
    foreach ((isset($d['fabrics']) && is_array($d['fabrics']) ? array_slice($d['fabrics'], 0, 16) : array()) as $fb) {
        $idx = is_array($fb) ? mt_ganz(isset($fb['index']) ? $fb['index'] : null) : null;
        if ($idx === null || $idx < 1 || $idx > 254) {
            continue;
        }
        $vid = mt_ganz(isset($fb['vendor_id']) ? $fb['vendor_id'] : null);
        $z['fabrics'][] = array('index' => $idx, 'hersteller_id' => ($vid !== null && $vid <= 65535) ? $vid : null,
            'hersteller' => mt_kurz(isset($fb['vendor_name']) && is_string($fb['vendor_name']) ? $fb['vendor_name'] : '', 60),
            'bezeichnung' => mt_kurz(isset($fb['label']) && is_string($fb['label']) ? $fb['label'] : '', 32));
    }
    $z['geraete'] = isset($d['geraete']) && is_int($d['geraete']) ? $d['geraete'] : null;
    return $z;
}

/** Das Fenster fuer weitere Systeme, gemerkt OHNE Codes (0600). */
function mt_br_fenster()
{
    $d = mt_json_lesen(mt_br_pfade()['fenster']);
    return isset($d['ende']) && (int) $d['ende'] > time() ? (int) $d['ende'] : 0;
}

/* ---------------- Sicherung (Entscheidung 8) ---------------- */

/** Den Bridge-Ordner packen - ohne die Plugin-Konfiguration samt Kennwort
 *  (sie wird aus bridge.json/bridge_zugang.json neu geschrieben) und ohne
 *  node_modules (laesst sich mit npm ci neu holen). Die Kopplungsschluessel
 *  sind trotzdem darin. Rueckgabe array(ok, Pfad oder Meldung, Dateien). */
function mt_br_packen($ziel = null)
{
    $f = mt_br_pfade();
    $p = mt_paths();
    if (!is_dir($f['ordner'])) {
        return array(0, mt_t('BRIDGE.S_KEIN_ORDNER'), 0);
    }
    if (!mt_tar_da()) {
        return array(0, mt_t('EINST.M_FABRIC_KEIN_TAR'), 0);
    }
    if ($ziel === null) {
        $ziel = $p['datadir'] . '/bridge_export.' . getmypid() . '.tar.gz';
        if (!is_dir($p['datadir'])) {
            @mkdir($p['datadir'], 0775, true);
        }
    }
    $fehl = $ziel . '.err';
    $rc = 0;
    $aus = array();
    @exec('umask 077; LC_ALL=C timeout -k 5 120 tar -czf ' . escapeshellarg($ziel)
          . ' --exclude=./.matterbridge/matterbridge-loxone.config.json'
          . ' --exclude=./Matterbridge/m2l/node_modules'
          . ' -C ' . escapeshellarg($f['ordner']) . ' . 2>' . escapeshellarg($fehl), $aus, $rc);
    $meld = trim((string) @file_get_contents($fehl));
    @unlink($fehl);
    if ($rc !== 0 || !is_file($ziel)) {
        @unlink($ziel);
        return array(0, sprintf(mt_t('EINST.M_FABRIC_TAR_FEHL'), (int) $rc, mt_e((string) strtok($meld . "\n", "\n"))), 0);
    }
    $liste = array();
    @exec('tar -tzf ' . escapeshellarg($ziel) . ' 2>/dev/null', $liste);
    $n = 0;
    foreach ($liste as $z) {
        if ($z !== '' && substr($z, -1) !== '/') {
            $n++;
        }
    }
    return array(1, $ziel, $n);
}

function mt_br_sicherungen()
{
    $d = mt_br_pfade()['sicherungen'];
    $aus = array();
    foreach ((array) @scandir($d) as $f) {
        $f = (string) $f;
        if (preg_match('/^bridge-[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}\.tar\.gz$/', $f) && is_file($d . '/' . $f)) {
            $aus[$f] = array('name' => $f, 'pfad' => $d . '/' . $f,
                             'groesse' => (int) @filesize($d . '/' . $f), 'zeit' => (int) @filemtime($d . '/' . $f));
        }
    }
    krsort($aus);
    return array_values($aus);
}

/** Vor jedem Neuanlegen und vor dem Abbau: Sicherung, hoechstens drei. */
function mt_br_auto_sichern($behalten = 3)
{
    $f = mt_br_pfade();
    $inhalt = is_dir($f['ordner']) ? @scandir($f['ordner']) : array();
    if (!is_dir($f['ordner']) || (is_array($inhalt) && count($inhalt) <= 2)) {
        return array(1, '', 0);
    }
    if (!is_dir($f['sicherungen']) && !@mkdir($f['sicherungen'], 0700, true) && !is_dir($f['sicherungen'])) {
        return array(0, sprintf(mt_t('EINST.FS_ORDNER'), mt_e($f['sicherungen'])), 0);
    }
    @chmod($f['sicherungen'], 0700);
    $ziel = $f['sicherungen'] . '/bridge-' . date('Y-m-d_His') . '.tar.gz';
    list($ok, $was, $n) = mt_br_packen($ziel);
    if (!$ok) {
        return array(0, $was, 0);
    }
    @chmod($ziel, 0600);
    foreach (array_slice(mt_br_sicherungen(), max(1, (int) $behalten)) as $alt) {
        @unlink($alt['pfad']);
    }
    mt_log('Bridge: automatische Sicherung ' . $ziel . ' (' . (int) $n . ' Dateien).');
    return array(1, $ziel, $n);
}

/** Zurueckspielen wie die Fabric (mt_fabric_wiederherstellen): Liste vorher
 *  geprueft, Bridge angehalten, bisheriger Ordner bleibt als .vorher-<Zeit>.
 *  Gestartet wird danach NICHT hier - das tut der Vorgang bridge_neustart
 *  mit Testanmeldung und Beobachtung. Rueckgabe array(ok, html, war_an). */
function mt_br_wiederherstellen($archiv)
{
    $p = mt_paths();
    $f = mt_br_pfade();
    if ($p['home'] === '') {
        return array(0, mt_t('EINST.ARCHIV_VERWEIGERT'), 0);
    }
    if (!mt_tar_da()) {
        return array(0, mt_t('EINST.M_FABRIC_KEIN_TAR'), 0);
    }
    $sperre = @fopen($p['datadir'] . '/container_vorgang.lock', 'c');
    if ($sperre === false || !flock($sperre, LOCK_EX | LOCK_NB) || mt_ct_vorgang_aktiv() !== null) {
        if ($sperre !== false) {
            fclose($sperre);
        }
        return array(0, mt_t('EINST.V_LAEUFT_SCHON'), 0);
    }
    list($ok, $n) = mt_fabric_archiv_pruefen($archiv);
    if (!$ok) {
        flock($sperre, LOCK_UN);
        fclose($sperre);
        return array(0, $n, 0);
    }
    $war_an = 0;
    list($e, $g) = mt_br_eigen();
    if ($e === -1) {
        flock($sperre, LOCK_UN);
        fclose($sperre);
        return array(0, sprintf(mt_t('EINST.FW_UNKLAR'), mt_e($g)), 0);
    }
    if ($e === 1) {
        $ist = mt_br_ist();
        if ($ist !== null && $ist['laeuft']) {
            list($sok, $saus) = mt_docker('stop -t 20 ' . escapeshellarg(mt_br_name()), 40);
            if (!$sok) {
                flock($sperre, LOCK_UN);
                fclose($sperre);
                return array(0, sprintf(mt_t('EINST.FW_STOP_FEHL'), mt_e(mt_br_name()))
                    . ' <span class="sm-mono">' . mt_e(mt_br_log_filter(substr($saus, 0, 200))) . '</span>', 0);
            }
            $war_an = 1;
        }
    }
    $vorher = '';
    if (file_exists($f['ordner'])) {
        $vorher = $f['ordner'] . '.vorher-' . date('YmdHis');
        if (!@rename($f['ordner'], $vorher)) {
            flock($sperre, LOCK_UN);
            fclose($sperre);
            return array(0, sprintf(mt_t('EINST.FW_RENAME_FEHL'), mt_e($f['ordner'])), $war_an);
        }
    }
    $fehl = dirname($f['ordner']) . '/.bridge_auspacken.' . getmypid() . '.err';
    $rc = 0;
    if (!@mkdir($f['ordner'], 0700, true)) {
        $rc = -1;
    } else {
        @exec('umask 077; LC_ALL=C timeout -k 5 300 tar -xzf ' . escapeshellarg($archiv) . ' -C ' . escapeshellarg($f['ordner'])
              . ' --no-same-owner --no-overwrite-dir 2>' . escapeshellarg($fehl), $aus, $rc);
    }
    $meld = trim((string) @file_get_contents($fehl));
    @unlink($fehl);
    if ($rc !== 0) {
        @exec('rm -rf -- ' . escapeshellarg($f['ordner']));
        $zurueck = $vorher !== '' && @rename($vorher, $f['ordner']);
        flock($sperre, LOCK_UN);
        fclose($sperre);
        return array(0, sprintf(mt_t('EINST.FW_TAR_FEHL'), (int) $rc, mt_e((string) strtok($meld . "\n", "\n")))
                        . ' ' . ($vorher === '' ? '' : mt_t($zurueck ? 'EINST.FW_ALT_ZURUECK' : 'EINST.FW_ALT_NICHT_ZURUECK')), $war_an);
    }
    flock($sperre, LOCK_UN);
    fclose($sperre);
    mt_log('Bridge: Sicherung zurueckgespielt (' . (int) $n . ' Dateien), bisheriger Ordner: ' . ($vorher !== '' ? $vorher : '-') . '.');
    return array(1, sprintf(mt_t('BRIDGE.S_WH_OK'), (int) $n)
                    . ($vorher !== '' ? ' ' . sprintf(mt_t('EINST.FW_VORHER'), mt_e($vorher)) : ''), $war_an);
}

/* ---------------- Hintergrundvorgaenge (container_vorgang.php) ---------------- */

/** Gemeinsame Vorbedingungen fuer einrichten, uebernehmen und neustart. */
function mt_br_vorbedingungen()
{
    if (mt_paths()['home'] === '') {
        return array(0, mt_t('EINST.ARCHIV_VERWEIGERT'));
    }
    if (mt_br_cfg()['bridge_ein'] !== 1) {
        return array(0, mt_t('BRIDGE.V_AUS'));
    }
    list($lage, $lsatz) = mt_docker_lage(20);
    if ($lage !== 'ok') {
        $h = mt_docker_hinweis($lage);
        return array(0, $h !== '' ? $h : mt_e($lsatz));
    }
    list($ok, $schl) = mt_br_freigabe();
    if (!$ok) {
        return array(0, mt_t($schl));
    }
    if (mt_br_miniserver() === null) {
        return array(0, mt_t('BRIDGE.V_KEIN_MS'));
    }
    return array(1, '');
}

/**
 * Ordner, Abbild, Plugin (npm ci + --add), Konfiguration - alles, was vor
 * einem Start da sein muss. Rueckgabe array(ok, html).
 */
function mt_br_vorbereiten(array &$stand, $ende)
{
    $f = mt_br_pfade();
    if (mt_br_pairing_liegt()) {
        return array(0, sprintf(mt_t('BRIDGE.V_PAIRING'), mt_e($f['ordner'] . '/.mattercert/pairing.json')));
    }
    foreach (array($f['ordner'], $f['ordner'] . '/Matterbridge', $f['ordner'] . '/Matterbridge/m2l',
                   $f['ordner'] . '/.matterbridge', $f['ordner'] . '/.mattercert') as $d) {
        if (!is_dir($d) && !@mkdir($d, 0700, true) && !is_dir($d)) {
            return array(0, sprintf(mt_t('BRIDGE.V_ORDNER'), mt_e($d)));
        }
    }
    @chmod($f['ordner'], 0700);
    /* Entscheidung 6: Namen nur beim ERSTEN Anlegen, also nur, wenn noch keine
     * Knotenablage da ist (eine Handkopplung behaelt ihre Namen). */
    $c = mt_br_cfg();
    if ($c['namen_setzen'] === null) {
        $inhalt = array_diff((array) @scandir($f['ordner'] . '/.matterbridge'), array('.', '..', 'matterbridge-loxone.config.json'));
        $c['namen_setzen'] = $inhalt ? 0 : 1;
        mt_br_cfg_speichern($c);
    }
    $abbild = mt_br_abbild();
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
                            . ' <span class="sm-mono">' . mt_e(mt_br_log_filter(substr($aus, 0, 400))) . '</span>');
        }
    }
    /* Entscheidung 1 (Weg C'): package.json + package-lock.json aus dem
     * Plugin, npm ci im Einmal-Container in den Bridge-Ordner. Ist dieselbe
     * Sperrdatei schon installiert, entfaellt der Schritt (kein Netz). */
    $m2l = $f['ordner'] . '/Matterbridge/m2l';
    $lock = $f['npm'] . '/package-lock.json';
    if (!is_file($lock) || !is_file($f['npm'] . '/package.json')) {
        return array(0, sprintf(mt_t('BRIDGE.V_NPM_FEHLT'), mt_e($f['npm'])));
    }
    $md5 = md5_file($lock);
    $pk = mt_json_lesen($m2l . '/node_modules/matterbridge-loxone/package.json');
    $fertig = is_file($m2l . '/package-lock.json') && md5_file($m2l . '/package-lock.json') === $md5
        && isset($pk['version']) && $pk['version'] === mt_br_plugin_fassung();
    $name = mt_br_name();
    $einmal = ' --rm --label ' . mt_sh_arg(mt_br_label()) . ' --network host';
    if (!$fertig) {
        if (!@copy($f['npm'] . '/package.json', $m2l . '/package.json') || !@copy($lock, $m2l . '/package-lock.json')) {
            return array(0, sprintf(mt_t('BRIDGE.V_ORDNER'), mt_e($m2l)));
        }
        $stand['schritt'] = 'br_npm';
        mt_ct_vorgang_schreiben($stand);
        list($ok, $aus) = mt_docker('run' . $einmal . ' --name ' . mt_sh_arg($name . '-npm')
            . ' -v ' . mt_sh_arg($f['ordner'] . '/Matterbridge:/root/Matterbridge')
            . ' --entrypoint npm ' . mt_sh_arg($abbild)
            . ' ci --prefix /root/Matterbridge/m2l --omit=dev --ignore-scripts --no-audit --no-fund', max(30, min(600, $ende - time() - 30)));
        $pk = mt_json_lesen($m2l . '/node_modules/matterbridge-loxone/package.json');
        if (!$ok || !isset($pk['version']) || $pk['version'] !== mt_br_plugin_fassung()) {
            return array(0, sprintf(mt_t('BRIDGE.V_NPM_FEHL'), mt_br_plugin_fassung())
                            . ' <span class="sm-mono">' . mt_e(mt_br_log_filter(substr($aus, -600))) . '</span>');
        }
        mt_log('Bridge: matterbridge-loxone ' . mt_br_plugin_fassung() . ' mit npm ci installiert (Sperrdatei ' . $md5 . ').');
    }
    /* Registrieren: matterbridge --add <Pfad> endet von selbst. */
    $merk = $m2l . '/.m2l_registriert';
    if (!is_file($merk) || trim((string) @file_get_contents($merk)) !== $md5) {
        $stand['schritt'] = 'br_registrieren';
        mt_ct_vorgang_schreiben($stand);
        $v = '';
        foreach (mt_br_soll()['mounts'] as $q => $z) {
            $v .= ' -v ' . mt_sh_arg($q . ':' . $z);
        }
        list($ok, $aus) = mt_docker('run' . $einmal . ' --name ' . mt_sh_arg($name . '-add') . $v . ' ' . mt_sh_arg($abbild)
            . ' matterbridge --docker --add /root/Matterbridge/m2l/node_modules/matterbridge-loxone', 180);
        if (!$ok) {
            return array(0, mt_t('BRIDGE.V_ADD_FEHL') . ' <span class="sm-mono">' . mt_e(mt_br_log_filter(substr($aus, -600))) . '</span>');
        }
        @file_put_contents($merk, $md5 . "\n");
    }
    if (mt_br_frontend_kennwort() === '') {
        return array(0, mt_t('BRIDGE.V_KENNWORT_FEHL'));
    }
    $stand['schritt'] = 'br_konfig';
    mt_ct_vorgang_schreiben($stand);
    list($ok, $was) = mt_br_konfig_schreiben();
    if (!$ok) {
        return array(0, $was);
    }
    return array(1, '');
}

/**
 * Stufe 2 des Waechters: die ersten Sekunden nach einem Start beobachten.
 * Beim 2. Anmeldefehler sofort anhalten und sperren; ein Netzfehler zaehlt
 * nicht. Nebenbei das Frontend-Kennwort setzen, sobald die Schnittstelle
 * antwortet. Rueckgabe array(ergebnis, html) mit ergebnis ok | gesperrt | steht.
 */
function mt_br_beobachten($seit, array &$stand)
{
    $name = mt_br_name();
    $stand['schritt'] = 'br_beobachten';
    mt_ct_vorgang_schreiben($stand);
    $bis = $seit + mt_br_beobachten_frist();
    $kennwort = 0;
    while (true) {
        sleep(2);
        list(, $log) = mt_docker('logs --since ' . (int) $seit . ' --tail 2000 ' . escapeshellarg($name), 20);
        list($fehler, , $letzte) = mt_br_zaehlen($log);
        if ($fehler >= mt_br_schwelle_start()) {
            mt_br_sperren(2, $fehler, $letzte);
            return array('gesperrt', sprintf(mt_t('BRIDGE.V_GESPERRT_START'), (int) $fehler)
                . ($letzte !== '' ? ' <span class="sm-mono">' . mt_e($letzte) . '</span>' : ''));
        }
        $ist = mt_br_ist();
        if ($ist === null || !$ist['laeuft']) {
            list(, $tail) = mt_docker('logs --tail 15 ' . escapeshellarg($name), 20);
            return array('steht', mt_t('BRIDGE.V_STEHT') . ' <span class="sm-mono">'
                . mt_e(mt_kurz(mt_br_log_filter(trim((string) $tail)), 600)) . '</span>');
        }
        if (!$kennwort) {
            $a = mt_br_api('kennwort_setzen');
            if (!empty($a['ok'])) {
                $kennwort = 1;
                mt_log('Bridge: Frontend-Kennwort ' . (!empty($a['schon']) ? 'war schon gesetzt.' : 'gesetzt.'));
            }
        }
        if (time() >= $bis) {
            break;
        }
    }
    /* Stufe 3 zaehlt ab JETZT: was vor diesem gelungenen Start im Protokoll
     * steht (auch die Fehler, die zur Sperre fuehrten), hat Stufe 2 schon
     * gesehen. Im Pruefstand gemessen: mit einem geloeschten Merker zaehlte
     * der erste Waechterlauf die alten Zeilen der letzten 70 s mit. */
    mt_json_schreiben(mt_br_pfade()['waechter'], array('summe' => 0, 'bis' => time(), 'letzte' => ''), 0600);
    return array('ok', sprintf(mt_t('BRIDGE.V_BEOBACHTET'), mt_br_beobachten_frist())
                       . ($kennwort ? '' : ' ' . mt_t('BRIDGE.V_KENNWORT_OFFEN')));
}

/** Sperrmerker nach einem guten Start abraeumen. */
function mt_br_entsperren()
{
    $f = mt_br_pfade();
    if (is_file($f['gesperrt'])) {
        @unlink($f['gesperrt']);
        mt_log('Bridge: Sperrmerker entfernt (Testanmeldung nach der Sperre gelungen, Start beobachtet).');
    }
}

/** Ein Hintergrundauftrag der Bridge. Rueckgabe array(ok, html). */
function mt_br_vorgang($auftrag, array &$stand)
{
    list($ok, $satz) = mt_br_vorbedingungen();
    if (!$ok) {
        return array(0, $satz);
    }
    $ende = (int) $stand['start'] + mt_ct_frist();
    $name = mt_br_name();
    list($eigen, $grund) = mt_br_eigen();
    if ($eigen === 0 || $eigen === -1) {
        return array(0, mt_e(sprintf(mt_t('BRIDGE.V_NICHT_EIGEN'), $name, $grund)));
    }
    if ($auftrag !== 'einrichten' && $eigen === 2) {
        return array(0, mt_t('BRIDGE.V_KEINE'));
    }
    if ($auftrag === 'einrichten' && $eigen === 1) {
        $ist = mt_br_ist();
        if ($ist !== null && $ist['laeuft']) {
            return array(1, sprintf(mt_t('BRIDGE.V_SCHON'), mt_e($name)));
        }
        $auftrag = 'neustart';
    }
    list($ok, $satz) = mt_br_vorbereiten($stand, $ende);
    if (!$ok) {
        return array(0, $satz);
    }
    if ($auftrag === 'neustart') {
        $ist = mt_br_ist();
        $stand['schritt'] = 'br_starten';
        mt_ct_vorgang_schreiben($stand);
        $seit = time();
        list($ok, $aus) = mt_docker(($ist !== null && $ist['laeuft'] ? 'restart -t 20 ' : 'start ') . escapeshellarg($name), 60);
        if (!$ok) {
            return array(0, mt_t('BRIDGE.V_START_FEHL') . ' <span class="sm-mono">' . mt_e(mt_br_log_filter(substr($aus, 0, 300))) . '</span>');
        }
        list($erg, $satz) = mt_br_beobachten($seit, $stand);
        if ($erg === 'ok') {
            mt_br_entsperren();
        }
        $ab = mt_br_abweichung();
        return array($erg === 'ok' ? 1 : 0, $satz . ($ab ? ' ' . mt_t('BRIDGE.V_ABWEICHUNG') : ''));
    }
    $befehl = mt_br_befehl();
    if ($befehl === '') {
        return array(0, mt_t('BRIDGE.V_BEFEHL'));
    }
    if ($auftrag === 'einrichten') {
        $stand['schritt'] = 'anlegen';
        mt_ct_vorgang_schreiben($stand);
        $seit = time();
        list($ok, $aus) = mt_docker($befehl, max(30, $ende - time()));
        if (!$ok) {
            return array(0, mt_t('EINST.E_RUN_FEHL') . ' <span class="sm-mono">' . mt_e(mt_br_log_filter(substr($aus, 0, 400))) . '</span>');
        }
        mt_log('Bridge: Container ' . $name . ' angelegt (' . mt_br_abbild() . ', Label ' . mt_br_label() . ').');
        list($erg, $satz) = mt_br_beobachten($seit, $stand);
        if ($erg === 'ok') {
            mt_br_entsperren();
        }
        return array($erg === 'ok' ? 1 : 0, ($erg === 'ok' ? sprintf(mt_t('BRIDGE.V_EINGERICHTET'), mt_e($name)) . ' ' : '') . $satz);
    }
    /* uebernehmen: neu anlegen MIT Rueckweg (Bauart mt_container_neu_anlegen). */
    $rest_name = substr($name, 0, 60) . '-alt-' . date('YmdHis');
    $stand['schritt'] = 'umbenennen';
    mt_ct_vorgang_schreiben($stand);
    $war_an = ($i = mt_br_ist()) !== null && $i['laeuft'];
    list($ok, $aus) = mt_docker('rename ' . escapeshellarg($name) . ' ' . escapeshellarg($rest_name), 60);
    if (!$ok) {
        return array(0, sprintf(mt_t('EINST.N_RENAME_FEHL'), mt_e($name)));
    }
    $stand['schritt'] = 'anhalten';
    mt_ct_vorgang_schreiben($stand);
    mt_docker('stop -t 20 ' . escapeshellarg($rest_name), 60);
    $stand['schritt'] = 'br_sichern';
    mt_ct_vorgang_schreiben($stand);
    list($sok, $swas) = mt_br_auto_sichern();
    if (!$sok) {
        mt_br_rueckweg($rest_name, $name, $war_an, '');
        return array(0, sprintf(mt_t('EINST.N_SICHERUNG_FEHL'), $swas));
    }
    $stand['schritt'] = 'anlegen';
    mt_ct_vorgang_schreiben($stand);
    $seit = time();
    list($ok, $aus) = mt_docker($befehl, max(30, $ende - time()));
    if (!$ok) {
        $r = mt_br_rueckweg($rest_name, $name, $war_an, $name);
        return array(0, mt_t('EINST.N_NEU_FEHL') . ' <span class="sm-mono">' . mt_e(mt_br_log_filter(substr($aus, 0, 300))) . '</span> ' . $r);
    }
    list($erg, $satz) = mt_br_beobachten($seit, $stand);
    if ($erg === 'steht') {
        $r = mt_br_rueckweg($rest_name, $name, $war_an, $name);
        return array(0, $satz . ' ' . $r);
    }
    /* Angelegt - auch bei einer Sperre: der alte Container nutzt denselben
     * Zugang und scheiterte genauso. */
    mt_docker('rm -f ' . escapeshellarg($rest_name), 60);
    mt_log('Bridge: neu angelegt als ' . $name . ', alter Container (' . $rest_name . ') entfernt.');
    if ($erg === 'ok') {
        mt_br_entsperren();
    }
    return array($erg === 'ok' ? 1 : 0, sprintf(mt_t('EINST.N_OK'), mt_e($name)) . ' '
        . ($swas !== '' ? sprintf(mt_t('BRIDGE.S_GESICHERT'), mt_e(basename($swas))) . ' ' : '') . $satz);
}

/** Rueckweg beim Neuanlegen: den neuen (nur mit Bridge-Label) entfernen,
 *  den alten zurueckbenennen und, wenn er lief, starten. */
function mt_br_rueckweg($rest_name, $name, $war_an, $neu)
{
    if ($neu !== '') {
        $ist = mt_br_ist($neu);
        if ($ist !== null && $ist['label'] === mt_br_label()) {
            mt_docker('rm -f ' . escapeshellarg($neu), 60);
        }
    }
    list($ok, ) = mt_docker('rename ' . escapeshellarg($rest_name) . ' ' . escapeshellarg($name), 60);
    if (!$ok) {
        mt_log('Bridge: Rueckweg gescheitert - ' . $rest_name . ' liess sich nicht zurueckbenennen.');
        return sprintf(mt_t('EINST.N_RUECK_FEHL'), mt_e($rest_name), mt_e($name));
    }
    if ($war_an) {
        list($ok, ) = mt_docker('start ' . escapeshellarg($name), 60);
        return sprintf(mt_t($ok ? 'EINST.N_RUECK_OK' : 'EINST.N_RUECK_START_FEHL'), mt_e($name));
    }
    return sprintf(mt_t('EINST.N_RUECK_OK_AUS'), mt_e($name));
}

/* ---------------- synchron aus der Oberflaeche ---------------- */

/** Anhalten (docker stop -t 20), nur der eigene Container. */
function mt_br_anhalten()
{
    list($e, $g) = mt_br_eigen();
    if ($e === 2) {
        return array(0, mt_t('BRIDGE.V_KEINE'));
    }
    if ($e !== 1) {
        return array(0, mt_e(sprintf(mt_t('BRIDGE.V_NICHT_EIGEN'), mt_br_name(), $g)));
    }
    list($ok, $aus) = mt_docker('stop -t 20 ' . escapeshellarg(mt_br_name()), 40);
    mt_log('Bridge: ' . ($ok ? 'angehalten (Knopf).' : 'Anhalten gescheitert.'));
    return array($ok ? 1 : 0, $ok ? mt_t('BRIDGE.M_ANGEHALTEN') : mt_e(mt_br_log_filter(substr($aus, 0, 300))));
}

/**
 * Abbauen: Sicherung, Container entfernen; mit dem Pflichthaken auch die
 * Kopplungsdaten (alle Systeme muessen neu koppeln). Was der Container als
 * root geschrieben hat, entfernt ein Einmal-Container aus demselben Abbild.
 * Unter der Sperre des Hintergrundvorgangs. Rueckgabe array(ok, html).
 */
function mt_br_abbauen($daten)
{
    $p = mt_paths();
    $f = mt_br_pfade();
    if ($p['home'] === '') {
        return array(0, mt_t('EINST.ARCHIV_VERWEIGERT'));
    }
    $sperre = @fopen($p['datadir'] . '/container_vorgang.lock', 'c');
    if ($sperre === false || !flock($sperre, LOCK_EX | LOCK_NB) || mt_ct_vorgang_aktiv() !== null) {
        if ($sperre !== false) {
            fclose($sperre);
        }
        return array(0, mt_t('EINST.V_LAEUFT_SCHON'));
    }
    $teile = array();
    list($e, $g) = mt_br_eigen();
    if ($e === 0 || $e === -1) {
        flock($sperre, LOCK_UN);
        fclose($sperre);
        return array(0, mt_e(sprintf(mt_t('BRIDGE.V_NICHT_EIGEN'), mt_br_name(), $g)));
    }
    if ($e === 1) {
        mt_docker('stop -t 20 ' . escapeshellarg(mt_br_name()), 40);
    }
    list($sok, $swas) = mt_br_auto_sichern();
    if (!$sok) {
        flock($sperre, LOCK_UN);
        fclose($sperre);
        return array(0, sprintf(mt_t('EINST.N_SICHERUNG_FEHL'), $swas));
    }
    if ($swas !== '') {
        $teile[] = sprintf(mt_t('BRIDGE.S_GESICHERT'), mt_e(basename($swas)));
    }
    if ($e === 1) {
        list($ok, $aus) = mt_docker('rm -f ' . escapeshellarg(mt_br_name()), 60);
        $teile[] = $ok ? sprintf(mt_t('BRIDGE.M_ENTFERNT'), mt_e(mt_br_name())) : mt_e(mt_br_log_filter(substr($aus, 0, 200)));
    }
    if ($daten && is_dir($f['ordner'])) {
        list($da, ) = mt_docker('image inspect ' . escapeshellarg(mt_br_abbild()), 30);
        if ($da) {
            mt_docker('run --rm --label ' . mt_sh_arg(mt_br_label()) . ' --network none -v '
                . mt_sh_arg($f['ordner'] . ':/q') . ' --entrypoint rm ' . mt_sh_arg(mt_br_abbild())
                . ' -rf -- /q/Matterbridge /q/.matterbridge /q/.mattercert', 120);
        }
        @exec('rm -rf -- ' . escapeshellarg($f['ordner']) . ' 2>/dev/null');
        clearstatcache();
        $teile[] = is_dir($f['ordner']) ? sprintf(mt_t('BRIDGE.M_DATEN_REST'), mt_e($f['ordner'])) : mt_t('BRIDGE.M_DATEN_WEG');
        @unlink($f['waechter']);
        @unlink($f['fenster']);
    }
    flock($sperre, LOCK_UN);
    fclose($sperre);
    mt_log('Bridge: abgebaut' . ($daten ? ', Kopplungsdaten geloescht.' : ', Kopplungsdaten bleiben.'));
    return array(1, implode(' ', $teile) . ' ' . mt_t('BRIDGE.M_ABGEBAUT_HINWEIS'));
}

/* ---------------- Stufe 3: der minuetliche Waechter ---------------- */

/**
 * Von bin/bridge_waechter.php (ueber dienst.sh waechter) jede Minute.
 * Ohne bridge.json kein einziger Docker-Aufruf. Zaehlt die Anmeldefehler
 * seit dem letzten Lauf und haelt beim 3. seit dem letzten Erfolg an.
 * Schliesst ein abgelaufenes Kopplungsfenster. Startet NIE etwas.
 * Rueckgabe: ein Satz fuer die Pruefung (kein Geheimnis).
 */
function mt_br_waechter()
{
    $f = mt_br_pfade();
    $p = mt_paths();
    if ($p['home'] === '' || !is_file($f['cfg'])) {
        return 'keine Bridge eingerichtet';
    }
    $fe = mt_json_lesen($f['fenster']);
    if (isset($fe['ende']) && (int) $fe['ende'] <= time()) {
        $a = mt_br_api('fenster_zu');
        @unlink($f['fenster']);
        mt_log('Bridge: Kopplungsfenster nach Ablauf geschlossen' . (!empty($a['ok']) ? '.' : ' (die Bridge antwortete nicht).'));
    }
    $sperre = @fopen($p['datadir'] . '/container_vorgang.lock', 'c');
    if ($sperre !== false && !flock($sperre, LOCK_EX | LOCK_NB)) {
        fclose($sperre);
        return 'Vorgang laeuft - Stufe 2 beobachtet';
    }
    $erg = mt_br_waechter_zaehlen($f);
    if ($sperre !== false) {
        flock($sperre, LOCK_UN);
        fclose($sperre);
    }
    return $erg;
}

function mt_br_waechter_zaehlen(array $f)
{
    if (!mt_docker_da()) {
        return 'kein Docker';
    }
    $ist = mt_br_ist();
    if ($ist === null || $ist['label'] !== mt_br_label()) {
        return 'kein eigener Bridge-Container';
    }
    if (!$ist['laeuft']) {
        @unlink($f['waechter']);
        return 'Bridge steht';
    }
    $w = mt_json_lesen($f['waechter']);
    /* Fenster [seit, jetzt): docker logs --since ist einschliessend, --until
     * ausschliessend; mit ganzen Sekunden ueberlappen zwei Laeufe so nie. */
    $jetzt = time();
    $seit = isset($w['bis']) && (int) $w['bis'] > $jetzt - 600 && (int) $w['bis'] <= $jetzt ? (int) $w['bis'] : $jetzt - 70;
    list($ok, $log) = mt_docker('logs --since ' . $seit . ' --until ' . $jetzt . ' --tail 3000 ' . escapeshellarg(mt_br_name()), 30);
    if (!$ok) {
        return 'Protokoll nicht lesbar';
    }
    list($fehler, $erfolg, $letzte) = mt_br_zaehlen($log);
    $summe = isset($w['summe']) ? (int) $w['summe'] : 0;
    if ($erfolg > 0 && $fehler === 0) {
        $summe = 0;
    } else {
        $summe += $fehler;
    }
    mt_json_schreiben($f['waechter'], array('summe' => $summe, 'bis' => $jetzt, 'letzte' => $letzte), 0600);
    if ($summe >= mt_br_schwelle_betrieb()) {
        mt_br_sperren(3, $summe, $letzte);
        return 'angehalten (' . $summe . ' Anmeldefehler seit dem letzten Erfolg)';
    }
    return $summe . ' Anmeldefehler seit dem letzten Erfolg';
}

/* ---------------- Ampel (Entwurf 2.7) ---------------- */

/**
 * Vier Zeilen, je array(farbe, html): container, anmeldung, geraete,
 * kopplung. Unbekannt ist grau, nie gruen. Dazu 'api' (geprueft) und 'ist'.
 */
function mt_br_ampel($dlage)
{
    $c = mt_br_cfg();
    $a = array('api' => null, 'ist' => null, 'zustand' => 'kein');
    if (!is_array($dlage) || $dlage[0] !== 'ok') {
        $a['container'] = array('grau', mt_e(is_array($dlage) && $dlage[1] !== '' ? $dlage[1] : mt_t('BRIDGE.A_UNGEMESSEN')));
    } else {
        list($zu, $grund, $ist) = mt_br_zustand();
        $a['zustand'] = $zu;
        $a['ist'] = $ist;
        if ($zu === 'laeuft') {
            if ($ist['oom']) {
                $a['container'] = array('rot', mt_t('BRIDGE.A_C_OOM'));
            } elseif ($ist['health'] === 'unhealthy') {
                $a['container'] = array('rot', mt_t('BRIDGE.A_C_UNHEALTHY'));
            } elseif ($ist['health'] === 'healthy') {
                $a['container'] = array('gruen', sprintf(mt_t('BRIDGE.A_C_LAEUFT'), mt_e(mt_br_name())));
            } else {
                $a['container'] = array('grau', sprintf(mt_t('BRIDGE.A_C_STARTET'), mt_e(mt_br_name())));
            }
        } elseif ($zu === 'steht') {
            $a['container'] = array('rot', sprintf(mt_t('BRIDGE.A_C_STEHT'), mt_e(mt_br_name())));
        } elseif ($zu === 'kein') {
            $a['container'] = array('grau', mt_t($c['bridge_ein'] ? 'BRIDGE.A_C_KEINER' : 'BRIDGE.A_C_AUS'));
        } else {
            $a['container'] = array('grau', mt_e($grund));
        }
    }
    $s = mt_br_gesperrt();
    $an = mt_br_anmeldung();
    $w = mt_json_lesen(mt_br_pfade()['waechter']);
    if ($s !== null) {
        $a['anmeldung'] = array('rot', sprintf(mt_t('BRIDGE.A_A_GESPERRT'), date('d.m.Y H:i', (int) $s['ts']), (int) $s['zahl'], (int) $s['stufe']));
    } elseif (!empty($w['summe'])) {
        $a['anmeldung'] = array('rot', sprintf(mt_t('BRIDGE.A_A_FEHLER'), (int) $w['summe'], mt_br_schwelle_betrieb()));
    } elseif (mt_br_anmeldung_gilt()) {
        $a['anmeldung'] = array('gruen', sprintf(mt_t('BRIDGE.A_A_OK'), date('d.m.Y H:i', (int) $an['ts']), (int) $an['anzahl']));
    } elseif (isset($an['ergebnis']) && $an['ergebnis'] !== '' && $an['ergebnis'] !== 'ok') {
        $a['anmeldung'] = array('rot', mt_t('BRIDGE.T_' . strtoupper($an['ergebnis'])));
    } else {
        $a['anmeldung'] = array('grau', mt_t('BRIDGE.A_A_UNBEKANNT'));
    }
    $api = null;
    if ($a['zustand'] === 'laeuft') {
        $api = mt_br_zustand_pruefen(mt_br_api('zustand'));
        $a['api'] = $api;
    }
    if ($api === null || !$api['ok']) {
        $a['geraete'] = array('grau', mt_t($api === null ? 'BRIDGE.A_G_OHNE' : 'BRIDGE.A_G_STUMM'));
        $a['kopplung'] = array('grau', mt_t($api === null ? 'BRIDGE.A_G_OHNE' : 'BRIDGE.A_G_STUMM'));
    } else {
        $a['geraete'] = $api['geraete'] === null ? array('grau', mt_t('BRIDGE.A_G_STUMM'))
            : array($api['geraete'] > 0 ? 'gruen' : 'rot', sprintf(mt_t('BRIDGE.A_G_ZAHL'), (int) $api['geraete'], count(mt_br_cfg()['auswahl'])));
        if ($api['commissioned'] === true && $api['fabrics']) {
            $namen = array();
            foreach ($api['fabrics'] as $fb) {
                $namen[] = $fb['hersteller'] !== '' ? $fb['hersteller'] : ($fb['hersteller_id'] !== null ? sprintf('0x%04X', $fb['hersteller_id']) : '?');
            }
            $a['kopplung'] = array('gruen', sprintf(mt_t('BRIDGE.A_K_JA'), count($api['fabrics']), mt_e(implode(', ', $namen))));
        } elseif ($api['commissioned'] === false) {
            $a['kopplung'] = array('grau', mt_t('BRIDGE.A_K_NEIN'));
        } else {
            $a['kopplung'] = array('grau', mt_t('BRIDGE.A_G_STUMM'));
        }
    }
    return $a;
}

/** Die Pruefzeilen von Schritt 1: array(stand 1/0/-1, html). */
function mt_br_voraussetzungen($dlage, $laeuft)
{
    $z = array();
    $z[] = (is_array($dlage) && $dlage[0] === 'ok') ? array(1, mt_e($dlage[1])) : array(0, mt_e(is_array($dlage) ? $dlage[1] : ''));
    $v6 = mt_ipv6_zustand();
    $z[] = array($v6['ok'] ? 1 : 0, mt_e($v6['text']));
    foreach (array(array(mt_br_matter_port(), true, 'UDP'), array(mt_br_frontend_port(), false, 'TCP')) as $pt) {
        $b = mt_br_port_belegt($pt[0], $pt[1]);
        if ($b === -1) {
            $z[] = array(-1, sprintf(mt_t('BRIDGE.P_PORT_UNBEKANNT'), $pt[0], $pt[2]));
        } elseif ($b === 1) {
            $z[] = array($laeuft ? 1 : 0, sprintf(mt_t($laeuft ? 'BRIDGE.P_PORT_BRIDGE' : 'BRIDGE.P_PORT_BELEGT'), $pt[0], $pt[2]));
        } else {
            $z[] = array(1, sprintf(mt_t('BRIDGE.P_PORT_FREI'), $pt[0], $pt[2]));
        }
    }
    $mb = mt_br_speicher_frei();
    $z[] = $mb < 0 ? array(-1, mt_t('BRIDGE.P_SPEICHER_UNBEKANNT'))
        : array($mb >= 400 ? 1 : 0, sprintf(mt_t($mb >= 400 ? 'BRIDGE.P_SPEICHER_OK' : 'BRIDGE.P_SPEICHER_KNAPP'), $mb));
    return $z;
}
