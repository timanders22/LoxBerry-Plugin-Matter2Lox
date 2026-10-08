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
 *
 * Seit 0.9.30 (M6, Durchgang 30.09.2026) in BEIDEN Richtungen gemessen: die
 * Themen der Liste (Cluster-Tabelle, Ereignis- und abgeleitete Themen, die
 * festen Zeilen aus mt_themen_fest()) gegen die Staemme, die der Dienst selbst
 * bildet (matter_dienst.py --themen, mt_themen_dienst()). Bis 0.9.30 las die
 * Zeile nur die Tabelle und suchte im Dienst die Woerter online, ok und ts;
 * sie blieb gruen, waehrend hersteller, produkt, bezeichnung und firmware nie
 * hinausgingen, und ebenso bei einem zusaetzlich gesendeten Thema (gemessen,
 * Bericht oberflaeche Nr. 11). Laesst sich der Dienst nicht fragen, ist die
 * Zeile grau - "nicht gemessen" sieht nie wie "in Ordnung" aus.
 */
function mt_pruef_themen()
{
    $tab = mt_tabelle();
    $liste = array();
    foreach ((array) (isset($tab['cluster']) ? $tab['cluster'] : array()) as $c) {
        foreach ((array) (isset($c['attribute']) ? $c['attribute'] : array()) as $a) {
            if (isset($a['thema'])) {
                $liste[(string) $a['thema']] = 1;
            }
        }
    }
    foreach (array('ereignisthemen', 'abgeleitete_themen') as $gruppe) {
        $q = isset($tab[$gruppe]['themen']) ? $tab[$gruppe]['themen'] : array();
        foreach ((array) $q as $a) {
            if (isset($a['thema'])) {
                $liste[(string) $a['thema']] = 1;
            }
        }
    }
    if (!$liste) {
        return array(0, mt_t('TEST.A_THEMEN_LEER'));
    }
    foreach (array_keys(mt_themen_fest()) as $t) {
        $liste[(string) $t] = 1;
    }
    list($dienst, $grund) = mt_themen_dienst();
    if ($dienst === null) {
        return array(-1, sprintf(mt_t('TEST.A_THEMEN_UNKLAR'), mt_e($grund)));
    }
    $gesendet = array();
    foreach ($dienst as $t) {
        $gesendet[(string) $t] = 1;
    }
    $nur_liste = array();
    foreach ($liste as $t => $_egal) {
        if (!isset($gesendet[$t])) {
            $nur_liste[] = (string) $t;
        }
    }
    $nur_dienst = array();
    foreach ($gesendet as $t => $_egal) {
        if (!isset($liste[$t])) {
            $nur_dienst[] = (string) $t;
        }
    }
    sort($nur_liste);
    sort($nur_dienst);
    if ($nur_liste || $nur_dienst) {
        return array(0, sprintf(mt_t('TEST.A_THEMEN_FEHL'),
            mt_e($nur_liste ? implode(', ', $nur_liste) : '-'),
            mt_e($nur_dienst ? implode(', ', $nur_dienst) : '-')));
    }
    return array(1, sprintf(mt_t('TEST.A_THEMEN_OK'), count($liste)));
}

/**
 * Ist jedes Suchmuster eindeutig?
 *
 * Loxone sucht die Zeichenkette woertlich und nimmt den ERSTEN Treffer.
 * Steckt ein Feldname in einem anderen, liest der Eingang den falschen Wert -
 * ohne Fehlermeldung. Das fuehrende Semikolon aus mt_check() verhindert das;
 * diese Zeile misst es an der wirklich erzeugten Antwortzeile.
 */
/**
 * Trifft die Retain-Liste die Themen, die es wirklich gibt?
 *
 * Am Geraet gemessen (15.09.2026, Fassung 0.9.22): ZUSTANDSTHEMEN fuehrte 23
 * Namen, 19 davon kamen als Thema ueberhaupt nicht vor - sie waren gegen eine
 * fruehere Namensgebung geschrieben ("rauch" statt "rauch_alarm",
 * "verschlossen" statt "schloss"). Von 87 Themen gingen 83 fluechtig hinaus.
 * Das faellt an keiner Stelle auf: der Dienst meldet keinen Fehler, die
 * Oberflaeche zeigte nichts, und in Loxone merkt man es erst nach einem
 * Neustart des Brokers - an fehlenden Werten, nicht an einer Meldung.
 *
 * Gemessen wird gegen mt_zustandsthemen(), das die Liste AUS DEM DIENST
 * liest. Drei Namen der Geraeteebene (erreichbar, name, knoten) baut der
 * Dienst selbst und stehen in keiner Cluster-Tabelle; sie sind ausgenommen.
 */
