<?php
/**
 * Gazete teması — üye hesap sayfaları.
 *
 * Gövde HAM basılır (form içerir); bu yüzden `page` şablonundan ayrı bir şablondur.
 * Diğer temalarda `hesap.php` yoksa view_resolve() bu dosyaya düşer — tek dosya
 * beş temayı da kapsar.
 *
 * Beklenen değişkenler: $bolum, $uye, $veri, $dogrulamaSonuc, $sifirlamaToken, $geriBolum
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

$bolum = isset($bolum) ? $bolum : 'giris';
$uye   = isset($uye) ? $uye : null;
$veri  = isset($veri) ? $veri : [];
$ad    = $uye && function_exists('member_display_name') ? member_display_name($uye) : '';
?>
<div class="liste iki-sutun">
  <div class="ana-sutun hesap-sayfa">

    <?= breadcrumbs([
      ['label' => 'Anasayfa', 'url' => url()],
      ['label' => $page['title'], 'url' => member_url($bolum)],
    ]) ?>

    <?php if ($uye): ?>
      <nav class="hesap-menu" aria-label="Hesap menüsü">
        <a href="<?= esc(member_url('profil')) ?>" class="<?= $bolum === 'profil' ? 'etkin' : '' ?>">Hesabım</a>
        <a href="<?= esc(member_url('kaydedilenler')) ?>" class="<?= $bolum === 'kaydedilenler' ? 'etkin' : '' ?>">Kaydettiklerim</a>
        <a href="<?= esc(member_url('yorumlarim')) ?>" class="<?= $bolum === 'yorumlarim' ? 'etkin' : '' ?>">Yorumlarım</a>
        <a href="<?= esc(member_url('abonelik')) ?>" class="<?= $bolum === 'abonelik' ? 'etkin' : '' ?>">Aboneliğim</a>
        <a href="<?= esc(member_url('cikis')) ?>" class="cikis-bag">Çıkış</a>
      </nav>
    <?php endif; ?>

    <h1><?= esc($page['title']) ?></h1>

    <?php
    // ---------------------------------------------------------------- doğrulama sonucu
    if ($bolum === 'dogrula'):
      if (is_array($dogrulamaSonuc)):
        $ok = !empty($dogrulamaSonuc['ok']); ?>
        <div class="uyari-kutu <?= $ok ? 'ok' : 'err' ?>">
          <?= esc($ok ? (arr($dogrulamaSonuc, 'message', 'E-posta adresiniz doğrulandı.'))
                      : (arr($dogrulamaSonuc, 'error', 'Doğrulama bağlantısı geçersiz ya da süresi dolmuş.'))) ?>
        </div>
        <p><a class="dugme" href="<?= esc(member_url($ok ? 'giris' : 'kayit')) ?>">
          <?= $ok ? 'Giriş yap' : 'Yeniden dene' ?></a></p>
      <?php else: ?>
        <p class="soluk">Doğrulama bağlantısı eksik. E-postanızdaki bağlantıyı kullanın.</p>
      <?php endif;

    // ---------------------------------------------------------------- profil
    elseif ($bolum === 'profil'): ?>
      <div class="hesap-ozet">
        <p><strong><?= esc($ad) ?></strong><br><span class="soluk"><?= esc($uye['email']) ?></span></p>
        <?php $ab = arr($veri, 'abonelik', ['tier' => 'free']); ?>
        <p>
          <?php if (arr($ab, 'tier') === 'premium'): ?>
            <span class="rozet-premium">ABONE</span>
            <?php if (arr($ab, 'valid_until')): ?>
              <span class="soluk"><?= esc(tr_date(arr($ab, 'valid_until'), false)) ?> tarihine kadar</span>
            <?php endif; ?>
          <?php else: ?>
            <span class="rozet-uye">ÜCRETSİZ ÜYE</span>
          <?php endif; ?>
        </p>
      </div>
      <?php part('uye-form', ['tip' => 'profil', 'uye' => $uye]); ?>
      <?php part('uye-form', ['tip' => 'parola-degistir', 'uye' => $uye]); ?>

    <?php // -------------------------------------------------------- kaydedilenler
    elseif ($bolum === 'kaydedilenler'):
      $kayitlar = arr($veri, 'kayitlar', []);
      if (!$kayitlar): ?>
        <p class="bos">Henüz haber kaydetmediniz. Haber sayfalarındaki “Kaydet” düğmesini kullanabilirsiniz.</p>
      <?php else: ?>
        <div class="izgara">
          <?php foreach ($kayitlar as $p) { part('card', ['post' => $p, 'boyut' => 'orta']); } ?>
        </div>
      <?php endif;

    // ---------------------------------------------------------------- yorumlarım
    elseif ($bolum === 'yorumlarim'):
      $yorumlar = arr($veri, 'yorumlar', []);
      if (!$yorumlar): ?>
        <p class="bos">Henüz yorum yazmadınız.</p>
      <?php else: ?>
        <ul class="yorum-listesi">
          <?php foreach ($yorumlar as $y): ?>
            <li class="yorum">
              <div class="yorum-ust">
                <?php if (!empty($y['post_title'])): ?>
                  <a href="<?= esc(url_post(['id' => (int)arr($y, 'post_id', 0), 'slug' => (string)arr($y, 'post_slug', '')])) ?>">
                    <?= esc($y['post_title']) ?></a>
                <?php endif; ?>
                <time datetime="<?= esc(arr($y, 'created_at', '')) ?>"><?= esc(tr_ago(arr($y, 'created_at', ''))) ?></time>
                <?php
                $durum = (string)arr($y, 'status', '');
                if ($durum === 'pending') { echo '<span class="rozet-uye">onay bekliyor</span>'; }
                elseif ($durum === 'spam') { echo '<span class="rozet-premium">yayımlanmadı</span>'; }
                ?>
              </div>
              <p><?= nl2br(esc(arr($y, 'body', ''))) ?></p>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif;

    // ---------------------------------------------------------------- abonelik
    elseif ($bolum === 'abonelik'):
      $ab = arr($veri, 'abonelik', ['tier' => 'free']); ?>
      <div class="abonelik-kutu">
        <?php if (arr($ab, 'tier') === 'premium'): ?>
          <h2>Aboneliğiniz etkin</h2>
          <p>Abonelere özel içeriklerin tamamını okuyabilirsiniz.</p>
          <?php if (arr($ab, 'valid_until')): ?>
            <p class="soluk">Bitiş: <strong><?= esc(tr_date(arr($ab, 'valid_until'))) ?></strong></p>
          <?php endif; ?>
        <?php else: ?>
          <h2>Ücretsiz üyelik</h2>
          <?php if (!empty($ab['expired'])): ?>
            <p>Aboneliğinizin süresi doldu. Yenilemek için yayın kuruluşuyla iletişime geçin.</p>
          <?php else: ?>
            <p>Abonelere özel içerikleri okumak için abonelik gerekir.</p>
          <?php endif; ?>
          <?php $iletisim = page_by_slug('iletisim'); ?>
          <?php if ($iletisim): ?>
            <p><a class="dugme" href="<?= esc(url_page($iletisim)) ?>">Abonelik için iletişim</a></p>
          <?php endif; ?>
        <?php endif; ?>
        <p class="mini soluk">Abonelik işlemleri yayın kuruluşu tarafından yürütülür;
          Manşet ödeme altyapısı içermez.</p>
      </div>

    <?php // -------------------------------------------------- giriş / kayıt / parola
    else:
      part('uye-form', ['tip' => $bolum, 'token' => isset($sifirlamaToken) ? $sifirlamaToken : '',
                        'geri' => isset($geriBolum) ? $geriBolum : '']);
    endif; ?>

  </div>

  <?php part('sidebar'); ?>
</div>

<script src="<?= esc(url_asset('uye.js')) ?>?v=<?= esc(MANSET_VERSION) ?>" defer></script>

<style>
/* Üye hesap sayfalarına özel yerleşim (yalnız bu şablon kullanır). */
.hesap-sayfa{background:var(--renk-yuzey,#fff);border-radius:var(--kose,6px);padding:26px}
.hesap-menu{display:flex;gap:4px;flex-wrap:wrap;border-bottom:1px solid var(--renk-cizgi,#e3e7ec);margin-bottom:18px}
.hesap-menu a{padding:9px 14px;font-size:14px;border-bottom:3px solid transparent}
.hesap-menu a.etkin{border-bottom-color:var(--renk-ana,#c0392b);font-weight:700}
.hesap-menu a:hover{text-decoration:none;background:#f6f8fa}
.hesap-menu .cikis-bag{margin-left:auto;color:var(--renk-soluk,#6b7280)}
.hesap-ozet{background:#f6f8fa;border-radius:var(--kose,6px);padding:14px 16px;margin-bottom:20px}
.hesap-ozet p{margin:0 0 6px}
.abonelik-kutu{border:1px solid var(--renk-cizgi,#e3e7ec);border-radius:var(--kose,6px);padding:20px}
.uyari-kutu{padding:12px 15px;border-radius:var(--kose,6px);margin-bottom:16px;border:1px solid}
.uyari-kutu.ok{background:#f2fbf5;border-color:#b7e0c0;color:#14532d}
.uyari-kutu.err{background:#fdf3f3;border-color:#f0b6b6;color:#7a1f1f}
.rozet-premium{background:#b8860b;color:#fff;border-radius:3px;padding:2px 8px;font-size:11px;font-weight:800;letter-spacing:.04em}
.rozet-uye{background:#eef1f4;color:#4b5563;border-radius:3px;padding:2px 8px;font-size:11px;font-weight:700}
</style>
