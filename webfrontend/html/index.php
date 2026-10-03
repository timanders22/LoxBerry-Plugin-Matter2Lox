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
 * Lesetoken (0.9.35, Nr. 8): ist in der Konfiguration ein lesetoken gesetzt,
 * oeffnet es die lesenden Aktionen (status, statusalle, wert, liste, roh)
 * und den Selbsttest - alle anderen verlangen das Aktionstoken (sonst 403
 * GRUND=NUR_LESETOKEN). Ein leeres Lesetoken gilt nie; ein leeres
 * Aktionstoken schliesst den Endpunkt ganz, auch fuer das Lesetoken.
 *
 * Ohne Wirkung, nur zur Auskunft:
 *   ?selftest=1&token=<TOKEN>   drei Ausgaenge, kein Geraetekontakt,
 *                            kein Schreibzugriff:
 *                              richtiges Token   200 SELFTEST;OK=1;TOKEN=OK
 *                              Lesetoken         200 SELFTEST;OK=1;TOKEN=OK;
 *                                                    ART=LESEN
 *                              falsches Token    403 SELFTEST;OK=0;ERR=TOKEN
 *                              keines gesetzt    403 SELFTEST;OK=0;
 *                                                    ERR=KEIN_TOKEN_EINGERICHTET
 *
 * Jedes Geraet ist auf zwei Wegen ansprechbar:
 *   &geraet=N                die Geraetenummer des Plugins. Seit 0.9.10 fest -
 *                            die Zuordnung steht seit 0.9.17 NEBEN dem
 *                            Datenordner (data/plugins/<ordner>.nummern.json)
 *                            und ueberlebt damit ein Plugin-Update; bis 0.9.16
 *                            lag sie darin und wurde bei jedem Update
 *                            geloescht.
 *   &knoten=M                die Knotennummer des Matter-Servers. Haengt an
 *                            keiner Zaehlung des Plugins. Gewinnt, wenn beides
 *                            angegeben ist.
 *
 * Geraeteunabhaengig (braucht keine Steuerungsfreigabe):
 *   abruf                    ohne Geraeteangabe: Bestand neu holen
 *                            (get_nodes). Mit &geraet= oder &knoten=: diesen
 *                            einen Knoten neu auslesen (interview_node). Ein
 *                            angegebenes, aber unbekanntes Geraet ergibt 400
 *                            GRUND=GERAET_UNBEKANNT; ein leeres &geraet= gilt
 *                            als nicht angegeben (0.9.35, Nr. 7).
 *
 * Schaltend (nur wenn im Reiter Einstellungen zugelassen):
 *   ein | aus | umschalten   &geraet=N[&endpunkt=E]
 *   helligkeit    &wert=0..100     (0 = aus)
 *   farbtemperatur &wert=<Kelvin>
 *   farbton       &wert=0..360     (Grad)
 *   saettigung    &wert=0..100
 *   farbe         &wert=<Farbton 0..360>[&saettigung=0..100]
 *   loxfarbe      &wert=0..299999999  Ausgang des Loxone-Lichtbausteins:
 *                                  BBBGGGRRR (je 0..100) oder Lumitech
 *                                  20bbbtttt (Helligkeit, Kelvin)
 *   farbe_xy      &x=0..1&y=0..1   Farbort (Punkt oder Komma)
 *   rollo         &wert=0..100     (0 = ganz offen)
 *   lamelle       &wert=0..100     Lamellenstellung (0 = ganz offen)
 *   rollo_auf | rollo_zu | rollo_stopp
 *   soll_heizen | soll_kuehlen  &wert=<Grad>
 *   betriebsart   &wert=0..9
 *   luefter       &wert=0..100     (Sollwert)
 *   identify      [&wert=<Sekunden>]  Geraet macht sich bemerkbar
 *   attribut      &pfad=E/C/A&wert=...
 *   befehl        &cluster=N&name=<Name>[&nutzlast=<JSON-Objekt>]
 *
 *   Rohwege (0.9.35, Nr. 6): attribut und befehl auf Cluster 257 (DoorLock)
 *   verlangen zusaetzlich die Schlossfreigabe (403 GRUND=SCHLOSS_AUS); die
 *   Cluster 31, 48, 49, 60, 62 und 63 (Zugriffsrechte, Inbetriebnahme,
 *   Netzwerk, Anlernfenster, Zertifikate, Gruppenschluessel) sind im Rohweg
 *   gesperrt (403 GRUND=CLUSTER_GESPERRT). Die nutzlast muss ein
 *   JSON-Objekt sein (400 GRUND=NUTZLAST_KEIN_OBJEKT).
 *
 *   Gleichwert-Unterdrueckung (X-7, B-Nachzug 01.10.2026): ein Sollwert-
 *   Befehl (ein, aus, helligkeit, farbtemperatur, farbe, farbton,
 *   saettigung, loxfarbe, farbe_xy, rollo, lamelle, soll_heizen,
 *   soll_kuehlen, betriebsart, luefter) mit DEMSELBEN Wert fuer dasselbe
 *   Geraet innerhalb von 60 s wird nicht erneut gesendet: 200
 *   SET;OK=1;AKTION=..;UNVERAENDERT=1. Ein anderer Wert geht sofort hinaus.
 *   umschalten, rollo_auf/_zu/_stopp, identify, attribut und befehl gehen
 *   immer hinaus. Laesst sich der Merker nicht oeffnen, antwortet ein
 *   Sollwert-Befehl 503 GRUND=GLEICHWERT_MERKER und sendet nichts; haelt ein
 *   anderer Aufruf dasselbe Geraet laenger als etwa 2 s, 503
 *   GRUND=BESCHAEFTIGT (0.9.35, Nr. 4).
 *
 *   Antwort: SET;OK=<0|1|2>;AKTION=..[;IST=<wert>];MELDUNG=..
 *   OK=2 heisst: ohne Ergebnis (eingereiht ohne Warten, noch in Arbeit, oder
 *   vom Dienst als von einem neueren Befehl desselben Geraets ueberholt
 *   gemeldet). IST (0.9.35, Nr. 9) steht nur da, wenn der Dienst den
 *   Ist-Wert nach dem Befehl mitliefert, z. B. IST=1 nach sperren.
 *
 * Schaltend, mit einem ZWEITEN, eigenen Haken (Reiter Einstellungen):
 *   sperren | entsperren     Tuerschloss. Verlangt BEIDE Freigaben - die
 *                            allgemeine und die fuer Schloesser. Wer Lampen
 *                            schalten laesst, hat damit die Haustuer noch
 *                            nicht freigegeben.
 *                            (Bis 0.9.16 stand hier "bewusst nicht an der
 *                            allgemeinen Steuerungsfreigabe". Das war falsch:
 *                            der Dienst prueft steuerung_ein vor schloss_ein,
 *                            und dieser Endpunkt tut es ebenfalls.)
 *                            0.9.35 (Nr. 6): der Endpunkt prueft schloss_ein
 *                            jetzt selbst (403 GRUND=SCHLOSS_AUS) - bis
 *                            0.9.34 stand das hier, geprueft hat es nur der
 *                            Dienst.
 *                            Schlossbremse siehe unten (0.9.35, Nr. 1).
 *
 * Der Endpunkt spricht NIE selbst mit dem Matter-Server. Lesende Aufrufe
 * beantwortet er aus dem Zwischenspeicher, schaltende legt er in einer
 * Warteschlange ab, die der Dienst abarbeitet.
 *
 * Ein Strich als Wert bedeutet: dieses Feld gibt es bei diesem Geraet nicht.
 * Es wird bewusst keine 0 gesendet - eine 0 waere eine stille Falschaussage.
 *
 * OK (seit 0.9.30, C3, Entscheidung 4): 1 nur, wenn das Abbild eine
 * Verbindung zum Matter-Server meldet UND der Herzschlag des Dienstes
 * hoechstens 3 x Takt alt ist (mt_ok_endpunkt()). ALTER bleibt daneben
 * unveraendert das Alter des Abbilds.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/mt_lib.php';
