<?php
/**
 * Bülten teması — kenar çubuğu.
 * Varsayılan olarak KAPALIDIR (theme.json → sidebar_position = "none").
 * Açıldığında dahi ince, tipografik ve reklam slotlarını taşır.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
if (theme_setting('sidebar_position', 'none') === 'none') { return; }

$cokOkunan = most_read(5, 30);
?>
<aside class="kenar">
  <?= ad_slot('sidebar_1') ?>

  <?php if ($cokOkunan): ?>
    <section class="kenar-blok">
      <h2 class="kenar-baslik">Çok Okunanlar</h2>
      <ol class="kenar-liste">
        <?php foreach ($cokOkunan as $i => $p): ?>
          <li><span class="no"><?= $i + 1 ?></span><a href="<?= esc(url_post($p)) ?>"><?= esc($p['title']) ?><?= post_locked_badge($p) ?></a></li>
        <?php endforeach; ?>
      </ol>
    </section>
  <?php endif; ?>

  <?= ad_slot('sidebar_2') ?>

  <section class="kenar-blok">
    <h2 class="kenar-baslik">Bölümler</h2>
    <ul class="kenar-liste sade">
      <?php foreach (all_categories() as $c): ?>
        <li><a href="<?= esc(url_category($c)) ?>"><?= esc($c['name']) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </section>
</aside>
