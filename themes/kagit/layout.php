<?php
/**
 * Kâğıt teması — üst/alt çatı.
 * CONTRACTS §5.5 kancalarının tamamı burada ya da parçalarda uygulanır.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

// Çıktı başlamadan: gerekiyorsa CSRF oturumunu aç (anasayfa bülten formu için).
theme_prepare_forms();

$govdeSinif = theme_body_class();
if (theme_setting('kagit_doku', '1') === '1')   { $govdeSinif .= ' dokulu'; }
if (theme_setting('drop_cap', '1') === '1')     { $govdeSinif .= ' ilk-harf'; }
if (theme_setting('damga_etiket', '1') === '1') { $govdeSinif .= ' damgali'; }
$sutun = theme_setting('sutun_sayisi', '3') === '2' ? '2' : '3';
?><!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="<?= esc(theme_setting('color_bg', '#f7f3e9')) ?>">
<?= seo_head_tags() ?>
<?php if (site('favicon')): ?><link rel="icon" href="<?= esc(site('favicon')) ?>"><?php endif; ?>
<?= theme_font_link() ?>
<link rel="stylesheet" href="<?= esc(theme_url('style.css')) ?>?v=<?= esc(MANSET_VERSION) ?>">
<style><?= theme_css_vars() ?><?= theme_custom_css() ?></style>
</head>
<body class="<?= esc($govdeSinif) ?> sutun-<?= esc($sutun) ?>">

<a class="skip" href="#icerik">İçeriğe geç</a>

<?php part('header'); ?>

<main id="icerik" class="konteyner" tabindex="-1">
  <?= ad_slot('header') ?>
  <?php view_content(); ?>
</main>

<?php part('footer'); ?>
<?= cookie_notice_html() ?>
<script src="<?= esc(url_asset('site.js')) ?>?v=<?= esc(MANSET_VERSION) ?>" defer></script>
<script src="<?= esc(theme_url('theme.js')) ?>?v=<?= esc(MANSET_VERSION) ?>" defer></script>
</body>
</html>