header('Content-Type: text/plain; charset=utf-8');

/* Der unangemeldete Bereich darf NICHTS anlegen.
 *
 * Bis 0.9.16 stand hier ein blankes mt_config(). Das legt bei Bedarf den
 * Konfigordner an, schreibt die Zweitschrift zurueck, kopiert eine
 * beschaedigte Datei nach .kaputt und erzeugt dabei Protokoll- und
 * Merkerdateien - alles VOR der Tokenpruefung. Gemessen: ein Aufruf ohne
 * Token hinterliess drei neue Dateien. Der Schalter false sagt der
 * Lesefunktion, dass sie hier nur lesen darf. */
$mt_cfg = mt_config(false);

/* ---------------- Token ----------------
 *
 * is_string() vor jeder Wandlung: '?token[]=x' macht aus $_GET['token'] ein
 * Feld, und '(string) $feld' ergibt unter PHP 8 die Warnung "Array to string
 * conversion" - ausgegeben VOR http_response_code(), womit der Statuscode
 * nicht mehr gesetzt wird ("headers already sent") und Loxone eine 200 statt
 * einer 403 bekommt. Unter 7.4 ist es eine Notice und bleibt unsichtbar;
 * gemessen wurde beides. */
function mt_get($name)
{
    return isset($_GET[$name]) && is_string($_GET[$name]) ? $_GET[$name] : '';
}

/* Ein Parameter, der da ist, aber keine Zeichenkette: abweisen und melden.
 * Nicht auf den Vorgabewert zurueckbiegen - das waere still zurechtgebogen,
 * und der Aufrufer haelte die Antwort fuer die auf seine Frage. */
function mt_kein_feld($name)
{
    if (isset($_GET[$name]) && !is_string($_GET[$name])) {
        http_response_code(400);
        echo "FEHLER;OK=0;GRUND=PARAMETER\n";
        echo 'Der Wert von ' . $name . " ist keine Zeichenkette.\n";
        exit;
    }
}

$mt_soll = is_scalar($mt_cfg['aktionstoken']) ? (string) $mt_cfg['aktionstoken'] : '';
$mt_ist = mt_get('token');
$mt_selftest = mt_get('selftest') === '1';

