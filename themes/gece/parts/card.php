<?php
/**
 * Gece teması — haber kartı.
 * Beklenen değişkenler: $post (dizi), $boyut ('buyuk'|'orta'|'kucuk'|'liste')
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
if (empty($post)) { return; }

$boyut = isset($boyut) ? (string)$boyut : 'orta';
$img = post_image($post, $boyut === 'buyuk' ? 'large' : ($boyut === 'kucuk' || $boyut === 'liste' ? 'thumb' : 'medium'));
$srcset = post_image_srcset($post);
$kat = arr($post, 'category_color', '');
?>
<article class="kart kart-<?= esc($boyut) ?><?= $sira > 0 ? " kart-sirali" : "" ?>"<?= $kat !== '' ? ' style="--kat:' . esc($kat) . '"' : '' ?>>
  <?php if ($img && $boyut !== 'liste'): ?>
    <a class="kart-gorsel" href="<?= esc(url_post($post)) ?>" tabindex="-1" aria-hidden="true">
      <img src="<?= esc($img) ?>"
           <?= $srcset ? 'srcset="' . esc($srcset) . '" sizes="(max-width:760px) 100vw, 420px"' : '' ?>
           alt="" loading="lazy" decoding="async">
      <?php if (arr($post, 'type', '') === 'video'): ?><span class="oynat" aria-hidden="true">▶</span><?php endif; ?>
    </a>
  <?php endif; ?>

  <div class="kart-govde">
    <div class="kart-ust mono">
      <?php if (!empty($post['category_name'])): ?>
        <a class="kart-kategori" href="<?= esc(url('kategori/' . $post['category_slug'])) ?>"><?= esc($post['category_name']) ?></a>
      <?php endif; ?>
      <time datetime="<?= esc(arr($post, 'published_at', '')) ?>"><?= esc(tr_ago(arr($post, 'published_at', ''))) ?></time>
      <?php if (!empty($post['ai_generated'])): ?>
        <span class="rozet-ai" title="Yapay zekâ desteğiyle hazırlandı, editör onayından geçti">YZ</span>
      <?php endif; ?>
        <?php /* Ödeme duvarı listede de görünür: okur tıklamadan önce bilsin. */ ?>
        <?= post_locked_badge($post) ?>
    </div>

    <h3 class="kart-baslik"><a href="<?= esc(url_post($post)) ?>"><?= esc($post['title']) ?></a></h3>

    <?php if ($boyut !== 'liste' && $boyut !== 'kucuk' && !empty($post['spot'])): ?>
      <p class="kart-spot"><?= esc(excerpt($post['spot'], $boyut === 'buyuk' ? 200 : 120)) ?></p>
    <?php endif; ?>
  </div>
</article>
