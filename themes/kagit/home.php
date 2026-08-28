<?php
/**
 * Kâğıt teması — anasayfa.
 *
 * Yerleşim gazete temasından bilinçli olarak farklıdır:
 *   1) tek geniş KAPAK manşeti (tam genişlik, altında ince ayraçlı yan manşetler)
 *   2) altında 2-3 sütunlu "gazete sayfası" — bloklar sütunlara dağıtılır,
 *      sütunlar arasında dikey ayraç çizgisi bulunur (kategori şeridi yoktur)
 *   3) tam genişlik alanlar (piyasa şeridi, bülten, reklam) sayfanın dışındadır
 *
 * Blok tipleri CONTRACTS §5.4 ile aynıdır; tanınmayan tip sessizce atlanır.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

$blocks = isset($blocks) && is_array($blocks) ? $blocks : home_blocks();
$gosterilen = [];

$kapakHtml = '';
$seritHtml = '';
$parcalar  = [];   // sütunlara dağıtılacak bloklar
$altHtml   = [];   // sütunların altında tam genişlik duracaklar

foreach ($blocks as $b) {
    $tip   = (string)$b['type'];
    $limit = (int)$b['limit'];
    $ozelBaslik = (string)$b['title'];

    ob_start();

    switch ($tip) {

        // ------------------------------------------------------ kapak manşeti
        case 'headline':
            $items = (isset($headlines) && $headlines)
                ? $headlines
                : headline_posts($limit ?: (int)setting('headline_limit', '6'));
            if (!$items) { break; }
            foreach ($items as $it) { $gosterilen[] = (int)$it['id']; }
            $liste = array_values($items);
            $kapak = array_shift($liste);
            $yan   = array_slice($liste, 0, 3);
            $alt   = array_slice($liste, 3);
            $kimg  = post_image($kapak, 'large');
            $ksrc  = post_image_srcset($kapak);
            ?>
            <section class="kapak" aria-label="<?= esc($ozelBaslik !== '' ? $ozelBaslik : 'Kapak manşeti') ?>">
              <div class="kapak-ana">
                <p class="kapak-ust">
                  <?php if (!empty($kapak['category_name'])): ?>
                    <a href="<?= esc(url_category(['slug' => $kapak['category_slug']])) ?>"><?= esc($kapak['category_name']) ?></a>
                    <span aria-hidden="true">·</span>
                  <?php endif; ?>
                  <time datetime="<?= esc(arr($kapak, 'published_at', '')) ?>"><?= esc(tr_date(arr($kapak, 'published_at', ''), false)) ?></time>
                </p>
                <h2 class="kapak-baslik"><a href="<?= esc(url_post($kapak)) ?>"><?= esc($kapak['title']) ?></a></h2>
                <?php if (!empty($kapak['spot'])): ?>
                  <p class="kapak-spot"><?= esc(excerpt($kapak['spot'], 260)) ?></p>
                <?php endif; ?>
                <?php if ($kimg): ?>
                  <figure class="kapak-gorsel">
                    <a href="<?= esc(url_post($kapak)) ?>">
                      <?php /* Anasayfanin KAPAK gorselidir: sayfanin LCP adayi.
                           Tembel yuklemek tarayicinin onu gec kesfetmesine yol acar. */ ?>
                      <picture>
                        <?php $kwebp = post_image_webp_srcset($kapak); if ($kwebp !== ''): ?>
                          <source type="image/webp" srcset="<?= esc($kwebp) ?>" sizes="(max-width:900px) 100vw, 780px">
                        <?php endif; ?>
                        <img src="<?= esc($kimg) ?>"<?= $ksrc ? ' srcset="' . esc($ksrc) . '" sizes="(max-width:900px) 100vw, 780px"' : '' ?>
                             alt="<?= esc($kapak['title']) ?>" <?= post_image_attrs($kapak, 'large', true) ?>>
                      </picture>
                    </a>
                  </figure>
                <?php endif; ?>
              </div>

              <?php if ($yan): ?>
                <div class="kapak-yan">
                  <?php foreach ($yan as $p): ?>
                    <article class="kapak-yan-oge">
                      <?php if (!empty($p['category_name'])): ?>
                        <span class="kart-kategori"><?= esc($p['category_name']) ?></span>
                      <?php endif; ?>
                      <h3><a href="<?= esc(url_post($p)) ?>"><?= esc($p['title']) ?></a></h3>
                      <time datetime="<?= esc(arr($p, 'published_at', '')) ?>"><?= esc(tr_ago(arr($p, 'published_at', ''))) ?></time>
                    </article>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </section>

            <?php if ($alt): ?>
              <div class="kapak-alt">
                <?php foreach ($alt as $p) { part('card', ['post' => $p, 'boyut' => 'kucuk']); } ?>
              </div>
            <?php endif; ?>
            <hr class="cizgi-kalin">
            <?php
            break;

        // ------------------------------------------------------ son dakika
        case 'breaking':
            $items = breaking($limit ?: 5);
            if (!$items) { break; } ?>
            <section class="sutun-blok">
              <h2 class="sutun-baslik"><?= esc($ozelBaslik !== '' ? $ozelBaslik : 'Son Dakika') ?></h2>
              <ul class="tarihli-liste">
                <?php foreach ($items as $p): ?>
                  <li>
                    <time datetime="<?= esc(arr($p, 'published_at', '')) ?>"><?= esc(tr_date(arr($p, 'published_at', ''), true)) ?></time>
                    <a href="<?= esc(url_post($p)) ?>"><?= esc($p['title']) ?></a>
                  </li>
                <?php endforeach; ?>
              </ul>
            </section>
            <?php break;

        // ------------------------------------------------------ son haberler
        case 'latest':
            $items = latest_posts($limit ?: 8, 0, $gosterilen);
            if (!$items) { break; }
            foreach ($items as $it) { $gosterilen[] = (int)$it['id']; } ?>
            <section class="sutun-blok">
              <h2 class="sutun-baslik"><?= esc($ozelBaslik !== '' ? $ozelBaslik : 'Son Haberler') ?></h2>
              <?php foreach ($items as $p) { part('card', ['post' => $p, 'boyut' => 'orta']); } ?>
            </section>
            <?php break;

        // ------------------------------------------------------ bölüm (kategori)
        case 'category':
            $catId = (int)$b['category_id'];
            $cats = all_categories();
            if ($catId > 0) {
                $cats = array_values(array_filter($cats, function ($c) use ($catId) { return (int)$c['id'] === $catId; }));
            }
            foreach ($cats as $cat) {
                $items = posts_by_category($cat, $limit ?: 4);
                if (!$items) { continue; }
                $one = array_shift($items);
                ?>
                <section class="sutun-blok bolum-blok">
                  <h2 class="sutun-baslik"><a href="<?= esc(url_category($cat)) ?>"><?= esc($cat['name']) ?></a></h2>
                  <?php part('card', ['post' => $one, 'boyut' => 'orta']); ?>
                  <?php if ($items): ?>
                    <ul class="duz-liste">
                      <?php foreach ($items as $p): ?>
                        <li><a href="<?= esc(url_post($p)) ?>"><?= esc($p['title']) ?></a></li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                  <p class="tumu"><a href="<?= esc(url_category($cat)) ?>">Bölümün tamamı →</a></p>
                </section>
                <?php
                // Her kategori kendi sütun bloğu olarak ayrı parçaya yazılır
                $parcalar[] = trim((string)ob_get_clean());
                ob_start();
            }
            break;

        // ------------------------------------------------------ çok okunanlar
        case 'most_read':
            $items = most_read($limit ?: 6, 30);
            if (!$items) { break; } ?>
            <section class="sutun-blok">
              <h2 class="sutun-baslik"><?= esc($ozelBaslik !== '' ? $ozelBaslik : 'Çok Okunanlar') ?></h2>
              <ol class="sirali-liste">
                <?php foreach ($items as $i => $p): ?>
                  <li><span class="sira"><?= $i + 1 ?></span><a href="<?= esc(url_post($p)) ?>"><?= esc($p['title']) ?></a></li>
                <?php endforeach; ?>
              </ol>
            </section>
            <?php break;

        // ------------------------------------------------------ editörün seçimi
        case 'editor_pick':
            $items = editor_picks($limit ?: 4);
            if (!$items) { break; } ?>
            <section class="sutun-blok">
              <h2 class="sutun-baslik"><?= esc($ozelBaslik !== '' ? $ozelBaslik : 'Editörün Seçimi') ?></h2>
              <?php foreach ($items as $p) { part('card', ['post' => $p, 'boyut' => 'kucuk']); } ?>
            </section>
            <?php break;

        // ------------------------------------------------------ video haberler
        case 'video':
            $items = video_posts($limit ?: 4);
            if (!$items) { break; } ?>
            <section class="sutun-blok">
              <h2 class="sutun-baslik"><?= esc($ozelBaslik !== '' ? $ozelBaslik : 'Video Haberler') ?></h2>
              <?php foreach ($items as $p) { part('card', ['post' => $p, 'boyut' => 'kucuk']); } ?>
            </section>
            <?php break;

        // ------------------------------------------------------ hava durumu
        case 'weather':
            $rows = widget_weather_rows();
            if (!$rows) { break; }
            part('widget-hava', ['rows' => $rows, 'baslik' => $ozelBaslik, 'kutu' => true]);
            break;

        default:
            break;
    }

    $html = trim((string)ob_get_clean());

    // Tam genişlik alanlar sütun düzeninin dışına çıkar
    if ($tip === 'headline') {
        $kapakHtml = $html;
        continue;
    }
    if ($tip === 'market') {
        $rows = widget_market_rows();
        if ($rows) { $seritHtml = part_capture('widget-piyasa', ['rows' => $rows, 'baslik' => $ozelBaslik, 'kutu' => false]); }
        continue;
    }
    if ($tip === 'newsletter') {
        $altHtml[] = part_capture('bulten', ['baslik' => $ozelBaslik]);
        continue;
    }
    if ($tip === 'ad') {
        $reklam = ad_slot($b['slot'] !== '' ? $b['slot'] : 'in_article');
        if ($reklam !== '') { $altHtml[] = $reklam; }
        continue;
    }
    if ($html !== '') { $parcalar[] = $html; }
}

