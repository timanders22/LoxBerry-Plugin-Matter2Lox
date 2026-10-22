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
 *
 * Gruppe 8 (0.9.44): der ganze Endpunkt haengt am Haken "HTTP-Endpunkt fuer
 * Loxone annehmen" (endpunkt_ein; neu aus, nach einem Update an) - sonst
 * 403 GRUND=ENDPUNKT_AUS bzw. SELFTEST;OK=0;ERR=ENDPUNKT_AUS. Das Musterprojekt
 * holt Werte und sendet Befehle ueber MQTT. Der Rumpf der schaltenden Aktionen
 * und des Abrufs steht als mt_steuern() in mt_lib.php - dieselbe Funktion ruft
 * die Befehlsbruecke; die Antworten sind byte-gleich die bis 0.9.43.
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

/* Gruppe 8 (0.9.44, Entscheidung 1, E1): VOR der Tokenpruefung - lesend und
 * schaltend, auch der Selbsttest. Legt nichts an. */
if (!mt_endpunkt_an($mt_cfg)) {
    http_response_code(403);
    if (mt_get('selftest') === '1') {
        echo "SELFTEST;OK=0;ERR=ENDPUNKT_AUS\n";
        exit;
    }
    echo "FEHLER;OK=0;GRUND=ENDPUNKT_AUS\n";
    echo "Der HTTP-Endpunkt ist ausgeschaltet. Reiter Einbindung in Loxone, Haken 'HTTP-Endpunkt fuer Loxone annehmen'.\n";
    exit;
}

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

/* mt_w() und mt_f() stehen seit 0.9.44 in mt_lib.php (wortgleich) - mt_steuern() braucht sie fuer
 * beide Wege. */

$mt_lox = mt_loxone();
$mt_alle = mt_geraete();
$mt_alter = mt_alter();
list($mt_g, $mt_nr, $mt_knoten_unbekannt) = mt_geraet_suchen($mt_alle, $mt_nr, $mt_knoten);

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
/* Gruppe 8 (0.9.44, Entwurf 3.4, E11): ab hier steht der Rumpf als mt_steuern() in mt_lib.php - DIESELBE
 * Funktion ruft die Befehlsbruecke (bin/befehle_zuordnung.php). Hier bleiben Parameter und Ausgabe. */
$mt_e = array(
    'aktion' => $mt_aktion, 'nr' => $mt_nr, 'geraet_angegeben' => $mt_geraet_angegeben,
    'knoten' => $mt_knoten, 'endpunkt' => $mt_endpunkt, 'wert' => $mt_wert, 'x' => $mt_x, 'y' => $mt_y,
    'pfad' => $mt_pfad, 'cluster' => $mt_cluster, 'name' => $mt_name, 'nutzlast' => $mt_nutzlast,
    // Geprueft wird 'saettigung' erst in mt_steuern(), an derselben Stelle wie bis 0.9.43.
    'saettigung_roh' => isset($_GET['saettigung']) ? $_GET['saettigung'] : null,
);

/** Die Antwort von mt_steuern() ausgeben: Statuscode (200 wird nicht gesetzt), Zeilen, Ende. */
function mt_antwort(array $r)
{
    if ($r['code'] !== 200) {
        http_response_code($r['code']);
    }
    echo $r['text'];
    exit;
}

if ($mt_aktion === 'abruf') {
    mt_endpunkt_merken();
    mt_antwort(mt_steuern($mt_e, $mt_cfg));
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

/* Steuerungsfreigabe, Rohweg-Sperre, Schlossfreigabe, Dienst, Bereiche,
 * Schlossbremse, Gleichwert-Unterdrueckung und das Absetzen: mt_steuern()
 * in mt_lib.php (bis 0.9.43 standen sie hier, Zeilen 499-845). */
mt_endpunkt_merken();
mt_antwort(mt_steuern($mt_e, $mt_cfg));
