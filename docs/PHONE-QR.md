# Scan Matter QR codes in the commissioning page

The existing authenticated Matter2Lox commissioning form supports live camera
scanning, taking a photo and choosing an image. No separate mobile app or gateway
is required. Scanning fills the code field; the user must still press Commission.
It does not turn the phone into a Bluetooth commissioner.

## Use

1. Open LoxBerry in the phone browser, sign in and open Matter2Lox's commissioning tab.
2. Choose **Scan with camera**, **Take QR photo** or **Choose QR image**.
3. Check the recognized code and commissioning options, then press **Commission**.

Live camera access requires HTTPS with a certificate trusted by the phone and a
browser that supports camera access. On an insecure HTTP page the live camera
button is disabled with an explanation. Photo/image input and manual entry remain
available. The browser decides whether the photo button opens the camera directly
or presents a chooser. If a format is unsupported, use JPEG, PNG or a screenshot.
The scanner limits files to 20 MiB and decoded dimensions to 50 megapixels, and
processes downscaled images.

For Multi-Admin, open a new sharing window in the existing controller and scan
its newly generated code. Keep the appropriate network-only option selected.
An explicit IP address uses the existing numeric-code route; the scanner warns
when an IP is still filled in. Existing network reachability requirements apply.

## Privacy and behavior

- The bundled jsQR decoder runs in the browser. No CDN, image upload or external
  decoding service is used. Images are not included in the form submission.
- Codes are not stored in cookies, browser storage or URLs. The existing form
  sends the code only when the user submits it.
- Non-Matter QR codes are rejected and QR URLs are never opened. Complete payload
  validation remains with the existing Matter server.
- Camera tracks stop on recognition, Stop, submission, tab changes or leaving/
  hiding the page. A late permission grant after cancellation is also cleaned up.
- Manual entry remains available if JavaScript or the decoder cannot run.
- Commissioning options, backend selection and existing access controls are unchanged.

## Verification

The contributor reports successful camera scanning from the actual LoxBerry
plugin page over HTTPS. This is a user-confirmed practical check, not a claim
that every iPhone/Android browser or commissioning route has been tested.

The automated suite renders the real PHP partial and uses the bundled decoder
on generated QR pixels. It checks Chromium and WebKit with a mobile viewport:
photo recognition, HTTP fallback, invalid images/codes, denied and late camera
permission, camera cleanup, manual fallback and absence of automatic submission
or image uploads. A synthetic Chromium video stream exercises live decoding.
No live controller, real camera, radio or fabric is used by the automated suite.

```sh
cd tests/qr
npm ci
npx playwright install chromium webkit
npm test
```

Requires Node.js, PHP CLI and Python 3. The fixture generator uses the bundled
qrcodegen wheel; no Python package installation is needed. Set PYTHON to select
a Python executable. All 27 browser tests passed locally in Chromium and WebKit on 8 November 2026.

Decoder provenance, hash and license are recorded in
[the vendor README](../webfrontend/htmlauth/vendor/README.md).
