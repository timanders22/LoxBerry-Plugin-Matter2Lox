<?php
/**
 * Matter to Loxone - Bedienoberflaeche
 *
 * Reiter: Einstellungen | Geraete anlernen | MQTT | Einbindung in Loxone |
 *         Test | Logdateien
 *
 * Der Reiter "Geraete anlernen" kommt zu den fuenf des Hausstandards hinzu.
 * Er hat einen eigenen Reiter, weil das Anlernen ein einmaliger Vorgang mit
 * eigenen Voraussetzungen ist (Bluetooth, WLAN-Zugangsdaten, Thread-Dataset)
 * und in den Einstellungen untergehen wuerde.
 *
 * Diese Datei ist NUR Oberflaeche. Die Verbindung zum Matter-Server haelt der
 * Dienst (bin/matter_dienst.py), den Miniserver bedient
 * webfrontend/html/index.php.
 *
 * Praefix 'mt_', weil LBWeb::lbheader() SDK-Globale setzt.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$mt_gefunden = false;
foreach (array(
    dirname(dirname(__DIR__)) . '/html/plugins/' . basename(__DIR__) . '/mt_lib.php',
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/mt_lib.php',
    dirname(__DIR__) . '/html/mt_lib.php',
) as $mt_kandidat) {
    if (is_file($mt_kandidat)) {
        require_once $mt_kandidat;
        $mt_gefunden = true;
        break;
    }
}
if (!$mt_gefunden) {
    echo '<p><b>Fehler:</b> mt_lib.php wurde nicht gefunden. Bitte das Plugin neu installieren.</p>';
    exit;
}
require_once __DIR__ . '/mt_test.php';

$mt_p = mt_paths();
if ($mt_p['home'] !== '' && is_file($mt_p['home'] . '/libs/phplib/loxberry_system.php')) {
    require_once $mt_p['home'] . '/libs/phplib/loxberry_system.php';
    require_once $mt_p['home'] . '/libs/phplib/loxberry_web.php';
}

/* Aktiver Reiter. Die Positivliste MUSS jeden Reiter enthalten - fehlt einer,
 * ist er sichtbar und anklickbar, aber nach jedem Absenden springt die Seite
 * zurueck auf Einstellungen. */
$mt_muster = '/^tab-(settings|commission|mqtt|loxone|test|log)$/';
$mt_tab = 'tab-settings';

$mt_meldungen = array();
$mt_fehler = array();

/* ---------------------------------------------------------------- *
 * Der Wachposten - EIN Posten, vor allen Handlern.
 * Abgewiesen heisst gemeldet, und es wird NICHTS ausgefuehrt: $_POST
 * wird geleert, nur der aktive Reiter bleibt stehen, damit der Bediener
 * nach der Abweisung dort steht, wo er war.
 * ---------------------------------------------------------------- */
$mt_wache = mt_wachposten();
if ($mt_wache !== '') {
    $mt_reiter_merk = isset($_POST['activetab']) && is_string($_POST['activetab'])
        ? (string) $_POST['activetab'] : null;
    $_POST = array();
    if ($mt_reiter_merk !== null) {
        $_POST['activetab'] = $mt_reiter_merk;
    }
    $mt_fehler[] = $mt_wache;
}

/* Aktiver Reiter - NACH dem Wachposten. Der leert $_POST bis auf diesen
 * einen Wert; stuende die Wahl davor, uebernaehme die Seite den Reiter eines
 * abgewiesenen POST. Die Reihenfolge steht so auch im Kasten darunter. */
if (isset($_POST['activetab']) && is_string($_POST['activetab'])
        && preg_match($mt_muster, $_POST['activetab'])) {
    $mt_tab = $_POST['activetab'];
} elseif (isset($_GET['form']) && is_string($_GET['form'])
        && preg_match($mt_muster, 'tab-' . $_GET['form'])) {
    $mt_tab = 'tab-' . $_GET['form'];
}

$mt_testausgabe = '';
$mt_post = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST';

/* ==================================================================
 * DIE HANDLER STEHEN VOR lbheader() - DAS IST BAUVORSCHRIFT
 * ==================================================================
 *
 * Stand der Kopf davor, war er beim Aufruf von header() schon
 * geschrieben - "Cannot modify header information", und der Knopf
 * "Einstellungen sichern" lieferte eine Seite mit angehaengtem JSON
 * statt einer Datei.
 *
 * Am PHP-CLI ist das unsichtbar: header() ist dort wirkungslos und
 * headers_sent() immer falsch. Und wer OHNE gueltiges Formularmerkmal
 * misst, wird vom Wachposten abgewiesen, bevor der Handler anlaeuft.
 * Beides hat den Fehler lange verdeckt.
 *
 * Reihenfolge: Bibliothek, Konfiguration, Wachposten, Reiterwahl,
 * ALLE Handler samt Downloads, dann erst lbheader(), dann HTML.
 * ================================================================== */
/* ---------------- Vorlage herunterladen ---------------- */
if ($mt_post && isset($_POST['vorlage'])) {
    // 'alle' = Sammelvorlage, 'aus<N>' = virtuelle Ausgaenge, '<N>' = Eingaenge
    $mt_wunsch = (string) $_POST['vorlage'];
    if ($mt_wunsch === 'alle') {
        list($mt_name, $mt_inhalt) = mt_vorlage_alle();
    } elseif (preg_match('/^aus([0-9]{1,3})$/', $mt_wunsch, $mt_tr)) {
        list($mt_name, $mt_inhalt) = mt_vorlage_out((int) $mt_tr[1]);
        /* Ohne bekanntes Geraet enthaelt die Datei NULL Befehle. Sie waere
         * wohlgeformt und voellig nutzlos - und in Loxone Config faellt das
         * erst auf, wenn man sie importiert hat. Lieber ein Satz als ein
         * Download, der nichts enthaelt. */
        if (strpos($mt_inhalt, '<VirtualOutCmd') === false) {
            $mt_fehler[] = mt_t('LOX.M_VORLAGE_LEER');
            $mt_tab = 'tab-loxone';
            $mt_inhalt = null;
        }
    } else {
        /* Dieselbe Sorgfalt wie im Ausgangs-Zweig darueber: ein
         * unbekannter Geraetewunsch wird gemeldet, nicht still durch
         * Geraet 1 ersetzt, und eine Datei ohne einen einzigen Befehl geht
         * gar nicht erst hinaus. */
        $mt_nr = preg_match('/^[0-9]{1,3}$/', $mt_wunsch) ? (int) $mt_wunsch : 0;
        if ($mt_nr === 0) {
            $mt_fehler[] = mt_t('LOX.M_VORLAGE_LEER');
            $mt_tab = 'tab-loxone';
            $mt_inhalt = null;
            $mt_name = '';
        } else {
            list($mt_name, $mt_inhalt) = mt_vorlage($mt_nr);
            if (strpos((string) $mt_inhalt, '<VirtualInHttpCmd') === false) {
                $mt_fehler[] = mt_t('LOX.M_VORLAGE_LEER');
                $mt_tab = 'tab-loxone';
                $mt_inhalt = null;
            }
        }
    }
    if ($mt_inhalt !== null) {
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $mt_name . '"');
        echo $mt_inhalt;
        exit;
    }
}

/* ---------------- Fabric sichern ----------------
 *
 * Der Datenordner ist das Wertvollste, was dieses Plugin hat: Fabric und
 * Zertifikate. Kein Archiv liefert ihn mit, und wer ihn verliert, muss
 * JEDES Geraet zuruecksetzen und neu anlernen. Bis 0.9.9 warnte die
 * uninstall-Datei davor - einen Knopf zum Sichern gab es nicht. */
if ($mt_post && isset($_POST['fabric_sichern'])) {
    $mt_quelle = mt_fabric_pfad();
    if (!is_dir($mt_quelle)) {
        $mt_fehler[] = sprintf(mt_t('EINST.M_FABRIC_FEHLT'), mt_e($mt_quelle));
    } elseif (!mt_tar_da()) {
        $mt_fehler[] = mt_t('EINST.M_FABRIC_KEIN_TAR');
    } else {
        $mt_dname = 'matter-fabric-' . date('Y-m-d_H-i') . '.tar.gz';
        header('Content-Type: application/x-download');
        header('Content-Disposition: attachment; filename="' . $mt_dname . '"');
        /* Aus dem Ordner heraus packen, nicht den Pfad mitpacken: sonst
         * traegt das Archiv den absoluten Pfad des Rechners in sich. */
        passthru('tar -czf - -C ' . escapeshellarg($mt_quelle) . ' .');
        exit;
    }
    $mt_tab = 'tab-settings';
}

/* ---------------- Container aktualisieren ---------------- */
if ($mt_post && isset($_POST['container_akt'])) {
    list($mt_ok, $mt_ausgabe) = mt_container_aktualisieren();
    if ($mt_ok) { $mt_meldungen[] = $mt_ausgabe; } else { $mt_fehler[] = $mt_ausgabe; }
    $mt_tab = 'tab-settings';
}

/* ---------------- MQTT-Probewert ---------------- */
if ($mt_post && isset($_POST['mqtt_probe'])) {
    list($mt_ok, $mt_ausgabe) = mt_mqtt_probe();
    if ($mt_ok) { $mt_meldungen[] = $mt_ausgabe; } else { $mt_fehler[] = $mt_ausgabe; }
    $mt_tab = 'tab-mqtt';
}

