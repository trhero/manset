<?php
/**
 * Kâğıt teması — yan sütun.
 * Kutu yok: dikey ayraç çizgisiyle ayrılmış "sütun" bölümleri.
 * Kanca: ad_slot('sidebar_1') · ad_slot('sidebar_2')
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
if (theme_setting('sidebar_position', 'right') === 'none') { return; }

$cokOkunan = most_read(6, 30);
$editor    = editor_picks(4);
$piyasa    = widget_market_rows();
$hava      = widget_weather_rows();
?>
<aside class="kenar" aria-label="Yan sütun">
  <?= ad_slot('sidebar_1') ?>

  <?php if ($piyasa) { part('widget-piyasa', ['rows' => $piyasa, 'kutu' => true]); } ?>
  <?php if ($hava) { part('widget-hava', ['rows' => $hava, 'kutu' => true]); } ?>

  <?php if ($cokOkunan): ?>
    <section class="kutu">
      <h2 class="kutu-baslik">Çok Okunanlar</h2>
      <ol class="sirali-liste">
        <?php foreach ($cokOkunan as $i => $p): ?>
          <li><span class="sira"><?= $i + 1 ?></span><a href="<?= esc(url_post($p)) ?>"><?= esc($p['title']) ?><?= post_locked_badge($p) ?></a></li>
        <?php endforeach; ?>
      </ol>
    </section>
  <?php endif; ?>

  <?= ad_slot('sidebar_2') ?>

  <?php if ($editor): ?>
    <section class="kutu">
      <h2 class="kutu-baslik">Editörün Seçimi</h2>
      <ul class="mini-liste">
        <?php foreach ($editor as $p): ?>
          <li><a href="<?= esc(url_post($p)) ?>"><?= esc($p['title']) ?><?= post_locked_badge($p) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  <section class="kutu">
    <h2 class="kutu-baslik">Bölümler</h2>
    <ul class="kategori-listesi">
      <?php foreach (all_categories() as $c): ?>
        <li><a href="<?= esc(url_category($c)) ?>"><?= esc($c['name']) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </section>
</aside>
