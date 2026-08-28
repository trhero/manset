<?php
/**
 * Haber sonu gezinme — önceki / sonraki haber (1.3-10).
 * Beklenen: $post
 *
 * Beş temanın ortak parçasıdır: part() temada `parts/haber-gezinme.php`
 * bulamazsa buraya düşer. Bu yüzden yalnız tema belirteçleriyle boyanır.
 *
 * Modül yoksa (inc/discover.php kurulu değilse) HİÇBİR ŞEY basılmaz — tema
 * bozulmaz, yalnız gezinme çıkmaz.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
if (empty($post) || !is_array($post)) { return; }
if (!function_exists('discover_adjacent_posts')) { return; }

$komsu = discover_adjacent_posts($post);
if (empty($komsu['prev']) && empty($komsu['next'])) { return; }
?>
<nav class="haber-gezinme" aria-label="Haber gezinmesi">
  <?php if (!empty($komsu['prev'])): ?>
    <a class="gezinme-oge onceki" href="<?= esc(url_post($komsu['prev'])) ?>" rel="prev">
      <span class="gezinme-yon">‹ Önceki haber</span>
      <span class="gezinme-baslik"><?= esc($komsu['prev']['title']) ?></span>
    </a>
  <?php else: ?>
    <span class="gezinme-oge bos" aria-hidden="true"></span>
  <?php endif; ?>

  <?php if (!empty($komsu['next'])): ?>
    <a class="gezinme-oge sonraki" href="<?= esc(url_post($komsu['next'])) ?>" rel="next">
      <span class="gezinme-yon">Sonraki haber ›</span>
      <span class="gezinme-baslik"><?= esc($komsu['next']['title']) ?></span>
    </a>
  <?php endif; ?>
</nav>
