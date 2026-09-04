<?php
/**
 * Matter to Loxone - die Aktionen des Reiters Test
 *
 * Die Selbstpruefung beantwortet OHNE Loxone, ob die Einrichtung traegt. Sie
 * prueft dabei ausdruecklich auch die Voraussetzungen des Wirtssystems - bei
 * Matter scheitert weit mehr am Netz als am Plugin.
 */

function mt_pruefzeile($stand, $frage, $antwort)
{
    return array('stand' => $stand, 'frage' => $frage, 'antwort' => $antwort);
}

/**
 * Passen Positivliste, Reiterleiste, Flaechen und Verweise zusammen?
 *
 * Vier Stellen in derselben Datei muessen dieselben sechs Namen fuehren:
 *   - die Positivliste, die entscheidet, welcher Reiter nach dem Absenden
 *     offen bleibt. Fehlt ein Name, springt die Seite jedes Mal zurueck.
 *   - die Reiterleiste selbst.
 *   - die id der Flaechen. Stimmt eine nicht, bleibt die Flaeche unsichtbar.
 *   - der Verweis je Reiter. Stimmt er nicht, ist der Reiter ohne JavaScript
 *     unerreichbar.
 *
 * Gelesen wird STATISCH aus der Datei, verglichen wird ebenfalls statisch -
 * eine Pruefung, die statisch liest und gegen einen Laufzeitwert vergleicht,
 * steht dauerhaft auf Rot, ohne dass etwas falsch waere.
 */
/**
 * Ist die Konfiguration heil?
 *
 * Jeder Zustand, den mt_config() erzeugen kann, bekommt seinen Satz - das ist
 * Hausstandard und fehlte bis 0.9.16 ganz. Die Selbstheilung ist der teuerste
 * Mechanismus dieses Plugins; ob sie gerade gegriffen hat, stand nirgends.
 */
function mt_pruef_konfig()
{
    $p = mt_paths();
    $lage = mt_config_lage();
    $texte = array(
        'ok'           => array(1, 'TEST.A_KONFIG_OK'),
        'fehlt'        => array(-1, 'TEST.A_KONFIG_FEHLT'),
        'leer'         => array(-1, 'TEST.A_KONFIG_LEER'),
        'zweitschrift' => array(0, 'TEST.A_KONFIG_ZWEIT'),
        'kaputt'       => array(0, 'TEST.A_KONFIG_KAPUTT'),
        'beide_kaputt' => array(0, 'TEST.A_KONFIG_BEIDE'),
    );
    if (!isset($texte[$lage])) {
        return array(-1, sprintf(mt_t('TEST.A_KONFIG_UNBEKANNT'), mt_e($lage)));
    }
    $antwort = mt_t($texte[$lage][1]);
    if (is_file($p['config'] . '.kaputt')) {
        $antwort .= ' ' . sprintf(mt_t('TEST.A_KONFIG_KAPUTTDATEI'),
                                  mt_e($p['config'] . '.kaputt'));
    }
    return array($texte[$lage][0], $antwort);
}

/**
 * Tragen alle Formulare das Merkmal gegen fremde Absender?
 *
 * Ein Formular vergisst man. Gezaehlt wird in der eigenen Datei: oeffnende
 * <form>-Marken gegen Aufrufe von mt_fmt(). Der Wachposten kam in 0.9.14,
 * diese Zeile nicht.
 */
function mt_pruef_formulare()
{
    $datei = __DIR__ . '/index.php';
    if (!is_file($datei)) {
        return array(-1, sprintf(mt_t('TEST.A_REITER_UNBEKANNT'), mt_e($datei)));
    }
    $t = (string) @file_get_contents($datei);
    $formulare = preg_match_all('/<form\b/i', $t);
    $marken = preg_match_all('/mt_fmt\(\)/', $t);
    if ($formulare === 0) {
        return array(0, mt_t('TEST.A_FORM_KEINE'));
    }
    if ($formulare !== $marken) {
        return array(0, sprintf(mt_t('TEST.A_FORM_FEHL'), $formulare, $marken));
    }
    return array(1, sprintf(mt_t('TEST.A_FORM_OK'), $formulare));
}

/**
 * Nennt die Themenliste der Oberflaeche, was der Dienst wirklich sendet?
 *
 * Die Tabelle im Reiter MQTT ist die Anleitung. Laeuft sie gegen den
 * Sendecode auseinander, traegt jemand Eingaenge in Loxone ein, die nie einen
 * Wert bekommen - oder er sucht einen Wert, den es nicht gibt.
 */
