<?php
/**
 * Matter to Loxone - Bedienoberflaeche
 *
 * Reiter: Einstellungen | Geraete anlernen | Bridge | MQTT |
 *         Einbindung in Loxone | Test | Logdateien
 * 0.9.42: Reiter "Bridge (Loxone -> Alexa/Google/Apple)" - die Matter-Bridge
 * (Beta, ab Werk aus), die Gegenrichtung zu "Geraete anlernen".
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

/* Welche Lage gilt, entscheidet der eigene Ablageort, nicht die Reihenfolge
 * der Versuche: liegt diese Datei unter .../plugins/<ordner>, ist sie
 * installiert (Bibliothek unter <home>/webfrontend/html/plugins/<ordner>/),
 * sonst liegt sie in einem ausgepackten Archiv (../html/). Bis 0.9.28 wurden
 * drei Kandidaten der Reihe nach probiert, darunter zwei VOR der eigenen
 * Bibliothek und ausserhalb des Archivs - in WSL gemessen (25.09.2026,
 * Pruefung-Matter2Lox-0.9.29, Fall P1) lief aus einem Archiv unter <x>/arch
 * eine fremde <x>/html/plugins/htmlauth/mt_lib.php als Bibliothek, und aus
 * einem Archiv unter / waere das ein Pfad ab der Laufwerkswurzel gewesen.
 * Bauart ZendureSolarFlow 0.9.26. */
