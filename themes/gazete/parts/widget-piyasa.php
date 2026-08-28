<?php
/**
 * Gazete teması — piyasa şeridi.
 * Veri: widget_market_rows() (inc/view.php) — inc/widgets.php (Ajan-9) yoksa boş.
 * Beklenen değişkenler: $rows (isteğe bağlı), $baslik (isteğe bağlı), $kutu (bool, kenar çubuğu biçimi)
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

$rows = isset($rows) && is_array($rows) ? $rows : widget_market_rows();
if (!$rows) { return; }
$kutu = !empty($kutu);
$baslik = isset($baslik) && $baslik !== '' ? $baslik : 'Piyasalar';
?>
<?php if ($kutu): ?>
<section class="kutu piyasa-kutu">
  <h2 class="kutu-baslik"><?= esc($baslik) ?></h2>
  <ul class="piyasa-liste">
    <?php foreach ($rows as $r): ?>
      <li>
        <span class="piyasa-ad"><?= esc($r['label']) ?></span>
        <span class="piyasa-deger"><?= esc($r['value']) ?></span>
        <?php if ($r['change'] !== ''): ?>
          <span class="piyasa-degisim <?= esc($r['dir']) ?>"><?= esc($r['change']) ?></span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php else: ?>
<section class="piyasa-serit" aria-label="<?= esc($baslik) ?>">
  <div class="piyasa-ic">
    <span class="piyasa-etiket"><?= esc($baslik) ?></span>
    <ul>
      <?php foreach ($rows as $r): ?>
        <li>
          <span class="piyasa-ad"><?= esc($r['label']) ?></span>
          <span class="piyasa-deger"><?= esc($r['value']) ?></span>
          <?php if ($r['change'] !== ''): ?>
            <span class="piyasa-degisim <?= esc($r['dir']) ?>"><?= esc($r['change']) ?></span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
<?php endif; ?>
