<?php
/** Bülten teması — sabit sayfa ve künye. */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
?>
<div class="liste<?= theme_setting('sidebar_position', 'none') === 'none' ? '' : ' kenarli' ?>">
  <article class="akis-sutun sabit-sayfa">
    <header class="akis sayfa-ust">
      <?php if (!empty($isKunye)): ?><p class="ust-etiket">İletişim ve künye</p><?php endif; ?>
      <h1><?= esc($page['title']) ?></h1>
    </header>
    <div class="akis yazi-govde"><?= sanitize_html((string)$page['body'], true) ?></div>
  </article>
  <?php part('sidebar'); ?>
</div>