function mt_pruef_themen()
{
    $tab = mt_tabelle();
    $bekannt = array();
    foreach ((array) (isset($tab['cluster']) ? $tab['cluster'] : array()) as $c) {
        foreach ((array) (isset($c['attribute']) ? $c['attribute'] : array()) as $a) {
            if (isset($a['thema'])) {
                $bekannt[(string) $a['thema']] = 1;
            }
        }
    }
    foreach (array('ereignisthemen', 'abgeleitete_themen') as $gruppe) {
        $q = isset($tab[$gruppe]['themen']) ? $tab[$gruppe]['themen'] : array();
        foreach ((array) $q as $a) {
            if (isset($a['thema'])) {
                $bekannt[(string) $a['thema']] = 1;
            }
        }
    }
    if (!$bekannt) {
        return array(0, mt_t('TEST.A_THEMEN_LEER'));
    }
    /* Was der Dienst zusaetzlich zu den Attributthemen sendet, steht in
     * seiner Quelle. Gesucht werden die woertlichen Schluessel des
     * Lebenszeichens - sie sind der Teil, der ohne Geraet hinausgeht. */
    $lebens = array('online', 'ok', 'ts');
    $fehlend = array();
    $datei = mt_paths()['bindir'] . '/matter_dienst.py';
    if (is_file($datei)) {
        $py = (string) @file_get_contents($datei);
        foreach ($lebens as $l) {
            if (strpos($py, '"' . $l . '"') === false) {
                $fehlend[] = $l;
            }
        }
    }
    if ($fehlend) {
        return array(0, sprintf(mt_t('TEST.A_THEMEN_FEHL'), mt_e(implode(', ', $fehlend))));
    }
    return array(1, sprintf(mt_t('TEST.A_THEMEN_OK'), count($bekannt), count($lebens)));
}

/**
 * Ist jedes Suchmuster eindeutig?
 *
 * Loxone sucht die Zeichenkette woertlich und nimmt den ERSTEN Treffer.
 * Steckt ein Feldname in einem anderen, liest der Eingang den falschen Wert -
 * ohne Fehlermeldung. Das fuehrende Semikolon aus mt_check() verhindert das;
 * diese Zeile misst es an der wirklich erzeugten Antwortzeile.
 */
function mt_pruef_muster()
{
    $marken = array();
    foreach (array_keys(mt_status_felder()) as $feld) {
        $marken[] = (string) $feld;
    }
    foreach ((array) mt_geraete() as $nr => $g) {
        foreach ((array) (isset($g['endpunkte']) ? $g['endpunkte'] : array()) as $ep => $felder) {
            foreach ((array) $felder as $thema => $w) {
                $marken[] = strtoupper($ep . '_' . $thema);
            }
        }
    }
    $marken = array_values(array_unique($marken));
    if (!$marken) {
        return array(-1, mt_t('TEST.A_MUSTER_LEER'));
    }
    /* Die Antwortzeile so bauen, wie der Endpunkt sie baut: jedes Feld mit
     * fuehrendem Semikolon, auch das erste. */
    $zeile = 'MATTER';
    foreach ($marken as $m) {
        $zeile .= ';' . $m . '=1';
    }
    /* Gesucht wird das, was Loxone in der Antwortzeile WIRKLICH sucht: die
     * Zeichenfolge zwischen den beiden \i des Musters, also ';NAME='. Das
     * Muster selbst (mt_check) enthaelt die \i-Marken und kommt in der Zeile
     * nirgends woertlich vor - wer danach sucht, zaehlt immer null und meldet
     * jede Marke als doppelt. (Erster Lauf dieser Zeile: genau das ist
     * passiert.) */
    $doppelt = array();
    foreach ($marken as $m) {
        if (substr_count($zeile, ';' . $m . '=') !== 1) {
            $doppelt[] = $m;
        }
    }
    if ($doppelt) {
        return array(0, sprintf(mt_t('TEST.A_MUSTER_FEHL'), mt_e(implode(', ', $doppelt))));
    }
    return array(1, sprintf(mt_t('TEST.A_MUSTER_OK'), count($marken)));
}

/**
 * Steht die Fabric am neuen Ort - und liegt noch etwas am alten?
 *
 * Bis 0.9.16 lag sie in data/plugins/<ordner>/matter, und der Installer
 * loescht diesen Baum bei jedem Upgrade. Wer von einer alten Fassung kommt
 * und den Container noch nicht neu angelegt hat, laeuft weiter gegen den
 * alten Pfad - und verliert die Fabric beim naechsten Update.
 */
/**
 * Antwortet der Border-Router - ohne irgendetwas zu speichern?
 *
 * Der Knopf im Reiter "Anlernen" holt das Dataset UND legt es in die
 * Konfiguration. Diese Zeile tut nur das Erste. mt_thread_dataset_holen() ist
 * dafuer schon gebaut: sie fragt ab und gibt zurueck, gespeichert wird erst im
 * Handler. Hier wird nichts weitergereicht - der Rueckgabewert wandert in
 * einen Zwischenspeicher und in den Antworttext, nicht in mt_config().
 *
 * Zwischengespeichert wie die beiden anderen Netzzeilen: ein Abruf haengt an
 * einer Zeitschranke von fuenf Sekunden fuer den Verbindungsaufbau, und die
 * Selbstpruefung laeuft bei jedem Aufruf des Reiters Test. Der Schluessel des
 * Zwischenspeichers ist die Adresse - wer sie aendert, bekommt sofort eine
 * frische Messung.
 *
 * Drei Ausgaenge, und der dritte ist der wichtige:
 *   1  der Border-Router hat ein Dataset geliefert
 *   0  eine Adresse steht da, aber es kam keins  (Kreuz - der Bediener hat
 *      das Feld ausgefuellt, also soll es auch tragen)
 *  -1  gar keine Adresse eingetragen            (Strich - trifft nicht zu)
 */
