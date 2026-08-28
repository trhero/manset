<?php
/** Gece teması — kategori listesi. */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
$cat = $category;
$sidebarPos = theme_setting('sidebar_position', 'right');
?>
<div class="liste <?= $sidebarPos === 'none' ? 'tam' : 'iki-sutun' ?>">
  <div class="ana-sutun">
    <header class="liste-ust" style="--kat:<?= esc($cat['color']) ?>">
      <p class="ust-etiket mono">Kategori</p>
      <h1><?= esc($cat['name']) ?></h1>
      <p class="soluk mono"><?= (int)$total ?> haber</p>
    </header>

    <?php if (!$posts): ?>
      <p class="soluk">Bu kategoride henüz haber yok.</p>
    <?php else: ?>
      <div class="izgara">
        <?php foreach ($posts as $p) { part('card', ['post' => $p, 'boyut' => 'orta']); } ?>
      </div>
      <?= paginate($total, $perPage, $page, function ($n) use ($cat) {
            return url('kategori/' . $cat['slug'], $n > 1 ? ['sayfa' => $n] : []);
          }) ?>
    <?php endif; ?>
  </div>
  <?php part('sidebar'); ?>
</div>
