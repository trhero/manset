<?php
/**
 * Gazete teması — haber kartı.
 * Beklenen değişkenler:
 *   $post   (dizi, zorunlu)
 *   $boyut  'buyuk' | 'orta' | 'kucuk' | 'liste'   (varsayılan 'orta')
 *   $sira   (int, isteğe bağlı — sıralı listelerde numara rozeti)
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
if (empty($post) || !is_array($post)) { return; }

$boyut = isset($boyut) ? $boyut : 'orta';
if (!in_array($boyut, ['buyuk', 'orta', 'kucuk', 'liste'], true)) { $boyut = 'orta'; }

$gorselBoy = ['buyuk' => 'large', 'orta' => 'medium', 'kucuk' => 'thumb', 'liste' => 'thumb'];
$img = post_image($post, $gorselBoy[$boyut]);
$srcset = post_image_srcset($post);
$sizes = $boyut === 'buyuk' ? '(max-width:700px) 100vw, 720px' : '(max-width:700px) 100vw, 380px';
$kat = (string)arr($post, 'category_color', '');
$sira = isset($sira) ? (int)$sira : 0;
// LCP adayı: büyük kart ya da sıralı listenin ilk öğesi ekranın üstünde açılır.
$onceki = ($boyut === 'buyuk' || $sira === 1);
$webp = $img ? post_image_webp_srcset($post) : '';
?>
<article class="kart kart-<?= esc($boyut) ?><?= $sira > 0 ? " kart-sirali" : "" ?><?= $img ? '' : ' kart-gorselsiz' ?>">
  <a class="kart-bag" href="<?= esc(url_post($post)) ?>">
    <?php if ($img): ?>
      <div class="kart-gorsel">
        <picture>
          <?php if ($webp !== ''): ?>
            <source type="image/webp" srcset="<?= esc($webp) ?>" sizes="<?= esc($sizes) ?>">
          <?php endif; ?>
          <img src="<?= esc($img) ?>"<?= $srcset ? ' srcset="' . esc($srcset) . '" sizes="' . esc($sizes) . '"' : '' ?>
               alt="<?= esc($post['title']) ?>" <?= post_image_attrs($post, $gorselBoy[$boyut], $onceki) ?>>
        </picture>
        <?php if (!empty($post['type']) && $post['type'] === 'video'): ?>
          <span class="kart-video" aria-hidden="true">▶</span>
        <?php endif; ?>
        <?php if (!empty($post['is_breaking'])): ?>
          <span class="kart-sondakika">SON DAKİKA</span>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="kart-govde">
      <?php if ($sira > 0): ?><span class="kart-sira" aria-hidden="true"><?= $sira ?></span><?php endif; ?>

      <?php if (!empty($post['category_name'])): ?>
        <span class="kart-kategori"<?= $kat !== '' ? ' style="--kat:' . esc($kat) . '"' : '' ?>><?= esc($post['category_name']) ?></span>
      <?php endif; ?>

      <h3 class="kart-baslik"><?= esc($post['title']) ?></h3>

      <?php if ($boyut !== 'liste' && $boyut !== 'kucuk' && !empty($post['spot'])): ?>
        <p class="kart-spot"><?= esc(excerpt($post['spot'], $boyut === 'buyuk' ? 190 : 110)) ?></p>
      <?php endif; ?>

      <div class="kart-meta">
        <?php if (!empty($post['published_at'])): ?>
          <time datetime="<?= esc($post['published_at']) ?>"><?= esc(tr_ago($post['published_at'])) ?></time>
        <?php endif; ?>
        <?php if (!empty($post['ai_generated'])): ?>
          <span class="rozet-ai" title="Yapay zekâ desteğiyle hazırlandı, editör onayından geçti">YZ</span>
        <?php endif; ?>
          <?php /* Ödeme duvarı listede de görünür: okur tıklamadan önce bilsin. */ ?>
          <?= post_locked_badge($post) ?>
      </div>
    </div>
  </a>
</article>