function mt_pruef_border($hoechstalter = 120)
{
    $cfg = mt_config();
    $adr = is_scalar($cfg['thread_br']) ? trim((string) $cfg['thread_br']) : '';
    if ($adr === '') {
        return array(-1, mt_t('TEST.A_BR_LEER'));
    }

    $f = mt_paths()['datadir'] . '/.border.cache';   // Zwischenspeicher, keine Einstellung
    $d = mt_json_lesen($f);
    if (isset($d['ts'], $d['adr'], $d['stand'], $d['text']) && (string) $d['adr'] === $adr) {
        $alter = time() - (int) $d['ts'];
        if ($alter >= 0 && $alter <= $hoechstalter) {
            return mt_pruef_border_satz((int) $d['stand'], (string) $d['text'], $alter, $cfg);
        }
    }

    list($stand, $text) = mt_thread_dataset_holen($adr);
    /* Was zurueckkommt, ist bei Erfolg das Dataset selbst - ein Geheimnis der
     * Anlage. Es geht NICHT in den Zwischenspeicher und NICHT in die Anzeige;
     * gemerkt wird nur seine Laenge und, ob es zum gespeicherten passt. */
    $merk = $stand === 1
        ? (strlen($text) . ' ' . (hash_equals((string) $cfg['thread_dataset'], $text) ? 'gleich' : 'anders'))
        : $text;
    mt_json_schreiben($f, array('adr' => $adr, 'stand' => (int) $stand,
                                'text' => $merk, 'ts' => time()));
    return mt_pruef_border_satz((int) $stand, $merk, 0, $cfg);
}

/** Den Satz zum gemerkten Ergebnis bilden. Getrennt, damit der Zwischen-
 *  speicher und der frische Abruf durch dieselbe Stelle gehen. */
function mt_pruef_border_satz($stand, $merk, $alter, $cfg)
{
    $zusatz = $alter > 0 ? ' ' . sprintf(mt_t('TEST.A_PROBE_ALT'), (int) $alter) : '';
    if ($stand !== 1) {
        /* Stand 0 = die Adresse passt nicht ins Muster, Stand 2 = sie passt,
         * aber es kam kein Dataset. Beides ist hier ein Kreuz: das Feld ist
         * ausgefuellt, also soll der Abruf tragen. Der Text kommt aus
         * mt_thread_dataset_holen() und nennt schon, woran es lag. */
        return array(0, sprintf(mt_t('TEST.A_BR_FEHL'), mt_e($merk)) . $zusatz);
    }
    list($laenge, $gleich) = array_pad(explode(' ', $merk, 2), 2, '');
    $gespeichert = trim((string) $cfg['thread_dataset']);
    if ($gespeichert === '') {
        return array(1, sprintf(mt_t('TEST.A_BR_NEU'), (int) $laenge) . $zusatz);
    }
    return array(1, sprintf(mt_t($gleich === 'gleich' ? 'TEST.A_BR_GLEICH' : 'TEST.A_BR_ANDERS'),
                            (int) $laenge) . $zusatz);
}

function mt_pruef_fabric()
{
    $neu = mt_fabric_pfad();
    $alt = mt_fabric_pfad_alt();
    $altda = is_dir($alt) && count((array) @scandir($alt)) > 2;
    if ($altda) {
        return array(0, sprintf(mt_t('TEST.A_FABRIC_ALT'), mt_e($alt), mt_e($neu)));
    }
    if (!is_dir($neu)) {
        return array(-1, sprintf(mt_t('TEST.A_FABRIC_KEINE'), mt_e($neu)));
    }
    $g = mt_fabric_groesse($neu);
    return array(1, sprintf(mt_t('TEST.A_FABRIC_OK'), mt_e($neu), (int) round($g / 1024)));
}

function mt_pruef_reiter()
{
    $datei = __DIR__ . '/index.php';
    if (!is_file($datei)) {
        return array(-1, sprintf(mt_t('TEST.A_REITER_UNBEKANNT'), mt_e($datei)));
    }
    $t = (string) @file_get_contents($datei);

    // Positivliste: die Zeichenkette zwischen "'/^tab-(" und ")$/'".
    // Bewusst mit strpos statt einem Ausdruck: ein Suchausdruck, der zu viel
    // trifft, ist hier schon einmal teuer geworden.
    $liste = array();
    $a = strpos($t, "'/^tab-(");
    if ($a !== false) {
        $b = strpos($t, ")\$/'", $a);
        if ($b !== false) {
            $liste = explode('|', substr($t, $a + 8, $b - $a - 8));
        }
    }
    preg_match_all('/data-ziel="tab-([a-z]+)"/', $t, $m);
    $leiste = $m[1];
    preg_match_all('/id="tab-([a-z]+)"/', $t, $m);
    $flaechen = $m[1];
    preg_match_all('/href="index\.php\?form=([a-z]+)"/', $t, $m);
    $verweise = $m[1];

    $mengen = array(
        'TEST.W_LISTE'    => $liste,
        'TEST.W_LEISTE'   => $leiste,
        'TEST.W_FLAECHEN' => $flaechen,
        'TEST.W_VERWEISE' => $verweise,
    );
    foreach ($mengen as $name => $werte) {
        if (!$werte) {
            return array(0, sprintf(mt_t('TEST.A_REITER_LEER'), mt_e(mt_t($name))));
        }
    }
    $soll = $liste;
    sort($soll);
    $abweichungen = array();
    foreach ($mengen as $name => $werte) {
        $ist = array_values(array_unique($werte));
        sort($ist);
        if ($ist !== $soll) {
            $abweichungen[] = mt_t($name) . ': ' . implode(', ', $ist);
        }
    }
    if ($abweichungen) {
        return array(0, sprintf(mt_t('TEST.A_REITER_FEHL'),
            mt_e(implode(' | ', $soll)), mt_e(implode(' / ', $abweichungen))));
    }
    return array(1, sprintf(mt_t('TEST.A_REITER_OK'), count($soll), mt_e(implode(', ', $soll))));
}