function mt_pruef_retainliste()
{
    $z = mt_zustandsthemen();
    if (!$z) {
        return array(-1, mt_t('TEST.A_RETAINLISTE_LEER'));
    }
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
        return array(-1, mt_t('TEST.A_THEMEN_LEER'));
    }
    /* Nie retained, gleich was die Liste sagt: Lebenszeichen und
     * Erreichbarkeit (Regeln/07, Entscheidungen 18./19.09.2026). Steht einer
     * dieser Namen in ZUSTANDSTHEMEN, faengt ist_zustand() ihn im Dienst zwar
     * ab - die Liste waere aber eine zweite, falsche Aussage. */
    $nie = array();
    foreach (mt_nie_retained() as $t) {
        if (isset($z[$t])) {
            $nie[] = $t;
        }
    }
    if ($nie) {
        return array(0, sprintf(mt_t('TEST.A_RETAINLISTE_NIE'), mt_e(implode(', ', $nie))));
    }
    $geraeteebene = array('erreichbar' => 1, 'name' => 1, 'knoten' => 1);
    $tot = array();
    foreach ($z as $t => $_egal) {
        if (!isset($bekannt[$t]) && !isset($geraeteebene[$t])) {
            $tot[] = $t;
        }
    }
    if ($tot) {
        sort($tot);
        return array(0, sprintf(mt_t('TEST.A_RETAINLISTE_TOT'),
                                count($tot), mt_e(implode(', ', $tot))));
    }
    $retained = 0;
    foreach ($bekannt as $t => $_egal) {
        if (isset($z[$t])) {
            $retained++;
        }
    }
    return array(1, sprintf(mt_t('TEST.A_RETAINLISTE_OK'),
                            $retained, count($bekannt) - $retained));
}

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

/**
 * Tuer-1 (Verbesserungsbau 30.09.2026): meldet das Plugin Tueren und
 * Schloesser unter haus/tuer/? Gelesen wird die Merkdatei des Dienstes
 * (config/plugins/<ordner>.haus_themen.json) - sie nennt jedes Thema, das je
 * hinausging und noch nicht als leer bestaetigt ist. Aus ist eine
 * Entscheidung (grau); aus, aber noch gemerkte Themen, ist rot - dort steht
 * noch etwas im Broker, was Funkwacht oder Beschattungswaechter lesen.
 */
function mt_pruef_tuer($cfg)
{
    $haus = mt_haus_gemerkt();
    $liste = mt_e(implode(', ', array_keys($haus)));
    if (empty($cfg['tuer_haus'])) {
        return $haus ? array(0, sprintf(mt_t('TEST.A_TUER_REST'), count($haus), $liste))
                     : array(-1, mt_t('TEST.A_TUER_AUS'));
    }
    if (empty($cfg['mqtt_ein'])) {
        return array(0, mt_t('TEST.A_TUER_MQTT_AUS'));
    }
    return $haus ? array(1, sprintf(mt_t('TEST.A_TUER_AN'), count($haus), $liste))
                 : array(-1, mt_t('TEST.A_TUER_KEINE'));
}

/**
 * 0.9.35 (Nr. D2): Konflikte unter haus/tuer/ und die festen Namen.
 *
 * Der Dienst sendet unter haus/tuer/<name>/... nicht, wenn dort schon ein
 * zurueckbehaltener Wert eines ANDEREN Anbieters steht, und traegt solche
 * Themen in zustand.json als "haus_konflikte" ein. Die festen Namen (Geraet ->
 * Name) fuehrt er in config/plugins/<ordner>.haus_namen.json - eine
 * Umbenennung des Geraets verschiebt das Thema nicht. Rueckgabe array(stand,
 * html): rot bei Konflikten (bei eingeschaltetem Haken, sonst gelb/grau),
 * gruen mit Namen, grau ohne beides.
 */
function mt_pruef_tuer_konflikt($cfg)
{
    $z = mt_zustand();
    $konflikte = array();
    foreach ((isset($z['haus_konflikte']) && is_array($z['haus_konflikte']) ? $z['haus_konflikte'] : array()) as $t) {
        if (is_string($t) && preg_match('#^haus/tuer/[A-Za-z0-9_\-]{1,60}/[a-z_]{1,30}$#', $t)) {
            $konflikte[] = $t;
        }
    }
    $namen = mt_haus_namen();
    $ntext = array();
    foreach ($namen as $nr => $name) {
        $ntext[] = mt_e($nr . ' -> ' . $name);
    }
    $nsatz = $ntext ? sprintf(mt_t('TEST.A_TUERNAMEN'), '<span class="sm-mono">' . implode(', ', $ntext) . '</span>')
                    : mt_t('TEST.A_TUERNAMEN_KEINE');
    if ($konflikte) {
        return array(empty($cfg['tuer_haus']) ? -1 : 0,
                     sprintf(mt_t('TEST.A_TUERKONFLIKT'), '<span class="sm-mono">' . mt_e(implode(', ', $konflikte)) . '</span>')
                     . ' ' . $nsatz);
    }
    return array($namen ? 1 : -1, mt_t('TEST.A_TUERKONFLIKT_KEINER') . ' ' . $nsatz);
}

