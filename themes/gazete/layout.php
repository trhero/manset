<?php
/**
 * Gazete teması — üst/alt çatı.
 *
 * CONTRACTS §5.5 kanca noktalarının tamamı burada ya da bu çatının çağırdığı
 * parçalarda uygulanır:
 *   seo_head_tags()  theme_font_link()  theme_css_vars()+theme_custom_css()
 *   ad_slot('header')  ad_slot('sidebar_1'/'sidebar_2')  ad_slot('in_article')
 *   ad_slot('footer')  cookie_notice_html()  part('comments')  künye bağlantısı
 *
 * Şablonlar DB'ye doğrudan dokunmaz; yalnız inc/view.php fonksiyonlarını kullanır.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

// Çıktı başlamadan: gerekiyorsa CSRF oturumunu aç (anasayfa bülten formu için).
// Gerekmiyorsa oturum açılmaz, sayfa önbelleklenebilir kalır.
theme_prepare_forms();

$govdeSinif = theme_body_class();
if (theme_setting('dense', '1') === '1') { $govdeSinif .= ' siki'; }
$serit = theme_setting('ticker_speed', 'orta');
?><!doctype html>
<?php
/* Okuma tercihleri (1.2-13): sunucu YALNIZ varsayılanı basar — okurun seçimi
   localStorage'da ve <head>'deki `okuma-bas` betiğiyle uygulanır. Böylece HTML
   tüm anonim ziyaretçiler için aynı kalır ve sayfa önbelleği bozulmaz. */
$okumaTema = theme_dark_mode() ? 'koyu' : 'sistem';
$okumaGoruntu = theme_dark_mode() ? 'koyu' : 'acik';
?>
<html lang="tr" data-tema="<?= esc($okumaTema) ?>" data-goruntu="<?= esc($okumaGoruntu) ?>" data-olcek="orta">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="<?= esc(theme_setting('color_primary', '#c0392b')) ?>">
<?= seo_head_tags() ?>
<?php if (site('favicon')): ?><link rel="icon" href="<?= esc(site('favicon')) ?>"><?php endif; ?>
<?= theme_font_link() ?>
<link rel="stylesheet" href="<?= esc(theme_url('style.css')) ?>?v=<?= esc(MANSET_VERSION) ?>">
<style><?= theme_css_vars() ?><?= theme_custom_css() ?></style>
<?php part('okuma-bas'); ?>
</head>
<body class="<?= esc($govdeSinif) ?> serit-<?= esc($serit) ?>">

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