/**
 * Fuehren Oberflaeche und Dienst dieselben Vorgabewerte?
 *
 * mt_vorgaben() in mt_lib.php verlangt das im Kommentar seit jeher ("Muessen
 * zu VORGABEN in bin/matter_dienst.py passen"), geprueft hat es niemand. Ein
 * Schluessel, den nur eine der beiden Seiten kennt, faellt sonst erst auf,
 * wenn ein Wert unerklaerlich auf die Werkseinstellung zurueckspringt.
 */
function mt_pruef_vorgaben()
{
    $datei = mt_paths()['bindir'] . '/matter_dienst.py';
    if (!is_file($datei)) {
        return array(-1, sprintf(mt_t('TEST.A_VORGABEN_UNBEKANNT'), mt_e($datei)));
    }
    $t = (string) @file_get_contents($datei);
    $a = strpos($t, 'VORGABEN = {');
    if ($a === false) {
        return array(-1, sprintf(mt_t('TEST.A_VORGABEN_UNBEKANNT'), mt_e($datei)));
    }
    $b = strpos($t, "\n}", $a);
    if ($b === false) {
        return array(-1, sprintf(mt_t('TEST.A_VORGABEN_UNBEKANNT'), mt_e($datei)));
    }
    preg_match_all('/"([a-z_0-9]+)"\s*:/', substr($t, $a, $b - $a), $m);
    $dienst = array_values(array_unique($m[1]));
    $ober = array_keys(mt_vorgaben());
    sort($dienst);
    sort($ober);
    if (!$dienst) {
        return array(-1, sprintf(mt_t('TEST.A_VORGABEN_UNBEKANNT'), mt_e($datei)));
    }
    $nur_dienst = array_diff($dienst, $ober);
    $nur_ober = array_diff($ober, $dienst);
    if ($nur_dienst || $nur_ober) {
        return array(0, sprintf(mt_t('TEST.A_VORGABEN_FEHL'),
            mt_e($nur_dienst ? implode(', ', $nur_dienst) : '-'),
            mt_e($nur_ober ? implode(', ', $nur_ober) : '-')));
    }
    return array(1, sprintf(mt_t('TEST.A_VORGABEN_OK'), count($ober)));
}

