<?php
/**
 * Gazete teması — haberin altında yayımlanan düzeltme/cevap metinleri.
 *
 * Basın Kanunu m.14: düzeltme ve cevap yazısı, ilgili içeriğe URL bağlantısı
 * verilerek "aynı puntolarla ve aynı şekilde" yayımlanır (docs/BIK-NOTLARI.md §4).
 * Veri kaynağı: inc/kunye.php → kunye_post_corrections($postId) (Ajan-9).
 * Modül yoksa hiçbir şey basılmaz.
 *
 * Beklenen değişkenler: $post
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
if (empty($post) || !is_array($post)) { return; }
if (!function_exists('kunye_post_corrections')) { return; }

$liste = kunye_post_corrections((int)$post['id']);
if (!is_array($liste) || !$liste) { return; }
?>
<section class="duzeltme-yayim" aria-label="Düzeltme ve cevap">
  <h2 class="duzeltme-serit-baslik">Düzeltme ve Cevap</h2>
  <?php foreach ($liste as $n): ?>
    <?php
      $cevap = trim((string)arr($n, 'answer', ''));
      $metin = (string)arr($n, 'message', '');
      $tarih = (string)arr($n, 'publish_at', arr($n, 'created_at', ''));
      $adres = (string)arr($n, 'post_url', '');
      if ($cevap === '' && trim($metin) === '') { continue; }
    ?>
    <article class="duzeltme-oge">
      <div class="duzeltme-metin">
        <?php if ($cevap !== ''): ?>
          <?= sanitize_html($cevap, false) ?>
        <?php else: ?>
          <p><?= nl2br(esc($metin)) ?></p>
        <?php endif; ?>
      </div>
      <p class="duzeltme-tarih">
        <?php if ($tarih !== ''): ?>
          <time datetime="<?= esc($tarih) ?>"><?= esc(tr_date($tarih)) ?></time>
        <?php endif; ?>
        <?php if ($adres !== ''): ?>
          · <a href="<?= esc($adres) ?>">İlgili haber</a>
        <?php endif; ?>
      </p>
    </article>
  <?php endforeach; ?>
</section>
