<?php
/**
 * Kâğıt teması — sabit sayfa ve künye.
 * Beklenen değişkenler: $page, $isKunye (isteğe bağlı)
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

    <hr class="cizgi-kalin">
    <h1><?= esc($page['title']) ?></h1>
    <?php if (!$isKunye && $guncelleme !== ''): ?>
      <p class="sayfa-tarih">Son güncelleme:
        <time datetime="<?= esc($guncelleme) ?>"><?= esc(tr_date($guncelleme, false)) ?></time></p>
    <?php endif; ?>
    <hr class="cizgi-ince">

    <div class="haber-govde sayfa-govde"><?= $govde ?></div>

    <?php if ($isKunye): ?>
      <hr class="cizgi-ince">
      <p class="kunye-not"><small>Bu sayfa 5187 sayılı Basın Kanunu'nun 4. maddesi uyarınca
        ana sayfadan doğrudan erişilebilir biçimde yayımlanmaktadır.</small></p>
    <?php endif; ?>
  </article>

  <?php part('sidebar'); ?>
</div>
