<?php
/**
 * Bülten teması — üst/alt çatı.
 * CONTRACTS §5.5 kancalarının tamamı bu dosyada basılır.
 * Şablonlar DB'ye doğrudan dokunmaz; yalnız inc/view.php fonksiyonlarını kullanır.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

$sidebarPos = theme_setting('sidebar_position', 'none');
$karanlik   = theme_dark_mode();
?><!doctype html>
<html lang="tr"<?= $karanlik ? ' data-tema="karanlik"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<?= seo_head_tags() ?>
<?php if (site('favicon')): ?><link rel="icon" href="<?= esc(site('favicon')) ?>"><?php endif; ?>
<?= theme_font_link() ?>
<link rel="stylesheet" href="<?= esc(theme_url('style.css')) ?>?v=<?= esc(MANSET_VERSION) ?>">
<style><?= theme_css_vars() ?><?= theme_custom_css() ?></style>
</head>
<body class="tema-bulten kenar-<?= esc($sidebarPos) ?><?= $karanlik ? ' karanlik' : '' ?><?= theme_setting('buyuk_gorsel', '1') === '1' ? ' buyuk-gorsel' : '' ?>">

<a class="atla" href="#icerik">İçeriğe geç</a>

<?php part('header'); ?>

<main id="icerik" class="akis-govde">
  <?php $reklamUst = ad_slot('header'); if ($reklamUst !== ''): ?>
    <div class="akis"><?= $reklamUst ?></div>
  <?php endif; ?>
  <?php view_content(); ?>
</main>

<?php part('footer'); ?>
<?= cookie_notice_html() ?>
<script src="<?= esc(url_asset('site.js')) ?>?v=<?= esc(MANSET_VERSION) ?>" defer></script>
<script src="<?= esc(theme_url('theme.js')) ?>?v=<?= esc(MANSET_VERSION) ?>" defer></script>
</body>
</html>
