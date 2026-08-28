<?php
/**
 * Bülten teması — anasayfa.
 * Tek sütun "akış" düzeni. Blok dizilimi tema editöründen gelir (home_blocks()).
 * Desteklenen tipler: breaking headline latest category most_read editor_pick
 *                     video ad market weather newsletter
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

$blocks = isset($blocks) && $blocks ? $blocks : home_blocks();
$gosterilen = [];
$sidebarPos = theme_setting('sidebar_position', 'none');
$akisSayac = 0;

/**
 * widget_market() çıktısını şerit satırlarına indirger.
 * Beklenen biçim (inc/widgets.php · Ajan-9):
 *   ['USD'=>['deger','yon','degisim'], …, 'guncelleme'=>…, 'items'=>['USD'=>['label','deger','degisim']]]
 * `items` yoksa düz anahtar/değer biçimi de okunur.
 */
$piyasaVeri = function ($veri) {
    if (!is_array($veri) || !$veri) { return null; }
    $satirlar = [];
    if (!empty($veri['items']) && is_array($veri['items'])) {
        foreach ($veri['items'] as $k => $it) {
            if (!is_array($it)) { continue; }
            $satirlar[] = [
                'ad'    => (string)arr($it, 'label', str_replace('_', ' ', (string)$k)),
                'deger' => (string)arr($it, 'deger', arr($it, 'value', '')),
                'ek'    => (string)arr($it, 'degisim', arr($it, 'change', '')),
            ];
        }
    } else {
        foreach ($veri as $k => $v) {
            if (in_array((string)$k, ['guncelleme', 'items'], true)) { continue; }
            $ad = str_replace('_', ' ', (string)$k);
            if (is_array($v)) {
                $satirlar[] = ['ad' => $ad, 'deger' => (string)arr($v, 'deger', arr($v, 'value', '')),
                               'ek' => (string)arr($v, 'degisim', arr($v, 'change', ''))];
            } elseif (is_scalar($v)) {
                $satirlar[] = ['ad' => $ad, 'deger' => (string)$v, 'ek' => ''];
            }
        }
    }
    if (!$satirlar) { return null; }
    return ['satirlar' => $satirlar, 'guncelleme' => (string)arr($veri, 'guncelleme', '')];
};

/** widget_weather() çıktısı: ['sehir','derece','durum','ikon','guncelleme'] */
$havaVeri = function ($veri) {
    if (!is_array($veri) || !$veri) { return null; }
    $sehir  = (string)arr($veri, 'sehir', arr($veri, 'city', ''));
    $derece = (string)arr($veri, 'derece', arr($veri, 'temp', ''));
    if ($sehir === '' && $derece === '') { return null; }
    if ($derece !== '' && is_numeric(str_replace(',', '.', $derece))) { $derece .= '°'; }
    return [
        'sehir'      => $sehir,
        'derece'     => $derece,
        'durum'      => (string)arr($veri, 'durum', arr($veri, 'text', '')),
        'ikon'       => (string)arr($veri, 'ikon', arr($veri, 'icon', '')),
        'guncelleme' => (string)arr($veri, 'guncelleme', ''),
    ];
};

