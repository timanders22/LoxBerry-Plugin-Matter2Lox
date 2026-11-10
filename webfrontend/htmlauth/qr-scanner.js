/* Matter2Lox 0.9.50 (nach einem Vorschlag von centauri, PR #2): QR-Code im Browser
 * lesen und in das Feld "code" eintragen - eine reine Eingabehilfe. Sendet nichts ab,
 * laedt kein Bild hoch und merkt sich keinen Code. Der Decoder vendor/jsQR-1.4.0.js
 * (jsQR 1.4.0, Apache-2.0, Lizenz in vendor/jsQR-LICENSE) kommt aus diesem Ordner und
 * wird erst beim ersten Scan geladen; es gibt keinen Abruf nach aussen. */
(function () {
    'use strict';
    var root = document.getElementById('mt-qr-scanner');
    var field = document.getElementById('code');
    if (!root || !field) { return; }
    var text = JSON.parse(root.getAttribute('data-messages'));
    var base = document.currentScript.src;
    var live = root.querySelector('[data-qr="live"]');
    var stopButton = root.querySelector('[data-qr="stop"]');
    var photo = root.querySelector('[data-qr="photo"]');
    var file = root.querySelector('[data-qr="file"]');
    var video = root.querySelector('video');
    var status = root.querySelector('[role="status"]');
    var canvas = document.createElement('canvas');
    var context = canvas.getContext('2d', { willReadFrequently: true });
    var stream = null;
    var timer = null;
    var generation = 0;
    var cancelImage = null;
    var decoderPromise = null;
    var secureCamera = window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia;

    function message(key) { status.textContent = text[key]; }
    function release(media) {
        if (media) { media.getTracks().forEach(function (track) { track.stop(); }); }
    }
    function stop() {
        generation++;
        clearTimeout(timer);
        timer = null;
        release(stream);
        stream = null;
        video.pause();
        video.srcObject = null;
        video.hidden = true;
        stopButton.hidden = true;
        live.disabled = !secureCamera;
        if (cancelImage) { cancelImage(); }
        canvas.width = canvas.height = 0;
    }
    function decoder() {
        if (typeof window.jsQR === 'function') { return Promise.resolve(window.jsQR); }
        if (!decoderPromise) {
            decoderPromise = new Promise(function (resolve, reject) {
                var script = document.createElement('script');
                script.src = new URL('vendor/jsQR-1.4.0.js', base).href;
                script.onload = function () {
                    if (typeof window.jsQR === 'function') { resolve(window.jsQR); }
                    else { decoderPromise = null; reject(new Error('decoder')); }
                };
                script.onerror = function () {
                    script.remove();
                    decoderPromise = null;
                    reject(new Error('decoder'));
                };
                document.head.appendChild(script);
            });
        }
        return decoderPromise;
    }
    function scan(source, width, height, maxSide, read) {
        if (!width || !height) { return null; }
        var scale = Math.min(1, maxSide / Math.max(width, height));
        canvas.width = Math.max(1, Math.round(width * scale));
        canvas.height = Math.max(1, Math.round(height * scale));
        context.drawImage(source, 0, 0, canvas.width, canvas.height);
        var pixels = context.getImageData(0, 0, canvas.width, canvas.height);
        return read(pixels.data, pixels.width, pixels.height, { inversionAttempts: 'attemptBoth' });
    }
    function accept(result) {
        if (!result) { return false; }
        // Dieselbe Zeichen- und Laengengrenze wie im Dienst (matter_dienst.py). Den
        // Inhalt prueft der Matter-Server. Fremde QR-Codes (etwa Adressen) werden
        // weder geoeffnet noch angezeigt.
        var code = String(result.data || '').trim();
        if (!/^MT:[A-Z0-9.\-]{5,60}$/.test(code)) {
            message('wrong');
            return false;
        }
        stop();
        field.value = code;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
        message('found');
        var ip = document.getElementById('anlern_ip');
        if (ip && ip.value.trim()) { status.textContent += ' ' + text.ip; }
        field.focus();
        return true;
    }
    async function start() {
        stop();
        if (!secureCamera) { message('https'); return; }
        var token = generation;
        live.disabled = true;
        stopButton.hidden = false; // bricht auch eine offene Rueckfrage nach der Kamera ab
        message('starting');
        try {
            // Nur nach einem Klick, nie beim Laden der Seite.
            var media = await navigator.mediaDevices.getUserMedia({
                audio: false,
                video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }
            });
            if (token !== generation) { release(media); return; }
            stream = media;
            media.getTracks().forEach(function (track) {
                track.addEventListener('ended', function () {
                    if (token === generation) { stop(); message('stopped'); }
                });
            });
            video.srcObject = media;
            video.muted = true;
            video.hidden = false;
            await video.play();
            var read = await decoder();
            if (token !== generation) { return; }
            message('aim');
            function tick() {
                if (token !== generation) { return; }
                try {
                    if (video.readyState >= 2 && accept(scan(video, video.videoWidth, video.videoHeight, 960, read))) { return; }
                    timer = setTimeout(tick, 250);
                } catch (_) {
                    stop();
                    message('failed');
                }
            }
            tick();
        } catch (error) {
            if (token !== generation) { return; }
            stop();
            message(error && error.name === 'NotAllowedError' ? 'denied' : 'failed');
        }
    }
    function loadImage(blob) {
        return new Promise(function (resolve, reject) {
            var image = new Image();
            var url = URL.createObjectURL(blob);
            function finish() {
                URL.revokeObjectURL(url);
                image.onload = image.onerror = null;
                cancelImage = null;
            }
            cancelImage = function () {
                finish();
                image.src = '';
                resolve(null);
            };
            image.onload = function () { finish(); resolve(image); };
            image.onerror = function () { finish(); reject(new Error('image')); };
            image.src = url;
        });
    }
    async function imageSelected(input) {
        var blob = input.files && input.files[0];
        input.value = ''; // dasselbe Bild laesst sich erneut waehlen; wird nie abgeschickt
        if (!blob) { return; }
        stop();
        var token = generation;
        if (blob.size > 20 * 1024 * 1024) { message('large'); return; }
        if (!/^image\//.test(blob.type) || blob.type === 'image/svg+xml') { message('image'); return; }
        stopButton.hidden = false;
        message('reading');
        var image = null;
        try {
            var read = await decoder();
            if (token !== generation) { return; }
            image = await loadImage(blob);
            if (!image || token !== generation) { return; }
            if (image.naturalWidth * image.naturalHeight > 50000000) { message('large'); return; }
            var result = scan(image, image.naturalWidth, image.naturalHeight, 960, read);
            if (!result) { result = scan(image, image.naturalWidth, image.naturalHeight, 1920, read); }
            if (!result) { message('missing'); }
            else { accept(result); }
        } catch (error) {
            if (token === generation) { message(error.message === 'decoder' ? 'failed' : 'image'); }
        } finally {
            if (image) { image.src = ''; }
            if (token === generation) { stop(); }
        }
    }
    if (!context) { return; } // ohne Canvas bleibt die Eingabe von Hand
    root.hidden = false;
    live.disabled = !secureCamera;
    message(secureCamera ? 'ready' : 'https');
    live.addEventListener('click', start);
    stopButton.addEventListener('click', function () { stop(); message('stopped'); });
    root.querySelector('[data-qr="take"]').addEventListener('click', function () { stop(); message(secureCamera ? 'ready' : 'https'); photo.click(); });
    root.querySelector('[data-qr="choose"]').addEventListener('click', function () { stop(); message(secureCamera ? 'ready' : 'https'); file.click(); });
    photo.addEventListener('change', function () { imageSelected(photo); });
    file.addEventListener('change', function () { imageSelected(file); });
    field.addEventListener('input', function (event) { if (event.isTrusted) { stop(); message('stopped'); } });
    field.form.addEventListener('submit', stop);
    document.addEventListener('visibilitychange', function () { if (document.hidden) { stop(); message('stopped'); } });
    window.addEventListener('pagehide', stop);
    // Die Reiterleiste blendet Reiter um, ohne die Seite zu verlassen.
    document.querySelectorAll('.sm-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            if (tab.dataset.ziel !== 'tab-commission') { stop(); message('stopped'); }
        });
    });
}());
