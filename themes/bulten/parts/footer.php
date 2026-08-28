<?php
/** Bülten teması — alt bilgi. Künye bağlantısı zorunludur (BİK notları §2). */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
?>
<footer class="alt">
  <?php $reklamAlt = ad_slot('footer'); if ($reklamAlt !== ''): ?>
    <div class="akis bosluk-alt"><?= $reklamAlt ?></div>
  <?php endif; ?>

  <div class="akis alt-icerik">
    <div class="alt-marka">
      <h2><?= esc(site('title')) ?></h2>
      <?php if (site('desc')): ?><p class="soluk"><?= esc(site('desc')) ?></p><?php endif; ?>
    </div>

    <nav class="alt-baglar" aria-label="Alt bilgi bağlantıları">
      <a href="<?= esc(url('kunye')) ?>">Künye</a>
      <?php foreach (pages_footer() as $p): ?>
        <a href="<?= esc(url_page($p)) ?>"><?= esc($p['title']) ?></a>
      <?php endforeach; ?>
      <a href="<?= esc(base_url()) ?>/rss.php">RSS</a>
      <?php if (site('email')): ?><a href="mailto:<?= esc(site('email')) ?>"><?= esc(site('email')) ?></a><?php endif; ?>
    </nav>

    <p class="telif">© <?= esc(site('year')) ?> <?= esc(site('title')) ?>. Tüm hakları saklıdır.
      <span class="uretici">Manşet ile hazırlandı.</span></p>
  </div>
</footer>
