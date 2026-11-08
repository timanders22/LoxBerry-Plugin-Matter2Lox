# OTBR dataset input and network-only commissioning

This change tightens two existing paths. It does not add a mobile app, gateway,
BLE proxy, controller migration or new device commissioning protocol.

## Reading an OTBR dataset

The existing button reads GET /node/dataset/active and stores the result in the
plugin. Sending those credentials to the Matter server is a separate action.
Use the router's configured REST host and port; fetching its dataset does not
establish IPv6 routing or prove that devices are reachable.

The response parser accepts plain hexadecimal text or a JSON string containing
hexadecimal text, with an even number of digits (20–600). It rejects malformed
JSON, objects, unmatched quotes, odd-length hex and oversized responses. This
checks the encoding, not the complete Thread dataset's semantic validity.
Malformed response fragments are no longer included in user-visible errors,
because even an invalid response may contain network credentials.

## Multi-Admin

For a device already paired to another controller, open a new sharing window
there and use its newly generated code. Select network-only commissioning when
the device is reachable over IP. Do not reset a working device for this route.
The existing controller and relevant Thread border router should remain available.

The network-only flag now uses the same strict boolean parser as other plugin
flags: values such as the string "0" or "false" no longer incorrectly bypass the
Bluetooth and credential checks. A supplied device IP still selects the existing
network route. Network-only requests send no Wi-Fi or Thread credentials.

## Validation

Run with Python 3.10+, PHP CLI and the pinned test dependency:

```sh
pip install -r tests/requirements-test.txt
python -m unittest discover -s tests -v
```

All 10 tests passed locally with Python 3.13.7, websockets 15.0.1 and PHP 8.5.1.
Tests cover plain/JSON datasets, malformed and credential-bearing responses,
GET-only transport, HTTP status handling, no redirects, and both PHP cURL and
stream fallback paths. Commissioning tests check true/false flag representations,
existing-node preservation, permission gates and the existing IP route. They use
synthetic data, mocked controllers and a temporary localhost HTTP server.

Separately, the existing network-only Multi-Admin route was successfully used
with an IKEA GRILLPLATS: the device gained the Matter2Lox fabric and retained its
original controller fabric. That supports the documented workflow; it does not
constitute a live test of this new dataset parser or every device/backend.