if ($mt_soll === '') {
    http_response_code(403);
    if ($mt_selftest) {
        echo "SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET\n";
        exit;
    }
    echo "FEHLER;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
    /* C5 (Durchgang 30.09.2026): der Satz nennt die Ursache, die vorliegt.
     * Bis 0.9.30 stand hier immer "noch nie geoeffnet" - auch wenn eine
     * Konfiguration da war und nur ihr Token leer (nach dem Zurueckspielen
     * einer Sicherung mit leerem Token, Bericht code C5). */
    if (in_array(mt_config_lage(), array('fehlt', 'leer'), true)) {
        echo "Die Plugin-Oberflaeche wurde noch nie geoeffnet - es gibt noch kein Token.\n";
    } else {
        echo "Das Aktionstoken ist leer. Reiter Einbindung in Loxone, Knopf 'Neues Token erzeugen'.\n";
    }
    exit;
}
/* 0.9.35 (Nr. 8): Aktionstoken ODER Lesetoken (mt_token_art(), beide mit
 * hash_equals). Welche Aktion das Lesetoken oeffnet, entscheidet sich erst
 * nach der Weissliste unten. */
$mt_token_art = mt_token_art($mt_cfg, $mt_ist);
if ($mt_token_art === '') {
    http_response_code(403);
    echo $mt_selftest ? "SELFTEST;OK=0;ERR=TOKEN\n" : "FEHLER;OK=0;GRUND=TOKEN\n";
    exit;
}

/* Der Selbsttest beantwortet die Tokenfrage, ohne irgendetwas auszuloesen:
 * kein Geraetekontakt, kein Schreibzugriff, kein Protokolleintrag. Er steht
 * deshalb hinter der Tokenpruefung und vor allem anderen. Er ist lesend und
 * nimmt deshalb auch das Lesetoken; ART=LESEN sagt, welches es war. */
if ($mt_selftest) {
    echo $mt_token_art === 'lesen' ? "SELFTEST;OK=1;TOKEN=OK;ART=LESEN\n" : "SELFTEST;OK=1;TOKEN=OK\n";
    exit;
}

/* ---------------- Aktion (Weissliste) ---------------- */
$mt_lesend = mt_lesende_aktionen();
// 0.9.35 (Nr. 5): loxfarbe, farbe_xy, lamelle.
$mt_schaltend = array('ein', 'aus', 'umschalten', 'helligkeit', 'farbtemperatur',
                      'farbe', 'farbton', 'saettigung', 'loxfarbe', 'farbe_xy',
                      'rollo', 'rollo_auf', 'rollo_zu', 'rollo_stopp', 'lamelle',
                      'soll_heizen', 'soll_kuehlen', 'betriebsart', 'luefter',
                      'sperren', 'entsperren', 'identify',
                      'attribut', 'befehl', 'abruf');
mt_kein_feld('aktion');
$mt_aktion = mt_get('aktion');
if ($mt_aktion === '') {
    $mt_aktion = 'status';
}
if (!in_array($mt_aktion, array_merge($mt_lesend, $mt_schaltend), true)) {
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=UNBEKANNTE_AKTION\n";
    echo 'Erlaubt sind: ' . implode(', ', array_merge($mt_lesend, $mt_schaltend)) . "\n";
    exit;
}
/* 0.9.35 (Nr. 8): das Lesetoken oeffnet nur Lesendes. */
if ($mt_token_art !== 'aktion' && !in_array($mt_aktion, $mt_lesend, true)) {
    http_response_code(403);
    printf("SET;OK=0;AKTION=%s;GRUND=NUR_LESETOKEN\n", $mt_aktion);
    echo 'Das Lesetoken oeffnet nur ' . implode(', ', $mt_lesend) . ". Fuer diese Aktion das Aktionstoken verwenden.\n";
    exit;
}

/* ---------------- Parameter ----------------
 * Was nicht ins Muster passt, wird abgewiesen und gemeldet. Nie Zeichen
 * entfernen, nie zurechtbiegen.
 */
function mt_param($name, $muster, $vorgabe = '')
{
    if (!isset($_GET[$name])) {
        return $vorgabe;
    }
    /* Ein Feld ist keine Zeichenkette und wird abgewiesen, nicht gewandelt -
     * siehe die Begruendung bei mt_get(). */
    if (!is_string($_GET[$name])) {
        http_response_code(400);
        echo "FEHLER;OK=0;GRUND=PARAMETER\n";
        echo 'Der Wert von ' . $name . " ist keine Zeichenkette.\n";
        exit;
    }
    $w = $_GET[$name];
    if ($w === '') {
        return $vorgabe;
    }
    if (!preg_match($muster, $w)) {
        http_response_code(400);
        echo "FEHLER;OK=0;GRUND=PARAMETER\n";
        echo 'Der Wert von ' . $name . " passt nicht ins erlaubte Muster.\n";
        exit;
    }
    return $w;
}

$mt_nr       = mt_param('geraet', '/^[0-9]{1,3}$/', '1');
/* 0.9.35 (Nr. 7): wurde &geraet= wirklich angegeben? mt_param() hat den
 * Wert schon geprueft (ein Feld waere dort abgewiesen); ein leeres &geraet=
 * gilt als nicht angegeben, nicht als Geraet 1. */
$mt_geraet_angegeben = mt_get('geraet') !== '';
/* Die Knotennummer als dauerhafte Adresse.
 *
 * &geraet= ist seit 0.9.10 stabil (die Zuordnung steht in nummern.json und
 * wird nie veraendert). Wer ganz sichergehen will, adressiert ueber &knoten=
 * - das ist die Nummer, die der Matter-Server selbst vergeben hat, und sie
 * haengt an keiner Zaehlung des Plugins. Ist beides angegeben, gewinnt
 * &knoten=. */
