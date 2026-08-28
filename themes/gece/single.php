<?php
/**
 * Gece teması — haber detayı.
 * BİK notları §3: yayım tarihi VE (farklıysa) güncelleme tarihi görünür biçimde basılır.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
if (empty($post)) { return; }

$img = post_image($post, 'large');
$srcset = post_image_srcset($post);
$webp = $img ? post_image_webp_srcset($post) : '';
$etiketler = post_tags($post);
$guncel = !empty($post['updated_at']) && !empty($post['published_at'])
       && strtotime($post['updated_at']) > strtotime($post['published_at']) + 120;
$sidebarPos = theme_setting('sidebar_position', 'right');
/* NEDEN (denetim 3 · onyuz B07): post_reading_time() okuma süresini TAM gövdeden
   hesaplıyor. Kilitli (ödeme duvarı arkasındaki) haberde bu, anonim ziyaretçiye duvarın
   arkasındaki metnin UZUNLUĞUNU sızdırıyor — metin değil ama üstveri de duvarın içindedir.
   post_is_locked() (inc/roles.php) oturum AÇMAZ, bu yüzden §3.1 ve sayfa önbelleği bozulmaz.
   Kilitliyse süre hiç hesaplanmaz ve rozet hiç basılmaz. */
$kilitliHaber = function_exists('post_is_locked') && post_is_locked($post);
$sure = $kilitliHaber ? 0 : post_reading_time($post);
$sureGoster = !$kilitliHaber && theme_setting('show_reading_time', '1') === '1';
?>
<div class="detay <?= $sidebarPos === 'none' ? 'tam' : 'iki-sutun' ?>">
  <article class="ana-sutun haber" itemscope itemtype="https://schema.org/NewsArticle">

    <nav class="kirinti mono" aria-label="Konum">
      <a href="<?= esc(url()) ?>">Anasayfa</a>
      <?php if (!empty($post['category_name'])): ?>
        <span aria-hidden="true">/</span>
        <a href="<?= esc(url('kategori/' . $post['category_slug'])) ?>"><?= esc($post['category_name']) ?></a>
      <?php endif; ?>
    </nav>

    <header class="haber-ust">
      <?php if (!empty($post['is_breaking'])): ?><span class="rozet-sondakika">SON DAKİKA</span><?php endif; ?>
      <div class="baslik-satiri">
        <h1 itemprop="headline"><?= esc($post['title']) ?></h1>
        <?php /* Üyelik modülü kuruluysa "Kaydet"; JS assets/uye.js içindedir. */ ?>
        <?php /* onyuz B03: düğme herkese basılır (anonim HTML değişmez, önbellek bozulmaz);
         data-uye işaretiyle uye.js anonim tıklamada HİÇBİR ağ isteği yapmadan girişe gönderir.
         member_current() oturum AÇMAZ (current_user_if_session), §3.1 korunur. */ ?>
        <?php if (function_exists('member_current')): ?>
          <?php $uyeOturum = member_current(); ?>
          <button type="button" class="kaydet-dugme mono" data-kaydet="<?= (int)$post['id'] ?>" data-uye="<?= $uyeOturum ? '1' : '0' ?>"
                  data-giris="<?= esc(url('uye/giris')) ?>" aria-pressed="false" title="Haberi kaydet">
            <span class="kaydet-metin">KAYDET</span>
          </button>
        <?php endif; ?>
      </div>
      <?php if (!empty($post['spot'])): ?><p class="spot" itemprop="description"><?= esc($post['spot']) ?></p><?php endif; ?>

      <div class="haber-meta mono">
        <?php /* 1.3-10: yazar adı, yazarın diğer haberlerine bağlanır.
           discover_author_url() üye/pasif/personel olmayan hesapta ve yayında
           haberi olmayan hesapta '' döner; o zaman eski düz metin basılır. */ ?>
        <?php if (!empty($post['author_name'])): ?>
          <?php $yazarUrl = function_exists('discover_author_url') ? discover_author_url($post) : ''; ?>
          <?php if ($yazarUrl !== ''): ?>
            <a class="yazar" itemprop="author" href="<?= esc($yazarUrl) ?>"><?= esc($post['author_name']) ?></a>
          <?php else: ?>
            <span class="yazar" itemprop="author"><?= esc($post['author_name']) ?></span>
          <?php endif; ?>
        <?php endif; ?>
        <time itemprop="datePublished" datetime="<?= esc($post['published_at']) ?>"><?= esc(tr_date($post['published_at'])) ?></time>
        <?php if ($guncel): ?>
          <span class="guncelleme">Güncelleme:
            <time itemprop="dateModified" datetime="<?= esc($post['updated_at']) ?>"><?= esc(tr_date($post['updated_at'])) ?></time>
          </span>
        <?php endif; ?>
        <?php if ($sureGoster): ?><span class="okuma-suresi"><?= (int)$sure ?> dk okuma</span><?php endif; ?>
        <span class="goruntulenme"><?= (int)$post['view_count'] ?> görüntülenme</span>

        <?php /* Okuma ayarları (1.2-13): yazı ölçeği + görünüm; tercih localStorage'da. */ ?>
        <?php part('okuma-ayarlari', ['yer' => 'haber']); ?>
        <?php if (!empty($post['ai_generated'])): ?>
          <span class="rozet-ai-uzun">Yapay zekâ desteğiyle hazırlandı, editör onayından geçti</span>
        <?php endif; ?>
      </div>

      <?php part('paylas', ['post' => $post]); ?>
    </header>

    <?php if ($img): ?>
      <figure class="haber-gorsel">
        <picture>
          <?php if ($webp !== ''): ?>
            <source type="image/webp" srcset="<?= esc($webp) ?>" sizes="(max-width:1000px) 100vw, 820px">
          <?php endif; ?>
          <?php /* LCP adayı: sayfanın en büyük görseli, öncelikli yüklenir. */ ?>
          <img src="<?= esc($img) ?>" <?= $srcset ? 'srcset="' . esc($srcset) . '" sizes="(max-width:1000px) 100vw, 820px"' : '' ?>
               alt="<?= esc($post['title']) ?>" itemprop="image" <?= post_image_attrs($post, 'large', true) ?>>
        </picture>
      </figure>
    <?php endif; ?>

    <div class="haber-govde" itemprop="articleBody">
      <?= post_body_html($post) ?>
    </div>

    <?= ad_slot('in_article') ?>

    <?php if (!empty($post['source_name'])): ?>
      <p class="kaynak">Kaynak:
        <?php if (!empty($post['source_url'])): ?>
          <a href="<?= esc($post['source_url']) ?>" rel="nofollow noopener noreferrer" target="_blank"><?= esc($post['source_name']) ?></a>
        <?php else: ?><?= esc($post['source_name']) ?><?php endif; ?>
      </p>
    <?php endif; ?>

    <?php if ($etiketler): ?>
      <div class="etiketler mono">
        <?php foreach ($etiketler as $t): ?><a href="<?= esc(url_tag($t)) ?>">#<?= esc($t) ?></a><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php part('paylas', ['post' => $post]); ?>

    <?php if (setting('corrections_enabled', '1') === '1'): ?>
      <details class="duzeltme">
        <summary>Düzeltme talep et</summary>
        <p class="soluk kucuk">Bu haberde bir hata olduğunu düşünüyorsanız düzeltme ve cevap hakkınızı
          kullanabilirsiniz. Talebiniz yayın kuruluşuna iletilir.</p>
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
    <?php /* 1.3-10: ilgili haberler artık ETİKET KESİŞİMİYLE puanlanır
       (ortak etiket çoksa daha ilgili), yeterli aday yoksa kategoriye düşer.
       Modül kurulu değilse index.php'nin verdiği kategori listesi kalır. */ ?>
    <?php if (function_exists('discover_related_posts')) { $related = discover_related_posts($post, 4); } ?>

    <?php if (!empty($related)): ?>
      <section class="blok">
        <h2 class="blok-baslik">İlgili Haberler</h2>
        <div class="izgara"><?php foreach ($related as $p) { part('card', ['post' => $p, 'boyut' => 'kucuk']); } ?></div>
      </section>
    <?php endif; ?>

    <?php /* Basın Kanunu m.14: düzeltme ve cevap metni ilgili haberle birlikte yayımlanır. Bu blok üç temada hiç çağrılmıyordu. */ ?>

    <?php /* Güncelleme notları EDİTORYALDİR ve düzeltme/tekzipten AYRIDIR; yasal blok her zaman altta kalır. Not yoksa boş dize döner. */ ?>

    <?= function_exists('post_update_notes_html') ? post_update_notes_html((int)$post['id']) : '' ?>

    <?php part('duzeltme-haber', ['post' => $post]); ?>

    <?php /* 1.3-10: önceki / sonraki haber. */ ?>
    <?php part('haber-gezinme', ['post' => $post]); ?>

    <?php part('comments', ['post' => $post, 'comments' => isset($comments) ? $comments : []]); ?>
  </article>

  <?php part('sidebar'); ?>
</div>
<?php /* "Kaydet" düğmesinin davranışı; yalnız düğme basıldıysa yüklenir. */ ?>
<?php if (function_exists('member_current')): ?>
<script src="<?= esc(url_asset('uye.js')) ?>?v=<?= esc(MANSET_VERSION) ?>" defer></script>
<?php endif; ?>
