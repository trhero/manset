<?php
/**
 * Gece teması — yorumlar.
 * Beklenen değişkenler: $post, $comments
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
if (setting('comments_enabled', '1') !== '1') { return; }
if (empty($post)) { return; }
$comments = isset($comments) ? $comments : comments_for($post['id']);
$moderasyon = setting('comments_moderate', '1') === '1';
$anonim     = setting('comments_anonymous', '1') === '1';
$oturum     = current_user_if_session();   // oturum açmaz: sayfa önbelleği korunur
?>
<section class="yorumlar kutu" id="yorumlar">
  <h2 class="kutu-baslik">Yorumlar <span class="mono soluk">(<?= count($comments) ?>)</span></h2>

  <?php if (!$comments): ?>
    <p class="soluk">Bu habere henüz yorum yapılmamış. İlk yorumu siz yazın.</p>
  <?php else: ?>
    <ul class="yorum-listesi">
      <?php foreach ($comments as $c): ?>
        <li class="yorum">
          <div class="yorum-ust">
            <strong><?= esc($c['name']) ?></strong>
            <time class="mono" datetime="<?= esc($c['created_at']) ?>"><?= esc(tr_ago($c['created_at'])) ?></time>
          </div>
          <p><?= nl2br(esc($c['body'])) ?></p>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if (!$anonim && !$oturum): ?>
    <p class="soluk yorum-giris">Yorum yazabilmek için
      <a href="<?= esc(url('uye/giris')) ?>">giriş yapmanız</a> gerekiyor.</p>
  <?php else: ?>
    <form class="yorum-form" id="yorumForm" data-post="<?= (int)$post['id'] ?>">
      <h3>Yorum yaz</h3>
      <div class="form-sonuc" id="yorumSonuc" role="status" aria-live="polite" hidden></div>
      <?php if ($oturum): ?>
        <p class="soluk kucuk yorum-kimlik"><?= esc($oturum['name']) ?> olarak yazıyorsunuz.</p>
      <?php endif; ?>
      <div class="satir">
        <label>Adınız <input type="text" name="name" required minlength="2" maxlength="60"
               value="<?= esc($oturum ? $oturum['name'] : '') ?>" autocomplete="name"></label>
        <label>E-posta <input type="email" name="email" required maxlength="120"
               value="<?= esc($oturum ? $oturum['email'] : '') ?>" autocomplete="email">
          <small class="soluk">Yayımlanmaz.</small></label>
      </div>
      <label>Yorumunuz <textarea name="body" required rows="4" minlength="5" maxlength="2000"></textarea></label>
      <label class="onay"><input type="checkbox" name="kvkk" required>
        Yorumumun yayımlanması için kişisel verilerimin işlenmesini onaylıyorum.</label>
      <div class="tuzak" aria-hidden="true"><label>Bu alanı boş bırakın<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
      <?= csrf_field_lazy() ?>
      <button type="submit">Gönder</button>
      <p class="soluk kucuk"><?= $moderasyon
          ? 'Yorumlar editör onayından sonra yayımlanır.'
          : 'Yorumunuz doğrudan yayımlanır; hakaret ve reklam içeren yorumlar kaldırılır.' ?></p>
    </form>
  <?php endif; ?>
</section>
