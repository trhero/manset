<?php
/**
 * Panel — medya kütüphanesi (Ajan-2).
 * Yükleme (çoklu, sürükle-bırak), arama, alt metin düzenleme, silme, sayfalama.
 *
 * Silme yetkisi: v1'de media tablosunda "yükleyen" sütunu olmadığı için
 * kimin hangi dosyayı yüklediği bilinemiyor. Bu yüzden silme yalnız
 * media.delete_any iznine bağlandı (yazar rolü silemez).
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('media.manage');

$adminPageTitle = 'Medya';
$me = current_user();
$silebilir = can($me, 'media.delete_any');

$ara = sanitize_line((string)inp('q', ''), 120);
$sayfa = max(1, inp_i('sayfa', 1));
$perPage = 24;

$toplam = media_count($ara);
$ogeler = media_list($ara, $perPage, ($sayfa - 1) * $perPage);
$toplamBoyut = media_total_size();
$toplamAdet = media_count('');
$maxMb = (int)round(media_max_bytes() / 1048576);
$gdVar = media_gd_available();
?>

<div class="sayfa-basligi">
  <h1>Medya kütüphanesi</h1>
  <div class="esnek kucuk soluk">
    <span><?= (int)$toplamAdet ?> dosya</span>
    <span>·</span>
    <span><?= esc(media_human_size($toplamBoyut)) ?> toplam</span>
  </div>
</div>

<?php if (!$gdVar): ?>
  <div class="uyari warn">
    Sunucuda <strong>GD</strong> eklentisi yok. Yükleme çalışır ancak küçültülmüş görsel
    türevleri (thumb/medium/large) üretilemez; her yerde orijinal dosya kullanılır.
  </div>
<?php endif; ?>

<div class="kart">
  <div class="kart-baslik">Yükle</div>
  <div id="birakAlani" class="birak-alani" tabindex="0" role="button"
       aria-label="Dosya seçmek için tıklayın veya dosyaları buraya sürükleyin">
    <span class="birak-ikon" aria-hidden="true">⬆</span>
    <strong>Dosyaları buraya sürükleyin</strong>
    <span class="soluk kucuk">ya da seçmek için tıklayın — JPG, PNG, GIF, WEBP · en çok <?= (int)$maxMb ?> MB</span>
    <input type="file" id="dosyaSec" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden>
  </div>
  <div class="form-alan bosluk-ust">
    <label for="topluAlt">Yüklenecek dosyalar için ortak alt metin (isteğe bağlı)</label>
    <input type="text" id="topluAlt" maxlength="255" placeholder="Görseli görmeyen okuyucular için kısa açıklama">
  </div>
  <div id="yuklemeDurum" class="kucuk soluk" role="status"></div>
</div>

<form class="arac-cubugu" method="get" action="<?= esc(base_url()) ?>/admin/">
  <input type="hidden" name="p" value="media">
  <input type="search" name="q" value="<?= esc($ara) ?>" placeholder="Dosya adı veya alt metin…" aria-label="Medya ara">
  <button type="submit" class="dugme">Ara</button>
  <?php if ($ara !== ''): ?>
    <a class="dugme" href="<?= esc(admin_url('media')) ?>">Temizle</a>
    <span class="kucuk soluk"><?= (int)$toplam ?> sonuç</span>
  <?php endif; ?>
</form>

<?php if (!$ogeler): ?>
  <div class="bos-durum">
    <span class="buyuk" aria-hidden="true">▣</span>
    <h3><?= $ara !== '' ? 'Eşleşen dosya yok' : 'Kütüphane boş' ?></h3>
    <p><?= $ara !== '' ? 'Farklı bir arama deneyin.' : 'Yukarıdaki alana dosya sürükleyerek başlayın.' ?></p>
  </div>
<?php else: ?>
  <div class="medya-izgara">
    <?php foreach ($ogeler as $m): ?>
      <div class="medya-oge medya-kart" data-id="<?= (int)$m['id'] ?>">
        <a href="<?= esc($m['url']) ?>" target="_blank" rel="noopener" title="Tam boyutta aç">
          <img src="<?= esc($m['thumb']) ?>" alt="<?= esc($m['alt'] !== '' ? $m['alt'] : $m['filename']) ?>" loading="lazy">
        </a>
        <div class="ad" title="<?= esc($m['filename']) ?>"><?= esc(basename((string)$m['filename'])) ?></div>
        <div class="medya-bilgi mini soluk">
          <?= (int)$m['width'] ?>×<?= (int)$m['height'] ?> · <?= esc($m['size_text']) ?>
          <?php if (!empty($m['variants']['thumb'])): ?> · <span title="Küçültülmüş türevler üretildi">türevli</span><?php endif; ?>
        </div>
        <div class="medya-islem">
          <input type="text" class="alt-girdi" value="<?= esc($m['alt']) ?>" maxlength="255"
                 placeholder="Alt metin" aria-label="<?= esc(basename((string)$m['filename'])) ?> alt metni">
          <div class="dugme-grup">
            <button type="button" class="dugme kucuk" data-medya-kaydet="<?= (int)$m['id'] ?>">Kaydet</button>
            <?php if ($silebilir): ?>
              <button type="button" class="dugme kucuk tehlike" data-medya-sil="<?= (int)$m['id'] ?>"
                      data-onay="<?= esc(basename((string)$m['filename'])) ?> ve türevleri kalıcı olarak silinecek. Onaylıyor musunuz?">Sil</button>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?= admin_paginate($toplam, $perPage, $sayfa, 'media', $ara !== '' ? ['q' => $ara] : []) ?>
<?php endif; ?>

<?php if (!$silebilir): ?>
  <div class="uyari bilgi mini bosluk-ust">
    Dosya silme yetkisi (<code>media.delete_any</code>) hesabınızda yok. Silinmesi gereken dosyaları
    bir editöre ya da yöneticiye bildirin.
  </div>
<?php endif; ?>

<style>
/* admin.css'te bir "sürükle-bırak" kutusu ve medya kartı alt bölümü tanımlı değil.
   Aşağıdaki kurallar yalnız bu ekranın yükleme alanı ve kart altı için gereklidir;
   renkler mevcut değişkenlerden alınır. */
