<?php
/** Gece teması — arama ve etiket sonuçları. */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
$isTag = !empty($isTag);
$sidebarPos = theme_setting('sidebar_position', 'right');
?>
<div class="liste <?= $sidebarPos === 'none' ? 'tam' : 'iki-sutun' ?>">
  <div class="ana-sutun">
    <header class="liste-ust">
      <p class="ust-etiket mono"><?= $isTag ? 'Etiket' : 'Arama' ?></p>
      <h1><?= esc($heading) ?></h1>
      <p class="soluk mono"><?= (int)$total ?> sonuç</p>
    </header>

    <?php if (!$isTag): ?>
      <form class="arama-genis" action="<?= esc(sef_enabled() ? url('arama') : base_url() . '/index.php') ?>" method="get" role="search">
        <?php if (!sef_enabled()): ?><input type="hidden" name="r" value="arama"><?php endif; ?>
        <label class="gizli" for="qq">Arama</label>
        <input type="search" id="qq" name="q" value="<?= esc($term) ?>" placeholder="En az 2 karakter yazın…" maxlength="80">
        <button type="submit">Ara</button>
      </form>
    <?php endif; ?>

    <?php if (!$posts): ?>
      <p class="soluk"><?= $term === '' ? 'Arama yapmak için bir ifade girin.' : 'Sonuç bulunamadı.' ?></p>
    <?php else: ?>
      <div class="izgara">
        <?php foreach ($posts as $p) { part('card', ['post' => $p, 'boyut' => 'orta']); } ?>
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