$mt_knoten   = mt_param('knoten', '/^[0-9]{1,20}$/', '');
$mt_endpunkt = mt_param('endpunkt', '/^[0-9]{1,3}$/', '1');
/* 0.9.35 (Nr. 5): loxfarbe traegt bis zu neun Ziffern (20bbbtttt,
 * BBBGGGRRR) und nie ein Komma - eigenes Muster. */
$mt_wert     = mt_param('wert', $mt_aktion === 'loxfarbe'
                   ? '/^[0-9]{1,9}$/' : '/^-?[0-9]{1,6}([.,][0-9]{1,3})?$/', '');
// 0.9.35 (Nr. 5): Farbort fuer farbe_xy, Punkt oder Komma.
$mt_x        = mt_param('x', '/^[0-9]([.,][0-9]{1,6})?$/', '');
$mt_y        = mt_param('y', '/^[0-9]([.,][0-9]{1,6})?$/', '');
$mt_thema    = mt_param('thema', '/^[a-z0-9_]{1,40}$/', '');
$mt_pfad     = mt_param('pfad', '#^[0-9]{1,3}/[0-9]{1,5}/[0-9]{1,5}$#', '');
$mt_cluster  = mt_param('cluster', '/^[0-9]{1,5}$/', '');
$mt_name     = mt_param('name', '/^[A-Za-z][A-Za-z0-9]{0,48}$/', '');
$mt_nutzlast = mt_get('nutzlast');
if (strlen($mt_nutzlast) > 4096) {
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=NUTZLAST_ZU_LANG\n";
    exit;
}

function mt_w($v)
{
    if ($v === null || $v === '' || !is_numeric($v)) {
        return '-';
    }
    return (string) (0 + $v);
}

/**
 * Ein Feld des Abbilds, abgesichert.
 *
 * Das Abbild schreibt der Dienst; fehlt ein Schluessel (aeltere Fassung, halb
 * geschriebene Datei), ist das unter PHP 7.4 eine verschluckte Notice, unter
 * PHP 8 aber eine Warning - und die stuende MITTEN IN DER ANTWORTZEILE, die
 * der Miniserver auswertet. Deshalb geht jeder Zugriff hier durch.
 */
function mt_f($g, $name, $leer = '')
{
    return is_array($g) && isset($g[$name]) ? $g[$name] : $leer;
}

