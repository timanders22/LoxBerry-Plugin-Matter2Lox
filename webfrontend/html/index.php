<?php
/**
 * Matter to Loxone - Endpunkt fuer den Miniserver
 *
 * Liegt im unangemeldeten Bereich, damit Loxone ihn ohne Zugangsdaten
 * erreicht, und ist deshalb durch ein Token geschuetzt. Verglichen wird mit
 * hash_equals, also in gleichbleibender Zeit.
 *
 *   /plugins/<ordner>/index.php?token=<TOKEN>&aktion=<Befehl>
 *
 * Lesend:
 *   status     [&geraet=N]   alle uebersetzten Werte eines Geraets
 *   statusalle               alle Geraete in EINER Zeile, Marken mit
 *                            Geraetenummer (MATTER_3_1_TEMPERATUR) - fuer die
 *                            Sammelvorlage, die nur eine Adresse abfragen kann
 *   wert    &geraet=N&endpunkt=E&thema=T   ein einzelner Wert, blank
 *   liste                    alle Geraete
 *   roh                      vollstaendiges Abbild als JSON
 *
 * Jedes Geraet ist auf zwei Wegen ansprechbar:
 *   &geraet=N                die Geraetenummer des Plugins. Seit 0.9.10 fest -
 *                            die Zuordnung steht in data/.../nummern.json und
 *                            verschiebt sich nicht mehr, wenn ein Geraet aus
 *                            der Fabric faellt.
 *   &knoten=M                die Knotennummer des Matter-Servers. Haengt an
 *                            keiner Zaehlung des Plugins. Gewinnt, wenn beides
 *                            angegeben ist.
 *
 * Geraeteunabhaengig (braucht keine Steuerungsfreigabe):
 *   abruf                    ohne Geraeteangabe: Bestand neu holen
 *                            (get_nodes). Mit &geraet= oder &knoten=: diesen
 *                            einen Knoten neu auslesen (interview_node).
 *
 * Schaltend (nur wenn im Reiter Einstellungen zugelassen):
 *   ein | aus | umschalten   &geraet=N[&endpunkt=E]
 *   helligkeit    &wert=0..100
 *   farbtemperatur &wert=<Kelvin>
 *   farbton       &wert=0..360     (Grad)
 *   saettigung    &wert=0..100
 *   farbe         &wert=<Farbton 0..360>[&saettigung=0..100]
 *   rollo         &wert=0..100     (0 = ganz offen)
 *   rollo_auf | rollo_zu | rollo_stopp
 *   soll_heizen | soll_kuehlen  &wert=<Grad>
 *   betriebsart   &wert=0..9
 *   luefter       &wert=0..100     (Sollwert)
 *   identify      [&wert=<Sekunden>]  Geraet macht sich bemerkbar
 *   attribut      &pfad=E/C/A&wert=...
 *   befehl        &cluster=N&name=<Name>[&nutzlast=<JSON>]
 *
 * Schaltend, aber mit EIGENEM Haken (Reiter Einstellungen):
 *   sperren | entsperren     Tuerschloss. Bewusst nicht an der allgemeinen
 *                            Steuerungsfreigabe: wer Lampen schalten laesst,
 *                            will damit nicht die Haustuer freigeben.
 *
 * Der Endpunkt spricht NIE selbst mit dem Matter-Server. Lesende Aufrufe
 * beantwortet er aus dem Zwischenspeicher, schaltende legt er in einer
 * Warteschlange ab, die der Dienst abarbeitet.
 *
 * Ein Strich als Wert bedeutet: dieses Feld gibt es bei diesem Geraet nicht.
 * Es wird bewusst keine 0 gesendet - eine 0 waere eine stille Falschaussage.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/mt_lib.php';
header('Content-Type: text/plain; charset=utf-8');

$mt_cfg = mt_config();

/* ---------------- Token ---------------- */
$mt_soll = (string) $mt_cfg['aktionstoken'];
$mt_ist = isset($_GET['token']) ? (string) $_GET['token'] : '';
if ($mt_soll === '') {
    http_response_code(403);
    echo "FEHLER;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
    echo "Die Plugin-Oberflaeche wurde noch nie geoeffnet - es gibt noch kein Token.\n";
    exit;
}
if (!hash_equals($mt_soll, $mt_ist)) {
    http_response_code(403);
    echo "FEHLER;OK=0;GRUND=TOKEN\n";
    exit;
}

/* ---------------- Aktion (Weissliste) ---------------- */
$mt_lesend = array('status', 'statusalle', 'wert', 'liste', 'roh');
$mt_schaltend = array('ein', 'aus', 'umschalten', 'helligkeit', 'farbtemperatur',
                      'farbe', 'farbton', 'saettigung',
                      'rollo', 'rollo_auf', 'rollo_zu', 'rollo_stopp',
                      'soll_heizen', 'soll_kuehlen', 'betriebsart', 'luefter',
                      'sperren', 'entsperren', 'identify',
                      'attribut', 'befehl', 'abruf');