// Sütunlara sırayla dağıt (soldan sağa)
$sutunSayisi = theme_setting('sutun_sayisi', '3') === '2' ? 2 : 3;
$sutunlar = array_fill(0, $sutunSayisi, []);
foreach (array_values($parcalar) as $i => $html) {
    $sutunlar[$i % $sutunSayisi][] = $html;
}

$tamGenislik = theme_setting('sidebar_position', 'right') === 'none';
?>
<div class="kagit-home <?= $tamGenislik ? 'tam' : 'iki-sutun' ?>">
  <div class="ana-sutun">

    <?php /* Basın Kanunu m.14 — düzeltme/cevap metni ilk 24 saat kapağın üstünde */ ?>
    <?php part('duzeltme-serit'); ?>

    <?= $seritHtml ?>
    <?= $kapakHtml ?>

    <div class="sayfa" data-sutun="<?= (int)$sutunSayisi ?>">
      <?php foreach ($sutunlar as $sutun): ?>
        <div class="sutun"><?php foreach ($sutun as $html) { echo $html; } ?></div>
      <?php endforeach; ?>
    </div>

    <?php foreach ($altHtml as $html): ?>
      <div class="tam-alan"><?= $html ?></div>
    <?php endforeach; ?>
  </div>

  <?php part('sidebar'); ?>
</div>
