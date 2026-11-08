"""Offline regressions: no Docker, radios, production hosts or fabric files.

Run: python -m unittest discover -s tests -v
Python 3.10+, PHP CLI; websockets (see requirements-test.txt).
"""
import asyncio
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from unittest.mock import AsyncMock

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('matter_dienst', ROOT / 'bin/matter_dienst.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

# Synthetic public Matter example code, never a user's pairing code.
CODE = '34970112332'
# Synthetic byte sequence: tests transport encoding, not Thread semantics.
DATASET = '00112233445566778899aabbccddeeff'


def php(expression, args=(), no_ini=False):
    cmd = ['php'] + (['-n'] if no_ini else []) + ['-r',
        'require $argv[1] . "/webfrontend/html/mt_lib.php"; ' + expression,
        str(ROOT), *args]
    env = {k: v for k, v in os.environ.items() if k not in ('LBHOMEDIR', 'LBPPLUGINDIR')}
    result = subprocess.run(cmd, check=True, capture_output=True, text=True, env=env)
    if result.stderr:
        raise AssertionError(result.stderr)
    return json.loads(result.stdout)


class Commissioning(unittest.IsolatedAsyncioTestCase):
    def setUp(self):
        self.cfg = dict(m.VORGABEN, steuerung_ein=1)
        self.v = m.MatterVerbindung(self.cfg)
        self.v.server_info = {'bluetooth_enabled': False, 'wifi_credentials_set': False,
                              'thread_credentials_set': False}
        self.v.senden = AsyncMock(return_value={'node_id': 2, 'attributes': {}})
        self.v.knoten[1] = {'node_id': 1, 'attributes': {'1/6/0': True}}

    async def commission(self, **kwargs):
        return await m.befehl_ausfuehren(self.v, dict(aktion='anlernen', code=CODE, **kwargs), self.cfg, {})

    async def test_multi_admin_needs_no_ble_or_credentials(self):
        self.assertEqual((await self.commission(nur_netz=1))[0], 1)
        self.v.senden.assert_awaited_once_with('commission_with_code',
            {'code': CODE, 'network_only': True}, zeit=180)
        self.assertTrue(self.v.knoten[1]['attributes']['1/6/0'])
        self.assertIn(2, self.v.knoten)


    async def test_explicit_ip_uses_network_command(self):
        self.assertEqual((await self.commission(ip='fd12::abcd'))[0], 1)
        self.v.senden.assert_awaited_once_with('commission_on_network',
            {'setup_pin_code': 20202021, 'ip_addr': 'fd12::abcd'}, zeit=180)

    async def test_no_ble_rejects_fresh_device(self):
        for value in (0, False, '0', 'false', 'off', '', None):
            with self.subTest(value=value):
                self.assertEqual((await self.commission(nur_netz=value))[0], 0)
        self.v.senden.assert_not_awaited()


    async def test_control_gate_preserved_for_all_routes(self):
        self.cfg['steuerung_ein'] = 0
        for args in ({}, {'nur_netz': 1}, {'ip': 'fd12::abcd'}):
            self.assertEqual((await self.commission(**args))[0], 0)
        self.v.senden.assert_not_awaited()

    async def test_invalid_qr_with_ip_rejected_before_request(self):
        result = await m.befehl_ausfuehren(self.v,
            {'aktion': 'anlernen', 'code': 'MT:Y.ABCDEFG123456789', 'ip': 'fd12::abcd'}, self.cfg, {})
        self.assertEqual(result[0], 0)
        self.v.senden.assert_not_awaited()


@unittest.skipUnless(shutil.which('php'), 'PHP CLI required')
class PHPConfiguration(unittest.TestCase):


    def test_otbr_encoding_rejects_bad_or_secret_bearing_objects(self):
        bodies = [DATASET, json.dumps(DATASET), '\n' + DATASET + '\n', DATASET + 'a',
                  "'" + DATASET + "'", json.dumps({'NetworkKey': DATASET}),
                  '"' + DATASET, '<html>login</html>', '', 'a' * 4097]
        result = php('$a = json_decode($argv[2], true); echo json_encode(array_map("mt_thread_dataset_antwort", $a));', (json.dumps(bodies),))
        self.assertEqual(result, [DATASET, DATASET, DATASET] + [None] * 7)


@unittest.skipUnless(shutil.which('php'), 'PHP CLI required')
class OTBRTransport(unittest.TestCase):
    def test_read_only_http_status_headers_redaction_and_no_redirects(self):
        requests = []
        response = {'status': 200, 'body': DATASET}

        class Handler(BaseHTTPRequestHandler):
            def do_GET(self):
                requests.append((self.command, self.path, self.headers.get('Accept')))
                self.send_response(response['status'])
                if response['status'] == 302:
                    self.send_header('Location', '/must-not-follow')
                self.end_headers()
                self.wfile.write(response['body'].encode())
            def log_message(self, *args):
                pass

        with ThreadingHTTPServer(('127.0.0.1', 0), Handler) as server:
            worker = threading.Thread(target=server.serve_forever, daemon=True)
            worker.start()
            try:
                # Normal PHP uses curl where available; -n exercises the stream fallback.
                for no_ini in (False, True):
                    for status, body, expected in ((200, DATASET, 1), (200, json.dumps(DATASET), 1),
                            (204, '', 2), (200, '', 2), (404, 'not found', 2),
                            (401, 'unauthorized', 2), (302, DATASET, 2),
                            (200, json.dumps({'NetworkKey': DATASET}), 2)):
                        with self.subTest(no_ini=no_ini, status=status, body=body[:8]):
                            response.update(status=status, body=body)
                            requests.clear()
                            result = php('echo json_encode(mt_thread_dataset_holen($argv[2]));',
                                (f'127.0.0.1:{server.server_port}',), no_ini=no_ini)
                            self.assertEqual(result[0], expected)
                            self.assertEqual(requests, [('GET', '/node/dataset/active', 'text/plain')])
                            if expected == 1:
                                self.assertEqual(result[1], DATASET)
                            else:
                                self.assertNotIn(DATASET, result[1])
            finally:
                server.shutdown()
                worker.join()

    def test_invalid_addresses_never_reach_network(self):
        for address in ('http://192.168.10.20:8080', 'host:0', 'host:65536', 'host/x', 'user@host', ''):
            self.assertEqual(php('echo json_encode(mt_thread_dataset_holen($argv[2]));', (address,))[0], 0)


class NetworkOnlyFlags(unittest.IsolatedAsyncioTestCase):
    async def test_true_network_only_flags_skip_credentials_and_bluetooth(self):
        for value in (1, True, '1', 'true', 'on', 'ja'):
            with self.subTest(value=value):
                cfg = dict(m.VORGABEN, steuerung_ein=1)
                connection = m.MatterVerbindung(cfg)
                connection.server_info = {'bluetooth_enabled': False,
                    'wifi_credentials_set': False, 'thread_credentials_set': False}
                connection.senden = AsyncMock(return_value={'node_id': 2, 'attributes': {}})
                result = await m.befehl_ausfuehren(connection,
                    {'aktion': 'anlernen', 'code': CODE, 'nur_netz': value}, cfg, {})
                self.assertEqual(result[0], 1)
                connection.senden.assert_awaited_once_with('commission_with_code',
                    {'code': CODE, 'network_only': True}, zeit=180)


class Translations(unittest.TestCase):
    def test_dataset_error_does_not_interpolate_response_in_either_language(self):
        for language in ('de', 'en'):
            text = php('$a = parse_ini_file($argv[1] . "/templates/lang/language_" . $argv[2] . ".ini", true, INI_SCANNER_RAW); echo json_encode($a["ANLERN"]["BR_KEIN_HEX"]);', (language,))
            self.assertNotIn('%s', text)


if __name__ == '__main__':
    unittest.main()