/* ---------------- Einstellungen speichern ---------------- */
if ($mt_post && isset($_POST['speichern'])) {
    $mt_cfg = mt_config();
    $sauber = function ($feld) {
        // Nur Steuerzeichen, Anfuehrungszeichen und Leerraum entfernen - ein
        // hartes Filtern auf eine Positivliste zerstoert gueltige Eingaben.
        return trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', (string) (isset($_POST[$feld]) ? $_POST[$feld] : '')));
    };

    $host = $sauber('server_host');
    if ($host === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9\.\-:_\[\]]{0,80}$/', $host)) {
        $mt_fehler[] = mt_t('EINST.FEHLER_HOST');
    } else {
        $mt_cfg['server_host'] = $host;
    }

    foreach (array('server_port' => array(1, 65535), 'wartezeit' => array(0, 60),
                   'bluetooth_adapter' => array(0, 9), 'sendetakt' => array(0, 60),
                   'herzschlag' => array(0, 3600)) as $feld => $grenzen) {
        $w = $sauber($feld);
        if (!preg_match('/^[0-9]+$/', $w)) {
            $mt_fehler[] = sprintf(mt_t('EINST.FEHLER_ZAHL'), mt_t('EINST.L_' . strtoupper($feld)));
            continue;
        }
        if ((int) $w < $grenzen[0] || (int) $w > $grenzen[1]) {
            $mt_fehler[] = sprintf(mt_t('EINST.FEHLER_BEREICH'),
                mt_t('EINST.L_' . strtoupper($feld)), $grenzen[0], $grenzen[1]);
            continue;
        }
        $mt_cfg[$feld] = (int) $w;
    }

    $name = $sauber('container_name');
    if ($name !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,60}$/', $name)) {
        $mt_fehler[] = mt_t('EINST.FEHLER_CONTAINERNAME');
    } elseif ($name !== '') {
        $mt_cfg['container_name'] = $name;
    }
    $abbild = $sauber('container_abbild');
    if ($abbild !== '' && !preg_match('#^[A-Za-z0-9][A-Za-z0-9_./\-]{2,120}(:[A-Za-z0-9_.\-]{1,40})?$#', $abbild)) {
        $mt_fehler[] = mt_t('EINST.FEHLER_ABBILD');
    } elseif ($abbild !== '') {
        $mt_cfg['container_abbild'] = $abbild;
    }

    $mt_cfg['eigener_container'] = isset($_POST['eigener_container']) ? 1 : 0;
    $mt_cfg['steuerung_ein'] = isset($_POST['steuerung_ein']) ? 1 : 0;
    $mt_cfg['schloss_ein'] = isset($_POST['schloss_ein']) ? 1 : 0;

    if (!$mt_fehler) {
        if (mt_config_speichern($mt_cfg)) {
            $mt_meldungen[] = mt_t('EINST.GESPEICHERT');
        } else {
            $mt_fehler[] = sprintf(mt_t('EINST.FEHLER_SPEICHERN'), $mt_p['config']);
        }
    }
    $mt_tab = 'tab-settings';

    /* mqtt_ein und mqtt_topic werden hier bewusst NICHT angefasst: sie wohnen im
     * Reiter MQTT und haben dort ein eigenes Formular. Die Konfiguration
     * kommt aus mt_config(), die Werte ueberleben also unveraendert. Stuende
     * hier weiter "isset($_POST['mqtt_ein']) ? 1 : 0", wuerde jedes Speichern
     * der Einstellungen MQTT stillschweigend abschalten. */
}

/* ---------------- MQTT (eigener Reiter, eigenes Formular) ----------------
 *
 * Eigenes Formular UND eigener Handler gehoeren zusammen. Loesten beide
 * Formulare denselben Handler aus, setzte dieser die Haken des jeweils
 * nicht abgeschickten Formulars per isset() auf 0 - der Benutzer verloere
 * Werte, die er nie gesehen hat. Der Handler laedt darum den Bestand und
 * ruehrt ausschliesslich die MQTT-Werte an. */
if ($mt_post && isset($_POST['save_mqtt'])) {
    $mt_mcfg = mt_config();
    $mt_mcfg['mqtt_ein'] = isset($_POST['mqtt_ein']) ? 1 : 0;
    /* Der Haken fuer die Rohdurchreichung steht in DIESEM Formular (Reiter
     * MQTT) und wird deshalb auch hier gelesen. Bis 0.9.16 las ihn der
     * Handler des Reiters Einstellungen - der ihn nie mitgeschickt bekommt.
     * Folge: er liess sich gar nicht einschalten, und jedes Speichern der
     * Einstellungen schaltete ihn ab. Genau die Fehlerklasse, die der
     * Kommentar am Ende des Einstellungen-Handlers fuer mqtt_ein beschreibt. */
    $mt_mcfg['roh_ein'] = isset($_POST['roh_ein']) ? 1 : 0;
    $mt_mtopic = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '',
        (string) (isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : '')));
    if ($mt_mtopic === '' || !preg_match('#^[A-Za-z0-9_/\-]{1,64}$#', $mt_mtopic)) {
        $mt_fehler[] = mt_t('EINST.FEHLER_TOPIC');
    } else {
        $mt_mcfg['mqtt_topic'] = trim($mt_mtopic, '/');
    }
    /* Auswahl, welche Geraete ueberhaupt hinausgehen. Leer heisst alle -
     * das ist die Vorgabe, damit sich fuer bestehende Anlagen nichts
     * aendert. Was nicht ins Muster passt, wird ABGEWIESEN, nicht still
     * zurechtgebogen. */
    $mt_nur = trim((string) (isset($_POST['mqtt_nur']) ? $_POST['mqtt_nur'] : ''));
    if ($mt_nur !== '' && !preg_match('/^[0-9]{1,3}([ ,;]+[0-9]{1,3})*$/', $mt_nur)) {
        $mt_fehler[] = mt_t('EINST.FEHLER_MQTT_NUR');
    } else {
        $mt_mcfg['mqtt_nur'] = preg_replace('/[ ;]+/', ',', $mt_nur);
    }
    if (!$mt_fehler) {
        if (mt_config_speichern($mt_mcfg)) {
            $mt_meldungen[] = mt_t('EINST.GESPEICHERT');
        } else {
            /* Bis 0.9.16 fehlte dieser Zweig als einzigem der vier
             * Speichern-Handler: scheiterte das Schreiben, sah der Bediener
             * weder Erfolg noch Fehler. */
            $mt_fehler[] = sprintf(mt_t('EINST.FEHLER_SPEICHERN'), $mt_p['config']);
        }
    }
    $mt_tab = 'tab-mqtt';
}

/* ---------------- Netz-Zugangsdaten speichern ---------------- */
if ($mt_post && isset($_POST['netz_speichern'])) {
    $mt_cfg = mt_config();
    $ssid = trim(preg_replace('/[\x00-\x1F\x7F"]/', '',
        (string) (isset($_POST['wlan_ssid']) ? $_POST['wlan_ssid'] : '')));
    if ($ssid !== '') {
        $mt_cfg['wlan_ssid'] = $ssid;
    }
    // Leeres Passwortfeld loescht nichts.
    $pw = isset($_POST['wlan_passwort']) && is_string($_POST['wlan_passwort'])
        ? $_POST['wlan_passwort'] : '';
    if ($pw !== '') {
        $mt_cfg['wlan_passwort'] = $pw;
    }
    /* ERST pruefen, DANN uebernehmen. Bis 0.9.16 wurde jedes Nicht-Hex-
     * Zeichen still entfernt und danach geprueft - die Pruefung konnte dann
     * nur noch an der Laenge scheitern. Das widerspricht der eigenen Regel
     * im Endpunkt: was nicht ins Muster passt, wird abgewiesen und gemeldet,
     * nie zurechtgebogen. */
    $ds = trim((string) (isset($_POST['thread_dataset']) ? $_POST['thread_dataset'] : ''));
    if ($ds !== '' && !preg_match('/^[0-9A-Fa-f]{20,600}$/', $ds)) {
        $mt_fehler[] = mt_t('ANLERN.FEHLER_THREAD');
    } elseif ($ds !== '') {
        $mt_cfg['thread_dataset'] = $ds;
    }
    /* Ein HINWEIS, keine Sperre: bis 0.9.16 landete er in der
     * Beanstandungsliste und verhinderte damit das Speichern der ganzen
     * Seite - auch des Thread-Datasets, das mit WLAN nichts zu tun hat.
     * Melden ist richtig, blockieren nicht. */
    if (!empty($mt_cfg['wlan_passwort']) && trim((string) $mt_cfg['wlan_ssid']) === '') {
        $mt_meldungen[] = mt_t('ANLERN.WARN_PW_OHNE_SSID');
    }
    if (!$mt_fehler) {
        if (mt_config_speichern($mt_cfg)) {
            $mt_meldungen[] = mt_t('ANLERN.NETZ_GESPEICHERT');
        } else {
            $mt_fehler[] = sprintf(mt_t('EINST.FEHLER_SPEICHERN'), $mt_p['config']);
        }
    }
    $mt_tab = 'tab-commission';
}

/* ---------------- Thread-Dataset beim Border-Router holen ----------------
 *
 * Ein eigener Handler und ein eigenes Formular, nicht der Speichern-Knopf
 * darueber: sonst liefen beide Handler an einem Klick, und der Bediener
 * bekaeme zwei Meldungen zu einer Handlung.
 *
 * Die Adresse wird gemerkt, sobald sie die Formpruefung bestanden hat - auch
 * wenn der Abruf danach scheitert (Stand 2). Wer einen Border-Router
 * eintraegt, der gerade aus ist, soll die Adresse beim naechsten Anlauf
 * vorfinden und nicht neu tippen. Bei Stand 0 wurde die Adresse abgewiesen;
 * dann wird nichts gespeichert.
 */
if ($mt_post && isset($_POST['br_holen'])) {
    $mt_cfg = mt_config();
    $mt_adr = trim((string) (isset($_POST['thread_br']) ? $_POST['thread_br'] : ''));
    list($mt_stand, $mt_text) = mt_thread_dataset_holen($mt_adr);
    if ($mt_stand !== 0 && $mt_cfg['thread_br'] !== $mt_adr) {
        $mt_cfg['thread_br'] = $mt_adr;
        if (!mt_config_speichern($mt_cfg)) {
            $mt_fehler[] = sprintf(mt_t('EINST.FEHLER_SPEICHERN'), $mt_p['config']);
        }
    }
    if ($mt_stand === 1) {
        $mt_cfg['thread_dataset'] = $mt_text;
        if (mt_config_speichern($mt_cfg)) {
            $mt_meldungen[] = sprintf(mt_t('ANLERN.BR_GEHOLT'), strlen($mt_text));
        } else {
            $mt_fehler[] = sprintf(mt_t('EINST.FEHLER_SPEICHERN'), $mt_p['config']);
        }
    } else {
        $mt_fehler[] = mt_e($mt_text);
    }
    $mt_tab = 'tab-commission';
}

/* ---------------- Dienst ---------------- */
if ($mt_post && isset($_POST['dienst'])) {
    list($mt_ok, $mt_ausgabe) = mt_dienst((string) $_POST['dienst']);
    if ($mt_ok) {
        $mt_meldungen[] = mt_t('EINST.DIENST_' . strtoupper((string) $_POST['dienst'])) . ' ' . mt_e($mt_ausgabe);
    } else {
        $mt_fehler[] = mt_e($mt_ausgabe);
    }
    $mt_tab = 'tab-settings';
}

/* ---------------- Container ---------------- */
if ($mt_post && isset($_POST['container'])) {
    $mt_was = (string) $_POST['container'];
    if (!in_array($mt_was, array('anlegen', 'start', 'stop', 'restart', 'entfernen', 'holen'), true)) {
        $mt_fehler[] = mt_t('EINST.FEHLER_CONTAINERBEFEHL');
    } else {
        list($mt_ok, $mt_ausgabe) = mt_container($mt_was);
        if ($mt_ok) {
            $mt_meldungen[] = sprintf(mt_t('EINST.CONTAINER_OK'), mt_e($mt_was))
                            . ' <span class="sm-mono">' . mt_e(substr($mt_ausgabe, 0, 200)) . '</span>';
        } else {
            $mt_fehler[] = sprintf(mt_t('EINST.CONTAINER_FEHL'), mt_e($mt_was))
                         . ' <span class="sm-mono">' . mt_e(substr($mt_ausgabe, 0, 400)) . '</span>';
        }
    }
    $mt_tab = 'tab-settings';
}

