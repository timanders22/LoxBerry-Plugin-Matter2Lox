<?php
/** MQTT wire names only. Internal Matter keys, HTTP API and user names stay stable. */
function mt_mqtt_language($cfg = null)
{
    if ($cfg === null) { $cfg = mt_config(false); }
    return isset($cfg['mqtt_language']) && $cfg['mqtt_language'] === 'en' ? 'en' : 'de';
}

function mt_mqtt_topic($topic, $cfg = null, $decode = false)
{
    if (mt_mqtt_language($cfg) !== 'en') { return (string) $topic; }
    $table = mt_tabelle();
    if (empty($table['mqtt_english'])) { throw new RuntimeException('MQTT English schema missing'); }
    $map = $decode ? array_flip($table['mqtt_english']) : $table['mqtt_english'];
    $parts = explode('/', (string) $topic);
    foreach ($parts as &$part) {
        if (preg_match($decode ? '/^device([0-9]+|N)$/' : '/^geraet([0-9]+|N)$/', $part, $m)) {
            $part = ($decode ? 'geraet' : 'device') . $m[1];
        } elseif (isset($map[$part])) {
            $part = $map[$part];
        }
    }
    unset($part);
    return implode('/', $parts);
}

function mt_mqtt_title($word, $cfg = null)
{
    if (mt_mqtt_language($cfg) !== 'en') { return $word; }
    return ucfirst(str_replace('_', ' ', mt_mqtt_topic(strtolower($word), $cfg)));
}

function mt_loxone_key($key, $cfg = null)
{
    if (mt_mqtt_language($cfg) !== 'en') { return $key; }
    if ($key === 'ERREICH') { return 'AVAILABLE'; }
    return strtoupper(mt_mqtt_topic(strtolower($key), $cfg));
}

/** Flattened gateway identifiers in the bundled example; keep compound attribute names intact. */
function mt_mqtt_gateway_example($name, $cfg = null)
{
    if (mt_mqtt_language($cfg) !== 'en') { return $name; }
    if (preg_match('/^geraet([0-9]+)_([0-9]+)_(.+)$/', $name, $m)) {
        return 'device' . $m[1] . '_' . $m[2] . '_' . mt_mqtt_topic($m[3], $cfg);
    }
    if (preg_match('/^geraet([0-9]+)_(.+)$/', $name, $m)) {
        return 'device' . $m[1] . '_' . mt_mqtt_topic($m[2], $cfg);
    }
    if (strpos($name, 'status_') === 0) { return 'status_' . mt_mqtt_topic(substr($name, 7), $cfg); }
    return mt_mqtt_topic($name, $cfg);
}

/** Optional transport hooks for the shared command bridge; its safety checks stay canonical. */
function mt_mqtt_transport($cfg)
{
    return array(
        'transport_id' => mt_mqtt_language($cfg),
        'encode_topic' => function ($topic) use ($cfg) { return mt_mqtt_topic($topic, $cfg); },
        'decode_topic' => function ($topic) use ($cfg) {
            $canonical = mt_mqtt_topic($topic, $cfg, true);
            // Reject German/mixed aliases in English mode, rather than accidentally accepting both.
            return mt_mqtt_topic($canonical, $cfg) === $topic ? $canonical : null;
        },
    );
}
