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
    $erreichbar = 0;
    $fp = @fsockopen($cfg['server_host'], (int) $cfg['server_port'], $errno, $errstr, 3);
    if ($fp) {
        $erreichbar = 1;
        fclose($fp);
    }
    $zeilen[] = mt_pruefzeile($erreichbar, mt_t('TEST.F_ERREICHBAR'),
        $erreichbar ? mt_e($cfg['server_host'] . ':' . $cfg['server_port'])
                    : sprintf(mt_t('TEST.A_NICHT_ERREICHBAR'),
                              mt_e($cfg['server_host'] . ':' . $cfg['server_port']), mt_e($errstr)));

    $pid = mt_dienst_pid();
    $zeilen[] = mt_pruefzeile($pid > 0 ? 1 : 0, mt_t('TEST.F_DIENST'),
        $pid > 0 ? mt_t('TEST.A_DIENST_LAEUFT') . ' ' . $pid
                 : (mt_dienst_soll() ? mt_t('TEST.A_DIENST_SOLL_TOT') : mt_t('TEST.A_DIENST_GESTOPPT')));

    $srv = mt_serverinfo();
    if ($srv) {
        $zeilen[] = mt_pruefzeile(1, mt_t('TEST.F_SERVERINFO'),
            'SDK ' . mt_e($srv['sdk_version']) . ', Schema ' . (int) $srv['schema_version']
            . ', Fabric ' . mt_e($srv['fabric_id']));
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

    $geraete = mt_geraete();
    $zeilen[] = mt_pruefzeile(count($geraete) > 0 ? 1 : 0, mt_t('TEST.F_GERAETE'),
        count($geraete) > 0 ? sprintf(mt_t('TEST.A_GERAETE'), count($geraete))
                            : mt_t('TEST.A_KEINE_GERAETE'));

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
            if ($knoten === 0) {
                return array(0, mt_t('TEST.M_GERAET_UNBEKANNT'));
            }
            $w = isset($_POST['test_wert']) ? (string) $_POST['test_wert'] : '';
            if (!preg_match('/^[0-9]{1,3}$/', $w) || (int) $w > 100) {
                return array(0, mt_t('TEST.M_PROZENT_UNGUELTIG'));
            }
            return mt_befehl_absetzen(array('aktion' => 'helligkeit', 'knoten' => $knoten,
                                            'endpunkt' => (int) $ep, 'wert' => (int) $w));

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

        default:
            return array(0, mt_t('TEST.M_UNBEKANNT'));
    }
}