function mt_pruefungen()
{
    $cfg = mt_config();
    $zeilen = array();

    // --- Voraussetzungen des Wirtssystems ---
    $a = mt_architektur();
    $zeilen[] = mt_pruefzeile($a['ok'], mt_t('TEST.F_ARCH'),
        $a['ok'] ? mt_e($a['bogen']) : sprintf(mt_t('TEST.A_ARCH_FEHL'), mt_e($a['bogen'])));

    $ip = mt_ipv6_zustand();
    $zeilen[] = mt_pruefzeile($ip['ok'], mt_t('TEST.F_IPV6'),
        $ip['ok'] ? mt_e($ip['text']) : mt_e($ip['text']) . ' ' . mt_t('TEST.A_IPV6_FEHL'));

    // --- Matter-Server ---
    $zu = mt_container_zustand();
    if (!empty($cfg['eigener_container'])) {
        $stand = $zu === 'laeuft' ? 1 : 0;
        $text = array(
            'laeuft'      => mt_t('TEST.A_CONT_LAEUFT'),
            'gestoppt'    => mt_t('TEST.A_CONT_GESTOPPT'),
            'fehlt'       => mt_t('TEST.A_CONT_FEHLT'),
            'kein_docker' => mt_t('TEST.A_CONT_KEIN_DOCKER'),
        );
        $zeilen[] = mt_pruefzeile($stand, mt_t('TEST.F_CONTAINER'),
            isset($text[$zu]) ? $text[$zu] : $zu);
    } else {
        $zeilen[] = mt_pruefzeile(-1, mt_t('TEST.F_CONTAINER'), mt_t('TEST.A_CONT_FREMD'));
    }

    // Nimmt auf dem Port ueberhaupt jemand Verbindungen an?
    // Der Verbindungsversuch steckt in mt_erreichbar() und wird kurz
    // zwischengespeichert - bis 0.9.9 lief er bei JEDEM Seitenaufruf, mit drei
    // Sekunden Zeitueberlauf, auch wenn nur das Protokoll gefragt war. Damit
    // die Antwort nicht heimlich alt wird, steht ihr Alter dabei.
    list($erreichbar, $probe_alter, $probe_fehler) = mt_erreichbar();
    $adresse = mt_e($cfg['server_host'] . ':' . $cfg['server_port']);
    $antwort = $erreichbar ? $adresse
        : sprintf(mt_t('TEST.A_NICHT_ERREICHBAR'), $adresse, mt_e($probe_fehler));
    if ($probe_alter > 0) {
        $antwort .= ' ' . sprintf(mt_t('TEST.A_PROBE_ALT'), (int) $probe_alter);
    }
    $zeilen[] = mt_pruefzeile($erreichbar, mt_t('TEST.F_ERREICHBAR'), $antwort);

    $pid = mt_dienst_pid();
    $zeilen[] = mt_pruefzeile($pid > 0 ? 1 : 0, mt_t('TEST.F_DIENST'),
        $pid > 0 ? mt_t('TEST.A_DIENST_LAEUFT') . ' ' . $pid
                 : (mt_dienst_soll() ? mt_t('TEST.A_DIENST_SOLL_TOT') : mt_t('TEST.A_DIENST_GESTOPPT')));

    // Lebt der Dienst noch, oder steht der Prozess nur da?
    // Die Prozessnummer beantwortet das nicht: ein Prozess kann laufen und
    // nichts mehr tun. Der Herzschlag schreibt seinen Zeitstempel in die
    // zustand.json, unabhaengig von MQTT.
    $zst = mt_zustand();
    $hz = (int) $cfg['herzschlag'];
    $letztes = isset($zst['herzschlag']) ? (int) $zst['herzschlag'] : 0;
    if ($pid === 0) {
        $zeilen[] = mt_pruefzeile(-1, mt_t('TEST.F_LEBEN'), mt_t('TEST.A_LEBEN_DIENST_AUS'));
    } elseif ($hz === 0) {
        $zeilen[] = mt_pruefzeile(-1, mt_t('TEST.F_LEBEN'), mt_t('TEST.A_LEBEN_AUS'));
    } elseif ($letztes === 0) {
        $zeilen[] = mt_pruefzeile(-1, mt_t('TEST.F_LEBEN'), mt_t('TEST.A_LEBEN_KEINS'));
    } else {
        $alter = max(0, time() - $letztes);
        // Drei Takte Luft: ein einzelnes verpasstes Lebenszeichen ist kein
        // Ausfall. Dieselbe Regel wie bei den Ausfallschwellen in Loxone.
        $gut = $alter <= 3 * $hz;
        $zeilen[] = mt_pruefzeile($gut ? 1 : 0, mt_t('TEST.F_LEBEN'),
            sprintf(mt_t($gut ? 'TEST.A_LEBEN_OK' : 'TEST.A_LEBEN_ALT'), $alter, $hz));
    }

    $srv = mt_serverinfo();
    if ($srv) {
        // if ($srv) prueft nur, ob das Feld ueberhaupt da ist - nicht, ob es
        // die einzelnen Schluessel enthaelt. Ein Abbild aus einer aelteren
        // Fassung hat sie nicht, und unter PHP 8 stuende dann eine Warning in
        // der Pruefzeile, die den Zustand melden soll.
        $hol = function ($name, $leer = '?') use ($srv) {
            return isset($srv[$name]) && $srv[$name] !== null && $srv[$name] !== ''
                ? $srv[$name] : $leer;
        };
        $zeilen[] = mt_pruefzeile(1, mt_t('TEST.F_SERVERINFO'),
            'SDK ' . mt_e($hol('sdk_version')) . ', Schema ' . mt_e($hol('schema_version'))
            . ', Fabric ' . mt_e($hol('fabric_id')));
        // Nur eine Auskunft, kein Kreuz: das Plugin fuehrt selbst keine
        // Schemafassung. Es benutzt eine Handvoll Befehle, und eine Zahl dafuer
        // zu erfinden waere eine erfundene Zahl. Beim Umstieg auf einen anderen
        // Matter-Server ist das hier die erste Stelle zum Nachsehen.
        $zeilen[] = mt_pruefzeile(-1, mt_t('TEST.F_SCHEMA'),
            isset($srv['min_schema']) && $srv['min_schema'] !== null
                ? sprintf(mt_t('TEST.A_SCHEMA'), mt_e($hol('schema_version')),
                          mt_e($hol('min_schema')))
                : mt_t('TEST.A_SCHEMA_KEINS'));
        $zeilen[] = mt_pruefzeile(!empty($srv['bluetooth']) ? 1 : -1, mt_t('TEST.F_BT'),
            !empty($srv['bluetooth']) ? mt_t('TEST.A_BT_JA') : mt_t('TEST.A_BT_NEIN'));
        $creds = !empty($srv['wlan_gesetzt']) || !empty($srv['thread_gesetzt']);
        $zeilen[] = mt_pruefzeile($creds ? 1 : -1, mt_t('TEST.F_CREDS'),
            $creds ? (!empty($srv['wlan_gesetzt']) ? mt_t('TEST.A_CREDS_WLAN') : '')
                     . (!empty($srv['thread_gesetzt']) ? ' ' . mt_t('TEST.A_CREDS_THREAD') : '')
                   : mt_t('TEST.A_CREDS_KEINE'));
    } else {
        $zeilen[] = mt_pruefzeile(0, mt_t('TEST.F_SERVERINFO'), mt_t('TEST.A_KEINE_SERVERINFO'));
    }

    // "0 Geraete" war bis 0.9.9 immer ein rotes Kreuz - auch auf einer frisch
    // installierten Anlage, an der noch gar nichts angelernt sein KANN. Ein
    // Kreuz, das nichts bedeutet, ist schlimmer als keine Pruefung: man sucht
    // dann dort. Hat der Dienst noch nie verbunden, ist das jetzt ein Hinweis.
    $geraete = mt_geraete();
    if (count($geraete) > 0) {
        $zeilen[] = mt_pruefzeile(1, mt_t('TEST.F_GERAETE'),
            sprintf(mt_t('TEST.A_GERAETE'), count($geraete)));
    } elseif (!$zst || !isset($zst['ts'])) {
        $zeilen[] = mt_pruefzeile(-1, mt_t('TEST.F_GERAETE'), mt_t('TEST.A_GERAETE_NIE'));
    } else {
        $zeilen[] = mt_pruefzeile(0, mt_t('TEST.F_GERAETE'), mt_t('TEST.A_KEINE_GERAETE'));
    }

    $z = mt_zustand();
    if (!empty($z['fehler'])) {
        $zeilen[] = mt_pruefzeile(0, mt_t('TEST.F_LETZTER_FEHLER'), mt_e($z['fehler']));
    }

    $m = mt_mqtt_zustand();
    if (!$m['gefunden']) {
        $zeilen[] = mt_pruefzeile(0, mt_t('TEST.F_MQTT'), mt_t('TEST.A_MQTT_NICHT_GEFUNDEN'));
    } elseif ($m['autostart']) {
        $zeilen[] = mt_pruefzeile(1, mt_t('TEST.F_MQTT'),
            mt_e($m['broker']) . ':' . mt_e($m['brokerport']) . ' (UDP ' . (int) $m['udpport'] . ')');
    } else {
        $zeilen[] = mt_pruefzeile(0, mt_t('TEST.F_MQTT'), mt_t('TEST.A_MQTT_AUS'));
    }

    $zeilen[] = mt_pruefzeile(!empty($cfg['steuerung_ein']) ? 1 : -1, mt_t('TEST.F_STEUERUNG'),
        !empty($cfg['steuerung_ein']) ? mt_t('TEST.A_STEUERUNG_EIN') : mt_t('TEST.A_STEUERUNG_AUS'));

    $tab = mt_tabelle();
    $zeilen[] = mt_pruefzeile(!empty($tab['cluster']) ? 1 : 0, mt_t('TEST.F_TABELLE'),
        !empty($tab['cluster'])
            ? sprintf(mt_t('TEST.A_TABELLE'), count($tab['cluster']),
                      array_sum(array_map(function ($c) {
                          return count(isset($c['attribute']) ? $c['attribute'] : array());
                      }, $tab['cluster'])))
            : mt_t('TEST.A_TABELLE_FEHLT'));

    // --- Die Loxone-Vorlagen wirklich erzeugen und einlesen ---
    //
    // Ein Anfuehrungszeichen oder ein Umlaut im Geraetenamen zerlegt die
    // Datei, und Loxone Config meldet dazu nichts Brauchbares. Deshalb wird
    // hier erzeugt und sofort wieder eingelesen: wohlgeformt oder nicht.
    if (!function_exists('simplexml_load_string') || !function_exists('libxml_use_internal_errors')) {
        /* Ohne php-xml laesst sich die Vorlage nicht pruefen. Das ist ein
         * Strich, kein Kreuz - und es steht dabei, was fehlt. Ungesichert
         * waere es ein fataler Fehler und die ganze Seite bliebe weiss. */
        $zeilen[] = mt_pruefzeile(-1, mt_t('TEST.F_VORLAGE'), mt_t('TEST.A_VORLAGE_KEIN_XML'));
        return $zeilen;
    }
    $mt_vorher = libxml_use_internal_errors(true);
    $mt_kaputt = array();
    $mt_gezaehlt = 0;
    $mt_proben = array('VI alle' => mt_vorlage_alle());
    foreach (array_keys(mt_geraete()) as $mt_nr) {
        $mt_proben['VI ' . (int) $mt_nr] = mt_vorlage((int) $mt_nr);
        $mt_proben['VQ ' . (int) $mt_nr] = mt_vorlage_out((int) $mt_nr);
    }
    foreach ($mt_proben as $mt_was => $mt_paar) {
        $mt_gezaehlt++;
        libxml_clear_errors();
        if (simplexml_load_string($mt_paar[1]) === false) {
            $mt_fehler = libxml_get_errors();
            $mt_kaputt[] = $mt_was . ' (' . (isset($mt_fehler[0])
                ? trim($mt_fehler[0]->message) : '?') . ')';
        }
    }
    libxml_clear_errors();
    libxml_use_internal_errors($mt_vorher);
    $zeilen[] = mt_pruefzeile($mt_kaputt ? 0 : 1, mt_t('TEST.F_VORLAGE'),
        $mt_kaputt ? sprintf(mt_t('TEST.A_VORLAGE_FEHL'), mt_e(implode(', ', $mt_kaputt)))
                   : sprintf(mt_t('TEST.A_VORLAGE'), $mt_gezaehlt));

    // --- Ungepruefte Cluster ausweisen ---
    $mt_ungeprueft = array();
    foreach ($tab['cluster'] as $mt_cl) {
        if (!empty($mt_cl['_ungeprueft'])) {
            $mt_ungeprueft[] = isset($mt_cl['name']) ? $mt_cl['name'] : '?';
        }
    }
    if ($mt_ungeprueft) {
        $zeilen[] = mt_pruefzeile(-1, mt_t('TEST.F_UNGEPRUEFT'),
            sprintf(mt_t('TEST.A_UNGEPRUEFT'), mt_e(implode(', ', $mt_ungeprueft))));
    }

    // --- Antwortet die Seite, die Loxone bedient? ---
    //
    // Die teuerste Fehlerklasse dieses Hauses: html/ und htmlauth/ liegen
    // installiert in getrennten Baeumen, und keine Leseprüfung sieht das. Nur
    // der echte Aufruf beantwortet es. Kommt gar keine Verbindung zustande,
    // ist das ein HINWEIS und kein Kreuz - im Pruefaufbau faellt genau dieser
    // Fall an, und ein rotes Kreuz, das nichts bedeutet, ist schlimmer als
    // keine Prüfung.
    list($e_ok, $e_code, $e_text, $e_url, $e_alter) = mt_selbsttest_endpunkt();
    $e_zusatz = $e_alter > 0 ? ' ' . sprintf(mt_t('TEST.A_PROBE_ALT'), (int) $e_alter) : '';
    if ($e_ok) {
        $zeilen[] = mt_pruefzeile(1, mt_t('TEST.F_ENDPUNKT'),
            sprintf(mt_t('TEST.A_ENDPUNKT_OK'), (int) $e_code,
                    mt_e(substr($e_text, 0, 70))) . $e_zusatz);
    } elseif ((int) $e_code === 0) {
        $zeilen[] = mt_pruefzeile(-1, mt_t('TEST.F_ENDPUNKT'),
            sprintf(mt_t('TEST.A_ENDPUNKT_UNKLAR'), mt_e($e_text), mt_e($e_url)) . $e_zusatz);
    } else {
        $zeilen[] = mt_pruefzeile(0, mt_t('TEST.F_ENDPUNKT'),
            sprintf(mt_t('TEST.A_ENDPUNKT_FEHL'), (int) $e_code, mt_e($e_text),
                    mt_e($e_url)) . $e_zusatz);
    }

    // --- Die Oberflaeche gegen sich selbst ---
    $r = mt_pruef_reiter();
    $k = mt_pruef_konfig();
    $zeilen[] = mt_pruefzeile($k[0], mt_t('TEST.F_KONFIG'), $k[1]);

    $fb = mt_pruef_fabric();
    $zeilen[] = mt_pruefzeile($fb[0], mt_t('TEST.F_FABRIC'), $fb[1]);

    $br = mt_pruef_border();
    $zeilen[] = mt_pruefzeile($br[0], mt_t('TEST.F_BORDER'), $br[1]);

    $fo = mt_pruef_formulare();
    $zeilen[] = mt_pruefzeile($fo[0], mt_t('TEST.F_FORMULARE'), $fo[1]);

    $th = mt_pruef_themen();
    $zeilen[] = mt_pruefzeile($th[0], mt_t('TEST.F_THEMEN'), $th[1]);

    $mu = mt_pruef_muster();
    $zeilen[] = mt_pruefzeile($mu[0], mt_t('TEST.F_MUSTER'), $mu[1]);

    $zeilen[] = mt_pruefzeile($r[0], mt_t('TEST.F_REITER'), $r[1]);
    $vg = mt_pruef_vorgaben();
    $zeilen[] = mt_pruefzeile($vg[0], mt_t('TEST.F_VORGABEN'), $vg[1]);

    return $zeilen;
}