/**
 * 0.9.35 (Nr. E5): Welcher Matter-Server laeuft? SDK- und Schemafassung aus
 * loxone.json (server), dazu das Abbild der Container. Laufen ein
 * python-matter-server und ein matterjs-server auf derselben Fabric
 * gleichzeitig, ist das rot - das darf nie sein. Rueckgabe array(stand, html).
 */
function mt_pruef_serverart($cfg)
{
    $srv = mt_serverinfo();
    $teile = array();
    if ($srv) {
        $teile[] = sprintf(mt_t('TEST.A_SERVERART_INFO'),
            mt_e(isset($srv['sdk_version']) && is_scalar($srv['sdk_version']) ? $srv['sdk_version'] : '?'),
            mt_e(isset($srv['schema_version']) && is_scalar($srv['schema_version']) ? $srv['schema_version'] : '?'));
    } else {
        $teile[] = mt_t('TEST.A_SERVERART_KEINE_INFO');
    }
    $seite = mt_docker_seite();
    if (!mt_docker_da() || ($seite['lage'] !== '' && $seite['lage'] !== 'ok')) {
        $teile[] = mt_t('TEST.A_SERVERART_KEIN_DOCKER');
        return array($srv ? 1 : -1, implode(' ', $teile));
    }
    $liste = mt_container_bauarten();
    $eigen = array();
    $laufend = array();
    foreach ($liste as $c) {
        $teile[] = sprintf(mt_t('TEST.A_SERVERART_CONTAINER'), mt_e($c['name']), mt_e($c['abbild']),
            mt_e(mt_t($c['laeuft'] ? 'ALLG.LAEUFT' : 'ALLG.GESTOPPT')),
            mt_e(mt_t($c['eigene_fabric'] ? 'TEST.A_SERVERART_FABRIC_EIGEN' : 'TEST.A_SERVERART_FABRIC_FREMD')));
        if ($c['eigene_fabric']) {
            $eigen[$c['bauart']] = 1;
            if ($c['laeuft']) {
                $laufend[$c['bauart']] = 1;
            }
        }
    }
    if (!$liste) {
        $teile[] = mt_t('TEST.A_SERVERART_KEIN_CONTAINER');
    }
    if (count($laufend) > 1) {
        return array(0, mt_t('TEST.A_SERVERART_BEIDE_LAUFEN') . ' ' . implode(' ', $teile));
    }
    if (count($eigen) > 1) {
        return array(-1, mt_t('TEST.A_SERVERART_BEIDE_DA') . ' ' . implode(' ', $teile));
    }
    return array($srv || $liste ? 1 : -1, implode(' ', $teile));
}

/**
 * 0.9.36 (Docker-1-E5): Healthcheck-Zustand des eigenen Containers, aus
 * docker inspect (State.Health, ueber mt_container_ist()). Vier Antworten:
 * healthy (1), starting (Hinweis), unhealthy (0, mit Fehlschlaegen in Folge
 * und der letzten Ausgabe) und "kein Healthcheck" (Hinweis) - dort mit dem
 * Satz, ob das Plugin beim naechsten Neuanlegen einen eigenen setzt.
 * Laeuft der Container nicht oder antwortet Docker nicht: Hinweis, kein
 * Haken und kein Kreuz ueber etwas Ungemessenem.
 * $zustand: Ergebnis von mt_container_zustand() desselben Seitenaufrufs.
 * Rueckgabe array(stand, html).
 */
function mt_pruef_healthcheck($cfg, $zustand)
{
    if ($zustand === 'kein_docker') {
        return array(-1, mt_t('TEST.A_HC_NICHT_LESBAR'));
    }
    if ($zustand !== 'laeuft') {
        return array(-1, mt_t('TEST.A_HC_NICHT_LAUFEND'));
    }
    $ist = mt_container_ist(mt_container_name($cfg));
    if ($ist === null) {
        return array(-1, mt_t('TEST.A_HC_NICHT_LESBAR'));
    }
    if ($ist['health'] === 'healthy') {
        return array(1, mt_t('TEST.A_HC_HEALTHY'));
    }
    if ($ist['health'] === 'starting') {
        return array(-1, mt_t('TEST.A_HC_STARTING'));
    }
    if ($ist['health'] === 'unhealthy') {
        $t = sprintf(mt_t('TEST.A_HC_UNHEALTHY'), (int) $ist['health_folge']);
        if ($ist['health_ausgabe'] !== '') {
            $t .= ' ' . sprintf(mt_t('TEST.A_HC_AUSGABE'), mt_e($ist['health_ausgabe']));
        }
        return array(0, $t);
    }
    if ($ist['health'] === '') {
        $soll = mt_container_soll($cfg);
        return array(-1, mt_t($soll['health'] ? 'TEST.A_HC_KEINER_NEU' : 'TEST.A_HC_KEINER'));
    }
    return array(-1, sprintf(mt_t('TEST.A_HC_ANDERS'), mt_e($ist['health'])));
}

