<?php
/**
 * Matter2Lox - Bruecke vom Dienst zur gemeinsamen Sprachausgabe (seit 0.9.47).
 * Aufruf: php mt_ansage.php <anlass> <0|1> <pluginordner> [name] [frist]. Anlaesse: 'ausfall'. Legt nur hin und kehrt sofort zurueck; es spricht ein eigener Vorgang.
 * Ausgabe: eine ASCII-Zeile, nie ein Text oder Token. Rueckgabe 0 gelegt oder nichts zu tun, 1 nicht ausfuehrbar.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur Kommandozeile.\n");
}
$mt_a = isset($argv[1]) ? (string) $argv[1] : '';
$mt_g = isset($argv[2]) ? (string) $argv[2] : '';
$mt_o = isset($argv[3]) ? (string) $argv[3] : '';
$mt_n = isset($argv[4]) ? (string) $argv[4] : '';
$mt_f = isset($argv[5]) ? (int) $argv[5] : 0;
if (!in_array($mt_a, array('ausfall'), true) || ($mt_g !== '0' && $mt_g !== '1') || preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $mt_o) !== 1) {
    echo "ANSAGE;STAND=0;KENNUNG=AUFRUF\n";
    exit(1);
}
$mt_h = getenv('LBHOMEDIR');
if (!$mt_h || !is_dir($mt_h . '/config/plugins')) {
    $mt_h = dirname(dirname(dirname(__DIR__)));
}
$mt_lib = $mt_h . '/webfrontend/html/plugins/' . $mt_o . '/mt_lib.php';
if (!is_file($mt_lib)) {
    echo "ANSAGE;STAND=0;KENNUNG=MODUL_FEHLT\n";
    exit(1);
}
putenv('LBHOMEDIR=' . $mt_h);
putenv('LBPPLUGINDIR=' . $mt_o);
require_once $mt_lib;
echo 'ANSAGE;STAND=1;KENNUNG=' . strtoupper((string) mt_ansage_flanke($mt_a, $mt_g === '1', $mt_n, $mt_f)) . "\n";
exit(0);