$mt_gefunden = false;
if (basename(dirname(__DIR__)) === 'plugins') {
    $mt_kandidaten = array(dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/mt_lib.php');
} else {
    $mt_kandidaten = array(dirname(__DIR__) . '/html/mt_lib.php');
}
foreach ($mt_kandidaten as $mt_kandidat) {
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

/* ---------------- Stand des Hintergrundvorgangs als JSON ----------------
 *
 * 0.9.35 (Nr. 8): ein leichter Pfad fuer das Nachfragen der Seite, solange
 * ein Vorgang am Matter-Server laeuft (index.php?vorgang=1). Liest nur
 * container_vorgang.json und /proc - kein Docker, keine Konfiguration
 * schreiben, kein HTML. Bis 0.9.34 lud die Seite sich per meta refresh alle
 * 5 s komplett neu, mit allen Docker-Aufrufen - und verwarf dabei, was gerade
 * in ein Feld getippt wurde. Nur GET; ein POST geht den normalen Weg. */
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'GET'
        && isset($_GET['vorgang']) && $_GET['vorgang'] === '1') {
    $mt_vg = mt_ct_vorgang();
    $mt_vs = isset($mt_vg['schritt']) && is_string($mt_vg['schritt']) && $mt_vg['schritt'] !== ''
        ? mt_t('EINST.V_S_' . strtoupper($mt_vg['schritt'])) : '-';
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(array(
        'zustand' => (string) $mt_vg['zustand'],
        'seit'    => isset($mt_vg['start']) ? max(0, time() - (int) $mt_vg['start']) : 0,
        'schritt' => $mt_vs,
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

/* Aktiver Reiter. Die Positivliste MUSS jeden Reiter enthalten - fehlt einer,
 * ist er sichtbar und anklickbar, aber nach jedem Absenden springt die Seite
 * zurueck auf Einstellungen. */
$mt_muster = '/^tab-(settings|commission|bridge|mqtt|loxone|test|log)$/';
$mt_tab = 'tab-settings';

$mt_meldungen = array();
$mt_fehler = array();
/* X-2 (Regeln/04): die Eingaben eines abgewiesenen Formulars und die Namen
 * der beanstandeten Felder - sie reisen mit der Einmalmeldung. */
$mt_eingaben = array();
$mt_bean = array();
/* 0.9.39: die Liste der gekoppelten Systeme nach "Gekoppelte Systeme
 * anzeigen" - sie reist mit der Einmalmeldung (keine Zugangsdaten). */
$mt_fabrics = null;

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
 *
 * Seit 0.9.30 (O1, Regeln/04) endet JEDER POST mit einer Umleitung 303 auf
 * index.php?form=<reiter>; Meldungen reisen als Einmalmeldung
 * (mt_einmal_schreiben()). Bis 0.9.30 antwortete jeder POST mit 200, und F5
 * wiederholte Speichern, Token neu, Container entfernen, Schloss sperren und
 * jeden anderen Knopf (Bericht oberflaeche Nr. 1 und 2, Bauart D).
 * Ausgenommen sind nur die Downloads: sie liefern ihre Datei unmittelbar.
 * ================================================================== */
/* ---------------- Vorlage herunterladen ---------------- */
if ($mt_post && isset($_POST['vorlage'])) {
    // 'alle' = Sammelvorlage, 'aus<N>' = virtuelle Ausgaenge, '<N>' = Eingaenge
    // Nachtrag: eine Liste statt Text ist kein Geraetewunsch (keine PHP-Warnung).
    $mt_wunsch = is_string($_POST['vorlage']) ? $_POST['vorlage'] : '';
    if ($mt_wunsch === 'alle') {
        list($mt_name, $mt_inhalt) = mt_vorlage_alle();
    } elseif (preg_match('/^mqtt([0-9]{1,3})$/', $mt_wunsch, $mt_tr)) {
        /* Gruppe 8 (0.9.44, Entwurf 3.8): Vorlage MQTT-Befehle - dieselbe Thema-zu-Befehl-Tabelle wie die
         * Ausgaenge, Adresse /dev/udp/<LoxBerry>/<UDP-Eingang des Gateways>. Ohne schaltbaren Wert keine Datei. */
        list($mt_name, $mt_inhalt) = mt_vorlage_mqtt_out((int) $mt_tr[1]);
        if (strpos($mt_inhalt, '<VirtualOutCmd') === false) {
            $mt_fehler[] = mt_t('LOX.M_VORLAGE_LEER');
            $mt_tab = 'tab-loxone';
            $mt_inhalt = null;
        }
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
        /* M19 (0.9.44, Pruefbericht; Hausform Chromecast C16): den Ausgabepuffer leeren - eine Warnung, ein BOM oder
         * eine Leerzeile aus einer eingebundenen Datei laege sonst VOR der Datei. */
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
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
        /* O5 (Durchgang 30.09.2026): erst packen, den Rueckgabewert von tar
         * pruefen, dann ausliefern (mt_fabric_packen()). Bis 0.9.30 gingen
         * die Kopfzeilen vor dem Packen hinaus - eine unlesbare Datei ergab
         * ein unvollstaendiges Archiv mit HTTP 200 und ohne Meldung. Aus dem
         * Ordner heraus gepackt, nicht mit Pfad: sonst traegt das Archiv den
         * absoluten Pfad des Rechners in sich. */
        list($mt_fok, $mt_fwas, $mt_fzahl) = mt_fabric_packen();
        if ($mt_fok) {
            /* 0.9.35 (Nr. 10): die Temp-Datei (sie traegt die Fabric samt
             * Schluesseln) wird in JEDEM Fall geloescht - auch wenn der
             * Browser den Download abbricht. Bis 0.9.34 beendete ein Abbruch
             * das Skript vor dem unlink(), und die Datei blieb im Datenordner
             * liegen. */
            ignore_user_abort(true);
            $mt_fweg = $mt_fwas;
            register_shutdown_function(function () use ($mt_fweg) {
                if (is_file($mt_fweg)) {
                    @unlink($mt_fweg);
                }
            });
            $mt_dname = 'matter-fabric-' . date('Y-m-d_H-i') . '.tar.gz';
            /* M19 (0.9.44, Pruefbericht; Hausform Chromecast C16): den Ausgabepuffer leeren - eine Warnung, ein BOM oder
             * eine Leerzeile aus einer eingebundenen Datei laege sonst VOR der Datei. */
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            header('Content-Type: application/x-download');
            header('Content-Disposition: attachment; filename="' . $mt_dname . '"');
            header('Content-Length: ' . (int) @filesize($mt_fwas));
            header('X-Matter2Lox-Dateien: ' . (int) $mt_fzahl);
            readfile($mt_fwas);
            @unlink($mt_fwas);
            exit;
        }
        $mt_fehler[] = $mt_fwas;
    }
    $mt_tab = 'tab-settings';
}

/* ---------------- Matter-Server einrichten (E1, Welle 2) ----------------
 *
 * Ein Knopf statt der Reihenfolge "Abbild holen, Container anlegen,
 * starten": bin/container_vorgang.php einrichten im Hintergrund
 * (mt_ct_vorgang_starten()). Bis 0.9.33 liefen Anlegen und Holen im
 * Seitenaufruf, beim ersten Mal bis zu 15 Minuten. Die Meldung sagt nur
 * "gestartet"; das Ergebnis steht danach im Reiter (mt_ct_vorgang_anzeige()).
 * Ein zweiter Druck startet keinen zweiten Vorgang, aus dem Archivmodus und
 * bei eigener_container=0 wird nichts angelegt. */
if ($mt_post && isset($_POST['mt_einrichten'])) {
    list($mt_ok, $mt_ausgabe) = mt_ct_vorgang_starten('einrichten');
    if ($mt_ok) { $mt_meldungen[] = $mt_ausgabe; } else { $mt_fehler[] = $mt_ausgabe; }
    $mt_tab = 'tab-settings';
}

/* ---------------- Container aktualisieren ----------------
 * Seit Welle 2 im Hintergrund, mit dem Verhalten von
 * mt_container_aktualisieren() (unveraendert). */
if ($mt_post && isset($_POST['container_akt'])) {
    list($mt_ok, $mt_ausgabe) = mt_ct_vorgang_starten('aktualisieren');
    if ($mt_ok) { $mt_meldungen[] = $mt_ausgabe; } else { $mt_fehler[] = $mt_ausgabe; }
    $mt_tab = 'tab-settings';
}

/* ---------------- Einstellungen uebernehmen (Container neu anlegen) ----------------
 * 0.9.35 (Nr. 5): weicht der laufende Container von der Aufrufzeile ab (oder
 * steht der eigene unter einem anderen Namen), legt dieser Knopf ihn im
 * Hintergrund neu an - mit Rueckweg und Fabric-Sicherung (Nr. 6). */
if ($mt_post && isset($_POST['container_uebernehmen'])) {
    list($mt_ok, $mt_ausgabe) = mt_ct_vorgang_starten('uebernehmen');
    if ($mt_ok) { $mt_meldungen[] = $mt_ausgabe; } else { $mt_fehler[] = $mt_ausgabe; }
    $mt_tab = 'tab-settings';
}

/* ---------------- Umstieg auf matterjs-server (Beta) und zurueck ----------------
 * 0.9.35 (Nr. E5): setzt container_abbild auf das Abbild der anderen Bauart,
 * speichert und startet den Hintergrundvorgang 'uebernehmen' - der sichert die
 * Fabric und legt den Container mit Rueckweg neu an (mt_container_neu_anlegen).
 * Pflichthaken; nur bei eigenem Container und ohne laufenden Vorgang. */
if ($mt_post && isset($_POST['abbild_umstieg'])) {
    $mt_ziel = is_string($_POST['abbild_umstieg']) ? $_POST['abbild_umstieg'] : '';
    $mt_cfg = mt_config();
    if (!in_array($mt_ziel, array('matterjs', 'python'), true)) {
        $mt_fehler[] = mt_t('EINST.FEHLER_CONTAINERBEFEHL');
    } elseif (empty($_POST['umstieg_ja'])) {
        $mt_fehler[] = mt_t('EINST.UMSTIEG_HAKEN');
    } elseif ((string) $mt_cfg['eigener_container'] !== '1') {
        $mt_fehler[] = mt_t('EINST.V_NUR_EIGENER');
    } elseif (mt_ct_vorgang_aktiv() !== null) {
        $mt_fehler[] = mt_t('EINST.V_LAEUFT_SCHON');
    } else {
        $mt_abbild_vorher = (string) $mt_cfg['container_abbild'];
        $mt_cfg['container_abbild'] = mt_abbild_vorgabe($mt_ziel);
        if (!mt_config_speichern($mt_cfg)) {
            $mt_fehler[] = sprintf(mt_t('EINST.FEHLER_SPEICHERN'), $mt_p['config']);
        } else {
            mt_log('Container: Abbild umgestellt von ' . $mt_abbild_vorher . ' auf ' . $mt_cfg['container_abbild'] . '.');
            $mt_meldungen[] = sprintf(mt_t('EINST.UMSTIEG_GESPEICHERT'), mt_e($mt_cfg['container_abbild']));
            list($mt_ok, $mt_ausgabe) = mt_ct_vorgang_starten('uebernehmen');
            if ($mt_ok) { $mt_meldungen[] = $mt_ausgabe; } else { $mt_fehler[] = $mt_ausgabe; }
        }
    }
    $mt_tab = 'tab-settings';
}

/* ---------------- Ein anderer eigener Container ----------------
 * 0.9.35 (Nr. 5 und 7): ein Container mit dem Label dieses Plugins unter
 * einem anderen Namen (container_name geaendert, eigener_container=0, Rest
 * eines Neuanlegens) - anhalten, starten oder entfernen. Dieselbe
 * Eigentumspruefung wie die anderen Knoepfe (container_eigen.sh, mit dem
 * Label gilt er seit Nr. 3 immer als eigen). Nie der Datenordner. */
if ($mt_post && isset($_POST['container_anderer'])) {
    $mt_was = is_string($_POST['container_anderer']) ? $_POST['container_anderer'] : '';
    $mt_aname = isset($_POST['anderer_name']) && is_string($_POST['anderer_name']) ? $_POST['anderer_name'] : '';
    if (!in_array($mt_was, array('start', 'stop', 'entfernen'), true)
        || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,90}$/', $mt_aname)) {
        $mt_fehler[] = mt_t('EINST.FEHLER_CONTAINERBEFEHL');
    } elseif (mt_ct_vorgang_aktiv() !== null) {
        $mt_fehler[] = mt_t('EINST.V_LAEUFT_SCHON');
    } else {
        list($mt_ok, $mt_ausgabe) = mt_container($mt_was, $mt_aname);
        if ($mt_ok) {
            $mt_meldungen[] = sprintf(mt_t('EINST.CONTAINER_OK'), mt_e($mt_was . ' ' . $mt_aname));
        } else {
            $mt_fehler[] = sprintf(mt_t('EINST.CONTAINER_FEHL'), mt_e($mt_was . ' ' . $mt_aname))
                         . ' <span class="sm-mono">' . mt_e(substr($mt_ausgabe, 0, 800)) . '</span>';
        }
    }
    $mt_tab = 'tab-settings';
}

/* ---------------- Fabric zurueckspielen ----------------
 * 0.9.35 (Nr. 12): eine automatische Sicherung (Nr. 6) oder ein
 * hochgeladenes tar.gz. Pflichthaken, Formularmerkmal (Wachposten), Liste vor
 * dem Auspacken geprueft - die Schritte stehen ueber
 * mt_fabric_wiederherstellen(). Synchron, jeder Schritt mit Frist. */
if ($mt_post && isset($_POST['fabric_wh'])) {
    $mt_quelle = isset($_POST['fabric_wh_quelle']) && is_string($_POST['fabric_wh_quelle'])
        ? $_POST['fabric_wh_quelle'] : '';
    $mt_archiv = '';
    if (empty($_POST['fabric_wh_ja'])) {
        $mt_fehler[] = mt_t('EINST.FW_HAKEN');
    } elseif ($mt_quelle === 'upload') {
        if (!isset($_FILES['fabric_datei']) || !is_array($_FILES['fabric_datei'])
            || !isset($_FILES['fabric_datei']['tmp_name']) || !is_string($_FILES['fabric_datei']['tmp_name'])
            || !@is_uploaded_file($_FILES['fabric_datei']['tmp_name'])) {
            $mt_fehler[] = mt_t('EINST.FW_KEINE_DATEI');
        } elseif ((int) $_FILES['fabric_datei']['size'] > 64 * 1024 * 1024) {
            $mt_fehler[] = mt_t('EINST.FW_ZU_GROSS');
        } else {
            $mt_archiv = $_FILES['fabric_datei']['tmp_name'];
        }
    } else {
        foreach (mt_fabric_sicherungen() as $mt_fs) {
            if ($mt_fs['name'] === $mt_quelle) {
                $mt_archiv = $mt_fs['pfad'];
            }
        }
        if ($mt_archiv === '') {
            $mt_fehler[] = mt_t('EINST.FW_KEINE_DATEI');
        }
    }
    if ($mt_archiv !== '') {
        @set_time_limit(600);
        ignore_user_abort(true);
        list($mt_ok, $mt_ausgabe) = mt_fabric_wiederherstellen($mt_archiv);
        if ($mt_ok) { $mt_meldungen[] = $mt_ausgabe; } else { $mt_fehler[] = $mt_ausgabe; }
    }
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
    $mt_adr_vorher = $mt_cfg['server_host'] . ':' . (int) $mt_cfg['server_port'];
    // 0.9.35 (Nr. 5): die Aufrufzeile vorher - aendert sie sich, wird es gesagt.
    $mt_befehl_vorher = mt_container_befehl($mt_cfg);
    /* O2 (Durchgang 30.09.2026): nur Leerraum am Rand entfernen, sonst
     * nichts. Bis 0.9.30 entfernte diese Funktion still Anfuehrungs- und
     * Steuerzeichen - eine Adresse in Anfuehrungszeichen wurde ohne Meldung
     * zu einer anderen (Bericht oberflaeche Nr. 5). Was danach nicht ins
     * Muster passt, wird unten abgewiesen und gemeldet; die Muster schliessen
     * Anfuehrungs- und Steuerzeichen aus. */
    $sauber = function ($feld) {
        return isset($_POST[$feld]) && is_string($_POST[$feld]) ? trim($_POST[$feld]) : '';
    };

    $host = $sauber('server_host');
    if ($host === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9\.\-:_\[\]]{0,80}$/', $host)) {
        $mt_fehler[] = mt_t('EINST.FEHLER_HOST');
        $mt_bean[] = 'server_host';
    } else {
        $mt_cfg['server_host'] = $host;
    }

    /* O6: dieselben Grenzen wie beim Zurueckspielen (mt_zahlgrenzen()). */
    foreach (mt_zahlgrenzen() as $feld => $grenzen) {
        $w = $sauber($feld);
        if (!preg_match('/^[0-9]+$/', $w)) {
            $mt_fehler[] = sprintf(mt_t('EINST.FEHLER_ZAHL'), mt_t('EINST.L_' . strtoupper($feld)));
            $mt_bean[] = $feld;
            continue;
        }
        if ((int) $w < $grenzen[0] || (int) $w > $grenzen[1]) {
            $mt_fehler[] = sprintf(mt_t('EINST.FEHLER_BEREICH'),
                mt_t('EINST.L_' . strtoupper($feld)), $grenzen[0], $grenzen[1]);
            $mt_bean[] = $feld;
            continue;
        }
        $mt_cfg[$feld] = (int) $w;
    }

    /* Nr. 19 (B-Nachzug 01.10.2026): ein leeres Feld wird beanstandet. Bis
     * 0.9.34 behielt es still den alten Namen bzw. das alte Abbild - die Seite
     * meldete "gespeichert" und zeigte im Feld wieder den alten Wert. */
    $name = $sauber('container_name');
    if ($name === '') {
        $mt_fehler[] = mt_t('EINST.FEHLER_CONTAINERNAME_LEER');
        $mt_bean[] = 'container_name';
    } elseif (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,60}$/', $name)) {
        $mt_fehler[] = mt_t('EINST.FEHLER_CONTAINERNAME');
        $mt_bean[] = 'container_name';
    } else {
        $mt_cfg['container_name'] = $name;
    }
    $abbild = $sauber('container_abbild');
    if ($abbild === '') {
        $mt_fehler[] = mt_t('EINST.FEHLER_ABBILD_LEER');
        $mt_bean[] = 'container_abbild';
    } elseif (!preg_match('#^[A-Za-z0-9][A-Za-z0-9_./\-]{2,120}(:[A-Za-z0-9_.\-]{1,40})?$#', $abbild)) {
        $mt_fehler[] = mt_t('EINST.FEHLER_ABBILD');
        $mt_bean[] = 'container_abbild';
    } else {
        $mt_cfg['container_abbild'] = $abbild;
    }

    /* 0.9.35 (Nr. 1): Schnittstelle fuer --primary-interface (leer =
     * weglassen), Muster laut Vertrag (mt_textmuster()). */
    $mt_pi = $sauber('primary_interface');
    $mt_tmuster = mt_textmuster();
    if (isset($_POST['primary_interface']) && !is_string($_POST['primary_interface'])) {
        $mt_fehler[] = sprintf(mt_t('EINST.FEHLER_KEIN_TEXT'), mt_t('EINST.L_PRIMARY_INTERFACE'));
        $mt_bean[] = 'primary_interface';
    } elseif (!preg_match($mt_tmuster['primary_interface'], $mt_pi)) {
        $mt_fehler[] = mt_t('EINST.FEHLER_PRIMARY_INTERFACE');
        $mt_bean[] = 'primary_interface';
    } else {
        $mt_cfg['primary_interface'] = $mt_pi;
    }

    $mt_cfg['eigener_container'] = isset($_POST['eigener_container']) ? 1 : 0;
    $mt_cfg['steuerung_ein'] = isset($_POST['steuerung_ein']) ? 1 : 0;
    $mt_cfg['schloss_ein'] = isset($_POST['schloss_ein']) ? 1 : 0;
    // 0.9.39 (Entscheidung 8): fremde Systeme trennen (am Geraet erprobt am
    // 15.10.2026 mit dem python-matter-server; matterjs nur gegen die Attrappe).
    $mt_cfg['fabric_entfernen_ein'] = isset($_POST['fabric_entfernen_ein']) ? 1 : 0;
    // 0.9.35 (Nr. 1): nur auf 127.0.0.1 lauschen (eigener Container).
    $mt_cfg['server_lokal'] = isset($_POST['server_lokal']) ? 1 : 0;

    /* X-2: abgewiesen - die Eingaben reisen zurueck ins Formular. */
    if ($mt_fehler) {
        $mt_eingaben = mt_eingaben_sammeln('speichern', $mt_bean);
    }
    if (!$mt_fehler) {
        if (mt_config_speichern($mt_cfg)) {
            $mt_meldungen[] = mt_t('EINST.GESPEICHERT');
            /* C4 (Durchgang 30.09.2026): der Dienst vergleicht Adresse und Port
             * bei jedem Lesen der Konfiguration und verbindet neu - das wird
             * hier gesagt, statt nur "gespeichert". */
            $mt_adr_nachher = $mt_cfg['server_host'] . ':' . (int) $mt_cfg['server_port'];
            if ($mt_adr_nachher !== $mt_adr_vorher && mt_dienst_pid() > 0) {
                $mt_meldungen[] = mt_e(sprintf(mt_t('EINST.ADRESSE_NEU'), $mt_adr_nachher));
            }
            /* 0.9.35 (Nr. 5): Container-Einstellungen wirken erst nach dem
             * Neuanlegen - bis 0.9.34 sagte das niemand. */
            if ((string) $mt_cfg['eigener_container'] === '1'
                && mt_container_befehl($mt_cfg) !== $mt_befehl_vorher) {
                $mt_meldungen[] = mt_t('EINST.CONTAINER_NEU_NOETIG');
            }
        } else {
            $mt_fehler[] = sprintf(mt_t('EINST.FEHLER_SPEICHERN'), $mt_p['config']);
            // Nicht gespeichert: auch dann bleiben die Eingaben stehen (X-2).
            $mt_eingaben = mt_eingaben_sammeln('speichern', array());
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
    $mt_praefix_vorher = mt_mqtt_praefix($mt_mcfg);
    $mt_mcfg['mqtt_ein'] = isset($_POST['mqtt_ein']) ? 1 : 0;
    /* Der Haken fuer die Rohdurchreichung steht in DIESEM Formular (Reiter
     * MQTT) und wird deshalb auch hier gelesen. Bis 0.9.16 las ihn der
     * Handler des Reiters Einstellungen - der ihn nie mitgeschickt bekommt.
     * Folge: er liess sich gar nicht einschalten, und jedes Speichern der
     * Einstellungen schaltete ihn ab. Genau die Fehlerklasse, die der
     * Kommentar am Ende des Einstellungen-Handlers fuer mqtt_ein beschreibt. */
    $mt_mcfg['roh_ein'] = isset($_POST['roh_ein']) ? 1 : 0;
    /* Tuer-1 (Verbesserungsbau 30.09.2026): Tueren und Schloesser zusaetzlich
     * unter haus/tuer/ melden. Ab Werk aus; wer ihn abhakt, bekommt die je
     * gesendeten Themen abgeraeumt (unten, nach dem Speichern). */
    $mt_haus_vorher = !empty($mt_mcfg['tuer_haus']);
    $mt_mcfg['tuer_haus'] = isset($_POST['tuer_haus']) ? 1 : 0;
    /* Gruppe 8 (0.9.44, E1-E3): "Befehle ueber MQTT annehmen" und "Schloesser ueber MQTT entsperren". */
    $mt_mcfg['befehle_mqtt_ein'] = isset($_POST['befehle_mqtt_ein']) ? 1 : 0;
    $mt_mcfg['befehle_mqtt_entsperren'] = isset($_POST['befehle_mqtt_entsperren']) ? 1 : 0;
    /* O2: nicht still Zeichen entfernen, sondern gegen das Muster pruefen
     * und abweisen (Bericht oberflaeche Nr. 5). */
    $mt_mtopic = isset($_POST['mqtt_topic']) && is_string($_POST['mqtt_topic'])
        ? trim($_POST['mqtt_topic']) : '';
    /* Nr. 19: ein Praefix nur aus Schraegstrichen ("///") wurde bis 0.9.34
     * nach dem Abschneiden der Randstriche still LEER gespeichert, und Dienst
     * wie Oberflaeche nahmen dann die Vorgabe "matter". Jetzt beanstandet -
     * dieselbe Regel steht in mt_wert_pruefen() (Zurueckspielen, X-3).
     * Schraegstriche am Rand eines sonst gueltigen Praefixes werden weiter
     * entfernt: "matter/" und "matter" sind dasselbe Praefix (der Dienst
     * schneidet sie ebenso ab), es wird kein anderer Wert daraus. */
    if ($mt_mtopic === '' || !preg_match('#^[A-Za-z0-9_/\-]{1,64}$#', $mt_mtopic)
        || trim($mt_mtopic, '/') === '') {
        $mt_fehler[] = mt_t('EINST.FEHLER_TOPIC');
        $mt_bean[] = 'mqtt_topic';
    } else {
        $mt_mcfg['mqtt_topic'] = trim($mt_mtopic, '/');
    }
    /* Auswahl, welche Geraete ueberhaupt hinausgehen. Leer heisst alle -
     * das ist die Vorgabe, damit sich fuer bestehende Anlagen nichts
     * aendert. Was nicht ins Muster passt, wird ABGEWIESEN, nicht still
     * zurechtgebogen. */
    /* Nr. 19: ein Feld statt Text wird beanstandet, ohne erst "Array" daraus
     * zu machen (PHP 8: Warnung vor der Umleitung). */
    $mt_nur_feld = isset($_POST['mqtt_nur']) && !is_string($_POST['mqtt_nur']);
    $mt_nur = $mt_nur_feld ? '' : trim(isset($_POST['mqtt_nur']) ? $_POST['mqtt_nur'] : '');
    if ($mt_nur_feld || !mt_mqtt_nur_gueltig($mt_nur)) {
        $mt_fehler[] = mt_t('EINST.FEHLER_MQTT_NUR');
        $mt_bean[] = 'mqtt_nur';
    } else {
        // Gespeichert durch einfache Kommas getrennt ("1, 2" -> "1,2"); bis
        // 0.9.34 entstand daraus "1,,2".
        $mt_mcfg['mqtt_nur'] = preg_replace('/[ ,;]+/', ',', $mt_nur);
    }
    /* Gruppe 8 (0.9.44, Entwurf 3.9): mit "Befehle ueber MQTT annehmen" muss das Praefix auch fuer die
     * Befehlsbruecke taugen (bb_praefix_gueltig(): hoechstens fuenf Ebenen zu je 40 Zeichen, keine leere Ebene) -
     * Matter2Lox selbst erlaubt "a//b" und 64 Zeichen am Stueck. */
    if (!in_array('mqtt_topic', $mt_bean, true) && !empty($mt_mcfg['befehle_mqtt_ein']) && mt_bruecke_laden()
        && !bb_praefix_gueltig(mt_mqtt_praefix($mt_mcfg))) {
        $mt_fehler[] = mt_t('BEFEHL.FEHLER_PRAEFIX');
        $mt_bean[] = 'mqtt_topic';
    }
    /* X-2: abgewiesen - die Eingaben reisen zurueck ins Formular. */
    if ($mt_fehler) {
        $mt_eingaben = mt_eingaben_sammeln('save_mqtt', $mt_bean);
    }
    if (!$mt_fehler) {
        if (mt_config_speichern($mt_mcfg)) {
            $mt_meldungen[] = mt_t('EINST.GESPEICHERT');
            /* M5 (Durchgang 30.09.2026): altes und neues Praefix merken - die
             * Deinstallation raeumt unter beiden ab, und ein laufender Dienst
             * sendet unter dem neuen den vollen Satz und raeumt das alte ab. */
            $mt_praefix_nachher = mt_mqtt_praefix($mt_mcfg);
            if ($mt_praefix_nachher !== $mt_praefix_vorher) {
                mt_praefix_merken(array($mt_praefix_vorher, $mt_praefix_nachher));
                $mt_meldungen[] = mt_e(sprintf(mt_t('MQTT.PRAEFIX_GEWECHSELT'),
                                               $mt_praefix_vorher, $mt_praefix_nachher));
            }
            /* Gruppe 8 (0.9.44, Entwurf 3.9): die Befehlsbruecke gleich starten bzw. anhalten (nach einem
             * Praefixwechsel neu); sonst tut es der Waechter in cron.01min binnen einer Minute. */
            $mt_bbr = mt_bruecke_schalten($mt_praefix_nachher !== $mt_praefix_vorher);
            $mt_bbs = mt_bruecke_satz($mt_bbr);
            if ($mt_bbs !== '') {
                if (in_array($mt_bbr[0], array('ein', 'aus'), true)) {
                    $mt_meldungen[] = $mt_bbs;
                } else {
                    $mt_fehler[] = $mt_bbs;
                }
            }
            /* Tuer-1: abgehakt - die je unter haus/tuer/ gesendeten Themen
             * gleich abraeumen, mit Ruecklesen beim Broker (der Dienst tut
             * es ausserdem selbst, sobald er die Einstellung liest). */
            if ($mt_haus_vorher && empty($mt_mcfg['tuer_haus']) && mt_haus_gemerkt()) {
                list($mt_hrc, $mt_hzeilen) = mt_dienst_leeren(array('--haus-leeren'));
                $mt_hsatz = mt_e(implode(' ', $mt_hzeilen));
                if ($mt_hrc === 0) {
                    $mt_meldungen[] = mt_t('MQTT.HAUS_ABGERAEUMT') . ' ' . $mt_hsatz;
                } else {
                    $mt_fehler[] = mt_t('MQTT.HAUS_ABRAEUMEN_FEHL') . ' ' . $mt_hsatz;
                }
            }
        } else {
            /* Bis 0.9.16 fehlte dieser Zweig als einzigem der vier
             * Speichern-Handler: scheiterte das Schreiben, sah der Bediener
             * weder Erfolg noch Fehler. */
            $mt_fehler[] = sprintf(mt_t('EINST.FEHLER_SPEICHERN'), $mt_p['config']);
            $mt_eingaben = mt_eingaben_sammeln('save_mqtt', array());
        }
    }
    $mt_tab = 'tab-mqtt';
}

/* ---------------- Netz-Zugangsdaten speichern ---------------- */
if ($mt_post && isset($_POST['netz_speichern'])) {
    $mt_cfg = mt_config();
    /* O2 (Durchgang 30.09.2026): eine SSID darf Anfuehrungszeichen tragen -
     * sie wird unveraendert gespeichert (JSON maskiert sie). Bis 0.9.30
     * wurden sie still entfernt, und beim Anlernen ging eine andere SSID an
     * das Geraet (Bericht oberflaeche Nr. 5). Steuerzeichen werden
     * abgewiesen, nicht entfernt. */
    /* Nr. 19 (B-Nachzug 01.10.2026): ein geleertes Feld WLAN-Name wird
     * gespeichert, wie es dasteht. Bis 0.9.34 behielt es still den alten
     * Namen - die Seite meldete "gespeichert", und der Name liess sich nie
     * mehr entfernen. Der WLAN-Name ist kein Geheimnis (er steht sichtbar im
     * Feld); "leer = unveraendert" gilt nur fuer Passwort und Dataset.
     * Kommt eines der Felder nicht als Text an (nur bei einer gebauten
     * Anfrage), wird es beanstandet; bis 0.9.34 blieb es still beim alten
     * Wert. */
    $ssid = isset($_POST['wlan_ssid']) && is_string($_POST['wlan_ssid']) ? $_POST['wlan_ssid'] : '';
    if (isset($_POST['wlan_ssid']) && !is_string($_POST['wlan_ssid'])) {
        $mt_fehler[] = sprintf(mt_t('EINST.FEHLER_KEIN_TEXT'), mt_t('ANLERN.L_SSID'));
        $mt_bean[] = 'wlan_ssid';
    } elseif (preg_match('/[\x00-\x1F\x7F]/', $ssid)) {
        $mt_fehler[] = mt_t('ANLERN.FEHLER_SSID');
        $mt_bean[] = 'wlan_ssid';
    } elseif (isset($_POST['wlan_ssid'])) {
        $mt_cfg['wlan_ssid'] = $ssid;
    }
    // Leeres Passwortfeld loescht nichts (Geheimnisfeld).
    $pw = isset($_POST['wlan_passwort']) && is_string($_POST['wlan_passwort'])
        ? $_POST['wlan_passwort'] : '';
    if (isset($_POST['wlan_passwort']) && !is_string($_POST['wlan_passwort'])) {
        $mt_fehler[] = sprintf(mt_t('EINST.FEHLER_KEIN_TEXT'), mt_t('ANLERN.L_WLANPW'));
        $mt_bean[] = 'wlan_passwort';
    } elseif ($pw !== '') {
        $mt_cfg['wlan_passwort'] = $pw;
    }
    /* ERST pruefen, DANN uebernehmen. Bis 0.9.16 wurde jedes Nicht-Hex-
     * Zeichen still entfernt und danach geprueft - die Pruefung konnte dann
     * nur noch an der Laenge scheitern. Das widerspricht der eigenen Regel
     * im Endpunkt: was nicht ins Muster passt, wird abgewiesen und gemeldet,
     * nie zurechtgebogen. */
    $ds_feld = isset($_POST['thread_dataset']) && !is_string($_POST['thread_dataset']);
    $ds = $ds_feld ? '' : trim(isset($_POST['thread_dataset']) ? $_POST['thread_dataset'] : '');
    if ($ds_feld || ($ds !== '' && !preg_match('/^[0-9A-Fa-f]{20,600}$/', $ds))) {
        $mt_fehler[] = mt_t('ANLERN.FEHLER_THREAD');
        $mt_bean[] = 'thread_dataset';
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
    /* X-2: abgewiesen - die Eingaben reisen zurueck (ohne WLAN-Passwort und
     * Thread-Dataset: beide sind Netzzugangsdaten). */
    if ($mt_fehler) {
        $mt_eingaben = mt_eingaben_sammeln('netz_speichern', $mt_bean);
    }
    if (!$mt_fehler) {
        if (mt_config_speichern($mt_cfg)) {
            $mt_meldungen[] = mt_t('ANLERN.NETZ_GESPEICHERT');
        } else {
            $mt_fehler[] = sprintf(mt_t('EINST.FEHLER_SPEICHERN'), $mt_p['config']);
            $mt_eingaben = mt_eingaben_sammeln('netz_speichern', array());
        }
    }
    $mt_tab = 'tab-commission';
}

/* ---------------- Gespeicherte Netzdaten loeschen ----------------
 *
 * 0.9.35 (Nr. 13, Vertrag): ein leeres Passwort- bzw. Dataset-Feld heisst
 * beim Speichern "beibehalten" - geloescht werden konnten beide bis 0.9.34
 * gar nicht. Je ein Knopf mit Pflichthaken setzt den Schluessel auf "". Der
 * Matter-Server behaelt uebergebene Daten bis zu seinem Neustart; das sagt
 * die Meldung. */
if ($mt_post && isset($_POST['netz_loeschen'])) {
    $mt_nw = is_string($_POST['netz_loeschen']) ? $_POST['netz_loeschen'] : '';
    $mt_nschl = array('wlan' => 'wlan_passwort', 'thread' => 'thread_dataset');
    if (!isset($mt_nschl[$mt_nw])) {
        $mt_fehler[] = mt_t('TEST.M_UNBEKANNT');
    } elseif (empty($_POST['netz_loeschen_ja'])) {
        $mt_fehler[] = mt_t('ANLERN.LOESCHEN_HAKEN');
    } else {
        $mt_cfg = mt_config();
        $mt_cfg[$mt_nschl[$mt_nw]] = '';
        if (mt_config_speichern($mt_cfg)) {
            $mt_meldungen[] = mt_t($mt_nw === 'wlan' ? 'ANLERN.PW_GELOESCHT' : 'ANLERN.DATASET_GELOESCHT');
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
    /* Nr. 19: ein Feld statt Text wird abgewiesen wie eine falsche Adresse,
     * ohne erst "Array" daraus zu machen. */
    if (isset($_POST['thread_br']) && !is_string($_POST['thread_br'])) {
        $mt_adr = '';
        list($mt_stand, $mt_text) = array(0, mt_t('ANLERN.BR_FORM'));
    } else {
        $mt_adr = trim(isset($_POST['thread_br']) ? $_POST['thread_br'] : '');
        list($mt_stand, $mt_text) = mt_thread_dataset_holen($mt_adr);
    }
    /* X-2: nur Stand 0 beanstandet die Eingabe (Adresse abgewiesen, nichts
     * gespeichert); bei Stand 2 ist die Adresse gemerkt. */
    if ($mt_stand === 0) {
        $mt_eingaben = mt_eingaben_sammeln('br_holen', array('thread_br'));
    }
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
    /* Nachtrag B-Nachzug 01.10.2026: is_string() statt (string). Eine Liste
     * ergab unter PHP 8 eine Warnung vor der Umleitung (kein 303); jetzt ist
     * sie ein unbekannter Befehl. */
    $mt_dw = is_string($_POST['dienst']) ? $_POST['dienst'] : '';
    list($mt_ok, $mt_ausgabe, $mt_rc) = array_pad(mt_dienst($mt_dw), 3, 0);
    if ((int) $mt_rc === 3) {
        /* O4 (Durchgang 30.09.2026): dienst.sh hat wegen einer laufenden
         * Aktualisierung nichts angefasst (Rueckgabe 3). Bis 0.9.30 stand
         * hier "Dienst gestartet." bzw. "Dienst neu gestartet." - und "Neu
         * starten" hatte den Dienst sogar angehalten (Bericht oberflaeche
         * Nr. 7). */
        $mt_meldungen[] = mt_e(mt_t('EINST.DIENST_MARKE'));
    } elseif ($mt_ok) {
        $mt_meldungen[] = mt_t('EINST.DIENST_' . strtoupper($mt_dw)) . ' ' . mt_e($mt_ausgabe);
    } else {
        $mt_fehler[] = mt_e($mt_ausgabe);
    }
    $mt_tab = 'tab-settings';
}

/* ---------------- Container ---------------- */
if ($mt_post && isset($_POST['container'])) {
    $mt_was = is_string($_POST['container']) ? $_POST['container'] : '';   // Liste: unbekannter Befehl
    /* E3 (Welle 2): "anlegen" gibt es als Einzelknopf nicht mehr - das tut
     * "Matter-Server einrichten" im Hintergrund, und ein Seitenaufruf soll
     * nicht mehr 15 Minuten stehen. "holen" laeuft aus demselben Grund im
     * Hintergrund (derselbe Aufruf mt_container('holen')). starten, anhalten,
     * neu starten, entfernen wirken wie bisher. Solange ein Vorgang laeuft,
     * fasst kein Einzelknopf den Container an. */
    if (!in_array($mt_was, array('start', 'stop', 'restart', 'entfernen', 'holen'), true)) {
        $mt_fehler[] = mt_t('EINST.FEHLER_CONTAINERBEFEHL');
    } elseif (mt_ct_vorgang_aktiv() !== null) {
        $mt_fehler[] = mt_t('EINST.V_LAEUFT_SCHON');
    } elseif ($mt_was === 'holen') {
        list($mt_ok, $mt_ausgabe) = mt_ct_vorgang_starten('holen');
        if ($mt_ok) { $mt_meldungen[] = $mt_ausgabe; } else { $mt_fehler[] = $mt_ausgabe; }
    } else {
        list($mt_ok, $mt_ausgabe) = mt_container($mt_was);
        if ($mt_ok) {
            $mt_meldungen[] = sprintf(mt_t('EINST.CONTAINER_OK'), mt_e($mt_was))
                            . ' <span class="sm-mono">' . mt_e(substr($mt_ausgabe, 0, 200)) . '</span>';
        } else {
            $mt_fehler[] = sprintf(mt_t('EINST.CONTAINER_FEHL'), mt_e($mt_was))
                         . ' <span class="sm-mono">' . mt_e(substr($mt_ausgabe, 0, 800)) . '</span>';
        }
    }
    $mt_tab = 'tab-settings';
}

/* ---------------- Neues Token ---------------- */
if ($mt_post && isset($_POST['token_neu'])) {
    /* 0.9.35 (Nr. 4): Pflichthaken (Muster geraet_leeren). Ein neues Token
     * macht jede Adresse in Loxone ungueltig - bis 0.9.34 genuegte ein
     * versehentlicher Klick. */
    if (empty($_POST['token_neu_ja'])) {
        $mt_fehler[] = mt_t('LOX.TOKEN_HAKEN');
    } else {
        $mt_cfg = mt_config();
        $mt_cfg['aktionstoken'] = mt_token_erzeugen();
        if (mt_config_speichern($mt_cfg)) {
            $mt_meldungen[] = mt_t('LOX.TOKEN_NEU');
        } else {
            $mt_fehler[] = sprintf(mt_t('EINST.FEHLER_SPEICHERN'), $mt_p['config']);
        }
    }
    $mt_tab = 'tab-loxone';
}

/* ---------------- Lesetoken ----------------
 * 0.9.35 (Nr. 1, Vertrag): ein zweites Token NUR fuer lesende Aktionen
 * (status, statusalle, wert, liste, roh). Erzeugen ersetzt ein vorhandenes,
 * loeschen schliesst den Lesezugang - beides mit Pflichthaken, sobald es
 * eines gibt. Leer = keines. */
if ($mt_post && isset($_POST['lesetoken'])) {
    $mt_lw = is_string($_POST['lesetoken']) ? $_POST['lesetoken'] : '';
    $mt_cfg = mt_config();
    $mt_lalt = is_scalar($mt_cfg['lesetoken']) ? trim((string) $mt_cfg['lesetoken']) : '';
    if (!in_array($mt_lw, array('neu', 'loeschen'), true)) {
        $mt_fehler[] = mt_t('TEST.M_UNBEKANNT');
    } elseif ($mt_lalt !== '' && empty($_POST['lesetoken_ja'])) {
        $mt_fehler[] = mt_t('LOX.LESETOKEN_HAKEN');
    } elseif ($mt_lw === 'loeschen' && $mt_lalt === '') {
        $mt_meldungen[] = mt_t('LOX.LESETOKEN_KEINS');
    } else {
        $mt_neu_lt = '';
        if ($mt_lw === 'neu') {
            do {
                $mt_neu_lt = mt_token_erzeugen();
            } while ($mt_neu_lt === (string) $mt_cfg['aktionstoken']);
        }
        $mt_cfg['lesetoken'] = $mt_neu_lt;
        if (mt_config_speichern($mt_cfg)) {
            $mt_meldungen[] = mt_t($mt_lw === 'neu' ? 'LOX.LESETOKEN_NEU' : 'LOX.LESETOKEN_GELOESCHT');
        } else {
            $mt_fehler[] = sprintf(mt_t('EINST.FEHLER_SPEICHERN'), $mt_p['config']);
        }
    }
    $mt_tab = 'tab-loxone';
}

/* ---------------- Adresse fuer die Loxone-Vorlagen ----------------
 * 0.9.35 (Nr. 1, Vertrag): eigenes Formular im Reiter Einbindung in Loxone,
 * eigener Handler - er ruehrt nur loxone_adresse an. Leer = IP des LoxBerry. */
if ($mt_post && isset($_POST['save_loxone'])) {
    $mt_cfg = mt_config();
    $mt_tmuster = mt_textmuster();
    $mt_la = isset($_POST['loxone_adresse']) && is_string($_POST['loxone_adresse'])
        ? trim($_POST['loxone_adresse']) : null;
    if ($mt_la === null || !preg_match($mt_tmuster['loxone_adresse'], $mt_la)
        || stripos($mt_la, 'http') === 0) {
        $mt_fehler[] = mt_t('LOX.FEHLER_ADRESSE');
        $mt_bean[] = 'loxone_adresse';
        $mt_eingaben = mt_eingaben_sammeln('save_loxone', $mt_bean);
    } else {
        $mt_cfg['loxone_adresse'] = $mt_la;
        /* Gruppe 8 (0.9.44, E1): der Haken des HTTP-Endpunkts steht im selben Formular. */
        $mt_cfg['endpunkt_ein'] = isset($_POST['endpunkt_ein']) ? 1 : 0;
        if (mt_config_speichern($mt_cfg)) {
            $mt_meldungen[] = mt_t('EINST.GESPEICHERT');
        } else {
            $mt_fehler[] = sprintf(mt_t('EINST.FEHLER_SPEICHERN'), $mt_p['config']);
            $mt_eingaben = mt_eingaben_sammeln('save_loxone', array());
        }
    }
    $mt_tab = 'tab-loxone';
}

/* ---------------- Log leeren ---------------- */
if ($mt_post && isset($_POST['log_leeren'])) {
    @mkdir(dirname($mt_p['log']), 0775, true);
    /* O9 (Durchgang 30.09.2026): die Meldung haengt an der Wirkung. Bis
     * 0.9.30 wurde der Rueckgabewert verworfen und "geleert" gemeldet, auch
     * wenn die Datei unveraendert blieb (Bericht oberflaeche Nr. 13). */
    $mt_lzeile = '[' . date('Y-m-d H:i:s') . '] ' . mt_t('LOG.GELEERT') . "\n";
    $mt_lok = @file_put_contents($mt_p['log'], $mt_lzeile) === strlen($mt_lzeile);
    /* Der Dienst rotiert mit backupCount=1; ohne diese Zeile blieben bis zu
     * 512 kB in matter2lox.log.1 auf der Ramdisk liegen, und wer den Knopf
     * zum Platzsparen drueckte, gewann die Haelfte. Dazu die Startdatei. */
    if (is_file($mt_p['log'] . '.1')) {
        @unlink($mt_p['log'] . '.1');
    }
    if (is_file(dirname($mt_p['log']) . '/matter2lox.start.log')) {
        @unlink(dirname($mt_p['log']) . '/matter2lox.start.log');
    }
    if ($mt_lok) {
        $mt_meldungen[] = mt_t('LOG.GELEERT');
    } else {
        $mt_fehler[] = mt_e(sprintf(mt_t('LOG.FEHLER_LEEREN'), $mt_p['log']));
    }
    $mt_tab = 'tab-log';
}

/* ---------------- Aktionen aus Test und Anlernen ---------------- */
if ($mt_post && isset($_POST['test'])) {
    $mt_tw = is_string($_POST['test']) ? $_POST['test'] : '';   // Liste: unbekannte Aktion
    $mt_terg = mt_test_aktion($mt_tw);
    $mt_stand = (int) $mt_terg[0];
    $mt_text = (string) $mt_terg[1];
    /* 0.9.39: das strukturierte Ergebnis (antwort.daten). Die Codes eines
     * Fensters gehen in fenster.json (0600), NIE in die Einmalmeldung und
     * nie in eine Meldung (Befund F1). */
    $mt_tdaten = isset($mt_terg[3]) && is_array($mt_terg[3]) ? $mt_terg[3] : null;
    if ($mt_stand === 1) {
        $mt_meldungen[] = mt_e($mt_text);
    } else {
        $mt_fehler[] = mt_e($mt_text);
    }
    $mt_tnr = isset($_POST['test_geraet']) && is_string($_POST['test_geraet'])
        && preg_match('/^[0-9]{1,3}$/', $_POST['test_geraet']) ? (int) $_POST['test_geraet'] : 0;
    if ($mt_tw === 'fenster' && $mt_stand === 1 && $mt_tdaten !== null) {
        $mt_hcfg = mt_config();
        $mt_bauart = (string) $mt_hcfg['eigener_container'] === '1'
            ? mt_abbild_bauart($mt_hcfg['container_abbild']) : 'fremd';
        if (!mt_fenster_merken($mt_tdaten, $mt_tnr, $mt_bauart)) {
            $mt_fehler[] = mt_e(mt_t('ANLERN.M_FENSTER_NICHT_GEMERKT'));
        }
    } elseif ($mt_tw === 'fenster_zu' && $mt_stand === 1 && $mt_tdaten !== null
              && isset($mt_tdaten['knoten']) && mt_ganz($mt_tdaten['knoten']) !== null) {
        mt_fenster_vergessen(mt_ganz($mt_tdaten['knoten']));
    } elseif (($mt_tw === 'fabrics' || $mt_tw === 'fabric_entfernen') && $mt_tdaten !== null) {
        $mt_fabrics = mt_fabric_liste_pruefen(array_merge($mt_tdaten, array('nr' => $mt_tnr)));
    }
    $mt_tab = in_array($mt_tw, array('anlernen', 'wlan', 'thread', 'entfernen', 'fenster', 'name',
                                     'fenster_zu', 'fabrics', 'fabric_entfernen'), true)
        ? 'tab-commission' : 'tab-test';
    /* 0.9.35 (Nr. 4): Geraet, Endpunkt, Wert (bzw. Name, IP) bleiben nach der
     * Umleitung stehen - bis 0.9.34 sprangen sie bei jedem Druck auf 1/1/50
     * zurueck. Gereist wird mit der Einmalmeldung (mt_eingabe_felder()). */
    if ($mt_tw === 'anlernen') {
        $mt_eingaben = mt_eingaben_sammeln('anlernen', array());
    } elseif (in_array($mt_tw, array('entfernen', 'fenster', 'name', 'fenster_zu', 'fabrics',
                                     'fabric_entfernen'), true)) {
        $mt_eingaben = mt_eingaben_sammeln('verwalten', array());
    } elseif (!in_array($mt_tw, array('wlan', 'thread'), true)) {
        $mt_eingaben = mt_eingaben_sammeln('schalten', array());
    }
}
/* O1: Selbsttest und Containerprotokoll werden nach der Umleitung beim GET
 * gebildet; mit der Einmalmeldung reist nur ihr Name, nicht ihr Inhalt (ein
 * Containerprotokoll gehoert nicht in eine Datei unter data/). */
$mt_zeige = '';
if ($mt_post && isset($_POST['selbsttest'])) {
    $mt_zeige = 'selbsttest';
    $mt_tab = 'tab-test';
}
if ($mt_post && isset($_POST['containerlog'])) {
    $mt_zeige = 'containerlog';
    $mt_tab = 'tab-test';
}

/* ---------------- Themen eines Geraets abraeumen (Matter2Lox-b1) ----------------
 *
 * Verbesserungsbau 30.09.2026: je Zeile der Tabelle "Erkannte Geraete" ein
 * Haekchen und ein oranger Knopf. Ohne Haekchen passiert nichts (Regeln/04,
 * "Formregeln fuer einen loeschenden Knopf"). matter_dienst.py
 * --geraet-leeren N fragt den Broker, raeumt die zurueckbehaltenen Themen
 * DIESES Geraets ab (auch die unter haus/tuer/) und liest nach; andere
 * Geraete bleiben unberuehrt. Die Meldung sagt, was der Broker bestaetigt. */
if ($mt_post && isset($_POST['geraet_leeren'])) {
    $mt_gnr = is_string($_POST['geraet_leeren']) && preg_match('/^[1-9][0-9]{0,2}$/', $_POST['geraet_leeren'])
        ? (int) $_POST['geraet_leeren'] : 0;
    if ($mt_gnr === 0 || !in_array($mt_gnr, mt_geraetenummern(), true)) {
        $mt_fehler[] = mt_t('EINST.GERAET_LEEREN_UNBEKANNT');
    } elseif (empty($_POST['geraet_leeren_ja'])) {
        $mt_fehler[] = sprintf(mt_t('EINST.GERAET_LEEREN_HAKEN'), $mt_gnr);
    } else {
        list($mt_lrc, $mt_lzeilen) = mt_dienst_leeren(array('--geraet-leeren', (string) $mt_gnr));
        $mt_lsatz = mt_e(implode(' ', $mt_lzeilen));
        if ($mt_lrc === 0) {
            $mt_meldungen[] = sprintf(mt_t('EINST.GERAET_LEEREN_OK'), $mt_gnr) . ' ' . $mt_lsatz;
        } else {
            $mt_fehler[] = sprintf(mt_t('EINST.GERAET_LEEREN_FEHL'), $mt_gnr, $mt_lrc) . ' ' . $mt_lsatz;
        }
    }
    $mt_tab = 'tab-settings';
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
    /* X-3 (Verbesserungsbau 30.09.2026): wuerde das eigene Zurueckspielen
     * einen gespeicherten Wert abweisen, sagt es der Kopf der Datei - nur die
     * Namen, nie die Werte. Geliefert wird trotzdem vollstaendig; die gelbe
     * Warnung am Knopf sagt es vorher. */
    $mt_altwerte = mt_sicherung_altwerte(mt_config());
    if ($mt_altwerte) {
        $mt_sich = array('_warnung' => sprintf(mt_t('EINST.SICH_ALTWERT_KOPF'),
                                               implode(', ', $mt_altwerte))) + $mt_sich;
    }
    $mt_js = json_encode($mt_sich,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($mt_js !== false) {
        /* M19 (0.9.44, Pruefbericht; Hausform Chromecast C16): den Ausgabepuffer leeren - eine Warnung, ein BOM oder
         * eine Leerzeile aus einer eingebundenen Datei laege sonst VOR der Datei. */
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
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
        list($mt_neu, $mt_mangel, $mt_n, $mt_shinweise) = array_pad(mt_sicherung_lesen(
            (string) @file_get_contents($_FILES['mt_sicherung']['tmp_name'])), 4, array());
        if ($mt_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert wird
             * nichts. */
            $mt_fehler[] = mt_t('EINST.SICH_ABGELEHNT') . ' '
                            . implode(' ', $mt_mangel);
        } elseif (mt_config_speichern($mt_neu)) {
            $mt_meldungen[] = sprintf(mt_t('EINST.SICH_UEBERNOMMEN'), $mt_n);
            /* C5: das laufende Token blieb, weil die Datei ein leeres trug. */
            foreach ((array) $mt_shinweise as $mt_sh) {
                $mt_meldungen[] = mt_e($mt_sh);
            }
        } else {
            $mt_fehler[] = mt_t('EINST.SICH_SCHREIBFEHLER');
        }
    }
}

/* ==================================================================
 * 0.9.42: Matter-Bridge (Beta) - Reiter "Bridge"
 * ==================================================================
 * Entwurf Pruefung-Matter2Lox-0.9.42/entwurf_bridge.md, alle 15
 * Entscheidungen wie empfohlen. Ab Werk aus. Alle Knoepfe POST mit
 * Formularmerkmal, danach PRG; Kopplungscodes und Kennwoerter reisen nie in
 * der Einmalmeldung und stehen nie in mt_log. Die Bridge wird nur aus der
 * angemeldeten Oberflaeche verwaltet (kein Weg im Endpunkt html/index.php). */

/* Haken "Bridge betreiben" (ab Werk aus). Abhaken haelt eine laufende
 * eigene Bridge an - sie bleibt angehalten (unless-stopped). */
if ($mt_post && isset($_POST['br_ein_speichern'])) {
    $mt_bc = mt_br_cfg();
    $mt_bc['bridge_ein'] = isset($_POST['bridge_ein']) ? 1 : 0;
    if (!mt_br_cfg_speichern($mt_bc)) {
        $mt_fehler[] = sprintf(mt_t('EINST.FEHLER_SPEICHERN'), mt_e(mt_br_pfade()['cfg']));
    } else {
        mt_log('Bridge: Haken "Bridge betreiben" ' . ($mt_bc['bridge_ein'] ? 'gesetzt.' : 'abgenommen.'));
        $mt_meldungen[] = mt_t($mt_bc['bridge_ein'] ? 'BRIDGE.M_EIN' : 'BRIDGE.M_AUS');
        if (!$mt_bc['bridge_ein'] && mt_docker_da()) {
            list($mt_be, ) = mt_br_eigen();
            $mt_bi = $mt_be === 1 ? mt_br_ist() : null;
            if ($mt_bi !== null && $mt_bi['laeuft']) {
                list($mt_ok, $mt_ausgabe) = mt_br_anhalten();
                if ($mt_ok) { $mt_meldungen[] = $mt_ausgabe; } else { $mt_fehler[] = $mt_ausgabe; }
            }
        }
    }
    $mt_tab = 'tab-bridge';
}

/* Schritt 2: Zugang speichern und EINMAL pruefen (Entwurf 3.9). Ein leeres
 * Kennwortfeld aendert nichts; geloescht wird ueber den Haken (Regeln/05:20).
 * Das Kennwort steht nie im Protokoll, in der Meldung oder im Feld. */
if ($mt_post && isset($_POST['br_zugang'])) {
    $mt_bz = mt_br_zugang();
    $mt_bb = isset($_POST['br_benutzer']) && is_string($_POST['br_benutzer']) ? trim($_POST['br_benutzer']) : null;
    $mt_bk = isset($_POST['br_kennwort']) && is_string($_POST['br_kennwort']) ? $_POST['br_kennwort'] : '';
    if ($mt_bb === null || ($mt_bb !== '' && !mt_br_benutzer_gueltig($mt_bb))) {
        $mt_fehler[] = mt_t('BRIDGE.M_BENUTZER_FORM');
        $mt_bean[] = 'br_benutzer';   // M20 (0.9.44)
    } elseif ($mt_bk !== '' && (strlen($mt_bk) > 128 || preg_match('/[\x00-\x1F\x7F]/', $mt_bk) === 1)) {
        $mt_fehler[] = mt_t('BRIDGE.M_KENNWORT_FORM');
        $mt_bean[] = 'br_kennwort';   // M20 (0.9.44)
    } else {
        $mt_bvor = $mt_bz['benutzer'] . "\n" . $mt_bz['kennwort'];
        $mt_bz['benutzer'] = $mt_bb;
        if (!empty($_POST['br_kennwort_loeschen'])) {
            $mt_bz['kennwort'] = '';
        } elseif ($mt_bk !== '') {
            $mt_bz['kennwort'] = $mt_bk;
        }
        if ($mt_bvor !== $mt_bz['benutzer'] . "\n" . $mt_bz['kennwort']) {
            $mt_bz['geaendert'] = time();
        }
        if (!mt_br_zugang_speichern($mt_bz)) {
            $mt_fehler[] = sprintf(mt_t('EINST.FEHLER_SPEICHERN'), mt_e(mt_br_pfade()['zugang']));
            $mt_eingaben = mt_eingaben_sammeln('br_zugang', array());   // M20 (0.9.44)
        } else {
            mt_log('Bridge: Zugang gespeichert (Benutzer ' . ($mt_bz['benutzer'] !== '' ? $mt_bz['benutzer'] : '-')
                   . ', Kennwort ' . ($mt_bz['kennwort'] !== '' ? 'gesetzt' : 'leer') . ').');
            $mt_bpruefen = $mt_bz['benutzer'] !== '' && $mt_bz['kennwort'] !== '';
            if (!$mt_bpruefen) {
                $mt_meldungen[] = mt_t('BRIDGE.M_ZUGANG_UNVOLLSTAENDIG');
            }
        }
    }
    /* M20 (0.9.44, Pruefbericht; Regeln/04 X-2): beanstandet - der eingetippte Benutzername reist mit der
     * Einmalmeldung zurueck ins Feld, beide Felder werden markiert; das Kennwort reist nie mit. Bis 0.9.43 zeigte
     * das Feld nach dem 303 wieder den gespeicherten Namen. */
    if ($mt_bean) {
        $mt_eingaben = mt_eingaben_sammeln('br_zugang', $mt_bean);
    }
    $mt_tab = 'tab-bridge';
}
if ($mt_post && (isset($_POST['br_pruefen']) || !empty($mt_bpruefen))) {
    list($mt_berg, $mt_bzahl) = mt_br_testanmeldung();
    if ($mt_berg === 'ok') {
        $mt_meldungen[] = sprintf(mt_t('BRIDGE.T_OK'), (int) $mt_bzahl);
    } else {
        $mt_fehler[] = mt_t('BRIDGE.T_' . strtoupper($mt_berg));
    }
    $mt_tab = 'tab-bridge';
}

/* Schritt 3: Auswahl uebernehmen. Nur Zeilen der Typtabelle, nur erlaubte
 * Typen (mt_br_auswahl_pruefen). Laeuft die Bridge, startet der Vorgang
 * bridge_neustart (Konfiguration schreiben, Neustart, 120 s beobachten). */
if ($mt_post && isset($_POST['br_auswahl'])) {
    $mt_bwahl = isset($_POST['br_wahl']) && is_array($_POST['br_wahl']) ? $_POST['br_wahl'] : array();
    $mt_btyp = isset($_POST['br_typ']) && is_array($_POST['br_typ']) ? $_POST['br_typ'] : array();
    $mt_bzeilen = mt_br_zeilen();
    $mt_bvors = array();
    foreach ($mt_bzeilen as $mt_bz2) {
        $mt_bvors[$mt_bz2['schluessel']] = $mt_bz2['vorschlag'];
    }
    $mt_bwunsch = array();
    foreach (array_slice($mt_bwahl, 0, 2000) as $mt_bk2) {
        if (!is_string($mt_bk2)) {
            continue;
        }
        $mt_bt2 = isset($mt_btyp[$mt_bk2]) && is_string($mt_btyp[$mt_bk2]) ? $mt_btyp[$mt_bk2]
            : (isset($mt_bvors[$mt_bk2]) ? $mt_bvors[$mt_bk2] : '');
        $mt_bwunsch[$mt_bk2] = $mt_bt2;
    }
    list($mt_baw, $mt_bweg) = mt_br_auswahl_pruefen($mt_bwunsch, $mt_bzeilen);
    $mt_bc = mt_br_cfg();
    $mt_bc['auswahl'] = $mt_baw;
    if (!mt_br_cfg_speichern($mt_bc)) {
        $mt_fehler[] = sprintf(mt_t('EINST.FEHLER_SPEICHERN'), mt_e(mt_br_pfade()['cfg']));
    } else {
        mt_log('Bridge: Auswahl gespeichert (' . count($mt_baw) . ' Geraete' . ($mt_bweg ? ', ' . $mt_bweg . ' verworfen' : '') . ').');
        $mt_meldungen[] = sprintf(mt_t('BRIDGE.M_AUSWAHL'), count($mt_baw));
        if ($mt_bweg) {
            $mt_fehler[] = sprintf(mt_t('BRIDGE.M_AUSWAHL_VERWORFEN'), (int) $mt_bweg);
        }
        if (count($mt_baw) >= 50) {
            $mt_fehler[] = mt_t('BRIDGE.M_AUSWAHL_50');
        }
        if ($mt_bc['bridge_ein'] && mt_docker_da()) {
            list($mt_be, ) = mt_br_eigen();
            if ($mt_be === 1) {
                list($mt_ok, $mt_ausgabe) = mt_ct_vorgang_starten('bridge_neustart');
                if ($mt_ok) { $mt_meldungen[] = $mt_ausgabe; } else { $mt_fehler[] = $mt_ausgabe; }
            }
        }
    }
    $mt_tab = 'tab-bridge';
}

/* Einrichten, Neu starten (auch "Erneut versuchen" nach einer Sperre),
 * Einstellungen uebernehmen - im Hintergrund. Die Vorbedingungen
 * (Haken, Testanmeldung, Sperrmerker) prueft mt_br_vorgang() noch einmal. */
foreach (array('br_einrichten' => 'bridge_einrichten', 'br_neustart' => 'bridge_neustart',
               'br_uebernehmen' => 'bridge_uebernehmen') as $mt_bknopf => $mt_bauftrag) {
    if ($mt_post && isset($_POST[$mt_bknopf])) {
        list($mt_bfrei, $mt_bschl) = mt_br_freigabe();
        if (mt_br_cfg()['bridge_ein'] !== 1) {
            $mt_fehler[] = mt_t('BRIDGE.V_AUS');
        } elseif (!$mt_bfrei) {
            $mt_fehler[] = mt_t($mt_bschl);
        } else {
            list($mt_ok, $mt_ausgabe) = mt_ct_vorgang_starten($mt_bauftrag);
            if ($mt_ok) { $mt_meldungen[] = $mt_ausgabe; } else { $mt_fehler[] = $mt_ausgabe; }
        }
        $mt_tab = 'tab-bridge';
    }
}

if ($mt_post && isset($_POST['br_anhalten'])) {
    list($mt_ok, $mt_ausgabe) = mt_br_anhalten();
    if ($mt_ok) { $mt_meldungen[] = $mt_ausgabe; } else { $mt_fehler[] = $mt_ausgabe; }
    $mt_tab = 'tab-bridge';
}

/* Abbauen: Pflichthaken; der zweite Haken loescht auch die Kopplungsdaten. */
if ($mt_post && isset($_POST['br_abbauen'])) {
    if (empty($_POST['br_abbauen_ja'])) {
        $mt_fehler[] = mt_t('BRIDGE.M_ABBAUEN_HAKEN');
    } else {
        @set_time_limit(300);
        ignore_user_abort(true);
        list($mt_ok, $mt_ausgabe) = mt_br_abbauen(!empty($_POST['br_daten_ja']));
        if ($mt_ok) { $mt_meldungen[] = $mt_ausgabe; } else { $mt_fehler[] = $mt_ausgabe; }
    }
    $mt_tab = 'tab-bridge';
}

/* Kopplungsfenster fuer weitere Systeme (Multi-Admin, Entwurf 3.16). Der
 * Merker traegt nur das Ende, nie Codes; die Codes zeigt die Seite beim GET.
 * Nach 10 min schliesst der Waechter. */
if ($mt_post && isset($_POST['br_fenster_auf'])) {
    $mt_ba = mt_br_api('fenster_auf');
    if (!empty($mt_ba['ok'])) {
        $mt_bende = time() + mt_br_fenster_dauer();
        mt_json_schreiben(mt_br_pfade()['fenster'], array('ende' => $mt_bende), 0600);
        mt_log('Bridge: Kopplungsfenster fuer ein weiteres System geoeffnet (bis ' . date('H:i:s', $mt_bende) . ').');
        $mt_meldungen[] = sprintf(mt_t('BRIDGE.M_FENSTER_AUF'), date('H:i', $mt_bende));
    } else {
        $mt_fehler[] = sprintf(mt_t('BRIDGE.M_API_FEHL'), mt_e(isset($mt_ba['fehler']) ? (string) $mt_ba['fehler'] : '-'));
    }
    $mt_tab = 'tab-bridge';
}
if ($mt_post && isset($_POST['br_fenster_zu'])) {
    $mt_ba = mt_br_api('fenster_zu');
    @unlink(mt_br_pfade()['fenster']);
    mt_log('Bridge: Kopplungsfenster geschlossen (Knopf)' . (!empty($mt_ba['ok']) ? '.' : ', die Bridge antwortete nicht.'));
    if (!empty($mt_ba['ok'])) {
        $mt_meldungen[] = mt_t('BRIDGE.M_FENSTER_ZU');
    } else {
        $mt_fehler[] = sprintf(mt_t('BRIDGE.M_API_FEHL'), mt_e(isset($mt_ba['fehler']) ? (string) $mt_ba['fehler'] : '-'));
    }
    $mt_tab = 'tab-bridge';
}

/* Protokoll der Bridge - NUR gefiltert (mt_br_log_filter), nie roh. */
if ($mt_post && isset($_POST['br_log'])) {
    $mt_zeige = 'bridgelog';
    $mt_tab = 'tab-bridge';
}
if ($mt_post && isset($_POST['br_log_laden'])) {
    list($mt_ok, $mt_blog) = mt_br_log(2000);
    /* M19 (0.9.44, Pruefbericht; Hausform Chromecast C16): den Ausgabepuffer leeren - eine Warnung, ein BOM oder
     * eine Leerzeile aus einer eingebundenen Datei laege sonst VOR der Datei. */
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="matter2lox-bridge-' . date('Y-m-d_H-i') . '.log"');
    header('Cache-Control: no-store');
    echo $mt_blog . "\n";
    exit;
}

/* Sicherung herunterladen (Entscheidung 8: ohne die Plugin-Konfiguration
 * samt Kennwort; die Kopplungsschluessel sind darin). */
if ($mt_post && isset($_POST['br_sichern'])) {
    list($mt_fok, $mt_fwas, $mt_fzahl) = mt_br_packen();
    if ($mt_fok) {
        ignore_user_abort(true);
        $mt_fweg = $mt_fwas;
        register_shutdown_function(function () use ($mt_fweg) {
            if (is_file($mt_fweg)) {
                @unlink($mt_fweg);
            }
        });
        /* M19 (0.9.44, Pruefbericht; Hausform Chromecast C16): den Ausgabepuffer leeren - eine Warnung, ein BOM oder
         * eine Leerzeile aus einer eingebundenen Datei laege sonst VOR der Datei. */
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/x-download');
        header('Content-Disposition: attachment; filename="matter2lox-bridge-' . date('Y-m-d_H-i') . '.tar.gz"');
        header('Content-Length: ' . (int) @filesize($mt_fwas));
        header('X-Matter2Lox-Dateien: ' . (int) $mt_fzahl);
        readfile($mt_fwas);
        @unlink($mt_fwas);
        exit;
    }
    $mt_fehler[] = $mt_fwas;
    $mt_tab = 'tab-bridge';
}

/* Sicherung zurueckspielen wie die Fabric; gestartet wird danach ueber den
 * Vorgang bridge_neustart (Testanmeldung, Beobachtung), wenn die Bridge lief. */
if ($mt_post && isset($_POST['br_wh'])) {
    $mt_quelle = isset($_POST['br_wh_quelle']) && is_string($_POST['br_wh_quelle']) ? $_POST['br_wh_quelle'] : '';
    $mt_archiv = '';
    if (empty($_POST['br_wh_ja'])) {
        $mt_fehler[] = mt_t('EINST.FW_HAKEN');
    } elseif ($mt_quelle === 'upload') {
        if (!isset($_FILES['br_datei']) || !is_array($_FILES['br_datei'])
            || !isset($_FILES['br_datei']['tmp_name']) || !is_string($_FILES['br_datei']['tmp_name'])
            || !@is_uploaded_file($_FILES['br_datei']['tmp_name'])) {
            $mt_fehler[] = mt_t('EINST.FW_KEINE_DATEI');
        } elseif ((int) $_FILES['br_datei']['size'] > 64 * 1024 * 1024) {
            $mt_fehler[] = mt_t('EINST.FW_ZU_GROSS');
        } else {
            $mt_archiv = $_FILES['br_datei']['tmp_name'];
        }
    } else {
        foreach (mt_br_sicherungen() as $mt_fs) {
            if ($mt_fs['name'] === $mt_quelle) {
                $mt_archiv = $mt_fs['pfad'];
            }
        }
        if ($mt_archiv === '') {
            $mt_fehler[] = mt_t('EINST.FW_KEINE_DATEI');
        }
    }
    if ($mt_archiv !== '') {
        @set_time_limit(600);
        ignore_user_abort(true);
        list($mt_ok, $mt_ausgabe, $mt_bwar) = mt_br_wiederherstellen($mt_archiv);
        if ($mt_ok) { $mt_meldungen[] = $mt_ausgabe; } else { $mt_fehler[] = $mt_ausgabe; }
        if ($mt_ok && $mt_bwar) {
            list($mt_bfrei, $mt_bschl) = mt_br_freigabe();
            if ($mt_bfrei) {
                list($mt_ok, $mt_ausgabe) = mt_ct_vorgang_starten('bridge_neustart');
                if ($mt_ok) { $mt_meldungen[] = $mt_ausgabe; } else { $mt_fehler[] = $mt_ausgabe; }
            } else {
                $mt_fehler[] = mt_t($mt_bschl);
            }
        }
    }
    $mt_tab = 'tab-bridge';
}

/* ---------------- Jeder POST endet mit einer Umleitung (O1, PRG) ----------------
 *
 * Auch ein abgewiesener POST (Formularmerkmal falsch). Laesst sich die
 * Einmalmeldung nicht ablegen, wird wie bis 0.9.29 unmittelbar gerendert -
 * eine verschluckte Meldung waere schlimmer als ein F5-Risiko. Die
 * Einmalmeldung wird NUR beim GET gelesen (Regeln/04). */
if ($mt_post) {
    if (mt_einmal_schreiben(array('meldungen' => $mt_meldungen, 'fehler' => $mt_fehler,
                                  'zeige' => $mt_zeige, 'eingaben' => $mt_eingaben,
                                  'fabrics' => $mt_fabrics))) {
        header('Location: index.php?form=' . substr($mt_tab, 4), true, 303);
        exit;
    }
} else {
    $mt_einmal = mt_einmal_lesen();
    foreach (array('meldungen' => 'mt_meldungen', 'fehler' => 'mt_fehler') as $mt_q => $mt_z) {
        if (isset($mt_einmal[$mt_q]) && is_array($mt_einmal[$mt_q])) {
            foreach ($mt_einmal[$mt_q] as $mt_m) {
                if (is_string($mt_m)) {
                    ${$mt_z}[] = $mt_m;
                }
            }
        }
    }
    // X-2: mt_eingabe() und mt_markierung() lesen sie von hier.
    $mt_eingaben = mt_eingaben_pruefen(isset($mt_einmal['eingaben']) ? $mt_einmal['eingaben'] : null);
    // 0.9.39: die Liste der gekoppelten Systeme (nur Index, Hersteller, Bezeichnung).
    $mt_fabrics = isset($mt_einmal['fabrics']) ? mt_fabric_liste_pruefen($mt_einmal['fabrics']) : null;
    // 0.9.42: 'bridgelog' = das gefilterte Protokoll der Bridge (Reiter Bridge).
    if (isset($mt_einmal['zeige']) && in_array($mt_einmal['zeige'], array('selbsttest', 'containerlog', 'bridgelog'), true)) {
        $mt_zeige = $mt_einmal['zeige'];
    }
}
/* ---------------- Docker zuerst (0.9.35, Nr. 9) ----------------
 *
 * EIN "docker info" mit 5 s Frist, bevor irgendein anderer Docker-Aufruf
 * laeuft - und nur, wenn das Plugin den Server selbst betreibt oder der
 * Reiter Einstellungen bzw. Test offen ist. Ist Docker nicht "ok", folgt in
 * diesem Seitenaufruf kein weiterer Docker-Aufruf (mt_docker_seite()); ist er
 * "ok", bleiben alle zusammen in rund 10 s ab Seitenbeginn. Bis 0.9.34 liefen
 * je Seitenaufruf bis zu acht Docker-Aufrufe mit je 30 s Frist, in jedem
 * Reiter, und bei haengendem Docker stand die Seite minutenlang. */
$mt_cfg = mt_config();
$mt_eigener = (string) $mt_cfg['eigener_container'] === '1';
$mt_dlage = array('', '');
// 0.9.42: dazu der Reiter Bridge, sobald die Bridge je eingeschaltet wurde.
if ($mt_eigener || in_array($mt_tab, array('tab-settings', 'tab-test'), true)
    || ($mt_tab === 'tab-bridge' && is_file(mt_br_pfade()['cfg']))) {
    $mt_dbeginn = time();
    $mt_dlage = mt_docker_lage(5);
    mt_docker_seite($mt_dlage[0], max(3, 10 - (time() - $mt_dbeginn)));
}

if ($mt_zeige === 'selbsttest' && $mt_tab === 'tab-test') {
    $mt_testausgabe = mt_selbsttest_ausgabe();
} elseif ($mt_zeige === 'containerlog' && $mt_tab === 'tab-test') {
    $mt_testausgabe = mt_container_log(200);
}

/* ---------------- Laden ---------------- */
$mt_token = mt_token();
$mt_geraete = mt_geraete();
/* 0.9.39 (Entscheidung 4): offene Kopplungsfenster aus fenster.json -
 * Abgelaufenes wird dabei geloescht. Solange eines gezeigt wird, darf weder
 * der Browser noch ein Zwischenspeicher die Seite aufheben. */
$mt_fenster = mt_fenster_lesen();
if ($mt_fenster) {
    header('Cache-Control: no-store');
}
$mt_srv = mt_serverinfo();
$mt_zustand = mt_zustand();
$mt_alter = mt_alter();
$mt_pid = mt_dienst_pid();
$mt_mqtt = mt_mqtt_zustand();
/* 0.9.35 (Nr. 7 und 9): betreibt das Plugin den Server nicht selbst, heisst
 * die Kachel "fremder Server" (grau) statt "fehlt" (rot), und Docker wird
 * dafuer nicht gefragt. Sonst nur, wenn Docker "ok" ist. */
if (!$mt_eigener) {
    $mt_contzustand = 'fremd';
} elseif ($mt_dlage[0] === 'ok') {
    $mt_contzustand = mt_container_zustand();
} else {
    $mt_contzustand = $mt_dlage[0] === 'fehlt' ? 'kein_docker' : 'docker_stoerung';
}
$mt_tabelle = mt_tabelle();
$mt_einst = $mt_tab === 'tab-settings';
$mt_dok = $mt_dlage[0] === 'ok';
/* Was laeuft im Container wirklich, und wie gross ist die Fabric? Beides
 * stand bis 0.9.9 nirgends - und ohne die Abbildkennung laesst sich die
 * Wirkung des Knopfs 'Abbild neu holen' gar nicht beurteilen.
 * 0.9.35 (Nr. 9): nur im Reiter Einstellungen und nur mit Docker "ok". */
$mt_fassung = ($mt_einst && $mt_dok && $mt_eigener) ? mt_container_fassung($mt_cfg)
    : array('container' => '', 'abbild' => '', 'marke' => '');
/* E1/E2 (Welle 2): Stand des Hintergrundvorgangs (nur eine Datei lesen) und
 * die Ampel - gemessen nur, wenn der Reiter Einstellungen serverseitig offen
 * ist (die Docker-Lage von oben, container_eigen.sh, mt_erreichbar() mit
 * seinem 30-s-Zwischenspeicher). Die anderen Reiter fragen nichts neu. */
$mt_vorgang = mt_ct_vorgang();
$mt_ampel = $mt_einst ? mt_ct_ampel($mt_cfg, $mt_dlage[0] !== '' ? $mt_dlage : null) : null;
/* 0.9.35 (Nr. 5): weicht der eigene Container von der Aufrufzeile ab? Und
 * gibt es eigene Container unter anderem Namen (Nr. 5 und 7)? */
$mt_abweichung = null;
$mt_andere = array();
if ($mt_einst && $mt_dok) {
    if ($mt_eigener && $mt_ampel !== null && !empty($mt_ampel['eigen'])) {
        $mt_abweichung = mt_container_abweichung($mt_cfg);
    }
    $mt_andere = ($mt_ampel !== null && !empty($mt_ampel['andere'])) ? $mt_ampel['andere']
        : mt_container_andere($mt_cfg);
}
/* 0.9.35 (Nr. 12): die automatischen Fabric-Sicherungen (nur eine Liste). */
$mt_fsicherungen = $mt_einst ? mt_fabric_sicherungen() : array();
$mt_fabricgroesse = mt_fabric_groesse();
/* 0.9.42: Bridge (Beta). Gemessen wird nur, wenn der Reiter Bridge
 * serverseitig offen ist UND die Bridge je eingeschaltet wurde (bridge.json
 * liegt) - ab Werk also kein einziger Aufruf an Docker oder die Bridge.
 * Kopplungscodes kommen nur hier in die Seite (GET), nie in eine Meldung;
 * solange welche gezeigt werden, darf niemand die Seite aufheben. */
$mt_brc = mt_br_cfg();
$mt_brf = mt_br_pfade();
$mt_br_offen = $mt_tab === 'tab-bridge';
$mt_br_je = is_file($mt_brf['cfg']);
$mt_bra = ($mt_br_offen && $mt_br_je) ? mt_br_ampel($mt_dlage[0] !== '' ? $mt_dlage : null) : null;
$mt_brapi = ($mt_bra !== null && is_array($mt_bra['api']) && $mt_bra['api']['ok']) ? $mt_bra['api'] : null;
$mt_brfenster = mt_br_fenster();
$mt_brcodes = $mt_brapi !== null && $mt_brapi['manuell'] !== ''
    && ($mt_brapi['commissioned'] === false || ($mt_brfenster > 0 && $mt_brapi['advertising'] === true));
if ($mt_brcodes) {
    header('Cache-Control: no-store');
}
$mt_brzeilen = $mt_br_offen ? mt_br_zeilen() : array();
$mt_brz = mt_br_zugang();
$mt_brms = $mt_br_offen ? mt_br_miniserver() : null;
$mt_brsperre = mt_br_gesperrt();
$mt_brab = ($mt_bra !== null && $mt_bra['zustand'] !== 'kein' && $mt_bra['ist'] !== null) ? mt_br_abweichung($mt_bra['ist']) : null;
$mt_brlog = '';
if ($mt_zeige === 'bridgelog' && $mt_br_offen && $mt_dlage[0] === 'ok') {
    list(, $mt_brlog) = mt_br_log(300);
}
$mt_brsich = $mt_br_offen ? mt_br_sicherungen() : array();
/* 0.9.35 (Nachtrag Hauptbearbeiter): Adressen fuer Loxone ueber
 * mt_endpunkt_basis()/mt_endpunkt_host() (loxone_adresse -> IP des LoxBerry
 * -> Host-Kopf), nicht mehr ueber den Host-Kopf des Browsers. Lesende
 * Aufrufe (mt_lesende_aktionen()) tragen das Lesetoken, wenn eines gesetzt
 * ist. Die Knoepfe im Reiter Test, die der BROWSER oeffnet, gehen ueber den
 * relativen Pfad - der Browser erreicht den LoxBerry ja schon. */
$mt_host = mt_endpunkt_host($mt_cfg);
$mt_basis = mt_endpunkt_basis($mt_cfg) . '/index.php';
$mt_lesetoken = mt_lesetoken($mt_cfg);
$mt_lese = $mt_lesetoken !== '' ? $mt_lesetoken : $mt_token;
$mt_browser = '/plugins/' . $mt_p['plugin'] . '/index.php';
$mt_logzeilen = array();
if (is_file($mt_p['log'])) {
    $mt_logzeilen = array_slice(
        array_reverse(file($mt_p['log'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()),
        0, 400);
}

$mt_rahmen = class_exists('LBWeb', false);




if ($mt_rahmen) {
    /* 0.9.35 (Nr. 10): der Hilfelink fuehrt auf die Seite dieses Plugins,
     * nicht auf die Startseite des LoxBerry-Wikis. */
    LBWeb::lbheader('Matter to Loxone', 'https://github.com/timanders22/LoxBerry-Plugin-Matter2Lox#readme', 'help.html');
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
/* Ein beanstandetes Feld nach der Umleitung (X-2, Regeln/04). */
.sm-wrap input.sm-beanstandet { border: 2px solid #c62828 !important; background: #fff5f5 !important; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: Consolas, "Courier New", monospace;
    font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto;
    white-space: pre-wrap; }

/* Nachgetragene Definitionen (CSS-Luecken-Durchgang 13.08.2026):
   benutzt, aber nie definiert - wortgleich aus der Hausstandard-Vorlage
   bzw. der Referenzimplementierung uebernommen. */
.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
/* Rollbehaelter, wortgetreu aus VORLAGE_hausstandard.css.html (Regeln/04):
   die Tabelle "Erkannte Geraete" hat seit dem Verbesserungsbau (b1) mehr als
   sechs Spalten und ein Formular je Zeile. */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
/* Eigener Zusatz (Welle 2, E2): die Punkte der Ampel im Reiter
   Einstellungen, wortgleich aus MGiSmart 1.1.20. Feste Klassen, keine
   zusammengesetzte. */
.sm-ampel { width: 14px; height: 14px; border-radius: 50%; display: inline-block; }
.sm-ampel-gruen { background: #6dac20; }
.sm-ampel-rot   { background: #b00000; }
.sm-ampel-grau  { background: #9e9e9e; }
/* 0.9.35 (Nr. 7): grau fuer "fremder Server" (Kachel), weder an noch aus. */
.sm-grau { color: #757575; font-weight: 700; }
/* Welle Bild 19 (0.9.43): Seiten, Schritte, Hinweise und Bild je Baustein-Liste (Bauart Sprachsteuerung 0.12.5). */
.sm-wrap h4.sm-h4 { color: #4f7d17; font-size: 0.98em; font-weight: 700; margin: 22px 0 4px;
    padding-top: 10px; border-top: 1px solid #e0e0e0; }
.sm-schritte { margin: 6px 0 0 18px; padding: 0; }
.sm-schritte li { margin: 3px 0; }
.sm-bild { margin: 12px 0; }
.sm-bild img { max-width: 100%; height: auto; border: 1px solid #ccc; border-radius: 4px; background: #fff; }
.sm-bild figcaption { font-size: .9em; color: #555; margin-top: 4px; }
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
  <?php /* 0.9.35 (Nr. 7): bei eigener_container=0 grau "fremder Server" mit
     der Adresse, nicht rot "fehlt" - das Plugin betreibt dann keinen
     Container, und es fehlt nichts. */ ?>
  <div class="sm-kachel"><?= mt_e(mt_t('ALLG.CONTAINER')) ?>
    <b class="<?= $mt_contzustand === 'laeuft' ? 'sm-an' : ($mt_contzustand === 'fremd' ? 'sm-grau' : 'sm-aus') ?>"><?= mt_e(mt_t('ALLG.CONT_' . strtoupper($mt_contzustand))) ?></b>
    <span class="sm-hilfe"><?= $mt_contzustand === 'fremd'
        ? mt_e($mt_cfg['server_host'] . ':' . (int) $mt_cfg['server_port'])
        : mt_e(mt_container_name($mt_cfg)) ?></span>
  </div>
  <div class="sm-kachel"><?= mt_e(mt_t('ALLG.GERAETE')) ?>
    <b><?= count($mt_geraete) ?></b>
    <span class="sm-hilfe"><?= $mt_alter < 0 ? mt_e(mt_t('ALLG.NIE')) : (int) $mt_alter . ' s' ?></span>
  </div>
  <!-- Der grosse Wert ist die MQTT-Veroeffentlichung DIESES Plugins (mqtt_ein),
       der Autostart des Gateways steht klein darunter. Bis 0.9.27 stand hier
       der Autostart des Gateways; "MQTT ein" las sich, als sende das Plugin,
       auch wenn es gar nicht veroeffentlichte.
       Vorbild ZendureSolarFlow 0.9.21 und BatterieBMS 0.9.22. Ohne
       MQTT-Abschnitt in general.json heisst der Autostart "nicht feststellbar"
       statt "aus". -->
  <div class="sm-kachel">MQTT
    <b class="<?= !empty($mt_cfg['mqtt_ein']) ? 'sm-an' : 'sm-aus' ?>"><?= !empty($mt_cfg['mqtt_ein']) ? mt_e(mt_t('ALLG.EIN')) : mt_e(mt_t('ALLG.AUS')) ?></b>
    <span class="sm-hilfe"><?= mt_e(sprintf(mt_t('ALLG.KACHEL_MQTT_HILFE'),
        !$mt_mqtt['gefunden'] ? mt_t('ALLG.NICHT_FESTSTELLBAR')
        : ($mt_mqtt['autostart'] ? mt_t('ALLG.EIN') : mt_t('ALLG.AUS')))) ?></span>
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
	<a class="sm-tab<?= $mt_tab === 'tab-bridge' ? ' sm-active' : '' ?>" data-ziel="tab-bridge" href="index.php?form=bridge"><?= mt_e(mt_t('REITER.BRIDGE')) ?></a>
	<a class="sm-tab<?= $mt_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt" href="index.php?form=mqtt"><?= mt_e('MQTT') ?></a>
	<a class="sm-tab<?= $mt_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone" href="index.php?form=loxone"><?= mt_e(mt_t('REITER.LOXONE')) ?></a>
	<a class="sm-tab<?= $mt_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test" href="index.php?form=test"><?= mt_e(mt_t('REITER.TEST')) ?></a>
	<a class="sm-tab<?= $mt_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log" href="index.php?form=log"><?= mt_e(mt_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $mt_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">

<div class="sm-hinweis"><?= mt_t('EINST.WAS_IST_DAS') ?></div>

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
<?php
/* E1-E4 (Welle 2, Muster MGiSmart "Gateway einrichten"): ein Knopf
 * "Matter-Server einrichten" im Hintergrund, eine Ampel mit drei Zeilen, die
 * Einzelknoepfe unter "Fuer Fortgeschrittene". Bei eigener_container=0 gibt es
 * keinen Knopf, nur die Adresse. Die Ampelfarben sind feste Klassen. */
$mt_vakt = in_array($mt_vorgang['zustand'], array('gestartet', 'laeuft'), true);
$mt_vanz = mt_ct_vorgang_anzeige($mt_vorgang);
$mt_dhinweis = ($mt_ampel !== null && $mt_eigener) ? mt_docker_hinweis($mt_ampel['docker']) : '';
$mt_afarbe = array('gruen' => 'sm-ampel sm-ampel-gruen', 'rot' => 'sm-ampel sm-ampel-rot',
                   'grau' => 'sm-ampel sm-ampel-grau');
/* 0.9.35 (Nr. 8): kein meta refresh mehr. Laeuft ein Vorgang, fragt das
 * Skript am Seitenende index.php?vorgang=1 und laedt erst bei dessen Ende neu
 * - und nur, wenn dieser Reiter sichtbar ist und kein Feld geaendert oder
 * gerade bedient wird. */
?>
<div id="mt-vorgang" data-aktiv="<?= $mt_vakt ? '1' : '0' ?>">
<?php if ($mt_vanz !== null && $mt_vanz[0] === 'sm-hinweis') { ?>
<div class="sm-hinweis"><?= $mt_vanz[1] ?> <span id="mt-vorgang-stand"></span></div>
<?php } elseif ($mt_vanz !== null) { ?>
<div class="sm-warnung"><?= $mt_vanz[1] ?></div>
<?php } ?>
</div>
<?php if (!$mt_eigener) { ?>
<div class="sm-hinweis"><?= sprintf(mt_t('EINST.EIGENER_SERVER'), mt_e($mt_cfg['server_host'] . ':' . (int) $mt_cfg['server_port'])) ?></div>
<?php } elseif ($mt_dhinweis !== '') { ?>
<div class="sm-warnung"><?= $mt_dhinweis ?></div>
<?php } ?>
<?php if ($mt_ampel === null) { ?>
<div class="sm-hilfe"><?= mt_t('EINST.A_UNGEMESSEN') ?></div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th style="width:2.5em;"></th><th><?= mt_e(mt_t('EINST.A_FRAGE')) ?></th><th><?= mt_e(mt_t('EINST.A_BEFUND')) ?></th></tr>
<?php foreach (array('container' => 'EINST.A_CONTAINER', 'server' => 'EINST.A_SERVER', 'bruecke' => 'EINST.A_BRUECKE') as $mt_ak => $mt_ab) {
    $mt_af = isset($mt_afarbe[$mt_ampel[$mt_ak][0]]) ? $mt_afarbe[$mt_ampel[$mt_ak][0]] : $mt_afarbe['grau']; ?>
<tr><td style="text-align:center;"><i class="<?= $mt_af ?>" title="<?= mt_e($mt_ampel[$mt_ak][0]) ?>"></i></td>
    <td><?= mt_e(mt_t($mt_ab)) ?></td>
    <td><?= $mt_ampel[$mt_ak][1] ?></td></tr>
<?php } ?>
</table>
<?php } ?>
<?php /* 0.9.35 (Nr. 5): der eigene Container laeuft mit einer anderen
   Aufrufzeile als der eingestellten - gespeicherte Container-Einstellungen
   wirken erst nach dem Neuanlegen. */
if ($mt_eigener && !$mt_vakt && is_array($mt_abweichung) && $mt_abweichung) { ?>
<div class="sm-warnung"><?= sprintf(mt_t('EINST.AB_TITEL'), mt_e(mt_container_name($mt_cfg))) ?>
<ul style="margin:6px 0 6px 18px;padding:0;">
<?php foreach ($mt_abweichung as $mt_abz) { ?><li><span class="sm-mono"><?= mt_e($mt_abz) ?></span></li><?php } ?>
</ul>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="container_uebernehmen" value="1"><?= mt_e(mt_t('EINST.K_UEBERNEHMEN')) ?></button>
  </form>
</div>
<div class="sm-hilfe"><?= mt_t('EINST.H_UEBERNEHMEN') ?></div>
</div>
<?php } ?>
<?php /* 0.9.35 (Nr. 5 und 7): eigene Container unter einem anderen Namen
   (Label dieses Plugins, oder der Altbestand "matter-server"). */
if ($mt_andere && !$mt_vakt) {
    $mt_ohne_eigenen = $mt_eigener && ($mt_ampel === null || empty($mt_ampel['eigen'])); ?>
<div class="sm-warnung"><?= $mt_eigener
    ? sprintf(mt_t('EINST.ANDERE_EIGEN'), mt_e(mt_container_name($mt_cfg)))
    : mt_t('EINST.ANDERE_FREMD') ?>
<table class="sm-tbl">
<tr><th><?= mt_e(mt_t('EINST.T_NAME')) ?></th><th><?= mt_e(mt_t('EINST.T_CONTZUSTAND')) ?></th><th><?= mt_e(mt_t('EINST.L_CONTAINER_ABBILD')) ?></th><th></th></tr>
<?php foreach ($mt_andere as $mt_an) { ?>
<tr><td><span class="sm-mono"><?= mt_e($mt_an['name']) ?></span></td>
    <td><?= mt_e($mt_an['zustand']) ?></td>
    <td><span class="sm-mono"><?= mt_e($mt_an['abbild'] !== '' ? $mt_an['abbild'] : '-') ?></span></td>
    <td><div class="sm-knopfreihe" style="margin:0;">
<?php foreach (array('stop', 'start', 'entfernen') as $mt_aw) {
        if (($mt_aw === 'stop' && $mt_an['zustand'] !== 'running') || ($mt_aw === 'start' && $mt_an['zustand'] === 'running')) {
            continue;
        } ?>
      <form action="index.php" method="post">
        <?php echo mt_fmt(); ?>
        <input data-role="none" type="hidden" name="activetab" value="tab-settings">
        <input data-role="none" type="hidden" name="anderer_name" value="<?= mt_e($mt_an['name']) ?>">
        <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="container_anderer" value="<?= $mt_aw ?>"><?= mt_e(mt_t('EINST.KC_' . strtoupper($mt_aw))) ?></button>
      </form>
<?php } ?>
    </div></td></tr>
<?php } ?>
</table>
<?php if ($mt_ohne_eigenen) { ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="container_uebernehmen" value="1"><?= mt_e(mt_t('EINST.K_UEBERNEHMEN')) ?></button>
  </form>
</div>
<div class="sm-hilfe"><?= sprintf(mt_t('EINST.H_UEBERNEHMEN_ANDERE'), mt_e(mt_container_name($mt_cfg))) ?></div>
<?php } ?>
</div>
<?php } ?>
<?php if ($mt_eigener && !$mt_vakt && $mt_dhinweis === '') { ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= mt_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-hilfe"><?= mt_t('EINST.EINRICHTEN_HILFE') ?></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="mt_einrichten" value="1"><?= mt_e(mt_t('EINST.K_EINRICHTEN')) ?></button>
  </form>
  <form action="index.php" method="post">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="container_akt" value="1"><?= mt_e(mt_t('EINST.KC_AKTUALISIEREN')) ?></button>
  </form>
</div>
<div class="sm-hilfe"><?= mt_t('EINST.H_AKTUALISIEREN') ?></div>
<?php } ?>
<table class="sm-tbl">
<tr><th><?= mt_e(mt_t('ALLG.EIGENSCHAFT')) ?></th><th><?= mt_e(mt_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= mt_e(mt_t('EINST.T_CONTZUSTAND')) ?></td>
    <td class="<?= $mt_contzustand === 'laeuft' ? 'sm-an' : ($mt_contzustand === 'fremd' ? 'sm-grau' : 'sm-aus') ?>"><?= mt_e(mt_t('ALLG.CONT_' . strtoupper($mt_contzustand))) ?></td></tr>
<tr><td><?= mt_e(mt_t('EINST.T_DATENORDNER')) ?></td>
    <td><span class="sm-mono"><?= mt_e($mt_p['fabric']) ?></span>
        <?php if ($mt_fabricgroesse >= 0) { ?>(<?= (int) round($mt_fabricgroesse / 1024) ?> kB)<?php } ?></td></tr>
<?php if ($mt_eigener) { ?>
<tr><td><?= mt_e(mt_t('EINST.T_ABBILDSTAND')) ?></td>
    <td><?php if ($mt_fassung['container'] === '') { ?><?= mt_e(mt_t($mt_dok ? 'EINST.T_ABBILD_UNBEKANNT' : 'EINST.T_ABBILD_UNGEMESSEN')) ?><?php } else { ?>
        <span class="sm-mono"><?= mt_e(substr($mt_fassung['container'], 0, 19)) ?></span>
        <?php if ($mt_fassung['marke'] !== '') { ?>&mdash; <?= mt_e($mt_fassung['marke']) ?><?php } ?>
        <?php if ($mt_fassung['abbild'] !== '' && $mt_fassung['abbild'] !== $mt_fassung['container']) { ?>
        <br><span class="sm-aus"><?= mt_t('EINST.T_ABBILD_NEUER') ?></span><?php } ?>
    <?php } ?></td></tr>
<?php } ?>
</table>
<div class="sm-warnung"><?= mt_t('EINST.FABRIC_WARNUNG') ?></div>

<details class="sm-step"><summary><b><?= mt_e(mt_t('EINST.H_FORTGESCHRITTEN')) ?></b></summary>
<div class="sm-hilfe"><?= mt_t('EINST.FORTGESCHRITTEN') ?></div>
<table class="sm-tbl">
<tr><th><?= mt_e(mt_t('ALLG.EIGENSCHAFT')) ?></th><th><?= mt_e(mt_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= mt_e(mt_t('EINST.T_AUFRUF')) ?></td>
    <td><span class="sm-mono">docker <?= mt_e(mt_container_befehl($mt_cfg)) ?></span></td></tr>
</table>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= mt_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
<?php /* Alle fuenf veraendern etwas: 'holen' zieht ein Abbild von mehreren
   hundert Megabyte (im Hintergrund). Bis 0.9.16 trugen einige Gruen bzw.
   Grau - und die Legende erklaerte Gruen mit "veraendert nichts".
   "Container anlegen" entfaellt seit Welle 2 (E3): das tut der Knopf
   "Matter-Server einrichten". */
foreach (array('start' => 'sm-b-aktion', 'holen' => 'sm-b-aktion',
                     'restart' => 'sm-b-aktion', 'stop' => 'sm-b-aktion', 'entfernen' => 'sm-b-aktion') as $mt_b => $mt_farbe) { ?>
  <form action="index.php" method="post">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn <?= $mt_farbe ?>" type="submit" name="container" value="<?= $mt_b ?>"><?= mt_e(mt_t('EINST.KC_' . strtoupper($mt_b))) ?></button>
  </form>
<?php } ?>
</div>
<?php /* 0.9.35 (Nr. E5): Umstieg auf matterjs-server (Beta) bzw. zurueck. */
if ($mt_eigener) {
    $mt_ist_js = mt_abbild_bauart(mt_container_abbild($mt_cfg)) === 'matterjs'; ?>
<h3 class="sm-h3"><?= mt_e(mt_t($mt_ist_js ? 'EINST.H_UMSTIEG_ZURUECK' : 'EINST.H_UMSTIEG')) ?></h3>
<div class="sm-warnung"><?= mt_t($mt_ist_js ? 'EINST.UMSTIEG_ZURUECK_ERKLAERUNG' : 'EINST.UMSTIEG_ERKLAERUNG') ?></div>
<form action="index.php" method="post">
  <?php echo mt_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-settings">
  <label style="display:flex;align-items:center;gap:6px;margin:8px 0 0;font-size:0.9em;">
    <input data-role="none" type="checkbox" name="umstieg_ja" value="1"> <?= mt_e(mt_t('EINST.L_UMSTIEG_JA')) ?></label>
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="abbild_umstieg" value="<?= $mt_ist_js ? 'python' : 'matterjs' ?>"<?= $mt_vakt ? ' disabled' : '' ?>><?= mt_e(mt_t($mt_ist_js ? 'EINST.K_UMSTIEG_ZURUECK' : 'EINST.K_UMSTIEG')) ?></button>
  </div>
</form>
<?php } ?>
</details>

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
<?php /* 0.9.35 (Nr. 6 und 12): die automatischen Sicherungen vor jedem
   Neuanlegen und das Zurueckspielen ueber die Oberflaeche. */ ?>
<details class="sm-step"><summary><b><?= mt_e(mt_t('EINST.H_FABRIC_WH')) ?></b></summary>
<div class="sm-hilfe"><?= sprintf(mt_t('EINST.FS_ERKLAERUNG'), mt_e(mt_fabric_sicherungsordner())) ?></div>
<div class="sm-warnung"><?= mt_t('EINST.FW_ERKLAERUNG') ?></div>
<form action="index.php" method="post" enctype="multipart/form-data">
  <?php echo mt_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-settings">
  <table class="sm-tbl">
  <tr><th style="width:2.5em;"></th><th><?= mt_e(mt_t('EINST.FS_T_QUELLE')) ?></th><th><?= mt_e(mt_t('EINST.FS_T_GROESSE')) ?></th></tr>
<?php foreach ($mt_fsicherungen as $mt_fi => $mt_fs) { ?>
  <tr><td style="text-align:center;"><input data-role="none" type="radio" name="fabric_wh_quelle" id="fwq<?= (int) $mt_fi ?>" value="<?= mt_e($mt_fs['name']) ?>"<?= $mt_fi === 0 ? ' checked' : '' ?>></td>
      <td><label for="fwq<?= (int) $mt_fi ?>"><span class="sm-mono"><?= mt_e($mt_fs['name']) ?></span> (<?= mt_e(date('d.m.Y H:i', $mt_fs['zeit'])) ?>)</label></td>
      <td><?= (int) round($mt_fs['groesse'] / 1024) ?> kB</td></tr>
<?php } ?>
  <tr><td style="text-align:center;"><input data-role="none" type="radio" name="fabric_wh_quelle" id="fwqup" value="upload"<?= $mt_fsicherungen ? '' : ' checked' ?>></td>
      <td><label for="fwqup"><?= mt_e(mt_t('EINST.FS_UPLOAD')) ?></label>
          <input data-role="none" type="file" name="fabric_datei" accept=".gz,.tgz,application/gzip"></td>
      <td>&mdash;</td></tr>
  </table>
<?php if (!$mt_fsicherungen) { ?>
  <div class="sm-hilfe"><?= mt_e(mt_t('EINST.FS_KEINE')) ?></div>
<?php } ?>
  <label style="display:flex;align-items:center;gap:6px;margin:8px 0;font-size:0.9em;">
    <input data-role="none" type="checkbox" name="fabric_wh_ja" value="1"> <?= mt_e(mt_t('EINST.L_FW_JA')) ?></label>
  <div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= mt_t('LEGENDE.AKTION') ?></span></div>
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="fabric_wh" value="1"><?= mt_e(mt_t('EINST.K_FW')) ?></button>
  </div>
</form>
<div class="sm-hilfe"><?= mt_t('EINST.FABRIC_ZURUECK') ?>
<span class="sm-mono">docker stop <?= mt_e(mt_container_name($mt_cfg)) ?> &amp;&amp; tar -xzf matter-fabric-....tar.gz -C <?= mt_e($mt_p['fabric']) ?> &amp;&amp; docker start <?= mt_e(mt_container_name($mt_cfg)) ?></span></div>
</details>

<form action="index.php" method="post" autocomplete="off">
  <?php echo mt_fmt(); ?>
<input data-role="none" type="hidden" name="speichern" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<?php if (mt_eingaben_aktiv('speichern')) { ?>
<div class="sm-warnung"><?= mt_e(mt_t('EINST.EINGABEN_ZURUECK')) ?></div>
<?php } ?>

<h2><?= mt_e(mt_t('EINST.H_VERBINDUNG')) ?></h2>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="eigener_container" value="1" <?= !empty(mt_eingabe('speichern', 'eigener_container', $mt_cfg['eigener_container'])) ? 'checked' : '' ?>>
    <?= mt_e(mt_t('EINST.L_EIGENER_CONTAINER')) ?>
  </label>
  <div class="sm-hilfe"><?= mt_t('EINST.H_EIGENER_CONTAINER') ?></div>
</div>
<div class="sm-feld">
  <label for="server_host"><?= mt_e(mt_t('EINST.L_SERVER_HOST')) ?></label>
  <input data-role="none" type="text" id="server_host" name="server_host" value="<?= mt_e(mt_eingabe('speichern', 'server_host', $mt_cfg['server_host'])) ?>"<?= mt_markierung('speichern', 'server_host') ?> placeholder="127.0.0.1">
</div>
<div class="sm-feld">
  <label for="server_port"><?= mt_e(mt_t('EINST.L_SERVER_PORT')) ?></label>
  <input data-role="none" type="number" id="server_port" name="server_port" value="<?= mt_e(mt_eingabe('speichern', 'server_port', (int) $mt_cfg['server_port'])) ?>"<?= mt_markierung('speichern', 'server_port') ?> min="1" max="65535">
  <div class="sm-hilfe"><?= mt_t('EINST.H_SERVER_PORT') ?></div>
</div>
<?php /* 0.9.35 (Nr. 1 und 2): nur lokal lauschen; Hauptschnittstelle. */ ?>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="server_lokal" value="1" <?= !empty(mt_eingabe('speichern', 'server_lokal', $mt_cfg['server_lokal'])) ? 'checked' : '' ?>>
    <?= mt_e(mt_t('EINST.L_SERVER_LOKAL')) ?>
  </label>
  <div class="sm-hilfe"><?= mt_t('EINST.H_SERVER_LOKAL') ?></div>
</div>
<div class="sm-feld">
  <label for="primary_interface"><?= mt_e(mt_t('EINST.L_PRIMARY_INTERFACE')) ?></label>
  <input data-role="none" type="text" id="primary_interface" name="primary_interface" value="<?= mt_e(mt_eingabe('speichern', 'primary_interface', $mt_cfg['primary_interface'])) ?>"<?= mt_markierung('speichern', 'primary_interface') ?> placeholder="eth0" maxlength="15">
  <div class="sm-hilfe"><?= mt_t('EINST.H_PRIMARY_INTERFACE') ?></div>
</div>
<div class="sm-feld">
  <label for="container_name"><?= mt_e(mt_t('EINST.L_CONTAINER_NAME')) ?></label>
  <input data-role="none" type="text" id="container_name" name="container_name" value="<?= mt_e(mt_eingabe('speichern', 'container_name', $mt_cfg['container_name'])) ?>"<?= mt_markierung('speichern', 'container_name') ?>>
  <div class="sm-hilfe"><?= mt_t('EINST.H_CONTAINER_NAME') ?></div>
</div>
<div class="sm-feld">
  <label for="container_abbild"><?= mt_e(mt_t('EINST.L_CONTAINER_ABBILD')) ?></label>
  <input data-role="none" type="text" id="container_abbild" name="container_abbild" value="<?= mt_e(mt_eingabe('speichern', 'container_abbild', $mt_cfg['container_abbild'])) ?>"<?= mt_markierung('speichern', 'container_abbild') ?>>
  <?php /* 0.9.35 (Nr. 16): Hinweis auf den Nachfolger, mit Vorbehalt. */ ?>
  <div class="sm-hilfe"><?= mt_t('EINST.H_CONTAINER_ABBILD') ?></div>
</div>
<div class="sm-feld">
  <label for="bluetooth_adapter"><?= mt_e(mt_t('EINST.L_BLUETOOTH_ADAPTER')) ?></label>
  <input data-role="none" type="number" id="bluetooth_adapter" name="bluetooth_adapter" value="<?= mt_e(mt_eingabe('speichern', 'bluetooth_adapter', (int) $mt_cfg['bluetooth_adapter'])) ?>"<?= mt_markierung('speichern', 'bluetooth_adapter') ?> min="0" max="9">
  <div class="sm-hilfe"><?= mt_t('EINST.H_BLUETOOTH_ADAPTER') ?></div>
</div>
<div class="sm-feld">
  <label for="wartezeit"><?= mt_e(mt_t('EINST.L_WARTEZEIT')) ?></label>
  <input data-role="none" type="number" id="wartezeit" name="wartezeit" value="<?= mt_e(mt_eingabe('speichern', 'wartezeit', (int) $mt_cfg['wartezeit'])) ?>"<?= mt_markierung('speichern', 'wartezeit') ?> min="0" max="60">
  <div class="sm-hilfe"><?= mt_t('EINST.H_WARTEZEIT') ?></div>
</div>
<div class="sm-feld">
  <label for="sendetakt"><?= mt_e(mt_t('EINST.L_SENDETAKT')) ?></label>
  <input data-role="none" type="number" id="sendetakt" name="sendetakt" value="<?= mt_e(mt_eingabe('speichern', 'sendetakt', (int) $mt_cfg['sendetakt'])) ?>"<?= mt_markierung('speichern', 'sendetakt') ?> min="0" max="60">
  <div class="sm-hilfe"><?= mt_t('EINST.H_SENDETAKT') ?></div>
</div>
<div class="sm-feld">
  <label for="herzschlag"><?= mt_e(mt_t('EINST.L_HERZSCHLAG')) ?></label>
  <input data-role="none" type="number" id="herzschlag" name="herzschlag" value="<?= mt_e(mt_eingabe('speichern', 'herzschlag', (int) $mt_cfg['herzschlag'])) ?>"<?= mt_markierung('speichern', 'herzschlag') ?> min="0" max="3600">
  <div class="sm-hilfe"><?= mt_t('EINST.H_HERZSCHLAG') ?></div>
</div>

<h2><?= mt_e(mt_t('EINST.H_STEUERUNG')) ?></h2>
<div class="sm-warnung"><?= mt_t('EINST.STEUERUNG_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="steuerung_ein" value="1" <?= !empty(mt_eingabe('speichern', 'steuerung_ein', $mt_cfg['steuerung_ein'])) ? 'checked' : '' ?>>
    <?= mt_e(mt_t('EINST.L_STEUERUNG_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="schloss_ein" value="1" <?= !empty(mt_eingabe('speichern', 'schloss_ein', $mt_cfg['schloss_ein'])) ? 'checked' : '' ?>>
    <?= mt_e(mt_t('EINST.L_SCHLOSS_EIN')) ?>
  </label>
  <div class="sm-hilfe"><?= mt_t('EINST.H_SCHLOSS_EIN') ?></div>
</div>
<?php /* 0.9.39 (Entscheidung 8, Regeln/04): eigener Haken, ab Werk aus. Am Geraet erprobt am
   15.10.2026 mit dem python-matter-server (Shelly 2PM Gen3, Alexa). */ ?>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="fabric_entfernen_ein" value="1" <?= !empty(mt_eingabe('speichern', 'fabric_entfernen_ein', $mt_cfg['fabric_entfernen_ein'])) ? 'checked' : '' ?>>
    <?= mt_e(mt_t('EINST.L_FABRIC_ENTFERNEN_EIN')) ?>
  </label>
  <div class="sm-hilfe"><?= mt_t('EINST.H_FABRIC_ENTFERNEN_EIN') ?></div>
</div>

<?php /* MQTT stand hier bis zu dieser Fassung. Es wohnt jetzt
         vollstaendig im Reiter MQTT - eine Sache, eine Stelle. */ ?>

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= mt_e(mt_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= mt_e(mt_t('EINST.H_ERKANNT')) ?></h2>
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
/* Matter2Lox-b1 (Verbesserungsbau 30.09.2026): "zuletzt gesehen" ist die Zeit
 * der letzten Meldung des Geraets (vom Dienst in loxone.json gefuehrt), und
 * entfernte Geraete stehen mit ihrer Nummer da - unter ihnen koennen im
 * Broker noch "-" stehen. Je Zeile ein Haekchen und ein oranger Knopf. */
$mt_lox = mt_loxone();
$mt_zuletzt = isset($mt_lox['zuletzt']) && is_array($mt_lox['zuletzt']) ? $mt_lox['zuletzt'] : array();
$mt_entfernt = mt_geraete_entfernt($mt_geraete);
$mt_leer_knopf = function ($nr) {
    return '<form action="index.php" method="post">' . mt_fmt()
        . '<input data-role="none" type="hidden" name="activetab" value="tab-settings">'
        . '<input data-role="none" type="hidden" name="geraet_leeren" value="' . (int) $nr . '">'
        . '<label style="display:flex;align-items:center;gap:6px;margin:0 0 6px;font-size:0.85em;">'
        . '<input data-role="none" type="checkbox" name="geraet_leeren_ja" value="1"> '
        . mt_e(mt_t('EINST.L_GERAET_LEEREN_JA')) . '</label>'
        . '<button data-role="none" class="sm-btn sm-b-aktion" type="submit">'
        . mt_e(mt_t('EINST.K_GERAET_LEEREN')) . '</button></form>';
};
?>
<?php if (!$mt_geraete && !$mt_entfernt) { ?>
<div class="sm-warnung"><?= mt_t('EINST.KEINE_GERAETE') ?></div>
<?php } else { ?>
<p class="sm-hilfe"><?= mt_t('EINST.GERAET_LEEREN_HILFE') ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= mt_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th>#</th><th><?= mt_e(mt_t('EINST.T_NAME')) ?></th><th><?= mt_e(mt_t('EINST.T_KNOTEN')) ?></th>
    <th><?= mt_e(mt_t('EINST.T_HERSTELLER')) ?></th><th><?= mt_e(mt_t('EINST.T_PRODUKT')) ?></th>
    <th><?= mt_e(mt_t('EINST.T_SOFTWARESTAND')) ?></th>
    <th><?= mt_e(mt_t('EINST.T_TYP')) ?></th>
    <th><?= mt_e(mt_t('EINST.T_WERTE')) ?></th><th><?= mt_e(mt_t('EINST.T_ERREICHBAR')) ?></th>
    <th><?= mt_e(mt_t('EINST.T_ZULETZT')) ?></th><th><?= mt_e(mt_t('EINST.T_THEMEN')) ?></th></tr>
<?php foreach ($mt_geraete as $mt_nr => $mt_g) { ?>
<tr><td><?= mt_e($mt_nr) ?></td><td><?= mt_e($mt_feld($mt_g, 'name')) ?></td>
    <td><?= (int) $mt_feld($mt_g, 'node_id', 0) ?></td>
    <td><?= mt_e($mt_feld($mt_g, 'hersteller', '—')) ?></td><td><?= mt_e($mt_feld($mt_g, 'produkt', '—')) ?></td>
    <?php /* 0.9.42: Matter-Softwarestand (SoftwareVersionString, 0/40/10) - die
       Angabe des Geraets, nicht unbedingt die Firmware des Herstellers. */ ?>
    <td><?= is_scalar($mt_feld($mt_g, 'firmware', '—')) ? mt_e($mt_feld($mt_g, 'firmware', '—')) : '&mdash;' ?></td>
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
    <td class="<?= $mt_feld($mt_g, 'erreichbar', 0) ? 'sm-an' : 'sm-aus' ?>"><?= $mt_feld($mt_g, 'erreichbar', 0) ? mt_e(mt_t('ALLG.JA')) : mt_e(mt_t('ALLG.NEIN')) ?></td>
    <td><?= mt_e(mt_zuletzt_text($mt_feld($mt_g, 'zuletzt', 0))) ?></td>
    <td><?= $mt_leer_knopf($mt_nr) ?></td></tr>
<?php } ?>
<?php foreach ($mt_entfernt as $mt_enr => $mt_eknoten) { ?>
<tr><td><?= (int) $mt_enr ?></td><td><i><?= mt_e(mt_t('EINST.GERAET_ENTFERNT')) ?></i></td>
    <td><?= (int) $mt_eknoten ?></td>
    <td>&mdash;</td><td>&mdash;</td><td>&mdash;</td><td>&mdash;</td><td>&mdash;</td><td>&mdash;</td>
    <td><?= mt_e(mt_zuletzt_text(isset($mt_zuletzt[(string) $mt_eknoten]) ? $mt_zuletzt[(string) $mt_eknoten] : 0)) ?></td>
    <td><?= $mt_leer_knopf($mt_enr) ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>

<h2><?= mt_t('EINST.H_SICHERUNG') ?></h2>
<div class="sm-hinweis"><?= mt_t('EINST.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= mt_t('EINST.SICH_WARNUNG') ?></div>
<?php /* X-3 (Verbesserungsbau 30.09.2026): dieselbe Pruefung wie das
   Zurueckspielen (mt_sicherung_altwerte()); nur Namen, nie Werte. */
$mt_sich_alt = mt_sicherung_altwerte($mt_cfg);
if ($mt_sich_alt) { ?>
<div class="sm-warnung"><?= sprintf(mt_t('EINST.SICH_ALTWERT'), mt_e(implode(', ', $mt_sich_alt))) ?></div>
<?php } ?>
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

<?php
/* 0.9.35 (Nr. 4): das Feld "Geraet" als Auswahlliste mit Nummer und Name aus
 * mt_geraete(); ohne bekannte Geraete ein Zahlenfeld wie bisher. Der Wert
 * bleibt nach der Umleitung stehen (mt_eingabe()). */
$mt_geraet_feld = function ($id, $formular) use ($mt_geraete) {
    $wahl = (string) mt_eingabe($formular, 'test_geraet', '');
    if (!$mt_geraete) {
        return '<input data-role="none" type="number" id="' . mt_e($id) . '" name="test_geraet" value="'
             . mt_e($wahl !== '' ? $wahl : '1') . '" min="1" max="999">';
    }
    $h = '<select data-role="none" id="' . mt_e($id) . '" name="test_geraet">';
    foreach ($mt_geraete as $nr => $g) {
        $name = isset($g['name']) && is_scalar($g['name']) && (string) $g['name'] !== '' ? (string) $g['name'] : '?';
        $h .= '<option value="' . mt_e($nr) . '"' . ((string) $nr === $wahl ? ' selected' : '') . '>'
            . mt_e($nr . ' - ' . $name) . '</option>';
    }
    return $h . '</select>';
};
?>
<!-- ================= Reiter: Geraete anlernen ================= -->
<div class="sm-seite<?= $mt_tab === 'tab-commission' ? ' sm-active' : '' ?>" id="tab-commission">
<h2><?= mt_e(mt_t('ANLERN.H_TITEL')) ?></h2>
<p><?= mt_t('ANLERN.EINLEITUNG') ?></p>
<?php if (empty($mt_cfg['steuerung_ein'])) { ?>
<div class="sm-warnung"><?= mt_t('ANLERN.STEUERUNG_GESPERRT') ?></div>
<?php } ?>
<?php
/* 0.9.39 (Entscheidungen 4, 5, 11): jedes offene Kopplungsfenster oben im
 * Reiter - QR-Bild und Codes sofort sichtbar, darueber der gelbe Hinweis,
 * daneben "Fenster schliessen". Die Restzeit rechnet ein kleines Skript aus
 * data-ende (keine Abfrage); ohne JavaScript steht die Uhrzeit da. */
foreach ($mt_fenster as $mt_f) {
    $mt_fname = isset($mt_geraete[(string) $mt_f['nr']]['name']) && is_scalar($mt_geraete[(string) $mt_f['nr']]['name'])
        ? (string) $mt_geraete[(string) $mt_f['nr']]['name'] : '?';
    $mt_qrgrund = '';
    $mt_qrsvg = $mt_f['qr'] !== '' ? mt_qr_svg($mt_f['qr'], $mt_qrgrund) : '';
?>
<div class="mt-fenster" data-ende="<?= (int) $mt_f['ende'] ?>">
<h2><?= mt_e(sprintf(mt_t('ANLERN.FENSTER_TITEL'), $mt_f['nr'] . ' - ' . $mt_fname, (int) $mt_f['knoten'])) ?></h2>
<div class="sm-warnung"><?= mt_t('ANLERN.FENSTER_SICHERHEIT') ?></div>
<div class="mt-fenster-geheim">
<?php if ($mt_qrsvg !== '') { ?>
<div class="mt-qr" role="img" aria-label="<?= mt_e(mt_t('ANLERN.QR_ALT')) ?>" style="margin:10px 0;"><?= $mt_qrsvg ?></div>
<?php } else { ?>
<p><?= mt_e(mt_t('ANLERN.FENSTER_KEIN_BILD')) ?></p>
<?php } ?>
<table class="sm-tbl">
<tr><td><?= mt_e(mt_t('ANLERN.L_MANUELL')) ?></td><td><span class="sm-mono"><?= mt_e(mt_code_gegliedert($mt_f['manuell'])) ?></span></td></tr>
<?php if ($mt_f['qr'] !== '') { ?>
<tr><td><?= mt_e(mt_t('ANLERN.L_QRTEXT')) ?></td><td><span class="sm-mono"><?= mt_e($mt_f['qr']) ?></span></td></tr>
<?php } ?>
</table>
</div>
<p class="mt-fenster-zeit"><?= mt_e(sprintf(mt_t('ANLERN.FENSTER_BIS'), date('H:i:s', (int) $mt_f['ende']))) ?> <span class="mt-rest"></span></p>
<p class="mt-fenster-ab" hidden><b><?= mt_e(mt_t('ANLERN.FENSTER_ABGELAUFEN')) ?></b></p>
<?php if (!empty($mt_f['wiederverwendet'])) { ?>
<div class="sm-hinweis"><?= mt_e(mt_t('ANLERN.FENSTER_WIEDER')) ?></div>
<?php } ?>
<form action="index.php" method="post">
  <?php echo mt_fmt(); ?>
<input data-role="none" type="hidden" name="activetab" value="tab-commission">
<input data-role="none" type="hidden" name="test_geraet" value="<?= (int) $mt_f['nr'] ?>">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= mt_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= mt_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="fenster_zu"><?= mt_e(mt_t('ANLERN.K_FENSTER_ZU')) ?></button>
</div>
<div class="sm-hilfe"><?= mt_t('ANLERN.H_FENSTER_ZU') ?></div>
</form>
</div>
<?php }

/* 0.9.39 (Entscheidungen 6 bis 8): die gekoppelten Systeme eines Geraets,
 * frisch gelesen beim Druck auf "Gekoppelte Systeme anzeigen". Trennen nur
 * mit dem Haken in den Einstellungen und bekannter eigener Fabric; die
 * eigene Zeile hat nie einen Knopf. */
if (is_array($mt_fabrics)) {
    $mt_fbname = isset($mt_geraete[(string) $mt_fabrics['nr']]['name']) && is_scalar($mt_geraete[(string) $mt_fabrics['nr']]['name'])
        ? (string) $mt_geraete[(string) $mt_fabrics['nr']]['name'] : '?';
    $mt_trennen = (string) $mt_cfg['fabric_entfernen_ein'] === '1' && !empty($mt_cfg['steuerung_ein'])
        && $mt_fabrics['eigen'] !== null;
?>
<h2><?= mt_e(sprintf(mt_t('ANLERN.H_FABRICS'), $mt_fabrics['nr'] . ' - ' . $mt_fbname, (int) $mt_fabrics['knoten'])) ?></h2>
<div class="sm-hinweis"><?= mt_t('ANLERN.FABRICS_ERKLAERUNG') ?></div>
<?php if ($mt_fabrics['quelle'] === 'bestand') { ?>
<div class="sm-warnung"><?= mt_e(mt_t('ANLERN.FABRICS_BESTAND')) ?></div>
<?php } ?>
<?php if ($mt_trennen) { ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= mt_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= mt_t('LEGENDE.AKTION') ?></span>
</div>
<?php } ?>
<table class="sm-tbl">
<tr><th><?= mt_e(mt_t('ANLERN.T_NR')) ?></th><th><?= mt_e(mt_t('ANLERN.T_SYSTEM')) ?></th><th><?= mt_e(mt_t('ANLERN.T_HERSTELLER_ID')) ?></th><th><?= mt_e(mt_t('ANLERN.T_BEZEICHNUNG')) ?></th><th><?= mt_e(mt_t('ANLERN.T_TRENNEN')) ?></th></tr>
<?php foreach ($mt_fabrics['liste'] as $mt_fe) { ?>
<tr><td><?= (int) $mt_fe['index'] ?></td>
    <td><?= mt_e(mt_hersteller_text($mt_fe)) ?></td>
    <td><span class="sm-mono"><?= $mt_fe['hersteller_id'] === null ? '-' : mt_e(sprintf('0x%04X', $mt_fe['hersteller_id'])) ?></span></td>
    <td><?= $mt_fe['bezeichnung'] !== '' ? mt_e($mt_fe['bezeichnung']) : '-' ?></td>
    <td><?php if (!empty($mt_fe['eigen'])) { ?><b><?= mt_e(mt_t('ANLERN.T_DIESES')) ?></b><?php } elseif ($mt_trennen) { ?>
<form action="index.php" method="post">
  <?php echo mt_fmt(); ?>
<input data-role="none" type="hidden" name="activetab" value="tab-commission">
<input data-role="none" type="hidden" name="test_geraet" value="<?= (int) $mt_fabrics['nr'] ?>">
<input data-role="none" type="hidden" name="fabric_index" value="<?= (int) $mt_fe['index'] ?>">
<label style="display:flex;align-items:center;gap:6px;margin:0 0 6px;font-size:0.9em;">
  <input data-role="none" type="checkbox" name="fabric_entfernen_ja" value="1"> <?= mt_e(mt_t('ANLERN.L_FABRIC_JA')) ?></label>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="fabric_entfernen"><?= mt_e(mt_t('ANLERN.K_FABRIC_ENTFERNEN')) ?></button>
</form>
<?php /* 0.9.41 (Hausherr 15.10.2026): Haken an, Steuerung aus - die
   Steuerung nennen, nicht den Haken. */
} elseif ((string) $mt_cfg['fabric_entfernen_ein'] === '1' && empty($mt_cfg['steuerung_ein'])) { ?><?= mt_e(mt_t('ANLERN.FABRIC_STEUERUNG_AUS')) ?>
<?php } else { ?><?= mt_e(mt_t('ANLERN.FABRIC_AUS')) ?><?php } ?></td></tr>
<?php } ?>
</table>
<?php if ($mt_trennen) { ?>
<div class="sm-hilfe"><?= mt_t('ANLERN.H_FABRIC_ENTFERNEN') ?></div>
<?php } ?>
<p class="sm-hilfe"><?= sprintf(mt_t('ANLERN.FABRICS_STAND'), mt_e(date('H:i:s', (int) $mt_fabrics['ts']))) ?></p>
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
<?php if (mt_eingaben_aktiv('netz_speichern')) { ?>
<div class="sm-warnung"><?= mt_e(mt_t('ANLERN.EINGABEN_ZURUECK')) ?></div>
<?php } ?>
<div class="sm-feld">
  <label for="wlan_ssid"><?= mt_e(mt_t('ANLERN.L_SSID')) ?></label>
  <input data-role="none" type="text" id="wlan_ssid" name="wlan_ssid" value="<?= mt_e(mt_eingabe('netz_speichern', 'wlan_ssid', $mt_cfg['wlan_ssid'])) ?>"<?= mt_markierung('netz_speichern', 'wlan_ssid') ?>>
  <div class="sm-hilfe"><?= mt_t('ANLERN.H_SSID') ?></div>
</div>
<div class="sm-feld">
  <label for="wlan_passwort"><?= mt_e(mt_t('ANLERN.L_WLANPW')) ?></label>
  <?php /* 0.9.35 (Nr. 10): autocomplete="new-password" - sonst setzt der
     Browser hier sein gespeichertes LoxBerry-Kennwort ein, und Speichern
     ueberschreibt still das WLAN-Passwort. */ ?>
  <input data-role="none" type="password" autocomplete="new-password" id="wlan_passwort" name="wlan_passwort" value=""<?= mt_markierung('netz_speichern', 'wlan_passwort') ?>
         placeholder="<?= $mt_cfg['wlan_passwort'] !== '' ? mt_e(mt_t('ANLERN.PW_GESETZT')) : mt_e(mt_t('ANLERN.PW_LEER')) ?>">
</div>
<div class="sm-feld">
  <label for="thread_dataset"><?= mt_e(mt_t('ANLERN.L_THREAD')) ?></label>
  <?php /* Nachtrag B-Nachzug 01.10.2026 (Nr. 15: das Dataset traegt den
         Netzschluessel): gefuehrt wie das WLAN-Passwort - nie im HTML, leer
         lassen behaelt den gespeicherten Wert. Bis 0.9.34 stand es im Klartext
         im Feld. */ ?>
  <input data-role="none" type="password" autocomplete="new-password" id="thread_dataset" name="thread_dataset" value=""<?= mt_markierung('netz_speichern', 'thread_dataset') ?>
         placeholder="<?= (string) $mt_cfg['thread_dataset'] !== '' ? mt_e(mt_t('ANLERN.PW_GESETZT')) : mt_e(mt_t('ANLERN.PW_LEER')) ?>">
  <div class="sm-hilfe"><?= mt_t('ANLERN.H_THREAD') ?></div>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= mt_e(mt_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
<?php /* 0.9.35 (Nr. 13): gespeicherte Netzdaten loeschen - leer lassen heisst
   beim Speichern "beibehalten", deshalb ein eigener Knopf mit Pflichthaken. */
if ((string) $mt_cfg['wlan_passwort'] !== '' || (string) $mt_cfg['thread_dataset'] !== '') { ?>
<form action="index.php" method="post">
  <?php echo mt_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-commission">
  <label style="display:flex;align-items:center;gap:6px;margin:8px 0 0;font-size:0.9em;">
    <input data-role="none" type="checkbox" name="netz_loeschen_ja" value="1"> <?= mt_e(mt_t('ANLERN.L_LOESCHEN_JA')) ?></label>
  <div class="sm-knopfreihe">
<?php if ((string) $mt_cfg['wlan_passwort'] !== '') { ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="netz_loeschen" value="wlan"><?= mt_e(mt_t('ANLERN.K_PW_LOESCHEN')) ?></button>
<?php } ?>
<?php if ((string) $mt_cfg['thread_dataset'] !== '') { ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="netz_loeschen" value="thread"><?= mt_e(mt_t('ANLERN.K_DATASET_LOESCHEN')) ?></button>
<?php } ?>
  </div>
  <div class="sm-hilfe"><?= mt_t('ANLERN.H_LOESCHEN') ?></div>
</form>
<?php } ?>
<form action="index.php" method="post" autocomplete="off">
  <?php echo mt_fmt(); ?>
<input data-role="none" type="hidden" name="br_holen" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-commission">
<?php if (mt_eingaben_aktiv('br_holen')) { ?>
<div class="sm-warnung"><?= mt_e(mt_t('ANLERN.EINGABEN_ZURUECK_BR')) ?></div>
<?php } ?>
<div class="sm-feld">
  <label for="thread_br"><?= mt_e(mt_t('ANLERN.L_BR')) ?></label>
  <input data-role="none" type="text" id="thread_br" name="thread_br"
         value="<?= mt_e(mt_eingabe('br_holen', 'thread_br', $mt_cfg['thread_br'])) ?>"<?= mt_markierung('br_holen', 'thread_br') ?> placeholder="border-router:8081">
  <div class="sm-hilfe"><?= mt_t('ANLERN.H_BR') ?></div>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= mt_e(mt_t('ANLERN.K_BR')) ?></button>
</div>
</form>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= mt_t('LEGENDE.LESEN') ?></span>
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
    <input data-role="none" type="checkbox" name="nur_netz" value="1" <?= !empty(mt_eingabe('anlernen', 'nur_netz', 0)) ? 'checked' : '' ?>>
    <?= mt_e(mt_t('ANLERN.L_NUR_NETZ')) ?>
  </label>
  <div class="sm-hilfe"><?= mt_t('ANLERN.H_NUR_NETZ') ?></div>
</div>
<?php /* 0.9.35 (Nr. 14, Vertrag): Anlernen ueber eine bekannte IP-Adresse -
   nur mit dem manuellen Ziffern-Code. */ ?>
<div class="sm-feld">
  <label for="anlern_ip"><?= mt_e(mt_t('ANLERN.L_IP')) ?></label>
  <input data-role="none" type="text" id="anlern_ip" name="anlern_ip" maxlength="45" value="<?= mt_e(mt_eingabe('anlernen', 'anlern_ip', '')) ?>" placeholder="192.168.1.50">
  <div class="sm-hilfe"><?= mt_t('ANLERN.H_IP') ?></div>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= mt_t('LEGENDE.LESEN') ?></span>
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
  <?= $mt_geraet_feld('test_geraet2', 'verwalten') ?>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= mt_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= mt_t('LEGENDE.AKTION') ?></span>
</div>
<?php /* 0.9.39 (Entscheidungen 6 und 7): liest nur - gruen, ohne Steuerungsfreigabe. */ ?>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="fabrics"><?= mt_e(mt_t('ANLERN.K_FABRICS')) ?></button>
</div>
<div class="sm-feld">
  <label for="geraetename"><?= mt_e(mt_t('ANLERN.L_NAME')) ?></label>
  <input data-role="none" type="text" id="geraetename" name="geraetename" value="<?= mt_e(mt_eingabe('verwalten', 'geraetename', '')) ?>" maxlength="32">
  <div class="sm-hilfe"><?= mt_t('ANLERN.H_NAME') ?></div>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="name"><?= mt_e(mt_t('ANLERN.K_NAME')) ?></button>
</div>
<?php /* 0.9.39 (Entscheidung 3): Dauer 3, 5, 10 oder 15 Minuten, Vorgabe 5. */
$mt_dwahl = (string) mt_eingabe('verwalten', 'fenster_dauer', '5'); ?>
<div class="sm-feld">
  <label for="fenster_dauer"><?= mt_e(mt_t('ANLERN.L_FENSTER_DAUER')) ?></label>
  <select data-role="none" id="fenster_dauer" name="fenster_dauer">
<?php foreach (mt_fenster_dauern() as $mt_dm) { ?>
    <option value="<?= (int) $mt_dm ?>"<?= (string) $mt_dm === $mt_dwahl ? ' selected' : '' ?>><?= mt_e(sprintf(mt_t('ANLERN.O_FENSTER_MIN'), $mt_dm)) ?></option>
<?php } ?>
  </select>
  <div class="sm-hilfe"><?= mt_t('ANLERN.H_FENSTER_DAUER') ?></div>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="fenster"><?= mt_e(mt_t('ANLERN.K_FENSTER')) ?></button>
</div>
<?php /* 0.9.35 (Nr. 4): Pflichthaken fuer das Entfernen (Muster geraet_leeren). */ ?>
<label style="display:flex;align-items:center;gap:6px;margin:8px 0 0;font-size:0.9em;">
  <input data-role="none" type="checkbox" name="entfernen_ja" value="1"> <?= mt_e(mt_t('ANLERN.L_ENTFERNEN_JA')) ?></label>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="entfernen"><?= mt_e(mt_t('ANLERN.K_ENTFERNEN')) ?></button>
</div>
</form>
<div class="sm-hilfe"><?= mt_t('ANLERN.VERWALTEN_HILFE') ?></div>
<h3><?= mt_e(mt_t('ANLERN.H3_FENSTER_WAS')) ?></h3>
<p class="sm-hilfe"><?= mt_t('ANLERN.FENSTER_WAS') ?></p>
<p class="sm-hilfe"><?= mt_t('ANLERN.FENSTER_WOFUER') ?></p>
<p class="sm-hilfe"><?= mt_t('ANLERN.FENSTER_FREMD') ?></p>
<?php
/* 0.9.39 (Entscheidung 12): Fensterzustand am Geraet (Cluster 60), auch ein
 * fremdes Fenster - nur fuer Geraete, fuer die der Server ihn liefert. Fehlt
 * die Angabe, fehlt die Zeile, statt "geschlossen" zu behaupten. */
$mt_fz = array();
foreach ($mt_geraete as $mt_fznr => $mt_fzg) {
    $mt_fs = isset($mt_fzg['fenster_geraet']) && is_array($mt_fzg['fenster_geraet']) ? $mt_fzg['fenster_geraet'] : null;
    if ($mt_fs === null || !isset($mt_fs['status']) || !is_int($mt_fs['status'])) {
        continue;
    }
    if ($mt_fs['status'] === 0) {
        $mt_fztext = mt_t('ANLERN.FENSTER_GERAET_ZU');
    } else {
        /* 0.9.42 (Bericht 0.9.41, offen Nr. 4): oeffnete die EIGENE Fabric das
         * Fenster (AdminFabricIndex = CurrentFabricIndex, admin_eigen vom
         * Dienst), heisst der Oeffner "Matter2Lox" - wie in der Liste der
         * gekoppelten Systeme. */
        $mt_fzvon = !empty($mt_fs['admin_eigen']) ? mt_t('ANLERN.NAME_EIGEN')
            : (isset($mt_fs['admin_name']) && is_string($mt_fs['admin_name']) && $mt_fs['admin_name'] !== ''
            ? $mt_fs['admin_name']
            : (isset($mt_fs['admin_vendor']) && is_int($mt_fs['admin_vendor'])
               ? sprintf('0x%04X', $mt_fs['admin_vendor']) : mt_t('ANLERN.FENSTER_GERAET_UNBEKANNT')));
        $mt_fztext = sprintf(mt_t('ANLERN.FENSTER_GERAET_OFFEN'), $mt_fzvon);
    }
    $mt_fz[] = array($mt_fznr . ' - ' . (isset($mt_fzg['name']) && is_scalar($mt_fzg['name']) ? (string) $mt_fzg['name'] : '?'),
                     $mt_fztext, $mt_fs['status'] !== 0);
}
if ($mt_fz) { ?>
<h3><?= mt_e(mt_t('ANLERN.H3_FENSTER_GERAET')) ?></h3>
<table class="sm-tbl">
<tr><th><?= mt_e(mt_t('ANLERN.T_GERAET')) ?></th><th><?= mt_e(mt_t('ANLERN.T_FENSTER')) ?></th></tr>
<?php foreach ($mt_fz as $mt_fzz) { ?>
<tr><td><?= mt_e($mt_fzz[0]) ?></td><td class="<?= $mt_fzz[2] ? 'sm-aus' : 'sm-an' ?>"><?= mt_e($mt_fzz[1]) ?></td></tr>
<?php } ?>
</table>
<div class="sm-hilfe"><?= mt_e(mt_t('ANLERN.FENSTER_GERAET_HINWEIS')) ?></div>
<?php } ?>
</div>

<!-- ================= Reiter: Bridge (0.9.42, Beta) ================= -->
<div class="sm-seite<?= $mt_tab === 'tab-bridge' ? ' sm-active' : '' ?>" id="tab-bridge">
<h2><?= mt_e(mt_t('BRIDGE.H_TITEL')) ?></h2>
<div class="sm-warnung"><b><?= mt_e(mt_t('BRIDGE.BETA_TITEL')) ?></b> <?= mt_t('BRIDGE.BETA_TEXT') ?></div>
<p><?= mt_t('BRIDGE.EINLEITUNG') ?></p>
<p class="sm-hilfe"><?= mt_t('BRIDGE.GRENZEN') ?></p>
<?php /* Welle Bild 19 (0.9.43, E13): die Seite "Matter2Lox Bridge" steht im Reiter Einbindung, Schritt 7. */ ?>
<p class="sm-hilfe"><?= mt_t('BRIDGE.MUSTER_VERWEIS') ?></p>
<?php if (!$mt_br_offen) { ?>
<p class="sm-hilfe"><?= mt_e(mt_t('BRIDGE.NUR_HIER')) ?></p>
<?php } else {
    $mt_brvakt = in_array($mt_vorgang['zustand'], array('gestartet', 'laeuft'), true);
    $mt_brvanz = (isset($mt_vorgang['vorgang']) && strpos((string) $mt_vorgang['vorgang'], 'bridge_') === 0)
        ? mt_ct_vorgang_anzeige($mt_vorgang) : null;
    $mt_bfarbe = array('gruen' => 'sm-ampel sm-ampel-gruen', 'rot' => 'sm-ampel sm-ampel-rot', 'grau' => 'sm-ampel sm-ampel-grau');
?>
<div id="mt-vorgang-br" data-aktiv="<?= $mt_brvakt ? '1' : '0' ?>">
<?php if ($mt_brvanz !== null && $mt_brvanz[0] === 'sm-hinweis') { ?>
<div class="sm-hinweis"><?= $mt_brvanz[1] ?> <span id="mt-vorgang-br-stand"></span></div>
<?php } elseif ($mt_brvanz !== null) { ?>
<div class="sm-warnung"><?= $mt_brvanz[1] ?></div>
<?php } elseif ($mt_brvakt) { ?>
<div class="sm-hinweis"><?= mt_e(mt_t('BRIDGE.ANDERER_VORGANG')) ?> <span id="mt-vorgang-br-stand"></span></div>
<?php } ?>
</div>

<?php if ($mt_brsperre !== null) { ?>
<div class="sm-fehler"><b><?= mt_e(mt_t('BRIDGE.SPERRE_TITEL')) ?></b><br>
<?= sprintf(mt_t('BRIDGE.SPERRE_TEXT'), mt_e($mt_brz['benutzer'] !== '' ? $mt_brz['benutzer'] : '-'),
            mt_e(date('d.m.Y H:i', (int) $mt_brsperre['ts']))) ?>
<?php if (isset($mt_brsperre['zeile']) && is_string($mt_brsperre['zeile']) && $mt_brsperre['zeile'] !== '') { ?>
<br><span class="sm-mono"><?= mt_e(mt_br_log_filter($mt_brsperre['zeile'])) ?></span>
<?php } ?>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= mt_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-bridge">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="br_neustart" value="1"<?= $mt_brvakt ? ' disabled' : '' ?>><?= mt_e(mt_t('BRIDGE.K_ERNEUT')) ?></button>
  </form>
</div>
<div class="sm-hilfe"><?= mt_t('BRIDGE.H_ERNEUT') ?></div>
</div>
<?php } ?>

<?php if ($mt_bra !== null) { ?>
<table class="sm-tbl">
<tr><th style="width:2.5em;"></th><th><?= mt_e(mt_t('EINST.A_FRAGE')) ?></th><th><?= mt_e(mt_t('EINST.A_BEFUND')) ?></th></tr>
<?php foreach (array('container' => 'BRIDGE.A_CONTAINER', 'anmeldung' => 'BRIDGE.A_ANMELDUNG', 'geraete' => 'BRIDGE.A_GERAETE', 'kopplung' => 'BRIDGE.A_KOPPLUNG') as $mt_bak => $mt_bab) {
    $mt_baf = isset($mt_bfarbe[$mt_bra[$mt_bak][0]]) ? $mt_bfarbe[$mt_bra[$mt_bak][0]] : $mt_bfarbe['grau']; ?>
<tr><td style="text-align:center;"><i class="<?= $mt_baf ?>" title="<?= mt_e($mt_bra[$mt_bak][0]) ?>"></i></td>
    <td><?= mt_e(mt_t($mt_bab)) ?></td><td><?= $mt_bra[$mt_bak][1] ?></td></tr>
<?php } ?>
</table>
<?php } ?>

<div class="sm-step"><b><?= mt_e(mt_t('BRIDGE.S1_TITEL')) ?></b><br>
<?= mt_t('BRIDGE.S1_TEXT') ?>
<form action="index.php" method="post">
  <?php echo mt_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-bridge">
  <div class="sm-feld">
    <label style="display:inline-flex;align-items:center;gap:8px;">
      <input data-role="none" type="checkbox" name="bridge_ein" value="1"<?= $mt_brc['bridge_ein'] ? ' checked' : '' ?>>
      <?= mt_e(mt_t('BRIDGE.L_EIN')) ?>
    </label>
    <div class="sm-hilfe"><?= mt_t('BRIDGE.H_EIN') ?></div>
  </div>
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="br_ein_speichern" value="1"><?= mt_e(mt_t('ALLG.SPEICHERN')) ?></button>
  </div>
</form>
<?php if ($mt_brc['bridge_ein'] && $mt_bra !== null) {
    $mt_brlaeuft = $mt_bra['zustand'] === 'laeuft'; ?>
<table class="sm-tbl">
<tr><th style="width:36px;">&nbsp;</th><th><?= mt_e(mt_t('BRIDGE.T_VORAUSSETZUNG')) ?></th></tr>
<?php foreach (mt_br_voraussetzungen($mt_dlage[0] !== '' ? $mt_dlage : null, $mt_brlaeuft) as $mt_bv) { ?>
<tr><td style="text-align:center;"><?php
    if ($mt_bv[0] === 1) { echo '<span class="sm-an">&#10004;</span>'; }
    elseif ($mt_bv[0] === 0) { echo '<span class="sm-aus">&#10008;</span>'; }
    else { echo '<span style="color:#888;">&#9679;</span>'; } ?></td><td><?= $mt_bv[1] ?></td></tr>
<?php } ?>
</table>
<?php if ($mt_bra['zustand'] === 'kein') { ?>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= mt_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-bridge">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="br_einrichten" value="1"<?= $mt_brvakt ? ' disabled' : '' ?>><?= mt_e(mt_t('BRIDGE.K_EINRICHTEN')) ?></button>
  </form>
</div>
<div class="sm-hilfe"><?= mt_t('BRIDGE.H_EINRICHTEN') ?></div>
<?php } ?>
<?php } ?>
</div>

<div class="sm-step"><b><?= mt_e(mt_t('BRIDGE.S2_TITEL')) ?></b><br>
<div class="sm-hinweis"><?= mt_t('BRIDGE.S2_BENUTZER') ?></div>
<div class="sm-warnung"><?= mt_t('BRIDGE.S2_SPERRE') ?></div>
<p class="sm-hilfe"><?= $mt_brms !== null
    ? sprintf(mt_t('BRIDGE.S2_MS'), mt_e($mt_brms['host'] . ':' . $mt_brms['port']))
    : mt_t('BRIDGE.S2_KEIN_MS') ?></p>
<form action="index.php" method="post" autocomplete="off">
  <?php echo mt_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-bridge">
<?php if (mt_eingaben_aktiv('br_zugang')) { ?>
<div class="sm-warnung"><?= mt_e(mt_t('EINST.EINGABEN_ZURUECK')) ?></div>
<?php } ?>
  <div class="sm-feld">
    <label for="br_benutzer"><?= mt_e(mt_t('BRIDGE.L_BENUTZER')) ?></label>
    <input data-role="none" type="text" id="br_benutzer" name="br_benutzer" value="<?= mt_e(mt_eingabe('br_zugang', 'br_benutzer', $mt_brz['benutzer'])) ?>"<?= mt_markierung('br_zugang', 'br_benutzer') ?> maxlength="64" placeholder="matterbridge">
  </div>
  <div class="sm-feld">
    <label for="br_kennwort"><?= mt_e(mt_t('BRIDGE.L_KENNWORT')) ?></label>
    <input data-role="none" type="password" autocomplete="new-password" id="br_kennwort" name="br_kennwort" value=""<?= mt_markierung('br_zugang', 'br_kennwort') ?>
           placeholder="<?= $mt_brz['kennwort'] !== '' ? mt_e(mt_t('ANLERN.PW_GESETZT')) : mt_e(mt_t('ANLERN.PW_LEER')) ?>">
    <label style="display:flex;align-items:center;gap:6px;margin:6px 0 0;font-size:0.85em;">
      <input data-role="none" type="checkbox" name="br_kennwort_loeschen" value="1"> <?= mt_e(mt_t('BRIDGE.L_KENNWORT_LOESCHEN')) ?></label>
  </div>
  <div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= mt_t('LEGENDE.AKTION') ?></span></div>
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="br_zugang" value="1"><?= mt_e(mt_t('BRIDGE.K_ZUGANG')) ?></button>
  </div>
</form>
<?php if ($mt_brz['benutzer'] !== '' && $mt_brz['kennwort'] !== '') { ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-bridge">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="br_pruefen" value="1"><?= mt_e(mt_t('BRIDGE.K_PRUEFEN')) ?></button>
  </form>
</div>
<div class="sm-hilfe"><?= mt_t('BRIDGE.H_PRUEFEN') ?></div>
<?php } ?>
</div>

<div class="sm-step"><b><?= mt_e(mt_t('BRIDGE.S3_TITEL')) ?></b><br>
<?= mt_t('BRIDGE.S3_TEXT') ?>
<?php if (!$mt_brzeilen) { ?>
<div class="sm-hinweis"><?= mt_e(mt_t('BRIDGE.S3_LEER')) ?></div>
<?php } else {
    $mt_brzahl = 0;
    foreach ($mt_brzeilen as $mt_bzl) {
        if (isset($mt_brc['auswahl'][$mt_bzl['schluessel']])) { $mt_brzahl++; }
    } ?>
<form action="index.php" method="post">
  <?php echo mt_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-bridge">
  <div class="sm-breit">
  <table class="sm-tbl">
  <tr><th style="width:2.5em;"></th><th><?= mt_e(mt_t('BRIDGE.T_RAUM')) ?></th><th><?= mt_e(mt_t('BRIDGE.T_NAME')) ?></th>
      <th><?= mt_e(mt_t('BRIDGE.T_LOXTYP')) ?></th><th><?= mt_e(mt_t('BRIDGE.T_MATTERTYP')) ?></th><th><?= mt_e(mt_t('BRIDGE.T_HINWEIS')) ?></th></tr>
<?php foreach ($mt_brzeilen as $mt_bi2 => $mt_bzl) {
    $mt_bgrau = $mt_bzl['vorschlag'] === '';
    $mt_bist = isset($mt_brc['auswahl'][$mt_bzl['schluessel']]) ? $mt_brc['auswahl'][$mt_bzl['schluessel']] : ''; ?>
  <tr<?= $mt_bgrau ? ' style="color:#888;"' : '' ?>>
    <td style="text-align:center;"><input data-role="none" type="checkbox" name="br_wahl[]" id="brw<?= (int) $mt_bi2 ?>" value="<?= mt_e($mt_bzl['schluessel']) ?>"<?= $mt_bist !== '' ? ' checked' : '' ?><?= $mt_bgrau ? ' disabled' : '' ?>></td>
    <td><?= mt_e($mt_bzl['raum'] !== '' ? $mt_bzl['raum'] : '-') ?></td>
    <td><label for="brw<?= (int) $mt_bi2 ?>"><?= mt_e($mt_bzl['name']) ?></label></td>
    <td><span class="sm-mono"><?= mt_e($mt_bzl['loxone_typ']) ?></span></td>
    <td><?php if ($mt_bgrau) { ?>&mdash;<?php } elseif (count($mt_bzl['wahl']) === 1) { ?><span class="sm-mono"><?= mt_e($mt_bzl['wahl'][0]) ?></span><?php } else { ?>
      <select data-role="none" name="br_typ[<?= mt_e($mt_bzl['schluessel']) ?>]">
<?php foreach ($mt_bzl['wahl'] as $mt_bw) { ?>
        <option value="<?= mt_e($mt_bw) ?>"<?= ($mt_bist !== '' ? $mt_bist : $mt_bzl['vorschlag']) === $mt_bw ? ' selected' : '' ?>><?= mt_e($mt_bw) ?></option>
<?php } ?>
      </select><?php } ?></td>
    <td><?= $mt_bgrau ? mt_e(mt_t($mt_bzl['grund'])) : ($mt_bzl['hinweis'] !== '' ? mt_e(mt_t($mt_bzl['hinweis'])) : '') ?></td></tr>
<?php } ?>
  </table>
  </div>
  <p class="<?= $mt_brzahl >= 50 ? 'sm-aus' : 'sm-hilfe' ?>"><?= mt_e(sprintf(mt_t($mt_brzahl >= 50 ? 'BRIDGE.ZAHL_50' : 'BRIDGE.ZAHL'), $mt_brzahl)) ?></p>
  <div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= mt_t('LEGENDE.AKTION') ?></span></div>
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="br_auswahl" value="1"<?= $mt_brvakt ? ' disabled' : '' ?>><?= mt_e(mt_t('BRIDGE.K_AUSWAHL')) ?></button>
  </div>
</form>
<?php } ?>
<div class="sm-hilfe"><?= mt_t('BRIDGE.S3_FEHLT') ?></div>
</div>

<div class="sm-step"><b><?= mt_e(mt_t('BRIDGE.S4_TITEL')) ?></b><br>
<p class="sm-hilfe"><?= mt_e(sprintf(mt_t('BRIDGE.KENNUNGEN'), mt_br_matter_port())) ?></p>
<?php if ($mt_brapi === null) { ?>
<div class="sm-hinweis"><?= mt_e(mt_t('BRIDGE.S4_OHNE')) ?></div>
<?php } else {
    if ($mt_brcodes) {
        $mt_bqrgrund = '';
        $mt_bqrsvg = $mt_brapi['qr'] !== '' ? mt_qr_svg($mt_brapi['qr'], $mt_bqrgrund) : ''; ?>
<div class="mt-fenster"<?= $mt_brfenster > 0 && $mt_brapi['commissioned'] !== false ? ' data-ende="' . (int) $mt_brfenster . '"' : '' ?>>
<div class="sm-warnung"><?= mt_t('BRIDGE.CODE_SICHERHEIT') ?></div>
<div class="mt-fenster-geheim">
<?php if ($mt_bqrsvg !== '') { ?>
<div class="mt-qr" role="img" aria-label="<?= mt_e(mt_t('ANLERN.QR_ALT')) ?>" style="margin:10px 0;"><?= $mt_bqrsvg ?></div>
<?php } else { ?>
<p><?= mt_e(mt_t('ANLERN.FENSTER_KEIN_BILD')) ?></p>
<?php } ?>
<table class="sm-tbl">
<tr><td><?= mt_e(mt_t('ANLERN.L_MANUELL')) ?></td><td><span class="sm-mono"><?= mt_e(mt_code_gegliedert($mt_brapi['manuell'])) ?></span></td></tr>
</table>
</div>
<?php if ($mt_brfenster > 0 && $mt_brapi['commissioned'] !== false) { ?>
<p class="mt-fenster-zeit"><?= mt_e(sprintf(mt_t('ANLERN.FENSTER_BIS'), date('H:i:s', $mt_brfenster))) ?> <span class="mt-rest"></span></p>
<p class="mt-fenster-ab" hidden><b><?= mt_e(mt_t('ANLERN.FENSTER_ABGELAUFEN')) ?></b></p>
<?php } ?>
<p><?= mt_t('BRIDGE.KOPPELN_ANLEITUNG') ?></p>
</div>
<?php } elseif ($mt_brapi['commissioned'] === true) { ?>
<div class="sm-hinweis"><?= mt_e(mt_t('BRIDGE.S4_GEKOPPELT')) ?></div>
<?php } ?>
<?php if ($mt_brapi['commissioned'] === true) { ?>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= mt_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
<?php if ($mt_brfenster > 0) { ?>
  <form action="index.php" method="post">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-bridge">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="br_fenster_zu" value="1"><?= mt_e(mt_t('ANLERN.K_FENSTER_ZU')) ?></button>
  </form>
<?php } else { ?>
  <form action="index.php" method="post">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-bridge">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="br_fenster_auf" value="1"><?= mt_e(mt_t('BRIDGE.K_FENSTER_AUF')) ?></button>
  </form>
<?php } ?>
</div>
<div class="sm-hilfe"><?= mt_t('BRIDGE.H_FENSTER') ?></div>
<?php } ?>
<?php if ($mt_brapi['fabrics']) { ?>
<h3><?= mt_e(mt_t('BRIDGE.H_SYSTEME')) ?></h3>
<table class="sm-tbl">
<tr><th><?= mt_e(mt_t('ANLERN.T_NR')) ?></th><th><?= mt_e(mt_t('ANLERN.T_SYSTEM')) ?></th><th><?= mt_e(mt_t('ANLERN.T_HERSTELLER_ID')) ?></th><th><?= mt_e(mt_t('ANLERN.T_BEZEICHNUNG')) ?></th></tr>
<?php foreach ($mt_brapi['fabrics'] as $mt_bfb) { ?>
<tr><td><?= (int) $mt_bfb['index'] ?></td>
    <td><?= mt_e($mt_bfb['hersteller'] !== '' ? $mt_bfb['hersteller'] : mt_t('ANLERN.NAME_UNBEKANNT')) ?></td>
    <td><span class="sm-mono"><?= $mt_bfb['hersteller_id'] === null ? '-' : mt_e(sprintf('0x%04X', $mt_bfb['hersteller_id'])) ?></span></td>
    <td><?= $mt_bfb['bezeichnung'] !== '' ? mt_e($mt_bfb['bezeichnung']) : '-' ?></td></tr>
<?php } ?>
</table>
<div class="sm-hilfe"><?= mt_e(mt_t('BRIDGE.SYSTEME_HILFE')) ?></div>
<?php } ?>
<?php } ?>
<details class="sm-step"><summary><b><?= mt_e(mt_t('BRIDGE.H_GOOGLE')) ?></b></summary>
<?= mt_t('BRIDGE.GOOGLE_TEXT') ?>
<ol>
<li><?= mt_t('BRIDGE.GOOGLE_1') ?></li>
<li><?= mt_t('BRIDGE.GOOGLE_2') ?></li>
<li><?= mt_t('BRIDGE.GOOGLE_3') ?></li>
<li><?= mt_t('BRIDGE.GOOGLE_4') ?></li>
<li><?= mt_t('BRIDGE.GOOGLE_5') ?></li>
<li><?= mt_t('BRIDGE.GOOGLE_6') ?></li>
</ol>
<p class="sm-hilfe"><?= mt_t('BRIDGE.GOOGLE_PROJEKT') ?></p>
<p class="sm-hilfe"><?= mt_t('BRIDGE.GOOGLE_HUB') ?></p>
<p class="sm-hilfe"><?= mt_t('BRIDGE.GOOGLE_ANDROID') ?></p>
</details>
<p class="sm-hilfe"><?= mt_t('BRIDGE.ALEXA_HINWEIS') ?></p>
<p class="sm-hilfe"><?= mt_t('BRIDGE.TEILEN_HINWEIS') ?></p>
</div>

<div class="sm-step"><b><?= mt_e(mt_t('BRIDGE.S5_TITEL')) ?></b><br>
<?php if (is_array($mt_brab) && $mt_brab && !$mt_brvakt) { ?>
<div class="sm-warnung"><?= mt_e(mt_t('BRIDGE.AB_TITEL')) ?>
<ul style="margin:6px 0 6px 18px;padding:0;">
<?php foreach ($mt_brab as $mt_babz) { ?><li><span class="sm-mono"><?= mt_e($mt_babz) ?></span></li><?php } ?>
</ul>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-bridge">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="br_uebernehmen" value="1"><?= mt_e(mt_t('EINST.K_UEBERNEHMEN')) ?></button>
  </form>
</div>
<div class="sm-hilfe"><?= mt_t('BRIDGE.H_UEBERNEHMEN') ?></div>
</div>
<?php } ?>
<?php if ($mt_bra !== null && in_array($mt_bra['zustand'], array('laeuft', 'steht'), true)) { ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= mt_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= mt_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-bridge">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="br_neustart" value="1"<?= $mt_brvakt ? ' disabled' : '' ?>><?= mt_e(mt_t($mt_bra['zustand'] === 'laeuft' ? 'BRIDGE.K_NEUSTART' : 'BRIDGE.K_STARTEN')) ?></button>
  </form>
<?php if ($mt_bra['zustand'] === 'laeuft') { ?>
  <form action="index.php" method="post">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-bridge">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="br_anhalten" value="1"><?= mt_e(mt_t('BRIDGE.K_ANHALTEN')) ?></button>
  </form>
<?php } ?>
  <form action="index.php" method="post">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-bridge">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="br_log" value="1"><?= mt_e(mt_t('BRIDGE.K_LOG')) ?></button>
  </form>
  <form action="index.php" method="post">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-bridge">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="br_log_laden" value="1"><?= mt_e(mt_t('BRIDGE.K_LOG_LADEN')) ?></button>
  </form>
</div>
<div class="sm-hilfe"><?= mt_t('BRIDGE.H_LOG') ?></div>
<?php if ($mt_brlog !== '') { ?>
<div class="sm-pre"><?= mt_e($mt_brlog) ?></div>
<?php } ?>
<?php } ?>
<?php if ($mt_bra !== null && $mt_bra['zustand'] !== 'fremd' && $mt_bra['zustand'] !== 'unklar'
          && ($mt_bra['zustand'] !== 'kein' || is_dir($mt_brf['ordner']))) { ?>
<form action="index.php" method="post">
  <?php echo mt_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-bridge">
  <label style="display:flex;align-items:center;gap:6px;margin:8px 0 0;font-size:0.9em;">
    <input data-role="none" type="checkbox" name="br_abbauen_ja" value="1"> <?= mt_e(mt_t('BRIDGE.L_ABBAUEN_JA')) ?></label>
  <label style="display:flex;align-items:center;gap:6px;margin:4px 0 0;font-size:0.9em;">
    <input data-role="none" type="checkbox" name="br_daten_ja" value="1"> <?= mt_e(mt_t('BRIDGE.L_DATEN_JA')) ?></label>
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="br_abbauen" value="1"<?= $mt_brvakt ? ' disabled' : '' ?>><?= mt_e(mt_t('BRIDGE.K_ABBAUEN')) ?></button>
  </div>
</form>
<div class="sm-hilfe"><?= mt_t('BRIDGE.H_ABBAUEN') ?></div>
<?php } ?>
</div>

<?php if (is_dir($mt_brf['ordner']) || $mt_brsich) { ?>
<details class="sm-step"><summary><b><?= mt_e(mt_t('BRIDGE.H_SICHERUNG')) ?></b></summary>
<div class="sm-warnung"><?= mt_t('BRIDGE.SICHERUNG_TEXT') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= mt_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= mt_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php echo mt_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-bridge">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="br_sichern" value="1"><?= mt_e(mt_t('BRIDGE.K_SICHERN')) ?></button>
  </form>
</div>
<div class="sm-hilfe"><?= sprintf(mt_t('BRIDGE.S_AUTO'), mt_e($mt_brf['sicherungen'])) ?></div>
<form action="index.php" method="post" enctype="multipart/form-data">
  <?php echo mt_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-bridge">
  <table class="sm-tbl">
  <tr><th style="width:2.5em;"></th><th><?= mt_e(mt_t('EINST.FS_T_QUELLE')) ?></th><th><?= mt_e(mt_t('EINST.FS_T_GROESSE')) ?></th></tr>
<?php foreach ($mt_brsich as $mt_bsi => $mt_bss) { ?>
  <tr><td style="text-align:center;"><input data-role="none" type="radio" name="br_wh_quelle" id="bwq<?= (int) $mt_bsi ?>" value="<?= mt_e($mt_bss['name']) ?>"<?= $mt_bsi === 0 ? ' checked' : '' ?>></td>
      <td><label for="bwq<?= (int) $mt_bsi ?>"><span class="sm-mono"><?= mt_e($mt_bss['name']) ?></span> (<?= mt_e(date('d.m.Y H:i', $mt_bss['zeit'])) ?>)</label></td>
      <td><?= (int) round($mt_bss['groesse'] / 1024) ?> kB</td></tr>
<?php } ?>
  <tr><td style="text-align:center;"><input data-role="none" type="radio" name="br_wh_quelle" id="bwqup" value="upload"<?= $mt_brsich ? '' : ' checked' ?>></td>
      <td><label for="bwqup"><?= mt_e(mt_t('EINST.FS_UPLOAD')) ?></label>
          <input data-role="none" type="file" name="br_datei" accept=".gz,.tgz,application/gzip"></td>
      <td>&mdash;</td></tr>
  </table>
  <label style="display:flex;align-items:center;gap:6px;margin:8px 0;font-size:0.9em;">
    <input data-role="none" type="checkbox" name="br_wh_ja" value="1"> <?= mt_e(mt_t('BRIDGE.L_WH_JA')) ?></label>
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="br_wh" value="1"<?= $mt_brvakt ? ' disabled' : '' ?>><?= mt_e(mt_t('BRIDGE.K_WH')) ?></button>
  </div>
</form>
</details>
<?php } ?>

<details class="sm-step"><summary><b><?= mt_e(mt_t('EINST.H_FORTGESCHRITTEN')) ?></b></summary>
<table class="sm-tbl">
<tr><th><?= mt_e(mt_t('ALLG.EIGENSCHAFT')) ?></th><th><?= mt_e(mt_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= mt_e(mt_t('EINST.T_AUFRUF')) ?></td><td><span class="sm-mono">docker <?= mt_e(mt_br_befehl()) ?></span></td></tr>
<tr><td><?= mt_e(mt_t('BRIDGE.T_ORDNER')) ?></td><td><span class="sm-mono"><?= mt_e($mt_brf['ordner']) ?></span></td></tr>
<tr><td><?= mt_e(mt_t('BRIDGE.T_PLUGIN')) ?></td><td><span class="sm-mono">matterbridge-loxone <?= mt_e(mt_br_plugin_fassung()) ?></span></td></tr>
</table>
<p class="sm-hilfe"><?= mt_t('BRIDGE.FRONTEND_LOKAL') ?></p>
</details>
<?php } ?>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $mt_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">

<h2>MQTT</h2>
<form action="index.php" method="post">
  <?php echo mt_fmt(); ?>
<input data-role="none" type="hidden" name="save_mqtt" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<?php if (mt_eingaben_aktiv('save_mqtt')) { ?>
<div class="sm-warnung"><?= mt_e(mt_t('EINST.EINGABEN_ZURUECK')) ?></div>
<?php } ?>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="mqtt_ein" value="1" <?= !empty(mt_eingabe('save_mqtt', 'mqtt_ein', $mt_cfg['mqtt_ein'])) ? 'checked' : '' ?>>
    <?= mt_e(mt_t('EINST.L_MQTT_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="mqtt_topic"><?= mt_e(mt_t('EINST.L_MQTT_TOPIC')) ?></label>
  <input data-role="none" type="text" id="mqtt_topic" name="mqtt_topic" value="<?= mt_e(mt_eingabe('save_mqtt', 'mqtt_topic', $mt_cfg['mqtt_topic'])) ?>"<?= mt_markierung('save_mqtt', 'mqtt_topic') ?> placeholder="matter">
</div>
<div class="sm-feld">
  <label for="mqtt_nur"><?= mt_e(mt_t('EINST.L_MQTT_NUR')) ?></label>
  <input data-role="none" type="text" id="mqtt_nur" name="mqtt_nur" value="<?= mt_e(mt_eingabe('save_mqtt', 'mqtt_nur', $mt_cfg['mqtt_nur'])) ?>"<?= mt_markierung('save_mqtt', 'mqtt_nur') ?> placeholder="1,3,7">
  <div class="sm-hilfe"><?= mt_t('EINST.H_MQTT_NUR') ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="roh_ein" value="1" <?= !empty(mt_eingabe('save_mqtt', 'roh_ein', $mt_cfg['roh_ein'])) ? 'checked' : '' ?>>
    <?= mt_e(mt_t('EINST.L_ROH_EIN')) ?>
  </label>
  <div class="sm-hilfe"><?= mt_t('EINST.H_ROH_EIN') ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="tuer_haus" value="1" <?= !empty(mt_eingabe('save_mqtt', 'tuer_haus', $mt_cfg['tuer_haus'])) ? 'checked' : '' ?>>
    <?= mt_e(mt_t('EINST.L_TUER_HAUS')) ?>
  </label>
  <div class="sm-hilfe"><?= mt_t('EINST.H_TUER_HAUS') ?></div>
</div>
<?php /* Gruppe 8 / MQTT (0.9.44; Entwurf 3.9, E1-E3): die Haken der Befehlsbruecke im Formular des Reiters MQTT.
   Der zweite Haken fuer eingreifende Befehle ist der vorhandene "Schreibende Befehle zulassen" (Reiter
   Einstellungen, E2); Schloesser brauchen zusaetzlich "Schloesser schalten zulassen", Entsperren ueber MQTT den
   eigenen Haken hier (E3). Speichern startet bzw. haelt die Bruecke sofort (mt_bruecke_schalten()). */ ?>
<h3 class="sm-h3"><?= mt_e(mt_t('BEFEHL.H_TITEL')) ?></h3>
<p class="sm-hilfe"><?= mt_t('BEFEHL.H_TEXT') ?></p>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="befehle_mqtt_ein" value="1" <?= !empty(mt_eingabe('save_mqtt', 'befehle_mqtt_ein', $mt_cfg['befehle_mqtt_ein'])) ? 'checked' : '' ?>>
    <?= mt_e(mt_t('BEFEHL.L_EIN')) ?>
  </label>
  <div class="sm-hilfe"><?= sprintf(mt_t('BEFEHL.H_EIN'), '<span class="sm-mono">' . mt_e(mt_mqtt_praefix($mt_cfg) . '/befehl/#') . '</span>') ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="befehle_mqtt_entsperren" value="1" <?= !empty(mt_eingabe('save_mqtt', 'befehle_mqtt_entsperren', $mt_cfg['befehle_mqtt_entsperren'])) ? 'checked' : '' ?>>
    <?= mt_e(mt_t('BEFEHL.L_ENTSPERREN')) ?>
  </label>
  <div class="sm-hilfe"><?= mt_t('BEFEHL.H_ENTSPERREN') ?></div>
</div>
<p class="sm-hilfe"><?= mt_t('BEFEHL.ZWEITER_HAKEN') ?></p>
<div class="sm-warnung"><?= mt_t('BEFEHL.HEIMNETZ') ?></div>
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
<p class="sm-hilfe"><?= mt_t('MQTT.RETAIN_ERKLAERUNG') ?></p>
<table class="sm-tbl">
<tr><th><?= mt_e(mt_t('MQTT.T_THEMA')) ?></th><th><?= mt_e(mt_t('MQTT.T_RETAIN')) ?></th><th><?= mt_e(mt_t('MQTT.T_BEDEUTUNG')) ?></th></tr>
<?php /* M6 (Durchgang 30.09.2026): die festen Zeilen kommen aus EINER Liste
   (mt_themen_fest()), gegen die auch die Pruefzeile im Reiter Test die
   Themen des Dienstes haelt. */
foreach (mt_themen_fest() as $mt_ft => $mt_fi) { ?>
<tr><td><span class="sm-mono"><?= mt_e($mt_cfg['mqtt_topic']) ?>/<?= mt_e($mt_fi[0] . $mt_ft) ?></span></td><td><?= mt_e(mt_retain_text($mt_ft)) ?></td><td><?= mt_t($mt_fi[1]) ?></td></tr>
<?php } ?>
<?php /* Gruppe 8 (0.9.44): die Themen der Befehlsbruecke - status/befehle sendet die Bruecke selbst. */ ?>
<tr><td><span class="sm-mono"><?= mt_e($mt_cfg['mqtt_topic']) ?>/status/befehle</span></td><td><?= mt_e(mt_t('MQTT.RETAIN_NEIN')) ?></td><td><?= mt_t('MQTT.B_STATUS_BEFEHLE') ?></td></tr>
<tr><td><span class="sm-mono"><?= mt_e($mt_cfg['mqtt_topic']) ?>/befehl/&lt;Befehl&gt;/quittung</span></td><td><?= mt_e(mt_t('MQTT.RETAIN_NEIN')) ?></td><td><?= mt_t('MQTT.B_QUITTUNG') ?></td></tr>
<tr><td><span class="sm-mono"><?= mt_e($mt_cfg['mqtt_topic']) ?>/geraetN/&lt;Endpunkt&gt;/&lt;Thema&gt;</span></td><td><?= mt_e(mt_t('MQTT.RETAIN_TABELLE')) ?></td><td><?= mt_t('MQTT.B_WERT') ?></td></tr>
<tr><td><span class="sm-mono"><?= mt_e($mt_cfg['mqtt_topic']) ?>/geraetN/roh/&lt;Pfad&gt;</span></td><td><?= mt_e(mt_t('MQTT.RETAIN_TABELLE')) ?></td><td><?= mt_t('MQTT.B_ROH') ?></td></tr>
<?php /* Tuer-1: ein eigener Baum, unabhaengig vom Praefix - nur mit dem Haken
   "Tueren und Schloesser zusaetzlich unter dem Haus-Thema melden". */ ?>
<tr><td><span class="sm-mono">haus/tuer/&lt;Name&gt;/offen</span></td><td><?= mt_e(mt_t('MQTT.RETAIN_JA')) ?></td><td><?= mt_t('MQTT.B_TUER_OFFEN') ?></td></tr>
<tr><td><span class="sm-mono">haus/tuer/&lt;Name&gt;/verriegelt</span></td><td><?= mt_e(mt_t('MQTT.RETAIN_JA')) ?></td><td><?= mt_t('MQTT.B_TUER_VERRIEGELT') ?></td></tr>
</table>
<?php $mt_hausliste = mt_haus_gemerkt(); ?>
<p class="sm-hilfe"><?= mt_t('MQTT.HAUS_ERKLAERUNG') ?>
<?php if ($mt_hausliste) { ?><br><?= mt_e(mt_t('MQTT.HAUS_GEMERKT')) ?> <span class="sm-mono"><?= mt_e(implode(', ', array_keys($mt_hausliste))) ?></span><?php } ?></p>

<h2><?= mt_e(mt_t('MQTT.H_UEBERSETZUNG')) ?></h2>
<p class="sm-hilfe"><?= mt_t('MQTT.UEBERSETZUNG_ERKLAERUNG') ?></p>
<table class="sm-tbl">
<tr><th><?= mt_e(mt_t('MQTT.T_CLUSTER')) ?></th><th><?= mt_e(mt_t('MQTT.T_PFAD')) ?></th>
    <th><?= mt_e(mt_t('MQTT.T_THEMA')) ?></th><th><?= mt_e(mt_t('MQTT.T_RETAIN')) ?></th>
    <th><?= mt_e(mt_t('MQTT.T_UMRECHNUNG')) ?></th>
    <th><?= mt_e(mt_t('MQTT.T_BEDEUTUNG')) ?></th></tr>
<?php foreach ((array) $mt_tabelle['cluster'] as $mt_cl => $mt_d) {
    foreach ((array) $mt_d['attribute'] as $mt_at => $mt_a) { ?>
<tr><td><span class="sm-mono"><?= mt_e($mt_d['name']) ?></span></td>
    <td><span class="sm-mono">E/<?= mt_e($mt_cl) ?>/<?= mt_e($mt_at) ?></span></td>
    <td><span class="sm-mono"><?= mt_e($mt_a['thema']) ?></span></td>
    <td><?= mt_e(mt_retain_text($mt_a['thema'])) ?></td>
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
    <td><?= mt_e(mt_retain_text($mt_a['thema'])) ?></td>
    <td><?= mt_e(mt_t('UMR.' . strtoupper($mt_a['typ']))) ?></td>
    <td><?= mt_t($mt_a['text']) ?></td></tr>
<?php } ?>
<?php
/* Themen, die das Plugin AUSRECHNET (farbtemperatur_kelvin aus den Mireds,
 * farbton_grad aus dem Rohwert). Der Dienst veroeffentlicht sie, und bis
 * 0.9.22 standen sie in KEINER Tabelle der Oberflaeche - gemessen an der
 * gerenderten Seite, nicht am Quelltext: die Pruefzeile "Nennt die
 * Themenliste, was der Dienst wirklich sendet?" las die JSON-Datei und blieb
 * deshalb gruen. */
foreach ((array) (isset($mt_tabelle['abgeleitete_themen']['themen'])
        ? $mt_tabelle['abgeleitete_themen']['themen'] : array()) as $mt_a) { ?>
<tr><td><span class="sm-mono">ColorControl</span></td>
    <td><span class="sm-mono"><?= mt_e(mt_t('MQTT.T_AUSGERECHNET')) ?></span></td>
    <td><span class="sm-mono"><?= mt_e($mt_a['thema']) ?></span></td>
    <td><?= mt_e(mt_retain_text($mt_a['thema'])) ?></td>
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
<?php /* O10 (Durchgang 30.09.2026): die Legende steht oben im Reiter, und die
   Vorlage-Knoepfe sind grau - sie geben nur eine Datei aus. Bis 0.9.30 war
   "Ausgaenge" orange und die Vorlagen gruen, und die einzige orange Legende
   sprach vom Token (Bericht oberflaeche Nr. 14, Regeln/04). */ ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= mt_t('LEGENDE.VORLAGE') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= mt_t('LEGENDE.AKTION_TOKEN') ?></span>
</div>

<?php /* Gruppe 8 / MQTT (0.9.44; Entwurf 2.5, E9-E13): MQTT ist der Weg des LoxBerry-Plugins Musterprojekts, fuer
   Werte UND Befehle. Schritt 1 die Werte am Gateway (V1: das Abo legt der Dienst selbst an, mqtt_subscriptions.cfg
   seit 0.9.35; V2: Hakenliste mit Noncached/Resetaftersend), Schritt 2 die Namenstabelle (mt_mqtt_namen(); keine
   Importvorlage fuer Werte), Schritt 3 die Befehle der Befehlsbruecke mit der Vorlage "MQTT-Befehle" je Geraet,
   Schritt 4 die Ausfallerkennung ueber status/zaehler. Der HTTP-Weg (Token) steht zugeklappt darunter - fuer
   bestehende Anlagen und Fremdsysteme, aktiv nur mit dem Haken "HTTP-Endpunkt fuer Loxone annehmen" (endpunkt_ein,
   ab Werk aus). */
$mt_ln = mt_mqtt_namen($mt_cfg);
$mt_lh = mt_mqtt_hakenliste_muster();
$mt_lb = mt_bb_liste();
$mt_lpr = mt_mqtt_praefix($mt_cfg);
$mt_ep_an = mt_endpunkt_an($mt_cfg);
$mt_udp = (int) $mt_mqtt['udpport'];
$mt_v2text = function ($k) {
    return $k === 'N' ? mt_t('LOX.V2_N') : ($k === 'R' ? mt_t('LOX.V2_R') : '');
};
?>
<div class="sm-hinweis"><?= mt_t('LOX.S0_MQTT') ?></div>

<div class="sm-step"><b><?= mt_e(mt_t('LOX.MQ1_TITEL')) ?></b><br>
<?= mt_t('LOX.MQ1_TEXT') ?>
<p><span class="sm-mono"><?= mt_e($mt_lpr) ?>/#</span></p>
<?php if (empty($mt_cfg['mqtt_ein'])) { ?>
<div class="sm-warnung"><?= mt_t('LOX.MQ1_AUS') ?></div>
<?php } ?>
<p class="sm-hilfe"><?= mt_t('LOX.MQ1_V2') ?></p>
<div class="sm-breit">
<table class="sm-tbl" id="mt_hakenliste">
<tr><th>#</th><th><?= mt_e(mt_t('LOX.T_NAME_GATEWAY')) ?></th><th><?= mt_e(mt_t('LOX.T_V2')) ?></th></tr>
<?php foreach ($mt_lh as $mt_hi => $mt_hz) { ?>
<tr><td><?= (int) $mt_hi + 1 ?></td><td><span class="sm-mono"><?= mt_e(mt_gateway_name($mt_lpr . '/' . $mt_hz[0])) ?></span></td><td><?= mt_e($mt_hz[1] !== '' ? $mt_v2text($mt_hz[1]) : mt_t('LOX.V2_HAKEN')) ?></td></tr>
<?php } ?>
</table>
</div>
<p class="sm-hilfe"><?= mt_t('LOX.MQ1_RECONNECT') ?></p>
</div>

<div class="sm-step"><b><?= mt_e(mt_t('LOX.MQ2_TITEL')) ?></b><br>
<?= mt_t('LOX.MQ2_TEXT') ?>
<?php if (!$mt_geraete) { ?>
<div class="sm-hinweis"><?= mt_t('LOX.MQ2_KEINE_GERAETE') ?></div>
<?php } ?>
<div class="sm-breit">
<table class="sm-tbl" id="mt_namenstabelle">
<tr><th><?= mt_e(mt_t('MQTT.T_THEMA')) ?></th><th><?= mt_e(mt_t('LOX.T_NAME_GATEWAY')) ?></th><th><?= mt_e(mt_t('LOX.T_ART')) ?></th><th><?= mt_e(mt_t('MQTT.T_RETAIN')) ?></th><th><?= mt_e(mt_t('LOX.T_V2')) ?></th><th><?= mt_e(mt_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<?php foreach ($mt_ln as $mt_nz) { ?>
<tr><td><span class="sm-mono"><?= mt_e($mt_nz['voll']) ?></span></td><td><span class="sm-mono"><?= mt_e($mt_nz['name']) ?></span> <button data-role="none" type="button" class="sm-kopie" data-kopie="<?= mt_e($mt_nz['name']) ?>"><?= mt_e(mt_t('LOX.KOPIEREN')) ?></button></td><td><?= mt_e(mt_t($mt_nz['art'] === 'text' ? 'LOX.ART_TEXT' : 'LOX.ART_ZAHL')) ?></td><td><?= mt_e(mt_t($mt_nz['retained'] ? 'MQTT.RETAIN_JA' : 'MQTT.RETAIN_NEIN')) ?></td><td><?= mt_e($mt_v2text($mt_nz['v2'])) ?></td><td><?= mt_e($mt_nz['bedeutung']) ?></td></tr>
<?php } ?>
</table>
</div>
<p class="sm-hilfe"><?= mt_t('LOX.MQ2_EINGANG') ?></p>
<p class="sm-hilfe"><?= mt_t('LOX.MQ2_FEHLWERT') ?></p>
<p class="sm-hilfe"><?= mt_t('LOX.MQ2_PRAEFIX') ?></p>
<script>
(function () {
    var k = document.querySelectorAll('#mt_namenstabelle .sm-kopie');
    for (var i = 0; i < k.length; i++) {
        k[i].addEventListener('click', function () {
            var b = this, n = b.getAttribute('data-kopie');
            var fertig = function () { b.textContent = <?= json_encode(mt_t('LOX.KOPIERT')) ?>; };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(n).then(fertig);
            } else {
                var f = document.createElement('textarea');
                f.value = n; document.body.appendChild(f); f.select();
                try { document.execCommand('copy'); fertig(); } catch (e) {}
                document.body.removeChild(f);
            }
        });
    }
})();
</script>
</div>

<div class="sm-step" id="befehle_loxone"><b><?= mt_e(mt_t('LOX.MQ3_TITEL')) ?></b><br>
<?= sprintf(mt_t('LOX.MQ3_TEXT'), '<span class="sm-mono">' . mt_e($mt_lpr . '/befehl/#') . '</span>') ?>
<?php if (empty($mt_cfg['befehle_mqtt_ein'])) { ?>
<div class="sm-warnung"><?= mt_t('LOX.MQ3_HAKEN_AUS') ?></div>
<?php } ?>
<?php if (empty($mt_cfg['steuerung_ein'])) { ?>
<div class="sm-warnung"><?= mt_t('LOX.MQ3_STEUERUNG_AUS') ?></div>
<?php } ?>
<?php if ($mt_lb === null) { ?>
<div class="sm-fehler"><?= mt_t('LOX.MQ3_KEINE') ?></div>
<?php } else { ?>
<div class="sm-breit">
<table class="sm-tbl" id="mt_befehle">
<tr><th><?= mt_e(mt_t('BEFEHL.T_TITEL')) ?></th><th><?= mt_e(mt_t('BEFEHL.T_THEMA')) ?></th><th><?= mt_e(mt_t('BEFEHL.T_WERT')) ?></th><th><?= mt_e(mt_t('BEFEHL.T_EINGREIFEND')) ?></th><th><?= mt_e(mt_t('BEFEHL.T_SPERRE')) ?></th></tr>
<?php foreach ($mt_lb['befehle'] as $mt_bz) {
    if ($mt_bz['art'] === 'ganz' || $mt_bz['art'] === 'zahl') {
        $mt_bw = $mt_bz['min'] . ' … ' . $mt_bz['max'];
    } else {
        $mt_bw = $mt_bz['art'] === 'schalter' ? '0 / 1' : '1';
    } ?>
<tr><td><?= mt_e($mt_bz['titel']) ?></td><td><span class="sm-mono"><?= mt_e(str_replace(array('{geraet}', '{ep}'), array('geraet<N>', '<E>'), $mt_bz['thema'])) ?></span></td><td><span class="sm-mono"><?= mt_e($mt_bw) ?></span></td><td><?= mt_e(mt_t($mt_bz['eingreifend'] ? 'BEFEHL.T_JA_ZWEITER' : 'BEFEHL.T_NEIN')) ?></td><td><?= (int) $mt_bz['sperre_s'] ?> s</td></tr>
<?php } ?>
</table>
</div>
<?php } ?>
<p class="sm-hilfe"><?= mt_t('LOX.MQ3_ADRESSE') ?></p>
<p class="sm-hilfe"><?= mt_t('LOX.MQ3_SCHLOSS') ?></p>
<p class="sm-hilfe"><?= mt_t('LOX.MQ3_NICHTS') ?></p>
<div class="sm-warnung"><?= mt_t('BEFEHL.HEIMNETZ') ?></div>
<?php if ($mt_geraete) { ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= mt_t('LEGENDE.VORLAGE') ?></span>
</div>
<div class="sm-knopfreihe" style="margin-bottom:10px;">
<?php foreach ($mt_geraete as $mt_nr => $mt_g) { ?>
<form action="index.php" method="post">
  <?php echo mt_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <input data-role="none" type="hidden" name="vorlage" value="mqtt<?= mt_e($mt_nr) ?>">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= mt_e(mt_t('LOX.K_VORLAGE_MQTT')) ?> <?= mt_e($mt_feld($mt_g, 'name', '?')) ?></button>
</form>
<?php } ?>
</div>
<p class="sm-hilfe"><?= sprintf(mt_t('LOX.MQ3_VORLAGE'), '<span class="sm-mono">' . mt_e('/dev/udp/' . mt_udp_host($mt_cfg) . '/' . ($mt_udp > 0 ? $mt_udp : 11884)) . '</span>') ?></p>
<?php } ?>
<p class="sm-hilfe"><?= mt_t('BEFEHL.QUITTUNG') ?></p>
</div>

<div class="sm-step"><b><?= mt_e(mt_t('LOX.MQ4_TITEL')) ?></b><br>
<?= mt_t('LOX.MQ4_TEXT') ?>
<?php if (isset($mt_cfg['herzschlag']) && is_numeric($mt_cfg['herzschlag']) && (int) $mt_cfg['herzschlag'] === 0) { ?>
<div class="sm-warnung"><?= mt_t('LOX.MQ4_HERZSCHLAG_AUS') ?></div>
<?php } ?>
</div>

<details class="sm-step"><summary><b><?= mt_e(mt_t('LOX.H_HTTP')) ?></b></summary>
<p><?= mt_t('LOX.HTTP_TEXT') ?></p>
<?php /* Entscheidung 1 (E1): der ganze HTTP-Endpunkt haengt an endpunkt_ein. Dasselbe Formular traegt die Adresse
   des LoxBerry fuer die Vorlagen (0.9.35, Nr. 1) - sie gilt auch fuer die Vorlage MQTT-Befehle (Schritt 3). */ ?>
<form action="index.php" method="post" autocomplete="off">
  <?php echo mt_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <input data-role="none" type="hidden" name="save_loxone" value="1">
<?php if (mt_eingaben_aktiv('save_loxone')) { ?>
<div class="sm-warnung"><?= mt_e(mt_t('EINST.EINGABEN_ZURUECK')) ?></div>
<?php } ?>
  <div class="sm-feld">
    <label style="display:inline-flex;align-items:center;gap:8px;">
      <input data-role="none" type="checkbox" name="endpunkt_ein" value="1" <?= !empty(mt_eingabe('save_loxone', 'endpunkt_ein', $mt_cfg['endpunkt_ein'])) ? 'checked' : '' ?>>
      <?= mt_e(mt_t('LOX.L_ENDPUNKT_EIN')) ?>
    </label>
    <div class="sm-hilfe"><?= mt_t('LOX.H_ENDPUNKT_EIN') ?></div>
  </div>
  <h3 class="sm-h3"><?= mt_e(mt_t('LOX.H_ADRESSE')) ?></h3>
  <div class="sm-feld">
    <label for="loxone_adresse"><?= mt_e(mt_t('LOX.L_ADRESSE')) ?></label>
    <input data-role="none" type="text" id="loxone_adresse" name="loxone_adresse" maxlength="80"
           value="<?= mt_e(mt_eingabe('save_loxone', 'loxone_adresse', $mt_cfg['loxone_adresse'])) ?>"<?= mt_markierung('save_loxone', 'loxone_adresse') ?> placeholder="<?= mt_e(mt_t('LOX.P_ADRESSE')) ?>">
    <div class="sm-hilfe"><?= sprintf(mt_t('LOX.H_ADRESSE_FELD'), mt_e($mt_host)) ?></div>
  </div>
  <div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= mt_t('LEGENDE.AKTION') ?></span></div>
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= mt_e(mt_t('ALLG.SPEICHERN')) ?></button>
  </div>
</form>
<?php if (!$mt_ep_an) { ?>
<div class="sm-hinweis"><?= mt_t('LOX.HTTP_AUS') ?></div>
<?php } else { ?>

<div class="sm-step"><b><?= mt_e(mt_t('LOX.S3_TITEL')) ?></b><br>
<?= mt_t('LOX.S3_TEXT') ?>
<?php if (!$mt_geraete) { ?>
<div class="sm-warnung"><?= mt_t('LOX.KEINE_GERAETE') ?></div>
<?php } else { foreach ($mt_geraete as $mt_nr => $mt_g) { ?>
<p><b><?= mt_e($mt_feld($mt_g, 'name', '?')) ?></b> (<?= mt_e(mt_t('EINST.T_KNOTEN')) ?> <?= (int) $mt_feld($mt_g, 'node_id', 0) ?>)</p>
<table class="sm-tbl">
<tr><th><?= mt_e(mt_t('LOX.T_ADRESSE')) ?></th>
    <td colspan="3"><span class="sm-mono"><?= mt_e($mt_basis) ?>?token=<?= mt_e($mt_lese) ?>&amp;aktion=status&amp;geraet=<?= mt_e($mt_nr) ?></span></td></tr>
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
    <td><span class="sm-mono"><?= mt_e(mt_endpunkt_adresse('wert', $mt_nr) . '&endpunkt=' . $mt_ep . '&thema=' . $mt_thema) ?></span></td>
    <td><?= mt_e(mt_thema_text($mt_thema, $mt_tabelle)) ?></td></tr>
<?php } } ?>
</table>
<div class="sm-knopfreihe" style="margin-bottom:10px;">
<form action="index.php" method="post">
  <?php echo mt_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <input data-role="none" type="hidden" name="vorlage" value="<?= mt_e($mt_nr) ?>">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= mt_e(mt_t('LOX.K_VORLAGE')) ?> <?= mt_e($mt_feld($mt_g, 'name', '?')) ?></button>
</form>
<form action="index.php" method="post">
  <?php echo mt_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <input data-role="none" type="hidden" name="vorlage" value="aus<?= mt_e($mt_nr) ?>">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= mt_e(mt_t('LOX.K_VORLAGE_AUS')) ?> <?= mt_e($mt_feld($mt_g, 'name', '?')) ?></button>
</form>
</div>
<?php } } ?>

<h3 class="sm-h3"><?= mt_e(mt_t('LOX.H_SAMMEL')) ?></h3>
<div class="sm-hinweis"><?= mt_t('LOX.S_SAMMEL') ?></div>
<form action="index.php" method="post" style="margin-bottom:10px;">
  <?php echo mt_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <input data-role="none" type="hidden" name="vorlage" value="alle">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= mt_e(mt_t('LOX.K_VORLAGE_ALLE')) ?></button>
</form>
<div class="sm-hinweis"><?= mt_t('LOX.S3_EINZELN') ?></div>
<div class="sm-warnung"><?= mt_t('LOX.S3_STRICH') ?></div>
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
    /* 0.9.35 (Nachtrag Hauptbearbeiter, Vertrag): neue Aktionen. */
    'LOX.T_VA_LOXFARBE' => 'aktion=loxfarbe&amp;geraet=1&amp;endpunkt=1&amp;wert=&lt;v&gt;',
    'LOX.T_VA_XY'       => 'aktion=farbe_xy&amp;geraet=1&amp;endpunkt=1&amp;x=&lt;x&gt;&amp;y=&lt;y&gt;',
    'LOX.T_VA_LAMELLE'  => 'aktion=lamelle&amp;geraet=1&amp;endpunkt=1&amp;wert=&lt;v&gt;',
);
foreach ($mt_beispiele as $mt_k => $mt_q) { ?>
<tr><td><?= mt_e(mt_t($mt_k)) ?></td>
    <td><span class="sm-mono">/plugins/<?= mt_e($mt_p['plugin']) ?>/index.php?token=<?= mt_e($mt_token) ?>&amp;<?= $mt_q ?></span></td></tr>
<?php } ?>
</table>
<?= mt_t('LOX.S4_ROH') ?>
<div class="sm-warnung"><?= mt_t('LOX.S4_WARNUNG') ?></div>
<p><?= mt_t('LOX.S4_GLEICHWERT') ?></p>
<p><?= mt_t('LOX.S4_IST') ?></p>
</div>

<div class="sm-step"><b><?= mt_e(mt_t('LOX.S5_TITEL')) ?></b>
<table class="sm-tbl">
<tr><th><?= mt_e(mt_t('ALLG.EIGENSCHAFT')) ?></th><th><?= mt_e(mt_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= mt_e(mt_t('LOX.T_TOKEN')) ?></td><td><span class="sm-mono"><?= mt_e($mt_token) ?></span></td></tr>
</table>
<?= mt_t('LOX.S5_TEXT') ?>
<?php /* 0.9.35 (Nr. 4): Pflichthaken vor dem neuen Token (Muster geraet_leeren). */ ?>
<form action="index.php" method="post">
  <?php echo mt_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <label style="display:flex;align-items:center;gap:6px;margin:8px 0 0;font-size:0.9em;">
    <input data-role="none" type="checkbox" name="token_neu_ja" value="1"> <?= mt_e(mt_t('LOX.L_TOKEN_JA')) ?></label>
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= mt_e(mt_t('LOX.K_TOKEN_NEU')) ?></button>
  </div>
</form>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= mt_t('LEGENDE.AKTION_TOKEN') ?></span>
</div>

<?php /* 0.9.35 (Nr. 1, Vertrag): das Lesetoken - nur fuer lesende Aktionen. */ ?>
<h3 class="sm-h3"><?= mt_e(mt_t('LOX.H_LESETOKEN')) ?></h3>
<div class="sm-hinweis"><?= sprintf(mt_t('LOX.LESETOKEN_ERKLAERUNG'), mt_e(implode(', ', mt_lesende_aktionen()))) ?></div>
<table class="sm-tbl">
<tr><th><?= mt_e(mt_t('ALLG.EIGENSCHAFT')) ?></th><th><?= mt_e(mt_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= mt_e(mt_t('LOX.T_LESETOKEN')) ?></td><td><?= $mt_lesetoken !== ''
    ? '<span class="sm-mono">' . mt_e($mt_lesetoken) . '</span>' : mt_e(mt_t('LOX.LESETOKEN_LEER')) ?></td></tr>
</table>
<form action="index.php" method="post">
  <?php echo mt_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<?php if ($mt_lesetoken !== '') { ?>
  <label style="display:flex;align-items:center;gap:6px;margin:8px 0 0;font-size:0.9em;">
    <input data-role="none" type="checkbox" name="lesetoken_ja" value="1"> <?= mt_e(mt_t('LOX.L_LESETOKEN_JA')) ?></label>
<?php } ?>
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="lesetoken" value="neu"><?= mt_e(mt_t('LOX.K_LESETOKEN_NEU')) ?></button>
<?php if ($mt_lesetoken !== '') { ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="lesetoken" value="loeschen"><?= mt_e(mt_t('LOX.K_LESETOKEN_LOESCHEN')) ?></button>
<?php } ?>
  </div>
</form>

<?php /* Die Adresse fuer die Vorlagen steht seit 0.9.44 oben im Formular mit dem Haken des HTTP-Endpunkts. */ ?>
</div>

<div class="sm-step"><b><?= mt_e(mt_t('LOX.S6_TITEL')) ?></b><br><?= mt_t('LOX.S6_TEXT') ?></div>

<div class="sm-step"><b><?= mt_e(mt_t('LOX.S8_TITEL')) ?></b><br>
<?= mt_t('LOX.S8_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= mt_e(mt_t('LOX.T_PRUEFUNG')) ?></th><th><?= mt_e(mt_t('LOX.T_ERWARTUNG')) ?></th></tr>
<tr><td><span class="sm-mono"><?= mt_e($mt_basis) ?>?token=<?= mt_e($mt_lese) ?>&amp;aktion=liste</span></td>
    <td><span class="sm-mono">LISTE;OK=1;N=...</span></td></tr>
<tr><td><span class="sm-mono"><?= mt_e($mt_basis) ?>?aktion=liste</span></td>
    <td><span class="sm-mono">FEHLER;OK=0;GRUND=TOKEN</span> (HTTP 403)</td></tr>
<tr><td><span class="sm-mono"><?= mt_e($mt_basis) ?>?token=<?= mt_e($mt_token) ?>&amp;aktion=quatsch</span></td>
    <td><span class="sm-mono">FEHLER;OK=0;GRUND=UNBEKANNTE_AKTION</span> (HTTP 400)</td></tr>
</table>
</div>
<?php } ?>
</details>

<?php
/**
 * Die Baustein-Listen des Reiters "Einbindung in Loxone", Schritt 7 - Welle Bild 19 (0.9.43, Hausherr
 * 11.10.2026: Vollausstattung, ein absoluter Laie soll das Maximum leicht uebernehmen koennen; Entwurf Welle 19
 * mt, E1-E15, 18.10.2026). DREI Seiten wie im LoxBerry-Plugins Musterprojekt (Musterprojekt/baustein_listen.txt,
 * Abschnitte "Matter2Lox" 21 (seit 0.9.44: 18, Gruppe 8 - Werte und Befehle ueber MQTT), "Matter2Lox Geraete" 27,
 * "Matter2Lox Bridge" 3 Zeilen; beide Richtungen
 * getrennt). Bis 0.9.42 stand hier EINE Liste mit 16 Zeilen (Funktion mt_bausteine). Berichtigt gegen sie: der
 * Schwellwertschalter "Matter Werte veraltet" (900/600 auf ALTER) entfaellt - ALTER waechst in einem ruhigen
 * Haus bis etwa 1800 s (das Abbild wird nur bei Aenderung und alle 30 min geschrieben) und ist ein
 * Lebenszeichen, keine Altersgrenze: jetzt Analogwertvalidierung (E3, E5); "Beleuchtungssteuerung",
 * "Entprellung" und "Vergleicher" gab es so in Config nicht bzw. ohne Abnehmer (jetzt Dimmer mit Sts/Str,
 * nur HELLIGKEIT - ein Befehl je Aenderung statt zwei; "folgt nicht" als Statuszeile, E6, E7).
 *
 * Jede Zeile ist EIN Sprachschluessel BAUSTEIN.Z<Seite>_<Nr> "Typ | Name | Parameter | Eingaenge" (Zeilen mit
 * Prozentzeichen dort doppelt und ueber sprintf() geholt, angezeigt mit einem; deutsch wortgleich mit der Liste;
 * Bausteinnamen auch englisch deutsch). Die Namen sind die des Musterprojekts (Geraet 1 Leselampe, 2 Steckdose
 * Terrasse, 3 Klimasensor Bad, 4 Taster Flur, 5 Bewegungsmelder); die eigenen Befehlsnamen stehen in Schritt 3.
 * DIE NAMEN DER GATEWAY-EINGAENGE WERDEN GEBAUT: eine Zeile mit 'gw' traegt den Namen aus dem Praefix
 * (mt_mqtt_praefix(), Reiter Einstellungen) und dem Thema, "/" und "%" als "_" (gateway_name() im Dienst).
 * Mit dem Werkspraefix "matter" steht genau der Name der Liste da. Keine Leitung geht ueber eine Seitengrenze.
 *
 * Rueckgabe: Liste von array('seite', 'bild', 'zeilen' => array(array(Nr, Typ, Name, Parameter, Eingaenge),
 * ...), 'schritte', 'hinweise'). Zellen und Schritte sind Klartext (unten maskiert), die Hinweise
 * freigegebenes HTML aus der Sprachdatei.
 */
function mt_bausteinliste($mt_praefix)
{
    // Seitenname (= Config-Seite im Musterprojekt, deshalb fest), Bild, Zeilen, Kasten, Hinweise
    $mt_seiten = array(
        array('Matter2Lox', 'einbindung_loxone.png', array(
            array(mt_t('BAUSTEIN.Z1_01'), 'gw'),
            array(mt_t('BAUSTEIN.Z1_02'), 'gw'),
            array(mt_t('BAUSTEIN.Z1_03')),
            array(mt_t('BAUSTEIN.Z1_04')),
            array(mt_t('BAUSTEIN.Z1_05')),
            array(mt_t('BAUSTEIN.Z1_06')),
            array(mt_t('BAUSTEIN.Z1_07')),
            array(mt_t('BAUSTEIN.Z1_08')),
            array(mt_t('BAUSTEIN.Z1_09'), 'gw'),
            array(mt_t('BAUSTEIN.Z1_10'), 'gw'),
            array(mt_t('BAUSTEIN.Z1_11'), 'gw'),
            array(mt_t('BAUSTEIN.Z1_12')),
            array(mt_t('BAUSTEIN.Z1_13')),
            array(mt_t('BAUSTEIN.Z1_14')),
            array(mt_t('BAUSTEIN.Z1_15')),
            array(mt_t('BAUSTEIN.Z1_16')),
            array(mt_t('BAUSTEIN.Z1_17')),
            array(mt_t('BAUSTEIN.Z1_18')),
        ), array(
            'BAUSTEIN.SEITE1_SCHRITT1',
            'BAUSTEIN.SEITE1_SCHRITT2',
            'BAUSTEIN.SEITE1_SCHRITT3',
            'BAUSTEIN.SEITE1_SCHRITT4',
            'BAUSTEIN.SEITE1_SCHRITT5',
            'BAUSTEIN.SEITE1_SCHRITT6',
        ), array(
            'BAUSTEIN.SEITE1_H1',
            'BAUSTEIN.SEITE1_H2',
            'BAUSTEIN.SEITE1_H3',
            'BAUSTEIN.SEITE1_H4',
            'BAUSTEIN.SEITE1_H5',
            'BAUSTEIN.SEITE1_H6',
        )),
        array("Matter2Lox Ger\xc3\xa4te", 'einbindung_loxone_geraete.png', array(
            array(mt_t('BAUSTEIN.Z2_01'), 'gw'),
            array(sprintf(mt_t('BAUSTEIN.Z2_02')), 'gw'),
            array(mt_t('BAUSTEIN.Z2_03'), 'gw'),
            array(mt_t('BAUSTEIN.Z2_04'), 'gw'),
            array(sprintf(mt_t('BAUSTEIN.Z2_05'))),
            array(sprintf(mt_t('BAUSTEIN.Z2_06'))),
            array(mt_t('BAUSTEIN.Z2_07')),
            array(mt_t('BAUSTEIN.Z2_08')),
            array(mt_t('BAUSTEIN.Z2_09')),
            array(mt_t('BAUSTEIN.Z2_10')),
            array(mt_t('BAUSTEIN.Z2_11')),
            array(sprintf(mt_t('BAUSTEIN.Z2_12'))),
            array(mt_t('BAUSTEIN.Z2_13'), 'gw'),
            array(mt_t('BAUSTEIN.Z2_14'), 'gw'),
            array(mt_t('BAUSTEIN.Z2_15'), 'gw'),
            array(mt_t('BAUSTEIN.Z2_16'), 'gw'),
            array(mt_t('BAUSTEIN.Z2_17')),
            array(mt_t('BAUSTEIN.Z2_18')),
            array(mt_t('BAUSTEIN.Z2_19')),
            array(mt_t('BAUSTEIN.Z2_20'), 'gw'),
            array(sprintf(mt_t('BAUSTEIN.Z2_21')), 'gw'),
            array(sprintf(mt_t('BAUSTEIN.Z2_22')), 'gw'),
            array(sprintf(mt_t('BAUSTEIN.Z2_23'))),
            array(mt_t('BAUSTEIN.Z2_24'), 'gw'),
            array(mt_t('BAUSTEIN.Z2_25')),
            array(mt_t('BAUSTEIN.Z2_26')),
            array(mt_t('BAUSTEIN.Z2_27')),
        ), array(
            'BAUSTEIN.SEITE2_SCHRITT1',
            'BAUSTEIN.SEITE2_SCHRITT2',
            'BAUSTEIN.SEITE2_SCHRITT3',
            'BAUSTEIN.SEITE2_SCHRITT4',
            'BAUSTEIN.SEITE2_SCHRITT5',
        ), array(
            'BAUSTEIN.SEITE2_H1',
            'BAUSTEIN.SEITE2_H2',
            'BAUSTEIN.SEITE2_H3',
            'BAUSTEIN.SEITE2_H4',
            'BAUSTEIN.SEITE2_H5',
            'BAUSTEIN.SEITE2_H6',
        )),
        array('Matter2Lox Bridge', 'einbindung_loxone_bridge.png', array(
            array(mt_t('BAUSTEIN.Z3_01')),
            array(mt_t('BAUSTEIN.Z3_02')),
            array(mt_t('BAUSTEIN.Z3_03')),
        ), array(
            'BAUSTEIN.SEITE3_SCHRITT1',
            'BAUSTEIN.SEITE3_SCHRITT2',
            'BAUSTEIN.SEITE3_SCHRITT3',
            'BAUSTEIN.SEITE3_SCHRITT4',
            'BAUSTEIN.SEITE3_SCHRITT5',
        ), array(
            'BAUSTEIN.SEITE3_H1',
            'BAUSTEIN.SEITE3_H2',
            'BAUSTEIN.SEITE3_H3',
            'BAUSTEIN.SEITE3_H4',
        )),
    );
    $mt_gw = str_replace(array('/', '%'), '_', (string) $mt_praefix);
    /* 0.9.44 (Gruppe 8): Befehle und Abo tragen das Praefix als THEMA ('/' bleibt) - "publish matter/befehl/..."
     * und "matter/#" stehen so in der Liste; mit dem Werkspraefix aendert sich nichts. */
    $mt_tp = trim((string) $mt_praefix, '/');
    $mt_aus = array();
    foreach ($mt_seiten as $mt_s) {
        $mt_zeilen = array();
        foreach ($mt_s[2] as $mt_i => $mt_z) {
            $mt_c = array_pad(explode(' | ', $mt_z[0], 4), 4, '');
            if (isset($mt_z[1]) && $mt_z[1] === 'gw' && strpos($mt_c[1], 'matter_') === 0) {
                $mt_c[1] = $mt_gw . '_' . substr($mt_c[1], strlen('matter_'));
            }
            $mt_c[2] = str_replace(array('publish matter/', ' matter/#'),
                                   array('publish ' . $mt_tp . '/', ' ' . $mt_tp . '/#'), $mt_c[2]);
            $mt_zeilen[] = array_merge(array($mt_i + 1), $mt_c);
        }
        $mt_aus[] = array('seite' => $mt_s[0], 'bild' => $mt_s[1], 'zeilen' => $mt_zeilen,
                          'schritte' => array_map('mt_t', $mt_s[3]), 'hinweise' => array_map('mt_t', $mt_s[4]));
    }
    return $mt_aus;
}

/** Eine Seite: Ueberschrift (= Seitenname in Config), Kasten, Tabelle, Hinweise, Bild, Link. */
function mt_bausteinseite_zeigen($mt_bs, $mt_bs_nr)
{ ?>
<h4 class="sm-h4" id="mt-bs-<?= (int) $mt_bs_nr ?>"><?= mt_e($mt_bs['seite']) ?></h4>
<div class="sm-hinweis"><b><?= mt_e(mt_t('LOX.SCHRITTE_TITEL')) ?></b>
<ol class="sm-schritte">
<?php foreach ($mt_bs['schritte'] as $mt_bx) { ?>
<li><?= mt_e($mt_bx) ?></li>
<?php } ?>
</ol></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th>#</th><th><?= mt_e(mt_t('LOX.T_BAUSTEIN')) ?></th><th><?= mt_e(mt_t('LOX.T_NAMENSVORSCHLAG')) ?></th>
    <th><?= mt_e(mt_t('LOX.T_PARAMETER')) ?></th><th><?= mt_e(mt_t('LOX.T_EINGAENGE')) ?></th></tr>
<?php foreach ($mt_bs['zeilen'] as $mt_bz) { ?>
<tr><td><?= (int) $mt_bz[0] ?></td><td><?= mt_e($mt_bz[1]) ?></td><td><?= mt_e($mt_bz[2]) ?></td>
    <td><?= mt_e($mt_bz[3]) ?></td><td><?= mt_e($mt_bz[4]) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-warnung"><b><?= mt_e(mt_t('LOX.HINWEISE_TITEL')) ?></b>
<ul class="sm-schritte">
<?php foreach ($mt_bs['hinweise'] as $mt_bx) { ?>
<li><?= $mt_bx ?></li>
<?php } ?>
</ul></div>
<figure class="sm-bild">
<img src="<?= mt_e($mt_bs['bild']) ?>" alt="<?= mt_e(sprintf(mt_t('LOX.BILD_ALT'), $mt_bs['seite'])) ?>" loading="lazy">
<figcaption><?= mt_e(sprintf(mt_t('LOX.BILD_UNTERSCHRIFT'), $mt_bs['seite'])) ?></figcaption>
</figure>
<p class="sm-hilfe"><?= mt_t('LOX.MUSTERPROJEKT') ?></p>
<?php }
/* Gateway-Namen aus dem Praefix des Reiters Einstellungen ($mt_cfg ist oben gelesen). */
$mt_bl = mt_bausteinliste(mt_mqtt_praefix($mt_cfg));
?>
<div class="sm-step"><b><?= mt_e(mt_t('LOX.S7_TITEL')) ?></b><br>
<?= mt_t('LOX.S7_TEXT') ?>
<?php foreach ($mt_bl as $mt_bs_i => $mt_bs) { mt_bausteinseite_zeigen($mt_bs, $mt_bs_i + 1); } ?>
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
  <a class="sm-btn sm-b-lesen" href="<?= mt_e($mt_browser) ?>?token=<?= mt_e($mt_lese) ?>&amp;aktion=liste" target="_blank"><?= mt_e(mt_t('TEST.K_LISTE')) ?></a>
  <a class="sm-btn sm-b-lesen" href="<?= mt_e($mt_browser) ?>?token=<?= mt_e($mt_lese) ?>&amp;aktion=status&amp;geraet=1" target="_blank"><?= mt_e(mt_t('TEST.K_STATUS')) ?></a>
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
  <a class="sm-btn sm-b-technik" href="<?= mt_e($mt_browser) ?>?token=<?= mt_e($mt_lese) ?>&amp;aktion=roh" target="_blank"><?= mt_e(mt_t('TEST.K_ROH')) ?></a>
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
  <?= $mt_geraet_feld('test_geraet', 'schalten') ?>
</div>
<div class="sm-feld">
  <label for="test_endpunkt"><?= mt_e(mt_t('TEST.L_ENDPUNKT')) ?></label>
  <input data-role="none" type="number" id="test_endpunkt" name="test_endpunkt" value="<?= mt_e(mt_eingabe('schalten', 'test_endpunkt', '1')) ?>" min="0" max="255">
  <div class="sm-hilfe"><?= mt_t('TEST.H_ENDPUNKT') ?></div>
</div>
<div class="sm-feld">
  <label for="test_wert"><?= mt_e(mt_t('TEST.L_WERT')) ?></label>
  <input data-role="none" type="number" id="test_wert" name="test_wert" value="<?= mt_e(mt_eingabe('schalten', 'test_wert', '50')) ?>" min="0" max="100">
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
<?php /* O11 (Durchgang 30.09.2026): ein leerer Protokollreiter sagt, warum
   er leer ist, und nennt das letzte Lebenszeichen des Dienstes aus
   zustand.json (Regeln/04, Bericht oberflaeche Nr. 15). */
$mt_leben = isset($mt_zustand['herzschlag']) && (int) $mt_zustand['herzschlag'] > 0
    ? date('Y-m-d H:i:s', (int) $mt_zustand['herzschlag'])
    : (isset($mt_zustand['ts']) && (int) $mt_zustand['ts'] > 0
        ? date('Y-m-d H:i:s', (int) $mt_zustand['ts']) : mt_t('LOG.KEIN_LEBENSZEICHEN')); ?>
<div class="sm-hinweis"><?= sprintf(mt_t('LOG.LEER'), mt_e($mt_leben)) ?></div>
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

<?php /* 0.9.42: auch fuer das Kopplungsfenster der Bridge (Reiter Bridge). */
if ($mt_fenster || $mt_brcodes) { ?>
<script>
/* 0.9.39 (Entscheidung 11): Restzeit des Kopplungsfensters - nur aus
 * data-ende gerechnet, keine Abfrage beim LoxBerry. Der Abstand zwischen der
 * Uhr des LoxBerry und der des Browsers wird beim Laden einmal bestimmt. Bei
 * 0 verschwinden Bild und Codes aus der Seite. Ohne JavaScript steht die
 * Uhrzeit "Offen bis" da. */
(function () {
	var kaesten = document.querySelectorAll('.mt-fenster[data-ende]');
	if (!kaesten.length) { return; }
	var abstand = <?= (int) time() ?> * 1000 - Date.now();
	var noch = <?= json_encode(mt_t('ANLERN.FENSTER_NOCH'), JSON_UNESCAPED_UNICODE) ?>;
	function zwei(n) { return (n < 10 ? '0' : '') + n; }
	function takt() {
		var jetzt = Date.now() + abstand;
		kaesten.forEach(function (k) {
			var rest = Math.floor((parseInt(k.getAttribute('data-ende'), 10) * 1000 - jetzt) / 1000);
			var anzeige = k.querySelector('.mt-rest');
			if (rest > 0) {
				if (anzeige) { anzeige.textContent = '(' + noch + ' ' + Math.floor(rest / 60) + ':' + zwei(rest % 60) + ')'; }
				return;
			}
			var geheim = k.querySelector('.mt-fenster-geheim');
			if (geheim) { geheim.parentNode.removeChild(geheim); }
			var zeit = k.querySelector('.mt-fenster-zeit');
			if (zeit) { zeit.hidden = true; }
			var ab = k.querySelector('.mt-fenster-ab');
			if (ab) { ab.hidden = false; }
		});
	}
	takt();
	setInterval(takt, 1000);
})();
</script>
<?php } ?>

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
		// O7 (Durchgang 30.09.2026): der Reiter Test wird NICHT abgefangen - sein
		// Verweis laedt die Seite mit ?form=test, und erst dann laufen die
		// Pruefungen (Regeln/04). Bis 0.9.30 zeigte ein Klick nur die
		// Aufforderung, den Reiter aufzurufen (Bericht oberflaeche Nr. 10).
		// 0.9.42: der Reiter Bridge ebenso - er misst nur serverseitig.
		if (r.dataset.ziel === 'tab-test' || r.dataset.ziel === 'tab-bridge') { return; }
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	zeige(<?= json_encode($mt_tab) ?>);

	/* 0.9.35 (Nr. 8): statt meta refresh. Laeuft ein Vorgang am Matter-Server,
	 * fragt die Seite alle 3 s index.php?vorgang=1 (nur eine Datei, kein
	 * Docker) und zeigt Dauer und Schritt. Neu geladen wird erst, wenn der
	 * Vorgang zu Ende ist - und nur, wenn der Reiter Einstellungen sichtbar
	 * ist und kein Formularfeld geaendert wurde oder gerade bedient wird.
	 * Sonst steht ein Hinweis mit Verweis da. */
	/* 0.9.42: dieselbe Nachfrage fuer den Reiter Bridge (eigener Kasten
	 * mt-vorgang-br, Neuladen auf form=bridge). */
	function beobachte(vgId, standId, tabId, ziel) {
	var vg = document.getElementById(vgId);
	if (!vg || vg.getAttribute('data-aktiv') !== '1' || !window.fetch) { return; }
	var geaendert = false;
	document.addEventListener('input', function () { geaendert = true; }, true);
	document.addEventListener('change', function () { geaendert = true; }, true);
	function bedient() {
		var a = document.activeElement;
		return !!(a && /^(INPUT|SELECT|TEXTAREA)$/.test(a.tagName));
	}
	function sichtbar() {
		var t = document.getElementById(tabId);
		return !!(t && t.classList.contains('sm-active')) && !document.hidden;
	}
	var stand = document.getElementById(standId);
	var fertigText = <?= json_encode(mt_t('EINST.V_FERTIG_NEU_LADEN'), JSON_UNESCAPED_UNICODE) ?>;
	var fertig = false;
	function frage() {
		if (fertig) { return; }
		fetch('index.php?vorgang=1', { credentials: 'same-origin', cache: 'no-store' })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (d.zustand === 'gestartet' || d.zustand === 'laeuft') {
					if (stand) { stand.textContent = '(' + d.seit + ' s, ' + d.schritt + ')'; }
					setTimeout(frage, 3000);
					return;
				}
				fertig = true;
				if (sichtbar() && !geaendert && !bedient()) {
					location.href = 'index.php?form=' + ziel;
				} else if (stand) {
					stand.innerHTML = '';
					var a = document.createElement('a');
					a.href = 'index.php?form=' + ziel;
					a.textContent = fertigText;
					stand.appendChild(a);
				}
			})
			.catch(function () { setTimeout(frage, 6000); });
	}
	setTimeout(frage, 3000);
	}
	beobachte('mt-vorgang', 'mt-vorgang-stand', 'tab-settings', 'settings');
	beobachte('mt-vorgang-br', 'mt-vorgang-br-stand', 'tab-bridge', 'bridge');
})();
</script>
<?php
if ($mt_rahmen) {
    LBWeb::lbfooter();
}