$mt_aktion = isset($_GET['aktion']) ? (string) $_GET['aktion'] : 'status';
if (!in_array($mt_aktion, array_merge($mt_lesend, $mt_schaltend), true)) {
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=UNBEKANNTE_AKTION\n";
    echo 'Erlaubt sind: ' . implode(', ', array_merge($mt_lesend, $mt_schaltend)) . "\n";
    exit;
}

/* ---------------- Parameter ----------------
 * Was nicht ins Muster passt, wird abgewiesen und gemeldet. Nie Zeichen
 * entfernen, nie zurechtbiegen.
 */
function mt_param($name, $muster, $vorgabe = '')
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

$mt_nr       = mt_param('geraet', '/^[0-9]{1,3}$/', '1');
/* Die Knotennummer als dauerhafte Adresse.
 *
 * &geraet= ist seit 0.9.10 stabil (die Zuordnung steht in nummern.json und
 * wird nie veraendert). Wer ganz sichergehen will, adressiert ueber &knoten=
 * - das ist die Nummer, die der Matter-Server selbst vergeben hat, und sie
 * haengt an keiner Zaehlung des Plugins. Ist beides angegeben, gewinnt
 * &knoten=. */
$mt_knoten   = mt_param('knoten', '/^[0-9]{1,20}$/', '');
$mt_endpunkt = mt_param('endpunkt', '/^[0-9]{1,3}$/', '1');
$mt_wert     = mt_param('wert', '/^-?[0-9]{1,6}([.,][0-9]{1,3})?$/', '');
$mt_thema    = mt_param('thema', '/^[a-z0-9_]{1,40}$/', '');
$mt_pfad     = mt_param('pfad', '#^[0-9]{1,3}/[0-9]{1,5}/[0-9]{1,5}$#', '');
$mt_cluster  = mt_param('cluster', '/^[0-9]{1,5}$/', '');
$mt_name     = mt_param('name', '/^[A-Za-z][A-Za-z0-9]{0,48}$/', '');
$mt_nutzlast = isset($_GET['nutzlast']) ? (string) $_GET['nutzlast'] : '';

function mt_w($v)
{
    if ($v === null || $v === '' || !is_numeric($v)) {
        return '-';
    }
    return (string) (0 + $v);
}

$mt_lox = mt_loxone();
$mt_alle = mt_geraete();
$mt_alter = mt_alter();
if ($mt_knoten !== '') {
    $mt_g = null;
    foreach ($mt_alle as $mt_k => $mt_kandidat) {
        if (isset($mt_kandidat['node_id']) && (string) $mt_kandidat['node_id'] === $mt_knoten) {
            $mt_g = $mt_kandidat;
            $mt_nr = (string) $mt_k;
            break;
        }
    }
} else {
    $mt_g = isset($mt_alle[$mt_nr]) ? $mt_alle[$mt_nr] : null;
}

/* ================= Lesende Aktionen ================= */

