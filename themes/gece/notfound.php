<?php
/** Gece teması — 404 / 410. */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
?>
<div class="hata-sayfa">
  <p class="hata-kod mono">404</p>
  <h1>Sayfa bulunamadı</h1>
  <p class="soluk"><?= esc(isset($message) ? $message : 'Aradığınız sayfa bulunamadı.') ?></p>
  <p><a class="dugme" href="<?= esc(url()) ?>">Anasayfaya dön</a></p>

  <section class="blok">
    <h2 class="blok-baslik">Son Haberler</h2>
    <div class="izgara">
      <?php foreach (latest_posts(4) as $p) { part('card', ['post' => $p, 'boyut' => 'kucuk']); } ?>
    </div>
  </section>
</div>
