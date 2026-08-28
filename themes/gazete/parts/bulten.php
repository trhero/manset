<?php
/**
 * Gazete teması — bülten kayıt kutusu.
 *
 * KAPSAM DIŞI: toplu e-posta GÖNDERİMİ yok (CONTRACTS §13); yalnız abone kaydı.
 * Uç nokta `newsletter.subscribe` Ajan-9'a aittir. Henüz yoksa API 404 döner ve
 * theme.js "yakında" mesajı gösterir — şablonda function_exists denetimi yapılmaz.
 *
 * Beklenen değişkenler: $baslik, $metin (isteğe bağlı)
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

$baslik = isset($baslik) && $baslik !== '' ? $baslik : 'Bültene katılın';
$metin  = isset($metin) && $metin !== ''
    ? $metin
    : (string)theme_setting('newsletter_text', 'Günün öne çıkan haberlerini e-postanıza alın.');
?>
<section class="bulten">
  <div class="bulten-ic">
    <div class="bulten-metin">
      <h2><?= esc($baslik) ?></h2>
      <p><?= esc($metin) ?></p>
    </div>
    <form class="bulten-form" data-bulten>
      <div class="form-sonuc" role="status" hidden></div>
      <label class="gizli" for="bultenEposta">E-posta adresiniz</label>
      <div class="bulten-satir">
        <input type="email" id="bultenEposta" name="email" required maxlength="120"
               placeholder="ornek@eposta.com" autocomplete="email">
        <button type="submit">Kaydol</button>
      </div>
      <label class="onay">
        <input type="checkbox" name="kvkk" required>
        <span>E-posta adresimin bülten gönderimi için işlenmesini onaylıyorum.</span>
      </label>
      <div class="tuzak" aria-hidden="true">
        <label>Bu alanı boş bırakın<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
      </div>
      <?= csrf_field_lazy() ?>
    </form>
  </div>
</section>
