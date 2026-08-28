<?php
/** Yerel teması — sabit sayfa ve künye. */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
$sidebarPos = theme_setting('sidebar_position', 'right');
$bolge = trim((string)theme_setting('bolge_adi', ''));
?>
<div class="liste <?= $sidebarPos === 'none' ? 'tam' : 'iki-sutun' ?>">
  <article class="ana-sutun sabit-sayfa">
    <header class="liste-ust">
      <?php if (!empty($isKunye)): ?>
        <p class="ust-etiket">İletişim ve künye<?= $bolge !== '' ? ' · ' . esc($bolge) : '' ?></p>
      <?php endif; ?>
      <h1><?= esc($page['title']) ?></h1>
    </header>
    <div class="haber-govde"><?= sanitize_html((string)$page['body'], true) ?></div>
  </article>
  <?php part('sidebar'); ?>
</div>
