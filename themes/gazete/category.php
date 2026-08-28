<?php
/**
 * Gazete teması — kategori listesi.
 * Beklenen değişkenler: $category, $posts, $total, $page, $perPage
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
if (empty($category) || !is_array($category)) { return; }

$cat   = $category;
$renk  = (string)arr($cat, 'color', '');
$liste = isset($posts) && is_array($posts) ? $posts : [];
$one   = ($page <= 1 && $liste) ? array_shift($liste) : null;
?>
<div class="liste iki-sutun">
  <div class="ana-sutun">

    <?= breadcrumbs([
      ['label' => 'Anasayfa', 'url' => url()],
      ['label' => (string)$cat['name'], 'url' => url_category($cat)],
    ]) ?>

    <header class="liste-ust"<?= $renk !== '' ? ' style="--kat:' . esc($renk) . '"' : '' ?>>
      <h1><?= esc($cat['name']) ?></h1>
      <p class="sayi"><?= (int)$total ?> haber<?= $page > 1 ? ' · ' . (int)$page . '. sayfa' : '' ?></p>
    </header>

    <?php if (!$posts): ?>
      <p class="bos">Bu kategoride henüz haber yok.
        <a href="<?= esc(url()) ?>">Anasayfaya dönün</a> ya da diğer kategorilere göz atın.</p>
    <?php else: ?>
      <?php if ($one): ?>
        <div class="liste-one"<?= $renk !== '' ? ' style="--kat:' . esc($renk) . '"' : '' ?>>
          <?php part('card', ['post' => $one, 'boyut' => 'buyuk']); ?>
        </div>
      <?php endif; ?>

      <?php if ($liste): ?>
        <div class="izgara">
          <?php foreach ($liste as $p) { part('card', ['post' => $p, 'boyut' => 'orta']); } ?>
        </div>
      <?php endif; ?>

      <?= paginate($total, $perPage, $page, function ($n) use ($cat) {
            return url('kategori/' . $cat['slug'], $n > 1 ? ['sayfa' => $n] : []);
          }) ?>
    <?php endif; ?>
  </div>

  <?php part('sidebar'); ?>
</div>
