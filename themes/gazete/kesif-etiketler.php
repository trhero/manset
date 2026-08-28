<?php
/**
 * Keşif — etiket bulutu (1.3-10). /etiketler
 * Beklenen değişkenler: $tags ([label, slug, count, weight]), $total
 *
 * Beş temanın ortak yedeği (bkz. kesif-yazar.php başlığı).
 * Ağırlık YALNIZ punto değiştirir; renk her temada --renk-metin / --renk-soluk
 * belirteçlerinden gelir, böylece koyu modda kontrast temanın ölçülmüş
 * paletiyle birlikte hareket eder.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

$tags  = isset($tags) ? (array)$tags : [];
$arsivYil = 0;
if (function_exists('discover_archive_years')) {
    $y = discover_archive_years();
    if ($y) { $arsivYil = (int)array_key_first($y); }
}
?>
<div class="liste iki-sutun kesif-sayfa">
  <div class="ana-sutun kesif-govde">

    <?= breadcrumbs([
      ['label' => 'Anasayfa', 'url' => url()],
      ['label' => 'Etiketler', 'url' => url('etiketler')],
    ]) ?>

    <header class="liste-ust kesif-ust">
      <h1>Etiketler</h1>
      <p class="kesif-sayi"><?= count($tags) ?> etiket</p>
    </header>

    <?php if (!$tags): ?>
      <p class="kesif-bos">Henüz etiketlenmiş bir haber yok.</p>
    <?php else: ?>
      <ul class="etiket-bulutu">
        <?php foreach ($tags as $t): ?>
          <li>
            <a class="bulut-oge a<?= (int)$t['weight'] ?>" href="<?= esc(url('etiket/' . $t['slug'])) ?>">
              <span class="bulut-ad"><?= esc($t['label']) ?></span>
              <span class="bulut-adet"><?= (int)$t['count'] ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($arsivYil): ?>
      <nav class="kesif-baglantilar" aria-label="Keşif">
        <a href="<?= esc(url('arsiv/' . $arsivYil)) ?>">Tarih arşivi</a>
      </nav>
    <?php endif; ?>
  </div>

  <?php part('sidebar'); ?>
</div>
