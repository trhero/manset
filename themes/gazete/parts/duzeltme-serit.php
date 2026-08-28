<?php
/**
 * Gazete teması — düzeltme ve cevap şeridi.
 *
 * Basın Kanunu m.14: düzeltme/cevap metni ilgili haberin URL bağlantısı verilerek,
 * ilk 24 saat ANA SAYFADA yayımlanır (docs/BIK-NOTLARI.md §4).
 * Veri kaynağı: home_correction_notices() → inc/kunye.php (Ajan-9) kancası.
 * Kanca yoksa dizi boştur ve şerit hiç basılmaz.
 *
 * Beklenen değişkenler: $notices (isteğe bağlı)
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

$notices = isset($notices) && is_array($notices) ? $notices : home_correction_notices();
if (!$notices) { return; }
?>
<section class="duzeltme-serit" aria-label="Düzeltme ve cevap metinleri">
  <h2 class="duzeltme-serit-baslik">Düzeltme ve Cevap</h2>
  <?php foreach ($notices as $n): ?>
    <?php
      $baslik = (string)arr($n, 'post_title', '');
      $adres  = (string)arr($n, 'post_url', '');
      // Yayımlanan metin: varsa düzeltme-cevap yazısı ('answer'), yoksa talep ('message').
      // 'answer' kaynakta temizlenmiş olsa da çıkışta bir kez daha süzülür.
      $cevap  = trim((string)arr($n, 'answer', ''));
      $metin  = (string)arr($n, 'message', '');
      $tarih  = (string)arr($n, 'publish_at', arr($n, 'created_at', ''));
      if ($cevap === '' && trim($metin) === '') { continue; }
    ?>
    <article class="duzeltme-oge">
      <?php if ($baslik !== ''): ?>
        <h3>
          <?php if ($adres !== ''): ?>
            <a href="<?= esc($adres) ?>"><?= esc($baslik) ?></a>
          <?php else: ?><?= esc($baslik) ?><?php endif; ?>
        </h3>
      <?php endif; ?>
      <div class="duzeltme-metin">
        <?php if ($cevap !== ''): ?>
          <?= sanitize_html($cevap, false) ?>
        <?php else: ?>
          <p><?= nl2br(esc($metin)) ?></p>
        <?php endif; ?>
      </div>
      <?php if ($tarih !== ''): ?>
        <p class="duzeltme-tarih"><time datetime="<?= esc($tarih) ?>"><?= esc(tr_date($tarih)) ?></time></p>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</section>