/**
 * Aktionen des Reiters Test und des Reiters Geraete anlernen.
 * Rueckgabe: array(stand, Meldung).
 */
function mt_test_aktion($aktion)
{
    $nr = isset($_POST['test_geraet']) ? (string) $_POST['test_geraet'] : '1';
    if (!preg_match('/^[0-9]{1,3}$/', $nr)) {
        return array(0, mt_t('TEST.M_GERAET_UNGUELTIG'));
    }
    $ep = isset($_POST['test_endpunkt']) ? (string) $_POST['test_endpunkt'] : '1';
    if (!preg_match('/^[0-9]{1,3}$/', $ep)) {
        return array(0, mt_t('TEST.M_ENDPUNKT_UNGUELTIG'));
    }
    $geraete = mt_geraete();
    $knoten = isset($geraete[$nr]['node_id']) ? (int) $geraete[$nr]['node_id'] : 0;

    switch ($aktion) {
        case 'abruf':
            return mt_befehl_absetzen(array('aktion' => 'abruf'), 10);

        case 'ein':
        case 'aus':
        case 'umschalten':
            if ($knoten === 0) {
                return array(0, mt_t('TEST.M_GERAET_UNBEKANNT'));
            }
            return mt_befehl_absetzen(array('aktion' => $aktion, 'knoten' => $knoten,
                                            'endpunkt' => (int) $ep));

        case 'helligkeit':
        case 'luefter':
            if ($knoten === 0) {
                return array(0, mt_t('TEST.M_GERAET_UNBEKANNT'));
            }
            $w = isset($_POST['test_wert']) ? (string) $_POST['test_wert'] : '';
            if (!preg_match('/^[0-9]{1,3}$/', $w) || (int) $w > 100) {
                return array(0, mt_t('TEST.M_PROZENT_UNGUELTIG'));
            }
            return mt_befehl_absetzen(array('aktion' => $aktion, 'knoten' => $knoten,
                                            'endpunkt' => (int) $ep, 'wert' => (int) $w));

        case 'farbton':
            if ($knoten === 0) {
                return array(0, mt_t('TEST.M_GERAET_UNBEKANNT'));
            }
            // Das Wertfeld des Reiters fuehrt Prozent (0..100); ein Farbton
            // will Grad. Umgerechnet wird hier - und zwar sichtbar, damit
            // niemand 50 eingibt und 50 Grad erwartet.
            $w = isset($_POST['test_wert']) ? (string) $_POST['test_wert'] : '';
            if (!preg_match('/^[0-9]{1,3}$/', $w) || (int) $w > 100) {
                return array(0, mt_t('TEST.M_PROZENT_UNGUELTIG'));
            }
            return mt_befehl_absetzen(array('aktion' => 'farbton', 'knoten' => $knoten,
                                            'endpunkt' => (int) $ep,
                                            'wert' => (int) round((int) $w * 360 / 100)));

        case 'identify':
            if ($knoten === 0) {
                return array(0, mt_t('TEST.M_GERAET_UNBEKANNT'));
            }
            return mt_befehl_absetzen(array('aktion' => 'identify', 'knoten' => $knoten,
                                            'endpunkt' => (int) $ep, 'wert' => 15));

        case 'sperren':
        case 'entsperren':
            if ($knoten === 0) {
                return array(0, mt_t('TEST.M_GERAET_UNBEKANNT'));
            }
            return mt_befehl_absetzen(array('aktion' => $aktion, 'knoten' => $knoten,
                                            'endpunkt' => (int) $ep), 20);

        case 'anlernen':
            $code = isset($_POST['code']) ? trim((string) $_POST['code']) : '';
            // Nur Steuerzeichen und Leerraum entfernen - der Code selbst wird
            // NICHT gefiltert. Welche Zeichen bedeutungstragend sind, weiss
            // hier niemand sicher.
            $code = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', $code));
            if ($code === '') {
                return array(0, mt_t('ANLERN.M_CODE_LEER'));
            }
            return mt_befehl_absetzen(array('aktion' => 'anlernen', 'code' => $code,
                'nur_netz' => isset($_POST['nur_netz']) ? 1 : 0), 190);

        case 'wlan':
            $cfg = mt_config();
            if (trim((string) $cfg['wlan_ssid']) === '' || (string) $cfg['wlan_passwort'] === '') {
                return array(0, mt_t('ANLERN.M_WLAN_LEER'));
            }
            return mt_befehl_absetzen(array('aktion' => 'wlan', 'ssid' => $cfg['wlan_ssid'],
                                            'passwort' => $cfg['wlan_passwort']), 20);

        case 'thread':
            $cfg = mt_config();
            if (trim((string) $cfg['thread_dataset']) === '') {
                return array(0, mt_t('ANLERN.M_THREAD_LEER'));
            }
            return mt_befehl_absetzen(array('aktion' => 'thread',
                                            'dataset' => $cfg['thread_dataset']), 20);

        case 'entfernen':
            if ($knoten === 0) {
                return array(0, mt_t('TEST.M_GERAET_UNBEKANNT'));
            }
            return mt_befehl_absetzen(array('aktion' => 'entfernen', 'knoten' => $knoten), 70);

        case 'fenster':
            if ($knoten === 0) {
                return array(0, mt_t('TEST.M_GERAET_UNBEKANNT'));
            }
            return mt_befehl_absetzen(array('aktion' => 'fenster', 'knoten' => $knoten), 70);

        case 'anstupsen':
            if ($knoten === 0) {
                return array(0, mt_t('TEST.M_GERAET_UNBEKANNT'));
            }
            return mt_befehl_absetzen(array('aktion' => 'anstupsen', 'knoten' => $knoten), 70);

        case 'name':
            if ($knoten === 0) {
                return array(0, mt_t('TEST.M_GERAET_UNBEKANNT'));
            }
            // Nur Steuerzeichen entfernen. Was sonst im Namen stehen darf,
            // entscheidet nicht die Oberflaeche - der Dienst prueft Laenge und
            // Form und WEIST AB, statt zurechtzubiegen.
            $bez = trim(preg_replace('/[\x00-\x1F\x7F]/', '',
                (string) (isset($_POST['geraetename']) ? $_POST['geraetename'] : '')));
            if ($bez === '') {
                return array(0, mt_t('ANLERN.M_NAME_LEER'));
            }
            return mt_befehl_absetzen(array('aktion' => 'name', 'knoten' => $knoten,
                                            'bezeichnung' => $bez), 20);

        default:
            return array(0, mt_t('TEST.M_UNBEKANNT'));
    }
}
