<?php
/**
 * Gazete teması — manşet bloğu.
 * Beklenen değişkenler: $items (haber dizisi), $baslik (isteğe bağlı)
 * Yerleşim: 1 büyük kapak + sağda dikey liste + altında ikinci sıra.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
if (empty($items) || !is_array($items)) { return; }

$liste = array_values($items);
$ilk   = array_shift($liste);
$yan   = array_slice($liste, 0, 3);
$alt   = array_slice($liste, 3);
?>
<section class="manset-blok" aria-label="<?= esc(isset($baslik) && $baslik !== '' ? $baslik : 'Manşet') ?>">
  <div class="manset-ana">
    <?php part('card', ['post' => $ilk, 'boyut' => 'buyuk']); ?>
  </div>

  <?php if ($yan): ?>
    <div class="manset-yan">
      <?php foreach ($yan as $p) { part('card', ['post' => $p, 'boyut' => 'kucuk']); } ?>
    </div>
  <?php endif; ?>

  <?php if ($alt): ?>
    <div class="manset-alt">
      <?php foreach ($alt as $p) { part('card', ['post' => $p, 'boyut' => 'orta']); } ?>
    </div>
  <?php endif; ?>
</section>
