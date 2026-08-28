<?php
/**
 * Kâğıt teması — gazete başlığı (masthead).
 * Ortalanmış logo, üstünde ve altında ince/kalın çizgi çiftleri, künye satırı,
 * serif menü ve arama (canlı öneri: public.search_suggest).
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

$sonDakika = breaking(4);
$aramaUrl  = sef_enabled() ? url('arama') : base_url() . '/index.php';
$aramaTerim = isset($_GET['q']) ? (string)$_GET['q'] : '';
$oneriAcik = theme_setting('show_suggest', '1') === '1';
$slogan = trim((string)theme_setting('ust_slogan', ''));
?>
<header class="gazete-basligi">
  <div class="konteyner">

    <div class="kunye-satiri">
      <span class="kunye-sol"><?= esc(tr_date(now(), false)) ?></span>
      <span class="kunye-orta"><?= esc(site('slogan') !== '' ? site('slogan') : $slogan) ?></span>
      <span class="kunye-sag"><a href="<?= esc(url('kunye')) ?>">Künye</a></span>
    </div>

    <hr class="cizgi-kalin">

    <div class="logo-alani">
      <button type="button" class="menu-dugme" data-menu-ac aria-expanded="false" aria-controls="anaMenu" aria-label="Menüyü aç">
        <span></span><span></span><span></span>
      </button>

      <a class="logo" href="<?= esc(url()) ?>">
        <?php if (site('logo')): ?>
          <img src="<?= esc(site('logo')) ?>" alt="<?= esc(site('title')) ?>" height="56">
        <?php else: ?>
          <span class="logo-metin"><?= esc(site('title')) ?></span>
        <?php endif; ?>
      </a>

      <form class="arama<?= $oneriAcik ? ' oneri-var' : '' ?>" action="<?= esc($aramaUrl) ?>" method="get" role="search"
            <?= $oneriAcik ? 'data-oneri="1"' : '' ?>>
        <?php if (!sef_enabled()): ?><input type="hidden" name="r" value="arama"><?php endif; ?>
        <label class="gizli" for="q">Haberlerde ara</label>
        <input type="search" id="q" name="q" placeholder="Ara…" value="<?= esc($aramaTerim) ?>"
               maxlength="80" autocomplete="off" role="combobox" aria-expanded="false"
               aria-autocomplete="list" aria-controls="aramaOneri">
        <button type="submit">Ara</button>
        <ul class="arama-oneri" id="aramaOneri" role="listbox" aria-label="Arama önerileri" hidden></ul>
      </form>
    </div>

    <hr class="cizgi-ince">

    <nav class="ana-menu" id="anaMenu" aria-label="Ana menü">
      <ul>
        <li><a href="<?= esc(url()) ?>">Anasayfa</a></li>
        <?php foreach (menu() as $c): ?>
          <li><a href="<?= esc(url_category($c)) ?>"><?= esc($c['name']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </nav>

    <hr class="cizgi-kalin">

    <?php if ($sonDakika): ?>
      <p class="son-dakika-satiri">
        <span class="etiket">SON DAKİKA</span>
        <?php foreach ($sonDakika as $i => $b): ?>
          <?php if ($i > 0): ?><span class="ayrac" aria-hidden="true">—</span><?php endif; ?>
          <a href="<?= esc(url_post($b)) ?>"><?= esc($b['title']) ?></a>
        <?php endforeach; ?>
      </p>
      <hr class="cizgi-ince">
    <?php endif; ?>

  </div>
</header>
