<?php
/**
 * Bülten teması — yorumlar.
 * Beklenen değişkenler: $post, $comments
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
if (setting('comments_enabled', '1') !== '1') { return; }
if (empty($post)) { return; }
$comments = isset($comments) ? $comments : comments_for($post['id']);
?>
<section class="yorumlar" id="yorumlar">
  <h2 class="bolum-baslik">Okur yorumları <span class="soluk">(<?= count($comments) ?>)</span></h2>

  <?php if (!$comments): ?>
    <p class="soluk">Bu yazıya henüz yorum yapılmamış. İlk yorumu siz yazın.</p>
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

  <form class="yorum-form" id="yorumForm" data-post="<?= (int)$post['id'] ?>">
    <h3>Yorum yaz</h3>
    <div class="form-sonuc" id="yorumSonuc" role="status" hidden></div>
    <div class="satir">
      <label>Adınız <input type="text" name="name" required maxlength="60"></label>
      <label>E-posta <input type="email" name="email" required maxlength="120">
        <small class="soluk">Yayımlanmaz.</small></label>
    </div>
    <label>Yorumunuz <textarea name="body" required rows="4" maxlength="2000"></textarea></label>
    <label class="onay"><input type="checkbox" name="kvkk" required>
      Yorumumun yayımlanması için kişisel verilerimin işlenmesini onaylıyorum.</label>
    <div class="tuzak" aria-hidden="true"><label>Bu alanı boş bırakın<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
    <?= csrf_field_lazy() ?>
    <button type="submit">Gönder</button>
    <p class="soluk"><small>Yorumlar editör onayından sonra yayımlanır.</small></p>
  </form>
</section>
