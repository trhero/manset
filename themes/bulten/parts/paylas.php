<?php
/**
 * Bülten teması — paylaş satırı.
 * DIŞ API / SDK YOK: yalnız intent adresleri ve panoya kopyalama (theme.js).
 * Dergi diline uygun: kutu yok, ince çizgiler arasında küçük kapitel bağlantılar.
 * Beklenen değişkenler: $post
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
if (empty($post) || !is_array($post)) { return; }
if (theme_setting('show_share', '1') !== '1') { return; }

$baglantilar = share_links($post);
if (!$baglantilar) { return; }
?>
<div class="paylas" data-paylas>
  <span class="paylas-etiket">Paylaş</span>
  <?php foreach ($baglantilar as $b): ?>
    <?php if ($b['key'] === 'copy'): ?>
      <button type="button" class="paylas-dugme paylas-kopyala" data-kopyala="<?= esc($b['url']) ?>"
              aria-label="Bağlantıyı kopyala">Bağlantı</button>
    <?php else: ?>
      <a class="paylas-dugme paylas-<?= esc($b['key']) ?>" href="<?= esc($b['url']) ?>"
         target="_blank" rel="noopener noreferrer nofollow"
         aria-label="<?= esc($b['label']) ?> ile paylaş"><?= esc($b['label']) ?></a>
    <?php endif; ?>
  <?php endforeach; ?>
  <span class="paylas-sonuc" role="status" aria-live="polite"></span>
</div>