$mt_lox = mt_loxone();
$mt_alle = mt_geraete();
$mt_alter = mt_alter();
$mt_knoten_unbekannt = false;
if ($mt_knoten !== '') {
    $mt_g = null;
    foreach ($mt_alle as $mt_k => $mt_kandidat) {
        if (isset($mt_kandidat['node_id']) && (string) $mt_kandidat['node_id'] === $mt_knoten) {
            $mt_g = $mt_kandidat;
            $mt_nr = (string) $mt_k;
            break;
        }
    }
    $mt_knoten_unbekannt = ($mt_g === null);
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
        mt_ok_endpunkt($mt_lox, $mt_cfg), count($mt_alle), $mt_alter,
        isset($srv['sdk_version']) ? $srv['sdk_version'] : '-',
        isset($srv['bluetooth']) ? (int) $srv['bluetooth'] : 0);
    foreach ($mt_alle as $nr => $g) {
        echo $nr . ';' . mt_f($g, 'name', '?') . ';Knoten=' . (int) mt_f($g, 'node_id', 0)
           . ';Erreichbar=' . (int) mt_f($g, 'erreichbar', 0)
           . ';Endpunkte=' . count((array) mt_f($g, 'endpunkte', array())) . "\n";
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
        if (!mt_f($g, 'erreichbar', 0)) { $mt_alleda = false; break; }
    }
    $teile = array(
        'MATTER;OK=' . mt_ok_endpunkt($mt_lox, $mt_cfg),
        'ERREICH=' . (int) $mt_alleda,
        'ALTER=' . $mt_alter,
    );
    foreach ($mt_alle as $nr => $g) {
        foreach ((array) mt_f($g, 'endpunkte', array()) as $ep => $felder) {
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
    /* Eine angegebene, aber unbekannte Knotennummer wird gemeldet, nicht
     * uebergangen. Bis 0.9.16 loeste sie still einen Gesamtabruf aus - der
     * Aufrufer bekam OK=1 und hielt seinen Knoten fuer ausgelesen. */
    if ($mt_knoten_unbekannt) {
        http_response_code(400);
        printf("SET;OK=0;GRUND=KNOTEN_UNBEKANNT;KNOTEN=%s;N=%d\n", $mt_knoten, count($mt_alle));
        exit;
    }
    /* 0.9.35 (Nr. 7): dasselbe fuer eine angegebene, aber unbekannte
     * Geraetenummer. Bis 0.9.34 loeste sie still einen Gesamtabruf aus. */
    if ($mt_knoten === '' && $mt_geraet_angegeben && $mt_g === null) {
        http_response_code(400);
        printf("SET;OK=0;GRUND=GERAET_UNBEKANNT;GERAET=%s;N=%d\n", $mt_nr, count($mt_alle));
        exit;
    }
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
    // 0.9.35 (Nr. 7): $mt_geraet_angegeben statt isset() - ein leeres
    // &geraet= war bis 0.9.34 "Geraet 1".
    if ($mt_g !== null && ($mt_geraet_angegeben || $mt_knoten !== '')) {
        $mt_auftrag['knoten'] = (int) mt_f($mt_g, 'node_id', 0);
        $mt_frist = 20;
    }
    // 0.9.35 (Nr. 3): die schon gelesene Konfiguration mitgeben - ohne sie
    // las mt_befehl_absetzen() mt_config() MIT Schreibrecht.
    list($mt_erg, $mt_meldung) = mt_befehl_absetzen($mt_auftrag, $mt_frist, $mt_cfg);
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
    $mt_eps = (array) mt_f($mt_g, 'endpunkte', array());
    $ep = (array) (isset($mt_eps[$mt_endpunkt]) ? $mt_eps[$mt_endpunkt] : array());
    echo (isset($ep[$mt_thema]) ? mt_w($ep[$mt_thema]) : '-') . "\n";
    exit;
}

if ($mt_aktion === 'status') {
    $teile = array(
        'MATTER;OK=' . mt_ok_endpunkt($mt_lox, $mt_cfg),
        'ERREICH=' . (int) mt_f($mt_g, 'erreichbar', 0),
        'ALTER=' . $mt_alter,
    );
    foreach ((array) mt_f($mt_g, 'endpunkte', array()) as $ep => $felder) {
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

/* ---------------- Schloss und Rohwege (0.9.35, Nr. 6) ----------------
 *
 * Der Cluster, den ein Rohweg anspricht: bei befehl &cluster=, bei attribut
 * die Mitte von &pfad=E/C/A. -1 fuer alle anderen Aktionen. */
$mt_roh_cluster = -1;
if ($mt_aktion === 'befehl' && $mt_cluster !== '') {
    $mt_roh_cluster = (int) $mt_cluster;
} elseif ($mt_aktion === 'attribut' && $mt_pfad !== '') {
    $mt_roh_cluster = (int) explode('/', $mt_pfad)[1];
}
/* Gesperrt im Rohweg (VERTRAG, Endpunkt UND Dienst): 31 AccessControl,
 * 48 GeneralCommissioning, 49 NetworkCommissioning, 60 AdministratorCommissioning,
 * 62 OperationalCredentials, 63 GroupKeyManagement. Wer darueber schreibt,
 * kann das Geraet aus der Fabric werfen, ein Anlernfenster oeffnen oder
 * fremden Controllern Rechte geben - dafuer gibt es die Knoepfe der
 * Oberflaeche, nicht einen unangemeldeten Endpunkt. */
if (in_array($mt_roh_cluster, array(31, 48, 49, 60, 62, 63), true)) {
    http_response_code(403);
    printf("SET;OK=0;AKTION=%s;GRUND=CLUSTER_GESPERRT;CLUSTER=%d\n", $mt_aktion, $mt_roh_cluster);
    exit;
}
/* Schloesser verlangen den eigenen Haken - auch ueber die Rohwege. Bis
 * 0.9.34 pruefte das nur der Dienst; der Endpunkt nahm den Befehl an und
 * meldete erst nach der Wartezeit die Ablehnung. */
if (($mt_aktion === 'sperren' || $mt_aktion === 'entsperren' || $mt_roh_cluster === 257)
        && empty($mt_cfg['schloss_ein'])) {
    http_response_code(403);
    printf("SET;OK=0;AKTION=%s;GRUND=SCHLOSS_AUS\n", $mt_aktion);
    echo "Schloesser zu schalten ist gesperrt. Reiter Einstellungen, Haken 'Schloesser schalten zulassen'.\n";
    exit;
}

if (mt_dienst_pid() === 0) {
    http_response_code(503);
    echo "SET;OK=0;GRUND=DIENST_LAEUFT_NICHT\n";
    echo "Der Dienst laeuft nicht. Reiter Einstellungen, Knopf 'Dienst starten'.\n";
    exit;
}

/* Eine Zahl aus dem Wertfeld; Komma und Punkt sind gleich. */
function mt_ep_zahl($w)
{
    return (float) str_replace(',', '.', (string) $w);
}

/* Ausserhalb des erlaubten Bereichs: abweisen, nicht kappen. */
function mt_ep_bereich($aktion, $v, $klein, $gross)
{
    if ($v < $klein || $v > $gross) {
        http_response_code(400);
        printf("SET;OK=0;AKTION=%s;GRUND=WERT_BEREICH;MIN=%s;MAX=%s\n", $aktion, $klein, $gross);
        exit;
    }
}

$mt_befehl = array(
    'aktion'   => $mt_aktion,
    'knoten'   => (int) mt_f($mt_g, 'node_id', 0),
    'endpunkt' => (int) $mt_endpunkt,
);
if (in_array($mt_aktion, array('helligkeit', 'farbtemperatur', 'rollo', 'lamelle',
                               'soll_heizen', 'soll_kuehlen', 'betriebsart',
                               'farbe', 'farbton', 'saettigung', 'luefter'), true)) {
    if ($mt_wert === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=WERT_FEHLT\n";
        exit;
    }
    $mt_befehl['wert'] = mt_ep_zahl($mt_wert);
    // 0.9.35 (Nr. 5): helligkeit bleibt 0..100 (0 = aus), lamelle 0..100.
    if ($mt_aktion === 'helligkeit' || $mt_aktion === 'lamelle') {
        mt_ep_bereich($mt_aktion, $mt_befehl['wert'], 0, 100);
    }
    // Bei 'farbe' darf zusaetzlich die Saettigung mitkommen. Fehlt sie, setzt
    // der Dienst 100 % - das ist die volle Farbe, nicht Weiss.
    if ($mt_aktion === 'farbe') {
        $mt_saet = mt_param('saettigung', '/^[0-9]{1,3}$/', '');
        if ($mt_saet !== '') {
            $mt_befehl['saettigung'] = (int) $mt_saet;
        }
    }
} elseif ($mt_aktion === 'loxfarbe') {
    /* 0.9.35 (Nr. 5): der Ausgang des Loxone-Lichtbausteins als ganze
     * Zahl. Zerlegt wird im Dienst (20bbbtttt Lumitech, sonst BBBGGGRRR);
     * hier nur die Grenzen. */
    if ($mt_wert === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=WERT_FEHLT\n";
        exit;
    }
    $mt_befehl['wert'] = (int) $mt_wert;
    mt_ep_bereich($mt_aktion, $mt_befehl['wert'], 0, 299999999);
} elseif ($mt_aktion === 'farbe_xy') {
    // 0.9.35 (Nr. 5): Farbort der CIE-Normfarbtafel, beide 0..1.
    if ($mt_x === '' || $mt_y === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=WERT_FEHLT\n";
        exit;
    }
    $mt_befehl['x'] = mt_ep_zahl($mt_x);
    $mt_befehl['y'] = mt_ep_zahl($mt_y);
    mt_ep_bereich($mt_aktion, $mt_befehl['x'], 0, 1);
    mt_ep_bereich($mt_aktion, $mt_befehl['y'], 0, 1);
} elseif ($mt_aktion === 'identify') {
    // Ohne Angabe macht sich das Geraet 15 s lang bemerkbar.
    if ($mt_wert !== '') {
        $mt_befehl['wert'] = mt_ep_zahl($mt_wert);
    }
} elseif ($mt_aktion === 'attribut') {
    if ($mt_pfad === '' || $mt_wert === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=PFAD_ODER_WERT_FEHLT\n";
        exit;
    }
    $mt_befehl['pfad'] = $mt_pfad;
    $mt_befehl['wert'] = mt_ep_zahl($mt_wert) == (int) $mt_wert
        ? (int) $mt_wert : mt_ep_zahl($mt_wert);
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
        $mt_nl = json_decode($mt_nutzlast);
        if ($mt_nl === null && strtolower(trim($mt_nutzlast)) !== 'null') {
            http_response_code(400);
            echo "SET;OK=0;GRUND=NUTZLAST_KEIN_JSON\n";
            exit;
        }
        /* 0.9.35 (Nr. 6): die Nutzlast sind die Felder des Befehls - also
         * ein JSON-Objekt. Eine Liste, Zahl oder null liess der Dienst bis
         * 0.9.34 erst beim Senden scheitern. */
        if (!is_object($mt_nl)) {
            http_response_code(400);
            echo "SET;OK=0;GRUND=NUTZLAST_KEIN_OBJEKT\n";
            exit;
        }
        $mt_befehl['nutzlast'] = $mt_nutzlast;
    }
}

/* ---------------- Schlossbremse (0.9.35, Nr. 1) ----------------
 *
 * ENTSCHEIDUNGEN Nr. 15 (Nachtrag Verbesserungsbau 30.09.2026), neu
 * gefasst: eine Tuer soll ein flatternder Loxone-Ausgang nicht im
 * Sekundentakt aufsperren. Gebremst wird nur noch die unsichere Richtung.
 * Licht, Dimmen und alle anderen Befehle bekommen kein 429; fuer ihre
 * Sollwerte gilt die Gleichwert-Unterdrueckung darunter (Nr. 19).
 *
 * Gilt fuer sperren/entsperren und fuer den Rohweg befehl &cluster=257 mit
 * LockDoor (wie sperren) bzw. UnlockDoor, UnlockWithTimeout, UnlatchDoor
 * (wie entsperren) - sonst liesse sich die Bremse ueber den Rohweg umgehen.
 * Je Geraet (Knoten und Endpunkt):
 *   - sperren geht IMMER hinaus: nie UNVERAENDERT, nie 429. Sperren ist die
 *     sichere Richtung und LockDoor idempotent. Bis 0.9.34 konnte ein
 *     sperren kurz nach einem entsperren mit 429 abgewiesen werden - die
 *     Tuer blieb offen. Auch ein unlesbarer Merker haelt sperren nicht auf.
 *   - entsperren bleibt UNVERAENDERT (200, UNVERAENDERT=1) nur, wenn derselbe
 *     Befehl vor weniger als 60 s gelungen ist UND das Abbild das Schloss als
 *     entriegelt meldet (schloss 2 oder 3). Wer in der Zwischenzeit am
 *     Schloss von Hand zugesperrt hat, sperrt mit dem naechsten entsperren
 *     wieder auf - bis 0.9.34 wurde es 60 s lang verschluckt.
 *   - entsperren innerhalb von 10 s nach einem ANDEREN Schlossbefehl
 *     (sperren): 429, GRUND=BREMSE, WARTEN_S.
 *   - ist der Merker nicht zu oeffnen oder zu sperren, faellt entsperren
 *     geschlossen aus: 503, GRUND=BREMSE_MERKER - es wird nichts gesendet.
 * Gemerkt wird nur, was der Dienst AUSGEFUEHRT hat (OK=1). Bis 0.9.34 auch
 * OK=2 - ein verworfener oder ueberholter Befehl sperrte dann den Weg.
 * Die Sperre bleibt bis zum Ende des Befehls gehalten; zwei gleichzeitige
 * Schlossbefehle laufen nacheinander. */
$mt_schlossart = '';
if ($mt_aktion === 'sperren' || $mt_aktion === 'entsperren') {
    $mt_schlossart = $mt_aktion;
} elseif ($mt_aktion === 'befehl' && (int) $mt_cluster === 257) {
    if ($mt_name === 'LockDoor') {
        $mt_schlossart = 'sperren';
    } elseif (in_array($mt_name, array('UnlockDoor', 'UnlockWithTimeout', 'UnlatchDoor'), true)) {
        $mt_schlossart = 'entsperren';
    }
}
$mt_bfh = null;
$mt_bm = array();
$mt_bschl = (int) $mt_befehl['knoten'] . '|' . (int) $mt_befehl['endpunkt'];
if ($mt_schlossart !== '') {
    $mt_bremse = mt_paths()['datadir'] . '/schlossbremse.json';
    $mt_bfh = @fopen($mt_bremse, 'c+e');
    if ($mt_bfh === false || !@flock($mt_bfh, LOCK_EX)) {
        if ($mt_bfh !== false) {
            fclose($mt_bfh);
        }
        $mt_bfh = null;
        mt_log_gebremst('schlossbremse', 'Die Merkerdatei der Schlossbremse (' . $mt_bremse . ') laesst '
            . 'sich nicht oeffnen oder sperren - entsperren wird mit 503 abgewiesen, bis das '
            . 'behoben ist (sperren geht ohne Bremse hinaus).');
        if ($mt_schlossart === 'entsperren') {
            http_response_code(503);
            echo "SET;OK=0;AKTION=" . $mt_aktion . ";GRUND=BREMSE_MERKER\n";
            exit;
        }
    } else {
        $mt_bm = json_decode((string) stream_get_contents($mt_bfh), true);
        if (!is_array($mt_bm)) {
            $mt_bm = array();
        }
        if ($mt_schlossart === 'entsperren' && isset($mt_bm[$mt_bschl]) && is_array($mt_bm[$mt_bschl])
                && isset($mt_bm[$mt_bschl]['t'])) {
            $mt_seit = time() - (int) $mt_bm[$mt_bschl]['t'];
            $mt_bw = (string) (isset($mt_bm[$mt_bschl]['w']) ? $mt_bm[$mt_bschl]['w'] : '');
            // Was das Abbild ueber das Schloss sagt (DoorLock LockState:
            // 0 nicht ganz verriegelt, 1 verriegelt, 2 entriegelt, 3 offen).
            $mt_bep = (array) mt_f((array) mt_f($mt_g, 'endpunkte', array()), (string) $mt_endpunkt, array());
            $mt_bist = mt_f($mt_bep, 'schloss', null);
            $mt_entriegelt = is_numeric($mt_bist) && in_array((int) $mt_bist, array(2, 3), true);
            if ($mt_bw === 'entsperren' && $mt_seit >= 0 && $mt_seit < 60 && $mt_entriegelt) {
                flock($mt_bfh, LOCK_UN);
                fclose($mt_bfh);
                printf("SET;OK=1;AKTION=%s;UNVERAENDERT=1;IST=%s\n", $mt_aktion, mt_w($mt_bist));
                exit;
            }
            if ($mt_bw !== 'entsperren' && $mt_seit >= 0 && $mt_seit < 10) {
                flock($mt_bfh, LOCK_UN);
                fclose($mt_bfh);
                http_response_code(429);
                printf("SET;OK=0;AKTION=%s;GRUND=BREMSE;WARTEN_S=%d\n", $mt_aktion, 10 - $mt_seit);
                exit;
            }
        }
    }
}

/* Die Schlossbremse loesen (ohne zu schreiben) - fuer jeden Ausstieg
 * zwischen hier und dem Absetzen. */
function mt_ep_bremse_loesen($fh)
{
    if (is_resource($fh)) {
        @flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/* ---------------- Gleichwert-Unterdrueckung (X-7, B-Nachzug 01.10.2026) ----------------
 *
 * ENTSCHEIDUNGEN Nr. 19; Vorbild die Schlossbremse darueber, EVCC 0.9.37
 * und Marstek 1.1.19. Derselbe Sollwert fuer dasselbe Geraet (Knoten und
 * Endpunkt) innerhalb von 60 s geht nicht erneut hinaus: 200 mit
 * UNVERAENDERT=1, gesendet wird nichts. Ein anderer Wert geht sofort hinaus,
 * kein 429. Ereignisse und Rohwege werden nie unterdrueckt, fuehren den
 * Merker aber nach (mt_gleichwert_verfaellt()).
 *
 * 0.9.35 (Nr. 4): gesperrt wird nur noch DIESES Geraet, und zwar waehrend
 * des ganzen Befehls (mt_gleichwert_oeffnen()); die gemeinsame Merkerdatei
 * nur kurz zum Lesen und zum Nachfuehren. Laesst sich der Merker nicht
 * oeffnen, faellt ein Sollwert-Befehl geschlossen aus (503
 * GRUND=GLEICHWERT_MERKER); haelt ein anderer Aufruf dasselbe Geraet
 * laenger als etwa 2 s, 503 GRUND=BESCHAEFTIGT. Ein Ereignis geht in beiden
 * Faellen trotzdem hinaus - ohne Merker fallen die Sollwerte ohnehin
 * geschlossen aus. */
$mt_gw_fh = null;
$mt_gw_geraet = (int) $mt_befehl['knoten'] . '|' . (int) $mt_befehl['endpunkt'];
$mt_gw_schl = mt_gleichwert_schluessel($mt_aktion);
$mt_gw_wert = mt_gleichwert_wert($mt_befehl);
$mt_gw_betrifft = mt_gleichwert_betrifft($mt_aktion);
if ($mt_gw_betrifft) {
    $mt_gw_grund = '';
    list($mt_gw_fh, $mt_gw_grund) = mt_gleichwert_oeffnen($mt_gw_geraet);
    $mt_gw_merker = false;
    if ($mt_gw_fh !== false) {
        list($mt_gw_merker, $mt_gw_grund) = mt_gleichwert_lesen();
    }
    if ($mt_gw_merker === false && $mt_gw_schl !== '') {
        mt_gleichwert_freigeben($mt_gw_fh);
        mt_ep_bremse_loesen($mt_bfh);
        if ($mt_gw_grund !== 'BESCHAEFTIGT') {
            mt_log_gebremst('gleichwert', 'Die Merkerdatei der Gleichwert-Unterdrueckung (' . mt_gleichwert_datei()
                . ') oder eine Sperrdatei in ' . mt_gleichwert_ordner() . ' laesst sich nicht oeffnen oder '
                . 'sperren - Sollwert-Befehle werden mit 503 abgewiesen, bis das behoben ist.');
        }
        http_response_code(503);
        printf("SET;OK=0;AKTION=%s;GRUND=%s\n", $mt_aktion,
            $mt_gw_grund === 'BESCHAEFTIGT' ? 'BESCHAEFTIGT' : 'GLEICHWERT_MERKER');
        exit;
    }
    if ($mt_gw_merker !== false
            && mt_gleichwert_seit($mt_gw_merker, $mt_gw_geraet, $mt_gw_schl, $mt_gw_wert) >= 0) {
        mt_gleichwert_freigeben($mt_gw_fh);
        mt_ep_bremse_loesen($mt_bfh);
        printf("SET;OK=1;AKTION=%s;UNVERAENDERT=1\n", $mt_aktion);
        exit;
    }
}

// 0.9.35 (Nr. 2/3): drittes Element "ist"; die gelesene Konfiguration geht
// mit, damit nichts mit Schreibrecht gelesen wird.
list($mt_erg, $mt_meldung, $mt_istwert) = array_pad(mt_befehl_absetzen($mt_befehl, null, $mt_cfg), 3, null);
if ($mt_erg === 0) {
    http_response_code(500);
}
if ($mt_gw_betrifft) {
    /* Nachgefuehrt wird, solange die Geraetesperre noch gehalten ist - der
     * naechste Aufruf an dieses Geraet sieht schon den neuen Stand. Gemerkt
     * wird der eigene Wert nur bei OK=1 (nicht bei 2: ueberholt oder ohne
     * Ergebnis). */
    mt_gleichwert_nachfuehren((int) $mt_befehl['knoten'], $mt_gw_geraet, $mt_aktion, $mt_gw_wert,
        $mt_erg === 1);
    mt_gleichwert_freigeben($mt_gw_fh);
}
if (is_resource($mt_bfh)) {
    /* 0.9.35 (Nr. 1): gemerkt wird nur, was der Dienst ausgefuehrt hat
     * (OK=1). Laenge statt "!== false" gegen eine gekuerzte Schreibung
     * (Regeln/03). */
    if ($mt_erg === 1) {
        $mt_bm[$mt_bschl] = array('w' => $mt_schlossart, 't' => time());
        $mt_bjs = (string) json_encode($mt_bm);
        if (!(ftruncate($mt_bfh, 0) && rewind($mt_bfh)
              && @fwrite($mt_bfh, $mt_bjs) === strlen($mt_bjs) && fflush($mt_bfh))) {
            mt_log_gebremst('schlossbremse', 'Die Merkerdatei der Schlossbremse liess sich nicht '
                . 'schreiben - die Bremse erkennt den letzten Schlossbefehl nicht.');
        }
    }
    mt_ep_bremse_loesen($mt_bfh);
}
/* 0.9.35 (Nr. 9): der Ist-Wert nach dem Befehl, wenn der Dienst ihn
 * mitliefert - vor MELDUNG, weil MELDUNG freier Text ist und am Ende steht. */
$mt_ist_teil = '';
if ($mt_istwert !== null && $mt_istwert !== '') {
    $mt_ist_teil = ';IST=' . (is_numeric($mt_istwert) ? mt_w($mt_istwert)
        : str_replace(array("\r", "\n", ';', '='), ' ', (string) $mt_istwert));
}
printf("SET;OK=%d;AKTION=%s%s;MELDUNG=%s\n", $mt_erg, $mt_aktion, $mt_ist_teil,
    str_replace(array("\r", "\n", ';'), ' ', $mt_meldung));
