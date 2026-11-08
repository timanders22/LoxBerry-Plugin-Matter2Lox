<?php
// Only rendered by the local test runner; this is not installed as a plugin endpoint.
require dirname(__DIR__, 2) . '/webfrontend/html/mt_lib.php';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Matter2Lox QR test</title>
<style>body{font:16px sans-serif;margin:16px;max-width:560px}input[type=text]{box-sizing:border-box;width:100%;font-size:16px;padding:10px}button{font-size:16px;padding:10px;margin:4px 0}.sm-knopfreihe{display:flex;flex-wrap:wrap;gap:8px}.sm-hilfe{font-size:14px;line-height:1.5}.sm-btn{display:inline-block}label{display:block;margin:12px 0}</style></head><body>
<h1>Matter2Lox</h1><h2>Commission device</h2><a class="sm-tab" data-ziel="tab-settings" href="#settings">Settings</a>
<form action="/commission" method="post" autocomplete="off">
<label for="code">QR code or pairing code</label><input id="code" name="code" type="text" value="" autocomplete="off">
<?php require dirname(__DIR__, 2) . '/webfrontend/htmlauth/mt_qr.php'; ?>
<label><input type="checkbox" name="nur_netz" id="network-only" value="1"> Search on the network only, no Bluetooth</label>
<label for="anlern_ip">Optional device IP address</label><input type="text" id="anlern_ip" name="anlern_ip">
<button type="submit">Commission</button></form><script src="/qr-scanner.js" defer></script></body></html>
