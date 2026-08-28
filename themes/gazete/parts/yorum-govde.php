<?php
/**
 * ORTAK yorum gövdesi (1.3-07, Ajan-B).
 *
 * Beş temanın `parts/comments.php` dosyası bu parçaya devreder; `part()`
 * bulamadığı parçayı `themes/gazete/` altında aradığı için tek kopya yeter.
 * Temaya özgü olan yalnız başlık sınıfıdır ve değişkenle gelir.
 *
 * NEDEN TEK KOPYA: 1.2'de aynı yorum bloğu dört temada AYRI AYRI duruyordu ve
 * dördü de birbirinden hafifçe ayrışmıştı. Yanıt zinciri gibi bir GÜVENLİK
 * mantığı dört yerde tekrarlansaydı, düzeltmenin biri unutulduğunda o tema
 * sessizce açık kalırdı.
 *
 * Beklenen değişkenler:
 *   $post              (zorunlu)
 *   $yorumBolumSinif   <section> ek sınıfı      — örn. 'kutu'
 *   $yorumBaslikSinif  <h2> sınıfı              — örn. 'kutu-baslik'
 *   $yorumBaslik       başlık metni             — örn. 'Okur yorumları'
 *   $yorumSayiSinif    sayının sınıfı           — örn. 'mono soluk'
 *
 * ÇEREZ KURALI (CONTRACTS §3.1): bu dosya `current_user()` ÇAĞIRMAZ.
 * Oturum yalnız `current_user_if_session()` ile — yani zaten çerezi olan
 * ziyaretçi için — okunur. Rozetler yorum satırındaki `author_role`
 * sütunundan gelir, kullanıcı sorgusu yapılmaz.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
if (empty($post) || !is_array($post)) { return; }
if (setting('comments_enabled', '1') !== '1') { return; }

$yorumBolumSinif  = isset($yorumBolumSinif)  ? (string)$yorumBolumSinif  : '';
$yorumBaslikSinif = isset($yorumBaslikSinif) ? (string)$yorumBaslikSinif : '';
$yorumBaslik      = isset($yorumBaslik)      ? (string)$yorumBaslik      : 'Yorumlar';
$yorumSayiSinif   = isset($yorumSayiSinif)   ? (string)$yorumSayiSinif   : 'sayi';

$moderasyon = setting('comments_moderate', '1') === '1';
$anonim     = setting('comments_anonymous', '1') === '1';
$oturum     = current_user_if_session();   // oturum AÇMAZ: sayfa önbelleği korunur

// Yorum sayfası, önbellek beyaz listesinde ZATEN bulunan `sayfa` parametresini
// kullanır (`cache_query_whitelist()` = q, sayfa, r). Yeni bir parametre adı
// uydurmak, ikinci yorum sayfasını önbellek dışına atardı.
$yorumSayfa = max(1, (int)(isset($_GET['sayfa']) ? $_GET['sayfa'] : 1));

if (function_exists('comments_thread')) {
    $veri = comments_thread((int)$post['id'], $yorumSayfa);
} else {
    // Modül yüklenmemiş (dosya silinmiş) — 1.2 davranışına düş.
    $duz = comments_for((int)$post['id']);
    $veri = ['items' => [], 'total' => count($duz), 'count' => count($duz),
             'page' => 1, 'pages' => 1, 'per_page' => max(1, count($duz))];
    foreach ($duz as $d) { $veri['items'][] = ['comment' => $d, 'replies' => []]; }
}

$yanitAcik  = function_exists('comments_replies_enabled') ? comments_replies_enabled() : false;
$bildirAcik = function_exists('comments_report_enabled')  ? comments_report_enabled()  : false;

/** Sayfalama adresi — SEF açıkken de kapalıyken de doğru ayracı seçer. */
$yorumUrl = function ($n) use ($post) {
    $u = url_post($post);
    if ((int)$n > 1) { $u .= (strpos($u, '?') === false ? '?' : '&') . 'sayfa=' . (int)$n; }
    return $u . '#yorumlar';
};

