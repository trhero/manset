<?php
/**
 * Kâğıt teması — haber kartı (gazete sütun parçası).
 * Beklenen değişkenler: $post, $boyut ('buyuk'|'orta'|'kucuk'|'liste'), $sira (isteğe bağlı)
 * Kutu/gölge yok: üstte ince çizgi, serif başlık, damga kategori etiketi.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
if (empty($post) || !is_array($post)) { return; }

$boyut = isset($boyut) ? $boyut : 'orta';
if (!in_array($boyut, ['buyuk', 'orta', 'kucuk', 'liste'], true)) { $boyut = 'orta'; }

$gorselBoy = ['buyuk' => 'large', 'orta' => 'medium', 'kucuk' => 'thumb', 'liste' => 'thumb'];
$img = ($boyut === 'liste') ? '' : post_image($post, $gorselBoy[$boyut]);
$srcset = $img ? post_image_srcset($post) : '';
$webp = $img ? post_image_webp_srcset($post) : '';
$sira = isset($sira) ? (int)$sira : 0;
// LCP adayı: kapak sütunundaki büyük dizgi ya da sıralı listenin ilk maddesi.
$onceki = ($boyut === 'buyuk' || $sira === 1);
?>
<article class="kart kart-<?= esc($boyut) ?><?= $sira > 0 ? " kart-sirali" : "" ?><?= $img ? '' : ' kart-gorselsiz' ?>">
  <a class="kart-bag" href="<?= esc(url_post($post)) ?>">
    <?php if ($sira > 0): ?><span class="kart-sira" aria-hidden="true"><?= $sira ?></span><?php endif; ?>

    <?php if (!empty($post['category_name'])): ?>
      <span class="kart-kategori"><?= esc($post['category_name']) ?></span>
    <?php endif; ?>

    <h3 class="kart-baslik"><?= esc($post['title']) ?></h3>

    <?php if ($img): ?>
      <div class="kart-gorsel">
        <picture>
          <?php if ($webp !== ''): ?>
            <source type="image/webp" srcset="<?= esc($webp) ?>" sizes="(max-width:700px) 100vw, 360px">
          <?php endif; ?>
          <img src="<?= esc($img) ?>"<?= $srcset ? ' srcset="' . esc($srcset) . '" sizes="(max-width:700px) 100vw, 360px"' : '' ?>
               alt="<?= esc($post['title']) ?>" <?= post_image_attrs($post, $gorselBoy[$boyut], $onceki) ?>>
        </picture>
        <?php if (!empty($post['type']) && $post['type'] === 'video'): ?>
          <span class="kart-video" aria-hidden="true">▶</span>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($boyut !== 'liste' && $boyut !== 'kucuk' && !empty($post['spot'])): ?>
      <p class="kart-spot"><?= esc(excerpt($post['spot'], $boyut === 'buyuk' ? 200 : 120)) ?></p>
    <?php endif; ?>

    <p class="kart-meta">
      <?php if (!empty($post['published_at'])): ?>
        <time datetime="<?= esc($post['published_at']) ?>"><?= esc(tr_ago($post['published_at'])) ?></time>
      <?php endif; ?>
      <?php if (!empty($post['is_breaking'])): ?><span class="kart-sondakika">son dakika</span><?php endif; ?>
      <?php if (!empty($post['ai_generated'])): ?>
        <span class="rozet-ai" title="Yapay zekâ desteğiyle hazırlandı, editör onayından geçti">YZ</span>
      <?php endif; ?>
        <?php /* Ödeme duvarı listede de görünür: okur tıklamadan önce bilsin. */ ?>
        <?= post_locked_badge($post) ?>
    </p>
  </a>
</article>
