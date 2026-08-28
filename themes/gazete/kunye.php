<?php
/**
 * Gazete teması — künye sayfası (/kunye).
 *
 * NEDEN AYRI ŞABLON: Künye gövdesi inc/kunye.php tarafından güvenilir sunucu
 * koduyla üretilir ve Basın İlan Kurumu / EİDS doğrulama kodunu içerebilir.
 * `page.php` gövdeyi ikinci kez sanitize_html()'den geçirdiği için o kod
 * siliniyordu. Burada gövde HAM basılır.
 *
 * Diğer temalarda `kunye.php` yoksa view_resolve() bu dosyaya düşer —
 * yani tek dosya beş temayı da kapsar.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
if (empty($page) || !is_array($page)) { return; }

$eksik = function_exists('kunye_missing_required') ? kunye_missing_required() : [];
$ilkeler = page_by_slug('yayin-ilkeleri');
?>
<div class="liste iki-sutun">
  <article class="ana-sutun sabit-sayfa kunye-sayfa">

    <?= breadcrumbs([
      ['label' => 'Anasayfa', 'url' => url()],
      ['label' => 'Künye', 'url' => url('kunye')],
    ]) ?>

    <h1><?= esc($page['title']) ?></h1>

    <?php /* Gövde güvenilir sunucu kodundan gelir; ikinci temizlemeye tabi tutulmaz. */ ?>
    <div class="haber-govde kunye-govde"><?= $page['body'] ?></div>

    <?php /* current_user() DEĞİL: oturumsuz ziyaretçiye çerez takmasın (bkz. view.php). */ ?>
    <?php $kunyeIzleyen = current_user_if_session(); ?>
    <?php if ($eksik && $kunyeIzleyen && can($kunyeIzleyen, 'settings.manage')): ?>
      <div class="kunye-uyari">
        <strong>Yalnız yöneticiler görür:</strong>
        Doldurulmamış künye alanları var — <?= esc(implode(', ', $eksik)) ?>.
        <a href="<?= esc(base_url()) ?>/admin/?p=kunye">Panel → Künye / BİK</a> ekranından tamamlayabilirsiniz.
      </div>
    <?php endif; ?>

    <?php if ($ilkeler): ?>
      <p class="kunye-ilkeler">
        Yayın ilkelerimiz için: <a href="<?= esc(url_page($ilkeler)) ?>"><?= esc($ilkeler['title']) ?></a>
      </p>
    <?php endif; ?>

    <p class="kunye-not"><small>Bu sayfa, 5187 sayılı Basın Kanunu'nun 4. maddesi uyarınca
      ana sayfadan doğrudan erişilebilir biçimde yayımlanmaktadır. Düzeltme ve cevap
      talepleriniz için haber sayfalarındaki “Düzeltme talep et” formunu kullanabilirsiniz.</small></p>
  </article>

  <?php part('sidebar'); ?>
</div>

<style>
/* Künye sayfasına özel: tanım listesi düzeni (yalnız bu şablonda kullanılır). */
.kunye-govde dl{margin:0 0 22px;display:grid;grid-template-columns:minmax(180px,auto) 1fr;gap:6px 18px}
.kunye-govde dt{font-weight:700;color:var(--renk-soluk,#6b7280);font-size:14px}
.kunye-govde dd{margin:0}
.kunye-govde dd p{margin:0}
.kunye-govde h2{font-size:19px;margin-top:1.6em;border-bottom:2px solid var(--renk-cizgi,#e3e7ec);padding-bottom:6px}
.kunye-govde h2:first-child{margin-top:0}
.kunye-uyari{background:#fffaef;border:1px solid #f2ddb0;border-radius:var(--kose,6px);padding:12px 14px;margin:18px 0;font-size:14px}
.kunye-ilkeler{font-size:15px}
.kunye-not{color:var(--renk-soluk,#6b7280);border-top:1px solid var(--renk-cizgi,#e3e7ec);padding-top:14px;margin-top:22px}
@media(max-width:640px){.kunye-govde dl{grid-template-columns:1fr;gap:2px 0}.kunye-govde dd{margin-bottom:12px}}
</style>
