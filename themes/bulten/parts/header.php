<?php
/** Bülten teması — üst bant: sayı/tarih vurgusu, ince ayraçlar, tipografik logo. */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

$sonDakika = breaking(3);
$sayiGoster = theme_setting('sayi_goster', '1') === '1';
// "Sayı" numarası: yayın başlangıcı sabit alınarak gün farkından üretilir (DB'ye dokunmaz).
$sayi = (int)floor((time() - mktime(0, 0, 0, 1, 1, 2024)) / 86400) + 1;
?>
<header class="ust">
  <?php if ($sayiGoster): ?>
    <div class="akis kunye-bandi">
      <span class="sayi-no">Sayı <?= esc(number_format($sayi, 0, ',', '.')) ?></span>
      <span class="ayrac-nokta" aria-hidden="true">·</span>
      <time datetime="<?= esc(date('Y-m-d')) ?>"><?= esc(tr_date(now(), false)) ?></time>
    </div>
  <?php endif; ?>

  <div class="akis ust-orta">
    <a class="logo" href="<?= esc(url()) ?>">
      <?php if (site('logo')): ?>
        <img src="<?= esc(site('logo')) ?>" alt="<?= esc(site('title')) ?>" height="56">
      <?php else: ?>
        <span class="logo-metin"><?= esc(site('title')) ?></span>
      <?php endif; ?>
    </a>
    <?php if (site('slogan')): ?><p class="slogan"><?= esc(site('slogan')) ?></p><?php endif; ?>
  </div>

  <nav class="menu" aria-label="Ana menü">
    <div class="akis">
      <ul>
        <li><a href="<?= esc(url()) ?>">Anasayfa</a></li>
        <?php foreach (menu() as $c): ?>
          <li><a href="<?= esc(url_category($c)) ?>"><?= esc($c['name']) ?></a></li>
        <?php endforeach; ?>
      </ul>
      <form class="ara" action="<?= esc(sef_enabled() ? url('arama') : base_url() . '/index.php') ?>" method="get" role="search">
        <?php if (!sef_enabled()): ?><input type="hidden" name="r" value="arama"><?php endif; ?>
        <label class="gizli" for="q">Arama</label>
        <input type="search" id="q" name="q" placeholder="Ara…" maxlength="80"
               value="<?= esc(isset($_GET['q']) ? (string)$_GET['q'] : '') ?>">
      </form>
    </div>
  </nav>

  <?php if ($sonDakika): ?>
    <div class="akis serit">
      <span class="serit-etiket">Son dakika</span>
      <ul>
        <?php foreach ($sonDakika as $b): ?>
          <li><a href="<?= esc(url_post($b)) ?>"><?= esc($b['title']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</header>
