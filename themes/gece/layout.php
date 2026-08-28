<?php
/**
 * Gece teması — üst/alt çatı.
 * CONTRACTS §5.5 kancalarının tamamı bu dosyada basılır.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

$sidebarPos = theme_setting('sidebar_position', 'right');
$karanlik   = theme_dark_mode();
$seritSabit = theme_setting('serit_sabit', '1') === '1';
?><!doctype html>
<?php
/* Okuma tercihleri (1.2-13): sunucu YALNIZ varsayılanı basar — okurun seçimi
   localStorage'da ve <head>'deki `okuma-bas` betiğiyle uygulanır. Böylece HTML
   tüm anonim ziyaretçiler için aynı kalır ve sayfa önbelleği bozulmaz.
   `karanlik`/`aydinlik` gövde sınıfı yöneticinin site geneli seçimidir; okurun
   seçimi ondan daha özgül seçicilerle (html[data-goruntu=…] body) üzerine biner. */
$okumaTema = $karanlik ? 'koyu' : 'sistem';
$okumaGoruntu = $karanlik ? 'koyu' : 'acik';
?>
<html lang="tr" data-tema="<?= esc($okumaTema) ?>" data-goruntu="<?= esc($okumaGoruntu) ?>" data-olcek="orta">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<?= seo_head_tags() ?>
<?php if (site('favicon')): ?><link rel="icon" href="<?= esc(site('favicon')) ?>"><?php endif; ?>
<?= theme_font_link() ?>
<link rel="stylesheet" href="<?= esc(theme_url('style.css')) ?>?v=<?= esc(MANSET_VERSION) ?>">
<style><?= theme_css_vars() ?><?= theme_custom_css() ?></style>
<?php part('okuma-bas'); ?>
</head>
<body class="tema-gece <?= $karanlik ? 'karanlik' : 'aydinlik' ?> kenar-<?= esc($sidebarPos) ?> kart-<?= esc(theme_setting('card_style', 'border')) ?><?= $seritSabit ? ' serit-sabit' : '' ?><?= theme_setting('neon_cizgi', '1') === '1' ? ' neon' : '' ?>">

<a class="atla" href="#icerik">İçeriğe geç</a>

<?php part('header'); ?>

<main id="icerik" class="konteyner ana-alan">
  <?= ad_slot('header') ?>
  <?php view_content(); ?>
</main>

<?php part('footer'); ?>
<?= cookie_notice_html() ?>
<script src="<?= esc(url_asset('site.js')) ?>?v=<?= esc(MANSET_VERSION) ?>" defer></script>
<script src="<?= esc(theme_url('theme.js')) ?>?v=<?= esc(MANSET_VERSION) ?>" defer></script>
</body>
</html>
