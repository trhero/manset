<?php
/**
 * Gazete teması — alt bilgi.
 * Kanca: ad_slot('footer') + künye bağlantısı (CONTRACTS §5.5, BİK m.4).
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

$sayfalar = pages_footer();
$menuKat  = menu();
?>
<footer class="site-alt">
  <div class="konteyner">
    <?= ad_slot('footer') ?>

    <div class="alt-sutunlar">
      <div class="alt-hakkinda">
        <h3><?= esc(site('title')) ?></h3>
        <?php if (site('desc')): ?><p><?= esc(site('desc')) ?></p><?php endif; ?>
        <?php if (site('email')): ?>
          <p class="alt-iletisim"><a href="mailto:<?= esc(site('email')) ?>"><?= esc(site('email')) ?></a></p>
        <?php endif; ?>
      </div>

      <div>
        <h3>Kategoriler</h3>
        <ul>
          <?php foreach ($menuKat as $c): ?>
            <li><a href="<?= esc(url_category($c)) ?>"><?= esc($c['name']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div>
        <h3>Kurumsal</h3>
        <ul>
          <li><a href="<?= esc(url('kunye')) ?>"><strong>Künye</strong></a></li>
          <?php foreach ($sayfalar as $p): ?>
            <li><a href="<?= esc(url_page($p)) ?>"><?= esc($p['title']) ?></a></li>
          <?php endforeach; ?>
          <li><a href="<?= esc(base_url()) ?>/rss.php">RSS</a></li>
          <li><a href="<?= esc(base_url()) ?>/sitemap.php">Site haritası</a></li>
        </ul>
      </div>
    </div>

    <p class="telif">
      © <?= esc(site('year')) ?> <?= esc(site('title')) ?>. Tüm hakları saklıdır.
      <span class="uretici">Manşet ile hazırlandı.</span>
    </p>
  </div>
</footer>
