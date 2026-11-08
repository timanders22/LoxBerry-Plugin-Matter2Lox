# MQTT and Loxone naming: German or English

German integration names can make it harder for non-German-speaking users to
understand MQTT topics and wire Loxone inputs and commands. This optional setting
provides English names while preserving existing German installations by default.

## Setting

The **MQTT** tab now has **Language of MQTT names and Loxone templates**:

- **German — existing names (default)**: default, including existing configurations and older backups.
- **English — English names**: English MQTT topics, command topics, acknowledgement topic names,
  gateway input names and generated Loxone input/output titles.

The setting is `mqtt_language` (`de` or `en`). It is independent of the existing
interface language, which continues to follow LoxBerry. No MQTT language is
inferred from the browser, operating system or interface language.

A persistent notice beside the selector explains that existing Loxone links must
be changed, that switching back has the same consequence, and that devices stay
paired. Selecting a different language shows an additional unsaved-change notice
before Save; returning to the saved language clears it. A successful language
change also shows a reminder to update Loxone. This UI was checked in a local
Chromium browser in German and English, in both switch directions.

Exactly one schema is published and accepted. There is no simultaneous German
and English command aliasing or duplicate publication. User-defined prefixes,
device names, values, units, Matter node IDs, endpoint IDs and fabrics stay intact.

| German | English |
| --- | --- |
| `matter/geraet1/1/schalter` | `matter/device1/1/on_off` |
| `matter/geraet1/1/helligkeit` | `matter/device1/1/brightness` |
| `matter/geraet1/1/energie_bezug` | `matter/device1/1/energy_import` |
| `matter/geraet1/erreichbar` | `matter/device1/available` |
| `matter/status/zaehler` | `matter/status/counter` |
| `matter/befehl/geraet1/1/helligkeit` | `matter/command/device1/1/brightness` |
| `matter/befehl/geraet1/1/helligkeit/quittung` | `matter/command/device1/1/brightness/acknowledgement` |

The brightness command remains `publish <topic> <v.0>` in the Loxone template.
Commands must never be retained. Permissions, ranges, duplicate suppression,
lock restrictions and command expiry use the existing internal implementation.
German or mixed command names are rejected when English is selected.

## Migrating an existing installation

Changing this setting changes integration identifiers. It does not update an
already imported Loxone project. Plan the change as a coordinated MQTT/Loxone
migration, with a saved Loxone project and plugin configuration.

1. Record the current schema and existing Loxone links. Stop sending commands
   from the old configuration during the change.
2. Select the intended schema on the MQTT tab and save.
3. Use the updated names on **Integration in Loxone**, configure the gateway
   subscriptions/Noncached/Resetaftersend selections and import the matching
   generated command templates. Reconnect the Loxone logic to the new names.
4. Verify feedback and a deliberately chosen harmless command before restoring
   normal automation. A physical deployment and this validation are separate
   from the local automated tests.

The service sends a full state snapshot and heartbeat after a schema change.
Previously seen button pulses are not replayed just because the language changed.
It removes known retained device topics in the previous language and asks the
broker to confirm removal. If confirmation fails, cleanup is retried at most
once per minute. A pending cleanup marker survives service restarts and upgrades.
It does not delete unknown topics, raw data, command topics or the selected
schema. A language change is not considered proof that old retained values have
already disappeared: check the service log if the broker is unavailable.

To revert, select German and restore the matching Loxone configuration. The same
cleanup/resend mechanism applies in the reverse direction. Returning to older
plugin code should be preceded by selecting German and completing that migration;
old code cannot publish the new English schema.

## Compatibility boundaries

- The optional shared `haus/tuer/...` namespace stays in German. It is a cross-plugin
  LoxBerry convention, not a Matter2Lox-specific schema.
- HTTP API parameter names and response matchers stay stable; generated visible
  titles can be English without changing that API.
- Diagnostic logs, acknowledgement payload codes and internal configuration keys
  retain their established meanings/spellings. This feature does not translate
  every string in the original project or the original illustrative screenshots.
- User-supplied device names are never automatically translated.
- A schema change preserves commissioning/fabric data and does not contact BLE,
  reset a device or change the MR4U or commissioner gateway.

## Implementation and verification

`templates/matter_cluster.json` contains the shared `mqtt_english` mapping. Python
translates at the publication boundary; PHP uses the same table for exports,
gateway names and command transport. The shared command bridge now has optional
relative-topic encoder/decoder hooks; absent hooks preserve its original behavior.
Its canonical command table and safety checks are unchanged.

`tests/test_mqtt_language.py` tests wire bytes, retained/transient state, full
mapping parity, command dispatch and rejection, old/default settings, templates,
gateway reset names, namespace-scoped cleanup and restart/retry behavior.
`tests/fixtures/mqtt_legacy.json` uses synthetic device data to verify unchanged
German exports and lists against the upstream 0.9.49 baseline (`f15d1a5`).

```sh
python -m unittest discover -s tests -v
php bin/befehlsbruecke.php --selbsttest
```

Requires Python 3.10+, PHP CLI and `pip install -r tests/requirements-test.txt`.
MQTT wire tests mock the local transport and use temporary directories without a
live broker or radio.

Local validation: 13 MQTT integration tests and 122 command-bridge cases passed
with Python 3.13.7, websockets 15.0.1 and PHP 8.5.1. PHP syntax and patch whitespace
checks passed. The host's old macOS Bash 3.2 rejects a pre-existing construct in
`uninstall/uninstall`; the same failure occurs in the upstream baseline.
The only uninstall change removes the new schema marker alongside the existing
prefix marker. Debian/LoxBerry installation, a real broker and Loxone import remain
to be validated. No live system was modified during development.
