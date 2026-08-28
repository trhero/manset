<?php
/**
 * Yerel teması — bölge bandı, logo, menü, son dakika ve duyuru kutusu.
 * Bölge adı ve duyuru metni tema ayarlarından gelir (theme_setting).
 * Mobilde menü hamburger düğmesiyle açılır; arama canlı öneri verir
 * (uç nokta: public.search_suggest — inc/api/public.php).
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

$bolge   = trim((string)theme_setting('bolge_adi', ''));
$duyuru  = trim((string)theme_setting('duyuru_metni', ''));
$duyuruB = trim((string)theme_setting('duyuru_bag', ''));
$sonDakika = breaking(5);
$oneriAcik  = theme_setting('show_suggest', '1') === '1';
$aramaTerim = isset($_GET['q']) ? (string)$_GET['q'] : '';
?>
<?php if ($bolge !== ''): ?>
  <div class="bolge-bandi">
    <div class="konteyner">
      <span class="bolge-ad"><?= esc($bolge) ?></span>
      <span class="bolge-ayrac" aria-hidden="true">•</span>
      <time datetime="<?= esc(date('Y-m-d')) ?>"><?= esc(tr_date(now(), false)) ?></time>
      <span class="bolge-sag"><a href="<?= esc(url('kunye')) ?>">Künye ve iletişim</a></span>
    </div>
  </div>
<?php endif; ?>

<header class="site-ust">
  <div class="konteyner ust-orta">
    <button type="button" class="menu-dugme" data-menu-ac aria-expanded="false"
            aria-controls="anaMenu" aria-label="Menüyü aç">
      <span></span><span></span><span></span>
    </button>

    <a class="logo" href="<?= esc(url()) ?>">
      <?php if (site('logo')): ?>
        <img src="<?= esc(site('logo')) ?>" alt="<?= esc(site('title')) ?>" height="52">
      <?php else: ?>
        <span class="logo-metin"><?= esc(site('title')) ?></span>
      <?php endif; ?>
      <?php if (site('slogan')): ?><small class="slogan"><?= esc(site('slogan')) ?></small><?php endif; ?>
    </a>

    <form class="arama<?= $oneriAcik ? ' oneri-var' : '' ?>" role="search" method="get"
          action="<?= esc(sef_enabled() ? url('arama') : base_url() . '/index.php') ?>"
          <?= $oneriAcik ? 'data-oneri="1"' : '' ?>>
      <?php if (!sef_enabled()): ?><input type="hidden" name="r" value="arama"><?php endif; ?>
      <label class="gizli" for="q">Arama</label>
      <input type="search" id="q" name="q" placeholder="Haberlerde ara…" maxlength="80" autocomplete="off"
             value="<?= esc($aramaTerim) ?>" role="combobox" aria-expanded="false"
             aria-autocomplete="list" aria-controls="aramaOneri">
      <button type="submit">Ara</button>
      <ul class="arama-oneri" id="aramaOneri" role="listbox" aria-label="Arama önerileri" hidden></ul>
    </form>
  </div>

  <nav class="ana-menu" id="anaMenu" aria-label="Ana menü">
    <div class="konteyner">
      <ul>
        <li><a href="<?= esc(url()) ?>">Anasayfa</a></li>
        <?php foreach (menu() as $c): ?>
          <li><a href="<?= esc(url_category($c)) ?>" style="--kat:<?= esc($c['color']) ?>"><?= esc($c['name']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </nav>

  <?php if ($sonDakika): ?>
    <div class="son-dakika">
      <div class="konteyner">
        <span class="sd-etiket">SON DAKİKA</span>
        <ul>
          <?php foreach ($sonDakika as $b): ?>
            <li><a href="<?= esc(url_post($b)) ?>"><?= esc($b['title']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($duyuru !== ''): ?>
    <div class="konteyner">
      <aside class="duyuru-kutusu" role="note">
        <span class="duyuru-etiket">DUYURU</span>
        <p>
          <?php if ($duyuruB !== '' && sanitize_is_safe_url($duyuruB)): ?>
            <a href="<?= esc($duyuruB) ?>"><?= esc($duyuru) ?></a>
          <?php else: ?>
            <?= esc($duyuru) ?>
          <?php endif; ?>
        </p>
      </aside>
    </div>
  <?php endif; ?>
</header>
