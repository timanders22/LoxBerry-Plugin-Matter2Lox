<?php
/* Included inside the existing authenticated commissioning form. No upload endpoint. */
$mt_qr_texte = array();
foreach (array('ready', 'https', 'starting', 'aim', 'found', 'wrong', 'missing',
               'reading', 'denied', 'failed', 'image', 'large', 'stopped', 'ip') as $mt_qr_key) {
    $mt_qr_texte[$mt_qr_key] = mt_t('ANLERN.QR_' . strtoupper($mt_qr_key));
}
?>
<style>#mt-qr-scanner[hidden], #mt-qr-scanner [hidden] { display: none !important; }</style>
<div id="mt-qr-scanner" class="sm-feld" hidden data-messages="<?= mt_e(json_encode($mt_qr_texte, JSON_UNESCAPED_UNICODE)) ?>">
  <div class="sm-knopfreihe">
    <button data-role="none" type="button" class="sm-btn sm-b-lesen" data-qr="live"><?= mt_e(mt_t('ANLERN.QR_LIVE')) ?></button>
    <button data-role="none" type="button" class="sm-btn sm-b-lesen" data-qr="take"><?= mt_e(mt_t('ANLERN.QR_TAKE')) ?></button>
    <button data-role="none" type="button" class="sm-btn sm-b-lesen" data-qr="choose"><?= mt_e(mt_t('ANLERN.QR_CHOOSE')) ?></button>
    <button data-role="none" type="button" class="sm-btn sm-b-aktion" data-qr="stop" hidden><?= mt_e(mt_t('ANLERN.QR_STOP')) ?></button>
  </div>
  <!-- No name attributes: selected images are never part of the form POST. -->
  <input data-role="none" data-qr="photo" type="file" accept="image/*" capture="environment" hidden aria-label="<?= mt_e(mt_t('ANLERN.QR_TAKE')) ?>">
  <input data-role="none" data-qr="file" type="file" accept="image/*" hidden aria-label="<?= mt_e(mt_t('ANLERN.QR_CHOOSE')) ?>">
  <video muted playsinline hidden style="display:block;max-width:100%;width:480px;border-radius:6px;margin:8px 0" aria-label="<?= mt_e(mt_t('ANLERN.QR_PREVIEW')) ?>"></video>
  <p class="sm-hilfe" role="status" aria-live="polite"></p>
  <div class="sm-hilfe"><?= mt_e(mt_t('ANLERN.QR_PRIVACY')) ?></div>
</div>
<noscript><p class="sm-hilfe"><?= mt_e(mt_t('ANLERN.QR_NO_JS')) ?></p></noscript>
