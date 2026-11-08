"""Offline MQTT wire/schema regressions; no live brokers, controllers or fabrics."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import Mock, patch
ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("matter_dienst", ROOT / "bin/matter_dienst.py")
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

CFG_EN = dict(m.VORGABEN, mqtt_language='en', mqtt_topic='home/geraet1')
DEVICE = {'name': 'Lamp & Desk', 'kurz': 'Lamp & Desk', 'erreichbar': 1, 'node_id': 123,
          'info': {'hersteller': 'Example'},
          'endpunkte': {'1': {'schalter': 1, 'helligkeit': 42, 'farbtemperatur_mired': 250,
                              'leistung': 15.5, 'taste_kurz': 1, 'taster_stellungen': 2,
                              'schloss': 1, 'soll_heizen': 21.5},
                        '2': {'schalter': 0}}, 'roh': {'1/6/0': True}}


class PHPFixture(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.home = Path(self.temp.name)
        self.configdir = self.home / 'config/plugins/matter2lox'
        self.datadir = self.home / 'data/plugins/matter2lox'
        self.configdir.mkdir(parents=True)
        self.datadir.mkdir(parents=True)
        (self.home / 'bin/plugins').mkdir(parents=True)
        (self.home / 'bin/plugins/matter2lox').symlink_to(ROOT / 'bin', target_is_directory=True)
        self.write_config('en')
        (self.datadir / 'loxone.json').write_text(json.dumps({'ok': 1, 'geraete': {'1': DEVICE}}))
        (self.home / 'config/system').mkdir()
        (self.home / 'config/system/general.json').write_text(json.dumps({'Mqtt': {'Udpinport': 11884}}))

    def write_config(self, language):
        cfg = dict(m.VORGABEN, mqtt_topic='home/geraet1', mqtt_language=language,
                   befehle_mqtt_ein=1, steuerung_ein=1, aktionstoken='fixture_token')
        (self.configdir / 'matter2lox.json').write_text(json.dumps(cfg))

    def php(self, code, *args, root=ROOT, ui='en'):
        env = dict(os.environ, LBHOMEDIR=str(self.home), LBPPLUGINDIR='matter2lox', LBLANG=ui)
        result = subprocess.run(['php', '-r', 'require $argv[1]."/webfrontend/html/mt_lib.php"; '+code,
                                 str(root), *args], env=env, text=True, capture_output=True, check=True)
        self.assertEqual(result.stderr, '')
        return json.loads(result.stdout)

    def test_german_exports_are_byte_compatible_with_pre_feature_commit(self):
        self.write_config('de')
        expected = json.loads((ROOT/'tests/fixtures/mqtt_legacy.json').read_text())
        actual = self.php('echo json_encode(array(mt_vorlage_mqtt_out(1), mt_vorlage_out(1), mt_vorlage(1), mt_mqtt_namen(), mt_bb_liste()));')
        self.assertEqual(actual, expected)

    def test_schema_parity_all_attributes_and_commands(self):
        table = m.tabelle()['mqtt_english']
        topics = ['geraet12/1/' + k for k in table] + ['befehl/geraet12/1/helligkeit', 'status/zaehler',
                  'geraet12/roh/1/6/0', 'befehl/geraet12/abruf', 'befehl/abruf']
        output = self.php('echo json_encode(array_map("mt_mqtt_topic", json_decode($argv[2], true)));', json.dumps(topics))
        self.assertEqual(output, [m.mqtt_topic(t, CFG_EN) for t in topics])
        self.assertEqual(len(table), len(set(table.values())))
        for topic, translated in zip(topics, output):
            self.assertEqual(m.mqtt_topic(translated, CFG_EN, decode=True), topic)
        self.assertTrue(set(m.themen_selbstauskunft()['themen']) <= set(table))

    def test_interface_language_cannot_change_mqtt_schema(self):
        for language, expected in [('de', 'geraet1/1/helligkeit'), ('en', 'device1/1/brightness')]:
            self.write_config(language)
            for ui in ('de', 'en', 'nl'):
                self.assertEqual(self.php('echo json_encode(mt_mqtt_topic("geraet1/1/helligkeit"));', ui=ui), expected)

    def test_settings_validation_and_old_backup(self):
        self.assertEqual(self.php('echo json_encode(array(mt_wert_pruefen("mqtt_language","en"),'
                                 'mt_wert_pruefen("mqtt_language","de"),mt_vorgaben()["mqtt_language"]));'), ['', '', 'de'])
        for bad in ('nl', 'EN', '', 'en\n', [], True):
            self.assertTrue(self.php('echo json_encode(mt_wert_pruefen("mqtt_language",json_decode($argv[2],true)));', json.dumps(bad)))
        self.assertIn('mqtt_language', self.php('echo json_encode(mt_sicherung_neue_schluessel());'))
        backup = self.php('$c=mt_vorgaben(); unset($c["mqtt_language"]); $c["aktionstoken"]=str_repeat("a",32); '
                          '$r=mt_sicherung_lesen(json_encode($c)); echo json_encode(array($r[0]["mqtt_language"],$r[1]));')
        self.assertEqual(backup, ['de', []])

    def test_templates_and_gateway_names_match_wire(self):
        output = self.php('echo json_encode(array(mt_vorlage_mqtt_out(1),mt_mqtt_namen(),mt_bb_liste(),mt_vorlage_out(1)));')
        mqtt_xml = output[0][1]
        self.assertIn('MQTT Commands', mqtt_xml)
        self.assertIn('Lamp &amp; Desk Brightness', mqtt_xml)
        self.assertIn('publish home/geraet1/command/device1/1/brightness &lt;v.0&gt;', mqtt_xml)
        self.assertIn('publish home/geraet1/command/device1/1/on_off 0', mqtt_xml)
        self.assertIn('Endpoint 2', mqtt_xml)
        rows = {row['thema']: row for row in output[1]}
        self.assertEqual(rows['device1/1/on_off']['name'], 'home_geraet1_device1_1_on_off')
        self.assertTrue(rows['device1/1/on_off']['retained'])
        self.assertFalse(rows['device1/1/power']['retained'])
        self.assertEqual(rows['device1/available']['v2'], 'N')
        self.assertEqual(rows['device1/1/button_short_press']['v2'], 'R')
        self.assertEqual(output[2]['abo'], 'home/geraet1/command/#')
        self.assertEqual(output[2]['lebenszeichen'], 'home/geraet1/status/commands')
        # HTTP command contract remains canonical even when its title is translated.
        self.assertIn('aktion=helligkeit', output[3][1])

    def test_gateway_example_preserves_compound_names(self):
        self.assertEqual(self.php('echo json_encode(array(mt_mqtt_gateway_example("geraet2_1_energie_bezug"),'
                                 'mt_mqtt_gateway_example("geraet2_erreichbar"),mt_mqtt_gateway_example("status_zaehler")));'),
                         ['device2_1_energy_import', 'device2_available', 'status_counter'])

    def bridge(self, topic, payload='42', retain='0', accept=True, writes=True):
        return self.php('''mt_bruecke_laden(); $z = require $argv[1].'/bin/befehle_zuordnung.php';
            $calls = array(); foreach ($z['befehle'] as $key => &$item) {
                $item['tun'] = function($value,$parts) use (&$calls,$key) { $calls[] = array($key,$value,$parts); return array(true,''); };
            } unset($item); $z['protokoll'] = function($level,$text) {}; $GLOBALS['bb_zuordnung'] = $z;
            $e = bb_einstellungen($z); $e['annehmen'] = $argv[5] === '1'; $e['eingreifend'] = $argv[6] === '1';
            $state = bb_neuer_zustand(); $acks = array();
            bb_stapel(array($argv[4].' home/geraet1/'.$argv[2].' '.bin2hex($argv[3])), $state, $z, $e,
                function($name,$text) use (&$acks,$e) { $acks[] = array(bb_wire($e,'befehl/'.$name.'/quittung'),$text); },
                function() { return 1000; }); echo json_encode(array($calls,$acks,$state['zaehler']));''',
                        topic, payload, retain, '1' if accept else '0', '1' if writes else '0')

    def test_english_commands_dispatch_to_existing_validator(self):
        result = self.bridge('command/device1/1/brightness')
        self.assertEqual(result[0], [['{geraet}/{ep}/helligkeit', '42', {'geraet': 'geraet1', 'ep': '1'}]])
        self.assertEqual(result[1][0][0], 'home/geraet1/command/device1/1/brightness/acknowledgement')
        self.assertEqual(len(self.bridge('command/device1/refresh', '1')[0]), 1)

    def test_retain_range_permissions_and_mixed_languages_rejected(self):
        for kwargs in ({'retain': '1'}, {'payload': '101'}, {'payload': 'not-a-number'},
                       {'accept': False}, {'writes': False}):
            with self.subTest(kwargs=kwargs):
                self.assertEqual(self.bridge('command/device1/1/brightness', **kwargs)[0], [])
        for topic in ('befehl/geraet1/1/helligkeit', 'command/geraet1/1/brightness',
                      'command/device1/1/helligkeit', 'command/device1/1/raw',
                      'command/device1/1/brightness/acknowledgement'):
            self.assertEqual(self.bridge(topic)[0], [])
        self.write_config('de')
        self.assertEqual(len(self.bridge('befehl/geraet1/1/helligkeit')[0]), 1)
        self.assertEqual(self.bridge('command/device1/1/brightness')[0], [])


class PythonWire(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.home = Path(self.temp.name)

    def test_actual_udp_bytes_preserve_retention_payload_prefix_and_events(self):
        for lang, suffix, key in [('de', 'geraet1/1/schalter', 'taste_kurz'), ('en', 'device1/1/on_off', 'button_short_press')]:
            cfg = dict(CFG_EN, mqtt_language=lang)
            sock = Mock()
            with patch.object(m, 'mqtt_zustand', return_value={'udpport': 11884, 'autostart': True}), \
                 patch.object(m.socket, 'socket', return_value=sock), patch.object(m, '_altlast_vorher_leeren', return_value=False):
                sent = m.mqtt_senden({'geraet1/1/schalter': 1, 'geraet1/1/leistung': 15.5,
                                     'geraet1/1/taste_kurz': 1, 'geraet1/name': 'schalter Lamp',
                                     'geraet1/roh/1/6/0': True}, cfg['mqtt_topic'], cfg=cfg)
            messages = [call.args[0].decode() for call in sock.sendto.call_args_list]
            self.assertIn('retain home/geraet1/' + suffix + ' 1', messages)
            self.assertIn('publish home/geraet1/' + ('device1' if lang == 'en' else 'geraet1') + '/1/' + key + ' 1', messages)
            self.assertTrue(any(message.endswith(' schalter Lamp') for message in messages))
            self.assertEqual(len(sent), 5)
            self.assertFalse(any(message.startswith('retain ') and ('power' in message or 'leistung' in message) for message in messages))

    def test_gateway_reset_file_uses_actual_english_event_names(self):
        pairs = m.paare_bilden({'1': DEVICE}, CFG_EN, 1, m.tabelle())
        with patch.object(m, 'DATEI_GW_ABOS', self.home/'subscriptions'), patch.object(m, 'DATEI_GW_RESET', self.home/'reset'):
            m.gateway_dateien_pflegen(CFG_EN, pairs)
        self.assertEqual((self.home/'subscriptions').read_text(), 'home/geraet1/#\n')
        reset = (self.home/'reset').read_text().splitlines()
        self.assertIn('home_geraet1_device1_1_button_short_press', reset)
        self.assertTrue(all('device1_1_button_' in value for value in reset))

    def test_heartbeat_uses_language_and_remains_nonretained(self):
        with patch.object(m, 'zustand_schreiben'), patch.object(m, 'ansage_lage'), \
             patch.object(m, 'mqtt_senden') as send, patch.dict(m._ERREICHBAR, {'geraet1/erreichbar': 1}, clear=True):
            m.herzschlag_senden(CFG_EN, 1)
        self.assertEqual(send.call_args.kwargs['cfg']['mqtt_language'], 'en')
        actual = {m.mqtt_topic(k, CFG_EN): v for k,v in send.call_args.args[0].items()}
        self.assertIn('status/counter', actual)
        self.assertIn('device1/available', actual)
        self.assertTrue(all(not m.ist_zustand(k) for k in send.call_args.args[0]))

    def test_cleanup_cannot_remove_commands_raw_unknown_or_another_prefix(self):
        fits = m._leer_passt('home/geraet1')
        for topic in ('geraet1/1/schalter', 'device1/1/on_off', 'device2/0/manufacturer', 'device1/available'):
            self.assertTrue(fits('home/geraet1/'+topic), topic)
        for topic in ('device1/1/schalter', 'command/device1/1/on_off', 'device1/raw/1/6/0',
                      'device1/1/unknown', 'device1/1/power', 'device1/1/button_short_press', 'status/counter'):
            self.assertFalse(fits('home/geraet1/'+topic), topic)
        self.assertFalse(fits('other/device1/1/on_off'))

    def test_language_switch_cleanup_retries_survives_restart_and_forces_resend(self):
        marker = self.home/'schema.json'
        with patch.object(m, 'DATEI_MQTT_SCHEMA', marker), \
             patch.dict(m._MQTT_SCHEMA, {'active': None, 'cleaned': False, 'retry': 0.0}, clear=True), \
             patch.object(m, 'themen_leeren', return_value=(1,False)) as clean, \
             patch.object(m, 'time') as clock, patch.dict(m._LETZTE_PAARE, {'geraet1/1/schalter':'1', 'geraet1/1/taste_kurz':'1'}, clear=True):
            clock.time.return_value = 100
            self.assertTrue(m.mqtt_schema_pflegen(dict(CFG_EN, mqtt_language='de')))
            clean.assert_not_called()  # Existing installs have no new broker work.
            self.assertTrue(m.mqtt_schema_pflegen(CFG_EN))
            self.assertEqual(m._LETZTE_PAARE, {'geraet1/1/taste_kurz':'1'})
            fits = clean.call_args.args[1]
            self.assertTrue(fits('home/geraet1/geraet1/1/schalter'))
            self.assertFalse(fits('home/geraet1/device1/1/on_off'))
            self.assertTrue(json.loads(marker.read_text())['pending'])
            clean.reset_mock()
            self.assertFalse(m.mqtt_schema_pflegen(CFG_EN))
            clean.assert_not_called()
            m._MQTT_SCHEMA['active'] = None  # Restart retries a persisted pending cleanup.
            clean.return_value = (0,True)
            self.assertTrue(m.mqtt_schema_pflegen(CFG_EN))
            self.assertFalse(json.loads(marker.read_text())['pending'])
            clean.reset_mock()
            m._MQTT_SCHEMA['active'] = None
            m.mqtt_schema_pflegen(CFG_EN)
            clean.assert_not_called()
            m.mqtt_schema_pflegen(dict(CFG_EN, mqtt_language='de'))
            fits = clean.call_args.args[1]
            self.assertTrue(fits('home/geraet1/device1/1/on_off'))
            self.assertFalse(fits('home/geraet1/geraet1/1/schalter'))
