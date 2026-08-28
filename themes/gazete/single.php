<?php
/**
 * Gazete teması — haber detay.
 *
 * BİK / Basın Kanunu m.4 gereği (docs/BIK-NOTLARI.md §3):
 * yayım tarihi VE — farklıysa — güncelleme tarihi içeriğin üzerinde basılır.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
if (empty($post) || !is_array($post)) { return; }

$img       = post_image($post, 'large');
$srcset    = post_image_srcset($post);
$etiketler = post_tags($post);
$govde     = post_body_html($post);
$kaynakGovdede = (strpos($govde, 'class="kaynak"') !== false);
$sure      = post_reading_time($post);
$sureGoster = theme_setting('show_reading_time', '1') === '1';
$guncel    = post_updated_visible($post);

$yol = [['label' => 'Anasayfa', 'url' => url()]];
if (!empty($post['category_name'])) {
    $yol[] = ['label' => (string)$post['category_name'], 'url' => url('kategori/' . $post['category_slug'])];
}
$yol[] = ['label' => (string)$post['title'], 'url' => url_post($post)];
?>
<div class="detay iki-sutun">
  <article class="ana-sutun haber" itemscope itemtype="https://schema.org/NewsArticle">

    <?= breadcrumbs($yol) ?>

    <header class="haber-ust">
      <?php if (!empty($post['is_breaking'])): ?><span class="rozet-son-dakika">SON DAKİKA</span><?php endif; ?>
      <?php if (!empty($post['category_name'])): ?>
        <a class="haber-kategori" href="<?= esc(url('kategori/' . $post['category_slug'])) ?>"
           style="--kat:<?= esc(arr($post, 'category_color', '#c0392b')) ?>"><?= esc($post['category_name']) ?></a>
      <?php endif; ?>

      <h1 itemprop="headline"><?= esc($post['title']) ?></h1>

      <?php if (!empty($post['spot'])): ?>
        <p class="spot" itemprop="description"><?= esc($post['spot']) ?></p>
      <?php endif; ?>

      <div class="haber-meta">
        <?php if (!empty($post['author_name'])): ?>
          <span class="yazar" itemprop="author"><?= esc($post['author_name']) ?></span>
        <?php endif; ?>

        <?php /* BİK m.4 — ilk yayım tarihi */ ?>
        <time class="tarih-yayim" itemprop="datePublished" datetime="<?= esc($post['published_at']) ?>">
          <?= esc(tr_date($post['published_at'])) ?></time>

        <?php /* BİK m.4 — sonraki güncelleme tarihi */ ?>
        <?php if ($guncel): ?>
          <span class="guncelleme">Güncelleme:
            <time itemprop="dateModified" datetime="<?= esc($post['updated_at']) ?>"><?= esc(tr_date($post['updated_at'])) ?></time>
          </span>
        <?php endif; ?>

        <?php if ($sureGoster): ?>
          <span class="okuma-suresi"><?= (int)$sure ?> dk okuma</span>
        <?php endif; ?>

        <span class="goruntulenme"><?= (int)arr($post, 'view_count', 0) ?> görüntülenme</span>

        <?php if (!empty($post['ai_generated'])): ?>
          <span class="rozet-ai-uzun">Yapay zekâ desteğiyle hazırlandı, editör onayından geçti</span>
        <?php endif; ?>
      </div>
    </header>

    <?php part('paylas', ['post' => $post]); ?>

    <?php if ($img): ?>
      <figure class="haber-gorsel">
        <img src="<?= esc($img) ?>"<?= $srcset ? ' srcset="' . esc($srcset) . '" sizes="(max-width:900px) 100vw, 820px"' : '' ?>
             alt="<?= esc($post['title']) ?>" itemprop="image" decoding="async" loading="lazy">
      </figure>
    <?php endif; ?>

    <div class="haber-govde" itemprop="articleBody">
      <?= $govde ?>
    </div>

    <?= ad_slot('in_article') ?>

    <?php /* Kaynak bloğu gövdede zaten varsa (YZ/RSS haberleri) tekrar basılmaz */ ?>
    <?php if (!$kaynakGovdede && !empty($post['source_name'])): ?>
      <p class="kaynak">Kaynak:
        <?php if (!empty($post['source_url'])): ?>
          <a href="<?= esc($post['source_url']) ?>" rel="nofollow noopener noreferrer" target="_blank"><?= esc($post['source_name']) ?></a>
        <?php else: ?><?= esc($post['source_name']) ?><?php endif; ?>
      </p>
    <?php endif; ?>

    <?php if ($etiketler): ?>
      <div class="etiketler" aria-label="Etiketler">
        <?php foreach ($etiketler as $t): ?><a href="<?= esc(url_tag($t)) ?>">#<?= esc($t) ?></a><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php part('paylas', ['post' => $post]); ?>

    <?php /* Basın Kanunu m.14 — yayımlanmış düzeltme/cevap metinleri */ ?>
    <?php part('duzeltme-haber', ['post' => $post]); ?>

    <?php if (setting('corrections_enabled', '1') === '1'): ?>
      <details class="duzeltme">
        <summary>Düzeltme talep et</summary>
        <p><small>Bu haberde bir hata olduğunu düşünüyorsanız düzeltme ve cevap hakkınızı kullanabilirsiniz.
          Talebiniz yayın kuruluşuna iletilir.</small></p>
        <form class="duzeltme-form" data-post="<?= (int)$post['id'] ?>">
          <div class="satir">
            <label>Adınız <input type="text" name="name" required maxlength="60"></label>
            <label>E-posta <input type="email" name="email" required maxlength="120"></label>
          </div>
          <label>Talebiniz <textarea name="message" required rows="4" maxlength="3000"></textarea></label>
          <div class="tuzak" aria-hidden="true"><label>Boş bırakın<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
          <?= csrf_field_lazy() ?>
          <button type="submit">Gönder</button>
          <div class="form-sonuc" role="status" hidden></div>
        </form>
      </details>
    <?php endif; ?>

    <?php $ilgili = isset($related) && $related ? $related : related_posts($post, 4); ?>
    <?php if ($ilgili): ?>
      <section class="ilgili">
        <h2 class="blok-baslik">İlgili Haberler</h2>
        <div class="izgara dar">
          <?php foreach ($ilgili as $p) { part('card', ['post' => $p, 'boyut' => 'kucuk']); } ?>
        </div>
      </section>
    <?php endif; ?>

    <?php part('comments', ['post' => $post, 'comments' => isset($comments) ? $comments : []]); ?>
  </article>

  <?php part('sidebar'); ?>
</div>
