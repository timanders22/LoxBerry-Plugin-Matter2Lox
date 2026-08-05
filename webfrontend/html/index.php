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
 *   status  [&geraet=N]      alle uebersetzten Werte eines Geraets
 *   wert    &geraet=N&endpunkt=E&thema=T   ein einzelner Wert, blank
 *   liste                    alle Geraete
 *   roh                      vollstaendiges Abbild als JSON
 *
 * Schaltend (nur wenn im Reiter Einstellungen zugelassen):
 *   ein | aus | umschalten   &geraet=N[&endpunkt=E]
 *   helligkeit    &wert=0..100
 *   farbtemperatur &wert=<Kelvin>
 *   rollo         &wert=0..100     (0 = ganz offen)
 *   rollo_auf | rollo_zu | rollo_stopp
 *   soll_heizen | soll_kuehlen  &wert=<Grad>
 *   betriebsart   &wert=0..9
 *   attribut      &pfad=E/C/A&wert=...
 *   befehl        &cluster=N&name=<Name>[&nutzlast=<JSON>]
 *   abruf
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
$mt_lesend = array('status', 'wert', 'liste', 'roh');
$mt_schaltend = array('ein', 'aus', 'umschalten', 'helligkeit', 'farbtemperatur',
                      'rollo', 'rollo_auf', 'rollo_zu', 'rollo_stopp',
                      'soll_heizen', 'soll_kuehlen', 'betriebsart',
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
$mt_g = isset($mt_alle[$mt_nr]) ? $mt_alle[$mt_nr] : null;

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

if ($mt_aktion !== 'abruf' && empty($mt_cfg['steuerung_ein'])) {
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
                               'soll_heizen', 'soll_kuehlen', 'betriebsart'), true)) {
    if ($mt_wert === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=WERT_FEHLT\n";
        exit;
    }
    $mt_befehl['wert'] = (float) str_replace(',', '.', $mt_wert);
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
