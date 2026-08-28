<?php
/**
 * Gazete teması — hava durumu.
 * Veri: widget_weather_rows() (inc/view.php) — inc/widgets.php (Ajan-9) yoksa boş.
 * Beklenen değişkenler: $rows (isteğe bağlı), $baslik (isteğe bağlı), $kutu (bool)
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

$rows = isset($rows) && is_array($rows) ? $rows : widget_weather_rows();
if (!$rows) { return; }
$kutu = !empty($kutu);
$baslik = isset($baslik) && $baslik !== '' ? $baslik : 'Hava Durumu';
?>
<section class="<?= $kutu ? 'kutu hava-kutu' : 'blok hava-blok' ?>" aria-label="<?= esc($baslik) ?>">
  <h2 class="<?= $kutu ? 'kutu-baslik' : 'blok-baslik' ?>"><?= esc($baslik) ?></h2>
  <ul class="hava-liste">
    <?php foreach ($rows as $r): ?>
      <li>
        <span class="hava-sehir"><?= esc($r['city']) ?></span>
        <?php if ($r['temp'] !== ''): ?><span class="hava-derece"><?= esc($r['temp']) ?>°</span><?php endif; ?>
        <?php if ($r['text'] !== ''): ?><span class="hava-durum"><?= esc($r['text']) ?></span><?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
