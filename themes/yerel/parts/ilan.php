<?php
/**
 * Yerel teması — vefat / ilan bloğu.
 *
 * ÖNEMLİ: İçerik AYRI BİR TABLODAN GELMEZ. Yönetici, Panel → Reklamlar
 * ekranında seçilen slota (theme_setting('ilan_slot')) ilan HTML'ini girer;
 * burada yalnız ad_slot() ile basılır. Slot boşsa blok hiç görünmez.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

$ilanSlot = (string)theme_setting('ilan_slot', 'sidebar_2');
if (!in_array($ilanSlot, ['header', 'sidebar_1', 'sidebar_2', 'in_article', 'footer'], true)) {
    $ilanSlot = 'sidebar_2';
}
$ilanHtml = ad_slot($ilanSlot);
if ($ilanHtml === '') { return; }
$ilanBaslik = trim((string)theme_setting('ilan_basligi', 'Vefat ve İlanlar'));
?>
<section class="kutu ilan-kutusu">
  <h2 class="kutu-baslik ilan-baslik"><?= esc($ilanBaslik !== '' ? $ilanBaslik : 'Vefat ve İlanlar') ?></h2>
  <div class="ilan-govde"><?= $ilanHtml ?></div>
  <p class="ilan-not">İlan ve başsağlığı mesajları için <a href="<?= esc(url('kunye')) ?>">iletişim</a> sayfasına bakın.</p>
</section>
