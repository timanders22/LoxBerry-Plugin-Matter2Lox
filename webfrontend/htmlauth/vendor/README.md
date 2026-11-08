# Local QR decoder

`jsQR-1.4.0.js` is the unmodified `dist/jsQR.js` from jsqr 1.4.0.

- Upstream: https://github.com/cozmo/jsQR
- Package: https://registry.npmjs.org/jsqr/-/jsqr-1.4.0.tgz
- License: Apache-2.0, included as `jsQR-LICENSE` from the package.
- File SHA-256: `bc40c8a15196236b2314db0856f72ca0b49980cd5413b8c852a7349f5fee0859`
- npm package integrity: `sha512-dxLob7q65Xg2DvstYkRpkYtmKm2sPJ9oFhrhmudT1dZvNFFTlroai3AWSpLey/w5vMcLBXRgOJsbXpdN9HzU/A==`

The commissioning UI loads this file from the same authenticated plugin directory
only after a scan action. There is no CDN, analytics, image upload, or decoder
service. Updating this dependency requires repeating the real-pixel browser tests
in `tests/qr/`.
