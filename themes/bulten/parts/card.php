<?php
/**
 * Bülten teması — akış öğesi ("kart" yerine feed satırı).
 * Beklenen değişkenler: $post (dizi), $boyut ('kapak'|'buyuk'|'orta'|'kucuk'|'liste')
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
if (empty($post)) { return; }

$boyut = isset($boyut) ? (string)$boyut : 'orta';
$buyukGorsel = theme_setting('buyuk_gorsel', '1') === '1';
$kapak = ($boyut === 'kapak' || $boyut === 'buyuk') && $buyukGorsel;
$gorselBoy = $kapak ? 'large' : ($boyut === 'kucuk' || $boyut === 'liste' ? 'thumb' : 'medium');
$img = post_image($post, $gorselBoy);
$srcset = post_image_srcset($post);
$webp = $img ? post_image_webp_srcset($post) : '';
$sira = isset($sira) ? (int)$sira : 0;
// LCP adayı: kapak öğesi ya da akışın ilk sıradaki maddesi ekranın üstünde açılır.
$onceki = ($kapak || $boyut === 'buyuk' || $sira === 1);
?>
<article class="oge oge-<?= esc($boyut) ?><?= $kapak ? ' oge-kapak' : '' ?>">
  <?php if ($img && $boyut !== 'liste'): ?>
    <a class="oge-gorsel" href="<?= esc(url_post($post)) ?>" tabindex="-1" aria-hidden="true">
      <picture>
        <?php if ($webp !== ''): ?>
          <source type="image/webp" srcset="<?= esc($webp) ?>" sizes="(max-width:760px) 100vw, 720px">
        <?php endif; ?>
        <img src="<?= esc($img) ?>"
             <?= $srcset ? 'srcset="' . esc($srcset) . '" sizes="(max-width:760px) 100vw, 720px"' : '' ?>
             alt="" <?= post_image_attrs($post, $gorselBoy, $onceki) ?>>
      </picture>
    </a>
  <?php endif; ?>

  <div class="oge-govde">
    <div class="oge-ust">
      <?php if ($sira): ?><span class="oge-sira"><?= esc(str_pad((string)$sira, 2, '0', STR_PAD_LEFT)) ?></span><?php endif; ?>
      <?php if (!empty($post['category_name'])): ?>
        <a class="oge-kategori" href="<?= esc(url('kategori/' . $post['category_slug'])) ?>"><?= esc($post['category_name']) ?></a>
      <?php endif; ?>
      <time datetime="<?= esc(arr($post, 'published_at', '')) ?>"><?= esc(tr_ago(arr($post, 'published_at', ''))) ?></time>
      <?php if (!empty($post['ai_generated'])): ?>
        <span class="rozet-ai" title="Yapay zekâ desteğiyle hazırlandı, editör onayından geçti">YZ</span>
      <?php endif; ?>
        <?php /* Ödeme duvarı listede de görünür: okur tıklamadan önce bilsin. */ ?>
        <?= post_locked_badge($post) ?>
    </div>

    <h3 class="oge-baslik"><a href="<?= esc(url_post($post)) ?>"><?= esc($post['title']) ?></a></h3>

    <?php if ($boyut !== 'liste' && !empty($post['spot'])): ?>
      <p class="oge-spot"><?= esc(excerpt($post['spot'], $kapak ? 220 : 150)) ?></p>
    <?php endif; ?>
  </div>
</article>
