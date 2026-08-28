<?php
/**
 * Bülten teması — haber / makale detayı.
 * BİK notları §3: yayım tarihi VE (farklıysa) güncelleme tarihi görünür biçimde basılır.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
if (empty($post)) { return; }

$img = post_image($post, 'large');
$srcset = post_image_srcset($post);
$etiketler = post_tags($post);
$guncel = !empty($post['updated_at']) && !empty($post['published_at'])
       && strtotime($post['updated_at']) > strtotime($post['published_at']) + 120;
?>
<div class="detay<?= theme_setting('sidebar_position', 'none') === 'none' ? '' : ' kenarli' ?>">
  <article class="akis-sutun yazi" itemscope itemtype="https://schema.org/NewsArticle">

    <nav class="kirinti akis" aria-label="Konum">
      <a href="<?= esc(url()) ?>">Anasayfa</a>
      <?php if (!empty($post['category_name'])): ?>
        <span aria-hidden="true">/</span>
        <a href="<?= esc(url('kategori/' . $post['category_slug'])) ?>"><?= esc($post['category_name']) ?></a>
      <?php endif; ?>
    </nav>

    <header class="akis yazi-ust">
      <?php if (!empty($post['is_breaking'])): ?><p class="rozet-sondakika">Son dakika</p><?php endif; ?>
      <h1 itemprop="headline"><?= esc($post['title']) ?></h1>
      <?php if (!empty($post['spot'])): ?>
        <p class="spot" itemprop="description"><?= esc($post['spot']) ?></p>
      <?php endif; ?>

      <div class="yazi-meta">
        <?php if (!empty($post['author_name'])): ?>
          <span class="yazar" itemprop="author"><?= esc($post['author_name']) ?></span>
        <?php endif; ?>
        <time itemprop="datePublished" datetime="<?= esc($post['published_at']) ?>"><?= esc(tr_date($post['published_at'])) ?></time>
        <?php if ($guncel): ?>
          <span class="guncelleme">Güncelleme:
            <time itemprop="dateModified" datetime="<?= esc($post['updated_at']) ?>"><?= esc(tr_date($post['updated_at'])) ?></time>
          </span>
        <?php endif; ?>
        <span class="goruntulenme"><?= (int)$post['view_count'] ?> okunma</span>
        <?php if (!empty($post['ai_generated'])): ?>
          <span class="rozet-ai-uzun">Yapay zekâ desteğiyle hazırlandı, editör onayından geçti</span>
        <?php endif; ?>
      </div>
    </header>

    <?php if ($img): ?>
      <figure class="yazi-gorsel<?= theme_setting('buyuk_gorsel', '1') === '1' ? ' genis' : '' ?>">
        <img src="<?= esc($img) ?>" <?= $srcset ? 'srcset="' . esc($srcset) . '" sizes="100vw"' : '' ?>
             alt="<?= esc($post['title']) ?>" itemprop="image" decoding="async">
      </figure>
    <?php endif; ?>

    <div class="akis yazi-govde" itemprop="articleBody">
      <?= post_body_html($post) ?>
    </div>

    <?php $reklamIci = ad_slot('in_article'); if ($reklamIci !== ''): ?>
      <div class="akis bosluk-ust"><?= $reklamIci ?></div>
    <?php endif; ?>

    <?php if (!empty($post['source_name'])): ?>
      <div class="akis">
        <p class="kaynak">Kaynak:
          <?php if (!empty($post['source_url'])): ?>
            <a href="<?= esc($post['source_url']) ?>" rel="nofollow noopener noreferrer" target="_blank"><?= esc($post['source_name']) ?></a>
          <?php else: ?><?= esc($post['source_name']) ?><?php endif; ?>
        </p>
      </div>
    <?php endif; ?>

    <?php if ($etiketler): ?>
      <div class="akis etiketler">
        <?php foreach ($etiketler as $t): ?><a href="<?= esc(url_tag($t)) ?>"><?= esc($t) ?></a><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (setting('corrections_enabled', '1') === '1'): ?>
      <div class="akis">
        <details class="duzeltme">
          <summary>Düzeltme talep et</summary>
          <p class="soluk"><small>Bu haberde bir hata olduğunu düşünüyorsanız düzeltme ve cevap hakkınızı
            kullanabilirsiniz. Talebiniz yayın kuruluşuna iletilir.</small></p>
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
      </div>
    <?php endif; ?>

    <?php if (!empty($related)): ?>
      <section class="akis blok">
        <h2 class="bolum-baslik">İlgili yazılar</h2>
        <?php foreach ($related as $p) { part('card', ['post' => $p, 'boyut' => 'kucuk']); } ?>
      </section>
    <?php endif; ?>

    <div class="akis">
      <?php part('comments', ['post' => $post, 'comments' => isset($comments) ? $comments : []]); ?>
    </div>
  </article>

  <?php part('sidebar'); ?>
</div>
