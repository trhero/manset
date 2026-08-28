<?php
/**
 * Yerel teması — haber kartı.
 * Beklenen değişkenler: $post (dizi), $boyut ('buyuk'|'orta'|'kucuk'|'liste')
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
if (empty($post)) { return; }

$boyut = isset($boyut) ? (string)$boyut : 'orta';
$gorselBoy = $boyut === 'buyuk' ? 'large' : ($boyut === 'kucuk' || $boyut === 'liste' ? 'thumb' : 'medium');
$img = post_image($post, $gorselBoy);
$srcset = post_image_srcset($post);
$webp = $img ? post_image_webp_srcset($post) : '';
$kat = arr($post, 'category_color', '');
$sira = isset($sira) ? (int)$sira : 0;
// LCP adayı: büyük kart ya da sıralı listenin ilk maddesi ekranın üstünde açılır.
$onceki = ($boyut === 'buyuk' || $sira === 1);
?>
<article class="kart kart-<?= esc($boyut) ?><?= $sira > 0 ? " kart-sirali" : "" ?>"<?= $kat !== '' ? ' style="--kat:' . esc($kat) . '"' : '' ?>>
  <a class="kart-bag" href="<?= esc(url_post($post)) ?>">
    <?php if ($img && $boyut !== 'liste'): ?>
      <div class="kart-gorsel">
        <picture>
          <?php if ($webp !== ''): ?>
            <source type="image/webp" srcset="<?= esc($webp) ?>" sizes="(max-width:760px) 100vw, 380px">
          <?php endif; ?>
          <img src="<?= esc($img) ?>"
               <?= $srcset ? 'srcset="' . esc($srcset) . '" sizes="(max-width:760px) 100vw, 380px"' : '' ?>
               alt="<?= esc($post['title']) ?>" <?= post_image_attrs($post, $gorselBoy, $onceki) ?>>
        </picture>
      </div>
    <?php endif; ?>
    <div class="kart-govde">
      <?php if (!empty($post['category_name'])): ?>
        <span class="kart-kategori"><?= esc($post['category_name']) ?></span>
      <?php endif; ?>
      <h3 class="kart-baslik"><?= esc($post['title']) ?></h3>
      <?php if ($boyut !== 'liste' && $boyut !== 'kucuk' && !empty($post['spot'])): ?>
        <p class="kart-spot"><?= esc(excerpt($post['spot'], $boyut === 'buyuk' ? 190 : 110)) ?></p>
      <?php endif; ?>
      <div class="kart-meta">
        <time datetime="<?= esc(arr($post, 'published_at', '')) ?>"><?= esc(tr_ago(arr($post, 'published_at', ''))) ?></time>
        <?php if (!empty($post['ai_generated'])): ?>
          <span class="rozet-ai" title="Yapay zekâ desteğiyle hazırlandı, editör onayından geçti">YZ</span>
        <?php endif; ?>
          <?php /* Ödeme duvarı listede de görünür: okur tıklamadan önce bilsin. */ ?>
          <?= post_locked_badge($post) ?>
      </div>
    </div>
  </a>
</article>
