<?php
/**
 * Kâğıt teması — ölçülü ödeme duvarı kilit kutusu (1.2-05, Ajan-D).
 *
 * `inc/paywall.php` → paywall_locked_html() bu parçayı part_capture() ile basar.
 * Diğer temalarda dosya yoksa view.php zaten buraya düşer.
 *
 * Beklenen değişkenler:
 *   $post $vis $uye $kalan $toplam $olculu $deneme $denemeVar $neden
 *
 * ÖN YÜZ KURALI: burada current_user() ÇAĞRILMAZ (oturum açardı) ve form
 * csrf_field_lazy() kullanır — boş alan basılır, anahtar kullanıcı forma
 * dokunduğunda assets/site.js tarafından alınır.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

$vis       = isset($vis) ? (string)$vis : 'premium';
$uye       = isset($uye) ? $uye : null;
$kalan     = isset($kalan) ? (int)$kalan : 0;
$toplam    = isset($toplam) ? (int)$toplam : 0;
$olculu    = !empty($olculu);
$deneme    = isset($deneme) ? (int)$deneme : 0;
$denemeVar = !empty($denemeVar);
$postId    = (isset($post) && is_array($post)) ? (int)arr($post, 'id', 0) : 0;

$baslik = ($vis === 'premium') ? 'Bu içerik abonelere özeldir' : 'Bu içerik üyelere özeldir';
$denemeGoster = ($deneme > 0 && $uye && !$denemeVar);
?>
<div class="icerik-kilit olculu-kilit" data-kilit="<?= esc($vis) ?>">
  <h3><?= esc($baslik) ?></h3>

  <?php if ($olculu && $toplam > 0): ?>
    <?php if ($kalan > 0): ?>
      <p class="olcum-durum">Bu ay <strong><?= (int)$kalan ?></strong> ücretsiz haber okuma hakkınız kaldı
        (aylık <?= (int)$toplam ?>).</p>
    <?php else: ?>
      <p class="olcum-durum">Bu ayki <strong><?= (int)$toplam ?></strong> ücretsiz haber hakkınızı kullandınız.
        Hakkınız ayın başında yenilenir.</p>
    <?php endif; ?>
  <?php else: ?>
    <p class="olcum-durum"><?= $uye ? 'Okumaya devam etmek için aboneliğinizi başlatın.'
                                    : 'Okumaya devam etmek için giriş yapın veya abone olun.' ?></p>
  <?php endif; ?>

  <p class="kilit-eylem">
    <?php if ($uye): ?>
      <a class="dugme" href="<?= esc(url('hesap')) ?>">Aboneliğim</a>
    <?php else: ?>
      <a class="dugme" href="<?= esc(url('uye/giris')) ?>">Giriş yap</a>
      <a class="dugme ghost" href="<?= esc(url('uye/kayit')) ?>">Üye ol</a>
    <?php endif; ?>
  </p>

  <?php if ($denemeGoster): ?>
    <form class="kilit-deneme" method="post" action="<?= esc(base_url() . '/hediye.php') ?>">
      <?= csrf_field_lazy() ?>
      <input type="hidden" name="eylem" value="deneme">
      <input type="hidden" name="haber" value="<?= (int)$postId ?>">
      <button type="submit" class="dugme ghost"><?= (int)$deneme ?> gün ücretsiz deneyin</button>
      <small class="soluk">Deneme hakkı hesap başına bir kezdir.</small>
    </form>
  <?php endif; ?>

  <p class="kilit-not soluk"><small>Ücretsiz okuma hakkı tarayıcınızda tutulur; kişisel veriniz saklanmaz.</small></p>
</div>


<style>
.olculu-kilit{border-top:2px solid var(--renk-metin,#232323);border-bottom:1px solid var(--renk-cizgi,#dcd7cc);background:transparent;padding:18px 0;margin:24px 0}
.olculu-kilit h3{margin:0 0 8px;font-family:var(--yazi-baslik,Georgia,serif);font-weight:600}
.olculu-kilit .olcum-durum{margin:0 0 12px;font-family:var(--yazi-govde,Georgia,serif)}
.olculu-kilit .kilit-eylem{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 10px}
.olculu-kilit .dugme{display:inline-block;background:var(--renk-metin,#232323);color:var(--renk-zemin,#f6f3ec);border:0;padding:8px 18px;font-size:14px;letter-spacing:.02em;cursor:pointer}
.olculu-kilit .dugme.ghost{background:transparent;color:var(--renk-metin,#232323);border:1px solid var(--renk-metin,#232323)}
.olculu-kilit .dugme:hover{text-decoration:none}
.olculu-kilit .kilit-deneme{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 8px}
.olculu-kilit .kilit-not{margin:0}
.olculu-kilit .soluk{color:var(--renk-soluk,#6f6a60)}
</style>
