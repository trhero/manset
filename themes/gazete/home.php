<?php
/**
 * Gazete teması — anasayfa.
 *
 * Blok dizilimi tema editöründen gelir: home_blocks() (CONTRACTS §5.4).
 * Desteklenen tipler: breaking headline latest category most_read editor_pick
 *                     video ad market weather newsletter
 * Tanınmayan tip sessizce atlanır.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

$blocks = isset($blocks) && is_array($blocks) ? $blocks : home_blocks();
$gosterilen = [];   // aynı haberi iki blokta tekrarlamamak için
$tamGenislik = theme_setting('sidebar_position', 'right') === 'none';
?>
<div class="anasayfa <?= $tamGenislik ? 'tam' : 'iki-sutun' ?>">
  <div class="ana-sutun">

    <?php /* Basın Kanunu m.14 — düzeltme/cevap metni ilk 24 saat manşetin üstünde */ ?>
    <?php part('duzeltme-serit'); ?>

    <?php foreach ($blocks as $b):
      $tip   = (string)$b['type'];
      $limit = (int)$b['limit'];
      $ozelBaslik = (string)$b['title'];

      switch ($tip):

        // ------------------------------------------------------ son dakika
        case 'breaking':
          $items = breaking($limit ?: 5);
          if (!$items) { break; } ?>
          <section class="blok sondakika-blok">
            <h2 class="blok-baslik"><?= esc($ozelBaslik !== '' ? $ozelBaslik : 'Son Dakika') ?></h2>
            <ul class="sondakika-liste">
              <?php foreach ($items as $p): ?>
                <li>
                  <time datetime="<?= esc(arr($p, 'published_at', '')) ?>"><?= esc(tr_date(arr($p, 'published_at', ''), true)) ?></time>
                  <a href="<?= esc(url_post($p)) ?>"><?= esc($p['title']) ?></a>
                </li>
              <?php endforeach; ?>
            </ul>
          </section>
          <?php break;

        // ------------------------------------------------------ manşet
        case 'headline':
          $items = (isset($headlines) && $headlines)
            ? $headlines
            : headline_posts($limit ?: (int)setting('headline_limit', '6'));
          if (!$items) { break; }
          foreach ($items as $it) { $gosterilen[] = (int)$it['id']; }
          part('blok-manset', ['items' => $items, 'baslik' => $ozelBaslik]);
          break;

        // ------------------------------------------------------ son haberler
        case 'latest':
          $items = latest_posts($limit ?: 9, 0, $gosterilen);
          if (!$items) { break; }
          foreach ($items as $it) { $gosterilen[] = (int)$it['id']; } ?>
          <section class="blok">
            <h2 class="blok-baslik"><?= esc($ozelBaslik !== '' ? $ozelBaslik : 'Son Haberler') ?></h2>
            <div class="izgara">
              <?php foreach ($items as $p) { part('card', ['post' => $p, 'boyut' => 'orta']); } ?>
            </div>
          </section>
          <?php break;

        // ------------------------------------------------------ kategori şeridi
        case 'category':
          $catId = (int)$b['category_id'];
          $cats = all_categories();
          if ($catId > 0) {
            $cats = array_values(array_filter($cats, function ($c) use ($catId) { return (int)$c['id'] === $catId; }));
          }
          foreach ($cats as $cat) {
            $items = posts_by_category($cat, $limit ?: 4);
            if (!$items) { continue; }
            part('blok-kategori', [
              'cat'    => $cat,
              'items'  => $items,
              'baslik' => ($catId > 0 && $ozelBaslik !== '') ? $ozelBaslik : '',
            ]);
          }
          break;

        // ------------------------------------------------------ çok okunanlar
        case 'most_read':
          $items = most_read($limit ?: 6, 30);
          if (!$items) { break; } ?>
          <section class="blok cok-okunan-blok">
            <h2 class="blok-baslik"><?= esc($ozelBaslik !== '' ? $ozelBaslik : 'Çok Okunanlar') ?></h2>
            <div class="izgara dar">
              <?php foreach ($items as $i => $p) { part('card', ['post' => $p, 'boyut' => 'kucuk', 'sira' => $i + 1]); } ?>
            </div>
          </section>
          <?php break;

        // ------------------------------------------------------ editörün seçimi
        case 'editor_pick':
          $items = editor_picks($limit ?: 4);
          if (!$items) { break; } ?>
          <section class="blok editor-blok">
            <h2 class="blok-baslik"><?= esc($ozelBaslik !== '' ? $ozelBaslik : 'Editörün Seçimi') ?></h2>
            <div class="izgara">
              <?php foreach ($items as $p) { part('card', ['post' => $p, 'boyut' => 'orta']); } ?>
            </div>
          </section>
          <?php break;

        // ------------------------------------------------------ video haberler
        case 'video':
          $items = video_posts($limit ?: 4);
          if (!$items) { break; } ?>
          <section class="blok video-blok">
            <h2 class="blok-baslik"><?= esc($ozelBaslik !== '' ? $ozelBaslik : 'Video Haberler') ?></h2>
            <div class="izgara">
              <?php foreach ($items as $p) { part('card', ['post' => $p, 'boyut' => 'orta']); } ?>
            </div>
          </section>
          <?php break;

        // ------------------------------------------------------ reklam
        case 'ad':
          echo ad_slot($b['slot'] !== '' ? $b['slot'] : 'in_article');
          break;

        // ------------------------------------------------------ piyasa şeridi
        case 'market':
          $rows = widget_market_rows();
          if (!$rows) { break; }   // widget modülü yoksa blok atlanır
          part('widget-piyasa', ['rows' => $rows, 'baslik' => $ozelBaslik, 'kutu' => false]);
          break;

        // ------------------------------------------------------ hava durumu
        case 'weather':
          $rows = widget_weather_rows();
          if (!$rows) { break; }
          part('widget-hava', ['rows' => $rows, 'baslik' => $ozelBaslik, 'kutu' => false]);
          break;

        // ------------------------------------------------------ bülten kaydı
        case 'newsletter':
          part('bulten', ['baslik' => $ozelBaslik]);
          break;

        default:
          break;   // tanınmayan blok sessizce atlanır
      endswitch;
    endforeach; ?>

  </div>

  <?php part('sidebar'); ?>
</div>