/** Tek bir yorum satırı. $kok=false ise yanıttır (yanıtın yanıtı olmaz). */
$yorumSatir = function ($c, $kok) use ($yanitAcik, $bildirAcik) {
    $rozet = function_exists('comments_badge') ? comments_badge($c) : null;
    ?>
    <li class="yorum<?= $kok ? '' : ' yorum-yanit' ?>" id="yorum-<?= (int)$c['id'] ?>">
      <div class="yorum-ust">
        <strong><?= esc($c['name']) ?></strong>
        <?php if ($rozet): ?>
          <span class="yorum-rozet yorum-rozet-<?= esc($rozet['tur']) ?>"><?= esc($rozet['etiket']) ?></span>
        <?php endif; ?>
        <time datetime="<?= esc($c['created_at']) ?>"><?= esc(tr_ago($c['created_at'])) ?></time>
      </div>
      <p><?= nl2br(esc($c['body'])) ?></p>
      <div class="yorum-eylem">
        <?php if ($kok && $yanitAcik): ?>
          <button type="button" class="yorum-dugme" data-yorum-yanit="<?= (int)$c['id'] ?>"
                  data-yorum-ad="<?= esc_attr($c['name']) ?>">Yanıtla</button>
        <?php endif; ?>
        <?php if ($bildirAcik): ?>
          <button type="button" class="yorum-dugme yorum-bildir" data-yorum-bildir="<?= (int)$c['id'] ?>">Bildir</button>
        <?php endif; ?>
      </div>
    </li>
    <?php
};
?>
<section class="yorumlar <?= esc($yorumBolumSinif) ?>" id="yorumlar" aria-label="Yorumlar"
         data-api="<?= esc(base_url() . '/api.php') ?>">
  <h2<?= $yorumBaslikSinif !== '' ? ' class="' . esc($yorumBaslikSinif) . '"' : '' ?>>
    <?= esc($yorumBaslik) ?> <span class="<?= esc($yorumSayiSinif) ?>">(<?= (int)$veri['count'] ?>)</span>
  </h2>

  <?php if (!$veri['items']): ?>
    <p class="bos soluk">Bu habere henüz yorum yapılmamış. İlk yorumu siz yazın.</p>
  <?php else: ?>
    <ul class="yorum-listesi">
      <?php foreach ($veri['items'] as $dugum): ?>
        <?php $yorumSatir($dugum['comment'], true); ?>
        <?php if (!empty($dugum['replies'])): ?>
          <li class="yorum-yanitlar-sarma">
            <ul class="yorum-yanitlar">
              <?php foreach ($dugum['replies'] as $y) { $yorumSatir($y, false); } ?>
            </ul>
          </li>
        <?php endif; ?>
      <?php endforeach; ?>
    </ul>
    <?php if ((int)$veri['pages'] > 1): ?>
      <div class="yorum-sayfalama">
        <?= paginate((int)$veri['total'], (int)$veri['per_page'], (int)$veri['page'], $yorumUrl) ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if (!$anonim && !$oturum): ?>
    <p class="soluk yorum-giris">Yorum yazabilmek için
      <a href="<?= esc(url('uye/giris')) ?>">giriş yapmanız</a> gerekiyor.</p>
  <?php else: ?>
    <div id="yorumFormYuva">
      <form class="yorum-form" id="yorumForm" data-post="<?= (int)$post['id'] ?>">
        <h3 id="yorumFormBaslik">Yorum yaz</h3>
        <p class="yorum-yanit-bilgi" id="yorumYanitBilgi" hidden>
          <span id="yorumYanitAd"></span> adlı okura yanıt yazıyorsunuz.
          <button type="button" class="yorum-dugme" id="yorumYanitIptal">Vazgeç</button>
        </p>
        <div class="form-sonuc" id="yorumSonuc" role="status" aria-live="polite" hidden></div>
        <?php if ($oturum): ?>
          <p class="soluk kucuk yorum-kimlik"><?= esc($oturum['name']) ?> olarak yazıyorsunuz.</p>
        <?php endif; ?>
        <div class="satir">
          <label for="yorumAd">Adınız
            <input type="text" id="yorumAd" name="name" required minlength="2" maxlength="60"
                   value="<?= esc($oturum ? $oturum['name'] : '') ?>" autocomplete="name"></label>
          <label for="yorumEposta">E-posta
            <input type="email" id="yorumEposta" name="email" required maxlength="120"
                   value="<?= esc($oturum ? $oturum['email'] : '') ?>" autocomplete="email">
            <small class="soluk">Yayımlanmaz.</small></label>
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
        <input type="hidden" name="parent_id" id="yorumParent" value="0">
        <?= csrf_field_lazy() ?>
        <button type="submit">Gönder</button>
        <p><small class="soluk"><?= $moderasyon
            ? 'Yorumlar editör onayından sonra yayımlanır.'
            : 'Yorumunuz doğrudan yayımlanır; hakaret ve reklam içeren yorumlar kaldırılır.' ?></small></p>
      </form>
    </div>
  <?php endif; ?>