/** 0.9.35 (Nr. D2): die festen Namen unter haus/tuer/ (Geraetenummer => Name), Datei des Dienstes. */
function mt_haus_namen()
{
    $datei = preg_replace('/\.haus_themen\.json$/', '.haus_namen.json', mt_paths()['haus']);
    $d = mt_json_lesen($datei);
    $aus = array();
    foreach ((isset($d['namen']) && is_array($d['namen']) ? $d['namen'] : array()) as $nr => $name) {
        if (preg_match('/^[0-9]{1,4}$/', (string) $nr) && is_string($name) && preg_match('/^[a-z0-9_\-]{1,50}$/', $name)) {
            $aus[(int) $nr] = $name;
        }
    }
    ksort($aus);
    return $aus;
}

/* ==================================================================
 * 0.9.35 (Nr. 11): Netz-Selbsttest - was das Wirtssystem fuer Matter und
 * Thread mitbringen muss. Gelesen wird aus /proc und /sys (ohne root
 * lesbar); ss nur fuer die Prozessnamen, und wo es die ohne root nicht
 * gibt, steht das so da.
 * ================================================================== */

/** Die Hauptschnittstelle: primary_interface, sonst die der Standardroute.
 *  Rueckgabe array(name oder '', Quelle 'einstellung'|'route4'|'route6'|''). */
function mt_hauptschnittstelle($cfg)
{
    $pi = isset($cfg['primary_interface']) && is_scalar($cfg['primary_interface'])
        ? trim((string) $cfg['primary_interface']) : '';
    if ($pi !== '' && preg_match('/^[A-Za-z0-9_.\-]{1,15}$/', $pi)) {
        return array($pi, 'einstellung');
    }
    foreach ((array) @file('/proc/net/route', FILE_IGNORE_NEW_LINES) as $i => $z) {
        $t = preg_split('/\s+/', trim((string) $z));
        if ($i > 0 && count($t) >= 8 && $t[1] === '00000000' && $t[7] === '00000000'
            && preg_match('/^[A-Za-z0-9_.\-]{1,15}$/', $t[0])) {
            return array($t[0], 'route4');
        }
    }
    foreach ((array) @file('/proc/net/ipv6_route', FILE_IGNORE_NEW_LINES) as $z) {
        $t = preg_split('/\s+/', trim((string) $z));
        if (count($t) >= 10 && $t[0] === str_repeat('0', 32) && $t[1] === '00'
            && $t[9] !== 'lo' && preg_match('/^[A-Za-z0-9_.\-]{1,15}$/', $t[9])) {
            return array($t[9], 'route6');
        }
    }
    return array('', '');
}

/** Ein Wert aus /proc/sys/net/ipv6/conf/<if>/<name>, oder null. */
function mt_ipv6_conf($if, $name)
{
    $f = '/proc/sys/net/ipv6/conf/' . $if . '/' . $name;
    if (!is_readable($f)) {
        return null;
    }
    $w = trim((string) @file_get_contents($f));
    return preg_match('/^-?[0-9]+$/', $w) ? (int) $w : null;
}

/**
 * Router-Advertisements fuer Thread. Ein Thread-Border-Router kuendigt das
 * Praefix des Thread-Netzes per Router-Advertisement mit Route-Information
 * an. Linux nimmt sie nur an, wenn accept_ra 1 ist (2, wenn die Schnittstelle
 * weiterleitet) und accept_ra_rt_info_max_plen mindestens 64 - ab Werk 0.
 * Dann kennt der LoxBerry keinen Weg zu Thread-Geraeten, und sie sind nach
 * dem Anlernen "nicht erreichbar". Rueckgabe: zwei Zeilen array(stand, text).
 */
