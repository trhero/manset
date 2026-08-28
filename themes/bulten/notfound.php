<?php
/** Bülten teması — 404 / 410. */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
?>
<div class="liste">
  <div class="akis-sutun">
    <header class="akis sayfa-ust hata-ust">
      <p class="ust-etiket">404</p>
      <h1>Sayfa bulunamadı</h1>
      <p class="soluk"><?= esc(isset($message) ? $message : 'Aradığınız sayfa bulunamadı.') ?></p>
      <p><a class="dugme" href="<?= esc(url()) ?>">Anasayfaya dön</a></p>
    </header>

    <section class="akis blok">
      <h2 class="bolum-baslik">Son yazılar</h2>
      <?php foreach (latest_posts(5) as $p) { part('card', ['post' => $p, 'boyut' => 'kucuk']); } ?>
    </section>
  </div>
</div>
