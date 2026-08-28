<?php
/**
 * Kâğıt teması — 404 / 410 sayfası.
 * Beklenen değişkenler: $message (isteğe bağlı)
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

$mesaj = isset($message) && $message !== '' ? (string)$message : 'Aradığınız sayfa bulunamadı.';
$aramaUrl = sef_enabled() ? url('arama') : base_url() . '/index.php';
$son = latest_posts(4);
?>
<div class="hata-sayfa">
  <hr class="cizgi-kalin">
  <p class="hata-kod" aria-hidden="true">404</p>
  <h1>Bu sayfa baskıya girmemiş</h1>
  <p class="hata-mesaj"><?= esc($mesaj) ?></p>
  <hr class="cizgi-ince">

  <form class="arama-genis hata-arama" action="<?= esc($aramaUrl) ?>" method="get" role="search">
    <?php if (!sef_enabled()): ?><input type="hidden" name="r" value="arama"><?php endif; ?>
    <label class="gizli" for="qHata">Arama ifadesi</label>
    <input type="search" id="qHata" name="q" placeholder="Haberlerde ara…" maxlength="80">
    <button type="submit">Ara</button>
  </form>

  <p><a class="dugme" href="<?= esc(url()) ?>">Kapağa dön</a></p>

  <?php if ($son): ?>
    <section class="ilgili">
      <hr class="cizgi-kalin">
      <h2 class="sutun-baslik">Son Haberler</h2>
      <div class="ilgili-izgara">
        <?php foreach ($son as $p) { part('card', ['post' => $p, 'boyut' => 'kucuk']); } ?>
      </div>
    </section>
  <?php endif; ?>
</div>
