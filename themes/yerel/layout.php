<?php
/**
 * Yerel teması — üst/alt çatı.
 * CONTRACTS §5.5 kancalarının tamamı bu dosyada basılır.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

$sidebarPos = theme_setting('sidebar_position', 'right');
?><!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<?= seo_head_tags() ?>
<?php if (site('favicon')): ?><link rel="icon" href="<?= esc(site('favicon')) ?>"><?php endif; ?>
<?= theme_font_link() ?>
<link rel="stylesheet" href="<?= esc(theme_url('style.css')) ?>?v=<?= esc(MANSET_VERSION) ?>">
<style><?= theme_css_vars() ?><?= theme_custom_css() ?></style>
</head>
<body class="tema-yerel kenar-<?= esc($sidebarPos) ?> kart-<?= esc(theme_setting('card_style', 'border')) ?>">

<a class="atla" href="#icerik">İçeriğe geç</a>

<?php part('header'); ?>

<main id="icerik" class="konteyner ana-alan">
  <?= ad_slot('header') ?>
  <?php view_content(); ?>
</main>

<?php part('footer'); ?>
<?= cookie_notice_html() ?>
<script src="<?= esc(url_asset('site.js')) ?>?v=<?= esc(MANSET_VERSION) ?>" defer></script>
</body>
</html>
