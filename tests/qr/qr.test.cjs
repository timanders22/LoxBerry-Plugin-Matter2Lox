/* Browser contract tests. No real camera, Matter endpoint or LoxBerry is used. */
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium, webkit } = require('playwright');
const root = path.resolve(__dirname, '../..');
const code = 'MT:Y.ABCDEFG123456789'; // Synthetic fixture, not a user's pairing code.
const makePng = text => execFileSync(process.env.PYTHON || 'python3', [path.join(__dirname, 'make_fixtures.py'), text]);
const qrPng = makePng(code);
const wrongPng = makePng('https://example.invalid/do-not-open');
const html = execFileSync('php', [path.join(__dirname, 'fixture.php')], {
    env: { ...process.env, LBHOMEDIR: '', LBPPLUGINDIR: '', LBLANG: 'en' }
});
let server, origin;
const browsers = {};
before(async () => {
    server = http.createServer((req, res) => {
        if (req.url === '/' || req.url === '/?fixture') {
            res.setHeader('Content-Type', 'text/html; charset=utf-8'); return res.end(html);
        }
        const names = { '/qr-scanner.js': 'qr-scanner.js', '/vendor/jsQR-1.4.0.js': 'vendor/jsQR-1.4.0.js' };
        if (names[req.url]) {
            res.setHeader('Content-Type', 'text/javascript');
            return res.end(fs.readFileSync(path.join(root, 'webfrontend/htmlauth', names[req.url])));
        }
        res.statusCode = 404; res.end();
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    origin = 'http://127.0.0.1:' + server.address().port;
    browsers.chromium = await chromium.launch({ headless: true });
    browsers.webkit = await webkit.launch({ headless: true });
});
after(async () => {
    for (const browser of Object.values(browsers)) { await browser.close(); }
    if (server) { await new Promise(resolve => server.close(resolve)); }
});
async function pageFor(t, browser, setup) {
    const context = await browsers[browser].newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
    t.after(() => context.close());
    const page = await context.newPage();
    const requests = [], errors = [];
    page.on('request', r => requests.push({ url: r.url(), method: r.method() }));
    page.on('pageerror', e => errors.push(e.message));
    await page.addInitScript(() => { window.cameraCalls = 0; });
    if (setup) { await page.addInitScript(setup); }
    await page.goto(origin);
    await page.waitForFunction(() => !document.getElementById('mt-qr-scanner').hidden);
    t.after(() => assert.deepEqual(errors, []));
    return { page, requests };
}
const status = page => page.locator('[role=status]');
const upload = (page, buffer = qrPng, mimeType = 'image/png') => page.locator('[data-qr=file]').setInputFiles({ name: 'fixture.png', mimeType, buffer });

for (const browser of ['chromium', 'webkit']) {
    test(browser + ': no camera permission or decoder request before user action', async t => {
        const { page, requests } = await pageFor(t, browser, () => {
            navigator.mediaDevices.getUserMedia = () => { window.cameraCalls++; return Promise.reject(new Error('unexpected')); };
        });
        assert.equal(await page.evaluate(() => window.cameraCalls), 0);
        assert.equal(requests.some(r => r.url.includes('vendor')), false);
        assert.equal(await page.locator('video').isVisible(), false);
        assert.equal(await page.locator('[data-qr=stop]').isVisible(), false);
        assert.equal(await page.locator('[data-qr=photo]').getAttribute('capture'), 'environment');
    });
    test(browser + ': HTTP fallback reads real QR pixels locally, no auto-submit or credential changes', async t => {
        const { page, requests } = await pageFor(t, browser, () => Object.defineProperty(window, 'isSecureContext', { value: false }));
        assert.equal(await page.locator('[data-qr=live]').isDisabled(), true);
        assert.match(await status(page).textContent(), /HTTPS/);
        await page.locator('#network-only').check();
        await upload(page);
        await page.waitForFunction(c => document.getElementById('code').value === c, code);
        assert.equal(await page.locator('#network-only').isChecked(), true);
        assert.equal(await page.locator('[data-qr=stop]').isVisible(), false);
        assert.match(await status(page).textContent(), /Nothing has been sent/);
        assert.equal(requests.some(r => r.method === 'POST'), false);
        assert(requests.every(r => r.url.startsWith(origin + '/') || r.url.startsWith('blob:')));
        assert.equal(requests.some(r => r.url.includes(code)), false);
        const form = await page.evaluate(() => Array.from(new FormData(document.querySelector('form')).entries()));
        assert(form.every(([key, value]) => typeof value === 'string'));
        assert.equal(await page.evaluate(() => localStorage.length + sessionStorage.length), 0);
        await page.reload();
        assert.equal(await page.locator('#code').inputValue(), '');
    });
    test(browser + ': non-Matter QR is rejected without following URL or replacing the field', async t => {
        const { page, requests } = await pageFor(t, browser);
        await page.locator('#code').fill('34970112332');
        await upload(page, wrongPng);
        await page.waitForFunction(() => document.querySelector('[role=status]').textContent.includes('not a supported Matter'));
        assert.equal(await page.locator('#code').inputValue(), '34970112332');
        assert.equal(requests.some(r => r.url.includes('example.invalid')), false);
    });
    test(browser + ': image errors preserve manual input', async t => {
        const { page } = await pageFor(t, browser);
        await page.locator('#code').fill('34970112332');
        await upload(page, Buffer.from('not an image'));
        await page.waitForFunction(() => document.querySelector('[role=status]').textContent.includes('could not be opened'));
        assert.equal(await page.locator('#code').inputValue(), '34970112332');
        await upload(page, Buffer.alloc(21 * 1024 * 1024));
        assert.match(await status(page).textContent(), /too large/);
    });
    test(browser + ': existing IP is preserved with a specific QR/manual-code hint', async t => {
        const { page } = await pageFor(t, browser);
        await page.locator('#anlern_ip').fill('fd12::1234');
        await upload(page);
        await page.waitForFunction(c => document.getElementById('code').value === c, code);
        assert.equal(await page.locator('#anlern_ip').inputValue(), 'fd12::1234');
        assert.match(await status(page).textContent(), /IP route requires a manual/);
    });
    test(browser + ': camera denial offers image/manual fallback', async t => {
        const { page } = await pageFor(t, browser, () => {
            navigator.mediaDevices.getUserMedia = () => { window.cameraCalls++; return Promise.reject(new DOMException('denied', 'NotAllowedError')); };
        });
        await page.locator('[data-qr=live]').click();
        await page.waitForFunction(() => document.querySelector('[role=status]').textContent.includes('was denied'));
        assert.equal(await page.locator('[data-qr=stop]').isVisible(), false);
        assert.equal(await page.locator('[data-qr=live]').isDisabled(), false);
        assert.equal(await page.evaluate(() => window.cameraCalls), 1);
    });
    test(browser + ': cancellation releases a camera granted after the user stopped', async t => {
        const { page } = await pageFor(t, browser, () => {
            window.stoppedTracks = 0;
            navigator.mediaDevices.getUserMedia = () => new Promise(resolve => { window.grant = resolve; });
        });
        await page.locator('[data-qr=live]').click();
        await page.locator('[data-qr=stop]').click();
        await page.evaluate(() => window.grant({ getTracks: () => [{ stop: () => window.stoppedTracks++ }] }));
        await page.waitForFunction(() => window.stoppedTracks === 1);
        assert.equal(await page.locator('video').isVisible(), false);
        assert.equal(await page.locator('#code').inputValue(), '');
    });
    test(browser + ': leaving the commissioning tab cancels a pending camera request', async t => {
        const { page } = await pageFor(t, browser, () => {
            window.stoppedTracks = 0;
            navigator.mediaDevices.getUserMedia = () => new Promise(resolve => { window.grant = resolve; });
        });
        await page.locator('[data-qr=live]').click();
        await page.locator('.sm-tab').click();
        await page.evaluate(() => window.grant({ getTracks: () => [{ stop: () => window.stoppedTracks++ }] }));
        await page.waitForFunction(() => window.stoppedTracks === 1);
        assert.equal(await page.locator('[data-qr=stop]').isVisible(), false);
    });
    test(browser + ': pagehide cancels a pending camera request', async t => {
        const { page } = await pageFor(t, browser, () => {
            window.stoppedTracks = 0;
            navigator.mediaDevices.getUserMedia = () => new Promise(resolve => { window.grant = resolve; });
        });
        await page.locator('[data-qr=live]').click();
        await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
        await page.evaluate(() => window.grant({ getTracks: () => [{ stop: () => window.stoppedTracks++ }] }));
        await page.waitForFunction(() => window.stoppedTracks === 1);
    });
    test(browser + ': missing local decoder leaves manual input usable', async t => {
        const { page } = await pageFor(t, browser);
        await page.route('**/vendor/jsQR-1.4.0.js', route => route.abort());
        await upload(page);
        await page.waitForFunction(() => document.querySelector('[role=status]').textContent.includes('could not start'));
        await page.locator('#code').fill('34970112332');
        assert.equal(await page.locator('#code').inputValue(), '34970112332');
        assert.equal(await page.locator('[data-qr=stop]').isVisible(), false);
    });
    test(browser + ': mobile layout fits and widget screenshot', async t => {
        const { page } = await pageFor(t, browser, () => Object.defineProperty(window, 'isSecureContext', { value: false }));
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
        if (process.env.QR_SCREENSHOT_DIR) {
            await page.screenshot({ path: path.join(process.env.QR_SCREENSHOT_DIR, 'qr-' + browser + '.png'), fullPage: true });
        }
    });
}

test('chromium: live stream decodes a QR and stops the tracks after success', async t => {
    const { page } = await pageFor(t, 'chromium');
    await page.evaluate(async data => {
        const image = new Image(); image.src = data; await image.decode();
        const canvas = document.createElement('canvas'); canvas.width = canvas.height = 480;
        canvas.getContext('2d').drawImage(image, 0, 0, 480, 480);
        window.fixtureStream = canvas.captureStream(5);
        navigator.mediaDevices.getUserMedia = async options => { window.cameraOptions = options; return window.fixtureStream; };
        window.fixtureCanvas = canvas;
    }, 'data:image/png;base64,' + qrPng.toString('base64'));
    await page.locator('[data-qr=live]').click();
    await page.waitForFunction(c => document.getElementById('code').value === c, code);
    assert.equal(await page.evaluate(() => window.fixtureStream.getTracks()[0].readyState), 'ended');
    assert.equal(await page.evaluate(() => window.cameraOptions.audio), false);
    assert.equal(await page.locator('video').isVisible(), false);
});

for (const action of ['stop', 'ended', 'hidden', 'tab']) {
    test('chromium: active camera is released on ' + action, async t => {
        const { page } = await pageFor(t, 'chromium');
        await page.evaluate(() => {
            const canvas = document.createElement('canvas'); canvas.width = canvas.height = 320;
            canvas.getContext('2d').fillRect(0, 0, 320, 320);
            window.fixtureCanvas = canvas;
            window.fixtureStream = canvas.captureStream(5);
            navigator.mediaDevices.getUserMedia = async () => window.fixtureStream;
        });
        await page.locator('[data-qr=live]').click();
        await page.waitForFunction(() => document.querySelector('[role=status]').textContent.includes('Point the rear camera'));
        if (action === 'stop') { await page.locator('[data-qr=stop]').click(); }
        if (action === 'tab') { await page.locator('.sm-tab').click(); }
        if (action === 'ended') {
            await page.evaluate(() => window.fixtureStream.getTracks()[0].dispatchEvent(new Event('ended')));
        }
        if (action === 'hidden') {
            await page.evaluate(() => {
                Object.defineProperty(document, 'hidden', { value: true });
                document.dispatchEvent(new Event('visibilitychange'));
            });
        }
        assert.equal(await page.evaluate(() => window.fixtureStream.getTracks()[0].readyState), 'ended');
        assert.equal(await page.locator('video').isVisible(), false);
        assert.equal(await page.locator('[data-qr=stop]').isVisible(), false);
        assert.equal(await page.locator('#code').inputValue(), '');
    });
}
