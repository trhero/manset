<?php
/**
 * Kâğıt teması — bölüm (kategori) sayfası.
 * Yerleşim: geniş "bölüm başlığı" + altında sütunlara dağılmış haberler.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
if (empty($category) || !is_array($category)) { return; }

$cat   = $category;
$liste = isset($posts) && is_array($posts) ? $posts : [];
$one   = ($page <= 1 && $liste) ? array_shift($liste) : null;
$sutunSayisi = theme_setting('sutun_sayisi', '3') === '2' ? 2 : 3;
?>
<div class="liste iki-sutun">
  <div class="ana-sutun">

    <?= breadcrumbs([
      ['label' => 'Anasayfa', 'url' => url()],
      ['label' => (string)$cat['name'], 'url' => url_category($cat)],
    ]) ?>

    <header class="bolum-ust">
      <hr class="cizgi-kalin">
      <h1><?= esc($cat['name']) ?></h1>
      <p class="bolum-sayi"><?= (int)$total ?> haber<?= $page > 1 ? ' · ' . (int)$page . '. sayfa' : '' ?></p>
      <hr class="cizgi-ince">
    </header>

    <?php if (!$posts): ?>
      <p class="bos">Bu bölümde henüz haber yok.
        <a href="<?= esc(url()) ?>">Kapağa dönün</a>.</p>
    <?php else: ?>
      <?php if ($one): ?>
        <div class="bolum-one">
          <?php part('card', ['post' => $one, 'boyut' => 'buyuk']); ?>
          <hr class="cizgi-kalin">
        </div>
      <?php endif; ?>

      <?php if ($liste): ?>
        <div class="sayfa" data-sutun="<?= (int)$sutunSayisi ?>">
          <?php
            $sutunlar = array_fill(0, $sutunSayisi, []);
            foreach (array_values($liste) as $i => $p) { $sutunlar[$i % $sutunSayisi][] = $p; }
            foreach ($sutunlar as $sutun): ?>
            <div class="sutun">
              <?php foreach ($sutun as $p) { part('card', ['post' => $p, 'boyut' => 'orta']); } ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?= paginate($total, $perPage, $page, function ($n) use ($cat) {
            return url('kategori/' . $cat['slug'], $n > 1 ? ['sayfa' => $n] : []);
          }) ?>
    <?php endif; ?>
  </div>

  <?php part('sidebar'); ?>
</div>
