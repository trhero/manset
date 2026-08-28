<?php
/**
 * Keşif — tarih arşivi (1.3-10). /arsiv/2026 · /arsiv/2026/08
 * Beklenen değişkenler: $yil, $ay, $yillar, $aySayilar, $posts, $total, $page, $perPage, $baseUrl
 *
 * Beş temanın ortak yedeği (bkz. kesif-yazar.php başlığı).
 * İçeriği OLMAYAN aya bağlantı basılmaz: discover_archive_view() boş/gelecek
 * tarihi bilerek 404'ler, dolayısıyla o bağlantı okuru duvara sürerdi.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

$yil       = isset($yil) ? (int)$yil : 0;
$ay        = isset($ay) ? (int)$ay : 0;
$yillar    = isset($yillar) ? (array)$yillar : [];
$aySayilar = isset($aySayilar) ? (array)$aySayilar : [];
$posts     = isset($posts) ? (array)$posts : [];
$total     = isset($total) ? (int)$total : 0;
$page      = isset($page) ? (int)$page : 1;
$perPage   = isset($perPage) ? (int)$perPage : 12;
$baseUrl   = isset($baseUrl) ? (string)$baseUrl : url('arsiv/' . $yil);
$adlar     = function_exists('discover_month_names') ? discover_month_names() : [];
$buYil     = (int)date('Y');
$buAy      = (int)date('n');
$yolAdi    = isset($heading) ? (string)$heading : ((string)$yil);
?>
<div class="liste iki-sutun kesif-sayfa">
  <div class="ana-sutun kesif-govde">

    <?php
    $yol = [['label' => 'Anasayfa', 'url' => url()]];
    $yol[] = ['label' => $yil . ' arşivi', 'url' => url('arsiv/' . $yil)];
    if ($ay) { $yol[] = ['label' => $yolAdi, 'url' => $baseUrl]; }
    echo breadcrumbs($yol);
    ?>

    <header class="liste-ust kesif-ust">
      <h1><?= esc($yolAdi) ?></h1>
      <p class="kesif-sayi"><?= (int)$total ?> haber<?= $page > 1 ? ' · ' . (int)$page . '. sayfa' : '' ?></p>
    </header>

    <?php if ($yillar): ?>
      <nav class="arsiv-gezinme" aria-label="Yıllar">
        <span class="arsiv-etiket">Yıl:</span>
        <?php foreach ($yillar as $y => $adet): ?>
          <?php if ((int)$y === $yil): ?>
            <span class="arsiv-oge secili" aria-current="page"><?= (int)$y ?></span>
          <?php else: ?>
            <a class="arsiv-oge" href="<?= esc(url('arsiv/' . (int)$y)) ?>"><?= (int)$y ?>
              <span class="arsiv-adet"><?= (int)$adet ?></span></a>
          <?php endif; ?>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>

    <?php if ($aySayilar): ?>
      <nav class="arsiv-gezinme aylar" aria-label="Aylar">
        <span class="arsiv-etiket">Ay:</span>
        <?php if ($ay): ?>
          <a class="arsiv-oge" href="<?= esc(url('arsiv/' . $yil)) ?>">Tüm yıl</a>
        <?php else: ?>
          <span class="arsiv-oge secili" aria-current="page">Tüm yıl</span>
        <?php endif; ?>
        <?php for ($m = 1; $m <= 12; $m++): ?>
          <?php
          $adet = isset($aySayilar[$m]) ? (int)$aySayilar[$m] : 0;
          $gelecek = ($yil > $buYil) || ($yil === $buYil && $m > $buAy);
          $adAy = isset($adlar[$m]) ? $adlar[$m] : (string)$m;
          ?>
          <?php if ($m === $ay): ?>
            <span class="arsiv-oge secili" aria-current="page"><?= esc($adAy) ?></span>
          <?php elseif ($adet > 0 && !$gelecek): ?>
            <a class="arsiv-oge" href="<?= esc(url('arsiv/' . $yil . '/' . sprintf('%02d', $m))) ?>"><?= esc($adAy) ?>
              <span class="arsiv-adet"><?= $adet ?></span></a>
          <?php else: ?>
            <span class="arsiv-oge bos"><?= esc($adAy) ?></span>
          <?php endif; ?>
        <?php endfor; ?>
      </nav>
    <?php endif; ?>

    <?php if (!$posts): ?>
      <p class="kesif-bos">Bu tarihte yayımlanmış haber yok.</p>
    <?php else: ?>
      <div class="izgara kesif-izgara">
        <?php foreach ($posts as $p) { part('card', ['post' => $p, 'boyut' => 'orta']); } ?>
      </div>
      <?= paginate($total, $perPage, $page, function ($n) use ($baseUrl) {
            return $n > 1 ? $baseUrl . (strpos($baseUrl, '?') === false ? '?' : '&') . 'sayfa=' . (int)$n : $baseUrl;
          }) ?>
    <?php endif; ?>

    <nav class="kesif-baglantilar" aria-label="Keşif">
      <a href="<?= esc(url('etiketler')) ?>">Etiket bulutu</a>
    </nav>
  </div>

  <?php part('sidebar'); ?>
</div>
