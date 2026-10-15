<?php
/**
 * Matter to Loxone 0.9.42 - Waechter der Matter-Bridge (Beta), Stufe 3.
 *
 * Gerufen von bin/dienst.sh waechter (cron.01min, als loxberry), jede Minute,
 * unabhaengig davon, ob der Brueckendienst laufen soll. Ohne
 * config/plugins/<ordner>/bridge.json (Bridge nie eingeschaltet) kein
 * einziger Docker-Aufruf. Sonst:
 *  - schliesst ein abgelaufenes Kopplungsfenster (bridge_fenster.json),
 *  - zaehlt die Anmeldefehler im Protokoll der Bridge seit dem letzten Lauf
 *    und haelt sie beim 3. seit dem letzten Erfolg an (Sperrmerker,
 *    Benachrichtigung). Netzfehler zaehlen nie.
 * Startet NIE etwas - auch keine angehaltene Bridge (Entwurf 3.11: nie
 * Endlos-Neuversuch). Laeuft ein Hintergrundvorgang, zaehlt dessen
 * Beobachtung (Stufe 2), und dieser Lauf tut nichts.
 *
 * Aufruf: php bridge_waechter.php   Rueckgabe 0; eine Zeile auf stdout.
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
    fwrite(STDERR, "bridge_waechter.php: keine LoxBerry-Installation gefunden - nichts angefasst.\n");
    exit(1);
}
if (is_file($mt_p['home'] . '/libs/phplib/loxberry_system.php')) {
    require_once $mt_p['home'] . '/libs/phplib/loxberry_system.php';
}
echo mt_br_waechter() . "\n";
exit(0);
