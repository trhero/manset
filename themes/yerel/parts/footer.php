<?php
/** Yerel teması — alt bilgi. Künye bağlantısı zorunludur (BİK notları §2). */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
$bolge = trim((string)theme_setting('bolge_adi', ''));
?>
<footer class="site-alt">
  <div class="konteyner">
    <?= ad_slot('footer') ?>

    <div class="alt-sutunlar">
      <div>
        <h3><?= esc(site('title')) ?></h3>
        <?php if ($bolge !== ''): ?><p class="alt-bolge"><?= esc($bolge) ?> ve çevresinin haber kaynağı</p><?php endif; ?>
        <?php if (site('desc')): ?><p class="soluk"><?= esc(site('desc')) ?></p><?php endif; ?>
        <?php if (site('email')): ?>
          <p><a href="mailto:<?= esc(site('email')) ?>"><?= esc(site('email')) ?></a></p>
        <?php endif; ?>
      </div>
      <div>
        <h3>Kategoriler</h3>
        <ul>
          <?php foreach (menu() as $c): ?>
            <li><a href="<?= esc(url_category($c)) ?>"><?= esc($c['name']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div>
        <h3>Kurumsal</h3>
        <ul>
          <li><a href="<?= esc(url('kunye')) ?>">Künye</a></li>
          <?php foreach (pages_footer() as $p): ?>
            <li><a href="<?= esc(url_page($p)) ?>"><?= esc($p['title']) ?></a></li>
          <?php endforeach; ?>
          <li><a href="<?= esc(base_url()) ?>/rss.php">RSS</a></li>
          <?php /* 1.3-10: keşif sayfaları alt bilgiden de erişilebilir olmalı;
             yoksa okur onlara yalnız bir haberin içinden ulaşabilirdi. Arşiv
             yılı VERİDEN gelir, içeriği olmayan yıla bağlantı verilmez. */ ?>
          <li><a href="<?= esc(url('etiketler')) ?>">Etiketler</a></li>
          <?php if (function_exists('discover_archive_years')): $kesifYillar = discover_archive_years(); ?>
            <?php if ($kesifYillar): ?>
              <li><a href="<?= esc(url('arsiv/' . (int)array_key_first($kesifYillar))) ?>">Arşiv</a></li>
            <?php endif; ?>
          <?php endif; ?>
        </ul>
      </div>
    </div>

    <?php /* Okuma ayarları her sayfada erişilebilir olsun diye alt bilgide de basılır
             (1.2-13). Beş temada da aynı parça; tema seçimi bu özelliği düşürmez. */ ?>
    <div class="alt-okuma"><?php part('okuma-ayarlari', ['yer' => 'alt']); ?></div>

    <p class="telif">© <?= esc(site('year')) ?> <?= esc(site('title')) ?>. Tüm hakları saklıdır.
      <span class="uretici">Manşet ile hazırlandı.</span></p>
  </div>
</footer>