/* ---------------- Neues Token ---------------- */
if ($mt_post && isset($_POST['token_neu'])) {
    $mt_cfg = mt_config();
    $mt_cfg['aktionstoken'] = mt_token_erzeugen();
    if (mt_config_speichern($mt_cfg)) {
        $mt_meldungen[] = mt_t('LOX.TOKEN_NEU');
    } else {
        $mt_fehler[] = sprintf(mt_t('EINST.FEHLER_SPEICHERN'), $mt_p['config']);
    }
    $mt_tab = 'tab-loxone';
}

/* ---------------- Log leeren ---------------- */
if ($mt_post && isset($_POST['log_leeren'])) {
    @mkdir(dirname($mt_p['log']), 0775, true);
    @file_put_contents($mt_p['log'], '[' . date('Y-m-d H:i:s') . '] ' . mt_t('LOG.GELEERT') . "\n");
    /* Der Dienst rotiert mit backupCount=1; ohne diese Zeile blieben bis zu
     * 512 kB in matter2lox.log.1 auf der Ramdisk liegen, und wer den Knopf
     * zum Platzsparen drueckte, gewann die Haelfte. Dazu die Startdatei. */
    if (is_file($mt_p['log'] . '.1')) {
        @unlink($mt_p['log'] . '.1');
    }
    if (is_file(dirname($mt_p['log']) . '/matter2lox.start.log')) {
        @unlink(dirname($mt_p['log']) . '/matter2lox.start.log');
    }
    $mt_meldungen[] = mt_t('LOG.GELEERT');
    $mt_tab = 'tab-log';
}

/* ---------------- Aktionen aus Test und Anlernen ---------------- */
if ($mt_post && isset($_POST['test'])) {
    list($mt_stand, $mt_text) = mt_test_aktion((string) $_POST['test']);
    if ($mt_stand === 1) {
        $mt_meldungen[] = mt_e($mt_text);
    } else {
        $mt_fehler[] = mt_e($mt_text);
    }
    $mt_tab = in_array((string) $_POST['test'], array('anlernen', 'wlan', 'thread', 'entfernen', 'fenster', 'name'), true)
        ? 'tab-commission' : 'tab-test';
}
if ($mt_post && isset($_POST['selbsttest'])) {
    $mt_testausgabe = mt_selbsttest_ausgabe();
    $mt_tab = 'tab-test';
}
if ($mt_post && isset($_POST['containerlog'])) {
    $mt_testausgabe = mt_container_log(200);
    $mt_tab = 'tab-test';
}

/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken. Ohne ihn
 * stuenden nach dem Zurueckspielen alle Felder richtig, und das Plugin
 * kaeme trotzdem nicht an die Anlage; die Datei waere wertlos. Damit
 * traegt sie ein Geheimnis, und der Hinweis am Knopf sagt das.
 *
 * Der lesbare Kopf (Schluessel mit fuehrendem Unterstrich) gehoert zum
 * Hausstandard; die Leseseite uebergeht ihn seit 0.9.17. Bis dahin gab es
 * ihn nicht - und eine Datei MIT Kopf haette die Leseseite abgewiesen. */
if ($mt_post && isset($_POST['mt_sichern'])) {
    $mt_sich = array(
        '_hinweis' => mt_t('EINST.SICH_KOPF'),
        '_stand'   => date('Y-m-d H:i:s'),
        '_fassung' => mt_fassung(),
    ) + mt_config();
    $mt_js = json_encode($mt_sich,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($mt_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="matter2lox_einstellungen_'
               . date('Ymd_His') . '.json"');
        echo $mt_js;
        exit;
    }
    $mt_fehler[] = mt_t('EINST.SICH_SCHREIBFEHLER');
}

/* ---------------- Einstellungen zurueckspielen ----------------
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei des
 * Servers unterschieben. Dann die Groessengrenze - eine Sicherung dieses
 * Plugins ist wenige Kilobyte gross; alles darueber wird gar nicht gelesen.
 *
 * Dieser Handler steht - wie alle anderen - VOR dem Ladeblock. Bis 0.9.16
 * stand er als einziger dahinter: die Seite meldete Erfolg und zeigte
 * danach in allen Feldern und in jeder Loxone-Adresse noch den alten Stand
 * samt altem Aktionstoken. Wer die Adresse dann abschrieb, trug ein Token
 * nach Loxone, das nicht mehr galt - und ein virtueller Eingang wertet die
 * 403-Antwort nicht aus. */