/** Değişim metnine yön sınıfı verir (+ artı, − eksi). */
$yonSinifi = function ($ek) {
    $ek = trim((string)$ek);
    if ($ek === '') { return ''; }
    if ($ek[0] === '+') { return ' yukari'; }
    if ($ek[0] === '-' || $ek[0] === '−') { return ' asagi'; }
    return '';
};
?>
<div class="anasayfa<?= $sidebarPos === 'none' ? '' : ' kenarli' ?>">
  <div class="akis-sutun">
    <?php foreach ($blocks as $b):
      $tip = (string)$b['type'];
      $limit = (int)$b['limit'];
      $baslik = (string)$b['title'];

      switch ($tip):

        // ---------------------------------------------------------- son dakika
        case 'breaking':
          $items = breaking($limit ?: 5);
          if (!$items) { break; } ?>
          <section class="akis blok blok-sondakika">
            <h2 class="bolum-baslik"><?= esc($baslik !== '' ? $baslik : 'Son Dakika') ?></h2>
            <ul class="duz-liste">
              <?php foreach ($items as $p): ?>
                <li>
                  <time datetime="<?= esc(arr($p, 'published_at', '')) ?>"><?= esc(tr_date(arr($p, 'published_at', ''), true)) ?></time>
                  <a href="<?= esc(url_post($p)) ?>"><?= esc($p['title']) ?></a>
                </li>
              <?php endforeach; ?>
            </ul>
          </section>
          <?php break;

        // ---------------------------------------------------------- manşet
        case 'headline':
          $items = isset($headlines) && $headlines ? $headlines : headline_posts($limit ?: (int)setting('headline_limit', '6'));
          if (!$items) { break; }
          foreach ($items as $it) { $gosterilen[] = (int)$it['id']; }
          $ilk = array_shift($items); ?>
          <section class="akis blok blok-manset">
            <?php part('card', ['post' => $ilk, 'boyut' => 'kapak']); ?>
            <?php if ($items): ?>
              <div class="alt-akis">
                <?php foreach ($items as $p) { part('card', ['post' => $p, 'boyut' => 'orta']); } ?>
              </div>
            <?php endif; ?>
          </section>
          <?php break;

        // ---------------------------------------------------------- son haberler
        case 'latest':
          $items = latest_posts($limit ?: 10, 0, $gosterilen);
          if (!$items) { break; }
          foreach ($items as $it) { $gosterilen[] = (int)$it['id']; } ?>
          <section class="akis blok">
            <h2 class="bolum-baslik"><?= esc($baslik !== '' ? $baslik : 'Akış') ?></h2>
            <?php foreach ($items as $p) { $akisSayac++; part('card', ['post' => $p, 'boyut' => 'orta', 'sira' => $akisSayac]); } ?>
          </section>
          <?php break;

        // ---------------------------------------------------------- kategori
        case 'category':
          $catId = (int)$b['category_id'];
          $cats = all_categories();
          if ($catId) {
            $cats = array_values(array_filter($cats, function ($c) use ($catId) { return (int)$c['id'] === $catId; }));
          }
          foreach ($cats as $cat):
            $items = posts_by_category($cat, $limit ?: 4);
            if (!$items) { continue; } ?>
            <section class="akis blok blok-kategori">
              <h2 class="bolum-baslik">
                <a href="<?= esc(url_category($cat)) ?>"><?= esc($baslik !== '' ? $baslik : $cat['name']) ?></a>
              </h2>
              <?php foreach ($items as $p) { part('card', ['post' => $p, 'boyut' => 'orta']); } ?>
            </section>
          <?php endforeach;
          break;

        // ---------------------------------------------------------- çok okunanlar
        case 'most_read':
          $items = most_read($limit ?: 6, 30);
          if (!$items) { break; } ?>
          <section class="akis blok">
            <h2 class="bolum-baslik"><?= esc($baslik !== '' ? $baslik : 'Çok Okunanlar') ?></h2>
            <ol class="numara-liste">
              <?php foreach ($items as $i => $p): ?>
                <li>
                  <span class="no"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
                  <a href="<?= esc(url_post($p)) ?>"><?= esc($p['title']) ?></a>
                </li>
              <?php endforeach; ?>
            </ol>
          </section>
          <?php break;

        // ---------------------------------------------------------- editörün seçimi
        case 'editor_pick':
          $items = editor_picks($limit ?: 4);
          if (!$items) { break; } ?>
          <section class="akis blok blok-secim">
            <h2 class="bolum-baslik"><?= esc($baslik !== '' ? $baslik : 'Editörün Seçimi') ?></h2>
            <?php foreach ($items as $p) { part('card', ['post' => $p, 'boyut' => 'orta']); } ?>
          </section>
          <?php break;

        // ---------------------------------------------------------- video
        case 'video':
          $items = video_posts($limit ?: 4);
          if (!$items) { break; } ?>
          <section class="akis blok">
            <h2 class="bolum-baslik"><?= esc($baslik !== '' ? $baslik : 'Video Haberler') ?></h2>
            <?php foreach ($items as $p) { part('card', ['post' => $p, 'boyut' => 'orta']); } ?>
          </section>
          <?php break;

        // ---------------------------------------------------------- reklam
        case 'ad':
          $html = ad_slot($b['slot'] !== '' ? $b['slot'] : 'in_article');
          if ($html === '') { break; }
          echo '<div class="akis blok blok-reklam">' . $html . '</div>';
          break;

        // ---------------------------------------------------------- piyasa
        case 'market':
          $piyasa = $piyasaVeri(widget_market());
          if (!$piyasa) { break; } ?>
          <section class="akis blok veri-serit">
            <h2 class="bolum-baslik"><?= esc($baslik !== '' ? $baslik : 'Piyasa') ?></h2>
            <ul class="veri-liste">
              <?php foreach ($piyasa['satirlar'] as $s): ?>
                <li><span class="ad"><?= esc($s['ad']) ?></span>
                    <span class="deger"><?= esc($s['deger']) ?></span>
                    <?php if ($s['ek'] !== ''): ?><span class="ek<?= $yonSinifi($s['ek']) ?>"><?= esc($s['ek']) ?></span><?php endif; ?></li>
              <?php endforeach; ?>
            </ul>
            <?php if ($piyasa['guncelleme'] !== ''): ?>
              <p class="veri-guncelleme"><?= esc($piyasa['guncelleme']) ?></p>
            <?php endif; ?>
          </section>
          <?php break;

        // ---------------------------------------------------------- hava durumu
        case 'weather':
          $hava = $havaVeri(widget_weather());
          if (!$hava) { break; } ?>
          <section class="akis blok veri-serit">
            <h2 class="bolum-baslik"><?= esc($baslik !== '' ? $baslik : 'Hava Durumu') ?></h2>
            <div class="hava-kutu">
              <?php if ($hava['ikon'] !== ''): ?><span class="hava-ikon" aria-hidden="true"><?= esc($hava['ikon']) ?></span><?php endif; ?>
              <?php if ($hava['sehir'] !== ''): ?><span class="hava-sehir"><?= esc($hava['sehir']) ?></span><?php endif; ?>
              <?php if ($hava['derece'] !== ''): ?><span class="hava-derece"><?= esc($hava['derece']) ?></span><?php endif; ?>
              <?php if ($hava['durum'] !== ''): ?><span class="hava-durum"><?= esc($hava['durum']) ?></span><?php endif; ?>
            </div>
            <?php if ($hava['guncelleme'] !== ''): ?>
              <p class="veri-guncelleme"><?= esc($hava['guncelleme']) ?></p>
            <?php endif; ?>
          </section>
          <?php break;

        // ---------------------------------------------------------- bülten kaydı
        case 'newsletter': ?>
          <div class="akis blok"><?php part('newsletter', ['baslik' => $baslik]); ?></div>
          <?php break;

        default:
          // Tema bu bloğu desteklemiyor → sessizce atlanır
          break;
      endswitch;
    endforeach; ?>
  </div>

  <?php part('sidebar'); ?>
</div>