if ($mt_aktion === 'roh') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($mt_lox, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($mt_aktion === 'liste') {
    $srv = mt_serverinfo();
    printf("LISTE;OK=%d;N=%d;ALTER=%d;SDK=%s;BLUETOOTH=%s\n",
        (int) (!empty($mt_lox['ok'])), count($mt_alle), $mt_alter,
        isset($srv['sdk_version']) ? $srv['sdk_version'] : '-',
        isset($srv['bluetooth']) ? (int) $srv['bluetooth'] : 0);
    foreach ($mt_alle as $nr => $g) {
        echo $nr . ';' . $g['name'] . ';Knoten=' . (int) $g['node_id']
           . ';Erreichbar=' . (int) $g['erreichbar']
           . ';Endpunkte=' . count((array) $g['endpunkte']) . "\n";
    }
    exit;
}

if ($mt_aktion === 'statusalle') {
    /* Alle Geraete in EINER Zeile - fuer die Sammelvorlage.
     *
     * Eine XML-Vorlage hat nur ein Wurzelelement und damit nur eine Adresse.
     * Ohne diesen Endpunkt braeuchte man je Geraet eine eigene Datei und
     * einen eigenen virtuellen Eingang. Die Marken tragen die Geraetenummer
     * im Namen (MATTER_3_1_TEMPERATUR), damit sie eindeutig bleiben.
     *
     * OK, ERREICH und ALTER stehen einmal am Anfang und gelten fuer das
     * Abbild als Ganzes. ERREICH ist hier 1, wenn ALLE Geraete erreichbar
     * sind - ein einzelnes stummes Geraet faellt sonst nicht auf.
     */
    $mt_alleda = count($mt_alle) > 0;
    foreach ($mt_alle as $g) {
        if (empty($g['erreichbar'])) { $mt_alleda = false; break; }
    }
    $teile = array(
        'MATTER;OK=' . (int) (!empty($mt_lox['ok'])),
        'ERREICH=' . (int) $mt_alleda,
        'ALTER=' . $mt_alter,
    );
    foreach ($mt_alle as $nr => $g) {
        foreach ((array) $g['endpunkte'] as $ep => $felder) {
            foreach ((array) $felder as $thema => $w) {
                $teile[] = 'MATTER_' . (int) $nr . '_' . strtoupper($ep . '_' . $thema)
                         . '=' . mt_w($w);
            }
        }
    }
    echo implode(';', $teile) . "\n";
    exit;
}

/* ================= Geraeteunabhaengige Aktionen =================
 *
 * MUSS vor der Pruefung auf $mt_g stehen. 'abruf' stoesst einen Sofortabruf
 * ALLER Knoten an - es gilt keinem einzelnen Geraet, und der Dienst liest die
 * Knotennummer dafuer gar nicht aus (matter_dienst.py, befehl_ausfuehren:
 * 'abruf' kehrt zurueck, bevor node_id ueberhaupt gelesen wird).
 *
 * Bis 0.9.1 stand der Abschnitt HINTER der Pruefung $mt_g === null. Da
 * &geraet= ohne Angabe auf 1 steht, brach der Aufruf mit GERAET_UNBEKANNT ab,
 * sobald das Abbild kein Geraet 1 enthielt. Nachgestellt:
 *
 *   ohne Abbild (frisch installiert):
 *     MATTER;OK=0;GRUND=GERAET_UNBEKANNT;N=0;ALTER=-1
 *   Dienst laeuft, Verbindung zum Matter-Server verloren, geraete leer:
 *     MATTER;OK=0;GRUND=GERAET_UNBEKANNT;N=0;ALTER=...
 *
 * Der zweite Fall ist der wunde Punkt: Die Geraete stehen in der Fabric, nur
 * der Zwischenspeicher ist leer - also genau die Lage, in der man 'abruf'
 * aufruft. Der Befehl, der die Lage beheben soll, war dann gesperrt.
 *
 * Dass es ein Versehen war und keine Absicht, zeigt der Abschnitt darunter:
 * dort ist 'abruf' seit jeher von der Steuerungsfreigabe ausgenommen
 * ($mt_aktion !== 'abruf'). An einer Stelle geraeteunabhaengig behandelt, an
 * der anderen nicht.
 */
$mt_global = array('abruf');

if (in_array($mt_aktion, $mt_global, true)) {
    if (mt_dienst_pid() === 0) {
        http_response_code(503);
        echo "SET;OK=0;GRUND=DIENST_LAEUFT_NICHT\n";
        echo "Der Dienst laeuft nicht. Reiter Einstellungen, Knopf 'Dienst starten'.\n";
        exit;
    }
    /* Ohne Geraeteangabe wird der ganze Bestand neu geholt, mit Angabe genau
     * ein Knoten neu ausgelesen. Ein Interview dauert laenger als ein
     * Bestandsabgleich - deshalb bekommt der Einzelfall mehr Zeit. */
    $mt_auftrag = array('aktion' => $mt_aktion);
    $mt_frist = null;
    if ($mt_g !== null && (isset($_GET['geraet']) || $mt_knoten !== '')) {
        $mt_auftrag['knoten'] = (int) $mt_g['node_id'];
        $mt_frist = 20;
    }
    list($mt_erg, $mt_meldung) = mt_befehl_absetzen($mt_auftrag, $mt_frist);
    if ($mt_erg === 0) {
        http_response_code(500);
    }
    printf("SET;OK=%d;AKTION=%s;MELDUNG=%s\n", $mt_erg, $mt_aktion,
        str_replace(array("\r", "\n", ';'), ' ', $mt_meldung));
    exit;
}

if ($mt_g === null) {
    printf("MATTER;OK=0;GRUND=GERAET_UNBEKANNT;N=%d;ALTER=%d\n", count($mt_alle), $mt_alter);
    exit;
}

if ($mt_aktion === 'wert') {
    /* Ein einzelner Wert, blank ausgegeben. Fuer Loxone der bequemste Weg:
     * ein virtueller HTTP-Eingang ohne Befehlserkennung nimmt die Zahl direkt. */
    if ($mt_thema === '') {
        http_response_code(400);
        echo "-\n";
        exit;
    }
    $ep = (array) (isset($mt_g['endpunkte'][$mt_endpunkt]) ? $mt_g['endpunkte'][$mt_endpunkt] : array());
    echo (isset($ep[$mt_thema]) ? mt_w($ep[$mt_thema]) : '-') . "\n";
    exit;
}

if ($mt_aktion === 'status') {
    $teile = array(
        'MATTER;OK=' . (int) (!empty($mt_lox['ok'])),
        'ERREICH=' . (int) $mt_g['erreichbar'],
        'ALTER=' . $mt_alter,
    );
    foreach ((array) $mt_g['endpunkte'] as $ep => $felder) {
        foreach ((array) $felder as $thema => $w) {
            $teile[] = strtoupper($ep . '_' . $thema) . '=' . mt_w($w);
        }
    }
    echo implode(';', $teile) . "\n";
    exit;
}

/* ================= Schaltende Aktionen ================= */

/* 'abruf' wird hier NICHT mehr geprueft - es kommt gar nicht bis hierher,
 * sondern wird oben unter den geraeteunabhaengigen Aktionen erledigt. Die
 * frueher noetige Ausnahme ($mt_aktion !== 'abruf') ist damit entfallen. */
if (empty($mt_cfg['steuerung_ein'])) {
    http_response_code(403);
    echo "SET;OK=0;GRUND=STEUERUNG_AUS\n";
    echo "Schreibende Befehle sind gesperrt. Reiter Einstellungen, Haken 'Schreibende Befehle zulassen'.\n";
    exit;
}
if (mt_dienst_pid() === 0) {
    http_response_code(503);
    echo "SET;OK=0;GRUND=DIENST_LAEUFT_NICHT\n";
    echo "Der Dienst laeuft nicht. Reiter Einstellungen, Knopf 'Dienst starten'.\n";
    exit;
}

$mt_befehl = array(
    'aktion'   => $mt_aktion,
    'knoten'   => (int) $mt_g['node_id'],
    'endpunkt' => (int) $mt_endpunkt,
);
if (in_array($mt_aktion, array('helligkeit', 'farbtemperatur', 'rollo',
                               'soll_heizen', 'soll_kuehlen', 'betriebsart',
                               'farbe', 'farbton', 'saettigung', 'luefter'), true)) {
    if ($mt_wert === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=WERT_FEHLT\n";
        exit;
    }
    $mt_befehl['wert'] = (float) str_replace(',', '.', $mt_wert);
    // Bei 'farbe' darf zusaetzlich die Saettigung mitkommen. Fehlt sie, setzt
    // der Dienst 100 % - das ist die volle Farbe, nicht Weiss.
    if ($mt_aktion === 'farbe') {
        $mt_saet = mt_param('saettigung', '/^[0-9]{1,3}$/', '');
        if ($mt_saet !== '') {
            $mt_befehl['saettigung'] = (int) $mt_saet;
        }
    }
} elseif ($mt_aktion === 'identify') {
    // Ohne Angabe macht sich das Geraet 15 s lang bemerkbar.
    if ($mt_wert !== '') {
        $mt_befehl['wert'] = (float) str_replace(',', '.', $mt_wert);
    }
} elseif ($mt_aktion === 'attribut') {
    if ($mt_pfad === '' || $mt_wert === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=PFAD_ODER_WERT_FEHLT\n";
        exit;
    }
    $mt_befehl['pfad'] = $mt_pfad;
    $mt_befehl['wert'] = (float) str_replace(',', '.', $mt_wert) == (int) $mt_wert
        ? (int) $mt_wert : (float) str_replace(',', '.', $mt_wert);
} elseif ($mt_aktion === 'befehl') {
    if ($mt_cluster === '' || $mt_name === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=CLUSTER_ODER_NAME_FEHLT\n";
        exit;
    }
    $mt_befehl['cluster'] = (int) $mt_cluster;
    $mt_befehl['name'] = $mt_name;
    if ($mt_nutzlast !== '') {
        // Die Nutzlast wird NICHT gefiltert, sondern nur auf gueltiges JSON
        // geprueft - ein hartes Filtern zerstoerte gueltige Nutzlasten.
        if (json_decode($mt_nutzlast, true) === null && strtolower(trim($mt_nutzlast)) !== 'null') {
            http_response_code(400);
            echo "SET;OK=0;GRUND=NUTZLAST_KEIN_JSON\n";
            exit;
        }
        $mt_befehl['nutzlast'] = $mt_nutzlast;
    }
}

list($mt_erg, $mt_meldung) = mt_befehl_absetzen($mt_befehl);
if ($mt_erg === 0) {
    http_response_code(500);
}
printf("SET;OK=%d;AKTION=%s;MELDUNG=%s\n", $mt_erg, $mt_aktion,
    str_replace(array("\r", "\n", ';'), ' ', $mt_meldung));
