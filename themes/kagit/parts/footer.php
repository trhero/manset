<?php
/**
 * Kâğıt teması — alt bilgi (kolofon).
 * Kanca: ad_slot('footer') + künye bağlantısı (CONTRACTS §5.5, BİK m.4).
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

$sayfalar = pages_footer();
?>
<footer class="kolofon">
  <div class="konteyner">
    <?= ad_slot('footer') ?>

    <hr class="cizgi-kalin">

    <div class="kolofon-sutunlar">
      <div>
        <h3><?= esc(site('title')) ?></h3>
        <?php if (site('desc')): ?><p><?= esc(site('desc')) ?></p><?php endif; ?>
        <?php if (site('email')): ?>
          <p><a href="mailto:<?= esc(site('email')) ?>"><?= esc(site('email')) ?></a></p>
        <?php endif; ?>
      </div>

      <div>
        <h3>Bölümler</h3>
        <ul>
          <?php foreach (menu() as $c): ?>
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
          <?php /* 1.3-10: keşif sayfaları alt bilgiden de erişilebilir olmalı;
             yoksa okur onlara yalnız bir haberin içinden ulaşabilirdi. Arşiv
             yılı VERİDEN gelir, içeriği olmayan yıla bağlantı verilmez. */ ?>
          <li><a href="<?= esc(url('etiketler')) ?>">Etiketler</a></li>
          <?php if (function_exists('discover_archive_years')): $kesifYillar = discover_archive_years(); ?>
            <?php if ($kesifYillar): ?>
              <li><a href="<?= esc(url('arsiv/' . (int)array_key_first($kesifYillar))) ?>">Arşiv</a></li>
            <?php endif; ?>
          <?php endif; ?>
          <li><a href="<?= esc(base_url()) ?>/sitemap.php">Site haritası</a></li>
        </ul>
      </div>
    </div>

    <hr class="cizgi-ince">

    <?php /* Okuma ayarları her sayfada erişilebilir olsun diye alt bilgide de basılır
             (1.2-13). Beş temada da aynı parça; tema seçimi bu özelliği düşürmez. */ ?>
    <div class="alt-okuma"><?php part('okuma-ayarlari', ['yer' => 'alt']); ?></div>

    <p class="telif">
      © <?= esc(site('year')) ?> <?= esc(site('title')) ?> · Tüm hakları saklıdır.
      <span class="uretici">Manşet ile dizildi.</span>
    </p>
  </div>
</footer>