</section>

<style>
/* Yalnız 1.3 ile gelen ögeler. Temaların kendi .yorum / .yorum-form kuralları
   olduğu gibi kalır; buradaki hiçbir seçici onları ezmez. */
.yorum-yanitlar-sarma { list-style:none; }
.yorum-yanitlar { list-style:none; margin:0 0 0 1.6rem; padding:0; border-left:2px solid rgba(128,128,128,.28); }
.yorum-yanitlar > .yorum { padding-left:.9rem; }
.yorum-rozet { display:inline-block; margin:0 .35rem; padding:.05em .5em; border-radius:999px;
  font-size:.72em; line-height:1.6; vertical-align:middle; border:1px solid rgba(128,128,128,.45); }
.yorum-rozet-personel { border-color:rgba(30,140,70,.6); }
.yorum-eylem { margin-top:.25rem; display:flex; gap:.8rem; flex-wrap:wrap; }
.yorum-dugme { background:none; border:0; padding:.35rem 0; font:inherit; font-size:.82em;
  color:inherit; opacity:.7; cursor:pointer; text-decoration:underline; min-height:24px; }
.yorum-dugme:hover, .yorum-dugme:focus { opacity:1; }
.yorum-dugme[disabled] { opacity:.4; cursor:default; text-decoration:none; }
.yorum-yanit-bilgi { font-size:.9em; opacity:.85; }
.yorum-sayfalama { margin:1rem 0; }
.yorum-bildir-kutu { display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; margin-top:.4rem; }
.yorum-bildir-kutu select { font:inherit; font-size:.85em; max-width:100%; }
</style>

<script>
/* Yorum formu, yanıt ve bildir — 1.3-07.
   Bu blok assets/site.js'ten ÖNCE kaydolur: site.js `defer` ile yüklenir, bu
   satır içi betik ise ayrıştırma sırasında çalışır. Bu yüzden aşağıdaki
   stopImmediatePropagation() site.js'in eski `public.comment` göndericisini
   devre dışı bırakır — iki gönderici aynı formu iki kez yollamaz.
   site.js'in BELGE düzeyindeki tembel-CSRF yakalayıcısı yakalama evresinde
   çalıştığı için bundan etkilenmez; anahtarı doldurup formu yeniden gönderir. */