.birak-alani { display:flex; flex-direction:column; align-items:center; gap:6px; text-align:center;
  padding:28px 18px; border:2px dashed var(--cizgi); border-radius:var(--kose);
  background:var(--yuzey-2); cursor:pointer; transition:border-color .15s, background .15s; }
.birak-alani:hover, .birak-alani:focus-visible { border-color:var(--ana); outline:none; }
.birak-alani.uzerinde { border-color:var(--ana); background:#fdf0ee; }
.birak-ikon { font-size:26px; opacity:.5; }
.medya-kart { cursor:default; display:flex; flex-direction:column; }
.medya-bilgi { padding:0 8px 4px; }
.medya-islem { padding:6px 8px 8px; margin-top:auto; display:flex; flex-direction:column; gap:6px; }
.medya-islem .alt-girdi { font-size:12px; padding:5px 7px; }
.medya-kart.siliniyor { opacity:.4; pointer-events:none; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var alan = document.getElementById('birakAlani');
  var girdi = document.getElementById('dosyaSec');
  var durum = document.getElementById('yuklemeDurum');
  var altAlan = document.getElementById('topluAlt');

  function bildir(metin) { if (durum) { durum.textContent = metin || ''; } }

  function yukle(dosyalar) {
    if (!dosyalar || !dosyalar.length) { return; }
    var fd = new FormData();
    var sayi = 0;
    Array.prototype.forEach.call(dosyalar, function (f) {
      if (sayi >= 20) { return; }
      fd.append('dosya[]', f);
      sayi++;
    });
    if (!sayi) { return; }
    if (altAlan && altAlan.value.trim() !== '') { fd.append('alt', altAlan.value.trim()); }

    bildir(sayi + ' dosya yükleniyor…');
    M.api('media.upload', null, { formData: fd }).then(function (r) {
      bildir('');
      if (M.sonuc(r, 'Yüklendi.')) { setTimeout(function () { location.reload(); }, 600); }
    });
  }

  if (alan && girdi) {
    alan.addEventListener('click', function () { girdi.click(); });
    alan.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); girdi.click(); }
    });
    girdi.addEventListener('change', function () { yukle(girdi.files); girdi.value = ''; });

    ['dragenter', 'dragover'].forEach(function (ad) {
      alan.addEventListener(ad, function (e) { e.preventDefault(); e.stopPropagation(); alan.classList.add('uzerinde'); });
    });
    ['dragleave', 'drop'].forEach(function (ad) {
      alan.addEventListener(ad, function (e) { e.preventDefault(); e.stopPropagation(); alan.classList.remove('uzerinde'); });
    });
    alan.addEventListener('drop', function (e) {
      if (e.dataTransfer && e.dataTransfer.files) { yukle(e.dataTransfer.files); }
    });
  }

  // Sayfanın geri kalanına bırakılan dosya tarayıcıda açılmasın
  ['dragover', 'drop'].forEach(function (ad) {
    window.addEventListener(ad, function (e) {
      if (alan && alan.contains(e.target)) { return; }
      e.preventDefault();
    });
  });

  /* ------------------------------------------------ alt metin kaydet */
  M.qsa('[data-medya-kaydet]').forEach(function (b) {
    b.addEventListener('click', function () {
      var kart = b.closest('.medya-kart');
      var deger = M.qs('.alt-girdi', kart).value;
      b.disabled = true;
      M.api('media.update', { id: parseInt(b.getAttribute('data-medya-kaydet'), 10), alt: deger })
        .then(function (r) { M.sonuc(r, 'Alt metin kaydedildi.'); b.disabled = false; });
    });
  });

  /* ------------------------------------------------ sil */
  M.qsa('[data-medya-sil]').forEach(function (b) {
    b.addEventListener('click', function () {
      var kart = b.closest('.medya-kart');
      kart.classList.add('siliniyor');
      M.api('media.delete', { id: parseInt(b.getAttribute('data-medya-sil'), 10) }).then(function (r) {
        if (M.sonuc(r, 'Silindi.')) { kart.parentNode.removeChild(kart); }
        else { kart.classList.remove('siliniyor'); }
      });
    });
  });
});
</script>