if ($mt_post && isset($_POST['mt_zurueck'])) {
    if (!isset($_FILES['mt_sicherung']) || !is_array($_FILES['mt_sicherung'])
        || !isset($_FILES['mt_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['mt_sicherung']['tmp_name'])) {
        $mt_fehler[] = mt_t('EINST.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['mt_sicherung']['size'] > 262144) {
        $mt_fehler[] = mt_t('EINST.SICH_ZU_GROSS');
    } else {
        list($mt_neu, $mt_mangel, $mt_n) = mt_sicherung_lesen(
            (string) @file_get_contents($_FILES['mt_sicherung']['tmp_name']));
        if ($mt_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert wird
             * nichts. */
            $mt_fehler[] = mt_t('EINST.SICH_ABGELEHNT') . ' '
                            . implode(' ', $mt_mangel);
        } elseif (mt_config_speichern($mt_neu)) {
            $mt_meldungen[] = sprintf(mt_t('EINST.SICH_UEBERNOMMEN'), $mt_n);
        } else {
            $mt_fehler[] = mt_t('EINST.SICH_SCHREIBFEHLER');
        }
    }
}

/* ---------------- Laden ---------------- */
$mt_cfg = mt_config();
$mt_token = mt_token();
$mt_geraete = mt_geraete();
$mt_srv = mt_serverinfo();
$mt_zustand = mt_zustand();
$mt_alter = mt_alter();
$mt_pid = mt_dienst_pid();
$mt_mqtt = mt_mqtt_zustand();
$mt_contzustand = mt_container_zustand();
$mt_tabelle = mt_tabelle();
/* Was laeuft im Container wirklich, und wie gross ist die Fabric? Beides
 * stand bis 0.9.9 nirgends - und ohne die Abbildkennung laesst sich die
 * Wirkung des Knopfs 'Abbild neu holen' gar nicht beurteilen. */
$mt_fassung = mt_container_fassung($mt_cfg);
$mt_fabricgroesse = mt_fabric_groesse();
$mt_host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
    ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
    : (gethostname() ?: 'loxberry');
$mt_basis = 'http://' . $mt_host . '/plugins/' . $mt_p['plugin'] . '/index.php';
$mt_logzeilen = array();
if (is_file($mt_p['log'])) {
    $mt_logzeilen = array_slice(
        array_reverse(file($mt_p['log'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()),
        0, 400);
}

$mt_rahmen = class_exists('LBWeb', false);




if ($mt_rahmen) {
    LBWeb::lbheader('Matter to Loxone', 'https://wiki.loxberry.de/', 'help.html');
}

?>
<style>
/* Hausstandard, wortgetreu aus VORLAGE_hausstandard.css.html uebernommen.
   Nicht neu erfinden: der Knopf-Fehler vom 30.07.2026 steckte in sieben
   Plugins gleichzeitig, weil jedes seine eigene Kopie hatte. */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; white-space: pre-wrap; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-fehler { border: 1px solid #ef9a9a; background: #ffebee; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: Consolas, "Courier New", monospace;
    font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto;
    white-space: pre-wrap; }

/* Nachgetragene Definitionen (CSS-Luecken-Durchgang 13.08.2026):
   benutzt, aber nie definiert - wortgleich aus der Hausstandard-Vorlage
   bzw. der Referenzimplementierung uebernommen. */
.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
</style>
<div class="sm-wrap">

<?php foreach ($mt_meldungen as $mt_m) { ?>
<div class="sm-hinweis"><?= $mt_m ?></div>
<?php } ?>
<?php if ($mt_fehler) { ?>
<div class="sm-fehler"><b><?= mt_e(mt_t('ALLG.BEANSTANDUNG')) ?></b>
<ul style="margin:6px 0 0 18px;padding:0;">
<?php foreach ($mt_fehler as $mt_f) { ?><li><?= $mt_f ?></li><?php } ?>
</ul></div>
<?php } ?>

<div class="sm-kacheln">
  <div class="sm-kachel"><?= mt_e(mt_t('ALLG.DIENST')) ?>
    <b class="<?= $mt_pid ? 'sm-an' : 'sm-aus' ?>"><?= $mt_pid ? mt_e(mt_t('ALLG.LAEUFT')) : mt_e(mt_t('ALLG.GESTOPPT')) ?></b>
    <span class="sm-hilfe"><?= $mt_pid ? 'PID ' . (int) $mt_pid : mt_e(mt_t('ALLG.KEINE_PID')) ?></span>
  </div>
  <div class="sm-kachel"><?= mt_e(mt_t('ALLG.CONTAINER')) ?>
    <b class="<?= $mt_contzustand === 'laeuft' ? 'sm-an' : 'sm-aus' ?>"><?= mt_e(mt_t('ALLG.CONT_' . strtoupper($mt_contzustand))) ?></b>
    <span class="sm-hilfe"><?= mt_e(mt_container_name($mt_cfg)) ?></span>
  </div>
  <div class="sm-kachel"><?= mt_e(mt_t('ALLG.GERAETE')) ?>
    <b><?= count($mt_geraete) ?></b>
    <span class="sm-hilfe"><?= $mt_alter < 0 ? mt_e(mt_t('ALLG.NIE')) : (int) $mt_alter . ' s' ?></span>
  </div>
  <div class="sm-kachel">MQTT
    <b class="<?= $mt_mqtt['autostart'] ? 'sm-an' : 'sm-aus' ?>"><?= $mt_mqtt['autostart'] ? mt_e(mt_t('ALLG.EIN')) : mt_e(mt_t('ALLG.AUS')) ?></b>
    <span class="sm-hilfe"><?= mt_e(mt_t('ALLG.GATEWAY')) ?></span>
  </div>
</div>

<?php if (!empty($mt_zustand['fehler'])) { ?>
<div class="sm-warnung"><b><?= mt_e(mt_t('ALLG.LETZTE_STOERUNG')) ?></b> <?= mt_e($mt_zustand['fehler']) ?></div>
<?php } ?>

<?php
/*
 * Die Reiter sind echte Verweise - das waren sie schon. Was fehlte, war die
 * Klasse sm-active AUF DEM SERVER.
 *
 * .sm-seite steht auf display:none, sichtbar wird eine Flaeche erst durch
 * .sm-active. Diese Klasse setzte bis 0.9.1 ausschliesslich das JavaScript am
 * Seitenende. Im ausgelieferten HTML kam sm-active also gar nicht vor (nur in
 * den beiden CSS-Regeln) - ohne JavaScript war die Seite vollstaendig leer:
 * Kacheln und Reiterleiste standen da, darunter nichts.
 *
 * $mt_tab wurde dabei sehr wohl schon serverseitig ermittelt; benutzt wurde
 * das Ergebnis aber nur, um es dem JavaScript zu uebergeben. Jetzt setzt der
 * Server die Klasse selbst, und das JavaScript spart nur noch den
 * Seitenaufbau beim Umschalten.
 *
 * Die Liste hier, die Positivliste in $mt_muster und die id der Flaechen
 * muessen deckungsgleich bleiben - alle drei.
 */
/*
 * Die Reiterleiste steht ausgeschrieben da, nicht als Schleife.
 *
 * Bis 0.9.9 wurde sie aus einem Feld erzeugt. Das ist sparsamer zu lesen,
 * macht aber hausstandard_pruefen.py blind: das Werkzeug sucht die Reiter
 * woertlich im Quelltext und meldete deshalb 'nicht gemessen' statt eines
 * Ergebnisses. Eine Korrektur, die eine Pruefung blind macht, ist keine.
 *
 * Ausgeschrieben heisst: drei Stellen muessen von Hand zusammenpassen -
 * die Positivliste in $mt_muster ganz oben, diese Leiste und die id der
 * Flaechen darunter. Genau das prueft der Reiter Test jetzt nach und liest
 * dafuer diese Datei; siehe mt_pruef_reiter() in mt_test.php.
 */
?>
<div class="sm-tabs">
	<a class="sm-tab<?= $mt_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings" href="index.php?form=settings"><?= mt_e(mt_t('REITER.EINSTELLUNGEN')) ?></a>
	<a class="sm-tab<?= $mt_tab === 'tab-commission' ? ' sm-active' : '' ?>" data-ziel="tab-commission" href="index.php?form=commission"><?= mt_e(mt_t('REITER.ANLERNEN')) ?></a>
	<a class="sm-tab<?= $mt_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt" href="index.php?form=mqtt"><?= mt_e('MQTT') ?></a>
	<a class="sm-tab<?= $mt_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone" href="index.php?form=loxone"><?= mt_e(mt_t('REITER.LOXONE')) ?></a>
	<a class="sm-tab<?= $mt_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test" href="index.php?form=test"><?= mt_e(mt_t('REITER.TEST')) ?></a>
	<a class="sm-tab<?= $mt_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log" href="index.php?form=log"><?= mt_e(mt_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $mt_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">

<div class="sm-warnung"><?= mt_t('EINST.WAS_IST_DAS') ?></div>

<h2><?= mt_e(mt_t('EINST.H_DIENST')) ?></h2>
<p class="sm-hilfe"><?= mt_t('EINST.DIENST_ERKLAERUNG') ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= mt_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
<?php /* 'start' ist orange, nicht gruen: die Legende sagt bei Gruen
   "fragt nur ab, veraendert nichts", und ein Dienststart tut beides nicht.
   Trennlinie ist "kann den Betrieb stoeren". */
foreach (array('start' => 'sm-b-aktion', 'restart' => 'sm-b-aktion', 'stop' => 'sm-b-aktion') as $mt_b => $mt_farbe) { ?>
  <form action="index.php" method="post">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn <?= $mt_farbe ?>" type="submit" name="dienst" value="<?= $mt_b ?>"><?= mt_e(mt_t('EINST.K_' . strtoupper($mt_b))) ?></button>
  </form>
<?php } ?>
</div>

<h2><?= mt_e(mt_t('EINST.H_CONTAINER')) ?></h2>
<div class="sm-hinweis"><?= mt_t('EINST.CONTAINER_ERKLAERUNG') ?></div>
<?php if (!mt_docker_da()) { ?>
<div class="sm-fehler"><?= mt_t('EINST.KEIN_DOCKER') ?></div>
<?php } ?>
<table class="sm-tbl">
<tr><th><?= mt_e(mt_t('ALLG.EIGENSCHAFT')) ?></th><th><?= mt_e(mt_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= mt_e(mt_t('EINST.T_CONTZUSTAND')) ?></td>
    <td class="<?= $mt_contzustand === 'laeuft' ? 'sm-an' : 'sm-aus' ?>"><?= mt_e(mt_t('ALLG.CONT_' . strtoupper($mt_contzustand))) ?></td></tr>
<tr><td><?= mt_e(mt_t('EINST.T_AUFRUF')) ?></td>
    <td><span class="sm-mono">docker <?= mt_e(mt_container_befehl($mt_cfg)) ?></span></td></tr>
<tr><td><?= mt_e(mt_t('EINST.T_DATENORDNER')) ?></td>
    <td><span class="sm-mono"><?= mt_e($mt_p['datadir']) ?>/matter</span>
        <?php if ($mt_fabricgroesse >= 0) { ?>(<?= (int) round($mt_fabricgroesse / 1024) ?> kB)<?php } ?></td></tr>
<tr><td><?= mt_e(mt_t('EINST.T_ABBILDSTAND')) ?></td>
    <td><?php if ($mt_fassung['container'] === '') { ?><?= mt_e(mt_t('EINST.T_ABBILD_UNBEKANNT')) ?><?php } else { ?>
        <span class="sm-mono"><?= mt_e(substr($mt_fassung['container'], 0, 19)) ?></span>
        <?php if ($mt_fassung['marke'] !== '') { ?>&mdash; <?= mt_e($mt_fassung['marke']) ?><?php } ?>
        <?php if ($mt_fassung['abbild'] !== '' && $mt_fassung['abbild'] !== $mt_fassung['container']) { ?>
        <br><span class="sm-aus"><?= mt_t('EINST.T_ABBILD_NEUER') ?></span><?php } ?>
    <?php } ?></td></tr>
</table>
<div class="sm-warnung"><?= mt_t('EINST.FABRIC_WARNUNG') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= mt_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
<?php /* Alle sechs veraendern etwas: 'anlegen' erzeugt einen Container,
   'holen' zieht ein Abbild von mehreren hundert Megabyte. Bis 0.9.16 trugen
   drei davon Gruen bzw. Grau - und die Legende darueber erklaerte Gruen mit
   "veraendert nichts". */
foreach (array('anlegen' => 'sm-b-aktion', 'start' => 'sm-b-aktion', 'holen' => 'sm-b-aktion',
                     'restart' => 'sm-b-aktion', 'stop' => 'sm-b-aktion', 'entfernen' => 'sm-b-aktion') as $mt_b => $mt_farbe) { ?>
  <form action="index.php" method="post">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn <?= $mt_farbe ?>" type="submit" name="container" value="<?= $mt_b ?>"><?= mt_e(mt_t('EINST.KC_' . strtoupper($mt_b))) ?></button>
  </form>
<?php } ?>
  <form action="index.php" method="post">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="container_akt" value="1"><?= mt_e(mt_t('EINST.KC_AKTUALISIEREN')) ?></button>
  </form>
</div>
<div class="sm-hilfe"><?= mt_t('EINST.H_AKTUALISIEREN') ?></div>

<h2><?= mt_e(mt_t('EINST.H_FABRIC')) ?></h2>
<div class="sm-warnung"><?= mt_t('EINST.FABRIC_SICHERN_ERKLAERUNG') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= mt_t('LEGENDE.LESEN') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="fabric_sichern" value="1"><?= mt_e(mt_t('EINST.K_FABRIC_SICHERN')) ?></button>
  </form>
</div>
<div class="sm-step"><?= mt_t('EINST.FABRIC_ZURUECK') ?>
<p><span class="sm-mono">docker stop <?= mt_e(mt_container_name($mt_cfg)) ?> &amp;&amp; tar -xzf matter-fabric-....tar.gz -C <?= mt_e($mt_p['fabric']) ?> &amp;&amp; docker start <?= mt_e(mt_container_name($mt_cfg)) ?></span></p>
</div>

<form action="index.php" method="post" autocomplete="off">
  <?php echo mt_fmt(); ?>
<input data-role="none" type="hidden" name="speichern" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?= mt_e(mt_t('EINST.H_VERBINDUNG')) ?></h2>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="eigener_container" value="1" <?= !empty($mt_cfg['eigener_container']) ? 'checked' : '' ?>>
    <?= mt_e(mt_t('EINST.L_EIGENER_CONTAINER')) ?>
  </label>
  <div class="sm-hilfe"><?= mt_t('EINST.H_EIGENER_CONTAINER') ?></div>
</div>
<div class="sm-feld">
  <label for="server_host"><?= mt_e(mt_t('EINST.L_SERVER_HOST')) ?></label>
  <input data-role="none" type="text" id="server_host" name="server_host" value="<?= mt_e($mt_cfg['server_host']) ?>" placeholder="127.0.0.1">
</div>
<div class="sm-feld">
  <label for="server_port"><?= mt_e(mt_t('EINST.L_SERVER_PORT')) ?></label>
  <input data-role="none" type="number" id="server_port" name="server_port" value="<?= (int) $mt_cfg['server_port'] ?>" min="1" max="65535">
  <div class="sm-hilfe"><?= mt_t('EINST.H_SERVER_PORT') ?></div>
</div>
<div class="sm-feld">
  <label for="container_name"><?= mt_e(mt_t('EINST.L_CONTAINER_NAME')) ?></label>
  <input data-role="none" type="text" id="container_name" name="container_name" value="<?= mt_e($mt_cfg['container_name']) ?>">
</div>
<div class="sm-feld">
  <label for="container_abbild"><?= mt_e(mt_t('EINST.L_CONTAINER_ABBILD')) ?></label>
  <input data-role="none" type="text" id="container_abbild" name="container_abbild" value="<?= mt_e($mt_cfg['container_abbild']) ?>">
</div>
<div class="sm-feld">
  <label for="bluetooth_adapter"><?= mt_e(mt_t('EINST.L_BLUETOOTH_ADAPTER')) ?></label>
  <input data-role="none" type="number" id="bluetooth_adapter" name="bluetooth_adapter" value="<?= (int) $mt_cfg['bluetooth_adapter'] ?>" min="0" max="9">
  <div class="sm-hilfe"><?= mt_t('EINST.H_BLUETOOTH_ADAPTER') ?></div>
</div>
<div class="sm-feld">
  <label for="wartezeit"><?= mt_e(mt_t('EINST.L_WARTEZEIT')) ?></label>
  <input data-role="none" type="number" id="wartezeit" name="wartezeit" value="<?= (int) $mt_cfg['wartezeit'] ?>" min="0" max="60">
  <div class="sm-hilfe"><?= mt_t('EINST.H_WARTEZEIT') ?></div>
</div>
<div class="sm-feld">
  <label for="sendetakt"><?= mt_e(mt_t('EINST.L_SENDETAKT')) ?></label>
  <input data-role="none" type="number" id="sendetakt" name="sendetakt" value="<?= (int) $mt_cfg['sendetakt'] ?>" min="0" max="60">
  <div class="sm-hilfe"><?= mt_t('EINST.H_SENDETAKT') ?></div>
</div>
<div class="sm-feld">
  <label for="herzschlag"><?= mt_e(mt_t('EINST.L_HERZSCHLAG')) ?></label>
  <input data-role="none" type="number" id="herzschlag" name="herzschlag" value="<?= (int) $mt_cfg['herzschlag'] ?>" min="0" max="3600">
  <div class="sm-hilfe"><?= mt_t('EINST.H_HERZSCHLAG') ?></div>
</div>

<h2><?= mt_e(mt_t('EINST.H_STEUERUNG')) ?></h2>
<div class="sm-warnung"><?= mt_t('EINST.STEUERUNG_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="steuerung_ein" value="1" <?= !empty($mt_cfg['steuerung_ein']) ? 'checked' : '' ?>>
    <?= mt_e(mt_t('EINST.L_STEUERUNG_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="schloss_ein" value="1" <?= !empty($mt_cfg['schloss_ein']) ? 'checked' : '' ?>>
    <?= mt_e(mt_t('EINST.L_SCHLOSS_EIN')) ?>
  </label>
  <div class="sm-hilfe"><?= mt_t('EINST.H_SCHLOSS_EIN') ?></div>
</div>

<?php /* MQTT stand hier bis zu dieser Fassung. Es wohnt jetzt
         vollstaendig im Reiter MQTT - eine Sache, eine Stelle. */ ?>

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= mt_e(mt_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= mt_e(mt_t('EINST.H_ERKANNT')) ?></h2>
<?php if (!$mt_geraete) { ?>
<div class="sm-warnung"><?= mt_t('EINST.KEINE_GERAETE') ?></div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th>#</th><th><?= mt_e(mt_t('EINST.T_NAME')) ?></th><th><?= mt_e(mt_t('EINST.T_KNOTEN')) ?></th>
    <th><?= mt_e(mt_t('EINST.T_HERSTELLER')) ?></th><th><?= mt_e(mt_t('EINST.T_PRODUKT')) ?></th>
    <th><?= mt_e(mt_t('EINST.T_TYP')) ?></th>
    <th><?= mt_e(mt_t('EINST.T_WERTE')) ?></th><th><?= mt_e(mt_t('EINST.T_ERREICHBAR')) ?></th></tr>
<?php
/*
 * Zugriff mit Rueckfallwert, nicht unmittelbar.
 *
 * loxone.json schreibt zwar der Dienst, aber die Datei ueberdauert
 * Aktualisierungen und kann aus einer aelteren Fassung stammen, halb
 * geschrieben oder von Hand veraendert sein. Fehlt dann ein Schluessel, ist
 * das unter PHP 7.4 eine Notice, die das error_reporting dieser Datei
 * verschluckt - unter PHP 8 eine Warning, und die steht dann MITTEN IN DER
 * TABELLE, einmal je Geraet und Spalte. Beim Rendern gegen beide Fassungen
 * waren es sechs Meldungen im Seitenkoerper.
 */
$mt_feld = function ($g, $name, $leer = '') {
    return isset($g[$name]) && $g[$name] !== null && $g[$name] !== '' ? $g[$name] : $leer;
};
?>
<?php foreach ($mt_geraete as $mt_nr => $mt_g) { ?>
<tr><td><?= mt_e($mt_nr) ?></td><td><?= mt_e($mt_feld($mt_g, 'name')) ?></td>
    <td><?= (int) $mt_feld($mt_g, 'node_id', 0) ?></td>
    <td><?= mt_e($mt_feld($mt_g, 'hersteller', '—')) ?></td><td><?= mt_e($mt_feld($mt_g, 'produkt', '—')) ?></td>
    <td><?php
      /* Der Dienst rechnet die Geraetetypen je Endpunkt aus und schreibt sie
       * ins Abbild; die Tabelle fuehrt dreizehn uebersetzte Namen dafuer.
       * Gelesen hat das bis 0.9.9 kein PHP - dreizehn verwaiste
       * Sprachschluessel und eine Auskunft, die dalag und niemandem nutzte. */
      $mt_typen = array();
      foreach ((array) $mt_feld($mt_g, 'typen', array()) as $mt_ep2 => $mt_liste2) {
          $mt_t2 = mt_geraetetyp_text($mt_liste2, $mt_tabelle);
          if ($mt_t2 !== '') { $mt_typen[$mt_t2] = 1; }
      }
      echo $mt_typen ? mt_e(implode(', ', array_keys($mt_typen))) : '&mdash;';
    ?></td>
    <td><?php
      $mt_liste = array();
      foreach ((array) $mt_feld($mt_g, 'endpunkte', array()) as $mt_ep => $mt_felder) {
          foreach ((array) $mt_felder as $mt_thema => $mt_w) {
              $mt_liste[] = '<span class="sm-mono">' . mt_e($mt_ep . '/' . $mt_thema) . '</span> = ' . mt_e($mt_w);
          }
      }
      echo $mt_liste ? implode(', ', $mt_liste) : '&mdash;';
    ?></td>
    <td class="<?= $mt_feld($mt_g, 'erreichbar', 0) ? 'sm-an' : 'sm-aus' ?>"><?= $mt_feld($mt_g, 'erreichbar', 0) ? mt_e(mt_t('ALLG.JA')) : mt_e(mt_t('ALLG.NEIN')) ?></td></tr>
<?php } ?>
</table>
<?php } ?>

<h2><?= mt_t('EINST.H_SICHERUNG') ?></h2>
<div class="sm-hinweis"><?= mt_t('EINST.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= mt_t('EINST.SICH_WARNUNG') ?></div>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt. -->
  <form action="index.php" method="post">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="mt_sichern" value="1"><?= mt_t('EINST.K_SICHERN') ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="file" name="mt_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="mt_zurueck" value="1"><?= mt_t('EINST.K_ZURUECK') ?></button>
  </form>
</div>
</div>

<!-- ================= Reiter: Geraete anlernen ================= -->
<div class="sm-seite<?= $mt_tab === 'tab-commission' ? ' sm-active' : '' ?>" id="tab-commission">
<h2><?= mt_e(mt_t('ANLERN.H_TITEL')) ?></h2>
<p><?= mt_t('ANLERN.EINLEITUNG') ?></p>
<?php if (empty($mt_cfg['steuerung_ein'])) { ?>
<div class="sm-warnung"><?= mt_t('ANLERN.STEUERUNG_GESPERRT') ?></div>
<?php } ?>

<div class="sm-step"><b><?= mt_e(mt_t('ANLERN.S1_TITEL')) ?></b><br>
<?= mt_t('ANLERN.S1_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= mt_e(mt_t('ALLG.EIGENSCHAFT')) ?></th><th><?= mt_e(mt_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= mt_e(mt_t('ANLERN.T_BT')) ?></td>
    <td class="<?= !empty($mt_srv['bluetooth']) ? 'sm-an' : 'sm-aus' ?>">
    <?= !empty($mt_srv['bluetooth']) ? mt_e(mt_t('ALLG.JA')) : mt_e(mt_t('ALLG.NEIN')) ?></td></tr>
<tr><td><?= mt_e(mt_t('ANLERN.T_WLAN')) ?></td>
    <td class="<?= !empty($mt_srv['wlan_gesetzt']) ? 'sm-an' : 'sm-aus' ?>">
    <?= !empty($mt_srv['wlan_gesetzt']) ? mt_e(mt_t('ALLG.JA')) : mt_e(mt_t('ALLG.NEIN')) ?></td></tr>
<tr><td><?= mt_e(mt_t('ANLERN.T_THREAD')) ?></td>
    <td class="<?= !empty($mt_srv['thread_gesetzt']) ? 'sm-an' : 'sm-aus' ?>">
    <?= !empty($mt_srv['thread_gesetzt']) ? mt_e(mt_t('ALLG.JA')) : mt_e(mt_t('ALLG.NEIN')) ?></td></tr>
</table>
</div>

<h2><?= mt_e(mt_t('ANLERN.H_NETZ')) ?></h2>
<div class="sm-hinweis"><?= mt_t('ANLERN.NETZ_ERKLAERUNG') ?></div>
<form action="index.php" method="post" autocomplete="off">
  <?php echo mt_fmt(); ?>
<input data-role="none" type="hidden" name="netz_speichern" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-commission">
<div class="sm-feld">
  <label for="wlan_ssid"><?= mt_e(mt_t('ANLERN.L_SSID')) ?></label>
  <input data-role="none" type="text" id="wlan_ssid" name="wlan_ssid" value="<?= mt_e($mt_cfg['wlan_ssid']) ?>">
  <div class="sm-hilfe"><?= mt_t('ANLERN.H_SSID') ?></div>
</div>
<div class="sm-feld">
  <label for="wlan_passwort"><?= mt_e(mt_t('ANLERN.L_WLANPW')) ?></label>
  <input data-role="none" type="password" id="wlan_passwort" name="wlan_passwort" value=""
         placeholder="<?= $mt_cfg['wlan_passwort'] !== '' ? mt_e(mt_t('ANLERN.PW_GESETZT')) : mt_e(mt_t('ANLERN.PW_LEER')) ?>">
</div>
<div class="sm-feld">
  <label for="thread_dataset"><?= mt_e(mt_t('ANLERN.L_THREAD')) ?></label>
  <input data-role="none" type="text" id="thread_dataset" name="thread_dataset" value="<?= mt_e($mt_cfg['thread_dataset']) ?>">
  <div class="sm-hilfe"><?= mt_t('ANLERN.H_THREAD') ?></div>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= mt_e(mt_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
<form action="index.php" method="post" autocomplete="off">
  <?php echo mt_fmt(); ?>
<input data-role="none" type="hidden" name="br_holen" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-commission">
<div class="sm-feld">
  <label for="thread_br"><?= mt_e(mt_t('ANLERN.L_BR')) ?></label>
  <input data-role="none" type="text" id="thread_br" name="thread_br"
         value="<?= mt_e($mt_cfg['thread_br']) ?>" placeholder="border-router:8081">
  <div class="sm-hilfe"><?= mt_t('ANLERN.H_BR') ?></div>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= mt_e(mt_t('ANLERN.K_BR')) ?></button>
</div>
</form>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= mt_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-commission">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="wlan"><?= mt_e(mt_t('ANLERN.K_WLAN')) ?></button>
  </form>
  <form action="index.php" method="post">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-commission">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="thread"><?= mt_e(mt_t('ANLERN.K_THREAD')) ?></button>
  </form>
</div>

<h2><?= mt_e(mt_t('ANLERN.H_CODE')) ?></h2>
<div class="sm-step"><?= mt_t('ANLERN.CODE_ERKLAERUNG') ?></div>
<form action="index.php" method="post" autocomplete="off">
  <?php echo mt_fmt(); ?>
<input data-role="none" type="hidden" name="activetab" value="tab-commission">
<div class="sm-feld">
  <label for="code"><?= mt_e(mt_t('ANLERN.L_CODE')) ?></label>
  <input data-role="none" type="text" id="code" name="code" value="" placeholder="MT:Y.ABCDEFG123456789">
  <div class="sm-hilfe"><?= mt_t('ANLERN.HILFE_CODE') ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="nur_netz" value="1">
    <?= mt_e(mt_t('ANLERN.L_NUR_NETZ')) ?>
  </label>
  <div class="sm-hilfe"><?= mt_t('ANLERN.H_NUR_NETZ') ?></div>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= mt_t('LEGENDE.AKTION_ANLERNEN') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="anlernen"><?= mt_e(mt_t('ANLERN.K_ANLERNEN')) ?></button>
</div>
</form>
<div class="sm-warnung"><?= mt_t('ANLERN.DAUER_WARNUNG') ?></div>

<h2><?= mt_e(mt_t('ANLERN.H_VERWALTEN')) ?></h2>
<form action="index.php" method="post">
  <?php echo mt_fmt(); ?>
<input data-role="none" type="hidden" name="activetab" value="tab-commission">
<div class="sm-feld">
  <label for="test_geraet2"><?= mt_e(mt_t('TEST.L_GERAET')) ?></label>
  <input data-role="none" type="number" id="test_geraet2" name="test_geraet" value="1" min="1" max="999">
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= mt_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-feld">
  <label for="geraetename"><?= mt_e(mt_t('ANLERN.L_NAME')) ?></label>
  <input data-role="none" type="text" id="geraetename" name="geraetename" value="" maxlength="32">
  <div class="sm-hilfe"><?= mt_t('ANLERN.H_NAME') ?></div>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="name"><?= mt_e(mt_t('ANLERN.K_NAME')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="fenster"><?= mt_e(mt_t('ANLERN.K_FENSTER')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="entfernen"><?= mt_e(mt_t('ANLERN.K_ENTFERNEN')) ?></button>
</div>
</form>
<div class="sm-hilfe"><?= mt_t('ANLERN.VERWALTEN_HILFE') ?></div>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $mt_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">

<h2>MQTT</h2>
<form action="index.php" method="post">
  <?php echo mt_fmt(); ?>
<input data-role="none" type="hidden" name="save_mqtt" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="mqtt_ein" value="1" <?= !empty($mt_cfg['mqtt_ein']) ? 'checked' : '' ?>>
    <?= mt_e(mt_t('EINST.L_MQTT_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="mqtt_topic"><?= mt_e(mt_t('EINST.L_MQTT_TOPIC')) ?></label>
  <input data-role="none" type="text" id="mqtt_topic" name="mqtt_topic" value="<?= mt_e($mt_cfg['mqtt_topic']) ?>" placeholder="matter">
</div>
<div class="sm-feld">
  <label for="mqtt_nur"><?= mt_e(mt_t('EINST.L_MQTT_NUR')) ?></label>
  <input data-role="none" type="text" id="mqtt_nur" name="mqtt_nur" value="<?= mt_e($mt_cfg['mqtt_nur']) ?>" placeholder="1,3,7">
  <div class="sm-hilfe"><?= mt_t('EINST.H_MQTT_NUR') ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="roh_ein" value="1" <?= !empty($mt_cfg['roh_ein']) ? 'checked' : '' ?>>
    <?= mt_e(mt_t('EINST.L_ROH_EIN')) ?>
  </label>
  <div class="sm-hilfe"><?= mt_t('EINST.H_ROH_EIN') ?></div>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= mt_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= mt_e(mt_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
<h2><?= mt_e(mt_t('MQTT.H_ZUSTAND')) ?></h2>
<p class="sm-hilfe"><?= mt_t('MQTT.GATEWAY_ERKLAERUNG') ?></p>
<?php if (!$mt_mqtt['gefunden']) { ?>
<div class="sm-fehler"><?= mt_t('MQTT.NICHT_GEFUNDEN') ?></div>
<?php } elseif (!$mt_mqtt['autostart']) { ?>
<div class="sm-fehler"><?= mt_t('MQTT.AUTOSTART_AUS') ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= mt_t('MQTT.AUTOSTART_EIN') ?></div>
<?php } ?>
<table class="sm-tbl">
<tr><th><?= mt_e(mt_t('ALLG.EIGENSCHAFT')) ?></th><th><?= mt_e(mt_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= mt_e(mt_t('MQTT.T_AUTOSTART')) ?></td><td class="<?= $mt_mqtt['autostart'] ? 'sm-an' : 'sm-aus' ?>"><?= $mt_mqtt['autostart'] ? mt_e(mt_t('ALLG.EIN')) : mt_e(mt_t('ALLG.AUS')) ?></td></tr>
<tr><td><?= mt_e(mt_t('MQTT.T_BROKER')) ?></td><td><span class="sm-mono"><?= mt_e($mt_mqtt['broker']) ?>:<?= mt_e($mt_mqtt['brokerport']) ?></span></td></tr>
<tr><td><?= mt_e(mt_t('MQTT.T_UDP')) ?></td><td><span class="sm-mono"><?= (int) $mt_mqtt['udpport'] ?></span></td></tr>
</table>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= mt_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="mqtt_probe" value="1"><?= mt_e(mt_t('MQTT.K_PROBE')) ?></button>
  </form>
</div>
<div class="sm-hilfe"><?= mt_t('MQTT.H_PROBE') ?></div>

<h2><?= mt_e(mt_t('MQTT.H_ABO')) ?></h2>
<div class="sm-warnung"><?= mt_abo_text() ?></div>
<div class="sm-step"><?= mt_t('MQTT.ABO_SCHRITTE') ?>
<p><span class="sm-mono"><?= mt_e($mt_cfg['mqtt_topic']) ?>/#</span></p>
</div>

<h2><?= mt_e(mt_t('MQTT.H_THEMEN')) ?></h2>
<p class="sm-hilfe"><?= mt_t('MQTT.THEMEN_ERKLAERUNG') ?></p>
<table class="sm-tbl">
<tr><th><?= mt_e(mt_t('MQTT.T_THEMA')) ?></th><th><?= mt_e(mt_t('MQTT.T_BEDEUTUNG')) ?></th></tr>
<tr><td><span class="sm-mono"><?= mt_e($mt_cfg['mqtt_topic']) ?>/ok</span></td><td><?= mt_t('MQTT.B_OK') ?></td></tr>
<tr><td><span class="sm-mono"><?= mt_e($mt_cfg['mqtt_topic']) ?>/online</span></td><td><?= mt_t('MQTT.B_ONLINE') ?></td></tr>
<tr><td><span class="sm-mono"><?= mt_e($mt_cfg['mqtt_topic']) ?>/ts</span></td><td><?= mt_t('MQTT.B_TS') ?></td></tr>
<tr><td><span class="sm-mono"><?= mt_e($mt_cfg['mqtt_topic']) ?>/geraete</span></td><td><?= mt_t('MQTT.B_GERAETE') ?></td></tr>
<tr><td><span class="sm-mono"><?= mt_e($mt_cfg['mqtt_topic']) ?>/geraetN/name</span></td><td><?= mt_t('MQTT.B_NAME') ?></td></tr>
<tr><td><span class="sm-mono"><?= mt_e($mt_cfg['mqtt_topic']) ?>/geraetN/knoten</span></td><td><?= mt_t('MQTT.B_KNOTEN') ?></td></tr>
<tr><td><span class="sm-mono"><?= mt_e($mt_cfg['mqtt_topic']) ?>/geraetN/erreichbar</span></td><td><?= mt_t('MQTT.B_ERREICH') ?></td></tr>
<tr><td><span class="sm-mono"><?= mt_e($mt_cfg['mqtt_topic']) ?>/geraetN/&lt;Endpunkt&gt;/&lt;Thema&gt;</span></td><td><?= mt_t('MQTT.B_WERT') ?></td></tr>
<tr><td><span class="sm-mono"><?= mt_e($mt_cfg['mqtt_topic']) ?>/geraetN/roh/&lt;Pfad&gt;</span></td><td><?= mt_t('MQTT.B_ROH') ?></td></tr>
</table>

<h2><?= mt_e(mt_t('MQTT.H_UEBERSETZUNG')) ?></h2>
<p class="sm-hilfe"><?= mt_t('MQTT.UEBERSETZUNG_ERKLAERUNG') ?></p>
<table class="sm-tbl">
<tr><th><?= mt_e(mt_t('MQTT.T_CLUSTER')) ?></th><th><?= mt_e(mt_t('MQTT.T_PFAD')) ?></th>
    <th><?= mt_e(mt_t('MQTT.T_THEMA')) ?></th><th><?= mt_e(mt_t('MQTT.T_UMRECHNUNG')) ?></th>
    <th><?= mt_e(mt_t('MQTT.T_BEDEUTUNG')) ?></th></tr>
<?php foreach ((array) $mt_tabelle['cluster'] as $mt_cl => $mt_d) {
    foreach ((array) $mt_d['attribute'] as $mt_at => $mt_a) { ?>
<tr><td><span class="sm-mono"><?= mt_e($mt_d['name']) ?></span></td>
    <td><span class="sm-mono">E/<?= mt_e($mt_cl) ?>/<?= mt_e($mt_at) ?></span></td>
    <td><span class="sm-mono"><?= mt_e($mt_a['thema']) ?></span></td>
    <td><?= mt_e(mt_t('UMR.' . strtoupper($mt_a['typ']))) ?></td>
    <td><?= mt_t($mt_a['text']) ?></td></tr>
<?php } } ?>
<?php
/* Themen, die aus einem EREIGNIS stammen. Sie stehen nicht unter 'cluster'
 * und fehlten deshalb in dieser Tabelle, obwohl der Dienst sie
 * veroeffentlicht. Ein Thema auf dem Broker, das in der Oberflaeche nicht
 * vorkommt, ist genau die Art Luecke, die der Hausstandard hier schliesst. */
foreach ((array) (isset($mt_tabelle['ereignisthemen']['themen'])
        ? $mt_tabelle['ereignisthemen']['themen'] : array()) as $mt_a) { ?>
<tr><td><span class="sm-mono">Switch</span></td>
    <td><span class="sm-mono"><?= mt_e(mt_t('MQTT.T_EREIGNIS')) ?></span></td>
    <td><span class="sm-mono"><?= mt_e($mt_a['thema']) ?></span></td>
    <td><?= mt_e(mt_t('UMR.' . strtoupper($mt_a['typ']))) ?></td>
    <td><?= mt_t($mt_a['text']) ?></td></tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?= mt_t('MQTT.PLATZHALTER') ?></p>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $mt_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= mt_e(mt_t('LOX.H_TITEL')) ?></h2>
<p><?= mt_t('LOX.EINLEITUNG') ?></p>

<div class="sm-step"><b><?= mt_e(mt_t('LOX.S1_TITEL')) ?></b><br><?= mt_t('LOX.S1_TEXT') ?></div>

<div class="sm-step"><b><?= mt_e(mt_t('LOX.S2_TITEL')) ?></b><br>
<?= mt_t('LOX.S2_TEXT') ?>
<p><span class="sm-mono"><?= mt_e($mt_cfg['mqtt_topic']) ?>/#</span></p>
<div class="sm-warnung"><?= mt_abo_text() ?></div>
</div>

<div class="sm-step"><b><?= mt_e(mt_t('LOX.S3_TITEL')) ?></b><br>
<?= mt_t('LOX.S3_TEXT') ?>
<?php if (!$mt_geraete) { ?>
<div class="sm-warnung"><?= mt_t('LOX.KEINE_GERAETE') ?></div>
<?php } else { foreach ($mt_geraete as $mt_nr => $mt_g) { ?>
<p><b><?= mt_e($mt_feld($mt_g, 'name', '?')) ?></b> (<?= mt_e(mt_t('EINST.T_KNOTEN')) ?> <?= (int) $mt_feld($mt_g, 'node_id', 0) ?>)</p>
<table class="sm-tbl">
<tr><th><?= mt_e(mt_t('LOX.T_ADRESSE')) ?></th>
    <td colspan="3"><span class="sm-mono"><?= mt_e($mt_basis) ?>?token=<?= mt_e($mt_token) ?>&amp;aktion=status&amp;geraet=<?= mt_e($mt_nr) ?></span></td></tr>
<tr><th><?= mt_e(mt_t('LOX.T_TITEL')) ?></th><th><?= mt_e(mt_t('LOX.T_BEFEHL')) ?></th>
    <th><?= mt_e(mt_t('LOX.T_EINZELN')) ?></th><th><?= mt_e(mt_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<?php /* Die Schleifenvariable heisst NICHT $mt_feld: so hiess der
   Verschluss aus dem Reiter Einstellungen, und eine Schleife hier haette
   ihn ueberschrieben. */
foreach (mt_status_felder() as $mt_sfeld => $mt_info) { ?>
<tr><td><span class="sm-mono">MATTER_<?= mt_e($mt_nr) ?>_<?= mt_e($mt_sfeld) ?></span></td>
    <td><span class="sm-mono"><?= mt_e(mt_check($mt_sfeld)) ?></span></td><td>&mdash;</td>
    <td><?= mt_t($mt_info[1]) ?></td></tr>
<?php }
foreach ((array) $mt_feld($mt_g, 'endpunkte', array()) as $mt_ep => $mt_felder) {
    foreach ((array) $mt_felder as $mt_thema => $mt_w) {
        $mt_marke = strtoupper($mt_ep . '_' . $mt_thema); ?>
<tr><td><span class="sm-mono">MATTER_<?= mt_e($mt_nr) ?>_<?= mt_e($mt_marke) ?></span></td>
    <td><span class="sm-mono"><?= mt_e(mt_check($mt_marke)) ?></span></td>
    <td><span class="sm-mono">&amp;aktion=wert&amp;endpunkt=<?= mt_e($mt_ep) ?>&amp;thema=<?= mt_e($mt_thema) ?></span></td>
    <td><?= mt_e(mt_thema_text($mt_thema, $mt_tabelle)) ?></td></tr>
<?php } } ?>
</table>
<div class="sm-knopfreihe" style="margin-bottom:10px;">
<form action="index.php" method="post">
  <?php echo mt_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <input data-role="none" type="hidden" name="vorlage" value="<?= mt_e($mt_nr) ?>">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit"><?= mt_e(mt_t('LOX.K_VORLAGE')) ?> <?= mt_e($mt_feld($mt_g, 'name', '?')) ?></button>
</form>
<form action="index.php" method="post">
  <?php echo mt_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <input data-role="none" type="hidden" name="vorlage" value="aus<?= mt_e($mt_nr) ?>">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= mt_e(mt_t('LOX.K_VORLAGE_AUS')) ?> <?= mt_e($mt_feld($mt_g, 'name', '?')) ?></button>
</form>
</div>
<?php } } ?>

<h3 class="sm-h3"><?= mt_e(mt_t('LOX.H_SAMMEL')) ?></h3>
<div class="sm-hinweis"><?= mt_t('LOX.S_SAMMEL') ?></div>
<form action="index.php" method="post" style="margin-bottom:10px;">
  <?php echo mt_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <input data-role="none" type="hidden" name="vorlage" value="alle">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit"><?= mt_e(mt_t('LOX.K_VORLAGE_ALLE')) ?></button>
</form>
<div class="sm-hinweis"><?= mt_t('LOX.S3_EINZELN') ?></div>
<div class="sm-warnung"><?= mt_t('LOX.S3_STRICH') ?></div>
<div class="sm-warnung"><?= mt_t('LOX.S3_NEU_EINLESEN') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= mt_t('LEGENDE.LESEN') ?></span>
</div>
</div>

<div class="sm-step"><b><?= mt_e(mt_t('LOX.S4_TITEL')) ?></b><br>
<?= mt_t('LOX.S4_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= mt_e(mt_t('ALLG.EIGENSCHAFT')) ?></th><th><?= mt_e(mt_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= mt_e(mt_t('LOX.T_VA_ADRESSE')) ?></td><td><span class="sm-mono">http://<?= mt_e($mt_host) ?></span></td></tr>
<?php
$mt_beispiele = array(
    'LOX.T_VA_EIN'    => 'aktion=ein&amp;geraet=1&amp;endpunkt=1',
    'LOX.T_VA_AUS'    => 'aktion=aus&amp;geraet=1&amp;endpunkt=1',
    'LOX.T_VA_DIMMEN' => 'aktion=helligkeit&amp;geraet=1&amp;endpunkt=1&amp;wert=&lt;v&gt;',
    'LOX.T_VA_CT'     => 'aktion=farbtemperatur&amp;geraet=1&amp;endpunkt=1&amp;wert=&lt;v&gt;',
    'LOX.T_VA_ROLLO'  => 'aktion=rollo&amp;geraet=1&amp;endpunkt=1&amp;wert=&lt;v&gt;',
    'LOX.T_VA_SOLL'   => 'aktion=soll_heizen&amp;geraet=1&amp;endpunkt=1&amp;wert=&lt;v&gt;',
);
foreach ($mt_beispiele as $mt_k => $mt_q) { ?>
<tr><td><?= mt_e(mt_t($mt_k)) ?></td>
    <td><span class="sm-mono">/plugins/<?= mt_e($mt_p['plugin']) ?>/index.php?token=<?= mt_e($mt_token) ?>&amp;<?= $mt_q ?></span></td></tr>
<?php } ?>
</table>
<?= mt_t('LOX.S4_ROH') ?>
<div class="sm-warnung"><?= mt_t('LOX.S4_WARNUNG') ?></div>
</div>

<div class="sm-step"><b><?= mt_e(mt_t('LOX.S5_TITEL')) ?></b>
<table class="sm-tbl">
<tr><th><?= mt_e(mt_t('ALLG.EIGENSCHAFT')) ?></th><th><?= mt_e(mt_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= mt_e(mt_t('LOX.T_TOKEN')) ?></td><td><span class="sm-mono"><?= mt_e($mt_token) ?></span></td></tr>
</table>
<?= mt_t('LOX.S5_TEXT') ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= mt_e(mt_t('LOX.K_TOKEN_NEU')) ?></button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= mt_t('LEGENDE.AKTION_TOKEN') ?></span>
</div>
</div>

<div class="sm-step"><b><?= mt_e(mt_t('LOX.S6_TITEL')) ?></b><br><?= mt_t('LOX.S6_TEXT') ?></div>

<?php
/**
 * Die komplette Baustein-Liste. Pflicht im Hausstandard.
 *
 * Anspruch: Wer die Tabelle von oben nach unten abarbeitet, hat die Funktion
 * nachgebaut, ohne nachzudenken. Loxone Config fuehrt alle Bausteine in der
 * Baustein-Suche (F5).
 */
function mt_bausteine()
{
    return array(
        // Das Suchmuster kommt aus mt_check() - derselben Funktion, aus der
        // auch die XML-Vorlage es holt. Bis 0.9.10 stand es hier ein zweites
        // Mal in den Sprachdateien, und zwar OHNE das fuehrende Semikolon:
        // wer die Tabelle von Hand nachbaute, bekam das alte, anfaellige
        // Muster, waehrend die erzeugte Vorlage das richtige trug. Zwei
        // Quellen fuer dieselbe Angabe sind eine zu viel.
        array(1,  'BAUSTEIN.T_VE',      'BAUSTEIN.N01',
              sprintf(mt_t('BAUSTEIN.P01'), mt_check('1_SCHALTER')), '&mdash;'),
        array(2,  'BAUSTEIN.T_VE',      'BAUSTEIN.N02',
              sprintf(mt_t('BAUSTEIN.P02'), mt_check('1_HELLIGKEIT')), '&mdash;'),
        array(3,  'BAUSTEIN.T_VE',      'BAUSTEIN.N03',
              sprintf(mt_t('BAUSTEIN.P03'), mt_check('ALTER')), '&mdash;'),
        array(4,  'BAUSTEIN.T_VE',      'BAUSTEIN.N04',
              sprintf(mt_t('BAUSTEIN.P04'), mt_check('ERREICH')), '&mdash;'),
        array(5,  'BAUSTEIN.T_VE',      'BAUSTEIN.N05',
              sprintf(mt_t('BAUSTEIN.P05'), mt_check('OK')), '&mdash;'),
        array(6,  'BAUSTEIN.T_SWS',     'BAUSTEIN.N06', mt_t('BAUSTEIN.P06'), 'I &larr; #3'),
        array(7,  'BAUSTEIN.T_NICHT',   'BAUSTEIN.N07', '',             'I &larr; #4'),
        array(8,  'BAUSTEIN.T_ODER',    'BAUSTEIN.N08', '',             'I1 &larr; #6, I2 &larr; #7'),
        array(9,  'BAUSTEIN.T_EVZ',     'BAUSTEIN.N09', mt_t('BAUSTEIN.P09'), 'I &larr; #8'),
        array(10, 'BAUSTEIN.T_BENACHR', 'BAUSTEIN.N10', mt_t('BAUSTEIN.P10'), 'I &larr; #9'),
        array(11, 'BAUSTEIN.T_LICHT',   'BAUSTEIN.N11', mt_t('BAUSTEIN.P11'), 'AI &larr; ' . mt_t('BAUSTEIN.TASTER')),
        array(12, 'BAUSTEIN.T_VA',      'BAUSTEIN.N12', mt_t('BAUSTEIN.P12'), 'I &larr; #11 (Q)'),
        array(13, 'BAUSTEIN.T_VA',      'BAUSTEIN.N13', mt_t('BAUSTEIN.P13'), 'I &larr; #11 (AQ)'),
        array(14, 'BAUSTEIN.T_ENTPRELL','BAUSTEIN.N14', mt_t('BAUSTEIN.P14'), 'I &larr; #11 (AQ)'),
        array(15, 'BAUSTEIN.T_VERGL',   'BAUSTEIN.N15', mt_t('BAUSTEIN.P15'), 'I1 &larr; #1, I2 &larr; #11 (AQ)'),
        array(16, 'BAUSTEIN.T_STATUS',  'BAUSTEIN.N16', mt_t('BAUSTEIN.P16'), 'I1 &larr; #2, I2 &larr; #5'),
    );
}
?>
<div class="sm-step"><b><?= mt_e(mt_t('LOX.S7_TITEL')) ?></b><br>
<?= mt_t('LOX.S7_TEXT') ?>
<table class="sm-tbl">
<tr><th>#</th><th><?= mt_e(mt_t('LOX.T_BAUSTEIN')) ?></th><th><?= mt_e(mt_t('LOX.T_NAMENSVORSCHLAG')) ?></th>
    <th><?= mt_e(mt_t('LOX.T_PARAMETER')) ?></th><th><?= mt_e(mt_t('LOX.T_EINGAENGE')) ?></th></tr>
<?php foreach (mt_bausteine() as $mt_b) { ?>
<tr><td><?= (int) $mt_b[0] ?></td><td><?= mt_t($mt_b[1]) ?></td><td><?= mt_t($mt_b[2]) ?></td>
    <td><?= $mt_b[3] !== '' ? $mt_b[3] : '&mdash;' ?></td><td><?= $mt_b[4] ?></td></tr>
<?php } ?>
</table>
<?= mt_t('LOX.S7_ERLAEUTERUNG') ?>
</div>

<div class="sm-step"><b><?= mt_e(mt_t('LOX.S8_TITEL')) ?></b><br>
<?= mt_t('LOX.S8_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= mt_e(mt_t('LOX.T_PRUEFUNG')) ?></th><th><?= mt_e(mt_t('LOX.T_ERWARTUNG')) ?></th></tr>
<tr><td><span class="sm-mono"><?= mt_e($mt_basis) ?>?token=<?= mt_e($mt_token) ?>&amp;aktion=liste</span></td>
    <td><span class="sm-mono">LISTE;OK=1;N=...</span></td></tr>
<tr><td><span class="sm-mono"><?= mt_e($mt_basis) ?>?aktion=liste</span></td>
    <td><span class="sm-mono">FEHLER;OK=0;GRUND=TOKEN</span> (HTTP 403)</td></tr>
<tr><td><span class="sm-mono"><?= mt_e($mt_basis) ?>?token=<?= mt_e($mt_token) ?>&amp;aktion=quatsch</span></td>
    <td><span class="sm-mono">FEHLER;OK=0;GRUND=UNBEKANNTE_AKTION</span> (HTTP 400)</td></tr>
</table>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $mt_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?= mt_e(mt_t('TEST.H_SELBSTPRUEFUNG')) ?></h2>
<p class="sm-hilfe"><?= mt_t('TEST.EINLEITUNG') ?></p>
<table class="sm-tbl">
<tr><th style="width:36px;">&nbsp;</th><th><?= mt_e(mt_t('TEST.T_FRAGE')) ?></th><th><?= mt_e(mt_t('TEST.T_BEFUND')) ?></th></tr>
<?php /* Nur wenn dieser Reiter serverseitig der offene ist. Alle Reiter
   werden bei jedem Aufruf mitgerendert; bis 0.9.16 liefen deshalb bei JEDEM
   Seitenaufruf ein fsockopen zum Matter-Server (3 s), ein HTTP-Aufruf des
   eigenen Endpunkts (5 s Verbindung) und drei docker-Aufrufe - auch im
   Reiter Logdateien. Die Zwischenspeicher federten das nur ab. */
if ($mt_tab !== 'tab-test') { ?>
<p class="sm-hilfe"><?= mt_e(mt_t('TEST.NUR_HIER')) ?></p>
<?php } else {
foreach (mt_pruefungen() as $mt_z) { ?>
<tr><td style="text-align:center;"><?php
    if ($mt_z['stand'] === 1) { echo '<span class="sm-an">&#10004;</span>'; }
    elseif ($mt_z['stand'] === 0) { echo '<span class="sm-aus">&#10008;</span>'; }
    else { echo '<span style="color:#888;">&#9679;</span>'; }
?></td><td><?= $mt_z['frage'] ?></td><td><?= $mt_z['antwort'] ?></td></tr>
<?php } } ?>
</table>

<div class="sm-warnung"><?= mt_t('TEST.NETZ_WARNUNG') ?></div>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= mt_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= mt_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= mt_t('LEGENDE.AKTION') ?></span>
</div>

<h3><?= mt_e(mt_t('TEST.H_LESEN')) ?></h3>
<div class="sm-knopfreihe">
  <a class="sm-btn sm-b-lesen" href="<?= mt_e($mt_basis) ?>?token=<?= mt_e($mt_token) ?>&amp;aktion=liste" target="_blank"><?= mt_e(mt_t('TEST.K_LISTE')) ?></a>
  <a class="sm-btn sm-b-lesen" href="<?= mt_e($mt_basis) ?>?token=<?= mt_e($mt_token) ?>&amp;aktion=status&amp;geraet=1" target="_blank"><?= mt_e(mt_t('TEST.K_STATUS')) ?></a>
</div>

<h3><?= mt_e(mt_t('TEST.H_TECHNIK')) ?></h3>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="selbsttest" value="1"><?= mt_e(mt_t('TEST.K_SELBSTTEST')) ?></button>
  </form>
  <form action="index.php" method="post">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="containerlog" value="1"><?= mt_e(mt_t('TEST.K_CONTAINERLOG')) ?></button>
  </form>
  <a class="sm-btn sm-b-technik" href="<?= mt_e($mt_basis) ?>?token=<?= mt_e($mt_token) ?>&amp;aktion=roh" target="_blank"><?= mt_e(mt_t('TEST.K_ROH')) ?></a>
</div>
<?php if ($mt_testausgabe !== '') { ?>
<div class="sm-pre"><?= mt_e($mt_testausgabe) ?></div>
<?php } ?>

<h3><?= mt_e(mt_t('TEST.H_SCHALTEN')) ?></h3>
<div class="sm-warnung"><?= mt_t('TEST.SCHALTEN_WARNUNG') ?></div>
<?php if (empty($mt_cfg['steuerung_ein'])) { ?>
<div class="sm-hinweis"><?= mt_t('TEST.SCHALTEN_GESPERRT') ?></div>
<?php } ?>
<form action="index.php" method="post">
  <?php echo mt_fmt(); ?>
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<div class="sm-feld">
  <label for="test_geraet"><?= mt_e(mt_t('TEST.L_GERAET')) ?></label>
  <input data-role="none" type="number" id="test_geraet" name="test_geraet" value="1" min="1" max="999">
</div>
<div class="sm-feld">
  <label for="test_endpunkt"><?= mt_e(mt_t('TEST.L_ENDPUNKT')) ?></label>
  <input data-role="none" type="number" id="test_endpunkt" name="test_endpunkt" value="1" min="0" max="255">
  <div class="sm-hilfe"><?= mt_t('TEST.H_ENDPUNKT') ?></div>
</div>
<div class="sm-feld">
  <label for="test_wert"><?= mt_e(mt_t('TEST.L_WERT')) ?></label>
  <input data-role="none" type="number" id="test_wert" name="test_wert" value="50" min="0" max="100">
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="abruf"><?= mt_e(mt_t('TEST.K_ABRUF')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="ein"><?= mt_e(mt_t('TEST.K_EIN')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="aus"><?= mt_e(mt_t('TEST.K_AUS')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="umschalten"><?= mt_e(mt_t('TEST.K_UMSCHALTEN')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="helligkeit"><?= mt_e(mt_t('TEST.K_HELLIGKEIT')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="anstupsen"><?= mt_e(mt_t('TEST.K_ANSTUPSEN')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="identify"><?= mt_e(mt_t('TEST.K_IDENTIFY')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="farbton"><?= mt_e(mt_t('TEST.K_FARBTON')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="luefter"><?= mt_e(mt_t('TEST.K_LUEFTER')) ?></button>
</div>
<div class="sm-warnung"><?= mt_t('TEST.SCHLOSS_WARNUNG') ?></div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="sperren"><?= mt_e(mt_t('TEST.K_SPERREN')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="entsperren"><?= mt_e(mt_t('TEST.K_ENTSPERREN')) ?></button>
</div>
</form>

<div class="sm-warnung"><b><?= mt_e(mt_t('TEST.H_UNGEPRUEFT')) ?></b><br><?= mt_t('TEST.UNGEPRUEFT') ?></div>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?= $mt_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?= mt_e(mt_t('LOG.H_TITEL')) ?></h2>
<?php
if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) {
    echo LBWeb::loglist_html();
}
?>
<p class="sm-hilfe"><?= mt_t('LOG.ERKLAERUNG') ?><br>
<span class="sm-mono"><?= mt_e($mt_p['log']) ?></span></p>
<?php if ($mt_logzeilen) { ?>
<div class="sm-log"><?= mt_e(implode("\n", $mt_logzeilen)) ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= mt_t('LOG.LEER') ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= mt_t('LEGENDE.AKTION_LOG') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="log_leeren" value="1"><?= mt_e(mt_t('LOG.K_LEEREN')) ?></button>
  </form>
</div>
</div>

</div><!-- /sm-wrap -->

<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	zeige(<?= json_encode($mt_tab) ?>);
})();
</script>
<?php
if ($mt_rahmen) {
    LBWeb::lbfooter();
}