(function () {
  'use strict';
  var form = document.getElementById('yorumForm');
  var bolum = document.getElementById('yorumlar');
  if (!bolum) { return; }

  /* API adresi sunucudan gelir: satır içi betik kendi src'sini okuyamaz ve
     göreli 'api.php' /haber/... altında yanlış adrese çıkardı. */
  var API = bolum.getAttribute('data-api') || 'api.php';

  function gonder(action, data, csrf) {
    return fetch(API + '?a=' + encodeURIComponent(action), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf || '', 'Accept': 'application/json' },
      body: JSON.stringify(data),
      credentials: 'same-origin'
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Sunucu yanıtı okunamadı.' }; });
    });
  }

  function kutuyaYaz(kutu, ok, mesaj) {
    if (!kutu) { return; }
    kutu.hidden = false;
    kutu.className = 'form-sonuc ' + (ok ? 'ok' : 'err');
    kutu.textContent = mesaj;
  }

  /* ---------------------------------------------------------- gönderim */
  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      e.stopImmediatePropagation();
      var d = {};
      Array.prototype.forEach.call(form.elements, function (el) {
        if (!el.name) { return; }
        d[el.name] = (el.type === 'checkbox') ? (el.checked ? 1 : 0) : el.value;
      });
      d.post_id = parseInt(form.getAttribute('data-post'), 10) || 0;
      d.parent_id = parseInt(d.parent_id, 10) || 0;
      var btn = form.querySelector('button[type=submit]');
      var kutu = document.getElementById('yorumSonuc');
      if (btn) { btn.disabled = true; }
      gonder('comments.submit', d, d._csrf).then(function (r) {
        kutuyaYaz(kutu, !!r.ok, r.ok ? (r.message || 'Yorumunuz alındı.') : (r.error || 'Gönderilemedi.'));
        if (r.ok) {
          var govde = document.getElementById('yorumGovde');
          if (govde) { govde.value = ''; }
          yanitiBirak();
        }
        if (btn) { btn.disabled = false; }
      }).catch(function () {
        kutuyaYaz(kutu, false, 'Bağlantı hatası. Lütfen tekrar deneyin.');
        if (btn) { btn.disabled = false; }
      });
    });
  }

  /* ---------------------------------------------------------- yanıt */
  var yuva = document.getElementById('yorumFormYuva');
  var alan = document.getElementById('yorumParent');
  var bilgi = document.getElementById('yorumYanitBilgi');
  var bilgiAd = document.getElementById('yorumYanitAd');

  function yanitiBirak() {
    if (alan) { alan.value = '0'; }
    if (bilgi) { bilgi.hidden = true; }
    if (yuva && form && yuva.parentNode !== bolum) { bolum.appendChild(yuva); }
  }

  var iptal = document.getElementById('yorumYanitIptal');
  if (iptal) { iptal.addEventListener('click', yanitiBirak); }

  bolum.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.getAttribute) { return; }

    var yanitId = t.getAttribute('data-yorum-yanit');
    if (yanitId && form && yuva && alan) {
      alan.value = String(parseInt(yanitId, 10) || 0);
      if (bilgiAd) { bilgiAd.textContent = t.getAttribute('data-yorum-ad') || ''; }
      if (bilgi) { bilgi.hidden = false; }
      var satir = document.getElementById('yorum-' + yanitId);
      if (satir && satir.parentNode) { satir.parentNode.insertBefore(yuva, satir.nextSibling); }
      var govde = document.getElementById('yorumGovde');
      if (govde) { govde.focus(); }
      return;
    }

    var bildirId = t.getAttribute('data-yorum-bildir');
    if (bildirId) { bildirKutusu(t, parseInt(bildirId, 10) || 0); }
  });

  /* ---------------------------------------------------------- bildir */
  function bildirKutusu(dugme, id) {
    if (!id || dugme.disabled) { return; }
    var eski = document.querySelector('.yorum-bildir-kutu');
    if (eski && eski.parentNode) { eski.parentNode.removeChild(eski); }

    var kutu = document.createElement('div');
    kutu.className = 'yorum-bildir-kutu';
    var sec = document.createElement('select');
    sec.setAttribute('aria-label', 'Bildirim gerekçesi');
    [['hakaret', 'Hakaret / nefret söylemi'], ['spam', 'Spam / reklam'],
     ['alakasiz', 'Konuyla alakasız'], ['diger', 'Diğer']].forEach(function (o) {
      var op = document.createElement('option');
      op.value = o[0];
      op.textContent = o[1];
      sec.appendChild(op);
    });
    var ok = document.createElement('button');
    ok.type = 'button';
    ok.className = 'yorum-dugme';
    ok.textContent = 'Gönder';
    var vaz = document.createElement('button');
    vaz.type = 'button';
    vaz.className = 'yorum-dugme';
    vaz.textContent = 'Vazgeç';
    var durum = document.createElement('span');
    durum.className = 'soluk kucuk';

    kutu.appendChild(sec);
    kutu.appendChild(ok);
    kutu.appendChild(vaz);
    kutu.appendChild(durum);
    if (dugme.parentNode) { dugme.parentNode.appendChild(kutu); }

    vaz.addEventListener('click', function () {
      if (kutu.parentNode) { kutu.parentNode.removeChild(kutu); }
    });
    ok.addEventListener('click', function () {
      ok.disabled = true;
      /* Bildir ucu CSRF istemez: anahtar almak oturum açardı ve okurun geri
         kalan gezintisi önbelleksiz kalırdı (CONTRACTS §3.1). */
      gonder('comments.report', { id: id, reason: sec.value }, '').then(function (r) {
        durum.textContent = r && r.ok ? (r.message || 'Bildiriminiz alındı.')
                                      : ((r && r.error) || 'Gönderilemedi.');
        if (r && r.ok) {
          dugme.disabled = true;
          sec.disabled = true;
          if (ok.parentNode) { ok.parentNode.removeChild(ok); }
          if (vaz.parentNode) { vaz.parentNode.removeChild(vaz); }
        } else {
          ok.disabled = false;
        }
      }).catch(function () {
        durum.textContent = 'Bağlantı hatası.';
        ok.disabled = false;
      });
    });
  }
})();
</script>
