<?php
/**
 * Gazete teması — 404 / 410 sayfası.
 * Beklenen değişkenler: $message (isteğe bağlı)
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

$mesaj = isset($message) && $message !== '' ? (string)$message : 'Aradığınız sayfa bulunamadı.';
$aramaUrl = sef_enabled() ? url('arama') : base_url() . '/index.php';
$son = latest_posts(4);
?>
<div class="hata-sayfa">
  <p class="hata-kod" aria-hidden="true">404</p>
  <h1>Sayfa bulunamadı</h1>
  <p class="hata-mesaj"><?= esc($mesaj) ?></p>

  <form class="arama-genis hata-arama" action="<?= esc($aramaUrl) ?>" method="get" role="search">
    <?php if (!sef_enabled()): ?><input type="hidden" name="r" value="arama"><?php endif; ?>
    <label class="gizli" for="qHata">Arama ifadesi</label>
    <input type="search" id="qHata" name="q" placeholder="Haberlerde ara…" maxlength="80">
    <button type="submit">Ara</button>
  </form>

  <p><a class="dugme" href="<?= esc(url()) ?>">Anasayfaya dön</a></p>

  <?php if ($son): ?>
    <section class="blok">
      <h2 class="blok-baslik">Son Haberler</h2>
      <div class="izgara dar">
        <?php foreach ($son as $p) { part('card', ['post' => $p, 'boyut' => 'kucuk']); } ?>
      </div>
    </section>
  <?php endif; ?>
</div>
