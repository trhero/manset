<?php
/** Gece teması — kenar çubuğu: reklam slotları, çok okunanlar, etiket bulutu. */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
if (theme_setting('sidebar_position', 'right') === 'none') { return; }

$cokOkunan = most_read(6, 30);
$secim = editor_picks(4);
?>
<aside class="kenar">
  <?= ad_slot('sidebar_1') ?>

  <?php if ($cokOkunan): ?>
    <section class="kutu">
      <h2 class="kutu-baslik">Çok Okunanlar</h2>
      <ol class="sirali-liste">
        <?php foreach ($cokOkunan as $i => $p): ?>
          <li>
            <span class="sira mono"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
            <a href="<?= esc(url_post($p)) ?>"><?= esc($p['title']) ?><?= post_locked_badge($p) ?></a>
          </li>
        <?php endforeach; ?>
      </ol>
    </section>
  <?php endif; ?>

  <?= ad_slot('sidebar_2') ?>

  <?php if ($secim): ?>
    <section class="kutu">
      <h2 class="kutu-baslik">Editörün Seçimi</h2>
      <ul class="sade-liste">
        <?php foreach ($secim as $p): ?>
          <li><a href="<?= esc(url_post($p)) ?>"><?= esc($p['title']) ?><?= post_locked_badge($p) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  <section class="kutu">
    <h2 class="kutu-baslik">Kategoriler</h2>
    <ul class="etiket-bulut">
      <?php foreach (all_categories() as $c): ?>
        <li><a href="<?= esc(url_category($c)) ?>" style="--kat:<?= esc($c['color']) ?>"><?= esc($c['name']) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </section>
</aside>
