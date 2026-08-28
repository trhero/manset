<?php
/**
 * Kâğıt teması — arama ve etiket sonuçları.
 * Beklenen değişkenler: $heading, $term, $isTag, $posts, $total, $page, $perPage
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

$isTag = !empty($isTag);
$term  = isset($term) ? (string)$term : '';
$aramaUrl = sef_enabled() ? url('arama') : base_url() . '/index.php';
$oneriAcik = theme_setting('show_suggest', '1') === '1';
$sutunSayisi = theme_setting('sutun_sayisi', '3') === '2' ? 2 : 3;
?>
<div class="liste iki-sutun">
  <div class="ana-sutun">

    <?= breadcrumbs([
      ['label' => 'Anasayfa', 'url' => url()],
      ['label' => (string)$heading, 'url' => $isTag ? url_tag($term) : url_search($term)],
    ]) ?>

    <header class="bolum-ust">
      <hr class="cizgi-kalin">
      <h1><?= esc($heading) ?></h1>
      <p class="bolum-sayi"><?= (int)$total ?> sonuç<?= $page > 1 ? ' · ' . (int)$page . '. sayfa' : '' ?></p>
      <hr class="cizgi-ince">
    </header>

    <?php if (!$isTag): ?>
      <form class="arama-genis<?= $oneriAcik ? ' oneri-var' : '' ?>" action="<?= esc($aramaUrl) ?>" method="get"
            role="search" <?= $oneriAcik ? 'data-oneri="1"' : '' ?>>
        <?php if (!sef_enabled()): ?><input type="hidden" name="r" value="arama"><?php endif; ?>
        <label class="gizli" for="qGenis">Arama ifadesi</label>
        <input type="search" id="qGenis" name="q" value="<?= esc($term) ?>" placeholder="En az 2 karakter yazın…"
               maxlength="80" autocomplete="off" role="combobox" aria-expanded="false"
               aria-autocomplete="list" aria-controls="aramaOneriGenis">
        <button type="submit">Ara</button>
        <ul class="arama-oneri" id="aramaOneriGenis" role="listbox" aria-label="Arama önerileri" hidden></ul>
      </form>
    <?php endif; ?>

    <?php if (!$posts): ?>
      <div class="bos-kutu">
        <p class="bos"><?= $term === ''
          ? 'Arama yapmak için bir ifade girin.'
          : 'Aradığınız ifadeyle eşleşen haber bulunamadı.' ?></p>
        <?php $son = latest_posts(4); if ($son): ?>
          <hr class="cizgi-ince">
          <h2 class="sutun-baslik">Bunlara göz atın</h2>
          <div class="ilgili-izgara">
            <?php foreach ($son as $p) { part('card', ['post' => $p, 'boyut' => 'kucuk']); } ?>
          </div>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="sayfa" data-sutun="<?= (int)$sutunSayisi ?>">
        <?php
          $sutunlar = array_fill(0, $sutunSayisi, []);
          foreach (array_values($posts) as $i => $p) { $sutunlar[$i % $sutunSayisi][] = $p; }
          foreach ($sutunlar as $sutun): ?>
          <div class="sutun">
            <?php foreach ($sutun as $p) { part('card', ['post' => $p, 'boyut' => 'orta']); } ?>
          </div>
        <?php endforeach; ?>
      </div>

      <?= paginate($total, $perPage, $page, function ($n) use ($isTag, $term) {
            return $isTag
              ? url('etiket/' . slugify($term), $n > 1 ? ['sayfa' => $n] : [])
              : url('arama', $n > 1 ? ['q' => $term, 'sayfa' => $n] : ['q' => $term]);
          }) ?>
    <?php endif; ?>
  </div>

  <?php part('sidebar'); ?>
</div>
