<?php
/** Bülten teması — kategori (bölüm) akışı. */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
$cat = $category;
$sira = 0;
?>
<div class="liste<?= theme_setting('sidebar_position', 'none') === 'none' ? '' : ' kenarli' ?>">
  <div class="akis-sutun">
    <header class="akis sayfa-ust">
      <p class="ust-etiket">Bölüm</p>
      <h1><?= esc($cat['name']) ?></h1>
      <p class="soluk"><?= (int)$total ?> yazı</p>
    </header>

    <?php if (!$posts): ?>
      <div class="akis"><p class="soluk">Bu bölümde henüz yazı yok.</p></div>
    <?php else: ?>
      <div class="akis blok">
        <?php foreach ($posts as $p) { $sira++; part('card', ['post' => $p, 'boyut' => $sira === 1 ? 'kapak' : 'orta']); } ?>
      </div>
      <div class="akis"><?= paginate($total, $perPage, $page, function ($n) use ($cat) {
            return url('kategori/' . $cat['slug'], $n > 1 ? ['sayfa' => $n] : []);
          }) ?></div>
    <?php endif; ?>
  </div>
  <?php part('sidebar'); ?>
</div>
