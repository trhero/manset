<?php
/**
 * Gazete teması — sabit sayfa ve künye.
 * Beklenen değişkenler: $page (dizi), $isKunye (bool, isteğe bağlı)
 *
 * /kunye içeriğini inc/kunye.php (Ajan-9) üretir; modül yoksa yönlendirici
 * yer tutucu metin gönderir. Bu şablon yalnız çerçeveyi çizer.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
if (empty($page) || !is_array($page)) { return; }

$isKunye = !empty($isKunye);
$govde = sanitize_html((string)arr($page, 'body', ''), true);
$guncelleme = (string)arr($page, 'updated_at', '');
?>
<div class="liste iki-sutun">
  <article class="ana-sutun sabit-sayfa<?= $isKunye ? ' kunye-sayfa' : '' ?>">

    <?= breadcrumbs([
      ['label' => 'Anasayfa', 'url' => url()],
      ['label' => (string)$page['title'], 'url' => $isKunye ? url('kunye') : url_page($page)],
    ]) ?>

    <h1><?= esc($page['title']) ?></h1>

    <?php if (!$isKunye && $guncelleme !== ''): ?>
      <p class="sayfa-tarih">Son güncelleme:
        <time datetime="<?= esc($guncelleme) ?>"><?= esc(tr_date($guncelleme, false)) ?></time></p>
    <?php endif; ?>

    <div class="haber-govde"><?= $govde ?></div>

    <?php if ($isKunye): ?>
      <p class="kunye-not"><small>Bu sayfa 5187 sayılı Basın Kanunu'nun 4. maddesi uyarınca
        ana sayfadan doğrudan erişilebilir biçimde yayımlanmaktadır.</small></p>
    <?php endif; ?>
  </article>

  <?php part('sidebar'); ?>
</div>
