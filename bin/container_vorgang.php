<?php
/**
 * Matter to Loxone - richtet den Matter-Server im Hintergrund ein
 * (Verbesserungsbau Welle 2, 30.09.2026, E1).
 *
 * Gestartet von der Oberflaeche (Reiter Einstellungen, Knopf "Matter-Server
 * einrichten", "Matter-Server aktualisieren" und unter "Fuer Fortgeschrittene"
 * "Abbild neu holen") ueber mt_ct_vorgang_starten(). Im Hintergrund, weil
 * "docker pull" beim ersten Mal Minuten dauert und die Seite nicht so lange
 * warten darf - bis 0.9.33 stand sie bis zu 15 Minuten. Der Vorgang schreibt
 * seinen Stand nach data/plugins/<ordner>/container_vorgang.json (0600); die
 * Seite zeigt "wird eingerichtet ... seit N s" und laedt sich neu, solange
 * er laeuft.
 *
 * Kein Takt und kein Hakenskript ruft diese Datei.
 *
 * Aufruf: php container_vorgang.php einrichten|holen|aktualisieren|uebernehmen
 * Rueckgabewert: 0 erledigt, 1 nicht gelungen, 2 falscher Aufruf,
 * 3 es laeuft schon ein Vorgang.
 *
 * 0.9.35 (Nr. 5): "uebernehmen" = Einstellungen uebernehmen (Container mit
 * Rueckweg neu anlegen, mt_ct_uebernehmen()).
 * 0.9.35 (Nr. 15): eine eigene Sperre fuer die ganze Laufzeit (flock auf
 * data/plugins/<ordner>/container_vorgang.lock - dieselbe Datei, unter der
 * mt_ct_vorgang_starten() prueft und eintraegt). Bis 0.9.34 pruefte diese
 * Datei nur den Stand in container_vorgang.json; zwei Handstarts kurz
 * nacheinander sahen beide "kein Vorgang" und liefen beide.
 */
if (basename(dirname(__DIR__)) === 'plugins') {
    $mt_lib = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/'
        . basename(__DIR__) . '/mt_lib.php';
} else {
    $mt_lib = dirname(__DIR__) . '/webfrontend/html/mt_lib.php';
}
if (!is_file($mt_lib)) {
    fwrite(STDERR, "mt_lib.php nicht gefunden - Plugin neu installieren.\n");
    exit(1);
}
require_once $mt_lib;

$mt_p = mt_paths();
if ($mt_p['home'] === '') {
    fwrite(STDERR, "container_vorgang.php: keine LoxBerry-Installation gefunden - nichts angefasst.\n");
    exit(1);
}
/* Die Sprache der Meldungen wie in der Oberflaeche (LBSystem::lblanguage). */
if (is_file($mt_p['home'] . '/libs/phplib/loxberry_system.php')) {
    require_once $mt_p['home'] . '/libs/phplib/loxberry_system.php';
}
/* Laufzeitfehler in eine Datei, nicht auf die Fehlerausgabe: die geht beim
 * Start nach /dev/null (Regeln/03, "dritte Protokollart"). */
if (!is_dir($mt_p['logdir'])) {
    @mkdir($mt_p['logdir'], 0775, true);
}
ini_set('log_errors', '1');
ini_set('display_errors', '0');
ini_set('error_log', $mt_p['logdir'] . '/container_vorgang.err');

$mt_auftrag = isset($argv[1]) ? (string) $argv[1] : '';
if (!in_array($mt_auftrag, array('einrichten', 'holen', 'aktualisieren', 'uebernehmen'), true) || count($argv) !== 2) {
    fwrite(STDERR, "Unbekannter Auftrag - erlaubt sind nur: einrichten, holen, aktualisieren, uebernehmen\n");
    exit(2);
}

/* 0.9.35 (Nr. 15): die Sperre fuer die ganze Laufzeit. Ist sie belegt,
 * laeuft schon ein Vorgang (oder ein Zurueckspielen der Fabric) - dann endet
 * dieser, ohne etwas anzufassen. Freigegeben wird sie mit dem Prozessende.
 * Gehoert die Datei einem anderen Benutzer (Handstart als root), genuegt
 * Lesen: flock braucht kein Schreibrecht. */
if (!is_dir($mt_p['datadir'])) {
    @mkdir($mt_p['datadir'], 0775, true);
}
$mt_sperre = @fopen($mt_p['datadir'] . '/container_vorgang.lock', 'c');
if ($mt_sperre === false) {
    $mt_sperre = @fopen($mt_p['datadir'] . '/container_vorgang.lock', 'r');
}
if ($mt_sperre === false) {
    fwrite(STDERR, "Die Sperrdatei container_vorgang.lock laesst sich nicht oeffnen - nichts angefasst.\n");
    exit(1);
}
if (!flock($mt_sperre, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Es laeuft bereits ein Vorgang (Sperre belegt).\n");
    exit(3);
}

/* Zweimal laufen geht nicht: lebt schon ein anderer Vorgang, endet dieser. */
$mt_v = mt_ct_vorgang();
if ($mt_v['zustand'] === 'laeuft' && (int) $mt_v['pid'] !== getmypid()) {
    fwrite(STDERR, 'Es laeuft bereits ein Vorgang (PID ' . (int) $mt_v['pid'] . ").\n");
    exit(3);
}
$mt_rc = mt_ct_vorgang_ausfuehren($mt_auftrag) ? 0 : 1;
flock($mt_sperre, LOCK_UN);
fclose($mt_sperre);
exit($mt_rc);
