<?php
/**
 * Gazete teması — üye formları.
 *
 * Beklenen: $tip = giris | kayit | parola | profil | parola-degistir
 *           $uye (profil formları için), $token (parola sıfırlama), $geri
 *
 * OTURUMSUZ ÖN YÜZ: csrf_field_lazy() kullanılır; anahtar assets/site.js tarafından
 * kullanıcı forma dokununca doldurulur (CONTRACTS §3.1). Gönderim assets/uye.js ile.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

$tip   = isset($tip) ? $tip : 'giris';
$uye   = isset($uye) ? $uye : null;
$token = isset($token) ? (string)$token : '';
$geri  = isset($geri) ? (string)$geri : '';
?>

<?php if ($tip === 'giris'): ?>
  <form class="uye-form" data-uye="members.login" data-geri="<?= esc($geri) ?>">
    <div class="form-sonuc" role="status" hidden></div>
    <label>E-posta<input type="email" name="email" required autocomplete="email" maxlength="190"></label>
    <label>Parola<input type="password" name="password" required autocomplete="current-password"></label>
    <?= csrf_field_lazy() ?>
    <button type="submit">Giriş yap</button>
    <p class="form-alt">
      <a href="<?= esc(member_url('parola')) ?>">Parolamı unuttum</a> ·
      Hesabınız yok mu? <a href="<?= esc(member_url('kayit')) ?>">Üye olun</a>
    </p>
  </form>

<?php elseif ($tip === 'kayit'): ?>
  <form class="uye-form" data-uye="members.register">
    <div class="form-sonuc" role="status" hidden></div>
    <label>Adınız<input type="text" name="display_name" maxlength="80" autocomplete="name"></label>
    <label>E-posta<input type="email" name="email" required autocomplete="email" maxlength="190"></label>
    <label>Parola<input type="password" name="password" required minlength="8" autocomplete="new-password">
      <small>En az 8 karakter.</small></label>
    <label class="onay"><input type="checkbox" name="kvkk" value="1" required>
      Kişisel verilerimin işlenmesine ilişkin aydınlatma metnini okudum, onaylıyorum.</label>
    <div class="tuzak" aria-hidden="true"><label>Bu alanı boş bırakın
      <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
    <?= csrf_field_lazy() ?>
    <button type="submit">Üye ol</button>
    <p class="form-alt">Zaten üye misiniz? <a href="<?= esc(member_url('giris')) ?>">Giriş yapın</a></p>
  </form>

<?php elseif ($tip === 'parola'): ?>
  <?php if ($token !== ''): ?>
    <form class="uye-form" data-uye="members.reset_apply">
      <div class="form-sonuc" role="status" hidden></div>
      <p class="soluk">Yeni parolanızı belirleyin.</p>
      <input type="hidden" name="token" value="<?= esc($token) ?>">
      <label>Yeni parola<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
      <?= csrf_field_lazy() ?>
      <button type="submit">Parolayı güncelle</button>
    </form>
  <?php else: ?>
    <form class="uye-form" data-uye="members.reset_request">
      <div class="form-sonuc" role="status" hidden></div>
      <p class="soluk">Kayıtlı e-posta adresinizi girin; sıfırlama bağlantısı gönderelim.</p>
      <label>E-posta<input type="email" name="email" required autocomplete="email" maxlength="190"></label>
      <?= csrf_field_lazy() ?>
      <button type="submit">Sıfırlama bağlantısı gönder</button>
      <p class="form-alt"><a href="<?= esc(member_url('giris')) ?>">← Giriş ekranına dön</a></p>
    </form>
  <?php endif; ?>

<?php elseif ($tip === 'profil' && $uye): ?>
  <form class="uye-form" data-uye="members.profile_save">
    <div class="form-sonuc" role="status" hidden></div>
    <h2>Profil bilgileri</h2>
    <label>Görünen ad<input type="text" name="display_name" maxlength="80"
      value="<?= esc(arr($uye, 'display_name', '') !== '' ? $uye['display_name'] : arr($uye, 'name', '')) ?>"></label>
    <label>Hakkımda<textarea name="bio" rows="3" maxlength="500"><?= esc(arr($uye, 'bio', '')) ?></textarea></label>
    <?= csrf_field_lazy() ?>
    <button type="submit">Kaydet</button>
  </form>

<?php elseif ($tip === 'parola-degistir' && $uye): ?>
  <form class="uye-form" data-uye="members.password">
    <div class="form-sonuc" role="status" hidden></div>
    <h2>Parola değiştir</h2>
    <label>Mevcut parola<input type="password" name="old" required autocomplete="current-password"></label>
    <label>Yeni parola<input type="password" name="new" required minlength="8" autocomplete="new-password"></label>
    <?= csrf_field_lazy() ?>
    <button type="submit">Parolayı değiştir</button>
  </form>
<?php endif; ?>

<style>
/* Üye formları — hesap sayfası ve giriş kutuları için ortak. */
.uye-form{max-width:420px;margin:0 0 26px}
.uye-form h2{font-size:18px;margin-bottom:12px}
.uye-form label{display:block;margin:0 0 14px;font-size:14px;font-weight:600}
.uye-form label small{display:block;font-weight:400;color:var(--renk-soluk,#6b7280);margin-top:3px}
.uye-form input[type=text],.uye-form input[type=email],.uye-form input[type=password],.uye-form textarea{
  width:100%;margin-top:5px;padding:10px 12px;border:1px solid var(--renk-cizgi,#e3e7ec);
  border-radius:var(--kose,6px);font:inherit;font-weight:400}
.uye-form .onay{display:flex;gap:9px;align-items:flex-start;font-weight:400}
.uye-form .onay input{width:auto;margin-top:4px}
.uye-form button{background:var(--renk-ana,#c0392b);color:#fff;border:0;padding:11px 22px;
  border-radius:var(--kose,6px);font:inherit;font-weight:600;cursor:pointer}
.uye-form button[disabled]{opacity:.55;cursor:default}
.uye-form .form-alt{font-size:14px;color:var(--renk-soluk,#6b7280);margin-top:14px}
.uye-form .tuzak{position:absolute;left:-9999px;height:0;overflow:hidden}
</style>
