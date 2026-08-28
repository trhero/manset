<?php
/**
 * Yerel teması — kenar çubuğu.
 * Nöbetçi eczane, etkinlik, vefat/ilan, çok okunanlar ve reklam slotları.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
if (theme_setting('sidebar_position', 'right') === 'none') { return; }

$eczane   = trim((string)theme_setting('eczane_metni', ''));
$etkinlik = trim((string)theme_setting('etkinlik_metni', ''));
$ilanSlot = (string)theme_setting('ilan_slot', 'sidebar_2');
$cokOkunan = most_read(6, 30);
?>
<aside class="kenar">
  <?php if ($ilanSlot !== 'sidebar_1') { echo ad_slot('sidebar_1'); } ?>

  <?php if ($eczane !== ''): ?>
    <section class="kutu bilgi-kutu eczane">
      <h2 class="kutu-baslik">Nöbetçi Eczane</h2>
      <p><?= esc($eczane) ?></p>
    </section>
  <?php endif; ?>

  <?php if ($etkinlik !== ''): ?>
    <section class="kutu bilgi-kutu etkinlik">
      <h2 class="kutu-baslik">Bugün Ne Var?</h2>
      <p><?= esc($etkinlik) ?></p>
    </section>
  <?php endif; ?>

  <?php part('ilan'); ?>

  <?php if ($cokOkunan): ?>
    <section class="kutu">
      <h2 class="kutu-baslik">Çok Okunanlar</h2>
      <ol class="sirali-liste">
        <?php foreach ($cokOkunan as $i => $p): ?>
          <li><span class="sira"><?= $i + 1 ?></span><a href="<?= esc(url_post($p)) ?>"><?= esc($p['title']) ?><?= post_locked_badge($p) ?></a></li>
        <?php endforeach; ?>
      </ol>
    </section>
  <?php endif; ?>

  <?php if ($ilanSlot !== 'sidebar_2') { echo ad_slot('sidebar_2'); } ?>

  <section class="kutu">
    <h2 class="kutu-baslik">Kategoriler</h2>
    <ul class="kategori-listesi">
      <?php foreach (all_categories() as $c): ?>
        <li><a href="<?= esc(url_category($c)) ?>" style="--kat:<?= esc($c['color']) ?>"><?= esc($c['name']) ?>
          <span class="adet"><?= (int)posts_count_by_category((int)$c['id']) ?></span></a></li>
      <?php endforeach; ?>
    </ul>
  </section>
</aside>