function mt_pruef_ra($cfg)
{
    list($if, $quelle) = mt_hauptschnittstelle($cfg);
    $thread = trim((string) $cfg['thread_dataset']) !== '' || trim((string) $cfg['thread_br']) !== '';
    $schlecht = $thread ? 0 : -1;
    if ($if === '') {
        return array(array(-1, mt_t('TEST.A_IF_KEINE')), array(-1, mt_t('TEST.A_IF_KEINE')));
    }
    $ife = mt_e($if);
    $ra = mt_ipv6_conf($if, 'accept_ra');
    $fw = mt_ipv6_conf($if, 'forwarding');
    $plen = mt_ipv6_conf($if, 'accept_ra_rt_info_max_plen');
    $zusatz = ' ' . sprintf(mt_t('TEST.A_IF_QUELLE'), $ife, mt_t('TEST.A_IF_' . strtoupper($quelle)));
    if ($ra === null) {
        $z1 = array(-1, sprintf(mt_t('TEST.A_RA_UNLESBAR'), $ife) . $zusatz);
    } elseif (($fw === 1 && $ra === 2) || ($fw !== 1 && $ra >= 1)) {
        $z1 = array(1, sprintf(mt_t('TEST.A_RA_OK'), $ra, (int) $fw) . $zusatz);
    } else {
        $soll = $fw === 1 ? 2 : 1;
        $z1 = array($schlecht, sprintf(mt_t('TEST.A_RA_FEHL'), $ra, (int) $fw, $soll, $ife, $soll, $ife, $soll)
                               . ($thread ? '' : ' ' . mt_t('TEST.A_NUR_THREAD')) . $zusatz);
    }
    if ($plen === null) {
        $z2 = array(-1, sprintf(mt_t('TEST.A_PLEN_KEIN'), $ife));
    } elseif ($plen >= 64) {
        $z2 = array(1, sprintf(mt_t('TEST.A_PLEN_OK'), $plen));
    } else {
        $z2 = array($schlecht, sprintf(mt_t('TEST.A_PLEN_FEHL'), $plen, $ife, $ife)
                               . ($thread ? '' : ' ' . mt_t('TEST.A_NUR_THREAD')));
    }
    return array($z1, $z2);
}

/** Bluetooth-Adapter (/sys/class/bluetooth/hci*) und rfkill. Rueckgabe array(stand, text). */
function mt_pruef_bt_adapter($cfg)
{
    $nr = is_numeric($cfg['bluetooth_adapter']) ? (int) $cfg['bluetooth_adapter'] : 0;
    $da = array();
    foreach ((array) @scandir('/sys/class/bluetooth') as $f) {
        if (preg_match('/^hci[0-9]+$/', (string) $f)) {
            $da[] = (string) $f;
        }
    }
    $gesperrt = array();
    foreach ((array) @scandir('/sys/class/rfkill') as $r) {
        if (!preg_match('/^rfkill[0-9]+$/', (string) $r)) {
            continue;
        }
        $b = '/sys/class/rfkill/' . $r;
        if (trim((string) @file_get_contents($b . '/type')) !== 'bluetooth') {
            continue;
        }
        $soft = trim((string) @file_get_contents($b . '/soft'));
        $hard = trim((string) @file_get_contents($b . '/hard'));
        if ($soft === '1' || $hard === '1') {
            $gesperrt[] = trim((string) @file_get_contents($b . '/name')) . ($hard === '1' ? ' (hard)' : ' (soft)');
        }
    }
    $eigen = (string) $cfg['eigener_container'] === '1';
    if (!$da) {
        return array($eigen ? 0 : -1, mt_t('TEST.A_BTA_KEINER'));
    }
    $liste = mt_e(implode(', ', $da));
    if ($gesperrt) {
        return array($eigen ? 0 : -1, sprintf(mt_t('TEST.A_BTA_RFKILL'), $liste, mt_e(implode(', ', $gesperrt))));
    }
    if (!in_array('hci' . $nr, $da, true)) {
        return array($eigen ? 0 : -1, sprintf(mt_t('TEST.A_BTA_FALSCH'), $nr, $liste));
    }
    if (!is_dir('/run/dbus')) {
        return array($eigen ? 0 : -1, sprintf(mt_t('TEST.A_BTA_DBUS'), $liste));
    }
    return array(1, sprintf(mt_t('TEST.A_BTA_OK'), $liste, $nr));
}

/**
 * Wer lauscht auf $port ($proto 'tcp' oder 'udp')? Aus /proc/net/<proto>(6);
 * Prozessnamen ueber ss, soweit es sie ohne root zeigt.
 * Rueckgabe: Liste array(adresse, prozess oder '').
 */
function mt_lauscher($proto, $port)
{
    $aus = array();
    foreach (array('', '6') as $v) {
        foreach ((array) @file('/proc/net/' . $proto . $v, FILE_IGNORE_NEW_LINES) as $i => $z) {
            $t = preg_split('/\s+/', trim((string) $z));
            if ($i === 0 || count($t) < 4 || strpos($t[1], ':') === false) {
                continue;
            }
            list($hex, $phex) = explode(':', $t[1], 2);
            // TCP: 0A = LISTEN; UDP: 07 = gebunden, ohne Gegenstelle
            if (hexdec($phex) !== (int) $port || $t[3] !== ($proto === 'tcp' ? '0A' : '07')) {
                continue;
            }
            $aus[] = array(mt_proc_adresse($hex), '');
        }
    }
    $ss = array();
    @exec('command -v ss 2>/dev/null', $ss);
    if ($aus && $ss) {
        $zeilen = array();
        @exec('timeout -k 1 5 ss -H -ln' . ($proto === 'tcp' ? 't' : 'u') . 'p '
              . escapeshellarg('sport = :' . (int) $port) . ' 2>/dev/null', $zeilen);
        $prozesse = array();
        foreach ($zeilen as $z) {
            if (preg_match_all('/\("([^"]+)",pid=([0-9]+)/', (string) $z, $m)) {
                foreach ($m[1] as $k => $n) {
                    $prozesse[] = $n . ' (PID ' . $m[2][$k] . ')';
                }
            }
        }
        $prozesse = array_values(array_unique($prozesse));
        foreach ($aus as $k => $a) {
            $aus[$k][1] = $prozesse ? implode(', ', $prozesse) : '';
        }
    }
    return $aus;
}

