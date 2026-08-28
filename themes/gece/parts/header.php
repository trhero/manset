<?php
/**
 * Gece teması — sabit son dakika şeridi, üst bant ve menü.
 * Şerit `serit_sabit` ayarı açıkken sayfa üstünde sabit kalır.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

$sonDakika = breaking(6);

// Üst bant arka görseli (isteğe bağlı tema ayarı — medya kütüphanesinden seçilir)
$ustGorsel = trim((string)theme_setting('ust_gorsel', ''));
$ustStil = '';
if ($ustGorsel !== '' && strpos($ustGorsel, '..') === false && preg_match('#^[A-Za-z0-9._\-/]+$#', $ustGorsel)) {
    $ustStil = ' style="background-image:url(' . esc(url_upload($ustGorsel)) . ')"';
}
?>
<?php if ($sonDakika): ?>
  <div class="sondakika-serit" role="region" aria-label="Son dakika haberleri">
    <div class="konteyner">
      <span class="sd-etiket"><span class="sd-nokta" aria-hidden="true"></span>SON DAKİKA</span>
      <div class="sd-akis">
        <ul>
          <?php foreach ($sonDakika as $b): ?>
            <li>
              <time datetime="<?= esc($b['published_at']) ?>"><?= esc(date('H:i', strtotime((string)$b['published_at']))) ?></time>
              <a href="<?= esc(url_post($b)) ?>"><?= esc($b['title']) ?></a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
<?php endif; ?>

<header class="site-ust<?= $ustStil !== '' ? ' gorselli' : '' ?>"<?= $ustStil ?>>
  <div class="konteyner ust-orta">
    <a class="logo" href="<?= esc(url()) ?>">
      <?php if (site('logo')): ?>
        <img src="<?= esc(site('logo')) ?>" alt="<?= esc(site('title')) ?>" height="44">
      <?php else: ?>
        <span class="logo-metin"><?= esc(site('title')) ?></span>
      <?php endif; ?>
    </a>
    <?php if (site('slogan')): ?><span class="slogan"><?= esc(site('slogan')) ?></span><?php endif; ?>

    <form class="arama" action="<?= esc(sef_enabled() ? url('arama') : base_url() . '/index.php') ?>" method="get" role="search">
      <?php if (!sef_enabled()): ?><input type="hidden" name="r" value="arama"><?php endif; ?>
      <label class="gizli" for="q">Arama</label>
      <input type="search" id="q" name="q" placeholder="Ara…" maxlength="80"
             value="<?= esc(isset($_GET['q']) ? (string)$_GET['q'] : '') ?>">
      <button type="submit">Ara</button>
    </form>
    <span class="saat mono"><?= esc(tr_date(now(), false)) ?></span>
  </div>

  <nav class="ana-menu" aria-label="Ana menü">
    <div class="konteyner">
      <ul>
        <li><a href="<?= esc(url()) ?>">Anasayfa</a></li>
        <?php foreach (menu() as $c): ?>
          <li><a href="<?= esc(url_category($c)) ?>" style="--kat:<?= esc($c['color']) ?>"><?= esc($c['name']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </nav>
</header>
