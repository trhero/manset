<?php
/**
 * Gazete teması — son dakika şeridi (kayan), logo, arama (canlı öneri), menü.
 * Canlı öneri uç noktası: public.search_suggest (inc/api/public.php)
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

$sonDakika = breaking(6);
$aramaUrl  = sef_enabled() ? url('arama') : base_url() . '/index.php';
$aramaTerim = isset($_GET['q']) ? (string)$_GET['q'] : '';
$oneriAcik = theme_setting('show_suggest', '1') === '1';
?>
<header class="site-ust">

  <?php if ($sonDakika): ?>
    <div class="son-dakika" data-serit="1">
      <div class="konteyner">
        <span class="etiket" aria-hidden="true">SON DAKİKA</span>
        <div class="serit-pencere">
          <ul class="serit-ray" aria-label="Son dakika haberleri">
            <?php foreach ($sonDakika as $b): ?>
              <li><a href="<?= esc(url_post($b)) ?>"><?= esc($b['title']) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <button type="button" class="serit-durdur" aria-label="Şeridi duraklat" data-serit-durdur>❚❚</button>
      </div>
    </div>
  <?php endif; ?>

  <div class="ust-orta konteyner">
    <button type="button" class="menu-dugme" data-menu-ac aria-expanded="false" aria-controls="anaMenu" aria-label="Menüyü aç">
      <span></span><span></span><span></span>
    </button>

    <a class="logo" href="<?= esc(url()) ?>">
      <?php if (site('logo')): ?>
        <img src="<?= esc(site('logo')) ?>" alt="<?= esc(site('title')) ?>" height="48">
      <?php else: ?>
        <span class="logo-metin"><?= esc(site('title')) ?></span>
      <?php endif; ?>
      <?php if (site('slogan')): ?><small class="slogan"><?= esc(site('slogan')) ?></small><?php endif; ?>
    </a>

    <form class="arama<?= $oneriAcik ? ' oneri-var' : '' ?>" action="<?= esc($aramaUrl) ?>" method="get" role="search"
          <?= $oneriAcik ? 'data-oneri="1"' : '' ?>>
      <?php if (!sef_enabled()): ?><input type="hidden" name="r" value="arama"><?php endif; ?>
      <label class="gizli" for="q">Haberlerde ara</label>
      <input type="search" id="q" name="q" placeholder="Haberlerde ara…" value="<?= esc($aramaTerim) ?>"
             maxlength="80" autocomplete="off" role="combobox" aria-expanded="false"
             aria-autocomplete="list" aria-controls="aramaOneri">
      <button type="submit">Ara</button>
      <ul class="arama-oneri" id="aramaOneri" role="listbox" aria-label="Arama önerileri" hidden></ul>
    </form>

    <div class="tarih"><time datetime="<?= esc(date('Y-m-d')) ?>"><?= esc(tr_date(now(), false)) ?></time></div>
  </div>

  <nav class="ana-menu" id="anaMenu" aria-label="Ana menü">
    <div class="konteyner">
      <ul>
        <li><a href="<?= esc(url()) ?>">Anasayfa</a></li>
        <?php foreach (menu() as $c): ?>
          <li><a href="<?= esc(url_category($c)) ?>" style="--kat:<?= esc($c['color']) ?>"><?= esc($c['name']) ?></a></li>
        <?php endforeach; ?>
        <li class="menu-sag"><a href="<?= esc(url('kunye')) ?>">Künye</a></li>
      </ul>
    </div>
  </nav>
</header>
