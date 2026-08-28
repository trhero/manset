<?php
/** Bülten teması — arama ve etiket sonuçları. */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
$isTag = !empty($isTag);
?>
<div class="liste<?= theme_setting('sidebar_position', 'none') === 'none' ? '' : ' kenarli' ?>">
  <div class="akis-sutun">
    <header class="akis sayfa-ust">
      <p class="ust-etiket"><?= $isTag ? 'Etiket' : 'Arama' ?></p>
      <h1><?= esc($heading) ?></h1>
      <p class="soluk"><?= (int)$total ?> sonuç</p>
    </header>

    <?php if (!$isTag): ?>
      <div class="akis">
        <form class="ara-genis" action="<?= esc(sef_enabled() ? url('arama') : base_url() . '/index.php') ?>" method="get" role="search">
          <?php if (!sef_enabled()): ?><input type="hidden" name="r" value="arama"><?php endif; ?>
          <label class="gizli" for="qq">Arama</label>
          <input type="search" id="qq" name="q" value="<?= esc($term) ?>" placeholder="En az 2 karakter yazın…" maxlength="80">
          <button type="submit">Ara</button>
        </form>
      </div>
    <?php endif; ?>

    <?php if (!$posts): ?>
      <div class="akis"><p class="soluk"><?= $term === '' ? 'Arama yapmak için bir ifade girin.' : 'Sonuç bulunamadı.' ?></p></div>
    <?php else: ?>
      <div class="akis blok">
        <?php foreach ($posts as $p) { part('card', ['post' => $p, 'boyut' => 'orta']); } ?>
      </div>
      <div class="akis"><?= paginate($total, $perPage, $page, function ($n) use ($isTag, $term) {
            return $isTag
              ? url('etiket/' . slugify($term), $n > 1 ? ['sayfa' => $n] : [])
              : url('arama', $n > 1 ? ['q' => $term, 'sayfa' => $n] : ['q' => $term]);
          }) ?></div>
    <?php endif; ?>
  </div>
  <?php part('sidebar'); ?>
</div>