/** Adresse aus /proc/net/tcp(6) (Hex, Netzbyte-Reihenfolge je 32 Bit) als Text. */
function mt_proc_adresse($hex)
{
    if (strlen($hex) === 8) {
        return implode('.', array_reverse(array_map('hexdec', str_split($hex, 2))));
    }
    if (strlen($hex) === 32) {
        $bin = '';
        foreach (str_split($hex, 8) as $wort) {
            $bin .= strrev(pack('H*', $wort));
        }
        $txt = @inet_ntop($bin);
        return $txt !== false ? $txt : $hex;
    }
    return $hex;
}

/** Belegung von WebSocket- und Matter-Port. Rueckgabe: zwei Zeilen array(stand, text). */
function mt_pruef_ports($cfg)
{
    $port = is_numeric($cfg['server_port']) ? (int) $cfg['server_port'] : 5580;
    $eigen = (string) $cfg['eigener_container'] === '1';
    $host = strtolower(trim((string) $cfg['server_host']));
    $lokal_host = in_array($host, array('127.0.0.1', 'localhost', '::1'), true);
    $text = function ($liste) {
        $t = array();
        foreach ($liste as $l) {
            $t[] = mt_e($l[0]) . ' - ' . ($l[1] !== '' ? mt_e($l[1]) : mt_e(mt_t('TEST.A_PORT_PROZESS_UNBEKANNT')));
        }
        return implode('; ', $t);
    };
    $ws = mt_lauscher('tcp', $port);
    $alle = false;
    foreach ($ws as $l) {
        if (in_array($l[0], array('0.0.0.0', '::'), true)) {
            $alle = true;
        }
    }
    if (!$ws) {
        $z1 = array($eigen && $lokal_host ? 0 : -1, sprintf(mt_t('TEST.A_PORT_WS_FREI'), $port));
    } elseif ($eigen && $alle && (string) $cfg['server_lokal'] !== '1') {
        $z1 = array(-1, sprintf(mt_t('TEST.A_PORT_WS_ALLE'), $port, $text($ws)));
    } elseif ($eigen && $alle && $lokal_host) {
        $z1 = array(0, sprintf(mt_t('TEST.A_PORT_WS_ALT'), $port, $text($ws)));
    } else {
        $z1 = array(1, sprintf(mt_t('TEST.A_PORT_WS'), $port, $text($ws)));
    }
    $mt = mt_lauscher('udp', 5540);
    if (!$mt) {
        $z2 = array(-1, mt_t('TEST.A_PORT_MATTER_FREI'));
    } else {
        $z2 = array(1, sprintf(mt_t('TEST.A_PORT_MATTER'), $text($mt)));
        $prozesse = array();
        foreach ($mt as $l) {
            if ($l[1] !== '') {
                $prozesse[$l[1]] = 1;
            }
        }
        if (count($mt) > 2 || count($prozesse) > 1) {
            $z2 = array(0, sprintf(mt_t('TEST.A_PORT_MATTER_MEHR'), $text($mt)));
        }
    }
    return array($z1, $z2);
}

