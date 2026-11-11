<?php
/**
 * Matter2Lox - Konfiguration vervollstaendigen, Kommandozeile (seit 0.9.52, Regeln/05).
 *
 * Aufgerufen vom Dienst beim Start (bin/matter_dienst.py, vor dem ersten Lesen der Konfiguration) und von
 * postupgrade.sh. Geschrieben wird ausschliesslich ueber mt_config_vervollstaendigen() in mt_lib.php - der Dienst
 * schreibt die Konfiguration nicht selbst. Waehrend einer Aktualisierung (Marke .upgrade_laeuft) geschieht nichts.
 *
 * Aufruf: php mt_vervollstaendigen.php <pluginordner>
 * Ausgabe genau eine Zeile, nur Schluesselnamen, nie ein Wert:
 *   VERVOLLSTAENDIGEN;STAND=<ohne_wurzel|ruht|ohne_datei|ohne_merkwort|vollstaendig|ergaenzt|fehler>;SCHLUESSEL=a,b
 *   VERVOLLSTAENDIGEN;STAND=0;KENNUNG=<AUFRUF|WURZEL|MODUL_FEHLT>
 * Rueckgabe 0 bei ruht, ohne_datei, ohne_merkwort, vollstaendig, ergaenzt; sonst 1.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur Kommandozeile.\n");
}
$mt_o = isset($argv[1]) ? (string) $argv[1] : '';
if (count($argv) !== 2 || preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $mt_o) !== 1) {
    echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=AUFRUF\n";
    exit(1);
}
// Als LoxBerry-Wurzel gilt nur ein Ordner mit config/plugins, data/plugins und config/system/general.json
// (wie bin/mt_ansage.php).
$mt_wurzel = function ($h) {
    return is_string($h) && $h !== '' && is_dir($h . '/config/plugins') && is_dir($h . '/data/plugins')
        && is_file($h . '/config/system/general.json');
};
$mt_h = getenv('LBHOMEDIR');
if (!$mt_wurzel($mt_h)) {
    $mt_h = dirname(dirname(dirname(__DIR__)));
    if (!$mt_wurzel($mt_h)) {
        echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=WURZEL\n";
        exit(1);
    }
}
$mt_lib = $mt_h . '/webfrontend/html/plugins/' . $mt_o . '/mt_lib.php';
if (!is_file($mt_lib)) {
    echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=MODUL_FEHLT\n";
    exit(1);
}
putenv('LBHOMEDIR=' . $mt_h);
putenv('LBPPLUGINDIR=' . $mt_o);
require_once $mt_lib;
$mt_e = mt_config_vervollstaendigen();
echo 'VERVOLLSTAENDIGEN;STAND=' . $mt_e['stand'] . ';SCHLUESSEL=' . implode(',', $mt_e['schluessel']) . "\n";
exit(in_array($mt_e['stand'], array('ruht', 'ohne_datei', 'ohne_merkwort', 'vollstaendig', 'ergaenzt'), true) ? 0 : 1);
