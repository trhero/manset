<?php
/**
 * Gazete teması — kategori şeridi.
 * Beklenen değişkenler: $cat (kategori dizisi), $items (haber dizisi),
 *                       $baslik (isteğe bağlı başlık ezmesi)
 * Yerleşim: solda öne çıkan haber, sağda 3'lü liste — "şerit" hissi.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
if (empty($cat) || empty($items) || !is_array($items)) { return; }

$liste = array_values($items);
$one   = array_shift($liste);
$renk  = (string)arr($cat, 'color', '');
$ad    = isset($baslik) && $baslik !== '' ? $baslik : (string)arr($cat, 'name', '');
?>
<section class="blok kategori-blok"<?= $renk !== '' ? ' style="--kat:' . esc($renk) . '"' : '' ?>>
  <h2 class="blok-baslik">
    <a href="<?= esc(url_category($cat)) ?>"><?= esc($ad) ?></a>
    <a class="blok-tumu" href="<?= esc(url_category($cat)) ?>">Tümü →</a>
  </h2>

  <div class="serit-izgara">
    <div class="serit-one">
      <?php part('card', ['post' => $one, 'boyut' => 'orta']); ?>
    </div>
    <?php if ($liste): ?>
      <ul class="serit-liste">
        <?php foreach ($liste as $p): ?>
          <li>
            <a href="<?= esc(url_post($p)) ?>">
              <span class="serit-baslik"><?= esc($p['title']) ?></span>
              <time datetime="<?= esc(arr($p, 'published_at', '')) ?>"><?= esc(tr_ago(arr($p, 'published_at', ''))) ?></time>
              <?= post_locked_badge($p) ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>