/** Die MQTT-Gateway-Dateien, die der Dienst schreibt (Vertrag). */
function mt_pruef_mqtt_dateien($cfg)
{
    $dir = mt_paths()['configdir'];
    $teile = array();
    $da = 0;
    foreach (array('mqtt_subscriptions.cfg', 'mqtt_resetaftersend.cfg') as $f) {
        $pfad = $dir . '/' . $f;
        if (is_file($pfad)) {
            $da++;
            $inhalt = trim((string) @file_get_contents($pfad, false, null, 0, 2000));
            $teile[] = '<span class="sm-mono">' . mt_e($f) . '</span>: '
                . ($inhalt !== '' ? '<span class="sm-mono">' . nl2br(mt_e($inhalt)) . '</span>' : mt_e(mt_t('TEST.A_MQTTDATEI_LEER')));
        } else {
            $teile[] = '<span class="sm-mono">' . mt_e($f) . '</span>: ' . mt_e(mt_t('TEST.A_MQTTDATEI_FEHLT'));
        }
    }
    $stand = $da > 0 ? 1 : -1;
    return array($stand, implode('<br>', $teile) . ($da === 0 ? ' ' . mt_t('TEST.A_MQTTDATEI_HINWEIS') : ''));
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

    /* 0.9.35 (Nr. 11): Netz-Selbsttest - Router-Advertisements fuer Thread,
     * Bluetooth-Adapter, Belegung der Ports, Gateway-Dateien. */
    list($ra1, $ra2) = mt_pruef_ra($cfg);
    $zeilen[] = mt_pruefzeile($ra1[0], mt_t('TEST.F_RA'), $ra1[1]);
    $zeilen[] = mt_pruefzeile($ra2[0], mt_t('TEST.F_PLEN'), $ra2[1]);
    $bta = mt_pruef_bt_adapter($cfg);
    $zeilen[] = mt_pruefzeile($bta[0], mt_t('TEST.F_BTA'), $bta[1]);
    list($po1, $po2) = mt_pruef_ports($cfg);
    $zeilen[] = mt_pruefzeile($po1[0], sprintf(mt_t('TEST.F_PORT_WS'), (int) $cfg['server_port']), $po1[1]);
    $zeilen[] = mt_pruefzeile($po2[0], mt_t('TEST.F_PORT_MATTER'), $po2[1]);

    // --- Matter-Server ---
    // 0.9.35 (Nr. E5): welche Bauart, und laufen nie beide auf derselben Fabric?
    $sa = mt_pruef_serverart($cfg);
    $zeilen[] = mt_pruefzeile($sa[0], mt_t('TEST.F_SERVERART'), $sa[1]);
    if (!empty($cfg['eigener_container'])) {
        // 0.9.35 (Nr. 9): Docker nur fragen, wenn das Plugin den Server betreibt.
        $zu = mt_container_zustand();
        $stand = $zu === 'laeuft' ? 1 : 0;
        $text = array(
            'laeuft'      => mt_t('TEST.A_CONT_LAEUFT'),
            'gestoppt'    => mt_t('TEST.A_CONT_GESTOPPT'),
            'fehlt'       => mt_t('TEST.A_CONT_FEHLT'),
            'kein_docker' => mt_t('TEST.A_CONT_KEIN_DOCKER'),
        );
        $zeilen[] = mt_pruefzeile($stand, mt_t('TEST.F_CONTAINER'),
            isset($text[$zu]) ? $text[$zu] : $zu);
        // 0.9.36 (Docker-1-E5): der Healthcheck-Zustand des Containers.
        $hc = mt_pruef_healthcheck($cfg, $zu);
        $zeilen[] = mt_pruefzeile($hc[0], mt_t('TEST.F_HC'), $hc[1]);
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

    /* Veroeffentlicht DIESES Plugin ueberhaupt? (Regeln/04, B46 aus
     * BatterieBMS 0.9.17, 06.09.2026)
     *
     * Die Zeile darunter liest den Autostart des GATEWAYS aus der
     * general.json - das ist eine Aussage ueber LoxBerry, nicht ueber dieses
     * Plugin. Steht der eigene Schalter auf aus, geht nichts an den Broker
     * und damit nichts an Loxone; der Reiter zeigte dazu trotzdem einen
     * gruenen Haken und konnte die beiden Faelle gar nicht unterscheiden.
     * Am Geraet gemessen (BatterieBMS, 06.09.2026): Dienst lief, Gateway
     * lief, 35 s Mithoeren am Broker bei 30 s Takt - keine einzige Nachricht.
     *
     * Grau statt rot: ausgeschaltet ist eine Entscheidung, kein Fehler. */
    $mqttEin = !empty($cfg['mqtt_ein']);
    $zeilen[] = mt_pruefzeile($mqttEin ? 1 : -1, mt_t('TEST.F_MQTT_EIN'),
        mt_t($mqttEin ? 'TEST.A_MQTT_EIN_JA' : 'TEST.A_MQTT_EIN_NEIN'));
    $tu = mt_pruef_tuer($cfg);
    $zeilen[] = mt_pruefzeile($tu[0], mt_t('TEST.F_TUER'), $tu[1]);
    // 0.9.35 (Nr. D2): Konflikte mit anderen Anbietern und die festen Namen.
    $tk = mt_pruef_tuer_konflikt($cfg);
    $zeilen[] = mt_pruefzeile($tk[0], mt_t('TEST.F_TUERKONFLIKT'), $tk[1]);

    $md = mt_pruef_mqtt_dateien($cfg);
    $zeilen[] = mt_pruefzeile($md[0], mt_t('TEST.F_MQTTDATEI'), $md[1]);

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

    // Die Marke "Aktualisierung laeuft" - zu jeder Regel gehoert das
    // Werkzeug, das sie findet (CLAUDE.md Punkt 6). Liegt sie, startet
    // kein Weg den Dienst; dann soll man das hier sehen und nicht raten.
    $um = mt_upgrade_marke();
    if ($um[0]) {
        $zeilen[] = mt_pruefzeile(0, mt_t('TEST.F_UPGRADE'),
            sprintf(mt_t('TEST.A_UPGRADE_LAEUFT'), (int) $um[1]));
    } elseif ($um[1] === -2) {
        $zeilen[] = mt_pruefzeile(0, mt_t('TEST.F_UPGRADE'), mt_t('TEST.A_UPGRADE_KAPUTT'));
    } elseif ($um[1] >= 0) {
        $zeilen[] = mt_pruefzeile(-1, mt_t('TEST.F_UPGRADE'),
            sprintf(mt_t('TEST.A_UPGRADE_ALT'), (int) round($um[1] / 60)));
    } else {
        $zeilen[] = mt_pruefzeile(1, mt_t('TEST.F_UPGRADE'), mt_t('TEST.A_UPGRADE_KEINE'));
    }

    $br = mt_pruef_border();
    $zeilen[] = mt_pruefzeile($br[0], mt_t('TEST.F_BORDER'), $br[1]);

    $fo = mt_pruef_formulare();
    $zeilen[] = mt_pruefzeile($fo[0], mt_t('TEST.F_FORMULARE'), $fo[1]);

    $th = mt_pruef_themen();
    $zeilen[] = mt_pruefzeile($th[0], mt_t('TEST.F_THEMEN'), $th[1]);

    $rl = mt_pruef_retainliste();
    $zeilen[] = mt_pruefzeile($rl[0], mt_t('TEST.F_RETAINLISTE'), $rl[1]);

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
    /* Nachtrag B-Nachzug 01.10.2026: is_string() statt (string) - eine Liste
     * ist ungueltig und wird gemeldet, ohne PHP-Warnung vor der Umleitung. */
    $nr = isset($_POST['test_geraet']) ? (is_string($_POST['test_geraet']) ? $_POST['test_geraet'] : '') : '1';
    if (!preg_match('/^[0-9]{1,3}$/', $nr)) {
        return array(0, mt_t('TEST.M_GERAET_UNGUELTIG'));
    }
    $ep = isset($_POST['test_endpunkt']) ? (is_string($_POST['test_endpunkt']) ? $_POST['test_endpunkt'] : '') : '1';
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
            $w = isset($_POST['test_wert']) && is_string($_POST['test_wert']) ? $_POST['test_wert'] : '';
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
            $w = isset($_POST['test_wert']) && is_string($_POST['test_wert']) ? $_POST['test_wert'] : '';
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
            $code = isset($_POST['code']) && is_string($_POST['code']) ? trim($_POST['code']) : '';
            // Nur Steuerzeichen und Leerraum entfernen - der Code selbst wird
            // NICHT gefiltert. Welche Zeichen bedeutungstragend sind, weiss
            // hier niemand sicher.
            $code = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', $code));
            if ($code === '') {
                return array(0, mt_t('ANLERN.M_CODE_LEER'));
            }
            $befehl = array('aktion' => 'anlernen', 'code' => $code,
                            'nur_netz' => isset($_POST['nur_netz']) ? 1 : 0);
            /* 0.9.35 (Nr. 14, Vertrag): mit IP-Adresse ruft der Dienst
             * commission_on_network(setup_pin_code, ip_addr) - das geht nur
             * mit dem manuellen Ziffern-Code (11 oder 21 Ziffern; Leerzeichen
             * und Bindestriche des Aufdrucks werden entfernt), nicht mit dem
             * MT:-Text eines QR-Codes. */
            $ip = isset($_POST['anlern_ip']) && is_string($_POST['anlern_ip']) ? trim($_POST['anlern_ip']) : '';
            if ($ip !== '') {
                $tm = mt_textmuster();
                if (!preg_match($tm['anlern_ip'], $ip)) {
                    return array(0, mt_t('ANLERN.M_IP_FORM'));
                }
                $ziffern = preg_replace('/[\s\-]/', '', $code);
                if (!preg_match('/^([0-9]{11}|[0-9]{21})$/', $ziffern)) {
                    return array(0, mt_t('ANLERN.M_IP_NUR_ZIFFERN'));
                }
                $befehl['code'] = $ziffern;
                $befehl['ip'] = $ip;
            }
            return mt_befehl_absetzen($befehl, 190);

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
            // 0.9.35 (Nr. 4): Pflichthaken - Entfernen loest das Geraet aus der Fabric.
            if (empty($_POST['entfernen_ja'])) {
                return array(0, sprintf(mt_t('ANLERN.M_ENTFERNEN_HAKEN'), (int) $nr));
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
                (isset($_POST['geraetename']) && is_string($_POST['geraetename']) ? $_POST['geraetename'] : '')));
            if ($bez === '') {
                return array(0, mt_t('ANLERN.M_NAME_LEER'));
            }
            return mt_befehl_absetzen(array('aktion' => 'name', 'knoten' => $knoten,
                                            'bezeichnung' => $bez), 20);

        default:
            return array(0, mt_t('TEST.M_UNBEKANNT'));
    }
}
