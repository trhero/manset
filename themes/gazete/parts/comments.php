<?php
/**
 * Gazete teması — yorum listesi ve formu.
 * Gönderim uç noktası: public.comment (assets/site.js sarmalıyor).
 * Beklenen değişkenler: $post, $comments (isteğe bağlı)
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
if (empty($post) || !is_array($post)) { return; }
if (setting('comments_enabled', '1') !== '1') { return; }

$comments  = isset($comments) && is_array($comments) ? $comments : comments_for($post['id']);
$moderasyon = setting('comments_moderate', '1') === '1';
$anonim     = setting('comments_anonymous', '1') === '1';
$oturum     = current_user_if_session();   // oturum açmaz: sayfa önbelleği korunur
?>
<section class="yorumlar" id="yorumlar" aria-label="Yorumlar">
  <h2>Yorumlar <span class="sayi">(<?= count($comments) ?>)</span></h2>

  <?php if (!$comments): ?>
    <p class="bos">Bu habere henüz yorum yapılmamış. İlk yorumu siz yazın.</p>
  <?php else: ?>
    <ul class="yorum-listesi">
      <?php foreach ($comments as $c): ?>
        <li class="yorum">
          <div class="yorum-ust">
            <strong><?= esc($c['name']) ?></strong>
            <time datetime="<?= esc($c['created_at']) ?>"><?= esc(tr_ago($c['created_at'])) ?></time>
          </div>
          <p><?= nl2br(esc($c['body'])) ?></p>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if (!$anonim && !$oturum): ?>
    <p class="bos">Yorum yazabilmek için giriş yapmanız gerekiyor.</p>
  <?php else: ?>
    <form class="yorum-form" id="yorumForm" data-post="<?= (int)$post['id'] ?>">
      <h3>Yorum yaz</h3>
      <div class="form-sonuc" id="yorumSonuc" role="status" aria-live="polite" hidden></div>
      <div class="satir">
        <label for="yorumAd">Adınız
          <input type="text" id="yorumAd" name="name" required minlength="2" maxlength="60"
                 value="<?= esc($oturum ? $oturum['name'] : '') ?>" autocomplete="name"></label>
        <label for="yorumEposta">E-posta
          <input type="email" id="yorumEposta" name="email" required maxlength="120"
                 value="<?= esc($oturum ? $oturum['email'] : '') ?>" autocomplete="email">
          <small>Yayımlanmaz.</small></label>
      </div>
      <label for="yorumGovde">Yorumunuz
        <textarea id="yorumGovde" name="body" required rows="4" minlength="5" maxlength="2000"></textarea></label>
      <label class="onay">
        <input type="checkbox" name="kvkk" required>
        <span>Yorumumun yayımlanması için kişisel verilerimin işlenmesini onaylıyorum.</span>
      </label>
      <div class="tuzak" aria-hidden="true">
        <label>Bu alanı boş bırakın<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
      </div>
      <?= csrf_field_lazy() ?>
      <button type="submit">Gönder</button>
      <p><small><?= $moderasyon
          ? 'Yorumlar editör onayından sonra yayımlanır.'
          : 'Yorumunuz doğrudan yayımlanır; hakaret ve reklam içeren yorumlar kaldırılır.' ?></small></p>
    </form>
  <?php endif; ?>
</section>
